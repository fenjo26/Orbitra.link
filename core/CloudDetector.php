<?php
/**
 * CloudDetector - Detect if a domain is proxied through Cloudflare.
 *
 * Used by SSL manager to skip Certbot for Cloudflare-proxied domains,
 * since Certbot cannot validate through the Cloudflare edge.
 */
class CloudDetector
{
    /**
     * Cloudflare IPv4 ranges (current as of 2025).
     * Source: https://www.cloudflare.com/ips/
     */
    private const CLOUDFLARE_IPV4_RANGES = [
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

    /**
     * Cloudflare IPv6 ranges (current as of 2025).
     */
    private const CLOUDFLARE_IPV6_RANGES = [
        '2606:4700::/32',
        '2803:f800::/32',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2c0f:f248::/32',
        '2a06:98c0::/29',
    ];

    /**
     * Check if an IP is within Cloudflare's ranges.
     */
    public static function isCloudflareIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $isV6 = strpos($ip, ':') !== false;
        $ranges = $isV6 ? self::CLOUDFLARE_IPV6_RANGES : self::CLOUDFLARE_IPV4_RANGES;

        foreach ($ranges as $range) {
            if (self::ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP is within a CIDR range.
     */
    private static function ipInRange(string $ip, string $range): bool
    {
        [$range, $netmask] = explode('/', $range, 2);
        $netmask = (int) $netmask;

        if (strpos($ip, ':') !== false) {
            // IPv6
            $ipBin = self::ipv6ToBin($ip);
            $rangeBin = self::ipv6ToBin($range);
            if ($ipBin === false || $rangeBin === false) {
                return false;
            }

            $ipInt = gmp_import($ipBin);
            $rangeInt = gmp_import($rangeBin);
            $mask = gmp_init(str_pad(str_repeat('1', $netmask), 128, '0'), 2);

            return gmp_and($ipInt, $mask)->equals(gmp_and($rangeInt, $mask));
        } else {
            // IPv4
            $ipLong = ip2long($ip);
            $rangeLong = ip2long($range);
            if ($ipLong === false || $rangeLong === false) {
                return false;
            }

            $mask = -1 << (32 - $netmask);
            return ($ipLong & $mask) === ($rangeLong & $mask);
        }
    }

    /**
     * Convert IPv6 address to binary string.
     */
    private static function ipv6ToBin(string $ip): false|string
    {
        if (!function_exists('inet_pton')) {
            return false;
        }

        $bytes = inet_pton($ip);
        if ($bytes === false) {
            return false;
        }

        return $bytes;
    }

    /**
     * Detect if a domain is proxied through Cloudflare.
     *
     * Checks DNS records to see if the domain resolves to Cloudflare IPs.
     *
     * @param string $domain The domain to check
     * @return bool True if domain appears to be Cloudflare-proxied
     */
    public static function isCloudflareProxied(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
            return false;
        }

        // Check A records
        $aRecords = @dns_get_record($domain, DNS_A);
        if (is_array($aRecords)) {
            foreach ($aRecords as $record) {
                $ip = $record['ip'] ?? '';
                if ($ip !== '' && self::isCloudflareIp($ip)) {
                    return true;
                }
            }
        }

        // Check AAAA records (IPv6)
        $aaaaRecords = @dns_get_record($domain, DNS_AAAA);
        if (is_array($aaaaRecords)) {
            foreach ($aaaaRecords as $record) {
                $ip = $record['ipv6'] ?? '';
                if ($ip !== '' && self::isCloudflareIp($ip)) {
                    return true;
                }
            }
        }

        // If DNS lookup fails, try HTTP check as fallback
        // Cloudflare sets specific headers when proxied
        return self::checkByHttpHeaders($domain);
    }

    /**
     * Check if domain uses Cloudflare via HTTP headers.
     *
     * This is a fallback method when DNS lookup fails or is inconclusive.
     * Note: This only works if the domain is already pointing to the tracker.
     */
    private static function checkByHttpHeaders(string $domain): bool
    {
        $url = 'https://' . $domain;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        // A host with a broken IPv6 route burns the whole timeout budget on a
        // stalled AAAA connect before IPv4 is ever reached.
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        // Only disable SSL verification in local development environment
        $isLocalEnv = isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'local'
                    || isset($_ENV['ENVIRONMENT']) && $_ENV['ENVIRONMENT'] === 'development'
                    || isset($_ENV['ENVIRONMENT']) && $_ENV['ENVIRONMENT'] === 'local';

        if ($isLocalEnv) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if (!is_string($response)) {
            return false;
        }

        $headers = substr($response, 0, $headerSize);
        $cloudflareHeaders = [
            'cf-ray:',
            'server: cloudflare',
        ];

        foreach ($cloudflareHeaders as $header) {
            if (stripos($headers, $header) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Auto-detect and update Cloudflare proxy status for a domain.
     *
     * @param PDO $pdo Database connection
     * @param int $domainId Domain ID
     * @return array{detected: bool, status: string, message: string}
     */
    public static function updateDomainStatus(PDO $pdo, int $domainId): array
    {
        try {
            $stmt = $pdo->prepare("SELECT name, cloudflare_proxy FROM domains WHERE id = ? LIMIT 1");
            $stmt->execute([$domainId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return ['detected' => false, 'status' => 'error', 'message' => 'Domain not found'];
            }

            $domain = $row['name'] ?? '';
            $currentFlag = (int) ($row['cloudflare_proxy'] ?? 0);

            // If manually set, respect the manual flag
            if ($currentFlag === 1) {
                return ['detected' => true, 'status' => 'cloudflare', 'message' => 'Manually set as Cloudflare'];
            }

            // Auto-detect
            $isProxied = self::isCloudflareProxied($domain);

            if ($isProxied) {
                // Update database to mark as Cloudflare
                $pdo->prepare("UPDATE domains SET cloudflare_proxy = 1, ssl_status = 'cloudflare' WHERE id = ?")
                    ->execute([$domainId]);
                return ['detected' => true, 'status' => 'cloudflare', 'message' => 'Cloudflare detected via DNS'];
            }

            return ['detected' => false, 'status' => 'direct', 'message' => 'Not proxied through Cloudflare'];
        } catch (\Throwable $e) {
            return ['detected' => false, 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Batch update Cloudflare status for all domains.
     *
     * @param PDO $pdo Database connection
     * @return array{updated: int, cloudflare: int, errors: int}
     */
    public static function updateAllDomains(PDO $pdo): array
    {
        $result = ['updated' => 0, 'cloudflare' => 0, 'errors' => 0];

        try {
            $stmt = $pdo->query("SELECT id, name, cloudflare_proxy FROM domains WHERE name IS NOT NULL AND name != ''");
            $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($domains as $row) {
                $id = (int) ($row['id'] ?? 0);
                $currentFlag = (int) ($row['cloudflare_proxy'] ?? 0);

                // Skip manually set flags
                if ($currentFlag === 1) {
                    $result['cloudflare']++;
                    continue;
                }

                $domain = $row['name'] ?? '';
                if ($domain === '' || self::isCloudflareProxied($domain)) {
                    try {
                        $pdo->prepare("UPDATE domains SET cloudflare_proxy = 1, ssl_status = 'cloudflare' WHERE id = ?")
                            ->execute([$id]);
                        $result['updated']++;
                        $result['cloudflare']++;
                    } catch (\Throwable $e) {
                        $result['errors']++;
                    }
                }
            }
        } catch (\Throwable $e) {
            $result['errors']++;
        }

        return $result;
    }
}
