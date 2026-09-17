<?php
// Durable conversion/CAPI writes for the incoming postback. External work must
// stay outside this callback: a SQLite writer cannot be held across network I/O.
require_once __DIR__ . '/db_retry.php';

if (!function_exists('orbitraPostbackTransactionActive')) {
    /**
     * Whether a SQLite transaction is really open on this connection.
     *
     * PHP < 8.4 cannot see a transaction started with a raw BEGIN through
     * PDO::exec() — inTransaction() stays false for its whole life (php bug
     * #81227, only fixed in 8.4 via sqlite3_get_autocommit). The postback
     * write path opens its writer lock exactly that way, so on older PHP the
     * real state must be probed: BEGIN DEFERRED fails with "cannot start a
     * transaction within a transaction" while one is open. When none is, the
     * probe opens (and rolls back) an empty deferred transaction, touching
     * neither data nor locks.
     *
     * Defined with a guard in the other transaction-guard files too: test
     * fixtures copy these files one by one, so each must be self-sufficient.
     */
    function orbitraPostbackTransactionActive(PDO $pdo): bool
    {
        if (PHP_VERSION_ID >= 80400) {
            return $pdo->inTransaction();
        }
        try {
            $started = $pdo->exec('BEGIN DEFERRED');
            if ($started === false) {
                // Non-exception error mode: the failure text is in errorInfo().
                return str_contains((string) ($pdo->errorInfo()[2] ?? ''), 'within a transaction');
            }
        } catch (\Throwable $e) {
            return str_contains($e->getMessage(), 'within a transaction');
        }
        try {
            $pdo->exec('ROLLBACK'); // undoes only the probe's own BEGIN
        } catch (\Throwable $e) {
            // Nothing left to undo; the connection is still in autocommit.
        }
        return false;
    }
}

function orbitraPostbackAssertTransactionActive(PDO $pdo, ?Throwable $cause = null): void
{
    if (!orbitraPostbackTransactionActive($pdo)) {
        // An optional helper may catch an error that made SQLite roll back the
        // WHOLE transaction. Never let later writes continue in autocommit.
        throw $cause ?? new RuntimeException('Postback transaction ended before its durable writes completed.');
    }
}

function orbitraPostbackTransaction(PDO $pdo, callable $write): array
{
    $timeoutStmt = $pdo->query('PRAGMA busy_timeout');
    $previousTimeout = (int) $timeoutStmt->fetchColumn();
    $timeoutStmt->closeCursor();
    // Bound this hot path; retry the whole unit with a fresh snapshot, not the
    // failing INSERT on a stale reader. Exhaustion asks the sender to retry.
    $pdo->exec('PRAGMA busy_timeout = 250');
    try {
        for ($attempt = 0; ; $attempt++) {
            $started = false;
            try {
                $pdo->exec('BEGIN IMMEDIATE');
                $started = true;
                $result = $write();
                orbitraPostbackAssertTransactionActive($pdo);
                $pdo->exec('COMMIT');
                return $result;
            } catch (\Throwable $e) {
                if ($started) {
                    try {
                        $pdo->exec('ROLLBACK');
                    } catch (\Throwable $rollbackError) {
                        // SQLite may already have rolled back (for example a
                        // RAISE(ROLLBACK) trigger). Keep the original failure.
                    }
                }
                if ($attempt >= 2 || !orbitraDbErrorIsLock($e)) {
                    throw $e;
                }
                usleep(25000 * ($attempt + 1));
            }
        }
    } finally {
        $pdo->exec('PRAGMA busy_timeout = ' . $previousTimeout);
    }
}

/**
 * Idempotency is scoped to pixel + event name + the existing click/status/tid
 * event ID. The caller holds BEGIN IMMEDIATE, so concurrent postbacks cannot
 * both pass this check. Token/API-version changes do not create another event.
 * This only handles the current incoming postback; it never scans for or replays
 * missing historical conversions.
 */
function orbitraPostbackEnqueueCapi(PDO $pdo, array $pixel, array $click, array $context, int $conversionId): bool
{
    orbitraPostbackAssertTransactionActive($pdo);
    $isTikTok = ($pixel['type'] ?? '') === 'tiktok';
    $class = $isTikTok ? 'TikTokConversions' : 'FacebookConversions';
    $eventName = $class::resolveEvent($pixel, (string) ($context['status'] ?? ''));
    if (empty($pixel['pixel_id']) || empty($pixel['token']) || $eventName === null) {
        // Preserve the provider's skipped-status diagnostics.
        return $class::enqueue($pdo, $pixel, $click, $context, $conversionId);
    }

    $stmt = $pdo->prepare('SELECT url, payload_json FROM s2s_postbacks_log WHERE conversion_id = ? AND postback_id IS NULL');
    $stmt->execute([$conversionId]);
    $previous = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    foreach ($previous as $row) {
        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload)) {
            continue;
        }
        if ($isTikTok) {
            $samePixel = ($payload['pixel_code'] ?? '') === (string) $pixel['pixel_id'];
            $sameEvent = ($payload['event_id'] ?? '') === (string) $context['event_id']
                && ($payload['event'] ?? '') === $eventName;
        } else {
            $url = parse_url((string) $row['url']);
            $samePixel = ($url['host'] ?? '') === 'graph.facebook.com'
                && preg_match('#/(?:v[0-9]+\.[0-9]+/)?' . preg_quote(rawurlencode((string) $pixel['pixel_id']), '#') . '/events$#', $url['path'] ?? '') === 1;
            $event = $payload['data'][0] ?? [];
            $sameEvent = ($event['event_id'] ?? '') === (string) $context['event_id']
                && ($event['event_name'] ?? '') === $eventName;
        }
        if ($samePixel && $sameEvent) {
            return true; // Already durably recorded, including delivered/failed rows.
        }
    }
    return $class::enqueue($pdo, $pixel, $click, $context, $conversionId);
}
