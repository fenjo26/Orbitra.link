#!/usr/bin/env bash
# Run one ladder stage: ramp RAMP s to STAGE_RATE, hold HOLD s.
# Usage: run_stage.sh <run_name> <profile> <stage_rate> <start_rate> [extra env as KEY=VAL...]
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
set -euo pipefail
RUN="$1"; PROFILE="$2"; RATE="$3"; START_RATE="$4"; shift 4
DIR="$ROOT/logs/${RUN}_${PROFILE}_s${RATE}"
mkdir -p "$DIR"
# shellcheck source=/dev/null
source "${LT_PARAMS:-$ROOT/params.env}"

POOL_FILE=""
if [ "$PROFILE" = "repeat" ]; then POOL_FILE="$ROOT/data/repeat10k.json"; fi
if [ "$PROFILE" = "repeat-tight" ]; then POOL_FILE="$ROOT/data/repeat50.json"; fi

BASE_URL="http://${TRACKER_IP}/${CAMPAIGN_ALIAS}"

echo "[run_stage $(date -u +%FT%TZ)] RUN=$RUN PROFILE=$PROFILE RATE=$RATE START_RATE=$START_RATE -> $DIR" | tee -a $ROOT/LOG.md

k6 run \
  -e RUN="$RUN" -e PROFILE="$PROFILE" -e STAGE_RATE="$RATE" \
  -e START_RATE="$START_RATE" -e BASE_URL="$BASE_URL" \
  -e POOL_FILE="$POOL_FILE" \
  -e RAMP="${RAMP:-30}" -e HOLD="${HOLD:-180}" \
  "$@" \
  --summary-export="$DIR/k6_summary.json" \
  --out "json=$DIR/k6.json" \
  $ROOT/scripts/k6_click.js \
  > "$DIR/k6_stdout.txt" 2>&1 &
K6PID=$!

bash $ROOT/scripts/sampler.sh "$DIR" "$K6PID"

K6RC=0
wait "$K6PID" || K6RC=$?
echo "[run_stage] k6 exit code: $K6RC" | tee -a $ROOT/LOG.md

python3 $ROOT/scripts/process_run.py "$DIR" "${RAMP:-30}" "${HOLD:-180}" > "$DIR/process_out.txt" 2>&1 || true
echo "[run_stage] processed. stats:" | tee -a $ROOT/LOG.md
jq -c '{sent,ok_302,errors,dropped_iterations,latency_ms_all_requests_incl_errors}' "$DIR/stage_stats.json" | tee -a $ROOT/LOG.md

if [ "${NO_RECON:-0}" != "1" ]; then
  python3 $ROOT/scripts/verify_stage.py "$DIR" "${RAMP:-30}" "${HOLD:-180}" > "$DIR/recon_out.txt" 2>&1 || true
  echo "[run_stage] recon done, see $DIR/recon_out.txt" | tee -a $ROOT/LOG.md
fi

bash $ROOT/scripts/log_errors.sh "$DIR" || true

gzip -f "$DIR/k6.json" || true
echo "[run_stage $(date -u +%FT%TZ)] done, dir=$DIR rc=$K6RC" | tee -a $ROOT/LOG.md

bash $ROOT/scripts/post_cpu.sh "$DIR" "${RAMP:-30}" "${HOLD:-180}" || true
