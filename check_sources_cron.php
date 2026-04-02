#!/usr/bin/env php
<?php
/**
 * Orbitra Traffic Sources URL Checker
 *
 * Проверяет доступность URL всех источников трафика
 * Можно запустить через cron:
 *   */30 * * * * php /path/to/check_sources_cron.php
 *
 * Или вручную:
 *   php check_sources_cron.php
 */

// Load config
require_once __DIR__ . '/config.php';

/**
 * Check URL availability and return status
 * @param string $url The URL to check
 * @return array ['status' => string, 'message' => string]
 */
function checkUrlAvailability($url)
{
    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['status' => 'error', 'message' => 'Invalid URL'];
    }

    // Parse URL and ensure it has a scheme
    $parsed = parse_url($url);
    if (empty($parsed['scheme'])) {
        $url = 'https://' . $url;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true, // HEAD request
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Orbitra/1.0; +https://orbitra.io)',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        if (strpos($error, 'timed out') !== false || strpos($error, 'timeout') !== false) {
            return ['status' => 'timeout', 'message' => 'Timeout'];
        }
        return ['status' => 'error', 'message' => $error];
    }

    if ($httpCode >= 200 && $httpCode < 400) {
        return ['status' => (string) $httpCode, 'message' => 'OK'];
    }

    return ['status' => (string) $httpCode, 'message' => "HTTP $httpCode"];
}

/**
 * Get human-readable status message for HTTP code
 */
function getStatusMessage($code)
{
    $messages = [
        200 => 'OK',
        301 => 'Moved Permanently',
        302 => 'Found',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];
    return $messages[$code] ?? "HTTP $code";
}

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
