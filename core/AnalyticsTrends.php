<?php
// core/AnalyticsTrends.php
//
// Shared time-series query engine for Analytics → Trends (action=trends)
// and Analytics → Compare (action=analytics_compare).
//
// Keeps one source of truth for:
//   - report-local day/hour bounds ($dbTzOffset),
//   - Safe Page exclusion (orbitraSafePagePredicate),
//   - campaign RBAC scope (orbitraCampaignScopeInSql),
//   - uniqueness mode (uniq_global vs uniq_campaign),
//   - zero-filling linear day/hour axes,
//   - derived ratios (CR, LP CTR, ROI, Real ROI, EPC, CPA),
//   - optional pacing totals (cutoff at current local hour on the final day
//     so "Today vs Yesterday" compares equal elapsed hours).

if (!function_exists('orbitraAnalyticsRunTrendQuery')) {
    /**
     * Resolve media_assets IDs (creative_asset_id filter) to ad_ref values
     * from creative_usages. Returns null if the table does not exist.
     *
     * @param int[] $assetIds
     * @return string[]
     */
    function orbitraAnalyticsCreativeAdRefs(PDO $pdo, array $assetIds): array
    {
        $assetIds = array_values(array_unique(array_filter(array_map('intval', $assetIds), fn($v) => $v > 0)));
        if (empty($assetIds)) {
            return [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
            $stmt = $pdo->prepare("
                SELECT DISTINCT ad_id
                FROM creative_usages
                WHERE asset_id IN ($placeholders)
                  AND ad_id IS NOT NULL
                  AND ad_id != ''
            ");
            $stmt->execute($assetIds);
            $refs = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return array_values(array_unique(array_filter(array_map('strval', $refs), fn($s) => $s !== '')));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Run a single time-series query over raw clicks + conversions + revenue_records.
     *
     * @param array{
     *   group_by?: string,
     *   date_from: string,
     *   date_to: string,
     *   metrics?: string[],
     *   filters?: array<int, array<string, mixed>>,
     *   db_tz_offset: string,
     *   pacing_cutoff_hour?: int|null,
     *   build_legacy_chart?: bool
     * } $opts
     * @return array<string, mixed>
     */
    function orbitraAnalyticsRunTrendQuery(PDO $pdo, array $opts): array
    {
        $groupBy = (string) ($opts['group_by'] ?? 'day');
        if (!in_array($groupBy, ['month', 'day_of_week', 'day', 'hour'], true)) {
            $groupBy = 'day';
        }
        $dateFrom = substr((string) ($opts['date_from'] ?? date('Y-m-d', strtotime('-7 days'))), 0, 10);
        $dateTo   = substr((string) ($opts['date_to'] ?? date('Y-m-d')), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $dateFrom = date('Y-m-d', strtotime('-7 days'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $dateTo = date('Y-m-d');
        }
        if (strcmp($dateFrom, $dateTo) > 0) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $selectedMetrics = is_array($opts['metrics'] ?? null)
            ? $opts['metrics']
            : ['clicks', 'conversions', 'revenue'];
        $filters = is_array($opts['filters'] ?? null) ? $opts['filters'] : [];
        $dbTzOffset = (string) ($opts['db_tz_offset'] ?? '+0 seconds');
        $pacingCutoffHour = isset($opts['pacing_cutoff_hour']) && $opts['pacing_cutoff_hour'] !== null && $opts['pacing_cutoff_hour'] !== ''
            ? (int) $opts['pacing_cutoff_hour']
            : null;
        if ($pacingCutoffHour !== null && ($pacingCutoffHour < 0 || $pacingCutoffHour > 23)) {
            $pacingCutoffHour = null;
        }
        $buildLegacyChart = !empty($opts['build_legacy_chart']);

        // Uniqueness like the dashboard cards: totals across campaigns
        // count uniq_global; a campaign filter scopes it to uniq_campaign.
        $trendUniqCol = 'uniq_global';
        foreach ($filters as $f) {
            if (($f['field'] ?? '') === 'campaign_id') {
                $trendUniqCol = 'uniq_campaign';
                break;
            }
        }

        // Whitelisted filter fields (injection-safe). Maps user keys to qualified columns.
        $allowedTrendFields = [
            'country_code' => 'cl.country_code',
            'device_type'  => 'cl.device_type',
            'campaign_id'  => 'cl.campaign_id',
            'offer_id'     => 'cl.offer_id',
            'landing_id'   => 'cl.landing_id',
            'source_id'    => 'cl.source_id',
            'ip'           => 'cl.ip',
            'browser'      => 'cl.browser',
            'os'           => 'cl.os',
            'ad_ref'       => 'cl.ad_ref',
            // Keitaro's "source" (site / placement): the traffic-source
            // templates write the site or zone macro into it.
            'source'       => "json_extract(cl.parameters_json, '\$.source')",
        ];

        // date_from / date_to are report-local days. Translate them to UTC bounds.
        [$trendFromUtc] = orbitraLocalDayBoundsUtc($dateFrom, $dbTzOffset);
        [, $trendToUtc] = orbitraLocalDayBoundsUtc($dateTo, $dbTzOffset);

        // Build WHERE clause from filters (shared with optional pacing query).
        $entityClauses = [];
        $entityParams = [];

        $trendScopeIn = orbitraCampaignScopeInSql(orbitraCampaignScope($pdo), 'cl.campaign_id');
        if ($trendScopeIn !== '') {
            $entityClauses[] = $trendScopeIn;
        }
        // Same per-campaign "exclude Safe Page clicks from reports" rule as
        // the dashboard and every other report surface.
        $entityClauses[] = orbitraSafePagePredicate('cl.');

        foreach ($filters as $f) {
            $field    = (string) ($f['field'] ?? '');
            $operator = (string) ($f['operator'] ?? 'contains');
            $value    = $f['value'] ?? '';

            if ($field === '' || $value === '' || $value === null) {
                continue;
            }

            // Virtual filter: creative_asset_id maps media_assets.id -> creative_usages.ad_id -> clicks.ad_ref.
            if ($field === 'creative_asset_id') {
                $ids = is_array($value)
                    ? array_map('intval', $value)
                    : array_map('intval', explode(',', (string) $value));
                $adRefs = orbitraAnalyticsCreativeAdRefs($pdo, $ids);
                if (empty($adRefs)) {
                    $entityClauses[] = '1 = 0';
                } else {
                    $placeholders = implode(',', array_fill(0, count($adRefs), '?'));
                    $entityClauses[] = "cl.ad_ref IN ($placeholders)";
                    array_push($entityParams, ...$adRefs);
                }
                continue;
            }

            if (!isset($allowedTrendFields[$field])) {
                continue;
            }

            $col = $allowedTrendFields[$field];
            $valStr = is_array($value) ? implode(',', $value) : (string) $value;

            switch ($operator) {
                case 'in':
                    if (in_array($field, ['campaign_id', 'offer_id', 'landing_id', 'source_id'], true)) {
                        $ids = array_values(array_filter(array_map('intval', explode(',', $valStr))));
                        if ($ids) {
                            $placeholders = implode(',', array_fill(0, count($ids), '?'));
                            $entityClauses[] = "$col IN ($placeholders)";
                            array_push($entityParams, ...$ids);
                        }
                    } else {
                        $vals = array_values(array_filter(array_map('trim', explode(',', $valStr)), fn($s) => $s !== ''));
                        if ($vals) {
                            $placeholders = implode(',', array_fill(0, count($vals), '?'));
                            $entityClauses[] = "$col IN ($placeholders)";
                            array_push($entityParams, ...$vals);
                        }
                    }
                    break;
                case 'contains':
                    $entityClauses[] = "$col LIKE ?";
                    $entityParams[] = "%$valStr%";
                    break;
                case 'not_contains':
                    $entityClauses[] = "$col NOT LIKE ?";
                    $entityParams[] = "%$valStr%";
                    break;
                case 'equals':
                    $entityClauses[] = "$col = ?";
                    $entityParams[] = $valStr;
                    break;
                case 'not_equals':
                    $entityClauses[] = "$col != ?";
                    $entityParams[] = $valStr;
                    break;
                case 'starts_with':
                    $entityClauses[] = "$col LIKE ?";
                    $entityParams[] = "$valStr%";
                    break;
                case 'ends_with':
                    $entityClauses[] = "$col LIKE ?";
                    $entityParams[] = "%$valStr";
                    break;
                case 'regexp':
                case 'regexp_exclude':
                    $re = orbitraNormalizeFilterRegex($valStr);
                    if ($re === null) {
                        return [
                            'ok'      => false,
                            'code'    => 'invalid_regex',
                            'pattern' => $valStr,
                            'message' => 'Invalid regular expression: ' . $valStr,
                        ];
                    }
                    orbitraRegisterRegexpFunction($pdo);
                    $entityClauses[] = $operator === 'regexp'
                        ? "orbitra_regexp(?, $col) = 1"
                        : "orbitra_regexp(?, $col) = 0";
                    $entityParams[] = $re;
                    break;
            }
        }

        $whereClauses = array_merge(["cl.created_at >= ? AND cl.created_at < ?"], $entityClauses);
        $params = array_merge([$trendFromUtc, $trendToUtc], $entityParams);
        $whereSQL = implode(' AND ', $whereClauses);

        // Determine grouping format
        $dateFormat = match ($groupBy) {
            'month'       => '%Y-%m',
            'day_of_week' => '%w',
            'hour'        => '%Y-%m-%d %H:00:00',
            default       => '%Y-%m-%d',
        };
        $trendPeriodExpr = "strftime('$dateFormat', cl.created_at, '$dbTzOffset')";
        $dayNames = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];

        $conversionsValueColumn = getConversionsValueColumn($pdo);
        $revenueRecordsValueColumn = getRevenueRecordsValueColumn($pdo);

        $cvJoin = $conversionsValueColumn !== null
            ? "LEFT JOIN (SELECT click_id, SUM($conversionsValueColumn) as rev FROM conversions GROUP BY click_id) cv ON cv.click_id = cl.id"
            : "";
        $cvSelect = $conversionsValueColumn !== null ? "COALESCE(SUM(COALESCE(cv.rev, 0)), 0)" : "0";

        $rrJoin = $revenueRecordsValueColumn !== null
            ? "LEFT JOIN (SELECT click_id, SUM($revenueRecordsValueColumn) as rev FROM revenue_records GROUP BY click_id) rr ON rr.click_id = cl.id"
            : "";
        $rrSelect = $revenueRecordsValueColumn !== null ? "COALESCE(SUM(COALESCE(rr.rev, 0)), 0)" : "0";

        $sql = "
            SELECT
                $trendPeriodExpr as period,
                COUNT(cl.id) as clicks,
                COALESCE(SUM(cl.{$trendUniqCol}), 0) as unique_clicks,
                COALESCE(SUM(cl.is_conversion), 0) as conversions,
                COALESCE(SUM(cl.cost), 0) as cost,
                $cvSelect as revenue,
                $rrSelect as real_revenue,
                COALESCE(SUM(CASE WHEN cl.landing_id IS NOT NULL AND cl.landing_id > 0 THEN 1 ELSE 0 END), 0) as prelander_clicks,
                COALESCE(SUM(CASE WHEN cl.landing_id IS NOT NULL AND cl.landing_id > 0
                                  AND cl.offer_at IS NOT NULL THEN 1 ELSE 0 END), 0) as lp_clicks
            FROM clicks cl
            $cvJoin
            $rrJoin
            WHERE $whereSQL
            GROUP BY period
            ORDER BY period ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Zero-fill linear day/hour axes so every range spans its full window.
        $zeroFilled = [];
        if (in_array($groupBy, ['day', 'hour'], true)) {
            $filled = [];
            if ($groupBy === 'hour') {
                $cursor = $dateFrom;
                $guard = 0;
                while (strcmp($cursor, $dateTo) <= 0 && $guard < 800) {
                    for ($h = 0; $h <= 23; $h++) {
                        $key = $cursor . ' ' . str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00:00';
                        $filled[$key] = [
                            'period' => $key, 'clicks' => 0, 'unique_clicks' => 0,
                            'conversions' => 0, 'revenue' => 0, 'real_revenue' => 0, 'cost' => 0,
                            'prelander_clicks' => 0, 'lp_clicks' => 0,
                        ];
                    }
                    $cursor = gmdate('Y-m-d', strtotime($cursor . ' 00:00:00 UTC +1 day'));
                    $guard++;
                }
                foreach ($rows as $row) {
                    if (isset($filled[$row['period']])) {
                        $filled[$row['period']] = $row;
                    }
                }
            } else {
                $cursor = $dateFrom;
                $endDay = $dateTo;
                $guard = 0;
                while (strcmp($cursor, $endDay) <= 0 && $guard < 800) {
                    $filled[$cursor] = [
                        'period' => $cursor, 'clicks' => 0, 'unique_clicks' => 0,
                        'conversions' => 0, 'revenue' => 0, 'real_revenue' => 0, 'cost' => 0,
                        'prelander_clicks' => 0, 'lp_clicks' => 0,
                    ];
                    $cursor = gmdate('Y-m-d', strtotime($cursor . ' 00:00:00 UTC +1 day'));
                    $guard++;
                }
                foreach ($rows as $row) {
                    if (isset($filled[$row['period']])) {
                        $filled[$row['period']] = $row;
                    }
                }
            }
            ksort($filled);
            $zeroFilled = array_values($filled);
        }
        $effectiveRows = !empty($zeroFilled) ? $zeroFilled : $rows;

        $trendCr      = fn(int $conv, int $cl) => $cl > 0 ? round(($conv / $cl) * 100, 2) : 0;
        $trendCtr     = fn(int $lp, int $pre) => $pre > 0 ? round(($lp / $pre) * 100, 2) : null;
        $trendRoi     = fn(float $p, float $cost) => $cost > 0 ? round(($p / $cost) * 100, 2) : ($p > 0 ? 100 : 0);
        $trendEpc     = fn(float $rev, int $cl) => $cl > 0 ? round($rev / $cl, 4) : 0;
        $trendCpa     = fn(float $cost, int $conv) => $conv > 0 ? round($cost / $conv, 2) : 0;

        $trendSums = [
            'clicks' => 0, 'conversions' => 0, 'revenue' => 0.0, 'real_revenue' => 0.0,
            'cost' => 0.0, 'prelander_clicks' => 0, 'lp_clicks' => 0,
        ];

        $tableData = [];
        $chartLabels = [];
        $metricData = [];
        if ($buildLegacyChart) {
            foreach ($selectedMetrics as $m) {
                $metricData[$m] = [];
            }
        }

        foreach ($effectiveRows as $row) {
            $period = (string) $row['period'];
            if ($groupBy === 'day_of_week') {
                $period = $dayNames[(int) $period] ?? $period;
            }

            $clicks = (int) $row['clicks'];
            $conversions = (int) $row['conversions'];
            $revenue = (float) $row['revenue'];
            $realRevenue = (float) $row['real_revenue'];
            $cost = (float) $row['cost'];
            $prelanderClicks = (int) ($row['prelander_clicks'] ?? 0);
            $lpClicks = (int) ($row['lp_clicks'] ?? 0);

            $profit = $revenue - $cost;
            $realProfit = $realRevenue - $cost;
            $ctr = $trendCtr($lpClicks, $prelanderClicks);
            $cr = $trendCr($conversions, $clicks);
            $roi = $trendRoi($profit, $cost);
            $realRoi = $trendRoi($realProfit, $cost);
            $epc = $trendEpc($revenue, $clicks);
            $cpa = $trendCpa($cost, $conversions);

            foreach (['clicks', 'conversions', 'revenue', 'real_revenue', 'cost', 'prelander_clicks', 'lp_clicks'] as $sumKey) {
                $trendSums[$sumKey] += (float) ($row[$sumKey] ?? 0);
            }

            $rowData = [
                'period'        => $period,
                'period_key'    => (string) $row['period'],
                'clicks'        => $clicks,
                'unique_clicks' => (int) $row['unique_clicks'],
                'conversions'   => $conversions,
                'revenue'       => round($revenue, 2),
                'real_revenue'  => round($realRevenue, 2),
                'cost'          => round($cost, 2),
                'profit'        => round($profit, 2),
                'real_profit'   => round($realProfit, 2),
                'ctr'           => $ctr,
                'cr'            => $cr,
                'roi'           => $roi,
                'real_roi'      => $realRoi,
                'epc'           => $epc,
                'cpa'           => $cpa,
            ];

            $tableData[] = $rowData;
            if ($buildLegacyChart) {
                $chartLabels[] = $period;
                foreach ($selectedMetrics as $m) {
                    $metricData[$m][] = $rowData[$m] ?? 0;
                }
            }
        }

        // Totals over the whole selected range (unique_clicks is COUNT(DISTINCT ip)).
        $uniqStmt = $pdo->prepare("SELECT COUNT(DISTINCT cl.ip) FROM clicks cl WHERE $whereSQL");
        $uniqStmt->execute($params);
        $totClicks = (int) $trendSums['clicks'];
        $totConversions = (int) $trendSums['conversions'];
        $totRevenue = (float) $trendSums['revenue'];
        $totRealRevenue = (float) $trendSums['real_revenue'];
        $totCost = (float) $trendSums['cost'];
        $totProfit = $totRevenue - $totCost;
        $totRealProfit = $totRealRevenue - $totCost;

        $trendTotals = [
            'clicks'        => $totClicks,
            'unique_clicks' => (int) $uniqStmt->fetchColumn(),
            'conversions'   => $totConversions,
            'revenue'       => round($totRevenue, 2),
            'real_revenue'  => round($totRealRevenue, 2),
            'cost'          => round($totCost, 2),
            'profit'        => round($totProfit, 2),
            'real_profit'   => round($totRealProfit, 2),
            'ctr'           => $trendCtr((int) $trendSums['lp_clicks'], (int) $trendSums['prelander_clicks']),
            'cr'            => $trendCr($totConversions, $totClicks),
            'roi'           => $trendRoi($totProfit, $totCost),
            'real_roi'      => $trendRoi($totRealProfit, $totCost),
            'epc'           => $trendEpc($totRevenue, $totClicks),
            'cpa'           => $trendCpa($totCost, $totConversions),
        ];

        // Optional pacing totals: cap the final day of the range at cutoffHour:59:59 local.
        $pacingTotals = null;
        if ($pacingCutoffHour !== null && $pacingCutoffHour < 23) {
            [$lastDayStartUtc] = orbitraLocalDayBoundsUtc($dateTo, $dbTzOffset);
            $pacingUpperTs = strtotime($lastDayStartUtc . ' UTC') + ($pacingCutoffHour + 1) * 3600;
            $pacingToUtc = gmdate('Y-m-d H:i:s', $pacingUpperTs);
            $pWhereClauses = array_merge(["cl.created_at >= ? AND cl.created_at < ?"], $entityClauses);
            $pParams = array_merge([$trendFromUtc, $pacingToUtc], $entityParams);
            $pWhereSQL = implode(' AND ', $pWhereClauses);

            $pSql = "
                SELECT
                    COUNT(cl.id) as clicks,
                    COUNT(DISTINCT cl.ip) as unique_clicks,
                    COALESCE(SUM(cl.is_conversion), 0) as conversions,
                    COALESCE(SUM(cl.cost), 0) as cost,
                    $cvSelect as revenue,
                    $rrSelect as real_revenue,
                    COALESCE(SUM(CASE WHEN cl.landing_id IS NOT NULL AND cl.landing_id > 0 THEN 1 ELSE 0 END), 0) as prelander_clicks,
                    COALESCE(SUM(CASE WHEN cl.landing_id IS NOT NULL AND cl.landing_id > 0
                                      AND cl.offer_at IS NOT NULL THEN 1 ELSE 0 END), 0) as lp_clicks
                FROM clicks cl
                $cvJoin
                $rrJoin
                WHERE $pWhereSQL
            ";
            $pStmt = $pdo->prepare($pSql);
            $pStmt->execute($pParams);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $pClicks = (int) ($pRow['clicks'] ?? 0);
            $pConv = (int) ($pRow['conversions'] ?? 0);
            $pRev = (float) ($pRow['revenue'] ?? 0);
            $pRealRev = (float) ($pRow['real_revenue'] ?? 0);
            $pCost = (float) ($pRow['cost'] ?? 0);
            $pProfit = $pRev - $pCost;
            $pRealProfit = $pRealRev - $pCost;
            $pacingTotals = [
                'clicks'        => $pClicks,
                'unique_clicks' => (int) ($pRow['unique_clicks'] ?? 0),
                'conversions'   => $pConv,
                'revenue'       => round($pRev, 2),
                'real_revenue'  => round($pRealRev, 2),
                'cost'          => round($pCost, 2),
                'profit'        => round($pProfit, 2),
                'real_profit'   => round($pRealProfit, 2),
                'ctr'           => $trendCtr((int) ($pRow['lp_clicks'] ?? 0), (int) ($pRow['prelander_clicks'] ?? 0)),
                'cr'            => $trendCr($pConv, $pClicks),
                'roi'           => $trendRoi($pProfit, $pCost),
                'real_roi'      => $trendRoi($pRealProfit, $pCost),
                'epc'           => $trendEpc($pRev, $pClicks),
                'cpa'           => $trendCpa($pCost, $pConv),
            ];
        } elseif ($pacingCutoffHour === 23) {
            $pacingTotals = $trendTotals;
        }

        // Stats seam check (same logic as original trends endpoint).
        $trendsSeamInfo = null;
        $trendsSeam = orbitraStatsSeamUtc($pdo);
        if ($trendsSeam !== null) {
            [$tFromH] = orbitraDashboardPeriodHoursUtc('custom', $dateFrom, $dateTo, $dbTzOffset);
            if ($tFromH === null || $tFromH <= orbitraStatsSeamHour($pdo)) {
                $trendsSeamInfo = ['raw_available_from' => $trendsSeam, 'reason' => 'filters'];
            }
        }

        $result = [
            'ok'         => true,
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
            'table'      => $tableData,
            'totals'     => $trendTotals,
            'stats_seam' => $trendsSeamInfo,
        ];
        if ($pacingTotals !== null) {
            $result['pacing_totals'] = $pacingTotals;
        }

        if ($buildLegacyChart) {
            $metricColors = [
                'clicks' => '#3B82F6', 'unique_clicks' => '#10B981', 'conversions' => '#F59E0B',
                'revenue' => '#8B5CF6', 'real_revenue' => '#4338CA', 'cost' => '#EF4444',
                'profit' => '#06B6D4', 'roi' => '#8B5CF6', 'real_roi' => '#6366F1',
                'ctr' => '#EC4899', 'cr' => '#84CC16', 'epc' => '#10B981', 'cpa' => '#F59E0B',
            ];
            $metricLabels = [
                'clicks' => 'Клики', 'unique_clicks' => 'Уник. клики', 'conversions' => 'Конверсии',
                'revenue' => 'Доход', 'real_revenue' => 'Real Rev', 'cost' => 'Расход',
                'profit' => 'Прибыль', 'roi' => 'ROI', 'real_roi' => 'Real ROI',
                'ctr' => 'CTR', 'cr' => 'CR', 'epc' => 'EPC', 'cpa' => 'CPA',
            ];
            $chartDatasets = [];
            foreach ($selectedMetrics as $m) {
                $chartDatasets[] = [
                    'metric'          => $m,
                    'label'           => $metricLabels[$m] ?? $m,
                    'data'            => $metricData[$m] ?? [],
                    'borderColor'     => $metricColors[$m] ?? '#666666',
                    'backgroundColor' => ($metricColors[$m] ?? '#666666') . '20',
                    'fill'            => true,
                    'tension'         => 0.4,
                ];
            }
            $result['chart'] = [
                'labels'   => $chartLabels,
                'datasets' => $chartDatasets,
            ];
        }

        return $result;
    }
}
