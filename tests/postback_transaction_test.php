<?php
// Contention and rollback tests on disposable SQLite. No application bootstrap
// or network; the second process is a concurrent database writer.
require_once __DIR__ . '/../core/PostbackDelivery.php';

$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . PHP_EOL;
    if (!$ok) $failures++;
};
$path = tempnam(sys_get_temp_dir(), 'orbitra_postback_tx_');
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$pdo = new PDO('sqlite:' . $path, null, null, $options);
$process = null;
$pipes = [];
try {
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('CREATE TABLE events (id INTEGER PRIMARY KEY, value TEXT)');
    $pdo->exec('CREATE TABLE marker (id INTEGER PRIMARY KEY, count INTEGER)');
    $pdo->exec('INSERT INTO marker VALUES (1, 0)');

    // The lock outlives one bounded attempt but is released before the retries
    // are exhausted. A fresh transaction must eventually persist exactly once.
    $writerCode = <<<'PHP'
$db = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('BEGIN IMMEDIATE');
$db->exec('UPDATE marker SET count=count+1');
fwrite(STDOUT, "locked\n");
fflush(STDOUT);
usleep(350000);
$db->exec('COMMIT');
PHP;
    $process = proc_open([PHP_BINARY, '-r', $writerCode, $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start lock fixture');
    fclose($pipes[0]);
    unset($pipes[0]);
    stream_set_timeout($pipes[1], 3);
    $check('concurrent writer acquired its lock', trim((string) fgets($pipes[1])) === 'locked');
    $result = orbitraPostbackTransaction($pdo, static function () use ($pdo): array {
        $pdo->exec("INSERT INTO events VALUES (1, 'committed')");
        return ['committed'];
    });
    $check('bounded retries succeed after the competing writer commits', $result === ['committed']);
    $check('retry commits once', (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn() === 1);
    $check('original connection timeout restored after success', (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn() === 5000);
    foreach ($pipes as $pipe) fclose($pipe);
    $pipes = [];
    $check('writer process exits successfully', proc_close($process) === 0);
    $process = null;

    // A retriable error after partial writes must rerun the whole callback from
    // a clean transaction. Fault injection is at the DB operation boundary.
    $attempts = 0;
    orbitraPostbackTransaction($pdo, static function () use ($pdo, &$attempts): array {
        $attempts++;
        $pdo->exec("INSERT INTO events VALUES (2, 'retried')");
        if ($attempts === 1) throw new PDOException('database is locked');
        return [];
    });
    $check('retry discards the previous partial transaction', $attempts === 2
        && (int) $pdo->query('SELECT COUNT(*) FROM events WHERE id=2')->fetchColumn() === 1);

    $attempts = 0;
    try {
        orbitraPostbackTransaction($pdo, static function () use ($pdo, &$attempts): array {
            $attempts++;
            $pdo->exec("INSERT INTO events VALUES (3, 'must roll back')");
            throw new RuntimeException('permanent insert failure');
        });
        $check('permanent failure escapes', false);
    } catch (RuntimeException $e) {
        $check('permanent failure is not retried', $attempts === 1 && $e->getMessage() === 'permanent insert failure');
    }
    $check('permanent failure rolls back the conversion unit', (int) $pdo->query('SELECT COUNT(*) FROM events WHERE id=3')->fetchColumn() === 0);

    $pdo->exec("CREATE TRIGGER auto_rollback BEFORE INSERT ON events WHEN NEW.id=6
        BEGIN SELECT RAISE(ROLLBACK, 'original transaction failure'); END");
    try {
        orbitraPostbackTransaction($pdo, static function () use ($pdo): array {
            $pdo->exec("INSERT INTO events VALUES (7, 'partial')");
            $pdo->exec("INSERT INTO events VALUES (6, 'automatic rollback')");
            return [];
        });
        $check('automatic rollback escapes with original failure', false);
    } catch (PDOException $e) {
        $check('automatic rollback preserves the original exception', strpos($e->getMessage(), 'original transaction failure') !== false);
    }
    $check('automatic rollback removes prior writes', (int) $pdo->query('SELECT COUNT(*) FROM events WHERE id IN (6,7)')->fetchColumn() === 0);
    $pdo->exec('DROP TRIGGER auto_rollback');

    $attempts = 0;
    try {
        orbitraPostbackTransaction($pdo, static function () use ($pdo, &$attempts): array {
            $attempts++;
            $pdo->exec("INSERT INTO events VALUES (4, 'must roll back')");
            throw new PDOException('database is locked');
        });
        $check('exhausted contention escapes', false);
    } catch (PDOException $e) {
        $check('contention retries are bounded', $attempts === 3);
    }
    $check('exhausted contention leaves no partial state', (int) $pdo->query('SELECT COUNT(*) FROM events WHERE id=4')->fetchColumn() === 0);
    $check('original connection timeout restored after failure', (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn() === 5000);
    $check('connection remains writable after rollback', orbitraPostbackTransaction($pdo, static function () use ($pdo): array {
        $pdo->exec("INSERT INTO events VALUES (5, 'after failure')");
        return ['ok'];
    }) === ['ok']);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $failures++;
} finally {
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    $pdo = null;
    // Only the explicit disposable fixture created by this test is removed.
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) if (is_file($file)) unlink($file);
}
echo "Failures: $failures\n";
exit($failures === 0 ? 0 : 1);
