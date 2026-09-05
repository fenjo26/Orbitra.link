<?php
// tests/lp_dwell_test.php
//
// Time on the landing for EVERY visitor — the ones who never clicked the offer
// included. The pre-existing landing_at/offer_at pair only ever produces a
// number for a visitor who pressed the CTA, which means the landing that bores
// everyone away reports nothing at all: exactly the page an operator needs to
// find. This checks the whole path:
//
//   1. A tracker-served landing carries the dwell timer, bound to THIS click.
//   2. /pixel.gif?action=lp records seconds + scroll depth on the click row.
//   3. MAX() semantics: a late or replayed beacon can only raise the number,
//      never shrink it — heartbeats arrive out of order and must be harmless.
//   4. Bounds: a fabricated beacon cannot poison the averages.
//   5. landing_at is backfilled only when the tracker never wrote one
//      (the external kclient.js/tracking.js case), never walked forward.
//   6. The shared dashboard SQL + derived metrics report time_on_lp,
//      lp_bounce_rate, lp_scroll_depth and lp_measured, and the lp_dwell
//      bucket dimension (mirror of $allowed_dimensions['lp_dwell'] in
//      api.php) groups a bounce that never reached the offer.
//
// Run: php tests/lp_dwell_test.php

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

    $cid = random_int(100000, 999998);
    $sid = $cid + 1;
    $lid = $cid + 2;
    $oid = $cid + 3;

    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived)
                   VALUES (?, 'Dwell Offer', 'https://pp-network.example/track', 0, 'active', 0)")->execute([$oid]);
    $pdo->prepare("INSERT INTO landings (id, name, url, type, action_type, action_payload, state, is_archived)
                   VALUES (?, 'Dwell LP', '', 'action', 'show_html',
                           '<html><body>DWELL_LP_MARKER <a href=\"/?_lp=1\">go</a></body></html>', 'active', 0)")->execute([$lid]);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived)
                   VALUES (?, 'Dwell Camp', 'dwellcamp$cid', '', 'active', 0)")->execute([$cid]);
    $schema = json_encode([
        'landings' => [['id' => $lid, 'weight' => 100, 'state' => 'active']],
        'offers'   => [['id' => $oid, 'weight' => 100, 'state' => 'active']],
    ]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks, offer_selection)
                   VALUES (?, ?, NULL, 'LP Stream', 'regular', 1, 'landing_offer', ?, 1, 1, 'after')")->execute([$sid, $cid, $schema]);
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', 'dwell_key')")->execute();
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')")->execute();
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_enabled', '1')")->execute();

    $browserHeaders = function (string $ip): array {
        return [
            'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
            "X-Forwarded-For: $ip",
            'Accept-Language: en-US,en;q=0.9',
        ];
    };
    $clickRow = function (string $id) use ($pdo) {
        return $pdo->query("SELECT * FROM clicks WHERE id = " . $pdo->quote($id))->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    // --- 1. The served landing carries the timer, bound to this click ---
    $resp = $firstResponse("/dwellcamp$cid", $browserHeaders('203.0.113.20'));
    assertEquals(200, $resp['code'], 'Landing is served inline (200)');
    assertContains('DWELL_LP_MARKER', $resp['body'], 'Landing body is the action payload');
    assertContains("action=lp&subid=", $resp['body'], 'The dwell timer script is injected into the landing');

    $row = $pdo->query("SELECT * FROM clicks WHERE campaign_id = $cid ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertTrue((bool) $row, 'The landing view produced a click row');
    $clickId = (string) $row['id'];
    assertContains($clickId, $resp['body'], 'The injected timer carries THIS click id');
    assertTrue($row['lp_seconds'] === null, 'lp_seconds starts empty — nothing measured yet');

    // --- 2. The beacon records seconds and scroll depth ---
    $px = $firstResponse("/pixel.gif?action=lp&subid=" . urlencode($clickId) . "&t=42&s=63", $browserHeaders('203.0.113.20'));
    assertEquals(200, $px['code'], 'The beacon answers 200 (a pixel always answers)');
    $r2 = $clickRow($clickId);
    assertEquals(42, (int) $r2['lp_seconds'], 'lp_seconds recorded from the beacon');
    assertEquals(63, (int) $r2['lp_scroll'], 'lp_scroll recorded from the beacon');

    // This visitor NEVER pressed the offer button — the point of the feature.
    assertTrue(empty($r2['offer_at']), 'No offer transition: the old timing pair stays empty');

    // --- 3. MAX(): a replayed / out-of-order beacon cannot shrink the value ---
    $firstResponse("/pixel.gif?action=lp&subid=" . urlencode($clickId) . "&t=10&s=5", $browserHeaders('203.0.113.20'));
    $r3 = $clickRow($clickId);
    assertEquals(42, (int) $r3['lp_seconds'], 'A lower replayed t does not shrink lp_seconds');
    assertEquals(63, (int) $r3['lp_scroll'], 'A lower replayed s does not shrink lp_scroll');

    $firstResponse("/pixel.gif?action=lp&subid=" . urlencode($clickId) . "&t=90&s=20", $browserHeaders('203.0.113.20'));
    $r4 = $clickRow($clickId);
    assertEquals(90, (int) $r4['lp_seconds'], 'A later heartbeat raises lp_seconds');
    assertEquals(63, (int) $r4['lp_scroll'], 'Scroll depth keeps its deepest value independently');

    // --- 4. Bounds: a fabricated beacon cannot poison the averages ---
    $firstResponse("/pixel.gif?action=lp&subid=" . urlencode($clickId) . "&t=99999999&s=4000", $browserHeaders('203.0.113.20'));
    $r5 = $clickRow($clickId);
    assertEquals(86400, (int) $r5['lp_seconds'], 'Seconds are clamped to a day');
    assertEquals(100, (int) $r5['lp_scroll'], 'Scroll depth is clamped to 100%');
    // Put a realistic bounce back for the metric assertions below.
    $pdo->prepare("UPDATE clicks SET lp_seconds = 3, lp_scroll = 8 WHERE id = ?")->execute([$clickId]);

    // --- 5. landing_at: backfilled only when the tracker never wrote one ---
    $servedLandingAt = (string) $r5['landing_at'];
    assertTrue($servedLandingAt !== '', 'The tracker wrote landing_at when it served the landing');
    $firstResponse("/pixel.gif?action=lp&subid=" . urlencode($clickId) . "&t=1", $browserHeaders('203.0.113.20'));
    $r6 = $clickRow($clickId);
    assertEquals($servedLandingAt, (string) $r6['landing_at'], 'The serve-time landing_at is never walked forward');

    // External flow: a click with no landing_at at all (kclient.js on someone
    // else's site) gets the start of the visit reconstructed from the beacon.
    $extId = 'dwell-external-' . $cid;
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at) VALUES (?, ?, '203.0.113.21', datetime('now'))")
        ->execute([$extId, $cid]);
    $firstResponse("/pixel.gif?action=lp&subid=" . urlencode($extId) . "&t=45&s=90", $browserHeaders('203.0.113.21'));
    $rExt = $clickRow($extId);
    assertEquals(45, (int) $rExt['lp_seconds'], 'External click records its dwell');
    assertTrue(!empty($rExt['landing_at']), 'landing_at backfilled for a click the tracker never served');
    $backfillAge = time() - strtotime((string) $rExt['landing_at'] . ' UTC');
    assertTrue(abs($backfillAge - 45) <= 5, "Backfilled landing_at is t seconds ago (got {$backfillAge}s, want 45s ±5)");

    // A garbage subid must change nothing and still answer with the image.
    $bogus = $firstResponse("/pixel.gif?action=lp&subid=no-such-click&t=30", $browserHeaders('203.0.113.22'));
    assertEquals(200, $bogus['code'], 'An unknown subid still gets the pixel, not an error');

    // --- 6. Derived metrics + the lp_dwell bucket dimension ---
    require_once $repoRoot . '/core/ReportMetrics.php';
    $raw = $pdo->query(orbitraDashboardMetricsSql('payout', null))->fetch(PDO::FETCH_ASSOC);
    $m = orbitraComputeDerivedMetrics($raw ?: []);
    assertEquals(2, (int) $m['lp_measured'], 'Both measured visits are in the sample');
    assertEquals('24s', (string) $m['time_on_lp'], 'time_on_lp is the average of 3s and 45s');
    assertEquals(50.0, (float) $m['lp_bounce_rate'], 'One of the two measured visits was under 5s');
    assertEquals(49.0, (float) $m['lp_scroll_depth'], 'Scroll depth averages 8% and 90%');
    // The bounce never reached the offer, so the OLD metric is silent about it.
    assertEquals('—', (string) $m['time_since_lp_click'], 'time_since_lp_click still sees nothing — that is the gap this closes');

    $bucketExpr = "CASE WHEN lp_seconds IS NULL THEN NULL
        WHEN lp_seconds < 5 THEN '0-5s'
        WHEN lp_seconds < 15 THEN '5-15s'
        WHEN lp_seconds < 30 THEN '15-30s'
        WHEN lp_seconds < 60 THEN '30-60s'
        WHEN lp_seconds < 180 THEN '1-3m'
        ELSE '3m+' END";
    $buckets = $pdo->query("SELECT id, COALESCE($bucketExpr, 'Unknown') AS b FROM clicks WHERE campaign_id = $cid")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
    assertEquals('0-5s', (string) ($buckets[$clickId] ?? ''), 'The 3s bounce lands in the 0-5s bucket');
    assertEquals('30-60s', (string) ($buckets[$extId] ?? ''), 'The 45s visit lands in the 30-60s bucket');

    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at) VALUES ('dwell-unmeasured', ?, '203.0.113.23', datetime('now'))")
        ->execute([$cid]);
    $unknown = $pdo->query("SELECT COALESCE($bucketExpr, 'Unknown') FROM clicks WHERE id = 'dwell-unmeasured'")->fetchColumn();
    assertEquals('Unknown', (string) $unknown, 'A visit that never reported groups as Unknown, not as 0s');

    echo "\n" . ($testPassed ? "LP dwell tests passed.\n" : "LP dwell tests FAILED.\n");
} finally {
    $harness->stop();
}

exit($testPassed ? 0 : 1);
