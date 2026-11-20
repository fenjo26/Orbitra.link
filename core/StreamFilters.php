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
        // No usable Accept-Language: only the "@empty" value matches, the
        // same meta word Keitaro uses. A bare "empty" counts too — imports
        // before 1.9.8 stripped the "@" and saved it that way.
        foreach ($payload as $item) {
            if (orbitraFilterIsEmptyToken((string) $item, true)) {
                return true;
            }
        }
        return false;
    }
    foreach ($payload as $item) {
        if (orbitraFilterIsEmptyToken((string) $item, true)) {
            continue;
        }
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

/**
 * Payload token of the Parameter filter that stands for "the parameter is
 * missing or empty" — the same spelling Keitaro uses in its filter payloads.
 */
const ORBITRA_PARAM_FILTER_EMPTY = '@empty';

/**
 * The parameter a Parameter filter reads: its configured name, trimmed.
 * '' means the filter is not configured and must abstain.
 */
function orbitraParameterFilterName(array $filter): string
{
    return trim((string) ($filter['param'] ?? ''));
}

/**
 * The value of $name for this visit.
 *
 * The raw query (GET + POST) comes first, so any name works — including one
 * no traffic-source template declares, like the `xxx=__PLACEMENT__` trick.
 * When the raw query has no such key, the captured click parameters are
 * consulted, so a filter on `sub_id_1` also sees a value the source template
 * mapped there from another parameter name.
 */
function orbitraParameterFilterValue(string $name, array $rawParams, array $clickParams): string
{
    if ($name === '') {
        return '';
    }
    $value = array_key_exists($name, $rawParams) ? $rawParams[$name] : ($clickParams[$name] ?? '');
    if (is_array($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    return trim((string) $value);
}

/**
 * Parameter stream filter: does the visit's value match any listed one?
 *
 *   "@empty"        → the parameter is missing or empty
 *   "__PLACEMENT__" → exactly that value (case-insensitive), so an
 *                     unreplaced ad-network macro is caught
 *   "tiktok*"       → "*" is a wildcard for any run of characters
 *
 * Keitaro's "catch unreplaced macros" setup is then: Parameter `xxx`,
 * include `__PLACEMENT__, @empty`, on an intercepting stream to the white page.
 */
function orbitraParameterFilterMatches(array $payload, string $value): bool
{
    foreach ($payload as $item) {
        $token = trim((string) $item);
        if ($token === '') {
            continue;
        }
        if (strcasecmp($token, ORBITRA_PARAM_FILTER_EMPTY) === 0) {
            if ($value === '') {
                return true;
            }
            continue;
        }
        if ($value === '') {
            continue;
        }
        if (strpos($token, '*') === false) {
            if (strcasecmp($token, $value) === 0) {
                return true;
            }
            continue;
        }
        $regex = '/^' . str_replace('\*', '.*', preg_quote($token, '/')) . '$/is';
        if (@preg_match($regex, $value) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * "Connection" filter: does the visitor's connection type match the payload?
 *
 * The router stores one of mobile / wifi / dialup / corporate
 * (orbitraLookupNetwork()). Operators type what they are used to, so the
 * common synonyms are accepted: cellular, 3g/4g/lte/5g → mobile; cable, dsl,
 * broadband, fiber, wi-fi → wifi; dial-up → dialup; corp, business → corporate.
 */
function orbitraConnectionFilterMatches(array $payload, string $connectionType): bool
{
    static $aliases = [
        'mobile' => 'mobile', 'cellular' => 'mobile', 'cell' => 'mobile', 'gsm' => 'mobile',
        '3g' => 'mobile', '4g' => 'mobile', 'lte' => 'mobile', '5g' => 'mobile',
        'wifi' => 'wifi', 'wi-fi' => 'wifi', 'cable' => 'wifi', 'dsl' => 'wifi', 'cable/dsl' => 'wifi',
        'broadband' => 'wifi', 'fiber' => 'wifi', 'fibre' => 'wifi', 'fixed' => 'wifi',
        'dialup' => 'dialup', 'dial-up' => 'dialup', 'dial' => 'dialup',
        'corporate' => 'corporate', 'corp' => 'corporate', 'business' => 'corporate',
    ];
    $connectionType = strtolower(trim($connectionType));
    if ($connectionType === '') {
        return false;
    }
    foreach ($payload as $item) {
        $token = strtolower(trim((string) $item));
        if ($token !== '' && ($aliases[$token] ?? $token) === $connectionType) {
            return true;
        }
    }
    return false;
}

/**
 * "Operator" filter: does the visitor's mobile operator match the payload?
 *
 * Substring match, case-insensitive, with punctuation folded to "_" on both
 * sides, so "megafon", "MegaFon" and "megafon_ru" all match a visitor stored
 * as "megafon_ru" (Keitaro list) or "MegaFon" (IP2Location), and "t-mobile"
 * matches "t_mobile_us". An empty operator (not a mobile network) never matches.
 */
function orbitraOperatorFilterMatches(array $payload, string $carrier): bool
{
    $fold = static fn(string $value): string => trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($value)), '_');
    $haystack = $fold($carrier);
    if ($haystack === '') {
        return false;
    }
    foreach ($payload as $item) {
        $needle = $fold((string) $item);
        if ($needle !== '' && strpos($haystack, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Is this filter value the "@empty" meta word (Keitaro's spelling for "the
 * value is missing or empty")? $acceptBare also takes plain "empty" — only
 * for filters where no real value can be spelled that way (Language).
 */
function orbitraFilterIsEmptyToken(string $value, bool $acceptBare = false): bool
{
    $v = strtolower(trim($value));
    return $v === ORBITRA_PARAM_FILTER_EMPTY || ($acceptBare && $v === 'empty');
}

/**
 * One text-filter value against a visitor string, case-insensitive:
 *
 *   "/pattern/"  → regular expression (PCRE), as in Keitaro
 *   "*google*"   → mask, "*" is any run of characters, whole value must fit
 *   "google"     → the visitor string contains it
 *
 * The plain form is "contains" rather than Keitaro's strict match, so a
 * beginner who types "iPhone" gets what they expect; a full string copied
 * from Keitaro still matches itself.
 */
function orbitraTextPatternMatches(string $token, string $value): bool
{
    $token = trim($token);
    if ($token === '' || $value === '') {
        return false;
    }
    if (strlen($token) > 2 && $token[0] === '/' && substr($token, -1) === '/') {
        $r = @preg_match($token . 'i', $value);
        if ($r !== false) {
            return $r === 1;
        }
        // Not a valid regex (e.g. a URL path "/promo/") — treat as text.
    }
    if (strpos($token, '*') !== false) {
        $regex = '/^' . str_replace('\*', '.*', preg_quote($token, '/')) . '$/is';
        return @preg_match($regex, $value) === 1;
    }
    return stripos($value, $token) !== false;
}

/** Shared by the Referer and UserAgent filters: "@empty" + text patterns. */
function orbitraTextFilterMatches(array $payload, string $value): bool
{
    $value = trim($value);
    foreach ($payload as $item) {
        if (is_array($item)) {
            continue;
        }
        if (orbitraFilterIsEmptyToken((string) $item)) {
            if ($value === '') {
                return true;
            }
            continue;
        }
        if (orbitraTextPatternMatches((string) $item, $value)) {
            return true;
        }
    }
    return false;
}

/** "Referer" filter: "@empty" = direct visit / no referrer header. */
function orbitraRefererFilterMatches(array $payload, string $referer): bool
{
    return orbitraTextFilterMatches($payload, $referer);
}

/** "UserAgent" filter: raw User-Agent header; "@empty" = header missing. */
function orbitraUserAgentFilterMatches(array $payload, string $userAgent): bool
{
    return orbitraTextFilterMatches($payload, $userAgent);
}

/**
 * Sensitivity of a "Bot" stream filter: low | medium | high.
 *
 * Filters saved before the setting existed have none and keep the old
 * behaviour, "high" (any single signal). The editor and the Keitaro import
 * write "medium" — closer to Keitaro, whose Bot filter only consults its
 * bot database.
 */
function orbitraBotFilterSensitivity(array $filter): string
{
    $s = strtolower(trim((string) ($filter['sensitivity'] ?? '')));
    return in_array($s, ['low', 'medium', 'high'], true) ? $s : 'high';
}
