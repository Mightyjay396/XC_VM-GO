package main

import (
	"fmt"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/beevik/etree"
)

// Seg = [t_abs, d, n, url_index, t_orig]
type Seg [5]int64

type Rep struct {
	Media string      `json:"media"`
	Init  string      `json:"init"`
	Base  string      `json:"base"`
	BW    string      `json:"bw"`
	TS    int64       `json:"ts"`
	Segs  []Seg       `json:"segs"`
	URLs  [][2]string `json:"urls"`
	Hits  int64       `json:"hits"`
	Off   int64       `json:"off"`
}

var (
	tplRe    = regexp.MustCompile(`\$(RepresentationID|Bandwidth|Number|Time)(%0\d+d)?\$`)
	durRe    = regexp.MustCompile(`^PT(?:(\d+)H)?(?:(\d+)M)?(?:([\d.]+)S)?`)
	initTRe  = regexp.MustCompile(`_t\d{8,}_`)
	errNoMPD = fmt.Errorf("no MPD root")
)

func tplSub(tpl string, vals map[string]string) string {
	out := tplRe.ReplaceAllStringFunc(tpl, func(m string) string {
		sm := tplRe.FindStringSubmatch(m)
		v, ok := vals[sm[1]]
		if !ok {
			return m
		}
		if sm[2] != "" {
			if iv, err := strconv.ParseInt(v, 10, 64); err == nil {
				return fmt.Sprintf(sm[2], iv)
			}
		}
		return v
	})
	return strings.ReplaceAll(out, "$$", "$")
}

func joinURL(parts ...string) string {
	out := ""
	for _, p := range parts {
		p = strings.TrimSpace(p)
		if p == "" {
			continue
		}
		if out == "" {
			out = p
			continue
		}
		b, err1 := url.Parse(out)
		r, err2 := url.Parse(p)
		if err1 != nil || err2 != nil {
			out = p
			continue
		}
		out = b.ResolveReference(r).String()
	}
	return out
}

func parseDur(s string) float64 {
	m := durRe.FindStringSubmatch(s)
	if m == nil {
		return 0
	}
	h, _ := strconv.Atoi(m[1])
	mi, _ := strconv.Atoi(m[2])
	sec, _ := strconv.ParseFloat(m[3], 64)
	return float64(h*3600+mi*60) + sec
}

func parseISO(s string) float64 {
	for _, f := range []string{time.RFC3339Nano, "2006-01-02T15:04:05.999999999", "2006-01-02T15:04:05"} {
		if t, err := time.Parse(f, s); err == nil {
			return float64(t.UnixNano()) / 1e9
		}
	}
	return 0
}

func attrI(e *etree.Element, k string, def int64) int64 {
	if a := e.SelectAttr(k); a != nil {
		if v, err := strconv.ParseInt(strings.TrimSpace(a.Value), 10, 64); err == nil {
			return v
		}
	}
	return def
}

func childText(e *etree.Element, tag string) string {
	if c := e.SelectElement(tag); c != nil {
		return c.Text()
	}
	return ""
}

type tdn struct{ t, d, n int64 }

func expandTimeline(tpl *etree.Element, sn int64) []tdn {
	tl := tpl.SelectElement("SegmentTimeline")
	if tl == nil {
		return nil
	}
	var out []tdn
	t, n := int64(0), sn
	for _, S := range tl.SelectElements("S") {
		if S.SelectAttr("t") != nil {
			t = attrI(S, "t", 0)
		}
		d := attrI(S, "d", 0)
		r := attrI(S, "r", 0)
		if r < 0 {
			r = 0
		}
		for i := int64(0); i <= r; i++ {
			out = append(out, tdn{t, d, n})
			t += d
			n++
		}
	}
	return out
}

func numberTimeline(tpl, root, period *etree.Element, delay int) []tdn {
	dur := attrI(tpl, "duration", 0)
	if dur == 0 {
		return nil
	}
	ts := attrI(tpl, "timescale", 1)
	sn := attrI(tpl, "startNumber", 1)
	pto := attrI(tpl, "presentationTimeOffset", 0)
	t0 := 0.0
	if ast := root.SelectAttrValue("availabilityStartTime", ""); ast != "" {
		t0 = parseISO(ast)
	}
	pstart := parseDur(period.SelectAttrValue("start", "PT0S"))
	now := float64(time.Now().UnixNano()) / 1e9
	live := sn + int64((now-t0-pstart)*float64(ts)/float64(dur)) - 1
	first := live - int64(3*float64(delay)*float64(ts)/float64(dur))
	if first < sn {
		first = sn
	}
	var out []tdn
	for n := first; n <= live; n++ {
		out = append(out, tdn{pto + (n-sn)*dur, dur, n})
	}
	return out
}

func urlIndex(e *Rep, base, media string) int64 {
	for i, u := range e.URLs {
		if u[0] == base && u[1] == media {
			return int64(i)
		}
	}
	e.URLs = append(e.URLs, [2]string{base, media})
	return int64(len(e.URLs) - 1)
}

// parseMPD merges all Periods; per representation keeps the dominant (init, timescale) group (drops SSAI ad periods).
func groupKey(init string, ts int64) string {
	return initTRe.ReplaceAllString(init, "_t*_") + "|" + strconv.FormatInt(ts, 10)
}

func parseMPD(xml []byte, finalURL string, delay int) (*etree.Document, map[string]*Rep, map[string]map[string]*Rep, error) {
	doc := etree.NewDocument()
	if err := doc.ReadFromBytes(xml); err != nil {
		return nil, nil, nil, err
	}
	root := doc.Root()
	if root == nil || root.Tag != "MPD" {
		return nil, nil, nil, errNoMPD
	}
	mpdBase := joinURL(finalURL, childText(root, "BaseURL"))
	type grp struct {
		key string
		rep *Rep
	}
	groups := map[string][]*grp{}
	var order []string
	for _, period := range root.SelectElements("Period") {
		pbase := joinURL(mpdBase, childText(period, "BaseURL"))
		pstart := parseDur(period.SelectAttrValue("start", "PT0S"))
		for _, aset := range period.SelectElements("AdaptationSet") {
			abase := joinURL(pbase, childText(aset, "BaseURL"))
			atpl := aset.SelectElement("SegmentTemplate")
			for _, rep := range aset.SelectElements("Representation") {
				tpl := rep.SelectElement("SegmentTemplate")
				if tpl == nil {
					tpl = atpl
				}
				if tpl == nil || tpl.SelectAttrValue("media", "") == "" {
					continue
				}
				sn := attrI(tpl, "startNumber", 1)
				ts := attrI(tpl, "timescale", 1)
				segs := expandTimeline(tpl, sn)
				if len(segs) == 0 {
					segs = numberTimeline(tpl, root, period, delay)
				}
				base := joinURL(abase, childText(rep, "BaseURL"))
				media := tpl.SelectAttrValue("media", "")
				init := tpl.SelectAttrValue("initialization", "")
				gkey := groupKey(init, ts)
				rid := rep.SelectAttrValue("id", "")
				if _, ok := groups[rid]; !ok {
					order = append(order, rid)
				}
				var g *grp
				for _, x := range groups[rid] {
					if x.key == gkey {
						g = x
					}
				}
				if g == nil {
					g = &grp{key: gkey, rep: &Rep{Media: media, Init: init, Base: base,
						BW: rep.SelectAttrValue("bandwidth", ""), TS: ts}}
					groups[rid] = append(groups[rid], g)
				}
				p0 := int64(pstart*float64(ts)+0.5) - attrI(tpl, "presentationTimeOffset", 0)
				lastT := int64(-1)
				if len(g.rep.Segs) > 0 {
					lastT = g.rep.Segs[len(g.rep.Segs)-1][0]
				}
				bi := urlIndex(g.rep, base, media)
				for _, s := range segs {
					if s.t+p0 > lastT {
						g.rep.Segs = append(g.rep.Segs, Seg{s.t + p0, s.d, s.n, bi, s.t})
					}
				}
			}
		}
	}
	liveEdge := 0.0
	if ast := root.SelectAttrValue("availabilityStartTime", ""); ast != "" {
		if t0 := parseISO(ast); t0 > 0 {
			liveEdge = float64(time.Now().UnixNano())/1e9 - t0
		}
	}
	reps := map[string]*Rep{}
	all := map[string]map[string]*Rep{}
	for _, rid := range order {
		all[rid] = map[string]*Rep{}
		for _, g := range groups[rid] {
			if g.rep.URLs == nil {
				g.rep.URLs = [][2]string{}
			}
			all[rid][g.key] = g.rep
		}
		// dominant group (content); during a long slate/ad (content > 60 s behind the live clock) follow the group
		// that is at the live edge instead, so a new session plays the slate rather than failing.
		var best *Rep
		for _, g := range groups[rid] {
			if best == nil || len(g.rep.Segs) > len(best.Segs) {
				best = g.rep
			}
		}
		if liveEdge > 0 && groupEnd(best) < liveEdge-60 {
			for _, g := range groups[rid] {
				if end := groupEnd(g.rep); end >= liveEdge-60 && end <= liveEdge+30 {
					best = g.rep
				}
			}
		}
		if best.URLs == nil {
			best.URLs = [][2]string{}
		}
		reps[rid] = best
	}
	return doc, reps, all, nil
}

func trimRep(e *Rep, delay int) {
	segs := e.Segs
	last := segs[len(segs)-1]
	cutoff := last[0] + last[1] - int64(delay)*e.TS
	drop := 0
	for drop < len(segs)-1 && segs[drop][0]+segs[drop][1] <= cutoff {
		drop++
	}
	e.Segs = append([]Seg(nil), segs[drop:]...)
	e.Off = 0
}

func newChild(parent *etree.Element, tag string) *etree.Element {
	if parent.Space != "" {
		return parent.CreateElement(parent.Space + ":" + tag)
	}
	return parent.CreateElement(tag)
}

// staticManifest: first Period only, static, templates -> /segn|/segi, startNumber=1, trimmed timeline + 48 h synthetic.
func staticManifest(doc *etree.Document, reps map[string]*Rep, sid string, delay int) ([]byte, error) {
	root := doc.Root()
	periods := root.SelectElements("Period")
	if len(periods) == 0 {
		return nil, fmt.Errorf("no Period")
	}
	for _, p := range periods[1:] {
		root.RemoveChild(p)
	}
	for _, a := range []string{"minimumUpdatePeriod", "timeShiftBufferDepth", "suggestedPresentationDelay", "publishTime"} {
		root.RemoveAttr(a)
	}
	root.CreateAttr("type", "static")
	root.CreateAttr("mediaPresentationDuration", "PT48H")
	period := periods[0]
	period.RemoveAttr("duration")
	for _, ev := range period.SelectElements("EventStream") {
		period.RemoveChild(ev)
	}
	for _, el := range root.FindElements("//BaseURL") {
		el.SetText("")
	}
	done := map[*etree.Element]bool{}
	for _, aset := range period.SelectElements("AdaptationSet") {
		atpl := aset.SelectElement("SegmentTemplate")
		for _, rep := range aset.SelectElements("Representation") {
			tpl := rep.SelectElement("SegmentTemplate")
			if tpl == nil {
				tpl = atpl
			}
			e := reps[rep.SelectAttrValue("id", "")]
			if tpl == nil || e == nil || len(e.Segs) == 0 {
				continue
			}
			trimRep(e, delay)
			if done[tpl] {
				continue
			}
			tpl.CreateAttr("media", fmt.Sprintf("http://127.0.0.1:%d/segn/%s/$RepresentationID$/$Number$", port, sid))
			if tpl.SelectAttr("initialization") != nil {
				tpl.CreateAttr("initialization", fmt.Sprintf("http://127.0.0.1:%d/segi/%s/$RepresentationID$", port, sid))
			}
			tpl.CreateAttr("startNumber", "1")
			tpl.RemoveAttr("duration")
			tl := tpl.SelectElement("SegmentTimeline")
			if tl == nil {
				tl = newChild(tpl, "SegmentTimeline")
			}
			for _, c := range tl.ChildElements() {
				tl.RemoveChild(c)
			}
			counts := map[int64]int{}
			var nominal int64
			for _, s := range e.Segs {
				counts[s[1]]++
				if counts[s[1]] > counts[nominal] {
					nominal = s[1]
				}
			}
			for _, s := range e.Segs {
				S := newChild(tl, "S")
				S.CreateAttr("t", strconv.FormatInt(s[0], 10))
				S.CreateAttr("d", strconv.FormatInt(s[1], 10))
			}
			last := e.Segs[len(e.Segs)-1]
			S := newChild(tl, "S")
			S.CreateAttr("t", strconv.FormatInt(last[0]+last[1], 10))
			S.CreateAttr("d", strconv.FormatInt(nominal, 10))
			S.CreateAttr("r", strconv.FormatInt(48*3600*e.TS/nominal, 10))
			done[tpl] = true
		}
	}
	body, err := doc.WriteToBytes()
	if err != nil || len(doc.Child) == 0 {
		return body, err
	}
	if _, ok := doc.Child[0].(*etree.ProcInst); !ok {
		body = append([]byte("<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"), body...)
	}
	return body, nil
}

func segURL(e *Rep, rid string, s Seg) string {
	base, media := e.Base, e.Media
	if int(s[3]) < len(e.URLs) {
		base, media = e.URLs[s[3]][0], e.URLs[s[3]][1]
	}
	return joinURL(base, tplSub(media, map[string]string{
		"RepresentationID": rid, "Bandwidth": e.BW,
		"Number": strconv.FormatInt(s[2], 10), "Time": strconv.FormatInt(s[4], 10)}))
}

func groupEnd(e *Rep) float64 {
	if len(e.Segs) == 0 || e.TS == 0 {
		return -1
	}
	l := e.Segs[len(e.Segs)-1]
	return float64(l[0]+l[1]) / float64(e.TS)
}
