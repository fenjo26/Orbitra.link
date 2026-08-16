<?php
// core/ReportMetrics.php
//
// One definition of the report metric set, shared by the campaigns list and the
// layered campaign report. Both used to build the same figures inline, with the
// conversion columns expressed as ten correlated subqueries per click row — on a
// campaign with a million clicks that is ten million scans of `conversions`, and
// the dashboard runs it on every load. The SQL below aggregates `conversions`
// once and joins the result, and the ratio maths lives in one function so the two
// endpoints cannot drift apart.

if (!function_exists('orbitraConversionStatusGroups')) {

    /** Status vocabulary the tracker groups conversions by. */
    function orbitraConversionStatusGroups(): array
    {
        return [
            'sale'     => ['sale', 'confirmed', 'approved'],
            'hold'     => ['lead', 'hold', 'pending'],
            'rejected' => ['rejected', 'declined'],
            'trash'    => ['trash', 'junk'],
        ];
    }

    /**
     * Per-click conversion aggregate, ready to LEFT JOIN on click id.
     *
     * $valueColumn is the payout column as reported by getConversionsValueColumn()
     * (installs differ), or null on a schema with no value column at all.
     */
    function orbitraConversionAggregateSql(?string $valueColumn): string
    {
        $value = $valueColumn !== null ? $valueColumn : '0';
        $parts = ["SUM($value) AS rev_all"];
        foreach (orbitraConversionStatusGroups() as $group => $statuses) {
            $list = "'" . implode("', '", $statuses) . "'";
            $parts[] = "SUM(CASE WHEN status IN ($list) THEN $value ELSE 0 END) AS rev_$group";
            // Conversion rows, not clicks: a rebill is a second sale, the way Keitaro counts it.
            $parts[] = "SUM(CASE WHEN status IN ($list) THEN 1 ELSE 0 END) AS cnt_$group";
        }
        return "(SELECT click_id, " . implode(', ', $parts) . " FROM conversions GROUP BY click_id)";
    }

    /** Per-click revenue_records aggregate ("real" revenue), ready to LEFT JOIN. */
    function orbitraRevenueRecordsAggregateSql(?string $valueColumn): string
    {
        $value = $valueColumn !== null ? $valueColumn : '0';
        return "(SELECT click_id, SUM($value) AS real_rev FROM revenue_records GROUP BY click_id)";
    }

    /**
     * Derive every ratio the reports show from the raw counters.
     *
     * Ratios with a zero denominator return 0 — except ROI, which returns null so
     * the UI can print a dash. Reporting "+100%" for a campaign with no recorded
     * spend, as this used to, reads as a real result.
     */
    function orbitraComputeDerivedMetrics(array $raw): array
    {
        $clicks       = (int) ($raw['clicks'] ?? 0);
        $uniqueClicks = (int) ($raw['unique_clicks'] ?? 0);
        $prelander    = (int) ($raw['prelander_clicks'] ?? 0);
        $offerClicks  = (int) ($raw['offer_clicks'] ?? 0);
        $conversions  = (int) ($raw['conversions'] ?? 0);
        $purchases    = (int) ($raw['purchases'] ?? 0);
        $holds        = (int) ($raw['holds'] ?? 0);
        $rejected     = (int) ($raw['rejected'] ?? 0);
        $trash        = (int) ($raw['trash'] ?? 0);
        $cost         = (float) ($raw['cost'] ?? 0);
        $revenue      = (float) ($raw['revenue'] ?? 0);
        $revConfirmed = (float) ($raw['revenue_confirmed'] ?? 0);
        $revHold      = (float) ($raw['revenue_hold'] ?? 0);
        $revRejected  = (float) ($raw['revenue_rejected'] ?? 0);
        $revTrash     = (float) ($raw['revenue_trash'] ?? 0);
        $realRevenue  = (float) ($raw['real_revenue'] ?? 0);

        $statused  = $purchases + $holds + $rejected + $trash;
        $exclTrash = $purchases + $holds + $rejected;

        return [
            'clicks'                  => $clicks,
            'unique_clicks'           => $uniqueClicks,
            'uc_rate'                 => $clicks > 0 ? round(($uniqueClicks / $clicks) * 100, 2) : 0,
            'prelander_clicks'        => $prelander,
            'offer_clicks'            => $offerClicks,
            'lp_ctr'                  => $prelander > 0 ? round(($offerClicks / $prelander) * 100, 2) : 0,
            'conversions'             => $conversions,
            'purchases'               => $purchases,
            'holds'                   => $holds,
            'rejected'                => $rejected,
            'trash'                   => $trash,
            'cost'                    => round($cost, 2),
            'revenue'                 => round($revenue, 2),
            'revenue_all'             => round($revenue, 2),
            'revenue_confirmed'       => round($revConfirmed, 2),
            'revenue_hold'            => round($revHold, 2),
            'revenue_rejected'        => round($revRejected, 2),
            'revenue_trash'           => round($revTrash, 2),
            'real_revenue'            => round($realRevenue, 2),
            'profit'                  => round($revenue - $cost, 2),
            'real_profit'             => round($realRevenue - $cost, 2),
            'roi'                     => $cost > 0 ? round((($revenue - $cost) / $cost) * 100, 2) : null,
            'real_roi'                => $cost > 0 ? round((($realRevenue - $cost) / $cost) * 100, 2) : null,
            'cr'                      => $clicks > 0 ? round(($conversions / $clicks) * 100, 2) : 0,
            'cr_all'                  => $clicks > 0 ? round(($conversions / $clicks) * 100, 2) : 0,
            'cr_sales'                => $clicks > 0 ? round(($purchases / $clicks) * 100, 2) : 0,
            'cr_holds'                => $clicks > 0 ? round(($holds / $clicks) * 100, 2) : 0,
            'approve_rate'            => $statused > 0 ? round(($purchases / $statused) * 100, 2) : 0,
            'approve_rate_excl_trash' => $exclTrash > 0 ? round(($purchases / $exclTrash) * 100, 2) : 0,
            'cpc'                     => $clicks > 0 ? round($cost / $clicks, 4) : 0,
            'ucpc'                    => $uniqueClicks > 0 ? round($cost / $uniqueClicks, 4) : 0,
            'cpa'                     => $conversions > 0 ? round($cost / $conversions, 2) : 0,
            'epc'                     => $clicks > 0 ? round($revenue / $clicks, 4) : 0,
            'uepc'                    => $uniqueClicks > 0 ? round($revenue / $uniqueClicks, 4) : 0,
            'earnings_per_conv'       => $conversions > 0 ? round($revenue / $conversions, 2) : 0,
        ];
    }
}
