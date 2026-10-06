package main

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync/atomic"
	"time"
)

// TSHandler serves MPEG-TS segments to clients, replacing the PHP chase-read
// loop entirely. It integrates with XC_VM's lines_live tracking via Tracker.
type TSHandler struct {
	cache          *SegmentCache
	watcher        *StreamWatcher
	tracker        *Tracker
	streamsPath    string
	signalsPath    string // /home/xc_vm/signals/ — admin drop/signal files
	consTmpPath    string // /home/xc_vm/tmp/opened_cons/ — connection touch files
	divergencePath string // /home/xc_vm/tmp/divergence/ — speed tracking
	readBufSize    int
	prebufferMax   int
	segTime        int
	segWaitTime    int // max seconds to wait for next segment
	viewers        atomic.Int64
}

func NewTSHandler(cache *SegmentCache, watcher *StreamWatcher, tracker *Tracker,
	streamsPath string, readBufSize, prebufferMax, segTime, segWaitTime int,
	signalsPath, consTmpPath, divergencePath string) *TSHandler {
	return &TSHandler{
		cache:          cache,
		watcher:        watcher,
		tracker:        tracker,
		streamsPath:    streamsPath,
		signalsPath:    signalsPath,
		consTmpPath:    consTmpPath,
		divergencePath: divergencePath,
		readBufSize:    readBufSize,
		prebufferMax:   prebufferMax,
		segTime:        segTime,
		segWaitTime:    segWaitTime,
	}
}

func (h *TSHandler) ActiveViewers() int64 {
	return h.viewers.Load()
}

var hlsPathRe = regexp.MustCompile(`^/hls_playlist/(\d+)$`)

// ServeHLSPlaylist handles: GET /hls_playlist/<stream_id>?uuid=<uuid>
// Internal-only route called via X-Accel-Redirect from PHP when the Go
// server is running. PHP has already created the connection in lines_live
// and done enforcement — Go just serves the tokenized m3u8 playlist.
func (h *TSHandler) ServeHLSPlaylist(w http.ResponseWriter, r *http.Request) {
	matches := hlsPathRe.FindStringSubmatch(r.URL.Path)
	if matches == nil {
		http.NotFound(w, r)
		return
	}

	streamID, _ := strconv.Atoi(matches[1])
	uuid := r.URL.Query().Get("uuid")
	if uuid == "" {
		http.Error(w, "missing uuid", http.StatusBadRequest)
		return
	}

	playlist, err := generateHLSPlaylist(h.streamsPath, streamID, uuid)
	if err != nil {
		log.Printf("hls-playlist: stream=%d uuid=%s error: %v", streamID, uuid, err)
		http.NotFound(w, r)
		return
	}

	log.Printf("hls-playlist: serving stream=%d uuid=%s (from php handoff)", streamID, uuid)

	w.Header().Set("Content-Type", "application/x-mpegurl")
	w.Header().Set("Cache-Control", "no-store, no-cache, must-revalidate")
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Content-Length", strconv.Itoa(len(playlist)))
	w.Write([]byte(playlist))
}

var pathRe = regexp.MustCompile(`^/ts/(\d+)$`)

// ServeLive handles: GET /ts/<stream_id>?prebuffer=<sec>&uuid=<uuid>
// Internal-only route called via X-Accel-Redirect from PHP (legacy path).
func (h *TSHandler) ServeLive(w http.ResponseWriter, r *http.Request) {
	matches := pathRe.FindStringSubmatch(r.URL.Path)
	if matches == nil {
		http.NotFound(w, r)
		return
	}

	streamID, _ := strconv.Atoi(matches[1])
	prebufferSec, _ := strconv.Atoi(r.URL.Query().Get("prebuffer"))
	uuid := r.URL.Query().Get("uuid")

	if prebufferSec > h.prebufferMax {
		prebufferSec = h.prebufferMax
	}
	if prebufferSec < 0 {
		prebufferSec = 0
	}

	log.Printf("viewer connect (legacy): stream=%d uuid=%s prebuffer=%ds", streamID, uuid, prebufferSec)

	// Register in tracker (heartbeat to MariaDB keeps connection alive)
	var stopCh <-chan struct{}
	if h.tracker != nil && uuid != "" {
		stopCh = h.tracker.Register(uuid, streamID)
		defer func() {
			h.tracker.Unregister(uuid)
			log.Printf("viewer disconnect (legacy): stream=%d uuid=%s", streamID, uuid)
		}()
	}

	h.DeliverTS(w, r, streamID, prebufferSec, uuid, stopCh)
}

// DeliverTS is the core MPEG-TS delivery loop. It handles:
//   - MPEG-TS headers
//   - Prebuffer (send recent cached segments for instant playback)
//   - Chase-read loop (serve segments as ffmpeg writes them)
//   - Admin drop signal checking (SIGNALS_PATH)
//   - External disconnect detection via stopCh
//   - Touch file management (CONS_TMP_PATH)
//   - Connection speed/divergence tracking
//
// Called by both ServeLive (internal/legacy) and ServeAuth (native Go auth).
// The caller is responsible for tracker registration/unregistration.
// NEVER drops a viewer because of DB state in the delivery loop —
// external disconnect is detected via heartbeat -> stopCh.
func (h *TSHandler) DeliverTS(w http.ResponseWriter, r *http.Request, streamID, prebufferSec int, uuid string, stopCh <-chan struct{}) {
	h.viewers.Add(1)
	defer h.viewers.Add(-1)

	// Read per-stream segment duration (_.dur) if it exists.
	// PHP: if (file_exists(STREAMS_PATH.$streamID."_.dur")) { $segTime = max($segTime, intval(file_get_contents(...))); }
	segTime := h.segTime
	durPath := filepath.Join(h.streamsPath, fmt.Sprintf("%d_.dur", streamID))
	if durData, err := os.ReadFile(durPath); err == nil {
		if dur, err := strconv.Atoi(strings.TrimSpace(string(durData))); err == nil && dur > segTime {
			segTime = dur
		}
	}

	// Create touch file (CONS_TMP_PATH) like PHP does
	if h.consTmpPath != "" && uuid != "" {
		touchPath := filepath.Join(h.consTmpPath, uuid)
		os.WriteFile(touchPath, nil, 0644)
		defer os.Remove(touchPath)
	}

	// Set headers for continuous MPEG-TS delivery
	w.Header().Set("Content-Type", "video/mp2t")
	w.Header().Set("Cache-Control", "no-store, no-cache, must-revalidate")
	w.Header().Set("X-Accel-Buffering", "no")

	flusher, canFlush := w.(http.Flusher)

	// Find the current (latest) segment
	currentSeg := h.findCurrentSegment(streamID)
	if currentSeg < 0 {
		log.Printf("stream %d: no segments found", streamID)
		http.Error(w, "stream not found", http.StatusNotFound)
		return
	}

	// Serve prebuffer: send recent segments from cache/disk for instant start
	var prebufBytes int64
	prebufStart := time.Now()
	if prebufferSec > 0 && segTime > 0 {
		prebufSegs := prebufferSec / segTime
		if prebufSegs < 1 {
			prebufSegs = 1
		}
		start := currentSeg - prebufSegs
		if start < 0 {
			start = 0
		}
		log.Printf("stream %d: prebuffer %d segments (%d-%d)", streamID, currentSeg-start, start, currentSeg-1)
		for seg := start; seg < currentSeg; seg++ {
			data := h.getSegmentData(streamID, seg)
			if data != nil {
				n, err := w.Write(data)
				prebufBytes += int64(n)
				if err != nil {
					return
				}
			}
		}
		if canFlush {
			flusher.Flush()
		}
	}

	// Write initial divergence from prebuffer if tracking enabled
	if h.divergencePath != "" && uuid != "" && prebufBytes > 0 {
		elapsed := time.Since(prebufStart).Seconds()
		if elapsed > 0.1 {
			speed := int(float64(prebufBytes) / elapsed / 1024)
			divPath := filepath.Join(h.divergencePath, uuid)
			os.WriteFile(divPath, []byte(strconv.Itoa(speed)), 0644)
			defer os.Remove(divPath)
		}
	} else if h.divergencePath != "" && uuid != "" {
		// Ensure divergence file is cleaned up on exit
		divPath := filepath.Join(h.divergencePath, uuid)
		defer os.Remove(divPath)
	}

	// Subscribe to inotify for this stream
	segCh := h.watcher.Subscribe(streamID)
	defer h.watcher.Unsubscribe(streamID, segCh)

	// Chase-read loop: serve segments as ffmpeg writes them.
	// Stops on: client disconnect, stream end, external close (heartbeat),
	// or admin drop signal. NEVER on DB state directly.
	failCount := 0
	totalFails := h.segWaitTime * 2
	if totalFails < segTime*2 {
		totalFails = segTime * 2
	}
	if totalFails < 20 {
		totalFails = 20
	}

	var segBytes int64
	segStart := time.Now()

	for {
		// Check client disconnect
		select {
		case <-r.Context().Done():
			return
		default:
		}

		// Check external disconnect (admin kill, reaper, limiter)
		if stopCh != nil {
			select {
			case <-stopCh:
				log.Printf("stream %d: uuid=%s externally disconnected", streamID, uuid)
				return
			default:
			}
		}

		// Check admin drop signal file (like PHP checks SIGNALS_PATH)
		if h.signalsPath != "" && uuid != "" {
			if dropped := h.checkSignalFile(uuid); dropped {
				log.Printf("stream %d: uuid=%s admin drop signal", streamID, uuid)
				return
			}
		}

		// Try to serve the current segment
		data := h.getSegmentData(streamID, currentSeg)
		if data != nil {
			n, err := w.Write(data)
			segBytes += int64(n)
			if err != nil {
				return
			}
			if canFlush {
				flusher.Flush()
			}

			// Update divergence tracking between segments
			if h.divergencePath != "" && uuid != "" {
				elapsed := time.Since(segStart).Seconds()
				if elapsed > 0.1 {
					speed := int(float64(segBytes) / elapsed / 1024)
					divPath := filepath.Join(h.divergencePath, uuid)
					os.WriteFile(divPath, []byte(strconv.Itoa(speed)), 0644)
				}
				segBytes = 0
				segStart = time.Now()
			}

			currentSeg++
			failCount = 0
			continue
		}

		// Segment not yet ready — try chase-read (segment being written by ffmpeg)
		if h.segmentFileExists(streamID, currentSeg) {
			sent, err := h.chaseReadSegment(w, flusher, canFlush, streamID, currentSeg)
			if err != nil {
				return
			}
			if sent > 0 {
				segBytes += sent
				currentSeg++
				failCount = 0
				continue
			}
		}

		// Wait for inotify notification or timeout
		select {
		case <-r.Context().Done():
			return
		case newSeg, ok := <-segCh:
			if !ok {
				return
			}
			if newSeg >= currentSeg {
				failCount = 0
			}
		case <-time.After(500 * time.Millisecond):
			if h.segmentFileExists(streamID, currentSeg) {
				continue
			}
			failCount++
			if failCount >= totalFails {
				log.Printf("stream %d: timed out waiting for segment %d", streamID, currentSeg)
				return
			}
		}
	}
}

// checkSignalFile checks for admin drop/signal file and returns true if viewer
// should be disconnected. Handles both "drop" and "signal" types like PHP.
func (h *TSHandler) checkSignalFile(uuid string) bool {
	signalPath := filepath.Join(h.signalsPath, uuid)
	data, err := os.ReadFile(signalPath)
	if err != nil {
		return false // no signal file
	}
	var sig map[string]interface{}
	if err := json.Unmarshal(data, &sig); err != nil {
		// Invalid JSON — remove and ignore
		os.Remove(signalPath)
		return false
	}
	sigType, _ := sig["type"].(string)
	switch sigType {
	case "drop":
		os.Remove(signalPath)
		return true
	case "signal":
		// PHP applies an overlay on the next segment. Go doesn't support this yet;
		// consume the signal file so it doesn't pile up.
		os.Remove(signalPath)
		return false
	default:
		os.Remove(signalPath)
		return false
	}
}

// chaseReadSegment reads a segment that ffmpeg is still writing, streaming
// data to the client as it becomes available. Returns bytes sent.
func (h *TSHandler) chaseReadSegment(w http.ResponseWriter, flusher http.Flusher, canFlush bool, streamID, segIdx int) (int64, error) {
	path := h.segmentPath(streamID, segIdx)
	f, err := os.Open(path)
	if err != nil {
		return 0, nil
	}
	defer f.Close()

	nextExists := func() bool {
		return h.segmentFileExists(streamID, segIdx+1)
	}

	var totalSent int64
	buf := make([]byte, h.readBufSize)
	stallCount := 0
	maxStalls := 20

	for {
		n, readErr := f.Read(buf)
		if n > 0 {
			written, writeErr := w.Write(buf[:n])
			totalSent += int64(written)
			if writeErr != nil {
				return totalSent, writeErr
			}
			if canFlush {
				flusher.Flush()
			}
			stallCount = 0
		}

		if readErr == io.EOF {
			if nextExists() {
				remaining, _ := io.ReadAll(f)
				if len(remaining) > 0 {
					written, writeErr := w.Write(remaining)
					totalSent += int64(written)
					if writeErr != nil {
						return totalSent, writeErr
					}
				}
				h.cacheFromDisk(streamID, segIdx)
				return totalSent, nil
			}
			stallCount++
			if stallCount >= maxStalls {
				if totalSent > 0 {
					h.cacheFromDisk(streamID, segIdx)
					return totalSent, nil
				}
				return 0, nil
			}
			time.Sleep(100 * time.Millisecond)
			continue
		}

		if readErr != nil {
			return totalSent, readErr
		}
	}
}

func (h *TSHandler) getSegmentData(streamID, segIdx int) []byte {
	if cached := h.cache.Get(streamID, segIdx); cached != nil {
		return cached.Data
	}
	if !h.segmentFileExists(streamID, segIdx+1) {
		return nil
	}
	data, err := os.ReadFile(h.segmentPath(streamID, segIdx))
	if err != nil || len(data) == 0 {
		return nil
	}
	h.cache.Put(streamID, segIdx, data)
	return data
}

func (h *TSHandler) cacheFromDisk(streamID, segIdx int) {
	data, err := os.ReadFile(h.segmentPath(streamID, segIdx))
	if err != nil || len(data) == 0 {
		return
	}
	h.cache.Put(streamID, segIdx, data)
}

func (h *TSHandler) findCurrentSegment(streamID int) int {
	pattern := filepath.Join(h.streamsPath, fmt.Sprintf("%d_*.ts", streamID))
	matches, err := filepath.Glob(pattern)
	if err != nil || len(matches) == 0 {
		return -1
	}

	maxSeg := -1
	prefix := fmt.Sprintf("%d_", streamID)
	for _, m := range matches {
		base := filepath.Base(m)
		if !strings.HasPrefix(base, prefix) {
			continue
		}
		numStr := strings.TrimPrefix(base, prefix)
		numStr = strings.TrimSuffix(numStr, ".ts")
		if n, err := strconv.Atoi(numStr); err == nil && n > maxSeg {
			maxSeg = n
		}
	}
	return maxSeg
}

func (h *TSHandler) segmentPath(streamID, segIdx int) string {
	return filepath.Join(h.streamsPath, fmt.Sprintf("%d_%d.ts", streamID, segIdx))
}

func (h *TSHandler) segmentFileExists(streamID, segIdx int) bool {
	_, err := os.Stat(h.segmentPath(streamID, segIdx))
	return err == nil
}

func init() {
	log.SetFlags(log.LstdFlags | log.Lshortfile)
}
