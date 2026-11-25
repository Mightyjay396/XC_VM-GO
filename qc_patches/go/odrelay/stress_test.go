package main

import (
	"context"
	"encoding/base64"
	"errors"
	"fmt"
	"io"
	"math/rand"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

// Stress: real-time fake provider + odrelay handlers under the race detector.
// Counts clients cut by odrelay while their source was fine ("premature").

var srcTS []byte

// paced writes the sample at real time from off, until ctx ends or limit elapses.
func paced(w http.ResponseWriter, r *http.Request, off int, limit time.Duration) {
	w.Header().Set("Content-Type", "video/mp2t")
	fl := w.(http.Flusher)
	bps := len(srcTS) / 600 // sample is 600 s
	step := (bps / 50 / pkt) * pkt
	t0 := time.Now()
	for off+step <= len(srcTS) {
		if limit > 0 && time.Since(t0) > limit {
			return
		}
		if _, err := w.Write(srcTS[off : off+step]); err != nil {
			return
		}
		fl.Flush()
		off += step
		select {
		case <-r.Context().Done():
			return
		case <-time.After(20 * time.Millisecond):
		}
	}
}

var dropOff atomic.Int64

func fakeProvider() *httptest.Server {
	mux := http.NewServeMux()
	mux.HandleFunc("/ok", func(w http.ResponseWriter, r *http.Request) { paced(w, r, rand.Intn(100)*pkt*700, 0) })
	mux.HandleFunc("/drop", func(w http.ResponseWriter, r *http.Request) { // ends every 7 s, resumes 3 s back (provider replay)
		off := int(dropOff.Load())
		back := (len(srcTS) / 600 * 3 / pkt) * pkt
		if off > back {
			off -= back
		}
		paced(w, r, off, 7*time.Second)
		dropOff.Store(int64(off + len(srcTS)/600*7/pkt*pkt))
	})
	mux.HandleFunc("/html", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/html")
		w.Write([]byte(strings.Repeat("<html>max connections reached</html>", 300)))
	})
	mux.HandleFunc("/404", func(w http.ResponseWriter, r *http.Request) { http.NotFound(w, r) })
	mux.HandleFunc("/stall", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.(http.Flusher).Flush()
		<-r.Context().Done()
	})
	return httptest.NewServer(mux)
}

func q(srcs ...string) string {
	s := "?w=8&pb=3&seg=2&list=5&scan=3&stall=5&del=3&fsleep=2&fexit=1&idle=5"
	for _, u := range srcs {
		s += "&u=" + base64.RawURLEncoding.EncodeToString([]byte(u))
	}
	return s
}

type tally struct{ ok, premature, refused, noData atomic.Int64 }

// tsViewer stays d; premature = odrelay ended the stream before the client left.
func tsViewer(base, id, qs string, d time.Duration, t *tally, wantFail bool) {
	ctx, cancel := context.WithTimeout(context.Background(), d)
	defer cancel()
	req, _ := http.NewRequestWithContext(ctx, "GET", base+"/ts/"+id+qs+fmt.Sprintf("&c=%d", rand.Int63()), nil)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		if ctx.Err() == nil {
			t.premature.Add(1)
		}
		return
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		if !wantFail {
			t.refused.Add(1)
		}
		return
	}
	buf := make([]byte, 64*1024)
	var real int64
	for {
		n, err := resp.Body.Read(buf)
		for i := 0; i+pkt <= n; i += pkt {
			if buf[i+1]&0x1f != 0x1f || buf[i+2] != 0xff { // not a null packet
				real++
			}
		}
		if err != nil {
			if ctx.Err() == nil && !errors.Is(err, context.DeadlineExceeded) {
				t.premature.Add(1)
			} else if real == 0 {
				t.noData.Add(1)
			} else {
				t.ok.Add(1)
			}
			return
		}
	}
}

func hlsPoller(base, id, qs string, d time.Duration, errs *atomic.Int64, polls *atomic.Int64) {
	end := time.Now().Add(d)
	for time.Now().Before(end) {
		resp, err := http.Get(base + "/hls/" + id + "/index.m3u8" + qs)
		if err != nil {
			errs.Add(1)
			return
		}
		pl, _ := io.ReadAll(resp.Body)
		resp.Body.Close()
		polls.Add(1)
		if resp.StatusCode != 200 {
			errs.Add(1)
		} else {
			lines := strings.Split(strings.TrimSpace(string(pl)), "\n")
			last := lines[len(lines)-1]
			if r2, err := http.Get(base + "/hls/" + id + "/" + last + qs); err != nil || r2.StatusCode != 200 {
				errs.Add(1)
			} else {
				io.Copy(io.Discard, r2.Body)
				r2.Body.Close()
			}
		}
		time.Sleep(2 * time.Second)
	}
}

func TestStress(t *testing.T) {
	var err error
	if srcTS, err = os.ReadFile("/tmp/stress_src.ts"); err != nil {
		t.Skip("no /tmp/stress_src.ts")
	}
	prov := fakeProvider()
	defer prov.Close()
	mux := http.NewServeMux()
	mux.HandleFunc("/ts/", serveTS)
	mux.HandleFunc("/hls/", serveHLS)
	od := httptest.NewServer(mux)
	defer od.Close()
	P := prov.URL

	scen := []struct {
		name, id string
		srcs     []string
		n        int
		stay     [2]int
		fail     bool
	}{
		{"A normal, 150 clients join/leave", "101", []string{P + "/ok"}, 150, [2]int{2, 15}, false},
		{"B source drops every 7s (replay)", "102", []string{P + "/drop"}, 20, [2]int{25, 35}, false},
		{"C 200-html then good backup", "103", []string{P + "/html", P + "/ok"}, 20, [2]int{5, 15}, false},
		{"D dead source (404) failure_exit", "104", []string{P + "/404"}, 5, [2]int{3, 6}, true},
		{"E stalled then good backup", "105", []string{P + "/stall", P + "/ok"}, 10, [2]int{8, 15}, false},
		{"G churn 300 x <1s around idle", "107", []string{P + "/ok"}, 300, [2]int{0, 1}, false},
	}
	var wg sync.WaitGroup
	res := make([]*tally, len(scen))
	for i, s := range scen {
		res[i] = &tally{}
		for k := 0; k < s.n; k++ {
			wg.Add(1)
			go func(s struct {
				name, id string
				srcs     []string
				n        int
				stay     [2]int
				fail     bool
			}, t *tally) {
				defer wg.Done()
				time.Sleep(time.Duration(rand.Intn(40000)) * time.Millisecond)
				stay := time.Duration(s.stay[0]*1000+rand.Intn((s.stay[1]-s.stay[0])*1000+300)) * time.Millisecond
				tsViewer(od.URL, s.id, q(s.srcs...), stay, t, s.fail)
			}(s, res[i])
		}
	}
	var hErr, hPolls atomic.Int64
	for _, id := range []string{"101", "102", "106"} {
		for k := 0; k < 5; k++ {
			wg.Add(1)
			go func(id string) {
				defer wg.Done()
				src := P + "/ok"
				if id == "102" {
					src = P + "/drop"
				}
				hlsPoller(od.URL, id, q(src), 40*time.Second, &hErr, &hPolls)
			}(id)
		}
	}
	wg.Wait()
	bad := false
	for i, s := range scen {
		r := res[i]
		t.Logf("%-38s ok=%d premature=%d refused=%d no_data=%d", s.name, r.ok.Load(), r.premature.Load(), r.refused.Load(), r.noData.Load())
		if !s.fail && (r.premature.Load() > 0 || r.refused.Load() > 0) {
			bad = true
		}
	}
	t.Logf("F HLS pollers (3 channels x5)          polls=%d errors=%d", hPolls.Load(), hErr.Load())
	if bad || hErr.Load() > 0 {
		t.Fail()
	}
}
