<?php
/**
 * One SQLite write, retried while the database is locked.
 *
 * SQLite allows a single writer. Orbitra runs several every-minute crons
 * (rotation optimiser, postback queue, aggregator, SSL queue) that write in
 * bursts, and a web request gets only a 5-second `busy_timeout` — deliberately,
 * so a contended page cannot pin a PHP-FPM worker. The result an operator sees
 * is an action that dies on `SQLSTATE[HY000]: General error: 5 database is
 * locked` while the very same write, attempted a second later, succeeds.
 *
 * Use this for the slow, deliberate, operator-initiated writes where waiting is
 * obviously better than failing — issuing a certificate, deleting a domain —
 * NOT for hot paths: every retry holds the worker for `$sleepSeconds` more.
 *
 * Rethrows anything that is not a lock, and rethrows the lock itself once the
 * attempts are exhausted, so the caller still decides what a real failure means.
 */

if (!function_exists('orbitraDbWriteWithRetry')) {
    function orbitraDbWriteWithRetry(PDO $pdo, string $sql, array $params = [], int $tries = 4, int $sleepSeconds = 1): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $pdo->prepare($sql)->execute($params);
                return;
            } catch (\PDOException $e) {
                if ($attempt >= $tries || !orbitraDbErrorIsLock($e)) {
                    throw $e;
                }
                sleep($sleepSeconds);
            }
        }
    }
}

if (!function_exists('orbitraDbErrorIsLock')) {
    /**
     * SQLite reports the same contention under two spellings — "database is
     * locked" (SQLITE_BUSY) and "database table is locked" (SQLITE_LOCKED) —
     * and PDO prefixes them differently depending on where the error surfaced.
     */
    function orbitraDbErrorIsLock(\Throwable $e): bool
    {
        $message = $e->getMessage();
        return stripos($message, 'database is locked') !== false
            || stripos($message, 'database table is locked') !== false;
    }
}

if (!function_exists('orbitraDbAllowSlowWrites')) {
    /**
     * Give the current connection a longer lock wait for one deliberately slow
     * admin action. The default web value (5s, set in config.php) protects the
     * request path; an action that already shells out to certbot for half a
     * minute is not the request path that value was chosen for.
     */
    function orbitraDbAllowSlowWrites(PDO $pdo, int $milliseconds = 15000): void
    {
        try {
            $pdo->exec('PRAGMA busy_timeout = ' . (int) $milliseconds . ';');
        } catch (\Throwable $e) {
            // A connection that will not take the pragma still works — the
            // retry helper above is the real guard.
        }
    }
}
