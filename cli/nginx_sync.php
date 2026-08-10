#!/usr/bin/php
<?php
/**
 * Orbitra Nginx config repair & sync.
 *
 *     sudo php /var/www/orbitra/cli/nginx_sync.php
 *
 * Rebuilds /etc/nginx/sites-available/orbitra from the domains in the database
 * and reloads nginx. Run it after updating Orbitra, when the panel became
 * unreachable, when something edited the config by hand, or after restoring a
 * server.
 *
 * On top of the rebuild it repairs the two things that used to break access by
 * IP and could not be fixed from the panel (they need root):
 *
 *  - Certificates issued by the old `certbot --nginx` still carry
 *    "authenticator = nginx" in their renewal config, so every renewal would
 *    rewrite the site config again and re-break IP access. They are switched to
 *    the webroot authenticator, which never touches nginx.
 *  - A self-signed certificate for the server IP, so https://<ip>/admin.php
 *    opens the panel instead of presenting a parked domain's certificate.
 *
 * The generated config always contains a catch-all server block, so afterwards
 * the panel is reachable at the server IP whatever domains are parked.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/shell.php';
require_once __DIR__ . '/../core/nginx_config.php';

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Run this as root: sudo php " . __FILE__ . "\n");
    exit(1);
}

echo "Orbitra Nginx sync\n";
echo "==================\n\n";

// ---- 1. ACME webroot -------------------------------------------------------
if (!is_dir(ORBITRA_ACME_WEBROOT . '/.well-known/acme-challenge')) {
    @mkdir(ORBITRA_ACME_WEBROOT . '/.well-known/acme-challenge', 0775, true);
    echo "  + created ACME webroot " . ORBITRA_ACME_WEBROOT . "\n";
}

// ---- 2. Renewal configs left behind by `certbot --nginx` -------------------
$migrated = [];
foreach (glob('/etc/letsencrypt/renewal/*.conf') ?: [] as $conf) {
    $body = (string) @file_get_contents($conf);
    if ($body === '' || strpos($body, 'authenticator = nginx') === false) {
        continue;
    }

    $body = str_replace('authenticator = nginx', 'authenticator = webroot', $body);
    $body = preg_replace('/^installer = nginx\s*$/m', '', $body);
    if (strpos($body, 'webroot_path') === false) {
        $body = preg_replace(
            '/^\[renewalparams\]\s*$/m',
            "[renewalparams]\nwebroot_path = " . ORBITRA_ACME_WEBROOT . ",",
            $body,
            1
        );
    }

    if (@file_put_contents($conf, $body) !== false) {
        $migrated[] = basename($conf, '.conf');
    }
}
if (!empty($migrated)) {
    echo "  + switched to the webroot authenticator: " . implode(', ', $migrated) . "\n";
    echo "    (renewals will no longer rewrite the Nginx config)\n";
}

// ---- 3. Self-signed certificate for HTTPS on the bare IP -------------------
$ip = trim((string) orbitraShell('curl -s --max-time 5 http://checkip.amazonaws.com 2>/dev/null'));
if ($ip === '') {
    $ip = trim((string) orbitraShell("hostname -I 2>/dev/null | awk '{print \$1}'"));
}

if (!file_exists(ORBITRA_SELF_SIGNED_CERT) || !file_exists(ORBITRA_SELF_SIGNED_KEY)) {
    @mkdir(dirname(ORBITRA_SELF_SIGNED_CERT), 0755, true);
    $cn = escapeshellarg('/CN=' . ($ip !== '' ? $ip : 'orbitra'));
    $san = $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)
        ? ' -addext ' . escapeshellarg('subjectAltName=IP:' . $ip)
        : '';
    $base = 'openssl req -x509 -nodes -newkey rsa:2048 -days 3650'
        . ' -keyout ' . escapeshellarg(ORBITRA_SELF_SIGNED_KEY)
        . ' -out ' . escapeshellarg(ORBITRA_SELF_SIGNED_CERT)
        . ' -subj ' . $cn;

    orbitraShell($base . $san . ' >/dev/null 2>&1');
    if (!file_exists(ORBITRA_SELF_SIGNED_CERT)) {
        // Older OpenSSL has no -addext; a certificate without the SAN still works.
        orbitraShell($base . ' >/dev/null 2>&1');
    }
    if (file_exists(ORBITRA_SELF_SIGNED_CERT)) {
        @chmod(ORBITRA_SELF_SIGNED_KEY, 0600);
        echo "  + generated a self-signed certificate for HTTPS access by IP\n";
    } else {
        echo "  ! could not generate a self-signed certificate; HTTPS by IP will be unavailable\n";
    }
}

// ---- 4. Rebuild the config -------------------------------------------------
if (!file_exists(ORBITRA_NGINX_CONFIG_PATH)) {
    @mkdir(dirname(ORBITRA_NGINX_CONFIG_PATH), 0755, true);
    @file_put_contents(ORBITRA_NGINX_CONFIG_PATH, '');
}
if (!file_exists('/etc/nginx/sites-enabled/orbitra')) {
    @symlink(ORBITRA_NGINX_CONFIG_PATH, '/etc/nginx/sites-enabled/orbitra');
}

$result = orbitraSyncNginx($pdo);

$domains = $pdo->query("SELECT name FROM domains WHERE name IS NOT NULL AND name != '' ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);

echo "\n";
echo "Config:  " . ORBITRA_NGINX_CONFIG_PATH . "\n";
echo "Domains: " . (empty($domains) ? '(none)' : implode(', ', $domains)) . "\n";
echo "Status:  {$result['status']} — {$result['message']}\n\n";

if ($result['status'] === 'error') {
    exit(1);
}

$adminPath = '';
try {
    $adminPath = (string) ($pdo->query("SELECT value FROM settings WHERE key = 'admin_path'")->fetchColumn() ?: '');
} catch (\Throwable $e) {
    // The setting predates this feature on old databases; the default path applies.
}

$host = $ip !== '' ? $ip : '<server-ip>';
if ($adminPath !== '') {
    echo "Admin panel: http://$host/$adminPath\n";
    echo "A secret admin path is configured, so /admin.php returns 404.\n";
} else {
    echo "Admin panel: http://$host/admin.php\n";
}

exit(0);
