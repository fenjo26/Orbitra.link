<?php
// core/ExtensionAdsStats.php
//
// Small, read-only reporting surface for the Ads Manager browser extension.
// It deliberately reuses ReportMetrics.php for conversion status groups and
// derived metrics so CPA/CPL/CPS/ROI agree with the Orbitra dashboard.

require_once __DIR__ . '/ReportMetrics.php';

if (!function_exists('orbitraExtensionAdsStats')) {
    /**
     * Normalize a comma-separated ID filter (or an array) for a bound SQL IN.
     * Meta IDs are decimal strings; keeping them as strings avoids integer
     * truncation on 32-bit PHP builds.
     *
     * @return string[]
     */
    function orbitraExtensionAdsNormalizeIds($value, int $limit = 500): array
    {
        $parts = is_array($value) ? $value : explode(',', (string) $value);
        $ids = [];
        foreach ($parts as $part) {
            $id = trim((string) $part);
            if ($id === '' || !preg_match('/^\d{1,32}$/', $id)) {
                continue;
            }
            // Prefix the map key: PHP silently casts decimal-looking array keys
            // to integers, which truncates large Meta IDs on 32-bit builds.
            $ids['id:' . $id] = $id;
            if (count($ids) >= $limit) {
                break;
            }
        }
        return array_values($ids);
    }

    /** Resolve `today` or a concrete date in the request timezone. */
    function orbitraExtensionAdsResolveDate($value): ?string
    {
        $value = trim((string) ($value ?? 'today'));
        if ($value === '' || strtolower($value) === 'today') {
            return date('Y-m-d');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * Aggregate one Meta hierarchy level from captured click parameters.
     *
     * @return array<string,array<string,int|float|null>>
     */
    function orbitraExtensionAdsAggregateLevel(
        PDO $pdo,
        string $level,
        string $date,
        array $filterIds,
        string $dbTzOffset,
        ?string $conversionValueColumn
    ): array {
        $expressions = [
            'campaign' => "COALESCE(json_extract(cl.parameters_json, '$.campaign_id'), json_extract(cl.parameters_json, '$.campaign'), json_extract(cl.parameters_json, '$.campaignid'), json_extract(cl.parameters_json, '$.ad_campaign_id'))",
            'adset' => "COALESCE(json_extract(cl.parameters_json, '$.adset_id'), json_extract(cl.parameters_json, '$.adgroup_id'), json_extract(cl.parameters_json, '$.adgroup'), json_extract(cl.parameters_json, '$.adgroupid'), json_extract(cl.parameters_json, '$.ad_group_id'))",
            'ad' => "COALESCE(json_extract(cl.parameters_json, '$.ad_id'), json_extract(cl.parameters_json, '$.creative_id'), json_extract(cl.parameters_json, '$.creative'))",
        ];
        if (!isset($expressions[$level])) {
            throw new InvalidArgumentException('Unsupported extension stats level');
        }

        $entityExpr = $expressions[$level];
        $conditions = [
            "date(cl.created_at, '$dbTzOffset') = date(?)",
            "$entityExpr IS NOT NULL",
            "CAST($entityExpr AS TEXT) != ''",
        ];
        $params = [$date];
        if ($filterIds) {
            $conditions[] = 'CAST(' . $entityExpr . ' AS TEXT) IN (' . implode(',', array_fill(0, count($filterIds), '?')) . ')';
            array_push($params, ...$filterIds);
        }

        // Case-insensitive group matching, shared with the reports (ReportMetrics).
        $saleMatch = orbitraConversionStatusMatchSql('cv.status', 'sale');
        $leadMatch = orbitraConversionStatusMatchSql('cv.status', 'hold');
        $valueExpr = $conversionValueColumn !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $conversionValueColumn)
            ? 'cv.' . $conversionValueColumn
            : '0';

        // First isolate today's requested clicks, then aggregate conversions per
        // click before rolling up the entity. This prevents a click with several
        // events from duplicating its spend and avoids grouping the entire
        // conversions table on every 15/30-second extension poll.
        $stmt = $pdo->prepare("
            WITH relevant_clicks AS (
                SELECT
                    cl.id AS click_id,
                    cl.cost AS click_cost,
                    CAST($entityExpr AS TEXT) AS entity_id
                FROM clicks cl
                WHERE " . implode(' AND ', $conditions) . "
            ), per_click AS (
                SELECT
                    rc.click_id,
                    rc.click_cost,
                    rc.entity_id,
                    COUNT(cv.id) AS conversions,
                    SUM(CASE WHEN $leadMatch THEN 1 ELSE 0 END) AS leads,
                    SUM(CASE WHEN $saleMatch THEN 1 ELSE 0 END) AS sales,
                    COALESCE(SUM($valueExpr), 0) AS revenue,
                    COALESCE(SUM(CASE WHEN $saleMatch THEN $valueExpr ELSE 0 END), 0) AS revenue_confirmed
                FROM relevant_clicks rc
                LEFT JOIN conversions cv ON cv.click_id = rc.click_id
                GROUP BY rc.click_id, rc.click_cost, rc.entity_id
            )
            SELECT
                entity_id,
                COUNT(click_id) AS clicks,
                COALESCE(SUM(click_cost), 0) AS cost,
                COALESCE(SUM(conversions), 0) AS conversions,
                COALESCE(SUM(leads), 0) AS leads,
                COALESCE(SUM(sales), 0) AS sales,
                COALESCE(SUM(revenue), 0) AS revenue,
                COALESCE(SUM(revenue_confirmed), 0) AS revenue_confirmed
            FROM per_click
            GROUP BY entity_id
        ");
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $raw) {
            $metrics = orbitraComputeDerivedMetrics($raw);
            $out[(string) $raw['entity_id']] = [
                'clicks' => (int) $metrics['clicks'],
                'cost' => (float) $metrics['cost'],
                'revenue' => (float) $metrics['revenue'],
                'revenue_confirmed' => (float) $metrics['revenue_confirmed'],
                'profit' => (float) $metrics['profit'],
                'profit_confirmed' => (float) $metrics['profit_confirmed'],
                'roi' => $metrics['roi'] === null ? null : (float) $metrics['roi'],
                'roi_confirmed' => $metrics['roi_confirmed'] === null ? null : (float) $metrics['roi_confirmed'],
                'cpa' => (float) $metrics['cpa'],
                'cpl' => (float) $metrics['cpl'],
                'cps' => (float) $metrics['cps'],
                'conversions' => (int) $metrics['conversions'],
                'leads' => (int) $metrics['leads'],
                'sales' => (int) $metrics['sales'],
            ];
        }
        return $out;
    }

    /**
     * Build all three hierarchy maps for the extension response.
     *
     * @param array{campaign_ids?:mixed,adset_ids?:mixed,ad_ids?:mixed} $filters
     */
    function orbitraExtensionAdsStats(
        PDO $pdo,
        string $date,
        array $filters,
        string $dbTzOffset,
        ?string $conversionValueColumn
    ): array {
        return [
            'campaigns' => orbitraExtensionAdsAggregateLevel(
                $pdo,
                'campaign',
                $date,
                orbitraExtensionAdsNormalizeIds($filters['campaign_ids'] ?? ''),
                $dbTzOffset,
                $conversionValueColumn
            ),
            'adsets' => orbitraExtensionAdsAggregateLevel(
                $pdo,
                'adset',
                $date,
                orbitraExtensionAdsNormalizeIds($filters['adset_ids'] ?? ''),
                $dbTzOffset,
                $conversionValueColumn
            ),
            'ads' => orbitraExtensionAdsAggregateLevel(
                $pdo,
                'ad',
                $date,
                orbitraExtensionAdsNormalizeIds($filters['ad_ids'] ?? ''),
                $dbTzOffset,
                $conversionValueColumn
            ),
        ];
    }
}
