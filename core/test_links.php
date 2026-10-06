<?php
// core/test_links.php — signed campaign test links (_t).
//
// A test link opens the campaign through the real routing pipeline (streams,
// filters, rotation, landings, offers) but never writes a clicks row: reports
// stay clean by construction — the same skip-INSERT pattern prefetch and
// no-collect streams already use. The signature is what separates a test from
// a visitor: without it ?_geo= would let anyone rewrite their own routing
// context, and ?_dbg= would hand the campaign's stream structure to anyone
// who can guess a URL.
//
// Format:  _t=<campaignId>:<32 hex chars>
//   sig = hash_hmac('sha256', 'test-link|<id>|<alias>', <postback_key>)[0:32]
//
// The instance postback_key signs it (the same secret /?_lp=1 tokens use), so
// a signature survives campaign token rotation and covers every campaign;
// rotating the postback key retires all test links at once. The id prefix
// keeps validation to one indexed SELECT, and hash_equals keeps the
// comparison constant-time.

/**
 * Compute the _t signature for a campaign row (needs id + alias).
 */
function orbitraTestSignature(array $campaign, string $secret): string
{
    $payload = 'test-link|' . (int) ($campaign['id'] ?? 0) . '|' . (string) ($campaign['alias'] ?? '');
    return (int) ($campaign['id'] ?? 0) . ':' . substr(hash_hmac('sha256', $payload, $secret), 0, 32);
}

/**
 * Validate a _t value: return the campaign it was issued for, or null for a
 * malformed value, an unknown/archived campaign, or a wrong signature. A
 * signature for a DIFFERENT campaign than the URL routes to is also null —
 * the caller compares ids and rejects the mismatch.
 */
function orbitraValidTestSignature(string $signature, PDO $pdo, string $secret): ?array
{
    $signature = trim($signature);
    $colon = strpos($signature, ':');
    if ($colon === false) {
        return null;
    }
    $id = (int) substr($signature, 0, $colon);
    $sig = strtolower(substr($signature, $colon + 1));
    if ($id <= 0 || preg_match('/^[0-9a-f]{32}$/', $sig) !== 1) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ? AND is_archived = 0 LIMIT 1");
        $stmt->execute([$id]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
    } catch (\Throwable $e) {
        return null;
    }
    if (!$campaign) {
        return null;
    }
    $expected = orbitraTestSignature($campaign, $secret);
    $expectedSig = substr($expected, strpos($expected, ':') + 1);
    return hash_equals($expectedSig, $sig) ? $campaign : null;
}
