<?php
// cli/push_cron.php
//
// Push delivery worker (phase 4): drains push_queue and scans for new events
// to enqueue. No composer — sends via core/PushSender.php (RFC 8291/8292).
//
// Run every 5 minutes:
//   *\/5 * * * * php /var/www/orbitra/cli/push_cron.php >> /var/log/orbitra_push.log 2>&1
//
// Aging is built into delivery: an endpoint answering 404/410 marks the
// subscription is_active = 0, so there is no separate cleanup cron.
// "The queue is the cursor": due rows (pending AND run_at <= now) are taken
// in batches, one HTTP call each; anything this run does not finish waits
// for the next one. The event triggers keep their own cursor in settings
// ('push_trigger_cursor' = {"clicks":N,"conversions":M} — last processed
// rowid per table).

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/PushBase.php';
require_once __DIR__ . '/../core/PushSender.php';
require_once __DIR__ . '/../core/PushMacros.php';
require_once __DIR__ . '/../core/PushQueue.php';

const PUSH_BATCH_SIZE      = 300;  // queue rows per batch
const PUSH_TIME_BUDGET     = 240;  // seconds of sending per run, then yield to the next
const PUSH_MAX_ATTEMPTS    = 5;    // poison-row guard: a row requeued this often dies
const PUSH_RETRY_BACKOFF   = 300;  // seconds before a requeued row is due again
const PUSH_TRIGGER_SCAN    = 2000; // clicks/conversions rows scanned per run

$options = getopt('', ['quiet']);
$isQuiet = isset($options['quiet']);

function orbitraPushLog(string $msg): void
{
    global $isQuiet;
    if (!$isQuiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }
}

// --- Single-instance lock ----------------------------------------------------
$lockFile = __DIR__ . '/../var/locks/push_cron.lock';
if (!is_dir(dirname($lockFile))) {
    @mkdir(dirname($lockFile), 0777, true);
}
$fp = @fopen($lockFile, 'c+');
if ($fp) {
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        exit(0); // another worker is already draining the queue
    }
}

set_time_limit(360);
$startedAt = time();

$delivered = 0;
$failed    = 0;
$requeued  = 0;
$aged      = 0;
$enqueued  = 0;

try {
    // ------------------------------------------------------------------
    // 1. Deliver due queue rows.
    // ------------------------------------------------------------------
    while (time() - $startedAt < PUSH_TIME_BUDGET) {
        $stmt = $pdo->prepare("
            SELECT q.id            AS queue_id,
                   q.attempts      AS attempts,
                   m.id            AS message_id,
                   m.title, m.text, m.icon_url, m.link_url,
                   s.id            AS sub_id,
                   s.click_id, s.endpoint, s.p256dh, s.auth, s.is_active
            FROM push_queue q
            JOIN push_messages m ON m.id = q.message_id
            LEFT JOIN push_subscriptions s ON s.id = q.subscription_id
            WHERE q.status = 'pending' AND q.run_at <= datetime('now')
            ORDER BY q.id
            LIMIT " . PUSH_BATCH_SIZE . "
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            break;
        }

        $updQueue = $pdo->prepare("UPDATE push_queue SET status = ?, attempts = attempts + 1, run_at = ?, last_code = ? WHERE id = ?");
        $insSend  = $pdo->prepare("INSERT INTO push_sends (message_id, subscription_id, ok, response_code) VALUES (?, ?, ?, ?)");
        $killSub  = $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE id = ?");

        foreach ($rows as $row) {
            if (time() - $startedAt >= PUSH_TIME_BUDGET) {
                break; // finish the batch next run — the queue keeps the position
            }

            // Dead (or deleted) subscription: nothing to send, no retry.
            if (empty($row['sub_id']) || (int) $row['is_active'] !== 1) {
                $updQueue->execute(['failed', null, null, $row['queue_id']]);
                $failed++;
                continue;
            }

            $result = PushSender::send($pdo, $row, $row);

            if ($result['ok']) {
                $updQueue->execute(['done', null, $result['code'], $row['queue_id']]);
                $insSend->execute([$row['message_id'], $row['sub_id'], 1, $result['code']]);
                $delivered++;
                continue;
            }

            if ($result['dead']) {
                // 404/410 — the endpoint is gone for good: age the base.
                $killSub->execute([$row['sub_id']]);
                $updQueue->execute(['failed', null, $result['code'], $row['queue_id']]);
                $insSend->execute([$row['message_id'], $row['sub_id'], 0, $result['code']]);
                $failed++;
                $aged++;
                continue;
            }

            if ($result['retryable'] && (int) $row['attempts'] + 1 < PUSH_MAX_ATTEMPTS) {
                // 429 honors Retry-After (min one backoff step); 5xx and
                // network errors just come back on the next run.
                $wait = $result['retry_after'] !== null
                    ? max(PUSH_RETRY_BACKOFF, (int) $result['retry_after'])
                    : PUSH_RETRY_BACKOFF;
                $updQueue->execute(['pending', date('Y-m-d H:i:s', time() + $wait), $result['code'], $row['queue_id']]);
                $requeued++;
                continue;
            }

            // Hard rejection (4xx) or attempt budget exhausted.
            $updQueue->execute(['failed', null, $result['code'], $row['queue_id']]);
            $failed++;
        }
    }

    // ------------------------------------------------------------------
    // 2. Event triggers: new installs and conversions → event messages.
    // ------------------------------------------------------------------
    $enqueued = orbitraPushScanTriggers($pdo);

    // Last-run stamp for the panel's queue health card (push_queue_list).
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES ('push_cron_last_ping_at', ?)
                               ON CONFLICT(key) DO UPDATE SET value = excluded.value");
        $stmt->execute([date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
        // cosmetic only
    }

    orbitraPushLog("push_cron: delivered=$delivered failed=$failed requeued=$requeued aged=$aged enqueued=$enqueued");
} catch (\Throwable $e) {
    orbitraPushLog('push_cron ERROR: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
} finally {
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Scan clicks/conversions newer than the stored cursor and enqueue the active
 * event messages for the push subscribers behind them. The cursor is the last
 * processed ROWID per table (a JSON settings row — two sequences, one key).
 */
function orbitraPushScanTriggers(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'push_trigger_cursor'");
    $stmt->execute();
    $cursor = json_decode((string) $stmt->fetchColumn(), true);
    $clicksPos = (int) ($cursor['clicks'] ?? 0);
    $convPos = (int) ($cursor['conversions'] ?? 0);

    // click_id → subscription ids (a click may own several endpoints).
    $subLookup = $pdo->prepare("SELECT id FROM push_subscriptions WHERE click_id = ? AND is_active = 1");
    $enqueued = 0;

    // --- installs: clicks.pwa_install_at on rows we have not seen yet ------
    // "rowid AS rid": when the table's INTEGER PRIMARY KEY aliases the rowid
    // (conversions.id does), SQLite hands the column back under the alias
    // name, so an associative read of $row['rowid'] silently yields null.
    $rows = $pdo->query("SELECT rowid AS rid, id, pwa_install_at FROM clicks WHERE rowid > $clicksPos ORDER BY rowid ASC LIMIT " . PUSH_TRIGGER_SCAN)->fetchAll(PDO::FETCH_ASSOC);
    $installClickIds = [];
    $lastClick = $clicksPos;
    foreach ($rows as $r) {
        $lastClick = (int) $r['rid'];
        if (!empty($r['pwa_install_at'])) {
            $installClickIds[] = $r['id'];
        }
    }

    // --- conversions: lead/sale events by the shared status groups ---------
    $groups = orbitraConversionStatusGroups();
    $convRows = $pdo->query("SELECT rowid AS rid, click_id, status FROM conversions WHERE rowid > $convPos ORDER BY rowid ASC LIMIT " . PUSH_TRIGGER_SCAN)->fetchAll(PDO::FETCH_ASSOC);
    $eventClickIds = ['lead' => [], 'sale' => []];
    $lastConv = $convPos;
    foreach ($convRows as $r) {
        $lastConv = (int) $r['rid'];
        $status = strtolower((string) $r['status']);
        if (in_array($status, $groups['sale'], true)) {
            $eventClickIds['sale'][] = $r['click_id'];
        } elseif (in_array($status, $groups['hold'], true)) {
            $eventClickIds['lead'][] = $r['click_id'];
        }
        // registration/deposit/rejected/trash are NOT push events in the MVP.
    }

    // --- enqueue install messages ------------------------------------------
    if ($installClickIds !== []) {
        foreach ($installClickIds as $clickId) {
            $subLookup->execute([$clickId]);
            $subIds = array_map('intval', $subLookup->fetchAll(PDO::FETCH_COLUMN));
            foreach (orbitraPushEventMessages($pdo, 'install') as $message) {
                $enqueued += orbitraPushEnqueueForSubscriptions($pdo, $message, $subIds);
            }
        }
    }

    // --- enqueue lead/sale messages -----------------------------------------
    foreach (['lead', 'sale'] as $event) {
        if ($eventClickIds[$event] === []) {
            continue;
        }
        $byClick = [];
        foreach (array_unique($eventClickIds[$event]) as $clickId) {
            $subLookup->execute([$clickId]);
            $ids = array_map('intval', $subLookup->fetchAll(PDO::FETCH_COLUMN));
            if ($ids) {
                $byClick[$clickId] = $ids;
            }
        }
        if (!$byClick) {
            continue;
        }
        foreach (orbitraPushEventMessages($pdo, $event) as $message) {
            $allIds = [];
            foreach ($byClick as $ids) {
                $allIds = array_merge($allIds, $ids);
            }
            $enqueued += orbitraPushEnqueueForSubscriptions($pdo, $message, $allIds);
        }
    }

    // Advance the cursor only after everything above survived.
    $stmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES ('push_trigger_cursor', ?)
                           ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $stmt->execute([json_encode(['clicks' => $lastClick, 'conversions' => $lastConv])]);
    return $enqueued;
}
