<?php
/**
 * TZ_LOAD_PERFORMANCE_2, part 5 — two pulse fields of the WAL checkpoint
 * loop, unit level (scratch DB + the real cron script, no load run):
 *
 *  max_block_ms — must account for EVERY RESTART attempt (ТЗ 1.9.7
 *    round 3), landed or busy. A reader pinned inside the WAL (an open read
 *    transaction) makes the forced attempt wait out its second and return
 *    busy — that wait must land in max_block_ms.
 *
 *  natural_restarts — every pass where the -wal file is SMALLER than on the
 *    previous pass counts one. The -wal file shrinks only on the FIRST
 *    COMMIT after a completed restart (journal_size_limit truncation —
 *    probed directly: RESTART alone leaves the file as is), so the scenario
 *    runs a background writer through the tick: the restart lands, the
 *    writer's next commit folds the file to the 64 MB cap, and the following
 *    pass must count the shrink.
 *
 * Each scenario gets its own scratch tree (the leftover WAL state of one
 * interferes with the other's sizing).
 *
 * RED on 8d50b57: natural_restarts does not exist there (0), and a failed
 * forced restart never fed max_block_ms (it stays ~0).
 *
 * Usage: php tests/wal_checkpoint_pulse_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

$repoRoot = dirname(__DIR__);

/** A scratch tree with the cron, a patched config and core/, plus helpers. */
$makeScratch = static function (string $tag) use ($repoRoot): array {
    $work = sys_get_temp_dir() . '/orbitra_walpulse_' . $tag . '_' . getmypid();
    @mkdir($work . '/cli', 0777, true);
    register_shutdown_function(static function () use ($work): void {
        @unlink($work . '/bgwriter.stop');
        foreach (glob($work . '/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($work . '/cli/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (['/cli', '/var/cache', '/var/locks', '/var/spool', '/var', ''] as $d) {
            @rmdir($work . $d);
        }
    });
    copy($repoRoot . '/cli/click_spool_cron.php', $work . '/cli/click_spool_cron.php');
    $configSrc = preg_replace(
        '/\$db_file\s*=\s*__DIR__\s*\.\s*[\'"]\/orbitra_db\.sqlite[\'"];/',
        '$db_file = __DIR__ . "/test.sqlite";',
        (string) file_get_contents($repoRoot . '/config.php')
    );
    file_put_contents($work . '/config.php', $configSrc);
    exec('cp -R ' . escapeshellarg($repoRoot . '/core') . ' ' . escapeshellarg($work . '/core') . ' 2>/dev/null');
    return [
        'dir' => $work,
        'db' => $work . '/test.sqlite',
        'run' => static function (float $budget, array $env = []) use ($work): void {
            $cmd = '';
            foreach ($env as $k => $v) {
                $cmd .= $k . '=' . escapeshellarg((string) $v) . ' ';
            }
            $cmd .= escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php')
                . ' --quiet --checkpoint-budget=' . $budget . ' 2>/dev/null';
            exec($cmd);
        },
        'pulse' => static function () use ($work): array {
            return json_decode((string) @file_get_contents($work . '/var/cache/wal_checkpoint.json'), true) ?: [];
        },
    ];
};

try {
    // --- Scenario B: the failed forced restart feeds max_block_ms -----------
    $s = $makeScratch('b');
    $s['run'](0.0); // schema via migrations on the first tick

    $writer = new PDO('sqlite:' . $s['db'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $writer->exec('PRAGMA wal_autocheckpoint = 0;');
    $writer->exec('CREATE TABLE IF NOT EXISTS pulse_blob (id INTEGER PRIMARY KEY, data TEXT)');
    $ins = $writer->prepare('INSERT INTO pulse_blob (data) VALUES (?)');
    $walPath = $s['db'] . '-wal';
    $guard = 0;
    while ($guard++ < 600) {
        clearstatcache();
        if (is_file($walPath) && (int) filesize($walPath) > 68 * 1048576) {
            break;
        }
        for ($i = 0; $i < 200; $i++) {
            $ins->execute([str_repeat('y', 24000)]);
        }
    }
    check(is_file($walPath) && (int) filesize($walPath) > 67108864,
        'the journal was grown past the 64 MB gate (' . round(((int) @filesize($walPath)) / 1048576) . ' MB)');

    // An open read transaction blocks any RESTART, so the forced attempt
    // (busy_timeout = 1000) waits out its second and returns busy.
    file_put_contents($s['dir'] . '/lt_pin_reader.php', <<<'PHP'
<?php
$pdo = new PDO('sqlite:' . __DIR__ . '/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('BEGIN');
$pdo->query('SELECT COUNT(*) FROM pulse_blob')->fetchColumn();
usleep(30000000); // hold the read mark
PHP
    );
    $reader = proc_open([PHP_BINARY, $s['dir'] . '/lt_pin_reader.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
    usleep(300000); // let the reader pin its mark before the tick
    $s['run'](0.0, ['ORBITRA_WAL_FORCED_BYTES' => 1048576]);
    if (is_resource($reader)) {
        proc_terminate($reader);
    }
    unset($writer); // release this scratch before the next one

    $stateB = $s['pulse']();
    check(($stateB['forced_restarts'] ?? 0) >= 1, 'the forced restart was attempted (got ' . ($stateB['forced_restarts'] ?? 'missing') . ')');
    check(($stateB['restarts_busy'] ?? 0) >= 2, 'both restart calls returned busy under the pinned reader (got ' . ($stateB['restarts_busy'] ?? 'missing') . ')');
    check((float) ($stateB['max_block_ms'] ?? 0) >= 700.0,
        sprintf('the ~1 s the FAILED forced restart waited is in max_block_ms (got %.0f ms)', (float) ($stateB['max_block_ms'] ?? 0)));
    check(($stateB['natural_restarts'] ?? 0) === 0, 'no shrink happened while the reader pinned the log');

    // --- Scenario A: the fold is counted as a natural restart ----------------
    // Fresh tree (the other scenario's leftover WAL state interferes with the
    // sizing). Grow past the gate, run a background writer through the tick
    // (the shrink lands on the first COMMIT after a completed restart), with
    // the opportunistic interval lowered — a zero-wait attempt can catch the
    // writer mid-commit and the production 30 s gate allows no quick retry.
    $a = $makeScratch('a');
    $a['run'](0.0); // schema

    $writerA = new PDO('sqlite:' . $a['db'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $writerA->exec('PRAGMA wal_autocheckpoint = 0;');
    $writerA->exec('CREATE TABLE IF NOT EXISTS pulse_blob (id INTEGER PRIMARY KEY, data TEXT)');
    $insA = $writerA->prepare('INSERT INTO pulse_blob (data) VALUES (?)');
    $walPathA = $a['db'] . '-wal';
    $guard = 0;
    while ($guard++ < 600) {
        clearstatcache();
        if (is_file($walPathA) && (int) filesize($walPathA) > 68 * 1048576) {
            break;
        }
        for ($i = 0; $i < 200; $i++) {
            $insA->execute([str_repeat('y', 24000)]);
        }
    }
    file_put_contents($a['dir'] . '/lt_bg_writer.php', <<<'PHP'
<?php
$pdo = new PDO('sqlite:' . __DIR__ . '/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA wal_autocheckpoint = 0;');
// The truncation on commit belongs to the COMMITTING connection — without
// this pragma (which every real Orbitra connection gets from config.php)
// the fold never happens here either.
$pdo->exec('PRAGMA journal_size_limit = 67108864;');
$ins = $pdo->prepare('INSERT INTO pulse_blob (data) VALUES (?)');
while (!is_file(__DIR__ . '/bgwriter.stop')) {
    $ins->execute([str_repeat('z', 100)]);
    usleep(300000);
}
PHP
    );
    $bgWriter = proc_open([PHP_BINARY, $a['dir'] . '/lt_bg_writer.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $bgPipes);
    usleep(200000);

    clearstatcache();
    check(is_file($walPathA) && (int) filesize($walPathA) > 67108864,
        'scenario A starts with the journal past the gate (' . round(((int) @filesize($walPathA)) / 1048576) . ' MB)');
    $a['run'](6.0, ['ORBITRA_WAL_RESTART_SECS' => '2']);
    file_put_contents($a['dir'] . '/bgwriter.stop', 'stop');
    if (is_resource($bgWriter)) {
        proc_terminate($bgWriter);
    }
    unset($writerA);

    $stateA = $a['pulse']();
    check(($stateA['restarts_ok'] ?? 0) >= 1, 'the freed log was restarted (restarts_ok = ' . ($stateA['restarts_ok'] ?? 'missing') . ')');
    check(($stateA['natural_restarts'] ?? 0) >= 1,
        'the pass after the fold counts the smaller -wal as a natural restart (got ' . ($stateA['natural_restarts'] ?? 'missing') . ')');
    clearstatcache();
    check(!is_file($walPathA) || (int) filesize($walPathA) <= 67108864,
        'the file itself ended at the 64 MB cap (' . round(((int) @filesize($walPathA)) / 1048576) . ' MB)');
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo $failures === 0 ? "WAL checkpoint pulse tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
