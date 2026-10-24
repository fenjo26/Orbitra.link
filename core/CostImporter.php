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
require_once __DIR__ . '/ReportMetrics.php';

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
     * Zone (site / placement) id keys — the level push/pop networks (AdMaven,
     * PropellerAds, …) report spend at. The Keitaro source pack writes every
     * network's zone macro into `source` (HilltopAds {{zoneid}}, Kadam
     * {site_id}, …), the built-in PropellerAds template uses `zone`. A bare
     * zone id is NOT unique across networks ("12345" exists in each), so the
     * zone level never matches on its own — only in combination with the
     * connection's campaign id (zoneLookupFactory below). Kept out of
     * DEFAULT_KEYS on purpose: attributionKeys()/attributionMatchSql() feed
     * the reports, which group by campaign, not by zone.
     * Must stay in sync with orbitraClickParamKeys() in core/ClickParams.php.
     */
    private const ZONE_KEYS = ['source', 'zone', 'zone_id', 'zoneid', 'site_id', 'sub_source_id'];

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

        // Agency commission (migration 61): applied at import time only, so a
        // percent change affects new spend and never rewrites history.
        $feePercent = self::connectionFeePercent($pdo, $connectionId);

        // Platform for ad_entities names/statuses (migration 62).
        $platform = '';
        try {
            $stmt = $pdo->prepare("SELECT engine FROM aggregator_connections WHERE id = ? LIMIT 1");
            $stmt->execute([$connectionId]);
            $platform = self::enginePlatform((string) $stmt->fetchColumn());
        } catch (\Throwable $e) {
        }

        // Cost spread across safe-page (cloaked) clicks is spend the reports then
        // exclude, so it disappears from every surface. With crawler traffic
        // cloaked correctly that is most of the raw click volume.
        $excludeSafePage = array_key_exists('exclude_safe_page', $options)
            ? (bool) $options['exclude_safe_page']
            : self::safePageExclusionNeeded($pdo);

        $findExisting = $pdo->prepare("SELECT id, amount, is_matched FROM cost_records WHERE connection_id = ? AND external_id = ?");
        $insertCost = $pdo->prepare("
            INSERT INTO cost_records
                (connection_id, external_id, source_campaign_id, ad_id, adset_id, zone_id, amount, fee_amount, currency, click_date, impressions, platform_clicks, raw_json, is_matched)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $updateCost = $pdo->prepare("
            UPDATE cost_records
            SET amount = ?, fee_amount = ?, currency = ?, source_campaign_id = ?, ad_id = ?, adset_id = ?, zone_id = ?,
                click_date = ?, impressions = ?, platform_clicks = ?, raw_json = ?, is_matched = ?
            WHERE id = ?
        ");

        // clicks.cost carries spend WITH the agency fee (honest ROI/CPC the
        // moment spend lands); clicks.fee carries the fee share so reports can
        // split "cabinet spend" vs "spend + fee" in any grouping.
        $setClickCost = $pdo->prepare("UPDATE clicks SET cost = ?, fee = ? WHERE id = ?");

        // Prepared lookups are built lazily per parameter name and reused: the key
        // set is small and fixed, the record loop is not.
        $lookup = self::clickLookupFactory($pdo);
        $zoneLookup = self::zoneLookupFactory($pdo);

        $keys = self::resolveKeys($fieldMapping);
        $zoneKeys = self::resolveZoneKeys($fieldMapping);
        $trackerCurrency = CurrencyRates::trackerCurrency($pdo);

        // ad_entities upserts (names for the reports' "Cabinet › Campaign ›
        // Adset" drill path); flushed once after the record loop.
        $entities = [];

        // Zone-less fallback click sets shared by several zone rows (see
        // resolveClickSet()); keyed by the click set, flushed after the loop.
        $sharedSets = [];

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
            'fee_percent' => $feePercent,
            'fee'        => 0.0,
        ];

        foreach ($records as $rec) {
            $externalId = $rec['external_id'] ?? null;
            $adId       = (string) ($rec['ad_id'] ?? '');
            $campaignId = (string) ($rec['source_campaign_id'] ?? '');
            $adsetId    = (string) ($rec['adset_id'] ?? '');
            $zoneId     = (string) ($rec['zone_id'] ?? '');
            $clickDate  = (string) ($rec['date'] ?? date('Y-m-d'));
            $impressions = (int) ($rec['impressions'] ?? 0);
            $platformClicks = (int) ($rec['platform_clicks'] ?? 0);

            // Names land in ad_entities even when the spend row is skipped as
            // unchanged: a renamed ad must not keep showing its old name for a
            // month. Keyed dedup — one row per entity per sync.
            if ($platform !== '' && ($campaignId !== '' || $adsetId !== '' || $adId !== '')) {
                $entities[$platform . '|campaign|' . $campaignId] = ['platform' => $platform, 'level' => 'campaign', 'external_id' => $campaignId, 'name' => (string) ($rec['campaign_name'] ?? ''), 'parent_id' => null];
                if ($adsetId !== '') {
                    $entities[$platform . '|adset|' . $adsetId] = ['platform' => $platform, 'level' => 'adset', 'external_id' => $adsetId, 'name' => (string) ($rec['adset_name'] ?? ''), 'parent_id' => $campaignId];
                }
                if ($adId !== '') {
                    $entities[$platform . '|ad|' . $adId] = ['platform' => $platform, 'level' => 'ad', 'external_id' => $adId, 'name' => (string) ($rec['ad_name'] ?? ''), 'parent_id' => $adsetId];
                }
            }

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
            $fee = $feePercent > 0 ? round($amount * $feePercent / 100, 6) : 0.0;
            $amountWithFee = $amount + $fee;
            $rawJson = self::annotateRaw($rec['raw_json'] ?? null, $sourceAmount, $sourceCurrency, $amount, $trackerCurrency, $fee, $feePercent);

            $existingId = null;
            $existingAmount = null;
            $existingMatched = false;
            if ($externalId) {
                $findExisting->execute([$connectionId, $externalId]);
                $existingRow = $findExisting->fetch(PDO::FETCH_ASSOC);
                if ($existingRow) {
                    $existingId = (int) $existingRow['id'];
                    $existingAmount = (float) $existingRow['amount'];
                    $existingMatched = (int) ($existingRow['is_matched'] ?? 0) === 1;
                }
            }

            // Nothing changed since the last sync and the row is already attributed —
            // skip the attribution work, but still count it as matched (skipping it
            // silently made every re-sync report "fetched N, matched 0").
            // An unchanged row that is still UNmatched is retried: its clicks may have
            // arrived after the first sync, or the ad URL may have been fixed since.
            // Skipping those left past days unmatched forever, because a closed day's
            // spend never changes again.
            // Zone rows are never skipped: a zone row that fell back onto the
            // campaign's zone-less clicks shares that click set with its sibling
            // zones, and the set's cost is their SUM — recomputing it needs
            // every sibling's amount, changed or not (see resolveClickSet()).
            if ($existingId !== null && $existingMatched && $zoneId === '' && abs($existingAmount - $amount) < 0.0000001) {
                $stats['matched']++;
                continue;
            }

            // Resolve the click set this spend belongs to. Most specific level first:
            // ad → adset → campaign. The adset step is what the Facebook template's
            // {{adset.id}} exists for; without it, spend on any campaign whose ad IDs
            // the tracker never saw falls all the way back to campaign level or is lost.
            // Offset for the spend day itself, not for "now": a DST account would
            // otherwise have its historical days shifted by today's offset.
            $tzModifier = self::offsetModifier($platformTz, $clickDate);

            $resolved = self::resolveClickSet($lookup, $zoneLookup, $keys, $zoneKeys, $adId, $adsetId, $campaignId, $zoneId, $clickDate, $tzModifier, $excludeSafePage);
            $clickIds = $resolved['ids'];
            if ($resolved['fallback'] && $excludeSafePage) {
                $stats['safe_page_fallbacks']++;
            }

            $isMatched = 0;
            if (!empty($clickIds) && $amount > 0 && !empty($resolved['shared'])) {
                // Shared zone-less fallback set: defer — summed and assigned
                // once after the loop, so sibling zones do not overwrite
                // each other's spend on the same clicks.
                self::addSharedCost($sharedSets, $clickIds, $amountWithFee, $fee);
                $isMatched = 1;
                $stats['matched']++;
                $stats['fee'] += $fee;
            } elseif (!empty($clickIds) && $amount > 0) {
                // Assign rather than accumulate: the platform reports a running total for
                // the day, so each sync must land on the same answer. Using += here is what
                // made re-syncs double-count. The day's spend is split evenly across the
                // day's clicks (flat CPC model), which is also how manual cost entry works.
                $cpc = $amountWithFee / count($clickIds);
                $feePerClick = $fee / count($clickIds);
                foreach ($clickIds as $cid) {
                    $setClickCost->execute([$cpc, $feePerClick, $cid]);
                }
                $isMatched = 1;
                $stats['matched']++;
                $stats['fee'] += $fee;
            } else {
                $stats['unmatched']++;
            }

            if ($existingId !== null) {
                $updateCost->execute([
                    $amount, $fee, $trackerCurrency, $campaignId, $adId, $adsetId, $zoneId,
                    $clickDate, $impressions, $platformClicks, $rawJson, $isMatched, $existingId,
                ]);
                $stats['updated']++;
            } else {
                $insertCost->execute([
                    $connectionId, $externalId, $campaignId, $adId, $adsetId, $zoneId,
                    $amount, $fee, $trackerCurrency, $clickDate, $impressions, $platformClicks, $rawJson, $isMatched,
                ]);
                $stats['new']++;
            }
        }

        self::flushSharedCost($sharedSets, $setClickCost);

        // Flush the ad_entities upserts. A rename wins, an empty name never
        // erases a stored one; status is owned by the hourly refresher.
        if (!empty($entities)) {
            try {
                $upsertEntity = $pdo->prepare("
                    INSERT INTO ad_entities (platform, level, external_id, name, parent_id, connection_id, first_seen_at, last_seen_at)
                    VALUES (?,?,?,?,?,?,datetime('now'),datetime('now'))
                    ON CONFLICT(platform, level, external_id) DO UPDATE SET
                        name = CASE WHEN excluded.name != '' THEN excluded.name ELSE ad_entities.name END,
                        parent_id = COALESCE(NULLIF(excluded.parent_id, ''), ad_entities.parent_id),
                        connection_id = excluded.connection_id,
                        last_seen_at = datetime('now')
                ");
                foreach ($entities as $entity) {
                    if ($entity['external_id'] === '') {
                        continue;
                    }
                    $upsertEntity->execute([
                        $entity['platform'], $entity['level'], $entity['external_id'],
                        $entity['name'], $entity['parent_id'], $connectionId,
                    ]);
                }
                $stats['ad_entities'] = count($entities);
            } catch (\Throwable $e) {
                // No ad_entities table yet (pre-migration DB or a unit-test
                // schema): reports fall back to bare ids, import continues.
                $stats['ad_entities'] = 0;
            }
        } else {
            $stats['ad_entities'] = 0;
        }

        // Nothing attributed: say *why* in terms the user can act on, instead of a
        // generic "check your URL". Cheap (a handful of grouped queries) and only
        // runs on the failure path.
        $stats['diagnostic'] = null;
        if ($stats['matched'] === 0 && $stats['fetched'] > 0) {
            try {
                $stats['diagnostic'] = self::diagnoseUnmatched($pdo, $records, $keys, $platformTz);
            } catch (\Throwable $e) {
                $stats['diagnostic'] = null;
            }
        }

        return $stats;
    }

    /**
     * Prepared "clicks carrying this platform value on this local day" lookup,
     * shared by import() and rematch(). Prepared lazily per (param, day
     * modifier, money-side) and reused — the key set is small and fixed, the
     * record loop is not.
     *
     * json_extract's path is not a bindable parameter. Names reaching here are
     * vetted (DEFAULT_KEYS literals or resolveKeys()'s identifier whitelist);
     * the strip inside is belt-and-braces. The tz modifier is generated from an
     * integer minute count, never from user input, so it is safe to inline.
     */
    private static function clickLookupFactory(PDO $pdo): \Closure
    {
        $cache = [];
        return function (string $param, string $tzModifier, bool $moneyOnly) use ($pdo, &$cache) {
            $cacheKey = $param . '|' . $tzModifier . '|' . ($moneyOnly ? '1' : '0');
            if (!isset($cache[$cacheKey])) {
                $safe = preg_replace('/[^A-Za-z0-9_]/', '', $param);
                $dateExpr = $tzModifier === '' ? 'date(created_at)' : "date(created_at, '{$tzModifier}')";
                // Money-side clicks only, resolved per campaign by
                // safePagePredicate() - the same rule api.php's reports use, so
                // cost lands on exactly the clicks the reports will count.
                $safeCond = $moneyOnly ? ' AND ' . self::safePagePredicate() : '';
                $cache[$cacheKey] = $pdo->prepare(
                    "SELECT id FROM clicks
                     WHERE json_extract(parameters_json, '$." . $safe . "') = ?
                       AND {$dateExpr} = ?" . $safeCond
                );
            }
            return $cache[$cacheKey];
        };
    }

    /**
     * Prepared compound lookups for the zone level. Two shapes:
     *  - mode 'zone': clicks carrying BOTH the zone param value and the
     *    campaign param value on the local day — binds (zone, campaign, date);
     *  - mode 'campaign_sans_zone': clicks carrying the campaign param value
     *    and no value in ANY zone key — binds (campaign, date). The zone-empty
     *    fragment is built once per cached statement from the vetted zone key
     *    list, never from user input.
     *
     * Same money-side / all-clicks pass convention as clickLookupFactory().
     */
    private static function zoneLookupFactory(PDO $pdo): \Closure
    {
        $cache = [];
        return function (string $zoneParam, string $campaignParam, array $zoneKeys, string $tzModifier, bool $moneyOnly, string $mode) use ($pdo, &$cache) {
            $cacheKey = $mode . '|' . $zoneParam . '|' . $campaignParam . '|' . implode(',', $zoneKeys) . '|' . $tzModifier . '|' . ($moneyOnly ? '1' : '0');
            if (!isset($cache[$cacheKey])) {
                $camp = preg_replace('/[^A-Za-z0-9_]/', '', $campaignParam);
                $dateExpr = $tzModifier === '' ? 'date(created_at)' : "date(created_at, '{$tzModifier}')";
                $safeCond = $moneyOnly ? ' AND ' . self::safePagePredicate() : '';
                if ($mode === 'zone') {
                    $zone = preg_replace('/[^A-Za-z0-9_]/', '', $zoneParam);
                    $cache[$cacheKey] = $pdo->prepare(
                        "SELECT id FROM clicks
                         WHERE json_extract(parameters_json, '$." . $zone . "') = ?
                           AND json_extract(parameters_json, '$." . $camp . "') = ?
                           AND {$dateExpr} = ?" . $safeCond
                    );
                } else {
                    $zoneEmpty = implode(' AND ', array_map(function ($zk) {
                        $safe = preg_replace('/[^A-Za-z0-9_]/', '', $zk);
                        $v = "json_extract(parameters_json, '$." . $safe . "')";
                        return "({$v} IS NULL OR {$v} = '')";
                    }, $zoneKeys));
                    $cache[$cacheKey] = $pdo->prepare(
                        "SELECT id FROM clicks
                         WHERE json_extract(parameters_json, '$." . $camp . "') = ?
                           AND {$dateExpr} = ?
                           AND {$zoneEmpty}" . $safeCond
                    );
                }
            }
            return $cache[$cacheKey];
        };
    }

    /**
     * The click set a spend row belongs to, most specific level first:
     * ad → adset → zone×campaign → campaign. Shared by import() and rematch()
     * so the two matching paths cannot drift apart.
     *
     * The zone level (push/pop rows carrying zone_id) has two guards the other
     * levels do not:
     *  - a zone matches only TOGETHER with the connection's campaign id — a
     *    bare zone id means a different zone in every network;
     *  - a zone row that found no zone×campaign clicks falls back to the
     *    campaign's clicks that carry NO zone value at all, never to the
     *    whole campaign: cost is assigned (not accumulated), so falling onto
     *    clicks already priced by their own zone rows would overwrite them.
     *    Several zone rows usually land on that same zone-less set (the zone
     *    macro is missing from the ad link), so the set is flagged `shared`
     *    and its cost is the SUM of those rows, assigned once by the caller —
     *    row by row, only the last zone's spend would survive.
     *
     * @return array{ids:string[], fallback:bool, shared:bool} fallback marks a match made on
     *               the all-clicks pass after the money-side pass found nothing
     *               (pure crawler day / cloaking misconfigured) — import()
     *               reports it so the operator notices.
     */
    private static function resolveClickSet(\Closure $lookup, \Closure $zoneLookup, array $keys, array $zoneKeys, string $adId, string $adsetId, string $campaignId, string $zoneId, string $clickDate, string $tzModifier, bool $excludeSafePage): array
    {
        $clickIds = [];
        $fallback = false;
        $shared = false;
        foreach ([['ad', $adId], ['adset', $adsetId]] as $level) {
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
                // Fallback: a period with no money-side clicks at all still gets
                // its spend attributed rather than silently dropped as unmatched.
                $stmt = $lookup($param, $tzModifier, false);
                $stmt->execute([$value, $clickDate]);
                $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($found)) {
                    $clickIds = $found;
                    $fallback = true;
                    break;
                }
            }
        }

        // Zone level: zone × campaign compound match.
        if (empty($clickIds) && $zoneId !== '' && $campaignId !== '') {
            foreach ($keys['campaign'] as $campaignParam) {
                foreach ($zoneKeys as $zoneParam) {
                    if ($excludeSafePage) {
                        $stmt = $zoneLookup($zoneParam, $campaignParam, $zoneKeys, $tzModifier, true, 'zone');
                        $stmt->execute([$zoneId, $campaignId, $clickDate]);
                        $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        if (!empty($found)) {
                            $clickIds = $found;
                            break 2;
                        }
                    }
                    $stmt = $zoneLookup($zoneParam, $campaignParam, $zoneKeys, $tzModifier, false, 'zone');
                    $stmt->execute([$zoneId, $campaignId, $clickDate]);
                    $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($found)) {
                        $clickIds = $found;
                        $fallback = true;
                        break 2;
                    }
                }
            }
        }

        // Zone-row fallback: the campaign's clicks that carry no zone value in
        // any zone key (macro stripped / clicks lost). Zone-less clicks cannot
        // be claimed by another zone's row, so this never overwrites zone
        // attribution done above.
        if (empty($clickIds) && $zoneId !== '' && $campaignId !== '') {
            foreach ($keys['campaign'] as $campaignParam) {
                if ($excludeSafePage) {
                    $stmt = $zoneLookup('', $campaignParam, $zoneKeys, $tzModifier, true, 'campaign_sans_zone');
                    $stmt->execute([$campaignId, $clickDate]);
                    $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($found)) {
                        $clickIds = $found;
                        $shared = true;
                        break;
                    }
                }
                $stmt = $zoneLookup('', $campaignParam, $zoneKeys, $tzModifier, false, 'campaign_sans_zone');
                $stmt->execute([$campaignId, $clickDate]);
                $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($found)) {
                    $clickIds = $found;
                    $fallback = true;
                    $shared = true;
                    break;
                }
            }
        }

        // Plain campaign level — only for rows that are NOT zone-scoped
        // (FB/Google/TikTok, HilltopAds campaign totals). A zone row must not
        // land here, see the method note.
        if (empty($clickIds) && $zoneId === '') {
            foreach ($keys['campaign'] as $param) {
                if ($excludeSafePage) {
                    $stmt = $lookup($param, $tzModifier, true);
                    $stmt->execute([$campaignId, $clickDate]);
                    $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($found)) {
                        $clickIds = $found;
                        break;
                    }
                }
                $stmt = $lookup($param, $tzModifier, false);
                $stmt->execute([$campaignId, $clickDate]);
                $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($found)) {
                    $clickIds = $found;
                    $fallback = true;
                    break;
                }
            }
        }
        return ['ids' => $clickIds, 'fallback' => $fallback, 'shared' => $shared];
    }

    /**
     * Public read-side view of resolveKeys(): the candidate click-parameter
     * names per attribution level (ad/adset/campaign), with per-connection
     * field_mapping overrides merged in. Reports use it to find the clicks a
     * connection's spend was attributed to, exactly like the importer does.
     */
    public static function attributionKeys(array $fieldMapping = []): array
    {
        return self::resolveKeys($fieldMapping);
    }

    /**
     * The zone key list (with the per-connection override merged) — ZoneRules
     * judges zones by the same keys the importer priced them under, so a rule
     * can never see a zone id the cost import matched differently.
     */
    public static function zoneAttributionKeys(array $fieldMapping = []): array
    {
        return self::resolveZoneKeys($fieldMapping);
    }

    /**
     * SQL fragment matching clicks by attribution ids across the candidate
     * parameter names: "(json_extract(parameters_json,'$.ad_id') IN (?,?,...)
     * OR json_extract(...,'$.creative') IN (...) OR ...)". Shared by
     * rematch()'s zero pass and the agencies report, so the read side and the
     * write side agree on what "this connection's clicks" means.
     *
     * @param array $keys        attributionKeys() output
     * @param array $levelValues level => string[] of platform ids (ad/adset/campaign)
     * @return array{sql:string, params:string[]} sql is '' when no ids to match
     */
    public static function attributionMatchSql(array $keys, array $levelValues, int $chunkSize = 200): array
    {
        $parts = [];
        $params = [];
        foreach ($keys as $levelName => $params_) {
            $values = $levelValues[$levelName] ?? [];
            $values = is_array($values) ? array_values(array_unique(array_filter(array_map('strval', $values)))) : [];
            if (empty($values)) {
                continue;
            }
            foreach ($params_ as $param) {
                $safe = preg_replace('/[^A-Za-z0-9_]/', '', $param);
                // Chunked to stay far below SQLite's parameter ceiling (999 on
                // old builds).
                foreach (array_chunk($values, max(1, $chunkSize)) as $chunk) {
                    $parts[] = "json_extract(parameters_json, '$." . $safe . "') IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")";
                    foreach ($chunk as $value) {
                        $params[] = $value;
                    }
                }
            }
        }
        return ['sql' => implode(' OR ', $parts), 'params' => $params];
    }

    /**
     * SQL condition telling a real platform id from an unreplaced macro
     * (__CID__, {{ad.id}}) on a click's parameter — the same rule
     * diagnoseUnmatched() applies, shared with the reports' attribution
     * banner (api.php action=revenue_attribution_health).
     *
     * $mode: 'real' — the parameter carries a usable id;
     *        'macro' — it carries a macro the platform never expanded.
     *
     * @param string[] $keys  candidate parameter names (attributionKeys() levels)
     * @param string   $prefix table alias including the dot
     */
    public static function attributionValuePredicateSql(array $keys, string $mode, string $prefix = 'clicks.'): string
    {
        $parts = [];
        foreach ($keys as $key) {
            $safe = preg_replace('/[^A-Za-z0-9_]/', '', $key);
            $v = "json_extract({$prefix}parameters_json, '$." . $safe . "')";
            if ($mode === 'real') {
                $parts[] = "({$v} IS NOT NULL AND {$v} != '' AND {$v} NOT LIKE '%\\_\\_%' ESCAPE '\\' AND {$v} NOT LIKE '%{%' AND {$v} NOT LIKE '%}%')";
            } else {
                $parts[] = "({$v} LIKE '%\\_\\_%' ESCAPE '\\' OR {$v} LIKE '%{%' OR {$v} LIKE '%}%')";
            }
        }
        return $parts ? implode(' OR ', $parts) : '0';
    }

    /**
     * Re-attribute stored cost_records to clicks under the connection's current
     * timezone — the repair path behind `aggregator_cron.php --rematch`.
     *
     * Import history matters: every import before the TikTok accountTimezone()
     * fix spread spend over UTC days, so for a UTC+3 cabinet about three hours
     * of nightly clicks carried the neighbouring day's cost. Re-importing fixes
     * new syncs but never the past — an already-matched unchanged row is skipped
     * by design — and re-fetching 30 days from every platform just to move day
     * boundaries spends API quota for data already stored. This method works
     * off cost_records alone, no network:
     *
     *  1. zero the cost on every click inside the window's day ranges (either
     *     reading — UTC or account timezone) carrying this connection's
     *     attribution ids: those clicks and only those got cost from this
     *     connection, and the moved boundary would leave yesterday-evening
     *     clicks with stale cost otherwise;
     *  2. re-run the ad → adset → campaign attribution for each stored row
     *     exactly like import() and rewrite cost_records.is_matched.
     *
     * The processed record window is two days wider than the zeroing window on
     * each side: a click zeroed under one reading always has its owning record
     * under the other reading inside that margin, so nothing is cleared without
     * a record to re-attribute it. Idempotent: a second run lands on the same
     * click→cost map.
     *
     * @return array{rows:int, matched:int, unmatched:int, zeroed_clicks:int, timezone:string}
     */
    public static function rematch(PDO $pdo, int $connectionId, string $dateFrom, string $dateTo): array
    {
        $conn = [];
        try {
            $stmt = $pdo->prepare("SELECT engine, credentials_json, field_mapping_json FROM aggregator_connections WHERE id = ? LIMIT 1");
            $stmt->execute([$connectionId]);
            $conn = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
        }
        $credentials = json_decode((string) ($conn['credentials_json'] ?? ''), true);
        $credentials = is_array($credentials) ? $credentials : [];
        $fieldMapping = json_decode((string) ($conn['field_mapping_json'] ?? ''), true);
        $fieldMapping = is_array($fieldMapping) ? $fieldMapping : [];

        $platformTz = self::resolveTimezone($pdo, $connectionId, $fieldMapping, ['credentials' => $credentials]);
        $excludeSafePage = self::safePageExclusionNeeded($pdo);
        $keys = self::resolveKeys($fieldMapping);
        $zoneKeys = self::resolveZoneKeys($fieldMapping);
        $lookup = self::clickLookupFactory($pdo);
        $zoneLookup = self::zoneLookupFactory($pdo);

        $rowsFrom = gmdate('Y-m-d', strtotime($dateFrom . ' UTC') - 2 * 86400);
        $rowsTo = gmdate('Y-m-d', strtotime($dateTo . ' UTC') + 2 * 86400);
        $rowsStmt = $pdo->prepare("
            SELECT id, amount, COALESCE(fee_amount, 0) AS fee_amount, source_campaign_id, ad_id, adset_id, COALESCE(zone_id, '') AS zone_id, click_date
            FROM cost_records
            WHERE connection_id = ? AND click_date >= ? AND click_date <= ?
        ");
        $rowsStmt->execute([$connectionId, $rowsFrom, $rowsTo]);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $stats = [
            'rows'          => count($rows),
            'matched'       => 0,
            'unmatched'     => 0,
            'zeroed_clicks' => 0,
            'timezone'      => $platformTz ?? 'UTC',
        ];
        if (empty($rows)) {
            return $stats;
        }

        // 1. Zero stale cost. IANA offsets span UTC-14..UTC+14, so any reading
        // of [dateFrom, dateTo] touches clicks created in
        // [dateFrom 00:00 − 14h, dateTo 00:00 + 24h + 14h). Only cost-bearing
        // clicks carrying this connection's platform ids inside that window are
        // touched — no other cost writer matches those ids (manual cost entry
        // keys on campaign+day, never on the platform's ad ids).
        $levelValues = ['ad' => [], 'adset' => [], 'campaign' => []];
        foreach ($rows as $row) {
            foreach (['ad' => 'ad_id', 'adset' => 'adset_id', 'campaign' => 'source_campaign_id'] as $level => $column) {
                $value = trim((string) ($row[$column] ?? ''));
                if ($value !== '') {
                    $levelValues[$level][$value] = true;
                }
            }
        }

        $zeroFrom = gmdate('Y-m-d H:i:s', strtotime($dateFrom . ' 00:00:00 UTC') - 14 * 3600);
        $zeroTo = gmdate('Y-m-d H:i:s', strtotime($dateTo . ' 00:00:00 UTC') + 38 * 3600);

        $zeroedIds = [];
        // levelValues maps value => true for cheap dedup; attributionMatchSql
        // wants plain lists.
        $matchValues = array_map(fn($set) => array_keys($set), $levelValues);
        $match = self::attributionMatchSql($keys, $matchValues);
        if ($match['sql'] !== '') {
            // One indexed created_at range scan; the OR list is the union of
            // every attribution id this connection's spend rows carry.
            $findStale = $pdo->prepare(
                "SELECT id FROM clicks
                 WHERE (cost > 0 OR fee > 0) AND created_at >= ? AND created_at < ?
                   AND (" . $match['sql'] . ")"
            );
            $findStale->execute(array_merge([$zeroFrom, $zeroTo], $match['params']));
            foreach ($findStale->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $zeroedIds[(string) $id] = true;
            }
        }

        $clearCost = $pdo->prepare("UPDATE clicks SET cost = 0, fee = 0 WHERE id = ?");
        foreach (array_keys($zeroedIds) as $id) {
            $clearCost->execute([$id]);
        }
        $stats['zeroed_clicks'] = count($zeroedIds);

        // 2. Re-attribute, exactly like import(): cost carries spend WITH the
        // agency fee stored on the row, fee carries the fee share.
        $setClickCost = $pdo->prepare("UPDATE clicks SET cost = ?, fee = ? WHERE id = ?");
        $setMatched = $pdo->prepare("UPDATE cost_records SET is_matched = ? WHERE id = ?");
        $sharedSets = [];

        foreach ($rows as $row) {
            $amount = (float) $row['amount'];
            $fee = (float) ($row['fee_amount'] ?? 0);
            $clickDate = (string) $row['click_date'];
            $tzModifier = self::offsetModifier($platformTz, $clickDate);

            $resolved = self::resolveClickSet(
                $lookup, $zoneLookup, $keys, $zoneKeys,
                (string) ($row['ad_id'] ?? ''),
                (string) ($row['adset_id'] ?? ''),
                (string) ($row['source_campaign_id'] ?? ''),
                (string) ($row['zone_id'] ?? ''),
                $clickDate,
                $tzModifier,
                $excludeSafePage
            );
            $clickIds = $resolved['ids'];

            if (!empty($clickIds) && $amount > 0 && !empty($resolved['shared'])) {
                self::addSharedCost($sharedSets, $clickIds, $amount + $fee, $fee);
                $setMatched->execute([1, (int) $row['id']]);
                $stats['matched']++;
            } elseif (!empty($clickIds) && $amount > 0) {
                $cpc = ($amount + $fee) / count($clickIds);
                $feePerClick = $fee / count($clickIds);
                foreach ($clickIds as $cid) {
                    $setClickCost->execute([$cpc, $feePerClick, $cid]);
                }
                $setMatched->execute([1, (int) $row['id']]);
                $stats['matched']++;
            } else {
                $setMatched->execute([0, (int) $row['id']]);
                $stats['unmatched']++;
            }
        }
        self::flushSharedCost($sharedSets, $setClickCost);

        return $stats;
    }

    /**
     * Accumulate one zone row's spend onto a shared zone-less fallback set.
     * Keyed by the exact click set, so two rows land together only when they
     * resolved to the very same clicks.
     */
    private static function addSharedCost(array &$sets, array $clickIds, float $amountWithFee, float $fee): void
    {
        $ids = array_map('strval', $clickIds);
        sort($ids);
        $key = md5(implode(',', $ids));
        if (!isset($sets[$key])) {
            $sets[$key] = ['ids' => $ids, 'amount' => 0.0, 'fee' => 0.0];
        }
        $sets[$key]['amount'] += $amountWithFee;
        $sets[$key]['fee'] += $fee;
    }

    /** Assign every shared set's summed spend evenly across its clicks. */
    private static function flushSharedCost(array $sets, \PDOStatement $setClickCost): void
    {
        foreach ($sets as $set) {
            $n = count($set['ids']);
            if ($n === 0) {
                continue;
            }
            $cpc = $set['amount'] / $n;
            $feePerClick = $set['fee'] / $n;
            foreach ($set['ids'] as $cid) {
                $setClickCost->execute([$cpc, $feePerClick, $cid]);
            }
        }
    }

    /**
     * Explain why no spend row found a click. Returns a machine-readable code plus
     * the concrete values behind it, so the UI can show e.g. "TikTok ad 1847… spent
     * on Oct 12, clicks that day carry ad_id=__CID__" rather than guesswork.
     *
     * Codes, checked in order:
     *  - no_spend:   every row the platform returned has zero spend.
     *  - no_clicks:  Orbitra has no clicks at all on any day the platform spent.
     *  - no_ids:     clicks exist that day, but none carries ad/adset/campaign IDs —
     *                the ad link does not reach Orbitra with the platform's macros.
     *  - macros:     the IDs on the clicks are unreplaced macros (__CID__, {{ad.id}}).
     *  - ids_differ: clicks carry real IDs, just not the ones the platform spent on.
     *
     * @return array{code:string}|null
     */
    private static function diagnoseUnmatched(PDO $pdo, array $records, array $keys, ?string $platformTz): ?array
    {
        $spend = array_values(array_filter($records, function ($r) {
            return (float) ($r['amount'] ?? 0) > 0 && (string) ($r['date'] ?? '') !== '';
        }));
        if (empty($spend)) {
            return ['code' => 'no_spend'];
        }
        usort($spend, function ($a, $b) {
            return (float) ($b['amount'] ?? 0) <=> (float) ($a['amount'] ?? 0);
        });

        $dayClicks = [];
        $countDay = function (string $date) use ($pdo, $platformTz, &$dayClicks): int {
            if (!array_key_exists($date, $dayClicks)) {
                $tz = self::offsetModifier($platformTz, $date);
                $expr = $tz === '' ? 'date(created_at)' : "date(created_at, '{$tz}')";
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM clicks WHERE {$expr} = ?");
                $stmt->execute([$date]);
                $dayClicks[$date] = (int) $stmt->fetchColumn();
            }
            return $dayClicks[$date];
        };

        // Largest spend row whose day has any clicks at all; capped so a 30-day
        // window of one-row-per-ad does not turn into hundreds of COUNT(*) scans.
        $target = null;
        $spendDays = [];
        foreach ($spend as $rec) {
            $spendDays[$rec['date']] = true;
        }
        $days = array_keys($spendDays);
        sort($days);
        foreach (array_slice($spend, 0, 60) as $rec) {
            if ($countDay((string) $rec['date']) > 0) {
                $target = $rec;
                break;
            }
        }
        if ($target === null) {
            return [
                'code'  => 'no_clicks',
                'from'  => $days[0],
                'to'    => $days[count($days) - 1],
                'days'  => count($days),
            ];
        }

        $date = (string) $target['date'];
        $tz = self::offsetModifier($platformTz, $date);
        $expr = $tz === '' ? 'date(created_at)' : "date(created_at, '{$tz}')";

        $params = [];
        foreach (['ad', 'adset', 'campaign'] as $level) {
            foreach ($keys[$level] as $param) {
                $params[] = preg_replace('/[^A-Za-z0-9_]/', '', $param);
            }
        }
        $params = array_values(array_unique(array_filter($params)));

        $seen = [];
        foreach ($params as $param) {
            $stmt = $pdo->prepare(
                "SELECT json_extract(parameters_json, '$." . $param . "') AS v, COUNT(*) AS c
                 FROM clicks
                 WHERE {$expr} = ? AND json_extract(parameters_json, '$." . $param . "') IS NOT NULL
                 GROUP BY v ORDER BY c DESC LIMIT 3"
            );
            $stmt->execute([$date]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $seen[] = ['param' => $param, 'value' => (string) $row['v'], 'clicks' => (int) $row['c']];
            }
        }

        $base = [
            'date'        => $date,
            'clicks'      => $countDay($date),
            'amount'      => round((float) ($target['amount'] ?? 0), 2),
            'currency'    => (string) ($target['currency'] ?? ''),
            'ad_id'       => (string) ($target['ad_id'] ?? ''),
            'adset_id'    => (string) ($target['adset_id'] ?? ''),
            'campaign_id' => (string) ($target['source_campaign_id'] ?? ''),
        ];

        if (empty($seen)) {
            return ['code' => 'no_ids'] + $base;
        }

        // A leftover macro in one optional key (e.g. creative=__CREATIVE_ID__, which
        // TikTok never fills) must not mask real IDs in the others: report "macros"
        // only when the clicks carry no real ID at all, and keep macros out of the
        // "ids_differ" sample.
        $isMacro = function (string $v): bool {
            return strpos($v, '__') !== false || strpos($v, '{') !== false || strpos($v, '}') !== false;
        };
        $real = array_values(array_filter($seen, function ($item) use ($isMacro) {
            return !$isMacro($item['value']);
        }));
        if (empty($real)) {
            return ['code' => 'macros', 'param' => $seen[0]['param'], 'value' => $seen[0]['value']] + $base;
        }

        $sample = array_map(function ($item) {
            return $item['param'] . '=' . $item['value'];
        }, array_slice($real, 0, 4));

        return ['code' => 'ids_differ', 'seen' => implode(', ', $sample)] + $base;
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
    public static function resolveTimezone(PDO $pdo, int $connectionId, array $fieldMapping = [], array $options = []): ?string
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
        $engineClasses = [
            'facebook'     => 'FacebookAdsEngine',
            'facebook_ads' => 'FacebookAdsEngine',
            'tiktok'       => 'TikTokAdsEngine',
        ];
        if (isset($engineClasses[$engine])) {
            $engineClass = $engineClasses[$engine];
            $enginePath = __DIR__ . '/../aggregator_engines/' . $engineClass . '.php';
            if (is_file($enginePath)) {
                require_once $enginePath;
                if (method_exists($engineClass, 'accountTimezone')) {
                    try {
                        $live = $engineClass::accountTimezone($credentials);
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
     * Per-row SQL for "this click counts", resolved from each campaign's own
     * exclude_safe_from_reports flag.
     *
     * This used to be a hand-copied mirror of the api.php original, kept in
     * step by hand because the cron never loads api.php. The original now lives
     * in core/ReportMetrics.php, which this file requires, so there is one
     * implementation and nothing left to drift.
     *
     * @param string $prefix Table alias including the dot, or '' for a bare
     *                       "FROM clicks".
     */
    private static function safePagePredicate(string $prefix = ''): string
    {
        return orbitraSafePagePredicate($prefix);
    }

    /**
     * Does ANY campaign run a cloak stream at all? Only a gate: when nothing in
     * the account cloaks there is no point making the extra money-side lookup
     * pass. Which clicks actually count is decided per campaign, in SQL, by
     * safePagePredicate() - never by this boolean.
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
                // json_extract's path is interpolated, not bound: an override
                // joins the candidate list only as a plain identifier, anything
                // else degrades to the defaults below instead of reaching SQL.
                $name = trim($param);
                if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                    continue;
                }
                array_unshift($keys[$level], $name);
                $keys[$level] = array_values(array_unique($keys[$level]));
            }
        }

        return $keys;
    }

    /**
     * Zone keys with a per-connection override merged in (zone_id_param), same
     * degradation rule as resolveKeys(): a malformed override never reaches
     * SQL, the defaults stay as fallback.
     */
    private static function resolveZoneKeys(array $fieldMapping): array
    {
        $zoneKeys = self::ZONE_KEYS;
        $override = trim((string) ($fieldMapping['zone_id_param'] ?? ''));
        if ($override !== '' && preg_match('/^[A-Za-z0-9_]+$/', $override)) {
            array_unshift($zoneKeys, $override);
            $zoneKeys = array_values(array_unique($zoneKeys));
        }
        return $zoneKeys;
    }

    private static function annotateRaw($rawJson, float $sourceAmount, string $sourceCurrency, float $amount, string $trackerCurrency, float $fee = 0.0, float $feePercent = 0.0): ?string
    {
        $decoded = is_string($rawJson) ? json_decode($rawJson, true) : null;
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $orbitra = [
            'source_amount'   => $sourceAmount,
            'source_currency' => $sourceCurrency,
            'stored_amount'   => $amount,
            'stored_currency' => $trackerCurrency,
            'imported_at'     => gmdate('c'),
        ];
        if ($fee > 0 || $feePercent > 0) {
            $orbitra['fee_amount'] = $fee;
            $orbitra['fee_percent'] = $feePercent;
        }
        $decoded['_orbitra'] = $orbitra;

        return json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Engine key of aggregator_connections → ad_entities.platform value.
     * '' means the connection is not an ad platform this module knows (revenue
     * networks, generic APIs) and reports keep bare ids for its ids.
     */
    private static function enginePlatform(string $engine): string
    {
        $engine = strtolower(trim($engine));
        if ($engine === 'facebook' || $engine === 'facebook_ads') {
            return 'facebook';
        }
        if ($engine === 'tiktok' || $engine === 'google_ads') {
            return $engine;
        }
        // Push/pop networks: ad_entities keeps campaign names for these the
        // same way (level 'campaign'); zone names are not an entity level.
        if (in_array($engine, ['admaven', 'hilltopads', 'propellerads', 'kadam', 'galaxion', 'adsterra', 'clickadu'], true)) {
            return $engine;
        }
        return '';
    }

    /**
     * The agency commission percent of a cost connection (0 without an agency).
     * Applied at import time only — history keeps the fee it was imported with,
     * so a percent change never rewrites past months (migration 61 notes).
     */
    private static function connectionFeePercent(PDO $pdo, int $connectionId): float
    {
        try {
            $stmt = $pdo->prepare("
                SELECT a.fee_percent FROM aggregator_connections c
                JOIN agencies a ON a.id = c.agency_id
                WHERE c.id = ? AND a.fee_model = 'percent'
            ");
            $stmt->execute([$connectionId]);
            $percent = $stmt->fetchColumn();
            return $percent === false ? 0.0 : max(0.0, min(1000.0, (float) $percent));
        } catch (\Throwable $e) {
            // No agencies table yet (pre-migration DB or a unit-test schema).
            return 0.0;
        }
    }
}
