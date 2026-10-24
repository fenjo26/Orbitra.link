<?php
// tests/conversion_popup_test.php
//
// Conversion popups (Settings → Plugins) — the full server contract:
//
//   1. Migration 71: clicks gains popup_shown/popup_clicked/popup_closed/
//      popup_variant; campaigns gains popup_mode defaulting to 'inherit'.
//   2. Off by default: a landing visit injects NO popup script.
//   3. Enabled: the landing body carries the shared /popup.js tag with the
//      whole config in data-orbitra-popup (offer = the signed transition).
//   4. Server gating: bots and safe-page visitors get no payload; campaign
//      popup_mode 'off' overrides the global switch, 'on' overrides an
//      global OFF.
//   5. /pixel.gif?action=popup: shown/click/close write the funnel with MAX
//      semantics; the FIRST variant wins; unknown events are a no-op GIF.
//   6. click_api info=1: info.popup rides the response (external sites via
//      tracking.js / kclient) with an absolute script/offer/endpoint.
//   7. The shared dashboard SQL + derived metrics expose the popup funnel.
//
// Run: php tests/conversion_popup_test.php

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
    // Writes below interleave with the router process's own writes (the click
    // funnel flushes after the response); SQLite's default busy timeout is 0,
    // so a colliding microsecond turns into "database is locked".
    $pdo->exec('PRAGMA busy_timeout = 5000');

    // --- 1. Migration 71 objects ---
    $clickCols = [];
    foreach ($pdo->query("PRAGMA table_info(clicks)") as $c) {
        $clickCols[$c['name']] = true;
    }
    foreach (['popup_shown', 'popup_clicked', 'popup_closed', 'popup_variant'] as $col) {
        assertTrue(isset($clickCols[$col]), "clicks.$col exists (migration 71)");
    }
    $campCols = [];
    foreach ($pdo->query("PRAGMA table_info(campaigns)") as $c) {
        $campCols[$c['name']] = true;
    }
    assertTrue(isset($campCols['popup_mode']), 'campaigns.popup_mode exists (migration 71)');

    // Seed: one action landing (show_html) + one offer + campaign + stream,
    // the same shape the pointer-activity test rides.
    $cid = random_int(400000, 499998);
    $sid = $cid + 1;
    $lid = $cid + 2;
    $oid = $cid + 3;
    $token = 'pptoken' . bin2hex(random_bytes(8));

    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived)
                   VALUES (?, 'PP Offer', 'https://pp-net.example/track', 0, 'active', 0)")->execute([$oid]);
    $pdo->prepare("INSERT INTO landings (id, name, url, type, action_type, action_payload, state, is_archived)
                   VALUES (?, 'PP LP', '', 'action', 'show_html',
                           '<html><body>PP_LP_MARKER <a href=\"{offer}\">go</a></body></html>', 'active', 0)")->execute([$lid]);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived)
                   VALUES (?, 'PP Camp', ?, ?, 'active', 0)")->execute([$cid, "ppcamp$cid", $token]);
    $schema = json_encode([
        'landings' => [['id' => $lid, 'weight' => 100, 'state' => 'active']],
        'offers'   => [['id' => $oid, 'weight' => 100, 'state' => 'active']],
    ]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks, offer_selection)
                   VALUES (?, ?, NULL, 'PP Stream', 'regular', 1, 'landing_offer', ?, 1, 1, 'before')")->execute([$sid, $cid, $schema]);
    foreach (['postback_key' => 'pp_key', 'ignore_prefetch' => '0', 'stats_enabled' => '1', 'popup_enabled' => '0'] as $k => $v) {
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$k, $v]);
    }

    $freshCampaignMode = $pdo->prepare("SELECT popup_mode FROM campaigns WHERE id = ?");
    $freshCampaignMode->execute([$cid]);
    // fetchColumn() leaves the cursor open — an un-fetched statement pins a
    // read snapshot and the next write on THIS connection deadlocks on itself.
    $freshModeValue = (string) $freshCampaignMode->fetchColumn();
    $freshCampaignMode->closeCursor();
    assertEquals('inherit', $freshModeValue, 'A fresh campaign inherits the global popup switch');

    $browserHeaders = [
        'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'X-Forwarded-For: 203.0.114.60',
        'Accept-Language: en-US,en;q=0.9',
    ];
    $clickRow = function (string $id) use ($pdo): array {
        $stmt = $pdo->prepare("SELECT * FROM clicks WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stmt->closeCursor();
        return $row;
    };
    $lastClickId = function () use ($pdo, $cid): string {
        $stmt = $pdo->prepare("SELECT id FROM clicks WHERE campaign_id = ? ORDER BY rowid DESC LIMIT 1");
        $stmt->execute([$cid]);
        $id = (string) $stmt->fetchColumn();
        $stmt->closeCursor();
        return $id;
    };

    // --- 2. Off by default: no popup anywhere in the body ---
    $respOff = $firstResponse("/ppcamp$cid", $browserHeaders);
    assertEquals(200, $respOff['code'], 'The action landing is served inline');
    assertContains('PP_LP_MARKER', $respOff['body'], 'The landing body is the action payload');
    assertTrue(strpos($respOff['body'], 'data-orbitra-popup') === false, 'Plugin off: no popup script is injected');

    // --- 3. Enabled: the shared script tag rides before </body> ---
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('popup_enabled', '1')")->execute();
    $respOn = $firstResponse("/ppcamp$cid", $browserHeaders);
    assertContains('PP_LP_MARKER', $respOn['body'], 'Enabled: the landing body is still served');
    assertContains('src="/popup.js"', $respOn['body'], 'Enabled: the shared /popup.js is referenced');
    assertContains('data-orbitra-popup="', $respOn['body'], 'Enabled: the whole client config rides the data attribute');
    assertContains('_lp=1&amp;_token=', $respOn['body'], 'Enabled: the button target is the signed landing→offer transition');

    $clickId = $lastClickId();
    assertTrue($clickId !== '', 'Enabled: the visit produced a click row (the popup binds to it)');

    // --- 4. Server gating (direct: core/PopupConfig.php) ---
    require_once $repoRoot . '/core/PopupConfig.php';

    $botId = 'pp-bot-' . $cid;
    $safeId = 'pp-safe-' . $cid;
    $humanId = 'pp-human-' . $cid;
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at, is_bot) VALUES (?, ?, '203.0.114.61', datetime('now'), 1)")
        ->execute([$botId, $cid]);
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at, is_safe_page) VALUES (?, ?, '203.0.114.62', datetime('now'), 1)")
        ->execute([$safeId, $cid]);
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at, country) VALUES (?, ?, '203.0.114.63', datetime('now'), 'DE')")
        ->execute([$humanId, $cid]);

    assertTrue(orbitraPopupPayloadForClick($pdo, $botId) === null, 'A bot click gets no popup payload at all');
    assertTrue(orbitraPopupPayloadForClick($pdo, $safeId) === null, 'A safe-page click gets no popup payload');
    $humanPayload = orbitraPopupPayloadForClick($pdo, $humanId);
    assertTrue(is_array($humanPayload), 'A human click resolves a payload');
    assertEquals('DE', $humanPayload['geo']['country'], 'The payload carries the click geo');
    assertEquals('EUR', $humanPayload['geo']['currencyCode'], 'The currency resolves from the country map');
    assertEquals(1, $humanPayload['v'], 'The payload speaks contract version 1');
    // Money texts quote {amount} in USD; popup.js converts with geo.rate.
    assertEquals(0.88, $humanPayload['geo']['rate'], 'The payload carries the local-units-per-USD rate (EUR)');
    assertEquals(26000.0, orbitraPopupUsdRate('VND'), 'VND rate: 250 must not print as "250 ₫"');
    assertEquals(1.0, orbitraPopupUsdRate('XXX'), 'An unknown currency falls back to rate 1');
    assertEquals('EUR', orbitraPopupCurrencyForCountry('BG')['code'], 'Bulgaria is on the euro since 2026');
    // No Gallery pick → the vertical's preset photo (neutral vertical here).
    assertEquals('/assets/popup-presets/neutral.webp', $humanPayload['image'], 'Without a Gallery pick the payload uses the vertical preset photo');
    assertEquals('/assets/popup-presets/gambling.webp', orbitraPopupPresetImage('gambling'), 'Each vertical maps to its own preset photo');
    assertEquals('', orbitraPopupPresetImage('../etc'), 'A malformed vertical never builds a path');

    // Campaign overrides of the global switch.
    $pdo->prepare("UPDATE campaigns SET popup_mode = 'off' WHERE id = ?")->execute([$cid]);
    assertTrue(orbitraPopupPayloadForClick($pdo, $humanId) === null, "popup_mode='off' overrides a global ON");
    $respOff2 = $firstResponse("/ppcamp$cid", $browserHeaders);
    assertTrue(strpos($respOff2['body'], 'data-orbitra-popup') === false, "popup_mode='off': no script injected on the landing");
    $pdo->prepare("UPDATE campaigns SET popup_mode = 'on' WHERE id = ?")->execute([$cid]);
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('popup_enabled', '0')")->execute();
    $onPayload = orbitraPopupPayloadForClick($pdo, $humanId);
    assertTrue(is_array($onPayload), "popup_mode='on' overrides a global OFF");
    $respOn2 = $firstResponse("/ppcamp$cid", $browserHeaders);
    assertContains('data-orbitra-popup="', $respOn2['body'], "popup_mode='on': the script is injected even with the plugin globally off");
    // No crossorigin attribute: it would turn the tag into a CORS fetch, and
    // nginx serves /popup.js without Access-Control-Allow-Origin.
    assertTrue(strpos($respOn2['body'], 'crossorigin') === false, 'The injected popup tag carries no crossorigin attribute');
    assertContains('charset="utf-8"', $respOn2['body'], 'The injected popup tag declares utf-8');
    $tagStart = strpos($respOn2['body'], '<script src="/popup.js"');
    $tag = $tagStart === false ? '' : substr($respOn2['body'], $tagStart, strpos($respOn2['body'], '</script>', $tagStart) - $tagStart);
    assertTrue($tag !== '' && !preg_match('/[\x80-\xFF]/', $tag), 'The popup tag is pure ASCII (currency symbols survive any landing charset)');
    assertTrue(strncmp((string) file_get_contents(dirname(__DIR__) . '/popup.js'), "\xEF\xBB\xBF", 3) === 0, 'popup.js starts with a UTF-8 BOM (texts survive a non-UTF-8 landing)');
    $pdo->prepare("UPDATE campaigns SET popup_mode = 'inherit' WHERE id = ?")->execute([$cid]);
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('popup_enabled', '1')")->execute();
    assertTrue(is_array(orbitraPopupPayloadForClick($pdo, $humanId)), "popup_mode='inherit' follows the global switch again");

    // Unknown/missing click: no payload.
    assertTrue(orbitraPopupPayloadForClick($pdo, 'pp-nosuch-' . $cid) === null, 'A missing click resolves no payload');

    // --- 5. /pixel.gif?action=popup funnel semantics ---
    $px = function (string $qs) use ($firstResponse, $browserHeaders): array {
        return $firstResponse('/pixel.gif?action=popup' . $qs, $browserHeaders);
    };

    $g = $px('&subid=' . urlencode($humanId) . '&e=shown&v=gift.1');
    assertEquals(200, $g['code'], 'The popup pixel answers 200');
    assertEquals('GIF', substr($g['body'], 0, 3), 'The popup pixel answers with the invisible GIF');
    $r = $clickRow($humanId);
    assertEquals(1, (int) $r['popup_shown'], 'e=shown writes popup_shown=1');
    assertEquals('gift.1', (string) $r['popup_variant'], 'e=shown records the variant');
    assertNull_($r['popup_clicked'], 'popup_clicked is still unset before the button press');

    $px('&subid=' . urlencode($humanId) . '&e=shown&v=card.0');
    $r = $clickRow($humanId);
    assertEquals('gift.1', (string) $r['popup_variant'], 'The FIRST variant wins — a later shown cannot rewrite it');

    $px('&subid=' . urlencode($humanId) . '&e=click&v=gift.1');
    $r = $clickRow($humanId);
    assertEquals(1, (int) $r['popup_clicked'], 'e=click writes popup_clicked=1');
    assertEquals(1, (int) $r['popup_shown'], 'e=click also guarantees popup_shown (the click implies it)');

    $px('&subid=' . urlencode($humanId) . '&e=close');
    $r = $clickRow($humanId);
    assertEquals(1, (int) $r['popup_closed'], 'e=close writes popup_closed=1');

    $px('&subid=' . urlencode($humanId) . '&e=nonsense');
    $r = $clickRow($humanId);
    assertEquals(1, (int) $r['popup_shown'], 'An unknown event changes nothing (still 1)');
    assertTrue($r['popup_variant'] === 'gift.1', 'An unknown event leaves the variant alone');

    // --- 6. click_api info=1 rides the popup config for external sites ---
    // A Gallery pick is stored root-relative; on a foreign site it must
    // come back absolute or the <img> would hit the visitor's own domain.
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('popup_image', '/uploads/media/ab/pp-test.webp')")->execute();
    $api = $firstResponse('/click_api/v3?token=' . urlencode($token) . '&info=1&se_referrer=https%3A%2F%2Fext.example%2F', $browserHeaders);
    $decoded = json_decode($api['body'], true);
    assertTrue(is_array($decoded), 'click_api answers JSON');
    $infoPopup = $decoded['info']['popup'] ?? null;
    assertTrue(is_array($infoPopup), 'info.popup is present when the plugin is on');
    assertContains('/popup.js', (string) ($infoPopup['script'] ?? ''), 'info.popup carries an absolute script URL');
    assertContains('_token=', (string) ($infoPopup['offer'] ?? ''), 'info.popup carries the signed offer transition');
    assertContains('/pixel.gif', (string) ($infoPopup['endpoint'] ?? ''), 'info.popup carries the absolute pixel endpoint');
    assertTrue((bool) preg_match('#^https?://[^/]+/uploads/media/ab/pp-test\.webp$#', (string) ($infoPopup['image'] ?? '')), 'info.popup carries an absolute image URL (it renders on a foreign domain)');
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('popup_image', '')")->execute();
    assertTrue(($infoPopup['geo']['country'] ?? '') !== '', 'info.popup resolves the click geo server-side');

    // The button the visitor presses continues through the tracker — that is
    // what makes popup CTR and the offer funnel the same click.
    $popjs = (string) file_get_contents($repoRoot . '/popup.js');
    assertContains("action=popup", $popjs, 'popup.js beacons through the shared pixel action');
    assertContains('sub_id_30=popup', $popjs, 'The button marks the click for the network side');
    assertContains('frequencyHours', $popjs, 'popup.js enforces the once-per-N-hours frequency');
    assertContains("seen.pointer && (seen.scroll || seen.key)", $popjs, 'Two distinct activity types, one of them pointer/touch');

    // tracking.js loads the shared script when the config is there.
    $tjs = (string) file_get_contents($repoRoot . '/tracking.js');
    assertContains('data-orbitra-popup', $tjs, 'tracking.js forwards the config into the shared popup.js tag');

    // --- 7. The shared dashboard SQL + derived metrics expose the funnel ---
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at, popup_shown, popup_clicked, popup_closed, popup_variant)
                   VALUES (?, ?, '203.0.114.64', datetime('now'), 1, 0, 1, 'card.0')")
        ->execute(['pp-extra-' . $cid, $cid]);

    require_once $repoRoot . '/core/ReportMetrics.php';
    $raw = $pdo->query(orbitraDashboardMetricsSql('payout', null))->fetch(PDO::FETCH_ASSOC);
    assertEquals(2, (int) $raw['popup_shown'], 'Dashboard SQL counts both shown clicks');
    assertEquals(1, (int) $raw['popup_clicked'], 'Dashboard SQL counts the one clicked click');
    // By now BOTH rows are closed: the human click pressed e=close, the
    // seeded extra row was born shown+closed (the dismissed-without-click
    // case the metric exists for).
    assertEquals(2, (int) $raw['popup_closed'], 'Dashboard SQL counts both closed clicks');
    assertEquals(0, (int) $raw['popup_conversions'], 'No conversions seeded — popup conversions stay 0');
    $m = orbitraComputeDerivedMetrics($raw ?: []);
    assertEquals(50.0, (float) $m['popup_ctr'], 'Derived popup CTR = 1 click / 2 shown = 50%');

    // The variant is a report dimension: NULLIF keeps no-popup clicks Unknown.
    $dim = $pdo->query("SELECT NULLIF(popup_variant, '') AS v, COUNT(*) AS n FROM clicks WHERE campaign_id = $cid GROUP BY NULLIF(popup_variant, '')")->fetchAll(PDO::FETCH_ASSOC);
    $byVariant = [];
    foreach ($dim as $d) { $byVariant[(string) $d['v']] = (int) $d['n']; }
    assertEquals(1, $byVariant['gift.1'], 'Variant dimension groups gift.1');
    assertEquals(1, $byVariant['card.0'], 'Variant dimension groups card.0');

    echo "\nAll conversion-popup checks completed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $testPassed = false;
} finally {
    $harness->stop();
}

// Local shim: NULL stays NULL (not measured), unlike 0 (measured as false).
function assertNull_($value, $message) {
    global $testPassed;
    if ($value !== null) {
        fwrite(STDERR, "FAILED: $message (got " . var_export($value, true) . ")\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
}

exit($testPassed ? 0 : 1);
