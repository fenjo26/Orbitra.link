<?php
// tests/domain_pwa_test.php
//
// Direct "domain root = PWA store" binding (domains.pwa_landing_id, migration
// 47). A bound domain serves its PWA landing straight from "/" — no campaign,
// no stream, no 302 into /lander/<slug>/ — while still logging a real organic
// click (campaign_id 0, the landing as its landing), so the funnel beacons,
// the {lp_url} signature and the push subscription attribute exactly like the
// campaign-served flow.
//
// Covered here, end to end over HTTP (index.php as the router, the way
// production nginx plays it):
//   - migration 47 adds domains.pwa_landing_id
//   - the root serves the store page with <base href="/">, a rewritten
//     service-worker registration (/lander/<slug>/sw.js with { scope: '/' })
//     and fresh-click macros, plus the funnel cookies
//   - the organic click row lands with campaign_id 0 and the landing id
//   - /?_lp=1 is never swallowed by the store page (it reaches the landing
//     transition handler and honestly reports the missing offer)
//   - explicit ?campaign_id= and live aliases keep winning
//   - the lander-path sw.js answers with Service-Worker-Allowed: /
//   - /manifest.webmanifest resolves through the orbitra_lp passthrough
//   - a stale binding (archived landing) falls through like an unbound domain
//   - the binding outranks index_campaign_id on the root path
//
// Run: php tests/domain_pwa_test.php

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/../core/PwaLanding.php';
require_once __DIR__ . '/../core/landing_path.php';

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

// serveLandingAsset hands file bodies to nginx via X-Accel-Redirect, which the
// php -S harness answers with an empty 200. Force the direct-streaming
// fallback so the suite can assert on actual asset bodies; must be set before
// start() so the server child inherits it.
putenv('ORBITRA_ASSET_FALLBACK=1');

try {
    $harness->start();
    // One throwaway request boots the server and runs the migrations on the
    // sandboxed DB copy; without it the columns below are checked too early.
    $harness->get('/nonexistent-boot-probe');
    $pdo = $harness->getPdo();

    // ------------------------------------------------------------------
    // Migration 47.
    // ------------------------------------------------------------------
    $dcols = $pdo->query("PRAGMA table_info(domains)")->fetchAll(PDO::FETCH_COLUMN, 1);
    assertTrue(in_array('pwa_landing_id', $dcols, true), 'migration 47 added domains.pwa_landing_id');

    // ------------------------------------------------------------------
    // Seed: a PWA landing whose install button routes to a campaign, the
    // campaigns the precedence checks need, and the bound domain (the lookup
    // key is the request host, 127.0.0.1).
    // ------------------------------------------------------------------
    $slug = 'pwa-root-' . bin2hex(random_bytes(4));
    $config = PwaLanding::normalizeConfig([
        'pwa' => true,
        'app_name' => 'Root Store App',
        'developer' => 'Direct Domains Ltd',
        // to_offer (the default): the store button renders {lp_url}, which is
        // the link the pwa_offer_id augmentation below appends offer_id to.
        'action_target' => 'to_offer',
    ]);
    $pdo->prepare("INSERT INTO landings (name, url, type, state, slug, config_json) VALUES (?, '', 'local', 'active', ?, ?)")
        ->execute(['Root Store PWA', $slug, json_encode($config, JSON_UNESCAPED_UNICODE)]);
    $landingId = (int) $pdo->lastInsertId();
    $written = PwaLanding::generate($pdo, $landingId);
    $dir = orbitraLandingDir($pdo, $landingId);
    $madeDirs[] = $dir;

    // The server runs from the sandboxed working dir: mirror the generated
    // files there or its lander/asset routes cannot find them.
    $sandboxDir = $harness->getWorkingDir() . '/landings/' . $slug;
    @mkdir($sandboxDir, 0775, true);
    $madeDirs[] = $sandboxDir;
    foreach ($written as $fname) {
        copy($dir . '/' . $fname, $sandboxDir . '/' . $fname);
    }

    $seedCampaign = static function (int $cid, string $alias, int $oid, string $offerUrl, int $sid) use ($pdo): void {
        $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived) VALUES (?, ?, ?, ?, 'active', 0)")
            ->execute([$cid, "Camp $cid", $alias, "tok$cid"]);
        $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, ?, ?, 0, 'active', 0)")
            ->execute([$oid, "Offer $oid", $offerUrl]);
        $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks) VALUES (?, ?, ?, ?, 'regular', 1, 'redirect', ?, 1, 1)")
            ->execute([$sid, $cid, $oid, "Stream $sid", json_encode(['offers' => [['id' => $oid, 'weight' => 100]]])]);
    };
    $seedCampaign(42, 'rootcamp', 4200, 'https://offers.example.com/root-landing', 420);
    $seedCampaign(9, 'othercamp', 900, 'https://offers.example.com/other-landing', 90);
    foreach ([['postback_key', 'testkey42'], ['ignore_prefetch', '0'], ['stats_enabled', '1']] as [$k, $v]) {
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$k, $v]);
    }
    $pdo->prepare("INSERT INTO domains (name, pwa_landing_id, status) VALUES ('127.0.0.1', ?, 'OK')")
        ->execute([$landingId]);

    $headerLines = static function (array $resp): array {
        // getWithHeaders' map keeps only the LAST of duplicated names; the
        // numeric entries are the raw lines, so every Set-Cookie survives.
        return array_values(array_filter($resp['headers'], 'is_int', ARRAY_FILTER_USE_KEY));
    };

    /**
     * GET without following redirects: the offers here point at unreachable
     * example hosts on purpose, and several checks are about the FIRST
     * response — its status line and Location.
     */
    $getNoFollow = static function (string $path, array $headers = []) use ($harness): array {
        $ctx = stream_context_create(['http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'header' => implode("\r\n", $headers),
        ]]);
        $body = @file_get_contents($harness->getBaseUrl() . $path, false, $ctx);
        $raw = $http_response_header ?? [];
        $code = 0;
        $flat = [];
        foreach ($raw as $h) {
            if (preg_match('#^HTTP/\d\.\d (\d+)#', $h, $m)) {
                $code = (int) $m[1];
            }
            if (strpos($h, ':') !== false) {
                [$k, $v] = explode(':', $h, 2);
                $flat[trim($k)] = trim($v);
            }
        }
        return ['code' => $code, 'body' => (string) $body, 'headers' => $flat];
    };

    // ------------------------------------------------------------------
    // THE feature: the root of the bound domain is the store.
    // ------------------------------------------------------------------
    $resp = $harness->getWithHeaders('/');
    assertTrue($resp['code'] === 200, 'bound domain root answers 200, not a redirect', );
    assertContains('Root Store App', $resp['body'], 'root serves the PWA store page');
    assertContains('<base href="/">', $resp['body'], 'root page carries the root <base>');
    assertContains(
        "register('/lander/" . $slug . "/sw.js', { scope: '/' })",
        $resp['body'],
        'service worker registered from the lander path with an explicit root scope'
    );
    assertNotContains("register('sw.js')", $resp['body'], 'the relative sw.js registration is gone (no /sw.js panel collision)');
    assertNotContains('{subid}', $resp['body'], 'macros resolved from the fresh click (no raw {subid})');
    assertNotContains('{lp_url}', $resp['body'], 'macros resolved from the fresh click (no raw {lp_url})');
    assertNotContains('{vapid_public}', $resp['body'], 'vapid macro consumed even without keys configured');

    $cookieLines = implode("\n", $headerLines($resp));
    assertContains('orbitra_click=', $cookieLines, 'fresh attribution cookie set on the root response');
    assertContains('orbitra_lp=', $cookieLines, 'orbitra_lp set so root-relative assets resolve');
    assertContains('orbitra_pwa_click=', $cookieLines, 'long-lived PWA attribution cookie set');

    // ------------------------------------------------------------------
    // The organic click: attributed to the archived system campaign, bound
    // to the landing, one per visit.
    // ------------------------------------------------------------------
    $organicId = (int) $pdo->query("SELECT id FROM campaigns WHERE alias = 'orbitra-pwa-organic' LIMIT 1")->fetchColumn();
    assertTrue($organicId > 0, 'migration 47 seeded the archived system campaign for organic PWA clicks');
    $archived = (int) $pdo->query("SELECT is_archived FROM campaigns WHERE id = $organicId")->fetchColumn();
    assertTrue($archived === 1, 'the system campaign is archived (hidden from the campaigns table)');

    $click = $pdo->query("SELECT id, campaign_id, landing_id, device_type, country_code FROM clicks WHERE landing_id = $landingId ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertTrue(is_array($click) && (int) $click['campaign_id'] === $organicId, 'organic click attributed to the system campaign');
    assertTrue(is_array($click) && (int) $click['landing_id'] === $landingId, 'organic click carries the PWA landing id');
    $clickCount = (int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE landing_id = $landingId")->fetchColumn();
    $resp2 = $harness->get('/');
    $clickCount2 = (int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE landing_id = $landingId")->fetchColumn();
    assertTrue($clickCount2 === $clickCount + 1, 'a second root visit logs its own click');

    // ------------------------------------------------------------------
    // Funnel wiring the binding must not break.
    // ------------------------------------------------------------------
    $resp = $harness->getWithHeaders('/?_lp=1');
    assertTrue($resp['code'] === 400 && strpos($resp['body'], 'Landing transition failed') !== false,
        '/?_lp=1 without cookies reaches the transition handler (not swallowed by the store)');

    $lpResp = $harness->getWithHeaders('/?_lp=1', ['Cookie: orbitra_click=' . $click['id']]);
    assertTrue($lpResp['code'] === 404 && strpos($lpResp['body'], 'no offer attached') !== false,
        'to_offer on an organic click resolves the click, then honestly reports the missing offer');

    // With domains.pwa_offer_id the root server appends offer_id= to every lp
    // link it renders, and the transition redirects to that offer — this is
    // what makes the "to_offer" button work on a direct domain.
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (4242, 'Offer 4242', 'https://offers.example.com/direct-offer', 0, 'active', 0)")->execute();
    $pdo->prepare("UPDATE domains SET pwa_offer_id = 4242 WHERE name = '127.0.0.1'")->execute();
    $resp = $harness->get('/');
    assertContains('/index.php?_lp=1&offer_id=4242', $resp['body'], 'root page lp links carry the binding offer_id');
    $freshClick = $pdo->query("SELECT id FROM clicks WHERE landing_id = $landingId ORDER BY id DESC LIMIT 1")->fetchColumn();
    $offerResp = $getNoFollow('/?_lp=1&offer_id=4242', ['Cookie: orbitra_click=' . $freshClick]);
    assertTrue($offerResp['code'] === 302 && strpos($offerResp['headers']['Location'] ?? '', 'offers.example.com/direct-offer') !== false,
        '/?_lp=1&offer_id= redirects the organic click to the binding offer');
    $credited = (int) $pdo->query("SELECT offer_id FROM clicks WHERE id = " . $pdo->quote((string) $freshClick))->fetchColumn();
    assertTrue($credited === 4242, 'the transition attributed the offer to the organic click');
    $pdo->prepare("UPDATE domains SET pwa_offer_id = NULL WHERE name = '127.0.0.1'")->execute();
    $pdo->prepare("DELETE FROM offers WHERE id = 4242")->execute();

    $resp = $getNoFollow('/?campaign_id=9');
    assertTrue($resp['code'] === 302 && strpos($resp['headers']['Location'] ?? '', 'offers.example.com/other-landing') !== false,
        'explicit ?campaign_id= keeps winning over the PWA binding');

    $resp = $getNoFollow('/othercamp');
    assertTrue($resp['code'] === 302 && strpos($resp['headers']['Location'] ?? '', 'offers.example.com/other-landing') !== false,
        'a live alias on a subpath keeps winning (the binding claims only the root)');

    $resp = $harness->getWithHeaders("/lander/$slug/sw.js");
    assertTrue($resp['code'] === 200, 'the lander-path service worker answers');
    assertTrue(isset($resp['headers']['Service-Worker-Allowed']) && trim($resp['headers']['Service-Worker-Allowed']) === '/',
        'sw.js response allows the wide scope (Service-Worker-Allowed: /)');
    if (strpos($resp['body'], 'orbitra-pwa-' . $landingId) === false) {
        fwrite(STDERR, "DEBUG sw.js body head: " . substr($resp['body'], 0, 300) . "\n");
    }
    assertContains('orbitra-pwa-' . $landingId, $resp['body'], 'the served worker is this landing\'s script');

    $resp = $harness->getWithHeaders('/manifest.webmanifest', ['Cookie: orbitra_lp=' . $landingId]);
    assertTrue($resp['code'] === 200 && strpos($resp['body'], 'Root Store App') !== false,
        '/manifest.webmanifest resolves through the orbitra_lp passthrough');

    // ------------------------------------------------------------------
    // Precedence and staleness.
    // ------------------------------------------------------------------
    $pdo->prepare("UPDATE domains SET index_campaign_id = 42 WHERE name = '127.0.0.1'")->execute();
    $resp = $harness->getWithHeaders('/');
    assertTrue($resp['code'] === 200 && strpos($resp['body'], 'Root Store App') !== false,
        'the PWA binding outranks index_campaign_id on the root path');
    $resp = $getNoFollow('/?campaign_id=9');
    assertTrue($resp['code'] === 302, 'explicit campaign still wins with both bindings present');
    $pdo->prepare("UPDATE domains SET index_campaign_id = NULL WHERE name = '127.0.0.1'")->execute();

    $pdo->prepare("UPDATE landings SET is_archived = 1 WHERE id = ?")->execute([$landingId]);
    $resp = $harness->getWithHeaders('/');
    assertTrue($resp['code'] === 200 && strpos($resp['body'], 'Root Store App') === false,
        'an archived landing falls through like an unbound domain');
    $pdo->prepare("UPDATE landings SET is_archived = 0 WHERE id = ?")->execute([$landingId]);
    $resp = $harness->getWithHeaders('/');
    assertContains('Root Store App', $resp['body'], 're-activating the landing restores the store');

    $pdo->prepare("UPDATE domains SET pwa_landing_id = NULL WHERE name = '127.0.0.1'")->execute();
    $resp = $harness->getWithHeaders('/');
    assertTrue($resp['code'] === 200 && strpos($resp['body'], 'Root Store App') === false,
        'an unbound domain root behaves exactly as before');
} catch (Throwable $e) {
    $testPassed = false;
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
} finally {
    foreach ($madeDirs as $d) {
        if (strpos((string) $d, $repoRoot . '/landings/') === 0 || strpos((string) $d, '/tmp/') === 0) {
            cleanupDir($d);
        }
    }
    $harness->stop();
}

echo $testPassed ? "\nALL TESTS PASSED\n" : "\nSOME TESTS FAILED\n";
exit($testPassed ? 0 : 1);
