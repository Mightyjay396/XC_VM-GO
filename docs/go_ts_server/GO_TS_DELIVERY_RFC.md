# Go TS/HLS/VOD Delivery Server — XC_VM Integration

## Problem

On any XC_VM node (MAIN or LB), the PHP delivery loops in `live.php` and
`vod.php` hold one PHP-FPM worker for the entire duration of every viewer
session. A worker that reads TS packets in a tight loop or serves a VOD
file cannot serve other requests, so the maximum number of concurrent
viewers equals the PHP-FPM `pm.max_children` setting.

## Solution

A small, statically linked Go HTTP server (`xc_ts_server`) replaces the
PHP delivery loops for **all three stream types**:

- **MPEG-TS** (live): chase-read loop with prebuffering and segment cache
- **HLS** (live): m3u8 playlist generation with rewritten segment URLs
- **VOD**: file serving with native HTTP Range/seek support

PHP still handles token decryption, authentication,
`ConnectionTracker::createLive()`, and all validation — then it hands the
response body to Go via `X-Accel-Redirect` and returns immediately (~100 ms
instead of minutes/hours). When the Go server is not running, PHP falls
back to its own delivery — zero risk of breaking existing installs.

### Architecture

```
Client → Cloudflare → MAIN/LB Nginx
  ┌──────────────────────────────────────────────┐
  │  /auth/<token>  →  live.php (TS/HLS)         │
  │    • decrypt token, validate user             │
  │    • createLive() in lines_live               │
  │    • enforcement (max connections, IP, etc.)   │
  │    • X-Accel-Redirect → /xc_ts_go/<stream>    │  ← TS
  │    • X-Accel-Redirect → /xc_hls_go/<stream>   │  ← HLS
  │    • return (PHP freed)                        │
  └──────────────────────────────────────────────┘
  ┌──────────────────────────────────────────────┐
  │  /vauth/<token>  →  vod.php                  │
  │    • decrypt token, validate user             │
  │    • createLive() in lines_live               │
  │    • enforcement (max connections, IP, etc.)   │
  │    • X-Accel-Redirect → /xc_vod_go/<stream>   │
  │    • return (PHP freed)                        │
  └──────────────────────────────────────────────┘
  ┌──────────────────────────────────────────────┐
  │  Nginx internal redirect → Go :8089           │
  │    TS:                                        │
  │    • in-memory segment cache (inotify)        │
  │    • prebuffer N segments for instant start   │
  │    • chase-read: stream TS as ffmpeg writes   │
  │    HLS:                                       │
  │    • read on-disk m3u8 playlist               │
  │    • rewrite segment URLs to Go endpoints     │
  │    • serve segments via /auth/seg/<file>       │
  │    VOD:                                       │
  │    • serve file with HTTP Range (206/416)     │
  │    • Content-Type mapping, seek support       │
  │    ALL:                                       │
  │    • MariaDB heartbeat every 15 s             │
  │    • on disconnect: SET hls_end=1             │
  └──────────────────────────────────────────────┘
```

### Key Design Decisions

1. **`$rCloseCon = false`** — PHP's `ShutdownHandler::handle('live')` checks
   this flag.  When PHP's role is only authentication, the handler must not
   close the connection in `lines_live` on exit — the Go server manages the
   lifecycle instead.

2. **`pid = 0`** — The cron reaper (`UsersCronJob`) skips connections with
   `pid = 0` ("Daemon-served, ADR 0003 Phase C").  The Go server sets
   `pid = 0` on its first heartbeat so the reaper treats the connection like a
   daemon-fed stream.

3. **`goTsServerAvailable()`** — PHP guard function that checks PID file +
   `/proc/<pid>` to detect if the Go server is actually running. Falls back
   to PHP delivery transparently when Go is not running.

4. **15-second heartbeat interval** — The cron reaper runs every 60 s.  A 15 s
   heartbeat ensures `hls_last_read` is always fresh when the reaper inspects
   the row, and `hls_end` is restored well before the next cycle.

### Nginx Configuration (MAIN and LB)

See `lb_configs/go_ts_server.conf` for the full config. Both `src/bin/nginx/conf/nginx.conf`
(MAIN) and `lb_configs/nginx.conf` (LB) include it via:

```nginx
include go_ts_server.conf;
```

The config provides internal X-Accel locations:

```nginx
# TS delivery
location /xc_ts_go/ {
    internal;
    rewrite ^/xc_ts_go/(.*)$ /ts/$1 break;
    proxy_pass http://127.0.0.1:8089;
    ...
}

# HLS playlist
location /xc_hls_go/ {
    internal;
    rewrite ^/xc_hls_go/(.*)$ /hls_playlist/$1 break;
    proxy_pass http://127.0.0.1:8089;
    ...
}

# VOD file serving
location /xc_vod_go/ {
    internal;
    rewrite ^/xc_vod_go/(.*)$ /vod_serve/$1 break;
    proxy_pass http://127.0.0.1:8089;
    ...
}
```

### Go Server Features

| Feature | TS | HLS | VOD |
|---------|:--:|:---:|:---:|
| **Segment cache** (in-memory LRU, inotify) | Yes | — | — |
| **Chase-read** (stream while ffmpeg writes) | Yes | — | — |
| **Prebuffer** (N recent segments) | Yes | — | — |
| **Playlist rewriting** (tokenized URLs) | — | Yes | — |
| **HTTP Range** (206/416, seek) | — | — | Yes |
| **Content-Type mapping** | Yes | Yes | Yes |
| **MariaDB heartbeat** (15s) | Yes | Yes | Yes |
| **On-demand start** (flock + monitor) | Yes | Yes | — |
| **Signal polling** (admin kill/drop) | Yes | Yes | Yes |
| **Connection enforcement** | Yes | Yes | Yes |
| **Graceful shutdown** (SIGTERM) | Yes | Yes | Yes |

### PHP Changes

**`live.php`**: Two X-Accel-Redirect blocks added (TS and HLS), guarded by
`goTsServerAvailable()`. Falls back to PHP delivery when Go is not running.

**`vod.php`**: One X-Accel-Redirect block added (VOD), guarded by
`goTsServerAvailable()`. Direct-proxy VOD (remote cURL) stays in PHP.

### Performance Characteristics

- **PHP-FPM workers freed**: each viewer consumes ~100 ms of PHP time instead
  of holding a worker for the session duration
- **Memory**: Go uses ~50 KB per viewer + cached segments (~7 MB × 15 per stream)
- **Latency**: inotify-based segment detection, no polling
- **Concurrency**: Go's goroutine model handles thousands of concurrent viewers
- **VOD**: native Go `http.ServeFile` with zero-copy sendfile
