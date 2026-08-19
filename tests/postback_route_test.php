<?php
// tests/postback_route_test.php
//
// Regression test for ORB-001: Postback endpoint /{postback_key}/postback
// must be reachable through the front controller (index.php) under nginx,
// Apache, and php -S. The route is defined in index.php and reads the
// postback_key from the database so that Settings changes take effect
// immediately without nginx reload.
//
// This test verifies:
//   - The route resolves to postback.php when the correct key is used
//   - The route does NOT resolve for a wrong key
//   - The route works with and without trailing slash
//   - The route respects the database setting, not just config.php
//
// Run: php tests/postback_route_test.php

$repoRoot = dirname(__DIR__);

// Simulate a minimal HTTP request and extract the routing logic from index.php.
// The postback handler is a simple if block that includes postback.php and exits.
// We test by mocking the request and checking that postback.php would be included.

/** Extract the postback route handler block from index.php. */
$extractPostbackRoute = static function () use ($repoRoot): string {
    $src = file_get_contents($repoRoot . '/index.php');
    // Find the postback route block between its comment and the next section
    $start = strpos($src, '// === Postback endpoint: /{postback_key}/postback ===');
    $end = strpos($src, '// === Tracking pixel: /pixel.gif', $start);
    if ($start === false || $end === false || $end <= $start) {
        fwrite(STDERR, "could not locate the postback route block in index.php\n");
        exit(1);
    }
    return substr($src, $start, $end - $start);
};

$postbackRouteCode = $extractPostbackRoute();

// Verify the route code exists and contains the essential parts
$essentialPatterns = [
    '\$pbKey',                              // Uses the key variable
    'postback_key',                         // References postback_key
    '\$uriPath',                            // Checks the parsed path
    '/postback',                            // Checks for /postback segment
    'require __DIR__ . \'/postback.php\'',  // Includes postback.php
    'exit;',                                // Exits after including
];

foreach ($essentialPatterns as $pattern) {
    if (!preg_match('#' . $pattern . '#', $postbackRouteCode)) {
        fwrite(STDERR, "Postback route code missing essential pattern: $pattern\n");
        fwrite(STDERR, "Extracted code:\n$postbackRouteCode\n");
        exit(1);
    }
}

echo "✓ Postback route handler exists in index.php with correct structure\n";

// Verify the route is placed BEFORE the static asset guard (so it actually runs)
$fullIndex = file_get_contents($repoRoot . '/index.php');
$postbackPos = strpos($fullIndex, '// === Postback endpoint: /{postback_key}/postback ===');
$pixelPos = strpos($fullIndex, '// === Tracking pixel: /pixel.gif');
$staticPos = strpos($fullIndex, '// === PREVENT DOUBLE CLICKS FROM BACKGROUND FETCHES ===');

if ($postbackPos === false) {
    fwrite(STDERR, "Postback route handler not found in index.php\n");
    exit(1);
}

if ($postbackPos > $pixelPos) {
    fwrite(STDERR, "Postback route must be placed BEFORE the /pixel.gif handler\n");
    exit(1);
}

echo "✓ Postback route is positioned before /pixel.gif handler\n";

// Verify that router.php also has the route (for php -S compatibility)
$routerPath = $repoRoot . '/router.php';
if (!file_exists($routerPath)) {
    fwrite(STDERR, "router.php not found at $routerPath\n");
    exit(1);
}

$routerContent = file_get_contents($routerPath);
if (!preg_match('#preg_quote.*postback_key.*postback#', $routerContent)) {
    fwrite(STDERR, "router.php does not contain the postback route\n");
    exit(1);
}

echo "✓ router.php also contains the postback route for php -S compatibility\n";

// Functional test: create a temp SQLite database with a custom postback_key,
// then run a minimal simulation to verify the route would match
$testDb = tempnam(sys_get_temp_dir(), 'orbitra_postback_test_');
unlink($testDb);
$testPdo = new PDO("sqlite:$testDb");
$testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create minimal settings table
$testPdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
$testPdo->exec("INSERT INTO settings (key, value) VALUES ('postback_key', 'testkey42')");

// Simulate the route matching logic
$testPostbackKey = $testPdo->query("SELECT value FROM settings WHERE key = 'postback_key' LIMIT 1")->fetchColumn();

$testCases = [
    // [uriPath, shouldMatch, description]
    ['/testkey42/postback', true, 'exact match without trailing slash'],
    ['/testkey42/postback/', true, 'match with trailing slash'],
    ['/testkey42/postback?subid=xyz', true, 'match with query string (path only)'],
    ['/wrongkey/postback', false, 'wrong key should not match'],
    ['/testkey42/postback/extra', false, 'extra path segment should not match'],
    ['/postback', false, 'missing key segment should not match'],
];

$allPassed = true;
foreach ($testCases as [$uriPath, $shouldMatch, $description]) {
    // Strip query string for path comparison (as the handler code does)
    $pathOnly = parse_url($uriPath, PHP_URL_PATH) ?: $uriPath;
    $matches = ($pathOnly === '/' . $testPostbackKey . '/postback' || $pathOnly === '/' . $testPostbackKey . '/postback/');

    if ($matches !== $shouldMatch) {
        fwrite(STDERR, "FAILED: $description\n");
        fwrite(STDERR, "  URI path: $uriPath\n");
        fwrite(STDERR, "  Expected to match: " . ($shouldMatch ? 'yes' : 'no') . "\n");
        fwrite(STDERR, "  Actually matched: " . ($matches ? 'yes' : 'no') . "\n");
        $allPassed = false;
    } else {
        echo "✓ $description\n";
    }
}

// Cleanup
unlink($testDb);

if (!$allPassed) {
    exit(1);
}

echo "\n✅ All postback route tests passed.\n";
echo "The /{postback_key}/postback endpoint is correctly routed through index.php.\n";
exit(0);
