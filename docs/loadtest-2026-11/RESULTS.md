# Load test 16–18.11.2026 (CPA.RIP methodology): 1.9.4 → 1.9.6, results summary

Test stand: VPS 4 vCPU / 10 GB, Ubuntu 24.04, the provider's network disk
(`fdatasync` p50 8.5 ms / p99 28 ms / max 92 ms — see `DIAG.md`). A k6
generator on a neighboring VPS in the same datacenter. Methodology and the
reusable script kit: [`tests/load/cpa-rip/`](../../tests/load/cpa-rip/)
(3-minute stages, 30 s ramp-up, 5 s timeout, every `event_id` verified against
the database, best stage repeated ×3 on a clean database).

*(Русская версия: `RESULTS-RU.md`.)*

This is a numbers-only summary. The complete machine data of every run sits
next to it **locally** (not in git, the directory is ignored): `results.tar.gz`
(baseline, 196 MB), `results_x.tar.gz` (experiment, 294 MB), `results_a.tar.gz`
(acceptance, 480 MB); each contains `summary.md`, `summary.json`, `env*.md`
and the raw per-stage data. Server-agent prompts and the working spec are not
committed.

## Run 0 — baseline: Orbitra 1.9.4 as installed (`results/summary.md`)

| Profile | Best passed stage | Overload |
|---|---|---|
| Uniques | **320 rps** (non-deterministic; 480 — a deterministic failure: 3501×HTTP 500 + 72 t/o) | `worker_connections 768` exhaustion → HTTP 500/fastcgi; tracker CPU up to 97% |
| Repeats (pool of 10 000) | **160 rps** | 320 rps: 9 t/o, p99 4326 ms |
| repeat-tight (pool of 50), metric (б) | 480 rps (the whole ladder) | — |

p99@160 uniques — **1294.9 ms**; p99@80 repeats — 319.3 ms. One click without
load: p50 24 ms / p99 66 ms, `fdatasync` 4.0 per click (the diagnosis in
`DIAG.md`: 4 disk synchronizations per click — opening/closing an SQLite
connection on every request).

## Run x — experiment: 1.9.4 + two patches (persistent PDO + worker_connections 4096) (`results_x/summary.md`)

| Profile | Best passed stage | Overload |
|---|---|---|
| Uniques | **480 rps** (3 valid passes on a clean database), 640/800 outside the methodology — no errors | none recorded; no new nginx alerts, CPU max 55.65% |
| Repeats | **320 rps** (480 — 17 t/o out of 86 357 without overload signs, cid_missing=0) | none recorded |

p99@160 uniques — **147.8 ms**; xu1b@480 clean database: p50 3.7 / p99 81.0 ms.

## Acceptance a — the 1.9.6 rc1 candidate (`results_a/summary.md`)

Acceptance thresholds — the facts:

| Threshold | Target | Fact | Verdict |
|---|---|---|---|
| p99 at 160 rps, uniques | ≤ 50 ms | au1 s160: **382.1 ms** | NOT MET |
| p99 at 480 rps, uniques | ≤ 100 ms | au1: **167.4**; au2: **3378.2**; au3: 635.2 | NOT MET |
| uniques 480 ×3 and 800 | 0 errors | 480×3: **0/0/0** ✓; 800: overload | 480 ✓; 800 ✗ |
| repeats 480 ×3 | 0 errors, cids in the database | 1 / 0 / 0 t/o; cid_missing=0 | partial |
| debounce=0, repeats 320 | (a) = 100% | **100.00%** (57 584/57 584 eid found exactly once) | MET |

End-to-end table p50/p99 (ms) \| errors \| tracker CPU — uniques:

| Stage | 1.9.4 baseline | x experiment | 1.9.6 rc1 |
|---|---|---|---|
| 20 | 44.1/219.8 \| 0 \| 5.95% | 5.4/31.8 \| 0 | 6.1/8.8 \| 0 \| 3.3% |
| 160 | 242.3/1294.9 \| 0 | 4.8/147.8 \| 0 | 4.9/382.1 \| 0 \| 19.1% |
| 320 | 1290.3/5000.7 \| 1739 (FAIL) | 4.0/133.3 \| 0 | 4.5/3427.9 \| 0 \| 29.4% |
| 480 | 13.9/4628.4 \| 3671 (FAIL) | 3.7/81.0 \| 0 | 4.0/167.4 \| 0 \| 38.3% |
| 640 | — | 3.5/63.6 \| 0 | 3.9/185.4 \| 3 |
| 800 | — | 3.7/109.9 \| 0 | overload / 4.3/3732.3 \| 2 |

WAL of rc1 (peak per stage): 20–40 rps ≈ 42–49 MB; 80 — 335 MB; 160 —
0.7–1 GB; 320 — 1–3 GB; 480 — 2.5–4.2 GB; 640 — 6.0 GB. A 12.8-minute soak
@480: saw-tooth up to 3.64 GB, **4891 t/o + 996×502, p95 2185.8 ms** — the
errors fall exactly into the WAL-reset windows.

## Bring-up rounds (rc2 → release) — from the acceptance log

1. **rc1 rejected**: the WAL grows to gigabytes under constant readers (PASSIVE
   never restarts the log; a rare TRUNCATE with a 30-second wait stalled the
   writers — a reset from 3.47 GB produced 4187 errors in 10 s).
2. **rc2**: a background RESTART with a 250 ms wait — the soak pair showed:
   with the wait on the web path 23 281 errors ≈ 23 362 fuse firings
   one-to-one (p95 3288 ms, WAL saw-tooth up to 330 MB); without it 0 errors
   (p95 136 ms) but the WAL grows ~550 MB/min. Conclusion: web requests never
   wait, the background does a zero-wait RESTART plus a forced one (1 s) once
   a minute past 1 GB.
3. **rc2+**: regular traffic at 100 rps — successful restarts blocked writes
   for up to 2 s (17–22/min, p95 840 ms) → the opportunistic RESTART only
   past 64 MB and at most once per 30 s.
4. **The final check** (15 min, uniques 480 rps) passed by the operator;
   **v1.9.6 released on 18.11.2026** (tag `v1.9.6`).

One click on rc1: p50 3.9 ms / p99 7.1 ms; `fdatasync` in the php-fpm
workers — **0** per click (all the synchronization lives in the background
`click_spool_cron`).
