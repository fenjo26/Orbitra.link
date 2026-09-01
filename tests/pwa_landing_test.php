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
    assertTrue(count($newClicks) === 1, 'click row created for the PWA campaign');
    $clickId = (string) $newClicks[0]['id'];
    // The harness does not follow redirects: the click request must answer
    // 302 straight into the landing's own scope.
    $loc = (string) ($resp['headers']['Location'] ?? '');
    assertTrue(in_array($resp['code'] ?? 0, [301, 302], true), "click request answers a redirect (got {$resp['code']})");
    assertTrue(strpos($loc, '/lander/' . $slug . '/') === 0, "redirect targets /lander/$slug/ (got $loc)");
    $row = $pdo->query("SELECT landing_at FROM clicks WHERE id = " . $pdo->quote($clickId))->fetch(PDO::FETCH_ASSOC);
    assertTrue($row && $row['landing_at'] !== null, 'landing_at stamped before the redirect');

    // With the click cookie: fresh signed token + subid injected.
    $resp = $harness->getWithHeaders("/lander/$slug/", [
        'User-Agent: ' . $mobileUa,
        'Cookie: orbitra_click=' . $clickId,
    ]);
    if (($resp['code'] ?? 0) !== 200) {
        fwrite(STDERR, "DEBUG lander-with-cookie code=" . ($resp['code'] ?? -1)
            . " body=" . substr((string) ($resp['body'] ?? ''), 0, 400) . "\n");
    }
    assertTrue(($resp['code'] ?? 0) === 200, 'lander route serves the PWA page (200)');
    assertContains('_token=', $resp['body'] ?? '', 'cookie click → {lp_url} carries a signed token');
    assertContains("subid = '$clickId'", $resp['body'] ?? '', 'cookie click → {subid} injected for beacons');
    assertNotContains($resp['body'] ?? '', '{lp_url}', 'placeholder fully replaced in serve');

    // Without the cookie: silent beacons, plain transition link.
    $resp = $harness->getWithHeaders("/lander/$slug/", ['User-Agent: ' . $mobileUa]);
    if (($resp['code'] ?? 0) !== 200) {
        fwrite(STDERR, "DEBUG lander-no-cookie code=" . ($resp['code'] ?? -1)
            . " body=" . substr((string) ($resp['body'] ?? ''), 0, 400) . "\n");
    }
    assertContains("subid = ''", $resp['body'] ?? '', 'no cookie → empty subid (beacons stay silent)');
    assertContains("'/?_lp=1'", $resp['body'] ?? '', 'no cookie → plain /?_lp=1 transition');

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
