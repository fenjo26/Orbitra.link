<?php
// tests/dashboard_chart_test.php
//
// The dashboard chart's axis and series (core/DashboardChart.php):
//   - one local day is 24 hourly buckets, and the hours that have not started
//     yet are null — never a zero that draws a fake collapse;
//   - the rolling presets start on the same anchor day as the click filter,
//     so the chart cannot drop a day the cards still count;
//   - every dashboard card has a series computed by the cards' own function;
//   - per-user colours/styles are validated and stored per user.
//
// Run: php tests/dashboard_chart_test.php

require_once __DIR__ . '/../core/DashboardChart.php';

$failed = 0;
$check = function (string $label, $expected, $got) use (&$failed) {
    if ($expected === $got) {
        echo "  ok   $label\n";
        return;
    }
    $failed++;
    echo "  FAIL: $label\n    expected " . var_export($expected, true) . "\n    got      " . var_export($got, true) . "\n";
};

$tz = '+02:00';
// Thursday 2026-11-12 14:23 local (+02:00) = 12:23 UTC.
$now = gmmktime(12, 23, 0, 11, 12, 2026);

echo "Hourly axis (today)\n";
$plan = orbitraDashboardChartPlan('today', null, null, $tz, $now);
$check('granularity', 'hour', $plan['granularity']);
$check('24 buckets', 24, count($plan['periods']));
$check('first key', '2026-11-12 00:00:00', $plan['periods'][0]);
$check('current hour is not future', 15, $plan['future_from']);
$check('labels are HH:00', '14:00', $plan['labels'][14]);

echo "Hourly axis (yesterday, custom single day)\n";
$plan = orbitraDashboardChartPlan('yesterday', null, null, $tz, $now);
$check('yesterday key', '2026-11-11 23:00:00', $plan['periods'][23]);
$check('nothing in the future', null, $plan['future_from']);
$plan = orbitraDashboardChartPlan('custom', '2026-11-01', '2026-11-01', $tz, $now);
$check('custom single day is hourly', 'hour', $plan['granularity']);

echo "Local day follows the report offset, not UTC\n";
// 23:30 UTC on the 12th is already 01:30 on the 13th at +02:00.
$late = gmmktime(23, 30, 0, 11, 12, 2026);
$plan = orbitraDashboardChartPlan('today', null, null, $tz, $late);
$check('local today', '2026-11-13 00:00:00', $plan['periods'][0]);
$check('future starts after 01:00', 2, $plan['future_from']);

echo "Daily axes share the click filter's anchor day\n";
foreach (['last_7_days', 'last_30_days', 'this_week', 'this_month'] as $preset) {
    $plan = orbitraDashboardChartPlan($preset, null, null, $tz, $now);
    $check("$preset starts on the anchor day", orbitraDashboardAnchorDay($preset, $tz, $now), $plan['periods'][0]);
}
$plan = orbitraDashboardChartPlan('last_7_days', null, null, $tz, $now);
$check('last_7_days ends today', '2026-11-12', end($plan['periods']));
$check('last_7_days has no future', null, $plan['future_from']);

$plan = orbitraDashboardChartPlan('this_week', null, null, $tz, $now);
$check('this_week ends on Sunday', '2026-11-15', end($plan['periods']));
$check('this_week future starts tomorrow', array_search('2026-11-13', $plan['periods'], true), $plan['future_from']);

$plan = orbitraDashboardChartPlan('this_month', null, null, $tz, $now);
$check('this_month is the whole month', 30, count($plan['periods']));
$check('this_month future index', 12, $plan['future_from']);

echo "Custom ranges\n";
$plan = orbitraDashboardChartPlan('custom', '2026-10-30', '2026-11-02', $tz, $now);
$check('custom days', ['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'], $plan['periods']);
$plan = orbitraDashboardChartPlan('custom', '2020-01-01', '2026-11-12', $tz, $now);
$check('custom range is capped', 366, count($plan['periods']));
$plan = orbitraDashboardChartPlan('custom', "2026-11-01'; DROP", '2026-11-02', $tz, $now);
$check('malformed custom falls back to today', ['2026-11-12'], $plan['periods']);
$plan = orbitraDashboardChartPlan('custom', '2026-11-05', '2026-11-01', $tz, $now);
$check('reversed custom never loops', 1, count($plan['periods']));

echo "Monthly axis\n";
$plan = orbitraDashboardChartPlan('this_year', null, null, $tz, $now);
$check('12 months', 12, count($plan['periods']));
$check('month key', '2026-11-01', $plan['periods'][10]);
$check('December is future', 11, $plan['future_from']);

echo "Series\n";
$plan = orbitraDashboardChartPlan('today', null, null, $tz, $now);
$rows = [
    ['period' => '2026-11-12 00:00:00', 'clicks' => 36, 'unique_clicks' => 33, 'real_offer_clicks' => 18,
     'conversions' => 0, 'cost' => 0, 'revenue' => 0],
    ['period' => '2026-11-12 09:00:00', 'clicks' => 28, 'unique_clicks' => 10, 'real_offer_clicks' => 22,
     'conversions' => 2, 'purchases' => 1, 'holds' => 1, 'cost' => 10, 'revenue' => 25, 'bots' => 7],
];
$datasets = orbitraDashboardChartDatasets($plan, $rows);
$series = [];
foreach ($datasets as $ds) {
    $series[$ds['label']] = $ds['data'];
}
$check('one series per chart metric', orbitraDashboardChartMetricKeys(), array_keys($series));
$check('every series spans the axis', [24], array_values(array_unique(array_map('count', $series))));
$check('clicks are real numbers', 28, $series['clicks'][9]);
$check('offer clicks are real numbers', 22, $series['real_offer_clicks'][9]);
$check('an empty past hour is 0', 0, $series['clicks'][5]);
$check('a future hour is null', null, $series['clicks'][15]);
$check('a future hour is null for money too', null, $series['revenue'][20]);
$check('profit', 15.0, $series['profit'][9]);
$check('roi', 150.0, $series['roi'][9]);
$check('roi without spend is undefined, not 0', null, $series['roi'][0]);
$check('cr', 7.14, $series['cr'][9]);
$check('cpa', 5.0, $series['cpa'][9]);
$check('sales', 1, $series['sales'][9]);
$check('leads', 1, $series['leads'][9]);
$check('bot rate', 25.0, $series['bot_rate'][9]);
$check('ctr keeps the card placeholder', 100, $series['ctr'][9]);

// The chart must agree with the cards: same function, same input.
$card = orbitraComputeDerivedMetrics($rows[1]);
foreach (['clicks', 'revenue', 'profit', 'roi', 'epc', 'cpc', 'cr', 'approve_rate'] as $key) {
    $check("series equals the card for $key", $card[$key], $series[$key][9]);
}

echo "Per-user appearance\n";
$clean = orbitraDashboardChartPrefsSanitize([
    'colors' => [
        'clicks' => '#FF8800',
        'revenue' => 'red',
        'cost' => '#12345',
        'not_a_metric' => '#000000',
        'roi' => 'url(javascript:alert(1))',
    ],
    'styles' => ['clicks' => 'dashed', 'revenue' => 'zigzag', 'nope' => 'solid', 'sales' => 'bars', 'ctr' => 'dashdot'],
    'extra' => 'ignored',
]);
$check('only valid colours survive', ['clicks' => '#ff8800'], $clean['colors']);
$check('only valid styles survive', ['clicks' => 'dashed', 'sales' => 'bars', 'ctr' => 'dashdot'], $clean['styles']);
$check('garbage input is empty prefs', ['colors' => [], 'styles' => []], orbitraDashboardChartPrefsSanitize('x'));

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$check('no row yet', ['colors' => [], 'styles' => []], orbitraDashboardChartPrefsLoad($pdo, 7));
orbitraDashboardChartPrefsSave($pdo, 7, ['colors' => ['clicks' => '#0072b2'], 'styles' => ['roi' => 'dotted']]);
orbitraDashboardChartPrefsSave($pdo, 8, ['colors' => ['clicks' => '#d55e00']]);
$check('user 7 reads own colours', ['clicks' => '#0072b2'], orbitraDashboardChartPrefsLoad($pdo, 7)['colors']);
$check('user 7 reads own styles', ['roi' => 'dotted'], orbitraDashboardChartPrefsLoad($pdo, 7)['styles']);
$check('user 8 is separate', ['clicks' => '#d55e00'], orbitraDashboardChartPrefsLoad($pdo, 8)['colors']);
orbitraDashboardChartPrefsSave($pdo, 7, []);
$check('reset clears', ['colors' => [], 'styles' => []], orbitraDashboardChartPrefsLoad($pdo, 7));
$check('reset keeps the other user', ['clicks' => '#d55e00'], orbitraDashboardChartPrefsLoad($pdo, 8)['colors']);

echo $failed === 0 ? "\nAll dashboard chart tests passed.\n" : "\n$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
