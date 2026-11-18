# DIAG — long WAL readers / WAL growth under sustained load (ТЗ 1.9.7) — stand runbook

Executor: the tracker-side agent on the load stand. Everything here is
measurement only; no production behavior changes unless a step says so
explicitly (the one switch used, `ORBITRA_WEB_AUTOCHECKPOINT`, defaults to the
shipped value and must be reverted after the A/B).

## Problem being investigated

On the stand (VPS 4 vCPU, network disk, `fdatasync` p50 8.5 ms / p99 28 ms)
under SUSTAINED load the WAL grows ~1 GB/min and essentially never folds while
the load runs (480 rps × 15 min → 15.6 GB; the forced RESTART succeeded once
in 15 minutes; `restarts_ok = 0` in every heartbeat window of the rc3 runs,
even at 100 rps). At 40 rps the WAL is stable at ~16 MB.

The stand serves traffic as **nginx → index.php directly** (no router.php, no
click.php on the hot path).

## Working mechanism (confirmed from source + rc3 data)

1. `wal_autocheckpoint = 10000` pages is set on WEB connections
   (config.php, TZ_LOAD_PERFORMANCE_2 stage 2) as a "fuse" for installs whose
   cron is dead.
2. SQLite's auto-checkpoint hook (`sqlite3WalDefaultHook`, src/main.c 3.45.1)
   fires **on every commit of every connection while the TOTAL WAL size is
   `>= wal_autocheckpoint` frames** — it compares the total log length, NOT
   the un-checkpointed backlog:
   ```c
   int sqlite3WalDefaultHook(..., int nFrame /* Size of WAL */){
     if( nFrame>=SQLITE_PTR_TO_INT(pClientData) ){
       sqlite3_wal_checkpoint(db, zDb);   /* PASSIVE, inline */
     }
   }
   ```
3. Under sustained load the background RESTART cannot fold the log (it needs
   the checkpointer lock plus a reader-free, writer-free instant), so the
   total size stays past 10000 frames (~40 MB) — and from that moment **every
   click INSERT in every php-fpm worker runs an inline PASSIVE checkpoint**
   (acquire the CHECKPOINTER lock, copy the accumulated backlog, fsync the
   main DB) inside the web request.
4. That saturates the CHECKPOINTER lock — the very lock the background RESTART
   needs — so the log never folds, so the fuse keeps firing: a self-sustaining
   loop.

Supporting rc3 evidence (already collected, in `docs/loadtest-2026-11/`):
- an external PASSIVE probe found the checkpointer lock BUSY in 38/53 samples
  (72%) at 480 rps and 32/61 (52%) at 100 rps;
- whenever the probe DID get the lock, the copy completed fully
  (`log == checkpointed`) — readers are NOT pinning the backfill;
- `restarts_ok = 0` throughout, while natural folds happen only in the gaps.

## Instruments (shipped in this branch, all OFF by default)

- `cli/diag/wal_watch.php` — samples `-wal` size + `PRAGMA wal_checkpoint
  (PASSIVE)` (busy / log / checkpointed → backfill lag) + a zero-wait FULL
  probe (its success rate ≈ the reader-free fraction). Does not rewind the
  log, safe to run against the live system.
- `cli/diag/shm_locks.php` — fcntl(F_GETLK) census of the `-shm` lock bytes
  at N Hz with the holder PID **and process type** per byte:
  `ts, writer, wtype, ckpt, ctype, readers, n, other`
  (byte 120 = WRITER, 121 = CHECKPOINTER, 124..127 = reader marks,
  123 = read-mark-0, 128 = the per-connection DMS lock — held by every live
  connection, NOT a reader; the layout was calibrated empirically).
  The `ctype` column is the direct test of the mechanism above: if the
  CHECKPOINTER byte is held mostly by `php-fpm` processes, the web
  auto-checkpoint fuse is the driver; if by `cron-spool`, the background
  copies themselves are.
- `ORBITRA_DB_TRACE` (config.php) — per-statement read-hold spans with
  `file:line` attribution; mode 1 keeps the persistent handle
  (connection-level events), mode 2 forces non-persistent so statements can
  be decorated. Diagnosis only; never leave enabled.

## Phase A — baseline (no changes), 480 rps

1. Deploy this branch to the tracker (git fetch + checkout
   `tz-1.9.7-long-reads`). No env vars set — behavior is identical to 1.9.6
   plus the two diagnostics scripts.
2. Start the observers on the tracker (as a user that can write the DB
   directory — root):
   ```
   mkdir -p /root/dt197
   php /var/www/orbitra/cli/diag/wal_watch.php \
       --db=/var/www/orbitra/orbitra_db.sqlite --hz=5 --seconds=300 \
       --out=/root/dt197/a_walwatch.csv &
   php /var/www/orbitra/cli/diag/shm_locks.php \
       --shm=/var/www/orbitra/orbitra_db.sqlite-shm --hz=20 --seconds=300 \
       --out=/root/dt197/a_census.csv &
   ```
3. From the generator, one 480 rps stage of the standard kit
   (`run_stage.sh <run> unique 480 480`), clean database, `click_debounce_seconds`
   as configured (do NOT change settings between A and B).
4. Collect after the run:
   - `ctype` histogram over the loaded window (php-fpm vs cron-spool share of
     the CHECKPOINTER byte);
   - WRITER-byte occupancy %; reader-mark coverage % (bytes 124–127);
   - wal_watch: backfill lag, FULL-ok %, WAL trajectory (max, growth rate);
   - the kit's usual stage_stats / recon; the cron heartbeat
     (`var/cache/wal_checkpoint.json`): restarts_ok / busy / forced /
     natural, max_block_ms.

Expected if the mechanism holds: ckpt byte held mostly by `php-fpm`, lag small,
WAL grows ~linearly, restarts_ok ≈ 0.

## Phase B — A/B: disable the web auto-checkpoint fuse

1. In `/etc/php/8.5/fpm/pool.d/www.conf` add:
   ```
   env[ORBITRA_WEB_AUTOCHECKPOINT] = 0
   ```
   then `systemctl reload php8.5-fpm`.
2. RESET the database (standard kit RESET), rerun the SAME 480 rps stage with
   the same two observers (`b_walwatch.csv`, `b_census.csv`).
3. Collect the same numbers. Decision criteria:
   - `ctype` now mostly `cron-spool` (the background loop owns checkpointing
     again);
   - `restarts_ok` / natural restarts > 0 during load;
   - WAL max bounded (target: folds back under 64 MB–1 GB repeatedly instead
     of monotonic growth to multi-GB);
   - p95/p99 NOT worse (fewer inline fsyncs in requests should improve tails);
   - zero errors, event_id verification (kit metrics (а)/(б)) unchanged.
4. If promising: repeat the 15-minute soak @480 the same way (fuse off) and
   compare against the rc3 soak (15.6 GB, forced once).
5. REVERT: remove the `env[...]` line, `systemctl reload php8.5-fpm`, RESET.

## Phase C (optional, short) — attribution of slow statements on this disk

1. In the pool add `env[ORBITRA_DB_TRACE] = /var/www/orbitra/var/diag/dbtrace`
   and `env[ORBITRA_DB_TRACE_MIN_MS] = 5`, reload, run 30–60 s of 480 rps,
   then REMOVE both lines and reload. Collect
   `/var/www/orbitra/var/diag/dbtrace/fpm-fcgi-*.jsonl`.
2. One-shot traced cron runs under load (CLI statements are always decorated):
   ```
   ORBITRA_DB_TRACE=2 ORBITRA_DB_TRACE_MIN_MS=0 \
     sudo -u www-data php /var/www/orbitra/rotation_optimiser_cron.php --quiet
   ```
   (same for postback_queue_cron.php, conversion_monitor_cron.php) — any
   `held_us > 1e6` record is a >1 s reader, exactly what the ТЗ asks to find.

## Reporting back

Numbers only, per phase: the four census/wal_watch aggregates, the heartbeat
counters, the kit stage stats, and the trace summaries. Raw CSVs/JSONL stay on
the tracker under /root/dt197 (tar them up; they are small).
