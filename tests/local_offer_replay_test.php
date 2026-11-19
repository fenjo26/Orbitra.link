<?php
// tests/local_offer_replay_test.php
//
// Session recordings on LOCAL offers: a click whose destination is an offer
// uploaded to the tracker (offers/<id>/, served inline) must load /replay.js
// when the campaign's recording switch is on, and must not when it is off.
// Before the fix the recorder was injected only on landings and organic
// sites — a local offer page never got it, so no recording and no heatmap
// points for the offer half of the funnel.
//
// Covered here (direct-to-offer campaign, the simplest arming path):
//   1. recording ON (campaign switch, 100 % sample) → the served offer HTML
//      carries data-orbitra-replay + /replay.js, and an armed session_replays
//      row exists for the click with campaign_id set and landing_id empty;
//   2. recording OFF → the served offer HTML carries no recorder tag;
//   3. the recorder must be the ONLY thing added: no LP dwell timer
//      (action=lp beacons would overwrite landing engagement numbers) and no
//      popup script on an offer's order form.
//
// The LP→offer hop rides the same orbitraInjectReplayForLocalOffer() call in
// orbitraServeLocalOffer(), so it is covered by the same injection point.
//
// Run: php tests/local_offer_replay_test.php

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

require_once __DIR__ . '/../core/SessionReplay.php';
require_once __DIR__ . '/lib/http.php';

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);

try {
    echo "Starting test server...\n";
    $harness->useProductionRouter(); // router.php knows /api.php and campaign aliases
    $harness->start();
    echo "Server started on " . $harness->getBaseUrl() . "\n\n";

    $pdo = $harness->getPdo();

    // Replay tables (the harness skips migrations) + the click columns the
    // arming read uses. Same defensive ALTERs as the cloak reconciliation test.
    orbitraReplayEnsureSchema($pdo);
    foreach (['is_safe_page' => 'INTEGER DEFAULT 0', 'is_bot' => 'INTEGER DEFAULT 0',
              'landing_id' => 'INTEGER', 'campaign_id' => 'INTEGER'] as $col => $def) {
        try {
            $pdo->exec("ALTER TABLE clicks ADD COLUMN $col $def");
        } catch (PDOException $e) {
            // Column already present.
        }
    }

    // ===== Seed: direct-to-LOCAL-offer campaign =====
    $campaignId = random_int(10000, 99999);
    $streamId = random_int(10000, 99999);
    $localOfferId = random_int(10000, 99999);
    $campaignAlias = 'lr' . bin2hex(random_bytes(4));
    $campaignToken = 'test_' . bin2hex(random_bytes(4));

    $pdo->prepare("
        INSERT INTO offers (id, name, url, is_local, state, is_archived)
        VALUES (?, ?, '', 1, 'active', 0)
    ")->execute([$localOfferId, 'Local Replay Offer']);

    $dbPath = $pdo->query("PRAGMA database_list")->fetch(PDO::FETCH_ASSOC)['file'];
    $workingDir = dirname($dbPath);
    $offerDir = $workingDir . '/offers/' . $localOfferId;
    @mkdir($offerDir, 0755, true);
    file_put_contents($offerDir . '/index.html', '<!DOCTYPE html><html><head><title>Offer ' . $localOfferId . '</title></head><body>Order Form ' . $localOfferId . '</body></html>');

    $pdo->prepare("
        INSERT INTO campaigns (id, name, alias, token, state, is_archived)
        VALUES (?, ?, ?, ?, 'active', 0)
    ")->execute([$campaignId, 'Local Offer Replay Campaign', $campaignAlias, $campaignToken]);

    // Redirect stream whose rotation is the local offer alone — no landing,
    // so the click arms through the CAMPAIGN recording switch.
    $pdo->prepare("
        INSERT INTO streams (id, campaign_id, name, type, schema_type, schema_custom_json, is_active, collect_clicks, position)
        VALUES (?, ?, ?, 'regular', 'redirect', ?, 1, 1, 1)
    ")->execute([
        $streamId,
        $campaignId,
        'Direct Local Offer Stream',
        json_encode(['offers' => [['id' => $localOfferId, 'weight' => 100]]]),
    ]);

    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_enabled', '1')")->execute();
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')")->execute();
    $harness->setPostbackKey('testkey42');

    $mobileUa = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    echo "Case 1: campaign recording ON (100 % sample)\n";
    $pdo->prepare("
        INSERT INTO replay_campaign_settings (campaign_id, enabled, sample_pct, updated_at)
        VALUES (?, 1, 100, ?)
        ON CONFLICT(campaign_id) DO UPDATE SET enabled = 1, sample_pct = 100
    ")->execute([$campaignId, gmdate('Y-m-d H:i:s')]);

    $resp1 = $harness->getWithHeaders("/$campaignAlias", [
        'User-Agent: ' . $mobileUa,
        'X-Forwarded-For: 185.13.10.1',
    ]);
    assertEquals(200, $resp1['code'], 'Local offer served inline (200)');
    assertTrue(strpos($resp1['body'], 'Order Form') !== false, 'Body is the offer page');
    assertTrue(strpos($resp1['body'], 'data-orbitra-replay') !== false, 'Recorder tag present in the offer HTML');
    assertTrue(strpos($resp1['body'], '/replay.js') !== false, 'Recorder loads /replay.js');

    $armed = $pdo->prepare("SELECT campaign_id, landing_id FROM session_replays WHERE campaign_id = ? ORDER BY created_at DESC LIMIT 1");
    $armed->execute([$campaignId]);
    $row = $armed->fetch(PDO::FETCH_ASSOC);
    assertTrue($row !== false, 'Armed session_replays row exists for the click');
    if ($row) {
        assertEquals($campaignId, (int) $row['campaign_id'], 'Recording row carries campaign_id');
        assertEquals(0, (int) $row['landing_id'], 'Direct-to-offer recording has no landing');
    }

    // The recorder must be the only addition: no dwell timer, no popup.
    assertTrue(strpos($resp1['body'], 'action=lp') === false, 'No LP dwell timer on the offer page');
    assertTrue(strpos($resp1['body'], '/popup.js') === false, 'No popup script on the offer page');

    echo "\nCase 2: campaign recording OFF\n";
    $pdo->prepare("UPDATE replay_campaign_settings SET enabled = 0 WHERE campaign_id = ?")->execute([$campaignId]);

    $resp2 = $harness->getWithHeaders("/$campaignAlias", [
        'User-Agent: ' . $mobileUa,
        'X-Forwarded-For: 185.13.10.2', // fresh IP: outside the 2 s debounce window's keying
    ]);
    assertEquals(200, $resp2['code'], 'Local offer still served inline with recording off');
    assertTrue(strpos($resp2['body'], 'Order Form') !== false, 'Body is still the offer page');
    assertTrue(strpos($resp2['body'], 'data-orbitra-replay') === false, 'No recorder tag when recording is off');

    echo $testPassed ? "\nALL PASSED\n" : "\nFAILED\n";
} catch (Throwable $e) {
    fwrite(STDERR, "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $testPassed = false;
    echo "\nFAILED\n";
} finally {
    $harness->stop();
}

exit($testPassed ? 0 : 1);
