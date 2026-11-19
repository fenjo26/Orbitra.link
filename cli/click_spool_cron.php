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
const WAL_RESTART_THRESHOLD = 67108864;   // 64 MB: past this, a RESTART is attempted EVERY pass
const WAL_RESTART_TAIL_MAX  = 4194304;    // 4 MB: max un-checkpointed tail a RESTART may still copy
                                           // (copy <= ~50 ms even at stand write rates, so copy+busy-wait
                                           // stays under the 200 ms writer-block budget; the slow-disk lab
                                           // measured an 8 MB tail peaking at 251 ms)
const WAL_RESTART_BUSY_MS   = 100;        // the short reader-wait a frequent-small RESTART gets
const WAL_FORCED_THRESHOLD  = 1073741824; // 1 GB: past this, the once-a-minute forced RESTART
const WAL_FORCED_BUSY_MS    = 1000;       // the longer wait, 1 s per minute, as a backstop
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
 * waits on us beyond one short bounded window.
 *
 * Every WAL_PASSIVE_INTERVAL seconds: a PASSIVE pass (copies the backlog
 * into the main database without blocking anyone), then — while the WAL is
 * past 64 MB — a RESTART attempt with busy_timeout = 100 ms. Frequent and
 * small is the whole design (ТЗ 1.9.7, round 3): the PASSIVE keeps the
 * un-checkpointed backlog at ~one pass of traffic, so the RESTART has almost
 * nothing to copy, its writer block is a fraction of a second, and the file
 * folds long before the journal_size_limit truncate has gigabytes to free.
 * The previous rare-and-large cadence (30 s, zero wait) let the log grow to
 * gigabytes at ~21 MB/s between attempts, and the landing RESTART then
 * blocked writers for seconds (max_block_ms 5.6 s, 592 timeouts, p99 4.2 s
 * on the stand — DIAG_CKPT_B).
 *
 * The forced tier stays as the backstop: past 1 GB, at most once a minute,
 * a RESTART with busy_timeout = 1000 ms for when the frequent-small attempts
 * keep losing the race.
 *
 * After any successful RESTART, journal_size_limit (64 MB) shrinks the file
 * back inside the checkpoint call itself.
 *
 * max_block_ms now accounts EVERY RESTART attempt (landed or busy) — a
 * failed attempt that waited out its window blocked writers just the same.
 *
 * The heartbeat file is refreshed on EVERY pass (not once per tick): the
 * web-side auto-checkpoint policy (core/wal_policy.php) keys off its mtime
 * with a 10 s freshness window, and the tick seam is ~5 s.
 *
 * ORBITRA_WAL_FORCED_BYTES (env) overrides the forced threshold for tests,
 * ORBITRA_WAL_RESTART_SECS (env) the minimum seconds between RESTART
 * attempts (0 = every pass, the default), ORBITRA_WAL_RESTART_BYTES the
 * 64 MB gate.
 *
 * The pulse also counts "natural" restarts: every pass where the -wal file
 * is SMALLER than on the previous pass — the log was restarted without our
 * RESTART machinery landing.
 *
 * @return array{passes:int, restarts_ok:int, restarts_busy:int, forced_restarts:int,
 *               natural_restarts:int, max_wal_bytes:int, max_ms:float, max_block_ms:float, at:int}
 */
function orbitraWalCheckpointLoop(PDO $pdo, float $tickStartedAt, float $budgetSeconds, bool $quiet): array
{
    $stats = [
        'passes' => 0, 'restarts_ok' => 0, 'restarts_busy' => 0, 'forced_restarts' => 0,
        'natural_restarts' => 0, 'restarts_skipped_tail' => 0,
        'max_wal_bytes' => 0, 'max_ms' => 0.0, 'max_block_ms' => 0.0, 'at' => time(),
    ];

    $forcedThreshold = WAL_FORCED_THRESHOLD;
    $forcedEnv = getenv('ORBITRA_WAL_FORCED_BYTES');
    if (is_numeric($forcedEnv) && (int) $forcedEnv > 0) {
        $forcedThreshold = (int) $forcedEnv;
    }
    $restartInterval = 0.0; // every pass past the gate (see the header comment)
    $restartEnv = getenv('ORBITRA_WAL_RESTART_SECS');
    if (is_numeric($restartEnv) && (float) $restartEnv >= 0) {
        $restartInterval = (float) $restartEnv;
    }
    // Test-only override of the 64 MB gate, same pattern as the ones above.
    $restartThreshold = WAL_RESTART_THRESHOLD;
    $restartBytesEnv = getenv('ORBITRA_WAL_RESTART_BYTES');
    if (is_numeric($restartBytesEnv) && (int) $restartBytesEnv > 0) {
        $restartThreshold = (int) $restartBytesEnv;
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

    // Heartbeat write used between passes AND immediately before/after every
    // checkpoint call: while the worker sits inside a long checkpoint it must
    // still look alive — the web fuse keys off this file's mtime, and one
    // checkpoint on the stand disk can take ~10 s (ТЗ 1.9.7 acceptance:
    // a 10 s checkpoint aged the pulse to 58.7 s, the web fuse came back
    // mid-run and closed the round-1 loop). in_checkpoint_since carries the
    // timestamp of the call in flight for observers.
    $writePulse = static function (?float $since) use (&$stats): void {
        $stats["at"] = time();
        if ($since !== null) {
            $stats["in_checkpoint_since"] = round($since, 3);
        } else {
            unset($stats["in_checkpoint_since"]);
        }
        @mkdir(dirname(WAL_STATE_FILE), 0770, true);
        @file_put_contents(WAL_STATE_FILE, json_encode($stats, JSON_UNESCAPED_UNICODE));
    };
    // busy_timeout shrinks to $busyMs for the call and is restored after.
    // busy=1 (live readers/writer) is EXPECTED, not an error.
    $shortCheckpoint = static function (string $kind, int $busyMs) use ($pdo, &$stats, $writePulse): int {
        $prevTimeout = 30000;
        try {
            $prevTimeout = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();
        } catch (\Throwable $e) {
            // keep the CLI default
        }
        $t0 = microtime(true);
        $writePulse(microtime(true));
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
        $writePulse(null);
        $elapsedMs = (microtime(true) - $t0) * 1000.0;
        if ($busy === 0) {
            $stats['restarts_ok']++;
        } else {
            $stats['restarts_busy']++;
        }
        // max_block_ms accounts EVERY RESTART attempt, landed or busy: a
        // failed attempt that waited out its window held writers off just
        // the same (ТЗ 1.9.7 round 3).
        if ($kind === 'RESTART') {
            $stats['max_block_ms'] = max($stats['max_block_ms'], $elapsedMs);
        }
        return $busy;
    };

    // A RESTART may only run when the PASSIVE of THIS pass left almost
    // nothing to copy: a RESTART acquires the WRITER lock FIRST and then
    // backfills everything that is left, so a big tail means the whole copy
    // runs with writers parked — on the stand that was a 9.75–13.35 s block
    // once the tail reached gigabytes (ТЗ 1.9.7 acceptance round, 480×15).
    // The gate: log == checkpointed, or the tail under WAL_RESTART_TAIL_MAX.
    $pageSize = 4096;
    try {
        $pageSize = max(512, (int) $pdo->query('PRAGMA page_size')->fetchColumn());
    } catch (\Throwable $e) {
        // the compiled-in default stands
    }
    $tailSmall = static function (array $passiveRow) use ($pageSize): bool {
        [$busy, $log, $checkpointed] = [(int) ($passiveRow[0] ?? 1), (int) ($passiveRow[1] ?? -1), (int) ($passiveRow[2] ?? -1)];
        if ($log < 0 || $checkpointed < 0) {
            return false; // the probe itself failed — do not gamble on a copy
        }
        $tailBytes = max(0, $log - $checkpointed) * $pageSize;
        return $log === $checkpointed || $tailBytes < WAL_RESTART_TAIL_MAX;
    };

    $lastRestartAt = 0.0;
    $lastForcedAt = 0.0;
    $prevSize = null;
    $lastPulseWrite = 0.0;
    while (true) {
        $size = $walSize();
        $stats['max_wal_bytes'] = max($stats['max_wal_bytes'], $size);
        // Smaller than the previous pass = the log was restarted in between
        // without our RESTART machinery having to land.
        if ($prevSize !== null && $size < $prevSize) {
            $stats['natural_restarts']++;
        }
        $prevSize = $size;

        $t0 = microtime(true);
        $writePulse(microtime(true));
        $passiveRow = $pdo->query('PRAGMA wal_checkpoint(PASSIVE)')->fetch(PDO::FETCH_NUM) ?: [1, -1, -1];
        $writePulse(null);
        $stats['passes']++;
        $stats['max_ms'] = max($stats['max_ms'], (microtime(true) - $t0) * 1000.0);
        $tailOk = $tailSmall(is_array($passiveRow) ? $passiveRow : [1, -1, -1]);

        // Frequent-small RESTART: EVERY pass while the WAL is past the gate
        // AND this pass's PASSIVE left (almost) nothing to copy — the copy
        // then takes a fraction of a second under the WRITER lock instead of
        // parking writers for the length of a gigabyte backfill. If the tail
        // is still big, this pass only PASSIVEs: catching the copy up
        // without blocking writes is strictly better than restarting on top
        // of a backlog. ORBITRA_WAL_RESTART_SECS re-introduces a rate limit
        // for tests only.
        if ($size > $restartThreshold && (microtime(true) - $lastRestartAt) >= $restartInterval) {
            if (!$tailOk) {
                $stats['restarts_skipped_tail'] = ($stats['restarts_skipped_tail'] ?? 0) + 1;
            } else {
                $lastRestartAt = microtime(true);
                $shortCheckpoint('RESTART', WAL_RESTART_BUSY_MS);
                // Forced backstop: 1 s of patience, at most once a minute,
                // only once the WAL is genuinely huge — SAME tail rule: no
                // gigabyte copies under the WRITER lock, ever.
                if ($size > $forcedThreshold && (microtime(true) - $lastForcedAt) >= WAL_FORCED_INTERVAL) {
                    $lastForcedAt = microtime(true);
                    $stats['forced_restarts']++;
                    $shortCheckpoint('RESTART', WAL_FORCED_BUSY_MS);
                }
            }
        }

        if ((microtime(true) - $lastPulseWrite) >= 1.0) {
            $writePulse(null);
            $lastPulseWrite = microtime(true);
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
    $writePulse(null);

    if (!$quiet) {
        orbitraSpoolLog(sprintf(
            'WAL checkpoint: %d passive, %d restart ok / %d busy, %d forced, %d natural, max WAL %.1f MB, slowest %.1f ms, longest block %.1f ms',
            $stats['passes'],
            $stats['restarts_ok'],
            $stats['restarts_busy'],
            $stats['forced_restarts'],
            $stats['natural_restarts'],
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
