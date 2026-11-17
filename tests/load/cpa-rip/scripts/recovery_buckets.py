#!/usr/bin/env python3
"""Per-10s recovery buckets: % correct redirects and % recorded clicks.

Usage: recovery_buckets.py <run_dir> <sub1_prefix>
Reads resp_log.jsonl.gz + db_poll.csv (ts, count_total_for_prefix).
Bucket recorded clicks = diff of cumulative DB counts between bucket edges.
"""
import gzip
import json
import sys

run_dir, prefix = sys.argv[1], sys.argv[2]

reqs = []
with gzip.open(f'{run_dir}/resp_log.jsonl.gz', 'rt') as gz:
    for line in gz:
        r = json.loads(line)
        reqs.append(r)

t0 = min(r['ts'] for r in reqs)
t1 = max(r['ts'] for r in reqs) + 1

poll = []
with open(f'{run_dir}/db_poll.csv') as f:
    next(f)
    for line in f:
        ts, cnt = line.strip().split(',')
        if cnt != 'NA':
            poll.append((float(ts), int(cnt)))

buckets = []
for b in range(int(t0), int(t0) + int(t1 - t0) + 10, 10):
    b0, b1 = b, b + 10
    inb = [r for r in reqs if b0 <= r['ts'] < b1]
    if not inb and not poll:
        continue
    sent = len(inb)
    ok = sum(1 for r in inb if r['result'] == 'ok')
    # DB rows recorded during this bucket: cumulative count at bucket end
    # (last poll before b1) minus cumulative count before bucket start (last poll < b0)
    before = next((c for ts, c in reversed(poll) if ts < b0), poll[0][1] if poll else 0)
    after = next((c for ts, c in reversed(poll) if ts < b1), poll[-1][1] if poll else 0)
    recorded = max(0, after - before)
    buckets.append({
        'bucket_start': b0,
        'sent': sent,
        'ok_302': ok,
        'ok_pct': round(100 * ok / sent, 2) if sent else None,
        'db_recorded_in_bucket': recorded,
        'recorded_pct_of_sent': round(100 * recorded / sent, 2) if sent else None,
    })

out = {
    'run_dir': run_dir,
    'prefix': prefix,
    'buckets': buckets,
    'first_bucket_all_100': next((b['bucket_start'] for b in buckets
                                  if b['sent'] > 0 and b['ok_pct'] == 100.0 and b['recorded_pct_of_sent'] == 100.0), None),
}
print(json.dumps(out, indent=2))
