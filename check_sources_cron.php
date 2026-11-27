#!/usr/bin/env php
<?php
/**
 * Orbitra Traffic Sources URL Checker
 *
 * Проверяет доступность URL всех источников трафика
 * Можно запустить через cron:
 *   0,30 * * * * php /path/to/check_sources_cron.php
 *
 * Или вручную:
 *   php check_sources_cron.php
 */

// Load config
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/url_guard.php';

// Main execution
try {
    // Get all sources with URLs
    $stmt = $pdo->query("SELECT id, name, url FROM traffic_sources WHERE url IS NOT NULL AND url != '' AND is_archived = 0");
    $sources = $stmt->fetchAll();

    if (empty($sources)) {
        echo "No traffic sources with URLs found.\n";
        exit(0);
    }

    $updateStmt = $pdo->prepare("UPDATE traffic_sources SET http_status = ?, last_checked = datetime('now'), status_message = ? WHERE id = ?");

    $results = [
        'total' => count($sources),
        'checked' => 0,
        'ok' => 0,
        'error' => 0,
        'timeout' => 0,
        'details' => []
    ];

    echo "Checking " . count($sources) . " traffic source URLs...\n";

    foreach ($sources as $source) {
        $result = checkUrlAvailability($source['url']);
        $updateStmt->execute([$result['status'], $result['message'], $source['id']]);

        $results['checked']++;

        if ($result['status'] === '200') {
            $results['ok']++;
        } elseif ($result['status'] === 'timeout') {
            $results['timeout']++;
        } else {
            $results['error']++;
        }

        $results['details'][] = [
            'id' => $source['id'],
            'name' => $source['name'],
            'url' => $source['url'],
            'status' => $result['status'],
            'message' => $result['message']
        ];

        echo sprintf(
            "  [%s] %s: %s - %s\n",
            $source['id'],
            $source['name'],
            $result['status'],
            $result['message']
        );
    }

    echo "\nSummary:\n";
    echo "  Total: {$results['total']}\n";
    echo "  Checked: {$results['checked']}\n";
    echo "  OK: {$results['ok']}\n";
    echo "  Errors: {$results['error']}\n";
    echo "  Timeouts: {$results['timeout']}\n";

    exit(0);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
