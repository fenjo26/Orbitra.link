<?php
/**
 * Certificate queue worker.
 *
 * Run from cron every few minutes. All the logic lives in core/ssl_manager.php,
 * which the panel calls too, so a certificate is obtained the same way whether a
 * human just saved a domain or the schedule came round.
 *
 * Usage: php /var/www/orbitra/cli/ssl_installer.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/ssl_manager.php';

$result = orbitraProcessSslQueue($pdo);

// Output matters: this runs from cron, and a silent worker is one nobody can
// debug when a domain never gets its certificate.
printf(
    "[%s] checked=%d issued=%d waiting_dns=%d failed=%d nginx_synced=%s\n",
    date('Y-m-d H:i:s'),
    $result['checked'],
    $result['issued'],
    $result['waiting'],
    $result['failed'],
    $result['synced'] ? 'yes' : 'no'
);

exit(0);
