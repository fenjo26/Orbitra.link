<?php
// index.php - Обработчик кликов
require_once 'config.php';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Получение реального IP адреса
function getClientIp()
{
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
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

function normalizeGeoString($value, $default = '')
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

function fillGeoData(array &$target, array $source)
{
    $stringKeys = ['country_code', 'region', 'city', 'zipcode', 'timezone'];
    foreach ($stringKeys as $key) {
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

// Получение расширенных GEO данных из локальных БД
function getGeoData($ip)
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

    // 1. IP2Location (DB11) - приоритет для расширенных полей
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

    if ($ip2locDb && class_exists('\IP2Location\Database')) {
        try {
            $db = new \IP2Location\Database($ip2locDb, \IP2Location\Database::FILE_IO);
            $records = $db->lookup($ip, \IP2Location\Database::ALL);
            if ($records && is_array($records)) {
                fillGeoData($geo, [
                    'country_code' => normalizeGeoString($records['countryCode'] ?? $records['country_code'] ?? '', ''),
                    'region' => normalizeGeoString($records['regionName'] ?? $records['region_name'] ?? '', ''),
                    'city' => normalizeGeoString($records['cityName'] ?? $records['city_name'] ?? '', ''),
                    'latitude' => $records['latitude'] ?? null,
                    'longitude' => $records['longitude'] ?? null,
                    'zipcode' => normalizeGeoString($records['zipCode'] ?? $records['zipcode'] ?? '', ''),
                    'timezone' => normalizeGeoString($records['timeZone'] ?? $records['timezone'] ?? '', ''),
                ]);
            }
        } catch (\Exception $e) {
            // Фолбек при ошибке базы
        }
    }

    // 2. MaxMind
    $maxMindDb = __DIR__ . '/geo/GeoLite2-City.mmdb';
    $readerClass = '\GeoIp2\Database\Reader';
    if (file_exists($maxMindDb) && class_exists($readerClass)) {
        try {
            $reader = new $readerClass($maxMindDb);
            $record = $reader->city($ip);
            fillGeoData($geo, [
                // @phpstan-ignore-next-line
                'country_code' => normalizeGeoString($record->country->isoCode ?? '', ''),
                // @phpstan-ignore-next-line
                'region' => normalizeGeoString($record->mostSpecificSubdivision->name ?? '', ''),
                // @phpstan-ignore-next-line
                'city' => normalizeGeoString($record->city->name ?? '', ''),
                // @phpstan-ignore-next-line
                'latitude' => $record->location->latitude ?? null,
                // @phpstan-ignore-next-line
                'longitude' => $record->location->longitude ?? null,
                // @phpstan-ignore-next-line
                'timezone' => normalizeGeoString($record->location->timeZone ?? '', ''),
            ]);
        } catch (\Exception $e) {
            // Фолбек при ошибке базы (например, IP не найден)
        }
    }

    // 3. Sypex
    $sxGeoDat = __DIR__ . '/var/geoip/SxGeoCity/SxGeoCity.dat';
    $sxGeoParser = __DIR__ . '/core/SxGeo.php';
    $sxGeoClass = '\SxGeo';
    if (file_exists($sxGeoDat) && file_exists($sxGeoParser)) {
        require_once $sxGeoParser;
        try {
            if (class_exists($sxGeoClass)) {
                $sxGeo = new $sxGeoClass($sxGeoDat);
                // @phpstan-ignore-next-line
                $country = $sxGeo->getCountry($ip);
                fillGeoData($geo, [
                    'country_code' => normalizeGeoString((string) $country, ''),
                ]);
            }
        } catch (\Exception $e) {
            // Фолбек при ошибке базы
        }
    }

    // 4. Резервный внешний API (заполняет недостающие поля)
    if ($geo['country_code'] === 'Unknown' || $geo['city'] === '' || $geo['region'] === '') {
        $ch = curl_init("http://ip-api.com/json/{$ip}?fields=countryCode,regionName,city,lat,lon,zip,timezone");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        $response = curl_exec($ch);
        // curl_close() deprecated in PHP 8.5 - resources are auto-freed

        if ($response) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                fillGeoData($geo, [
                    'country_code' => normalizeGeoString($data['countryCode'] ?? '', ''),
                    'region' => normalizeGeoString($data['regionName'] ?? '', ''),
                    'city' => normalizeGeoString($data['city'] ?? '', ''),
                    'latitude' => $data['lat'] ?? null,
                    'longitude' => $data['lon'] ?? null,
                    'zipcode' => normalizeGeoString($data['zip'] ?? '', ''),
                    'timezone' => normalizeGeoString($data['timezone'] ?? '', ''),
                ]);
            }
        }
    }

    if ($geo['country_code'] === '') {
        $geo['country_code'] = 'Unknown';
    }
    return $geo;
}

// Определение типа устройства (упрощенно)
function getDeviceType($userAgent)
{
    $mobileAgents = '/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i';
    if (preg_match($mobileAgents, strtolower($userAgent))) {
        return 'Mobile';
    }
    return 'Desktop';
}

function detectOs($userAgent)
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

function detectBrowser($userAgent)
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
    return 'Unknown';
}

function normalizeLanguageCode($value)
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

function extractLanguageCodes($headerValue)
{
    if (!is_string($headerValue)) {
        return [];
    }

    $result = [];
    foreach (explode(',', $headerValue) as $rawPart) {
        $normalized = normalizeLanguageCode($rawPart);
        if ($normalized === 'Unknown') {
            continue;
        }
        if (!in_array($normalized, $result, true)) {
            $result[] = $normalized;
        }
    }
    return $result;
}

function detectAcceptLanguageRaw()
{
    if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return '';
    }
    return trim((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']);
}

// Генерация UUID v4 для click_id
function generateUuid()
{
    try {
        $data = random_bytes(16);
    } catch (\Exception $e) {
        // Fallback if random_bytes fails (rare)
        $data = openssl_random_pseudo_bytes(16);
    }
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// Маршрутизация:
// 1) campaign query param: site.com/r/my-camp
// 2) alias from root path: site.com/my-camp
// 3) direct campaign_id от паркованного домена
$alias = $_GET['campaign'] ?? '';
$directCampaignId = $_GET['campaign_id'] ?? null;
$fallbackCampaignId = $_GET['fallback_campaign_id'] ?? null;
$requestHost = $_SERVER['HTTP_HOST'] ?? '';

// === DOMAIN OVERRIDES & SECURITY ===
if ($requestHost) {
    // Look up the domain settings
    $stmt = $pdo->prepare("SELECT is_noindex, https_only FROM domains WHERE name = ? LIMIT 1");
    $stmt->execute([explode(':', $requestHost)[0]]); // Strip port if present
    $domainInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($domainInfo) {
        // Enforce HTTPS
        if (!empty($domainInfo['https_only'])) {
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
            if (!$isSecure) {
                // Determine the requested URL to reconstruct the HTTPS equivalent
                $redirectUrl = 'https://' . $requestHost . $_SERVER['REQUEST_URI'];
                header('HTTP/1.1 301 Moved Permanently');
                header('Location: ' . $redirectUrl);
                exit;
            }
        }

        // Enforce Noindex (Bot Blocking)
        if (!empty($domainInfo['is_noindex'])) {
            header('X-Robots-Tag: noindex, nofollow');

            // Intercept robots.txt directly
            $uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($uriPath === '/robots.txt') {
                header('Content-Type: text/plain');
                echo "User-agent: *\nDisallow: /\n";
                exit;
            }
        }
    }
}
// ===================================

// === PREVENT DOUBLE CLICKS FROM BACKGROUND FETCHES ===
$staticExts = '/\.(ico|png|jpg|jpeg|gif|css|js|woff|woff2|ttf|svg|map|webmanifest)$/i';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

// Keitaro-compatible Click API endpoint (v3).
// Must be handled here because the default Nginx config routes unknown paths to index.php.
if ($uriPath === '/click_api/v3' || $uriPath === '/click_api/v3/') {
    require_once __DIR__ . '/core/click_api.php';
    orbitraClickApiV3($pdo);
    exit;
}

if (preg_match($staticExts, $uriPath)) {
    http_response_code(404);
    exit;
}

// Fetch Dest Header Check (Modern browsers tell us if they just want an image)
if (isset($_SERVER['HTTP_SEC_FETCH_DEST'])) {
    $dest = $_SERVER['HTTP_SEC_FETCH_DEST'];
    if (in_array($dest, ['image', 'style', 'script', 'font', 'manifest'])) {
        http_response_code(404);
        exit;
    }
}
// =====================================================

if (empty($alias) && isset($_SERVER['REQUEST_URI'])) {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (preg_match('#^/r/([^/]+)$#', $uri, $matches)) {
        $alias = $matches[1];
    } elseif (preg_match('#^/([^/]+)$#', $uri, $matches)) {
        $candidate = $matches[1];
        $reservedPaths = ['admin', 'admin.php', 'api.php', 'click.php', 'postback.php', 'router.php', 'robots.txt', 'favicon.ico'];
        if (!in_array($candidate, $reservedPaths, true)) {
            $alias = $candidate;
        }
    }
}

if (empty($alias) && !$directCampaignId) {
    die("Campaign not specified.");
}

$campaign = null;

// 1. Поиск кампании. Сначала по алиасу, затем по прямому ID (fallback для 404)
if (!empty($alias)) {
    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE alias = ? LIMIT 1");
    $stmt->execute([$alias]);
    $campaign = $stmt->fetch();
}

if (!$campaign && $directCampaignId) {
    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ? LIMIT 1");
    $stmt->execute([$directCampaignId]);
    $campaign = $stmt->fetch();
}

if (!$campaign && $fallbackCampaignId) {
    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ? LIMIT 1");
    $stmt->execute([$fallbackCampaignId]);
    $campaign = $stmt->fetch();
}

if (!$campaign) {
    die("Campaign not found.");
}

$campaignId = $campaign['id'];

// 2. Сбор данных
$ip = getClientIp();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$geoData = getGeoData($ip);
$country = $geoData['country_code'];
$countryCode = $geoData['country_code'];
$region = $geoData['region'];
$city = $geoData['city'];
$latitude = $geoData['latitude'];
$longitude = $geoData['longitude'];
$zipcode = $geoData['zipcode'];
$timezone = $geoData['timezone'];
$deviceType = getDeviceType($userAgent);
$os = detectOs($userAgent);
$browser = detectBrowser($userAgent);
$acceptLanguageRaw = detectAcceptLanguageRaw();
$languageCodes = extractLanguageCodes($acceptLanguageRaw);
$language = $languageCodes[0] ?? 'Unknown';
$clickId = generateUuid();



// Extra tracking parameters extraction (Keitaro standards)
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
// Map traditional 'subid' to 'sub_id_1' if needed
if (isset($clickParams['subid']) && !isset($clickParams['sub_id_1'])) {
    $clickParams['sub_id_1'] = $clickParams['subid'];
}

$parametersJson = json_encode($clickParams, JSON_UNESCAPED_UNICODE);

// Проверка уникальности
$isUnique = 1;
if (!empty($campaign['uniqueness_hours']) && $campaign['uniqueness_hours'] > 0) {
    $timeAgo = date('Y-m-d H:i:s', time() - ($campaign['uniqueness_hours'] * 3600));
    $uniqCond = "ip = ?";
    $uniqParams = [$ip];
    if (($campaign['uniqueness_method'] ?? '') === 'IP_UA') {
        $uniqCond .= " AND user_agent = ?";
        $uniqParams[] = $userAgent;
    }

    $uniqStmt = $pdo->prepare("SELECT id FROM clicks WHERE campaign_id = ? AND " . $uniqCond . " AND created_at >= ? LIMIT 1");
    $stmtParams = array_merge([$campaignId], $uniqParams, [$timeAgo]);
    $uniqStmt->execute($stmtParams);
    if ($uniqStmt->fetch()) {
        $isUnique = 0;
    }
}

// Load system settings
$settings = [];
$stmtSets = $pdo->query("SELECT * FROM settings");
foreach ($stmtSets->fetchAll() as $row) {
    $settings[$row['key']] = $row['value'];
}

if (($settings['ignore_prefetch'] ?? '1') === '1') {
    if (
        (isset($_SERVER['HTTP_X_PURPOSE']) && $_SERVER['HTTP_X_PURPOSE'] == 'preview') ||
        (isset($_SERVER['HTTP_X_MOZ']) && $_SERVER['HTTP_X_MOZ'] == 'prefetch')
    ) {
        die("Prefetch ignored.");
    }
}

// 3. Выбор потока (Keitaro Logic: Intercepting -> Regular -> Fallback)
$stmt = $pdo->prepare("SELECT * FROM streams WHERE campaign_id = ? AND is_active = 1 ORDER BY position ASC, id ASC");
$stmt->execute([$campaignId]);
$allStreams = $stmt->fetchAll();

function isBot($pdo, $ip, $userAgent)
{
    $stmt = $pdo->prepare("SELECT id FROM bot_ips WHERE ip_or_cidr = ? LIMIT 1");
    $stmt->execute([$ip]);
    if ($stmt->fetch())
        return true;

    if ($userAgent) {
        $stmt = $pdo->prepare("SELECT id FROM bot_signatures WHERE ? LIKE '%' || signature || '%' LIMIT 1");
        $stmt->execute([$userAgent]);
        if ($stmt->fetch())
            return true;
    }
    return false;
}

function streamMatchesFilters($stream, $ip, $country, $deviceType, $languageCodes, $userAgent, $pdo)
{
    if (empty($stream['filters_json']))
        return true;
    $filters = json_decode($stream['filters_json'], true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($filters) || empty($filters))
        return true;

    foreach ($filters as $f) {
        $mode = $f['mode'] ?? 'include';
        $payload = $f['payload'] ?? [];
        if (empty($payload))
            continue;

        $matched = false;
        switch ($f['name']) {
            case 'Country':
                $matched = in_array($country, $payload);
                break;
            case 'Device':
                $matched = in_array($deviceType, $payload);
                break;
            case 'Bot':
                $matched = isBot($pdo, $ip, $userAgent);
                break;
            case 'Language':
                $payloadLanguages = [];
                foreach ($payload as $item) {
                    $normalized = normalizeLanguageCode((string) $item);
                    if ($normalized !== 'Unknown') {
                        $payloadLanguages[] = $normalized;
                    }
                }
                $matched = !empty(array_intersect($payloadLanguages, $languageCodes));
                break;
            default:
                $matched = true;
        }

        if ($mode === 'include' && !$matched)
            return false;
        if ($mode === 'exclude' && $matched)
            return false;
    }
    return true;
}

$selectedStream = null;

// Пытаемся найти перехватывающий
foreach ($allStreams as $stream) {
    if (($stream['type'] ?? 'regular') === 'intercepting') {
        if (streamMatchesFilters($stream, $ip, $country, $deviceType, $languageCodes, $userAgent, $pdo)) {
            $selectedStream = $stream;
            break;
        }
    }
}

// Если не найден перехватывающий, отбираем обычные
if (!$selectedStream) {
    $regular = array_filter($allStreams, function ($s) use ($ip, $country, $deviceType, $languageCodes, $userAgent, $pdo) {
        return ($s['type'] ?? 'regular') === 'regular' && streamMatchesFilters($s, $ip, $country, $deviceType, $languageCodes, $userAgent, $pdo);
    });

    if (!empty($regular)) {
        if (($campaign['rotation_type'] ?? 'position') === 'weight') {
            $selectedStream = selectWeightedItem($regular);
        } else {
            $selectedStream = reset($regular);
        }
    }
}

// Если не найден обычный, берем замыкающий
if (!$selectedStream) {
    foreach ($allStreams as $stream) {
        if (($stream['type'] ?? '') === 'fallback') {
            if (streamMatchesFilters($stream, $ip, $country, $deviceType, $languageCodes, $userAgent, $pdo)) {
                $selectedStream = $stream;
                break;
            }
        }
    }
}

// Универсальная функция для выбора элемента с весами
function selectWeightedItem($items)
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

// 4. Определение финального оффера/лендинга
$offerIdToLog = 0;
$landingIdToLog = null;
$finalUrl = '';
$actionToPerfrom = null;

if ($selectedStream) {
    $schemaType = $selectedStream['schema_type'] ?? 'redirect';
    $customSchema = json_decode($selectedStream['schema_custom_json'] ?? '{}', true);
    if (!is_array($customSchema))
        $customSchema = [];

    if ($schemaType === 'action') {
        $actionToPerfrom = $selectedStream['action_payload'] ?? 'do_nothing';
    } else if ($schemaType === 'landing_offer') {
        $selectedLanding = selectWeightedItem($customSchema['landings'] ?? []);
        $selectedOffer = selectWeightedItem($customSchema['offers'] ?? []);

        if ($selectedOffer)
            $offerIdToLog = $selectedOffer['id'] ?? 0;
        if ($selectedLanding)
            $landingIdToLog = $selectedLanding['id'] ?? null;

        $landingType = null;
        $landingUrl = null;
        $landingAction = null;
        $offerUrl = null;

        if ($landingIdToLog) {
            $stmt = $pdo->prepare("SELECT type, url, action_payload FROM landings WHERE id = ?");
            $stmt->execute([$landingIdToLog]);
            $land = $stmt->fetch();
            if ($land) {
                $landingType = $land['type'];
                $landingUrl = $land['url'];
                $landingAction = $land['action_payload'];
            }
        }

        if ($offerIdToLog) {
            $stmt = $pdo->prepare("SELECT url FROM offers WHERE id = ?");
            $stmt->execute([$offerIdToLog]);
            $off = $stmt->fetch();
            if ($off) {
                $offerUrl = $off['url'];
                if (!$landingIdToLog) {
                    $finalUrl = $offerUrl;
                }
            }
        }
    } else { // redirect
        $selectedOffer = selectWeightedItem($customSchema['offers'] ?? []);

        // Fallback to legacy offer_id if no weighted array is provided
        if ($selectedOffer) {
            $offerIdToLog = $selectedOffer['id'] ?? 0;
        } else {
            $offerIdToLog = $selectedStream['offer_id'] ?? 0;
        }

        $stmt = $pdo->prepare("SELECT url FROM offers WHERE id = ?");
        $stmt->execute([$offerIdToLog]);
        $offer = $stmt->fetch();
        if ($offer) {
            $finalUrl = $offer['url'];
            $offerUrl = $offer['url'];
        }
    }
}

// 5. Логирование клика
$statsEnabled = isset($settings['stats_enabled']) ? (int) $settings['stats_enabled'] : 1;

$streamIdToLog = $selectedStream['id'] ?? null;
$sourceIdToLog = $campaign['source_id'] ?? null;

// Browser Debounce: Prevent double-logging when browsers fire duplicate background requests rapidly
$isDebounced = false;
$stmtDebounce = $pdo->prepare("SELECT id FROM clicks WHERE ip = ? AND campaign_id = ? AND created_at >= datetime('now', '-2 seconds') LIMIT 1");
$stmtDebounce->execute([$ip, $campaignId]);
if ($stmtDebounce->fetch()) {
    $isDebounced = true;
}

if ($statsEnabled && !$isDebounced) {
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
        $streamIdToLog,
        $sourceIdToLog,
        $landingIdToLog,
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
}

if (!$selectedStream) {
    die("Do nothing.");
}

// 6. Редирект или Выполнение действия
if ($actionToPerfrom) {
    if ($actionToPerfrom === 'not_found') {
        http_response_code(404);
        die("404 Not Found");
    } else if ($actionToPerfrom === 'show_html') {
        die("<h1>Default HTML Content</h1>");
    } else {
        die("Do nothing.");
    }
} else {
    $offerUrlMacros = str_replace('{clickid}', $clickId, $offerUrl ?? '');

    if (isset($landingType) && $landingType !== 'redirect') {
        if ($landingType === 'local') {
            $landingDir = __DIR__ . '/api/landings/' . $landingIdToLog;
            if (file_exists($landingDir . '/index.php')) {
                require $landingDir . '/index.php';
            } else if (file_exists($landingDir . '/index.html')) {
                echo file_get_contents($landingDir . '/index.html');
            } else {
                die("Local landing files not found in " . $landingDir);
            }
            exit;
        } else if ($landingType === 'action') {
            $payload = str_replace(
                ['{clickid}', '{offer_id}', '{offer}'],
                [$clickId, $offerIdToLog, $offerUrlMacros],
                $landingAction
            );
            echo $payload;
            exit;
        } else if ($landingType === 'preload') {
            $url = str_replace(
                ['{clickid}', '{offer_id}', '{offer}'],
                [$clickId, $offerIdToLog, $offerUrlMacros],
                $landingUrl
            );
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
            $html = curl_exec($ch);
            // curl_close() deprecated in PHP 8.5 - resources are auto-freed

            $baseTag = '<base href="' . htmlspecialchars($url) . '">';
            $htmlWithBase = preg_replace('/<head>/i', "<head>\n" . $baseTag, $html, 1);
            if ($htmlWithBase === $html) {
                $htmlWithBase = $baseTag . "\n" . $html;
            }
            echo $htmlWithBase;
            exit;
        }
    }

    if (!$finalUrl && isset($landingUrl) && $landingType === 'redirect') {
        $finalUrl = $landingUrl;
    }

    if (!$finalUrl) {
        die("URL not found.");
    }

    // Подстановка макросов
    // Подстановка макросов
    $finalUrl = str_replace('{clickid}', $clickId, $finalUrl);

    // Replace all extracted tracking parameters (e.g. {sub_id_1}, {keyword})
    if (!empty($clickParams)) {
        foreach ($clickParams as $key => $val) {
            $finalUrl = str_replace('{' . $key . '}', urlencode((string) $val), $finalUrl);
        }
    }

    if ($offerIdToLog) {
        $finalUrl = str_replace('{offer_id}', $offerIdToLog, $finalUrl);
        // If finalUrl is landingUrl, '{offer}' macro should point to the configured offer URL
        $finalUrl = str_replace('{offer}', urlencode($offerUrlMacros), $finalUrl);
    }

    // Ensure URL has a scheme (http/https) to prevent relative redirects back to the index
    if (!preg_match('#^(https?:)?//#i', $finalUrl) && !preg_match('#^/#', $finalUrl) && !preg_match('#^(mailto|tel):#i', $finalUrl)) {
        $finalUrl = 'http://' . ltrim($finalUrl, '/');
    }

    // Default behavior is redirect. Use redirect=0 for debug/integration checks.
    $shouldRedirect = ($_GET['redirect'] ?? '1') !== '0';
    if ($shouldRedirect) {
        header('Location: ' . $finalUrl, true, 302);
    } else {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode([
            'status' => 'ok',
            'click_id' => $clickId,
            'url' => $finalUrl
        ]);
    }
    exit;
}
