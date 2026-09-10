<?php
// Run: php tests/telegram_connection_test.php
// Real HTTP entry points + production schema in the existing harness sandbox.
// Only cURL is replaced; every fake Telegram call commits a concurrent WAL write.
require_once __DIR__ . '/lib/http.php';
date_default_timezone_set('UTC');

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) $failures++;
}

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();
$harness->start();
$server = null;
try {
    $pdo = $harness->getPdo();
    $dir = dirname($pdo->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file']);
    copy(__DIR__ . '/fixtures/telegram_transport.php', $dir . '/telegram_transport.php');
    copy(dirname(__DIR__) . '/mcp.php', $dir . '/mcp.php');
    mkdir($dir . '/mcp');
    copy(dirname(__DIR__) . '/mcp/tools.json', $dir . '/mcp/tools.json');
    mkdir($dir . '/cli');
    copy(dirname(__DIR__) . '/cli/api_invoke.php', $dir . '/cli/api_invoke.php');
    copy(dirname(__DIR__) . '/telegram_poll_cron.php', $dir . '/telegram_poll_cron.php');
    $pdo->exec("INSERT INTO users (id, username, password, role, timezone) VALUES (1, 'tg_admin', 'unused', 'admin', 'UTC')");
    $pdo->exec("INSERT INTO user_api_keys (user_id, key_name, api_key, permissions) VALUES
        (1, 'test write', 'TEST_WRITE', 'write'), (1, 'test read', 'TEST_READ', 'read')");
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', 'test-postback')");
    check(strtolower($pdo->query('PRAGMA journal_mode')->fetchColumn()) === 'wal', 'fixture uses WAL');

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException($error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    // Disable proc_open inside the server too: exercise MCP's in-process fallback.
    $server = proc_open([PHP_BINARY, '-d', 'disable_functions=curl_init,curl_setopt,curl_exec,proc_open',
        '-S', $address, '-t', $dir, $dir . '/telegram_transport.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $dir . '/tg-http.log', 'a'],
         2 => ['file', $dir . '/tg-http.log', 'a']], $pipes, $dir);
    if (!is_resource($server)) throw new RuntimeException('Could not start Telegram test server');
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        if (@file_get_contents("http://$address/health") === 'ready') { $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('Telegram test server did not start: ' . file_get_contents($dir . '/tg-http.log'));

    $request = static function (string $path, ?array $body = null, string $key = 'TEST_WRITE', string $scenario = '') use ($address): array {
        $headers = ['Content-Type: application/json', 'Host: tracker.example',
            'X-Forwarded-Proto: https', 'X-Api-Key: ' . $key, 'X-Telegram-Scenario: ' . $scenario];
        $raw = file_get_contents("http://$address$path", false, stream_context_create(['http' => [
            'method' => $body === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
            'content' => $body === null ? '' : json_encode($body), 'ignore_errors' => true, 'timeout' => 15,
        ]]));
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers() : ($http_response_header ?? []);
        preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $m);
        return ['code' => (int)($m[1] ?? 0), 'json' => json_decode($raw, true), 'raw' => $raw];
    };
    $setting = static function ($key) use ($pdo) {
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        return $stmt->fetchColumn();
    };
    $calls = static function () use ($dir): array {
        return array_map(fn($line) => json_decode($line, true), file($dir . '/telegram_calls.jsonl', FILE_IGNORE_NEW_LINES) ?: []);
    };
    $token = '123456789:TEST_ONLY_abcdefghijklmnopqrstuvwxyz';
    $save = ['token' => $token, 'mode' => 'webhook', 'notify_conversions' => true, 'daily_time' => '21:00'];
    $connect = $request('/api.php?action=save_telegram_settings', $save);
    check($connect['code'] === 200 && ($connect['json']['status'] ?? '') === 'success', 'connect returns success JSON after concurrent writes');
    check(($connect['json']['data']['mode'] ?? '') === 'webhook' && $setting('telegram_webhook_set') === '1', 'webhook mode is persisted');
    $methods = array_column($calls(), 'method');
    check(count(array_filter($methods, fn($m) => $m === 'setMyCommands')) === 8
        && in_array('setChatMenuButton', $methods, true), 'connection registers all language menus without a redeclaration fatal');
    $webhookCalls = array_values(array_filter($calls(), fn($c) => $c['method'] === 'setWebhook'));
    check(($webhookCalls[0]['params']['url'] ?? '') === 'https://tracker.example/telegram_bot.php'
        && ($webhookCalls[0]['params']['allowed_updates'] ?? []) === ['message', 'callback_query'], 'webhook URL and update types retain their contract');
    check(($webhookCalls[0]['options'][CURLOPT_TIMEOUT] ?? null) === 15
        && ($webhookCalls[0]['options'][CURLOPT_CONNECTTIMEOUT] ?? null) === 8, 'shared transport preserves bounded connection and request timeouts');
    $status = $request('/api.php?action=telegram_settings');
    check(($status['json']['data']['connected'] ?? false) === true, 'status reports connected');
    check(!str_contains($status['raw'], $token), 'status does not disclose the token');
    $reverse = $request('/bot-first?action=save_telegram_settings', $save);
    check(($reverse['json']['status'] ?? '') === 'success', 'loading notifications/bot before the API is also safe');

    $before = count($calls());
    foreach (['TEST_READ', 'INVALID_KEY'] as $key) {
        $denied = $request('/api.php?action=save_telegram_settings', $save, $key);
        check(in_array($denied['code'], [401, 403], true), "$key cannot connect a bot");
    }
    check(count($calls()) === $before, 'denied requests make no Telegram calls');
    foreach (['bad_token', 'transport_error', 'invalid_json', 'scalar_json'] as $scenario) {
        $invalid = $request('/api.php?action=save_telegram_settings', array_replace($save, ['token' => 'OTHER_TOKEN']), 'TEST_WRITE', $scenario);
        check(($invalid['json']['code'] ?? '') === 'bad_token' && $setting('telegram_bot_token') === $token, "$scenario preserves the configured token and returns JSON");
    }

    $fallback = $request('/api.php?action=save_telegram_settings', array_replace($save, ['mode' => 'auto']), 'TEST_WRITE', 'webhook_failure');
    check(($fallback['json']['data']['mode'] ?? '') === 'polling'
        && $setting('telegram_webhook_cleared') === '1', 'auto mode retains polling fallback');
    $explicit = $request('/api.php?action=save_telegram_settings', $save, 'TEST_WRITE', 'webhook_failure');
    check(($explicit['json']['code'] ?? '') === 'webhook_failed', 'explicit webhook failure remains actionable');
    $forced = $request('/api.php?action=save_telegram_settings', array_replace($save, ['mode' => 'polling']));
    check(($forced['json']['data']['mode'] ?? '') === 'polling', 'forced polling remains supported');
    $request('/api.php?action=save_telegram_settings', $save);

    $start = $request('/telegram_bot.php', ['message' => ['chat' => ['id' => 100],
        'from' => ['username' => 'fixture', 'first_name' => 'Fixture'], 'text' => '/start']]);
    check($start['code'] === 200 && $start['raw'] === 'ok', 'standalone webhook still handles /start');
    check((int)$pdo->query('SELECT COUNT(*) FROM telegram_bot_chats WHERE chat_id = 100')->fetchColumn() === 1, 'webhook registers the chat');
    $test = $request('/api.php?action=telegram_test', []);
    check(($test['json']['status'] ?? '') === 'success', 'test message works after connection');

    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('telegram_daily_time', '00:00')");
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('telegram_commands_sent', '2000-01-01')");
    $pdo->exec("UPDATE telegram_bot_chats SET notify_daily = 1");
    $housekeeping = $request('/housekeeping');
    check($housekeeping['raw'] === 'ok' && $setting('telegram_commands_sent') === date('Y-m-d'), 'cron menu registration releases its cursor before network I/O');
    check($setting('telegram_daily_last_sent') === date('Y-m-d'), 'daily summary can claim its date with concurrent writes');

    $rpc = $request('/mcp.php', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'orbitra_api_request', 'arguments' => [
            'method' => 'POST', 'action' => 'save_telegram_settings', 'body' => $save,
        ]]]);
    $rpcBody = json_decode($rpc['json']['result']['content'][0]['text'] ?? '', true);
    check(($rpcBody['status'] ?? '') === 'success' && empty($rpc['json']['result']['isError']), 'MCP in-process dispatch can connect with concurrent writes');

    $runCli = static function (string $script, array $args = [], string $input = '') use ($dir): array {
        $process = proc_open(array_merge([PHP_BINARY, '-d', 'disable_functions=curl_init,curl_setopt,curl_exec',
            '-d', 'auto_prepend_file=' . $dir . '/telegram_transport.php', $dir . '/' . $script], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    };
    $worker = $runCli('cli/api_invoke.php', [], json_encode(['method' => 'POST',
        'action' => 'save_telegram_settings', 'api_key' => 'TEST_WRITE', 'body' => $save, 'host' => 'tracker.example']));
    check($worker['code'] === 0 && (json_decode($worker['out'], true)['status'] ?? '') === 'success', 'MCP CLI worker can connect with concurrent writes');
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('telegram_mode', 'polling')");
    $poll = $runCli('telegram_poll_cron.php', ['--once', '--quiet']);
    check($poll['code'] === 0 && (bool)$setting('telegram_poll_last_run'), 'polling worker still processes a pass and records its heartbeat');

    $disconnect = $request('/api.php?action=save_telegram_settings', ['action' => 'disconnect']);
    check(($disconnect['json']['status'] ?? '') === 'success' && $setting('telegram_bot_token') === '', 'disconnect releases its token cursor before deleting the webhook');
    $noToken = $request('/api.php?action=telegram_test', []);
    check(($noToken['json']['message'] ?? '') === 'Bot token not configured', 'disconnected test remains a controlled error');
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $pdo = null;
    $harness->stop();
}
exit($failures ? 1 : 0);
