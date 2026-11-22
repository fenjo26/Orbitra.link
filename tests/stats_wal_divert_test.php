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


    // --- 5. Postback for a spooled click: pending does NOT expire -----------
    // The click is in the spool (not in the DB); the pending postback
    // must stay alive past the 2-hour TTL until the spool drains.
    {
        // Spool is non-empty (the click from test 2 is still there if we
        // re-create the divert scenario).
        @unlink($spoolFile);
        // Put a click in the spool (simulating divert).
        file_put_contents($spoolFile, json_encode([
            'id' => 'wd-pb-click', 'campaign_id' => 1, 'ip' => '203.0.113.60',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]) . "\n", FILE_APPEND | LOCK_EX);

        // Insert a pending postback for that click with a received_ts
        // far past the 2-hour TTL (simulate: divert lasted 3 hours).
        $pdo->prepare("INSERT INTO incoming_postbacks_log
            (click_id, result, created_at)
            VALUES ('wd-pb-click', 'pending_click', ?)")
            ->execute([gmdate('Y-m-d H:i:s', time() - 10800)]);   // 3 hours ago

        // Run the pending processor — the click is NOT in the DB, the spool
        // IS non-empty: the postback must stay waiting, not expire.
        require_once $work . '/core/PostbackPending.php';
        $pbStats = orbitraProcessPendingPostbacks($pdo, $work, 1.0);
        check(($pbStats['waiting'] ?? 0) >= 1 && ($pbStats['unmatched'] ?? 0) === 0,
            'pending postback survives past TTL while spool is non-empty');

        // Now drain the spool (empty it), and the postback SHOULD expire.
        @unlink($spoolFile);
        $pbStats2 = orbitraProcessPendingPostbacks($pdo, $work, 1.0);
        check(($pbStats2['unmatched'] ?? 0) >= 1,
            'pending postback expires after spool drains');
    }

    // --- 6. Anti-oscillation: spool replay chunks by WAL size ----------------
    {
        // The WAL throttling lives in the spool replay: every 5000 rows it
        // checks the WAL; past 80% of the quiet threshold it requeues the
        // rest. We verify the mechanism exists (constant + condition).
        $src = file_get_contents(__DIR__ . '/../cli/click_spool_cron.php');
        check(str_contains($src, 'walCheckCounter >= 5000') && str_contains($src, '858993459'),
            'spool replay throttles at 5000-row chunks gated by WAL size');
        check(str_contains($src, 'walBacklog'),
            'spool replay has a WAL-backlog early-exit path');
    }

    @unlink($divertFlag);
} finally {
    $harness->stop();
}

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);
