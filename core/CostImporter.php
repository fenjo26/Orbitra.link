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

class CostImporter
{
    /**
     * Ingest cost records for one aggregator connection.
     *
     * @param PDO   $pdo
     * @param int   $connectionId
     * @param array $records  Rows from an engine's fetchRecords():
     *                        {external_id, amount, currency, source_campaign_id,
     *                         ad_id, adset_id, date, raw_json}
     * @return array {fetched, new, updated, matched, unmatched}
     */
    public static function import(PDO $pdo, int $connectionId, array $records): array
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

        // Attribution keys. Facebook templates store ad_id / campaign_id; Google Ads
        // ValueTrack uses {campaignid} / {creative}, captured under those names.
        $findClicksByAd = $pdo->prepare("SELECT id FROM clicks WHERE json_extract(parameters_json, '$.ad_id') = ? AND date(created_at) = ?");
        $findClicksByCampaign = $pdo->prepare("SELECT id FROM clicks WHERE json_extract(parameters_json, '$.campaign_id') = ? AND date(created_at) = ?");
        $findClicksByCampaignGoogle = $pdo->prepare("SELECT id FROM clicks WHERE json_extract(parameters_json, '$.campaignid') = ? AND date(created_at) = ?");
        $setClickCost = $pdo->prepare("UPDATE clicks SET cost = ? WHERE id = ?");

        $stats = ['fetched' => count($records), 'new' => 0, 'updated' => 0, 'matched' => 0, 'unmatched' => 0];

        foreach ($records as $rec) {
            $externalId = $rec['external_id'] ?? null;
            $amount     = (float) ($rec['amount'] ?? 0);
            $adId       = (string) ($rec['ad_id'] ?? '');
            $campaignId = (string) ($rec['source_campaign_id'] ?? '');
            $adsetId    = (string) ($rec['adset_id'] ?? '');
            $currency   = (string) ($rec['currency'] ?? 'USD');
            $clickDate  = (string) ($rec['date'] ?? date('Y-m-d'));
            $rawJson    = $rec['raw_json'] ?? null;

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

            // Resolve the click set this spend belongs to. Prefer ad-level granularity,
            // fall back to campaign level.
            $clickIds = [];
            if ($adId !== '') {
                $findClicksByAd->execute([$adId, $clickDate]);
                $clickIds = $findClicksByAd->fetchAll(PDO::FETCH_COLUMN);
            }
            if (empty($clickIds) && $campaignId !== '') {
                $findClicksByCampaign->execute([$campaignId, $clickDate]);
                $clickIds = $findClicksByCampaign->fetchAll(PDO::FETCH_COLUMN);
                if (empty($clickIds)) {
                    $findClicksByCampaignGoogle->execute([$campaignId, $clickDate]);
                    $clickIds = $findClicksByCampaignGoogle->fetchAll(PDO::FETCH_COLUMN);
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
                    $amount, $currency, $campaignId, $adId, $adsetId,
                    $clickDate, $rawJson, $isMatched, $existingId,
                ]);
                $stats['updated']++;
            } else {
                $insertCost->execute([
                    $connectionId, $externalId, $campaignId, $adId, $adsetId,
                    $amount, $currency, $clickDate, $rawJson, $isMatched,
                ]);
                $stats['new']++;
            }
        }

        return $stats;
    }
}
