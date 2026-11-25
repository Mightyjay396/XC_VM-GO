package main

import (
	"fmt"
	"sync"
	"sync/atomic"
	"time"
)

const (
	stQueued  int32 = 0
	stRunning int32 = 1
	stClaimed int32 = 2
)

type entry struct {
	state    atomic.Int32
	done     chan struct{}
	data     []byte
	ctype    string
	ts       time.Time
	consumed time.Time
	proxy    string
}

var (
	cacheMu    sync.Mutex
	cache      = map[string]*entry{}
	cacheBytes int64
	fetchSem   chan struct{}
)

func finish(e *entry, data []byte, ct string) {
	cacheMu.Lock()
	e.data, e.ctype = data, ct
	if data != nil {
		cacheBytes += int64(len(data))
	}
	cacheMu.Unlock()
	close(e.done)
}

// prefetchInto: retries 403/404/412 (not yet on edge) and network errors (switching to a fallback tunnel) for PREFETCH_RETRY_S.
func prefetchInto(e *entry, u string) {
	fetchSem <- struct{}{}
	defer func() { <-fetchSem }()
	if !e.state.CompareAndSwap(stQueued, stRunning) {
		return
	}
	deadline := time.Now().Add(prefetchRetry)
	proxy := e.proxy
	var fbs []string
	for {
		data, ct, err := fetchSeg(u, proxy, slowSeg)
		if err == nil {
			finish(e, data, ct)
			return
		}
		if time.Now().After(deadline) {
			break
		}
		if retryableCode(err) {
			time.Sleep(2 * time.Second)
			continue
		}
		if _, ok := err.(*HTTPError); ok {
			break
		}
		if fbs == nil {
			fbs = fallbackProxies(e.proxy)
		}
		if len(fbs) > 0 {
			proxy, fbs = fbs[0], fbs[1:]
		} else {
			time.Sleep(time.Second)
		}
	}
	finish(e, nil, "")
}

func prefetch(urls []string, proxy string) {
	now := time.Now()
	cacheMu.Lock()
	var todo []*entry
	var todoU []string
	for _, u := range urls {
		if _, ok := cache[u]; ok {
			continue
		}
		e := &entry{done: make(chan struct{}), ts: now, proxy: proxy}
		cache[u] = e
		todo = append(todo, e)
		todoU = append(todoU, u)
	}
	cacheMu.Unlock()
	for i := range todo {
		go prefetchInto(todo[i], todoU[i])
	}
}

// getSegment: cache (wait ≤ WAIT_MAX) then live fetch with failover until WAIT_MAX. Consumed entries are kept a few
// seconds so an ffmpeg Range reconnect is served from memory.
func getSegment(u, proxy string, ahead []string) ([]byte, string, string, error) {
	t0 := time.Now()
	prefetch(ahead, proxy)
	cacheMu.Lock()
	e := cache[u]
	cacheMu.Unlock()
	if e != nil {
		if e.state.CompareAndSwap(stQueued, stClaimed) {
			// queued behind other prefetches: fetch it now ourselves
			data, ct, src, err := liveFetch(u, proxy, t0)
			if err == nil {
				finish(e, data, ct)
			} else {
				finish(e, nil, "")
			}
			markConsumed(e)
			return data, ct, src, err
		}
		ready := false
		select {
		case <-e.done:
			ready = true
		default:
			select {
			case <-e.done:
			case <-time.After(waitMax):
			}
		}
		cacheMu.Lock()
		data, ct := e.data, e.ctype
		cacheMu.Unlock()
		if data != nil {
			markConsumed(e)
			if ready {
				return data, ct, "cache", nil
			}
			return data, ct, "cache-wait", nil
		}
	}
	return liveFetch(u, proxy, t0)
}

func markConsumed(e *entry) {
	cacheMu.Lock()
	if e.consumed.IsZero() {
		e.consumed = time.Now()
	}
	cacheMu.Unlock()
}

func liveFetch(u, proxy string, t0 time.Time) ([]byte, string, string, error) {
	var fbs []string
	failed := false
	for {
		left := respondBy - time.Since(t0)
		if left < 2*time.Second {
			return nil, "", "", fmt.Errorf("segment not obtained within %s", respondBy)
		}
		// a WARP flow stuck at a few hundred kb/s is cut and retried on another tunnel; last attempt gets all the time left
		att := left
		if att > slowSeg+2*time.Second {
			att = slowSeg
		}
		data, ct, err := fetchSeg(u, proxy, att)
		if err == nil {
			if failed {
				return data, ct, "live-failover", nil
			}
			return data, ct, "live", nil
		}
		if time.Since(t0) > waitMax {
			return nil, "", "", err
		}
		if retryableCode(err) {
			time.Sleep(500 * time.Millisecond)
			continue
		}
		if _, ok := err.(*HTTPError); ok {
			return nil, "", "", err
		}
		if fbs == nil {
			fbs = fallbackProxies(proxy)
		}
		if len(fbs) > 0 {
			proxy, fbs, failed = fbs[0], fbs[1:], true
			logf("seg failover -> %s url=...%s", hostOf(proxy), tail(u, 60))
		} else {
			time.Sleep(time.Second)
		}
	}
}

func tail(s string, n int) string {
	if len(s) > n {
		return s[len(s)-n:]
	}
	return s
}

// cacheJanitor: drop expired / consumed entries, then oldest until under the byte cap.
func cacheJanitor() {
	now := time.Now()
	cacheMu.Lock()
	defer cacheMu.Unlock()
	for k, e := range cache {
		if now.Sub(e.ts) > cacheTTL || (!e.consumed.IsZero() && now.Sub(e.consumed) > 15*time.Second) {
			dropEntry(k, e)
		}
	}
	if cacheBytes <= cacheMax {
		return
	}
	type kv struct {
		k string
		e *entry
	}
	var all []kv
	for k, e := range cache {
		if e.data != nil {
			all = append(all, kv{k, e})
		}
	}
	sortByTS(all, func(i int) time.Time { return all[i].e.ts })
	for _, x := range all {
		if cacheBytes <= cacheMax {
			break
		}
		dropEntry(x.k, x.e)
	}
}

func dropEntry(k string, e *entry) {
	select {
	case <-e.done:
		cacheBytes -= int64(len(e.data))
		delete(cache, k)
	default:
		// in flight: keep (it will be dropped by TTL once finished)
	}
}
