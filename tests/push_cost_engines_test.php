<?php
/**
 * tests/push_cost_engines_test.php
 *
 * Push/pop spend import: the AdMaven / HilltopAds / PropellerAds engines
 * (HTTP layer faked, request shapes asserted against the verified API forms)
 * and CostImporter's zone attribution — zone × campaign compound matching,
 * the zone-less campaign fallback, cross-network isolation and the upsert.
 *
 * Run from the project root:
 *
 *     php tests/push_cost_engines_test.php
 *
 * No network: engines route through their $testHttpHandler.
 */

require_once __DIR__ . '/../aggregator_engines/AdMavenEngine.php';
require_once __DIR__ . '/../aggregator_engines/HilltopAdsEngine.php';
require_once __DIR__ . '/../aggregator_engines/PropellerAdsEngine.php';
require_once __DIR__ . '/../core/ClickParams.php';
require_once __DIR__ . '/../core/CurrencyRates.php';
require_once __DIR__ . '/../core/CostImporter.php';

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

/** Route fake HTTP by path; collects calls into $httpCalls. */
$httpCalls = [];
function fakeHttp(string $engineClass, array $routes): void
{
    global $httpCalls;
    $httpCalls = [];
    $prop = null;
    foreach (['AdMavenEngine', 'HilltopAdsEngine', 'PropellerAdsEngine'] as $cls) {
        if ($cls === $engineClass) {
            $prop = $cls;
        }
    }
    $prop::$testHttpHandler = function (string $method, string $path, array $query, array $credentials) use ($routes, &$prop) {
        global $httpCalls;
        $httpCalls[] = ['method' => $method, 'path' => $path, 'query' => $query];
        foreach ($routes as $prefix => $body) {
            if (strpos($path, $prefix) === 0) {
                if ($body instanceof \Closure) {
                    $body = $body($query);
                }
                if (is_array($body) && array_key_exists('error', $body)) {
                    return $body;
                }
                return ['body' => json_encode($body), 'error' => null];
            }
        }
        return ['body' => '{}', 'error' => null];
    };
}

function clearHttp(): void
{
    AdMavenEngine::$testHttpHandler = null;
    HilltopAdsEngine::$testHttpHandler = null;
    PropellerAdsEngine::$testHttpHandler = null;
}

$today = date('Y-m-d');
$tomorrow = gmdate('Y-m-d', strtotime($today . ' UTC') + 86400);

// ---- 1. AdMaven engine -------------------------------------------------------

echo "\nAdMavenEngine\n";

fakeHttp('AdMavenEngine', [
    '/api/public/campaign' => ['type' => 'fetched', 'message' => [
        ['id' => 829137, 'name' => 'Push ID 1'],
        ['id' => 829138, 'name' => 'Push ID 2'],
    ]],
    '/api/public/reports' => ['type' => 'fetched', 'message' => ['rows' => [
        ['source_id' => 1251896, 'cost' => '1.5', 'impressions' => 100, 'clicks' => 10],
        ['source_id' => 1481771, 'cost' => 0.0],          // zero spend is skipped
        ['source_id' => 1254337, 'revenue' => '0.75'],    // fallback key when cost is absent
        ['cost' => '9.99'],                                // no zone — skipped
    ]]],
]);

$adrecords = AdMavenEngine::fetchRecords(['api_token' => 'T'], $today, $today);
check('per-zone rows parsed, zero/no-zone rows dropped', count($adrecords) === 4, 'got ' . count($adrecords));
check('external_id = am_campaign_zone_day', $adrecords[0]['external_id'] === 'am_829137_1251896_' . $today, $adrecords[0]['external_id'] ?? '-');
check('zone_id carried on the record', ($adrecords[0]['zone_id'] ?? '') === '1251896');
check('campaign name carried for ad_entities', ($adrecords[0]['campaign_name'] ?? '') === 'Push ID 1');
check('cost parsed as float', abs((float) $adrecords[0]['amount'] - 1.5) < 0.0001);
check('revenue fallback key read', abs((float) $adrecords[1]['amount'] - 0.75) < 0.0001);
check('zone row keeps ad/adset empty (must not hit ad level)', ($adrecords[0]['ad_id'] ?? 'x') === '');
$reportsCall = null;
foreach ($httpCalls as $c) {
    if ($c['path'] === '/api/public/reports') {
        $reportsCall = $c;
    }
}
check('reports request uses group_by=source_id + columns=cost',
    ($reportsCall['query']['group_by'] ?? '') === 'source_id' && ($reportsCall['query']['columns'] ?? '') === 'cost',
    json_encode($reportsCall['query'] ?? []));
check('campaign list requested first', ($httpCalls[0]['path'] ?? '') === '/api/public/campaign');
check('one zone reports call per campaign', count(array_filter($httpCalls, fn($c) => $c['path'] === '/api/public/reports' && ($c['query']['group_by'] ?? '') === 'source_id')) === 2);

// Long window (30-day first sync): still single days — a multi-day request
// booked on one date put a week of spend on one day's clicks, and the next
// 5-day re-sync overwrote it and lost the rest. Idle campaigns are skipped
// via one group_by=campaign_id request over the whole window.
$day30 = gmdate('Y-m-d', strtotime($today . ' UTC') + 29 * 86400);
fakeHttp('AdMavenEngine', [
    '/api/public/campaign' => ['message' => [['id' => 1, 'name' => 'spends'], ['id' => 2, 'name' => 'idle']]],
    '/api/public/reports' => function (array $q) {
        if (($q['group_by'] ?? '') === 'campaign_id') {
            return ['message' => ['rows' => [['campaign_id' => 1, 'cost' => 30.0], ['campaign_id' => 2, 'cost' => 0]]]];
        }
        return ['message' => ['rows' => [['source_id' => 'z1', 'cost' => 1.0]]]];
    },
]);
$longRecords = AdMavenEngine::fetchRecords(['api_token' => 'T'], $today, $day30);
$zoneCalls = array_values(array_filter($httpCalls, fn($c) => $c['path'] === '/api/public/reports' && ($c['query']['group_by'] ?? '') === 'source_id'));
check('30-day window = 30 single-day requests', count($zoneCalls) === 30, 'calls=' . count($zoneCalls));
check('every request is one day', count(array_filter($zoneCalls, fn($c) => $c['query']['from_date'] === $c['query']['to_date'])) === 30);
check('idle campaign skipped', count(array_filter($zoneCalls, fn($c) => (string) $c['query']['campaign_id'] !== '1')) === 0);
check('one record per day, dated by its own day',
    count($longRecords) === 30 && count(array_unique(array_column($longRecords, 'date'))) === 30
    && $longRecords[0]['date'] === $today && $longRecords[29]['date'] === $day30,
    'records=' . count($longRecords));

// Unreadable campaign totals → every campaign is still fetched (no spend lost
// to the optimisation).
fakeHttp('AdMavenEngine', [
    '/api/public/campaign' => ['message' => [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]],
    '/api/public/reports' => function (array $q) {
        return ($q['group_by'] ?? '') === 'campaign_id' ? ['message' => 'oops'] : ['message' => ['rows' => []]];
    },
]);
AdMavenEngine::fetchRecords(['api_token' => 'T'], $today, $today);
check('unreadable totals fall back to all campaigns',
    count(array_filter($httpCalls, fn($c) => $c['path'] === '/api/public/reports' && ($c['query']['group_by'] ?? '') === 'source_id')) === 2);

fakeHttp('AdMavenEngine', [
    '/api/public/available_balance' => ['message' => 79.46],
]);
$test = AdMavenEngine::testConnection(['api_token' => 'T']);
check('test connection reads balance', $test['success'] && str_contains($test['message'], '79.46'), json_encode($test));

fakeHttp('AdMavenEngine', [
    '/api/public/available_balance' => ['error' => 'HTTP 401 — the API token was rejected or lacks read access.'],
]);
$test = AdMavenEngine::testConnection(['api_token' => 'bad']);
check('401 surfaces as a failure, not "0 records"', !$test['success'] && str_contains($test['message'], '401'), json_encode($test));

try {
    AdMavenEngine::fetchRecords([], $today, $today);
    check('missing token throws', false);
} catch (\RuntimeException $e) {
    check('missing token throws', str_contains($e->getMessage(), 'api_token'));
}

// ---- 2. HilltopAds engine ----------------------------------------------------

echo "\nHilltopAdsEngine\n";

// Verified shape (the working sync reads result[campaignId][0]): each
// campaign maps to a LIST of rows. A bare row object is accepted too.
fakeHttp('HilltopAdsEngine', [
    '/advertiser/listStats' => ['status' => 'success', 'result' => [
        '969033' => [['campaignId' => '969033', 'campaignTitle' => 'HB041002A', 'revenue' => '2.25', 'impressions' => 50, 'clicks' => 5]],
        '987938' => ['campaignTitle' => 'Second', 'spent' => '1.00', 'impressions' => 10, 'clicks' => 2],
        '0' => [['revenue' => '9.99']],  // junk key is skipped
    ]],
]);

$htrecords = HilltopAdsEngine::fetchRecords(['api_key' => 'K'], $today, $today);
check('campaign rows parsed from result map', count($htrecords) === 2, json_encode($htrecords));
check('campaign id taken from the result key', ($htrecords[0]['source_campaign_id'] ?? '') === '969033');
check('revenue read first', abs((float) $htrecords[0]['amount'] - 2.25) < 0.0001);
check('spent fallback read', abs((float) $htrecords[1]['amount'] - 1.0) < 0.0001);
check('campaign-level row keeps zone_id empty', ($htrecords[0]['zone_id'] ?? 'x') === '');
$statsCall = $httpCalls[0] ?? [];
check('date params are Date/Date2 — capitalized (lowercase is silently ignored)',
    ($statsCall['query']['Date'] ?? '') === $today && ($statsCall['query']['Date2'] ?? '') === $today,
    json_encode($statsCall['query'] ?? []));
check('group=campaignId requested', ($statsCall['query']['group'] ?? '') === 'campaignId');
check('api key travels as a query param, not a header', ($statsCall['query']['key'] ?? '') === 'K');

// Multi-row list: rows of one campaign are summed.
fakeHttp('HilltopAdsEngine', [
    '/advertiser/listStats' => ['status' => 'success', 'result' => [
        '555' => [['revenue' => '1.25', 'impressions' => 10], ['revenue' => '0.75', 'impressions' => 5]],
    ]],
]);
$multi = HilltopAdsEngine::fetchRecords(['api_key' => 'K'], $today, $today);
check('rows of one campaign summed', count($multi) === 1 && abs((float) $multi[0]['amount'] - 2.0) < 0.0001 && $multi[0]['impressions'] === 15,
    json_encode($multi));

// Empty day is `result: []` — no records, no error.
fakeHttp('HilltopAdsEngine', ['/advertiser/listStats' => ['status' => 'success', 'result' => []]]);
check('empty day yields no records', HilltopAdsEngine::fetchRecords(['api_key' => 'K'], $today, $today) === []);

// HTTP 200 + status=error must be a sync error, not "no spend".
fakeHttp('HilltopAdsEngine', ['/advertiser/listStats' => ['status' => 'error', 'errors' => ['Wrong API key'], 'result' => []]]);
$threw = '';
try {
    HilltopAdsEngine::fetchRecords(['api_key' => 'K'], $today, $today);
} catch (\RuntimeException $e) {
    $threw = $e->getMessage();
}
check('status=error surfaces as an exception', str_contains($threw, 'Wrong API key'), $threw);

// Real balance shape: {status, result: {balance: "579.64", currency}}.
fakeHttp('HilltopAdsEngine', ['/advertiser/balance' => ['status' => 'success', 'result' => ['balance' => '12.5', 'currency' => 'USD']]]);
$test = HilltopAdsEngine::testConnection(['api_key' => 'K']);
check('balance read from result.balance', str_contains($test['message'] ?? '', '12.5'), json_encode($test));
fakeHttp('HilltopAdsEngine', ['/advertiser/balance' => ['status' => 'error', 'errors' => ['Wrong API key']]]);
$bad = HilltopAdsEngine::testConnection(['api_key' => 'K']);
check('balance status=error fails the test connection', !$bad['success'] && str_contains($bad['message'], 'Wrong API key'), json_encode($bad));
fakeHttp('HilltopAdsEngine', ['/advertiser/balance' => ['status' => 'success', 'result' => ['balance' => '12.5', 'currency' => 'USD']]]);
$test = HilltopAdsEngine::testConnection(['api_key' => 'K']);
check('test connection ok', $test['success'] && str_contains($test['message'], '12.5'), json_encode($test));

// ---- 3. PropellerAds engine --------------------------------------------------

echo "\nPropellerAdsEngine\n";

fakeHttp('PropellerAdsEngine', [
    '/adv/statistics' => ['items' => [
        ['campaign_id' => '123456', 'campaign_name' => 'PA camp', 'zone_id' => '778899', 'money' => '3.1', 'impressions' => 200, 'clicks' => 20],
        ['campaign_id' => '123456', 'zone_id' => '', 'money' => '0.9', 'impressions' => 5, 'clicks' => 1],   // aggregate-only row
        ['campaign_id' => '', 'zone_id' => '778899', 'money' => '5.0'],                                        // no campaign — skipped
    ]],
]);

$parecords = PropellerAdsEngine::fetchRecords(['api_token' => 'T'], $today, $today);
check('zone rows parsed, campaign-less row dropped', count($parecords) === 2, json_encode($parecords));
check('money key read', abs((float) $parecords[0]['amount'] - 3.1) < 0.0001);
check('zone carried', ($parecords[0]['zone_id'] ?? '') === '778899');
check('empty zone degrades to a per-campaign row id', ($parecords[1]['external_id'] ?? '') === 'pa_123456_all_' . $today, $parecords[1]['external_id'] ?? '-');
$paCall = $httpCalls[0] ?? [];
check('group_by[] repeated keys survive the query build',
    ($paCall['query']['group_by[]'] ?? null) === ['campaign_id', 'zone_id'],
    json_encode($paCall['query'] ?? []));
check('single-day window requested (day_from == day_to)',
    ($paCall['query']['day_from'] ?? '') === $today && ($paCall['query']['day_to'] ?? '') === $today);

fakeHttp('PropellerAdsEngine', ['/adv/balance' => ['balance' => 8.2]]);
$test = PropellerAdsEngine::testConnection(['api_token' => 'T']);
check('test connection ok', $test['success'], json_encode($test));

fakeHttp('PropellerAdsEngine', ['/adv/balance' => ['error' => 'HTTP 429 — rate limited, retry on the next sync.']]);
$test = PropellerAdsEngine::testConnection(['api_token' => 'T']);
check('429 named as rate limit', !$test['success'] && str_contains($test['message'], '429'), json_encode($test));

clearHttp();

// ---- 4. Zone attribution in CostImporter -------------------------------------

echo "\nCostImporter zone attribution\n";

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
$pdo->exec("CREATE TABLE aggregator_connections (id INTEGER PRIMARY KEY, engine TEXT, credentials_json TEXT, field_mapping_json TEXT)");
$pdo->exec("CREATE TABLE clicks (
    id TEXT PRIMARY KEY, campaign_id INTEGER, ip TEXT, user_agent TEXT, referer TEXT,
    country_code TEXT, region TEXT, city TEXT, zipcode TEXT,
    cost REAL DEFAULT 0, fee REAL NOT NULL DEFAULT 0, revenue REAL DEFAULT 0,
    parameters_json TEXT, created_at DATETIME
)");
$pdo->exec("CREATE TABLE cost_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER, external_id TEXT,
    source_campaign_id TEXT, ad_id TEXT, adset_id TEXT, zone_id TEXT NOT NULL DEFAULT '',
    amount REAL, fee_amount REAL NOT NULL DEFAULT 0,
    currency TEXT, click_date DATE, impressions INTEGER, platform_clicks INTEGER,
    raw_json TEXT, is_matched INTEGER DEFAULT 0
)");
$pdo->exec("INSERT INTO settings (key, value) VALUES ('currency', 'USD')");
$pdo->exec("INSERT INTO aggregator_connections (id, engine, credentials_json, field_mapping_json) VALUES (1, 'admaven', '{}', '{}')");

$insertClick = $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, referer, country_code, parameters_json, created_at) VALUES (?,?,?,?,?,?,?,?)");
// AdMaven-style campaign 111: zones zA (3 clicks), zB (1 click), one click without a zone.
$insertClick->execute(['za1', 1, '203.0.113.1', 'Mozilla/5.0', '', 'de', json_encode(['ad_campaign_id' => '111', 'source' => 'zA']), $today . ' 10:00:00']);
$insertClick->execute(['za2', 1, '203.0.113.2', 'Mozilla/5.0', '', 'de', json_encode(['ad_campaign_id' => '111', 'source' => 'zA']), $today . ' 11:00:00']);
$insertClick->execute(['za3', 1, '203.0.113.3', 'Mozilla/5.0', '', 'de', json_encode(['ad_campaign_id' => '111', 'source' => 'zA']), $today . ' 12:00:00']);
$insertClick->execute(['zb1', 1, '203.0.113.4', 'Mozilla/5.0', '', 'de', json_encode(['ad_campaign_id' => '111', 'source' => 'zB']), $today . ' 13:00:00']);
$insertClick->execute(['nozone', 1, '203.0.113.5', 'Mozilla/5.0', '', 'de', json_encode(['ad_campaign_id' => '111']), $today . ' 14:00:00']);
// Same zone id under ANOTHER network's campaign: must never receive campaign 111's spend.
$insertClick->execute(['other', 1, '203.0.113.6', 'Mozilla/5.0', '', 'de', json_encode(['ad_campaign_id' => '222', 'source' => 'zA']), $today . ' 15:00:00']);
// A different day: date isolation.
$insertClick->execute(['yday', 1, '203.0.113.7', 'Mozilla/5.0', '', 'de', json_encode(['ad_campaign_id' => '111', 'source' => 'zA']), gmdate('Y-m-d H:i:s', strtotime($today . ' UTC') - 86400 + 3600)]);

$zoneRecords = [
    ['external_id' => 'am_111_zA_' . $today, 'source_campaign_id' => '111', 'ad_id' => '', 'adset_id' => '', 'zone_id' => 'zA',
     'amount' => 3.0, 'currency' => 'USD', 'date' => $today, 'raw_json' => '{}'],
    ['external_id' => 'am_111_zB_' . $today, 'source_campaign_id' => '111', 'ad_id' => '', 'adset_id' => '', 'zone_id' => 'zB',
     'amount' => 2.0, 'currency' => 'USD', 'date' => $today, 'raw_json' => '{}'],
    // Zone with no clicks of its own: the spend falls to the campaign's
    // zone-less clicks only — never onto zA/zB's already-priced clicks.
    ['external_id' => 'am_111_zC_' . $today, 'source_campaign_id' => '111', 'ad_id' => '', 'adset_id' => '', 'zone_id' => 'zC',
     'amount' => 1.0, 'currency' => 'USD', 'date' => $today, 'raw_json' => '{}'],
];

$stats = CostImporter::import($pdo, 1, $zoneRecords);

$cost = function (string $id) use ($pdo) {
    $stmt = $pdo->prepare("SELECT cost FROM clicks WHERE id = ?");
    $stmt->execute([$id]);
    return (float) $stmt->fetchColumn();
};

check('all zone rows matched', $stats['matched'] === 3 && $stats['unmatched'] === 0, json_encode($stats));
check('zA spend split across zA clicks only', abs($cost('za1') - 1.0) < 0.001 && abs($cost('za2') - 1.0) < 0.001 && abs($cost('za3') - 1.0) < 0.001,
    $cost('za1') . '/' . $cost('za2') . '/' . $cost('za3'));
check('zB spend lands on the single zB click', abs($cost('zb1') - 2.0) < 0.001, 'got ' . $cost('zb1'));
check('zone-less click absorbs the zone-less-campaign fallback', abs($cost('nozone') - 1.0) < 0.001, 'got ' . $cost('nozone'));
check('same zone under another campaign is untouched', $cost('other') === 0.0, 'got ' . $cost('other'));
check('other-day clicks untouched', $cost('yday') === 0.0, 'got ' . $cost('yday'));
$storedZone = $pdo->query("SELECT zone_id FROM cost_records WHERE external_id = 'am_111_zA_{$today}'")->fetchColumn();
check('zone_id stored on the cost row', $storedZone === 'zA', "got '$storedZone'");

// Re-sync: running total grows — assigned, not accumulated; no new rows.
$zoneRecords[0]['amount'] = 6.0;
$stats2 = CostImporter::import($pdo, 1, $zoneRecords);
// Zone rows are always re-evaluated (a shared zone-less set needs every
// sibling's amount), so all three are updates — none inserted.
check('zone re-sync updates, not inserts', $stats2['new'] === 0 && $stats2['updated'] === 3, json_encode($stats2));
check('grown zone total re-assigned', abs($cost('za1') - 2.0) < 0.001, 'got ' . $cost('za1'));
check('zB row unchanged by the re-sync', abs($cost('zb1') - 2.0) < 0.001, 'got ' . $cost('zb1'));

// Campaign-level row (HilltopAds shape: zone_id '') spreads across the whole
// campaign — zoned and zone-less clicks alike.
$pdo->exec("INSERT INTO clicks (id, campaign_id, ip, user_agent, referer, country_code, parameters_json, created_at) VALUES ('ht1', 1, '203.0.113.8', 'Mozilla/5.0', '', 'de', '" . json_encode(['ad_campaign_id' => '333']) . "', '" . $today . " 16:00:00')");
$pdo->exec("INSERT INTO clicks (id, campaign_id, ip, user_agent, referer, country_code, parameters_json, created_at) VALUES ('ht2', 1, '203.0.113.9', 'Mozilla/5.0', '', 'de', '" . json_encode(['ad_campaign_id' => '333', 'source' => 'hz1']) . "', '" . $today . " 17:00:00')");
$htRow = [['external_id' => 'ht_333_' . $today, 'source_campaign_id' => '333', 'ad_id' => '', 'adset_id' => '', 'zone_id' => '',
           'amount' => 4.0, 'currency' => 'USD', 'date' => $today, 'raw_json' => '{}']];
$statsHt = CostImporter::import($pdo, 1, $htRow);
check('campaign-level row matches all campaign clicks', abs($cost('ht1') - 2.0) < 0.001 && abs($cost('ht2') - 2.0) < 0.001,
    $cost('ht1') . '/' . $cost('ht2'));

// The common beginner case: the ad link has NO zone macro. Every zone row
// falls back to the same zone-less clicks — their spend must be SUMMED, not
// overwritten row by row (which left only the last zone's spend).
foreach (['nz1', 'nz2', 'nz3'] as $i => $id) {
    $insertClick->execute([$id, 1, '203.0.113.2' . $i, 'Mozilla/5.0', '', 'de', json_encode(['ad_campaign_id' => '444']), $today . ' 18:0' . $i . ':00']);
}
$noZoneRows = [];
foreach (['q1' => 2.0, 'q2' => 1.0, 'q3' => 0.5] as $z => $amt) {
    $noZoneRows[] = ['external_id' => 'am_444_' . $z . '_' . $today, 'source_campaign_id' => '444', 'ad_id' => '', 'adset_id' => '',
                     'zone_id' => $z, 'amount' => $amt, 'currency' => 'USD', 'date' => $today, 'raw_json' => '{}'];
}
$statsNz = CostImporter::import($pdo, 1, $noZoneRows);
$nzTotal = $cost('nz1') + $cost('nz2') + $cost('nz3');
check('missing zone macro: all zone spend kept (sum, not last row)', abs($nzTotal - 3.5) < 0.001, 'total=' . $nzTotal . ' ' . json_encode($statsNz));
check('missing zone macro: spread evenly', abs($cost('nz1') - 3.5 / 3) < 0.001 && abs($cost('nz3') - 3.5 / 3) < 0.001);
check('missing zone macro: every row counted matched', $statsNz['matched'] === 3, json_encode($statsNz));
// Re-sync where only one sibling grew — the set is recomputed from all three.
$noZoneRows[0]['amount'] = 3.0;
CostImporter::import($pdo, 1, $noZoneRows);
$nzTotal = $cost('nz1') + $cost('nz2') + $cost('nz3');
check('missing zone macro: re-sync with one changed sibling keeps the sum', abs($nzTotal - 4.5) < 0.001, 'total=' . $nzTotal);
check('missing zone macro: the zoned campaign is untouched', abs($cost('za1') - 2.0) < 0.001 && abs($cost('nozone') - 1.0) < 0.001,
    'za1=' . $cost('za1') . ' nozone=' . $cost('nozone'));

// ---- 5. rematch over zone rows ----------------------------------------------

echo "\nCostImporter rematch\n";

foreach (['za1', 'za2', 'za3', 'zb1', 'nozone', 'ht1', 'ht2'] as $id) {
    $pdo->prepare("UPDATE clicks SET cost = 0, fee = 0 WHERE id = ?")->execute([$id]);
}
$rematch = CostImporter::rematch($pdo, 1, $today, $today);
check('rematch re-attributes zone rows off stored cost_records',
    abs($cost('za1') - 2.0) < 0.001 && abs($cost('zb1') - 2.0) < 0.001 && abs($cost('nozone') - 1.0) < 0.001 && abs($cost('ht1') - 2.0) < 0.001,
    json_encode($rematch) . ' za1=' . $cost('za1') . ' zb1=' . $cost('zb1') . ' nozone=' . $cost('nozone') . ' ht1=' . $cost('ht1'));
check('rematch leaves the other network\'s campaign at zero', $cost('other') === 0.0, 'got ' . $cost('other'));
check('rematch keeps the summed zone-less fallback', abs($cost('nz1') + $cost('nz2') + $cost('nz3') - 4.5) < 0.001,
    'total=' . ($cost('nz1') + $cost('nz2') + $cost('nz3')));

// ---- Summary -----------------------------------------------------------------

echo "\n" . str_repeat('-', 60) . "\n";
echo "passed: $passed   failed: $failed\n";
exit($failed === 0 ? 0 : 1);
