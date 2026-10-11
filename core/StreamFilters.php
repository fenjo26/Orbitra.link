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

/**
 * Every language tag a browser's Accept-Language lists, lowercased, in order:
 * "en-IN,en-US;q=0.9,hi;q=0.8" → ['en-in', 'en-us', 'hi'].
 *
 * The region is kept. The click log's `language` column stores only the
 * primary subtag, and the Language filter used to compare against that, which
 * made "en-IN" and "en-US" the same "en" — a Tier-1 English stream could not
 * keep Indian English out.
 */
function orbitraAcceptLanguageTags(string $raw): array
{
    $tags = [];
    foreach (explode(',', $raw) as $part) {
        $tag = orbitraNormalizeLanguageTag(explode(';', $part, 2)[0]);
        if ($tag !== '' && !in_array($tag, $tags, true)) {
            $tags[] = $tag;
        }
    }
    return $tags;
}

/** "EN_in " → "en-in"; '' for anything that is not a language tag (incl. "*"). */
function orbitraNormalizeLanguageTag(string $value): string
{
    $tag = str_replace('_', '-', strtolower(trim($value)));
    return preg_match('/^[a-z]{1,8}(-[a-z0-9]{1,8})*$/', $tag) ? $tag : '';
}

/**
 * Language stream filter: does any listed value match any language the
 * browser sends, wherever it sits in the list?
 *
 *   "en"    → any English: en, en-US, en-IN, en-GB…
 *   "en-IN" → only en-IN (and its own sub-variants, e.g. en-IN-x)
 *
 * So "exclude en-IN" on a Tier-1 stream drops visitors whose browser lists
 * en-IN anywhere, while "include en" keeps matching every English browser
 * exactly as before.
 */
function orbitraLanguageFilterMatches(array $payload, string $acceptLanguageRaw): bool
{
    $tags = orbitraAcceptLanguageTags($acceptLanguageRaw);
    if ($tags === []) {
        return false;
    }
    foreach ($payload as $item) {
        $token = orbitraNormalizeLanguageTag((string) $item);
        if ($token === '') {
            continue;
        }
        $isPrimaryOnly = strpos($token, '-') === false;
        foreach ($tags as $tag) {
            if ($isPrimaryOnly) {
                if (explode('-', $tag, 2)[0] === $token) {
                    return true;
                }
            } elseif ($tag === $token || strncmp($tag, $token . '-', strlen($token) + 1) === 0) {
                return true;
            }
        }
    }
    return false;
}
