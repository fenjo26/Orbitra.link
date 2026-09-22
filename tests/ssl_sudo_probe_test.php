<?php
/**
 * orbitraSudoCertbotAvailable() must probe the sudo route the issuer actually
 * uses. Since 1.6.0 cli/server_setup.sh grants the web user ONLY the
 * orbitra-issue-cert wrapper and removes "NOPASSWD: /usr/bin/certbot"; the old
 * probe (`sudo -n certbot --help`) then failed on every up-to-date server and
 * the Domains page claimed certbot was not installed.
 *
 * A fake `sudo` on PATH stands in for sudoers: it runs a command only when its
 * basename is listed in ORBITRA_FAKE_SUDO_ALLOW. The wrapper is the real one,
 * cut out of cli/server_setup.sh, so its exit-2-on-bad-domain contract is the
 * shipped one.
 *
 * Usage: php tests/ssl_sudo_probe_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$tmp = sys_get_temp_dir() . '/orbitra_sudoprobe_' . getmypid();
@mkdir("$tmp/bin", 0777, true);
@mkdir("$tmp/acme", 0777, true);
file_put_contents("$tmp/nginx.conf", "server {}\n");

file_put_contents("$tmp/bin/sudo", <<<'SH'
#!/bin/sh
[ "$1" = "-n" ] && shift
name=$(basename "$1")
case ":$ORBITRA_FAKE_SUDO_ALLOW:" in
    *":$name:"*) exec "$@" ;;
esac
echo "sudo: a password is required" >&2
exit 1
SH);
file_put_contents("$tmp/bin/certbot", "#!/bin/sh\necho 'certbot 2.9.0'\nexit 0\n");
chmod("$tmp/bin/sudo", 0755);
chmod("$tmp/bin/certbot", 0755);

$setup = file_get_contents(__DIR__ . '/../cli/server_setup.sh');
if (!preg_match("/<<'ORBITRA_ISSUECERT'\n(.*?)\nORBITRA_ISSUECERT\n/s", $setup, $m)) {
    echo "FAIL: could not extract orbitra-issue-cert from cli/server_setup.sh\n";
    exit(1);
}
$wrapperSource = $m[1] . "\n";
$wrapper = "$tmp/orbitra-issue-cert";

putenv('PATH=' . "$tmp/bin:" . getenv('PATH'));

define('ORBITRA_CERT_ISSUE_WRAPPER', $wrapper);
define('ORBITRA_NGINX_CONFIG_PATH', "$tmp/nginx.conf");
define('ORBITRA_ACME_WEBROOT', "$tmp/acme");
require __DIR__ . '/../core/ssl_manager.php';

$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};
$installWrapper = static function (bool $present) use ($wrapper, $wrapperSource): void {
    if ($present) {
        file_put_contents($wrapper, $wrapperSource);
        chmod($wrapper, 0755);
    } elseif (is_file($wrapper)) {
        unlink($wrapper);
    }
    clearstatcache();
};

// 1. The 1.6.0 sudoers file: wrapper allowed, certbot itself not.
$installWrapper(true);
putenv('ORBITRA_FAKE_SUDO_ALLOW=orbitra-issue-cert');
$r = orbitraSudoCertbotAvailable();
$check($r['ok'] === true && $r['route'] === 'wrapper', 'wrapper-only sudoers (1.6.0+) counts as sudo access');
$env = orbitraSslEnvironment();
$check($env['can_issue'] === true, 'can_issue is true on a wrapper-only server');
$check(!in_array('no_certbot', $env['problems'], true) && !in_array('no_sudo_certbot', $env['problems'], true),
    'no certbot/sudo problem reported on a wrapper-only server');

// 2. Wrapper installed but its sudo rule missing — issuance would fail, even
//    if an administrator kept a certbot rule, because the issuer prefers the wrapper.
putenv('ORBITRA_FAKE_SUDO_ALLOW=certbot');
$r = orbitraSudoCertbotAvailable();
$check($r['ok'] === false && $r['route'] === 'wrapper', 'wrapper present but not sudo-allowed is reported');
$env = orbitraSslEnvironment();
$check($env['can_issue'] === false && $env['certbot'] === true && in_array('no_sudo_certbot', $env['problems'], true),
    'that case is no_sudo_certbot, not no_certbot');

// 3. Pre-wrapper server with the old blanket certbot rule still works.
$installWrapper(false);
putenv('ORBITRA_FAKE_SUDO_ALLOW=certbot');
$r = orbitraSudoCertbotAvailable();
$check($r['ok'] === true && $r['route'] === 'certbot', 'legacy certbot sudo rule still accepted without the wrapper');

// 4. Nothing allowed at all.
putenv('ORBITRA_FAKE_SUDO_ALLOW=');
$r = orbitraSudoCertbotAvailable();
$check($r['ok'] === false, 'no sudo rule at all is reported');

// 5. sudo-rs prints parse warnings to stderr on every call; exit codes decide.
file_put_contents("$tmp/bin/sudo", "#!/bin/sh\necho 'sudo: /etc/sudoers.d/x:3: wildcards are not allowed in command arguments' >&2\n"
    . "[ \"\$1\" = \"-n\" ] && shift\nexec \"\$@\"\n");
$installWrapper(true);
$r = orbitraSudoCertbotAvailable();
$check($r['ok'] === true, 'sudo stderr noise does not flip the verdict');

$installWrapper(false);
array_map('unlink', glob("$tmp/bin/*"));
@unlink("$tmp/nginx.conf");
@rmdir("$tmp/bin");
@rmdir("$tmp/acme");
@rmdir($tmp);

echo $fail === 0 ? "\nAll checks passed.\n" : "\n$fail check(s) FAILED\n";
exit($fail === 0 ? 0 : 1);
