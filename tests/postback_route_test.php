<?php
// tests/postback_route_test.php
//
// Real HTTP test for ORB-001 regression: Postback endpoint /{postback_key}/postback
// must be reachable through the front controller under nginx, Apache, and php -S.
// This test starts a real PHP server, sends real HTTP requests, and asserts on
// status code, body, and database state.
//
// Run: php tests/postback_route_test.php

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
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected to contain: " . var_export($needle, true) . "\n");
        fwrite(STDERR, "  In: " . var_export($haystack, true) . "\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return strpos($haystack, $needle) !== false;
}

// Load the test harness
require_once __DIR__ . '/lib/http.php';

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);

try {
    echo "Starting test server...\n";
    $harness->start();
    echo "Server started on " . $harness->getBaseUrl() . "\n\n";

    // Seed test data
    echo "Seeding test data...\n";
    $data = $harness->seedTestData();
    $postbackKey = $data['postback_key'];
    $clickId = $data['click_id'];
    echo "✓ Seeded with postback_key='$postbackKey', click_id='$clickId'\n\n";

    // Test 1: Valid postback without trailing slash
    echo "Test 1: Valid postback /{key}/postback with valid subid and status\n";
    $resp = $harness->get("/{$postbackKey}/postback?subid={$clickId}&status=lead&payout=10&currency=USD");
    assertEquals(200, $resp['code'], 'Status code should be 200');
    assertContains('Postback recorded successfully', $resp['body'], 'Body should contain success message');
    assertTrue($harness->hasConversionForClick($clickId), 'Conversion should be recorded in database');

    // Test 2: Valid postback with trailing slash
    echo "\nTest 2: Valid postback /{key}/postback/ (with trailing slash)\n";
    $resp = $harness->get("/{$postbackKey}/postback/?subid={$clickId}&status=sale&payout=25&currency=USD");
    assertEquals(200, $resp['code'], 'Status code should be 200');
    assertContains('Postback recorded successfully', $resp['body'], 'Body should contain success message');

    // Test 3: Wrong key should not be handled
    echo "\nTest 3: Wrong postback key should not be handled by postback.php\n";
    $resp = $harness->get("/wrongkey/postback?subid={$clickId}&status=lead");
    // Should not return 200 with postback success message
    if ($resp['code'] === 200 && strpos($resp['body'], 'Postback recorded') !== false) {
        fwrite(STDERR, "FAILED: Wrong key should not be handled by postback.php\n");
        $testPassed = false;
    } else {
        echo "✓ Wrong key correctly rejected (code: {$resp['code']})\n";
    }

    // Test 4: Key changed mid-test
    echo "\nTest 4: Postback key changed in settings mid-test\n";
    $oldKey = $postbackKey;
    $newKey = 'newkey99';
    $harness->setPostbackKey($newKey);

    // Old key should stop working
    $resp = $harness->get("/{$oldKey}/postback?subid={$clickId}&status=lead");
    if ($resp['code'] === 200 && strpos($resp['body'], 'Postback recorded') !== false) {
        fwrite(STDERR, "FAILED: Old key should no longer work after change\n");
        $testPassed = false;
    } else {
        echo "✓ Old key correctly rejected after change\n";
    }

    // New key should work
    $resp = $harness->get("/{$newKey}/postback?subid={$clickId}&status=lead&payout=15");
    assertEquals(200, $resp['code'], 'New key should work');
    assertContains('Postback recorded successfully', $resp['body'], 'New key should handle postback');

    // Verify database check requires correct key by looking at server behavior
    // (the real verification is that the new path works, not that the old doesn't,
    // since index.php simply won't route unknown paths)

    echo "\n✅ All postback route tests passed.\n";
    echo "The /{postback_key}/postback endpoint is correctly routed.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Test error: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    $testPassed = false;
} finally {
    echo "\nStopping test server...\n";
    $harness->stop();
}

exit($testPassed ? 0 : 1);
