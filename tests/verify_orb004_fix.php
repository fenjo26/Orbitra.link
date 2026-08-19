<?php
/**
 * ORB-004 Verification Script
 *
 * Use this to verify the Cloudflare DNS fix with a real domain.
 * Usage: php tests/verify_orb004_fix.php
 *
 * This script:
 * 1. Lists all domains with their DNS status and reason
 * 2. Shows which domains are detected as Cloudflare
 * 3. Can be used to verify a specific Cloudflare-proxied domain
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/DomainDnsResolver.php';
require_once __DIR__ . '/../core/CloudDetector.php';

echo "\n=== ORB-004 Cloudflare DNS Fix Verification ===\n\n";

// Get the server IP
$serverIp = '127.0.0.1';
if (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '') {
    $serverIp = $_SERVER['SERVER_ADDR'];
} elseif (isset($_SERVER['HTTP_HOST'])) {
    $hostname = explode(':', $_SERVER['HTTP_HOST'])[0];
    $hostIp = @gethostbyname($hostname);
    if ($hostIp !== $hostname) {
        $serverIp = $hostIp;
    }
}
echo "Server IP: $serverIp\n\n";

// Get all domains
$stmt = $pdo->query("SELECT id, name, cloudflare_proxy, dns_status, dns_reason, dns_ip FROM domains ORDER BY name ASC");
$domains = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($domains)) {
    echo "No domains found in database.\n";
    echo "Add a domain and test DNS resolution to verify the fix.\n";
    exit(0);
}

echo "Domains: " . count($domains) . "\n\n";

printf("%-30s %-15s %-20s %-20s\n", 'Domain', 'DNS Status', 'DNS Reason', 'Resolved IP');
echo str_repeat('-', 90) . "\n";

foreach ($domains as $domain) {
    $name = $domain['name'] ?? '';
    $status = $domain['dns_status'] ?? 'unknown';
    $reason = $domain['dns_reason'] ?? '';
    $ip = $domain['dns_ip'] ?? '';

    // Highlight Cloudflare domains
    if ($reason === 'cloudflare') {
        $name = "☁️  $name";
    }

    printf("%-30s %-15s %-20s %-20s\n", substr($name, 0, 30), $status, $reason, $ip);
}

echo "\n";
echo "Legend:\n";
echo "  ☁️  = Cloudflare proxied domain\n";
echo "  direct = Direct connection to origin\n";
echo "  cloudflare = Resolves to Cloudflare edge IP\n";
echo "  local = Localhost environment\n";
echo "  no_resolve = Domain does not resolve\n";
echo "  wrong_ip:X.X.X.X = Resolves to wrong IP\n\n";

// Check if any Cloudflare domains are not yet flagged
echo "=== Cloudflare Detection Check ===\n\n";
$cfDetected = 0;
$cfNeedsFlag = 0;

foreach ($domains as $domain) {
    $ip = $domain['dns_ip'] ?? '';
    $cfFlag = (int)($domain['cloudflare_proxy'] ?? 0);
    $reason = $domain['dns_reason'] ?? '';

    if (CloudDetector::isCloudflareIp($ip)) {
        $cfDetected++;
        if ($cfFlag !== 1) {
            $cfNeedsFlag++;
            echo "⚠️  {$domain['name']} resolves to Cloudflare IP ($ip) but cloudflare_proxy is not set\n";
        }
    }
}

if ($cfDetected > 0 && $cfNeedsFlag === 0) {
    echo "✓ All Cloudflare domains are properly flagged\n";
} elseif ($cfDetected === 0) {
    echo "ℹ️  No Cloudflare domains detected yet\n";
    echo "   To test ORB-004 fix, add a Cloudflare-proxied domain and run DNS check\n";
}

echo "\n=== Verification Complete ===\n";
