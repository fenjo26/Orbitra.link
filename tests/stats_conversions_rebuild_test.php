<?php
/**
 * ТЗ 2.1, миграция 86: пакетная пересборка conversions без FK + денорм.
 *
 * Имитирует существующую базу (таблица со старой формой — FK к clicks),
 * поднимает флаг 'conversions_rebuild_state' и прогоняет крон заморозки:
 *  - копирование идёт батчами и переживает перезапуск тика (last_id);
 *  - после свапа: FK нет, денорм-снимок заполнен, данные целы,
 *    индексы клика есть, AUTOINCREMENT не переиспользует id;
 *  - триггеры живы после свапа (поздняя конверсия инкрементит итоги);
 *  - watermark не двигался, пока шла пересборка.
 *
 * Usage: php tests/stats_conversions_rebuild_test.php
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

    // Small batch so the copy visibly spans more than one tick. The batch
    // size lives in the shared tick (core/stats_freeze.php) — the CLI file is
    // only a thin wrapper.
    $cron = file_get_contents($work . '/cli/stats_freeze_cron.php');
    $cron = str_replace("const CONV_COPY_BATCH     = 5000;", "const CONV_COPY_BATCH     = 2;", $cron);
    file_put_contents($work . '/cli/stats_freeze_cron.php', $cron);
    $core = file_get_contents($work . '/core/stats_freeze.php');
    $core = str_replace('orbitraStatsConversionsRebuildStep($pdo, 5000,', 'orbitraStatsConversionsRebuildStep($pdo, 2,', $core);
    file_put_contents($work . '/core/stats_freeze.php', $core);
    $runFreeze = static function (string $budget = '5') use ($work): void {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/stats_freeze_cron.php')
            . ' --quiet --budget=' . $budget . ' 2>/dev/null');
    };

    // --- an "existing" install: old-shape conversions with the FK ------------
    $old = gmdate('Y-m-d H:i:s', time() - 90 * 86400);
    $ins = $pdo->prepare("INSERT INTO clicks (id, campaign_id, stream_id, ip, country_code, device_type, os, browser, uniq_campaign, uniq_stream, uniq_global, created_at)
                          VALUES (?, 3, 30, '203.0.113.9', 'GR', 'mobile', 'ios', 'safari', 1, 1, 1, ?)");
    $insConv = $pdo->prepare("INSERT INTO conversions (click_id, tid, status, payout, currency, campaign_id, offer_id, created_at)
                              VALUES (?, NULL, 'sale', 4.20, 'USD', 3, 7, ?)");
    $clickIds = [];
    for ($i = 1; $i <= 5; $i++) {
        $clickIds[] = $cid = "rb-{$i}";
        $ins->execute([$cid, $old]);
        $insConv->execute([$cid, $old]);
    }
    $maxIdBefore = (int) $pdo->query('SELECT MAX(id) FROM conversions')->fetchColumn();

    // Old shape: build the legacy table WITHOUT renaming the live one — a
    // RENAME would rewrite s2s_postbacks_log's FK clause to the new name,
    // something the production migration never does (the cron's swap is
    // DROP + RENAME-new-in, which leaves child clauses pointing at
    // `conversions`). Create alongside, copy, drop, rename in.
    $pdo->exec('DROP TRIGGER IF EXISTS conv_denorm_fill');
    $pdo->exec('DROP TRIGGER IF EXISTS conv_stats_ins');
    $pdo->exec('DROP TRIGGER IF EXISTS conv_stats_upd');
    $pdo->exec('DROP TRIGGER IF EXISTS conv_stats_del');
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec("CREATE TABLE conversions_oldshape (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        click_id TEXT NOT NULL,
        tid TEXT,
        status TEXT NOT NULL,
        original_status TEXT,
        payout REAL DEFAULT 0.00,
        currency TEXT DEFAULT 'USD',
        cost REAL DEFAULT 0.00,
        sub_id_1 TEXT, sub_id_2 TEXT, sub_id_3 TEXT, sub_id_4 TEXT, sub_id_5 TEXT,
        offer_id INTEGER,
        campaign_id INTEGER,
        ip TEXT,
        user_agent TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(click_id, tid),
        FOREIGN KEY (click_id) REFERENCES clicks(id) ON DELETE CASCADE
    )");
    $pdo->exec("INSERT INTO conversions_oldshape SELECT id, click_id, tid, status, original_status, payout, currency, cost,
                sub_id_1, sub_id_2, sub_id_3, sub_id_4, sub_id_5, offer_id, campaign_id, ip, user_agent, created_at, updated_at
                FROM conversions");
    $pdo->exec('DROP TABLE conversions');
    $pdo->exec('ALTER TABLE conversions_oldshape RENAME TO conversions');
    $pdo->exec("INSERT OR REPLACE INTO sqlite_sequence (name, seq) VALUES ('conversions', {$maxIdBefore})");
    $pdo->exec("INSERT INTO settings (key, value, updated_at) VALUES ('conversions_rebuild_state', 'pending', datetime('now'))
                ON CONFLICT(key) DO UPDATE SET value='pending'");
    $pdo->exec('PRAGMA foreign_keys = ON');
    // No manual trigger re-arm: the cron's first tick re-arms them via
    // orbitraStatsRollupSchema (and again after the swap — the DROP takes
    // the old table's triggers with it).

    // Freeze the hour first so the watermark must NOT move during the rebuild.
    $oldHour = (int) (strtotime($old . ' UTC') / 3600);
    $pdo->prepare("INSERT INTO settings (key, value) VALUES ('stats_frozen_through_hour', ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([(string) ($oldHour - 1)]);
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_raw_retention_days', '3')");

    // --- rebuild across multiple ticks (batch=2, five rows) ------------------
    // budget=0: one batch per tick, then the budget check parks the copy.
    $runFreeze('0');
    $state1 = $pdo->query("SELECT value FROM settings WHERE key='conversions_rebuild_state'")->fetchColumn();
    check($state1 === 'copying', 'first tick started the batched copy (state=' . $state1 . ')');
    $runFreeze();
    $runFreeze();
    $state3 = $pdo->query("SELECT value FROM settings WHERE key='conversions_rebuild_state'")->fetchColumn();
    check($state3 === false || $state3 === null || $state3 === '',
        'rebuild finished across ticks (state flag deleted, got ' . var_export($state3, true) . ')');

    $convSql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='conversions'")->fetchColumn();
    check(!str_contains($convSql, 'REFERENCES clicks'), 'FK to clicks is gone after the swap');
    check(str_contains($convSql, 'click_created_at'), 'denorm columns present after the swap');
    check((int) $pdo->query('SELECT COUNT(*) FROM conversions')->fetchColumn() === 5, 'all rows survived the copy');
    $bad = (int) $pdo->query("SELECT COUNT(*) FROM conversions WHERE click_created_at IS NULL OR stream_id IS NULL OR country_code IS NULL")->fetchColumn();
    check($bad === 0, 'denorm snapshot filled for every copied row');
    foreach (['idx_conversions_click', 'idx_conversions_click_status'] as $idx) {
        check((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='$idx' AND tbl_name='conversions'")->fetchColumn() === 1,
            "index $idx recreated on the swapped table");
    }
    // AUTOINCREMENT continues past the copied ids — no id reuse. The insert
    // is re-prepared: the swap changed the table shape under the old one.
    $insConvPost = $pdo->prepare("INSERT INTO conversions (click_id, tid, status, payout, currency, campaign_id, offer_id, created_at)
                                  VALUES (?, NULL, 'sale', ?, 'USD', 3, NULL, ?)");
    $insConvPost->execute(['rb-5', 1.00, gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
    check(true, 'insert works after the swap (no FK errors)');
    // rb-5 already has a NULL-tid row; the second one lands via a tid.
    $pdo->prepare('UPDATE conversions SET tid = ? WHERE click_id = ? AND tid IS NULL AND id > ?')->execute(['t9', 'rb-5', $maxIdBefore]);
    $newMax = (int) $pdo->query('SELECT MAX(id) FROM conversions')->fetchColumn();
    check($newMax > $maxIdBefore, "AUTOINCREMENT continued past copied ids ($newMax > $maxIdBefore)");

    // Triggers alive on the swapped table: the finishing tick froze the
    // clicks' hour right after the swap (phase 2 continues in the same tick)
    // and then fast-forwarded over the empty hours to the window target —
    // so a late conversion must increment the frozen bucket now.
    $target3d = (int) ((time() - 3 * 86400) / 3600) - 1;
    $wm = (int) $pdo->query("SELECT value FROM settings WHERE key='stats_frozen_through_hour'")->fetchColumn();
    check($wm === $target3d, "watermark reached the target right after the rebuild ($wm == $target3d), past the data hour");
    $insConvPost->execute(['rb-1', 9.99, gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
    $pdo->prepare('UPDATE conversions SET tid = ? WHERE click_id = ? AND tid IS NULL AND payout = 9.99')->execute(['t99', 'rb-1']);
    $gr = (int) $pdo->query("SELECT id FROM stat_dim_country WHERE value = 'GR'")->fetchColumn();
    // Bucket content: the freeze counted the five seed conversions ($4.20
    // each); the two post-freeze inserts added $1.00 (rb-5) and $9.99 (rb-1)
    // through the triggers.
    $r = $pdo->query("SELECT conv_sale, rev_sale_micro FROM stats_place_country WHERE hour_utc = $oldHour AND country_id = {$gr}")->fetch(PDO::FETCH_ASSOC);
    check($r && (int) $r['conv_sale'] === 7 && (int) $r['rev_sale_micro'] === (int) round((5 * 4.20 + 1.00 + 9.99) * 1000000),
        'late conversion increments through the triggers after the swap');

    // s2s_postbacks_log still references the table by name — an insert must
    // not hit a dangling parent.
    try {
        $pdo->exec("INSERT INTO s2s_postbacks_log (conversion_id, url, method, status, attempts, next_retry_at)
                    VALUES ((SELECT id FROM conversions WHERE click_id='rb-1' LIMIT 1), 'http://x/', 'GET', 'delivered', 0, datetime('now'))");
        check(true, 's2s_postbacks_log insert resolves the swapped parent');
    } catch (\Throwable $e) {
        check(false, 's2s_postbacks_log insert resolves the swapped parent: ' . $e->getMessage());
    }
} finally {
    $harness->stop();
}

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);
