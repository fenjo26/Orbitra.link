<?php
// core/backorder.php
// Lightweight RDAP-based domain availability checks with an optional IANA bootstrap cache.

/**
 * Normalize input into a bare domain name.
 * Accepts lines like "https://www.Example.com/path" -> "example.com".
 */
function orbitraBackorderNormalizeDomain(string $raw): string
{
    $s = trim($raw);
    if ($s === '') {
        return '';
    }

    // Take first token (bulk pastes sometimes include extra columns).
    $parts = preg_split('/\s+/', $s);
    $s = $parts[0] ?? '';

    // Strip scheme.
    $s = preg_replace('#^https?://#i', '', $s);

    // Strip path/query/fragment.
    $s = preg_split('#[/?#]#', $s)[0] ?? $s;

    // Strip port.
    $s = preg_split('#:#', $s)[0] ?? $s;

    $s = strtolower(trim($s, " \t\n\r\0\x0B."));
    if (str_starts_with($s, 'www.')) {
        $s = substr($s, 4);
    }
    return $s;
}

/**
 * Simple ASCII domain validation.
 * Note: intentionally does not attempt IDN conversion to avoid intl dependency.
 */
function orbitraBackorderIsValidDomain(string $domain): bool
{
    if ($domain === '' || strlen($domain) > 253) {
        return false;
    }

    // At least one dot.
    if (strpos($domain, '.') === false) {
        return false;
    }

    // RFC-ish validation for labels: letters/digits/hyphen, no leading/trailing hyphen per label.
    $re = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/i';
    return (bool) preg_match($re, $domain);
}

function orbitraBackorderGetCacheDir(): string
{
    return __DIR__ . '/../var/cache';
}

/**
 * Returns TLD -> RDAP base URL mapping from cached IANA bootstrap.
 * Falls back to a small built-in map if network fetch fails.
 */
function orbitraBackorderGetRdapTldMap(int $ttlSeconds = 604800): array
{
    $cacheDir = orbitraBackorderGetCacheDir();
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }

    $cacheFile = $cacheDir . '/rdap_dns_bootstrap.json';
    $now = time();

    $json = null;
    if (is_readable($cacheFile)) {
        $mtime = filemtime($cacheFile) ?: 0;
        if (($now - $mtime) < $ttlSeconds) {
            $json = @file_get_contents($cacheFile);
        }
    }

    if ($json === null) {
        $url = 'https://data.iana.org/rdap/dns.json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Orbitra-Backorder/0.1',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // curl_close() is deprecated since PHP 8.5 and is a no-op since PHP 8.0.

        if ($code >= 200 && $code < 300 && is_string($body) && $body !== '') {
            $json = $body;
            @file_put_contents($cacheFile, $json);
        }
    }

    $map = [];
    if (is_string($json) && $json !== '') {
        $data = json_decode($json, true);
        if (is_array($data) && isset($data['services']) && is_array($data['services'])) {
            foreach ($data['services'] as $service) {
                $tlds = $service[0] ?? null;
                $urls = $service[1] ?? null;
                if (!is_array($tlds) || !is_array($urls) || empty($urls)) {
                    continue;
                }
                $base = $urls[0] ?? null;
                if (!is_string($base) || $base === '') {
                    continue;
                }
                foreach ($tlds as $tld) {
                    if (is_string($tld) && $tld !== '') {
                        $map[strtolower($tld)] = $base;
                    }
                }
            }
        }
    }

    // Minimal fallback for common gTLDs. Used if cache missing and network blocked.
    if (empty($map)) {
        $map = [
            'com' => 'https://rdap.verisign.com/com/v1/',
            'net' => 'https://rdap.verisign.com/net/v1/',
            'name' => 'https://rdap.verisign.com/name/v1/',
            // org/info/biz etc vary by registry; keep those to IANA bootstrap when possible.
        ];
    }

    return $map;
}

function orbitraBackorderExtractTld(string $domain): string
{
    $pos = strrpos($domain, '.');
    if ($pos === false) {
        return '';
    }
    return strtolower(substr($domain, $pos + 1));
}

function orbitraBackorderBuildRdapUrl(string $base, string $domain): string
{
    $base = rtrim($base, '/') . '/';
    return $base . 'domain/' . rawurlencode($domain);
}

/**
 * Perform an RDAP check and return a structured result.
 *
 * @return array{status:string,http_code:int,rdap_url:?string,error:?string,result_json:?string}
 */
function orbitraBackorderRdapCheck(string $domain, int $timeoutSeconds = 10): array
{
    if (!orbitraBackorderIsValidDomain($domain)) {
        return [
            'status' => 'error',
            'http_code' => 0,
            'rdap_url' => null,
            'error' => 'Invalid domain format',
            'result_json' => null,
        ];
    }

    $tld = orbitraBackorderExtractTld($domain);
    $map = orbitraBackorderGetRdapTldMap();
    $base = $map[$tld] ?? null;
    if (!is_string($base) || $base === '') {
        return [
            'status' => 'unsupported',
            'http_code' => 0,
            'rdap_url' => null,
            'error' => 'No RDAP service found for this TLD',
            'result_json' => null,
        ];
    }

    $url = orbitraBackorderBuildRdapUrl($base, $domain);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'Orbitra-Backorder/0.1',
        CURLOPT_HTTPHEADER => [
            'Accept: application/rdap+json, application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $curlErr = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    // curl_close() is deprecated since PHP 8.5 and is a no-op since PHP 8.0.

    if ($body === false) {
        return [
            'status' => 'error',
            'http_code' => 0,
            'rdap_url' => $url,
            'error' => $curlErr ?: 'RDAP request failed',
            'result_json' => null,
        ];
    }

    $bodyStr = is_string($body) ? $body : '';
    $bodyTrim = trim($bodyStr);
    $resultJson = $bodyTrim !== '' ? $bodyTrim : null;

    if ($code === 404) {
        return [
            'status' => 'available',
            'http_code' => $code,
            'rdap_url' => $url,
            'error' => null,
            'result_json' => $resultJson,
        ];
    }

    if ($code === 200) {
        return [
            'status' => 'registered',
            'http_code' => $code,
            'rdap_url' => $url,
            'error' => null,
            'result_json' => $resultJson,
        ];
    }

    if ($code === 429) {
        return [
            'status' => 'rate_limited',
            'http_code' => $code,
            'rdap_url' => $url,
            'error' => 'RDAP rate limited (HTTP 429)',
            'result_json' => $resultJson,
        ];
    }

    return [
        'status' => 'error',
        'http_code' => $code,
        'rdap_url' => $url,
        'error' => 'Unexpected RDAP response (HTTP ' . $code . ')',
        'result_json' => $resultJson,
    ];
}
