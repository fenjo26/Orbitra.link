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
     *                            actually arrives in.
     * @return array {fetched, new, updated, matched, unmatched, currency, converted}
     */
    public static function import(PDO $pdo, int $connectionId, array $records, array $fieldMapping = []): array
    {
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
        $lookup = function (string $param) use ($pdo, &$lookupCache) {
            if (!isset($lookupCache[$param])) {
                // json_extract's path is not a bindable parameter, so the name is
                // stripped of anything that could break out of the path literal.
                $safe = preg_replace('/[^A-Za-z0-9_]/', '', $param);
                $lookupCache[$param] = $pdo->prepare(
                    "SELECT id FROM clicks
                     WHERE json_extract(parameters_json, '$." . $safe . "') = ?
                       AND date(created_at) = ?"
                );
            }
            return $lookupCache[$param];
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
            $clickIds = [];
            foreach ([['ad', $adId], ['adset', $adsetId], ['campaign', $campaignId]] as $level) {
                [$levelName, $value] = $level;
                if ($value === '' || !empty($clickIds)) {
                    continue;
                }
                foreach ($keys[$levelName] as $param) {
                    $stmt = $lookup($param);
                    $stmt->execute([$value, $clickDate]);
                    $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($found)) {
                        $clickIds = $found;
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
