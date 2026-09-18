<?php
// Orbitra — Geo database updater (installer hook + monthly cron).
//
// Sypex Geo City is free and needs no keys, so it is updated on every run.
// MaxMind GeoLite2 and IP2Location/IP2Proxy download only when their account
// keys are saved in the panel (Settings → Geo databases) — without keys those
// endpoints cannot answer anything useful.
//
// Every outcome is a result line, never a crash: a failed download must not
// take the tracker down or spam the cron with stack traces.
//
// Usage:
//   php cli/geo_update.php            — normal run
//   php cli/geo_update.php --quiet    — log file only, no stdout
//
// Cron (install.sh adds it with the "# orbitra-geo" marker):
//   17 4 1 * * php /var/www/orbitra/cli/geo_update.php >> /var/www/orbitra/var/logs/geo_update.log 2>&1 # orbitra-geo

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

$options = getopt('', ['quiet']);
$isQuiet = isset($options['quiet']);

$root = dirname(__DIR__);
$logPath = $root . '/var/logs/geo_update.log';
if (!is_dir(dirname($logPath))) {
    @mkdir(dirname($logPath), 0755, true);
}

$writeLog = static function (string $msg) use ($logPath, $isQuiet): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($logPath, $line, FILE_APPEND);
    if (!$isQuiet) {
        echo $line;
    }
};

require_once $root . '/config.php';
require_once $root . '/core/geo_databases.php';

$writeLog('=== Orbitra geo databases update start ===');
$exitCode = 0;

// Sypex Geo City — always.
$sypex = orbitraUpdateSypex($root);
$writeLog(($sypex['ok'] ? 'OK   sypex_city: ' : 'FAIL sypex_city: ') . $sypex['message']);
if (!$sypex['ok']) {
    $exitCode = 1;
}

$setting = static function (string $key) use ($pdo): string {
    try {
        return trim((string) $pdo->query("SELECT value FROM settings WHERE key = " . $pdo->quote($key))->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
};

// MaxMind GeoLite2 — when the account is configured.
$maxmindKey = $setting('maxmind_license_key');
$maxmindAccount = $setting('maxmind_account_id');
if ($maxmindKey !== '' && $maxmindAccount !== '') {
    foreach (['GeoLite2-City', 'GeoLite2-ASN'] as $edition) {
        $res = orbitraUpdateMaxMind($edition, $maxmindAccount, $maxmindKey, $root);
        $writeLog(($res['ok'] ? "OK   maxmind {$edition}: " : "FAIL maxmind {$edition}: ") . $res['message']);
        if (!$res['ok']) {
            $exitCode = 1;
        }
    }
} else {
    $writeLog('SKIP maxmind: Account ID / License Key не заданы в настройках');
}

// IP2Location + IP2Proxy — when the token is configured.
$ip2Token = $setting('ip2location_token');
if ($ip2Token !== '') {
    $packages = [
        ['DB11LITEBINIPV6', 'ip2location_geo'],
        ['DBASNLITEBINIPV6', 'ip2location_asn'],
        ['PX12LITEBIN', 'ip2proxy'],
    ];
    foreach ($packages as [$variant, $kind]) {
        $res = orbitraUpdateIp2($variant, $kind, $ip2Token, $root);
        $writeLog(($res['ok'] ? "OK   ip2 {$variant}: " : "FAIL ip2 {$variant}: ") . $res['message']);
        if (!$res['ok']) {
            $exitCode = 1;
        }
    }
} else {
    $writeLog('SKIP ip2location: Token не задан в настройках');
}

$writeLog("=== done (exit {$exitCode}) ===");
exit($exitCode);
