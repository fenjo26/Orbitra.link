#!/usr/bin/env python3
"""Reconcile stage response log against tracker DB (clicks).

Usage: verify_stage.py <stage_dir> <ramp_s> <hold_s>
Reads params.env, resp_log.jsonl.gz, stage_stats.json; writes recon.json.
Assumes DB_READ_CMD takes a SQL string as argument and prints TSV (sub1<TAB>id).
"""
import gzip
import json
import subprocess
import sys
from collections import Counter


def load_params():
    import os
    p = {}
    with open(os.environ.get('LT_PARAMS', os.path.join(ROOT, 'params.env'))) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            k, v = line.split('=', 1)
            p[k.strip()] = v.strip().strip('"').strip("'")
    return p


def main():
    stage_dir = sys.argv[1]
    ramp = float(sys.argv[2])
    hold = float(sys.argv[3])
    skip_cid_global = '--skip-cid-global' in sys.argv
    explicit_prefix = None
    if '--prefix' in sys.argv:
        explicit_prefix = sys.argv[sys.argv.index('--prefix') + 1] + '-'

    params = load_params()
    stats = json.load(open(f'{stage_dir}/stage_stats.json'))
    w = stats['window']

    base = stage_dir.rsplit('/', 1)[-1]
    if explicit_prefix:
        prefix = explicit_prefix
    else:
        run_part, rate = base.rsplit('_s', 1)
        # dir name is ${RUN}_${PROFILE}_s${RATE}; RUN/PROFILE must not contain '_'
        run = run_part.rsplit('_', 1)[0] if '_' in run_part else run_part
        prefix = f'{run}-s{rate}-'

    resp = []
    with gzip.open(f'{stage_dir}/resp_log.jsonl.gz', 'rt') as gz:
        for line in gz:
            r = json.loads(line)
            if w['start_epoch'] <= r['ts'] < w['end_epoch']:
                resp.append(r)

    ssh = ['ssh', '-i', os.environ.get('LT_SSH_KEY', os.path.join(ROOT, 'keys', 'lt_ed25519')), '-o', 'BatchMode=yes',
           '-o', 'ConnectTimeout=5', '-o', 'StrictHostKeyChecking=accept-new',
           '-o', 'ControlMaster=auto', '-o', 'ControlPath=' + os.environ.get('LT_SSH_CM', os.path.join(ROOT, '.ssh', 'cm-%r@%h-p-%p')),
           '-o', 'ControlPersist=10m',
           f"{params['SSH_USER']}@{params['TRACKER_IP']}"]

    def run_sql(sql, timeout=600):
        esc = (sql.replace('\\', '\\\\').replace('"', '\\"')
                  .replace('$', '\\$').replace('`', '\\`'))
        remote_cmd = params['DB_READ_CMD'].replace('<SQL>', '"' + esc + '"')
        return subprocess.run(ssh + [remote_cmd], capture_output=True, text=True,
                              timeout=timeout)

    sql = ("select json_extract(parameters_json,'$.sub1'), id from clicks "
           f"where campaign_id = {params['CAMPAIGN_ID']} "
           f"and json_extract(parameters_json,'$.sub1') like '{prefix}%'")
    out = run_sql(sql)
    if out.returncode != 0:
        print('DB dump FAILED rc=%d stderr=%s' % (out.returncode, out.stderr[:2000]), file=sys.stderr)
        sys.exit(3)

    db_rows = []
    for line in out.stdout.splitlines():
        parts = line.split('|')
        if len(parts) >= 2:
            db_rows.append((parts[0].strip(), parts[1].strip()))

    db_count = Counter(s for s, _ in db_rows)
    db_ids = {i for _, i in db_rows}

    sent_eids = [r['eid'] for r in resp]
    sent_set = set(sent_eids)
    missing = sorted(e for e in sent_set if db_count.get(e, 0) == 0)
    dupes = sorted(e for e in sent_set if db_count.get(e, 0) > 1)
    missing_by_result = Counter(r['result'] for r in resp if r['eid'] in set(missing))
    dupe_by_result = Counter(r['result'] for r in resp if r['eid'] in set(dupes))
    db_only = sorted(set(db_count) - sent_set)

    ok_cids = [r['cid'] for r in resp if r['result'] == 'ok']
    ok_cids_set = set(ok_cids)
    cids_missing = sorted(c for c in ok_cids_set if c not in db_ids)

    # global cid existence check (covers repeat-tight where cid may belong to earlier click)
    cid_global_missing = []
    if skip_cid_global:
        global_missing_note = 'skipped'
    else:
        need = sorted(c for c in ok_cids_set if c not in db_ids)
        sql2_tmpl = ("select count(*) from clicks where campaign_id = {cid} and id in ({ids})")
        CH = 500
        found_globally = set()
        for i in range(0, len(need), CH):
            chunk = need[i:i + CH]
            ids = ','.join("'{}'".format(c) for c in chunk)
            sql2 = sql2_tmpl.format(cid=params['CAMPAIGN_ID'], ids=ids)
            o2 = run_sql(sql2, timeout=120)
            try:
                cnt = int(o2.stdout.strip().split()[-1])
            except (ValueError, IndexError):
                cnt = 0
            if cnt >= len(chunk):
                found_globally.update(chunk)
        cid_global_missing = sorted(c for c in need if c not in found_globally)
        global_missing_note = None

    recon = {
        'prefix': prefix,
        'window_sent': len(resp),
        'db_rows_for_prefix': len(db_rows),
        'eids_missing_in_db': len(missing),
        'eids_missing_sample': missing[:20],
        'eids_missing_by_response_result': dict(missing_by_result),
        'eids_found_more_than_once': len(dupes),
        'eids_dupe_sample': dupes[:20],
        'eids_dupe_by_response_result': dict(dupe_by_result),
        'db_rows_not_in_response_log': len(db_only),
        'db_only_sample': db_only[:20],
        'ok_cids_total_unique': len(ok_cids_set),
        'ok_cids_not_in_prefix_rows': len(cids_missing),
        'ok_cids_missing_global': len(cid_global_missing),
        'ok_cids_missing_global_sample': cid_global_missing[:20],
        'stage_pass_per_methodology': (stats['errors']['total'] == 0 and len(missing) == 0 and len(dupes) == 0),
    }
    json.dump(recon, open(f'{stage_dir}/recon.json', 'w'), indent=2)
    print(json.dumps(recon, indent=2))


if __name__ == '__main__':
    main()
