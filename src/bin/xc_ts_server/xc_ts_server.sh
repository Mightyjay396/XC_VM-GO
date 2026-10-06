#!/bin/bash
# xc_ts_server startup/stop script
# Go TS/HLS/VOD delivery server with native auth + MariaDB tracking for XC_VM
# Settings (prebuffer, seg_time, create_expiration, etc.) are read from MariaDB at startup.
#
# INSTALLATION:
#   1. Copy this script and the compiled binary to /home/xc_vm/bin/xc_ts_server/
#   2. Edit the configuration variables below
#   3. chmod +x xc_ts_server.sh
#   4. ./xc_ts_server.sh start

SCRIPT="/home/xc_vm/bin/xc_ts_server"
BINARY="$SCRIPT/xc_ts_server"
PID_FILE="$SCRIPT/ts_server.pid"
LOG_FILE="$SCRIPT/ts_server.log"
STREAMS="/home/xc_vm/content/streams/"
SIGNALS_PATH="/home/xc_vm/signals/"
CONS_TMP_PATH="/home/xc_vm/tmp/opened_cons/"
DIVERGENCE_PATH="/home/xc_vm/tmp/divergence/"

# ─── CONFIGURATION (edit these for your environment) ─────────────────
# MariaDB DSN for lines_live heartbeat + settings read
# Format: user:pass@tcp(host:port)/dbname
DB_DSN="${XC_TS_DB_DSN}"

# Native auth config (replaces PHP auth on this LB)
LIVE_STREAMING_PASS="${XC_TS_LIVE_PASS}"
OPENSSL_EXTRA="${XC_TS_OPENSSL_EXTRA}"
SERVER_ID="${XC_TS_SERVER_ID:-0}"

# PHP binary for on-demand stream starts
PHP_BIN="/home/xc_vm/bin/php/bin/php"
MAIN_HOME="/home/xc_vm/"

# Listen address (127.0.0.1 = local only, accessed via nginx X-Accel)
LISTEN_ADDR="127.0.0.1:8089"
# ─────────────────────────────────────────────────────────────────────

start() {
    if [ ! -f "$BINARY" ]; then
        echo "Binary not found: $BINARY"
        return 1
    fi

    if [ -f "$PID_FILE" ]; then
        OLD_PID=$(cat "$PID_FILE")
        if kill -0 "$OLD_PID" 2>/dev/null; then
            echo "xc_ts_server already running (PID $OLD_PID)"
            return 0
        fi
        rm -f "$PID_FILE"
    fi

    echo "Starting xc_ts_server..."
    nohup "$BINARY" \
        -listen "$LISTEN_ADDR" \
        -streams "$STREAMS" \
        -signals-path "$SIGNALS_PATH" \
        -cons-tmp-path "$CONS_TMP_PATH" \
        -divergence-path "$DIVERGENCE_PATH" \
        -db-dsn "$DB_DSN" \
        -server-id "$SERVER_ID" \
        -live-streaming-pass "$LIVE_STREAMING_PASS" \
        -openssl-extra "$OPENSSL_EXTRA" \
        -php-bin "$PHP_BIN" \
        -main-home "$MAIN_HOME" \
        -vod-path "/home/xc_vm/content/vod/" \
        >> "$LOG_FILE" 2>&1 &

    sleep 1

    if [ -f "$PID_FILE" ]; then
        echo "xc_ts_server started (PID $(cat $PID_FILE))"
    else
        echo "WARNING: xc_ts_server may not have started. Check $LOG_FILE"
    fi
}

stop() {
    if [ ! -f "$PID_FILE" ]; then
        echo "PID file not found, xc_ts_server may not be running"
        # Try to find and kill anyway
        pkill -f "$BINARY" 2>/dev/null
        return 0
    fi

    PID=$(cat "$PID_FILE")
    echo "Stopping xc_ts_server (PID $PID)..."
    kill "$PID" 2>/dev/null
    sleep 2

    if kill -0 "$PID" 2>/dev/null; then
        echo "Force killing..."
        kill -9 "$PID" 2>/dev/null
    fi

    rm -f "$PID_FILE"
    echo "xc_ts_server stopped"
}

status() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if kill -0 "$PID" 2>/dev/null; then
            echo "xc_ts_server is running (PID $PID)"
            # Health check
            curl -s "http://$LISTEN_ADDR/health" 2>/dev/null | python3 -m json.tool 2>/dev/null || echo "(health endpoint unreachable)"
            return 0
        fi
    fi
    echo "xc_ts_server is not running"
    return 1
}

case "$1" in
    start)   start ;;
    stop)    stop ;;
    restart) stop; sleep 1; start ;;
    status)  status ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}"
        exit 1
        ;;
esac
