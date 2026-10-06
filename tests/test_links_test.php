<?php
// tests/test_links_test.php
//
// Signed campaign test links (?_t=…): the real routing pipeline runs, but no
// clicks row is ever written; ?_geo= overrides the visitor's country for the
// stream filters; ?_dbg=1 answers with a routing trace instead of a redirect.
// The signature is what separates a test from a visitor — without it _geo and
// _dbg must be ignored.
//
//   1. Signature round-trip: valid passes, tampered / wrong-campaign fail.
//   2. Router: a test click routes to the stream's destination but never
//      inserts a clicks row (a normal click on the same URL does).
//   3. _geo changes filter verdicts: a DE-only stream wins at DE, loses at FR.
//   4. _dbg=1 returns the trace page: streams, verdicts, destination.
//   5. An invalid _t degrades to a normal (logged) click; _geo is ignored.
//   6. click.php (Click API) honours the same gate.
//   7. Click API v3 answers a test without writing a click.
//   8. /?_lp=1&_t=… redirects to the bound offer with a test- click id.
//
// Run: php tests/test_links_test.php

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
    $location = '';
    foreach ($lines as $h) {
        if (preg_match('#^HTTP/\d\.\d (\d{3})#', (string) $h, $m)) {
            $code = (int) $m[1];
        }
        if (preg_match('#^Location:\s*(.+)$#i', (string) $h, $m)) {
            $location = trim($m[1]);
        }
    }
    return ['code' => $code, 'body' => (string) $body, 'location' => $location];
};

try {
    $pdo = $harness->getPdo();

    $cid = random_int(200000, 299998);
    $sDe = $cid + 1;     // Country-include-DE stream (position 1)
    $sDef = $cid + 2;    // no-filters default stream (position 2)
    $oid = $cid + 3;

    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived)
                   VALUES (?, 'TestLink Offer', 'https://offer.example/?sub={clickid}', 0, 'active', 0)")->execute([$oid]);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived)
                   VALUES (?, 'TestLink Camp', 'testcamp$cid', 'tok_$cid', 'active', 0)")->execute([$cid]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks)
                   VALUES (?, ?, NULL, 'DE only', 'regular', 1, 'redirect', ?, 1, 1)")
        ->execute([$sDe, $cid, json_encode(['redirect_mode' => 'direct_url', 'direct_url' => 'https://de-stream.example/?c={clickid}'])]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks)
                   VALUES (?, ?, NULL, 'Default', 'regular', 2, 'redirect', ?, 1, 1)")
        ->execute([$sDef, $cid, json_encode(['redirect_mode' => 'direct_url', 'direct_url' => 'https://default-stream.example/'])]);
    $pdo->prepare("UPDATE streams SET filters_json = ? WHERE id = ?")
        ->execute([json_encode([['name' => 'Country', 'mode' => 'include', 'payload' => ['DE']]]), $sDe]);
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', 'testlink_key')")->execute();
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')")->execute();
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_enabled', '1')")->execute();

    require_once $repoRoot . '/core/test_links.php';
    $camp = $pdo->query("SELECT * FROM campaigns WHERE id = $cid")->fetch(PDO::FETCH_ASSOC);
    $sig = orbitraTestSignature($camp, 'testlink_key');

    $browserHeaders = function (string $ip): array {
        return [
            'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
            "X-Forwarded-For: $ip",
            'Accept-Language: en-US,en;q=0.9',
        ];
    };
    $clickCount = function () use ($pdo, $cid): int {
        return (int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE campaign_id = $cid")->fetchColumn();
    };

    // --- 1. Signature round-trip ---
    $validated = orbitraValidTestSignature($sig, $pdo, 'testlink_key');
    assertTrue(is_array($validated) && (int) $validated['id'] === $cid, 'A valid signature validates back to its campaign');
    assertTrue(orbitraValidTestSignature($sig, $pdo, 'another_key') === null, 'A different postback_key rejects the signature');
    $tampered = $cid . ':' . str_repeat('0', 32);
    assertTrue(orbitraValidTestSignature($tampered, $pdo, 'testlink_key') === null, 'A tampered signature is rejected');
    assertTrue(orbitraValidTestSignature(($cid + 500) . ':' . substr($sig, strpos($sig, ':') + 1), $pdo, 'testlink_key') === null, 'A signature for another campaign id is rejected');

    // --- 2. Router: test click routes but never logs ---
    // (The destination depends on whether geo DBs are installed — an unknown
    // country fails the DE filter closed when they are — so only the routing
    // itself and the absence of a row are asserted here. _geo pins the
    // country deterministically in check 3.)
    $r = $firstResponse("/testcamp$cid?_t=" . urlencode($sig), $browserHeaders('203.0.113.40'));
    assertEquals(302, $r['code'], 'A test click still redirects (302)');
    assertTrue($r['location'] !== '', 'The test click got a destination');
    assertEquals(0, $clickCount(), 'A test click writes NO clicks row');

    // A normal click on the same URL does log — the contrast that proves the gate.
    $r = $firstResponse("/testcamp$cid", $browserHeaders('203.0.113.41'));
    assertEquals(302, $r['code'], 'A normal click on the same URL also redirects');
    assertEquals(1, $clickCount(), 'The normal click IS logged');

    // --- 3. _geo changes filter verdicts ---
    $rFr = $firstResponse("/testcamp$cid?_t=" . urlencode($sig) . "&_geo=FR", $browserHeaders('203.0.113.42'));
    assertContains('default-stream.example', $rFr['location'], '_geo=FR: the DE-only stream loses, the default stream wins');
    $rDe = $firstResponse("/testcamp$cid?_t=" . urlencode($sig) . "&_geo=de", $browserHeaders('203.0.113.43'));
    assertContains('de-stream.example', $rDe['location'], '_geo=de (lowercase accepted): the DE-only stream wins');
    assertEquals(1, $clickCount(), 'Neither _geo test visit was logged');

    // --- 4. _dbg=1 returns the routing trace ---
    $rDbg = $firstResponse("/testcamp$cid?_t=" . urlencode($sig) . "&_geo=FR&_dbg=1", $browserHeaders('203.0.113.44'));
    assertEquals(200, $rDbg['code'], 'The trace answers 200 HTML');
    assertContains('Routing trace', $rDbg['body'], 'The trace page identifies itself');
    assertContains('DE only', $rDbg['body'], 'The trace lists the stream names');
    assertContains('rejected', $rDbg['body'], 'The DE stream is marked rejected at FR');
    assertContains('SELECTED', $rDbg['body'], 'The winning stream is marked SELECTED');
    assertContains('default-stream.example', $rDbg['body'], 'The trace shows the substituted destination');
    assertContains('_geo=FR', $rDbg['body'], 'The trace marks the country override');
    assertContains('Country', $rDbg['body'], 'Per-filter verdicts are listed');
    assertEquals(1, $clickCount(), 'A _dbg visit is not logged either');

    // --- 5. Invalid _t degrades to a normal click; _geo is ignored ---
    $rBad = $firstResponse("/testcamp$cid?_t=" . urlencode($tampered) . "&_geo=FR", $browserHeaders('203.0.113.42'));
    assertEquals(302, $rBad['code'], 'An invalid signature still serves the campaign');
    assertEquals(2, $clickCount(), 'An invalid signature is a NORMAL click — it logs');

    // --- 6. click.php honours the same gate ---
    $rApi = $firstResponse("/click.php?campaign_id=$cid&token=tok_$cid&_t=" . urlencode($sig) . "&redirect=0", $browserHeaders('203.0.113.46'));
    assertEquals(200, $rApi['code'], 'click.php answers the test request');
    $decoded = json_decode($rApi['body'], true);
    assertTrue(is_array($decoded) && isset($decoded['click_id']), 'click.php returns a click_id JSON');
    assertEquals(2, $clickCount(), 'click.php test click writes nothing');

    // --- 7. Click API v3 (tracking.js registerClick) honours the gate ---
    $rV3 = $firstResponse("/click_api/v3?token=tok_$cid&info=1&_t=" . urlencode($sig), $browserHeaders('203.0.113.47'));
    assertEquals(200, $rV3['code'], 'Click API v3 answers the test request');
    $v3 = json_decode($rV3['body'], true);
    assertTrue(is_array($v3) && is_array($v3['info'] ?? null), 'Click API v3 returns info');
    assertEquals(2, $clickCount(), 'Click API v3 test click writes nothing');

    // --- 8. /?_lp=1 test transition ---
    $rLp = $firstResponse("/testcamp$cid?_lp=1&_t=" . urlencode($sig) . "&offer_id=$oid", $browserHeaders('203.0.113.48'));
    assertEquals(302, $rLp['code'], 'The test landing transition redirects');
    assertContains('offer.example', $rLp['location'], 'The transition goes to the offer URL');
    assertContains('test-', $rLp['location'], 'The substituted click id is a test- id, not a stored click');
    assertEquals(2, $clickCount(), 'The test landing transition logs nothing');

    // And the honest answer for a stale signature: the normal path's
    // "original click not found" — a 400, not a redirect.
    $rLpStale = $firstResponse("/testcamp$cid?_lp=1&_t=" . urlencode($tampered) . "&offer_id=$oid", $browserHeaders('203.0.113.49'));
    assertEquals(400, $rLpStale['code'], 'A stale signature on /?_lp=1 gets the honest 400 (no click row to resolve)');

    echo "\nAll test-link checks completed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $testPassed = false;
} finally {
    $harness->stop();
}

exit($testPassed ? 0 : 1);
