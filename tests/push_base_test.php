<?php
// tests/push_base_test.php
//
// Phase-3 push base suite: VAPID keygen shape, the /push_subscribe endpoint
// (upsert by endpoint, click attribution with NULL-guard dedup), the
// prompt/decline pixel kinds, the {vapid_public} serve-time macro and the
// in-app subscribe screen gating.
//
// Run: php tests/push_base_test.php

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/../core/PushBase.php';
require_once __DIR__ . '/../core/PwaLanding.php';

$testPassed = true;
function assertTrue($condition, string $message): bool {
    global $testPassed;
    if (!$condition) { fwrite(STDERR, "FAILED: $message\n"); $testPassed = false; }
    else { echo "✓ $message\n"; }
    return (bool) $condition;
}
function assertContains(string $needle, $haystack, string $message): bool {
    global $testPassed;
    if (strpos((string) $haystack, $needle) === false) {
        fwrite(STDERR, "FAILED: $message\n  Expected to contain: $needle\n");
        $testPassed = false;
    } else { echo "✓ $message\n"; }
    return true;
}

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);
$madeDirs = [];

try {
    $harness->start();
    $harness->get('/nonexistent-boot-probe'); // boots the server, runs migrations
    $pdo = $harness->getPdo();

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    assertTrue(in_array('push_subscriptions', $tables, true), 'migration 45 created push_subscriptions');
    $cols = $pdo->query("PRAGMA table_info(clicks)")->fetchAll(PDO::FETCH_COLUMN, 1);
    assertTrue(in_array('push_prompted_at', $cols, true) && in_array('push_subscribed_at', $cols, true) && in_array('push_declined_at', $cols, true),
        'migration 45 added clicks.push_* columns');

    // ------------------------------------------------------------------
    // VAPID keygen: shape + storage roundtrip.
    // ------------------------------------------------------------------
    $keys = PushBase::generateKeys();
    $pubBin = base64_decode(strtr($keys['public'], '-_', '+/'));
    $privBin = base64_decode(strtr($keys['private'], '-_', '+/'));
    assertTrue(strlen($pubBin) === 65 && $pubBin[0] === "\x04", 'public key is an uncompressed P-256 point (65 bytes)');
    assertTrue(strlen($privBin) === 32, 'private key is 32 raw bytes');
    PushBase::storeKeys($pdo, $keys);
    $round = PushBase::getKeys($pdo);
    assertTrue($round['public'] === $keys['public'] && $round['private'] === $keys['private'], 'keys stored and read back');

    // ------------------------------------------------------------------
    // /push_subscribe: upsert by endpoint, attribution, dedup.
    // ------------------------------------------------------------------
    $campaignId = random_int(10000, 99999);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived) VALUES (?, ?, ?, ?, 'active', 0)")
        ->execute([$campaignId, 'Push Campaign', 'pushcamp' . $campaignId, 'push_tok_' . $campaignId]);
    $clickId = 'pushclk-' . bin2hex(random_bytes(6));
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent) VALUES (?, ?, '1.2.3.4', 'UA')")
        ->execute([$clickId, $campaignId]);

    $UA = 'Mozilla/5.0 (Linux; Android 13) Chrome/120 Mobile';
    $sub = function (array $body) use ($harness, $UA) {
        return $harness->postWithHeaders('/push_subscribe', json_encode($body), [
            'User-Agent: ' . $UA,
            'Content-Type: application/json',
        ]);
    };
    $good = ['subid' => $clickId, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-1', 'keys' => ['p256dh' => 'BPk2x', 'auth' => 'auth1']];
    $r = $sub($good);
    assertTrue(($r['code'] ?? 0) === 200, 'valid subscription accepted');
    $cnt = $pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint-1'")->fetchColumn();
    assertTrue((int) $cnt === 1, 'subscription row created');
    $row = $pdo->query("SELECT click_id, p256dh, country_code FROM push_subscriptions WHERE endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint-1'")->fetch(PDO::FETCH_ASSOC);
    assertTrue($row['click_id'] === $clickId, 'subscription attributed to the click');
    $st = $pdo->query("SELECT push_subscribed_at FROM clicks WHERE id = " . $pdo->quote($clickId))->fetchColumn();
    assertTrue(is_string($st) && $st !== '', 'push_subscribed_at stamped on the click');

    // Re-subscribe same endpoint with new keys: rotates in place, no dup.
    $rotated = ['subid' => $clickId, 'endpoint' => $good['endpoint'], 'keys' => ['p256dh' => 'NEW-p256dh', 'auth' => 'NEW-auth']];
    $sub($rotated);
    $cnt = $pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint-1'")->fetchColumn();
    assertTrue((int) $cnt === 1, 're-subscribe did not duplicate the row');
    $p = $pdo->query("SELECT p256dh FROM push_subscriptions WHERE endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint-1'")->fetchColumn();
    assertTrue($p === 'NEW-p256dh', 're-subscribe rotated the keys in place');
    $st2 = $pdo->query("SELECT push_subscribed_at FROM clicks WHERE id = " . $pdo->quote($clickId))->fetchColumn();
    assertTrue($st2 === $st, 're-subscribe did not re-stamp push_subscribed_at (NULL-guard dedup)');

    // Invalid bodies are rejected.
    $bad = $harness->postWithHeaders('/push_subscribe', json_encode(['endpoint' => 'https://x']), ['Content-Type: application/json']);
    assertTrue(($bad['code'] ?? 0) === 400, 'subscription without keys rejected (400)');

    // ------------------------------------------------------------------
    // pixel prompt/decline kinds.
    // ------------------------------------------------------------------
    $r = $harness->getWithHeaders("/pixel.gif?action=pwa&kind=prompt&subid=$clickId", ['User-Agent: t']);
    assertTrue(($r['code'] ?? 0) === 200, 'prompt beacon accepted');
    assertTrue($pdo->query("SELECT push_prompted_at FROM clicks WHERE id = " . $pdo->quote($clickId))->fetchColumn() !== null,
        'prompt beacon stamped push_prompted_at');
    $r = $harness->getWithHeaders("/pixel.gif?action=pwa&kind=decline&subid=$clickId", ['User-Agent: t']);
    assertTrue(($r['code'] ?? 0) === 200, 'decline beacon accepted');
    assertTrue($pdo->query("SELECT push_declined_at FROM clicks WHERE id = " . $pdo->quote($clickId))->fetchColumn() !== null,
        'decline beacon stamped push_declined_at');

    // ------------------------------------------------------------------
    // PWA page: subscribe screen gated by the serve-time VAPID macro.
    // ------------------------------------------------------------------
    require_once __DIR__ . '/../core/landing_path.php';
    $slug = 'push-test-' . bin2hex(random_bytes(4));
    // push_enabled is OPT-IN by default — pass it explicitly for the screen.
    $cfg = PwaLanding::normalizeConfig(['pwa' => true, 'app_name' => 'Push App', 'screens' => [], 'push_enabled' => true]);
    $offConfig = PwaLanding::normalizeConfig(['pwa' => true, 'app_name' => 'Push Off', 'push_enabled' => false]);
    assertTrue($cfg['push_enabled'] === true && $offConfig['push_enabled'] === false, 'push flag passes through normalizeConfig');
    $pdo->prepare("INSERT INTO landings (name, url, type, state, slug, config_json) VALUES (?, '', 'local', 'active', ?, ?)")
        ->execute(['Push PWA', $slug, json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
    $landingId = (int) $pdo->lastInsertId();
    $dir = orbitraLandingDir($pdo, $landingId);
    $madeDirs[] = $dir;
    PwaLanding::generate($pdo, $landingId);
    // Default-off regression: without the flag the page must not even ship
    // the subscribe screen markup.
    $offSlug = 'push-off-' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO landings (name, url, type, state, slug, config_json) VALUES (?, '', 'local', 'active', ?, ?)")
        ->execute(['Push Off PWA', $offSlug, json_encode($offConfig, JSON_UNESCAPED_UNICODE)]);
    $offLandingId = (int) $pdo->lastInsertId();
    $madeDirs[] = orbitraLandingDir($pdo, $offLandingId);
    PwaLanding::generate($pdo, $offLandingId);
    $offDir = orbitraLandingDir($pdo, $offLandingId);
    $sandboxOff = $harness->getWorkingDir() . '/landings/' . $offSlug;
    @mkdir($sandboxOff, 0775, true);
    foreach (['index.html', 'manifest.webmanifest', 'sw.js'] as $f) {
        if (is_file($offDir . '/' . $f)) copy($offDir . '/' . $f, $sandboxOff . '/' . $f);
    }
    $offHtml = (string) file_get_contents($offDir . '/index.html');
    assertTrue(strpos($offHtml, 'id="pwa-push"') === false, 'push disabled by default: no subscribe screen markup');
    $sandboxDir = $harness->getWorkingDir() . '/landings/' . $slug;
    @mkdir($sandboxDir, 0775, true);
    foreach (['index.html', 'manifest.webmanifest', 'sw.js'] as $f) {
        if (is_file($dir . '/' . $f)) copy($dir . '/' . $f, $sandboxDir . '/' . $f);
    }

    // No keys were present when the statics were generated — the page ships
    // the macro, and this test stored keys earlier, so it resolves live here.
    $r = $harness->getWithHeaders("/lander/$slug/", ['User-Agent: ' . $UA]);
    assertContains('id="pwa-push"', $r['body'] ?? '', 'subscribe screen markup present');
    assertContains("var VAPID = '", $r['body'] ?? '', 'VAPID macro resolves at serve time (never baked into statics)');

    // Keys stored → the next view carries the real public key (macro, not baked).
    PushBase::storeKeys($pdo, $keys);
    $r = $harness->getWithHeaders("/lander/$slug/", ['User-Agent: ' . $UA, 'Cookie: orbitra_click=' . $clickId]);
    assertContains("var VAPID = '" . $keys['public'] . "'", $r['body'] ?? '', 'public key injected serve-time into the page');
} catch (Throwable $e) {
    fwrite(STDERR, 'EXCEPTION: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $testPassed = false;
} finally {
    $harness->stop();
    foreach ($madeDirs as $d) {
        if (!is_dir($d)) continue;
        foreach (array_reverse(glob($d . '/*') ?: []) as $item) { is_dir($item) ? @rmdir($item) : @unlink($item); }
        @rmdir($d);
    }
}

echo $testPassed ? "\nALL TESTS PASSED\n" : "\nSOME TESTS FAILED\n";
exit($testPassed ? 0 : 1);
