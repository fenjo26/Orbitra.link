<?php
/**
 * tests/tiktok_cost_connection_test.php
 *
 * Регрессия на 40002 «advertiser_ids: Missing data for required field»:
 * /advertiser/info/ в Business API v1.3 читает только JSON-массив
 * `advertiser_ids`, а Orbitra слала одиночный `advertiser_id`, из-за чего
 * «Test Connection» падал ещё на валидации параметров — с любым токеном и
 * любым, даже совершенно верным, ID кабинета.
 *
 * Плюс разбор ответа (data.list[]), тексты ошибок и парсер proxy_url.
 *
 * Запуск из корня проекта:
 *
 *     php tests/tiktok_cost_connection_test.php
 *
 * Сети нет: HTTP-слой подменён TikTokAdsEngine::$testHttpHandler.
 */

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

/**
 * Ставит фейковый HTTP-слой и копит прошедшие через него запросы в $httpCalls.
 * $responses отдаются по порядку; последний повторяется, если запросов больше.
 */
$httpCalls = [];

function captureHttp(array $responses): void
{
    global $httpCalls;
    $httpCalls = [];
    TikTokAdsEngine::$testHttpHandler = function (string $method, string $url, ?array $payload, array $credentials) use ($responses): array {
        global $httpCalls;
        $httpCalls[] = ['method' => $method, 'url' => $url, 'payload' => $payload];
        $response = $responses[count($httpCalls) - 1] ?? end($responses);
        return is_array($response) && array_key_exists('error', $response)
            ? $response
            : ['body' => json_encode($response), 'error' => null];
    };
}

function privateCall(string $method, ...$args)
{
    $ref = new ReflectionMethod(TikTokAdsEngine::class, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
}

// Данные из баг-репорта: ровно этот ID возвращал 40002.
$creds = [
    'access_token'  => 'TEST_TOKEN',
    'advertiser_id' => '7661936120595972112',
];

$okAdvertiser = [
    'code'    => 0,
    'message' => 'OK',
    'data'    => ['list' => [[
        'advertiser_id' => '7661936120595972112',
        'name'          => 'Denver Marriott Westminster',
        'currency'      => 'USD',
        'timezone'      => 'America/Denver',
    ]]],
];

// ---- Форма запроса ---------------------------------------------------------

echo "TikTok advertiser/info request shape\n";

captureHttp([$okAdvertiser]);
$result = TikTokAdsEngine::testConnection($creds);
$calls = $httpCalls;

check('testConnection issues exactly one request', count($calls) === 1, 'got ' . count($calls));
$url = $calls[0]['url'] ?? '';
$query = [];
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

check('request goes to /advertiser/info/', strpos($url, '/open_api/v1.3/advertiser/info/') !== false, $url);
check('request is a GET', ($calls[0]['method'] ?? '') === 'GET');
check(
    'advertiser_ids is sent as a JSON array (the 40002 regression)',
    ($query['advertiser_ids'] ?? '') === '["7661936120595972112"]',
    'advertiser_ids=' . var_export($query['advertiser_ids'] ?? null, true)
);
check('the singular advertiser_id param is gone', !isset($query['advertiser_id']), $url);
check('no fields param — TikTok returns the default set', !isset($query['fields']), $url);

// ---- Разбор ответа ---------------------------------------------------------

echo "TikTok advertiser/info response parsing\n";

check('connection succeeds', ($result['success'] ?? false) === true, (string) ($result['message'] ?? ''));
check(
    'account name comes from data.list[0]',
    strpos((string) ($result['message'] ?? ''), 'Denver Marriott Westminster') !== false,
    (string) ($result['message'] ?? '')
);
check(
    'currency comes from data.list[0]',
    strpos((string) ($result['message'] ?? ''), 'USD') !== false,
    (string) ($result['message'] ?? '')
);

// Ответ старой формы (объект вместо списка) не должен читаться как «не найден».
captureHttp([['code' => 0, 'data' => ['advertiser_id' => '1', 'name' => 'Legacy', 'currency' => 'EUR']]]);
$legacy = TikTokAdsEngine::testConnection(['access_token' => 'T', 'advertiser_id' => '1']);
check('flat data object still parses', ($legacy['success'] ?? false) === true, (string) ($legacy['message'] ?? ''));

captureHttp([['code' => 0, 'data' => ['list' => []]]]);
$empty = TikTokAdsEngine::testConnection($creds);
check(
    'empty list reports the advertiser as unavailable, not as success',
    ($empty['success'] ?? true) === false && strpos((string) $empty['message'], '7661936120595972112') !== false,
    (string) ($empty['message'] ?? '')
);

// ---- Тексты ошибок ---------------------------------------------------------

echo "TikTok error messages\n";

captureHttp([['code' => 40105, 'message' => 'Access token is invalid']]);
$scope = TikTokAdsEngine::testConnection($creds);
check(
    'auth failures explain the Events-Manager-token trap',
    ($scope['success'] ?? true) === false
        && strpos((string) $scope['message'], 'Marketing API') !== false
        && strpos((string) $scope['message'], 'Events') !== false,
    (string) ($scope['message'] ?? '')
);
check('auth failure still carries the raw code', strpos((string) $scope['message'], '40105') !== false, (string) $scope['message']);

captureHttp([['code' => 40002, 'message' => 'advertiser_ids: Missing data for required field.']]);
$param = TikTokAdsEngine::testConnection($creds);
check(
    'a surviving 40002 is labelled an Orbitra bug, not bad credentials',
    ($param['success'] ?? true) === false && strpos((string) $param['message'], 'Orbitra bug') !== false,
    (string) ($param['message'] ?? '')
);

TikTokAdsEngine::$testHttpHandler = function (): array {
    throw new RuntimeException('handler must not be reached');
};
$blank = TikTokAdsEngine::testConnection(['access_token' => 'T', 'advertiser_id' => '']);
check('missing advertiser id is caught before any request', ($blank['success'] ?? true) === false && strpos((string) $blank['message'], 'advertiser_id') !== false, (string) $blank['message']);

$letters = TikTokAdsEngine::testConnection(['access_token' => 'T', 'advertiser_id' => 'act_12ab']);
check(
    'a non-numeric advertiser id is rejected, never silently reduced to its digits',
    ($letters['success'] ?? true) === false && stripos((string) $letters['message'], 'numeric') !== false,
    (string) $letters['message']
);

// Копипаст из Ads Manager обычно тащит кавычки, пробелы и невидимые символы.
captureHttp([$okAdvertiser]);
TikTokAdsEngine::testConnection(['access_token' => 'T', 'advertiser_id' => " \u{201c}7661936120595972112\u{201d}\u{200b} "]);
parse_str((string) parse_url((string) ($httpCalls[0]['url'] ?? ''), PHP_URL_QUERY), $pastedQuery);
check(
    'quotes, spaces and zero-width characters are stripped from a pasted id',
    ($pastedQuery['advertiser_ids'] ?? '') === '["7661936120595972112"]',
    var_export($pastedQuery['advertiser_ids'] ?? null, true)
);

// ---- Расход ----------------------------------------------------------------

echo "TikTok spend sync\n";

captureHttp([
    $okAdvertiser,
    ['code' => 0, 'data' => [
        'page_info' => ['total_page' => 1],
        'list' => [[
            'dimensions' => ['ad_id' => '17800000000000001', 'stat_time_day' => '2026-08-17 00:00:00'],
            'metrics'    => ['spend' => '12.34', 'campaign_id' => '17700000000000001', 'adgroup_id' => '17750000000000001'],
        ]],
    ]],
]);
$records = TikTokAdsEngine::fetchRecords($creds, '2026-08-17', '2026-08-17');
$syncCalls = $httpCalls;

check('spend sync reads currency from advertiser/info first', count($syncCalls) === 2, 'calls: ' . count($syncCalls));
check('report request carries advertiser_id (POST body, singular by design)', ($syncCalls[1]['payload']['advertiser_id'] ?? '') === '7661936120595972112');
check('one record per ad-day', count($records) === 1, 'got ' . count($records));
check('amount parsed', abs(($records[0]['amount'] ?? 0) - 12.34) < 0.001);
check('currency inherited from the ad account', ($records[0]['currency'] ?? '') === 'USD');
check('date trimmed to a day', ($records[0]['date'] ?? '') === '2026-08-17');
check('ad / adset / campaign ids kept for attribution',
    ($records[0]['ad_id'] ?? '') === '17800000000000001'
    && ($records[0]['adset_id'] ?? '') === '17750000000000001'
    && ($records[0]['source_campaign_id'] ?? '') === '17700000000000001');

captureHttp([['code' => 40105, 'message' => 'Access token is invalid']]);
try {
    TikTokAdsEngine::fetchRecords($creds, '2026-08-17', '2026-08-17');
    check('a dead token throws instead of syncing zero rows', false);
} catch (RuntimeException $e) {
    check('a dead token throws instead of syncing zero rows', strpos($e->getMessage(), 'Marketing API') !== false, $e->getMessage());
}

TikTokAdsEngine::$testHttpHandler = null;

// ---- Прокси ----------------------------------------------------------------

echo "Proxy URL parsing\n";

$parsed = privateCall('parseProxyUrl', 'http://Seladityawaliawalia:I3u9WoV@179.60.183.113:50100');
check('bug-report proxy parses', is_array($parsed)
    && $parsed['host'] === '179.60.183.113'
    && $parsed['port'] === 50100
    && $parsed['user'] === 'Seladityawaliawalia'
    && $parsed['pass'] === 'I3u9WoV'
    && $parsed['scheme'] === 'http', var_export($parsed, true));

$withAt = privateCall('parseProxyUrl', 'http://user:p@ss/word@1.2.3.4:8080');
check('password containing @ and / splits on the last @', is_array($withAt)
    && $withAt['host'] === '1.2.3.4' && $withAt['pass'] === 'p@ss/word', var_export($withAt, true));

$socks = privateCall('parseProxyUrl', 'socks5://1.2.3.4:1080');
check('socks5 without credentials parses', is_array($socks) && $socks['scheme'] === 'socks5' && $socks['user'] === null, var_export($socks, true));

$bare = privateCall('parseProxyUrl', '1.2.3.4:8080');
check('scheme defaults to http', is_array($bare) && $bare['scheme'] === 'http' && $bare['port'] === 8080, var_export($bare, true));

$v6 = privateCall('parseProxyUrl', 'http://[2001:db8::1]:3128');
check('bracketed IPv6 parses', is_array($v6) && $v6['host'] === '2001:db8::1' && $v6['port'] === 3128, var_export($v6, true));

check('empty-ish proxy is rejected', privateCall('parseProxyUrl', 'http://') === null);
check('proxy with a path is rejected', privateCall('parseProxyUrl', 'http://1.2.3.4:8080/path') === null);
check('out-of-range port is rejected', privateCall('parseProxyUrl', 'http://1.2.3.4:99999') === null);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
