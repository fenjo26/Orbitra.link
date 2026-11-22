<?php
/**
 * ТЗ 2.1, stage 4 review: VACUUM flag semantics.
 *
 *  - A click during vacuum.running goes straight to the spool (no INSERT
 *    attempt, no busy_timeout burn) and lands after the spool cron replays;
 *  - a dead worker pid → the spool cron's crash recovery marks
 *    'error:interrupted', removes the flag, the button is re-enabled.
 *
 * Usage: php tests/stats_vacuum_flag_test.php
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

    $flagFile = $work . '/var/cache/vacuum.running';
    $spoolFile = $work . '/var/spool/clicks.log';

    // --- 1. Click during the flag goes to the spool, not the DB --------
    @unlink($spoolFile);
    file_put_contents($flagFile, '999999');   // any pid — the file is the signal
    $t0 = microtime(true);
    $ok = orbitraPersistClick($pdo, [
        'id' => 'vac-click-1', 'campaign_id' => 1, 'ip' => '203.0.113.1',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $elapsed = microtime(true) - $t0;
    check($ok, 'persist returns true during the flag');
    check($elapsed < 0.1, 'no busy_timeout burn (took ' . round($elapsed * 1000) . 'ms, expected <100ms)');
    check((int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE id = 'vac-click-1'")->fetchColumn() === 0,
        'click NOT in the database while the flag is up');
    check(is_file($spoolFile) && str_contains((string) file_get_contents($spoolFile), 'vac-click-1'),
        'click is in the spool file');

    // --- 2. Flag removed → normal insert resumes -------------------------
    @unlink($flagFile);
    $ok2 = orbitraPersistClick($pdo, [
        'id' => 'vac-click-2', 'campaign_id' => 1, 'ip' => '203.0.113.2',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
    check($ok2 && (int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE id = 'vac-click-2'")->fetchColumn() === 1,
        'click lands in the database when the flag is gone');

    // --- 3. Spool cron replays the spooled click -------------------------
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php')
        . ' --quiet --checkpoint-budget=0 --freeze-budget=0 2>/dev/null');
    check((int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE id = 'vac-click-1'")->fetchColumn() === 1,
        'spooled click landed after the flag was removed (spool replay)');

    // --- 4. Dead worker pid → spool cron recovers to interrupted ---------
    @unlink($flagFile);
    $deadPid = 999999;   // nobody has this pid in a test environment
    file_put_contents($flagFile, (string) $deadPid);
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('vacuum_conversion_state', 'running')");
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php')
        . ' --quiet --checkpoint-budget=0 --freeze-budget=0 2>/dev/null');
    $state = (string) $pdo->query("SELECT value FROM settings WHERE key = 'vacuum_conversion_state'")->fetchColumn();
    check($state === 'error:interrupted', "dead pid → interrupted (state='$state')");
    check(!is_file($flagFile), 'flag file removed by recovery');

    // --- 5. Live pid → no recovery ----------------------------------------
    $livePid = (int) getmypid();
    file_put_contents($flagFile, (string) $livePid);
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('vacuum_conversion_state', 'running')");
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php')
        . ' --quiet --checkpoint-budget=0 --freeze-budget=0 2>/dev/null');
    $state2 = (string) $pdo->query("SELECT value FROM settings WHERE key = 'vacuum_conversion_state'")->fetchColumn();
    check($state2 === 'running', 'live pid → state stays running');
    check(is_file($flagFile), 'flag file intact for a live pid');
    @unlink($flagFile);

    // --- 6. Empty spool + state=requested → ONE tick spawns the worker ---
    // The stand caught this: the fast path (no spool batches) was missing
    // the vacuum spawn — state stuck at 'requested' for 6+ ticks.
    {
        // Ensure the spool is empty (no clicks.log / processing batches).
        @unlink($spoolFile);
        foreach (glob($work . '/var/spool/clicks.processing.*') ?: [] as $f) {
            @unlink($f);
        }
        copy(__DIR__ . '/../cli/vacuum_convert.php', $work . '/cli/vacuum_convert.php');
        $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('vacuum_conversion_state', 'requested')");
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php')
            . ' --quiet --checkpoint-budget=0 --freeze-budget=0 2>/dev/null');
        // The worker runs synchronously in the background (nohup); give it
        // a moment, then check the state advanced past 'requested'.
        usleep(500000);   // 500 ms
        $stateAfter = (string) $pdo->query("SELECT value FROM settings WHERE key = 'vacuum_conversion_state'")->fetchColumn();
        check($stateAfter !== 'requested',
            "empty spool: one tick advanced state past 'requested' (got '$stateAfter')");
        check(in_array($stateAfter, ['running', 'done'], true) || str_starts_with($stateAfter, 'error:'),
            "empty spool: state is running/done/error (got '$stateAfter')");
        // Clean up for other tests.
        @unlink($work . '/var/cache/vacuum.running');
        $pdo->exec("DELETE FROM settings WHERE key LIKE 'vacuum_conversion_%'");
    }
} finally {
    $harness->stop();
}

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);
