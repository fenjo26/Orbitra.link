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

// Tool manifest, handlers and api.php dispatch: core/McpTools.php (shared
// with the panel's built-in AI agent).
require_once __DIR__ . '/core/McpTools.php';

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
    // The API may run in this process, or in a child that writes to this DB.
    $stmt->closeCursor();
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
                // rather than letting api.php answer with a bare 403. A handler
                // counts as a write unless its manifest entry says readOnly —
                // handlers that only chain GETs (integration code, status
                // aggregates) are reads no matter how many calls they make.
                $writes = mcpToolWrites($tool);
                if ($writes && !$canWrite) {
                    $responses[] = mcpResult($id, mcpToolText(
                        'This connection uses a read-only API key, so "' . $name . '" is not permitted. '
                        . 'Generate a Write key in Users -> API Keys and reconnect with it.',
                        true
                    ));
                    break;
                }

                try {
                    $data = mcpDispatchTool($pdo, $providedKey, $tool, $args);
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
