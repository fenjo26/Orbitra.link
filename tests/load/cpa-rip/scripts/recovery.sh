#!/usr/bin/env bash
# Recovery check after overload: 20 rps for 2 min, no reset.
# Polls DB row count for the run prefix every 10s to compute per-window recorded clicks.
# Usage: recovery.sh <run_name>
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
set -euo pipefail
RUN="$1"
DIR="$ROOT/logs/${RUN}_recovery"
mkdir -p "$DIR"
source "${LT_PARAMS:-$ROOT/params.env}"
SSH_BASE="ssh -i ${SSH_KEY:-$ROOT/keys/lt_ed25519} -o BatchMode=yes -o ControlMaster=auto -o ControlPath=$ROOT/.ssh/cm-%r@%h-p-%p -o ControlPersist=10m ${SSH_USER}@${TRACKER_IP}"
POOL_FILE=""
if [ "$PROFILE" = "repeat" ]; then POOL_FILE="$ROOT/data/repeat10k.json"; fi
if [ "$PROFILE" = "repeat-tight" ]; then POOL_FILE="$ROOT/data/repeat50.json"; fi
BASE_URL="http://${TRACKER_IP}/${CAMPAIGN_ALIAS}"

POLL="$DIR/db_poll.csv"
echo "ts,count_total_for_prefix" > "$POLL"
PFX="${RUN}-s20-"

echo "[recovery $RUN] $(date -u +%FT%TZ) starting 20rps/120s" | tee -a $ROOT/LOG.md

k6 run \
  -e RUN="$RUN" -e PROFILE="${PROFILE:-unique}" -e STAGE_RATE=20 \
  -e START_RATE=20 -e BASE_URL="$BASE_URL" \
  -e POOL_FILE="$POOL_FILE" \
  -e RAMP=1 -e HOLD=120 \
  --summary-export="$DIR/k6_summary.json" \
  --out "json=$DIR/k6.json" \
  $ROOT/scripts/k6_click.js \
  > "$DIR/k6_stdout.txt" 2>&1 &
K6PID=$!

bash $ROOT/scripts/sampler.sh "$DIR" "$K6PID" &
SAMPID=$!

while kill -0 "$K6PID" 2>/dev/null; do
  SQL="select count(*) from clicks where campaign_id = ${CAMPAIGN_ID} and json_extract(parameters_json,'\$.sub1') like '${PFX}%'"
  SQLD=${SQL//\$/\\$}
  REMOTE=${DB_READ_CMD//'<SQL>'/"\"$SQLD\""}
  C=$($SSH_BASE "$REMOTE" 2>/dev/null | tail -1) || C=NA
  echo "$(date +%s.%N),${C:-NA}" >> "$POLL"
  sleep 10
done

wait "$K6PID" || true
kill "$SAMPID" 2>/dev/null || true

python3 $ROOT/scripts/process_run.py "$DIR" 1 120 > "$DIR/process_out.txt" 2>&1
python3 $ROOT/scripts/recovery_buckets.py "$DIR" "$PFX" > "$DIR/recovery_buckets.json" 2>&1 || true
python3 $ROOT/scripts/calc_cpu.py "$DIR/cpu_local.csv" \
  "$(jq .window.start_epoch "$DIR/stage_stats.json")" "$(jq .window.end_epoch "$DIR/stage_stats.json")" 1 \
  > "$DIR/cpu_local_stats.json" 2>&1 || true
python3 $ROOT/scripts/calc_cpu.py "$DIR/cpu_tracker.csv" \
  "$(jq .window.start_epoch "$DIR/stage_stats.json")" "$(jq .window.end_epoch "$DIR/stage_stats.json")" 2 \
  > "$DIR/cpu_tracker_stats.json" 2>&1 || true

echo "[recovery $RUN] buckets:" | tee -a $ROOT/LOG.md
cat "$DIR/recovery_buckets.json" | tee -a $ROOT/LOG.md
gzip -f "$DIR/k6.json" || true
echo "[recovery $RUN] done $(date -u +%FT%TZ)" | tee -a $ROOT/LOG.md
