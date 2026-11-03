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
 */

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
