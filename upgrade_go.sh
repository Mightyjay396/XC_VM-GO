#!/bin/bash
# ─────────────────────────────────────────────────────────────────────
# XC_VM-GO: Add Go delivery server to an existing XC_VM installation
#
# Run as root:
#   wget -qO- https://raw.githubusercontent.com/Mightyjay396/XC_VM-GO/main/upgrade_go.sh | bash
# ─────────────────────────────────────────────────────────────────────

set -e

REPO="https://github.com/Mightyjay396/XC_VM-GO.git"
XC_HOME="/home/xc_vm"
GO_VERSION="1.22.10"
TMP="/tmp/xc_vm_go_upgrade"

ok()   { echo -e "\033[0;32m[OK]\033[0m $1"; }
warn() { echo -e "\033[1;33m[WARN]\033[0m $1"; }
fail() { echo -e "\033[0;31m[ERROR]\033[0m $1"; exit 1; }

echo ""
echo "=============================="
echo "  XC_VM-GO Upgrade"
echo "=============================="
echo ""

[ "$(id -u)" -eq 0 ]              || fail "Must be run as root"
[ -d "$XC_HOME" ]                  || fail "XC_VM not found at $XC_HOME"
[ -f "$XC_HOME/console.php" ]      || fail "Invalid XC_VM installation"

# ── 1. Go toolchain ─────────────────────────────────────────────────
echo "--- 1/7  Go toolchain ---"
if [ -x /usr/local/go/bin/go ]; then
    ok "Already installed: $(/usr/local/go/bin/go version)"
else
    ARCH=$(uname -m); case "$ARCH" in x86_64) GA=amd64;; aarch64) GA=arm64;; *) fail "Unsupported: $ARCH";; esac
    wget -q "https://go.dev/dl/go${GO_VERSION}.linux-${GA}.tar.gz" -O /tmp/go.tar.gz || fail "Download failed"
    rm -rf /usr/local/go && tar -C /usr/local -xzf /tmp/go.tar.gz && rm /tmp/go.tar.gz
    ok "Go $GO_VERSION installed"
fi

# ── 2. Clone & build ────────────────────────────────────────────────
echo "--- 2/7  Clone & build ---"
rm -rf "$TMP"
git clone --depth 1 "$REPO" "$TMP" || fail "Clone failed"
cd "$TMP/src/bin/xc_ts_server"
CGO_ENABLED=0 /usr/local/go/bin/go build -trimpath -ldflags="-s -w" -o xc_ts_server . || fail "Build failed"
ok "Built $(du -h xc_ts_server | cut -f1)"

# ── 3. Stop old Go server ───────────────────────────────────────────
echo "--- 3/7  Stop old server ---"
pkill -u xc_vm -x xc_ts_server 2>/dev/null && sleep 2 && ok "Stopped" || ok "Not running"
rm -f "$XC_HOME/bin/xc_ts_server/ts_server.pid" "$XC_HOME/bin/xc_ts_server/run.lock"

# ── 4. Deploy files ─────────────────────────────────────────────────
echo "--- 4/7  Deploy ---"
mkdir -p "$XC_HOME/bin/xc_ts_server"

cp "$TMP/src/bin/xc_ts_server/xc_ts_server"    "$XC_HOME/bin/xc_ts_server/"
cp "$TMP/src/bin/xc_ts_server/run.sh"           "$XC_HOME/bin/xc_ts_server/"
cp "$TMP/src/bin/xc_ts_server/xc_ts_server.sh"  "$XC_HOME/bin/xc_ts_server/"
chmod +x "$XC_HOME/bin/xc_ts_server"/{xc_ts_server,run.sh,xc_ts_server.sh}

for f in live.php vod.php; do
    [ -f "$XC_HOME/Public/stream/$f" ] && cp "$XC_HOME/Public/stream/$f" "$XC_HOME/Public/stream/${f}.bak"
    cp "$TMP/src/Public/stream/$f" "$XC_HOME/Public/stream/$f"
done

cp "$TMP/lb_configs/go_ts_server.conf" "$XC_HOME/bin/nginx/conf/"
chown -R xc_vm:xc_vm "$XC_HOME/bin/xc_ts_server" "$XC_HOME/bin/nginx/conf/go_ts_server.conf"
chown xc_vm:xc_vm "$XC_HOME/Public/stream"/{live,vod}.php
ok "Files deployed"

# ── 5. Patch nginx ──────────────────────────────────────────────────
echo "--- 5/7  Nginx config ---"
CONF="$XC_HOME/bin/nginx/conf/nginx.conf"

grep -q '^\s*rewrite ^/auth/' "$CONF" && sed -i 's|^\(\s*rewrite ^/auth/\)|# \1|' "$CONF" && ok "Commented /auth/ rewrite" || ok "Already commented"
grep -q 'go_ts_server.conf' "$CONF" || { sed -i '/include custom.conf/a\        include go_ts_server.conf;' "$CONF" 2>/dev/null || sed -i '0,/location.*{/s//        include go_ts_server.conf;\n&/' "$CONF"; ok "Include added"; } && ok "Include present"

sudo -u xc_vm "$XC_HOME/bin/nginx/sbin/nginx" -t || fail "Nginx test failed"
sudo -u xc_vm "$XC_HOME/bin/nginx/sbin/nginx" -s reload
ok "Nginx reloaded"

# ── 6. Start Go server ──────────────────────────────────────────────
echo "--- 6/7  Start ---"
sudo -u xc_vm bash "$XC_HOME/bin/xc_ts_server/run.sh" &
sleep 3

# ── 7. Verify ────────────────────────────────────────────────────────
echo "--- 7/7  Verify ---"
H=$(curl -s http://127.0.0.1:8089/health 2>/dev/null)
if echo "$H" | python3 -c "import sys,json; d=json.load(sys.stdin); assert d['ok'] and d['auth_native']" 2>/dev/null; then
    ok "Go server healthy"
    echo "$H" | python3 -m json.tool
else
    warn "Health check failed — check: tail -f $XC_HOME/bin/xc_ts_server/xc_ts_server.log"
fi

rm -rf "$TMP"

echo ""
echo "=============================="
echo "  Upgrade complete"
echo "=============================="
echo "  Logs:     tail -f $XC_HOME/bin/xc_ts_server/xc_ts_server.log"
echo "  Stop:     $XC_HOME/bin/xc_ts_server/xc_ts_server.sh stop"
echo "  Rollback: sed -i 's|^# \\(.*rewrite ^/auth/\\)|\\1|' $CONF && sudo -u xc_vm $XC_HOME/bin/nginx/sbin/nginx -s reload"
echo ""
