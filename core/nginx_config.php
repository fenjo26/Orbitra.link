<?php

require_once __DIR__ . '/shell.php';
/**
 * Orbitra Nginx configuration builder.
 *
 * One place that knows what /etc/nginx/sites-available/orbitra should look like,
 * shared by the API (domain add/edit/delete), the SSL installer and the recovery
 * CLI. Previously each of those three wrote its own copy of the config, and they
 * had drifted apart — which is how the panel could become unreachable at the
 * server IP as soon as a domain was parked.
 *
 * Two rules the generated config must always satisfy:
 *
 *  1. A catch-all server block owns port 80 (and 443 when a self-signed
 *     certificate is present). Anything that does not match a parked domain —
 *     above all a request addressed to the bare server IP — lands there, so
 *     http://<ip>/admin.php keeps working no matter how many domains exist or
 *     which of them were later deleted.
 *  2. /.well-known/acme-challenge/ is served before the dotfile deny rule.
 *     Without that ordering the deny swallows the ACME challenge and a webroot
 *     certificate can never be issued.
 */

// Defined rather than const so a test harness (or an unusual install layout)
// can point them elsewhere before including this file.
defined('ORBITRA_NGINX_CONFIG_PATH') || define('ORBITRA_NGINX_CONFIG_PATH', '/etc/nginx/sites-available/orbitra');
defined('ORBITRA_ACME_WEBROOT') || define('ORBITRA_ACME_WEBROOT', '/var/www/orbitra/var/acme');
defined('ORBITRA_LETSENCRYPT_DIR') || define('ORBITRA_LETSENCRYPT_DIR', '/etc/letsencrypt');
defined('ORBITRA_SELF_SIGNED_CERT') || define('ORBITRA_SELF_SIGNED_CERT', '/etc/orbitra/ssl/self-signed.crt');
defined('ORBITRA_SELF_SIGNED_KEY') || define('ORBITRA_SELF_SIGNED_KEY', '/etc/orbitra/ssl/self-signed.key');

/**
 * The Certbot command used to obtain a certificate.
 *
 * "certonly" is deliberate. The old code ran `certbot --nginx`, which lets
 * Certbot's installer plugin rewrite /etc/nginx/sites-available/orbitra — it
 * narrows server_name to the domain being issued and appends a `return 404`
 * block. That is precisely what used to cut off access at the server IP, and it
 * came back on every renewal. Orbitra generates its own HTTPS server blocks in
 * orbitraBuildNginxConfig(), so all Certbot has to do is fetch the certificate.
 *
 * The webroot authenticator never touches nginx at all, and the catch-all server
 * block answers /.well-known/acme-challenge/ for any hostname, so validation
 * works even before the domain has a server block of its own.
 */
function orbitraCertbotCertonlyCommand(string $domain): string
{
    @mkdir(ORBITRA_ACME_WEBROOT . '/.well-known/acme-challenge', 0775, true);

    return 'sudo certbot certonly --webroot -w ' . escapeshellarg(ORBITRA_ACME_WEBROOT)
        . ' -n -d ' . escapeshellarg($domain)
        . ' --agree-tos --register-unsafely-without-email --keep-until-expiring';
}

/**
 * Did a Certbot run end with a usable certificate?
 */
function orbitraCertbotSucceeded(?string $output, string $domain): bool
{
    if (file_exists(ORBITRA_LETSENCRYPT_DIR . "/live/$domain/fullchain.pem")) {
        return true;
    }
    if (!is_string($output) || $output === '') {
        return false;
    }
    foreach (['Successfully received certificate', 'Certificate not yet due for renewal', 'successfully'] as $needle) {
        if (stripos($output, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Locate the PHP-FPM socket instead of hardcoding a version.
 *
 * The old generator wrote php8.3-fpm.sock unconditionally, so on a server with
 * any other PHP the first domain save produced a config that failed nginx -t.
 */
function orbitraPhpFpmSocket(): string
{
    $candidates = glob('/var/run/php/php*-fpm.sock') ?: [];
    if (empty($candidates)) {
        $candidates = glob('/run/php/php*-fpm.sock') ?: [];
    }

    if (!empty($candidates)) {
        // Highest version wins: 8.3 sorts after 8.1, and a host that kept an old
        // FPM around should still be served by the one PHP actually runs on.
        natsort($candidates);
        return (string) end($candidates);
    }

    // Nothing found (chroot, unusual distro): fall back to the version we run on.
    $v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    return "/var/run/php/php{$v}-fpm.sock";
}

/**
 * The location blocks every server block shares.
 */
function orbitraNginxCommonBody(string $fpmSocket): string
{
    $b = "    root /var/www/orbitra;\n";
    $b .= "    index index.php admin.php index.html;\n\n";

    $b .= "    # Let's Encrypt HTTP-01 challenge.\n";
    $b .= "    # Must precede the dotfile deny below, which would otherwise swallow it.\n";
    $b .= "    location ^~ /.well-known/acme-challenge/ {\n";
    $b .= "        root " . ORBITRA_ACME_WEBROOT . ";\n";
    $b .= "        default_type \"text/plain\";\n";
    $b .= "        try_files \$uri =404;\n";
    $b .= "    }\n\n";

    $b .= "    # Access to React/Vite static files\n";
    $b .= "    location /frontend/dist/ {\n";
    $b .= "        alias /var/www/orbitra/frontend/dist/;\n";
    $b .= "        try_files \$uri \$uri/ /frontend/dist/index.html;\n";
    $b .= "    }\n\n";

    $b .= "    # Keitaro Admin API compatible endpoint (Dolphin / Fbtool cost push).\n";
    $b .= "    # Without this the generic router below would hand /admin_api/... to\n";
    $b .= "    # index.php as a campaign alias. PHP still sees the original REQUEST_URI.\n";
    $b .= "    location ^~ /admin_api/ {\n";
    $b .= "        rewrite ^/admin_api/(.*)\$ /admin_api.php last;\n";
    $b .= "    }\n\n";

    $b .= "    # Router handling (API and clicks)\n";
    $b .= "    location / {\n";
    $b .= "        try_files \$uri \$uri/ /index.php?\$query_string;\n";
    $b .= "    }\n\n";

    $b .= "    # Allow large file uploads for Geo DB\n";
    $b .= "    client_max_body_size 256m;\n\n";

    $b .= "    # Uploaded landing/offer bundles are content, not tracker code.\n";
    $b .= "    #\n";
    $b .= "    # A LeadForge form posts to a relative order.php, which the browser resolves\n";
    $b .= "    # against the campaign URL (\"/pr6sxv41\") and therefore sends to /order.php —\n";
    $b .= "    # a path with no file behind it. snippets/fastcgi-php.conf ends in\n";
    $b .= "    # \"try_files \$fastcgi_script_name =404\", so the PHP handler below answered\n";
    $b .= "    # that POST with nginx's own 404 and index.php's order bridge never ran.\n";
    $b .= "    # These paths go to the front controller instead: it resolves which bundle\n";
    $b .= "    # the visitor is on and runs the handler in-process, gated by the same\n";
    $b .= "    # \"Allow PHP landings\" switch and execution budget as the rest of an\n";
    $b .= "    # uploaded archive. Keep this before the generic PHP handler — nginx tries\n";
    $b .= "    # regex locations in the order they are written.\n";
    $b .= "    location ~ ^/(?:order|thank_you|success|send|lucky|lemon)\\.php\$ {\n";
    $b .= "        rewrite ^ /index.php last;\n";
    $b .= "    }\n\n";

    $b .= "    # The bundles' own routes, /offers/<id>/... and /lander/<slug>/... . Same\n";
    $b .= "    # reason, plus the one that matters more: without this, any .php a bundle\n";
    $b .= "    # ships is executable straight off disk by URL, outside the switch and the\n";
    $b .= "    # budget that exist precisely because uploaded code is not trusted code.\n";
    $b .= "    location ~ ^/(?:offers|lander)/[^/]+/.*\\.php\$ {\n";
    $b .= "        rewrite ^ /index.php last;\n";
    $b .= "    }\n\n";

    $b .= "    # /landings/<id>/ is the storage directory behind /lander/<slug>/, not a\n";
    $b .= "    # public route. Nothing under it is ever executed.\n";
    $b .= "    location ~ ^/landings/.*\\.php\$ {\n";
    $b .= "        return 404;\n";
    $b .= "    }\n\n";

    $b .= "    # PHP processing\n";
    $b .= "    location ~ \\.php\$ {\n";
    $b .= "        include snippets/fastcgi-php.conf;\n";
    $b .= "        fastcgi_pass unix:{$fpmSocket};\n";
    $b .= "        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n";
    $b .= "        include fastcgi_params;\n";
    $b .= "    }\n\n";

    $b .= "    # Deny access to SQLite DB and configurations\n";
    $b .= "    location ~ \\.sqlite\$ {\n";
    $b .= "        deny all;\n";
    $b .= "    }\n";
    $b .= "    location ~ /\\. {\n";
    $b .= "        deny all;\n";
    $b .= "    }\n\n";

    $b .= "    # Deny access to log files, environment files, and sensitive data\n";
    $b .= "    # Must come before PHP handler to take precedence\n";
    $b .= "    location ~* \\.(log|txt|json|env|git|bak|sql)\$ {\n";
    $b .= "        deny all;\n";
    $b .= "        return 404;\n";
    $b .= "    }\n";

    return $b;
}

/**
 * Build the complete config.
 *
 * @param string[] $domains            Parked domain names.
 * @param bool     $withDefaultServer  Emit the default_server flag. Set false to
 *                                     retry when another vhost on the box already
 *                                     claims it and nginx -t reports a duplicate.
 */
function orbitraBuildNginxConfig(array $domains, bool $withDefaultServer = true): string
{
    $fpmSocket = orbitraPhpFpmSocket();
    $body = orbitraNginxCommonBody($fpmSocket);

    $domains = array_values(array_unique(array_filter(array_map(
        static fn($d) => strtolower(trim((string) $d)),
        $domains
    ))));
    sort($domains);

    $sslDomains = [];
    foreach ($domains as $domain) {
        if (file_exists(ORBITRA_LETSENCRYPT_DIR . "/live/$domain/fullchain.pem")) {
            $sslDomains[] = $domain;
        }
    }

    $default = $withDefaultServer ? ' default_server' : '';

    $c = "# Auto-generated by Orbitra - DO NOT EDIT MANUALLY\n";
    $c .= "# Regenerate with: sudo php /var/www/orbitra/cli/nginx_sync.php\n\n";

    // ---- Catch-all -------------------------------------------------------
    $c .= "# Catch-all. Serves everything that does not match a parked domain,\n";
    $c .= "# which is what keeps the admin panel reachable at the server IP.\n";
    $c .= "server {\n";
    $c .= "    listen 80{$default};\n";
    $c .= "    server_name _;\n\n";
    $c .= $body;
    $c .= "}\n\n";

    // HTTPS catch-all, so that https://<ip>/admin.php opens the panel behind a
    // self-signed warning instead of presenting some parked domain's certificate.
    // Note: default_server is NOT used here to avoid collisions with other vhosts.
    // Being the first server block in the config makes this the default for port 443.
    if (file_exists(ORBITRA_SELF_SIGNED_CERT) && file_exists(ORBITRA_SELF_SIGNED_KEY)) {
        $c .= "# HTTPS catch-all with a self-signed certificate. Let's Encrypt does not\n";
        $c .= "# issue for bare IPs, so the browser will warn — the panel still opens.\n";
        $c .= "server {\n";
        $c .= "    listen 443 ssl;\n";
        $c .= "    server_name _;\n\n";
        $c .= "    ssl_certificate " . ORBITRA_SELF_SIGNED_CERT . ";\n";
        $c .= "    ssl_certificate_key " . ORBITRA_SELF_SIGNED_KEY . ";\n\n";
        $c .= $body;
        $c .= "}\n\n";
    }

    // ---- Parked domains --------------------------------------------------
    if (!empty($domains)) {
        $c .= "# Parked domains over HTTP.\n";
        $c .= "server {\n";
        $c .= "    listen 80;\n";
        $c .= "    server_name " . implode(' ', $domains) . ";\n\n";
        $c .= $body;
        $c .= "}\n\n";
    }

    foreach ($sslDomains as $domain) {
        $c .= "server {\n";
        $c .= "    listen 443 ssl;\n";
        $c .= "    server_name $domain;\n\n";
        $c .= "    ssl_certificate " . ORBITRA_LETSENCRYPT_DIR . "/live/$domain/fullchain.pem;\n";
        $c .= "    ssl_certificate_key " . ORBITRA_LETSENCRYPT_DIR . "/live/$domain/privkey.pem;\n";
        if (file_exists(ORBITRA_LETSENCRYPT_DIR . '/options-ssl-nginx.conf')) {
            $c .= '    include ' . ORBITRA_LETSENCRYPT_DIR . "/options-ssl-nginx.conf;\n";
        }
        if (file_exists(ORBITRA_LETSENCRYPT_DIR . '/ssl-dhparams.pem')) {
            $c .= '    ssl_dhparam ' . ORBITRA_LETSENCRYPT_DIR . "/ssl-dhparams.pem;\n";
        }
        $c .= "\n";
        $c .= $body;
        $c .= "}\n\n";
    }

    return $c;
}

/**
 * Regenerate the config from the database, verify it and reload nginx.
 *
 * Returns ['status' => 'success'|'skip'|'pending'|'error', 'message' => string].
 *
 * Writing needs root. Direct writes work from the CLI (run under sudo); from the
 * API, www-data falls back to the single `cp` whitelisted in
 * /etc/sudoers.d/orbitra-ssl. Either way the previous config is restored if the
 * new one fails nginx -t, so a bad save can never leave a server that will not
 * come back up after a restart.
 */
function orbitraSyncNginx(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("SELECT name FROM domains WHERE name IS NOT NULL AND name != '' ORDER BY name");
        $domains = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $path = ORBITRA_NGINX_CONFIG_PATH;
        if (!file_exists($path)) {
            return ['status' => 'error', 'message' => 'Nginx config not found at ' . $path];
        }

        $previous = (string) @file_get_contents($path);
        $stage = '/tmp/orbitra_nginx_update.conf';

        $write = static function (string $contents) use ($path, $stage): bool {
            if (@file_put_contents($path, $contents) !== false) {
                return true;
            }
            if (@file_put_contents($stage, $contents) === false) {
                return false;
            }
            orbitraShell("sudo cp $stage $path 2>&1");
            @unlink($stage);
            return (string) @file_get_contents($path) === $contents;
        };

        $test = static function (): string {
            return (string) orbitraShell('sudo nginx -t 2>&1');
        };

        $config = orbitraBuildNginxConfig($domains, true);

        if (trim($config) === trim($previous)) {
            return ['status' => 'skip', 'message' => 'Config unchanged'];
        }

        if (!$write($config)) {
            return ['status' => 'error', 'message' => 'Cannot write ' . $path . ' (check /etc/sudoers.d/orbitra-ssl)'];
        }

        $output = $test();
        if (strpos($output, 'successful') === false) {
            // Another vhost on the box may already own default_server for this
            // port, which makes nginx reject the whole config. Retry without the
            // flag — being the first server block still makes this one the default.
            $config = orbitraBuildNginxConfig($domains, false);
            $write($config);
            $retry = $test();
            if (strpos($retry, 'successful') === false) {
                $write($previous);
                orbitraShell('sudo systemctl reload nginx 2>&1');
                return [
                    'status' => 'error',
                    'message' => 'Nginx config test failed, previous config restored: ' . trim($output),
                ];
            }
        }

        $reload = orbitraShell('sudo systemctl reload nginx 2>&1');
        $reloaded = ($reload === null || stripos((string) $reload, 'fail') === false);

        $https = substr_count($config, 'listen 443 ssl;');
        $count = count($domains);

        if ($reloaded) {
            return [
                'status' => 'success',
                'message' => "Nginx updated: $count domain(s) + IP fallback, $https HTTPS",
            ];
        }

        return ['status' => 'pending', 'message' => 'Config updated, but nginx reload failed. Run: sudo systemctl reload nginx'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}
