package main

import (
	"bufio"
	"context"
	"crypto/tls"
	"fmt"
	"io"
	"math/rand"
	"net"
	"net/http"
	"net/url"
	"os"
	"strings"
	"sync"
	"time"
)

const UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36"

type HTTPError struct{ Code int }

func (e *HTTPError) Error() string { return fmt.Sprintf("upstream http %d", e.Code) }

var (
	clientsMu sync.Mutex
	clients   = map[string]*http.Client{}
	slots     = map[string]chan struct{}{}
	perProxy  = 20 // concurrent upstream requests per tunnel per shard: 16 shards x 20 < tinyproxy MaxClients 500
)

func slotFor(proxy string) chan struct{} {
	clientsMu.Lock()
	defer clientsMu.Unlock()
	c := slots[proxy]
	if c == nil {
		c = make(chan struct{}, perProxy)
		slots[proxy] = c
	}
	return c
}

// one keep-alive transport per proxy: TLS/CONNECT tunnels are reused instead of re-handshaking every segment.
func clientFor(proxy string) *http.Client {
	clientsMu.Lock()
	defer clientsMu.Unlock()
	if c := clients[proxy]; c != nil {
		return c
	}
	tr := &http.Transport{
		DialContext:           (&net.Dialer{Timeout: 8 * time.Second, KeepAlive: 30 * time.Second}).DialContext,
		TLSHandshakeTimeout:   10 * time.Second,
		ResponseHeaderTimeout: 45 * time.Second,
		MaxIdleConns:          32,
		MaxIdleConnsPerHost:   4,
		IdleConnTimeout:       15 * time.Second,
		TLSClientConfig:       &tls.Config{},
		ForceAttemptHTTP2:     false,
		DisableCompression:    true,
	}
	if proxy != "" {
		if u, err := url.Parse(proxy); err == nil {
			tr.Proxy = http.ProxyURL(u)
		}
	}
	c := &http.Client{Transport: tr}
	clients[proxy] = c
	return c
}

// idleReader aborts the request when no byte arrives for `idle` (like a socket timeout), without capping slow-but-
// progressing transfers (some manifests can take 15-30 s through a tunnel).
type idleReader struct {
	r    io.Reader
	t    *time.Timer
	idle time.Duration
}

func (i *idleReader) Read(p []byte) (int, error) {
	n, err := i.r.Read(p)
	if n > 0 {
		i.t.Reset(i.idle)
	}
	return n, err
}

// fetch returns the full body or an error; a short body (cut connection) is an error, never a truncated segment.
func fetch(rawurl, proxy string, idle, total time.Duration) ([]byte, string, string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), total)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, "GET", rawurl, nil)
	if err != nil {
		return nil, "", "", err
	}
	req.Header.Set("User-Agent", UA)
	slot := slotFor(proxy)
	select {
	case slot <- struct{}{}:
	case <-ctx.Done():
		stats.add("slot_wait_timeout", 1)
		return nil, "", "", fmt.Errorf("tunnel busy: %w", ctx.Err())
	}
	defer func() { <-slot }()
	timer := time.AfterFunc(idle+20*time.Second, cancel) // connect + CONNECT + first byte
	defer timer.Stop()
	resp, err := clientFor(proxy).Do(req)
	if err != nil {
		return nil, "", "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		io.Copy(io.Discard, io.LimitReader(resp.Body, 1<<16))
		return nil, "", "", &HTTPError{resp.StatusCode}
	}
	timer.Reset(idle)
	var buf []byte
	if resp.ContentLength > 0 && resp.ContentLength < 64<<20 {
		buf = make([]byte, 0, resp.ContentLength)
	}
	w := &sliceWriter{buf}
	if _, err = io.Copy(w, &idleReader{resp.Body, timer, idle}); err != nil {
		if ctx.Err() == context.DeadlineExceeded {
			stats.add("slow_abort", 1)
		}
		return nil, "", "", err
	}
	if resp.ContentLength >= 0 && int64(len(w.b)) != resp.ContentLength {
		return nil, "", "", fmt.Errorf("short body %d/%d", len(w.b), resp.ContentLength)
	}
	ct := resp.Header.Get("Content-Type")
	if ct == "" {
		ct = "video/mp4"
	}
	return w.b, ct, resp.Request.URL.String(), nil
}

type sliceWriter struct{ b []byte }

func (s *sliceWriter) Write(p []byte) (int, error) { s.b = append(s.b, p...); return len(p), nil }

const fallbackFile = "/opt/mpdproxy_fallbacks.txt"

var (
	fbMu   sync.Mutex
	fbAt   time.Time
	fbList []string
)

func fallbackProxies(exclude string) []string {
	fbMu.Lock()
	if time.Since(fbAt) > 60*time.Second {
		fbList = nil
		if f, err := os.Open(fallbackFile); err == nil {
			sc := bufio.NewScanner(f)
			for sc.Scan() {
				l := strings.TrimSpace(sc.Text())
				if l != "" && !strings.HasPrefix(l, "#") {
					fbList = append(fbList, l)
				}
			}
			f.Close()
		}
		fbAt = time.Now()
	}
	var out []string
	for _, x := range fbList {
		if x != exclude {
			out = append(out, x)
		}
	}
	fbMu.Unlock()
	rand.Shuffle(len(out), func(i, j int) { out[i], out[j] = out[j], out[i] })
	return out
}

func hostOf(proxy string) string {
	if proxy == "" {
		return "direct"
	}
	if i := strings.LastIndex(proxy, "@"); i >= 0 {
		return proxy[i+1:]
	}
	return proxy
}

// fetchFailover: session proxy first, then `tries` fallback tunnels; returns the proxy that worked.
func fetchFailover(rawurl, proxy string, total time.Duration, tries int) ([]byte, string, string, error) {
	data, _, final, err := fetch(rawurl, proxy, 30*time.Second, total)
	if err == nil {
		return data, final, proxy, nil
	}
	last := err
	fbs := fallbackProxies(proxy)
	if len(fbs) > tries {
		fbs = fbs[:tries]
	}
	for _, fb := range fbs {
		data, _, final, err = fetch(rawurl, fb, 30*time.Second, total)
		if err == nil {
			logf("failover %s -> %s ok url=%.80s", hostOf(proxy), hostOf(fb), rawurl)
			return data, final, fb, nil
		}
		last = err
	}
	return nil, "", "", last
}

func retryableCode(err error) bool {
	if he, ok := err.(*HTTPError); ok {
		return he.Code == 403 || he.Code == 404 || he.Code == 412
	}
	return false
}

// fetchSeg = fetch + ISO-BMFF integrity: the top-level boxes must add up exactly to the body length, so a segment
// cut by a tunnel/CDN (even without Content-Length) is retried instead of reaching ffmpeg ("partial file", "subsample size").
func fetchSeg(rawurl, proxy string, total time.Duration) ([]byte, string, error) {
	data, ct, _, err := fetch(rawurl, proxy, 12*time.Second, total)
	if err != nil {
		return nil, "", err
	}
	if !isoComplete(data) {
		stats.add("truncated", 1)
		return nil, "", fmt.Errorf("truncated mp4 segment (%d bytes)", len(data))
	}
	return data, ct, nil
}

func isoComplete(b []byte) bool {
	if len(b) < 8 || !fourcc(b[4:8]) {
		return true // not ISO-BMFF (ts, vtt...): nothing to verify
	}
	off := 0
	for off < len(b) {
		if len(b)-off < 8 || !fourcc(b[off+4:off+8]) {
			return false
		}
		sz := uint64(b[off])<<24 | uint64(b[off+1])<<16 | uint64(b[off+2])<<8 | uint64(b[off+3])
		hdr := uint64(8)
		if sz == 1 {
			if len(b)-off < 16 {
				return false
			}
			sz = 0
			for i := 8; i < 16; i++ {
				sz = sz<<8 | uint64(b[off+i])
			}
			hdr = 16
		} else if sz == 0 {
			return true // box extends to end of data
		}
		if sz < hdr || sz > uint64(len(b)-off) {
			return false
		}
		off += int(sz)
	}
	return off == len(b)
}

func fourcc(t []byte) bool {
	for _, c := range t {
		if !(c >= 'a' && c <= 'z' || c >= 'A' && c <= 'Z' || c >= '0' && c <= '9' || c == ' ' || c == '-' || c == '_') {
			return false
		}
	}
	return true
}
