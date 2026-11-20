<?php
// core/wal_policy.php
//
// Pure decision helpers for the WAL checkpoint loop (the load-stand
// follow-up to DIAG_STEP1_FAIL / mon198, 2026-11-22). The A/B showed the
// transfer cost of step 1 and v2.0.0 is identical during load (writes are
// almost pure WAL appends; the transfer is deferred and folds cleanly after
// the load) — the only tail-ruiner was the FIXED 1 GB forced-RESTART
// threshold: on a disk with hundreds of GB free it paused writers ~3 s for
// no safety gain. The forced tier now keys on FREE SPACE, the thing the
// threshold actually protects:
//
//   forced runs only when  WAL > 25% of the free space on the db partition
//                       OR the partition has < 2 GB free
//   otherwise the loop stays with PASSIVE + the opportunistic RESTART
//   (zero wait, past 64 MB, at most every 30 s) exactly as before.
//
// ORBITRA_WAL_FORCED_BYTES (test hook, unchanged from earlier rounds) still
// forces the old fixed-threshold behavior when set. Nothing else in the
// flush scheme changed.

/**
 * Should the forced RESTART tier engage for this WAL size?
 *
 * @param int $walBytes current -wal size
 * @param int|null $diskFreeBytes free space of the db's partition (null =
 *        unknown — treat as "plenty", i.e. no forced restart; the
 *        opportunistic tier still runs)
 * @param float $ratio WAL must exceed this share of the free space
 * @param int $floorBytes free space below this forces regardless of WAL size
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
 * free space. 10% per the stand follow-up — informational, the operator
 * still has 2.5× headroom before the forced tier would even engage.
 */
function orbitraWalSpaceWarned(int $walBytes, ?int $diskFreeBytes, float $ratio = 0.10): bool
{
    if ($diskFreeBytes === null || $diskFreeBytes <= 0) {
        return false;
    }
    return $walBytes > (int) floor($diskFreeBytes * $ratio);
}
