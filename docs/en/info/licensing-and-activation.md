# Licensing & Activation

XC_VM is **free and open-source** under the **GNU Affero General Public License
v3.0 (AGPL-3.0)**. This page discloses, in full, how the panel's licensing,
attribution check and activation work — including exactly what the panel sends
to the licensing server and when. Nothing here is hidden or covert.

## Dual-licensing model

| You keep the attribution notice                                                                         | You remove the attribution (white-label)                                                                                                                                     |
| ------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **AGPL-3.0 — free.** Everything works, including load-balancer nodes. No activation, no server contact. | **Requires activation.** A free, machine-bound activation key is needed to run load-balancer / cluster nodes. The panel still runs locally on a single server without a key. |

Keeping the _"Vateron Media · AGPL-3.0"_ credit (as AGPL-3.0 **§7(b)** expressly
permits requiring) is all a community deployment needs. The activation path
exists only for operators who remove that attribution — it is the point at which
Vateron Media, as the sole copyright holder, offers a separate arrangement rather
than AGPL.

!!! note "Activation keys are free"
Keys are issued **free**, self-service, one per machine (HWID). They are not
sold. Their purpose is accountability — a revocable record of who is running a
rebranded copy — not monetization.

## Attribution-integrity check (AGPL §7b)

On each request the panel verifies its attribution notice is still present in the
footer. If it has been removed, the **management UI** (admin / reseller / player)
shows an `ATTRIBUTION_REMOVED` notice until it is restored. The check is
**reversible and non-destructive**: no data is changed, the CLI keeps working, and
restoring the notice unlocks the panel on the next request. **End-viewer streaming
is not affected** by this check.

## What activation gates

For a **white-label** install (attribution removed) without a valid key:

- **Load-balancer / cluster nodes cannot be provisioned** — the compiled core
  refuses to grant a remote node access to the panel database.
- **High-capacity live delivery (the fanout daemon) is disabled**, so delivery
  falls back to the legacy path. A single-server panel remains usable.

A **community** install (attribution intact) is never gated and never contacts the
licensing server.

## How to activate

1. Find your **HWID** — shown in **Settings → Info** and on the dashboard banner
   (`XC_VM::install_id()`).
2. Submit it on the activation page and receive a key bound to that HWID.
3. Enter the key in the panel (dashboard activation banner). The panel verifies it
   **offline** against a public key compiled into the extension — no round-trip is
   required to start using it.

## Data the panel sends — full disclosure

A **community install (attribution intact) sends nothing** — it never contacts the
licensing server.

A **white-label install** communicates with the licensing server
(`https://www.xcvm.tech`)

**What is never sent:** no stream data, no viewer/subscriber data, no account
credentials, no database contents, no file contents. There is no remote command
channel — the panel is never instructed by the server to do anything; it only
reads a signed _valid / revoked_ verdict for its own key.

## Provenance

Each build is stamped with a unique identifier (`XC_VM_BUILD_ID`), so a leaked or
rebranded copy can be traced back to the build it originated from.

---

> ⚖️ You are solely responsible for how XC_VM is used. Vateron Media takes no
> responsibility for misuse or illegal deployments.
