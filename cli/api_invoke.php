<?php
/**
 * Run one api.php action in a CLI process and print its JSON to stdout.
 *
 * mcp.php uses this to execute MCP tool calls. It deliberately does NOT reach
 * api.php over HTTP: on a small VPS, PHP-FPM often runs only a couple of workers,
 * so a request that waits on another request to the same pool can deadlock the
 * site. A short-lived CLI process has no such contention.
 *
 * It is also not an include: api.php declares functions at file scope and the
 * tools that read-then-write need two dispatches in one MCP call, which a second
 * include would answer with a redeclaration fatal.
 *
 * Input (stdin, JSON):
 *   { "method": "GET|POST", "action": "campaigns", "params": {...},
 *     "body": {...}, "api_key": "..." }
 *
 * Output (stdout): whatever api.php produced, verbatim.
 * Exit code is 0 even for API-level errors — the caller reads the JSON.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raw = stream_get_contents(STDIN);
$req = json_decode((string) $raw, true);
if (!is_array($req)) {
    fwrite(STDERR, "api_invoke: malformed request on stdin\n");
    exit(2);
}

$action = (string) ($req['action'] ?? '');
if ($action === '') {
    fwrite(STDERR, "api_invoke: missing action\n");
    exit(2);
}

$method = strtoupper((string) ($req['method'] ?? 'GET')) === 'POST' ? 'POST' : 'GET';
$params = is_array($req['params'] ?? null) ? $req['params'] : [];
$body = is_array($req['body'] ?? null) ? $req['body'] : [];
$apiKey = (string) ($req['api_key'] ?? '');

// Build the request context api.php expects from a web server.
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
$_SERVER['HTTP_HOST'] = (string) ($req['host'] ?? 'localhost');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_API_KEY'] = $apiKey;
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $apiKey;
$_SERVER['HTTP_USER_AGENT'] = 'Orbitra-MCP/1.0';

// api.php reads the body through orbitraRequestBody(), which prefers this global.
$GLOBALS['ORBITRA_INTERNAL_REQUEST_BODY'] = $method === 'POST'
    ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : '';

// An API key authenticates on its own; there is no browser session to carry a
// CSRF token, and api.php already exempts key clients from that check.
chdir(__DIR__ . '/..');
require __DIR__ . '/../api.php';
