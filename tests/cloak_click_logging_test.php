<?php

require_once __DIR__ . '/../core/click_api.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)');
$pdo->exec('CREATE TABLE campaigns (
    id INTEGER PRIMARY KEY, token TEXT, source_id INTEGER, rotation_type TEXT,
    uniqueness_hours INTEGER, uniqueness_method TEXT, is_archived INTEGER DEFAULT 0
)');
$pdo->exec('CREATE TABLE streams (
    id INTEGER PRIMARY KEY, campaign_id INTEGER, offer_id INTEGER, is_active INTEGER,
    type TEXT, position INTEGER, filters_json TEXT, filters_logic TEXT,
    schema_type TEXT, schema_custom_json TEXT
)');
$pdo->exec('CREATE TABLE offers (id INTEGER PRIMARY KEY, url TEXT, is_local INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE landings (id INTEGER PRIMARY KEY, type TEXT, url TEXT, action_payload TEXT, action_type TEXT, slug TEXT)');
$pdo->exec('CREATE TABLE bot_ips (id INTEGER PRIMARY KEY, ip_or_cidr TEXT)');
$pdo->exec('CREATE TABLE bot_signatures (id INTEGER PRIMARY KEY, signature TEXT)');
$pdo->exec('CREATE TABLE clicks (
    id TEXT PRIMARY KEY, campaign_id INTEGER, offer_id INTEGER, stream_id INTEGER,
    source_id INTEGER, landing_id INTEGER, ip TEXT, user_agent TEXT, referer TEXT,
    country TEXT, country_code TEXT, region TEXT, city TEXT, latitude REAL,
    longitude REAL, zipcode TEXT, timezone TEXT, device_type TEXT, os TEXT,
    browser TEXT, language TEXT, accept_language_raw TEXT, parameters_json TEXT,
    is_bot INTEGER DEFAULT 0, is_proxy INTEGER DEFAULT 0,
    uniq_campaign INTEGER DEFAULT 0, uniq_stream INTEGER DEFAULT 0,
    uniq_global INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');

$settings = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)');
foreach ([
    'click_api_enabled' => '1',
    'stats_enabled' => '1',
    'ignore_prefetch' => '1',
    'bot_isp_list' => '',
    'postback_key' => 'test-secret',
] as $key => $value) {
    $settings->execute([$key, $value]);
}

$pdo->exec("INSERT INTO campaigns (id, token, rotation_type, uniqueness_hours, uniqueness_method) VALUES (1, 'cloak-token', 'position', 24, 'IP')");
$pdo->exec("INSERT INTO offers (id, url) VALUES (1, 'https://money.example/path')");
// A LeadForge-style direct local offer: is_local=1, no url — the uploaded
// archive is the destination, served by the tracker's /offers/<id>/ route.
$pdo->exec("INSERT INTO offers (id, url, is_local) VALUES (2, NULL, 1)");
$pdo->exec("INSERT INTO offers (id, url, is_local) VALUES (3, 'https://remote.example/offer', 0)");

$streamInsert = $pdo->prepare('INSERT INTO streams (
    id, campaign_id, offer_id, is_active, type, position, filters_json,
    filters_logic, schema_type, schema_custom_json
) VALUES (1, 1, NULL, 1, \'regular\', 1, \'[]\', \'and\', \'cloak\', ?)');
$streamInsert->execute([json_encode([])]);

$runClickApi = function (array $schema, string $userAgent) use ($pdo): array {
    $pdo->prepare('UPDATE streams SET schema_custom_json = ? WHERE id = 1')
        ->execute([json_encode($schema)]);
    $_GET = [
        'token' => 'cloak-token',
        'ip' => '127.0.0.1',
        'info' => '1',
        'log' => '1',
    ];
    $_POST = [];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = $userAgent;
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
    unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_SEC_PURPOSE'], $_SERVER['HTTP_PURPOSE']);

    ob_start();
    orbitraClickApiV3($pdo);
    $response = json_decode((string) ob_get_clean(), true);
    if (!is_array($response)) {
        throw new RuntimeException('Click API returned invalid JSON');
    }
    return $response;
};

$baseSchema = [
    'detect_datacenter' => false,
    'detect_vpn' => false,
    'detect_bots' => false,
    'detect_ua' => true,
    'sensitivity' => 'high',
    'safe_mode' => 'url',
    'safe_url' => 'https://safe.example/review',
    'offers' => [['id' => 1, 'weight' => 100]],
];

$botUa = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
$browserUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/127.0.0.0 Safari/537.36';

$safeResponse = $runClickApi($baseSchema, $botUa);
if ((int) $pdo->query('SELECT COUNT(*) FROM clicks')->fetchColumn() !== 0) {
    throw new RuntimeException('Default Safe Page request was written to clicks');
}
if (($safeResponse['headers'][0] ?? '') !== 'Location: https://safe.example/review') {
    throw new RuntimeException('Safe Page URL was not returned');
}

$htmlSchema = $baseSchema;
$htmlSchema['safe_mode'] = 'html';
$htmlSchema['safe_html'] = '<h1>Review page</h1>';
$htmlResponse = $runClickApi($htmlSchema, $botUa);
if ((int) $pdo->query('SELECT COUNT(*) FROM clicks')->fetchColumn() !== 0) {
    throw new RuntimeException('Inline Safe Page request was written to clicks');
}
if (($htmlResponse['body'] ?? '') !== '<h1>Review page</h1>' || !empty($htmlResponse['headers'])) {
    throw new RuntimeException('Saved Safe Page mode did not override the stale URL field');
}

$moneyResponse = $runClickApi($baseSchema, $browserUa);
if ((int) $pdo->query('SELECT COUNT(*) FROM clicks')->fetchColumn() !== 1) {
    throw new RuntimeException('Money Page request was not recorded');
}
if (($moneyResponse['headers'][0] ?? '') !== 'Location: https://money.example/path') {
    throw new RuntimeException('Money Page URL was not returned');
}

$recordSafeSchema = $baseSchema;
$recordSafeSchema['dont_record_safe_clicks'] = false;
$recordedSafeResponse = $runClickApi($recordSafeSchema, $botUa);
if ((int) $pdo->query('SELECT COUNT(*) FROM clicks')->fetchColumn() !== 2) {
    throw new RuntimeException('Explicitly enabled Safe Page logging did not record the click');
}
if (($recordedSafeResponse['headers'][0] ?? '') !== 'Location: https://safe.example/review') {
    throw new RuntimeException('Recorded Safe Page URL was not returned');
}

// --- Safe Page as a local offer (safe_mode='offer') -------------------------
$offerSafeSchema = $baseSchema;
$offerSafeSchema['safe_mode'] = 'offer';
$offerSafeSchema['safe_offer_id'] = 2;
$offerSafeResponse = $runClickApi($offerSafeSchema, $botUa);
if (($offerSafeResponse['headers'][0] ?? '') !== 'Location: /offers/2/') {
    throw new RuntimeException('Safe Page local offer redirect was not returned');
}
if ((int) $pdo->query('SELECT COUNT(*) FROM clicks')->fetchColumn() !== 2) {
    throw new RuntimeException('Safe Page local offer request was written to clicks');
}

// A non-local offer id must not produce an /offers/ redirect — the default
// fallback page answers instead.
$offerSafeSchema['safe_offer_id'] = 3;
$offerFallbackResponse = $runClickApi($offerSafeSchema, $botUa);
if (!empty($offerFallbackResponse['headers']) || strpos((string) ($offerFallbackResponse['body'] ?? ''), 'Content is loading') === false) {
    throw new RuntimeException('Non-local Safe Page offer did not fall back to the default page');
}

// Money Page: a direct local offer (no url) resolves to its /offers/<id>/
// address instead of an empty destination.
$moneyLocalSchema = $baseSchema;
unset($moneyLocalSchema['safe_mode'], $moneyLocalSchema['safe_url']);
$moneyLocalSchema['offers'] = [['id' => 2, 'weight' => 100]];
$moneyLocalResponse = $runClickApi($moneyLocalSchema, $browserUa);
if (($moneyLocalResponse['headers'][0] ?? '') !== 'Location: /offers/2/') {
    throw new RuntimeException('Money Page direct local offer URL was not returned');
}

// Legacy schema without safe_mode: a saved safe_offer_id alone must select the
// offer mode, the same way a saved safe_landing_id selects the landing mode.
$legacyOfferSchema = $baseSchema;
unset($legacyOfferSchema['safe_mode']);
$legacyOfferSchema['safe_offer_id'] = 2;
$legacyOfferResponse = $runClickApi($legacyOfferSchema, $botUa);
if (($legacyOfferResponse['headers'][0] ?? '') !== 'Location: /offers/2/') {
    throw new RuntimeException('Legacy schema with only safe_offer_id did not resolve to the offer Safe Page');
}

echo "Cloak click logging integration tests passed.\n";
