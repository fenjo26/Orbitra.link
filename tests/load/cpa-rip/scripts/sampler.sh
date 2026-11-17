#!/usr/bin/env bash
# Sampler: every 5s records generator + tracker CPU (/proc/stat) and loadavg.
# Usage: sampler.sh <outdir> <pid-to-watch>
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
set -u
OUT="$1"; PID="$2"
# shellcheck source=/dev/null
source "${LT_PARAMS:-$ROOT/params.env}"
SSH_BASE="ssh -i ${SSH_KEY:-$ROOT/keys/lt_ed25519} -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=accept-new -o ControlMaster=auto -o ControlPath=$ROOT/.ssh/cm-%r@%h-p-%p -o ControlPersist=10m ${SSH_USER}@${TRACKER_IP}"

CSV_LOCAL="$OUT/cpu_local.csv"
CSV_REMOTE="$OUT/cpu_tracker.csv"
echo "ts,cpu_line,loadavg" > "$CSV_LOCAL"
echo "ts,remote_ts,cpu_line,loadavg" > "$CSV_REMOTE"

while kill -0 "$PID" 2>/dev/null; do
  TS=$(date +%s.%N)
  echo "$TS,$(grep '^cpu ' /proc/stat),$(cat /proc/loadavg)" >> "$CSV_LOCAL"
  ( $SSH_BASE "date +%s.%N; grep '^cpu ' /proc/stat; cat /proc/loadavg" \
      | awk -v ts="$TS" 'NR==1{rt=$1;next} /^cpu /{cpu=$0} NR==3{load=$0} END{print ts","rt","cpu","load}' \
      >> "$CSV_REMOTE" ) &
  sleep 5
done
wait
