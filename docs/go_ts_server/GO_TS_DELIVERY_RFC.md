# Go TS/HLS/VOD Delivery Server — XC_VM Integration

## Problem

On any XC_VM node (MAIN or LB), the PHP delivery loops in `live.php` and
`vod.php` hold one PHP-FPM worker for the entire duration of every viewer
session. A worker that reads TS packets in a tight loop or serves a VOD
file cannot serve other requests, so the maximum number of concurrent
viewers equals the PHP-FPM `pm.max_children` setting.

## Solution

A small, statically linked Go HTTP server (`xc_ts_server`) replaces the
PHP **delivery loops** for live and VOD streams on all nodes (MAIN and LBs).

### Current configuration (Mode B — active by default)

PHP handles **auth, connection tracking, and enforcement** as before.
Go handles **byte delivery, heartbeat, and lifecycle** via X-Accel-Redirect.
If Go is not running, PHP falls back to its own delivery — zero risk.

```
Client → Nginx → PHP live.php / vod.php
                    ├── Decrypt token (PHP)
                    ├── Validate user (PHP)
                    ├── createLive() in lines_live (PHP)
                    ├── Enforce max_connections (PHP)
                    ├── goTsServerAvailable()?
                    │   ├── YES → X-Accel-Redirect → Go :8089
                    │   │         ├── TS: chase-read + prebuffer + heartbeat
                    │   │         ├── HLS: playlist rewriting + segment serving
                    │   │         └── VOD: file serving with Range/seek
                    │   └── NO  → PHP serves the stream itself (fallback)
                    └── return (PHP freed when Go handles delivery)
```

### Native auth mode (Mode A — available, not activated)

Go can handle the **entire pipeline** — token decryption, auth, connection
tracking, enforcement, on-demand, and delivery — bypassing PHP completely.
The code is fully implemented in `auth.go` but Mode A nginx locations are
**commented out** in `go_ts_server.conf`. To activate:

1. Uncomment the `/auth/` and `/auth/seg/` location blocks in `go_ts_server.conf`
2. The existing nginx rewrite (`/auth/<token>` → `/stream/live`) will be
   overridden by the more specific regex location
3. No PHP fallback in this mode — if Go is down, nginx returns 502

```
Client → Nginx → Go :8089 /auth/<token>
                    ├── Decrypt token (Go: AES-256-GCM + CBC)
                    ├── Validate expiry, off-air, extension (Go)
                    ├── Check disallow_2nd_ip_con (Go)
                    ├── Create/update lines_live (Go)
                    ├── Check restrict_same_ip (Go)
                    ├── Enforce max_connections (Go)
                    ├── On-demand start if needed (Go)
                    ├── Serve TS or HLS (Go)
                    └── Heartbeat + hls_end=1 on disconnect (Go)
```

### VOD (always Mode B)

VOD always goes through PHP first because `vod.php` has additional logic
(direct proxy, transcoding, download limits) that Go does not replicate.
Go handles only the local file serving with Range support.

```
Client → Nginx → PHP vod.php
                    ├── Auth + enforcement (PHP)
                    ├── goTsServerAvailable() + local file?
                    │   ├── YES → X-Accel-Redirect → Go /vod_serve/
                    │   └── NO  → PHP serves (fallback) or direct proxy
                    └── return
```

## Go Auth Pipeline (auth.go — Mode A only)

When Mode A is activated, Go replicates the full `live.php` pipeline:

| Step | PHP (`live.php`) | Go (`auth.go`) |
|------|------------------|----------------|
| 1 | `sendStreamHeaders()` | CORS + protection headers |
| 2 | `Encryption::decrypt()` | `TokenDecryptor.Decrypt()` (GCM + CBC) |
| 3 | Check `off_air` / `video_path` | Return `STREAM_OFF_AIR` |
| 4 | Check `$rExpires` | Compare `td.Expires` vs `now - timeOffset` |
| 5 | `ConnectionLimiter::disallow2nd` | `CheckUserIP()` + `IPsMatch()` |
| 6 | `ConnectionTracker::createLive()` | `CreateConnection()` / `UpdateConnectionReuse()` |
| 7 | `ConnectionLimiter::restrictSameIP` | `IPsMatch()` on existing row |
| 8 | `StreamAuth::validateConnections()` | `EnforceMaxConnections()` / `EnforceMaxConnectionsHMAC()` |
| 9 | HLS: `HLSGenerator`, TS: chase-read | `generateHLSPlaylist()` / `DeliverTS()` |
| 10 | `ShutdownHandler::handle()` | `Unregister()` → `hls_end=1` |

This code is ready but not used until Mode A is activated in nginx.

## Key Design Decisions

1. **Mode B is default** — Safest approach: PHP handles auth, Go handles
   delivery. If Go crashes or is stopped, PHP falls back automatically.
   No risk of breaking existing installs.

2. **Mode A is opt-in** — Uncomment 2 nginx blocks to bypass PHP entirely
   for live streams. Higher performance (no PHP worker at all) but no
   fallback if Go is down.

3. **`pid = 0`** — Go-served connections use `pid = 0` in `lines_live`.
   The cron reaper (`UsersCronJob`) skips `pid = 0` rows, treating them
   like daemon-fed streams.

4. **`goTsServerAvailable()`** — PHP guard function that checks PID file +
   `/proc/<pid>`. Fast check (~0.1 ms), avoids X-Accel to a dead server.

5. **15-second heartbeat** — Go heartbeats to MariaDB every 15s. The cron
   reaper runs every 60s. This ensures `hls_last_read` is always fresh.

6. **HLS connection key** — Go generates the same deterministic UUID as PHP
   so repeated playlist requests reuse one `lines_live` row.

## Nginx Configuration

Both `src/bin/nginx/conf/nginx.conf` (MAIN) and `lb_configs/nginx.conf` (LB)
include `go_ts_server.conf` which provides:

**Active (Mode B — X-Accel from PHP):**
```nginx
location /xc_ts_go/  { internal; rewrite → /ts/$1;           proxy_pass Go; }
location /xc_hls_go/ { internal; rewrite → /hls_playlist/$1; proxy_pass Go; }
location /xc_vod_go/ { internal; rewrite → /vod_serve/$1;    proxy_pass Go; }
```

**Commented (Mode A — direct Go auth, uncomment to activate):**
```nginx
# location ~ ^/auth/seg/(.+)$ { proxy_pass http://127.0.0.1:8089; }
# location ~ ^/auth/(.+)$     { proxy_pass http://127.0.0.1:8089; }
```

## Go Server Features

| Feature | Mode B (current) | Mode A (opt-in) |
|---------|:-----------------:|:----------------:|
| Token decryption | PHP | **Go** |
| Auth & validation | PHP | **Go** |
| Connection tracking (lines_live) | PHP creates, **Go heartbeats** | **Go** |
| Enforcement (max_conn, IP) | PHP | **Go** |
| On-demand start | PHP | **Go** |
| TS chase-read + prebuffer | **Go** | **Go** |
| HLS playlist + segments | **Go** | **Go** |
| VOD Range/seek | **Go** | **Go** |
| Signal polling | **Go** | **Go** |
| PHP fallback if Go down | **Yes** | No (502) |

## PHP Changes

**`live.php`**: Two X-Accel-Redirect blocks (TS and HLS), guarded by
`goTsServerAvailable()`. After PHP auth/enforcement, if Go is running,
delivery is handed off to Go. If Go is not running, PHP serves normally.

**`vod.php`**: One X-Accel-Redirect block (VOD), guarded by
`goTsServerAvailable()`. Direct-proxy VOD stays entirely in PHP.

## Performance

- **Mode B**: PHP worker is freed after ~100 ms (auth + X-Accel). Go handles
  the long-lived delivery. PHP-FPM can serve many more concurrent requests.
- **Mode A**: No PHP worker involved at all. Go handles everything from
  connection to disconnection.
- **Memory**: Go uses ~50 KB per viewer + cached segments
- **Concurrency**: Go goroutines handle thousands of concurrent viewers
- **VOD**: Native Go `http.ServeFile` with zero-copy sendfile
