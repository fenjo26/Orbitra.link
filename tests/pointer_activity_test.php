<?php
// tests/pointer_activity_test.php
//
// The behavioural bot hint: a landing visit where the timer ran but not one
// mousemove/touchstart/pointerdown arrived is a "possible bot" — a hint for
// the reports, never an exclusion. NULL means "not measured" and never
// counts; only an explicit zero does.
//
//   1. The injected landing timer carries the pa flag and the listeners.
//   2. /pixel.gif?action=lp&pa=0 writes pointer_activity=0; pa=1 raises it
//      to 1; a replayed pa=0 cannot lower it back (MAX semantics).
//   3. A beacon without pa (an older script) leaves the column NULL.
//   4. possible_bots in the shared dashboard SQL + derived metrics counts
//      exactly the explicitly-zero rows.
//
// Run: php tests/pointer_activity_test.php

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
$harness->useProductionRouter();
$harness->start();

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
    foreach ($lines as $h) {
        if (preg_match('#^HTTP/\d\.\d (\d{3})#', (string) $h, $m)) {
            $code = (int) $m[1];
        }
    }
    return ['code' => $code, 'body' => (string) $body];
};

try {
    $pdo = $harness->getPdo();

    $cid = random_int(300000, 399998);
    $sid = $cid + 1;
    $lid = $cid + 2;
    $oid = $cid + 3;

    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived)
                   VALUES (?, 'PA Offer', 'https://pa-net.example/track', 0, 'active', 0)")->execute([$oid]);
    $pdo->prepare("INSERT INTO landings (id, name, url, type, action_type, action_payload, state, is_archived)
                   VALUES (?, 'PA LP', '', 'action', 'show_html',
                           '<html><body>PA_LP_MARKER <a href=\"/?_lp=1\">go</a></body></html>', 'active', 0)")->execute([$lid]);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived)
                   VALUES (?, 'PA Camp', 'pacamp$cid', '', 'active', 0)")->execute([$cid]);
    $schema = json_encode([
        'landings' => [['id' => $lid, 'weight' => 100, 'state' => 'active']],
        'offers'   => [['id' => $oid, 'weight' => 100, 'state' => 'active']],
    ]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks, offer_selection)
                   VALUES (?, ?, NULL, 'PA Stream', 'regular', 1, 'landing_offer', ?, 1, 1, 'after')")->execute([$sid, $cid, $schema]);
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', 'pa_key')")->execute();
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')")->execute();
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_enabled', '1')")->execute();

    $browserHeaders = function (string $ip): array {
        return [
            'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
            "X-Forwarded-For: $ip",
            'Accept-Language: en-US,en;q=0.9',
        ];
    };
    $clickRow = function (string $id) use ($pdo): array {
        $stmt = $pdo->prepare("SELECT * FROM clicks WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stmt->closeCursor();
        return $row;
    };

    // --- 1. The injected timer carries the pointer signal ---
    $resp = $firstResponse("/pacamp$cid", $browserHeaders('203.0.113.60'));
    assertEquals(200, $resp['code'], 'The landing is served inline');
    assertContains('PA_LP_MARKER', $resp['body'], 'The landing body is the action payload');
    assertContains("&pa='+pa", $resp['body'], 'The injected timer sends the pa flag');
    assertContains("addEventListener('mousemove'", $resp['body'], 'The injected timer listens for mouse movement');
    assertContains("addEventListener('touchstart'", $resp['body'], 'The injected timer listens for touches');

    $row = $pdo->query("SELECT * FROM clicks WHERE campaign_id = $cid ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertTrue((bool) $row, 'The landing view produced a click row');
    $clickId = (string) $row['id'];
    assertTrue($row['pointer_activity'] === null, 'pointer_activity starts NULL — the beacon has not spoken yet');

    // --- 2. pa semantics: explicit zero, raise to one, MAX keeps it ---
    $firstResponse("/pixel.gif?action=lp&subid=" . urlencode($clickId) . "&t=10&s=20&pa=0", $browserHeaders('203.0.113.60'));
    $r2 = $clickRow($clickId);
    assertTrue($r2['pointer_activity'] !== null, 'pa=0 is stored (measured), not left NULL');
    assertEquals(0, (int) $r2['pointer_activity'], 'pa=0 records an explicit zero (measured, no pointer)');
    assertEquals(10, (int) $r2['lp_seconds'], 'The same beacon still records dwell');

    // A scripted visitor cannot un-flag itself: once real activity was seen,
    // a replayed pa=0 must not lower it.
    $firstResponse("/pixel.gif?action=lp&subid=" . urlencode($clickId) . "&t=12&s=20&pa=1", $browserHeaders('203.0.113.60'));
    $r3 = $clickRow($clickId);
    assertEquals(1, (int) $r3['pointer_activity'], 'pa=1 raises the flag to 1');
    $firstResponse("/pixel.gif?action=lp&subid=" . urlencode($clickId) . "&t=15&s=20&pa=0", $browserHeaders('203.0.113.60'));
    $r4 = $clickRow($clickId);
    assertEquals(1, (int) $r4['pointer_activity'], 'A replayed pa=0 cannot shrink the flag back (MAX semantics)');

    // --- 3. No pa, no opinion: an older script leaves NULL ---
    $extId = 'pa-external-' . $cid;
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at) VALUES (?, ?, '203.0.113.61', datetime('now'))")
        ->execute([$extId, $cid]);
    $firstResponse("/pixel.gif?action=lp&subid=" . urlencode($extId) . "&t=30&s=10", $browserHeaders('203.0.113.61'));
    $rExt = $clickRow($extId);
    assertEquals(30, (int) $rExt['lp_seconds'], 'The old-style beacon still records dwell');
    assertTrue($rExt['pointer_activity'] === null, 'A beacon without pa leaves pointer_activity NULL — not measured, not counted');

    // --- 4. possible_bots: exactly the explicitly-zero rows ---
    // Rows by then: $clickId (raised to 1 in check 2), pa-external (NULL),
    // plus three seeded below — one human (1), one suspected (0), one
    // unmeasured (NULL). Exactly one explicitly-zero row exists.
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at, pointer_activity) VALUES (?, ?, '203.0.113.62', datetime('now'), 1)")
        ->execute(['pa-human-' . $cid, $cid]);
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at, pointer_activity) VALUES (?, ?, '203.0.113.63', datetime('now'), 0)")
        ->execute(['pa-suspect-' . $cid, $cid]);
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at) VALUES (?, ?, '203.0.113.64', datetime('now'))")
        ->execute(['pa-unknown-' . $cid, $cid]);

    require_once $repoRoot . '/core/ReportMetrics.php';
    $raw = $pdo->query(orbitraDashboardMetricsSql('payout', null))->fetch(PDO::FETCH_ASSOC);
    assertEquals(1, (int) $raw['possible_bots'], 'possible_bots counts exactly the one explicitly-zero row');
    assertEquals(5, (int) $raw['visitors'], 'All five rows are still visitors (the hint never excludes)');
    $m = orbitraComputeDerivedMetrics($raw ?: []);
    assertEquals(1, (int) $m['possible_bots'], 'The derived metrics pass possible_bots through');

    // --- 5. tracking.js (external landings) ships the same signal ---
    $tjs = (string) file_get_contents($repoRoot . '/tracking.js');
    assertContains("addEventListener('mousemove'", $tjs, 'tracking.js listens for mouse movement');
    assertContains("addEventListener('touchstart'", $tjs, 'tracking.js listens for touches');
    assertContains('&pa=', $tjs, 'tracking.js beacons the pa flag');

    echo "\nAll pointer-activity checks completed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $testPassed = false;
} finally {
    $harness->stop();
}

exit($testPassed ? 0 : 1);
