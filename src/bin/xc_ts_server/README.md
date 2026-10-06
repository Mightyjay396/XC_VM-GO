# xc_ts_server — Go TS/HLS/VOD Delivery Server

High-performance Go replacement for PHP stream delivery in XC_VM.
Handles MPEG-TS live streaming, HLS playlist generation, and VOD file serving
with native authentication, MariaDB connection tracking, and on-demand stream management.

## Features

- **Native Auth**: Decrypts XC_VM tokens, validates users, enforces connection limits — replaces PHP auth entirely
- **MPEG-TS Delivery**: Chase-read loop serving live TS segments with prebuffering, divergence tracking, and signal handling
- **HLS Playlist**: Generates tokenized m3u8 playlists with rewritten segment URLs pointing to Go
- **HLS Segments**: Serves individual `.ts`/`.enc` segments with UUID validation
- **VOD File Serving**: HTTP Range support (206/416), seek, Content-Type mapping, heartbeat
- **Connection Tracking**: MariaDB `lines_live` heartbeat, `opened_cons` touch files, activity logging
- **On-Demand**: File-locked stream start via PHP `console.php monitor`, stale lock cleanup
- **Signal Polling**: Admin kill/drop signals for Go-served connections (pid=0)
- **Enforcement**: Max connections, IP restrictions, cross-server eviction via signals table
- **Redis Support**: Optional dual-mode (Redis+MariaDB) when `redis_handler=1`

## Architecture

```
Client → Nginx → Go xc_ts_server (127.0.0.1:8089)
                      ├── /auth/<token>           Native auth (TS + HLS)
                      ├── /auth/seg/<file>        HLS segment delivery
                      ├── /ts/<stream_id>         Internal TS (X-Accel from PHP)
                      ├── /hls_playlist/<id>      Internal HLS (X-Accel from PHP)
                      ├── /vod_serve/<id>         Internal VOD (X-Accel from PHP)
                      └── /health                 Health check
```

### Two Integration Modes

**Mode A — Native Auth** (recommended for LB nodes):
- Nginx routes `/auth/<token>` directly to Go
- Go handles everything: auth, tracking, enforcement, delivery
- PHP is completely bypassed for live streams

**Mode B — PHP Auth + Go Delivery**:
- Nginx routes to PHP as usual
- PHP handles auth, creates `lines_live`, runs enforcement
- PHP sends `X-Accel-Redirect` to Go's internal routes for byte delivery
- Go handles efficient file serving (TS chase-read, VOD Range)

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

All configuration is via command-line flags. The startup script `xc_ts_server.sh`
reads from environment variables for sensitive values.

### Required Environment Variables

Set these before running `xc_ts_server.sh`:

```bash
export XC_TS_DB_DSN="user:pass@tcp(host:port)/dbname"
export XC_TS_LIVE_PASS="your_live_streaming_pass"
export XC_TS_OPENSSL_EXTRA="your_openssl_extra_constant"
export XC_TS_SERVER_ID=5
```

### Command-Line Flags

| Flag | Default | Description |
|------|---------|-------------|
| `-listen` | `127.0.0.1:8089` | HTTP listen address |
| `-socket` | _(empty)_ | Unix socket path (overrides -listen) |
| `-streams` | `/home/xc_vm/content/streams/` | Path to streams tmpfs |
| `-vod-path` | `/home/xc_vm/content/vod/` | Path to VOD files |
| `-db-dsn` | _(required)_ | MariaDB DSN |
| `-server-id` | `0` | This server's XC_VM server ID |
| `-live-streaming-pass` | _(empty)_ | Enables native auth |
| `-openssl-extra` | _(empty)_ | XC_VM OPENSSL_EXTRA constant |
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

See `lb_configs/go_ts_server.conf` for the nginx configuration snippet.

### Quick Setup

1. Add to your nginx config:
```nginx
include go_ts_server.conf;
```

2. For **Mode A** (native auth), uncomment the `/auth/` location blocks in the config.

3. For **Mode B** (PHP handoff), the internal locations are already active. Apply the
   PHP patches to `live.php` and `vod.php` (see below).

4. `nginx -t && nginx -s reload`

## PHP Integration (Mode B)

The modified `live.php` and `vod.php` include Go handoff logic:

- **live.php**: After PHP auth/enforcement, if Go is running (PID file exists),
  sends `X-Accel-Redirect` to Go for TS delivery or HLS playlist generation
- **vod.php**: After PHP auth/enforcement, if Go is running,
  sends `X-Accel-Redirect` to Go for file serving with Range support

The handoff is automatic when the Go server is running and transparent
when it's not (PHP falls back to its own delivery).

## File Structure

```
src/bin/xc_ts_server/
├── main.go          # Entry point, flags, route setup, health
├── auth.go          # Token decryption, native auth handler
├── handler.go       # TS chase-read delivery, HLS playlist internal
├── hls.go           # HLS segment handler
├── vod.go           # VOD file serving with Range
├── tracker.go       # MariaDB lines_live tracking, heartbeat, enforcement
├── ondemand.go      # On-demand stream start via PHP console.php
├── signals.go       # Admin signal polling (kill/drop)
├── cache.go         # In-memory segment cache
├── watcher.go       # Filesystem segment watcher
├── redis.go         # Optional Redis integration
├── igbinary.go      # PHP igbinary format decoder (Redis compat)
├── flock.go         # File locking for on-demand
├── go.mod / go.sum  # Go module dependencies
├── xc_ts_server.sh  # Startup/stop script
└── README.md        # This file
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
    "on_demand_instant_off": 1,
    "signal_poller": true,
    "client_prebuffer": 30,
    "restreamer_prebuffer": 30,
    "seg_time": 10,
    "seg_wait_time": 45,
    "on_demand_wait_time": 60
}
```

## Dependencies

- Go 1.22+
- `github.com/go-sql-driver/mysql` — MariaDB driver
- `github.com/redis/go-redis/v9` — Redis client (optional)
- `golang.org/x/sys` — Syscall extensions

## License

AGPL-3.0 — same as XC_VM
