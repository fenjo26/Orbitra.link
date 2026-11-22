<?php
/**
 * ТЗ 2.1 (rb21 stand fix): the WAL divert — when disk space is at risk the
 * WAL checkpoint loop creates var/cache/wal_divert; click_logger sends clicks
 * to the spool (no INSERT, no WAL growth); when the WAL folds below the
 * quiet threshold the flag lifts and the spool replays.
 *
 * Uses ORBITRA_WAL_DISK_FREE_BYTES to fake a low-space condition without
 * actually filling a disk.
 *
 * Usage: php tests/stats_wal_divert_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/http.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->start();
try {
    $pdo = $harness->getPdo();
    $work = $harness->getWorkingDir();
    @mkdir($work . '/cli', 0777, true);
    @mkdir($work . '/var/cache', 0777, true);
    @mkdir($work . '/var/spool', 0777, true);
    copy(__DIR__ . '/../cli/click_spool_cron.php', $work . '/cli/click_spool_cron.php');
    require_once $work . '/core/click_logger.php';

    $divertFlag = $work . '/var/cache/wal_divert';
    $spoolFile = $work . '/var/spool/clicks.log';

    // --- 1. Low free space → the WAL loop creates the divert flag --------
    // Fake 1 GB free space with a WAL forced threshold of 10 MB: the check
    // "WAL > 50% of free" fires, the flag goes up.
    $env = 'ORBITRA_WAL_DISK_FREE_BYTES=1073741824';
    putenv($env);
    @unlink($divertFlag);
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php')
        . ' --quiet --checkpoint-budget=2 --freeze-budget=0 2>/dev/null');
    putenv('ORBITRA_WAL_DISK_FREE_BYTES');
    check(is_file($divertFlag), 'low disk space → wal_divert flag created');

    // --- 2. Click during the flag → spool, not the DB ---------------------
    @unlink($spoolFile);
    $ok = orbitraPersistClick($pdo, [
        'id' => 'wd-click-1', 'campaign_id' => 1, 'ip' => '203.0.113.50',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
    check($ok, 'persist returns true during WAL divert');
    check((int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE id = 'wd-click-1'")->fetchColumn() === 0,
        'click NOT in the database during divert');
    check(is_file($spoolFile) && str_contains((string) file_get_contents($spoolFile), 'wd-click-1'),
        'click is in the spool file');

    // --- 3. Normal space → flag lifts, clicks resume, spool drains ----------
    @unlink($divertFlag);   // simulate the WAL having folded
    putenv('ORBITRA_WAL_DISK_FREE_BYTES=68719476736');   // 64 GB free
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php')
        . ' --quiet --checkpoint-budget=2 --freeze-budget=0 2>/dev/null');
    putenv('ORBITRA_WAL_DISK_FREE_BYTES');
    check(!is_file($divertFlag), 'normal space → flag stays lifted');

    $ok2 = orbitraPersistClick($pdo, [
        'id' => 'wd-click-2', 'campaign_id' => 1, 'ip' => '203.0.113.51',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
    check($ok2 && (int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE id = 'wd-click-2'")->fetchColumn() === 1,
        'click lands in the database after divert lifts');

    // Spool replay: the spool cron picks up the spooled click
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php')
        . ' --quiet --checkpoint-budget=0 --freeze-budget=0 2>/dev/null');
    check((int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE id = 'wd-click-1'")->fetchColumn() === 1,
        'spooled click landed after divert lifted (spool replay)');
    check((int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE id IN ('wd-click-1','wd-click-2')")->fetchColumn() === 2,
        '0 click losses (both in DB)');

    // --- 4. Telemetry: cumulative counters in wal_checkpoint.json ----------
    $walState = @json_decode((string) @file_get_contents($work . '/var/cache/wal_checkpoint.json'), true);
    check(is_array($walState) && ($walState['divert_on_total'] ?? 0) >= 1,
        'divert_on_total ≥ 1 in wal_checkpoint.json');
    check(($walState['divert_active'] ?? true) === false,
        'divert_active false after lift');

    @unlink($divertFlag);
} finally {
    $harness->stop();
}

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);
