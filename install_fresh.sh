#!/bin/bash
# ─────────────────────────────────────────────────────────────────────
# XC_VM-GO: Fresh install of XC_VM with Go delivery server
#
# Installs the full XC_VM panel + Go TS/HLS/VOD delivery from scratch.
#
# Run as root on a clean Ubuntu 20/22/24 or Debian 11/12 server:
#   wget -qO- https://raw.githubusercontent.com/Mightyjay396/XC_VM-GO/main/install_fresh.sh | bash
# ─────────────────────────────────────────────────────────────────────

set -e

REPO="https://github.com/Mightyjay396/XC_VM-GO.git"
GO_VERSION="1.22.10"
INSTALL_DIR="/root/XC_VM-GO"

ok()   { echo -e "\033[0;32m[OK]\033[0m $1"; }
fail() { echo -e "\033[0;31m[ERROR]\033[0m $1"; exit 1; }

echo ""
echo "=============================="
echo "  XC_VM-GO Fresh Install"
echo "=============================="
echo ""

[ "$(id -u)" -eq 0 ] || fail "Must be run as root"

# ── 1. System packages ──────────────────────────────────────────────
echo "--- 1/5  System packages ---"
apt-get update -qq
apt-get install -y -qq git wget curl build-essential > /dev/null 2>&1
ok "Packages installed"

# ── 2. Go toolchain ─────────────────────────────────────────────────
echo "--- 2/5  Go toolchain ---"
if [ -x /usr/local/go/bin/go ]; then
    ok "Already installed: $(/usr/local/go/bin/go version)"
else
    ARCH=$(uname -m); case "$ARCH" in x86_64) GA=amd64;; aarch64) GA=arm64;; *) fail "Unsupported: $ARCH";; esac
    wget -q "https://go.dev/dl/go${GO_VERSION}.linux-${GA}.tar.gz" -O /tmp/go.tar.gz || fail "Download failed"
    rm -rf /usr/local/go && tar -C /usr/local -xzf /tmp/go.tar.gz && rm /tmp/go.tar.gz
    ok "Go $GO_VERSION installed"
fi

# ── 3. Clone repository ─────────────────────────────────────────────
echo "--- 3/5  Clone repository ---"
rm -rf "$INSTALL_DIR"
git clone "$REPO" "$INSTALL_DIR" || fail "Clone failed"
cd "$INSTALL_DIR"
ok "Repository cloned to $INSTALL_DIR"

# ── 4. Build Go binary ──────────────────────────────────────────────
echo "--- 4/5  Build Go binary ---"
cd "$INSTALL_DIR/src/bin/xc_ts_server"
CGO_ENABLED=0 /usr/local/go/bin/go build -trimpath -ldflags="-s -w" -o xc_ts_server . || fail "Build failed"
ok "xc_ts_server built ($(du -h xc_ts_server | cut -f1))"

# ── 5. Run XC_VM installer ──────────────────────────────────────────
echo "--- 5/5  Run XC_VM installer ---"
echo ""
echo "The XC_VM installer will now start."
echo "It will ask you for database credentials, ports, and other settings."
echo "Go delivery server will be built and configured automatically."
echo ""
cd "$INSTALL_DIR"
python3 install

echo ""
echo "=============================="
echo "  Fresh install complete"
echo "=============================="
echo ""
echo "  XC_VM panel + Go delivery server installed."
echo ""
echo "  Go health:  curl -s http://127.0.0.1:8089/health | python3 -m json.tool"
echo "  Go logs:    tail -f /home/xc_vm/bin/xc_ts_server/xc_ts_server.log"
echo "  Panel:      Open http://YOUR_SERVER_IP in a browser"
echo ""
