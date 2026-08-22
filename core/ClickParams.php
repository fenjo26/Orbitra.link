<?php
// core/ClickParams.php
//
// One place that decides what ends up in clicks.parameters_json.
//
// Why this is shared: index.php (the redirect path) and click.php (the Click API
// path) each used to build the list themselves, and both only kept a fixed set of
// sub_id_* keys. Nothing captured ad_id / adset_id / campaign_id — which meant the
// traffic-source templates advertised {{ad.id}} and {{adset.id}} to the user while
// the tracker threw the values away. Cost import then matched zero clicks and every
// campaign showed spend 0 / ROI ∞.
//
// It also captures the platform click identifiers (fbclid and friends) plus the
// Meta browser cookies (_fbp / _fbc), which is what server-side Conversions API
// deduplication and attribution need. See core/FacebookConversions.php.

if (!function_exists('orbitraClickParamKeys')) {

    /**
     * Normalize malformed query strings that contain a literal '?' — a valid
     * query string never does. Facebook Ads (URL parameters box with a leading
     * "?") and third-party cloakers concatenate a second "?" onto a URL that
     * already had one; PHP's own parse then swallows everything between the two
     * "?" into the FIRST param's value ("c=tk4u2h?utm=x&ad_id=1" → c holds
     * "tk4u2h?utm=x", ad_id is lost from the routing keys).
     *
     * Handles:
     * - "c=tk4u2h?utm_source=facebook" → ["c" => "tk4u2h", "utm_source" => "facebook"]
     * - "??param=value" → ["param" => "value"]
     * - "utm_a=1?utm_b=2&x=3" → ["utm_a" => "1", "utm_b" => "2", "x" => "3"]
     *
     * Values decoded by parse_str that legitimately contain '?' (from %3F) are
     * preserved as-is.
     *
     * @param string $queryString The raw query string from $_SERVER['QUERY_STRING']
     * @return array Normalized key-value pairs (later segments override earlier)
     */
    function orbitraNormalizeQueryString(string $queryString): array
    {
        if ($queryString === '' || $queryString === '?') {
            return [];
        }

        $queryString = ltrim($queryString, '?');

        // A literal '?' separates what should have been independent params.
        // Each segment is parsed as its own query string.
        $segments = explode('?', $queryString);
        $result = [];

        foreach ($segments as $segment) {
            if (trim($segment) === '') {
                continue;
            }

            parse_str($segment, $parsed);

            foreach ((array) $parsed as $key => $value) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Detect and auto-heal malformed query strings in the incoming request.
     * Returns true if any $_GET entry was recovered or repaired.
     *
     * MUST run before campaign routing: the first query pair is usually the
     * routing key (token / campaign / campaign_id), and an add-if-missing merge
     * would leave its corrupted value in place — the click would still be lost.
     * Normalized values therefore REPLACE $_GET's, which is safe because the
     * function only runs when the query string contains a literal '?' and is
     * broken by definition.
     *
     * @param array $getArray $_GET (or a copy), modified in place
     * @param string|null $queryString Raw query string; defaults to $_SERVER['QUERY_STRING']
     * @return bool True if healing changed anything
     */
    function orbitraHealQueryString(array &$getArray, ?string $queryString = null): bool
    {
        if ($queryString === null) {
            $queryString = $_SERVER['QUERY_STRING'] ?? '';
        }

        // A valid query string contains no literal '?': its presence is the
        // double-"?" smell. Normal traffic never enters the healing path.
        if (strpos($queryString, '?') === false) {
            return false;
        }

        $normalized = orbitraNormalizeQueryString($queryString);
        if (empty($normalized)) {
            return false;
        }

        $healed = false;
        foreach ($normalized as $key => $value) {
            if (!array_key_exists($key, $getArray) || $getArray[$key] !== $value) {
                $getArray[$key] = $value;
                $healed = true;
            }
        }

        return $healed;
    }

    /** Baseline keys, kept for backward compatibility with existing installs. */
    function orbitraClickParamKeys(): array
    {
        static $keys = null;
        if ($keys !== null) {
            return $keys;
        }

        $keys = ['keyword', 'cost', 'currency', 'external_id', 'creative_id', 'ad_campaign_id', 'source', 'subid'];

        for ($i = 1; $i <= 30; $i++) {
            $keys[] = 'sub_id_' . $i;
            $keys[] = 'sub' . $i;
        }

        // Ad-network attribution IDs. These are the keys cost import matches on —
        // see CostImporter::DEFAULT_KEYS, which must stay in sync with this list.
        $keys = array_merge($keys, [
            'ad_id', 'adset_id', 'campaign_id',
            'ad_name', 'adset_name', 'campaign_name',
            'adgroup', 'adgroupid', 'ad_group_id', 'campaignid', 'creative',
            'site', 'site_id', 'placement', 'utm_placement', 'widget', 'section',
            'matchtype', 'device', 'loc_physical', 'cpc',
        ]);

        // Platform click identifiers. fbclid is the one Meta's Conversions API
        // needs; the rest are captured so the same mechanism works for other
        // networks without another migration.
        $keys = array_merge($keys, [
            'fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'msclkid',
            'twclid', 'rdt_cid', 'li_fat_id', 'epik', 'obclid', 'ScCid', 'yclid',
        ]);

        // UTM tags — cheap to keep and routinely the only thing a manual campaign sets.
        foreach (['source', 'medium', 'campaign', 'content', 'term'] as $utm) {
            $keys[] = 'utm_' . $utm;
        }

        $keys = array_values(array_unique($keys));
        return $keys;
    }

    /**
     * Parameters declared on the campaign's traffic source, as {param => alias}.
     * A source template can name anything — this is how a custom network's macro
     * reaches parameters_json under a predictable alias.
     */
    function orbitraSourceParamAliases(PDO $pdo, $sourceId): array
    {
        static $cache = [];

        $sourceId = (int) $sourceId;
        if ($sourceId <= 0) {
            return [];
        }
        if (isset($cache[$sourceId])) {
            return $cache[$sourceId];
        }

        $aliases = [];
        try {
            $stmt = $pdo->prepare("SELECT parameters_json FROM traffic_sources WHERE id = ? LIMIT 1");
            $stmt->execute([$sourceId]);
            $decoded = json_decode((string) $stmt->fetchColumn(), true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $param = trim((string) ($entry['param'] ?? ''));
                    $alias = trim((string) ($entry['alias'] ?? $param));
                    if ($param !== '' && $alias !== '') {
                        $aliases[$param] = $alias;
                    }
                }
            }
        } catch (\Throwable $e) {
            // A missing/!legacy traffic_sources row must not break click logging.
        }

        $cache[$sourceId] = $aliases;
        return $aliases;
    }

    /**
     * Build the parameter map for one click.
     *
     * @param array $incoming  $_GET merged with $_POST
     * @param array $cookies   $_COOKIE
     * @param mixed $sourceId  campaigns.source_id, or null
     */
    function orbitraCollectClickParams(PDO $pdo, array $incoming, array $cookies = [], $sourceId = null): array
    {
        $maxValueLength = 512;
        $maxKeys = 120;

        $params = [];

        $store = function (string $key, $value) use (&$params, $maxValueLength, $maxKeys) {
            if (count($params) >= $maxKeys || $key === '') {
                return;
            }
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $value = (string) $value;
            if ($value === '') {
                return;
            }
            if (strlen($value) > $maxValueLength) {
                $value = substr($value, 0, $maxValueLength);
            }
            $params[$key] = $value;
        };

        foreach (orbitraClickParamKeys() as $key) {
            if (isset($incoming[$key])) {
                $store($key, $incoming[$key]);
            }
        }

        // Source-declared parameters. Applied after the baseline so an explicit
        // template mapping wins when both name the same alias.
        foreach (orbitraSourceParamAliases($pdo, $sourceId) as $param => $alias) {
            if (isset($incoming[$param])) {
                $store($alias, $incoming[$param]);
            }
        }

        // Legacy alias: a bare ?subid= is sub_id_1 unless one was sent explicitly.
        if (isset($params['subid']) && !isset($params['sub_id_1'])) {
            $params['sub_id_1'] = $params['subid'];
        }

        // Meta browser cookies. _fbp identifies the browser, _fbc the ad click;
        // both raise Conversions API event match quality noticeably, and neither
        // can be recovered later — they only exist on the request that carried them.
        if (!empty($cookies['_fbp'])) {
            $store('fbp', $cookies['_fbp']);
        }
        if (!empty($cookies['_fbc'])) {
            $store('fbc', $cookies['_fbc']);
        } elseif (!empty($params['fbclid'])) {
            // Meta's documented construction when the cookie is absent:
            // fb.<subdomain-index>.<creation-time-ms>.<fbclid>
            $store('fbc', 'fb.1.' . (int) round(microtime(true) * 1000) . '.' . $params['fbclid']);
        }

        return $params;
    }
}
