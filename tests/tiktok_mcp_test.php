<?php
/**
 * tests/tiktok_mcp_test.php
 *
 * Covers the TikTok for Business MCP connect path (no developer app):
 * TikTokMcpClient PKCE/authorize/DCR/token exchange/tool parsing through the
 * test HTTP seam, the MCP branch of TikTokAdsEngine (report via
 * report_integrated_get, advertiser_info_get currency, MCP token refresh with
 * sibling propagation), and the no-client-registration failure mode.
 *
 * Run from the project root:
 *
 *     php tests/tiktok_mcp_test.php
 *
 * No network: every TikTokMcpClient request is replaced by a handler.
 */

require_once __DIR__ . '/../aggregator_engines/TikTokAdsEngine.php';
require_once __DIR__ . '/../core/TikTokMcpClient.php';

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

function freshPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
    $pdo->exec("CREATE TABLE aggregator_connections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT, engine TEXT, auth_type TEXT, credentials_json TEXT,
        sync_interval_hours INTEGER DEFAULT 2, is_active INTEGER DEFAULT 1
    )");
    $pdo->exec("CREATE TABLE pixel_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        traffic_source TEXT, niche TEXT, name TEXT, pixel_id TEXT,
        token TEXT, is_active INTEGER DEFAULT 1
    )");
    $pdo->exec("CREATE TABLE campaign_pixels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER, pixel_profile_id INTEGER, type TEXT,
        pixel_id TEXT, token TEXT, is_active INTEGER DEFAULT 1
    )");
    return $pdo;
}

function insertConnection(PDO $pdo, array $credentials): int
{
    $stmt = $pdo->prepare("INSERT INTO aggregator_connections (name, engine, auth_type, credentials_json) VALUES ('conn', 'tiktok', 'oauth', ?)");
    $stmt->execute([json_encode($credentials, JSON_UNESCAPED_SLASHES)]);
    return (int) $pdo->lastInsertId();
}

function connectionCredentials(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("SELECT credentials_json FROM aggregator_connections WHERE id = ?");
    $stmt->execute([$id]);
    return json_decode((string) $stmt->fetchColumn(), true);
}

function toolResponse(array $inner): array
{
    return ['status' => 200, 'error' => null, 'body' => json_encode([
        'jsonrpc' => '2.0',
        'result' => ['content' => [['type' => 'text', 'text' => json_encode($inner)]]],
    ])];
}

// ---- PKCE -------------------------------------------------------------------

$pkce = TikTokMcpClient::pkcePair();
check('PKCE verifier is base64url', preg_match('/^[A-Za-z0-9_-]{64}$/', $pkce['verifier']) === 1, $pkce['verifier']);
$expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $pkce['verifier'], true)), '+/', '-_'), '=');
check('PKCE challenge is S256(verifier)', $pkce['challenge'] === $expectedChallenge);

$url = TikTokMcpClient::authorizeUrl('CID1', 'https://panel.example/cb', 'STATE123', 'CHALLENGE');
$query = [];
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
check('authorize URL targets the MCP consent screen', strpos($url, 'business-api.tiktok.com/portal/mcp-tt4b-authorize') !== false, $url);
check('authorize URL carries client/redirect/scope/state/PKCE',
    $query['client_id'] === 'CID1'
    && $query['response_type'] === 'code'
    && $query['redirect_uri'] === 'https://panel.example/cb'
    && $query['scope'] === 'mcp:tt4b'
    && $query['state'] === 'STATE123'
    && $query['code_challenge'] === 'CHALLENGE'
    && $query['code_challenge_method'] === 'S256'
    && ($query['resource'] ?? '') === TikTokMcpClient::MCP_URL,
    json_encode($query));

// ---- DCR: ensureClient registers once, caches in settings -------------------

$pdo = freshPdo();
$registerCalls = 0;
TikTokMcpClient::$testHttpHandler = function (string $method, string $url, $params, array $ctx) use (&$registerCalls) {
    if ($ctx['kind'] !== 'register') {
        return ['status' => 500, 'body' => '', 'error' => 'unexpected kind ' . $ctx['kind']];
    }
    $registerCalls++;
    return ['status' => 201, 'error' => null, 'body' => json_encode(['client_id' => 'CID-' . $registerCalls])];
};

try {
    $first = TikTokMcpClient::ensureClient($pdo, 'https://panel.example/api.php?action=tiktok_mcp_oauth_callback');
    check('first ensureClient registers and returns client_id', $first === 'CID-1', $first);

    $second = TikTokMcpClient::ensureClient($pdo, 'https://panel.example/api.php?action=tiktok_mcp_oauth_callback');
    check('second ensureClient reuses the stored client_id without network', $second === 'CID-1' && $registerCalls === 1, "$second / $registerCalls calls");

    $third = TikTokMcpClient::ensureClient($pdo, 'https://moved.example/api.php?action=tiktok_mcp_oauth_callback');
    check('changed redirect URI triggers re-registration', $third === 'CID-2' && $registerCalls === 2, "$third / $registerCalls calls");

    check('client_id persisted in settings', TikTokMcpClient::storedClientId($pdo) === 'CID-2');
} finally {
    TikTokMcpClient::$testHttpHandler = null;
}

$registerPayload = null;
TikTokMcpClient::$testHttpHandler = function (string $method, string $url, $params, array $ctx) use (&$registerPayload) {
    $registerPayload = $params;
    return ['status' => 201, 'error' => null, 'body' => json_encode(['client_id' => 'CID-X'])];
};
try {
    TikTokMcpClient::ensureClient($pdo, 'https://panel.example/cb');
    check('DCR register body is a public client for this redirect',
        $registerPayload['token_endpoint_auth_method'] === 'none'
        && $registerPayload['redirect_uris'] === ['https://panel.example/cb']
        && in_array('authorization_code', $registerPayload['grant_types'], true)
        && in_array('refresh_token', $registerPayload['grant_types'], true),
        json_encode($registerPayload));
} finally {
    TikTokMcpClient::$testHttpHandler = null;
}

// ---- Token exchange and refresh ---------------------------------------------

$tokenForms = [];
TikTokMcpClient::$testHttpHandler = function (string $method, string $url, $params, array $ctx) use (&$tokenForms) {
    $tokenForms[] = ['url' => $url, 'form' => $ctx['form']];
    if (($ctx['form']['grant_type'] ?? '') === 'authorization_code') {
        return ['status' => 200, 'error' => null, 'body' => json_encode([
            'access_token' => 'AT1', 'refresh_token' => 'RT1',
            'expires_in' => 86399, 'refresh_token_expires_in' => 2591941,
        ])];
    }
    if (($ctx['form']['error'] ?? '') !== '') {
        return ['status' => 400, 'error' => null, 'body' => json_encode(['error' => 'invalid_grant', 'error_description' => 'code already used'])];
    }
    return ['status' => 200, 'error' => null, 'body' => json_encode([
        'access_token' => 'AT2', 'refresh_token' => 'RT2',
        'expires_in' => 86399, 'refresh_token_expires_in' => 2591941,
    ])];
};

try {
    $tokens = TikTokMcpClient::exchangeCode('CID-1', 'https://panel.example/cb', 'AUTHCODE', 'VERIFIER');
    check('code exchange hits the MCP token endpoint', strpos($tokenForms[0]['url'], '/tt-ads-mcp-layer/oauth/token') !== false, $tokenForms[0]['url']);
    check('code exchange sends grant/code_verifier/client_id/redirect_uri (no secret)',
        $tokenForms[0]['form']['grant_type'] === 'authorization_code'
        && $tokenForms[0]['form']['code_verifier'] === 'VERIFIER'
        && $tokenForms[0]['form']['client_id'] === 'CID-1'
        && $tokenForms[0]['form']['redirect_uri'] === 'https://panel.example/cb'
        && ($tokenForms[0]['form']['resource'] ?? '') === TikTokMcpClient::MCP_URL
        && !isset($tokenForms[0]['form']['client_secret'])
        && !isset($tokenForms[0]['form']['secret']),
        json_encode($tokenForms[0]['form']));
    check('code exchange parses the token pair',
        $tokens['access_token'] === 'AT1' && $tokens['refresh_token'] === 'RT1' && $tokens['expires_in'] === 86399,
        json_encode($tokens));

    $refreshed = TikTokMcpClient::refreshToken([], 'CID-1', 'RT1');
    check('refresh returns the rotated token pair',
        $refreshed['access_token'] === 'AT2' && $refreshed['refresh_token'] === 'RT2' && $refreshed['refresh_token_expires_in'] === 2591941,
        json_encode($refreshed));
    check('refresh sends the RFC 8707 resource indicator',
        ($tokenForms[1]['form']['grant_type'] ?? '') === 'refresh_token'
        && ($tokenForms[1]['form']['resource'] ?? '') === TikTokMcpClient::MCP_URL,
        json_encode($tokenForms[1]['form'] ?? null));
} finally {
    TikTokMcpClient::$testHttpHandler = null;
}

TikTokMcpClient::$testHttpHandler = function () {
    return ['status' => 400, 'error' => null, 'body' => json_encode(['error' => 'invalid_grant', 'error_description' => 'code already used'])];
};
try {
    TikTokMcpClient::exchangeCode('CID-1', 'https://panel.example/cb', 'STALE', 'V');
    check('OAuth error surfaces with its description', false);
} catch (RuntimeException $e) {
    check('OAuth error surfaces with its description', strpos($e->getMessage(), 'code already used') !== false, $e->getMessage());
} finally {
    TikTokMcpClient::$testHttpHandler = null;
}

// ---- tools/call response parsing --------------------------------------------

TikTokMcpClient::$testHttpHandler = function () {
    return toolResponse(['code' => 0, 'message' => 'OK', 'data' => ['list' => [['name' => 'Acme']]]]);
};
try {
    $payload = TikTokMcpClient::callTool(['access_token' => 'AT'], 'advertiser_info_get', ['advertiser_ids' => ['1']]);
    check('plain JSON envelope unwraps to the inner Marketing API payload',
        ($payload['data']['list'][0]['name'] ?? '') === 'Acme' && $payload['code'] === 0,
        json_encode($payload));
} finally {
    TikTokMcpClient::$testHttpHandler = null;
}

TikTokMcpClient::$testHttpHandler = function () {
    $event = json_encode(['jsonrpc' => '2.0', 'result' => ['content' => [['type' => 'text', 'text' => '{"code":0,"data":{"list":[]}}']]]]);
    return ['status' => 200, 'error' => null, 'body' => "event: message\r\ndata: $event\r\n\r\n"];
};
try {
    $payload = TikTokMcpClient::callTool(['access_token' => 'AT'], 'auth_advertiser_get', []);
    check('SSE envelope unwraps the same way', ($payload['data']['list'] ?? null) === [] && $payload['code'] === 0, json_encode($payload));
} finally {
    TikTokMcpClient::$testHttpHandler = null;
}

TikTokMcpClient::$testHttpHandler = function () {
    return toolResponse(['code' => 40105, 'message' => 'not authorized']);
};
try {
    $payload = TikTokMcpClient::callTool(['access_token' => 'AT'], 'report_integrated_get', []);
    check('inner API error payload is returned, not swallowed',
        ($payload['code'] ?? -1) === 40105 && strpos($payload['message'] ?? '', 'not authorized') !== false,
        json_encode($payload));

    // The engine is the layer that turns code != 0 into an exception with a
    // human hint — an Events-Manager-style token must fail visibly here.
    TikTokAdsEngine::fetchRecords(['mcp' => 1, 'access_token' => 'AT', 'advertiser_id' => '1234567890'], '2026-10-09', '2026-10-09');
    check('engine turns an inner MCP error into an exception with the auth hint', false);
} catch (RuntimeException $e) {
    check('engine turns an inner MCP error into an exception with the auth hint',
        strpos($e->getMessage(), '40105') !== false && strpos($e->getMessage(), 'Marketing API') !== false,
        $e->getMessage());
} finally {
    TikTokMcpClient::$testHttpHandler = null;
}

TikTokMcpClient::$testHttpHandler = function () {
    return ['status' => 200, 'error' => null, 'body' => json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'tool not found']])];
};
try {
    TikTokMcpClient::callTool(['access_token' => 'AT'], 'no_such_tool', []);
    check('rpc-level error throws with the server message', false);
} catch (RuntimeException $e) {
    check('rpc-level error throws with the server message', strpos($e->getMessage(), 'tool not found') !== false, $e->getMessage());
} finally {
    TikTokMcpClient::$testHttpHandler = null;
}

// ---- Engine: MCP report fetch + currency + pagination -----------------------

$mcpCreds = [
    'mcp' => 1,
    'access_token' => 'MCP_AT',
    'refresh_token' => 'MCP_RT',
    'token_expires_at' => time() + 7200,
    'advertiser_id' => '1234567890',
];

$reportRequests = [];
TikTokMcpClient::$testHttpHandler = function (string $method, string $url, $params, array $ctx) use (&$reportRequests) {
    if ($ctx['kind'] !== 'tool') {
        return ['status' => 500, 'body' => '', 'error' => 'unexpected kind'];
    }
    $args = (array) $params['params']['arguments'];
    if ($ctx['tool'] === 'advertiser_info_get') {
        return toolResponse(['code' => 0, 'message' => 'OK', 'data' => ['list' => [['name' => 'Acme EU', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin']]]]);
    }
    if ($ctx['tool'] === 'report_integrated_get') {
        $reportRequests[] = ['args' => $args, 'headers' => $ctx['headers']];
        $page = (int) ($args['page'] ?? 1);
        $rows = $page === 1 ? [
            ['dimensions' => ['ad_id' => '9001', 'stat_time_day' => '2026-10-09 00:00:00'], 'metrics' => ['spend' => '12.34', 'campaign_id' => '777', 'adgroup_id' => '888', 'campaign_name' => 'Fall']],
        ] : [
            ['dimensions' => ['ad_id' => '9002', 'stat_time_day' => '2026-10-10 00:00:00'], 'metrics' => ['spend' => '5', 'campaign_id' => '777', 'adgroup_id' => '889', 'campaign_name' => 'Fall']],
        ];
        return toolResponse(['code' => 0, 'message' => 'OK', 'data' => [
            'list' => $rows,
            'page_info' => ['total_page' => 2],
        ]]);
    }
    return toolResponse(['code' => -1, 'message' => 'unexpected tool ' . $ctx['tool']]);
};

try {
    $records = TikTokAdsEngine::fetchRecords($mcpCreds, '2026-10-09', '2026-10-10');
    check('MCP fetch reads every report page', count($reportRequests) === 2
        && (int) $reportRequests[0]['args']['page'] === 1 && (int) $reportRequests[1]['args']['page'] === 2,
        json_encode(array_map(static fn($r) => $r['args']['page'] ?? null, $reportRequests)));
    check('report call carries the Bearer token and advertiser_id',
        in_array('Authorization: Bearer MCP_AT', $reportRequests[0]['headers'], true)
        && ($reportRequests[0]['args']['advertiser_id'] ?? '') === '1234567890',
        json_encode($reportRequests[0]));
    check('MCP records map to cost rows with advertiser currency',
        count($records) === 2
        && $records[0]['amount'] === 12.34
        && $records[0]['currency'] === 'EUR'
        && $records[0]['external_id'] === 'tt_777_9001_2026-10-09'
        && $records[0]['adset_id'] === '888',
        json_encode($records[0] ?? null));

    $test = TikTokAdsEngine::testConnection($mcpCreds);
    check('MCP testConnection validates via advertiser_info_get',
        $test['success'] === true && strpos($test['message'], 'EUR') !== false,
        json_encode($test));
} finally {
    TikTokMcpClient::$testHttpHandler = null;
}

// ---- Engine: MCP token refresh ----------------------------------------------

$expiredMcp = [
    'mcp' => 1,
    'access_token' => 'MCP_OLD',
    'refresh_token' => 'MCP_RT1',
    'token_expires_at' => time() - 60,
    'advertiser_id' => '1234567890',
];

$pdo = freshPdo();
$idA = insertConnection($pdo, $expiredMcp);
$idB = insertConnection($pdo, $expiredMcp); // same MCP login, second cabinet
$idDirect = insertConnection($pdo, [
    'access_token' => 'DIRECT_OLD', 'refresh_token' => 'DR', 'token_expires_at' => time() - 60,
    'app_id' => '700', 'app_secret' => 's', 'advertiser_id' => '555',
]);

try {
    TikTokAdsEngine::ensureFreshToken($pdo, connectionCredentials($pdo, $idA));
    check('expired MCP token without registration throws', false);
} catch (RuntimeException $e) {
    check('expired MCP token without registration throws', strpos($e->getMessage(), 'client registration') !== false, $e->getMessage());
}

$pdo->exec("INSERT INTO settings (key, value) VALUES ('tiktok_mcp_client_id', 'CID-1')");

TikTokAdsEngine::$testMcpRefreshHandler = function (array $creds) {
    return ['access_token' => 'MCP_NEW', 'refresh_token' => 'MCP_RT2', 'expires_in' => 86400, 'refresh_token_expires_in' => 2591941];
};
try {
    $returned = TikTokAdsEngine::ensureFreshToken($pdo, connectionCredentials($pdo, $idA));
    check('MCP refresh returns renewed credentials',
        $returned['access_token'] === 'MCP_NEW' && $returned['refresh_token'] === 'MCP_RT2' && $returned['token_expires_at'] > time() + 80000,
        json_encode($returned));

    $credsB = connectionCredentials($pdo, $idB);
    check('sibling MCP connection got the rotated pair',
        $credsB['access_token'] === 'MCP_NEW' && $credsB['refresh_token'] === 'MCP_RT2' && ($credsB['mcp'] ?? 0) === 1,
        json_encode($credsB));

    $credsDirect = connectionCredentials($pdo, $idDirect);
    check('direct-token connection untouched by MCP refresh', $credsDirect['access_token'] === 'DIRECT_OLD', json_encode($credsDirect));

    $again = TikTokAdsEngine::ensureFreshToken($pdo, connectionCredentials($pdo, $idA));
    check('fresh MCP token is not refreshed again', $again['access_token'] === 'MCP_NEW');
} finally {
    TikTokAdsEngine::$testMcpRefreshHandler = null;
}

TikTokAdsEngine::$testMcpRefreshHandler = function () {
    return ['access_token' => ''];
};
try {
    $dead = $expiredMcp;
    TikTokAdsEngine::ensureFreshToken($pdo, $dead);
    check('dead MCP refresh surfaces as an exception', false);
} catch (RuntimeException $e) {
    check('dead MCP refresh surfaces as an exception', strpos($e->getMessage(), 'no access token') !== false, $e->getMessage());
} finally {
    TikTokAdsEngine::$testMcpRefreshHandler = null;
}

// ---- api.php loads the client for every tiktok_mcp_* action -----------------
// v1.6.10 regression: only tiktok_mcp_oauth_start required the class, so the
// OAuth callback fataled with 'Class "TikTokMcpClient" not found' right after
// the user clicked Authorize. The require must sit at file scope, before any
// action dispatch.
$apiSource = (string) file_get_contents(__DIR__ . '/../api.php');
$requirePos = strpos($apiSource, "require_once __DIR__ . '/core/TikTokMcpClient.php';");
$firstUsePos = strpos($apiSource, 'TikTokMcpClient::');
check('api.php requires TikTokMcpClient at file scope before any action uses it',
    $requirePos !== false && $firstUsePos !== false && $requirePos < $firstUsePos
    && $requirePos < (int) strpos($apiSource, "case 'tiktok_mcp_oauth_start'"),
    'require at ' . var_export($requirePos, true) . ', first use at ' . var_export($firstUsePos, true));

echo PHP_EOL . ($failed === 0 ? "PASSED: $passed checks" : "FAILED: $failed of " . ($passed + $failed)) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
