// livehold: keeps XC_VM 24/7 viewers (TS and HLS) connected across ffmpeg restarts and source stalls.
// Reads ffmpeg's on-disk HLS output (each client at its own position, no shared RAM buffer),
// stitches restarts into one continuous feed, shows the offline video while the channel is down,
// and kills an ffmpeg whose output froze so XC_VM's monitor restarts it at once.
package main

import (
	"encoding/json"
	"fmt"
	"log"
	"math"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

var (
	addr       = env("LH_ADDR", "127.0.0.1:8149")
	streamsDir = env("LH_STREAMS", "/home/xc_vm/content/streams")
	listFile   = env("LH_LIST", "/home/xc_vm/config/livehold_streams.txt")
	slatePath  = env("LH_SLATE", "/home/xc_vm/content/video/duck_offline.ts")
	frozenWait = time.Duration(envInt("LH_FROZEN_SEC", 12)) * time.Second
	holdMax    = time.Duration(envInt("LH_HOLD_SEC", 300)) * time.Second
	stallKill  = time.Duration(envInt("LH_STALL_KILL_SEC", 18)) * time.Second
	idle       = time.Duration(envInt("LH_IDLE_SEC", 60)) * time.Second
	slateDur   time.Duration
	slate      []byte
	feeds      = map[string]*Feed{}
	feedsMu    sync.Mutex
	idRe       = regexp.MustCompile(`^\d{1,9}$`)
	plRe       = regexp.MustCompile(`^/hls/(\d{1,9})/index\.m3u8$`)
	sgRe       = regexp.MustCompile(`^/hls/(\d{1,9})/(\d{1,12})\.ts$`)
	nullPkt    = func() []byte {
		p := make([]byte, 188)
		p[0], p[1], p[2], p[3] = 0x47, 0x1f, 0xff, 0x10
		for i := 4; i < 188; i++ {
			p[i] = 0xff
		}
		return p
	}()
)

func env(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}

func envInt(k string, d int) int {
	if n, err := strconv.Atoi(os.Getenv(k)); err == nil && n > 0 {
		return n
	}
	return d
}

func getFeed(id string, join bool) *Feed {
	feedsMu.Lock()
	defer feedsMu.Unlock()
	f := feeds[id]
	if f == nil {
		f = newFeed(id)
		feeds[id] = f
		go f.run()
	}
	f.mu.Lock()
	f.lastTouch = time.Now()
	if join {
		f.viewers++
	}
	f.mu.Unlock()
	return f
}

// GET /ts/{id}?pb=<prebuffer seconds>
func serveTS(w http.ResponseWriter, r *http.Request) {
	id := strings.TrimPrefix(r.URL.Path, "/ts/")
	if !idRe.MatchString(id) {
		http.Error(w, "bad request", 400)
		return
	}
	pb, _ := strconv.ParseFloat(r.URL.Query().Get("pb"), 64)
	f := getFeed(id, true)
	defer func() { f.mu.Lock(); f.viewers--; f.lastTouch = time.Now(); f.mu.Unlock() }()
	f.mu.Lock()
	if f.dead() {
		f.mu.Unlock()
		http.Error(w, "offline", 503)
		return
	}
	pos := f.startSeq(pb)
	f.mu.Unlock()
	w.Header().Set("Content-Type", "video/mp2t")
	w.WriteHeader(200)
	fl, _ := w.(http.Flusher)
	ctx := r.Context()
	stop := context_AfterFunc(ctx, f)
	defer stop()
	for {
		f.mu.Lock()
		var e entry
		var ok bool
		for {
			if f.closed || ctx.Err() != nil || f.dead() {
				f.mu.Unlock()
				return
			}
			if len(f.ents) > 0 && pos < f.ents[0].vseq { // client too slow: back to live
				pos = f.ents[len(f.ents)-1].vseq
			}
			if e, ok = f.find(pos); ok {
				break
			}
			if !waitFor(f, 1500*time.Millisecond) { // nothing new: keep the socket alive
				f.mu.Unlock()
				if _, err := w.Write(nullPkt); err != nil {
					return
				}
				if fl != nil {
					fl.Flush()
				}
				f.mu.Lock()
			}
		}
		f.mu.Unlock()
		pos++
		if b := readEntry(e); b != nil {
			if _, err := w.Write(b); err != nil {
				return
			}
			if fl != nil {
				fl.Flush()
			}
		}
	}
}

// waitFor waits on the feed cond up to d; false on timeout.
func waitFor(f *Feed, d time.Duration) bool {
	timedOut := false
	t := time.AfterFunc(d, func() { f.mu.Lock(); timedOut = true; f.cond.Broadcast(); f.mu.Unlock() })
	f.cond.Wait()
	t.Stop()
	return !timedOut
}

func context_AfterFunc(ctx interface{ Done() <-chan struct{} }, f *Feed) func() {
	done := make(chan struct{})
	go func() {
		select {
		case <-ctx.Done():
			f.mu.Lock()
			f.cond.Broadcast()
			f.mu.Unlock()
		case <-done:
		}
	}()
	return func() { close(done) }
}

func serveHLS(w http.ResponseWriter, r *http.Request) {
	if m := plRe.FindStringSubmatch(r.URL.Path); m != nil {
		f := getFeed(m[1], false)
		f.mu.Lock()
		defer f.mu.Unlock()
		if f.dead() {
			http.Error(w, "offline", 503)
			return
		}
		w.Header().Set("Content-Type", "application/vnd.apple.mpegurl")
		w.Write([]byte(f.playlist()))
		return
	}
	if m := sgRe.FindStringSubmatch(r.URL.Path); m != nil {
		feedsMu.Lock()
		f := feeds[m[1]]
		feedsMu.Unlock()
		if f == nil {
			http.NotFound(w, r)
			return
		}
		seq, _ := strconv.ParseInt(m[2], 10, 64)
		f.mu.Lock()
		f.lastTouch = time.Now()
		e, ok := f.find(seq)
		f.mu.Unlock()
		var b []byte
		if ok {
			b = readEntry(e)
		}
		if b == nil {
			http.NotFound(w, r)
			return
		}
		w.Header().Set("Content-Type", "video/mp2t")
		w.Header().Set("Content-Length", strconv.Itoa(len(b)))
		w.Write(b)
		return
	}
	http.NotFound(w, r)
}

func (f *Feed) playlist() string {
	from := len(f.ents) - 6
	if from < 0 {
		from = 0
	}
	disc := f.discBase
	for _, e := range f.ents[1 : from+1] {
		if e.disc {
			disc++
		}
	}
	target := 1.0
	for _, e := range f.ents[from:] {
		target = math.Max(target, e.dur)
	}
	var b strings.Builder
	fmt.Fprintf(&b, "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:%d\n#EXT-X-MEDIA-SEQUENCE:%d\n#EXT-X-DISCONTINUITY-SEQUENCE:%d\n", int(math.Ceil(target)), f.ents[from].vseq, disc)
	for i, e := range f.ents[from:] {
		if e.disc && i > 0 {
			b.WriteString("#EXT-X-DISCONTINUITY\n")
		}
		fmt.Fprintf(&b, "#EXTINF:%.3f,\n%d.ts\n", e.dur, e.vseq)
	}
	return b.String()
}

// GET /name/{id}/{vseq}: on-disk file name of a feed entry ("slate" for the offline video), so segment.php serves it the legacy way.
// GET /latest/{id}: newest on-disk segment name (admin "send message" overlay in TS relay mode).
func serveName(w http.ResponseWriter, r *http.Request) {
	parts := strings.Split(strings.Trim(r.URL.Path, "/"), "/")
	if len(parts) < 2 || !idRe.MatchString(parts[1]) {
		http.NotFound(w, r)
		return
	}
	feedsMu.Lock()
	f := feeds[parts[1]]
	feedsMu.Unlock()
	if f == nil {
		http.NotFound(w, r)
		return
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	f.lastTouch = time.Now()
	if parts[0] == "latest" {
		for i := len(f.ents) - 1; i >= 0; i-- {
			if !f.ents[i].slate {
				w.Write([]byte(filepath.Base(f.ents[i].path)))
				return
			}
		}
		http.NotFound(w, r)
		return
	}
	seq, _ := strconv.ParseInt(parts[len(parts)-1], 10, 64)
	e, ok := f.find(seq)
	switch {
	case !ok:
		http.NotFound(w, r)
	case e.slate:
		w.Write([]byte("slate"))
	default:
		w.Write([]byte(filepath.Base(e.path)))
	}
}

// GET /active/{id}: 200 when clients are being held on this channel (PHP then skips its off-air checks).
func serveActive(w http.ResponseWriter, r *http.Request) {
	id := strings.TrimPrefix(r.URL.Path, "/active/")
	feedsMu.Lock()
	f := feeds[id]
	feedsMu.Unlock()
	if f != nil {
		f.mu.Lock()
		ok := !f.dead() && !f.closed
		f.mu.Unlock()
		if ok {
			w.Write([]byte("1"))
			return
		}
	}
	http.Error(w, "0", 404)
}

func serveStats(w http.ResponseWriter, _ *http.Request) {
	feedsMu.Lock()
	list := make([]*Feed, 0, len(feeds))
	for _, f := range feeds {
		list = append(list, f)
	}
	feedsMu.Unlock()
	out := map[string]any{}
	for _, f := range list {
		f.mu.Lock()
		slates := 0
		for _, e := range f.ents {
			if e.slate {
				slates++
			}
		}
		out[f.id] = map[string]any{"viewers": f.viewers, "entries": len(f.ents), "slates": slates, "since_real_s": int(time.Since(f.lastReal).Seconds()), "dead": f.dead()}
		f.mu.Unlock()
	}
	_ = json.NewEncoder(w).Encode(out)
}

// supervise: an ffmpeg alive but whose playlist froze for stallKill is killed so the XC_VM monitor restarts it now (instead of after 36 s).
func supervise() {
	last := map[string]time.Time{}
	for range time.Tick(2 * time.Second) {
		b, err := os.ReadFile(listFile)
		if err != nil {
			continue
		}
		ids := strings.Fields(string(b))
		for _, id := range ids {
			if id == "*" {
				feedsMu.Lock()
				for fid, f := range feeds {
					f.mu.Lock()
					if f.viewers > 0 || time.Since(f.lastTouch) < 30*time.Second {
						ids = append(ids, fid)
					}
					f.mu.Unlock()
				}
				feedsMu.Unlock()
			}
		}
		for _, id := range ids {
			if !idRe.MatchString(id) || time.Since(last[id]) < 60*time.Second {
				continue
			}
			pf := filepath.Join(streamsDir, id+"_.pid")
			pst, err1 := os.Stat(pf)
			pl, err2 := os.Stat(filepath.Join(streamsDir, id+"_.m3u8"))
			if err1 != nil || err2 != nil || time.Since(pst.ModTime()) < 45*time.Second || time.Since(pl.ModTime()) < stallKill {
				continue
			}
			pb, _ := os.ReadFile(pf)
			pid, _ := strconv.Atoi(strings.TrimSpace(string(pb)))
			cmd := cmdline(pid)
			if pid <= 1 || !strings.Contains(strings.SplitN(cmd, "\x00", 2)[0], "ffmpeg") {
				continue
			}
			if ckRe.MatchString(cmd) && time.Since(pl.ModTime()) < 150*time.Second { // ClearKey: ad/KID pauses, same as XC
				continue
			}
			last[id] = time.Now()
			log.Printf("stream %s: no new segment for %s, killing ffmpeg %d so XC_VM restarts it", id, time.Since(pl.ModTime()).Round(time.Second), pid)
			_ = syscall.Kill(pid, syscall.SIGKILL)
		}
	}
}

var ckRe = regexp.MustCompile(`\.mpd|/mpd\?`)

func cmdline(pid int) string {
	b, _ := os.ReadFile(fmt.Sprintf("/proc/%d/cmdline", pid))
	return string(b)
}

func main() {
	var err error
	if slate, err = os.ReadFile(slatePath); err != nil {
		log.Fatalf("slate %s: %v", slatePath, err)
	}
	slateDur = time.Duration(envInt("LH_SLATE_SEC", 10)) * time.Second
	http.HandleFunc("/ts/", serveTS)
	http.HandleFunc("/hls/", serveHLS)
	http.HandleFunc("/active/", serveActive)
	http.HandleFunc("/name/", serveName)
	http.HandleFunc("/latest/", serveName)
	http.HandleFunc("/stats", serveStats)
	http.HandleFunc("/health", func(w http.ResponseWriter, _ *http.Request) { w.Write([]byte("ok")) })
	go supervise()
	log.Printf("livehold on %s frozen=%s hold=%s stall_kill=%s slate=%s (%s)", addr, frozenWait, holdMax, stallKill, slatePath, slateDur)
	srv := &http.Server{Addr: addr, ReadHeaderTimeout: 10 * time.Second}
	log.Fatal(srv.ListenAndServe())
}
