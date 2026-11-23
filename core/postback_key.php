<?php
/**
 * Postback key lifecycle.
 *
 * Affiliate networks store their Postback URL as a ready-to-copy string with
 * the tracker's postback key already substituted into it. Rotating the key in
 * Settings used to leave every stored URL on the old key — a beginner copied
 * the link for their affiliate manager and it silently never fired. Both
 * settings writers call this helper after a key change, rewriting the
 * /{key}/postback path segment in every stored URL.
 *
 * The key is also the secret behind the landing→offer hop and the test links,
 * so a rotation invalidates links already handed out — see orbitraRotatePostbackKey().
 */

/** The key the repository ships with. Public: an install that keeps it accepts forged conversions. */
function orbitraPublicPostbackKey(): string
{
    return 'fd12e72';
}

/** A new postback key: 24 hex characters — URL-safe, no path ambiguity. */
function orbitraGeneratePostbackKey(): string
{
    return bin2hex(random_bytes(12));
}

/**
 * Answer a postback that came with a key this installation does not use: the
 * old key after a rotation, or a typo in a network's cabinet.
 *
 * Both front controllers used to let it fall through to the panel's catch-all
 * and answer 200, so the network marked the conversion as delivered, never
 * retried, and the tracker kept no trace of it. Now it is the 404 the network
 * can see, and the Postbacks log names the reason.
 */
function orbitraRejectUnknownPostbackKey(PDO $pdo): void
{
    try {
        $pdo->prepare("INSERT INTO incoming_postbacks_log (created_at, remote_ip, request_uri, params_json, result, error)
                       VALUES (datetime('now'), ?, ?, ?, 'rejected', 'Invalid postback key')")
            ->execute([
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                $_SERVER['REQUEST_URI'] ?? '',
                json_encode($_GET, JSON_UNESCAPED_UNICODE),
            ]);
    } catch (\Throwable $e) {
        // The log is best-effort: the 404 goes out either way.
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Invalid postback key');
}

/**
 * A postback key typed by hand. An empty one would quietly put the route back
 * on the public default, and characters a URL path cannot carry would take it
 * down. Only a CHANGED key is checked, so an install with an odd legacy key
 * keeps saving its other settings.
 *
 * @return array{ok: bool, value: string} the value is the trimmed key when ok
 */
function orbitraValidatePostbackKey(PDO $pdo, $raw): array
{
    $value = trim((string) $raw);
    $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'postback_key'");
    $current = trim((string) $stmt->fetchColumn());
    $stmt->closeCursor();
    $ok = $value === $current || preg_match('/^[A-Za-z0-9_-]{8,64}$/', $value) === 1;
    return ['ok' => $ok, 'value' => $ok ? $value : ''];
}

/**
 * Move the installation onto a new postback key: store it and carry every
 * stored affiliate-network Postback URL over to it.
 *
 * $expectedCurrent, when given, is the key the caller believes is stored — the
 * one its page shows. A different stored key means someone else rotated it in
 * the meantime, and the caller would hand out a link that is already dead, so
 * nothing is written and 'rotated' is false.
 *
 * Beyond the Postback URL, the key signs the landing→offer hop and the test
 * links: visitors already inside the funnel and previously issued test links
 * do not survive a rotation on a live install.
 *
 * @return array{rotated: bool, key: string, rewritten: int}
 */
function orbitraRotatePostbackKey(PDO $pdo, ?string $expectedCurrent = null): array
{
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'postback_key'");
        $current = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($expectedCurrent !== null && (string) $current !== $expectedCurrent) {
            $pdo->exec('ROLLBACK');
            return ['rotated' => false, 'key' => (string) $current, 'rewritten' => 0];
        }
        $key = orbitraGeneratePostbackKey();
        $pdo->prepare("INSERT INTO settings (key, value) VALUES ('postback_key', ?)
                       ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$key]);
        $rewritten = $current !== false ? orbitraRewriteStoredPostbackUrls($pdo, (string) $current, $key) : 0;
        $pdo->exec('COMMIT');
        return ['rotated' => true, 'key' => $key, 'rewritten' => $rewritten];
    } catch (\Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (\Throwable $rollbackError) {
            // The failed statement already undid the transaction.
        }
        throw $e;
    }
}

function orbitraRewriteStoredPostbackUrls(PDO $pdo, string $oldKey, string $newKey): int
{
    $oldKey = trim($oldKey);
    $newKey = trim($newKey);
    if ($oldKey === '' || $newKey === '' || $oldKey === $newKey) {
        return 0;
    }
    // Replace the full /{key}/postback path segment, never the bare key: a
    // short old key could otherwise be rewritten inside unrelated parts of
    // the URL (a macro, a domain, a query value).
    $needle = '/' . $oldKey . '/postback';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle) . '%';
    $stmt = $pdo->prepare(
        "UPDATE affiliate_networks
            SET postback_url = REPLACE(postback_url, ?, ?)
          WHERE postback_url IS NOT NULL
            AND postback_url LIKE ? ESCAPE '\\'"
    );
    $stmt->execute([$needle, '/' . $newKey . '/postback', $like]);
    return $stmt->rowCount();
}
