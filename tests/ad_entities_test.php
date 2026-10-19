<?php
/**
 * tests/ad_entities_test.php
 *
 * Площадочные имена и счётчики в отчётах по объявлениям (миграция 62).
 *
 * Импорт расхода теперь несёт не только суммы: FB insights и TikTok integrated
 * report отдают имена ad/adset/campaign и счётчики impressions/clicks. Тест
 * закрывает цепочку:
 *
 *  1. движки: FacebookAdsEngine::fetchAccountEntities (три edge-запроса) и
 *     TikTokAdsEngine::fetchAccountEntities (/campaign|adgroup|ad/get/) —
 *     структура {name, status, parent_id} по уровням;
 *  2. CostImporter: impressions/platform_clicks в cost_records + upsert
 *     ad_entities (переименование выигрывает, пустое имя не стирает);
 *  3. AdEntities::refreshStatuses — часовой троттлинг, статусы по нормализованной
 *     шкале (ENABLE → ACTIVE), сбой движка не бросается наружу.
 *
 * Запуск из корня проекта:
 *
 *     php tests/ad_entities_test.php
 */

require_once __DIR__ . '/../core/ClickParams.php';
require_once __DIR__ . '/../core/CurrencyRates.php';
require_once __DIR__ . '/../core/CostImporter.php';
require_once __DIR__ . '/../core/AdEntities.php';
require_once __DIR__ . '/../aggregator_engines/FacebookAdsEngine.php';
require_once __DIR__ . '/../aggregator_engines/TikTokAdsEngine.php';

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

// ---- Facebook: три edge-запроса ----------------------------------------------

echo "FacebookAdsEngine::fetchAccountEntities\n";

$fbCalls = [];
FacebookAdsEngine::$testHttpHandler = function (string $url, array $credentials) use (&$fbCalls): array {
    $fbCalls[] = $url;
    if (strpos($url, '/campaigns') !== false) {
        $body = ['data' => [
            ['id' => '60111111111111001', 'name' => 'GR: Casino Athens', 'effective_status' => 'ACTIVE'],
            ['id' => '60111111111111002', 'name' => 'GR: Casino Thess', 'effective_status' => 'PAUSED'],
        ]];
    } elseif (strpos($url, '/adsets') !== false) {
        $body = ['data' => [
            ['id' => '60111111111111011', 'name' => 'AdSet Broad 25-65', 'effective_status' => 'ACTIVE', 'campaign_id' => '60111111111111001'],
        ]];
    } elseif (strpos($url, '/ads') !== false) {
        $body = ['data' => [
            ['id' => '60111111111111021', 'name' => 'Sales Video 3', 'effective_status' => 'ACTIVE', 'adset_id' => '60111111111111011'],
            ['id' => '60111111111111022', 'name' => 'Dead one', 'effective_status' => 'DELETED', 'adset_id' => '60111111111111011'],
        ]];
    } else {
        $body = ['data' => []];
    }
    return ['body' => json_encode($body), 'error' => null];
};

$fbEntities = FacebookAdsEngine::fetchAccountEntities(['token' => 'T', 'ad_account_id' => 'act_123']);
check('three edges requested (campaigns, adsets, ads)', count($fbCalls) === 3, implode(' | ', $fbCalls));
check('campaign level parsed with statuses', ($fbEntities['campaign']['60111111111111001']['name'] ?? '') === 'GR: Casino Athens'
    && ($fbEntities['campaign']['60111111111111002']['status'] ?? '') === 'PAUSED');
check('adset level carries the campaign parent', ($fbEntities['adset']['60111111111111011']['parent_id'] ?? '') === '60111111111111001');
check('ad level carries the adset parent and effective status', ($fbEntities['ad']['60111111111111021']['parent_id'] ?? '') === '60111111111111011'
    && ($fbEntities['ad']['60111111111111022']['status'] ?? '') === 'DELETED');

FacebookAdsEngine::$testHttpHandler = null;

// ---- TikTok: три /get/ запроса ------------------------------------------------

echo "TikTokAdsEngine::fetchAccountEntities\n";

$ttCalls = [];
TikTokAdsEngine::$testHttpHandler = function (string $method, string $url, ?array $payload, array $credentials) use (&$ttCalls): array {
    $ttCalls[] = $url;
    if (strpos($url, '/campaign/get/') !== false) {
        $data = ['list' => [
            ['campaign_id' => '1790000000000000001', 'campaign_name' => 'TT Casino Athena', 'status' => 'ENABLE'],
        ]];
    } elseif (strpos($url, '/adgroup/get/') !== false) {
        $data = ['list' => [
            ['adgroup_id' => '1795000000000000001', 'adgroup_name' => 'TT Broad', 'status' => 'ENABLE', 'campaign_id' => '1790000000000000001'],
        ]];
    } elseif (strpos($url, '/ad/get/') !== false) {
        $data = ['list' => [
            ['ad_id' => '1780000000000000001', 'ad_name' => 'TT Hook 12s', 'status' => 'DISABLE', 'adgroup_id' => '1795000000000000001'],
        ]];
    } else {
        $data = ['list' => []];
    }
    return ['body' => json_encode(['code' => 0, 'message' => 'OK', 'data' => $data + ['page_info' => ['total_page' => 1]]]), 'error' => null];
};

$ttEntities = TikTokAdsEngine::fetchAccountEntities(['access_token' => 'T', 'advertiser_id' => '7661936120595972112']);
check('three endpoints requested (campaign, adgroup, ad)', count($ttCalls) === 3, implode(' | ', $ttCalls));
check('campaign level parsed', ($ttEntities['campaign']['1790000000000000001']['name'] ?? '') === 'TT Casino Athena');
check('ad level parsed with raw DISABLE status', ($ttEntities['ad']['1780000000000000001']['status'] ?? '') === 'DISABLE');

TikTokAdsEngine::$testHttpHandler = null;

check('status normalization: ENABLE → ACTIVE', AdEntities::normalizeStatus('ENABLE') === 'ACTIVE');
check('status normalization: DISABLE → PAUSED', AdEntities::normalizeStatus('DISABLE') === 'PAUSED');
check('status normalization: DELETE → DELETED', AdEntities::normalizeStatus('DELETE') === 'DELETED');

// ---- Импорт: счётчики + upsert ad_entities ------------------------------------

echo "CostImporter: platform counters and ad_entities upsert\n";

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT, updated_at DATETIME)");
$pdo->exec("CREATE TABLE aggregator_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, engine TEXT, credentials_json TEXT, field_mapping_json TEXT, agency_id INTEGER, is_active INTEGER DEFAULT 1)");
$pdo->exec("CREATE TABLE clicks (id TEXT PRIMARY KEY, campaign_id INTEGER, ip TEXT, cost REAL DEFAULT 0, fee REAL NOT NULL DEFAULT 0, revenue REAL DEFAULT 0, parameters_json TEXT, created_at DATETIME)");
$pdo->exec("CREATE TABLE cost_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER, external_id TEXT,
    source_campaign_id TEXT, ad_id TEXT, adset_id TEXT, amount REAL, fee_amount REAL NOT NULL DEFAULT 0,
    currency TEXT, click_date DATE, impressions INTEGER, platform_clicks INTEGER,
    raw_json TEXT, is_matched INTEGER DEFAULT 0
)");
$pdo->exec("CREATE TABLE ad_entities (
    id INTEGER PRIMARY KEY AUTOINCREMENT, platform TEXT NOT NULL, level TEXT NOT NULL,
    external_id TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', status TEXT, parent_id TEXT,
    connection_id INTEGER, first_seen_at DATETIME, last_seen_at DATETIME,
    UNIQUE(platform, level, external_id)
)");
$pdo->exec("INSERT INTO settings (key, value) VALUES ('currency', 'USD')");
$pdo->exec("INSERT INTO aggregator_connections (name, engine, credentials_json, field_mapping_json) VALUES ('tt', 'tiktok', '{}', '{}')");
$connId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO clicks (id, campaign_id, ip, parameters_json, created_at) VALUES ('X1', 1, '1.1.1.1', '{\"ad_id\":\"1780000000000000001\"}', '2026-10-14 12:00:00')");

$rec = [
    'external_id' => 'tt_1_1780000000000000001_2026-10-14',
    'source_campaign_id' => '1790000000000000001',
    'ad_id' => '1780000000000000001',
    'adset_id' => '1795000000000000001',
    'amount' => 40.0, 'currency' => 'USD', 'date' => '2026-10-14',
    'ad_name' => 'TT Hook 12s', 'adset_name' => 'TT Broad', 'campaign_name' => 'TT Casino Athena',
    'impressions' => 5000, 'platform_clicks' => 120,
    'raw_json' => '{}',
];
$stats = CostImporter::import($pdo, $connId, [$rec], [], ['exclude_safe_page' => false]);
check('import reports three upserted entities', ($stats['ad_entities'] ?? 0) === 3, json_encode($stats));

$cr = $pdo->query('SELECT impressions, platform_clicks FROM cost_records')->fetch(PDO::FETCH_ASSOC);
check('cost_records carries the platform counters', (int) $cr['impressions'] === 5000 && (int) $cr['platform_clicks'] === 120, json_encode($cr));

$ad = $pdo->query("SELECT name, parent_id, connection_id FROM ad_entities WHERE platform='tiktok' AND level='ad' AND external_id='1780000000000000001'")->fetch(PDO::FETCH_ASSOC);
check('ad entity stored with name, parent and connection', ($ad['name'] ?? '') === 'TT Hook 12s' && ($ad['parent_id'] ?? '') === '1795000000000000001' && (int) ($ad['connection_id'] ?? 0) === $connId, json_encode($ad));

// Ренейм выигрывает, пустое имя не стирает.
$recRenamed = $rec;
$recRenamed['ad_name'] = 'TT Hook 12s v2';
$recRenamed['campaign_name'] = '';
CostImporter::import($pdo, $connId, [$recRenamed], [], ['exclude_safe_page' => false]);
$names = $pdo->query("SELECT
    (SELECT name FROM ad_entities WHERE level='ad' AND external_id='1780000000000000001') AS ad_name,
    (SELECT name FROM ad_entities WHERE level='campaign' AND external_id='1790000000000000001') AS camp_name
")->fetch(PDO::FETCH_ASSOC);
check('a rename wins, an empty name never erases', ($names['ad_name'] ?? '') === 'TT Hook 12s v2' && ($names['camp_name'] ?? '') === 'TT Casino Athena', json_encode($names));

// ---- Часовой рефреш: троттлинг + хранение --------------------------------------

echo "AdEntities::refreshStatuses\n";

TikTokAdsEngine::$testHttpHandler = function (string $method, string $url, ?array $payload, array $credentials): array {
    $data = ['list' => [], 'page_info' => ['total_page' => 1]];
    if (strpos($url, '/ad/get/') !== false) {
        $data['list'] = [['ad_id' => '1780000000000000001', 'ad_name' => 'TT Hook 12s v2', 'status' => 'SYSTEM_STOP', 'adgroup_id' => '1795000000000000001']];
    }
    return ['body' => json_encode(['code' => 0, 'message' => 'OK', 'data' => $data]), 'error' => null];
};

$refresh = AdEntities::refreshStatuses($pdo, $connId, ['access_token' => 'T', 'advertiser_id' => '7661936120595972112'], 'tiktok');
check('first refresh stores entities', ($refresh['refreshed'] ?? 0) >= 1 && empty($refresh['error']), json_encode($refresh));
$adStatus = $pdo->query("SELECT status FROM ad_entities WHERE platform='tiktok' AND level='ad' AND external_id='1780000000000000001'")->fetchColumn();
check('the refreshed status lands normalized', $adStatus === 'SYSTEM_STOP', var_export($adStatus, true));

$again = AdEntities::refreshStatuses($pdo, $connId, ['access_token' => 'T', 'advertiser_id' => '7661936120595972112'], 'tiktok');
check('second call inside the hour is throttled', $again['skipped'] === true && $again['refreshed'] === 0, json_encode($again));

$unknown = AdEntities::refreshStatuses($pdo, $connId, [], 'affilka');
check('non-ad engines are a no-op', $unknown['skipped'] === true);

TikTokAdsEngine::$testHttpHandler = function (): array {
    throw new RuntimeException('engine HTTP must not be reached: throttle still applies');
};
$stillThrottled = AdEntities::refreshStatuses($pdo, $connId, ['access_token' => 'T', 'advertiser_id' => '7661936120595972112'], 'tiktok');
check('a failing engine call never throws out of the refresher', $stillThrottled['skipped'] === true);

TikTokAdsEngine::$testHttpHandler = null;

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
