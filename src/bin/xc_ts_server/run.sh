#!/bin/bash
# xc_ts_server keepalive supervisor. Started from `service` boot() as:
#   sudo -u xc_vm bash /home/xc_vm/bin/xc_ts_server/run.sh &
#
# Go auto-configures from the XC_VM installation:
#   - DSN from go_db.conf (dedicated Go DB user)
#   - OPENSSL_EXTRA from config/openssl_extra
#   - SERVER_ID from PHP/XC_VM C extension
#   - All settings (live_streaming_pass, etc.) from DB
#
# No config.ini parsing needed. Auto-restarts on crash (2s backoff).
# Single-instance: flock on run.lock prevents duplicate supervisors.

XC_HOME="/home/xc_vm"
TS_DIR="$XC_HOME/bin/xc_ts_server"
LOG="$TS_DIR/ts_server.log"

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

# ─── Main loop ────────────────────────────────────────────────────
while true; do
    # Disabled flag: set by the panel or admin to stop the Go server
    if [ -f "$TS_DIR/disabled" ]; then
        echo "=== $(date '+%F %T') supervisor pid=$$ exiting: xc_ts_server disabled ===" >> "$LOG"
        exit 0
    fi

    if [ -x "$TS_DIR/xc_ts_server" ]; then
        # Kill any orphaned instance
        pkill -u xc_vm -x xc_ts_server 2>/dev/null
        sleep 1

        echo "=== $(date '+%F %T') supervisor pid=$$ spawning xc_ts_server ===" >> "$LOG"

        # Go auto-configures from -main-home:
        #   - reads go_db.conf for DSN
        #   - reads config/openssl_extra for OPENSSL_EXTRA
        #   - runs PHP for server_id
        #   - loads live_streaming_pass from DB settings
        "$TS_DIR/xc_ts_server" \
            -listen "127.0.0.1:8089" \
            -main-home "$XC_HOME/" \
            >> "$LOG" 2>&1

        rc=$?
        echo "=== $(date '+%F %T') xc_ts_server EXITED rc=$rc (supervisor pid=$$) ===" >> "$LOG"
    else
        echo "=== $(date '+%F %T') binary not found: $TS_DIR/xc_ts_server ===" >> "$LOG"
    fi

    sleep 2
done
