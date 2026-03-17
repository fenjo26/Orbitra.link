#!/usr/bin/env php
<?php
// DNS Debug Script for Orbitra
// Run: php check_dns_debug.php

echo "=== Orbitra DNS Debug ===\n\n";

// Get server IP
$serverIp = $_SERVER['SERVER_ADDR'] ?? 'unknown';
echo "Server IP: $serverIp\n\n";

// Domains to check
$domains = ['orbitra.net.ru', 'orbitra.pp.ru', 'orbitra.org.ru'];

foreach ($domains as $domain) {
    echo "Checking: $domain\n";

    // DNS lookup
    $domainIp = gethostbyname($domain);
    echo "  Resolves to: $domainIp\n";

    // Comparison
    if ($domainIp === $serverIp) {
        echo "  Status: ACTIVE ✓\n";
    } elseif ($domainIp === '127.0.0.1' || $serverIp === '127.0.0.1') {
        echo "  Status: ACTIVE (localhost) ✓\n";
    } elseif ($domainIp === $domain) {
        echo "  Status: PENDING ✗ (DNS not resolving)\n";
    } else {
        echo "  Status: PENDING ✗ (IP mismatch: expected $serverIp)\n";
    }
    echo "\n";
}

echo "\n=== Testing force_check_all_dns endpoint ===\n";

// Test the endpoint
$apiUrl = 'http://localhost/api.php?action=force_check_all_dns';
echo "Calling: $apiUrl\n";

$context = stream_context_create([
    'http' => [
        'timeout' => 120
    ]
]);

$result = @file_get_contents($apiUrl, false, $context);

if ($result === false) {
    echo "ERROR: Failed to call endpoint\n";
} else {
    echo "Response:\n$result\n";
}
