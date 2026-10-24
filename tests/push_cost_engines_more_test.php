<?php
/**
 * Push/pop spend import, second wave: Kadam, Galaxion, Adsterra, Clickadu.
 * Offline — every engine's HTTP is faked with the response shapes captured
 * from live accounts (ads-manager probe/sync, 2026-10-12).
 *
 * Run: php tests/push_cost_engines_more_test.php
 */

require_once __DIR__ . '/../aggregator_engines/KadamEngine.php';
require_once __DIR__ . '/../aggregator_engines/GalaxionEngine.php';
require_once __DIR__ . '/../aggregator_engines/AdsterraEngine.php';
require_once __DIR__ . '/../aggregator_engines/ClickaduEngine.php';

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

/** Route fake HTTP by path; a route may be a closure over the request params. */
$httpCalls = [];
function fakeHttp(string $engineClass, array $routes): void
{
    global $httpCalls;
    $httpCalls = [];
    $engineClass::$testHttpHandler = function (string $method, string $path, array $params, array $credentials) use ($routes) {
        global $httpCalls;
        $httpCalls[] = ['method' => $method, 'path' => $path, 'params' => $params, 'credentials' => $credentials];
        foreach ($routes as $prefix => $body) {
            if (strpos($path, $prefix) === 0) {
                if ($body instanceof \Closure) {
                    $body = $body($params, $method);
                }
                if (is_array($body) && array_key_exists('error', $body) && is_string($body['error'])) {
                    return $body;
                }
                return ['body' => json_encode($body), 'error' => null];
            }
        }
        return ['body' => '{}', 'error' => null];
    };
}

$today = gmdate('Y-m-d');
$yday = gmdate('Y-m-d', strtotime($today . ' UTC') - 86400);
GalaxionEngine::$pauseSeconds = 0;

// ---- Kadam -------------------------------------------------------------------

echo "\nKadamEngine\n";

fakeHttp('KadamEngine', [
    '/campaigns' => ['success' => true, 'code' => 200, 'data' => ['rows' => [
        ['campaign' => ['id' => 955714, 'name' => 'KB0410 | KU0809A']],
        ['campaign' => ['id' => 955715, 'name' => 'idle']],
    ], 'totalRows' => 2]],
    '/stats/sites' => function (array $body) use ($today) {
        $cid = $body['filters']['campaignIds'][0] ?? 0;
        if (($body['perPage'] ?? 0) === 1) {
            // Whole-window totals: the second campaign spent nothing.
            return ['success' => true, 'data' => ['rows' => [], 'totalRows' => 0,
                'total' => ['spending' => $cid === 955714 ? '$3.50' : '0.00']]];
        }
        return ['success' => true, 'data' => ['totalRows' => 3, 'rows' => [
            ['macrosId' => 'site_77', 'spending' => '$2.25', 'views' => '1 200', 'clicks' => '15'],
            ['macrosId' => 'site_78', 'spending' => '$1 001.25', 'views' => '10', 'clicks' => '1'],
            ['macrosId' => 'site_79', 'spending' => '$0.00', 'views' => '3', 'clicks' => '0'],
        ]]];
    },
]);
$kd = KadamEngine::fetchRecords(['api_token' => 'T', 'timezone' => 'Asia/Jakarta'], $today, $today);
check('zone rows parsed, zero-spend zone dropped', count($kd) === 2, json_encode($kd));
check('zone id = macrosId', ($kd[0]['zone_id'] ?? '') === 'site_77');
check('campaign id carried', ($kd[0]['source_campaign_id'] ?? '') === '955714');
check('campaign name carried', ($kd[0]['campaign_name'] ?? '') === 'KB0410 | KU0809A');
check('"$2.25" parsed', abs((float) ($kd[0]['amount'] ?? 0) - 2.25) < 0.0001);
check('"$1 001.25" (space thousands) parsed', abs((float) ($kd[1]['amount'] ?? 0) - 1001.25) < 0.0001, (string) ($kd[1]['amount'] ?? ''));
check('"1 200" views parsed', ($kd[0]['impressions'] ?? 0) === 1200);
check('external id = kd_campaign_zone_day', ($kd[0]['external_id'] ?? '') === 'kd_955714_site_77_' . $today);
$dayCalls = array_values(array_filter($httpCalls, fn($c) => $c['path'] === '/stats/sites' && ($c['params']['perPage'] ?? 0) > 1));
check('idle campaign skipped after its window total', count($dayCalls) === 1 && ($dayCalls[0]['params']['filters']['campaignIds'][0] ?? 0) === 955714, json_encode($dayCalls));
check('single-day request', ($dayCalls[0]['params']['filters']['dateFrom'] ?? '') === $today && ($dayCalls[0]['params']['filters']['dateTo'] ?? '') === $today);
check('timezone sent as the account offset in hours (Jakarta = 7)', ($dayCalls[0]['params']['filters']['timezone'] ?? null) === 7);
check('campaign list requested with POST', ($httpCalls[0]['method'] ?? '') === 'POST' && ($httpCalls[0]['path'] ?? '') === '/campaigns');

fakeHttp('KadamEngine', ['/me' => ['success' => true, 'data' => ['balance' => 32.28, 'currency' => 'usd']]]);
$t = KadamEngine::testConnection(['api_token' => 'T']);
check('test connection reads balance', $t['success'] && str_contains($t['message'], '32.28') && str_contains($t['message'], 'USD'), json_encode($t));
fakeHttp('KadamEngine', ['/me' => ['success' => false, 'code' => 401, 'msg' => 'Invalid token']]);
$t = KadamEngine::testConnection(['api_token' => 'T']);
check('success=false surfaces as an error', !$t['success'] && str_contains($t['message'], 'Invalid token'), json_encode($t));
check('missing token fails fast', !KadamEngine::testConnection([])['success']);

// ---- Galaxion ----------------------------------------------------------------

echo "\nGalaxionEngine\n";

fakeHttp('GalaxionEngine', [
    '/v1/auth' => ['token' => 'TOK123'],
    '/v1/advertiser/statistics' => ['rows' => [
        ['id' => 0, 'campaign' => '2192879 - GB041010M', 'campaignId' => 2192879, 'zone' => 364306, 'money' => 21.189825678474, 'impressions' => 142725, 'clicks' => 0],
        ['id' => 1, 'campaign' => '1729668 - GB041004 | GU0405X', 'zone' => 99, 'money' => 0.5, 'impressions' => 10, 'clicks' => 0],
        ['id' => 2, 'campaign' => '2192879 - GB041010M', 'campaignId' => 2192879, 'zone' => 1, 'money' => 0, 'impressions' => 5, 'clicks' => 0],
    ]],
]);
$gx = GalaxionEngine::fetchRecords(['email' => 'a@b.c', 'password' => 'p'], $today, $today);
check('login with e-mail + password first', ($httpCalls[0]['path'] ?? '') === '/v1/auth' && ($httpCalls[0]['params']['email'] ?? '') === 'a@b.c');
check('stats call carries the token', ($httpCalls[1]['credentials']['token'] ?? '') === 'TOK123');
check('zero-spend row dropped', count($gx) === 2, json_encode($gx));
check('campaign × zone parsed', ($gx[0]['source_campaign_id'] ?? '') === '2192879' && ($gx[0]['zone_id'] ?? '') === '364306');
check('campaign id recovered from "id - name" when campaignId is absent', ($gx[1]['source_campaign_id'] ?? '') === '1729668');
check('name stripped of the id prefix', ($gx[1]['campaign_name'] ?? '') === 'GB041004 | GU0405X');
$q = $httpCalls[1]['params'] ?? [];
check('full datetimes + mandatory orderBy + groupBy[]',
    ($q['dateFrom'] ?? '') === $today . ' 00:00:00' && ($q['dateTo'] ?? '') === $today . ' 23:59:59'
    && ($q['orderBy[field]'] ?? '') === 'money' && ($q['groupBy[0]'] ?? '') === 'campaign' && ($q['groupBy[1]'] ?? '') === 'zone',
    json_encode($q));

fakeHttp('GalaxionEngine', ['/v1/advertiser/statistics' => ['rows' => []]]);
GalaxionEngine::fetchRecords(['api_token' => 'READY', 'email' => 'x', 'password' => 'y'], $yday, $today);
check('a pasted token skips the login', count(array_filter($httpCalls, fn($c) => $c['path'] === '/v1/auth')) === 0);
check('one request per day', count($httpCalls) === 2);

fakeHttp('GalaxionEngine', ['/v1/advertiser/statistics' => ['rows' => array_fill(0, 10000, ['campaignId' => 1, 'zone' => 2, 'money' => 0.01])]]);
$threw = '';
try {
    GalaxionEngine::fetchRecords(['api_token' => 'T'], $today, $today);
} catch (\RuntimeException $e) {
    $threw = $e->getMessage();
}
check('a full page is an error, not a silently short day', str_contains($threw, 'incomplete'), $threw);

$t = GalaxionEngine::testConnection([]);
check('no credentials → clear message', !$t['success'] && str_contains($t['message'], 'e-mail and password'), json_encode($t));
fakeHttp('GalaxionEngine', ['/v1/advertiser/balance' => ['balance' => 3769.23]]);
$t = GalaxionEngine::testConnection(['api_token' => 'T']);
check('test connection reads balance', $t['success'] && str_contains($t['message'], '3769.23'), json_encode($t));

// ---- Adsterra ----------------------------------------------------------------

echo "\nAdsterraEngine\n";

fakeHttp('AdsterraEngine', [
    '/advertiser/campaigns.json' => ['items' => [['id' => 1509244, 'alias' => 'AB0509']]],
    '/advertiser/stats.json' => ['items' => [
        ['impressions' => 25545, 'clicks' => 0, 'spent' => 2.8, 'campaign' => 1509244],
        ['impressions' => 10, 'clicks' => 0, 'spent' => 0, 'campaign' => 1509245],
    ], 'timezone' => 'GMT+00:00'],
]);
$as = AdsterraEngine::fetchRecords(['api_key' => 'K'], $yday, $today);
$statCalls = array_values(array_filter($httpCalls, fn($c) => $c['path'] === '/advertiser/stats.json'));
check('one request per day, single-day range', count($statCalls) === 2
    && $statCalls[0]['params']['start_date'] === $yday && $statCalls[0]['params']['finish_date'] === $yday, json_encode($statCalls));
check('group_by=campaign (placement rows carry no campaign id)', ($statCalls[0]['params']['group_by'] ?? '') === 'campaign');
check('zero-spend campaign dropped, one row per day', count($as) === 2, json_encode($as));
check('campaign-level row (zone_id empty)', ($as[0]['zone_id'] ?? 'x') === '' && ($as[0]['source_campaign_id'] ?? '') === '1509244');
check('alias used as campaign name', ($as[0]['campaign_name'] ?? '') === 'AB0509');
check('each row dated by its own day', ($as[0]['date'] ?? '') === $yday && ($as[1]['date'] ?? '') === $today);
fakeHttp('AdsterraEngine', ['/advertiser/balance.json' => ['item' => '330.43']]);
$t = AdsterraEngine::testConnection(['api_key' => 'K']);
check('test connection reads {item: balance}', $t['success'] && str_contains($t['message'], '330.43'), json_encode($t));
fakeHttp('AdsterraEngine', ['/advertiser/balance.json' => ['error' => 'HTTP 401 — the API key was rejected']]);
check('rejected key fails the test', !AdsterraEngine::testConnection(['api_key' => 'K'])['success']);

// ---- Clickadu ----------------------------------------------------------------

echo "\nClickaduEngine\n";

fakeHttp('ClickaduEngine', [
    '/v1.0/api/client/statistics/' => ['result' => ['page' => 1, 'items' => [
        ['impressions' => 31253, 'clicks' => 0, 'campaignId' => 3947584, 'campaignName' => 'CB061003 | CU0206X', 'spent' => 2.61],
        ['impressions' => 5, 'clicks' => 0, 'campaignId' => 3947585, 'campaignName' => 'idle', 'spent' => 0],
    ]]],
]);
$ca = ClickaduEngine::fetchRecords(['api_key' => 'K'], $today, $today);
check('campaign rows parsed, zero spend dropped', count($ca) === 1, json_encode($ca));
check('campaign id / name / spend', ($ca[0]['source_campaign_id'] ?? '') === '3947584' && ($ca[0]['campaign_name'] ?? '') === 'CB061003 | CU0206X'
    && abs((float) ($ca[0]['amount'] ?? 0) - 2.61) < 0.0001);
check('campaign-level row (zone_id empty)', ($ca[0]['zone_id'] ?? 'x') === '');
$q = $httpCalls[0]['params'] ?? [];
check('dateFrom/dateTill single day + groupBy=campaignId', ($q['dateFrom'] ?? '') === $today && ($q['dateTill'] ?? '') === $today && ($q['groupBy'] ?? '') === 'campaignId', json_encode($q));
fakeHttp('ClickaduEngine', ['/api/v2/client/balance/' => ['result' => ['advertiser' => ['balance' => '367.16']]]]);
$t = ClickaduEngine::testConnection(['api_key' => 'K']);
check('test connection reads result.advertiser.balance', $t['success'] && str_contains($t['message'], '367.16'), json_encode($t));
fakeHttp('ClickaduEngine', ['/api/v2/client/balance/' => ['errors' => ['Unauthorized']]]);
$t = ClickaduEngine::testConnection(['api_key' => 'K']);
check('error body without result is a failure', !$t['success'] && str_contains($t['message'], 'Unauthorized'), json_encode($t));

// ---- Summary -----------------------------------------------------------------

echo "\n" . str_repeat('-', 60) . "\n";
echo "passed: $passed   failed: $failed\n";
exit($failed === 0 ? 0 : 1);
