<?php
// tests/trends_report_test.php
//
// The Trends page (api.php?action=trends) must count exactly what the
// dashboard counts for the same local day:
//   1. date_from/date_to are report-local days ($dbTzOffset), not UTC days;
//   2. hourly buckets actually match the zero-filled axis (no all-zero hours);
//   3. hourly over several days spans every hour of every day;
//   4. the per-campaign "exclude Safe Page clicks from reports" flag applies;
//   5. the Total row: sums, ratios from the sums, uniques over the whole range;
//   6. uniqueness mode per surface: no campaign filter → rows sum uniq_global
//      (the dashboard-card rule), a campaign filter → rows sum uniq_campaign.
//
// Run: php tests/trends_report_test.php

$testPassed = true;

function assertEquals($expected, $actual, $message) {
    global $testPassed;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected: " . var_export($expected, true) . "\n");
        fwrite(STDERR, "  Actual: " . var_export($actual, true) . "\n");
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
    $campaignId = (int) $data['campaign_id'];
    $pdo = $harness->getPdo();

    $apiKey = 'test_key_' . bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)')
        ->execute(['trends_test_admin', password_hash('x', PASSWORD_DEFAULT), 'admin']);
    $adminId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_api_keys (user_id, api_key, key_name, permissions) VALUES (?, ?, ?, ?)')
        ->execute([$adminId, $apiKey, 'trends-test', 'full']);
    $api = function (array $q) use ($harness, $apiKey): array {
        $resp = $harness->getWithHeaders('/api.php?' . http_build_query($q), ['Authorization: Bearer ' . $apiKey]);
        $json = json_decode($resp['body'], true);
        if (!is_array($json) || ($json['status'] ?? '') !== 'success') {
            fwrite(STDERR, "Bad response ({$resp['code']}): " . substr($resp['body'], 0, 500) . "\n");
            return [];
        }
        return $json['data'];
    };

    // Moscow is UTC+3 with no DST, so the expected buckets are unambiguous.
    // [UTC created_at, is_safe_page]
    $rows = [
        ['2026-11-09 22:30:00', 0], // 2026-11-10 01:30 MSK — 10th locally, 9th in UTC
        ['2026-11-10 12:15:00', 0], // 2026-11-10 15:15 MSK
        ['2026-11-10 13:00:00', 1], // 2026-11-10 16:00 MSK, safe page hit
        ['2026-11-10 21:30:00', 0], // 2026-11-11 00:30 MSK — 11th locally, 10th in UTC
    ];
    $ins = $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at, is_safe_page) VALUES (?, ?, ?, ?, ?)");
    foreach ($rows as $i => [$at, $safe]) {
        $ins->execute(['trend_' . $i . '_' . bin2hex(random_bytes(4)), $campaignId, '10.0.0.' . ($i + 1), $at, $safe]);
    }

    $filters = json_encode([['field' => 'campaign_id', 'operator' => 'equals', 'value' => (string) $campaignId]]);
    $trends = function (string $groupBy, string $from, string $to) use ($api, $filters): array {
        return $api(['action' => 'trends', 'group_by' => $groupBy, 'date_from' => $from, 'date_to' => $to,
            'metrics' => 'clicks', 'filters' => $filters, 'timezone' => 'Europe/Moscow']);
    };
    $sumClicks = fn(array $d) => array_sum(array_map(fn($r) => (int) $r['clicks'], $d['table'] ?? []));

    // 1 + 4: one local day, safe hit excluded by default — and equal to the dashboard.
    $day = $trends('day', '2026-11-10', '2026-11-10');
    assertEquals(2, $sumClicks($day), 'Trends day: local-day bounds, safe hit excluded');
    $dash = $api(['action' => 'metrics', 'campaign_id' => $campaignId, 'date_range' => 'custom',
        'custom_from' => '2026-11-10', 'custom_to' => '2026-11-10', 'timezone' => 'Europe/Moscow']);
    assertEquals((int) ($dash['clicks'] ?? -1), $sumClicks($day), 'Trends day total equals the dashboard total');

    // 2: hourly buckets carry the clicks.
    $hour = $trends('hour', '2026-11-10', '2026-11-10');
    $byPeriod = [];
    foreach ($hour['table'] ?? [] as $r) { $byPeriod[$r['period']] = (int) $r['clicks']; }
    assertEquals(24, count($byPeriod), 'Hourly single day: 24 buckets');
    assertEquals(1, $byPeriod['2026-11-10 01:00:00'] ?? null, 'Hourly: 01:00 MSK bucket holds its click');
    assertEquals(1, $byPeriod['2026-11-10 15:00:00'] ?? null, 'Hourly: 15:00 MSK bucket holds its click');
    assertEquals(0, $byPeriod['2026-11-10 16:00:00'] ?? null, 'Hourly: safe hit is not counted in its hour');
    assertEquals(2, $sumClicks($hour), 'Hourly total equals daily total');

    // 3: hourly across two days spans 48 hours.
    $hour2 = $trends('hour', '2026-11-10', '2026-11-11');
    $periods = array_column($hour2['table'] ?? [], 'period');
    assertEquals(48, count($periods), 'Hourly two days: 48 buckets');
    assertEquals('2026-11-11 23:00:00', end($periods), 'Hourly two days: axis ends at the last hour of the last day');
    assertEquals(3, $sumClicks($hour2), 'Hourly two days: second day click included');

    // 5: the Total row. Additive counts sum, ratios are recomputed from the
    // sums, and unique clicks are counted once over the whole range — the
    // same IP on two days is one unique visitor, not two.
    // [UTC created_at, ip, landing_id, offer_at?, is_conversion, cost]
    $totalRows = [
        ['2026-11-12 09:00:00', '10.9.0.1', 5, true, 0, 0.5],  // 12th MSK: landing → offer
        ['2026-11-12 10:00:00', '10.9.0.2', 5, false, 0, 0.5], // 12th MSK: landing, left
        ['2026-11-13 09:00:00', '10.9.0.1', 0, false, 1, 1.0], // 13th MSK: same IP again, converts
    ];
    $insT = $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at, is_safe_page, landing_id, offer_at, is_conversion, cost)
                           VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)");
    foreach ($totalRows as $i => [$at, $ip, $lid, $hasOffer, $conv, $cost]) {
        $insT->execute(['trend_tot_' . $i . '_' . bin2hex(random_bytes(4)), $campaignId, $ip, $at,
            $lid, $hasOffer ? $at : null, $conv, $cost]);
    }
    $tot = $api(['action' => 'trends', 'group_by' => 'day', 'date_from' => '2026-11-12', 'date_to' => '2026-11-13',
        'metrics' => 'clicks,unique_clicks,conversions,cost,cr,ctr', 'filters' => $filters, 'timezone' => 'Europe/Moscow']);
    $rowsBy = [];
    foreach ($tot['table'] ?? [] as $r) { $rowsBy[$r['period_key'] ?? '?'] = $r; }
    $totals = $tot['totals'] ?? [];
    assertEquals(3, (int) ($totals['clicks'] ?? -1), 'Totals: clicks are the sum of the rows');
    assertEquals(3, array_sum(array_map(fn($r) => (int) $r['unique_clicks'], $tot['table'] ?? [])), 'Rows: per-day uniques add up to 3');
    assertEquals(2, (int) ($totals['unique_clicks'] ?? -1), 'Totals: unique clicks counted once over the range (2, not 3)');
    assertEquals(1, (int) ($totals['conversions'] ?? -1), 'Totals: conversions summed');
    assertEquals(2.0, (float) ($totals['cost'] ?? -1), 'Totals: cost summed');
    assertEquals(33.33, (float) ($totals['cr'] ?? -1), 'Totals: CR recomputed from the sums, not averaged (0% and 100%)');
    assertEquals(50.0, (float) ($rowsBy['2026-11-12']['ctr'] ?? -1), 'Rows: LP CTR = offer transitions / landing views');
    $row13 = $rowsBy['2026-11-13'] ?? [];
    assertEquals(true, array_key_exists('ctr', $row13) && $row13['ctr'] === null, 'Rows: no landing views → LP CTR is null, not 0 or 100');
    assertEquals(50.0, (float) ($totals['ctr'] ?? -1), 'Totals: LP CTR from summed counts');

    // 4: unticking the campaign checkbox brings the safe hit back.
    $harness->updateStreamSchema((int) $data['cloak_stream_id'], ['exclude_safe_from_reports' => false]);
    $dayAll = $trends('day', '2026-11-10', '2026-11-10');
    assertEquals(3, $sumClicks($dayAll), 'Trends day: safe hit counted when the campaign keeps safe clicks');
    $dashAll = $api(['action' => 'metrics', 'campaign_id' => $campaignId, 'date_range' => 'custom',
        'custom_from' => '2026-11-10', 'custom_to' => '2026-11-10', 'timezone' => 'Europe/Moscow']);
    assertEquals((int) ($dashAll['clicks'] ?? -1), $sumClicks($dayAll), 'Trends still equals the dashboard with the flag off');

    // 6: uniqueness mode per surface. 10.9.0.1 already clicked campaign A on
    // the 12th; in campaign B they are first-time (uniq_campaign=1) but a
    // returning visitor (uniq_global=0). Without a campaign filter the rows
    // must count them as NOT unique — same number the dashboard card shows;
    // with a campaign filter they are unique.
    $campaignB = 910000 + random_int(0, 89999);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived) VALUES (?, 'Trends Uniq B', ?, '', 'active', 0)")
        ->execute([$campaignB, 'tuniq' . $campaignB]);
    $insU = $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at, is_safe_page, uniq_campaign, uniq_global)
                           VALUES (?, ?, ?, ?, 0, ?, ?)");
    $insU->execute(['trend_uniq_1_' . bin2hex(random_bytes(4)), $campaignB, '10.9.0.1', '2026-11-14 09:00:00', 1, 0]);
    $insU->execute(['trend_uniq_2_' . bin2hex(random_bytes(4)), $campaignB, '10.9.0.7', '2026-11-14 10:00:00', 1, 1]);
    $rowOf = function (array $d): array { return $d['table'][0] ?? []; };
    $noFilter = $api(['action' => 'trends', 'group_by' => 'day', 'date_from' => '2026-11-14', 'date_to' => '2026-11-14',
        'metrics' => 'clicks,unique_clicks', 'timezone' => 'Europe/Moscow']);
    assertEquals(2, (int) ($rowOf($noFilter)['clicks'] ?? -1), 'Uniq mode: campaign B clicks visible without a filter');
    assertEquals(1, (int) ($rowOf($noFilter)['unique_clicks'] ?? -1), 'Uniq mode: rows sum uniq_global without a campaign filter (returning visitor not unique)');
    assertEquals(2, (int) ($noFilter['totals']['unique_clicks'] ?? -1), 'Uniq mode: totals still COUNT(DISTINCT ip)');
    $withB = $api(['action' => 'trends', 'group_by' => 'day', 'date_from' => '2026-11-14', 'date_to' => '2026-11-14',
        'metrics' => 'clicks,unique_clicks', 'timezone' => 'Europe/Moscow',
        'filters' => json_encode([['field' => 'campaign_id', 'operator' => 'equals', 'value' => (string) $campaignB]])]);
    assertEquals(2, (int) ($rowOf($withB)['unique_clicks'] ?? -1), 'Uniq mode: rows sum uniq_campaign under a campaign filter');
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    $testPassed = false;
} finally {
    $harness->stop();
}

exit($testPassed ? 0 : 1);
