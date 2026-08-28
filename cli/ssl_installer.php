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

// Optional per-run domain limit (the panel's synchronous run passes a small
// one so a bulk paste answers fast; cron takes the default 5).
$workerLimit = max(1, min(10, (int) ($argv[1] ?? 5)));

// One worker at a time. Certbot itself locks /var/lib/letsencrypt, and two
// concurrent workers (the save-time synchronous run racing the cron tick)
// used to collide on it — the loser failed its domain with a lock error.
// A held lock means another instance is already working the queue: exit
// quietly, it will do our share.
$lockFile = __DIR__ . '/../var/ssl_worker.lock';
if (!is_dir(dirname($lockFile))) {
    @mkdir(dirname($lockFile), 0775, true);
}
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] another worker instance is running — nothing to do\n";
    exit(0);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/ssl_manager.php';

// The every-minute crons can hold the SQLite write lock; a locked run is a
// "try again", not a stack trace — especially for the operator who just
// parked a domain and ran this by hand to get its certificate now.
try {
    $result = orbitraProcessSslQueue($pdo, $workerLimit);
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
