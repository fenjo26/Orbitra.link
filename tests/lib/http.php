<?php
/**
 * HTTP test harness for Orbitra integration tests.
 *
 * Starts a real PHP built-in web server on a random port, provides a helper
 * for making HTTP requests, and ensures the server is shut down even on
 * fatal error or test interruption.
 *
 * Usage:
 *
 *     $harness = new OrbitraTestHarness(__DIR__ . '/../..');
 *     $harness->start();
 *     try {
 *         $resp = $harness->get('/fd12e72/postback?subid=test&status=lead');
 *         assertEquals(200, $resp['code']);
 *     } finally {
 *         $harness->stop();
 *     }
 *
 * The harness automatically cleans up on exit via register_shutdown_function.
 */

class OrbitraTestHarness
{
    private string $repoRoot;
    private ?int $pid = null;
    private string $host = '127.0.0.1';
    private ?int $port = null;
    private string $routerFile;
    private ?string $testDbPath = null;
    private ?string $originalDbPath = null;
    private ?string $workingDir = null;

    /** @var resource|null */
    private $serverStdout = null;

    /** @var resource|null */
    private $serverStderr = null;

    /** @var resource|null */
    private $serverStdin = null;

    private bool $stopped = false;

    /**
     * @param string $repoRoot Path to the repository root (contains index.php, config.php, etc.)
     */
    public function __construct(string $repoRoot)
    {
        $this->repoRoot = rtrim($repoRoot, '/');

        // Prefer index.php for routing (it handles trailing slash correctly)
        // Fall back to router.php for legacy compatibility
        $candidates = [
            $this->repoRoot . '/index.php',
            $this->repoRoot . '/router.php',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $this->routerFile = $path;
                break;
            }
        }
        if (!isset($this->routerFile)) {
            throw new RuntimeException('No router file found (router.php or index.php)');
        }

        // Register shutdown handler to ensure server is killed even on fatal error
        register_shutdown_function(function () {
            if (isset($this) && !$this->stopped) {
                $this->stop();
            }
        });
    }

    /**
     * Start the PHP built-in web server on a random available port.
     *
     * @throws RuntimeException If PHP binary cannot be found or server fails to start
     */
    public function start(): void
    {
        if ($this->pid !== null) {
            return; // Already started
        }

        // Find PHP binary
        $phpBinary = $this->findPhpBinary();
        if ($phpBinary === null) {
            throw new RuntimeException('PHP binary not found. Ensure PHP is in PATH or PHP_BINARY is set.');
        }

        // Find a free port
        $this->port = $this->findFreePort();
        if ($this->port === null) {
            throw new RuntimeException('Could not find a free port for the test server');
        }

        // Prepare the test database - use a temporary SQLite file
        $this->setupTestDatabase();

        // Start the server in the background
        $cmd = sprintf(
            '%s -S %s:%d -t %s %s',
            escapeshellarg($phpBinary),
            $this->host,
            $this->port,
            escapeshellarg($this->repoRoot),
            escapeshellarg($this->routerFile)
        );

        $descriptorspec = [
            0 => ['pipe', 'r'],   // stdin
            1 => ['pipe', 'w'],   // stdout
            2 => ['pipe', 'w'],   // stderr
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes, $this->repoRoot);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start PHP server');
        }

        $this->pid = proc_get_status($process)['pid'] ?? null;
        if (!$this->pid) {
            proc_close($process);
            throw new RuntimeException('Failed to get server PID');
        }

        $this->serverStdin = $pipes[0];
        $this->serverStdout = $pipes[1];
        $this->serverStderr = $pipes[2];

        // Set streams to non-blocking mode
        stream_set_blocking($this->serverStdout, false);
        stream_set_blocking($this->serverStderr, false);

        // Wait for server to be ready
        $ready = $this->waitForServer(10); // 10 second timeout
        if (!$ready) {
            $this->stop();
            throw new RuntimeException('PHP server failed to start within timeout');
        }
    }

    /**
     * Make an HTTP GET request to the test server.
     *
     * @param string $path Request path (e.g., '/fd12e72/postback?subid=test&status=lead')
     * @return array{code:int, body:string, headers:array<string,string>}
     * @throws RuntimeException If server is not running or request fails
     */
    public function get(string $path): array
    {
        if ($this->port === null || $this->pid === null) {
            throw new RuntimeException('Server is not running. Call start() first.');
        }

        // Ensure path starts with /
        $path = '/' . ltrim($path, '/');

        $url = sprintf('http://%s:%d%s', $this->host, $this->port, $path);

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true, // We want to read the response even on 4xx/5xx
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $error = error_get_last();
            throw new RuntimeException(
                sprintf('HTTP request failed: %s', $error['message'] ?? 'unknown error')
            );
        }

        // Parse response headers using PHP 8.5+ function or fallback
        $headers = [];
        $statusCode = 200;
        if (function_exists('http_get_last_response_headers')) {
            $responseHeaders = http_get_last_response_headers();
        } else {
            $responseHeaders = $http_response_header ?? [];
        }

        if (is_array($responseHeaders)) {
            foreach ($responseHeaders as $header) {
                if (strpos($header, ':') !== false) {
                    [$key, $value] = explode(':', $header, 2);
                    $headers[trim($key)] = trim($value);
                }
            }
            // Extract status code from the first header
            $statusLine = $responseHeaders[0] ?? '';
            if (preg_match('#^HTTP/\d\.\d (\d+)#', $statusLine, $match)) {
                $statusCode = (int) $match[1];
            }
        }

        return [
            'code' => $statusCode,
            'body' => $body,
            'headers' => $headers,
        ];
    }

    /**
     * Get the base URL for the test server.
     *
     * @return string e.g., 'http://127.0.0.1:12345'
     * @throws RuntimeException If server is not running
     */
    public function getBaseUrl(): string
    {
        if ($this->port === null) {
            throw new RuntimeException('Server is not running. Call start() first.');
        }
        return sprintf('http://%s:%d', $this->host, $this->port);
    }

    /**
     * Stop the test server and clean up resources.
     */
    public function stop(): void
    {
        $this->stopped = true;

        // Close pipes
        foreach ([$this->serverStdin, $this->serverStdout, $this->serverStderr] as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->serverStdin = $this->serverStdout = $this->serverStderr = null;

        // Kill the server process
        if ($this->pid !== null) {
            // Try graceful shutdown first
            @posix_kill($this->pid, SIGTERM);

            // Wait a bit and force kill if still running
            usleep(100000); // 100ms
            if (@posix_kill($this->pid, 0)) {
                @posix_kill($this->pid, SIGKILL);
            }

            $this->pid = null;
        }

        // Restore original database if we replaced it
        $this->restoreDatabase();
    }

    /**
     * Seed test data into the test database.
     *
     * Creates a campaign, offer, and click with known IDs for testing.
     *
     * @return array{campaign_id:int, offer_id:int, click_id:string, postback_key:string}
     * @throws RuntimeException If database operations fail
     */
    public function seedTestData(): array
    {
        if ($this->testDbPath === null) {
            throw new RuntimeException('Test database not set up. Call start() first.');
        }

        try {
            $pdo = new PDO('sqlite:' . $this->testDbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Generate test data
            $testClickId = 'test-click-' . bin2hex(random_bytes(8));
            $testCampaignId = random_int(10000, 99999);
            $testOfferId = random_int(10000, 99999);
            $testPostbackKey = 'testkey42';

            // Insert conversion types
            $pdo->exec("
                INSERT OR IGNORE INTO conversion_types (name, status_values)
                VALUES
                    ('lead', 'lead,approved'),
                    ('sale', 'sale,sale,approved'),
                    ('rejected', 'rejected,rejected,declined'),
                    ('trash', 'trash')
            ");

            // Insert campaign
            $pdo->prepare("
                INSERT INTO campaigns (id, name, alias, token, state, is_archived)
                VALUES (?, ?, ?, ?, 'active', 0)
            ")->execute([
                $testCampaignId,
                'Test Campaign',
                'testcamp',
                'test-token-' . $testCampaignId,
            ]);

            // Insert offer
            $pdo->prepare("
                INSERT INTO offers (id, name, url, is_local, state, is_archived)
                VALUES (?, ?, ?, 0, 'active', 0)
            ")->execute([
                $testOfferId,
                'Test Offer',
                'https://example.com/offer',
            ]);

            // Insert click
            $pdo->prepare("
                INSERT INTO clicks (id, campaign_id, offer_id, ip, user_agent, country_code)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([
                $testClickId,
                $testCampaignId,
                $testOfferId,
                '127.0.0.1',
                'Test-Agent/1.0',
                'US',
            ]);

            // Set postback_key in settings
            $pdo->prepare("
                INSERT OR REPLACE INTO settings (key, value)
                VALUES ('postback_key', ?)
            ")->execute([$testPostbackKey]);

            $pdo = null;

            return [
                'campaign_id' => $testCampaignId,
                'offer_id' => $testOfferId,
                'click_id' => $testClickId,
                'postback_key' => $testPostbackKey,
            ];
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to seed test data: ' . $e->getMessage());
        }
    }

    /**
     * Update the postback_key in the test database.
     *
     * @param string $newKey New postback key value
     * @throws RuntimeException If database operation fails
     */
    public function setPostbackKey(string $newKey): void
    {
        if ($this->testDbPath === null) {
            throw new RuntimeException('Test database not set up. Call start() first.');
        }

        try {
            $pdo = new PDO('sqlite:' . $this->testDbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', ?)")
                ->execute([$newKey]);
            $pdo = null;
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to update postback_key: ' . $e->getMessage());
        }
    }

    /**
     * Get the number of conversion rows in the test database.
     *
     * @return int Number of conversions
     * @throws RuntimeException If database operation fails
     */
    public function countConversions(): int
    {
        if ($this->testDbPath === null) {
            throw new RuntimeException('Test database not set up. Call start() first.');
        }

        try {
            $pdo = new PDO('sqlite:' . $this->testDbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $pdo->query("SELECT COUNT(*) FROM conversions");
            $count = (int) $stmt->fetchColumn();
            $pdo = null;
            return $count;
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to count conversions: ' . $e->getMessage());
        }
    }

    /**
     * Check if a specific click exists in the conversions table.
     *
     * @param string $clickId Click ID to check
     * @return bool True if conversion exists for the click
     * @throws RuntimeException If database operation fails
     */
    public function hasConversionForClick(string $clickId): bool
    {
        if ($this->testDbPath === null) {
            throw new RuntimeException('Test database not set up. Call start() first.');
        }

        try {
            $pdo = new PDO('sqlite:' . $this->testDbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $pdo->prepare("SELECT 1 FROM conversions WHERE click_id = ? LIMIT 1");
            $stmt->execute([$clickId]);
            $exists = $stmt->fetch() !== false;
            $pdo = null;
            return $exists;
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to check conversion: ' . $e->getMessage());
        }
    }

    /**
     * Wait for the server to become ready.
     *
     * @param int $timeoutSeconds Maximum time to wait in seconds
     * @return bool True if server is ready, false if timeout occurred
     */
    private function waitForServer(int $timeoutSeconds): bool
    {
        $startTime = time();
        $timeout = $timeoutSeconds;

        while (time() - $startTime < $timeout) {
            // Try to fetch a simple page
            $context = stream_context_create([
                'http' => [
                    'timeout' => 1,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $url = sprintf('http://%s:%d/', $this->host, $this->port);
            $result = @file_get_contents($url, false, $context);

            if ($result !== false) {
                // Server is responding
                return true;
            }

            // Check if process is still running
            if ($this->pid !== null && !@posix_kill($this->pid, 0)) {
                // Process died
                return false;
            }

            usleep(100000); // Wait 100ms before retry
        }

        return false;
    }

    /**
     * Find a free port on localhost.
     *
     * @return int|null Available port number, or null if none found
     */
    private function findFreePort(): ?int
    {
        $maxAttempts = 50;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $port = random_int(20000, 65000);

            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket === false) {
                // Port is available
                return $port;
            }
            fclose($socket);
        }

        return null;
    }

    /**
     * Find the PHP binary executable.
     *
     * @return string|null Path to PHP binary, or null if not found
     */
    private function findPhpBinary(): ?string
    {
        // Try PHP_BINARY constant (available in most modern PHP)
        if (defined('PHP_BINARY') && is_file(PHP_BINARY)) {
            return PHP_BINARY;
        }

        // Try common paths
        $candidates = [
            '/opt/homebrew/bin/php',
            '/usr/local/bin/php',
            '/usr/bin/php',
            'php',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === 'php') {
                // Try from PATH
                $which = shell_exec('which php 2>/dev/null');
                if ($whom !== null && trim($which) !== '') {
                    $path = trim($which);
                    if (is_file($path)) {
                        return $path;
                    }
                }
            } else {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Set up a test database on a temporary file.
     *
     * Creates a temporary working directory with copies of the necessary files
     * and a custom config.php that points to a test SQLite database.
     */
    private function setupTestDatabase(): void
    {
        // Create a temporary working directory
        $this->workingDir = tempnam(sys_get_temp_dir(), 'orbitra_test_work_');
        unlink($this->workingDir);
        mkdir($this->workingDir, 0700, true);

        // Create a temporary database file
        $this->testDbPath = $this->workingDir . '/orbitra_test.sqlite';

        // Copy necessary files from the repo root
        $filesToCopy = [
            'index.php',
            'postback.php',
            'router.php',
            'admin.php',
            'api.php',
            'telegram_notify.php',
            'session_bootstrap.php',
            'core',
        ];

        foreach ($filesToCopy as $item) {
            $src = $this->repoRoot . '/' . $item;
            $dst = $this->workingDir . '/' . $item;

            if (is_dir($src)) {
                $this->copyDirectory($src, $dst);
            } elseif (is_file($src)) {
                copy($src, $dst);
            }
        }

        // Create a custom config.php that uses the test database
        $configSrc = file_get_contents($this->repoRoot . '/config.php');
        // Replace the database path
        $configSrc = preg_replace(
            '/\$db_file\s*=\s*__DIR__\s*\.\s*[\'"]\/orbitra_db\.sqlite[\'"];/',
            '$db_file = __DIR__ . "/orbitra_test.sqlite";',
            $configSrc
        );

        // Add initialization to create schema if needed
        $configSrc .= "\n\n// Test mode: ensure schema exists\n";
        $configSrc .= "if (!file_exists(\$db_file)) {\n";
        $configSrc .= "    touch(\$db_file);\n";
        $configSrc .= "}\n";

        file_put_contents($this->workingDir . '/config.php', $configSrc);

        // Update the working directory for the server
        $this->repoRoot = $this->workingDir;
        $this->routerFile = $this->workingDir . '/' . basename($this->routerFile);
    }

    /**
     * Recursively copy a directory.
     */
    private function copyDirectory(string $src, string $dst): void
    {
        mkdir($dst, 0755, true);
        $items = scandir($src);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = $src . '/' . $item;
            $dstPath = $dst . '/' . $item;
            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }

    /**
     * Restore the original database and clean up test files.
     */
    private function restoreDatabase(): void
    {
        // Clean up the temporary working directory
        if ($this->workingDir !== null && is_dir($this->workingDir)) {
            $this->removeDirectory($this->workingDir);
        }

        // Clear environment variable
        putenv('ORBITRA_TEST_DB=');
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

// Export the test config path for use in tests
if (isset($harness)) {
    $GLOBALS['__ORBITRA_TEST_CONFIG_PATH__'] = $harness->testConfigPath ?? null;
}
