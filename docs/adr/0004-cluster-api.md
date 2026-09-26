# ADR 0004 — Cluster API between MAIN and load balancers: the panel's contract

- **Status:** Accepted. Phase 0 (seams), Phase 1 (crypto contract, schema, settings) and Phase 2's API, Go agent and SSH enrolment of new LBs (below) are implemented. Enrolling existing LBs over SSH (`server:enrol`), `token_rekey` and enrolment by code are too. The admin page *Servers → Cluster Nodes*, `cron:cluster` and MAIN's own FPM pools for the API are too. Phase 3 (authoritative telemetry, the 1 s liveness loop, MAIN endpoint changes) is too. Phase 4 has its command channel (RPCs and viewer kills) and root commands. Phase 5 (logs, stream state, content and the fanout's monitor feed as events) is too. Phase 6 has remote kills and viewer drops as commands, the connection store seam, the agent's connection registry, connection limits enforced on MAIN, and the connection digest with snapshots and seeding; admission and the agent's HLS reaper are not in yet. Phases 6–11 are not.
- **Date:** 2026-09-25
- **Plan:** `docs/superpowers/specs/2026-09-21-main-lb-api-communication-design.md` (MAIN ↔ LB API communication, revision 3 plus corrections).
- **Extension side:** `xcvm_core` ADR-002, "Cluster API: the extension's half of MAIN ↔ LB communication", cluster API version 1.

## Context

Load balancers today reach MAIN's MariaDB and Redis directly (the `db_grant` model) and MAIN reaches them through `/api` with the live-streaming password. The plan replaces both with a signed, encrypted HTTP API started by each LB's Go `xc_agent`. The cryptographic root lives in `xcvm_core`. PHP runs the protocol, and the agent is its other end. Three codebases must therefore produce the same bytes.

## Decisions

### Who owns which part of the wire

| Part | Owner | Where it is defined |
| --- | --- | --- |
| XCVM-SEAL-v1, XCVM-BOX-v1, token derivation, panel signature domain, tag registry and record classes, lease, pin, DR bundle | `xcvm_core` | ADR-002; vectors `tests/Support/cluster_vectors.json` (copied verbatim from the extension, generated from its Rust) |
| Canonical request/response serialisation and the request/response MAC | panel | `Core\Cluster\Crypto\Canonical`; vectors `tests/Support/cluster_canonical_vectors.json` |
| Node signatures (`X-XCVM-Node-Sig`, relay auth, file digests from an LB) | panel | `Core\Cluster\Crypto\NodeSig` (domain `xcvm-node-sig-v1`, never the panel's `xcvm-sig-v1`) |
| Relay and file tickets, `X-XCVM-Relay-Auth`, `X-XCVM-File-Digest` | panel | `Ticket`, `RelayAuth`, `FileDigest` |

The Go agent's `internal/clustercrypto` (XC_VM_Fanout) passes both vector files.

### Canonical form

```text
req_ctx = "xcvm-req-v1" ‖ u32(proto) ‖ lp(agent) ‖ lp(METHOD) ‖ lp(path) ‖ lp(query)
          ‖ lp(content_type) ‖ lp(content_encoding) ‖ lp(node) ‖ u64(epoch) ‖ u64(ts_ms) ‖ nonce[16]
res_ctx = "xcvm-res-v1" ‖ SHA-256(req_ctx) ‖ u32(status) ‖ lp(content_type) ‖ u64(ts_ms) ‖ nonce[16]
mac     = HMAC-SHA256(K_mac_up | K_mac_down, "xcvm-mac-v1" ‖ lp(ctx) ‖ SHA-256(body))
```

The rules that make the form canonical:

- The query string is percent-decoded (`+` becomes a space), re-encoded per RFC 3986, and sorted by key then value.
- The method is upper-cased. The content type and encoding are lower-cased and trimmed.
- `node` is a uuid, or `sid:<n>` before enrolment finishes.
- The request context is the BOX context in both directions, and the response context hashes the request in, so a reply cannot be moved onto another request.
- The window is ±90 s.

### Fail closed

`ClusterCryptoFactory::create()` throws `ClusterUnavailableException` when:

- `xcvm_core` or `XC_VM::cluster_session` is missing;
- `cluster_info()['api']` is outside `API_MIN..API_MAX` (currently 1..1);
- sodium or AES-GCM is unavailable.

The node then stays legacy and `db_grant` remains the gate. No PHP implementation of the extension's secret parts ships: the binding, token MAC, session keys and panel signing with a seed live in `tests/Support/ClusterReference.php`. `ClusterCryptoFailClosedTest` checks that nothing under `Core/Cluster` signs as the panel or computes tokens.

### Schema numbering

The plan numbers its Phase 1 migrations 026–032. 026 and 027 were already taken (`ssh_hostkey_sha1`, `fanout_enabled`), so they ship as 028–034. The contents are unchanged. The plan's Phase 10 `033_drop_connection_sync_timer` will get the next free number.

| Migration | Contents |
| --- | --- |
| 028 | the 19 settings |
| 029 | `cluster_nodes`, `cluster_node_epochs`, `cluster_meta` |
| 030 | `cluster_commands` |
| 031 | enrolment codes and requests |
| 032 | audit, nonces, reservations |
| 033 | `crontab.role` plus a disabled `cluster` row |
| 034 | `cluster_changes`, `cluster_stream_ver` |

### Settings

`Core\Cluster\ClusterSettings` holds the defaults and bounds. It lives in `Core/Cluster`, which ships to LBs, not in `Domain/Cluster`, which the plan strips from LB builds, so nodes can apply the same bounds. It clamps silently and refuses only where clamping would change intent:

- a taken or out-of-range port;
- a host name that is an IP;
- `https_required` without working HTTPS;
- enabling the API without the extension;
- `api` mode before the cutover.

### MAIN's API (Phase 2)

`Domain\Cluster\ClusterApi` serves `/cluster/v1/<op>` behind `Public/cluster/index.php`. It is transport-free and tested without a web server. `ClusterApiTest` runs every flow against a PHP fake of the extension's token half, and opt-in against a real test-hooks `xcvm_core` (`XCVM_CLUSTER_API_REAL=1`). All of it is MAIN only: the LB build strips `Domain/Cluster`, `Public/cluster` and `cluster:init`, and the LB nginx has no `/cluster/` route.

| Op | Method | Auth | Node state | Reply |
| --- | --- | --- | --- | --- |
| `health` | GET | none; works without the DB and with the API disabled | any | panel-signed (`hlt`): time, proto range, panel keys |
| `challenge?cn=` | GET | none | any | panel-signed (`hlt`): a single-use 32-byte challenge, licence state, policy |
| `enrol_complete` | POST | session + node signature, epoch 1 only | `enrolling`, before `enrol_deadline` (30 min) | BOX: state, mode, flows, gen, policy |
| `token_refresh` | POST | session + node signature | `active`, `quarantined` | BOX: the next epoch's sealed token |
| `enrol_code` | POST | `sid:<n>`, epoch 0: code MAC (K_req) + signature by the key being enrolled; body SEALed to the panel box key | none yet | MAC'd (K_res): `pending_approval` |
| `enrol_code_status` | POST | `sid:<n>`, epoch 0: code MAC (K_req) | the request's | MAC'd (K_res): the state; once approved, epoch 1 panel-signed (`pre`) |
| `token_rekey` | POST | node signature (epoch 0, no MAC), body SEALed to the panel box key, a challenge | `active`, once a minute | panel-signed (`pre`): a new epoch's sealed token |
| `hello` | POST | session | `active`, `quarantined` | BOX: state, mode, flows, proto, policy |
| `heartbeat` | POST | session | `active`, `quarantined` | BOX: state, mode, flows, `pending` |
| `commands` | POST | session | `active` | BOX: signed commands after `after_seq`, held up to `wait_ms` (≤ 20 s) |
| `ack` | POST | session | `active`, `quarantined` | BOX: `ok` (own commands only) |

A session request is checked in this order. Nothing is written, not even the nonce, before the MAC and, for token operations, the node signature have verified:

1. header syntax and the 8 MB body cap;
2. protocol range (426 `PROTO` carries min and max);
3. the ±90 s window;
4. the node (`sid:` identities are refused: they are only valid on the two code ops) and its revocation;
5. the extension's session for the named epoch (its refusals map to `NODE_REVOKED`, `LICENCE_INVALID`, `CLOCK` or `TOKEN_EXPIRED`);
6. the request MAC;
7. the node signature, verified with the key from the extension-sealed epoch record, never the DB row;
8. the nonce claim (401 `REPLAY`, with `retry_after_ms` when MAIN only cannot vouch for the nonce yet: see the second cluster bus increment);
9. the node state;
10. a bus permit, for `hello`, `config` and `conn_snapshot` (503 `RATE_LIMITED`);
11. opening the BOX.

Refusals are panel-signed (`den`) and name the node and the request nonce. `STARTING` without the extension is the one unsigned reply, and agents treat it as a transport error. While MAIN's cluster pools are starting, `STARTING` is panel-signed (see "The cluster pools").

Token epochs:

- Enrolment (`EnrolmentService::issueFirst`, called by the SSH install flow in a later increment) mints epoch 1.
- `token_refresh` mints the next epoch for the agent's per-epoch key. A retry with the same key gets the same unused token back. A retry with another key replaces the unused epoch under the same number. A node therefore never holds more than two valid epochs. Migration 035 adds `cluster_node_epochs.agent_eph_pub` for this.
- Re-enrolment and revocation raise the extension's generation floor before touching the database, so a restored row cannot open a session.

Re-key (`token_rekey`), for a node whose tokens have all expired while its keys are intact:

- The agent fetches `GET challenge?cn=<uuid>`, then sends `POST token_rekey` with `X-XCVM-Epoch: 0` and no `X-XCVM-Sig`, as there is no session. The body is XCVM-SEAL-v1 to the panel box key, purpose `rekey`, with the request context as the SEAL context. It carries the challenge, a fresh per-epoch X25519 key, and `instance_id`, `boot_id` and `agent_version`. The request is node-signed like `token_refresh`, with the enrolled key from `cluster_nodes`, as no epoch record is left.
- MAIN checks, in order:
  1. headers, protocol and window;
  2. the node exists and is not revoked;
  3. the node signature;
  4. the nonce claim;
  5. the node is `active`;
  6. a bus permit (503 `RATE_LIMITED` with `retry_after_ms` and `op`), so a busy MAIN spends neither the minute nor the challenge;
  7. the once-a-minute limit (429 `RATE_LIMITED` with `retry_after_ms`), charged per authenticated attempt;
  8. the SEAL opens (`cluster_open_sealed`);
  9. the challenge is live (180 s) and unused (401 `CHALLENGE`). Using it is a claim on `used:chal:<uuid>` (`NonceStore`), so two concurrent uses cannot both win;
  10. the attestation: an `instance_id` other than the enrolled one quarantines the node (409 `NOT_ACTIVE`, state `quarantined`);
  11. the mint. A licence refusal returns 403 `LICENCE_INVALID` and leaves the node's rows as they were.
- The new epoch follows the node's current epoch and any epoch row still held. Every other epoch row is dropped, so a re-key whose reply was lost leaves nothing behind.
- The reply is panel-signed with tag `pre`, a granting record the extension signs only under a valid licence. It names the node and the request nonce, and carries the token sealed to the agent's new key.
- Nodes installed from this release get `panel_box_pub` in their install data. Nodes enrolled earlier take it once from the signed `health` document.
- The agent re-keys when a session op is refused with `TOKEN_EXPIRED` (or `LICENCE_INVALID`, the hard revocation mode), or when it holds no epoch. While the challenge says `licence_ok: false`, or the node is quarantined, it asks again every 60 s. Otherwise it backs off with jitter. Only `NODE_REVOKED`, `UNKNOWN_NODE` and `ENROL_EXPIRED` stop it. A node whose first token expires before `enrol_complete` still stops, because MAIN re-keys active nodes only.

Other behaviour:

- `hello` from an active node with a different `instance_id` quarantines it. That is authenticated evidence of a clone.
- The first authenticated heartbeat sets `servers.status = 1`. Heartbeat telemetry is kept in shadow in `tmp/cluster/tel_<id>.json`.
- `cluster:init`, or enabling the API in Settings, creates the extension root and records the panel keys and `ready_at` in `cluster_meta`. Enabling it from Settings runs as php-fpm, so the files belong to the user that serves the API. Liveness counts silence from `max(last_seen_at, ready_at)`.
- Under `cluster_transport = auto`, HTTPS URLs appear in the policy only once the self-probe result is recorded. Until then, `auto` publishes HTTP URLs.

### Enrolling a new LB at install (SSH)

`LbInstallFlow::provisionCluster` runs at the end of an LB install, over the install's verified SSH session, when `cluster_api_enabled` is on and the extension is available. Otherwise the node stays legacy (mode 0), exactly as before.

1. MAIN pushes `xc_agent` from its cache. `console.php agent_binary` keeps one SHA-256-verified copy per arch in `bin/xc_agent/cache/`, taken from the XC_VM_Fanout release (`xc_agent-linux-<arch>`, the same tag as `xc_fanout`). LBs never download the agent themselves.
2. `xc_agent keygen` makes the node's Ed25519 and X25519 keys and the first per-epoch key on the node, and prints only the public halves and the SAS. MAIN recomputes the SAS and refuses keys that do not match it.
3. `xc_agent probe` checks MAIN's signed `/cluster/v1/health` from the node, with the panel key it received over SSH, at the URLs of the node's policy. If this fails, the install stops (status 4) before any token exists.
4. MAIN mints epoch 1 (`EnrolmentService::issueFirst`). A licence refusal stops the install with `CLUSTER_LICENCE_REQUIRED`.
5. `xc_agent install` opens the token with the per-epoch key and checks the panel's signature, node and server before saving `config/cluster/agent.json` (0600).
6. The agent starts (`bin/xc_agent/run.sh`) and finishes with `enrol_complete`.

A missing agent binary, for example when GitHub is unreachable and there is no cached copy, leaves the node legacy and does not fail the install.

`run.sh` is a flock-guarded respawn loop. `service` boot and the RootSignals cron keep it alive on enrolled nodes. The agent exits 3 when MAIN has stopped the node: it was revoked or is unknown, or its enrolment was never completed. An expired token re-keys instead. `run.sh` then writes `bin/xc_agent/stopped` and nothing restarts it until the node is enrolled again. The `/etc/xc_vm/cluster` root pin, which the root executor needs, arrives with Phase 4.

### Enrolling an existing LB (SSH)

`console.php server:enrol <id> <sshPort> --cred-file=<path> [--expect-hostkey=<sha1>]` runs the same `provisionCluster` on a node that is already serving, without reinstalling it. The credentials travel as they do for `server:install`: a 0600 file, read and then deleted. The command differs from the install flow in three ways:

- **No trust on first use.** The SSH host key must match `--expect-hostkey` or the fingerprint stored at install (`ssh_hostkey_sha1`). The admin reads the key on the node with `ssh-keygen -l -E sha1 -f /etc/ssh/ssh_host_ed25519_key.pub`.
- **The node must already run this release.** Its `bin/xc_agent/run.sh` must exist; update the node through the legacy `update` signal first.
- **A failure never marks the node failed.** It keeps serving the legacy way.

Re-enrolling stops the node's running agent before replacing its identity. The generation goes up, so every token of the previous identity stops working.

`cluster:reenrol` runs this path for many nodes in one go; see *Re-enrolling the fleet*.

### Enrolling by code (break-glass)

For a node MAIN cannot reach over SSH (NAT, lost keys). `Domain\Cluster\EnrolCodeService` owns it:

```text
code   = base32(u8 1 ‖ u32 sid ‖ u8 len ‖ main_url ‖ SHA-256(panel_sign_pub)[0:16] ‖ secret[16]), dash-grouped
K_req  = HKDF-SHA256(secret, salt = u32 sid, info = "xcvm/enrol-code/v1/req")
K_res  = HKDF-SHA256(secret, salt = u32 sid, info = "xcvm/enrol-code/v1/res")
lookup = SHA-256(secret)
```

1. `console.php cluster:enrol-code <id> [--url=http://host:port]` issues a code. MAIN keeps K_req and K_res sealed with `cluster_seal_local`, under a row MAC keyed by K_res, and never keeps the secret. There is one live code per server: a new one supersedes unused codes and pending requests. A code lives 30 minutes.
2. On the node, `xc_agent enrol <code>`:
   - fetches the signed `health` at the code's URL;
   - pins the panel key whose hash the code carries;
   - makes the node's keys;
   - sends `enrol_code` (MAC'd with K_req, signed by the new node key, body SEALed to the panel box key);
   - prints the SAS.

   The request's per-epoch key waits in `cluster_enrol_requests.agent_eph_pub` (migration 036).
3. `console.php cluster:enrol-approve <id> <SAS>` approves the request. Only at approval does MAIN run `startEnrolment` (raising the generation) and mint epoch 1. The approval is panel-signed (`pre`) and stored.
   - Five wrong SAS entries reject the request.
   - A licence refusal leaves it pending.
   - `--reject` rejects it outright.
4. The node polls `enrol_code_status` every 10 s. The approved reply is MAC'd with K_res and carries the panel signature. The node checks it, opens the token with its per-epoch key, and writes its state as the SSH install does. The agent then finishes with `enrol_complete`.

Other rules:

- A wrong code MAC records nothing: no nonce and no attempt. The attempts count only the admin's wrong SAS entries, so a code cannot be burned without its secret.
- Under a used code, other keys get a 409 `ENROL_CONFLICT` and an audit entry, never a silent replacement. The same keys again (a lost reply) are accepted as they stand.
- `xc_agent enrol` refuses to replace an identity that holds tokens unless given `-force`.
- The pin blob (`cluster_pin`) waits for Phase 4, as it does on the SSH path.

### Cluster Nodes page and housekeeping

*Servers → Cluster Nodes* (`cluster_nodes`, permission `servers`) is the admin side of the CLI commands, through `Domain\Cluster\ClusterAdmin`. It:

- lists enrolled nodes with state, liveness (`NodeHealth`), mode, epoch, token expiry, last heartbeat and agent version;
- shows code enrolments waiting for a SAS, with *Approve* (SAS field) and *Reject*;
- issues enrolment codes (optional MAIN URL; the code is shown once);
- revokes nodes.

Actions POST back to the page; admin sessions are `SameSite=Strict`. The deciding admin is recorded in `decided_by` and in the audit log.

`cron:cluster` runs every minute (migration 037 enables its crontab row). It deletes expired epochs, which erases their `z`, the replay cache and used challenges, unused expired codes, and decided requests after a day. It returns at once on load balancers, which get the crontab verbatim but not `Domain/Cluster`, and while the API is disabled.

### Telemetry (Phase 3)

Every agent samples its host each second (`clusteragent.Sampler`) and sends the latest sample in each heartbeat. The sample covers:

- CPU (user + system over user, nice, system and idle, as the watchdog measured it), cores and model, load;
- memory, disk of the deploy root, kernel and uptime;
- stream producers (ffmpeg, `xc_fanout remux`) and the xc_vm PHP-FPM worker pids;
- per-interface rates, totals and link speed.

What only PHP knows, nginx requests per second, the fanout daemon's status and the devices (next section), the LB's watchdog writes to `config/cluster/local.json`. The agent forwards that file while it is under 10 s old and at most 64 KiB, parsed as a JSON object and re-encoded.

For a node with the TELEMETRY flow on (mode ≥ 1, toggled per node on the Cluster Nodes page), `HeartbeatService` makes the sample authoritative:

- **Every 5 s:** `servers.watchdog_data`, `last_check_ago`, `requests_per_second` and `php_pids`; without the Redis handler, also `connections` and `users`, counted as the watchdog counted them. `toWatchdogData()` keeps the legacy `SystemInfo::getStats()` keys and order, plus `cpu_average_array` and `fanout`. `ClusterTelemetryTest` pins that against `getStats()`'s source. `network_interface` selects interfaces as before.
- **Every minute:** the `servers_stats` row the LB's `cron:servers` wrote.
- **Devices, GPUs and disk I/O:** from the node's `local.json` (next section).

The node learns its mode and flows from MAIN's authenticated replies. The agent writes them to `config/cluster/flows.json`, and `Core\Cluster\NodeFlows` reads them. With TELEMETRY on:

- the LB's watchdog stops writing its `servers` row and only refreshes `local.json`;
- `cron:servers` skips its `servers_stats` row;
- `network.py` is stopped.

A stopped agent removes `flows.json`, so the node falls back to the legacy paths.

### Telemetry (Phase 3, second increment): devices, GPUs and disk I/O

**Before.** `toWatchdogData()` reported `audio_devices`, `video_devices`, `gpu_info` and `iostat_info` empty. A TELEMETRY node showed 0 % I/O wait on the dashboard and its server page, and its `servers_stats` rows had no GPU or iostat history.

**The node.** Only PHP probes these, so the watchdog's `local.json` now carries them. The agent is unchanged: it already parses the file as a JSON object (at most 64 KiB, under 10 s old) and re-encodes it as `telemetry.local`. Key order and number formatting are not kept (object keys come out sorted, `7.0` arrives as `7`); MAIN reads keys, not order, and relies on no int/float distinction.

This differs from the plan, whose "What moves to Go" table moves the `SystemInfo` forks into the agent in Phase 3. These four probes stay in the node's PHP, every 30 s: the agent already forwards `local.json`, so no agent release is needed, and `getStats()` and the node keep one probe.

- **Probe.** `SystemInfo::getDevices()`: each section is `[]` unless its tool is installed (`iostat`, `nvidia-smi`, `v4l2-ctl`, `arecord`), as `getStats()` decided. `getStats()` now takes its four sections from it, so the two cannot drift apart. For `local.json` each tool runs under `timeout -k 1 5` (`LocalTelemetry::PROBE_TIMEOUT`), so a hung `nvidia-smi` cannot stall the watchdog while the agent keeps the node looking healthy. A tool cut short reports `[]` (`nvidia-smi`, `iostat`) or the devices it listed by then (`v4l2-ctl`, `arecord`). `getStats()` waits for its tools, as before.
- **Every 30 s.** The probes shell out, so `Core\Cluster\LocalTelemetry::refresh()` reuses a probe for 30 s. The watchdog runs one pass per process and re-execs, so the last probe is kept in `tmp/watchdog_devices.json` (`{"t": unix time, "devices": {…}}`), not in a variable. `local.json` is still rewritten every pass (about 3 s). When a probe is due, the file is written first with the last probe's sections and again after the probe, so a slow probe never ages it past the agent's 10 s. With no probe under a minute old (none, an unreadable cache, a clock that went back) the probe runs first, so an old probe is never reported as current.
- **Size.** The agent drops a `local.json` over 64 KiB, and with it the requests per second and the fanout status. `LocalTelemetry::encode()` keeps the file at most 60 KiB (61 440 bytes). Over that, it empties every GPU's `processes` list first, then the largest device section, one at a time. If that still does not fit, only `requests_per_second` and the four sections as `[]` are kept; `fanout` is dropped, so MAIN keeps its last value.

**The contract: `telemetry.local`.** Same heartbeat, same op, no new lane or event. The keys, all optional:

| Key | Type | Probe (legacy shape) |
| --- | --- | --- |
| `requests_per_second` | int | nginx `stub_status` |
| `fanout` | object or null | `FanoutClient::status()` |
| `audio_devices` | list of strings (`hw:CARD=…` names) | `SystemInfo::getAudioDevices()`, `arecord -L` |
| `video_devices` | list of `{name, video_device}` | `getVideoDevices()`, `v4l2-ctl --list-devices` |
| `gpu_info` | `{attached_gpus, driver_version, cuda_version, gpus: [{name, power_readings, utilisation, memory_usage, fan_speed, temperature, clocks, uuid, id, processes: [{pid, memory}]}]}` or `[]` | `getGPUInfo()`, `nvidia-smi -x -q` |
| `iostat_info` | `{"avg-cpu": {user, nice, system, iowait, steal, idle}, disk: [{disk_device, …}]}` or `[]` | `getIO()`, `iostat -o JSON -m` |

A device section is `[]` when its tool is absent, timed out, or a trim dropped it. An older node's PHP does not write the four keys. The whole object is at most 64 KiB encoded (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`); MAIN enforces that too (below).

**MAIN.** `toWatchdogData()` takes each section from `telemetry.local` when it is an array, else `[]`.

- **No `local`** (the file is stale, missing or too big, or the watchdog is down): every section is `[]`, not the last value, since stale figures would look live. `fanout` keeps its last value, as before.
- **Over 64 KiB.** MAIN does not trust the agent's cap alone, since `gpu_info` and `iostat_info` are kept in `servers_stats` for `servers_stats_retention_days`. A `local` whose encoding is over `HeartbeatService::MAX_LOCAL` (65 536 bytes) is treated as absent: the four sections `[]`, `requests_per_second` 0, `fanout` its last value. The heartbeat itself is never refused for it.
- **Shape.** Only what the panel reads is checked: `audio_devices` keeps its strings and `video_devices` its objects, both as lists; `gpu_info.gpus` keeps its objects; `iostat_info["avg-cpu"]` keeps its numeric figures, because the dashboard rounds `iowait`.
- **Where it lands.** Through the existing path: `watchdog_data` every 5 s, and `gpu_info` and `iostat_info` in the minute's `servers_stats` row. The dashboard's and the server page's I/O wait (`StatsAjaxController`, `server_view.php`, `ServerViewController`) now work for TELEMETRY nodes. The `servers` columns `gpu_info`, `video_devices` and `audio_devices` (GPU cards, profile editor, capture streams) already came from `node.inventory`.
- **Shadow copy.** `tmp/cluster/tel_<id>.json` now takes up to 128 KiB (`HeartbeatService::MAX_TELEMETRY`), because `local.json` alone may be 64 KiB.

**Cost.** Each heartbeat carries the sections although they change at most every 30 s: a few KiB as a rule, 60 KiB every 2 s per node at worst. An agent that sent `local` only when it changed would save that, but MAIN would then need to keep the last one; nothing does that yet.

`ClusterTelemetryTest` pins the mapping, the shape checks, the `servers_stats` columns, `refresh()` (the 30 s reuse across passes, the write before a due probe, the probe first without a recent one), the timeout wrapper, the node's size cap and its boundary, MAIN's 64 KiB cap and the shadow copy's room for it, and `local.json` as `LocalTelemetry` writes it, re-encoded as the agent does, read back by `toWatchdogData()`.

### Liveness (Phase 3)

`LivenessService::tick()` runs every second in MAIN's signals daemon, with `cron:cluster` as the per-minute fallback. It judges the active nodes whose TELEMETRY flow is on by their silence (`NodeHealth`):

| State | When | Effect on routing |
| --- | --- | --- |
| `ok` | heard within 10 s | online, whatever the legacy `last_check_ago` says |
| `suspect` | silent over 10 s | still online; capacity weight doubled |
| `offline` | silent over `cluster_offline_after_sec` (30 s) | offline |

Other rules:

- A node never heard from counts as offline only once the loop has been up for that long.
- The result goes to `tmp/cluster/health.json`. `Core\Cluster\ClusterHealth` is how `ServerRepository::getAll()` (`server_online`, `cluster_health`) and `ConnectionTracker::getCapacity()` read it.
- Each transition rewrites the servers cache at once and is audited (`node.health`).
- **Hysteresis** (`NodeHealth::settle`): a published state gets worse at once, but better only after 30 s of steady health (`NodeHealth::RECOVER_MS`). An offline node that speaks again is `suspect` at once, so routing resumes at half weight, and `ok` after the steady period. Without this, a node whose heartbeats straddle the 10 s threshold flips on every gap: 10 flips a minute at 11.5 s gaps, one with it (`NodeHealthHysteresisTest`). The steady period is longer than the suspect threshold on purpose; at 10 s such a node would recover between gaps and flap as often. Since when each node has been ok is kept in `health.json` (`ok_since`), so the period survives passes that publish nothing.
- **Fleet silence guard:** when over half of those nodes, and at least two, are silent together, MAIN suspects itself. It holds every node at its last published state instead of marking any offline. It audits `cluster.fleet_silence`, and the Cluster Nodes page shows an alert until the silence clears.
- Nodes without the flow keep the legacy 90 s rule. The Phase 6 orphan purge at `cluster_orphan_conn_ttl_sec` is not part of this loop yet.

### MAIN endpoint changes (Phase 3)

The case: MAIN's HTTP broadcast port changes on its server page, and the cluster API has no port of its own (`cluster_api_port` = 0). `ClusterEndpoint::recordChange()` then runs before the new ports are applied:

1. It bumps `cluster_policy_ver`. Heartbeat replies carry that version, and an agent that sees a newer one says hello again and gets the new URLs within about 2 s.
2. It keeps the old port for 7 days in `cluster_legacy_ports` (migration 038, with `cluster_policy_ver`).

While a port is kept:

- The policy lists it after the new URLs, so a node that was offline during the change still finds MAIN.
- The root-side `set_port` handler renders `bin/nginx/conf/cluster_legacy.conf` on MAIN: one server block per kept port, serving `/cluster/v1/` and a 404 for everything else. `nginx.conf` includes it by glob, so a missing file is no error. Since the third Phase 2 increment this is `cluster.d/old_port.conf`, rendered as xc_vm (see "The rendered nginx config").

When the 7 days are up, `cron:cluster` drops the port, bumps the policy again and re-applies MAIN's ports so nginx releases it. It now renders the nginx config again instead.

This was checked with nginx 1.24:

- `nginx -t` passes with and without the file.
- On the old port, only `/cluster/v1/` reaches PHP.

**Not built:** a change of MAIN's HTTPS broadcast port, `server_ip` or `private_ip` is not announced and keeps no old URL (plan §3, "Endpoint changes"). Only the HTTP broadcast port, `cluster_api_port`, `cluster_transport` and `cluster_main_host` bump `cluster_policy_ver`.

### Commands (Phase 4, first increment)

`CommandBus` queues MAIN → node commands in `cluster_commands`, FIFO per node by `seq`. Each command is a typed JSON record signed by the panel with tag `cmd`:

```text
{"v":1, "type", "exp", "iat", "cmd_id", "seq", "node_uuid", "gen", "dedupe_key", "args"}
```

The extension derives the class from `type`. Kills and stops are restrictive and sign without a licence; the rest need it. A `dedupe_key` replaces a not-yet-acked command for the same desired state. Commands expire (`conn.*` 5 min, `node.root` 24 h, default 10 min), and `cron:cluster` prunes them.

The flow, for a node whose COMMANDS flow is on (toggled per node on the Cluster Nodes page):

1. **Poll.** The agent holds a `commands` long-poll. Each poll holds a PHP worker on MAIN; the plan's bus replaces that later.
2. **Checks.** The agent checks each command: the panel signature under its pinned key, its own uuid and generation, `seq` above its persisted high-water `cmd_seq`, and `exp` on MAIN's clock.
3. **Run.** It runs the command through `console.php cluster:exec`. `cluster:exec` checks the signature again and runs `node.rpc{action}` with the legacy `/api` handlers (`InternalApiController::runCommand`, actions limited to `NodeRpc::ACTIONS`) and `conn.kill_worker`.
4. **Ack.** The agent `ack`s the command with the result (≤ 64 KB) and raises its high-water. MAIN raises `cluster_nodes.cmd_seq`.
5. **Refusals.** A refused command is acked as refused, with the reason.

On MAIN, `Domain\Cluster\ClusterRoute` sits behind the Phase 0 seams:

- `NodeRpc::request()` becomes a signed `node.rpc`, and MAIN waits for its ack up to the caller's timeout.
- `NodeRpc::broadcast()` and `SignalDispatcher::kill()` queue without waiting.
- Nodes without the flow, and every LB, keep the legacy transport.

### Root commands (Phase 4, second increment)

`NodeActions::send()` (reboot, update, service restarts and the other `NodeActions::ROOT_ACTIONS`) becomes a signed `node.root` command when the node takes it. Otherwise it stays on the signals table. The agent runs as `xc_vm`, and so does everything that can write its files, so root does not trust the agent's copy of the panel key.

- **Pin.** Root keeps its own copy in `/etc/xc_vm/cluster/`, owned by `root:root` and writable by nobody else:
  - `main_sign.pub` holds the panel signing key (hex).
  - `node` holds the node's uuid.
  - `root.seq` holds root's own high-water.
  - `LbInstallFlow` writes the pin over SSH right after enrolment. On a node enrolled by code, root runs `console.php cluster:pin-root <panel_fp>` with the fingerprint shown on the Cluster Nodes page. The command checks it against the key the agent received.
  - A new pin clears `root.seq`.
- **Hand-off.** `cluster:exec` does not run a `node.root` command itself. It writes `<seq>.json` (command and signature) into `config/cluster/root-inbox/` (xc_vm, 0700) and waits up to 5 s for `<seq>.done`. On a timeout it acks `{"queued":true}`.
- **Root side.** `cluster:root` runs from root's crontab every minute and watches the inbox for 58 s under a lock. For each file in `seq` order it:
  1. verifies the signature under the pin, `type` `node.root`, the node's uuid, `exp` (5 min grace), `seq` above `root.seq`, and the action against `NodeActions::ROOT_ACTIONS`;
  2. raises `root.seq` before running, so a crash cannot replay;
  3. runs the action through `RootSignalsCronJob::executeAction()`, the same code the signals path uses;
  4. writes the result exclusively (`fopen 'x'`, after removing anything planted at the path), so root never follows a symlink.
- **Readiness.** The agent reports `root_ready` in every heartbeat: the pin exists and matches its own panel key and uuid. MAIN stores it in `cluster_nodes.root_ready` (migration 039). It routes `node.root` only to nodes with the COMMANDS flow and `root_ready`. The Cluster Nodes page shows it.

### Logs and stream state (Phase 5, first increment)

A node whose LOGS or STREAMS flow is on stops writing its logs and stream runtime state into MAIN's database. The Phase 0 seams send them as events instead:

- **LB PHP.** `LogSink::write()` and `StreamStateWriter` redact first (`Redactor`), then hand the events to `Core\Cluster\EventSpool`. The spool holds one file per write under `config/cluster/spool/<lane>/`, written aside and renamed in. The lanes are:
  - `p0`: `stream.state`, never dropped;
  - `p1`: `log.<type>`, one event per `LogSink::CHUNK` rows.

  When the agent has not touched `flows.json` for 120 s (it does on every heartbeat), the spool refuses and the write falls back to SQL, so a stopped agent loses nothing.
- **Agent.** One loop per lane (P0 every 200 ms, P1 every 5 s) sends the oldest files to MAIN's `events` op, numbered from the lane's cursor (`useq`). Before each send it writes the batch's first number and file list to `<lane>.inflight`, so a crash or a lost reply resends the same files under the same numbers. Past 64 MB, P1 drops its oldest files and reports the count as a `skip` event, which is spooled so it survives a restart. `p0_reset` is not in yet.
- **MAIN.** `events` (`EventIngest`) applies a batch and the new cursor together:
  - P0 is gap-checked: a first number other than `useq_p0 + 1` gets a signed `409 USEQ_GAP {expected_useq}` and the agent renumbers.
  - P1 skips numbers at or below `useq_p1`.
  - A batch at or below the cursor is a repeat and applies nothing.
  - Every event applies as the sending node. `stream.state` merges only runtime-state columns into that node's own `streams_servers` row (`StreamRowMerge`), log rows get its `server_id`, and both are redacted again.
  - An event whose flow is off is dropped and counted.
  - `hello` returns the cursors.
- **Admin.** The Cluster Nodes page switches LOGS and STREAMS per node.

### Content (Phase 5, second increment)

The rest of what a node writes about its own content goes the same way, as P0 events through `Domain\Stream\ContentSink`. MAIN applies each event only where the node is the owner:

| Event | Flow | Written by | MAIN applies it when |
| --- | --- | --- | --- |
| `recording.state {id, status}` | CONTENT | `RecordCommand` | `recordings.source_id` is the node |
| `stream.worker {stream_id, worker, pid}` | STREAMS | `ArchiveCommand`, `ThumbnailCommand` (`tv_archive`, `vframes`) | `streams.<worker>_server_id` is the node |
| `vod.analysis {stream_id, props}` | CONTENT | `VodCronJob`, `CleanupCronJob` | the node holds the movie |

`vod.analysis` carries only the ffprobe keys (`duration_secs`, `duration`, `video`, `audio`, `subtitle`, `bitrate`), and MAIN merges them into its own `movie_properties`.

When an event changes a stream's routing state, MAIN writes the cache signal (`StreamProcess::updateStream`) itself. A node with STREAMS on no longer writes that signal into MAIN's database.

**Recordings.** A finished recording becomes one VOD through `Domain\Stream\RecordingFinalizer`. It works in two steps around the node's conversion to `VOD_PATH/<id>.mp4`:

1. `create()` makes the VOD row and its bouquets, and records it as `created_id`. It is idempotent.
2. `finish()` attaches the VOD to the node (pid 1, `to_analyze` 1) and sets status 2.

A legacy node runs both in-process, as before. A CONTENT node needs the id before it can convert, so it asks MAIN synchronously:

- **Agent socket.** The node calls the `recording_complete` op through the agent's local socket. The socket is `config/cluster/agent.sock`, mode 0660, and PHP reaches it through `Core\Cluster\AgentClient`. It serves `POST /v1/main/{op}` for an allowlist of ops, which is only `recording_complete` today. The plan puts the socket under `bin/xc_agent/sockets/`; it lives beside the agent's state instead, with the spool and `flows.json`.
- **Completion.** After converting, the node reports `recording.state` 2, and MAIN runs `finish()`.
- **Checks.** `recording_complete` needs the CONTENT flow, or MAIN answers `409 FLOW_OFF`. It answers only for the node's own recordings, and only takes an icon from the node's own image store.

The Cluster Nodes page switches CONTENT.

### Fanout monitor feed and P0 compaction (Phase 5, third increment)

**Fanout feed.** xc_fanout publishes its supervised streams' monitor transitions on `GET /events?boot=&since=&wait=` on its control socket:

- A watcher compares states every 250 ms. It ignores the counters that move on their own (uptime, the sampled bitrate).
- The last 4096 transitions are kept in a ring.
- A request is held up to 25 s when there is nothing new.
- A consumer that is new, behind the ring, or on another daemon life (`boot`) gets `reset` and a full snapshot.
- Viewer open and close join the feed in Phase 6.

**Agent side.** While STREAMS is on, the agent follows the feed (`-fanout-ctl`) and spools each transition as a P0 `stream.monitor {stream_id, state}` event.

- Before spooling, it redacts `source` and drops `last_error`.
- It names spool files on `CLOCK_MONOTONIC`, the clock of PHP's `hrtime()`, so the agent's files and PHP's sort together.
- Once the feed answers, the agent adds `"features": ["fanout_events"]` to `flows.json`. `NodeFlows::agentHas()` reads it, and the node's `reconcileSupervised` then stops writing that state. It still releases streams that nothing should produce.

**MAIN side.** MAIN derives the row from `stream.monitor` with the same pure rule PHP uses (`StreamProcess::supervisedRowUpdate`), against its own copy. It leaves a row the panel has stopped untouched and refreshes the stream cache on a change. Transitions reach MAIN within a poll step, not the reconcile's cadence.

**P0 compaction.** P0 is never dropped. Past 128 MB, the agent collapses the backlog instead, keeping the latest state per key:

- `stream.state` per row, with its fields merged;
- `stream.monitor` per stream;
- `stream.worker` per stream and worker;
- `recording.state` per recording;
- `vod.analysis` per movie, with its props merged.

Other event types are kept as they are, in order. The result replaces the oldest file, so it still goes first. MAIN applies it like any batch, so the plan's separate `p0_reset` event is not needed.

### Security blocks (Phase 5, fourth increment)

**Node side.** An LB's flood and bruteforce guard (`Core/Auth/BruteforceGuard`) still blocks the IP locally at once, with its `block_<ip>` file. Where it wrote the block to MAIN's `blocked_ips`, it now spools a P0 `security.block_ip {ip, reason}` once the node's CONFIG flow is on. CONFIG is the flow that makes the blocklist MAIN's. With the flow off, the agent stopped, or on MAIN itself, the guard writes the row as before.

**MAIN side.** `EventIngest` records the block in `blocked_ips` with the guard's reason, as the node used to, and audits it (`security.block_ip`). Every node picks it up with the blocklist. MAIN refuses and audits (`security.block_ip_refused`):

- a reason that is not one of the guard's own (`BruteforceGuard::REASON_PATTERN`), or an address that is not an IP;
- an address the guard never blocks: a cluster server's `server_ip`, `private_ip` or `whitelist_ips`, the admin allowlist (`allowed_ips_admin`), or loopback. A node therefore cannot lock the cluster out.

An address that is already blocked is accepted and left as it is.

**Not built:** the blocklist delta in `cluster_changes`. No block or unblock path writes it yet; it comes with the R1 replica (`ReplicaBuilder`, Phase 7), which must cover every path at once, MAIN's auto-unban included.

### Node state and inventory (Phase 5, fifth increment)

**Node side.** What a node writes about itself in its own `servers` row goes through `Core/Cluster/NodeStateSink`. With the TELEMETRY flow on it becomes an event; otherwise the row is written as before.

- `node.state {fields}`, on P0, when it changes: `certbot_ssl` (certbot command and cron), `governor` and `sysctl` (`cron:root_signals`).
- `node.inventory {fields}`, on P1, once a minute from `cron:servers`: `remote_status`, `xc_vm_version`, `server_hardware`, `governors`, `sysctl`, the devices, `gpu_info`, `interfaces` and `ping`. A newer inventory replaces an older one, so P1's drop-oldest cap costs nothing. P1 serves in place of the plan's P2; the P2 lane, built in the tenth Phase 6 increment, takes only `conn.touch` so far.

The plan names the second event `inventory`; it is `node.inventory` here, next to `node.state`.

**Never the node's.** Columns that grant or route stay with MAIN and the admin, and MAIN refuses them in either event:

- `whitelist_ips`, which feeds the allowed IPs (`/api`, the internal endpoints, the flood exemptions). The legacy cron wrote the node's interface addresses there; with TELEMETRY on the cron stops, and the column keeps what the admin or the last legacy write left.
- `server_ip`: `cron:root_signals` still auto-updates it with a direct write, and only while the node reaches MAIN's database.
- `status`, which the heartbeat owns.

**MAIN side.** `EventIngest` writes only the event type's columns of the sending node's own row. Each value must be a scalar of at most 256 KB. An inventory also sets `time_offset` from the node's heartbeat clock offset, which is what the legacy cron measured against the database clock.

**Root.** `cron:root_signals` and the certbot cron run as root. `EventSpool` hands a lane or file that root creates to the owner of the agent's state directory, so the agent can still read, compact and delete it.

**`stream.progress`.** No separate event is built. `progress_info` already reaches MAIN in `stream.state`: `cron:streams` sends it at most once a minute per stream, and MAIN treats it as cache-neutral. A created channel's encoding progress, every 10 s while it encodes, is the one faster writer. P0 compaction keeps one state per row, so none of it can grow the backlog. It stays in `stream.state` now that the P2 lane exists (tenth Phase 6 increment); it can move there as a type of its own.

### Connections (Phase 6, first increment): kills as commands

Every kill MAIN sends to another node's viewers now travels as a signed command when that node takes commands. Before this, only `SignalDispatcher::kill` in MySQL mode did.

- **Kills from `ConnectionTracker::redisSignal`.** In Redis mode, a worker pid becomes `conn.kill_worker`, and so does an RTMP client (`rtmp: true`). A daemon viewer's `drop_con` becomes `conn.drop {uuid}`. This covers every caller: `ConnectionTracker::closeConnection` and `ConnectionLimiter`'s kicks. Before, all of these went through `SIGNALS#<sid>` in MAIN's Redis.
- **Drops from `dropDaemonViewer`.** In MySQL mode, the drop of a viewer on another node also becomes `conn.drop`, instead of a `drop_con` row in `signals`.

**On the node.** `conn.drop` is restrictive, so it is signed without a licence. Repeat drops for the same viewer supersede each other through `dedupe_key`.

The agent runs `conn.drop` in its own process: a `DELETE /connections/<uuid>` on the fanout's control socket. A 404 is acked as "not connected here". When the agent cannot reach the fanout, `cluster:exec` does the same through `FanoutClient::dropConnection`. A kill reaches the node within a poll step of the `commands` long-poll.

### Connections (Phase 6, second increment): the connection store seam

The stream endpoints (`live.php`, `vod.php`, `timeshift.php`, `rtmp.php`) now reach the connection store only through `ConnectionTracker`. Before, each of them read and wrote `lines_live` or Redis inline. The seam's operations:

| Operation | What it does |
| --- | --- |
| `openRecord($settings, $record, $dbRow)` | Records a viewer. `$record` is the Redis record; `$dbRow` holds exactly the `lines_live` columns each caller wrote before. VOD still leaves `external_device` NULL, and RTMP still writes the node's own `date_start` on the table path. `createLive` goes through it too. |
| `findByUuid($settings, $uuid, $columns, $fallback)` | Finds a viewer by uuid. On the table path, an HTTP Range request without the uuid falls back to matching line (or HMAC key), container, agent and stream. |
| `updateLive` | Refreshes and re-opens a viewer (unchanged). |
| `heartbeat($settings, $uuid, $lastRead)` | The long-running viewers' five-minute check-in. |
| `acceptedIP($settings, $lineID)` | The IP the first open connection of a line came from (`disallow_2nd_ip_con`). |

It is a refactor: no store changes behaviour, as `ConnectionStoreTest` pins on both `lines_live` and a real Redis. The seam resolves the database through the current handle (`DatabaseFactory::get()`), the one the endpoints' global `$db` holds between `connectLazy()` and `close()`. A handle injected at boot may already be closed. Here, the next increment swaps in the node's agent as the store for nodes with CONNECTIONS on.

### Connections (Phase 6, third increment): the agent's connection registry

On a node whose CONNECTIONS flow is on, the agent holds the node's viewers, and the connection store seam reads and writes them there. CONNECTIONS needs COMMANDS and STREAMS, and the Cluster Nodes page refuses it without them.

**Seam.** The seam's methods go to the agent: `openRecord`, `findByUuid` (with the Range fallback), `lookupLive`, `updateLive`, `heartbeat` and `acceptedIP`. They use `Core\Cluster\AgentConnections` over the local socket, with a 1 s timeout. The stream endpoints make no WAN call for their viewers any more. When the agent does not answer, the call falls back to MAIN's store, so a viewer is never held up by the agent. `lookupLive` applies the same owner, server, container, stream and open checks the table path's query does.

**Agent.** The agent's `Registry` holds the records, in ConnectionTracker's Redis record shape, and keeps a snapshot in `config/cluster/registry.snap`. It serves `/v1/conn/...` on the local socket. It mirrors every change to MAIN as P0 events, spooling the event before changing the record:

- `conn.upsert {record}` for a new or changed connection. A change of `hls_last_read` alone goes at most every 10 s, inside the 30 s after which MAIN's reaper closes an HLS viewer.
- `conn.remove {uuid}` when the node removes a connection.

**MAIN.** MAIN keeps its store current from these events, in Redis or `lines_live` as `redis_handler` says (`Domain\Cluster\ConnectionIngest`). The reaper, the limits and the admin read what they always read. A node writes only its own connections: `server_id` is the sender, the line identity is recomputed from the record's owner, and a uuid another node holds is refused.

**Closes.**

- **Decided on MAIN** (a kick, a limit, MAIN's reaper): the close reaches the node as `conn.close {uuid, remove}`, which is restrictive and deduplicated per viewer. The agent applies it to its registry in-process. Without this, the player's next playlist request would resume a kicked HLS viewer from the node's registry.
- **Made by the node itself** (its reaper, in MySQL mode): the node still writes MAIN's store directly, and tells its registry with `POST /v1/conn/{uuid}/close`, which sends no event.

**Known gap.** An upsert already in flight when MAIN closes the same viewer can re-open it in MAIN's store. The node's registry holds the viewer as ended, so its next request starts a new connection, with the token's checks. Admission, snapshots with digests and the agent's HLS reaper are the next increments.

### Connections (Phase 6, fourth increment): limits on MAIN

A node whose CONNECTIONS flow is on does not run `ConnectionLimiter` against MAIN's store any more. That would be a WAN round trip on every viewer's open, and it could evict the viewer that just opened.

**Node.** When a viewer opens with a limit, `StreamAuth::validateConnections` spools a P0 `conn.limit` event after the viewer's `conn.upsert`. For a line it carries `{uuid, ip, user_agent, user_id}`. For an HMAC identity it carries `{uuid, ip, user_agent, hmac_id, hmac_identifier, max_connections}`. If the spool refuses (for example, a stale `flows.json`), the node enforces the limit itself, as before.

**MAIN.** `EventIngest` accepts `conn.limit` only from a node with CONNECTIONS. It queues the check as a file in `TMP_PATH/cluster_limits/` (`Domain\Cluster\ConnectionLimits`), so the events op returns at once. `cron:signals` drains the queue on its 1 s loop, and runs each check with these rules:

- The viewer must be in MAIN's store under the sending node, with the same owner. Otherwise the check is dropped: the viewer is gone, or it is not that node's.
- A line's limit is read from `lines`, never from the event. An HMAC identity's limit is the node's, because MAIN has no row for it.
- `StreamAuth::validateConnections` then runs on MAIN, with the viewer's IP. It closes the owner's oldest connections, preferring the requesting device, as it does on a legacy node.

A viewer over its limit is closed within about 1–1.5 s of opening on another node. The viewer that just opened is not the one evicted.

**Closes reach the node.** `ConnectionLimiter::closeConnection` also sends `conn.close {uuid, remove}` when the viewer is another node's and that node has CONNECTIONS. This covers any close made by the limiter, on MAIN or from another path. An ended HLS viewer is kept as ended (`remove: false`); anything else is removed. Without this, the node's registry would resume a kicked HLS viewer on the next playlist request.

Admission when the token is minted (reservations) is not in this increment.

### Connections (Phase 6, fifth increment): digest, snapshot and seed

MAIN's store for a CONNECTIONS node can drift from the node's registry. Causes include an event lost to a bug, an upsert that re-opens a viewer MAIN closed, or a registry restored from an older `registry.snap`. The digest finds a drift, and a snapshot repairs it.

**Digest.** Both sides compute it over the open connections (`hls_end` not set), in `Domain\Cluster\ConnectionDigest` and the agent's `Registry.Digest`. Both pin one test vector. The digest is `{count, users, xor64}`:

- `count`: the number of open connections.
- `users`: the number of distinct owners. An owner is `u:<user_id>`, or `h:<hmac_id>:<hmac_identifier>` for an HMAC identity.
- `xor64`: the XOR of the first 8 bytes of SHA-256(`uuid` "\n" owner), as 16 hex digits.

**Heartbeat.** An agent whose CONNECTIONS flow is on adds `conn_digest` to every heartbeat. MAIN compares it with its store for that node at most every 4 s. It answers `want_conn_snapshot` only when two checks in a row disagree, because events in flight catch up well within that. It asks one node at most once every 30 s. The check's state is a small file per node in `TMP_PATH/cluster_digest/`.

**Snapshot.** The agent sends its whole registry with the `conn_snapshot` op, `{snap_id, seq, last, records}`. The registry goes in chunks of 1000 records, numbered from 0, up to 50 chunks. MAIN keeps the chunks in `TMP_PATH/cluster_snapshots/<sid>/` and applies nothing until the last one arrives. Then:

- every record is upserted as `conn.upsert` would be;
- every open connection MAIN holds for the node that the snapshot lacks is removed;
- records that are not the node's are dropped, as at ingest.

A chunk 0 starts over. Any other chunk out of order gets `409 SNAP_GAP {expected_seq}`, and the agent drops that snapshot; MAIN asks again if the drift stays. The op needs CONNECTIONS (`409 FLOW_OFF`), and each snapshot applied is audited as `conn.snapshot`.

"Applied as a whole" means MAIN changes nothing before the last chunk. The apply itself is a sequence of store writes, not a transaction. Events the node spooled before the snapshot can land after it; they carry older or equal state, and a drift they leave is caught by the next check.

**Seed.** `console.php cluster:seed-connections` runs on the node while it still reaches MAIN's store, before the CONNECTIONS flow is switched on. It loads the node's connections from MAIN's store (Redis `SERVER#<sid>`, or `lines_live`) into the agent through `POST /v1/conn/seed`. The first chunk empties the registry, and loading sends no event. Only the registry record's keys are sent (`AgentConnections::RECORD_KEYS`), never the line's other columns. After the switch, the first digest agrees and no snapshot is needed.

Still to come in Phase 6: rebuilding the registry from the fanout and the HLS markers after an agent restart. Until then, a restarted agent has only `registry.snap`. A snapshot makes MAIN's store match the registry, not the other way round, so viewers missing from an older `registry.snap` drop out of MAIN's store. An HLS viewer is recorded again on its next playlist request. A TS viewer the fanout serves is not counted toward its line's limit until the registry is rebuilt from the fanout.

### Connections (Phase 6, sixth increment): the agent's HLS reaper

**The problem.** An HLS viewer has no worker to watch, only its playlist requests. The legacy reaper (`UsersCronJob`) ends one 30 s after its `hls_last_read`. On a CONNECTIONS node, that time reaches MAIN only in the agent's upserts, at most every 10 s. A slow or cut link to MAIN would therefore end viewers who are still watching.

**On the node.** The agent's `Registry.Reap` runs every 5 s while CONNECTIONS is on:

- It ends an open HLS viewer that has made no playlist request for 30 s: `hls_end` 1, sent as a P0 `conn.upsert`, spooled before the registry changes.
- The time is the node's own: when a request last changed the record's `hls_last_read`. A clock step does not end anyone, and neither does MAIN being out of reach.
- After a restart, every viewer loaded from `registry.snap` gets a full 30 s window.
- A request after the end re-opens the viewer, as before.

**Telling MAIN.** The agent says `features: ["hls_reaper"]` at hello, and MAIN keeps it in `cluster_nodes.features` (migration 041). An older agent says nothing, so MAIN keeps doing everything itself. The agent also writes `hls_reaper` into `flows.json`.

**MAIN's reaper.** `UsersCronJob` asks `Core\Cluster\HlsReaping`. For an active node in mode ≥ 1, with CONNECTIONS on and the feature:

- the 30 s rule is off;
- only what the node ended (`hls_end` 1) is closed, with the usual activity row and a `conn.close` back to the node.

An LB that reaps its own rows (MySQL mode) asks its own agent through `NodeFlows` instead.

**Orphans.** A node that falls silent would keep its viewers counted against their lines forever, so it is orphaned once both of these hold:

- it has been silent for `cluster_orphan_conn_ttl_sec`, counted from `max(last_seen_at, cluster_ready_at)`;
- MAIN's reaper has itself watched it stay silent that long (`TMP_PATH/cluster_orphans.json`).

A gap of more than 3 minutes between reaper passes restarts the watch, and so does the fleet silence guard, so MAIN's own downtime never orphans a node (see "Acceptance tests that found gaps").

**The orphan purge.** Every CONNECTIONS node is watched this way, whether or not its agent reaps. An orphaned node's rows, HLS and TS alike, are purged from MAIN's store only (`ConnectionIngest::purgeNode`, audited as `conn.orphan_purge`), so they stop counting toward their lines' limits. The purge sends no kill and no command: the node's registry still holds its viewers. If the node comes back, its digest disagrees and a snapshot restores them. Before this, a dead node's TS rows stayed for ever, because the reaper kept trusting the node's last `php_pids` list, and it skips daemon-served rows (pid 0) altogether.

**Touches.** Touches still reach MAIN every 10 s, because a panel that predates this reaps by the 30 s rule. Moving them to the bus (`conn.touch`, every 60 s) waits for the bus. `conn.divergence` is not built: divergence still reaches `lines_divergence` the legacy way. (Both were built in the tenth increment.)

### Connections (Phase 6, seventh increment): admission when the token is minted

MAIN now applies a line's limit when it mints the stream token, before the viewer reaches a node (`Domain\Cluster\ConnectionAdmission`, called at the six viewer mint sites in `Public/stream/auth.php`). Thumbnails and subtitles are not admitted.

**When it applies.** All of these must hold:
- the cluster API is on;
- the line or HMAC identity has a limit;
- the node that will record the viewer is active, in mode ≥ 1, with CONNECTIONS on. Behind a proxy, that node is the originator.

Other targets are unchanged: a legacy node limits at open, as before.

**What it does.**
1. **Reserve.** The viewer's uuid is reserved for the identity for the token's life (`create_expiration`) plus 10 s, and the identity's other reservations still in flight are counted.
   - **The cluster bus**, when MAIN runs it: a Lua script on `RESV#<identity>`, in either store mode.
   - **Without the bus, Redis mode:** the same script on the shared Redis.
   - **Without the bus, MySQL mode:** `cluster_reservations`, the table migration 032 created for this.
   - **No lock:** insert-then-count needs none, because of two concurrent mints at least one sees the other.
2. **Evict.** `ConnectionLimiter::closeConnections` cuts the identity's open connections, and the pair's, to leave room for this viewer and the ones in flight. The order is the limiter's: the requesting device first, then the oldest. The new viewer is never evicted, because it is not open yet. Closes on CONNECTIONS nodes go out as commands, as every close MAIN makes does.
3. **Release.** When the node reports the connection (`ConnectionIngest::upsert`), the reservation is released.

**The node's `conn.limit` stays.** It is the re-check that settles a race between two nodes. After admission it normally finds nothing to do.

**Failures.** Admission never refuses a viewer and never fails a request. When the store or the registry cannot be read, it does nothing, and `conn.limit` enforces the limit once the viewer opens.

**Not built:**
- the `adm` claim in the token;
- the `conn_admit` op, with `lb_offline_admission`, for tokens minted without admission.

A CONNECTIONS node already makes no WAN call for limits: it spools `conn.limit`. So these matter only when the cluster bus replaces MAIN's store. The ninth increment builds both on the panel.

### Connections (Phase 6, eighth increment): TS closes from the fanout

**Before.** Under X-Accel, no PHP worker sees a daemon-served TS viewer leave. On a CONNECTIONS node, that close waited for `FanoutSyncCommand`, which reads MAIN's store over the WAN and closes rows the fanout no longer holds.

**The fanout.** It now publishes `conn_close {stream, uuid}` on `GET /events` when a uuid's last connection leaves a stream, whether the client went or the panel dropped it.

**The agent.** While CONNECTIONS is on, it follows that feed:
- **Check.** It confirms against `GET /connections` that the uuid is really gone, since a viewer may have reconnected with the same uuid.
- **Close.** For each open, non-HLS registry record with pid 0, it spools a P0 `conn.close {uuid}` and then drops the record. A spool that refuses leaves the feed where it was, so the events come again.
- **Scope.** PHP-served viewers have a worker to watch, and HLS viewers have the reaper, so neither is touched.

**MAIN.** `ConnectionIngest::close` closes the node's own row as `fanout_sync` would, with its activity row. It sends no kill and no `conn.close` back, because the viewer is already gone and the registry has already dropped it. A row that is already gone is accepted.

**Compatibility and safety net.**
- An older panel drops the unknown event, so its lane still advances.
- An older fanout sends nothing.
- `FanoutSyncCommand` keeps running as the safety net, for closes a feed reset loses and for when the fanout cannot answer.

The close reaches MAIN within about a second, with no WAN read from the node.

### Connections (Phase 6, ninth increment): the `adm` claim, `conn_admit` and the offline policy

This increment builds the panel half of plan section 8, "Global `max_connections` and kills", steps 4 to 6. The agent half is specified under **The agent's contract** below and is not built yet.

**The claim.** A token minted after admission applied carries `adm: {exp, sid}`. `ConnectionAdmission::admitToken` adds it at the six viewer mint sites in `Public/stream/auth.php`.
- `exp` is when the reservation expires: MAIN's unix seconds, `create_expiration` + 10 s after the mint.
- `sid` is the node the reservation was made for: the originator behind a proxy, else the redirect target.
- The reservation's id is the token's own `uuid`.

The token is sealed with MAIN's stream secret, so a node trusts the claim once the token opens. With `secure_stream_tokens` off, the token is the legacy AES-CBC format with no MAC, and the claim is exactly as forgeable as the credentials beside it. The node's `conn.limit` re-check follows every open either way.

A token without a claim was minted before this, or where admission did not apply: an unlimited line, a target without CONNECTIONS, or a store that could not be reached.

**The node's PHP.** A new viewer is registered through `ConnectionTracker::openRecord`. live.php, vod.php and timeshift.php now pass it the token and the node's `time_offset`. On a CONNECTIONS node, `AgentConnections::register` adds an `X-XCVM-Admission` header to `PUT /v1/conn/{uuid}`, built by `AgentConnections::admission`.
- **Why a header.** An agent that predates it ignores the header, and the record it stores stays the record.
- **When.** Only a new viewer with a limited token (`max_connections` > 0) sends it, and only while `flows.json` says the node is `active`. Refreshes (`updateLive`), RTMP and endpoints without a token do not. A quarantined node sends none: MAIN mints it no claim and answers its `conn_admit` with `NOT_ACTIVE`, so its viewers are admitted without asking, as before this increment.
- **Timeout.** Such a register waits 2.5 s (`ADMIT_TIMEOUT`) instead of 1 s, since the agent may ask MAIN for up to 1.5 s.

PHP reads the agent's answer as follows:
- **200:** admitted. This is what every older agent answers.
- **403 `{admit: false, reason}`:** refused. Nothing is recorded, in the registry or in MAIN's store. A reason that is not `[A-Z_]{1,32}` reads as `REFUSED`. live.php (HLS and TS), vod.php and timeshift.php then refuse the viewer through `StreamAuth::refuseAdmission`, the way auth.php refuses the same condition at mint (`StreamAuth::admissionRefusal`). The client log's data is `admission: <reason>`.
  - `EXPIRED`: `USER_EXPIRED` and the expired video.
  - `BANNED`: `USER_BAN` and the banned video. `DISABLED`: `USER_DISABLED` and the banned video.
  - `UNKNOWN_LINE`, `UNKNOWN_HMAC`: `AUTH_FAILED` and `INVALID_CREDENTIALS`.
  - `LIMIT`, `OFFLINE`, `REFUSED` and any other reason: `USER_ALREADY_CONNECTED` and the "connected" video, as live.php refuses a line already connected elsewhere.
  - Without the video, each falls back as `OffAirHandler::showVideoServer` does: `EXPIRED`, `BANNED` or a 404.
- **Anything else, or no answer:** the viewer goes to MAIN's store, as when the agent is down.

**HLS.** live.php records an HLS viewer under its playlist key, not the token's uuid. So the reservation made at mint was never released, and it counted against the line until it expired. When the token has a claim and the two uuids differ, the record now names the reserved uuid as `adm_uuid`. `ConnectionIngest::upsert` releases it with the viewer. `adm_uuid` is not a store column; the agent stores and mirrors it like any other record key.

**MAIN: `conn_admit`.** Admission for a viewer whose token has no claim, or an expired one (`ConnectionAdmission::forNode`).
- **Transport.** `POST`, ctl lane, session auth (MAC and BOX, no node signature). The node must be `active` (409 `NOT_ACTIVE`) with CONNECTIONS on (409 `FLOW_OFF {flow: "connections"}`). A malformed request gets 400 `BAD_REQUEST`, and a database MAIN cannot read gets 503 `DB`.
- **Request.** `{uuid, line_id | hmac_id + identifier, stream_id, ip, ua}`, with exactly one identity; the exact fields are under **Wire** below. MAIN reads the line itself and ignores any limit the node sends.
- **Refused:** a line auth.php would refuse before it mints, checked in auth.php's order: `UNKNOWN_LINE`, then `EXPIRED` (`exp_date` not after now), `BANNED` (`admin_enabled` 0), `DISABLED` (`enabled` 0). An HMAC key that is unknown or disabled gets `UNKNOWN_HMAC`. Bouquets, allowed IPs and agents, countries and ISPs are not checked again: auth.php checked them before it minted the token.
- **Admitted:** everything else, since admission never refuses a valid viewer. A limited line is reserved for the authenticated node, as at mint. The cut that makes room for the viewer is queued (below), and the viewer is never cut. An unlimited line needs no reservation. An HMAC identity is reserved but not cut, because its limit is signed into the client's request and stored nowhere. The node's `conn.limit`, which carries that limit, enforces it. A reservation store that cannot be reached reserves nothing and still admits.
- **Idempotent.** A repeated uuid refreshes its own reservation and gets the same answer.

**Wire.** The request is the BOX'd JSON object:
- `uuid`: string, `[A-Za-z0-9_-]{1,64}`, the PUT's uuid. In MySQL store mode without the cluster bus, a uuid longer than 32 characters is admitted but not reserved (`cluster_reservations.id` is `char(32)`, the size auth.php mints).
- Exactly one identity:
  - `line_id`: int > 0; or
  - `hmac_id`: int > 0 with `identifier`: string. MAIN keeps the identifier's first 255 bytes, as `conn.limit`'s queue does.
- The other identity's id is absent, `null` or `0`, so a Go struct without `omitempty` works. `identifier` is ignored beside `line_id`. A string where an int belongs (`"42"`), both ids > 0, or neither is 400 `BAD_REQUEST`.
- `stream_id`: int ≥ 0, optional (0).
- `ip`: string, optional (""). MAIN keeps its first 64 bytes.
- `ua`: string, optional (""). MAIN keeps its first 512 bytes.
- Any other key, `max_connections` included, is ignored.

The reply is BOX'd with the session keys, status 200:
- `admit`: bool;
- `exp`: int, MAIN's unix seconds until which the reservation holds, 0 when refused;
- `reason`: string, only when refused;
- `main_time_ms`: int.

The denials are signed and name the node and the request's nonce: 409 `NOT_ACTIVE`, 409 `FLOW_OFF {flow: "connections"}`, 400 `BAD_REQUEST` and 503 `DB`. The session denials of every ctl op, such as 401 `BAD_MAC` or `TOKEN_EXPIRED`, also apply.

**The cut is queued.** The cluster endpoint has none of the legacy globals `ConnectionLimiter` needs (`SERVER_ID`, `$rServers`), and the ctl lane must answer within 1.5 s. So `conn_admit` queues the cut in `conn.limit`'s queue (`ConnectionLimits::queueAdmission`, written by MAIN only), and `cron:signals` runs it within about a second (`ConnectionAdmission::cut`).
- The limit and pair are read from `lines` again when the cut runs.
- The room is the limit, less the line's other reservations still in flight when the cut runs (`ConnectionAdmission::inFlight`, in the store `reserve` wrote to), less one for the viewer. A viewer that has opened by then is already counted among the open connections, so it takes no extra room. An ended record under its uuid, such as a closed HLS key, is not open.
- The reservations are counted when the cut runs, not at admission. A reservation counted at admission may open before the cut runs: ingest releases it, and it is then one of the open connections. A count kept from admission would take it twice and evict a viewer within the limit.
- A store that cannot be read cuts nothing, as at mint. `conn.limit` follows the open.
- A uuid that MAIN's store holds for another node drops the cut.
- The queued check is marked `admission: true`. `ConnectionLimits::queue`, which takes a node's `conn.limit`, rebuilds the check from its own keys, so a node cannot queue an admission cut, which skips the owner check.

**Delivering the policy.** The hello and heartbeat replies carry `offline_admission` (`local`, `allow` or `deny`) at the top level, normalised from `lb_offline_admission`. A change reaches every node within one heartbeat, without the CONFIG flow or a replica. It is not in `policy`: that object is versioned by `policy_ver`, which only endpoint changes raise.

**The agent's contract.** For the Go half, not built yet:
1. **The register.** `PUT /v1/conn/{uuid}` keeps its body (the record) and its 200 answer with the record. The new request header `X-XCVM-Admission` is a compact JSON object, ASCII-only (non-ASCII is `\u`-escaped), with no CR or LF:
   - `adm` (optional): `{exp: int, sid: int}`. PHP passes it only when `exp` is not past on the node's clock corrected by `time_offset`, and when `sid` is the record's `server_id`.
   - `line_id: int`, or `hmac_id: int` and `identifier: string` (as the record's, uncapped).
   - `stream_id: int`, `max_connections: int` (≥ 1), `ip: string`, `ua: string`.
   - A missing header, or one that is not a JSON object, means a plain register, as today.
   - So does an object without exactly one valid identity (`line_id` an int > 0, or `hmac_id` an int > 0 with a string `identifier`), or whose `max_connections` is missing, not an int, or < 1.
   - An `adm` that is not an object with int `exp` and int `sid` is ignored, as if absent.
   - The agent does not compare `adm.sid` with its own server id: PHP passes the claim only when `sid` is the record's `server_id`.
2. **With `adm`.** When `adm.exp × 1000` is not before MAIN's time as the agent keeps it (`MainNowMs`), the viewer is admitted with no WAN call: store it and answer 200.
3. **Without it.** When the agent's last hello or heartbeat reply has `state` other than `active`, or CONNECTIONS off, it does not call `conn_admit` and admits. Otherwise, call `conn_admit` with `{uuid, line_id | hmac_id + identifier, stream_id, ip, ua}`, as under **Wire** above: the other identity's keys left out, never `max_connections`. The call has 1.5 s from the PUT's arrival, waiting for the ctl lane included.
   - A MAC'd 200 with `admit: true` admits.
   - A MAC'd 200 with `admit: false` refuses with MAIN's `reason`.
   - A verified denial (`*Denial`: panel-signed, naming this node and this request's nonce) with reason `NOT_ACTIVE`, `FLOW_OFF` or `BAD_REQUEST` admits. MAIN has answered, and admission does not apply to this node or this request. Its `conn.limit` still follows the open.
   - Anything else applies the offline policy: a transport error, a timeout, nginx's unsigned errors or `STARTING`, a reply that does not verify (`ErrTransport`), and any other verified denial (`DB`, `TOKEN_EXPIRED`, `RATE_LIMITED`, and so on). An older MAIN's `UNKNOWN_OP` names no node or nonce, so it reaches the agent as `ErrTransport`.
4. **The offline policy.** The agent uses the last `offline_admission` from a hello or heartbeat reply. It keeps it across restarts in its state file, and uses `local` until it has one. A value other than `local`, `allow` or `deny` is ignored, and the value it holds is kept.
   - `allow`: admit.
   - `deny`: refuse with `OFFLINE`.
   - `local`: count the registry's open records (`hls_end` not set) with the viewer's owner, leaving out this uuid and the same device's records (the same `user_ip` and `user_agent` as the PUT's record). The owner is the digest's, taken from the record: `u:<line_id>` or `h:<hmac_id>:<identifier>`. Refuse with `LIMIT` when the count is at least `max_connections`; otherwise admit. It never ends a record.
5. **Answers.** Admit: store and answer 200 with the record, as today. Refuse: answer 403 with `{"admit": false, "reason": "<REASON>"}`, store nothing and spool no event. A refused uuid already in the registry, such as an ended HLS record, stays as it was.
6. **Caching.** The agent may treat an admitting `conn_admit` answer as an `adm` for that uuid until its `exp`.
7. **Reasons.** From MAIN: `UNKNOWN_LINE`, `EXPIRED`, `BANNED`, `DISABLED`, `UNKNOWN_HMAC`. From the agent: `LIMIT`, `OFFLINE`.

**Differs from the plan.**
- **`local` refuses the newcomer.** Legacy enforcement, and MAIN's own cuts, admit the newest viewer and close the oldest. While MAIN is out of reach, `local` refuses a newcomer from another device instead of ending an open viewer. The plan's DEGRADED state keeps existing sessions, and Phase 6's acceptance drops no viewer while MAIN is stopped for 5 minutes. Evicting from the node would break both. The same device's records are left out of the count, so a channel switch is admitted while the old record ends. For HLS that takes up to 30 s, the reaper's wait. Once MAIN answers again, the spooled `conn.limit` applies newest-wins.
- **An answer that admission does not apply is not an outage.** The plan applies `lb_offline_admission` only when MAIN is unreachable. A verified `NOT_ACTIVE`, `FLOW_OFF` or `BAD_REQUEST` is MAIN's answer, so the agent admits. Otherwise a quarantined node, or one in a CONNECTIONS rollback, would ask on every limited viewer and get the offline policy every time. Under `deny`, all of those viewers would be refused.
- **Quarantined nodes are admitted without asking.** The agent's socket stays on for a quarantined node (`NodeFlows::on` accepts it), but MAIN mints no claim for one, and `conn_admit` accepts only `active` nodes. Its viewers are admitted as before this increment: PHP sends no header, and the agent skips `conn_admit`.

**Compatibility.**
- An older agent ignores the header and answers 200: every viewer is admitted, as before, and `conn.limit` still enforces the limit on MAIN.
- An older agent also ignores `offline_admission`.
- An older MAIN mints no claim. It answers `conn_admit` with an `UNKNOWN_OP` that names no node or nonce, which the contract treats as MAIN out of reach. The node's PHP ships in the same release as MAIN, so that happens only while a fleet is being upgraded.

**Not built:**
- the agent's half, above;
- admission for RTMP viewers (`rtmp.php` has no stream token);
- an audit row for refusals. A refusal is only in the client log, so a flood of expired tokens cannot fill `cluster_audit`.

Tests:
- `ConnectionAdmissionTest`: the claim; `conn_admit`'s refusals and their order; the HMAC case; idempotency; the wire's tolerance (an id of 0, a long identifier); the queued cut, which counts in flight when it runs, never twice, and cuts nothing when the store cannot be read; the HLS release.
- `ConnectionLimitsTest`: a node's `conn.limit` cannot queue an admission cut.
- `ClusterApiTest`: the op (MAC, flow, state, MAIN's own line, the node's reservation, 503 `DB`) and the policy in hello and heartbeat.
- `AgentAdmissionTest`, against a stand-in agent on a unix socket: the header (ASCII whatever the user agent; `adm` judged on MAIN's clock through `time_offset`; none on a quarantined node); live.php's `createLive` passing the token on; the unchanged body; the 403 refusal recorded nowhere and cleared by the next viewer; how each reason is shown; an older agent; the 2.5 s wait; and the endpoints' wiring.

### Connections (Phase 6, tenth increment): `conn.divergence`, and the P2 lane with `conn.touch`

This increment builds the panel half of two rows of the plan's section 7 events table, `conn.divergence` (P1) and `conn.touch` (P2), and the P2 lane of section 8, "Ordering and backpressure". The agent needs no change for the first. The second is specified under **The agent's contract** below and is not built in the agent yet.

**Divergence before.** Two writers on every node, MAIN included, put a viewer's divergence into MAIN's database:
- `cron:users` reads the speed files (KiB/s, one per uuid in `DIVERGENCE_TMP_PATH`) that live.php's pre-fanout TS loop, vod.php and timeshift.php write.
- `fanout_sync` reads the fanout's `GET /rates` for the node's daemon-served TS viewers.

Each compared the rate with the stream's bitrate on that node and wrote `lines_divergence`, and `lines_live.divergence` in MySQL store mode. MAIN's `cron:users` deletes the `lines_divergence` rows of connections its store no longer holds.

**Divergence now.** On a node whose CONNECTIONS flow is on, both writers hand the rates to `Core\Cluster\DivergenceSink::spool()`, which spools a P1 event through the `EventSpool`. When the spool refuses (flow off, the agent stopped), they write MAIN's tables as before. MAIN never has flows, so it always writes its own.
- **Event:** `conn.divergence {rows: [{uuid, rate}, …]}`. `uuid` is a string matching `[A-Za-z0-9_-]{1,32}`, the width of `lines_divergence.uuid`. `rate` is an int ≥ 0, in KiB/s. There are at most 1000 rows per event (`DivergenceSink::CHUNK`), and the events of one write go in one spool file. Rows that do not qualify are left out on the node.
- **MAIN** (`EventIngest`, `ConnectionIngest::divergence`) takes the event on P1 only, from a node with CONNECTIONS on:
  - It keeps the rows whose uuid MAIN's store (Redis, or `lines_live`) holds for the sending node. Rows with a bad uuid or a rate that is not an int ≥ 0 are skipped.
  - It works out each row's divergence with the node cron's formula, now shared: expected = `bitrate / 8 · 0.92` from the node's `streams_servers` row, and divergence = the percentage by which the rate falls short of it (0 when faster, or when no bitrate is known).
  - It writes them with one `REPLACE INTO lines_divergence`, and in MySQL store mode one `UPDATE lines_live … CASE activity_id`.
  - An event with none of the node's viewers, no valid row, more than 1000 rows or `rows` that is not a list is dropped and counted.
  - A store that cannot be read also drops the event instead of failing the batch. The next report comes within a minute, and the lane's logs are not held up for it.
- **Cleanup.** MAIN's cleanup of `lines_divergence` is unchanged, since the rows it writes are keyed by connections its store holds.
- **Agent.** The agent ships the event like any P1 spool file and needs no change.
- **No backlog.** Only a writer's latest report counts, and P1 is shared with the node's logs:
  - `fanout_sync` reports at most once a minute (`FanoutSyncCommand::DIVERGENCE_EVERY`), as often as `cron:users`. At its 10 s pass, 20k daemon viewers (about 56 bytes a row) would put about 6.6 MB a minute on P1, over half of what the agent's P1 loop ships (one batch of at most 1 MiB every 5 s). Without CONNECTIONS it still writes MAIN's tables every pass, as before.
  - A writer skips a report while its previous one is still in `spool/p1`, not yet sent (MAIN out of reach, the lane behind). `EventSpool::append` tags the file (`<hrtime>-<pid>-<rand>-divergence_cron.ndjson`, `…-divergence_fanout.ndjson`) and `EventSpool::pending()` finds it. During a MAIN outage each writer therefore leaves one report in the spool, and the lane's cap drops no logs for stale divergence. A skipped report writes nothing; with the agent stopped (`EventSpool::agentAlive()` false), the writer writes MAIN's tables as before.
  - The agent treats spool file names as opaque: it ships every `*.ndjson` in name order, so the tag needs no agent change.

**The P2 lane.** The `events` op takes `lane: "p2"`, for state of which only the newest value per key counts:
- **No number.** `first_useq` is not read and may be left out. The lane has no cursor and no `cluster_nodes` column, and `hello`'s `cursors` stays `{p0, p1}`.
- **Never a 409 `USEQ_GAP`.** Only the 400 `BAD_REQUEST` of any malformed batch (for example `events` not a list, or more than 5000 events), 503 `DB`, and the session denials of every op apply. Among those is 409 `NOT_ACTIVE`: the `events` op takes only an active node, while heartbeat, which a quarantined node still sends, keeps listing `p2_types`.
- **Reply:** BOX'd 200 `{ok: true, useq: 0, applied, dropped, main_time_ms}`.
- **Latest wins.** A batch is folded to the latest event per key by the event's `t`; of two with the same `t`, the later in the batch wins. Across batches, whatever holds the value keeps the latest too, so a repeated or late batch changes nothing newer.
- **Refusals.** An event of a type MAIN does not take on P2, or whose flow is off, is dropped and counted.

**`conn.touch`.** `{type: "conn.touch", t, d: {uuid, hls_last_read}}` is P2's first type: when a viewer last asked for its playlist, keyed by uuid.
- `t`: int ≥ 0, unix milliseconds on the agent's clock, as in every spool line. Only the order of one viewer's touches matters (see the contract, item 4).
- `uuid`: string matching `[A-Za-z0-9_-]{1,64}`.
- `hls_last_read`: int ≥ 0, the registry record's own value (the node's clock corrected by `time_offset`, as PHP writes it).
- It needs CONNECTIONS. Anything else is dropped.

Where MAIN keeps it (`ConnectionIngest::touch`):
- **The cluster bus only**, for a node whose agent ends its own idle HLS viewers (`HlsReaping::capable`: mode ≥ 1, CONNECTIONS on, `hls_reaper` said at hello). The key is `touch:<sid>:<uuid>` and holds `<t>:<hls_last_read>`. A Lua script replaces it only with a `t` that is not earlier, and it expires 5 minutes after its last write (`ClusterBus::TOUCH_TTL_MS`). Two bounds keep the bus safe from a node:
  - **Only the node's own viewers.** MAIN reads its store once per batch (one `MGET` on Redis, one `SELECT … uuid IN` on `lines_live`) and keeps the touches of the uuids it holds for the node. A node can neither create keys for made-up uuids nor touch another node's, and the bus holds at most one key per connection of a reaping node.
  - **At most half the bus.** Past `ClusterBus::TOUCH_MEMORY_SHARE` (0.5) of the bus's `maxmemory` (256 MB), read with `INFO memory` per batch, the touches go to MAIN's store instead. The bus evicts the keys closest to expiry first (`volatile-ttl`), so touches, which live longest, would otherwise push out the admission reservations (about 15 s) and the wake-ups (60 s) first.

  Nothing on MAIN reads the bus's touches back yet. `ClusterBus::lastReads()` is the reader for when something does, and the tests use it.
- **MAIN's store**, without the bus, with the bus past its share, or for a node that does not reap, since MAIN's 30 s rule reads that node's `hls_last_read`. It writes the value as the P0 upsert did, with these limits:
  - only the node's own connections;
  - never back to an earlier read;
  - never re-opening, creating or moving a connection between Redis sets;
  - one `UPDATE … CASE uuid` per 1000 on `lines_live`;
  - on Redis, `WATCH` per record, so a record an upsert or a close wrote meanwhile stays as that write left it.
- **`applied`** counts every well-formed touch taken. One for a viewer the store does not hold for the node changes nothing, on the bus as in the store. A store that cannot be read or written fails the batch with 503 `DB`, so the node sends its newer values again.

**Nothing on MAIN overlays the bus.** The plan's sink is "bus only". While a node reaps for itself, no MAIN reader decides by a touch-only change of `hls_last_read`:

| Reader | What it reads | With touches on the bus |
| --- | --- | --- |
| `UsersCronJob::hlsEnded` (the 30 s rule) | `hls_last_read` | Off for a reaping node, which sends its end as a P0 `conn.upsert` with `hls_end` 1 |
| `UsersCronJob::isRemoteWorkerRunning` (Redis mode) | `max(date_start, hls_last_read)`, as when the worker pid was set | A pid change is a P0 upsert carrying `hls_last_read`; a touch only ever made the time later, which is more lenient |
| `cron:users`' ENDED rule and 300 s rule for ended rows | the `hls_last_read` written with the end | Written by the P0 upsert of the end |
| `fanout_sync`'s 20 s connect grace | pid-0 TS rows | Never touched after they open |
| `ConnectionLimiter`, admission | `date_start`, `activity_id` | Not `hls_last_read` |
| `ConnectionDigest`, `ConnectionSnapshot` | uuid, owner, `hls_end`; a snapshot writes the registry's current record | Unaffected |
| Admin and reseller live connections, stats, the admin API | — | None shows `hls_last_read` |
| `UsersCronJob::hlsEnded`, once the node stops reaping for itself | `hls_last_read` | Covered by the leave grace below |

The gaps were in `HlsReaping` itself. In each, the store's `hls_last_read` for a reaping node's viewers can be minutes old, because their reads reached only the bus, and the 30 s rule would end live viewers:
- **A failed read.** When `cluster_nodes` could not be read, every node fell back to the 30 s rule. `HlsReaping::begin` now keeps the reapers of the last pass that read the table (`reaps` in `cluster_orphans.json`) and orphans nothing on a failed read. With no such pass, it falls back to the 30 s rule as before.
- **Leaving the reaper set.** A node stops reaping for itself when an admin turns CONNECTIONS off, its mode drops to 0, it is revoked or leaves `active`, or it says hello without `hls_reaper`. It then keeps counting as reaping for `HlsReaping::LEAVE_GRACE` (120 s, two reaper passes) from the first pass that finds it so (`leaving` in `cluster_orphans.json`), unless it is orphaned, whose rows are purged anyway. In that time the node hears of the change at its next heartbeat, and its viewers' next playlist requests put fresh reads into the store: written by the node's PHP once CONNECTIONS is off, or as an older agent's P0 upserts. Only then does the 30 s rule judge its rows.
- **An LB in MySQL mode** judges its own rows in `lines_live` by `localReaps()`, from `flows.json`. Its `cron:users` now calls `HlsReaping::beginLocal()` once per pass, which keeps the same grace once its agent stops reaping (`local` and `local_left` in its own `cluster_orphans.json`).

MAIN does not copy the bus's touches into the store on the way out: with touches sent at most once a minute (the contract, item 3), they can be older than the 30 s rule allows anyway.

**Telling the agent.** The hello reply and every heartbeat reply carry `p2_types`, a list of strings: the event types MAIN takes on P2, now `["conn.touch"]`. An older MAIN leaves the key out. It is in every heartbeat so that a MAIN rolled back to a release without P2 is noticed within one heartbeat.

**The agent's contract.** For the Go half, not built yet:
1. **When.** Touches go on P2 only while all of these hold:
   - the latest hello or heartbeat reply's `p2_types` contains `"conn.touch"`;
   - CONNECTIONS is on;
   - the agent runs its HLS reaper and says `hls_reaper` at hello.

   Otherwise a change of `hls_last_read` alone goes, as today, as a P0 `conn.upsert` at most every 10 s (`TouchEvery`). A panel that reaps by the 30 s rule needs that.
2. **What moves.** Only a `Put` whose sole change is `hls_last_read` (`Touch`, or a `PUT` that `sameBut(…, "hls_last_read")` finds unchanged otherwise). It no longer emits the throttled P0 upsert: the registry keeps the latest value per uuid as a pending touch. Every other change still goes at once as a P0 `conn.upsert` of the whole record, its current `hls_last_read` included: a new record, any other key, `pid`, `hls_end` either way (the reaper's end, a re-open).
3. **Cadence.** A uuid's pending touch is sent at most once every 60 s, and only when its value differs from the last one MAIN got for that uuid on either lane. A third events loop, next to the `p0` and `p1` spool loops and sharing their client, runs every 10 s with one request in flight and never delays `p0`. It sends the pending touches whose 60 s have passed, at most 2000 events and 1 MiB per request, as the other lanes.
4. **Wire.** `POST events` with `{lane: "p2", events: [{type: "conn.touch", t: <unix ms>, d: {uuid: <string>, hls_last_read: <int>}}, …]}`. Leave out `first_useq`: it is not read on P2, and nothing is journalled or numbered.
   - `t` and `hls_last_read` must be JSON integers: a record seeded from `lines_live` may hold `hls_last_read` as a numeric string, which the agent converts (`intOf`). MAIN drops a string.
   - `t` orders one viewer's touches on the bus, where a lower `t` never replaces a higher one. Take it from a clock that does not step back while the agent runs: the wall time read at start plus the monotonic time since (Go's `start.Add(time.Since(start))`). After a backward step across a restart, the bus keeps a higher `t` until the new one passes it or the key expires (5 min). Nothing on MAIN decides by the bus's value, and the store fallback orders by `hls_last_read`, so this costs nothing today.
5. **Replies.**
   - A MAC'd 200 `{ok, useq: 0, applied, dropped, main_time_ms}`: the values sent are done, and a newer value for the same uuid stays pending. `dropped` > 0 is logged and nothing is resent. `applied` also counts a touch MAIN ignored because its store does not hold the uuid for the node.
   - A verified 400 `BAD_REQUEST`, which is what a MAIN without P2 answers: stop P2 until a reply lists `conn.touch` again, and handle the pending touches as in item 6.
   - A 503 (`DB`), a 429, a transport error or a reply that does not verify: keep the pending touches (newer values replace them) and retry with the lane's backoff.
   - A session denial, as on the `p0` and `p1` loops: 409 `NOT_ACTIVE` (the node is quarantined: `events` takes only an active node, though heartbeat still lists `p2_types`), or a token or re-key denial. Keep the pending touches, back off and retry; the heartbeat loop re-keys. `NODE_REVOKED`, `UNKNOWN_NODE` and `ENROL_EXPIRED` stop the agent, as on every op.
   - There is never a 409 `USEQ_GAP`.
6. **Losing it.** When a condition of item 1 stops holding, stop sending P2 at once. This MAIN does not rely on the agent to make the switch safe: it keeps a node that stops reaping for itself out of the 30 s rule for 120 s (`HlsReaping::LEAVE_GRACE`), and an LB in MySQL mode does the same for its own rows. An older MAIN has no such grace, but it leaves a node that says `hls_reaper` to its own reaper.
   - **CONNECTIONS off.** Send nothing: MAIN refuses every `conn.upsert` once the flow is off, and the node's PHP writes MAIN's store itself again.
   - **MAIN rolled back** (a reply without `conn.touch` in `p2_types`, or a 400 to a P2 batch), **or the reaper off**, with CONNECTIONS still on. Send at once, as a P0 `conn.upsert` of the uuid's current record, every open record whose current `hls_last_read` differs from the last value sent for it on P0. Track that apart from the last value sent on P2: a value that went on P2 reached the bus, not the store. Then go back to the 10 s P0 touches.
7. **Forgetting one.** Drop a uuid's pending touch when the record leaves the registry (`Delete`, a MAIN `conn.close`, `FanoutClosed`, a seed that resets). A P0 upsert of the uuid may drop it too, because the upsert carried the newer value.
8. **State.** Pending touches may live in memory only. A restart loses at most a minute of them, which nothing on MAIN decides by, and the reaper gives every viewer restored from `registry.snap` a full window anyway.
9. **Unchanged.** The digest, the snapshot, the reaper and `conn.divergence`.

**Differs from the plan.**
- **`conn.divergence` carries the rate, not the divergence.** MAIN holds the node's connections and its streams' bitrates, so the node needs no WAN read of `streams_servers` or of MAIN's store to report it, and the formula lives in one place.
- **`conn.divergence` keeps one report per writer in the spool.** The plan puts it on P1, which replays everything. A writer skips its report while the last one is unsent, and `fanout_sync` reports once a minute, so stale reports never take the lane or its cap from the logs.
- **`conn.touch` is bus-only only for reaping nodes.** Without the bus, past half of it, or for a node that does not reap, the touch goes into MAIN's store, because the 30 s rule reads it there.
- **The bus takes a touch only for a viewer MAIN's store holds for the node.** That costs one store read per batch, which the plan's "bus only" sink does not have. It keeps a node to its own viewers and bounds the bus by the reaping nodes' connections.
- **Leaving the reaper set has a grace.** The plan does not say what happens when a node stops reaping for itself; here it keeps counting as reaping for 120 s, so a store the bus left minutes behind is refreshed before the 30 s rule reads it.
- **P2 has no cursor.** The plan orders P2 "latest per key by timestamp"; here that is the event's `t` on the bus. The store fallback keeps the greatest `hls_last_read`, which orders a viewer's reads the same way.
- **`stream.progress` and `node.inventory` stay where they were:** in `stream.state` on P0, and on P1. They can move to P2 as types of their own, each listed in `p2_types`.

**Compatibility.**
- An older agent keeps sending touches as P0 `conn.upsert`, which is unchanged, and ignores `p2_types`. It ships the tagged divergence spool files like any other.
- An older MAIN lists no `p2_types`, and answers a P2 batch with 400 `BAD_REQUEST`.
- An older panel drops an unknown `conn.divergence` on P1, so the lane still advances. The node's PHP ships with MAIN in the same release anyway.

Tests:
- `ConnectionDivergenceTest`: the node's spool and its fallback; one report per writer while the last is unsent; both writers through their call sites (`UsersCronJob::writeDivergence`, `FanoutSyncCommand::writeDivergence`), which either spool or write MAIN's tables, never both, and `fanout_sync`'s minute; the shared formula; MAIN's own-rows write on `lines_live` and on Redis; and the refusals.
- `ConnectionTouchTest`: no cursor and no gap; the latest per viewer by `t` within a batch and across batches on the bus; the refusals; the store fallback on both stores (own rows, never back, never re-opening); bus-only for a reaping node, and only for the viewers its store holds; a mode-0 node and a bus past its share, both to the store; a store that cannot be read or reached failing the batch; and an older agent's P0 upsert.
- `ClusterApiTest`: the P2 op and `p2_types` in hello and heartbeat; 503 `DB` with the store down; 409 `NOT_ACTIVE` for a quarantined node.
- `HlsReapingTest`: the last reapers stand on a failed read; the leave grace for CONNECTIONS off, mode 0, a hello without `hls_reaper`, revoked and deleted nodes; none for an orphaned node; and the same on an LB (`beginLocal`).

### Acceptance tests that found gaps (Phases 2, 4 and 6)

Five tests the plan lists (§13) now run against the real code. Each found MAIN doing something other than what the plan says, and each was fixed together with its test. The large-snapshot gap, and the bounds on the hard-mode denial and the fleet silence guard, came from a review of the first four fixes.

**`https_required` over plain HTTP (Phase 2, `HttpsRequiredRecoveryTest`).**

- MAIN did not know which transport a request came over, so under `https_required` it served every op over plain HTTP. `Public/cluster/index.php` now passes nginx's `HTTPS` flag (`fastcgi_params`). Over plain HTTP, `ClusterApi` answers every op but `challenge` with a panel-signed `403 HTTPS_REQUIRED`, bound to the node and request nonce when the headers name them.
- `health` is answered before the settings are read, so it stays on plain HTTP. The plan names only `GET /challenge`; `health` is signed and carries nothing secret.
- A settings save that changed `cluster_transport` left `cluster_policy_ver` as it was. Nodes never saw the new policy in their heartbeats, and a signed policy recorded before the switch had the same version as the new one, so an agent would adopt it again. A save that changes `cluster_transport` or `cluster_main_host` now raises the version in its own `UPDATE`.
- A settings form could set `cluster_policy_ver` itself, back to 1. `SettingsService` now drops `cluster_policy_ver` and `cluster_legacy_ports`, which are MAIN's own state, from a POST.
- The drill runs through `SettingsService::edit()`, with the HTTPS self-probe faked (`ClusterSettings::useHttpsProbe()`). A node enrolled under `https_required` loses HTTPS, is refused over HTTP, and polls the signed challenge over HTTP. It does not adopt a replayed policy of a lower version, and it is back on plain HTTP once the admin picks `auto`, with no SSH.
- The agent must answer `HTTPS_REQUIRED` by fetching the challenge over HTTP. That is XC_VM_Fanout's part.

**Kills in the hard revocation mode (Phase 4, `HardModeKillChannelTest`).**

- With `lb_revocation_mode=hard` and no licence, the extension refuses the node's session, so the long-poll and every MAC'd reply stop. Kills were still signed, being restrictive, but stayed queued until they expired.
- A `LICENCE_INVALID` from the session check now carries `commands_sealed`: the node's pending restrictive commands (`CommandBus::restrictive()`), oldest first, in the long-poll's shape (`doc`, `sig`, `seq`), as a JSON list SEALed to the node's box key (`cluster_nodes.node_box_pub`, purpose `commands`, the node uuid as context) and base64-encoded. Only a node that takes commands (active, mode ≥ 1, COMMANDS on) gets them.
- The request is not authenticated: without a session there is no MAC to check, and the denial goes to whoever names the node, whose uuid travels in every request's headers. Hence the seal: in clear, the list would show anyone the viewers' connection uuids and the workers' pids, which a BOXed reply hides. With it, a sniffer or a forger learns only the list's size, as the plan's attacker view allows. The review of this increment also proposed checking a node signature first; heartbeats carry none (only token ops do), so the agent would have to sign every request in case MAIN turns out unlicensed, and the seal already keeps the content to the node.
- Nothing is marked delivered. The agent checks each command's signature, uuid, generation, `seq` above its high-water and expiry, as on the long-poll, but does not raise its long-poll high-water for them: it keeps their `cmd_id`s until they expire instead. Otherwise running a kill with `seq` N would skip every granting command queued below N (an RPC, a root command), which the long-poll, asking for `seq` above the high-water, would never hand out once the licence is back; each would sit queued until it expired, and its caller would get no result. Now the long-poll hands them out, and a kill it hands out again is acked with its result, not run twice.
- The class comes from `cluster_commands.class`, which `CommandBus` sets from the plan's list of restrictive types. A granting command signed before the lapse is not handed out.
- `FakeClusterCrypto` now does what the extension does: it refuses a hard session without a licence, and it classes a `cmd` record by its type. Its list of restrictive types is a copy of `CommandBus::RESTRICTIVE`, and the test fails when the two drift apart; the real list is the extension's.
- The tests also cover a forged request (no MAC, no node signature: it gets only the sealed list), another node's kill, and expired and acked kills (never carried), and the licence coming back (the RPC queued before the lapse comes on the long-poll).
- The agent must open and run the commands a denial carries, and keep their `cmd_id`s. That is XC_VM_Fanout's part.

**Apply once (Phase 6, `ConnectionIngestIdempotencyTest`).**

- `EventIngest` checked a batch against the cursor in the node row that the request read when it authenticated. A node whose `events` request times out sends the batch again, while MAIN may still be applying the first copy. Both copies passed the P0 gap check, so a closed viewer was re-opened and its close wrote a second activity row.
- MAIN now applies one batch per node and lane at a time, and reads the cursor under that lock. Only the P0 and P1 lanes take it: P2 keeps no cursor (tenth increment). The lock is a file, `TMP_PATH/cluster_ingest/<sid>_<lane>.lock`, waited for up to 10 s (then `503 DB`). It is not the node's database row: every heartbeat writes that row, and holding it for a whole batch would delay them.
- A cursor `UPDATE` that failed was ignored. When the database connection dropped mid-batch, taking the transaction with it, the node was still told the batch was applied, and moved on past events MAIN never kept. Such a batch now fails with `503 DB`, and the node sends it again.
- The same event under a new number already applied once: an upsert updates in place, and a remove or close of a viewer already gone is accepted and changes nothing.
- **Known gap:** a close's activity row goes to a file, outside the transaction. A batch that fails after one of its closes was applied keeps that activity row, and the resend writes it again, in either store. In MySQL mode the connection's removal rolls back with the batch; Redis has no transaction, so there the batch's writes stay, and the resend opens and closes the viewer again. `ConnectionIngestIdempotencyTest` pins both, with the second activity row.
- The test pins the lock too: a query hook checks that the lane's lock is held when the cursor is read and when it is moved. The lock directory has a test seam (`EventIngest::useLockDir()`); the test bootstrap points it at a directory of the test process's own under `tests/.tmp`, so no suite run shares lock files with another.

**MAIN's downtime and the orphan purge (Phase 6, `MainOutageNoPurgeTest`).**

- `HlsReaping` measured a node's silence from `last_seen_at` alone, and kept its watch across reaper gaps of up to 3 minutes. A watch that began while MAIN's nginx was stopping survived a short restart. The first pass after it then purged every CONNECTIONS node's viewers before the nodes could reconnect.
- Silence now counts from `max(last_seen_at, cluster_ready_at)`, as `NodeHealth` counts it (`cluster_meta.ready_at`, or 0 when it cannot be read).
- While the fleet silence guard is up (`ClusterHealth`), the nodes it holds are not watched, and their watch starts over when it clears, so the time MAIN suspected itself never counts. The plan says the guard suspends purges; it does not say whether the watch restarts.
- The guard does not hold a node that was offline before it came up. The liveness loop keeps such a node offline under the guard, and its silence began before MAIN suspected itself, so its watch goes on and it is purged a TTL after the watch began.
- The guard holds the watch for at most 4 × `cluster_orphan_conn_ttl_sec` (8 minutes at the default), counted from the reaper pass that first saw it (`guard` in `cluster_orphans.json`, beside the tenth increment's `reaps` and `leaving`). The plan triggers the guard when most nodes "go silent within 10 s"; the liveness loop raises it whenever more than half of the TELEMETRY nodes (at least two) are not ok, with no time limit. A lasting loss of most of the fleet, such as both LBs of two, never clears it. Without the bound, those nodes' viewers would count against their lines until an admin acted, and on a `max_connections=1` line they could not reconnect elsewhere. With it, such a node is purged one TTL after the hold ends. A purge during a longer cut of MAIN's own network is undone when the nodes come back: their digests disagree, and their snapshots restore the rows. The guard's hold on offline marking (routing) is unchanged.
- `MainOutageNoPurgeTest` also covers a node never heard (its silence counts from `cluster_ready_at`), a five-minute outage, a node offline before the guard, and a lasting loss of two nodes out of three.

**Large snapshots (Phase 6, `LargeSnapshotChunkingTest`).**

- Twenty chunks of 1000 records, against `lines_live` as the install creates it, change the store only with the last chunk. An oversized, malformed, out-of-order or unreadable staged chunk leaves the store as it was.
- The staging was atomic, but the apply was not: 20 000 separate autocommitted writes. The staged chunks were deleted before it, and a write that failed only counted as dropped, so a connection lost part-way left a half-applied store and was still answered `ok`. Readers could also see the store half-changed while it ran.
- In MySQL mode the apply is now one transaction. A commit that fails (the connection was lost) rolls it back and answers `503 DB`, and the staged chunks are kept until an apply succeeds, so the last chunk sent again applies the whole snapshot.
- The removal pass removed every connection whose upsert had not succeeded, so a write MAIN failed to make deleted a viewer the node still had. It now spares every uuid the snapshot names.
- The review proposed failing the snapshot on any write that fails. A single record MAIN refuses or cannot write is still counted as dropped instead: a record the database rejects would otherwise fail every snapshot the node sends, and the node would never get back in step. The next heartbeat's digest check (`ConnectionDigest`) finds such a difference and asks for another snapshot.
- Redis has no transaction across 20 000 records. A Redis error part-way answers `503 DB` and leaves the part already applied; applying the whole again converges.

### The settings section (Phase 7, fourth increment)

**The allowlist.** `src/Core/Cluster/lb_settings_keys.php` lists the settings a node's replica may carry. It is generated by `tools/ci/lb-settings-keys.sh --write`, and `make gates` fails when it is stale. The script builds the LB file manifest the way `verify-lb-archive.sh` does, then scans every shipped PHP file for settings reads:

- `SettingsManager::get('key')`, and its `getBool`/`getInt`/`getString` forms;
- any `['key']` index whose key is a settings column. This is over-inclusive on purpose: a server column that shares a settings name costs one extra key, never a missing one.
- a dynamic read (`SettingsManager::get($x)`, `getAll()[$x]`, `$rSettings[$x]`). It must carry an `lb-settings: key, …` note on its line or one of the two before it, or the gate fails.

The settings columns come from the install schema and the migrations. Secrets are withheld whatever reads them, and listed as such: `api_pass`, `license`, `live_streaming_pass`, `redis_password`, the third-party API keys and the reCAPTCHA secret. The node needs `live_streaming_pass`, which will come in the sealed `secrets` section. `ReplicaBuilderSecretsTest` fails on an allowlisted key that looks secret and has not been vetted.

**Serving.** `ReplicaBuilder::whole()` sends a section whole, as a `rep` record without a `seq`, whenever its ETag differs from the one the node names in `have`. The `settings` section is the raw `settings` row, allowlisted keys only, with each value as a string. It goes only to an agent that names it in `have`, so an older agent is not sent a section it cannot store.

**The node.** The agent stores `replica/settings.rep` and writes `replica/settings.json` (`{etag, data}`) from the verified record, then runs `cluster:apply`.

**Applying.** `ReplicaApply` decodes the section as the panel does, through `SettingsRepository::decode()`, now shared. It reports the keys whose value differs from the settings cache. It stays in shadow even with CONFIG on: the section withholds secrets that the node still reads, so it becomes authoritative together with the `secrets` section.

### The cluster bus (Phase 2, first increment): wake-ups

**What it is.** The cluster bus is MAIN's own Redis instance for the cluster API (`Domain\Cluster\ClusterBus`). It runs the bundled `redis-server` with `bin/cluster_bus/cluster.conf`, and is separate from the shared Redis that the panel and legacy LBs use.
- **Access:** only a unix socket, `bin/cluster_bus/cluster.sock`, mode 0700, owned by xc_vm. There is no TCP port, and the admin commands are renamed away.
- **Persistence:** none, because nothing in it has to survive a restart.
- **Where it runs:** MAIN only. `service` and `ServiceCommand` start it, and `ServersCronJob` revives it. LB builds strip `bin/cluster_bus`.
- **Liveness checks:** it runs the same binary as the shared Redis, so `ServersCronJob` tells the two apart by process title: `redis-server unixsocket:…` for the bus, `redis-server *:6379` for the shared one. Before this, a running bus would have hidden a dead shared Redis.

**What it carries.** Wake-ups, the admission reservations (`ConnectionAdmission`, seventh Phase 6 increment), and the touches of nodes that reap their own HLS viewers (`touch:<sid>:<uuid>`, tenth Phase 6 increment):
- **`wake:<sid>`:** `CommandBus::enqueue` pushes it, and the `commands` long-poll waits on it. The long-poll used to re-read `cluster_commands` every 250 ms for up to 20 s per node. It now reads once, blocks on the bus, and reads again when woken. While it blocks it holds no MySQL connection: it closes the handle first (`waitNodeReleasing`), and `DatabaseHandler` reconnects on the next read.
- **`ack:<cmd_id>`:** `CommandBus::ack` pushes it, and `CommandBus::await` (an RPC waiting for its answer) waits on it instead of polling every 100 ms.

**How a wake works.** A wake is a one-element list with a 60 s TTL, taken with `BLPOP`:
- a wake pushed just before the waiter blocks is not lost;
- repeated wakes collapse into one;
- a stale wake costs one extra query.

**Without the bus.** If the bus is not running, or this is an LB or a test, `waitNode`/`waitAck` return null and the callers poll as before.

**Still to come on the bus:** telemetry (`cl:tel:<sid>`). Nonces and the per-op semaphores came in the second increment, below.

### The cluster bus (Phase 2, second increment): nonces and per-op semaphores

**Nonces before.** Every authenticated request inserted its `(node, nonce)` into `cluster_nonces`, so each heartbeat, event batch and long-poll cost a MySQL write.

**Nonces now.** While the bus runs, `NonceStore::claim()` checks and adds the nonce in one Lua script:
- `nonce:<node>` is a sorted set. Each member is a nonce in hex, scored by its expiry: MAIN ms, 180 s after the claim. The script prunes expired members first. `purge()` (`cron:cluster`, every minute) prunes the sets of nodes gone quiet, and an empty set disappears.
- `nonces_since` holds the MAIN ms of the first claim this bus took. The bus holds every claim made since then.
- Neither key has a TTL. The bus's `volatile-ttl` policy evicts only keys that have one, so a nonce is never evicted while it can still be replayed.
- Nor is a claim refused at `maxmemory`. The script's first write (the prune) lets the rest of it through, and past `maxmemory` the bus evicts keys that have a TTL instead: wake-ups, reservations and touches, nearest expiry first. Only authenticated traffic adds nonces, which bounds them: about 150 bytes a nonce, so 1000 requests a second hold about 27 MB. (A fresh bus at `maxmemory` fails the script on `SET nonces_since`, and the claim is handled as without the bus.)

MySQL stays the store while the bus is out of reach: when it is not running, its socket is gone, a connect or script fails, or during the 5 s pause `ClusterBus` takes after a failed connect.

**Replay protection never gets weaker.** The bus is not persisted, and one worker can lose it while others still reach it. With LEAD = 250 ms (`NonceStore::LEAD_MS`) and TTL = 180 s:

| Case | Rule |
| --- | --- |
| A bus restarted or flushed, and lost the claims it held | A request stamped (`X-XCVM-Ts`) at or before `nonces_since` + LEAD is refused. Its nonce may have been claimed on the lost bus, since each such claim was stamped no later than its claim time + LEAD (next row). |
| A request stamped more than LEAD ahead of MAIN's clock | Also claimed in MySQL, so the rule above can leave it there. |
| A bus younger than TTL | Also claims in MySQL, where the claims from before it are. |
| MySQL took claims while a bus socket existed | It marks the second in `bin/cluster_bus/nonces.sql`, a file's mtime. While that mark is under TTL + 1 s old, the bus also claims in MySQL. |
| The bus is out of reach | A worker marks the second in `bin/cluster_bus/nonces.bus` before each claim it runs on the bus. Without the bus, a request stamped before the end of the marked second + LEAD is refused, since the bus may hold its nonce. A mark from the current or the previous second means the bus may be taking claims right now, some not marked yet, so the refusal then runs to the end of the current second + LEAD. Workers that still reach the bus keep the mark current, so a worker that cannot reach it refuses until it can. |

- **The marks** are written at most once a second, on disk beside the socket, so they survive a reboot. A mark only moves forward: a worker that read the clock in an earlier second and writes late leaves it as it is. A mark more than 1 s ahead of the clock (it stepped back) is rewritten. A claim whose mark cannot be written is refused.
- **No socket.** Without a bus socket (the bus stopped cleanly, or never ran), MySQL takes claims without the SQL mark: the next bus to start is young, and looks in MySQL anyway.
- **Clock steps.** A `nonces_since` more than 1 s ahead of MAIN's clock (the clock stepped back) starts a new history. That costs a short refusal instead of refusing everything until the clock catches up.
- **The refusal** is the usual 401 `REPLAY`: MAIN cannot tell such a request from a replay. It does know when a request stamped anew will pass, so this refusal carries `retry_after_ms` (below). A replay proper, a nonce the store already holds, carries none.
- **What it costs.** After a bus (re)start, requests stamped within 250 ms of its first claim are refused, and every claim for the next 180 s also goes to MySQL. After the bus is lost, requests are refused until the end of the second after the last one it took a claim in (up to 2 s). A worker that cannot reach a running bus refuses its requests, for up to 5 s at a time (the connect pause).
- **Unstamped values.** The re-key minute (`rekey:<uuid>`) and a used challenge (`used:chal:<uuid>`) are MAIN's own values, and the stamp rules do not apply to them. A bus that loses a re-key minute allows one more re-key attempt in that minute.

**Challenges.** `challenge` issues its value with `NonceStore::issue()`:
- The value goes into MySQL, as before, even while the bus runs. `GET challenge` is unauthenticated, and on the bus a flood of values would push out the reservations and wake-ups (`volatile-ttl` evicts the nearest expiry first). Challenges are rare: a re-key, or a policy fetch while fenced.
- Without the bus, the value is also marked like a claim (`nonces.sql`), so a bus that comes back records its use in MySQL too, where a worker without the bus looks.
- `consume()` finds the value in MySQL and takes it with a claim on `used:<node>`, on the bus or in MySQL, so a value is used once.

**Semaphores.** Plan section 8 gives `hello`, `conn_snapshot`, `token_rekey`, `config` and `streams` a bus semaphore of 4. `streams` has no op yet, so the other four get one (`Domain\Cluster\ClusterSemaphore`):
- **The permit** is a member of `sem:<op>`, a sorted set without a TTL (never evicted), scored by the permit's expiry.
- **When.** A session op takes it after the node state and before the BOX is opened (step 10 of the order under "MAIN's API"). It gives it back in `finally` when the handler ends, however it ends. `token_rekey` takes it after its node signature, nonce and node state, and before its once-a-minute slot and the challenge, so a busy MAIN spends neither.
- **Crashes.** The permit of a holder that died expires after its lane's pool timeout: 60 s for `hello` and `token_rekey` (ctl), 90 s for `config` and `conn_snapshot` (ingest).
- **Time** is the bus's own clock (`TIME`, read inside the script). Scripts run one at a time, so each sees a time no earlier than the permits it finds. A worker's own clock, read before its script reached the bus, could lag another worker's and drop that worker's live permits as taken in the future.
- **Clock steps.** A permit expiring more than 1 s (`ClusterSemaphore::STEP_MS`) past now plus its lifetime was taken before the clock stepped back, and is dropped.
- **Without the bus,** or when the bus call fails, no permit is taken, as before.

**Wire: the busy refusal.** When all 4 of an op's permits are held, its handler does not run, and the node gets a denial like every other one: panel-signed (`den`), naming the node and the request nonce. Its fields:
- status 503;
- `reason`: `RATE_LIMITED`;
- `retry_after_ms`: int, from 1000 to 3000, drawn at random per refusal to spread a fleet out;
- `op`: string, the op refused: `hello`, `token_rekey`, `config` or `conn_snapshot`.

The re-key minute keeps its 429 `RATE_LIMITED` with `retry_after_ms`. The status tells the two apart.

**Wire: `REPLAY` with a wait.** The 401 `REPLAY` denial (panel-signed `den`, naming the node and the request nonce, with `main_time_ms` as every denial has) gains one optional field:
- `retry_after_ms`: int, at least 50. It is present only when MAIN refused because it cannot vouch for the nonce yet, never for a nonce it holds. A request stamped anew (`X-XCVM-Ts`) at or after the denial's `main_time_ms` + `retry_after_ms` is past the refused range. Today it is at most about 1.4 s.

It is sent on every op that claims a nonce: the session ops, `token_rekey`, `enrol_code` and `enrol_code_status`. It has three causes, each a range of stamps MAIN refuses:
- a bus started, restarted or was flushed: stamps up to 250 ms past its first claim (`nonces_since`);
- the bus was lost: stamps before the end of the last second it took a claim in, + 250 ms;
- the MAIN worker cannot reach a bus the others still use: stamps before the end of the current second, + 250 ms.

The last two are one rule (the `nonces.bus` mark) as a worker sees it. The wait is the time from MAIN's clock to the end of the range, plus 50 ms (`NonceStore::RETRY_MARGIN_MS`).

**What today's agent does.**
- `token_rekey`: `recover()` already waits `max(1 s, retry_after_ms)`, ±10 %, on `RATE_LIMITED`, without raising its backoff.
- `hello`: `Start` is called in three places. At start, `Run` backs off 2 s, doubling up to 1 minute, as on any error. After a re-key, the heartbeat loop calls `Start` once and drops a non-fatal error, so that hello is lost until the next re-key or policy change. On a newer `policy_ver`, a failed `Start` is only logged, and the next heartbeat (2 s) tries again.
- `config`: `SyncReplica` logs the error, and the next poll comes a minute later.
- `conn_snapshot`: the snapshot is abandoned. MAIN asks again if the digest still disagrees.
- `REPLAY`: `retry_after_ms` is ignored. A heartbeat is retried at the next tick (2 s), an event batch after the lane's backoff, a long-poll after 1 s. The clock offset is not updated from a denial, so an agent with no offset yet (the first hello after it starts) lags MAIN by its clock error: after a bus start its requests are refused until that lag has passed, up to the 90 s window.

All of this is safe, only slower than the contract below.

**The agent's contract.** For the Go half, not built yet:
1. **503 `RATE_LIMITED`**, a verified denial with status 503 to `hello`, `token_rekey`, `config` or `conn_snapshot`, means MAIN is busy, not failing. Wait `retry_after_ms` with ±10 % jitter, clamped to 1–60 s. Then send the same op again with a fresh nonce and stamp. Do not raise the op's backoff or count it as a failure.
   - `hello`, at every call site: in `Run`'s start loop, wait `retry_after_ms` instead of the doubling start backoff. After a re-key, retry the hello after `retry_after_ms` until it succeeds or fails fatally; do not drop it. After a newer `policy_ver`, retry after `retry_after_ms` too (the heartbeat loop keeps running meanwhile).
   - `config`: retry after `retry_after_ms` instead of at the next minute's poll.
   - `conn_snapshot`: resend the refused chunk, with the same `snap_id` and `seq`. MAIN keeps the chunks it took, and a chunk it no longer expects gets 409 `SNAP_GAP`, which ends the snapshot as today.
   - `token_rekey`: as today. The challenge was not consumed, so it may be sent again while under 180 s old, or a new one fetched.
   - A 429 `RATE_LIMITED` (the re-key minute) is handled as today.
2. **401 `REPLAY` with `retry_after_ms`**, a verified denial to a request the agent sent once, on any op, means MAIN cannot vouch for its nonce yet (the three causes above). First set the clock offset from the denial's `main_time_ms`, as from a MAC'd reply: the denial is panel-signed and names this request. Then wait `retry_after_ms`, never less (jitter may only add, up to 10 %), clamped to at most 10 s. Then send the op once more with a fresh nonce, a fresh `MainNowMs()` stamp and a fresh MAC, BOX or SEAL. A second `REPLAY` in a row, or a `REPLAY` without `retry_after_ms`, takes the op's usual backoff.
3. **Stamps.** `X-XCVM-Ts` stays `MainNowMs()`: local time plus the offset from the last authenticated `main_time_ms` (a MAC'd reply, or a `REPLAY` as in item 2), never pushed ahead by an RTT estimate. A request stamped more than 250 ms ahead of MAIN's clock is accepted, but costs MAIN a MySQL write.
4. **Nothing else changes:** no new op, header or setting. The one new field is `retry_after_ms` on `REPLAY`.

**Differs from the plan.**
- **No `streams` semaphore.** The op does not exist yet (Phase 7, R2).
- **A sorted set per node, not `SET NX` with a 180 s TTL.** A key with a TTL is evictable under the bus's `volatile-ttl` policy, which would reopen that nonce's replay window under memory pressure.
- **The nonce floor, defined.** The plan names a nonce floor against replay after a bus restart (section 3 and the security checks) without defining it. Here it is `nonces_since` + LEAD, and a young bus also claims in MySQL for 180 s, since the bus is not persisted. The marks, for a bus out of reach, are not in the plan.
- **MySQL writes remain** while the bus is young or out of reach, and for requests stamped ahead: the cases the bus alone cannot vouch for.
- **The busy refusal reuses `RATE_LIMITED`.** The plan names no reason, and today's agent honours `retry_after_ms` only on `RATE_LIMITED`, so it re-keys promptly.
- **Challenge values stay in MySQL.** They are issued unauthenticated, and on the bus a flood of them would evict what must stay.
- **`REPLAY` can carry `retry_after_ms`.** The plan gives a replay refusal no wait. When MAIN only cannot vouch for a nonce yet, the refusal tells the agent when a request stamped anew will pass.

**Compatibility.**
- Older agents keep working. The only wire changes are the 503 on four ops and `REPLAY` in the cases above, both refusals they already handle, and `retry_after_ms` on such a `REPLAY`, which they ignore.
- On upgrade, the running bus has no `nonces_since`, so the first claim sets it. The first 250 ms of requests are refused, and for 180 s every claim also goes to MySQL, which holds the claims from before the upgrade.
- LB builds have neither `Domain/Cluster` nor `bin/cluster_bus`.

**Limits.**
- The marks are file mtimes, and a power cut can lose the last few seconds of metadata (ext4 commits every 5 s). If MAIN then serves requests before its bus starts, and within 90 s of the cut, a request from those last seconds could be replayed once. A bus started at boot is young and covers this.
- The rules assume MAIN's clock does not step back by more than a second.
- Two copies of one request, one at a worker with the bus and one at a worker without it, can both pass if they arrive together after a second in which the bus took no claim. The worker without the bus must pause between reading the bus mark and writing the SQL mark while the other claims.
- A bus that loses some nonce keys but keeps `nonces_since` goes unnoticed: a manual `DEL`, or an `allkeys-*` eviction policy. The shipped `cluster.conf` uses `volatile-ttl`.

Tests:
- `ClusterNonceStoreTest`, against a real redis-server on a unix socket as `ClusterBusTest` does:
  - MySQL without the bus;
  - on a settled bus, one write there, no TTL, expiry and purge;
  - the lead: a request stamped more than 250 ms ahead also goes to MySQL;
  - a fresh bus's floor with its wait, and its MySQL writes for exactly its first TTL;
  - a clock step back that restarts the history, and one of a second that does not;
  - a nonce MySQL took while the bus was out of reach, refused once it is back, and the SQL mark's TTL + 1 s;
  - a nonce the bus took, refused while it is out of reach, with the previous-second rule, the lead and another worker's mark;
  - a bus mark ahead of the clock, the mark written before the claim runs, a mark that cannot be written, and marks that only move forward;
  - a killed and restarted bus, and a flushed one;
  - challenges issued in MySQL, with the bus or without it, used once.
- `ClusterSemaphoreTest`:
  - no limit without the bus;
  - 4 permits per op, and each op's worst case;
  - the signed 503 with `retry_after_ms` and `op`;
  - release after the handler returns or throws;
  - expiry after the op's worst case, by the bus's clock;
  - workers' clocks that disagree drop no permit;
  - a permit taken before a clock step back, and a step of under 1 s.
- `ClusterApiTest`:
  - no MySQL row per request on the bus, with replays still refused;
  - a busy `hello` refused while `heartbeat` goes on, and a served one returning its permit;
  - `NOT_ACTIVE`, `BAD_MAC` and `REPLAY` before any permit;
  - `config` and `conn_snapshot` refused when busy;
  - a busy `token_rekey` that spends neither the minute nor the challenge;
  - on a fresh bus, a `heartbeat` and a `token_rekey` stamped at its floor, refused with `retry_after_ms`, then served.
- `ClusterEnrolCodeTest`: on a fresh bus, and after a restart, `enrol_code` and `enrol_code_status` stamped at its floor, refused with `retry_after_ms`, then served.

### The cluster pools (Phase 2, second increment)

**What they are.** MAIN serves the cluster API from two PHP-FPM pools of its own (`Domain\Cluster\ClusterPool`). A fleet's long-polls and ingest then never hold the workers that serve the panel and viewers, and a busy panel never delays a heartbeat.

| Pool | Ops (agent lanes) | `pm.max_children` | Timeout |
| --- | --- | --- | --- |
| `cluster_ctl` | `health`, `challenge`, enrolment, token ops, `hello`, `heartbeat`, `conn_admit`, `commands`, `ack` (poll, ctl) | 2·nodes + 24 | 60 s |
| `cluster_ingest` | `events`, `config`, `streams`, `conn_snapshot`, `stream_bundle`, `rpc_result`, `recording_complete`, `vod_analysis`, the three queue ops, `artefact` (p0, bulk) | min(2·`cluster_ingest_concurrency` + 8, floor(0.25 · MariaDB `max_connections`)), at least 2 | 90 s |

- **Nodes** are the streaming servers other than MAIN, enrolled or not, so a pool is sized before a node's first long-poll. Proxies do not count.
- **The floor of 2** keeps one P0 and one bulk request running when MariaDB's limit is tiny. The plan gives no floor.
- **Unknown ops** go to `cluster_ctl`, which refuses them.
- **Files.** Configs go in `bin/php/etc/cluster/<pool>.conf`, because `set_services` deletes and rewrites `bin/php/etc/*.conf` for the panel's pools. Sockets go in `bin/php/sockets/<pool>.sock`. Pid files go in `bin/php/var/run/`, because `restart_php_fpm` counts `sockets/*.pid` as panel pools.
- **Workers** are titled `php-fpm: pool cluster_ctl` (or `cluster_ingest`), so they never pass for a viewer's worker in `php_pids`.
- **Always there on MAIN.** The pools exist whether or not the API is enabled. With `pm = ondemand`, an idle pool is just its master.

**Routing.** nginx's `location ^~ /cluster/v1/` (fixed in `nginx.conf` at first, rendered since the third increment) passes to the upstream `cluster_ctl`. A nested regex location sends the ingest ops to `cluster_ingest`. Both upstreams list the panel pool `1.sock` as `backup`, so the API still answers while a pool is down or not yet created. The pool's own socket has `max_fails=0`: only a request that cannot reach the pool goes to the backup, and one failed request (a long-poll cut by a reload, say) never sends the pool's traffic to the panel pool for `fail_timeout`. `ClusterPoolTest` fails when the location's list differs from `ClusterPool::INGEST_OPS`, or when an op the API serves has no lane.

**Bringing them up.** `ClusterPool::ensure()`:

1. writes a pool's config when its size changed;
2. starts a pool whose master is not running, as xc_vm;
3. reloads (`SIGUSR2`) a running pool whose config changed. The reload lets requests finish for 5 s (`process_control_timeout`); a held long-poll is cut, and the agent polls again. The socket stays open meanwhile, and new requests queue on it;
4. writes the marker `tmp/cluster_ready`, when it is missing, once both pools answer FPM's own ping (`ping.path`, one FastCGI request over the socket). It removes the marker only when a pool's master is gone, and writes it again once the restarted pool answers.

A reload, or a pool too busy to answer, therefore keeps the marker: a resize from `cron:servers` never turns the fleet `STARTING` while held long-polls stretch the reload to 5 s. A pool whose master runs but has stopped answering keeps it too; its requests wait on the socket.

`status` runs it while XC_VM runs: at every boot, after the migrations, and after an update. `cron:servers` runs it every minute as a watchdog, which also resizes the pools as servers come and go. A lock keeps the two from starting a pool twice. A master is found by its title, which names its config, not by its pid file.

**As xc_vm only.** The configs, the lock and the marker live in directories xc_vm owns, and root would follow a link planted there, then hand its target to xc_vm or overwrite it. So `ensure()` does nothing unless it runs as the pool user, and `status` (root) runs it through `sudo -u xc_vm console.php cluster:pools`, which prints whether both pools answer.

**STARTING.** Until the marker exists, `Public/cluster/index.php` answers every op but `health` with a panel-signed `503 STARTING`. The denial names the node and request nonce when the request carries them, and asks for `retry_after_ms` 5000. `health` needs neither the database nor the pools, so it is answered throughout.

- **When it applies.** The service removes the marker whenever it starts, and tmp/ is a tmpfs. So a boot, a restart and an update each answer `STARTING` until the migrations have run and both pools answer.
- **Silence clock.** Creating the marker runs `ClusterMeta::markReady()`, so the time the API was `STARTING` never counts against a node's silence.

**Not built:**

- the plan's `cluster_ctl` listen-queue check, which should feed the fleet silence guard;
- the ingest permits on the bus.

The old-port servers, which still passed to the panel pool, reach the pools since the rendered nginx config.

### The rendered nginx config (Phase 2, third increment)

**What it is.** MAIN's nginx route for the cluster API is rendered by `Domain\Cluster\ClusterNginxConfig` instead of fixed in `nginx.conf`. It writes three files under `bin/nginx/conf/`:

| File | Included by | Holds |
| --- | --- | --- |
| `cluster_locations.conf` | the public `server{}`, and each server below | `location ^~ /cluster/v1/`, its ingest lane built from `ClusterPool::INGEST_OPS` |
| `cluster.d/listen.conf` | `http{}`, by the glob `cluster.d/*.conf` | a plain-HTTP server on `cluster_api_port`, only when it is not 0 |
| `cluster.d/old_port.conf` | the same glob | a server per old port `ClusterEndpoint` keeps, until its 7 days are up |

Both servers include the same location and answer 404 to everything else. The old ports therefore reach the cluster pools now, not a panel pool.

**Transport limits.** The location applies §3's numbers:

- `limit_req` zone `cluster`: 100 r/s keyed by `$realip_remote_addr`, the TCP peer, whatever `X-Forwarded-For` says; burst 400, `nodelay`, status 429. Before, the API shared the viewers' zone `one` (20 r/s per client, burst 40, status 503).
- `client_max_body_size 8m` and `gzip off`.

The zone is declared in `nginx.conf` itself, because the location cannot load without it.

**Default install.** With `cluster_api_port` = 0, `cluster.d/` stays empty and the location is the one `nginx.conf` used to hold, with those limits. The release ships `cluster_locations.conf` as rendered, so an update's `nginx -t` and the first boot see the same file. `ClusterNginxConfigTest` fails when the two differ.

**The public server keeps the location**, whatever `cluster_api_port` says. The plan does not say whether it should. It does, because the policy's HTTPS URLs use it, and the broadcast port's URL keeps working for a node that has not moved to the dedicated port yet.

**Port changes.** `ClusterEndpoint` used to handle the broadcast port only. A `cluster_api_port` change, saved in Settings, now goes the same way: the policy version goes up and the old port is kept for 7 days.

- While the API is on the broadcast port, that port is its URL. So 0 → N keeps the broadcast port listed in the policy (the public server serves it anyway), and N → 0 keeps N.
- With the API off, nothing is announced, as for the broadcast port.
- A kept port that MAIN serves again, on the public server or as the dedicated port, gets no server of its own.
- The policy lists kept ports whatever `cluster_api_port` is.

**Applying.** `apply()`:

1. renders from the settings and the ports the public server listens on (`ports/http.conf`, `ports/https.conf`). Called without settings, it reads `cluster_api_port` and `cluster_legacy_ports` from the database, not from the settings the process loaded;
2. checks that a new dedicated port is free: one nginx does not listen on yet by its files (`ports/`, `cluster.d/`) must bind. When it does not, nothing is written;
3. writes each file that differs, atomically (a temporary file, then a rename);
4. runs `nginx -t`. When it fails, it puts every file back as it was, audits `cluster.nginx` with nginx's message and reports the failure;
5. otherwise reloads nginx, unless the caller reloads it itself. After the reload a new dedicated port must accept connections within 3 s. When it does not, the previous files go back, nginx reloads again and the failure is reported.

When nothing differs there is no test and no reload. A lock serialises callers. A refusal that repeats the last one is not audited again (`cluster.d/.refused`), since `cron:cluster` retries every minute.

Steps 2 and 5 exist because neither `nginx -t` nor the reload's exit code says whether nginx can take a port. In test mode nginx binds the listen sockets but ignores `EADDRINUSE`, so `nginx -t` passes when another program holds the port. `nginx -s reload` exits 0 once the signal is sent. The master then fails to bind and keeps its previous config, and the next start (a reboot, `service xc_vm restart`, an update) fails with "still could not bind()", taking the panel and streaming down. Reproduced with nginx 1.24. The check in step 5 is a TCP connect, which a program that took the port between steps 2 and 5 would also pass.

A kept old port is not checked: nginx served it until the change, so nginx holds it. Any port in nginx's config can still be taken while XC_VM is stopped, which stops nginx from starting, as for the broadcast ports.

**Who runs it.**

- `status`, at boot and after an update, runs `sudo -u xc_vm console.php cluster:nginx`, reloading only while XC_VM runs.
- The root `set_port` handler runs `cluster:nginx --no-reload` after it writes the HTTP or HTTPS ports, before its own reload.
- `cron:cluster` runs it every minute, with the API on or off, after it drops expired old ports. It used to re-send MAIN's ports through `set_port`, only when a port expired. A render that matches the files is a no-op, so the minute retries a render that failed and undoes one that raced a settings save.
- A settings save that changes `cluster_api_port` (`ClusterNginxConfig::stageApiPort()`) runs it before the value is stored, with the new port and the kept one. The save is refused, and nothing changes, when another program listens on the port (`cluster_error_port_busy`), or when `nginx -t` fails or nginx does not serve the port after the reload (`cluster_error_nginx`, with nginx's message). After the `UPDATE`, `commitApiPort()` bumps the policy (`ClusterEndpoint::recordApiPortChange()`) only if the value was stored. Either way it renders again from what is stored: that undoes a render from the old value that ran in between (`status`, `set_port`, `cron:cluster`), and a failed `UPDATE` puts nginx back on the stored port. So no node is sent a URL that nginx refused or did not serve after the reload.

**As xc_vm only**, like the pools, because the files live in a directory xc_vm owns. Root no longer writes the old-port file.

**The old file.** The first render removes `cluster_legacy.conf`, but only once `nginx.conf` includes `cluster.d/`. An update whose `nginx.conf` was rolled back still reads the old file, so it stays.

That older `nginx.conf` reads neither `cluster.d/` file. While it is in place, `apply()` refuses any render that needs one (a dedicated port, or a kept old port), and a new `cluster_api_port` cannot be saved. Otherwise `nginx -t` would pass and the port would be stored and announced but never served. The API stays on the broadcast ports, through that `nginx.conf`'s fixed location. Its `cluster_legacy.conf` is not rendered again, so ports kept in it stay open until the next update installs an `nginx.conf` that passes `nginx -t`.

**Checked** with nginx 1.24:

- `testRealNginx` (opt-in, `XCVM_TEST_NGINX`): the rendered files pass `nginx -t`, and a broken include elsewhere restores the previous files.
- `ClusterNginxConfigTest` and `SettingsServiceClusterPortTest` fake nginx and the port checks. They cover the save path (`stageApiPort()`, `commitApiPort()`, the refusals in `SettingsService::edit()`), the rollback after a failed write or reload, the `nginx.conf` that predates `cluster.d/`, `cluster:nginx` and `cron:cluster`. `testTheFreeCheckBindsThePort` runs the real bind check against a port the test holds. The lock and the atomic write are not tested.
- By hand, with the real code and a running nginx master: a port a Python socket held was refused before anything was written. Once the port was free, it was served after the reload, the move to another port kept the old one served, and nginx restarted cleanly. With nginx stopped, a new port was rolled back. On the dedicated and the old ports, `/cluster/v1/` reached the pools and every other path got 404. Past the burst, nginx answered 429. There is no test for these checks.

**Not built:**

- releasing an old port before its 7 days once every node uses the new URL. Neither the policy version a node last fetched nor the URL it used is recorded;
- IPv6 listeners: the dedicated and old ports listen as `ports/http.conf` does, on IPv4.

### Re-enrolling the fleet (Phase 2, fourth increment)

**What it is.** `console.php cluster:reenrol (--all [--state=…] | <id>…) --cred-file=<path> [--dry-run]` (`ClusterReenrolCommand`) is the plan's `cluster:reenrol --all`. After `cluster:init` on a MAIN replaced without a DR bundle, it re-enrols the fleet over SSH, one node after the other. Each node goes through `ServerEnrolCommand::enrol()`, the path `server:enrol` takes, so each gets a new identity and generation.

**Which nodes.**

- `--all` takes the enrolled nodes in state `enrolling` or `active`; `--state=` names others.
- Revoked and quarantined nodes are skipped unless asked for, because both states record an admin's decision.
- Nodes named by id are taken whatever their state. A server that is not an enrolled load balancer is reported and left alone; `server:enrol` enrols a legacy LB.
- The states are read when the run starts, and each node's row is read again just before its turn. A run is sequential and can last tens of minutes, and the plan revokes a node on suspected compromise. So with `--all`, a node that an admin revoked, or the API quarantined, since the start is skipped, and a node no longer enrolled is left alone however it was chosen. The window left is the node's own enrolment, a few seconds.

**Credentials.** The plan names the command but not where its SSH credentials come from, and each node needs its own. One file carries them all:

```json
{"u": "root", "p": "…", "port": 22,
 "nodes": {"7": {"p": "…", "port": 2222, "hostkey": "SHA1:…"}}}
```

- The top level is every node's default. It has the shape of `server:install`'s credential file, so a fleet that shares one root password needs only that. `nodes` overrides it per server id.
- It must be a `.cred` file directly in `bin/install/`, like `server:enrol`'s, and owner-only (0600). Unknown keys, bad ports and bad host keys refuse the whole file.
- A run without `--dry-run` reads and deletes it before any other check, as `server:enrol` does. So a run refused for its arguments, a disabled API or a missing extension leaves no passwords on disk. A file that its group or others can read is refused and, on such a run, deleted as well, since its secrets are exposed already.
- The SSH port is the node's entry, else the file's default, else 22. The panel keeps no node's SSH port. `server:install` writes one to `bin/install/<id>.json` but deletes that file once the install succeeds, and a replaced MAIN does not have the old disk anyway. Keeping it in `servers` would need a core migration, so a node on another port gets `port` in the file instead.
- There is no `--expect-hostkey`: each node's `hostkey` goes in its entry.

**The same checks as `server:enrol`.**

- The SSH host key must match the node's `hostkey` in the file, else the one stored at its install (`ssh_hostkey_sha1`). A node with neither is never contacted: no trust on first use.
- The node must run this release, and its new keys must match their SAS.
- A failure never marks a live node failed.

**Failures.**

- A node that fails is reported with its reason, and the run goes on. The reason is `enrol()`'s own, or what `provisionCluster` printed when it stopped. `enrol()` passes that output through as it streams and keeps a copy. An exception, such as an SSH channel error, fails only its node.
- A node counts as enrolled only when it has a new `node_uuid` afterwards. `provisionCluster` returns true without enrolling when there is no `xc_agent` for the node's architecture, or when the API is off. `enrol()` reports that as a failure with the flow's own words, and the node keeps its previous identity (a legacy LB stays legacy). An earlier version compared the row's `created_at` with the start of the flow, which has one-second resolution.
- A licence refusal stops the run. Every later node would have its agent stopped only to be refused the same way. An extension that reports no licence (`info()['licensed']`) refuses before any node is touched.
- The exit code is 0 only when every chosen node was re-enrolled. A real run is audited as `cluster.reenrol`, with the ids that succeeded and those that failed. Each node's `node.enrol_start` is audited as before.

**One at a time.** `cluster:reenrol` holds `TMP_PATH/cluster_reenrol.lock` (non-blocking, as `cluster:root` does), so a second run refuses. `ServerEnrolCommand::enrol()` holds `TMP_PATH/cluster_enrol_<id>.lock` for its node. So `server:enrol` and a fleet run cannot interleave one node's `keygen` and `startEnrolment`.

**Dry run.** `--dry-run` contacts no node and changes nothing. It keeps the credential file for the real run, and it runs without one too. For each node it shows the user, the address and port, and the host key with where it comes from. It lists the nodes that cannot be attempted and why, for example no host key or no credentials.

**The SSH seam.** `SshSession` wraps the ssh2 session: connect, host key, password login, `SshChannel`'s run and send, close. `server:enrol` now runs through it too, and the tests replace it with `FakeSshFleet`. `SshSession` and `ClusterReenrolCommand` are stripped from the LB archive.

**Mode and flows.** A re-enrolled node starts over as a new node does: in the mode `lb_new_node_mode` gives, with every flow off (`NodeRegistry::startEnrolment`), as after `server:enrol`. The admin switches its flows on again.

**A failed node may need another run.** `provisionCluster` stops the node's agent and runs `keygen` before the probe. A licence refusal comes after `startEnrolment` has already replaced the node's row. So a node that fails at the probe or later is left with its agent stopped and new keys made, and should be re-enrolled again once the cause is fixed. `server:enrol` behaves the same. Re-enrol one node by id before `--all`: a cause that affects the whole fleet, such as MAIN's cluster port closed to the LBs, then stops at one node.

**Not built:**

- Nodes are re-enrolled one at a time, never in parallel.
- `--all` does not skip nodes already re-enrolled under the current root, so after a canary node, or a partial failure, the rest are best named by id. Telling them apart would need the time of the last root change. `cluster:init` records it in the audit log only, and a fleet-wide re-enrolment without a root change (new identities after a suspected compromise) must still take every node.

**Tests:**

- `ServerEnrolCommandTest`: one node's path. It covers no trust on first use, a changed key that runs nothing, the refusals before the flow, the flow's reason, a flow that enrols nothing (in the same second as a previous enrolment), and the node's lock.
- `ClusterReenrolCommandTest`: selection, including nodes revoked, quarantined or removed during the run; continuing past failures and exceptions; each node's port and password; the licence stop; the dry run; the credential file; the arguments; and `main()`, the command from its arguments on (`execute()` adds only the user check). `main()` shows that a dry run stays dry, that a refused real run still deletes the file, and that a second run refuses.

### Blocklist delta (Phase 7, first increment)

**The log.** Every path that blocks or unblocks something appends to `cluster_changes` (section `blocklist`) through `Core/Cluster/BlocklistChanges`. No triggers are used. Each row names the kind and the key that changed:

| Kind | Table | Key |
| --- | --- | --- |
| `ip` | `blocked_ips` | the address |
| `ua` | `blocked_uas` | `id` |
| `isp` | `blocked_isps` | `id` |
| `asn` | `blocked_asns` | `id` |
| `rtmp` | `rtmp_ips` | `id` |

A row does not say what the key became; MAIN reads that when it serves the change. Bulk changes record `reset` for their kind instead of keys: a flush, a whole ASN type, a migration from another panel, or more than 500 keys at once. Recording never fails the block itself.

**The paths.** The admin's block, edit and delete of each kind (`BlocklistService`, the ASN and MySQL-syslog actions, the ASN bulk buttons), the flushes (admin, API, `tools`), the flood guard on MAIN and on legacy LBs, the Ministra portal's bans, `cache_handler`'s signals, `security.block_ip`, MAIN's auto-unban in `cron:root_signals` (which now selects what it removes), and the migration. The ASN catalog sync is exempt: it only upserts reference columns and prunes unblocked ASNs, so no blocked ASN changes. `BlocklistDeltaTest` fails when a new file writes a blocklist table without logging it.

**The read.** `Domain/Cluster/BlocklistDelta::since($id)` gives a node everything past the last id it applied:

- Blocked IPs, the kind the flood guard changes all the time, come as `add` and `remove` lists. Each changed address is added if it is blocked now and removed otherwise, so the order of two changes to one address never matters.
- Every other kind changes rarely and by hand. A change to it, or a bulk `reset`, names the kind in `reload`, and the node takes the whole section again.
- `full` means there is no starting point: `since` is 0, the log was pruned past it, or the log went backwards (a restore).

`snapshot()` is the whole list. It holds each blocked address and ASN, and the user-agent, ISP and RTMP rows. The node needs the RTMP password to check publishers, so it is in the section; the section is sealed to the node.

**Pruning.** `cron:cluster` keeps seven days of the log, and always keeps its newest row, so a quiet week does not send every node into a full reload. It prunes even while the cluster API is off, because the log is written either way.

`allowed_ips`, `proxy_servers` and `allowed_domains` are not in this section. They come from `servers` and the settings, so they travel with those sections.

### The replica transport (Phase 7, second increment)

**Records.** `Domain/Cluster/ReplicaBuilder` builds what a node keeps. Each record is panel-signed and sealed to the node's X25519 key, with purpose `replica` and the node uuid as context:

```text
record = SEAL(node_box_pub, "replica", node_uuid, u32(len) ‖ payload ‖ sig(tag, payload))
rep  {v, section, node, gen, etag, seq, iat, data}   a whole section (granting)
blk  {v, seq, iat, add, remove}                      a blocklist delta
```

A `rep` record names the node and its generation. The ETag is the SHA-256 of the section's canonical data: keys sorted, numbers typed the same whichever driver read them.

`blk` is the extension's record, and its strict keys admit only plain strings. So only blocked IPs travel as deltas. Additions only restrict, so they sign without a licence and bans reach nodes even then. A removal, or a whole section, grants and needs the licence; without one, MAIN answers `LICENCE_INVALID` and the node keeps what it has.

**The `config` op.** The node sends `{blocklist_since, have: {blocklist: etag}}`. MAIN answers with one of four outcomes:

- a `blk` delta, when only IPs changed;
- the whole section, when there is no starting point or another kind changed;
- `unchanged`, when the node already holds the section's current ETag;
- nothing, when nothing changed.

A section carries the log head read before the snapshot, so a change made in between comes again as a delta. The op needs an active node but no flow, because the node fetches its replica in shadow before its CONFIG flow is switched on.

**The agent.** `internal/clusteragent/replica.go` calls `config` every minute, and again at once while `more` is set. It stores only records that open for this node and verify against the pinned panel key, and a `rep` must name this node and match what the reply announced. It keeps them as they came, under `config/cluster/replica/`: `blocklist.rep`, the deltas since it in `blocklist.d/<seq>.blk`, and `state.json`. A new section removes the deltas. Once a day, or past 1000 deltas, it asks from 0 with the ETag it holds, which is the plan's daily safety net. The plan puts the replica under `var/cluster/replica/`; it lives beside the agent's other state instead.

### Applying the blocklist (Phase 7, third increment)

**Materialising.** After a change, the agent writes `replica/blocklist.json`: the stored section with its deltas applied in seq order. It opens and verifies each record again first, and PHP holds no key to open them. It then runs `console.php cluster:apply` (`ClusterApplyCommand`, with the work in `Core/Cluster/ReplicaApply`).

**The caches.** `cluster:apply` turns the file into the four caches `cron:cache` builds from MAIN's database, in the same shapes, so every reader keeps its contract:

| Cache | Source |
| --- | --- |
| `blocked_ips` | the addresses |
| `blocked_servers` | the blocked ASNs |
| `blocked_ua` | `[id => {id, exact_match, blocked_ua}]`, lower-cased |
| `blocked_isp` | `[{id, isp, blocked}]` |
| `rtmp_ips` | `[resolved ip => {password, push, pull}]`, as MAIN's `cron:cache` builds it; an LB used to read the database for this |

What it does depends on the node's CONFIG flow:

- **CONFIG off (shadow).** Nothing is written. `replica/apply.json` counts, per cache, entries the database has that the replica lacks (`missing`) and the reverse (`extra`). Rows are compared by value, whichever driver typed them. Zeros there are the evidence for switching CONFIG on.
- **CONFIG on.** The replica writes the caches. `cron:cache` stops writing them, and `BlocklistService::getBlocked*` read the cache instead of refreshing it from the database. Without that, the next reader would overwrite the replica within 20 s.

The shadow diff for `rtmp_ips` compares against the database, because an LB never cached it. With CONFIG on, three more readers switch to the replica:

- `getAllowedRTMP`, which `rtmp.php` calls, reads the cache.
- `cron:root_signals` syncs iptables from the replica's `blocked_ips` cache (`RootSignalsCronJob::blockedIPs`).
- When that cache is not there yet, the sync leaves iptables as it is rather than unblocking everything.

**Not built:** the other R1 sections (`settings` with its allowlist, `secrets`, `servers`, `node`, `crontab`, `cluster`), `ReplicaStage`, and the mode-2 refusal. On the blocklist path, the root flush still arrives as a `signals` row, until `node.root blocklist_sync` replaces it.

### Disaster recovery of MAIN's cluster keys

`cluster:export-keys <file>` and `cluster:import-keys <file>` wrap `xcvm_core`'s `cluster_export_keys()` and `cluster_import_keys()` (ADR-002, "Disaster recovery"):

- **What the bundle holds:** the root, the revocation floors and the clock high-water.
- **How it is protected:** Argon2id (1 GiB, 4 passes) of a passphrase, plus a pepper that only an extension holds. The extension enforces the passphrase strength.
- **Where the passphrase comes from:** typed twice without echo, `--passphrase-file`, or one line on standard input. It is never an argument, which any user could read in the process list.
- **Export:** writes the file 0600 and never overwrites.
- **Import:** records the panel keys as `cluster:init` does and audits `cluster.import_keys`. The extension refuses a different root already on the machine (`ROOT_EXISTS`); the same root again is a no-op. Nodes then recover with `token_rekey`, because their epoch records were sealed to the old machine.
- **No bundle:** `cluster:init` already covers the plan's `cluster:reinit` (a new root, audited `cluster.root_changed`). Then `cluster:reenrol --all` re-enrols the fleet from one credential file (*Re-enrolling the fleet*), or `server:enrol` re-enrols one node.
- **Tests:** `ClusterDrTest` covers the commands. Opt-in, with `XCVM_EXT_SO`, it runs the real extension, shrunk by `XCVM_TEST_DR_MEM_KIB`, across two config dirs.
- **Operator procedure:** `docs/en/administration/backup-strategy.md`.

### Shared MariaDB and Redis before lockdown (Phase 2)

Until lockdown, legacy and hybrid LBs still use MAIN's MariaDB (3306) and Redis (6379), which listen on every interface.

- **Redis commands.** `CONFIG`, `DEBUG`, `SHUTDOWN`, `SLAVEOF`, `REPLICAOF`, `MIGRATE` and `MODULE` are renamed to `""`, which removes them: with the password alone, an attacker can no longer write files through `CONFIG SET dir`, replicate from a hostile host or load a module.
  - `FLUSHALL`, `FLUSHDB` and `EVAL` stay, because the panel uses them.
  - The shipped `bin/redis/redis.conf` carries the lines. Updates never overwrite `bin/redis`, so `status` appends them once to an existing install's config (`RedisConfigHardening`). They take effect when Redis next restarts.
  - An operator who needs a command renames it to a secret name; any `rename-command` line for it is left alone.
- **Allowlist.** `cluster_db_allowlist` is off by default. When on, only these may connect to the two ports:
  - loopback and MAIN's own addresses;
  - every other `servers` row (LBs and proxies) except nodes in cluster mode 2;
  - `cluster_db_allowlist_extra`.
- **Proxies.** Every proxy stays on the allowlist, because which proxies still hold a `db_grant` is not recorded.
- **Hostnames.** A `server_ip` given as a name is resolved to its IPv4 addresses.
- **The chain.** `DbAllowlist` keeps the rules in the chain `XCVM_DB`, jumped to from INPUT for the two ports, for IPv4 and IPv6, and touches no other rule.
- **Reconciliation.** `RootSignalsCronJob` reconciles the chain every minute on MAIN. It compares the live chain with the wanted one and rewrites it with `iptables-restore --noflush` only when they differ, so a flush, a reboot or a server change is repaired within a minute. If the settings or the servers table cannot be read, the firewall is left as it is.
- **The command.** `cluster:db-allowlist status` lists the allowed sources, whether each family's chain is in sync, and the established connections from outside the list (`ss`). `apply` and `undo` set the setting and act at once.
- **Audit.** Changes are audited as `cluster.db_allowlist`.
- **No shell.** Tools run with literal argv through `proc_open`.
- **Password rotation** is Phase 9's (credentials and lockdown).

### Extension updates

`console.php xcvm_core` rolls back an update whose cluster API falls outside the panel's range when the installed one was inside it. `console.php xcvm_core status` reports what is loaded. Installing an exact pinned version needs versioned paths in the binaries repo; that prerequisite is still open.

## Consequences

- Changing any formula in Canonical changes `cluster_canonical_vectors.json`. That is a protocol change: raise `proto` and keep accepting N−1, per the plan's mixed-version rules.
- A new extension API version needs `API_MAX` raised, and new vectors copied in, in the same panel release.
- The crypto pipeline measures about 0.25 ms p99 for a 64 KB request against the plan's 1 ms budget. It measures about 41 ms for 8 MB in the CI container against the plan's 40 ms target, because the body is hashed twice and encrypted twice. `ClusterCryptoBenchTest` guards 8 MB at 2× the target. It is opt-in (`XCVM_BENCH=1`), because wall-clock timings depend on the machine and must not fail the unit suite on a slower one. The target itself needs a check on bundled PHP and production hardware.
- `ClusterExtensionIntegrationTest` runs the panel against a real test-hooks build of `xcvm_core` (opt-in, throwaway `XCVM_CONFIG_DIR`). It passed against the 2.2.2 build at the time of writing.
