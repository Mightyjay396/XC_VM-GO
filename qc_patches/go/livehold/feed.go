package main

import (
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

// entry: one segment of the continuous feed served to clients (a file ffmpeg wrote, or the offline slate).
type entry struct {
	vseq  int64
	path  string
	dur   float64
	disc  bool
	slate bool
}

// Feed tracks one 24/7 channel's on-disk HLS output across ffmpeg restarts. No media is
// held in RAM: each client reads the segment files at its own position (like PHP did).
type Feed struct {
	id        string
	mu        sync.Mutex
	cond      *sync.Cond
	ents      []entry
	vseq      int64
	discBase  int64
	seen      map[uint64]bool // segment inodes already in the feed
	seenOrder []uint64
	lastN     int
	pendDisc  bool
	lastReal  time.Time
	lastSlate time.Time
	viewers   int
	lastTouch time.Time
	closed    bool
}

var segName = regexp.MustCompile(`^(\d+)_(\d+)\.ts$`)

func newFeed(id string) *Feed {
	f := &Feed{id: id, seen: map[uint64]bool{}, lastN: -1, vseq: time.Now().Unix(), lastTouch: time.Now()}
	f.cond = sync.NewCond(&f.mu)
	f.mu.Lock()
	f.poll(true)
	f.mu.Unlock()
	return f
}

type plItem struct {
	n   int
	dur float64
}

func readPlaylist(id string) []plItem {
	b, err := os.ReadFile(filepath.Join(streamsDir, id+"_.m3u8"))
	if err != nil {
		return nil
	}
	var out []plItem
	dur := 0.0
	for _, l := range strings.Split(string(b), "\n") {
		l = strings.TrimSpace(l)
		if strings.HasPrefix(l, "#EXTINF:") {
			dur, _ = strconv.ParseFloat(strings.SplitN(l[8:], ",", 2)[0], 64)
		} else if m := segName.FindStringSubmatch(l); m != nil && m[1] == id {
			n, _ := strconv.Atoi(m[2])
			out = append(out, plItem{n, dur})
		}
	}
	return out
}

// poll appends new ffmpeg segments (any restart = new inodes), and the offline slate while the channel is down.
func (f *Feed) poll(first bool) {
	now := time.Now()
	added := false
	for _, it := range readPlaylist(f.id) {
		p := filepath.Join(streamsDir, f.id+"_"+strconv.Itoa(it.n)+".ts")
		st, err := os.Stat(p)
		if err != nil {
			continue
		}
		ino := st.Sys().(*syscall.Stat_t).Ino
		if f.seen[ino] {
			continue
		}
		f.markSeen(ino)
		disc := f.pendDisc || (f.lastN >= 0 && it.n != f.lastN+1)
		f.add(entry{path: p, dur: it.dur, disc: disc && len(f.ents) > 0})
		f.pendDisc, f.lastN, added = false, it.n, true
		if first {
			f.lastReal = st.ModTime()
		} else {
			f.lastReal = now
		}
	}
	if added {
		f.cond.Broadcast()
		return
	}
	down := now.Sub(f.lastReal)
	if !first && len(f.ents) > 0 && down > frozenWait && down < holdMax && f.active(now) && now.Sub(f.lastSlate) >= slateDur {
		f.add(entry{slate: true, dur: slateDur.Seconds(), disc: true})
		f.lastSlate, f.pendDisc = now, true
		f.cond.Broadcast()
	}
}

func (f *Feed) markSeen(ino uint64) {
	f.seen[ino] = true
	f.seenOrder = append(f.seenOrder, ino)
	if len(f.seenOrder) > 200 {
		delete(f.seen, f.seenOrder[0])
		f.seenOrder = f.seenOrder[1:]
	}
}

func (f *Feed) add(e entry) {
	f.vseq++
	e.vseq = f.vseq
	f.ents = append(f.ents, e)
	for len(f.ents) > 40 {
		if f.ents[1].disc {
			f.discBase++
		}
		f.ents = f.ents[1:]
	}
}

func (f *Feed) active(now time.Time) bool {
	return f.viewers > 0 || now.Sub(f.lastTouch) < 30*time.Second
}

// dead: no real segment for holdMax — clients are released (as XC would show off-air).
func (f *Feed) dead() bool {
	return len(f.ents) == 0 || time.Since(f.lastReal) > holdMax
}

func (f *Feed) run() {
	t := time.NewTicker(500 * time.Millisecond)
	defer t.Stop()
	for range t.C {
		feedsMu.Lock()
		f.mu.Lock()
		if f.viewers == 0 && time.Since(f.lastTouch) > idle {
			f.closed = true
			f.cond.Broadcast()
			delete(feeds, f.id)
			f.mu.Unlock()
			feedsMu.Unlock()
			return
		}
		feedsMu.Unlock()
		f.poll(false)
		f.mu.Unlock()
	}
}

func (f *Feed) find(vseq int64) (entry, bool) {
	if len(f.ents) == 0 {
		return entry{}, false
	}
	i := vseq - f.ents[0].vseq
	if i < 0 || i >= int64(len(f.ents)) {
		return entry{}, false
	}
	return f.ents[i], true
}

// startSeq: the entry that gives the client `pb` seconds of buffer (XC client_prebuffer).
func (f *Feed) startSeq(pb float64) int64 {
	sum := 0.0
	i := len(f.ents) - 1
	for ; i > 0 && sum < pb; i-- {
		sum += f.ents[i].dur
	}
	return f.ents[i].vseq
}

func readEntry(e entry) []byte {
	if e.slate {
		return slate
	}
	b, err := os.ReadFile(e.path)
	if err != nil {
		return nil
	}
	return b
}
