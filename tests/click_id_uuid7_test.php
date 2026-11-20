<?php
/**
 * ТЗ 1.9.8 — the click id is UUIDv7 from ONE generator. Unit properties +
 * an HTTP pass proving the entry point actually persists uuid7 rows:
 *  - format: 36 chars, lowercase hex + dashes, version nibble '7' at
 *    position 14, variant nibble 8/9/a/b at position 19;
 *  - time-ordered at millisecond granularity: the 48-bit ms prefix of the
 *    generation sequence never decreases (strict per-call ordering within
 *    one millisecond is NOT a uuid7 property — the random tail is unordered
 *    there, which is fine: same-ms ids land on the same right-edge pages);
 *  - uniqueness across a burst;
 *  - the legacy shims (index.php generateUuid, click.php clickGenerateUuid,
 *    core/click_api.php orbitraClickApiGenerateUuid) all delegate to the one
 *    generator — version 7 through each of them;
 *  - a real click through the router lands in clicks with a uuid7 id.
 *
 * Usage: php tests/click_id_uuid7_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/../core/click_logger.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}
$isUuid7 = static function (string $id): bool {
    return strlen($id) === 36
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id) === 1;
};
$msPrefix = static function (string $id): int {
    return (int) hexdec(substr(str_replace('-', '', $id), 0, 12));
};

// --- unit properties -----------------------------------------------------------
$ids = [];
for ($i = 0; $i < 3000; $i++) {
    $ids[] = orbitraGenerateClickId();
}
$bad = array_filter($ids, fn($id) => !$isUuid7($id));
check(count($bad) === 0, '3000 ids are valid UUIDv7 (36 chars, version 7, variant 10xx)');
$nondecreasing = true;
for ($i = 1, $n = count($ids); $i < $n; $i++) {
    if ($msPrefix($ids[$i]) < $msPrefix($ids[$i - 1])) {
        $nondecreasing = false;
        break;
    }
}
check($nondecreasing, 'the 48-bit ms prefix never decreases across the sequence');
check(count(array_unique($ids)) === count($ids), 'all 3000 ids are unique');
$first = $msPrefix($ids[0]);
$last = $msPrefix($ids[count($ids) - 1]);
check(abs($last - $first) < 60000, "timestamps are 'now' (span {$last}-{$first} ms)");

// --- the three legacy shims delegate to the one generator ----------------------
// (index.php/click.php definitions need their files; load just enough)
$clickPhp = (string) file_get_contents(__DIR__ . '/../click.php');
check(strpos($clickPhp, 'return orbitraGenerateClickId();') !== false, 'click.php clickGenerateUuid delegates to orbitraGenerateClickId');
$indexPhp = (string) file_get_contents(__DIR__ . '/../index.php');
check(strpos($indexPhp, 'return orbitraGenerateClickId();') !== false, 'index.php generateUuid delegates to orbitraGenerateClickId');
$apiPhp = (string) file_get_contents(__DIR__ . '/../core/click_api.php');
check(strpos($apiPhp, 'return orbitraGenerateClickId();') !== false, 'core/click_api.php delegates to orbitraGenerateClickId');

// --- HTTP: the entry point persists uuid7 rows ---------------------------------
$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();
$harness->start();
try {
    $base = $harness->getBaseUrl();
    $db = $harness->getWorkingDir() . '/orbitra_test.sqlite';
    for ($w = 0; $w < 10; $w++) {
        $ctx = stream_context_create(['http' => ['timeout' => 25, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "User-Agent: warm\r\nX-Forwarded-For: 198.52.0.251"]]);
        @file_get_contents($base . '/testcamp?sub1=w0', false, $ctx);
        $p = new PDO('sqlite:' . $db);
        $uv = (int) $p->query('PRAGMA user_version')->fetchColumn();
        $p = null;
        if ($uv > 0) break;
        usleep(400000);
    }
    $seed = $harness->seedTestData();
    $sp = $harness->getPdo();
    $sp->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (990021, 'X', 'https://o.example/?cid={subid}', 0, 'active', 0)")->execute([]);
    $sp->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks) VALUES (990022, ?, 990021, 'S', 'regular', 1, 'redirect', ?, 1, 1)")
        ->execute([(int) $seed['campaign_id'], json_encode(['offers' => [['id' => 990021, 'weight' => 100]]])]);
    $sp->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')")->execute([]);
    unset($sp);

    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "User-Agent: Mozilla/5.0 uuid7test\r\nX-Forwarded-For: 198.52.9.7"]]);
    @file_get_contents($base . '/testcamp?sub1=uuid7probe', false, $ctx);
    $pdo = $harness->getPdo();
    $id = (string) $pdo->query("SELECT id FROM clicks WHERE parameters_json LIKE '%uuid7probe%' ORDER BY rowid DESC LIMIT 1")->fetchColumn();
    check($isUuid7($id), "the persisted click id is UUIDv7 (got: {$id})");
    // and the dropped index is really gone after migration 82
    $idx = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_clicks_ip_created'")->fetchColumn();
    check($idx === false, 'migration 82 dropped idx_clicks_ip_created on the fresh schema');
    // idx_clicks_ip_ua_created is built by the spool worker (migration 55
    // pattern), not by the schema — a fresh install only QUEUES the build.
    $uaState = $pdo->query("SELECT value FROM settings WHERE key = 'clicks_ua_index_state'")->fetchColumn();
    check($uaState === 'pending' || $uaState === 'building' || $uaState === 'backfilling' || $uaState === 'done',
        'the ip_ua index build is queued/built via the spool worker (' . var_export($uaState, true) . ')');
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage());
} finally {
    $harness->stop();
}

echo $failures === 0 ? "Click id UUIDv7 tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
