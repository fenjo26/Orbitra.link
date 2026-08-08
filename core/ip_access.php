<?php
/**
 * Admin panel IP access list.
 *
 * The SystemSettings panel exposes an "admin_ip_access" textarea that, before
 * this file existed, the UI advertised as access control but the backend never
 * read — any value was silently discarded. An empty (or '0') value keeps the
 * panel open to everyone (backward compatible); a populated list restricts
 * admin.php and the authenticated api.php surface to the listed addresses.
 *
 * This is defence in depth on top of the password and the secret admin path,
 * not a replacement for either. It runs as early as possible in the request so
 * that an unlisted client never reaches a login form or a session.
 */

/**
 * Parse a free-form IP/CIDR list into structured rules.
 *
 * Accepts one entry per line; commas and whitespace also separate entries, so
 * "1.2.3.4, 10.0.0.0/8\n2001:db8::/32" parses as three rules. Comments (lines
 * starting with '#') and blank lines are ignored.
 *
 * @return array{rules: array<int, array{raw: string, type: string, ip: string, prefix: ?int, bin: string}>, errors: array<int, string>}
 *   `type` is 'ipv4' or 'ipv6'; `bin` is the packed 16/16-byte form used for
 *   range matching; `prefix` is null for a bare address (treated as /32 or /128).
 */
function orbitraParseIpAccess(string $raw): array
{
    $rules = [];
    $errors = [];

    // Normalise separators: any run of commas / whitespace becomes a newline.
    $normalised = preg_replace('/[,\s]+/', "\n", trim($raw));
    if ($normalised === '' || $normalised === null) {
        return ['rules' => [], 'errors' => []];
    }

    foreach (explode("\n", $normalised) as $entry) {
        $entry = trim($entry);
        if ($entry === '' || $entry[0] === '#') {
            continue;
        }

        $prefix = null;
        $ipPart = $entry;
        if (strpos($entry, '/') !== false) {
            [$ipPart, $maskPart] = explode('/', $entry, 2);
            $prefix = (int) $maskPart;
            // A non-numeric mask (e.g. "1.2.3.4/foo") makes $prefix 0, caught below.
            if (!ctype_digit((string) $maskPart)) {
                $errors[] = $entry;
                continue;
            }
        }

        $packed = @inet_pton($ipPart);
        if ($packed === false) {
            $errors[] = $entry;
            continue;
        }

        $type = strlen($packed) === 4 ? 'ipv4' : 'ipv6';
        $maxPrefix = $type === 'ipv4' ? 32 : 128;

        if ($prefix !== null && ($prefix < 0 || $prefix > $maxPrefix)) {
            $errors[] = $entry;
            continue;
        }

        // inet_pton returns 16 bytes for IPv6 but also 16 bytes for an IPv4-mapped
        // address (::ffff:1.2.3.4). Normalise both representations to the same key
        // so a v4 rule and a mapped-v6 address match consistently.
        if ($type === 'ipv6' && substr($packed, 0, 10) === "\0\0\0\0\0\0\0\0\0\0"
            && (substr($packed, 10, 2) === "\0\0" || substr($packed, 10, 2) === "\xff\xff")) {
            $v4Tail = substr($packed, 12, 4);
            if ($v4Tail !== false && $v4Tail !== "\0\0\0\0") {
                $packed = str_repeat("\0", 12) . "\xff\xff" . $v4Tail;
            }
        }

        $rules[] = [
            'raw' => $entry,
            'type' => $type,
            'ip' => $ipPart,
            'prefix' => $prefix,
            'bin' => $packed,
        ];
    }

    return ['rules' => $rules, 'errors' => $errors];
}

/**
 * Does $ip fall inside any of the parsed rules?
 *
 * IPv4 addresses are matched against IPv4 rules only, and v6 against v6, so a
 * v4 whitelist cannot be bypassed by connecting over v6 and vice versa.
 */
function orbitraIpInList(string $ip, array $rules): bool
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return false;
    }
    $ipType = strlen($packed) === 4 ? 'ipv4' : 'ipv6';

    foreach ($rules as $rule) {
        if ($rule['type'] !== $ipType) {
            continue;
        }

        $prefix = $rule['prefix'];
        if ($prefix === null) {
            $maxPrefix = $ipType === 'ipv4' ? 32 : 128;
            $prefix = $maxPrefix;
        }

        if ($prefix === 0) {
            // /0 matches everything in its family.
            return true;
        }

        // Compare the leading $prefix bits of the packed forms. IPv4 is 4 bytes;
        // inet_pton gives exactly that, so the byte-level comparison is correct.
        $wholeBytes = intdiv($prefix, 8);
        $remainderBits = $prefix % 8;

        if ($wholeBytes > 0 && substr($packed, 0, $wholeBytes) !== substr($rule['bin'], 0, $wholeBytes)) {
            continue;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $a = ord($packed[$wholeBytes]);
        $b = ord($rule['bin'][$wholeBytes]);
        $mask = ~(0xFF >> $remainderBits) & 0xFF;
        if (($a & $mask) === ($b & $mask)) {
            return true;
        }
    }

    return false;
}

/**
 * Best-effort connecting IP for the access list.
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
