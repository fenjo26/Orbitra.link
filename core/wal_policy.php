<?php
// core/wal_policy.php
//
// WAL autocheckpoint policy (ТЗ 1.9.7). SQLite's auto-checkpoint hook
// (sqlite3WalDefaultHook, src/main.c) fires on EVERY commit of a connection
// while the TOTAL WAL size is over wal_autocheckpoint frames — not the
// un-checkpointed backlog. Under sustained load the log sits past any
// threshold for as long as a RESTART cannot fold it, so a threshold on web
// (or CLI) connections turns every click INSERT into an inline PASSIVE
// checkpoint: checkpointer lock + a DB fsync inside the request. On the load
// stand that saturated the checkpointer byte (php-fpm held it, WAL grew
// ~21 MB/s to 6.8 GB, restarts_ok = 0); with the auto-checkpoint off the
// background worker folded the log again (DIAG_CKPT_B, 2026-11-19).
//
// Policy: checkpointing belongs to cli/click_spool_cron.php. Everyone else
// runs WITHOUT an auto-checkpoint while the spool worker's heartbeat is
// fresh; when the heartbeat goes stale (cron dead — minutes, not one long
// checkpoint; see orbitraWalPulseStaleSeconds) the web fuse returns so a
// dead-cron install still folds its WAL eventually.
//
// Kept as pure functions so tests can cover the whole table.

/**
 * The autocheckpoint value a connection should use.
 *
 * @param int|null $pulseAgeSeconds age of var/cache/wal_checkpoint.json in
 *        seconds (null = the file does not exist yet: a fresh install before
 *        the first cron tick — treat as NOT fresh, the fuse must exist from
 *        the very first request)
 * @param bool $isCli PHP_SAPI === 'cli'
 * @param string $scriptBase basename of the running script (CLI only); the
 *        spool worker keeps SQLite's default — it is the one place an inline
 *        checkpoint is fine
 * @param int|null $envOverride ORBITRA_WEB_AUTOCHECKPOINT when valid
 *        (0 = disable outright; >0 = that value); test/A-B knob, wins over
 *        the pulse logic for web connections
 * @return int pragma value for wal_autocheckpoint
 */
function orbitraWalAutocheckpointPolicy(?int $pulseAgeSeconds, bool $isCli, string $scriptBase, ?int $envOverride = null): int
{
    if ($isCli) {
        // The spool worker owns checkpointing explicitly; every other CLI
        // script (the every-minute crons, one-shot tools) gets no inline
        // auto-checkpoint at all.
        return $scriptBase === 'click_spool_cron.php' ? 1000 : 0;
    }
    if ($envOverride !== null && $envOverride >= 0) {
        return $envOverride;
    }
    // Web: no auto-checkpoint while the background worker is alive (its
    // heartbeat is refreshed before/after every checkpoint call and every
    // pass); the 10000-page fuse returns only when the worker is REALLY
    // gone. The threshold must be far above one checkpoint's duration: on
    // the load stand a single checkpoint can run ~10 s, and a 10 s fuse
    // threshold flipped mid-run (hb aged to 58.7 s while the worker sat in
    // one long RESTART) — the web stampede returned and closed the round-1
    // loop (ТЗ 1.9.7 acceptance round). 120 s = two missed minutes.
    $fresh = $pulseAgeSeconds !== null && $pulseAgeSeconds <= orbitraWalPulseStaleSeconds();
    return $fresh ? 0 : 10000;
}

/**
 * How old the spool worker's heartbeat may be before the web-side
 * auto-checkpoint fuse engages, seconds. The worker refreshes the file
 * before and after every checkpoint call and on every pass (~2 s cadence,
 * ~5 s tick seam), so a live worker keeps it seconds-fresh even while a
 * long checkpoint is in flight (in_checkpoint_since shows the call). Only a
 * genuinely dead cron (killed, cron disabled) lets it age past this — the
 * acceptance round taught us the threshold must be minutes, not seconds.
 */
function orbitraWalPulseStaleSeconds(): int
{
    return 120;
}
