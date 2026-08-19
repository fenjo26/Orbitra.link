<?php
/**
 * DomainDnsResolver - Shared DNS resolution and state determination.
 *
 * Consolidates the DNS checking logic that was duplicated across three
 * api.php handlers (domains, check_domain_dns, force_check_all_dns).
 * Adds Cloudflare-aware detection to resolve ORB-004.
 */
require_once __DIR__ . '/CloudDetector.php';

/**
 * Resolve a domain's DNS state with Cloudflare awareness.
 *
 * @param PDO $pdo Database connection
 * @param array $domain Domain row (must include id, name, cloudflare_proxy, dns_status, dns_reason)
 * @param string $serverIp The tracker's origin server IP
 * @return array{status: string, reason: string, ip: string, cloudflare_proxy: int|null}
 *
 * Status values:
 * - 'active': Domain is reachable (direct or via Cloudflare)
 * - 'pending': DNS check failed or wrong IP
 *
 * Reason values:
 * - 'direct': Direct connection to origin IP
 * - 'cloudflare': Resolves to Cloudflare edge IP
 * - 'local': Localhost environment
 * - 'no_resolve': Domain does not resolve at all
 * - 'wrong_ip:<actual_ip>': Resolves to wrong IP
 */
function orbitraResolveDomainDnsState(PDO $pdo, array $domain, string $serverIp): array
{
    $domainName = $domain['name'] ?? '';
    $domainId = (int) ($domain['id'] ?? 0);
    $currentCloudflareFlag = (int) ($domain['cloudflare_proxy'] ?? 0);

    // Perform DNS lookup
    $domainIp = @gethostbyname($domainName);
    $domainIp = trim($domainIp);
    $serverIp = trim($serverIp);

    $status = 'pending';
    $reason = 'no_resolve';
    $updateCloudflareFlag = null;

    // 1. Resolution failed (domain does not resolve)
    if ($domainIp === $domainName) {
        $status = 'pending';
        $reason = 'no_resolve';
    }
    // 2. Direct match to origin IP
    elseif ($domainIp === $serverIp) {
        $status = 'active';
        $reason = 'direct';
    }
    // 3. Cloudflare edge IP detected
    elseif (CloudDetector::isCloudflareIp($domainIp)) {
        $status = 'active';
        $reason = 'cloudflare';
        // Set cloudflare_proxy flag and ssl_status to cloudflare if not already set
        if ($currentCloudflareFlag !== 1) {
            $updateCloudflareFlag = 1;
            try {
                $pdo->prepare("UPDATE domains SET cloudflare_proxy = 1, ssl_status = 'cloudflare' WHERE id = ?")
                    ->execute([$domainId]);
            } catch (\Throwable $e) {
                // Non-critical: the flag will be set on the next check
            }
        }
    }
    // 4. Localhost environment
    elseif ($domainIp === '127.0.0.1' || $serverIp === '127.0.0.1') {
        $status = 'active';
        $reason = 'local';
    }
    // 5. Wrong IP
    else {
        $status = 'pending';
        $reason = 'wrong_ip:' . $domainIp;
    }

    return [
        'status' => $status,
        'reason' => $reason,
        'ip' => $domainIp,
        'cloudflare_proxy' => $updateCloudflareFlag,
    ];
}
