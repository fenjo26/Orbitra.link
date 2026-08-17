<?php
// tests/offers_stats_test.php
//
// End-to-end check for the offers table stats: seeds a reference dataset
// (offers, clicks with costs, conversions in every status group) into a
// throwaway SQLite file, runs the EXACT production query
// (orbitraOffersWithStatsSql + orbitraComputeDerivedMetrics) and asserts every
// per-offer number against hand-computed expectations.
//
// Run: php tests/offers_stats_test.php

require_once __DIR__ . '/../core/ReportMetrics.php';

$tmpDb = sys_get_temp_dir() . '/orbitra_offers_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$pdo = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec('CREATE TABLE offers (id INTEGER PRIMARY KEY, name TEXT, group_id INTEGER, affiliate_network_id INTEGER, url TEXT, redirect_type TEXT, is_local INTEGER, geo TEXT, payout_type TEXT, payout_value REAL, payout_auto INTEGER, allow_rebills INTEGER, capping_limit INTEGER, capping_timezone TEXT, alt_offer_id INTEGER, notes TEXT, state TEXT, created_at TEXT, is_archived INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE offer_groups (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE affiliate_networks (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE clicks (id TEXT PRIMARY KEY, campaign_id INTEGER, offer_id INTEGER, stream_id INTEGER, source_id INTEGER, landing_id INTEGER, ip TEXT, user_agent TEXT, referer TEXT, country TEXT, country_code TEXT, region TEXT, city TEXT, latitude REAL, longitude REAL, zipcode TEXT, timezone TEXT, device_type TEXT, os TEXT, browser TEXT, language TEXT, accept_language_raw TEXT, is_conversion INTEGER DEFAULT 0, revenue REAL DEFAULT 0.00, cost REAL DEFAULT 0.00, parameters_json TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE conversions (id INTEGER PRIMARY KEY, click_id TEXT, tid TEXT, status TEXT, payout REAL)');

// Offer 1 (Crypto, network "NetA"): 3 clicks on 2 unique IPs, cost 0.5+0.7+0.3 = 1.5.
// Conversions across every status group:
//   c1: sale 20            → sale group
//   c2: confirmed 10       → sale group
//   c2: lead 2             → hold group  (= leads)
//   c3: rejected 5         → rejected
//   c3: trash 0.5          → trash
//   c3: deposit 30         → deposit (own column, NOT sale — see status groups)
// Hand-computed per ReportMetrics semantics:
//   clicks=3, unique_clicks=2, conversions=6 (events, not the is_conversion flag)
//   sales=2, leads=1, rejected=1, trash=1
//   revenue=67.5, revenue_confirmed=30, cost=1.5
//   cr=6/3*100=200 (multi-conversion clicks may exceed 100% — same as reports)
//   epc_confirmed=30/3=10, cpc=cpv=1.5/3=0.5
//   profit_confirmed=30-1.5=28.5, roi_confirmed=28.5/1.5*100=1900
// Offer 2 (no traffic): everything zero, ratios 0 / roi null — LEFT JOIN must
// not fabricate rows or divide by zero.
$pdo->exec("INSERT INTO offers (id, name, group_id, affiliate_network_id, state) VALUES (1, 'Offer A', 1, 1, 'active'), (2, 'Offer B', NULL, NULL, 'active')");
$pdo->exec("INSERT INTO offer_groups (id, name) VALUES (1, 'Crypto')");
$pdo->exec("INSERT INTO affiliate_networks (id, name) VALUES (1, 'NetA')");

$ins = $pdo->prepare('INSERT INTO clicks (id, campaign_id, offer_id, ip, cost) VALUES (?,?,?,?,?)');
$ins->execute(['c1', 1, 1, '1.1.1.1', 0.5]);
$ins->execute(['c2', 1, 1, '2.2.2.2', 0.7]);
$ins->execute(['c3', 1, 1, '1.1.1.1', 0.3]);

$cv = $pdo->prepare('INSERT INTO conversions (click_id, tid, status, payout) VALUES (?,?,?,?)');
$cv->execute(['c1', 'a1', 'sale', 20]);
$cv->execute(['c2', 'a2', 'confirmed', 10]);
$cv->execute(['c2', 'a3', 'lead', 2]);
$cv->execute(['c3', 'a4', 'rejected', 5]);
$cv->execute(['c3', 'a5', 'trash', 0.5]);
$cv->execute(['c3', 'a6', 'deposit', 30]);

$assert = function (string $label, $got, $expected, float $tol = 0.01) {
    $ok = is_numeric($got) && is_numeric($expected)
        ? abs((float) $got - (float) $expected) <= $tol
        : $got === $expected;
    if (!$ok) {
        echo "FAIL $label: got " . var_export($got, true) . ", expected " . var_export($expected, true) . "\n";
        exit(1);
    }
    echo "ok  $label = " . var_export($got, true) . "\n";
};

$rows = $pdo->query(orbitraOffersWithStatsSql('', 'payout') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$assert('row count', count($rows), 2);

$a = $rows[0];
$assert('A group_name', $a['group_name'], 'Crypto');
$assert('A affiliate_network_name', $a['affiliate_network_name'], 'NetA');
$assert('A clicks', $a['clicks'], 3);
$assert('A unique_clicks', $a['unique_clicks'], 2);
$assert('A conversions (events)', $a['conversions'], 6);
$assert('A sales', $a['sales'], 2);
$assert('A leads', $a['leads'], 1);
$assert('A rejected', $a['rejected'], 1);
$assert('A trash', $a['trash'], 1);
$assert('A revenue', $a['revenue'], 67.5);
$assert('A revenue_confirmed', $a['revenue_confirmed'], 30);
$assert('A cost', $a['cost'], 1.5);

$m = orbitraComputeDerivedMetrics($a);
$assert('A cr', $m['cr'], 200);
$assert('A epc_confirmed', $m['epc_confirmed'], 10);
$assert('A cpc', $m['cpc'], 0.5);
$assert('A cpv', $m['cpv'], 0.5);
$assert('A profit_confirmed', $m['profit_confirmed'], 28.5);
$assert('A roi_confirmed', $m['roi_confirmed'], 1900);

$b = $rows[1];
$assert('B clicks', $b['clicks'], 0);
$assert('B conversions', $b['conversions'], 0);
$assert('B revenue', $b['revenue'], 0);
$assert('B revenue_confirmed', $b['revenue_confirmed'], 0);
$assert('B cost', $b['cost'], 0);
$mB = orbitraComputeDerivedMetrics($b);
$assert('B cr', $mB['cr'], 0);
$assert('B epc_confirmed', $mB['epc_confirmed'], 0);
$assert('B cpc', $mB['cpc'], 0);
$assert('B cpv', $mB['cpv'], 0);
$assert('B profit_confirmed', $mB['profit_confirmed'], 0);
$assert('B roi_confirmed (null = no cost)', $mB['roi_confirmed'], null);

@unlink($tmpDb);
echo "offers_stats_test: all assertions passed\n";
