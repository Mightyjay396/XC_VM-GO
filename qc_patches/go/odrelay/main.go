// odrelay: fast-start relay for XC_VM on-demand MPEG-TS channels (TS and HLS viewers).
// One upstream connection per channel, shared ring buffer, viewers start on the
// latest keyframe as soon as bytes arrive; channel kept warm IDLE after last viewer.
package main

import (
	"encoding/base64"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
)

var (
	addr     = env("ODRELAY_ADDR", "127.0.0.1:8147")
	idle     = time.Duration(envInt("ODRELAY_IDLE_SEC", 60)) * time.Second
	ringMax  = envInt("ODRELAY_RING_MB", 16) << 20
	startWin = time.Duration(envInt("ODRELAY_START_SEC", 18)) * time.Second
	segMin   = float64(envInt("ODRELAY_SEG_SEC", 2))
	segList  = 5
	segKeep  = 8
	hubs     = map[string]*Hub{}
	hubsMu   sync.Mutex
	idRe     = regexp.MustCompile(`^\d{1,9}$`)
	segRe    = regexp.MustCompile(`^/hls/(\d{1,9})/(\d{1,12})\.ts$`)
	plRe     = regexp.MustCompile(`^/hls/(\d{1,9})/index\.m3u8$`)
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

// getHub returns the channel hub (creating it), joining a TS viewer or touching it for HLS, atomically with tryClose.
func getHub(id string, srcs []string, hdr map[string]string, join, hls bool) (*Hub, *viewer) {
	hubsMu.Lock()
	defer hubsMu.Unlock()
	h := hubs[id]
	if h == nil {
		h = newHub(id, srcs, hdr)
		hubs[id] = h
		go h.run()
		go h.slater()
	}
	h.mu.Lock()
	defer h.mu.Unlock()
	h.lastV = time.Now()
	if hls {
		h.enableHLS()
	}
	if !join {
		return h, nil
	}
	h.viewers++
	return h, &viewer{h: h, pos: -1}
}

// sources: ?u=<b64url source>[&u=...]&h=<b64url json headers>
func sources(r *http.Request) ([]string, map[string]string) {
	var srcs []string
	for _, e := range r.URL.Query()["u"] {
		if u, err := base64.RawURLEncoding.DecodeString(e); err == nil && len(u) > 0 {
			srcs = append(srcs, string(u))
		}
	}
	hdr := map[string]string{}
	if hb, err := base64.RawURLEncoding.DecodeString(r.URL.Query().Get("h")); err == nil && len(hb) > 0 {
		_ = json.Unmarshal(hb, &hdr)
	}
	return srcs, hdr
}

// GET /ts/{id}?u=..&h=..
func serveTS(w http.ResponseWriter, r *http.Request) {
	id := strings.TrimPrefix(r.URL.Path, "/ts/")
	srcs, hdr := sources(r)
	if !idRe.MatchString(id) || len(srcs) == 0 {
		http.Error(w, "bad request", 400)
		return
	}
	h, v := getHub(id, srcs, hdr, true, false)
	defer h.leave(v)
	psi, ok := v.waitStart(r.Context(), startWin)
	if !ok {
		http.Error(w, "source unavailable", 504)
		return
	}
	w.Header().Set("Content-Type", "video/mp2t")
	w.Header().Set("Cache-Control", "no-cache")
	w.WriteHeader(200)
	fl, _ := w.(http.Flusher)
	if _, err := w.Write(psi); err != nil {
		return
	}
	for {
		b, ok := v.next(r.Context())
		if !ok {
			return
		}
		if _, err := w.Write(b); err != nil {
			return
		}
		if fl != nil {
			fl.Flush()
		}
	}
}

// GET /hls/{id}/index.m3u8?u=..&h=..  and  GET /hls/{id}/{seq}.ts
func serveHLS(w http.ResponseWriter, r *http.Request) {
	if m := plRe.FindStringSubmatch(r.URL.Path); m != nil {
		srcs, hdr := sources(r)
		if len(srcs) == 0 {
			http.Error(w, "bad request", 400)
			return
		}
		h, _ := getHub(m[1], srcs, hdr, false, true)
		pl, ok := h.waitSegs(r.Context(), startWin)
		if !ok {
			http.Error(w, "source unavailable", 504)
			return
		}
		w.Header().Set("Content-Type", "application/vnd.apple.mpegurl")
		w.Write([]byte(pl))
		return
	}
	if m := segRe.FindStringSubmatch(r.URL.Path); m != nil {
		hubsMu.Lock()
		h := hubs[m[1]]
		hubsMu.Unlock()
		seq, _ := strconv.ParseInt(m[2], 10, 64)
		var b []byte
		if h != nil {
			b = h.segData(seq)
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

func serveStats(w http.ResponseWriter, _ *http.Request) {
	hubsMu.Lock()
	list := make(map[string]*Hub, len(hubs))
	for id, h := range hubs {
		list[id] = h
	}
	hubsMu.Unlock()
	out := map[string]any{}
	for id, h := range list {
		out[id] = h.stats()
	}
	_ = json.NewEncoder(w).Encode(out)
}

func main() {
	http.HandleFunc("/ts/", serveTS)
	http.HandleFunc("/hls/", serveHLS)
	http.HandleFunc("/stats", serveStats)
	http.HandleFunc("/health", func(w http.ResponseWriter, _ *http.Request) { w.Write([]byte("ok")) })
	loadSlate()
	log.Printf("odrelay on %s slate=%.1fs cold=%s stall=%s idle", addr, slateDur, coldWait, stallWait)
	log.Printf("odrelay on %s idle=%s ring=%dMB start=%s seg=%.0fs", addr, idle, ringMax>>20, startWin, segMin)
	srv := &http.Server{Addr: addr, ReadHeaderTimeout: 10 * time.Second}
	log.Fatal(srv.ListenAndServe())
}
