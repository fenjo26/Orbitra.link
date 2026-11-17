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
require_once __DIR__ . '/../core/PostbackPending.php';

const SPOOL_DIR          = __DIR__ . '/../var/spool';
const SPOOL_FILE         = SPOOL_DIR . '/clicks.log';
const SPOOL_DEAD_FILE    = SPOOL_DIR . '/clicks.dead.log';
const SPOOL_LOCK_FILE    = __DIR__ . '/../var/locks/click_spool.lock';
const SPOOL_TIME_BUDGET  = 50;   // seconds per run; the rest waits for the next tick
const SPOOL_MAX_ATTEMPTS = 30;   // failed replays before a row is parked as dead

// Background WAL checkpoint (TZ_LOAD_PERFORMANCE_2, stage 2). Web requests do
// not checkpoint anymore (persistent connections + wal_autocheckpoint as a
// distant 40 MB fuse), so the WAL is folded into the main database HERE, off
// the click path. The state file doubles as the heartbeat the panel's System
// status reads — a file older than 5 minutes means this cron is not running.
const WAL_STATE_FILE        = __DIR__ . '/../var/cache/wal_checkpoint.json';
const WAL_PASSIVE_INTERVAL  = 2.0;        // seconds between checkpoint passes
const WAL_RESTART_THRESHOLD = 67108864;   // 64 MB: past this, an opportunistic RESTART may run
const WAL_RESTART_INTERVAL  = 30.0;       // ...but at most once per this many seconds
const WAL_FORCED_THRESHOLD  = 1073741824; // 1 GB: past this, the once-a-minute forced RESTART
const WAL_FORCED_BUSY_MS    = 1000;       // the ONLY wait the background allows, 1 s per minute
const WAL_FORCED_INTERVAL   = 60.0;       // seconds between forced attempts
const WAL_TICK_BUDGET       = 55.0;       // seconds from tick start, tops

$options = getopt('', ['quiet', 'checkpoint-budget:']);
$isQuiet = isset($options['quiet']);
// Tests drive one short tick; a zero budget still runs a single PASSIVE pass
// and refreshes the heartbeat file.
$walBudgetOpt = $options['checkpoint-budget'] ?? getenv('ORBITRA_WAL_CHECKPOINT_BUDGET');
$walBudgetSeconds = is_numeric($walBudgetOpt) ? max(0.0, (float) $walBudgetOpt) : WAL_TICK_BUDGET;

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

/**
 * Checkpoint the WAL until the tick budget runs out — the writer side never
 * waits on us, and we almost never wait on readers.
 *
 * Every WAL_PASSIVE_INTERVAL seconds: a PASSIVE pass. An opportunistic
 * RESTART with busy_timeout = 0 runs only when BOTH gates open: the WAL is
 * past 64 MB AND the last opportunistic attempt is over 30 s old. The two
 * gates exist because even a SUCCESSFUL zero-wait RESTART blocks writers
 * while it copies the accumulated frames into the main database — on a slow
 * disk that is up to ~2 s per restart, and at the old every-pass cadence
 * (17–22 restarts a minute at 100 rps) it pushed p95 to 840 ms (ТЗ part 4:
 * regular-traffic check). Rare and large beats often and small.
 *
 * The WAL peak under sustained load is bounded by the rare FORCED restart:
 * past 1 GB, at most once a minute, one RESTART with busy_timeout = 1000 ms
 * — the only wait anywhere in the design, one second per minute (ТЗ part 3:
 * the soak pair showed a 250 ms wait on the WEB path turns a big WAL into an
 * error avalanche, yet without any wait the WAL grows ~550 MB/min).
 *
 * After any successful RESTART, journal_size_limit (64 MB) shrinks the file
 * back, so the WAL folds back to normal the moment load drops.
 *
 * ORBITRA_WAL_FORCED_BYTES (env) overrides the forced threshold for tests.
 *
 * @return array{passes:int, restarts_ok:int, restarts_busy:int, forced_restarts:int,
 *               max_wal_bytes:int, max_ms:float, max_block_ms:float, at:int}
 */
function orbitraWalCheckpointLoop(PDO $pdo, float $tickStartedAt, float $budgetSeconds, bool $quiet): array
{
    $stats = [
        'passes' => 0, 'restarts_ok' => 0, 'restarts_busy' => 0, 'forced_restarts' => 0,
        'max_wal_bytes' => 0, 'max_ms' => 0.0, 'max_block_ms' => 0.0, 'at' => time(),
    ];

    $forcedThreshold = WAL_FORCED_THRESHOLD;
    $forcedEnv = getenv('ORBITRA_WAL_FORCED_BYTES');
    if (is_numeric($forcedEnv) && (int) $forcedEnv > 0) {
        $forcedThreshold = (int) $forcedEnv;
    }

    $dbFile = '';
    foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $db) {
        if (($db['name'] ?? '') === 'main' && !empty($db['file'])) {
            $dbFile = (string) $db['file'];
            break;
        }
    }
    $walFile = $dbFile !== '' ? $dbFile . '-wal' : '';
    $walSize = static function () use ($walFile): int {
        clearstatcache();
        return is_file($walFile) ? (int) filesize($walFile) : 0;
    };

    // busy_timeout shrinks to $busyMs for the call and is restored after.
    // busy=1 (live readers) is EXPECTED, not an error.
    $shortCheckpoint = static function (string $kind, int $busyMs) use ($pdo, &$stats): int {
        $prevTimeout = 30000;
        try {
            $prevTimeout = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();
        } catch (\Throwable $e) {
            // keep the CLI default
        }
        $t0 = microtime(true);
        try {
            $pdo->exec('PRAGMA busy_timeout = ' . max(0, $busyMs) . ';');
            $row = $pdo->query('PRAGMA wal_checkpoint(' . $kind . ')')->fetch(PDO::FETCH_NUM);
            $busy = (int) ($row[0] ?? 1);
        } catch (\Throwable $e) {
            $busy = 1;
        } finally {
            try {
                $pdo->exec('PRAGMA busy_timeout = ' . max(0, $prevTimeout) . ';');
            } catch (\Throwable $e) {
                // nothing sensible to do; the process exits with the tick
            }
        }
        if ($busy === 0) {
            $stats['restarts_ok']++;
            $stats['max_block_ms'] = max($stats['max_block_ms'], (microtime(true) - $t0) * 1000.0);
        } else {
            $stats['restarts_busy']++;
        }
        return $busy;
    };

    $lastRestartAt = 0.0;
    $lastForcedAt = 0.0;
    while (true) {
        $size = $walSize();
        $stats['max_wal_bytes'] = max($stats['max_wal_bytes'], $size);

        $t0 = microtime(true);
        $pdo->query('PRAGMA wal_checkpoint(PASSIVE)')->fetch(PDO::FETCH_NUM);
        $stats['passes']++;
        $stats['max_ms'] = max($stats['max_ms'], (microtime(true) - $t0) * 1000.0);

        // Opportunistic RESTART: zero wait, but BOTH gates — past 64 MB and
        // over 30 s since the last attempt. A successful restart still blocks
        // writers while it copies (up to ~2 s on slow disks), so rare-and-
        // large is the whole point.
        if ($size > WAL_RESTART_THRESHOLD && (microtime(true) - $lastRestartAt) >= WAL_RESTART_INTERVAL) {
            $lastRestartAt = microtime(true);
            $shortCheckpoint('RESTART', 0);
            // Forced: the only wait in the design — 1 s, at most once a
            // minute, only once the WAL is genuinely huge.
            if ($size > $forcedThreshold && (microtime(true) - $lastForcedAt) >= WAL_FORCED_INTERVAL) {
                $lastForcedAt = microtime(true);
                $stats['forced_restarts']++;
                $shortCheckpoint('RESTART', WAL_FORCED_BUSY_MS);
            }
        }

        $next = microtime(true) + WAL_PASSIVE_INTERVAL;
        if ($next - $tickStartedAt >= $budgetSeconds) {
            break;
        }
        $sleepUntil = min($next, $tickStartedAt + $budgetSeconds);
        if ($sleepUntil > microtime(true)) {
            time_sleep_until($sleepUntil);
        }
    }

    if (!$quiet) {
        orbitraSpoolLog(sprintf(
            'WAL checkpoint: %d passive, %d restart ok / %d busy, %d forced, max WAL %.1f MB, slowest %.1f ms, longest block %.1f ms',
            $stats['passes'],
            $stats['restarts_ok'],
            $stats['restarts_busy'],
            $stats['forced_restarts'],
            $stats['max_wal_bytes'] / 1048576,
            $stats['max_ms'],
            $stats['max_block_ms']
        ));
    }

    @mkdir(dirname(WAL_STATE_FILE), 0770, true);
    @file_put_contents(WAL_STATE_FILE, json_encode($stats, JSON_UNESCAPED_UNICODE));
    return $stats;
}

// --- single-flight -----------------------------------------------------------
if (!is_dir(dirname(SPOOL_LOCK_FILE))) {
    @mkdir(dirname(SPOOL_LOCK_FILE), 0770, true);
}
$lockFp = @fopen(SPOOL_LOCK_FILE, 'c');
if ($lockFp && !flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit(0); // another worker is running
}

$tickStartedAt = microtime(true);

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
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('clicks_ua_index_state', 'backfilling')")->execute();
        // The backfill marker doubles as the completion signal: present means
        // batches are still running (or a tick must resume them), deleted
        // means the step walked past every in-window legacy row.
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('clicks_ua_backfill_rowid', '0')")->execute();
        $uaIndexState = 'backfilling'; // same tick continues with the backfill
        orbitraSpoolLog('idx_clicks_ip_ua_created built in ' . round(microtime(true) - $uaIndexStartedAt, 1) . ' s; hashing legacy clicks next');
    }
    // Legacy rows predate ua_hash (migration 55) and stay invisible to the
    // hashed probes for as long as they sit inside a uniqueness window — up
    // to a day or more on installs with mobile traffic (acceptance round 2,
    // blocker 4). Hash them right after the index build, in short committed
    // batches that resume across ticks; rows outside every window are never
    // probed and stay untouched.
    if ($uaIndexState === 'backfilling') {
        $uaBackfillStartedAt = microtime(true);
        try {
            $uaHashed = orbitraBackfillUaHashStep($pdo, 40.0);
            // NULL-hash rows outside the uniqueness window stay NULL forever,
            // so completion is the step deleting its marker — not a global
            // NULL count.
            $uaMarkerLeft = $pdo->query("SELECT value FROM settings WHERE key = 'clicks_ua_backfill_rowid'")->fetchColumn();
            if ($uaMarkerLeft === false) {
                $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('clicks_ua_index_state', 'done')")->execute();
                orbitraSpoolLog('ua_hash backfill finished (' . $uaHashed . ' rows this tick, ' . round(microtime(true) - $uaBackfillStartedAt, 1) . ' s)');
            } elseif ($uaHashed > 0) {
                orbitraSpoolLog('ua_hash backfill: hashed ' . $uaHashed . ' legacy clicks in ' . round(microtime(true) - $uaBackfillStartedAt, 1) . ' s; continues next tick');
            }
        } catch (\Throwable $e) {
            orbitraSpoolLogError('ua_hash backfill deferred: ' . $e->getMessage());
        }
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

// Postbacks that arrived while their click was still in the spool
// (core/PostbackPending.php). Runs on EVERY tick, also when the spool is
// empty: the click may have landed on an earlier tick, and rows whose click
// never came have to expire. One indexed lookup when nothing is waiting.
$replayPendingPostbacks = static function () use ($pdo): void {
    $pending = orbitraProcessPendingPostbacks($pdo, dirname(__DIR__));
    if (array_sum($pending) > 0) {
        orbitraSpoolLog(sprintf(
            'Delayed postbacks: %d processed, %d still waiting for the click, %d given up (no click), %d failed',
            $pending['replayed'], $pending['waiting'], $pending['unmatched'], $pending['failed']
        ));
    }
};

// Batches from this run and any left behind by a crashed one, oldest first.
$batches = glob(SPOOL_DIR . '/clicks.processing.*.log') ?: [];
sort($batches);
if (!$batches) {
    $replayPendingPostbacks();
    orbitraWalCheckpointLoop($pdo, $tickStartedAt, $walBudgetSeconds, $isQuiet);
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

$replayPendingPostbacks();

// Last on purpose: with the spool replayed, the rest of the tick belongs to
// background WAL checkpointing (see orbitraWalCheckpointLoop). If the replay
// ate the minute, the loop is simply short — the next tick picks it up.
orbitraWalCheckpointLoop($pdo, $tickStartedAt, $walBudgetSeconds, $isQuiet);

if ($lockFp) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

orbitraSpoolLog("Spool replay: $inserted inserted, $requeued back in the spool, $dead parked in clicks.dead.log");
exit(0);
