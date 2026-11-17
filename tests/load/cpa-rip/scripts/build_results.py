#!/usr/bin/env python3
"""Build results/summary.json and summary.md from all stage dirs."""
import glob
import json
import os

LOGS = ROOT + '/logs'
RES = ROOT + '/results'
os.makedirs(RES, exist_ok=True)


def load(d, name):
    p = f'{LOGS}/{d}/{name}'
    if os.path.exists(p):
        return json.load(open(p))
    return None


def stage_row(d):
    s = load(d, 'stage_stats.json')
    r = load(d, 'recon.json')
    tl = load(d, 'cpu_tracker_stats.json')
    gl = load(d, 'cpu_local_stats.json')
    if not s:
        return None
    lat = s['latency_ms_all_requests_incl_errors']
    e = s['errors']
    sent = s['sent']
    import re
    m = re.search(r'_s(\d+)$', d)
    row = {
        'dir': d,
        'sent': sent,
        'rate_configured': float(m.group(1)) if m else None,
        'actual_rate': s['actual_send_rate'],
        'ok_302': s['ok_302'],
        'err_timeout': e['timeout'],
        'err_non302': e['non302'],
        'non302_by_status': e.get('non302_by_status', {}),
        'err_neterr': e['neterr'],
        'err_total': e['total'],
        'drops': s['dropped_iterations'],
        'lat_avg': lat['avg'], 'lat_p50': lat['p50'], 'lat_p95': lat['p95'],
        'lat_p99': lat['p99'], 'lat_max': lat['max'],
        'tracker_cpu_avg': tl['avg_cpu_window'] if tl else None,
        'tracker_cpu_max': tl['max_cpu_window'] if tl else None,
        'tracker_load1_avg': tl['avg_load1_window'] if tl else None,
        'tracker_load1_max': tl['max_load1_window'] if tl else None,
        'gen_cpu_avg': gl['avg_cpu_window'] if gl else None,
        'gen_cpu_max': gl['max_cpu_window'] if gl else None,
    }
    if r:
        row.update({
            'eids_missing': r['eids_missing_in_db'],
            'eids_missing_pct': round(100 * r['eids_missing_in_db'] / sent, 2) if sent else None,
            'eids_dupes': r['eids_found_more_than_once'],
            'cid_missing': r['ok_cids_missing_global'],
            'pass_strict': r['stage_pass_per_methodology'],
        })
    if 'recovery' in d:
        rb = load(d, 'recovery_buckets.json')
        if rb:
            row['recovery_buckets'] = rb['buckets']
    return row


rows = [stage_row(os.path.basename(p)) for p in sorted(glob.glob(f'{LOGS}/*'))
        if '_s' in os.path.basename(p) or '_recovery' in os.path.basename(p)]
rows = [r for r in rows if r]

# group: ladder / clean-base repeats / recovery
def is_recovery(r): return 'recovery' in r['dir']

summary = {'stages': rows, 'recovery': [r for r in rows if is_recovery(r)],
           'runs': [r for r in rows if not is_recovery(r)]}
json.dump(summary, open(f'{RES}/summary.json', 'w'), indent=2, ensure_ascii=False)

print(f"wrote {RES}/summary.json with {len(rows)} entries")
