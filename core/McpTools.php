<?php
// core/McpTools.php
//
// The Orbitra tool layer shared by the remote MCP endpoint (mcp.php) and the
// panel's built-in AI agent (core/AiAgent.php): the tool manifest
// (mcp/tools.json, generated from mcp/src/index.js), the handlers that are
// more than one forwarded call, and the api.php dispatch (a CLI child per
// call, or in-process when proc_open is unavailable).
//
// Moved out of mcp.php unchanged except for paths (this file lives in core/).

if (!function_exists('mcpTools')) {

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

    // Inside a panel request api.php is already loaded (the built-in agent
    // runs from api.php): including it again would be a redeclaration fatal.
    if (function_exists('logAudit')) {
        throw new RuntimeException(
            'This server disables proc_open(), which the built-in assistant needs to run tools. '
            . 'Allow proc_open() in php.ini (remove it from disable_functions).'
        );
    }

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
        require dirname(__DIR__) . '/api.php';
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
        throw new RuntimeException(mcpApiErrorMessage($decoded));
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
    $cmd = [mcpPhpBinary($pdo), dirname(__DIR__) . '/cli/api_invoke.php'];
    $process = @proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
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
        throw new RuntimeException(mcpApiErrorMessage($decoded));
    }
    return $decoded;
}

/**
 * The message a failed api.php call becomes inside an MCP tool result. The bare
 * "message" alone is often not actionable: the SSL endpoints put the fix (which
 * dependency is missing, which IP the DNS check saw) into "detail"/"data", and a
 * model that never sees those can only guess.
 */
function mcpApiErrorMessage(array $decoded): string
{
    $message = 'Orbitra API error: ' . ($decoded['message'] ?? 'unknown');
    $extras = [];
    foreach (['detail', 'data'] as $key) {
        if (isset($decoded[$key]) && ($decoded[$key] !== '' && $decoded[$key] !== [])) {
            $extras[] = is_string($decoded[$key]) ? $decoded[$key] : json_encode($decoded[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    if ($extras) {
        $message .= ' — ' . implode('; ', $extras);
    }
    return $message;
}

/** Mirror of normalizeStreamForSave() in mcp/src/index.js. */
/**
 * schema_custom is an object ({offers:[...], landings:[...], ...}); a bare list of
 * offers is read as the offers list (api.php normalises the same way on save).
 */
function mcpSchemaCustomObject($v): array
{
    if (!is_array($v)) {
        return [];
    }
    if ($v !== [] && array_is_list($v)) {
        return ['offers' => array_values(array_filter($v, 'is_array'))];
    }
    return $v;
}

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
    // id keeps the stream (and its click history) across the save; name,
    // filters_logic, offer_selection and collect_clicks used to be dropped,
    // which renamed streams, turned OR filters into AND and reset offer
    // selection on every update_campaign call.
    $out = [
        'offer_id' => $s['offer_id'] ?? null,
        'weight' => $s['weight'] ?? 100,
        'is_active' => $s['is_active'] ?? 1,
        'type' => $s['type'] ?? 'regular',
        'position' => $s['position'] ?? 0,
        'filters' => $s['filters'] ?? $parse($s['filters_json'] ?? null, []),
        'filters_logic' => ($s['filters_logic'] ?? 'and') === 'or' ? 'or' : 'and',
        'schema_type' => $s['schema_type'] ?? 'redirect',
        'action_payload' => $s['action_payload'] ?? '',
        'schema_custom' => mcpSchemaCustomObject($s['schema_custom'] ?? $parse($s['schema_custom_json'] ?? null, [])),
        'offer_selection' => ($s['offer_selection'] ?? 'before') === 'after' ? 'after' : 'before',
        'collect_clicks' => (int) ($s['collect_clicks'] ?? 1) === 0 ? 0 : 1,
    ];
    if (!empty($s['id'])) {
        $out['id'] = (int) $s['id'];
    }
    if (isset($s['name'])) {
        $out['name'] = $s['name'];
    }
    return $out;
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

        case 'get_integration_code':
            // The options object must travel as ONE JSON string in the query —
            // api_invoke only forwards scalar GET params, and String(object)
            // would be "[object Object]". Both transports (this handler and
            // the Node twin) stringify before the GET.
            $campaignId = (int) ($args['campaign_id'] ?? 0);
            $method = trim((string) ($args['method'] ?? ''));
            if ($campaignId <= 0 || $method === '') {
                throw new RuntimeException('campaign_id and method are required.');
            }
            $params = ['campaign_id' => $campaignId, 'method' => $method];
            if (!empty($args['tracker_url'])) {
                $params['tracker_url'] = (string) $args['tracker_url'];
            }
            if (isset($args['options'])) {
                $params['options'] = is_string($args['options'])
                    ? $args['options']
                    : json_encode($args['options'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            return mcpCallApi($pdo, $apiKey, 'GET', 'integration_code', $params);

        case 'integrations_status':
            // Three read calls, each reported in its own section so one failing
            // section (or, on proc_open-less hosts, the one-dispatch limit of
            // the in-process fallback) never hides the rest.
            $grab = static function (string $action) use ($pdo, $apiKey): array {
                try {
                    $res = mcpCallApi($pdo, $apiKey, 'GET', $action);
                    return is_array($res['data'] ?? null) ? $res['data'] : ['raw' => $res];
                } catch (\Throwable $e) {
                    return ['error' => $e->getMessage()];
                }
            };
            return [
                'cloudflare' => $grab('cloudflare_status'),
                'telegram' => $grab('telegram_settings'),
                'traffic_sources' => $grab('traffic_sources'),
            ];
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
    $file = dirname(__DIR__) . '/mcp/tools.json';
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

/**
 * Run one manifest tool with the caller's API key — the dispatch mcp.php's
 * tools/call and the built-in agent share. Returns the decoded API payload;
 * throws with a message the model can act on.
 */
function mcpDispatchTool(PDO $pdo, string $apiKey, array $tool, array $args)
{
    if (isset($tool['handler'])) {
        return mcpRunHandler($pdo, $apiKey, (string) $tool['handler'], $args);
    }
    if (($tool['method'] ?? 'GET') === 'POST') {
        return mcpCallApi($pdo, $apiKey, 'POST', (string) $tool['action'], [], $args);
    }
    return mcpCallApi($pdo, $apiKey, 'GET', (string) $tool['action'], $args);
}

/** Does this manifest tool change anything? (mcp.php's write-key gate.) */
function mcpToolWrites(array $tool): bool
{
    return ($tool['method'] ?? '') === 'POST'
        || isset($tool['handler']) && $tool['handler'] !== 'api_request' && empty($tool['readOnly']);
}

}
