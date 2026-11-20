<?php
/**
 * TZ_LOAD_PERFORMANCE_2, stage 3: the nginx limits step of server_setup v2
 * (cli/server_setup_nginx_limits.sh).
 *
 *  - stock Ubuntu 24.04 (worker_connections 768) and Debian 12 (1024) configs
 *    are raised to 4096, with worker_rlimit_nofile 16384 added before the
 *    events block, and the reload runs exactly once;
 *  - a second run is a no-op: the file does not change and nothing reloads;
 *  - a hand-tuned worker_connections 8192 is left byte-identical, no reload;
 *  - when nginx -t rejects the change, the config is rolled back from the
 *    backup byte-identically and nothing reloads;
 *  - core/server_setup.php requires version 2 (the panel banner wiring).
 *
 * Runs the real shell step against fixture configs with a fake nginx binary
 * and a fake reload command — no nginx, no root, works on macOS and Linux.
 * RED on c43ce94: the helper script and v2 do not exist there.
 *
 * Usage: php tests/server_setup_nginx_limits_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

$repoRoot = dirname(__DIR__);
$helper = $repoRoot . '/cli/server_setup_nginx_limits.sh';
$tmp = sys_get_temp_dir() . '/orbitra_nginx_limits_' . getmypid();
@mkdir($tmp, 0777, true);
register_shutdown_function(static function () use ($tmp): void {
    foreach (glob($tmp . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
});

check(is_file($helper), 'cli/server_setup_nginx_limits.sh exists (server_setup v2 companion)');

// Fake binaries: nginx-ok passes -t, nginx-fail rejects everything.
file_put_contents($tmp . '/nginx-ok', "#!/bin/sh\nexit 0\n");
file_put_contents($tmp . '/nginx-fail', "#!/bin/sh\nexit 1\n");
// The reload "command": a single-word script path, as the helper execs it.
file_put_contents($tmp . '/fake-reload', "#!/bin/sh\necho reload >> '" . $tmp . "/reload.log'\n");
chmod($tmp . '/nginx-ok', 0755);
chmod($tmp . '/nginx-fail', 0755);
chmod($tmp . '/fake-reload', 0755);

$run = static function (string $conf, string $nginxBin) use ($helper, $tmp): array {
    @unlink($tmp . '/reload.log');
    $setupTmp = $tmp . '/setup';
    @mkdir($setupTmp, 0777, true);
    $cmd = sprintf(
        'NGINX_CONF=%s NGINX_BIN=%s NGINX_RELOAD=%s ORBITRA_SETUP_TMP=%s bash %s 2>&1',
        escapeshellarg($conf),
        escapeshellarg($nginxBin),
        escapeshellarg($tmp . '/fake-reload'),
        escapeshellarg($setupTmp),
        escapeshellarg($helper)
    );
    exec($cmd, $out, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $out), 'reloads' => (is_file($tmp . '/reload.log') ? count(file($tmp . '/reload.log')) : 0)];
};

$stockConf = static fn(int $connections): string => <<<CONF
user www-data;
worker_processes auto;
pid /run/nginx.pid;
include /etc/nginx/modules-enabled/*.conf;

events {
\tworker_connections {$connections};
}

http {
\tinclude /etc/nginx/mime.types;
\tdefault_type application/octet-stream;
\taccess_log /var/log/nginx/access.log;
\tinclude /etc/nginx/sites-enabled/*;
}

CONF;

try {
    // --- Ubuntu 24.04 stock (768) ---------------------------------------------
    $conf = $tmp . '/ubuntu.conf';
    $original = $stockConf(768);
    file_put_contents($conf, $original);
    $r = $run($conf, $tmp . '/nginx-ok');
    $edited = (string) file_get_contents($conf);
    check($r['rc'] === 0, 'the step exits 0 on a stock Ubuntu config');
    check(strpos($edited, 'worker_connections 4096;') !== false, 'Ubuntu stock 768 raised to 4096');
    check(preg_match('/^worker_rlimit_nofile 16384;\n\nevents \{/m', $edited) === 1, 'worker_rlimit_nofile 16384 inserted at the main level before events {');
    check($r['reloads'] === 1, 'nginx reloaded exactly once after the change');
    $hashAfterFirst = md5($edited);

    // Idempotent second run.
    $r2 = $run($conf, $tmp . '/nginx-ok');
    check(md5((string) file_get_contents($conf)) === $hashAfterFirst, 'a second run leaves the config byte-identical');
    check($r2['reloads'] === 0, 'a second run reloads nothing ("already configured")');

    // --- Debian 12 stock (1024) ------------------------------------------------
    $conf = $tmp . '/debian.conf';
    file_put_contents($conf, $stockConf(1024));
    $r = $run($conf, $tmp . '/nginx-ok');
    check(strpos((string) file_get_contents($conf), 'worker_connections 4096;') !== false,
        'Debian stock 1024 raised to 4096 (out: ' . str_replace("\n", ' | ', $r['out']) . ')');

    // --- hand-tuned 8192: the value stays, only the missing rlimit is added ----
    $conf = $tmp . '/custom.conf';
    file_put_contents($conf, $stockConf(8192));
    $r = $run($conf, $tmp . '/nginx-ok');
    $edited = (string) file_get_contents($conf);
    check(strpos($edited, 'worker_connections 8192;') !== false && strpos($edited, 'worker_connections 4096;') === false,
        'a hand-tuned worker_connections 8192 keeps its value (4096 never forced)');
    check(preg_match('/^worker_rlimit_nofile 16384;/m', $edited) === 1,
        'the absent worker_rlimit_nofile is still added for the hand-tuned config');
    check($r['reloads'] === 1, 'exactly one reload for the rlimit-only change');

    // --- nginx -t rejects the change -> rollback -------------------------------
    // Force an editable file: stock 768 again, but the fake nginx fails -t.
    $conf = $tmp . '/rejected.conf';
    $original = $stockConf(768);
    file_put_contents($conf, $original);
    $r = $run($conf, $tmp . '/nginx-fail');
    check(md5((string) file_get_contents($conf)) === md5($original), 'when nginx -t fails the config is restored byte-identically');
    check($r['reloads'] === 0, 'no reload after a rejected change');
    check($r['rc'] === 0, 'a rejected change is a warning, not a failure (exit 0)');

    // --- an existing worker_rlimit_nofile is never rewritten --------------------
    $conf = $tmp . '/rlimit-set.conf';
    file_put_contents($conf, "worker_rlimit_nofile 32768;\n" . $stockConf(768));
    $run($conf, $tmp . '/nginx-ok');
    $edited = (string) file_get_contents($conf);
    check(preg_match_all('/^worker_rlimit_nofile /m', $edited) === 1 && strpos($edited, 'worker_rlimit_nofile 32768;') !== false,
        'an existing worker_rlimit_nofile 32768 is kept, no duplicate inserted');

    // --- the panel and the script agree on the version ---------------------------
    // Parity, not a pin: the literal version moves with every server_setup
    // bump (v3 = the cron package), and a test hard-coding "2" fails on the
    // next release while proving nothing. What must hold is that sh and php
    // declare the SAME number, or the banner would never clear.
    require_once $repoRoot . '/core/server_setup.php';
    $shVersion = (int) shell_exec('grep -o "^SERVER_SETUP_VERSION=[0-9]*" ' . escapeshellarg($repoRoot . '/cli/server_setup.sh') . ' 2>/dev/null | head -n1 | cut -d= -f2');
    check($shVersion >= 2, 'cli/server_setup.sh declares SERVER_SETUP_VERSION (got ' . $shVersion . ')');
    check(ORBITRA_SERVER_SETUP_REQUIRED === $shVersion,
        'core/server_setup.php requires the version cli/server_setup.sh declares (php ' . ORBITRA_SERVER_SETUP_REQUIRED . ', sh ' . $shVersion . ')');
    check(strpos((string) shell_exec('grep -c "server_setup_nginx_limits" ' . escapeshellarg($repoRoot . '/install.sh') . ' 2>/dev/null'), '0') !== false,
        'install.sh does NOT duplicate the nginx step (it calls server_setup.sh once)');
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage());
}

echo $failures === 0 ? "Server setup nginx limits tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
