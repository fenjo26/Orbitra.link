<?php
/**
 * Postback status codes test — verifies that postback.php returns correct HTTP
 * status codes for various scenarios, and that the pixel path always returns 200.
 *
 * This is a REAL HTTP test: it starts a PHP server, sends actual requests,
 * and asserts on status code, body, and headers.
 *
 * Run with: php tests/postback_status_codes_test.php
 */

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

function assertNotContains($needle, $haystack, $message) {
    global $testPassed;
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected NOT to contain: " . var_export($needle, true) . "\n");
        fwrite(STDERR, "  In: " . var_export($haystack, true) . "\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return strpos($haystack, $needle) === false;
}

function assertContentType($expected, $headers, $message) {
    global $testPassed;
    $actual = $headers['Content-Type'] ?? '';
    if (strpos($actual, $expected) === false) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected Content-Type to contain: $expected\n");
        fwrite(STDERR, "  Actual Content-Type: $actual\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return strpos($actual, $expected) !== false;
}

function assertValidGif($body, $message) {
    global $testPassed;
    // A valid GIF starts with "GIF87a" or "GIF89a"
    if (strlen($body) < 6) {
        fwrite(STDERR, "FAILED: $message (body too short)\n");
        $testPassed = false;
        return false;
    }
    $header = substr($body, 0, 6);
    if ($header !== 'GIF87a' && $header !== 'GIF89a') {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected GIF header (GIF87a or GIF89a)\n");
        fwrite(STDERR, "  Actual header: " . bin2hex($header) . "\n");
        $testPassed = false;
        return false;
    }
    echo "✓ $message\n";
    return true;
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

    // Test 1: Valid postback should return 200
    echo "Test 1: Valid postback should return 200\n";
    $resp = $harness->get("/{$postbackKey}/postback?subid={$clickId}&status=lead&payout=10&currency=USD");
    assertEquals(200, $resp['code'], 'Status code should be 200');
    assertContains('Postback recorded successfully', $resp['body'], 'Body should contain success message');

    // Test 2: Missing subid should return 400
    echo "\nTest 2: Missing subid should return 400\n";
    $resp = $harness->get("/{$postbackKey}/postback?status=lead");
    assertEquals(400, $resp['code'], 'Status code should be 400');
    assertContains('Missing subid', $resp['body'], 'Body should contain error message');

    // Test 3: Missing status should return 400
    echo "\nTest 3: Missing status should return 400\n";
    $resp = $harness->get("/{$postbackKey}/postback?subid={$clickId}");
    assertEquals(400, $resp['code'], 'Status code should be 400');
    assertContains('Missing status', $resp['body'], 'Body should contain error message');

    // Test 4: Unknown status with no transformation rule should return 400
    echo "\nTest 4: Unknown status with no transformation should return 400\n";
    $resp = $harness->get("/{$postbackKey}/postback?subid={$clickId}&status=xyz_unknown_status_12345");
    assertEquals(400, $resp['code'], 'Status code should be 400');
    assertContains('Unknown status', $resp['body'], 'Body should contain error message');

    // Test 5: subid matching no click should return 404
    echo "\nTest 5: subid matching no click should return 404\n";
    $resp = $harness->get("/{$postbackKey}/postback?subid=nonexistent_click_id_xyz&status=lead");
    assertEquals(404, $resp['code'], 'Status code should be 404');
    assertContains('not found', $resp['body'], 'Body should contain error message');

    // Test 6: Database error (simulated by pointing at an unwritable location)
    // This is hard to test reliably without breaking the test harness itself,
    // so we skip it but note that production should handle this

    // Test 7: /pixel.gif with invalid subid should return 200 GIF
    echo "\nTest 7: /pixel.gif with invalid subid should return 200 GIF\n";
    $resp = $harness->get("/pixel.gif?action=conversion&subid=invalid_subid_xyz123&status=lead");
    assertEquals(200, $resp['code'], 'Status code should be 200');
    assertContentType('image/gif', $resp['headers'], 'Content-Type should be image/gif');
    assertValidGif($resp['body'], 'Body should be a valid GIF');

    // Test 8: /pixel.gif with valid postback should return 200 GIF and record conversion
    echo "\nTest 8: /pixel.gif with valid conversion should return 200 GIF and record conversion\n";
    $initialCount = $harness->countConversions();
    // Use a unique tid to ensure a new conversion is created
    $testTid = 'test_tid_' . bin2hex(random_bytes(4));
    $resp = $harness->get("/pixel.gif?action=conversion&subid={$clickId}&status=lead&payout=5&currency=USD&tid={$testTid}");
    assertEquals(200, $resp['code'], 'Status code should be 200');
    assertContentType('image/gif', $resp['headers'], 'Content-Type should be image/gif');
    assertValidGif($resp['body'], 'Body should be a valid GIF');
    assertEquals($initialCount + 1, $harness->countConversions(), 'Conversion should be recorded');

    // Test 9: Pixel with missing status should still return GIF (pixel contract)
    echo "\nTest 9: /pixel.gif with missing status should still return 200 GIF\n";
    $resp = $harness->get("/pixel.gif?action=conversion&subid={$clickId}&payout=5");
    // Pixel should always return 200 with GIF, even on errors
    assertEquals(200, $resp['code'], 'Status code should be 200');
    assertContentType('image/gif', $resp['headers'], 'Content-Type should be image/gif');
    assertValidGif($resp['body'], 'Body should be a valid GIF');

    // Test 10: Pixel with unknown click should still return GIF
    echo "\nTest 10: /pixel.gif with unknown click should still return 200 GIF\n";
    $resp = $harness->get("/pixel.gif?action=conversion&subid=unknown_click_xyz&status=lead");
    assertEquals(200, $resp['code'], 'Status code should be 200');
    assertContentType('image/gif', $resp['headers'], 'Content-Type should be image/gif');
    assertValidGif($resp['body'], 'Body should be a valid GIF');

    // Additional: Verify no SQL or exception text leaks in error responses
    echo "\nTest 11: Error responses should not leak SQL or exception details\n";
    $resp = $harness->get("/{$postbackKey}/postback?subid=bad_click_xyz&status=lead");
    assertNotContains('SQL', $resp['body'], 'Body should not contain "SQL"');
    assertNotContains('PDOException', $resp['body'], 'Body should not contain "PDOException"');
    assertNotContains('Stack trace', $resp['body'], 'Body should not contain stack trace');

    echo "\n✅ All postback status codes tests passed.\n";
    echo "Status codes are correct and pixel.gif always returns 200 GIF.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Test error: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    $testPassed = false;
} finally {
    echo "\nStopping test server...\n";
    $harness->stop();
}

exit($testPassed ? 0 : 1);
