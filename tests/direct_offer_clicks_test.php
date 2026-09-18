<?php
// tests/direct_offer_clicks_test.php
//
// A hop to a stream's "Direct URL" (no catalog offer, so no offer_id) is an
// offer click (migration 53, clicks.direct_offer). Before, Clicks read 0 for
// every direct-URL campaign while Visitors counted all the traffic.
//
// Run: php tests/direct_offer_clicks_test.php

require_once __DIR__ . '/../core/ReportMetrics.php';
require_once __DIR__ . '/../core/click_logger.php';

$failures = 0;
$assert = function (string $label, $got, $expected) use (&$failures) {
    $ok = $got == $expected;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label;
    if (!$ok) {
        echo ' — got ' . var_export($got, true) . ', expected ' . var_export($expected, true);
        $failures++;
    }
    echo "\n";
};

echo "click row\n";
$row = orbitraBuildClickRow(['click_id' => 'x', 'campaign_id' => 1, 'offer_id' => 0, 'direct_offer' => true]);
$assert('direct hop flagged', $row['direct_offer'] ?? null, 1);
$row = orbitraBuildClickRow(['click_id' => 'y', 'campaign_id' => 1, 'offer_id' => 0]);
$assert('plain hit carries no key (older schemas keep inserting)', array_key_exists('direct_offer', $row), false);
$row = orbitraBuildClickRow(['click_id' => 'z', 'campaign_id' => 1, 'offer_id' => 5, 'direct_offer' => true]);
$assert('catalog offer wins, no flag', array_key_exists('direct_offer', $row), false);

echo "dashboard metrics\n";
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE conversions (id INTEGER PRIMARY KEY, click_id TEXT, tid TEXT, status TEXT, payout REAL)');
$pdo->exec('CREATE TABLE pwa_screen_views (id INTEGER PRIMARY KEY, click_id TEXT, landing_id INTEGER, screen TEXT)');
$pdo->exec('CREATE TABLE clicks (id TEXT PRIMARY KEY, campaign_id INTEGER, offer_id INTEGER,
    landing_id INTEGER, ip TEXT, cost REAL DEFAULT 0, is_conversion INTEGER DEFAULT 0,
    revenue REAL DEFAULT 0, is_bot INTEGER DEFAULT 0, is_proxy INTEGER DEFAULT 0,
    referer TEXT, created_at TEXT DEFAULT "2026-01-01 10:00:00",
    uniq_campaign INTEGER DEFAULT 1, uniq_stream INTEGER DEFAULT 1, uniq_global INTEGER DEFAULT 1,
    landing_at TEXT, offer_at TEXT, lp_seconds INTEGER, lp_scroll INTEGER,
    pwa_intent_at TEXT, pwa_install_at TEXT, pwa_open_at TEXT, pwa_open_count INTEGER DEFAULT 0,
    push_prompted_at TEXT, push_subscribed_at TEXT, push_declined_at TEXT,
    pwa_entry_screen TEXT, pwa_last_screen TEXT, direct_offer INTEGER DEFAULT 0)');
// d1..d3: Direct URL hops. a1: action stream ("show text", the dno fallback).
// o1: catalog offer. s1: cloak safe page.
foreach ([['d1', null, 1, 'A'], ['d2', null, 1, 'B'], ['d3', null, 1, 'B'],
          ['a1', null, 0, 'C'], ['o1', 7, 0, 'D'], ['s1', null, 0, 'E']] as [$id, $offer, $direct, $ip]) {
    $pdo->prepare('INSERT INTO clicks (id, campaign_id, offer_id, ip, direct_offer) VALUES (?, 1, ?, ?, ?)')
        ->execute([$id, $offer, $ip, $direct]);
}
$pdo->exec("INSERT INTO conversions (click_id, status, payout) VALUES ('d2', 'sale', 10)");
$raw = $pdo->query(orbitraDashboardMetricsSql('payout', null))->fetch(PDO::FETCH_ASSOC);
$m = orbitraComputeDerivedMetrics($raw ?: []);
$assert('clicks = 3 direct + 1 catalog', $m['clicks'], 4);
$assert('visitors = every hit', $m['visitors'], 6);
$assert('CR over clicks', round($m['cr'], 2), 25.0);

echo "migration 53 backfill\n";
$pdo->exec('CREATE TABLE streams (id INTEGER PRIMARY KEY, schema_type TEXT, schema_custom_json TEXT)');
$pdo->exec("INSERT INTO streams VALUES (1, 'redirect', '{\"direct_url\":\"https://x.example/?s={subid}\"}'),
    (2, 'action', '{}'), (3, 'redirect', '{\"direct_url\":\"  \"}')");
$pdo->exec('ALTER TABLE clicks ADD COLUMN stream_id INTEGER');
$pdo->exec('ALTER TABLE clicks ADD COLUMN is_safe_page INTEGER DEFAULT 0');
$pdo->exec("UPDATE clicks SET direct_offer = 0");
$pdo->exec("UPDATE clicks SET stream_id = 1 WHERE id IN ('d1','d2','d3','o1')");
$pdo->exec("UPDATE clicks SET stream_id = 2 WHERE id = 'a1'");
$pdo->exec("UPDATE clicks SET stream_id = 3, is_safe_page = 0 WHERE id = 's1'");
// Same statement as config.php migration 53.
$config = file_get_contents(__DIR__ . '/../config.php');
preg_match('/\$pdo->exec\("(UPDATE clicks SET direct_offer = 1.*?)"\);/s', $config, $mm);
$assert('backfill SQL found in config.php', isset($mm[1]), true);
$pdo->exec(str_replace('\\$', '$', $mm[1] ?? 'SELECT 1'));
$flagged = $pdo->query("SELECT id FROM clicks WHERE direct_offer = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$assert('only direct-URL stream hits without an offer', implode(',', $flagged), 'd1,d2,d3');

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
