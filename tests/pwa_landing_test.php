<?php
// tests/pwa_landing_test.php
//
// Phase-1 PWA landing suite: generator output (files, manifest, SW, serve-time
// placeholders), the /lander/<slug>/ macro pass ({lp_url}/{subid} from the
// orbitra_click cookie), the click-flow 302 into the landing's own scope, the
// /pixel.gif?action=pwa beacon dedup, and the pwa_* report aggregation math.
//
// Run: php tests/pwa_landing_test.php

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/../core/PwaLanding.php';
require_once __DIR__ . '/../core/ReportMetrics.php';

$testPassed = true;
function assertTrue($condition, string $message): bool {
    global $testPassed;
    if (!$condition) {
        fwrite(STDERR, "FAILED: $message\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return (bool) $condition;
}
function assertContains(string $needle, $haystack, string $message): bool {
    global $testPassed;
    if (strpos((string) $haystack, $needle) === false) {
        fwrite(STDERR, "FAILED: $message\n  Expected to contain: $needle\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return true;
}
function assertNotContains(string $needle, $haystack, string $message): bool {
    global $testPassed;
    if (strpos((string) $haystack, $needle) !== false) {
        fwrite(STDERR, "FAILED: $message\n  Must NOT contain: $needle\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return true;
}
function cleanupDir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (array_reverse(glob($dir . '/*') ?: []) as $item) {
        is_dir($item) ? cleanupDir($item) : @unlink($item);
    }
    @rmdir($dir);
}

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);
$madeDirs = [];

try {
    $harness->start();
    // One throwaway request boots the server and runs the migrations on the
    // sandboxed DB copy; without it the columns below are checked too early.
    $harness->get('/nonexistent-boot-probe');
    $pdo = $harness->getPdo();

    // Migration 43 must have run on the sandboxed DB copy at boot. PRAGMA
    // table_info rows are (cid, name, ...) — fetch column 1 for names.
    $cols = $pdo->query("PRAGMA table_info(clicks)")->fetchAll(PDO::FETCH_COLUMN, 1);
    assertTrue(in_array('pwa_intent_at', $cols, true)
        && in_array('pwa_install_at', $cols, true)
        && in_array('pwa_open_count', $cols, true), 'migration 43 added clicks.pwa_* columns');
    $lcols = $pdo->query("PRAGMA table_info(landings)")->fetchAll(PDO::FETCH_COLUMN, 1);
    assertTrue(in_array('config_json', $lcols, true), 'migration 43 added landings.config_json');

    // ------------------------------------------------------------------
    // normalizeConfig: junk in, sane defaults out.
    // ------------------------------------------------------------------
    $junk = PwaLanding::normalizeConfig([
        'pwa' => true,
        'app_name' => 'Test App',
        'not_a_key' => 'drop me',
        'rating_counts' => 'garbage',
        'auto_redirect' => 9999,
        'comments' => [['name' => 'Ann', 'text' => 'Great', 'stars' => 99, 'likes' => -5]],
        'screens' => ['a.png', 42, null],
    ]);
    assertTrue($junk !== [] && $junk['app_name'] === 'Test App', 'normalizeConfig keeps pwa + app_name');
    assertTrue(!array_key_exists('not_a_key', $junk), 'normalizeConfig drops unknown keys');
    assertTrue($junk['auto_redirect'] === 180, 'normalizeConfig clamps timers to 180');
    assertTrue($junk['comments'][0]['stars'] === 5 && $junk['comments'][0]['likes'] === 0, 'comment stars/likes clamped');
    assertTrue($junk['screens'] === ['a.png', '42'], 'screens coerced to scalar strings');
    assertTrue(PwaLanding::normalizeConfig(['app_name' => 'no pwa flag']) === [], 'missing pwa flag → empty config');

    // ------------------------------------------------------------------
    // generate(): three files, placeholders NOT baked in.
    // ------------------------------------------------------------------
    $slug = 'pwa-test-' . bin2hex(random_bytes(4));
    $config = PwaLanding::normalizeConfig([
        'pwa' => true,
        'app_name' => 'Lucky Spin',
        'developer' => 'PlayBest Ltd',
        'description' => 'Best app by {value1}, download {value} now',
        'tags' => ['casino', 'slots'],
        'rating_counts' => [120, 30, 10, 4, 2],
        'comments' => [['name' => 'Anna', 'text' => 'Works great', 'stars' => 5, 'likes' => 3, 'date' => '2026-08-30', 'reply' => 'Thanks!']],
        'screens' => ['missing.png'],
        'color_scheme' => 'purple',
        'theme_mode' => 'dark',
        'ios_flow' => 'instruction',
        'auto_redirect' => 45,
    ]);
    $pdo->prepare("INSERT INTO landings (name, url, type, state, slug, config_json) VALUES (?, '', 'local', 'active', ?, ?)")
        ->execute(['Lucky Spin PWA', $slug, json_encode($config, JSON_UNESCAPED_UNICODE)]);
    $landingId = (int) $pdo->lastInsertId();

    require_once __DIR__ . '/../core/landing_path.php';
    $dir = orbitraLandingDir($pdo, $landingId);
    $madeDirs[] = $dir;
    $written = PwaLanding::generate($pdo, $landingId);
    assertTrue($written === ['index.html', 'manifest.webmanifest', 'sw.js'], 'generate() wrote the three owned files');

    // The server runs from the sandboxed working dir: mirror the generated
    // files there or its lander route cannot find them.
    $sandboxDir = $harness->getWorkingDir() . '/landings/' . $slug;
    @mkdir($sandboxDir, 0775, true);
    foreach ($written as $fname) {
        copy($dir . '/' . $fname, $sandboxDir . '/' . $fname);
    }

    $html = (string) file_get_contents($dir . '/index.html');
    assertContains('rel="manifest" href="manifest.webmanifest"', $html, 'index.html links the manifest');
    assertContains('{lp_url}', $html, 'index.html keeps {lp_url} as a serve-time placeholder');
    assertContains('{subid}', $html, 'index.html keeps {subid} as a serve-time placeholder');
    assertContains('pwa-install-btn', $html, 'index.html renders the install button');
    assertContains('Best app by PlayBest Ltd, download Lucky Spin now', $html, 'description macros resolved at generation time');
    assertContains('id="pwa-ios"', $html, 'iOS instruction overlay present');
    assertNotContains($html, '_token=', 'no click token baked into the static page');
    assertTrue(!strpos($html, 'missing.png'), 'non-existent screen dropped from the strip');

    $manifest = json_decode((string) file_get_contents($dir . '/manifest.webmanifest'), true);
    assertTrue(is_array($manifest) && $manifest['scope'] === './' && $manifest['display'] === 'standalone'
        && $manifest['start_url'] === './', 'manifest: relative scope/start_url, standalone');
    $sw = (string) file_get_contents($dir . '/sw.js');
    assertContains("req.mode === 'navigate'", $sw, 'sw.js serves navigations network-first');
    assertContains('orbitra-pwa-' . $landingId, $sw, 'sw.js cache name scoped to the landing id');

    // ------------------------------------------------------------------
    // Media-library URLs: icon_url wins, URL screens survive, missing local
    // files are still dropped.
    // ------------------------------------------------------------------
    $urlConfig = PwaLanding::normalizeConfig([
        'pwa' => true,
        'app_name' => 'URL Assets App',
        'icon_url' => '/uploads/media/ab/abcd1234-icon.png',
        'screens' => ['/uploads/media/cd/cdef5678-shot.png', 'gone-local.png'],
    ]);
    $urlSlug = 'pwa-url-' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO landings (name, url, type, state, slug, config_json) VALUES (?, '', 'local', 'active', ?, ?)")
        ->execute(['URL Assets PWA', $urlSlug, json_encode($urlConfig, JSON_UNESCAPED_UNICODE)]);
    $urlLandingId = (int) $pdo->lastInsertId();
    $madeDirs[] = orbitraLandingDir($pdo, $urlLandingId);
    PwaLanding::generate($pdo, $urlLandingId);
    $urlDir = orbitraLandingDir($pdo, $urlLandingId);
    $urlHtml = (string) file_get_contents($urlDir . '/index.html');
    assertContains('src="/uploads/media/ab/abcd1234-icon.png"', $urlHtml, 'icon_url from the media library rendered as the app icon');
    assertContains('onerror=', $urlHtml, 'broken images degrade silently (hide, not torn-icon glyph)');
    assertContains('4.8K reviews', $urlHtml, 'rating totals render compactly (K/M, not raw 4845)');
    // Ads label must surface in BOTH layouts (it lives under the iOS GET
    // button and in the Google Play hero line).
    assertContains('ios-in-app-text">Contains ads', $urlHtml, 'ads label visible on the iOS layout too');
    // Default app behavior: opening the installed app stays on the listing
    // unless the operator chose an action; the flag travels through cfg JSON.
    assertContains('"appAction":"store"', $urlHtml, 'default app action is the store listing');
    // Regression: buckets are [5★..1★] (frontend order). Weighing index 0 as
    // one star rendered every preset listing at ~1.2★.
    assertContains('<div class="big-avg">4.8</div>', $urlHtml, 'average computed with frontend bucket order (4.8, not 1.2)');
    assertContains('/uploads/media/cd/cdef5678-shot.png', $urlHtml, 'media-library screen URL survives generation');
    assertTrue(!strpos($urlHtml, 'gone-local.png'), 'missing local screen still dropped');
    $urlManifest = json_decode((string) file_get_contents($urlDir . '/manifest.webmanifest'), true);
    assertTrue(($urlManifest['icons'][0]['src'] ?? '') === '/uploads/media/ab/abcd1234-icon.png',
        'manifest icon points at the media-library URL');

    // ------------------------------------------------------------------
    // renderPreview: production renderer, neutralized macros, iOS flag.
    // ------------------------------------------------------------------
    $prev = PwaLanding::renderPreview($urlConfig, 'auto');
    assertContains('<!DOCTYPE html>', $prev, 'preview renders the full store page');
    assertTrue(strpos($prev, '{subid}') === false && strpos($prev, '{lp_url}') === false,
        'preview placeholders neutralized (no raw macros leak into the iframe)');
    assertContains("lpUrl = '#'", $prev, 'preview transition links are inert');
    assertTrue(strpos($prev, '__PWA_FORCE_IOS = true') === false, 'auto preview does not set the iOS force flag');
    $prevIos = PwaLanding::renderPreview($urlConfig, 'ios');
    assertContains('window.__PWA_FORCE_IOS = true', $prevIos, 'ios preview forces the instruction overlay');
    // Regression: the flag has to precede the store-picking script in <head>
    // — injected late, both preview toggles rendered the same Google Play
    // layout and the App Store design was unreachable from the constructor.
    assertTrue(
        strpos($prevIos, '__PWA_FORCE_IOS') < strpos($prevIos, 'data-store'),
        'iOS flag injected before the store-picking script runs'
    );
    assertContains('class="store-layout store-gp"', $prev, 'preview contains Google Play store layout');
    assertContains('class="store-layout store-ios"', $prev, 'preview contains Apple App Store layout');
    assertContains('ios-metrics-ribbon', $prev, 'preview contains Apple metric strip');
    assertContains('ios-info-table', $prev, 'preview contains Apple information table');

    // In-app screen (app_action=screen)
    $screenConfig = array_merge($urlConfig, [
        'app_action' => 'screen',
        'app_screen_title' => 'VIP Casino Bonus',
        'app_screen_text' => 'Spin & Win',
        'app_screen_button' => 'Claim Bonus',
        'app_screen_image' => '/assets/pwa-presets/lucky-casino/app-hero.png',
    ]);
    $prevScreen = PwaLanding::renderPreview($screenConfig, 'auto', 'screen');
    assertContains('window.__PWA_FORCE_APP_SCREEN = true', $prevScreen, 'screen preview forces the in-app screen');
    assertContains('id="pwa-app-screen"', $prevScreen, 'app screen container rendered');
    assertContains('class="appscr-header"', $prevScreen, 'app screen has native header');
    assertContains('class="appscr-balance-pill"', $prevScreen, 'app screen has balance pill');
    assertContains('class="appscr-tiles-row"', $prevScreen, 'app screen has lobby feature tiles');
    assertContains('class="appscr-tabbar"', $prevScreen, 'app screen has native tab bar');
    assertContains('VIP Casino Bonus', $prevScreen, 'app screen custom title rendered');

    // Action destinations: to_campaign, to_url, not_found
    $campConfig = PwaLanding::normalizeConfig([
        'pwa' => true,
        'app_name' => 'Campaign Route App',
        'action_target' => 'to_campaign',
        'action_campaign_id' => 777,
    ]);
    assertTrue($campConfig['action_target'] === 'to_campaign' && $campConfig['action_campaign_id'] === 777, 'action_target to_campaign normalized');
    $campLandingId = random_int(10000, 99999);
    $campDir = orbitraLandingDir($pdo, $campLandingId);
    $madeDirs[] = $campDir;
    $pdo->prepare("INSERT INTO landings (id, name, type, url, state, config_json) VALUES (?, ?, 'local', '', 'active', ?)")
        ->execute([$campLandingId, 'Camp Action Landing', json_encode($campConfig)]);
    PwaLanding::generate($pdo, $campLandingId);
    $campHtml = (string) file_get_contents($campDir . '/index.html');
    assertContains('/?campaign_id=777&subid={subid}', $campHtml, 'to_campaign generates campaign transition link in JS');

    $urlTargetConfig = PwaLanding::normalizeConfig([
        'pwa' => true,
        'app_name' => 'URL Route App',
        'action_target' => 'to_url',
        'action_url' => 'https://custom-destination.example.com/?subid={subid}',
    ]);
    $urlTargetLandingId = random_int(10000, 99999);
    $urlTargetDir = orbitraLandingDir($pdo, $urlTargetLandingId);
    $madeDirs[] = $urlTargetDir;
    $pdo->prepare("INSERT INTO landings (id, name, type, url, state, config_json) VALUES (?, ?, 'local', '', 'active', ?)")
        ->execute([$urlTargetLandingId, 'URL Action Landing', json_encode($urlTargetConfig)]);
    PwaLanding::generate($pdo, $urlTargetLandingId);
    $urlTargetHtml = (string) file_get_contents($urlTargetDir . '/index.html');
    assertContains('https://custom-destination.example.com/?subid={subid}', $urlTargetHtml, 'to_url generates custom URL transition link in JS');

    $notFoundConfig = PwaLanding::normalizeConfig([
        'pwa' => true,
        'app_name' => '404 App',
        'action_target' => 'not_found',
    ]);
    $notFoundLandingId = random_int(10000, 99999);
    $notFoundDir = orbitraLandingDir($pdo, $notFoundLandingId);
    $madeDirs[] = $notFoundDir;
    $pdo->prepare("INSERT INTO landings (id, name, type, url, state, config_json) VALUES (?, ?, 'local', '', 'active', ?)")
        ->execute([$notFoundLandingId, '404 Action Landing', json_encode($notFoundConfig)]);
    PwaLanding::generate($pdo, $notFoundLandingId);
    $notFoundHtml = (string) file_get_contents($notFoundDir . '/index.html');
    assertContains("var lpUrl = '/404';", $notFoundHtml, 'not_found generates 404 transition link in JS');

    // Test Interactive 777 Slot Machine In-App Screen
    $slotConfig = PwaLanding::normalizeConfig([
        'pwa' => true,
        'app_name' => 'Mega 777 Slots',
        'app_action' => 'screen',
        'app_screen_type' => 'slot',
    ]);
    $slotLandingId = random_int(10000, 99999);
    $slotDir = orbitraLandingDir($pdo, $slotLandingId);
    $madeDirs[] = $slotDir;
    $pdo->prepare("INSERT INTO landings (id, name, type, url, state, config_json) VALUES (?, ?, 'local', '', 'active', ?)")
        ->execute([$slotLandingId, 'Slot Screen Landing', json_encode($slotConfig)]);
    PwaLanding::generate($pdo, $slotLandingId);
    $slotHtml = (string) file_get_contents($slotDir . '/index.html');
    assertContains('pwa-slot-cabinet', $slotHtml, 'slot mode generates slot cabinet');
    assertContains('pwa-slot-spin-btn', $slotHtml, 'slot mode contains spin button');
    assertContains('pwa-slot-win-modal', $slotHtml, 'slot mode contains win modal');
    assertContains('pwa-countdown', $slotHtml, 'slot win modal includes countdown timer');
    assertContains('window.orbitraRedirect = redirect;', $slotHtml, 'global redirect helper exposed');

    // Test Interactive Fortune Wheel In-App Screen
    $wheelConfig = PwaLanding::normalizeConfig([
        'pwa' => true,
        'app_name' => 'Lucky Fortune Wheel',
        'app_action' => 'screen',
        'app_screen_type' => 'wheel',
    ]);
    $wheelLandingId = random_int(10000, 99999);
    $wheelDir = orbitraLandingDir($pdo, $wheelLandingId);
    $madeDirs[] = $wheelDir;
    $pdo->prepare("INSERT INTO landings (id, name, type, url, state, config_json) VALUES (?, ?, 'local', '', 'active', ?)")
        ->execute([$wheelLandingId, 'Wheel Screen Landing', json_encode($wheelConfig)]);
    PwaLanding::generate($pdo, $wheelLandingId);
    $wheelHtml = (string) file_get_contents($wheelDir . '/index.html');
    assertContains('pwa-wheel-disc', $wheelHtml, 'wheel mode generates fortune wheel SVG disc');
    assertContains('pwa-wheel-spin-btn', $wheelHtml, 'wheel mode contains wheel spin button');
    assertContains('pwa-wheel-win-modal', $wheelHtml, 'wheel mode contains wheel win modal');

    // Test Custom HTML & JS In-App Screen & Global Code Injection
    $customConfig = PwaLanding::normalizeConfig([
        'pwa' => true,
        'app_name' => 'Custom Roulette App',
        'app_action' => 'screen',
        'app_screen_type' => 'custom',
        'app_screen_custom_html' => '<div id="custom-roulette-container">CUSTOM ROULETTE</div>',
        'app_screen_custom_js' => 'console.log("custom game loaded");',
        'custom_head_code' => '<!-- FB PIXEL 123456 -->',
        'custom_js' => 'console.log("global custom js running");',
    ]);
    $customLandingId = random_int(10000, 99999);
    $customDir = orbitraLandingDir($pdo, $customLandingId);
    $madeDirs[] = $customDir;
    $pdo->prepare("INSERT INTO landings (id, name, type, url, state, config_json) VALUES (?, ?, 'local', '', 'active', ?)")
        ->execute([$customLandingId, 'Custom Code Landing', json_encode($customConfig)]);
    PwaLanding::generate($pdo, $customLandingId);
    $customHtml = (string) file_get_contents($customDir . '/index.html');
    assertContains('<div id="custom-roulette-container">CUSTOM ROULETTE</div>', $customHtml, 'custom screen renders custom HTML');
    assertContains('console.log("custom game loaded");', $customHtml, 'custom screen injects custom script');
    assertContains('<!-- FB PIXEL 123456 -->', $customHtml, 'custom head code injected');
    assertContains('console.log("global custom js running");', $customHtml, 'custom global JS injected');

    try {
        PwaLanding::renderPreview(['app_name' => 'no pwa flag'], 'auto');
        fwrite(STDERR, "FAILED: renderPreview must reject a non-PWA config\n");
        $testPassed = false;
    } catch (InvalidArgumentException $e) {
        echo "✓ renderPreview rejects a non-PWA config\n";
    }

    // ------------------------------------------------------------------
    // Lander route: macros resolve from the orbitra_click cookie.
    // ------------------------------------------------------------------
    $campaignId = random_int(10000, 99999);
    $offerId = random_int(10000, 99999);
    $streamId = random_int(10000, 99999);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived) VALUES (?, ?, ?, ?, 'active', 0)")
        ->execute([$campaignId, 'PWA Test Campaign', 'pwacamp' . $campaignId, 'pwa_token_' . $campaignId]);
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, ?, ?, 0, 'active', 0)")
        ->execute([$offerId, 'PWA Test Offer', 'https://example.com/offer']);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks)
                   VALUES (?, ?, ?, 'PWA Stream', 'regular', 1, 'landing_offer', ?, 1, 1)")
        ->execute([$streamId, $campaignId, $offerId, json_encode([
            'schema_type' => 'landing_offer',
            'offer_selection' => 'after',
            'landings' => [['id' => $landingId, 'weight' => 100]],
            'offers' => [['id' => $offerId, 'weight' => 100]],
        ])]);

    $mobileUa = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';
    $baseline = $harness->getClickCount();
    $resp = $harness->getWithHeaders("/?campaign_id=$campaignId", [
        'User-Agent: ' . $mobileUa,
        'X-Forwarded-For: 103.212.120.7',
    ]);
    $newClicks = $harness->getNewClicksSince($baseline);
    // The harness follows the 302 into /lander/<slug>/, and the cold lander
    // view the campaign link points at now logs its own organic visit (the
    // campaign-link entry contract) — so two clicks arrive: the campaign one
    // and the organic one. Assert the campaign click by its campaign_id.
    $campaignClicks = array_values(array_filter($newClicks, static fn ($c) => (int) ($c['campaign_id'] ?? 0) === $campaignId));
    assertTrue(count($campaignClicks) === 1, 'click row created for the PWA campaign');
    $clickId = (string) $campaignClicks[0]['id'];
    // The harness does not follow redirects: the click request must answer
    // 302 straight into the landing's own scope.
    $loc = (string) ($resp['headers']['Location'] ?? '');
    assertTrue(in_array($resp['code'] ?? 0, [301, 302], true), "click request answers a redirect (got {$resp['code']})");
    assertTrue(strpos($loc, '/lander/' . $slug . '/') === 0, "redirect targets /lander/$slug/ (got $loc)");
    // Long-lived attribution cookie rides the redirect response itself.
    assertContains('orbitra_pwa_click', (string) ($resp['headers']['Set-Cookie'] ?? $resp['headers']['set-cookie'] ?? ''),
        'PWA redirect sets the 30-day orbitra_pwa_click cookie');
    $row = $pdo->query("SELECT landing_at FROM clicks WHERE id = " . $pdo->quote($clickId))->fetch(PDO::FETCH_ASSOC);
    assertTrue($row && $row['landing_at'] !== null, 'landing_at stamped before the redirect');

    // Renderer auto-heal: statics written by an older renderer regenerate on
    // the next HTML view, so renderer upgrades reach existing PWAs.
    file_put_contents($sandboxDir . '/index.html', '<html><body>stale page</body></html>');
    $resp = $harness->getWithHeaders("/lander/$slug/", [
        'User-Agent: ' . $mobileUa,
        'Cookie: orbitra_click=' . $clickId,
    ]);
    if (($resp['code'] ?? 0) !== 200) {
        fwrite(STDERR, "DEBUG lander-with-cookie code=" . ($resp['code'] ?? -1)
            . " body=" . substr((string) ($resp['body'] ?? ''), 0, 400) . "\n");
    }
    assertTrue(($resp['code'] ?? 0) === 200, 'lander route serves the PWA page (200)');
    assertContains('orbitra-renderer', $resp['body'] ?? '', 'stale statics auto-healed to the current renderer version');
    assertContains('pwa-install-btn', $resp['body'] ?? '', 'healed page is the full generated store page');
    assertContains('_token=', $resp['body'] ?? '', 'cookie click → {lp_url} carries a signed token');
    assertContains("subid = '$clickId'", $resp['body'] ?? '', 'cookie click → {subid} injected for beacons');
    assertNotContains($resp['body'] ?? '', '{lp_url}', 'placeholder fully replaced in serve');

    // Token roundtrip: the {lp_url} the page carries must actually verify and
    // redirect to the offer. The lander signs with the DB postback key loaded
    // on the fly — $settings is NOT populated at lander dispatch time, and a
    // fallback-constant signature would be rejected right here.
    if (preg_match("/lpUrl = '([^']+)'/", (string) ($resp['body'] ?? ''), $m)) {
        $lpResp = $harness->getWithHeaders($m[1], [
            'User-Agent: ' . $mobileUa,
            'Cookie: orbitra_click=' . $clickId,
        ]);
        assertTrue(in_array($lpResp['code'] ?? 0, [301, 302], true), "signed {lp_url} verifies and redirects (got {$lpResp['code']})");
        assertContains('example.com', (string) ($lpResp['headers']['Location'] ?? ''), 'LP transition heads to the offer URL');
    } else {
        assertTrue(false, 'failed to extract lpUrl from the served page');
    }

    // Without the cookie: the cold visit logs exactly one organic click
    // attributed to the orbitra-pwa-organic system campaign, the fresh subid
    // rides the page for the beacons, and the {lp_url} carries its signed
    // token — the whole funnel a cold /lander/<slug>/ campaign link must have.
    $baselineNoCookie = $harness->getClickCount();
    $resp = $harness->getWithHeaders("/lander/$slug/", ['User-Agent: ' . $mobileUa]);
    if (($resp['code'] ?? 0) !== 200) {
        fwrite(STDERR, "DEBUG lander-no-cookie code=" . ($resp['code'] ?? -1)
            . " body=" . substr((string) ($resp['body'] ?? ''), 0, 400) . "\n");
    }
    $organicClicks = $harness->getNewClicksSince($baselineNoCookie);
    assertTrue(count($organicClicks) === 1, 'cold lander view logs exactly one organic click');
    $organicId = (string) $organicClicks[0]['id'];
    $organicAlias = (string) $pdo->query("SELECT c.alias FROM clicks k JOIN campaigns c ON c.id = k.campaign_id WHERE k.id = " . $pdo->quote($organicId))->fetchColumn();
    assertContains('orbitra-pwa-organic', $organicAlias, 'cold visit attributes to the system organic campaign');
    // The harness keeps only the LAST repeated Set-Cookie header; the lander
    // response closes with the 30-day orbitra_pwa_click cookie, so its
    // presence proves the whole cookie family (orbitra_click, subid,
    // orbitra_lp) shipped in the same response.
    assertContains('orbitra_pwa_click=', (string) ($resp['headers']['Set-Cookie'] ?? $resp['headers']['set-cookie'] ?? ''),
        'cold visit sets the attribution cookies in the same response');
    assertContains("subid = '$organicId'", $resp['body'] ?? '', 'cold visit → fresh organic subid injected for beacons');
    assertContains('_token=', $resp['body'] ?? '', 'cold visit → {lp_url} carries a signed token');

    // A ?_preview= look-see stays uncounted: the operator inspecting their own
    // store must not manufacture organic traffic.
    $baselinePreview = $harness->getClickCount();
    $harness->getWithHeaders("/lander/$slug/?_preview=1788363413", ['User-Agent: ' . $mobileUa]);
    assertTrue($harness->getClickCount() === $baselinePreview, '?_preview= look-sees log nothing');

    // ------------------------------------------------------------------
    // pixel.gif?action=pwa: dedup gates and throttle.
    // ------------------------------------------------------------------
    $mk = function () use ($pdo, $campaignId) {
        $id = 'pwaclk-' . bin2hex(random_bytes(6));
        $pdo->prepare("INSERT INTO clicks (id, campaign_id, offer_id, ip, user_agent, is_bot) VALUES (?, ?, NULL, '1.2.3.4', 'UA', 0)")
            ->execute([$id, $campaignId]);
        return $id;
    };
    $get = function (string $path) use ($harness) {
        return $harness->getWithHeaders($path, ['User-Agent: test']);
    };

    // install: second beacon must not move the timestamp.
    $cInstall = $mk();
    $get("/pixel.gif?action=pwa&kind=install&subid=$cInstall");
    $t1 = $pdo->query("SELECT pwa_install_at FROM clicks WHERE id = " . $pdo->quote($cInstall))->fetchColumn();
    assertTrue(is_string($t1) && $t1 !== '', 'install beacon stamps pwa_install_at');
    usleep(1100000); // datetime('now') has 1-second resolution
    $get("/pixel.gif?action=pwa&kind=install&subid=$cInstall");
    $t2 = $pdo->query("SELECT pwa_install_at FROM clicks WHERE id = " . $pdo->quote($cInstall))->fetchColumn();
    assertTrue($t1 === $t2, 'repeated install beacon deduped by the NULL guard');

    // intent: same gate.
    $cIntent = $mk();
    $get("/pixel.gif?action=pwa&kind=intent&subid=$cIntent");
    assertTrue((bool) $pdo->query("SELECT pwa_intent_at FROM clicks WHERE id = " . $pdo->quote($cIntent))->fetchColumn(),
        'intent beacon stamps pwa_intent_at');

    // open: count grows once, immediate second open is throttled.
    $cOpen = $mk();
    $get("/pixel.gif?action=pwa&kind=open&subid=$cOpen");
    $get("/pixel.gif?action=pwa&kind=open&subid=$cOpen");
    $cnt = (int) $pdo->query("SELECT pwa_open_count FROM clicks WHERE id = " . $pdo->quote($cOpen))->fetchColumn();
    assertTrue($cnt === 1, "open beacon throttled to one per 10 minutes (got $cnt)");

    // Junk is a silent no-op.
    $cJunk = $mk();
    $r = $get("/pixel.gif?action=pwa&kind=whatever&subid=$cJunk");
    $r2 = $get("/pixel.gif?action=pwa&kind=install&subid=does-not-exist");
    assertTrue(($r['code'] ?? 0) === 200 && ($r2['code'] ?? 0) === 200, 'unknown kind / unknown click still answer the GIF');

    // ------------------------------------------------------------------
    // Aggregation: landings stats SQL + derived metrics.
    // ------------------------------------------------------------------
    $mSlug = 'pwa-metrics-' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO landings (name, url, type, state, slug, config_json) VALUES (?, '', 'local', 'active', ?, ?)")
        ->execute(['PWA Metrics', $mSlug, json_encode($config)]);
    $mLandingId = (int) $pdo->lastInsertId();
    $madeDirs[] = orbitraLandingDir($pdo, $mLandingId); // never generated; dir may not exist — cleanup tolerates

    $m1 = 'pwam-1-' . bin2hex(random_bytes(4));
    $m2 = 'pwam-2-' . bin2hex(random_bytes(4));
    $m3 = 'pwam-3-' . bin2hex(random_bytes(4));
    $ins = $pdo->prepare("INSERT INTO clicks (id, campaign_id, landing_id, ip, user_agent, is_bot, pwa_intent_at, pwa_install_at, pwa_open_count)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([$m1, $campaignId, $mLandingId, '9.9.9.9', 'UA', 0, '2026-09-01 10:00:00', '2026-09-01 10:01:00', 2]);
    $ins->execute([$m2, $campaignId, $mLandingId, '9.9.9.8', 'UA', 1, null, '2026-09-01 11:00:00', 0]);
    $ins->execute([$m3, $campaignId, $mLandingId, '9.9.9.7', 'UA', 0, null, null, 0]);

    // Full engine over ALL landings, then pick our row: the joinCondition
    // parameter filters every landing's clicks at once, so it cannot scope
    // the query to one id.
    $stmt = $pdo->query(orbitraLandingsWithStatsSql('', null, null));
    $stats = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $lrow) {
        if ((int) $lrow['id'] === $mLandingId) {
            $stats = $lrow;
            break;
        }
    }
    assertTrue(is_array($stats), 'landings stats SQL runs with pwa columns');
    // clicks here: the landing's own 3 synthetic rows (AND 1=0 keeps other
    // landings' clicks out; HAVING picks the row back).
    $derived = orbitraComputeDerivedMetrics($stats);
    assertTrue((int) $stats['clicks'] === 3, 'stats row counts exactly the 3 seeded clicks');
    assertTrue((int) $stats['pwa_intents'] === 1, 'pwa_intents aggregated');
    assertTrue((int) $stats['pwa_installs'] === 2, 'pwa_installs counts bots too');
    assertTrue((int) $stats['pwa_installs_real'] === 1, 'pwa_installs_real excludes the bot click');
    assertTrue((int) $stats['pwa_opens'] === 2, 'pwa_opens sums the throttled counter');
    assertTrue((int) $derived['real_pwa_installs'] === 1, 'derived real_pwa_installs = 1');
    assertTrue((float) $derived['pwa_install_rate'] === 33.33, 'derived install rate 1/3 = 33.33%');
} catch (Throwable $e) {
    fwrite(STDERR, 'EXCEPTION: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $testPassed = false;
} finally {
    $harness->stop();
    foreach ($madeDirs as $d) {
        cleanupDir($d);
    }
}

echo $testPassed ? "\nALL TESTS PASSED\n" : "\nSOME TESTS FAILED\n";
exit($testPassed ? 0 : 1);
