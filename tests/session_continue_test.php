<?php
// tests/session_continue_test.php
//
// Session continuation in the Click API (organic sites, schema 78):
//
//   1. tracking.js registers page 1 of a site via /click_api/v3?info=1 —
//      a click appears, and with the campaign recording switch on, the
//      response carries the recorder payload.
//   2. Page 2 sends ?_subid=<that click> → the SAME sub_id comes back with
//      continued:true, no new clicks row, no re-rotation — and the recorder
//      payload points at the same click, so both pages land in ONE recording.
//      Opt-in: the campaign's "one click per visit" switch must be ON (the
//      default is off, so an upgrade changes nobody's numbers).
//   3. A NEW ad marker (a different fbclid, a fresh sub_id_N) is a new
//      campaign entry even with the switch on — a paid click is never
//      swallowed; the SAME marker value still glues.
//   4. A _subid of another campaign, a stale click, or a bot click is not
//      continued — a fresh click is registered instead.
//   5. The continued response still rebuilds the signed offer transition.
//
// (The client-side gates — same-site referrer, 30-minute window — live in
// tracking.js and are not observable over HTTP; the server-side rules above
// are what this file pins down.)
//
// Run: php tests/session_continue_test.php

$testPassed = true;

function assertTrue($condition, $message) {
    global $testPassed;
    if (!$condition) {
        fwrite(STDERR, "FAILED: $message\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return (bool) $condition;
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

$browser = function (string $ip): array {
    return [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
        "X-Forwarded-For: $ip",
        'Accept-Language: en-US,en;q=0.9',
    ];
};

try {
    $pdo = $harness->getPdo();
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $work = $harness->getWorkingDir();
    define('ORBITRA_REPLAY_DIR', $work . '/var/replays');
    require_once $work . '/core/SessionReplay.php';

    // Seed: an organic-style campaign — direct offer stream, no landing, the
    // site itself is tracked with tracking.js.
    $cid = random_int(600000, 699990);
    $oid = $cid + 1;
    $sid = $cid + 2;
    $token = 'sctoken' . bin2hex(random_bytes(8));
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived, uniqueness_hours, uniqueness_method)
                   VALUES (?, 'SC Organic', ?, ?, 'active', 0, 24, 'IP')")->execute([$cid, "scorg$cid", $token]);
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived)
                   VALUES (?, 'SC Offer', 'https://net.example/offer', 0, 'active', 0)")->execute([$oid]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, is_active, collect_clicks)
                   VALUES (?, ?, ?, 'Direct', 'regular', 1, 'redirect', 1, 1)")->execute([$sid, $cid, $oid]);
    foreach (['postback_key' => 'sc_key', 'ignore_prefetch' => '0', 'stats_enabled' => '1'] as $k => $v) {
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$k, $v]);
    }
    // Recording switched on for the whole campaign, consent required, and the
    // visit gluing opted in ("one click per visit" — off by default).
    $pdo->prepare("INSERT INTO replay_campaign_settings (campaign_id, enabled, sample_pct, until_at, consent, session_continue, updated_at)
                   VALUES (?, 1, 100, NULL, 1, 1, datetime('now'))")->execute([$cid]);

    $countClicks = function () use ($pdo, $cid): int {
        $st = $pdo->prepare("SELECT COUNT(*) FROM clicks WHERE campaign_id = ?");
        $st->execute([$cid]);
        return (int) $st->fetchColumn();
    };

    // --- 1. Page 1: fresh registration ---
    $api1 = $firstResponse('/click_api/v3?token=' . urlencode($token) . '&info=1&se_referrer=' . urlencode('https://www.google.com/'), $browser('198.51.100.10'));
    assertEquals(200, $api1['code'], 'v3 answers 200');
    $d1 = json_decode($api1['body'], true);
    assertTrue(is_array($d1['info'] ?? null), 'info is present');
    $sub1 = (string) ($d1['info']['sub_id'] ?? '');
    assertTrue($sub1 !== '', 'Page 1 registers a click');
    assertEquals(1, $countClicks(), 'One clicks row after page 1');
    assertTrue(isset($d1['info']['replay']), 'Page 1 carries the recorder payload');
    assertEquals(1, $d1['info']['replay']['consent'] ?? null, 'Consent flag rides the recorder payload');
    assertEquals($sub1, $d1['info']['replay']['subid'] ?? null, 'The recorder is bound to this click');

    // --- 2. Page 2: continuation ---
    $api2 = $firstResponse('/click_api/v3?token=' . urlencode($token) . '&info=1&_subid=' . urlencode($sub1), $browser('198.51.100.10'));
    assertEquals(200, $api2['code'], 'Continuation answers 200');
    $d2 = json_decode($api2['body'], true);
    assertEquals($sub1, (string) ($d2['info']['sub_id'] ?? ''), 'Page 2 continues the SAME click');
    assertEquals(true, $d2['info']['continued'] ?? null, 'The response is marked continued');
    assertEquals(1, $countClicks(), 'Still one clicks row after page 2');
    assertEquals($oid, (int) ($d2['info']['offer_id'] ?? 0), 'Continued click keeps its offer');
    assertTrue((bool) preg_match('#\?_lp=1\&_token=#', (string) ($d2['info']['offer_link'] ?? '')), 'Continuation rebuilds the signed offer transition');
    assertTrue(isset($d2['info']['replay']), 'Continuation carries the recorder payload');
    assertEquals($sub1, $d2['info']['replay']['subid'] ?? null, 'Page 2 records into the SAME recording');

    // (The two pages ingesting into ONE recording file is covered end to end
    // by tests/session_replay_test.php §11 — same click id, append-only file.)

    // --- 3. Not continued cases ---
    // The gluing switch is OFF (the default): ?_subid= is ignored entirely —
    // an upgrade changes nobody's click numbers.
    $offCid = $cid + 20;
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived) VALUES (?, 'SC Off', 'scoff$cid', 'sctokenoff', 'active', 0)")->execute([$offCid]);
    $offToken = 'scofftok' . bin2hex(random_bytes(6));
    $pdo->prepare("UPDATE campaigns SET token = ? WHERE id = ?")->execute([$offToken, $offCid]);
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at) VALUES (?, ?, '198.51.100.96', datetime('now'))")->execute(["scoff1$cid", $offCid]);
    $apiOff = $firstResponse('/click_api/v3?token=' . urlencode($offToken) . '&info=1&_subid=' . urlencode("scoff1$cid"), $browser('198.51.100.14'));
    $dOff = json_decode($apiOff['body'], true);
    assertTrue(($dOff['info']['sub_id'] ?? '') !== "scoff1$cid", 'Switch off: ?_subid= is ignored, a fresh click is registered');

    // A NEW ad marker is a new campaign entry, not a page of the visit: the
    // second paid click is never swallowed by the remembered one. The same
    // marker value (a site propagating its own links) still glues.
    $ad2 = $firstResponse('/click_api/v3?token=' . urlencode($token) . '&info=1&_subid=' . urlencode($sub1) . '&fbclid=AD_TWO', $browser('198.51.100.10'));
    $dAd2 = json_decode($ad2['body'], true);
    assertTrue(($dAd2['info']['sub_id'] ?? '') !== $sub1, 'A different fbclid starts a NEW click');
    $subAd2 = (string) ($dAd2['info']['sub_id'] ?? '');
    $ad2Row = $pdo->prepare("SELECT parameters_json FROM clicks WHERE id = ?");
    $ad2Row->execute([$subAd2]);
    $ad2Params = (string) $ad2Row->fetchColumn();
    $ad2Row->closeCursor();
    assertTrue(strpos($ad2Params, 'AD_TWO') !== false, 'The second ad click keeps its own fbclid');
    $ad2Same = $firstResponse('/click_api/v3?token=' . urlencode($token) . '&info=1&_subid=' . urlencode($subAd2) . '&fbclid=AD_TWO', $browser('198.51.100.10'));
    $dAd2Same = json_decode($ad2Same['body'], true);
    assertEquals($subAd2, (string) ($dAd2Same['info']['sub_id'] ?? ''), 'The SAME fbclid on the next page still glues');
    $subIdParam = $firstResponse('/click_api/v3?token=' . urlencode($token) . '&info=1&_subid=' . urlencode($subAd2) . '&sub_id_1=OTHER', $browser('198.51.100.10'));
    $dSubId = json_decode($subIdParam['body'], true);
    assertTrue(($dSubId['info']['sub_id'] ?? '') !== $subAd2, 'A changed sub_id_N also starts a new click');

    // Another campaign's click id: a fresh click of THIS campaign.
    $foreignCid = $cid + 50;
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived) VALUES (?, 'SC Other', 'scother$foreignCid', 'sctokenother', 'active', 0)")->execute([$foreignCid]);
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at) VALUES (?, ?, '198.51.100.99', datetime('now'))")->execute(["scforeign$cid", $foreignCid]);
    $api3 = $firstResponse('/click_api/v3?token=' . urlencode($token) . '&info=1&_subid=' . urlencode("scforeign$cid"), $browser('198.51.100.11'));
    $d3 = json_decode($api3['body'], true);
    assertTrue(($d3['info']['sub_id'] ?? '') !== "scforeign$cid", 'A foreign campaign click is not continued');
    assertTrue(($d3['info']['continued'] ?? false) !== true, 'Foreign continuation is not marked continued');
    assertTrue(($d3['info']['sub_id'] ?? '') !== $sub1, 'Foreign _subid registers its own fresh click');

    // Stale click (created a week ago, window is 24 h).
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at) VALUES (?, ?, '198.51.100.98', datetime('now', '-7 days'))")->execute(["scstale$cid", $cid]);
    $api4 = $firstResponse('/click_api/v3?token=' . urlencode($token) . '&info=1&_subid=' . urlencode("scstale$cid"), $browser('198.51.100.12'));
    $d4 = json_decode($api4['body'], true);
    assertTrue(($d4['info']['sub_id'] ?? '') !== "scstale$cid", 'A stale click is not continued');

    // Bot click: also just a fresh registration — and the fresh click is a
    // normal human visit, so it DOES arm (the bot's own row never does).
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, is_bot, created_at) VALUES (?, ?, '198.51.100.97', 1, datetime('now'))")->execute(["scbot$cid", $cid]);
    $api5 = $firstResponse('/click_api/v3?token=' . urlencode($token) . '&info=1&_subid=' . urlencode("scbot$cid"), $browser('198.51.100.13'));
    $d5 = json_decode($api5['body'], true);
    assertTrue(($d5['info']['sub_id'] ?? '') !== "scbot$cid", 'A bot click is not continued');
    assertTrue(($d5['info']['replay']['subid'] ?? '') === ($d5['info']['sub_id'] ?? ''), 'The fresh click behind a bot _subid arms its own recording');
} finally {
    $harness->stop();
}

if ($testPassed) {
    echo "\nAll session continuation tests passed.\n";
    exit(0);
}
fwrite(STDERR, "\nSession continuation tests FAILED.\n");
exit(1);
