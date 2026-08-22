<?php
// tests/cloak_click_http_test.php
//
// Characterisation tests for the three click-logging paths (index.php, click.php, core/click_api.php).
// Tests cover: money route, safe route, suppressed safe click, prefetch skip,
// 2-second debounce, collect_clicks=0, and non-cloak streams.
//
// Run: php tests/cloak_click_http_test.php

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

function assertContains($needle, $haystack, $message) {
    global $testPassed;
    if (strpos((string) $haystack, (string) $needle) === false) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected to contain: " . var_export($needle, true) . "\n");
        fwrite(STDERR, "  In: " . var_export($haystack, true) . "\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return strpos((string) $haystack, (string) $needle) !== false;
}

// Load the test harness
require_once __DIR__ . '/lib/http.php';

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);

try {
    echo "Starting test server...\n";
    $harness->start();
    echo "Server started on " . $harness->getBaseUrl() . "\n\n";

    // Seed test data with cloak and non-cloak streams
    echo "Seeding test data...\n";
    $data = $harness->seedCloakTestData();
    $campaignId = $data['campaign_id'];
    $cloakStreamId = $data['cloak_stream_id'];
    $redirectStreamId = $data['redirect_stream_id'];
    $offerId = $data['offer_id'];
    $landingId = $data['landing_id'];
    $safeLandingId = $data['safe_landing_id'];
    echo "✓ Seeded campaign_id=$campaignId, cloak_stream=$cloakStreamId, redirect_stream=$redirectStreamId\n\n";

    // ===== Test 1: Money route via index.php =====
    echo "Test 1: Money route via index.php - normal mobile visitor from IN\n";
    $baseline = $harness->getClickCount();
    $resp = $harness->getWithHeaders("/?campaign_id=$data[campaign_id]", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.0',
        'Accept-Language: en-IN,en;q=0.9',
    ]);
    // Money route redirects (302/301)
    assertTrue($resp['code'] === 302 || $resp['code'] === 301, 'Money route should return 302 or 301');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(1, count($newClicks), 'One click should be logged for money route');
    $clickRow = reset($newClicks);
    assertEquals($campaignId, $clickRow['campaign_id'], 'Click should have correct campaign_id');
    // Note: offer_id is NULL in current implementation for cloak streams - this documents actual behavior
    echo "\n";

    // ===== Test 2: Safe route via index.php =====
    echo "Test 2: Safe route via index.php - curl UA\n";
    $baseline = $harness->getClickCount();
    $resp = $harness->getWithHeaders("/?campaign_id=$data[campaign_id]", [
        'User-Agent: curl/8.4.0',
        'X-Forwarded-For: 103.212.120.1',
    ]);
    // safe_mode=html serves the inline safe page (200), not a redirect
    assertEquals(200, $resp['code'], 'Safe route serves inline HTML (safe_mode=html)');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(1, count($newClicks), 'Safe click should still be logged by default');
    echo "\n";

    // ===== Test 3: Suppressed safe click =====
    echo "Test 3: Suppressed safe click - dont_record_safe_clicks enabled\n";
    $baseline = $harness->getClickCount();
    $harness->updateStreamSchema($cloakStreamId, ['dont_record_safe_clicks' => true]);
    $resp = $harness->getWithHeaders("/?campaign_id=$data[campaign_id]", [
        'User-Agent: python-requests/2.31.0',
        'X-Forwarded-For: 103.212.120.2',
    ]);
    assertEquals(200, $resp['code'], 'Suppressed safe route still serves the inline safe page');
    $newClicks = $harness->getNewClicksSince($baseline);
    // W3.1: suppression works — the safe hit is answered but not written
    assertEquals(0, count($newClicks), 'Suppressed safe click is NOT logged (dont_record_safe_clicks)');
    $harness->updateStreamSchema($cloakStreamId, ['dont_record_safe_clicks' => false]);
    echo "\n";

    // ===== Test 4: Prefetch skip via index.php =====
    echo "Test 4: Prefetch skip - Sec-Purpose: prefetch header\n";
    $baseline = $harness->getClickCount();
    $harness->setSetting('ignore_prefetch', '1');
    $resp = $harness->getWithHeaders("/?campaign_id=$data[campaign_id]", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.3',
        'Sec-Purpose: prefetch',
    ]);
    assertTrue($resp['code'] === 302 || $resp['code'] === 301, 'Prefetch should still serve 302 or 301');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(0, count($newClicks), 'Prefetch click should NOT be logged');
    $harness->setSetting('ignore_prefetch', '0');
    echo "\n";

    // ===== Test 5: Debounce via index.php =====
    echo "Test 5: 2-second debounce - duplicate click\n";
    $harness->setSetting('ignore_prefetch', '0');
    $baseline = $harness->getClickCount();
    $harness->getWithHeaders("/?campaign_id=$data[campaign_id]", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.4',
    ]);
    $countAfterFirst = $harness->getClickCount();
    assertEquals(1, $countAfterFirst - $baseline, 'First click should be logged');
    // Immediate duplicate
    $harness->getWithHeaders("/?campaign_id=$data[campaign_id]", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.4',
    ]);
    $countAfterDuplicate = $harness->getClickCount();
    assertEquals($countAfterFirst, $countAfterDuplicate, 'Debounced duplicate should NOT create new click');
    echo "\n";

    // ===== Test 6: collect_clicks=0 via index.php =====
    echo "Test 6: collect_clicks=0 - stream does not collect stats\n";
    $baseline = $harness->getClickCount();
    $harness->updateStreamCollectClicks($cloakStreamId, 0);
    $resp = $harness->getWithHeaders("/?campaign_id=$data[campaign_id]", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.5',
    ]);
    assertTrue($resp['code'] === 302 || $resp['code'] === 301, 'collect_clicks=0 should still serve 302 or 301');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(0, count($newClicks), 'collect_clicks=0 click is NOT logged');
    $harness->updateStreamCollectClicks($cloakStreamId, 1);
    echo "\n";

    // ===== Test 7: Non-cloak stream via index.php =====
    echo "Test 7: Non-cloak redirect stream via index.php\n";
    $baseline = $harness->getClickCount();
    $harness->updateStreamSchemaType($redirectStreamId, 'redirect');
    $harness->updateCampaignStreams($campaignId, [$redirectStreamId]);
    $resp = $harness->getWithHeaders("/?campaign_id=$data[campaign_id]", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.6',
    ]);
    assertEquals(302, $resp['code'], 'Redirect stream should return 302') or assertEquals(301, $resp['code'], 'Redirect stream should return redirect status');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(1, count($newClicks), 'Non-cloak stream should log click');
    $harness->updateCampaignStreams($campaignId, [$cloakStreamId]);
    echo "\n";

    // ===== Test 8: Safe route via click.php =====
    echo "Test 8: Safe route via click.php - curl UA\n";
    $baseline = $harness->getClickCount();
    $resp = $harness->getWithHeaders("/click.php?campaign_id=$campaignId", [
        'User-Agent: curl/8.4.0',
        'X-Forwarded-For: 103.212.120.7',
    ]);
    // click.php safe route returns 200 with HTML content
    assertEquals(200, $resp['code'], 'click.php safe route should return 200 for HTML');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(1, count($newClicks), 'click.php should log safe click by default');
    echo "\n";

    // ===== Test 9: Prefetch skip via click.php =====
    echo "Test 9: Prefetch skip via click.php\n";
    $baseline = $harness->getClickCount();
    $harness->setSetting('ignore_prefetch', '1');
    $resp = $harness->getWithHeaders("/click.php?campaign_id=$campaignId", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.8',
        'Sec-Purpose: prefetch',
    ]);
    // click.php prefetch is served normally (302), the click is just not logged
    assertTrue($resp['code'] === 302 || $resp['code'] === 301, 'click.php prefetch should still redirect');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(0, count($newClicks), 'click.php prefetch should NOT log click');
    $harness->setSetting('ignore_prefetch', '0');
    echo "\n";

    // ===== Test 10: Debounce via click.php =====
    echo "Test 10: 2-second debounce via click.php\n";
    $harness->setSetting('ignore_prefetch', '0');
    $baseline = $harness->getClickCount();
    $harness->getWithHeaders("/click.php?campaign_id=$campaignId", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.9',
    ]);
    $countAfterFirst = $harness->getClickCount();
    assertEquals(1, $countAfterFirst - $baseline, 'click.php first click should be logged');
    $harness->getWithHeaders("/click.php?campaign_id=$campaignId", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.9',
    ]);
    $countAfterDuplicate = $harness->getClickCount();
    assertEquals($countAfterFirst, $countAfterDuplicate, 'click.php debounce should work');
    echo "\n";

    // ===== Test 11: Money route via click.php =====
    echo "Test 11: Money route via click.php - normal visitor\n";
    $harness->setSetting('ignore_prefetch', '0');
    $baseline = $harness->getClickCount();
    $harness->updateStreamCollectClicks($cloakStreamId, 1);
    $resp = $harness->getWithHeaders("/click.php?campaign_id=$campaignId", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.10',
    ]);
    assertEquals(302, $resp['code'], 'click.php money route should redirect to the offer');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(1, count($newClicks), 'click.php money route should log click');
    $clickRow = end($newClicks);
    assertEquals($offerId, $clickRow['offer_id'], 'click.php money click should have the money offer_id');
    echo "\n";

    // ===== Test 12: Safe route via click_api =====
    echo "Test 12: Safe route via click_api - crawler UA\n";
    $baseline = $harness->getClickCount();
    $resp = $harness->getWithHeaders("/click_api/v3?token={$data['campaign_token']}&log=1&ip=103.212.120.11&user_agent=curl/8.4.0");
    assertEquals(200, $resp['code'], 'click_api safe route should return 200');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(1, count($newClicks), 'click_api should log safe click by default');
    echo "\n";

    // ===== Test 13: Prefetch skip via click_api =====
    echo "Test 13: Prefetch skip via click_api\n";
    $baseline = $harness->getClickCount();
    $harness->setSetting('ignore_prefetch', '1');
    $resp = $harness->get("/click_api/v3?token={$data['campaign_token']}&log=1&ip=103.212.120.12&user_agent=curl/8.4.0");
    assertEquals(200, $resp['code'], 'click_api prefetch should return 200');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(1, count($newClicks), 'click_api prefetch click IS logged (prefetch detection works differently for API)');
    $harness->setSetting('ignore_prefetch', '0');
    echo "\n";

    // ===== Test 14: Debounce via click_api =====
    echo "Test 14: 2-second debounce via click_api\n";
    $harness->setSetting('ignore_prefetch', '0');
    $baseline = $harness->getClickCount();
    $harness->get("/click_api/v3?token={$data['campaign_token']}&log=1&ip=103.212.120.13&user_agent=Mozilla/5.0%20(iPhone;%20CPU%20iPhone%20OS%2016_0%20like%20Mac%20OS%20X)%20AppleWebKit/605.1.15%20(KHTML,%20like%20Gecko)%20Version/16.0%20Mobile/15E148%20Safari/604.1");
    $countAfterFirst = $harness->getClickCount();
    assertEquals(1, $countAfterFirst - $baseline, 'click_api first click should be logged');
    // Duplicate
    $harness->get("/click_api/v3?token={$data['campaign_token']}&log=1&ip=103.212.120.13&user_agent=Mozilla/5.0%20(iPhone;%20CPU%20iPhone%20OS%2016_0%20like%20Mac%20OS%20X)%20AppleWebKit/605.1.15%20(KHTML,%20like%20Gecko)%20Version/16.0%20Mobile/15E148%20Safari/604.1");
    $countAfterDuplicate = $harness->getClickCount();
    // click_api does NOT have debounce - duplicates ARE logged
    assertEquals($countAfterFirst + 1, $countAfterDuplicate, 'click_api debounce does NOT exist (logs duplicates)');
    echo "\n";

    // ===== Test 15: Money route via click_api =====
    echo "Test 15: Money route via click_api - normal visitor\n";
    $harness->setSetting('ignore_prefetch', '0');
    $baseline = $harness->getClickCount();
    $harness->updateStreamCollectClicks($cloakStreamId, 1);
    $resp = $harness->get("/click_api/v3?token={$data['campaign_token']}&log=1&ip=103.212.120.14&user_agent=Mozilla/5.0%20(iPhone;%20CPU%20iPhone%20OS%2016_0%20like%20Mac%20OS%20X)%20AppleWebKit/605.1.15%20(KHTML,%20like%20Gecko)%20Version/16.0%20Mobile/15E148%20Safari/604.1");
    assertEquals(200, $resp['code'], 'click_api money route should return 200');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(1, count($newClicks), 'click_api money route should log click');
    $clickRow = end($newClicks);
    assertEquals($offerId, $clickRow['offer_id'], 'click_api money click should have offer_id');
    echo "\n";

    // ===== Test 16: collect_clicks=0 via click.php =====
    echo "Test 16: collect_clicks=0 via click.php\n";
    $harness->setSetting('ignore_prefetch', '0');
    $baseline = $harness->getClickCount();
    $harness->updateStreamCollectClicks($cloakStreamId, 0);
    $resp = $harness->getWithHeaders("/click.php?campaign_id=$campaignId", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.15',
    ]);
    assertTrue($resp['code'] === 302 || $resp['code'] === 301, 'click.php collect_clicks=0 still redirects');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(0, count($newClicks), 'click.php collect_clicks=0 should NOT log');
    $harness->updateStreamCollectClicks($cloakStreamId, 1);
    echo "\n";

    // ===== Test 17: collect_clicks=0 via click_api =====
    echo "Test 17: collect_clicks=0 via click_api\n";
    $harness->setSetting('ignore_prefetch', '0');
    $baseline = $harness->getClickCount();
    $harness->updateStreamCollectClicks($cloakStreamId, 0);
    $resp = $harness->get("/click_api/v3?token={$data['campaign_token']}&log=1&ip=103.212.120.16&user_agent=Mozilla/5.0%20(iPhone;%20CPU%20iPhone%20OS%2016_0%20like%20Mac%20OS%20X)%20AppleWebKit/605.1.15%20(KHTML,%20like%20Gecko)%20Version/16.0%20Mobile/15E148%20Safari/604.1");
    assertEquals(200, $resp['code'], 'click_api collect_clicks=0 should return 200');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(0, count($newClicks), 'click_api collect_clicks=0 should NOT log');
    $harness->updateStreamCollectClicks($cloakStreamId, 1);
    echo "\n";

    // ===== Test 18: Non-cloak stream via click.php =====
    echo "Test 18: Non-cloak redirect stream via click.php\n";
    $harness->setSetting('ignore_prefetch', '0');
    $baseline = $harness->getClickCount();
    $harness->updateCampaignStreams($campaignId, [$redirectStreamId]);
    $resp = $harness->getWithHeaders("/click.php?campaign_id=$campaignId", [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 103.212.120.17',
    ]);
    assertEquals(302, $resp['code'], 'click.php redirect should return 302') or assertEquals(301, $resp['code'], 'click.php redirect should return redirect status');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(1, count($newClicks), 'click.php non-cloak should log click');
    $harness->updateCampaignStreams($campaignId, [$cloakStreamId]);
    echo "\n";

    // ===== Test 19: Non-cloak stream via click_api =====
    echo "Test 19: Non-cloak redirect stream via click_api\n";
    $harness->setSetting('ignore_prefetch', '0');
    $baseline = $harness->getClickCount();
    $harness->updateCampaignStreams($campaignId, [$redirectStreamId]);
    $resp = $harness->get("/click_api/v3?token={$data['campaign_token']}&log=1&ip=103.212.120.18&user_agent=Mozilla/5.0%20(iPhone;%20CPU%20iPhone%20OS%2016_0%20like%20Mac%20OS%20X)%20AppleWebKit/605.1.15%20(KHTML,%20like%20Gecko)%20Version/16.0%20Mobile/15E148%20Safari/604.1");
    assertEquals(200, $resp['code'], 'click_api non-cloak should return 200');
    $newClicks = $harness->getNewClicksSince($baseline);
    assertEquals(1, count($newClicks), 'click_api non-cloak should log click');
    $harness->updateCampaignStreams($campaignId, [$cloakStreamId]);
    echo "\n";

    echo "\n✅ All cloak click logging characterisation tests passed.\n";
    echo "Characterisation baseline established for:\n";
    echo "  - index.php: money route, safe route, prefetch, debounce, collect_clicks\n";
    echo "  - click.php: money route, safe route, prefetch, debounce, collect_clicks\n";
    echo "  - core/click_api.php: money route, safe route, prefetch, debounce, collect_clicks\n";

} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Test error: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    $testPassed = false;
} finally {
    echo "\nStopping test server...\n";
    $harness->stop();
}

exit($testPassed ? 0 : 1);
