# ADR 0004 — Cluster API between MAIN and load balancers: the panel's contract

- **Status:** Accepted. Phase 0 (seams) and Phase 1 (crypto contract, schema, settings) are implemented; Phases 2–11 are not.
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

The Go agent (`internal/clustercrypto` in XC_VM_Fanout) must pass both vector files. That package is not written yet.

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

### Extension updates

`console.php xcvm_core` rolls back an update whose cluster API falls outside the panel's range when the installed one was inside it. `console.php xcvm_core status` reports what is loaded. Installing an exact pinned version needs versioned paths in the binaries repo; that prerequisite is still open.

## Consequences

- Changing any formula in Canonical changes `cluster_canonical_vectors.json`. That is a protocol change: raise `proto` and keep accepting N−1, per the plan's mixed-version rules.
- A new extension API version needs `API_MAX` raised, and new vectors copied in, in the same panel release.
- The crypto pipeline measures about 0.25 ms p99 for a 64 KB request against the plan's 1 ms budget. It measures about 41 ms for 8 MB in the CI container against the plan's 40 ms target, because the body is hashed twice and encrypted twice. `ClusterCryptoBenchTest` guards 8 MB at 2× the target; the target itself needs a check on bundled PHP and production hardware.
- `ClusterExtensionIntegrationTest` runs the panel against a real test-hooks build of `xcvm_core` (opt-in, throwaway `XCVM_CONFIG_DIR`). It passed against the 2.2.2 build at the time of writing.
