<?php
// tests/lp_sub_id_update_test.php
//
// Keitaro parity for the landing→offer hop: sub_id_1..sub_id_30 appended to
// /?_lp=1 update the click, reach the offer URL as {sub_id_N}, and are stored
// on the click row. Empty values never erase, other keys are ignored, and an
// unsigned transition still writes nothing.
//
// Run: php tests/lp_sub_id_update_test.php

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
        fwrite(STDERR, "  Actual:   " . var_export($actual, true) . "\n");
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
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
}

require_once __DIR__ . '/lib/http.php';

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);
// router.php serves the REAL click.php file; index.php-as-router would treat
// any /click.php?campaign_id= request as a campaign view (landing and all).
$harness->useProductionRouter();
$harness->start();

/**
 * The harness's get() follows redirects; the timing tests need the FIRST
 * response (the landing body / the 302 itself).
 */
$firstResponse = function (string $path, array $headers = []) use ($harness): array {
    $url = $harness->getBaseUrl() . '/' . ltrim($path, '/');
    $ctx = stream_context_create(['http' => [
        'timeout' => 8,
        'ignore_errors' => true,
        'follow_location' => 0,
        'header' => implode("\r\n", $headers),
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $lines = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?: [])
        : ($http_response_header ?? []);
    $code = 0;
    $location = '';
    foreach ($lines as $h) {
        if (preg_match('#^HTTP/\d\.\d (\d{3})#', (string) $h, $m)) {
            $code = (int) $m[1];
        }
        if (stripos((string) $h, 'Location:') === 0) {
            $location = trim(substr((string) $h, 9));
        }
    }
    return ['code' => $code, 'body' => (string) $body, 'location' => $location];
};

/** Mirror of issueLpToken() in index.php (pure HMAC — same payload/sig). */
$lpToken = function (string $clickId, string $secret): string {
    $payload = base64_encode(json_encode(['c' => $clickId, 'e' => time() + 3600]));
    $payload = rtrim(strtr($payload, '+/', '-_'), '=');
    return $payload . '.' . substr(hash_hmac('sha256', $payload, $secret), 0, 32);
};

try {
    $pdo = $harness->getPdo();

    $cid = random_int(100000, 999998);
    $sid = $cid + 1;
    $lid = $cid + 2;
    $oid = $cid + 3;
    $key = 'lp_subid_key';

    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived)
                   VALUES (?, 'SubId Offer', 'https://pp-network.example/track?src={sub_id_1}&product={sub_id_5}&image={sub_id_6}&kw={keyword}', 0, 'active', 0)")->execute([$oid]);
    $pdo->prepare("INSERT INTO landings (id, name, url, type, action_type, action_payload, state, is_archived)
                   VALUES (?, 'SubId LP', '', 'action', 'show_html',
                           '<html><body>SUBID_LP_MARKER <a href=\"/?_lp=1\">go</a></body></html>', 'active', 0)")->execute([$lid]);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived)
                   VALUES (?, 'SubId Camp', 'subidcamp$cid', '', 'active', 0)")->execute([$cid]);
    $schema = json_encode([
        'landings' => [['id' => $lid, 'weight' => 100, 'state' => 'active']],
        'offers'   => [['id' => $oid, 'weight' => 100, 'state' => 'active']],
    ]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks, offer_selection)
                   VALUES (?, ?, NULL, 'LP Stream', 'regular', 1, 'landing_offer', ?, 1, 1, 'before')")->execute([$sid, $cid, $schema]);
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', ?)")->execute([$key]);
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')")->execute();
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_enabled', '1')")->execute();

    $headers = [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 203.0.113.40',
        'Accept-Language: en-US,en;q=0.9',
    ];

    // --- 1. Landing view carries the traffic source's own values ---
    $resp = $firstResponse("/subidcamp$cid?sub_id_1=from_source&sub_id_5=old_product&keyword=orig", $headers);
    assertEquals(200, $resp['code'], 'Landing is served');
    $row = $pdo->query("SELECT * FROM clicks WHERE campaign_id = $cid ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertTrue((bool) $row, 'Click row exists');
    $clickId = (string) $row['id'];
    $before = json_decode((string) $row['parameters_json'], true) ?: [];
    assertEquals('old_product', $before['sub_id_5'] ?? null, 'Campaign link set sub_id_5');

    // --- 2. Transition with the landing's choice ---
    $token = $lpToken($clickId, $key);
    $resp2 = $firstResponse("/subidcamp$cid?_lp=1&_token=" . urlencode($token)
        . '&sub_id_5=' . urlencode('Blue Watch')
        . '&sub_id_6=' . urlencode('https://cdn.example/blue.jpg')
        . '&sub_id_1='           // empty: must not erase the source value
        . '&keyword=hack',       // not updatable from a landing
        $headers);
    assertEquals(302, $resp2['code'], 'Transition redirects to the offer');
    $loc = $resp2['location'];
    assertContains('product=Blue+Watch', $loc, '{sub_id_5} carries the landing choice');
    assertContains('image=' . urlencode('https://cdn.example/blue.jpg'), $loc, '{sub_id_6} carries the image URL, encoded');
    assertContains('src=from_source', $loc, 'Empty sub_id_1 keeps the source value');
    assertContains('kw=orig', $loc, 'keyword is not updatable from the landing');

    $after = json_decode((string) $pdo->query("SELECT parameters_json FROM clicks WHERE id = " . $pdo->quote($clickId))->fetchColumn(), true) ?: [];
    assertEquals('Blue Watch', $after['sub_id_5'] ?? null, 'Click row stores the updated sub_id_5');
    assertEquals('https://cdn.example/blue.jpg', $after['sub_id_6'] ?? null, 'Click row stores the new sub_id_6');
    assertEquals('from_source', $after['sub_id_1'] ?? null, 'Click row keeps sub_id_1');
    assertEquals('orig', $after['keyword'] ?? null, 'Click row keeps keyword');
    assertEquals(1, (int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE campaign_id = $cid")->fetchColumn(), 'No second click row');

    // --- 3. Over-long values are capped like the click itself ---
    $resp3 = $firstResponse("/subidcamp$cid?_lp=1&_token=" . urlencode($token) . '&sub_id_7=' . str_repeat('x', 900), $headers);
    assertEquals(302, $resp3['code'], 'Transition with a long value still redirects');
    $after3 = json_decode((string) $pdo->query("SELECT parameters_json FROM clicks WHERE id = " . $pdo->quote($clickId))->fetchColumn(), true) ?: [];
    assertEquals(512, strlen((string) ($after3['sub_id_7'] ?? '')), 'sub_id_7 is capped at 512 bytes');
    assertEquals('Blue Watch', $after3['sub_id_5'] ?? null, 'Earlier update survives a later transition');

    // --- 4. A forged click id is still refused (no unsigned _subid) ---
    $resp4 = $firstResponse("/subidcamp$cid?_lp=1&_subid=" . urlencode($clickId) . '&sub_id_5=forged', ['User-Agent: curl/8']);
    assertTrue($resp4['code'] >= 400, 'Unsigned transition is rejected');
    $after4 = json_decode((string) $pdo->query("SELECT parameters_json FROM clicks WHERE id = " . $pdo->quote($clickId))->fetchColumn(), true) ?: [];
    assertEquals('Blue Watch', $after4['sub_id_5'] ?? null, 'A rejected transition writes nothing');

    // --- 5. Shared helper semantics ---
    require_once $repoRoot . '/core/ClickParams.php';
    [$m, $c] = orbitraMergeClickParamUpdates(['sub_id_2' => 'a'], ['sub_id_2' => 'a', 'sub_id_3' => ['x']], orbitraLandingUpdatableParamKeys());
    assertEquals(false, $c, 'Same value / non-scalar value is not a change');
    assertEquals(['sub_id_2' => 'a'], $m, 'Non-scalar values are ignored');
    assertEquals(30, count(orbitraLandingUpdatableParamKeys()), 'sub_id_1..30 are updatable');

    echo "\n" . ($testPassed ? "LP sub_id update tests passed.\n" : "LP sub_id update tests FAILED.\n");
} finally {
    $harness->stop();
}

exit($testPassed ? 0 : 1);
