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
#
# A binary MAIN's `agent_binary` just installed is on trial (xc_agent.trial,
# "<installed at> <failed starts>"): if it exits within 60 s of its start three
# times within TRIAL_SEC of the install, the one it replaced (xc_agent.prev)
# is put back. A run that lasts, an exit MAIN asked for, or the end of the
# trial ends it. XCVM_AGENT_HOME is for tests: sudo does not pass it on.
SCRIPT="${XCVM_AGENT_HOME:-/home/xc_vm}"
AGENT_DIR="$SCRIPT/bin/xc_agent"
STATE="$SCRIPT/config/cluster/agent.json"
MAIN_ID="$SCRIPT/config/cluster/main.json"
MAIN_STATE="$SCRIPT/config/cluster/main_agent.json"
LOG="$AGENT_DIR/xc_agent.log"
TRIAL_SEC=600

exec 9>"$AGENT_DIR/run.lock"
if command -v flock >/dev/null 2>&1; then
  flock -n 9 || exit 0
fi
echo "=== $(date '+%F %T') supervisor pid=$$ start ===" >> "$LOG"

# trial <started at> <exit code>: judge a run of a binary on trial.
trial() {
  local now at fails
  [ -f "$AGENT_DIR/xc_agent.trial" ] || return 0
  now=$(date +%s)
  read -r at fails < "$AGENT_DIR/xc_agent.trial"
  if [ $((now - ${at:-0})) -gt "$TRIAL_SEC" ] || [ $((now - $1)) -ge 60 ] || [ "$2" = "3" ]; then
    rm -f "$AGENT_DIR/xc_agent.trial"
    return 0
  fi
  fails=$(( ${fails:-0} + 1 ))
  if [ "$fails" -ge 3 ] && [ -x "$AGENT_DIR/xc_agent.prev" ]; then
    mv -f "$AGENT_DIR/xc_agent.prev" "$AGENT_DIR/xc_agent"
    rm -f "$AGENT_DIR/xc_agent.trial"
    echo "=== $(date '+%F %T') the new agent failed at start $fails times: the previous one is back ===" >> "$LOG"
  else
    echo "$at $fails" > "$AGENT_DIR/xc_agent.trial"
  fi
}

while true; do
  if [ -f "$MAIN_ID" ] && [ -f "$MAIN_STATE" ]; then
    # MAIN: its data-plane client. main.json says whether it serves; an
    # identity that changed ends the agent, and the loop starts it anew.
    if [ -x "$AGENT_DIR/xc_agent" ]; then
      pkill -u xc_vm -x xc_agent 2>/dev/null
      started=$(date +%s)
      "$AGENT_DIR/xc_agent" run -role main -state "$MAIN_STATE" >> "$LOG" 2>&1
      rc=$?
      echo "=== $(date '+%F %T') main agent exited rc=$rc ===" >> "$LOG"
      trial "$started" "$rc"
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
    started=$(date +%s)
    "$AGENT_DIR/xc_agent" run -state "$STATE" >> "$LOG" 2>&1
    rc=$?
    echo "=== $(date '+%F %T') agent exited rc=$rc ===" >> "$LOG"
    trial "$started" "$rc"
    if [ "$rc" = "3" ]; then
      date '+%F %T' > "$AGENT_DIR/stopped"
      exit 0
    fi
  fi
  sleep 2
done
