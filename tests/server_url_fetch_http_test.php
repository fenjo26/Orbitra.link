<?php
// Run: php tests/server_url_fetch_http_test.php
// Preload landings and "curl_proxy" offers are fetched by the server on the
// click path. A URL that points at a loopback-only service must never be
// requested by the server: the click falls back to a plain redirect instead,
// and the page body never reaches the visitor.

require_once __DIR__ . '/lib/http.php';

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

// A loopback-only "internal" service that logs every request it receives.
$internalDir = sys_get_temp_dir() . '/orbitra-internal-' . bin2hex(random_bytes(4));
if (!mkdir($internalDir, 0700)) {
    fwrite(STDERR, "ERROR: could not create {$internalDir}\n");
    exit(1);
}
$internalLog = $internalDir . '/requests.log';
$internalRouter = $internalDir . '/router.php';
file_put_contents($internalRouter, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(getenv('INTERNAL_TEST_LOG'), $_SERVER['REQUEST_METHOD'] . ' ' . $path . "\n", FILE_APPEND | LOCK_EX);
if ($path === '/ping') {
    http_response_code(204);
    return;
}
echo '<html><head></head><body>INTERNAL SECRET</body></html>';
PHP);

$internalProcess = null;
$internalPipes = [];
$internalPort = 0;
$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();

try {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $candidate = random_int(20000, 65000);
        $socket = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.1);
        if ($socket === false) {
            $internalPort = $candidate;
            break;
        }
        fclose($socket);
    }
    if ($internalPort === 0) {
        throw new RuntimeException('Could not find a free port for the internal service');
    }

    // The command MUST stay an array: a string would run through /bin/sh -c and
    // make the pid the shell's, orphaning the server on stop.
    $internalProcess = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $internalPort, '-t', $internalDir, $internalRouter],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $internalPipes,
        $internalDir,
        array_merge(array_filter(getenv(), 'is_string'), ['INTERNAL_TEST_LOG' => $internalLog])
    );
    if (!is_resource($internalProcess)) {
        throw new RuntimeException('Failed to start the internal service');
    }
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        if (@file_get_contents('http://127.0.0.1:' . $internalPort . '/ping', false, $context) !== false) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('Internal service did not start');
    }
    @unlink($internalLog);

    $harness->start();
    $pdo = $harness->getPdo();
    $insert = static function (string $table, array $values) use ($pdo): int {
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', array_keys($values))
            . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')';
        $pdo->prepare($sql)->execute(array_values($values));
        return (int) $pdo->lastInsertId();
    };
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES
        ('postback_key', 'guarded-fetch-test-key'), ('stats_enabled', '1'), ('ignore_prefetch', '0')");

    $internalUrl = 'http://127.0.0.1:' . $internalPort . '/internal';
    $number = 0;
    $campaign = static function (string $schemaType, array $offers, array $landings) use ($insert, &$number): array {
        $number++;
        $alias = 'guardedfetch' . $number;
        $id = $insert('campaigns', ['name' => $alias, 'alias' => $alias, 'token' => 'guarded-fetch-' . $number, 'state' => 'active']);
        $insert('streams', [
            'campaign_id' => $id, 'name' => $alias, 'type' => 'regular', 'position' => 1,
            'schema_type' => $schemaType,
            'schema_custom_json' => json_encode([
                'offers' => $offers, 'landings' => $landings,
                'detect_datacenter' => false, 'detect_vpn' => false, 'detect_bots' => false, 'detect_ua' => false,
            ]),
            'offer_selection' => 'before', 'is_active' => 1, 'collect_clicks' => 1,
        ]);
        return ['alias' => $alias];
    };
    $requestNumber = 0;
    $click = static function (string $alias) use ($harness, &$requestNumber): array {
        // A unique synthetic visitor per request also avoids the browser debounce.
        $requestNumber++;
        $ctx = stream_context_create(['http' => [
            'timeout' => 8,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
                'X-Forwarded-For: 203.0.113.' . $requestNumber,
            ]),
        ]]);
        $body = @file_get_contents($harness->getBaseUrl() . '/' . $alias, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('Local test server did not answer /' . $alias);
        }
        $lines = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?: []) : ($http_response_header ?? []);
        $code = 0;
        $location = '';
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\d\.\d (\d{3})#', $line, $match)) {
                $code = (int) $match[1];
            } elseif (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
            }
        }
        return ['code' => $code, 'body' => $body, 'location' => $location];
    };
    $internalHits = static function () use ($internalLog): string {
        return trim((string) @file_get_contents($internalLog));
    };

    $preloadLanding = $insert('landings', ['name' => 'Preload to loopback', 'type' => 'preload', 'url' => $internalUrl]);
    $preload = $click($campaign('landing_offer', [], [['id' => $preloadLanding, 'weight' => 100]])['alias']);
    $assert(302, $preload['code'], 'Preload landing at a loopback URL falls back to a redirect');
    $assert($internalUrl, $preload['location'], 'Preload fallback redirects to the landing URL');
    $assert(false, strpos($preload['body'], 'INTERNAL SECRET'), 'Preload fallback leaks no upstream body');
    $assert('', $internalHits(), 'The server did not fetch the preload landing');

    $proxiedOffer = $insert('offers', ['name' => 'Proxied to loopback', 'url' => $internalUrl, 'redirect_type' => 'curl_proxy']);
    $proxied = $click($campaign('redirect', [['id' => $proxiedOffer, 'weight' => 100]], [])['alias']);
    $assert(302, $proxied['code'], 'curl_proxy offer at a loopback URL falls back to a redirect');
    $assert($internalUrl, $proxied['location'], 'curl_proxy fallback redirects to the offer URL');
    $assert(false, strpos($proxied['body'], 'INTERNAL SECRET'), 'curl_proxy fallback leaks no upstream body');
    $assert('', $internalHits(), 'The server did not fetch the proxied offer');

    // Control: a plain redirect sends the VISITOR there, which is the visitor's
    // own browser and not the server — unchanged behaviour.
    $redirectOffer = $insert('offers', ['name' => 'Redirect to loopback', 'url' => $internalUrl, 'redirect_type' => 'redirect']);
    $redirect = $click($campaign('redirect', [['id' => $redirectOffer, 'weight' => 100]], [])['alias']);
    $assert(302, $redirect['code'], 'Plain redirect still redirects');
    $assert($internalUrl, $redirect['location'], 'Plain redirect target is unchanged');
    $assert('', $internalHits(), 'A plain redirect never involves the server');
} catch (Throwable $e) {
    $failures++;
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
} finally {
    $harness->stop();
    foreach ($internalPipes as $pipe) {
        if (is_resource($pipe)) {
            @fclose($pipe);
        }
    }
    if (is_resource($internalProcess)) {
        @proc_terminate($internalProcess);
        usleep(100000);
        if (function_exists('exec')) {
            @exec('pkill -9 -f ' . escapeshellarg('php -S 127.0.0.1:' . $internalPort) . ' >/dev/null 2>&1');
        }
        @proc_close($internalProcess);
    }
    foreach ([$internalRouter, $internalLog] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($internalDir);
}

echo "Server URL fetch tests: {$checks} checks, {$failures} failures.\n";
exit($failures === 0 ? 0 : 1);
