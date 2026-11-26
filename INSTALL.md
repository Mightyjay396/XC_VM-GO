# XC_VM-GO — unofficial fork of XC_VM 2.7.4 with the QC Go delivery engine

> [!WARNING]
> **Unofficial fork.** XC_VM-GO is a community fork of [Vateron-Media/XC_VM](https://github.com/Vateron-Media/XC_VM).
> It is **not** an official XC_VM release and is **not affiliated with, endorsed by or supported by Vateron Media**.
> Report problems with this fork in [this repository's issues](https://github.com/Mightyjay396/XC_VM-GO/issues), not to the upstream XC_VM project.
> For the official XC_VM, go to [github.com/Vateron-Media/XC_VM](https://github.com/Vateron-Media/XC_VM).


This unofficial fork ships upstream **XC_VM 2.7.4** plus the QC fixes, modules and the Go delivery engine, released as
**2.7.4-p4** (a release of this fork, not of XC_VM). It installs, adds load balancers and updates exactly like the original
XC_VM — every download points to this repository's Releases, so new servers always get this version.
No server data is included: no channels, source URLs, EPG, database, proxy list, keys or IP addresses.

## Install

**Fresh Main** (same as the original installer):

```bash
wget https://github.com/Mightyjay396/XC_VM-GO/releases/latest/download/XC_VM.zip && unzip XC_VM.zip && sudo python3 install
```

**Load balancers**: add them from the panel (*Servers → Install Load Balancer*). The Main downloads this
release's `loadbalancer.tar.gz`, checks it against `hashes.md5`, installs it and starts the Go engine.

**Updates**: the panel's update button and *Auto-update load balancers* use this repository's Releases.
Each node is pinned to the Main's version, as in the original XC_VM.

**Existing XC_VM 2.7.2 / 2.7.4 panels** (keep your install, add the patches): download
`qc_xcvm_updater.tgz` from the Release and run on the Main:

```bash
cd /root && rm -rf qc_xcvm_pkg && tar xzf qc_xcvm_updater.tgz && python3 qc_xcvm_pkg/qc_xcvm_updater.py --restart --all-lbs
```

`--all-lbs` also updates every enabled load balancer over SSH (a dedicated key is installed on first run,
the root password is asked once), one at a time, stopping at the first failure. Other options:
`--dry-run`, `--no-deps`, `--rollback <dir>`, `--force`.

## Go delivery engine — replaces FFmpeg (installed and started on every load balancer)

| Program | Local port | Role |
|---|---|---|
| `odrelay` | 8147 | **Replaces FFmpeg for all on-demand channels** (MPEG-TS and HLS sources, incl. HLS with separate audio, per-stream proxy): one shared source connection per channel, first byte in ~1 s |
| `ffmpeg-golive` | — | **Replaces FFmpeg for all live channels by default** (plain copy to HLS); hands over to FFmpeg for transcoding, filters, logos, encrypted HLS, fMP4 |
| `livehold` | 8150 | Keeps viewers connected through restarts and source drops (offline slate instead of a disconnect), TS + HLS |
| `ckts` | 8160 | ClearKey DASH → MPEG-TS engine, with 15 `mpdproxy-ads` relays (8115-8129) for ad breaks |
| `mpdproxy` | 8098-8113 | DASH/ClearKey proxy (manifests + segments, cache), 16 socket-activated instances |
| FFmpeg 8.1-dash | — | Probes DASH/ClearKey sources and runs those not handled by `ckts` (`bin/ffmpeg_bin/8.1-dash`) |

FFmpeg remains installed and is used only for what Go does not do: transcoding, filters, logos, encrypted HLS / fMP4,
several outputs. This is not XC_VM's fanout (left as is). `config/odrelay_streams.txt`, `config/livehold_streams.txt` and
`/etc/qc_ckts_ids` contain `*` (all channels); replace with stream IDs to limit. `config/golive_streams.txt` contains `*` by
default (all live channels in Go); put stream IDs (one per line) to limit, or empty it to keep all live channels on FFmpeg
(an existing file is never overwritten by an update).
A channel switches at its next (re)start. Panel settings (segments,
prebuffers, wait/scan, fail sleep, probe) drive the Go engine and apply without a restart. The stream list shows a
`go` badge for channels run by Go, and on-demand channels served by Go show their stats. The Main dashboard shows a
**Go delivery** line in *Service Status* listing the servers running the engine.
The binaries ship in the release archives (`bin/qc/`), installed by `bin/qc/qc_deps.sh` (run as root by the
installer and by every update).

Sources in `qc_patches/go/`: `odrelay` (same as the shipped binary; also built as `ffmpeg-golive`), `livehold` and `mpdproxy` (earlier
sources than the shipped binaries). `ckts` ships as a binary only. Build: `cd qc_patches/go/<prog> && CGO_ENABLED=0 go build`.

## XC_VM fixes

- **Active TS/VOD viewers disconnected (XC_VM 2.7.2 and 2.7.4 bug)** — `Domain/Cluster/ConnectionIngest.php`:
  on nodes that reap their own HLS viewers, TS worker check-ins (every 300 s) only reached the cluster bus,
  while `cron:users` reads the connection store; after 900 s it took the viewer for gone and closed it
  (sessions cut at exactly 10, 15, 20 min). TS/VOD check-ins now reach the store too. HLS is unchanged.
- Ghost connections: records whose worker died are cleaned (`Core/Cluster/QcGhostReaper.php`), and only
  really active connections count toward the connection limit.
- Channels still started by FFmpeg (DASH sources, encrypted HLS / fMP4, transcoding, filters, logos): shorter
  FFprobe for on-demand starts, on-demand channels re-armed after a failed start, `-rw_timeout` + reconnect options
  on HTTP sources. On-demand channels served by Go skip FFprobe entirely; live channels run by Go keep XC_VM's
  normal source probe and use its `-rw_timeout` as their read timeout.
- HLS continuity: channels kept by `livehold` restart without breaking the playlist (files kept, numbering continues).
- DASH / ClearKey: `ckts` (Go) decrypts to MPEG-TS, then the live channel is copied by Go; `mpdproxy` (Go) caches
  manifests and segments; FFmpeg 8.1-dash probes these sources and runs the ones not handled by `ckts`.
- Movies and series: repair and import (TMDB, TVDB fallback), batch import.
- EPG: per-channel time shift.
- Source proxies: management page + per-channel "Proxy (list)" option (`source_proxies` table, empty).
- Tickets: unread indicator.
- Ubuntu 26 support in the load balancer installer.

## Modules and pages (Main)

- **Reseller credit refunds** (`refundcredit`, `refund_history` table) + admin line extension.
- **Trial API** (`trialapi`, nginx route `/trial_api`) and **Line API** (`/line_api`).
- **Provider import** (`providerimport`).
- **XAccel-compatible API** (`/xaapi/`), configured in `config/xaccel_compat_api.json`.
- New tables come from migration `migrations/database/up/106_qc_tables.sql`.

## Repository layout

```
src/                 XC_VM 2.7.4-p4 (Main tree; release archives add bin/qc/ with the Go binaries)
qc_patches/updater/  qc_xcvm_updater.py + patched files for 2.7.2 and 2.7.4 (existing panels)
qc_patches/go/       Go sources (odrelay / ffmpeg-golive, livehold, mpdproxy)
qc_patches/build_dist.py + qc_deps.sh   how the release assets are built (binaries: release archives, bin/qc/)
```

The previous content of this repository is kept in the `pre-qc-backup` branch.
