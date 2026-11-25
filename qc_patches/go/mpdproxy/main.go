// mpdproxy (Go) — "DASH live → DASH static" translator for ClearKey sources read by ffmpeg (XC_VM).
// Same HTTP contract and state file as the Python version:
//
//	GET /mpd?s=1&d=<s>&u=<url>[&p=<proxy>]  GET /segi/<sid>/<rep>  GET /segn/<sid>/<rep>/<n>
//	GET /stats  GET /sessions  GET /reproxy?u=|from=&to=  GET /health
package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"runtime"
	"runtime/debug"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"
)

const (
	prefetchN     = 28
	prefetchRetry = 55 * time.Second
	waitMax       = 50 * time.Second
	slowSeg       = 12 * time.Second // max per segment attempt before switching tunnel
	respondBy     = 75 * time.Second // hard cap per segment request (< ffmpeg rw_timeout 90 s)
	cacheTTL      = 120 * time.Second
	refreshMin    = 6.0
	refreshAhead  = 10
	sessionTTL    = 900.0
	keepBehind    = 30
	unusedKeep    = 200
	probeTTL      = 180.0
	saveEvery     = 15 * time.Second
	version       = "go-r8"
	warmup        = 90 * time.Second
)

var (
	port      = 8098
	cacheMax  int64
	inflight  atomic.Int64
	startedAt = time.Now()
)

func logf(f string, a ...any) { log.Printf(f, a...) }

type statMap struct {
	mu sync.Mutex
	m  map[string]int64
}

func (s *statMap) add(k string, v int64) { s.mu.Lock(); s.m[k] += v; s.mu.Unlock() }
func (s *statMap) set(k string, v int64) { s.mu.Lock(); s.m[k] = v; s.mu.Unlock() }

var stats = &statMap{m: map[string]int64{}}

func envInt(k string, def int) int {
	if v, err := strconv.Atoi(os.Getenv(k)); err == nil && v > 0 {
		return v
	}
	return def
}

func memTotal() int64 {
	b, err := os.ReadFile("/proc/meminfo")
	if err != nil {
		return 4 << 30
	}
	var kb int64
	fmt.Sscanf(string(b), "MemTotal: %d kB", &kb)
	return kb * 1024
}

func send(w http.ResponseWriter, r *http.Request, code int, body []byte, ctype string) {
	h := w.Header()
	h.Set("Connection", "close")
	h.Set("Content-Type", ctype)
	if rng := r.Header.Get("Range"); code == 200 && len(body) > 0 && strings.HasPrefix(rng, "bytes=") {
		if a, b, ok := strings.Cut(rng[6:], "-"); ok {
			n := len(body)
			start, end, valid := 0, n-1, true
			if a != "" {
				v, err := strconv.Atoi(a)
				start, valid = v, err == nil
				if b != "" {
					v2, err2 := strconv.Atoi(b)
					end, valid = v2, valid && err2 == nil
				}
			} else if v, err := strconv.Atoi(b); err == nil {
				start = n - v
				if start < 0 {
					start = 0
				}
			} else {
				valid = false
			}
			if end >= n {
				end = n - 1
			}
			if valid && start >= 0 && start <= end {
				h.Set("Content-Range", fmt.Sprintf("bytes %d-%d/%d", start, end, n))
				h.Set("Content-Length", strconv.Itoa(end-start+1))
				w.WriteHeader(206)
				w.Write(body[start : end+1])
				return
			}
		}
	}
	h.Set("Content-Length", strconv.Itoa(len(body)))
	w.WriteHeader(code)
	w.Write(body)
}

func sendJSON(w http.ResponseWriter, r *http.Request, v any) {
	b, _ := json.Marshal(v)
	send(w, r, 200, b, "application/json")
}

func handler(w http.ResponseWriter, r *http.Request) {
	inflight.Add(1)
	defer inflight.Add(-1)
	parts := strings.Split(strings.Trim(r.URL.Path, "/"), "/")
	var err error
	switch {
	case parts[0] == "mpd":
		err = hMPD(w, r)
	case parts[0] == "segi" && len(parts) == 3:
		err = hSegi(w, r, parts[1], parts[2])
	case parts[0] == "segn" && len(parts) == 4:
		n, e2 := strconv.ParseInt(parts[3], 10, 64)
		if e2 != nil {
			send(w, r, 404, nil, "text/plain")
			return
		}
		err = hSegn(w, r, parts[1], parts[2], n)
	case parts[0] == "stats":
		hStats(w, r)
	case parts[0] == "health":
		send(w, r, 200, []byte("ok "+version), "text/plain")
	case parts[0] == "reproxy":
		hReproxy(w, r)
	case parts[0] == "sessions":
		hSessions(w, r)
	default:
		send(w, r, 404, nil, "text/plain")
	}
	if err != nil {
		var he *HTTPError
		if errors.As(err, &he) {
			logf("upstream http %d %.60s", he.Code, r.URL.Path)
			send(w, r, he.Code, nil, "text/plain")
			return
		}
		logf("error %.60s: %.160s", r.URL.Path, err.Error())
		msg := []byte(err.Error())
		if len(msg) > 200 {
			msg = msg[:200]
		}
		send(w, r, 502, msg, "text/plain")
	}
}

func hMPD(w http.ResponseWriter, r *http.Request) error {
	q := r.URL.Query()
	u, proxy := q.Get("u"), q.Get("p")
	delay := 60
	if d, err := strconv.Atoi(q.Get("d")); err == nil {
		delay = d
	}
	if !strings.HasPrefix(u, "http") {
		send(w, r, 400, nil, "text/plain")
		return nil
	}
	sid, body, nreps, err := sessionNew(u, proxy, delay)
	if err != nil {
		return err
	}
	stats.add("sessions_created", 1)
	px := "non"
	if proxy != "" {
		px = "oui"
	}
	logf("session %s delay=%ds reps=%d proxy=%s", sid, delay, nreps, px)
	send(w, r, 200, body, "application/dash+xml")
	return nil
}

func sessRep(sid, rid string) (*Session, *Rep) {
	s := getSess(sid)
	if s == nil {
		return nil, nil
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	e := s.Reps[rid]
	if e == nil {
		return nil, nil
	}
	s.LastUse = nowF()
	return s, e
}

func hSegi(w http.ResponseWriter, r *http.Request, sid, rid string) error {
	s, e := sessRep(sid, rid)
	if e == nil {
		send(w, r, 404, nil, "text/plain")
		return nil
	}
	s.mu.Lock()
	u := joinURL(e.Base, tplSub(e.Init, map[string]string{"RepresentationID": rid, "Bandwidth": e.BW}))
	proxy := s.Proxy
	s.mu.Unlock()
	data, ct, _, err := getSegment(u, proxy, nil)
	if err != nil {
		return err
	}
	send(w, r, 200, data, ct)
	return nil
}

func hSegn(w http.ResponseWriter, r *http.Request, sid, rid string, n int64) error {
	s, e := sessRep(sid, rid)
	if e == nil || n < 1 {
		send(w, r, 404, nil, "text/plain")
		return nil
	}
	t0 := time.Now()
	for {
		s.mu.Lock()
		idx := n - 1 - e.Off
		known := int64(len(e.Segs))
		lr := s.LastRefresh
		s.mu.Unlock()
		if idx < 0 {
			send(w, r, 404, nil, "text/plain")
			return nil
		}
		if idx < known {
			break
		}
		if time.Since(t0) > waitMax {
			stats.add("not_yet", 1)
			logf("not_yet %s/%s#%d known=%d off=%d last_refresh=%.0fs ago url=%.90s", sid, rid, n, known, e.Off, nowF()-lr, s.URL)
			send(w, r, 404, nil, "text/plain")
			return nil
		}
		s.refresh()
		s.mu.Lock()
		still := n-1-e.Off >= int64(len(e.Segs))
		s.mu.Unlock()
		if still {
			time.Sleep(time.Second)
		}
	}
	s.mu.Lock()
	idx := n - 1 - e.Off
	if idx < 0 || idx >= int64(len(e.Segs)) {
		s.mu.Unlock()
		send(w, r, 404, nil, "text/plain")
		return nil
	}
	if idx+refreshAhead >= int64(len(e.Segs)) && nowF()-s.LastRefresh >= refreshMin {
		go s.refresh()
	}
	u := segURL(e, rid, e.Segs[idx])
	e.Hits++
	pn := prefetchN
	if time.Since(startedAt) < warmup {
		pn = 6 // after a restart: refill the cache gradually instead of a burst through the shared tunnels
	}
	if e.Hits <= 3 {
		pn = 2 // ffprobe probes: no big prefetch
	}
	var ahead []string
	for i := idx + 1; i < int64(len(e.Segs)) && i <= idx+int64(pn); i++ {
		ahead = append(ahead, segURL(e, rid, e.Segs[i]))
	}
	proxy := s.Proxy
	s.mu.Unlock()
	data, ct, src, err := getSegment(u, proxy, ahead)
	if err != nil {
		return err
	}
	stats.add(src, 1)
	send(w, r, 200, data, ct)
	s.mu.Lock()
	if cur := n - 1 - e.Off; cur > keepBehind {
		drop := cur - keepBehind
		if drop > int64(len(e.Segs)) {
			drop = int64(len(e.Segs))
		}
		e.Segs = append([]Seg(nil), e.Segs[drop:]...)
		e.Off += drop
	}
	s.mu.Unlock()
	if el := time.Since(t0); el > time.Second {
		logf("seg %s/%s#%d wait=%.2fs size=%d src=%s", sid, rid, n, el.Seconds(), len(data), src)
	}
	return nil
}

func hStats(w http.ResponseWriter, r *http.Request) {
	out := map[string]any{}
	stats.mu.Lock()
	for k, v := range stats.m {
		out[k] = v
	}
	stats.mu.Unlock()
	sessMu.Lock()
	out["sessions"] = len(sessions)
	sessMu.Unlock()
	cacheMu.Lock()
	out["cache_entries"] = len(cache)
	out["cache_mb"] = float64(cacheBytes) / 1048576
	cacheMu.Unlock()
	out["cache_max_mb"] = cacheMax / 1048576
	out["inflight"] = inflight.Load()
	out["goroutines"] = runtime.NumGoroutine()
	out["version"] = version
	sendJSON(w, r, out)
}

func hReproxy(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	u, src, to := q.Get("u"), q.Get("from"), q.Get("to")
	n := 0
	sessMu.Lock()
	for _, s := range sessions {
		s.mu.Lock()
		if (u != "" && s.URL == u) || (src != "" && s.Proxy == src) {
			s.Proxy = to
			n++
		}
		s.mu.Unlock()
	}
	sessMu.Unlock()
	tgt := redactProxy(to)
	if tgt == "" {
		tgt = "direct"
	}
	logf("reproxy %d session(s) -> %s", n, tgt)
	sendJSON(w, r, map[string]int{"changed": n})
}

func hSessions(w http.ResponseWriter, r *http.Request) {
	now := nowF()
	var rows []map[string]any
	sessMu.Lock()
	for k, s := range sessions {
		s.mu.Lock()
		u := s.URL
		if len(u) > 120 {
			u = u[:120]
		}
		rows = append(rows, map[string]any{"sid": k, "url": u, "proxy": redactProxy(s.Proxy),
			"age": int(now - s.Created), "idle": int(now - s.LastUse)})
		s.mu.Unlock()
	}
	sessMu.Unlock()
	sendJSON(w, r, rows)
}

func listener() (net.Listener, error) {
	if os.Getenv("LISTEN_FDS") == "1" {
		if pid := os.Getenv("LISTEN_PID"); pid == "" || pid == strconv.Itoa(os.Getpid()) {
			f := os.NewFile(3, "systemd-socket")
			return net.FileListener(f)
		}
	}
	return net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", port))
}

func main() {
	log.SetFlags(log.Ldate | log.Ltime)
	if len(os.Args) > 1 {
		if p, err := strconv.Atoi(os.Args[1]); err == nil {
			port = p
		}
	}
	cacheMax = int64(envInt("MPDPROXY_CACHE_MB", 0)) * 1048576
	if cacheMax == 0 {
		cacheMax = memTotal() / 4
	}
	workers := envInt("MPDPROXY_FETCH_WORKERS", 64)
	if workers > 64 {
		workers = 64
	}
	perProxy = envInt("MPDPROXY_PER_PROXY", perProxy)
	fetchSem = make(chan struct{}, workers)
	if os.Getenv("GOMEMLIMIT") == "" {
		debug.SetMemoryLimit(cacheMax + cacheMax/2 + 512<<20)
	}
	ln, err := listener()
	if err != nil {
		log.Fatalf("listen: %v", err)
	}
	restored := loadState()
	logf("mpdproxy %s ready port=%d sessions_restored=%d state=%s fetch_workers=%d per_proxy=%d cache_max_mb=%d",
		version, port, restored, stateFile(), workers, perProxy, cacheMax>>20)
	srv := &http.Server{Handler: http.HandlerFunc(handler), ReadHeaderTimeout: 15 * time.Second,
		ErrorLog: log.New(os.Stderr, "http: ", log.LstdFlags)}
	srv.SetKeepAlivesEnabled(false)
	go func() {
		tc := time.NewTicker(5 * time.Second)
		last := time.Now()
		for range tc.C {
			cacheJanitor()
			if time.Since(last) >= saveEvery {
				last = time.Now()
				if err := saveState(); err != nil {
					logf("state save failed: %v", err)
				}
			}
		}
	}()
	sig := make(chan os.Signal, 1)
	signal.Notify(sig, syscall.SIGTERM, syscall.SIGINT)
	go func() {
		<-sig
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		srv.Shutdown(ctx)
	}()
	if err := srv.Serve(ln); err != nil && err != http.ErrServerClosed {
		logf("serve: %v", err)
	}
	time.Sleep(200 * time.Millisecond)
	if err := saveState(); err != nil {
		logf("final state save failed: %v", err)
	}
	sessMu.Lock()
	n := len(sessions)
	sessMu.Unlock()
	logf("mpdproxy stopped, %d sessions saved", n)
}
