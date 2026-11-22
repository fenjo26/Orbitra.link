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
// mon201 tiers (see core/wal_policy.php for the full story):
//  1. Opportunistic RESTART only while the WAL is ≤ 1 GB — a restart past
//     that blocked writers 3.6 s on the stand (the 8.09 GB fold) plus the
//     journal_size_limit truncation inside the first click after it.
//  2. A bigger WAL folds only at a QUIET moment: < 2 MB growth over the
//     last 10 s of passes → RESTART with a short wait, then TRUNCATE —
//     the only place the -wal file is ever shrunk (web connections carry
//     journal_size_limit = -1, so no click ever truncates).
//  3. Forced is disk-space safety only: WAL > 50% of the free space OR
//     free < 2 GB. No byte cap — the 8 GB cap is what put the big-WAL
//     fold under load in mon201; and 50% because a fold needs almost no
//     extra space — at 25% the stand arithmetic (61 GB free) would have
//     forced a mid-load fold of a ~12 GB WAL at the 11th minute.
const WAL_QUIET_THRESHOLD    = 1073741824;  // 1 GB: above this the opportunistic tier stands down
const WAL_QUIET_WINDOW_SECS  = 10.0;        // the window the growth rate is averaged over
const WAL_QUIET_RATE_MAX     = 2097152;     // < 2 MB/s average over the window = quiet
const WAL_TRUNCATE_RATE_MAX  = 209715;      // ~0.2 MB/s: nearly still — the file may shrink
const WAL_QUIET_BUSY_MS      = 250;         // the short wait of the quiet fold
const WAL_FORCED_SPACE_RATIO = 0.50;
const WAL_FORCED_FREE_FLOOR  = 2147483648;  // 2 GB
const WAL_WARN_SPACE_RATIO   = 0.10;        // System-status warning level
const WAL_FORCED_BUSY_MS    = 1000;       // the ONLY other wait, 1 s per minute
const WAL_FORCED_INTERVAL   = 60.0;       // seconds between forced attempts
const WAL_TICK_BUDGET       = 55.0;       // seconds from tick start, tops

$options = getopt('', ['quiet', 'checkpoint-budget:', 'freeze-budget:']);
$isQuiet = isset($options['quiet']);
// Tests drive one short tick; a zero budget still runs a single PASSIVE pass
// and refreshes the heartbeat file.
$walBudgetOpt = $options['checkpoint-budget'] ?? getenv('ORBITRA_WAL_CHECKPOINT_BUDGET');
$walBudgetSeconds = is_numeric($walBudgetOpt) ? max(0.0, (float) $walBudgetOpt) : WAL_TICK_BUDGET;
// The freeze tick's own slice of this minute (ТЗ 2.1): its own lock, its own
// budget, so it can never starve the spool replay or the WAL loop — and no
// install needs a NEW crontab line to start freezing (this line already runs
// every minute everywhere; a missing dedicated line would have left cleanup
// watermark-gated forever = the database grows without bound).
const SPOOL_FREEZE_BUDGET = 10.0;   // seconds per minute for the rollup freeze
$freezeBudgetOpt = $options['freeze-budget'] ?? getenv('ORBITRA_FREEZE_BUDGET');
$freezeBudgetSeconds = is_numeric($freezeBudgetOpt) ? max(0.0, (float) $freezeBudgetOpt) : SPOOL_FREEZE_BUDGET;

function orbitraSpoolLog(string $msg): void
{
    global $isQuiet;
    if (!$isQuiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }
}

// ТЗ 2.1: ALL post-spool periodic steps in ONE function, called by BOTH exit
// paths (empty-spool fast path and the replay path). A step added here can
// never again be forgotten on one path — the stand caught exactly that with
// the VACUUM worker spawn (state stuck at 'requested' for 6+ ticks because
// the fast path skipped it).
//
// Order: stale-worker recovery → freeze tick → VACUUM worker spawn → WAL
// checkpoint loop → incremental_vacuum. The first three are lightweight;
// WAL checkpointing and incremental_vacuum consume the remaining budget.
function orbitraSpoolPostSteps(PDO $pdo, float $tickStartedAt, float $freezeBudgetSeconds, float $walBudgetSeconds, bool $isQuiet): void
{
    // --- 1. VACUUM crash recovery (dead pid → interrupted) ---------------
    try {
        $vacFlag = __DIR__ . '/../var/cache/vacuum.running';
        if (is_file($vacFlag)) {
            $vacPid = (int) @file_get_contents($vacFlag);
            $vacAlive = function_exists('posix_kill') ? @posix_kill($vacPid, 0) : @is_dir("/proc/{$vacPid}");
            if ($vacPid > 0 && !$vacAlive) {
                @unlink($vacFlag);
                $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('vacuum_conversion_state', 'error:interrupted', datetime('now'))
                               ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute();
                orbitraSpoolLog('vacuum conversion interrupted (pid ' . $vacPid . ' dead), flag cleared');
            }
        }
    } catch (\Throwable $e) {
        // Non-fatal: the next tick retries.
    }

    // --- 2. Rollup freeze tick (no new crontab line anywhere) -------------
    require_once __DIR__ . '/../core/stats_freeze.php';
    orbitraStatsFreezeTick($pdo, $freezeBudgetSeconds, 0, $isQuiet ? null : 'orbitraSpoolLog');

    // --- 3. VACUUM worker spawn (nohup, separate process) ------------------
    try {
        $vacState = $pdo->query("SELECT value FROM settings WHERE key = 'vacuum_conversion_state'")->fetchColumn();
        if ($vacState === 'requested') {
            $vacScript = __DIR__ . '/vacuum_convert.php';
            if (is_file($vacScript)) {
                $vacLog = __DIR__ . '/../var/logs/vacuum_convert.log';
                @mkdir(dirname($vacLog), 0777, true);
                $vacCmd = 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($vacScript)
                    . ' >> ' . escapeshellarg($vacLog) . ' 2>&1 &';
                proc_close(proc_open($vacCmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $vacPipes));
                orbitraSpoolLog('vacuum conversion worker spawned');
            }
        }
    } catch (\Throwable $e) {
        // Settings table missing (half-migrated): the next tick retries.
    }

    // --- 4. WAL checkpoint loop (the remaining tick budget) ----------------
    orbitraWalCheckpointLoop($pdo, $tickStartedAt, $walBudgetSeconds, $isQuiet);

    // --- 5. incremental_vacuum (large doses when the WAL is quiet) ---------
    try {
        $avMode = (int) $pdo->query('PRAGMA auto_vacuum')->fetchColumn();
        if ($avMode === 2) {
            $freelist = (int) $pdo->query('PRAGMA freelist_count')->fetchColumn();
            if ($freelist > 1000) {
                $dbFileNow = $pdo->query("SELECT file FROM pragma_database_list WHERE name = 'main'")->fetchColumn() ?: '';
                $walNow = is_file($dbFileNow . '-wal') ? (int) filesize($dbFileNow . '-wal') : 0;
                $dose = $walNow < 67108864 ? 2000 : 100;
                $pdo->exec("PRAGMA incremental_vacuum({$dose})");
            }
        }
    } catch (\Throwable $e) {
        // Read-only DB or transient lock: the next tick retries.
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
 * The WAL peak under sustained load is bounded by the three tiers listed
 * above (the full history is in core/wal_policy.php): opportunistic
 * restarts while the log is small, the quiet fold past 1 GB once traffic
 * drains, and the space-forced RESTART (busy_timeout = 1000 ms, at most
 * once a minute — the only 1 s wait anywhere in the design) when the disk
 * actually needs the space. The soak pair behind the 1 s wait: a 250 ms
 * wait on the WEB path turns a big WAL into an error avalanche (ТЗ part 3).
 *
 * Only this loop ever shrinks the -wal file: web connections run with
 * journal_size_limit = -1, and the TRUNCATE here fires only once the log
 * is nearly still (< 0.2 MB/s), never under live traffic.
 *
 * ORBITRA_WAL_FORCED_BYTES (env) overrides the forced threshold for tests,
 * ORBITRA_WAL_RESTART_SECS (env) the opportunistic interval — same pattern.
 *
 * The pulse also counts "natural" restarts: every pass where the -wal file is
 * SMALLER than on the previous pass — the log was restarted without our
 * RESTART machinery landing (a PASSIVE that fully checkpointed with no
 * reader in the way, or a web connection's autocheckpoint). Next to
 * restarts_ok it shows how much of the WAL keeping happens for free.
 *
 * @return array{passes:int, restarts_ok:int, restarts_busy:int, forced_restarts:int,
 *               natural_restarts:int, max_wal_bytes:int, max_ms:float, max_block_ms:float, at:int}
 */
function orbitraWalCheckpointLoop(PDO $pdo, float $tickStartedAt, float $budgetSeconds, bool $quiet): array
{
    require_once __DIR__ . '/../core/wal_policy.php';
    $stats = [
        'passes' => 0, 'restarts_ok' => 0, 'restarts_busy' => 0, 'forced_restarts' => 0,
        'natural_restarts' => 0, 'quiet_restarts' => 0, 'quiet_truncates' => 0, 'max_wal_bytes' => 0, 'max_ms' => 0.0, 'max_block_ms' => 0.0, 'at' => time(),
        // Space context for the panel («System status») and the post-mortems:
        // what the forced tier was actually looking at when it last decided.
        'wal_bytes' => 0, 'disk_free_bytes' => null, 'wal_space_warned' => false,
        // mon201: a WAL past the quiet threshold waits for traffic to drain;
        // the panel says so instead of showing a growing number in silence.
        'quiet_pending' => false, 'wal_growth_10s' => 0,
        // WAL DIVERT (rb21 stand fix): cumulative counters carried across
        // ticks (read from the previous state file, never reset).
        'divert_on_total' => 0, 'divert_off_total' => 0, 'divert_active' => false,
    ];
    // Carry the divert counters forward from the previous state file.
    {
        $prevState = @json_decode((string) @file_get_contents(WAL_STATE_FILE), true);
        if (is_array($prevState)) {
            $stats['divert_on_total'] = (int) ($prevState['divert_on_total'] ?? 0);
            $stats['divert_off_total'] = (int) ($prevState['divert_off_total'] ?? 0);
        }
    }

    // Test hooks (production keeps the compiled-in defaults): the legacy
    // fixed threshold for the old-style tests, and a fake free-space reading
    // so the space logic is testable without actually filling a disk.
    $forcedThreshold = null;
    $forcedEnv = getenv('ORBITRA_WAL_FORCED_BYTES');
    if (is_numeric($forcedEnv) && (int) $forcedEnv > 0) {
        $forcedThreshold = (int) $forcedEnv;
    }
    $diskFreeOverride = getenv('ORBITRA_WAL_DISK_FREE_BYTES');
    $diskFreeOverride = is_numeric($diskFreeOverride) && (int) $diskFreeOverride >= 0 ? (int) $diskFreeOverride : null;
    // And the quiet-tier knobs: the threshold that stands the opportunistic
    // tier down, and the growth window that defines "quiet".
    $quietThreshold = WAL_QUIET_THRESHOLD;
    $quietEnv = getenv('ORBITRA_WAL_QUIET_BYTES');
    if (is_numeric($quietEnv) && (int) $quietEnv > 0) {
        $quietThreshold = (int) $quietEnv;
    }
    $restartInterval = WAL_RESTART_INTERVAL;
    $restartEnv = getenv('ORBITRA_WAL_RESTART_SECS');
    if (is_numeric($restartEnv) && (float) $restartEnv >= 0) {
        $restartInterval = (float) $restartEnv;
    }
    // Test-only override of the 64 MB opportunistic gate, same pattern as the
    // two above. The wal_restart_under_readers test must drive the restart
    // machinery deterministically; once the click path stopped pinning the log
    // (ТЗ 1.9.7: the router alias statement release) the WAL folds naturally
    // below 64 MB at the test's rate, the gate never opens and the machinery
    // would go untested. Production keeps the compiled-in default.
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

    // ORBITRA_WAL_TRACE=1 (default off): one CSV line per checkpoint call
    // and per pass into var/logs/wal_trace.log —
    // ts_start_us,mode,duration_ms,busy,log,checkpointed,wal_bytes_before,
    // wal_bytes_after,wait_ms,pass_duration_ms. Pure observation; no logic
    // depends on it. wait_ms is the schedule skew (how late past the planned
    // pass start the call began); lock/busy waiting happens INSIDE
    // duration_ms. ts is assembled from integer seconds + fraction: a single
    // $ts * 1e6 multiply loses distinctness at the ~1.8e15 magnitude.
    $walTraceOn = getenv('ORBITRA_WAL_TRACE') === '1';
    $walTraceFile = __DIR__ . '/../var/logs/wal_trace.log';
    $walTrace = static function (float $tsStart, string $mode, float $durationMs, int $busy, int $log, int $checkpointed, int $walBefore, int $walAfter, float $waitMs, float $passDurationMs) use ($walTraceOn, $walTraceFile): void {
        if (!$walTraceOn) {
            return;
        }
        @mkdir(dirname($walTraceFile), 0770, true);
        $tsUs = (int) floor($tsStart) * 1000000 + (int) round(fmod($tsStart, 1.0) * 1000000.0);
        @file_put_contents($walTraceFile, sprintf(
            "%d,%s,%.3f,%d,%d,%d,%d,%d,%.3f,%.3f\n",
            $tsUs, $mode, $durationMs, $busy, $log, $checkpointed, $walBefore, $walAfter, $waitMs, $passDurationMs
        ), FILE_APPEND);
    };

    // busy_timeout shrinks to $busyMs for the call and is restored after.
    // busy=1 (live readers) is EXPECTED, not an error.
    // $traceMode feeds the ORBITRA_WAL_TRACE line ('RESTART' or 'forced').
    $shortCheckpoint = static function (string $kind, int $busyMs, string $traceMode = 'RESTART') use ($pdo, &$stats, $walTrace, $walSize, $walTraceOn): int {
        $prevTimeout = 30000;
        try {
            $prevTimeout = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();
        } catch (\Throwable $e) {
            // keep the CLI default
        }
        $walBefore = $walTraceOn ? $walSize() : 0;
        $t0 = microtime(true);
        $row = null;
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
        if ($walTraceOn) {
            $walTrace($t0, $traceMode, $elapsedMs, $busy, (int) ($row[1] ?? -1), (int) ($row[2] ?? -1), $walBefore, $walSize(), 0.0, 0.0);
        }
        return $busy;
    };

    $lastRestartAt = 0.0;
    $lastForcedAt = 0.0;
    $prevSize = null;
    $passScheduledAt = $tickStartedAt;
    // Quiet-window samples for the tier-2 fold: [timestamp, wal_bytes] per
    // pass, kept for WAL_QUIET_WINDOW_SECS. A freshly started tick has no
    // window — never quiet (a mid-burst start must not look like drained
    // traffic).
    $quietSamples = [];
    while (true) {
        $passStart = microtime(true);
        $size = $walSize();
        $stats['max_wal_bytes'] = max($stats['max_wal_bytes'], $size);
        // Space context for the panel and the post-mortems (mon198): the WAL
        // size RIGHT NOW and the free space the forced tier keys on. The env
        // override keeps the tests deterministic without filling a disk.
        clearstatcache(true, $dbFile);
        $diskFree = $diskFreeOverride ?? ($dbFile !== '' && is_file($dbFile) ? @disk_free_space(dirname($dbFile)) : null);
        $diskFree = \is_int($diskFree) || \is_float($diskFree) ? (int) $diskFree : null;
        $stats['wal_bytes'] = $size;
        $stats['disk_free_bytes'] = $diskFree;
        $stats['wal_space_warned'] = orbitraWalSpaceWarned($size, $diskFree, WAL_WARN_SPACE_RATIO);
        // Quiet-window bookkeeping: the sample goes in BEFORE the PASSIVE
        // pass may fold the log (a fold inside this pass is not "growth").
        $quietSamples[] = [$passStart, $size];
        while (count($quietSamples) > 1 && ($passStart - $quietSamples[0][0]) > WAL_QUIET_WINDOW_SECS + WAL_PASSIVE_INTERVAL) {
            array_shift($quietSamples);
        }
        $stats['wal_growth_10s'] = max(0, $size - $quietSamples[0][1]);
        $isQuiet = orbitraWalQuietMoment($quietSamples, $passStart, WAL_QUIET_WINDOW_SECS, WAL_QUIET_RATE_MAX);
        $canTruncate = orbitraWalTruncateAllowed($quietSamples, $passStart, WAL_QUIET_WINDOW_SECS, WAL_TRUNCATE_RATE_MAX);
        $stats['quiet_pending'] = $size > $quietThreshold && !$isQuiet;
        // Smaller than the previous pass = the log was restarted in between
        // without our RESTART machinery having to land. Counted per shrink,
        // deliberately simple: next to restarts_ok it shows how much of the
        // WAL keeping happens "for free" (ТЗ part 5).
        if ($prevSize !== null && $size < $prevSize) {
            $stats['natural_restarts']++;
        }
        $prevSize = $size;

        $t0 = microtime(true);
        $passiveRow = $pdo->query('PRAGMA wal_checkpoint(PASSIVE)')->fetch(PDO::FETCH_NUM) ?: [1, -1, -1];
        $passiveCallMs = (microtime(true) - $t0) * 1000.0;
        $stats['passes']++;
        $stats['max_ms'] = max($stats['max_ms'], $passiveCallMs);

        // Tier 1 — opportunistic RESTART (zero wait, past 64 MB, ≥ 30 s
        // apart) but ONLY while the WAL is ≤ the quiet threshold: mon201
        // measured a successful zero-wait RESTART at 8.09 GB blocking
        // writers 3.6 s, and the journal_size_limit truncation that followed
        // landed inside the first click after it. Cheap restarts keep the
        // log small at moderate traffic (the 1.9.6 behavior); past 1 GB a
        // restart stops being cheap and the quiet tier below owns the fold.
        if ($size > $restartThreshold
            && orbitraWalOpportunisticAllowed($size, $quietThreshold)
            && (microtime(true) - $lastRestartAt) >= $restartInterval) {
            $lastRestartAt = microtime(true);
            $shortCheckpoint('RESTART', 0);
        }

        // Tier 2 — the quiet fold: a WAL past the threshold folds only when
        // the log grew < 2 MB/s on average over the last 10 s (traffic
        // drained enough — the mon201 post-load observation: 8.6 GB folded
        // in ~1 min, no pause; a rate, not a byte budget, so background
        // 50–100 rps still folds). RESTART with a short bounded wait; the
        // TRUNCATE behind it — the ONLY place the -wal file is ever shrunk
        // (web connections run with journal_size_limit = -1) — fires only
        // when the log is nearly still (< 0.2 MB/s). A merely-slow log
        // keeps the big file: it is reused from the start, and an ftruncate
        // under live writers is the mon201 click-path stall. The shared
        // $lastRestartAt cadence keeps tiers 1 and 2 from stacking two folds.
        if ($size > $quietThreshold && $isQuiet && (microtime(true) - $lastRestartAt) >= $restartInterval) {
            $lastRestartAt = microtime(true);
            $stats['quiet_restarts']++;
            $shortCheckpoint('RESTART', WAL_QUIET_BUSY_MS, 'quiet');
            if ($canTruncate) {
                $stats['quiet_truncates']++;
                $shortCheckpoint('TRUNCATE', 0, 'quiet-truncate');
            }
            // The fold restarted the log — the growth window is meaningless now.
            $quietSamples = [[$passStart, $walSize()]];
            $stats['quiet_pending'] = false;
        }

        // Tier 3 — forced: disk-space safety only, 1 s wait, ≤ 1/min. WAL >
        // 50% of the partition's free space or the partition nearly full
        // (< 2 GB); NO byte cap — the 8 GB cap is what pushed the big-WAL
        // fold under load in mon201 — and 50% because a fold needs almost
        // no extra space (frames move into the already-allocated db file):
        // at 25% the stand arithmetic (61 GB free) would force a mid-load
        // fold of a ~12 GB WAL at the 11th minute of the run. On a roomy
        // disk the WAL grows through the load and the quiet fold collects
        // it after. Runs regardless of the tier-1 size gate (a disk filling
        // up must not wait for quiet).
        // ORBITRA_WAL_FORCED_BYTES restores the fixed threshold for tests.
        $forcedWanted = $forcedThreshold !== null
            ? $size > $forcedThreshold
            : orbitraWalForcedSpaceWanted($size, $diskFree, WAL_FORCED_SPACE_RATIO, WAL_FORCED_FREE_FLOOR);
        if ($forcedWanted && (microtime(true) - $lastForcedAt) >= WAL_FORCED_INTERVAL) {
            $lastForcedAt = microtime(true);
            $stats['forced_restarts']++;
            $shortCheckpoint('RESTART', WAL_FORCED_BUSY_MS, 'forced');
        }

        // WAL DIVERT (rb21 stand fix): the forced RESTART alone cannot save
        // the disk under sustained load — a 63 GB WAL at ~700 IOPS meant the
        // backfill never caught up and the partition filled. When the same
        // disk-risk condition fires, a flag file diverts clicks to the spool
        // (the same file_exists path click_logger uses for VACUUM). Without
        // new WAL frames the PASSIVE checkpoint folds the log in one pass;
        // when the WAL drops below the quiet threshold the flag lifts and
        // the spool replays in the normal cron tick.
        {
            $divertFlag = __DIR__ . '/../var/cache/wal_divert';
            $divertNeeded = $forcedWanted;
            if ($diskFreeOverride !== null) {
                $divertNeeded = $forcedThreshold !== null
                    ? $size > $forcedThreshold
                    : ($size > $diskFreeOverride * WAL_FORCED_SPACE_RATIO || $diskFreeOverride < WAL_FORCED_FREE_FLOOR);
            }
            if ($divertNeeded && !is_file($divertFlag)) {
                @mkdir(dirname($divertFlag), 0777, true);
                @file_put_contents($divertFlag, (string) time());
                $stats['divert_on_total']++;
                fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] WAL DIVERT ON: WAL ' . round($size / 1048576, 1)
                    . ' MB, disk free ' . ($diskFree !== null ? round($diskFree / 1073741824, 1) . ' GB' : 'N/A')
                    . ' — clicks go to the spool until the WAL folds' . PHP_EOL);
            } elseif (!$divertNeeded && is_file($divertFlag) && $size < $quietThreshold) {
                @unlink($divertFlag);
                $stats['divert_off_total']++;
                fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] WAL DIVERT OFF: WAL folded to ' . round($size / 1048576, 1)
                    . ' MB — clicks resume normal writes' . PHP_EOL);
            }
            $stats['divert_active'] = is_file($divertFlag);
        }

        // The pass line goes last so pass_duration_ms covers everything the
        // pass did (PASSIVE + any RESTART of the same pass).
        if ($walTraceOn) {
            $walTrace(
                $passStart,
                'PASSIVE',
                $passiveCallMs,
                (int) ($passiveRow[0] ?? 1),
                (int) ($passiveRow[1] ?? -1),
                (int) ($passiveRow[2] ?? -1),
                $size,
                $walSize(),
                max(0.0, ($passStart - $passScheduledAt) * 1000.0),
                (microtime(true) - $passStart) * 1000.0
            );
        }

        $next = microtime(true) + WAL_PASSIVE_INTERVAL;
        $passScheduledAt = $next;
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
            'WAL checkpoint: %d passive, %d restart ok / %d busy, %d forced, %d quiet (%d truncated), %d natural, max WAL %.1f MB, slowest %.1f ms, longest block %.1f ms',
            $stats['passes'],
            $stats['restarts_ok'],
            $stats['restarts_busy'],
            $stats['forced_restarts'],
            $stats['quiet_restarts'],
            $stats['quiet_truncates'],
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
    // idx_clicks_ip_created handoff (stand incident, 2026-11-21): migration 84
    // only drops it when the ip_ua index ALREADY exists; on a database
    // snapshotted before this worker's first tick (the load stand's
    // clean.sqlite) the drop is deferred to HERE — the one place that knows
    // the replacement is real. Safe the moment the CREATE INDEX lands: the
    // probes this index served run on the ip_ua (ip, ...) prefix from that
    // instant (including the legacy NULL-hash branch — it also seeks by the
    // ip prefix); the backfill that follows only rewrites ua_hash values.
    // Idempotent, one sqlite_master probe per tick.
    try {
        $ipCreatedExists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = 'idx_clicks_ip_created'")->fetchColumn();
        if ($ipCreatedExists
            && $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = 'idx_clicks_ip_ua_created'")->fetchColumn()) {
            $pdo->exec('DROP INDEX IF EXISTS idx_clicks_ip_created');
            orbitraSpoolLog('idx_clicks_ip_ua_created ready — dropped the retired idx_clicks_ip_created');
        }
    } catch (\Throwable $e) {
        // Next tick retries; until then the probes keep using ip_created,
        // which still exists — no gap either way.
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
    // ТЗ 2.1: ALL post-spool steps (recovery + freeze + vacuum spawn + WAL +
    // incremental_vacuum) live in one function called by BOTH paths.
    orbitraSpoolPostSteps($pdo, $tickStartedAt, $freezeBudgetSeconds, $walBudgetSeconds, $isQuiet);
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

// ТЗ 2.1: ALL post-spool steps in one function — same call as the fast path.
orbitraSpoolPostSteps($pdo, $tickStartedAt, $freezeBudgetSeconds, $walBudgetSeconds, $isQuiet);

if ($lockFp) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

orbitraSpoolLog("Spool replay: $inserted inserted, $requeued back in the spool, $dead parked in clicks.dead.log");
exit(0);
