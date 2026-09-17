<?php
// Executes the real worker against scratch SQLite, stubbing only DNS/cURL.
// Optional first argument selects a baseline checkout to reproduce the old bug:
// php tests/postback_queue_response_test.php /path/to/baseline

$repoRoot = dirname(__DIR__);
$sourceRoot = isset($argv[1]) ? realpath($argv[1]) : $repoRoot;
if ($sourceRoot === false || !is_file($sourceRoot . '/postback_queue_cron.php')) {
    fwrite(STDERR, "Worker source directory not found.\n");
    exit(1);
}
$failed = 0;
$check = static function (bool $condition, string $label) use (&$failed): void {
    echo ($condition ? '  ok  ' : 'FAIL  ') . $label . "\n";
    if (!$condition) {
        $failed++;
    }
};
$tmp = sys_get_temp_dir() . '/orbitra_meta_response_' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
mkdir($tmp . '/core', 0700);
mkdir($tmp . '/var', 0700);
mkdir($tmp . '/var/locks', 0700);

try {
    copy($sourceRoot . '/postback_queue_cron.php', $tmp . '/postback_queue_cron.php');
    copy($sourceRoot . '/core/FacebookConversions.php', $tmp . '/core/FacebookConversions.php');
    if (is_file($sourceRoot . '/core/MetaCapiResponse.php')) {
        copy($sourceRoot . '/core/MetaCapiResponse.php', $tmp . '/core/MetaCapiResponse.php');
    }
    copy(__DIR__ . '/fixtures/postback_queue_transport.php', $tmp . '/transport.php');
    copy(__DIR__ . '/fixtures/meta_manual_send.php', $tmp . '/manual_send.php');
    file_put_contents($tmp . '/config.php', "<?php\ndate_default_timezone_set('UTC');\n"
        . '$pdo = new PDO("sqlite:" . __DIR__ . "/queue.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);' . "\n");
    $pdo = new PDO('sqlite:' . $tmp . '/queue.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)");
    $pdo->exec("CREATE TABLE s2s_postbacks_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, conversion_id INTEGER, url TEXT, method TEXT,
        status TEXT, attempts INTEGER DEFAULT 0, next_retry_at TEXT, payload_json TEXT,
        content_type TEXT, proxy_url TEXT, headers_json TEXT, http_code INTEGER, status_code INTEGER,
        last_error TEXT, response TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT
    )");

    $token = 'fixture-token-DoNotPersist';
    $proxyPassword = 'fixture-proxy-password';
    $ip = '203.0.113.42';
    $ua = 'FixtureBrowser/1.2.3';
    $clickId = 'FixtureClickIdMustStayPrivate';
    $sourceUrl = 'https://checkout.example/receipt?email=fixture@example.com';
    $payload = ['data' => [[
        'event_name' => 'Purchase', 'event_id' => 'fixture-order-1', 'event_time' => 1789500000,
        'action_source' => 'website', 'event_source_url' => $sourceUrl,
        'user_data' => ['client_ip_address' => $ip, 'client_user_agent' => $ua,
            'fbc' => 'fb.1.1789400000000.' . $clickId, 'fbp' => 'fb.1.1789400000000.1234567890'],
    ]]];
    $accepted = json_encode(['events_received' => 1, 'messages' => [], 'fbtrace_id' => 'trace-fixture-ok']);
    $cases = [
        'accepted' => ['http' => 200, 'body' => $accepted, 'status' => 'delivered'],
        'warning' => ['http' => 200, 'body' => json_encode([
            'events_received' => 1,
            'messages' => ['Review fbc formatting.', ['code' => 100, 'message' => 'Optional parameter unavailable.']],
            'fbtrace_id' => 'trace-fixture-warning',
            'untrusted' => ['access_token' => $token, 'user_data' => $payload['data'][0]['user_data']],
        ]), 'status' => 'delivered'],
        'sensitive-warning' => ['http' => 200, 'body' => json_encode([
            'events_received' => 1, 'fbtrace_id' => 'trace-fixture-redacted',
            'messages' => ["Warning echo $token $proxyPassword $ip $ua $clickId $sourceUrl other@example.com 2001:db8::1",
                ['message' => 'Header fixture-header-secret', 'data' => ['token' => $token]]],
        ]), 'status' => 'delivered'],
        'body-error' => ['http' => 200, 'body' => json_encode(['events_received' => 1,
            'error' => ['code' => 100, 'message' => 'Invalid event_source_url', 'is_transient' => false]]), 'status' => 'failed'],
        'zero' => ['http' => 200, 'body' => '{"events_received":0}', 'status' => 'pending'],
        'missing' => ['http' => 200, 'body' => '{"messages":[]}', 'status' => 'pending'],
        'malformed' => ['http' => 200, 'body' => '<html>' . $token . ' fixture@example.com</html>', 'status' => 'pending'],
        'array' => ['http' => 200, 'body' => '[{"events_received":1}]', 'status' => 'pending'],
        'null' => ['http' => 200, 'body' => 'null', 'status' => 'pending'],
        'string-count' => ['http' => 200, 'body' => '{"events_received":"1"}', 'status' => 'pending'],
        'partial' => ['http' => 200, 'body' => $accepted, 'status' => 'pending', 'batch_size' => 2],
        'excess-count' => ['http' => 200, 'body' => '{"events_received":2}', 'status' => 'pending'],
        'auth' => ['http' => 400, 'body' => json_encode(['error' => ['code' => 190, 'error_subcode' => 463,
            'type' => 'OAuthException', 'message' => "Invalid token $token", 'fbtrace_id' => 'trace-fixture-auth']]), 'status' => 'failed'],
        'http-error' => ['http' => 400, 'body' => '{"error":{"code":100,"message":"Invalid parameter"}}', 'status' => 'failed'],
        'server-error' => ['http' => 503, 'body' => $accepted, 'status' => 'pending'],
        'rate-limit' => ['http' => 429, 'body' => '{"error":{"code":613,"is_transient":true}}', 'status' => 'pending'],
        'transient' => ['http' => 400, 'body' => '{"error":{"code":100,"is_transient":true}}', 'status' => 'pending'],
        'rate-limit-code' => ['http' => 400, 'body' => '{"error":{"code":4}}', 'status' => 'pending'],
        'timeout' => ['http' => 0, 'body' => false,
            'error' => "Timeout https://graph.facebook.com/events?access_token=$token proxy $proxyPassword", 'status' => 'pending'],
        'redirect' => ['http' => 302, 'body' => $accepted, 'status' => 'failed'],
        'empty' => ['http' => 204, 'body' => '', 'status' => 'pending'],
        'exhausted' => ['http' => 503, 'body' => '', 'status' => 'failed', 'attempts' => 5],
        'generic-redirect' => ['http' => 302, 'body' => '', 'status' => 'delivered', 'host' => 'partner.example'],
        'generic-body-error' => ['http' => 200, 'body' => '{"error":true}', 'status' => 'delivered', 'host' => 'partner.example'],
        'generic-http-error' => ['http' => 400, 'body' => '', 'status' => 'pending', 'host' => 'partner.example'],
        'tiktok-error' => ['http' => 200, 'body' => '{"code":40002,"message":"Invalid event"}', 'status' => 'delivered', 'host' => 'business-api.tiktok.com'],
        'dns-error' => ['http' => 0, 'body' => '', 'status' => 'pending', 'host' => 'dns-failure.example', 'no_transport' => true],
        'ssrf-blocked' => ['http' => 0, 'body' => '', 'status' => 'failed', 'host' => '127.0.0.1', 'no_transport' => true],
    ];
    file_put_contents($tmp . '/transport_cases.json', json_encode($cases));
    file_put_contents($tmp . '/manual_input.json', json_encode([
        'payload' => $payload,
        'pixel' => ['pixel_id' => '12345', 'token' => $token,
            'proxy_url' => 'http://fixture-proxy-user:' . $proxyPassword . '@proxy.example:8080'],
    ]));
    $insert = $pdo->prepare("INSERT INTO s2s_postbacks_log
        (url, method, status, attempts, next_retry_at, payload_json, content_type, proxy_url, headers_json, response)
        VALUES (?, 'POST', 'pending', ?, datetime('now', '-1 minute'), ?, 'application/json', ?, ?, ?)");
    $ids = [];
    $bodies = [];
    foreach ($cases as $name => $case) {
        $requestPayload = $payload;
        if (($case['batch_size'] ?? 1) === 2) {
            $requestPayload['data'][] = array_merge($payload['data'][0], ['event_id' => 'fixture-order-2']);
        }
        $host = $case['host'] ?? 'graph.facebook.com';
        $url = 'https://' . $host . '/v25.0/12345/events?fixture=' . $name . '&access_token=' . $token;
        $bodies[$name] = json_encode($requestPayload);
        $insert->execute([$url, $case['attempts'] ?? 0, $bodies[$name],
            'http://fixture-proxy-user:' . $proxyPassword . '@proxy.example:8080',
            json_encode(['Authorization' => 'Bearer fixture-header-secret']),
            isset($case['host']) ? 'existing generic response' : null]);
        $ids[$name] = (int) $pdo->lastInsertId();
    }
    // Historical failed/delivered rows must never be replayed by this change.
    $pdo->exec("INSERT INTO s2s_postbacks_log (url, method, status, attempts, next_retry_at)
        VALUES ('https://graph.facebook.com/v25.0/12345/events?fixture=historical', 'POST', 'failed', 1, datetime('now', '-1 day'))");
    $historicalId = (int) $pdo->lastInsertId();

    $run = static function (string $script = 'postback_queue_cron.php') use ($tmp): array {
        $disabled = 'gethostbyname,curl_init,curl_setopt,curl_exec,curl_getinfo,curl_error,curl_close';
        $proc = proc_open([PHP_BINARY, '-d', 'disable_functions=' . $disabled,
            '-d', 'auto_prepend_file=' . $tmp . '/transport.php', $tmp . '/' . $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tmp);
        if (!is_resource($proc)) {
            throw new RuntimeException('Unable to start worker fixture.');
        }
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out];
    };
    [$code, $out] = $run();
    $check($code === 0 && strpos($out, 'postback_queue error:') === false, 'worker exits cleanly');
    $select = $pdo->prepare('SELECT * FROM s2s_postbacks_log WHERE id = ?');
    $rows = [];
    foreach ($cases as $name => $case) {
        $select->execute([$ids[$name]]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        $select->closeCursor();
        $rows[$name] = $row;
        $check($row['status'] === $case['status'], "$name: status is {$case['status']}");
        $check((int) $row['attempts'] === ($case['attempts'] ?? 0) + 1, "$name: attempt recorded once");
        $check($row['payload_json'] === $bodies[$name], "$name: event IDs/times and body stay unchanged");
        if ($case['status'] === 'pending') {
            $check($row['next_retry_at'] > gmdate('Y-m-d H:i:s', time() + 45), "$name: existing first backoff retained");
        }
        if (!isset($case['host'])) {
            $summary = json_decode((string) $row['response'], true);
            $check(is_array($summary), "$name: sanitized diagnostic stored in response");
            if ($case['status'] !== 'delivered') {
                $check($row['last_error'] !== '' && $row['last_error'] !== null, "$name: failure visible through last_error");
            }
        } else {
            $check($row['response'] === 'existing generic response', "$name: generic response behavior preserved");
        }
    }
    $warning = json_decode((string) $rows['warning']['response'], true);
    $check(($warning['events_received'] ?? null) === 1 && ($warning['fbtrace_id'] ?? '') === 'trace-fixture-warning'
        && count($warning['messages'] ?? []) === 2 && !isset($warning['untrusted']), 'count, warnings and trace retained; arbitrary response fields dropped');
    $sensitive = (string) $rows['sensitive-warning']['response'] . (string) $rows['auth']['response'] . $out;
    foreach ([$token, $proxyPassword, $ip, $ua, $clickId, $sourceUrl, 'other@example.com', '2001:db8::1', 'fixture-header-secret'] as $secret) {
        $check(strpos($sensitive, $secret) === false, 'diagnostics and worker output do not expose ' . (in_array($secret, [$ip, $ua], true) ? 'visitor metadata' : 'sensitive fixture value'));
    }
    $calls = array_map(static fn($line) => json_decode($line, true), file($tmp . '/transport_calls.jsonl', FILE_IGNORE_NEW_LINES));
    $check(count($calls) === count($cases) - 2, 'each due row reaches transport once, excluding SSRF/DNS failures');
    foreach ($calls as $call) {
        $check($call['post'] === true && $call['body'] === $bodies[$call['fixture']], $call['fixture'] . ': actual transport receives original POST JSON');
    }
    [$secondCode, $secondOut] = $run();
    $check($secondCode === 0 && count(file($tmp . '/transport_calls.jsonl')) === count($calls), 'rerun does not replay successes, permanent failures or not-yet-due retries');
    $select->execute([$historicalId]);
    $historical = $select->fetch(PDO::FETCH_ASSOC);
    $check($historical['status'] === 'failed' && (int) $historical['attempts'] === 1, 'historical failed row stays untouched');

    [$manualCode, $manualOut] = $run('manual_send.php');
    $check($manualCode === 0, 'manual send fixture exits cleanly');
    $manual = json_decode((string) @file_get_contents($tmp . '/manual_results.json'), true) ?? [];
    foreach ($cases as $name => $case) {
        if (!isset($case['host'])) {
            $check(isset($manual[$name]['success']) && $manual[$name]['success'] === ($case['status'] === 'delivered'),
                "$name: manual send uses the same acceptance rules as cron");
        }
    }
    $manualWarning = $manual['warning']['response'] ?? [];
    $check(($manualWarning['fbtrace_id'] ?? '') === 'trace-fixture-warning'
        && count($manualWarning['messages'] ?? []) === 2 && !isset($manualWarning['untrusted']), 'manual send retains bounded warnings/trace without arbitrary fields');
    $manualDiagnostics = json_encode($manual) . $manualOut;
    foreach ([$token, $proxyPassword, $ip, $ua, $clickId, $sourceUrl, 'other@example.com', '2001:db8::1'] as $secret) {
        $check(strpos($manualDiagnostics, $secret) === false, 'manual send does not expose sensitive fixture data');
    }
} catch (Throwable $e) {
    $failed++;
    fwrite(STDERR, 'EXCEPTION: ' . $e->getMessage() . "\n");
} finally {
    $pdo = null;
    // Delete only the random scratch directory this test created.
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) {
                if ($name !== '.' && $name !== '..') {
                    $remove($path . '/' . $name);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    };
    $remove($tmp);
}
echo $failed === 0 ? "\nALL TESTS PASSED\n" : "\n$failed TESTS FAILED\n";
exit($failed === 0 ? 0 : 1);
