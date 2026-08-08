#!/usr/bin/php
<?php
/**
 * Orbitra SSL Background Installer
 *
 * This script runs in the background to install SSL certificates for domains
 * that have https_only enabled but don't have SSL yet.
 *
 * It's triggered by the API when domains are added/updated with HTTPS-only.
 * Processes up to 5 domains per run to avoid blocking.
 *
 * Usage: php /var/www/orbitra/cli/ssl_installer.php
 *         (normally called automatically from API with &)
 */

// Change to Orbitra directory to ensure relative paths work
chdir(dirname(__DIR__));

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/nginx_config.php';

// Find domains with pending SSL (limit to 5 per run)
$stmt = $pdo->prepare("SELECT id, name FROM domains WHERE ssl_status = 'pending' AND https_only = 1 LIMIT 5");
$stmt->execute();
$domains = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($domains)) {
    // No pending SSL installations
    exit(0);
}

foreach ($domains as $domain) {
    $domainId = (int) $domain['id'];
    $domainName = $domain['name'];

    // Mark as installing
    $pdo->prepare("UPDATE domains SET ssl_status = 'installing' WHERE id = ?")->execute([$domainId]);

    // Check if SSL certificate already exists
    $certPath = "/etc/letsencrypt/live/$domainName/cert.pem";
    if (file_exists($certPath)) {
        // SSL already exists, mark as installed
        $pdo->prepare("UPDATE domains SET ssl_status = 'installed', ssl_error = NULL WHERE id = ?")->execute([$domainId]);
        continue;
    }

    // Obtain the certificate. certonly + webroot on purpose: Certbot's nginx
    // installer used to rewrite the site config, narrowing server_name to this
    // domain and appending `return 404`, which is what cut off access by IP.
    $output = shell_exec(orbitraCertbotCertonlyCommand($domainName) . ' 2>&1');

    if ($output === null) {
        $output = '';
    }

    if (orbitraCertbotSucceeded($output, $domainName)) {
        // Mark as installed
        $pdo->prepare("UPDATE domains SET ssl_status = 'installed', ssl_error = NULL WHERE id = ?")->execute([$domainId]);

        // Regenerate the config so this domain gets its HTTPS server block.
        // Certbot deliberately no longer does this for us.
        try {
            orbitraSyncNginx($pdo);
        } catch (Throwable $e) {
            // Non-fatal: the certificate exists, the next sync will pick it up.
        }
    } else {
        // Mark as failed with error message
        $errorMsg = substr($output, 0, 500);
        if (empty($errorMsg)) {
            $errorMsg = 'Unknown error - Certbot produced no output';
        }
        $pdo->prepare("UPDATE domains SET ssl_status = 'failed', ssl_error = ? WHERE id = ?")->execute([$errorMsg, $domainId]);
    }
}

exit(0);
