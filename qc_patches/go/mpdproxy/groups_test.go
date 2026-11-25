package main

import (
	"strings"
	"testing"
	"time"
)

// content period (cenc init, ts 30000) + longer ad period (other init, ts 90000): session must stay on content.
const ssai = `<?xml version="1.0"?><MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="dynamic">
<Period id="c1" start="PT100S"><AdaptationSet><SegmentTemplate timescale="30000" presentationTimeOffset="0" media="c_$Number$.mp4" initialization="cenc_init.mp4" startNumber="10"><SegmentTimeline><S t="0" d="60000" r="1"/></SegmentTimeline></SegmentTemplate><Representation id="7" bandwidth="1"/></AdaptationSet></Period>
<Period id="ad" start="PT104S"><AdaptationSet><SegmentTemplate timescale="90000" media="ad_$Number$.mp4" initialization="ad_init.mp4" startNumber="0"><SegmentTimeline><S t="0" d="180000" r="5"/></SegmentTimeline></SegmentTemplate><Representation id="7" bandwidth="1"/></AdaptationSet></Period>
</MPD>`

func TestStickyGroup(t *testing.T) {
	_, dom, all, err := parseMPD([]byte(ssai), "http://x/m.mpd", 60)
	if err != nil {
		t.Fatal(err)
	}
	if dom["7"].Init != "ad_init.mp4" {
		t.Fatalf("expected ad group dominant in this window, got %s", dom["7"].Init)
	}
	c := all["7"][groupKey("cenc_init.mp4", 30000)]
	if c == nil || len(c.Segs) != 2 {
		t.Fatalf("content group missing: %+v", c)
	}
}

// content stopped long before the ad/slate group: a new session must follow the slate
const slate = `<?xml version="1.0"?><MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="dynamic" availabilityStartTime="AST">
<Period id="c1" start="PT0S"><AdaptationSet><SegmentTemplate timescale="30000" media="c_$Number$.mp4" initialization="cenc_init.mp4"><SegmentTimeline><S t="0" d="60000" r="9"/></SegmentTimeline></SegmentTemplate><Representation id="7" bandwidth="1"/></AdaptationSet></Period>
<Period id="ad" start="PT300S"><AdaptationSet><SegmentTemplate timescale="90000" media="ad_$Number$.mp4" initialization="ad_init.mp4"><SegmentTimeline><S t="0" d="180000" r="2"/></SegmentTimeline></SegmentTemplate><Representation id="7" bandwidth="1"/></AdaptationSet></Period>
</MPD>`

func TestSlateFollowsLive(t *testing.T) {
	ast := time.Now().Add(-306 * time.Second).UTC().Format(time.RFC3339)
	_, dom, _, _ := parseMPD([]byte(strings.Replace(slate, "AST", ast, 1)), "http://x/m.mpd", 60)
	if dom["7"].Init != "ad_init.mp4" {
		t.Fatalf("expected live slate group, got %s", dom["7"].Init)
	}
}
