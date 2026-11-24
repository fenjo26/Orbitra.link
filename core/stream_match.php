<?php
// core/stream_match.php
//
// The click router's stream matcher, verbatim: does a visitor pass a stream's
// filters (core/StreamFilters.php holds the per-filter helpers this calls)?
//
// It lived in index.php, which cannot be required from anywhere else, so the
// campaign simulator (api.php, simulate_traffic) carried a private, shortened
// copy of it. That copy treated OS, Browser, IP, ISP, Keyword, Referer, Weekday
// and Time filters as always matched and ignored the unknown-country policy —
// the simulator answered "passes this stream" where the live router said
// "skipped". One matcher, used by both, is the fix; the parity is pinned by
// tests/simulator_parity_test.php.
//
// Every definition is guarded: this file is required by index.php, the test
// harness and the simulator in any order.

require_once __DIR__ . '/StreamFilters.php';
require_once __DIR__ . '/CloakDetector.php';
require_once __DIR__ . '/geo_databases.php';
require_once __DIR__ . '/UserAgent.php';

if (!function_exists('orbitraStreamsNeedUniqueness')) {
    /**
     * Does any of these streams carry a "Unique" filter? The uniqueness probe
     * costs one indexed read per click, so the click path runs it only when a
     * filter is going to read the answer.
     */
    function orbitraStreamsNeedUniqueness(array $streams): bool
    {
        foreach ($streams as $stream) {
            if (!empty($stream['filters_json']) && stripos((string) $stream['filters_json'], 'uniq') !== false) {
                foreach (orbitraNormalizeStreamFilters($stream['filters_json']) as $filter) {
                    if (strcasecmp((string) ($filter['name'] ?? ''), 'Unique') === 0) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}

if (!function_exists('orbitraOsVersionTokenMatches')) {
    /**
     * Does an "OS version" filter value fit this visitor's OS and version?
     *
     * One value is an OS and a condition on its version, in any of these forms:
     *   iOS 16          that major version
     *   iOS 15-18       from 15 up to and including 18
     *   iOS <=14        iOS 14 and every 14.x   (also <, >=, >, =)
     *   iOS 16.4        that exact version
     * OS names: ios, android, windows, macos (mac), linux. Versions compare
     * segment by segment (15.4 is below 15.10), so a range's ends are majors.
     *
     * From the User-Agent alone. iOS reports its real version there; Android
     * does not — Chrome on Android 10 and newer announces "Android 10" for
     * every one of them, and Windows 10 and 11 both say "Windows NT 10.0" —
     * so an Android or Windows value separates browsers, not systems.
     */
    function orbitraOsVersionTokenMatches(string $token, string $os, string $version): bool
    {
        $token = strtolower(trim($token));
        $os = strtolower(trim($os));
        $version = trim($version);
        if ($token === '' || $version === '') {
            return false;
        }
        if (!preg_match('/^([a-z]+)\s*(<=|>=|<|>|=)?\s*(v?[0-9]+(?:\.[0-9]+)*)?(?:\s*-\s*(v?[0-9]+(?:\.[0-9]+)*))?$/', $token, $m)) {
            return false;
        }
        $family = $m[1] === 'mac' ? 'macos' : ($m[1] === 'iphone' || $m[1] === 'ipad' ? 'ios' : $m[1]);
        if ($family !== $os) {
            return false;
        }
        $from = ltrim((string) ($m[3] ?? ''), 'v');
        $to = ltrim((string) ($m[4] ?? ''), 'v');
        $op = (string) ($m[2] ?? '');
        if ($to !== '') {
            // "iOS 15-18": every 15.x up to the start of 19.
            return version_compare($version, $from, '>=') && version_compare($version, ((int) $to + 1) . '', '<');
        }
        if ($from === '') {
            return false;
        }
        if ($op === '') {
            // "iOS 16" or "iOS 16.4": the same version, any minor.
            return version_compare($version, $from, '>=') && version_compare($version, $from . '.9999', '<=');
        }
        if ($op === '=') {
            return version_compare($version, $from, '==');
        }
        // A bare major is a whole release: "iOS <=14" is iOS 14 and every
        // 14.x before it, "iOS >16" starts with 17 — 14.8 would otherwise
        // read as "above 14".
        if (strpos($from, '.') === false && ($op === '<=' || $op === '>')) {
            return version_compare($version, ((int) $from + 1) . '', $op === '<=' ? '<' : '>=');
        }
        return version_compare($version, $from, $op);
    }
}

if (!function_exists('filterTokenEquals')) {
    // Case-insensitive equality of a payload token against a value.
    function filterTokenEquals($token, $value)
    {
        return strcasecmp(trim((string) $token), trim((string) $value)) === 0;
    }
}

if (!function_exists('browserMatchesToken')) {
    // Match a Browser filter token against the detected browser name and raw UA.
    // Supports standard browsers (by detected name) and in-app browsers like
    // TikTok / Facebook / Instagram (by user-agent signatures), since these
    // in-app webviews are Chrome/Safari based and are not distinguishable by name.
    function browserMatchesToken($token, $browser, $userAgent)
    {
        $t = strtolower(trim((string) $token));
        if ($t === '')
            return false;

        $ua = strtolower($userAgent);
        $bname = strtolower((string) $browser);

        // In-app browser signatures (matched against the raw user-agent).
        $inAppSignatures = [
            'tiktok' => ['tiktok', 'musical_ly', 'bytedancewebview', 'bytedance', 'trill', 'aweme'],
            'facebook' => ['fban', 'fbav', 'fb_iab', 'fbios'],
            'messenger' => ['messenger'],
            'instagram' => ['instagram'],
            'snapchat' => ['snapchat'],
            'pinterest' => ['pinterest'],
            'telegram' => ['telegram'],
            'twitter' => ['twitter', 'twitterandroid'],
            'wechat' => ['micromessenger'],
            'webview' => ['; wv', 'webview'],
        ];

        if (isset($inAppSignatures[$t])) {
            foreach ($inAppSignatures[$t] as $sig) {
                if (strpos($ua, $sig) !== false)
                    return true;
            }
            return false;
        }

        // Standard browsers: rely on the detected browser name to avoid false
        // positives (e.g. every Chrome UA also contains "Safari/").
        if ($t === $bname)
            return true;
        if ($t === 'samsung' && $bname === 'samsung browser')
            return true;
        if ($bname !== '' && $bname !== 'unknown' && strpos($bname, $t) !== false)
            return true;

        return false;
    }
}

if (!function_exists('orbitraIpInCidrOrRange')) {
    // Match an IP filter token (supports exact match and wildcards like 10.0.0.*).
    /**
     * true/false when $token is a CIDR block or an "a-b" range of one address
     * family, null when it is neither (the caller falls back to exact / mask).
     */
    function orbitraIpInCidrOrRange(string $token, string $ip): ?bool
    {
        $ipBin = @inet_pton(trim($ip));
        if (strpos($token, '/') !== false) {
            [$net, $bits] = array_map('trim', explode('/', $token, 2));
            $netBin = @inet_pton($net);
            if ($netBin === false || !ctype_digit($bits)) {
                return null;
            }
            $bits = (int) $bits;
            if ($ipBin === false || strlen($ipBin) !== strlen($netBin) || $bits > strlen($netBin) * 8) {
                return false;
            }
            $bytes = intdiv($bits, 8);
            if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                return false;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
        }
        $parts = array_map('trim', explode('-', $token, 2));
        if (count($parts) !== 2) {
            return null;
        }
        $from = @inet_pton($parts[0]);
        $to = @inet_pton($parts[1]);
        if ($from === false || $to === false || strlen($from) !== strlen($to)) {
            return null;
        }
        if ($ipBin === false || strlen($ipBin) !== strlen($from)) {
            return false;
        }
        return strcmp($ipBin, $from) >= 0 && strcmp($ipBin, $to) <= 0;
    }
}

if (!function_exists('ipMatchesToken')) {
    function ipMatchesToken($token, $ip)
    {
        $token = trim((string) $token);
        if ($token === '')
            return false;
        // CIDR (1.2.3.0/24, 2001:db8::/32) and ranges (1.2.3.4-1.2.3.99), the
        // forms Keitaro's IP filter accepts besides exact values and "*" masks.
        if (strpos($token, '/') !== false || strpos($token, '-') !== false) {
            $r = orbitraIpInCidrOrRange($token, (string) $ip);
            if ($r !== null) {
                return $r;
            }
        }
        if (strpos($token, '*') === false) {
            return $token === $ip;
        }
        $regex = '/^' . str_replace('\*', '.*', preg_quote($token, '/')) . '$/';
        return (bool) preg_match($regex, $ip);
    }
}

if (!function_exists('orbitraCountryFilterGeoReady')) {
    // Memoized "geo can actually resolve" probe for the Country stream filter.
    // Fail-closed there is only safe when a geo database is installed AND its
    // reader class is loadable — otherwise getGeoData() can never turn 'Unknown'
    // into a country and the policy below would reject every visitor (fresh
    // installs ship without geo databases). Memoized because
    // streamMatchesFilters() runs once per stream per request.
    function orbitraCountryFilterGeoReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = orbitraGeoDatabasesInstalled() && (
                class_exists('\\IP2Location\\Database')
                || class_exists('\\GeoIp2\\Database\\Reader')
                || is_file(dirname(__DIR__) . '/core/SxGeo.php')
            );
        }
        return $ready;
    }
}

if (!function_exists('orbitraBotFilterClickContext')) {
    /**
     * Click-log fields for a visit routed by a "Bot" stream filter: the same
     * columns a cloak stream fills, so the Reason column and the click details
     * show the signals. Verdict "bot_filter", route "safe".
     */
    function orbitraBotFilterClickContext(array $hit): array
    {
        $reasons = array_values(array_filter(array_map('strval', $hit['reasons'] ?? [])));
        return [
            'cloak_verdict' => 'bot_filter',
            'cloak_reasons' => implode(',', $reasons),
            'is_safe_page' => 1,
            'cloak_sensitivity' => (string) ($hit['sensitivity'] ?? ''),
        ];
    }
}

if (!function_exists('streamMatchesFilters')) {
    function streamMatchesFilters($stream, $visitor, $pdo, &$filterTrace = null)
    {
        // Debug observer (?_dbg=1): when a trace array is passed in, every filter
        // records its verdict — match / no-match / abstain — so the routing trace
        // can show WHY a stream was skipped. Null (the production callers) keeps
        // the function allocation-free on the hot path.
        // Untyped on purpose: the trace is a debug aid and must never be the
        // thing that turns a click into an HTTP 500 (2.0.0 field report — a
        // string payload hit the old `array $payload` hint on every request,
        // trace or not, because PHP checks the types before the body runs).
        $record = function ($filter, $mode, string $result, $payload = [], string $note = '') use (&$filterTrace): void {
            if ($filterTrace === null) {
                return;
            }
            $filterTrace[] = [
                'name' => (string) (is_array($filter) ? ($filter['name'] ?? '?') : '?'),
                'mode' => (string) $mode,
                'result' => $result,
                'payload' => implode(', ', array_slice(array_map(static function ($v) {
                    return is_scalar($v) ? (string) $v : json_encode($v);
                }, orbitraFilterPayloadList($payload)), 0, 8)),
                'note' => $note,
            ];
        };

        if (empty($stream['filters_json'])) {
            return true;
        }
        $filters = json_decode($stream['filters_json'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($filters) || empty($filters)) {
            return true;
        }

        $ip = $visitor['ip'] ?? '';
        $country = $visitor['country'] ?? '';
        $deviceType = $visitor['device'] ?? '';
        $os = $visitor['os'] ?? '';
        $browser = $visitor['browser'] ?? '';
        $languageCodes = $visitor['languageCodes'] ?? [];
        $userAgent = $visitor['userAgent'] ?? '';
        $referer = $visitor['referer'] ?? '';
        $params = $visitor['params'] ?? [];
        $rawParams = is_array($visitor['rawParams'] ?? null) ? $visitor['rawParams'] : [];
        $timezone = $visitor['timezone'] ?? '';
        $isp = trim(($visitor['isp'] ?? '') . ' ' . ($visitor['asn'] ?? ''));

        $logic = orbitraStreamFilterLogic($stream);
        $votes = [];

        foreach ($filters as $f) {
            if (!is_array($f)) {
                continue;
            }
            $mode = (string) ($f['mode'] ?? 'include');
            // A string or scalar payload (API / AI assistant / hand-edited rows)
            // is read as a list, like the editor reads it.
            $payload = orbitraFilterPayloadList($f['payload'] ?? []);
            // Set when a branch already wrote its own (annotated) trace record;
            // the generic vote record at the bottom then stays quiet.
            $fRecorded = false;
            if (empty($payload)) {
                $record($f, $mode, 'abstain', [], 'empty payload');
                continue;
            }

            $matched = false;
            switch ((string) ($f['name'] ?? '')) {
                case 'Country':
                    // Policy for an undetermined country (Unknown/Local/empty):
                    // - include mode fails closed — the filter does not match, so
                    //   a stream targeted at specific countries stops serving
                    //   visitors whose country we could not resolve — but only
                    //   when geo actually works (orbitraCountryFilterGeoReady()).
                    //   Without a geo database the filter abstains like before,
                    //   otherwise a fresh install would reject everyone.
                    // - exclude mode abstains: an unknown country is no evidence
                    //   the visitor IS in the excluded list, so exclusion keeps
                    //   its old permissive behavior.
                    if ($country === '' || $country === 'Unknown' || $country === 'Local') {
                        if ($mode !== 'include' || !orbitraCountryFilterGeoReady()) {
                            $record($f, $mode, 'abstain', $payload, 'country undetermined');
                            continue 2;
                        }
                        // $matched stays false — fail closed.
                        $record($f, $mode, 'no-match', $payload, 'country undetermined, include fails closed');
                        $fRecorded = true;
                        break;
                    }
                    foreach ($payload as $item) {
                        if (filterTokenEquals($item, $country)) {
                            $matched = true;
                            break;
                        }
                    }
                    break;
                case 'Device':
                    $matched = orbitraDeviceGroupMatches((string) $deviceType, $payload);
                    break;
                case 'OS':
                    foreach ($payload as $item) {
                        if (filterTokenEquals($item, $os)) {
                            $matched = true;
                            break;
                        }
                    }
                    break;
                case 'Browser':
                    foreach ($payload as $item) {
                        if (browserMatchesToken($item, $browser, $userAgent)) {
                            $matched = true;
                            break;
                        }
                    }
                    break;
                case 'Bot':
                    // The visitor as the detector reads it. accept_language
                    // unknown: the Click API caller never forwarded the header,
                    // so "empty" says nothing about the visitor.
                    $botVisitor = [
                        'ip' => $ip,
                        'user_agent' => $userAgent,
                        'asn' => $visitor['asn'] ?? '',
                        'isp' => $visitor['isp'] ?? '',
                        'is_proxy' => $visitor['isProxy'] ?? 0,
                        'proxy_type' => $visitor['proxyType'] ?? '',
                        'proxy_threat' => $visitor['proxyThreat'] ?? '',
                        'proxy_provider' => $visitor['proxyProvider'] ?? '',
                        'proxy_fraud_score' => $visitor['proxyFraudScore'] ?? null,
                        'accept_language' => $visitor['acceptLanguage'] ?? '',
                        'accept_language_unknown' => !empty($visitor['acceptLanguageUnknown']),
                        'pdo' => $pdo,
                    ];
                    // The global Bot ISP list (Settings → Bots) is part of this
                    // filter's verdict. An entry point that has already loaded
                    // the settings hands the list over; otherwise the detector
                    // reads the row itself.
                    $botOptions = ['sensitivity' => orbitraBotFilterSensitivity($f)];
                    if (array_key_exists('botIspList', $visitor)) {
                        $botOptions['bot_isp_list'] = (string) $visitor['botIspList'];
                    }
                    $botVerdict = CloakDetector::detectBotFilter($botVisitor, $botOptions);
                    $matched = (bool) ($botVerdict['is_suspicious'] ?? false);
                    $botReasons = array_values(array_filter(array_map('strval', $botVerdict['reasons'] ?? [])));
                    // Remembered per stream so the click log can say WHY a visit
                    // counted as a bot (see orbitraBotFilterClickContext()).
                    if ($matched && $mode === 'include' && isset($stream['id'])) {
                        $GLOBALS['orbitraBotFilterHits'][(int) $stream['id']] = [
                            'reasons' => $botReasons,
                            'sensitivity' => orbitraBotFilterSensitivity($f),
                        ];
                    }
                    $record($f, $mode, $matched ? 'match' : 'no-match', $payload,
                        orbitraBotFilterSensitivity($f) . ': ' . ($botReasons === [] ? 'no signals' : implode(', ', $botReasons)));
                    $fRecorded = true;
                    break;
                case 'Unique':
                    // "unique" / "non-unique" by the campaign's own uniqueness
                    // window (IP, or IP + User-Agent). The entry point computes
                    // it only when some stream asks (orbitraStreamsNeedUniqueness()).
                    if (!array_key_exists('isUnique', $visitor) || $visitor['isUnique'] === null) {
                        $record($f, $mode, 'abstain', $payload, 'uniqueness not evaluated');
                        continue 2;
                    }
                    $anyKnown = false;
                    foreach ($payload as $item) {
                        $token = strtolower(trim((string) $item));
                        $wantUnique = in_array($token, ['unique', 'yes', '1', 'new'], true) ? true
                            : (in_array($token, ['non-unique', 'nonunique', 'no', '0', 'returning'], true) ? false : null);
                        if ($wantUnique === null) {
                            continue;
                        }
                        $anyKnown = true;
                        if ($visitor['isUnique'] === $wantUnique) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$anyKnown) {
                        $record($f, $mode, 'abstain', $payload, 'no "unique" / "non-unique" value');
                        continue 2;
                    }
                    $record($f, $mode, $matched ? 'match' : 'no-match', $payload,
                        'visitor is ' . ($visitor['isUnique'] ? 'unique' : 'not unique'));
                    $fRecorded = true;
                    break;
                case 'OS version':
                    // From the User-Agent; the OS family and its version come
                    // from the same parser the click log shows.
                    $uaInfo = orbitraParseUserAgent($userAgent);
                    $visitorOs = strtolower((string) ($uaInfo['os'] ?? ''));
                    $visitorOs = $visitorOs === 'ipados' ? 'ios' : $visitorOs;
                    $visitorVersion = trim((string) ($uaInfo['os_version'] ?? ''));
                    if ($visitorOs === '' || $visitorOs === 'unknown' || $visitorVersion === '' || $visitorVersion === '10/11') {
                        $record($f, $mode, 'abstain', $payload, 'OS version not reported by the browser');
                        continue 2;
                    }
                    foreach ($payload as $item) {
                        if (orbitraOsVersionTokenMatches((string) $item, $visitorOs, $visitorVersion)) {
                            $matched = true;
                            break;
                        }
                    }
                    $record($f, $mode, $matched ? 'match' : 'no-match', $payload, 'visitor: ' . $uaInfo['os'] . ' ' . $visitorVersion);
                    $fRecorded = true;
                    break;
                case 'Language':
                    // Full tags from the raw header, so "en-IN" is not just "en".
                    $matched = orbitraLanguageFilterMatches($payload, (string) ($visitor['acceptLanguage'] ?? ''));
                    break;
                case 'IP':
                    foreach ($payload as $item) {
                        if (ipMatchesToken($item, $ip)) {
                            $matched = true;
                            break;
                        }
                    }
                    break;
                case 'Referer':
                    // Contains / "*" mask / "/regex/"; "@empty" = no referrer
                    // (core/StreamFilters.php).
                    $matched = orbitraRefererFilterMatches($payload, (string) $referer);
                    break;
                case 'UserAgent':
                    $matched = orbitraUserAgentFilterMatches($payload, (string) $userAgent);
                    break;
                case 'Keyword':
                    $keyword = strtolower((string) ($params['keyword'] ?? ''));
                    foreach ($payload as $item) {
                        $needle = strtolower(trim((string) $item));
                        if ($needle !== '' && $keyword !== '' && strpos($keyword, $needle) !== false) {
                            $matched = true;
                            break;
                        }
                    }
                    break;
                case 'Parameter':
                    // Any URL parameter by name; "@empty" matches a missing or
                    // empty one (core/StreamFilters.php). No name configured →
                    // abstain, like any other filter that cannot be evaluated.
                    $paramName = orbitraParameterFilterName($f);
                    if ($paramName === '') {
                        $record($f, $mode, 'abstain', $payload, 'no parameter name');
                        continue 2;
                    }
                    $paramValue = orbitraParameterFilterValue($paramName, $rawParams, $params);
                    $matched = orbitraParameterFilterMatches($payload, $paramValue);
                    $record($f, $mode, $matched ? 'match' : 'no-match', $payload,
                        $paramName . '=' . ($paramValue === '' ? '(empty)' : $paramValue));
                    $fRecorded = true;
                    break;
                case 'Weekday':
                    try {
                        $tz = new DateTimeZone(($timezone && $timezone !== 'Unknown') ? $timezone : date_default_timezone_get());
                    } catch (\Exception $e) {
                        $tz = new DateTimeZone(date_default_timezone_get());
                    }
                    $now = new DateTime('now', $tz);
                    $currentWeekday = strtolower($now->format('l')); // monday, tuesday...
                    foreach ($payload as $item) {
                        if (filterTokenEquals($item, $currentWeekday)) {
                            $matched = true;
                            break;
                        }
                    }
                    break;
                case 'Time':
                    try {
                        $tz = new DateTimeZone(($timezone && $timezone !== 'Unknown') ? $timezone : date_default_timezone_get());
                    } catch (\Exception $e) {
                        $tz = new DateTimeZone(date_default_timezone_get());
                    }
                    $now = new DateTime('now', $tz);
                    $minutesNow = ((int) $now->format('G')) * 60 + (int) $now->format('i');
                    foreach ($payload as $item) {
                        $range = trim((string) $item);
                        if (strpos($range, '-') === false)
                            continue;
                        [$from, $to] = array_map('trim', explode('-', $range, 2));
                        $fromMin = timeToMinutes($from);
                        $toMin = timeToMinutes($to);
                        if ($fromMin === null || $toMin === null)
                            continue;
                        if ($fromMin <= $toMin) {
                            $inRange = $minutesNow >= $fromMin && $minutesNow <= $toMin;
                        } else {
                            // Overnight range, e.g. 22-6
                            $inRange = $minutesNow >= $fromMin || $minutesNow <= $toMin;
                        }
                        if ($inRange) {
                            $matched = true;
                            break;
                        }
                    }
                    break;
                case 'ISP':
                    // Requires a GeoLite2-ASN database (or commercial IP2Location
                    // with ISP field). If no ISP data is available for this IP,
                    // skip the filter so traffic is not blocked.
                    if ($isp === '') {
                        $record($f, $mode, 'abstain', $payload, 'no ISP data');
                        continue 2;
                    }
                    $ispHaystack = strtolower($isp);
                    foreach ($payload as $item) {
                        $needle = strtolower(trim((string) $item));
                        if ($needle !== '' && strpos($ispHaystack, $needle) !== false) {
                            $matched = true;
                            break;
                        }
                    }
                    break;
                case 'Connection':
                    // Needs a source that knows the connection type: a commercial
                    // IP2Location BIN or an uploaded mobile-operator list
                    // (orbitraLookupNetwork()). Without one — or for an address
                    // the source cannot classify — the filter abstains, so a
                    // stream is never emptied by a database that is not there.
                    $connectionType = (string) ($visitor['connectionType'] ?? '');
                    if ($connectionType === '') {
                        $record($f, $mode, 'abstain', $payload, 'no connection data');
                        continue 2;
                    }
                    $matched = orbitraConnectionFilterMatches($payload, $connectionType);
                    $record($f, $mode, $matched ? 'match' : 'no-match', $payload, 'connection=' . $connectionType);
                    $fRecorded = true;
                    break;
                case 'Operator':
                    // Mobile operator. With no operator source installed the
                    // filter abstains. With one, an address that belongs to no
                    // operator is simply "not this operator": include does not
                    // match, exclude passes.
                    if (empty(orbitraNetworkSources(dirname(__DIR__))['carrier'])) {
                        $record($f, $mode, 'abstain', $payload, 'no operator database');
                        continue 2;
                    }
                    $carrier = (string) ($visitor['carrier'] ?? '');
                    $matched = orbitraOperatorFilterMatches($payload, $carrier);
                    $record($f, $mode, $matched ? 'match' : 'no-match', $payload, 'operator=' . ($carrier === '' ? '(none)' : $carrier));
                    $fRecorded = true;
                    break;
                default:
                    // Unknown filter type — do not block traffic.
                    $record($f, $mode, 'abstain', $payload, 'unknown filter type');
                    continue 2;
            }

            // Filters that reach this point vote; the `continue 2` cases above
            // (undeterminable ISP, connection type, unknown types, and country
            // without working geo or in exclude mode) abstain — an abstention
            // neither blocks AND nor satisfies OR. The country filter in include
            // mode with working geo deliberately does NOT abstain: an unresolved
            // country votes "no match" (fail closed).
            $votes[] = ($mode === 'include') ? $matched : !$matched;
            if (!$fRecorded) {
                $record($f, $mode, $matched ? 'match' : 'no-match', $payload);
            }
        }
        return orbitraCombineFilterVotes($votes, $logic);
    }
}

if (!function_exists('orbitraTraceStreams')) {
    /**
     * Debug observer for ?_dbg=1: evaluate every stream against the SAME visitor
     * context the live selection used, recording each filter's vote. Pure
     * observer — the selection itself has already happened above, so a broken
     * trace can at worst lie in the debug page, never in the routing.
     */
    function orbitraTraceStreams(array $allStreams, array $visitor, PDO $pdo): array
    {
        $trace = [];
        foreach ($allStreams as $stream) {
            $filtersTrace = [];
            $matched = streamMatchesFilters($stream, $visitor, $pdo, $filtersTrace);
            $trace[] = [
                'id' => (int) ($stream['id'] ?? 0),
                'name' => (string) ($stream['name'] ?? ('#' . ($stream['id'] ?? '?'))),
                'type' => (string) ($stream['type'] ?? 'regular'),
                'schema' => (string) ($stream['schema_type'] ?? 'redirect'),
                'logic' => orbitraStreamFilterLogic($stream),
                'filters' => $filtersTrace,
                'matched' => $matched,
            ];
        }
        return $trace;
    }
}

if (!function_exists('timeToMinutes')) {
    // Convert "HH" or "HH:MM" to minutes since midnight, or null if invalid.
    function timeToMinutes($value){
        $value = trim((string) $value);
        if ($value === '')
            return null;
        if (strpos($value, ':') !== false) {
            [$h, $m] = array_map('intval', explode(':', $value, 2));
        } else {
            $h = (int) $value;
            $m = 0;
        }
        if ($h < 0 || $h > 24 || $m < 0 || $m > 59)
            return null;
        return $h * 60 + $m;
    }
}
