# ADR 0004 — Cluster API between MAIN and load balancers: the panel's contract

- **Status:** Accepted. Phase 0 (seams), Phase 1 (crypto contract, schema, settings) and Phase 2's API, Go agent and SSH enrolment of new LBs (below) are implemented. Enrolling existing LBs over SSH (`server:enrol`), `token_rekey` and enrolment by code are too. The admin page *Servers → Cluster Nodes* and `cron:cluster` are too. Phase 3 (authoritative telemetry, the 1 s liveness loop, MAIN endpoint changes) is too. Phase 4 has its command channel (RPCs and viewer kills) and root commands. Phase 5 (logs, stream state, content and the fanout's monitor feed as events) is too. Phase 6 has remote kills and viewer drops as commands, the connection store seam, the agent's connection registry, connection limits enforced on MAIN, and the connection digest with snapshots and seeding; admission and the agent's HLS reaper are not in yet. Phases 6–11 are not.
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
8. the nonce claim;
9. the node state;
10. opening the BOX.

Refusals are panel-signed (`den`) and name the node and the request nonce. `STARTING` (no extension) is the one unsigned reply, and agents treat it as a transport error.

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
  6. the once-a-minute limit (429 `RATE_LIMITED` with `retry_after_ms`), charged per authenticated attempt;
  7. the SEAL opens (`cluster_open_sealed`);
  8. the challenge is live (180 s) and unused (401 `CHALLENGE`). Consuming it is a second claim in `cluster_nonces`, so two concurrent uses cannot both win;
  9. the attestation: an `instance_id` other than the enrolled one quarantines the node (409 `NOT_ACTIVE`, state `quarantined`);
  10. the mint. A licence refusal returns 403 `LICENCE_INVALID` and leaves the node's rows as they were.
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

What only PHP knows, nginx requests per second and the fanout daemon's status, the LB's watchdog writes to `config/cluster/local.json`. The agent forwards that file while it is under 10 s old.

For a node with the TELEMETRY flow on (mode ≥ 1, toggled per node on the Cluster Nodes page), `HeartbeatService` makes the sample authoritative:

- **Every 5 s:** `servers.watchdog_data`, `last_check_ago`, `requests_per_second` and `php_pids`; without the Redis handler, also `connections` and `users`, counted as the watchdog counted them. `toWatchdogData()` keeps the legacy `SystemInfo::getStats()` keys and order, plus `cpu_average_array` and `fanout`. `ClusterTelemetryTest` pins that against `getStats()`'s source. `network_interface` selects interfaces as before.
- **Every minute:** the `servers_stats` row the LB's `cron:servers` wrote.
- **Not yet reported:** GPU, iostat and capture devices are reported empty.

The node learns its mode and flows from MAIN's authenticated replies. The agent writes them to `config/cluster/flows.json`, and `Core\Cluster\NodeFlows` reads them. With TELEMETRY on:

- the LB's watchdog stops writing its `servers` row and only refreshes `local.json`;
- `cron:servers` skips its `servers_stats` row;
- `network.py` is stopped.

A stopped agent removes `flows.json`, so the node falls back to the legacy paths.

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
- The root-side `set_port` handler renders `bin/nginx/conf/cluster_legacy.conf` on MAIN: one server block per kept port, serving `/cluster/v1/` and a 404 for everything else. `nginx.conf` includes it by glob, so a missing file is no error.

When the 7 days are up, `cron:cluster` drops the port, bumps the policy again and re-applies MAIN's ports so nginx releases it.

This was checked with nginx 1.24:

- `nginx -t` passes with and without the file.
- On the old port, only `/cluster/v1/` reaches PHP.

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

- its `last_seen_at` is older than `cluster_orphan_conn_ttl_sec`;
- MAIN's reaper has itself watched it stay silent that long (`TMP_PATH/cluster_orphans.json`).

A gap of more than 3 minutes between reaper passes restarts the watch, so MAIN's own downtime never orphans a node.

**The orphan purge.** Every CONNECTIONS node is watched this way, whether or not its agent reaps. An orphaned node's rows, HLS and TS alike, are purged from MAIN's store only (`ConnectionIngest::purgeNode`, audited as `conn.orphan_purge`), so they stop counting toward their lines' limits. The purge sends no kill and no command: the node's registry still holds its viewers. If the node comes back, its digest disagrees and a snapshot restores them. Before this, a dead node's TS rows stayed for ever, because the reaper kept trusting the node's last `php_pids` list, and it skips daemon-served rows (pid 0) altogether.

**Touches.** Touches still reach MAIN every 10 s, because a panel that predates this reaps by the 30 s rule. Moving them to the bus (`conn.touch`, every 60 s) waits for the bus. `conn.divergence` is not built: divergence still reaches `lines_divergence` the legacy way.

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

A CONNECTIONS node already makes no WAN call for limits: it spools `conn.limit`. So these matter only when the cluster bus replaces MAIN's store.

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

### The cluster bus (Phase 2, first increment): wake-ups

**What it is.** The cluster bus is MAIN's own Redis instance for the cluster API (`Domain\Cluster\ClusterBus`). It runs the bundled `redis-server` with `bin/cluster_bus/cluster.conf`, and is separate from the shared Redis that the panel and legacy LBs use.
- **Access:** only a unix socket, `bin/cluster_bus/cluster.sock`, mode 0700, owned by xc_vm. There is no TCP port, and the admin commands are renamed away.
- **Persistence:** none, because nothing in it has to survive a restart.
- **Where it runs:** MAIN only. `service` and `ServiceCommand` start it, and `ServersCronJob` revives it. LB builds strip `bin/cluster_bus`.
- **Liveness checks:** it runs the same binary as the shared Redis, so `ServersCronJob` tells the two apart by process title: `redis-server unixsocket:…` for the bus, `redis-server *:6379` for the shared one. Before this, a running bus would have hidden a dead shared Redis.

**What it carries.** Wake-ups, and the admission reservations (`ConnectionAdmission`, seventh Phase 6 increment):
- **`wake:<sid>`:** `CommandBus::enqueue` pushes it, and the `commands` long-poll waits on it. The long-poll used to re-read `cluster_commands` every 250 ms for up to 20 s per node. It now reads once, blocks on the bus, and reads again when woken. While it blocks it holds no MySQL connection: it closes the handle first (`waitNodeReleasing`), and `DatabaseHandler` reconnects on the next read.
- **`ack:<cmd_id>`:** `CommandBus::ack` pushes it, and `CommandBus::await` (an RPC waiting for its answer) waits on it instead of polling every 100 ms.

**How a wake works.** A wake is a one-element list with a 60 s TTL, taken with `BLPOP`:
- a wake pushed just before the waiter blocks is not lost;
- repeated wakes collapse into one;
- a stale wake costs one extra query.

**Without the bus.** If the bus is not running, or this is an LB or a test, `waitNode`/`waitAck` return null and the callers poll as before.

**Still to come on the bus:**
- nonces;
- telemetry (`cl:tel:<sid>`);
- the `conn.touch` state;
- per-op semaphores.

### Disaster recovery of MAIN's cluster keys

`cluster:export-keys <file>` and `cluster:import-keys <file>` wrap `xcvm_core`'s `cluster_export_keys()` and `cluster_import_keys()` (ADR-002, "Disaster recovery"):

- **What the bundle holds:** the root, the revocation floors and the clock high-water.
- **How it is protected:** Argon2id (1 GiB, 4 passes) of a passphrase, plus a pepper that only an extension holds. The extension enforces the passphrase strength.
- **Where the passphrase comes from:** typed twice without echo, `--passphrase-file`, or one line on standard input. It is never an argument, which any user could read in the process list.
- **Export:** writes the file 0600 and never overwrites.
- **Import:** records the panel keys as `cluster:init` does and audits `cluster.import_keys`. The extension refuses a different root already on the machine (`ROOT_EXISTS`); the same root again is a no-op. Nodes then recover with `token_rekey`, because their epoch records were sealed to the old machine.
- **No bundle:** `cluster:init` already covers the plan's `cluster:reinit` (a new root, audited `cluster.root_changed`), followed by `server:enrol` per node. There is no fleet-wide `cluster:reenrol --all`, because each node needs its own SSH credentials.
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
