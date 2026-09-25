# ADR 0004 — Cluster API between MAIN and load balancers: the panel's contract

- **Status:** Accepted. Phase 0 (seams), Phase 1 (crypto contract, schema, settings) and Phase 2's API, Go agent and SSH enrolment of new LBs (below) are implemented. Enrolling existing LBs over SSH (`server:enrol`), `token_rekey` and enrolment by code are too. The admin page *Servers → Cluster Nodes* and `cron:cluster` are too. Phase 3 (authoritative telemetry, the 1 s liveness loop, MAIN endpoint changes) is too. Phase 4 has its command channel (RPCs and viewer kills); root commands are not in yet. Phases 5–11 are not.
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

The extension derives the class from `type`. Kills and stops are restrictive and sign without a licence; the rest need it. A `dedupe_key` replaces a not-yet-acked command for the same desired state. Commands expire (`conn.*` 5 min, default 10 min), and `cron:cluster` prunes them.

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

Root actions (`NodeActions`) stay on the signals table until `cluster:root` and the root-owned panel-key pin exist.

### Extension updates

`console.php xcvm_core` rolls back an update whose cluster API falls outside the panel's range when the installed one was inside it. `console.php xcvm_core status` reports what is loaded. Installing an exact pinned version needs versioned paths in the binaries repo; that prerequisite is still open.

## Consequences

- Changing any formula in Canonical changes `cluster_canonical_vectors.json`. That is a protocol change: raise `proto` and keep accepting N−1, per the plan's mixed-version rules.
- A new extension API version needs `API_MAX` raised, and new vectors copied in, in the same panel release.
- The crypto pipeline measures about 0.25 ms p99 for a 64 KB request against the plan's 1 ms budget. It measures about 41 ms for 8 MB in the CI container against the plan's 40 ms target, because the body is hashed twice and encrypted twice. `ClusterCryptoBenchTest` guards 8 MB at 2× the target; the target itself needs a check on bundled PHP and production hardware.
- `ClusterExtensionIntegrationTest` runs the panel against a real test-hooks build of `xcvm_core` (opt-in, throwaway `XCVM_CONFIG_DIR`). It passed against the 2.2.2 build at the time of writing.
