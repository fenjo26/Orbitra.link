<?php
/**
 * Conversion Monitoring Cron Script
 *
 * Periodically checks conversion failure rates and sends alerts when thresholds
 * are exceeded. Should be run every 5-15 minutes via cron.
 *
 * Crontab example (every 5 minutes):
 *   printf "*\/5 * * * * php /var/www/orbitra/conversion_monitor_cron.php\n" | crontab -
 *
 * Usage:
 *   php conversion_monitor_cron.php [--alert-threshold=10] [--window=60]
 */

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

// Parse CLI arguments
$options = getopt('', ['alert-threshold:', 'window:', 'quiet']);
$threshold = (int)($options['alert-threshold'] ?? 10);  // Alert if failure rate >= 10%
$window = (int)($options['window'] ?? 60);               // Check last 60 minutes
$isQuiet = isset($options['quiet']);

function log_msg(string $msg): void
{
    global $isQuiet;
    if (!$isQuiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }
}

// Load config & DB
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/ConversionMonitor.php';

log_msg('=== Conversion Monitor Start ===');
log_msg("Threshold: {$threshold}%, Window: {$window} minutes");

try {
    // Check conversion failure rate
    $metrics = orbitraCheckConversionAlertThreshold($pdo, $threshold, $window);

    log_msg("Total Leads: {$metrics['total']}");
    log_msg("Failed Conversions: {$metrics['failed']}");
    log_msg("Failure Rate: {$metrics['rate']}%");

    if ($metrics['alert']) {
        log_msg('⚠️  ALERT THRESHOLD EXCEEDED!');
        $sent = orbitraSendConversionAlert($metrics);

        if ($sent) {
            log_msg('✓ Alert sent successfully');
        } else {
            log_msg('✗ Alert sending failed');
        }

        // Also check for persistent issues (look back 24 hours)
        $stats = orbitraGetConversionFailureStats($pdo, 24);
        if ($stats['failure_rate'] > ($threshold / 2)) {
            log_msg('⚠️  ELEVATED 24-HOUR FAILURE RATE: ' . $stats['failure_rate'] . '%');
        }
    } else {
        log_msg('✓ Failure rate within normal range');
    }

    // Clean up old log entries (keep last 7 days)
    $logFile = __DIR__ . '/var/logs/conversion_failures.log';
    if (file_exists($logFile)) {
        $cutoffTime = date('Y-m-d H:i:s', strtotime('-7 days'));
        $lines = file($logFile);
        $filtered = [];
        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                if ($matches[1] >= $cutoffTime) {
                    $filtered[] = $line;
                }
            }
        }
        if (count($filtered) < count($lines)) {
            @file_put_contents($logFile, implode('', $filtered));
            log_msg('Cleaned up old log entries');
        }
    }

    log_msg('=== Conversion Monitor Complete ===');

} catch (\Throwable $e) {
    log_msg('✗ ERROR: ' . $e->getMessage());
    exit(1);
}
