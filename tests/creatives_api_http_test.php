<?php
/**
 * tests/creatives_api_http_test.php
 *
 * HTTP-контракты библиотеки креативов: creatives_list / creative_detail /
 * creative_tag_op / campaign_creatives — через настоящий api.php на тестовом
 * сервере (штатный tests/lib/http.php харнесс, как push_api_test).
 *
 * Ключевая регрессия campaign_creatives: один ассет, два объявления в кампании
 * — дневной агрегат обязан считаться ОДИН раз (correlated subquery), а не
 * по разу на каждую связку usages.
 *
 * Запуск из корня проекта:
 *
 *     php tests/creatives_api_http_test.php
 */

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/tests/lib/http.php';

$testPassed = true;
function assertTrue($condition, string $message): bool {
    global $testPassed;
    if (!$condition) { fwrite(STDERR, "FAILED: $message\n"); $testPassed = false; }
    else { echo "✓ $message\n"; }
    return (bool) $condition;
}

$harness = new OrbitraTestHarness($repoRoot);
$harness->useProductionRouter();
$harness->start();

try {
    $pdo = $harness->getPdo();

    // Schema stamp: read from config.php, never hardcoded.
    $expectedSchema = '';
    if (preg_match('/\$LATEST_SCHEMA_VERSION\s*=\s*(\d+)/', file_get_contents($repoRoot . '/config.php'), $mSchema)) {
        $expectedSchema = $mSchema[1];
    }
    assertTrue($expectedSchema !== '' && (int) $pdo->query('PRAGMA user_version')->fetchColumn() === (int) $expectedSchema,
        "migration stamps schema $expectedSchema");

    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, 'admin', 1, '{}')")
        ->execute(['cr_admin', password_hash('pass123', PASSWORD_DEFAULT)]);

    $login = static function () use ($harness) {
        try { $harness->getPdo()->exec('DELETE FROM rate_limits'); } catch (\Throwable $e) {}
        $resp = $harness->postWithHeaders('/api.php?action=login', json_encode(['username' => 'cr_admin', 'password' => 'pass123']), ['Content-Type: application/json']);
        $body = json_decode($resp['body'], true);
        if (($body['status'] ?? '') !== 'success') {
            fwrite(STDERR, "login failed: " . $resp['body'] . "\n");
            exit(1);
        }
        preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $m);
        return ['cookie' => 'ORBITRASESSID=' . $m[1], 'csrf' => $body['data']['csrf_token'] ?? ''];
    };
    $get = static function (string $action, array $ctx) use ($harness) {
        return $harness->getWithHeaders("/api.php?action=$action", ['Cookie: ' . $ctx['cookie']]);
    };
    $post = static function (string $action, array $ctx, array $payload) use ($harness) {
        return $harness->postWithHeaders("/api.php?action=$action", json_encode($payload), [
            'Cookie: ' . $ctx['cookie'], 'X-CSRF-TOKEN: ' . $ctx['csrf'], 'Content-Type: application/json',
        ]);
    };

    $admin = $login();

    // --- Fixture -------------------------------------------------------------
    $today = gmdate('Y-m-d');
    $pdo->exec("INSERT INTO media_assets (orig_name, stored_name, sha256, source, kind) VALUES ('hook.png', 'ab/hook.png', 'sha1', 'facebook', 'image')");
    $assetId = (int) $pdo->lastInsertId();
    // Один ассет, ДВА объявления — регрессия двойного счёта.
    $pdo->exec("INSERT INTO creative_usages (asset_id, platform, ad_id, adset_id, campaign_id, connection_id, ad_text)
        VALUES ($assetId, 'facebook', 'FBAD1', 'FBSET1', 'FBCAMP1', 1, 'Join now'),
               ($assetId, 'facebook', 'FBAD2', 'FBSET1', 'FBCAMP1', 1, 'Join now 2')");
    $pdo->exec("INSERT INTO creative_daily (asset_id, day, spend, fee, impressions, platform_clicks, tracker_clicks, visitors, installs, registrations, ftd, revenue)
        VALUES ($assetId, '$today', 90.0, 10.0, 5000, 120, 8, 10, 2, 3, 1, 165.0)");

    // Кампания 77 — cloak с исключением сейф-пейджа (как в проде).
    $pdo->exec("INSERT INTO campaigns (id, name, alias) VALUES (77, 'Cr Campaign', 'cr_campaign_77')");
    $pdo->exec("INSERT INTO streams (id, campaign_id, schema_type, schema_custom_json) VALUES (9, 77, 'cloak', '{\"exclude_safe_from_reports\":true}')");
    $ins = $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, cost, parameters_json, created_at, ad_ref, offer_id, direct_offer, is_safe_page) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $ins->execute(['m1', 77, '1.1.1.1', 5, '{"ad_id":"FBAD1"}', "$today 09:00:00", 'FBAD1', 3, 1, 0]);
    $ins->execute(['m2', 77, '1.1.1.1', 5, '{"ad_id":"FBAD2"}', "$today 10:00:00", 'FBAD2', 3, 1, 0]);
    $ins->execute(['sp', 77, '1.1.1.1', 0, '{"ad_id":"FBAD1"}', "$today 11:00:00", 'FBAD1', null, 0, 1]);
    $ins->execute(['other', 88, '1.1.1.1', 0, '{"ad_id":"FBAD1"}', "$today 12:00:00", 'FBAD1', null, 0, 0]);

    // --- campaign_creatives ---------------------------------------------------
    echo "\ncampaign_creatives\n";
    $resp = $get('campaign_creatives&campaign_id=77', $admin);
    $body = json_decode($resp['body'], true);
    assertTrue(($body['status'] ?? '') === 'success', 'campaign_creatives succeeds');
    $rows = $body['data'] ?? [];
    assertTrue(count($rows) === 1, 'one creative bound (asset shared by two ads)');
    $row = $rows[0] ?? [];
    assertTrue(abs(($row['spend_total'] ?? 0) - 100.0) < 0.0001, "daily aggregate counted ONCE despite two usages (got {$row['spend_total']})");
    assertTrue(abs(($row['revenue'] ?? 0) - 165.0) < 0.0001, 'revenue from creative_daily');
    assertTrue(abs(($row['roi'] ?? 0) - 65.0) < 0.001, "roi = (165-100)/100 (got {$row['roi']})");
    assertTrue(($row['campaign_clicks'] ?? 0) === 2, 'campaign clicks of both ads, safe page excluded');

    $resp = $get('campaign_creatives&campaign_id=88', $admin);
    $body = json_decode($resp['body'], true);
    $otherRow = $body['data'][0] ?? [];
    assertTrue(($body['status'] ?? '') === 'success'
        && count($body['data'] ?? []) === 1
        && ($otherRow['campaign_clicks'] ?? 0) === 1,
        'clicks are campaign-scoped (the other campaign sees only its own click)');

    // --- creatives_list ---------------------------------------------------------
    echo "\ncreatives_list\n";
    $resp = $get("creatives_list&date_from=$today&date_to=$today", $admin);
    $body = json_decode($resp['body'], true);
    assertTrue(($body['status'] ?? '') === 'success', 'creatives_list succeeds');
    assertTrue(count($body['data'] ?? []) === 1, 'the synced asset is listed');
    $listRow = $body['data'][0] ?? [];
    assertTrue(($listRow['platform'] ?? '') === 'facebook' && ($listRow['active_ads'] ?? -1) >= 0, 'platform + ad counters present');
    assertTrue(abs(($body['totals']['spend_total'] ?? 0) - 100.0) < 0.0001, 'totals over unique assets');
    assertTrue(isset($body['worker']['pending']), 'worker diagnostics attached');

    // --- creative_detail ---------------------------------------------------------
    echo "\ncreative_detail\n";
    $resp = $get("creative_detail&id=$assetId&date_from=$today&date_to=$today", $admin);
    $body = json_decode($resp['body'], true);
    assertTrue(($body['status'] ?? '') === 'success', 'creative_detail succeeds');
    $detail = $body['data'] ?? [];
    assertTrue(count($detail['series'] ?? []) === 1, 'one day in the series');
    assertTrue(count($detail['usages'] ?? []) === 2, 'both ads listed with their texts');
    assertTrue(($detail['usages'][0]['ad_text'] ?? '') !== '', 'ad text carried');

    // --- creative_tag_op ---------------------------------------------------------
    echo "\ncreative_tag_op\n";
    $resp = $post('creative_tag_op', $admin, ['id' => $assetId, 'tags' => ['casino', 'greece', '']]);
    $body = json_decode($resp['body'], true);
    assertTrue(($body['status'] ?? '') === 'success', 'creative_tag_op saves');
    assertTrue(($body['data']['tags'] ?? []) === ['casino', 'greece'], 'empty tags dropped');
    $stored = $pdo->prepare("SELECT tags FROM media_assets WHERE id = ?");
    $stored->execute([$assetId]);
    assertTrue(($stored->fetchColumn() === '["casino","greece"]'), 'tags persisted as JSON');

} finally {
    $harness->stop();
}

echo $testPassed ? "\nALL TESTS PASSED\n" : "\nTESTS FAILED\n";
exit($testPassed ? 0 : 1);
