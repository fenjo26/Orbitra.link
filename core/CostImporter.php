<?php
// core/CostImporter.php
// Shared ingest path for cost-shaped records produced by the ad-platform engines
// (FacebookAdsEngine, GoogleAdsEngine). Used by both aggregator_cron.php (scheduled
// sync) and api.php (manual "Sync now"), so the two can never drift apart.
//
// Why this exists as a helper: ad platforms report a *running total* for the current
// day. Syncing at 10:00 and again at 18:00 must not be treated as a duplicate — the
// second sync carries eight more hours of spend. Records are therefore upserted on
// (connection_id, external_id) and the attribution is recomputed, not accumulated.

require_once __DIR__ . '/CurrencyRates.php';

class CostImporter
{
    /**
     * Click-parameter names each attribution level can live under, most specific
     * first. Facebook's source template writes ad_id/adset_id/campaign_id; Google
     * ValueTrack writes creative/adgroupid/campaignid. A connection can override
     * any of these via field_mapping when traffic passes through an app that
     * repacks the macros into sub_id_N (see docs/facebook.md).
     *
     * Must stay in sync with orbitraClickParamKeys() in core/ClickParams.php —
     * a key that is never captured on the click can never be matched here.
     */
    private const DEFAULT_KEYS = [
        'ad'       => ['ad_id', 'creative_id', 'creative'],
        'adset'    => ['adset_id', 'adgroup_id', 'adgroup', 'adgroupid', 'ad_group_id'],
        'campaign' => ['campaign_id', 'campaign', 'campaignid', 'ad_campaign_id'],
    ];

    /**
     * Ingest cost records for one aggregator connection.
     *
     * @param PDO   $pdo
     * @param int   $connectionId
     * @param array $records  Rows from an engine's fetchRecords():
     *                        {external_id, amount, currency, source_campaign_id,
     *                         ad_id, adset_id, date, raw_json}
     * @param array $fieldMapping Optional overrides: ad_id_param, adset_id_param,
     *                            campaign_id_param — the click parameter each ID
     *                            actually arrives in. 'timezone' overrides the
     *                            ad-account timezone used for date matching.
     * @param array $options      credentials  — connection credentials, used to look
     *                                          up the ad-account timezone;
     *                            timezone     — explicit IANA name, wins over both;
     *                            exclude_safe_page — override the auto-detected
     *                                          cloak-stream exclusion.
     * @return array {fetched, new, updated, matched, unmatched, currency, converted,
     *                timezone, safe_page_excluded}
     */
    public static function import(PDO $pdo, int $connectionId, array $records, array $fieldMapping = [], array $options = []): array
    {
        // Ad platforms report spend by *their* calendar day, in the ad account's
        // timezone. clicks.created_at is UTC. Comparing the two directly puts every
        // click before the account's midnight-offset on the previous day, so the
        // platform's spend for a day matches none of that day's clicks — the whole
        // import reconciles to nothing on any non-UTC account.
        $platformTz = self::resolveTimezone($pdo, $connectionId, $fieldMapping, $options);

        // Cost spread across safe-page (cloaked) clicks is spend the reports then
        // exclude, so it disappears from every surface. With crawler traffic
        // cloaked correctly that is most of the raw click volume.
        $excludeSafePage = array_key_exists('exclude_safe_page', $options)
            ? (bool) $options['exclude_safe_page']
            : self::safePageExclusionNeeded($pdo);

        $findExisting = $pdo->prepare("SELECT id, amount FROM cost_records WHERE connection_id = ? AND external_id = ?");
        $insertCost = $pdo->prepare("
            INSERT INTO cost_records
                (connection_id, external_id, source_campaign_id, ad_id, adset_id, amount, currency, click_date, raw_json, is_matched)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $updateCost = $pdo->prepare("
            UPDATE cost_records
            SET amount = ?, currency = ?, source_campaign_id = ?, ad_id = ?, adset_id = ?,
                click_date = ?, raw_json = ?, is_matched = ?
            WHERE id = ?
        ");

        $setClickCost = $pdo->prepare("UPDATE clicks SET cost = ? WHERE id = ?");

        // Prepared lookups are built lazily per parameter name and reused: the key
        // set is small and fixed, the record loop is not.
        $lookupCache = [];
        $lookup = function (string $param, string $tzModifier, bool $moneyOnly) use ($pdo, &$lookupCache) {
            $cacheKey = $param . '|' . $tzModifier . '|' . ($moneyOnly ? '1' : '0');
            if (!isset($lookupCache[$cacheKey])) {
                // json_extract's path is not a bindable parameter, so the name is
                // stripped of anything that could break out of the path literal.
                $safe = preg_replace('/[^A-Za-z0-9_]/', '', $param);
                // $tzModifier is generated from an integer minute count below, never
                // from user input, so it is safe to inline into the modifier literal.
                $dateExpr = $tzModifier === '' ? 'date(created_at)' : "date(created_at, '{$tzModifier}')";
                // COALESCE, not "= 0": a NULL is_safe_page (pre-v38 rows) is
                // money-side traffic and must not be filtered out.
                $safeCond = $moneyOnly ? " AND COALESCE(is_safe_page, 0) = 0" : '';
                $lookupCache[$cacheKey] = $pdo->prepare(
                    "SELECT id FROM clicks
                     WHERE json_extract(parameters_json, '$." . $safe . "') = ?
                       AND {$dateExpr} = ?" . $safeCond
                );
            }
            return $lookupCache[$cacheKey];
        };

        $keys = self::resolveKeys($fieldMapping);
        $trackerCurrency = CurrencyRates::trackerCurrency($pdo);

        $stats = [
            'fetched'   => count($records),
            'new'       => 0,
            'updated'   => 0,
            'matched'   => 0,
            'unmatched' => 0,
            'currency'  => $trackerCurrency,
            'converted' => 0,
            'timezone'  => $platformTz ?? 'UTC',
            'safe_page_excluded' => $excludeSafePage,
            'safe_page_fallbacks' => 0,
        ];

        foreach ($records as $rec) {
            $externalId = $rec['external_id'] ?? null;
            $adId       = (string) ($rec['ad_id'] ?? '');
            $campaignId = (string) ($rec['source_campaign_id'] ?? '');
            $adsetId    = (string) ($rec['adset_id'] ?? '');
            $clickDate  = (string) ($rec['date'] ?? date('Y-m-d'));

            // Ad accounts bill in their own currency. Reporting mixes this number
            // with revenue, so convert once here — everything downstream (cost_records,
            // clicks.cost, every report) is then in the tracker's currency.
            $sourceCurrency = strtoupper((string) ($rec['currency'] ?? 'USD'));
            $sourceAmount   = (float) ($rec['amount'] ?? 0);
            $amount = CurrencyRates::convert($pdo, $sourceAmount, $sourceCurrency, $trackerCurrency);
            if ($sourceCurrency !== $trackerCurrency) {
                $stats['converted']++;
            }

            // Keep the platform's own numbers alongside the converted one, otherwise
            // a wrong FX rate is undebuggable after the fact.
            $rawJson = self::annotateRaw($rec['raw_json'] ?? null, $sourceAmount, $sourceCurrency, $amount, $trackerCurrency);

            $existingId = null;
            $existingAmount = null;
            if ($externalId) {
                $findExisting->execute([$connectionId, $externalId]);
                $existingRow = $findExisting->fetch(PDO::FETCH_ASSOC);
                if ($existingRow) {
                    $existingId = (int) $existingRow['id'];
                    $existingAmount = (float) $existingRow['amount'];
                }
            }

            // Nothing changed since the last sync — skip the attribution work entirely.
            if ($existingId !== null && abs($existingAmount - $amount) < 0.0000001) {
                continue;
            }

            // Resolve the click set this spend belongs to. Most specific level first:
            // ad → adset → campaign. The adset step is what the Facebook template's
            // {{adset.id}} exists for; without it, spend on any campaign whose ad IDs
            // the tracker never saw falls all the way back to campaign level or is lost.
            // Offset for the spend day itself, not for "now": a DST account would
            // otherwise have its historical days shifted by today's offset.
            $tzModifier = self::offsetModifier($platformTz, $clickDate);

            $clickIds = [];
            foreach ([['ad', $adId], ['adset', $adsetId], ['campaign', $campaignId]] as $level) {
                [$levelName, $value] = $level;
                if ($value === '' || !empty($clickIds)) {
                    continue;
                }
                foreach ($keys[$levelName] as $param) {
                    if ($excludeSafePage) {
                        $stmt = $lookup($param, $tzModifier, true);
                        $stmt->execute([$value, $clickDate]);
                        $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        if (!empty($found)) {
                            $clickIds = $found;
                            break;
                        }
                    }
                    // Fallback: a period with no money-side clicks at all (pure
                    // crawler day, or cloaking misconfigured) still gets its spend
                    // attributed rather than silently dropped as unmatched.
                    $stmt = $lookup($param, $tzModifier, false);
                    $stmt->execute([$value, $clickDate]);
                    $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($found)) {
                        $clickIds = $found;
                        if ($excludeSafePage) {
                            $stats['safe_page_fallbacks']++;
                        }
                        break;
                    }
                }
            }

            $isMatched = 0;
            if (!empty($clickIds) && $amount > 0) {
                // Assign rather than accumulate: the platform reports a running total for
                // the day, so each sync must land on the same answer. Using += here is what
                // made re-syncs double-count. The day's spend is split evenly across the
                // day's clicks (flat CPC model), which is also how manual cost entry works.
                $cpc = $amount / count($clickIds);
                foreach ($clickIds as $cid) {
                    $setClickCost->execute([$cpc, $cid]);
                }
                $isMatched = 1;
                $stats['matched']++;
            } else {
                $stats['unmatched']++;
            }

            if ($existingId !== null) {
                $updateCost->execute([
                    $amount, $trackerCurrency, $campaignId, $adId, $adsetId,
                    $clickDate, $rawJson, $isMatched, $existingId,
                ]);
                $stats['updated']++;
            } else {
                $insertCost->execute([
                    $connectionId, $externalId, $campaignId, $adId, $adsetId,
                    $amount, $trackerCurrency, $clickDate, $rawJson, $isMatched,
                ]);
                $stats['new']++;
            }
        }

        return $stats;
    }

    /**
     * IANA timezone of the ad account this connection reports for, or null when it
     * is unknown (and UTC is therefore the only honest assumption).
     *
     * Order: explicit option, field_mapping override, whatever the engine stored in
     * credentials, then a cached live lookup. The live answer is cached in settings
     * for a day — it changes about never, and the import loop must not make a Graph
     * call per record.
     */
    private static function resolveTimezone(PDO $pdo, int $connectionId, array $fieldMapping, array $options): ?string
    {
        $candidates = [
            $options['timezone'] ?? null,
            $fieldMapping['timezone'] ?? null,
        ];

        $credentials = is_array($options['credentials'] ?? null) ? $options['credentials'] : [];
        $engine = '';
        try {
            $row = $pdo->prepare("SELECT engine, credentials_json, field_mapping_json FROM aggregator_connections WHERE id = ? LIMIT 1");
            $row->execute([$connectionId]);
            $conn = $row->fetch(PDO::FETCH_ASSOC) ?: [];
            $engine = strtolower((string) ($conn['engine'] ?? ''));
            if (!$credentials && !empty($conn['credentials_json'])) {
                $decoded = json_decode((string) $conn['credentials_json'], true);
                $credentials = is_array($decoded) ? $decoded : [];
            }
            if (!empty($conn['field_mapping_json'])) {
                $fm = json_decode((string) $conn['field_mapping_json'], true);
                if (is_array($fm) && !empty($fm['timezone'])) {
                    $candidates[] = $fm['timezone'];
                }
            }
        } catch (\Throwable $e) {
        }

        $candidates[] = $credentials['timezone'] ?? null;
        $candidates[] = $credentials['timezone_name'] ?? null;

        foreach ($candidates as $candidate) {
            $name = trim((string) ($candidate ?? ''));
            if ($name !== '' && self::isValidTimezone($name)) {
                return $name;
            }
        }

        // Cached live lookup.
        $cacheKey = 'aggregator_tz_' . $connectionId;
        try {
            $cached = $pdo->prepare("SELECT value, updated_at FROM settings WHERE key = ? LIMIT 1");
            $cached->execute([$cacheKey]);
            $cachedRow = $cached->fetch(PDO::FETCH_ASSOC);
            if ($cachedRow && !empty($cachedRow['value'])) {
                $age = time() - (int) strtotime((string) ($cachedRow['updated_at'] ?? '') . ' UTC');
                if ($age < 86400 && self::isValidTimezone((string) $cachedRow['value'])) {
                    return (string) $cachedRow['value'];
                }
            }
        } catch (\Throwable $e) {
        }

        $live = null;
        if ($engine === 'facebook' || $engine === 'facebook_ads') {
            $enginePath = __DIR__ . '/../aggregator_engines/FacebookAdsEngine.php';
            if (is_file($enginePath)) {
                require_once $enginePath;
                if (method_exists('FacebookAdsEngine', 'accountTimezone')) {
                    try {
                        $live = FacebookAdsEngine::accountTimezone($credentials);
                    } catch (\Throwable $e) {
                        $live = null;
                    }
                }
            }
        }

        if ($live !== null && self::isValidTimezone($live)) {
            try {
                $pdo->prepare("
                    INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                    ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')
                ")->execute([$cacheKey, $live]);
            } catch (\Throwable $e) {
            }
            return $live;
        }

        return null;
    }

    private static function isValidTimezone(string $name): bool
    {
        try {
            new DateTimeZone($name);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * SQLite date() modifier that turns a UTC created_at into the ad account's local
     * date, evaluated for the spend day itself so DST boundaries land correctly.
     */
    private static function offsetModifier(?string $timezone, string $date): string
    {
        if ($timezone === null || $timezone === '') {
            return '';
        }
        try {
            $tz = new DateTimeZone($timezone);
            $ref = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' 12:00:00', $tz);
            if (!$ref) {
                $ref = new DateTime('now', $tz);
            }
            $minutes = (int) round($tz->getOffset($ref) / 60);
        } catch (\Throwable $e) {
            return '';
        }

        return $minutes === 0 ? '' : sprintf('%+d minutes', $minutes);
    }

    /**
     * Does any active campaign run a cloak stream whose safe-page clicks are kept out
     * of reports? Mirrors orbitraSafePageExclusionNeeded() in api.php, restated here
     * because the cron never loads api.php.
     */
    private static function safePageExclusionNeeded(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM streams s
                JOIN campaigns c ON s.campaign_id = c.id
                WHERE s.schema_type = 'cloak'
                  AND s.schema_custom_json IS NOT NULL AND s.schema_custom_json != '' AND s.schema_custom_json != '{}'
                  AND c.state = 'active'
                LIMIT 1
            ");
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Merge per-connection overrides into the default key list. An override is
     * tried first but the defaults stay as a fallback, so a half-configured
     * mapping degrades instead of matching nothing.
     */
    private static function resolveKeys(array $fieldMapping): array
    {
        $keys = self::DEFAULT_KEYS;
        $overrides = [
            'ad'       => $fieldMapping['ad_id_param'] ?? null,
            'adset'    => $fieldMapping['adset_id_param'] ?? null,
            'campaign' => $fieldMapping['campaign_id_param'] ?? null,
        ];

        foreach ($overrides as $level => $param) {
            if (is_string($param) && trim($param) !== '') {
                array_unshift($keys[$level], trim($param));
                $keys[$level] = array_values(array_unique($keys[$level]));
            }
        }

        return $keys;
    }

    private static function annotateRaw($rawJson, float $sourceAmount, string $sourceCurrency, float $amount, string $trackerCurrency): ?string
    {
        $decoded = is_string($rawJson) ? json_decode($rawJson, true) : null;
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $decoded['_orbitra'] = [
            'source_amount'   => $sourceAmount,
            'source_currency' => $sourceCurrency,
            'stored_amount'   => $amount,
            'stored_currency' => $trackerCurrency,
            'imported_at'     => gmdate('c'),
        ];

        return json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }
}
