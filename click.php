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
require_once __DIR__ . '/core/Device.php';
require_once __DIR__ . '/core/UserAgent.php';
require_once __DIR__ . '/core/OfferUrl.php';
require_once __DIR__ . '/core/CloakDetector.php';
require_once __DIR__ . '/core/click_logger.php';
// The shared geo resolver (orbitraClickApiGetGeoData) lives with the Click API
// helpers: country and city, plus the network identity the stream filters and
// the cloak decision need.
require_once __DIR__ . '/core/click_api.php';
// Same prefetch guard as the main click path — a speculative request here would
// otherwise be counted as a real click.
require_once __DIR__ . '/core/prefetch.php';
// IP access control with secure Cloudflare-aware client IP resolution.
require_once __DIR__ . '/core/ip_access.php';
require_once __DIR__ . '/session_bootstrap.php';

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

// Heal malformed query strings (double "?" from Facebook Ads / cloakers)
// BEFORE the campaign lookup: the routing keys (campaign_id / token) are the
// first query pair and are exactly what a double "?" corrupts. Healing after
// the lookup would recover tracking params for a click the router already
// rejected. No-op for normal traffic.
require_once __DIR__ . '/core/ClickParams.php';
orbitraHealQueryString($_GET, $_SERVER['QUERY_STRING'] ?? '');

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
    $stmt->closeCursor();
    if ($campaign) {
        $campaignId = (int) ($campaign['id'] ?? 0);
    }
} else if ($campaignId) {
    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ? LIMIT 1");
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch();
    $stmt->closeCursor();
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

// Stopped campaigns must not take traffic. The state gate runs only after the
// token matched above: an ID-only request for a token-protected campaign is
// rejected with 403 first, so the 503/404 answers below cannot be used to
// probe other campaigns' state by guessing IDs. Non-existent campaigns keep
// the plain 404 from the lookup above.
$campaignState = strtolower((string) ($campaign['state'] ?? 'active'));
if ($campaignState === 'disabled' || $campaignState === 'paused') {
    http_response_code(503);
    echo json_encode(['error' => 'Campaign is not accepting traffic']);
    exit;
}
if ((int) ($campaign['is_archived'] ?? 0) === 1) {
    http_response_code(404);
    echo json_encode(['error' => 'Campaign not found']);
    exit;
}

// Signed test link (?_t=…): route through the real stream selection but write
// nothing — the same no-log rule the router applies. The signature is keyed by
// the instance postback_key and bound to THIS campaign's id + alias.
$isTestClick = false;
$testGeoOverride = '';
if (isset($_GET['_t'])) {
    require_once __DIR__ . '/core/test_links.php';
    $testSecret = 'orbitra_secret';
    try {
        $testSecretRow = $pdo->query("SELECT value FROM settings WHERE key = 'postback_key' LIMIT 1")->fetchColumn();
        if (is_string($testSecretRow) && $testSecretRow !== '') {
            $testSecret = $testSecretRow;
        }
    } catch (\Throwable $e) {
        // Falls back to the constant; a wrong key only rejects, never accepts.
    }
    $testSigCampaign = orbitraValidTestSignature((string) $_GET['_t'], $pdo, $testSecret);
    if ($testSigCampaign !== null && (int) ($testSigCampaign['id'] ?? 0) === (int) $campaignId) {
        $isTestClick = true;
        if (isset($_GET['_geo']) && preg_match('/^[A-Za-z]{2}$/', (string) $_GET['_geo']) === 1) {
            $testGeoOverride = strtoupper((string) $_GET['_geo']);
        }
    }
}

function clickGetDeviceType($ua)
{
    return orbitraDetectDeviceType((string) $ua);
}

// Coarse families stored in clicks.os / clicks.browser — see core/UserAgent.php.
function clickDetectOs($userAgent)
{
    return orbitraUaOsFamily((string) $userAgent);
}

function clickDetectBrowser($userAgent)
{
    return orbitraUaBrowserFamily((string) $userAgent);
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

// ТЗ 1.9.8: one generator for every entry point — see core/click_logger.php
// (UUIDv7, time-ordered: inserts append to the PK index right edge).
function clickGenerateUuid()
{
    return orbitraGenerateClickId();
}

$ip = orbitraClientIp();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

// Wrap the click-logging path so a failure (geo lookup, DB insert, etc.)
// is reported as a JSON error + logged to system_logs, instead of a bare
// HTTP 500 with an empty body. A bare 500 is undebuggable from a client
// and looks like a broken tracker to integration scripts.
try {
// The same resolver the Click API uses. The one that used to live here stopped
// at the city: no ASN, no ISP, no proxy — so the Bot and ISP filters had no
// network to judge, the cloak decision only saw an ISP when an IP2Location ASN
// file happened to be installed, and every click logged here carried an empty
// ISP / ASN. Local databases only, like the router.
$geoData = orbitraClickApiGetGeoData($ip);

// The settings the click path hands to the cloak decision and to the "Bot"
// stream filter (index.php loads the same two keys).
$clickSettings = [];
foreach ($pdo->query("SELECT key, value FROM settings WHERE key IN ('bot_isp_list', 'browser_check_mode')") as $settingsRow) {
    $clickSettings[$settingsRow['key']] = $settingsRow['value'];
}
$country = $geoData['country_code'];
$countryCode = $geoData['country_code'];
// A valid ?_geo= on a test link overrides the resolved country: cloak country
// rules and {country} macros then see the test country.
if ($testGeoOverride !== '') {
    $country = $testGeoOverride;
    $countryCode = $testGeoOverride;
}
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
// (ClickParams.php is already required and $_GET healed at the top of the file,
// before campaign routing — see the orbitraHealQueryString call there.)
$clickParams = orbitraCollectClickParams($pdo, array_merge($_GET, $_POST), $_COOKIE, $campaign['source_id'] ?? null, true, (int) $campaignId);
$parametersJson = json_encode($clickParams, JSON_UNESCAPED_UNICODE);

// Check stats_enabled setting. The statement is consumed in one expression
// on purpose: kept in a variable it stays mid-scan (the row was found) and
// holds a WAL read snapshot until request end (ТЗ 1.9.7).
$statsEnabledRow = $pdo->query("SELECT value FROM settings WHERE key = 'stats_enabled'")->fetchColumn();
$statsEnabled = $statsEnabledRow !== '0';

// Universal function for weighted selection locally in click.php
function clickSelectWeightedItem($items)
{
    if (empty($items))
        return null;
    $items = array_values(array_filter($items, function($it) {
        if (isset($it['state']) && ($it['state'] === 'disabled' || $it['state'] === 'paused')) return false;
        if (isset($it['is_active']) && ($it['is_active'] === false || $it['is_active'] === 0 || $it['is_active'] === '0')) return false;
        return true;
    }));
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

// Stream selection — the router's rule (index.php): the intercepting streams
// first, then the regular ones by position or by weight, then the fallback. A
// stream's own filters decide whether it takes the visitor, and a cloak stream
// in gate mode ("send clean visitors to the next stream") that finds this
// visitor clean is skipped in favour of the streams below it.
//
// click.php used to take the first stream by position, or a weighted pick over
// every stream whatever its type, and never looked at the filters — so every
// visitor of a campaign saw its first stream: the first country stream of a
// Keitaro import, the "404 for bots" interceptor, whichever came first.
require_once __DIR__ . '/core/stream_match.php';
require_once __DIR__ . '/core/cloak_gate.php';
require_once __DIR__ . '/core/stream_action.php';

$stmt = $pdo->prepare("SELECT * FROM streams WHERE campaign_id = ? AND is_active = 1 ORDER BY position ASC, id ASC");
$stmt->execute([$campaignId]);
$allStreams = $stmt->fetchAll();

// The visitor exactly as index.php builds it for streamMatchesFilters().
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
    'rawParams' => array_merge($_GET, $_POST),
    'timezone' => $timezone,
    'isp' => $geoData['isp'] ?? '',
    'asn' => $geoData['asn'] ?? '',
    'carrier' => $geoData['carrier'] ?? '',
    'connectionType' => $geoData['connection_type'] ?? '',
    'isProxy' => $geoData['is_proxy'] ?? 0,
    'proxyType' => $geoData['proxy_type'] ?? '',
    'proxyThreat' => $geoData['proxy_threat'] ?? '',
    'proxyProvider' => $geoData['proxy_provider'] ?? '',
    'proxyFraudScore' => $geoData['proxy_fraud_score'] ?? null,
    'acceptLanguage' => $acceptLanguageRaw,
    // The global Bot ISP list, already loaded with the settings: the "Bot"
    // stream filter consults it (CloakDetector::detectBotFilter).
    'botIspList' => $clickSettings['bot_isp_list'] ?? '',
];
$cloakGateCtx = [
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
];
// What the router reads from ?_ocjf= once the cloak JS probe reports webdriver.
$jsFailure = (string) ($_GET['_ocjf'] ?? '');
// click.php has no JS-challenge round trip, so "the detector finds the visitor
// clean" is all it asks of a gate (the router also wants a challenge token).
$gatePasses = static function (array $candidate) use ($cloakGateCtx, $clickSettings, $countryCode, $deviceType, $jsFailure): bool {
    $decision = orbitraCloakGateDecision($candidate, $cloakGateCtx, $clickSettings, (string) $countryCode, (string) $deviceType, $jsFailure);
    return $decision !== null && empty($decision['show_safe']);
};
$streamSelectable = static function ($candidate) use ($visitor, $pdo, $gatePasses): bool {
    return streamMatchesFilters($candidate, $visitor, $pdo) && !$gatePasses($candidate);
};

$stream = null;
foreach ($allStreams as $candidate) {
    if (($candidate['type'] ?? 'regular') === 'intercepting' && $streamSelectable($candidate)) {
        $stream = $candidate;
        break;
    }
}
if (!$stream) {
    $regular = array_filter($allStreams, static fn ($s) => ($s['type'] ?? 'regular') === 'regular' && $streamSelectable($s));
    if ($regular) {
        $stream = ($campaign['rotation_type'] ?? 'position') === 'weight'
            ? clickSelectWeightedItem($regular)
            : reset($regular);
    }
}
if (!$stream) {
    foreach ($allStreams as $candidate) {
        if (($candidate['type'] ?? '') === 'fallback' && $streamSelectable($candidate)) {
            $stream = $candidate;
            break;
        }
    }
}

// null (not 0) when there is no offer — clicks.offer_id is a FK to
// offers(id), and id=0 never exists, so a 0 here trips FOREIGN KEY
// constraint failed on campaigns that have no stream/offer assigned.
$offerId = null;
$streamId = null;
$sourceId = $campaign['source_id'] ?? null;
$offerUrl = '';
$skipClickLogging = false;
$safeResponseBody = null;
$safeResponseContentType = 'text/html; charset=utf-8';
$cloakShowSafe = false;
// "type" or "type:payload" of a stream with schema "action" — performed after the click is logged.
$actionToPerform = null;

if ($stream) {
    $streamId = $stream['id'];
    $customSchema = json_decode($stream['schema_custom_json'] ?? '{}', true);
    if (!is_array($customSchema)) {
        $customSchema = [];
    }
    $schemaType = (string) ($stream['schema_type'] ?? 'redirect');

    // A stream action ("404", text, HTML, "do nothing", "to another campaign"):
    // performed by the router's own performer once the click is logged below.
    if ($schemaType === 'action') {
        $actionToPerform = (string) ($stream['action_payload'] ?? 'do_nothing');
    }

    if ($schemaType === 'cloak') {
        // Compute cloak routing decision using shared function (W1): the passive
        // layers, the cloak card's targeting rules and the JS-probe failure —
        // the same verdict, on the same geo data, as the router's.
        $cloakVisitor = [
            'ip' => $ip,
            'user_agent' => $userAgent,
            'asn' => (string) ($geoData['asn'] ?? ''),
            'isp' => (string) ($geoData['isp'] ?? ''),
            'is_proxy' => (int) ($geoData['is_proxy'] ?? 0),
            'proxy_type' => (string) ($geoData['proxy_type'] ?? ''),
            'proxy_threat' => (string) ($geoData['proxy_threat'] ?? ''),
            'proxy_provider' => (string) ($geoData['proxy_provider'] ?? ''),
            'proxy_fraud_score' => $geoData['proxy_fraud_score'] ?? null,
            'accept_language' => $acceptLanguageRaw,
            'pdo' => $pdo,
        ] + orbitraCloakVisitorHeaders();

        $cloakDecision = orbitraCloakDecision(
            $customSchema,
            $cloakVisitor,
            $clickSettings,
            (string) $countryCode,
            (string) $deviceType,
            $jsFailure
        );

        $cloakShowSafe = (bool) $cloakDecision['show_safe'];
        $cloakVerdict = $cloakDecision['verdict'];
        $cloakReasons = $cloakDecision['reasons'];
        $skipClickLogging = (bool) $cloakDecision['skip_click_log'];

        // Keep error_log logging for post-mortems
        if ($cloakVerdict) {
            $cloakVisitorCtx = [
                'ip' => $ip,
                'user_agent' => $userAgent,
                'asn' => (string) ($geoData['asn'] ?? ''),
                'isp' => (string) ($geoData['isp'] ?? ''),
            ];
            logCloakEvent(
                strtoupper((string) $cloakVerdict),
                $campaignId ?? '?',
                $streamId ?? '?',
                $cloakVisitorCtx,
                $cloakReasons,
                $cloakDecision['sensitivity']
            );
        }
    }

    if ($cloakShowSafe) {
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
            $stmtSafe = $pdo->prepare("SELECT type, url, action_payload, action_type, slug FROM landings WHERE id = ? LIMIT 1");
            $stmtSafe->execute([$safeLandingId]);
            $safeLanding = $stmtSafe->fetch();
            $stmtSafe->closeCursor();
            if ($safeLanding) {
                $safeType = (string) ($safeLanding['type'] ?? '');
                if (in_array($safeType, ['redirect', 'preload'], true) && !empty($safeLanding['url'])) {
                    $offerUrl = (string) $safeLanding['url'];
                } elseif ($safeType === 'local' && !empty($safeLanding['slug'])) {
                    $safeScheme = orbitraIsHttps() ? 'https' : 'http';
                    $safeHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
                    $safePath = '/lander/' . rawurlencode((string) $safeLanding['slug']) . '/';
                    $offerUrl = $safeHost !== '' ? $safeScheme . '://' . $safeHost . $safePath : $safePath;
                } elseif ($safeType === 'action'
                    && in_array((string) ($safeLanding['action_type'] ?? ''), ['show_html', 'show_text'], true)) {
                    $safeResponseBody = (string) ($safeLanding['action_payload'] ?? '');
                    $safeResponseContentType = ($safeLanding['action_type'] ?? '') === 'show_text'
                        ? 'text/plain; charset=utf-8'
                        : 'text/html; charset=utf-8';
                }
            }
            if (!$safeLanding || ($offerUrl === '' && $safeResponseBody === null)) {
                $safeResponseBody = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Welcome</title></head><body><h1>Page</h1><p>Content is loading.</p></body></html>';
            }
        } elseif ($safeMode === 'offer' && $safeOfferId > 0) {
            // A local offer as the white page. click.php cannot serve the
            // archive itself — the tracker's /offers/<id>/ route does — so send
            // the bot there, absolute like the /lander/ redirect above.
            $stmtSafeOffer = $pdo->prepare("SELECT is_local FROM offers WHERE id = ? LIMIT 1");
            $stmtSafeOffer->execute([$safeOfferId]);
            $safeOfferRow = $stmtSafeOffer->fetch();
            $stmtSafeOffer->closeCursor();
            if ($safeOfferRow && (int) ($safeOfferRow['is_local'] ?? 0) === 1) {
                $safeScheme = orbitraIsHttps() ? 'https' : 'http';
                $safeHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
                $safePath = '/offers/' . $safeOfferId . '/';
                $offerUrl = $safeHost !== '' ? $safeScheme . '://' . $safeHost . $safePath : $safePath;
            } else {
                $safeResponseBody = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Welcome</title></head><body><h1>Page</h1><p>Content is loading.</p></body></html>';
            }
        } elseif ($safeMode === 'url' && !empty($customSchema['safe_url'])) {
            $offerUrl = (string) $customSchema['safe_url'];
        } else {
            $safeResponseBody = !empty($customSchema['safe_html'])
                ? (string) $customSchema['safe_html']
                : '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Welcome</title></head><body><h1>Page</h1><p>Content is loading.</p></body></html>';
        }
    } else {
        // The stream's destination, resolved as the router resolves a redirect
        // stream (index.php): a Direct URL, else an offer entry that carries a
        // URL of its own, else the weighted offer of the schema, else the
        // stream's legacy offer field. click.php sends visitors to offers
        // only — a landing is never served from here.
        if (!empty($customSchema['direct_url']) || ($customSchema['redirect_mode'] ?? '') === 'direct_url') {
            $offerUrl = trim((string) ($customSchema['direct_url'] ?? ''));
        }
        if ($offerUrl === '' && $actionToPerform === null) {
            $pickedOffer = clickSelectWeightedItem($customSchema['offers'] ?? []);
            if (!empty($pickedOffer['url'])) {
                $offerUrl = (string) $pickedOffer['url'];
            } else {
                if (!empty($pickedOffer['id'])) {
                    $offerId = $pickedOffer['id'];
                } elseif ($stream['offer_id']) {
                    $offerId = $stream['offer_id'];
                }
                if ($offerId) {
                    $offer = orbitraGetOfferDestination($pdo, (int) $offerId);
                    if ($offer) {
                        $offerUrl = $offer['url'];
                    }
                }
            }
        }
    }
}

// Debounce: prevent duplicate clicks inside the click_debounce_seconds window
// (2 s by default). Keyed by ip + user agent (IP alone collapsed different
// people behind one carrier NAT), and a hit reuses the stored click id so the
// redirect/JSON carries a subid that exists in the clicks table — see
// orbitraFindDebounceDuplicate(). Window 0 disables the guard: every request
// writes its own row.
$debounceWindow = orbitraClickDebounceSeconds($pdo);
$debounceId = ($isTestClick || $debounceWindow === 0)
    ? null
    : orbitraFindDebounceDuplicate($pdo, (int) $campaignId, $ip, $userAgent, $debounceWindow);
$isDebounced = $debounceId !== null;
if ($isDebounced) {
    $clickId = $debounceId;
}

// Stream-level "Collect clicks" (see index.php): a no-collect stream serves
// its destination without a clicks row.
$streamCollectsClicks = !$stream || (int) ($stream['collect_clicks'] ?? 1) === 1;

// Log click (a prefetch hit is served but never logged; a test click never logs)
if ($statsEnabled && !$isDebounced && !$skipClickOnPrefetch && !$skipClickLogging && $streamCollectsClicks && !$isTestClick) {
    // Build click row using shared module (note: landing_id is null for click.php)
    $clickCtx = [
        'click_id' => $clickId,
        'campaign_id' => $campaignId,
        'offer_id' => $offerId,
        'stream_id' => $streamId,
        'source_id' => $sourceId,
        'ip' => $ip,
        'user_agent' => $userAgent,
        'referer' => $referer,
        'country' => $country,
        'country_code' => $countryCode,
        'region' => $region,
        'city' => $city,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'zipcode' => $zipcode,
        'timezone' => $timezone,
        'device_type' => $deviceType,
        'os' => $os,
        'browser' => $browser,
        'language' => $language,
        'accept_language_raw' => $acceptLanguageRaw,
        'parameters_json' => $parametersJson,
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        // Connection data for EVERY click, not only cloak streams: the geo
        // lookup already resolved it (ASN / IP2Proxy databases), it just was
        // never stored, so ISP / ASN / proxy read "-" on every non-cloak click.
        // A cloak stream's own context below still overrides these.
        'isp' => !in_array(trim((string) ($geoData['isp'] ?? '')), ['', '-'], true) ? (string) $geoData['isp'] : null,
        'asn' => !in_array(trim((string) ($geoData['asn'] ?? '')), ['', '-'], true) ? (string) $geoData['asn'] : null,
        'proxy_type' => !in_array(trim((string) ($geoData['proxy_type'] ?? '')), ['', '-'], true) ? (string) $geoData['proxy_type'] : null,
    ];

    // Add cloak observability fields for cloak streams (W1)
    if (isset($cloakDecision) && ($stream['schema_type'] ?? '') === 'cloak') {
        $cloakClickCtx = orbitraCloakClickContext($cloakDecision, $geoData ?? []);
        $clickCtx = array_merge($clickCtx, $cloakClickCtx);
    } elseif (!empty($streamId) && isset($GLOBALS['orbitraBotFilterHits'][(int) $streamId])) {
        // The stream was chosen because its "Bot" filter judged the visit a
        // bot: log the signals, the same way the router does.
        $clickCtx = array_merge($clickCtx, orbitraBotFilterClickContext($GLOBALS['orbitraBotFilterHits'][(int) $streamId]));
    }

    // Honesty flags (bots/proxies/uniqueness) are computed BEFORE the insert
    // and written by the same INSERT — one commit, one set of pages in the WAL
    // (load ТЗ part 2: the separate post-insert UPDATE doubled the disk write).
    require_once __DIR__ . '/core/ClickFlags.php';
    $clickCtx = orbitraMergeClickFlags(
        $clickCtx,
        orbitraComputeClickFlags($pdo, $ip, $userAgent, $campaign ?? [], $streamId ?? 0, is_array($geoData ?? null) ? $geoData : [])
    );

    $clickRow = orbitraBuildClickRow($clickCtx);

    // Persist click using shared module
    if (orbitraPersistClick($pdo, $clickRow)) {
        orbitraMetaRememberBrowserClick($pdo, $clickId, $clickParams);
    }

    // The browser-check verdict row still needs the click to exist.
    orbitraBcPersistPending($pdo, $clickId);

    // A client-side landing timer (kclient.js / tracking.js append _lt to the
    // offer click): synthesize the landing→offer pair so Time-since-LP-click
    // also covers external landings whose visits never went through the
    // tracker's router. The click.php row has no landing_id, so this counts
    // as an offer transition, not an LP funnel click.
    if (isset($_GET['_lt']) && preg_match('/^\d{1,6}$/', (string) $_GET['_lt'])) {
        $lpElapsed = min((int) $_GET['_lt'], 604800);
        if ($lpElapsed > 0) {
            try {
                $pdo->prepare("UPDATE clicks SET offer_at = datetime('now'), landing_at = COALESCE(landing_at, ?) WHERE id = ?")
                    ->execute([gmdate('Y-m-d H:i:s', time() - $lpElapsed), $clickId]);
            } catch (\Throwable $e) {
                // Timing is a nice-to-have.
            }
        }
    }
} elseif ($statsEnabled && !$isDebounced && !$skipClickOnPrefetch && !$isTestClick && ($skipClickLogging || !$streamCollectsClicks)) {
    // Click was suppressed - record it for visibility (W3.3)
    $verdict = 'unknown';
    $reasons = '';

    if (isset($cloakDecision) && ($stream['schema_type'] ?? '') === 'cloak') {
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

// A stream action ("404", text, HTML, "do nothing", "to another campaign"),
// now that the click is logged: the router's own performer. The stream is
// never resolved to an offer above, so this comes before any redirect.
if ($actionToPerform !== null) {
    $streamActionType = $actionToPerform;
    $streamActionPayload = '';
    if (strpos($actionToPerform, ':') !== false) {
        [$streamActionType, $streamActionPayload] = explode(':', $actionToPerform, 2);
    }
    if (trim($streamActionType) === 'show_html' && function_exists('orbitraInjectMetaMatching')) {
        $streamActionPayload = orbitraInjectMetaMatching($streamActionPayload, $pdo, (string) $clickId);
    }
    performTrackerAction($streamActionType, $streamActionPayload);
}

if ($safeResponseBody !== null) {
    header('Content-Type: ' . $safeResponseContentType);
    echo $safeResponseBody;
    exit;
}

// Determine redirect behavior
$shouldRedirect = ($_GET['redirect'] ?? '1') !== '0';
$explicitUrl = $cloakShowSafe ? '' : ($_GET['url'] ?? '');

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
        // A registered offer uses the same click context as the main tracker.
        // Explicit URL overrides and cloak safe destinations retain their
        // existing substitution and domain-validation behavior.
        if (!$explicitUrl && !$cloakShowSafe && !empty($offerId)) {
            $finalUrl = orbitraResolveOfferUrlMacros(
                $finalUrl, $clickId, $offerId, $clickParams,
                ['ip' => $ip, 'country' => $countryCode]
            );
        } else {
            // Tracker macros for an explicit URL, a Direct URL stream or a safe
            // destination — the ones the router substitutes before the click's
            // own parameters.
            $finalUrl = str_replace(['{clickid}', '{subid}', '{ip}', '{country}'], [$clickId, $clickId, urlencode($ip), urlencode((string) $countryCode)], $finalUrl);
            foreach ($clickParams as $key => $val) {
                $finalUrl = str_replace('{' . $key . '}', urlencode((string)$val), $finalUrl);
            }
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
        echo json_encode(['status' => 'ok', 'click_id' => $clickId, 'matching' => orbitraMetaMatchingConfig($pdo, $clickId)]);
    }
}
else {
    // redirect=0 — just log the click and return JSON
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['status' => 'ok', 'click_id' => $clickId, 'matching' => orbitraMetaMatchingConfig($pdo, $clickId)]);
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
    // Only the stable error code goes to the client. The exception message and
    // location stay in system_logs above — echoing them back would leak file
    // names, SQL text and line numbers to whoever can hit the endpoint.
    echo json_encode(['error' => 'click_log_failed']);
}
