package main

import "testing"

func TestLiveCfg(t *testing.T) {
	o, why := parseFF([]string{"-rw_timeout", "10000000", "-analyzeduration", "5000000", "-i", "http://x/a.m3u8", "-vcodec", "copy", "-acodec", "copy", "-f", "hls", "-hls_time", "6", "-hls_list_size", "8", "-hls_delete_threshold", "3", "-hls_segment_type", "mpegts", "-hls_segment_filename", "/s/32_%d.ts", "/s/32_.m3u8"})
	if why != "" || o.rw.Seconds() != 10 || o.analyze.Seconds() != 5 {
		t.Fatal(why, o.rw, o.analyze)
	}
	c := o.cfg(map[string]string{"seg_time": "4", "seg_list_size": "7", "seg_delete_threshold": "2", "segment_wait_time": "25", "stream_fail_sleep": "10", "probe_extra_wait": "10"})
	if c.seg != 4 || c.list != 7 || c.del != 2 || c.wait.Seconds() != 25 || c.fail.Seconds() != 10 || c.probe.Seconds() != 19 {
		t.Fatalf("%+v", c)
	}
	if d := o.cfg(nil); d.seg != 6 || d.list != 8 || d.del != 3 {
		t.Fatalf("%+v", d)
	}
}
