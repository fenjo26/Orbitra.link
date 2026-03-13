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
    // Allows overriding via Click API param.
    $ipFromQuery = trim((string) ($_GET['ip'] ?? ''));
    if ($ipFromQuery !== '' && filter_var($ipFromQuery, FILTER_VALIDATE_IP)) {
        return $ipFromQuery;
    }

    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            foreach (explode(',', (string) $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
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
    $mobileAgents = '/(android|bb\\d+|meego).+mobile|avantgo|bada\\/+|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\\/+|plucker|pocket|psp|series(4|6)0|symbian|treo|up\\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i';
    if (preg_match($mobileAgents, strtolower($ua))) {
        return 'Mobile';
    }
    return 'Desktop';
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

    foreach (['latitude', 'longitude'] as $key) {
        if ($target[$key] === null && isset($source[$key]) && is_numeric($source[$key])) {
            $target[$key] = (float) $source[$key];
        }
    }
}

function orbitraClickApiGetGeoData(string $ip): array
{
    // Copied from click.php/index.php style: prefer local DBs, fall back to ip-api.com.
    $geo = [
        'country_code' => 'Unknown',
        'region' => '',
        'city' => '',
        'latitude' => null,
        'longitude' => null,
        'zipcode' => '',
        'timezone' => '',
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

    if ($ip2locDb !== null && class_exists('\\IP2Location\\Database')) {
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

function orbitraClickApiStreamMatchesFilters(array $stream, string $ip, string $country, string $deviceType, array $languageCodes, string $userAgent, PDO $pdo): bool
{
    if (empty($stream['filters_json'])) {
        return true;
    }
    $filters = json_decode((string) $stream['filters_json'], true);
    if (!is_array($filters) || empty($filters)) {
        return true;
    }

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
                $matched = in_array($deviceType, $payload, true);
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
            default:
                // Unknown filters: keep permissive to avoid blocking traffic.
                $matched = true;
                break;
        }

        if ($mode === 'include' && !$matched) {
            return false;
        }
        if ($mode === 'exclude' && $matched) {
            return false;
        }
    }

    return true;
}

function orbitraClickApiV3(PDO $pdo): void
{
    // Ensure optional GeoIP dependencies can autoload (index.php loads it too, but router.php does not).
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

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

    // Extract Keitaro-standard params for macro replacement.
    $incomingParams = array_merge($_GET, $_POST);
    $clickParams = [];
    $standardKeys = ['keyword', 'cost', 'currency', 'external_id', 'creative_id', 'ad_campaign_id', 'source', 'subid'];
    for ($i = 1; $i <= 30; $i++) {
        $standardKeys[] = 'sub_id_' . $i;
    }
    foreach ($standardKeys as $key) {
        if (isset($incomingParams[$key])) {
            $clickParams[$key] = $incomingParams[$key];
        }
    }
    if (isset($clickParams['subid']) && !isset($clickParams['sub_id_1'])) {
        $clickParams['sub_id_1'] = $clickParams['subid'];
    }
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
            if (orbitraClickApiStreamMatchesFilters($stream, $ip, $country, $deviceType, $languageCodes, $userAgent, $pdo)) {
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
            if (orbitraClickApiStreamMatchesFilters($stream, $ip, $country, $deviceType, $languageCodes, $userAgent, $pdo)) {
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
                if (orbitraClickApiStreamMatchesFilters($stream, $ip, $country, $deviceType, $languageCodes, $userAgent, $pdo)) {
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
    $offerUrl = null;
    $finalUrl = null;

    if ($schemaType === 'landing_offer') {
        $pickedLanding = orbitraClickApiSelectWeightedItem($customSchema['landings'] ?? []);
        $pickedOffer = orbitraClickApiSelectWeightedItem($customSchema['offers'] ?? []);
        if ($pickedLanding) $landingIdToLog = (int) ($pickedLanding['id'] ?? 0) ?: null;
        if ($pickedOffer) $offerIdToLog = (int) ($pickedOffer['id'] ?? 0);

        if ($landingIdToLog) {
            $stmtL = $pdo->prepare("SELECT type, url, action_payload FROM landings WHERE id = ?");
            $stmtL->execute([$landingIdToLog]);
            $land = $stmtL->fetch(PDO::FETCH_ASSOC);
            if ($land) {
                $landingType = $land['type'] ?? null;
                $landingUrl = $land['url'] ?? null;
                $landingAction = $land['action_payload'] ?? null;
            }
        }
        if ($offerIdToLog) {
            $stmtO = $pdo->prepare("SELECT url FROM offers WHERE id = ?");
            $stmtO->execute([$offerIdToLog]);
            $off = $stmtO->fetch(PDO::FETCH_ASSOC);
            if ($off) {
                $offerUrl = $off['url'] ?? null;
            }
        }

        // Default click API behavior: redirect to landing if it is a redirect landing.
        // If force_redirect_offer=1, always provide offer URL if available.
        if ($forceRedirectOffer && $offerUrl) {
            $finalUrl = $offerUrl;
        } else if ($landingType === 'redirect' && $landingUrl) {
            $finalUrl = $landingUrl;
        } else if ($offerUrl) {
            $finalUrl = $offerUrl;
        }
    } else if ($schemaType === 'action') {
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
            $stmtO = $pdo->prepare("SELECT url FROM offers WHERE id = ?");
            $stmtO->execute([$offerIdToLog]);
            $off = $stmtO->fetch(PDO::FETCH_ASSOC);
            if ($off) {
                $offerUrl = $off['url'] ?? null;
                $finalUrl = $offerUrl;
            }
        }
    }

    // Log click (if stats are enabled).
    $statsEnabled = ($settings['stats_enabled'] ?? '1') !== '0';
    if ($statsEnabled) {
        try {
            $insertStmt = $pdo->prepare("
                INSERT INTO clicks
                (
                    id, campaign_id, offer_id, stream_id, source_id, landing_id, ip, user_agent, referer,
                    country, country_code, region, city, latitude, longitude, zipcode, timezone,
                    device_type, os, browser, language, accept_language_raw, parameters_json
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([
                $clickId,
                $campaignId,
                $offerIdToLog,
                $streamId,
                $campaign['source_id'] ?? null,
                $landingIdToLog,
                $ip,
                $userAgent,
                $referer,
                $country,
                $country,
                $geoData['region'] ?? '',
                $geoData['city'] ?? '',
                $geoData['latitude'] ?? null,
                $geoData['longitude'] ?? null,
                $geoData['zipcode'] ?? '',
                $geoData['timezone'] ?? '',
                $deviceType,
                'Unknown',
                'Unknown',
                $language,
                $acceptLanguageRaw,
                $parametersJson,
            ]);
        } catch (Throwable $e) {
            if ($wantLog) {
                $log[] = "DB insert failed: " . $e->getMessage();
            }
        }
    }

    $headers = [];
    if ($finalUrl) {
        // Macro replacement (similar to index.php).
        $offerUrlMacros = str_replace('{clickid}', $clickId, (string) ($offerUrl ?? ''));

        $resolved = str_replace('{clickid}', $clickId, $finalUrl);
        foreach ($clickParams as $key => $val) {
            $resolved = str_replace('{' . $key . '}', urlencode((string) $val), $resolved);
        }
        if ($offerIdToLog) {
            $resolved = str_replace('{offer_id}', (string) $offerIdToLog, $resolved);
            $resolved = str_replace('{offer}', urlencode($offerUrlMacros), $resolved);
        }

        if (!preg_match('#^(https?:)?//#i', $resolved) && !preg_match('#^/#', $resolved) && !preg_match('#^(mailto|tel):#i', $resolved)) {
            $resolved = 'http://' . ltrim($resolved, '/');
        }

        $headers[] = "Location: {$resolved}";
        if ($wantLog) {
            $log[] = "Send headers: Location: {$resolved}";
        }
    } else if ($wantLog) {
        $log[] = "No Location header (action/local landing or URL not found)";
    }

    $resp = [
        'body' => null,
        'contentType' => 'text/html; charset=utf-8',
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
            'type' => $headers ? 'location' : 'none',
            // Mirror Keitaro-ish semantics: `url` is the (unresolved) destination template.
            'url' => $finalUrl,
            'landing_id' => $landingIdToLog,
            'offer_id' => $offerIdToLog ?: null,
        ];
    }

    if ($wantLog) {
        $resp['log'] = $log;
    }

    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
}
