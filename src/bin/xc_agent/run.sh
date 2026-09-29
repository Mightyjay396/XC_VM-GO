#!/bin/bash
# xc_agent keepalive supervisor (MAIN <-> LB cluster API). Started from
# `service` boot() and by the RootSignals cron keepalive, as xc_vm:
#   sudo -u xc_vm bash /home/xc_vm/bin/xc_agent/run.sh &
#
# Runs only on an enrolled node (config/cluster/agent.json exists), or on MAIN
# once its data-plane client was switched on (config/cluster/main.json, from
# `console.php cluster:main-dataplane on`): there it runs
# `xc_agent run -role main` with MAIN's own key (main_agent.json). One
# supervisor at a time (flock), one agent at a time (the loop blocks on it). The
# agent exits 3 when MAIN has stopped the node (revoked, unknown, or its
# enrolment never completed; an expired token re-keys instead): the
# supervisor then writes `stopped` and exits, and
# nothing restarts it until the node is enrolled again, which removes the file.
SCRIPT=/home/xc_vm
AGENT_DIR="$SCRIPT/bin/xc_agent"
STATE="$SCRIPT/config/cluster/agent.json"
MAIN_ID="$SCRIPT/config/cluster/main.json"
MAIN_STATE="$SCRIPT/config/cluster/main_agent.json"
LOG="$AGENT_DIR/xc_agent.log"

exec 9>"$AGENT_DIR/run.lock"
if command -v flock >/dev/null 2>&1; then
  flock -n 9 || exit 0
fi
echo "=== $(date '+%F %T') supervisor pid=$$ start ===" >> "$LOG"

while true; do
  if [ -f "$MAIN_ID" ] && [ -f "$MAIN_STATE" ]; then
    # MAIN: its data-plane client. main.json says whether it serves; an
    # identity that changed ends the agent, and the loop starts it anew.
    if [ -x "$AGENT_DIR/xc_agent" ]; then
      pkill -u xc_vm -x xc_agent 2>/dev/null
      "$AGENT_DIR/xc_agent" run -role main -state "$MAIN_STATE" >> "$LOG" 2>&1
      echo "=== $(date '+%F %T') main agent exited rc=$? ===" >> "$LOG"
    fi
    sleep 2
    continue
  fi
  if [ ! -f "$STATE" ] || [ -f "$AGENT_DIR/stopped" ]; then
    echo "=== $(date '+%F %T') supervisor pid=$$ exiting: not enrolled or stopped by MAIN ===" >> "$LOG"
    exit 0
  fi
  if [ -x "$AGENT_DIR/xc_agent" ]; then
    pkill -u xc_vm -x xc_agent 2>/dev/null
    "$AGENT_DIR/xc_agent" run -state "$STATE" >> "$LOG" 2>&1
    rc=$?
    echo "=== $(date '+%F %T') agent exited rc=$rc ===" >> "$LOG"
    if [ "$rc" = "3" ]; then
      date '+%F %T' > "$AGENT_DIR/stopped"
      exit 0
    fi
  fi
  sleep 2
done
