<?php
// core/ExtensionStats.php
//
// Deep stats for the Ads Manager overlay extension: one call fuses the
// attributed Facebook spend (clicks.cost, written by the cost importer) with
// verified tracker revenue per ad / adset / FB campaign, plus the daily
// history, the landing/offer breakdown and the CAPI delivery check.
//
// Lives in core/ (not inline in api.php) so the fixture test can exercise it
// against a throwaway SQLite database the same way facebook_integration_test
// does. The api.php case owns authentication; this code only reads.

require_once __DIR__ . '/ReportMetrics.php';

final class ExtensionStats
{
    /**
     * Click-parameter key that carries the network id, by entity type. These
     * are the keys the Facebook traffic-source template writes into
     * parameters_json (and the cost importer distributes spend by).
     */
    private const PARAM_BY_TYPE = [
        'ad'      => 'ad_id',
        'adset'   => 'adset_id',
        'campaign' => 'campaign_id',
    ];

    /**
     * @param string $valueColumn conversions payout column ('payout'|'revenue'|'amount'|null)
     * @param string $dateFrom    YYYY-MM-DD, UTC day bounds
     * @param string $dateTo      YYYY-MM-DD
     * @param array  $entities    [['type' => 'ad'|'adset'|'campaign', 'id' => '123'], ...]
     */
    public static function deepStats(PDO $pdo, ?string $valueColumn, string $dateFrom, string $dateTo, array $entities): array
    {
        $from = $dateFrom . ' 00:00:00';
        $to = $dateTo . ' 23:59:59';
        $convAgg = orbitraConversionAggregateSql($valueColumn);

        $out = ['totals' => self::emptyTotals(), 'entities' => []];
        $seen = [];

        foreach ($entities as $entity) {
            $type = (string) ($entity['type'] ?? '');
            $id = trim((string) ($entity['id'] ?? ''));
            if (!isset(self::PARAM_BY_TYPE[$type]) || !ctype_digit($id)) {
                continue;
            }
            $key = $type . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $row = self::entityTotals($pdo, $convAgg, self::PARAM_BY_TYPE[$type], $id, $from, $to);
            if ($row === null) {
                continue;
            }

            $metrics = self::decorate($row);
            $metrics['id'] = $id;
            $metrics['type'] = $type;
            $metrics['daily_history'] = self::dailyHistory($pdo, $convAgg, self::PARAM_BY_TYPE[$type], $id, $from, $to);
            $metrics['landings'] = self::landingBreakdown($pdo, $convAgg, self::PARAM_BY_TYPE[$type], $id, $from, $to);
            $metrics['offers'] = self::offerBreakdown($pdo, $convAgg, self::PARAM_BY_TYPE[$type], $id, $from, $to);
            $metrics['pixel_accuracy'] = self::pixelAccuracy($pdo, self::PARAM_BY_TYPE[$type], $id, $from, $to);

            $out['entities'][$id] = $metrics;

            foreach (['spend', 'revenue', 'revenue_confirmed', 'sales', 'clicks', 'unique_clicks', 'conversions'] as $k) {
                $out['totals'][$k] += $metrics[$k];
            }
        }

        $out['totals'] = self::decorate($out['totals']);
        return $out;
    }

    private static function emptyTotals(): array
    {
        return [
            'clicks' => 0, 'unique_clicks' => 0, 'spend' => 0.0,
            'conversions' => 0, 'sales' => 0,
            'revenue' => 0.0, 'revenue_confirmed' => 0.0,
            'prelander_clicks' => 0, 'offer_clicks' => 0, 'lp_clicks' => 0,
        ];
    }

    /** @return array|null null when the entity never received a click */
    private static function entityTotals(PDO $pdo, string $convAgg, string $param, string $id, string $from, string $to): ?array
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(clicks.id) AS clicks,
                   COALESCE(SUM(clicks.uniq_campaign), 0) AS unique_clicks,
                   COALESCE(SUM(clicks.cost), 0) AS spend,
                   COALESCE(SUM(cv.cnt_any), 0) AS conversions,
                   COALESCE(SUM(cv.cnt_sale), 0) AS sales,
                   COALESCE(SUM(cv.rev_all), 0) AS revenue,
                   COALESCE(SUM(cv.rev_sale), 0) AS revenue_confirmed,
                   COALESCE(SUM(CASE WHEN clicks.landing_id > 0 THEN 1 ELSE 0 END), 0) AS prelander_clicks,
                   COALESCE(SUM(CASE WHEN clicks.offer_id > 0 THEN 1 ELSE 0 END), 0) AS offer_clicks,
                   COALESCE(SUM(CASE WHEN clicks.landing_id > 0 AND clicks.offer_id > 0 THEN 1 ELSE 0 END), 0) AS lp_clicks
            FROM clicks
            LEFT JOIN $convAgg cv ON cv.click_id = clicks.id
            WHERE json_extract(clicks.parameters_json, '\$.{$param}') = :id
              AND clicks.created_at >= :from AND clicks.created_at <= :to
        ");
        $stmt->execute([':id' => $id, ':from' => $from, ':to' => $to]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['clicks'] === 0) {
            return null;
        }
        return array_map(static fn($v) => is_string($v) && is_numeric($v) ? (float) $v : $v, $row);
    }

    private static function dailyHistory(PDO $pdo, string $convAgg, string $param, string $id, string $from, string $to): array
    {
        $stmt = $pdo->prepare("
            SELECT date(clicks.created_at) AS date,
                   COUNT(clicks.id) AS clicks,
                   COALESCE(SUM(clicks.cost), 0) AS spend,
                   COALESCE(SUM(cv.rev_all), 0) AS revenue,
                   COALESCE(SUM(cv.rev_sale), 0) AS revenue_confirmed,
                   COALESCE(SUM(cv.cnt_sale), 0) AS sales
            FROM clicks
            LEFT JOIN $convAgg cv ON cv.click_id = clicks.id
            WHERE json_extract(clicks.parameters_json, '\$.{$param}') = :id
              AND clicks.created_at >= :from AND clicks.created_at <= :to
            GROUP BY date(clicks.created_at)
            ORDER BY date DESC
        ");
        $stmt->execute([':id' => $id, ':from' => $from, ':to' => $to]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['spend'] = (float) $r['spend'];
            $r['revenue'] = (float) $r['revenue'];
            $r['revenue_confirmed'] = (float) $r['revenue_confirmed'];
            $r['profit'] = $r['revenue'] - $r['spend'];
            $r['roi'] = $r['spend'] > 0 ? ($r['profit'] / $r['spend']) * 100 : 0.0;
            $rows[] = $r;
        }
        return $rows;
    }

    private static function landingBreakdown(PDO $pdo, string $convAgg, string $param, string $id, string $from, string $to): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT l.id, l.name,
                       COUNT(clicks.id) AS clicks,
                       COALESCE(SUM(CASE WHEN clicks.landing_id > 0 AND clicks.offer_id > 0 THEN 1 ELSE 0 END), 0) AS lp_clicks,
                       COALESCE(SUM(clicks.cost), 0) AS spend,
                       COALESCE(SUM(cv.rev_all), 0) AS revenue
                FROM clicks
                LEFT JOIN landings l ON l.id = clicks.landing_id
                LEFT JOIN $convAgg cv ON cv.click_id = clicks.id
                WHERE json_extract(clicks.parameters_json, '\$.{$param}') = :id
                  AND clicks.landing_id > 0
                  AND clicks.created_at >= :from AND clicks.created_at <= :to
                GROUP BY clicks.landing_id
                ORDER BY clicks DESC
            ");
            $stmt->execute([':id' => $id, ':from' => $from, ':to' => $to]);
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['lp_ctr'] = $r['clicks'] > 0 ? ($r['lp_clicks'] / $r['clicks']) * 100 : 0.0;
                $r['profit'] = (float) $r['revenue'] - (float) $r['spend'];
                $rows[] = $r;
            }
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function offerBreakdown(PDO $pdo, string $convAgg, string $param, string $id, string $from, string $to): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT o.id, o.name,
                       COUNT(clicks.id) AS clicks,
                       COALESCE(SUM(cv.cnt_any), 0) AS conversions,
                       COALESCE(SUM(clicks.cost), 0) AS spend,
                       COALESCE(SUM(cv.rev_all), 0) AS revenue
                FROM clicks
                LEFT JOIN offers o ON o.id = clicks.offer_id
                LEFT JOIN $convAgg cv ON cv.click_id = clicks.id
                WHERE json_extract(clicks.parameters_json, '\$.{$param}') = :id
                  AND clicks.offer_id > 0
                  AND clicks.created_at >= :from AND clicks.created_at <= :to
                GROUP BY clicks.offer_id
                ORDER BY clicks DESC
            ");
            $stmt->execute([':id' => $id, ':from' => $from, ':to' => $to]);
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['cr'] = $r['clicks'] > 0 ? ($r['conversions'] / $r['clicks']) * 100 : 0.0;
                $r['profit'] = (float) $r['revenue'] - (float) $r['spend'];
                $rows[] = $r;
            }
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Conversions the tracker recorded vs CAPI events Meta actually received
     * (delivered rows in the s2s queue for this entity's clicks). A gap means
     * the pixel/CAPI side is losing events — or no pixel is connected at all,
     * which shows as fb_reported = 0.
     */
    private static function pixelAccuracy(PDO $pdo, string $param, string $id, string $from, string $to): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT spl.id)
                FROM s2s_postbacks_log spl
                JOIN conversions cv ON cv.id = spl.conversion_id
                WHERE spl.url LIKE '%graph.facebook%'
                  AND spl.status = 'delivered'
                  AND cv.click_id IN (
                      SELECT clicks.id FROM clicks
                      WHERE json_extract(clicks.parameters_json, '\$.{$param}') = :id
                        AND clicks.created_at >= :from AND clicks.created_at <= :to
                  )
            ");
            $stmt->execute([':id' => $id, ':from' => $from, ':to' => $to]);
            $delivered = (int) $stmt->fetchColumn();

            $stmtLeads = $pdo->prepare("
                SELECT COALESCE(SUM(cv.cnt_any), 0)
                FROM clicks
                LEFT JOIN " . orbitraConversionAggregateSql(null) . " cv ON cv.click_id = clicks.id
                WHERE json_extract(clicks.parameters_json, '\$.{$param}') = :id
                  AND clicks.created_at >= :from AND clicks.created_at <= :to
            ");
            $stmtLeads->execute([':id' => $id, ':from' => $from, ':to' => $to]);
            $tracker = (int) $stmtLeads->fetchColumn();

            return [
                'tracker_leads' => $tracker,
                'fb_reported' => $delivered,
                'accuracy_pct' => $tracker > 0 ? round(($delivered / $tracker) * 100, 1) : 0.0,
            ];
        } catch (\Throwable $e) {
            return ['tracker_leads' => 0, 'fb_reported' => 0, 'accuracy_pct' => 0.0];
        }
    }

    /** Adds the derived unit economics to a raw aggregate row, in place. */
    private static function decorate(array $r): array
    {
        $r['profit'] = (float) $r['revenue'] - (float) $r['spend'];
        $r['roi'] = $r['spend'] > 0 ? ($r['profit'] / $r['spend']) * 100 : 0.0;
        $r['cpa'] = $r['sales'] > 0 ? $r['spend'] / $r['sales'] : 0.0;
        $r['cpl'] = $r['conversions'] > 0 ? $r['spend'] / $r['conversions'] : 0.0;
        $r['cps'] = $r['sales'] > 0 ? $r['spend'] / $r['sales'] : 0.0;
        $r['cpc'] = $r['clicks'] > 0 ? $r['spend'] / $r['clicks'] : 0.0;
        $r['epc'] = $r['clicks'] > 0 ? $r['revenue'] / $r['clicks'] : 0.0;
        $r['cr'] = $r['clicks'] > 0 ? ($r['conversions'] / $r['clicks']) * 100 : 0.0;
        $r['lp_ctr'] = $r['prelander_clicks'] > 0 ? ($r['lp_clicks'] / $r['prelander_clicks']) * 100 : 0.0;
        return $r;
    }
}
