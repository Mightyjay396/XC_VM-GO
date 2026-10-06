# Nginx Fallback Routing & HLS/VOD Audit

**Date:** 2026-10-06
**Status:** Code-level audit complete. Not runtime-tested on a live XC_VM instance.

## 1. Nginx Fallback Routing: Go-Primary + PHP Fallback

### Architecture Overview

```
Go running (default):
  Client -> /auth/<token> -> nginx -> Go (8089) -> full pipeline
  Client -> /vauth/<token> -> nginx -> PHP vod.php -> X-Accel -> Go /vod_serve/

Go down (automatic fallback):
  Client -> /auth/<token> -> nginx -> Go (502) -> @go_auth_fallback -> PHP live.php
  Client -> /vauth/<token> -> nginx -> PHP vod.php -> serves file itself
```

### Routing Verification

#### 1.1 `/auth/` Rewrite Disabled

| Config File | Line | Status |
|---|---|---|
| `lb_configs/nginx.conf` | 73 | Commented out with explanation |
| `src/bin/nginx/conf/nginx.conf` | 102 | Commented out with explanation |

Both configs comment the rewrite with:
```nginx
# Go handles /auth/ natively -- see go_ts_server.conf
# rewrite ^/auth/(?<token>[^/]*)$ /stream/live?token=$token break;
```

**Verdict: PASS** - Without this rewrite, `/auth/<token>` URIs reach location matching unchanged.

#### 1.2 Go Location Matching

`go_ts_server.conf` defines two regex locations:

```nginx
location ~ ^/auth/seg/(.+)$     # HLS segments (matched first - longer pattern)
location ~ ^/auth/(?<go_token>.+)$  # All other /auth/ requests (TS + HLS playlists)
```

**Nginx regex location ordering:** Regex locations match in order of appearance in config. `/auth/seg/` appears first and has a more specific pattern, so segment requests never fall into the general `/auth/` handler.

**No conflicting locations:** Checked all `location` and `rewrite` directives in both MAIN and LB configs. No other pattern matches `/auth/<token>`:
- `/stream/(auth|key|...)` requires `/stream/` prefix
- Catch-all rewrites (`^/(?<username>...`) require 3+ path segments
- `^~` prefix locations (`/images/`, `/xc_fanout/`, etc.) don't match `/auth/`

**Verdict: PASS**

#### 1.3 Fallback Named Location: `$go_token` Persistence

```nginx
location ~ ^/auth/(?<go_token>.+)$ {
    ...
    error_page 502 503 504 = @go_auth_fallback;
}

location @go_auth_fallback {
    rewrite ^ /stream/live?token=$go_token last;
}
```

**Key question:** Does `$go_token` survive into the named location?

**Answer: Yes.** Named captures from nginx regex locations are set during the location-matching phase and persist through `error_page` redirects to named locations. This is documented nginx behavior.

**The `=` in `error_page`:** Without a status code after `=`, the response code is determined by the fallback handler (PHP). This is correct -- the PHP response code (200 for stream, 403 for auth fail, etc.) becomes the final status.

**Verdict: PASS**

#### 1.4 PHP Streaming Hot Path Match

After the fallback rewrite to `/stream/live?token=$go_token`, the `last` flag triggers a new location search:

| Config | Location Pattern | Includes `live`? |
|---|---|---|
| MAIN `nginx.conf` | `^/stream/(auth\|key\|segment\|live\|vod\|...)$` | Yes (line 242) |
| LB `nginx.conf` | `^/stream/(key\|segment\|live\|vod\|...)$` | Yes (line 89) |

Both configs route `/stream/live` to PHP via fastcgi, with `SCRIPT_FILENAME` pointing to the stream index, and `XC_STREAM` set to `live`. This triggers `live.php`.

**Verdict: PASS**

#### 1.5 HLS Segment Fallback

```nginx
location @go_seg_fallback {
    return 503 "Go TS server unavailable for HLS segments";
}
```

There is no PHP equivalent for Go-native HLS segment URLs (`/auth/seg/<file>?uuid=<uuid>`). PHP's HLS uses different URLs (`/hls/<token>` -> `segment.php`). This is correct:

- **New HLS sessions:** Client requests `/auth/<token>` (m3u8). If Go is down, fallback sends to PHP `live.php`, which generates its own playlist with PHP-style segment URLs. Player fetches segments via `/hls/<token>` -> `segment.php`. Full fallback works.
- **Existing Go HLS sessions:** If Go goes down mid-session, segment requests fail with 503. Player will retry and eventually re-request the playlist, which falls back to PHP.

**Verdict: PASS** (graceful degradation by design)

#### 1.6 X-Accel Internal Locations

| Location | Source | Target | Purpose |
|---|---|---|---|
| `/xc_ts_go/` | PHP live.php | Go `/ts/` | TS delivery (secondary path) |
| `/xc_hls_go/` | PHP live.php | Go `/hls_playlist/` | HLS playlist (secondary path) |
| `/xc_vod_go/` | PHP vod.php | Go `/vod_serve/` | VOD file delivery |

All three are marked `internal` (unreachable directly by clients). PHP calls them via `X-Accel-Redirect` headers. The rewrite rules correctly transform the path for Go.

**Verdict: PASS**

#### 1.7 VOD Fallback (PHP-Level)

VOD uses a different fallback mechanism -- PHP checks `goTsServerAvailable()`:

```php
function goTsServerAvailable(): bool {
    $pidFile = '/home/xc_vm/bin/xc_ts_server/ts_server.pid';
    if (!file_exists($pidFile)) { return false; }
    $pid = intval(file_get_contents($pidFile));
    return ($pid > 0 && file_exists("/proc/{$pid}"));
}
```

If Go is not running, PHP skips the `X-Accel-Redirect` and serves the VOD file itself using its own Range/HttpRange implementation. This is a **process-level check** (PID file + /proc), not a network check.

**Verdict: PASS**

### 1.8 Potential Edge Cases

| Scenario | Behavior | Severity |
|---|---|---|
| Go partially responsive (accepts TCP but hangs) | `proxy_read_timeout 3600s` means up to 1 hour before fallback | Low - only affects edge case of Go in zombie state |
| Go process exists but not listening on 8089 | `proxy_connect_timeout 2s` triggers fast 502 fallback | None - handled correctly |
| Go restarts during active TS stream | Client gets broken pipe; reconnect hits Go or PHP | None - standard behavior |
| `goTsServerAvailable()` race with Go stop | PHP might X-Accel to Go just as it stops; nginx returns 502 to client | Low - transient during restart |

---

## 2. HLS m3u8 Playback Verification

### 2.1 Flow: Go-Primary HLS

```
1. Player: GET /auth/<encrypted_token>  (token has extension=m3u8)
2. Nginx: matches ^/auth/(?<go_token>.+)$ -> proxy to Go:8089
3. Go auth.go ServeAuth():
   a. Decrypt token (GCM then CBC fallback)
   b. Detect extension == "m3u8" -> HLS mode
   c. Generate deterministic UUID via hlsConnectionKey()
   d. Create/update connection in lines_live
   e. Enforce max_connections
   f. Write cons_tmp file with client IP
   g. Generate tokenized playlist from on-disk m3u8
   h. Return playlist (Content-Type: application/x-mpegurl)
4. Player parses playlist, finds segment URLs: seg/<file>?uuid=<uuid>
5. Browser resolves relative URL: /auth/seg/<file>?uuid=<uuid>
6. Nginx: matches ^/auth/seg/(.+)$ -> proxy to Go:8089
7. Go hls.go ServeSegment():
   a. Validate UUID (cons_tmp file exists)
   b. Validate IP (restrict_same_ip)
   c. Sanitize filename (path traversal protection)
   d. Serve segment file (http.ServeFile)
```

### 2.2 Playlist Generation (hls.go `generateHLSPlaylist`)

- **Source:** Reads `/home/xc_vm/content/streams/<stream_id>_.m3u8`
- **Rewrites:**
  - `.ts` and `.m4s` segment lines -> `seg/<filename>?uuid=<uuid>`
  - `#EXT-X-MAP:URI="<file>"` for fMP4 init segments -> `URI="seg/<file>?uuid=<uuid>"`
  - Comment lines and `#EXT-X-*` tags pass through unchanged
- **Relative URL resolution:** Playlist served at `/auth/<token>`, so relative `seg/...` resolves to `/auth/seg/...` which matches the nginx segment location

**Verdict: PASS**

### 2.3 HLS Connection Key (PHP Parity)

Go `hlsConnectionKey()` matches PHP `ConnectionTracker::hlsConnectionKey()`:

```go
// HMAC: "hmac_<identifier>_<stream_id>_<md5(ip+ua)>"
// User: "user_<user_id>_<stream_id>_<md5(ip+ua)>"
```

This ensures repeated playlist requests from the same player reuse ONE tracked connection, preventing row balloon in lines_live on channel switch.

**Verdict: PASS**

### 2.4 Segment Security Model

1. **UUID validation:** cons_tmp file must exist at `CONS_TMP_PATH/<uuid>`
2. **IP validation:** If `restrict_same_ip` is enabled, segment IP must match the IP stored in cons_tmp
3. **Subnet matching:** Supports `/24` (IPv4) and `/48` (IPv6) subnet matching
4. **Path traversal:** `filepath.Base()` strips directory components from segment filename

**Verdict: PASS**

### 2.5 Known Parity Gap: HLS Encryption

PHP `segment.php` supports `encrypt_hls` setting -- it encrypts segments with AES-128-CBC at serve-time (on-disk segments are stored unencrypted). Go's segment handler serves segments raw from disk.

**Impact:** If `encrypt_hls` is enabled in XC_VM settings:
- Go-primary path (`/auth/` -> Go): Serves unencrypted segments. If the on-disk m3u8 doesn't have `#EXT-X-KEY` tags (because encryption is added at serve-time by PHP), the player receives unencrypted content correctly. However, if the m3u8 references a key, the player would try to decrypt content that isn't encrypted.
- PHP fallback: Works correctly with encryption.

**Status:** Documented parity gap. Does not affect installations where `encrypt_hls` is disabled (default). See P1 backlog item.

### 2.6 Known Parity Gap: `#EXT-X-KEY` URI Rewriting

Go's `generateHLSPlaylist()` does not rewrite `#EXT-X-KEY:...URI="..."` lines. If the on-disk m3u8 contains key URIs, they pass through unchanged. This should work if the key URI points to a PHP endpoint (`/key/<token>`) since those requests still go through nginx to PHP.

**Status:** Low risk -- only relevant if on-disk m3u8 contains key URIs. The key endpoint is not affected by Go changes.

---

## 3. VOD Seeking Verification

### 3.1 Flow: PHP Auth -> Go Delivery

```
1. Player: GET /vauth/<encrypted_token>
2. Nginx: rewrites to /stream/vod?token=<token> -> PHP vod.php
3. PHP vod.php:
   a. Decrypt token, validate user, create connection
   b. Enforce max_connections
   c. Check goTsServerAvailable()
   d. If Go available: X-Accel-Redirect: /xc_vod_go/<stream_id>?uuid=U&ext=E
   e. If Go unavailable: PHP serves file with manual Range handling
4. Nginx: matches internal /xc_vod_go/ -> rewrites to /vod_serve/<stream_id>
5. Go vod.go ServeVOD():
   a. Parse stream_id, uuid, ext from URL
   b. Build file path: VOD_PATH/<stream_id>.<ext>
   c. Register for heartbeat tracking
   d. Touch cons_tmp file
   e. Set Content-Type from extension mapping
   f. Serve file via http.ServeFile (full Range support)
   g. Monitor stopCh for external kill signal
```

### 3.2 HTTP Range Support

Go's `http.ServeFile` provides native HTTP Range support:

| Feature | PHP (manual HttpRange) | Go (http.ServeFile) |
|---|---|---|
| Single range request | Yes | Yes |
| Multi-range request | Yes | Yes |
| `206 Partial Content` | Yes | Yes |
| `416 Range Not Satisfiable` | Yes | Yes |
| `Content-Range` header | Yes | Yes |
| `Accept-Ranges: bytes` | Yes | Yes |
| `If-Modified-Since` | No | Yes (bonus) |
| `If-None-Match` (ETag) | No | Yes (bonus) |

Go's implementation is actually more complete than PHP's for conditional requests.

**Seeking behavior:**
- Player sends `Range: bytes=12345-` header
- Go responds with `206 Partial Content` and `Content-Range: bytes 12345-99999/100000`
- Player can seek to any position in the file

**Verdict: PASS**

### 3.3 Content-Type Mapping

Compared PHP `$rTypes` (vod.php line 225) vs Go `vodContentTypes` (vod.go lines 41-53):

| Extension | PHP Content-Type | Go Content-Type | Match |
|---|---|---|---|
| mp4 | video/mp4 | video/mp4 | Yes |
| m4v | video/mp4 | video/mp4 | Yes |
| mkv | video/x-matroska | video/x-matroska | Yes |
| avi | video/x-msvideo | video/x-msvideo | Yes |
| 3gp | video/3gpp | video/3gpp | Yes |
| flv | video/x-flv | video/x-flv | Yes |
| wmv | video/x-ms-wmv | video/x-ms-wmv | Yes |
| mov | video/quicktime | video/quicktime | Yes |
| ts | video/mp2t | video/mp2t | Yes |
| mpg | video/mpeg | video/mpeg | Yes |
| mpeg | video/mpeg | video/mpeg | Yes |
| Unknown | application/octet-stream | application/octet-stream | Yes |

**Verdict: PASS** - Complete 1:1 mapping.

### 3.4 Heartbeat and Lifecycle

- `tracker.Register(uuid, streamID)` starts periodic `hls_last_read` updates in `lines_live`
- `tracker.Unregister(uuid)` on completion/disconnect sets `hls_end=1`
- `stopCh` channel signals external kill (enforcement/admin action)
- cons_tmp file is created at start, removed on handler exit (defer)

**Verdict: PASS**

### 3.5 Known Minor Issue: Speed Tracking Placeholder

`vod.go` line 156 contains a no-op placeholder:
```go
elapsed := time.Since(time.Now()).Seconds() // placeholder
```

PHP's vod.php tracks download speed via a divergence file for bitrate throttling (`vod_limit_perc`, `vod_bitrate_plus`). Go does not implement this yet.

**Impact:** VOD files are served at full speed without throttling. This could be faster than intended for operators who configured bitrate limits.

**Status:** P2 backlog item (vod_limit_perc throttling).

---

## Summary

| Component | Status | Issues Found |
|---|---|---|
| Nginx `/auth/` routing to Go | PASS | None |
| `$go_token` variable in fallback | PASS | None |
| PHP fallback chain | PASS | None |
| HLS segment fallback (503) | PASS | By design |
| X-Accel internal locations | PASS | None |
| VOD PHP-level fallback | PASS | None |
| HLS playlist generation | PASS | None |
| HLS segment security | PASS | None |
| HLS connection key parity | PASS | None |
| VOD Range/seeking support | PASS | None |
| VOD Content-Type mapping | PASS | Complete 1:1 match |
| VOD heartbeat/lifecycle | PASS | None |
| HLS encryption (`encrypt_hls`) | GAP | Go serves unencrypted; P1 backlog |
| VOD speed throttling | GAP | Placeholder code; P2 backlog |
| Partial Go failure timeout | NOTE | 3600s read timeout; low risk |
