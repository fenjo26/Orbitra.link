<?php

require_once __DIR__ . '/../core/finance_masking.php';

$failures = [];

$check = static function (string $name, $expected, $actual) use (&$failures) {
    if ($expected !== $actual) {
        $failures[] = "$name: expected " . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

// --- orbitraFinanceFlagsFromPermissions: defaults are "visible" ---
$check('null permissions → all visible',
    ['costs' => true, 'revenue' => true, 'payout' => true],
    orbitraFinanceFlagsFromPermissions(null));
$check('no finance key → all visible',
    ['costs' => true, 'revenue' => true, 'payout' => true],
    orbitraFinanceFlagsFromPermissions(['campaigns' => ['access' => 'full']]));
$check('non-array finance → all visible',
    ['costs' => true, 'revenue' => true, 'payout' => true],
    orbitraFinanceFlagsFromPermissions(['finance' => 'nope']));
$check('all three disabled',
    ['costs' => false, 'revenue' => false, 'payout' => false],
    orbitraFinanceFlagsFromPermissions(['finance' => ['show_costs' => false, 'show_revenue' => false, 'show_payout' => false]]));
$check('partial flags default to visible',
    ['costs' => false, 'revenue' => true, 'payout' => true],
    orbitraFinanceFlagsFromPermissions(['finance' => ['show_costs' => false]]));

// --- orbitraFinanceKeyMasked: family vectors ---
$allHidden = ['costs' => false, 'revenue' => false, 'payout' => false];
$keyVectors = [
    'cost'                  => [true,  false, false],
    'click_cost'            => [true,  false, false],
    'costs'                 => [true,  false, false],
    'spend'                 => [true,  false, false],
    'cpc'                   => [true,  false, false],
    'cpa'                   => [true,  false, false],
    'cost_value'            => [true,  false, false],
    'cost_model (label)'    => [false, false, false],
    'revenue'               => [false, true,  false],
    'real_revenue'          => [false, true,  false],
    'click_sale_revenue'    => [false, true,  false],
    'revenue_confirmed'     => [false, true,  false],
    'revenue_hold'          => [false, true,  false],
    'profit'                => [false, true,  false],
    'real_profit'           => [false, true,  false],
    'roi'                   => [false, true,  false],
    'real_roi'              => [false, true,  false],
    'epc'                   => [false, true,  false],
    'payout'                => [false, false, true],
    'payouts'               => [false, false, true],
    'payout_value'          => [false, false, true],
    'payout_type (label)'   => [false, false, false],
    'clicks (not finance)'  => [false, false, false],
    'conversions'           => [false, false, false],
    'avg_lp_seconds'        => [false, false, false],
];
foreach ($keyVectors as $key => [$costsHidden, $revenueHidden, $payoutHidden]) {
    $check("key '$key' with costs hidden", $costsHidden, orbitraFinanceKeyMasked($key, ['costs' => false, 'revenue' => true, 'payout' => true]));
    $check("key '$key' with revenue hidden", $revenueHidden, orbitraFinanceKeyMasked($key, ['costs' => true, 'revenue' => false, 'payout' => true]));
    $check("key '$key' with payout hidden", $payoutHidden, orbitraFinanceKeyMasked($key, ['costs' => true, 'revenue' => true, 'payout' => false]));
    $check("key '$key' fully visible", false, orbitraFinanceKeyMasked($key, ['costs' => true, 'revenue' => true, 'payout' => true]));
    $check("key '$key' all hidden", $costsHidden || $revenueHidden || $payoutHidden, orbitraFinanceKeyMasked($key, $allHidden));
}

// --- orbitraMaskFinance: recursion over a report-shaped payload ---
$payload = [
    'status' => 'success',
    'data' => [
        ['name' => 'Camp A', 'clicks' => 12, 'cost' => 3.5, 'revenue' => 9.0, 'profit' => 5.5, 'cost_model' => 'CPC', 'payout_value' => 4.0],
        ['name' => 'Camp B', 'clicks' => 7, 'cost' => 1.0, 'real_revenue' => 2.0, 'real_roi' => 100, 'payout_type' => 'cpa'],
    ],
    'totals' => ['clicks' => 19, 'cost' => 4.5, 'revenue' => 11.0],
];
$masked = orbitraMaskFinance($payload, $allHidden);
$check('status untouched', 'success', $masked['status']);
$check('non-finance row key untouched', 12, $masked['data'][0]['clicks']);
$check('cost nulled', null, $masked['data'][0]['cost']);
$check('revenue nulled', null, $masked['data'][0]['revenue']);
$check('profit nulled', null, $masked['data'][0]['profit']);
$check('cost_model label survives', 'CPC', $masked['data'][0]['cost_model']);
$check('payout_value nulled', null, $masked['data'][0]['payout_value']);
$check('real_revenue nulled', null, $masked['data'][1]['real_revenue']);
$check('real_roi nulled', null, $masked['data'][1]['real_roi']);
$check('payout_type label survives', 'cpa', $masked['data'][1]['payout_type']);
$check('nested totals cost nulled', null, $masked['totals']['cost']);
$check('nested totals revenue nulled', null, $masked['totals']['revenue']);
$check('nested totals clicks survive', 19, $masked['totals']['clicks']);

// Only one family hidden → the other families survive.
$costsOnly = orbitraMaskFinance($payload, ['costs' => false, 'revenue' => true, 'payout' => true]);
$check('costs-only: cost nulled', null, $costsOnly['data'][0]['cost']);
$check('costs-only: revenue survives', 9.0, $costsOnly['data'][0]['revenue']);
$check('costs-only: payout_value survives', 4.0, $costsOnly['data'][0]['payout_value']);

// Fully visible flags must return the payload untouched.
$check('visible flags are a passthrough', $payload, orbitraMaskFinance($payload, ['costs' => true, 'revenue' => true, 'payout' => true]));

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Finance masking tests passed ('
    . count($keyVectors) . ' key vectors × 5 flag sets + flag defaults + recursion + passthrough).' . PHP_EOL;
