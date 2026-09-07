<?php
/**
 * Postback FX conversion test — verifies that postback.php converts non-base
 * currency payouts into the tracker's base currency (settings.currency) via
 * core/CurrencyRates.php, keeps the original amount/currency/rate as fx_*
 * audit keys in the click's parameters_json, and leaves same-currency /
 * unknown-currency postbacks untouched.
 *
 * This is a REAL HTTP test: it starts a PHP server, sends actual requests,
 * and asserts on the resulting rows.
 *
 * Run with: php tests/postback_fx_test.php
 */

$testPassed = true;

function assertTrue($condition, $message) {
    global $testPassed;
    if (!$condition) {
        fwrite(STDERR, "FAILED: $message\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return $condition;
}

function assertEquals($expected, $actual, $message) {
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

function assertSimilar($expected, $actual, $delta, $message) {
    global $testPassed;
    if (!is_numeric($actual) || abs((float) $expected - (float) $actual) > $delta) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected ~$expected (±$delta), got: " . var_export($actual, true) . "\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return true;
}

require_once __DIR__ . '/lib/http.php';

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);

try {
    echo "Starting test server...\n";
    $harness->start();

    echo "Seeding test data...\n";
    $data = $harness->seedTestData();
    $postbackKey = $data['postback_key'];
    $clickId = $data['click_id'];
    $clickId2 = 'fx-click-' . bin2hex(random_bytes(8));
    $clickId3 = 'fx-click-' . bin2hex(random_bytes(8));
    $clickId4 = 'fx-click-' . bin2hex(random_bytes(8));
    $campaignId = $data['campaign_id'];
    $offerId = $data['offer_id'];

    $pdo = $harness->getPdo();

    // Pin the FX environment before any request so the test never touches the
    // network: a fresh cached table plus a manual override that must win.
    $now = (string) time();
    $rates = json_encode(['USD' => 1.0, 'UAH' => 40.0, 'EUR' => 0.9]);
    foreach ([
        ['currency', 'USD'],
        ['fx_rates_json', $rates],
        ['fx_rates_updated_at', $now],
        ['fx_rates_manual_json', json_encode(['UAH' => 50.0])],
    ] as [$k, $v]) {
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$k, $v]);
    }

    // Extra clicks for the independent scenarios below.
    foreach ([$clickId2, $clickId3, $clickId4] as $cid) {
        $pdo->prepare("
            INSERT INTO clicks (id, campaign_id, offer_id, ip, user_agent, country_code)
            VALUES (?, ?, ?, '127.0.0.1', 'Test-Agent/1.0', 'US')
        ")->execute([$cid, $campaignId, $offerId]);
    }

    $convRow = function (string $cid) use ($pdo): ?array {
        $stmt = $pdo->prepare("SELECT status, payout, currency FROM conversions WHERE click_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$cid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    };
    $clickParams = function (string $cid) use ($pdo): array {
        $stmt = $pdo->prepare("SELECT parameters_json, revenue FROM clicks WHERE id = ?");
        $stmt->execute([$cid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $decoded = json_decode((string) ($row['parameters_json'] ?? '{}'), true);
        return ['params' => is_array($decoded) ? $decoded : [], 'revenue' => $row['revenue'] ?? null];
    };

    // Test 1: foreign-currency payout is converted into the base currency.
    // Manual override wins: 600 UAH / 50 = 12.00 USD.
    echo "\nTest 1: UAH payout converted to base USD via manual override\n";
    $resp = $harness->get("/{$postbackKey}/postback?subid={$clickId}&status=sale&payout=600&currency=UAH&tid=fx-tid-1");
    assertEquals(200, $resp['code'], 'Converted postback should return 200');
    $row = $convRow($clickId);
    assertTrue($row !== null, 'Conversion row exists');
    assertEquals('sale', $row['status'], 'Status recorded as sale');
    assertSimilar(12.0, $row['payout'], 0.001, 'Payout converted 600 UAH -> 12 USD (manual rate 50 wins)');
    assertEquals('USD', $row['currency'], 'Conversion currency is the base currency');
    $click = $clickParams($clickId);
    // Kept verbatim from the query string, so the raw type (string) is expected.
    assertSimilar(600, $click['params']['fx_orig_payout'] ?? null, 0.001, 'fx_orig_payout audit key holds the raw amount');
    assertEquals('UAH', $click['params']['fx_orig_currency'] ?? null, 'fx_orig_currency audit key holds the raw currency');
    assertSimilar(0.02, $click['params']['fx_rate_used'] ?? null, 0.001, 'fx_rate_used holds the effective rate');
    assertSimilar(12.0, $click['params']['fx_converted_payout'] ?? null, 0.001, 'fx_converted_payout mirrors the stored payout');
    assertSimilar(12.0, $click['revenue'], 0.001, 'clicks.revenue recount uses the converted payout');

    // Test 2: a repeat postback (same tid) re-converts instead of double-applying.
    echo "\nTest 2: repeat postback for the same tid updates the converted payout\n";
    $resp = $harness->get("/{$postbackKey}/postback?subid={$clickId}&status=sale&payout=500&currency=UAH&tid=fx-tid-1");
    assertEquals(200, $resp['code'], 'Repeat postback should return 200');
    $row = $convRow($clickId);
    assertSimilar(10.0, $row['payout'], 0.001, 'Payout re-converted from the new raw amount (500 UAH -> 10 USD)');
    assertEquals('USD', $row['currency'], 'Currency still base');

    // Test 3: same-currency postback passes through untouched, no fx keys.
    echo "\nTest 3: base-currency payout is stored as-is without fx audit keys\n";
    $resp = $harness->get("/{$postbackKey}/postback?subid={$clickId2}&status=sale&payout=7&currency=USD&tid=fx-tid-3");
    assertEquals(200, $resp['code'], 'Same-currency postback should return 200');
    $row = $convRow($clickId2);
    assertSimilar(7.0, $row['payout'], 0.001, 'USD payout stored raw');
    assertEquals('USD', $row['currency'], 'Currency unchanged');
    $click = $clickParams($clickId2);
    assertTrue(!isset($click['params']['fx_orig_currency']), 'No fx_orig_currency key on same-currency conversion');

    // Test 4: an unknown currency is NOT converted and NOT relabeled — payout
    // stays raw with its original currency and no fx keys claim otherwise.
    echo "\nTest 4: unknown currency pair passes through unconverted, unlabeled\n";
    $resp = $harness->get("/{$postbackKey}/postback?subid={$clickId3}&status=sale&payout=5&currency=XYZ&tid=fx-tid-4");
    assertEquals(200, $resp['code'], 'Unknown-currency postback should still return 200');
    $row = $convRow($clickId3);
    assertSimilar(5.0, $row['payout'], 0.001, 'Unknown-currency payout stored raw');
    assertEquals('XYZ', $row['currency'], 'Original currency label preserved (no fake USD)');
    $click = $clickParams($clickId3);
    assertTrue(!isset($click['params']['fx_orig_currency']), 'No fx audit keys when no conversion happened');

    // Test 5: lowercase currency codes convert too.
    echo "\nTest 5: lowercase currency codes convert\n";
    $resp = $harness->get("/{$postbackKey}/postback?subid={$clickId4}&status=sale&payout=250&currency=uah&tid=fx-tid-5");
    assertEquals(200, $resp['code'], 'Lowercase-currency postback should return 200');
    $row = $convRow($clickId4);
    assertSimilar(5.0, $row['payout'], 0.001, '250 uah -> 5 USD');
    assertEquals('USD', $row['currency'], 'Currency normalized to base');

} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: unexpected exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $testPassed = false;
} finally {
    $harness->stop();
}

echo $testPassed ? "\nALL TESTS PASSED\n" : "\nSOME TESTS FAILED\n";
exit($testPassed ? 0 : 1);
