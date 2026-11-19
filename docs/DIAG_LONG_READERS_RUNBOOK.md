# DIAG — long WAL readers / WAL growth under sustained load (ТЗ 1.9.7) — stand runbook

Executor: the tracker-side agent on the load stand. Rounds 1–2 are DONE
(findings below); round 3 is the acceptance of the shipped scheme.

## Status of the investigation

**Round 1 (mechanism, confirmed).** `wal_autocheckpoint = 10000` on web
connections + sqlite's `sqlite3WalDefaultHook` (src/main.c 3.45.1) firing on
EVERY commit while the TOTAL WAL size is over the threshold ⇒ under sustained
load every click INSERT ran an inline PASSIVE checkpoint (checkpointer lock +
DB fsync) inside the request. Stand evidence: checkpointer byte held by
php-fpm, WAL ~21 MB/s → 6.8 GB in 5 min, `restarts_ok = 0`; backfill always
complete when probed (readers never pinned it).

**Round 2 (A/B, DIAG_CKPT_B).** `ORBITRA_WEB_AUTOCHECKPOINT=0`: php-fpm never
took the CKPT byte, cron-spool held it ~90%, WAL sawtooth 0.2–1.2 GB,
`restarts_ok` 1–3/min — the growth cause is closed. New problem exposed: the
rare-and-large RESTART (30 s gate, zero wait) let the log grow ~600 MB+
between attempts, and landing it then blocked writers up to 5.6 s
(max_block_ms), 592 timeouts, p99 4.2 s.

**Round 3 (the fix shipped in this branch).**
- Web/CLI connections run with NO auto-checkpoint while the spool worker's
  heartbeat is fresh (≤ 10 s, refreshed every checkpoint pass); a stale
  heartbeat brings the 10000-page fuse back (dead-cron installs). Policy in
  core/wal_policy.php + tests; `ORBITRA_WEB_AUTOCHECKPOINT` still overrides.
- The checkpoint loop is frequent-and-small: PASSIVE every 2 s (bounds the
  backlog to ~one pass of traffic), then a RESTART attempt on EVERY pass
  while the WAL is past 64 MB, with a 100 ms busy wait. The forced tier
  (1 GB, 1 s wait, once a minute) stays as the backstop. `max_block_ms` now
  accounts every RESTART attempt.
- Local verification (wal_restart_under_readers_test, ~580 rps + 8 constant
  readers): restarts ok=39/busy=0 over 70 s, WAL max 57 MB, slowest click
  25 ms.

## Micro-bench first (5 minutes, no load needed): truncate cost on THIS disk

The 5.6 s block is dominated by copying/folding a gigabyte-scale log. Measure
what a truncate itself costs here (feeds the journal_size_limit decision —
shipped: 64 MB):

```bash
php -r '
$f = "/root/trunc_test.bin";
$fh = fopen($f, "wb");
$chunk = str_repeat("x", 1048576);
$t0 = microtime(true);
for ($i = 0; $i < 1200; $i++) fwrite($fh, $chunk);   // ~1.2 GB of real blocks
printf("write 1.2 GB: %.2f s\n", microtime(true) - $t0);
$t0 = microtime(true); ftruncate($fh, 64 * 1048576);  fflush($fh);
printf("ftruncate 1.2 GB -> 64 MB: %.2f s\n", microtime(true) - $t0);
$t0 = microtime(true); fwrite($fh, $chunk); fflush($fh);
printf("append+fflush after: %.3f s\n", microtime(true) - $t0);
fclose($fh); unlink($f);
' 2>&1
```
Repeat with 128 MB → 64 MB (first loop `$i < 128`). Report both. If the
1.2 GB truncate is ≫ the 128 MB one, the frequent-small scheme is what keeps
the truncate cheap (high-water stays ~64–110 MB); if even 128 → 64 is slow
(> 200 ms), we raise journal_size_limit in a follow-up.

## Acceptance runs (the numbers that close the ТЗ)

Observers for every run (wal_watch is now a PURE observer — it runs no
checkpoints):

```bash
mkdir -p /root/dt197
php /var/www/orbitra/cli/diag/wal_watch.php \
    --db=/var/www/orbitra/orbitra_db.sqlite --hz=5 --seconds=<run+60> \
    --out=/root/dt197/<tag>_walwatch.csv &
php /var/www/orbitra/cli/diag/shm_locks.php \
    --shm=/var/www/orbitra/orbitra_db.sqlite-shm --hz=20 --seconds=<run+60> \
    --out=/root/dt197/<tag>_census.csv &
```

1. **480 rps × 15 min** (soak, the main gate):
   - 0 errors;
   - WAL ≤ 512 MB at every sample;
   - p99 ≤ 200 ms;
   - pulse (`var/cache/wal_checkpoint.json` + the wal_watch hb_* columns):
     restarts_ok grows steadily (frequent-small), forced_restarts ≈ 0–2,
     `max_block_ms` ≤ ~200 ms.
2. **480 rps ×3 × 3 min** (CPA.RIP methodology, clean DB each): like 1.9.6 —
   0 errors, event_id verification (а)/(б) 100%, report p50/p95/p99.
3. **100 rps × 10 min**: 0 errors, p99 ≤ 100 ms, WAL bounded.
4. After each run: RESET. No env vars needed — the scheme is the default now.
   (Optional extra datum: one 480×3 with `env[ORBITRA_WEB_AUTOCHECKPOINT]=10000`
   to show the old behavior is still reachable via the override — NOT part of
   acceptance.)

Watch live during run 1: `ckpt` census column should be ~always `cron-spool`,
never php-fpm; `writer` holds short; wal_watch `lag_mb` small except spikes
right before a fold; `hb_age_s` ≤ ~7 continuously (the web fuse must stay
disengaged).

## If run 1 misses p99 ≤ 200 ms

The tunables (all env, no redeploy): `ORBITRA_WAL_RESTART_BYTES` (gate, def.
64 MB), `ORBITRA_WAL_RESTART_SECS` (min seconds between attempts, def. 0 =
every pass), `ORBITRA_WAL_FORCED_BYTES` (def. 1 GB) — set them on the
click_spool_cron line in root's crontab (prefix env) or via
`putenv`-wrapper, then `service cron reload`. If blocks are still too long at
~110 MB high-water, raise the gate is wrong direction — instead LOWER
`ORBITRA_WAL_RESTART_BYTES` (fold sooner) and/or shrink the PASSIVE interval
(report; that one needs a code change).

## Reporting back

Numbers only, per run: the four wal_watch aggregates (max wal, max lag,
restarts_ok growth, max_block_ms via hb), census ctype/writer occupancy, the
kit stage stats, and the two micro-bench truncate numbers. Raw CSVs stay on
the tracker under /root/dt197 (tar them; they are small).
