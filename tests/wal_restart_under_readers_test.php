<?php
/**
 * TZ_LOAD_PERFORMANCE_2, part 3: web requests NEVER checkpoint or wait; the
 * background worker owns the WAL — an opportunistic RESTART with a ZERO busy
 * wait every pass, plus a rare forced RESTART (1 s busy wait, at most once a
 * minute) once the WAL is genuinely huge.
 *
 * Why this shape: the part-3 soak pair showed a 250 ms wait on the web path
 * parks every fpm worker in line for the same RESTART — 23 281 k6 errors,
 * one per fuse firing — while zero waiting everywhere lets the WAL grow
 * ~550 MB/min under load. Zero-wait-often plus one-second-per-minute threads
 * the needle: no writer is ever blocked, and the WAL peak stays bounded.
 *
 * Criteria (per the ТЗ):
 *  - no HTTP request slower than 1.5 s (web side waits on nothing);
 *  - under constant readers the WAL stays bounded and the forced RESTART
 *    fires (threshold lowered to 24 MB via ORBITRA_WAL_FORCED_BYTES);
 *  - after the load and the readers stop, the WAL folds back to ≤ 64 MB
 *    within a pass or two (RESTART + journal_size_limit);
 *  - every measured click lands in clicks.
 *
 * RED on 1e0c285 (rc2): that code has no forced restart at all and instead
 * checkpoints on WEB connections past 256 MB — the pulse carries no
 * forced_restarts, so the forced assert fails there.
 *
 * Usage: php tests/wal_restart_under_readers_test.php
 * (Not safe to run under parallel load: the WAL-bound criterion assumes this
 * test owns the machine's spare capacity.)
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

$CLICK_SECONDS = 70;          // of click traffic (spans two forced windows)
$READERS = 8;                 // parallel short-SELECT loops
$FORCED_BYTES = 25165824;     // 24 MB instead of 1 GB — tests must not build a gigabyte

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
// workers under load (the stand always had at least one reader inside the
// WAL). Dies when the stop file appears.
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
    $cronEnv = ['ORBITRA_WAL_FORCED_BYTES' => (string) $FORCED_BYTES];
    $cron = proc_open(
        [PHP_BINARY, $work . '/cli/click_spool_cron.php', '--quiet', '--checkpoint-budget=' . ($CLICK_SECONDS + 5)],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $cPipes,
        null,
        array_merge(array_filter(getenv(), 'is_string'), $cronEnv)
    );
    check(is_resource($cron), 'the spool tick with the checkpoint loop started');

    // --- click traffic -----------------------------------------------------------
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
        @file_get_contents($harness->getBaseUrl() . '/testcamp?sub1=' . $sub . '-' . $i, false, $ctx);
        $code = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\d\.\d (\d+)#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
        return [$code, (microtime(true) - $t0) * 1000.0];
    };

    // Warm-up: the first request ever also runs the schema migrations, and
    // under eight concurrent readers that first migration run can hiccup —
    // retry a few times (idempotent), then require a clean 302.
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
    check($warmupOk, "warm-up click answered 302 (schema built, $warmupAttempts attempt(s))");

    $codes302 = 0;
    $fired = 0;
    $maxReqMs = 0.0;
    $maxWal = 0;
    $non302 = [];
    // Pace ~580 rps: stand-scale inflow (unpaced, a fast local machine pushes
    // 1300+ rps and dwarfs the forced threshold mechanics).
    $minInterval = 1.7 / 1000.0;
    $nextShot = microtime(true);
    $deadline = microtime(true) + $CLICK_SECONDS;
    $i = 5;
    while (microtime(true) < $deadline) {
        // usleep, not time_sleep_until: the latter warns when the target is
        // already in the past (one click can overrun the interval).
        usleep(max(0, (int) (($nextShot - microtime(true)) * 1000000)));
        $nextShot += $minInterval;
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

    // Let the tick finish its last passes, then stop readers.
    usleep(1500000);
    if (is_resource($cron)) {
        proc_close($cron);
    }
    // The long tick's pulse, read BEFORE any drain tick overwrites it.
    $state = json_decode((string) @file_get_contents($work . '/var/cache/wal_checkpoint.json'), true) ?: [];
    file_put_contents($stopFile, 'stop');
    foreach ($readers as $r) {
        if (is_resource($r)) {
            proc_terminate($r);
        }
    }

    check($codes302 === $fired, "every click answered 302 ($codes302/$fired; non-302: " . implode(',', array_unique($non302) ?: ['none']) . ')');
    check($maxReqMs < 1500.0, sprintf('no click request waited longer than 1.5 s — the web side waits on nothing (slowest %.0f ms)', $maxReqMs));
    check($maxWal <= 1200 * 1048576, sprintf('the WAL stayed bounded under constant readers (max %.0f MB, forced threshold %d MB)', $maxWal / 1048576, $FORCED_BYTES / 1048576));
    check(($state['forced_restarts'] ?? 0) >= 1,
        'the forced RESTART fired (' . ($state['forced_restarts'] ?? 0) . ' attempts, pulse: ok=' . ($state['restarts_ok'] ?? 0) . ' busy=' . ($state['restarts_busy'] ?? 0) . ')');
    check((($state['restarts_ok'] ?? 0) + ($state['restarts_busy'] ?? 0)) > 0, 'opportunistic restarts were attempted every pass');

    // --- after the load: the WAL folds back within a pass or two ----------------
    // Readers are gone, so a zero-wait RESTART lands on the first pass and
    // journal_size_limit caps the file.
    exec('ORBITRA_WAL_FORCED_BYTES=' . $FORCED_BYTES . ' ' . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg($work . '/cli/click_spool_cron.php') . ' --quiet --checkpoint-budget=4 2>/dev/null');
    clearstatcache();
    $walAfter = is_file($dbPath . '-wal') ? (int) filesize($dbPath . '-wal') : 0;
    check($walAfter <= 64 * 1048576, sprintf('after the load the WAL folded back to ≤ 64 MB (now %.1f MB)', $walAfter / 1048576));

    // At hyper-local rates some clicks sit in the live spool (lock
    // contention, by design); the drain tick above also replayed them.
    $pdo = $harness->getPdo();
    // '%rs-%' deliberately excludes the 'rsw-' warm-ups: a failed warm-up
    // attempt may or may not have inserted its row.
    $rows = (int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE parameters_json LIKE '%rs-%' AND parameters_json NOT LIKE '%rsw-%'")->fetchColumn();
    check($rows === $fired, "every measured click landed in clicks ($rows/$fired)");
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
