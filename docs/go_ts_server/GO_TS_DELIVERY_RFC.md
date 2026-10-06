# Go TS Delivery Server for Load Balancers

## Problem

On a load-balancer node with `redis_handler = 0` (database tracking), the PHP
chase-read loop in `live.php` holds one PHP-FPM worker for the entire duration
of every MPEG-TS viewer session.  A worker that reads 188-byte TS packets in a
tight loop cannot serve other requests, so the maximum number of concurrent
viewers equals the PHP-FPM `pm.max_children` setting.

## Solution

A small, statically linked Go HTTP server (`xc_ts_server`) replaces the
chase-read loop.  PHP still handles token decryption, authentication,
`ConnectionTracker::createLive()`, and all validation — then it hands the
response body to Go via `X-Accel-Redirect` and returns immediately (~100 ms
instead of minutes/hours).

### Architecture

```
Client → Cloudflare → MAIN (auth + 302) → LB Nginx
  ┌──────────────────────────────────────────────┐
  │  /auth/<token>  →  live.php                  │
  │    • decrypt token, validate user            │
  │    • createLive() in lines_live              │
  │    • $rCloseCon = false                      │
  │    • X-Accel-Redirect → /xc_ts_go/<stream>  │
  │    • return (PHP freed)                      │
  └──────────────────────────────────────────────┘
  ┌──────────────────────────────────────────────┐
  │  Nginx internal redirect → Go :8089          │
  │    • in-memory segment cache (inotify)       │
  │    • prebuffer N segments for instant start  │
  │    • chase-read: stream TS as ffmpeg writes  │
  │    • MariaDB heartbeat every 15 s            │
  │      (UPDATE lines_live SET hls_last_read,   │
  │       pid=0, hls_end=0 WHERE uuid=?)         │
  │    • on disconnect: SET hls_end=1            │
  └──────────────────────────────────────────────┘
```

### Key Design Decisions

1. **`$rCloseCon = false`** — PHP's `ShutdownHandler::handle('live')` checks
   this flag.  When PHP's role is only authentication, the handler must not
   close the connection in `lines_live` on exit — the Go server manages the
   lifecycle instead.

2. **`pid = 0`** — The cron reaper (`UsersCronJob`) skips TS connections with
   `pid = 0` ("Daemon-served TS, ADR 0003 Phase C").  The Go server sets
   `pid = 0` on its first heartbeat so the reaper treats the connection like a
   daemon-fed stream.

3. **Unconditional heartbeat** — The heartbeat UPDATE always resets
   `hls_end = 0`.  If a remote reaper or cron sets `hls_end = 1` between
   heartbeats, the next cycle restores it.  The Go server never drops a viewer
   based on DB state — only on TCP disconnect or stream end.

4. **15-second heartbeat interval** — The cron reaper runs every 60 s.  A 15 s
   heartbeat ensures `hls_last_read` is always fresh when the reaper inspects
   the row, and `hls_end` is restored well before the next cycle.

### Nginx Configuration (LB)

```nginx
# Internal location for Go TS delivery
location ^~ /xc_ts_go/ {
    internal;
    rewrite ^/xc_ts_go/(\d+)$ /ts/$1 break;
    proxy_pass              http://127.0.0.1:8089;
    proxy_http_version      1.1;
    proxy_buffering         off;
    proxy_request_buffering off;
    proxy_read_timeout      86400s;
    proxy_send_timeout      86400s;
}
```

### Go Server Features

| Feature | Description |
|---------|-------------|
| **Segment cache** | In-memory LRU cache per stream (configurable size + TTL) |
| **inotify watcher** | Detects new segments without polling |
| **Chase-read** | Streams a segment while ffmpeg is still writing it |
| **Prebuffer** | Serves N recent segments on connect for instant playback |
| **MariaDB tracker** | Heartbeat, pid=0, hls_end lifecycle |
| **Graceful shutdown** | Ends all connections cleanly on SIGTERM |

### PHP Change (live.php)

The change is minimal — one line added inside the existing X-Accel-Redirect
block in the "Fanout off" TS delivery section:

```php
if (file_exists("/home/xc_vm/bin/xc_ts_server/ts_server.pid")) {
    $rCloseCon = false; // Go server manages connection lifecycle
    // ... existing prebuffer + X-Accel-Redirect headers ...
    return;
}
```

The guard (`file_exists` on the PID file) means the redirect only activates
when the Go server is running.  If it's stopped, PHP falls through to the
standard chase-read loop — zero risk of breaking existing installs.

### Performance Characteristics

- **PHP-FPM workers freed**: each viewer consumes ~100 ms of PHP time instead
  of holding a worker for the session duration
- **Memory**: Go uses ~50 KB per viewer + cached segments (~7 MB × 15 per stream)
- **Latency**: inotify-based segment detection, no polling
- **Concurrency**: Go's goroutine model handles thousands of concurrent viewers
