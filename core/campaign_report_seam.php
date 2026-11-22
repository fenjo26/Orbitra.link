<?php
// core/campaign_report_seam.php
//
// ТЗ 2.1, stage 2 — INCLUDED from api.php's campaign_report action when the
// period crosses the freeze seam AND every layer and every filter lives in
// ONE rollup table (place: campaign/stream/offer/landing/source/country;
// device: campaign/device_type/os/browser). Builds $sql as
//
//     report columns  :=  outer SUM over
//         ( raw half: the legacy inner subquery, conditions rebuilt with
//           named :r_* placeholders, clamped to the seam )
//         UNION ALL
//         ( frozen half: the rollup table, :f_* placeholders, layer values
//           from the dimension dictionaries, NULLIF(x,0) restoring raw NULLs )
//
// Averages travel as sum/cnt pairs (h_8/h_9 lp_time, h_10/h_11 dwell,
// h_13/h_14 scroll) and divide in the outer SELECT; money comes back from
// micro units. Money rounding stays 1e-6-equivalent to the raw half.
//
// Sets $sql, $reportSeamParams (named), $reportSeamInfo and
require_once __DIR__ . '/resource_access.php';   // orbitraCampaignScope

// Anything incompatible never reaches this file —
// the action already fell back to the raw-only path with raw_available_from.

$frozenMetricMap = [
    // report alias => rollup column / expression
    'unique_clicks' => 'SUM(uniq_campaign)',
    'unique_clicks_stream' => 'SUM(uniq_stream)',
    'unique_clicks_global' => 'SUM(uniq_global)',
    'visitors' => 'SUM(clicks)',
    'clicks' => 'SUM(clicks)',
    'bots' => 'SUM(bots)',
    'possible_bots' => 'SUM(possible_bots)',
    'proxies' => 'SUM(proxies)',
    'empty_referrers' => 'SUM(empty_referrers)',
    'lp_time_sum' => 'SUM(lp_time_sum)',
    'lp_time_cnt' => 'SUM(lp_time_cnt)',
    'lp_dwell_sum' => 'SUM(lp_dwell_sum)',
    'lp_dwell_cnt' => 'SUM(lp_dwell_cnt)',
    'lp_bounces' => 'SUM(lp_bounces)',
    'lp_scroll_sum' => 'SUM(scroll_sum)',
    'lp_scroll_cnt' => 'SUM(scroll_cnt)',
    'prelander_clicks' => 'SUM(prelander_clicks)',
    'offer_clicks' => 'SUM(offer_clicks)',
    'lp_clicks' => 'SUM(lp_clicks)',
    'real_lp_clicks' => 'SUM(real_lp_clicks)',
    'real_offer_clicks' => 'SUM(real_offer_clicks)',
    'pwa_intents' => 'SUM(pwa_intents)',
    'pwa_installs' => 'SUM(pwa_installs)',
    'pwa_installs_real' => 'SUM(pwa_installs_real)',
    'pwa_opens' => 'SUM(pwa_opens)',
    'push_subscribed' => 'SUM(push_subscribed)',
    'pwa_screen_views' => 'SUM(pwa_screen_views)',
    'ext_installs' => 'SUM(ext_installs)',
    'conversions' => 'SUM(conv_any)',
    'purchases' => 'SUM(conv_sale)',
    'holds' => 'SUM(conv_hold)',
    'rejected' => 'SUM(conv_rejected)',
    'trash' => 'SUM(conv_trash)',
    'registrations' => 'SUM(conv_registration)',
    'deposits' => 'SUM(conv_deposit)',
    'cost' => 'SUM(cost_micro) / 1000000.0',
    'fee' => 'SUM(fee_micro) / 1000000.0',
    'revenue' => 'SUM(rev_all_micro) / 1000000.0',
    'revenue_confirmed' => 'SUM(rev_sale_micro) / 1000000.0',
    'revenue_hold' => 'SUM(rev_hold_micro) / 1000000.0',
    'revenue_rejected' => 'SUM(rev_rejected_micro) / 1000000.0',
    'revenue_trash' => 'SUM(rev_trash_micro) / 1000000.0',
    'revenue_registration' => 'SUM(rev_registration_micro) / 1000000.0',
    'revenue_deposit' => 'SUM(rev_deposit_micro) / 1000000.0',
    'real_revenue' => 'SUM(real_rev_micro) / 1000000.0',
];

// Raw half expressions over the legacy inner subquery, SAME ORDER as the
// frozen map's pair columns (the h_ indexes must line up).
$rawMetricExprs = [
    'SUM(uniq_campaign)', 'SUM(uniq_stream)', 'SUM(uniq_global)',
    'COUNT(click_id)', 'COUNT(click_id)', 'SUM(is_bot)',
    'SUM(CASE WHEN pointer_activity = 0 THEN 1 ELSE 0 END)',
    'SUM(is_proxy)',
    "SUM(CASE WHEN referer IS NULL OR referer = '' THEN 1 ELSE 0 END)",
    "SUM(CASE WHEN landing_at IS NOT NULL AND offer_at IS NOT NULL
              THEN CAST(strftime('%s', offer_at) - strftime('%s', landing_at) AS INTEGER) ELSE 0 END)",
    'SUM(CASE WHEN landing_at IS NOT NULL AND offer_at IS NOT NULL THEN 1 ELSE 0 END)',
    'SUM(lp_seconds)',
    'SUM(CASE WHEN lp_seconds IS NOT NULL THEN 1 ELSE 0 END)',
    'SUM(CASE WHEN lp_seconds IS NOT NULL AND lp_seconds < 5 THEN 1 ELSE 0 END)',
    'SUM(lp_scroll)',
    'SUM(CASE WHEN lp_scroll IS NOT NULL THEN 1 ELSE 0 END)',
    'SUM(CASE WHEN landing_id IS NOT NULL AND landing_id > 0 THEN 1 ELSE 0 END)',
    'SUM(CASE WHEN (offer_id > 0 OR COALESCE(direct_offer, 0) = 1) THEN 1 ELSE 0 END)',
    'SUM(CASE WHEN landing_id IS NOT NULL AND landing_id > 0 AND offer_at IS NOT NULL THEN 1 ELSE 0 END)',
    'SUM(CASE WHEN landing_id IS NOT NULL AND landing_id > 0 AND offer_at IS NOT NULL THEN 1 ELSE 0 END)',
    'SUM(CASE WHEN (offer_id > 0 OR COALESCE(direct_offer, 0) = 1)
             AND (landing_id IS NULL OR landing_id = 0 OR offer_at IS NOT NULL) THEN 1 ELSE 0 END)',
    'SUM(CASE WHEN pwa_intent_at IS NOT NULL THEN 1 ELSE 0 END)',
    'SUM(CASE WHEN pwa_install_at IS NOT NULL THEN 1 ELSE 0 END)',
    'SUM(CASE WHEN pwa_install_at IS NOT NULL AND is_bot = 0 THEN 1 ELSE 0 END)',
    'COALESCE(SUM(pwa_open_count), 0)',
    'SUM(CASE WHEN push_subscribed_at IS NOT NULL THEN 1 ELSE 0 END)',
    'COALESCE(SUM(pwa_screen_views), 0)',
    'SUM(CASE WHEN pwa_external_install IS NOT NULL THEN 1 ELSE 0 END)',
    'COALESCE(SUM(cnt_any), 0)',
    'COALESCE(SUM(cnt_sale), 0)',
    'COALESCE(SUM(cnt_hold), 0)',
    'COALESCE(SUM(cnt_rejected), 0)',
    'COALESCE(SUM(cnt_trash), 0)',
    'COALESCE(SUM(cnt_registration), 0)',
    'COALESCE(SUM(cnt_deposit), 0)',
    'COALESCE(SUM(click_cost), 0)',
    'COALESCE(SUM(click_fee), 0)',
    'COALESCE(SUM(click_revenue), 0)',
    'COALESCE(SUM(click_sale_revenue), 0)',
    'COALESCE(SUM(click_hold_revenue), 0)',
    'COALESCE(SUM(click_rej_revenue), 0)',
    'COALESCE(SUM(click_trash_revenue), 0)',
    'COALESCE(SUM(click_reg_revenue), 0)',
    'COALESCE(SUM(click_dep_revenue), 0)',
    'COALESCE(SUM(click_real_revenue), 0)',
];

// --- frozen half: layers + filters over the rollup ----------------------------
$dimJoinsF = '';
$frozenLayer = static function (string $layer) use (&$dimJoinsF): string {
    if ($layer === 'campaign_id') {
        return 'campaign_id';
    }
    if ($layer === 'country') {
        $dimJoinsF .= ' LEFT JOIN stat_dim_country dc ON dc.id = country_id';
        return "NULLIF(dc.value, '')";
    }
    if ($layer === 'device_type') {
        $dimJoinsF .= ' LEFT JOIN stat_dim_device dd ON dd.id = device_id';
        return 'dd.value';
    }
    if ($layer === 'os') {
        $dimJoinsF .= ' LEFT JOIN stat_dim_os dos ON dos.id = os_id';
        return 'dos.value';
    }
    if ($layer === 'browser') {
        $dimJoinsF .= ' LEFT JOIN stat_dim_browser db ON db.id = browser_id';
        return 'db.value';
    }
    return "NULLIF({$layer}, 0)";   // stream/offer/landing/source: 0 = raw NULL
};
$frozenSelects = [];
$frozenGroupBy = [];
foreach ($layers as $i => $layer) {
    $expr = $frozenLayer($layer);
    $frozenSelects[] = "COALESCE({$expr}, 'Unknown') as dim_" . ($i + 1);
    $frozenGroupBy[] = $expr;
}
$hAliases = [];
$frozenMetricSelects = [];
$i = 0;
foreach ($frozenMetricMap as $alias => $expr) {
    $frozenMetricSelects[] = "{$expr} AS h_{$i}";
    $hAliases[$i] = $alias;
    $i++;
}

$frozenConds = [orbitraSafePagePredicateStats(), 'hour_utc <= :f_seam'];
$reportSeamParams[':f_seam'] = (string) $seamHour;
if ($campaign_id > 0) {
    $frozenConds[] = 'campaign_id = :f_camp';
    $reportSeamParams[':f_camp'] = $campaign_id;
}
$scopeArr = orbitraCampaignScope($pdo);
$scopeIdsArr = $scopeArr !== null ? array_map('intval', $scopeArr['ids'] ?? []) : [];
if ($scopeArr !== null) {
    if (!$scopeIdsArr) {
        $frozenConds[] = '1 = 0';
    } else {
        $frozenConds[] = 'campaign_id IN (' . implode(', ', array_map(fn($k) => ":f_sc{$k}", array_keys($scopeIdsArr))) . ')';
        foreach ($scopeIdsArr as $k => $sid) {
            $reportSeamParams[":f_sc{$k}"] = $sid;
        }
    }
}
if ($rFromHour !== null) {
    $frozenConds[] = 'hour_utc >= :f_from';
    $reportSeamParams[':f_from'] = (string) $rFromHour;
}
if ($rToHour !== null) {
    $frozenConds[] = 'hour_utc < :f_to';
    $reportSeamParams[':f_to'] = (string) $rToHour;
}
foreach ($reportFilters as $fi => [$ff, $fop, $fval]) {
    $fexp = $frozenLayer($ff);
    $fOpSql = $fop === 'eq' ? '=' : ($fop === 'neq' ? '!=' : ($fop === 'contains' ? 'LIKE' : 'NOT LIKE'));
    $frozenConds[] = "{$fexp} {$fOpSql} :f_fl{$fi}";
    $reportSeamParams[":f_fl{$fi}"] = ($fop === 'contains' || $fop === 'not_contains') ? "%{$fval}%" : $fval;
}
$frozenWhereSql = implode(' AND ', $frozenConds);

// --- raw half: legacy subquery, :r_* conditions --------------------------------
$rawConds = ['clicks.created_at >= :r_seam', orbitraSafePagePredicate('clicks.')];
$reportSeamParams[':r_seam'] = $reportSeam;
if ($campaign_id > 0) {
    $rawConds[] = 'clicks.campaign_id = :r_camp';
    $reportSeamParams[':r_camp'] = $campaign_id;
}
if ($scopeArr !== null) {
    if (!$scopeIdsArr) {
        $rawConds[] = '1 = 0';
    } else {
        $rawConds[] = 'clicks.campaign_id IN (' . implode(', ', array_map(fn($k) => ":r_sc{$k}", array_keys($scopeIdsArr))) . ')';
        foreach ($scopeIdsArr as $k => $sid) {
            $reportSeamParams[":r_sc{$k}"] = $sid;
        }
    }
}
if ($rToBound !== null) {
    $rawConds[] = 'clicks.created_at < :r_to';
    $reportSeamParams[':r_to'] = $rToBound;
}
foreach ($reportFilters as $fi => [$ff, $fop, $fval]) {
    $rexp = $allowed_dimensions[$ff];
    $rOpSql = $fop === 'eq' ? '=' : ($fop === 'neq' ? '!=' : ($fop === 'contains' ? 'LIKE' : 'NOT LIKE'));
    $rawConds[] = "{$rexp} {$rOpSql} :r_fl{$fi}";
    $reportSeamParams[":r_fl{$fi}"] = ($fop === 'contains' || $fop === 'not_contains') ? "%{$fval}%" : $fval;
}
$rawWhereSeam = implode(' AND ', $rawConds);
$rawMetricSelects = [];
foreach ($rawMetricExprs as $hi => $hExpr) {
    $rawMetricSelects[] = "{$hExpr} AS h_{$hi}";
}

// h_ indexes of the average pairs, resolved by map order.
$idx = [];
foreach (['lp_time_sum', 'lp_time_cnt', 'lp_dwell_sum', 'lp_dwell_cnt', 'lp_scroll_sum', 'lp_scroll_cnt'] as $k) {
    $idx[$k] = array_search($k, array_keys($frozenMetricMap), true);
}

$outerMetrics = [];
foreach ($hAliases as $hi => $alias) {
    $outerMetrics[] = "COALESCE(SUM(h_{$hi}), 0) as {$alias}";
}

$sql = "
    SELECT
        " . implode(', ', $dimOuter) . ",
        COALESCE(SUM(h_{$idx['lp_time_sum']}) * 1.0 / NULLIF(SUM(h_{$idx['lp_time_cnt']}), 0), 0) as avg_lp_seconds,
        COALESCE(SUM(h_{$idx['lp_dwell_sum']}) * 1.0 / NULLIF(SUM(h_{$idx['lp_dwell_cnt']}), 0), 0) as avg_lp_dwell,
        COALESCE(SUM(h_{$idx['lp_scroll_sum']}) * 1.0 / NULLIF(SUM(h_{$idx['lp_scroll_cnt']}), 0), 0) as avg_lp_scroll,
        COALESCE(SUM(h_{$idx['lp_dwell_cnt']}), 0) as lp_dwell_samples,
        " . implode(', ', $outerMetrics) . "
    FROM (
        SELECT " . implode(', ', $dimOuter) . ", " . implode(', ', $rawMetricSelects) . "
        FROM (
            SELECT clicks.id as click_id,
                   clicks.ip as click_ip,
                   clicks.referer,
                   clicks.is_bot,
                   clicks.is_proxy,
                   clicks.pointer_activity,
                   clicks.uniq_campaign,
                   clicks.uniq_stream,
                   clicks.uniq_global,
                   clicks.landing_at,
                   clicks.offer_at,
                   clicks.lp_seconds,
                   clicks.lp_scroll,
                   clicks.landing_id,
                   clicks.offer_id,
                   clicks.direct_offer,
                   clicks.pwa_intent_at,
                   clicks.pwa_install_at,
                   COALESCE(clicks.pwa_open_count, 0) as pwa_open_count,
                   clicks.push_subscribed_at,
                   (SELECT COUNT(*) FROM pwa_screen_views v WHERE v.click_id = clicks.id) as pwa_screen_views,
                   clicks.external_install_at as pwa_external_install,
                   clicks.cost as click_cost,
                   COALESCE(clicks.fee, 0) as click_fee,
                   COALESCE(cv.cnt_any, 0) as cnt_any,
                   COALESCE(cv.rev_all, 0) as click_revenue,
                   COALESCE(cv.rev_sale, 0) as click_sale_revenue,
                   COALESCE(cv.rev_hold, 0) as click_hold_revenue,
                   COALESCE(cv.rev_rejected, 0) as click_rej_revenue,
                   COALESCE(cv.rev_trash, 0) as click_trash_revenue,
                   $realRevSelect as click_real_revenue,
                   COALESCE(cv.cnt_sale, 0) as cnt_sale,
                   COALESCE(cv.cnt_hold, 0) as cnt_hold,
                   COALESCE(cv.cnt_rejected, 0) as cnt_rejected,
                   COALESCE(cv.cnt_trash, 0) as cnt_trash,
                   COALESCE(cv.cnt_registration, 0) as cnt_registration,
                   COALESCE(cv.cnt_deposit, 0) as cnt_deposit,
                   COALESCE(cv.rev_registration, 0) as click_reg_revenue,
                   COALESCE(cv.rev_deposit, 0) as click_dep_revenue,
                   " . implode(', ', $dimInner) . "
            FROM clicks
            LEFT JOIN $convAggSql cv ON cv.click_id = clicks.id
            $realJoin
            WHERE $rawWhereSeam
        )
        GROUP BY " . implode(', ', $dimGroupBy) . "
        UNION ALL
        SELECT " . implode(', ', $frozenSelects) . ", " . implode(', ', $frozenMetricSelects) . "
        FROM {$seamTable}{$dimJoinsF}
        WHERE $frozenWhereSql
        GROUP BY " . implode(', ', $frozenGroupBy) . "
    )
    GROUP BY " . implode(', ', $dimGroupBy) . "
    HAVING SUM(h_" . array_search('visitors', array_keys($frozenMetricMap), true) . ") > 0
    ORDER BY clicks DESC
    LIMIT 2000";

$params = $reportSeamParams;
$reportSeamInfo = ['frozen_through' => $reportSeam];
$reportSeamBuilt = true;
