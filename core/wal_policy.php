<?php
// core/wal_policy.php
//
// Pure decision helpers for the WAL checkpoint loop (DIAG_STEP1_FAIL →
// mon198 → mon201, 2026-11-22). What the stands taught, in order:
//
//   mon198 (clean A/B): during load the writes are almost pure WAL appends
//   and the transfer is deferred — a 5.9 GB WAL folded in ~50 s AFTER the
//   load with zero block. The only tail-ruiner was the FIXED 1 GB forced
//   threshold (a ~3 s writer pause for no safety gain on a roomy disk).
//
//   mon201 (480 rps × 15 min, ce57989): 14 minutes perfect, then ONE ~10 s
//   pause at WAL 8.09 GB — a successful OPPORTUNISTIC zero-wait RESTART
//   (3.6 s blocking copy) followed by the 8 GB → 64 MB journal_size_limit
//   truncation landing INSIDE the first click to commit after the restart
//   (several seconds of near-zero writes on iostat = the ftruncate).
//   The 8 GB cap put a "fold the big WAL" trigger right under load; the
//   fold itself is fine AFTER the load, catastrophic during it.
//
// The three tiers after mon201:
//
//   1. OPPORTUNISTIC RESTART (zero wait, past 64 MB, ≥ 30 s apart) — only
//      while the WAL is ≤ 1 GB: cheap restarts, exactly the 1.9.6 behavior
//      that keeps the log small at moderate traffic. Above 1 GB a restart
//      stops being cheap, so the tier stands down.
//
//   2. QUIET FOLD — a WAL past 1 GB folds only at a QUIET moment: the log
//      grew less than 2 MB/s ON AVERAGE over the last ≥ 10 s (mon202: the
//      old "< 2 MB per 10 s" needed a near-dead log — at background
//      50–100 rps the journal would not have compacted until night; the
//      mon201 post-load observation: 8.6 GB folded in ~1 min, no pause).
//      Then a RESTART with a short bounded wait. TRUNCATE follows only when
//      the log is nearly still (< 0.2 MB/s) — the only place the -wal file
//      is ever shrunk, off the click path. A merely-slow log keeps the big
//      file: it is reused from the start anyway (a wrap needs no extra
//      space), and an ftruncate under live writers is the mon201 stall.
//
//   3. FORCED (disk-space safety only, 1 s wait, ≤ 1/min): WAL > 50% of
//      the free space OR the partition has < 2 GB free. No byte cap — the
//      8 GB cap is what pushed a big-WAL fold under load in mon201 — and
//      50%, not less, because folding a WAL needs almost no extra space
//      (frames move into the already-allocated db file): at 25% the mon201
//      stand arithmetic (61 GB free) would have forced a mid-load fold of
//      a ~12 GB WAL at the 11th minute of the run. On a roomy disk the WAL
//      simply grows through the load and the quiet fold collects it after.
//
// The web side never truncates: config.php sets journal_size_limit = -1 on
// web connections (mon201: the first writer's truncation is a click-path
// stall); the cron's TRUNCATE owns the shrink. ORBITRA_WAL_FORCED_BYTES
// (test hook) still forces the legacy fixed-threshold behavior.

/**
 * Tier 1 gate: is the opportunistic zero-wait RESTART allowed at this size?
 * Cheap while the log is small; stands down once a restart stops being cheap.
 */
function orbitraWalOpportunisticAllowed(int $walBytes, int $maxBytes = 1073741824): bool
{
    return $walBytes <= $maxBytes;
}

/**
 * The growth RATE of the log across the sample window, in bytes per second,
 * or null when the window is too fresh to say anything (a tick that just
 * started mid-burst must not look like drained traffic).
 *
 * @param array<array{0:float,1:int}> $samples ordered [timestamp, wal_bytes]
 *        samples, oldest first, one per checkpoint pass
 */
function orbitraWalSampleWindowRate(array $samples, float $now, float $windowSeconds = 10.0): ?float
{
    if (!$samples) {
        return null;
    }
    $oldestT = $samples[0][0];
    if (($now - $oldestT) < $windowSeconds * 0.8) {
        return null;
    }
    $span = $now - $oldestT;
    if ($span <= 0.0) {
        return null;
    }
    return ($samples[count($samples) - 1][1] - $samples[0][1]) / $span;
}

/**
 * Tier 2 gate: is NOW a quiet moment to fold a big WAL? True when the log
 * grew less than $maxRateBytesPerSec ON AVERAGE across a window of at least
 * $windowSeconds (mon202: a rate, not a fixed byte budget — background
 * 50–100 rps must still fold, only real load may not).
 */
function orbitraWalQuietMoment(array $samples, float $now, float $windowSeconds = 10.0, float $maxRateBytesPerSec = 2097152.0): bool
{
    $rate = orbitraWalSampleWindowRate($samples, $now, $windowSeconds);
    return $rate !== null && $rate < $maxRateBytesPerSec;
}

/**
 * May the quiet fold also TRUNCATE the file? Only when the log is nearly
 * still (< 0.2 MB/s average): a merely-slow log keeps the big file — it is
 * reused from the start (a wrap costs no space), while an ftruncate under
 * live writers is exactly the mon201 click-path stall.
 */
function orbitraWalTruncateAllowed(array $samples, float $now, float $windowSeconds = 10.0, float $maxRateBytesPerSec = 209715.2): bool
{
    $rate = orbitraWalSampleWindowRate($samples, $now, $windowSeconds);
    return $rate !== null && $rate < $maxRateBytesPerSec;
}

/**
 * Tier 3 gate: should the forced RESTART engage? FREE SPACE only, no byte
 * cap (mon201): the cap put the fold under load; space is the actual danger.
 * The default share is 50% (mon202) — folding a WAL needs almost no extra
 * space, so a smaller share would fire under load on a roomy disk.
 *
 * @param int|null $diskFreeBytes free space of the db's partition (null =
 *        unknown — treat as "plenty"; the opportunistic/quiet tiers run)
 */
function orbitraWalForcedSpaceWanted(int $walBytes, ?int $diskFreeBytes, float $ratio = 0.50, int $floorBytes = 2147483648): bool
{
    if ($diskFreeBytes === null || $diskFreeBytes < 0) {
        return false;
    }
    if ($diskFreeBytes < $floorBytes) {
        return true;
    }
    return $walBytes > (int) floor($diskFreeBytes * $ratio);
}

/**
 * The System-status warning level: WAL has grown past this share of the
 * free space. 10% — informational, the operator still has 2.5× headroom
 * before the forced tier would even engage.
 */
function orbitraWalSpaceWarned(int $walBytes, ?int $diskFreeBytes, float $ratio = 0.10): bool
{
    if ($diskFreeBytes === null || $diskFreeBytes <= 0) {
        return false;
    }
    return $walBytes > (int) floor($diskFreeBytes * $ratio);
}
