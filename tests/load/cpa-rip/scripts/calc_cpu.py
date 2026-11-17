#!/usr/bin/env python3
"""Compute CPU% from /proc/stat samples and loadavg stats.

Usage: calc_cpu.py <csv> <w_start_epoch> <w_end_epoch> [ts_column]
CSV format: ts[,remote_ts],cpu_line,loadavg   (ts_column: 1=local, 2=remote_ts)
CPU% = 100*(1 - idle_delta/total_delta) between consecutive samples.
Output JSON: avg/max CPU overall and inside window, loadavg stats, per-sample list.
"""
import json
import sys


def cpu_pct(line1, line2):
    f1 = list(map(float, line1.split()[1:]))
    f2 = list(map(float, line2.split()[1:]))
    d = [b - a for a, b in zip(f1, f2)]
    total = sum(d)
    idle = d[3] + d[4]  # idle + iowait
    if total <= 0:
        return None
    return 100.0 * (1 - idle / total)


def main():
    path = sys.argv[1]
    ws, we = float(sys.argv[2]), float(sys.argv[3])
    ts_col = int(sys.argv[4]) if len(sys.argv) > 4 else 1

    rows = []  # (ts, cpu_line, load1)
    with open(path) as f:
        next(f)  # header
        for line in f:
            parts = line.rstrip('\n').split(',')
            ts = float(parts[ts_col - 1])
            cpu_line = parts[ts_col]
            load1 = float(parts[ts_col + 1].split()[0])
            rows.append((ts, cpu_line, load1))

    samples = []
    win_vals, all_vals = [], []
    for i in range(1, len(rows)):
        prev, cur = rows[i - 1], rows[i]
        c = cpu_pct(prev[1], cur[1])
        if c is None:
            continue
        mid = (prev[0] + cur[0]) / 2
        samples.append({'ts': round(cur[0], 2), 'cpu': round(c, 2), 'load1': cur[2]})
        all_vals.append(c)
        if ws <= mid < we:
            win_vals.append(c)

    win_loads = [s['load1'] for s in samples if ws <= s['ts'] < we]

    out = {
        'samples': samples,
        'avg_cpu_all': round(sum(all_vals) / len(all_vals), 2) if all_vals else None,
        'max_cpu_all': round(max(all_vals), 2) if all_vals else None,
        'avg_cpu_window': round(sum(win_vals) / len(win_vals), 2) if win_vals else None,
        'max_cpu_window': round(max(win_vals), 2) if win_vals else None,
        'avg_load1_window': round(sum(win_loads) / len(win_loads), 3) if win_loads else None,
        'max_load1_window': round(max(win_loads), 3) if win_loads else None,
    }
    print(json.dumps(out, indent=2))


if __name__ == '__main__':
    main()
