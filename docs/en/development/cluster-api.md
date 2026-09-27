# Cluster API (MAIN ↔ LB)

A load balancer used to reach MAIN's MariaDB and Redis directly: its crons, daemons and
streaming endpoints held the panel's database credentials and wrote MAIN's tables. The
cluster API replaces that with one signed, encrypted HTTP channel the node opens to MAIN.
This page is the protocol and the operator's view of it; the design record with every
decision and its history is [`docs/adr/0004-cluster-api.md`](https://github.com/Vateron-Media/XC_VM/blob/main/docs/adr/0004-cluster-api.md),
and the code seams each call goes through are in [Cluster seams](cluster-seams.md).

!!! info "Plain HTTP is the default"
    The channel does not need TLS. Every request is signed and its body encrypted with keys
    derived from the node's token, so a panel with no certificate loses nothing by talking
    over `http://`. HTTPS is optional and, when a certificate verifies, preferred — see
    [Transport](#transport-and-endpoints).

## What travels

The node always dials MAIN; MAIN never connects to a node (its instructions wait for the
node to ask for them). Every call is `POST /cluster/v1/<op>` on MAIN's HTTP broadcast port,
or on `cluster_api_port` when one is set.

| Direction | Carried as | Examples |
| --- | --- | --- |
| Node → MAIN, needs an answer | an op on the control lane | `hello`, `heartbeat`, `token_refresh`, `conn_admit`, `commands`, `ack` |
| Node → MAIN, a report | an op on an ingest lane | `events`, `streams`, `conn_snapshot`, `queue_claim`, `queue_update`, `queue_enqueue`, `config`, `recording_complete`, `vod_analysis`, `artefact` |
| MAIN → node | a signed command the node's next `commands` call collects | `node.rpc`, `node.root`, `node.cache`, `conn.kill_worker`, `conn.drop`, `conn.close`, `config.changed`, `artefact.fetch` |

Two ops the plan lists are deliberately never served: `rpc_result` (a command's result comes
back inline with its `ack`, which takes 64 KiB) and `stream_bundle` (the replica's `streams`
section carries a stream's whole record, and a cache miss reads it from disk). They stay in
the op list because nginx's ingest lane is rendered from it, and the API refuses an op it
does not serve.

### Lanes

Events are spooled by priority and drained by the agent:

| Lane | What | Pace |
| --- | --- | --- |
| P0 | a viewer opening or closing, a stream's state | at once |
| P1 | logs, the node's inventory, its audit | batched |
| P2 | HLS touches | batched, only while MAIN takes them |

MAIN serves the control ops on the `cluster_ctl` FPM pool and the ingest ops on
`cluster_ingest`, sized by `cluster_ingest_concurrency`; half its permits are reserved for
P0 so a flood of logs can never delay a viewer. A node that arrives while MAIN is starting
is refused with `STARTING` and a back-off, never a 500.

## Authentication

There is no shared secret in a file and no bearer credential on the wire.

1. **Enrolment.** MAIN gives the node an identity (`node_uuid`, an Ed25519 key pair the node
   generates itself, MAIN's pinned public key) over SSH at install, or through a one-time
   code the operator reads out (`cluster:enrol-code`, approved with `cluster:enrol-approve`).
2. **The token** is minted inside the `xcvm_core` extension from the panel's root secret and
   the licence, bound to the node's uuid, generation and epoch. It never exists in PHP as
   plaintext: the node receives it sealed to its own key.
3. **Every request** carries a timestamp, a nonce and an HMAC over a canonical
   representation of the request, with the body encrypted (AES-256-GCM). MAIN replies the
   same way. A replayed request is refused by the nonce store; a request whose clock is off
   by more than the allowed skew is refused.
4. **Rotation.** The token's lifetime is `lb_token_rotation_min` (5–1440, default 60). The
   agent refreshes at half its life; a refused token re-keys rather than stopping the node.
   Revoking the licence stops new tokens being minted at all, which is how a fleet is
   fenced.

## Transport and endpoints

`cluster_transport`:

| Value | Meaning |
| --- | --- |
| `auto` (default) | HTTPS first when MAIN's own certificate verifies, else plain HTTP |
| `http` | plain HTTP only |
| `https_preferred` | HTTPS first, plain HTTP as the fallback |
| `https_required` | HTTPS only; refused unless MAIN's certificate verifies **and** every active node has already reached MAIN over HTTPS (its agent reports the `https` feature) |

The URLs a node dials, their order and the transport are MAIN's *policy*, versioned by
`cluster_policy_ver`. A node adopts a policy that is not older than the one it holds, and
remembers the plain-HTTP URLs of every policy it has seen: under `https_required` with a
broken certificate, the signed challenge over plain HTTP is its way back.

Changing MAIN's address, its HTTPS port or `cluster_api_port` keeps the old endpoint served
for seven days so no node is lost; `cluster:endpoint list` shows what is kept and
`cron:cluster` releases a port once every node has moved off it.

The policy also carries the fleet's heartbeat (`lb_telemetry_interval_sec`, 1–3 s), so
changing it reaches every node with the next policy and no agent restart.

## Flows and modes

What a node does through the API is switched per node, on the *Cluster Nodes* page. A flow
moves one part of its work to the agent and stands the legacy path down.

| Bit | Flow | Moves |
| --- | --- | --- |
| 1 | TELEMETRY | the server's stats: MAIN writes the `servers` row from the heartbeats |
| 2 | COMMANDS | kills, cache jobs and root actions arrive as signed commands |
| 4 | LOGS | client, stream, error, activity and on-demand records become `log.*` events |
| 8 | STREAMS | the node's streams come from its replica, its runtime state from its own store |
| 16 | CONTENT | recordings, VOD analysis and the encoding queue go through MAIN |
| 32 | CONFIG | the node's caches are built from the replica, not MAIN's database |
| 64 | CONNECTIONS | the node's viewers live in its agent's registry |
| 128 | DATAPLANE | the relay and file data plane (Phase 8, not built) |

`mode` is the node's independence:

| Mode | Name | Meaning |
| --- | --- | --- |
| 0 | legacy | reads MAIN's database as it always did |
| 1 | hybrid | boots from its replica, may still reach MAIN's database |
| 2 | api | every connect to MAIN's MariaDB or Redis is refused in code (`LbDatabaseAccessException`) |

Moving a node up is gated (`ClusterAdmin::modeGate()`): mode 1 needs CONFIG; mode 2 needs
every flow but the data plane **and** the node's own connect audit clean — zero MySQL and
zero Redis connects for seven days. Moving down is always allowed, because it is the way
back. A crontab row whose role is `legacy` (today `cron:users`) is not sent to a node in
mode 2 at all.

## The node replica

While CONFIG is on, the node's caches are built from what the agent stored, not from a
query. MAIN serves the sections; `cluster:apply` turns them into the caches the node's
readers use (a shadow diff before the flow is on, so an operator sees what would change):

| Section | Carries |
| --- | --- |
| `settings` | the settings a node reads, never a secret |
| `servers` | every server's row, without liveness or telemetry |
| `node` | the node's own row and its node settings |
| `crontab` | the enabled rows whose role fits the node's mode |
| `cluster` | MAIN's URLs, the transport, the heartbeat, the panel keys, `min_proto` |
| `secrets` | the viewer-token secret and `OPENSSL_EXTRA`, each with the value it replaced and how long that is still accepted |
| `bouquets`, `categories` | as `cron:cache` builds them |
| `streams` (R2) | one record per stream the node holds, kept by deltas and a resync |

## Settings reference

| Setting | Bounds | What reads it |
| --- | --- | --- |
| `cluster_api_enabled` | 0/1 | the whole API; needs `cluster:init` and the extension |
| `cluster_api_port` | 0 or 1024–65535 | 0 serves the API on MAIN's HTTP broadcast port |
| `cluster_main_host` | hostname | MAIN's DNS name in the URLs nodes dial |
| `cluster_transport` | see above | the policy |
| `lb_token_rotation_min` | 5–1440 (60) | the token's lifetime; grace is `clamp(L/4, 5, 60)` |
| `lb_revocation_mode` | graceful \| hard | how fast a fleet stops after a licence revocation |
| `lb_telemetry_interval_sec` | 1–3 (2) | the fleet's heartbeat, carried by the policy |
| `cluster_offline_after_sec` | 10–300 (30) | silence before MAIN marks a node offline |
| `cluster_orphan_conn_ttl_sec` | 30–3600 (120) | silence before MAIN purges a node's viewers |
| `lb_offline_admission` | local \| allow \| deny | admitting viewers while MAIN is unreachable |
| `cluster_kill_on_line_disable` | 0/1 (1) | a disabled, locked or expired line loses its sessions |
| `cluster_ingest_concurrency` | 1–64 (6) | MAIN's ingest permits; half reserved for P0 |
| `lb_new_node_mode` | legacy \| api | the mode a newly installed LB enrols at |
| `servers_stats_retention_days` | 1–365 (30) | `cron:cleanup` prunes `servers_stats` |
| `cluster_audit_retention_days` | 1–365 (30) | `cron:cleanup` prunes `cluster_audit` |
| `cluster_agent_upgrade_parallel` | 1–50 (1) | nodes upgraded at once by `cron:cluster` |
| `cluster_db_allowlist` (+`_extra`) | 0/1 | firewalls 3306/6379 on MAIN to the fleet |
| `lb_scan_roots` | paths | the directories the node's scan RPC may list |
| `lb_partition_tolerance_h`, `lb_fence_drain_min` | 0–24, 0–60 | Phase 9 (not built) |

## Operating it

```bash
# MAIN, once: create the cluster root and record the panel keys
console.php cluster:init

# Enrol a load balancer over SSH (or at install time, automatically)
console.php server:enrol <serverID>

# Without SSH: a one-time code the operator reads out, then approves by its SAS
console.php cluster:enrol-code <serverID>
console.php cluster:enrol-approve <serverID> <SAS>

# The node's own side, run by its agent after every change
console.php cluster:apply            # --from-disk at boot
console.php cluster:exec             # one signed command

# MAIN's endpoint and pools
console.php cluster:nginx            # render and reload the API's nginx config
console.php cluster:pools            # start or resize the API's FPM pools
console.php cluster:endpoint list    # old ports and URLs still served

# Before switching CONNECTIONS on: load the node's viewers into its agent
console.php cluster:seed-connections <serverID>

# The agent binary MAIN pins, per architecture
console.php agent_binary [amd64|arm64|armv7|386] [force]

# Firewall MariaDB and Redis to the fleet (check first)
console.php cluster:db-allowlist status | apply | undo

# Disaster recovery of the cluster root
console.php cluster:export-keys   /path/bundle
console.php cluster:import-keys   /path/bundle
```

The *Cluster Nodes* page is where a node is approved, its flows switched, its mode moved and
its state read (epoch, token expiry, last seen, agent version and architecture, settings
misses, connect audit). Every decision is written to `cluster_audit`, which
`cron:cluster` prunes.

### A cutover, in order

1. `cluster:init` on MAIN, `cluster_api_enabled` on, `cluster:nginx`, `cluster:pools`.
2. Enrol the node; approve it if it came by code.
3. Switch TELEMETRY, then COMMANDS, then LOGS — each is reversible and visible on the page.
4. Switch STREAMS and CONTENT; watch the node's settings misses and its audit.
5. Switch CONFIG, then `mode_up` to 1: the node now boots from its replica.
6. `cluster:seed-connections`, then CONNECTIONS.
7. Leave it for a week. When the node's connect audit shows zero MySQL and zero Redis
   connects for seven days, `mode_up` to 2.
8. `cluster:db-allowlist apply` once every node is in mode 2.

## Limits

- **The relay and file data plane is not built** (Phase 8). Cross-server pulls — a child
  relaying a stream, a VOD or subtitle fetch, a created-channel source — still carry
  `password=<live_streaming_pass>` in their URL, which means the secret is in the argv of
  the processes that run them. The ticket and digest primitives exist
  (`Core\Cluster\Crypto\Ticket`, `FileDigest`) and nothing uses them yet; `/xfile`, the
  loopback URLs and the agent's relay proxy come with them, as one change.
- **The licence lease, the fence and the credential lockdown are not built** (Phase 9). A
  node in mode 2 refuses its own connects in code; MAIN's MariaDB still has a grant for it
  until `cluster:db-allowlist` closes the port, and `lb_new_node_mode=api` is refused until
  that phase ships.
- The viewer-token secret can be *replaced* gracefully (the value it replaces stays readable
  for ten minutes, fleet-wide), but a full rotation — re-encrypting what is stored under it
  — is Phase 9's.
- A node's `whitelist_ips` is the admin's: a node no longer publishes its own addresses,
  because that column grants the legacy `/api` allowlist.
