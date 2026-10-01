# Per-node viewer-token keys (H1, D7): design

H1 is Phase 11's first item (D7, recommended yes) in the [MAIN ↔ LB plan](2026-09-21-main-lb-api-communication-design.md).

## Problem

A load balancer serves a viewer on the strength of a token alone. On an API-mode (mode-2) node, `live.php`, `vod.php` and `timeshift.php` take user, line, channel and admission data from the token MAIN minted at the redirect (`auth.php`), and check nothing else.

Every token is sealed with one fleet-wide secret, `live_streaming_pass` keyed by `OPENSSL_EXTRA`. MAIN ships both to every node in the replica's `secrets` section. So whoever holds one node holds the secret, and can mint tokens that MAIN and every other node accept:
- any channel;
- any line, or none;
- any connection limit.

Rotating the stream secret (`cluster:rotate-stream-secret`) ends this only until the next node is taken.

## Goal

A token minted for node *n* opens only on *n*. A stolen node can then forge access only to itself, which it serves anyway.

**Non-goals:**
- **Lease-gating the open.** A thief who controls the node also controls its PHP.
- **Proxies.** Their routing prefix, `md5(… OPENSSL_EXTRA)`, is D8.

## Where a node uses the shared secret today

These are the uses in the LB archive (`make lb`, 47 files):

| Group | Uses | In mode 2 |
| --- | --- | --- |
| A. Tokens MAIN mints, read on the node | the redirect token (`auth.php` → `StreamAuthMiddleware`), the admin player's `uitoken` (`AdminStreamToken`), subtitle, thumb and RTMP tokens, the off-air redirect (`OffAirHandler`) | per-node key |
| B. Tokens the node mints for itself | HLS segment and key tokens (`HLSGenerator` → `segment.php`, `key.php`), timeshift HLS | per-node key (its own) |
| C. Viewer APIs (player_api, Enigma2, playlists) | mint tokens for any server | already 404 in mode 2 (D16) |
| D. The shared password | legacy relays (`relayUrl`'s fallback), `RelayGuard`'s legacy branch, RTMP push/pull, `/api` (404 in mode 2), module install | the parent's check needs only a hash; a child pulling from a legacy parent needs the value, until the whole fleet relays on tickets |
| E. Data at rest | `hmac_keys` (decrypted where HMAC lines are authenticated, i.e. MAIN's `auth.php`), image-cache file names (viewer APIs) | not used on a mode-2 node |
| F. Proxy route prefixes | `md5(server_originator_OPENSSL_EXTRA)` | unchanged (D8); `OPENSSL_EXTRA` alone mints nothing |
| G. The legacy cron lock path | a hash of the secret | a fixed id since Phase 0; it must tolerate no secret |

## Design

### The key

`K_n = HMAC-SHA256(live_streaming_pass, "xc_vm viewer key v1|" ‖ server_id_n)`.
- **No new state on MAIN.** MAIN stores nothing new; it derives `K_n` when it mints.
- **Why the server id.** `auth.php` already has the servers cache on its hot path, where the cluster uuid would cost a lookup per viewer request. The containment is the same, since a key opens tokens on its own server only.
- **Rotation comes free.** Rotating the stream secret rotates every `K_n`. While `StreamSecret::previous()` is accepted, MAIN also ships `K_n` under the previous secret, so links already in players' hands keep opening.
- **Format.** Tokens keep today's sealed format (`Encryption::seal`, AES-256-GCM), with `K_n` (hex) in place of `live_streaming_pass` and the same `OPENSSL_EXTRA` context. Per-node tokens are always sealed, whatever `secure_stream_tokens` says: only new code reads them.

### Shipping, and when MAIN uses it

- **Shipping.** The replica's `secrets` section to a cluster node also carries `viewer_key` (and `viewer_key_prev` inside the previous secret's window). It is sealed to the node's box key like the rest of the section, and the node keeps it in `config/viewer_key` (0600).
- **The node reports.** It reports the key's fingerprint, `viewer_key_fp`: the entry's `kid` (`ReplicaSections::kid`), in its node state (`NodeStateSink`, the new `servers.viewer_key_fp` column).
- **When MAIN uses it.** MAIN mints for *n* with `K_n` only while *n* reports the fingerprint of the current `K_n`. Otherwise it mints with the shared secret, as today.

This makes the rollout self-synchronising:
- a node on older code never reports, so it keeps getting tokens it can read;
- a node that hasn't applied a rotation yet reports the old fingerprint, so it keeps the shared-secret tokens until it catches up.

### Minting on MAIN

`ViewerKeys::mint($data, $rServerID, $rSettings)` (`Domain/Cluster`, MAIN-only):
- **Which node.** `$rServerID` is the node that will read the token: the channel's `originator_id` when a proxy fronts it, else its `redirect_id`.
- **Which key.** It returns `seal(data, K_n, OPENSSL_EXTRA)` when *n* qualifies (above), else today's `mintToken(...)`.
- **Call sites.** Every group-A mint site goes through it: `auth.php` (nine sites), `OffAirHandler::showVideoServer`, the admin player (`StreamViewController`, `player.php`), and the subtitle, thumb and RTMP tokens. MAIN-local tokens (MAIN serves them itself) stay on the shared secret.

### Reading on the node

`Encryption::readToken` tries the node's own keys first (`config/viewer_key`, then `viewer_key_prev`), then the shared secret while the node still holds one.
- **One choke point.** Group A and group B readers all go through `readToken`, so they need no change.
- **Group B.** Group B mints switch to the node's own key when it has one (`ViewerKeys::own()`, available on every node).

### Taking the shared secret away (the containment)

Per-node keys alone change nothing while the node also holds the shared secret. MAIN stops shipping `live_streaming_pass` to a mode-2 node at lockdown (§10 of the plan): from then, every relay goes on tickets and the legacy password is no longer needed by any child.
- **The section.** The `secrets` section then carries `viewer_key`, `openssl_extra`, and `streaming_pass_hash` (SHA-256 of the value) instead of the value. The hash is enough for `RelayGuard` and RTMP to check a legacy password a parent still receives.
- **The rotation.** Lockdown already rotates the stream secret (D20), so the value every node held before becomes useless.
- **The node's `readToken`** then has only its own keys.

## Increments

Increments 1–3 are built (ADR 0004, "Per-node viewer-token keys"); the fourth is optional.

1. **Keys and minting.**
   - `ViewerKeys` (derive, fingerprint, own-key file).
   - The `secrets` section carries `viewer_key`; the node installs it and reports `viewer_key_fp`.
   - MAIN mints group A for a node that reports the current fingerprint.
   - `readToken` tries the node's own keys first.
   - Behaviour is unchanged for a node that doesn't report.
2. **The node's own tokens.** Group B minted with the node's own key.
3. **Containment.** At lockdown, `streaming_pass_hash` replaces the value in the `secrets` section of mode-2 nodes; `RelayGuard`, RTMP and the cron lock path work from the hash or without the secret; a test checks that no mode-2 code path reads `live_streaming_pass`.
4. **Optional: the key in `xcvm_core`.** The extension would derive `K_n` from PRK, and the node's copy would open only inside the extension while the lease holds. It adds non-extractability and lease-gating against unmodified code, not against a thief who controls the box. It needs an extension release. Recommended only if the owner wants it.

## Tests

- **Unit.**
  - A token minted for *n* opens on *n* and not on *m*, nor with the shared secret.
  - A node that doesn't report the fingerprint, or reports a stale one, gets shared-secret tokens.
  - The rotation window: `K_n` under the previous secret still opens.
  - Group B tokens round-trip with the own key.
  - Increment 3: with no `live_streaming_pass`, the node's readers and `RelayGuard` (hash) still work.
- **E2E.** `lb-delivery-kinds` passes unchanged (every kind is served on the LB through the per-node key), and a token for the LB replayed against MAIN or another node is refused.
