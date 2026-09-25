# Cluster seams

The MAIN ↔ LB API plan (`docs/superpowers/specs/2026-09-21-main-lb-api-communication-design.md`)
replaces the node's direct access to MAIN's MySQL and Redis with a signed API. Phase 0
gets the code ready without changing any behaviour. Every place a node reaches MAIN's
state, and every place MAIN reaches a node, goes through one class. Each class has one
backend today, the legacy one, which does exactly what the call sites used to do.
Later phases swap that backend and leave the call sites alone.

Each seam has a hook for tests and for the future transport (`useSink()`,
`useLoader()` or `useTransport()`). Passing `null` restores the legacy backend.

## Node → MAIN

| Seam | Wraps | Legacy backend | API backend (phase) |
| --- | --- | --- | --- |
| `Core\Cluster\SignalDispatcher` | the 47 `INSERT INTO signals` sites (kill, cache jobs, root actions) | `LegacySqlSignalSink` | commands and events (4/5) |
| `Domain\Stream\StreamStateWriter` | a node's runtime state in `streams_servers`; refuses any column outside `STATE_FIELDS` | `StreamRowMerge::apply()` | `stream.state` event (5) |
| `Domain\Stream\StreamSource` | the stream row, this node's `streams_servers` row and the stream options, read before running a stream | SQL | R2 stream delta, `stream_bundle` on a miss (5) |
| `Core\Cluster\LogSink` | client, stream, stream-error, panel-error and restream-detection records | one multi-row INSERT per batch (chunks of 1000) | `log.*` events, redacted first (5) |

## MAIN side

| Seam | Role |
| --- | --- |
| `Domain\Stream\StreamRowMerge` | Merges a node's runtime state into that node's row only. `eventFields()` keeps only runtime columns and redacts the source URL. |
| `Domain\Stream\StreamCacheBuilder` | The `stream_<id>` cache entry: columns, per-server rows, and the rule that a stream's source URLs stay out of the cache unless it is a direct source. `cron:cache_engine` builds it with this class. |
| `Core\Cluster\Redactor` | Strips `password=`, `token=` and `username=` values, the `/user/pass/` segments of Xtream URLs, and `user:pass@` from text before it is journaled. |

## MAIN → node

| Seam | Wraps | Catalogue | API form (phase 4) |
| --- | --- | --- | --- |
| `Core\Cluster\NodeRpc` | `ApiClient::systemRequest()` / `asyncRequest()`: request/response calls to a node's `/api` | `NodeRpc::ACTIONS` | `node.rpc{action}`, answered via `ack` / `rpc_result` |
| `Core\Cluster\NodeActions` | root actions for `RootSignalsCronJob`: reboot, services, update/rollback, ports, sysctl, certbot, modules, blocklist flush, `OPENSSL_EXTRA` | `NodeActions::ROOT_ACTIONS` | `node.root{action}` for `cluster:root` |

Both seams refuse an action that is not in their catalogue, so a new call is a
deliberate change. `NodeRpcActionsTest` checks that every call site uses a
catalogued RPC action and that `RootSignalsCronJob` handles every catalogued root
action.

## Rules for new code

- Do not write `INSERT INTO signals`, `UPDATE streams_servers` (for runtime state),
  or an INSERT into a log table directly; use the seam.
- Do not call `ApiClient::systemRequest()` / `asyncRequest()` or
  `SignalDispatcher::rootAction()` outside `Core\Cluster`; add the action to the
  catalogue and use `NodeRpc` / `NodeActions`.
- Node-side code reads stream definitions through `StreamSource`, not with its
  own queries on `streams_options`.

Tests that pin these rules: `SignalDispatcherParityTest`, `StreamStateWriterTest`,
`StreamRowMergeTest`, `StreamCacheBuilderSourceTest`, `LogSinkTest` and
`NodeRpcActionsTest`.
