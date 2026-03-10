<?php
// backorder_cron.php
// Cron worker: checks exactly one domain per run (oldest/never checked first).
//
// Example cron (every 3 minutes):
// */3 * * * * php /var/www/orbitra/backorder_cron.php >> /var/log/orbitra_backorder.log 2>&1

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/backorder.php';

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
    // Pick one domain: never checked first, then oldest checked.
    $stmt = $pdo->query("
        SELECT id, name
        FROM backorder_domains
        ORDER BY
            CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END,
            COALESCE(last_checked_at, created_at) ASC
        LIMIT 1
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        exit(0);
    }

    $id = (int) $row['id'];
    $name = (string) $row['name'];

    $check = orbitraBackorderRdapCheck($name);

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
        $check['status'],
        $check['http_code'],
        $check['error'],
        $check['rdap_url'],
        $check['result_json'],
        $id
    ]);

    // Output a compact line for cron logs.
    $ts = date('Y-m-d H:i:s');
    $msg = $check['status'];
    $code = (int) $check['http_code'];
    echo "[$ts] backorder: $name => $msg (HTTP $code)\n";
} catch (Throwable $e) {
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] backorder_cron error: " . $e->getMessage() . "\n";
} finally {
    if (isset($fp) && is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

