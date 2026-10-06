# Go TS/HLS/VOD Delivery Server — XC_VM Integration

## Problem

On any XC_VM node (MAIN or LB), the PHP delivery loops in `live.php` and
`vod.php` hold one PHP-FPM worker for the entire duration of every viewer
session. A worker that reads TS packets in a tight loop or serves a VOD
file cannot serve other requests, so the maximum number of concurrent
viewers equals the PHP-FPM `pm.max_children` setting.

## Solution

A small, statically linked Go HTTP server (`xc_ts_server`) replaces the
**full PHP pipeline** for live streams on all nodes (MAIN and LBs).

### How it works — Live TS/HLS (default)

Go is the **primary handler** for `/auth/<token>`. Nginx routes directly
to Go, which handles the entire lifecycle:

```
Client → Nginx → Go :8089 /auth/<token>
                    ├── 1. Decrypt token (AES-256-GCM / CBC)
                    ├── 2. Validate expiry, off-air, extension
                    ├── 3. Check disallow_2nd_ip_con
                    ├── 4. Create/update lines_live in MariaDB
                    ├── 5. Check restrict_same_ip
                    ├── 6. Enforce max_connections (regular/HMAC/pair)
                    ├── 7. On-demand start if needed (flock + monitor)
                    ├── 8. Serve TS (chase-read + prebuffer)
                    │   or  Serve HLS (m3u8 playlist + /auth/seg/ segments)
                    └── 9. Heartbeat every 15s, hls_end=1 on disconnect
```

### PHP fallback (automatic)

If Go is not running (returns 502/503/504), nginx **automatically** falls
back to PHP. The `@go_auth_fallback` named location rewrites the request
to `/stream/live?token=...` which restarts location matching and hits PHP:

```
Client → Nginx → Go :8089 (down) → 502
                    └── @go_auth_fallback
                        └── rewrite → /stream/live?token=T → PHP live.php
                            ├── Decrypt token (PHP)
                            ├── Auth + enforcement (PHP)
                            └── Serve stream (PHP)
```

Zero manual intervention needed. When Go is restarted, nginx automatically
routes back to Go on the next request.

### VOD

VOD always goes through PHP first because `vod.php` has additional logic
(direct proxy, transcoding, download limits) that Go does not replicate.
PHP handles auth, then X-Accel-Redirects to Go for local file serving:

```
Client → Nginx → PHP vod.php
                    ├── Auth + enforcement (PHP)
                    ├── goTsServerAvailable()?
                    │   ├── YES + local file → X-Accel → Go /vod_serve/
                    │   └── NO or proxy → PHP handles delivery
                    └── return
```

## Go Auth Pipeline (auth.go)

Go replicates the full `live.php` pipeline:

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

## Nginx Configuration

Both `src/bin/nginx/conf/nginx.conf` (MAIN) and `lb_configs/nginx.conf` (LB):

1. The `/auth/` rewrite is **commented out** (Go handles it via location block):
   ```nginx
   # rewrite ^/auth/(?<token>[^/]*)$ /stream/live?token=$token break;
   ```

2. `go_ts_server.conf` is included, which provides:

   **Go primary + PHP fallback:**
   ```nginx
   location ~ ^/auth/(?<go_token>.+)$ {
       proxy_pass http://127.0.0.1:8089;
       proxy_connect_timeout 2s;        # Fast fail if Go is down
       proxy_intercept_errors on;
       error_page 502 503 504 = @go_auth_fallback;
   }

   location @go_auth_fallback {
       rewrite ^ /stream/live?token=$go_token last;  # → PHP
   }
   ```

   **X-Accel for VOD (PHP auth → Go delivery):**
   ```nginx
   location /xc_vod_go/ { internal; rewrite → /vod_serve/$1; proxy_pass Go; }
   ```

   **X-Accel for TS/HLS (secondary path, if PHP handles auth):**
   ```nginx
   location /xc_ts_go/  { internal; rewrite → /ts/$1;           proxy_pass Go; }
   location /xc_hls_go/ { internal; rewrite → /hls_playlist/$1; proxy_pass Go; }
   ```

## Key Design Decisions

1. **Go is the default handler** — All `/auth/<token>` requests go to Go.
   PHP is the fallback, not the primary path.

2. **`proxy_connect_timeout 2s`** — If Go is down, nginx detects it in 2s
   and falls back to PHP. Viewers experience a brief delay on the first
   request after Go goes down, then PHP handles normally.

3. **`pid = 0`** — Go-served connections use `pid = 0` in `lines_live`.
   The cron reaper (`UsersCronJob`) skips `pid = 0` rows.

4. **15-second heartbeat** — Go heartbeats to MariaDB every 15s. The cron
   reaper runs every 60s. This ensures `hls_last_read` is always fresh.

5. **HLS connection key** — Go generates the same deterministic UUID as PHP
   so repeated playlist requests reuse one `lines_live` row.

6. **VOD stays PHP-first** — `vod.php` has direct-proxy, transcoding, and
   download logic that Go doesn't replicate. Go only handles local file
   serving with Range support after PHP auth.

## Features Summary

| Feature | Live TS/HLS (Go) | VOD (PHP → Go) | Fallback (PHP) |
|---------|:-----------------:|:---------------:|:--------------:|
| Token decryption | **Go** | PHP | PHP |
| Auth & validation | **Go** | PHP | PHP |
| Connection tracking | **Go** | PHP + Go heartbeat | PHP |
| Enforcement | **Go** | PHP | PHP |
| On-demand start | **Go** | — | PHP |
| Segment cache (inotify) | **Go** | — | — |
| Chase-read + prebuffer | **Go** | — | PHP |
| HLS playlist rewrite | **Go** | — | PHP |
| HTTP Range (206/416) | — | **Go** | PHP |
| Signal polling | **Go** | **Go** | — |
| MariaDB heartbeat | **Go** | **Go** | PHP |

## Performance

- **Live (Go primary)**: No PHP worker involved at all. Go handles everything
  from token decryption to stream disconnect.
- **VOD (PHP + Go)**: PHP worker freed after ~100 ms (auth + X-Accel).
- **Memory**: Go uses ~50 KB per viewer + cached segments (~7 MB × 15 per stream)
- **Concurrency**: Go's goroutine model handles thousands of concurrent viewers
- **VOD**: native Go `http.ServeFile` with zero-copy sendfile
- **Fallback latency**: 2s `proxy_connect_timeout` when Go is down
