#!/usr/bin/env bash
# Collect error-log snippets + spool info from tracker for a stage dir (read-only).
# Usage: log_errors.sh <stage_dir>
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
set -u
DIR="$1"
# shellcheck source=/dev/null
source "${LT_PARAMS:-$ROOT/params.env}"
OUT="$DIR/tracker_logs.txt"
SSH_BASE="ssh -i ${SSH_KEY:-$ROOT/keys/lt_ed25519} -o BatchMode=yes -o ConnectTimeout=5 -o ControlMaster=auto -o ControlPath=$ROOT/.ssh/cm-%r@%h-p-%p -o ControlPersist=10m ${SSH_USER}@${TRACKER_IP}"

{
  echo "=== $(date -u +%FT%TZ) stage dir: $DIR ==="
  echo "--- spool file ---"
  $SSH_BASE "ls -la ${SPOOL_FILE:-/var/spool/clicks.log} 2>/dev/null; wc -l ${SPOOL_FILE:-/var/spool/clicks.log} 2>/dev/null" || true
  echo "--- nginx/php-fpm error tails ---"
  $SSH_BASE "grep -hE 'worker_connections|upstream|temporarily unavailable' ${NGINX_ERROR_LOG:-/var/log/nginx/error.log} ${PHPFPM_LOG:-/var/log/php8.5-fpm.log} 2>/dev/null | tail -100" || true
  echo "--- orbitra var/logs tails ---"
  $SSH_BASE "find ${ORBITRA_DIR:-/var/www/orbitra}/var/logs -type f -newermt '-10 minutes' 2>/dev/null | head; find ${ORBITRA_DIR:-/var/www/orbitra}/var/logs -type f -newermt '-10 minutes' -exec tail -n 50 {} + 2>/dev/null | tail -200" || true
} > "$OUT" 2>&1
echo "tracker logs written to $OUT" | tee -a $ROOT/LOG.md
