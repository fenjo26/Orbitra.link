# DIAG — long WAL readers / WAL growth under sustained load (ТЗ 1.9.7) — stand runbook

> **Note (v2.0.2):** the acceptance gates below belong to the round-4 scheme
> and are kept as the investigation record. The shipped policy has moved on
> (mon201/mon202 — see the header comment in `core/wal_policy.php`): under
> load the WAL is allowed to grow into gigabytes by design, and the forced
> fold keys on free-space share, not a byte cap — a run judged against the
> "WAL ≤ 512 MB" gate would now fail a healthy system. The diagnostic tools
> this runbook drives (`cli/diag/wal_watch.php`, `cli/diag/shm_locks.php`)
> and the round-5 tracing live on the `archive/long-reads-round4` tag, not
> in the release tree.

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

**Round 4 (the current scheme — rounds 1–3 history in git).** The 480×15
acceptance of round 3 failed: a RESTART copied a gigabyte tail under WRITER
(10–13 s holds), and a long checkpoint aged the 10 s heartbeat so the web
fuse re-armed mid-run (round-1 loop). Both fixed:
- RESTART (opportunistic AND forced) runs ONLY when the PASSIVE of the same
  pass left log == checkpointed or a tail < 4 MB (WAL_RESTART_TAIL_MAX);
  otherwise the pass only PASSIVEs. No big copies under the WRITER lock.
- The heartbeat is written before/after EVERY checkpoint call and between
  passes (in_checkpoint_since shows the in-flight call); the web fuse
  engages only at 120 s of heartbeat age (orbitraWalPulseStaleSeconds).
- Slow-disk local gate (required before any stand run): fsync 9–11 ms +
  ~83 MB/s write cap injected into the web tier and the cron via
  DYLD_INTERPOSE (libslowfsync.dylib), 630 s at ≥ 21 MB/s. Round-4 lab
  result: 21.7 MB/s, 256768/256768 302, 0 timeouts, p50 3.7 / p99 147 ms,
  WAL max 154 MB, hb_age max 4.9 s, max_block_ms 140, CHECKPOINTER byte
  only ever cron-spool, max single-holder WRITER hold 200 ms.

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

## Round 5 (1.9.8 investigation): ORBITRA_WAL_TRACE — per-call checkpoint + slow-click tracing

Env `ORBITRA_WAL_TRACE=1` (default off, no logic depends on it):

- The spool cron appends ONE CSV line per checkpoint call AND per pass to
  `var/logs/wal_trace.log`:
  `ts_start_us,mode,duration_ms,busy,log,checkpointed,wal_bytes_before,wal_bytes_after,wait_ms,pass_duration_ms`
  (mode = PASSIVE | RESTART | forced; wait_ms = how late past the planned
  pass start the call began — lock/busy waiting happens INSIDE duration_ms;
  the PASSIVE line is written last so pass_duration_ms covers everything the
  pass did, including any RESTART of the same pass).
- Web: a click-writing request slower than 500 ms appends one line to
  `var/logs/slow_click.log`: `ts_us,pid,total_ms,insert_ms`
  (insert_ms = the click INSERT/commit time from orbitraPersistClick).

Enable on the stand by adding `env[ORBITRA_WAL_TRACE] = 1` to the php-fpm
pool (reload) and `ORBITRA_WAL_TRACE=1` on the click_spool_cron crontab
line (cron reload); both files are append-only CSV — collect with the rest
of /root/dt197.
