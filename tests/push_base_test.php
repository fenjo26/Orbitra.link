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
    assertTrue(in_array('push_fail_reason', $cols, true), 'migration 48 added clicks.push_fail_reason');

    // ------------------------------------------------------------------
    // Migration 46 (phase 4, sending): PRAGMA asserts. The expected stamp
    // is read from config.php — a hardcoded number would fail on every
    // future migration bump (media_http_test pattern).
    // ------------------------------------------------------------------
    $expectedSchema = '';
    if (preg_match('/\$LATEST_SCHEMA_VERSION\s*=\s*(\d+)/', file_get_contents(dirname(__DIR__) . '/config.php'), $mSchema)) {
        $expectedSchema = $mSchema[1];
    }
    assertTrue($expectedSchema !== '' && (int) $pdo->query('PRAGMA user_version')->fetchColumn() === (int) $expectedSchema,
        "migration stamps schema $expectedSchema (user_version matches config.php)");
    assertTrue(in_array('push_messages', $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN), true)
        && in_array('push_queue', $tables, true) && in_array('push_sends', $tables, true),
        'migration 46 created push_messages, push_queue, push_sends');
    $msgCols = $pdo->query("PRAGMA table_info(push_messages)")->fetchAll(PDO::FETCH_COLUMN, 1);
    assertTrue(count(array_diff(['title', 'text', 'icon_url', 'link_url', 'kind', 'event', 'delay_seconds', 'segment', 'active', 'created_at'], $msgCols)) === 0,
        'push_messages carries all message columns');
    $queueCols = $pdo->query("PRAGMA table_info(push_queue)")->fetchAll(PDO::FETCH_COLUMN, 1);
    assertTrue(count(array_diff(['message_id', 'subscription_id', 'run_at', 'status', 'attempts', 'last_code'], $queueCols)) === 0,
        'push_queue carries the delivery columns');
    $sendCols = $pdo->query("PRAGMA table_info(push_sends)")->fetchAll(PDO::FETCH_COLUMN, 1);
    assertTrue(count(array_diff(['message_id', 'subscription_id', 'ok', 'response_code', 'sent_at'], $sendCols)) === 0,
        'push_sends carries the delivery-log columns');
    $clickCols = $pdo->query("PRAGMA table_info(clicks)")->fetchAll(PDO::FETCH_COLUMN, 1);
    assertTrue(in_array('push_clicks', $clickCols, true), 'migration 46 added clicks.push_clicks');
    $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(PDO::FETCH_COLUMN);
    assertTrue(in_array('idx_push_queue_due', $indexes, true), 'push_queue has the (status, run_at) due index');
    $qDefault = $pdo->query("SELECT dflt_value FROM pragma_table_info('push_queue') WHERE name = 'status'")->fetchColumn();
    assertTrue($qDefault === "'pending'", 'push_queue.status defaults to pending');

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
    // Browser-shaped keys: p256dh = 65-byte uncompressed EC point, auth =
    // 16 bytes, both base64url — the ingest rejects any other shape now.
    $b64u = function (string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); };
    $P256DH = $b64u("\x04" . str_repeat('A', 64));
    $AUTH = $b64u(str_repeat('B', 16));
    $P256DH2 = $b64u("\x04" . str_repeat('C', 64));
    $AUTH2 = $b64u(str_repeat('D', 16));
    $sub = function (array $body) use ($harness, $UA) {
        return $harness->postWithHeaders('/push_subscribe', json_encode($body), [
            'User-Agent: ' . $UA,
            'Content-Type: application/json',
        ]);
    };
    $good = ['subid' => $clickId, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-1', 'keys' => ['p256dh' => $P256DH, 'auth' => $AUTH]];
    $r = $sub($good);
    assertTrue(($r['code'] ?? 0) === 200, 'valid subscription accepted');
    $cnt = $pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint-1'")->fetchColumn();
    assertTrue((int) $cnt === 1, 'subscription row created');
    $row = $pdo->query("SELECT click_id, p256dh, country_code FROM push_subscriptions WHERE endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint-1'")->fetch(PDO::FETCH_ASSOC);
    assertTrue($row['click_id'] === $clickId, 'subscription attributed to the click');
    $st = $pdo->query("SELECT push_subscribed_at FROM clicks WHERE id = " . $pdo->quote($clickId))->fetchColumn();
    assertTrue(is_string($st) && $st !== '', 'push_subscribed_at stamped on the click');

    // Re-subscribe same endpoint with new keys: rotates in place, no dup.
    $rotated = ['subid' => $clickId, 'endpoint' => $good['endpoint'], 'keys' => ['p256dh' => $P256DH2, 'auth' => $AUTH2]];
    $sub($rotated);
    $cnt = $pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint-1'")->fetchColumn();
    assertTrue((int) $cnt === 1, 're-subscribe did not duplicate the row');
    $p = $pdo->query("SELECT p256dh FROM push_subscriptions WHERE endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint-1'")->fetchColumn();
    assertTrue($p === $P256DH2, 're-subscribe rotated the keys in place');
    $st2 = $pdo->query("SELECT push_subscribed_at FROM clicks WHERE id = " . $pdo->quote($clickId))->fetchColumn();
    assertTrue($st2 === $st, 're-subscribe did not re-stamp push_subscribed_at (NULL-guard dedup)');

    // The worker's own repair paths (activate self-heal, pushsubscriptionchange)
    // re-post the SAME endpoint with no subid — they have no click of their own.
    // A plain `click_id = excluded.click_id` would then WIPE the attribution the
    // page's handover just stored, which is how a filled push base can silently
    // lose its click linkage. Attribution is gained here, never lost.
    $sub(['endpoint' => $good['endpoint'], 'keys' => ['p256dh' => $P256DH2, 'auth' => $AUTH2]]);
    $keptClick = $pdo->query("SELECT click_id FROM push_subscriptions WHERE endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint-1'")->fetchColumn();
    assertTrue($keptClick === $clickId, 'a subid-less repost keeps the existing click attribution');

    // Invalid bodies are rejected.
    $bad = $harness->postWithHeaders('/push_subscribe', json_encode(['endpoint' => 'https://x']), ['Content-Type: application/json']);
    assertTrue(($bad['code'] ?? 0) === 400, 'subscription without keys rejected (400)');

    // Malformed key shapes never reach the base: they would hard-fail the
    // sender's AES128GCM layer on every send and burn queue retries forever.
    $badShapes = [
        'p256dh decodes to 64 bytes' => ['p256dh' => $b64u(str_repeat('A', 64)), 'auth' => $AUTH],
        'p256dh decodes to 66 bytes' => ['p256dh' => $b64u(str_repeat('A', 66)), 'auth' => $AUTH],
        'auth decodes to 15 bytes' => ['p256dh' => $P256DH, 'auth' => $b64u(str_repeat('B', 15))],
        'auth decodes to 17 bytes' => ['p256dh' => $P256DH, 'auth' => $b64u(str_repeat('B', 17))],
        'p256dh outside base64url' => ['p256dh' => $P256DH . '+', 'auth' => $AUTH],
        'endpoint with credentials' => ['endpoint' => 'https://user:pass@fcm.googleapis.com/fcm/send/x', 'keys' => ['p256dh' => $P256DH, 'auth' => $AUTH]],
        'endpoint with fragment' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x#frag', 'keys' => ['p256dh' => $P256DH, 'auth' => $AUTH]],
    ];
    foreach ($badShapes as $label => $shape) {
        $bad2 = $sub([
            'subid' => $clickId,
            'endpoint' => $shape['endpoint'] ?? 'https://fcm.googleapis.com/fcm/send/test-endpoint-bad',
            'keys' => $shape['keys'] ?? $shape,
        ]);
        assertTrue(($bad2['code'] ?? 0) === 400, "malformed subscription rejected (400): $label");
    }
    $cnt = $pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE endpoint LIKE '%test-endpoint-bad%' OR endpoint LIKE '%user:pass%' OR endpoint LIKE '%#frag%'")->fetchColumn();
    assertTrue((int) $cnt === 0, 'no malformed subscription row was written');

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

    // Decline carries its reason; the technical pushfail kind records a cause
    // WITHOUT a funnel timestamp, both NULL-guarded (first write wins).
    $reasonClickId = 'pushclk-' . bin2hex(random_bytes(6));
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent) VALUES (?, ?, '1.2.3.4', 'UA')")
        ->execute([$reasonClickId, $campaignId]);
    $r = $harness->getWithHeaders("/pixel.gif?action=pwa&kind=decline&reason=denied&subid=$reasonClickId", ['User-Agent: t']);
    assertTrue(($r['code'] ?? 0) === 200, 'decline beacon with reason accepted');
    $rowR = $pdo->query("SELECT push_declined_at, push_fail_reason FROM clicks WHERE id = " . $pdo->quote($reasonClickId))->fetch(PDO::FETCH_ASSOC);
    assertTrue($rowR['push_declined_at'] !== null && $rowR['push_fail_reason'] === 'denied', 'decline stamps the timestamp and the denied reason');
    $harness->getWithHeaders("/pixel.gif?action=pwa&kind=pushfail&reason=nokey&subid=$reasonClickId", ['User-Agent: t']);
    $rowR = $pdo->query("SELECT push_declined_at, push_fail_reason FROM clicks WHERE id = " . $pdo->quote($reasonClickId))->fetch(PDO::FETCH_ASSOC);
    assertTrue($rowR['push_fail_reason'] === 'denied', 'pushfail cannot overwrite an already stored reason (write-once)');
    $failClickId = 'pushclk-' . bin2hex(random_bytes(6));
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent) VALUES (?, ?, '1.2.3.4', 'UA')")
        ->execute([$failClickId, $campaignId]);
    $harness->getWithHeaders("/pixel.gif?action=pwa&kind=pushfail&reason=insecure&subid=$failClickId", ['User-Agent: t']);
    $rowF = $pdo->query("SELECT push_declined_at, push_prompted_at, push_fail_reason FROM clicks WHERE id = " . $pdo->quote($failClickId))->fetch(PDO::FETCH_ASSOC);
    assertTrue($rowF['push_fail_reason'] === 'insecure' && $rowF['push_declined_at'] === null && $rowF['push_prompted_at'] === null,
        'pushfail records the cause without touching funnel timestamps');

    // ------------------------------------------------------------------
    // PWA page: subscribe screen gated by the serve-time VAPID macro.
    // ------------------------------------------------------------------
    require_once __DIR__ . '/../core/landing_path.php';
    $slug = 'push-test-' . bin2hex(random_bytes(4));
    // push_enabled is OPT-IN by default — pass it explicitly for the screen.
    $cfg = PwaLanding::normalizeConfig(['pwa' => true, 'app_name' => 'Push App', 'screens' => [], 'push_enabled' => true]);
    $offConfig = PwaLanding::normalizeConfig(['pwa' => true, 'app_name' => 'Push Off', 'push_enabled' => false]);
    assertTrue($cfg['push_enabled'] === true && $offConfig['push_enabled'] === false, 'push flag passes through normalizeConfig');
    $campCfg = PwaLanding::normalizeConfig(['pwa' => true, 'app_name' => 'Camp App', 'screens' => [], 'app_action' => 'campaign', 'app_campaign_id' => 7]);
    assertTrue($campCfg['app_action'] === 'campaign' && $campCfg['app_campaign_id'] === 7, 'campaign app_action + app_campaign_id pass through normalizeConfig');
    assertTrue(PwaLanding::normalizeConfig(['pwa' => true, 'app_name' => 'Bad App', 'app_action' => 'nonsense'])['app_action'] === 'store', 'unknown app_action falls back to store');
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
    // The phase-4 message/queue API e2e lives in tests/push_api_test.php —
    // this file boots through index.php, which does not route /api.php.
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
