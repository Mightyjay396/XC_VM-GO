# xc_ts_server — Go TS/HLS/VOD Delivery Server

High-performance Go replacement for the full PHP live stream pipeline in XC_VM.
Go handles token decryption, authentication, connection tracking, enforcement,
on-demand start, and stream delivery (TS/HLS). PHP is the automatic fallback
when Go is not running.

Runs on all nodes (MAIN and LBs). VOD uses PHP auth with Go file serving.

## Features

- **Full Auth Pipeline**: Decrypts XC_VM tokens (AES-256-GCM/CBC), validates users, enforces connection limits, tracks connections in MariaDB — replaces PHP entirely for live streams
- **Automatic PHP Fallback**: If Go is down, nginx falls back to PHP via `@go_auth_fallback` — zero manual intervention
- **MPEG-TS Delivery**: Chase-read loop serving live TS segments with prebuffering, divergence tracking, and signal handling
- **HLS Playlist**: Generates tokenized m3u8 playlists with rewritten segment URLs pointing to Go
- **HLS Segments**: Serves individual `.ts`/`.enc` segments with UUID validation
- **VOD File Serving**: HTTP Range support (206/416), seek, Content-Type mapping, heartbeat (PHP auth → Go delivery)
- **Connection Tracking**: MariaDB `lines_live` heartbeat, `opened_cons` touch files, activity logging
- **On-Demand**: File-locked stream start via PHP `console.php monitor`, stale lock cleanup
- **Signal Polling**: Admin kill/drop signals for Go-served connections (pid=0)
- **Enforcement**: Max connections (regular/HMAC/pair_id), IP restrictions, cross-server eviction
- **Redis Support**: Optional dual-mode (Redis+MariaDB) when `redis_handler=1`

## Architecture

```
Live TS/HLS (Go is primary):
  Client → Nginx → Go :8089 /auth/<token>
                      ├── Decrypt token, auth, tracking, enforcement
                      ├── On-demand start if needed
                      ├── Serve TS or HLS
                      └── Heartbeat + cleanup on disconnect

  If Go is down (502/503/504):
  Client → Nginx → Go (down) → @go_auth_fallback
                                └── rewrite → PHP live.php (full pipeline)

VOD (PHP auth + Go delivery):
  Client → Nginx → PHP vod.php
                      ├── Auth + enforcement
                      └── X-Accel → Go /vod_serve/ (Range/seek)
```

### Routes

| Route | Handler | Description |
|-------|---------|-------------|
| `/auth/<token>` | Go primary, PHP fallback | Live TS/HLS — full pipeline |
| `/auth/seg/<file>` | Go | HLS segment delivery |
| `/ts/<stream_id>` | Go (internal X-Accel) | TS delivery (secondary path from live.php) |
| `/hls_playlist/<id>` | Go (internal X-Accel) | HLS playlist (secondary path from live.php) |
| `/vod_serve/<id>` | Go (internal X-Accel) | VOD file serving (from vod.php) |
| `/health` | Go | Health check endpoint |

## Building

```bash
cd src/bin/xc_ts_server
go build -o xc_ts_server .
```

Cross-compile for your server:
```bash
GOOS=linux GOARCH=amd64 go build -o xc_ts_server .
```

## Configuration

All configuration is via command-line flags. The startup script `run.sh`
reads credentials automatically from XC_VM's `config/config.ini` and
`config/openssl_extra`.

### Command-Line Flags

| Flag | Default | Description |
|------|---------|-------------|
| `-listen` | `127.0.0.1:8089` | HTTP listen address |
| `-socket` | _(empty)_ | Unix socket path (overrides -listen) |
| `-streams` | `/home/xc_vm/content/streams/` | Path to streams tmpfs |
| `-vod-path` | `/home/xc_vm/content/vod/` | Path to VOD files |
| `-db-dsn` | _(required)_ | MariaDB DSN |
| `-server-id` | `0` | This server's XC_VM server ID |
| `-live-streaming-pass` | _(required for auth)_ | XC_VM `live_streaming_pass` from settings |
| `-openssl-extra` | _(required for auth)_ | XC_VM OPENSSL_EXTRA constant |
| `-php-bin` | `/home/xc_vm/bin/php/bin/php` | PHP binary for on-demand |
| `-main-home` | `/home/xc_vm/` | XC_VM home directory |
| `-signals-path` | `/home/xc_vm/signals/` | Admin signal files |
| `-cons-tmp-path` | `/home/xc_vm/tmp/opened_cons/` | Connection touch files |
| `-divergence-path` | `/home/xc_vm/tmp/divergence/` | Speed tracking files |
| `-heartbeat-sec` | `60` | DB heartbeat interval |
| `-cache-segments` | `15` | Max cached segments per stream |
| `-cache-ttl` | `30s` | Segment cache TTL |
| `-read-buffer` | `24064` | Read buffer size (bytes) |
| `-prebuffer-max` | `60` | Max prebuffer seconds |

Settings like `client_prebuffer`, `seg_time`, `create_expiration`, etc. are
automatically read from the XC_VM `settings` table in MariaDB at startup.

## Nginx Integration

See `lb_configs/go_ts_server.conf` for the complete nginx config.

### Setup (2 steps)

1. **Comment out** the `/auth/` rewrite in your nginx.conf:
   ```nginx
   # rewrite ^/auth/(?<token>[^/]*)$ /stream/live?token=$token break;
   ```

2. **Include** the Go config (already done in the modified nginx.conf):
   ```nginx
   include go_ts_server.conf;
   ```

3. `nginx -t && nginx -s reload`

The `/auth/` rewrite MUST be commented out — otherwise nginx rewrites the
URL to `/stream/live` before Go's location block can match it.

## PHP Fallback

When Go is running: all `/auth/<token>` requests go to Go.
When Go is stopped: nginx gets 502, triggers `@go_auth_fallback`, which
rewrites to `/stream/live?token=...` → PHP handles everything as before.

The fallback is fully automatic. No config changes needed.

For VOD: `vod.php` always checks `goTsServerAvailable()`. If Go is down,
PHP serves the file itself. If Go is up, PHP X-Accel-Redirects to Go.

## Service Management

**Automatic (via XC_VM service):**
```bash
service xc_vm start    # Starts Go via run.sh (reads config.ini)
service xc_vm stop     # Stops all services including Go
```

**Manual:**
```bash
./xc_ts_server.sh start|stop|restart|status
```

**Disable Go without uninstalling:**
```bash
touch /home/xc_vm/bin/xc_ts_server/disabled
# Go's run.sh will exit. PHP fallback activates automatically.
# Remove the file to re-enable.
```

## Health Check

```bash
curl http://127.0.0.1:8089/health | python3 -m json.tool
```

Response:
```json
{
    "ok": true,
    "streams": 5,
    "cached_segments": 42,
    "active_viewers": 12,
    "tracked_viewers": 12,
    "tracker_active": true,
    "auth_native": true,
    "hls_native": true,
    "vod_native": true,
    "redis_active": false,
    "on_demand_enabled": true,
    "signal_poller": true
}
```

## File Structure

```
src/bin/xc_ts_server/
├── main.go          # Entry point, flags, route setup, health
├── auth.go          # Token decryption, full auth pipeline
├── handler.go       # TS chase-read delivery, internal HLS
├── hls.go           # HLS segment handler
├── vod.go           # VOD file serving with Range
├── tracker.go       # MariaDB lines_live tracking, enforcement
├── ondemand.go      # On-demand stream start via console.php
├── signals.go       # Admin signal polling (kill/drop)
├── cache.go         # In-memory segment cache
├── watcher.go       # Filesystem segment watcher (inotify)
├── redis.go         # Optional Redis integration
├── igbinary.go      # PHP igbinary format decoder (Redis compat)
├── flock.go         # File locking for on-demand
├── go.mod / go.sum  # Go module dependencies
├── run.sh           # Keepalive supervisor (reads config.ini)
├── xc_ts_server.sh  # Manual start/stop script
└── README.md        # This file
```

## Dependencies

- Go 1.22+
- `github.com/go-sql-driver/mysql` — MariaDB driver
- `github.com/redis/go-redis/v9` — Redis client (optional)
- `golang.org/x/sys` — Syscall extensions

## License

AGPL-3.0 — same as XC_VM
