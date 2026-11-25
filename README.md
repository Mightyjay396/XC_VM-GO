<p align="center">
  <img src="https://avatars.githubusercontent.com/u/149707645?s=400&v=4" width="220" alt="Vateron Media Logo"/>
</p>

<h1 align="center">XC_VM IPTV Panel</h1>
<p align="center">
  <b>Open-source, community-driven Xtream Codes panel</b><br>
  Built for modern IPTV workflows – powerful, scalable, and free.
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Mightyjay396/XC_VM-GO" /></a>
  <a href="https://github.com/Mightyjay396/XC_VM-GO/stargazers"><img src="https://img.shields.io/github/stars/Mightyjay396/XC_VM-GO?style=flat" /></a>
  <a href="https://github.com/Mightyjay396/XC_VM-GO/issues"><img src="https://img.shields.io/github/issues/Mightyjay396/XC_VM-GO" /></a>
</p>

---

# 📑 Table of Contents

<details>
<summary><strong>📘 Contents</strong></summary>

- 🏁 [Overview](#-overview)
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

**XC_VM** is an open-source IPTV platform based on Xtream Codes.
It enables:

- 📺 Live & VOD streaming
- 🔀 Load balancing
- 📊 Full user/reseller control
- 🎚️ Transcoding & EPG
- 🔐 Hardened security fixes

> ✅ 100% free. No license checks. No server locks.

---

## ⚠️ Status

> **BETA SOFTWARE** — actively developed

---

## 📚 Documentation

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
| FFmpeg    | 8.0, 7.1, 4.0 | Media transcoding & processing  |
| MariaDB   | 11.4          | SQL database engine             |
| KeyDB     | 6.3.4         | Cache & session storage (Redis) |

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

> ✅ Ubuntu 22.04+, Debian 12+ — installs **XC_VM 2.7.4-p1**: official XC_VM 2.7.4 + the QC fixes, modules and Go delivery engine (odrelay, livehold, ckts, mpdproxy)

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

- ✅ No server restrictions
- ✅ EPG importer
- ✅ VOD management
- ✅ User/reseller panel
- ✅ Security patches
- ✅ Clean UI

### ⚡ Go Delivery Engine (this fork)

Installed and started automatically on every load balancer (not XC_VM's fanout, which stays as is):

- **odrelay** (8147) — on-demand channels start instantly: the viewer gets video while FFmpeg starts (TS + HLS)
- **livehold** (8150) — viewers stay connected through FFmpeg restarts and source drops (offline slate instead of a disconnect)
- **ckts** (8160) — ClearKey DASH → MPEG-TS engine, with ad-break relays
- **mpdproxy** (8098-8113) — DASH/ClearKey proxy with cache, plus **FFmpeg 8.1-dash**
- Fix for the XC_VM 2.7.2/2.7.4 bug that disconnected active TS/VOD viewers every 10-20 min
- Reseller credit refunds, trial API, line API, provider import, XAccel-compatible API, source proxies
- *Service Status* on the dashboard shows which servers run the Go engine

Details: [INSTALL.md](INSTALL.md).

---

## 🔧 Known Limitations

- ❌ Requires Linux knowledge
- ❌ Community-based support
- ❌ Some bugs in transcoding module (in progress)

---

## 🤝 Contributing

We welcome community help!

- 🛠️ [Contributing Guide](CONTRIBUTING.md)
- 👥 [Contributors List](CONTRIBUTORS.md)

---

### ⚠️ Legal & Project Independence Notice

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

