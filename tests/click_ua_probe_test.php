<?php
/**
 * Blocker 2 of the v1.6.3 acceptance (docs/TZ_LOAD_PERFORMANCE.md): the four
 * IP_UA uniqueness probes must seek an index instead of scanning every click
 * of a busy carrier IP.
 *
 * Every click runs orbitraFindUniquenessConflict plus three
 * orbitraClickIsUnique checks (campaign, stream, global). They all filtered
 * user agents row by row after an (ip, created_at) seek, so a NEW visitor —
 * no match anywhere — paid a full scan of that IP's window for each check.
 * The fix is the ua_hash column (crc32, written at every insert point) and
 * idx_clicks_ip_ua_created (ip, ua_hash, created_at), built once by
 * cli/click_spool_cron.php — never inside a web request.
 *
 * Pins here: both probes (hashed and the NULL-hash rows that predate
 * migration 55) are index SEEKS — a full scan on the NULL probe would be the
 * disaster this index exists to end — the legacy fallback actually finds
 * pre-upgrade rows, every writer stores the hash, and the migration left its
 * build flag behind.
 *
 * Usage: php tests/click_ua_probe_test.php
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
    $seed = $harness->seedTestData();
    $campaignId = (int) $seed['campaign_id'];
    $work = $harness->getWorkingDir();
    require_once $work . '/core/click_logger.php';
    require_once $work . '/core/ClickFlags.php';

    $pdo = $harness->getPdo();

    // Migration 55 must have left the build flag for the spool worker.
    $flag = $pdo->query("SELECT value FROM settings WHERE key = 'clicks_ua_index_state'")->fetchColumn();
    check($flag === 'pending' || $flag === 'building' || $flag === 'done',
        "migration 55 left the clicks_ua_index_state flag ('$flag')");

    // What the spool worker builds once on a real install.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clicks_ip_ua_created ON clicks(ip, ua_hash, created_at)');

    // --- hash helper ----------------------------------------------------------
    $ua = 'Mozilla/5.0 UaProbe/1.0';
    check(orbitraUaHash($ua) === orbitraUaHash($ua) && orbitraUaHash($ua) === crc32($ua),
        'orbitraUaHash is crc32 and deterministic');

    // --- writers store the hash ----------------------------------------------
    $row = orbitraBuildClickRow(['click_id' => 'ua-hashed', 'campaign_id' => $campaignId, 'ip' => '203.0.113.60', 'user_agent' => $ua]);
    check(($row['ua_hash'] ?? null) === orbitraUaHash($ua), 'orbitraBuildClickRow writes ua_hash');
    check(orbitraPersistClick($pdo, $row), 'the hashed click row persisted');
    $stored = $pdo->query("SELECT ua_hash FROM clicks WHERE id = 'ua-hashed'")->fetchColumn();
    check((int) $stored === orbitraUaHash($ua), 'the stored row carries the hash');

    // A row written before migration 55: no hash at all.
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, ua_hash, created_at)
                   VALUES ('ua-legacy', ?, '203.0.113.61', ?, NULL, datetime('now', '-1 hour'))")
        ->execute([$campaignId, $ua]);

    // --- orbitraClickIsUnique: hashed and legacy duplicates -------------------
    $uniq = orbitraClickIsUnique($pdo, '203.0.113.60', $ua, true, 24, ['campaign_id' => $campaignId], 'other-click');
    check($uniq === false, 'IP_UA check finds the hashed duplicate (not unique)');
    $uniqLegacy = orbitraClickIsUnique($pdo, '203.0.113.61', $ua, true, 24, ['campaign_id' => $campaignId], 'other-click');
    check($uniqLegacy === false, 'IP_UA check finds the pre-migration NULL-hash duplicate (not unique)');
    $uniqScope = orbitraClickIsUnique($pdo, '203.0.113.60', $ua, true, 24, ['campaign_id' => $campaignId + 1], 'other-click');
    check($uniqScope === true, 'the same visitor in ANOTHER campaign stays unique');
    $uniqOther = orbitraClickIsUnique($pdo, '203.0.113.62', $ua, true, 24, ['campaign_id' => $campaignId], 'other-click');
    check($uniqOther === true, 'a new visitor behind the same carrier IP stays unique');

    // --- plans: both probes are index seeks -----------------------------------
    $planOf = static function (string $sql, array $params) use ($pdo): string {
        $stmt = $pdo->prepare('EXPLAIN QUERY PLAN ' . $sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return implode(' ', array_map(static fn($r) => (string) ($r['detail'] ?? ''), $rows));
    };

    $hashedProbeSql = "SELECT id FROM clicks WHERE ip = ? AND ua_hash = ? AND user_agent = ? AND created_at >= ? AND (campaign_id = ?) LIMIT 1";
    $hashedPlan = $planOf($hashedProbeSql, ['203.0.113.60', orbitraUaHash($ua), $ua, date('Y-m-d H:i:s', time() - 86400), $campaignId]);
    check(strpos($hashedPlan, 'idx_clicks_ip_ua_created') !== false && strpos($hashedPlan, 'ua_hash=?') !== false,
        "hashed probe seeks the hash index: $hashedPlan");

    // The legacy probe deliberately carries NO campaign scope in SQL (the
    // planner would prefer the campaign index and range over the window) —
    // scope is filtered in PHP over the handful of sought rows.
    $nullProbeSql = "SELECT id, campaign_id, stream_id FROM clicks WHERE ip = ? AND ua_hash IS NULL AND user_agent = ? AND created_at >= ?";
    $nullPlan = $planOf($nullProbeSql, ['203.0.113.61', $ua, date('Y-m-d H:i:s', time() - 86400)]);
    // SQLite prints the IS NULL constraint as ua_hash=? — it is the seek over
    // the NULL range of the index.
    check(strpos($nullPlan, 'SEARCH') !== false && strpos($nullPlan, 'idx_clicks_ip_ua_created') !== false,
        "NULL probe seeks the hash index, scope stays in PHP (no full scan): $nullPlan");

    // --- the router helpers agree ---------------------------------------------
    $conflict = orbitraFindUniquenessConflict($pdo, $campaignId, '203.0.113.60', $ua, 24, 'IP_UA');
    check($conflict === true, 'orbitraFindUniquenessConflict reports the returning visitor');
    $fresh = orbitraFindUniquenessConflict($pdo, $campaignId, '203.0.113.63', $ua, 24, 'IP_UA');
    check($fresh === false, 'orbitraFindUniquenessConflict reports the new visitor as unique');
    $ipOnly = orbitraFindUniquenessConflict($pdo, $campaignId, '203.0.113.60', 'SomeOther/2.0', 24, 'IP');
    check($ipOnly === true, 'IP-only method still settles on the first click of the IP');

    // Freshen the legacy row into the 2-second debounce window.
    $pdo->exec("UPDATE clicks SET created_at = datetime('now') WHERE id = 'ua-legacy'");
    $debounced = orbitraFindDebounceDuplicate($pdo, $campaignId, '203.0.113.61', $ua);
    check($debounced === 'ua-legacy', 'debounce finds the NULL-hash duplicate and returns its id');
    check(orbitraFindDebounceDuplicate($pdo, $campaignId, '203.0.113.63', $ua) === null,
        'debounce passes a genuinely new visitor');
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage());
} finally {
    $harness->stop();
}

echo $failures === 0 ? "Click UA probe tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
