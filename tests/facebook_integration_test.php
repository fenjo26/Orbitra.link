<?php
/**
 * tests/facebook_integration_test.php
 *
 * Covers the Facebook cost-import and Conversions API path end to end against a
 * throwaway SQLite database. Run from the project root:
 *
 *     php tests/facebook_integration_test.php
 *
 * No network is touched: the ad-platform records are hand-built in the shape
 * FacebookAdsEngine::fetchRecords() returns, and CAPI delivery is asserted at the
 * queue row rather than at Meta.
 */

require_once __DIR__ . '/../core/ClickParams.php';
require_once __DIR__ . '/../core/CurrencyRates.php';
require_once __DIR__ . '/../core/CostImporter.php';
require_once __DIR__ . '/../core/FacebookConversions.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

// ---- Fixture ---------------------------------------------------------------

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
$pdo->exec("CREATE TABLE traffic_sources (id INTEGER PRIMARY KEY, name TEXT, parameters_json TEXT)");
$pdo->exec("CREATE TABLE clicks (
    id TEXT PRIMARY KEY, campaign_id INTEGER, ip TEXT, user_agent TEXT, referer TEXT,
    country_code TEXT, region TEXT, city TEXT, zipcode TEXT,
    cost REAL DEFAULT 0, revenue REAL DEFAULT 0,
    parameters_json TEXT, created_at DATETIME
)");
$pdo->exec("CREATE TABLE cost_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER, external_id TEXT,
    source_campaign_id TEXT, ad_id TEXT, adset_id TEXT, amount REAL, currency TEXT,
    click_date DATE, raw_json TEXT, is_matched INTEGER DEFAULT 0
)");
$pdo->exec("CREATE TABLE s2s_postbacks_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, conversion_id INTEGER, url TEXT, method TEXT,
    status TEXT, attempts INTEGER, next_retry_at DATETIME, postback_id INTEGER,
    payload_json TEXT, content_type TEXT, proxy_url TEXT, updated_at DATETIME
)");

$pdo->exec("INSERT INTO settings (key, value) VALUES ('currency', 'USD')");
// Pin FX so the assertions do not depend on a live rate feed.
$pdo->exec("INSERT INTO settings (key, value) VALUES ('fx_rates_manual_json', '" . json_encode(['EUR' => 0.5]) . "')");
$pdo->exec("INSERT INTO settings (key, value) VALUES ('fx_rates_json', '" . json_encode(['USD' => 1.0]) . "')");
$pdo->exec("INSERT INTO settings (key, value) VALUES ('fx_rates_updated_at', '" . time() . "')");

$pdo->exec("INSERT INTO traffic_sources (id, name, parameters_json) VALUES (1, 'Facebook Ads', '" .
    json_encode([
        ['alias' => 'ad_id', 'param' => 'ad_id', 'macro' => '{{ad.id}}'],
        ['alias' => 'adset_id', 'param' => 'adset_id', 'macro' => '{{adset.id}}'],
        ['alias' => 'placement', 'param' => 'plc', 'macro' => '{{placement}}'],
    ]) . "')");

$today = date('Y-m-d');
$insertClick = $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, referer, country_code, region, city, zipcode, parameters_json, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");

$insertClick->execute(['click-ad-1', 1, '203.0.113.10', 'Mozilla/5.0', 'https://lp.example/a', 'de', 'Berlin', 'Berlin', '10115',
    json_encode(['ad_id' => '111', 'adset_id' => '222', 'campaign_id' => '333', 'fbclid' => 'IwAR_test', 'fbp' => 'fb.1.1700000000.999']), $today . ' 10:00:00']);
$insertClick->execute(['click-ad-2', 1, '203.0.113.11', 'Mozilla/5.0', '', 'de', '', '', '',
    json_encode(['ad_id' => '111', 'adset_id' => '222', 'campaign_id' => '333']), $today . ' 11:00:00']);
// Adset-level only: the ad id never reached the tracker (creative rotated).
$insertClick->execute(['click-adset', 1, '203.0.113.12', 'Mozilla/5.0', '', 'de', '', '', '',
    json_encode(['adset_id' => '888', 'campaign_id' => '333']), $today . ' 12:00:00']);
// Campaign-level only.
$insertClick->execute(['click-camp', 1, '203.0.113.13', 'Mozilla/5.0', '', 'de', '', '', '',
    json_encode(['campaign_id' => '444']), $today . ' 13:00:00']);
// Traffic passed through an app that repacked the adset id into sub_id_3.
$insertClick->execute(['click-app', 1, '203.0.113.14', 'Mozilla/5.0', '', 'de', '', '', '',
    json_encode(['sub_id_3' => '999', 'campaign_id' => '555']), $today . ' 14:00:00']);

// ---- 1. Click parameter capture -------------------------------------------

echo "\nClickParams\n";

$params = orbitraCollectClickParams($pdo, [
    'ad_id' => '111', 'adset_id' => '222', 'campaign_id' => '333',
    'fbclid' => 'IwAR_abc', 'sub_id_1' => 'aff-1', 'plc' => 'feed',
    'unknown_junk' => 'x',
], ['_fbp' => 'fb.1.1700000000.42'], 1);

check('captures ad_id', ($params['ad_id'] ?? null) === '111');
check('captures adset_id — the key Keitaro requires for cost import', ($params['adset_id'] ?? null) === '222');
check('captures campaign_id', ($params['campaign_id'] ?? null) === '333');
check('captures fbclid', ($params['fbclid'] ?? null) === 'IwAR_abc');
check('captures sub_id_1', ($params['sub_id_1'] ?? null) === 'aff-1');
check('maps source-declared param plc -> placement', ($params['placement'] ?? null) === 'feed');
check('reads _fbp cookie', ($params['fbp'] ?? null) === 'fb.1.1700000000.42');
check('derives fbc from fbclid', isset($params['fbc']) && str_ends_with($params['fbc'], '.IwAR_abc'));
check('ignores undeclared parameters', !isset($params['unknown_junk']));

// ---- 2. Currency conversion ------------------------------------------------

echo "\nCurrencyRates\n";

check('tracker currency read from settings', CurrencyRates::trackerCurrency($pdo) === 'USD');
check('EUR->USD uses the pinned rate', abs(CurrencyRates::convert($pdo, 10.0, 'EUR', 'USD') - 20.0) < 0.0001,
    'got ' . CurrencyRates::convert($pdo, 10.0, 'EUR', 'USD'));
check('same-currency conversion is a no-op', CurrencyRates::convert($pdo, 10.0, 'USD', 'USD') === 10.0);
check('unknown currency returns the amount unchanged, not zero', CurrencyRates::convert($pdo, 10.0, 'XYZ', 'USD') === 10.0);

// ---- 3. Cost import --------------------------------------------------------

echo "\nCostImporter\n";

$records = [
    ['external_id' => 'fb_333_111_' . $today, 'source_campaign_id' => '333', 'ad_id' => '111', 'adset_id' => '222',
     'amount' => 10.0, 'currency' => 'USD', 'date' => $today, 'raw_json' => '{"spend":"10"}'],
    ['external_id' => 'fb_333_777_' . $today, 'source_campaign_id' => '333', 'ad_id' => '777', 'adset_id' => '888',
     'amount' => 4.0, 'currency' => 'USD', 'date' => $today, 'raw_json' => '{"spend":"4"}'],
    ['external_id' => 'fb_444_666_' . $today, 'source_campaign_id' => '444', 'ad_id' => '666', 'adset_id' => '555',
     'amount' => 7.0, 'currency' => 'USD', 'date' => $today, 'raw_json' => '{"spend":"7"}'],
];

$stats = CostImporter::import($pdo, 1, $records);

$cost = function (string $id) use ($pdo) {
    $stmt = $pdo->prepare("SELECT cost FROM clicks WHERE id = ?");
    $stmt->execute([$id]);
    return (float) $stmt->fetchColumn();
};

check('all records ingested', $stats['fetched'] === 3 && $stats['new'] === 3, json_encode($stats));
check('ad-level spend splits across the ad\'s clicks', abs($cost('click-ad-1') - 5.0) < 0.001 && abs($cost('click-ad-2') - 5.0) < 0.001,
    $cost('click-ad-1') . ' / ' . $cost('click-ad-2'));
check('adset-level fallback attaches spend (was dropped before)', abs($cost('click-adset') - 4.0) < 0.001, 'got ' . $cost('click-adset'));
check('campaign-level fallback still works', abs($cost('click-camp') - 7.0) < 0.001, 'got ' . $cost('click-camp'));
check('every record matched', $stats['matched'] === 3 && $stats['unmatched'] === 0, json_encode($stats));

// Re-sync of the same day with a higher running total must not double-count.
$records[0]['amount'] = 16.0;
$stats2 = CostImporter::import($pdo, 1, $records);
check('re-sync updates instead of inserting', $stats2['new'] === 0 && $stats2['updated'] === 1, json_encode($stats2));
check('growing daily total is assigned, not accumulated', abs($cost('click-ad-1') - 8.0) < 0.001, 'got ' . $cost('click-ad-1'));

$rowCount = (int) $pdo->query("SELECT COUNT(*) FROM cost_records")->fetchColumn();
check('no duplicate cost rows after re-sync', $rowCount === 3, "rows=$rowCount");

// Currency conversion through the importer.
$eurRecord = [['external_id' => 'fb_eur_' . $today, 'source_campaign_id' => '333', 'ad_id' => '111', 'adset_id' => '222',
    'amount' => 10.0, 'currency' => 'EUR', 'date' => $today, 'raw_json' => '{"spend":"10"}']];
$statsEur = CostImporter::import($pdo, 2, $eurRecord);
$stored = $pdo->query("SELECT amount, currency, raw_json FROM cost_records WHERE connection_id = 2")->fetch();
check('EUR spend is stored converted to tracker currency', abs((float) $stored['amount'] - 20.0) < 0.001 && $stored['currency'] === 'USD',
    json_encode($stored));
check('original amount kept for audit', str_contains((string) $stored['raw_json'], '"source_currency":"EUR"'));
check('conversion counted in stats', $statsEur['converted'] === 1, json_encode($statsEur));

// field_mapping override: adset id lives in sub_id_3 for app traffic.
$appRecord = [['external_id' => 'fb_app_' . $today, 'source_campaign_id' => '555', 'ad_id' => '', 'adset_id' => '999',
    'amount' => 3.0, 'currency' => 'USD', 'date' => $today, 'raw_json' => '{}']];
CostImporter::import($pdo, 3, $appRecord, ['adset_id_param' => 'sub_id_3']);
check('field_mapping routes adset id to a sub_id parameter', abs($cost('click-app') - 3.0) < 0.001, 'got ' . $cost('click-app'));

// A record nothing can be attached to must be recorded as unmatched, not lost.
$orphan = [['external_id' => 'fb_orphan_' . $today, 'source_campaign_id' => 'nope', 'ad_id' => 'nope', 'adset_id' => 'nope',
    'amount' => 5.0, 'currency' => 'USD', 'date' => $today, 'raw_json' => '{}']];
$statsOrphan = CostImporter::import($pdo, 4, $orphan);
check('unattributable spend is stored and flagged unmatched', $statsOrphan['unmatched'] === 1 && $statsOrphan['new'] === 1,
    json_encode($statsOrphan));

// ---- 4. Conversions API ----------------------------------------------------

echo "\nFacebookConversions\n";

$pixel = [
    'pixel_id' => '1234567890',
    'token'    => 'EAAtest',
    'mapping_json' => json_encode(['sale' => 'Purchase', 'lead' => 'Lead', 'rejected' => '']),
    'test_event_code' => 'TEST123',
];

check('sale maps to Purchase', FacebookConversions::resolveEvent($pixel, 'sale') === 'Purchase');
check('rejected is suppressed', FacebookConversions::resolveEvent($pixel, 'rejected') === null);
check('unmapped status falls back to the default table',
    FacebookConversions::resolveEvent(['pixel_id' => '1', 'token' => 't'], 'registration') === 'CompleteRegistration');
check('unknown status sends nothing', FacebookConversions::resolveEvent($pixel, 'weird_status') === null);

$clickRow = $pdo->query("SELECT * FROM clicks WHERE id = 'click-ad-1'")->fetch();
$payload = FacebookConversions::buildPayload($pixel, $clickRow, [
    'event_name'   => 'Purchase',
    'event_time'   => 1700000000,
    'event_id'     => 'click-ad-1_sale',
    'payout'       => 42.5,
    'currency'     => 'USD',
    'click_params' => json_decode($clickRow['parameters_json'], true),
    'extra'        => ['em' => ' John.Doe@Example.COM ', 'ph' => '+1 (234) 567-8900'],
]);

$event = $payload['data'][0];
check('event name and time set', $event['event_name'] === 'Purchase' && $event['event_time'] === 1700000000);
check('action_source is website', $event['action_source'] === 'website');
check('event_id present for pixel deduplication', $event['event_id'] === 'click-ad-1_sale');
check('IP and user agent sent unhashed', $event['user_data']['client_ip_address'] === '203.0.113.10'
    && $event['user_data']['client_user_agent'] === 'Mozilla/5.0');
check('fbp passed through', $event['user_data']['fbp'] === 'fb.1.1700000000.999');
check('fbc derived from fbclid', str_ends_with($event['user_data']['fbc'], '.IwAR_test'));
check('email hashed after normalisation', $event['user_data']['em'][0] === hash('sha256', 'john.doe@example.com'),
    $event['user_data']['em'][0] ?? 'missing');
check('phone hashed digits-only', $event['user_data']['ph'][0] === hash('sha256', '12345678900'));
check('city/country taken from the click', isset($event['user_data']['ct'], $event['user_data']['country']));
check('country hashed as 2-letter code', $event['user_data']['country'][0] === hash('sha256', 'de'));
check('payout becomes custom_data value', abs($event['custom_data']['value'] - 42.5) < 0.001 && $event['custom_data']['currency'] === 'USD');
check('test_event_code forwarded', ($payload['test_event_code'] ?? null) === 'TEST123');
check('no raw PII left in the payload', !str_contains(json_encode($payload), 'john.doe@example.com'));

$queued = FacebookConversions::enqueue($pdo, $pixel, $clickRow, [
    'status' => 'sale', 'payout' => 42.5, 'currency' => 'USD',
    'event_id' => 'click-ad-1_sale',
    'click_params' => json_decode($clickRow['parameters_json'], true),
    'extra' => [],
], 99);

check('enqueue returns true for a mapped status', $queued === true);

$row = $pdo->query("SELECT * FROM s2s_postbacks_log ORDER BY id DESC LIMIT 1")->fetch();
check('queued as a pending POST', $row['status'] === 'pending' && $row['method'] === 'POST');
check('queued with a JSON body', $row['content_type'] === 'application/json' && str_contains($row['payload_json'], '"Purchase"'));
check('queued against the graph endpoint', str_contains($row['url'], '/1234567890/events'));
check('conversion_id linked for the S2S log UI', (int) $row['conversion_id'] === 99);

check('browser-only pixel (no token) is not queued',
    FacebookConversions::enqueue($pdo, ['pixel_id' => '1', 'token' => ''], $clickRow, ['status' => 'sale'], null) === false);
check('suppressed status is not queued',
    FacebookConversions::enqueue($pdo, $pixel, $clickRow, ['status' => 'rejected'], null) === false);

// ---- Summary ---------------------------------------------------------------

echo "\n" . str_repeat('-', 60) . "\n";
echo "passed: $passed   failed: $failed\n";
exit($failed === 0 ? 0 : 1);
