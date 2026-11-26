package main

import (
	"errors"
	"log"
	"sync"
	"sync/atomic"
	"time"

	"github.com/bluenviron/gohlslib/v2"
	"github.com/bluenviron/gohlslib/v2/pkg/codecs"
	"github.com/bluenviron/mediacommon/v2/pkg/formats/mpegts"
	tscodecs "github.com/bluenviron/mediacommon/v2/pkg/formats/mpegts/codecs"
)

var errSepAudio = errors.New("separate audio rendition")

// tsFeed hands the muxer's TS packets to the hub like bytes read from a TS source.
type tsFeed struct {
	h     *Hub
	buf   []byte
	last  time.Time
	total atomic.Int64
}

// Write batches the muxer's many small writes: feeding the hub per packet cost ~15% CPU (copies + GC).
func (f *tsFeed) Write(p []byte) (int, error) {
	f.buf = append(f.buf, p...)
	if len(f.buf) < 256*pkt && time.Since(f.last) < 200*time.Millisecond {
		return len(p), nil
	}
	f.last = time.Now()
	var good int
	f.buf, good = f.h.feed(f.buf)
	f.total.Add(int64(good))
	return len(p), nil
}

// pullHLSMux: HLS source whose audio is a separate rendition (e.g. TVA).
// gohlslib reads video + audio renditions in sync, mediacommon muxes them into one TS for the hub.
func (h *Hub) pullHLSMux(src string) int64 {
	h.mu.Lock()
	stallT, segWait := h.stallT, h.segWait
	if h.dedup { // gohlslib replays ~3 segments in real time: keep dropping already-sent pictures until the new edge
		h.dedupSince = time.Now().Add(30 * time.Second)
	}
	h.mu.Unlock()
	f := &tsFeed{h: h}
	var mu sync.Mutex
	last := map[*gohlslib.Track]time.Time{} // last data per track: gohlslib keeps running while one rendition died
	offSet, off := false, int64(0)
	quiet := func(string) {}
	c := &gohlslib.Client{URI: src, HTTPClient: h.httpc(), OnDownloadPrimaryPlaylist: quiet, OnDownloadStreamPlaylist: quiet,
		OnDownloadSegment: quiet, OnDownloadPart: quiet, OnDecodeError: func(err error) {}}
	// ts maps a session timestamp onto the hub timeline: wall clock (PROGRAM-DATE-TIME) when present, else continue after the last one.
	ts := func(in *gohlslib.Track, t int64) int64 {
		rate := int64(in.ClockRate)
		t = t * 90000 / rate
		if !offSet {
			offSet = true
			if h.muxEpoch.IsZero() {
				h.muxEpoch = time.Now().Add(-10 * time.Second)
			}
			if ntp, ok := c.AbsoluteTime(in); ok && ntp.After(h.muxEpoch) {
				off = int64(ntp.Sub(h.muxEpoch).Seconds()*90000) - t
			} else {
				off = h.muxEnd + 3003 - t
			}
		}
		last[in] = time.Now()
		v := (t + off) & (1<<33 - 1)
		h.muxEnd = max(h.muxEnd, t+off)
		return v
	}
	c.OnTracks = func(tracks []*gohlslib.Track) error {
		type pair struct {
			in  *gohlslib.Track
			out *mpegts.Track
		}
		var ps []pair
		var outs []*mpegts.Track
		for _, t := range tracks {
			var mc tscodecs.Codec
			switch cc := t.Codec.(type) {
			case *codecs.H264:
				mc = &tscodecs.H264{}
			case *codecs.H265:
				mc = &tscodecs.H265{}
			case *codecs.MPEG4Audio:
				mc = &tscodecs.MPEG4Audio{Config: cc.Config}
			case *codecs.Opus:
				mc = &tscodecs.Opus{ChannelCount: cc.ChannelCount}
			default:
				continue
			}
			o := &mpegts.Track{Codec: mc}
			ps, outs = append(ps, pair{t, o}), append(outs, o)
		}
		w := &mpegts.Writer{W: f, Tracks: outs}
		if err := w.Initialize(); err != nil {
			return err
		}
		for _, p := range ps {
			in, out := p.in, p.out
			switch in.Codec.(type) {
			case *codecs.H264:
				c.OnDataH26x(in, func(pts, dts int64, au [][]byte) {
					mu.Lock()
					w.WriteH264(out, ts(in, pts), ts(in, dts), au)
					mu.Unlock()
				})
			case *codecs.H265:
				c.OnDataH26x(in, func(pts, dts int64, au [][]byte) {
					mu.Lock()
					w.WriteH265(out, ts(in, pts), ts(in, dts), au)
					mu.Unlock()
				})
			case *codecs.MPEG4Audio:
				c.OnDataMPEG4Audio(in, func(pts int64, aus [][]byte) {
					mu.Lock()
					w.WriteMPEG4Audio(out, ts(in, pts), aus)
					mu.Unlock()
				})
			case *codecs.Opus:
				c.OnDataOpus(in, func(pts int64, pkts [][]byte) {
					mu.Lock()
					w.WriteOpus(out, ts(in, pts), pkts)
					mu.Unlock()
				})
			}
		}
		log.Printf("hub %s hls: %d tracks merged (video + separate audio)", h.id, len(outs))
		return nil
	}
	if err := c.Start(); err != nil {
		log.Printf("hub %s hls mux: %v", h.id, err)
		return 0
	}
	defer c.Close()
	tick := time.NewTicker(time.Second)
	defer tick.Stop()
	lim := max(stallT, 15*time.Second)
	if segWait > 0 { // live: panel segment_wait_time
		lim = segWait
	}
	start := time.Now()
	done := c.Wait() // once: each Wait() call starts a goroutine that lives until the client ends (leaked ~1/s)
	for {
		select {
		case err := <-done:
			log.Printf("hub %s hls mux ended: %v", h.id, err)
			return f.total.Load()
		case <-tick.C:
			if h.idleExpired() {
				return f.total.Load()
			}
			mu.Lock()
			var dead time.Duration
			for _, t := range last {
				dead = max(dead, time.Since(t))
			}
			none := len(last) == 0
			mu.Unlock()
			if dead > lim || (none && time.Since(start) > 2*lim) {
				log.Printf("hub %s hls mux: a track stopped (%s without data), restarting", h.id, dead.Round(time.Second))
				return f.total.Load()
			}
		}
	}
}
