<h1 align="center">XC_VM-GO</h1>
<p align="center">
  <b>Unofficial fork of the XC_VM IPTV panel, with a Go delivery engine</b><br>
  Based on <a href="https://github.com/Vateron-Media/XC_VM">Vateron-Media/XC_VM</a> 2.7.4 · not an official XC_VM release
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Mightyjay396/XC_VM-GO" /></a>
  <a href="https://github.com/Mightyjay396/XC_VM-GO/stargazers"><img src="https://img.shields.io/github/stars/Mightyjay396/XC_VM-GO?style=flat" /></a>
  <a href="https://github.com/Mightyjay396/XC_VM-GO/issues"><img src="https://img.shields.io/github/issues/Mightyjay396/XC_VM-GO" /></a>
  <a href="https://github.com/Mightyjay396/XC_VM-GO/releases/latest"><img src="https://img.shields.io/github/v/release/Mightyjay396/XC_VM-GO?label=latest%20release" /></a>
  <a href="https://github.com/Mightyjay396/XC_VM-GO/releases/latest"><img src="https://img.shields.io/github/release-date/Mightyjay396/XC_VM-GO?label=released" /></a>
</p>

<p align="center">
  <b>Latest update: 2.7.4-p7 — released November 27, 2026</b> · <a href="#-release-history">Release history</a> · <a href="https://github.com/Mightyjay396/XC_VM-GO/releases">All releases</a><br>
  Get notified of new updates: <b>Watch → Custom → Releases</b> (top of this page)
</p>

> [!WARNING]
> **Unofficial fork.** XC_VM-GO is a community fork of [Vateron-Media/XC_VM](https://github.com/Vateron-Media/XC_VM).
> It is **not** an official XC_VM release and is **not affiliated with, endorsed by or supported by Vateron Media**.
> Report problems with this fork in [this repository's issues](https://github.com/Mightyjay396/XC_VM-GO/issues), not to the upstream XC_VM project.
> For the official XC_VM, go to [github.com/Vateron-Media/XC_VM](https://github.com/Vateron-Media/XC_VM).

---

# 📑 Table of Contents

<details>
<summary><strong>📘 Contents</strong></summary>

- 🏁 [Overview](#-overview)
- 🆕 [What This Fork Changes](#-what-this-fork-changes)
- ⚠️ [Status](#️-status)
- 📚 [Documentation](#-documentation)
- 🔄 [Migration Guide](#-migration-guide)
- 🧱 [Technology Stack](#-technology-stack)
- 🧩 [Supported OS](#-supported-operating-systems)
- 📥 [Quick Install](#-quick-install)
- 🧰 [Service Management](#-service-management)
- 📂 [Project Structure](#-project-structure)
- 🧮 [Server Requirements & Sizing](#-server-requirements--sizing)
- ⭐ [Features](#-features)
- 🐞 [Known Limitations](#-known-limitations)
- 🤝 [Contributing](#-contributing)
- ⚠️ [Legal & Licensing Notice](#️-legal--licensing-notice)
- 📜 [License (AGPL-3.0)](#-license-agpl-30)

</details>

---

## 🚀 Overview

**XC_VM-GO** is an unofficial fork of [XC_VM](https://github.com/Vateron-Media/XC_VM), the open-source IPTV panel
based on Xtream Codes. It keeps XC_VM 2.7.4 as its base and adds:

- ⚡ a **Go delivery engine that replaces FFmpeg** wherever FFmpeg only copied the stream: every on-demand channel,
  and every live channel that is a plain copy to HLS (by default). FFmpeg is kept only for what Go cannot do (transcoding, filters,
  logos, encrypted HLS / fMP4). Viewers kept through source drops, ClearKey DASH → TS, DASH proxy
- 🩹 a fix for active TS/VOD viewers being **disconnected every 10–20 minutes** (XC_VM 2.7.2 / 2.7.4)
- 🧩 extra modules: reseller credit refunds, trial API, line API, provider import, XAccel-compatible API, source proxies
- 🔁 its **own releases**: installs, new load balancers and panel updates all come from this repository

Everything else (live & VOD streaming, load balancing, users/resellers, transcoding, EPG) is XC_VM's and works the same.

> ✅ Free and open source (AGPL-3.0), like XC_VM. No license checks. No server locks.

---

## 🆕 What This Fork Changes

| Area | Upstream XC_VM 2.7.4 | XC_VM-GO 2.7.4-p7 |
| --- | --- | --- |
| Stream delivery on load balancers | PHP / fanout | + Go engine: **odrelay**, **livehold**, **ckts**, **mpdproxy** (started automatically, survive reboots) |
| On-demand channels | PHP starts FFmpeg per channel; viewer waits for the probe + first segment (4–9 s measured) | **Go replaces FFmpeg**: one shared source connection per channel, first byte in ~1.1–1.3 s, TS and HLS sources (incl. HLS with a separate audio rendition), per-stream proxy |
| Live channels (copy) | One FFmpeg process per channel | **Go engine by default** (`ffmpeg-golive`) for every plain copy-to-HLS channel; FFmpeg is kept automatically for transcoding, filters/logos, encrypted HLS / fMP4 |
| Panel | Shows `ffmpeg` / stats only for FFmpeg-run streams | `go` badge on channels run by Go; on-demand channels served by Go show codecs, resolution, FPS, bitrate, CPU/RAM |
| Panel settings | Used by FFmpeg | Also drive the Go engine (segment time/list/delete threshold, prebuffers, wait/scan/fail-sleep, instant-off), applied without restart |
| Source drops / FFmpeg restarts | Viewers disconnected | Viewers stay connected (offline slate) |
| ClearKey DASH sources | Not supported | ClearKey DASH → MPEG-TS in Go (`ckts`), Go DASH proxy with cache, FFmpeg 8.1-dash for probing |
| Active TS/VOD viewers | Cut every 10–20 min (check-in bug) | Fixed |
| Reseller credits | No refund | Credit refunds with history |
| APIs | Standard | + trial API, line API, provider import, XAccel-compatible API |
| Source proxies | — | Per-stream source proxies |
| Dashboard *Service Status* | — | "Go delivery" line per server |
| Install / updates / new LBs | Vateron-Media releases | This fork's releases |

Not included on purpose: no server data, credentials, streams, customers or EPG — every install starts empty.

---

## ⚠️ Status

> **BETA SOFTWARE** — XC_VM itself is in beta, and this fork follows XC_VM releases with a delay.
> Current fork release: **2.7.4-p7** (released **2026-11-27**), based on XC_VM 2.7.4.

---

## 📅 Release History

Newest first. Dates are UTC. Each release lists its changes on its [release page](https://github.com/Mightyjay396/XC_VM-GO/releases); the panel's update screen shows them too.

| Version | Released | Highlights |
| --- | --- | --- |
| [**2.7.4-p7**](https://github.com/Mightyjay396/XC_VM-GO/releases/tag/2.7.4-p7) (latest) | **2026-11-27** | **Go reconnects like FFmpeg, panel shows the right engine**: a dead or mute source makes Go exit so XC_VM restarts the channel with its own timings, still on Go (no more permanent switch to FFmpeg after source cuts); channels running an updated Go binary are shown as Go again instead of FFmpeg |
| [2.7.4-p6](https://github.com/Mightyjay396/XC_VM-GO/releases/tag/2.7.4-p6) | 2026-11-26 | **CPU fix on busy load balancers**: the `ckts` sound check (~30 runs/s of the system ffmpeg, ~200 libraries loaded each time) now uses a small static AAC decoder (`/opt/qc-ffaac`), and Go live channels no longer start PHP every minute to re-read unchanged panel settings |
| [2.7.4-p5](https://github.com/Mightyjay396/XC_VM-GO/releases/tag/2.7.4-p5) | 2026-11-26 | **ClearKey engine sized to the server**: `ckts` memory limit = 10 % of RAM (4–32 GiB) and at most a third of the cores (4–32), computed at start instead of a fixed 8 GiB |
| [2.7.4-p4](https://github.com/Mightyjay396/XC_VM-GO/releases/tag/2.7.4-p4) | 2026-11-26 | **CPU fix for live channels in Go**: HLS sources with separate audio leaked one task per second and ended up using many CPU cores; Go live channels now use at most 4 threads and their memory limit follows real use |
| [2.7.4-p3](https://github.com/Mightyjay396/XC_VM-GO/releases/tag/2.7.4-p3) | 2026-11-25 | **Go is the default for live channels too**: every plain copy-to-HLS live channel runs on Go (`golive_streams.txt` = `*`), no list to maintain; FFmpeg only for transcoding, filters, logos, encrypted HLS / fMP4 |
| [2.7.4-p2](https://github.com/Mightyjay396/XC_VM-GO/releases/tag/2.7.4-p2) | 2026-11-25 | Go replaces FFmpeg for on-demand channels (TS + HLS, separate-audio HLS, per-stream proxy); opt-in Go live engine `ffmpeg-golive`; `go` badge and on-demand stats in the panel; panel settings drive the Go engine |
| [2.7.4-p1](https://github.com/Mightyjay396/XC_VM-GO/releases/tag/2.7.4-p1) | 2026-11-25 | First fork release: XC_VM 2.7.4 + Go delivery engine (odrelay, livehold, ckts, mpdproxy), check-in disconnect fix, modules, fork-based install / updates |

**How updates reach you:** an installed panel checks this repository's latest release (Settings → Updates) and installs it with XC_VM's normal updater; load balancers added afterwards get the same version.

---

## 📚 Documentation

> The guides below are the **official upstream XC_VM documentation**. They do not cover this fork's changes:
> see [INSTALL.md](INSTALL.md) for those.

- 🇬🇧 **English Guide**
  [https://vateron-media.github.io/XC_VM/](https://vateron-media.github.io/XC_VM/)

- 🇷🇺 **Руководство на русском**
  [https://vateron-media.github.io/XC_VM/ru/](https://vateron-media.github.io/XC_VM/ru/)

---

## 🔄 Migration Guide

Migrating from Xtream Codes / XUI.one? Follow the step-by-step migration guide:

- 📖 **Migration Guide**
  [https://vateron-media.github.io/XC_VM/latest/info/migration_guide/](https://vateron-media.github.io/XC_VM/latest/info/migration_guide/)

---

## 🧱 Technology Stack

| Component | Version       | Description                     |
| --------- | ------------- | ------------------------------- |
| PHP       | 8.1.33        | Backend runtime                 |
| Nginx     | 1.24          | Web server & reverse proxy      |
| FFmpeg    | 8.0, 7.1, 4.0 | Transcoding, filters, logos and other non-copy jobs (copy jobs: Go engine) |
| MariaDB   | 11.4          | SQL database engine             |
| KeyDB     | 6.3.4         | Cache & session storage (Redis) |
| Go engine | odrelay, livehold, ckts, mpdproxy | Stream delivery on load balancers (this fork) |
| FFmpeg    | 8.1-dash      | DASH / ClearKey probing and fallback (this fork) |

---

## 🐧 Supported Operating Systems

XC_VM supports multiple Linux distributions. Distribution-specific binaries are downloaded automatically during installation: PHP and Nginx from [XC_VM_Binaries](https://github.com/Vateron-Media/XC_VM_Binaries), FFmpeg from [XC_VM_FFMPEG](https://github.com/Vateron-Media/XC_VM_FFMPEG).

### Ubuntu

| Version   | Codename        | Status                                                                                     |
| --------- | --------------- | ------------------------------------------------------------------------------------------ |
| **18.04** | Bionic Beaver   | ❌ _Not supported_                                                                          |
| **20.04** | Focal Fossa     | ⚠️ _Outdated_ — installation possible, but some packages may need to be installed manually |
| **22.04** | Jammy Jellyfish | ✅ **Recommended**                                                                         |
| **24.04** | Noble Numbat    | ✅ **Recommended**                                                                         |

### Debian

| Version | Codename | Status             |
| ------- | -------- | ------------------ |
| **12**  | Bookworm | ✅ Supported       |
| **13**  | Trixie   | ✅ **Recommended** |

### RHEL-compatible (Rocky Linux, AlmaLinux, CentOS, RHEL)

| Version | Status                 |
| ------- | ---------------------- |
| **8**   | 🚧 _Not yet supported_ |
| **9**   | 🚧 _Not yet supported_ |

> ⚠️ RHEL-family support is planned but not yet available: there are no pre-built binaries or FFmpeg builds for it, and the installer refuses these distributions.

---

### 💡 Recommendations

For new installations:

- 🟢 **Ubuntu 22.04 / 24.04 LTS**
- 🟢 **Debian 13**

> ⚠️ Ubuntu 18.04 and Debian 11 are not supported: the installer warns on them and asks before going on.

---

## 📥 Quick Install

> ✅ Ubuntu 22.04+, Debian 12+ — installs **XC_VM-GO 2.7.4-p7** (unofficial fork): upstream XC_VM 2.7.4 + this fork's fixes, modules and Go delivery engine (odrelay, livehold, ckts, mpdproxy)

```bash
# 1. Update system
sudo apt update && sudo apt full-upgrade -y

# 2. Install dependencies
sudo apt install -y curl wget python3 unzip

# 3. Download latest release
latest_version=$(curl -s https://api.github.com/repos/Mightyjay396/XC_VM-GO/releases/latest | grep '"tag_name":' | cut -d '"' -f 4)
wget "https://github.com/Mightyjay396/XC_VM-GO/releases/download/${latest_version}/XC_VM.zip"

# 4. Unpack and install
unzip XC_VM.zip
sudo python3 install
```

**Load balancers:** add them from the panel (*Servers → Install Load Balancer*). The Main installs this
fork's `loadbalancer.tar.gz` (same version as the Main) and starts the Go delivery engine automatically.

**Updates:** the panel update and *Auto-update load balancers* download this fork's releases, exactly like the original XC_VM.

**Already running official XC_VM 2.7.2 / 2.7.4?** Add the patches without reinstalling — on the Main:

```bash
cd /root && rm -rf qc_xcvm_pkg qc_xcvm_updater.tgz && wget -q https://github.com/Mightyjay396/XC_VM-GO/releases/latest/download/qc_xcvm_updater.tgz && tar xzf qc_xcvm_updater.tgz && python3 qc_xcvm_pkg/qc_xcvm_updater.py --restart --all-lbs
```

`--all-lbs` updates every load balancer too (root password asked once per server).
See [INSTALL.md](INSTALL.md) for the full guide: Go engine, fixes, modules and options.

---

## 🧰 Service Management

```bash
sudo systemctl start xc_vm     # Start
sudo systemctl stop xc_vm      # Stop
sudo systemctl restart xc_vm   # Restart
sudo systemctl status xc_vm    # Status
sudo /home/xc_vm/bin/nginx/sbin/nginx -s reload    # Reload Nginx config
journalctl -u xc_vm -f         # Live logs
```

Go delivery engine (load balancers):

```bash
systemctl status odrelay livehold ckts             # Status
pgrep -c ffmpeg-golive                             # Live channels currently run by Go
cat /home/xc_vm/config/golive_streams.txt          # * = all live channels in Go (default); ids = only those; empty = none
systemctl list-units 'mpdproxy*' --no-pager        # DASH proxy instances
sudo bash /home/xc_vm/bin/qc/qc_deps.sh            # Reinstall / restart the Go engine
```

---

## 🔍 Troubleshooting — Panel Won't Start

If the panel fails to start, check the following logs and commands:

**Nginx error log:**

```bash
cat /home/xc_vm/bin/nginx/logs/error.log
```

**PHP-FPM log:**

```bash
cat /home/xc_vm/bin/php/var/log/php-fpm.log
```

**Verify PHP binary works:**

```bash
/home/xc_vm/bin/php/bin/php -v
```

> If PHP fails to run, the panel cannot start at all — fix PHP issues first before investigating Nginx.

---

## 📂 Project Structure

```text.
├─ docs/        # 📚 Project documentation
├─ lb_configs/  # ⚙️ Configurations for building Load Balancer (LB)
└─ src/         # 💻 Main project code
```

---

## 🧮 Server Requirements & Sizing

### 🔧 Minimum Specs

| Component | Recommendation                            |
| --------- | ----------------------------------------- |
| CPU       | 6+ cores (Xeon/Ryzen)                     |
| RAM       | 16–32 GB                                  |
| Disk      | SSD/NVMe, 480+ GB                         |
| Network   | Dedicated 1 Gbps port                     |
| OS        | Ubuntu 22.04+, Debian 12+ (clean install) |

---

### 📊 Planning Formulae

- **Bandwidth (Mbps)** = Channels × Bitrate
- **Max Users** = Bandwidth ÷ Stream Bitrate

```text
Example:
HD bitrate = 4 Mbps
1 Gbps = ~940 usable Mbps

→ Max Channels: 940 ÷ 4 = ~235
→ Max Users:    940 ÷ 4 = ~235
```

> ⚠️ 10 users watching the same channel = 10× bandwidth (unless caching or multicast used)

---

### 💻 RAM & CPU Usage

| Resource         | Load per Stream |
| ---------------- | --------------- |
| RAM              | 50–100 MB       |
| CPU (transcoded) | ~1 core         |

#### Fanout RAM

With fanout delivery on, each online channel keeps one ring buffer that serves both the TS prebuffer and the HLS window, and all its viewers read from it, so RAM scales with channels, not clients:

- **Ring seconds** = max(Fanout HLS Window × Segment Time, Client Prebuffer + Segment Time), capped at 120 (defaults: max(6 × 6, 30 + 6) = 36 s)
- **Fanout RAM** ≈ Inbound (MB/s) × Ring seconds × 3
- The ×3 is the daemon's real overhead over raw stream data (Go heap headroom, GOP buffer capacity, recycled buffers), measured on a production LB
- A channel with no viewers for 30 s shrinks to ~50% of its ring (`Fanout Idle Buffer Grace` / `Fanout Idle Buffer Ratio`)
- Each client adds only socket/nginx buffers (a few MB at most for a slow viewer)

```text
Rule of thumb (default settings): ~15 GB of RAM per 1 Gbps of inbound channels

Measured: 352 channels, ~2 Gbps inbound, 36 s ring
→ 250 MB/s × 36 s × 3 ≈ 27 GB  (xc_fanout RSS: 29.2 GiB, ~85 MiB per channel)
→ the same with 10 or 1000 viewers
```

> To shrink it, lower the ring: the larger of `Fanout HLS Window × Segment Time` and `Client Prebuffer + Segment Time` sets it, so lowering only one of them may change nothing.

---

## ✅ Features

From XC_VM:

- ✅ No server restrictions
- ✅ EPG importer
- ✅ VOD management
- ✅ User/reseller panel
- ✅ Security patches
- ✅ Clean UI

Added by this fork: see below and [What This Fork Changes](#-what-this-fork-changes).

### ⚡ Go Delivery Engine — replaces FFmpeg (this fork)

Installed and started automatically on every load balancer (not XC_VM's fanout, which stays as is).
**In upstream XC_VM, every channel is an FFmpeg process started by PHP. In XC_VM-GO, the Go engine does that work
whenever FFmpeg would only copy the stream**, and FFmpeg is kept only where it is really needed.

| Job | Upstream XC_VM | XC_VM-GO 2.7.4-p7 |
| --- | --- | --- |
| On-demand channels (MPEG-TS and HLS sources) | PHP starts FFmpeg per channel | **Go (`odrelay`), no FFmpeg** — all on-demand channels |
| Live channels, plain copy to HLS | FFmpeg per channel | **Go (`ffmpeg-golive`)** for all of them, by default |
| HLS with a separate audio rendition | FFmpeg | **Go** (merged with gohlslib + mediacommon) |
| ClearKey DASH | Not supported | **Go `ckts` engine** (decryption) + Go copy to HLS |
| Transcoding, filters, logos, encrypted HLS, fMP4, extra outputs | FFmpeg | FFmpeg (handed over automatically) |

Programs:

- **odrelay** (8147) — **replaces FFmpeg for on-demand channels**: one upstream connection per channel shared by all
  viewers, a ring buffer, late join on a keyframe, source fallback, replay/rewind suppression after reconnects,
  offline slate, TS and HLS output, per-stream HTTP proxy. Sources: MPEG-TS and HLS (HLS with a separate audio
  rendition is merged with [gohlslib](https://github.com/bluenviron/gohlslib) +
  [mediacommon](https://github.com/bluenviron/mediacommon), MIT). Encrypted HLS / fMP4 is detected and stays on
  FFmpeg (cached 24 h). A reader stuck for 60 s is released (no ghost viewers).
- **ffmpeg-golive** — **replaces FFmpeg for live channels, by default for all of them**. `/home/xc_vm/config/golive_streams.txt`
  contains `*` (all live channels); put stream ids instead (one per line) to limit, or leave it empty to keep every live
  channel on FFmpeg. XC_VM still builds its normal FFmpeg command; Go runs it when it
  is a plain copy to HLS (MPEG-TS, HLS, HLS with separate audio, ckts output) and hands it to the real FFmpeg
  (`exec`) for anything else. It writes the segments, playlist and `-progress` file (frame, fps, bitrate) that XC_VM
  monitors, so restarts from the panel, automatic restarts and FPS checks work as before. A channel switches to Go at
  its next (re)start. Panel settings are re-read only when their content changes (XC_VM rewrites the cache every minute).
- **qc-ffaac** — small static AAC decoder for the `ckts` sound check. `/usr/bin/ffmpeg` becomes a tiny launcher
  (`dpkg-divert`, the real one is `/usr/bin/ffmpeg.distrib`) that sends only `ffmpeg -f aac -i pipe:0 ... -f s16le pipe:1`
  to `/opt/qc-ffaac/ffaac` and everything else to the real ffmpeg. Undo: `mv /usr/bin/ffmpeg.distrib /usr/bin/ffmpeg && dpkg-divert --remove --no-rename /usr/bin/ffmpeg`.
- **Panel settings drive Go**: segment time, list size, delete threshold, client/restreamer prebuffer, on-demand
  wait/scan, segment wait time, stream fail sleep, analyze duration + probe extra wait, instant off — changes apply
  without a restart.
- **livehold** (8150) — viewers stay connected through source drops and restarts (offline slate instead of a disconnect)
- **ckts** (8160) — ClearKey DASH → MPEG-TS engine, with ad-break relays
- **mpdproxy** (8098-8113) — DASH/ClearKey proxy with cache, plus **FFmpeg 8.1-dash**
- **Panel**: the stream list shows a `go` badge for channels run by Go (`ffmpeg` when FFmpeg runs them); on-demand
  channels served by Go show as running with codecs, resolution, FPS, bitrate and CPU/RAM share (via `cron:streams`);
  *Service Status* on the dashboard shows which servers run the Go engine.
- Fix for the XC_VM 2.7.2/2.7.4 bug that disconnected active TS/VOD viewers every 10-20 min
- Reseller credit refunds, trial API, line API, provider import, XAccel-compatible API, source proxies

Details: [INSTALL.md](INSTALL.md).

---

### 📈 Go vs FFmpeg — measured on a production load balancer

| Measure | PHP + FFmpeg | Go |
| --- | --- | --- |
| On-demand open (5 channels) | 4.2–9.4 s (probe + first segment) | 1.1–1.3 s to first byte |
| Live restart → first segment, HLS with separate audio | 4.6–4.7 s | 2.1–2.7 s |
| Live restart → first segment, MPEG-TS | 1.2–1.3 s | 1.2–1.3 s |
| RAM per live copy channel (average, 351 FFmpeg / 66 Go channels) | ~54 MB | ~48 MB |
| CPU per live copy channel (same average) | ~0.56 % | ~0.9 % (HLS + separate audio merge: ~8 %) |

FFmpeg stays cheaper in CPU for a plain copy (about 0.3 % of a core more per channel in Go); Go wins on start time, RAM,
shared upstream connections and viewer continuity.

## 🔧 Known Limitations

- ❌ Requires Linux knowledge
- ❌ Community-based support — for this fork, only through [its issues](https://github.com/Mightyjay396/XC_VM-GO/issues)
- ❌ Some bugs in transcoding module (upstream, in progress)
- ❌ New XC_VM versions reach this fork only once its changes are carried over to them
- ❌ The Go engine is installed on Ubuntu / Debian x86-64 only
- ❌ Transcoding, filters and logos always use FFmpeg (no production-grade Go encoder exists)
- ❌ Go live (`ffmpeg-golive`) keeps all tracks of the source; `-map` track selection is not applied yet

---

## 🤝 Contributing

This is a fork. For problems with **XC_VM-GO**, open an issue in [this repository](https://github.com/Mightyjay396/XC_VM-GO/issues).
To contribute to **XC_VM** itself, use the [upstream repository](https://github.com/Vateron-Media/XC_VM).

- 🛠️ [Contributing Guide](CONTRIBUTING.md)
- 👥 [Contributors List](CONTRIBUTORS.md)

---

### ⚠️ Legal & Project Independence Notice

**XC_VM-GO** is an unofficial, independent fork of XC_VM by Vateron Media. It is not affiliated with, endorsed by or
supported by Vateron Media. The original copyright and the AGPL-3.0 attribution of XC_VM are kept unchanged, as the
license requires. Changes made in this fork are also released under AGPL-3.0.

**XC_VM** is an independent software project and is **not affiliated with or endorsed by XUI.one**.

All backend systems, core logic, and infrastructure of XC_VM have been **independently developed** without using source code from XUI.one or related projects.

The administrative interface (UI/UX) is **inspired by general industry practices and partially by XUI.one in terms of usability concepts only**.
No proprietary source code or protected assets have been intentionally reused.

---

### 📜 License Enforcement (AGPL-3.0)

XC_VM is distributed under the **GNU Affero General Public License v3.0 (AGPL-3.0)**.

Under this license:

- Redistribution or modification is permitted **only under the same license (AGPL-3.0)**
- Any modified version **must remain open-source**
- Providing XC_VM as a service **requires making the source code available**
- **Copyright and attribution must be preserved**

Any attempt to redistribute this software under a different license, remove attribution, or obscure the origin of the project constitutes a violation of the license terms.

#### Attribution-integrity check

As permitted by **AGPL-3.0 §7(b)** (preservation of author attributions), the panel
verifies on each request that its attribution notice — the _"Vateron Media · AGPL-3.0"_
credit shown in the panel footer — is still present. If the notice has been removed, the
**management UI** (admin / reseller / player panels) is locked with an
`ATTRIBUTION_REMOVED` notice until it is restored. The check is fully reversible and
non-destructive: no data is modified, the CLI remains available, and restoring the notice
unlocks the panel on the next request. End-viewer streaming is **not** affected by this check.

Each build is also stamped with a unique build identifier (`XC_VM_BUILD_ID`) for provenance,
so a leaked or rebranded copy can be traced back to the build it originated from.

#### White-label activation (dual-licensing)

Keeping the attribution notice means **AGPL-3.0 — free, and everything works**, including
load-balancer nodes. If the attribution is **removed** (white-labelling), the panel
requires a **free, machine-bound activation key** to provision load-balancer / cluster
nodes; a single-server panel still runs locally without one. Keys are issued **free** and
self-service (one per HWID, shown in **Settings → Info** and on the dashboard) and are
**revocable** — this is accountability against rebranded resale, not monetization.

#### What the panel sends (full transparency)

A **community install (attribution intact) never contacts the licensing server.** A
white-label install contacts `https://www.xcvm.tech` only to: (a) obtain its key
(`install_id`), (b) run a weekly revocation check (`jti` + nonce), and (c) send **one daily
check-in** (`install_id`, `jti`, `env_fp`, nonce) recording which IP a HWID runs from —
evidence against a leaked copy. Every response is **Ed25519-signed** and verified. **No
stream data, viewer data, credentials, database or file contents, and no remote command
channel** are ever involved. Full detail:
[Licensing & Activation](docs/en/info/licensing-and-activation.md).

> ⚖️ You are solely responsible for how it is used.
> We take no responsibility for misuse or illegal deployments.

---

