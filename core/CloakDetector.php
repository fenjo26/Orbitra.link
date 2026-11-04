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

    /**
     * Cached iCloud Private Relay egress prefixes, parsed once per process:
     * a flat list of [packed-network, prefix-bits] pairs. An empty list means
     * the data file is absent or unreadable — the relay feature is then off
     * and every IP falls through to the ordinary ASN/ISP reasoning.
     */
    private static $relayPrefixes = null;

    /**
     * Memoized `bot_vpn_asn_signal` verdict for this process (null = not read
     * yet). TRUE is the historical default: a VPN/proxy ASN counts as a signal.
     */
    private static $vpnAsnSignal = null;

    /**
     * Memoized `bot_iprange_hard_signal` verdict for this process (null = not
     * read yet). FALSE is the historical default: a hit in the published
     * cloud/crawler ranges is a soft signal.
     */
    private static $iprangeHardSignal = null;

    /**
     * Memoized `bot_search_verify_money` verdict for this process (null = not
     * read yet). FALSE is the default: claimed search bots are treated purely
     * as bots (the historical behavior).
     */
    private static $searchBotMoney = null;

    /**
     * Per-process cache of reverse/forward DNS verifications, keyed
     * "claim|ip". Bounded: past the cap new entries are computed but not
     * cached, so a spoofed-UA flood cannot grow the cache unbounded.
     */
    private static $searchBotVerifyCache = [];

    private const SEARCH_BOT_VERIFY_CACHE_MAX = 500;

    /**
     * On-disk DNS verification cache (positive AND negative answers, TTL a
     * day): var/cache/cloak_dns_verify.json. PHP-FPM wipes statics between
     * requests, so the in-memory cache above does not survive a click —
     * without this file every claimed-bot hit would pay the two DNS lookups
     * again. Tests point it at a temp path via useDnsCacheFile().
     */
    private const DNS_CACHE_TTL = 86400;
    private const DNS_CACHE_MAX = 5000;
    private static $dnsCacheFile = null;   // null = default path
    private static $dnsCache = null;       // null = not loaded
    private static $dnsCacheDirty = false;

    /**
     * Claimed search crawler → PTR suffixes that may legitimately own its IP.
     * The reverse-DNS suffix a real crawler of each engine resolves to; a
     * "googlebot" UA whose IP reverse-resolves anywhere else is spoofed.
     * Hostname matching is case-insensitive; the trailing FQDN dot is ignored.
     * Google-InspectionTool (Search Console's live test) claims no "googlebot"
     * in its UA but PTRs into the same googlebot.com space.
     */
    private const SEARCH_BOT_REVERSE_SUFFIXES = [
        'googlebot' => ['.googlebot.com', '.google.com'],
        'google-inspectiontool' => ['.googlebot.com', '.google.com'],
        'bingbot'   => ['.search.msn.com'],
        'yandexbot' => ['.yandex.com', '.yandex.ru', '.yandex.net'],
    ];

    /**
     * Claimed crawler → engine (drives which IP list / ASN gate applies).
     */
    private const CLAIM_ENGINE = [
        'googlebot' => 'google',
        'google-inspectiontool' => 'google',
        'bingbot' => 'bing',
        'yandexbot' => 'yandex',
    ];

    /**
     * Engine → ASNs its crawlers may legitimately come from. A DNS lookup is
     * only attempted when the visitor's ASN is on this list: an attacker
     * cannot source traffic from Google's network, so the gate closes the
     * hang-a-FPM-worker-on-slow-PTR DoS (gethostbyaddr has no usable
     * timeout) while list-based verification carries the common case.
     */
    private const ENGINE_ASNS = [
        'google' => [15169, 396982],
        'bing'   => [8075],
        'yandex' => [13238],
    ];

    /**
     * Maximum length of the evidence part of a `code:evidence` reason string.
     * A full desktop Chrome UA is ~120 chars; without a cap a single reason
     * could dominate the clicks.cloak_reasons column.
     */
    private const EVIDENCE_MAX = 64;

    private static function configBool(array $config, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }
        $parsed = filter_var($config[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }

    /**
     * Build a `code:evidence` reason string.
     *
     * Readers split on the FIRST ':' only, so evidence may itself contain ':'
     * (an IPv6 CIDR, for example). Commas are the reason-list separator in
     * clicks.cloak_reasons and are stripped here so a single evidence value can
     * never be mistaken for two reasons. Empty evidence degrades to the bare
     * code, which stays valid for readers of pre-change rows.
     */
    private static function withEvidence(string $code, $evidence): string
    {
        $evidence = trim((string) $evidence);
        if ($evidence === '') {
            return $code;
        }
        // Commas separate reasons; newlines would break the error_log line.
        $evidence = str_replace([',', "\r", "\n"], ' ', $evidence);
        $evidence = trim(preg_replace('/\s+/', ' ', $evidence));
        if ($evidence === '') {
            return $code;
        }
        if (function_exists('mb_substr')) {
            $evidence = mb_substr($evidence, 0, self::EVIDENCE_MAX, 'UTF-8');
        } else {
            $evidence = substr($evidence, 0, self::EVIDENCE_MAX);
        }
        return $code . ':' . $evidence;
    }

    /**
     * Strip the evidence from a reason string, leaving the bare code.
     *
     * Threshold logic and any other code that compares reasons must go through
     * this: 'crawler_or_tool_ua:curl/' has to still count as the hard signal
     * 'crawler_or_tool_ua'. Rows written before evidence was added contain no
     * ':' and pass through unchanged.
     */
    public static function reasonCode(string $reason): string
    {
        $pos = strpos($reason, ':');
        return $pos === false ? $reason : substr($reason, 0, $pos);
    }

    /**
     * The evidence half of a `code:evidence` reason, or '' when absent.
     */
    public static function reasonEvidence(string $reason): string
    {
        $pos = strpos($reason, ':');
        return $pos === false ? '' : substr($reason, $pos + 1);
    }

    /**
     * Whether a visitor sent to a cloak stream's Safe Page should bypass the
     * clicks table. The editor exposes this as an opt-out checkbox, so missing
     * values from older streams use the privacy/clean-report default (enabled),
     * while an explicitly stored false restores normal click logging.
     * W3.1: Default changed to false - new streams log safe traffic by default.
     *
     * Decision path:
     * 1. If log_safe_clicks is set, use its inverse (skip = !log_safe_clicks)
     * 2. Otherwise, fall back to dont_record_safe_clicks for legacy streams
     */
    public static function shouldSkipSafePageClick(array $config, bool $showSafe): bool
    {
        if (!$showSafe) {
            return false;
        }

        // W3.1+: Check new log_safe_clicks key first
        if (isset($config['log_safe_clicks'])) {
            // log_safe_clicks = true means we DO log, so skip = false
            // log_safe_clicks = false means we DON'T log, so skip = true
            return self::configBool($config, 'log_safe_clicks', true) === false;
        }

        // Legacy fallback: dont_record_safe_clicks
        // dont_record_safe_clicks = true means we DON'T log (skip = true)
        // dont_record_safe_clicks = false means we DO log (skip = false)
        return self::configBool($config, 'dont_record_safe_clicks', false);
    }

    /**
     * Keitaro-style "Bot: Yes" stream filter.
     *
     * A bot intercepting stream is expected to catch every available suspicious
     * signal before regular streams are evaluated. It therefore enables every
     * detector layer and uses the aggressive threshold; users who need tunable
     * sensitivity can use the dedicated Cloak schema instead.
     *
     * @param array $options optional overrides merged over the defaults above,
     *                       e.g. ['sensitivity' => 'medium'] or
     *                       ['vpn_asn_signal' => false] — see detect()
     */
    public static function detectBotFilter(array $visitor, array $options = []): array
    {
        return self::detect($visitor, array_merge([
            'detect_datacenter' => true,
            'detect_vpn' => true,
            'detect_bots' => true,
            'detect_ua' => true,
            'sensitivity' => 'high',
        ], $options));
    }

    /**
     * Check the shared bot blocklists.
     *
     * index.php historically supplied an isBot() helper and this method preferred it,
     * which meant the same detector did nothing when called from the traffic
     * simulator or any other entry point that never loaded index.php. That helper is
     * gone; the tables are queried directly, from every entry point, through the one
     * implementation below.
     *
     * @return string|null Null if no match, otherwise the matched rule (IP or UA signature)
     */
    private static function matchesBotBlocklist(array $visitor): ?string
    {
        $ip = (string) ($visitor['ip'] ?? '');
        $ua = (string) ($visitor['user_agent'] ?? '');

        $pdo = $visitor['pdo'] ?? ($GLOBALS['pdo'] ?? null);
        if (!($pdo instanceof PDO)) {
            return null;
        }

        return self::findBlocklistRow($pdo, $ip, $ua);
    }

    /**
     * Is `$ip` inside `$cidr`?
     *
     * Binary prefix comparison over inet_pton output, so one implementation
     * covers IPv4 and IPv6 and neither needs gmp — which is not installed
     * everywhere, and a fatal here would take down click serving itself.
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }
        [$network, $bitsRaw] = explode('/', $cidr, 2);
        if (!preg_match('/^\d+$/', trim($bitsRaw))) {
            return false;
        }
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton(trim($network));
        // Different lengths means one is v4 and the other v6: never a match.
        if ($ipBin === false || $netBin === false) {
            return false;
        }
        return self::ipBinInPrefix($ipBin, $netBin, (int) trim($bitsRaw));
    }

    /**
     * Is a packed IP address inside a packed network prefix?
     *
     * Shared by ipInCidr() and the iCloud Private Relay matcher. Callers must
     * pass same-length packed strings (IPv4 with IPv4, IPv6 with IPv6) and a
     * prefix length valid for that family; anything else is "no match".
     */
    private static function ipBinInPrefix(string $ipBin, string $netBin, int $bits): bool
    {
        if (strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && strncmp($ipBin, $netBin, $wholeBytes) !== 0) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);
        return ($ipBin[$wholeBytes] & $mask) === ($netBin[$wholeBytes] & $mask);
    }

    /**
     * Load the iCloud Private Relay egress ranges once per process.
     *
     * The file (core/data/icloud_private_relay.json) is a periodic snapshot of
     * Apple's published https://mask-api.icloud.com/egress-ip-ranges feed:
     * {"_source": ..., "_fetched": ..., "ranges": ["1.2.3.0/24", ...]}. When it
     * is missing, unreadable or malformed the feature is simply off — an empty
     * prefix list never matches, and click serving never depends on the file.
     * Each range is parsed to a packed [network, bits] pair here so the per-
     * visitor match below is pure binary comparison, no per-request re-parsing.
     */
    private static function loadRelayPrefixes(): array
    {
        if (self::$relayPrefixes !== null) {
            return self::$relayPrefixes;
        }
        self::$relayPrefixes = [];
        $path = __DIR__ . '/data/icloud_private_relay.json';
        if (is_readable($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded) && isset($decoded['ranges']) && is_array($decoded['ranges'])) {
                $prefixes = [];
                foreach ($decoded['ranges'] as $cidr) {
                    $cidr = trim((string) $cidr);
                    if ($cidr === '' || strpos($cidr, '/') === false) {
                        continue;
                    }
                    [$network, $bitsRaw] = explode('/', $cidr, 2);
                    if (!preg_match('/^\d+$/', trim($bitsRaw))) {
                        continue;
                    }
                    $netBin = @inet_pton(trim($network));
                    if ($netBin === false) {
                        continue;
                    }
                    $bits = (int) trim($bitsRaw);
                    if ($bits < 0 || $bits > strlen($netBin) * 8) {
                        continue;
                    }
                    $prefixes[] = [$netBin, $bits];
                }
                self::$relayPrefixes = $prefixes;
            }
        }
        return self::$relayPrefixes;
    }

    /**
     * Whether the visitor's IP is one of Apple iCloud Private Relay's published
     * egress ranges (audit #15). A relay egress address is Apple's privacy
     * infrastructure, not the visitor's network: the GeoLite2 ASN says "AWS" or
     * "Google Cloud" and the literal ipranges lists match too, so without this
     * check ordinary Safari/iCloud+ users were routed to the safe page as
     * "datacenter" traffic. False (the safe answer) whenever the IP is not
     * parseable or the snapshot is absent — the feature can only under-fire.
     */
    public static function isIcloudPrivateRelayIp(string $ip): bool
    {
        $ipBin = @inet_pton(trim($ip));
        if ($ipBin === false) {
            return false;
        }
        foreach (self::loadRelayPrefixes() as [$netBin, $bits]) {
            if (self::ipBinInPrefix($ipBin, $netBin, $bits)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a VPN/proxy ASN match should still count as a verdict signal.
     *
     * Backed by the settings key `bot_vpn_asn_signal` (audit #15): '0' means a
     * VPN/proxy ASN must NOT contribute a bot verdict (datacenter ASNs keep
     * working as before), while an absent key or any other value keeps the
     * historical behavior. Resolution order:
     *  1. an explicit 'vpn_asn_signal' entry in the detect() config wins, so
     *     callers that already load settings (and tests) never touch the DB;
     *  2. otherwise the setting is read through the same PDO access path the
     *     bot blocklist layer already uses ($visitor['pdo'], falling back to
     *     $GLOBALS['pdo']), memoized for the process. The lookup only runs when
     *     a VPN ASN has actually matched, so ordinary traffic pays nothing.
     */
    private static function vpnAsnSignalEnabled(array $visitor, array $config): bool
    {
        if (array_key_exists('vpn_asn_signal', $config)) {
            $parsed = filter_var($config['vpn_asn_signal'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            return $parsed ?? true;
        }
        if (self::$vpnAsnSignal !== null) {
            return self::$vpnAsnSignal;
        }
        self::$vpnAsnSignal = true;
        $pdo = $visitor['pdo'] ?? ($GLOBALS['pdo'] ?? null);
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'bot_vpn_asn_signal' LIMIT 1");
                $stmt->execute();
                $value = $stmt->fetchColumn();
                if (is_string($value) && trim($value) === '0') {
                    self::$vpnAsnSignal = false;
                }
            } catch (\Throwable $e) {
                // Unreadable settings keep the historical default: VPN ASNs count.
            }
        }
        return self::$vpnAsnSignal;
    }

    /**
     * The bot_ips / bot_signatures row that this visitor matches, or null.
     *
     * Returns the row's own text (the IP/CIDR or the UA signature) so the click
     * log can name the rule instead of just saying "blocklisted".
     */
    private static function findBlocklistRow($pdo, string $ip, string $ua): ?string
    {
        if (!($pdo instanceof PDO)) {
            return null;
        }

        try {
            $stmt = $pdo->prepare('SELECT ip_or_cidr FROM bot_ips WHERE ip_or_cidr = ? LIMIT 1');
            $stmt->execute([$ip]);
            $matchedIp = $stmt->fetchColumn();
            if ($matchedIp !== false && $matchedIp !== null && $matchedIp !== '') {
                return (string) $matchedIp;
            }

            // A CIDR row can never be string-equal to a visitor's IP, so the
            // comparison above skipped every range in a column literally named
            // ip_or_cidr: a pasted 1.2.3.0/24 sat in the list looking like a
            // rule and blocked nothing. Ranges are matched in PHP instead, over
            // the rows that actually contain a '/' — normally a handful of
            // hand-added entries, because the public datacenter feed is
            // deliberately not stored here (see the bot_ips_feed endpoint); it
            // is matched by IpRanges, which indexes it properly.
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $cidrStmt = $pdo->query("SELECT ip_or_cidr FROM bot_ips WHERE ip_or_cidr LIKE '%/%' LIMIT 5000");
                if ($cidrStmt !== false) {
                    while (($cidrRow = $cidrStmt->fetchColumn()) !== false) {
                        if (self::ipInCidr($ip, (string) $cidrRow)) {
                            return (string) $cidrRow;
                        }
                    }
                }
            }

            if ($ua !== '') {
                $stmt = $pdo->prepare("SELECT signature FROM bot_signatures WHERE trim(signature) <> '' AND ? LIKE '%' || signature || '%' LIMIT 1");
                $stmt->execute([$ua]);
                $matchedSig = $stmt->fetchColumn();
                if ($matchedSig !== false && $matchedSig !== null && $matchedSig !== '') {
                    return (string) $matchedSig;
                }
            }
        } catch (Throwable $e) {
            // A missing/old table must not route legitimate traffic to the safe page.
        }

        return null;
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
     * Entries that are nothing but a generic corporate suffix. "Reliance Jio
     * Infocomm Limited" contains "Limited", "Facebook, Inc." contains "Inc" —
     * nearly every ISP on earth carries one of these words, so an entry that
     * IS one would route all real traffic to the safe page.
     * Mirrored in frontend/src/utils/botIspList.js — keep the two in sync.
     */
    private const GENERIC_ISP_SUFFIXES = [
        'inc', 'ltd', 'llc', 'limited', 'gmbh', 'corp', 'co', 'sa', 'ag',
        'bv', 'oy', 'pty', 'plc', 'network', 'services',
    ];

    /**
     * Whether a bot-ISP entry is unusable as a standalone entry: shorter than
     * 3 characters, or nothing but a generic corporate suffix ("Inc", "Ltd.",
     * "GmbH") — such entries match nearly every ISP on earth.
     */
    private static function ispEntryIgnorable(string $entry): bool
    {
        $length = function_exists('mb_strlen') ? mb_strlen($entry, 'UTF-8') : strlen($entry);
        if ($length < 3) {
            return true;
        }
        // "Inc." / "LTD." are the same suffix with a trailing period.
        return in_array(rtrim(strtolower($entry), '. '), self::GENERIC_ISP_SUFFIXES, true);
    }

    /**
     * Parse a bot-ISP blocklist into matchable entries.
     *
     * The list is the Keitaro "Провайдеры" format (cpa.rip/services/
     * keitaro-isp-base): ONE PROVIDER PER LINE, matched as a whole phrase.
     * Names carry their own punctuation — commas ("Amazon.com, Inc.",
     * "ZSCALER, INC."), dots, parentheses, slashes, apostrophes — so a line is
     * split on commas only when every segment is itself a plausible standalone
     * entry (the legacy comma-separated list format; spaces never split).
     * When any segment would be dropped by the guards, the commas belong to
     * the name and the whole line stays one entry.
     *
     * The guards: entries shorter than 3 characters and entries that are
     * nothing but a generic corporate suffix are ignored — word-splitting a
     * multi-line list used to leave standalone "Inc"/"Ltd"/"Limited" keywords
     * that matched almost every ISP ("Reliance Jio Infocomm Limited" contains
     * "Limited") and routed real visitors to the safe page. The settings
     * textareas mirror this and warn about the ignored entries.
     *
     * @param string|array $raw array elements are taken as one entry each
     * @return string[] kept entries, deduplicated, original case preserved
     */
    public static function parseBotIspEntries($raw): array
    {
        if (is_array($raw)) {
            $entries = [];
            foreach ($raw as $item) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $entries[] = $item;
                }
            }
        } else {
            $entries = [];
            foreach (preg_split('/\R/', (string) $raw) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $segments = array_values(array_filter(array_map('trim', explode(',', $line)), static fn ($s) => $s !== ''));
                $isLegacyList = $segments !== [];
                foreach ($segments as $segment) {
                    if (self::ispEntryIgnorable($segment)) {
                        $isLegacyList = false;
                        break;
                    }
                }
                if ($isLegacyList) {
                    foreach ($segments as $segment) {
                        $entries[] = $segment;
                    }
                } else {
                    $entries[] = $line;
                }
            }
        }

        $kept = [];
        foreach ($entries as $entry) {
            if ($entry === '' || isset($kept[$entry]) || self::ispEntryIgnorable($entry)) {
                continue;
            }
            $kept[$entry] = true;
        }
        return array_keys($kept);
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
     *                          default true), custom_bot_isps (comma/newline-
     *                          separated entries; multi-word entries match as
     *                          whole phrases; local override of the global list),
     *                          geo_unknown_action ('safe'|'money', default 'safe')
     * @param string $countryCode    visitor country, e.g. 'US' (or 'Unknown')
     * @param string $deviceType     Mobile/Tablet/Desktop or a known alias
     * @param string $ispHaystack    visitor "isp asn" string, any case
     * @param string $globalBotIspList comma/newline-separated entries from settings.bot_isp_list
     * @param bool   $geoReady      true when a geo database is installed and readable
     * @return array reason codes with evidence: geo_country:US / device_type:Mobile / bot_isp:hetzner (may be empty)
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
                $reasons[] = self::withEvidence('geo_country', $countryUpper ?: 'Unknown');
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
                $reasons[] = self::withEvidence('device_type', $deviceType ?: 'Unknown');
            }
        }

        // 3. Bot ISP blocklist: entries from the stream's local list, or the
        //    global settings.bot_isp_list when the local one is empty, matched
        //    against the visitor's ISP + ASN string. Each entry is a whole
        //    phrase (Keitaro provider line: multi-word, punctuation kept) and
        //    the boundaries at the ends matter: 'meta' must not hit
        //    'Metronet', 'aws' must not hit 'Lawson' — a residential ISP whose
        //    name merely contains a cloud vendor's would otherwise land its
        //    real users on the safe page. parseBotIspEntries() also drops bare
        //    corporate suffixes ('Inc', 'Ltd') and sub-3-char entries: they
        //    match nearly every ISP on earth.
        if (self::configBool($targeting, 'block_bot_isps', true)) {
            $rawList = trim((string) ($targeting['custom_bot_isps'] ?? ''));
            if ($rawList === '') {
                $rawList = trim($globalBotIspList);
            }
            $keywords = self::parseBotIspEntries($rawList);
            $haystack = preg_replace('/\s+/', ' ', strtolower(trim($ispHaystack)));
            if (!empty($keywords) && $haystack !== '') {
                foreach ($keywords as $keyword) {
                    // Lookarounds instead of \b: entries may end in
                    // punctuation ("ZSCALER, INC."), where a trailing \b can
                    // never match. For word-character entries
                    // (?<!\w)x(?!\w) is exactly \bx\b. A trailing period is
                    // optional so "INC." also matches a dot-less haystack.
                    $probe = rtrim(strtolower($keyword), '. ');
                    if ($probe !== '' && preg_match('/(?<!\w)' . preg_quote($probe, '/') . '(?!\w)/', $haystack)) {
                        $reasons[] = self::withEvidence('bot_isp', $keyword);
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
     * Returns a reason code with evidence, or null. 'no_user_agent' and
     * 'crawler_or_tool_ua:signature' are treated as HARD signals by detect():
     * a request that self-identifies as curl or Googlebot, or sends no UA at all,
     * is not a browser under any reasonable doubt.
     *
     * @return string|null Null if no match, otherwise 'code' or 'code:evidence'
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
                // Name the signature, not the whole UA: it is the actionable part
                // and it cannot bloat the row.
                return self::withEvidence('crawler_or_tool_ua', $sig);
            }
        }
        return null;
    }

    /**
     * Whether an `iprange_datacenter` hit counts as a HARD signal.
     *
     * Backed by the settings key `bot_iprange_hard_signal` ('1' = hard). Off by
     * default, so existing installs keep the historical verdicts: the hit is a
     * soft signal that needs a second one on medium and is ignored on low.
     *
     * Why the switch exists: the range feed covers providers whose ASNs are
     * NOT in asn_blocklist.json's datacenter_hosting set — Meta (AS32934) and
     * Telegram (AS62041) among them — and installs without the GeoLite2-ASN
     * database never produce datacenter_asn at all. In both cases a reviewer
     * with an ordinary browser UA coming from the provider's own published
     * space carries exactly one soft signal and reaches the money page on
     * medium. Operators running Meta/Telegram traffic can opt into treating
     * that hit as conclusive.
     *
     * Resolution order mirrors vpnAsnSignalEnabled(): an explicit
     * 'iprange_hard_signal' entry in the detect() config wins (tests, callers
     * that already hold settings); otherwise the settings row is read through
     * $visitor['pdo'] / $GLOBALS['pdo'] and memoized. Callers only ask after a
     * range has actually matched, so ordinary traffic pays nothing.
     */
    private static function iprangeHardSignalEnabled(array $visitor, array $config): bool
    {
        if (array_key_exists('iprange_hard_signal', $config)) {
            $parsed = filter_var($config['iprange_hard_signal'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            return $parsed ?? false;
        }
        if (self::$iprangeHardSignal !== null) {
            return self::$iprangeHardSignal;
        }
        self::$iprangeHardSignal = false;
        $pdo = $visitor['pdo'] ?? ($GLOBALS['pdo'] ?? null);
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'bot_iprange_hard_signal' LIMIT 1");
                $stmt->execute();
                $value = $stmt->fetchColumn();
                if (is_string($value) && trim($value) === '1') {
                    self::$iprangeHardSignal = true;
                }
            } catch (\Throwable $e) {
                // Unreadable settings keep the historical default: soft signal.
            }
        }
        return self::$iprangeHardSignal;
    }

    /**
     * Whether the "SEO shield" search-bot verification mode is on.
     *
     * Backed by the settings key `bot_search_verify_money` ('1' = on):
     * claimed search bots (Googlebot/Bingbot/YandexBot) get verified by
     * reverse+forward DNS — a VERIFIED crawler is routed to the money page
     * (it is the page that must rank), a spoofed UA gets the hard signal
     * `spoofed_search_bot`. Resolution order mirrors vpnAsnSignalEnabled():
     * an explicit 'search_bot_money' entry in the detect() config wins;
     * otherwise the settings row is read through $visitor['pdo'] /
     * $GLOBALS['pdo'] and memoized. Default off — without the setting every
     * search-bot UA keeps going to the safe page exactly as before.
     */
    private static function searchBotMoneyEnabled(array $visitor, array $config): bool
    {
        if (array_key_exists('search_bot_money', $config)) {
            $parsed = filter_var($config['search_bot_money'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            return $parsed ?? false;
        }
        if (self::$searchBotMoney !== null) {
            return self::$searchBotMoney;
        }
        self::$searchBotMoney = false;
        $pdo = $visitor['pdo'] ?? ($GLOBALS['pdo'] ?? null);
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'bot_search_verify_money' LIMIT 1");
                $stmt->execute();
                $value = $stmt->fetchColumn();
                if (is_string($value) && trim($value) === '1') {
                    self::$searchBotMoney = true;
                }
            } catch (\Throwable $e) {
                // Unreadable settings keep the historical default: bots stay bots.
            }
        }
        return self::$searchBotMoney;
    }

    /**
     * Which search crawler this UA claims to be, or null.
     *
     * @return string|null one of the SEARCH_BOT_REVERSE_SUFFIXES keys
     */
    public static function claimedSearchBot(string $ua): ?string
    {
        $uaLower = strtolower($ua);
        if ($uaLower === '') {
            return null;
        }
        foreach (array_keys(self::SEARCH_BOT_REVERSE_SUFFIXES) as $claim) {
            if (strpos($uaLower, $claim) !== false) {
                return $claim;
            }
        }
        return null;
    }

    /**
     * Verify a claimed search crawler — the entry point Layer 0 calls.
     *
     * Order of evidence, strongest and cheapest first:
     *  1. the engine's PUBLISHED IP LIST (common-crawlers.json / bingbot.json,
     *     refreshed daily into var/ipranges by the same cron) — Google's own
     *     recommended method for verification at scale, no DNS on the click
     *     path;
     *  2. reverse+forward DNS, gated by the visitor's ASN: only attempted
     *     when the IP already belongs to the engine's network (Yandex
     *     publishes no list, so it lives on this path alone). Results —
     *     including negative ones — are cached on disk for a day: PHP-FPM
     *     wipes statics per request, and gethostbyaddr has no usable timeout.
     *
     * A claim that matches neither is spoofed by definition: the engine's
     * own space says the IP is not a crawler, and no further lookups run.
     *
     * @param array $visitor full visitor context (ip, asn, resolvers for tests)
     * @param string $claim  a claimedSearchBot() key
     * @return array{verified:bool,evidence:string,method:string}
     *         method: 'list' | 'dns' | 'asn' | 'none'
     */
    public static function verifySearchBot(array $visitor, string $claim): array
    {
        $fail = static function (string $method): array {
            return ['verified' => false, 'evidence' => '', 'method' => $method];
        };
        $ip = trim((string) ($visitor['ip'] ?? ''));
        if ($ip === '' || !isset(self::CLAIM_ENGINE[$claim])) {
            return $fail('none');
        }
        $engine = self::CLAIM_ENGINE[$claim];

        // 1) Published IP lists — Google and Bing.
        if ($engine === 'google') {
            require_once __DIR__ . '/IpRanges.php';
            $cidr = IpRanges::matchGoogleCrawler($ip);
            if ($cidr !== null) {
                return ['verified' => true, 'evidence' => $cidr, 'method' => 'list'];
            }
        } elseif ($engine === 'bing') {
            require_once __DIR__ . '/IpRanges.php';
            $cidr = IpRanges::matchBingCrawler($ip);
            if ($cidr !== null) {
                return ['verified' => true, 'evidence' => $cidr, 'method' => 'list'];
            }
        }

        // 2) DNS fallback, only from the engine's own ASN. Without a known
        // ASN there is nothing that ties the IP to the engine, and a DNS
        // lookup per spoofed-UA hit is exactly the DoS the gate prevents.
        $asnInt = self::asnToInt((string) ($visitor['asn'] ?? ''));
        if ($asnInt === null || !in_array($asnInt, self::ENGINE_ASNS[$engine], true)) {
            return $fail('asn');
        }

        $cacheKey = $claim . '|' . $ip;
        if (array_key_exists($cacheKey, self::$searchBotVerifyCache)) {
            $cached = self::$searchBotVerifyCache[$cacheKey];
            return ['verified' => $cached['verified'], 'evidence' => $cached['hostname'], 'method' => 'dns-cache'];
        }
        $disk = self::dnsCacheGet($cacheKey);
        if ($disk !== null) {
            return ['verified' => $disk['verified'], 'evidence' => $disk['hostname'], 'method' => 'dns-cache'];
        }

        $result = self::verifySearchBotHost($ip, $claim, $visitor);
        $entry = ['verified' => $result['verified'], 'hostname' => $result['hostname'], 'ts' => time()];
        self::dnsCachePut($cacheKey, $entry);
        if (count(self::$searchBotVerifyCache) < self::SEARCH_BOT_VERIFY_CACHE_MAX) {
            self::$searchBotVerifyCache[$cacheKey] = $entry;
        }
        return ['verified' => $result['verified'], 'evidence' => $result['hostname'], 'method' => 'dns'];
    }

    /**
     * Point the DNS verification cache at another file (tests) or back to
     * the default (null). Resets the in-memory copy.
     */
    public static function useDnsCacheFile(?string $path): void
    {
        self::$dnsCacheFile = $path;
        self::$dnsCache = null;
        self::$dnsCacheDirty = false;
    }

    private static function dnsCachePath(): string
    {
        return self::$dnsCacheFile ?? (__DIR__ . '/../var/cache/cloak_dns_verify.json');
    }

    /**
     * Cached verdict for "claim|ip", or null when absent or older than a day.
     * The file maps key → {verified, hostname, ts}; entries past the TTL are
     * pruned on load. Unreadable/absent file = empty cache, never an error:
     * verification then just runs uncached (still ASN-gated).
     *
     * @return array{verified:bool,hostname:string}|null
     */
    private static function dnsCacheGet(string $key): ?array
    {
        $cache = self::dnsCacheLoad();
        if (!isset($cache[$key])) {
            return null;
        }
        $entry = $cache[$key];
        if (!is_array($entry) || !isset($entry['ts']) || (time() - (int) $entry['ts']) > self::DNS_CACHE_TTL) {
            unset(self::$dnsCache[$key]);
            self::$dnsCacheDirty = true;
            return null;
        }
        return ['verified' => !empty($entry['verified']), 'hostname' => (string) ($entry['hostname'] ?? '')];
    }

    /**
     * Store a verdict and write the file back (atomic tmp+rename). A failed
     * write only costs the cache, never the click.
     */
    private static function dnsCachePut(string $key, array $entry): void
    {
        $cache = self::dnsCacheLoad();
        $cache[$key] = $entry;
        if (count($cache) > self::DNS_CACHE_MAX) {
            // Drop the oldest entries first; ts is the sort key.
            uasort($cache, static fn ($a, $b) => (int) ($a['ts'] ?? 0) <=> (int) ($b['ts'] ?? 0));
            $cache = array_slice($cache, count($cache) - self::DNS_CACHE_MAX, null, true);
        }
        self::$dnsCache = $cache;
        self::$dnsCacheDirty = true;

        $path = self::dnsCachePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $path . '.tmp' . getmypid();
        $json = json_encode($cache, JSON_UNESCAPED_UNICODE);
        if (is_string($json) && $json !== ''
            && @file_put_contents($tmp, $json) !== false
            && !@rename($tmp, $path)
        ) {
            @unlink($tmp);
        }
    }

    /**
     * Lazy-load the DNS cache file once per process.
     */
    private static function dnsCacheLoad(): array
    {
        if (self::$dnsCache !== null) {
            return self::$dnsCache;
        }
        self::$dnsCache = [];
        $raw = @file_get_contents(self::dnsCachePath());
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                self::$dnsCache = $decoded;
            }
        }
        return self::$dnsCache;
    }

    /**
     * Verify a claimed search crawler by Google's documented procedure:
     * reverse DNS on the IP → hostname must end in the engine's own suffix →
     * forward DNS on that hostname → must resolve back to the SAME IP.
     * User-Agent alone proves nothing — Google explicitly warns it is
     * "often spoofed".
     *
     * Resolvers are injectable for tests: $visitor['dns_reverse'] (ip →
     * hostname|false) and $visitor['dns_forward'] (hostname, family →
     * array of IP strings); production uses gethostbyaddr/dns_get_record.
     * Results are cached per process (bounded, see SEARCH_BOT_VERIFY_CACHE_MAX).
     *
     * @param string $ip    visitor IP (v4 or v6)
     * @param string $claim a claimedSearchBot() key
     * @param array  $visitor full visitor context (resolvers, cache overrides)
     * @return array{verified:bool,hostname:string} hostname is '' on failure
     */
    public static function verifySearchBotHost(string $ip, string $claim, array $visitor = []): array
    {
        $ip = trim($ip);
        $fail = ['verified' => false, 'hostname' => ''];
        if ($ip === '' || !isset(self::SEARCH_BOT_REVERSE_SUFFIXES[$claim])) {
            return $fail;
        }

        $cacheKey = $claim . '|' . $ip;
        if (array_key_exists($cacheKey, self::$searchBotVerifyCache)) {
            return self::$searchBotVerifyCache[$cacheKey];
        }

        $result = self::verifySearchBotHostUncached($ip, $claim, $visitor);

        if (count(self::$searchBotVerifyCache) < self::SEARCH_BOT_VERIFY_CACHE_MAX) {
            self::$searchBotVerifyCache[$cacheKey] = $result;
        }
        return $result;
    }

    /**
     * The uncached half of verifySearchBotHost().
     */
    private static function verifySearchBotHostUncached(string $ip, string $claim, array $visitor): array
    {
        $fail = ['verified' => false, 'hostname' => ''];

        $reverse = $visitor['dns_reverse'] ?? null;
        if (is_callable($reverse)) {
            $hostname = $reverse($ip);
        } else {
            // gethostbyaddr returns the unmodified IP on failure (not false).
            $hostname = @gethostbyaddr($ip);
        }
        if (!is_string($hostname) || $hostname === '' || $hostname === $ip) {
            return $fail;
        }

        $hostname = strtolower(rtrim(trim($hostname), '.'));
        $suffixMatch = false;
        foreach (self::SEARCH_BOT_REVERSE_SUFFIXES[$claim] as $suffix) {
            $apex = substr($suffix, 1); // '.googlebot.com' → 'googlebot.com'
            if ($hostname === $apex || str_ends_with($hostname, $suffix)) {
                $suffixMatch = true;
                break;
            }
        }
        if (!$suffixMatch) {
            return $fail;
        }

        // Forward-confirm: the hostname must point back at the original IP.
        $ipNorm = @inet_pton($ip);
        if ($ipNorm === false) {
            return $fail;
        }
        $forward = $visitor['dns_forward'] ?? null;
        if (is_callable($forward)) {
            $candidates = $forward($hostname, strpos($ip, ':') === false ? 'ipv4' : 'ipv6');
            $candidates = is_array($candidates) ? $candidates : [];
        } elseif (strpos($ip, ':') === false) {
            $resolved = @gethostbyname($hostname); // IP string on failure
            $candidates = filter_var($resolved, FILTER_VALIDATE_IP) ? [$resolved] : [];
        } elseif (function_exists('dns_get_record')) {
            $records = @dns_get_record($hostname, DNS_AAAA);
            $candidates = is_array($records)
                ? array_filter(array_column($records, 'ipv6'))
                : [];
        } else {
            $candidates = [];
        }

        foreach ($candidates as $candidate) {
            $candidateBin = @inet_pton(trim((string) $candidate));
            if ($candidateBin !== false && $candidateBin === $ipNorm) {
                return ['verified' => true, 'hostname' => $hostname];
            }
        }
        return $fail;
    }

    /**
     * The visitor's country when this request came through Chrome's prefetch
     * proxy, or null. BOTH conditions must hold — this is what keeps the
     * exemption narrow:
     *  - the request announces the proxy: Sec-Purpose carries
     *    "anonymous-client-ip" (a forbidden header name, so page JS cannot
     *    forge it; server-side clients forward it explicitly);
     *  - the IP is in the proxy's OWN geofeed (RFC 8805), not Google's broader
     *    fetcher feeds — those also cover e.g. the Translate proxy, whose
     *    "visitors" are moderators and abusers, exactly who the datacenter
     *    layers must keep flagging.
     * The geofeed row carries the real geo-located country, so callers can
     * use it for geo targeting instead of the proxy's MaxMind answer.
     *
     * @param array $visitor must carry ip and sec_purpose
     * @return string|null country code ('IN', 'DE', …) or null
     */
    public static function prefetchProxyCountry(array $visitor): ?string
    {
        $secPurpose = strtolower((string) ($visitor['sec_purpose'] ?? ''));
        if ($secPurpose === '' || strpos($secPurpose, 'anonymous-client-ip') === false) {
            return null;
        }
        $ip = trim((string) ($visitor['ip'] ?? ''));
        if ($ip === '') {
            return null;
        }
        require_once __DIR__ . '/IpRanges.php';
        return IpRanges::prefetchProxyCountry($ip);
    }

    /**
     * Hostname patterns of search-engine result pages. A referer from one of
     * these is "the visitor clicked our snippet in search" — the SEO-traffic
     * referrer rule's positive class.
     */
    private const SEARCH_REFERER_HOSTS = [
        '/(^|\\.)google\\.[a-z]{2,3}(\\.[a-z]{2,3})?$/',   // google.de, google.co.uk
        '/(^|\\.)bing\\.com$/',
        '/(^|\\.)yandex\\.[a-z]{2,3}(\\.[a-z]{2,3})?$/',    // yandex.ru, yandex.com.tr
        '/(^|\\.)duckduckgo\\.com$/',
        '/(^|\\.)search\\.yahoo\\.[a-z]{2,3}$/',            // search.yahoo.com
        '/(^|\\.)yahoo\\.[a-z]{2,3}$/',                     // se.search.yahoo.com hits the row above
        '/(^|\\.)baidu\\.com$/',
        '/(^|\\.)ecosia\\.org$/',
        '/(^|\\.)startpage\\.com$/',
        '/(^|\\.)search\\.brave\\.com$/',
        '/(^|\\.)qwant\\.com$/',
        '/(^|\\.)seznam\\.cz$/',
        '/(^|\\.)mail\\.ru$/',
        '/(^|\\.)ask\\.com$/',
        '/(^|\\.)aol\\.com$/',
    ];

    /**
     * Whether the referer URL is a search engine's result page. An empty
     * referer is NOT a search referer (it is a direct hit or a stripped
     * referrer policy) — the rule that uses this treats it as a soft signal
     * precisely because bookmarks and returning users land there too.
     */
    public static function isSearchReferer(string $referer): bool
    {
        if (trim($referer) === '') {
            return false;
        }
        $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        $host = preg_replace('/^www\\./', '', $host) ?? $host;
        foreach (self::SEARCH_REFERER_HOSTS as $pattern) {
            if (preg_match($pattern, $host)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Evaluate a visitor against all enabled detection layers.
     *
     * @param array $visitor  Visitor context: ip, user_agent, asn ('AS1234'), isp,
     *                         accept_language, optional IP2Proxy fields, and
     *                         the cloak-relevant headers http_referer /
     *                         sec_purpose (see orbitraCloakVisitorHeaders).
     * @param array $config   Stream cloak config from schema_custom_json:
     *                          detect_datacenter (bool, default true)
     *                          detect_vpn        (bool, default true)
     *                          detect_bots       (bool, default true)
     *                          detect_ua         (bool, default true)
     *                          sensitivity       'low'|'medium'|'high' (default 'medium')
     *                          vpn_asn_signal    bool, explicit override of the
     *                                            settings key bot_vpn_asn_signal
     *                                            (default: read from settings,
     *                                            true when unreadable/absent)
     *                          iprange_hard_signal bool, explicit override of
     *                                            the settings key
     *                                            bot_iprange_hard_signal
     *                                            (default: read from settings,
     *                                            false when unreadable/absent)
     *                          search_bot_money bool, explicit override of the
     *                                            settings key
     *                                            bot_search_verify_money — the
     *                                            SEO-shield mode where VERIFIED
     *                                            search crawlers see the money
     *                                            page (default: off)
     *                          search_referrer bool, per-stream rule: a referer
     *                                            from a search engine is
     *                                            required, else the SOFT signal
     *                                            non_search_referrer
     *                                            (default: off)
     * @return array {is_suspicious: bool, reasons: string[], verified_search_bot?: bool}
     */
    public static function detect(array $visitor, array $config = []): array
    {
        $reasons = [];

        $detectDatacenter = self::configBool($config, 'detect_datacenter', true);
        $detectVpn        = self::configBool($config, 'detect_vpn', true);
        $detectBots       = self::configBool($config, 'detect_bots', true);
        $detectUa         = self::configBool($config, 'detect_ua', true);
        $sensitivity      = $config['sensitivity'] ?? 'medium';

        // --- Layer 0: SEO-shield verification of claimed search bots ---
        // Opt-in (settings key bot_search_verify_money / config
        // search_bot_money): the stream doubles as an SEO shield, where a
        // VERIFIED search crawler must see the money page (that is the page
        // that ranks), while a crawler UA that fails verification is the
        // strongest possible bot evidence — spoofing a search engine is what
        // abusers and scrapers do. Verification is by the engine's published
        // IP list first (Google's own recommended method, no DNS on the click
        // path), reverse+forward DNS only as an ASN-gated fallback.
        if (self::searchBotMoneyEnabled($visitor, $config)) {
            $claim = self::claimedSearchBot((string) ($visitor['user_agent'] ?? ''));
            if ($claim !== null) {
                $verification = self::verifySearchBot($visitor, $claim);
                if ($verification['verified']) {
                    // Verified crawler: not suspicious by ANY later layer —
                    // a real Googlebot arrives from Google IP space, which
                    // the datacenter/ASN layers below would flag, and from
                    // the US, which geo targeting would flag. The early
                    // return carries a flag so orbitraCloakDecision() can
                    // skip its targeting layer too; the reason keeps the
                    // verdict visible in the click log.
                    return [
                        'is_suspicious' => false,
                        'reasons' => [self::withEvidence('verified_search_bot', $verification['evidence'])],
                        'verified_search_bot' => true,
                    ];
                }
                $reasons[] = self::withEvidence('spoofed_search_bot', $claim);
            }
        }

        // Chrome may prefetch a clicked search result through its proxy, so a
        // real visitor can arrive from Google's own IP space. The exemption
        // needs BOTH the Sec-Purpose marker and a geofeed IP — the header
        // gate is what keeps Google's other proxies (Translate among them)
        // flagged as the datacenter traffic they are. Null = not a prefetch
        // proxy request; the country rides along for geo targeting.
        $prefetchCountry = self::prefetchProxyCountry($visitor);

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
                // The prefetch proxy's own space reads as DCH to IP2Proxy by
                // construction — a Chrome preload visitor would land in the
                // safe page on the strongest possible signal. The geofeed +
                // Sec-Purpose pair is the better authority, so it wins here.
                // VPN/TOR/RES etc. keep firing: the proxy ranges are never
                // legitimately those, so a hit there stays suspicious.
                if ($prefetchCountry === null) {
                    $reasons[] = self::withEvidence('ip2proxy_datacenter', $proxyType);
                }
            } elseif (in_array($proxyType, ['SES', 'AIC'], true) && $detectBots) {
                if ($prefetchCountry === null) {
                    $reasons[] = self::withEvidence('ip2proxy_bot', $proxyType);
                }
            } elseif ($detectVpn && in_array($proxyType, ['VPN', 'TOR', 'PUB', 'WEB', 'RES', 'CPN', 'EPN'], true)) {
                $reasons[] = self::withEvidence('ip2proxy_vpn_proxy', $proxyType);
            }
        } elseif ($detectVpn && $isProxy === 1) {
            $reasons[] = 'ip2proxy_vpn_proxy';
        }

        if ($detectBots && !in_array($proxyThreat, $unsupported, true)) {
            $reasons[] = self::withEvidence('ip2proxy_threat', $proxyThreat);
        }
        if (($detectVpn || $detectBots) && is_numeric($proxyFraudScore) && (int) $proxyFraudScore >= 80) {
            $reasons[] = self::withEvidence('ip2proxy_high_fraud', $proxyFraudScore);
        }

        // --- Layer 2: ASN datacenter / hosting ---
        // The iCloud Private Relay guard (audit #15) is ANDed onto the layer
        // switch, so a relay egress IP never reaches ANY of the infrastructure
        // inference below: not the ASN category sets, not the literal cloud
        // ranges (which list Apple's AWS/Google-hosted egress space), not the
        // hosting ISP keywords. A relay address is Apple's privacy
        // infrastructure rather than the visitor's network, so none of it may
        // produce a datacenter/hosting/VPN verdict or any other bot reason.
        // The same guard now covers the Chrome prefetch proxy (geofeed IP +
        // the Sec-Purpose marker): a proxy IP is Google's privacy
        // infrastructure, not the visitor's network — the merged ipranges
        // list and the "google cloud" ISP keyword would both flag it.
        // All other layers (blocklists, UA heuristics) are untouched and keep
        // working on relay/proxy traffic exactly as before. The relay check is
        // lazy per request; the prefetch verdict is computed once above.
        if (($detectDatacenter || $detectVpn)
            && !self::isIcloudPrivateRelayIp((string) ($visitor['ip'] ?? ''))
            && $prefetchCountry === null
        ) {
            $asnRaw = (string) ($visitor['asn'] ?? '');
            $asnInt = self::asnToInt($asnRaw);
            if ($asnInt !== null) {
                $sets = self::loadAsnSets();
                if ($detectDatacenter && isset($sets['datacenter_hosting'][$asnInt])) {
                    $reasons[] = self::withEvidence('datacenter_asn', $asnRaw);
                }
                // `bot_vpn_asn_signal` = '0' turns a VPN/proxy ASN into a silent
                // category: matched, but contributing no reason and therefore no
                // verdict at any sensitivity. Datacenter ASNs are unaffected.
                if ($detectVpn
                    && isset($sets['vpn_proxy'][$asnInt])
                    && self::vpnAsnSignalEnabled($visitor, $config)
                ) {
                    $reasons[] = self::withEvidence('vpn_proxy_asn', $asnRaw);
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
                if (IpRanges::available()) {
                    $matchedRange = IpRanges::match((string) $visitor['ip']);
                    if ($matchedRange !== null) {
                        // The evidence is the matched CIDR itself (e.g. 52.0.0.0/8):
                        // the actionable fact for the operator checking the click log.
                        $reasons[] = self::withEvidence('iprange_datacenter', $matchedRange);
                    }
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
                        // Check if any datacenter_asn reason already exists (by code)
                        $hasDatacenterAsn = false;
                        foreach ($reasons as $r) {
                            if (self::reasonCode($r) === 'datacenter_asn') {
                                $hasDatacenterAsn = true;
                                break;
                            }
                        }
                        if ($detectDatacenter && !$hasDatacenterAsn) {
                            $reasons[] = self::withEvidence('hosting_isp', $kw);
                        }
                        break;
                    }
                }
            }
        }

        // --- Layer 3: existing bot blocklists (bot_ips / bot_signatures) ---
        if ($detectBots) {
            $blocklisted = self::matchesBotBlocklist($visitor);
            if ($blocklisted !== null) {
                $reasons[] = self::withEvidence('bot_blocklist', $blocklisted);
            }
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

        // --- Layer 5: referrer (opt-in, SEO traffic only) ---
        // Streams that buy SEO traffic can require the visitor to arrive
        // FROM a search result page. Deliberately a SOFT signal: a missing
        // referer is also a returning user with a bookmark or a stripped
        // referrer policy, and a hard rule here is one of the divergences
        // comparative cloaking detection looks for. On medium it takes a
        // second soft signal to send the visitor to the safe page; on high
        // it acts alone. Verified search bots never reach this layer.
        if (self::configBool($config, 'search_referrer', false)) {
            $referer = (string) ($visitor['http_referer'] ?? '');
            if (!self::isSearchReferer($referer)) {
                $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
                $reasons[] = self::withEvidence('non_search_referrer', $host !== '' ? $host : 'direct');
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
        $hardSignalCodes = [
            'bot_blocklist', 'datacenter_asn', 'vpn_proxy_asn',
            'no_user_agent', 'crawler_or_tool_ua', 'spoofed_search_bot',
            'ip2proxy_datacenter', 'ip2proxy_bot', 'ip2proxy_vpn_proxy',
            'ip2proxy_threat', 'ip2proxy_high_fraud',
        ];
        // Opt-in (settings key bot_iprange_hard_signal): a hit in a provider's
        // own published range is treated as conclusive. Only consulted when a
        // range actually matched, so the settings read is skipped otherwise.
        foreach ($reasons as $r) {
            if (self::reasonCode($r) === 'iprange_datacenter') {
                if (self::iprangeHardSignalEnabled($visitor, $config)) {
                    $hardSignalCodes[] = 'iprange_datacenter';
                }
                break;
            }
        }
        $hardHits = [];
        $softHits = [];
        foreach ($reasons as $r) {
            $code = self::reasonCode($r);
            if (in_array($code, $hardSignalCodes, true)) {
                $hardHits[] = $r;
            } else {
                $softHits[] = $r;
            }
        }
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
