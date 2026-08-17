<?php
// tests/report_metrics_test.php
//
// End-to-end math check for the 65-metric set: seeds a reference dataset
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
    'unique_clicks_stream' => 5, 'unique_clicks_global' => 4, 'visitors' => 4,
    'bots' => 1, 'proxies' => 2, 'empty_referrers' => 3, 'avg_lp_seconds' => 95,
    'prelander_clicks' => 3, 'offer_clicks' => 4, 'lp_clicks' => 3,
    'conversions' => 8, 'purchases' => 3, 'holds' => 1, 'rejected' => 1, 'trash' => 1,
    'registrations' => 1, 'deposits' => 1,
    'cost' => 21, 'revenue' => 83.5, 'revenue_confirmed' => 45, 'revenue_hold' => 2,
    'revenue_rejected' => 5, 'revenue_trash' => 0.5, 'revenue_deposit' => 30,
    'revenue_registration' => 1, 'real_revenue' => 40,
]);

$expected = [
    'clicks' => 6, 'unique_clicks' => 5, 'uc_rate' => 83.33,
    'unique_clicks_stream' => 5, 'unique_clicks_global' => 4, 'visitors' => 4,
    'bots' => 1, 'bot_rate' => 16.67, 'proxies' => 2, 'empty_referrers' => 3,
    'time_since_lp_click' => '1m 35s',
    'conversions' => 8, 'sales' => 3, 'leads' => 1, 'registrations' => 1, 'deposits' => 1,
    'approve_rate' => 50.0,
    'revenue' => 83.5, 'revenue_confirmed' => 45, 'revenue_deposit' => 30, 'revenue_registration' => 1,
    'cost' => 21, 'profit' => 62.5, 'profit_confirmed' => 24,
    'roi' => 297.62, 'roi_confirmed' => 114.29, 'profitability' => 74.85,
    'cr' => 133.33, 'cr_sales' => 50, 'cr_deposits' => 16.67, 'cr_regs_to_deps' => 100, 'ucr' => 20,
    'epc' => 13.9167, 'uepc' => 16.7, 'epc_confirmed' => 7.5, 'uepc_confirmed' => 9,
    'epv' => 13.9167, 'epv_confirmed' => 7.5,
    'cps' => 7, 'cpl' => 21, 'cpr' => 21, 'cpd' => 21, 'cpa' => 2.63,
    'cpc' => 3.5, 'ucpc' => 4.2, 'cpv' => 3.5, 'ecpc' => 3.5,
    'ecpm_all' => 10416.67, 'ecpm_confirmed' => 4000,
    'earnings_per_conv' => 10.44, 'ec_confirmed' => 15,
    'real_profit' => 19, 'real_roi' => 90.48,
    'lp_views' => 3, 'lp_clicks' => 3, 'offer_clicks' => 4, 'lp_ctr' => 100,
];
foreach ($expected as $k => $v) {
    $tol = is_numeric($v) ? max(0.02, abs($v) * 0.005) : 0;
    $assert($k, $m[$k] ?? null, $v, $tol);
}

// ROI at zero spend must be null (rendered as a dash), not a made-up 100%.
$zero = orbitraComputeDerivedMetrics(['clicks' => 1, 'cost' => 0, 'revenue' => 5]);
$assert('roi at zero cost is null', $zero['roi'], null);

// ---------------------------------------------------------------------------
// Production SQL check for the Landings/Offers table pages: seed the mini
// universe below, run the exact queries core/ReportMetrics.php ships, apply
// the same derivation loop the api.php endpoints run, and assert every column
// the tables render. Hand-computed expectations, so a formula drift anywhere
// in visits/LP CTR/approve/EPV/ROI fails here before it ships.
//
// Clicks (one row = one visit; offer_id set means the visitor clicked through
// to the offer — the router UPDATEs the row, it never inserts a second one):
//   m1 landing 1 → offer 7, ip A, cost 10   (3 conversions, all sale group)
//   m2 landing 1, no offer,    ip A, cost 5  (deposit + registration)
//   m3 landing 1, no offer,    ip B, cost 1  (lead)
//   m4 landing 2 → offer 8, ip C, cost 4     (rejected)
//   m5 no landing → offer 9,  ip D, cost 1   (trash)
//   m6 nothing,               ip E, cost 0   (no conversions)
$pdo->exec('CREATE TABLE clicks (id TEXT PRIMARY KEY, campaign_id INTEGER, offer_id INTEGER,
    landing_id INTEGER, ip TEXT, cost REAL DEFAULT 0, is_conversion INTEGER DEFAULT 0,
    revenue REAL DEFAULT 0, created_at TEXT DEFAULT "2026-01-01 10:00:00")');
$pdo->exec('CREATE TABLE landings (id INTEGER PRIMARY KEY, name TEXT, type TEXT, url TEXT,
    state TEXT, group_id INTEGER, is_archived INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE landing_groups (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE offers (id INTEGER PRIMARY KEY, name TEXT, group_id INTEGER,
    affiliate_network_id INTEGER, url TEXT, redirect_type TEXT, is_local INTEGER, geo TEXT,
    payout_type TEXT, payout_value REAL, payout_auto INTEGER, allow_rebills INTEGER,
    capping_limit INTEGER, capping_timezone TEXT, alt_offer_id INTEGER, notes TEXT,
    state TEXT, created_at TEXT, is_archived INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE offer_groups (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE affiliate_networks (id INTEGER PRIMARY KEY, name TEXT)');

$st = $pdo->prepare('INSERT INTO clicks (id, landing_id, offer_id, ip, cost) VALUES (?,?,?,?,?)');
foreach ([['m1',1,7,'A',10], ['m2',1,null,'A',5], ['m3',1,null,'B',1],
          ['m4',2,8,'C',4], ['m5',null,9,'D',1], ['m6',null,null,'E',0]] as $r) { $st->execute($r); }
$st = $pdo->prepare('INSERT INTO landings (id, name, group_id) VALUES (?,?,?)');
foreach ([[1,'LP one',1], [2,'LP two',null], [3,'LP empty',null]] as $r) { $st->execute($r); }
$pdo->exec("INSERT INTO landing_groups (id, name) VALUES (1, 'grp')");
$pdo->exec('INSERT INTO offers (id, name) VALUES (7,"of7"), (8,"of8"), (9,"of9")');

// The derivation loop the landings/offers endpoints run after the SQL.
$deriveRow = function (array $row): array {
    $row['prelander_clicks'] = $row['clicks'];
    $m = orbitraComputeDerivedMetrics($row);
    foreach (['lp_ctr', 'cr', 'approve_rate', 'epc', 'epc_confirmed', 'epv', 'cpc', 'cpv',
        'profit', 'profit_confirmed', 'roi', 'roi_confirmed'] as $k) {
        $row[$k] = $m[$k];
    }
    $row['visits'] = $m['clicks'];
    $row['unique_visits'] = $m['unique_clicks'];
    return $row;
};

$rows = $pdo->query(orbitraLandingsWithStatsSql('', 'payout') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$lp = [];
foreach ($rows as $r) { $lp[$r['id']] = $deriveRow($r); }

// Landing 1: 3 visits / 2 unique, 1 LP click, 6 conversion events (3 sale +
// deposit + reg + lead), revenue 45+31+2=78, cost 16.
$assert('L1 clicks', $lp[1]['clicks'], 3, 0);
$assert('L1 visits alias', $lp[1]['visits'], 3, 0);
$assert('L1 unique_clicks', $lp[1]['unique_clicks'], 2, 0);
$assert('L1 unique_visits alias', $lp[1]['unique_visits'], 2, 0);
$assert('L1 lp_clicks', $lp[1]['lp_clicks'], 1, 0);
$assert('L1 lp_ctr', $lp[1]['lp_ctr'], 33.33);
$assert('L1 conversions (events, not the is_conversion flag)', $lp[1]['conversions'], 6, 0);
$assert('L1 sales', $lp[1]['sales'], 3, 0);
$assert('L1 leads', $lp[1]['leads'], 1, 0);
$assert('L1 revenue', $lp[1]['revenue'], 78);
$assert('L1 revenue_confirmed', $lp[1]['revenue_confirmed'], 45);
$assert('L1 cost', $lp[1]['cost'], 16);
$assert('L1 cr', $lp[1]['cr'], 200);
$assert('L1 approve_rate', $lp[1]['approve_rate'], 75);
$assert('L1 epc', $lp[1]['epc'], 26);
$assert('L1 epv (equals epc: one row = one visit)', $lp[1]['epv'], 26);
$assert('L1 epc_confirmed', $lp[1]['epc_confirmed'], 15);
$assert('L1 cpc', $lp[1]['cpc'], 5.3333);
$assert('L1 cpv', $lp[1]['cpv'], 5.3333);
$assert('L1 profit', $lp[1]['profit'], 62);
$assert('L1 profit_confirmed', $lp[1]['profit_confirmed'], 29);
$assert('L1 roi', $lp[1]['roi'], 387.5);
$assert('L1 roi_confirmed', $lp[1]['roi_confirmed'], 181.25);

// Landing 2: 1 visit that clicked through, one rejected conversion.
$assert('L2 clicks', $lp[2]['clicks'], 1, 0);
$assert('L2 lp_ctr', $lp[2]['lp_ctr'], 100);
$assert('L2 approve_rate (1 rejected → 0%)', $lp[2]['approve_rate'], 0);
$assert('L2 profit_confirmed', $lp[2]['profit_confirmed'], -4);
$assert('L2 roi_confirmed', $lp[2]['roi_confirmed'], -100);

// Landing 3: no clicks at all — zero counters, ratios 0, ROI a dash.
$assert('L3 clicks', $lp[3]['clicks'], 0, 0);
$assert('L3 lp_ctr', $lp[3]['lp_ctr'], 0);
$assert('L3 cpv', $lp[3]['cpv'], 0);
$assert('L3 roi null', $lp[3]['roi'], null);

$of = [];
foreach ($pdo->query(orbitraOffersWithStatsSql('', 'payout') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $of[$r['id']] = $deriveRow($r);
}
// Offer 7 got exactly click m1 (through landing 1): LP share 100%, CR 300%.
$assert('O7 clicks', $of[7]['clicks'], 1, 0);
$assert('O7 lp_clicks (arrived via a landing)', $of[7]['lp_clicks'], 1, 0);
$assert('O7 lp_ctr', $of[7]['lp_ctr'], 100);
$assert('O7 conversions', $of[7]['conversions'], 3, 0);
$assert('O7 cr', $of[7]['cr'], 300);
$assert('O7 approve_rate', $of[7]['approve_rate'], 100);
$assert('O7 roi', $of[7]['roi'], 350);
// Offer 9 got a direct click (no landing): lp_clicks 0, its conversion is trash.
$assert('O9 clicks', $of[9]['clicks'], 1, 0);
$assert('O9 lp_clicks', $of[9]['lp_clicks'], 0, 0);
$assert('O9 trash', $of[9]['trash'], 1, 0);

@unlink($tmpDb);
echo "Report metrics tests passed (" . count($expected) . " derived + landing/offer production-SQL checks).\n";
