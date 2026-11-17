#!/usr/bin/env bash
# Compute generator + tracker CPU stats for a stage window.
# Usage: post_cpu.sh <stage_dir> <ramp> <hold>
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
set -euo pipefail
DIR="$1"; RAMP="$2"; HOLD="$3"
WS=$(python3 -c "import json;print(json.load(open('$DIR/stage_stats.json'))['window']['start_epoch'])")
WE=$(python3 -c "import json;print(json.load(open('$DIR/stage_stats.json'))['window']['end_epoch'])")
python3 $ROOT/scripts/calc_cpu.py "$DIR/cpu_local.csv" "$WS" "$WE" 1 > "$DIR/cpu_local_stats.json"
python3 $ROOT/scripts/calc_cpu.py "$DIR/cpu_tracker.csv" "$WS" "$WE" 2 > "$DIR/cpu_tracker_stats.json"
echo "[post_cpu] generator avg/max cpu in window: $(jq -r '.avg_cpu_window, .max_cpu_window' "$DIR/cpu_local_stats.json" | paste -sd/ -)"
echo "[post_cpu] tracker avg/max cpu in window: $(jq -r '.avg_cpu_window, .max_cpu_window' "$DIR/cpu_tracker_stats.json" | paste -sd/ -)"
