package main

import (
	"flag"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

var (
	listenAddr   = flag.String("listen", "127.0.0.1:8089", "HTTP listen address")
	unixSocket   = flag.String("socket", "", "Unix socket path (overrides -listen)")
	streamsPath  = flag.String("streams", "", "Path to streams tmpfs (default: XC_HOME/content/streams/)")
	cacheSize    = flag.Int("cache-segments", 15, "Max segments cached per stream")
	cacheTTL     = flag.Duration("cache-ttl", 30*time.Second, "Segment cache TTL")
	pidFile      = flag.String("pidfile", "", "PID file (default: XC_HOME/bin/xc_ts_server/ts_server.pid)")
	readBufSize  = flag.Int("read-buffer", 188*128, "Read buffer size (bytes)")
	prebufferMax = flag.Int("prebuffer-max", 60, "Max prebuffer seconds")

	// MariaDB tracking
	dbDSN        = flag.String("db-dsn", "", "MariaDB DSN (auto-detected from go_db.conf if empty)")
	dbTimeOffset = flag.Int("db-time-offset", 0, "Server time_offset for lines_live")
	heartbeatSec = flag.Int("heartbeat-sec", 60, "Heartbeat interval in seconds")

	// Native auth (replaces PHP entirely on MAIN and LB)
	liveStreamingPass = flag.String("live-streaming-pass", "", "XC_VM live_streaming_pass (auto-detected from DB if empty)")
	opensslExtra      = flag.String("openssl-extra", "", "XC_VM OPENSSL_EXTRA (auto-detected from config/openssl_extra if empty)")
	serverID          = flag.Int("server-id", 0, "Server ID (auto-detected from PHP if 0)")

	// Redis (when redis_handler=1 in XC_VM settings)
	redisAddr = flag.String("redis-addr", "", "Redis address host:port (auto-detected from DB if empty)")
	redisPass = flag.String("redis-pass", "", "Redis password (auto-detected from DB if empty)")
	redisDB   = flag.Int("redis-db", 0, "Redis database number")

	// XC_VM paths for PHP parity
	signalsPath    = flag.String("signals-path", "", "Path to admin signal files (default: XC_HOME/signals/)")
	consTmpPath    = flag.String("cons-tmp-path", "", "Path to connection touch files (default: XC_HOME/tmp/opened_cons/)")
	divergencePath = flag.String("divergence-path", "", "Path to divergence files (default: XC_HOME/tmp/divergence/)")

	// On-demand stream start (PHP binary for console.php)
	phpBin   = flag.String("php-bin", "", "Path to PHP binary (default: XC_HOME/bin/php/bin/php)")
	mainHome = flag.String("main-home", "/home/xc_vm/", "XC_VM main home directory")

	// VOD path
	vodPath = flag.String("vod-path", "", "Path to VOD files (default: XC_HOME/content/vod/)")

	// Auto-config: write DB credentials file
	writeDBConf = flag.Bool("write-db-conf", false, "Write go_db.conf and exit")
	dbConfUser  = flag.String("db-user", "", "DB user for -write-db-conf")
	dbConfPass  = flag.String("db-pass", "", "DB password for -write-db-conf")
	dbConfHost  = flag.String("db-host", "", "DB host for -write-db-conf")
	dbConfPort  = flag.String("db-port", "3306", "DB port for -write-db-conf")
	dbConfName  = flag.String("db-name", "xc_vm", "DB name for -write-db-conf")
)

func main() {
	flag.Parse()

	xcHome := *mainHome
	if !strings.HasSuffix(xcHome, "/") {
		xcHome += "/"
	}

	// Handle -write-db-conf: write go_db.conf and exit
	if *writeDBConf {
		if *dbConfUser == "" || *dbConfPass == "" || *dbConfHost == "" {
			log.Fatal("-write-db-conf requires -db-user, -db-pass, -db-host")
		}
		if err := writeGoDBConf(xcHome, *dbConfUser, *dbConfPass, *dbConfHost, *dbConfPort, *dbConfName); err != nil {
			log.Fatalf("write-db-conf: %v", err)
		}
		return
	}

	// ── Auto-config: fill empty flags from XC_VM installation ──
	// Resolve default paths relative to XC_HOME
	if *streamsPath == "" {
		*streamsPath = xcHome + "content/streams/"
	}
	if *pidFile == "" {
		*pidFile = xcHome + "bin/xc_ts_server/ts_server.pid"
	}
	if *signalsPath == "" {
		*signalsPath = xcHome + "signals/"
	}
	if *consTmpPath == "" {
		*consTmpPath = xcHome + "tmp/opened_cons/"
	}
	if *divergencePath == "" {
		*divergencePath = xcHome + "tmp/divergence/"
	}
	if *phpBin == "" {
		*phpBin = xcHome + "bin/php/bin/php"
	}
	if *vodPath == "" {
		*vodPath = xcHome + "content/vod/"
	}

	// Auto-detect DSN, OPENSSL_EXTRA, SERVER_ID from XC_VM files
	if *dbDSN == "" || *opensslExtra == "" || *serverID == 0 {
		ac, err := loadAutoConfig(xcHome, *phpBin)
		if err != nil {
			log.Printf("autoconfig warning: %v", err)
		}
		if ac != nil {
			if *dbDSN == "" && ac.DBDSN != "" {
				*dbDSN = ac.DBDSN
			}
			if *opensslExtra == "" && ac.OpensslExtra != "" {
				*opensslExtra = ac.OpensslExtra
			}
			if *serverID == 0 && ac.ServerID != 0 {
				*serverID = ac.ServerID
			}
		}
	}

	// Write PID file
	if *pidFile != "" {
		os.WriteFile(*pidFile, []byte(fmt.Sprintf("%d", os.Getpid())), 0644)
		defer os.Remove(*pidFile)
	}

	// Initialize tracker (MariaDB heartbeat)
	var tracker *Tracker
	if *dbDSN != "" {
		var err error
		tracker, err = NewTracker(*dbDSN, *dbTimeOffset, time.Duration(*heartbeatSec)*time.Second)
		if err != nil {
			log.Printf("WARNING: tracker init failed (no DB heartbeat): %v", err)
		} else {
			log.Printf("tracker connected to MariaDB (heartbeat every %ds)", *heartbeatSec)
			defer tracker.Stop()
		}
	} else {
		log.Println("WARNING: no -db-dsn provided, tracking disabled")
	}

	// Load XC_VM settings from DB (prebuffer, seg_time, create_expiration, etc.)
	var xcSettings *XCSettings
	if tracker != nil {
		var err error
		xcSettings, err = LoadSettings(tracker.GetDB())
		if err != nil {
			log.Printf("WARNING: failed to load XC_VM settings from DB: %v", err)
		} else {
			log.Printf("XC_VM settings loaded: client_prebuffer=%d restreamer_prebuffer=%d seg_time=%d create_expiration=%d disallow_2nd_ip=%d restrict_same_ip=%d use_buffer=%d seg_wait=%d redis_handler=%d on_demand_wait=%d",
				xcSettings.ClientPrebuffer, xcSettings.RestreamerPrebuffer, xcSettings.SegTime, xcSettings.CreateExpiration,
				xcSettings.Disallow2ndIPCon, xcSettings.RestrictSameIP, xcSettings.UseBuffer, xcSettings.SegmentWaitTime,
				xcSettings.RedisHandler, xcSettings.OnDemandWaitTime)

			// Auto-detect live_streaming_pass from DB if not provided via flag
			if *liveStreamingPass == "" && xcSettings.LiveStreamingPass != "" {
				*liveStreamingPass = xcSettings.LiveStreamingPass
				log.Println("autoconfig: live_streaming_pass loaded from DB settings")
			}
		}
	}

	// Connect to Redis when redis_handler=1
	if xcSettings != nil && xcSettings.RedisHandler == 1 && tracker != nil {
		addr := *redisAddr
		pass := *redisPass
		// Auto-detect from DB settings if not provided via flags
		if addr == "" {
			// Redis typically runs on Main server. Extract host from DB DSN.
			if *dbDSN != "" {
				// DSN format: user:pass@tcp(host:port)/db
				if start := strings.Index(*dbDSN, "tcp("); start >= 0 {
					end := strings.Index((*dbDSN)[start:], ")")
					if end > 0 {
						hostPort := (*dbDSN)[start+4 : start+end]
						// Replace MySQL port with Redis default port
						if colonIdx := strings.LastIndex(hostPort, ":"); colonIdx >= 0 {
							addr = hostPort[:colonIdx] + ":6379"
						} else {
							addr = hostPort + ":6379"
						}
					}
				}
			}
		}
		if pass == "" && xcSettings.RedisPassword != "" {
			pass = xcSettings.RedisPassword
		}
		if addr != "" {
			rt, err := NewRedisTracker(addr, pass, *redisDB)
			if err != nil {
				log.Printf("WARNING: Redis connection failed (falling back to MySQL-only): %v", err)
			} else {
				tracker.AttachRedis(rt)
				log.Printf("Redis connected at %s (dual-mode: Redis+MariaDB)", addr)
			}
		} else {
			log.Println("WARNING: redis_handler=1 but no Redis address available")
		}
	}

	// Use DB settings or sensible defaults
	segTime := 10
	clientPrebuffer := 30
	restrPrebuffer := 0
	createExpiration := 15
	segWaitTime := 20
	if xcSettings != nil {
		segTime = xcSettings.SegTime
		clientPrebuffer = xcSettings.ClientPrebuffer
		restrPrebuffer = xcSettings.RestreamerPrebuffer
		createExpiration = xcSettings.CreateExpiration
		segWaitTime = xcSettings.SegmentWaitTime
		if segWaitTime <= 0 {
			segWaitTime = 20
		}
	}

	// Configure tracker with settings
	if xcSettings != nil && tracker != nil {
		tracker.SetIPSubnetMatch(xcSettings.IPSubnetMatch)
	}
	// Activity logging: write closed-connection records for XC_VM activity processor
	if tracker != nil {
		logDir := filepath.Dir(*consTmpPath) // /home/xc_vm/tmp
		if logDir != "" && logDir != "." {
			tracker.SetActivityLogDir(logDir)
			log.Printf("activity logging enabled: %s", logDir)
		}
		tracker.SetConsTmpPath(*consTmpPath)
		tracker.SetServerID(*serverID)
	}

	// Initialize on-demand stream starter
	onDemandWaitTime := 60
	onDemandInstantOff := 0
	if xcSettings != nil && xcSettings.OnDemandWaitTime > 0 {
		onDemandWaitTime = xcSettings.OnDemandWaitTime
	}
	if xcSettings != nil {
		onDemandInstantOff = xcSettings.OnDemandInstantOff
	}
	signalsTmpPath := filepath.Join(filepath.Dir(*consTmpPath), "signals") + "/"
	var onDemand *OnDemandStarter
	if *phpBin != "" && *mainHome != "" {
		onDemand = NewOnDemandStarter(*streamsPath, *phpBin, *mainHome, onDemandWaitTime, *serverID, onDemandInstantOff, signalsTmpPath)
		log.Printf("on-demand starter enabled: php=%s home=%s wait=%ds instant_off=%d", *phpBin, *mainHome, onDemandWaitTime, onDemandInstantOff)
	}

	// Initialize signal poller (admin kills for pid=0 Go-served connections)
	var signalPoller *SignalPoller
	if tracker != nil {
		signalPoller = NewSignalPoller(tracker.GetDB(), tracker, *serverID, *consTmpPath)
		if tracker.RedisEnabled() {
			signalPoller.AttachRedis(tracker.GetRedis())
		}
		signalPoller.Start()
		defer signalPoller.Stop()
	}

	// Initialize cache and watcher
	cache := NewSegmentCache(*cacheSize, *cacheTTL)
	watcher := NewStreamWatcher(*streamsPath)
	go watcher.Run()
	defer watcher.Stop()

	handler := NewTSHandler(cache, watcher, tracker, *streamsPath,
		*readBufSize, *prebufferMax, segTime, segWaitTime,
		*signalsPath, *consTmpPath, *divergencePath)

	mux := http.NewServeMux()

	// Legacy internal route: /ts/<stream_id> (via X-Accel-Redirect from PHP)
	mux.HandleFunc("/ts/", handler.ServeLive)

	// HLS internal route: /hls_playlist/<stream_id>?uuid=<uuid> (via X-Accel from PHP)
	mux.HandleFunc("/hls_playlist/", handler.ServeHLSPlaylist)

	// VOD internal route: /vod_serve/<stream_id>?uuid=<uuid>&ext=<ext> (via X-Accel from PHP)
	vodHandler := NewVODHandler(tracker, *vodPath, *consTmpPath, *divergencePath)
	mux.HandleFunc("/vod_serve/", vodHandler.ServeVOD)

	// Native auth route: /auth/<token> (replaces PHP entirely)
	if *liveStreamingPass != "" && *opensslExtra != "" {
		decryptor := NewTokenDecryptor(*liveStreamingPass, *opensslExtra)
		authHandler := NewAuthHandler(
			decryptor, handler, tracker,
			*serverID, *dbTimeOffset, clientPrebuffer, restrPrebuffer,
			segTime, createExpiration, xcSettings, onDemand,
		)

		// HLS segment handler: /auth/seg/<file>?uuid=<uuid>
		// Must be registered BEFORE /auth/ (Go mux longest-prefix match handles it)
		restrictSameIP := 0
		ipSubnetMatch := 0
		if xcSettings != nil {
			restrictSameIP = xcSettings.RestrictSameIP
			ipSubnetMatch = xcSettings.IPSubnetMatch
		}
		hlsHandler := NewHLSHandler(tracker, *streamsPath, *consTmpPath, restrictSameIP, ipSubnetMatch)
		mux.HandleFunc("/auth/seg/", hlsHandler.ServeSegment)

		mux.HandleFunc("/auth/", authHandler.ServeAuth)
		log.Printf("native auth enabled: server_id=%d client_prebuffer=%ds restreamer_prebuffer=%ds hls=true",
			*serverID, clientPrebuffer, restrPrebuffer)
	} else {
		log.Println("native auth disabled (no -live-streaming-pass or -openssl-extra)")
	}

	// Health endpoint
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		trackerActive := tracker != nil
		trackedViewers := 0
		redisActive := false
		if tracker != nil {
			trackedViewers = tracker.ActiveCount()
			redisActive = tracker.RedisEnabled()
		}
		authEnabled := *liveStreamingPass != "" && *opensslExtra != ""
		onDemandEnabled := onDemand != nil
		signalPollerActive := signalPoller != nil
		fmt.Fprintf(w, `{"ok":true,"streams":%d,"cached_segments":%d,"active_viewers":%d,"tracked_viewers":%d,"tracker_active":%v,"auth_native":%v,"hls_native":%v,"vod_native":%v,"redis_active":%v,"on_demand_enabled":%v,"on_demand_instant_off":%d,"signal_poller":%v,"client_prebuffer":%d,"restreamer_prebuffer":%d,"seg_time":%d,"seg_wait_time":%d,"on_demand_wait_time":%d}`,
			cache.StreamCount(), cache.TotalSegments(), handler.ActiveViewers(), trackedViewers, trackerActive, authEnabled, authEnabled, true, redisActive,
			onDemandEnabled, onDemandInstantOff, signalPollerActive,
			clientPrebuffer, restrPrebuffer, segTime, segWaitTime, onDemandWaitTime)
	})

	var listener net.Listener
	var err error

	if *unixSocket != "" {
		os.Remove(*unixSocket)
		listener, err = net.Listen("unix", *unixSocket)
		if err != nil {
			log.Fatalf("Listen unix %s: %v", *unixSocket, err)
		}
		os.Chmod(*unixSocket, 0770)
		log.Printf("xc_ts_server listening on unix:%s", *unixSocket)
	} else {
		listener, err = net.Listen("tcp", *listenAddr)
		if err != nil {
			log.Fatalf("Listen tcp %s: %v", *listenAddr, err)
		}
		log.Printf("xc_ts_server listening on %s", *listenAddr)
	}

	server := &http.Server{
		Handler:      mux,
		ReadTimeout:  10 * time.Second,
		WriteTimeout: 0, // long-lived TS streams
		IdleTimeout:  60 * time.Second,
	}

	// Graceful shutdown
	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)
	go func() {
		sig := <-sigCh
		log.Printf("received %v, shutting down...", sig)
		server.Close()
	}()

	log.Printf("xc_ts_server ready: streams=%s seg_time=%ds prebuffer_max=%ds cache=%d×%s server_id=%d signals=%s cons_tmp=%s on_demand_wait=%ds",
		*streamsPath, segTime, *prebufferMax, *cacheSize, *cacheTTL, *serverID, *signalsPath, *consTmpPath, onDemandWaitTime)

	if err := server.Serve(listener); err != nil && err != http.ErrServerClosed {
		log.Fatalf("Server error: %v", err)
	}
	log.Println("xc_ts_server stopped")
}
