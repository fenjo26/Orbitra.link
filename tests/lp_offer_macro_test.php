<?php
// tests/lp_offer_macro_test.php
//
// {offer} is always the signed landing→offer transition (Keitaro parity,
// v1.6.14) — never the advertiser's direct URL:
//   • 'before'/'after' local + action landings: the href carries _token;
//   • appending &offer_id= / &sub_id_N to {offer} reaches the tracker
//     (offer switch + click param update) instead of a foreign domain;
//   • the hop completes the funnel: offer_at is written, so the click
//     counts in reports (pre-1.6.14 'before' streams showed 0 clicks);
//   • an external redirect landing receives the docs-promised _subid/_token
//     pair, and a {offer} inside its URL becomes an ABSOLUTE signed
//     transition (relative would resolve against the landing's own domain).
//
// Run: php tests/lp_offer_macro_test.php

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

function assertContains($needle, $haystack, $message) {
    global $testPassed;
    if (strpos((string) $haystack, (string) $needle) === false) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected to contain: " . var_export($needle, true) . "\n  In: " . substr((string) $haystack, 0, 300) . "\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
}

function assertNotContains($needle, $haystack, $message) {
    global $testPassed;
    if (strpos((string) $haystack, (string) $needle) !== false) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected NOT to contain: " . var_export($needle, true) . "\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
}

function assertEquals2($expected, $actual, $message) {
    global $testPassed;
    if ((string) $expected !== (string) $actual) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected: " . var_export($expected, true) . "\n  Actual:   " . var_export($actual, true) . "\n");
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
        'timeout' => 8, 'ignore_errors' => true, 'follow_location' => 0,
        'header' => implode("\r\n", $headers),
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $lines = $http_response_header ?? [];
    $code = 0; $location = '';
    foreach ($lines as $h) {
        if (preg_match('#^HTTP/\d\.\d (\d{3})#', (string) $h, $m)) $code = (int) $m[1];
        if (stripos((string) $h, 'Location:') === 0) $location = trim(substr((string) $h, 9));
    }
    return ['code' => $code, 'body' => (string) $body, 'location' => $location];
};

/** Mirror of issueLpToken() in index.php (pure HMAC — same payload/sig). */
$lpToken = function (string $clickId, string $secret): string {
    $payload = base64_encode(json_encode(['c' => $clickId, 'e' => time() + 3600]));
    $payload = rtrim(strtr($payload, '+/', '-_'), '=');
    return $payload . '.' . substr(hash_hmac('sha256', $payload, $secret), 0, 32);
};

$clickRow = function ($pdo, string $clickId) {
    return $pdo->query("SELECT * FROM clicks WHERE id = " . $pdo->quote($clickId))->fetch(PDO::FETCH_ASSOC) ?: [];
};

try {
    $pdo = $harness->getPdo();
    $cid = random_int(300000, 399998);
    $key = 'offer_macro_key';

    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', ?)")->execute([$key]);
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')")->execute();
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_enabled', '1')")->execute();

    $oid1 = $cid + 1;
    $oid2 = $cid + 2;
    $lid  = $cid + 3;
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived)
                   VALUES (?, 'Primary Offer', 'https://pp.example/buy?cid={clickid}&p={sub_id_5}', 0, 'active', 0)")->execute([$oid1]);
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived)
                   VALUES (?, 'Alt Offer', 'https://alt.example/checkout?cid={clickid}', 0, 'active', 0)")->execute([$oid2]);
    $pdo->prepare("INSERT INTO landings (id, name, url, type, action_type, action_payload, state, is_archived)
                   VALUES (?, 'Offer Macro LP', '', 'action', 'show_html',
                           '<a href=\"{offer}\">go</a><a href=\"{offer}&offer_id=$oid2\">alt</a><a href=\"{offer}&sub_id_5=Blue\">pick</a>', 'active', 0)")->execute([$lid]);

    $mkStream = function (int $streamCid, int $landingId, array $offerIds, string $selection) use ($pdo): void {
        $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived)
                       VALUES (?, 'OM Camp', 'omcamp$streamCid', '', 'active', 0)")->execute([$streamCid]);
        $schema = json_encode([
            'landings' => [['id' => $landingId, 'weight' => 100, 'state' => 'active']],
            'offers'   => array_map(fn($id) => ['id' => $id, 'weight' => 100, 'state' => 'active'], $offerIds),
        ]);
        $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks, offer_selection)
                       VALUES (?, ?, NULL, 'OM Stream', 'regular', 1, 'landing_offer', ?, 1, 1, ?)")
            ->execute([$streamCid + 50, $streamCid, $schema, $selection]);
    };

    $headers = [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 203.0.113.77',
    ];

    // ================= 'before' mode =================
    // Single offer: rotation must not make the primary-offer assertion flaky.
    // The offer SWITCH below overrides explicitly with offer_id=$oid2.
    $mkStream($cid, $lid, [$oid1], 'before');
    $resp = $firstResponse("/omcamp$cid?sub_id_5=Red", $headers);
    assertTrue($resp['code'] === 200, "before: landing served");
    assertContains('/index.php?_lp=1&_token=', $resp['body'], 'before: {offer} is the signed transition');
    assertNotContains('https://pp.example/buy', $resp['body'], 'before: {offer} is NOT the direct advertiser URL');

    $row = $pdo->query("SELECT * FROM clicks WHERE campaign_id = $cid ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertTrue((bool) $row, 'before: click row exists');
    $clickId = (string) $row['id'];
    assertTrue($row['offer_at'] === null, 'before: no offer_at until the visitor clicks the CTA');

    // --- hop 1: plain {offer} — funnel completes, macros substituted ---
    $token = $lpToken($clickId, $key);
    $hop = $firstResponse("/index.php?_lp=1&_token=" . urlencode($token), $headers);
    assertTrue($hop['code'] === 302, 'before: hop redirects');
    assertContains('https://pp.example/buy', $hop['location'], 'before: hop goes to the primary offer');
    assertContains('cid=' . urlencode($clickId), $hop['location'], 'before: {clickid} substituted at the hop');
    assertContains('p=Red', $hop['location'], 'before: {sub_id_5} from the campaign link carried');
    assertTrue($clickRow($pdo, $clickId)['offer_at'] !== null, 'before: offer_at written by the hop (clicks column counts again)');

    // --- hop 2: {offer}&offer_id= switches the offer ---
    $token2 = $lpToken($clickId, $key);
    $hop2 = $firstResponse("/index.php?_lp=1&_token=" . urlencode($token2) . "&offer_id=$oid2", $headers);
    assertTrue($hop2['code'] === 302, 'offer switch: hop redirects');
    assertContains('https://alt.example/checkout', $hop2['location'], '{offer}&offer_id= switches to the alt offer');
    assertEquals2($oid2, (string) $clickRow($pdo, $clickId)['offer_id'], 'offer switch: click re-attributed to the alt offer');

    // --- hop 3: {offer}&sub_id_5= updates the click (1.6.13 synergy) ---
    $token3 = $lpToken($clickId, $key);
    $hop3 = $firstResponse("/index.php?_lp=1&_token=" . urlencode($token3) . "&sub_id_5=Blue&offer_id=$oid1", $headers);
    assertTrue($hop3['code'] === 302, 'param append: hop redirects');
    assertContains('p=Blue', $hop3['location'], '{offer}&sub_id_5= reaches the offer URL');
    $params3 = json_decode((string) $clickRow($pdo, $clickId)['parameters_json'], true) ?: [];
    assertEquals2('Blue', (string) ($params3['sub_id_5'] ?? ''), '{offer}&sub_id_5= stored on the click');

    // ================= 'after' mode =================
    $cidAfter = $cid + 1000;
    $mkStream($cidAfter, $lid, [$oid1], 'after');
    $respA = $firstResponse("/omcamp$cidAfter?sub_id_5=Red", $headers);
    assertTrue($respA['code'] === 200, "after: landing served");
    assertContains('/index.php?_lp=1&_token=', $respA['body'], 'after: {offer} is signed (was a bare /?_lp=1 before 1.6.14)');

    // ================= external redirect landing =================
    $cidExt = $cid + 2000;
    $lidExt = $cid + 2003;
    $pdo->prepare("INSERT INTO landings (id, name, url, type, state, is_archived)
                   VALUES (?, 'Ext LP', 'http://external.example/lp?next={offer}', 'redirect', 'active', 0)")->execute([$lidExt]);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived)
                   VALUES (?, 'OM Ext Camp', 'omextcamp$cidExt', '', 'active', 0)")->execute([$cidExt]);
    $schemaExt = json_encode([
        'landings' => [['id' => $lidExt, 'weight' => 100, 'state' => 'active']],
        'offers'   => [['id' => $oid1, 'weight' => 100, 'state' => 'active']],
    ]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks, offer_selection)
                   VALUES (?, ?, NULL, 'OM Ext Stream', 'regular', 1, 'landing_offer', ?, 1, 1, 'before')")->execute([$cidExt + 50, $cidExt, $schemaExt]);

    $respE = $firstResponse("/omextcamp$cidExt?sub_id_5=Red", $headers);
    assertTrue($respE['code'] === 302, 'ext: redirect to the landing');
    $loc = $respE['location'];
    assertContains('http://external.example/lp?next=', $loc, 'ext: landing URL kept');
    assertContains('_subid=', $loc, 'ext: docs-promised _subid appended (guard was dead since v0.9.3.4)');
    assertContains('_token=', $loc, 'ext: docs-promised _token appended');
    $base = $harness->getBaseUrl();
    assertContains('next=' . urlencode($base . '/index.php?_lp=1'), $loc, 'ext: {offer} is the ABSOLUTE transition');
    assertContains('offer_id=' . $oid1, urldecode($loc), 'ext: transition names the chosen offer');

    // The landing hands the visitor the absolute transition; follow it back.
    $extClickRow = $pdo->query("SELECT * FROM clicks WHERE campaign_id = $cidExt ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $extClickId = (string) $extClickRow['id'];
    $extToken = $lpToken($extClickId, $key);
    $hopE = $firstResponse("/index.php?_lp=1&_token=" . urlencode($extToken) . "&offer_id=$oid1", $headers);
    assertTrue($hopE['code'] === 302, 'ext: handed-back transition redirects');
    assertContains('https://pp.example/buy', $hopE['location'], 'ext: transition reaches the offer');
    assertTrue($clickRow($pdo, $extClickId)['offer_at'] !== null, 'ext: funnel completed for the external hop');

    echo "\n" . ($testPassed ? "LP {offer} macro tests passed.\n" : "LP {offer} macro tests FAILED.\n");
} finally {
    $harness->stop();
}

exit($testPassed ? 0 : 1);
