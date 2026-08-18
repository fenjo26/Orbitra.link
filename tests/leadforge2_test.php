<?php
// tests/leadforge2_test.php
//
// LeadForge 2.0 + CRM Anti-Shaving Vault:
//   - E.164 normalization table (raw → clean, per GEO);
//   - orbitraCrmRecordLead on a throwaway SQLite: vault row, conversion
//     upsert (exactly one per click), QA rows never touch analytics,
//     duplicate detection;
//   - orbitraCrmSyncPostbackStatus: status sync, payout update, and the
//     shave heuristic (rejected + delivered-valid-E164 + network 200 →
//     suspect; anything less → not a suspect);
//   - generators: orderPhp() passes PhpLanding::scan and carries the CRM/QA
//     wiring; stripForeignScripts removes known counters and keeps the form;
//     removeLegacyHandlers cuts the old network's handlers.
//
// Run: php tests/leadforge2_test.php

require_once __DIR__ . '/../core/LeadForge.php';
require_once __DIR__ . '/../core/PhpLanding.php';

$assert = function (string $label, $got, $expected) {
    if ($got !== $expected) {
        echo "FAIL $label: got " . var_export($got, true) . ", expected " . var_export($expected, true) . "\n";
        exit(1);
    }
    echo "ok  $label\n";
};
$assertTrue = function (string $label, $got) {
    if ($got !== true) {
        echo "FAIL $label: got " . var_export($got, true) . "\n";
        exit(1);
    }
    echo "ok  $label\n";
};

// ---------------------------------------------------------------- E.164
$e164Cases = [
    ['+39 333 123 4567', 'IT', '+393331234567'],
    ['3331234567',        'IT', '+393331234567'],
    ['00393331234567',    'IT', '+393331234567'],
    ['(912) 345-67-89',   'RU', '+79123456789'],
    ['380991234567',      'UA', '+380991234567'],
    ['+1 (555) 123-4567', 'US', '+15551234567'],
    ['abc',               'IT', ''],
];
foreach ($e164Cases as [$raw, $geo, $want]) {
    $assert("e164 [$raw @$geo]", orbitraNormalizePhoneE164($raw, $geo), $want);
}

// ------------------------------------------------------- throwaway schema
$tmpDb = sys_get_temp_dir() . '/orbitra_lf2_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$pdo = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec('CREATE TABLE campaigns (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
$pdo->exec('CREATE TABLE clicks (
    id TEXT PRIMARY KEY, campaign_id INTEGER NOT NULL, ip TEXT, user_agent TEXT,
    landing_id INTEGER, parameters_json TEXT DEFAULT "{}",
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE)');
$pdo->exec('CREATE TABLE conversions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, click_id TEXT, tid TEXT, status TEXT,
    payout REAL, currency TEXT, campaign_id INTEGER, ip TEXT,
    created_at DATETIME, updated_at DATETIME, UNIQUE(click_id, tid))');
// Full vault shape as migration 28 creates it.
$pdo->exec('CREATE TABLE crm_leads (
    id INTEGER PRIMARY KEY AUTOINCREMENT, click_id VARCHAR(64) NOT NULL,
    campaign_id INTEGER DEFAULT 0, lander_id INTEGER DEFAULT 0,
    offer_id VARCHAR(64) DEFAULT "", network VARCHAR(32) NOT NULL DEFAULT "custom",
    network_lead_id VARCHAR(128) DEFAULT NULL, product VARCHAR(128) DEFAULT "",
    customer_name VARCHAR(255) DEFAULT "", raw_phone VARCHAR(64) NOT NULL,
    clean_phone VARCHAR(64) NOT NULL, price REAL DEFAULT 0, payout REAL DEFAULT 0,
    currency VARCHAR(3) DEFAULT "USD", geo VARCHAR(8) DEFAULT "", ip VARCHAR(45) DEFAULT "",
    user_agent TEXT DEFAULT "", utm_source VARCHAR(128) DEFAULT "",
    utm_campaign VARCHAR(128) DEFAULT "", utm_placement VARCHAR(128) DEFAULT "",
    adset_id VARCHAR(64) DEFAULT "", adset_name VARCHAR(128) DEFAULT "",
    ad_id VARCHAR(64) DEFAULT "", ad_name VARCHAR(128) DEFAULT "",
    sub_data_json TEXT DEFAULT "{}", network_request_json TEXT DEFAULT "{}",
    network_response_json TEXT DEFAULT "{}", status VARCHAR(32) DEFAULT "lead",
    status_reason TEXT DEFAULT "", status_source VARCHAR(32) DEFAULT "form_submit",
    s2s_postback_status VARCHAR(32) DEFAULT "pending", is_qa_test INTEGER DEFAULT 0,
    is_duplicate INTEGER DEFAULT 0, shave_suspect INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');

$pdo->exec("INSERT INTO campaigns (name) VALUES ('Test Campaign')");
$pdo->exec("INSERT INTO clicks (id, campaign_id, ip) VALUES ('click_ok', 1, '1.2.3.4')");

// ------------------------------------------------------------ record lead
$res = orbitraCrmRecordLead($pdo, [
    'click_id' => 'click_ok', 'network' => 'webvork', 'customer_name' => 'Mario Rossi',
    'raw_phone' => '333 123 4567', 'geo' => 'IT', 'payout' => 25, 'currency' => 'EUR',
    'utm_source' => 'fb_ads', 'utm_campaign' => 'cbo_it', 'adset_id' => 'adset_1',
    'sub_data' => ['sub1' => 'x', 'pixel' => '991'],
    'network_request' => ['endpoint' => 'https://api.webvork.com/v1/lead', 'method' => 'POST'],
    'network_response' => ['http_code' => 200, 'body' => '{"lead_id":"wv_1"}', 'network_lead_id' => 'wv_1'],
], false);
$assertTrue('record ok', $res['ok']);
$row = $pdo->query("SELECT * FROM crm_leads WHERE click_id = 'click_ok'")->fetch(PDO::FETCH_ASSOC);
$assert('vault clean_phone', $row['clean_phone'], '+393331234567');
$assert('vault raw_phone preserved', $row['raw_phone'], '333 123 4567');
$assert('vault campaign from click', $row['campaign_id'], 1);
$assert('vault utm_campaign', $row['utm_campaign'], 'cbo_it');
$assert('vault adset_id', $row['adset_id'], 'adset_1');
$assert('vault network_lead_id', $row['network_lead_id'], 'wv_1');
$assertTrue('conversion created', $res['conversion']);
$assert('exactly one conversion', (int) $pdo->query("SELECT COUNT(*) FROM conversions WHERE click_id = 'click_ok'")->fetchColumn(), 1);

// Same click again → upsert, never a second conversion.
$res2 = orbitraCrmRecordLead($pdo, [
    'click_id' => 'click_ok', 'network' => 'webvork', 'raw_phone' => '3331234567',
    'geo' => 'IT', 'status' => 'lead', 'payout' => 25,
], false);
$assert('still one conversion after resubmit', (int) $pdo->query("SELECT COUNT(*) FROM conversions WHERE click_id = 'click_ok'")->fetchColumn(), 1);
$assert('duplicate flagged on same phone+network', $res2['is_duplicate'], true);

// QA lead: vault row, zero analytics footprint.
$resQa = orbitraCrmRecordLead($pdo, [
    'click_id' => 'qa_test_123', 'network' => 'webvork', 'customer_name' => 'QA-Test-Lead',
    'raw_phone' => '+39 333 000 1122', 'geo' => 'IT', 'is_qa_test' => 1,
], false);
$assertTrue('qa record ok', $resQa['ok']);
$assert('qa no conversion', $resQa['conversion'], false);
$assert('qa rows in vault', (int) $pdo->query("SELECT COUNT(*) FROM crm_leads WHERE is_qa_test = 1")->fetchColumn(), 1);
// QA numbers must not poison duplicate detection.
$res3 = orbitraCrmRecordLead($pdo, [
    'click_id' => 'click_qa2', 'network' => 'webvork', 'raw_phone' => '+39 333 000 1122',
    'geo' => 'IT', 'is_qa_test' => 1,
], false);
$assert('qa not duplicate', $res3['is_duplicate'], false);

// -------------------------------------------------------- postback sync
orbitraCrmSyncPostbackStatus($pdo, 'click_ok', 'sale', 31.5);
$row = $pdo->query("SELECT status, s2s_postback_status, payout FROM crm_leads WHERE click_id = 'click_ok' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assert('sync status sale', $row['status'], 'sale');
$assert('sync payout', (float) $row['payout'], 31.5);

orbitraCrmSyncPostbackStatus($pdo, 'click_ok', 'rejected', 0.0, 'Invalid Phone');
// Two vault rows exist for this click (initial + resubmit); the suspect flag
// lands on the row that carries the network's 200 — the one we can prove.
$row = $pdo->query("SELECT shave_suspect, status_reason, status_source FROM crm_leads
                    WHERE click_id = 'click_ok' AND network_response_json LIKE '%\"http_code\":200%'")->fetch(PDO::FETCH_ASSOC);
$assert('rejected+valid+http200 → suspect', (int) $row['shave_suspect'], 1);
$assert('reason stored', $row['status_reason'], 'Invalid Phone');
$assert('status_source network', $row['status_source'], 'network_postback');

// Rejected but the network NEVER accepted it (http 500) → not a suspect.
$pdo->exec("INSERT INTO clicks (id, campaign_id, ip) VALUES ('click_500', 1, '1.2.3.4')");
orbitraCrmRecordLead($pdo, [
    'click_id' => 'click_500', 'network' => 'webvork', 'raw_phone' => '3331234567', 'geo' => 'IT',
    'network_response' => ['http_code' => 500, 'body' => 'error'],
], false);
orbitraCrmSyncPostbackStatus($pdo, 'click_500', 'rejected', 0.0);
$row = $pdo->query("SELECT shave_suspect FROM crm_leads WHERE click_id = 'click_500'")->fetch(PDO::FETCH_ASSOC);
$assert('http500 reject → not suspect', (int) $row['shave_suspect'], 0);

// Rejected with a broken phone → not a suspect either.
$pdo->exec("INSERT INTO clicks (id, campaign_id, ip) VALUES ('click_bad', 1, '1.2.3.4')");
orbitraCrmRecordLead($pdo, [
    'click_id' => 'click_bad', 'network' => 'webvork', 'raw_phone' => '12', 'geo' => 'IT',
    'network_response' => ['http_code' => 200],
], false);
orbitraCrmSyncPostbackStatus($pdo, 'click_bad', 'rejected', 0.0);
$row = $pdo->query("SELECT shave_suspect FROM crm_leads WHERE click_id = 'click_bad'")->fetch(PDO::FETCH_ASSOC);
$assert('bad phone reject → not suspect', (int) $row['shave_suspect'], 0);

// --------------------------------------------------------- generators
$orderSrc = LeadForge::orderPhp([
    'network' => 'drcash', 'api_key' => "k'ey\"\\x", 'offer_id' => 'abc123',
    'geo' => 'IT', 'payout' => 25, 'currency' => 'EUR', 'crm_enabled' => true,
    'base_url' => 'https://track.example.com', 'landing_name' => "Quo'te Land",
]);
$assertTrue('order.php passes PhpLanding scan', empty(PhpLanding::scan($orderSrc)));
$assertTrue('order.php calls in-process vault', strpos($orderSrc, 'orbitraCrmRecordLead') !== false);
$assertTrue('order.php carries crm-ingest fallback', strpos($orderSrc, '/crm-ingest') !== false);
$assertTrue('order.php carries QA guard', strpos($orderSrc, "strpos(\$subid, 'qa_test_')") !== false);
$assertTrue('order.php api key escaped safely', strpos($orderSrc, var_export("k'ey\"\\x", true)) !== false);
$assertTrue('order.php E.164 normalization', strpos($orderSrc, 'cleanPhone') !== false);

$thankSrc = LeadForge::thankYouPhp('IT', 25, 'EUR', true);
$assertTrue('thank_you passes scan', empty(PhpLanding::scan($thankSrc)));
// The pixel <img> stays in the source inside the if-branch; with CRM sync on
// the guard compiles to `&& false` so it never fires.
$assertTrue('thank_you pixel off with CRM sync', strpos($thankSrc, '&& false') !== false);
$thankSrcPix = LeadForge::thankYouPhp('IT', 25, 'EUR', false);
$assertTrue('thank_you pixel on without CRM sync', strpos($thankSrcPix, '&& true') !== false);

$adapter = LeadForge::adapterJs('IT', true);
$assertTrue('adapter has GEO mask', strpos($adapter, "+39 3## ### ####") !== false);
$assertTrue('adapter reads click cookie', strpos($adapter, "orbitra_click") !== false);
$assertTrue('adapter stores across pages', strpos($adapter, 'orbitra_lf_') !== false);

// --------------------------------------------------- script stripping
$html = '<html><head><script>fbq("init","1");</script></head><body>'
      . '<form action="https://api.webvork.com/v1/lead"><input name="name"><input name="phone"></form>'
      . '<script>console.log("page logic");</script>'
      . '<img src="https://www.facebook.com/tr?id=1&ev=PageView" height="1" width="1">'
      . '</body></html>';
[$stripped, $removed] = LeadForge::stripForeignScripts($html);
$assertTrue('strips fb pixel script', strpos($stripped, 'fbq(') === false);
$assertTrue('strips tracking img', strpos($stripped, 'facebook.com/tr') === false);
$assertTrue('keeps page logic script', strpos($stripped, 'page logic') !== false);
$assertTrue('keeps the form', strpos($stripped, '<form') !== false);
$assertTrue('removed labels reported', in_array('facebook_pixel', $removed, true));

// ------------------------------------------------ legacy handler removal
$dir = sys_get_temp_dir() . '/lf2_legacy_' . uniqid();
@mkdir($dir, 0775, true);
foreach (['order.php', 'send.php', 'index.html'] as $f) {
    file_put_contents("$dir/$f", '<html></html>');
}
$removedFiles = LeadForge::removeLegacyHandlers($dir);
$assert('legacy handlers removed', $removedFiles, ['order.php', 'send.php']);
$assertTrue('index kept', is_file($dir . '/index.html'));
LeadForge::rrmdir($dir);
$assertTrue('rrmdir cleans up', !is_dir($dir));

// --------------------------------------------------------- analyze dir
$dir = sys_get_temp_dir() . '/lf2_analyze_' . uniqid();
@mkdir($dir, 0775, true);
file_put_contents($dir . '/index.html',
    '<html lang="it"><head><script src="https://api.webvork.com/v1/lead.js"></script></head>'
    . '<body><form action="/send.php"><input name="name"><input name="phone"></form>'
    . '<a href="#order">Order</a></body></html>');
$card = LeadForge::analyzeDir($dir);
$assert('analyze network webvork', $card['network'], 'webvork');
$assert('analyze forms', $card['forms_count'], 1);
$assert('analyze geo from lang', $card['detected_geo'], 'IT');
$assert('analyze inputs', in_array('phone', $card['detected_inputs'], true), true);
$assert('analyze cta', $card['cta_links_count'] >= 1, true);
LeadForge::rrmdir($dir);

// ------------------------------------------- lead validation (phone lock)
$assertTrue('order.php national length lock', strpos($orderSrc, 'lfNational') !== false);
$assertTrue('order.php name letter check', strpos($orderSrc, 'valid customer name') !== false);
$assertTrue('order.php phone markers all replaced', strpos($orderSrc, '@@PHONE') === false);
$assertTrue('order.php IT range message', strpos($orderSrc, "9 . '-' . 11") !== false);
$orderIn = LeadForge::orderPhp([
    'network' => 'drcash', 'api_key' => 'k', 'offer_id' => 'abc',
    'geo' => 'IN', 'payout' => 25, 'currency' => 'INR', 'crm_enabled' => true,
    'base_url' => 'https://track.example.com', 'landing_name' => 'IN Land',
]);
$assertTrue('order.php IN exact-10 message', strpos($orderIn, "'exactly ' . 10") !== false);
$assertTrue('order.php IN passes scan', empty(PhpLanding::scan($orderIn)));

$assertTrue('adapter name validation', strpos($adapter, 'orbitra-name-invalid') !== false);
$assertTrue('adapter national digit count', strpos($adapter, 'nationalDigits') !== false);
$assertTrue('adapter phone input lock', strpos($adapter, ': PHONE_MAX;') !== false);

// --------------------------------------------------- GEO language aliases
$dir = sys_get_temp_dir() . '/lf2_geo_' . uniqid();
@mkdir($dir, 0775, true);
file_put_contents($dir . '/index.html',
    '<html lang="hi"><head></head><body>नमस्ते ऑर्डर करें'
    . '<form action="/send.php"><input name="name"><input name="phone"></form></body></html>');
$card = LeadForge::analyzeDir($dir);
$assert('analyze hindi lang maps to IN', $card['detected_geo'], 'IN');
LeadForge::rrmdir($dir);

$dir = sys_get_temp_dir() . '/lf2_geo2_' . uniqid();
@mkdir($dir, 0775, true);
file_put_contents($dir . '/index.html',
    '<html><head></head><body>नमस्ते कृपया ऑर्डर करें'
    . '<form action="/send.php"><input name="name"><input name="phone"></form></body></html>');
$card = LeadForge::analyzeDir($dir);
$assert('analyze devanagari script votes IN', $card['detected_geo'], 'IN');
LeadForge::rrmdir($dir);

@unlink($tmpDb);
echo "ALL LEADFORGE2 TESTS PASSED\n";
