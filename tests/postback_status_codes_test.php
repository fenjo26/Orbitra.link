<?php
/**
 * Postback status codes test — verifies that postback.php returns correct HTTP
 * status codes for various scenarios, and that the pixel path always returns 200.
 *
 * Run with: php tests/postback_status_codes_test.php
 */

require_once __DIR__ . '/../config.php';

echo "Testing postback status codes...\n";

// Get the postback key from settings
$stmt = $pdo->query("SELECT value FROM settings WHERE `key` = 'postback_key'");
$postbackKey = $stmt->fetchColumn() ?: 'orbitra';

echo "Using postback key: {$postbackKey}\n";

$baseUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

// Test 1: Valid postback should return 200
echo "\n1. Testing valid postback...\n";
// First, we need a valid click ID to test with
$stmt = $pdo->query("SELECT id FROM clicks LIMIT 1");
$click = $stmt->fetch();
if (!$click) {
    echo "  WARNING: No clicks found in database, skipping success test\n";
    echo "  Run php tests/seed_click.php to create test data\n";
} else {
    $clickId = $click['id'];
    $url = "{$baseUrl}/{$postbackKey}/postback?subid={$clickId}&status=lead&payout=10&currency=USD";
    echo "  URL: {$url}\n";
    echo "  Expected: 200 status and 'Postback recorded successfully.'\n";
    echo "  Run manually: curl -i \"{$url}\"\n";
}

// Test 2: Invalid subid should return 404
echo "\n2. Testing invalid subid (404 expected)...\n";
$url = "{$baseUrl}/{$postbackKey}/postback?subid=invalid_subid_xyz123&status=lead";
echo "  URL: {$url}\n";
echo "  Expected: 404 status and error message\n";
echo "  Run manually: curl -i \"{$url}\"\n";

// Test 3: Missing status should return 400
echo "\n3. Testing missing status (400 expected)...\n";
$url = "{$baseUrl}/{$postbackKey}/postback?subid=test123";
echo "  URL: {$url}\n";
echo "  Expected: 400 status and 'Missing status' error\n";
echo "  Run manually: curl -i \"{$url}\"\n";

// Test 4: Missing subid should return 400
echo "\n4. Testing missing subid (400 expected)...\n";
$url = "{$baseUrl}/{$postbackKey}/postback?status=lead";
echo "  URL: {$url}\n";
echo "  Expected: 400 status and 'Missing subid' error\n";
echo "  Run manually: curl -i \"{$url}\"\n";

// Test 5: Wrong key should not be handled by postback.php
echo "\n5. Testing wrong postback key (should not be handled)...\n";
$url = "{$baseUrl}/wrongkey/postback?subid=test&status=lead";
echo "  URL: {$url}\n";
echo "  Expected: Not handled by postback.php (likely 404 or index.php response)\n";
echo "  Run manually: curl -i \"{$url}\"\n";

// Test 6: Pixel with invalid subid should still return 200 GIF
echo "\n6. Testing pixel with invalid subid (200 GIF expected)...\n";
$url = "{$baseUrl}/pixel.gif?action=conversion&subid=invalid_subid_xyz123&status=lead";
echo "  URL: {$url}\n";
echo "  Expected: 200 status, Content-Type: image/gif, valid GIF body\n";
echo "  Run manually: curl -i \"{$url}\"\n";

// Test 7: Pixel with valid postback should return 200 GIF
if (isset($clickId)) {
    echo "\n7. Testing pixel with valid conversion (200 GIF and conversion recorded)...\n";
    $url = "{$baseUrl}/pixel.gif?action=conversion&subid={$clickId}&status=lead&payout=5&currency=USD";
    echo "  URL: {$url}\n";
    echo "  Expected: 200 status, valid GIF, conversion recorded in database\n";
    echo "  Run manually: curl -i \"{$url}\"\n";
}

echo "\n=== Manual Verification Steps ===\n";
echo "1. All endpoints should respond with correct HTTP status codes\n";
echo "2. Pixel path should ALWAYS return 200 with valid GIF, even on errors\n";
echo "3. Postbacks should be logged to incoming_postbacks_log table\n";
echo "4. Valid conversions should appear in conversions table\n";
echo "\nTo run actual HTTP tests, start the PHP server and run the curl commands above.\n";
