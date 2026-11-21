<?php
/**
 * The three checkpoint tiers after mon201 (core/wal_policy.php + the cron
 * loop). mon201 (480 rps × 15 min, ce57989): 14 minutes perfect, then ONE
 * ~10 s pause — a successful OPPORTUNISTIC zero-wait RESTART at WAL
 * 8.09 GB (3.6 s blocking copy) followed by the journal_size_limit
 * truncation landing INSIDE the first click (forced_restarts was 0; the
 * 8 GB cap on the forced share is what put the big fold under load).
 *
 * Contract:
 *  A. pure decisions:
 *     - opportunistic allowed only while WAL ≤ 1 GB (cheap restarts, the
 *       1.9.6 behavior; stands down past it);
 *     - quiet moment = < 2 MB growth across a ≥ 10 s sample window; a
 *       fresh/short window is NEVER quiet; shrinking is quiet;
 *     - forced = WAL > 25% of free OR free < 2 GB, and NOTHING else — no
 *       byte cap (the 8 GB cap caused mon201's under-load fold);
 *     - warning at WAL > 10% of free.
 *
 *  B. the loop itself (real cron, faked readings): a WAL past the quiet
 *     threshold under fake LOAD keeps quiet_restarts at 0 and the heartbeat
 *     says quiet_pending; the same WAL with fake-drained traffic folds
 *     (quiet_restarts ≥ 1, the -wal file actually shrinks); ORBITRA_WAL_
 *     FORCED_BYTES keeps the legacy fixed threshold for the older tests.
 *
 * Usage: php tests/wal_forced_space_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/core/wal_policy.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

// --- A1. the opportunistic size gate -------------------------------------------
check(orbitraWalOpportunisticAllowed(70 * 1048576) === true, '70 MB WAL: the opportunistic tier runs (cheap restart)');
check(orbitraWalOpportunisticAllowed(1073741824) === true, 'exactly 1 GB: still allowed (≤, the boundary belongs to the cheap tier)');
check(orbitraWalOpportunisticAllowed(1073741824 + 1) === false, 'one byte past 1 GB: the opportunistic tier stands down (mon201)');

// --- A2. the quiet moment -------------------------------------------------------
$now = 1000.0;
// a fresh window (one sample) is never quiet — a tick started mid-burst
check(orbitraWalQuietMoment([[$now, 500 * 1048576]], $now) === false, 'a single sample is never quiet');
// a short window says nothing either (0.8× the requested span is required)
check(orbitraWalQuietMoment([[$now, 500 * 1048576], [$now + 5.0, 501 * 1048576]], $now + 5.0) === false, 'a 5 s window is too short to prove drained traffic');
// a full 10 s window with < 2 MB growth is quiet
check(orbitraWalQuietMoment([[$now, 500 * 1048576], [$now + 4.0, 500.5 * 1048576], [$now + 10.0, 501 * 1048576]], $now + 10.0) === true, '10 s window, 1 MB growth: quiet');
// the same window with heavy growth is not
check(orbitraWalQuietMoment([[$now, 500 * 1048576], [$now + 10.0, 900 * 1048576]], $now + 10.0) === false, '10 s window, 400 MB growth: not quiet (load)');
// exactly 2 MB growth is NOT quiet (strictly less than the budget)
check(orbitraWalQuietMoment([[$now, 500 * 1048576], [$now + 10.0, 500 * 1048576 + 2097152]], $now + 10.0) === false, 'exactly 2 MB growth: not quiet (strictly less)');
// a shrinking log is quiet (the fold itself must not disqualify the pass after it)
check(orbitraWalQuietMoment([[$now, 900 * 1048576], [$now + 10.0, 400 * 1048576]], $now + 10.0) === true, 'a shrinking log: quiet');
// no samples at all
check(orbitraWalQuietMoment([], $now) === false, 'no samples: never quiet');

// --- A3. the forced tier: space only, NO byte cap --------------------------------
check(orbitraWalForcedSpaceWanted(70 * 1048576, 100 * 1073741824) === false, '70 MB WAL on 100 GB free: no forced restart');
check(orbitraWalForcedSpaceWanted(3 * 1073741824, 10 * 1073741824) === true, '3 GB WAL on 10 GB free (> 25%): forced');
check(orbitraWalForcedSpaceWanted(2684354560, 10 * 1073741824) === false, 'exactly the share (2.5 GB on 10 GB free): not yet (strictly greater)');
check(orbitraWalForcedSpaceWanted(1024, 1073741824) === true, 'free < 2 GB: forced even with a 1 KB WAL');
check(orbitraWalForcedSpaceWanted(50 * 1073741824, null) === false, 'free unknown: no forced restart (fail soft)');
// mon201 regression: a big WAL on a huge disk must NOT force — that is the
// quiet tier's job now, after the traffic drains
check(orbitraWalForcedSpaceWanted(9 * 1073741824, 200 * 1073741824) === false, '9 GB WAL on 200 GB free: NOT forced — no byte cap, the quiet fold owns it');
check(orbitraWalForcedSpaceWanted(51 * 1073741824, 200 * 1073741824) === true, '51 GB WAL on 200 GB free (> 25%): forced — space is the only trigger');
// warning level
check(orbitraWalSpaceWarned(11 * 1073741824, 100 * 1073741824) === true, 'WAL > 10% of free: warned');
check(orbitraWalSpaceWarned(5 * 1073741824, 100 * 1073741824) === false, 'WAL < 10% of free: not warned');
check(orbitraWalSpaceWarned(50 * 1073741824, null) === false, 'warning with unknown free: off');
// and the cron wires the tiers with no cap
$cronSource = (string) file_get_contents($repoRoot . '/cli/click_spool_cron.php');
check(strpos($cronSource, 'WAL_QUIET_THRESHOLD    = 1073741824') !== false, 'the cron carries the 1 GB quiet threshold');
check(strpos($cronSource, 'WAL_FORCED_SPACE_CAP') === false, 'the 8 GB cap is gone from the cron (mon201)');
check(strpos($cronSource, "orbitraWalForcedSpaceWanted(\$size, \$diskFree, WAL_FORCED_SPACE_RATIO, WAL_FORCED_FREE_FLOOR)") !== false,
    'the forced call passes only the ratio and the floor');

// --- B. the loop ----------------------------------------------------------------
$work = sys_get_temp_dir() . '/orbitra_walforced_' . getmypid();
@mkdir($work . '/cli', 0777, true);
register_shutdown_function(static function () use ($work): void {
    foreach (glob($work . '/cli/*') ?: [] as $f) @unlink($f);
    foreach (glob($work . '/*') ?: [] as $f) @unlink($f);
    foreach (glob($work . '/var/cache/*') ?: [] as $f) @unlink($f);
    foreach (['/cli', '/var/cache', '/var', ''] as $d) @rmdir($work . $d);
});
copy($repoRoot . '/cli/click_spool_cron.php', $work . '/cli/click_spool_cron.php');
$configSrc = preg_replace(
    '/\$db_file\s*=\s*__DIR__\s*\.\s*[\'"]\/orbitra_db\.sqlite[\'"];/',
    '$db_file = __DIR__ . "/test.sqlite";',
    (string) file_get_contents($repoRoot . '/config.php')
);
file_put_contents($work . '/config.php', $configSrc);
exec('cp -R ' . escapeshellarg($repoRoot . '/core') . ' ' . escapeshellarg($work . '/core') . ' 2>/dev/null');

exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php') . ' --quiet --checkpoint-budget=0 2>/dev/null');

// Grow the WAL past the QUIET threshold. The default 1 GB is too heavy for a
// test — drive the threshold down with ORBITRA_WAL_QUIET_BYTES and the
// restart gate down with ORBITRA_WAL_RESTART_BYTES; the tier logic under
// test is threshold-agnostic.
$quietBytes = 8 * 1048576;   // "big WAL" = past 8 MB in this test
$restartBytes = 2 * 1048576; // opportunistic gate at 2 MB
$writer = new PDO('sqlite:' . $work . '/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$writer->exec('PRAGMA wal_autocheckpoint = 0');
$writer->exec('CREATE TABLE IF NOT EXISTS blob (id INTEGER PRIMARY KEY, data TEXT)');
$ins = $writer->prepare('INSERT INTO blob (data) VALUES (?)');
$walPath = $work . '/test.sqlite-wal';
$guard = 0;
while ($guard++ < 600) {
    clearstatcache();
    if (is_file($walPath) && (int) filesize($walPath) > $quietBytes + 1048576) break;
    for ($i = 0; $i < 200; $i++) {
        $ins->execute([str_repeat('x', 24000)]);
    }
}
clearstatcache();
$grown = is_file($walPath) ? (int) filesize($walPath) : 0;
check($grown > $quietBytes, "the WAL grew past the (lowered) quiet threshold (" . round($grown / 1048576) . " MB)");
$writer = null;

$runTick = static function (array $env, float $budget = 0.0) use ($work): array {
    $cmd = '';
    foreach ($env as $k => $v) {
        $cmd .= $k . '=' . escapeshellarg((string) $v) . ' ';
    }
    $cmd .= escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php')
        . ' --quiet --checkpoint-budget=' . $budget . ' 2>/dev/null';
    exec($cmd);
    return json_decode((string) @file_get_contents($work . '/var/cache/wal_checkpoint.json'), true) ?: [];
};
$baseEnv = ['ORBITRA_WAL_DISK_FREE_BYTES' => 100 * 1073741824, 'ORBITRA_WAL_QUIET_BYTES' => $quietBytes, 'ORBITRA_WAL_RESTART_BYTES' => $restartBytes];

// B1: a busy tick (zero budget = a single pass = fresh window, never quiet):
// the big WAL must NOT fold — no quiet restart, pending flag on, forced off.
$state = $runTick($baseEnv);
check(($state['quiet_restarts'] ?? -1) === 0, 'a fresh window never folds the big WAL');
check(($state['quiet_pending'] ?? false) === true, 'and the heartbeat says it is waiting for a quiet moment');
check(($state['forced_restarts'] ?? -1) === 0, 'plenty of free space: no forced attempt (no byte cap to bite)');
check((int) ($state['wal_growth_10s'] ?? -1) >= 0, 'the heartbeat carries the 10 s growth reading');

// B2: a drained tick — a long budget whose passes see no new writes. The
// window fills (≥ 10 s), growth < 2 MB → the quiet fold fires: RESTART +
// TRUNCATE, the file actually shrinks below the threshold.
$state = $runTick($baseEnv, 14.0);
check(($state['quiet_restarts'] ?? 0) >= 1, 'a drained 14 s tick folded the big WAL (quiet restart)');
clearstatcache();
$after = is_file($walPath) ? (int) filesize($walPath) : 0;
check($after < $quietBytes, "the -wal file actually shrank (TRUNCATE; now " . round($after / 1048576, 1) . " MB)");
check(($state['quiet_pending'] ?? true) === false, 'pending cleared after the fold');

// B3: the legacy fixed-threshold hook still behaves (the older tests).
// Regrow first: B2 just truncated the log to ~0 and the fixed hook compares
// against the CURRENT size.
$writer = new PDO('sqlite:' . $work . '/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$writer->exec('PRAGMA wal_autocheckpoint = 0');
$ins = $writer->prepare('INSERT INTO blob (data) VALUES (?)');
$guard = 0;
while ($guard++ < 600) {
    clearstatcache();
    if (is_file($walPath) && (int) filesize($walPath) > $quietBytes + 1048576) break;
    for ($i = 0; $i < 200; $i++) {
        $ins->execute([str_repeat('x', 24000)]);
    }
}
$writer = null;
$state = $runTick(['ORBITRA_WAL_DISK_FREE_BYTES' => 100 * 1073741824, 'ORBITRA_WAL_QUIET_BYTES' => $quietBytes, 'ORBITRA_WAL_RESTART_BYTES' => $restartBytes, 'ORBITRA_WAL_FORCED_BYTES' => 1048576]);
check(($state['forced_restarts'] ?? 0) >= 1, 'ORBITRA_WAL_FORCED_BYTES keeps the legacy fixed threshold');

// B4: space-forced under load — a tight fake disk must fire even though the
// window is fresh (a filling partition must not wait for quiet).
$state = $runTick(['ORBITRA_WAL_DISK_FREE_BYTES' => 1073741824, 'ORBITRA_WAL_QUIET_BYTES' => $quietBytes, 'ORBITRA_WAL_RESTART_BYTES' => $restartBytes]);
check(($state['forced_restarts'] ?? 0) >= 1, 'free < 2 GB: forced fires even on a fresh window');

echo $failures === 0 ? "WAL forced-space tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
