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

/**
 * Run one api.php action and return its decoded JSON.
 * Throws on transport-level trouble; API-level errors come back in the payload.
 */
function mcpCallApi(PDO $pdo, string $apiKey, string $method, string $action, array $params = [], array $body = []): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException(
            'proc_open() is disabled on this server, so the HTTP MCP endpoint cannot dispatch tools. '
            . 'Remove it from disable_functions in php.ini, or use the local server in mcp/ instead.'
        );
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
        throw new RuntimeException('Could not start a PHP process to run "' . $action . '".');
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
        $hint = trim((string) $stderr) !== '' ? trim((string) $stderr) : substr(trim((string) $stdout), 0, 300);
        throw new RuntimeException(
            'Orbitra returned a non-JSON response for "' . $action . '" (exit ' . $exit . '). ' . $hint
        );
    }
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
 * Recursively coerce JSON-Schema fields that must be objects but were emitted
 * as arrays by an older generator. Empty arrays become {} (an object with no
 * keys); non-empty arrays become a permissive "allow anything" value for
 * additionalProperties or are dropped for properties/patternProperties, since
 * an array of property schemas has no unambiguous object representation.
 */
function mcpNormalizeSchema(array $schema): array
{
    foreach (['properties', 'patternProperties'] as $key) {
        if (isset($schema[$key]) && is_array($schema[$key]) && array_is_list($schema[$key])) {
            $schema[$key] = []; // {} once JSON-encoded
        }
    }
    if (array_key_exists('additionalProperties', $schema) && is_array($schema['additionalProperties'])) {
        // An empty array means "no constraints" (treat as true); a list of
        // schemas is also "any of these" which true approximates safely.
        $schema['additionalProperties'] = true;
    }
    foreach ($schema as $k => $v) {
        if (is_array($v)) {
            // Recurse into nested schema nodes (items, additionalProperties as
            // an object, property definitions, anyOf branches, etc.).
            if (array_is_list($v)) {
                $schema[$k] = array_map('mcpNormalizeSchemaWrapped', $v);
            } else {
                $schema[$k] = mcpNormalizeSchema($v);
            }
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
