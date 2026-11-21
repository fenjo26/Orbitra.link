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

                // Freeze hour $next: dictionaries, both rollups, watermark —
                // one transaction. REPLACE keeps a replayed hour identical.
                $from = $fromHour($next);
                $to = $fromHour($next + 1);
                $pdo->beginTransaction();
                try {
                    foreach (['country:country_code', 'device:device_type', 'os:os', 'browser:browser'] as $dimSpec) {
                        [$dim, $col] = explode(':', $dimSpec);
                        $pdo->prepare("INSERT OR IGNORE INTO stat_dim_{$dim} (value)
                                       SELECT DISTINCT {$col} FROM clicks
                                       WHERE created_at >= ? AND created_at < ? AND {$col} IS NOT NULL AND {$col} != ''")
                            ->execute([$from, $to]);
                    }
                    $pdo->prepare(orbitraStatsFreezePlaceSql())->execute([$next, $from, $to]);
                    $pdo->prepare(orbitraStatsFreezeDeviceSql())->execute([$next, $from, $to]);
                    orbitraStatsSetWatermark($pdo, $next);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    $pdo->rollBack();
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

if (!function_exists('orbitraStatsFreezeMetrics')) {

    function orbitraStatsFreezeMetrics(): array
    {
        $statusGroups = orbitraConversionStatusGroups();
        $metrics = [
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
            'conv_popup_any' => "COALESCE(SUM(CASE WHEN cl.popup_clicked = 1 THEN COALESCE(cva.cnt_any, 0) ELSE 0 END), 0)",
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
            'conv_any' => "COALESCE(SUM(cva.cnt_any), 0)",
            'rev_all_micro' => "CAST(ROUND(COALESCE(SUM(cva.rev_all), 0) * 1000000, 0) AS INTEGER)",
        ];
        foreach (array_keys($statusGroups) as $g) {
            $metrics["conv_{$g}"] = "COALESCE(SUM(cva.cnt_{$g}), 0)";
            $metrics["rev_{$g}_micro"] = "CAST(ROUND(COALESCE(SUM(cva.rev_{$g}), 0) * 1000000, 0) AS INTEGER)";
        }
        $metrics['real_rev_micro'] = "CAST(ROUND(COALESCE(SUM(rra.real_rev), 0) * 1000000, 0) AS INTEGER)";
        return $metrics;
    }

    function orbitraStatsFreezeMetricColumns(): string
    {
        return implode(', ', array_keys(orbitraStatsFreezeMetrics()));
    }

    function orbitraStatsFreezeMetricSelects(): string
    {
        $parts = [];
        foreach (orbitraStatsFreezeMetrics() as $alias => $expr) {
            $parts[] = "{$expr} AS {$alias}";
        }
        return implode(', ', $parts);
    }

    function orbitraStatsFreezeJoins(): string
    {
        // The reports' own aggregate expressions (see the block comment above).
        $cva = orbitraConversionAggregateSql('payout');
        $rra = orbitraRevenueRecordsAggregateSql('amount');
        return "FROM clicks cl
                LEFT JOIN {$cva} cva ON cva.click_id = cl.id
                LEFT JOIN {$rra} rra ON rra.click_id = cl.id";
    }

    function orbitraStatsFreezePlaceSql(): string
    {
        $metrics = orbitraStatsFreezeMetricSelects();
        $cols = orbitraStatsFreezeMetricColumns();
        return "INSERT OR REPLACE INTO stats_place_country
                    (hour_utc, campaign_id, stream_id, offer_id, landing_id, source_id, country_id, {$cols})
                SELECT ?,
                       cl.campaign_id,
                       COALESCE(cl.stream_id, 0),
                       COALESCE(cl.offer_id, 0),
                       COALESCE(cl.landing_id, 0),
                       COALESCE(cl.source_id, 0),
                       COALESCE(dc.id, 0),
                       {$metrics}
                " . orbitraStatsFreezeJoins() . "
                LEFT JOIN stat_dim_country dc ON dc.value = cl.country_code
                WHERE cl.created_at >= ? AND cl.created_at < ?
                GROUP BY cl.campaign_id, cl.stream_id, cl.offer_id, cl.landing_id, cl.source_id, dc.id";
    }

    function orbitraStatsFreezeDeviceSql(): string
    {
        $metrics = orbitraStatsFreezeMetricSelects();
        $cols = orbitraStatsFreezeMetricColumns();
        return "INSERT OR REPLACE INTO stats_device
                    (hour_utc, campaign_id, device_id, os_id, browser_id, {$cols})
                SELECT ?,
                       cl.campaign_id,
                       COALESCE(dd.id, 0),
                       COALESCE(dos.id, 0),
                       COALESCE(db.id, 0),
                       {$metrics}
                " . orbitraStatsFreezeJoins() . "
                LEFT JOIN stat_dim_device dd ON dd.value = cl.device_type
                LEFT JOIN stat_dim_os dos ON dos.value = cl.os
                LEFT JOIN stat_dim_browser db ON db.value = cl.browser
                WHERE cl.created_at >= ? AND cl.created_at < ?
                GROUP BY cl.campaign_id, dd.id, dos.id, db.id";
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
