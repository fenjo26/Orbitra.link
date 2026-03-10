<?php
// core/backorder.php
// Domain availability checks via RDAP (preferred) with WHOIS fallback for unsupported TLDs.

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

function orbitraBackorderGetWhoisTldMap(int $ttlSeconds = 604800): array
{
    $cacheDir = orbitraBackorderGetCacheDir();
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }

    $cacheFile = $cacheDir . '/whois_tld_map.json';
    $now = time();

    if (is_readable($cacheFile)) {
        $mtime = filemtime($cacheFile) ?: 0;
        if (($now - $mtime) < $ttlSeconds) {
            $json = @file_get_contents($cacheFile);
            $data = is_string($json) ? json_decode($json, true) : null;
            if (is_array($data)) {
                return $data;
            }
        }
    }

    return [];
}

function orbitraBackorderSaveWhoisTldMap(array $map): void
{
    $cacheDir = orbitraBackorderGetCacheDir();
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $cacheFile = $cacheDir . '/whois_tld_map.json';
    @file_put_contents($cacheFile, json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Perform a raw WHOIS query (port 43).
 *
 * @return array{ok:bool,error:?string,raw:?string}
 */
function orbitraBackorderWhoisRawQuery(string $server, string $query, int $timeoutSeconds = 10): array
{
    $server = trim($server);
    if ($server === '') {
        return ['ok' => false, 'error' => 'WHOIS server is empty', 'raw' => null];
    }

    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($server, 43, $errno, $errstr, max(1, $timeoutSeconds));
    if (!$fp) {
        return ['ok' => false, 'error' => $errstr ?: ('WHOIS connection failed (' . $errno . ')'), 'raw' => null];
    }

    // Apply read/write timeouts.
    stream_set_timeout($fp, max(1, $timeoutSeconds));
    @fwrite($fp, $query . "\r\n");

    $buf = '';
    while (!feof($fp)) {
        $chunk = fgets($fp, 4096);
        if ($chunk === false) {
            break;
        }
        $buf .= $chunk;
        // Safety: cap to avoid huge responses blowing memory/DB.
        if (strlen($buf) > 200000) {
            break;
        }
    }

    $meta = stream_get_meta_data($fp);
    fclose($fp);

    if (!empty($meta['timed_out'])) {
        return ['ok' => false, 'error' => 'WHOIS timed out', 'raw' => null];
    }

    $raw = trim($buf);
    if ($raw === '') {
        return ['ok' => false, 'error' => 'Empty WHOIS response', 'raw' => null];
    }

    return ['ok' => true, 'error' => null, 'raw' => $raw];
}

function orbitraBackorderDiscoverWhoisServer(string $tld, int $timeoutSeconds = 8): ?string
{
    $tld = strtolower(trim($tld, ". \t\n\r\0\x0B"));
    if ($tld === '') {
        return null;
    }

    // IANA WHOIS meta server returns a record with `whois:` field for many TLDs.
    $res = orbitraBackorderWhoisRawQuery('whois.iana.org', $tld, $timeoutSeconds);
    if (!$res['ok'] || !is_string($res['raw'])) {
        return null;
    }

    if (preg_match('/^whois:\\s*(\\S+)\\s*$/mi', $res['raw'], $m)) {
        $server = strtolower(trim($m[1]));
        return $server !== '' ? $server : null;
    }

    return null;
}

function orbitraBackorderGetWhoisServerForTld(string $tld): ?string
{
    $tld = strtolower(trim($tld, ". \t\n\r\0\x0B"));
    if ($tld === '') {
        return null;
    }

    $map = orbitraBackorderGetWhoisTldMap();
    if (isset($map[$tld]) && is_string($map[$tld]) && $map[$tld] !== '') {
        return $map[$tld];
    }

    $server = orbitraBackorderDiscoverWhoisServer($tld);
    if (is_string($server) && $server !== '') {
        $map[$tld] = $server;
        orbitraBackorderSaveWhoisTldMap($map);
        return $server;
    }

    return null;
}

/**
 * Best-effort interpretation of WHOIS response.
 * Not standardized across registries; keep it conservative.
 */
function orbitraBackorderInterpretWhois(string $raw): string
{
    $s = strtolower($raw);

    // Strong signals of "not found" across many WHOIS servers.
    $notFound = [
        'no match',
        'not found',
        'no entries found',
        'no data found',
        'nothing found',
        'no object found',
        'domain not found',
        'no such domain',
        'status: free',
        'available for registration',
        'is free',
        'is available',
        'no information available about domain name',
        'the domain has not been registered',
    ];

    // Strong signals of "registered".
    $registered = [
        'domain name:',
        'registry domain id:',
        'registrar:',
        'creation date:',
        'created:',
        'paid-till:',
        'expires:',
        'expiry date:',
        'expiration date:',
        'nserver:',
        'name server:',
        'status:',
        'domain:',
    ];

    $hasRegistered = false;
    foreach ($registered as $needle) {
        if (strpos($s, $needle) !== false) {
            $hasRegistered = true;
            break;
        }
    }

    $hasNotFound = false;
    foreach ($notFound as $needle) {
        if (strpos($s, $needle) !== false) {
            $hasNotFound = true;
            break;
        }
    }

    // If both appear, prefer "registered" to avoid false availability.
    if ($hasRegistered) {
        // Some servers include "not found" as part of policy text; registered signals usually mean it's taken.
        return 'registered';
    }
    if ($hasNotFound) {
        return 'available';
    }

    return 'unknown';
}

/**
 * WHOIS-based check (fallback for TLDs without RDAP bootstrap entries).
 *
 * @return array{status:string,http_code:int,rdap_url:?string,error:?string,result_json:?string}
 */
function orbitraBackorderWhoisCheck(string $domain, int $timeoutSeconds = 12): array
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
    $server = orbitraBackorderGetWhoisServerForTld($tld);
    if (!is_string($server) || $server === '') {
        return [
            'status' => 'unsupported',
            'http_code' => 0,
            'rdap_url' => null,
            'error' => 'No WHOIS server found for this TLD',
            'result_json' => null,
        ];
    }

    $res = orbitraBackorderWhoisRawQuery($server, $domain, $timeoutSeconds);
    if (!$res['ok'] || !is_string($res['raw'])) {
        return [
            'status' => 'error',
            'http_code' => 0,
            'rdap_url' => 'whois://' . $server,
            'error' => $res['error'] ?: 'WHOIS request failed',
            'result_json' => null,
        ];
    }

    $status = orbitraBackorderInterpretWhois($res['raw']);
    if ($status === 'unknown') {
        return [
            'status' => 'error',
            'http_code' => 0,
            'rdap_url' => 'whois://' . $server,
            'error' => 'Unrecognized WHOIS response',
            // Keep payload small; this column is TEXT but should not grow unbounded.
            'result_json' => substr($res['raw'], 0, 8192),
        ];
    }

    return [
        'status' => $status,
        'http_code' => 0,
        'rdap_url' => 'whois://' . $server,
        'error' => null,
        'result_json' => substr($res['raw'], 0, 8192),
    ];
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

/**
 * .gr registry provides an official web WHOIS/availability UI (no RDAP and no port-43 WHOIS in many cases).
 * This function performs a minimal form POST against that UI and parses the resulting page.
 *
 * @return array{status:string,http_code:int,rdap_url:?string,error:?string,result_json:?string}
 */
function orbitraBackorderGrWebCheck(string $domain, int $timeoutSeconds = 12): array
{
    $tld = orbitraBackorderExtractTld($domain);
    if (!in_array($tld, ['gr', 'xn--qxam'], true)) {
        return [
            'status' => 'unsupported',
            'http_code' => 0,
            'rdap_url' => null,
            'error' => 'Not a .gr/.ελ domain',
            'result_json' => null,
        ];
    }

    if (!orbitraBackorderIsValidDomain($domain)) {
        return [
            'status' => 'error',
            'http_code' => 0,
            'rdap_url' => null,
            'error' => 'Invalid domain format',
            'result_json' => null,
        ];
    }

    $baseUrl = 'https://grweb.ics.forth.gr';
    $formUrl = $baseUrl . '/public/whois?lang=en';
    // Keep lang=en on results as well (otherwise the UI may default to Greek and parsing becomes unreliable).
    $resultUrl = $baseUrl . '/public/whois?lang=en';
    $postUrl = $baseUrl . '/public/whois/query';

    try {
        $httpVersions = [];
        if (defined('CURL_HTTP_VERSION_2TLS')) {
            $httpVersions[] = CURL_HTTP_VERSION_2TLS;
        }
        $httpVersions[] = CURL_HTTP_VERSION_1_1;

        $lastError = null;
        foreach ($httpVersions as $httpVersion) {
            // Keep a single curl handle so cookies persist in-memory even on PHP versions
            // where curl_close() is a no-op.
            $cookieFile = @tempnam(sys_get_temp_dir(), 'orbitra_grwhois_');
            if (!is_string($cookieFile) || $cookieFile === '') {
                $cookieFile = __DIR__ . '/../var/cache/grwhois_cookie.txt';
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT => 'Orbitra-Backorder/0.2',
                CURLOPT_COOKIEJAR => $cookieFile,
                CURLOPT_COOKIEFILE => $cookieFile,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTP_VERSION => $httpVersion,
                // Some registries return compressed responses. Let curl negotiate + decode.
                CURLOPT_ENCODING => '',
            ]);

            // 1) GET form to obtain CSRF + session cookies
            curl_setopt_array($ch, [
                CURLOPT_URL => $formUrl,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_HTTPHEADER => ['Accept: text/html'],
                CURLOPT_POST => false,
                CURLOPT_HTTPGET => true,
            ]);
            $html = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlErr = curl_error($ch);

            if ($html === false || !is_string($html) || $html === '' || $code < 200 || $code >= 400) {
                $lastError = $curlErr ?: ('GR WHOIS form fetch failed (HTTP ' . $code . ')');
                unset($ch);
                if (is_string($cookieFile) && strpos($cookieFile, sys_get_temp_dir()) === 0) @unlink($cookieFile);
                continue;
            }

            if (!preg_match('/name="_csrf"\\s+value="([^"]+)"/i', $html, $m)) {
                $lastError = 'GR WHOIS: CSRF token not found';
                unset($ch);
                if (is_string($cookieFile) && strpos($cookieFile, sys_get_temp_dir()) === 0) @unlink($cookieFile);
                continue;
            }
            $csrf = $m[1];

            // 2) POST query (it redirects back to /public/whois)
            $postData = http_build_query([
                '_csrf' => $csrf,
                'domain' => $domain,
            ]);

            curl_setopt_array($ch, [
                CURLOPT_URL => $postUrl,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html',
                    'Content-Type: application/x-www-form-urlencoded',
                    'Referer: ' . $formUrl,
                    'Expect:',
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
            ]);
            $body2 = curl_exec($ch);
            $code2 = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlErr2 = curl_error($ch);

            if ($body2 === false) {
                $lastError = $curlErr2 ?: 'GR WHOIS query failed';
                unset($ch);
                if (is_string($cookieFile) && strpos($cookieFile, sys_get_temp_dir()) === 0) @unlink($cookieFile);
                continue;
            }

            if ($code2 === 403) {
                $lastError = 'GR WHOIS blocked request (HTTP 403)';
                unset($ch);
                if (is_string($cookieFile) && strpos($cookieFile, sys_get_temp_dir()) === 0) @unlink($cookieFile);
                continue;
            }

            // 3) GET results page (POST redirects to /public/whois)
            curl_setopt_array($ch, [
                CURLOPT_URL => $resultUrl,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_HTTPHEADER => ['Accept: text/html', 'Referer: ' . $formUrl],
                CURLOPT_POST => false,
                CURLOPT_HTTPGET => true,
            ]);
            $htmlRes = curl_exec($ch);
            $code3 = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlErr3 = curl_error($ch);

            if ($htmlRes === false || !is_string($htmlRes) || $htmlRes === '' || $code3 < 200 || $code3 >= 400) {
                $lastError = $curlErr3 ?: ('GR WHOIS result fetch failed (HTTP ' . $code3 . ')');
                unset($ch);
                if (is_string($cookieFile) && strpos($cookieFile, sys_get_temp_dir()) === 0) @unlink($cookieFile);
                continue;
            }

            // Parse the alert message rendered after query.
            $msg = null;
            if (preg_match('/<div\\s+role=\"alert\"[^>]*>.*?<strong>\\s*([^<]+)\\s*<\\/strong>/is', $htmlRes, $mm)) {
                $msg = trim(html_entity_decode($mm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            } elseif (preg_match('/<strong>\\s*([^<]+)\\s*<\\/strong>/i', $htmlRes, $mm)) {
                $msg = trim(html_entity_decode($mm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            // As a fallback, try to capture the exact `<strong>domain : ...</strong>` line.
            if (($msg === null || $msg === '') && preg_match('/<strong>\\s*' . preg_quote($domain, '/') . '\\s*:\\s*([^<]+)\\s*<\\/strong>/i', $htmlRes, $mm2)) {
                $msg = $domain . ' : ' . trim(html_entity_decode($mm2[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $msgLc = is_string($msg) ? strtolower($msg) : '';
            $htmlLc = strtolower($htmlRes);

            $status = 'error';
            $err = 'GR WHOIS: unrecognized response';

            if (strpos($msgLc, 'can be provisioned') !== false) {
                $status = 'available';
                $err = null;
            } elseif (strpos($msgLc, 'in use') !== false) {
                $status = 'registered';
                $err = null;
            } elseif (strpos($msgLc, 'pending registration declaration') !== false) {
                // The registry indicates the name is in a pending/hold state; it should be treated as not available.
                $status = 'registered';
                $err = null;
            } elseif (strpos($htmlLc, 'can be provisioned') !== false) {
                $status = 'available';
                $err = null;
                if ($msg === null || $msg === '') {
                    $msg = $domain . ' : The Domain Name can be provisioned.';
                    $msgLc = strtolower($msg);
                }
            } elseif (strpos($htmlLc, 'pending registration declaration') !== false) {
                $status = 'registered';
                $err = null;
                if ($msg === null || $msg === '') {
                    $msg = $domain . ' : Pending registration declaration';
                    $msgLc = strtolower($msg);
                }
            } elseif (strpos($htmlLc, ' : in use') !== false) {
                $status = 'registered';
                $err = null;
                if ($msg === null || $msg === '') {
                    $msg = $domain . ' : In use';
                    $msgLc = strtolower($msg);
                }
            } elseif ($msg !== null && $msg !== '') {
                $err = 'GR WHOIS: ' . $msg;
            }

            // Parse nameservers if present.
            $ns = [];
            $needle = 'Nameserver';
            $pos = stripos($htmlRes, $needle);
            if ($pos !== false) {
                $slice = substr($htmlRes, $pos, 4000);
                if (preg_match_all('/<div class="col-12 text-md-end">\\s*([^<\\s]+)\\s*<\\/div>/i', $slice, $nsM)) {
                    foreach ($nsM[1] as $v) {
                        $v = strtolower(trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                        if ($v !== '' && strpos($v, '.') !== false && strlen($v) <= 255) {
                            $ns[] = $v;
                        }
                    }
                    $ns = array_values(array_unique($ns));
                }
            }

            $out = [
                'status' => $status,
                'http_code' => 0,
                'rdap_url' => $formUrl,
                'error' => $err,
                'result_json' => json_encode([
                    'method' => 'grweb',
                    'message' => $msg,
                    'nameservers' => $ns,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];

            unset($ch);
            if (is_string($cookieFile) && strpos($cookieFile, sys_get_temp_dir()) === 0) @unlink($cookieFile);

            if (in_array($status, ['available', 'registered'], true)) {
                return $out;
            }

            $lastError = $err;
            continue;
        }

        return [
            'status' => 'error',
            'http_code' => 0,
            'rdap_url' => $formUrl,
            'error' => $lastError ?: 'GR WHOIS: failed',
            // Store a small hint to help debugging on servers that get blocked by WAF/captcha.
            'result_json' => json_encode([
                'method' => 'grweb',
                'error' => $lastError ?: 'failed',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    } finally {
        // resources cleaned up per-attempt
    }
}

/**
 * Full check: RDAP first, WHOIS fallback when RDAP is unsupported for this TLD.
 *
 * @return array{status:string,http_code:int,rdap_url:?string,error:?string,result_json:?string}
 */
function orbitraBackorderCheck(string $domain, int $timeoutSeconds = 10): array
{
    $rdap = orbitraBackorderRdapCheck($domain, $timeoutSeconds);
    if (($rdap['status'] ?? '') !== 'unsupported') {
        return $rdap;
    }

    $tld = orbitraBackorderExtractTld($domain);
    if (in_array($tld, ['gr', 'xn--qxam'], true)) {
        // GR web endpoint sometimes returns intermittent 403; retry a couple of times.
        $gr = orbitraBackorderGrWebCheck($domain, max(10, $timeoutSeconds));
        if (!in_array(($gr['status'] ?? ''), ['available', 'registered'], true)) {
            usleep(200000);
            $gr = orbitraBackorderGrWebCheck($domain, max(10, $timeoutSeconds));
        }
        if (in_array(($gr['status'] ?? ''), ['available', 'registered'], true)) {
            return $gr;
        }
        // For .gr we prefer returning the actual GR registry failure instead of "No RDAP",
        // otherwise users see a misleading "cannot check" even when the registry endpoint is blocked.
        if (in_array(($gr['status'] ?? ''), ['error', 'rate_limited'], true)) {
            return $gr;
        }
        // If GR web check fails with "unsupported" for some future corner case, continue with WHOIS fallback.
    }

    // RDAP unsupported for this TLD. Try WHOIS as best-effort fallback.
    $whois = orbitraBackorderWhoisCheck($domain, max(10, $timeoutSeconds));

    // If WHOIS also unsupported, keep original RDAP unsupported reason (more specific).
    if (($whois['status'] ?? '') === 'unsupported') {
        return $rdap;
    }

    return $whois;
}
