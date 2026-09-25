<?php
/**
 * Stage 1 of docs/TZ_LOAD_PERFORMANCE.md: open read cursors on the click path
 * turn the later click INSERT into an immediate SQLITE_BUSY_SNAPSHOT.
 *
 * A single-row ->fetch() that FINDS a row leaves the statement open, and an
 * open statement holds a WAL read snapshot. If any other writer commits in
 * that window, the click INSERT of the SAME connection fails instantly with
 * "database is locked" (busy_timeout does not apply to BUSY_SNAPSHOT) and the
 * click goes to var/spool/clicks.log for up to a minute. Returning visitors
 * were hit every time: for unique visitors the uniqueness SELECT runs to
 * completion (no row) and releases the snapshot on its own.
 *
 * The test pins the production lookup helper
 * (orbitraFindUniquenessConflict from core/click_logger.php): after the check
 * the cursor must be closed, so a concurrent commit between the check and
 * orbitraPersistClick() must not break the INSERT. A test that greps the
 * source for closeCursor() would not be accepted; this one reproduces the
 * lock itself.
 *
 * Usage: php tests/click_snapshot_lock_test.php
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

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->start();
try {
    $seed = $harness->seedTestData();
    $campaignId = (int) $seed['campaign_id'];

    $work = $harness->getWorkingDir();
    require_once $work . '/core/click_logger.php';

    // Two independent connections to the same sandbox DB, mirroring two
    // concurrent php-fpm workers. busy_timeout mirrors production (5 s web):
    // the point of the test is that the snapshot error fires DESPITE it.
    $pdoA = $harness->getPdo();
    $pdoB = $harness->getPdo();
    $pdoA->exec('PRAGMA busy_timeout = 5000');
    $pdoB->exec('PRAGMA busy_timeout = 5000');

    $journal = (string) $pdoA->query('PRAGMA journal_mode')->fetchColumn();
    check(strtolower($journal) === 'wal', 'sandbox DB is in WAL mode (precondition)');

    $ip = '203.0.113.50';
    $ua = 'SnapLockAgent/1.0';

    // A previous click from the same visitor: the uniqueness/debounce lookups
    // must FIND it — that is exactly the branch that used to leak the cursor.
    $pdoA->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, created_at)
                    VALUES ('snap-prev', ?, ?, ?, datetime('now', '-1 hour'))")
        ->execute([$campaignId, $ip, $ua]);

    $rowFor = static function (string $id) use ($campaignId, $ip, $ua): array {
        return [
            'click_id' => $id,
            'campaign_id' => $campaignId,
            'ip' => $ip,
            'user_agent' => $ua,
        ];
    };
    $clickExists = static function (string $id) use ($harness): bool {
        $pdo = $harness->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clicks WHERE id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn() === 1;
    };
    $spoolPath = $work . '/var/spool/clicks.log';
    $spoolEmpty = static function () use ($spoolPath): bool {
        return !is_file($spoolPath) || filesize($spoolPath) === 0;
    };

    // --- 1. the raw v1.6.2 pattern really locks (mechanism pin) ---------------
    // Same statement shape the inline code used before the fix, cursor left
    // open on purpose. This documents WHY the helpers must close the cursor:
    // if a future SQLite/PDO stops throwing here, the pin below tells us the
    // reproduction lost its teeth. The INSERT runs in a function scope like
    // orbitraPersistClick() does — a statement variable that outlives the
    // failure would keep the read transaction (and its stale snapshot) open
    // for the rest of the request and poison every later write, which the
    // production code never does.
    $rawStmt = $pdoA->prepare("SELECT id FROM clicks WHERE campaign_id = ? AND ip = ? AND user_agent = ? AND created_at >= ? LIMIT 1");
    $rawStmt->execute([$campaignId, $ip, $ua, date('Y-m-d H:i:s', time() - 86400)]);
    $rawFound = (bool) $rawStmt->fetch(); // cursor stays open — the old bug
    $pdoB->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, created_at)
                    VALUES ('snap-other', ?, '198.51.100.9', 'OtherAgent/2.0', datetime('now'))")
        ->execute([$campaignId]);
    $locked = false;
    $t0 = microtime(true);
    try {
        $persistRaw = static function () use ($pdoA, $campaignId, $ip, $ua): void {
            $ins = $pdoA->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, created_at)
                                   VALUES ('snap-raw', ?, ?, ?, datetime('now'))");
            $ins->execute([$campaignId, $ip, $ua]);
        };
        $persistRaw();
    } catch (\Throwable $e) {
        $locked = stripos($e->getMessage(), 'database is locked') !== false;
    }
    $elapsedMs = (microtime(true) - $t0) * 1000;
    $rawStmt->closeCursor();
    unset($rawStmt);
    check($rawFound, 'uniqueness lookup finds the returning visitor');
    check($locked && $elapsedMs < 1000,
        'unclosed cursor + concurrent commit = instant "database is locked" (took ' . round($elapsedMs) . ' ms)');

    // --- 2. production uniqueness helper: cursor closed, INSERT survives ------
    $helperExists = function_exists('orbitraFindUniquenessConflict');
    check($helperExists, 'orbitraFindUniquenessConflict() exists (core/click_logger.php)');
    if ($helperExists) {
        $found = orbitraFindUniquenessConflict($pdoA, $campaignId, $ip, $ua, 24, 'IP_UA');
        check($found === true, 'uniqueness helper reports the returning visitor');

        // Another writer commits AFTER the read snapshot was taken.
        $pdoB->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, created_at)
                        VALUES ('snap-other-2', ?, '198.51.100.10', 'OtherAgent/3.0', datetime('now'))")
            ->execute([$campaignId]);

        $ok = orbitraPersistClick($pdoA, orbitraBuildClickRow($rowFor('snap-uniq')));
        check($ok, 'persisting the click right after the uniqueness check succeeded');
        check($clickExists('snap-uniq'), 'the click landed in the clicks table');
        check($spoolEmpty(), 'the spool stayed empty');

        // Unique-visitor branch: no row found, still must not leak a cursor.
        $foundUnique = orbitraFindUniquenessConflict($pdoA, $campaignId, '203.0.113.51', $ua, 24, 'IP_UA');
        $pdoB->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, created_at)
                        VALUES ('snap-other-3', ?, '198.51.100.11', 'OtherAgent/4.0', datetime('now'))")
            ->execute([$campaignId]);
        $okUnique = orbitraPersistClick($pdoA, orbitraBuildClickRow($rowFor('snap-unew')));
        check($foundUnique === false && $okUnique && $clickExists('snap-unew'),
            'unique-visitor branch persists across a concurrent commit');
    }
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage());
} finally {
    $harness->stop();
}

echo $failures === 0 ? "Click snapshot lock tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
