<?php
/**
 * Click spool: core/click_logger.php → var/spool/clicks.log → cli/click_spool_cron.php.
 *
 *  - spooled rows land in the database, the spool and batch files are gone;
 *  - a row that keeps failing goes back with an attempt counter and is
 *    parked in clicks.dead.log after SPOOL_MAX_ATTEMPTS;
 *  - a writer that opened the spool just before the cron renamed it away
 *    reopens the live file instead of writing into the taken batch;
 *  - orbitraPersistClick() does not retry in-request (one busy wait, then spool).
 *
 * Usage: php tests/click_spool_test.php
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
    copy(__DIR__ . '/../cli/click_spool_cron.php', $work . '/cli/click_spool_cron.php');
    require_once $work . '/core/click_logger.php';

    $spool = $work . '/var/spool/clicks.log';
    $dead = $work . '/var/spool/clicks.dead.log';
    $runCron = static function () use ($work): void {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php') . ' --quiet 2>/dev/null');
    };
    $row = static fn(string $id, array $extra = []) => $extra + [
        'id' => $id, 'campaign_id' => 1, 'ip' => '203.0.113.7', 'created_at' => gmdate('Y-m-d H:i:s'),
    ];
    $clickExists = static function (string $id) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clicks WHERE id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn() === 1;
    };

    // --- 1. happy path ---------------------------------------------------------
    check(orbitraSpoolClick($row('spool-a')) && orbitraSpoolClick($row('spool-b')), 'rows spooled');
    file_put_contents($spool, "not json\n", FILE_APPEND);
    $runCron();
    check($clickExists('spool-a') && $clickExists('spool-b'), 'spooled rows landed in clicks');
    check(!is_file($spool) || filesize($spool) === 0, 'live spool emptied');
    check((glob($work . '/var/spool/clicks.processing.*') ?: []) === [], 'no batch file left behind');
    check(is_file($dead) && strpos((string) file_get_contents($dead), 'not json') !== false, 'unparsable line parked in clicks.dead.log');

    // --- 2. a failing row is retried, then parked ------------------------------
    @unlink($dead);
    orbitraSpoolClick($row('spool-bad', ['no_such_column' => 'x']));
    $runCron();
    $back = json_decode(trim((string) @file_get_contents($spool)), true);
    check(is_array($back) && ($back['_spool_attempts'] ?? 0) === 1, 'failing row back in the spool with attempts = 1');
    $back['_spool_attempts'] = 29;
    file_put_contents($spool, json_encode($back) . "\n");
    $runCron();
    check((!is_file($spool) || filesize($spool) === 0) && strpos((string) @file_get_contents($dead), 'spool-bad') !== false,
        'after the last attempt the row is parked, not replayed forever');

    // --- 3. writer vs. rename race -----------------------------------------------
    // A helper holds the spool lock, renames the file away (what the cron does)
    // and releases. The writer, blocked on flock meanwhile, must notice that its
    // handle is no longer the live spool and write to the new file.
    @unlink($spool);
    touch($spool);
    $taken = $work . '/var/spool/clicks.taken.log';
    $helper = sprintf(
        '$f=fopen(%s,"ab"); flock($f,LOCK_EX); echo "locked\n"; fflush(STDOUT); usleep(700000); rename(%s,%s); flock($f,LOCK_UN);',
        var_export($spool, true), var_export($spool, true), var_export($taken, true)
    );
    $proc = proc_open([PHP_BINARY, '-r', $helper], [1 => ['pipe', 'w']], $pipes);
    fgets($pipes[1]); // wait until the helper holds the lock
    $ok = orbitraSpoolClick($row('spool-race'));
    proc_close($proc);
    check($ok, 'writer succeeded after the rename');
    check(strpos((string) @file_get_contents($spool), 'spool-race') !== false, 'row went to the live spool');
    check(strpos((string) @file_get_contents($taken), 'spool-race') === false, 'row did not go into the taken batch');

    // --- 4. no in-request retry -------------------------------------------------
    $src = (string) file_get_contents(__DIR__ . '/../core/click_logger.php');
    $fn = substr($src, strpos($src, 'function orbitraPersistClick'));
    $fn = substr($fn, 0, strpos($fn, "\n}\n"));
    check(strpos($fn, 'usleep') === false && strpos($fn, 'while') === false, 'orbitraPersistClick has no retry loop');
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage());
} finally {
    $harness->stop();
}

echo $failures === 0 ? "Click spool tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
