<?php
// tests/session_lifetime_test.php
//
// The `session_lifetime` setting (seeded at 86400 by migration 8) used to be
// dead weight — nothing read it, so PHP's own gc_maxlifetime default applied
// and a panel left open came back to a wall of 401s from every request the
// still-loaded UI made. session_bootstrap.php now resolves it (constant →
// environment → settings table), applies it to session.gc_maxlifetime, and
// enforces idle expiry itself, because the distribution's session-cleanup cron
// reads php.ini rather than anything ini_set() does at runtime.
//
// Run: php tests/session_lifetime_test.php

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

// --- Sandbox: the bootstrap next to a scratch database ----------------------

$tmp = sys_get_temp_dir() . '/orbitra_session_' . getmypid();
@mkdir($tmp . '/var/sessions', 0775, true);
copy($repoRoot . '/session_bootstrap.php', $tmp . '/session_bootstrap.php');

$pdo = new PDO('sqlite:' . $tmp . '/orbitra_db.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)');
$pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute(['session_lifetime', '86400']);
unset($pdo);

register_shutdown_function(static function () use ($tmp): void {
    foreach (glob($tmp . '/var/sessions/*') ?: [] as $f) {
        @unlink($f);
    }
    foreach (['/session_bootstrap.php', '/orbitra_db.sqlite', '/probe.php', '/cookies.txt'] as $f) {
        @unlink($tmp . $f);
    }
    foreach (['/var/sessions', '/var', ''] as $d) {
        @rmdir($tmp . $d);
    }
});

/** Resolve the lifetime in a child process, so each case starts from a clean static cache. */
$resolve = static function (string $prelude, array $env = []) use ($tmp): int {
    $code = $prelude . ' require ' . var_export($tmp . '/session_bootstrap.php', true) . '; echo orbitraSessionLifetime();';
    $cmd = '';
    foreach ($env as $k => $v) {
        $cmd .= $k . '=' . escapeshellarg((string) $v) . ' ';
    }
    $cmd .= 'php -r ' . escapeshellarg($code);
    return (int) trim((string) shell_exec($cmd));
};

echo "orbitraSessionLifetime resolution\n";

$assert('the settings table is read when nothing overrides it', $resolve(''), 86400);
$assert('the environment wins over the settings table', $resolve('', ['ORBITRA_SESSION_LIFETIME' => 7200]), 7200);
$assert('a constant wins over both', $resolve('define("ORBITRA_SESSION_LIFETIME", 3600);', ['ORBITRA_SESSION_LIFETIME' => 7200]), 3600);
$assert('an absurdly short value is clamped to the 5-minute floor', $resolve('define("ORBITRA_SESSION_LIFETIME", 30);'), 300);
$assert('an absurdly long value is clamped to 30 days', $resolve('define("ORBITRA_SESSION_LIFETIME", 999999999);'), 2592000);

// A database that cannot be read must not shorten anyone's session.
$dbPath = $tmp . '/orbitra_db.sqlite';
$saved = file_get_contents($dbPath);
file_put_contents($dbPath, 'not a database');
$assert('an unreadable database falls back to the 86400 default', $resolve(''), 86400);
file_put_contents($dbPath, $saved);

// --- Idle expiry over real HTTP ---------------------------------------------

echo "\nidle expiry\n";

file_put_contents($tmp . '/probe.php', <<<'PROBE'
<?php
define('ORBITRA_SESSION_LIFETIME', 300);
require __DIR__ . '/session_bootstrap.php';
orbitraBootstrapSession();
if (isset($_GET['login'])) {
    $_SESSION['user'] = 'operator';
}
if (isset($_GET['idle'])) {
    $_SESSION['_orbitra_last_seen'] = time() - (int) $_GET['idle'];
}
echo json_encode([
    'gc'   => (int) ini_get('session.gc_maxlifetime'),
    'own'  => basename(dirname(session_save_path())) . '/' . basename(session_save_path()),
    'user' => $_SESSION['user'] ?? null,
]);
PROBE);

$port = 9000 + (getmypid() % 900);
$server = proc_open(
    sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($tmp)),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "could not start the probe server\n");
    exit(1);
}
register_shutdown_function(static function () use ($server): void {
    proc_terminate($server);
    proc_close($server);
});

$cookie = null;
$hit = static function (string $query) use ($port, &$cookie): array {
    $headers = $cookie ? ['Cookie: ' . $cookie] : [];
    $url = sprintf('http://127.0.0.1:%d/probe.php%s', $port, $query);
    for ($try = 0; $try < 40; $try++) {
        $body = @file_get_contents($url, false, stream_context_create(['http' => [
            'timeout' => 2, 'ignore_errors' => true, 'header' => implode("\r\n", $headers),
        ]]));
        if ($body !== false) {
            foreach ($http_response_header ?? [] as $header) {
                if (stripos($header, 'Set-Cookie: ') === 0) {
                    $cookie = trim(explode(';', substr($header, 12))[0]);
                }
            }
            return json_decode($body, true) ?: [];
        }
        usleep(100000);
    }
    fwrite(STDERR, "probe server never answered\n");
    exit(1);
};

$first = $hit('?login=1');
$assert('gc_maxlifetime follows the resolved lifetime', $first['gc'] ?? null, 300);
$assert('sessions live in the tracker\'s own var/sessions', $first['own'] ?? null, 'var/sessions');
$assert('the operator is logged in', $first['user'] ?? null, 'operator');

$hit('?idle=200');
$assert('a session idle for less than the lifetime survives', $hit('')['user'] ?? null, 'operator');

$hit('?idle=400');
$assert('a session idle for longer than the lifetime is emptied', $hit('')['user'] ?? null, null);

// ---------------------------------------------------------------------------

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "All session lifetime tests passed.\n";
