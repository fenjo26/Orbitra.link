<?php
// click.php — Lightweight click handler for integration scripts
// Accepts campaign_id, records click, and optionally redirects to offer URL
// Usage: /click.php?campaign_id=1&sub1=value&redirect=0

require_once 'config.php';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$campaignId = $_GET['campaign_id'] ?? null;

if (!$campaignId) {
    http_response_code(400);
    echo json_encode(['error' => 'campaign_id required']);
    exit;
}

// Look up campaign
$stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ? LIMIT 1");
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch();

if (!$campaign) {
    http_response_code(404);
    echo json_encode(['error' => 'Campaign not found']);
    exit;
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

    foreach (['latitude', 'longitude'] as $key) {
        if ($target[$key] === null && isset($source[$key]) && is_numeric($source[$key])) {
            $target[$key] = (float) $source[$key];
        }
    }
}

function clickGetGeoData($ip)
{
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

    if ($ip2locDb !== null && class_exists('\IP2Location\Database')) {
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
$clickId = clickGenerateUuid();

// Collect sub parameters
$clickParams = [];
$standardKeys = ['keyword', 'cost', 'currency', 'external_id', 'creative_id', 'ad_campaign_id', 'source', 'subid'];
for ($i = 1; $i <= 30; $i++) {
    $standardKeys[] = 'sub' . $i;
    $standardKeys[] = 'sub_id_' . $i;
}
foreach ($standardKeys as $key) {
    if (isset($_GET[$key])) {
        $clickParams[$key] = $_GET[$key];
    }
}
if (isset($clickParams['subid']) && !isset($clickParams['sub_id_1'])) {
    $clickParams['sub_id_1'] = $clickParams['subid'];
}
$parametersJson = json_encode($clickParams, JSON_UNESCAPED_UNICODE);

// Check stats_enabled setting
$stmtSetting = $pdo->query("SELECT value FROM settings WHERE key = 'stats_enabled'");
$statsEnabled = $stmtSetting ? ($stmtSetting->fetchColumn() !== '0') : true;

// Find the default stream/offer for this campaign
$stmt = $pdo->prepare("SELECT * FROM streams WHERE campaign_id = ? AND is_active = 1 ORDER BY position ASC, id ASC LIMIT 1");
$stmt->execute([$campaignId]);
$stream = $stmt->fetch();

$offerId = 0;
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

// Log click
if ($statsEnabled && !$isDebounced) {
    $insertStmt = $pdo->prepare("
        INSERT INTO clicks (
            id, campaign_id, offer_id, stream_id, source_id, ip, user_agent, referer,
            country, country_code, region, city, latitude, longitude, zipcode, timezone,
            device_type, os, browser, parameters_json
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        $parametersJson
    ]);
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
