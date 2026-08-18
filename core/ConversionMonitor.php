<?php
/**
 * Conversion Monitoring & Alerting System
 *
 * Tracks conversion creation failures and provides alerting when failure rates
 * exceed configured thresholds. Helps identify integration issues before they
 * significantly impact reporting accuracy.
 */

if (!function_exists('orbitraLogConversionFailure')) {

    /**
     * Log a conversion failure with contextual information for debugging.
     *
     * @param string $clickId The click ID that failed conversion creation
     * @param string $reason The error reason or exception message
     * @param array $context Additional context (campaign_id, source, etc.)
     * @return bool True if log was written successfully
     */
    function orbitraLogConversionFailure(string $clickId, string $reason, array $context = []): bool
    {
        $logDir = __DIR__ . '/../var/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $logFile = $logDir . '/conversion_failures.log';
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        $logLine = "[$timestamp] click_id=$clickId reason=$reason$contextStr" . PHP_EOL;

        return @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX) !== false;
    }
}

if (!function_exists('orbitraCheckConversionAlertThreshold')) {

    /**
     * Check if conversion failure rate exceeds alert threshold.
     *
     * @param PDO $pdo Database connection
     * @param int $thresholdPercentage Alert threshold (0-100)
     * @param int $windowMinutes Time window to check (default: 60 minutes)
     * @return array ['alert'=>bool, 'rate'=>float, 'total'=>int, 'failed'=>int]
     */
    function orbitraCheckConversionAlertThreshold(PDO $pdo, int $thresholdPercentage = 10, int $windowMinutes = 60): array
    {
        $result = ['alert' => false, 'rate' => 0.0, 'total' => 0, 'failed' => 0];

        try {
            // Count total leads (vault writes) in the time window
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM crm_leads
                WHERE created_at >= datetime('now', '-$windowMinutes minutes')
                    AND is_qa_test = 0
            ");
            $stmt->execute();
            $result['total'] = (int) $stmt->fetchColumn();

            if ($result['total'] === 0) {
                return $result;
            }

            // Count failed conversions by reading the log file
            $logFile = __DIR__ . '/../var/logs/conversion_failures.log';
            if (file_exists($logFile)) {
                $cutoffTime = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));
                $handle = @fopen($logFile, 'r');
                if ($handle) {
                    while (($line = fgets($handle)) !== false) {
                        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                            if ($matches[1] >= $cutoffTime) {
                                $result['failed']++;
                            }
                        }
                    }
                    fclose($handle);
                }
            }

            // Calculate failure rate
            $result['rate'] = $result['total'] > 0
                ? round(($result['failed'] / $result['total']) * 100, 2)
                : 0.0;

            // Trigger alert if threshold exceeded
            $result['alert'] = $result['rate'] >= $thresholdPercentage;

        } catch (\Throwable $e) {
            // Silently fail - monitoring should never break the application
        }

        return $result;
    }
}

if (!function_exists('orbitraSendConversionAlert')) {

    /**
     * Send an alert notification for high conversion failure rates.
     *
     * @param array $metrics The alert metrics from orbitraCheckConversionAlertThreshold
     * @return bool True if alert was sent successfully
     */
    function orbitraSendConversionAlert(array $metrics): bool
    {
        if (!$metrics['alert']) {
            return false;
        }

        $message = sprintf(
            "⚠️ Conversion Failure Alert\n" .
            "Failure Rate: %s%%\n" .
            "Total Leads: %d\n" .
            "Failed Conversions: %d\n" .
            "Time: %s\n\n" .
            "Check var/logs/conversion_failures.log for details.",
            $metrics['rate'],
            $metrics['total'],
            $metrics['failed'],
            date('Y-m-d H:i:s')
        );

        // Log to system alerts log
        $logDir = __DIR__ . '/../var/logs';
        $alertFile = $logDir . '/conversion_alerts.log';
        $logLine = "[" . date('Y-m-d H:i:s') . "] " . str_replace("\n", ' | ', $message) . PHP_EOL;
        @file_put_contents($alertFile, $logLine, FILE_APPEND | LOCK_EX);

        // Send to Telegram if configured
        try {
            if (file_exists(__DIR__ . '/../telegram_notify.php')) {
                require_once __DIR__ . '/../telegram_notify.php';
                // Telegram integration would call a notification function here
                // This is a placeholder for future implementation
            }
        } catch (\Throwable $e) {
            // Continue silently
        }

        return true;
    }
}

if (!function_exists('orbitraGetConversionFailureStats')) {

    /**
     * Get recent conversion failure statistics for dashboard display.
     *
     * @param PDO $pdo Database connection
     * @param int $hours Hours to look back (default: 24)
     * @return array Statistics including hourly breakdown
     */
    function orbitraGetConversionFailureStats(PDO $pdo, int $hours = 24): array
    {
        $stats = [
            'period_hours' => $hours,
            'total_leads' => 0,
            'estimated_failures' => 0,
            'failure_rate' => 0.0,
            'hourly_breakdown' => [],
            'recent_errors' => []
        ];

        try {
            // Get total leads in period
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM crm_leads
                WHERE created_at >= datetime('now', '-$hours hours')
                    AND is_qa_test = 0
            ");
            $stmt->execute();
            $stats['total_leads'] = (int) $stmt->fetchColumn();

            // Read recent errors from log
            $logFile = __DIR__ . '/../var/logs/conversion_failures.log';
            $cutoffTime = date('Y-m-d H:i:s', time() - ($hours * 3600));

            if (file_exists($logFile) && is_readable($logFile)) {
                $handle = @fopen($logFile, 'r');
                if ($handle) {
                    $hourlyCounts = [];
                    $recentErrors = [];
                    $lineNum = 0;

                    while (($line = fgets($handle)) !== false && $lineNum < 1000) {
                        $lineNum++;
                        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*click_id=([^\s]+)\s+reason=([^\s]+)/', $line, $matches)) {
                            $timestamp = $matches[1];
                            $clickId = $matches[2];
                            $reason = substr($matches[3], 0, 100);

                            if ($timestamp >= $cutoffTime) {
                                $hour = substr($timestamp, 0, 13); // YYYY-MM-DD HH
                                $hourlyCounts[$hour] = ($hourlyCounts[$hour] ?? 0) + 1;
                                $stats['estimated_failures']++;

                                // Keep last 10 error details
                                if (count($recentErrors) < 10) {
                                    $recentErrors[] = [
                                        'time' => $timestamp,
                                        'click_id' => $clickId,
                                        'reason' => $reason
                                    ];
                                }
                            }
                        }
                    }
                    fclose($handle);

                    // Build hourly breakdown
                    ksort($hourlyCounts);
                    foreach ($hourlyCounts as $hour => $count) {
                        $stats['hourly_breakdown'][] = ['hour' => $hour, 'failures' => $count];
                    }

                    $stats['recent_errors'] = array_reverse($recentErrors);
                }
            }

            // Calculate failure rate
            $stats['failure_rate'] = $stats['total_leads'] > 0
                ? round(($stats['estimated_failures'] / $stats['total_leads']) * 100, 2)
                : 0.0;

        } catch (\Throwable $e) {
            $stats['error'] = $e->getMessage();
        }

        return $stats;
    }
}
