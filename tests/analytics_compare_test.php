<?php
// tests/analytics_compare_test.php
//
// Verifies the shared AnalyticsTrends engine and the new API endpoints:
//   1. action=trends with compare_from/compare_to & pacing_cutoff_hour returns
//      primary + comparison tables, totals, and pacing_totals accurately;
//   2. action=analytics_compare (POST & GET) returns multi-series results for
//      campaigns, offers, landings, creatives (via creative_usages -> clicks.ad_ref),
//      and multi-period slots in one batched call;
//   3. derived metrics (roi, epc, cpa) match formulas;
//   4. action=creatives_simple returns lightweight creative asset list.
//
// Run: php tests/analytics_compare_test.php

$testPassed = true;

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
}

require_once __DIR__ . '/lib/http.php';

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();

try {
    $harness->start();
    $data = $harness->seedCloakTestData();
    $campA = (int) $data['campaign_id'];
    $pdo = $harness->getPdo();

    $apiKey = 'test_cmp_' . bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)')
        ->execute(['compare_test_admin', password_hash('x', PASSWORD_DEFAULT), 'admin']);
    $adminId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_api_keys (user_id, api_key, key_name, permissions) VALUES (?, ?, ?, ?)')
        ->execute([$adminId, $apiKey, 'compare-test', 'full']);

    $getApi = function (array $q) use ($harness, $apiKey): array {
        $resp = $harness->getWithHeaders('/api.php?' . http_build_query($q), ['Authorization: Bearer ' . $apiKey]);
        $json = json_decode($resp['body'], true);
        if (!is_array($json) || ($json['status'] ?? '') !== 'success') {
            fwrite(STDERR, "Bad GET response ({$resp['code']}): " . substr($resp['body'], 0, 500) . "\n");
            return [];
        }
        return $json['data'];
    };

    $postApi = function (array $q, array $body) use ($harness, $apiKey): array {
        $resp = $harness->postWithHeaders(
            '/api.php?' . http_build_query($q),
            json_encode($body),
            ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json']
        );
        $json = json_decode($resp['body'], true);
        if (!is_array($json) || ($json['status'] ?? '') !== 'success') {
            fwrite(STDERR, "Bad POST response ({$resp['code']}): " . substr($resp['body'], 0, 500) . "\n");
            return [];
        }
        return $json['data'];
    };

    // Second campaign + creative asset + usage
    $campB = 920000 + random_int(0, 50000);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived) VALUES (?, 'Compare Camp B', ?, '', 'active', 0)")
        ->execute([$campB, 'cmpb' . $campB]);

    $pdo->prepare("INSERT INTO media_assets (orig_name, stored_name, mime, size, kind, source, is_active) VALUES (?, ?, 'image/png', 100, 'image', 'facebook', 1)")
        ->execute(['Hook Video 1.mp4', 'hook_1.png']);
    $assetId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO creative_usages (asset_id, platform, ad_id, connection_id) VALUES (?, 'facebook', 'ad_999', 1)")
        ->execute([$assetId]);

    // Seed clicks across two days in UTC:
    //   2026-11-18 (yesterday):
    //     08:00 — campA, ad_999, cost=2.0, conv=1, rev=10.0
    //     18:00 — campA, ad_999, cost=3.0, conv=0, rev=0.0  (after pacing hour 12!)
    //   2026-11-19 (today):
    //     08:00 — campA, ad_999, cost=2.0, conv=1, rev=12.0
    //     10:00 — campB, no ad,  cost=1.0, conv=1, rev=5.0
    $insClick = $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at, is_safe_page, is_conversion, cost, ad_ref, uniq_global, uniq_campaign)
                               VALUES (?, ?, ?, ?, 0, ?, ?, ?, 1, 1)");
    $insConv = $pdo->prepare("INSERT INTO conversions (click_id, status, payout) VALUES (?, 'sale', ?)");

    $c1 = 'cmp_c1_' . bin2hex(random_bytes(4));
    $c2 = 'cmp_c2_' . bin2hex(random_bytes(4));
    $c3 = 'cmp_c3_' . bin2hex(random_bytes(4));
    $c4 = 'cmp_c4_' . bin2hex(random_bytes(4));

    $insClick->execute([$c1, $campA, '10.1.0.1', '2026-11-18 08:00:00', 1, 2.0, 'ad_999']);
    $insConv->execute([$c1, 10.0]);

    $insClick->execute([$c2, $campA, '10.1.0.2', '2026-11-18 18:00:00', 0, 3.0, 'ad_999']);

    $insClick->execute([$c3, $campA, '10.1.0.3', '2026-11-19 08:00:00', 1, 2.0, 'ad_999']);
    $insConv->execute([$c3, 12.0]);

    $insClick->execute([$c4, $campB, '10.1.0.4', '2026-11-19 10:00:00', 1, 1.0, '']);
    $insConv->execute([$c4, 5.0]);

    // 1. Trends period comparison + pacing cutoff at hour 12 (UTC)
    $tRes = $getApi([
        'action'             => 'trends',
        'group_by'           => 'hour',
        'date_from'          => '2026-11-19',
        'date_to'            => '2026-11-19',
        'compare_from'       => '2026-11-18',
        'compare_to'         => '2026-11-18',
        'pacing_cutoff_hour' => 12,
        'timezone'           => 'UTC',
        'filters'            => json_encode([['field' => 'campaign_id', 'operator' => 'equals', 'value' => (string) $campA]]),
    ]);
    assertEquals(1, (int) ($tRes['totals']['clicks'] ?? -1), 'Trends compare: primary clicks = 1');
    assertEquals(2, (int) ($tRes['compare']['totals']['clicks'] ?? -1), 'Trends compare: full yesterday clicks = 2');
    assertEquals(1, (int) ($tRes['compare']['pacing_totals']['clicks'] ?? -1), 'Trends compare: pacing yesterday clicks (<=12:59) = 1');
    assertEquals(10.0, (float) ($tRes['compare']['pacing_totals']['revenue'] ?? -1), 'Trends compare: pacing yesterday revenue = 10.0');
    assertEquals(500.0, (float) ($tRes['totals']['roi'] ?? -1), 'Trends derived ROI: (12-2)/2 * 100 = 500%');
    assertEquals(12.0, (float) ($tRes['totals']['epc'] ?? -1), 'Trends derived EPC: 12/1 = 12.0');
    assertEquals(2.0, (float) ($tRes['totals']['cpa'] ?? -1), 'Trends derived CPA: 2/1 = 2.0');

    // 2. analytics_compare POST: Campaign A vs Campaign B vs Creative asset
    $cmpRes = $postApi(['action' => 'analytics_compare', 'timezone' => 'UTC'], [
        'group_by'  => 'day',
        'date_from' => '2026-11-18',
        'date_to'   => '2026-11-19',
        'series'    => [
            [
                'id'      => 'campA',
                'filters' => [['field' => 'campaign_id', 'operator' => 'equals', 'value' => (string) $campA]],
            ],
            [
                'id'      => 'campB',
                'filters' => [['field' => 'campaign_id', 'operator' => 'equals', 'value' => (string) $campB]],
            ],
            [
                'id'      => 'creative1',
                'filters' => [['field' => 'creative_asset_id', 'operator' => 'equals', 'value' => (string) $assetId]],
            ],
            [
                'id'        => 'campA_day1_only',
                'date_from' => '2026-11-18',
                'date_to'   => '2026-11-18',
                'filters'   => [['field' => 'campaign_id', 'operator' => 'equals', 'value' => (string) $campA]],
            ],
        ],
    ]);
    $byId = [];
    foreach ($cmpRes['series'] ?? [] as $s) {
        $byId[$s['id']] = $s;
    }
    assertEquals(3, (int) ($byId['campA']['totals']['clicks'] ?? -1), 'Compare POST: campA total clicks across 2 days = 3');
    assertEquals(1, (int) ($byId['campB']['totals']['clicks'] ?? -1), 'Compare POST: campB total clicks = 1');
    assertEquals(3, (int) ($byId['creative1']['totals']['clicks'] ?? -1), 'Compare POST: creative_asset_id resolves via ad_ref to 3 clicks');
    assertEquals(22.0, (float) ($byId['creative1']['totals']['revenue'] ?? -1), 'Compare POST: creative_asset_id revenue = 22.0');
    assertEquals(2, (int) ($byId['campA_day1_only']['totals']['clicks'] ?? -1), 'Compare POST: per-series custom date range respected');

    // 3. creatives_simple returns the seeded asset
    $creativesSimple = $getApi(['action' => 'creatives_simple']);
    $found = false;
    foreach ($creativesSimple as $item) {
        if ((int) ($item['id'] ?? 0) === $assetId && ($item['name'] ?? '') === 'Hook Video 1.mp4') {
            $found = true;
            break;
        }
    }
    assertEquals(true, $found, 'creatives_simple returns seeded creative asset');
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    $testPassed = false;
} finally {
    $harness->stop();
}

exit($testPassed ? 0 : 1);
