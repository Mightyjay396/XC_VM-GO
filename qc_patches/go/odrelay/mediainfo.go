package main

import (
	"bytes"

	"github.com/bluenviron/mediacommon/v2/pkg/codecs/h264"
	"github.com/bluenviron/mediacommon/v2/pkg/codecs/h265"
)

var streamTypes = map[byte][2]string{
	0x01: {"v", "mpeg1video"}, 0x02: {"v", "mpeg2video"}, 0x1b: {"v", "h264"}, 0x24: {"v", "hevc"},
	0x03: {"a", "mp2"}, 0x04: {"a", "mp2"}, 0x0f: {"a", "aac"}, 0x11: {"a", "aac_latm"}, 0x81: {"a", "ac3"}, 0x87: {"a", "eac3"},
}

// pmtCodecs: codec names of the PMT's elementary streams (first video, first audio, like ffprobe's main ones).
func pmtCodecs(pmt []byte) (video, audio string) {
	if len(pmt) < pkt || pmt[0] != 0x47 {
		return
	}
	o := 4
	if pmt[3]&0x20 != 0 {
		o += 1 + int(pmt[4])
	}
	if o >= pkt {
		return
	}
	o += 1 + int(pmt[o]) // pointer field
	if o+12 > pkt || pmt[o] != 0x02 {
		return
	}
	end := min(o+3+(int(pmt[o+1]&0x0f)<<8|int(pmt[o+2]))-4, pkt)
	i := o + 12 + (int(pmt[o+10]&0x0f)<<8 | int(pmt[o+11]))
	for i+5 <= end {
		t, il := pmt[i], int(pmt[i+3]&0x0f)<<8|int(pmt[i+4])
		k, n := streamTypes[t][0], streamTypes[t][1]
		if t == 0x06 { // private data: AC-3 / E-AC-3 descriptors
			for d := i + 5; d+2 <= min(i+5+il, end); d += 2 + int(pmt[d+1]) {
				switch pmt[d] {
				case 0x6a:
					k, n = "a", "ac3"
				case 0x7a:
					k, n = "a", "eac3"
				}
			}
		}
		if k == "v" && video == "" {
			video = n
		} else if k == "a" && audio == "" {
			audio = n
		}
		i += 5 + il
	}
	return
}

// pesPayload joins the payloads of one PID's packets (enough to reach a keyframe's SPS).
func pesPayload(d []byte, pid, limit int) []byte {
	var out []byte
	for i := 0; i+pkt <= len(d) && len(out) < limit; i += pkt {
		p := d[i : i+pkt]
		if p[0] != 0x47 || (int(p[1]&0x1f)<<8|int(p[2])) != pid {
			continue
		}
		o := 4
		if p[3]&0x20 != 0 {
			o += 1 + int(p[4])
		}
		if o < pkt {
			out = append(out, p[o:]...)
		}
	}
	return out
}

// spsSize: picture size from the first SPS found (H.264 or HEVC).
func spsSize(es []byte, hevc bool) (int, int) {
	for _, nal := range bytes.Split(es, []byte{0, 0, 1}) {
		if len(nal) < 4 {
			continue
		}
		nal = bytes.TrimRight(nal, "\x00")
		if !hevc && nal[0]&0x1f == 7 {
			var s h264.SPS
			if s.Unmarshal(nal) == nil {
				return s.Width(), s.Height()
			}
		}
		if hevc && (nal[0]>>1)&0x3f == 33 {
			var s h265.SPS
			if s.Unmarshal(nal) == nil {
				return s.Width(), s.Height()
			}
		}
	}
	return 0, 0
}

// mediaInfo (h.mu held): what the panel shows for a running stream, measured on the ring.
func (h *Hub) mediaInfo() map[string]any {
	v, a := pmtCodecs(h.pmt)
	m := map[string]any{"video": v, "audio": a}
	if len(h.ring) < 2 {
		return m
	}
	// rates over stream time (keyframe PTS), not arrival time: providers deliver in bursts
	k2 := -1
	for i := len(h.ring) - 1; i >= 0; i-- {
		if h.ring[i].key && h.ring[i].pts >= 0 {
			k2 = i
			break
		}
	}
	if k2 < 0 {
		return m
	}
	k1, span := -1, 0.0
	for i := k2 - 1; i >= 0; i-- {
		c := h.ring[i]
		if !c.key || c.pts < 0 {
			continue
		}
		d := float64((h.ring[k2].pts-c.pts)&(1<<33-1)) / 90000
		if d > 60 {
			break
		}
		k1, span = i, d
		if d >= 10 {
			break
		}
	}
	for i := len(h.ring) - 1; i >= 0 && m["width"] == nil; i-- {
		if c := h.ring[i]; v != "" && c.key {
			if w, ht := spsSize(pesPayload(c.data, h.vPID, 4096), v == "hevc"); w > 0 {
				m["width"], m["height"] = w, ht
			}
		}
	}
	if k1 < 0 || span < 2 {
		return m
	}
	fr, size := 0, 0
	for i := k1; i < k2; i++ {
		fr += frames(h.ring[i].data, h.vPID)
		size += len(h.ring[i].data)
	}
	m["kbps"] = int(float64(size) * 8 / span / 1000)
	if v != "" {
		m["fps"] = float64(int(float64(fr)/span*100)) / 100
	}
	return m
}
