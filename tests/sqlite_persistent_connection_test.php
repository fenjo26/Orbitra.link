<?php
/**
 * TZ_LOAD_PERFORMANCE_2, stage 1: web requests keep ONE SQLite connection
 * open per worker (PDO::ATTR_PERSISTENT) instead of opening and closing one
 * per request.
 *
 * RED on c43ce94: closing the last connection on every request made SQLite
 * checkpoint and DELETE the -wal file after each click, so the next request
 * rebuilt it — 4 disk syncs per click. This test watches for the -wal file
 * BETWEEN requests and fails while it keeps disappearing.
 *
 * The transaction/snapshot cases are regression guards for the persistence
 * guard in config.php; they also pass on the old code by design: what they
 * pin is that persistence never makes a worker inherit a dead request's
 * open transaction or its read snapshot.
 *
 * Runs over real HTTP against php -S forced to ONE worker (the harness env
 * knob), so "the next request on the same worker" is a real, testable thing.
 * The production router serves the probe file as-is (file_exists pass-through).
 *
 * Usage: php tests/sqlite_persistent_connection_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/http.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

// One worker: every request below must land on the process whose persistent
// handle the previous request left behind.
putenv('ORBITRA_TEST_SERVER_WORKERS=1');

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();
$harness->start();

try {
    $work = $harness->getWorkingDir();
    $dbPath = $work . '/orbitra_test.sqlite';

    // Probe endpoints live only in the sandbox; each case uses the SAME
    // config.php (and therefore the same persistent handle policy) as the app.
    file_put_contents($work . '/lt_persist_probe.php', <<<'PROBE'
<?php
require_once __DIR__ . '/config.php';
$case = $_GET['c'] ?? 'ping';
switch ($case) {
    case 'ping':
        echo 'pong';
        break;
    case 'txn_state':
        echo $pdo->inTransaction() ? '1' : '0';
        break;
    case 'txn_abandon':
        // The crash stand-in: open a transaction, write a row, exit without
        // commit or rollback.
        $pdo->beginTransaction();
        $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('persist_probe_zombie', 'yes')");
        echo 'abandoned';
        break;
    case 'write_marker':
        $st = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $st->execute([$_GET['k'] ?? 'persist_probe_b', $_GET['v'] ?? 'x']);
        echo 'wrote';
        break;
    case 'read_marker':
        $st = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
        $st->execute([$_GET['k'] ?? '']);
        echo (string) $st->fetchColumn();
        $st->closeCursor();
        break;
    case 'unread_select':
        // Fetch ONE row and end the request with the cursor still open —
        // the next request on this worker must not inherit that snapshot.
        $res = $pdo->query('SELECT key, value FROM settings');
        $first = $res->fetch(PDO::FETCH_ASSOC);
        echo $first ? (string) $first['key'] : 'none';
        break;
}
PROBE);

    $probe = static function (string $case, array $params = []) use ($harness): string {
        $qs = http_build_query(array_merge(['c' => $case], $params));
        return (string) $harness->get('/lt_persist_probe.php?' . $qs)['body'];
    };

    // First contact runs the schema migrations on the empty sandbox DB.
    check($probe('ping') === 'pong', 'probe answers through the production router');

    // --- Case A: the WAL file survives BETWEEN requests (red on 1.9.5) -----
    // No getPdo() until this loop is over: the test process itself must not
    // hold the last connection, or the -wal file would exist for that reason.
    $walSeenBetweenRequests = 0;
    for ($i = 0; $i < 25; $i++) {
        $probe('ping');
        if (is_file($dbPath . '-wal')) {
            $walSeenBetweenRequests++;
        }
    }
    check($walSeenBetweenRequests === 25,
        "the -wal file exists between all 25 requests (seen $walSeenBetweenRequests/25; " .
        'on 1.9.5 SQLite deleted it after every request)');

    // --- Case B: a transaction abandoned by a dead request -------------------
    $probe('txn_abandon');
    check($probe('txn_state') === '0', 'the next request starts outside any transaction');
    $probe('write_marker', ['k' => 'persist_probe_b', 'v' => 'written-after-zombie']);
    $pdo = $harness->getPdo();
    $zombie = $pdo->query("SELECT value FROM settings WHERE key = 'persist_probe_zombie'")->fetchColumn();
    $marker = $pdo->query("SELECT value FROM settings WHERE key = 'persist_probe_b'")->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM settings");
    $stmt->closeCursor();
    check($zombie === false, 'the abandoned transaction\'s row is NOT in the database');
    check($marker === 'written-after-zombie', 'the request after the zombie committed its own write');

    // --- Case C: a read snapshot does not leak into the next request --------
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('persist_probe_s1', 'v1')")->execute();
    $probe('unread_select'); // opens a cursor, ends the request with it open
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('persist_probe_s2', 'v2')")->execute();
    check($probe('read_marker', ['k' => 'persist_probe_s2']) === 'v2',
        'the worker sees a commit made by another connection after its open cursor ended');

    // --- Case D: writes still land (sanity for the whole click path) --------
    $probe('write_marker', ['k' => 'persist_probe_c', 'v' => 'final']);
    check($pdo->query("SELECT value FROM settings WHERE key = 'persist_probe_c'")->fetchColumn() === 'final',
        'a plain probe write persists through the persistent handle');
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    $harness->stop();
}

echo $failures === 0 ? "SQLite persistent connection tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
