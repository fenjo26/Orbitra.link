<?php
/**
 * Postbacks that arrived before their click reached the database.
 *
 * A click whose INSERT hit "database is locked" goes to the file spool
 * (var/spool/clicks.log) and cli/click_spool_cron.php lands it within a
 * minute. The visitor already holds the subid by then, so a fast conversion
 * (a registration, an install, a thank-you pixel) can come back while the
 * click is still on disk. postback.php used to answer 404 "Click ID not
 * found" and the conversion was gone.
 *
 * Now such a postback is parked in incoming_postbacks_log with
 * result = 'pending_click' (the row already holds the full request in
 * params_json) and the spool cron replays it through postback.php itself once
 * the click exists — same status mapping, attribution, CAPI and S2S queue as
 * a live postback, nothing re-implemented here.
 *
 * States of a parked row (incoming_postbacks_log.result):
 *   pending_click → processing → recorded | updated | rejected | superseded
 *   pending_click → unmatched      (the click never arrived within the TTL)
 */

/** How long a parked postback waits for its click before it is given up. */
const ORBITRA_PENDING_POSTBACK_TTL = 7200;

/** Spool activity this recent still counts as "a click may be on its way". */
const ORBITRA_PENDING_SPOOL_GRACE = 120;

/**
 * Whether a click that is missing from the database could still be in flight.
 *
 * A click is written (or spooled) before the visitor gets the redirect, so a
 * subid that is not in `clicks` can only be on its way if the spool holds
 * something: the live file, a batch the cron is replaying, or — to cover the
 * instant between the cron taking a batch and finishing it — a spool
 * directory that changed moments ago. With an idle spool the answer stays the
 * honest 404, so a mistyped subid is not acknowledged and an integration bug
 * stays visible to whoever is setting the network up.
 *
 * Three stat calls, and only on the click-not-found path.
 */
function orbitraPostbackClickMayBeInFlight(?string $root = null): bool
{
    $dir = ($root ?? dirname(__DIR__)) . '/var/spool';
    if (!is_dir($dir)) {
        return false;
    }
    clearstatcache(true, $dir);
    clearstatcache(true, $dir . '/clicks.log');
    if (is_file($dir . '/clicks.log') && (int) @filesize($dir . '/clicks.log') > 0) {
        return true;
    }
    if (glob($dir . '/clicks.processing.*.log') ?: []) {
        return true;
    }
    $mtime = (int) @filemtime($dir);
    return $mtime > 0 && (time() - $mtime) <= ORBITRA_PENDING_SPOOL_GRACE;
}

/**
 * Replay parked postbacks whose click has arrived; expire the rest.
 *
 * Called by cli/click_spool_cron.php on every tick, after the spool replay.
 * The cron is single-flight, so a row found in 'processing' here belongs to a
 * replay that died mid-way and is handed back to the queue.
 *
 * Each replay runs in its own PHP process (cli/postback_replay.php):
 * postback.php is a top-level script and cannot be included twice.
 *
 * @return array{replayed:int,waiting:int,unmatched:int,failed:int}
 */
function orbitraProcessPendingPostbacks(PDO $pdo, string $root, float $budgetSeconds = 20.0, int $maxRows = 500): array
{
    $stats = ['replayed' => 0, 'waiting' => 0, 'unmatched' => 0, 'failed' => 0];
    $startedAt = microtime(true);

    try {
        $pdo->exec("UPDATE incoming_postbacks_log SET result = 'pending_click'
                    WHERE result = 'processing'");
        $stmt = $pdo->prepare("
            SELECT id, click_id, CAST(strftime('%s', created_at) AS INTEGER) AS received_ts
            FROM incoming_postbacks_log
            WHERE result = 'pending_click'
            ORDER BY id
            LIMIT ?
        ");
        $stmt->execute([$maxRows]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
    } catch (\Throwable $e) {
        return $stats; // pre-migration or locked database: next tick
    }
    if (!$rows) {
        return $stats;
    }

    $clickStmt = $pdo->prepare('SELECT 1 FROM clicks WHERE id = ? LIMIT 1');
    $claimStmt = $pdo->prepare("UPDATE incoming_postbacks_log SET result = 'processing'
                                WHERE id = ? AND result = 'pending_click'");
    $expireStmt = $pdo->prepare("UPDATE incoming_postbacks_log
                                 SET result = 'unmatched', error = 'Click ID not found in database'
                                 WHERE id = ? AND result = 'pending_click'");
    $resultStmt = $pdo->prepare('SELECT result FROM incoming_postbacks_log WHERE id = ?');
    $releaseStmt = $pdo->prepare("UPDATE incoming_postbacks_log SET result = 'pending_click'
                                  WHERE id = ? AND result = 'processing'");
    $script = $root . '/cli/postback_replay.php';
    $canExec = is_file($script) && function_exists('exec')
        && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $age = time() - (int) $row['received_ts'];
        try {
            $clickStmt->execute([(string) $row['click_id']]);
            $clickExists = (bool) $clickStmt->fetchColumn();
            $clickStmt->closeCursor();

            if (!$clickExists) {
                // WAL-divert safety: when clicks are in the spool (divert was
                // active or the spool is still draining), the click the
                // postback references may not be in the database YET — a
                // replay at 5k rows/minute with 860k rows takes ~3 hours,
                // far past the 2-hour TTL. Don't expire while the spool is
                // non-empty; the postback replays once the click lands.
                $spoolStillDraining = is_file($root . '/var/spool/clicks.log')
                    && filesize($root . '/var/spool/clicks.log') > 0;
                if (!$spoolStillDraining && $age >= ORBITRA_PENDING_POSTBACK_TTL) {
                    $expireStmt->execute([$id]);
                    $stats['unmatched']++;
                } else {
                    $stats['waiting']++;
                }
                continue;
            }
            if (!$canExec || (microtime(true) - $startedAt) >= $budgetSeconds) {
                $stats['waiting']++;
                continue;
            }

            $claimStmt->execute([$id]);
            if ($claimStmt->rowCount() !== 1) {
                continue; // someone else has it
            }
            $output = [];
            $exitCode = 1;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $id . ' 2>&1', $output, $exitCode);

            $resultStmt->execute([$id]);
            $result = (string) $resultStmt->fetchColumn();
            $resultStmt->closeCursor();
            if ($result === 'processing' || $result === 'pending_click') {
                // The replay did not finish (still locked, or it crashed).
                // It stays in the queue until the TTL, then stops as an error
                // rather than as "unmatched" — the click is there.
                if ($age >= ORBITRA_PENDING_POSTBACK_TTL) {
                    $pdo->prepare("UPDATE incoming_postbacks_log
                                   SET result = 'error', error = 'Delayed postback could not be processed'
                                   WHERE id = ?")->execute([$id]);
                } else {
                    $releaseStmt->execute([$id]);
                }
                $stats['failed']++;
                if ($output) {
                    error_log('Orbitra delayed postback ' . $id . ' not processed: ' . substr(implode(' | ', $output), 0, 300));
                }
            } else {
                $stats['replayed']++;
            }
        } catch (\Throwable $e) {
            $stats['failed']++;
            try {
                $releaseStmt->execute([$id]);
            } catch (\Throwable $ignored) {
            }
        }
    }

    return $stats;
}
