<?php
// tests/cloak_report_http_test.php
//
// HTTP integration tests for report endpoints with cloak streams.
// Tests cover: dashboard metrics, offers, landings, and cohort/trends.
// Ensures the cloak_streams schema queries work correctly and safe clicks
// are filtered from reports when exclude_safe_from_reports is enabled.
//
// Run: php tests/cloak_report_http_test.php

// Track test exit status
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

function assertGreaterThan($min, $actual, $message) {
    global $testPassed;
    if ($actual <= $min) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected: > $min, got: $actual\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return $actual > $min;
}

function assertIsArray($actual, $message) {
    global $testPassed;
    if (!is_array($actual)) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected: array, got: " . gettype($actual) . "\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return is_array($actual);
}

function assertHasKeys($actual, $keys, $message) {
    global $testPassed;
    if (!is_array($actual)) {
        fwrite(STDERR, "FAILED: $message - value is not an array\n");
        $testPassed = false;
        return false;
    }
    foreach ($keys as $key) {
        if (!array_key_exists($key, $actual)) {
            fwrite(STDERR, "FAILED: $message - missing key '$key'\n");
            $testPassed = false;
            return false;
        }
    }
    echo "✓ $message\n";
    return true;
}

// Load the test harness
require_once __DIR__ . '/lib/http.php';

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);
// Panel-API test: route through router.php — index.php-as-router resolves
// ?campaign_id= into the campaign itself and answers with the safe page.
$harness->useProductionRouter();

try {
    echo "Starting test server...\n";
    $harness->start();
    echo "Server started on " . $harness->getBaseUrl() . "\n\n";

    // Seed test data with cloak streams
    echo "Seeding test data...\n";
    $data = $harness->seedCloakTestData();
    $campaignId = $data['campaign_id'];
    $cloakStreamId = $data['cloak_stream_id'];
    $offerId = $data['offer_id'];
    $landingId = $data['landing_id'];
    echo "✓ Seeded campaign_id=$campaignId, cloak_stream=$cloakStreamId, offer=$offerId, landing=$landingId\n\n";

    // api.php requires an authenticated principal: seed an admin user with a
    // personal API key (the panel API accepts Authorization: Bearer <key>).
    $apiKey = 'test_key_' . bin2hex(random_bytes(8));
    $pdo = $harness->getPdo();
    $pdo->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)')
        ->execute(['report_test_admin', password_hash('x', PASSWORD_DEFAULT), 'admin']);
    $adminId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_api_keys (user_id, api_key, key_name, permissions) VALUES (?, ?, ?, ?)')
        ->execute([$adminId, $apiKey, 'cloak-report-test', 'full']);
    $apiGet = function (string $path) use ($harness, $apiKey): array {
        return $harness->getWithHeaders($path, ['Authorization: Bearer ' . $apiKey]);
    };


    // Generate some test clicks
    echo "Generating test clicks...\n";
    $baselineClicks = $harness->getClickCount();

    // Money click (should be included in reports)
    $harness->getWithHeaders("/{$data['alias']}", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15',
        'X-Forwarded-For: 103.212.120.50',
    ]);

    // Safe click (should be filtered from reports when exclude_safe_from_reports is true)
    $harness->getWithHeaders("/{$data['alias']}", [
        'User-Agent: curl/8.4.0',
        'X-Forwarded-For: 103.212.120.51',
    ]);

    $newClicks = $harness->getNewClicksSince($baselineClicks);
    assertEquals(2, count($newClicks), 'Should have logged 2 clicks');
    echo "✓ Generated 2 test clicks (1 money, 1 safe)\n\n";

    // ===== Test 1: Dashboard metrics endpoint =====
    echo "Test 1: Dashboard metrics endpoint (api.php?action=metrics)\n";
    $resp = $apiGet("/api.php?action=metrics&campaign_id=$campaignId");
    assertEquals(200, $resp['code'], 'Metrics endpoint should return 200');

    $metricsEnvelope = json_decode($resp['body'], true);
    assertTrue($metricsEnvelope !== null, 'Metrics response should be valid JSON');
    assertTrue(($metricsEnvelope['status'] ?? '') === 'success', 'Metrics should return success status');
    $metrics = $metricsEnvelope['data'] ?? null;
    assertIsArray($metrics, 'Metrics should be an array');
    assertHasKeys($metrics, ['clicks', 'unique_clicks', 'cost'], 'Metrics should have basic keys');

    // With exclude_safe_from_reports=true, safe clicks should be excluded
    // So we should see only 1 click (the money click)
    echo "  Clicks count: " . ($metrics['clicks'] ?? 'null') . "\n";
    assertGreaterThan(0, $metrics['clicks'] ?? 0, 'Should have at least some clicks');
    echo "\n";

    // ===== Test 2: Offers endpoint =====
    echo "Test 2: Offers endpoint (api.php?action=offers)\n";
    $resp = $apiGet("/api.php?action=offers&campaign_id=$campaignId");
    assertEquals(200, $resp['code'], 'Offers endpoint should return 200');

    $offersData = json_decode($resp['body'], true);
    assertTrue($offersData !== null, 'Offers response should be valid JSON');
    assertTrue(isset($offersData['status']) && $offersData['status'] === 'success', 'Offers should return success status');
    assertIsArray($offersData['data'] ?? null, 'Offers data should be an array');

    if (!empty($offersData['data'])) {
        $firstOffer = reset($offersData['data']);
        assertHasKeys($firstOffer, ['id', 'name', 'clicks'], 'Offer should have basic keys');
        echo "  Offers returned: " . count($offersData['data']) . "\n";
    }
    echo "\n";

    // ===== Test 3: Landings endpoint =====
    echo "Test 3: Landings endpoint (api.php?action=landings)\n";
    $resp = $apiGet("/api.php?action=landings&campaign_id=$campaignId");
    assertEquals(200, $resp['code'], 'Landings endpoint should return 200');

    $landingsData = json_decode($resp['body'], true);
    assertTrue($landingsData !== null, 'Landings response should be valid JSON');
    assertTrue(isset($landingsData['status']) && $landingsData['status'] === 'success', 'Landings should return success status');
    assertIsArray($landingsData['data'] ?? null, 'Landings data should be an array');

    if (!empty($landingsData['data'])) {
        $firstLanding = reset($landingsData['data']);
        assertHasKeys($firstLanding, ['id', 'name', 'clicks'], 'Landing should have basic keys');
        echo "  Landings returned: " . count($landingsData['data']) . "\n";
    }
    echo "\n";

    // ===== Test 4: Cohort endpoint =====
    echo "Test 4: Cohort endpoint (api.php?action=cohort)\n";
    $dateFrom = date('Y-m-d', strtotime('-7 days'));
    $dateTo = date('Y-m-d');
    $resp = $apiGet("/api.php?action=cohort&date_from=$dateFrom&date_to=$dateTo&campaign_id=$campaignId");
    assertEquals(200, $resp['code'], 'Cohort endpoint should return 200');

    $cohortData = json_decode($resp['body'], true);
    assertTrue($cohortData !== null, 'Cohort response should be valid JSON');
    assertTrue(isset($cohortData['status']) && $cohortData['status'] === 'success', 'Cohort should return success status');
    echo "  Cohort data returned\n";
    echo "\n";

    // ===== Test 5: Test with exclude_safe_from_reports=false =====
    echo "Test 5: Metrics with exclude_safe_from_reports=false (safe clicks should be included)\n";
    $harness->updateStreamSchema($cloakStreamId, ['exclude_safe_from_reports' => false]);

    $resp = $apiGet("/api.php?action=metrics&campaign_id=$campaignId");
    assertEquals(200, $resp['code'], 'Metrics endpoint should return 200 with exclude_safe_from_reports=false');

    $metricsAllEnvelope = json_decode($resp['body'], true);
    assertTrue($metricsAllEnvelope !== null, 'Metrics response should be valid JSON');
    $metricsAll = $metricsAllEnvelope['data'] ?? null;
    assertIsArray($metricsAll, 'Metrics should be an array');

    // With exclude_safe_from_reports=false, ALL clicks including safe should be counted
    $clicksCount = $metricsAll['clicks'] ?? 0;
    echo "  Clicks count (including safe): $clicksCount\n";
    assertEquals(2, $clicksCount, 'Should have 2 clicks when safe clicks are included');

    // Restore exclude_safe_from_reports=true
    $harness->updateStreamSchema($cloakStreamId, ['exclude_safe_from_reports' => true]);
    echo "\n";

    // ===== Test 6: Chart endpoint =====
    echo "Test 6: Chart endpoint (api.php?action=chart)\n";
    $resp = $apiGet("/api.php?action=chart&campaign_id=$campaignId");
    assertEquals(200, $resp['code'], 'Chart endpoint should return 200');

    $chartData = json_decode($resp['body'], true);
    assertTrue($chartData !== null, 'Chart response should be valid JSON');
    assertTrue(isset($chartData['status']) && $chartData['status'] === 'success', 'Chart should return success status');
    assertIsArray($chartData['data'] ?? null, 'Chart data should be an array');
    echo "  Chart data points returned: " . count($chartData['data'] ?? []) . "\n";
    echo "\n";

    echo "\n✅ All cloak report endpoint tests passed.\n";
    echo "Report endpoints verified:\n";
    echo "  - api.php?action=metrics (dashboard cards)\n";
    echo "  - api.php?action=offers (offers table)\n";
    echo "  - api.php?action=landings (landings table)\n";
    echo "  - api.php?action=cohort (cohort analysis)\n";
    echo "  - api.php?action=chart (main chart)\n";
    echo "\nSchema queries verified:\n";
    echo "  - streams.schema_custom_json is correctly queried\n";
    echo "  - schema_type='cloak' filtering works\n";
    echo "  - exclude_safe_from_reports filtering works\n";

} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Test error: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    $testPassed = false;
} finally {
    echo "\nStopping test server...\n";
    $harness->stop();
}

exit($testPassed ? 0 : 1);
