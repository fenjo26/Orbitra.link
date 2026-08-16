<?php
// tests/report_metrics_test.php
//
// End-to-end math check for the 64-metric set: seeds a reference dataset
// (clicks with costs, landings/offers paths, conversions in every status,
// aggregator revenue) into a throwaway SQLite file, runs the shared
// conversion-aggregate SQL and orbitraComputeDerivedMetrics(), and asserts
// every derived number against hand-computed expectations.
//
// Run: php tests/report_metrics_test.php

require_once __DIR__ . '/../core/ReportMetrics.php';

$tmpDb = sys_get_temp_dir() . '/orbitra_metrics_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$pdo = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec('CREATE TABLE conversions (id INTEGER PRIMARY KEY, click_id TEXT, tid TEXT, status TEXT, payout REAL)');
// Mirror of the live seed used to hunt the registration/deposit bugs:
// 2 sales + 1 confirmed (sale group), 1 deposit, 1 registration, 1 lead,
// 1 rejected, 1 trash — payouts 20/15/10/30/1/2/5/0.5 = 83.5 total,
// 8 conversion EVENTS on 6 clicks.
$rows = [
    ['m1', 'a1', 'sale', 20], ['m1', 'a2', 'sale', 15], ['m1', 'a3', 'confirmed', 10],
    ['m2', 'a4', 'deposit', 30], ['m2', 'a5', 'registration', 1],
    ['m3', 'a6', 'lead', 2], ['m4', 'a7', 'rejected', 5], ['m5', 'a8', 'trash', 0.5],
];
$st = $pdo->prepare('INSERT INTO conversions (click_id, tid, status, payout) VALUES (?,?,?,?)');
foreach ($rows as $r) { $st->execute($r); }

$aggSql = orbitraConversionAggregateSql('payout');
$agg = $pdo->query("SELECT * FROM $aggSql WHERE click_id = 'm1'")->fetch(PDO::FETCH_ASSOC);
$assert = function (string $label, $got, $expected, float $tol = 0.01) {
    $ok = is_numeric($got) && is_numeric($expected)
        ? abs((float) $got - (float) $expected) <= $tol
        : $got === $expected;
    if (!$ok) {
        echo "FAIL $label: got " . var_export($got, true) . ", expected " . var_export($expected, true) . "\n";
        exit(1);
    }
};

// Aggregate per click m1: 3 conversion events; the sale group swallows
// 'confirmed' too (sale/confirmed/approved/purchase), so 3 events / 45.00.
$assert('cnt_any m1', $agg['cnt_any'], 3, 0);
$assert('cnt_sale m1', $agg['cnt_sale'], 3, 0);
$assert('rev_sale m1', $agg['rev_sale'], 45);

// Whole-set derived metrics: clicks 6, uniques 5, cost 21, lp views 3,
// lp transitions 3, offer clicks 4 (one direct-linked).
$m = orbitraComputeDerivedMetrics([
    'clicks' => 6, 'unique_clicks' => 5,
    'prelander_clicks' => 3, 'offer_clicks' => 4, 'lp_clicks' => 3,
    'conversions' => 8, 'purchases' => 3, 'holds' => 1, 'rejected' => 1, 'trash' => 1,
    'registrations' => 1, 'deposits' => 1,
    'cost' => 21, 'revenue' => 83.5, 'revenue_confirmed' => 45, 'revenue_hold' => 2,
    'revenue_rejected' => 5, 'revenue_trash' => 0.5, 'revenue_deposit' => 30,
    'revenue_registration' => 1, 'real_revenue' => 40,
]);

$expected = [
    'clicks' => 6, 'unique_clicks' => 5, 'uc_rate' => 83.33,
    'conversions' => 8, 'sales' => 3, 'leads' => 1, 'registrations' => 1, 'deposits' => 1,
    'approve_rate' => 50.0,
    'revenue' => 83.5, 'revenue_confirmed' => 45, 'revenue_deposit' => 30, 'revenue_registration' => 1,
    'cost' => 21, 'profit' => 62.5, 'profit_confirmed' => 24,
    'roi' => 297.62, 'roi_confirmed' => 114.29, 'profitability' => 74.85,
    'cr' => 133.33, 'cr_sales' => 50, 'cr_deposits' => 16.67, 'cr_regs_to_deps' => 100, 'ucr' => 20,
    'epc' => 13.9167, 'uepc' => 16.7, 'epc_confirmed' => 7.5, 'uepc_confirmed' => 9,
    'cps' => 7, 'cpl' => 21, 'cpr' => 21, 'cpd' => 21, 'cpa' => 2.63,
    'cpc' => 3.5, 'ucpc' => 4.2, 'ecpc' => 3.5,
    'ecpm_all' => 10416.67, 'ecpm_confirmed' => 4000,
    'earnings_per_conv' => 10.44, 'ec_confirmed' => 15,
    'real_profit' => 19, 'real_roi' => 90.48,
    'lp_views' => 3, 'lp_clicks' => 3, 'offer_clicks' => 4, 'lp_ctr' => 100,
];
foreach ($expected as $k => $v) {
    $assert($k, $m[$k] ?? null, $v, max(0.02, abs($v) * 0.005));
}

// ROI at zero spend must be null (rendered as a dash), not a made-up 100%.
$zero = orbitraComputeDerivedMetrics(['clicks' => 1, 'cost' => 0, 'revenue' => 5]);
$assert('roi at zero cost is null', $zero['roi'], null);

@unlink($tmpDb);
echo "Report metrics tests passed (" . count($expected) . " derived + aggregate checks).\n";
