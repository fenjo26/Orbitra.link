<?php
/**
 * ORB-016: the generated nginx vhost must not use the alias directive
 * (October 2026 nginx advisory), and the alias-free X-Accel location must
 * resolve through the self-pointing symlink core/accel_symlink.php keeps in
 * place.
 *
 * Usage: php tests/nginx_alias_free_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$tmp = sys_get_temp_dir() . '/orbitra_aliasfree_' . getmypid();
@mkdir($tmp . '/le/live/le.example', 0777, true);
@mkdir($tmp . '/selfsigned', 0777, true);
foreach (['le/live/le.example/fullchain.pem', 'le/live/le.example/privkey.pem', 'le/options-ssl-nginx.conf',
          'selfsigned/c.crt', 'selfsigned/c.key'] as $f) {
    file_put_contents("$tmp/$f", 'x');
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

$conf = orbitraBuildNginxConfig([['name' => 'a.example', 'ssl_source' => 'auto', 'cloudflare_proxy' => 0]], true);

// 1. No alias directive on any directive line (comments may mention the word).
$check(preg_match('/^\s*alias\s/m', $conf) !== 1, 'no alias directive anywhere in the generated vhost');

// 2. The internal X-Accel location uses root + stays internal.
$check(strpos($conf, "location /_internal_assets/") !== false, 'internal location present');
$check(preg_match('#location /_internal_assets/\s*\{\s*internal;\s*root /var/www/orbitra;#s', $conf) === 1,
    'internal location is internal + root /var/www/orbitra');

// 3. The frontend location is alias-free too and still falls back to its index.
$check(preg_match('#location /frontend/dist/\s*\{\s*root /var/www/orbitra;\s*try_files#s', $conf) === 1,
    'frontend/dist location uses root, not alias');

// 4. The wrapper accepts the whole thing (the panel must be able to install it).
$check(orbitraNginxWrapValidate($conf) === [], 'wrapper accepts the alias-free config');

// 5. The symlink the location resolves through: ensure() creates it, is
//    idempotent, and a zip-style plain-file artifact gets repaired.
$link = ORBITRA_ACCEL_SYMLINK;
@unlink($link);
$check(orbitraEnsureAccelSymlink() && is_link($link) && readlink($link) === '.',
    'ensure creates _internal_assets -> . when absent');
$check(orbitraEnsureAccelSymlink(), 'ensure is a no-op when the link exists');
@unlink($link);
file_put_contents($link, '.');
$check(orbitraEnsureAccelSymlink() && is_link($link), 'zip artifact (plain file ".") is replaced by the link');
@unlink($link);
mkdir($link);
$check(!orbitraEnsureAccelSymlink() && is_dir($link) && !is_link($link),
    'a real directory at the path is left alone (ensure returns false)');
rmdir($link);
$check(orbitraEnsureAccelSymlink(), 'link restored for the working tree');

array_map('unlink', glob("$tmp/le/live/le.example/*.*") ?: []);
array_map('unlink', glob("$tmp/le/*.*") ?: []);
array_map('unlink', glob("$tmp/selfsigned/*.*") ?: []);
@rmdir("$tmp/le/live/le.example");
@rmdir("$tmp/le/live");
@rmdir("$tmp/le");
@rmdir("$tmp/selfsigned");
@rmdir($tmp);
echo $fail === 0 ? "nginx alias-free tests passed\n" : "$fail check(s) FAILED\n";
exit($fail === 0 ? 0 : 1);
