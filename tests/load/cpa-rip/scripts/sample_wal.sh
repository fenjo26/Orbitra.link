#!/usr/bin/env bash
# Sample tracker SQLite WAL file size every 5 s for <seconds> (read-only stat via SSH).
# Usage: sample_wal.sh <stage_dir> [seconds]
set -uo pipefail
DIR="$1"; SECS="${2:-280}"; INT="${3:-5}"
source "${LT_PARAMS:-/root/lt/params.env}"
SSH_BASE="ssh -i /root/lt/keys/lt_ed25519 -o BatchMode=yes -o ControlMaster=auto -o ControlPath=/root/lt/.ssh/cm-%r@%h-p-%p -o ControlPersist=10m ${SSH_USER}@${TRACKER_IP}"
WAL="${WAL_FILE:-/var/www/orbitra/orbitra_db.sqlite-wal}"
OUT="$DIR/wal_size.csv"
mkdir -p "$DIR"
echo "ts,size_bytes" > "$OUT"
END=$(( $(date +%s) + SECS ))
while [ "$(date +%s)" -lt "$END" ]; do
  S=$($SSH_BASE "stat -c %s '$WAL' 2>/dev/null" 2>/dev/null)
  echo "$(date +%s),${S:-NA}" >> "$OUT"
  sleep $INT
done
