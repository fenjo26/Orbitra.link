<?php
/**
 * Prefetch / preload detection.
 *
 * Browsers and crawlers fire speculative requests — link prefetching, tab
 * preloading, `<link rel=preload>`, Chrome's earlier prerender — that would
 * otherwise be counted as real clicks. The "ignore_prefetch" setting makes
 * the tracker drop them instead.
 *
 * index.php had a header check that only recognised the legacy Safari/Firefox
 * hints (X-Purpose: preview, X-Moz: prefetch); this helper adds the modern
 * Sec-Purpose / Sec-Fetch-* vocabulary so current Chrome and Edge are caught
 * too, and keeps the three click entry points (index.php, click.php,
 * core/click_api.php) identical.
 */

/**
 * Does the request look like a prefetch / preload?
 *
 * Pure function over the server globals so the three entry points behave the
 * same way and the result is trivially unit-testable.
 */
function orbitraIsPrefetch(array $server): bool
{
    // Legacy Safari hint ("X-Purpose: preview").
    $xPurpose = $server['HTTP_X_PURPOSE'] ?? '';
    if (is_string($xPurpose) && strcasecmp($xPurpose, 'preview') === 0) {
        return true;
    }

    // Legacy Firefox hint ("X-Moz: prefetch").
    $xMoz = $server['HTTP_X_MOZ'] ?? '';
    if (is_string($xMoz) && strcasecmp($xMoz, 'prefetch') === 0) {
        return true;
    }

    // Modern Fetch Metadata. "Sec-Purpose: prefetch" (Chrome/Safari) and
    // "Sec-Purpose: prefetch" alongside a navigation covers link hover and
    // prerender; older drafts spelled it "Sec-Fetch-Dest: document".
    $secPurpose = $server['HTTP_SEC_PURPOSE'] ?? $server['HTTP_SEC_FETCH_PURPOSE'] ?? '';
    if (is_string($secPurpose) && preg_match('/\bprefetch\b/i', $secPurpose)) {
        return true;
    }

    // Sec-Fetch-Mode: no-cors on a document load is the signature of a
    // speculative `<link rel=preload>` fetch rather than a navigation.
    $secMode = $server['HTTP_SEC_FETCH_MODE'] ?? '';
    if (is_string($secMode) && strcasecmp($secMode, 'no-cors') === 0) {
        $secDest = $server['HTTP_SEC_FETCH_DEST'] ?? '';
        if (is_string($secDest) && in_array(strtolower($secDest), ['document', 'empty'], true)) {
            return true;
        }
    }

    // Google's Web Light / AMP prefetch and some prefetch rels announce themselves.
    $purpose = $server['HTTP_PURPOSE'] ?? '';
    if (is_string($purpose) && strcasecmp($purpose, 'prefetch') === 0) {
        return true;
    }

    return false;
}

/**
 * Apply the ignore_prefetch setting: when enabled and the request looks like a
 * prefetch, stop the request with the same terse body the inline code used.
 */
function orbitraMaybeDieOnPrefetch(string $prefetchSetting): void
{
    if ($prefetchSetting !== '1') {
        return;
    }
    if (orbitraIsPrefetch($_SERVER)) {
        // Keep the historical body so log scrapers/grep that looked for it still match.
        die("Prefetch ignored.");
    }
}
