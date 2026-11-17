#!/usr/bin/env bash
# Pre-run: RESET_CMD, 60s pause, loadavg check (<0.5), smoke request.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
set -euo pipefail
RUN="$1"
source "${LT_PARAMS:-$ROOT/params.env}"
SSH_BASE="ssh -i ${SSH_KEY:-$ROOT/keys/lt_ed25519} -o BatchMode=yes -o ControlMaster=auto -o ControlPath=$ROOT/.ssh/cm-%r@%h-p-%p -o ControlPersist=10m ${SSH_USER}@${TRACKER_IP}"
echo "[pre_run $RUN] $(date -u +%FT%TZ) resetting" | tee -a $ROOT/LOG.md
$SSH_BASE "$RESET_CMD" | tee -a $ROOT/LOG.md
sleep 60
LA=$($SSH_BASE 'cat /proc/loadavg')
echo "[pre_run $RUN] loadavg: $LA" | tee -a $ROOT/LOG.md
L1=$(echo "$LA" | cut -d' ' -f1)
awk -v l="$L1" 'BEGIN{exit !(l < 0.5)}' || { echo "LOADAVG TOO HIGH: $LA" | tee -a $ROOT/LOG.md; exit 4; }
S=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "http://${TRACKER_IP}/${CAMPAIGN_ALIAS}?sub1=smoke-${RUN}")
echo "[pre_run $RUN] smoke: $S" | tee -a $ROOT/LOG.md
echo "$S" | grep -q '^302 https://offer.example/?cid=' || { echo "SMOKE FAILED" | tee -a $ROOT/LOG.md; exit 5; }
echo "[pre_run $RUN] ok" | tee -a $ROOT/LOG.md
