<?php
// Run: php tests/mcp_ssl_tools_test.php
//
// The SSL MCP tools (orbitra_ssl_environment, orbitra_reissue_ssl,
// orbitra_run_ssl_worker) over the real mcp.php HTTP endpoint.
//
// What happened without this coverage: a caller trying to issue a certificate
// through the generic orbitra_api_request escape hatch sent {"id": ...} as a
// query parameter (or {"domain_id": ...} in the body) and reissue_ssl
// answered "Invalid domain ID" on every format, because it only reads the
// JSON body field "id". The dedicated tools encode that contract in their
// schema, and this test pins the wire format end to end.
//
// The sandbox server runs with shell_exec disabled: certbot and the crontab
// write in orbitraEnsureSslCron() become impossible (and are asserted to stay
// impossible), while proc_open stays available so mcp.php dispatches through
// its normal cli/api_invoke.php child — the same path a production server
// takes.

require_once __DIR__ . '/lib/http.php';
date_default_timezone_set('UTC');

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}
function debugDump(string $label, $value): void
{
    if (getenv('MCP_TEST_DEBUG')) {
        fwrite(STDERR, "DEBUG $label: " . var_export($value, true) . "\n");
    }
}

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();
$harness->start();
$server = null;

try {
    // Boot probe: runs the migrations on the sandbox DB copy.
    $harness->get('/nonexistent-boot-probe');
    $pdo = $harness->getPdo();
    $dir = $harness->getWorkingDir();

    // The harness copies api.php + core/, but not the MCP endpoint files.
    copy(dirname(__DIR__) . '/mcp.php', $dir . '/mcp.php');
    mkdir($dir . '/mcp');
    copy(dirname(__DIR__) . '/mcp/tools.json', $dir . '/mcp/tools.json');
    mkdir($dir . '/cli');
    copy(dirname(__DIR__) . '/cli/api_invoke.php', $dir . '/cli/api_invoke.php');

    $pdo->exec("INSERT INTO users (id, username, password, role, is_active) VALUES
        (1, 'ssl_admin', 'unused', 'admin', 1),
        (2, 'ssl_viewer', 'unused', 'user', 1)");
    $pdo->exec("INSERT INTO user_api_keys (user_id, key_name, api_key, permissions) VALUES
        (1, 'admin write', 'SSL_ADMIN_WRITE', 'write'),
        (1, 'admin read',  'SSL_ADMIN_READ',  'read'),
        (2, 'viewer read', 'SSL_VIEWER_READ', 'read')");
    // Pin the CLI binary so mcp.php's proc_open dispatch does not depend on
    // PATH discovery inside the server process.
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('mcp_php_binary', ?)")
        ->execute([PHP_BINARY]);

    $pdo->exec("INSERT INTO domains (name, ssl_status, ssl_attempts, cloudflare_proxy) VALUES
        ('reissue-pending.example.com', 'pending', 0, 0),
        ('reissue-cf.example.com', 'cloudflare', 0, 1)");
    $pendingId = (int) $pdo->query("SELECT id FROM domains WHERE name = 'reissue-pending.example.com'")->fetchColumn();
    $cfId = (int) $pdo->query("SELECT id FROM domains WHERE name = 'reissue-cf.example.com'")->fetchColumn();

    // No router script: the built-in server serves /mcp.php directly.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) {
        throw new RuntimeException($error);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'disable_functions=shell_exec',
        '-S', $address, '-t', $dir],
        [0 => ['pipe', 'r'], 1 => ['file', $dir . '/mcp-http.log', 'a'],
         2 => ['file', $dir . '/mcp-http.log', 'a']], $pipes, $dir);
    if (!is_resource($server)) {
        throw new RuntimeException('Could not start the MCP test server');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        // GET is answered with 405 + a body naming POST, which is proof enough
        // that mcp.php itself (not the sandbox router) took the request.
        $probe = @file_get_contents("http://$address/mcp.php", false, stream_context_create(['http' => ['ignore_errors' => true]]));
        if (is_string($probe) && strpos($probe, 'send JSON-RPC') !== false) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('MCP test server did not start: ' . substr((string) @file_get_contents($dir . '/mcp-http.log'), -500));
    }

    // One JSON-RPC call over the real endpoint; returns the decoded payload
    // text plus the isError flag from the MCP envelope.
    $rpc = static function (string $tool, array $args, string $key) use ($address): array {
        $raw = file_get_contents("http://$address/mcp.php?k=" . urlencode($key), false, stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $args]]),
            'ignore_errors' => true,
            'timeout' => 20,
        ]]));
        $envelope = json_decode((string) $raw, true);
        $text = $envelope['result']['content'][0]['text'] ?? '';
        $decoded = json_decode($text, true);
        return [
            'isError' => (bool) ($envelope['result']['isError'] ?? false),
            'envelopeError' => $envelope['error']['message'] ?? null,
            // Tools that returned JSON decode to arrays; plain error strings stay strings.
            'payload' => is_array($decoded) ? $decoded : $text,
        ];
    };

    $domainState = static function (int $id) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT ssl_status, ssl_attempts FROM domains WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    // --- tools/list exposes the three SSL tools --------------------------------

    $raw = file_get_contents("http://$address/mcp.php?k=SSL_ADMIN_READ", false, stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']),
        'ignore_errors' => true,
        'timeout' => 20,
    ]]));
    $names = array_column(json_decode((string) $raw, true)['result']['tools'] ?? [], 'name');
    check(in_array('orbitra_ssl_environment', $names, true), 'tools/list exposes orbitra_ssl_environment');
    check(in_array('orbitra_reissue_ssl', $names, true), 'tools/list exposes orbitra_reissue_ssl');
    check(in_array('orbitra_run_ssl_worker', $names, true), 'tools/list exposes orbitra_run_ssl_worker');

    // --- ssl_environment: read tool, admin-gated at the API level --------------

    $env = $rpc('orbitra_ssl_environment', [], 'SSL_ADMIN_READ');
    debugDump('ssl_environment', $env);
    check(!$env['isError'] && ($env['payload']['status'] ?? '') === 'success'
        && ($env['payload']['data']['can_issue'] ?? null) === false
        && ($env['payload']['data']['problems'] ?? []) !== [],
        'orbitra_ssl_environment answers with can_issue + problems for an admin key');

    $envViewer = $rpc('orbitra_ssl_environment', [], 'SSL_VIEWER_READ');
    check($envViewer['isError'] && strpos((string) $envViewer['payload'], 'Forbidden') !== false,
        'orbitra_ssl_environment refuses a non-admin user key');

    // --- reissue_ssl: write key required, body {"id": N} contract --------------

    $readKey = $rpc('orbitra_reissue_ssl', ['id' => $pendingId], 'SSL_ADMIN_READ');
    check($readKey['isError'] && strpos((string) $readKey['payload'], 'read-only') !== false,
        'orbitra_reissue_ssl is refused for a read-only key before reaching the API');

    $missing = $rpc('orbitra_reissue_ssl', ['id' => 999999], 'SSL_ADMIN_WRITE');
    debugDump('reissue_missing', $missing);
    check($missing['isError'] && strpos((string) $missing['payload'], 'Domain not found') !== false,
        'orbitra_reissue_ssl forwards POST with the id in the JSON body (Domain not found, not Invalid domain ID)');

    $cf = $rpc('orbitra_reissue_ssl', ['id' => $cfId], 'SSL_ADMIN_WRITE');
    debugDump('reissue_cf', $cf);
    check($cf['isError'] && strpos((string) $cf['payload'], 'Cannot issue certificate for Cloudflare-proxied domains') !== false,
        'orbitra_reissue_ssl refuses Cloudflare-proxied domains');

    // Shell is disabled in this sandbox, so the environment pre-flight must
    // stop the issuance AND leave the domain's stored state untouched — the
    // handler only starts writing after every pre-flight has passed.
    $pending = $rpc('orbitra_reissue_ssl', ['id' => $pendingId], 'SSL_ADMIN_WRITE');
    debugDump('reissue_pending', $pending);
    $state = $domainState($pendingId);
    check($pending['isError']
        && strpos((string) $pending['payload'], 'Cannot issue certificates on this server') !== false
        && strpos((string) $pending['payload'], 'Certbot is not installed') !== false
        && strpos((string) $pending['payload'], 'no_certbot') !== false,
        'orbitra_reissue_ssl reports the environment problem, with its detail, when certbot cannot run');
    check($state === ['ssl_status' => 'pending', 'ssl_attempts' => 0],
        'a blocked pre-flight leaves the domain untouched (no status reset, no burned attempt)');

    // --- run_ssl_worker: queue pass without a shell -----------------------------

    $worker = $rpc('orbitra_run_ssl_worker', [], 'SSL_ADMIN_WRITE');
    check(!$worker['isError'] && ($worker['payload']['status'] ?? '') === 'success'
        && array_key_exists('issued', $worker['payload']['data'] ?? [])
        && array_key_exists('waiting', $worker['payload']['data'] ?? [])
        && array_key_exists('server_ip', $worker['payload']['data'] ?? [])
        && ($worker['payload']['data']['can_issue'] ?? null) === false,
        'orbitra_run_ssl_worker runs one queue pass and reports can_issue=false + server_ip');
    check(($worker['payload']['data']['cron_scheduled'] ?? null) === false,
        'orbitra_run_ssl_worker cannot touch the real crontab from the sandbox (cron_scheduled=false)');
    check($domainState($pendingId) === ['ssl_status' => 'pending', 'ssl_attempts' => 0],
        'the queue pass marks nothing failed while issuing is impossible');

    $viewer = $rpc('orbitra_run_ssl_worker', [], 'SSL_VIEWER_READ');
    check($viewer['isError'] && strpos((string) $viewer['payload'], 'read-only') !== false,
        'orbitra_run_ssl_worker is write-gated at the MCP layer');

    // --- check_domain_dns waits out a held write lock -------------------------
    // The cache UPDATE used to be bare: a mass MCP sweep over every parked
    // domain, run while reissue/worker writes were in flight, died on
    // "database is locked". Hold the write lock from a second connection for
    // 7s — past the 5s web busy_timeout, well inside the 15s allowance the
    // action now takes — and require the check to succeed anyway.
    $dbPath = null;
    foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $dbRow) {
        if (($dbRow['file'] ?? '') !== '') { $dbPath = $dbRow['file']; }
    }
    $holder = proc_open([PHP_BINARY, '-r', '
        $pdo = new PDO("sqlite:" . $argv[1]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("BEGIN IMMEDIATE");
        $pdo->exec("UPDATE settings SET value = value WHERE key = \'postback_key\'");
        usleep(7000000);
    ', $dbPath], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $holderPipes);
    usleep(300000); // let the holder actually take the lock
    $lockStart = microtime(true);
    $dns = $rpc('orbitra_check_domain_dns', ['id' => $pendingId], 'SSL_ADMIN_READ');
    $lockWait = microtime(true) - $lockStart;
    foreach ($holderPipes as $p) { fclose($p); }
    proc_close($holder);
    check(!$dns['isError'] && array_key_exists('dns_status', $dns['payload']['data'] ?? []),
        'check_domain_dns survives a held write lock instead of dying on "database is locked"');
    check($lockWait > 5, "the check really waited for the lock ({$lockWait}s) — the contention was real");
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $pdo = null;
    $harness->stop();
}
exit($failures ? 1 : 0);
