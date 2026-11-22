# ТЗ 2.1 — Load-stand runbook

Executable commands for the tracker agent; no codebase knowledge assumed.
Companion: [docs/ru/STAND_RUNBOOK_2.1.md](ru/STAND_RUNBOOK_2.1.md).

## Prerequisites

```bash
# On the LOCAL machine (has the repo):
cd /path/to/Orbitra
git fetch origin && git checkout v2.0.3          # start from the release tag

# On the STAND (the tracker host):
#   PHP 8.2+, k6, sqlite3, python3, curl, jq
#   The tracker installed at /var/www/orbitra (adjust ORBITRA_DIR below)
ORBITRA_DIR=/var/www/orbitra
DB=$ORBITRA_DIR/orbitra_db.sqlite
POSTBACK_KEY=$(sqlite3 "$DB" "SELECT value FROM settings WHERE key='postback_key'")
BASE=http://127.0.0.1

# Create the load campaign (one regular stream → offer redirect, 24 h IP_UA):
#   Through the panel: Campaigns → Add → alias "loadtest", stream: redirect to
#   any offer, uniqueness: 24 h IP_UA. Note the id.
CAMPAIGN_ID=<id>        # ← set this
```

## Scenario A: seed 1.7 M clicks + 10 K conversions on v2.0.3

### A1. Generate ~1.7 M clicks (480 rps × 60 min, unique IPs)

```bash
cd /path/to/Orbitra
ssh stand "cd $ORBITRA_DIR && CAMPAIGN_ID=$CAMPAIGN_ID \
  bash tests/load/run.sh A_seed unique 3600s 480"
```

**Expected:** `sent ≈ 1,728,000`, `ok302 = sent`, `fail = 0`, `dropped ≈ 0`, `db ≈ sent`.

### A2. Send 10 000 real postback conversions

The postback URL format is `/$POSTBACK_KEY/postback?subid=<click_id>&status=<status>&payout=<amount>`.

```bash
# Pick 10 000 random click ids, curl the postback URL for each.
ssh stand "sqlite3 '$DB' \"SELECT id FROM clicks WHERE campaign_id=$CAMPAIGN_ID ORDER BY RANDOM() LIMIT 10000\" \
  | while read cid; do
      curl -s -o /dev/null -w '%{http_code}\n' \
        '$BASE/$POSTBACK_KEY/postback?subid='$cid'&status=sale&payout='\"(\$RANDOM % 50 + 1)\"'.'\"(\$RANDOM % 100)\"
    done > /tmp/postbacks.log"
grep -c 200 /tmp/postbacks.log     # expect 10000
```

**Expected:** all postbacks return 200; `SELECT COUNT(*) FROM conversions` increases by 10 000.

### A3. Age the data (shift created_at 35–95 days back)

```bash
ssh stand "sqlite3 '$DB' \"
  -- Shift clicks: 35–95 days back, uniform across hours.
  UPDATE clicks SET created_at = datetime(created_at, '-' || (35 + ABS(RANDOM() % 61)) || ' days',
    '-' || (ABS(RANDOM() % 24)) || ' hours')
  WHERE campaign_id = $CAMPAIGN_ID;

  -- Shift conversions to match their clicks (the join is by click_id).
  UPDATE conversions SET created_at = (
    SELECT c.created_at FROM clicks c WHERE c.id = conversions.click_id
  ) WHERE click_id IN (SELECT id FROM clicks WHERE campaign_id = $CAMPAIGN_ID);

  -- Update the denormalised click_created_at on conversions too (migration 86).
  UPDATE conversions SET click_created_at = created_at
  WHERE click_id IN (SELECT id FROM clicks WHERE campaign_id = $CAMPAIGN_ID);
\""
```

**Verify:**
```bash
ssh stand "sqlite3 '$DB' \"
  SELECT MIN(created_at), MAX(created_at), COUNT(*) FROM clicks WHERE campaign_id=$CAMPAIGN_ID;
  SELECT COUNT(*) FROM conversions;
\""
# Expect: min ≈ 95 days ago, max ≈ 35 days ago, clicks ≈ 1.7 M, conversions ≈ 10 K.
```

### A4. Snapshot the base (for repeats)

```bash
ssh stand "cp '$DB' /root/orbitra_2.1_base.sqlite && \
  sqlite3 /root/orbitra_2.1_base.sqlite 'SELECT COUNT(*) FROM clicks; SELECT COUNT(*) FROM conversions;'"
```

### A5. Baseline: reports over the FULL period (the equivalence reference)

```bash
# Capture the dashboard cards, campaign list and country report BEFORE any freeze.
# These are the golden numbers scenario D compares against.
ssh stand "
  # Login (admin)
  TOKEN=\$(curl -s -c /tmp/cookies.txt -X POST '$BASE/api.php?action=login' \
    -H 'Content-Type: application/json' \
    -d '{\"username\":\"admin\",\"password\":\"<admin_password>\"}' | jq -r '.data.csrf_token')

  # Dashboard cards over the full period
  curl -s -b /tmp/cookies.txt '$BASE/api.php?action=metrics&date_range=all' > /root/ref_metrics.json

  # Campaign list over the full period
  curl -s -b /tmp/cookies.txt '$BASE/api.php?action=campaigns&date_range=all' > /root/ref_campaigns.json

  # Campaign report: country layer, full period
  curl -s -b /tmp/cookies.txt '$BASE/api.php?action=campaign_report&campaign_id=$CAMPAIGN_ID&group_by=country&date_range=all' > /root/ref_country.json
"
echo "Reference captured: /root/ref_*.json"
```

---

## Scenario B: update to tz-2.1 under 200 rps traffic

### B1. Deploy the branch

```bash
cd /path/to/Orbitra
git checkout tz-2.1
# Deploy to the stand (the standard update path):
ssh stand "cd $ORBITRA_DIR && git fetch && git checkout tz-2.1 && php cli/run_update.php 2>&1 | tail -5"
```

### B2. Drive traffic during the migration

```bash
ssh stand "cd $ORBITRA_DIR && CAMPAIGN_ID=$CAMPAIGN_ID \
  bash tests/load/run.sh B_update unique 900s 200"
```

**Watch for (run in another terminal):**

```bash
# The freeze progress (backlog hours decreasing):
watch -n 5 "sqlite3 '$DB' \"SELECT value FROM settings WHERE key='stats_frozen_through_hour'\""

# The conversions rebuild state:
watch -n 5 "sqlite3 '$DB' \"SELECT value FROM settings WHERE key='conversions_rebuild_state'\""

# Migration errors in the logs:
tail -f $ORBITRA_DIR/var/logs/*.log | grep -i 'error\|fail\|locked'
```

**Pass criteria:**
- Generator: `fail = 0`, `dropped = 0`, `p99 < 500 ms`.
- `conversions_rebuild_state` → disappears (done) within a few minutes (10 K conversions).
- `stats_frozen_through_hour` advances (backlog shrinking; record how long the catch-up takes).
- Panel → System Status → Statistics rollup: shows the frozen-through date advancing.

### B3. Record the catch-up time

```bash
ssh stand "sqlite3 '$DB' \"
  SELECT 'rebuild done', updated_at FROM settings WHERE key='conversions_rebuild_state' LIMIT 1;
  SELECT 'freeze target reached', value, updated_at FROM settings WHERE key='stats_frozen_through_hour';
\""
```

Note: the freeze cron advances one hour per transaction, ~1 M clicks/hour.
For 1.7 M clicks over 61 days (35–95 days back = ~1464 hours of data), the catch-up
at 1 hour/minute-tick takes ~24 hours of cron time. On the stand the cron fires
every minute; actual wall time depends on hour density.

---

## Scenario C: freeze + cleanup under 480 rps × 15 min

### C1. Lower the raw retention to 31 days

```bash
ssh stand "sqlite3 '$DB' \"
  INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_raw_retention_days', '31');
\""
# Wait for the freeze to catch up to the new window (~2–3 minutes at 200 rps
# background), then verify:
ssh stand "sqlite3 '$DB' \"SELECT value FROM settings WHERE key='stats_frozen_through_hour'\""
```

### C2. Run 480 rps for 15 minutes

```bash
ssh stand "cd $ORBITRA_DIR && CAMPAIGN_ID=$CAMPAIGN_ID \
  bash tests/load/run.sh C_load unique 900s 480"
```

### C3. Collect metrics DURING the run

```bash
# WRITER hold during hour-freeze commits — add a µs timer:
# Before the run, patch core/stats_freeze.php line ~170 to log the commit time:
ssh stand "cd $ORBITRA_DIR && sed -i.bak \
  's/orbitraStatsCommitFrozenHour(\$pdo, \$next, \$read);/\\
  { \$__t0=microtime(true); orbitraStatsCommitFrozenHour(\$pdo, \$next, \$read); \\
  error_log(sprintf(\"FREEZE_COMMIT_MS %.1f hour=%d\", (microtime(true)-\$__t0)*1000, \$next)); }/' \
  core/stats_freeze.php"

# Watch the commit times during the load:
ssh stand "tail -f $ORBITRA_DIR/var/logs/error.log 2>/dev/null | grep FREEZE_COMMIT" &
# OR if error_log goes to stderr of the spool cron:
ssh stand "tail -f $ORBITRA_DIR/var/logs/click_spool.log 2>/dev/null | grep FREEZE_COMMIT" &

# WAL size (should fold back after traffic subsides):
watch -n 10 "ls -lh $DB-wal 2>/dev/null"

# The quiet fold state:
watch -n 10 "cat $ORBITRA_DIR/var/cache/wal_checkpoint.json | jq .quiet_pending,.wal_growth_10s"
```

**Pass criteria:**
- Generator: `fail = 0`, `dropped = 0`.
- p99 from the generator output ≤ the v2.0.2 baseline (compare with mon202 results).
- FREEZE_COMMIT_MS: median < 200 ms, max < 500 ms (the stage-1 benchmark said
  ~260 ms median on a laptop; the stand should be faster).
- After traffic subsides: WAL file returns to ≤ 64 MB within 10 minutes
  (the quiet fold from 2.0.2 — verify it hasn't slowed down).
- `SELECT COUNT(*) FROM stats_place_country` > 0 (rollups are being written).

### C4. Cleanup verification

```bash
ssh stand "sqlite3 '$DB' \"
  SELECT 'total clicks', COUNT(*) FROM clicks;
  SELECT 'converted clicks', COUNT(*) FROM clicks WHERE id IN (SELECT DISTINCT click_id FROM conversions);
  SELECT 'frozen through', value FROM settings WHERE key='stats_frozen_through_hour';
\""
# Also run the cleanup manually:
ssh stand "php $ORBITRA_DIR/cli/cleanup_cron.php"
# Then verify:
ssh stand "sqlite3 '$DB' \"
  SELECT 'after cleanup', COUNT(*) FROM clicks;
  SELECT 'conversions intact', COUNT(*) FROM conversions;
\""
```

**Pass criteria:**
- Unconverted clicks older than 31 days are deleted; converted clicks survive.
- Conversions never disappear.
- Reports over the full period still return the same totals (from the rollups).

---

## Scenario D: report equivalence on stand data

### D1. Compare against the reference (A5)

```bash
ssh stand "
  TOKEN=\$(curl -s -c /tmp/cookies.txt -X POST '$BASE/api.php?action=login' \
    -H 'Content-Type: application/json' \
    -d '{\"username\":\"admin\",\"password\":\"<admin_password>\"}' | jq -r '.data.csrf_token')

  # Same reports, now over the seam:
  curl -s -b /tmp/cookies.txt '$BASE/api.php?action=metrics&date_range=all' > /root/seam_metrics.json
  curl -s -b /tmp/cookies.txt '$BASE/api.php?action=campaigns&date_range=all' > /root/seam_campaigns.json
  curl -s -b /tmp/cookies.txt '$BASE/api.php?action=campaign_report&campaign_id=$CAMPAIGN_ID&group_by=country&date_range=all' > /root/seam_country.json
"

# Compare (python3, money at 1e-6):
python3 <<'PY'
import json

def close(a, b, tol=1e-6):
    if isinstance(a, (int, float)) and isinstance(b, (int, float)):
        return abs(a - b) <= tol
    return a == b

def cmp_metrics(ref, seam):
    r = json.load(open(ref))['data']
    s = json.load(open(seam))['data']
    bad = [k for k in r if not close(r[k], s.get(k, 0))]
    return bad

bad = cmp_metrics('/root/ref_metrics.json', '/root/seam_metrics.json')
print('metrics: ' + ('OK' if not bad else f'MISMATCH: {bad[:5]}'))

r = json.load(open('/root/ref_campaigns.json'))['data']
s = json.load(open('/root/seam_campaigns.json'))['data']
if len(r) == len(s):
    diffs = []
    for row_r, row_s in zip(sorted(r, key=lambda x: x['id']), sorted(s, key=lambda x: x['id'])):
        for k in ('clicks', 'unique_clicks', 'conversions', 'revenue', 'cost'):
            if not close(row_r.get(k, 0), row_s.get(k, 0)):
                diffs.append(f"campaign {row_r['id']} {k}: {row_r[k]} vs {row_s[k]}")
    print('campaigns: ' + ('OK' if not diffs else f'MISMATCH: {diffs[:5]}'))
else:
    print(f'campaigns: ROW COUNT {len(r)} vs {len(s)}')

r = json.load(open('/root/ref_country.json'))['data']['rows']
s = json.load(open('/root/seam_country.json'))['data']['rows']
if len(r) == len(s):
    diffs = []
    for row_r, row_s in zip(sorted(r, key=lambda x: x['dims']), sorted(s, key=lambda x: x['dims'])):
        for k in ('clicks', 'conversions', 'revenue', 'cost'):
            if not close(row_r.get(k, 0), row_s.get(k, 0)):
                diffs.append(f"{row_r['dims']} {k}: {row_r[k]} vs {row_s[k]}")
    print('country report: ' + ('OK' if not diffs else f'MISMATCH: {diffs[:5]}'))
else:
    print(f'country report: ROW COUNT {len(r)} vs {len(s)}')
PY
```

**Pass criteria:** all three reports print `OK` (zero divergence on money, counts).

**Important note:** the reference was captured on v2.0.3 BEFORE the update.
The uniqueness semantics changed (flags vs COUNT DISTINCT), so `unique_clicks`
is EXPECTED to differ. Compare `clicks`, `conversions`, `revenue`, `cost` —
these must be identical.

---

## Scenario E: VACUUM button on a large base under 100 rps

### E1. Check the pre-state

```bash
ssh stand "sqlite3 '$DB' \"
  SELECT 'auto_vacuum', (SELECT value FROM pragma_auto_vacuum);
  SELECT 'db size MB', (SELECT page_count * page_size / 1048576 FROM pragma_page_count, pragma_page_size);
  SELECT 'freelist', (SELECT value FROM pragma_freelist_count);
  SELECT 'free MB', (SELECT freelist_count * page_size / 1048576 FROM pragma_freelist_count, pragma_page_size);
\""
df -h $(dirname $DB)    # need ≥ 2.2× the db size free
```

### E2. Press the button (API)

```bash
ssh stand "
  TOKEN=\$(curl -s -c /tmp/cookies.txt -X POST '$BASE/api.php?action=login' \
    -H 'Content-Type: application/json' \
    -d '{\"username\":\"admin\",\"password\":\"<admin_password>\"}' | jq -r '.data.csrf_token')

  curl -s -b /tmp/cookies.txt -X POST \
    -H 'X-CSRF-TOKEN: '\$TOKEN \
    '$BASE/api.php?action=convert_incremental_vacuum'
"
# Expected: {"status":"success","data":{"state":"requested",...}}
```

### E3. Drive 100 rps traffic during the conversion

```bash
ssh stand "cd $ORBITRA_DIR && CAMPAIGN_ID=$CAMPAIGN_ID \
  bash tests/load/run.sh E_vacuum unique 300s 100" &
```

### E4. Monitor the conversion + click spooling

```bash
# Watch the state machine:
watch -n 5 "sqlite3 '$DB' \"SELECT value FROM settings WHERE key='vacuum_conversion_state'\""

# Watch the pid flag:
watch -n 5 "cat $ORBITRA_DIR/var/cache/vacuum.running 2>/dev/null && ps -p \$(cat $ORBITRA_DIR/var/cache/vacuum.running) || echo 'no flag'"

# Watch the spool grow (clicks bypassing the DB during VACUUM):
watch -n 5 "wc -l $ORBITRA_DIR/var/spool/clicks.log 2>/dev/null || echo 0"

# After VACUUM: the spool drains (the cron replays):
watch -n 10 "wc -l $ORBITRA_DIR/var/spool/clicks.log 2>/dev/null; sqlite3 '$DB' 'SELECT COUNT(*) FROM clicks WHERE created_at > datetime(\"now\", \"-10 minutes\")'"
```

**Pass criteria:**
- `vacuum_conversion_state` → `running` → `done`.
- Generator: `fail = 0` (clicks went to the spool, not timed out).
- During VACUUM: spool grows; after VACUUM: spool drains to 0, clicks in DB.
- `PRAGMA auto_vacuum` = 2 (INCREMENTAL).
- Database file size decreased (or `db_reclaimable_bytes > 0` in System Status).
- `incremental_vacuum` doses appear in the spool cron log over the next minutes.

---

## Cleanup

```bash
# Restore the base for repeats:
ssh stand "systemctl stop php*-fpm nginx 2>/dev/null; \
  cp /root/orbitra_2.1_base.sqlite '$DB' && rm -f '$DB-wal' '$DB-shm' && \
  systemctl start php*-fpm nginx 2>/dev/null"
```

## What to collect from each scenario

| Scenario | Files to save | Metrics to note |
|---|---|---|
| A | `/tmp/postbacks.log`, generator JSON/log | sent, ok302, fail, clicks count, conversions count |
| B | generator JSON/log, freeze/rebuild state timeline | catch-up time (first tick → target reached), fail count |
| C | generator JSON/log, FREEZE_COMMIT_MS lines, wal_checkpoint.json | p50/p99, WRITER hold median/max, WAL recovery time |
| D | ref_*.json, seam_*.json, comparison output | divergence count (must be 0 for clicks/conv/rev/cost) |
| E | generator JSON/log, vacuum state timeline, spool line counts | VACUUM duration, spool max depth, reclaim MB |
