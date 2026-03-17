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

    // Install SSL using Certbot
    $cmd = "sudo certbot --nginx -n -d $domainName --agree-tos --register-unsafely-without-email 2>&1";
    $output = shell_exec($cmd);

    if ($output === null) {
        $output = '';
    }

    // Check if installation was successful
    if (strpos($output, 'successfully') !== false ||
        strpos($output, 'certificate was successfully deployed') !== false ||
        file_exists($certPath)) {

        // Mark as installed
        $pdo->prepare("UPDATE domains SET ssl_status = 'installed', ssl_error = NULL WHERE id = ?")->execute([$domainId]);

        // Regenerate Nginx config to include HTTPS block for this domain
        try {
            // Include the api.php file to get the updateNginxConfig function
            if (file_exists(__DIR__ . '/../api.php')) {
                // We need to call updateNginxConfig but can't include api.php directly
                // because it will try to handle the request. Instead, reload nginx.
                shell_exec('sudo systemctl reload nginx 2>&1');
            }
        } catch (Throwable $e) {
            // Ignore nginx reload errors
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
