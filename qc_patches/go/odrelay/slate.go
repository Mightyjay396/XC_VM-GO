package main

import (
	"log"
	"os"
	"time"
)

// Offline video shown while a channel's source is down; the hub keeps retrying the source behind it.
var (
	slatePath  = env("ODRELAY_SLATE", "/opt/odrelay/offline.ts")
	coldWait   = time.Duration(envInt("ODRELAY_OFFLINE_COLD_SEC", 10)) * time.Second
	stallWait  = time.Duration(envInt("ODRELAY_OFFLINE_STALL_SEC", 20)) * time.Second
	slate      []byte
	slateDur   float64
	slate90k   int64
	slateCount = map[int]int{} // payload packets per PID in one loop (keeps CC continuous across loops)
)

func loadSlate() {
	b, err := os.ReadFile(slatePath)
	if err != nil || len(b) < 2*pkt || len(b)%pkt != 0 || b[0] != 0x47 {
		log.Fatalf("slate %s unusable: %v", slatePath, err)
	}
	slate = b
	lo, hi, frames := int64(-1), int64(-1), 0
	var vpid, pmtPID int
	for i := 0; i+pkt <= len(b); i += pkt {
		p := b[i : i+pkt]
		pid := int(p[1]&0x1f)<<8 | int(p[2])
		if p[3]&0x10 != 0 {
			slateCount[pid]++
		}
		if p[1]&0x40 == 0 {
			continue
		}
		switch {
		case pid == 0:
			pmtPID = patPMT(p)
		case pid == pmtPID && vpid == 0:
			vpid, _ = pmtVideo(p)
		case pid == vpid:
			if t := pesPTS(p); t >= 0 {
				if lo < 0 || t < lo {
					lo = t
				}
				if t > hi {
					hi = t
				}
				frames++
			}
		}
	}
	if frames < 2 {
		log.Fatalf("slate %s: no video timestamps", slatePath)
	}
	slate90k = (hi - lo) + (hi-lo)/int64(frames-1)
	slateDur = float64(slate90k) / 90000
}

// slateLoop returns loop k of the slate with PTS/DTS/PCR and continuity counters shifted to follow loop k-1.
func slateLoop(k int64) []byte {
	b := append([]byte{}, slate...)
	off := k * slate90k
	for i := 0; i+pkt <= len(b); i += pkt {
		p := b[i : i+pkt]
		pid := int(p[1]&0x1f)<<8 | int(p[2])
		if p[3]&0x10 != 0 {
			p[3] = p[3]&0xf0 | byte((int64(p[3]&0x0f)+k*int64(slateCount[pid]))&15)
		}
		if p[3]&0x20 != 0 && p[4] >= 7 && p[5]&0x10 != 0 { // PCR
			base := int64(p[6])<<25 | int64(p[7])<<17 | int64(p[8])<<9 | int64(p[9])<<1 | int64(p[10])>>7
			base = (base + off) & (1<<33 - 1)
			p[6], p[7], p[8], p[9] = byte(base>>25), byte(base>>17), byte(base>>9), byte(base>>1)
			p[10] = p[10]&0x7f | byte(base&1)<<7
		}
		if p[1]&0x40 == 0 {
			continue
		}
		o := payOff(p)
		if o < 0 || o+19 > pkt {
			continue
		}
		q := p[o:]
		if q[0] != 0 || q[1] != 0 || q[2] != 1 || q[3] < 0xbc {
			continue
		}
		if q[7]&0x80 != 0 {
			shiftTS(q[9:14], off)
		}
		if q[7]&0x40 != 0 {
			shiftTS(q[14:19], off)
		}
	}
	return b
}

func shiftTS(t []byte, off int64) {
	v := int64(t[0]&0x0e)<<29 | int64(t[1])<<22 | int64(t[2]&0xfe)<<14 | int64(t[3])<<7 | int64(t[4])>>1
	v = (v + off) & (1<<33 - 1)
	t[0] = t[0]&0xf1 | byte(v>>29)&0x0e
	t[1] = byte(v >> 22)
	t[2] = byte(v>>14) | 1
	t[3] = byte(v >> 7)
	t[4] = byte(v<<1) | 1
}

// slater feeds the offline video while the source gives nothing (cold start or stall), until the hub closes.
func (h *Hub) slater() {
	t := time.NewTicker(500 * time.Millisecond)
	defer t.Stop()
	for range t.C {
		h.mu.Lock()
		if h.closed {
			h.mu.Unlock()
			return
		}
		wait := stallWait
		if !h.started {
			wait = coldWait
		}
		now := time.Now()
		if now.Sub(h.lastReal) >= wait && now.Sub(h.lastSlate) >= time.Duration(slateDur*float64(time.Second)) {
			if !h.offline {
				log.Printf("hub %s offline video on", h.id)
			}
			h.offline, h.lastSlate = true, now
			h.addSlate(slateLoop(h.slateN))
			h.slateN++
			h.cond.Broadcast()
		}
		h.mu.Unlock()
	}
}
