<?php
/**
 * Orbitra MCP over HTTP — the endpoint you paste into Claude's
 * "Add custom connector" dialog.
 *
 *     https://your-tracker.example.com/mcp.php?k=<api_key>
 *
 * Why the key sits in the query string: that dialog accepts a URL and nothing
 * else — no header field, and its only auth mechanism is OAuth. So the URL is
 * the credential. Treat it like one: it is shown once in the panel, it can be
 * revoked from Users -> API Keys, and a read-scoped key cannot change anything.
 *
 * Why this exists alongside mcp/src/index.js: the Node server speaks stdio and
 * therefore only works with desktop clients that can launch a local process.
 * Browser clients can only talk to a remote HTTPS endpoint, which is this file.
 * Both expose the same tools — mcp/tools.json is generated from the Node server
 * (see mcp/src/manifest.js), so the two cannot drift apart unnoticed.
 *
 * Transport: MCP Streamable HTTP, stateless. Every POST is a self-contained
 * JSON-RPC exchange; no session id is issued, so there is nothing to resume and
 * nothing to keep in memory between requests.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/version.php';

const MCP_PROTOCOL_VERSION = '2025-06-18';
const MCP_SUPPORTED_PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

// ---------------------------------------------------------------------------
// Transport plumbing
// ---------------------------------------------------------------------------

function mcpJson($payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mcpError($id, int $code, string $message, $data = null): array
{
    $err = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $err['data'] = $data;
    }
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $err];
}

/** $result may be an object so an empty result encodes as {} rather than []. */
function mcpResult($id, $result): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

/** Tool results are content blocks; isError reports a failure the model should see. */
function mcpToolText($text, bool $isError = false): array
{
    return [
        'content' => [['type' => 'text', 'text' => is_string($text) ? $text : json_encode($text, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
        'isError' => $isError,
    ];
}

// The in-process fallback includes api.php, which exits outright on some paths.
// That would end the request with a bare API payload where the client expects a
// JSON-RPC envelope, so whatever was produced is wrapped up here instead.
register_shutdown_function(function () {
    if (empty($GLOBALS['ORBITRA_MCP_INFLIGHT'])) {
        return;
    }
    $GLOBALS['ORBITRA_MCP_INFLIGHT'] = false;
    $chunks = [];
    while (ob_get_level() > 0) {
        $chunks[] = (string) ob_get_clean();
    }
    $out = implode('', array_reverse($chunks));

    $decoded = json_decode($out, true);
    $text = is_array($decoded)
        ? (($decoded['status'] ?? '') === 'error'
            ? 'Error: Orbitra API error: ' . ($decoded['message'] ?? 'unknown')
            : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        : 'Error: Orbitra returned a non-JSON response. ' . substr(trim($out), 0, 300);

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => $GLOBALS['ORBITRA_MCP_RPC_ID'] ?? null,
        'result' => [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => !is_array($decoded) || ($decoded['status'] ?? '') === 'error',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

// ---------------------------------------------------------------------------
// Executing a tool: one CLI dispatch of api.php per api call
// ---------------------------------------------------------------------------

/**
 * Locate a PHP CLI binary. Under FPM, PHP_BINARY points at the FPM daemon, so it
 * cannot be used directly. Cron already relies on a CLI php being present.
 */
function mcpPhpBinary(PDO $pdo): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    try {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'mcp_php_binary' LIMIT 1");
        $stmt->execute();
        $configured = trim((string) $stmt->fetchColumn());
        if ($configured !== '' && @is_executable($configured)) {
            return $resolved = $configured;
        }
    } catch (\Throwable $e) {
        // settings table unavailable — fall through to discovery
    }

    if (PHP_SAPI === 'cli' && @is_executable(PHP_BINARY)) {
        return $resolved = PHP_BINARY;
    }
    foreach ([PHP_BINDIR . '/php', '/usr/bin/php', '/usr/local/bin/php'] as $candidate) {
        if (@is_executable($candidate)) {
            return $resolved = $candidate;
        }
    }
    return $resolved = 'php';
}

/** Is $name callable, i.e. neither undefined nor in disable_functions? */
function mcpFunctionAvailable(string $name): bool
{
    static $disabled = null;
    if ($disabled === null) {
        $disabled = array_map(
            'strtolower',
            array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ini_get('disable_functions'))))
        );
    }
    return function_exists($name) && !in_array(strtolower($name), $disabled, true);
}

/**
 * Run one api.php action in this very process.
 *
 * The fallback for hosts that disable proc_open() — a common shared-hosting
 * default, and the reason the endpoint used to connect fine and then fail on
 * every single tool call.
 *
 * api.php declares its functions at file scope, so it can only be included once
 * per request; a second include would be a redeclaration fatal. One dispatch per
 * request is therefore the hard limit here, which covers every tool that forwards
 * a single call — all but three of them.
 */
function mcpCallApiInProcess(string $apiKey, string $method, string $action, array $params, array $body): array
{
    // api.php is included here, so it runs in this function's scope — and its own
    // require_once of config.php is a no-op by then, since mcp.php already loaded
    // it. Without these, $pdo would simply be null inside every handler.
    global $pdo, $db_file, $postback_key;

    static $spent = false;
    if ($spent) {
        throw new RuntimeException(
            'This server disables proc_open(), so the HTTP endpoint can only run one api.php dispatch per request, '
            . 'and "' . $action . '" needs several. Allow proc_open() in php.ini (remove it from disable_functions), '
            . 'or use the local server in mcp/ which has no such limit.'
        );
    }
    $spent = true;

    $_GET = ['action' => $action];
    foreach ($params as $k => $v) {
        if ($v === null || $v === '' || is_array($v) || is_object($v)) {
            continue;
        }
        $_GET[(string) $k] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
    }
    $_POST = [];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = '/api.php?' . http_build_query($_GET);
    $_SERVER['SCRIPT_NAME'] = '/api.php';
    $_SERVER['HTTP_X_API_KEY'] = $apiKey;
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $apiKey;
    $GLOBALS['ORBITRA_INTERNAL_REQUEST_BODY'] = $method === 'POST'
        ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : '';

    // api.php exits outright on some paths (auth failures, rate limiting). That
    // would end this request mid-JSON-RPC, so the buffer is handed to a shutdown
    // handler that still closes the envelope properly.
    $GLOBALS['ORBITRA_MCP_INFLIGHT'] = true;
    ob_start();
    try {
        require __DIR__ . '/api.php';
    } finally {
        $out = ob_get_clean();
        $GLOBALS['ORBITRA_MCP_INFLIGHT'] = false;
        unset($GLOBALS['ORBITRA_INTERNAL_REQUEST_BODY']);
    }

    $decoded = json_decode((string) $out, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(
            'Orbitra returned a non-JSON response for "' . $action . '". ' . substr(trim((string) $out), 0, 300)
        );
    }
    if (($decoded['status'] ?? '') === 'error') {
        throw new RuntimeException('Orbitra API error: ' . ($decoded['message'] ?? 'unknown'));
    }
    return $decoded;
}

/**
 * Run one api.php action and return its decoded JSON.
 * Throws on transport-level trouble; API-level errors come back in the payload.
 */
function mcpCallApi(PDO $pdo, string $apiKey, string $method, string $action, array $params = [], array $body = []): array
{
    // proc_open can be missing, listed in disable_functions, or present but unable
    // to spawn anything (restricted containers, open_basedir, no CLI binary). All
    // three end up on the in-process path; the third is only detectable by trying.
    static $spawnWorks = null;
    if ($spawnWorks === false || !mcpFunctionAvailable('proc_open')) {
        return mcpCallApiInProcess($apiKey, $method, $action, $params, $body);
    }

    $payload = json_encode([
        'method' => $method,
        'action' => $action,
        'params' => $params,
        'body' => $body,
        'api_key' => $apiKey,
        'host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = [mcpPhpBinary($pdo), __DIR__ . '/cli/api_invoke.php'];
    $process = @proc_open($cmd, $descriptors, $pipes, __DIR__);
    if (!is_resource($process)) {
        $spawnWorks = false;
        return mcpCallApiInProcess($apiKey, $method, $action, $params, $body);
    }

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        // Nothing on stdout and a non-zero exit means the CLI binary never ran —
        // wrong path, open_basedir, whatever. Take the in-process route instead of
        // reporting a failure the user cannot act on.
        if (trim((string) $stdout) === '' && $exit !== 0) {
            $spawnWorks = false;
            return mcpCallApiInProcess($apiKey, $method, $action, $params, $body);
        }
        $hint = trim((string) $stderr) !== '' ? trim((string) $stderr) : substr(trim((string) $stdout), 0, 300);
        throw new RuntimeException(
            'Orbitra returned a non-JSON response for "' . $action . '" (exit ' . $exit . '). ' . $hint
        );
    }
    $spawnWorks = true;
    if (($decoded['status'] ?? '') === 'error') {
        throw new RuntimeException('Orbitra API error: ' . ($decoded['message'] ?? 'unknown'));
    }
    return $decoded;
}

/** Mirror of normalizeStreamForSave() in mcp/src/index.js. */
function mcpNormalizeStream(array $s): array
{
    $parse = function ($v, $fallback) {
        if ($v === null || $v === '') {
            return $fallback;
        }
        if (is_array($v)) {
            return $v;
        }
        $decoded = json_decode((string) $v, true);
        return is_array($decoded) ? $decoded : $fallback;
    };
    return [
        'offer_id' => $s['offer_id'] ?? null,
        'weight' => $s['weight'] ?? 100,
        'is_active' => $s['is_active'] ?? 1,
        'type' => $s['type'] ?? 'regular',
        'position' => $s['position'] ?? 0,
        'filters' => $s['filters'] ?? $parse($s['filters_json'] ?? null, []),
        'schema_type' => $s['schema_type'] ?? 'redirect',
        'action_payload' => $s['action_payload'] ?? '',
        'schema_custom' => $s['schema_custom'] ?? $parse($s['schema_custom_json'] ?? null, []),
    ];
}

/**
 * The four tools that are more than a single forwarded call. Their Node
 * counterparts live in mcp/src/index.js; mcp/tools.json records the steps each
 * one must perform so the two implementations stay comparable.
 */
function mcpRunHandler(PDO $pdo, string $apiKey, string $handler, array $args)
{
    switch ($handler) {
        case 'update_offer':
            $id = (int) ($args['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('id is required.');
            }
            $current = mcpCallApi($pdo, $apiKey, 'GET', 'get_offer', ['id' => $id])['data'] ?? null;
            if (!$current) {
                throw new RuntimeException("Offer {$id} not found.");
            }
            return mcpCallApi($pdo, $apiKey, 'POST', 'save_offer', [], array_merge($current, $args));

        case 'update_campaign':
            $id = (int) ($args['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('id is required.');
            }
            $current = mcpCallApi($pdo, $apiKey, 'GET', 'get_campaign', ['id' => $id])['data'] ?? null;
            if (!$current) {
                throw new RuntimeException("Campaign {$id} not found.");
            }
            $streams = $args['streams'] ?? $current['streams'] ?? [];
            $payload = array_merge($current, $args);
            $payload['streams'] = array_map(
                fn($s) => mcpNormalizeStream(is_array($s) ? $s : []),
                is_array($streams) ? $streams : []
            );
            return mcpCallApi($pdo, $apiKey, 'POST', 'save_campaign', [], $payload);

        case 'bulk_create_campaigns':
            $items = $args['campaigns'] ?? [];
            if (!is_array($items) || !$items) {
                throw new RuntimeException('campaigns must be a non-empty array.');
            }
            $results = [];
            $created = 0;
            foreach ($items as $c) {
                $c = is_array($c) ? $c : [];
                try {
                    $res = mcpCallApi($pdo, $apiKey, 'POST', 'save_campaign', [], $c);
                    $results[] = ['name' => $c['name'] ?? null, 'alias' => $c['alias'] ?? null, 'ok' => true, 'data' => $res['data'] ?? $res];
                    $created++;
                } catch (\Throwable $e) {
                    $results[] = ['name' => $c['name'] ?? null, 'alias' => $c['alias'] ?? null, 'ok' => false, 'error' => $e->getMessage()];
                }
            }
            return ['requested' => count($items), 'created' => $created, 'failed' => count($items) - $created, 'results' => $results];

        case 'api_request':
            $action = trim((string) ($args['action'] ?? ''));
            if ($action === '') {
                throw new RuntimeException('action is required.');
            }
            $method = strtoupper((string) ($args['method'] ?? 'GET')) === 'POST' ? 'POST' : 'GET';
            $params = is_array($args['params'] ?? null) ? $args['params'] : [];
            $body = is_array($args['body'] ?? null) ? $args['body'] : [];
            return mcpCallApi($pdo, $apiKey, $method, $action, $params, $body);
    }
    throw new RuntimeException('Unknown handler: ' . $handler);
}

// ---------------------------------------------------------------------------
// Tool manifest
// ---------------------------------------------------------------------------

function mcpTools(): array
{
    static $tools = null;
    if ($tools !== null) {
        return $tools;
    }
    $file = __DIR__ . '/mcp/tools.json';
    $manifest = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($manifest) || empty($manifest['tools'])) {
        throw new RuntimeException(
            'mcp/tools.json is missing or unreadable. Regenerate it with: cd mcp && node src/manifest.js'
        );
    }
    $tools = [];
    foreach ($manifest['tools'] as $t) {
        if (!empty($t['name'])) {
            // Older versions of the manifest generator emitted JSON-Schema object
            // fields as arrays for parameter-less tools (e.g. "properties": []).
            // The MCP spec requires these to be records, so normalise defensively
            // at load time instead of depending on the file being regenerated.
            if (isset($t['inputSchema']) && is_array($t['inputSchema'])) {
                $t['inputSchema'] = mcpNormalizeSchema($t['inputSchema']);
            }
            $tools[$t['name']] = $t;
        }
    }
    return $tools;
}

/**
 * JSON-Schema fields that the spec defines as objects. json_decode(..., true)
 * turns an empty {} into an empty PHP array, and json_encode turns an empty
 * PHP array back into "[]" — not "{}" — so MCP clients that validate against
 * the schema reject these as "expected record, received array". Coerce them to
 * stdClass so they always serialise as objects, and recurse into nested schemas.
 */
function mcpNormalizeSchema(array $schema): array
{
    // Fields that MUST be objects. If empty, encode as {} (stdClass); if
    // already a populated associative array, leave intact and recurse below.
    foreach (['properties', 'patternProperties', '$defs', 'definitions'] as $key) {
        if (array_key_exists($key, $schema)) {
            if (!is_array($schema[$key]) || $schema[$key] === []) {
                $schema[$key] = new stdClass();
            } elseif (array_is_list($schema[$key])) {
                // A list here is malformed; there is no faithful object form.
                $schema[$key] = new stdClass();
            }
        }
    }

    // additionalProperties may be a boolean or a schema object. An array value
    // (incl. the empty array) is not valid for the empty-object case, so coerce
    // empties to "true" (allow any) — the permissive, safe interpretation.
    if (array_key_exists('additionalProperties', $schema)) {
        $ap = $schema['additionalProperties'];
        if (is_array($ap)) {
            $schema['additionalProperties'] = $ap === [] ? true : mcpNormalizeSchema($ap);
        }
    }

    foreach ($schema as $k => $v) {
        if (is_array($v) && !array_is_list($v)) {
            $schema[$k] = mcpNormalizeSchema($v);
        } elseif (is_array($v)) {
            // Lists of subschemas: anyOf, oneOf, allOf, items-as-list, prefixItems.
            $schema[$k] = array_map('mcpNormalizeSchemaWrapped', $v);
        }
    }
    return $schema;
}

function mcpNormalizeSchemaWrapped($v)
{
    return is_array($v) && !array_is_list($v) ? mcpNormalizeSchema($v) : $v;
}

// ---------------------------------------------------------------------------
// Request handling
// ---------------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    header('Allow: POST, OPTIONS');
    http_response_code(204);
    exit;
}

// Stateless: there is no long-lived stream to attach to, so the optional SSE
// channel a client may open with GET has nothing to carry.
if ($method === 'GET' || $method === 'DELETE') {
    header('Allow: POST, OPTIONS');
    mcpJson(['error' => 'This endpoint is stateless MCP over HTTP; send JSON-RPC with POST.'], 405);
}

if ($method !== 'POST') {
    header('Allow: POST, OPTIONS');
    mcpJson(['error' => 'Method not allowed.'], 405);
}

// --- authenticate -----------------------------------------------------------
$providedKey = trim((string) ($_GET['k'] ?? $_GET['key'] ?? ''));
if ($providedKey === '') {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($hdr !== '' && preg_match('/Bearer\s+(\S+)/i', $hdr, $m)) {
        $providedKey = trim($m[1]);
    } elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
        $providedKey = trim((string) $_SERVER['HTTP_X_API_KEY']);
    }
}

if ($providedKey === '') {
    mcpJson([
        'error' => 'Missing API key. The connector URL must carry it: https://your-tracker/mcp.php?k=YOUR_KEY '
            . '(generate one in Users -> API Keys).',
    ], 401);
}

try {
    $stmt = $pdo->prepare(
        "SELECT k.id, k.user_id, k.permissions, u.username
         FROM user_api_keys k JOIN users u ON u.id = k.user_id
         WHERE k.api_key = ? LIMIT 1"
    );
    $stmt->execute([$providedKey]);
    $keyRow = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    mcpJson(['error' => 'Database unavailable.'], 500);
}

if (!$keyRow) {
    mcpJson(['error' => 'Invalid API key.'], 401);
}

$keyPermissions = strtolower((string) ($keyRow['permissions'] ?? 'read'));
$canWrite = $keyPermissions === 'write' || $keyPermissions === 'full';

try {
    $pdo->prepare("UPDATE user_api_keys SET last_used = datetime('now') WHERE id = ?")->execute([$keyRow['id']]);
} catch (\Throwable $e) {
    // last_used is a nicety, never a reason to fail the call
}

// --- parse JSON-RPC ---------------------------------------------------------
$rawBody = (string) file_get_contents('php://input');
$request = json_decode($rawBody, true);
if (!is_array($request)) {
    mcpJson(mcpError(null, -32700, 'Parse error: body is not valid JSON.'), 400);
}

$isBatch = array_is_list($request) && $request !== [];
$messages = $isBatch ? $request : [$request];
$responses = [];

foreach ($messages as $msg) {
    if (!is_array($msg)) {
        $responses[] = mcpError(null, -32600, 'Invalid Request');
        continue;
    }
    $id = $msg['id'] ?? null;
    $rpcMethod = (string) ($msg['method'] ?? '');
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
    $isNotification = !array_key_exists('id', $msg);

    try {
        switch ($rpcMethod) {
            case 'initialize':
                $wanted = (string) ($params['protocolVersion'] ?? MCP_PROTOCOL_VERSION);
                $negotiated = in_array($wanted, MCP_SUPPORTED_PROTOCOLS, true) ? $wanted : MCP_PROTOCOL_VERSION;
                $responses[] = mcpResult($id, [
                    'protocolVersion' => $negotiated,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => [
                        'name' => 'orbitra',
                        'title' => 'Orbitra Tracker',
                        'version' => defined('ORBITRA_VERSION') ? ORBITRA_VERSION : '0.0.0',
                    ],
                    'instructions' => 'Orbitra traffic tracker. Read tools cover metrics, campaigns, offers, '
                        . 'conversions and reports; management tools create and edit campaigns, offers, domains, '
                        . 'traffic sources and landings. This connection uses a '
                        . ($canWrite ? 'write' : 'read-only')
                        . ' API key' . ($canWrite ? '.' : ', so management tools will be refused.'),
                ]);
                break;

            case 'notifications/initialized':
            case 'notifications/cancelled':
                break; // nothing to acknowledge

            case 'ping':
                $responses[] = mcpResult($id, new stdClass());
                break;

            case 'tools/list':
                $list = [];
                foreach (mcpTools() as $t) {
                    $list[] = [
                        'name' => $t['name'],
                        'description' => $t['description'] ?? '',
                        'inputSchema' => $t['inputSchema'] ?? ['type' => 'object'],
                    ];
                }
                $responses[] = mcpResult($id, ['tools' => $list]);
                break;

            case 'tools/call':
                $name = (string) ($params['name'] ?? '');
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                $tools = mcpTools();
                if (!isset($tools[$name])) {
                    $responses[] = mcpError($id, -32602, 'Unknown tool: ' . $name);
                    break;
                }
                $tool = $tools[$name];
                // Remembered for the shutdown handler, in case the in-process
                // dispatch below is cut short by an exit() inside api.php.
                $GLOBALS['ORBITRA_MCP_RPC_ID'] = $id;

                // Refuse writes early with an explanation the model can act on,
                // rather than letting api.php answer with a bare 403.
                $writes = ($tool['method'] ?? '') === 'POST' || isset($tool['handler']) && $tool['handler'] !== 'api_request';
                if ($writes && !$canWrite) {
                    $responses[] = mcpResult($id, mcpToolText(
                        'This connection uses a read-only API key, so "' . $name . '" is not permitted. '
                        . 'Generate a Write key in Users -> API Keys and reconnect with it.',
                        true
                    ));
                    break;
                }

                try {
                    if (isset($tool['handler'])) {
                        $data = mcpRunHandler($pdo, $providedKey, (string) $tool['handler'], $args);
                    } elseif (($tool['method'] ?? 'GET') === 'POST') {
                        $data = mcpCallApi($pdo, $providedKey, 'POST', (string) $tool['action'], [], $args);
                    } else {
                        $data = mcpCallApi($pdo, $providedKey, 'GET', (string) $tool['action'], $args);
                    }
                    $responses[] = mcpResult($id, mcpToolText($data));
                } catch (\Throwable $e) {
                    // A failing tool is a result, not a protocol error: the model
                    // should see the message and be able to correct its arguments.
                    $responses[] = mcpResult($id, mcpToolText('Error: ' . $e->getMessage(), true));
                }
                break;

            default:
                if (!$isNotification) {
                    $responses[] = mcpError($id, -32601, 'Method not found: ' . $rpcMethod);
                }
        }
    } catch (\Throwable $e) {
        if (!$isNotification) {
            $responses[] = mcpError($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }
}

// Notifications alone get an empty 202, per the Streamable HTTP transport.
if (!$responses) {
    http_response_code(202);
    exit;
}

mcpJson($isBatch ? $responses : $responses[0]);
