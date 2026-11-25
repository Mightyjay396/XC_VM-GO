package main

import (
	"context"
	"fmt"
	"io"
	"log"
	"math"
	"net"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"
)

const pkt = 188

type chunk struct {
	seq  int64
	data []byte
	key  bool  // starts with a video keyframe packet
	pts  int64 // keyframe PTS (90 kHz) or -1
	psi  []byte
	slt  bool // offline video loop
}

type seg struct {
	seq  int64
	data []byte
	dur  float64
	disc bool
}

type Hub struct {
	id      string
	srcs    []string
	hdr     map[string]string
	mu      sync.Mutex
	cond    *sync.Cond
	ring    []chunk
	size    int
	seq     int64
	viewers int
	lastV   time.Time
	started bool
	closed  bool
	upBytes int64
	created time.Time
	src     int
	// offline video state
	lastReal  time.Time
	lastSlate time.Time
	slateN    int64
	offline   bool
	discNext  bool
	discDrop  int
	anySeg    bool
	quiet     bool // long outage: log one failure in 20
	// PSI: last PAT/PMT packets, video PID
	pat, pmt     []byte
	pmtPID, vPID int
	// HLS segmenter (only once an HLS viewer asked)
	hls    bool
	segs   []seg
	segSeq int64
	cur    []byte
	curPTS int64
	curT   time.Time
	curOn  bool
}

type viewer struct {
	h   *Hub
	pos int64
}

var client = &http.Client{Timeout: 0, Transport: &http.Transport{
	DialContext:           (&net.Dialer{Timeout: 5 * time.Second}).DialContext,
	TLSHandshakeTimeout:   5 * time.Second,
	ResponseHeaderTimeout: 5 * time.Second,
	DisableCompression:    true,
	MaxIdleConnsPerHost:   4,
}}

func newHub(id string, srcs []string, hdr map[string]string) *Hub {
	h := &Hub{id: id, srcs: srcs, hdr: hdr, lastV: time.Now(), created: time.Now(), lastReal: time.Now(), segSeq: time.Now().Unix()}
	h.cond = sync.NewCond(&h.mu)
	return h
}

func (h *Hub) leave(_ *viewer) {
	h.mu.Lock()
	h.viewers--
	h.lastV = time.Now()
	h.mu.Unlock()
}

func (h *Hub) stats() map[string]any {
	h.mu.Lock()
	defer h.mu.Unlock()
	return map[string]any{"viewers": h.viewers, "ring_bytes": h.size, "up_bytes": h.upBytes, "started": h.started,
		"age_s": int(time.Since(h.created).Seconds()), "hls": h.hls, "segs": len(h.segs), "src": h.src, "idle_s": int(time.Since(h.lastV).Seconds()), "offline": h.offline}
}

// startPos: newest keyframe chunk in the ring (fanout-like join), else newest chunk.
func (h *Hub) startPos() int64 {
	for i := len(h.ring) - 1; i >= 0; i-- {
		if h.ring[i].key {
			return h.ring[i].seq
		}
	}
	return h.ring[len(h.ring)-1].seq
}

// waitStart waits for the first keyframe and returns PAT+PMT to send before it.
func (v *viewer) waitStart(ctx context.Context, d time.Duration) ([]byte, bool) {
	h := v.h
	deadline := time.Now().Add(d)
	stop := context.AfterFunc(ctx, func() { h.mu.Lock(); h.cond.Broadcast(); h.mu.Unlock() })
	defer stop()
	h.mu.Lock()
	defer h.mu.Unlock()
	for !h.hasKey() && !h.closed && ctx.Err() == nil && time.Now().Before(deadline) {
		waitTimeout(h, time.Until(deadline))
	}
	if len(h.ring) == 0 || h.closed {
		return nil, false
	}
	v.pos = h.startPos()
	return h.ring[v.pos-h.ring[0].seq].psi, true
}

func (h *Hub) hasKey() bool {
	for i := len(h.ring) - 1; i >= 0; i-- {
		if h.ring[i].key {
			return true
		}
	}
	return false
}

func (v *viewer) next(ctx context.Context) ([]byte, bool) {
	h := v.h
	stop := context.AfterFunc(ctx, func() { h.mu.Lock(); h.cond.Broadcast(); h.mu.Unlock() })
	defer stop()
	h.mu.Lock()
	defer h.mu.Unlock()
	for {
		if ctx.Err() != nil || h.closed {
			return nil, false
		}
		if n := len(h.ring); n > 0 {
			first := h.ring[0].seq
			if v.pos < first { // lagged past the ring: jump to live keyframe instead of disconnecting
				v.pos = h.startPos()
			}
			if v.pos <= h.ring[n-1].seq {
				c := h.ring[v.pos-first]
				v.pos++
				return c.data, true
			}
		}
		if !waitFor(h, 2*time.Second) { // nothing new: a null packet keeps the player's socket alive
			return nullPkt, true
		}
	}
}

var nullPkt = append([]byte{0x47, 0x1f, 0xff, 0x10}, make([]byte, pkt-4)...)

// waitFor waits on the hub cond up to d; false on timeout.
func waitFor(h *Hub, d time.Duration) bool {
	timedOut := false
	t := time.AfterFunc(d, func() { h.mu.Lock(); timedOut = true; h.cond.Broadcast(); h.mu.Unlock() })
	h.cond.Wait()
	t.Stop()
	return !timedOut
}

func waitTimeout(h *Hub, d time.Duration) {
	if d <= 0 {
		return
	}
	t := time.AfterFunc(d, func() { h.mu.Lock(); h.cond.Broadcast(); h.mu.Unlock() })
	h.cond.Wait()
	t.Stop()
}

// push stores aligned TS packets; a chunk is split at its first keyframe so joins start exactly on it.
func (h *Hub) push(b []byte) {
	h.mu.Lock()
	defer h.mu.Unlock()
	k, pts := h.scan(b)
	if h.offline && len(h.pat) > 0 { // back from the offline video: real PSI first
		log.Printf("hub %s source back, offline video off", h.id)
		h.offline, h.discNext = false, true
		b = append(append(append([]byte{}, h.pat...), h.pmt...), b...)
		if k >= 0 {
			k += len(h.pat) + len(h.pmt)
		}
	}
	h.lastReal = time.Now()
	if k > 0 {
		h.add(b[:k], false, -1)
		h.add(b[k:], true, pts)
	} else {
		h.add(b, k == 0, pts)
	}
	h.upBytes += int64(len(b))
	h.started = true
	h.cond.Broadcast()
}

func (h *Hub) add(b []byte, key bool, pts int64) {
	h.seq++
	c := chunk{seq: h.seq, data: b, key: key, pts: pts}
	if key {
		c.psi = append(append([]byte{}, h.pat...), h.pmt...)
	}
	h.ring = append(h.ring, c)
	h.size += len(b)
	for h.size > ringMax && len(h.ring) > 1 {
		h.size -= len(h.ring[0].data)
		h.ring[0] = chunk{}
		h.ring = h.ring[1:]
	}
	if h.hls {
		h.segment(c)
	}
}

// addSlate stores one offline-video loop as a keyframe chunk (it carries its own PSI) and as its own HLS segment.
func (h *Hub) addSlate(b []byte) {
	h.seq++
	h.ring = append(h.ring, chunk{seq: h.seq, data: b, key: true, pts: -1, slt: true})
	h.size += len(b)
	for h.size > ringMax && len(h.ring) > 1 {
		h.size -= len(h.ring[0].data)
		h.ring[0] = chunk{}
		h.ring = h.ring[1:]
	}
	if h.hls {
		h.slateSeg(b)
	}
}

func (h *Hub) slateSeg(b []byte) {
	if h.curOn {
		h.closeSeg(time.Since(h.curT).Seconds())
	}
	h.cur = b
	h.discNext = true
	h.closeSeg(slateDur)
	h.discNext = true
}

// enableHLS starts the segmenter, replaying the ring so a warm channel has segments at once.
func (h *Hub) enableHLS() {
	if h.hls {
		return
	}
	h.hls = true
	for _, c := range h.ring {
		if c.slt {
			h.slateSeg(c.data)
		} else {
			h.segment(c)
		}
	}
}

func (h *Hub) segment(c chunk) {
	now := time.Now()
	if h.curOn {
		d := ptsDur(h.curPTS, c.pts)
		if d < 0 {
			d = now.Sub(h.curT).Seconds()
		}
		min := segMin
		if len(h.segs) == 0 {
			min = 1 // short first segment: HLS opens faster
		}
		if c.key && d >= min {
			h.closeSeg(d)
		} else if now.Sub(h.curT) > 12*time.Second { // no keyframe seen: cut anyway
			h.closeSeg(now.Sub(h.curT).Seconds())
			h.openSeg(c, now)
		}
	}
	if !h.curOn {
		if !c.key {
			return
		}
		h.openSeg(c, now)
	}
	h.cur = append(h.cur, c.data...)
}

func (h *Hub) openSeg(c chunk, now time.Time) {
	h.cur = append(append(make([]byte, 0, 2<<20), h.pat...), h.pmt...)
	h.curPTS, h.curT, h.curOn = c.pts, now, true
}

func (h *Hub) closeSeg(d float64) {
	h.segSeq++
	h.segs = append(h.segs, seg{seq: h.segSeq, data: h.cur, dur: d, disc: h.discNext && h.anySeg})
	h.discNext, h.anySeg = false, true
	if len(h.segs) > segKeep {
		if h.segs[0].disc {
			h.discDrop++
		}
		h.segs[0] = seg{}
		h.segs = h.segs[1:]
	}
	h.cur, h.curOn = nil, false
}

func ptsDur(a, b int64) float64 {
	if a < 0 || b < 0 {
		return -1
	}
	f := float64((b-a)&(1<<33-1)) / 90000
	if f > 30 {
		return -1
	}
	return f
}

func (h *Hub) playlist() string {
	n := len(h.segs)
	from := n - segList
	if from < 0 {
		from = 0
	}
	target := 1.0
	for _, s := range h.segs[from:] {
		target = math.Max(target, s.dur)
	}
	var b strings.Builder
	disc := h.discDrop
	for _, s := range h.segs[:from] {
		if s.disc {
			disc++
		}
	}
	fmt.Fprintf(&b, "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:%d\n#EXT-X-MEDIA-SEQUENCE:%d\n#EXT-X-DISCONTINUITY-SEQUENCE:%d\n", int(math.Ceil(target)), h.segs[from].seq, disc)
	for _, s := range h.segs[from:] {
		if s.disc {
			b.WriteString("#EXT-X-DISCONTINUITY\n")
		}
		fmt.Fprintf(&b, "#EXTINF:%.3f,\n%d.ts\n", s.dur, s.seq)
	}
	return b.String()
}

func (h *Hub) waitSegs(ctx context.Context, d time.Duration) (string, bool) {
	deadline := time.Now().Add(d)
	stop := context.AfterFunc(ctx, func() { h.mu.Lock(); h.cond.Broadcast(); h.mu.Unlock() })
	defer stop()
	h.mu.Lock()
	defer h.mu.Unlock()
	for len(h.segs) == 0 && !h.closed && ctx.Err() == nil && time.Now().Before(deadline) {
		waitTimeout(h, time.Until(deadline))
	}
	if len(h.segs) == 0 || h.closed {
		return "", false
	}
	h.lastV = time.Now()
	return h.playlist(), true
}

func (h *Hub) segData(seq int64) []byte {
	h.mu.Lock()
	defer h.mu.Unlock()
	h.lastV = time.Now()
	for _, s := range h.segs {
		if s.seq == seq {
			return s.data
		}
	}
	return nil
}

// scan updates PAT/PMT/video PID and returns the offset of the first video keyframe packet (-1 if none) and its PTS.
func (h *Hub) scan(b []byte) (int, int64) {
	key, kpts := -1, int64(-1)
	for i := 0; i+pkt <= len(b); i += pkt {
		p := b[i : i+pkt]
		pid := int(p[1]&0x1f)<<8 | int(p[2])
		pusi := p[1]&0x40 != 0
		switch {
		case pid == 0 && pusi:
			h.pat = append(h.pat[:0], p...)
			if m := patPMT(p); m > 0 {
				h.pmtPID = m
			}
		case pid == h.pmtPID && pid != 0 && pusi:
			h.pmt = append(h.pmt[:0], p...)
			if v := pmtVideo(p); v > 0 {
				h.vPID = v
			}
		}
		if key < 0 && (h.vPID == 0 || pid == h.vPID) && p[3]&0x20 != 0 && p[4] > 0 && p[5]&0x40 != 0 {
			key = i
			if pusi {
				kpts = pesPTS(p)
			}
		}
	}
	return key, kpts
}

func payOff(p []byte) int {
	off := 4
	if p[3]&0x20 != 0 {
		off += 1 + int(p[4])
	}
	if p[3]&0x10 == 0 || off >= pkt {
		return -1
	}
	return off
}

func section(p []byte) []byte {
	off := payOff(p)
	if off < 0 {
		return nil
	}
	off += 1 + int(p[off])
	if off+3 > pkt {
		return nil
	}
	s := p[off:]
	l := 3 + (int(s[1]&0x0f)<<8 | int(s[2])) - 4
	if l > len(s) {
		l = len(s)
	}
	return s[:l]
}

func patPMT(p []byte) int {
	s := section(p)
	if len(s) < 8 || s[0] != 0x00 {
		return 0
	}
	for i := 8; i+4 <= len(s); i += 4 {
		if prog := int(s[i])<<8 | int(s[i+1]); prog != 0 {
			return int(s[i+2]&0x1f)<<8 | int(s[i+3])
		}
	}
	return 0
}

func pmtVideo(p []byte) int {
	s := section(p)
	if len(s) < 12 || s[0] != 0x02 {
		return 0
	}
	for i := 12 + (int(s[10]&0x0f)<<8 | int(s[11])); i+5 <= len(s); {
		st, pid := s[i], int(s[i+1]&0x1f)<<8|int(s[i+2])
		switch st {
		case 0x01, 0x02, 0x10, 0x1b, 0x24, 0x42, 0xea:
			return pid
		}
		i += 5 + (int(s[i+3]&0x0f)<<8 | int(s[i+4]))
	}
	return 0
}

func pesPTS(p []byte) int64 {
	off := payOff(p)
	if off < 0 || off+14 > pkt {
		return -1
	}
	q := p[off:]
	if q[0] != 0 || q[1] != 0 || q[2] != 1 || q[7]&0x80 == 0 {
		return -1
	}
	return int64(q[9]&0x0e)<<29 | int64(q[10])<<22 | int64(q[11]&0xfe)<<14 | int64(q[12])<<7 | int64(q[13])>>1
}

func (h *Hub) idleExpired() bool {
	h.mu.Lock()
	defer h.mu.Unlock()
	return h.viewers == 0 && time.Since(h.lastV) > idle
}

// tryClose removes the hub atomically with getHub (hubsMu first), so no viewer joins a closing hub.
func (h *Hub) tryClose(force bool) bool {
	hubsMu.Lock()
	defer hubsMu.Unlock()
	h.mu.Lock()
	defer h.mu.Unlock()
	if !force && !(h.viewers == 0 && time.Since(h.lastV) > idle) {
		return false
	}
	h.closed = true
	h.ring, h.segs, h.cur = nil, nil, nil
	h.cond.Broadcast()
	if hubs[h.id] == h {
		delete(hubs, h.id)
	}
	log.Printf("hub %s closed (forced=%v up=%dB)", h.id, force, h.upBytes)
	return true
}

// run keeps one upstream connection, rotating through the channel's sources on failure.
func (h *Hub) run() {
	fails := 0
	for {
		if h.tryClose(false) {
			return
		}
		h.mu.Lock()
		src := h.srcs[h.src]
		h.mu.Unlock()
		h.quiet = fails >= 3 && fails%20 != 0
		if h.pull(src) > 0 {
			fails = 0
			continue
		}
		fails++
		h.mu.Lock()
		h.src = (h.src + 1) % len(h.srcs)
		h.mu.Unlock()
		switch { // source down: keep retrying behind the offline video while someone watches
		case fails <= 20:
			time.Sleep(300 * time.Millisecond)
		case fails <= 60:
			time.Sleep(time.Second)
		default:
			time.Sleep(3 * time.Second)
		}
	}
}

func (h *Hub) pull(src string) int64 {
	req, _ := http.NewRequest("GET", src, nil)
	for k, v := range h.hdr {
		req.Header.Set(k, v)
	}
	resp, err := client.Do(req)
	if err != nil {
		if ue, ok := err.(*url.Error); ok {
			err = ue.Err // never log the source URL (provider credentials)
		}
		if !h.quiet {
			log.Printf("hub %s connect: %v", h.id, err)
		}
		return 0
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		if !h.quiet {
			log.Printf("hub %s upstream HTTP %d", h.id, resp.StatusCode)
		}
		return 0
	}
	var total int64
	buf := make([]byte, 0, 128*pkt)
	tmp := make([]byte, 32*1024)
	stall := time.AfterFunc(15*time.Second, func() { resp.Body.Close() })
	defer stall.Stop()
	for {
		n, err := resp.Body.Read(tmp)
		if n > 0 {
			stall.Reset(15 * time.Second)
			buf = append(buf, tmp[:n]...)
			if len(buf) > 0 && buf[0] != 0x47 {
				if s := sync47(buf); s >= 0 {
					buf = buf[s:]
				} else if len(buf) > 2*pkt {
					buf = append(buf[:0], buf[len(buf)-2*pkt:]...)
				}
			}
			if buf[0] == 0x47 {
				if m := len(buf) / pkt * pkt; m >= 16*pkt {
					out := make([]byte, m)
					copy(out, buf[:m])
					buf = append(buf[:0], buf[m:]...)
					h.push(out)
					total += int64(m)
				}
			}
		}
		if err != nil || h.idleExpired() {
			if err != nil && err != io.EOF {
				log.Printf("hub %s upstream: %v", h.id, err)
			}
			return total
		}
	}
}

// sync47 returns the offset of the first byte aligned on three consecutive TS sync bytes, -1 if none.
func sync47(b []byte) int {
	for i := 0; i+2*pkt < len(b); i++ {
		if b[i] == 0x47 && b[i+pkt] == 0x47 && b[i+2*pkt] == 0x47 {
			return i
		}
	}
	return -1
}
