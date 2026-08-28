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

// The every-minute crons can hold the SQLite write lock; a locked run is a
// "try again", not a stack trace — especially for the operator who just
// parked a domain and ran this by hand to get its certificate now.
try {
    $result = orbitraProcessSslQueue($pdo);
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] worker aborted: %s — the database was busy (every-minute crons); run me again\n",
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));
    exit(1);
}

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
