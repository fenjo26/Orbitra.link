<?php
// tests/ssl_server_ip_override_test.php
//
// The SSL gate compares a domain's A record against this server's address. If
// that address comes back empty, EVERY domain reports "waiting for DNS — the
// domain does not point at this server" with an empty server IP, no matter how
// correct its DNS is, and no certificate is ever issued.
//
// That is what happened: core/ssl_manager.php carried its own private copy of
// the detection ladder (cache → UDP → ipify) which did not know about the
// `server_ip_override` setting. An operator whose VPS cannot autodetect would
// set the address by hand, see the settings banner confirm it, and still watch
// every domain stall — the banner and the gate were reading different things,
// which is the exact duplication ORB-005 consolidated into core/server_ip.php.
//
// Guards both halves: the detector honours the override, and ssl_manager no
// longer owns a second ladder.
//
// Run: php tests/ssl_server_ip_override_test.php

$repoRoot = dirname(__DIR__);
$failures = 0;

$assert = static function (string $label, $got, $expected) use (&$failures): void {
    $ok = $got === $expected;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label;
    if (!$ok) {
        echo ' — got ' . var_export($got, true) . ', expected ' . var_export($expected, true);
        $failures++;
    }
    echo "\n";
};

// --- 1. The detector, in a sandbox of its own --------------------------------

echo "orbitraDetectServerIp\n";

$tmp = sys_get_temp_dir() . '/orbitra_serverip_' . getmypid();
@mkdir($tmp . '/core', 0775, true);
@mkdir($tmp . '/var', 0775, true);
copy($repoRoot . '/core/server_ip.php', $tmp . '/core/server_ip.php');

$cacheFile = $tmp . '/var/server_ip_cache.txt';
$dbFile = $tmp . '/settings.sqlite';

register_shutdown_function(static function () use ($tmp, $cacheFile, $dbFile): void {
    foreach ([$cacheFile, $dbFile, $tmp . '/core/server_ip.php'] as $f) {
        @unlink($f);
    }
    foreach (['/core', '/var', ''] as $d) {
        @rmdir($tmp . $d);
    }
});

$makeDb = static function (?string $override) use ($dbFile): void {
    @unlink($dbFile);
    $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)');
    if ($override !== null) {
        $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute(['server_ip_override', $override]);
    }
};

/** Detect in a child process: the function caches statically and writes a cache file. */
$detect = static function (bool $withPdo) use ($tmp, $dbFile): string {
    $code = 'require ' . var_export($tmp . '/core/server_ip.php', true) . ';'
        . ($withPdo
            ? '$pdo = new PDO("sqlite:" . ' . var_export($dbFile, true) . '); echo orbitraDetectServerIp($pdo);'
            : 'echo orbitraDetectServerIp();');
    return trim((string) shell_exec('php -r ' . escapeshellarg($code)));
};

// The override beats every autodetection step below it.
$makeDb('203.0.113.77');
@unlink($cacheFile);
$assert('the server_ip_override setting wins over autodetection', $detect(true), '203.0.113.77');

// ...and it is what gets cached, so the next caller agrees with the banner.
$assert('the override is what lands in the cache file', trim((string) @file_get_contents($cacheFile)), '203.0.113.77');

// A private address in the setting is not an address the world can reach:
// autodetection takes over rather than poisoning the gate with 10.x.
$makeDb('10.0.0.5');
@unlink($cacheFile);
$assert('a private override is refused, not stored', $detect(true) === '10.0.0.5', false);

// A fresh cache is trusted without a lookup.
$makeDb(null);
file_put_contents($cacheFile, '198.51.100.9');
$assert('a fresh cache file is used as-is', $detect(false), '198.51.100.9');

// --- 2. ssl_manager must not grow a second ladder ----------------------------
//
// A source-level guard on purpose: the regression was not a wrong value, it was
// a duplicate implementation drifting away from the shared one.

echo "\ncore/ssl_manager.php\n";

$sslSrc = (string) file_get_contents($repoRoot . '/core/ssl_manager.php');

$assert('it loads the shared detector', strpos($sslSrc, "require_once __DIR__ . '/server_ip.php';") !== false, true);
$assert('orbitraServerIp() delegates to it', preg_match('/function orbitraServerIp\(\).*?orbitraDetectServerIp\(/s', $sslSrc) === 1, true);
$assert('no private copy of the external lookup remains', stripos($sslSrc, 'ipify') !== false, false);
$assert('no private copy of the cache file remains', strpos($sslSrc, 'server_ip_cache.txt') !== false, false);

// ---------------------------------------------------------------------------

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "All SSL server-IP tests passed.\n";
