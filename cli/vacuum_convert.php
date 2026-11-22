<?php
// cli/vacuum_convert.php
//
// ТЗ 2.1, stage 4: the background VACUUM worker. Spawned by the spool cron
// (nohup, NOT inside its tick budget) when the admin presses the
// "Enable incremental vacuum" button. The button only sets a settings flag;
// this script does the actual PRAGMA auto_vacuum = INCREMENTAL + VACUUM.
//
// While VACUUM runs, the database is exclusively locked: click_logger checks
// var/cache/vacuum.running (a pid flag file, no DB query) and sends clicks
// straight to the spool. After VACUUM, the spool replays them.
//
// Temp files go to the DATABASE directory (SQLITE_TMPDIR before the
// connection opens) — /tmp may be tmpfs or a different partition.
//
// State machine (settings table):
//   vacuum_conversion_state: 'requested' → 'running' → 'done' | 'error:<msg>'
//   vacuum_conversion_started_at / vacuum_conversion_finished_at
//
// Crash recovery: the spool cron checks var/cache/vacuum.running — if the
// pid is dead, state → 'error: interrupted', flag removed, button re-enabled.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// SQLITE_TMPDIR must point at the database's partition BEFORE config.php
// opens the connection — VACUUM's temp copy lands there, not in /tmp.
$dbDir = dirname(__DIR__) . '/data';
if (!is_dir($dbDir)) {
    $dbDir = dirname(__DIR__);
}
putenv('SQLITE_TMPDIR=' . $dbDir);
putenv('TMPDIR=' . $dbDir);

require_once __DIR__ . '/../config.php';

const VACUUM_FLAG_FILE = __DIR__ . '/../var/cache/vacuum.running';

// --- Single-instance lock ------------------------------------------------------
$lockFile = __DIR__ . '/../var/locks/vacuum_convert.lock';
if (!is_dir(dirname($lockFile))) {
    @mkdir(dirname($lockFile), 0777, true);
}
$fp = @fopen($lockFile, 'c+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . '] Another vacuum conversion is already running.' . PHP_EOL;
    exit(0);
}
ftruncate($fp, 0);
fwrite($fp, (string) time());

function orbitraVacuumLog(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

function orbitraVacuumSetState(string $state): void
{
    global $pdo;
    $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('vacuum_conversion_state', ?, datetime('now'))
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')")
        ->execute([$state]);
}

function orbitraVacuumRemoveFlag(): void
{
    @unlink(VACUUM_FLAG_FILE);
}

try {
    $requested = $pdo->query("SELECT value FROM settings WHERE key = 'vacuum_conversion_state'")->fetchColumn();
    if ($requested !== 'requested') {
        orbitraVacuumLog('Not requested (state=' . var_export($requested, true) . '), exiting.');
        orbitraVacuumRemoveFlag();
        exit(0);
    }

    $mode = (int) $pdo->query('PRAGMA auto_vacuum')->fetchColumn();
    if ($mode === 2) {
        orbitraVacuumLog('Already INCREMENTAL, marking done.');
        orbitraVacuumSetState('done');
        orbitraVacuumRemoveFlag();
        exit(0);
    }

    // Disk space gate on the DATABASE partition (2.2×: temp copy + WAL).
    $dbFile = $pdo->query("SELECT file FROM pragma_database_list WHERE name = 'main'")->fetchColumn() ?: '';
    $dbDir = dirname($dbFile) ?: $dbDir;
    $dbSize = is_file($dbFile) ? (int) filesize($dbFile) : 0;
    $free = disk_free_space($dbDir);
    $required = (int) ($dbSize * 2.2);
    if ($free !== false && $free < $required) {
        orbitraVacuumSetState('error:Not enough disk space on the database partition: need ~' . round($required / 1073741824, 1)
            . ' GB free (2.2× the database), have ' . round($free / 1073741824, 1) . ' GB');
        orbitraVacuumRemoveFlag();
        orbitraVacuumLog('ABORT: not enough disk space on the database partition.');
        exit(1);
    }

    // Signal: running + the pid flag file (click_logger checks this).
    orbitraVacuumSetState('running');
    $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('vacuum_conversion_started_at', ?, datetime('now'))
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([date('Y-m-d H:i:s')]);
    @mkdir(dirname(VACUUM_FLAG_FILE), 0777, true);
    file_put_contents(VACUUM_FLAG_FILE, (string) getmypid());

    orbitraVacuumLog('Starting VACUUM (database ' . round($dbSize / 1073741824, 1) . ' GB, expected minutes)...');
    $t0 = microtime(true);

    $pdo->exec('PRAGMA auto_vacuum = INCREMENTAL');
    $pdo->exec('VACUUM');

    $elapsed = round(microtime(true) - $t0, 1);
    $freelist = (int) $pdo->query('PRAGMA freelist_count')->fetchColumn();
    $newMode = (int) $pdo->query('PRAGMA auto_vacuum')->fetchColumn();

    orbitraVacuumSetState('done');
    $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('vacuum_conversion_finished_at', ?, datetime('now'))
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([date('Y-m-d H:i:s')]);
    orbitraVacuumRemoveFlag();
    orbitraVacuumLog("VACUUM done in {$elapsed}s: auto_vacuum={$newMode}, freelist={$freelist}.");
} catch (\Throwable $e) {
    orbitraVacuumSetState('error:' . substr($e->getMessage(), 0, 200));
    orbitraVacuumRemoveFlag();
    orbitraVacuumLog('FAILED: ' . $e->getMessage());
} finally {
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// --- Crash recovery (called by the spool cron each tick) ------------------------
// A dead pid in the flag file means the worker was killed or the server
// restarted mid-VACUUM: mark interrupted, remove the flag, re-enable the button.

if (!function_exists('orbitraVacuumRecoverStale')) {
    function orbitraVacuumRecoverStale(PDO $pdo): void
    {
        if (!is_file(VACUUM_FLAG_FILE)) {
            return;
        }
        $pid = (int) @file_get_contents(VACUUM_FLAG_FILE);
        if ($pid <= 0) {
            return;
        }
        // Signal 0 = existence check; on error the process is gone.
        $alive = function_exists('posix_kill') ? @posix_kill($pid, 0) : (@file_exists("/proc/{$pid}") || stripos(PHP_OS, 'win') !== false);
        if (!$alive) {
            @unlink(VACUUM_FLAG_FILE);
            try {
                $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('vacuum_conversion_state', 'error:interrupted (worker pid ' . ? . ' died)', datetime('now'))
                               ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$pid]);
            } catch (\Throwable $e) {
                // Settings table unavailable: the flag removal is the recovery.
            }
        }
    }
}
