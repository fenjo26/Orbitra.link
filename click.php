<?php
// click.php — Lightweight click handler for integration scripts
// Accepts campaign_id OR campaign token (Keitaro Click API compatible), records click,
// and optionally redirects to offer URL.
// Usage:
//   /click.php?campaign_id=1&token=...&redirect=0
//   /click.php?token=...&redirect=0

require_once 'config.php';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/core/geo_databases.php';
// Same prefetch guard as the main click path — a speculative request here would
// otherwise be counted as a real click.
require_once __DIR__ . '/core/prefetch.php';

// ignore_prefetch is read before any campaign lookup so a dropped request never
// touches the DB. Default is on, matching index.php.
$ignorePrefetch = '1';
try {
    $prefetchRow = $pdo->query("SELECT value FROM settings WHERE key = 'ignore_prefetch' LIMIT 1")->fetchColumn();
    if (is_string($prefetchRow)) {
        $ignorePrefetch = $prefetchRow;
    }
} catch (\Throwable $e) {
    // Default stands on a read error.
}
// Prefetch guard (ignore_prefetch): a speculative request is answered normally
// but its click is not logged — killing the request used to leave the cached
// "Prefetch ignored." body on the visitor's screen.
$skipClickOnPrefetch = orbitraShouldSkipClickOnPrefetch($ignorePrefetch);

$campaignId = $_GET['campaign_id'] ?? null;
$token = $_GET['token'] ?? ($_GET['api_token'] ?? null);
if (is_string($token)) {
    $token = trim($token);
    if ($token === '') $token = null;
}

// Look up campaign by token (preferred) or ID (legacy).
$campaign = null;
if ($token) {
    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $campaign = $stmt->fetch();
    if ($campaign) {
        $campaignId = (int) ($campaign['id'] ?? 0);
    }
} else if ($campaignId) {
    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ? LIMIT 1");
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch();
}

if (!$campaign) {
    http_response_code(404);
    echo json_encode(['error' => 'Campaign not found']);
    exit;
}

// If campaign has a token, require it for click API calls (prevents fake clicks by ID guessing).
if (!empty($campaign['token'])) {
    if (!$token) {
        http_response_code(403);
        echo json_encode(['error' => 'token required']);
        exit;
    }
    if (!hash_equals((string) $campaign['token'], (string) $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'invalid token']);
        exit;
    }
}

// --- Collect visitor data ---
function clickGetClientIp()
{
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function clickNormalizeGeoString($value, $default = '')
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

function clickFillGeoData(array &$target, array $source)
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

function clickGetGeoData($ip)
{
    orbitraGeoMigrateMisplacedProxy(__DIR__);

    $geo = [
        'country_code' => 'Unknown',
        'region' => '',
        'city' => '',
        'latitude' => null,
        'longitude' => null,
        'zipcode' => '',
        'timezone' => ''
    ];

    if (in_array($ip, ['127.0.0.1', '::1'])) {
        $geo['country_code'] = 'Local';
        return $geo;
    }

    $ip2locCandidates = [
        __DIR__ . '/geo/IP2LOCATION-LITE-DB11.BIN',
        __DIR__ . '/geo/IP2LOCATION-LITE.BIN', // legacy path
    ];
    $ip2locDb = null;
    foreach ($ip2locCandidates as $candidate) {
        if (file_exists($candidate)) {
            $ip2locDb = $candidate;
            break;
        }
    }

    $ip2locHeader = $ip2locDb ? orbitraGeoBinHeader($ip2locDb) : null;
    if ($ip2locDb !== null && ($ip2locHeader['product_code'] ?? null) !== 2 && class_exists('\IP2Location\Database')) {
        try {
            $db = new \IP2Location\Database($ip2locDb, \IP2Location\Database::FILE_IO);
            $records = $db->lookup($ip, \IP2Location\Database::ALL);
            if ($records && is_array($records)) {
                clickFillGeoData($geo, [
                    'country_code' => clickNormalizeGeoString($records['countryCode'] ?? $records['country_code'] ?? '', ''),
                    'region' => clickNormalizeGeoString($records['regionName'] ?? $records['region_name'] ?? '', ''),
                    'city' => clickNormalizeGeoString($records['cityName'] ?? $records['city_name'] ?? '', ''),
                    'latitude' => $records['latitude'] ?? null,
                    'longitude' => $records['longitude'] ?? null,
                    'zipcode' => clickNormalizeGeoString($records['zipCode'] ?? $records['zipcode'] ?? '', ''),
                    'timezone' => clickNormalizeGeoString($records['timeZone'] ?? $records['timezone'] ?? '', ''),
                ]);
            }
        }
        catch (\Exception $e) {
        }
    }

    $maxMindDb = __DIR__ . '/geo/GeoLite2-City.mmdb';
    if (file_exists($maxMindDb) && class_exists('\GeoIp2\Database\Reader')) {
        try {
            $reader = new \GeoIp2\Database\Reader($maxMindDb);
            $record = $reader->city($ip);
            clickFillGeoData($geo, [
                'country_code' => clickNormalizeGeoString($record->country->isoCode ?? '', ''),
                'region' => clickNormalizeGeoString($record->mostSpecificSubdivision->name ?? '', ''),
                'city' => clickNormalizeGeoString($record->city->name ?? '', ''),
                'latitude' => $record->location->latitude ?? null,
                'longitude' => $record->location->longitude ?? null,
                'timezone' => clickNormalizeGeoString($record->location->timeZone ?? '', ''),
            ]);
        }
        catch (\Exception $e) {
        }
    }

    $sxGeoDat = __DIR__ . '/var/geoip/SxGeoCity/SxGeoCity.dat';
    $sxGeoParser = __DIR__ . '/core/SxGeo.php';
    if (file_exists($sxGeoDat) && file_exists($sxGeoParser)) {
        require_once $sxGeoParser;
        try {
            $sxGeoClass = 'SxGeo';
            if (class_exists($sxGeoClass)) {
                $sxGeo = new $sxGeoClass($sxGeoDat);
                $country = $sxGeo->getCountry($ip);
                clickFillGeoData($geo, [
                    'country_code' => clickNormalizeGeoString((string) $country, ''),
                ]);
            }
        }
        catch (\Exception $e) {
        }
    }

    if ($geo['country_code'] === 'Unknown' || $geo['region'] === '' || $geo['city'] === '') {
        $ch = curl_init("http://ip-api.com/json/{$ip}?fields=countryCode,regionName,city,lat,lon,zip,timezone");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        $response = curl_exec($ch);
        if ($response) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                clickFillGeoData($geo, [
                    'country_code' => clickNormalizeGeoString($data['countryCode'] ?? '', ''),
                    'region' => clickNormalizeGeoString($data['regionName'] ?? '', ''),
                    'city' => clickNormalizeGeoString($data['city'] ?? '', ''),
                    'latitude' => $data['lat'] ?? null,
                    'longitude' => $data['lon'] ?? null,
                    'zipcode' => clickNormalizeGeoString($data['zip'] ?? '', ''),
                    'timezone' => clickNormalizeGeoString($data['timezone'] ?? '', ''),
                ]);
            }
        }
    }

    if ($geo['country_code'] === '') {
        $geo['country_code'] = 'Unknown';
    }
    return $geo;
}

function clickGetDeviceType($ua)
{
    $mobileAgents = '/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i';
    if (preg_match($mobileAgents, strtolower($ua)))
        return 'Mobile';
    return 'Desktop';
}

function clickDetectOs($userAgent)
{
    $ua = strtolower($userAgent);
    if (strpos($ua, 'windows') !== false)
        return 'Windows';
    if (strpos($ua, 'android') !== false)
        return 'Android';
    if (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false || strpos($ua, 'ios') !== false)
        return 'iOS';
    if (strpos($ua, 'mac os') !== false || strpos($ua, 'macintosh') !== false)
        return 'macOS';
    if (strpos($ua, 'linux') !== false)
        return 'Linux';
    return 'Unknown';
}

function clickDetectBrowser($userAgent)
{
    $ua = strtolower($userAgent);
    if (strpos($ua, 'edg/') !== false)
        return 'Edge';
    if (strpos($ua, 'opr/') !== false || strpos($ua, 'opera') !== false)
        return 'Opera';
    if (strpos($ua, 'samsungbrowser') !== false)
        return 'Samsung Browser';
    if (strpos($ua, 'chrome/') !== false && strpos($ua, 'edg/') === false)
        return 'Chrome';
    if (strpos($ua, 'firefox/') !== false)
        return 'Firefox';
    if (strpos($ua, 'safari/') !== false && strpos($ua, 'chrome/') === false)
        return 'Safari';
    if (strpos($ua, 'trident/') !== false || strpos($ua, 'msie') !== false)
        return 'Internet Explorer';
    return 'Unknown';
}

function clickNormalizeLanguageCode($value)
{
    if (!is_string($value)) {
        return 'Unknown';
    }

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

function clickExtractLanguageCodes($headerValue)
{
    if (!is_string($headerValue)) {
        return [];
    }

    $result = [];
    foreach (explode(',', $headerValue) as $rawPart) {
        $normalized = clickNormalizeLanguageCode($rawPart);
        if ($normalized === 'Unknown') {
            continue;
        }
        if (!in_array($normalized, $result, true)) {
            $result[] = $normalized;
        }
    }
    return $result;
}

function clickDetectAcceptLanguageRaw()
{
    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return '';
    }
    return trim((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']);
}

function clickGenerateUuid()
{
    try {
        $data = random_bytes(16);
    }
    catch (\Exception $e) {
        // Fallback if random_bytes fails (rare)
        $data = openssl_random_pseudo_bytes(16);
    }
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

$ip = clickGetClientIp();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

// Wrap the click-logging path so a failure (geo lookup, DB insert, etc.)
// is reported as a JSON error + logged to system_logs, instead of a bare
// HTTP 500 with an empty body. A bare 500 is undebuggable from a client
// and looks like a broken tracker to integration scripts.
try {
$geoData = clickGetGeoData($ip);
$country = $geoData['country_code'];
$countryCode = $geoData['country_code'];
$region = $geoData['region'];
$city = $geoData['city'];
$latitude = $geoData['latitude'];
$longitude = $geoData['longitude'];
$zipcode = $geoData['zipcode'];
$timezone = $geoData['timezone'];
$deviceType = clickGetDeviceType($userAgent);
$os = clickDetectOs($userAgent);
$browser = clickDetectBrowser($userAgent);
$acceptLanguageRaw = clickDetectAcceptLanguageRaw();
$languageCodes = clickExtractLanguageCodes($acceptLanguageRaw);
$language = $languageCodes[0] ?? 'Unknown';
$clickId = clickGenerateUuid();

// Collect sub parameters. Same helper as index.php — the Click API must record the
// ad-network IDs (ad_id / adset_id / campaign_id) and fbclid too, otherwise cost
// import and Conversions API silently skip every click that came in this way.
require_once __DIR__ . '/core/ClickParams.php';
$clickParams = orbitraCollectClickParams($pdo, array_merge($_GET, $_POST), $_COOKIE, $campaign['source_id'] ?? null);
$parametersJson = json_encode($clickParams, JSON_UNESCAPED_UNICODE);

// Check stats_enabled setting
$stmtSetting = $pdo->query("SELECT value FROM settings WHERE key = 'stats_enabled'");
$statsEnabled = $stmtSetting ? ($stmtSetting->fetchColumn() !== '0') : true;

// Universal function for weighted selection locally in click.php
function clickSelectWeightedItem($items)
{
    if (empty($items))
        return null;
    $totalW = 0;
    foreach ($items as $it) {
        $w = (int) ($it['weight'] ?? 0);
        if ($w < 0) $w = 0;
        $totalW += $w;
    }
    if ($totalW > 0) {
        $rand = mt_rand(1, (int) $totalW);
        $curW = 0;
        foreach ($items as $item) {
            $curW += max(0, (int) ($item['weight'] ?? 0));
            if ($rand <= $curW) {
                return $item;
            }
        }
    }
    return reset($items);
}

// Find the default stream/offer for this campaign
$stmt = $pdo->prepare("SELECT * FROM streams WHERE campaign_id = ? AND is_active = 1 ORDER BY position ASC, id ASC");
$stmt->execute([$campaignId]);
$allStreams = $stmt->fetchAll();

$stream = null;
if (!empty($allStreams)) {
    if (($campaign['rotation_type'] ?? 'position') === 'weight') {
        $stream = clickSelectWeightedItem($allStreams);
    } else {
        $stream = reset($allStreams);
    }
}

// null (not 0) when there is no offer — clicks.offer_id is a FK to
// offers(id), and id=0 never exists, so a 0 here trips FOREIGN KEY
// constraint failed on campaigns that have no stream/offer assigned.
$offerId = null;
$streamId = null;
$sourceId = $campaign['source_id'] ?? null;
$offerUrl = '';

if ($stream) {
    $streamId = $stream['id'];
    $customSchema = json_decode($stream['schema_custom_json'] ?? '{}', true);

    // Get offer from schema or legacy field
    if (!empty($customSchema['offers'][0]['id'])) {
        $offerId = $customSchema['offers'][0]['id'];
    }
    elseif ($stream['offer_id']) {
        $offerId = $stream['offer_id'];
    }

    if ($offerId) {
        $stmt = $pdo->prepare("SELECT url FROM offers WHERE id = ?");
        $stmt->execute([$offerId]);
        $offer = $stmt->fetch();
        if ($offer)
            $offerUrl = $offer['url'];
    }
}

// Debounce: prevent duplicate clicks within 2 seconds
$isDebounced = false;
$stmtDebounce = $pdo->prepare("SELECT id FROM clicks WHERE ip = ? AND campaign_id = ? AND created_at >= datetime('now', '-2 seconds') LIMIT 1");
$stmtDebounce->execute([$ip, $campaignId]);
if ($stmtDebounce->fetch()) {
    $isDebounced = true;
}

// Log click (a prefetch hit is served but never logged)
if ($statsEnabled && !$isDebounced && !$skipClickOnPrefetch) {
    $insertStmt = $pdo->prepare("
        INSERT INTO clicks (
            id, campaign_id, offer_id, stream_id, source_id, ip, user_agent, referer,
            country, country_code, region, city, latitude, longitude, zipcode, timezone,
            device_type, os, browser, language, accept_language_raw, parameters_json
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->execute([
        $clickId,
        $campaignId,
        $offerId,
        $streamId,
        $sourceId,
        $ip,
        $userAgent,
        $referer,
        $country,
        $countryCode,
        $region,
        $city,
        $latitude,
        $longitude,
        $zipcode,
        $timezone,
        $deviceType,
        $os,
        $browser,
        $language,
        $acceptLanguageRaw,
        $parametersJson
    ]);

    // Honesty flags for the report metrics — same helper the router uses.
    require_once __DIR__ . '/core/ClickFlags.php';
    orbitraWriteClickFlags($pdo, $clickId, $ip, $userAgent, $campaign ?? [], $streamId ?? 0, is_array($geoData ?? null) ? $geoData : []);
}

// Determine redirect behavior
$shouldRedirect = ($_GET['redirect'] ?? '1') !== '0';
$explicitUrl = $_GET['url'] ?? '';

if ($shouldRedirect) {
    if ($explicitUrl) {
        $parsed = parse_url($explicitUrl);
        $host = $parsed['host'] ?? '';

        // Security: Open Redirect protection
        $stmtUrls = $pdo->query("SELECT url FROM offers WHERE state = 'active' AND url IS NOT NULL");
        $offerUrls = $stmtUrls->fetchAll(PDO::FETCH_COLUMN);
        $allowedDomains = [];
        foreach ($offerUrls as $u) {
            $parsedCmp = parse_url($u);
            if (!empty($parsedCmp['host'])) {
                $allowedDomains[] = $parsedCmp['host'];
            }
        }

        if ($offerUrl) {
            $offerParsed = parse_url($offerUrl);
            if (!empty($offerParsed['host'])) {
                $allowedDomains[] = $offerParsed['host'];
            }
        }

        if (!in_array($host, $allowedDomains)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid redirect domain']);
            exit;
        }
    }

    $finalUrl = $explicitUrl ?: $offerUrl;

    if ($finalUrl) {
        // Replace macros
        $finalUrl = str_replace('{clickid}', $clickId, $finalUrl);
        foreach ($clickParams as $key => $val) {
            $finalUrl = str_replace('{' . $key . '}', urlencode((string)$val), $finalUrl);
        }

        if (!preg_match('#^(https?:)?//#i', $finalUrl)) {
            $finalUrl = 'http://' . ltrim($finalUrl, '/');
        }

        header('Location: ' . $finalUrl);
    }
    else {
        // No URL to redirect — return click_id
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode(['status' => 'ok', 'click_id' => $clickId]);
    }
}
else {
    // redirect=0 — just log the click and return JSON
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['status' => 'ok', 'click_id' => $clickId]);
}
} // end try
catch (\Throwable $e) {
    // Log the real cause to system_logs so it's visible from the API/panel,
    // and return a JSON error instead of a bare HTML 500.
    try {
        $logStmt = $pdo->prepare("INSERT INTO system_logs (level, message, context) VALUES ('error', ?, ?)");
        $logStmt->execute([
            'click.php failed: ' . $e->getMessage(),
            json_encode([
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
                'campaign_id' => $campaignId ?? null,
                'ip' => $ip ?? null,
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
            ], JSON_UNESCAPED_UNICODE)
        ]);
    } catch (\Throwable $logErr) { /* never let logging mask the original error */ }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
    }
    echo json_encode([
        'error' => 'click_log_failed',
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
}
