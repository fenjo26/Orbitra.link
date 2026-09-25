#!/bin/bash
# usage: CAMPAIGN_ID=<id> [BASE=http://127.0.0.1] [ALIAS=loadtest] [ORBITRA_DIR=/var/www/orbitra] cidrun.sh <run> <repeat|nat> <rate>
# Sends <rate> rps for 60 s, then checks that EVERY cid returned in a 302 exists in clicks,
# and counts spool lines / "database is locked" added during the run. Run on the tracker host.
RUN=$1; MODE=$2; RATE=$3
DIR=${ORBITRA_DIR:-/var/www/orbitra}; CID=${CAMPAIGN_ID:?set CAMPAIGN_ID}; OUT=${OUT:-/tmp/orbitra-load}; mkdir -p "$OUT"
SP=$DIR/var/spool/clicks.log; S0=$(cat "$SP" 2>/dev/null | wc -l)
k6 run -e RUN=$RUN -e MODE=$MODE -e RATE=$RATE -e BASE=${BASE:-http://127.0.0.1} -e ALIAS=${ALIAS:-loadtest} \
  --summary-export "$OUT/$RUN.json" "$(dirname "$0")/cidcheck.js" 2>&1 | grep -o 'CID [^"]*' | awk '{print $2}' | sort -u > "$OUT/$RUN.cids"
sleep 3
S1=$(cat "$SP" 2>/dev/null | wc -l)
sqlite3 -cmd ".timeout 30000" "$DIR/orbitra_db.sqlite" "select id from clicks where campaign_id=$CID and created_at >= datetime('now','-10 minutes')" | sort -u > "$OUT/$RUN.db"
python3 - "$OUT/$RUN" <<'PY'
import json,sys
r=sys.argv[1]; m=json.load(open(r+'.json'))['metrics']; d=m['http_req_duration']
c=set(open(r+'.cids').read().split())-{'NONE'}; db=set(open(r+'.db').read().split())
print(f"{r.split('/')[-1]}: requests={m['http_reqs']['count']} ok302={m['checks']['passes']} fail={m['checks']['fails']} p95={d['p(95)']:.0f}ms p99={d['p(99)']:.0f}ms distinct_cids={len(c)} cids_missing_in_db={len(c-db)}")
PY
echo "spool lines added: $((S1-S0))"
