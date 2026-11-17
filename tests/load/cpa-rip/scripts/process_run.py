#!/usr/bin/env python3
"""Post-process one k6 stage run (json output) into stats + gzipped response log.

Usage: process_run.py <stage_dir> <ramp_s> <hold_s> [--window N]
Stage dir must contain k6.json (k6 --out json output).
Outputs (in stage_dir): resp_log.jsonl.gz, stage_stats.json
Window is measured by request *sent* time (completion time minus duration).
--window N: override hold window length (used for recovery runs).
"""
import gzip
import json
import math
import sys
from collections import Counter, defaultdict


def pct(sorted_vals, p):
    if not sorted_vals:
        return None
    k = max(0, math.ceil(p / 100 * len(sorted_vals)) - 1)
    return sorted_vals[k]


def main():
    stage_dir = sys.argv[1]
    ramp = float(sys.argv[2])
    hold = float(sys.argv[3])
    window = hold
    if '--window' in sys.argv:
        window = float(sys.argv[sys.argv.index('--window') + 1])

    t0 = None
    drops_total = 0
    drop_points = []
    reqs = []
    with open(f'{stage_dir}/k6.json') as f:
        for line in f:
            try:
                o = json.loads(line)
            except json.JSONDecodeError:
                continue
            m = o.get('metric', '')
            d = o.get('data', {})
            t = d.get('time')
            if t is None:
                continue
            # ISO8601 Z -> epoch float
            ts = iso_to_epoch(t)
            if t0 is None or ts < t0:
                t0 = ts
            if m == 'resp_log':
                tg = d.get('tags', {})
                ms = float(tg.get('ms', 0) or 0)
                reqs.append({
                    'ts': ts - ms / 1000.0,  # sent time
                    'eid': tg.get('eid'),
                    'ip': tg.get('ip'),
                    'profile': tg.get('profile'),
                    'status': tg.get('st'),
                    'result': tg.get('r'),
                    'cid': tg.get('cid'),
                    'ms': ms,
                    'err': tg.get('err', ''),
                })
            elif m == 'dropped_iterations':
                drops_total += d.get('value', 0)
                drop_points.append((ts, d.get('value', 0)))

    if not reqs:
        print('ERROR: no resp_log data points found', file=sys.stderr)
        sys.exit(2)

    w_start = t0 + ramp
    w_end = t0 + ramp + window
    in_win = [r for r in reqs if w_start <= r['ts'] < w_end]
    drops_win = sum(v for ts, v in drop_points if w_start <= ts < w_end)

    res_cnt = Counter(r['result'] for r in in_win)
    status_cnt = Counter(r['status'] for r in in_win if r['result'] == 'non302')
    errs = Counter()
    for r in reqs:  # errors during ramp window, for information
        if r['result'] not in ('ok',) and not (w_start <= r['ts'] < w_end):
            errs[r['result']] += 1

    durations = sorted(r['ms'] for r in in_win)
    sent = len(in_win)
    dur_actual = w_end - w_start
    lat = {
        'avg': round(sum(durations) / len(durations), 2) if durations else None,
        'p50': pct(durations, 50),
        'p95': pct(durations, 95),
        'p99': pct(durations, 99),
        'max': durations[-1] if durations else None,
    }

    stats = {
        'stage_dir': stage_dir,
        't0_epoch': t0,
        'window': {'start_epoch': w_start, 'end_epoch': w_end, 'duration_s': dur_actual},
        'sent': sent,
        'actual_send_rate': round(sent / dur_actual, 2) if dur_actual else None,
        'ok_302': res_cnt.get('ok', 0),
        'errors': {
            'timeout': res_cnt.get('timeout', 0),
            'non302': res_cnt.get('non302', 0),
            'non302_by_status': dict(status_cnt),
            'badloc': res_cnt.get('badloc', 0),
            'neterr': res_cnt.get('neterr', 0),
            'total': sent - res_cnt.get('ok', 0),
            'outside_window': dict(errs),
        },
        'dropped_iterations': {'window': drops_win, 'total_run': drops_total},
        'latency_ms_all_requests_incl_errors': lat,
    }

    with gzip.open(f'{stage_dir}/resp_log.jsonl.gz', 'wt') as gz:
        for r in reqs:
            gz.write(json.dumps(r) + '\n')

    with open(f'{stage_dir}/stage_stats.json', 'w') as f:
        json.dump(stats, f, indent=2)
    print(json.dumps(stats, indent=2))


def iso_to_epoch(s):
    from datetime import datetime, timezone
    s = s.replace('Z', '+00:00')
    return datetime.fromisoformat(s).timestamp()


if __name__ == '__main__':
    main()
