package main

import (
	"context"
	"crypto/tls"
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
	slt  bool      // offline video loop
	t    time.Time // arrival
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
	vType        byte // PMT stream type of the video PID
	inbandPS     bool // keyframes carry SPS/PPS in band: joins wait for one of those
	resume       map[string]resumePos
	active       map[string]*viewer // open connection per client key
	lastKeyPTS   int64              // PTS of the last keyframe pushed
	dedup        bool               // source reconnected: drop what viewers already got (provider prebuffer)
	dedupSince   time.Time
	muxEpoch     time.Time     // HLS mux: wall-clock origin so restarted sessions keep one timeline
	muxEnd       int64         // HLS mux: last output timestamp (90 kHz)
	segMin       float64       // HLS segment length (panel seg_time)
	segList      int           // HLS playlist length (panel seg_list_size)
	idle         time.Duration // kept after the last viewer (panel on_demand_instant_off_grace)
	scanT        time.Duration // first valid TS deadline per source (panel on_demand_scan_time)
	stallT       time.Duration // max gap without valid TS once playing (panel segment_wait_time)
	segDel       int           // extra segments kept after the playlist (panel seg_delete_threshold)
	segWait      time.Duration // live: panel segment_wait_time before a silent source is restarted
	panel        string        // last panel values seen (logged on change)
	keySeen      bool
	cl           *http.Client  // per-channel client (XC_VM http_proxy); nil = shared
	unsup        string        // source format Go can't carry whole (golive hands over to ffmpeg)
	failSleep    time.Duration // wait after every source failed (panel stream_fail_sleep)
	failExit     bool          // close a failing hub nobody watches (panel on_demand_failure_exit)
	noVideo      bool          // PMT without video (radio): every PES start is a join point
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
	key string // client (line+IP) for resume on reconnect
}

// resumePos: where a client left, so a quick reconnect continues instead of replaying the prebuffer.
type resumePos struct {
	seq int64
	t   time.Time
}

var client = &http.Client{Timeout: 0, Transport: &http.Transport{
	DialContext:         (&net.Dialer{Timeout: 5 * time.Second}).DialContext,
	TLSHandshakeTimeout: 5 * time.Second,
	TLSClientConfig:     &tls.Config{ClientSessionCache: tls.NewLRUClientSessionCache(512)}, // resumed TLS: one round trip less
	DisableCompression:  true,
	MaxIdleConnsPerHost: 4,
}}

// goodSrc remembers, per channel, the source that last delivered, so the next open skips refused ones.
var (
	goodSrc   = map[string]int{}
	goodSrcMu sync.Mutex
)

func newHub(id string, srcs []string, hdr map[string]string) *Hub {
	h := &Hub{id: id, srcs: srcs, hdr: hdr, lastV: time.Now(), created: time.Now(), lastReal: time.Now(), segSeq: time.Now().Unix(), lastKeyPTS: -1, segMin: segMin, segList: segList, idle: idle, scanT: 8 * time.Second, stallT: 15 * time.Second, segDel: 3, failSleep: 3 * time.Second}
	goodSrcMu.Lock()
	if i, ok := goodSrc[id]; ok && i < len(srcs) {
		h.src = i
	}
	goodSrcMu.Unlock()
	h.cond = sync.NewCond(&h.mu)
	return h
}

func (h *Hub) leave(v *viewer) {
	h.mu.Lock()
	if v != nil && h.active[v.key] == v {
		delete(h.active, v.key)
	}
	if v != nil && v.key != "" && v.pos > 0 {
		if h.resume == nil {
			h.resume = map[string]resumePos{}
		}
		for k, r := range h.resume {
			if time.Since(r.t) > time.Minute {
				delete(h.resume, k)
			}
		}
		h.resume[v.key] = resumePos{v.pos, time.Now()}
	}
	h.viewers--
	h.lastV = time.Now()
	h.mu.Unlock()
}

func (h *Hub) stats() map[string]any {
	h.mu.Lock()
	defer h.mu.Unlock()
	return map[string]any{"viewers": h.viewers, "ring_bytes": h.size, "up_bytes": h.upBytes, "started": h.started,
		"age_s": int(time.Since(h.created).Seconds()), "hls": h.hls, "segs": len(h.segs), "src": h.src, "idle_s": int(time.Since(h.lastV).Seconds()), "offline": h.offline, "info": h.mediaInfo()}
}

// startPos: newest keyframe chunk at least pb old (XC_VM prebuffer: the player gets pb seconds at once),
// else the oldest keyframe in the ring, else the newest chunk. pb 0 = live edge.
func (h *Hub) startPos(pb time.Duration) int64 {
	edge := time.Now().Add(-pb)
	oldest := int64(-1)
	for i := len(h.ring) - 1; i >= 0; i-- {
		if !h.ring[i].key {
			continue
		}
		if pb <= 0 || !h.ring[i].t.After(edge) {
			return h.ring[i].seq
		}
		oldest = h.ring[i].seq
	}
	if oldest >= 0 {
		return oldest
	}
	return h.ring[len(h.ring)-1].seq
}

// waitStart waits for the first keyframe and returns PAT+PMT to send before it.
func (v *viewer) waitStart(ctx context.Context, d, pb time.Duration) ([]byte, bool) {
	h := v.h
	deadline := time.Now().Add(d)
	stop := context.AfterFunc(ctx, func() { h.mu.Lock(); h.cond.Broadcast(); h.mu.Unlock() })
	defer stop()
	h.mu.Lock()
	defer h.mu.Unlock()
	from := int64(-1) // reconnect within 30 s: next keyframe after what this client already got
	if r, ok := h.resume[v.key]; ok && v.key != "" && time.Since(r.t) < 30*time.Second && len(h.ring) > 0 && r.seq >= h.ring[0].seq {
		from = r.seq
		delete(h.resume, v.key)
	}
	if a := h.active[v.key]; v.key != "" && a != nil && a != v && len(h.ring) > 0 && a.pos >= h.ring[0].seq {
		from = a.pos // same client reconnects while its old connection is still open
	}
	if v.key != "" {
		if h.active == nil {
			h.active = map[string]*viewer{}
		}
		h.active[v.key] = v
	}
	for h.keyFrom(from) < 0 && !h.closed && ctx.Err() == nil && time.Now().Before(deadline) {
		waitTimeout(h, time.Until(deadline))
	}
	if len(h.ring) == 0 || h.closed {
		return nil, false
	}
	if p := h.keyFrom(from); from >= 0 && p >= 0 {
		v.pos = p
	} else {
		v.pos = h.startPos(pb)
	}
	return h.ring[v.pos-h.ring[0].seq].psi, true
}

// keyFrom: first keyframe chunk with seq >= from (from < 0: any keyframe), -1 if none.
func (h *Hub) keyFrom(from int64) int64 {
	if from < 0 {
		if h.hasKey() {
			return 0
		}
		return -1
	}
	for _, c := range h.ring {
		if c.key && c.seq >= from {
			return c.seq
		}
	}
	return -1
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
				v.pos = h.startPos(0)
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
	if h.dedup && time.Since(h.dedupSince) < 20*time.Second {
		if k < 0 || pts < 0 || h.lastKeyPTS < 0 {
			return
		}
		d := (pts - h.lastKeyPTS) & (1<<33 - 1)
		if d >= 1<<32 {
			d -= 1 << 33
		}
		if d <= 0 && d > -90000*60 { // same or older picture: already sent to viewers
			return
		}
		b, k = b[k:], 0
	}
	h.dedup = false
	if k >= 0 && pts >= 0 {
		h.lastKeyPTS = pts
	}
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
	if !h.keySeen && k >= 0 {
		h.keySeen = true
		log.Printf("hub %s cold: first keyframe at %dms", h.id, time.Since(h.created).Milliseconds())
	}
	h.started = true
	h.cond.Broadcast()
}

func (h *Hub) add(b []byte, key bool, pts int64) {
	h.seq++
	c := chunk{seq: h.seq, data: b, key: key, pts: pts, t: time.Now()}
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
	h.ring = append(h.ring, chunk{seq: h.seq, data: b, key: true, pts: -1, slt: true, t: time.Now()})
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
		min := h.segMin
		if len(h.segs) == 0 {
			min = 1 // short first segment: HLS opens faster
		}
		if c.key && d >= min {
			h.closeSeg(d)
		} else if now.Sub(h.curT) > time.Duration(max(2*h.segMin, 4)*float64(time.Second)) { // no keyframe seen: cut at 2x panel seg_time
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
	if len(h.segs) > h.segList+h.segDel {
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
	from := n - h.segList
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
			v, t := pmtVideo(p)
			h.vPID, h.vType, h.noVideo = v, t, v == 0
		}
		if key < 0 && h.isKey(p, pid, pusi) {
			key = i
			if pusi {
				kpts = pesPTS(p)
			}
		}
	}
	return key, kpts
}

// isKey: a video access unit a decoder can start on. H.264/HEVC are read from the NAL units (IDR, with SPS/PPS
// when the source sends them in band), not from the random-access flag some sources set on non-IDR frames.
func (h *Hub) isKey(p []byte, pid int, pusi bool) bool {
	rai := p[3]&0x20 != 0 && p[4] > 0 && p[5]&0x40 != 0
	if h.noVideo {
		return pusi && pid != 0 && pid != h.pmtPID && pid != 0x1fff
	}
	if h.vPID == 0 {
		return rai
	}
	if pid != h.vPID || !pusi {
		return false
	}
	if h.vType == 0x01 || h.vType == 0x02 { // MPEG-2: sequence header
		return rai || mpeg2Seq(p)
	}
	if h.vType != 0x1b && h.vType != 0x24 {
		return rai
	}
	ps, idr := nalScan(p, h.vType == 0x24)
	if ps { // parameter sets open the access unit; its IDR slice may be in the next packets
		h.inbandPS = true
		return true
	}
	return idr && !h.inbandPS
}

// nalScan reports parameter-set and IDR/IRAP NAL units in the PES start packet of a video access unit.
func nalScan(p []byte, hevc bool) (ps, idr bool) {
	off := payOff(p)
	if off < 0 || off+9 > pkt {
		return
	}
	q := p[off:]
	if q[0] != 0 || q[1] != 0 || q[2] != 1 {
		return
	}
	es := q[min(9+int(q[8]), len(q)):]
	for i := 0; i+3 < len(es); i++ {
		if es[i] != 0 || es[i+1] != 0 || es[i+2] != 1 {
			continue
		}
		if hevc {
			switch t := (es[i+3] >> 1) & 0x3f; {
			case t >= 32 && t <= 34:
				ps = true
			case t >= 16 && t <= 21:
				idr = true
			}
		} else {
			switch es[i+3] & 0x1f {
			case 7, 8:
				ps = true
			case 5:
				idr = true
			}
		}
		i += 2
	}
	return
}

func mpeg2Seq(p []byte) bool {
	off := payOff(p)
	if off < 0 {
		return false
	}
	q := p[off:]
	for i := 0; i+3 < len(q); i++ {
		if q[i] == 0 && q[i+1] == 0 && q[i+2] == 1 && q[i+3] == 0xb3 {
			return true
		}
	}
	return false
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

func pmtVideo(p []byte) (int, byte) {
	s := section(p)
	if len(s) < 12 || s[0] != 0x02 {
		return 0, 0
	}
	for i := 12 + (int(s[10]&0x0f)<<8 | int(s[11])); i+5 <= len(s); {
		st, pid := s[i], int(s[i+1]&0x1f)<<8|int(s[i+2])
		switch st {
		case 0x01, 0x02, 0x10, 0x1b, 0x24, 0x42, 0xea:
			return pid, st
		}
		i += 5 + (int(s[i+3]&0x0f)<<8 | int(s[i+4]))
	}
	return 0, 0
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
	return h.viewers == 0 && time.Since(h.lastV) > h.idle
}

// tryClose removes the hub atomically with getHub (hubsMu first), so no viewer joins a closing hub.
func (h *Hub) tryClose(force bool) bool {
	return h.closeIf(force, func() bool { return h.viewers == 0 && time.Since(h.lastV) > h.idle })
}

// closeIf closes the hub when ok() holds, checked under the same locks getHub takes: a joining viewer is never cut.
func (h *Hub) closeIf(force bool, ok func() bool) bool {
	hubsMu.Lock()
	defer hubsMu.Unlock()
	h.mu.Lock()
	defer h.mu.Unlock()
	if !force && !ok() {
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
		if fails%len(h.srcs) != 0 { // untried backup sources: no wait
			continue
		}
		h.mu.Lock()
		sleep, exit := h.failSleep, h.failExit
		h.mu.Unlock()
		// panel on_demand_failure_exit: stop asking the provider for a dead channel nobody watches (no TS viewer, no recent HLS poll)
		if exit && h.closeIf(false, func() bool { return h.viewers == 0 && time.Since(h.lastV) > h.failSleep }) {
			log.Printf("hub %s: every source failed, nobody watching -> closed (on_demand_failure_exit)", h.id)
			return
		}
		time.Sleep(sleep) // panel stream_fail_sleep; offline video meanwhile
	}
}

func (h *Hub) pull(src string) int64 {
	if isHLS(src) {
		return h.pullHLS(src)
	}
	h.mu.Lock()
	wait := h.scanT
	h.mu.Unlock()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	req, _ := http.NewRequestWithContext(ctx, "GET", src, nil)
	for k, v := range h.hdr {
		req.Header.Set(k, v)
	}
	hdrWait := time.AfterFunc(wait, cancel) // provider answer bounded by panel on_demand_scan_time
	resp, err := h.httpc().Do(req)
	hdrWait.Stop()
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
	h.mu.Lock()
	if !h.started {
		log.Printf("hub %s cold: source answered in %dms", h.id, time.Since(h.created).Milliseconds())
	}
	si, scanT, stallT := h.src, h.scanT, h.stallT
	if h.started { // reconnect: the provider may resend seconds the viewers already have
		h.dedup, h.dedupSince = true, time.Now()
	}
	h.mu.Unlock()
	goodSrcMu.Lock()
	goodSrc[h.id] = si
	goodSrcMu.Unlock()
	var total int64
	buf := make([]byte, 0, 128*pkt)
	tmp := make([]byte, 32*1024)
	stall := time.AfterFunc(scanT, func() { resp.Body.Close() }) // no valid TS within panel on_demand_scan_time: next source
	defer stall.Stop()
	for {
		n, err := resp.Body.Read(tmp)
		if n > 0 {
			buf = append(buf, tmp[:n]...)
			var good int
			buf, good = h.feed(buf)
			if good > 0 {
				total += int64(good)
				stall.Reset(stallT) // panel segment_wait_time; only valid TS keeps the connection: a 200 page or junk is dropped like a dead source
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

// feed pushes every run of packets that each start with the sync byte, resyncs past corrupt ones
// (like a demuxer), and returns the unconsumed tail and the bytes pushed.
func (h *Hub) feed(buf []byte) ([]byte, int) {
	pushed := 0
	for len(buf) > 0 {
		if buf[0] != 0x47 {
			s := sync47(buf)
			if s < 0 {
				if len(buf) > 2*pkt {
					buf = append(buf[:0], buf[len(buf)-2*pkt:]...)
				}
				break
			}
			buf = buf[s:]
		}
		k := 0
		for (k+1)*pkt <= len(buf) && buf[k*pkt] == 0x47 {
			k++
		}
		bad := k < len(buf)/pkt
		if k >= 16 || (bad && k >= 3) { // a lone 0x47 in junk is not a packet run
			out := make([]byte, k*pkt)
			copy(out, buf)
			h.push(out)
			pushed += len(out)
			buf = buf[k*pkt:]
		}
		if !bad {
			break
		}
		buf = buf[1:]
	}
	return append([]byte(nil), buf...), pushed
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
