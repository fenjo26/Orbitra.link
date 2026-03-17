#!/usr/bin/env php
<?php
// DNS Debug Script for Orbitra
// Run: php check_dns_debug.php

echo "=== Orbitra DNS Debug ===\n\n";

// Get server IP - try multiple methods
$serverIp = 'unknown';

// Method 1: Try to get from external service
$publicIp = @file_get_contents('http://169.254.169.254/latest/meta-data/public-ipv4'); // AWS
if (!$publicIp) {
    $publicIp = @file_get_contents('http://checkip.amazonaws.com');
}
if ($publicIp && filter_var($publicIp, FILTER_VALIDATE_IP)) {
    $serverIp = trim($publicIp);
}

// Method 2: Try hostname resolution
if ($serverIp === 'unknown') {
    $hostname = @gethostname();
    if ($hostname) {
        $ip = @gethostbyname($hostname);
        if ($ip && $ip !== $hostname && filter_var($ip, FILTER_VALIDATE_IP)) {
            $serverIp = $ip;
        }
    }
}

// Method 3: Try network interfaces
if ($serverIp === 'unknown') {
    $ips = @gethostbynamel(gethostname());
    if ($ips && count($ips) > 0) {
        foreach ($ips as $ip) {
            if ($ip !== '127.0.0.1' && filter_var($ip, FILTER_VALIDATE_IP)) {
                $serverIp = $ip;
                break;
            }
        }
    }
}

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

// Try multiple methods to call the API
$apiUrl = 'http://localhost/api.php?action=force_check_all_dns';
echo "Calling: $apiUrl\n";

// Method 1: Try file_get_contents
$context = stream_context_create([
    'http' => [
        'timeout' => 120,
        'header' => "Host: orbitra.link\r\n"
    ]
]);

$result = @file_get_contents($apiUrl, false, $context);

if ($result === false) {
    echo "file_get_contents failed, trying curl...\n";

    // Method 2: Try curl
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: orbitra.link']);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false || $httpCode !== 200) {
        echo "curl also failed. HTTP code: $httpCode\n";
        echo "Trying direct database check instead...\n\n";

        // Method 3: Check database directly
        try {
            require_once '/var/www/orbitra/config.php';
            $stmt = $pdo->query("SELECT name, dns_status, dns_ip FROM domains WHERE name LIKE '%orbitra.%'");
            $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo "Direct database check:\n";
            foreach ($domains as $domain) {
                echo "  {$domain['name']}: status={$domain['dns_status']}, ip={$domain['dns_ip']}\n";
            }
        } catch (Exception $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
        }
    } else {
        echo "Response:\n$result\n";
    }
} else {
    echo "Response:\n$result\n";
}
