<?php
/**
 * TZ_LOAD_PERFORMANCE_2, stage 2: WAL checkpoints live OUTSIDE the click path.
 *
 *  - web connections run with wal_autocheckpoint = 10000 (~40 MB fuse) and
 *    journal_size_limit = 64 MB — RED on c43ce94, where the defaults were
 *    1000 pages / no limit and every big-enough request checkpointed itself;
 *  - a burst of unique clicks grows the -wal file monotonically (no request
 *    folds it back into the main database);
 *  - one cli/click_spool_cron.php tick (--checkpoint-budget=0) performs a
 *    PASSIVE checkpoint, refreshes var/cache/wal_checkpoint.json (the panel's
 *    heartbeat) and leaves every row in place;
 *  - clicks fired WHILE the checkpoint loop runs all land — none spooled.
 *
 * Usage: php tests/wal_background_checkpoint_test.php
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
$harness->useProductionRouter();
$harness->start();

try {
    $work = $harness->getWorkingDir();
    $dbPath = $work . '/orbitra_test.sqlite';

    file_put_contents($work . '/lt_wal_probe.php', <<<'PROBE'
<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
echo json_encode([
    'wal_autocheckpoint' => (string) $pdo->query('PRAGMA wal_autocheckpoint')->fetchColumn(),
    'journal_size_limit' => (string) $pdo->query('PRAGMA journal_size_limit')->fetchColumn(),
    'journal_mode'       => (string) $pdo->query('PRAGMA journal_mode')->fetchColumn(),
]);
PROBE);

    // --- 1. the web connection's checkpoint guard rails (red on 1.9.5) ------
    $pragma = json_decode((string) $harness->get('/lt_wal_probe.php')['body'], true) ?: [];
    check(($pragma['wal_autocheckpoint'] ?? '') === '10000',
        'web connections run with wal_autocheckpoint = 10000 (got ' . ($pragma['wal_autocheckpoint'] ?? '?') . ')');
    // mon201: WEB connections run with journal_size_limit = -1 (no truncation
    // inside a click — the 8 GB → 64 MB shrink after the big-WAL restart was a
    // multi-second stall); only the CLI/cron side keeps the 64 MB cap.
    check(($pragma['journal_size_limit'] ?? '') === '-1',
        'web connections never truncate the WAL (journal_size_limit = -1; got ' . ($pragma['journal_size_limit'] ?? '?') . ')');
    check(($pragma['journal_mode'] ?? '') === 'wal', 'sandbox runs in WAL mode');

    // --- a campaign that really writes clicks -------------------------------
    $seed = $harness->seedTestData();
    $campaignId = (int) $seed['campaign_id'];
    $pdo = $harness->getPdo();
    $offerId = 990011;
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, 'WAL Offer', 'https://offer.example/wal?cid={subid}', 0, 'active', 0)")
        ->execute([$offerId]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks)
                   VALUES (990012, ?, ?, 'WAL Stream', 'regular', 1, 'redirect', ?, 1, 1)")
        ->execute([$campaignId, $offerId, json_encode(['offers' => [['id' => $offerId, 'weight' => 100]]])]);
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')")->execute();

    $getNoFollow = static function (string $path, array $headers) use ($harness): int {
        $ctx = stream_context_create(['http' => [
            'timeout' => 10,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'header' => implode("\r\n", $headers),
        ]]);
        $body = @file_get_contents($harness->getBaseUrl() . $path, false, $ctx);
        $code = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\d\.\d (\d+)#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
        return $code;
    };
    $click = static function (int $i) use ($getNoFollow): int {
        return $getNoFollow('/testcamp?sub1=wal-' . $i, [
            'User-Agent: Mozilla/5.0 WAL-Burst/' . $i,
            'X-Forwarded-For: 198.51.' . intdiv($i, 250) . '.' . ($i % 250 + 1),
        ]);
    };

    // --- 2. burst: the WAL grows, no request checkpoints it back ------------
    // 300 unique clicks; the test process holds no PDO while watching the
    // file (a held connection would keep it alive for the wrong reason).
    unset($pdo);
    $codes302 = 0;
    for ($i = 0; $i < 300; $i++) {
        if ($click($i) === 302) {
            $codes302++;
        }
    }
    $walSizeAfterBurst = is_file($dbPath . '-wal') ? (int) filesize($dbPath . '-wal') : 0;
    check($codes302 === 300, "all 300 burst clicks answered 302 (got $codes302)");
    check($walSizeAfterBurst > 4096,
        "after 300 clicks the -wal file carries their frames ({$walSizeAfterBurst} bytes; on 1.9.5 a request checkpoint folded and deleted it)");

    // --- 3. one spool-cron tick checkpoints in the background ----------------
    @mkdir($work . '/cli', 0777, true);
    copy(__DIR__ . '/../cli/click_spool_cron.php', $work . '/cli/click_spool_cron.php');
    $stateFile = $work . '/var/cache/wal_checkpoint.json';
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php')
        . ' --quiet --checkpoint-budget=0 2>/dev/null', $cronOut, $cronRc);
    check($cronRc === 0, 'the spool tick with a zero checkpoint budget exits 0');
    $state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
    check(($state['passes'] ?? 0) >= 1, 'the tick performed at least one PASSIVE checkpoint');
    check(($state['at'] ?? 0) > time() - 60, 'the heartbeat file is fresh (the panel warns when it is not)');
    check(($state['max_ms'] ?? -1) >= 0, 'the tick recorded its slowest checkpoint duration (max_ms)');

    $pdo = $harness->getPdo();
    // parameters_json carries sub1=wal-N only for the burst (seedTestData's own
    // click has none), so this counts exactly the burst rows.
    $burstRows = (int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE parameters_json LIKE '%wal-%'")->fetchColumn();
    check($burstRows === 300, "all 300 burst clicks are rows in clicks (got $burstRows)");

    // --- 4. clicks while the checkpoint loop is running ----------------------
    // ~3 s of PASSIVE checkpoints in the background while 60 clicks arrive.
    $bg = proc_open(
        [PHP_BINARY, $work . '/cli/click_spool_cron.php', '--quiet', '--checkpoint-budget=3'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $bgPipes
    );
    $duringCodes = 0;
    for ($i = 300; $i < 360; $i++) {
        if ($click($i) === 302) {
            $duringCodes++;
        }
    }
    // Wait for the background tick to finish before judging the spool.
    if (is_resource($bg)) {
        proc_close($bg);
    }
    $duringRows = (int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE parameters_json LIKE '%wal-%'")->fetchColumn();
    $spoolFile = $work . '/var/spool/clicks.log';
    $spoolBytes = is_file($spoolFile) ? (int) filesize($spoolFile) : 0;
    check($duringCodes === 60, "clicks during the background checkpoint all answered 302 (got $duringCodes)");
    check($duringRows === 360, "all 360 clicks landed in the database, none lost to the checkpoint (got $duringRows)");
    check($spoolBytes === 0 && (glob($work . '/var/spool/clicks.processing.*') ?: []) === [],
        'nothing was spooled: PASSIVE checkpoints do not block click writes');

    // Heartbeat refreshed by the longer tick too.
    $state2 = json_decode((string) file_get_contents($stateFile), true) ?: [];
    check(($state2['passes'] ?? 0) >= 1 && ($state2['at'] ?? 0) >= ($state['at'] ?? 0),
        'the longer background tick refreshed the heartbeat again');
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    $harness->stop();
}

echo $failures === 0 ? "WAL background checkpoint tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
