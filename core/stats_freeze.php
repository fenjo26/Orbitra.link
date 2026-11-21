<?php
// core/stats_freeze.php
//
// ТЗ 2.1, stage 1: the hourly-rollup freeze tick.
//
//   stats_frozen_through_hour = the last UTC hour whose clicks are fully
//   represented in stats_place_country / stats_device. Reports read raw
//   clicks at or after the watermark and rollups below it; cleanup deletes
//   raw clicks ONLY below it, so nothing is ever deleted before it is frozen
//   (the watermark, never now − window, is the seam — raising the retention
//   window must not resurrect deleted hours).
//
// Per hour, in ONE transaction: dictionary rows for the hour's dimension
// values, INSERT OR REPLACE of both rollup tables, watermark = hour. A crash
// between ticks replays the same hour and writes the same rows (REPLACE over
// identical aggregates); a late conversion after the hour froze is applied by
// the conv_stats_* / rev_stats_* triggers inside the conversion's own
// transaction instead.
//
// Before any freezing the tick finishes the migration-86 rebuild of
// `conversions` (no FK to clicks + denormalised bucket dimensions) in
// batches: the copy joins clicks for the snapshot while every row is still
// present. The watermark does not move until that rebuild is done.
//
// ENTRY POINTS. cli/click_spool_cron.php calls orbitraStatsFreezeTick()
// every minute after its spool replay — the spool line is in every install's
// crontab (install.sh, cli/server_setup.sh, and the panel's
// orbitraEnsureClickSpoolCron() for old installs), so an in-panel update
// starts freezing without ANY server reconfiguration; a dedicated crontab
// line would have left ~all updated installs never freezing while cleanup is
// watermark-gated (the database would grow forever). cli/stats_freeze_cron.php
// is a thin standalone wrapper for load stands and manual runs; both entries
// share var/locks/stats_freeze.lock, so they never double-tick.
//
// The tick holds its own lock and runs under its own budget (seconds), so a
// host caller simply gives it a slice of its tick.

if (!function_exists('orbitraStatsFreezeTick')) {

    /**
     * One freeze tick under var/locks/stats_freeze.lock.
     *
     * @param float  $budgetSeconds wall-clock budget for this tick (0 = one
     *                              statement's worth, still useful in tests).
     * @param int    $maxHours      cap on hours actually frozen this tick
     *                              (0 = uncapped); empty-hour watermark jumps
     *                              are free and never consume the cap.
     * @param ?callable(string) $log optional line logger.
     * @return array{done: bool, phase: string, watermark: int, target: int, pending_rebuild: bool}
     *         done = rebuild finished AND watermark reached the target.
     */
    function orbitraStatsFreezeTick(PDO $pdo, float $budgetSeconds, int $maxHours = 0, ?callable $log = null): array
    {
        $startedAt = microtime(true);
        $say = static function (string $msg) use ($log): void {
            if ($log !== null) {
                $log($msg);
            }
        };

        $state = ['done' => false, 'phase' => 'start', 'watermark' => 0, 'target' => 0, 'pending_rebuild' => false];

        // --- single-instance lock (shared with the standalone wrapper) -----
        $locksDir = __DIR__ . '/../var/locks';
        if (!is_dir($locksDir)) {
            @mkdir($locksDir, 0777, true);
        }
        $fp = @fopen($locksDir . '/stats_freeze.lock', 'c+');
        if ($fp) {
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                // Another entry point is mid-tick; nothing to do.
                return $state;
            }
            ftruncate($fp, 0);
            fwrite($fp, (string) time());
        }

        try {
            // The rollup schema is idempotent; re-ensuring here makes a
            // half-locked migration 87 self-heal on the first tick.
            orbitraStatsRollupSchema($pdo);
            require_once __DIR__ . '/ReportMetrics.php';

            $rawDays = max(1, min(3650, (int) orbitraStatsSetting($pdo, 'stats_raw_retention_days', '60')));
            $watermark = (int) orbitraStatsSetting($pdo, 'stats_frozen_through_hour', '0');
            $target = (int) ((time() - $rawDays * 86400) / 3600) - 1;   // last fully closed hour outside the raw window
            $state['watermark'] = $watermark;
            $state['target'] = $target;

            // --- Phase 1: conversions rebuild (migration 86) ----------------
            $rebuildState = orbitraStatsSetting($pdo, 'conversions_rebuild_state', '');
            if ($rebuildState !== '' && $rebuildState !== 'done') {
                $state['phase'] = 'conversions_rebuild';
                $state['pending_rebuild'] = true;
                try {
                    $done = orbitraStatsConversionsRebuildStep($pdo, 5000, $startedAt, $budgetSeconds);
                } catch (\Throwable $e) {
                    // A transient failure (lock, disk) defers to the next
                    // tick — the watermark stays parked, so nothing is frozen
                    // or purged on a half-rebuilt conversions table.
                    $say('conversions rebuild deferred: ' . $e->getMessage());
                    return $state;
                }
                if (!$done) {
                    $say('conversions rebuild continues next tick (watermark parked at ' . $watermark . ')');
                    return $state;
                }
                $say('conversions rebuild finished: no FK to clicks, denorm snapshot copied');
            }

            // --- Phase 2: freeze hours --------------------------------------
            $state['phase'] = 'freeze';
            $state['pending_rebuild'] = false;
            $hoursDone = 0;
            $fromHour = static fn(int $h): string => gmdate('Y-m-d H:i:s', $h * 3600);

            while ($watermark < $target
                && ($maxHours === 0 || $hoursDone < $maxHours)
                && (microtime(true) - $startedAt) < $budgetSeconds) {

                // Empty-hour fast-forward: hours without clicks are frozen by
                // definition (an empty rollup), so the watermark jumps to the
                // hour before the oldest click still ahead — never walking
                // decades of nothing after the first freeze or on a fresh
                // install.
                $next = $watermark + 1;
                $probe = $pdo->prepare('SELECT MIN(created_at) FROM clicks WHERE created_at >= ?');
                $probe->execute([$fromHour($next)]);
                $oldest = $probe->fetchColumn();
                if ($oldest === null || $oldest === false) {
                    // No raw clicks ahead of the watermark at all: everything
                    // up to the target is empty.
                    $pdo->beginTransaction();
                    orbitraStatsSetWatermark($pdo, $target);
                    $pdo->commit();
                    $watermark = $target;
                    break;
                }
                $oldestHour = (int) (strtotime((string) $oldest . ' UTC') / 3600);
                if ($oldestHour > $next) {
                    $jump = min($oldestHour - 1, $target);
                    if ($jump > $watermark) {
                        // An empty-hour jump is free: $maxHours caps actual
                        // hour freezes, not the watermark arithmetic.
                        $pdo->beginTransaction();
                        orbitraStatsSetWatermark($pdo, $jump);
                        $pdo->commit();
                        $watermark = $jump;
                    }
                    continue;
                }

                // Freeze hour $next — two steps, so the write lock is held
                // only for the short one (a busy hour is 1–2 M clicks: the
                // click-side aggregation must never run under the WRITER, or
                // clicks pause for seconds like the pre-mon201 RESTART did):
                //   0. dictionary seed — a micro-transaction of INSERT OR
                //      IGNORE over the hour's DISTINCT dimension values
                //      (an old hour's values are immutable; the read step's
                //      dim joins need the ids to exist);
                //   1. orbitraStatsReadHourMetrics — plain WAL read of the
                //      click-side metrics (no transaction, readers never
                //      block and are never blocked);
                //   2. orbitraStatsCommitFrozenHour — BEGIN IMMEDIATE: the
                //      FEW conversion-side metrics recomputed inside the
                //      lock from a fresh snapshot, REPLACE of both rollups,
                //      watermark. A conversion that lands between 1 and 2 is
                //      no-op'd by its trigger (the hour is not frozen yet)
                //      and is picked up by 2's fresh read — never lost,
                //      never double-counted.
                try {
                    orbitraStatsSeedHourDictionaries($pdo, $next);
                    $read = orbitraStatsReadHourMetrics($pdo, $next);
                    orbitraStatsCommitFrozenHour($pdo, $next, $read);
                } catch (\Throwable $e) {
                    $say('freeze of hour ' . $next . ' deferred: ' . $e->getMessage());
                    break;
                }
                $watermark = $next;
                $hoursDone++;
            }

            $state['watermark'] = $watermark;
            $state['done'] = !$state['pending_rebuild'] && $watermark >= $target;
            $say('freeze tick: watermark=' . $watermark . ' target=' . $target . ' hours=' . $hoursDone);
        } finally {
            // Heartbeat for the panel's System status component — written even
            // on the early-return paths above.
            $state['at'] = time();
            $cacheDir = __DIR__ . '/../var/cache';
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0777, true);
            }
            @file_put_contents($cacheDir . '/stats_freeze.json', json_encode($state));
            if ($fp) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
        return $state;
    }

    function orbitraStatsSetWatermark(PDO $pdo, int $hour): void
    {
        $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('stats_frozen_through_hour', ?, datetime('now'))
                       ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')")
            ->execute([(string) $hour]);
    }
}

// --- The freeze aggregation SQL -------------------------------------------------
//
// The conversion and real-revenue per-click aggregates are the REPORTS' own
// expressions (orbitraConversionAggregateSql / orbitraRevenueRecordsAggregateSql
// from core/ReportMetrics.php): the frozen numbers are what a report over the
// same hour computes, by construction. Money rounds to micro units only at
// the very cast (ROUND before CAST — float dust never truncates 12.8e6 into
// 12799999). Uniqueness is the insert-time flags (SUM), never a period
// DISTINCT: flags decompose across every dimension slice and across the
// raw/rollup seam (decision 2026-11-21).
//
// The metrics split into two families for the two-step freeze:
//  - CLICK-side (orbitraStatsFreezeClickMetrics): aggregated by a plain WAL
//    read outside any transaction — heavy on a 1–2 M-click hour, but readers
//    never hold the WRITER;
//  - CONVERSION-side (orbitraStatsFreezeConvMetrics): few rows (only clicks
//    with conversions), aggregated INSIDE the commit transaction so a late
//    conversion between the read and the commit is captured, not lost.

if (!function_exists('orbitraStatsFreezeMetrics')) {

    function orbitraStatsFreezeMetrics(): array
    {
        return array_merge(orbitraStatsFreezeClickMetrics(), orbitraStatsFreezeConvMetrics());
    }

    function orbitraStatsFreezeClickMetrics(): array
    {
        return [
            'clicks' => "COUNT(cl.id)",
            'uniq_campaign' => "COALESCE(SUM(cl.uniq_campaign), 0)",
            'uniq_stream' => "COALESCE(SUM(cl.uniq_stream), 0)",
            'uniq_global' => "COALESCE(SUM(cl.uniq_global), 0)",
            'bots' => "COALESCE(SUM(cl.is_bot), 0)",
            'proxies' => "COALESCE(SUM(cl.is_proxy), 0)",
            'possible_bots' => "COALESCE(SUM(CASE WHEN cl.pointer_activity = 0 THEN 1 ELSE 0 END), 0)",
            'safe_hits' => "COALESCE(SUM(COALESCE(cl.is_safe_page, 0)), 0)",
            'prelander_clicks' => "COALESCE(SUM(CASE WHEN cl.landing_id IS NOT NULL AND cl.landing_id > 0 THEN 1 ELSE 0 END), 0)",
            'lp_clicks' => "COALESCE(SUM(CASE WHEN cl.landing_id IS NOT NULL AND cl.landing_id > 0 AND cl.offer_at IS NOT NULL THEN 1 ELSE 0 END), 0)",
            'offer_clicks' => "COALESCE(SUM(CASE WHEN (cl.offer_id > 0 OR COALESCE(cl.direct_offer, 0) = 1) THEN 1 ELSE 0 END), 0)",
            'real_lp_clicks' => "COALESCE(SUM(CASE WHEN cl.landing_id IS NOT NULL AND cl.landing_id > 0 AND cl.offer_at IS NOT NULL THEN 1 ELSE 0 END), 0)",
            'real_offer_clicks' => "COALESCE(SUM(CASE WHEN (cl.offer_id > 0 OR COALESCE(cl.direct_offer, 0) = 1)
                               AND (cl.landing_id IS NULL OR cl.landing_id = 0 OR cl.offer_at IS NOT NULL)
                          THEN 1 ELSE 0 END), 0)",
            'popup_shown' => "COALESCE(SUM(CASE WHEN cl.popup_shown = 1 THEN 1 ELSE 0 END), 0)",
            'popup_clicked' => "COALESCE(SUM(CASE WHEN cl.popup_clicked = 1 THEN 1 ELSE 0 END), 0)",
            'popup_closed' => "COALESCE(SUM(CASE WHEN cl.popup_closed = 1 THEN 1 ELSE 0 END), 0)",
            'pwa_intents' => "COALESCE(SUM(CASE WHEN cl.pwa_intent_at IS NOT NULL THEN 1 ELSE 0 END), 0)",
            'pwa_installs' => "COALESCE(SUM(CASE WHEN cl.pwa_install_at IS NOT NULL THEN 1 ELSE 0 END), 0)",
            'pwa_installs_real' => "COALESCE(SUM(CASE WHEN cl.pwa_install_at IS NOT NULL AND cl.is_bot = 0 THEN 1 ELSE 0 END), 0)",
            'pwa_opens' => "COALESCE(SUM(COALESCE(cl.pwa_open_count, 0)), 0)",
            'pwa_screen_views' => "COALESCE(SUM((SELECT COUNT(*) FROM pwa_screen_views v WHERE v.click_id = cl.id)), 0)",
            'push_subscribed' => "COALESCE(SUM(CASE WHEN cl.push_subscribed_at IS NOT NULL THEN 1 ELSE 0 END), 0)",
            'lp_dwell_cnt' => "COALESCE(SUM(CASE WHEN cl.lp_seconds IS NOT NULL THEN 1 ELSE 0 END), 0)",
            'lp_dwell_sum' => "COALESCE(SUM(cl.lp_seconds), 0)",
            'lp_bounces' => "COALESCE(SUM(CASE WHEN cl.lp_seconds IS NOT NULL AND cl.lp_seconds < 5 THEN 1 ELSE 0 END), 0)",
            'lp_time_cnt' => "COALESCE(SUM(CASE WHEN cl.landing_at IS NOT NULL AND cl.offer_at IS NOT NULL THEN 1 ELSE 0 END), 0)",
            'lp_time_sum' => "COALESCE(SUM(CASE WHEN cl.landing_at IS NOT NULL AND cl.offer_at IS NOT NULL
                               THEN CAST(strftime('%s', cl.offer_at) - strftime('%s', cl.landing_at) AS INTEGER) ELSE 0 END), 0)",
            'scroll_cnt' => "COALESCE(SUM(CASE WHEN cl.lp_scroll IS NOT NULL THEN 1 ELSE 0 END), 0)",
            'scroll_sum' => "COALESCE(SUM(cl.lp_scroll), 0)",
            'cost_micro' => "CAST(ROUND(COALESCE(SUM(cl.cost), 0) * 1000000, 0) AS INTEGER)",
            'fee_micro' => "CAST(ROUND(COALESCE(SUM(cl.fee), 0) * 1000000, 0) AS INTEGER)",
        ];
    }

    /**
     * The conversion-side SELECT expressions in their COMMIT-time form:
     * aggregated over the conversions rows themselves (alias cv) instead of
     * the per-click cva aggregate — same status groups (the report parity
     * stays by construction), same aliases, so the merge keys line up.
     */
    function orbitraStatsFreezeConvExprs(): array
    {
        $statusGroups = orbitraConversionStatusGroups();
        $exprs = [
            'conv_any' => "COUNT(*)",
            'rev_all_micro' => "CAST(ROUND(COALESCE(SUM(cv.payout), 0) * 1000000, 0) AS INTEGER)",
            'conv_popup_any' => "COALESCE(SUM(CASE WHEN cl.popup_clicked = 1 THEN 1 ELSE 0 END), 0)",
            'real_rev_micro' => "CAST(ROUND(COALESCE(SUM(rra.real_rev), 0) * 1000000, 0) AS INTEGER)",
        ];
        foreach ($statusGroups as $g => $statuses) {
            $list = "'" . implode("', '", array_map(
                static fn($s) => str_replace("'", "''", strtolower($s)), $statuses
            )) . "'";
            $m = "LOWER(cv.status) IN ($list)";
            $exprs["conv_{$g}"] = "COALESCE(SUM(CASE WHEN {$m} THEN 1 ELSE 0 END), 0)";
            $exprs["rev_{$g}_micro"] = "CAST(ROUND(COALESCE(SUM(CASE WHEN {$m} THEN cv.payout ELSE 0 END), 0) * 1000000, 0) AS INTEGER)";
        }
        return $exprs;
    }

    function orbitraStatsFreezeConvMetrics(): array
    {
        $statusGroups = orbitraConversionStatusGroups();
        $conv = [
            'conv_any' => "COALESCE(SUM(cva.cnt_any), 0)",
            'rev_all_micro' => "CAST(ROUND(COALESCE(SUM(cva.rev_all), 0) * 1000000, 0) AS INTEGER)",
            'conv_popup_any' => "COALESCE(SUM(CASE WHEN cl.popup_clicked = 1 THEN COALESCE(cva.cnt_any, 0) ELSE 0 END), 0)",
            'real_rev_micro' => "CAST(ROUND(COALESCE(SUM(rra.real_rev), 0) * 1000000, 0) AS INTEGER)",
        ];
        foreach (array_keys($statusGroups) as $g) {
            $conv["conv_{$g}"] = "COALESCE(SUM(cva.cnt_{$g}), 0)";
            $conv["rev_{$g}_micro"] = "CAST(ROUND(COALESCE(SUM(cva.rev_{$g}), 0) * 1000000, 0) AS INTEGER)";
        }
        return $conv;
    }

    /** Table key columns per rollup: [select expression list, GROUP BY list, dim joins]. */
    function orbitraStatsFreezeKeys(string $table): array
    {
        if ($table === 'stats_device') {
            return [
                'cl.campaign_id AS campaign_id, COALESCE(dd.id, 0) AS device_id, COALESCE(dos.id, 0) AS os_id, COALESCE(db.id, 0) AS browser_id',
                'cl.campaign_id, dd.id, dos.id, db.id',
                'LEFT JOIN stat_dim_device dd ON dd.value = cl.device_type
                 LEFT JOIN stat_dim_os dos ON dos.value = cl.os
                 LEFT JOIN stat_dim_browser db ON db.value = cl.browser',
                ['campaign_id', 'device_id', 'os_id', 'browser_id'],
            ];
        }
        return [
            'cl.campaign_id AS campaign_id, COALESCE(cl.stream_id, 0) AS stream_id, COALESCE(cl.offer_id, 0) AS offer_id, COALESCE(cl.landing_id, 0) AS landing_id, COALESCE(cl.source_id, 0) AS source_id, COALESCE(dc.id, 0) AS country_id',
            'cl.campaign_id, cl.stream_id, cl.offer_id, cl.landing_id, cl.source_id, dc.id',
            'LEFT JOIN stat_dim_country dc ON dc.value = cl.country_code',
            ['campaign_id', 'stream_id', 'offer_id', 'landing_id', 'source_id', 'country_id'],
        ];
    }

    /**
     * Step 0: seed the dimension dictionaries with this hour's DISTINCT
     * values, in a micro-transaction. An hour this old is immutable — the
     * same DISTINCT would be produced at commit time — but the READ step's
     * dim joins need the ids to exist before it runs.
     */
    function orbitraStatsSeedHourDictionaries(PDO $pdo, int $hour): void
    {
        $from = gmdate('Y-m-d H:i:s', $hour * 3600);
        $to = gmdate('Y-m-d H:i:s', ($hour + 1) * 3600);
        // The DISTINCT scans are reads (an hour this old is immutable); only
        // the handful of new dictionary VALUES is written, inside a
        // micro-transaction — never a million-row INSERT..SELECT under the
        // WRITER.
        $values = [];
        foreach (['country:country_code', 'device:device_type', 'os:os', 'browser:browser'] as $dimSpec) {
            [$dim, $col] = explode(':', $dimSpec);
            $stmt = $pdo->prepare("SELECT DISTINCT {$col} FROM clicks
                                   WHERE created_at >= ? AND created_at < ? AND {$col} IS NOT NULL AND {$col} != ''");
            $stmt->execute([$from, $to]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $v) {
                $values[] = ["stat_dim_{$dim}", (string) $v];
            }
        }
        if (!$values) {
            return;
        }
        $pdo->beginTransaction();
        try {
            $perDim = [];
            foreach ($values as [$table, $v]) {
                $perDim[$table] ??= $pdo->prepare("INSERT OR IGNORE INTO {$table} (value) VALUES (?)");
                $perDim[$table]->execute([$v]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Step 1: the click-side metrics of one hour, as a plain WAL read.
     * Conversion-side columns come back zero-filled; step 2 fills them.
     *
     * @return array{place: array<string, array>, device: array<string, array>}
     *         rows keyed by the bucket key ("c|s|o|l|src|co" / "c|d|os|b").
     */
    function orbitraStatsReadHourMetrics(PDO $pdo, int $hour): array
    {
        $from = gmdate('Y-m-d H:i:s', $hour * 3600);
        $to = gmdate('Y-m-d H:i:s', ($hour + 1) * 3600);
        $clickMetrics = orbitraStatsFreezeClickMetrics();
        $convZero = array_fill_keys(array_keys(orbitraStatsFreezeConvMetrics()), 0);
        $selects = [];
        foreach ($clickMetrics as $alias => $expr) {
            $selects[] = "{$expr} AS {$alias}";
        }
        $selectSql = implode(', ', $selects);

        $out = [];
        foreach (['stats_place_country', 'stats_device'] as $table) {
            [$keySelect, $groupBy, $dimJoins, $keyCols] = orbitraStatsFreezeKeys($table);
            $sql = "SELECT {$keySelect}, {$selectSql}
                    FROM clicks cl
                    {$dimJoins}
                    WHERE cl.created_at >= ? AND cl.created_at < ?
                    GROUP BY {$groupBy}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$from, $to]);
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                // SQLite numbers come back as strings; the whole row goes
                // straight back into an INSERT on commit — integers now, so
                // the placeholder count and types stay honest.
                $key = implode('|', array_map(static fn($c) => (string) $row[$c], $keyCols));
                foreach ($row as $k => $v) {
                    $row[$k] = (int) $v;
                }
                $rows[$key] = $row + $convZero;
            }
            $out[substr($table, 6) === 'device' ? 'device' : 'place'] = $rows;
        }
        return $out;
    }

    /**
     * Step 2: the short WRITER transaction. BEGIN IMMEDIATE (never a
     * DEFERRED read→write upgrade: a click committing in between would turn
     * this into BUSY_SNAPSHOT), then: the conversion-side metrics of the
     * hour recomputed from the in-transaction snapshot (few rows — only
     * clicks with conversions), merged over the pre-read click-side rows,
     * REPLACE of both rollups in chunks, watermark. The lock is held for
     * the conversion aggregation and the rollup write only — never for the
     * 1–2 M-click aggregation.
     */
    function orbitraStatsCommitFrozenHour(PDO $pdo, int $hour, array $read): void
    {
        $from = gmdate('Y-m-d H:i:s', $hour * 3600);
        $to = gmdate('Y-m-d H:i:s', ($hour + 1) * 3600);

        // Conversion-side aggregation runs OVER THE CONVERSIONS THEMSELVES:
        // every row carries its click's bucket dimensions and hour (the
        // migration-86 denorm, filled at conversion time by the trigger and
        // at rebuild time by the copy), so idx_conversions_click_created
        // answers "this hour's conversions" in milliseconds without ever
        // touching the hour's clicks — a 1–2 M-click hour must not be
        // scanned while the WRITER is held. The click is joined by PRIMARY
        // KEY only for conv_popup_any (the popup_clicked flag lives there).
        $convMetrics = orbitraStatsFreezeConvMetrics();
        $convSelects = [];
        foreach (orbitraStatsFreezeConvExprs() as $alias => $expr) {
            $convSelects[] = "{$expr} AS {$alias}";
        }
        $convSelectSql = implode(', ', $convSelects);

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach (['stats_place_country' => 'place', 'stats_device' => 'device'] as $table => $slot) {
                [$keySelect, $groupBy, $dimJoins, $keyCols] = orbitraStatsFreezeKeys($table);
                // Key expressions over the conversion's denorm snapshot.
                // Bucket keys come from the CLICK (report semantics: a
                // conversion lives in its click's bucket), reached by PRIMARY
                // KEY over the hour's few conversions — never a scan of the
                // hour's clicks. The conversion's denorm snapshot stays a
                // fallback for a dead click row (its hour was frozen long
                // before, so this path never sees one in practice).
                $cvKeySelect = $keySelect;
                $cvGroupBy = $groupBy;
                $cvDimJoins = $dimJoins;
                $rraHour = "(SELECT rr.click_id, SUM(rr.amount) AS real_rev
                             FROM revenue_records rr
                             JOIN conversions cv2 ON cv2.click_id = rr.click_id
                                  AND cv2.click_created_at >= '{$from}' AND cv2.click_created_at < '{$to}'
                             GROUP BY rr.click_id)";
                $sql = "SELECT {$cvKeySelect}, {$convSelectSql}
                        FROM conversions cv
                        JOIN clicks cl ON cl.id = cv.click_id
                        LEFT JOIN {$rraHour} rra ON rra.click_id = cv.click_id
                        {$cvDimJoins}
                        WHERE cv.click_created_at >= ? AND cv.click_created_at < ?
                        GROUP BY {$cvGroupBy}";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$from, $to]);

                $metricCols = array_keys(orbitraStatsFreezeMetrics());
                $zeroClick = array_fill_keys(array_keys(orbitraStatsFreezeClickMetrics()), 0);
                $insertCols = array_merge($keyCols, $metricCols);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $key = implode('|', array_map(static fn($c) => (string) $row[$c], $keyCols));
                    $base = $read[$slot][$key] ?? null;
                    if ($base === null) {
                        // A converting click that appeared after the read —
                        // impossible for a 60-day-old hour in production, but
                        // a manual watermark rollback replays it; start the
                        // bucket from zeroed metrics rather than losing the
                        // conversion.
                        $base = array_combine($keyCols, array_map(static fn($c) => (int) $row[$c], $keyCols))
                            + $zeroClick
                            + array_fill_keys(array_keys($convMetrics), 0);
                    }
                    foreach ($convMetrics as $alias => $_) {
                        $base[$alias] = (int) $row[$alias];
                    }
                    $read[$slot][$key] = $base;
                }

                // REPLACE in chunks: 15 rows × 54 columns = 810 bound
                // parameters, under every SQLITE_MAX_VARIABLE_NUMBER default.
                $colSql = implode(', ', $insertCols);
                // +1 for hour_utc, which bindRow prepends to every row.
                $rowSql = '(' . implode(', ', array_fill(0, count($insertCols) + 1, '?')) . ')';
                $insertStmt = $pdo->prepare(
                    "INSERT OR REPLACE INTO {$table} (hour_utc, {$colSql}) VALUES "
                    . implode(', ', array_fill(0, 15, $rowSql))
                );
                // Rows carry bucket keys + metrics; hour_utc itself is
                // prepended at bind time.
                $bindRow = static fn(array $r): array => array_map(
                    static fn($v) => (int) $v,
                    array_merge([$hour], array_values($r))
                );
                $chunk = [];
                foreach ($read[$slot] as $row) {
                    $chunk[] = $row;
                    if (count($chunk) === 15) {
                        $insertStmt->execute(array_merge(...array_map($bindRow, $chunk)));
                        $chunk = [];
                    }
                }
                if ($chunk) {
                    $partial = $pdo->prepare(
                        "INSERT OR REPLACE INTO {$table} (hour_utc, {$colSql}) VALUES "
                        . implode(', ', array_fill(0, count($chunk), $rowSql))
                    );
                    $partial->execute(array_merge(...array_map($bindRow, $chunk)));
                }
            }

            orbitraStatsSetWatermark($pdo, $hour);
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable $ignored) {
                // The connection is in a bad state; the next tick's BEGIN
                // IMMEDIATE either recovers or reports.
            }
            throw $e;
        }
    }
}

// --- Migration 86: batched rebuild of `conversions` ----------------------------
//
// Copy in committed batches (resumable across ticks via the last copied id),
// then swap in one short transaction: delta copy, DROP the old table, RENAME
// the new one in, recreate the click lookup indexes, restore the
// AUTOINCREMENT sequence past every copied id. FKs are OFF for the swap only:
// children (s2s_postbacks_log) keep pointing at the NAME `conversions`, and
// DROP-before-RENAME never rewrites their clauses. The denorm snapshot is
// taken from clicks in the same statement — every row's click is still
// present because cleanup never deletes ahead of the watermark and the
// watermark never moves before this rebuild is done.
//
// Returns true when the rebuild is complete (flag deleted).

if (!function_exists('orbitraStatsConversionsRebuildStep')) {

    function orbitraStatsConversionsRebuildStep(PDO $pdo, int $batch, float $startedAt, float $budgetSeconds): bool
    {
        $denormCols = ['click_created_at', 'stream_id', 'landing_id', 'source_id',
            'country_code', 'device_type', 'os', 'browser'];

        $setState = static function (string $v) use ($pdo): void {
            $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('conversions_rebuild_state', ?, datetime('now'))
                           ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$v]);
        };
        $getLastId = static fn(): int => (int) $pdo->query("SELECT value FROM settings WHERE key = 'conversions_rebuild_last_id'")->fetchColumn();
        $setLastId = static function (int $v) use ($pdo): void {
            $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('conversions_rebuild_last_id', ?, datetime('now'))
                           ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([(string) $v]);
        };

        $state = (string) $pdo->query("SELECT value FROM settings WHERE key = 'conversions_rebuild_state'")->fetchColumn();
        if ($state === 'pending') {
            // Clone the live table's CREATE (its column set survived a decade
            // of ALTERs), strip the clicks FK clause, add the denorm columns.
            // The clause regex anchors on the LEADING comma and stops at the
            // next , or ) — correct for both own-line and shared-line FK
            // clauses, and never eats the table's closing paren.
            $createSql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'conversions'")->fetchColumn();
            if ($createSql === '') {
                $setState('done');
                return true;
            }
            $newSql = (string) preg_replace(
                '/,\s*FOREIGN\s+KEY\s*\(\s*click_id\s*\)\s+REFERENCES\s+clicks\s*\([^)]*\)[^,)]*/i',
                '',
                $createSql
            );
            $newSql = (string) preg_replace('/CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?"?conversions"?/i', 'CREATE TABLE IF NOT EXISTS conversions_new', $newSql, 1);
            $denorm = implode(",\n            ", array_map(
                static fn($c) => "{$c} " . ($c === 'click_created_at' || str_ends_with($c, '_code') || in_array($c, ['device_type', 'os', 'browser'], true) ? 'TEXT' : 'INTEGER'),
                $denormCols
            ));
            $newSql = (string) preg_replace('/UNIQUE\s*\(\s*click_id\s*,\s*tid\s*\)/i', $denorm . ",\n        UNIQUE(click_id, tid)", $newSql);
            $pdo->exec('DROP TABLE IF EXISTS conversions_new');
            $pdo->exec($newSql);
            $setState('copying');
            $setLastId(0);
            $lastId = 0;
        } else {
            $lastId = $getLastId();
        }

        // Shared column list: what the old table has, denorm appended.
        $oldCols = [];
        foreach ($pdo->query('PRAGMA table_info(conversions)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $oldCols[] = '"' . $c['name'] . '"';
        }
        $copyCols = implode(', ', $oldCols) . ', ' . implode(', ', $denormCols);
        $selectCols = 'c.' . implode(', c.', $oldCols);
        // clicks.created_at is denormalised under its own name; every other
        // column keeps it. A bare click_created_at in the subquery would
        // first try clicks (missing column → hard error here).
        $clickCol = static fn(string $col): string => $col === 'click_created_at' ? 'created_at' : $col;
        $denormSelect = implode(', ', array_map(
            static fn($c) => "(SELECT {$clickCol($c)} FROM clicks WHERE id = c.click_id)",
            $denormCols
        ));
        $copyStmt = $pdo->prepare("INSERT INTO conversions_new ({$copyCols})
                                   SELECT {$selectCols}, {$denormSelect}
                                   FROM conversions c WHERE c.id > ? ORDER BY c.id LIMIT " . (int) $batch);

        while (true) {
            $copyStmt->execute([$lastId]);
            $copied = $copyStmt->rowCount();
            $newLast = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM conversions_new')->fetchColumn();
            if ($newLast > $lastId) {
                $lastId = $newLast;
                $setLastId($lastId);
            }
            if ($copied < $batch) {
                break;   // source exhausted — swap below
            }
            if ((microtime(true) - $startedAt) >= $budgetSeconds) {
                return false;   // continue next tick from $lastId
            }
        }

        // --- the swap: one short transaction -------------------------------
        $oldSeq = 0;
        try {
            $oldSeq = (int) $pdo->query("SELECT seq FROM sqlite_sequence WHERE name = 'conversions'")->fetchColumn();
        } catch (\Throwable $e) {
            // Empty table never wrote a sequence row.
        }
        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            $pdo->beginTransaction();
            $copyStmt->execute([$lastId]);   // the delta written since the last batch
            $newMax = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM conversions_new')->fetchColumn();
            $pdo->exec('DROP TABLE conversions');
            $pdo->exec('ALTER TABLE conversions_new RENAME TO conversions');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_conversions_click ON conversions(click_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_conversions_click_status ON conversions(click_id, status)');
            $seq = max($oldSeq, $newMax);
            $pdo->exec("INSERT OR REPLACE INTO sqlite_sequence (name, seq) VALUES ('conversions', {$seq})");
            $pdo->prepare("DELETE FROM settings WHERE key IN ('conversions_rebuild_state', 'conversions_rebuild_last_id')")->execute();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('PRAGMA foreign_keys = ON');
            throw $e;
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        // DROP TABLE took the old table's triggers with it — re-arm everything
        // (idempotent: CREATE TRIGGER IF NOT EXISTS).
        orbitraStatsRollupSchema($pdo);
        return true;
    }
}
