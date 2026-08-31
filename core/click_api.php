<?php
// Keitaro-like Click API (minimal v3 compatibility layer).
// Entry point: /click_api/v3?token=...&log=1&info=1
//
// Design notes:
// - Must work with Orbitra's default Nginx config (try_files -> /index.php).
//   So index.php routes /click_api/v3 here explicitly.
// - Token is stored on campaigns.token (imported from Keitaro dumps).
// - Returns JSON describing what headers (Location) would be sent, plus optional info/log.
//
// This intentionally does NOT attempt to fully implement Keitaro's uniqueness_cookie flow yet.

require_once __DIR__ . '/geo_databases.php';
require_once __DIR__ . '/Device.php';
require_once __DIR__ . '/CloakDetector.php';
require_once __DIR__ . '/StreamFilters.php';
require_once __DIR__ . '/ip_access.php';
require_once __DIR__ . '/click_logger.php';

function orbitraClickApiGetSettings(PDO $pdo): array
{
    $settings = [];
    try {
        $stmtSets = $pdo->query("SELECT * FROM settings");
        if ($stmtSets) {
            foreach ($stmtSets->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['key'])) {
                    $settings[(string) $row['key']] = (string) ($row['value'] ?? '');
                }
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $settings;
}

// index.php defines issueLpToken() before routing here, but router.php does not —
// and the {offer} macro in an action landing's body needs a signed transition
// token for the click this API just logged. Same implementation, guarded.
if (!function_exists('issueLpToken')) {
    function issueLpToken($clickId, $secret, $ttl = 86400)
    {
        $payload = base64_encode(json_encode(['c' => $clickId, 'e' => time() + (int) $ttl]));
        $payload = rtrim(strtr($payload, '+/', '-_'), '=');
        $sig = substr(hash_hmac('sha256', $payload, $secret), 0, 32);
        return $payload . '.' . $sig;
    }
}

// Shared click-parameter capture: the same whitelist + source aliases the
// redirect path uses. Capturing only the legacy Keitaro keys here used to drop
// ad_id/adset_id/campaign_id — the very keys cost import matches on — for every
// click arriving through this API (KClient PHP/JS, Tracking Script, banners).
require_once __DIR__ . '/ClickParams.php';

function orbitraClickApiGenerateUuid(): string
{
    try {
        $data = random_bytes(16);
    } catch (Throwable $e) {
        $data = openssl_random_pseudo_bytes(16);
    }
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function orbitraClickApiGetClientIp(): string
{
    // Allows overriding via Click API param (for testing/integrations).
    $ipFromQuery = trim((string) ($_GET['ip'] ?? ''));
    if ($ipFromQuery !== '' && filter_var($ipFromQuery, FILTER_VALIDATE_IP)) {
        return $ipFromQuery;
    }

    return orbitraClientIp();
}

function orbitraClickApiGetUserAgent(): string
{
    $uaFromQuery = (string) ($_GET['user_agent'] ?? '');
    if (trim($uaFromQuery) !== '') {
        return $uaFromQuery;
    }
    return (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
}

function orbitraClickApiDetectAcceptLanguageRaw(): string
{
    $langFromQuery = trim((string) ($_GET['language'] ?? ''));
    if ($langFromQuery !== '') {
        return $langFromQuery;
    }
    return trim((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
}

function orbitraClickApiNormalizeLanguageCode(string $value): string
{
    $value = strtolower(trim($value));
    if ($value === '' || $value === '*') {
        return 'Unknown';
    }

    $value = explode(',', $value)[0];
    $value = explode(';', $value)[0];
    $value = trim($value);
    if ($value === '') {
        return 'Unknown';
    }

    $primary = preg_split('/[-_]/', $value)[0] ?? '';
    $primary = preg_replace('/[^a-z]/', '', $primary);
    if ($primary === '') {
        return 'Unknown';
    }

    return $primary;
}

function orbitraClickApiExtractLanguageCodes(string $headerValue): array
{
    if ($headerValue === '') {
        return [];
    }
    $result = [];
    foreach (explode(',', $headerValue) as $rawPart) {
        $normalized = orbitraClickApiNormalizeLanguageCode($rawPart);
        if ($normalized === 'Unknown') {
            continue;
        }
        if (!in_array($normalized, $result, true)) {
            $result[] = $normalized;
        }
    }
    return $result;
}

function orbitraClickApiGetDeviceType(string $ua): string
{
    return orbitraDetectDeviceType($ua);
}

function orbitraClickApiNormalizeGeoString($value, string $default = ''): string
{
    if (!is_string($value)) {
        return $default;
    }
    $value = trim($value);
    if ($value === '' || $value === '-' || strtolower($value) === 'unknown') {
        return $default;
    }
    return $value;
}

function orbitraClickApiFillGeoData(array &$target, array $source): void
{
    foreach (['country_code', 'region', 'city', 'zipcode', 'timezone'] as $key) {
        if ((empty($target[$key]) || $target[$key] === 'Unknown') && !empty($source[$key])) {
            $target[$key] = (string) $source[$key];
        }
    }

    if ($target['latitude'] === null && array_key_exists('latitude', $source)) {
        $target['latitude'] = orbitraGeoCoordinate($source['latitude'], -90, 90);
    }
    if ($target['longitude'] === null && array_key_exists('longitude', $source)) {
        $target['longitude'] = orbitraGeoCoordinate($source['longitude'], -180, 180);
    }
}

function orbitraClickApiGetGeoData(string $ip): array
{
    orbitraGeoMigrateMisplacedProxy(dirname(__DIR__));

    // Copied from click.php/index.php style: prefer local DBs, fall back to ip-api.com.
    $geo = [
        'country_code' => 'Unknown',
        'region' => '',
        'city' => '',
        'latitude' => null,
        'longitude' => null,
        'zipcode' => '',
        'timezone' => '',
        'asn' => '',
        'isp' => '',
        'is_proxy' => 0,
        'proxy_type' => '',
        'proxy_threat' => '',
        'proxy_provider' => '',
        'proxy_fraud_score' => null,
    ];

    if (in_array($ip, ['127.0.0.1', '::1'], true)) {
        $geo['country_code'] = 'Local';
        return $geo;
    }

    $ip2locCandidates = [
        __DIR__ . '/../geo/IP2LOCATION-LITE-DB11.BIN',
        __DIR__ . '/../geo/IP2LOCATION-LITE.BIN',
    ];
    $ip2locDb = null;
    foreach ($ip2locCandidates as $candidate) {
        if (file_exists($candidate)) {
            $ip2locDb = $candidate;
            break;
        }
    }

    $ip2locHeader = $ip2locDb ? orbitraGeoBinHeader($ip2locDb) : null;
    if ($ip2locDb !== null && ($ip2locHeader['product_code'] ?? null) !== 2 && class_exists('\\IP2Location\\Database')) {
        try {
            $db = new \IP2Location\Database($ip2locDb, \IP2Location\Database::FILE_IO);
            $records = $db->lookup($ip, \IP2Location\Database::ALL);
            if ($records && is_array($records)) {
                orbitraClickApiFillGeoData($geo, [
                    'country_code' => orbitraClickApiNormalizeGeoString($records['countryCode'] ?? $records['country_code'] ?? '', ''),
                    'region' => orbitraClickApiNormalizeGeoString($records['regionName'] ?? $records['region_name'] ?? '', ''),
                    'city' => orbitraClickApiNormalizeGeoString($records['cityName'] ?? $records['city_name'] ?? '', ''),
                    'latitude' => $records['latitude'] ?? null,
                    'longitude' => $records['longitude'] ?? null,
                    'zipcode' => orbitraClickApiNormalizeGeoString($records['zipCode'] ?? $records['zipcode'] ?? '', ''),
                    'timezone' => orbitraClickApiNormalizeGeoString($records['timeZone'] ?? $records['timezone'] ?? '', ''),
                ]);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $maxMindDb = __DIR__ . '/../geo/GeoLite2-City.mmdb';
    if (file_exists($maxMindDb) && class_exists('\\GeoIp2\\Database\\Reader')) {
        try {
            $reader = new \GeoIp2\Database\Reader($maxMindDb);
            $record = $reader->city($ip);
            orbitraClickApiFillGeoData($geo, [
                'country_code' => orbitraClickApiNormalizeGeoString($record->country->isoCode ?? '', ''),
                'region' => orbitraClickApiNormalizeGeoString($record->mostSpecificSubdivision->name ?? '', ''),
                'city' => orbitraClickApiNormalizeGeoString($record->city->name ?? '', ''),
                'latitude' => $record->location->latitude ?? null,
                'longitude' => $record->location->longitude ?? null,
                'timezone' => orbitraClickApiNormalizeGeoString($record->location->timeZone ?? '', ''),
            ]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    $sxGeoDat = __DIR__ . '/../var/geoip/SxGeoCity/SxGeoCity.dat';
    $sxGeoParser = __DIR__ . '/SxGeo.php';
    if (file_exists($sxGeoDat) && file_exists($sxGeoParser)) {
        require_once $sxGeoParser;
        try {
            if (class_exists('SxGeo')) {
                $sxGeo = new SxGeo($sxGeoDat);
                $country = $sxGeo->getCountry($ip);
                orbitraClickApiFillGeoData($geo, [
                    'country_code' => orbitraClickApiNormalizeGeoString((string) $country, ''),
                ]);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $maxMindAsnDb = __DIR__ . '/../geo/GeoLite2-ASN.mmdb';
    if (file_exists($maxMindAsnDb) && class_exists('\\GeoIp2\\Database\\Reader')) {
        try {
            $reader = new \GeoIp2\Database\Reader($maxMindAsnDb);
            $record = $reader->asn($ip);
            if (!empty($record->autonomousSystemNumber)) {
                $geo['asn'] = 'AS' . (int) $record->autonomousSystemNumber;
            }
            $geo['isp'] = orbitraClickApiNormalizeGeoString($record->autonomousSystemOrganization ?? '', '');
        } catch (Throwable $e) {
            // try the IP2Location ASN database below
        }
    }

    if ($geo['asn'] === '' || $geo['isp'] === '') {
        $asnRecord = orbitraLookupIp2LocationAsn($ip, dirname(__DIR__));
        if ($geo['asn'] === '') {
            $asnValue = orbitraClickApiNormalizeGeoString($asnRecord['asn'] ?? '', '');
            if ($asnValue !== '') {
                $geo['asn'] = stripos($asnValue, 'AS') === 0 ? $asnValue : 'AS' . $asnValue;
            }
        }
        if ($geo['isp'] === '') {
            $geo['isp'] = orbitraClickApiNormalizeGeoString($asnRecord['as'] ?? '', '');
        }
    }

    $proxyRecord = orbitraLookupIp2Proxy($ip, dirname(__DIR__));
    if (!empty($proxyRecord)) {
        $geo['is_proxy'] = (int) ($proxyRecord['isProxy'] ?? 0);
        $geo['proxy_type'] = orbitraClickApiNormalizeGeoString($proxyRecord['proxyType'] ?? '', '');
        $geo['proxy_threat'] = orbitraClickApiNormalizeGeoString($proxyRecord['threat'] ?? '', '');
        $geo['proxy_provider'] = orbitraClickApiNormalizeGeoString($proxyRecord['provider'] ?? '', '');
        $geo['proxy_fraud_score'] = is_numeric($proxyRecord['fraudScore'] ?? null)
            ? (int) $proxyRecord['fraudScore']
            : null;
        if ($geo['asn'] === '') {
            $proxyAsn = orbitraClickApiNormalizeGeoString($proxyRecord['asn'] ?? '', '');
            if ($proxyAsn !== '') {
                $geo['asn'] = stripos($proxyAsn, 'AS') === 0 ? $proxyAsn : 'AS' . $proxyAsn;
            }
        }
        if ($geo['isp'] === '') {
            $geo['isp'] = orbitraClickApiNormalizeGeoString($proxyRecord['isp'] ?? ($proxyRecord['as'] ?? ''), '');
        }
    }

    if ($geo['country_code'] === 'Unknown' || $geo['region'] === '' || $geo['city'] === '') {
        try {
            $ch = curl_init("http://ip-api.com/json/{$ip}?fields=countryCode,regionName,city,lat,lon,zip,timezone");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            $response = curl_exec($ch);
            if ($response) {
                $data = json_decode($response, true);
                if (is_array($data)) {
                    orbitraClickApiFillGeoData($geo, [
                        'country_code' => orbitraClickApiNormalizeGeoString($data['countryCode'] ?? '', ''),
                        'region' => orbitraClickApiNormalizeGeoString($data['regionName'] ?? '', ''),
                        'city' => orbitraClickApiNormalizeGeoString($data['city'] ?? '', ''),
                        'latitude' => $data['lat'] ?? null,
                        'longitude' => $data['lon'] ?? null,
                        'zipcode' => orbitraClickApiNormalizeGeoString($data['zip'] ?? '', ''),
                        'timezone' => orbitraClickApiNormalizeGeoString($data['timezone'] ?? '', ''),
                    ]);
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if ($geo['country_code'] === '') {
        $geo['country_code'] = 'Unknown';
    }

    return $geo;
}

function orbitraClickApiSelectWeightedItem(array $items): ?array
{
    if (empty($items)) {
        return null;
    }
    $items = array_values(array_filter($items, function($it) {
        if (isset($it['state']) && ($it['state'] === 'disabled' || $it['state'] === 'paused')) return false;
        if (isset($it['is_active']) && ($it['is_active'] === false || $it['is_active'] === 0 || $it['is_active'] === '0')) return false;
        return true;
    }));
    if (empty($items)) {
        return null;
    }
    $totalW = 0;
    foreach ($items as $it) {
        $w = (int) ($it['weight'] ?? 0);
        if ($w < 0) $w = 0;
        $totalW += $w;
    }
    if ($totalW > 0) {
        $rand = mt_rand(1, (int) $totalW);
        $curW = 0;
        foreach ($items as $it) {
            $curW += max(0, (int) ($it['weight'] ?? 0));
            if ($rand <= $curW) {
                return $it;
            }
        }
    }
    return $items[0];
}

function orbitraClickApiStreamMatchesFilters(array $stream, string $ip, string $country, string $deviceType, array $languageCodes, string $userAgent, array $geoData, string $acceptLanguageRaw, PDO $pdo): bool
{
    if (empty($stream['filters_json'])) {
        return true;
    }
    $filters = json_decode((string) $stream['filters_json'], true);
    if (!is_array($filters) || empty($filters)) {
        return true;
    }

    $logic = orbitraStreamFilterLogic($stream);
    $votes = [];

    foreach ($filters as $f) {
        $mode = $f['mode'] ?? 'include';
        $payload = $f['payload'] ?? [];
        if (empty($payload) || !is_array($payload)) {
            continue;
        }

        $matched = false;
        switch ($f['name'] ?? '') {
            case 'Country':
                $matched = in_array($country, $payload, true);
                break;
            case 'Device':
                $matched = orbitraDeviceGroupMatches($deviceType, $payload);
                break;
            case 'Language':
                $normalizedPayload = [];
                foreach ($payload as $item) {
                    $candidate = orbitraClickApiNormalizeLanguageCode((string) $item);
                    if ($candidate !== '' && $candidate !== 'Unknown') {
                        $normalizedPayload[] = $candidate;
                    }
                }
                $matched = !empty(array_intersect($normalizedPayload, $languageCodes));
                break;
            case 'Bot':
                $botVerdict = CloakDetector::detectBotFilter([
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                    'asn' => $geoData['asn'] ?? '',
                    'isp' => $geoData['isp'] ?? '',
                    'is_proxy' => $geoData['is_proxy'] ?? 0,
                    'proxy_type' => $geoData['proxy_type'] ?? '',
                    'proxy_threat' => $geoData['proxy_threat'] ?? '',
                    'proxy_provider' => $geoData['proxy_provider'] ?? '',
                    'proxy_fraud_score' => $geoData['proxy_fraud_score'] ?? null,
                    'accept_language' => $acceptLanguageRaw,
                    'pdo' => $pdo,
                ]);
                $matched = (bool) ($botVerdict['is_suspicious'] ?? false);
                break;
            default:
                // Unknown filters: keep permissive to avoid blocking traffic.
                $matched = true;
                break;
        }

        $votes[] = ($mode === 'include') ? $matched : !$matched;
    }

    // Unknown filter types vote "pass" above (permissive), so they keep the
    // stream eligible under AND and satisfy OR — same behavior as before the
    // logic toggle, now routed through the shared combiner.
    return orbitraCombineFilterVotes($votes, $logic);
}

function orbitraClickApiV3(PDO $pdo): void
{
    // Ensure optional GeoIP dependencies can autoload (index.php loads it too, but router.php does not).
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    // Heal double-"?" query strings BEFORE the token read below: the token is
    // the first query pair on these URLs and is exactly what a malformed
    // concatenation corrupts. No-op for normal traffic.
    require_once __DIR__ . '/ClickParams.php';
    orbitraHealQueryString($_GET, $_SERVER['QUERY_STRING'] ?? '');

    $token = trim((string) ($_GET['token'] ?? ''));
    $wantLog = ((string) ($_GET['log'] ?? '0')) === '1';
    $wantInfo = ((string) ($_GET['info'] ?? '0')) === '1';
    $forceRedirectOffer = ((string) ($_GET['force_redirect_offer'] ?? '0')) === '1';

    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');

    if ($token === '') {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized (missing token)']);
        return;
    }

    $settings = orbitraClickApiGetSettings($pdo);
    if (($settings['click_api_enabled'] ?? '1') === '0') {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Click API disabled']);
        return;
    }

    // Apply ignore_prefetch consistently with the other click entry points: a
    // speculative request is answered normally but its click is not logged. The
    // helper is loaded by index.php for this path; require once keeps it safe if
    // the file is ever reached another way.
    if (!function_exists('orbitraShouldSkipClickOnPrefetch')) {
        require_once __DIR__ . '/prefetch.php';
    }
    $prefetchSkipClick = orbitraShouldSkipClickOnPrefetch($settings['ignore_prefetch'] ?? '1');

    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE is_archived = 0 AND token = ? LIMIT 1");
    $stmt->execute([$token]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized (campaign not found)']);
        return;
    }

    $log = [];
    $campaignId = (int) ($campaign['id'] ?? 0);
    if ($wantLog) {
        $log[] = "Processing campaign {$campaignId}";
    }

    $ip = orbitraClickApiGetClientIp();
    $userAgent = orbitraClickApiGetUserAgent();
    $acceptLanguageRaw = orbitraClickApiDetectAcceptLanguageRaw();
    $languageCodes = orbitraClickApiExtractLanguageCodes($acceptLanguageRaw);
    $language = $languageCodes[0] ?? 'Unknown';
    $deviceType = orbitraClickApiGetDeviceType($userAgent);

    if ($wantLog) {
        $log[] = "IP: {$ip}";
        $log[] = "UserAgent: {$userAgent}";
        $log[] = "Language: {$acceptLanguageRaw}";
    }

    $geoData = orbitraClickApiGetGeoData($ip);
    $country = (string) ($geoData['country_code'] ?? 'Unknown');

    // Shared capture: standard keys, sub_id_N, ad-network IDs, click ids and
    // the campaign source's declared aliases — identical to redirect visits.
    // ($_GET was healed at the top of this handler, before the token read.)
    $incomingParams = array_merge($_GET, $_POST);
    $clickParams = orbitraCollectClickParams($pdo, $incomingParams, [], $campaign['source_id'] ?? null);
    $parametersJson = json_encode($clickParams, JSON_UNESCAPED_UNICODE);

    $clickId = orbitraClickApiGenerateUuid();
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');

    // Streams selection (Intercepting -> Regular -> Fallback).
    $stmt = $pdo->prepare("SELECT * FROM streams WHERE campaign_id = ? AND is_active = 1 ORDER BY position ASC, id ASC");
    $stmt->execute([$campaignId]);
    $allStreams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $selectedStream = null;
    foreach ($allStreams as $stream) {
        if (($stream['type'] ?? 'regular') === 'intercepting') {
            if ($wantLog) $log[] = "Checking stream #{$stream['id']} (intercepting)";
            if (orbitraClickApiStreamMatchesFilters($stream, $ip, $country, $deviceType, $languageCodes, $userAgent, $geoData, $acceptLanguageRaw, $pdo)) {
                $selectedStream = $stream;
                if ($wantLog) $log[] = "Accepted by filters (intercepting)";
                break;
            }
        }
    }

    if (!$selectedStream) {
        $eligible = [];
        foreach ($allStreams as $stream) {
            if (($stream['type'] ?? 'regular') !== 'regular') continue;
            if ($wantLog) $log[] = "Checking stream #{$stream['id']} (regular)";
            if (orbitraClickApiStreamMatchesFilters($stream, $ip, $country, $deviceType, $languageCodes, $userAgent, $geoData, $acceptLanguageRaw, $pdo)) {
                $eligible[] = $stream;
                if ($wantLog) $log[] = "Accepted by filters (regular)";
            }
        }

        if (!empty($eligible)) {
            if (($campaign['rotation_type'] ?? 'position') === 'weight') {
                $selectedStream = orbitraClickApiSelectWeightedItem($eligible);
                if ($wantLog) $log[] = "Selected stream by weight: #{$selectedStream['id']}";
            } else {
                $selectedStream = $eligible[0];
                if ($wantLog) $log[] = "Selected stream by position: #{$selectedStream['id']}";
            }
        }
    }

    if (!$selectedStream) {
        foreach ($allStreams as $stream) {
            if (($stream['type'] ?? '') === 'fallback') {
                if ($wantLog) $log[] = "Checking stream #{$stream['id']} (fallback)";
                if (orbitraClickApiStreamMatchesFilters($stream, $ip, $country, $deviceType, $languageCodes, $userAgent, $geoData, $acceptLanguageRaw, $pdo)) {
                    $selectedStream = $stream;
                    if ($wantLog) $log[] = "Accepted by filters (fallback)";
                    break;
                }
            }
        }
    }

    if (!$selectedStream) {
        http_response_code(404);
        $resp = [
            'body' => null,
            'contentType' => 'application/json; charset=utf-8',
            'headers' => [],
            'status' => '404',
        ];
        if ($wantLog) {
            $resp['log'] = array_merge($log, ['No stream matched']);
        }
        if ($wantInfo) {
            $resp['info'] = [
                'campaign_id' => $campaignId,
                'stream_id' => null,
                'sub_id' => $clickId,
                'type' => 'none',
                'url' => null,
            ];
        }
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
        return;
    }

    $streamId = (int) ($selectedStream['id'] ?? 0);
    $schemaType = $selectedStream['schema_type'] ?? 'redirect';
    $customSchema = json_decode((string) ($selectedStream['schema_custom_json'] ?? '{}'), true);
    if (!is_array($customSchema)) $customSchema = [];

    $landingIdToLog = null;
    $offerIdToLog = 0;
    $landingType = null;
    $landingUrl = null;
    $landingAction = null;
    $landingActionType = '';
    $offerUrl = null;
    $finalUrl = null;
    $skipClickLogging = false;
    // Action landing (Keitaro «Показать как HTML/текст»): the stream's content —
    // a banner, a text block — is delivered as the response body for the client
    // (banner.js / KClient JS/PHP) to inject into the site, instead of a redirect.
    $actionBody = null;
    $actionContentType = 'text/html; charset=utf-8';

    $routeSchemaType = $schemaType;
    if ($schemaType === 'cloak') {
        $cloakConfig = [
            'detect_datacenter' => $customSchema['detect_datacenter'] ?? true,
            'detect_vpn' => $customSchema['detect_vpn'] ?? true,
            'detect_bots' => $customSchema['detect_bots'] ?? true,
            'detect_ua' => $customSchema['detect_ua'] ?? true,
            'sensitivity' => $customSchema['sensitivity'] ?? 'medium',
        ];
        $cloakVisitor = [
            'ip' => $ip,
            'user_agent' => $userAgent,
            'asn' => $geoData['asn'] ?? '',
            'isp' => $geoData['isp'] ?? '',
            'is_proxy' => $geoData['is_proxy'] ?? 0,
            'proxy_type' => $geoData['proxy_type'] ?? '',
            'proxy_threat' => $geoData['proxy_threat'] ?? '',
            'proxy_provider' => $geoData['proxy_provider'] ?? '',
            'proxy_fraud_score' => $geoData['proxy_fraud_score'] ?? null,
            'accept_language' => $acceptLanguageRaw,
            'pdo' => $pdo,
        ];

        // Compute cloak routing decision using shared function (W1)
        $jsFailure = (string) ($_GET['_ocjf'] ?? '');
        $cloakDecision = orbitraCloakDecision(
            $customSchema,
            $cloakVisitor,
            $settings,
            $country,
            $deviceType,
            $jsFailure
        );

        $cloakShowSafe = $cloakDecision['show_safe'];
        $cloakVerdict = $cloakDecision['verdict'];
        $cloakReasons = $cloakDecision['reasons'];
        $skipClickLogging = $cloakDecision['skip_click_log'];
        $routeSchemaType = $cloakShowSafe ? 'cloak_safe' : 'landing_offer';

        if ($wantLog) {
            $log[] = $cloakShowSafe
                ? 'Cloak decision: Safe Page' . ($skipClickLogging ? ' (click logging skipped)' : '')
                : 'Cloak decision: Money Page';
        }

        // Keep error_log logging for post-mortems
        if ($cloakVerdict) {
            $cloakVisitorCtx = [
                'ip' => $ip,
                'user_agent' => $userAgent,
                'asn' => $geoData['asn'] ?? '',
                'isp' => $geoData['isp'] ?? '',
            ];
            logCloakEvent(
                strtoupper($cloakVerdict),
                $campaignId,
                $streamId,
                $cloakVisitorCtx,
                $cloakReasons,
                $cloakDecision['sensitivity']
            );
        }
    }

    if ($routeSchemaType === 'cloak_safe') {
        $safeLandingId = (int) ($customSchema['safe_landing_id'] ?? 0);
        $safeOfferId = (int) ($customSchema['safe_offer_id'] ?? 0);
        $safeMode = (string) ($customSchema['safe_mode'] ?? '');
        if (!in_array($safeMode, ['landing', 'offer', 'url', 'html'], true)) {
            $safeMode = $safeLandingId > 0
                ? 'landing'
                : ($safeOfferId > 0
                    ? 'offer'
                    : (!empty($customSchema['safe_url']) ? 'url' : 'html'));
        }

        if ($safeMode === 'landing' && $safeLandingId > 0) {
            $stmtL = $pdo->prepare("SELECT type, url, action_payload, action_type, slug FROM landings WHERE id = ?");
            $stmtL->execute([$safeLandingId]);
            $land = $stmtL->fetch(PDO::FETCH_ASSOC);
            if ($land) {
                $landingIdToLog = $safeLandingId;
                $landingType = $land['type'] ?? null;
                $landingUrl = $land['url'] ?? null;
                $landingAction = $land['action_payload'] ?? null;
                $landingActionType = (string) ($land['action_type'] ?? '');
                if (in_array($landingType, ['redirect', 'preload'], true) && $landingUrl) {
                    $finalUrl = $landingUrl;
                } elseif ($landingType === 'local' && !empty($land['slug'])) {
                    $finalUrl = '/lander/' . rawurlencode((string) $land['slug']) . '/';
                } elseif ($landingType === 'action'
                    && in_array($landingActionType, ['show_html', 'show_text'], true)) {
                    $actionBody = (string) $landingAction;
                    $actionContentType = $landingActionType === 'show_text'
                        ? 'text/plain; charset=utf-8'
                        : 'text/html; charset=utf-8';
                }
            } else {
                $actionBody = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Welcome</title></head><body><h1>Page</h1><p>Content is loading.</p></body></html>';
            }
        } elseif ($safeMode === 'offer' && $safeOfferId > 0) {
            // A local offer as the white page. The Click API cannot serve the
            // archive itself — the tracker's public /offers/<id>/ route does.
            // Relative on purpose: this endpoint is served by index.php on the
            // same host, exactly like the /lander/ redirect above.
            $stmtSafeOffer = $pdo->prepare("SELECT is_local FROM offers WHERE id = ? LIMIT 1");
            $stmtSafeOffer->execute([$safeOfferId]);
            $safeOfferRow = $stmtSafeOffer->fetch();
            if ($safeOfferRow && (int) ($safeOfferRow['is_local'] ?? 0) === 1) {
                $finalUrl = '/offers/' . $safeOfferId . '/';
            } else {
                $actionBody = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Welcome</title></head><body><h1>Page</h1><p>Content is loading.</p></body></html>';
            }
        } elseif ($safeMode === 'url' && !empty($customSchema['safe_url'])) {
            $finalUrl = (string) $customSchema['safe_url'];
        } else {
            $actionBody = !empty($customSchema['safe_html'])
                ? (string) $customSchema['safe_html']
                : '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Welcome</title></head><body><h1>Page</h1><p>Content is loading.</p></body></html>';
        }
    } elseif ($routeSchemaType === 'landing_offer') {
        $pickedLanding = orbitraClickApiSelectWeightedItem($customSchema['landings'] ?? []);
        $pickedOffer = orbitraClickApiSelectWeightedItem($customSchema['offers'] ?? []);
        if ($pickedLanding) $landingIdToLog = (int) ($pickedLanding['id'] ?? 0) ?: null;
        if ($pickedOffer) $offerIdToLog = (int) ($pickedOffer['id'] ?? 0);

        if ($landingIdToLog) {
            $stmtL = $pdo->prepare("SELECT type, url, action_payload, action_type FROM landings WHERE id = ?");
            $stmtL->execute([$landingIdToLog]);
            $land = $stmtL->fetch(PDO::FETCH_ASSOC);
            if ($land) {
                $landingType = $land['type'] ?? null;
                $landingUrl = $land['url'] ?? null;
                $landingAction = $land['action_payload'] ?? null;
                $landingActionType = (string) ($land['action_type'] ?? '');
            }
        }
        if ($offerIdToLog) {
            $stmtO = $pdo->prepare("SELECT url, is_local FROM offers WHERE id = ?");
            $stmtO->execute([$offerIdToLog]);
            $off = $stmtO->fetch(PDO::FETCH_ASSOC);
            if ($off) {
                $offerUrl = $off['url'] ?? null;
                // A direct local offer carries no URL — the tracker's public
                // /offers/<id>/ route serves its uploaded page instead.
                if ((string) $offerUrl === '' && (int) ($off['is_local'] ?? 0) === 1) {
                    $offerUrl = '/offers/' . $offerIdToLog . '/';
                }
            }
        }

        if ($forceRedirectOffer && $offerUrl) {
            $finalUrl = $offerUrl;
        } else if ($landingType === 'action') {
            // Show the stream's content on the page the client is already on. The
            // click stays put; {offer} inside the payload is resolved below to a
            // signed transition link, so the banner click continues THIS click.
            if ($landingActionType === 'show_html' || $landingActionType === 'show_text') {
                $actionBody = (string) $landingAction;
                $actionContentType = $landingActionType === 'show_text'
                    ? 'text/plain; charset=utf-8'
                    : 'text/html; charset=utf-8';
            }
            // do_nothing / not_found / to_campaign: nothing to render — matches
            // Keitaro, where a "Do nothing" stream leaves the site untouched.
        } else if ($landingType === 'redirect' && $landingUrl) {
            $finalUrl = $landingUrl;
        } else if ($offerUrl) {
            $finalUrl = $offerUrl;
        }
    } else if ($routeSchemaType === 'action') {
        // Click API cannot "render" local/preload/action streams reliably.
        $finalUrl = null;
    } else {
        // redirect schema
        $pickedOffer = orbitraClickApiSelectWeightedItem($customSchema['offers'] ?? []);
        if ($pickedOffer) {
            $offerIdToLog = (int) ($pickedOffer['id'] ?? 0);
        } else {
            $offerIdToLog = (int) ($selectedStream['offer_id'] ?? 0);
        }

        if ($offerIdToLog) {
            $stmtO = $pdo->prepare("SELECT url, is_local FROM offers WHERE id = ?");
            $stmtO->execute([$offerIdToLog]);
            $off = $stmtO->fetch(PDO::FETCH_ASSOC);
            if ($off) {
                $offerUrl = $off['url'] ?? null;
                // Same direct-local-offer fallback as the landing_offer schema.
                if ((string) $offerUrl === '' && (int) ($off['is_local'] ?? 0) === 1) {
                    $offerUrl = '/offers/' . $offerIdToLog . '/';
                }
                $finalUrl = $offerUrl;
            }
        }
    }

    // Stream-level "Collect clicks" (see index.php): a no-collect stream is
    // answered without a clicks row — the returned URL carries a sub_id that
    // will not resolve, by design.
    $streamCollectsClicks = !$selectedStream || (int) ($selectedStream['collect_clicks'] ?? 1) === 1;

    // Log click (if stats are enabled; a prefetch hit is answered but skipped).
    $statsEnabled = ($settings['stats_enabled'] ?? '1') !== '0';
    if ($statsEnabled && !$prefetchSkipClick && !$skipClickLogging && $streamCollectsClicks) {
        try {
            // Build click row using shared module
            $clickCtx = [
                'click_id' => $clickId,
                'campaign_id' => $campaignId,
                'offer_id' => $offerIdToLog,
                'stream_id' => $streamId,
                'source_id' => $campaign['source_id'] ?? null,
                'landing_id' => $landingIdToLog,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'referer' => $referer,
                'country' => $country,
                'country_code' => $country,
                'region' => $geoData['region'] ?? '',
                'city' => $geoData['city'] ?? '',
                'latitude' => $geoData['latitude'] ?? null,
                'longitude' => $geoData['longitude'] ?? null,
                'zipcode' => $geoData['zipcode'] ?? '',
                'timezone' => $geoData['timezone'] ?? '',
                'device_type' => $deviceType,
                'os' => 'Unknown',
                'browser' => 'Unknown',
                'language' => $language,
                'accept_language_raw' => $acceptLanguageRaw,
                'parameters_json' => $parametersJson,
            ];

            // Add cloak observability fields for cloak streams (W1)
            if (isset($cloakDecision) && $schemaType === 'cloak') {
                $cloakClickCtx = orbitraCloakClickContext($cloakDecision, $geoData ?? []);
                $clickCtx = array_merge($clickCtx, $cloakClickCtx);
            }

            $clickRow = orbitraBuildClickRow($clickCtx);

            // Persist click using shared module
            orbitraPersistClick($pdo, $clickRow);

            // Honesty flags for the report metrics — same helper the router uses.
            require_once __DIR__ . '/ClickFlags.php';
            orbitraWriteClickFlags($pdo, $clickId, $ip, $userAgent, $campaign, $streamId, $geoData);

            // Time-since-LP-click starts here for external landings too: the
            // click carries the landing the visitor is being sent to, and the
            // signed offer_link closes the pair on the /?_lp=1 transition.
            if ($landingIdToLog) {
                try {
                    $pdo->prepare("UPDATE clicks SET landing_at = datetime('now') WHERE id = ? AND landing_at IS NULL")->execute([$clickId]);
                } catch (Throwable $e) {
                    // Timing is a nice-to-have.
                }
            }
        } catch (Throwable $e) {
            if ($wantLog) {
                $log[] = "DB insert failed: " . $e->getMessage();
            }
        }
    } elseif ($statsEnabled && !$prefetchSkipClick && ($skipClickLogging || !$streamCollectsClicks)) {
        // Click was suppressed - record it for visibility (W3.3)
        $verdict = 'unknown';
        $reasons = '';

        if (isset($cloakDecision) && $schemaType === 'cloak') {
            $verdict = $cloakDecision['verdict'] ?? 'unknown';
            $reasons = !empty($cloakDecision['reasons']) ? implode(',', $cloakDecision['reasons']) : '';
        }

        orbitraRecordSuppressedHit(
            $pdo,
            (int) $campaignId,
            $streamId,
            $verdict,
            $reasons
        );
    }

    $headers = [];
    // A signed landing→offer transition for the click just logged: the {offer}
    // macro inside an action landing's body and the clients' getOffer() both use
    // it, so the follow-up click continues THIS click instead of creating a new one.
    $offerTransitionLink = null;
    if ($offerUrl && $offerIdToLog) {
        $lpScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $lpHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($lpHost !== '') {
            $lpSecret = $settings['postback_key'] ?? '';
            if ($lpSecret === '') {
                $lpSecret = 'orbitra_secret';
            }
            $offerTransitionLink = $lpScheme . '://' . $lpHost . '/?_lp=1'
                . '&_token=' . urlencode(issueLpToken($clickId, $lpSecret))
                . '&offer_id=' . (int) $offerIdToLog;
        }
    }

    if ($finalUrl) {
        // Macro replacement — the same set index.php substitutes on the redirect
        // path. {clickid} alone is not enough: the stream editor documents
        // {subid}, {ip} and {country} as working in a direct destination URL,
        // and an offer URL written with {subid} (the Keitaro spelling, and the
        // one most networks ask for) reached the network empty when the visitor
        // arrived through KClient instead of the campaign link — so the
        // conversion postback had no click to attach itself to.
        $clickMacros = ['{clickid}', '{subid}', '{ip}', '{country}'];
        $clickValues = [$clickId, $clickId, urlencode((string) $ip), urlencode((string) $country)];

        $offerUrlMacros = str_replace($clickMacros, $clickValues, (string) ($offerUrl ?? ''));

        $resolved = str_replace($clickMacros, $clickValues, $finalUrl);
        foreach ($clickParams as $key => $val) {
            $resolved = str_replace('{' . $key . '}', urlencode((string) $val), $resolved);
        }
        if ($offerIdToLog) {
            $resolved = str_replace('{offer_id}', (string) $offerIdToLog, $resolved);
            $resolved = str_replace('{offer}', urlencode($offerUrlMacros), $resolved);
        }

        // Drop macros the click carried no value for — same cleanup as the
        // index.php redirect path (a literal "{utm_term}" must not reach the
        // affiliate network).
        $resolved = preg_replace('#\{[a-zA-Z0-9_]+\}#', '', $resolved);

        if (!preg_match('#^(https?:)?//#i', $resolved) && !preg_match('#^/#', $resolved) && !preg_match('#^(mailto|tel):#i', $resolved)) {
            $resolved = 'http://' . ltrim($resolved, '/');
        }

        $headers[] = "Location: {$resolved}";
        if ($wantLog) {
            $log[] = "Send headers: Location: {$resolved}";
        }
    } else if ($actionBody !== null) {
        // The action landing's content carries the banner. {offer} was already
        // resolved into the signed transition link above.
        $actionBody = str_replace(
            ['{clickid}', '{subid}', '{ip}', '{country}'],
            [$clickId, $clickId, urlencode((string) $ip), urlencode((string) $country)],
            $actionBody
        );
        foreach ($clickParams as $key => $val) {
            $actionBody = str_replace('{' . $key . '}', urlencode((string) $val), $actionBody);
        }
        if ($offerTransitionLink !== null) {
            $actionBody = str_replace('{offer}', $offerTransitionLink, $actionBody);
            $actionBody = str_replace('{offer_id}', (string) $offerIdToLog, $actionBody);
        }
        if ($wantLog) {
            $log[] = "Action landing body: " . strlen($actionBody) . " bytes";
        }
    } else if ($wantLog) {
        $log[] = "No Location header (action/local landing or URL not found)";
    }

    $resp = [
        'body' => $actionBody,
        'contentType' => $actionContentType,
        'headers' => $headers,
        'status' => '200',
        'cookies_ttl' => (int) ($campaign['uniqueness_hours'] ?? 24),
        'uniqueness_cookie' => null,
    ];

    if ($wantInfo) {
        $resp['info'] = [
            'campaign_id' => $campaignId,
            'stream_id' => $streamId ?: null,
            'sub_id' => $clickId,
            'type' => $headers ? 'location' : ($actionBody !== null ? ($actionContentType === 'text/plain; charset=utf-8' ? 'text' : 'html') : 'none'),
            // Mirror Keitaro-ish semantics: `url` is the (unresolved) destination template.
            'url' => $finalUrl,
            // The signed, ready-to-use offer link (what {offer} in a body resolves
            // to); getOffer()-style clients should prefer it over `url`.
            'offer_link' => $offerTransitionLink,
            'landing_id' => $landingIdToLog,
            'offer_id' => $offerIdToLog ?: null,
        ];
    }

    if ($wantLog) {
        $resp['log'] = $log;
    }

    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
}
