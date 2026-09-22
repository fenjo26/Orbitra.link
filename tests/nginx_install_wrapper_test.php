<?php
/**
 * cli/orbitra-install-nginx-conf — the root wrapper that replaced
 * "sudo cp /tmp/... /etc/nginx/sites-available/orbitra" (security audit #4).
 *
 * 1. Every branch the generator emits passes the allowlist (so the panel can
 *    still update nginx).
 * 2. The directives that turn a config write into root code execution or a
 *    root file read/write are rejected.
 * 3. The generator itself drops unsafe domain names and custom cert paths.
 *
 * Usage: php tests/nginx_install_wrapper_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$tmp = sys_get_temp_dir() . '/orbitra_wrap_' . getmypid();
@mkdir($tmp . '/le/live/le.example', 0777, true);
@mkdir($tmp . '/selfsigned', 0777, true);
foreach (['le/live/le.example/fullchain.pem', 'le/live/le.example/privkey.pem', 'le/options-ssl-nginx.conf',
          'le/ssl-dhparams.pem', 'selfsigned/c.crt', 'selfsigned/c.key', 'custom.crt', 'custom.key'] as $f) {
    file_put_contents("$tmp/$f", "x");
}
define('ORBITRA_LETSENCRYPT_DIR', "$tmp/le");
define('ORBITRA_SELF_SIGNED_CERT', "$tmp/selfsigned/c.crt");
define('ORBITRA_SELF_SIGNED_KEY', "$tmp/selfsigned/c.key");
define('ORBITRA_NGINX_WRAP_LIBRARY', true);

require __DIR__ . '/../core/nginx_config.php';
require __DIR__ . '/../cli/orbitra-install-nginx-conf';

$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

// Generator output with the LE dir mapped back to its production path.
$gen = static function (array $domains, bool $default) use ($tmp): string {
    $c = orbitraBuildNginxConfig($domains, $default);
    return str_replace("$tmp/le", '/etc/letsencrypt', $c);
};

$domains = [
    ['name' => 'le.example', 'ssl_source' => 'letsencrypt', 'cloudflare_proxy' => 0, 'ssl_status' => 'active'],
    ['name' => 'custom.example', 'custom_ssl_cert' => "$tmp/custom.crt", 'custom_ssl_key' => "$tmp/custom.key",
     'ssl_source' => 'cloudflare_origin', 'cloudflare_proxy' => 1, 'ssl_status' => 'active'],
    ['name' => 'cf.example', 'ssl_source' => 'auto', 'cloudflare_proxy' => 1, 'ssl_status' => 'cloudflare'],
    ['name' => 'plain.example', 'ssl_source' => 'auto', 'cloudflare_proxy' => 0, 'ssl_status' => 'pending'],
];

foreach ([true, false] as $default) {
    $conf = $gen($domains, $default);
    $errs = orbitraNginxWrapValidate($conf);
    $check($errs === [], 'generator output passes (default_server=' . ($default ? 'on' : 'off') . ')'
        . ($errs ? ' — ' . implode('; ', array_slice($errs, 0, 3)) : ''));
}
$check(orbitraNginxWrapValidate($gen([], true)) === [], 'generator output with no domains passes');
foreach (['listen 443 ssl', 'ssl_certificate ', 'include /etc/letsencrypt/options-ssl-nginx.conf', 'fastcgi_pass', 'alias /var/www/orbitra/'] as $needle) {
    $check(strpos($gen($domains, true), $needle) !== false, "fixture exercises: $needle");
}

$base = "server {\n    listen 80;\n    server_name a.example;\n    %s\n}\n";
$bad = [
    'access_log to a cron file'      => 'access_log /etc/cron.d/pwn;',
    'access_log with format'         => 'access_log /var/log/nginx/x.log combined;',
    'error_log anywhere'             => 'error_log /etc/cron.d/pwn;',
    'include outside the allowlist'  => 'include /var/www/orbitra/var/evil.conf;',
    'include of a glob'              => 'include /etc/nginx/conf.d/*.conf;',
    'root outside the app'           => 'root /etc;',
    'alias traversal'                => 'alias /var/www/orbitra/../../../etc/;',
    'alias prefix trick'             => 'alias /var/www/orbitra-evil/;',
    'proxy_pass'                     => 'location / { proxy_pass http://127.0.0.1:22; }',
    'fastcgi_pass to a remote host'  => 'fastcgi_pass 10.0.0.5:9000;',
    'client_body_temp_path'          => 'client_body_temp_path /etc/cron.d;',
    'ssl key with injected tail'     => 'ssl_certificate_key "/tmp/x; access_log /etc/cron.d/p";',
    'ssl key from /proc'             => 'ssl_certificate_key /proc/self/environ;',
    'nested server'                  => 'server { listen 81; }',
    'perl/lua/js modules'            => 'js_import /tmp/x.js;',
];
foreach ($bad as $label => $snippet) {
    $check(orbitraNginxWrapValidate(sprintf($base, $snippet)) !== [], "rejects $label");
}
$check(orbitraNginxWrapValidate("load_module /tmp/x.so;\n" . sprintf($base, '')) !== [], 'rejects top-level load_module');
$check(orbitraNginxWrapValidate("user root;\n") !== [], 'rejects top-level user');
$check(orbitraNginxWrapValidate(sprintf($base, 'access_log off;')) === [], 'accepts access_log off');
$check(orbitraNginxWrapValidate("server {\n listen 80;\n") !== [], 'rejects unbalanced braces');
$check(orbitraNginxWrapValidate("server { listen 80; }\x00") !== [], 'rejects NUL bytes');

// Generator-side filtering.
$evilName = "evil.example;\n    access_log /etc/cron.d/pwn";
$c = orbitraBuildNginxConfig([['name' => $evilName, 'ssl_source' => 'auto', 'cloudflare_proxy' => 0]], true);
$check(strpos($c, '/etc/cron.d') === false, 'generator drops a domain name carrying directives');
$evilPath = "$tmp/k;\naccess_log \x2Fetc\x2Fcron.d\x2Fpwn";
@file_put_contents($evilPath, 'x');
$c = orbitraBuildNginxConfig([['name' => 'c2.example', 'custom_ssl_cert' => $evilPath, 'custom_ssl_key' => $evilPath,
    'ssl_source' => 'custom', 'cloudflare_proxy' => 0]], true);
$check(strpos($c, 'cron.d') === false, 'generator ignores a custom cert path carrying directives');

// CLI: --check reads STDIN, never writes.
$wrapper = __DIR__ . '/../cli/orbitra-install-nginx-conf';
$run = static function (string $stdin, string $arg) use ($wrapper): int {
    $p = proc_open([PHP_BINARY, $wrapper, $arg], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    return proc_close($p);
};
$check($run($gen($domains, true), '--check') === 0, 'CLI --check accepts generator output');
$check($run(sprintf($base, 'access_log /etc/cron.d/pwn;'), '--check') === 3, 'CLI --check exits 3 on a rejected config');
$check($run('', '--bogus') === 2, 'CLI rejects unknown arguments');

array_map('unlink', glob("$tmp/*.*") ?: []);
echo $fail === 0 ? "nginx install wrapper tests passed\n" : "$fail check(s) FAILED\n";
exit($fail === 0 ? 0 : 1);
