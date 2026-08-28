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
function orbitraCertbotCertonlyCommand(string $domain, bool $force = false): string
{
    @mkdir(ORBITRA_ACME_WEBROOT . '/.well-known/acme-challenge', 0775, true);

    // --force-renewal is for the panel's "re-issue" button, which must actually
    // re-issue: with --keep-until-expiring certbot prints "not yet due for
    // renewal" and keeps the line it was asked to replace. The background
    // worker keeps the gentle flag — renewals there should respect the limits.
    $renewalFlag = $force ? '--force-renewal' : '--keep-until-expiring';

    return 'sudo certbot certonly --webroot -w ' . escapeshellarg(ORBITRA_ACME_WEBROOT)
        . ' -n -d ' . escapeshellarg($domain)
        . " --agree-tos --register-unsafely-without-email $renewalFlag";
}

/**
 * Does a Let's Encrypt certificate line exist for this domain — root's view?
 *
 * `file_exists()` here runs as the web user, and on hosts where certbot's
 * output stays root-only it answers false for a certificate that very much
 * exists, which is how a healthy domain ends up classified self-signed and
 * gets an ERR_CERT_AUTHORITY_INVALID in the browser. `sudo certbot
 * certificates` reads the same tree as root and needs no sudoers entry
 * beyond the one install.sh already writes for certbot itself; it lists
 * certificate names and paths, never private key material.
 *
 * Cached per process: the worker asks once per domain per run.
 */
function orbitraLetsEncryptCertExists(string $domain): bool
{
    static $cache = [];
    $domain = strtolower(trim($domain));
    if ($domain === '') {
        return false;
    }
    if (isset($cache[$domain])) {
        return $cache[$domain];
    }

    if (file_exists(ORBITRA_LETSENCRYPT_DIR . "/live/$domain/fullchain.pem")) {
        return $cache[$domain] = true;
    }

    if (!orbitraShellAvailable() || !orbitraCommandExists('sudo') || !orbitraCommandExists('certbot')) {
        return false;
    }

    $listing = orbitraShell('sudo -n certbot certificates 2>/dev/null');
    $found = is_string($listing)
        && stripos($listing, 'Certificate Name: ' . $domain) !== false;

    return $cache[$domain] = $found;
}

/**
 * Did a Certbot run end with a usable certificate?
 */
function orbitraCertbotSucceeded(?string $output, string $domain): bool
{
    // Root's view, not the web user's: certbot writes as root, and on a
    // root-only /etc/letsencrypt a plain file_exists() here would deny a
    // certificate that was just written.
    if (orbitraLetsEncryptCertExists($domain)) {
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
 * Cloudflare Real IP restoration and HTTPS forwarding directives.
 * Used for domains proxied through Cloudflare to restore visitor IPs
 * and properly handle HTTPS protocol forwarding.
 *
 * @return string Nginx directives for Cloudflare
 */
function orbitraCloudflareDirectives(): string
{
    $cf = "    # Cloudflare Real IP restoration\n";
    $cf .= "    set_real_ip_from 173.245.48.0/20;\n";
    $cf .= "    set_real_ip_from 103.21.244.0/22;\n";
    $cf .= "    set_real_ip_from 103.22.200.0/22;\n";
    $cf .= "    set_real_ip_from 103.31.4.0/24;\n";
    $cf .= "    set_real_ip_from 141.101.64.0/18;\n";
    $cf .= "    set_real_ip_from 108.162.192.0/18;\n";
    $cf .= "    set_real_ip_from 190.93.240.0/20;\n";
    $cf .= "    set_real_ip_from 188.114.96.0/20;\n";
    $cf .= "    set_real_ip_from 197.234.240.0/22;\n";
    $cf .= "    set_real_ip_from 198.41.128.0/17;\n";
    $cf .= "    set_real_ip_from 162.158.0.0/15;\n";
    $cf .= "    set_real_ip_from 104.16.0.0/13;\n";
    $cf .= "    set_real_ip_from 104.24.0.0/14;\n";
    $cf .= "    set_real_ip_from 172.64.0.0/13;\n";
    $cf .= "    set_real_ip_from 131.0.72.0/22;\n";
    $cf .= "    # IPv6\n";
    $cf .= "    set_real_ip_from 2606:4700::/32;\n";
    $cf .= "    set_real_ip_from 2606:4700::/36;\n";
    $cf .= "    set_real_ip_from 2803:f800::/32;\n";
    $cf .= "    set_real_ip_from 2405:b500::/32;\n";
    $cf .= "    set_real_ip_from 2405:8100::/32;\n";
    $cf .= "    set_real_ip_from 2a06:98c1::/32;\n";
    $cf .= "    set_real_ip_from 2c0f:f248::/32;\n";
    $cf .= "    real_ip_header CF-Connecting-IP;\n\n";

    $cf .= "    # Trust Cloudflare protocol forwarding for HTTPS detection\n";
    $cf .= "    set \$https_proto \"off\";\n";
    $cf .= "    if (\$http_cf_visitor_https = \"https\") {\n";
    $cf .= "        set \$https_proto \"on\";\n";
    $cf .= "    }\n\n";

    return $cf;
}

/**
 * The location blocks every server block shares.
 */
function orbitraNginxCommonBody(string $fpmSocket): string
{
    $b = "    root /var/www/orbitra;\n";
    $b .= "    index index.php admin.php index.html;\n\n";

    $b .= "    # ORB-013: Internal location for X-Accel-Redirect (flattened, no nested regex).\n";
    $b .= "    # PHP resolves landing asset paths with security checks, then hands\n";
    $b .= "    # off to nginx via X-Accel-Redirect. This location serves the file\n";
    $b .= "    # with sendfile (zero-copy) while PHP is freed for the next request.\n";
    $b .= "    # A landing page with 30 assets no longer means 30 PHP processes.\n";
    $b .= "    #\n";
    $b .= "    # CRITICAL: No nested location ~* block! Nginx breaks alias inheritance\n";
    $b .= "    # when a nested regex location is used, causing redirect loops.\n";
    $b .= "    # PHP handles all security checks and MIME type validation.\n";
    $b .= "    location /_internal_assets/ {\n";
    $b .= "        internal;\n";
    $b .= "        alias /var/www/orbitra/;\n";
    $b .= "        expires 1h;\n";
    $b .= "        add_header Cache-Control \"public, immutable\";\n";
    $b .= "    }\n\n";

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
 * @param array[] $domains            Array of domain arrays with metadata:
 *                                    Each domain array must have 'name' key.
 *                                    May include 'custom_ssl_cert', 'custom_ssl_key',
 *                                    'ssl_source', 'cloudflare_proxy'.
 * @param bool     $withDefaultServer  Emit the default_server flag. Set false to
 *                                     retry when another vhost on the box already
 *                                     claims it and nginx -t reports a duplicate.
 */
function orbitraBuildNginxConfig(array $domains, bool $withDefaultServer = true): string
{
    $fpmSocket = orbitraPhpFpmSocket();
    $body = orbitraNginxCommonBody($fpmSocket);

    // Normalize and extract domain names
    $domainNames = array_values(array_unique(array_filter(array_map(
        static fn($d) => strtolower(trim((string) (is_array($d) ? ($d['name'] ?? '') : $d))),
        $domains
    ))));
    sort($domainNames);

    // Index domains by name for quick lookup
    $domainMap = [];
    foreach ($domains as $d) {
        $name = strtolower(trim((string) (is_array($d) ? ($d['name'] ?? '') : $d)));
        if ($name !== '') {
            $domainMap[$name] = is_array($d) ? $d : ['name' => $name];
        }
    }

    // Categorize domains by SSL certificate source
    $letsEncryptDomains = [];
    $customCertDomains = [];
    $selfSignedDomains = [];

    foreach ($domainNames as $domain) {
        $domainInfo = $domainMap[$domain] ?? ['name' => $domain];
        $customCert = $domainInfo['custom_ssl_cert'] ?? '';
        $customKey = $domainInfo['custom_ssl_key'] ?? '';
        $sslSource = $domainInfo['ssl_source'] ?? 'auto';
        $cloudflareProxy = (int) ($domainInfo['cloudflare_proxy'] ?? 0) === 1;

        // Custom certificate takes precedence
        if ($customCert !== '' && $customKey !== '' && file_exists($customCert) && file_exists($customKey)) {
            $customCertDomains[] = [
                'name' => $domain,
                'cert' => $customCert,
                'key' => $customKey,
                'source' => $sslSource,
            ];
        } elseif (orbitraLetsEncryptCertExists($domain)) {
            $letsEncryptDomains[] = $domain;
        } elseif (!$cloudflareProxy) {
            // Non-cloudflare domains without LE cert get self-signed
            $selfSignedDomains[] = $domain;
        }
    }

    // Cloudflare domains without custom cert still get 443 with self-signed for Full mode
    $cloudflareDomains = array_filter($domainNames, function($name) use ($domainMap) {
        $info = $domainMap[$name] ?? [];
        return (int) ($info['cloudflare_proxy'] ?? 0) === 1 ||
               ($info['ssl_status'] ?? '') === 'cloudflare';
    });

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
    // ORB-014: Orbitra must own the default server on port 443. Any HTTPS request
    // for a hostname Orbitra does not recognise must be answered by Orbitra, never
    // passed to another vhost (e.g. LeadForge's ip-https) that happens to be loaded.
    if (file_exists(ORBITRA_SELF_SIGNED_CERT) && file_exists(ORBITRA_SELF_SIGNED_KEY)) {
        $c .= "# HTTPS catch-all with a self-signed certificate. Let's Encrypt does not\n";
        $c .= "# issue for bare IPs, so the browser will warn — the panel still opens.\n";
        $c .= "# ORB-014: This block owns default_server on port 443 to prevent other\n";
        $c .= "# vhosts from capturing traffic for unknown hostnames.\n";
        $c .= "server {\n";
        $c .= "    listen 443 ssl{$default};\n";
        $c .= "    server_name _;\n\n";
        $c .= "    ssl_certificate " . ORBITRA_SELF_SIGNED_CERT . ";\n";
        $c .= "    ssl_certificate_key " . ORBITRA_SELF_SIGNED_KEY . ";\n\n";
        $c .= $body;
        $c .= "}\n\n";
    }

    // ---- Parked domains over HTTP ----------------------------------------
    if (!empty($domainNames)) {
        $c .= "# Parked domains over HTTP.\n";
        $c .= "server {\n";
        $c .= "    listen 80;\n";
        $c .= "    server_name " . implode(' ', $domainNames) . ";\n\n";
        $c .= $body;
        $c .= "}\n\n";
    }

    // ---- Domains with custom certificates (Cloudflare Origin CA, etc) ----
    foreach ($customCertDomains as $domain) {
        $sourceLabel = match($domain['source']) {
            'cloudflare_origin' => 'Cloudflare Origin CA',
            'custom' => 'Custom',
            default => 'Custom',
        };
        $c .= "# Parked domain over HTTPS with {$sourceLabel} certificate.\n";
        $c .= "server {\n";
        $c .= "    listen 443 ssl;\n";
        $c .= "    server_name {$domain['name']};\n\n";
        $c .= "    ssl_certificate {$domain['cert']};\n";
        $c .= "    ssl_certificate_key {$domain['key']};\n";
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

    // ---- Let's Encrypt certificates ---------------------------------------
    foreach ($letsEncryptDomains as $domain) {
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

    // ---- Self-signed for non-Cloudflare domains without LE cert -------------------------
    $needsSelfSigned = array_diff($selfSignedDomains, $cloudflareDomains);
    $needsSelfSigned = array_diff($needsSelfSigned, array_column($customCertDomains, 'name'), $letsEncryptDomains);
    $needsSelfSigned = array_values(array_unique($needsSelfSigned));

    // ---- Cloudflare domains with self-signed origin (Full SSL mode) ----
    $cloudflareOnlyDomains = array_values(array_intersect($cloudflareDomains, $selfSignedDomains));
    $cloudflareOnlyDomains = array_diff($cloudflareOnlyDomains, array_column($customCertDomains, 'name'), $letsEncryptDomains);
    $cloudflareOnlyDomains = array_values(array_unique($cloudflareOnlyDomains));

    if (!empty($cloudflareOnlyDomains) && file_exists(ORBITRA_SELF_SIGNED_CERT) && file_exists(ORBITRA_SELF_SIGNED_KEY)) {
        $c .= "# Parked domains over HTTPS (Cloudflare Full SSL with self-signed origin).\n";
        $c .= "# ORB-014: Cloudflare edge serves SSL to visitors. Origin uses self-signed\n";
        $c .= "# certificate. Real IP restoration and HTTPS protocol forwarding enabled.\n";
        $c .= "server {\n";
        $c .= "    listen 443 ssl;\n";
        $c .= "    server_name " . implode(' ', $cloudflareOnlyDomains) . ";\n\n";
        $c .= "    ssl_certificate " . ORBITRA_SELF_SIGNED_CERT . ";\n";
        $c .= "    ssl_certificate_key " . ORBITRA_SELF_SIGNED_KEY . ";\n\n";
        $c .= orbitraCloudflareDirectives();
        $c .= $body;
        $c .= "}\n\n";
    }

    if (!empty($needsSelfSigned) && file_exists(ORBITRA_SELF_SIGNED_CERT) && file_exists(ORBITRA_SELF_SIGNED_KEY)) {
        $c .= "# Parked domains over HTTPS (self-signed).\n";
        $c .= "# ORB-014: Every parked domain gets a 443 block. Let's Encrypt\n";
        $c .= "# will replace this when issued.\n";
        $c .= "server {\n";
        $c .= "    listen 443 ssl;\n";
        $c .= "    server_name " . implode(' ', $needsSelfSigned) . ";\n\n";
        $c .= "    ssl_certificate " . ORBITRA_SELF_SIGNED_CERT . ";\n";
        $c .= "    ssl_certificate_key " . ORBITRA_SELF_SIGNED_KEY . ";\n\n";
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
        // ORB-014: Fetch full domain metadata including custom SSL certificate paths
        $stmt = $pdo->query("
            SELECT name, custom_ssl_cert, custom_ssl_key, ssl_source, cloudflare_proxy, ssl_status
            FROM domains
            WHERE name IS NOT NULL AND name != ''
            ORDER BY name
        ");
        $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
