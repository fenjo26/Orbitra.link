<?php
// core/CloakDetector.php
// Cloaking detection for Orbitra. Decides whether a visitor should see the "safe"
// (white) page or the "money" page, based on signals that indicate a bot, a manual
// moderator, or automated review tooling.
//
// Design principles:
//  * Free signals only — no paid API required. Uses GeoLite2-ASN (already bundled),
//    the existing bot_ips / bot_signatures blocklists, and UA heuristics.
//  * Conservative by default: when in doubt, show the money page (false negatives cost
//    a conversion; false positives cost a campaign). The JS fingerprint check is an
//    opt-in second step, not a hard gate.
//  * All layers are individually toggleable per stream via schema_custom_json config.

require_once __DIR__ . '/Device.php';

class CloakDetector
{
    /**
     * Cached ASN blocklist (datacenter/hosting + VPN/proxy), keyed by category.
     */
    private static $asnSets = null;

    private static function configBool(array $config, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }
        $parsed = filter_var($config[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }

    /**
     * Whether a visitor sent to a cloak stream's Safe Page should bypass the
     * clicks table. The editor exposes this as an opt-out checkbox, so missing
     * values from older streams use the privacy/clean-report default (enabled),
     * while an explicitly stored false restores normal click logging.
     */
    public static function shouldSkipSafePageClick(array $config, bool $showSafe): bool
    {
        return $showSafe && self::configBool($config, 'dont_record_safe_clicks', true);
    }

    /**
     * Keitaro-style "Bot: Yes" stream filter.
     *
     * A bot intercepting stream is expected to catch every available suspicious
     * signal before regular streams are evaluated. It therefore enables every
     * detector layer and uses the aggressive threshold; users who need tunable
     * sensitivity can use the dedicated Cloak schema instead.
     */
    public static function detectBotFilter(array $visitor): array
    {
        return self::detect($visitor, [
            'detect_datacenter' => true,
            'detect_vpn' => true,
            'detect_bots' => true,
            'detect_ua' => true,
            'sensitivity' => 'high',
        ]);
    }

    /**
     * Check the shared bot blocklists without depending on index.php helpers.
     *
     * index.php historically supplied isBot(), which meant the same detector did
     * nothing when called from the traffic simulator or another entry point. Keep
     * that helper as the first choice for backwards compatibility, then query the
     * tables directly when a PDO connection is available.
     */
    private static function matchesBotBlocklist(array $visitor): bool
    {
        $ip = (string) ($visitor['ip'] ?? '');
        $ua = (string) ($visitor['user_agent'] ?? '');

        if (function_exists('isBot')) {
            global $pdo;
            return isset($pdo) && isBot($pdo, $ip, $ua);
        }

        $pdo = $visitor['pdo'] ?? ($GLOBALS['pdo'] ?? null);
        if (!($pdo instanceof PDO)) {
            return false;
        }

        try {
            $stmt = $pdo->prepare('SELECT id FROM bot_ips WHERE ip_or_cidr = ? LIMIT 1');
            $stmt->execute([$ip]);
            if ($stmt->fetchColumn()) {
                return true;
            }

            if ($ua !== '') {
                $stmt = $pdo->prepare("SELECT id FROM bot_signatures WHERE trim(signature) <> '' AND ? LIKE '%' || signature || '%' LIMIT 1");
                $stmt->execute([$ua]);
                return (bool) $stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            // A missing/old table must not route legitimate traffic to the safe page.
        }

        return false;
    }

    /**
     * Load the ASN blocklist once per request.
     */
    private static function loadAsnSets(): array
    {
        if (self::$asnSets !== null) {
            return self::$asnSets;
        }
        $path = __DIR__ . '/data/asn_blocklist.json';
        self::$asnSets = ['datacenter_hosting' => [], 'vpn_proxy' => []];
        if (is_readable($path)) {
            $raw = file_get_contents($path);
            $decoded = json_decode($raw ?: '', true);
            if (is_array($decoded)) {
                foreach (['datacenter_hosting', 'vpn_proxy'] as $cat) {
                    if (isset($decoded[$cat]) && is_array($decoded[$cat])) {
                        self::$asnSets[$cat] = array_fill_keys(array_map('intval', $decoded[$cat]), true);
                    }
                }
            }
        }
        return self::$asnSets;
    }

    /**
     * Extract the integer AS number from a string like "AS13335".
     */
    private static function asnToInt(string $asn): ?int
    {
        $asn = trim($asn);
        if ($asn === '') {
            return null;
        }
        $asn = ltrim($asn, 'ASas ');
        $n = (int) $asn;
        return $n > 0 ? $n : null;
    }

    /**
     * Quick targeting filters from the cloak card (schema_custom_json):
     * country allow/block, device allow/block and the Bot ISP blocklist.
     *
     * Unlike the detection layers these are hard routing rules, not heuristics —
     * any miss sends the visitor to the safe page regardless of sensitivity.
     *
     * @param array  $targeting schema_custom fields: countries ('US, DE' string or
     *                          array; empty disables the filter), geo_mode
     *                          ('allow'|'deny'), devices (string/array, empty
     *                          disables), device_mode, block_bot_isps (bool,
     *                          default true), custom_bot_isps (comma-separated
     *                          local override of the global list), geo_unknown_action
     *                          ('safe'|'money', default 'safe')
     * @param string $countryCode    visitor country, e.g. 'US' (or 'Unknown')
     * @param string $deviceType     Mobile/Tablet/Desktop or a known alias
     * @param string $ispHaystack    visitor "isp asn" string, any case
     * @param string $globalBotIspList comma-separated keywords from settings.bot_isp_list
     * @param bool   $geoReady      true when a geo database is installed and readable
     * @return array reason codes: geo_country / geo_unknown / device_type / bot_isp (may be empty)
     */
    public static function targetingReasons(array $targeting, string $countryCode, string $deviceType, string $ispHaystack, string $globalBotIspList, bool $geoReady = true): array
    {
        $reasons = [];
        $normalize = static function ($raw): array {
            $items = is_array($raw) ? $raw : preg_split('/[\s,]+/', (string) $raw);
            $items = array_map(static fn ($item) => trim((string) $item), $items ?: []);
            return array_values(array_filter($items, static fn ($item) => $item !== ''));
        };

        // 1. Country (GEO): allow = must be in the list, deny = must not be.
        // W4: Distinguish "not in list" from "cannot tell" - emit geo_unknown when
        // country is Unknown and no geo database is installed.
        $countries = array_map('strtoupper', $normalize($targeting['countries'] ?? ''));
        if (!empty($countries)) {
            $countryUpper = strtoupper(trim($countryCode));
            $inList = in_array($countryUpper, $countries, true);
            $deny = ($targeting['geo_mode'] ?? 'allow') === 'deny';

            // W4: Special handling for Unknown country when geo is not ready
            $isUnknown = $countryUpper === '' || $countryUpper === 'UNKNOWN';
            $geoUnknownAction = $targeting['geo_unknown_action'] ?? 'safe';

            if ($isUnknown && !$geoReady && !$deny) {
                // Country allow-list + no geo DB + Unknown country
                // Emit geo_unknown reason (instead of geo_country)
                // Route based on geo_unknown_action setting
                if ($geoUnknownAction !== 'money') {
                    $reasons[] = 'geo_unknown';
                }
                // If geo_unknown_action === 'money', no reason is emitted and
                // the visitor passes through to the money page
            } elseif ($deny ? $inList : !$inList) {
                $reasons[] = 'geo_country';
            }
        }

        // 2. Device types: normalize imported/granular aliases before applying
        // allow/deny so e.g. smartphone and phablet satisfy a mobile filter.
        $rawDevices = $targeting['devices'] ?? '';
        $devices = is_array($rawDevices) ? $rawDevices : explode(',', (string) $rawDevices);
        $devices = array_map(static fn ($device) => strtolower(trim((string) $device)), $devices);
        $devices = array_values(array_filter($devices, static fn ($device) => $device !== ''));
        if (!empty($devices)) {
            $inList = orbitraDeviceGroupMatches($deviceType, $devices);
            $deny = ($targeting['device_mode'] ?? 'allow') === 'deny';
            if ($deny ? $inList : !$inList) {
                $reasons[] = 'device_type';
            }
        }

        // 3. Bot ISP blocklist: keywords from the stream's local list, or the
        //    global settings.bot_isp_list when the local one is empty, matched
        //    against the visitor's ISP + ASN string. Word boundaries matter:
        //    'meta' must not hit 'Metronet', 'aws' must not hit 'Lawson' — a
        //    residential ISP whose name merely contains a cloud vendor's would
        //    otherwise land its real users on the safe page.
        if (self::configBool($targeting, 'block_bot_isps', true)) {
            $rawList = trim((string) ($targeting['custom_bot_isps'] ?? ''));
            if ($rawList === '') {
                $rawList = trim($globalBotIspList);
            }
            $keywords = $normalize($rawList);
            $haystack = strtolower(trim($ispHaystack));
            if (!empty($keywords) && $haystack !== '') {
                foreach ($keywords as $keyword) {
                    if (preg_match('/\b' . preg_quote(strtolower($keyword), '/') . '\b/', $haystack)) {
                        $reasons[] = 'bot_isp';
                        break;
                    }
                }
            }
        }

        return $reasons;
    }

    /**
     * Public bot-UA verdict for click logging (report metric "Bots"): the same
     * signature list the cloaker uses, without any of the heavier layers.
     */
    public static function isBotUserAgent(string $ua): bool
    {
        return self::classifyUa($ua) !== null;
    }

    /**
     * Classify a User-Agent.
     *
     * Returns a reason code or null. 'no_user_agent' and 'crawler_or_tool_ua' are
     * treated as HARD signals by detect(): a request that self-identifies as curl or
     * Googlebot, or sends no UA at all, is not a browser under any reasonable doubt.
     */
    private static function classifyUa(string $ua): ?string
    {
        if ($ua === '') {
            // No User-Agent at all is highly unusual for a real browser.
            return 'no_user_agent';
        }
        $uaLower = strtolower($ua);
        $toolSignatures = [
            'curl/', 'wget/', 'python-requests', 'python-urllib', 'libwww',
            'httpclient', 'okhttp', 'java/', 'go-http-client', 'node-fetch',
            'axios/', 'aiohttp', 'scrapy', 'mechanize', 'phantomjs', 'headless',
            'selenium', 'puppeteer', 'webdriver', 'googlebot', 'bingbot', 'yandexbot',
            'baiduspider', 'duckduckbot', 'slurp', 'semrushbot', 'ahrefsbot',
            'mj12bot', 'dotbot', 'petalbot', 'bytespider', 'applebot', 'twitterbot',
            'facebookexternalhit', 'telegrambot', 'discord-bot', 'whatsapp',
            'skypeuripreviewer', 'linkedinbot', 'embedly', 'vkshare', 'sitechecker',
            'lighthouse', 'pagespeed', 'chrome-lighthouse', 'w3c_validator', 'validator',
            // Field-supplied bot list (2026-08): scrapers, link previews and
            // moderation crawlers that keep appearing in cloaking tickets.
            'facebot', 'facebookcatalog', 'meta-externalagent', 'meta-externalfetcher',
            'python', 'zgrab', 'checkmarknetwork', 'nadesiko', 'jetty', 'jersey',
            'apache-httpclient', 'mediapartners-google', 'surf', 'safednsbot',
            'bomborabot', 'dianomi', 'weborama-fetcher', 'shortlinktranslate',
            'bitlybot', 'proximic', 'ruby', 'adsbot-google', 'google-inspectiontool',
            'googleother', 'bingpreview', 'kakaotalk-scrap', 'kakaostory-og-reader',
            'line-poker', 'remindpreview', 'blueno', 'kraphio', 'belly-scrap',
            'bandscraper', 'worksogcrawler', 'goscraper', 'pagebot',
            'wildlink_preview_bot', 'mijnverlanglijstje', 'mijnverlanglijst',
            'deeplink.me', 'firephp', 'httpx', 'recon',
        ];
        foreach ($toolSignatures as $sig) {
            if (strpos($uaLower, $sig) !== false) {
                return 'crawler_or_tool_ua';
            }
        }
        return null;
    }

    /**
     * Evaluate a visitor against all enabled detection layers.
     *
     * @param array $visitor  Visitor context: ip, user_agent, asn ('AS1234'), isp,
     *                         accept_language and optional IP2Proxy fields.
     * @param array $config   Stream cloak config from schema_custom_json:
     *                          detect_datacenter (bool, default true)
     *                          detect_vpn        (bool, default true)
     *                          detect_bots       (bool, default true)
     *                          detect_ua         (bool, default true)
     *                          sensitivity       'low'|'medium'|'high' (default 'medium')
     * @return array {is_suspicious: bool, reasons: string[]}
     */
    public static function detect(array $visitor, array $config = []): array
    {
        $reasons = [];

        $detectDatacenter = self::configBool($config, 'detect_datacenter', true);
        $detectVpn        = self::configBool($config, 'detect_vpn', true);
        $detectBots       = self::configBool($config, 'detect_bots', true);
        $detectUa         = self::configBool($config, 'detect_ua', true);
        $sensitivity      = $config['sensitivity'] ?? 'medium';

        // --- Layer 1: explicit IP2Proxy result ---
        // PX12 knows about VPN, Tor, hosting/datacenter, residential proxies,
        // privacy networks, crawlers and current threat ranges directly. It is
        // stronger evidence than trying to infer VPN usage from an ASN name.
        $proxyType = strtoupper(trim((string) ($visitor['proxy_type'] ?? '')));
        $isProxy = (int) ($visitor['is_proxy'] ?? 0);
        $proxyThreat = strtoupper(trim((string) ($visitor['proxy_threat'] ?? '')));
        $proxyFraudScore = $visitor['proxy_fraud_score'] ?? null;
        $unsupported = [
            '', '-', 'UNKNOWN', 'THIS PARAMETER IS UNAVAILABLE IN SELECTED .BIN DATA FILE. PLEASE UPGRADE DATA FILE.',
        ];

        if (!in_array($proxyType, $unsupported, true)) {
            if ($proxyType === 'DCH' && $detectDatacenter) {
                $reasons[] = 'ip2proxy_datacenter';
            } elseif (in_array($proxyType, ['SES', 'AIC'], true) && $detectBots) {
                $reasons[] = 'ip2proxy_bot';
            } elseif ($detectVpn && in_array($proxyType, ['VPN', 'TOR', 'PUB', 'WEB', 'RES', 'CPN', 'EPN'], true)) {
                $reasons[] = 'ip2proxy_vpn_proxy';
            }
        } elseif ($detectVpn && $isProxy === 1) {
            $reasons[] = 'ip2proxy_vpn_proxy';
        }

        if ($detectBots && !in_array($proxyThreat, $unsupported, true)) {
            $reasons[] = 'ip2proxy_threat';
        }
        if (($detectVpn || $detectBots) && is_numeric($proxyFraudScore) && (int) $proxyFraudScore >= 80) {
            $reasons[] = 'ip2proxy_high_fraud';
        }

        // --- Layer 2: ASN datacenter / hosting ---
        if ($detectDatacenter || $detectVpn) {
            $asnInt = self::asnToInt((string) ($visitor['asn'] ?? ''));
            if ($asnInt !== null) {
                $sets = self::loadAsnSets();
                if ($detectDatacenter && isset($sets['datacenter_hosting'][$asnInt])) {
                    $reasons[] = 'datacenter_asn';
                }
                if ($detectVpn && isset($sets['vpn_proxy'][$asnInt])) {
                    $reasons[] = 'vpn_proxy_asn';
                }
            }

            // Layer 2b: literal IP ranges of clouds and crawlers (AWS, GCP,
            // Azure, Meta, Telegram, OpenAI, … — lord-alfred/ipranges, refreshed
            // daily by ipranges_cron.php). Stronger than ASN/ISP heuristics: the
            // address is IN the provider's own published space, not just "the
            // ISP name sounds like hosting". Inactive until the lists exist.
            if ($detectDatacenter && !empty($visitor['ip'])) {
                require_once __DIR__ . '/IpRanges.php';
                // Existing installs may not have the cron registered yet — the
                // first cloak visit with stale/missing lists schedules a
                // background download (after the response is sent, zero latency).
                IpRanges::ensureFreshBackground();
                if (IpRanges::available() && IpRanges::match((string) $visitor['ip'])) {
                    $reasons[] = 'iprange_datacenter';
                }
            }

            // Hosting keywords in the ISP/organization string reinforce the ASN signal.
            $isp = strtolower((string) ($visitor['isp'] ?? ''));
            if ($isp !== '') {
                // IMPORTANT: these are matched via strpos (substring), so avoid
                // generic words like 'cloud', 'server', 'amazon' that appear in
                // legitimate residential ISP names (CloudMTS, InterServer, etc.).
                // Use precise provider names only.
                $hostingKeywords = ['hosting', 'datacenter', 'data center',
                    'ovh', 'hetzner', 'digitalocean', 'amazonaws', 'amazon web services',
                    'aws', 'google cloud', 'microsoft azure', 'linode', 'vultr',
                    'contabo', 'leaseweb', 'choopa', 'm247', 'datacamp', 'scaleway',
                    'selectel', 'kamatera', 'upcloud', 'oracle cloud'];
                foreach ($hostingKeywords as $kw) {
                    if (strpos($isp, $kw) !== false) {
                        if ($detectDatacenter && !in_array('datacenter_asn', $reasons, true)) {
                            $reasons[] = 'hosting_isp';
                        }
                        break;
                    }
                }
            }
        }

        // --- Layer 3: existing bot blocklists (bot_ips / bot_signatures) ---
        if ($detectBots && self::matchesBotBlocklist($visitor)) {
            $reasons[] = 'bot_blocklist';
        }

        // --- Layer 4: User-Agent heuristics ---
        if ($detectUa) {
            $uaReason = self::classifyUa((string) ($visitor['user_agent'] ?? ''));
            if ($uaReason !== null) {
                $reasons[] = $uaReason;
            }
            // Missing Accept-Language is uncommon for real browsers but common for bots.
            // Only meaningful when a UA was sent — no-UA is already covered above.
            $acceptLang = (string) ($visitor['accept_language'] ?? '');
            if ($acceptLang === '' && ($visitor['user_agent'] ?? '') !== '') {
                $reasons[] = 'missing_accept_language';
            }
        }

        // --- Threshold by sensitivity ---
        // Signals split into two confidence classes:
        //   hard  — an explicit blocklist/ASN match. Near-certain bot or datacenter.
        //   soft  — heuristics (hosting keyword in ISP string, tool-like UA, missing
        //           Accept-Language). Individually noisy: privacy browsers and some
        //           mobile carriers trip them.
        //
        // low    = hard signals only. Fewest false positives, lets soft bots through.
        // medium = hard signals, or two soft signals corroborating each other.
        // high   = any single signal. Most aggressive, will misclassify some real users.
        //
        // These must stay distinct — an earlier version had medium collapse into high
        // because `$blocklistHit || !empty($reasons)` reduces to `!empty($reasons)`.
        $hardSignals = [
            'bot_blocklist', 'datacenter_asn', 'vpn_proxy_asn',
            'no_user_agent', 'crawler_or_tool_ua',
            'ip2proxy_datacenter', 'ip2proxy_bot', 'ip2proxy_vpn_proxy',
            'ip2proxy_threat', 'ip2proxy_high_fraud',
        ];
        $hardHits = array_intersect($hardSignals, $reasons);
        $softHits = array_diff($reasons, $hardSignals);
        $blocklistHit = !empty($hardHits);

        if ($sensitivity === 'high') {
            $isSuspicious = !empty($reasons);
        } elseif ($sensitivity === 'low') {
            $isSuspicious = $blocklistHit;
        } else { // medium
            $isSuspicious = $blocklistHit || count($softHits) >= 2;
        }

        return [
            'is_suspicious' => $isSuspicious,
            'reasons'       => array_values(array_unique($reasons)),
        ];
    }
}
