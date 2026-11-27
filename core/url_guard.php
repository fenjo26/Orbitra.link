<?php
/**
 * Guard for requests the server itself makes to operator-supplied URLs.
 *
 * The tracker stores URLs its users type in — traffic sources, preload
 * landings, proxied offers. Before the server fetches one, the host has to
 * prove to be public: otherwise an account with limited rights can point the
 * server at the LAN, at localhost services or at cloud metadata addresses and
 * read the answer back.
 *
 * The vetted addresses are pinned into the request (CURLOPT_RESOLVE), so the
 * server talks to exactly what it checked — a resolver cannot hand curl a
 * different, private address a moment later.
 */

/** Redirects a guarded fetch follows by hand before it gives up. */
const ORBITRA_URL_GUARD_MAX_REDIRECTS = 5;

/**
 * Whether an IP address is reachable only from the public internet.
 */
function orbitraUrlGuardIsPublicIp(string $ip): bool
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return false;
    }
    if (strlen($packed) === 4) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Neither private nor reserved for ext/filter, and not public either:
        // 100.64.0.0/10 (carrier-grade NAT) and 198.18.0.0/15 (benchmarking).
        $first = ord($packed[0]);
        $second = ord($packed[1]);
        return !($first === 100 && ($second & 0xc0) === 0x40)
            && !($first === 198 && ($second & 0xfe) === 18);
    }
    // IPv6: only global unicast space (2000::/3) is public. That leaves out
    // loopback, link-local, unique-local and every IPv4-embedded form without
    // relying on the PHP version's own list of reserved ranges.
    return (ord($packed[0]) & 0xe0) === 0x20;
}

/**
 * Vet one URL: plain http(s), and every address its host resolves to is public.
 *
 * Returns ['ok' => bool, 'host' => string, 'port' => int, 'addresses' => string[],
 * 'reason' => string, 'code' => string]. `addresses` are the vetted IPs to pin
 * the request to; `reason` explains a refusal in a sentence and `code` names
 * it (url_invalid | url_host_unresolved | url_internal_address).
 */
function orbitraUrlGuardResolve(string $url): array
{
    $parts = parse_url($url);
    $defaultPorts = ['http' => 80, 'https' => 443];
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    if ($scheme === '' || empty($parts['host']) || !isset($defaultPorts[$scheme])) {
        return ['ok' => false, 'host' => '', 'port' => 0, 'addresses' => [], 'reason' => 'Only http and https URLs to a public host are checked', 'code' => 'url_invalid'];
    }
    // parse_url() keeps the brackets on an IPv6 literal.
    $host = trim($parts['host'], '[]');
    $port = (int) ($parts['port'] ?? $defaultPorts[$scheme]);

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $addresses = [$host];
    } else {
        // gethostbyname() hands the name back unresolved on failure.
        $ip = gethostbyname($host);
        if ($ip !== $host) {
            $addresses = [$ip];
        } else {
            // No A record: an IPv6-only host is still worth a look. dns_get_record()
            // asks the resolver directly and is missing from some builds.
            $records = function_exists('dns_get_record') ? @dns_get_record($host, DNS_AAAA) : false;
            $addresses = [];
            foreach (is_array($records) ? $records : [] as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
            if ($addresses === []) {
                return ['ok' => false, 'host' => $host, 'port' => $port, 'addresses' => [], 'reason' => 'Could not resolve host', 'code' => 'url_host_unresolved'];
            }
        }
    }
    foreach ($addresses as $address) {
        if (!orbitraUrlGuardIsPublicIp($address)) {
            return ['ok' => false, 'host' => $host, 'port' => $port, 'addresses' => [], 'reason' => 'Host resolves to a private or reserved address', 'code' => 'url_internal_address'];
        }
    }
    return ['ok' => true, 'host' => $host, 'port' => $port, 'addresses' => $addresses, 'reason' => ''];
}

/**
 * CURLOPT_RESOLVE entry that keeps a request on the vetted addresses.
 */
function orbitraUrlGuardPin(array $vetted): string
{
    $addresses = array_map(
        static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
        $vetted['addresses']
    );
    return $vetted['host'] . ':' . $vetted['port'] . ':' . implode(',', $addresses);
}

/**
 * Location header of a response's header block, or '' when it names no target.
 */
function orbitraUrlGuardLocation(string $headerBlock): string
{
    $location = '';
    foreach (preg_split('~\r?\n~', $headerBlock) ?: [] as $line) {
        if (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
        }
    }
    return $location;
}

/**
 * Turn a Location header into an absolute URL, relative to the URL it came from.
 */
function orbitraUrlGuardAbsolute(string $location, string $base): string
{
    $location = trim($location);
    if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $location)) {
        return $location;
    }
    $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
    if (str_starts_with($location, '//')) {
        return $scheme . ':' . $location;
    }
    $port = parse_url($base, PHP_URL_PORT);
    $origin = $scheme . '://' . parse_url($base, PHP_URL_HOST) . ($port ? ':' . $port : '');
    $path = (string) parse_url($base, PHP_URL_PATH);
    if (str_starts_with($location, '?')) {
        return $origin . ($path === '' ? '/' : $path) . $location;
    }
    if ($location !== '' && $location[0] !== '/') {
        // Resolve against the base directory and fold "." and ".." segments.
        $slash = strrpos($path, '/');
        $segments = [];
        foreach (explode('/', ($slash === false ? '' : substr($path, 0, $slash)) . '/' . $location) as $segment) {
            if ($segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }
        $location = '/' . ltrim(implode('/', $segments), '/');
    }
    return $origin . $location;
}

/**
 * GET or HEAD the URL and follow its redirects by hand, vetting every hop with
 * $resolve before touching it. curl's own FOLLOWLOCATION would take a public
 * page's Location header to the LAN unchecked.
 *
 * $options are extra curl options (a proxy among them). The redirect policy
 * and TLS verification are set here and cannot be relaxed by the caller.
 *
 * @param callable $resolve  string => array, the shape of orbitraUrlGuardResolve();
 *                          an empty `addresses` list means the hop cannot be
 *                          pinned (a proxy resolves it) and is still followed
 * @param ?callable $nextHop string $url, int $status, string $body, string $headers
 *                          => string: a redirect the page itself performs (meta
 *                          refresh, location.href = …) or ''
 * @return array ['url' => string, 'status' => int, 'body' => string, 'error' => string,
 *               'reason' => string, 'reason_code' => string, 'headers' => string, 'hops' => list]
 *         `url` is the last URL requested and `headers` its response headers,
 *         `error` curl's message or the redirect verdict, `reason` the guard's
 *         refusal and `reason_code` its name, `hops` every request made (url,
 *         status, ms, location, error).
 */
function orbitraUrlGuardFetch(string $url, array $options, callable $resolve, ?callable $nextHop = null): array
{
    // One time budget for the whole chain, as curl's own TIMEOUT covers a
    // followed chain: a slow hop must not multiply the wait.
    $totalSeconds = (int) ($options[CURLOPT_TIMEOUT] ?? 10);
    $connectMs = (int) (($options[CURLOPT_CONNECTTIMEOUT] ?? 5) * 1000);
    unset($options[CURLOPT_TIMEOUT], $options[CURLOPT_CONNECTTIMEOUT]);
    $deadline = microtime(true) + $totalSeconds;
    $withBody = empty($options[CURLOPT_NOBODY]);

    $hops = [];
    $finish = static function (string $lastUrl, int $status, string $body, string $error, string $reason, string $headers, string $reasonCode = '') use (&$hops): array {
        return [
            'url' => $lastUrl,
            'status' => $status,
            'body' => $body,
            'error' => $error,
            'reason' => $reason,
            'reason_code' => $reasonCode,
            'headers' => $headers,
            'hops' => $hops,
        ];
    };

    $seen = [];
    for ($redirects = 0; ; $redirects++) {
        $vetted = $resolve($url);
        if (!$vetted['ok']) {
            return $finish($url, 0, '', '', $vetted['reason'], '', (string) ($vetted['code'] ?? ''));
        }
        $leftMs = (int) (($deadline - microtime(true)) * 1000);
        if ($leftMs <= 0) {
            return $finish($url, 0, '', 'Operation timed out', '', '');
        }

        $requestOptions = $options + [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min($connectMs, $leftMs),
            CURLOPT_TIMEOUT_MS => $leftMs,
            // TLS verification is never skipped, local host included: a disabled
            // check on a "local" Host header is a MITM hole, and localhost targets
            // plain HTTP anyway.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($vetted['addresses'] !== []) {
            $requestOptions[CURLOPT_RESOLVE] = [orbitraUrlGuardPin($vetted)];
        }

        $ch = curl_init();
        curl_setopt_array($ch, $requestOptions);

        $startedAt = microtime(true);
        $response = curl_exec($ch);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // curl_close() deprecated in PHP 8.5 - resources are auto-freed

        $headers = substr((string) $response, 0, $headerSize);
        $body = $withBody ? (string) substr((string) $response, $headerSize) : '';
        $hops[] = [
            'url' => $url,
            'status' => $status,
            'ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'location' => '',
            'error' => $error,
        ];

        if ($error !== '') {
            return $finish($url, 0, '', $error, '', $headers);
        }

        $location = $status >= 300 && $status < 400 ? orbitraUrlGuardLocation($headers) : '';
        if ($location === '' && $nextHop !== null && $status >= 200 && $status < 300 && $withBody) {
            $location = (string) $nextHop($url, $status, $body, $headers);
        }
        if ($location === '') {
            return $finish($url, $status, $body, '', '', $headers);
        }
        $hops[count($hops) - 1]['location'] = $location;

        $next = orbitraUrlGuardAbsolute($location, $url);
        if (isset($seen[$next])) {
            return $finish($next, 0, '', 'Redirect loop', '', $headers);
        }
        if ($redirects >= ORBITRA_URL_GUARD_MAX_REDIRECTS) {
            return $finish($next, 0, '', 'Too many redirects', '', $headers);
        }
        $seen[$next] = true;
        $url = $next;
    }
}

/**
 * Check URL availability and return status
 * @param string $url The URL to check
 * @return array ['status' => string, 'message' => string]
 */
function checkUrlAvailability($url): array
{
    return orbitraUrlCheckFollow((string) $url, 'orbitraUrlGuardResolve');
}

/**
 * HEAD the URL through the guarded fetch and report the outcome the way the
 * traffic-source list stores it.
 *
 * @param callable $resolve string => array, the shape of orbitraUrlGuardResolve()
 * @return array ['status' => string, 'message' => string]
 */
function orbitraUrlCheckFollow(string $url, callable $resolve): array
{
    // Ensure URL has a scheme before validation
    if ($url !== '' && !preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }

    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['status' => 'error', 'message' => 'Invalid URL'];
    }

    $fetch = orbitraUrlGuardFetch($url, [
        CURLOPT_NOBODY => true, // HEAD request
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Orbitra/1.0; +https://orbitra.io)',
    ], $resolve);

    if ($fetch['reason'] !== '') {
        return ['status' => 'error', 'message' => $fetch['reason']];
    }
    if ($fetch['error'] !== '') {
        if (strpos($fetch['error'], 'timed out') !== false || strpos($fetch['error'], 'timeout') !== false) {
            return ['status' => 'timeout', 'message' => 'Timeout'];
        }
        return ['status' => 'error', 'message' => $fetch['error']];
    }
    if ($fetch['status'] >= 200 && $fetch['status'] < 400) {
        return ['status' => (string) $fetch['status'], 'message' => 'OK'];
    }

    return ['status' => (string) $fetch['status'], 'message' => orbitraUrlCheckStatusMessage($fetch['status'])];
}

/**
 * Human-readable status message for an HTTP code
 */
function orbitraUrlCheckStatusMessage(int $code): string
{
    $messages = [
        200 => 'OK',
        301 => 'Moved Permanently',
        302 => 'Found',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];
    return $messages[$code] ?? "HTTP $code";
}
