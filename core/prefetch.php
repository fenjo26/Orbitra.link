<?php
/**
 * Prefetch / preload detection.
 *
 * Browsers fire speculative requests — omnibox preloading, link prefetching,
 * Chrome prerender — that would otherwise be counted as real clicks. The
 * "ignore_prefetch" setting makes the tracker skip click logging for them.
 *
 * A prefetch response must still serve the campaign. The guard used to
 * die("Prefetch ignored."), and the browser happily cached that stub and
 * displayed it as the page once the prefetched navigation was activated —
 * a blank "Prefetch ignored." screen until a manual refresh. Now the click
 * is simply not logged and the landing/redirect goes out as usual, with a
 * no-store hint so the browser re-requests on the real navigation and that
 * visit is counted properly.
 */

/**
 * Does the request look like a prefetch / preload?
 *
 * Pure function over the server globals so the entry points behave the same
 * way and the result is trivially unit-testable.
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

    // Modern Fetch Metadata. "Sec-Purpose: prefetch" covers omnibox preloading
    // and link prefetch in Chrome/Edge/Safari; prerendering announces itself
    // the same way, and older drafts spelled the header "Sec-Fetch-Purpose".
    $secPurpose = $server['HTTP_SEC_PURPOSE'] ?? $server['HTTP_SEC_FETCH_PURPOSE'] ?? '';
    if (is_string($secPurpose) && preg_match('/\b(?:prefetch|prerender)\b/i', $secPurpose)) {
        return true;
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
 * prefetch, its click must NOT be logged — but the response itself must go out
 * so the visitor never sees a blank page instead of the landing.
 *
 * Side effect: marks the response Cache-Control: no-store, nudging the browser
 * into re-requesting on the real navigation so that visit lands in the stats.
 */
function orbitraShouldSkipClickOnPrefetch(string $prefetchSetting): bool
{
    if ($prefetchSetting !== '1') {
        return false;
    }
    if (!orbitraIsPrefetch($_SERVER)) {
        return false;
    }
    if (!headers_sent()) {
        header('Cache-Control: no-store');
    }
    return true;
}

/**
 * Deprecated no-op kept for backwards compatibility. It used to terminate the
 * request with "Prefetch ignored." — which the browser then showed as the page
 * once the prefetched navigation was activated. Callers should use
 * orbitraShouldSkipClickOnPrefetch() and keep serving the campaign.
 */
function orbitraMaybeDieOnPrefetch(string $prefetchSetting): void
{
    // Intentionally does nothing.
}
