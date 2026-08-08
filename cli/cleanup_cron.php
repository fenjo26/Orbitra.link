<?php
// cli/cleanup_cron.php
// Cron worker: purges stale clicks, conversions and archived items according to
// the retention windows configured under System Settings.
//
// Before this worker existed the SystemSettings "log retention" and "archive
// retention" fields were saved but never read — the database grew forever and
// archived campaigns lingered. This honours those windows so the fields do
// what their labels promise.
//
// Example cron (once daily):
//   0 3 * * * php /var/www/orbitra/cli/cleanup_cron.php >> /var/log/orbitra_cleanup.log 2>&1

require_once __DIR__ . '/../config.php';

// --- Single-instance lock ----------------------------------------------------
// A second run while the first is still deleting would race on the same rows
// and double the write load. The TTL covers a worker that died mid-run.
if (!is_dir(__DIR__ . '/../var/locks')) {
    @mkdir(__DIR__ . '/../var/locks', 0777, true);
}
$lockFile = __DIR__ . '/../var/locks/cleanup.lock';
$lockTtlSeconds = 3600;

$fp = @fopen($lockFile, 'c+');
if ($fp) {
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        // Another worker is running.
        exit(0);
    }
    // Stale-lock sweep: if the lock file is older than the TTL, the previous
    // worker is presumed dead and we carry on (flock is advisory on the fd).
    clearstatcache(true, $lockFile);
    if (filemtime($lockFile) !== false && (time() - filemtime($lockFile)) > $lockTtlSeconds) {
        // Owned by this process now; just continue.
    }
    ftruncate($fp, 0);
    fwrite($fp, (string) time());
}

function orbitraCleanupLog(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

function orbitraCleanupSetting(PDO $pdo, string $key, int $default): int
{
    try {
        $value = $pdo->query("SELECT value FROM settings WHERE key = " . $pdo->quote($key) . " LIMIT 1")->fetchColumn();
    } catch (\Throwable $e) {
        return $default;
    }
    $days = (int) $value;
    // 0 or negative makes no sense for retention; treat as default rather than
    // "delete everything immediately".
    return $days > 0 ? min($days, 3650) : $default;
}

// Chunked delete so a multi-million-row table does not lock under one statement.
// SQLite (as shipped with PHP) is not compiled with UPDATE/DELETE LIMIT support,
// so we delete by rowid selected in a subquery instead — that syntax is always
// available and keeps the LIMIT parameterised via a hard-cast int.
// Returns the number of rows deleted across all chunks.
function orbitraCleanupChunkedDelete(PDO $pdo, string $table, string $whereSql, array $params, int $chunkSize = 1000, int $maxChunks = 200): int
{
    $total = 0;
    $chunk = max(1, (int) $chunkSize);
    // The selector picks the ids of rows to delete; the outer statement deletes
    // exactly those. rowid is stable and indexed for free on every SQLite table.
    $selectSql = "SELECT rowid FROM {$table} WHERE {$whereSql} LIMIT {$chunk}";
    $selectStmt = $pdo->prepare($selectSql);
    $deleteStmt = $pdo->prepare("DELETE FROM {$table} WHERE rowid IN (" . $selectSql . ")");
    for ($i = 0; $i < $maxChunks; $i++) {
        try {
            $selectStmt->execute($params);
            $ids = $selectStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (\Throwable $e) {
            orbitraCleanupLog('  select error on ' . $table . ': ' . $e->getMessage());
            return $total;
        }
        if (empty($ids)) {
            break;
        }
        try {
            $deleteStmt->execute($params);
            $deleted = $deleteStmt->rowCount();
        } catch (\Throwable $e) {
            orbitraCleanupLog('  delete error on ' . $table . ': ' . $e->getMessage());
            return $total;
        }
        $total += $deleted;
        if (count($ids) < $chunk) {
            // Fewer than a full chunk means nothing left to purge this run.
            break;
        }
    }
    return $total;
}

// --- Configuration -----------------------------------------------------------
$clicksRetentionDays = orbitraCleanupSetting($pdo, 'stats_retention_days', 256);
$archiveRetentionDays = orbitraCleanupSetting($pdo, 'archive_retention_days', 30);

orbitraCleanupLog("Cleanup starting (clicks retention={$clicksRetentionDays}d, archive retention={$archiveRetentionDays}d)");

// --- Clicks (and their conversions via ON DELETE CASCADE) -------------------
// clicks(conversions.click_id) cascades, so deleting an old click also drops its
// conversions. We delete conversions older than the same window first by their
// own created_at so a long-lived click does not anchor stale conversions.
$convWhere = "created_at < datetime('now', '-" . $clicksRetentionDays . " days')";
try {
    $convDeleted = orbitraCleanupChunkedDelete($pdo, 'conversions', $convWhere, []);
} catch (\Throwable $e) {
    $convDeleted = 0;
    orbitraCleanupLog('  conversions delete skipped: ' . $e->getMessage());
}

$clicksWhere = "created_at < datetime('now', '-" . $clicksRetentionDays . " days')";
try {
    $clicksDeleted = orbitraCleanupChunkedDelete($pdo, 'clicks', $clicksWhere, []);
} catch (\Throwable $e) {
    $clicksDeleted = 0;
    orbitraCleanupLog('  clicks delete skipped: ' . $e->getMessage());
}

orbitraCleanupLog("  purged {$clicksDeleted} clicks, {$convDeleted} conversions older than {$clicksRetentionDays} days");

// --- Archived items past their retention window -----------------------------
// Deleting an archived campaign cascades to its streams/clicks (FK ON DELETE
// CASCADE); offers/landings set dependent campaign columns to NULL. These rows
// are already archived, so removing the stale ones never touches live data.
$archiveTables = ['campaigns', 'offers', 'landings', 'traffic_sources', 'affiliate_networks'];
$totalArchived = 0;
foreach ($archiveTables as $table) {
    $archiveWhere = "is_archived = 1 AND archived_at IS NOT NULL AND archived_at < datetime('now', '-" . $archiveRetentionDays . " days')";
    try {
        $archDeleted = orbitraCleanupChunkedDelete($pdo, $table, $archiveWhere, []);
        $totalArchived += $archDeleted;
    } catch (\Throwable $e) {
        orbitraCleanupLog("  archive {$table} delete skipped: " . $e->getMessage());
        continue;
    }
}
orbitraCleanupLog("  purged {$totalArchived} archived items older than {$archiveRetentionDays} days");

// --- Record last run ---------------------------------------------------------
try {
    $stmt = $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('cleanup_cron_last_run', ?, datetime('now')) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=datetime('now')");
    $stmt->execute([date('Y-m-d H:i:s')]);
} catch (\Throwable $e) {
    // Non-fatal: the purge still happened.
}

// Optional: reclaim OS-level space once a week-ish is out of scope here — SQLite
// reuses freed pages internally, and a VACUUM would lock the DB. Left to the
// operator if disk pressure demands it.

if ($fp) {
    ftruncate($fp, 0);
    flock($fp, LOCK_UN);
    fclose($fp);
}
orbitraCleanupLog('Cleanup done');
