<?php
/**
 * Stage 2 of docs/TZ_LOAD_PERFORMANCE.md: browser debounce keyed by
 * ip + user_agent, with the stored click id reused on a hit.
 *
 * The old debounce key was IP only. Behind one mobile carrier IP (CGNAT) a
 * second visitor within the 2-second window got no clicks row but still a
 * redirect carrying a freshly generated subid — a conversion from that
 * redirect had no click to attach to. Now the key is campaign + ip + UA, and
 * a debounced hit reuses the stored click id, so every cid handed out in a
 * 302 exists in clicks.
 *
 * Runs over real HTTP against the production router (php -S), the same way
 * the load test drives it. Also pins the query plan: the debounce lookup
 * must keep using idx_clicks_ip_created (no new index needed for the extra
 * user_agent equality) and must not leak its cursor.
 *
 * Usage: php tests/click_debounce_test.php
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

/**
 * GET without following redirects, with arbitrary headers — the offer hosts
 * are unreachable on purpose and the thing under test is the FIRST response.
 */
$getNoFollow = static function (string $path, array $headers = []) use ($harness): array {
    $ctx = stream_context_create(['http' => [
        'timeout' => 10,
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 0,
        'header' => implode("\r\n", $headers),
    ]]);
    $body = @file_get_contents($harness->getBaseUrl() . $path, false, $ctx);
    $code = 0;
    $flat = [];
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\d\.\d (\d+)#', $h, $m)) {
            $code = (int) $m[1];
        }
        if (strpos($h, ':') !== false) {
            [$k, $v] = explode(':', $h, 2);
            $flat[trim($k)] = trim($v);
        }
    }
    return ['code' => $code, 'body' => (string) $body, 'headers' => $flat];
};

try {
    $seed = $harness->seedTestData();
    $campaignId = (int) $seed['campaign_id'];
    $work = $harness->getWorkingDir();
    require_once $work . '/core/click_logger.php';

    $pdo = $harness->getPdo();
    // Mirror the load-test campaign: returning visitors matter, uniqueness is
    // IP_UA so the uniqueness lookup runs on every request.
    $pdo->prepare("UPDATE campaigns SET uniqueness_hours = 24, uniqueness_method = 'IP_UA' WHERE id = ?")
        ->execute([$campaignId]);
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')")->execute();

    $offerId = 990001;
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, 'Debounce Offer', 'https://offer.example/post?cid={subid}', 0, 'active', 0)")
        ->execute([$offerId]);
    $streamId = 990002;
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks)
                   VALUES (?, ?, ?, 'Debounce Stream', 'regular', 1, 'redirect', ?, 1, 1)")
        ->execute([$streamId, $campaignId, $offerId, json_encode(['offers' => [['id' => $offerId, 'weight' => 100]]])]);

    $ip = '203.0.113.77';
    $uaA = 'Mozilla/5.0 DebounceTest UA-A';
    $uaB = 'Mozilla/5.0 DebounceTest UA-B';

    $hit = static function (string $ua) use ($getNoFollow) {
        // seedTestData() always names its campaign 'testcamp'.
        return $getNoFollow('/testcamp?sub1=deb', [
            'User-Agent: ' . $ua,
            'X-Forwarded-For: 203.0.113.77',
        ]);
    };
    $cidOf = static function (array $resp): string {
        parse_str((string) parse_url($resp['headers']['Location'] ?? '', PHP_URL_QUERY), $q);
        return (string) ($q['cid'] ?? '');
    };
    // seedTestData() plants one click of its own in this campaign — exclude it.
    $seedClickId = (string) $seed['click_id'];
    $clickRows = static function () use ($pdo, $campaignId, $seedClickId): array {
        $stmt = $pdo->prepare("SELECT id, user_agent FROM clicks WHERE campaign_id = ? AND id != ? ORDER BY created_at, id");
        $stmt->execute([$campaignId, $seedClickId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $rows;
    };

    // --- Case A: same ip + UA twice within a second → one row, same cid -------
    $r1 = $hit($uaA);
    $r2 = $hit($uaA);
    $cid1 = $cidOf($r1);
    $cid2 = $cidOf($r2);
    check($r1['code'] === 302 && $r2['code'] === 302, 'both returning-visitor requests answered 302');
    check($cid1 !== '' && $cid1 === $cid2, "both redirects carry the SAME cid ($cid1 / $cid2)");
    $rows = $clickRows();
    check(count($rows) === 1, 'exactly one clicks row for the duplicated visit (got ' . count($rows) . ')');
    $allIds = static function (array $rows): array {
        return array_map(static fn($r) => $r['id'], $rows);
    };
    check(in_array($cid1, $allIds($rows), true), 'the cid handed out exists in clicks');

    // --- Case B: same ip, DIFFERENT UA behind the same carrier NAT ------------
    $r3 = $hit($uaB);
    $cid3 = $cidOf($r3);
    check($r3['code'] === 302 && $cid3 !== '' && $cid3 !== $cid1, 'the second person behind the NAT got their own cid');
    $rows = $clickRows();
    check(count($rows) === 2, 'two clicks rows after the different-UA visit (got ' . count($rows) . ')');
    check(in_array($cid3, $allIds($rows), true), 'the NAT neighbour cid exists in clicks (conversion can attach)');

    // --- Case C: same ip + UA after the 2-second window -----------------------
    sleep(3);
    $r4 = $hit($uaA);
    $cid4 = $cidOf($r4);
    check($r4['code'] === 302 && $cid4 !== '' && $cid4 !== $cid1, 'after the window the same visitor is a new click again');
    $rows = $clickRows();
    check(count($rows) === 3, 'three clicks rows after the window passed (got ' . count($rows) . ')');

    // --- Query plan: the ip-leading index still serves the debounce key -------
    $plan = $pdo->query("EXPLAIN QUERY PLAN SELECT id FROM clicks WHERE ip = 'x' AND campaign_id = 1 AND user_agent = 'u' AND created_at >= datetime('now', '-2 seconds') LIMIT 1")
        ->fetchAll(PDO::FETCH_ASSOC);
    $planText = implode(' ', array_map(static fn($r) => (string) ($r['detail'] ?? ''), $plan));
    check(strpos($planText, 'idx_clicks_ip_created') !== false,
        'debounce lookup uses idx_clicks_ip_created (plan: ' . $planText . ')');

    // --- Cursor discipline: a write may follow the debounce check -------------
    // Same shape the snapshot test pins for uniqueness: the helper must not
    // leave a WAL snapshot open, or this INSERT would fail instantly.
    $pdoB = $harness->getPdo();
    $pdoB->exec('PRAGMA busy_timeout = 5000');
    $dup = orbitraFindDebounceDuplicate($pdo, $campaignId, $ip, $uaA);
    check($dup !== null, 'debounce helper returns the stored id of the recent click');
    $pdoB->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, created_at)
                    VALUES ('deb-other', ?, '198.51.100.5', 'OtherAgent/1.0', datetime('now'))")
        ->execute([$campaignId]);
    $persisted = orbitraPersistClick($pdo, orbitraBuildClickRow([
        'click_id' => 'deb-after-check',
        'campaign_id' => $campaignId,
        'ip' => '203.0.113.78',
        'user_agent' => $uaA,
    ]));
    check($persisted, 'persisting right after the debounce check succeeded (cursor closed)');
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage());
} finally {
    $harness->stop();
}

echo $failures === 0 ? "Click debounce tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
