<?php
// index.php - Обработчик кликов
require_once 'config.php';

// Landing assets debug mode. Set to true via environment variable or setting
// to diagnose issues with CSS/JS loading on landing pages.
// Enable with: putenv('ORBITRA_LANDING_DEBUG=1') or in settings table
$orbitraLandingDebug = (filter_var(getenv('ORBITRA_LANDING_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN) ||
                        (!empty($_GET['orbitra_debug_assets']) && $_GET['orbitra_debug_assets'] === '1'));

// Keep tracker diagnostics in one predictable application-owned file. Without
// this, error_log() may land in an FPM/Apache journal whose path varies by host.
$orbitraLogDir = __DIR__ . '/var/logs';
if (!is_dir($orbitraLogDir)) {
    @mkdir($orbitraLogDir, 0775, true);
}
if (is_dir($orbitraLogDir) && is_writable($orbitraLogDir)) {
    ini_set('log_errors', '1');
    ini_set('error_log', $orbitraLogDir . '/php_errors.log');
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/core/geo_databases.php';
require_once __DIR__ . '/core/Device.php';
// Cloaking detector (datacenter/VPN ASN + UA heuristics + bot blocklists). Lazy: only
// consulted when a stream with schema_type='cloak' is selected.
require_once __DIR__ . '/core/CloakDetector.php';
// Shared prefetch / preload detection (ignore_prefetch setting), used here and by
// click.php / core/click_api.php so all three entry points behave identically.
require_once __DIR__ . '/core/prefetch.php';
// CRM lead vault. Loaded unconditionally: the LeadForge order.php bridge below
// executes inside this process and calls orbitraCrmRecordLead() directly, and
// the public /crm-ingest route answers stand-alone landings deployed elsewhere.
require_once __DIR__ . '/core/CrmVault.php';
// IP access control with secure Cloudflare-aware client IP resolution.
require_once __DIR__ . '/core/ip_access.php';
require_once __DIR__ . '/session_bootstrap.php';

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
    $stringKeys = ['country_code', 'region', 'city', 'zipcode', 'timezone', 'isp', 'asn'];
    foreach ($stringKeys as $key) {
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
        'timezone' => '',
        'isp' => '',
        'asn' => '',
        'is_proxy' => 0,
        'proxy_type' => '',
        'proxy_threat' => '',
        'proxy_provider' => '',
        'proxy_fraud_score' => null,
    ];

    if (in_array($ip, ['127.0.0.1', '::1'])) {
        $geo['country_code'] = 'Local';
        return $geo;
    }

    orbitraGeoMigrateMisplacedProxy(__DIR__);

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

    $ip2locHeader = $ip2locDb ? orbitraGeoBinHeader($ip2locDb) : null;
    if ($ip2locDb && ($ip2locHeader['product_code'] ?? null) !== 2 && class_exists('\IP2Location\Database')) {
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
                    // Present only in commercial IP2Location DBs with ISP field.
                    'isp' => normalizeGeoString($records['isp'] ?? '', ''),
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

    // 2b. MaxMind GeoLite2-ASN (бесплатная) — определяет сеть/провайдера (ISP).
    // Опциональна: если файла нет, поля isp/asn останутся пустыми и фильтр ISP
    // просто пропускает трафик.
    if ($geo['isp'] === '' || $geo['asn'] === '') {
        $asnDb = __DIR__ . '/geo/GeoLite2-ASN.mmdb';
        if (file_exists($asnDb) && class_exists($readerClass)) {
            try {
                $asnReader = new $readerClass($asnDb);
                // @phpstan-ignore-next-line
                $asnRecord = $asnReader->asn($ip);
                // @phpstan-ignore-next-line
                $org = normalizeGeoString($asnRecord->autonomousSystemOrganization ?? '', '');
                // @phpstan-ignore-next-line
                $asNumber = $asnRecord->autonomousSystemNumber ?? null;
                if ($geo['isp'] === '' && $org !== '') {
                    $geo['isp'] = $org;
                }
                if ($geo['asn'] === '' && $asNumber !== null) {
                    $geo['asn'] = 'AS' . $asNumber;
                }
            } catch (\Exception $e) {
                // IP не найден в ASN-базе — оставляем поля пустыми
            }
        }
    }

    // 2c. Optional IP2Location ASN LITE fallback. MaxMind remains first because
    // existing installations already use it, while this provider can be added
    // with the same IP2Location token or the universal upload control.
    if ($geo['asn'] === '' || $geo['isp'] === '') {
        $asnRecord = orbitraLookupIp2LocationAsn($ip, __DIR__);
        $asnNumber = normalizeGeoString($asnRecord['asn'] ?? '', '');
        $asName = normalizeGeoString($asnRecord['as'] ?? $asnRecord['asName'] ?? '', '');
        if ($geo['asn'] === '' && $asnNumber !== '') {
            $geo['asn'] = stripos($asnNumber, 'AS') === 0 ? $asnNumber : 'AS' . $asnNumber;
        }
        if ($geo['isp'] === '' && $asName !== '') {
            $geo['isp'] = $asName;
        }
    }

    // 2d. IP2Proxy is not a geolocation replacement. Its dedicated parser adds
    // explicit VPN/proxy/datacenter signals for the cloak detector.
    $proxyRecord = orbitraLookupIp2Proxy($ip, __DIR__);
    if (!empty($proxyRecord)) {
        $geo['is_proxy'] = (int) ($proxyRecord['isProxy'] ?? 0);
        $geo['proxy_type'] = normalizeGeoString($proxyRecord['proxyType'] ?? '', '');
        $geo['proxy_threat'] = normalizeGeoString($proxyRecord['threat'] ?? '', '');
        $geo['proxy_provider'] = normalizeGeoString($proxyRecord['provider'] ?? '', '');
        $fraudScore = $proxyRecord['fraudScore'] ?? null;
        $geo['proxy_fraud_score'] = is_numeric($fraudScore) ? (int) $fraudScore : null;

        if ($geo['isp'] === '') {
            $geo['isp'] = normalizeGeoString($proxyRecord['isp'] ?? $proxyRecord['as'] ?? '', '');
        }
        if ($geo['asn'] === '') {
            $proxyAsn = normalizeGeoString($proxyRecord['asn'] ?? '', '');
            if ($proxyAsn !== '') {
                $geo['asn'] = stripos($proxyAsn, 'AS') === 0 ? $proxyAsn : 'AS' . $proxyAsn;
            }
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

    // 4. Geo enrichment from external APIs is DISABLED in the click path.
    //
    // The condition below used to call ip-api.com synchronously while the visitor
    // waited, which caused stalls when the API throttled (45 req/min free tier)
    // or when DNS resolution exceeded the timeout. Moving enrichment out of the
    // request path is required for stable click handling.
    //
    // Future: a queue + cron worker pattern (like the S2S postback queue) can
    // backfill city/region on stored clicks. The worker would:
    // - Cache results per IP with a TTL
    // - Be opt-in with a configurable endpoint
    // - Include CURLOPT_CONNECTTIMEOUT + circuit breaker
    //
    // For now, serve from local databases only. An incomplete city/region is
    // acceptable; a blocking request to a third party is not.

    if ($geo['country_code'] === '') {
        $geo['country_code'] = 'Unknown';
    }
    return $geo;
}

// Canonical device taxonomy shared with click.php and the Click API.
function getDeviceType($userAgent)
{
    return orbitraDetectDeviceType((string) $userAgent);
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

// Replace tracking macros in an offer URL and ensure it has a scheme.
function applyOfferMacros($url, $clickId, $offerId, $params)
{
    $url = str_replace('{clickid}', $clickId, (string) $url);
    if (!empty($params) && is_array($params)) {
        foreach ($params as $key => $val) {
            $url = str_replace('{' . $key . '}', urlencode((string) $val), $url);
        }
    }
    if ($offerId) {
        $url = str_replace('{offer_id}', (string) $offerId, $url);
    }
    // Ensure URL has a scheme to prevent a relative redirect back to the tracker.
    if (!preg_match('#^(https?:)?//#i', $url) && !preg_match('#^/#', $url) && !preg_match('#^(mailto|tel):#i', $url)) {
        $url = 'http://' . ltrim($url, '/');
    }
    return $url;
}

// Render the visitor response for a final destination URL according to the offer's
// redirect_type. The default ("redirect") is the classic HTTP 302. The other types
// return an HTML document so the browser performs the navigation client-side — useful
// when an ad network blocks server-side redirects or when the destination needs to
// receive data in the request body. $url is assumed already macro-substituted.
function renderRedirectResponse($type, $url)
{
    $type = strtolower((string) ($type ?? ''));
    if ($type === '' || $type === 'redirect') {
        header('Location: ' . $url, true, 302);
        return;
    }

    $escUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $jsUrl  = json_encode($url); // safely quoted for embedding in a JS string literal

    if ($type === 'js') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex,nofollow"><title></title>'
            . '<script>window.location.href=' . $jsUrl . ';</script>'
            . '</head><body></body></html>';
        return;
    }

    if ($type === 'meta_refresh') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<meta http-equiv="refresh" content="0;url=' . $escUrl . '">'
            . '<title></title></head><body></body></html>';
        return;
    }

    if ($type === 'iframe' || $type === 'frame') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex,nofollow"><title></title>'
            . '<style>html,body{margin:0;padding:0;height:100%;overflow:hidden}'
            . 'iframe{border:0;width:100vw;height:100vh;position:fixed;inset:0}</style>'
            . '</head><body><iframe src="' . $escUrl . '" allowfullscreen></iframe>'
            . '</body></html>';
        return;
    }

    if ($type === 'form_submit') {
        // Move the query string into hidden POST fields so the destination receives
        // the data in the request body rather than the URL.
        $parsed  = parse_url($url);
        $scheme  = $parsed['scheme'] ?? 'https';
        // Keep userinfo/port — dropping the port silently sent the visitor to :443
        // for offers hosted on a non-standard port.
        $authority = '';
        if (isset($parsed['user']) && $parsed['user'] !== '') {
            $authority .= $parsed['user'];
            if (isset($parsed['pass']) && $parsed['pass'] !== '') {
                $authority .= ':' . $parsed['pass'];
            }
            $authority .= '@';
        }
        $authority .= ($parsed['host'] ?? '');
        if (isset($parsed['port']) && $parsed['port']) {
            $authority .= ':' . (int) $parsed['port'];
        }
        $action = $scheme . '://' . $authority . ($parsed['path'] ?? '');
        parse_str($parsed['query'] ?? '', $qsFields);
        // parse_str() turns "a[]=1&a[]=2" into an array; emit one field per value so
        // nothing is lost and (string) never gets handed an array.
        $hidden = '';
        $addField = function ($name, $value) use (&$hidden) {
            $hidden .= '<input type="hidden" name="' . htmlspecialchars((string) $name, ENT_QUOTES) . '"'
                . ' value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '">';
        };
        foreach ($qsFields as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $sub) {
                    if (!is_array($sub)) {
                        $addField($name . '[]', $sub);
                    }
                }
            } else {
                $addField($name, $value);
            }
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex,nofollow"><title></title></head><body>'
            . '<form id="orbitra-redirect-form" method="POST" action="' . htmlspecialchars($action, ENT_QUOTES) . '">'
            . $hidden . '</form>'
            . '<script>document.getElementById("orbitra-redirect-form").submit();</script>'
            . '</body></html>';
        return;
    }

    if ($type === 'curl_proxy') {
        // Serve a remote page through this server with a <base> tag so its relative
        // assets resolve. Mirrors the landings "preload" behaviour, applied to an offer.
        //
        // This is the one place the tracker makes a server-side request to a stored URL,
        // so it gets the same SSRF guard as outbound postbacks: http/https only, and the
        // target must not resolve to a private or reserved address. Offer URLs are
        // admin-set, but an admin account should not be a pivot into the LAN.
        $proxyHost = parse_url($url, PHP_URL_HOST);
        $proxyScheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $proxyIp = $proxyHost ? @gethostbyname($proxyHost) : '';
        $proxyAllowed = $proxyHost
            && ($proxyScheme === 'http' || $proxyScheme === 'https')
            && filter_var($proxyIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        if (!$proxyAllowed) {
            // Don't silently proxy something we couldn't vet — fall back to a plain redirect.
            header('Location: ' . $url, true, 302);
            return;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $html = (string) curl_exec($ch);
        // curl_close() deprecated in PHP 8.5 - resources are auto-freed

        if ($html === '') {
            // Upstream gave us nothing; a blank page is worse than a redirect.
            header('Location: ' . $url, true, 302);
            return;
        }

        $baseTag = '<base href="' . $escUrl . '">';
        $htmlWithBase = preg_replace('/<head>/i', "<head>\n" . $baseTag, $html, 1);
        if ($htmlWithBase === $html) {
            $htmlWithBase = $baseTag . "\n" . $html;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $htmlWithBase;
        return;
    }

    // Unknown type — fall back to the safe default.
    header('Location: ' . $url, true, 302);
}

/**
 * Stream a file directly with Range request support.
 * Used as fallback when X-Accel-Redirect is not available or nginx config is missing.
 *
 * @param string $file Absolute path to the file
 * @param string $mimeType MIME type of the file
 */
function orbitraStreamAssetFile(string $file, string $mimeType): void
{
    $size = filesize($file);
    $mtime = filemtime($file);
    $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';

    header('Content-Type: ' . $mimeType);
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Cache-Control: public, max-age=3600');

    $ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    $ifModifiedSince = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '') ?: 0;
    if ($ifNoneMatch === $etag || ($ifNoneMatch === '' && $ifModifiedSince >= $mtime)) {
        http_response_code(304);
        exit;
    }

    // Range request support
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '') {
        $parts = explode('-', substr($range, 6)); // "bytes="
        $start = (int)($parts[0] ?? 0);
        $end = (int)($parts[1] ?? ($size - 1));
        if ($end >= $size) {
            $end = $size - 1;
        }
        $length = $end - $start + 1;

        header('HTTP/1.1 206 Partial Content');
        header('Content-Length: ' . $length);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);

        $fp = fopen($file, 'rb');
        fseek($fp, $start);
        echo fread($fp, $length);
        fclose($fp);
        exit;
    }

    header('Content-Length: ' . $size);
    readfile($file);
    exit;
}

/**
 * Serve a file belonging to a local landing, addressed from the domain root.
 *
 * Local landings are printed at the campaign URL while their files sit in
 * /landings/<id>/, so "<img src="a.png">" arrives here as a request for "/a.png".
 * If the visitor's orbitra_lp cookie points at a landing that owns that file, we
 * serve it and exit; otherwise we return and let the caller 404 as before.
 *
 * Range requests are honoured because Safari refuses to play a <video> whose
 * source cannot answer with 206, and landing pages routinely embed mp4.
 */
function serveLandingAsset($landingId, $uriPath, $baseDir = null)
{
    static $mimeTypes = [
        'ico' => 'image/x-icon',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'mjs' => 'application/javascript',
        'json' => 'application/json',
        'map' => 'application/json',
        'webmanifest' => 'application/manifest+json',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'm4v' => 'video/x-m4v',
        'ogv' => 'video/ogg',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'm4a' => 'audio/mp4',
        'txt' => 'text/plain',
        'pdf' => 'application/pdf',
    ];

    if ($landingId <= 0) {
        return;
    }

    // Whitelist by extension: this must never hand back .php, .htaccess or the
    // tracker's own files, whatever the landing directory happens to contain.
    $ext = strtolower(pathinfo(parse_url($uriPath, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    if ($ext === '' || !isset($mimeTypes[$ext])) {
        return;
    }

    // $pdo is global here (index.php bootstraps config.php which builds it);
    // orbitraLandingDir resolves the landing's slug→dir, falling back to id.
    // $baseDir overrides the resolution — local offers pass offers/<id>.
    $assetPdo = $GLOBALS['pdo'] ?? null;
    if (is_string($baseDir)) {
        $resolved = rtrim($baseDir, '/');
    } elseif ($assetPdo instanceof PDO) {
        $resolved = orbitraLandingDir($assetPdo, $landingId);
    } else {
        $resolved = __DIR__ . '/landings/' . (int) $landingId;
    }
    $root = realpath($resolved);
    if ($root === false) {
        return;
    }

    // realpath() collapses "..", so the prefix check below is what actually
    // contains the request — a crafted "/../../config.php" cannot escape.
    $file = realpath($root . '/' . ltrim(rawurldecode($uriPath), '/'));
    if ($file === false || !is_file($file) || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) {
        // Legacy single-nested archives: retry the subdirectory that holds the
        // index, so pages served from there get their css/js/images too. The
        // containment check applies to the candidate exactly as to the root hit.
        $nestedHit = false;
        foreach ((array) glob($root . '/*', GLOB_ONLYDIR) as $sub) {
            $subBase = basename($sub);
            if ($subBase === '__MACOSX' || $subBase[0] === '.') {
                continue;
            }
            $candidate = realpath($sub . '/' . ltrim(rawurldecode($uriPath), '/'));
            if ($candidate !== false && is_file($candidate) && strpos($candidate, $root . DIRECTORY_SEPARATOR) === 0) {
                $file = $candidate;
                $nestedHit = true;
                break;
            }
        }
        if (!$nestedHit) {
            return;
        }
    }

    $size = filesize($file);
    $mtime = filemtime($file);
    $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';

    header('Content-Type: ' . $mimeTypes[$ext]);
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    // Short TTL rather than "immutable": re-uploading a landing keeps its id, so
    // a year-long cache would pin visitors to the files it replaced.
    header('Cache-Control: public, max-age=3600');

    $ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    $ifModifiedSince = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '') ?: 0;
    if ($ifNoneMatch === $etag || ($ifNoneMatch === '' && $ifModifiedSince >= $mtime)) {
        http_response_code(304);
        exit;
    }

    // ORB-013: Use X-Accel-Redirect to hand off file serving to nginx.
    // Previously, PHP read and echoed the file with readfile(), which kept
    // a PHP-FPM worker occupied for the entire transfer. With 30 assets on a
    // landing page, this meant 30 concurrent PHP processes and 30 database
    // connections for a single page view.
    //
    // Now, PHP resolves the path (with all security checks intact), sends an
    // X-Accel-Redirect header to an internal nginx location, and exits immediately.
    // nginx serves the file with sendfile (zero-copy) and PHP is free to handle
    // the next request.
    //
    // The internal location is configured in nginx config:
    // location /_internal_assets/ {
    //     internal;
    //     alias /var/www/orbitra/;
    // }

    $docRoot = realpath(__DIR__);
    if ($docRoot === false) {
        // Fallback to direct serving if we can't resolve the docroot
        if ($GLOBALS['orbitraLandingDebug'] ?? false) {
            header('X-Orbitra-Asset-Fallback: docroot-failed');
        }
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return;
        }
        fpassthru($handle);
        fclose($handle);
        exit;
    }

    // Build the internal path for nginx X-Accel-Redirect
    // The path must be relative to the document root
    $internalPath = str_replace($docRoot . '/', '', $file);
    // URL-encode the path for the header
    $internalPath = implode('/', array_map('rawurlencode', explode('/', $internalPath)));

    // Debug headers for troubleshooting landing asset issues
    // Enable with: putenv('ORBITRA_LANDING_DEBUG=1') or add ?orbitra_debug_assets=1 to URL
    if ($GLOBALS['orbitraLandingDebug'] ?? false) {
        header('X-Orbitra-Asset-Debug: 1');
        header('X-Orbitra-Asset-File: ' . $file);
        header('X-Orbitra-Asset-Internal: /_internal_assets/' . $internalPath);
        header('X-Orbitra-Asset-Size: ' . $size);
        header('X-Orbitra-Asset-LandingId: ' . $landingId);
    }

    // ORB-013: Automatic fail-safe for broken nginx configs.
    // Detect and guard against the nested regex bug that causes 500 redirect loops.
    // After git pull, users get fixed nginx templates BUT /etc/nginx stays broken
    // until nginx_sync.php runs. This detection ensures immediate asset delivery.
    if (!defined('ORBITRA_NGINX_CONFIG_PATH')) {
        define('ORBITRA_NGINX_CONFIG_PATH', '/etc/nginx/sites-available/orbitra');
    }
    if (file_exists(ORBITRA_NGINX_CONFIG_PATH)) {
        $config = @file_get_contents(ORBITRA_NGINX_CONFIG_PATH);
        if ($config !== false) {
            // Check 1: Missing _internal_assets location at all
            if (strpos($config, 'location /_internal_assets/') === false) {
                if ($GLOBALS['orbitraLandingDebug'] ?? false) {
                    header('X-Orbitra-Asset-Source: php_stream');
                    header('X-Orbitra-Asset-Fallback: nginx_config_missing');
                }
                orbitraStreamAssetFile($file, $mimeTypes[$ext]);
            }
            // Check 2: BROKEN CONFIG - nested regex inside _internal_assets causes 500 loop!
            // Pattern matches: location /_internal_assets/ { ... location ~* ... }
            // This breaks alias inheritance and causes redirect loops → 500 errors
            elseif (preg_match('#location\s+/_internal_assets/\s*\{[^}]*location\s*~[*\s]#s', $config)) {
                if ($GLOBALS['orbitraLandingDebug'] ?? false) {
                    header('X-Orbitra-Asset-Source: php_stream');
                    header('X-Orbitra-Asset-Fallback: broken_nested_regex_detected');
                }
                orbitraStreamAssetFile($file, $mimeTypes[$ext]);
            }
        }
    }

    // Check if we should use fallback mode (nginx not synced or non-standard port)
    // Enable via environment variable or when X-Accel-Redirect is known to fail
    $useFallback = (getenv('ORBITRA_ASSET_FALLBACK') === '1');

    if ($useFallback) {
        // Direct file serving as fallback when nginx is not configured
        if ($GLOBALS['orbitraLandingDebug'] ?? false) {
            header('X-Orbitra-Asset-Fallback: forced-fallback-mode');
        }
        header('Content-Length: ' . $size);
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return;
        }
        fpassthru($handle);
        fclose($handle);
        exit;
    }

    header('X-Accel-Redirect: /_internal_assets/' . $internalPath);
    header('Content-Length: ' . $size);
    exit;
}

/**
 * The handler files inside an uploaded landing/offer bundle that the tracker
 * runs on their own URL.
 *
 * A LeadForge bundle is not a single page. The form posts to order.php, which
 * answers with a relative `Location: success.php` (thank_you.php on older
 * packs), and a few networks name their sender send.php, lucky.php or lemon.php
 * instead. Every one of them is a form handler no static server can run, so all
 * of them need the same in-process execution — miss one and the lead reaches the
 * network while the visitor still lands on a 404, which is exactly how
 * success.php behaved before it was on this list.
 *
 * api.php is included only for the routes that carry the bundle's id in the URL
 * (/offers/<id>/..., /lander/<slug>/...). The domain-root bridge must never
 * claim it: /api.php is the tracker's own admin API, and an Ezaff bundle — whose
 * sender is named api.php — would otherwise shadow it for every visitor holding
 * a bundle cookie.
 */
function orbitraBundleHandlers(bool $withApi = false): array
{
    $handlers = ['order.php', 'thank_you.php', 'success.php', 'send.php', 'lucky.php', 'lemon.php'];
    if ($withApi) {
        $handlers[] = 'api.php';
    }
    return $handlers;
}

/**
 * Serve a local landing at /lander/<slug>/, the way Keitaro does.
 *
 * Keitaro publishes a local landing at /lander/<name>/ and injects a <base> tag
 * into the served HTML so the page's own relative paths resolve inside that
 * directory — which is exactly why its documentation requires the landing not to
 * ship a <base> of its own. Orbitra's Folder field already advertised this URL,
 * but nothing answered it: a landing's files were reachable only during a real
 * click, through the orbitra_lp cookie. This is the route that makes the label
 * true, and what the editor's preview loads.
 *
 * Not a click: nothing is logged, no cookie is set, and {offer} has no stream to
 * resolve against, so it points at the campaign entry Keitaro uses for the same
 * job. PHP landing pages are not executed here — they need the click context this
 * route deliberately does not have. The LeadForge bundle handlers
 * (orbitraBundleHandlers()) are the exception: they are network actors, not
 * click-context pages, and answer their own POSTs under this URL like they do at
 * the root.
 */
function orbitraServeLanderPath(PDO $pdo, string $slug, string $rest): void
{
    $notFound = function () {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><html><head><title>404 Not Found</title></head><body><h1>404 Not Found</h1></body></html>";
        exit;
    };

    try {
        $stmt = $pdo->prepare("SELECT id, type FROM landings WHERE slug = ? AND is_archived = 0 LIMIT 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $row = null;
    }
    if (!$row || ($row['type'] ?? '') !== 'local') {
        $notFound();
    }

    $id = (int) $row['id'];
    $rest = trim(rawurldecode($rest), '/');
    if ($rest === '') {
        $rest = 'index.html';
    }

    // LeadForge rewrites a cloned form's action to a relative order.php, which
    // the <base> injected below resolves right back here: the form's POST URL
    // is /lander/<slug>/order.php, and the handler redirects to a relative
    // thank_you.php the same way. The asset whitelist a few lines down
    // deliberately never serves .php, so these two get the in-process
    // execution the domain-root bridge gives them (see the order-bridge block
    // in the main flow), gated by the same switch and budget. The slug in this
    // URL names the landing, so unlike the root bridge no cookie is involved.
    $bridgeFile = strtolower(basename($rest));
    if (in_array($bridgeFile, orbitraBundleHandlers(true), true)) {
        $bridgeRoot = realpath(orbitraLandingContentDir(orbitraLandingDir($pdo, $id)));
        $bridgeTarget = $bridgeRoot === false ? false : realpath($bridgeRoot . '/' . $rest);
        if ($bridgeTarget === false || !is_file($bridgeTarget)
            || strpos($bridgeTarget, $bridgeRoot . DIRECTORY_SEPARATOR) !== 0) {
            $notFound();
        }
        require_once __DIR__ . '/core/PhpLanding.php';
        if (!PhpLanding::enabled($pdo)) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'This page has an order handler written in PHP, which is disabled on this tracker. '
                . 'Enable it in Settings -> General -> "Allow PHP landings".';
            exit;
        }
        // Same floor the root bridge gives these files: they call the CPA
        // network (curl, up to ~15s) and the CRM vault before answering.
        @set_time_limit(max(PhpLanding::timeout($pdo), 25));
        require $bridgeTarget;
        exit;
    }

    // Anything that is not a page goes through the same extension whitelist and
    // path containment the click flow uses. serveLandingAsset() exits when it
    // serves and simply returns when it will not.
    if (!preg_match('/\.html?$/i', $rest)) {
        serveLandingAsset($id, '/' . $rest);
        $notFound();
    }

    $root = realpath(orbitraLandingDir($pdo, $id));
    if ($root === false) {
        $notFound();
    }
    $file = realpath($root . '/' . $rest);
    if ($file === false || !is_file($file) || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) {
        if ($rest === 'index.html' && is_file($root . '/index.php')) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'This landing is written in PHP. A PHP landing runs only inside a real click, '
                . 'where the tracker can give it the click context, so it cannot be previewed here.';
            exit;
        }
        $notFound();
    }

    $html = (string) file_get_contents($file);

    // The <base> Keitaro adds. Relative paths in the page ("img/a.png") resolve
    // under the landing's folder instead of the domain root, which is where they
    // would otherwise be requested from — and 404.
    $base = '<base href="/lander/' . htmlspecialchars($slug, ENT_QUOTES) . '/">';

    // Remove any existing <base> tag first to avoid conflicts.
    // Many landing pages have their own <base> pointing to their original domain,
    // which would break all relative paths. Ours must win.
    $html = preg_replace('/<base\s+[^>]*>/i', '', $html);

    if (preg_match('/<head[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $at = $m[0][1] + strlen($m[0][0]);
        $html = substr($html, 0, $at) . "\n" . $base . substr($html, $at);
    } else {
        $html = $base . "\n" . $html;
    }

    // No stream picked an offer for this view, so the macro resolves to the same
    // entry point a hand-written Keitaro landing uses.
    $html = str_replace('{offer}', '/?_lp=1', $html);

    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $html;
    exit;
}

/**
 * Sign a click id so a landing on another domain can prove which click it came from.
 *
 * The landing→offer link (/?_lp=1) resolves the visitor's click from the
 * orbitra_click cookie. That works for a local landing, which is served from the
 * tracker's own domain, but a redirect landing lives somewhere else entirely and
 * never sees that cookie — so its offer link had no way to identify the click and
 * simply failed. The tracker therefore appends _subid and _token to a redirect
 * landing's URL, and accepts the token in place of the cookie.
 *
 * Signed rather than just passed along: without a signature anyone could hand the
 * tracker an arbitrary click id and attribute their traffic to someone else's click.
 */
function issueLpToken($clickId, $secret, $ttl = 86400)
{
    $payload = base64_encode(json_encode(['c' => $clickId, 'e' => time() + (int) $ttl]));
    $payload = rtrim(strtr($payload, '+/', '-_'), '=');
    $sig = substr(hash_hmac('sha256', $payload, $secret), 0, 32);
    return $payload . '.' . $sig;
}

/** @return string|null the click id, or null if the token is forged, malformed or expired */
function verifyLpToken($token, $secret)
{
    if (!is_string($token) || strpos($token, '.') === false) {
        return null;
    }
    [$payload, $sig] = explode('.', $token, 2);
    $expected = substr(hash_hmac('sha256', $payload, $secret), 0, 32);
    if (!hash_equals($expected, (string) $sig)) {
        return null;
    }
    $json = base64_decode(strtr($payload, '-_', '+/') . str_repeat('=', (4 - strlen($payload) % 4) % 4), true);
    $data = $json === false ? null : json_decode($json, true);
    if (!is_array($data) || empty($data['c']) || empty($data['e'])) {
        return null;
    }
    if ((int) $data['e'] < time()) {
        return null;
    }
    return (string) $data['c'];
}

/**
 * Perform a landing/stream "action" and end the request.
 *
 * The five behaviours a tracker is expected to offer, shared by both places an
 * action can be configured: a stream with schema "action", and a landing of type
 * "action". Before this they diverged — a stream understood two of them and
 * silently fell through to "Do nothing." for anything else, while a landing just
 * echoed its payload as HTML whatever you meant by it.
 *
 * @param string $type    not_found | show_text | show_html | do_nothing | to_campaign
 * @param string $payload text/HTML to show, or the target campaign id
 */
function performTrackerAction($type, $payload = '')
{
    $type = trim((string) $type);
    // Anything unrecognised means an install from before action types existed,
    // where the payload was always treated as HTML.
    if ($type === '' || !in_array($type, ['not_found', 'show_text', 'show_html', 'do_nothing', 'to_campaign'], true)) {
        $type = $payload === '' ? 'do_nothing' : 'show_html';
    }

    switch ($type) {
        case 'not_found':
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>404 Not Found</h1></body></html>';
            exit;

        case 'show_text':
            header('Content-Type: text/plain; charset=utf-8');
            echo $payload;
            exit;

        case 'show_html':
            header('Content-Type: text/html; charset=utf-8');
            echo $payload;
            exit;

        case 'to_campaign':
            // Keitaro hands the visitor to another campaign without a redirect.
            // Doing that here would mean re-entering this script, which declares
            // its functions at file scope and cannot be included twice, so this is
            // a redirect instead. The click is recorded in both campaigns either
            // way; the visitor just makes one extra hop.
            $targetId = (int) $payload;
            if ($targetId <= 0) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Action "to_campaign" has no target campaign id configured.';
                exit;
            }
            $query = $_GET;
            unset($query['campaign'], $query['campaign_id'], $query['fallback_campaign_id'], $query['_lp']);
            $query['campaign_id'] = $targetId;
            header('Location: /?' . http_build_query($query), true, 302);
            exit;

        case 'do_nothing':
        default:
            // Nothing to render and nowhere to send them: answer without a body
            // so the browser leaves the page as it is.
            http_response_code(204);
            exit;
    }
}

/**
 * Substitute tracker macros in a local landing's HTML.
 *
 * Keitaro-compatible: a landing's buy button is written as
 *
 *     <a href="{offer}">Buy</a>
 *
 * and {offer} becomes the URL of the offer bound to this stream, with the click
 * id already in it. Without this the macro reached the browser verbatim and the
 * button led nowhere, which is the single most common thing to get wrong when
 * moving a landing over from Keitaro.
 *
 * Only the tracker's own macros are touched — never a blanket sweep of every
 * {...} in the page, or a landing using JS template literals, Vue or Angular
 * would be mangled. Anything not recognised is left exactly as it was.
 */
// === Local offers ===
// A local offer's uploaded archive lives in offers/<id>/ and is served inline
// at the moment the tracker would redirect to the offer — the same machinery
// local landings use (macros, PhpLanding, asset passthrough), one directory root.

/** Directory of a local offer's uploaded archive. */
function orbitraOfferDir($offerId)
{
    return __DIR__ . '/offers/' . (int) $offerId;
}

/**
 * Tell the PHP inside a local offer's bundle where that bundle lives.
 *
 * A local offer is served at the campaign's URL ("/pr6sxv41"), not at its own
 * directory, so nothing in $_SERVER tells a file in the bundle which URL prefix
 * its siblings are reachable under — __DIR__ answers that for the filesystem and
 * has no URL equivalent. These three constants are that equivalent, and a bundle
 * can use them while staying runnable on its own:
 *
 *     defined('ORBITRA_OFFER_URL') ? ORBITRA_OFFER_URL . 'order.php' : 'order.php'
 *
 * Defined once per request, before any bundle code runs. Outside Orbitra they are
 * simply undefined and the bundle keeps its relative paths.
 */
function orbitraDefineOfferContext(int $offerId, string $dir): void
{
    if (!defined('ORBITRA_OFFER_ID')) {
        define('ORBITRA_OFFER_ID', $offerId);
    }
    if (!defined('ORBITRA_OFFER_URL')) {
        define('ORBITRA_OFFER_URL', '/offers/' . $offerId . '/');
    }
    if (!defined('ORBITRA_OFFER_PATH')) {
        define('ORBITRA_OFFER_PATH', rtrim(strtr($dir, '\\', '/'), '/') . '/');
    }
}

/**
 * Point a served bundle's relative *.php form actions at the bundle's own URL.
 *
 * A local offer's page is printed at the campaign URL ("/pr6sxv41"), which has no
 * trailing segment, so the browser resolves the action LeadForge writes — a bare
 * "order.php" — against the domain root and posts the lead to /order.php. The
 * domain-root bridge does answer that, but only for a visitor whose bundle cookie
 * or Referer survived, and one network's sender is named api.php, which at the
 * root is the tracker's own admin API. Rewriting the action to
 * /offers/<id>/order.php puts the bundle's id in the URL itself, so the POST
 * needs neither cookie nor Referer and cannot collide with a tracker endpoint.
 *
 * Deliberately narrow. A <base> tag would do all of this in one line, but it also
 * turns every "#order" anchor into a navigation away from the campaign URL, and
 * those buttons are the whole interaction on a lead lander. So only form actions
 * are touched, only while they are relative, only when they point at a .php file,
 * and never when they climb out of the bundle with "..". An action that is
 * already absolute, external, or aimed at a static page is left exactly as it was.
 */
function orbitraAbsolutizeBundleActions($html, string $urlBase)
{
    if (!is_string($html) || $html === '' || stripos($html, '.php') === false) {
        return $html;
    }

    $resolve = static function ($value) use ($urlBase) {
        $trimmed = trim((string) $value);
        if ($trimmed === '' || $trimmed[0] === '/' || $trimmed[0] === '#' || $trimmed[0] === '?') {
            return null;
        }
        // A scheme ("https:", "mailto:", "javascript:"), a climb out of the
        // bundle, or a macro the tracker has not resolved: not ours to rewrite.
        if (strpos($trimmed, '..') !== false || strpos($trimmed, '{') !== false
            || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $trimmed)) {
            return null;
        }
        $path = explode('?', explode('#', $trimmed, 2)[0], 2)[0];
        if (!preg_match('/\.php$/i', $path)) {
            return null;
        }
        return $urlBase . preg_replace('#^\./#', '', $trimmed);
    };

    // The action itself, plus the lock attribute LeadForge's validation layer
    // restores it from when a cloned sender script overwrites it.
    $html = preg_replace_callback(
        '/<form\b[^>]*>/i',
        static function (array $tag) use ($resolve) {
            return preg_replace_callback(
                '/\b(data-leadforge-action-lock|action)\s*=\s*(["\'])(.*?)\2/is',
                static function (array $attr) use ($resolve) {
                    $next = $resolve($attr[3]);
                    return $next === null ? $attr[0] : $attr[1] . '=' . $attr[2] . $next . $attr[2];
                },
                $tag[0]
            );
        },
        $html
    ) ?? $html;

    // Inline copies of the senders LeadForge pins ("currentRequestModify =
    // 'order.php'", "form.action = 'order.php'"). An external .js keeps its
    // relative path and keeps working through the domain-root bridge.
    return preg_replace_callback(
        '/((?:currentRequestModify|\.action)\s*=\s*)(["\'])(.*?)\2/is',
        static function (array $m) use ($resolve) {
            $next = $resolve($m[3]);
            return $next === null ? $m[0] : $m[1] . $m[2] . $next . $m[2];
        },
        $html
    ) ?? $html;
}

/**
 * Rewrite absolute asset paths to relative paths for correct base tag resolution.
 * Converts: href="/css/style.css" → href="./css/style.css"
 * Skips: External URLs (http://, https://, //), protocols (mailto:, tel:), anchors (#)
 *
 * @param string $html The HTML content
 * @return string HTML with rewritten asset paths
 */
function orbitraRewriteAssetPaths(string $html): string
{
    if ($html === '') {
        return $html;
    }

    // Patterns to skip (external URLs, protocols, anchors, macros)
    $shouldSkip = function($path) {
        if ($path === '' || $path[0] === '#' || $path[0] === '?') {
            return true;
        }
        // URLs with scheme (http:, javascript:, mailto:, tel:)
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)) {
            return true;
        }
        // Protocol-relative URLs, parent paths, macros
        if (strpos($path, '//') === 0 || strpos($path, '..') !== false || strpos($path, '{') !== false) {
            return true;
        }
        return false;
    };

    $rewrite = function($matches) use ($shouldSkip) {
        $value = $matches[2];
        if ($shouldSkip($value)) {
            return $matches[0];
        }
        // Convert /path to ./path
        if (strpos($value, '/') === 0) {
            return $matches[1] . '="./' . ltrim($value, '/') . '"' . $matches[3];
        }
        return $matches[0];
    };

    // Process href, src, poster, cite, background, data-src
    $html = preg_replace_callback(
        '/\s(href|src|poster|data-src|cite|background)\s*=\s*(["\'])([^\2]+?)\2/i',
        $rewrite,
        $html
    ) ?? $html;

    return $html;
}

/**
 * Inject a <base> tag into HTML to ensure relative paths resolve correctly.
 * Used for local offers and landings served inline at campaign URLs,
 * where the browser is at /campaignAlias but assets live at /offers/<id>/
 * or /lander/<slug>/. Without <base>, all relative assets 404 in Incognito.
 *
 * @param string $html The HTML content
 * @param string $baseUrl The base URL (e.g., "/offers/123/" or "/lander/my-landing/")
 * @return string HTML with injected <base> tag
 */
function orbitraInjectBaseTag(string $html, string $baseUrl): string
{
    if ($html === '') {
        return $html;
    }

    // Remove any existing <base> tag first to avoid conflicts.
    // Many landing pages have their own <base> pointing to their original domain,
    // which would break all relative paths. Ours must win.
    $html = preg_replace('/<base\s+[^>]*>/i', '', $html);

    $base = '<base href="' . htmlspecialchars($baseUrl, ENT_QUOTES) . '">';

    // Insert after <head> tag if present, otherwise prepend to HTML
    if (preg_match('/<head[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $at = $m[0][1] + strlen($m[0][0]);
        $html = substr($html, 0, $at) . "\n" . $base . substr($html, $at);
    } else {
        // No <head> tag found - prepend to HTML
        $html = $base . "\n" . $html;
    }

    // Inject anchor link polyfill to fix smooth scrolling with <base> tag
    // The <base> tag breaks anchor links (<a href="#form">) by making them
    // reload the page. This JavaScript polyfill restores smooth scrolling.
    $anchorPolyfill = '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(\'a[href^="#"]\').forEach(function(link){link.addEventListener("click",function(e){var t=this.getAttribute("href").substring(1),el=document.getElementById(t);if(el){e.preventDefault();el.scrollIntoView({behavior:"smooth"});if(history.pushState){history.pushState(null,null,"#"+t);}}});});});</script>';

    // Inject before closing </body> tag, or at end if no body tag
    if (preg_match('/<\/body>/i', $html)) {
        $html = preg_replace('/<\/body>/i', $anchorPolyfill . '</body>', $html);
    } else {
        $html = $html . $anchorPolyfill;
    }

    return $html;
}

function orbitraOfferIsLocal(PDO $pdo, $offerId): bool
{
    static $cache = [];
    $offerId = (int) $offerId;
    if ($offerId <= 0) {
        return false;
    }
    if (!isset($cache[$offerId])) {
        try {
            $stmt = $pdo->prepare("SELECT is_local FROM offers WHERE id = ? LIMIT 1");
            $stmt->execute([$offerId]);
            $cache[$offerId] = ((int) ($stmt->fetchColumn() ?? 0)) === 1;
        } catch (\Throwable $e) {
            $cache[$offerId] = false;
        }
    }
    return $cache[$offerId];
}

/** Serve a local offer's index page; false when the caller should keep redirecting. */
function orbitraServeLocalOffer(PDO $pdo, $offerId, $clickId, array $clickParams, array $settings): bool
{
    $offerId = (int) $offerId;
    if ($offerId <= 0 || !orbitraOfferIsLocal($pdo, $offerId)) {
        return false;
    }
    $dir = orbitraOfferDir($offerId);
    if (!is_dir($dir)) {
        return false;
    }
    // Same nested-folder/statcache resolution as landings.
    $dir = orbitraLandingContentDir($dir);

    // Assets of this page resolve against the offer's directory; the landing
    // cookie must go, or the landing the visitor came from would answer instead.
    if (!headers_sent()) {
        $secure = orbitraIsHttps();
        $opts = ['expires' => time() + 86400, 'path' => '/', 'secure' => $secure, 'httponly' => false, 'samesite' => 'Lax'];
        setcookie('orbitra_lo', (string) $offerId, $opts);
        setcookie('orbitra_click', (string) $clickId, $opts);
        setcookie('orbitra_subid', (string) $clickId, $opts);
        setcookie('subid', (string) $clickId, $opts);
        setcookie('orbitra_lp', '', ['expires' => time() - 3600, 'path' => '/']);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['orbitra_lo'] = (string) $offerId;
        $_SESSION['orbitra_click'] = (string) $clickId;
        $_SESSION['orbitra_subid'] = (string) $clickId;
        $_SESSION['subid'] = (string) $clickId;
    }

    if (file_exists($dir . '/index.php')) {
        require_once __DIR__ . '/core/PhpLanding.php';
        if (!PhpLanding::enabled($pdo)) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'This offer is written in PHP, which is disabled on this tracker. '
                . 'Enable it in Settings -> General -> "Allow PHP landings" if you trust its code.';
            exit;
        }
        @set_time_limit(PhpLanding::timeout($pdo));
        orbitraDefineOfferContext($offerId, $dir);
        $rawClick = new OrbitraRawClick(array_merge(
            $clickParams,
            [
                'click_id' => $clickId,
                'subid' => $clickId,
                'offer_id' => $offerId,
            ]
        ));
        ob_start();
        require $dir . '/index.php';
        $content = ob_get_clean();
        $processed = orbitraAbsolutizeBundleActions(
            applyLandingMacros(
                $content,
                $clickId,
                $offerId,
                '',
                $clickParams,
                issueLpToken($clickId, $settings['postback_key'] ?? 'orbitra_secret')
            ),
            '/offers/' . $offerId . '/'
        );
        echo orbitraInjectBaseTag(orbitraRewriteAssetPaths($processed), '/offers/' . $offerId . '/');
        exit;
    }

    if (file_exists($dir . '/index.html')) {
        $processed = orbitraAbsolutizeBundleActions(
            applyLandingMacros(
                file_get_contents($dir . '/index.html'),
                $clickId,
                $offerId,
                '',
                $clickParams,
                issueLpToken($clickId, $settings['postback_key'] ?? 'orbitra_secret')
            ),
            '/offers/' . $offerId . '/'
        );
        echo orbitraInjectBaseTag(orbitraRewriteAssetPaths($processed), '/offers/' . $offerId . '/');
        exit;
    }

    // An archive without an index is not servable — let the caller decide.
    return false;
}

/**
 * A local offer's own address, /offers/<id>/ — the offer twin of /lander/<slug>/.
 * Cloaked streams that use a local offer as their Safe Page send bots here from
 * click.php and the Click API, which cannot serve the archive themselves. Same
 * rules as the lander route: not a click, nothing logged, PHP indexes are not
 * executed (no click context here) — except the LeadForge bundle handlers,
 * which answer their own POSTs under this URL. The orbitra_lo cookie is set
 * while serving, so the page's relative assets and the LeadForge order.php
 * bridge keep resolving for a visitor standing on this URL.
 */
function orbitraServeOfferPath(PDO $pdo, int $offerId, string $rest): void
{
    $notFound = function () {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><html><head><title>404 Not Found</title></head><body><h1>404 Not Found</h1></body></html>";
        exit;
    };

    if ($offerId <= 0) {
        $notFound();
    }
    try {
        $stmt = $pdo->prepare("SELECT is_local, state FROM offers WHERE id = ? LIMIT 1");
        $stmt->execute([$offerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $row = null;
    }
    if (!$row || (int) ($row['is_local'] ?? 0) !== 1 || ($row['state'] ?? '') === 'archived') {
        $notFound();
    }

    $root = realpath(orbitraLandingContentDir(orbitraOfferDir($offerId)));
    if ($root === false) {
        $notFound();
    }

    $rest = trim(rawurldecode($rest), '/');
    if ($rest === '') {
        $rest = 'index.html';
    }

    // Same order-bridge handling as /lander/<slug>/: a LeadForge form on this
    // page posts to a relative order.php the <base> below resolves to
    // /offers/<id>/order.php, and the handler's relative "Location: success.php"
    // resolves back here too. The domain-root bridge answers those when the
    // orbitra_lo cookie this route sets survives; this branch answers them from
    // the id in the URL itself, so a cookie-less browser is covered too.
    $bridgeFile = strtolower(basename($rest));
    if (in_array($bridgeFile, orbitraBundleHandlers(true), true)) {
        $bridgeTarget = realpath($root . '/' . $rest);
        if ($bridgeTarget === false || !is_file($bridgeTarget)
            || strpos($bridgeTarget, $root . DIRECTORY_SEPARATOR) !== 0) {
            $notFound();
        }
        require_once __DIR__ . '/core/PhpLanding.php';
        if (!PhpLanding::enabled($pdo)) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'This page has an order handler written in PHP, which is disabled on this tracker. '
                . 'Enable it in Settings -> General -> "Allow PHP landings".';
            exit;
        }
        @set_time_limit(max(PhpLanding::timeout($pdo), 25));
        orbitraDefineOfferContext($offerId, $root);
        require $bridgeTarget;
        exit;
    }

    // Anything that is not a page goes through the same extension whitelist
    // and path containment the click flow uses. serveOfferAsset() exits when
    // it serves and simply returns when it will not.
    if (!preg_match('/\.html?$/i', $rest)) {
        serveOfferAsset($offerId, '/' . $rest);
        $notFound();
    }

    $file = realpath($root . '/' . $rest);
    if ($file === false || !is_file($file) || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) {
        if ($rest === 'index.html' && is_file($root . '/index.php')) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'This offer page is written in PHP. A PHP page runs only inside a real click, '
                . 'where the tracker can give it the click context, so it cannot be previewed here.';
            exit;
        }
        $notFound();
    }

    $html = (string) file_get_contents($file);

    // Same <base> the lander route injects: relative paths must resolve inside
    // the offer's folder, and /offers/<id>/... is exactly what this route answers.
    $base = '<base href="/offers/' . $offerId . '/">';
    if (preg_match('/<head[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $at = $m[0][1] + strlen($m[0][0]);
        $html = substr($html, 0, $at) . "\n" . $base . substr($html, $at);
    } else {
        $html = $base . "\n" . $html;
    }

    // No stream picked an offer for this view, so the macro resolves to the same
    // entry point a hand-written landing uses.
    $html = str_replace('{offer}', '/?_lp=1', $html);

    if (!headers_sent()) {
        $secure = orbitraIsHttps();
        $opts = ['expires' => time() + 86400, 'path' => '/', 'secure' => $secure, 'httponly' => false, 'samesite' => 'Lax'];
        setcookie('orbitra_lo', (string) $offerId, $opts);
        setcookie('orbitra_lp', '', ['expires' => time() - 3600, 'path' => '/']);
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

/** Asset passthrough for a local offer's directory (mirror of serveLandingAsset). */
function serveOfferAsset($offerId, $uriPath)
{
    serveLandingAsset((int) $offerId, $uriPath, orbitraOfferDir($offerId));
}

function applyLandingMacros($html, $clickId, $offerId, $offerUrl, array $clickParams = [], $lpToken = '')
{
    if (!is_string($html) || $html === '' || strpos($html, '{') === false) {
        return $html;
    }

    $macros = [
        '{clickid}' => (string) $clickId,
        '{subid}' => (string) $clickId,
        '{click_id}' => (string) $clickId,
        '{sub_id}' => (string) $clickId,
        '{sub1}' => (string) $clickId,
        '{subid1}' => (string) $clickId,
        '{data1}' => (string) $clickId,
        '{external_id}' => (string) $clickId,
        '{{clickid}}' => (string) $clickId,
        '{{subid}}' => (string) $clickId,
        '{{click_id}}' => (string) $clickId,
        '{{sub_id}}' => (string) $clickId,
        '{{sub1}}' => (string) $clickId,
        '{{subid1}}' => (string) $clickId,
        '{{data1}}' => (string) $clickId,
        // Consumed by the JS adapter; harmless on a landing that doesn't use it.
        '{token}' => (string) $lpToken,
        '{{token}}' => (string) $lpToken,
        '{offer_id}' => (string) ($offerId ?: ''),
        '{{offer_id}}' => (string) ($offerId ?: ''),
        // Not url-encoded: this lands in an href, so it has to stay a usable URL.
        '{offer}' => (string) $offerUrl !== '' ? (string) $offerUrl : '/?_lp=1',
        '{{offer}}' => (string) $offerUrl !== '' ? (string) $offerUrl : '/?_lp=1',
    ];

    foreach ($clickParams as $key => $val) {
        if (is_scalar($val)) {
            $escaped = htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
            $macros['{' . $key . '}'] = $escaped;
            $macros['{{' . $key . '}}'] = $escaped;
        }
    }

    return str_replace(array_keys($macros), array_values($macros), $html);
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

function generateChallengeToken($campaignId, $alias, $secret) {
    $payload = json_encode(['cid' => $campaignId, 'alias' => $alias, 'ts' => time()]);
    $encoded = base64_encode($payload);
    $sig = hash_hmac('sha256', $encoded, $secret);
    return [$encoded, $sig];
}

function validateChallengeToken($ct, $cs, $secret, $campaignId) {
    if (empty($ct) || empty($cs)) return false;
    $expectedSig = hash_hmac('sha256', $ct, $secret);
    if (!hash_equals($expectedSig, $cs)) return false;
    $payload = json_decode(base64_decode($ct), true);
    if (!is_array($payload)) return false;
    // TTL: 15 minutes
    if (!isset($payload['ts']) || (time() - $payload['ts']) > 900) return false;
    // Campaign match
    if (!isset($payload['cid']) || (int)$payload['cid'] !== (int)$campaignId) return false;
    return true;
}

// --- Cloaking: JS execution step --------------------------------------------
//
// The ASN/UA layers in CloakDetector are passive — they only see the request. A
// moderator driving a real residential connection with a normal browser string
// passes them. This step adds an active check: serve the SAFE page to everyone
// first, require JavaScript to execute, and only then send the visitor to the
// money page. Explicit browser automation remains blocked through webdriver.
//
// Anything that does not execute JavaScript (curl, most crawlers, naive scrapers)
// simply keeps looking at the white page. Opt-in — off unless js_challenge is set,
// because it costs every real visitor one extra round trip.

function generateCloakJsToken($campaignId, $secret)
{
    $payload = base64_encode(json_encode(['cid' => (int) $campaignId, 'ts' => time()]));
    return [$payload, hash_hmac('sha256', $payload, $secret)];
}

function validateCloakJsToken($token, $sig, $secret, $campaignId)
{
    if (empty($token) || empty($sig)) {
        return false;
    }
    if (!hash_equals(hash_hmac('sha256', $token, $secret), $sig)) {
        return false;
    }
    $payload = json_decode(base64_decode($token), true);
    if (!is_array($payload) || !isset($payload['ts'], $payload['cid'])) {
        return false;
    }
    // Short TTL: the probe redirect happens within a second or two.
    if ((time() - (int) $payload['ts']) > 300) {
        return false;
    }
    return (int) $payload['cid'] === (int) $campaignId;
}

/**
 * Render the safe page plus the silent probe. $safeHtml is shown as-is; the probe
 * navigates to $nextUrl when JavaScript runs in a non-WebDriver browser.
 */
function renderCloakJsChallenge($safeHtml, $nextUrl, $webdriverUrl)
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $jsNext = json_encode($nextUrl, $jsonFlags) ?: '""';
    $jsWebdriver = json_encode($webdriverUrl, $jsonFlags) ?: '""';

    ob_start();
    ?>
<script>
(function () {
    try {
        // webdriver is an explicit automation signal. Privacy settings, empty
        // plugin lists, canvas restrictions and background tabs are not: all of
        // those occur in legitimate browsers and caused residential false positives.
        if (navigator.webdriver === true) {
            window.location.replace(<?php echo $jsWebdriver; ?>);
            return;
        }

        // A small timer proves that JavaScript ran without relying on rAF, which is
        // commonly paused for background tabs and embedded/in-app browsers.
        setTimeout(function () {
            window.location.replace(<?php echo $jsNext; ?>);
        }, 60);
    } catch (e) {
        // Any exception: stay on the safe page.
    }
})();
</script>
<?php
    $probeScript = (string) ob_get_clean();
    if (preg_match('/<\/body\s*>/i', (string) $safeHtml)) {
        echo preg_replace_callback(
            '/<\/body\s*>/i',
            static fn($match) => $probeScript . $match[0],
            (string) $safeHtml,
            1
        );
        return;
    }
    echo (string) $safeHtml . $probeScript;
}

function logCloakEvent($stage, $campaignId, $streamId, array $visitor, array $reasons, $sensitivity)
{
    $clean = static function ($value) {
        return preg_replace('/[\r\n]+/', ' ', (string) $value);
    };
    error_log(sprintf(
        'Orbitra cloak [campaign=%s stream=%s]: stage=%s ip=%s asn=%s isp=%s proxy=%s provider=%s ua=%.80s reasons=[%s] sensitivity=%s',
        $clean($campaignId),
        $clean($streamId),
        $clean($stage),
        $clean($visitor['ip'] ?? ''),
        $clean($visitor['asn'] ?? ''),
        $clean($visitor['isp'] ?? ''),
        $clean($visitor['proxy_type'] ?? ''),
        $clean($visitor['proxy_provider'] ?? ''),
        $clean($visitor['user_agent'] ?? ''),
        implode(', ', array_map($clean, $reasons)),
        $clean($sensitivity)
    ));
}

function renderChallengePage($campaign, $settings, $ct, $cs, $queryString) {
    $challengeType = $campaign['challenge_type'] ?? 'none';
    $campaignAlias = htmlspecialchars($campaign['alias'] ?? '');
    $siteKeyV2 = htmlspecialchars($settings['recaptcha_v2_site_key'] ?? '');
    $siteKeyV3 = htmlspecialchars($settings['recaptcha_v3_site_key'] ?? '');
    $siteKeyTurnstile = htmlspecialchars($settings['turnstile_site_key'] ?? '');
    $thresholdV3 = (float)($settings['recaptcha_v3_threshold'] ?? 0.5);
    $customCode = $campaign['challenge_custom_code'] ?? '';
    $ctEnc = htmlspecialchars($ct);
    $csEnc = htmlspecialchars($cs);
    // Pass original query params (except challenge fields) so they survive the POST
    $qs = htmlspecialchars($queryString);

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Please verify</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f5f7fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.card{background:#fff;border-radius:12px;padding:40px 32px;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:400px;width:90%;text-align:center}
.icon{font-size:48px;margin-bottom:16px}
h2{font-size:20px;font-weight:600;color:#1a1a2e;margin-bottom:8px}
p{color:#6b7280;font-size:14px;line-height:1.5;margin-bottom:24px}
.btn{display:inline-block;background:#4f46e5;color:#fff;border:none;border-radius:8px;padding:12px 28px;font-size:15px;font-weight:600;cursor:pointer;width:100%}
.btn:hover{background:#4338ca}
.recaptcha-wrap{display:flex;justify-content:center;margin-bottom:16px}
</style>
</head>
<body>
<div class="card">
  <div class="icon">🛡️</div>
  <h2>Quick verification</h2>
  <p>Please confirm you're human to continue.</p>
  <form method="POST" action="?<?php echo $qs; ?>" id="challenge-form">
    <input type="hidden" name="_ct" value="<?php echo $ctEnc; ?>">
    <input type="hidden" name="_cs" value="<?php echo $csEnc; ?>">
    <?php if ($challengeType === 'recaptcha_v2' && $siteKeyV2): ?>
    <div class="recaptcha-wrap">
      <div class="g-recaptcha" data-sitekey="<?php echo $siteKeyV2; ?>"></div>
    </div>
    <button type="submit" class="btn">Continue &rarr;</button>
    <?php elseif ($challengeType === 'recaptcha_v3' && $siteKeyV3): ?>
    <button type="submit" class="btn" id="submit-btn">Continue &rarr;</button>
    <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
    <?php elseif ($challengeType === 'turnstile' && $siteKeyTurnstile): ?>
    <div class="recaptcha-wrap">
      <div class="cf-turnstile" data-sitekey="<?php echo $siteKeyTurnstile; ?>" data-callback="onTurnstileSuccess"></div>
    </div>
    <button type="submit" class="btn" id="submit-btn" style="display:none">Continue &rarr;</button>
    <?php elseif ($challengeType === 'custom' && $customCode): ?>
    <?php echo $customCode; ?>
    <?php else: ?>
    <button type="submit" class="btn">Continue &rarr;</button>
    <?php endif; ?>
  </form>
</div>
<?php if ($challengeType === 'recaptcha_v2' && $siteKeyV2): ?>
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<?php elseif ($challengeType === 'recaptcha_v3' && $siteKeyV3): ?>
<script src="https://www.google.com/recaptcha/api.js?render=<?php echo $siteKeyV3; ?>"></script>
<script>
grecaptcha.ready(function(){
  document.getElementById('submit-btn').addEventListener('click', function(e){
    e.preventDefault();
    grecaptcha.execute('<?php echo $siteKeyV3; ?>', {action:'verify'}).then(function(token){
      document.getElementById('g-recaptcha-response').value = token;
      document.getElementById('challenge-form').submit();
    });
  });
});
</script>
<?php elseif ($challengeType === 'turnstile' && $siteKeyTurnstile): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<script>
function onTurnstileSuccess(token) {
  document.getElementById('challenge-form').submit();
}
setTimeout(function(){
  var btn = document.getElementById('submit-btn');
  if(btn) btn.style.display = 'inline-block';
}, 2500);
</script>
<?php endif; ?>
</body>
</html>
<?php
    exit;
}

function verifyChallengeResponse($challengeType, $settings, $postData) {
    if ($challengeType === 'recaptcha_v2') {
        $secretKey = $settings['recaptcha_v2_secret_key'] ?? '';
        $response = $postData['g-recaptcha-response'] ?? '';
        if (empty($secretKey) || empty($response)) return false;
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['secret' => $secretKey, 'response' => $response]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $result = curl_exec($ch);
        if (!$result) return false;
        $data = json_decode($result, true);
        return !empty($data['success']);
    } elseif ($challengeType === 'recaptcha_v3') {
        $secretKey = $settings['recaptcha_v3_secret_key'] ?? '';
        $response = $postData['g-recaptcha-response'] ?? '';
        $threshold = (float)($settings['recaptcha_v3_threshold'] ?? 0.5);
        if (empty($secretKey) || empty($response)) return false;
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['secret' => $secretKey, 'response' => $response]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $result = curl_exec($ch);
        if (!$result) return false;
        $data = json_decode($result, true);
        return !empty($data['success']) && ($data['score'] ?? 0) >= $threshold;
    } elseif ($challengeType === 'turnstile') {
        $secretKey = $settings['turnstile_secret_key'] ?? '';
        $response = $postData['cf-turnstile-response'] ?? '';
        if (empty($secretKey) || empty($response)) return false;
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'secret' => $secretKey,
            'response' => $response,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $result = curl_exec($ch);
        if (!$result) return false;
        $data = json_decode($result, true);
        return !empty($data['success']);
    } elseif ($challengeType === 'custom') {
        // Custom challenge: trust a hidden field _challenge_ok=1 submitted by user's own code
        return !empty($postData['_challenge_ok']);
    }
    return true; // no challenge type
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
            $isSecure = orbitraIsHttps();
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

// === SECRET ADMIN PATH ===
// Runs before any campaign routing: the panel must win over an alias lookup, and
// it must be reachable on every host — the parked domains and the bare server IP
// alike — so that parking or deleting a domain can never lock anyone out.
require_once __DIR__ . '/core/admin_path.php';
if (orbitraTryServeAdminPath($pdo, parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH))) {
    exit;
}
// =========================

// === PREVENT DOUBLE CLICKS FROM BACKGROUND FETCHES ===
$staticExts = '/\.(ico|png|jpg|jpeg|gif|bmp|webp|avif|css|js|mjs|json|woff|woff2|ttf|otf|eot|svg|map|webmanifest|mp4|webm|m4v|ogv|mp3|ogg|wav|m4a)$/i';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

// Keitaro-compatible Click API endpoint (v3).
// Must be handled here because the default Nginx config routes unknown paths to index.php.
if ($uriPath === '/click_api/v3' || $uriPath === '/click_api/v3/') {
    require_once __DIR__ . '/core/click_api.php';
    orbitraClickApiV3($pdo);
    exit;
}

// === Postback endpoint: /<postback_key>/postback ===
// The postback_key is configurable from Settings and overrides the default
// from config.php, so changing it in the panel takes effect immediately.
$pbKey = (string) ($postback_key ?? '');
if ($pbKey !== '' && ($uriPath === '/' . $pbKey . '/postback'
                   || $uriPath === '/' . $pbKey . '/postback/')) {
    require __DIR__ . '/postback.php';
    exit;
}

// === Tracking pixel: /pixel.gif (Keitaro-compatible) ===
// One invisible image, two jobs:
//   /pixel.gif?campaign_id=42[&click params]  — email/impression pixel: logs a
//                                               click for the campaign, no redirect
//   /pixel.gif?action=conversion&subid=S&status=lead[&payout=&currency=&tid=]
//                                             — registers a conversion on click S
// Must sit BEFORE the Sec-Fetch-Dest image guard below, which exists precisely
// to 404 stray image probes — this one is a legitimate image.
if ($uriPath === '/pixel.gif') {
    $orbitraPixelGif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    $orbitraPixelHeaders = function () {
        if (headers_sent()) {
            return;
        }
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    };

    if (($_GET['action'] ?? '') === 'conversion') {
        // Full postback semantics (status mapping, tid upsert, outbound S2S)
        // live in postback.php; it die()s on bad input, so the shutdown function
        // guarantees the browser still gets its pixel either way.
        define('ORBITRA_INSIDE_PIXEL_GIF', true);
        $pxBaseObLevel = ob_get_level();
        register_shutdown_function(function () use ($orbitraPixelGif, $orbitraPixelHeaders, $pxBaseObLevel) {
            while (ob_get_level() > $pxBaseObLevel) { @ob_end_clean(); }
            http_response_code(200);
            $orbitraPixelHeaders();
            echo $orbitraPixelGif;
        });
        ob_start();
        require __DIR__ . '/postback.php';
        while (ob_get_level() > $pxBaseObLevel) { @ob_end_clean(); }
        exit;
    }

    if (($_GET['action'] ?? '') === 'update') {
        // KTracking.update({sub_id_N: …}) — merge parameters onto an existing
        // click. No new click, no conversion: the pixel just answers with the GIF.
        $pxSubid = trim((string) ($_GET['subid'] ?? ''));
        if ($pxSubid !== '') {
            try {
                $stmtPxSel = $pdo->prepare("SELECT parameters_json FROM clicks WHERE id = ? LIMIT 1");
                $stmtPxSel->execute([$pxSubid]);
                $pxRow = $stmtPxSel->fetch(PDO::FETCH_ASSOC);
                if ($pxRow) {
                    $pxExisting = json_decode((string) ($pxRow['parameters_json'] ?? ''), true);
                    if (!is_array($pxExisting)) {
                        $pxExisting = [];
                    }
                    $pxAllowed = ['keyword', 'cost', 'currency', 'external_id', 'creative_id', 'ad_campaign_id', 'source'];
                    for ($pxI = 1; $pxI <= 30; $pxI++) {
                        $pxAllowed[] = 'sub_id_' . $pxI;
                    }
                    $pxDirty = false;
                    foreach ($pxAllowed as $pxKey) {
                        if (isset($_GET[$pxKey]) && $_GET[$pxKey] !== '') {
                            $pxExisting[$pxKey] = substr((string) $_GET[$pxKey], 0, 512);
                            $pxDirty = true;
                        }
                    }
                    if ($pxDirty) {
                        $stmtPxUpd = $pdo->prepare("UPDATE clicks SET parameters_json = ? WHERE id = ?");
                        $stmtPxUpd->execute([json_encode($pxExisting, JSON_UNESCAPED_UNICODE), $pxSubid]);
                    }
                }
            } catch (\Throwable $e) {
                // The pixel must answer with the image no matter what.
            }
        }
        $orbitraPixelHeaders();
        echo $orbitraPixelGif;
        exit;
    }

    // Impression click. The campaign comes by numeric id (the snippets Orbitra
    // generates), by alias (hand-made links) or by campaign token (JS clients,
    // which only ever hold the token). A pixel must answer with the image even
    // when the campaign is missing, so failures fall through to the GIF.
    $orbitraPixelCampaign = null;
    try {
        if ((int) ($_GET['campaign_id'] ?? 0) > 0) {
            $stmtPx = $pdo->prepare("SELECT * FROM campaigns WHERE is_archived = 0 AND id = ? LIMIT 1");
            $stmtPx->execute([(int) $_GET['campaign_id']]);
            $orbitraPixelCampaign = $stmtPx->fetch(PDO::FETCH_ASSOC) ?: null;
        } elseif (!empty($_GET['token'])) {
            $stmtPx = $pdo->prepare("SELECT * FROM campaigns WHERE is_archived = 0 AND token = ? LIMIT 1");
            $stmtPx->execute([trim((string) $_GET['token'])]);
            $orbitraPixelCampaign = $stmtPx->fetch(PDO::FETCH_ASSOC) ?: null;
        } elseif (!empty($_GET['campaign'])) {
            $stmtPx = $pdo->prepare("SELECT * FROM campaigns WHERE is_archived = 0 AND alias = ? LIMIT 1");
            $stmtPx->execute([trim((string) $_GET['campaign'])]);
            $orbitraPixelCampaign = $stmtPx->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (\Throwable $e) {
        $orbitraPixelCampaign = null;
    }

    if ($orbitraPixelCampaign) {
        require_once __DIR__ . '/core/click_api.php';
        require_once __DIR__ . '/core/ClickParams.php';

        $pxIp = orbitraClickApiGetClientIp();
        $pxUa = orbitraClickApiGetUserAgent();
        $pxAcceptLanguage = orbitraClickApiDetectAcceptLanguageRaw();
        $pxGeo = orbitraClickApiGetGeoData($pxIp);
        $pxClickId = orbitraClickApiGenerateUuid();
        // The same capture whitelist the campaign router uses: standard keys plus
        // whatever the campaign's traffic source declares (ad_id & co for costs).
        // The pixel's own routing params are stripped first — campaign_id/token are
        // how THIS request found the campaign, not attributes of the click.
        $pxIncoming = array_merge($_GET, $_POST);
        unset($pxIncoming['campaign_id'], $pxIncoming['campaign'], $pxIncoming['token'], $pxIncoming['action'], $pxIncoming['js'], $pxIncoming['subid'], $pxIncoming['status'], $pxIncoming['payout'], $pxIncoming['tid']);
        $pxParams = orbitraCollectClickParams($pdo, $pxIncoming, $_COOKIE, $orbitraPixelCampaign['source_id'] ?? null);
        $pxParamsJson = json_encode($pxParams, JSON_UNESCAPED_UNICODE);

        try {
            $stmtPxIns = $pdo->prepare("
                INSERT INTO clicks
                (id, campaign_id, offer_id, stream_id, source_id, ip, user_agent, referer,
                 country, country_code, region, city, latitude, longitude, zipcode, timezone,
                 device_type, os, browser, language, accept_language_raw, parameters_json)
                VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Unknown', 'Unknown', ?, ?, ?)
            ");
            $stmtPxIns->execute([
                $pxClickId,
                (int) $orbitraPixelCampaign['id'],
                $orbitraPixelCampaign['source_id'] ?? null,
                $pxIp,
                $pxUa,
                (string) ($_SERVER['HTTP_REFERER'] ?? ''),
                (string) ($pxGeo['country_code'] ?? 'Unknown'),
                (string) ($pxGeo['country_code'] ?? 'Unknown'),
                $pxGeo['region'] ?? '',
                $pxGeo['city'] ?? '',
                $pxGeo['latitude'] ?? null,
                $pxGeo['longitude'] ?? null,
                $pxGeo['zipcode'] ?? '',
                $pxGeo['timezone'] ?? '',
                orbitraClickApiGetDeviceType($pxUa),
                ($pxLangCodes = orbitraClickApiExtractLanguageCodes($pxAcceptLanguage)) ? $pxLangCodes[0] : 'Unknown',
                $pxAcceptLanguage,
                $pxParamsJson,
            ]);

            require_once __DIR__ . '/core/ClickFlags.php';
            orbitraWriteClickFlags($pdo, $pxClickId, $pxIp, $pxUa, $orbitraPixelCampaign ?? [], 0, is_array($pxGeo ?? null) ? $pxGeo : []);
        } catch (\Throwable $e) {
            // A duplicate/DB hiccup must not break the pixel — the image goes out.
        }
    }

    $orbitraPixelHeaders();
    echo $orbitraPixelGif;
    exit;
}

// === CRM lead ingest: POST /crm-ingest (LeadForge /crm-ingest route) ===
// The public counterpart of the pixel's conversion endpoint: a LeadForge
// landing deployed on foreign hosting POSTs its full lead snapshot here.
// Same exposure model as /pixel.gif?action=conversion — anyone can call it,
// so it attaches to existing clicks only and creates none; QA-flagged leads
// are stored for the CRM audit trail but never touch analytics.
if ($uriPath === '/crm-ingest') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Access-Control-Allow-Origin: *');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'POST method required']);
        exit;
    }
    $rawBody = (string) file_get_contents('php://input');
    if (strlen($rawBody) > 131072) {
        http_response_code(413);
        echo json_encode(['status' => 'error', 'message' => 'Payload too large']);
        exit;
    }
    $ingest = json_decode($rawBody, true);
    if (!is_array($ingest)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'JSON body required']);
        exit;
    }
    $ingest['ip'] = $ingest['ip'] ?? orbitraClientIp();
    $ingest['user_agent'] = $ingest['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $res = orbitraCrmRecordLead($pdo, $ingest, false);
    echo json_encode(['status' => $res['ok'] ? 'success' : 'error', 'message' => $res['message'], 'data' => ['lead_id' => $res['lead_id']]]);
    exit;
}

// A local landing's own address, /lander/<slug>/, matching Keitaro. Must come
// before the click handling below: this path is a look at the landing, not a
// visit to a campaign, and nothing about it should be logged as traffic.
if ($uriPath !== null && preg_match('#^/lander/([A-Za-z0-9][A-Za-z0-9_-]{0,63})(?:/(.*))?$#', $uriPath, $landerMatch)) {
    orbitraServeLanderPath($pdo, strtolower($landerMatch[1]), $landerMatch[2] ?? '');
}

// Local landing asset passthrough (Keitaro-compatible).
// A local landing's HTML is printed at the campaign URL ("/" or "/alias"), but its
// files live in /landings/<id>/. Relative paths inside the landing ("img/a.png")
// are therefore requested from the domain root, where nothing exists — which is
// why every image, font and video used to 404. Resolve such requests against the
// landing the visitor was actually shown, remembered in the orbitra_lp cookie.
// This keeps uploaded landings working untouched, exactly as they do in Keitaro.
// The offer cookie wins when both are set: a visitor who clicked from a local
// landing to a local offer is now on the offer's page, so its assets resolve
// against offers/<id>, not against the landing they came from.
// Local-offer / local-landing order bridge (LeadForge): the generated
// handlers (orbitraBundleHandlers()) are form senders no static server can run.
// The asset passthrough below deliberately never serves .php, so they get the
// same in-process execution the landing's own index.php gets — gated by the same
// "Allow PHP landings" switch and execution budget. The cookies say which
// uploaded page the visitor is actually on (offer wins, as with assets); the
// Referer is the fallback when no cookie does (blocked cookies, or a form
// posted absolute from a /lander/<slug>/ preview, which sets no cookies).
if ($uriPath !== null) {
    $orbitraBridgeFile = strtolower(basename(parse_url($uriPath, PHP_URL_PATH) ?? ''));
    if (in_array($orbitraBridgeFile, orbitraBundleHandlers(), true)) {
        $orbitraBridgeRoot = '';
        // Which offer, when it is an offer: the handler gets ORBITRA_OFFER_URL
        // and friends the same way it would on the offer's own route.
        $orbitraBridgeOffer = 0;
        if (!empty($_COOKIE['orbitra_lo']) && orbitraOfferIsLocal($pdo, (int) $_COOKIE['orbitra_lo'])) {
            $orbitraBridgeOffer = (int) $_COOKIE['orbitra_lo'];
            $orbitraBridgeRoot = orbitraLandingContentDir(orbitraOfferDir($orbitraBridgeOffer));
        } elseif (!empty($_COOKIE['orbitra_lp'])) {
            $orbitraBridgeRoot = orbitraLandingContentDir(orbitraLandingDir($pdo, (int) $_COOKIE['orbitra_lp']));
        }
        // No cookie names the page: cookies are blocked, or a hand-written
        // landing posted an absolute /order.php straight off its /lander/<slug>/
        // preview, which deliberately sets none. The Referer still says where
        // the form lives, and its slug / offer id goes through the same
        // resolvers the cookie path uses — a Referer is no easier to forge
        // than a cookie, which was never an auth boundary here either.
        if ($orbitraBridgeRoot === '' && !empty($_SERVER['HTTP_REFERER'])) {
            $orbitraRefPath = (string) (parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH) ?? '');
            if (preg_match('#^/lander/([A-Za-z0-9][A-Za-z0-9_-]{0,63})#', $orbitraRefPath, $orbitraRefMatch)) {
                try {
                    $orbitraRefStmt = $pdo->prepare("SELECT id, type FROM landings WHERE slug = ? AND is_archived = 0 LIMIT 1");
                    $orbitraRefStmt->execute([strtolower($orbitraRefMatch[1])]);
                    $orbitraRefRow = $orbitraRefStmt->fetch(PDO::FETCH_ASSOC);
                } catch (\Throwable $e) {
                    $orbitraRefRow = null;
                }
                if ($orbitraRefRow && ($orbitraRefRow['type'] ?? '') === 'local') {
                    $orbitraBridgeRoot = orbitraLandingContentDir(orbitraLandingDir($pdo, (int) $orbitraRefRow['id']));
                }
            } elseif (preg_match('#^/offers/(\d+)#', $orbitraRefPath, $orbitraRefMatch)
                && orbitraOfferIsLocal($pdo, (int) $orbitraRefMatch[1])) {
                $orbitraBridgeOffer = (int) $orbitraRefMatch[1];
                $orbitraBridgeRoot = orbitraLandingContentDir(orbitraOfferDir($orbitraBridgeOffer));
            }
        }
        if ($orbitraBridgeRoot !== '' && is_file($orbitraBridgeRoot . '/' . $orbitraBridgeFile)) {
            require_once __DIR__ . '/core/PhpLanding.php';
            if (!PhpLanding::enabled($pdo)) {
                http_response_code(503);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'This page has an order handler written in PHP, which is disabled on this tracker. '
                    . 'Enable it in Settings -> General -> "Allow PHP landings".';
                exit;
            }
            // The order bridge is a network actor: it calls the CPA network
            // (curl, up to ~15s) and the CRM vault before redirecting. The
            // generic landing budget (3s default) would kill a healthy lead
            // mid-flight, so bridge files get a floor of their own.
            @set_time_limit(max(PhpLanding::timeout($pdo), 25));
            if ($orbitraBridgeOffer > 0) {
                orbitraDefineOfferContext($orbitraBridgeOffer, $orbitraBridgeRoot);
            }
            require $orbitraBridgeRoot . '/' . $orbitraBridgeFile;
            exit;
        }
    }
}

// A local offer's own address, /offers/<id>/ — see orbitraServeOfferPath().
// Comes after the order.php bridge (a form on that page posts to a relative
// order.php the bridge must run, using the cookie this route sets) and before
// the cookie passthrough: the id in the URL, not the cookie, decides here.
if ($uriPath !== null && preg_match('#^/offers/(\d+)(?:/(.*))?$#', $uriPath, $offerMatch)) {
    orbitraServeOfferPath($pdo, (int) $offerMatch[1], $offerMatch[2] ?? '');
}

if (!empty($_COOKIE['orbitra_lo']) && $uriPath !== null && $uriPath !== '/') {
    serveOfferAsset((int) $_COOKIE['orbitra_lo'], $uriPath);
}
if (!empty($_COOKIE['orbitra_lp']) && $uriPath !== null && $uriPath !== '/') {
    serveLandingAsset((int) $_COOKIE['orbitra_lp'], $uriPath);
}

/**
 * Resolve landing/offer from campaign alias in referer.
 * Searches all active streams for the campaign and finds first local landing.
 *
 * @param PDO $pdo Database connection
 * @param string $refererPath The path from HTTP_REFERER
 * @return array|null ['type' => 'landing'|'offer', 'id' => int, 'slug' => string|null]|null
 */
function orbitraResolveCampaignContext(PDO $pdo, string $refererPath): ?array
{
    // Extract alias from referer (e.g., "/bd86o7dw" → "bd86o7dw")
    $alias = basename(parse_url($refererPath, PHP_URL_PATH) ?? '');
    if ($alias === '' || preg_match('/\./', $alias)) {
        return null; // Skip file requests
    }

    try {
        // Find campaign by alias
        $stmt = $pdo->prepare("SELECT id FROM campaigns WHERE alias = ? AND is_archived = 0 LIMIT 1");
        $stmt->execute([$alias]);
        $campaignId = $stmt->fetchColumn();
        if (!$campaignId) {
            return null;
        }

        // Get all active streams for this campaign
        $stmt = $pdo->prepare("SELECT schema_custom_json FROM streams WHERE campaign_id = ? AND is_active = 1 ORDER BY position ASC");
        $stmt->execute([$campaignId]);
        $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($streams as $stream) {
            $customSchema = json_decode($stream['schema_custom_json'] ?? '{}', true);
            if (!is_array($customSchema)) {
                continue;
            }

            // Search landings array for first local landing
            $landings = $customSchema['landings'] ?? [];
            foreach ($landings as $landing) {
                $landingId = (int) ($landing['id'] ?? 0);
                if ($landingId > 0) {
                    // Verify it's a local landing
                    $checkStmt = $pdo->prepare("SELECT slug, type FROM landings WHERE id = ? LIMIT 1");
                    $checkStmt->execute([$landingId]);
                    $land = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    if ($land && ($land['type'] ?? '') === 'local') {
                        return [
                            'type' => 'landing',
                            'id' => $landingId,
                            'slug' => $land['slug'] ?? null
                        ];
                    }
                }
            }

            // Also check for local offers in offers array
            $offers = $customSchema['offers'] ?? [];
            foreach ($offers as $offer) {
                $offerId = (int) ($offer['id'] ?? 0);
                if ($offerId > 0) {
                    $checkStmt = $pdo->prepare("SELECT is_local FROM offers WHERE id = ? LIMIT 1");
                    $checkStmt->execute([$offerId]);
                    if (((int) ($checkStmt->fetchColumn() ?? 0)) === 1) {
                        return [
                            'type' => 'offer',
                            'id' => $offerId,
                            'slug' => null
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // Fall through to 404 on error
    }

    return null;
}

// === Referer fallback for Incognito/Private mode (no cookies) ===
// When a browser blocks cookies or is in Incognito mode, the orbitra_lo/orbitra_lp
// cookies are not sent with asset requests. Check HTTP_REFERER to recover the
// bundle ID from the preview URL (/offers/<id>/ or /lander/<slug>/) or from
// campaign context.
if ($uriPath !== null && $uriPath !== '/' && empty($_COOKIE['orbitra_lo']) && empty($_COOKIE['orbitra_lp'])) {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer !== '') {
        $refererPath = parse_url($referer, PHP_URL_PATH) ?? '';
        // Check for /offers/<id>/ pattern in referer
        if (preg_match('#^/offers/(\d+)/#', $refererPath, $refMatch)) {
            $offerId = (int) $refMatch[1];
            if ($offerId > 0 && orbitraOfferIsLocal($pdo, $offerId)) {
                serveOfferAsset($offerId, $uriPath);
                // If we get here, the asset wasn't found - fall through to 404
            }
        }
        // Check for /lander/<slug>/ pattern in referer
        elseif (preg_match('#^/lander/([a-z0-9_-]+)/#i', $refererPath, $refMatch)) {
            $landingSlug = $refMatch[1];
            try {
                $stmt = $pdo->prepare("SELECT id FROM landings WHERE slug = ? AND type = 'local' LIMIT 1");
                $stmt->execute([$landingSlug]);
                $landingId = $stmt->fetchColumn();
                if ($landingId !== false && (int) $landingId > 0) {
                    serveLandingAsset((int) $landingId, $uriPath);
                    // If we get here, the asset wasn't found - fall through to 404
                }
            } catch (\Throwable $e) {
                // Fall through to 404
            }
        }
        // NEW: Campaign context fallback - resolve campaign alias to landing/offer
        elseif ($campaignCtx = orbitraResolveCampaignContext($pdo, $refererPath)) {
            if ($campaignCtx['type'] === 'landing') {
                serveLandingAsset($campaignCtx['id'], $uriPath);
            } elseif ($campaignCtx['type'] === 'offer') {
                serveOfferAsset($campaignCtx['id'], $uriPath);
            }
            // If we get here, the asset wasn't found - fall through to 404
        }
    }
}

if (preg_match($staticExts, $uriPath)) {
    http_response_code(404);
    exit;
}

// Fetch Dest Header Check (Modern browsers tell us if they just want an image)
if (isset($_SERVER['HTTP_SEC_FETCH_DEST'])) {
    $dest = $_SERVER['HTTP_SEC_FETCH_DEST'];
    if (in_array($dest, ['image', 'style', 'script', 'font', 'manifest', 'video', 'audio', 'track'])) {
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

// === Landing → Offer transition (Keitaro-compatible /?_lp=1) ===
// When a visitor clicks an offer link on a local landing, resolve the offer
// bound to their original click (remembered via cookie when the landing was
// shown) and redirect to it. Runs before campaign resolution so it works even
// on a bare "/?_lp=1" link without campaign parameters.
if (isset($_GET['_lp'])) {
    // A signed token wins over the cookie: it is the only thing a landing hosted
    // on another domain can carry, and it is proof rather than a claim. A bare
    // _subid is NOT accepted — accepting an unsigned click id from the URL would
    // let anyone attribute a visit to any click they like. A local landing that
    // carries the click in a cookie does not need _subid at all.
    // This block runs before the settings map is loaded, so the signing key is
    // read on its own rather than silently falling back to a constant — which
    // would make every token verifiable by anyone running Orbitra.
    $lpSecret = 'orbitra_secret';
    try {
        $lpSecretRow = $pdo->query("SELECT value FROM settings WHERE key = 'postback_key' LIMIT 1")->fetchColumn();
        if (is_string($lpSecretRow) && $lpSecretRow !== '') {
            $lpSecret = $lpSecretRow;
        }
    } catch (\Throwable $e) {
        // Falls back to the constant; a wrong key only rejects tokens, never accepts.
    }
    $lpClickId = '';
    if (!empty($_GET['_token'])) {
        $lpClickId = (string) (verifyLpToken((string) $_GET['_token'], $lpSecret) ?? '');
        if ($lpClickId === '') {
            http_response_code(400);
            die('Landing transition failed: the _token is invalid or has expired.');
        }
    }
    if ($lpClickId === '') {
        $lpClickId = $_COOKIE['orbitra_click'] ?? ($_COOKIE['subid'] ?? '');
    }
    if ($lpClickId === '') {
        http_response_code(400);
        die('Landing transition failed: original click not found. A landing on another domain must carry a signed _token (see docs/landing-pages.md); a local landing recovers the click from its cookie.');
    }

    $lpClick = null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM clicks WHERE id = ? LIMIT 1");
        $stmt->execute([$lpClickId]);
        $lpClick = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        $lpClick = null;
    }

    // Determine the offer: explicit ?offer_id=X wins, then the click's logged
    // offer, then the offer remembered in the cookie (covers stats-disabled).
    $lpOfferId = 0;
    if (isset($_GET['offer_id']) && (int) $_GET['offer_id'] > 0) {
        $lpOfferId = (int) $_GET['offer_id'];
    } elseif ($lpClick && (int) ($lpClick['offer_id'] ?? 0) > 0) {
        $lpOfferId = (int) $lpClick['offer_id'];
    } elseif (isset($_COOKIE['orbitra_offer']) && (int) $_COOKIE['orbitra_offer'] > 0) {
        $lpOfferId = (int) $_COOKIE['orbitra_offer'];
    }

    // Nothing bound yet: this is a stream configured to pick the offer after the
    // click, so the choice happens now, from the same weighted list the stream
    // would have used earlier.
    if ($lpOfferId === 0 && $lpClick && !empty($lpClick['stream_id'])) {
        try {
            $lpStmt = $pdo->prepare("SELECT schema_custom_json, offer_selection FROM streams WHERE id = ? LIMIT 1");
            $lpStmt->execute([(int) $lpClick['stream_id']]);
            $lpStream = $lpStmt->fetch(PDO::FETCH_ASSOC);
            if ($lpStream && ($lpStream['offer_selection'] ?? 'before') === 'after') {
                $lpSchema = json_decode($lpStream['schema_custom_json'] ?? '{}', true);
                $lpPicked = selectWeightedItem(is_array($lpSchema) ? ($lpSchema['offers'] ?? []) : []);
                if ($lpPicked && !empty($lpPicked['id'])) {
                    $lpOfferId = (int) $lpPicked['id'];
                }
            }
        } catch (\Throwable $e) {
            // Leaves $lpOfferId at 0, which reports "no offer" below rather than 500.
        }
    }

    if ($lpOfferId <= 0) {
        http_response_code(404);
        die('Landing transition failed: no offer attached to this stream.');
    }

    $lpOffer = null;
    $stmt = $pdo->prepare("SELECT url, redirect_type, is_local FROM offers WHERE id = ? LIMIT 1");
    $stmt->execute([$lpOfferId]);
    $lpOffer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$lpOffer || (empty($lpOffer['url']) && empty($lpOffer['is_local']))) {
        http_response_code(404);
        die('Landing transition failed: offer not found.');
    }

    // Restore tracking params from the original click for macro substitution.
    $lpParams = [];
    if ($lpClick && !empty($lpClick['parameters_json'])) {
        $decoded = json_decode($lpClick['parameters_json'], true);
        if (is_array($decoded)) {
            $lpParams = $decoded;
        }
    }

    // Attribute the click to whichever offer it ends up going to — either an
    // explicit override, or the one just picked for a stream that defers the
    // choice. Without this the click stays offer-less in reports and an incoming
    // conversion has nothing to credit.
    $lpShouldAttribute = $lpClick && $lpOfferId > 0 && $lpOfferId !== (int) ($lpClick['offer_id'] ?? 0);
    if ($lpShouldAttribute) {
        try {
            $pdo->prepare("UPDATE clicks SET offer_id = ? WHERE id = ?")->execute([$lpOfferId, $lpClickId]);
        } catch (\Throwable $e) {
            // non-critical
        }
    }

    // The landing→offer transition completes the Time-since-LP-click pair.
    try {
        $pdo->prepare("UPDATE clicks SET offer_at = datetime('now') WHERE id = ? AND offer_at IS NULL")->execute([$lpClickId]);
    } catch (\Throwable $e) {
        // non-critical
    }

    $lpUrl = applyOfferMacros($lpOffer['url'], $lpClickId, $lpOfferId, $lpParams);

    // A local offer is served from the tracker instead of the redirect — the
    // landing→offer click then lands on the offer's own uploaded page.
    if (!empty($lpOffer['is_local'])) {
        orbitraServeLocalOffer($pdo, $lpOfferId, $lpClickId, $lpParams, ['postback_key' => $lpSecret]);
    }

    renderRedirectResponse($lpOffer['redirect_type'] ?? 'redirect', $lpUrl);
    exit;
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

// A campaign paused from the panel (state='disabled') stops serving right
// away — same visibility to a visitor as a deleted campaign, reversible from
// the campaigns table toggle.
if (strtolower((string) ($campaign['state'] ?? 'active')) === 'disabled') {
    http_response_code(503);
    die("Campaign is disabled.");
}

$campaignId = $campaign['id'];

// 2. Сбор данных
$ip = orbitraClientIp();
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



// Extra tracking parameters extraction (Keitaro standards).
// The full list — including the ad-network IDs cost import matches on and the
// Meta click identifiers CAPI needs — lives in core/ClickParams.php, shared with
// click.php so the two entry points cannot capture different things.
require_once __DIR__ . '/core/ClickParams.php';
$incomingParams = array_merge($_GET, $_POST);
$clickParams = orbitraCollectClickParams($pdo, $incomingParams, $_COOKIE, $campaign['source_id'] ?? null);

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

// Prefetch guard (ignore_prefetch): a speculative request is not counted as a
// click, but the campaign itself is served — killing the request here used to
// leave the browser showing the cached "Prefetch ignored." body instead of the
// landing until a manual refresh.
$isPrefetchRequest = orbitraShouldSkipClickOnPrefetch($settings['ignore_prefetch'] ?? '1');

// === BOT CHALLENGE VERIFICATION ===
$challengeType = $campaign['challenge_type'] ?? 'none';
if ($challengeType !== 'none') {
    $challengeSecret = $settings['postback_key'] ?? 'orbitra_secret';

    // Build query string without challenge fields to preserve original params
    $originalParams = array_diff_key($_GET, array_flip(['_ct', '_cs', '_challenge_ok']));
    $originalQs = http_build_query($originalParams);

    // Check if this is a POST with a valid challenge token
    $isVerifiedPost = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postCt = $_POST['_ct'] ?? '';
        $postCs = $_POST['_cs'] ?? '';

        if (validateChallengeToken($postCt, $postCs, $challengeSecret, $campaignId)) {
            // Token is valid — verify the captcha response
            if (verifyChallengeResponse($challengeType, $settings, $_POST)) {
                $isVerifiedPost = true;
            } else {
                // Failed captcha — show challenge again
                [$ct, $cs] = generateChallengeToken($campaignId, $campaign['alias'] ?? '', $challengeSecret);
                renderChallengePage($campaign, $settings, $ct, $cs, $originalQs);
                exit;
            }
        }
    }

    if (!$isVerifiedPost) {
        // Not verified — show challenge page
        [$ct, $cs] = generateChallengeToken($campaignId, $campaign['alias'] ?? '', $challengeSecret);
        renderChallengePage($campaign, $settings, $ct, $cs, $originalQs);
        exit;
    }
}
// === END BOT CHALLENGE ===

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
        $stmt = $pdo->prepare("SELECT id FROM bot_signatures WHERE trim(signature) <> '' AND ? LIKE '%' || signature || '%' LIMIT 1");
        $stmt->execute([$userAgent]);
        if ($stmt->fetch())
            return true;
    }
    return false;
}

// Case-insensitive equality of a payload token against a value.
function filterTokenEquals($token, $value)
{
    return strcasecmp(trim((string) $token), trim((string) $value)) === 0;
}

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

// Match an IP filter token (supports exact match and wildcards like 10.0.0.*).
function ipMatchesToken($token, $ip)
{
    $token = trim((string) $token);
    if ($token === '')
        return false;
    if (strpos($token, '*') === false) {
        return $token === $ip;
    }
    $regex = '/^' . str_replace('\*', '.*', preg_quote($token, '/')) . '$/';
    return (bool) preg_match($regex, $ip);
}

function streamMatchesFilters($stream, $visitor, $pdo)
{
    if (empty($stream['filters_json']))
        return true;
    $filters = json_decode($stream['filters_json'], true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($filters) || empty($filters))
        return true;

    $ip = $visitor['ip'] ?? '';
    $country = $visitor['country'] ?? '';
    $deviceType = $visitor['device'] ?? '';
    $os = $visitor['os'] ?? '';
    $browser = $visitor['browser'] ?? '';
    $languageCodes = $visitor['languageCodes'] ?? [];
    $userAgent = $visitor['userAgent'] ?? '';
    $referer = $visitor['referer'] ?? '';
    $params = $visitor['params'] ?? [];
    $timezone = $visitor['timezone'] ?? '';
    $isp = trim(($visitor['isp'] ?? '') . ' ' . ($visitor['asn'] ?? ''));

    $logic = orbitraStreamFilterLogic($stream);
    $votes = [];

    foreach ($filters as $f) {
        $mode = $f['mode'] ?? 'include';
        $payload = $f['payload'] ?? [];
        if (empty($payload))
            continue;

        $matched = false;
        switch ($f['name']) {
            case 'Country':
                // Free geo databases cannot always resolve an IP. To avoid
                // silently dropping real traffic, an undetermined country
                // (Unknown/Local/empty) passes the country gate instead of
                // being blocked.
                if ($country === '' || $country === 'Unknown' || $country === 'Local') {
                    continue 2;
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
                $botVerdict = CloakDetector::detectBotFilter([
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
                    'pdo' => $pdo,
                ]);
                $matched = (bool) ($botVerdict['is_suspicious'] ?? false);
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
            case 'IP':
                foreach ($payload as $item) {
                    if (ipMatchesToken($item, $ip)) {
                        $matched = true;
                        break;
                    }
                }
                break;
            case 'Referer':
                $ref = strtolower($referer);
                foreach ($payload as $item) {
                    $needle = strtolower(trim((string) $item));
                    if ($needle !== '' && strpos($ref, $needle) !== false) {
                        $matched = true;
                        break;
                    }
                }
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
                // Connection type (mobile/wifi/cable) has no free data source —
                // skip without affecting stream selection.
                continue 2;
            default:
                // Unknown filter type — do not block traffic.
                continue 2;
        }

        // Filters that reach this point vote; the `continue 2` cases above
        // (undeterminable country/ISP, connection type, unknown types)
        // abstain — an abstention neither blocks AND nor satisfies OR.
        $votes[] = ($mode === 'include') ? $matched : !$matched;
    }
    return orbitraCombineFilterVotes($votes, $logic);
}

// Convert "HH" or "HH:MM" to minutes since midnight, or null if invalid.
function timeToMinutes($value)
{
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

$selectedStream = null;

// Контекст посетителя для сопоставления фильтров потоков
$visitor = [
    'ip' => $ip,
    'country' => $country,
    'device' => $deviceType,
    'os' => $os,
    'browser' => $browser,
    'languageCodes' => $languageCodes,
    'userAgent' => $userAgent,
    'referer' => $referer,
    'params' => $clickParams,
    'timezone' => $timezone,
    'isp' => $geoData['isp'] ?? '',
    'asn' => $geoData['asn'] ?? '',
    'isProxy' => $geoData['is_proxy'] ?? 0,
    'proxyType' => $geoData['proxy_type'] ?? '',
    'proxyThreat' => $geoData['proxy_threat'] ?? '',
    'proxyProvider' => $geoData['proxy_provider'] ?? '',
    'proxyFraudScore' => $geoData['proxy_fraud_score'] ?? null,
    'acceptLanguage' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
];

// Пытаемся найти перехватывающий
foreach ($allStreams as $stream) {
    if (($stream['type'] ?? 'regular') === 'intercepting') {
        if (streamMatchesFilters($stream, $visitor, $pdo)) {
            $selectedStream = $stream;
            break;
        }
    }
}

// Если не найден перехватывающий, отбираем обычные
if (!$selectedStream) {
    $regular = array_filter($allStreams, function ($s) use ($visitor, $pdo) {
        return ($s['type'] ?? 'regular') === 'regular' && streamMatchesFilters($s, $visitor, $pdo);
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
            if (streamMatchesFilters($stream, $visitor, $pdo)) {
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
$offerRedirectType = 'redirect';
$landingRedirectType = 'redirect';
$actionToPerfrom = null;
$skipClickLogging = false;
$deferredSafeHtml = null;

if ($selectedStream) {
    $schemaType = $selectedStream['schema_type'] ?? 'redirect';
    $customSchema = json_decode($selectedStream['schema_custom_json'] ?? '{}', true);
    if (!is_array($customSchema))
        $customSchema = [];

    if ($schemaType === 'action') {
        $actionToPerfrom = $selectedStream['action_payload'] ?? 'do_nothing';
    } else if ($schemaType === 'landing_offer') {
        $selectedLanding = selectWeightedItem($customSchema['landings'] ?? []);

        // "After the click" defers the choice to the moment the visitor leaves the
        // landing, so a slot that ran out of cap while they were reading is not
        // already burned on them. The click is logged without an offer and gets
        // one when /?_lp=1 fires.
        $deferOffer = ($selectedStream['offer_selection'] ?? 'before') === 'after';
        $selectedOffer = $deferOffer ? null : selectWeightedItem($customSchema['offers'] ?? []);

        if ($selectedOffer)
            $offerIdToLog = $selectedOffer['id'] ?? 0;
        if ($selectedLanding)
            $landingIdToLog = $selectedLanding['id'] ?? null;

        $landingType = null;
        $landingUrl = null;
        $landingAction = null;
        $landingActionType = '';
        $offerUrl = null;

        if ($landingIdToLog) {
            $stmt = $pdo->prepare("SELECT type, url, action_payload, action_type, redirect_type FROM landings WHERE id = ?");
            $stmt->execute([$landingIdToLog]);
            $land = $stmt->fetch();
            if ($land) {
                $landingType = $land['type'];
                $landingUrl = $land['url'];
                $landingAction = $land['action_payload'];
                $landingActionType = $land['action_type'] ?? '';
                $landingRedirectType = $land['redirect_type'] ?? 'redirect';
            }
        }

        if ($offerIdToLog) {
            $stmt = $pdo->prepare("SELECT url, redirect_type FROM offers WHERE id = ?");
            $stmt->execute([$offerIdToLog]);
            $off = $stmt->fetch();
            if ($off) {
                $offerUrl = $off['url'];
                $offerRedirectType = $off['redirect_type'] ?? 'redirect';
            }
        }

        if ($landingType === 'redirect' && !empty($landingUrl)) {
            $finalUrl = $landingUrl;
            $offerRedirectType = $landingRedirectType;
        } else if (!empty($offerUrl) && ($landingType === null || $landingType === 'redirect')) {
            $finalUrl = $offerUrl;
        }
    } else if ($schemaType === 'cloak') {
        // Cloaking: route suspicious visitors (bots / moderators / datacenter traffic)
        // to a safe page, real visitors to the money page. The detector's verdict is
        // computed once here; the chosen branch reuses the same landing/offer serving
        // logic as the landing_offer and redirect schemas.
        $cloakConfig = [
            'detect_datacenter' => $customSchema['detect_datacenter'] ?? true,
            'detect_vpn'        => $customSchema['detect_vpn'] ?? true,
            'detect_bots'       => $customSchema['detect_bots'] ?? true,
            'detect_ua'         => $customSchema['detect_ua'] ?? true,
            'sensitivity'       => $customSchema['sensitivity'] ?? 'medium',
        ];
        $cloakVisitorCtx = [
            'ip'              => $ip,
            'user_agent'      => $userAgent,
            'asn'             => $geoData['asn'] ?? '',
            'isp'             => $geoData['isp'] ?? '',
            'is_proxy'        => $geoData['is_proxy'] ?? 0,
            'proxy_type'      => $geoData['proxy_type'] ?? '',
            'proxy_threat'    => $geoData['proxy_threat'] ?? '',
            'proxy_provider'  => $geoData['proxy_provider'] ?? '',
            'proxy_fraud_score' => $geoData['proxy_fraud_score'] ?? null,
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        ];
        $verdict = CloakDetector::detect($cloakVisitorCtx, $cloakConfig);
        $cloakShowSafe = (bool) $verdict['is_suspicious'];

        $jsChallengeEnabled = filter_var(
            $customSchema['js_challenge'] ?? false,
            FILTER_VALIDATE_BOOL
        );
        $jsFailure = (string) ($_GET['_ocjf'] ?? '');
        if (!$cloakShowSafe && $jsChallengeEnabled && $jsFailure === 'webdriver') {
            $cloakShowSafe = true;
            logCloakEvent(
                'JS_SAFE',
                $campaignId ?? '?',
                $selectedStream['id'] ?? '?',
                $cloakVisitorCtx,
                ['webdriver'],
                $cloakConfig['sensitivity']
            );
        }

        if ($verdict['is_suspicious']) {
            logCloakEvent(
                'PASSIVE_SAFE',
                $campaignId ?? '?',
                $selectedStream['id'] ?? '?',
                $cloakVisitorCtx,
                $verdict['reasons'],
                $cloakConfig['sensitivity']
            );
        }

        // Quick targeting filters from the cloak card: hard routing rules, not
        // heuristics — a country/device/Bot-ISP miss goes to the safe page
        // whatever the detector said.
        if (!$cloakShowSafe) {
            $targetingReasons = CloakDetector::targetingReasons(
                $customSchema,
                (string) $countryCode,
                (string) $deviceType,
                ($geoData['isp'] ?? '') . ' ' . ($geoData['asn'] ?? ''),
                (string) ($settings['bot_isp_list'] ?? '')
            );
            if (!empty($targetingReasons)) {
                $cloakShowSafe = true;
                logCloakEvent(
                    'TARGETING_SAFE',
                    $campaignId ?? '?',
                    $selectedStream['id'] ?? '?',
                    $cloakVisitorCtx,
                    $targetingReasons,
                    $cloakConfig['sensitivity']
                );
            }
        }

        // Optional active step: a visitor who passed the passive layers still has to
        // prove it runs a real browser before the money page is served. See
        // renderCloakJsChallenge(). Off by default — it adds a round trip.
        if (!$cloakShowSafe && $jsChallengeEnabled) {
            $cloakSecret = $settings['postback_key'] ?? 'orbitra_secret';
            $jsToken = $_GET['_ocj'] ?? '';
            $jsSig   = $_GET['_ocs'] ?? '';

            if (!validateCloakJsToken($jsToken, $jsSig, $cloakSecret, $campaignId)) {
                // Not yet verified: show the safe page and probe in the background.
                [$newToken, $newSig] = generateCloakJsToken($campaignId, $cloakSecret);
                $nextParams = array_diff_key($_GET, array_flip(['_ocj', '_ocs', '_ocjf']));
                $nextParams['_ocj'] = $newToken;
                $nextParams['_ocs'] = $newSig;
                $nextUrl = '?' . http_build_query($nextParams);

                $webdriverParams = array_diff_key($nextParams, array_flip(['_ocj', '_ocs']));
                $webdriverParams['_ocjf'] = 'webdriver';
                $webdriverUrl = '?' . http_build_query($webdriverParams);

                $safeHtml = '';
                if (isset($customSchema['safe_html']) && $customSchema['safe_html'] !== '') {
                    $safeHtml = (string) $customSchema['safe_html'];
                } else {
                    $safeHtml = '<!DOCTYPE html><html><head><meta charset="utf-8">'
                        . '<title>Welcome</title></head><body><h1>Page</h1>'
                        . '<p>Content is loading.</p></body></html>';
                }
                renderCloakJsChallenge($safeHtml, $nextUrl, $webdriverUrl);
                exit;
            }
        }

        $skipClickLogging = CloakDetector::shouldSkipSafePageClick($customSchema, $cloakShowSafe);

        if ($cloakShowSafe) {
            // --- Safe page ---
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
                $stmt = $pdo->prepare("SELECT type, url, action_payload, action_type, redirect_type FROM landings WHERE id = ?");
                $stmt->execute([$safeLandingId]);
                $safeLand = $stmt->fetch();
                if ($safeLand) {
                    $landingIdToLog = $safeLandingId;
                    $landingType = $safeLand['type'];
                    $landingUrl = $safeLand['url'];
                    $landingAction = $safeLand['action_payload'];
                    $landingActionType = $safeLand['action_type'] ?? '';
                    $landingRedirectType = $safeLand['redirect_type'] ?? 'redirect';
                    if ($landingType === 'redirect' && !empty($landingUrl)) {
                        $finalUrl = $landingUrl;
                        $offerRedirectType = $landingRedirectType;
                    }
                }
                if (!$safeLand) {
                    $deferredSafeHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Welcome</title></head><body><h1>Page</h1><p>Content is loading.</p></body></html>';
                }
            } elseif ($safeMode === 'offer' && $safeOfferId > 0) {
                // A local offer as the white page: mark it as the click's offer
                // and let the direct local-offer branch at the redirect stage
                // serve the uploaded archive inline, with the same macros and
                // orbitra_lo cookie handling a money-page offer gets.
                if (orbitraOfferIsLocal($pdo, $safeOfferId)) {
                    $offerIdToLog = $safeOfferId;
                } else {
                    $deferredSafeHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Welcome</title></head><body><h1>Page</h1><p>Content is loading.</p></body></html>';
                }
            } elseif ($safeMode === 'url' && !empty($customSchema['safe_url'])) {
                $finalUrl = (string) $customSchema['safe_url'];
            } else {
                // Defer output until after the optional click insert. Enabled
                // no-log streams skip that insert; an explicit false keeps the
                // checkbox reversible even for inline HTML safe pages.
                $deferredSafeHtml = isset($customSchema['safe_html']) && $customSchema['safe_html'] !== ''
                    ? $customSchema['safe_html']
                    : '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Welcome</title></head><body><h1>Page</h1><p>Content is loading.</p></body></html>';
            }
        } else {
            // --- Money page --- behaves like the landing_offer schema: a weighted landing
            // and/or offer selection. Falls back to a plain redirect if only an offer is set.
            $selectedLanding = selectWeightedItem($customSchema['landings'] ?? []);
            $selectedOffer = selectWeightedItem($customSchema['offers'] ?? []);
            if ($selectedOffer) {
                $offerIdToLog = $selectedOffer['id'] ?? 0;
            }
            if ($selectedLanding) {
                $landingIdToLog = $selectedLanding['id'] ?? null;
            }

            if ($landingIdToLog) {
                $stmt = $pdo->prepare("SELECT type, url, action_payload, action_type, redirect_type FROM landings WHERE id = ?");
                $stmt->execute([$landingIdToLog]);
                $land = $stmt->fetch();
                if ($land) {
                    $landingType = $land['type'];
                    $landingUrl = $land['url'];
                    $landingAction = $land['action_payload'];
                    $landingActionType = $land['action_type'] ?? '';
                    $landingRedirectType = $land['redirect_type'] ?? 'redirect';
                }
            }
            if ($offerIdToLog) {
                $stmt = $pdo->prepare("SELECT url, redirect_type FROM offers WHERE id = ?");
                $stmt->execute([$offerIdToLog]);
                $off = $stmt->fetch();
                if ($off) {
                    $offerUrl = $off['url'];
                    $offerRedirectType = $off['redirect_type'] ?? 'redirect';
                }
            }

            if ($landingType === 'redirect' && !empty($landingUrl)) {
                $finalUrl = $landingUrl;
                $offerRedirectType = $landingRedirectType;
            } else if (!empty($offerUrl) && ($landingType === null || $landingType === 'redirect')) {
                $finalUrl = $offerUrl;
            }
        }
    } else { // redirect
        if (!empty($customSchema['direct_url']) || ($customSchema['redirect_mode'] ?? '') === 'direct_url') {
            $directUrl = trim((string) ($customSchema['direct_url'] ?? ''));
            if ($directUrl !== '') {
                $finalUrl = $directUrl;
                $offerUrl = $directUrl;
                $offerRedirectType = $customSchema['redirect_type'] ?? 'redirect';
            }
        }

        if (empty($finalUrl)) {
            $selectedOffer = selectWeightedItem($customSchema['offers'] ?? []);

            // Support direct URL inside offers array or lookup from offers table
            if ($selectedOffer) {
                if (!empty($selectedOffer['url'])) {
                    $finalUrl = $selectedOffer['url'];
                    $offerUrl = $selectedOffer['url'];
                    $offerRedirectType = $selectedOffer['redirect_type'] ?? 'redirect';
                } else {
                    $offerIdToLog = $selectedOffer['id'] ?? 0;
                }
            } else {
                $offerIdToLog = $selectedStream['offer_id'] ?? 0;
            }

            if ($offerIdToLog && empty($finalUrl)) {
                $stmt = $pdo->prepare("SELECT url, redirect_type FROM offers WHERE id = ?");
                $stmt->execute([$offerIdToLog]);
                $offer = $stmt->fetch();
                if ($offer) {
                    $finalUrl = $offer['url'];
                    $offerUrl = $offer['url'];
                    $offerRedirectType = $offer['redirect_type'] ?? 'redirect';
                }
            }
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

// Stream-level "Collect clicks": the stream still serves its destination,
// but the visit never reaches the stats — the same skip path prefetch and the
// cloak safe page use. No clicks row also means no sub_id: conversions from
// this stream have nothing to attach to, which is the point for white pages.
$streamCollectsClicks = !$selectedStream || (int) ($selectedStream['collect_clicks'] ?? 1) === 1;

// A prefetch hit serves the campaign but never reaches the stats.
if ($statsEnabled && !$isDebounced && !$isPrefetchRequest && !$skipClickLogging && $streamCollectsClicks) {
    // No offer (e.g. landing-only stream) must be logged as NULL, not 0, to
    // avoid the offers(id) foreign-key violation.
    $offerIdForDb = !empty($offerIdToLog) ? $offerIdToLog : null;
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
            $offerIdForDb,
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

        // Honesty flags for the report metrics (bots/proxies/uniqueness) —
        // one UPDATE, never allowed to break the click itself.
        require_once __DIR__ . '/core/ClickFlags.php';
        orbitraWriteClickFlags($pdo, $clickId, $ip, $userAgent, $campaign ?? [], $streamIdToLog ?? 0, is_array($geoData ?? null) ? $geoData : []);
    } catch (\Throwable $e) {
        // Never let click logging break the redirect/landing. Log and continue.
        error_log('Orbitra click logging failed: ' . $e->getMessage());
    }
}

if (!$selectedStream) {
    die("Do nothing.");
}

if ($deferredSafeHtml !== null) {
    header('Content-Type: text/html; charset=utf-8');
    echo $deferredSafeHtml;
    exit;
}

// 6. Редирект или Выполнение действия
if ($actionToPerfrom) {
    // A stream action stores "type" or "type:payload" — the second form carries
    // the text, HTML or target campaign id the action needs.
    $streamActionType = $actionToPerfrom;
    $streamActionPayload = '';
    if (strpos($actionToPerfrom, ':') !== false) {
        [$streamActionType, $streamActionPayload] = explode(':', $actionToPerfrom, 2);
    }
    performTrackerAction($streamActionType, $streamActionPayload);
} else {
    $offerUrlMacros = str_replace('{clickid}', $clickId, $offerUrl ?? '');

    // Remember this click so a landing's offer link (/?_lp=1) can resolve the
    // bound offer later. Must be set before any landing output.
    if (!empty($landingIdToLog) && !headers_sent()) {
        $lpSecure = orbitraIsHttps();
        $lpCookieOpts = ['expires' => time() + 86400, 'path' => '/', 'secure' => $lpSecure, 'httponly' => false, 'samesite' => 'Lax'];
        setcookie('orbitra_click', $clickId, $lpCookieOpts);
        setcookie('subid', $clickId, $lpCookieOpts);
        if (!empty($offerIdToLog)) {
            setcookie('orbitra_offer', (string) $offerIdToLog, $lpCookieOpts);
        }
        // Lets serveLandingAsset() find the files behind this page's relative
        // paths, which the browser will request from the domain root.
        if (($landingType ?? '') === 'local') {
            setcookie('orbitra_lp', (string) $landingIdToLog, $lpCookieOpts);
            // Time-since-LP-click starts here: remember when the landing was shown.
            try {
                $pdo->prepare("UPDATE clicks SET landing_at = datetime('now') WHERE id = ? AND landing_at IS NULL")->execute([$clickId]);
            } catch (\Throwable $e) {
                // Timing is a nice-to-have.
            }
            // The landing is the page in play now — a leftover offer cookie from
            // an earlier visit would steal its asset requests.
            setcookie('orbitra_lo', '', ['expires' => time() - 3600, 'path' => '/']);
        }
    }

    if (isset($landingType) && $landingType !== 'redirect') {
        if ($landingType === 'local') {
            // Resolves through single-nested folders and drops statcache — the
            // very first click after an upload must find the files too.
            $landingDir = orbitraLandingContentDir(orbitraLandingDir($pdo, $landingIdToLog));

            // Fetch landing slug for <base> tag injection (Incognito fix)
            $landingSlug = '';
            try {
                $slugStmt = $pdo->prepare("SELECT slug FROM landings WHERE id = ? LIMIT 1");
                $slugStmt->execute([$landingIdToLog]);
                $landingSlug = (string) ($slugStmt->fetchColumn() ?? '');
            } catch (\Throwable $e) {
                $landingSlug = '';
            }

            if (file_exists($landingDir . '/index.php')) {
                require_once __DIR__ . '/core/PhpLanding.php';
                if (!PhpLanding::enabled($pdo)) {
                    // The file is there but the feature is off — say so instead of
                    // serving source or a blank page.
                    http_response_code(503);
                    header('Content-Type: text/plain; charset=utf-8');
                    echo 'This landing is written in PHP, which is disabled on this tracker. '
                        . 'Enable it in Settings -> General -> "Allow PHP landings" if you trust its code.';
                    exit;
                }

                // A hung landing otherwise occupies a PHP-FPM worker until the
                // server's own limit, which on a small box means the site stops
                // answering. set_time_limit is disallowed inside the landing itself.
                @set_time_limit(PhpLanding::timeout($pdo));

                // Keitaro-compatible: the click is reachable as $rawClick->get('...').
                $rawClick = new OrbitraRawClick(array_merge(
                    $clickParams ?? [],
                    [
                        'click_id' => $clickId,
                        'subid' => $clickId,
                        'campaign_id' => $campaignId ?? null,
                        'offer_id' => $offerIdToLog,
                        'landing_id' => $landingIdToLog,
                        'offer' => $offerUrlMacros,
                    ]
                ));

                ob_start();
                require $landingDir . '/index.php';
                $landingOutput = ob_get_clean();
                $processed = applyLandingMacros(
                    $landingOutput,
                    $clickId,
                    $offerIdToLog,
                    $offerUrlMacros,
                    $clickParams ?? [],
                    issueLpToken($clickId, $settings['postback_key'] ?? 'orbitra_secret')
                );
                // Inject <base> tag so assets resolve correctly in Incognito/Private mode
                echo orbitraInjectBaseTag(orbitraRewriteAssetPaths($processed), '/lander/' . ($landingSlug !== '' ? htmlspecialchars($landingSlug, ENT_QUOTES) : $landingIdToLog) . '/');
            } else if (file_exists($landingDir . '/index.html')) {
                $processed = applyLandingMacros(
                    file_get_contents($landingDir . '/index.html'),
                    $clickId,
                    $offerIdToLog,
                    $offerUrlMacros,
                    $clickParams ?? [],
                    issueLpToken($clickId, $settings['postback_key'] ?? 'orbitra_secret')
                );
                // Inject <base> tag so assets resolve correctly in Incognito/Private mode
                echo orbitraInjectBaseTag(orbitraRewriteAssetPaths($processed), '/lander/' . ($landingSlug !== '' ? htmlspecialchars($landingSlug, ENT_QUOTES) : $landingIdToLog) . '/');
            } else {
                die("Local landing files not found in " . $landingDir);
            }
            exit;
        } else if ($landingType === 'action') {
            $payload = applyLandingMacros(
                (string) $landingAction,
                $clickId,
                $offerIdToLog,
                $offerUrlMacros,
                $clickParams ?? [],
                issueLpToken($clickId, $settings['postback_key'] ?? 'orbitra_secret')
            );
            performTrackerAction($landingActionType ?? '', $payload);
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

    // A selected landing is always the destination, regardless of whether an
    // offer is attached to the stream. Local / action / preload landings were
    // already served above; redirect landings (and any landing carrying a URL)
    // redirect here. This makes "landing only" streams work without an offer.
    if (!$finalUrl && !empty($landingIdToLog) && !empty($landingUrl)) {
        $finalUrl = $landingUrl;
        // The destination is the landing rather than the offer, so the landing's
        // own redirect method (http/js/meta_refresh) applies on the final hop.
        $offerRedirectType = $landingRedirectType;

        // A landing on another domain cannot read this tracker's cookies, so its
        // offer link has nothing to identify the visitor with. Hand it a signed
        // token (and the raw subid, which Keitaro-shaped snippets expect) that
        // /?_lp=1 will accept in the cookie's place.
        if ($landingType === 'redirect') {
            $lpSecretOut = $settings['postback_key'] ?? 'orbitra_secret';
            $finalUrl .= (strpos($finalUrl, '?') === false ? '?' : '&')
                . '_subid=' . rawurlencode($clickId)
                . '&_token=' . rawurlencode(issueLpToken($clickId, $lpSecretOut));
        }
    }

    // Default behavior is redirect. Use redirect=0 for debug/integration checks.
    $shouldRedirect = ($_GET['redirect'] ?? '1') !== '0';

    // A direct local offer has no URL to redirect to — its uploaded page IS the
    // destination and is served below, so an empty URL must not die on the way
    // there. This is what makes "Direct Local Offer" streams (and a local offer
    // picked as the cloak Safe Page) reachable at all.
    $pendingLocalOffer = $shouldRedirect && !empty($offerIdToLog) && orbitraOfferIsLocal($pdo, $offerIdToLog);
    if (!$finalUrl && !$pendingLocalOffer) {
        die("URL not found.");
    }

    // A NULL url on the picked offer (e.g. a direct local offer reached this
    // point) must not leak into the macro substitution below.
    if ($finalUrl === null) {
        $finalUrl = '';
    }

    // Подстановка макросов.
    //
    // Direct-URL macros: the stream editor tells the user that {subid}, {clickid},
    // {country}, {ip} and {sub_id_1}..{sub_id_30} work in a direct destination URL,
    // so all of them have to be substituted here — {subid} used to travel to the
    // affiliate network as the literal string.
    $finalUrl = str_replace(
        ['{clickid}', '{subid}', '{ip}', '{country}'],
        [$clickId, $clickId, urlencode((string) $ip), urlencode((string) $country)],
        $finalUrl
    );

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
    if ($finalUrl !== '' && !preg_match('#^(https?:)?//#i', $finalUrl) && !preg_match('#^/#', $finalUrl) && !preg_match('#^(mailto|tel):#i', $finalUrl)) {
        $finalUrl = 'http://' . ltrim($finalUrl, '/');
    }

    // A local offer replaces the redirect: the visitor stays on the tracker and
    // gets the offer's uploaded page inline (same machinery as local landings).
    if ($shouldRedirect && !empty($offerIdToLog) && orbitraOfferIsLocal($pdo, $offerIdToLog)) {
        orbitraServeLocalOffer($pdo, $offerIdToLog, $clickId, $clickParams ?? [], $settings);
    }

    if ($shouldRedirect) {
        renderRedirectResponse($offerRedirectType, $finalUrl);
    } else {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode([
            'status' => 'ok',
            'click_id' => $clickId,
            'url' => $finalUrl,
            'redirect_type' => $offerRedirectType,
        ]);
    }
    exit;
}
