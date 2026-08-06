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

class CloakDetector
{
    /**
     * Cached ASN blocklist (datacenter/hosting + VPN/proxy), keyed by category.
     */
    private static $asnSets = null;

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
     * @param array $visitor  Visitor context: ip, user_agent, asn ('AS1234'), isp, accept_language.
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

        $detectDatacenter = $config['detect_datacenter'] ?? true;
        $detectVpn        = $config['detect_vpn'] ?? true;
        $detectBots       = $config['detect_bots'] ?? true;
        $detectUa         = $config['detect_ua'] ?? true;
        $sensitivity      = $config['sensitivity'] ?? 'medium';

        // --- Layer 1: ASN datacenter / hosting ---
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

            // Hosting keywords in the ISP/organization string reinforce the ASN signal.
            $isp = strtolower((string) ($visitor['isp'] ?? ''));
            if ($isp !== '') {
                $hostingKeywords = ['hosting', 'datacenter', 'data center', 'cloud',
                    'server', 'ovh', 'hetzner', 'digitalocean', 'amazon', 'aws',
                    'google cloud', 'microsoft azure', 'linode', 'vultr', 'contabo',
                    'leaseweb', 'choopa', 'm247', 'datacamp', 'scaleway'];
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

        // --- Layer 2: existing bot blocklists (bot_ips / bot_signatures) ---
        if ($detectBots && function_exists('isBot')) {
            // isBot is defined in index.php and operates on the global $pdo.
            global $pdo;
            if (isset($pdo) && isBot($pdo, $visitor['ip'] ?? '', $visitor['user_agent'] ?? '')) {
                $reasons[] = 'bot_blocklist';
            }
        }

        // --- Layer 3: User-Agent heuristics ---
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
