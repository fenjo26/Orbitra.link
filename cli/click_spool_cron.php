<?php
// cli/click_spool_cron.php
//
// Replays the click spool written by core/click_logger.php. When a click
// INSERT fails (most often "database is locked" after busy_timeout), the row
// is appended as one JSON object per line to var/spool/clicks.log so the
// visitor's redirect is never delayed — this worker is what eventually lands
// those rows in the database instead of losing them.
//
// The web side must never wait on this worker, so the spool lock is held only
// for a rename: the live file is moved to clicks.processing.<time>.log and the
// replay (database writes, possibly slow under the same lock contention that
// filled the spool) works on that private copy. Writers that opened the old
// file just before the rename notice the inode change and reopen
// (orbitraSpoolClick()). A row that keeps failing is retried on later runs
// and, after SPOOL_MAX_ATTEMPTS, parked in clicks.dead.log instead of being
// replayed forever.
//
// Scheduled every minute (marker "# orbitra-click-spool") by install.sh, by
// cli/server_setup.sh, and — on installs that predate both — by the panel
// itself (orbitraEnsureClickSpoolCron(), called from check_update).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/click_logger.php';

const SPOOL_DIR          = __DIR__ . '/../var/spool';
const SPOOL_FILE         = SPOOL_DIR . '/clicks.log';
const SPOOL_DEAD_FILE    = SPOOL_DIR . '/clicks.dead.log';
const SPOOL_LOCK_FILE    = __DIR__ . '/../var/locks/click_spool.lock';
const SPOOL_TIME_BUDGET  = 50;   // seconds per run; the rest waits for the next tick
const SPOOL_MAX_ATTEMPTS = 30;   // failed replays before a row is parked as dead

$options = getopt('', ['quiet']);
$isQuiet = isset($options['quiet']);

function orbitraSpoolLog(string $msg): void
{
    global $isQuiet;
    if (!$isQuiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }
}

function orbitraSpoolLogError(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
}

// --- single-flight -----------------------------------------------------------
if (!is_dir(dirname(SPOOL_LOCK_FILE))) {
    @mkdir(dirname(SPOOL_LOCK_FILE), 0770, true);
}
$lockFp = @fopen(SPOOL_LOCK_FILE, 'c');
if ($lockFp && !flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit(0); // another worker is running
}

// --- one-time build: the IP+UA uniqueness index (migration 55) ----------------
// (ip, ua_hash, created_at) serves every IP_UA probe on the click path:
// uniqueness, debounce and the ClickFlags campaign/stream/global checks.
// Building it scans the whole clicks table — seconds when small, minutes on
// multi-million-row databases — which is exactly why it never runs inside a
// web request: clicks keep spooling while the write lock is held, and the
// replay below lands them right after this finishes. Runs BEFORE the spool
// take so the replay never competes with (or burns attempts against) the
// build. Until the build happens the probes still work, just as the old
// range scans.
try {
    $uaIndexState = $pdo->query("SELECT value FROM settings WHERE key = 'clicks_ua_index_state'")->fetchColumn();
    if ($uaIndexState === 'pending' || $uaIndexState === 'building') {
        orbitraSpoolLog('Building idx_clicks_ip_ua_created (one-time; blocks writes while it runs)...');
        $uaIndexStartedAt = microtime(true);
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('clicks_ua_index_state', 'building')")->execute();
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clicks_ip_ua_created ON clicks(ip, ua_hash, created_at)');
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('clicks_ua_index_state', 'done')")->execute();
        orbitraSpoolLog('idx_clicks_ip_ua_created built in ' . round(microtime(true) - $uaIndexStartedAt, 1) . ' s');
    }
} catch (\Throwable $e) {
    // A failed build must never stop the spool replay; the flag stays where
    // it is and the next tick retries.
    orbitraSpoolLogError('idx_clicks_ip_ua_created build deferred: ' . $e->getMessage());
}

// --- take the live spool (lock held only for the rename) -----------------------
if (is_file(SPOOL_FILE) && filesize(SPOOL_FILE) > 0) {
    $fp = @fopen(SPOOL_FILE, 'ab');
    if ($fp && flock($fp, LOCK_EX)) {
        $batch = SPOOL_DIR . '/clicks.processing.' . date('YmdHis') . '.' . getmypid() . '.log';
        if (!@rename(SPOOL_FILE, $batch)) {
            orbitraSpoolLogError('Cannot rename the spool for replay; leaving it in place');
        }
        flock($fp, LOCK_UN);
    }
    if ($fp) {
        fclose($fp);
    }
}

// Batches from this run and any left behind by a crashed one, oldest first.
$batches = glob(SPOOL_DIR . '/clicks.processing.*.log') ?: [];
sort($batches);
if (!$batches) {
    exit(0);
}

$startedAt = time();
$inserted = 0;
$requeued = 0;
$dead = 0;

$requeue = static function (array $row) use (&$requeued, &$dead): void {
    $row['_spool_attempts'] = (int) ($row['_spool_attempts'] ?? 0) + 1;
    if ($row['_spool_attempts'] >= SPOOL_MAX_ATTEMPTS) {
        @file_put_contents(SPOOL_DEAD_FILE, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        $dead++;
        return;
    }
    if (orbitraSpoolClick($row)) {
        $requeued++;
    } else {
        @file_put_contents(SPOOL_DEAD_FILE, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        $dead++;
    }
};

foreach ($batches as $batchFile) {
    $in = @fopen($batchFile, 'rb');
    if (!$in) {
        orbitraSpoolLogError('Cannot open ' . basename($batchFile));
        continue;
    }
    while (($line = fgets($in)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            continue;
        }
        $row = json_decode($line, true);
        if (!is_array($row) || empty($row['id']) || !is_string($row['id'])) {
            @file_put_contents(SPOOL_DEAD_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
            $dead++;
            continue;
        }
        if ((time() - $startedAt) >= SPOOL_TIME_BUDGET) {
            // Out of time: hand the row back to the live spool untouched
            // (no attempt spent) for the next tick.
            if (!orbitraSpoolClick($row)) {
                @file_put_contents(SPOOL_DEAD_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
                $dead++;
            } else {
                $requeued++;
            }
            continue;
        }

        $attempts = (int) ($row['_spool_attempts'] ?? 0);
        unset($row['_spool_attempts']);
        try {
            $columns = array_keys($row);
            $stmt = $pdo->prepare(
                'INSERT OR IGNORE INTO clicks (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')'
            );
            $stmt->execute(array_values($row));
            $inserted++;
        } catch (\Throwable $e) {
            orbitraSpoolLogError('Replay failed for click ' . $row['id'] . ': ' . $e->getMessage());
            $row['_spool_attempts'] = $attempts;
            $requeue($row);
        }
    }
    fclose($in);
    @unlink($batchFile);
}

if ($lockFp) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

orbitraSpoolLog("Spool replay: $inserted inserted, $requeued back in the spool, $dead parked in clicks.dead.log");
exit(0);
