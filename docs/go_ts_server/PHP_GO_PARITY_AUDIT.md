# PHP↔Go Parity Audit — live.php vs xc_ts_server

## Scope
Go replaces **TS, HLS and VOD delivery** on all nodes (MAIN and LBs). PHP retains panel, daemons, RTMP, crons, and auth/enforcement (Go receives delivery via X-Accel-Redirect after PHP auth).

## Audit Matrix

| # | Feature / Branch | PHP (live.php) | Go (xc_ts_server) | Status | Priority |
|---|---|---|---|---|---|
| 1 | Token decrypt (GCM + CBC) | `StreamAuthMiddleware::decryptToken` | `auth.go: Decrypt()` GCM→CBC fallback | Covered | — |
| 2 | Off-air / video_path token | Reads off-air video file, streams it | Returns HTTP 404 `STREAM_OFF_AIR` | Gap | P1 |
| 3 | Extension validation | ts/m3u8, defaults to `api_container` | ts-only, error on others | By design | — |
| 4 | CORS header | `Access-Control-Allow-Origin: *` | Same | Covered | — |
| 5 | Protection headers (XSS, Content-Type-Options) | From settings `send_protection_headers` | Not sent | Gap | P2 |
| 6 | Server header | From settings `send_server_header` | Not sent | Gap | P2 |
| 7 | Alt-Svc header (HTTP/3) | From settings `send_altsvc_header` | Not sent | Gap | P2 |
| 8 | Unique cookie | From settings `send_unique_header` | Not sent | Gap | P2 |
| 9 | `X-Accel-Buffering: no` | When `use_buffer=0` | Always sent | Covered | — |
| 10 | Server/Proxy ID resolution (originator/redirect) | `$rChannelInfo["originator_id"]`, `redirect_id` | `auth.go` lines 347-353 | Covered | — |
| 11 | FanoutMode delivery routing | `legacyDelivery()`, daemon vs legacy | Not implemented (N/A when fanout off) | N/A | — |
| 12 | PID file reads (_.pid, _.monitor) | `AsyncFileOperations::readFile` | `ondemand.go: readPidFile()` | Covered | — |
| 13 | On-demand queue (addToQueue/removeFromQueue) | PID queue file `SIGNALS_TMP_PATH/queue_<id>` | In-memory UUID map (no file written) | Gap | P1 |
| 14 | On-demand start: START_MONITOR | `lockOnDemandStart` → `startMonitor` → wait | `doStartMonitor`: flock → PHP console.php → wait | Covered | — |
| 15 | On-demand start: START_PROXY | `lockOnDemandStart` → `startProxy` → wait | `doStartProxy`: flock → PHP console.php → wait | Covered | — |
| 16 | On-demand shutdown | PHP daemon `XC_VM[Ondemand]` via lines_live/queue | Delegated to PHP daemon (Go sets hls_end=1) | Covered | — |
| 17 | isWatched (monitor + fanout check) | `ProcessManager::isMonitorAlive` + `FanoutClient::isSupervised` | `processAlive(monitorPid)` only | Gap (minor, fanout off) | P2 |
| 18 | First segment wait | `awaitAnyFileExists([ts, m4s])` | `waitForSegments` checks both .ts and .m4s | Covered | — |
| 19 | Post-segment stream alive check | `isWatched && isStreamAlive` after segment found | Not done after segment found | Gap (minor) | P2 |
| 20 | Token expiry validation | `$rTokenData['expires'] < time() - offset` | `auth.go` line 326 | Covered | — |
| 21 | Create expiration | `$rActivityStart + $rCreateExpiration + exec_time - offset` | `auth.go` lines 458-464 | Covered | — |
| 22 | `disallow_2nd_ip_con` | Check user IP, max check with disallow_2nd_ip_max | `auth.go` lines 388-406 | Covered | — |
| 23 | `restrict_same_ip` | Check IP on existing connection | `auth.go` lines 445-449 | Covered | — |
| 24 | IP subnet match (/24 IPv4, /48 IPv6) | `NetworkUtils::ipMatches` | `tracker.go: sameSubnet()` | Covered | — |
| 25 | lookupLive (existing connection) | `ConnectionTracker::lookupLive` | `tracker.LookupConnection(uuid)` | Covered | — |
| 26 | createLive (new connection) | `ConnectionTracker::createLive` | `tracker.CreateConnection(conn)` | Covered | — |
| 27 | UpdateLive (reconnect) | `ConnectionTracker::updateLive` | `tracker.UpdateConnectionReuse` | Covered | — |
| 28 | Delete closed by UUID | Delete stale `hls_end=1` rows before create | `tracker.DeleteClosedByUUID` | Covered | — |
| 29 | PHP TS worker kill on reconnect | `posix_kill(old_pid, 9)` when pid>0 | N/A (Go uses pid=0, no worker to kill) | By design | — |
| **30** | **Enforcement: container-aware eviction** | **HLS → UPDATE hls_end=1; TS pid>0 → SIGKILL+DELETE; pid=0 → dropDaemonViewer** | **All → DELETE (no container/pid differentiation)** | **BUG** | **P0** |
| **31** | **Enforcement: cross-server signaling** | **Different server → SignalDispatcher::kill / dropDaemonViewer** | **DELETE regardless of server** | **BUG** | **P0** |
| 32 | Enforcement 3-pass eviction order | Same IP+UA → same IP → any (oldest first) | Same 3-pass logic | Covered | — |
| 33 | Enforcement pair_id | `closeConnections($rUserInfo['pair_id'], ...)` | `EnforceMaxConnections(*td.UserInfo.PairID, ...)` | Covered | — |
| 34 | Touch file (CONS_TMP_PATH) | `touch(CONS_TMP_PATH . uuid)` + cleanup | `handler.go` line 114 + defer Remove | Covered | — |
| 35 | TS Content-Type header | `Content-Type: video/mp2t` | Same | Covered | — |
| 36 | Prebuffer calculation (client/restreamer) | Complex: is_restreamer, prebuffer field, settings | `GetPrebufferSec` in auth.go | Covered | — |
| 37 | TS chase-read loop | `stream_get_line` + sleep/check | inotify + chase-read + segment cache | Covered (improved) | — |
| 38 | Segment wait timeout | `segment_wait_time` setting (default 20) | `segWaitTime` parameter | Covered | — |
| 39 | Admin signal file (drop) | Read signal file, exit on "drop" | `checkSignalFile` in handler.go | Covered | — |
| 40 | Admin signal file (overlay) | `SignalSender::sendSignal` burns overlay onto segment | Consumed but not applied | Gap | P2 |
| 41 | NodeLease fencing | `NodeLease::refusesEverything()` check per segment | Not implemented | Gap | P1 |
| 42 | Divergence/speed tracking | `DIVERGENCE_TMP_PATH . uuid` | `divergencePath` in handler.go | Covered | — |
| 43 | Segment duration file (_.dur) | Reads `<id>_.dur`, adjusts seg_time | Not read | Gap | P1 |
| 44 | Connection heartbeat | Every 5 min: re-read settings, heartbeat | Every N seconds (configurable), MariaDB UPDATE | Covered (more frequent) | — |
| 45 | Shutdown: normal disconnect | `UPDATE hls_end=1 WHERE uuid=? AND pid=?` | `UPDATE hls_end=1 WHERE uuid=? AND hls_end=0` | Covered (pid check N/A for Go) | — |
| 46 | Shutdown: removeFromQueue | `ConnectionTracker::removeFromQueue($streamID, $PID)` | In-memory map (no file) | Gap (same as #13) | P1 |
| 47 | Shutdown: unlink proxy socket | `@unlink(CONS_TMP_PATH . $streamID . '/' . uuid)` | Not done | Gap | P2 |
| 48 | Activity logging (writeOfflineActivity) | `ConnectionTracker::writeOfflineActivity` to file | `tracker.writeOfflineActivity` to file | Covered | — |
| 49 | Signal poller (DB signals) | PHP SignalsCommand daemon | `signals.go: pollDBSignals()` | Covered | — |
| 50 | Signal poller (Redis SIGNALS#) | PHP signals daemon | `signals.go: pollRedisSignals()` (code present, disabled) | Covered (code ready) | — |
| 51 | DatabaseLogger::clientLog | Logs events for panel visibility | Not implemented | Gap | P1 |
| 52 | Proxy TS relay (UNIX datagrams) | ProxyCommand → socket → viewer | Not implemented (Go reads disk) | Gap | P1 |
| 53 | Redis dual-mode tracking | All CRUD to Redis + MySQL | All CRUD dual-mode code present, disabled | Covered (code ready) | — |
| 54 | igbinary encode/decode | PHP native igbinary | `igbinary.go` custom encoder/decoder | Covered (code ready) | — |

## P0 Fixes Required (This Session)

### Fix 1: Container-aware enforcement eviction (#30)
**Problem**: Go DELETEs ALL evicted connections regardless of container type. PHP differentiates:
- HLS (`container='hls'/'m3u8'`): UPDATE hls_end=1 (soft close, player detects on next refresh)
- TS with pid>0 (PHP worker): DELETE + SIGKILL
- TS with pid=0 (Go/daemon): DELETE + signal/tracker drop

**Impact**: Go evicting an HLS viewer via DELETE causes the player to hang instead of gracefully stopping.

### Fix 2: Cross-server enforcement signaling (#31)
**Problem**: Go DELETEs connections from other servers without notification. PHP dispatches a signal (via `signals` table or Redis) so the other server's daemon can act.

**Impact**: Evicted connections on other servers remain active until their next heartbeat (up to 5 min).

## P1 Items (Future)
- Off-air video serving (#2)
- On-demand PID queue file (#13, #46)
- NodeLease fencing (#41)
- Segment duration file (#43)
- DatabaseLogger::clientLog (#51)
- Proxy TS relay (#52)

## P2 Items (Backlog)
- Protection/Server/Alt-Svc/Cookie headers (#5-8)
- Signal overlay (#40)
- isWatched fanout check (#17)
- Proxy socket cleanup (#47)
- Post-segment stream alive check (#19)

## VOD Parity

| # | Feature | PHP (vod.php) | Go (vod.go) | Status |
|---|---|---|---|---|
| V1 | Token decrypt + validation | `StreamAuthMiddleware::decryptToken` | PHP handles, Go receives via X-Accel | Covered (PHP→Go) |
| V2 | Connection tracking | `ConnectionTracker::createLive/updateLive` | PHP handles creation, Go heartbeats | Covered |
| V3 | Max connections enforcement | `StreamAuth::validateConnections` | PHP handles | Covered |
| V4 | HTTP Range (seek) | Manual `HttpRange::sendHeaders` | `http.ServeFile` (native Go) | Covered (improved) |
| V5 | Content-Type mapping | `$rTypes[ext]` | `vodContentTypes[ext]` (same map) | Covered |
| V6 | Local file delivery | `fread` loop with throttling | `http.ServeFile` (full speed) | Covered |
| V7 | Heartbeat (300s PHP / 15s Go) | Every 300s | Every 15s via tracker | Covered (more frequent) |
| V8 | External kill detection | `connection_status()` check | stopCh from tracker | Covered |
| V9 | Proxy VOD (remote cURL) | cURL relay | Not implemented (stays in PHP) | N/A (by design) |
| V10 | Bitrate throttling | `vod_bitrate_plus`, `vod_limit_perc` | Not implemented | Gap (P2) |
| V11 | Fanout daemon delivery | FanoutClient | Not implemented (fanout off) | N/A |

