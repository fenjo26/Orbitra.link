<?php
/**
 * mon198 follow-up: the forced RESTART tier keys on FREE SPACE, not the
 * fixed 1 GB. The stand A/B showed the fixed trip pauses writers ~3 s on a
 * disk with hundreds of GB free — for no safety gain (the WAL folds cleanly
 * after the load). Contract (nothing else in the flush scheme changes):
 *
 *  A. pure decision (core/wal_policy.php):
 *     - WAL > min(25% of free, 8 GB)  → forced wanted
 *     - free < 2 GB                   → forced wanted regardless of WAL
 *     - plenty of free space          → NOT wanted (PASSIVE + opportunistic
 *                                       RESTART continue as before)
 *     - free unknown                  → NOT wanted (fail soft; the
 *                                       opportunistic tier still runs)
 *     - the 8 GB cap                  → bounds the share on huge partitions
 *                                       (25% of 200 GB free would admit a
 *                                       50 GB WAL); small disks never see it
 *     - warning level: WAL > 10% of free
 *
 *  B. the loop itself, driven through the real cron with a faked
 *     free-space reading (ORBITRA_WAL_DISK_FREE_BYTES): a WAL past the
 *     opportunistic gate with plenty of fake free space must show ZERO
 *     forced attempts; the same WAL with a tight fake disk must fire one.
 *     ORBITRA_WAL_FORCED_BYTES keeps the legacy fixed-threshold behavior
 *     for the older tests (verified here too).
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

// --- A. the pure decision ------------------------------------------------------
// plenty of space: 70 MB WAL on 100 GB free (25% = 25 GB) — not wanted
check(orbitraWalForcedSpaceWanted(70 * 1048576, 100 * 1073741824) === false, '70 MB WAL on 100 GB free: no forced restart');
// WAL past a quarter of free: 3 GB WAL on 10 GB free — wanted
check(orbitraWalForcedSpaceWanted(3 * 1073741824, 10 * 1073741824) === true, '3 GB WAL on 10 GB free (> 25%): forced');
// exactly at the share boundary is NOT wanted (strictly greater, like the old
// threshold) — on a small disk, where the share (2.5 GB) is below the cap and
// still the trigger. On 100 GB free this same 25 GB WAL is now FORCED: the
// share there is 25 GB but the cap cuts it to 8 GB, which 25 GB exceeds.
check(orbitraWalForcedSpaceWanted(2684354560, 10 * 1073741824) === false, 'exactly the share (2.5 GB on 10 GB free): not yet (strictly greater)');
check(orbitraWalForcedSpaceWanted(25 * 1073741824, 100 * 1073741824) === true, '25 GB WAL on 100 GB free: forced — the 8 GB cap replaces the 25 GB share');
// partition nearly full: forced regardless of WAL size
check(orbitraWalForcedSpaceWanted(1024, 1073741824) === true, 'free < 2 GB: forced even with a 1 KB WAL');
// unknown free space: fail soft
check(orbitraWalForcedSpaceWanted(50 * 1073741824, null) === false, 'free unknown: no forced restart (opportunistic still runs)');
// warning level
check(orbitraWalSpaceWarned(11 * 1073741824, 100 * 1073741824) === true, 'WAL > 10% of free: warned');
check(orbitraWalSpaceWarned(5 * 1073741824, 100 * 1073741824) === false, 'WAL < 10% of free: not warned');
check(orbitraWalSpaceWarned(50 * 1073741824, null) === false, 'warning with unknown free: off');

// --- A2. the 8 GB cap (tz-2.0.1-forced-space follow-up) ------------------------
// On a huge partition the 25% share alone would admit an absurd WAL (25% of
// 200 GB free = 50 GB — a fold measured in hours and a recovery to match).
// The cap bounds the share at 8 GB while the free-space danger the ratio
// guards against is nowhere near on such disks.
check(orbitraWalForcedSpaceWanted(9 * 1073741824, 200 * 1073741824) === true, '9 GB WAL on 200 GB free: forced — the cap bites under the 25% share (50 GB)');
check(orbitraWalForcedSpaceWanted(8 * 1073741824, 200 * 1073741824) === false, 'exactly 8 GB WAL on 200 GB free: not yet (strictly greater)');
check(orbitraWalForcedSpaceWanted(8 * 1073741824 + 1, 200 * 1073741824) === true, 'one byte past the cap: forced');
check(orbitraWalForcedSpaceWanted(7 * 1073741824, 200 * 1073741824) === false, '7 GB WAL on 200 GB free: not forced (under the cap and under the share)');
// Small disks never see the cap: 25% of 10 GB free = 2.5 GB < 8 GB, the
// ratio stays the trigger exactly as before the cap existed.
check(orbitraWalForcedSpaceWanted(2 * 1073741824, 10 * 1073741824) === false, '10 GB free, WAL 2 GB (< the 2.5 GB share): not forced');
// Where the share and the cap coincide (25% of 32 GB free = 8 GB) the
// boundary behaves the same from either side.
check(orbitraWalForcedSpaceWanted(8 * 1073741824, 32 * 1073741824) === false, '32 GB free: share == cap == 8 GB, exactly there is not yet forced');
check(orbitraWalForcedSpaceWanted(8 * 1073741824 + 1, 32 * 1073741824) === true, '32 GB free: one byte past the coincidence point fires');
// And the cron must actually wire the cap into the call (the loop-level
// cases below cannot grow a 9 GB WAL cheaply, so the wiring is asserted on
// the source, the arithmetic on the pure function above).
$cronSource = (string) file_get_contents($repoRoot . '/cli/click_spool_cron.php');
check(strpos($cronSource, 'WAL_FORCED_SPACE_CAP   = 8589934592') !== false
    && strpos($cronSource, 'WAL_FORCED_FREE_FLOOR, WAL_FORCED_SPACE_CAP') !== false,
    'the cron passes WAL_FORCED_SPACE_CAP into the decision');

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

// one baseline tick builds the schema
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php') . ' --quiet --checkpoint-budget=0 2>/dev/null');

// grow the WAL past the opportunistic gate (64 MB) once, shared by all runs
$writer = new PDO('sqlite:' . $work . '/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$writer->exec('PRAGMA wal_autocheckpoint = 0');
$writer->exec('CREATE TABLE IF NOT EXISTS blob (id INTEGER PRIMARY KEY, data TEXT)');
$ins = $writer->prepare('INSERT INTO blob (data) VALUES (?)');
$walPath = $work . '/test.sqlite-wal';
$guard = 0;
while ($guard++ < 600) {
    clearstatcache();
    if (is_file($walPath) && (int) filesize($walPath) > 68 * 1048576) break;
    for ($i = 0; $i < 200; $i++) {
        $ins->execute([str_repeat('x', 24000)]);
    }
}
clearstatcache();
$grown = is_file($walPath) ? (int) filesize($walPath) : 0;
check($grown > 67108864, "the WAL grew past the 64 MB gate (" . round($grown / 1048576) . " MB)");
$writer = null;

$runTick = static function (array $env) use ($work): array {
    $cmd = '';
    foreach ($env as $k => $v) {
        $cmd .= $k . '=' . escapeshellarg((string) $v) . ' ';
    }
    $cmd .= escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php')
        . ' --quiet --checkpoint-budget=0 2>/dev/null';
    exec($cmd);
    return json_decode((string) @file_get_contents($work . '/var/cache/wal_checkpoint.json'), true) ?: [];
};

// B1: plenty of fake free space (100 GB) — the forced tier must NOT fire
$state = $runTick(['ORBITRA_WAL_DISK_FREE_BYTES' => 100 * 1073741824]);
check(($state['forced_restarts'] ?? -1) === 0, 'plenty of free space: zero forced attempts');
check(($state['disk_free_bytes'] ?? null) === 100 * 1073741824, 'the heartbeat carries the (faked) free-space reading');
check(($state['wal_bytes'] ?? 0) > 67108864, 'the heartbeat carries the current WAL size');
check(($state['wal_space_warned'] ?? true) === false, 'no space warning at this WAL/free ratio');

// B2: tight fake disk (free < 2 GB) — one forced attempt must fire
$state = $runTick(['ORBITRA_WAL_DISK_FREE_BYTES' => 1073741824]);
check(($state['forced_restarts'] ?? 0) >= 1, 'free < 2 GB: the forced attempt fired');
// the warning flag must match the heartbeat's own WAL reading (the WAL may
// have folded between ticks; self-consistency is what we assert)
$expectedWarn = ((int) ($state['wal_bytes'] ?? 0)) > (int) floor(1073741824 * 0.10);
check(($state['wal_space_warned'] ?? null) === $expectedWarn, 'the warning flag matches its own WAL reading (' . ($state['wal_bytes'] ?? -1) . ' bytes on 1 GB free)');

// B3: the ratio path — free = 100 MB, WAL ~70 MB > 25 MB → forced
$state = $runTick(['ORBITRA_WAL_DISK_FREE_BYTES' => 100 * 1048576]);
check(($state['forced_restarts'] ?? 0) >= 1, 'WAL > 25% of free: the forced attempt fired');

// B4: the legacy fixed-threshold hook still behaves as before
$state = $runTick(['ORBITRA_WAL_DISK_FREE_BYTES' => 100 * 1073741824, 'ORBITRA_WAL_FORCED_BYTES' => 32 * 1048576]);
check(($state['forced_restarts'] ?? 0) >= 1, 'ORBITRA_WAL_FORCED_BYTES keeps the legacy fixed threshold (tests)');

echo $failures === 0 ? "WAL forced-space tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
