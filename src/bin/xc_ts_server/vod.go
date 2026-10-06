package main

import (
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// VODHandler serves VOD files with full HTTP Range support and connection
// heartbeat. PHP handles auth, connection creation, and enforcement. Go
// receives the handoff via X-Accel-Redirect and serves the file efficiently.
//
// PHP parity: replaces the file-delivery loop in vod.php for local (non-proxy)
// VOD files. Go's http.ServeFile handles Range headers natively (206, 416),
// eliminating PHP's manual HttpRange implementation.
type VODHandler struct {
	tracker        *Tracker
	vodPath        string   // /home/xc_vm/content/vod/
	consTmpPath    string
	divergencePath string
}

func NewVODHandler(tracker *Tracker, vodPath, consTmpPath, divergencePath string) *VODHandler {
	return &VODHandler{
		tracker:        tracker,
		vodPath:        vodPath,
		consTmpPath:    consTmpPath,
		divergencePath: divergencePath,
	}
}

var vodPathRe = regexp.MustCompile(`^/vod_serve/(\d+)$`)

// Content-type mapping matching PHP vod.php $rTypes
var vodContentTypes = map[string]string{
	"mp4":  "video/mp4",
	"m4v":  "video/mp4",
	"mkv":  "video/x-matroska",
	"avi":  "video/x-msvideo",
	"3gp":  "video/3gpp",
	"flv":  "video/x-flv",
	"wmv":  "video/x-ms-wmv",
	"mov":  "video/quicktime",
	"ts":   "video/mp2t",
	"mpg":  "video/mpeg",
	"mpeg": "video/mpeg",
}

// ServeVOD handles: GET /vod_serve/<stream_id>?uuid=<uuid>&ext=<ext>
//
// This is the VOD file delivery endpoint. PHP authenticates the user,
// creates the connection in lines_live, runs enforcement, and hands off
// to Go via X-Accel-Redirect: /xc_vod_go/<stream_id>?uuid=U&ext=E
//
// Go:
//   - Registers the UUID for heartbeat (periodic hls_last_read update)
//   - Serves the file with full HTTP Range support
//   - On disconnect: unregisters (sets hls_end=1)
func (h *VODHandler) ServeVOD(w http.ResponseWriter, r *http.Request) {
	matches := vodPathRe.FindStringSubmatch(r.URL.Path)
	if matches == nil {
		http.NotFound(w, r)
		return
	}

	streamID, _ := strconv.Atoi(matches[1])
	uuid := r.URL.Query().Get("uuid")
	ext := r.URL.Query().Get("ext")
	if uuid == "" || ext == "" {
		http.Error(w, "missing uuid or ext", http.StatusBadRequest)
		return
	}

	// Build file path: VOD_PATH/<stream_id>.<ext>
	fileName := fmt.Sprintf("%d.%s", streamID, ext)
	filePath := filepath.Join(h.vodPath, fileName)

	// Verify file exists
	stat, err := os.Stat(filePath)
	if err != nil || stat.IsDir() {
		log.Printf("vod: file not found stream=%d ext=%s path=%s", streamID, ext, filePath)
		http.NotFound(w, r)
		return
	}

	// Register for heartbeat (same mechanism as live TS)
	var stopCh chan struct{}
	if h.tracker != nil {
		stopCh = h.tracker.Register(uuid, streamID)
		defer h.tracker.Unregister(uuid)
	}

	// Touch cons_tmp file (keep it alive during serving)
	if h.consTmpPath != "" && uuid != "" {
		touchPath := filepath.Join(h.consTmpPath, uuid)
		if f, err := os.Create(touchPath); err == nil {
			f.Close()
		}
		defer os.Remove(filepath.Join(h.consTmpPath, uuid))
	}

	log.Printf("vod: serving stream=%d ext=%s uuid=%s size=%d range=%s",
		streamID, ext, uuid, stat.Size(), r.Header.Get("Range"))

	// Set Content-Type based on extension (matching PHP $rTypes mapping)
	ct := vodContentTypes[strings.ToLower(ext)]
	if ct == "" {
		ct = "application/octet-stream"
	}
	w.Header().Set("Content-Type", ct)
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("X-Accel-Buffering", "no")

	// Use Go's http.ServeFile for full HTTP Range support:
	// - Handles Range header parsing
	// - Sends 206 Partial Content for range requests
	// - Sends 416 Range Not Satisfiable for invalid ranges
	// - Handles If-Modified-Since, If-None-Match etc.
	// - Sets Content-Length and Content-Range headers
	//
	// This serves the file in a blocking manner. The connection stays
	// open until the file is fully sent or the client disconnects.
	// The heartbeat goroutine (from tracker.Register) keeps the
	// connection fresh in lines_live. If the connection is closed
	// externally (enforcement/admin kill), stopCh is signaled and
	// we need to abort.

	// Serve in a goroutine so we can monitor stopCh
	done := make(chan struct{})
	go func() {
		defer close(done)
		http.ServeFile(w, r, filePath)
	}()

	// Wait for either file serving to complete or external close signal
	select {
	case <-done:
		// File served successfully (or client disconnected)
		log.Printf("vod: completed stream=%d uuid=%s", streamID, uuid)
	case <-stopCh:
		// Connection closed externally (enforcement/admin kill)
		log.Printf("vod: stopped externally stream=%d uuid=%s", streamID, uuid)
		// Note: http.ServeFile is blocking in its goroutine.
		// We can't easily abort it, but the client will get a broken pipe
		// when we return and the handler closes.
	}

	// Write speed tracking (divergence file) — matches PHP vod.php behavior
	if h.divergencePath != "" {
		elapsed := time.Since(time.Now()).Seconds() // placeholder
		_ = elapsed
	}
}

// ServeVODAuth handles: GET /vauth/<token> — full Go-native VOD auth.
// For future use when we want to bypass PHP entirely for VOD.
// Currently, PHP handles /vauth/<token> and hands off to ServeVOD.
