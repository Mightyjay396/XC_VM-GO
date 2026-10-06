package main

import (
	"database/sql"
	"fmt"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
)

// OnDemandStarter handles starting on-demand streams that aren't running.
// Mirrors PHP's live.php on-demand logic:
//  1. Determine start mode: startMonitor or startProxy (via FanoutMode::startFor logic)
//  2. flock(STREAMS_PATH/<stream_id>_.start) — one start per stream
//  3. Check if monitor/proxy already running
//  4. exec PHP_BIN console.php monitor|proxy <stream_id>
//  5. Wait for _.pid file and first segment (on_demand_wait_time)
type OnDemandStarter struct {
	streamsPath string
	phpBin      string // /home/xc_vm/bin/php/bin/php
	mainHome    string // /home/xc_vm/
	waitTime    int    // on_demand_wait_time from settings (seconds)
	serverID    int
	instantOff  int // on_demand_instant_off from settings
	segTime     int // segment time from settings (seconds)

	// DB for direct ffmpeg fallback when PHP CLI fails
	db        *sql.DB
	ffmpegBin string // /home/xc_vm/bin/ffmpeg_bin/<version>/ffmpeg

	// Per-stream locks to prevent concurrent starts in Go
	mu      sync.Mutex
	pending map[int]chan struct{} // stream_id → wait channel

	// On-demand viewer tracking (monitoring only — PHP daemon handles kill)
	queueMu sync.Mutex
	queue   map[int]map[string]bool // stream_id → set of UUIDs
}

func NewOnDemandStarter(streamsPath, phpBin, mainHome string, waitTime, serverID, instantOff, segTime int, signalsTmpPath string, db *sql.DB) *OnDemandStarter {
	if waitTime <= 0 {
		waitTime = 60
	}
	if segTime <= 0 {
		segTime = 10
	}
	// Auto-detect ffmpeg binary
	ffmpegBin := ""
	ffmpegDir := filepath.Join(mainHome, "bin/ffmpeg_bin")
	if entries, err := os.ReadDir(ffmpegDir); err == nil {
		for _, e := range entries {
			if e.IsDir() {
				candidate := filepath.Join(ffmpegDir, e.Name(), "ffmpeg")
				if _, err := os.Stat(candidate); err == nil {
					ffmpegBin = candidate
					break
				}
			}
		}
	}
	return &OnDemandStarter{
		streamsPath: streamsPath,
		phpBin:      phpBin,
		mainHome:    mainHome,
		waitTime:    waitTime,
		serverID:    serverID,
		instantOff:  instantOff,
		segTime:     segTime,
		db:          db,
		ffmpegBin:   ffmpegBin,
		pending:     make(map[int]chan struct{}),
		queue:       make(map[int]map[string]bool),
	}
}

// StartResult holds the outcome of an on-demand start attempt.
type StartResult struct {
	OK    bool
	Error string
}

// StartMode determines which start command to use, matching PHP FanoutMode::startFor().
// Since fanout_enabled=0 → legacy=true:
//   - proxy stream → START_PROXY (console.php proxy)
//   - non-proxy on-demand → START_MONITOR (console.php monitor)
//   - non-proxy not-on-demand → OFF_AIR
type StartMode int

const (
	StartMonitor StartMode = iota
	StartProxy
	StartOffAir
)

func (o *OnDemandStarter) determineStartMode(isProxy bool, isOnDemand bool) StartMode {
	// Matches FanoutMode::startFor(legacy=true, proxy, onDemand)
	// With legacy=true (fanout off):
	//   proxy → START_PROXY
	//   !proxy && onDemand → START_MONITOR
	//   !proxy && !onDemand → OFF_AIR
	if isProxy {
		return StartProxy
	}
	if isOnDemand {
		return StartMonitor
	}
	return StartOffAir
}

// StartAndWait attempts to start an on-demand stream and waits for it to
// become available. Multiple concurrent callers for the same stream share
// the wait — only the first one triggers the actual start.
func (o *OnDemandStarter) StartAndWait(streamID int, isProxy bool) StartResult {
	mode := o.determineStartMode(isProxy, true)
	if mode == StartOffAir {
		return StartResult{OK: false, Error: "off_air"}
	}

	o.mu.Lock()
	if ch, exists := o.pending[streamID]; exists {
		// Another goroutine is already starting this stream — just wait
		o.mu.Unlock()
		log.Printf("ondemand: stream=%d waiting for existing start", streamID)
		<-ch
		return o.checkResult(streamID)
	}

	// We are the first — create the wait channel
	ch := make(chan struct{})
	o.pending[streamID] = ch
	o.mu.Unlock()

	defer func() {
		o.mu.Lock()
		delete(o.pending, streamID)
		close(ch) // wake up all waiters
		o.mu.Unlock()
	}()

	return o.doStart(streamID, mode)
}

// insertGuardViewer inserts a temporary lines_live row so the XC_VM watchdog
// sees at least one viewer for the on-demand stream while FFmpeg boots.
// Returns the placeholder UUID used (caller must removeGuardViewer after the
// real client connection is registered).
func (o *OnDemandStarter) insertGuardViewer(streamID int) string {
	if o.db == nil || o.serverID <= 0 {
		return ""
	}
	uuid := fmt.Sprintf("g%d%d", streamID, time.Now().UnixNano()%1e9)
	if len(uuid) > 32 {
		uuid = uuid[:32]
	}
	_, err := o.db.Exec(
		`INSERT INTO lines_live (uuid, user_id, stream_id, server_id, container, date_start, hls_last_read, user_ip, user_agent)
		 VALUES (?, 0, ?, ?, 'ts', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), '127.0.0.1', 'go-ts-guard')`,
		uuid, streamID, o.serverID,
	)
	if err != nil {
		log.Printf("ondemand: stream=%d guard viewer insert failed: %v", streamID, err)
		return ""
	}
	log.Printf("ondemand: stream=%d guard viewer inserted uuid=%s", streamID, uuid)
	return uuid
}

func (o *OnDemandStarter) removeGuardViewer(uuid string) {
	if uuid == "" || o.db == nil {
		return
	}
	o.db.Exec("DELETE FROM `lines_live` WHERE `uuid`=?", uuid)
	log.Printf("ondemand: guard viewer removed uuid=%s", uuid)
}

func (o *OnDemandStarter) doStart(streamID int, mode StartMode) StartResult {
	// Insert a temporary viewer so the watchdog doesn't kill the stream
	// while FFmpeg boots (typically 3-15 seconds).
	guardUUID := o.insertGuardViewer(streamID)
	defer o.removeGuardViewer(guardUUID)

	// Step 1: Take file lock (matching PHP lockOnDemandStart)
	lockPath := filepath.Join(o.streamsPath, fmt.Sprintf("%d_.start", streamID))
	lockFile, err := os.OpenFile(lockPath, os.O_CREATE|os.O_RDWR, 0644)
	if err != nil {
		log.Printf("ondemand: stream=%d lock open failed: %v", streamID, err)
		return StartResult{OK: false, Error: "lock_failed"}
	}
	defer lockFile.Close()

	if err := flockExclusive(lockFile); err != nil {
		log.Printf("ondemand: stream=%d flock failed: %v", streamID, err)
		return StartResult{OK: false, Error: "lock_failed"}
	}
	defer flockUnlock(lockFile)

	// Step 2: Recheck if stream appeared while waiting for lock
	if o.hasSegments(streamID) {
		log.Printf("ondemand: stream=%d already has segments (appeared during lock wait)", streamID)
		return StartResult{OK: true}
	}

	// Step 3: Read current PID files
	monitorPidFile := filepath.Join(o.streamsPath, fmt.Sprintf("%d_.monitor", streamID))
	pidFile := filepath.Join(o.streamsPath, fmt.Sprintf("%d_.pid", streamID))

	switch mode {
	case StartProxy:
		return o.doStartProxy(streamID, monitorPidFile, pidFile)
	case StartMonitor:
		return o.doStartMonitor(streamID, monitorPidFile, pidFile)
	default:
		return StartResult{OK: false, Error: "off_air"}
	}
}

// doStartProxy mirrors PHP's START_PROXY path in live.php:
//  1. Check if XC_VMProxy is already running for this stream
//  2. If not, clean up stale files and exec console.php proxy <stream_id>
//  3. Wait for _.monitor file (the proxy process writes it)
//  4. The proxy process IS the producer (no separate _.pid wait needed for proxy)
func (o *OnDemandStarter) doStartProxy(streamID int, monitorPidFile, pidFile string) StartResult {
	// Check if proxy is already running (matching PHP's isNamedProcessRunning check)
	monitorPid := o.readPidFile(monitorPidFile)
	if monitorPid > 0 && o.isNamedProcessRunning(monitorPid, "XC_VMProxy", streamID) {
		log.Printf("ondemand: stream=%d proxy already alive (pid=%d)", streamID, monitorPid)
		// For proxy, the monitor IS the producer — wait for segments
		return o.waitForSegments(streamID)
	}

	// Clean up stale files
	os.Remove(monitorPidFile)
	os.Remove(pidFile)

	// Start proxy via PHP console.php — must run as xc_vm user
	log.Printf("ondemand: stream=%d starting proxy via sudo -u xc_vm %s console.php proxy %d", streamID, o.phpBin, streamID)
	cmd := o.buildPHPCommand("proxy", streamID)
	if err := cmd.Start(); err != nil {
		log.Printf("ondemand: stream=%d proxy start failed: %v", streamID, err)
		return StartResult{OK: false, Error: "start_failed"}
	}
	go cmd.Wait()

	// Wait for monitor PID file (10s — PHP may need time for ffprobe before writing _.monitor)
	if o.awaitFile(monitorPidFile, 10*time.Second, 100*time.Millisecond) {
		monitorPid = o.readPidFile(monitorPidFile)
		log.Printf("ondemand: stream=%d proxy monitor pid=%d", streamID, monitorPid)
	}

	if monitorPid <= 0 {
		log.Printf("ondemand: stream=%d proxy monitor pid not created", streamID)
		return StartResult{OK: false, Error: "not_on_air"}
	}

	// For proxy, the monitor_pid IS the producer — set it as the pid
	// and wait for segments
	return o.waitForSegments(streamID)
}

// doStartMonitor mirrors PHP's START_MONITOR path in live.php:
//  1. Check if monitor is already watched (isWatched check)
//  2. If not, clean up stale files and exec console.php monitor <stream_id>
//  3. Wait for _.monitor file
//  4. Wait for _.pid file (on_demand_wait_time)
//  5. Wait for first TS segment
func (o *OnDemandStarter) doStartMonitor(streamID int, monitorPidFile, pidFile string) StartResult {
	// Check if monitor is already running (matching PHP isWatched)
	monitorPid := o.readPidFile(monitorPidFile)
	if monitorPid > 0 && o.processAlive(monitorPid) {
		log.Printf("ondemand: stream=%d monitor already alive (pid=%d), waiting for segments", streamID, monitorPid)
	} else {
		// Clean up stale files (matching PHP)
		os.Remove(monitorPidFile)
		os.Remove(pidFile)

		// Start monitor via PHP console.php — must run as xc_vm user
		log.Printf("ondemand: stream=%d starting monitor via sudo -u xc_vm %s console.php monitor %d", streamID, o.phpBin, streamID)
		cmd := o.buildPHPCommand("monitor", streamID)
		if err := cmd.Start(); err != nil {
			log.Printf("ondemand: stream=%d monitor start failed: %v", streamID, err)
			return StartResult{OK: false, Error: "start_failed"}
		}
		go cmd.Wait()

		// Wait for monitor PID file (10s — PHP may need time for ffprobe before writing _.monitor)
		if o.awaitFile(monitorPidFile, 10*time.Second, 100*time.Millisecond) {
			monitorPid = o.readPidFile(monitorPidFile)
			log.Printf("ondemand: stream=%d monitor pid=%d", streamID, monitorPid)
		}

		if monitorPid <= 0 {
			log.Printf("ondemand: stream=%d monitor pid not created, trying direct ffmpeg fallback", streamID)
			return o.doStartFFmpegDirect(streamID)
		}
	}

	// Wait for producer PID file — use a short timeout, then fallback to direct ffmpeg
	pidWait := 15 * time.Second
	log.Printf("ondemand: stream=%d waiting up to %s for producer pid", streamID, pidWait)
	if !o.awaitFile(pidFile, pidWait, 50*time.Millisecond) {
		// Producer not created in 15s — check if monitor is still alive
		monitorPid = o.readPidFile(monitorPidFile)
		monitorAlive := monitorPid > 0 && o.processAlive(monitorPid)

		if monitorAlive {
			// Monitor alive but slow — give it a bit more time
			remaining := 15 * time.Second
			log.Printf("ondemand: stream=%d monitor pid=%d still alive, extending wait %s", streamID, monitorPid, remaining)
			if !o.awaitFile(pidFile, remaining, 50*time.Millisecond) {
				log.Printf("ondemand: stream=%d producer still not created after extended wait, trying direct ffmpeg", streamID)
				os.Remove(monitorPidFile)
				os.Remove(pidFile)
				return o.doStartFFmpegDirect(streamID)
			}
		} else {
			// Monitor died without creating producer → PHP CLI broken → ffmpeg fallback
			log.Printf("ondemand: stream=%d monitor died (pid=%d alive=%v), trying direct ffmpeg", streamID, monitorPid, monitorAlive)
			os.Remove(monitorPidFile)
			os.Remove(pidFile)
			return o.doStartFFmpegDirect(streamID)
		}
	}

	producerPid := o.readPidFile(pidFile)
	log.Printf("ondemand: stream=%d producer pid=%d", streamID, producerPid)

	if producerPid <= 0 || !o.processAlive(producerPid) {
		log.Printf("ondemand: stream=%d producer not alive, trying direct ffmpeg", streamID)
		os.Remove(monitorPidFile)
		os.Remove(pidFile)
		return o.doStartFFmpegDirect(streamID)
	}

	// Wait for first TS segment
	return o.waitForSegments(streamID)
}

func (o *OnDemandStarter) waitForSegments(streamID int) StartResult {
	segWait := time.Duration(o.waitTime) * time.Second

	// Check for _0.ts or _0.m4s (matching PHP awaitAnyFileExists)
	firstTS := filepath.Join(o.streamsPath, fmt.Sprintf("%d_0.ts", streamID))
	firstM4S := filepath.Join(o.streamsPath, fmt.Sprintf("%d_0.m4s", streamID))

	log.Printf("ondemand: stream=%d waiting up to %s for first segment", streamID, segWait)
	deadline := time.Now().Add(segWait)
	for time.Now().Before(deadline) {
		if fileExists(firstTS) || fileExists(firstM4S) {
			log.Printf("ondemand: stream=%d first segment found, stream is ready", streamID)
			return StartResult{OK: true}
		}
		time.Sleep(100 * time.Millisecond)
	}

	log.Printf("ondemand: stream=%d first segment not found in %s", streamID, segWait)
	return StartResult{OK: false, Error: "wait_time_expired"}
}

func (o *OnDemandStarter) checkResult(streamID int) StartResult {
	if o.hasSegments(streamID) {
		return StartResult{OK: true}
	}
	return StartResult{OK: false, Error: "wait_time_expired"}
}

func (o *OnDemandStarter) hasSegments(streamID int) bool {
	pattern := filepath.Join(o.streamsPath, fmt.Sprintf("%d_*.ts", streamID))
	matches, err := filepath.Glob(pattern)
	if err == nil && len(matches) > 0 {
		return true
	}
	// Also check .m4s
	pattern = filepath.Join(o.streamsPath, fmt.Sprintf("%d_*.m4s", streamID))
	matches, err = filepath.Glob(pattern)
	return err == nil && len(matches) > 0
}

func (o *OnDemandStarter) readPidFile(path string) int {
	data, err := os.ReadFile(path)
	if err != nil {
		return 0
	}
	pid, err := strconv.Atoi(strings.TrimSpace(string(data)))
	if err != nil {
		return 0
	}
	return pid
}

func (o *OnDemandStarter) processAlive(pid int) bool {
	if pid <= 0 {
		return false
	}
	return fileExists(fmt.Sprintf("/proc/%d", pid))
}

// doStartFFmpegDirect is the fallback when PHP CLI cannot start a stream
// (e.g. config.enc is corrupted). Go queries the DB for the stream source
// and starts ffmpeg directly, mimicking what console.php monitor does.
func (o *OnDemandStarter) doStartFFmpegDirect(streamID int) StartResult {
	if o.db == nil || o.ffmpegBin == "" {
		log.Printf("ondemand: stream=%d direct ffmpeg fallback not available (db=%v ffmpeg=%s)", streamID, o.db != nil, o.ffmpegBin)
		return StartResult{OK: false, Error: "not_on_air"}
	}

	// Query DB for stream source URL
	sourceURL, err := o.getStreamSource(streamID)
	if err != nil || sourceURL == "" {
		log.Printf("ondemand: stream=%d no source URL found: %v", streamID, err)
		return StartResult{OK: false, Error: "not_on_air"}
	}

	log.Printf("ondemand: stream=%d starting ffmpeg directly with source: %s", streamID, truncateURL(sourceURL))

	// Ensure stream directory exists
	streamDir := o.streamsPath

	// Build ffmpeg command matching XC_VM's pattern
	idStr := strconv.Itoa(streamID)
	progressPath := filepath.Join(streamDir, idStr+"_.progress")
	segPattern := filepath.Join(streamDir, idStr+"_%d.ts")
	m3u8Path := filepath.Join(streamDir, idStr+"_.m3u8")
	pidFile := filepath.Join(streamDir, idStr+"_.pid")
	monitorFile := filepath.Join(streamDir, idStr+"_.monitor")

	segTime := strconv.Itoa(o.segTime)

	args := []string{
		"-y", "-nostdin", "-hide_banner", "-loglevel", "warning",
		"-err_detect", "ignore_err",
		"-thread_queue_size", "1024",
		"-fflags", "+genpts", "-async", "1",
		"-reconnect", "1", "-reconnect_streamed", "1", "-reconnect_delay_max", "5",
		"-probesize", "1500000", "-analyzeduration", "1500000",
		"-progress", progressPath,
		"-i", sourceURL,
		"-max_muxing_queue_size", "1024",
		"-vcodec", "copy", "-sn", "-acodec", "copy",
		"-map", "0", "-copy_unknown",
		"-individual_header_trailer", "0",
		"-f", "hls",
		"-hls_init_time", "2",
		"-hls_time", segTime,
		"-hls_list_size", "6",
		"-hls_delete_threshold", "4",
		"-hls_flags", "delete_segments+discont_start+omit_endlist",
		"-hls_segment_type", "mpegts",
		"-hls_segment_filename", segPattern,
		m3u8Path,
	}

	cmd := exec.Command("sudo", append([]string{"-u", "xc_vm", o.ffmpegBin}, args...)...)
	cmd.Dir = o.mainHome
	cmd.Stdout = nil
	cmd.Stderr = nil

	if err := cmd.Start(); err != nil {
		log.Printf("ondemand: stream=%d ffmpeg start failed: %v", streamID, err)
		return StartResult{OK: false, Error: "start_failed"}
	}

	pid := cmd.Process.Pid
	log.Printf("ondemand: stream=%d ffmpeg started pid=%d", streamID, pid)

	// Write PID files (matching console.php monitor behavior)
	os.WriteFile(pidFile, []byte(strconv.Itoa(pid)), 0644)
	os.WriteFile(monitorFile, []byte(strconv.Itoa(os.Getpid())), 0644)

	// Update streams_servers table
	if o.db != nil {
		_, _ = o.db.Exec(
			"UPDATE streams_servers SET pid=?, monitor_pid=?, on_demand=1, stream_status=1, stream_started=UNIX_TIMESTAMP() WHERE stream_id=? AND server_id=?",
			pid, os.Getpid(), streamID, o.serverID,
		)
	}

	// Wait for ffmpeg in background (clean up PID files on exit)
	go func() {
		cmd.Wait()
		log.Printf("ondemand: stream=%d ffmpeg pid=%d exited", streamID, pid)
		os.Remove(pidFile)
	}()

	// Wait for first segment
	return o.waitForSegments(streamID)
}

// getStreamSource queries the DB for the source URL of a stream on this server.
func (o *OnDemandStarter) getStreamSource(streamID int) (string, error) {
	// First check streams_servers.current_source for this specific server
	var source sql.NullString
	err := o.db.QueryRow(
		"SELECT current_source FROM streams_servers WHERE stream_id=? AND server_id=?",
		streamID, o.serverID,
	).Scan(&source)
	if err == nil && source.Valid && source.String != "" {
		return source.String, nil
	}

	// Fallback: check streams.direct_source
	err = o.db.QueryRow(
		"SELECT direct_source FROM streams WHERE id=?", streamID,
	).Scan(&source)
	if err == nil && source.Valid && source.String != "" && source.String != "0" {
		return source.String, nil
	}

	// Fallback: check streams.stream_source
	err = o.db.QueryRow(
		"SELECT stream_source FROM streams WHERE id=?", streamID,
	).Scan(&source)
	if err == nil && source.Valid && source.String != "" {
		// stream_source is a JSON array like ["http:\/\/..."]
		src := strings.TrimSpace(source.String)
		if strings.HasPrefix(src, "[") {
			src = strings.Trim(src, "[]")
			parts := strings.SplitN(src, ",", 2)
			src = strings.Trim(parts[0], "\"' ")
		}
		// Unescape JSON slashes
		src = strings.ReplaceAll(src, "\\/", "/")
		if src != "" && src != "0" {
			return src, nil
		}
	}

	return "", fmt.Errorf("no source found for stream %d on server %d", streamID, o.serverID)
}

// truncateURL shortens a URL for logging (hides credentials).
func truncateURL(u string) string {
	if len(u) > 80 {
		return u[:40] + "..." + u[len(u)-30:]
	}
	return u
}

// isNamedProcessRunning matches PHP ProcessManager::isNamedProcessRunning
// Checks if PID is alive and its cmdline contains the expected name and stream ID.
func (o *OnDemandStarter) isNamedProcessRunning(pid int, name string, streamID int) bool {
	if pid <= 0 || !o.processAlive(pid) {
		return false
	}
	cmdline, err := os.ReadFile(fmt.Sprintf("/proc/%d/cmdline", pid))
	if err != nil {
		return false
	}
	cmdStr := string(cmdline)
	return strings.Contains(cmdStr, name) || strings.Contains(cmdStr, strconv.Itoa(streamID))
}

// awaitFile waits for a file to exist, checking every interval up to timeout.
func (o *OnDemandStarter) awaitFile(path string, timeout, interval time.Duration) bool {
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		if fileExists(path) {
			return true
		}
		time.Sleep(interval)
	}
	return false
}

// buildPHPCommand creates an exec.Command that runs PHP console.php as the
// xc_vm user. XC_VM's console.php refuses to run as root ("Please run as
// XC_VM!"), so we must use sudo -u xc_vm with proper environment.
func (o *OnDemandStarter) buildPHPCommand(action string, streamID int) *exec.Cmd {
	consolePath := filepath.Join(o.mainHome, "console.php")
	// Use sudo with environment preservation for HOME and USER
	cmd := exec.Command("sudo", "-u", "xc_vm",
		"env", "HOME=/home/xc_vm", "USER=xc_vm",
		o.phpBin, consolePath, action, strconv.Itoa(streamID))
	cmd.Dir = o.mainHome
	cmd.Stdout = nil
	cmd.Stderr = nil
	return cmd
}

// ──────────────────────────────────────────────
// On-demand viewer queue — compatibility layer
// ──────────────────────────────────────────────
// PHP's XC_VM[Ondemand] daemon already runs on the node and handles stream
// shutdown by checking lines_live viewer counts + PID queue + 30s age guard.
// Since Go writes connections to lines_live, the PHP daemon sees Go viewers.
// When the last Go viewer disconnects, Go sets hls_end=1 and deletes the row,
// the daemon sees online_clients=0 and kills the stream — exactly like PHP.
//
// Go does NOT kill producers itself. The PHP daemon is the single authority.
//
// AddToQueue / RemoveFromQueue are kept as no-ops for logging/monitoring.

// AddToQueue logs that a viewer joined an on-demand stream.
func (o *OnDemandStarter) AddToQueue(streamID int, uuid string) {
	if o.instantOff == 0 {
		return
	}
	o.queueMu.Lock()
	if o.queue[streamID] == nil {
		o.queue[streamID] = make(map[string]bool)
	}
	o.queue[streamID][uuid] = true
	count := len(o.queue[streamID])
	o.queueMu.Unlock()
	log.Printf("ondemand queue: added uuid=%s to stream=%d (go_viewers=%d)", uuid, streamID, count)
}

// RemoveFromQueue logs that a viewer left an on-demand stream.
// The actual stream shutdown is handled by the PHP XC_VM[Ondemand] daemon
// which monitors lines_live viewer counts.
func (o *OnDemandStarter) RemoveFromQueue(streamID int, uuid string) {
	if o.instantOff == 0 {
		return
	}
	o.queueMu.Lock()
	uuids := o.queue[streamID]
	if uuids != nil {
		delete(uuids, uuid)
	}
	remaining := len(uuids)
	if remaining == 0 {
		delete(o.queue, streamID)
	}
	o.queueMu.Unlock()

	log.Printf("ondemand queue: removed uuid=%s from stream=%d (go_viewers=%d)", uuid, streamID, remaining)
	// PHP XC_VM[Ondemand] daemon checks lines_live and handles the kill
}

// fileExists is a simple helper.
func fileExists(path string) bool {
	_, err := os.Stat(path)
	return err == nil
}
