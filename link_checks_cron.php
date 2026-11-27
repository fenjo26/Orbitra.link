<?php
// link_checks_cron.php
// Cron worker: opens the tracked offers and domains on their schedule and
// records what came back (core/LinkChecks.php).
//
// One pass re-reads the scope, probes the proxies that are due and checks as
// many due links as its time budget allows — what does not fit waits for the
// next minute. The pass itself is orbitraLinkChecksPass(); this file is the
// single-instance lock, the liveness ping and the log line around it.
//
// Example cron (every minute):
// * * * * * php /var/www/orbitra/link_checks_cron.php >> /var/www/orbitra/var/logs/link_checks.log 2>&1

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/LinkChecks.php';

// --- Single-instance lock ----------------------------------------------------
$lockFile = __DIR__ . '/var/locks/link_checks.lock';
if (!is_dir(__DIR__ . '/var/locks')) {
    @mkdir(__DIR__ . '/var/locks', 0777, true);
}

$fp = @fopen($lockFile, 'c+');
if ($fp && !flock($fp, LOCK_EX | LOCK_NB)) {
    // Another worker running.
    exit(0);
}

// Settings timestamps must be UTC: worker_health parses them with an explicit
// ' UTC' modifier, and date() here would write server-local time.
$tsUtc = gmdate('Y-m-d H:i:s');

try {
    orbitraLinkChecksSetSetting($pdo, 'link_checks_last_ping_at', $tsUtc);

    // Allow disabling from the panel while keeping the cron in place.
    $settings = orbitraLinkChecksSettings($pdo);
    if (!$settings['enabled']) {
        echo "[$tsUtc] link_checks: disabled via settings\n";
        exit(0);
    }

    $stats = orbitraLinkChecksPass($pdo);
    orbitraLinkChecksSetSetting($pdo, 'link_checks_last_run', json_encode($stats));
    echo "[$tsUtc] link_checks run: checked={$stats['checked']} problems={$stats['problems']} recovered={$stats['recovered']}"
        . " proxies_probed={$stats['proxies_probed']} added={$stats['added']} removed={$stats['removed']}\n";
} catch (Throwable $e) {
    orbitraLinkChecksSetSetting($pdo, 'link_checks_last_error', $e->getMessage());
    echo "[$tsUtc] link_checks error: " . $e->getMessage() . "\n";
} finally {
    if (isset($fp) && is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
