<?php
/**
 * PushMacros — push text/link macros, expanded at SEND time (push_queue keeps
 * the raw text, so editing a message does not touch queued copies).
 *
 *   {вариант1|вариант2|…}  — random pick among the alternatives;
 *   {Random=(X,Y)}         — random integer, X..Y inclusive;
 *   {subid}                — click_id of the subscription being pushed.
 *
 * Nesting up to 10 levels deep is supported ({a|{b|c}}): expansion walks
 * innermost-first, and anything still brace-wrapped after 10 passes (deeper
 * nesting, unbalanced braces) is left verbatim. Unknown macros (typos) are
 * left verbatim too — never dropped silently.
 *
 * {offer.value} / {offer.value1} / {offer.value2} (stage 2.4): the per-country
 * values of the OFFER the subscription's click went to (offers.country_values:
 * country → [бонус, фриспины, валюта]), matched by the subscriber's country
 * with the country-less row as the fallback. Without offer context (preview,
 * a click without an offer) the macros stay verbatim, same honesty rule.
 */

class PushMacros
{
    /** Nesting budget: passes over the text; deeper structures stay raw. */
    public const MAX_DEPTH = 10;

    /**
     * Expand every macro in $text.
     *
     * @param string $text     raw message text / link
     * @param string $subid    click_id of the recipient subscription
     * @param ?array $offerCtx offer values for {offer.*}: ['country' => 'GR',
     *                         'values' => [country => [v, v1, v2]]] or null
     */
    public static function expand(string $text, string $subid = '', ?array $offerCtx = null): string
    {
        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            $expanded = self::expandInnermost($text, $subid, $offerCtx);
            if ($expanded === $text) {
                break; // nothing innermost left — done (or unbalanced input)
            }
            $text = $expanded;
        }
        return $text;
    }

    /**
     * The per-country offer table (offers.country_values JSON) as the lookup
     * {offer.*} reads: country(upper) → [value, value1, value2]. A row with
     * an empty country is the universal fallback.
     *
     * @param string $json offers.country_values
     */
    public static function parseCountryValues(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $country = strtoupper(trim((string) ($row['country'] ?? '')));
            if (isset($out[$country])) {
                continue; // first row wins — a duplicate must not flip values
            }
            $out[$country] = [
                (string) ($row['value'] ?? ''),
                (string) ($row['value1'] ?? ''),
                (string) ($row['value2'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * One pass: replace every brace group that contains no further braces.
     * Inner groups therefore always resolve before their parents see them.
     */
    private static function expandInnermost(string $text, string $subid, ?array $offerCtx): string
    {
        if (strpos($text, '{') === false) {
            return $text;
        }
        $out = '';
        $pos = 0;
        $len = strlen($text);
        while ($pos < $len) {
            $open = strpos($text, '{', $pos);
            if ($open === false) {
                $out .= substr($text, $pos);
                break;
            }
            $close = strpos($text, '}', $open + 1);
            if ($close === false) {
                // Unbalanced tail — copy verbatim, nothing to expand here.
                $out .= substr($text, $pos);
                break;
            }
            $inner = strpos($text, '{', $open + 1);
            if ($inner !== false && $inner < $close) {
                // This group still holds an inner '{' — it is not innermost.
                // Copy up to the inner brace and resume there.
                $out .= substr($text, $pos, $inner - $pos);
                $pos = $inner;
                continue;
            }
            $out .= substr($text, $pos, $open - $pos);
            $out .= self::expandOne(substr($text, $open + 1, $close - $open - 1), $subid, $offerCtx);
            $pos = $close + 1;
        }
        return $out;
    }

    /** Resolve one innermost group, or return it unchanged when unknown. */
    private static function expandOne(string $token, string $subid, ?array $offerCtx): string
    {
        if ($token === 'subid') {
            return $subid;
        }
        if (preg_match('/^offer\.(value|value1|value2)$/i', $token, $om)) {
            if ($offerCtx === null) {
                return '{' . $token . '}'; // no offer context — stays visible
            }
            $country = strtoupper(trim((string) ($offerCtx['country'] ?? '')));
            $values = is_array($offerCtx['values'] ?? null) ? $offerCtx['values'] : [];
            $row = $values[$country] ?? ($values[''] ?? null);
            if ($row === null) {
                return '{' . $token . '}';
            }
            $index = strtolower($om[1]) === 'value' ? 0 : (strtolower($om[1]) === 'value1' ? 1 : 2);
            return (string) $row[$index];
        }
        if (preg_match('/^Random\s*=\s*\(\s*(-?\d+)\s*,\s*(-?\d+)\s*\)$/', $token, $m)) {
            $from = (int) $m[1];
            $to = (int) $m[2];
            if ($to < $from) {
                [$from, $to] = [$to, $from];
            }
            return (string) random_int($from, $to);
        }
        if (strpos($token, '|') !== false) {
            $options = explode('|', $token);
            return $options[random_int(0, count($options) - 1)];
        }
        return '{' . $token . '}';
    }
}
