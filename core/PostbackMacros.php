<?php
// core/PostbackMacros.php
//
// Macro handling shared by the outgoing S2S postback path.
//
// Why this exists: the traffic-source templates shipped in data/keitaro_*.json come
// straight from Keitaro, and 15 of them carry a status transform in the URL —
// {status: lead=reg sale=dep}. Without this the tracker would send the literal
// "{status: lead=reg sale=dep}" to the network, which is the class of bug the
// template rewrite was meant to remove.

if (!function_exists('orbitraApplyStatusTransform')) {

    /**
     * Resolve Keitaro's {status: from=to from=to} transform against an internal status.
     *
     * The transform maps the tracker's own status name to whatever vocabulary the
     * traffic source expects. A status the template does not list falls back to the
     * internal name, matching Keitaro's behaviour.
     *
     * Values are urlencoded because the result lands inside a query string, the same
     * way the plain {status} macro is encoded by the caller.
     */
    function orbitraApplyStatusTransform(string $url, string $internalStatus): string
    {
        if (stripos($url, '{status:') === false) {
            return $url;
        }

        return (string) preg_replace_callback(
            '/\{status:\s*([^}]*)\}/i',
            function (array $m) use ($internalStatus) {
                foreach (preg_split('/\s+/', trim($m[1])) as $pair) {
                    if ($pair === '' || strpos($pair, '=') === false) {
                        continue;
                    }
                    list($from, $to) = explode('=', $pair, 2);
                    if (strcasecmp(trim($from), $internalStatus) === 0) {
                        return urlencode(trim($to));
                    }
                }
                return urlencode($internalStatus);
            },
            $url
        );
    }
}

if (!function_exists('orbitraBuiltinStatusAliases')) {

    /**
     * Network status words a fresh install understands without a conversion-type
     * setup: approved/confirmed/... count as sale, declined/refused/... as rejected.
     *
     * Single source of truth for mapStatus() in postback.php (recording) and
     * orbitraStatusFilterMatch() (the campaign S2S status filter) — the two must
     * never disagree about what a word means.
     */
    function orbitraBuiltinStatusAliases(): array
    {
        return [
            'sale'     => ['approved', 'confirmed', 'accepted', 'converted'],
            'rejected' => ['declined', 'refused', 'cancelled', 'canceled'],
        ];
    }
}

if (!function_exists('orbitraMapBuiltinAliasWord')) {

    /**
     * The built-in alias target for a raw network word, or null when the word is
     * not one of the aliases. Only rescues a status that would otherwise land in
     * 'custom' — configured conversion types and {$type}_status parameters are
     * resolved by the caller before consulting this.
     */
    function orbitraMapBuiltinAliasWord(string $word): ?string
    {
        $needle = strtolower(trim($word));
        if ($needle === '') {
            return null;
        }
        foreach (orbitraBuiltinStatusAliases() as $type => $words) {
            if (in_array($needle, $words, true)) {
                return $type;
            }
        }
        return null;
    }
}

if (!function_exists('orbitraStatusFilterMatch')) {

    /**
     * Does a campaign postback's statuses filter admit this conversion?
     *
     * Returns WHY it matched:
     *  - 'internal'      the filter names the internal status (sale/rejected/...);
     *  - 'original'      the filter names the network's own word (a chip typed to
     *                    match what the network actually sends, e.g. 'approved').
     *                    A chip naming the network word is a deliberate operator
     *                    choice, so it wins even where a {$type}_status redirect
     *                    mapped that word elsewhere — internal always outranks it,
     *                    but when internal is absent the operator's chip rules;
     *  - 'alias_custom'  a pre-v1.5.11 'custom' chip: aliased words used to be
     *                    recorded as custom before the built-in aliases existed,
     *                    so a filter configured back then keeps receiving them.
     *
     * Null means the filter rejects the conversion — do not enqueue.
     *
     * 'original' and 'alias_custom' exist so the v1.5.11 alias change (approved ->
     * sale instead of custom) cannot silently stop a working S2S setup: without
     * them, every campaign configured before v1.5.11 stops matching on update.
     *
     * $viaBuiltinAlias must come from mapStatus()'s by-reference flag when the
     * caller has it: only a word the BUILT-IN alias rescued from 'custom' may
     * pass a 'custom' chip — an explicit sale_status=... parameter mapped the
     * word to the same target before v1.5.11 too, and 'custom' must not adopt
     * conversions it never received. null (unknown — reconstructing from stored
     * rows) falls back to inferring it from the word and the target, which is
     * exact whenever the original request carried no {$type}_status parameters.
     */
    function orbitraStatusFilterMatch(string $internalStatus, string $originalStatus, string $filterCsv, ?bool $viaBuiltinAlias = null): ?string
    {
        $list = array_map(
            static fn ($v) => strtolower(trim((string) $v)),
            explode(',', (string) $filterCsv)
        );

        $internal = strtolower(trim($internalStatus));
        if ($internal !== '' && in_array($internal, $list, true)) {
            return 'internal';
        }

        $original = strtolower(trim($originalStatus));
        if ($original !== '' && in_array($original, $list, true)) {
            return 'original';
        }

        if (in_array('custom', $list, true) && $internal !== '' && $internal !== 'custom') {
            if ($viaBuiltinAlias === false) {
                return null;
            }
            $aliasType = orbitraMapBuiltinAliasWord($original);
            if ($aliasType !== null && strcasecmp($aliasType, $internal) === 0) {
                return 'alias_custom';
            }
        }

        return null;
    }
}

if (!function_exists('orbitraResolveS2SUrl')) {

    /**
     * Build the final outbound URL for a campaign S2S postback: the
     * {status: from=to} transform first, then every macro resolved against the
     * conversion context, values urlencoded the way the query string needs.
     *
     * Returns null when the resolved host is a local/private/reserved address —
     * the same enqueue-time SSRF gate the live path applies (the worker
     * re-checks before sending, in case DNS changed since).
     *
     * Shared by the live enqueue in postback.php and the missed-conversion
     * backfill in api.php so a backfilled row is byte-identical to a live one.
     *
     * $ctx keys: click_id, campaign_id, offer_id, status (internal), payout,
     * currency, tid, cost, revenue, params (the click's saved parameters array).
     */
    function orbitraResolveS2SUrl(string $pbUrl, array $ctx): ?string
    {
        $macroValues = [
            '{subid}'       => (string) ($ctx['click_id'] ?? ''),
            // Aliases the imported Keitaro source templates use.
            '{clickid}'     => (string) ($ctx['click_id'] ?? ''),
            '{click_id}'    => (string) ($ctx['click_id'] ?? ''),
            '{status}'      => (string) ($ctx['status'] ?? ''),
            '{payout}'      => (string) ($ctx['payout'] ?? ''),
            '{conversion_revenue}' => (string) ($ctx['payout'] ?? ''),
            '{currency}'    => (string) ($ctx['currency'] ?? ''),
            '{external_id}' => (string) ($ctx['tid'] ?? ''),
            '{tid}'         => (string) ($ctx['tid'] ?? ''),
            '{campaign_id}' => (string) ($ctx['campaign_id'] ?? ''),
            '{offer_id}'    => (string) ($ctx['offer_id'] ?? ''),
            '{cost}'        => (string) ($ctx['cost'] ?? 0),
            '{revenue}'     => (string) ($ctx['revenue'] ?? 0),
            '{profit}'      => (string) ((float) ($ctx['revenue'] ?? 0) - (float) ($ctx['cost'] ?? 0)),
        ];
        // sub_id_1..30 and the other saved click parameters.
        if (!empty($ctx['params']) && is_array($ctx['params'])) {
            foreach ($ctx['params'] as $key => $val) {
                $macroValues['{' . $key . '}'] = (string) $val;
            }
        }

        $url = orbitraApplyStatusTransform($pbUrl, (string) ($ctx['status'] ?? ''));
        foreach ($macroValues as $macro => $value) {
            $url = str_replace($macro, urlencode($value), $url);
        }

        $host = (string) (parse_url($url)['host'] ?? '');
        if ($host !== '') {
            $ip = gethostbyname($host);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }
        return $url;
    }
}

if (!function_exists('orbitraDetectUrlPlaceholders')) {

    /**
     * Unfilled slots a traffic-source template URL carries: bbg=xxx,
     * event_id=[YOUR_AD_GROUP_CONVERSION_EVENT_ID], appKey=your-parameter.
     * Shipped to the source as-is, the request cannot be attributed — the S2S
     * equivalent of Keitaro's literal-macro FAQ failure, minus the braces the
     * macro check catches.
     *
     * Matches whole parameter VALUES only (an 'xxx' inside a real click id is
     * legitimate), and bracket-wrapped template hints of 3+ word characters.
     */
    function orbitraDetectUrlPlaceholders(string $url): array
    {
        $found = [];
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return $found;
        }
        foreach (explode('&', $query) as $pair) {
            $eq = strpos($pair, '=');
            $value = trim(rawurldecode($eq === false ? $pair : substr($pair, $eq + 1)));
            if ($value === '') {
                continue;
            }
            if (in_array(strtolower($value), ['xxx', 'your-parameter', 'your_parameter'], true)
                || (bool) preg_match('/^\[[A-Za-z0-9_ \-]{3,}\]$/', $value)) {
                $found[] = $value;
            }
        }
        return array_values(array_unique($found));
    }
}
