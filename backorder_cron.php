<?php
// backorder_cron.php
// Cron worker: checks exactly one "due" domain per run.
// Due = never checked OR last_checked_at older than backorder_check_interval_sec (default 900s).
//
// Example cron (every 3 minutes):
// */3 * * * * php /var/www/orbitra/backorder_cron.php >> /var/log/orbitra_backorder.log 2>&1

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/backorder.php';

function orbitraCronSetSetting(PDO $pdo, string $key, string $value): void
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
        // Non-fatal: cron worker should still attempt main work.
    }
}

// Tweakable defaults for personal use.
$lockFile = __DIR__ . '/var/locks/backorder_cron.lock';
$lockTtlSeconds = 300; // safety in case of stale locks

if (!is_dir(__DIR__ . '/var/locks')) {
    @mkdir(__DIR__ . '/var/locks', 0777, true);
}

// Best-effort single-instance lock.
$fp = @fopen($lockFile, 'c+');
if ($fp) {
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        // Another worker running.
        exit(0);
    }
    // If lock file too old, truncate to keep it small.
    $st = fstat($fp);
    if ($st && isset($st['mtime']) && (time() - (int) $st['mtime']) > $lockTtlSeconds) {
        ftruncate($fp, 0);
    }
}

try {
    $ts = date('Y-m-d H:i:s');
    orbitraCronSetSetting($pdo, 'backorder_cron_last_ping_at', $ts);

    // Allow disabling the worker from UI while keeping cron in place.
    $enabled = '1';
    try {
        $val = $pdo->query("SELECT value FROM settings WHERE key='backorder_cron_enabled'")->fetchColumn();
        if (is_string($val) && $val !== '') {
            $enabled = $val;
        }
    } catch (Throwable $e) {
        // Ignore.
    }

    if ($enabled === '0') {
        echo "[$ts] backorder: disabled via settings\n";
        exit(0);
    }

    // Re-check intervals (seconds). Stored in settings; no schema changes needed.
    // Keep registered checks sparse to avoid burning external rate limits (notably .gr web UI).
    $intervalSec = 900; // default
    $intervalRegisteredSec = 86400;
    $intervalRateLimitedSec = 3600;
    $intervalErrorSec = 1800;
    try {
        $v = $pdo->query("SELECT value FROM settings WHERE key='backorder_check_interval_sec'")->fetchColumn();
        if (is_string($v) && $v !== '' && preg_match('/^\\d+$/', $v)) {
            $intervalSec = max(15, (int) $v);
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $v = $pdo->query("SELECT value FROM settings WHERE key='backorder_check_interval_registered_sec'")->fetchColumn();
        if (is_string($v) && $v !== '' && preg_match('/^\\d+$/', $v)) {
            $intervalRegisteredSec = max(60, (int) $v);
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $v = $pdo->query("SELECT value FROM settings WHERE key='backorder_check_interval_rate_limited_sec'")->fetchColumn();
        if (is_string($v) && $v !== '' && preg_match('/^\\d+$/', $v)) {
            $intervalRateLimitedSec = max(60, (int) $v);
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $v = $pdo->query("SELECT value FROM settings WHERE key='backorder_check_interval_error_sec'")->fetchColumn();
        if (is_string($v) && $v !== '' && preg_match('/^\\d+$/', $v)) {
            $intervalErrorSec = max(60, (int) $v);
        }
    } catch (Throwable $e) {
        // ignore
    }

    $nowEpoch = time();
    $paramsDue = [
        ':cutoff_default' => $nowEpoch - $intervalSec,
        ':cutoff_registered' => $nowEpoch - $intervalRegisteredSec,
        ':cutoff_rate_limited' => $nowEpoch - $intervalRateLimitedSec,
        ':cutoff_error' => $nowEpoch - $intervalErrorSec,
    ];

    $dueWhereSql = "
        WHERE COALESCE(NULLIF(status, ''), 'unknown') != 'available'
          AND (
              last_checked_at IS NULL
              OR (
                  (COALESCE(NULLIF(status, ''), 'unknown') = 'registered' AND CAST(strftime('%s', last_checked_at) AS INTEGER) < :cutoff_registered)
                  OR (COALESCE(NULLIF(status, ''), 'unknown') = 'rate_limited' AND CAST(strftime('%s', last_checked_at) AS INTEGER) < :cutoff_rate_limited)
                  OR (COALESCE(NULLIF(status, ''), 'unknown') = 'error' AND CAST(strftime('%s', last_checked_at) AS INTEGER) < :cutoff_error)
                  OR (COALESCE(NULLIF(status, ''), 'unknown') NOT IN ('registered','rate_limited','error') AND CAST(strftime('%s', last_checked_at) AS INTEGER) < :cutoff_default)
              )
          )
    ";

    // Pick one due domain: never checked first, then oldest checked.
    // Note: status=available is treated as terminal (not re-checked) to save resources.
    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            COALESCE(NULLIF(status, ''), 'unknown') AS status,
            last_http_code,
            last_error,
            last_rdap_url,
            last_result_json
        FROM backorder_domains
        $dueWhereSql
        ORDER BY
            CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END,
            COALESCE(last_checked_at, created_at) ASC
        LIMIT 1
    ");
    $stmt->execute($paramsDue);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor(); // Free SQLite read lock

    if (!$row) {
        orbitraCronSetSetting($pdo, 'backorder_cron_last_checked_at', $ts);
        orbitraCronSetSetting($pdo, 'backorder_cron_last_domain', '');
        orbitraCronSetSetting($pdo, 'backorder_cron_last_status', 'idle');
        orbitraCronSetSetting($pdo, 'backorder_cron_last_http_code', '0');
        orbitraCronSetSetting($pdo, 'backorder_cron_last_error', '');
        exit(0);
    }

    $id = (int) $row['id'];
    $name = (string) $row['name'];

    $check = orbitraBackorderCheck($name);

    $prevStatus = (string) ($row['status'] ?? 'unknown');
    $transient = orbitraBackorderIsTransientCheckResult($check);

    $statusToStore = (string) ($check['status'] ?? 'unknown');
    $httpToStore = $check['http_code'] ?? 0;
    $errorToStore = $check['error'] ?? null;
    $rdapToStore = $check['rdap_url'] ?? null;
    $jsonToStore = $check['result_json'] ?? null;

    if ($transient) {
        if (in_array($prevStatus, ['registered', 'available'], true)) {
            $statusToStore = $prevStatus;
            $httpToStore = $row['last_http_code'] ?? 0;
            $errorToStore = $row['last_error'] ?? null;
            $rdapToStore = $row['last_rdap_url'] ?? null;
            $jsonToStore = $row['last_result_json'] ?? null;
        } else {
            if (in_array($prevStatus, ['error', 'rate_limited'], true)) {
                $statusToStore = 'unknown';
            } else {
                $statusToStore = $prevStatus ?: 'unknown';
            }
        }
    }

    $pdo->prepare("
        UPDATE backorder_domains
        SET
            status = ?,
            last_checked_at = CURRENT_TIMESTAMP,
            last_http_code = ?,
            last_error = ?,
            last_rdap_url = ?,
            last_result_json = ?
        WHERE id = ?
    ")->execute([
        $statusToStore,
        $httpToStore,
        $errorToStore,
        $rdapToStore,
        $jsonToStore,
        $id
    ]);

    // Output a compact line for cron logs.
    $msg = (string) $statusToStore;
    $code = (int) $httpToStore;
    echo "[$ts] backorder: $name => $msg (HTTP $code)\n";

    orbitraCronSetSetting($pdo, 'backorder_cron_last_checked_at', $ts);
    orbitraCronSetSetting($pdo, 'backorder_cron_last_domain', $name);
    orbitraCronSetSetting($pdo, 'backorder_cron_last_status', $msg);
    orbitraCronSetSetting($pdo, 'backorder_cron_last_http_code', (string) $code);
    orbitraCronSetSetting($pdo, 'backorder_cron_last_error', (string) ($errorToStore ?? ''));
} catch (Throwable $e) {
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] backorder_cron error: " . $e->getMessage() . "\n";
    orbitraCronSetSetting($pdo, 'backorder_cron_last_error', $e->getMessage());
} finally {
    if (isset($fp) && is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
