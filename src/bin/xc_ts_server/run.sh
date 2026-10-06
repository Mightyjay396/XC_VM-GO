#!/bin/bash
# xc_ts_server keepalive supervisor. Started from `service` boot() as:
#   sudo -u xc_vm bash /home/xc_vm/bin/xc_ts_server/run.sh &
#
# Reads DB credentials from /home/xc_vm/config/config.ini and the OPENSSL_EXTRA
# secret from /home/xc_vm/config/openssl_extra. Queries live_streaming_pass
# from the MariaDB settings table on each start (so a panel change takes effect
# on the next daemon restart). Auto-restarts on crash (2s backoff).
#
# Single-instance: flock on run.lock prevents duplicate supervisors. `service stop`
# kills all xc_vm processes which frees the lock; next `service boot` re-acquires.

SCRIPT=/home/xc_vm
TS_DIR="$SCRIPT/bin/xc_ts_server"
LOG="$TS_DIR/xc_ts_server.log"
CONFIG_INI="$SCRIPT/config/config.ini"
OPENSSL_EXTRA_FILE="$SCRIPT/config/openssl_extra"

echo "=== $(date '+%F %T') run.sh start attempt pid=$$ ppid=$PPID ===" >> "$LOG"

# Single-instance guard
exec 9>"$TS_DIR/run.lock"
if command -v flock >/dev/null 2>&1; then
    if ! flock -n 9; then
        echo "=== $(date '+%F %T') run.sh pid=$$ could NOT take flock — exiting ===" >> "$LOG"
        exit 0
    fi
fi
echo "=== $(date '+%F %T') run.sh pid=$$ acquired flock, supervising ===" >> "$LOG"

# ─── Parse config.ini for DB credentials ─────────────────────────
# Reads hostname, database, port, username, password, server_id from config.ini.
# The [Encrypted] section username/password are plaintext after install, or
# re-encrypted by PHP — we read the current on-disk values.
parse_config() {
    if [ ! -f "$CONFIG_INI" ]; then
        echo "=== $(date '+%F %T') ERROR: $CONFIG_INI not found ===" >> "$LOG"
        return 1
    fi

    DB_HOST=$(awk -F'"' '/^hostname/ {print $2}' "$CONFIG_INI")
    DB_NAME=$(awk -F'"' '/^database/ {print $2}' "$CONFIG_INI")
    DB_PORT=$(awk -F'=' '/^port/ {gsub(/[[:space:]]/, "", $2); print $2}' "$CONFIG_INI")
    SERVER_ID=$(awk -F'=' '/^server_id/ {gsub(/[[:space:]]/, "", $2); print $2}' "$CONFIG_INI")
    DB_USER=$(awk -F'"' '/^username/ {print $2}' "$CONFIG_INI")
    DB_PASS=$(awk -F'"' '/^password/ {print $2}' "$CONFIG_INI")

    # Defaults
    DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_NAME="${DB_NAME:-xc_vm}"
    DB_PORT="${DB_PORT:-3306}"
    SERVER_ID="${SERVER_ID:-1}"

    if [ -z "$DB_USER" ] || [ -z "$DB_PASS" ]; then
        echo "=== $(date '+%F %T') ERROR: could not parse DB credentials from $CONFIG_INI ===" >> "$LOG"
        return 1
    fi

    DB_DSN="${DB_USER}:${DB_PASS}@tcp(${DB_HOST}:${DB_PORT})/${DB_NAME}"
    return 0
}

# ─── Read OPENSSL_EXTRA ──────────────────────────────────────────
read_openssl_extra() {
    OPENSSL_EXTRA=""
    if [ -f "$OPENSSL_EXTRA_FILE" ]; then
        OPENSSL_EXTRA=$(cat "$OPENSSL_EXTRA_FILE")
    fi
}

# ─── Query live_streaming_pass from MariaDB settings ─────────────
# This is the encrypted token password that the Go server needs for native auth.
# Read once before each daemon start so a panel change takes effect on restart.
query_live_streaming_pass() {
    LIVE_PASS=""
    if command -v mysql >/dev/null 2>&1; then
        LIVE_PASS=$(mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -P"$DB_PORT" "$DB_NAME" \
            -sNe "SELECT live_streaming_pass FROM settings LIMIT 1" 2>/dev/null)
    elif [ -x "$SCRIPT/bin/php/bin/php" ]; then
        # Fallback: use PHP to query
        LIVE_PASS=$("$SCRIPT/bin/php/bin/php" -r "
            \$pdo = new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_NAME}', '${DB_USER}', '${DB_PASS}');
            echo \$pdo->query('SELECT live_streaming_pass FROM settings LIMIT 1')->fetchColumn();
        " 2>/dev/null)
    fi
    if [ -z "$LIVE_PASS" ]; then
        echo "=== $(date '+%F %T') WARNING: could not read live_streaming_pass from DB ===" >> "$LOG"
    fi
}

# ─── Main loop ────────────────────────────────────────────────────
while true; do
    # Disabled flag: set by the panel or admin to stop the Go server
    if [ -f "$TS_DIR/disabled" ]; then
        echo "=== $(date '+%F %T') supervisor pid=$$ exiting: xc_ts_server disabled ===" >> "$LOG"
        exit 0
    fi

    if [ -x "$TS_DIR/xc_ts_server" ]; then
        # Parse config fresh each iteration (credentials may change)
        if ! parse_config; then
            echo "=== $(date '+%F %T') config parse failed, retrying in 10s ===" >> "$LOG"
            sleep 10
            continue
        fi

        read_openssl_extra
        query_live_streaming_pass

        # Kill any orphaned instance
        pkill -u xc_vm -x xc_ts_server 2>/dev/null
        sleep 1

        # Build arguments
        ARGS=(
            -listen "127.0.0.1:8089"
            -streams "$SCRIPT/content/streams/"
            -vod-path "$SCRIPT/content/vod/"
            -db-dsn "$DB_DSN"
            -server-id "$SERVER_ID"
            -signals-path "$SCRIPT/signals/"
            -cons-tmp-path "$SCRIPT/tmp/opened_cons/"
            -divergence-path "$SCRIPT/tmp/divergence/"
            -php-bin "$SCRIPT/bin/php/bin/php"
            -main-home "$SCRIPT/"
        )

        # Native auth flags (only when credentials are available)
        if [ -n "$LIVE_PASS" ]; then
            ARGS+=(-live-streaming-pass "$LIVE_PASS")
        fi
        if [ -n "$OPENSSL_EXTRA" ]; then
            ARGS+=(-openssl-extra "$OPENSSL_EXTRA")
        fi

        echo "=== $(date '+%F %T') supervisor pid=$$ spawning xc_ts_server (server_id=$SERVER_ID) ===" >> "$LOG"
        "$TS_DIR/xc_ts_server" "${ARGS[@]}" >> "$LOG" 2>&1
        rc=$?
        echo "=== $(date '+%F %T') xc_ts_server EXITED rc=$rc (supervisor pid=$$) ===" >> "$LOG"
    fi

    sleep 2
done
