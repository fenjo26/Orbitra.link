<?php
// Orbitra — datacenter/crawler IP ranges updater (cloaking feed).
//
// Downloads the daily-updated all-in-one lists from lord-alfred/ipranges into
// var/ipranges/ for CloakDetector's iprange layer. Skips the run when the files
// are younger than 24h, so a tight cron schedule costs nothing.
//
//   php ipranges_cron.php            — update when stale (the normal cron mode)
//   php ipranges_cron.php --force    — update unconditionally
//   php ipranges_cron.php --quiet    — no output on success
//
// Crontab example (the installer schedules it daily):
//   23 4 * * * php /var/www/orbitra/ipranges_cron.php >> /var/www/orbitra/var/logs/ipranges.log 2>&1

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/IpRanges.php';

$options = getopt('', ['force', 'quiet']);
$isQuiet = isset($options['quiet']);

function ipranges_log(string $msg): void
{
    global $isQuiet;
    if (!$isQuiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }
}

if (!IpRanges::isFresh() || isset($options['force'])) {
    $result = IpRanges::update();
    if ($result['ok']) {
        ipranges_log('IP ranges updated: ipv4=' . $result['ipv4'] . ' ranges, ipv6=' . $result['ipv6'] . ' ranges.');
    } else {
        ipranges_log('IP ranges update FAILED: ' . implode('; ', $result['errors']));
        exit(1);
    }
} else {
    ipranges_log('IP ranges are fresh, nothing to do.');
}
