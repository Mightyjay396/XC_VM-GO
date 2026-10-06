#!/bin/bash
# ─────────────────────────────────────────────────────────────────────
# XC_VM-GO: Add Go delivery server to an existing XC_VM installation
#
# Run as root:
#   wget -qO- https://raw.githubusercontent.com/Mightyjay396/XC_VM-GO/main/upgrade_go.sh | bash
#
# Go auto-configures from the XC_VM installation:
#   - DB credentials: dedicated Go user (created by this script)
#   - OPENSSL_EXTRA: read from config/openssl_extra
#   - SERVER_ID: read via PHP/XC_VM C extension
#   - Settings: loaded from DB at startup
# ─────────────────────────────────────────────────────────────────────

set -e

REPO="https://github.com/Mightyjay396/XC_VM-GO.git"
XC_HOME="/home/xc_vm"
GO_VERSION="1.22.10"
TMP="/tmp/xc_vm_go_upgrade"
GO_DB_USER="xc_ts_go"
GO_DB_PASS=$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)

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
echo "--- 1/8  Go toolchain ---"
if [ -x /usr/local/go/bin/go ]; then
    ok "Already installed: $(/usr/local/go/bin/go version)"
else
    ARCH=$(uname -m); case "$ARCH" in x86_64) GA=amd64;; aarch64) GA=arm64;; *) fail "Unsupported: $ARCH";; esac
    wget -q "https://go.dev/dl/go${GO_VERSION}.linux-${GA}.tar.gz" -O /tmp/go.tar.gz || fail "Download failed"
    rm -rf /usr/local/go && tar -C /usr/local -xzf /tmp/go.tar.gz && rm /tmp/go.tar.gz
    ok "Go $GO_VERSION installed"
fi

# ── 2. Clone & build ────────────────────────────────────────────────
echo "--- 2/8  Clone & build ---"
rm -rf "$TMP"
git clone --depth 1 "$REPO" "$TMP" || fail "Clone failed"
cd "$TMP/src/bin/xc_ts_server"
CGO_ENABLED=0 /usr/local/go/bin/go build -trimpath -ldflags="-s -w" -o xc_ts_server . || fail "Build failed"
ok "Built $(du -h xc_ts_server | cut -f1)"

# ── 3. Create Go DB user ────────────────────────────────────────────
echo "--- 3/8  Database user ---"
PHP_BIN="$XC_HOME/bin/php/bin/php"

# Extract DB host and current server IP using PHP/XC_VM C extension
DB_INFO=$("$PHP_BIN" -r '
$pdo = \XC_VM::db_connect();
$host = explode(" ", $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS))[0];
$user = explode("@", $pdo->query("SELECT USER()")->fetchColumn());
$myIP = isset($user[1]) ? $user[1] : "127.0.0.1";
echo "$host\n$myIP";
' 2>/dev/null) || fail "Cannot extract DB info from PHP"

DB_HOST=$(echo "$DB_INFO" | head -1)
MY_IP=$(echo "$DB_INFO" | tail -1)

# Try to create Go user via PHP (uses XC_VM's DB connection)
"$PHP_BIN" -r "
\$pdo = \\XC_VM::db_connect();
try {
    \$pdo->exec(\"CREATE USER IF NOT EXISTS '$GO_DB_USER'@'$MY_IP' IDENTIFIED BY '$GO_DB_PASS'\");
    \$pdo->exec(\"GRANT ALL PRIVILEGES ON xc_vm.* TO '$GO_DB_USER'@'$MY_IP'\");
    \$pdo->exec(\"FLUSH PRIVILEGES\");
    echo 'GO_USER_CREATED';
} catch (Exception \$e) {
    // If GRANT fails (no GRANT privilege), check if user already exists
    try {
        \$test = new PDO('mysql:host=$DB_HOST;port=3306;dbname=xc_vm;charset=utf8mb4', '$GO_DB_USER', '$GO_DB_PASS');
        echo 'GO_USER_EXISTS';
    } catch (Exception \$e2) {
        echo 'GO_USER_NEEDS_MANUAL_SETUP';
    }
}
" 2>/dev/null

RESULT=$?
if [ $RESULT -ne 0 ]; then
    warn "Could not create Go DB user automatically. Manually run on the MAIN server:"
    warn "  CREATE USER '$GO_DB_USER'@'$MY_IP' IDENTIFIED BY '$GO_DB_PASS';"
    warn "  GRANT ALL PRIVILEGES ON xc_vm.* TO '$GO_DB_USER'@'$MY_IP';"
fi

# Write go_db.conf (DSN for Go)
mkdir -p "$XC_HOME/bin/xc_ts_server"
echo "${GO_DB_USER}:${GO_DB_PASS}@tcp(${DB_HOST}:3306)/xc_vm?clientFoundRows=true" > "$XC_HOME/bin/xc_ts_server/go_db.conf"
chmod 600 "$XC_HOME/bin/xc_ts_server/go_db.conf"
chown xc_vm:xc_vm "$XC_HOME/bin/xc_ts_server/go_db.conf"
ok "go_db.conf written (host=$DB_HOST, user=$GO_DB_USER)"

# ── 4. Stop old Go server ───────────────────────────────────────────
echo "--- 4/8  Stop old server ---"
pkill -u xc_vm -x xc_ts_server 2>/dev/null && sleep 2 && ok "Stopped" || ok "Not running"
rm -f "$XC_HOME/bin/xc_ts_server/ts_server.pid" "$XC_HOME/bin/xc_ts_server/run.lock"

# ── 5. Deploy files ─────────────────────────────────────────────────
echo "--- 5/8  Deploy ---"

cp "$TMP/src/bin/xc_ts_server/xc_ts_server"    "$XC_HOME/bin/xc_ts_server/"
cp "$TMP/src/bin/xc_ts_server/run.sh"           "$XC_HOME/bin/xc_ts_server/"
[ -f "$TMP/src/bin/xc_ts_server/xc_ts_server.sh" ] && \
  cp "$TMP/src/bin/xc_ts_server/xc_ts_server.sh"  "$XC_HOME/bin/xc_ts_server/"
chmod +x "$XC_HOME/bin/xc_ts_server"/{xc_ts_server,run.sh}

for f in live.php vod.php; do
    [ -f "$XC_HOME/Public/stream/$f" ] && cp "$XC_HOME/Public/stream/$f" "$XC_HOME/Public/stream/${f}.bak"
    cp "$TMP/src/Public/stream/$f" "$XC_HOME/Public/stream/$f"
done

cp "$TMP/lb_configs/go_ts_server.conf" "$XC_HOME/bin/nginx/conf/"
chown -R xc_vm:xc_vm "$XC_HOME/bin/xc_ts_server" "$XC_HOME/bin/nginx/conf/go_ts_server.conf"
chown xc_vm:xc_vm "$XC_HOME/Public/stream"/{live,vod}.php
rm -f "$XC_HOME/bin/xc_ts_server/disabled"
ok "Files deployed"

# ── 6. Remove old inline Go blocks (from manual LB2 deployments) ───
echo "--- 6/8  Clean nginx inline blocks ---"
CONF="$XC_HOME/bin/nginx/conf/nginx.conf"

python3 -c "
import re
with open('$CONF') as f:
    c = f.read()
for p in [r'\s*# ─── Go HLS playlist X-Accel.*?\n\s*\}',
          r'\s*# ─── Go VOD file X-Accel.*?\n\s*\}',
          r'\s*# ─── Go TS server X-Accel.*?\n\s*\}',
          r'\s*# ─── Go native auth.*?\n\s*\}']:
    c = re.sub(p, '', c, flags=re.DOTALL)
c = re.sub(r'\n{3,}', '\n\n', c)
with open('$CONF', 'w') as f:
    f.write(c)
" 2>/dev/null || ok "No inline blocks to clean"

# ── 7. Patch nginx ──────────────────────────────────────────────────
echo "--- 7/8  Nginx config ---"

grep -q '^\s*rewrite ^/auth/' "$CONF" && \
    sed -i 's|^\(\s*\)\(rewrite ^/auth/\)|\1# Go handles /auth/ natively -- see go_ts_server.conf\n\1# \2|' "$CONF" && \
    ok "Commented /auth/ rewrite" || ok "/auth/ rewrite already handled"

grep -q 'go_ts_server.conf' "$CONF" || \
    { sed -i '/include custom.conf/a\        include go_ts_server.conf;' "$CONF" 2>/dev/null || \
      sed -i '0,/location.*{/s//        include go_ts_server.conf;\n&/' "$CONF"; ok "Include added"; } && \
    ok "Include present"

sudo -u xc_vm "$XC_HOME/bin/nginx/sbin/nginx" -t || fail "Nginx test failed"
sudo -u xc_vm "$XC_HOME/bin/nginx/sbin/nginx" -s reload
ok "Nginx reloaded"

# ── 8. Start Go server ──────────────────────────────────────────────
echo "--- 8/8  Start & verify ---"
sudo -u xc_vm bash "$XC_HOME/bin/xc_ts_server/run.sh" &
sleep 4

H=$(curl -s http://127.0.0.1:8089/health 2>/dev/null)
if echo "$H" | python3 -c "import sys,json; d=json.load(sys.stdin); assert d.get('ok') and d.get('auth_native')" 2>/dev/null; then
    ok "Go server healthy with native auth"
    echo "$H" | python3 -m json.tool
else
    warn "Health check inconclusive — check logs:"
    warn "  tail -f $XC_HOME/bin/xc_ts_server/ts_server.log"
    echo "$H" 2>/dev/null
fi

rm -rf "$TMP"

echo ""
echo "=============================="
echo "  Upgrade complete"
echo "=============================="
echo ""
echo "  Go auto-configures from: $XC_HOME"
echo "  DB config:  $XC_HOME/bin/xc_ts_server/go_db.conf"
echo "  Logs:       tail -f $XC_HOME/bin/xc_ts_server/ts_server.log"
echo "  Health:     curl -s http://127.0.0.1:8089/health | python3 -m json.tool"
echo "  Stop:       pkill -u xc_vm -x xc_ts_server"
echo "  Rollback:   Restore live.php.bak, vod.php.bak, uncomment /auth/ rewrite, nginx -s reload"
echo ""
