<?php
// cli/stats_freeze_cron.php
//
// Cron worker (ТЗ 2.1, stage 1): folds closed hours of raw clicks into the
// hourly rollup tables and advances the freeze watermark.
//
//   stats_frozen_through_hour = the last UTC hour whose clicks are fully
//   represented in stats_place_country / stats_device. Reports read raw
//   clicks at or after the watermark and rollups below it. cli/cleanup_cron.php
//   deletes raw clicks ONLY below the watermark, so nothing is ever deleted
//   before it is frozen (the watermark, never now − window, is the seam —
//   raising the retention window must not resurrect deleted hours).
//
// Per hour, in ONE transaction: dictionary rows for the hour's dimension
// values, INSERT OR REPLACE of both rollup tables, watermark = hour. A crash
// between ticks replays the same hour and writes the same rows (REPLACE over
// identical aggregates); a late conversion after the hour froze is applied by
// the conv_stats_* / rev_stats_* triggers inside the conversion's own
// transaction instead.
//
// Before any freezing this worker finishes the migration-86 rebuild of
// `conversions` (no FK to clicks + denormalised bucket dimensions) in
// batches: the copy joins clicks for the snapshot while every row is still
// present. The watermark does not move until that rebuild is done.
//
// Budget: one tick never runs longer than FREEZE_TICK_BUDGET seconds
// (tests drive short ticks with --budget), and --max-hours caps the number of
// hours frozen in a tick so a test can advance the watermark one hour at a
// time.
//
// Example cron (every 5 minutes):
//   */5 * * * * php /var/www/orbitra/cli/stats_freeze_cron.php >> /var/log/orbitra_stats_freeze.log 2>&1

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';

const FREEZE_TICK_BUDGET  = 55.0;    // seconds per tick, tops (mon201 rule)
const FREEZE_HOUR_BATCH   = 3600;    // clicks re-read per freeze statement max (an hour of the 240k/day install ≈ 10k)
const CONV_COPY_BATCH     = 5000;    // conversions rows copied per rebuild batch
const FREEZE_STATE_FILE   = __DIR__ . '/../var/cache/stats_freeze.json';

$options = getopt('', ['quiet', 'budget:', 'max-hours:']);
$isQuiet = isset($options['quiet']);
$budgetOpt = $options['budget'] ?? getenv('ORBITRA_FREEZE_BUDGET');
$budgetSeconds = is_numeric($budgetOpt) ? max(0.0, (float) $budgetOpt) : FREEZE_TICK_BUDGET;
$maxHours = isset($options['max-hours']) ? max(0, (int) $options['max-hours']) : 0;
$tickStartedAt = microtime(true);

function orbitraFreezeLog(string $msg): void
{
    global $isQuiet;
    if (!$isQuiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }
}

function orbitraFreezeSetting(PDO $pdo, string $key, string $default): string
{
    try {
        $value = $pdo->query('SELECT value FROM settings WHERE key = ' . $pdo->quote($key) . ' LIMIT 1')->fetchColumn();
    } catch (\Throwable $e) {
        return $default;
    }
    return $value === false || $value === null || $value === '' ? $default : (string) $value;
}

function orbitraFreezeSetWatermark(PDO $pdo, int $hour): void
{
    $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('stats_frozen_through_hour', ?, datetime('now'))
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')")
        ->execute([(string) $hour]);
}

function orbitraFreezeWriteState(array $state): void
{
    $dir = dirname(FREEZE_STATE_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents(FREEZE_STATE_FILE, json_encode($state + ['at' => time()]));
}

// --- Single-instance lock -----------------------------------------------------
if (!is_dir(__DIR__ . '/../var/locks')) {
    @mkdir(__DIR__ . '/../var/locks', 0777, true);
}
$lockFile = __DIR__ . '/../var/locks/stats_freeze.lock';
$fp = @fopen($lockFile, 'c+');
if ($fp) {
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        exit(0);
    }
    clearstatcache(true, $lockFile);
    if (time() - (int) (filemtime($lockFile) ?: 0) > 3600) {
        // Stale lock from a dead worker: owned now, carry on.
    }
    ftruncate($fp, 0);
    fwrite($fp, (string) time());
}

$state = ['phase' => 'start', 'watermark' => 0, 'target' => 0, 'pending_rebuild' => false];

try {
    // The rollup schema is idempotent; re-ensuring here makes a half-locked
    // migration 87 self-heal on the first tick.
    orbitraStatsRollupSchema($pdo);
    require_once __DIR__ . '/../core/ReportMetrics.php';

    $rawDays = max(1, min(3650, (int) orbitraFreezeSetting($pdo, 'stats_raw_retention_days', '60')));
    $watermark = (int) orbitraFreezeSetting($pdo, 'stats_frozen_through_hour', '0');
    $target = (int) ((time() - $rawDays * 86400) / 3600) - 1;   // last fully closed hour outside the raw window

    // --- Phase 1: conversions rebuild (migration 86) --------------------------
    $rebuildState = orbitraFreezeSetting($pdo, 'conversions_rebuild_state', '');
    if ($rebuildState !== '' && $rebuildState !== 'done') {
        $state['phase'] = 'conversions_rebuild';
        $state['pending_rebuild'] = true;
        try {
            $done = orbitraConversionsRebuildStep($pdo, CONV_COPY_BATCH, $tickStartedAt, $budgetSeconds);
        } catch (\Throwable $e) {
            // A transient failure (lock, disk) defers to the next tick — the
            // watermark stays parked, so nothing is frozen or purged on a
            // half-rebuilt conversions table.
            orbitraFreezeWriteState($state + ['watermark' => $watermark, 'target' => $target]);
            orbitraFreezeLog('conversions rebuild deferred: ' . $e->getMessage());
            exit(0);
        }
        if (!$done) {
            orbitraFreezeWriteState($state + ['watermark' => $watermark, 'target' => $target]);
            orbitraFreezeLog('conversions rebuild continues next tick (watermark parked at ' . $watermark . ')');
            exit(0);
        }
        orbitraFreezeLog('conversions rebuild finished: no FK to clicks, denorm snapshot copied');
    }

    // --- Phase 2: freeze hours -------------------------------------------------
    $state['phase'] = 'freeze';
    $state['pending_rebuild'] = false;
    $hoursDone = 0;
    $fromHour = static fn(int $h): string => gmdate('Y-m-d H:i:s', $h * 3600);

    while ($watermark < $target
        && ($maxHours === 0 || $hoursDone < $maxHours)
        && (microtime(true) - $tickStartedAt) < $budgetSeconds) {

        // Empty-hour fast-forward: hours without clicks are frozen by
        // definition (an empty rollup), so the watermark jumps to the hour
        // before the oldest click still ahead — never walking decades of
        // nothing after the first freeze or on a fresh install.
        $next = $watermark + 1;
        $probe = $pdo->prepare('SELECT MIN(created_at) FROM clicks WHERE created_at >= ?');
        $probe->execute([$fromHour($next)]);
        $oldest = $probe->fetchColumn();
        if ($oldest === null || $oldest === false) {
            // No raw clicks ahead of the watermark at all: everything up to
            // the target is empty.
            $pdo->beginTransaction();
            orbitraFreezeSetWatermark($pdo, $target);
            $pdo->commit();
            $watermark = $target;
            break;
        }
        $oldestHour = (int) (strtotime((string) $oldest . ' UTC') / 3600);
        if ($oldestHour > $next) {
            $jump = min($oldestHour - 1, $target);
            if ($jump > $watermark) {
                // An empty-hour jump is free: --max-hours caps actual
                // hour freezes, not the watermark arithmetic.
                $pdo->beginTransaction();
                orbitraFreezeSetWatermark($pdo, $jump);
                $pdo->commit();
                $watermark = $jump;
            }
            continue;
        }

        // Freeze hour $next: dictionaries, both rollups, watermark — one
        // transaction. REPLACE keeps a replayed hour byte-identical.
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
            $pdo->prepare(orbitraFreezePlaceSql())->execute([$next, $from, $to]);
            $pdo->prepare(orbitraFreezeDeviceSql())->execute([$next, $from, $to]);
            orbitraFreezeSetWatermark($pdo, $next);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            orbitraFreezeLog('freeze of hour ' . $next . ' deferred: ' . $e->getMessage());
            break;
        }
        $watermark = $next;
        $hoursDone++;
    }

    orbitraFreezeWriteState($state + ['watermark' => $watermark, 'target' => $target]);
    orbitraFreezeLog('tick done: watermark=' . $watermark . ' target=' . $target . ' hours=' . $hoursDone);
} finally {
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
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

function orbitraFreezeMetrics(): array
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

function orbitraFreezeMetricColumns(): string
{
    return implode(', ', array_keys(orbitraFreezeMetrics()));
}

function orbitraFreezeMetricSelects(): string
{
    $parts = [];
    foreach (orbitraFreezeMetrics() as $alias => $expr) {
        $parts[] = "{$expr} AS {$alias}";
    }
    return implode(', ', $parts);
}

function orbitraFreezeJoins(): string
{
    // The reports' own aggregate expressions (see the block comment above).
    $cva = orbitraConversionAggregateSql('payout');
    $rra = orbitraRevenueRecordsAggregateSql('amount');
    return "FROM clicks cl
            LEFT JOIN {$cva} cva ON cva.click_id = cl.id
            LEFT JOIN {$rra} rra ON rra.click_id = cl.id";
}

function orbitraFreezePlaceSql(): string
{
    $metrics = orbitraFreezeMetricSelects();
    $cols = orbitraFreezeMetricColumns();
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
            " . orbitraFreezeJoins() . "
            LEFT JOIN stat_dim_country dc ON dc.value = cl.country_code
            WHERE cl.created_at >= ? AND cl.created_at < ?
            GROUP BY cl.campaign_id, cl.stream_id, cl.offer_id, cl.landing_id, cl.source_id, dc.id";
}

function orbitraFreezeDeviceSql(): string
{
    $metrics = orbitraFreezeMetricSelects();
    $cols = orbitraFreezeMetricColumns();
    return "INSERT OR REPLACE INTO stats_device
                (hour_utc, campaign_id, device_id, os_id, browser_id, {$cols})
            SELECT ?,
                   cl.campaign_id,
                   COALESCE(dd.id, 0),
                   COALESCE(dos.id, 0),
                   COALESCE(db.id, 0),
                   {$metrics}
            " . orbitraFreezeJoins() . "
            LEFT JOIN stat_dim_device dd ON dd.value = cl.device_type
            LEFT JOIN stat_dim_os dos ON dos.value = cl.os
            LEFT JOIN stat_dim_browser db ON db.value = cl.browser
            WHERE cl.created_at >= ? AND cl.created_at < ?
            GROUP BY cl.campaign_id, dd.id, dos.id, db.id";
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

function orbitraConversionsRebuildStep(PDO $pdo, int $batch, float $tickStartedAt, float $budgetSeconds): bool
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
        // Clone the live table's CREATE (its column set survived a decade of
        // ALTERs), strip the clicks FK clause, add the denorm columns. The
        // clause regex anchors on the LEADING comma and stops at the next ,
        // or ) — correct for both own-line and shared-line FK clauses, and
        // never eats the table's closing paren.
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
    // column keeps it. A bare click_created_at in the subquery would first
    // try clicks (missing column → hard error here).
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
        if ((microtime(true) - $tickStartedAt) >= $budgetSeconds) {
            return false;   // continue next tick from $lastId
        }
    }

    // --- the swap: one short transaction -----------------------------------
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
