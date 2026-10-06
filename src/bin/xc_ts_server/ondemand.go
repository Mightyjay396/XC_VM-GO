package main

import (
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
	instantOff  int    // on_demand_instant_off from settings

	// Per-stream locks to prevent concurrent starts in Go
	mu      sync.Mutex
	pending map[int]chan struct{} // stream_id → wait channel

	// On-demand viewer tracking (monitoring only — PHP daemon handles kill)
	queueMu sync.Mutex
	queue   map[int]map[string]bool // stream_id → set of UUIDs
}

func NewOnDemandStarter(streamsPath, phpBin, mainHome string, waitTime, serverID, instantOff int, signalsTmpPath string) *OnDemandStarter {
	if waitTime <= 0 {
		waitTime = 60
	}
	return &OnDemandStarter{
		streamsPath: streamsPath,
		phpBin:      phpBin,
		mainHome:    mainHome,
		waitTime:    waitTime,
		serverID:    serverID,
		instantOff:  instantOff,
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

func (o *OnDemandStarter) doStart(streamID int, mode StartMode) StartResult {
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
			log.Printf("ondemand: stream=%d monitor pid not created", streamID)
			return StartResult{OK: false, Error: "not_on_air"}
		}
	}

	// Wait for producer PID file (on_demand_wait_time)
	pidWait := time.Duration(o.waitTime) * time.Second
	log.Printf("ondemand: stream=%d waiting up to %s for producer pid", streamID, pidWait)
	if !o.awaitFile(pidFile, pidWait, 50*time.Millisecond) {
		log.Printf("ondemand: stream=%d producer pid not created in %s", streamID, pidWait)
		return StartResult{OK: false, Error: "not_on_air"}
	}

	producerPid := o.readPidFile(pidFile)
	log.Printf("ondemand: stream=%d producer pid=%d", streamID, producerPid)

	if producerPid <= 0 || !o.processAlive(producerPid) {
		log.Printf("ondemand: stream=%d producer not alive", streamID)
		return StartResult{OK: false, Error: "not_on_air"}
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
