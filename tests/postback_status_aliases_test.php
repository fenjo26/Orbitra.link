<?php
/**
 * Postback built-in status alias test — verifies that the most common network
 * status words map to real conversion statuses out of the box (BIGO and
 * Dr. Cash send "approved", which used to land in 'custom': invisible in
 * revenue counters and filtered out of campaign S2S postbacks configured for
 * sale/rejected).
 *
 * Verified over real HTTP:
 * 1. a configured conversion_type mapping wins over the built-in alias
 *    (the harness seed maps approved→lead; the test adds accepted→trial)
 * 2. an alias word with no configuration maps to sale/rejected on its own
 * 3. an S2S postback listening for "sale" receives the aliased conversion
 * 4. a genuinely unknown status still records as custom
 *
 * Reads use fresh query() statements with literal ids: the ids are generated
 * hex, and a reused prepared statement on this PDO/SQLite build has been
 * observed serving a stale WAL snapshot mid-test.
 *
 * Run with: php tests/postback_status_aliases_test.php
 */

$testPassed = true;

function aliasFail($message) {
    global $testPassed;
    fwrite(STDERR, "FAILED: $message\n");
    $testPassed = false;
}
function aliasOk($message) {
    echo "✓ $message\n";
}
function aliasEquals($expected, $actual, $message) {
    global $testPassed;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAILED: $message\n  Expected: " . var_export($expected, true) . "\n  Actual: " . var_export($actual, true) . "\n");
        $testPassed = false;
        return false;
    }
    aliasOk($message);
    return true;
}

require_once __DIR__ . '/lib/http.php';

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);

try {
    echo "Starting test server...\n";
    $harness->start();

    $data = $harness->seedTestData();
    $postbackKey = $data['postback_key'];
    $campaignId = $data['campaign_id'];
    $offerId = $data['offer_id'];
    $pdo = $harness->getPdo();

    // approved (harness seed: lead), confirmed (nobody), canceled (nobody),
    // accepted (trial below), zzz (nobody, not an alias word).
    $clicks = [];
    foreach (['approved', 'confirmed', 'canceled', 'accepted', 'zzz'] as $word) {
        $clicks[$word] = 'alias-' . $word . '-' . bin2hex(random_bytes(6));
    }
    foreach ($clicks as $cid) {
        $pdo->exec("INSERT INTO clicks (id, campaign_id, offer_id, ip, user_agent, country_code)
            VALUES ('{$cid}', {$campaignId}, {$offerId}, '127.0.0.1', 'Test-Agent/1.0', 'US')");
    }

    // An S2S postback that, like most default configs, only listens for sale.
    // example.com resolves publicly, so the enqueue-time SSRF check passes.
    $pdo->exec("INSERT INTO campaign_postbacks (campaign_id, url, method, statuses)
        VALUES ({$campaignId}, 'https://example.com/conv?cid={subid}', 'GET', 'sale')");

    // An operator mapping that must win over the built-in alias: "accepted"
    // would alias to sale, but a configured type claims it first.
    $pdo->exec("INSERT INTO conversion_types (name, status_values, record_conversion, record_revenue, send_postback, affect_cap)
        VALUES ('trial', 'accepted', 1, 1, 1, 1)");

    $convStatus = static function (string $cid) use ($pdo): ?array {
        foreach ($pdo->query("SELECT status, original_status, payout FROM conversions WHERE click_id = '{$cid}' ORDER BY id DESC LIMIT 1", PDO::FETCH_ASSOC) as $row) {
            return $row;
        }
        return null;
    };

    // 1. Configured mapping wins: the harness seed maps approved→lead, so the
    //    built-in approved→sale alias must NOT fire.
    $resp = $harness->get("/{$postbackKey}/postback?subid={$clicks['approved']}&status=approved&payout=7");
    aliasEquals(200, $resp['code'], 'Approved postback returns 200');
    aliasEquals('lead', $convStatus($clicks['approved'])['status'] ?? null, 'configured conversion_type beats the built-in approved→sale alias');

    // 2a. Unconfigured alias word → sale, and the payout is counted.
    $resp = $harness->get("/{$postbackKey}/postback?subid={$clicks['confirmed']}&status=confirmed&payout=7");
    aliasEquals(200, $resp['code'], 'Confirmed-family postback returns 200');
    $row = $convStatus($clicks['confirmed']);
    aliasEquals('sale', $row['status'] ?? null, 'confirmed maps to sale out of the box');
    aliasEquals('confirmed', $row['original_status'] ?? null, 'original wording preserved');
    foreach ($pdo->query("SELECT revenue, is_conversion FROM clicks WHERE id = '{$clicks['confirmed']}'", PDO::FETCH_ASSOC) as $click) {
        aliasEquals(7.0, (float) $click['revenue'], 'aliased sale payout reaches clicks.revenue');
        aliasEquals(1, (int) $click['is_conversion'], 'aliased sale marks the click converted');
    }

    // 2b. Unconfigured alias word → rejected (declined family).
    $resp = $harness->get("/{$postbackKey}/postback?subid={$clicks['canceled']}&status=canceled&payout=7");
    aliasEquals(200, $resp['code'], 'Canceled postback returns 200');
    aliasEquals('rejected', $convStatus($clicks['canceled'])['status'] ?? null, 'canceled maps to rejected out of the box');

    // 2c. accepted is claimed by the trial type configured above.
    $resp = $harness->get("/{$postbackKey}/postback?subid={$clicks['accepted']}&status=accepted&payout=7");
    aliasEquals(200, $resp['code'], 'Accepted postback returns 200');
    aliasEquals('trial', $convStatus($clicks['accepted'])['status'] ?? null, 'operator type claims a would-be alias word');

    // 3. The sale-only S2S postback received exactly the aliased sale
    //    (confirmed→sale): one queued row for its click id.
    $count = (int) $pdo->query("SELECT COUNT(*) FROM s2s_postbacks_log WHERE url LIKE '%{$clicks['confirmed']}%'")->fetchColumn();
    aliasEquals(1, $count, 'sale-only S2S postback queued for the aliased sale only');

    // 4. A genuinely unknown word still lands in custom.
    $resp = $harness->get("/{$postbackKey}/postback?subid={$clicks['zzz']}&status=zzz&payout=7");
    aliasEquals(200, $resp['code'], 'Unknown status still returns 200');
    aliasEquals('custom', $convStatus($clicks['zzz'])['status'] ?? null, 'unknown status stays custom');
    aliasEquals('zzz', $convStatus($clicks['zzz'])['original_status'] ?? null, 'unknown status keeps original wording');
} finally {
    $harness->stop();
}

echo $testPassed ? "\nALL TESTS PASSED\n" : "\nSOME TESTS FAILED\n";
exit($testPassed ? 0 : 1);
