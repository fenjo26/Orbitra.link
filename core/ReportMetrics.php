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
     * Full offers list with per-status conversion counters, money and cost,
     * ready to prepare/execute. The offers table page runs this and then feeds
     * each row through orbitraComputeDerivedMetrics(), so its numbers are the
     * verified 64-metric math by construction, not a near copy.
     *
     * Row extras after compute: cr, epc_confirmed, cpc, profit_confirmed,
     * roi_confirmed (plus raw sales/leads/rejected/trash/revenue_confirmed/cost
     * and lp_clicks — clicks that reached the offer through a landing).
     */
    function orbitraOffersWithStatsSql(string $joinCondition, ?string $valueColumn): string
    {
        $agg = orbitraConversionAggregateSql($valueColumn);
        return "
            SELECT id, name, group_id, affiliate_network_id, url, redirect_type,
                   is_local, geo, payout_type, payout_value, payout_auto,
                   allow_rebills, capping_limit, capping_timezone, alt_offer_id,
                   notes, state, created_at, group_name, affiliate_network_name,
                   COUNT(click_id) as clicks,
                   COUNT(DISTINCT click_ip) as unique_clicks,
                   COALESCE(SUM(via_landing), 0) as lp_clicks,
                   COALESCE(SUM(cnt_any), 0) as conversions,
                   COALESCE(SUM(rev_all), 0) as revenue,
                   COALESCE(SUM(rev_sale), 0) as revenue_confirmed,
                   COALESCE(SUM(cnt_sale), 0) as sales,
                   COALESCE(SUM(cnt_hold), 0) as leads,
                   COALESCE(SUM(cnt_rejected), 0) as rejected,
                   COALESCE(SUM(cnt_trash), 0) as trash,
                   COALESCE(SUM(click_cost), 0) as cost
            FROM (
                SELECT o.id, o.name, o.group_id, o.affiliate_network_id, o.url, o.redirect_type,
                       o.is_local, o.geo, o.payout_type, o.payout_value, o.payout_auto,
                       o.allow_rebills, o.capping_limit, o.capping_timezone, o.alt_offer_id,
                       o.notes, o.state, o.created_at,
                       og.name as group_name,
                       an.name as affiliate_network_name,
                       cl.id as click_id,
                       cl.ip as click_ip,
                       cl.cost as click_cost,
                       CASE WHEN cl.landing_id IS NOT NULL AND cl.landing_id > 0 THEN 1 ELSE 0 END as via_landing,
                       cva.cnt_any, cva.rev_all, cva.rev_sale,
                       cva.cnt_sale, cva.cnt_hold, cva.cnt_rejected, cva.cnt_trash
                FROM offers o
                LEFT JOIN offer_groups og ON o.group_id = og.id
                LEFT JOIN affiliate_networks an ON an.id = o.affiliate_network_id
                LEFT JOIN clicks cl ON o.id = cl.offer_id $joinCondition
                LEFT JOIN $agg cva ON cva.click_id = cl.id
                WHERE o.is_archived = 0
            )
            GROUP BY id
        ";
    }

    /**
     * Full landings list with the same counters, keyed on the landing instead
     * of the offer. Run it and feed each row through orbitraComputeDerivedMetrics()
     * with prelander_clicks = clicks (every click row bound to a landing is one
     * landing view), so landing numbers are the same verified math as offers.
     *
     * Row extras after compute: lp_ctr, cr, approve_rate, epc/epv family, cpc,
     * profit, roi (plus raw sales/leads/rejected/trash/revenue_confirmed/cost).
     */
    function orbitraLandingsWithStatsSql(string $joinCondition, ?string $valueColumn): string
    {
        $agg = orbitraConversionAggregateSql($valueColumn);
        return "
            SELECT id, name, type, url, state, group_name,
                   COUNT(click_id) as clicks,
                   COUNT(DISTINCT click_ip) as unique_clicks,
                   COALESCE(SUM(offer_clicked), 0) as lp_clicks,
                   COALESCE(SUM(cnt_any), 0) as conversions,
                   COALESCE(SUM(rev_all), 0) as revenue,
                   COALESCE(SUM(rev_sale), 0) as revenue_confirmed,
                   COALESCE(SUM(cnt_sale), 0) as sales,
                   COALESCE(SUM(cnt_hold), 0) as leads,
                   COALESCE(SUM(cnt_rejected), 0) as rejected,
                   COALESCE(SUM(cnt_trash), 0) as trash,
                   COALESCE(SUM(click_cost), 0) as cost,
                   MAX(click_created) as last_event
            FROM (
                SELECT l.id, l.name, l.type, l.url, l.state,
                       lg.name as group_name,
                       cl.id as click_id,
                       cl.ip as click_ip,
                       cl.cost as click_cost,
                       cl.created_at as click_created,
                       CASE WHEN cl.offer_id IS NOT NULL AND cl.offer_id > 0 THEN 1 ELSE 0 END as offer_clicked,
                       cva.cnt_any, cva.rev_all, cva.rev_sale,
                       cva.cnt_sale, cva.cnt_hold, cva.cnt_rejected, cva.cnt_trash
                FROM landings l
                LEFT JOIN landing_groups lg ON l.group_id = lg.id
                LEFT JOIN clicks cl ON l.id = cl.landing_id $joinCondition
                LEFT JOIN $agg cva ON cva.click_id = cl.id
                WHERE l.is_archived = 0
            )
            GROUP BY id
        ";
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
            // EPV — earnings per visit. At campaign/landing/offer scope a visit
            // is one click row (the LP→offer transition updates the row rather
            // than inserting one), so the denominator is clicks and EPV equals
            // EPC by definition, not by accident.
            'epv'                     => $clicks > 0 ? round($revenue / $clicks, 4) : 0,
            'epv_confirmed'           => $clicks > 0 ? round($revConfirmed / $clicks, 4) : 0,
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
