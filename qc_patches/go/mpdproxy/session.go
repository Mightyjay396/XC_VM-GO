package main

import (
	"crypto/md5"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"
)

type Session struct {
	URL         string          `json:"url"`
	Proxy       string          `json:"proxy"`
	Delay       int             `json:"delay"`
	Reps        map[string]*Rep `json:"reps"`
	LastRefresh float64         `json:"last_refresh"`
	LastUse     float64         `json:"last_use"`
	Created     float64         `json:"created"`

	mu        sync.Mutex // reps/segs/proxy/timestamps
	refreshMu sync.Mutex // serializes manifest refreshes (held during the upstream fetch)
	noAdv     int
}

var (
	sessMu   sync.Mutex
	sessions = map[string]*Session{}
	saveMu   sync.Mutex
)

func nowF() float64 { return float64(time.Now().UnixNano()) / 1e9 }

func getSess(sid string) *Session {
	sessMu.Lock()
	defer sessMu.Unlock()
	return sessions[sid]
}

func sessionNew(u, proxy string, delay int) (string, []byte, int, error) {
	xml, final, px, err := fetchFailover(u, proxy, 40*time.Second, 1)
	if err != nil {
		return "", nil, 0, err
	}
	doc, reps, _, err := parseMPD(xml, final, delay)
	if err != nil {
		return "", nil, 0, fmt.Errorf("parse manifest: %w", err)
	}
	h := md5.Sum([]byte(fmt.Sprintf("%s|%f", u, nowF())))
	sid := hex.EncodeToString(h[:])[:12]
	body, err := staticManifest(doc, reps, sid, delay)
	if err != nil {
		return "", nil, 0, err
	}
	now := nowF()
	s := &Session{URL: u, Proxy: px, Delay: delay, Reps: reps, LastRefresh: now, LastUse: now, Created: now}
	sessMu.Lock()
	sessions[sid] = s
	expireSessionsLocked()
	sessMu.Unlock()
	return sid, body, len(reps), nil
}

func expireSessionsLocked() {
	now := nowF()
	for k, s := range sessions {
		s.mu.Lock()
		idle := now - s.LastUse
		var hits int64
		for _, e := range s.Reps {
			hits += e.Hits
		}
		probe := hits <= int64(3*len(s.Reps))
		s.mu.Unlock()
		if idle > sessionTTL || (probe && idle > probeTTL) {
			delete(sessions, k)
		}
	}
}

// refresh re-reads the real manifest and appends new segments (increasing t) to every representation.
func (s *Session) refresh() {
	s.refreshMu.Lock()
	defer s.refreshMu.Unlock()
	s.mu.Lock()
	if nowF()-s.LastRefresh < refreshMin {
		s.mu.Unlock()
		return
	}
	s.LastRefresh = nowF()
	u, proxy, delay := s.URL, s.Proxy, s.Delay
	s.mu.Unlock()
	xml, final, _, err := fetchFailover(u, proxy, 40*time.Second, 1)
	if err != nil {
		logf("refresh failed: %.120s url=%.90s", err.Error(), u)
		return
	}
	_, fresh, all, err := parseMPD(xml, final, delay)
	if err != nil {
		logf("refresh parse failed: %.120s url=%.90s", err.Error(), u)
		return
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	advanced := false
	for rid, e := range s.Reps {
		// stay on the session's own timeline (content), even when ad periods dominate the current window
		f := all[rid][groupKey(e.Init, e.TS)]
		if f == nil {
			f = fresh[rid]
		}
		if f == nil {
			continue
		}
		lastT := int64(-1)
		if len(e.Segs) > 0 {
			lastT = e.Segs[len(e.Segs)-1][0]
		}
		remap := map[int64]int64{}
		for _, sg := range f.Segs {
			if sg[0] <= lastT {
				continue
			}
			bi, ok := remap[sg[3]]
			if !ok {
				p := f.URLs[sg[3]]
				bi = urlIndex(e, p[0], p[1])
				remap[sg[3]] = bi
			}
			e.Segs = append(e.Segs, Seg{sg[0], sg[1], sg[2], bi, sg[4]})
			if e.Hits > 0 {
				advanced = true
			}
		}
		if drop := len(e.Segs) - unusedKeep; drop > 0 && e.Hits == 0 {
			e.Segs = append([]Seg(nil), e.Segs[drop:]...)
			e.Off += int64(drop)
		}
	}
	if advanced {
		s.noAdv = 0
	} else if s.noAdv++; s.noAdv%10 == 3 {
		logf("refresh no-advance x%d url=%.90s", s.noAdv, u)
	}
}

// ---------------------------------------------------------------- state persistence (same JSON as the Python version)

func stateFile() string {
	d := os.Getenv("STATE_DIRECTORY")
	if d == "" {
		d = "/tmp"
	}
	return filepath.Join(d, "mpdproxy_sessions.json")
}

func saveState() error {
	saveMu.Lock()
	defer saveMu.Unlock()
	sessMu.Lock()
	expireSessionsLocked()
	snap := make(map[string]*Session, len(sessions))
	for k, s := range sessions {
		snap[k] = s
	}
	sessMu.Unlock()
	f := stateFile()
	tmp := fmt.Sprintf("%s.tmp.%d", f, os.Getpid())
	fh, err := os.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0600)
	if err != nil {
		return err
	}
	enc := json.NewEncoder(fh)
	fh.WriteString("{")
	first := true
	for sid, s := range snap {
		s.mu.Lock()
		kb, _ := json.Marshal(sid)
		vb, err := json.Marshal(s)
		s.mu.Unlock()
		if err != nil {
			continue
		}
		if !first {
			fh.WriteString(",")
		}
		first = false
		fh.Write(kb)
		fh.WriteString(":")
		fh.Write(vb)
	}
	_ = enc
	fh.WriteString("}")
	if err := fh.Close(); err != nil {
		os.Remove(tmp)
		return err
	}
	if len(snap) > 0 {
		if _, err := os.Stat(f); err == nil {
			os.Rename(f, f+".bak")
		}
	}
	if err := os.Rename(tmp, f); err != nil {
		return err
	}
	stats.set("state_saved_at", time.Now().Unix())
	return nil
}

func loadState() int {
	f := stateFile()
	if olds, _ := filepath.Glob(f + ".tmp.*"); len(olds) > 0 {
		for _, o := range olds {
			os.Remove(o)
		}
	}
	var data map[string]*Session
	for _, p := range []string{f, f + ".bak"} {
		b, err := os.ReadFile(p)
		if err != nil {
			continue
		}
		var d map[string]*Session
		if err := json.Unmarshal(b, &d); err != nil {
			logf("state load failed (%s): %.120s", filepath.Base(p), err.Error())
			continue
		}
		if p != f {
			logf("state: main file unreadable, using .bak")
		}
		data = d
		break
	}
	now := nowF()
	n := 0
	sessMu.Lock()
	for sid, s := range data {
		if s == nil || s.Reps == nil || now-s.LastUse > sessionTTL {
			continue
		}
		s.LastRefresh = 0
		for _, e := range s.Reps {
			if e.URLs == nil {
				e.URLs = [][2]string{}
			}
		}
		sessions[sid] = s
		n++
	}
	sessMu.Unlock()
	stats.set("sessions_restored", int64(n))
	return n
}

func sortByTS[T any](a []T, ts func(i int) time.Time) {
	sort.Slice(a, func(i, j int) bool { return ts(i).Before(ts(j)) })
}

func redactProxy(p string) string {
	if i := strings.Index(p, "//"); i >= 0 {
		if j := strings.LastIndex(p, "@"); j > i {
			return p[:i+2] + p[j+1:]
		}
	}
	return p
}
