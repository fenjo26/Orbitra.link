<?php
// cli/vacuum_convert.php
//
// ТЗ 2.1, stage 4: the background VACUUM worker. Spawned by the spool cron
// (nohup, NOT inside its tick budget) when the admin presses the
// "Enable incremental vacuum" button. The button only sets a settings flag;
// this script does the actual PRAGMA auto_vacuum = INCREMENTAL + VACUUM.
//
// While VACUUM runs, the database is exclusively locked: clicks spool up
// (the existing click_logger → spool mechanism) and replay after VACUUM
// finishes. The operator sees "clicks may pause" in the confirm dialog.
//
// State machine (settings table):
//   vacuum_conversion_state: 'requested' → 'running' → 'done' | 'error:<msg>'
//   vacuum_conversion_started_at / vacuum_conversion_finished_at
//
// Usage: php cli/vacuum_convert.php   (spawned by click_spool_cron.php)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';

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

try {
    // Only run when requested (the button set the flag).
    $requested = $pdo->query("SELECT value FROM settings WHERE key = 'vacuum_conversion_state'")->fetchColumn();
    if ($requested !== 'requested') {
        orbitraVacuumLog('Not requested (state=' . var_export($requested, true) . '), exiting.');
        exit(0);
    }

    $mode = (int) $pdo->query('PRAGMA auto_vacuum')->fetchColumn();
    if ($mode === 2) {
        orbitraVacuumLog('Already INCREMENTAL, marking done.');
        orbitraVacuumSetState('done');
        exit(0);
    }

    // Disk space gate: VACUUM in WAL = temp copy + WAL growth → 2.2×.
    $dbFile = $pdo->query("SELECT file FROM pragma_database_list WHERE name = 'main'")->fetchColumn() ?: '';
    $dbSize = is_file($dbFile) ? (int) filesize($dbFile) : 0;
    $free = disk_free_space(dirname($dbFile) ?: '.');
    $required = (int) ($dbSize * 2.2);
    if ($free !== false && $free < $required) {
        orbitraVacuumSetState('error:Not enough disk space: need ~' . round($required / 1073741824, 1)
            . ' GB free (2.2× the database), have ' . round($free / 1073741824, 1) . ' GB');
        orbitraVacuumLog('ABORT: not enough disk space.');
        exit(1);
    }

    // Signal: running. From this moment the operator sees it in System Status.
    orbitraVacuumSetState('running');
    $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('vacuum_conversion_started_at', ?, datetime('now'))
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([date('Y-m-d H:i:s')]);

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
    orbitraVacuumLog("VACUUM done in {$elapsed}s: auto_vacuum={$newMode}, freelist={$freelist}.");
} catch (\Throwable $e) {
    orbitraVacuumSetState('error:' . substr($e->getMessage(), 0, 200));
    orbitraVacuumLog('FAILED: ' . $e->getMessage());
} finally {
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
