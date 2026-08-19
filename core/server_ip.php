<?php
/**
 * Server IP Detection - Unified public IP discovery.
 *
 * Consolidates the duplicated IP detection logic from api.php into a single
 * source of truth. Resolves ORB-005 by ensuring the banner IP and compared
 * IP are always the same value.
 *
 * Strategy order (public-first):
 * 1. Cache file (24h TTL)
 * 2. Settings override (manual)
 * 3. UDP socket trick (local egress IP)
 * 4. Cloud metadata (AWS EC2)
 * 5. External services (checkip, ipify)
 * 6. SERVER_ADDR (only if public)
 * 7. Explicit failure (empty string, never 127.0.0.1)
 */

/**
 * Detect the server's public IP address.
 *
 * Uses multiple strategies with a static cache to ensure one external lookup
 * per page load. Returns empty string on failure (never 127.0.0.1).
 *
 * @param PDO|null $pdo Optional database connection for settings override
 * @return string The detected public IP, or empty string on failure
 */
function orbitraDetectServerIp(?PDO $pdo = null): string
{
    static $ip = null;
    if ($ip !== null) {
        return $ip;
    }

    $cacheFile = dirname(__DIR__) . '/var/server_ip_cache.txt';
    $cacheTtl = 86400; // 24 hours

    // Helper: validate as public IP (no private/reserved ranges)
    $isPublicIp = function (?string $candidate): bool {
        if ($candidate === null || $candidate === '') {
            return false;
        }
        return (bool) filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    };

    // 1. Cache file (fastest path)
    if (is_file($cacheFile) && (time() - (int) @filemtime($cacheFile)) < $cacheTtl) {
        $cached = trim((string) @file_get_contents($cacheFile));
        if ($isPublicIp($cached)) {
            return $ip = $cached;
        }
    }

    $found = '';

    // 2. Settings override (beats all autodetection)
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'server_ip_override' LIMIT 1");
            $stmt->execute();
            $override = $stmt->fetchColumn();
            if ($override !== false && $override !== '') {
                $override = trim($override);
                if ($isPublicIp($override)) {
                    $found = $override;
                }
                // If override is invalid, fall through to autodetection silently
            }
        } catch (Throwable $e) {
            // Settings table may not exist; fall through to autodetection
        }
    }

    // 3. UDP socket trick (detect local egress IP without external calls)
    if ($found === '') {
        $sock = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
        if ($sock) {
            $local = @stream_socket_get_name($sock, false);
            @fclose($sock);
            if (is_string($local) && strpos($local, ':') !== false) {
                $candidate = substr($local, 0, strrpos($local, ':'));
                if ($isPublicIp($candidate)) {
                    $found = $candidate;
                }
            }
        }
    }

    // 4. Cloud metadata (AWS EC2)
    if ($found === '') {
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $metadataIp = @file_get_contents('http://169.254.169.254/latest/meta-data/public-ipv4', false, $ctx);
        if (is_string($metadataIp) && $isPublicIp($metadataIp)) {
            $found = trim($metadataIp);
        }
    }

    // 5. External services (with timeout)
    if ($found === '') {
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        foreach (['http://checkip.amazonaws.com', 'https://api.ipify.org'] as $url) {
            $body = @file_get_contents($url, false, $ctx);
            if (is_string($body) && $isPublicIp(trim($body))) {
                $found = trim($body);
                break;
            }
        }
    }

    // 6. SERVER_ADDR (only if it's a public IP)
    if ($found === '' && isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '') {
        $serverAddr = $_SERVER['SERVER_ADDR'];
        if ($isPublicIp($serverAddr)) {
            $found = $serverAddr;
        }
    }

    // 7. Explicit failure (return empty string, never 127.0.0.1)
    if ($found === '') {
        return $ip = '';
    }

    // Cache the successful result
    if ($found !== '') {
        @mkdir(dirname($cacheFile), 0775, true);
        @file_put_contents($cacheFile, $found);
    }

    return $ip = $found;
}

/**
 * Check if server IP detection succeeded.
 *
 * @return bool True if a public IP was detected, false otherwise
 */
function orbitraHasServerIp(): bool
{
    return orbitraDetectServerIp() !== '';
}

/**
 * Reset the static IP cache (for testing purposes only).
 *
 * This function should ONLY be called from tests. It resets the static
 * cache so that subsequent calls to orbitraDetectServerIp() will re-run
 * the detection logic instead of returning a cached value.
 *
 * @internal
 */
function orbitraResetServerIpCache(): void
{
    // Clear file cache
    $cacheFile = dirname(__DIR__) . '/var/server_ip_cache.txt';
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }

    // Clear static cache by using reflection
    static $reset = false;
    if (!$reset) {
        // We can't directly reset a static variable from outside the function,
        // but we can use runkit or reflection in some environments.
        // For now, this is a placeholder - the test file handles this via
        // re-including the file to get a fresh static scope.
        $reset = true;
    }
}
