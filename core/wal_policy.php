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
//      grew less than 2 MB over the last 10 s (traffic has drained; the
//      mon201 post-load observation: 8.6 GB folded in ~1 min, no pause).
//      Then a RESTART with a short bounded wait followed by TRUNCATE — the
//      only place the -wal file is ever shrunk, off the click path.
//
//   3. FORCED (disk-space safety only, 1 s wait, ≤ 1/min): WAL > 25% of
//      the free space OR the partition has < 2 GB free. No byte cap — the
//      8 GB cap is what pushed a big-WAL fold under load in mon201. On a
//      roomy disk the WAL simply grows through the load and the quiet fold
//      collects it afterwards.
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
 * Tier 2 gate: is NOW a quiet moment to fold a big WAL? True when the log
 * grew less than $maxGrowthBytes across a sample window of at least
 * $windowSeconds (a freshly started tick has no window yet — never quiet).
 *
 * @param array<array{0:float,1:int}> $samples ordered [timestamp, wal_bytes]
 *        samples, oldest first, one per checkpoint pass
 */
function orbitraWalQuietMoment(array $samples, float $now, float $windowSeconds = 10.0, int $maxGrowthBytes = 2097152): bool
{
    if (!$samples) {
        return false;
    }
    $oldestT = $samples[0][0];
    $oldestSize = $samples[0][1];
    // A window shorter than requested says nothing about "the traffic has
    // drained" — the tick may have just started mid-burst.
    if (($now - $oldestT) < $windowSeconds * 0.8) {
        return false;
    }
    $newestSize = $samples[count($samples) - 1][1];
    return ($newestSize - $oldestSize) < $maxGrowthBytes;
}

/**
 * Tier 3 gate: should the forced RESTART engage? FREE SPACE only, no byte
 * cap (mon201): the cap put the fold under load; space is the actual danger.
 *
 * @param int|null $diskFreeBytes free space of the db's partition (null =
 *        unknown — treat as "plenty"; the opportunistic/quiet tiers run)
 */
function orbitraWalForcedSpaceWanted(int $walBytes, ?int $diskFreeBytes, float $ratio = 0.25, int $floorBytes = 2147483648): bool
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
