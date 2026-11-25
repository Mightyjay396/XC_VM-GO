// odrelay: fast-start relay for XC_VM on-demand MPEG-TS channels (TS and HLS viewers).
// One upstream connection per channel, shared ring buffer, viewers start on the
// latest keyframe as soon as bytes arrive; channel kept warm IDLE after last viewer.
package main

import (
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
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

const writeWait = 60 * time.Second

func envInt(k string, d int) int {
	if n, err := strconv.Atoi(os.Getenv(k)); err == nil && n > 0 {
		return n
	}
	return d
}

// getHub returns the channel hub (creating it), joining a TS viewer or touching it for HLS, atomically with tryClose.
func getHub(r *http.Request, id string, srcs []string, hdr map[string]string, join, hls bool) (*Hub, *viewer) {
	hubsMu.Lock()
	defer hubsMu.Unlock()
	h := hubs[id]
	if h == nil {
		h = newHub(id, srcs, hdr)
		h.cl = reqProxy(r) // panel per-stream http proxy
		hubs[id] = h
		go h.run()
		go h.slater()
	}
	h.mu.Lock()
	defer h.mu.Unlock()
	if d := qSec(r, "idle", 0, 5, 3600); d > 0 { // panel on_demand_instant_off_grace
		h.idle = d
	}
	if n := qSec(r, "list", 0, 3, 20); n > 0 { // panel seg_list_size
		h.segList = int(n / time.Second)
	}
	if d := qSec(r, "scan", 0, 3, 60); d > 0 { // panel on_demand_scan_time
		h.scanT = d
	}
	if d := qSec(r, "stall", 0, 5, 120); d > 0 { // panel segment_wait_time
		h.stallT = d
	}
	if n := qSec(r, "del", -1, 0, 20); n >= 0 { // panel seg_delete_threshold
		h.segDel = int(n / time.Second)
	}
	if d := qSec(r, "fsleep", 0, 1, 60); d > 0 { // panel stream_fail_sleep
		h.failSleep = d
	}
	if v := r.URL.Query().Get("fexit"); v != "" { // panel on_demand_failure_exit
		h.failExit = v == "1"
	}
	if d := qSec(r, "seg", 0, 1, 10); d > 0 { // panel seg_time, before enableHLS replays the ring
		h.segMin = d.Seconds()
	}
	if p := fmt.Sprintf("seg=%.0fs list=%d del=%d scan=%s stall=%s idle=%s fsleep=%s fexit=%v", h.segMin, h.segList, h.segDel, h.scanT, h.stallT, h.idle, h.failSleep, h.failExit); p != h.panel {
		log.Printf("hub %s panel: %s", id, p) // logged when the panel values change
		h.panel = p
	}
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

// reqProxy: ?p=<b64url proxy URL> (XC_VM's -http_proxy for the stream) -> client through it.
func reqProxy(r *http.Request) *http.Client {
	pb, err := base64.RawURLEncoding.DecodeString(r.URL.Query().Get("p"))
	if err != nil || len(pb) == 0 {
		return nil
	}
	c, err := proxyClient(string(pb))
	if err != nil {
		return nil
	}
	return c
}

type checkRes struct {
	ok bool
	t  time.Time
}

var (
	checkMu    sync.Mutex
	checkCache = map[string]checkRes{}
)

// GET /check/{id}?u=..&h=..&p=..  HLS source: "ok" if Go carries it whole, "unsup" (encrypted/fMP4) -> PHP keeps ffmpeg. Cached 24 h.
func serveCheck(w http.ResponseWriter, r *http.Request) {
	id := strings.TrimPrefix(r.URL.Path, "/check/")
	srcs, hdr := sources(r)
	if !idRe.MatchString(id) || len(srcs) == 0 {
		http.Error(w, "bad request", 400)
		return
	}
	checkMu.Lock()
	c, hit := checkCache[id]
	checkMu.Unlock()
	if hit && time.Since(c.t) < 24*time.Hour {
		w.Write([]byte(map[bool]string{true: "ok", false: "unsup"}[c.ok]))
		return
	}
	if !isHLS(srcs[0]) {
		w.Write([]byte("ok"))
		return
	}
	h := newHub(id, srcs, hdr)
	h.cl = reqProxy(r)
	body, _, err := h.srcPlaylist(srcs[0], 8*time.Second)
	ok := errors.Is(err, errSepAudio) || (err == nil && parseMedia(body).bad == "")
	if err != nil && !errors.Is(err, errSepAudio) {
		w.Write([]byte("ok")) // source down now: normal on-demand path (offline video / retries), not cached
		return
	}
	checkMu.Lock()
	checkCache[id] = checkRes{ok, time.Now()}
	checkMu.Unlock()
	if !ok {
		log.Printf("hub %s check: HLS not carried whole by Go -> ffmpeg 24 h", id)
	}
	w.Write([]byte(map[bool]string{true: "ok", false: "unsup"}[ok]))
}

// qSec: panel setting passed by PHP in seconds (pb = client/restreamer_prebuffer, w = on_demand_wait_time,
// seg = seg_time), clamped to [lo, hi]; def when absent.
func qSec(r *http.Request, k string, def time.Duration, lo, hi int) time.Duration {
	n, err := strconv.Atoi(r.URL.Query().Get(k))
	if err != nil {
		return def
	}
	return time.Duration(min(max(n, lo), hi)) * time.Second
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
	h, v := getHub(r, id, srcs, hdr, true, false)
	if c := r.URL.Query().Get("c"); len(c) <= 64 {
		v.key = c
	}
	defer h.leave(v)
	psi, ok := v.waitStart(r.Context(), qSec(r, "w", startWin, 3, 60), qSec(r, "pb", 0, 0, 60))
	if !ok {
		http.Error(w, "source unavailable", 504)
		return
	}
	w.Header().Set("Content-Type", "video/mp2t")
	w.Header().Set("Cache-Control", "no-cache")
	w.WriteHeader(200)
	fl, _ := w.(http.Flusher)
	rc := http.NewResponseController(w)
	rc.SetWriteDeadline(time.Now().Add(writeWait))
	if _, err := w.Write(psi); err != nil {
		return
	}
	for {
		b, ok := v.next(r.Context())
		if !ok {
			return
		}
		rc.SetWriteDeadline(time.Now().Add(writeWait)) // reader stuck 60 s: viewer released, no ghost connection
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
		h, _ := getHub(r, m[1], srcs, hdr, false, true)
		pl, ok := h.waitSegs(r.Context(), qSec(r, "w", startWin, 3, 60))
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
	if len(os.Args) > 1 && strings.HasPrefix(os.Args[1], "--ffmpeg=") { // XC_VM live channel: ffmpeg drop-in
		runGoLive()
		return
	}
	http.HandleFunc("/ts/", serveTS)
	http.HandleFunc("/hls/", serveHLS)
	http.HandleFunc("/stats", serveStats)
	http.HandleFunc("/check/", serveCheck)
	http.HandleFunc("/health", func(w http.ResponseWriter, _ *http.Request) { w.Write([]byte("ok")) })
	loadSlate()
	log.Printf("odrelay on %s slate=%.1fs cold=%s stall=%s idle", addr, slateDur, coldWait, stallWait)
	log.Printf("odrelay on %s idle=%s ring=%dMB start=%s seg=%.0fs", addr, idle, ringMax>>20, startWin, segMin)
	srv := &http.Server{Addr: addr, ReadHeaderTimeout: 10 * time.Second}
	log.Fatal(srv.ListenAndServe())
}
