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

    // --- backfill (acceptance round 2, blocker 4) ------------------------------
    // Legacy rows inside the widest uniqueness window get hashed in rowid
    // batches; rows outside every window stay NULL forever (no probe can
    // reach them). Marker deleted = the backfill walked past everything.
    // 'ua-legacy' from the debounce section above is also in-window NULL —
    // the backfill must hash it too.
    $backfillPdo = $harness->getPdo();
    $backfillPdo->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, ua_hash, created_at)
                            VALUES ('bf-1', ?, '198.51.100.70', 'LegacyAgent/1.0 a', NULL, datetime('now', '-1 hour'))")
        ->execute([$campaignId]);
    $backfillPdo->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, ua_hash, created_at)
                            VALUES ('bf-2', ?, '198.51.100.71', 'LegacyAgent/1.0 b', NULL, datetime('now', '-2 hours'))")
        ->execute([$campaignId]);
    $backfillPdo->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, ua_hash, created_at)
                            VALUES ('bf-old', ?, '198.51.100.72', 'LegacyAgent/1.0 old', NULL, datetime('now', '-40 days'))")
        ->execute([$campaignId]);
    $backfillPdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('clicks_ua_backfill_rowid', '0')")->execute();

    $markerPresent = static function () use ($backfillPdo): bool {
        return $backfillPdo->query("SELECT value FROM settings WHERE key = 'clicks_ua_backfill_rowid'")->fetchColumn() !== false;
    };
    $hashedTotal = 0;
    do {
        // Tiny batches on purpose: the marker must advance between them.
        $n = orbitraBackfillUaHashStep($backfillPdo, 10.0, 2);
        $hashedTotal += $n;
    } while ($n > 0 && $markerPresent());

    $hashOf = static function (string $id) use ($backfillPdo) {
        $stmt = $backfillPdo->prepare('SELECT ua_hash FROM clicks WHERE id = ?');
        $stmt->execute([$id]);
        $hash = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $hash === false || $hash === null ? null : (int) $hash;
    };
    check($hashOf('bf-1') === crc32('LegacyAgent/1.0 a') && $hashOf('bf-2') === crc32('LegacyAgent/1.0 b'),
        'backfill hashed the in-window legacy rows');
    check($hashOf('ua-legacy') === crc32($ua), 'the debounce-section legacy row was in the window and got hashed too');
    check($hashOf('bf-old') === null, 'the out-of-window legacy row stays NULL (no probe can reach it)');
    check(!$markerPresent(), 'the backfill marker is deleted when the window is walked past');
    check(orbitraBackfillUaHashStep($backfillPdo, 5.0) === 0, 'a second backfill step is a no-op');

    // --- the real cron walks the whole state machine in one tick --------------
    // pending -> index -> backfill -> done, exactly what an updated install
    // sees on the next spool-worker tick.
    $work2 = $harness->getWorkingDir();
    @mkdir($work2 . '/cli', 0777, true);
    copy(__DIR__ . '/../cli/click_spool_cron.php', $work2 . '/cli/click_spool_cron.php');
    $backfillPdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('clicks_ua_index_state', 'pending')")->execute();
    $backfillPdo->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, ua_hash, created_at)
                            VALUES ('cron-e2e', ?, '198.51.100.73', 'CronLegacy/3.0', NULL, datetime('now', '-3 hours'))")
        ->execute([$campaignId]);
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work2 . '/cli/click_spool_cron.php') . ' 2>/dev/null', $cronOut, $cronRc);
    check($cronRc === 0, 'the click spool cron ran cleanly');
    $flagAfter = (string) $backfillPdo->query("SELECT value FROM settings WHERE key = 'clicks_ua_index_state'")->fetchColumn();
    check($flagAfter === 'done', "the cron left clicks_ua_index_state = done (got '$flagAfter')");
    $stmt = $backfillPdo->prepare('SELECT ua_hash FROM clicks WHERE id = ?');
    $stmt->execute(['cron-e2e']);
    $e2eHash = $stmt->fetchColumn();
    $stmt->closeCursor();
    check((int) $e2eHash === crc32('CronLegacy/3.0'), 'the cron hashed the in-window legacy row with the exact writer hash');
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage());
} finally {
    $harness->stop();
}

echo $failures === 0 ? "Click UA probe tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
