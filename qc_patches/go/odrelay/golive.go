package main

import (
	"bufio"
	"context"
	"crypto/sha256"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"math"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"regexp"
	"runtime"
	"runtime/debug"
	"runtime/metrics"
	"runtime/pprof"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// ---------- HLS source input (unencrypted MPEG-TS segments) ----------

func (h *Hub) httpc() *http.Client {
	if h.cl != nil {
		return h.cl
	}
	return client
}

// proxyClient: the shared client's settings through XC_VM's per-stream HTTP proxy.
func proxyClient(p string) (*http.Client, error) {
	if !strings.Contains(p, "://") {
		p = "http://" + p
	}
	u, err := url.Parse(p)
	if err != nil {
		return nil, err
	}
	t := client.Transport.(*http.Transport).Clone()
	t.Proxy = http.ProxyURL(u)
	return &http.Client{Transport: t}, nil
}

func (h *Hub) setUnsup(why string) {
	h.mu.Lock()
	h.unsup = why
	h.mu.Unlock()
}

// defaultAudioSeparate: the default audio rendition lives in its own playlist (TVA); a muxed default (ICI) needs no merge.
func defaultAudioSeparate(master string) bool {
	sep, muxed := false, false
	for _, l := range strings.FieldsFunc(master, func(r rune) bool { return r == 10 || r == 13 }) {
		if strings.HasPrefix(l, "#EXT-X-MEDIA:") && strings.Contains(l, "TYPE=AUDIO") && strings.Contains(l, "DEFAULT=YES") {
			if strings.Contains(l, "URI=") {
				sep = true
			} else {
				muxed = true // e.g. ICI: main audio inside the video segments, extra described-video track aside
			}
		}
	}
	return sep && !muxed
}

func isHLS(src string) bool {
	u, err := url.Parse(src)
	return err == nil && strings.HasSuffix(strings.ToLower(u.Path), ".m3u8")
}

func (h *Hub) get(ctx context.Context, u string) (*http.Response, error) {
	req, _ := http.NewRequestWithContext(ctx, "GET", u, nil)
	for k, v := range h.hdr {
		req.Header.Set(k, v)
	}
	return h.httpc().Do(req)
}

// playlist fetches a media playlist (following a master to its highest-bandwidth variant); returns body and final URL.
func (h *Hub) srcPlaylist(u string, wait time.Duration) (string, string, error) {
	for range 2 {
		ctx, cancel := context.WithTimeout(context.Background(), wait)
		resp, err := h.get(ctx, u)
		if err != nil {
			cancel()
			return "", "", err
		}
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
		resp.Body.Close()
		cancel()
		if resp.StatusCode != 200 {
			return "", "", fmt.Errorf("playlist HTTP %d", resp.StatusCode)
		}
		final, body := resp.Request.URL, string(b)
		if defaultAudioSeparate(body) {
			return "", "", errSepAudio
		}
		if !strings.Contains(body, "#EXT-X-STREAM-INF") {
			return body, final.String(), nil
		}
		best, bw := "", -1
		lines := strings.FieldsFunc(body, func(r rune) bool { return r == 10 || r == 13 })
		for i, l := range lines {
			if !strings.HasPrefix(l, "#EXT-X-STREAM-INF") {
				continue
			}
			m := regexp.MustCompile(`BANDWIDTH=(\d+)`).FindStringSubmatch(l)
			n := 0
			if m != nil {
				n, _ = strconv.Atoi(m[1])
			}
			for j := i + 1; j < len(lines); j++ {
				if v := strings.TrimSpace(lines[j]); v != "" && !strings.HasPrefix(v, "#") {
					if n > bw {
						best, bw = v, n
					}
					break
				}
			}
		}
		r, err := final.Parse(best)
		if best == "" || err != nil {
			return "", "", fmt.Errorf("master without variant")
		}
		u = r.String()
	}
	return "", "", fmt.Errorf("nested master")
}

type mediaPL struct {
	seq    int64
	segs   []string
	target float64
	ended  bool
	bad    string
}

func parseMedia(body string) mediaPL {
	p := mediaPL{target: 6}
	for _, l := range strings.FieldsFunc(body, func(r rune) bool { return r == 10 || r == 13 }) {
		l = strings.TrimSpace(l)
		switch {
		case strings.HasPrefix(l, "#EXT-X-MEDIA-SEQUENCE:"):
			p.seq, _ = strconv.ParseInt(strings.TrimPrefix(l, "#EXT-X-MEDIA-SEQUENCE:"), 10, 64)
		case strings.HasPrefix(l, "#EXT-X-TARGETDURATION:"):
			p.target, _ = strconv.ParseFloat(strings.TrimPrefix(l, "#EXT-X-TARGETDURATION:"), 64)
		case strings.HasPrefix(l, "#EXT-X-KEY:") && !strings.Contains(l, "METHOD=NONE"):
			p.bad = "encrypted HLS"
		case strings.HasPrefix(l, "#EXT-X-MAP:"):
			p.bad = "fMP4 HLS"
		case l == "#EXT-X-ENDLIST":
			p.ended = true
		case l != "" && !strings.HasPrefix(l, "#"):
			p.segs = append(p.segs, l)
		}
	}
	return p
}

// pullHLS follows a live HLS source from its edge and feeds its TS segments like a TS stream.
func (h *Hub) pullHLS(src string) int64 {
	h.mu.Lock()
	scanT, stallT, si := h.scanT, h.stallT, h.src
	if h.started {
		h.dedup, h.dedupSince = true, time.Now()
	}
	h.mu.Unlock()
	body, media, err := h.srcPlaylist(src, scanT)
	if errors.Is(err, errSepAudio) {
		return h.pullHLSMux(src)
	}
	if err != nil {
		log.Printf("hub %s hls: %v", h.id, err)
		return 0
	}
	var total int64
	last, lastNew := int64(-1), time.Now()
	for !h.idleExpired() {
		p := parseMedia(body)
		if p.bad != "" {
			h.setUnsup(p.bad)
			log.Printf("hub %s hls: %s not supported", h.id, p.bad)
			return total
		}
		base, _ := url.Parse(media)
		for i, s := range p.segs {
			n := p.seq + int64(i)
			if n <= last || (last < 0 && i < len(p.segs)-3) { // first pass: start 3 segments from the edge
				continue
			}
			su, err := base.Parse(s)
			if err != nil {
				continue
			}
			got := h.fetchSeg(su.String(), stallT)
			if got <= 0 {
				return total
			}
			if total == 0 {
				goodSrcMu.Lock()
				goodSrc[h.id] = si
				goodSrcMu.Unlock()
			}
			total += got
			last, lastNew = n, time.Now()
		}
		if p.ended || time.Since(lastNew) > stallT {
			return total
		}
		time.Sleep(time.Duration(max(p.target/2, 1) * float64(time.Second)))
		if body, media, err = h.srcPlaylist(media, scanT); err != nil {
			log.Printf("hub %s hls: %v", h.id, err)
			return total
		}
	}
	return total
}

func (h *Hub) fetchSeg(u string, wait time.Duration) int64 {
	ctx, cancel := context.WithTimeout(context.Background(), 4*wait)
	defer cancel()
	resp, err := h.get(ctx, u)
	if err != nil {
		return 0
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return 0
	}
	var total int64
	buf, tmp := make([]byte, 0, 128*pkt), make([]byte, 32*1024)
	for {
		n, err := resp.Body.Read(tmp)
		if n > 0 {
			buf = append(buf, tmp[:n]...)
			var good int
			buf, good = h.feed(buf)
			total += int64(good)
		}
		if err != nil {
			return total
		}
	}
}

// ---------- ffmpeg drop-in for XC_VM live channels (copy -> HLS on disk) ----------

type ffOpts struct {
	in, progress, pattern, out, proxy string
	hdr                               map[string]string
	hlsTime, initTime                 float64
	list, del                         int
	rw, analyze                       time.Duration
}

const (
	panelSettingsFile = "/home/xc_vm/tmp/cache/settings"
	panelPHP          = "/home/xc_vm/bin/php/bin/php"
)

// panelSettings reads XC_VM's settings cache (igbinary) through XC_VM's own PHP.
func panelSettings() map[string]string {
	out, err := exec.Command(panelPHP, "-r", `$s=@igbinary_unserialize(@file_get_contents("`+panelSettingsFile+`"));echo json_encode(is_array($s)?$s:[]);`).Output()
	r := map[string]string{}
	m := map[string]any{}
	if err != nil || json.Unmarshal(out, &m) != nil {
		return r
	}
	for k, v := range m {
		if v != nil {
			r[k] = fmt.Sprint(v)
		}
	}
	return r
}

func settingsSum() [32]byte {
	b, _ := os.ReadFile(panelSettingsFile)
	return sha256.Sum256(b)
}

// liveCfg: the panel values a live channel runs with; command values first, panel settings override (and follow later edits).
type liveCfg struct {
	seg               float64
	list, del         int
	wait, fail, probe time.Duration
}

func (o ffOpts) cfg(ps map[string]string) liveCfg {
	num := func(k string) (float64, bool) {
		v, err := strconv.ParseFloat(strings.TrimSpace(ps[k]), 64)
		return v, err == nil
	}
	c := liveCfg{seg: o.hlsTime, list: o.list, del: o.del, wait: 20 * time.Second, fail: 3 * time.Second}
	if v, ok := num("seg_time"); ok && v >= 1 {
		c.seg = v
	}
	if v, ok := num("seg_list_size"); ok && v >= 1 {
		c.list = int(v)
	}
	if v, ok := num("seg_delete_threshold"); ok && v >= 0 {
		c.del = int(v)
	}
	if v, ok := num("segment_wait_time"); ok && v >= 1 {
		c.wait = time.Duration(v * float64(time.Second))
	}
	if v, ok := num("stream_fail_sleep"); ok && v >= 0 {
		c.fail = time.Duration(v * float64(time.Second))
	}
	an := o.analyze // XC_VM probe timeout: analyzeduration + probe_extra_wait, then the first segment
	if an == 0 {
		if v, ok := num("stream_max_analyze"); ok {
			an = time.Duration(v) * time.Microsecond
		}
	}
	extra := 10 * time.Second
	if v, ok := num("probe_extra_wait"); ok && v >= 0 {
		extra = time.Duration(v * float64(time.Second))
	}
	c.probe = an + extra + time.Duration(c.seg*float64(time.Second))
	return c
}

func (h *Hub) applyLive(c liveCfg, rw time.Duration) {
	h.mu.Lock()
	h.segMin, h.segList, h.segDel = c.seg, max(c.list, 1), max(c.del, 0)
	h.scanT, h.stallT = rw, rw // XC_VM's -rw_timeout: read deadline per source
	h.segWait, h.failSleep = c.wait, c.fail
	h.mu.Unlock()
}

// parseFF accepts only XC_VM's plain copy-to-HLS command; anything else (transcode, filters, proxy, DRM) stays on ffmpeg.
func parseFF(a []string) (ffOpts, string) {
	o := ffOpts{hdr: map[string]string{}, hlsTime: 6, initTime: 2, list: 8, del: 3}
	inputs, vcopy, acopy, hls := 0, false, false, false
	for i := 0; i < len(a); i++ {
		k, v := a[i], ""
		if i+1 < len(a) {
			v = a[i+1]
		}
		switch k {
		case "-i":
			inputs++
			o.in = v
			i++
		case "-headers":
			for _, l := range strings.Split(strings.ReplaceAll(v, "\r", ""), "\n") {
				if kk, vv, ok := strings.Cut(l, ":"); ok {
					o.hdr[strings.TrimSpace(kk)] = strings.TrimSpace(vv)
				}
			}
			i++
		case "-http_proxy":
			o.proxy = v
			i++
		case "-user_agent":
			o.hdr["User-Agent"] = v
			i++
		case "-rw_timeout":
			if us, err := strconv.ParseInt(v, 10, 64); err == nil && us > 0 {
				o.rw = time.Duration(us) * time.Microsecond
			}
			i++
		case "-analyzeduration":
			if us, err := strconv.ParseInt(v, 10, 64); err == nil && us > 0 {
				o.analyze = time.Duration(us) * time.Microsecond
			}
			i++
		case "-progress":
			o.progress = v
			i++
		case "-vcodec", "-c:v":
			vcopy = v == "copy"
			i++
		case "-acodec", "-c:a":
			acopy = v == "copy"
			i++
		case "-f":
			hls = hls || v == "hls"
			i++
		case "-hls_time":
			o.hlsTime, _ = strconv.ParseFloat(v, 64)
			i++
		case "-hls_init_time":
			o.initTime, _ = strconv.ParseFloat(v, 64)
			i++
		case "-hls_list_size":
			o.list, _ = strconv.Atoi(v)
			i++
		case "-hls_delete_threshold":
			o.del, _ = strconv.Atoi(v)
			i++
		case "-hls_segment_filename":
			o.pattern = v
			i++
		case "-hls_segment_type":
			if v != "mpegts" {
				return o, "segment type " + v
			}
			i++
		case "-vf", "-af", "-filter_complex", "-cookies", "-decryption_key", "-cenc_decryption_key", "-c", "-codec", "-s", "-r", "-b:v", "-b:a":
			return o, "option " + k
		}
	}
	if len(a) > 0 && strings.HasSuffix(a[len(a)-1], ".m3u8") {
		o.out = a[len(a)-1]
	}
	switch {
	case inputs != 1 || !strings.HasPrefix(o.in, "http"):
		return o, "input"
	case !vcopy || !acopy:
		return o, "transcode"
	case !hls || o.out == "" || !strings.Contains(o.pattern, "%d"):
		return o, "output"
	}
	return o, ""
}

var golivePIDs = regexp.MustCompile(`^(\d+)_`)

func runGoLive() {
	if f := os.Getenv("GOLIVE_CPUPROFILE"); f != "" { // diagnostics: 30 s CPU profile
		if fh, err := os.Create(f); err == nil && pprof.StartCPUProfile(fh) == nil {
			time.AfterFunc(30*time.Second, func() { pprof.StopCPUProfile(); fh.Close() })
		}
	}
	real := strings.TrimPrefix(os.Args[1], "--ffmpeg=")
	args := os.Args[2:]
	fallback := func(why string) {
		log.Printf("golive: %s -> ffmpeg", why)
		_ = syscall.Exec(real, append([]string{real}, args...), os.Environ())
		os.Exit(1)
	}
	o, why := parseFF(args)
	if why != "" {
		fallback("unsupported " + why)
	}
	m := golivePIDs.FindStringSubmatch(filepath.Base(o.out))
	if m == nil {
		fallback("output name")
	}
	id := m[1]
	failF := "/tmp/golive_" + id + ".fail"
	mark := "/tmp/golive_" + id + ".ffmpeg"
	if st, err := os.Stat(mark); err == nil && time.Since(st.ModTime()) < 24*time.Hour {
		b, _ := os.ReadFile(mark)
		fallback(strings.TrimSpace(string(b)) + " (remembered 24 h)")
	}
	if recentFails(failF) >= 3 {
		fallback("3 Go failures in 10 min")
	}
	// RAM like ffmpeg: no TS viewer reads this hub's ring, and segments live on disk once written
	ringMax = 2 << 20
	runtime.GOMAXPROCS(min(runtime.NumCPU(), 4)) // a copy job needs few threads; also caps GC workers per channel
	debug.SetGCPercent(-1)                       // collect only near the limit: far less GC CPU, RAM stays bounded
	debug.SetMemoryLimit(48 << 20)
	go memGuard(id)
	h := newHub(id, []string{o.in}, o.hdr)
	if o.proxy != "" {
		c, err := proxyClient(o.proxy)
		if err != nil {
			fallback("proxy " + err.Error())
		}
		h.cl = c
	}
	rw := o.rw
	if rw == 0 {
		rw = 10 * time.Second
	}
	cfg := o.cfg(panelSettings())
	h.applyLive(cfg, rw)
	h.mu.Lock()
	h.idle = 100 * 365 * 24 * time.Hour
	h.enableHLS()
	h.mu.Unlock()
	log.Printf("golive %s panel: seg=%gs list=%d del=%d wait=%s fail_sleep=%s rw=%s first_video=%s", id, cfg.seg, cfg.list, cfg.del, cfg.wait, cfg.fail, rw, cfg.probe)
	go h.run()
	var setMod time.Time
	if st, err := os.Stat(panelSettingsFile); err == nil {
		setMod = st.ModTime()
	}
	setSum := settingsSum()
	lastChk := time.Now()
	sig := make(chan os.Signal, 1)
	signal.Notify(sig, syscall.SIGTERM, syscall.SIGINT)
	w := &hlsWriter{o: o, t0: time.Now(), last: -1}
	w.o.hlsTime, w.o.list, w.o.del = cfg.seg, cfg.list, cfg.del
	tick := time.NewTicker(250 * time.Millisecond)
	for {
		select {
		case <-sig:
			os.Exit(0)
		case <-tick.C:
		}
		h.mu.Lock()
		var news []seg
		for i, s := range h.segs {
			if s.seq > w.last {
				news = append(news, s)
				h.segs[i].data = nil // written to disk below; the hub only needs seq/dur
			}
		}
		vpid, unsup := h.vPID, h.unsup
		h.mu.Unlock()
		if unsup != "" { // e.g. HLS audio in its own rendition: ffmpeg merges it, Go would drop the sound
			os.WriteFile(mark, []byte(unsup), 0o664)
			fallback("source " + unsup)
		}
		for _, s := range news {
			w.write(s, vpid)
		}
		w.writeProgress()
		if time.Since(lastChk) > 10*time.Second { // panel edits apply live (XC_VM refreshes its settings cache)
			lastChk = time.Now()
			if st, err := os.Stat(panelSettingsFile); err == nil && !st.ModTime().Equal(setMod) {
				setMod = st.ModTime()
				// XC_VM rewrites the cache every minute: start PHP only when its content really changed
				if sum := settingsSum(); sum != setSum {
					setSum = sum
					if c := o.cfg(panelSettings()); c != cfg {
						cfg = c
						h.applyLive(cfg, rw)
						w.o.hlsTime, w.o.list, w.o.del = cfg.seg, cfg.list, cfg.del
						log.Printf("golive %s panel changed: seg=%gs list=%d del=%d wait=%s fail_sleep=%s first_video=%s", id, cfg.seg, cfg.list, cfg.del, cfg.wait, cfg.fail, cfg.probe)
					}
				}
			}
		}
		switch {
		case w.n == 0 && time.Since(w.t0) > cfg.probe:
			addFail(failF)
			fallback(fmt.Sprintf("no video from the source in %s", cfg.probe))
		case w.n > 0 && time.Since(w.lastW) > cfg.wait:
			addFail(failF)
			log.Printf("golive %s: source silent %s (segment_wait_time), exit for XC_VM restart", id, cfg.wait)
			os.Exit(1)
		}
	}
}

type hlsWriter struct {
	o            ffOpts
	t0, lastW    time.Time
	last         int64
	n            int
	pl           []plEntry
	frames, size int64
	outUs        float64
	lastProg     time.Time
	fps          float64
}

type plEntry struct {
	name string
	dur  float64
	disc bool
	num  int
}

func (w *hlsWriter) write(s seg, vpid int) {
	name := fmt.Sprintf(w.o.pattern, w.n)
	tmp := name + ".tmp"
	if err := os.WriteFile(tmp, s.data, 0o664); err != nil || os.Rename(tmp, name) != nil {
		return
	}
	fr := frames(s.data, vpid)
	if s.dur > 0 {
		w.fps = float64(fr) / s.dur
	}
	w.frames += int64(fr)
	w.size += int64(len(s.data))
	w.outUs += s.dur * 1e6
	w.pl = append(w.pl, plEntry{filepath.Base(name), s.dur, s.disc && w.n > 0, w.n})
	w.last, w.lastW = s.seq, time.Now()
	w.n++
	keep := w.o.list + w.o.del
	for len(w.pl) > keep {
		os.Remove(filepath.Join(filepath.Dir(name), w.pl[0].name))
		w.pl = w.pl[1:]
	}
	show := w.pl
	if len(show) > w.o.list {
		show = show[len(show)-w.o.list:]
	}
	td := 0.0
	for _, e := range show {
		td = math.Max(td, e.dur)
	}
	var b strings.Builder
	fmt.Fprintf(&b, "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:%d\n#EXT-X-MEDIA-SEQUENCE:%d\n", int(math.Ceil(td)), show[0].num)
	for _, e := range show {
		if e.disc {
			b.WriteString("#EXT-X-DISCONTINUITY\n")
		}
		fmt.Fprintf(&b, "#EXTINF:%.6f,\n%s\n", e.dur, e.name)
	}
	if os.WriteFile(w.o.out+".tmp", []byte(b.String()), 0o664) == nil {
		os.Rename(w.o.out+".tmp", w.o.out)
	}
}

// writeProgress appends ffmpeg-style -progress blocks, read by XC_VM's StreamsCronJob/MonitorCommand.
func (w *hlsWriter) writeProgress() {
	if w.o.progress == "" || time.Since(w.lastProg) < time.Second {
		return
	}
	w.lastProg = time.Now()
	f, err := os.OpenFile(w.o.progress, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o664)
	if err != nil {
		return
	}
	defer f.Close()
	us := int64(w.outUs)
	br := "N/A"
	if w.outUs > 0 {
		br = fmt.Sprintf("%.1fkbits/s", float64(w.size)*8/(w.outUs/1e6)/1000)
	}
	bw := bufio.NewWriter(f)
	fmt.Fprintf(bw, "frame=%d\nfps=%.2f\nstream_0_0_q=-1.0\nbitrate=%s\ntotal_size=%d\nout_time_us=%d\nout_time_ms=%d\nout_time=%02d:%02d:%02d.%06d\ndup_frames=0\ndrop_frames=0\nspeed=1x\nprogress=continue\n",
		w.frames, w.fps, br, w.size, us, us, us/3600e6, us/60e6%60, us/1e6%60, us%1e6)
	bw.Flush()
}

func recentFails(f string) int {
	b, _ := os.ReadFile(f)
	n := 0
	for _, l := range strings.Fields(string(b)) {
		if t, err := strconv.ParseInt(l, 10, 64); err == nil && time.Since(time.Unix(t, 0)) < 10*time.Minute {
			n++
		}
	}
	return n
}

func addFail(f string) {
	fh, err := os.OpenFile(f, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o664)
	if err == nil {
		fmt.Fprintf(fh, "%d\n", time.Now().Unix())
		fh.Close()
	}
}

// frames counts pictures for the progress fps: access unit delimiters when the stream has them
// (a PES may carry several pictures), else video PES starts.
func frames(d []byte, vpid int) int {
	pes, aud := 0, 0
	for i := 0; i+pkt <= len(d); i += pkt {
		p := d[i : i+pkt]
		if p[0] != 0x47 || (int(p[1]&0x1f)<<8|int(p[2])) != vpid {
			continue
		}
		if p[1]&0x40 != 0 {
			pes++
		}
		o := 4
		if p[3]&0x20 != 0 {
			o += 1 + int(p[4])
		}
		for j := o; j+4 < pkt; j++ {
			if p[j] == 0 && p[j+1] == 0 && p[j+2] == 1 && (p[j+3] == 0x09 || (p[j+3] == 0x46 && p[j+4] == 0x01)) {
				aud++
			}
		}
	}
	if aud > 0 {
		return aud
	}
	return pes
}

// memGuard keeps the memory limit above twice the live heap: a limit under the live heap makes the GC run non-stop
// (seen on an HLS source with separate audio: 7+ cores busy in GC).
func memGuard(id string) {
	smp := []metrics.Sample{{Name: "/gc/heap/live:bytes"}}
	lim, n := int64(48<<20), 0
	for range time.Tick(2 * time.Second) {
		metrics.Read(smp)
		live := int64(smp[0].Value.Uint64())
		if want := max(48<<20, 2*live); want > lim*5/4 || want < lim*3/4 {
			lim = want
			debug.SetMemoryLimit(lim)
		}
		if n++; n%300 == 0 { // every 10 min
			log.Printf("golive %s mem: live=%dMB limit=%dMB goroutines=%d", id, live>>20, lim>>20, runtime.NumGoroutine())
		}
	}
}
