<?php
/**
 * TZ_LOAD_PERFORMANCE_2, part 2, fix 1: the background checkpoint RESTARTS
 * the WAL with a short bounded wait (250 ms) instead of a rare long TRUNCATE.
 *
 * Why: under load a reader is almost always inside the WAL, so a PASSIVE
 * checkpoint copies pages but never lets SQLite begin the log anew — the file
 * only grows (the acceptance soak saw ~21 MB/s, gigabytes total), until a
 * rare multi-second TRUNCATE stalls every writer. The fix takes the restart
 * moment with a SHORT wait, over and over.
 *
 * Setup mirrors the failure: several CLI "readers" hammer short SELECTs at
 * the sandbox database (web-worker stand-ins) while unique clicks arrive over
 * HTTP and the spool cron's checkpoint loop runs. Criteria (per the ТЗ):
 *  - the -wal file never exceeds 128 MB;
 *  - no click request waits longer than 1 s;
 *  - every click lands in clicks;
 *  - the tick's pulse records successful RESTARTs.
 *
 * RED on 106a3c8: PASSIVE never restarts under constant readers and the
 * TRUNCATE branch waits on busy==0 which never comes — the WAL outruns
 * 128 MB inside the window.
 *
 * Usage: php tests/wal_restart_under_readers_test.php
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

$CLICK_SECONDS = 50;          // of click traffic
$READERS = 8;                 // parallel short-SELECT loops (dense: see below)

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();
$harness->start();

$readers = [];
try {
    $work = $harness->getWorkingDir();
    $dbPath = $work . '/orbitra_test.sqlite';

    // --- campaign that really writes clicks -----------------------------------
    $seed = $harness->seedTestData();
    $campaignId = (int) $seed['campaign_id'];
    $pdo = $harness->getPdo();
    $offerId = 990021;
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, 'RESTART Offer', 'https://offer.example/r?cid={subid}', 0, 'active', 0)")
        ->execute([$offerId]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks)
                   VALUES (990022, ?, ?, 'RESTART Stream', 'regular', 1, 'redirect', ?, 1, 1)")
        ->execute([$campaignId, $offerId, json_encode(['offers' => [['id' => $offerId, 'weight' => 100]]])]);
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')")->execute([]);
    unset($pdo); // no test-side DB handle while the WAL is being watched

    // --- readers: short SELECT loops, like web workers under load --------------
    $stopFile = $work . '/readers.stop';
    @unlink($stopFile);
    $readerScript = $work . '/lt_reader.php';
    file_put_contents($readerScript, <<<'PHP'
<?php
// Test-only reader: endless short SELECTs with tiny gaps — the shape of web
// workers under load. Dense on purpose: the acceptance stand always had at
// least one reader inside the WAL, which is exactly what keeps a PASSIVE
// checkpoint from ever restarting the log. Dies when the stop file appears.
$pdo = new PDO('sqlite:' . __DIR__ . '/orbitra_test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
while (!is_file(__DIR__ . '/readers.stop')) {
    try {
        $st = $pdo->query('SELECT value FROM settings LIMIT 1');
        $st->fetchColumn();
        $st->closeCursor();
    } catch (Throwable $e) {
        // schema not built yet — retry
    }
    usleep(200);
}
PHP
    );
    for ($i = 0; $i < $READERS; $i++) {
        $readers[$i] = proc_open([PHP_BINARY, $readerScript], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
    }

    // --- the spool cron's checkpoint loop, one long tick ------------------------
    @mkdir($work . '/cli', 0777, true);
    copy(__DIR__ . '/../cli/click_spool_cron.php', $work . '/cli/click_spool_cron.php');
    $cron = proc_open(
        [PHP_BINARY, $work . '/cli/click_spool_cron.php', '--quiet', '--checkpoint-budget=' . ($CLICK_SECONDS + 5)],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $cPipes
    );
    check(is_resource($cron), 'the spool tick with the checkpoint loop started');

    // --- click traffic -----------------------------------------------------------
    $codes302 = 0;
    $maxReqMs = 0.0;
    $maxWal = 0;
    $fired = 0;
    $click = static function (int $i, string $sub = 'rs') use ($harness): array {
        $ctx = stream_context_create(['http' => [
            'timeout' => 10,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0 RESTART-Burst/' . $i,
                'X-Forwarded-For: 198.52.' . intdiv($i, 250) . '.' . ($i % 250 + 1),
            ]),
        ]]);
        $t0 = microtime(true);
        $body = (string) @file_get_contents($harness->getBaseUrl() . '/testcamp?sub1=' . $sub . '-' . $i, false, $ctx);
        $code = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\d\.\d (\d+)#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
        return [$code, (microtime(true) - $t0) * 1000.0, $body];
    };

    // Warm-up: the first request ever also runs the schema migrations, and
    // under eight concurrent readers that first migration run can hiccup —
    // retry a few times (idempotent), then require a clean 302 before the
    // measured window starts.
    $warmupOk = false;
    $warmupAttempts = 0;
    for ($w = 0; $w < 5 && !$warmupOk; $w++) {
        $warmupAttempts++;
        [$code] = $click($w, 'rsw');
        if ($code === 302) {
            $warmupOk = true;
        } else {
            usleep(300000);
        }
    }
    $warmups = $warmupAttempts;
    check($warmupOk, "warm-up click answered 302 (schema built, $warmupAttempts attempt(s))");
    $fired = 0;
    $non302 = [];
    $deadline = microtime(true) + $CLICK_SECONDS;
    $i = 5;
    while (microtime(true) < $deadline) {
        [$code, $ms] = $click($i++);
        $fired++;
        if ($code === 302) {
            $codes302++;
        } else {
            $non302[] = $code;
        }
        $maxReqMs = max($maxReqMs, $ms);
        clearstatcache();
        $maxWal = max($maxWal, is_file($dbPath . '-wal') ? (int) filesize($dbPath . '-wal') : 0);
    }
    if ($non302) {
        echo 'NOTE: non-302 responses: ' . implode(',', array_unique($non302)) . ' (' . count($non302) . ")\n";
    }

    // Let the tick finish its last passes, then stop readers and judge.
    usleep(1500000);
    clearstatcache();
    $maxWal = max($maxWal, is_file($dbPath . '-wal') ? (int) filesize($dbPath . '-wal') : 0);
    file_put_contents($stopFile, 'stop');
    foreach ($readers as $r) {
        if (is_resource($r)) {
            proc_terminate($r);
        }
    }
    if (is_resource($cron)) {
        proc_close($cron);
    }

    // The long tick's pulse, read BEFORE any drain tick overwrites it.
    $state = json_decode((string) @file_get_contents($work . '/var/cache/wal_checkpoint.json'), true) ?: [];

    // At hyper-local rates some clicks sit in the live spool (lock
    // contention, by design); one synchronous drain tick replays them
    // before rows are counted.
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php')
        . ' --quiet --checkpoint-budget=2 2>/dev/null');

    check($codes302 === $fired, "every click answered 302 ($codes302/$fired)");
    check($maxReqMs < 1000.0, sprintf('no click request waited longer than 1 s (slowest %.0f ms)', $maxReqMs));
    check($maxWal <= 128 * 1048576, sprintf('the WAL stayed within 128 MB (max %.1f MB)', $maxWal / 1048576));

    $pdo = $harness->getPdo();
    // '%rs-%' deliberately excludes the 'rsw-' warm-ups: a failed warm-up
    // attempt may or may not have inserted its row, and the criterion is
    // about the measured traffic only.
    $rows = (int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE parameters_json LIKE '%rs-%' AND parameters_json NOT LIKE '%rsw-%'")->fetchColumn();
    check($rows === $fired, "every measured click landed in clicks ($rows/$fired)");

    check(($state['restarts_ok'] ?? 0) > 0,
        'the tick recorded successful RESTARTs (' . ($state['restarts_ok'] ?? 0) . ' ok, ' . ($state['restarts_busy'] ?? 0) . ' busy)');
    check(($state['max_block_ms'] ?? 99999) <= 1000,
        sprintf('the longest restart block was bounded (' . round((float) ($state['max_block_ms'] ?? -1)) . ' ms)'));
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach ($readers as $r) {
        if (is_resource($r)) {
            proc_terminate($r);
        }
    }
    $harness->stop();
}

echo $failures === 0 ? "WAL restart under readers tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
