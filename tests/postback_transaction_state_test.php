<?php
// Regression test for php bug #81227: before PHP 8.4, PDO::SQLite cannot see
// a transaction started with a raw BEGIN through exec() — inTransaction()
// returns false mid-transaction. The postback write path (v1.5.9) opens its
// writer lock exactly that way and asserts the transaction is alive, so on
// PHP <= 8.3 every incoming postback died with "Postback transaction ended
// before its durable writes completed" and answered 500.
//
// orbitraPostbackTransactionActive() hides the version difference. These
// checks exercise whichever branch the running interpreter uses (inTransaction
// on 8.4+, the BEGIN DEFERRED probe below), so run this file on BOTH an old
// and a new PHP to cover both code paths.
require_once __DIR__ . '/../core/PostbackDelivery.php';

$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . PHP_EOL;
    if (!$ok) $failures++;
};
$path = tempnam(sys_get_temp_dir(), 'orbitra_postback_txstate_');
$pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    $pdo->exec('CREATE TABLE probe (id INTEGER PRIMARY KEY, value TEXT)');

    // Autocommit: no transaction.
    $check('autocommit reports no transaction', orbitraPostbackTransactionActive($pdo) === false);

    // The exact way orbitraPostbackTransaction() opens its writer lock.
    $pdo->exec('BEGIN IMMEDIATE');
    $check('raw BEGIN IMMEDIATE is visible as a transaction', orbitraPostbackTransactionActive($pdo) === true);
    $pdo->exec('COMMIT');
    $check('commit returns the connection to autocommit', orbitraPostbackTransactionActive($pdo) === false);

    // Tracked transactions must keep working on every PHP version.
    $pdo->beginTransaction();
    $check('beginTransaction() is visible as a transaction', orbitraPostbackTransactionActive($pdo) === true);
    $pdo->rollBack();
    $check('rollBack returns the connection to autocommit', orbitraPostbackTransactionActive($pdo) === false);

    // The assert helper is what the postback path calls mid-transaction. On
    // PHP <= 8.3 with the pre-fix body it threw here unconditionally.
    $orbitraThrew = null;
    $result = orbitraPostbackTransaction($pdo, static function () use ($pdo, &$orbitraThrew): array {
        try {
            orbitraPostbackAssertTransactionActive($pdo);
            $orbitraThrew = false;
        } catch (\Throwable $e) {
            $orbitraThrew = true;
        }
        $pdo->exec("INSERT INTO probe VALUES (1, 'kept')");
        return ['kept'];
    });
    $check('mid-transaction assert does not throw', $orbitraThrew === false);
    $check('transaction result is returned', $result === ['kept']);
    $check('transaction body committed', (int) $pdo->query('SELECT COUNT(*) FROM probe')->fetchColumn() === 1);

    // The probe must never leave state behind: a full write unit still works
    // right after the checks above and rolls back as one unit on failure.
    try {
        orbitraPostbackTransaction($pdo, static function () use ($pdo): array {
            $pdo->exec("INSERT INTO probe VALUES (2, 'doomed')");
            throw new RuntimeException('forced failure');
        });
        $check('failed unit reports its exception', false);
    } catch (RuntimeException $e) {
        $check('failed unit reports its exception', $e->getMessage() === 'forced failure');
    }
    $check('failed unit rolls back fully', (int) $pdo->query('SELECT COUNT(*) FROM probe')->fetchColumn() === 1);

    // The guard pattern from ConversionAttribution/FacebookConversions/
    // TikTokConversions: an error inside a live transaction is swallowed,
    // but the same error after the transaction silently died must rethrow.
    $pdo->exec('BEGIN IMMEDIATE');
    $swallowed = false;
    try {
        $inTransaction = orbitraPostbackTransactionActive($pdo);
        try {
            throw new RuntimeException('helper error inside live transaction');
        } catch (RuntimeException $e) {
            if ($inTransaction && !orbitraPostbackTransactionActive($pdo)) {
                throw $e;
            }
            $swallowed = true;
        }
    } catch (RuntimeException $e) {
        $swallowed = false;
    }
    $check('guard swallows an error while the transaction lives', $swallowed === true);
    $pdo->exec('ROLLBACK');

    // Simulate the dead-transaction case: the guard's exit check must see it.
    $rethrown = false;
    $inTransaction = orbitraPostbackTransactionActive($pdo); // autocommit here
    try {
        try {
            throw new RuntimeException('helper error after rollback');
        } catch (RuntimeException $e) {
            if ($inTransaction && !orbitraPostbackTransactionActive($pdo)) {
                throw $e;
            }
        }
    } catch (RuntimeException $e) {
        $rethrown = true;
    }
    // In autocommit $inTransaction is false, so nothing rethrows — matching
    // the historical behaviour of the guards outside a transaction.
    $check('guard leaves autocommit errors to their callers', $rethrown === false);
} finally {
    @unlink($path);
}

echo $failures === 0
    ? 'Postback transaction state: all passed (PHP ' . PHP_VERSION . ')' . PHP_EOL
    : "Postback transaction state: FAILURES: $failures (PHP " . PHP_VERSION . ')' . PHP_EOL;
exit($failures === 0 ? 0 : 1);
