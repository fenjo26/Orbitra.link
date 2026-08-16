<?php
// core/ReportMetrics.php
//
// Full Keitaro-compatible metric calculation set, shared by the campaigns list and the
// layered campaign report.

if (!function_exists('self_fmtLpSeconds')) {
    /** "1m 12s" / "45s" / "—" for the LP→offer time metric. */
    function self_fmtLpSeconds($seconds): string
    {
        if ($seconds === null || $seconds === '' || (float) $seconds <= 0) {
            return '—';
        }
        $s = (int) round((float) $seconds);
        if ($s < 60) {
            return $s . 's';
        }
        return intdiv($s, 60) . 'm ' . ($s % 60) . 's';
    }
}

if (!function_exists('orbitraConversionStatusGroups')) {

    /** Status vocabulary the tracker groups conversions by. */
    function orbitraConversionStatusGroups(): array
    {
        return [
            // 'deposit' deliberately NOT in the sale group: it has its own column,
            // and listing it in both counted every deposit twice (as a sale and
            // again as a deposit) in revenue and approve-rate math.
            'sale'          => ['sale', 'confirmed', 'approved', 'purchase'],
            'hold'          => ['lead', 'hold', 'pending'],
            'rejected'      => ['rejected', 'declined'],
            'trash'         => ['trash', 'junk'],
            'registration'  => ['registration', 'reg'],
            'deposit'       => ['deposit', 'dep'],
        ];
    }

    /**
     * Per-click conversion aggregate, ready to LEFT JOIN on click id.
     */
    function orbitraConversionAggregateSql(?string $valueColumn): string
    {
        $value = $valueColumn !== null ? $valueColumn : '0';
        // COUNT(*) = conversion EVENTS. clicks.is_conversion is a per-click flag
        // (0/1), so it undercounts every click with several conversions and
        // capped CR at 100% — Keitaro's CR may exceed it, and ours now may too.
        $parts = ["COUNT(*) AS cnt_any", "SUM($value) AS rev_all"];
        foreach (orbitraConversionStatusGroups() as $group => $statuses) {
            $list = "'" . implode("', '", $statuses) . "'";
            $parts[] = "SUM(CASE WHEN status IN ($list) THEN $value ELSE 0 END) AS rev_$group";
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
     * Derive every Keitaro ratio and metric from the raw counters.
     */
    function orbitraComputeDerivedMetrics(array $raw): array
    {
        $clicks          = (int) ($raw['clicks'] ?? 0);
        $uniqueClicks    = (int) ($raw['unique_clicks'] ?? 0);
        $visitors        = (int) ($raw['visitors'] ?? $uniqueClicks);
        $uniqueStream    = (int) ($raw['unique_clicks_stream'] ?? $uniqueClicks);
        $uniqueGlobal    = (int) ($raw['unique_clicks_global'] ?? $uniqueClicks);
        $bots            = (int) ($raw['bots'] ?? 0);
        $proxies         = (int) ($raw['proxies'] ?? 0);
        $emptyReferrers  = (int) ($raw['empty_referrers'] ?? 0);

        $prelander       = (int) ($raw['prelander_clicks'] ?? $raw['lp_views'] ?? 0);
        $offerClicks     = (int) ($raw['offer_clicks'] ?? $raw['lp_clicks'] ?? 0);
        // LP CTR counts landing → offer transitions (both ids set). A click that
        // went straight to the offer without a landing inflated the rate.
        $lpClicks        = (int) ($raw['lp_clicks'] ?? 0);

        $conversions     = (int) ($raw['conversions'] ?? 0);
        $purchases       = (int) ($raw['purchases'] ?? $raw['sales'] ?? 0);
        $holds           = (int) ($raw['holds'] ?? $raw['leads'] ?? 0);
        $rejected        = (int) ($raw['rejected'] ?? 0);
        $trash           = (int) ($raw['trash'] ?? 0);
        $registrations   = (int) ($raw['registrations'] ?? $raw['cnt_registration'] ?? 0);
        $deposits        = (int) ($raw['deposits'] ?? $raw['cnt_deposit'] ?? 0);

        $cost            = (float) ($raw['cost'] ?? 0);
        $revenue         = (float) ($raw['revenue'] ?? 0);
        $revConfirmed    = (float) ($raw['revenue_confirmed'] ?? $raw['rev_sale'] ?? 0);
        $revHold         = (float) ($raw['revenue_hold'] ?? $raw['rev_hold'] ?? 0);
        $revRejected     = (float) ($raw['revenue_rejected'] ?? $raw['rev_rejected'] ?? 0);
        $revTrash        = (float) ($raw['revenue_trash'] ?? $raw['rev_trash'] ?? 0);
        $revRegistration = (float) ($raw['revenue_registration'] ?? $raw['rev_registration'] ?? 0);
        $revDeposit      = (float) ($raw['revenue_deposit'] ?? $raw['rev_deposit'] ?? 0);
        $realRevenue     = (float) ($raw['real_revenue'] ?? 0);

        $statused  = $purchases + $holds + $rejected + $trash;
        $exclTrash = $purchases + $holds + $rejected;

        $profit          = $revenue - $cost;
        $profitConfirmed = $revConfirmed - $cost;
        $realProfit      = $realRevenue - $cost;

        $roi          = $cost > 0 ? round(($profit / $cost) * 100, 2) : null;
        $roiConfirmed = $cost > 0 ? round(($profitConfirmed / $cost) * 100, 2) : null;
        $realRoi      = $cost > 0 ? round(($realProfit / $cost) * 100, 2) : null;

        $profitability = $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0;

        return [
            // Traffic & Visits
            'clicks'                  => $clicks,
            'unique_clicks'           => $uniqueClicks,
            'visitors'                => $visitors,
            'unique_clicks_stream'    => $uniqueStream,
            'unique_clicks_global'    => $uniqueGlobal,
            'uc_rate'                 => $clicks > 0 ? round(($uniqueClicks / $clicks) * 100, 2) : 0,
            'uc_rate_stream'          => $clicks > 0 ? round(($uniqueStream / $clicks) * 100, 2) : 0,
            'uc_rate_global'          => $clicks > 0 ? round(($uniqueGlobal / $clicks) * 100, 2) : 0,
            'bots'                    => $bots,
            'bot_rate'                => $clicks > 0 ? round(($bots / $clicks) * 100, 2) : 0,
            'proxies'                 => $proxies,
            'empty_referrers'         => $emptyReferrers,

            // Landing Pages
            'lp_views'                => $prelander,
            'prelander_clicks'        => $prelander,
            'lp_clicks'               => $lpClicks > 0 ? $lpClicks : $offerClicks,
            'offer_clicks'            => $offerClicks,
            'lp_ctr'                  => $prelander > 0 ? round((($lpClicks > 0 ? $lpClicks : $offerClicks) / $prelander) * 100, 2) : 0,
            // Average landing→offer time, human-formatted ("1m 12s").
            'time_since_lp_click'     => self_fmtLpSeconds($raw['avg_lp_seconds'] ?? null),

            // Conversions & Events
            'conversions'             => $conversions,
            'purchases'               => $purchases,
            'sales'                   => $purchases,
            'holds'                   => $holds,
            'leads'                   => $holds,
            'registrations'           => $registrations,
            'deposits'                => $deposits,
            'rejected'                => $rejected,
            'trash'                   => $trash,
            'approve_rate'            => $statused > 0 ? round(($purchases / $statused) * 100, 2) : 0,
            'approve_rate_excl_trash' => $exclTrash > 0 ? round(($purchases / $exclTrash) * 100, 2) : 0,

            // Conversion Rates (CR)
            'cr'                      => $clicks > 0 ? round(($conversions / $clicks) * 100, 2) : 0,
            'cr_all'                  => $clicks > 0 ? round(($conversions / $clicks) * 100, 2) : 0,
            'cr_sales'                => $clicks > 0 ? round(($purchases / $clicks) * 100, 2) : 0,
            'cr_holds'                => $clicks > 0 ? round(($holds / $clicks) * 100, 2) : 0,
            'cr_leads'                => $clicks > 0 ? round(($holds / $clicks) * 100, 2) : 0,
            'cr_registrations'        => $clicks > 0 ? round(($registrations / $clicks) * 100, 2) : 0,
            'cr_deposits'             => $clicks > 0 ? round(($deposits / $clicks) * 100, 2) : 0,
            'cr_regs_to_deps'         => $registrations > 0 ? round(($deposits / $registrations) * 100, 2) : 0,
            'ucr'                     => $uniqueClicks > 0 ? round(($registrations / $uniqueClicks) * 100, 2) : 0,

            // Financial
            'cost'                    => round($cost, 2),
            'revenue'                 => round($revenue, 2),
            'revenue_all'             => round($revenue, 2),
            'revenue_confirmed'       => round($revConfirmed, 2),
            'revenue_hold'            => round($revHold, 2),
            'revenue_rejected'        => round($revRejected, 2),
            'revenue_trash'           => round($revTrash, 2),
            'revenue_registration'    => round($revRegistration, 2),
            'revenue_deposit'         => round($revDeposit, 2),
            'real_revenue'            => round($realRevenue, 2),

            'profit'                  => round($profit, 2),
            'profit_all'              => round($profit, 2),
            'profit_confirmed'        => round($profitConfirmed, 2),
            'real_profit'             => round($realProfit, 2),
            'profitability'           => $profitability,

            'roi'                     => $roi,
            'roi_all'                 => $roi,
            'roi_confirmed'           => $roiConfirmed,
            'real_roi'                => $realRoi,

            // Unit Economics
            'epc'                     => $clicks > 0 ? round($revenue / $clicks, 4) : 0,
            'epc_all'                 => $clicks > 0 ? round($revenue / $clicks, 4) : 0,
            'uepc'                    => $uniqueClicks > 0 ? round($revenue / $uniqueClicks, 4) : 0,
            'uepc_all'                => $uniqueClicks > 0 ? round($revenue / $uniqueClicks, 4) : 0,
            'epc_confirmed'           => $clicks > 0 ? round($revConfirmed / $clicks, 4) : 0,
            'uepc_confirmed'          => $uniqueClicks > 0 ? round($revConfirmed / $uniqueClicks, 4) : 0,
            'epc_hold'                => $clicks > 0 ? round($revHold / $clicks, 4) : 0,
            'uepc_hold'               => $uniqueClicks > 0 ? round($revHold / $uniqueClicks, 4) : 0,
            'epc_registration'        => $clicks > 0 ? round($revRegistration / $clicks, 4) : 0,
            'uepc_registration'       => $uniqueClicks > 0 ? round($revRegistration / $uniqueClicks, 4) : 0,

            'cpc'                     => $clicks > 0 ? round($cost / $clicks, 4) : 0,
            'ucpc'                    => $uniqueClicks > 0 ? round($cost / $uniqueClicks, 4) : 0,
            // eCPC — effective CPC once conversions are weighed in is not
            // derivable from these counters; keep it the plain cost per click
            // (identical to CPC) rather than the bogus cost*1000 it used to be.
            'ecpc'                    => $clicks > 0 ? round($cost / $clicks, 4) : 0,

            'cpa'                     => $conversions > 0 ? round($cost / $conversions, 2) : 0,
            'cps'                     => $purchases > 0 ? round($cost / $purchases, 2) : 0,
            'cpl'                     => $holds > 0 ? round($cost / $holds, 2) : 0,
            'cpr'                     => $registrations > 0 ? round($cost / $registrations, 2) : 0,
            'cpd'                     => $deposits > 0 ? round($cost / $deposits, 2) : 0,

            'ecpm_all'                => $clicks > 0 ? round(($profit / $clicks) * 1000, 2) : 0,
            'ecpm_confirmed'          => $clicks > 0 ? round(($profitConfirmed / $clicks) * 1000, 2) : 0,

            'earnings_per_conv'       => $conversions > 0 ? round($revenue / $conversions, 2) : 0,
            'ec_all'                  => $conversions > 0 ? round($revenue / $conversions, 2) : 0,
            'ec_confirmed'            => $purchases > 0 ? round($revConfirmed / $purchases, 2) : 0,
        ];
    }
}
