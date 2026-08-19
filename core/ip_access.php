<?php
/**
 * Orbitra IP Access Control
 *
 * Provides secure IP resolution for click tracking and admin access control.
 * - orbitraClientIp(): Extracts client IP for click tracking, with Cloudflare
 *   range validation and protection against spoofable headers.
 * - orbitraAdminIpAllowed(): Validates admin panel access against IP allowlist.
 */

/**
 * Cloudflare IPv4 CIDR ranges (2025-01). Parsed from:
 * https://www.cloudflare.com/ips-v4
 */
return [
    '173.245.48.0/20',
    '103.21.244.0/22',
    '103.22.200.0/22',
    '103.31.4.0/22',
    '141.101.64.0/18',
    '108.162.192.0/18',
    '190.93.240.0/20',
    '188.114.96.0/20',
    '197.234.240.0/22',
    '198.41.128.0/17',
    '162.158.0.0/15',
    '104.16.0.0/13',
    '104.24.0.0/14',
    '172.64.0.0/13',
    '131.0.72.0/22',
    '2400:cb00::/32',
    '2606:4700::/32',
    '2803:f800::/32',
    '2405:b500::/32',
    '2605:8100::/32',
    '2610:a0::/28',
    '2620:11a::/28',
    '2a06:98c0::/29',
    '2c0f:f248::/32',
];

function orbitraParseIpAccess(string $value): array
{
    $result = ['rules' => [], 'errors' => []];
    if (trim($value) === '') {
        return $result;
    }

    $lines = explode("\n", $value);
    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $isAllow = true;
        if (stripos($line, 'deny ') === 0) {
            $isAllow = false;
            $line = substr($line, 5);
        } elseif (stripos($line, 'allow ') === 0) {
            $line = substr($line, 6);
        }

        $line = trim($line);
        if ($line === '') {
            continue;
        }

        // Single IP
        if (filter_var($line, FILTER_VALIDATE_IP) !== false) {
            $result['rules'][] = ['allow' => $isAllow, 'ip' => $line, 'mask' => 32];
            continue;
        }

        // CIDR notation
        if (strpos($line, '/') !== false) {
            $parts = explode('/', $line, 2);
            $ip = $parts[0] ?? '';
            $mask = (int)($parts[1] ?? 32);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false && $mask >= 0 && $mask <= 32) {
                $result['rules'][] = ['allow' => $isAllow, 'ip' => $ip, 'mask' => $mask];
                continue;
            }
        }

        $result['errors'][] = "Line " . ($lineNum + 1) . ": invalid entry '{$line}'";
    }

    return $result;
}

/**
 * Test if an IP matches a CIDR range.
 */
function orbitraIpMatchesRange(string $ip, string $range, int $mask): bool
{
    $ipDec = ip2long($ip);
    $rangeDec = ip2long($range);
    if ($ipDec === false || $rangeDec === false) {
        return false;
    }

    $maskDec = -1 << (32 - $mask);
    return ($ipDec & $maskDec) === ($rangeDec & $maskDec);
}

/**
 * Test if an IP is in a list of parsed rules.
 * Returns true if allowed (no matching deny rule, or matching allow rule).
 */
function orbitraIpInList(string $ip, array $rules): bool
{
    $ip = trim($ip);
    if ($ip === '' || $ip === '0.0.0.0') {
        return true; // Local/unspecified passes
    }

    $allowed = true; // Default: allow unless denied
    $hasMatch = false;

    foreach ($rules as $rule) {
        if (!orbitraIpMatchesRange($ip, $rule['ip'], $rule['mask'])) {
            continue;
        }

        $hasMatch = true;
        $allowed = $rule['allow'];
    }

    return $hasMatch ? $allowed : true;
}

/**
 * Cloudflare IPv4 CIDR ranges (2025-01).
 */
function orbitraCloudflareRanges(): array
{
    return [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];
}

/**
 * Check if an IP is in a Cloudflare range.
 */
function orbitraIpIsCloudflare(string $ip): bool
{
    $ranges = orbitraCloudflareRanges();
    foreach ($ranges as $range) {
        $parts = explode('/', $range, 2);
        if (count($parts) === 2 && orbitraIpMatchesRange($ip, $parts[0], (int)$parts[1])) {
            return true;
        }
    }
    return false;
}

/**
 * Get the client IP for click tracking.
 *
 * Security model:
 * - REMOTE_ADDR is the peer we see (may be Cloudflare, reverse proxy, or direct).
 * - Cloudflare CF-Connecting-IP is trusted ONLY when REMOTE_ADDR is in CF ranges.
 * - X-Forwarded-For is checked as a fallback (leftmost IP, validated as public).
 * - HTTP_CLIENT_IP is NEVER checked (easily spoofed).
 *
 * This differs from orbitraRequestClientIp() which is for admin access where
 * the operator controls the proxy stack and trusts X-Forwarded-For unconditionally.
 */
function orbitraClientIp(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Trust CF-Connecting-IP only when REMOTE_ADDR is in Cloudflare ranges
    $cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cfIp !== '' && orbitraIpIsCloudflare($remote)) {
        $candidate = trim(explode(',', $cfIp)[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return $candidate;
        }
    }

    // Fallback: X-Forwarded-For (leftmost IP only, reject private ranges)
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $candidate = trim(explode(',', $xff)[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return $candidate;
        }
    }

    // REMOTE_ADDR is the fallback (may be the visitor's real IP or a proxy)
    return $remote;
}

/**
 * Get the client IP for admin access control.
 *
 * Unlike index.php:getClientIp() — which walks forwarded headers to recover a
 * visitor's "real" IP for geo/cloak purposes and intentionally drops private
 * ranges — an access list wants the peer the operator actually sees. We trust
 * REMOTE_ADDR first; behind Cloudflare or a reverse proxy we prefer
 * CF-Connecting-IP then the leftmost X-Forwarded-For, since the operator is the
 * one who put the proxy in front and knows what it does.
 */
function orbitraRequestClientIp(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cfIp !== '') {
        $candidate = trim(explode(',', $cfIp)[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return $candidate;
        }
    }

    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $candidate = trim(explode(',', $xff)[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return $candidate;
        }
    }

    return $remote;
}

/**
 * Is the current client allowed to reach the admin surface?
 *
 * Empty or '0' => open to everyone (the default, unchanged behaviour). A
 * populated list => only the parsed entries. On any DB error we fail open so a
 * flaky database never locks the operator out of their own panel.
 */
function orbitraAdminIpAllowed(PDO $pdo, ?string $remoteIp = null): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $value = '';
    try {
        $row = $pdo->query("SELECT value FROM settings WHERE key = 'admin_ip_access' LIMIT 1")->fetchColumn();
        if (is_string($row)) {
            $value = $row;
        }
    } catch (\Throwable $e) {
        $cached = true;
        return true;
    }

    if ($value === '' || $value === '0') {
        $cached = true;
        return true;
    }

    $ip = $remoteIp ?? orbitraRequestClientIp();
    $parsed = orbitraParseIpAccess($value);
    if (empty($parsed['rules'])) {
        // Every entry failed to parse: refuse rather than fall open, otherwise a
        // typo in the only allowed address would silently widen access.
        $cached = false;
        return false;
    }

    $cached = orbitraIpInList($ip, $parsed['rules']);
    return $cached;
}
