<?php
// tests/lp_transition_timing_test.php
//
// End-to-end check of the landing→offer timing feature ("Time since LP click"
// + honest LP transition counters):
//
//   1. A landing_offer stream with offer_selection='after' logs the landing
//      view WITHOUT an offer bound, and landing_at is written for action
//      landings too (it used to be local-landings-only, so redirect/action
//      and external landings never produced a timing pair).
//   2. The signed /?_lp=1 transition binds offer_id + offer_at to the SAME
//      click row — the honest LP transition — and backfills landing_at from
//      the client's _lt seconds when the landing view never went through the
//      tracker (COALESCE keeps the authoritative serve-time value).
//   3. click.php accepts _lt and synthesizes the landing_at/offer_at pair on
//      its own row — the external KClient/tracking.js offer-link flow.
//   4. The shared dashboard SQL + derived metrics report real_lp_clicks /
//      real_offer_clicks / real_lp_ctr, and the lp_time bucket dimension
//      (mirror of $allowed_dimensions['lp_time'] in api.php) groups the rows.
//
// Run: php tests/lp_transition_timing_test.php

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

    // --- Seed: campaign + landing_offer stream ("after") + action landing ---
    $cid = random_int(100000, 999998);
    $sid = $cid + 1;
    $lid = $cid + 2;
    $oid = $cid + 3;
    $key = 'lp_timing_key';

    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived)
                   VALUES (?, 'Timing Offer', 'https://pp-network.example/track', 0, 'active', 0)")->execute([$oid]);
    $pdo->prepare("INSERT INTO landings (id, name, url, type, action_type, action_payload, state, is_archived)
                   VALUES (?, 'Timing LP', '', 'action', 'show_html',
                           '<html><body>TIMING_LP_MARKER <a href=\"/?_lp=1\">go</a></body></html>', 'active', 0)")->execute([$lid]);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived)
                   VALUES (?, 'Timing Camp', 'timingcamp$cid', '', 'active', 0)")->execute([$cid]);
    $schema = json_encode([
        'landings' => [['id' => $lid, 'weight' => 100, 'state' => 'active']],
        'offers'   => [['id' => $oid, 'weight' => 100, 'state' => 'active']],
    ]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks, offer_selection)
                   VALUES (?, ?, NULL, 'LP Stream', 'regular', 1, 'landing_offer', ?, 1, 1, 'after')")->execute([$sid, $cid, $schema]);
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', ?)")->execute([$key]);
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')")->execute();
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_enabled', '1')")->execute();

    $latestClick = function () use ($pdo, $cid) {
        $row = $pdo->query("SELECT * FROM clicks WHERE campaign_id = $cid ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            fwrite(STDERR, "FAILED: no click row for campaign $cid\n");
            global $testPassed;
            $testPassed = false;
            exit(1);
        }
        return $row;
    };
    $browserHeaders = function (string $ip): array {
        return [
            'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
            "X-Forwarded-For: $ip",
            'Accept-Language: en-US,en;q=0.9',
        ];
    };

    // --- 1. Landing view: no offer bound, landing_at written (action landing) ---
    // Alias path, not /?campaign_id=: under the production router the root
    // path serves the SPA, while /<alias> resolves the campaign in index.php.
    $resp = $firstResponse("/timingcamp$cid", $browserHeaders('203.0.113.10'));
    assertEquals(200, $resp['code'], 'Action landing is served inline (200)');
    assertContains('TIMING_LP_MARKER', $resp['body'], 'Landing body carries the action payload');

    $row = $latestClick();
    assertEquals($lid, (int) $row['landing_id'], 'Click is bound to the landing');
    assertTrue(empty($row['offer_id']), 'offer_selection=after keeps the click offer-less on the landing view');
    assertTrue(!empty($row['landing_at']), 'landing_at is written for an action landing (not local-only anymore)');
    assertTrue(empty($row['offer_at']), 'offer_at is still open before the CTA click');
    $clickId = (string) $row['id'];

    // --- 2. Signed /?_lp=1 transition: offer_id + offer_at on the SAME row ---
    // The alias path carries the _lp params: under the production router a
    // bare "/" belongs to the admin SPA, while /<alias> always reaches
    // index.php (whose _lp branch runs before campaign resolution).
    $token = $lpToken($clickId, $key);
    $resp2 = $firstResponse("/timingcamp$cid?_lp=1&_token=" . urlencode($token) . "&offer_id=$oid", $browserHeaders('203.0.113.10'));
    assertEquals(302, $resp2['code'], 'The transition redirects to the offer');
    assertContains('pp-network.example', $resp2['location'], 'Location points at the offer URL');

    $row2 = $latestClick();
    assertEquals($clickId, (string) $row2['id'], 'The transition continues the original click (no second row)');
    assertEquals($oid, (int) $row2['offer_id'], 'offer_id is bound on the transition');
    assertTrue(!empty($row2['offer_at']), 'offer_at closes the timing pair');
    assertEquals($row['landing_at'], $row2['landing_at'], 'Serve-time landing_at is not overwritten');

    // --- 3. click.php with _lt: pair synthesized on its own row ---
    $resp3 = $firstResponse("/click.php?campaign_id=$cid&redirect=0&_lt=37", $browserHeaders('203.0.113.11'));
    assertEquals(200, $resp3['code'], 'click.php answers redirect=0 with JSON');
    $json = json_decode($resp3['body'], true);
    if (!is_array($json) || empty($json['click_id'])) {
        fwrite(STDERR, "FAILED: click.php returned a non-JSON body (HTTP {$resp3['code']}):\n" . substr($resp3['body'], 0, 800) . "\n");
        global $testPassed;
        $testPassed = false;
    } else {
        echo "✓ click.php returns a click id\n";
    }
    $row3 = $pdo->query("SELECT * FROM clicks WHERE id = " . $pdo->quote((string) $json['click_id']))->fetch(PDO::FETCH_ASSOC);
    assertTrue((bool) $row3, 'The click.php row exists');
    assertTrue(empty($row3['landing_id']), 'The click.php row has no landing_id (external flow)');
    assertEquals($oid, (int) $row3['offer_id'], 'The stream offer is bound');
    assertTrue(!empty($row3['landing_at']) && !empty($row3['offer_at']), '_lt synthesized the landing_at/offer_at pair');
    $delta = (int) $row3 ? 0 : 0;
    $delta = (int) (strtotime($row3['offer_at'] . ' UTC') - strtotime($row3['landing_at'] . ' UTC'));
    assertTrue(abs($delta - 37) <= 3, "Pair delta equals the reported _lt (got {$delta}s, want 37s ±3)");

    // _lt must never clobber a serve-time landing_at: move the click's
    // landing_at backwards, repeat the transition carrying _lt=5, and check
    // the serve-time value survived (COALESCE keeps what's there).
    $presetLandingAt = gmdate('Y-m-d H:i:s', time() - 90);
    $pdo->prepare("UPDATE clicks SET landing_at = ? WHERE id = ?")->execute([$presetLandingAt, $clickId]);
    $resp4 = $firstResponse("/timingcamp$cid?_lp=1&_token=" . urlencode($lpToken($clickId, $key)) . "&offer_id=$oid&_lt=5", $browserHeaders('203.0.113.12'));
    $row4 = $pdo->query("SELECT * FROM clicks WHERE id = " . $pdo->quote($clickId))->fetch(PDO::FETCH_ASSOC);
    assertEquals($presetLandingAt, $row4['landing_at'], '_lt does not overwrite an existing landing_at (COALESCE)');
    // Restore the original serve-time value for the bucket assertions below.
    $pdo->prepare("UPDATE clicks SET landing_at = ? WHERE id = ?")->execute([$row['landing_at'], $clickId]);

    // --- 4. Shared dashboard SQL + derived metrics over the sandbox DB ---
    require_once $repoRoot . '/core/ReportMetrics.php';
    $raw = $pdo->query(orbitraDashboardMetricsSql('payout', null))->fetch(PDO::FETCH_ASSOC);
    $m = orbitraComputeDerivedMetrics($raw ?: []);
    assertEquals(2, (int) $m['clicks'], 'Two click rows in play');
    assertEquals(1, (int) $m['lp_views'], 'One landing view (the click.php row has no landing)');
    assertEquals(1, (int) $m['real_lp_clicks'], 'real_lp_clicks counts the completed transition only');
    assertEquals(2, (int) $m['real_offer_clicks'], 'real_offer_clicks = transition + direct click.php click');
    assertEquals(100.0, (float) $m['real_lp_ctr'], 'real_lp_ctr = 1 real transition / 1 view');
    // avg_lp_seconds is provided by the campaigns-list SQL, not the dashboard
    // one — check the AVG directly over the timing pairs (both rows completed
    // a transition: the landing click seconds after the view, click.php 37s).
    $pairs = $pdo->query("SELECT CAST(strftime('%s', offer_at) - strftime('%s', landing_at) AS INTEGER)
                          FROM clicks WHERE campaign_id = $cid AND landing_at IS NOT NULL AND offer_at IS NOT NULL")
                  ->fetchAll(PDO::FETCH_COLUMN);
    assertEquals(2, count($pairs), 'Both clicks carry a timing pair');
    assertTrue(max($pairs) >= 35 && max($pairs) <= 39, 'The synthesized 37s pair is in the set');
    $avgSeconds = array_sum($pairs) / count($pairs);

    // --- 5. lp_time bucket dimension (mirror of api.php $allowed_dimensions) ---
    $bucketExpr = "CASE WHEN landing_at IS NULL OR offer_at IS NULL THEN NULL
        WHEN CAST(strftime('%s', offer_at) - strftime('%s', landing_at) AS INTEGER) < 3 THEN '0-3s'
        WHEN CAST(strftime('%s', offer_at) - strftime('%s', landing_at) AS INTEGER) < 10 THEN '3-10s'
        WHEN CAST(strftime('%s', offer_at) - strftime('%s', landing_at) AS INTEGER) < 30 THEN '10-30s'
        WHEN CAST(strftime('%s', offer_at) - strftime('%s', landing_at) AS INTEGER) < 60 THEN '30-60s'
        ELSE '60s+' END";
    // The click.php row's synthesized pair (37s) and the landing row's own
    // pair must each land in the bucket their delta computes to.
    $rows5 = $pdo->query("SELECT id, $bucketExpr AS b,
        CAST(strftime('%s', offer_at) - strftime('%s', landing_at) AS INTEGER) AS s
        FROM clicks WHERE campaign_id = $cid")->fetchAll(PDO::FETCH_ASSOC);
    assertEquals(2, count($rows5), 'Both clicks are bucketed');
    foreach ($rows5 as $r5) {
        $expectedBucket = $r5['s'] < 3 ? '0-3s' : ($r5['s'] < 10 ? '3-10s' : ($r5['s'] < 30 ? '10-30s' : ($r5['s'] < 60 ? '30-60s' : '60s+')));
        assertEquals($expectedBucket, $r5['b'], "Bucket matches the delta for a {$r5['s']}s pair");
    }
    // A row without a pair (landing shown, no transition) groups as Unknown:
    // seed such a row and check the NULL branch.
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, landing_id, ip, landing_at, created_at)
                   VALUES ('lp-test-null-pair', ?, ?, '203.0.113.99', datetime('now'), datetime('now'))")->execute([$cid, $lid]);
    $nullBucket = $pdo->query("SELECT COALESCE($bucketExpr, 'Unknown') FROM clicks WHERE id = 'lp-test-null-pair'")->fetchColumn();
    assertEquals('Unknown', $nullBucket, 'NULL pair groups as Unknown');
    $pdo->prepare("DELETE FROM clicks WHERE id = 'lp-test-null-pair'")->execute();

    echo "\n" . ($testPassed ? "LP transition timing tests passed.\n" : "LP transition timing tests FAILED.\n");
} finally {
    $harness->stop();
}

exit($testPassed ? 0 : 1);
