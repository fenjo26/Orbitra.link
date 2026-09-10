<?php
// tests/admin_api_update_costs_test.php
//
// Keitaro Admin API v1 compatibility endpoint (admin_api.php → POST
// /admin_api/v1/campaigns/{id}/update_costs), exercised through the real
// router over HTTP:
//   - a filter key matches clicks.parameters_json directly (sub_id_4) and via
//     the Keitaro FB-template aliases (sub_id_3 → adset_id, the Dolphin /
//     Fbtool defaults), and only within the pushed date window;
//   - the json_extract path is interpolated, not bound: a filter key that is
//     not a plain identifier fails the whole push with 400 — whitelist
//     validation instead of strip-and-try, and never a silent filter drop,
//     which would let the day's spend spread across every campaign click;
//   - cost lands only on the matched clicks (flat CPC, replace semantics);
//   - a read-only API key is rejected with 403.
//
// Run: php tests/admin_api_update_costs_test.php

$testPassed = true;

function assertTrue($condition, string $message) {
    global $testPassed;
    if (!$condition) {
        fwrite(STDERR, "FAILED: $message\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return $condition;
}

function assertEquals($expected, $actual, string $message) {
    global $testPassed;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected: " . var_export($expected, true) . "\n");
        fwrite(STDERR, "  Actual: " . var_export($actual, true) . "\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return $expected === $actual;
}

require_once __DIR__ . '/lib/http.php';

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);
$harness->useProductionRouter();

try {
    echo "Starting test server...\n";
    $harness->start();
    $pdo = $harness->getPdo();

    // --- Seed: one campaign, three clicks with distinct parameter names ---
    $campaignId = random_int(10000, 99999);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived)
                   VALUES (?, ?, ?, ?, 'active', 0)")
        ->execute([$campaignId, 'Admin API Cost Campaign', 'apicostcamp', 'tok_' . bin2hex(random_bytes(4))]);

    // Click 1 arrives under the Fbtool default sub_id_4, click 2 under a bare
    // adset_id (what a Dolphin sub_id_3 alias resolves to), click 3 carries an
    // unrelated parameter only. created_at defaults to now — inside the window.
    $insertClick = $pdo->prepare(
        "INSERT INTO clicks (id, campaign_id, ip, parameters_json, created_at)
         VALUES (?, ?, '127.0.0.1', ?, datetime('now'))"
    );
    $insertClick->execute(['apicost-click-1', $campaignId, json_encode(['sub_id_4' => '777'])]);
    $insertClick->execute(['apicost-click-2', $campaignId, json_encode(['adset_id' => '888'])]);
    $insertClick->execute(['apicost-click-3', $campaignId, json_encode(['noise' => '1'])]);

    // Admin principal with one write and one read API key.
    $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)")
        ->execute(['adminapi_test_admin', password_hash('x', PASSWORD_DEFAULT), 'admin']);
    $adminId = (int) $pdo->lastInsertId();
    $writeKey = 'wkey_' . bin2hex(random_bytes(8));
    $readKey = 'rkey_' . bin2hex(random_bytes(8));
    $insertKey = $pdo->prepare(
        "INSERT INTO user_api_keys (user_id, api_key, key_name, permissions) VALUES (?, ?, ?, ?)"
    );
    $insertKey->execute([$adminId, $writeKey, 'admin-api-write', 'write']);
    $insertKey->execute([$adminId, $readKey, 'admin-api-read', 'read']);

    $today = gmdate('Y-m-d');
    $push = function (array $filters, string $key, ?string $date = null) use ($harness, $campaignId, $today): array {
        $resp = $harness->postWithHeaders(
            "/admin_api.php?route=/campaigns/{$campaignId}/update_costs",
            json_encode([
                'start_date' => $date ?? $today,
                'end_date'   => $date ?? $today,
                'cost'       => 10,
                'currency'   => 'USD',
                'filters'    => $filters,
            ]),
            ['Content-Type: application/json', 'Authorization: Bearer ' . $key]
        );
        $resp['json'] = json_decode($resp['body'], true);
        return $resp;
    };
    $costOf = function (string $clickId) use ($pdo): float {
        $stmt = $pdo->prepare('SELECT cost FROM clicks WHERE id = ?');
        $stmt->execute([$clickId]);
        return (float) $stmt->fetchColumn();
    };

    // ===== Test 1: direct parameter match ==================================
    echo "Test 1: filter key sub_id_4 matches the click's parameter\n";
    $resp = $push(['sub_id_4' => '777'], $writeKey);
    assertEquals(200, $resp['code'], 'update_costs returns 200');
    assertTrue(is_array($resp['json']) && ($resp['json']['success'] ?? false) === true, 'response is a Keitaro-shaped success');
    assertEquals(1, (int) ($resp['json']['clicks'] ?? -1), 'exactly one click matched sub_id_4=777');
    assertTrue($costOf('apicost-click-1') > 0, 'the matched click received the cost');
    assertEquals(0.0, $costOf('apicost-click-3'), 'unrelated click stayed at zero cost');
    echo "\n";

    // ===== Test 2: Keitaro FB-template alias ===============================
    echo "Test 2: sub_id_3 resolves through the Keitaro alias to adset_id\n";
    $resp = $push(['sub_id_3' => '888'], $writeKey);
    assertEquals(1, (int) ($resp['json']['clicks'] ?? -1), 'sub_id_3 matched the adset_id click');
    assertTrue($costOf('apicost-click-2') > 0, 'the adset_id click received the cost');
    echo "\n";

    // ===== Test 3: hostile filter key is rejected, not stripped ============
    // The path is interpolated, not bound. A key that is not a plain
    // identifier must fail the push loudly: stripping it down would match a
    // name nobody sent, and silently dropping the filter would let the whole
    // day's spend spread across every campaign click.
    echo "Test 3: injection-shaped filter key is rejected with 400\n";
    $costBefore = $costOf('apicost-click-2');
    $resp = $push(["x' OR '1'='1" => '999', 'adset_id' => '888'], $writeKey);
    assertEquals(400, $resp['code'], 'hostile key is rejected with 400');
    assertTrue(($resp['json']['success'] ?? null) === false, 'rejection is a Keitaro-shaped error');
    assertEquals($costBefore, $costOf('apicost-click-2'), 'no cost moved on a rejected push');
    assertEquals(0.0, $costOf('apicost-click-3'), 'injection-shaped key widened nothing');
    echo "\n";

    // ===== Test 4: identifier-ish key with a separator is rejected =========
    echo "Test 4: key with a separator character is rejected with 400\n";
    $resp = $push(['sub id 4' => '777'], $writeKey);
    assertEquals(400, $resp['code'], 'spaced key is rejected instead of being mangled into subid4');
    echo "\n";

    // ===== Test 5: read-only key is rejected ===============================
    echo "Test 5: read-only API key gets 403\n";
    $resp = $push(['sub_id_4' => '777'], $readKey);
    assertEquals(403, $resp['code'], 'read-only key cannot push costs');
    echo "\n";

    // ===== Test 6: unknown route ===========================================
    echo "Test 6: unknown route 404s\n";
    $resp = $harness->postWithHeaders(
        '/admin_api.php?route=/campaigns',
        json_encode(['start_date' => $today, 'end_date' => $today, 'cost' => 1]),
        ['Content-Type: application/json', 'Authorization: Bearer ' . $writeKey]
    );
    assertEquals(404, $resp['code'], 'unknown route returns 404');
    echo "\n";
} finally {
    $harness->stop();
}

echo $testPassed ? "\n=== ALL TESTS PASSED ===\n" : "\n=== SOME TESTS FAILED ===\n";
exit($testPassed ? 0 : 1);
