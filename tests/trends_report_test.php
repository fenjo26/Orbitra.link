<?php
// tests/trends_report_test.php
//
// The Trends page (api.php?action=trends) must count exactly what the
// dashboard counts for the same local day:
//   1. date_from/date_to are report-local days ($dbTzOffset), not UTC days;
//   2. hourly buckets actually match the zero-filled axis (no all-zero hours);
//   3. hourly over several days spans every hour of every day;
//   4. the per-campaign "exclude Safe Page clicks from reports" flag applies.
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

    // 4: unticking the campaign checkbox brings the safe hit back.
    $harness->updateStreamSchema((int) $data['cloak_stream_id'], ['exclude_safe_from_reports' => false]);
    $dayAll = $trends('day', '2026-11-10', '2026-11-10');
    assertEquals(3, $sumClicks($dayAll), 'Trends day: safe hit counted when the campaign keeps safe clicks');
    $dashAll = $api(['action' => 'metrics', 'campaign_id' => $campaignId, 'date_range' => 'custom',
        'custom_from' => '2026-11-10', 'custom_to' => '2026-11-10', 'timezone' => 'Europe/Moscow']);
    assertEquals((int) ($dashAll['clicks'] ?? -1), $sumClicks($dayAll), 'Trends still equals the dashboard with the flag off');
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    $testPassed = false;
} finally {
    $harness->stop();
}

exit($testPassed ? 0 : 1);
