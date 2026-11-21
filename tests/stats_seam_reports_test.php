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
    check((int) $raw["clicks"] === 4 && (int) $seamRow["clicks"] === 4,
        'safe-page hit excluded on both paths (raw=' . $raw['clicks'] . ' seam=' . $seamRow['clicks'] . ', expect 4)');
    $rawAll = $pdo->query(orbitraDashboardMetricsSql($convCol, 'amount')
        . " WHERE cl.created_at >= '" . $hourTs($periodFromHour) . "' AND COALESCE(cl.is_safe_page, 0) = 1")
        ->fetch(PDO::FETCH_ASSOC);
    check((int) $rawAll['clicks'] === 1, 'the safe hit is the excluded one');

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
