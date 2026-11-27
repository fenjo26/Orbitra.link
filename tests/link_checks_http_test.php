<?php
// Run: php tests/link_checks_http_test.php
// The link-check API through the real router: what an admin may do, what a
// limited account may read and book, and that proxies never give up their
// password. The worker itself is covered by tests/link_checks_test.php; here
// the scope is synced by calling the engine on the sandbox database.

require_once __DIR__ . '/lib/http.php';
// Inherited by the sandboxed server: switching the feature on must not
// schedule anything in the developer's own crontab.
putenv('ORBITRA_CRON_SELF_HEAL=0');

$failures = 0;
$checks = 0;
$assert = static function ($expected, $actual, string $label) use (&$failures, &$checks): void {
    $checks++;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$label}\n  expected: " . var_export($expected, true)
            . "\n  actual:   " . var_export($actual, true) . "\n");
    }
};

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();

try {
    $harness->start();
    $pdo = $harness->getPdo();
    foreach ([['lc_admin', 'admin', null], ['lc_buyer', 'user', ['offers' => ['access' => 'full']]]] as [$username, $role, $permissions]) {
        $pdo->prepare('INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, ?, 1, ?)')
            ->execute([$username, password_hash('pass123', PASSWORD_DEFAULT), $role, json_encode($permissions ?? new stdClass())]);
    }
    $login = static function (string $username) use ($harness): array {
        $resp = $harness->postWithHeaders('/api.php?action=login', json_encode(['username' => $username, 'password' => 'pass123']), ['Content-Type: application/json']);
        preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $m);
        if (empty($m[1])) {
            throw new RuntimeException('Could not log in as ' . $username . ': ' . $resp['body']);
        }
        $body = json_decode($resp['body'], true);
        // A session's writes carry its CSRF token; the login answer hands it over.
        return ['cookie' => 'Cookie: ORBITRASESSID=' . $m[1], 'csrf' => (string) ($body['data']['csrf_token'] ?? '')];
    };
    $admin = $login('lc_admin');
    $buyer = $login('lc_buyer');
    $get = static fn (string $path, array $session): array => json_decode($harness->getWithHeaders($path, [$session['cookie']])['body'], true);
    $post = static fn (string $path, array $data, array $session): array => json_decode($harness->postWithHeaders($path, json_encode($data),
        [$session['cookie'], 'X-CSRF-TOKEN: ' . $session['csrf'], 'Content-Type: application/json'])['body'], true);
    $lastBody = '';

    $pdo->exec("INSERT INTO offers (id, name, url) VALUES (1, 'Offer A', 'https://a.example/go'), (2, 'Local', '')");
    $pdo->exec("INSERT INTO domains (id, name, ssl_status) VALUES (1, 'go.example.com', 'installed')");

    // --- Admin: settings ------------------------------------------------------
    $settings = $get('/api.php?action=link_checks_settings', $admin);
    $assert('success', $settings['status'] ?? null, 'Admin reads the settings');
    $assert([false, 'all', 'all', 60], array_values(array_intersect_key($settings['data']['settings'] ?? [], ['enabled' => 1, 'offers' => 1, 'domains' => 1, 'interval_min' => 1])), 'The feature starts switched off');
    $assert([], $settings['data']['proxies'] ?? null, 'A fresh install has no proxies');

    $saved = $post('/api.php?action=save_link_checks_settings', ['enabled' => true, 'offers' => true, 'domains' => true, 'interval_min' => 30, 'proxy_mode' => 'rotate'], $admin);
    $assert([true, 'all', 'all', 30, 'rotate'], [
        $saved['data']['settings']['enabled'] ?? null,
        $saved['data']['settings']['offers'] ?? null,
        $saved['data']['settings']['domains'] ?? null,
        $saved['data']['settings']['interval_min'] ?? null,
        $saved['data']['settings']['proxy_mode'] ?? null,
    ], 'The admin switches the feature on (including boolean checkboxes from UI) and sets interval and proxy_mode');
    $assert(false, $saved['data']['worker']['alive'] ?? null, 'Without the worker running, the panel is told so');
    $assert(true, str_contains((string) ($saved['data']['worker']['cron_line'] ?? ''), 'link_checks_cron.php'), 'Worker payload carries the dynamic cron command');

    // --- The scope and the lists -----------------------------------------------
    require_once __DIR__ . '/../core/LinkChecks.php';
    orbitraLinkChecksSync($pdo, orbitraLinkChecksSettings($pdo));
    $list = $get('/api.php?action=link_checks_list', $admin);
    $rows = array_column($list['data']['rows'] ?? [], null, 'object_type');
    $assert(['success', 2, ['Offer A', 'go.example.com']], [$list['status'] ?? null, $list['data']['total'] ?? null, array_map(static fn (array $r): string => (string) $r['label'], $list['data']['rows'] ?? [])], 'Every external offer and every domain is listed once');
    $assert(['offer', 'domain'], array_keys($rows), 'Rows say what they are');
    $assert(['https://a.example/go', 'https://go.example.com/'], [$rows['offer']['url'] ?? null, $rows['domain']['url'] ?? null], 'The list carries the URL that is opened');
    $assert(['pending', 'pending'], [$rows['offer']['status'] ?? null, $rows['domain']['status'] ?? null], 'A link nobody has looked at yet is pending');
    $summary = $get('/api.php?action=link_checks_summary', $admin);
    $assert([1, 1, 0], [$summary['data']['offers']['total'] ?? null, $summary['data']['domains']['total'] ?? null, $summary['data']['failed'] ?? null], 'The summary counts by type for the badge');

    $offerRow = (int) ($rows['offer']['id'] ?? 0);
    $now = $post('/api.php?action=link_check_now', ['ids' => [$offerRow]], $admin);
    $assert(['success', 1], [$now['status'] ?? null, $now['data']['queued'] ?? null], '"Check now" books the link for the worker');
    $assert(true, strtotime($pdo->query('SELECT next_check_at FROM link_checks WHERE id = ' . $offerRow)->fetchColumn() . ' UTC') <= time() + 31, 'And the booking is visible in the database');
    $log = $get('/api.php?action=link_checks_log&id=' . $offerRow, $admin);
    $assert(['success', []], [$log['status'] ?? null, $log['data'] ?? null], 'A link that was never checked has an empty history');

    // --- A limited account reads, but neither books checks nor configures -------------
    $assert('success', $get('/api.php?action=link_checks_list', $buyer)['status'] ?? null, 'A limited account sees the list');
    $assert('success', $get('/api.php?action=link_checks_summary', $buyer)['status'] ?? null, 'A limited account sees the summary');
    foreach ([
        'books a check' => ['POST', '/api.php?action=link_check_now', ['ids' => [$offerRow]]],
        'reads settings' => ['GET', '/api.php?action=link_checks_settings', null],
        'saves settings' => ['POST', '/api.php?action=save_link_checks_settings', ['enabled' => false]],
        'lists proxies' => ['GET', '/api.php?action=check_proxies', null],
        'edits a link' => ['POST', '/api.php?action=save_link_check', ['id' => $offerRow, 'interval_min' => 720]],
    ] as $label => [$method, $path, $data]) {
        $resp = $method === 'GET' ? $get($path, $buyer) : $post($path, $data, $buyer);
        $assert('error', $resp['status'] ?? null, "A limited account cannot {$label}");
    }
    $assert('1', (string) $pdo->query("SELECT value FROM settings WHERE key = 'link_checks_enabled'")->fetchColumn(), 'The refused save did not switch the feature off');

    // --- Proxies: the password stays on the server ------------------------------------
    $refused = $post('/api.php?action=save_check_proxy', ['url' => 'http://127.0.0.1:3128'], $admin);
    $assert(['error', 'proxy_private'], [$refused['status'] ?? null, $refused['code'] ?? null], 'A proxy on a private address is refused through the API, with a code the panel translates');
    $refused = $post('/api.php?action=save_check_proxy', ['url' => 'ftp://203.0.113.10:21'], $admin);
    $assert('error', $refused['status'] ?? null, 'A malformed proxy line is refused through the API');
    $pdo->exec("INSERT INTO check_proxies (name, type, host, port, username, password, status) VALUES ('GR-1', 'socks5', '203.0.113.10', 1080, 'bob', 'secret', 'ok')");
    $proxyId = (int) $pdo->lastInsertId();
    $proxies = $get('/api.php?action=check_proxies', $admin);
    $assert(['GR-1', true], [$proxies['data'][0]['name'] ?? null, $proxies['data'][0]['has_password'] ?? null], 'The proxy is listed, with the fact that it has a password');
    $assert(false, array_key_exists('password', $proxies['data'][0] ?? []), 'The password itself never leaves the server');

    // --- Per-link settings ------------------------------------------------------------
    $edited = $post('/api.php?action=save_link_check', ['id' => $offerRow, 'interval_min' => 120, 'proxy_id' => $proxyId, 'expected_mode' => 'list', 'expected_domains' => "go.example.com"], $admin);
    $assert(['success', 120, 'list', [$proxyId]], [$edited['status'] ?? null, $edited['data']['interval_min'] ?? null, $edited['data']['expected_mode'] ?? null, [$edited['data']['proxy_id'] ?? null]], 'Per-link settings are saved through the API');
    $assert('error', $post('/api.php?action=save_link_check', ['id' => $offerRow, 'expected_domains' => 'not a domain'], $admin)['status'] ?? null, 'An invalid domain is refused');
    $assert('error', $post('/api.php?action=save_link_check', ['id' => 999999, 'interval_min' => 60], $admin)['status'] ?? null, 'An unknown link is refused');
    $deleted = $post('/api.php?action=delete_check_proxy', ['id' => $proxyId], $admin);
    $assert([['success'], null], [[$deleted['status'] ?? null], $pdo->query('SELECT proxy_id FROM link_checks WHERE id = ' . $offerRow)->fetchColumn()], 'Deleting a proxy frees the links that used it');

    // --- Bulk proxy import through the API -------------------------------------------
    $bulkResp = $post('/api.php?action=save_check_proxy', [
        'urls' => "1.1.1.1:1080:u1:p1\n1.0.0.1:1080:u2:p2\n127.0.0.1:8080\n1.1.1.1:1080:u1:p1",
        'default_type' => 'socks5',
        'name' => 'EU',
        'probe' => false,
    ], $admin);
    $assert(['success', 2, 1, 1], [
        $bulkResp['status'] ?? null,
        $bulkResp['data']['added_count'] ?? null,
        $bulkResp['data']['skipped'] ?? null,
        count($bulkResp['data']['errors'] ?? []),
    ], 'Bulk proxy import accepts host:port:user:pass lists, deduplicates and refuses private addresses');

    // --- The Telegram switch ------------------------------------------------------------
    $pdo->exec("INSERT INTO telegram_bot_chats (chat_id, language) VALUES ('424242', 'ru')");
    $assert('success', $post('/api.php?action=telegram_chat_flags', ['chat_id' => '424242', 'flag' => 'link_checks', 'value' => false], $admin)['status'] ?? null, 'Link-check alerts can be switched off for a chat');
    $assert(0, (int) $pdo->query("SELECT notify_link_checks FROM telegram_bot_chats WHERE chat_id = '424242'")->fetchColumn(), 'And the switch is stored');
    $chats = $get('/api.php?action=telegram_settings', $admin);
    $assert([false, true], [!empty($chats['data']['chats'][0]['notify_link_checks']), array_key_exists('notify_link_checks', $chats['data']['chats'][0] ?? [])], 'The chat list carries the switch back to the panel');

    // --- The worker banner still answers --------------------------------------------------
    $assert('success', $get('/api.php?action=worker_health', $admin)['status'] ?? null, 'worker_health answers with the feature switched on');
} catch (Throwable $e) {
    $failures++;
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
} finally {
    $harness->stop();
}

echo "Link checks HTTP tests: {$checks} checks, {$failures} failures.\n";
exit($failures === 0 ? 0 : 1);
