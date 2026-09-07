<?php
// tests/pwa_funnel_http_test.php
//
// The per-screen PWA funnel (renderer v16, migration 51): the flow-step
// configuration model (normalizeFunnel), the funnel markup the renderer
// ships (data-flow-screen sections, prefixed ids, lazy per-screen scripts),
// the /pixel.gif?action=pwa&kind=screen beacon path (entry/last columns plus
// the capped pwa_screen_views log) and the pwa_funnel_stats endpoint the
// Landings funnel card reads.
//
// Run: php tests/pwa_funnel_http_test.php

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/tests/lib/http.php';
require_once $repoRoot . '/core/PwaLanding.php';

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

// ---------------------------------------------------------------------------
// Pure renderer checks — no HTTP.
// ---------------------------------------------------------------------------

// Legacy configs (no funnel key) synthesize the default [store] flow.
$legacy = PwaLanding::normalizeConfig(['pwa' => true]);
assertTrue($legacy['funnel'] === [['id' => 'store', 'type' => 'store', 'enabled' => true]],
    'legacy config synthesizes the [store] funnel');

// Junk steps: duplicate builtins collapse, bad ids regenerate, screen cap holds.
$junkFunnel = PwaLanding::normalizeConfig(['pwa' => true, 'funnel' => [
    ['id' => 'store', 'type' => 'store', 'enabled' => true],
    ['id' => 'store', 'type' => 'store', 'enabled' => true],        // dup builtin
    ['id' => 'push', 'type' => 'push', 'enabled' => true],
    ['id' => 'push', 'type' => 'push', 'enabled' => true],          // dup builtin
    ['id' => 'BAD ID!', 'type' => 'screen', 'enabled' => true, 'template' => 'wheels'],
    ['id' => 'scr_a', 'type' => 'screen', 'enabled' => true, 'template' => 'slot', 'button' => 'Spin'],
    ['id' => 'scr_b', 'type' => 'screen', 'enabled' => true, 'template' => 'lobby'],
    ['id' => 'scr_c', 'type' => 'screen', 'enabled' => true, 'template' => 'custom', 'custom_js' => 'a()'],
    ['id' => 'scr_d', 'type' => 'screen', 'enabled' => true, 'template' => 'lobby'],
    ['id' => 'scr_e', 'type' => 'screen', 'enabled' => true, 'template' => 'lobby'],
    ['id' => 'scr_f', 'type' => 'screen', 'enabled' => true, 'template' => 'lobby'],  // over cap
    ['type' => 'screen'],                                           // no id at all
]]);
$jf = $junkFunnel['funnel'];
assertTrue(count($jf) === 7, 'funnel keeps one builtin each + screens up to the cap');
assertTrue($jf[0]['type'] === 'store' && $jf[1]['type'] === 'push', 'duplicate builtin steps collapse to the first');
assertTrue(preg_match('/^[a-z0-9_]{1,32}$/', $jf[2]['id']) === 1 && $jf[2]['id'] !== 'BAD ID!',
    'invalid screen id regenerated into the safe namespace');
assertTrue($jf[2]['template'] === 'lobby', 'unknown screen template falls back to lobby');
assertTrue($jf[3]['id'] === 'scr_a' && $jf[3]['button'] === 'Spin', 'valid screen step kept with its fields');
$screensInFunnel = array_values(array_filter($jf, fn($s) => $s['type'] === 'screen'));
assertTrue(count($screensInFunnel) === 5, 'screen step cap of 5 enforced');
$screenIds = array_column($screensInFunnel, 'id');
assertTrue(count($screenIds) === count(array_unique($screenIds)), 'screen step ids unique');
assertTrue(!in_array('scr_e', $screenIds, true) && !in_array('scr_f', $screenIds, true), 'steps over the cap dropped');

// Rendering: a funnel with a custom first screen.
$flowConfig = PwaLanding::normalizeConfig(['pwa' => true, 'app_name' => 'Flow App', 'push_enabled' => true, 'funnel' => [
    ['id' => 'scr_a', 'type' => 'screen', 'enabled' => true, 'template' => 'slot', 'button' => 'Spin & Win', 'custom_js' => 'console.log("scr-a-pixel")'],
    ['id' => 'store', 'type' => 'store', 'enabled' => true],
    ['id' => 'instructions', 'type' => 'instructions', 'enabled' => true],
    ['id' => 'push', 'type' => 'push', 'enabled' => true],
]]);
$flowHtml = PwaLanding::renderPreview($flowConfig, 'auto', 'auto');
assertContains('content="' . PwaLanding::RENDERER_VERSION . '"', $flowHtml, 'flow page carries the current renderer version');
assertContains('data-flow-screen="scr_a"', $flowHtml, 'custom screen step rendered as a flow section');
assertContains('fsscr_a_pwa-slot-spin-btn', $flowHtml, 'screen step element ids prefixed with the step id');
assertContains('data-flow-screen="instructions"', $flowHtml, 'instructions step rendered as a flow section');
assertContains('flow-instr-android', $flowHtml, 'instructions step carries the Android list');
assertContains('data-flow-screen="push"', $flowHtml, 'push step rendered as a flow section');
assertContains('pwa-flow-push-allow', $flowHtml, 'push flow card wired with its own allow button');
assertContains('"flow"', $flowHtml, 'page config carries the flow list');
assertContains('data-flow-js="scr_a"', $flowHtml, 'per-screen script embedded for lazy activation');
assertContains('html[data-flow-first]:not([data-flow-first="store"]) .store-layout', $flowHtml, 'first-paint CSS hides the store when a non-store step opens');
assertContains('wireSlotEngine', $flowHtml, 'slot engine factory shipped');
assertNotContains('window.__PWA_FORCE_SCREEN = ', $flowHtml, 'organic page carries no forced-screen flag');

// A push step without push tech never joins the flow (dead-end guard).
$noPush = PwaLanding::normalizeConfig(['pwa' => true, 'funnel' => [
    ['id' => 'store', 'type' => 'store', 'enabled' => true],
    ['id' => 'push', 'type' => 'push', 'enabled' => true],
]]);
$noPushHtml = PwaLanding::renderPreview($noPush, 'auto', 'auto');
assertNotContains('data-flow-screen="push"', $noPushHtml, 'push step without push_enabled renders no flow card');

// Legacy default: no flow sections, store stays the first paint.
$legacyHtml = PwaLanding::renderPreview(PwaLanding::normalizeConfig(['pwa' => true, 'app_name' => 'Old']), 'auto', 'auto');
assertNotContains('data-flow-screen=', $legacyHtml, 'legacy config renders no flow sections');
assertContains('var flowFirst = "store"', $legacyHtml, 'legacy config keeps the store as the first flow step');

// Preview can open the funnel at a chosen step.
$forcedHtml = PwaLanding::renderPreview($flowConfig, 'auto', 'scr_a');
assertContains('window.__PWA_FORCE_SCREEN = ', $forcedHtml, 'preview forces a flow step via the render flag');

// ---------------------------------------------------------------------------
// HTTP: the screen beacon path and the stats endpoint.
// ---------------------------------------------------------------------------
$harness = new OrbitraTestHarness($repoRoot);
$harness->useProductionRouter();
$harness->start();

try {
    $harness->get('/nonexistent-boot-probe');
    $pdo = $harness->getPdo();
    // The harness PDO is a bare connection: no busy_timeout, so our reads
    // would collide with the server's writes (the pixel swallows "locked"
    // silently) and an in-flight server write would fail US instantly. A
    // reader must also close its cursors — an open SQLite statement keeps a
    // SHARED lock that blocks the single-worker server from writing at all.
    $pdo->exec('PRAGMA busy_timeout = 30000;');
    $close = static function ($stmt): void {
        if ($stmt) {
            $stmt->closeCursor();
        }
    };

    $cols = $pdo->query('PRAGMA table_info(clicks)')->fetchAll(PDO::FETCH_COLUMN, 1);
    assertTrue(in_array('pwa_entry_screen', $cols, true) && in_array('pwa_last_screen', $cols, true),
        'migration 51 added clicks.pwa_entry_screen / pwa_last_screen');
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    assertTrue(in_array('pwa_screen_views', $tables, true), 'migration 51 created pwa_screen_views');

    $campaignId = (int) $pdo->query("SELECT id FROM campaigns WHERE alias = 'flow-test' LIMIT 1")->fetchColumn();
    if (!$campaignId) {
        $pdo->prepare("INSERT INTO campaigns (name, alias, token, state) VALUES ('Flow Test', 'flow-test', 'flowtok', 'active')")
            ->execute();
        $campaignId = (int) $pdo->lastInsertId();
    }
    $funnelJson = json_encode(['pwa' => true, 'funnel' => [
        ['id' => 'store', 'type' => 'store', 'enabled' => true],
        ['id' => 'scr_a', 'type' => 'screen', 'enabled' => true, 'template' => 'lobby'],
        ['id' => 'instructions', 'type' => 'instructions', 'enabled' => true],
    ]], JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO landings (id, name, type, url, slug, config_json) VALUES (60, 'Flow PWA', 'local', '', 'flow-pwa', ?)")
        ->execute([$funnelJson]);

    $insertClick = static function (string $id) use ($pdo, $campaignId): void {
        $pdo->prepare("INSERT INTO clicks (id, campaign_id, landing_id, ip, user_agent, country_code) VALUES (?, ?, 60, '127.0.0.1', 'Flow-Agent/1.0', 'US')")
            ->execute([$id, $campaignId]);
    };
    $c1 = 'flowclick-' . bin2hex(random_bytes(6));
    $c2 = 'flowclick-' . bin2hex(random_bytes(6));
    $insertClick($c1);
    $insertClick($c2);

    $get = fn(string $path) => $harness->get($path);
    $clickRow = static function (string $id) use ($pdo, $close): array {
        $stmt = $pdo->prepare('SELECT pwa_entry_screen, pwa_last_screen FROM clicks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $close($stmt);
        return $row;
    };
    $viewCount = static function (string $id) use ($pdo, $close): int {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pwa_screen_views WHERE click_id = ?');
        $stmt->execute([$id]);
        $n = (int) $stmt->fetchColumn();
        $close($stmt);
        return $n;
    };

    // 1. First screen: entry + last + one event row, attributed to the landing.
    $r = $get("/pixel.gif?action=pwa&kind=screen&screen=store&subid=$c1");
    assertTrue(($r['code'] ?? 0) === 200, 'screen beacon answers with the pixel');
    $row = $clickRow($c1);
    assertTrue(($row['pwa_entry_screen'] ?? null) === 'store', 'first screen stored as pwa_entry_screen');
    assertTrue(($row['pwa_last_screen'] ?? null) === 'store', 'first screen stored as pwa_last_screen');
    assertTrue($viewCount($c1) === 1, 'screen view row inserted');
    $lv = $pdo->prepare('SELECT landing_id FROM pwa_screen_views WHERE click_id = ?');
    $lv->execute([$c1]);
    $landingOfView = (int) $lv->fetchColumn();
    $close($lv);
    assertTrue($landingOfView === 60, 'screen view carries the landing id');

    // 2. Second screen: entry frozen, last advances, second row.
    $get("/pixel.gif?action=pwa&kind=screen&screen=scr_a&subid=$c1");
    $row = $clickRow($c1);
    assertTrue(($row['pwa_entry_screen'] ?? null) === 'store', 'replayed screen cannot overwrite the entry');
    assertTrue(($row['pwa_last_screen'] ?? null) === 'scr_a', 'pwa_last_screen follows the current step');
    assertTrue($viewCount($c1) === 2, 'second view row inserted');

    // 3. Back to the store — a revisit is a view too (and still no entry overwrite).
    $get("/pixel.gif?action=pwa&kind=screen&screen=store&subid=$c1");
    assertTrue($viewCount($c1) === 3, 'revisiting a screen adds another view row');
    $row = $clickRow($c1);
    assertTrue(($row['pwa_entry_screen'] ?? null) === 'store', 'entry still frozen after a revisit');

    // 4. Junk and hostile beacons write nothing.
    $get('/pixel.gif?action=pwa&kind=screen&screen=' . urlencode('Bad Screen!') . "&subid=$c2");
    $get("/pixel.gif?action=pwa&kind=screen&screen=" . urlencode(str_repeat('x', 40)) . "&subid=$c2");
    $get("/pixel.gif?action=pwa&kind=screen&subid=$c2");
    assertTrue($viewCount($c2) === 0, 'invalid screen ids are rejected without writes');
    $r = $get("/pixel.gif?action=pwa&kind=screen&screen=store&subid=flowclick-unknown");
    assertTrue(($r['code'] ?? 0) === 200, 'beacon for an unknown click still answers 200');

    // 5. The per-click cap: a cycling tab cannot pump the table.
    for ($i = 0; $i < 105; $i++) {
        $get("/pixel.gif?action=pwa&kind=screen&screen=scr_loop&subid=$c2");
    }
    assertTrue($viewCount($c2) === 100, 'per-click view cap of 100 enforced');

    // 6. The funnel card endpoint (admin session).
    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, 'admin', 1, '{}')")
        ->execute(['flow_admin', password_hash('pass123', PASSWORD_DEFAULT)]);
    try { $pdo->exec('DELETE FROM rate_limits'); } catch (\Throwable $e) {}
    $login = $harness->postWithHeaders('/api.php?action=login', json_encode(['username' => 'flow_admin', 'password' => 'pass123']), ['Content-Type: application/json']);
    if ((json_decode($login['body'], true)['status'] ?? '') !== 'success') {
        fwrite(STDERR, "login failed: " . $login['body'] . "\n");
        exit(1);
    }
    preg_match('/ORBITRASESSID=([^;]+)/', $login['headers']['Set-Cookie'] ?? '', $sess);
    $cookie = 'ORBITRASESSID=' . ($sess[1] ?? '');

    $api = static function (string $qs) use ($harness, $cookie) {
        return $harness->getWithHeaders("/api.php?$qs", ["Cookie: $cookie"]);
    };

    $resp = $api('action=pwa_funnel_stats&id=60');
    $data = json_decode($resp['body'] ?? '', true);
    assertTrue(($data['status'] ?? '') === 'success', 'pwa_funnel_stats answers success');
    $screens = array_column($data['data']['screens'] ?? [], null, 'screen');
    assertTrue((($data['data']['totals']['clicks'] ?? 0)) === 2, 'totals report the landing click count');
    assertTrue((($data['data']['totals']['with_screens'] ?? 0)) === 2, 'totals count clicks that saw a screen');
    assertTrue(($screens['store']['views'] ?? 0) === 2, 'store views aggregated (2 shows)');
    assertTrue(($screens['scr_a']['views'] ?? 0) === 1, 'scr_a views aggregated');
    assertTrue(($screens['store']['entry'] ?? 0) === 1, 'store counted as the entry screen');
    assertTrue(($screens['scr_loop']['views'] ?? 0) === 100, 'capped views still counted up to the cap');
    $transitions = $data['data']['transitions'] ?? [];
    $hasStoreToScrA = false;
    $hasScrAToStore = false;
    foreach ($transitions as $tr) {
        if (($tr['from'] ?? '') === 'store' && ($tr['to'] ?? '') === 'scr_a' && ($tr['n'] ?? 0) === 1) $hasStoreToScrA = true;
        if (($tr['from'] ?? '') === 'scr_a' && ($tr['to'] ?? '') === 'store' && ($tr['n'] ?? 0) === 1) $hasScrAToStore = true;
    }
    assertTrue($hasStoreToScrA && $hasScrAToStore, 'transition matrix reflects the click walk store→scr_a→store');

    // 7. Screen order mirrors the funnel config (store first, then scr_a).
    $order = array_column($data['data']['screens'] ?? [], 'screen');
    assertTrue(($order[0] ?? '') === 'store' && ($order[1] ?? '') === 'scr_a', 'screens ordered by funnel config first');

    // 8. Guards.
    $resp = $api('action=pwa_funnel_stats&id=999999');
    assertTrue((json_decode($resp['body'] ?? '', true)['message'] ?? '') === 'Landing not found', 'unknown landing id rejected');
    $resp = $api('action=pwa_funnel_stats&id=0');
    assertTrue((json_decode($resp['body'] ?? '', true)['message'] ?? '') === 'Missing ID', 'missing id rejected');
} catch (Throwable $e) {
    fwrite(STDERR, 'EXCEPTION: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $testPassed = false;
} finally {
    if (isset($harness)) {
        $harness->stop();
    }
}

echo $testPassed ? "\nALL CHECKS PASSED\n" : "\nSOME CHECKS FAILED\n";
exit($testPassed ? 0 : 1);
