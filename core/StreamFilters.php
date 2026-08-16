<?php
// core/StreamFilters.php
//
// Shared filter-combination semantics for stream filter matching. Four
// engines decide whether a visitor passes a stream — the click router
// (index.php), the click tracker (click.php via the same router helpers),
// the Click API (core/click_api.php) and the campaign simulator (api.php) —
// so the AND/OR rule lives here once instead of being re-derived four times.

/**
 * The stream's filter combination mode: 'and' (default, every filter must
 * pass) or 'or' (any passing filter is enough).
 */
function orbitraStreamFilterLogic(array $stream): string
{
    return strtolower((string) ($stream['filters_logic'] ?? 'and')) === 'or' ? 'or' : 'and';
}

/**
 * Combine per-filter verdicts under the stream's logic.
 *
 * $votes holds one bool per filter that actually voted. Filters that
 * abstained (undeterminable country, missing ISP data, connection type,
 * unknown filter types) must NOT push a vote: under AND an abstention must
 * not block the stream, and under OR it must not satisfy it. A stream whose
 * every filter abstained passes, same as a stream without filters.
 */
function orbitraCombineFilterVotes(array $votes, string $logic): bool
{
    if ($votes === []) {
        return true;
    }
    if ($logic === 'or') {
        return in_array(true, $votes, true);
    }
    return !in_array(false, $votes, true);
}
