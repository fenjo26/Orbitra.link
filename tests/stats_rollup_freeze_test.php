<?php
/**
 * ТЗ 2.1, этап 1: почасовые итоги и заморозка сырых кликов.
 *
 *  - миграция создаёт stats_place_country / stats_device (WITHOUT ROWID,
 *    деньги INTEGER микро-единицы ×1e6) и справочники измерений;
 *  - крон заморозки сворачивает закрытые часы за raw-окном в итоги,
 *    идемпотентно, по часу за транзакцию, двигая watermark;
 *  - итогы по построению совпадают с отчётной агрегацией по clicks
 *    (расхождение 0, деньги после округления до 1e-6);
 *  - поздние конверсии (после заморозки) инкрементят замороженные строки
 *    триггером — включая конверсию клика, который уже удалён (денорм);
 *  - cleanup удаляет только замороженные часы и хранит конвертированные
 *    клики; смена срока между заморозками не откатывает watermark;
 *  - EXPLAIN запроса заморозки использует idx_clicks_created_at без
 *    sqlite_stat1 и с ней.
 *
 * Usage: php tests/stats_rollup_freeze_test.php
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
        copy(__DIR__ . '/../cli/cleanup_cron.php', $work . '/cli/cleanup_cron.php');

        $runFreeze = static function (string $extra = '') use ($work): void {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/stats_freeze_cron.php')
                . ' --quiet --budget=10 ' . $extra . ' 2>/dev/null');
        };
        $runCleanup = static function () use ($work): void {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/cleanup_cron.php') . ' 2>/dev/null');
        };

        // --- 0. the spool cron freezes with NO dedicated crontab line -------
        // The spool worker is the only freeze entry point on regular installs;
        // simulate one by running click_spool_cron.php with both budgets
        // pinned (the WAL loop would otherwise burn a minute per call).
        {
            copy(__DIR__ . '/../cli/click_spool_cron.php', $work . '/cli/click_spool_cron.php');
            $old = gmdate('Y-m-d H:i:s', time() - 5 * 86400);
            $pdo->exec("INSERT INTO clicks (id, campaign_id, ip, country_code, uniq_campaign, uniq_stream, uniq_global, created_at)
                        VALUES ('spool-frozen', 1, '203.0.113.99', 'DE', 1, 1, 1, '$old')");
            $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_raw_retention_days', '3')");
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php')
                . ' --quiet --checkpoint-budget=0 --freeze-budget=10 2>/dev/null');
            $spoolWm = (int) $pdo->query("SELECT value FROM settings WHERE key = 'stats_frozen_through_hour'")->fetchColumn();
            $expectSpool = (int) ((time() - 3 * 86400) / 3600) - 1;
            check($spoolWm === $expectSpool, "spool cron alone advances the watermark ($spoolWm == $expectSpool)");
            $rolled = $pdo->query("SELECT clicks FROM stats_place_country WHERE campaign_id = 1")->fetch(PDO::FETCH_ASSOC);
            check($rolled !== false && (int) $rolled['clicks'] === 1, 'spool cron froze the hour into the rollups');
            // Reset for the main scenario below.
            $pdo->exec('DELETE FROM stats_place_country');
            $pdo->exec('DELETE FROM stats_device');
            $pdo->exec('DELETE FROM clicks');
            $pdo->exec("UPDATE settings SET value = '0' WHERE key = 'stats_frozen_through_hour'");
        }


    $hourTs = static fn(int $h): string => gmdate('Y-m-d H:i:s', $h * 3600);
    $watermark = static function () use ($pdo): int {
        return (int) $pdo->query("SELECT value FROM settings WHERE key = 'stats_frozen_through_hour'")->fetchColumn();
    };
    $setSetting = static function (string $k, string $v) use ($pdo): void {
        $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                       ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$k, $v]);
    };

    // --- 1. schema ----------------------------------------------------------
    $placeSql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='stats_place_country'")->fetchColumn();
    $devSql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='stats_device'")->fetchColumn();
    check(is_string($placeSql) && str_contains($placeSql, 'WITHOUT ROWID'), 'stats_place_country exists, WITHOUT ROWID');
    check(is_string($devSql) && str_contains($devSql, 'WITHOUT ROWID'), 'stats_device exists, WITHOUT ROWID');
    check(is_string($placeSql) && str_contains($placeSql, 'cost_micro INTEGER') && str_contains($placeSql, 'rev_all_micro INTEGER'),
        'money columns are INTEGER micro units');
    foreach (['stat_dim_country', 'stat_dim_os', 'stat_dim_browser', 'stat_dim_device'] as $dim) {
        check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = '$dim'")->fetchColumn() === 1, "dictionary $dim exists");
    }
    $convSql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='conversions'")->fetchColumn();
    check(str_contains($convSql, 'click_created_at') && !str_contains($convSql, 'FOREIGN KEY (click_id) REFERENCES clicks'),
        'conversions has denorm columns and no FK to clicks (fresh install)');
    foreach (['conv_denorm_fill', 'conv_stats_ins', 'conv_stats_upd', 'conv_stats_del', 'rev_stats_ins', 'rev_stats_upd', 'rev_stats_del'] as $trg) {
        check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='trigger' AND name = '$trg'")->fetchColumn() === 1,
            "trigger $trg exists");
    }

    // --- seed: 2 old hours (freezeable) + empty old hour + 1 fresh hour -----
    $now = time();
    $oldBase = (int) (($now - 5 * 86400) / 3600);   // 5 days ago, hour 0
    $freshTs = gmdate('Y-m-d H:i:s', $now - 3600);  // 1 hour ago — stays raw
    $setSetting('stats_raw_retention_days', '3');
    $setSetting('stats_converted_retention_days', '365');

    $ins = $pdo->prepare("
        INSERT INTO clicks (id, campaign_id, stream_id, offer_id, landing_id, source_id, ip, user_agent,
            country_code, device_type, os, browser, is_bot, is_proxy, pointer_activity,
            uniq_campaign, uniq_stream, uniq_global, is_safe_page,
            cost, fee, lp_seconds, created_at)
        VALUES (:id, :c, :s, :o, :l, :src, :ip, 'UA', :cc, :dev, :os, :br, :bot, 0, :pa,
            :uc, :us, :ug, 0, :cost, :fee, :lps, :ts)");
    $mk = static function (string $id, int $hOff, array $over = []) use ($oldBase, $hourTs): array {
        return array_merge([
            'id' => $id, 'c' => 1, 's' => 10, 'o' => 20, 'l' => null, 'src' => null,
            'ip' => '203.0.113.' . random_int(1, 250), 'cc' => 'DE', 'dev' => 'mobile', 'os' => 'android', 'br' => 'chrome',
            'bot' => 0, 'pa' => 1, 'uc' => 1, 'us' => 1, 'ug' => 1, 'cost' => 0.0008, 'fee' => 0.0001, 'lps' => 7,
            'ts' => $hourTs($oldBase + $hOff),
        ], $over);
    };
    // Hour 0: cl-a (DE android, sale $12.30), cl-b (US desktop, non-unique? no —
    // unique, hold $0.50), cl-c (DE android bot, non-unique everywhere).
    $ins->execute($mk('cl-a', 0));
    $ins->execute($mk('cl-b', 0, ['cc' => 'US', 'dev' => 'desktop', 'os' => 'windows', 'br' => 'firefox']));
    $ins->execute($mk('cl-c', 0, ['bot' => 1, 'pa' => 0, 'uc' => 0, 'us' => 0, 'ug' => 0]));
    // cl-e: never converts — the raw click the cleanup is allowed to purge.
    $ins->execute($mk('cl-e', 0, ['cc' => 'FR', 'cost' => 0, 'fee' => 0]));
    // Hour 1: cl-d (campaign 2, NULL country, ios/safari), no conversions.
    $ins->execute($mk('cl-d', 1, ['c' => 2, 's' => 11, 'cc' => null, 'os' => 'ios', 'br' => 'safari']));
    // Fresh hour, stays raw.
    $ins->execute($mk('cl-fresh', 0, ['ts' => $freshTs, 'c' => 9]));

    $insConv = $pdo->prepare("INSERT INTO conversions (click_id, tid, status, payout, currency, campaign_id, offer_id, created_at)
                              VALUES (?, NULL, ?, ?, 'USD', 1, 20, datetime('now'))");
    $insConv->execute(['cl-a', 'sale', 12.30]);
    $insConv->execute(['cl-b', 'hold', 0.50]);

    // Watermark is still 0 — nothing frozen, so the conversion triggers must
    // be no-ops: live hours are computed wholesale from raw clicks at freeze
    // time; a trigger increment on top of that would double-count.
    check((int) $pdo->query('SELECT COUNT(*) FROM stats_place_country')->fetchColumn() === 0
        && (int) $pdo->query('SELECT COUNT(*) FROM stats_device')->fetchColumn() === 0,
        'conversion triggers are no-ops before any hour is frozen (watermark gate)');

    // --- 2. partial freeze: one hour only ------------------------------------
    $runFreeze('--max-hours=1');
    $wmAfterOne = $watermark();
    check($wmAfterOne === $oldBase, "watermark == first hour ($wmAfterOne)");

    $cid = static fn(string $dim, string $val): int => (int) $pdo
        ->query("SELECT id FROM $dim WHERE value = " . $pdo->quote($val))->fetchColumn();
    $nid = static fn(string $dim): int => (int) $pdo->query("SELECT id FROM $dim WHERE value = ''")->fetchColumn();

    $deId = $cid('stat_dim_country', 'DE');
    $row = $pdo->query("SELECT * FROM stats_place_country WHERE hour_utc = $oldBase AND campaign_id = 1
                        AND stream_id = 10 AND offer_id = 20 AND country_id = $deId")->fetch(PDO::FETCH_ASSOC);
    check($row !== false, 'hour0 DE bucket row exists');
    if ($row) {
        check((int) $row['clicks'] === 2, "hour0 DE clicks = 2 (got {$row['clicks']})");
        check((int) $row['uniq_campaign'] === 1, "hour0 DE uniq_campaign = 1");
        check((int) $row['bots'] === 1, 'hour0 DE bot counted');
        check((int) $row['conv_sale'] === 1 && (int) $row['conv_any'] === 1, 'hour0 DE sale conversion counted');
        check((int) $row['rev_sale_micro'] === 12300000, "hour0 DE rev_sale_micro = 12300000 (got {$row['rev_sale_micro']})");
        check((int) $row['cost_micro'] === 1600 && (int) $row['fee_micro'] === 200, 'hour0 DE cost/fee micro summed');
    }
    $rowUs = $pdo->query("SELECT * FROM stats_place_country WHERE hour_utc = $oldBase AND country_id = " . $cid('stat_dim_country', 'US'))->fetch(PDO::FETCH_ASSOC);
    check($rowUs && (int) $rowUs['clicks'] === 1 && (int) $rowUs['conv_hold'] === 1 && (int) $rowUs['rev_hold_micro'] === 500000,
        'hour0 US bucket: clicks + hold conversion + micro revenue');
    $rowDev = $pdo->query("SELECT clicks, bots, possible_bots FROM stats_device WHERE hour_utc = $oldBase AND campaign_id = 1
                           AND os_id = " . $cid('stat_dim_os', 'android'))->fetch(PDO::FETCH_ASSOC);
    check($rowDev && (int) $rowDev['clicks'] === 3 && (int) $rowDev['bots'] === 1 && (int) $rowDev['possible_bots'] === 1,
        'hour0 device bucket (android): 3 clicks, bot + possible_bot flagged');
    check((int) $pdo->query("SELECT COUNT(*) FROM stats_place_country WHERE hour_utc = " . ($oldBase + 1))->fetchColumn() === 0,
        'hour 1 not frozen yet (--max-hours=1)');
    check((int) $pdo->query("SELECT COUNT(*) FROM stats_place_country WHERE campaign_id = 9")->fetchColumn() === 0,
        'fresh-hour campaign 9 not frozen (stays raw)');

    // --- 3. idempotency -------------------------------------------------------
    $before = $pdo->query("SELECT COUNT(*), COALESCE(SUM(clicks),0), COALESCE(SUM(conv_sale),0), COALESCE(SUM(rev_all_micro),0)
                           FROM stats_place_country")->fetch(PDO::FETCH_NUM);
    // Replay the SAME hour: roll the watermark back and re-freeze. REPLACE
    // over identical aggregates must leave the table byte-identical.
    $setSetting('stats_frozen_through_hour', (string) ($oldBase - 1));
    $runFreeze('--max-hours=1');
    check($watermark() === $oldBase, 'replayed hour re-advanced the watermark');
    $after = $pdo->query("SELECT COUNT(*), COALESCE(SUM(clicks),0), COALESCE(SUM(conv_sale),0), COALESCE(SUM(rev_all_micro),0)
                          FROM stats_place_country")->fetch(PDO::FETCH_NUM);
    check($before === $after, 're-freezing the same hour is idempotent');

    // --- 4. equivalence: report aggregate from clicks == frozen totals -------
    $raw = $pdo->query("
        SELECT COUNT(*) clicks, COALESCE(SUM(is_bot),0) bots, COALESCE(SUM(uniq_campaign),0) uc,
               COALESCE(SUM(cost),0) cost
        FROM clicks WHERE created_at >= '" . $hourTs($oldBase) . "' AND created_at < '" . $hourTs($oldBase + 1) . "'")->fetch(PDO::FETCH_ASSOC);
    $conv = $pdo->query("
        SELECT SUM(CASE WHEN LOWER(c.status) IN ('sale','confirmed','approved','approve','accepted','purchase','paid') THEN 1 ELSE 0 END) cs,
               COALESCE(SUM(CASE WHEN LOWER(c.status) IN ('sale','confirmed','approved','approve','accepted','purchase','paid') THEN c.payout ELSE 0 END),0) rs
        FROM conversions c JOIN clicks cl ON cl.id = c.click_id
        WHERE cl.created_at >= '" . $hourTs($oldBase) . "' AND cl.created_at < '" . $hourTs($oldBase + 1) . "'")->fetch(PDO::FETCH_ASSOC);
    $frozen = $pdo->query("SELECT COALESCE(SUM(clicks),0) clicks, COALESCE(SUM(bots),0) bots, COALESCE(SUM(uniq_campaign),0) uc,
                                  COALESCE(SUM(cost_micro),0) cost_micro, COALESCE(SUM(conv_sale),0) cs, COALESCE(SUM(rev_sale_micro),0) rs
                           FROM stats_place_country WHERE hour_utc = $oldBase")->fetch(PDO::FETCH_ASSOC);
    check((int) $raw['clicks'] === (int) $frozen['clicks'] && (int) $raw['bots'] === (int) $frozen['bots']
        && (int) $raw['uc'] === (int) $frozen['uc'], 'equivalence: clicks/bots/uniq flags');
    check(round((float) $raw['cost'], 6) === round((int) $frozen['cost_micro'] / 1000000, 6), 'equivalence: cost after 1e-6 rounding');
    check((int) $conv['cs'] === (int) $frozen['cs'] && round((float) $conv['rs'], 6) === round((int) $frozen['rs'] / 1000000, 6),
        'equivalence: sale conversions and revenue');

    // --- 5. late conversion after freeze (trigger) ----------------------------
    $insConv->execute(['cl-c', 'rejected', 3.00]);
    $r = $pdo->query("SELECT conv_rejected, rev_rejected_micro FROM stats_place_country
                      WHERE hour_utc = $oldBase AND campaign_id = 1 AND country_id = $deId")->fetch(PDO::FETCH_ASSOC);
    check($r && (int) $r['conv_rejected'] === 1 && (int) $r['rev_rejected_micro'] === 3000000,
        'late conversion increments the frozen bucket');

    // --- 6. status change after freeze (hold -> sale) --------------------------
    $pdo->prepare('UPDATE conversions SET status = ?, payout = ? WHERE click_id = ?')->execute(['sale', 15.00, 'cl-b']);
    $r = $pdo->query("SELECT conv_hold, rev_hold_micro, conv_sale, rev_sale_micro FROM stats_place_country
                      WHERE hour_utc = $oldBase AND country_id = " . $cid('stat_dim_country', 'US'))->fetch(PDO::FETCH_ASSOC);
    check($r && (int) $r['conv_hold'] === 0 && (int) $r['rev_hold_micro'] === 0
        && (int) $r['conv_sale'] === 1 && (int) $r['rev_sale_micro'] === 15000000,
        'status change moves counts and micro revenue between groups');

    // --- 7. conversion of a deleted click (denorm path) -------------------------
    $denorm = $pdo->query("SELECT click_created_at, stream_id, country_code FROM conversions WHERE click_id = 'cl-b'")->fetch(PDO::FETCH_ASSOC);
    check($denorm && $denorm['click_created_at'] !== null && $denorm['click_created_at'] !== ''
        && (int) $denorm['stream_id'] === 10 && $denorm['country_code'] === 'US',
        'denorm columns filled at conversion time');
    $pdo->exec('DELETE FROM clicks WHERE id = ' . $pdo->quote('cl-b')); // simulate the 365d expiry
    $pdo->prepare('UPDATE conversions SET payout = ? WHERE click_id = ?')->execute([16.00, 'cl-b']);
    $r = $pdo->query("SELECT conv_sale, rev_sale_micro FROM stats_place_country
                      WHERE hour_utc = $oldBase AND country_id = " . $cid('stat_dim_country', 'US'))->fetch(PDO::FETCH_ASSOC);
    check($r && (int) $r['conv_sale'] === 1 && (int) $r['rev_sale_micro'] === 16000000,
        'trigger still buckets correctly after the click row is gone (denorm)');

    // --- 8. cleanup respects the watermark and converted clicks ----------------
    $runCleanup();
    $alive = static function (string $id) use ($pdo): bool {
        return (bool) $pdo->query('SELECT COUNT(*) FROM clicks WHERE id = ' . $pdo->quote($id))->fetchColumn();
    };
    check(!$alive('cl-e'), 'cleanup removed the unconverted click of a frozen hour');
    check($alive('cl-c'), 'cleanup keeps the click whose conversion arrived late (it is converted now)');
    check($alive('cl-a'), 'cleanup keeps the converted click of a frozen hour');
    check($alive('cl-d'), 'cleanup keeps clicks of hours the freeze has not reached');
    $convLeft = (int) $pdo->query("SELECT COUNT(*) FROM conversions WHERE click_id IN ('cl-a','cl-b','cl-c')")->fetchColumn();
    check($convLeft === 3, 'conversions survive their clicks (no cascade)');

    // --- 8b. the read→commit race: a conversion landing between the two
    // freeze steps must land in the rollup, not be lost. The trigger no-ops
    // (the hour is not frozen yet), and the commit's conversion-side SELECT
    // — running INSIDE the held WRITER lock — sees it. Driven through the
    // step functions directly, since the CLI entry point has no seam.
    {
        require_once $work . '/core/ReportMetrics.php';
        require_once $work . '/core/stats_freeze.php';
        $raceHour = $oldBase + 1;   // cl-d's hour — still raw
        $ins->execute($mk('cl-race', 1, ['cc' => 'DE']));
        orbitraStatsSeedHourDictionaries($pdo, $raceHour);
        $read = orbitraStatsReadHourMetrics($pdo, $raceHour);
        check(($read['place']['1|10|20|0|0|' . $cid('stat_dim_country', 'DE') . '|0']['clicks'] ?? 0) === 1,
            'race: click-side read sees the race click');
        $pdo->prepare("INSERT INTO conversions (click_id, tid, status, payout, currency, campaign_id, offer_id)
                       VALUES ('cl-race', NULL, 'sale', 7.77, 'USD', 1, 20)")->execute();
        $raceDe = (int) $pdo->query("SELECT COALESCE(SUM(conv_sale), 0) FROM stats_place_country WHERE hour_utc = {$raceHour}")->fetchColumn();
        check($raceDe === 0, 'race: trigger is a no-op while the hour is unfrozen');
        orbitraStatsCommitFrozenHour($pdo, $raceHour, $read);
        check($watermark() === $raceHour, 'race: commit advanced the watermark over the raced hour');
        $r = $pdo->query("SELECT conv_sale, rev_sale_micro FROM stats_place_country
                          WHERE hour_utc = {$raceHour} AND campaign_id = 1 AND stream_id = 10 AND offer_id = 20
                            AND country_id = " . $cid('stat_dim_country', 'DE'))->fetch(PDO::FETCH_ASSOC);
        check($r && (int) $r['conv_sale'] === 1 && (int) $r['rev_sale_micro'] === 7770000,
            'race: the between-steps conversion is in the frozen numbers, not lost');
    }

    // --- 8c. conv_clicks is path-independent (flag set BEFORE the insert) ---
    {
        // A late click whose flag some write path had already set to 1 — the
        // trigger must still count its FIRST conversion once, ignore the
        // second, and step back only when the last conversion goes.
        $ins->execute($mk('cl-px', 0, ['uc' => 0, 'us' => 0, 'ug' => 0]));
        $pdo->exec('UPDATE clicks SET is_conversion = 1 WHERE id = ' . $pdo->quote('cl-px'));
        $before = (int) $pdo->query("SELECT COALESCE(SUM(conv_clicks), 0) FROM stats_place_country WHERE hour_utc = $oldBase")->fetchColumn();
        $c1 = $pdo->prepare("INSERT INTO conversions (click_id, tid, status, payout, currency, campaign_id, offer_id) VALUES ('cl-px', 'p1', 'sale', 1, 'USD', 1, 20)");
        $c2 = $pdo->prepare("INSERT INTO conversions (click_id, tid, status, payout, currency, campaign_id, offer_id) VALUES ('cl-px', 'p2', 'sale', 2, 'USD', 1, 20)");
        $c1->execute();
        $afterFirst = (int) $pdo->query("SELECT COALESCE(SUM(conv_clicks), 0) FROM stats_place_country WHERE hour_utc = $oldBase")->fetchColumn();
        $c2->execute();
        $afterSecond = (int) $pdo->query("SELECT COALESCE(SUM(conv_clicks), 0) FROM stats_place_country WHERE hour_utc = $oldBase")->fetchColumn();
        $pdo->exec("DELETE FROM conversions WHERE click_id = 'cl-px' AND tid = 'p2'");
        $afterDel2 = (int) $pdo->query("SELECT COALESCE(SUM(conv_clicks), 0) FROM stats_place_country WHERE hour_utc = $oldBase")->fetchColumn();
        $pdo->exec("DELETE FROM conversions WHERE click_id = 'cl-px' AND tid = 'p1'");
        $afterDel1 = (int) $pdo->query("SELECT COALESCE(SUM(conv_clicks), 0) FROM stats_place_country WHERE hour_utc = $oldBase")->fetchColumn();
        check($afterFirst === $before + 1, 'conv_clicks +1 on the first conversion (flag was pre-set)');
        check($afterSecond === $afterFirst, 'conv_clicks unchanged on the second conversion');
        check($afterDel2 === $afterFirst, 'conv_clicks unchanged when a non-last conversion is deleted');
        check($afterDel1 === $before, 'conv_clicks -1 when the last conversion goes');
    }

    // --- 9. full freeze ----------------------------------------------------------
    $runFreeze();
    $target = (int) (($now - 3 * 86400) / 3600) - 1;
    $wm = $watermark();
    check($wm === $target, "watermark reached the window target ($wm == $target)");
    $rowH1 = $pdo->query("SELECT clicks FROM stats_place_country WHERE hour_utc = " . ($oldBase + 1)
        . " AND country_id = " . $nid('stat_dim_country'))->fetch(PDO::FETCH_ASSOC);
    check($rowH1 && (int) $rowH1['clicks'] === 1,
        'hour1 NULL-country bucket holds the raw click (cl-d)');
    check((int) $pdo->query("SELECT COUNT(*) FROM stats_place_country WHERE hour_utc = " . ($oldBase + 2))->fetchColumn() === 0,
        'empty hour 2 skipped without rows');

    // --- 10. retention change between freezes --------------------------------------
    $setSetting('stats_raw_retention_days', '10');
    $runFreeze();
    check($watermark() === $wm, 'raising the retention window never rolls the watermark back');
    $setSetting('stats_raw_retention_days', '2');
    $runFreeze();
    $target2 = (int) (($now - 2 * 86400) / 3600) - 1;
    check($watermark() === $target2, "lowering the window lets the cron catch up ($wm -> $target2)");

    // --- 11. EXPLAIN: freeze scan rides the created_at index ------------------------
    $plan = $pdo->query("EXPLAIN QUERY PLAN SELECT COUNT(*) FROM clicks
                         WHERE created_at >= '2026-01-01 00:00:00' AND created_at < '2026-01-01 01:00:00'")->fetchAll(PDO::FETCH_COLUMN, 3);
    check(!!array_filter($plan, fn($p) => str_contains($p, 'idx_clicks_created_at')), 'freeze range scan uses idx_clicks_created_at');
    $pdo->exec('ANALYZE clicks');
    $plan2 = $pdo->query("EXPLAIN QUERY PLAN SELECT COUNT(*) FROM clicks
                          WHERE created_at >= '2026-01-01 00:00:00' AND created_at < '2026-01-01 01:00:00'")->fetchAll(PDO::FETCH_COLUMN, 3);
    check(!!array_filter($plan2, fn($p) => str_contains($p, 'idx_clicks_created_at')), 'EXPLAIN with sqlite_stat1 still uses the index');
} finally {
    $harness->stop();
}

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);
