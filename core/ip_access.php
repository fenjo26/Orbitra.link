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

        // Single IP. An IPv6 host address is a /128, not a /32.
        if (filter_var($line, FILTER_VALIDATE_IP) !== false) {
            $result['rules'][] = ['allow' => $isAllow, 'ip' => $line, 'mask' => strpos($line, ':') !== false ? 128 : 32];
            continue;
        }

        // CIDR notation. The prefix ceiling follows the family: 32 for IPv4,
        // 128 for IPv6.
        if (strpos($line, '/') !== false) {
            $parts = explode('/', $line, 2);
            $ip = $parts[0] ?? '';
            $mask = (int)($parts[1] ?? 32);
            $maxMask = strpos($ip, ':') !== false ? 128 : 32;
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false && $mask >= 0 && $mask <= $maxMask) {
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
 *
 * Works for IPv4 and IPv6. ip2long() could not be used here — it is IPv4-only
 * and returns false for every v6 address, so v6 entries never matched. A
 * v4-mapped v6 address (::ffff:a.b.c.d) is compared as its IPv4 self, so a
 * stored v4 rule keeps matching visitors that arrive over a dual-stack
 * socket. Signature unchanged: ($ip, $range, $prefixBits).
 */
function orbitraIpMatchesRange(string $ip, string $range, int $mask): bool
{
    $ipBin = @inet_pton($ip);
    $rangeBin = @inet_pton($range);
    if ($ipBin === false || $rangeBin === false) {
        return false;
    }

    // Unmap ::ffff:a.b.c.d down to a.b.c.d so both sides speak one family.
    $v4Mapped = str_repeat("\x00", 10) . "\xff\xff";
    if (strlen($ipBin) === 16 && substr($ipBin, 0, 12) === $v4Mapped) {
        $ipBin = substr($ipBin, 12);
    }
    if (strlen($rangeBin) === 16 && substr($rangeBin, 0, 12) === $v4Mapped) {
        $rangeBin = substr($rangeBin, 12);
    }

    // An IPv4 rule never matches an IPv6 address and vice versa.
    if (strlen($ipBin) !== strlen($rangeBin)) {
        return false;
    }

    $bits = strlen($ipBin) * 8;
    if ($mask <= 0) {
        return true; // /0 spans the whole family
    }
    if ($mask >= $bits) {
        return $ipBin === $rangeBin;
    }

    $fullBytes = intdiv($mask, 8);
    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($rangeBin, 0, $fullBytes)) {
        return false;
    }
    $remBits = $mask % 8;
    if ($remBits === 0) {
        return true;
    }
    $highBits = (0xff << (8 - $remBits)) & 0xff;
    return ((ord($ipBin[$fullBytes]) ^ ord($rangeBin[$fullBytes])) & $highBits) === 0;
}

/**
 * Test if an IP is in a list of parsed rules.
 * Returns true if allowed (no matching deny rule, or matching allow rule).
 *
 * Last matching rule wins. The default depends on what the list contains:
 * deny-only lists stay open-by-default (a deny rule that does not match
 * cannot narrow anything), but the moment an allow rule is present the list
 * is a whitelist and it fails CLOSED — an address that matches nothing is
 * refused, because an allow list that admitted strangers would be decoration.
 */
function orbitraIpInList(string $ip, array $rules): bool
{
    $hasAllow = false;
    foreach ($rules as $rule) {
        if (!empty($rule['allow'])) {
            $hasAllow = true;
            break;
        }
    }

    $ip = trim($ip);
    if ($ip === '' || $ip === '0.0.0.0') {
        // Local/unspecified passes, but only while the list imposes no allow
        // constraint: with a whitelist in force, 0.0.0.0 is not on it.
        return !$hasAllow;
    }

    $allowed = !$hasAllow; // Default: open for deny lists, closed for whitelists
    $hasMatch = false;

    foreach ($rules as $rule) {
        if (!orbitraIpMatchesRange($ip, $rule['ip'], $rule['mask'])) {
            continue;
        }

        $hasMatch = true;
        $allowed = $rule['allow'];
    }

    return $hasMatch ? $allowed : !$hasAllow;
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
 * This differs from orbitraRequestClientIp(), which is for admin access: there
 * the forwarded headers are consulted only when the request demonstrably came
 * through a proxy the operator deployed — a Cloudflare edge or a private-
 * address hop — and not from a direct visitor who set them by hand.
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
 * ranges — an access list wants the peer the operator actually sees. REMOTE_ADDR
 * is the answer unless the request can only have arrived through a proxy the
 * operator put there themselves: a Cloudflare edge, or a reverse proxy on a
 * private/reserved address. Only then are CF-Connecting-IP and the leftmost
 * X-Forwarded-For consulted — a direct visitor on a public address controls
 * both headers freely, so trusting them there would let anyone rename their
 * IP for the access check.
 */
function orbitraRequestClientIp(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Trusted peer: Cloudflare, or the operator's own proxy on private/
    // reserved space (which includes the CLI/test default 0.0.0.0).
    $trustedPeer = orbitraIpIsCloudflare($remote)
        || filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

    if ($trustedPeer) {
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
