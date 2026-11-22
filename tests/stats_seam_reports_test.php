<?php
/**
 * ТЗ 2.1, stage 2: the dashboard cards across the raw/rollup seam.
 *
 * On one database with a frozen hour (old clicks still present — cleanup has
 * not run) the seam card SQL (orbitraDashboardMetricsSqlSeam) must return
 * EXACTLY what the raw card SQL (orbitraDashboardMetricsSql) returns for the
 * same period: the acceptance rule "расхождение 0", money after rounding the
 * raw sums to 1e-6. Also pins:
 *  - a period fully inside the raw window takes the raw path unchanged;
 *  - the seam is the watermark hour, never now − window;
 *  - the safe-page predicate works over the frozen rows (is_safe buckets).
 *
 * Usage: php tests/stats_seam_reports_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/http.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->start();
try {
    $pdo = $harness->getPdo();
    $work = $harness->getWorkingDir();
    @mkdir($work . '/cli', 0777, true);
    copy(__DIR__ . '/../cli/stats_freeze_cron.php', $work . '/cli/stats_freeze_cron.php');
    require_once $work . '/core/ReportMetrics.php';

    $runFreeze = static function () use ($work): void {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/stats_freeze_cron.php')
            . ' --quiet --budget=10 2>/dev/null');
    };
    $setSetting = static function (string $k, string $v) use ($pdo): void {
        $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                       ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$k, $v]);
    };

    // --- seed: two old hours (one frozen with a safe hit) + a fresh hour ---
    $now = time();
    $oldBase = (int) (($now - 5 * 86400) / 3600);
    $hourTs = static fn(int $h): string => gmdate('Y-m-d H:i:s', $h * 3600);
    $setSetting('stats_raw_retention_days', '3');

    $ins = $pdo->prepare("
        INSERT INTO clicks (id, campaign_id, stream_id, offer_id, landing_id, ip, referer,
            country_code, device_type, os, browser, is_bot, pointer_activity,
            uniq_campaign, uniq_stream, uniq_global, is_safe_page, cost, fee, lp_seconds, lp_scroll, created_at)
        VALUES (:id, :c, :s, :o, :l, :ip, :ref, :cc, :dev, :os, :br, :bot, :pa,
            :uc, :us, :ug, :safe, :cost, :fee, :lps, :scr, :ts)");
    $mk = static function (string $id, int $hOff, array $over = []) use ($oldBase, $hourTs): array {
        return array_merge([
            'id' => $id, 'c' => 1, 's' => 10, 'o' => 20, 'l' => null, 'ip' => '203.0.113.' . random_int(1, 250),
            'ref' => '', 'cc' => 'DE', 'dev' => 'mobile', 'os' => 'android', 'br' => 'chrome',
            'bot' => 0, 'pa' => 1, 'uc' => 1, 'us' => 1, 'ug' => 1, 'safe' => 0,
            'cost' => 0.0008, 'fee' => 0.0001, 'lps' => 12, 'scr' => 40, 'ts' => $hourTs($oldBase + $hOff),
        ], $over);
    };
    // Frozen hours.
    $ins->execute($mk('sc-a', 0));
    $ins->execute($mk('sc-b', 0, ['cc' => 'US', 'ref' => 'https://t.co/x', 'uc' => 0, 'us' => 0, 'ug' => 0, 'scr' => null, 'lps' => null]));
    $ins->execute($mk('sc-c', 0, ['safe' => 1, 'bot' => 1, 'pa' => 0]));       // safe-page hit, own bucket
    $ins->execute($mk('sc-d', 1, ['c' => 2, 'cc' => null, 'os' => 'ios']));
    // Fresh hour — stays raw.
    $ins->execute($mk('sc-f', 0, ['ts' => gmdate('Y-m-d H:i:s', $now - 1800), 'c' => 9]));

    // A cloak stream with the report exclusion ON: without it the safe-page
    // predicate includes every hit by design, and there is nothing to pin.
    $pdo->exec("INSERT INTO streams (campaign_id, schema_type, schema_custom_json, position)
                VALUES (1, 'cloak', '{\"exclude_safe_from_reports\": true}', 0)");

    $insConv = $pdo->prepare("INSERT INTO conversions (click_id, tid, status, payout, currency, campaign_id, offer_id)
                              VALUES (?, NULL, ?, ?, 'USD', 1, 20)");
    $insConv->execute(['sc-a', 'sale', 12.30]);
    $insConv->execute(['sc-b', 'rejected', 0.50]);

    $runFreeze();
    $wm = (int) $pdo->query("SELECT value FROM settings WHERE key='stats_frozen_through_hour'")->fetchColumn();
    check($wm === (int) (($now - 3 * 86400) / 3600) - 1, 'freeze reached the window target');

    // --- equivalence: raw card vs seam card over the crossing period -------
    $periodFromHour = 0;   // from the beginning of time — crosses the seam
    $rawWhere = "WHERE cl.created_at >= '" . $hourTs($periodFromHour) . "' AND " . orbitraSafePagePredicate('cl.');
    $convCol = 'payout';
    $raw = $pdo->query(orbitraDashboardMetricsSql($convCol, 'amount') . $rawWhere)->fetch(PDO::FETCH_ASSOC);
    $seamUtc = gmdate('Y-m-d H:i:s', ($wm + 1) * 3600);
    $frozenConds = [
        orbitraSafePagePredicateStats(),
        "hour_utc <= $wm",
        "hour_utc >= $periodFromHour",
    ];
    $seamRow = $pdo->query(orbitraDashboardMetricsSqlSeam($convCol, 'amount', $seamUtc, $rawWhere, implode(' AND ', $frozenConds)))
        ->fetch(PDO::FETCH_ASSOC);
    check($raw !== false && $seamRow !== false, 'both card paths returned rows');

    $moneyCols = ['cost', 'revenue', 'revenue_confirmed', 'revenue_rejected', 'real_revenue'];
    $avgCols = ['avg_lp_dwell', 'avg_lp_scroll', 'lp_dwell_samples'];
    $mismatches = [];
    foreach ($raw as $col => $rawVal) {
        if (!array_key_exists($col, $seamRow)) {
            $mismatches[] = "$col missing in seam row";
            continue;
        }
        $a = (float) $rawVal;
        $b = (float) $seamRow[$col];
        $tolerance = in_array($col, $moneyCols, true) ? 0.000001 : 0.000001;
        if (abs($a - $b) > $tolerance) {
            $mismatches[] = "$col: raw=$a seam=$b";
        }
    }
    check($mismatches === [], 'equivalence across the seam: ' . ($mismatches === [] ? 'all columns match' : implode('; ', array_slice($mismatches, 0, 5))));

    // --- safe-page exclusion parity -----------------------------------------
    // The raw card predicate excludes the safe hit (default exclude); the
    // seam card must too — the safe hit lives in its own is_safe bucket.
    // Totals without a campaign filter: uniq_global — sc-a, sc-d, sc-f
    // carry the global flag (sc-b repeats, the safe hit is excluded).
    check((int) $raw['unique_clicks'] === 3 && (int) $seamRow['unique_clicks'] === 3,
        'totals uniqueness is uniq_global on both paths');
    check((int) $raw["clicks"] === 4 && (int) $seamRow["clicks"] === 4,
        'safe-page hit excluded on both paths (raw=' . $raw['clicks'] . ' seam=' . $seamRow['clicks'] . ', expect 4)');
    $rawAll = $pdo->query(orbitraDashboardMetricsSql($convCol, 'amount')
        . " WHERE cl.created_at >= '" . $hourTs($periodFromHour) . "' AND COALESCE(cl.is_safe_page, 0) = 1")
        ->fetch(PDO::FETCH_ASSOC);
    check((int) $rawAll['clicks'] === 1, 'the safe hit is the excluded one');

    // --- chart across the seam: daily buckets, both paths -------------------
    $dayFmt = '%Y-%m-%d';
    $rawChart = $pdo->query(orbitraDashboardMetricsSql($convCol, 'amount',
        "strftime('{$dayFmt}', cl.created_at, '+00:00') AS period,", 'global')
        . $rawWhere . ' GROUP BY period ORDER BY period ASC')->fetchAll(PDO::FETCH_ASSOC);
    $seamChart = $pdo->query(orbitraDashboardMetricsSqlSeam($convCol, 'amount', $seamUtc, $rawWhere,
        implode(' AND ', $frozenConds), 'global', $dayFmt, '+00:00'))->fetchAll(PDO::FETCH_ASSOC);
    $same = count($rawChart) === count($seamChart);
    if ($same) {
        foreach ($rawChart as $i => $row) {
            foreach ($row as $col => $v) {
                if (abs((float) $v - (float) $seamChart[$i][$col]) > 0.000001) {
                    $same = false;
                    break 2;
                }
            }
        }
    }
    check($same, 'chart daily buckets identical across the seam (' . count($seamChart) . ' periods)');

    // --- resource lists: raw reference vs the seam subquery, every dim ------
    foreach (['campaign_id', 'offer_id', 'landing_id', 'source_id'] as $listDim) {
        $dimFilter = $listDim === 'campaign_id' ? '' : "AND cl.{$listDim} IS NOT NULL";
        $rawRef = $pdo->query("
            SELECT cl.{$listDim} AS dim_id,
                   COUNT(cl.id) AS clicks,
                   COALESCE(SUM(cl.uniq_campaign), 0) AS unique_clicks,
                   COALESCE(SUM(cl.uniq_global), 0) AS unique_clicks_global,
                   COALESCE(SUM(cl.is_bot), 0) AS bots,
                   COALESCE(SUM(cl.cost), 0) AS cost,
                   COALESCE(SUM(cva.cnt_any), 0) AS conversions,
                   COALESCE(SUM(cva.rev_all), 0) AS revenue
            FROM clicks cl
            LEFT JOIN (SELECT click_id, COUNT(*) AS cnt_any, SUM(payout) AS rev_all FROM conversions GROUP BY click_id) cva ON cva.click_id = cl.id
            WHERE cl.created_at >= '2020-01-01 00:00:00' AND " . orbitraSafePagePredicate('cl.') . " {$dimFilter}
            GROUP BY cl.{$listDim}")->fetchAll(PDO::FETCH_ASSOC);
        $seamSub = orbitraStatsSeamListSql('payout', 'amount', $listDim,
            ['cl.created_at >= :r_seam', 'cl.created_at >= :r_from', orbitraSafePagePredicate('cl.')],
            [orbitraSafePagePredicateStats(), 'hour_utc <= :f_seam', 'hour_utc >= :f_from']);
        $seamStmt = $pdo->prepare("SELECT {$listDim} AS dim_id, clicks, unique_clicks, unique_clicks_global, bots, cost, conversions, revenue FROM {$seamSub}");
        $seamStmt->execute([':r_seam' => $seamUtc, ':r_from' => '2020-01-01 00:00:00', ':f_seam' => (string) $wm, ':f_from' => 0]);
        $seamRows = [];
        foreach ($seamStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $seamRows[(int) $r['dim_id']] = $r;
        }
        $ok = true;
        $why = 'rows';
        foreach ($rawRef as $row) {
            $s = $seamRows[(int) $row['dim_id']] ?? null;
            if (!$s) { $ok = false; $why = "missing {$row['dim_id']}"; break; }
            foreach (['clicks', 'unique_clicks', 'unique_clicks_global', 'bots', 'conversions'] as $col) {
                if ((int) $row[$col] !== (int) $s[$col]) { $ok = false; $why = "{$row['dim_id']} $col {$row[$col]} vs {$s[$col]}"; break 2; }
            }
            foreach (['cost', 'revenue'] as $col) {
                if (abs((float) $row[$col] - (float) $s[$col]) > 0.000001) { $ok = false; $why = "{$row['dim_id']} $col {$row[$col]} vs {$s[$col]}"; break 2; }
            }
        }
        check($ok, "list equivalence [$listDim]: seam subquery matches the raw reference ($why)");
    }

    // --- campaigns list: raw reference vs the seam subquery -----------------
    {
        $rawRef = $pdo->query("
            SELECT cl.campaign_id,
                   COUNT(cl.id) AS clicks,
                   COALESCE(SUM(cl.uniq_campaign), 0) AS unique_clicks,
                   COALESCE(SUM(cl.uniq_global), 0) AS unique_clicks_global,
                   COALESCE(SUM(cl.is_bot), 0) AS bots,
                   COALESCE(SUM(CASE WHEN cl.referer IS NULL OR cl.referer = '' THEN 1 ELSE 0 END), 0) AS empty_referrers,
                   COALESCE(SUM(cl.cost), 0) AS cost,
                   COALESCE(SUM(cva.cnt_any), 0) AS conversions,
                   COALESCE(SUM(cva.rev_all), 0) AS revenue
            FROM clicks cl
            LEFT JOIN (SELECT click_id, COUNT(*) AS cnt_any, SUM(payout) AS rev_all FROM conversions GROUP BY click_id) cva ON cva.click_id = cl.id
            WHERE cl.created_at >= '2020-01-01 00:00:00' AND " . orbitraSafePagePredicate('cl.') . "
            GROUP BY cl.campaign_id")->fetchAll(PDO::FETCH_ASSOC);
        $seamSub = orbitraStatsSeamListSql('payout', 'amount', 'campaign_id',
            ['cl.created_at >= :r_seam', 'cl.created_at >= :r_from', orbitraSafePagePredicate('cl.')],
            [orbitraSafePagePredicateStats(), 'hour_utc <= :f_seam', 'hour_utc >= :f_from']);
        $seamStmt = $pdo->prepare("SELECT campaign_id, clicks, unique_clicks, unique_clicks_global, bots, empty_referrers, cost, conversions, revenue FROM {$seamSub}");
        $seamStmt->execute([':r_seam' => $seamUtc, ':r_from' => '2020-01-01 00:00:00', ':f_seam' => (string) $wm, ':f_from' => 0]);
        $seamRows = [];
        foreach ($seamStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $seamRows[(int) $r['campaign_id']] = $r;
        }
        $ok = count($rawRef) === count($seamRows);
        $why = 'row count ' . count($rawRef) . ' vs ' . count($seamRows);
        foreach ($rawRef as $row) {
            $s = $seamRows[(int) $row['campaign_id']] ?? null;
            if (!$s) { $ok = false; $why = 'missing campaign ' . $row['campaign_id']; break; }
            foreach (['clicks', 'unique_clicks', 'unique_clicks_global', 'bots', 'empty_referrers', 'conversions'] as $col) {
                if ((int) $row[$col] !== (int) $s[$col]) { $ok = false; $why = "campaign {$row['campaign_id']} $col: {$row[$col]} vs {$s[$col]}"; break 2; }
            }
            foreach (['cost', 'revenue'] as $col) {
                if (abs((float) $row[$col] - (float) $s[$col]) > 0.000001) { $ok = false; $why = "campaign {$row['campaign_id']} $col: {$row[$col]} vs {$s[$col]}"; break 2; }
            }
        }
        check($ok, "campaigns list: seam subquery matches the raw reference per campaign ($why)");
    }

    // --- stream stats across the seam ---------------------------------------
    {
        require_once $work . '/core/stream_stats.php';
        // Fresh reference over the live clicks (still all present), then the
        // same call over the seam: identical per-stream counters.
        $rawStats = null;
        $seamSave = $seamUtc;
        // Roll the watermark back to nothing to force the raw path.
        $pdo->exec("UPDATE settings SET value = '0' WHERE key = 'stats_frozen_through_hour'");
        $rawStats = orbitraStreamStats($pdo, 1, 'today', 0, $now + 7200);
        // today's local (UTC) day spans the frozen hours too — the period is
        // chosen so the frozen hours 0..1 are inside it.
        $rawRows = [];
        foreach ($rawStats['streams'] as $sid => $s) {
            $rawRows[$sid] = $s;
        }
        // Restore the frozen seam and re-run the same period.
        $pdo->exec("UPDATE settings SET value = '{$wm}' WHERE key = 'stats_frozen_through_hour'");
        $seamStats = orbitraStreamStats($pdo, 1, 'today', 0, $now + 7200);
        $ok = true;
        $why = 'shape';
        $seamRows = $seamStats['streams'];
        if (count($rawRows) !== count($seamRows)) {
            $ok = false;
            $why = 'stream count ' . count($rawRows) . ' vs ' . count($seamRows);
        } else {
            foreach ($rawRows as $sid => $s) {
                $t = $seamRows[$sid] ?? null;
                if (!$t) { $ok = false; $why = "missing stream $sid"; break; }
                foreach (['visitors', 'unique', 'bots', 'conversions'] as $k) {
                    if ((int) $s[$k] !== (int) $t[$k]) { $ok = false; $why = "stream $sid $k {$s[$k]} vs {$t[$k]}"; break 2; }
                }
            }
        }
        check($ok, "stream stats identical across the seam ($why)");
    }

    // --- campaign_report across the seam: country layer ----------------------
    {
        // Raw reference: same action with the watermark rolled back (the raw
        // path serves everything while nothing is frozen).
        $pdo->exec("UPDATE settings SET value = '0' WHERE key = 'stats_frozen_through_hour'");
        $rawRef = [];
        $rawRows = $pdo->query("
            SELECT COALESCE(clicks.country_code, 'Unknown') AS dim_1,
                   COUNT(clicks.id) AS clicks,
                   COALESCE(SUM(clicks.uniq_campaign), 0) AS unique_clicks,
                   COALESCE(SUM(clicks.cost), 0) AS cost,
                   COALESCE(SUM(cva.cnt_any), 0) AS conversions,
                   COALESCE(SUM(cva.rev_all), 0) AS revenue
            FROM clicks
            LEFT JOIN (SELECT click_id, COUNT(*) AS cnt_any, SUM(payout) AS rev_all FROM conversions GROUP BY click_id) cva ON cva.click_id = clicks.id
            WHERE " . orbitraSafePagePredicate('clicks.') . "
            GROUP BY dim_1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rawRows as $row) {
            $rawRef[$row['dim_1']] = $row;
        }
        $pdo->exec("UPDATE settings SET value = '{$wm}' WHERE key = 'stats_frozen_through_hour'");

        // Seam path: drive the builder exactly the way the action does.
        $layers = ['country'];
        $reportFilters = [];
        $campaign_id = 0;
        $reportSeamLayers = ['stats_place_country', ['campaign_id', 'stream_id', 'offer_id', 'landing_id', 'source_id', 'country']];
        [$seamTable, $seamDims] = $reportSeamLayers;
        $seamHour = $wm;
        $reportSeam = $seamUtc;
        $rFromHour = null;
        $rToHour = null;
        $rToBound = null;
        $allowed_dimensions = ['country' => 'clicks.country'];
        $dimInner = ["COALESCE(clicks.country_code, 'Unknown') as dim_1"];
        $dimOuter = ['dim_1'];
        $dimGroupBy = ['dim_1'];
        $convAggSql = orbitraConversionAggregateSql('payout');
        $realJoin = '';
        $realRevSelect = '0';
        include $work . '/core/campaign_report_seam.php';
        $st = $pdo->prepare($sql);
        $st->execute($reportSeamParams);
        $seamRef = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $seamRef[$row['dim_1']] = $row;
        }
        $ok = count($rawRef) === count($seamRef);
        $why = 'rows ' . count($rawRef) . ' vs ' . count($seamRef);
        if ($ok) {
            foreach ($rawRef as $dim => $row) {
                $s = $seamRef[$dim] ?? null;
                if (!$s) { $ok = false; $why = "missing $dim"; break; }
                foreach (['clicks', 'unique_clicks', 'conversions'] as $c) {
                    if ((int) $row[$c] !== (int) $s[$c]) { $ok = false; $why = "$dim $c {$row[$c]} vs {$s[$c]}"; break 2; }
                }
                foreach (['cost', 'revenue'] as $c) {
                    if (abs((float) $row[$c] - (float) $s[$c]) > 0.000001) { $ok = false; $why = "$dim $c {$row[$c]} vs {$s[$c]}"; break 2; }
                }
            }
        }
        check($ok, "campaign_report [country] identical across the seam ($why)");
    }

    // --- raw-only period takes the raw path (no frozen part) ----------------
    $freshFrom = gmdate('Y-m-d H:i:s', $now - 7200);
    $rawFresh = $pdo->query(orbitraDashboardMetricsSql($convCol, 'amount') . "WHERE cl.created_at >= '$freshFrom'")->fetch(PDO::FETCH_ASSOC);
    $seamFresh = $pdo->query(orbitraDashboardMetricsSqlSeam($convCol, 'amount', $seamUtc, "WHERE cl.created_at >= '$freshFrom'",
        "hour_utc <= $wm AND hour_utc >= " . ((int) (($now - 7200) / 3600))))->fetch(PDO::FETCH_ASSOC);
    check((int) $seamFresh['clicks'] === (int) $rawFresh['clicks'] && (int) $seamFresh['clicks'] === 1,
        'period fully above the seam reads raw only (fresh click counted once)');
} finally {
    $harness->stop();
}

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);
