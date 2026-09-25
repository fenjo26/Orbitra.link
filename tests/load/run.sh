#!/bin/bash
# usage: CAMPAIGN_ID=<id> [ALIAS=loadtest] [BASE=http://127.0.0.1] [ORBITRA_DB=...] run.sh <label> <unique|repeat> <dur> <rate>...
# Must run ON the tracker host (reads /proc/stat and the SQLite file). See README.md.
LABEL=$1; MODE=$2; DUR=$3; shift 3
DB=${ORBITRA_DB:-/var/www/orbitra/orbitra_db.sqlite}
CID=${CAMPAIGN_ID:?set CAMPAIGN_ID}
OUT=${OUT:-/tmp/orbitra-load}; mkdir -p $OUT
for R in "$@"; do
  RUN="${LABEL}_${MODE}_${R}"
  read -r _ a b c d e f g h _ < <(head -1 /proc/stat); T0=$((a+b+c+d+e+f+g+h)); I0=$((d+e))
  k6 run -q -e ALIAS=${ALIAS:-loadtest} -e BASE=${BASE:-http://127.0.0.1} --summary-export $OUT/$RUN.json -e RATE=$R -e DUR=$DUR -e MODE=$MODE -e RUN=$RUN "$(dirname "$0")/click.js" >$OUT/$RUN.log 2>&1
  read -r _ a b c d e f g h _ < <(head -1 /proc/stat); T1=$((a+b+c+d+e+f+g+h)); I1=$((d+e))
  CPU=$(( 100*((T1-T0)-(I1-I0))/(T1-T0) ))
  sleep 3
  INDB=$(sqlite3 -cmd ".timeout 30000" $DB "select count(*) from clicks where campaign_id=$CID and parameters_json like '%\"$RUN\"%'" )
  SPOOL=$(grep -c "$RUN" "$(dirname "$DB")/var/spool/clicks.log" 2>/dev/null); SPOOL=${SPOOL:-0}
  python3 - "$RUN" "$R" "$CPU" "$INDB" "$SPOOL" "$OUT" <<'PY'
import json,sys
run,rate,cpu,indb,spool=sys.argv[1:6]
m=json.load(open(sys.argv[6]+f'/{run}.json'))['metrics']
d=m['http_req_duration']; reqs=m['http_reqs']['count']
chk=m['checks']; ok=chk['passes']; bad=chk['fails']
drop=m.get('dropped_iterations',{}).get('count',0)
print(f"{run:28s} rate={rate:>4} sent={reqs:>6} ok302={ok:>6} fail={bad:>5} dropped={drop:>5} p50={d['med']:.0f}ms p95={d['p(95)']:.0f}ms p99={d['p(99)']:.0f}ms max={d['max']:.0f}ms cpu={cpu}% db={indb} spool={spool}")
PY
done
