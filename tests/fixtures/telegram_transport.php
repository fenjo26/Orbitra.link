<?php
// Test-only router. Run with curl_init/curl_setopt/curl_exec disabled so no
// request can reach Telegram. The production transport still builds each call.
if (!in_array('curl_init', explode(',', ini_get('disable_functions')), true)) {
    throw new RuntimeException('The Telegram fixture requires disabled cURL functions');
} else {
    // Conditional declarations also let ordinary php -l validate this fixture.
    function curl_init($url) { return (object)['url' => $url, 'options' => []]; }
    function curl_setopt($ch, $option, $value) { $ch->options[$option] = $value; return true; }
    function curl_exec($ch)
    {
        if (!str_starts_with($ch->url, 'https://api.telegram.org/bot')) {
            throw new RuntimeException('Unexpected outbound URL in Telegram test');
        }
        $method = basename(parse_url($ch->url, PHP_URL_PATH));
        $params = json_decode($ch->options[CURLOPT_POSTFIELDS] ?? '{}', true);
        file_put_contents(__DIR__ . '/telegram_calls.jsonl', json_encode([
            'method' => $method, 'params' => $params, 'options' => $ch->options,
        ]) . "\n", FILE_APPEND);

        // A committed click/cron write while the caller waits for Telegram. This
        // deterministically exposes a stale WAL reader; no timing/sleep race needed.
        $writer = new PDO('sqlite:' . __DIR__ . '/orbitra_test.sqlite');
        $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $writer->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('telegram_test_writer', random())");
        $writer = null;

        $scenario = $_SERVER['HTTP_X_TELEGRAM_SCENARIO'] ?? '';
        if ($method === 'getMe') {
            if ($scenario === 'transport_error') return false;
            if ($scenario === 'invalid_json') return '<html>upstream error</html>';
            if ($scenario === 'scalar_json') return 'true';
            if ($scenario === 'bad_token') return '{"ok":false,"error_code":401,"description":"Unauthorized"}';
            return '{"ok":true,"result":{"id":123,"username":"fixture_bot"}}';
        }
        if ($method === 'setWebhook' && $scenario === 'webhook_failure') {
            return '{"ok":false,"description":"Bad Request: invalid webhook"}';
        }
        if ($method === 'getWebhookInfo') {
            return '{"ok":true,"result":{"url":"https://tracker.example/telegram_bot.php","pending_update_count":0}}';
        }
        if ($method === 'getUpdates') return '{"ok":true,"result":[]}';
        return '{"ok":true,"result":true}';
    }
}

if (PHP_SAPI === 'cli') return; // Also usable as auto_prepend_file for the MCP worker/poller.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/health') { echo 'ready'; return; }
if ($path === '/api.php') { require __DIR__ . '/api.php'; return; }
if ($path === '/bot-first') {
    define('ORBITRA_TELEGRAM_NO_WEBHOOK', true);
    require __DIR__ . '/telegram_notify.php';
    require __DIR__ . '/api.php';
    return;
}
if ($path === '/telegram_bot.php') { require __DIR__ . '/telegram_bot.php'; return; }
if ($path === '/mcp.php') { require __DIR__ . '/mcp.php'; return; }
if ($path === '/housekeeping') {
    define('ORBITRA_TELEGRAM_NO_WEBHOOK', true);
    require __DIR__ . '/telegram_notify.php';
    orbitraTelegramMaybeRegisterCommands($pdo, 'TEST_TOKEN');
    orbitraTelegramMaybeSendDaily($pdo);
    echo 'ok';
    return;
}
http_response_code(404);
