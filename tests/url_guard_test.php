<?php
// Run: php tests/url_guard_test.php
// The server-side availability check (core/url_guard.php) must refuse private
// and reserved targets on every redirect hop. The pure helpers are checked
// directly; the redirect walker runs against a disposable local server that
// the test's own resolver pins two fake hostnames to.

require_once __DIR__ . '/../core/url_guard.php';

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

$vet = static function (string $url) use ($assert, &$checks): void {
    $checks++;
    $result = orbitraUrlGuardResolve($url);
    if ($result['ok']) {
        $failures++;
        fwrite(STDERR, "FAIL: {$url} is accepted\n  actual:   " . var_export($result, true) . "\n");
    }
};

// --- What counts as a public address -------------------------------------
foreach ([
    // Private and reserved IPv4, plus ranges ext/filter does not know about.
    '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1', '127.0.0.1', '0.0.0.0',
    '169.254.169.254', '100.64.0.1', '100.127.255.254', '198.18.0.1', '240.0.0.1', '255.255.255.255',
    // IPv6: loopback, unspecified, link-local, unique-local, IPv4-embedded, NAT64.
    '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', '::ffff:127.0.0.1', '::ffff:8.8.8.8', '64:ff9b::7f00:1',
    'not-an-ip', '',
] as $ip) {
    $assert(false, orbitraUrlGuardIsPublicIp($ip), "Not public: {$ip}");
}
foreach ([
    '8.8.8.8', '1.1.1.1', '100.63.255.255', '100.128.0.1', '198.17.255.255', '198.20.0.1',
    '172.32.0.1', '172.15.255.255', '192.169.0.1',
    '2001:4860:4860::8888', '2606:4700::1111', '2a00:1450:4001:81b::200e',
] as $ip) {
    $assert(true, orbitraUrlGuardIsPublicIp($ip), "Public: {$ip}");
}

// --- Which URLs the guard vetoes ------------------------------------------
foreach ([
    'http://127.0.0.1/', 'http://127.0.0.1:8080/admin', 'http://10.0.0.5/', 'http://169.254.169.254/latest/meta-data/',
    'http://[::1]:8080/', 'http://localhost/', 'http://orbitra-url-guard-test.invalid/',
    'file:///etc/passwd', 'gopher://8.8.8.8/', 'ftp://example.com/', 'http://', 'not a url',
] as $url) {
    $vet($url);
}
$assert(['ok' => true, 'host' => '8.8.8.8', 'port' => 443, 'addresses' => ['8.8.8.8'], 'reason' => ''],
    orbitraUrlGuardResolve('https://8.8.8.8/'), 'IPv4 literal: vetted and kept for pinning');
$assert(8080, orbitraUrlGuardResolve('http://1.1.1.1:8080/x')['port'], 'Explicit port survives into the pin');
$assert('h.test:8443:1.2.3.4', orbitraUrlGuardPin(['host' => 'h.test', 'port' => 8443, 'addresses' => ['1.2.3.4']]), 'IPv4 pin');
$assert('h.test:443:[2001:4860:4860::8888]', orbitraUrlGuardPin(['host' => 'h.test', 'port' => 443, 'addresses' => ['2001:4860:4860::8888']]), 'IPv6 pin is bracketed');

// --- Location handling -----------------------------------------------------
foreach ([
    ['/a', 'https://h.test/x/y', 'https://h.test/a'],
    ['//other.test/p', 'http://h.test/', 'http://other.test/p'],
    ['next', 'http://h.test:8080/dir/page', 'http://h.test:8080/dir/next'],
    ['../up', 'http://h.test/a/b/c', 'http://h.test/a/up'],
    ['?q=1', 'http://h.test/p', 'http://h.test/p?q=1'],
    ['https://x.test/z', 'http://h.test/', 'https://x.test/z'],
    ['file:///etc/passwd', 'http://h.test/', 'file:///etc/passwd'],
] as [$location, $base, $expected]) {
    $assert($expected, orbitraUrlGuardAbsolute($location, $base), "Location {$location} against {$base}");
}
$assert('http://x.test/a', orbitraUrlGuardLocation("HTTP/1.1 302 Found\r\nServer: x\r\nlocation: http://x.test/a\r\n\r\n"), 'Location header is case-insensitive');
$assert('', orbitraUrlGuardLocation("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"), 'No Location header');

// --- The redirect walker against a disposable server ----------------------
$dir = sys_get_temp_dir() . '/orbitra-url-guard-' . bin2hex(random_bytes(4));
if (!mkdir($dir, 0700)) {
    fwrite(STDERR, "ERROR: could not create {$dir}\n");
    exit(1);
}
$log = $dir . '/requests.log';
$router = $dir . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
// Disposable target for tests/url_guard_test.php: logs every request, redirects on a few paths.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(getenv('URL_GUARD_TEST_LOG'), $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['HTTP_HOST'] . $path . "\n", FILE_APPEND | LOCK_EX);
$port = getenv('URL_GUARD_TEST_PORT');
switch ($path) {
    case '/ping':        http_response_code(204); break;
    case '/ok':          http_response_code(200); break;
    case '/missing':     http_response_code(404); break;
    case '/page':        header('Content-Type: text/html'); echo '<html><head></head><body>PAGE</body></html>'; break;
    case '/page-r':      header('Location: /page'); http_response_code(302); break;
    case '/slow0':
    case '/slow1':
    case '/slow2':
        // Each slow hop takes most of a second before it redirects.
        usleep(400000);
        header('Location: /slow' . ((int) substr($path, 5) + 1));
        http_response_code(302);
        break;
    case '/slow3':       http_response_code(200); break;
    case '/r1':          header('Location: /r2'); http_response_code(302); break;
    case '/r2':          header('Location: http://tracker.test:' . $port . '/r3'); http_response_code(301); break;
    case '/r3':          header('Location: ../hop4'); http_response_code(302); break;
    case '/hop4':        header('Location: /ok'); http_response_code(302); break;
    case '/to-internal': header('Location: http://127.0.0.1:' . $port . '/internal'); http_response_code(302); break;
    case '/internal':    http_response_code(200); break;
    case '/to-file':     header('Location: file:///etc/passwd'); http_response_code(302); break;
    case '/loop-a':      header('Location: /loop-b'); http_response_code(302); break;
    case '/loop-b':      header('Location: /loop-a'); http_response_code(302); break;
    default:
        if (preg_match('#^/chain([0-9])$#', $path, $match)) {
            header('Location: /chain' . ((int) $match[1] + 1));
            http_response_code(302);
            break;
        }
        http_response_code(404);
}
PHP);

$process = null;
$pid = null;
$pipes = [];
$port = 0;
try {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $candidate = random_int(20000, 65000);
        $socket = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.1);
        if ($socket === false) {
            $port = $candidate;
            break;
        }
        fclose($socket);
    }
    if ($port === 0) {
        throw new RuntimeException('Could not find a free port for the test server');
    }

    // The command MUST stay an array: a string would run through /bin/sh -c and
    // make $pid the shell's, orphaning the server on stop.
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir, $router],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $dir,
        array_merge(array_filter(getenv(), 'is_string'), [
            'URL_GUARD_TEST_LOG' => $log,
            'URL_GUARD_TEST_PORT' => (string) $port,
        ])
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start the test server');
    }
    $pid = proc_get_status($process)['pid'];
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        if (@file_get_contents('http://127.0.0.1:' . $port . '/ping', false, $context) !== false) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('Test server did not start: ' . stream_get_contents($pipes[2]));
    }

    $hits = static function (string $path) use ($log): int {
        return substr_count((string) @file_get_contents($log), $path . "\n");
    };

    // The two fake hosts stand in for public ones — the real guard stops at
    // their DNS — and are pinned to the disposable server by hand. Everything
    // else goes through the real guard, exactly as in production.
    $testResolve = static function (string $url) use ($port): array {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if (!in_array($host, ['source.test', 'tracker.test'], true)) {
            return orbitraUrlGuardResolve($url);
        }
        return ['ok' => true, 'host' => $host, 'port' => $port, 'addresses' => ['127.0.0.1'], 'reason' => ''];
    };
    $base = 'http://source.test:' . $port;

    $assert(['status' => '200', 'message' => 'OK'], orbitraUrlCheckFollow($base . '/ok', $testResolve), 'Direct 200');
    $assert(1, $hits('/ok'), 'HEAD request reached the server with the pinned host');
    $assert(['status' => '404', 'message' => 'Not Found'], orbitraUrlCheckFollow($base . '/missing', $testResolve), '404 is reported');
    $assert(['status' => 'error', 'message' => 'Invalid URL'], orbitraUrlCheckFollow('not a url', $testResolve), 'Invalid URL');

    $assert(['status' => '200', 'message' => 'OK'], orbitraUrlCheckFollow($base . '/r1', $testResolve), 'Chain of 4 redirects ends in 200');
    foreach (['/r1', '/r2', '/r3', '/hop4'] as $hop) {
        $assert(1, $hits($hop), "Redirect chain visited {$hop} once");
    }
    $assert(2, $hits('/ok'), 'Chain ends on /ok');

    $internal = orbitraUrlCheckFollow($base . '/to-internal', $testResolve);
    $assert('error', $internal['status'], 'Redirect to a loopback address is refused');
    $assert('Host resolves to a private or reserved address', $internal['message'], 'Refusal names the reason');
    $assert(0, $hits('/internal'), 'The loopback target was never requested');
    $file = orbitraUrlCheckFollow($base . '/to-file', $testResolve);
    $assert('error', $file['status'], 'Redirect to a file:// URL is refused');

    // The same walker serves pages, not just statuses: preload landings and
    // proxied offers get their HTML through it.
    $page = orbitraUrlGuardFetch($base . '/page-r', [CURLOPT_TIMEOUT => 5, CURLOPT_USERAGENT => 'orbitra-test'], $testResolve);
    $assert(200, $page['status'], 'GET follows redirects and reports the final status');
    $assert($base . '/page', $page['url'], 'GET reports the final URL');
    $assert('<html><head></head><body>PAGE</body></html>', $page['body'], 'GET returns the body without the headers');
    $assert('', $page['error'] . $page['reason'], 'GET has no error');
    $refused = orbitraUrlGuardFetch($base . '/to-internal', [], $testResolve);
    $assert('Host resolves to a private or reserved address', $refused['reason'], 'GET is refused on a loopback redirect hop');
    $assert('', $refused['body'], 'A refused GET returns no body');
    $assert(0, $hits('/internal'), 'The loopback target is still untouched');

    // The timeout is one budget for the whole chain, not a fresh one per hop.
    $startedAt = microtime(true);
    $slow = orbitraUrlGuardFetch($base . '/slow0', [CURLOPT_TIMEOUT => 1, CURLOPT_CONNECTTIMEOUT => 1], $testResolve);
    $elapsed = microtime(true) - $startedAt;
    $assert(true, str_contains($slow['error'], 'timed out'), 'A chain slower than its budget times out');
    $assert(0, $hits('/slow3'), 'The chain stops at the budget, not at the last hop');
    $assert(true, $elapsed < 2.5, 'The whole chain stays near its single budget (took ' . round($elapsed, 2) . ' s)');

    $assert(['status' => 'error', 'message' => 'Redirect loop'],
        orbitraUrlCheckFollow($base . '/loop-a', $testResolve), 'Redirect loop is detected');
    $assert(1, $hits('/loop-b'), 'Loop: the second hop is not fetched again');
    $assert(['status' => 'error', 'message' => 'Too many redirects'],
        orbitraUrlCheckFollow($base . '/chain0', $testResolve), 'Redirect limit is enforced');
    $assert(1, $hits('/chain' . ORBITRA_URL_GUARD_MAX_REDIRECTS), 'The last allowed hop is fetched');
    $assert(0, $hits('/chain' . (ORBITRA_URL_GUARD_MAX_REDIRECTS + 1)), 'One hop past the limit is not fetched');

    // The public entry point always uses the real guard: a loopback target is
    // refused even when the test server would happily answer it.
    $assert('error', checkUrlAvailability('http://127.0.0.1:' . $port . '/ok')['status'], 'checkUrlAvailability refuses loopback');
    $assert('error', checkUrlAvailability('127.0.0.1:' . $port . '/ok')['status'], 'Scheme-less loopback is refused too');
    $assert(2, $hits('/ok'), 'Refused requests never reached the server');
} catch (Throwable $e) {
    $failures++;
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            @fclose($pipe);
        }
    }
    if (is_resource($process)) {
        @proc_terminate($process);
        usleep(100000);
        // PHP_CLI_SERVER_WORKERS children can outlive a SIGTERM; sweep the
        // server set by its exact host:port so nothing listens after the run.
        if (function_exists('exec')) {
            @exec('pkill -9 -f ' . escapeshellarg('php -S 127.0.0.1:' . $port) . ' >/dev/null 2>&1');
        }
        @proc_close($process);
    }
    foreach ([$router, $log] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($dir);
}

echo "URL guard tests: {$checks} checks, {$failures} failures.\n";
exit($failures === 0 ? 0 : 1);
