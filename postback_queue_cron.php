<?php
// postback_queue_cron.php
// Cron worker: delivers queued outbound S2S postbacks with exponential backoff retry.
//
// A row is enqueued by postback.php with status='pending'. This worker picks due rows
// (next_retry_at <= now, attempts < MAX_ATTEMPTS), performs the HTTP call, and on a
// non-2xx/3xx response schedules the next attempt with growing delay. Rows that exhaust
// MAX_ATTEMPTS are marked status='failed' and kept for inspection in the S2S logs UI.
//
// Example cron (every minute):
// * * * * * php /var/www/orbitra/postback_queue_cron.php >> /var/log/orbitra_postback_queue.log 2>&1

require_once __DIR__ . '/config.php';

function orbitraPqSetSetting(PDO $pdo, string $key, string $value): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO settings (key, value, updated_at)
            VALUES (?, ?, datetime('now'))
            ON CONFLICT(key) DO UPDATE SET
                value = excluded.value,
                updated_at = datetime('now')
        ");
        $stmt->execute([$key, $value]);
    } catch (Throwable $e) {
        // Non-fatal: worker should still attempt delivery.
    }
}

function orbitraPqLog(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

// --- Configuration -----------------------------------------------------------
// A row is delivered on attempt 1, then retried after each entry of PQ_BACKOFF_SECONDS.
// So MAX_ATTEMPTS must be count(PQ_BACKOFF_SECONDS) + 1 for the whole schedule — including
// the final 24h wait — to actually be used before the row is marked dead.
const PQ_BACKOFF_SECONDS = [60, 300, 1800, 7200, 86400]; // 1m, 5m, 30m, 2h, 24h
const PQ_MAX_ATTEMPTS   = 6;       // 1 initial attempt + 5 retries
const PQ_BATCH_SIZE     = 50;      // rows per run
const PQ_HTTP_TIMEOUT   = 8;       // seconds per delivery attempt
// A row claimed as 'in_flight' longer than this is assumed to belong to a worker that
// died mid-delivery (fatal error, OOM, cron kill) and is returned to the queue.
const PQ_INFLIGHT_STALE_SECONDS = 600; // 10 minutes

// --- Single-instance lock ----------------------------------------------------
$lockFile = __DIR__ . '/var/locks/postback_queue.lock';
$lockTtlSeconds = 300;

if (!is_dir(__DIR__ . '/var/locks')) {
    @mkdir(__DIR__ . '/var/locks', 0777, true);
}

$fp = @fopen($lockFile, 'c+');
if ($fp) {
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        // Another worker running.
        exit(0);
    }
    $st = fstat($fp);
    if ($st && isset($st['mtime']) && (time() - (int) $st['mtime']) > $lockTtlSeconds) {
        ftruncate($fp, 0);
    }
}

$processed = 0;
$delivered = 0;
$requeued  = 0;
$failed    = 0;
// Defined outside the try so the catch block can always reference it.
$ts = date('Y-m-d H:i:s');

try {
    orbitraPqSetSetting($pdo, 'postback_queue_last_ping_at', $ts);

    // Allow disabling the worker from UI while keeping cron in place.
    $enabled = '1';
    try {
        $val = $pdo->query("SELECT value FROM settings WHERE key='postback_queue_enabled'")->fetchColumn();
        if (is_string($val) && $val !== '') {
            $enabled = $val;
        }
    } catch (Throwable $e) {
        // Ignore.
    }
    if ($enabled === '0') {
        orbitraPqLog("postback_queue: disabled via settings");
        exit(0);
    }

    // Reclaim rows abandoned by a crashed worker. Without this a row that was claimed
    // as 'in_flight' and never finished would be invisible to the due-query forever,
    // because that query only looks at 'pending'.
    try {
        // attempts is incremented so a "poison" row that reliably kills the worker
        // burns through its budget instead of looping forever.
        $reap = $pdo->prepare("
            UPDATE s2s_postbacks_log
            SET status = 'pending',
                attempts = attempts + 1,
                updated_at = datetime('now'),
                last_error = 'worker died mid-delivery; requeued'
            WHERE status = 'in_flight'
              AND COALESCE(updated_at, created_at) <= datetime('now', ?)
        ");
        $reap->execute(['-' . PQ_INFLIGHT_STALE_SECONDS . ' seconds']);
        $reaped = $reap->rowCount();
        if ($reaped > 0) {
            orbitraPqLog("postback_queue: requeued $reaped stale in_flight row(s)");
        }

        // Retire pending rows that are out of attempts. Without this they would linger
        // as 'pending' forever, invisible to the due-query and misleading in the UI.
        $retire = $pdo->prepare("
            UPDATE s2s_postbacks_log
            SET status = 'failed', updated_at = datetime('now')
            WHERE status = 'pending' AND attempts >= " . PQ_MAX_ATTEMPTS . "
        ");
        $retire->execute();
    } catch (Throwable $e) {
        orbitraPqLog('postback_queue: reaper failed: ' . $e->getMessage());
    }

    // Select due rows. Claiming is done by flipping status to 'in_flight' inside a
    // transaction so a parallel worker cannot pick the same row.
    $dueStmt = $pdo->prepare("
        SELECT id, conversion_id, url, method, attempts
        FROM s2s_postbacks_log
        WHERE status = 'pending'
          AND next_retry_at <= datetime('now')
          AND attempts < " . PQ_MAX_ATTEMPTS . "
        ORDER BY next_retry_at ASC
        LIMIT " . PQ_BATCH_SIZE . "
    ");
    $dueStmt->execute();
    $rows = $dueStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        orbitraPqSetSetting($pdo, 'postback_queue_last_checked_at', $ts);
        exit(0);
    }

    // Every transition stamps updated_at so the reaper above can tell a live delivery
    // from an abandoned one.
    $claimStmt = $pdo->prepare("UPDATE s2s_postbacks_log SET status = 'in_flight', updated_at = datetime('now') WHERE id = ? AND status = 'pending'");
    $doneStmt  = $pdo->prepare("UPDATE s2s_postbacks_log SET status = 'delivered', http_code = ?, status_code = ?, last_error = NULL, attempts = attempts + 1, updated_at = datetime('now') WHERE id = ?");
    $retryStmt = $pdo->prepare("UPDATE s2s_postbacks_log SET status = 'pending', attempts = attempts + 1, next_retry_at = datetime('now', ?), http_code = ?, status_code = ?, last_error = ?, updated_at = datetime('now') WHERE id = ?");
    $deadStmt  = $pdo->prepare("UPDATE s2s_postbacks_log SET status = 'failed', attempts = attempts + 1, http_code = ?, status_code = ?, last_error = ?, updated_at = datetime('now') WHERE id = ?");

    foreach ($rows as $row) {
        // Claim the row.
        $pdo->beginTransaction();
        try {
            $claimStmt->execute([$row['id']]);
            $claimed = $claimStmt->rowCount() > 0;
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            continue;
        }
        if (!$claimed) {
            continue; // taken by another worker
        }
        $processed++;

        $url    = (string) $row['url'];
        $method = (string) $row['method'] === 'POST' ? 'POST' : 'GET';
        $attempt = (int) $row['attempts'];

        // SSRF re-check right before delivery (DNS may have changed since enqueue).
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? '';
        $ssrfBlocked = false;
        $dnsFailed   = false;
        if ($host) {
            // gethostbyname() returns the input unchanged both for an IP literal and on
            // resolution failure, so do NOT skip the check when $ip === $host — that
            // would wave through http://127.0.0.1/.
            $ip = @gethostbyname($host);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                // Resolved (or already an IP literal): block private/reserved targets.
                $ssrfBlocked = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
            } else {
                // Could not resolve. Likely transient — retry rather than kill the row.
                $dnsFailed = true;
            }
        }

        if ($dnsFailed) {
            $nextAttempt = (int) $row['attempts'] + 1;
            $errMsg = 'DNS resolution failed for ' . $host;
            if ($nextAttempt >= PQ_MAX_ATTEMPTS) {
                $deadStmt->execute([0, 0, $errMsg, $row['id']]);
                $failed++;
            } else {
                $backoff = PQ_BACKOFF_SECONDS[min($nextAttempt - 1, count(PQ_BACKOFF_SECONDS) - 1)];
                $retryStmt->execute(['+' . $backoff . ' seconds', 0, 0, $errMsg, $row['id']]);
                $requeued++;
            }
            orbitraPqLog("postback #{$row['id']} $errMsg");
            continue;
        }

        if ($ssrfBlocked) {
            // Treat as permanent failure — do not retry a blocked target.
            $deadStmt->execute([0, 0, 'SSRF: target resolves to a private/reserved IP', $row['id']]);
            $failed++;
            orbitraPqLog("postback #{$row['id']} SSRF-blocked -> failed");
            continue;
        }

        // Perform the HTTP call.
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, PQ_HTTP_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

        if ($method === 'POST') {
            // Move query-string fields into the POST body so partners receive them in
            // the request body (the common S2S convention) instead of an empty body.
            curl_setopt($ch, CURLOPT_POST, true);
            $parsedForBody = parse_url($url);
            parse_str($parsedForBody['query'] ?? '', $bodyFields);
            if (!empty($bodyFields)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($bodyFields));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            }
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $success = ($httpCode >= 200 && $httpCode < 400) && $curlErr === '';

        if ($success) {
            $doneStmt->execute([$httpCode, $httpCode, $row['id']]);
            $delivered++;
            orbitraPqLog("postback #{$row['id']} delivered (HTTP $httpCode)");
        } else {
            // Determine next state: retry or give up.
            $nextAttempt = $attempt + 1;
            $errMsg = $curlErr !== '' ? $curlErr : "HTTP $httpCode";
            if ($nextAttempt >= PQ_MAX_ATTEMPTS) {
                $deadStmt->execute([$httpCode, $httpCode, $errMsg, $row['id']]);
                $failed++;
                orbitraPqLog("postback #{$row['id']} FAILED after $nextAttempt attempts: $errMsg");
            } else {
                $backoff = PQ_BACKOFF_SECONDS[min($nextAttempt - 1, count(PQ_BACKOFF_SECONDS) - 1)];
                $retryStmt->execute([
                    '+' . $backoff . ' seconds',
                    $httpCode,
                    $httpCode,
                    $errMsg,
                    $row['id'],
                ]);
                $requeued++;
                orbitraPqLog("postback #{$row['id']} retry #$nextAttempt in {$backoff}s: $errMsg");
            }
        }
    }

    // Health/state for the UI.
    orbitraPqSetSetting($pdo, 'postback_queue_last_checked_at', $ts);
    orbitraPqSetSetting($pdo, 'postback_queue_last_run_processed', (string) $processed);
    orbitraPqSetSetting($pdo, 'postback_queue_last_run_delivered', (string) $delivered);
    orbitraPqSetSetting($pdo, 'postback_queue_last_run_requeued', (string) $requeued);
    orbitraPqSetSetting($pdo, 'postback_queue_last_run_failed', (string) $failed);

    orbitraPqLog("postback_queue run: processed=$processed delivered=$delivered requeued=$requeued failed=$failed");
} catch (Throwable $e) {
    orbitraPqSetSetting($pdo, 'postback_queue_last_error', $e->getMessage());
    echo "[$ts] postback_queue error: " . $e->getMessage() . "\n";
} finally {
    if (isset($fp) && is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
