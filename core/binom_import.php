<?php
/**
 * Binom -> Orbitra importer (v2 Public API).
 *
 * Pulls the metadata entities from a live Binom tracker over HTTP and maps
 * them into Orbitra's SQLite, mirroring core/keitaro_import.php in scope
 * (no click/conversion history — Binom's API does not expose conversions as
 * rows, and click-log migration is a separate later stage).
 *
 * Transport. Every request goes through the injected $transport callable
 * (url -> ['status'=>int,'body'=>string]); production uses curl with the
 * Api-Key header. The callable indirection exists so tests can serve the
 * recorded fixtures from tests/fixtures/binom/ without a network.
 *
 * SSRF. The tracker URL is operator-supplied, so every production request
 * re-validates that the host still resolves to a public address — the same
 * two-layer discipline as the S2S postback worker (an IP literal is checked
 * as itself: gethostbyname() returns it unchanged, which must not short-
 * circuit the check).
 *
 * Endpoints (pinned against a live v2 install, October 2026):
 *   GET /public/api/v1/settings/info                      {email, timezone}
 *   GET /public/api/v1/domains                            [{id: uuid, domain, isSsl, ...}]
 *   GET /public/api/v1/traffic_source/list/filtered       [{id, name, ...}]
 *   GET /public/api/v1/traffic_source/{id}                {postbackUrl, tokens[], ...}
 *   GET /public/api/v1/stats/affiliate_network            [{id, name, is_deleted, ...}]
 *   GET /public/api/v1/stats/offer                        [{id, name, url, country, payout{}, ...}]
 *   GET /public/api/v1/offer/{id}                         {affiliateNetworkId, payout{}, ...}
 *   GET /public/api/v1/landing/list/filtered              [{id, name, location, ...}]
 *   GET /public/api/v1/groups/campaigns                   [{uuid, name}]
 *   GET /public/api/v1/campaign/list/filtered             [{id, name, ...}] (paginated)
 *   GET /public/api/v1/campaign/{id}                      full campaign incl. customRotation
 *
 * The campaign detail carries the whole traffic schema in customRotation:
 * defaultPaths[] (the no-rule-matched rotation) and rules[] (criteria →
 * paths). Rules become regular streams evaluated first; defaultPaths become
 * fallback streams pushed to the end — the same ordering trick the Keitaro
 * importer uses for its "default" streams.
 */

declare(strict_types=1);

require_once __DIR__ . '/keitaro_import.php';

/**
 * Validate a tracker base URL and check the host resolves to a public
 * address. Returns [ok, normalizedBaseUrl, error].
 */
function orbitraBinomPrepareUrl(string $rawUrl): array
{
    $url = trim($rawUrl);
    if ($url === '') {
        return [false, '', 'Tracker URL is required'];
    }
    // No scheme: assume https the way a browser would — parse_url() sees a
    // bare host as a path, so prepend BEFORE parsing.
    if (strpos($url, '://') === false) {
        $url = 'https://' . ltrim($url, '/');
    }
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        return [false, '', 'Could not parse the tracker URL'];
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return [false, '', 'Only http and https tracker URLs are supported'];
    }
    $host = (string) $parts['host'];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ip = $host;
    } else {
        $ip = gethostbyname($host);
        // gethostbyname() returns the input unchanged on failure — treat as
        // a transient DNS error, not as "looks fine".
        if ($ip === $host || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [false, '', 'Could not resolve the tracker host (DNS)'];
        }
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return [false, '', 'The tracker host resolves to a private/reserved address — refused'];
    }
    // Only scheme+host+port survive into the base: a pasted panel path
    // (…/panel/settings/…) is dropped here, the API lives on the root.
    $base = $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    return [true, rtrim($base, '/') . '/', ''];
}

/**
 * Production transport: curl with the Api-Key header. Timeouts and the
 * HTTPS/HTTP protocol pin follow the aggregator engines' template; the
 * public-address check runs again per request (DNS can change mid-import).
 */
function orbitraBinomCurlTransport(string $apiKey): callable
{
    return function (string $url) use ($apiKey): array {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['status' => 0, 'body' => 'blocked: ' . $host . ' resolves to a private address'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => 'curl init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Api-Key: ' . $apiKey, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // No curl_close(): a documented no-op since PHP 8.0, deprecated in 8.5.
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    };
}

/** GET $base.$path with query, decode JSON, throw a RuntimeException on transport/HTTP/JSON errors. */
function orbitraBinomGet(callable $transport, string $base, string $path, array $query = [])
{
    $url = $base . $path . ($query !== [] ? '?' . http_build_query($query) : '');
    $r = $transport($url);
    $status = (int) ($r['status'] ?? 0);
    if ($status === 401 || $status === 403) {
        throw new RuntimeException('Binom API rejected the key (HTTP ' . $status . ')');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Binom API ' . $path . ' failed (HTTP ' . $status . '): ' . substr((string) $r['body'], 0, 200));
    }
    $decoded = json_decode((string) $r['body'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Binom API ' . $path . ' returned non-JSON output');
    }
    return $decoded;
}

/** Rows of a stats endpoint without the trailing {"id":"totals"} pseudo-row. */
function orbitraBinomStatsRows(array $decoded): array
{
    $rows = [];
    foreach ($decoded as $row) {
        if (is_array($row) && (($row['id'] ?? '') !== 'totals')) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Fetch every entity the importer maps. One place so TestConnection and
 * Import see the same data. Soft-failures (per-entity) land in $issues.
 */
function orbitraBinomFetchAll(callable $transport, string $base, array &$issues = []): array
{
    $data = [];
    $probe = function (string $key, string $path, array $query, callable $shape) use ($transport, $base, &$issues, &$data): void {
        try {
            $data[$key] = $shape(orbitraBinomGet($transport, $base, $path, $query));
        } catch (Throwable $e) {
            $issues[] = $key . ': ' . $e->getMessage();
            $data[$key] = null;
        }
    };

    $probe('settings', 'public/api/v1/settings/info', [], fn ($d) => $d);
    $probe('domains', 'public/api/v1/domains', [], fn ($d) => $d);
    $probe('sources', 'public/api/v1/traffic_source/list/filtered', ['status' => 'all'], fn ($d) => $d);
    $probe('networks', 'public/api/v1/stats/affiliate_network', [], fn ($d) => orbitraBinomStatsRows($d));
    $probe('offers', 'public/api/v1/stats/offer', [], fn ($d) => orbitraBinomStatsRows($d));
    $probe('landings', 'public/api/v1/landing/list/filtered', [], fn ($d) => $d);
    $probe('groups', 'public/api/v1/groups/campaigns', [], fn ($d) => $d);

    // Campaigns: paginated summary, then one detail call each (the detail
    // carries customRotation — paths, rules, costs, tokens).
    $campaigns = [];
    $offset = 0;
    try {
        while (true) {
            $page = orbitraBinomGet($transport, $base, 'public/api/v1/campaign/list/filtered', [
                'accessLevel' => 'READ', 'sortColumn' => 'id', 'sortType' => 'asc',
                'limit' => 500, 'offset' => $offset,
            ]);
            foreach ($page as $row) {
                if (is_array($row)) {
                    $campaigns[] = $row;
                }
            }
            if (count($page) < 500) {
                break;
            }
            $offset += 500;
            if ($offset > 100000) {
                break;
            }
        }
    } catch (Throwable $e) {
        $issues[] = 'campaigns: ' . $e->getMessage();
    }
    $details = [];
    foreach ($campaigns as $c) {
        $cid = (int) ($c['id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        try {
            $details[$cid] = orbitraBinomGet($transport, $base, 'public/api/v1/campaign/' . $cid);
        } catch (Throwable $e) {
            $issues[] = 'campaign ' . $cid . ' detail: ' . $e->getMessage();
        }
    }
    $data['campaign_summaries'] = $campaigns;
    $data['campaigns'] = $details;

    // Offer details carry affiliateNetworkId, which the stats rows lack.
    $offerDetails = [];
    foreach (($data['offers'] ?? []) as $o) {
        $oid = (int) ($o['id'] ?? 0);
        if ($oid <= 0) {
            continue;
        }
        try {
            $offerDetails[$oid] = orbitraBinomGet($transport, $base, 'public/api/v1/offer/' . $oid);
        } catch (Throwable $e) {
            // Non-fatal: the stats row alone still imports.
            $offerDetails[$oid] = null;
        }
    }
    $data['offer_details'] = $offerDetails;

    return $data;
}

/** Connection/capability report for the panel's "test connection" button. */
function orbitraBinomTestConnection(string $baseUrl, string $apiKey, ?callable $transport = null): array
{
    [$ok, $base, $err] = orbitraBinomPrepareUrl($baseUrl);
    if (!$ok) {
        return ['ok' => false, 'error' => $err];
    }
    $transport = $transport ?? orbitraBinomCurlTransport($apiKey);
    $issues = [];
    $data = orbitraBinomFetchAll($transport, $base, $issues);
    return [
        'ok' => true,
        'timezone' => (string) ($data['settings']['timezone'] ?? ''),
        'counts' => [
            'domains' => is_array($data['domains'] ?? null) ? count($data['domains']) : 0,
            'traffic_sources' => is_array($data['sources'] ?? null) ? count($data['sources']) : 0,
            'affiliate_networks' => is_array($data['networks'] ?? null) ? count($data['networks']) : 0,
            'offers' => is_array($data['offers'] ?? null) ? count($data['offers']) : 0,
            'landings' => is_array($data['landings'] ?? null) ? count($data['landings']) : 0,
            'campaigns' => is_array($data['campaign_summaries'] ?? null) ? count($data['campaign_summaries']) : 0,
        ],
        'issues' => $issues,
    ];
}

/** Binom criteria operator ("is" / "is_not") -> Orbitra filter mode. */
function orbitraBinomFilterMode(string $operator): string
{
    return strtolower(trim($operator)) === 'is_not' ? 'exclude' : 'include';
}

/**
 * Binom rule criteria -> Orbitra filters_json rows. Country, Language and
 * Device map; anything else is counted into $dropped (same honesty contract
 * as the Keitaro filter builder — a dropped condition must be visible in
 * the import report, or a campaign silently behaves differently).
 */
function orbitraBinomCriteriaToFilters(array $criteria, array &$dropped = []): array
{
    $out = [];
    foreach ($criteria as $c) {
        if (!is_array($c)) {
            continue;
        }
        if ((int) ($c['enabled'] ?? 1) === 0) {
            continue; // disabled criterion — not evaluated in Binom either
        }
        $type = strtolower(trim((string) ($c['type'] ?? '')));
        $mode = orbitraBinomFilterMode((string) ($c['operator'] ?? 'is'));
        $values = [];
        foreach ((array) ($c['values'] ?? []) as $v) {
            $values[] = (string) $v;
        }
        $values = array_values(array_filter($values, fn ($v) => trim($v) !== ''));

        if ($type === 'country') {
            $cc = [];
            foreach ($values as $v) {
                $v = strtoupper(trim($v));
                if (preg_match('/^[A-Z]{2}$/', $v)) {
                    $cc[] = $v;
                }
            }
            if ($cc !== []) {
                $out[] = ['name' => 'Country', 'mode' => $mode, 'payload' => array_values(array_unique($cc))];
            }
            continue;
        }
        if ($type === 'language' || $type === 'lang') {
            $lv = [];
            foreach ($values as $v) {
                $v = strtolower(trim(preg_split('/[-_]/', $v)[0] ?? $v));
                $v = preg_replace('/[^a-z]/', '', $v);
                if ($v !== '') {
                    $lv[] = $v;
                }
            }
            if ($lv !== []) {
                $out[] = ['name' => 'Language', 'mode' => $mode, 'payload' => array_values(array_unique($lv))];
            }
            continue;
        }
        if ($type === 'device_type' || $type === 'device') {
            $dv = [];
            foreach ($values as $v) {
                $m = orbitraKeitaroMapDeviceValue($v);
                if ($m !== '') {
                    $dv[] = $m;
                }
            }
            if ($dv !== []) {
                $out[] = ['name' => 'Device', 'mode' => $mode, 'payload' => array_values(array_unique($dv))];
            }
            continue;
        }
        $dropped[$type !== '' ? $type : 'unknown'] = ($dropped[$type !== '' ? $type : 'unknown'] ?? 0) + 1;
    }
    return $out;
}

/** distributionType -> Orbitra rotation_type (weight vs position). */
function orbitraBinomMapRotation(string $distribution): string
{
    $d = strtoupper(trim($distribution));
    if ($d === 'TOP_TO_BOTTOM' || strpos($d, 'FIX_ON_PATH') === 0) {
        return 'position';
    }
    return 'weight';
}

/** Latin slug for the campaign alias; Binom has no aliases of its own. */
function orbitraBinomMakeAlias(string $name, int $binomId): string
{
    $s = strtolower(trim($name));
    $s = (string) preg_replace('/[^a-z0-9]+/i', '-', $s);
    if (function_exists('iconv')) {
        $tr = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if (is_string($tr) && $tr !== '') {
            $s = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $tr), '-'));
        }
    }
    $s = trim((string) $s, '-');
    if ($s === '' || !preg_match('/^[a-z]/', $s)) {
        $s = 'b' . ($binomId > 0 ? $binomId : substr(md5($name), 0, 6));
    }
    return substr($s, 0, 40);
}

/**
 * One Binom path -> one Orbitra stream row (schema_custom built the way the
 * Keitaro importer and the stream editor both read it back). Direct URLs
 * (offerId=0 + directUrl) are synthesized into real Orbitra offers — the
 * only shape that keeps weights when a path mixes landings, catalog offers
 * and raw URLs. Returns null when the path carries nothing importable.
 */
function orbitraBinomPathToStream(
    array $path,
    array $offerMap,
    array $landingMap,
    callable $directOfferFactory,
    array &$warnings,
    string $campaignLabel
): ?array {
    $landings = [];
    foreach ((array) ($path['landings'] ?? []) as $l) {
        if (!is_array($l)) {
            continue;
        }
        $bid = (int) ($l['id'] ?? 0);
        // id=0 + name=DIRECT is Binom's "no landing" placeholder.
        if ($bid <= 0) {
            continue;
        }
        $oid = $landingMap[$bid] ?? null;
        if ($oid) {
            $landings[] = ['id' => (int) $oid, 'weight' => (int) ($l['weight'] ?? 100)];
        } else {
            $warnings[] = $campaignLabel . ': landing binom_id=' . $bid . ' not imported — path entry skipped';
        }
    }

    $offers = [];
    foreach ((array) ($path['offers'] ?? []) as $o) {
        if (!is_array($o)) {
            continue;
        }
        $bid = (int) ($o['offerId'] ?? 0);
        $weight = max(0, (int) ($o['weight'] ?? 100));
        if ($bid > 0) {
            $oid = $offerMap[$bid] ?? null;
            if ($oid) {
                $offers[] = ['id' => (int) $oid, 'weight' => $weight];
            } else {
                $warnings[] = $campaignLabel . ': offer binom_id=' . $bid . ' not imported — path entry skipped';
            }
            continue;
        }
        $direct = trim((string) ($o['directUrl'] ?? ''));
        if ($direct !== '' && preg_match('#^https?://#i', $direct)) {
            $oid = $directOfferFactory($direct, $campaignLabel);
            if ($oid) {
                $offers[] = ['id' => (int) $oid, 'weight' => $weight];
            }
        }
    }

    if ($offers === [] && $landings === []) {
        return null;
    }

    $landings = orbitraKeitaroNormalizeWeights($landings, 'weight', 100);
    $offers = orbitraKeitaroNormalizeWeights($offers, 'weight', 100);

    $schemaType = 'redirect';
    $custom = [];
    if ($landings !== []) {
        $schemaType = 'landing_offer';
        $custom = ['landings' => $landings, 'offers' => $offers];
    } else {
        $custom = ['offers' => $offers];
    }

    return [
        'name' => trim((string) ($path['name'] ?? '')) ?: 'Path',
        'enabled' => (int) ($path['enabled'] ?? 1) === 1,
        'weight' => max(0, (int) ($path['weight'] ?? 100)),
        'schema_type' => $schemaType,
        'schema_custom' => $custom,
    ];
}

/**
 * The import itself. Options mirror the Keitaro importer's:
 * dry_run, import_domains, import_networks, import_traffic_sources,
 * import_offers, import_landings, import_campaigns (streams ride along —
 * Binom keeps them inside the campaign object).
 */
function orbitraBinomImport(PDO $pdo, string $baseUrl, string $apiKey, array $opts = [], ?callable $transport = null): array
{
    $o = array_merge([
        'dry_run' => false,
        'import_domains' => true,
        'import_networks' => true,
        'import_offers' => true,
        'import_landings' => true,
        'import_traffic_sources' => false,
        'import_campaigns' => false,
        'import_streams' => true,
    ], $opts);
    foreach (['dry_run', 'import_domains', 'import_networks', 'import_offers', 'import_landings',
        'import_traffic_sources', 'import_campaigns', 'import_streams'] as $k) {
        $o[$k] = !empty($o[$k]);
    }

    [$ok, $base, $err] = orbitraBinomPrepareUrl($baseUrl);
    if (!$ok) {
        throw new RuntimeException($err);
    }
    $transport = $transport ?? orbitraBinomCurlTransport($apiKey);
    $issues = [];
    $data = orbitraBinomFetchAll($transport, $base, $issues);

    $result = [
        'dry_run' => $o['dry_run'],
        'fetched' => [
            'domains' => is_array($data['domains'] ?? null) ? count($data['domains']) : 0,
            'affiliate_networks' => is_array($data['networks'] ?? null) ? count($data['networks']) : 0,
            'traffic_sources' => is_array($data['sources'] ?? null) ? count($data['sources']) : 0,
            'landings' => is_array($data['landings'] ?? null) ? count($data['landings']) : 0,
            'offers' => is_array($data['offers'] ?? null) ? count($data['offers']) : 0,
            'campaigns' => is_array($data['campaigns'] ?? null) ? count($data['campaigns']) : 0,
        ],
        'imported' => [
            'domains' => ['inserted' => 0, 'skipped' => 0],
            'affiliate_networks' => ['inserted' => 0, 'skipped' => 0],
            'traffic_sources' => ['inserted' => 0, 'skipped' => 0],
            'landings' => ['inserted' => 0, 'skipped' => 0],
            'offers' => ['inserted' => 0, 'skipped' => 0],
            'campaign_groups' => ['inserted' => 0, 'skipped' => 0],
            'campaigns' => ['inserted' => 0, 'skipped' => 0],
            'streams' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0],
        ],
        'warnings' => array_values($issues),
    ];
    $warnings = &$result['warnings'];

    if ($o['dry_run']) {
        unset($warnings);
        return $result;
    }

    $pdo->beginTransaction();
    try {
        // ---- Domains (Binom uuid -> in-memory map; cross-run by name) ----
        $domainUuidToId = [];
        $stmtFindDomain = $pdo->prepare('SELECT id FROM domains WHERE name = ? LIMIT 1');
        $stmtInsDomain = $pdo->prepare('INSERT INTO domains (name, catch_404, is_noindex, https_only) VALUES (?, 0, 0, ?)');
        foreach ((array) ($data['domains'] ?? []) as $d) {
            if (!is_array($d)) {
                continue;
            }
            $name = trim((string) ($d['domain'] ?? $d['host'] ?? ''));
            if ($name === '') {
                continue;
            }
            $stmtFindDomain->execute([$name]);
            $oid = (int) ($stmtFindDomain->fetchColumn() ?: 0);
            if ($oid > 0) {
                $domainUuidToId[(string) $d['id']] = $oid;
                if ($o['import_domains']) {
                    $result['imported']['domains']['skipped']++;
                }
                continue;
            }
            if ($o['import_domains']) {
                $stmtInsDomain->execute([$name, (int) ($d['isSsl'] ?? 0) ? 1 : 0]);
                $oid = (int) ($pdo->lastInsertId() ?: 0);
                $result['imported']['domains']['inserted']++;
            }
            if ($oid > 0) {
                $domainUuidToId[(string) $d['id']] = $oid;
            }
        }

        // ---- Affiliate networks ----
        $networkIdMap = [];
        if ($o['import_networks'] && is_array($data['networks'] ?? null)) {
            $stmtFind = $pdo->prepare('SELECT id FROM affiliate_networks WHERE is_archived = 0 AND name = ? LIMIT 1');
            $hasBinomCol = orbitraKeitaroSqliteHasColumn($pdo, 'affiliate_networks', 'binom_id');
            $stmtUpdB = $hasBinomCol
                ? $pdo->prepare('UPDATE affiliate_networks SET binom_id = ? WHERE id = ? AND (binom_id IS NULL OR binom_id = 0)')
                : null;
            $stmtIns = $pdo->prepare('INSERT INTO affiliate_networks (name, notes, state, binom_id) VALUES (?, ?, ?, ?)');
            foreach ($data['networks'] as $n) {
                $bid = (int) ($n['id'] ?? 0);
                $name = trim((string) ($n['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $deleted = !empty($n['is_deleted']);
                $stmtFind->execute([$name]);
                $oid = (int) ($stmtFind->fetchColumn() ?: 0);
                if ($oid > 0) {
                    $networkIdMap[$bid] = $oid;
                    if ($stmtUpdB && $bid > 0) {
                        $stmtUpdB->execute([$bid, $oid]);
                    }
                    $result['imported']['affiliate_networks']['skipped']++;
                    continue;
                }
                $stmtIns->execute([$name, '[Binom] affiliate_network id=' . $bid, $deleted ? 'archived' : 'active', $bid > 0 ? $bid : null]);
                $oid = (int) ($pdo->lastInsertId() ?: 0);
                if ($bid > 0 && $oid > 0) {
                    $networkIdMap[$bid] = $oid;
                }
                $result['imported']['affiliate_networks']['inserted']++;
            }
        }

        // ---- Traffic sources ----
        $sourceIdMap = [];
        if ($o['import_traffic_sources'] && is_array($data['sources'] ?? null)) {
            $stmtFind = $pdo->prepare('SELECT id FROM traffic_sources WHERE name = ? LIMIT 1');
            $hasBinomCol = orbitraKeitaroSqliteHasColumn($pdo, 'traffic_sources', 'binom_id');
            $stmtUpdB = $hasBinomCol
                ? $pdo->prepare('UPDATE traffic_sources SET binom_id = ? WHERE id = ? AND (binom_id IS NULL OR binom_id = 0)')
                : null;
            $stmtIns = $pdo->prepare('INSERT INTO traffic_sources (name, postback_url, parameters_json, notes, state, binom_id) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($data['sources'] as $s) {
                $bid = (int) ($s['id'] ?? 0);
                $name = trim((string) ($s['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $stmtFind->execute([$name]);
                $oid = (int) ($stmtFind->fetchColumn() ?: 0);
                if ($oid > 0) {
                    $sourceIdMap[$bid] = $oid;
                    if ($stmtUpdB && $bid > 0) {
                        $stmtUpdB->execute([$bid, $oid]);
                    }
                    $result['imported']['traffic_sources']['skipped']++;
                    continue;
                }
                // Token macros -> parameters_json, the Keitaro output contract.
                $params = [];
                $tokens = null;
                try {
                    $tokens = orbitraBinomGet($transport, $base, 'public/api/v1/traffic_source/' . $bid);
                } catch (Throwable $e) {
                    $warnings[] = 'traffic_source ' . $bid . ' detail: ' . $e->getMessage();
                }
                $postbackUrl = '';
                if (is_array($tokens)) {
                    $postbackUrl = trim((string) ($tokens['postbackUrl'] ?? ''));
                    foreach ((array) ($tokens['tokens'] ?? []) as $t) {
                        if (!is_array($t)) {
                            continue;
                        }
                        $alias = (string) ($t['name'] ?? $t['token'] ?? $t['title'] ?? '');
                        $macro = (string) ($t['macro'] ?? $t['placeholder'] ?? $t['value'] ?? '');
                        if ($alias !== '' && $macro !== '') {
                            $params[] = ['alias' => $alias, 'param' => strtolower($alias), 'macro' => $macro];
                        }
                    }
                }
                $stmtIns->execute([
                    $name,
                    $postbackUrl,
                    $params !== [] ? json_encode($params, JSON_UNESCAPED_SLASHES) : null,
                    '[Binom] traffic_source id=' . $bid,
                    'active',
                    $bid > 0 ? $bid : null,
                ]);
                $oid = (int) ($pdo->lastInsertId() ?: 0);
                if ($bid > 0 && $oid > 0) {
                    $sourceIdMap[$bid] = $oid;
                }
                $result['imported']['traffic_sources']['inserted']++;
            }
        }

        // ---- Landings ----
        $landingIdMap = [];
        if ($o['import_landings'] && is_array($data['landings'] ?? null)) {
            $hasBinomCol = orbitraKeitaroSqliteHasColumn($pdo, 'landings', 'binom_id');
            $stmtFind = $pdo->prepare('SELECT id FROM landings WHERE url = ? LIMIT 1');
            $stmtUpdB = $hasBinomCol
                ? $pdo->prepare('UPDATE landings SET binom_id = ? WHERE id = ? AND (binom_id IS NULL OR binom_id = 0)')
                : null;
            $stmtIns = $pdo->prepare('INSERT INTO landings (name, url, type, state, binom_id) VALUES (?, ?, ?, ?, ?)');
            foreach ($data['landings'] as $l) {
                $bid = (int) ($l['id'] ?? 0);
                $url = trim((string) ($l['location'] ?? $l['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $name = trim((string) ($l['name'] ?? '')) ?: $url;
                $state = 'active';
                $stmtFind->execute([$url]);
                $oid = (int) ($stmtFind->fetchColumn() ?: 0);
                if ($oid > 0) {
                    $landingIdMap[$bid] = $oid;
                    if ($stmtUpdB && $bid > 0) {
                        $stmtUpdB->execute([$bid, $oid]);
                    }
                    $result['imported']['landings']['skipped']++;
                    continue;
                }
                $stmtIns->execute([$name, $url, 'redirect', $state, $bid > 0 ? $bid : null]);
                $oid = (int) ($pdo->lastInsertId() ?: 0);
                if ($bid > 0 && $oid > 0) {
                    $landingIdMap[$bid] = $oid;
                }
                $result['imported']['landings']['inserted']++;
            }
        }

        // ---- Offers ----
        $offerIdMap = [];
        if ($o['import_offers'] && is_array($data['offers'] ?? null)) {
            $hasBinomCol = orbitraKeitaroSqliteHasColumn($pdo, 'offers', 'binom_id');
            $stmtFind = $pdo->prepare('SELECT id FROM offers WHERE url = ? AND is_archived = 0 LIMIT 1');
            $stmtUpdB = $hasBinomCol
                ? $pdo->prepare('UPDATE offers SET binom_id = ? WHERE id = ? AND (binom_id IS NULL OR binom_id = 0)')
                : null;
            $stmtIns = $pdo->prepare('INSERT INTO offers (name, affiliate_network_id, url, geo, payout_type, payout_value, payout_auto, notes, state, is_archived, binom_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $seenCurrency = [];
            foreach ($data['offers'] as $of) {
                $bid = (int) ($of['id'] ?? 0);
                $url = trim((string) ($of['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $name = trim((string) ($of['name'] ?? '')) ?: $url;
                $payout = is_array($of['payout'] ?? null) ? $of['payout'] : [];
                $money = is_array($payout['money'] ?? null) ? $payout['money'] : [];
                $amount = (float) ($money['amount'] ?? 0);
                $currency = strtoupper((string) ($money['currency'] ?? 'USD'));
                if ($currency !== '' && $currency !== 'USD' && !empty($amount)) {
                    $seenCurrency[$currency] = true;
                }
                $geo = strtoupper(trim((string) ($of['country'] ?? '')));
                if (!preg_match('/^[A-Z]{2}(,[A-Z]{2})*$/', $geo)) {
                    $geo = '';
                }
                $deleted = !empty($of['is_deleted']);
                $networkId = null;
                $detail = $data['offer_details'][$bid] ?? null;
                if (is_array($detail)) {
                    $nid = (int) ($detail['affiliateNetworkId'] ?? 0);
                    if ($nid > 0 && isset($networkIdMap[$nid])) {
                        $networkId = (int) $networkIdMap[$nid];
                    }
                }
                $stmtFind->execute([$url]);
                $oid = (int) ($stmtFind->fetchColumn() ?: 0);
                if ($oid > 0) {
                    $offerIdMap[$bid] = $oid;
                    if ($stmtUpdB && $bid > 0) {
                        $stmtUpdB->execute([$bid, $oid]);
                    }
                    $result['imported']['offers']['skipped']++;
                    continue;
                }
                $stmtIns->execute([
                    $name, $networkId, $url, $geo !== '' ? $geo : null,
                    'cpa', $amount, !empty($payout['isAuto']) ? 1 : 0,
                    '[Binom] offer id=' . $bid,
                    $deleted ? 'paused' : 'active', $deleted ? 1 : 0,
                    $bid > 0 ? $bid : null,
                ]);
                $oid = (int) ($pdo->lastInsertId() ?: 0);
                if ($bid > 0 && $oid > 0) {
                    $offerIdMap[$bid] = $oid;
                }
                $result['imported']['offers']['inserted']++;
            }
            if ($seenCurrency !== []) {
                $warnings[] = 'payout currency ' . implode(', ', array_keys($seenCurrency))
                    . ' imported as plain numbers — check the FX settings if your base currency differs';
            }
        }

        // ---- Campaigns (+ streams from customRotation) ----
        if ($o['import_campaigns'] && is_array($data['campaigns'] ?? null)) {
            // Groups first (uuid -> campaign_groups).
            $groupUuidToId = [];
            $stmtFindGroup = $pdo->prepare('SELECT id FROM campaign_groups WHERE name = ? LIMIT 1');
            $stmtInsGroup = $pdo->prepare('INSERT INTO campaign_groups (name) VALUES (?)');
            foreach ((array) ($data['groups'] ?? []) as $g) {
                if (!is_array($g)) {
                    continue;
                }
                $name = trim((string) ($g['name'] ?? ''));
                $uuid = (string) ($g['uuid'] ?? $g['id'] ?? '');
                if ($name === '' || $uuid === '') {
                    continue;
                }
                $stmtFindGroup->execute([$name]);
                $oid = (int) ($stmtFindGroup->fetchColumn() ?: 0);
                if ($oid > 0) {
                    $groupUuidToId[$uuid] = $oid;
                    $result['imported']['campaign_groups']['skipped']++;
                    continue;
                }
                $stmtInsGroup->execute([$name]);
                $oid = (int) ($pdo->lastInsertId() ?: 0);
                if ($oid > 0) {
                    $groupUuidToId[$uuid] = $oid;
                }
                $result['imported']['campaign_groups']['inserted']++;
            }

            $hasCampaignBinomCol = orbitraKeitaroSqliteHasColumn($pdo, 'campaigns', 'binom_id');
            $stmtCheckPrev = $pdo->prepare('SELECT id, token, binom_id FROM campaigns WHERE alias = ? LIMIT 1');
            $stmtUpdB = $hasCampaignBinomCol
                ? $pdo->prepare('UPDATE campaigns SET binom_id = ? WHERE id = ? AND (binom_id IS NULL OR binom_id = 0)')
                : null;
            $stmtInsCampaign = $pdo->prepare('INSERT INTO campaigns (name, alias, domain_id, group_id, source_id, cost_model, cost_value, uniqueness_method, uniqueness_hours, rotation_type, token, state, is_archived, archived_at, binom_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmtFindStream = $pdo->prepare('SELECT id FROM streams WHERE campaign_id = ? AND name = ? AND position = ? LIMIT 1');
            $stmtInsStream = $pdo->prepare('INSERT INTO streams (campaign_id, offer_id, name, weight, is_active, type, position, filters_json, schema_type, action_payload, schema_custom_json, collect_clicks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, 1)');
            $stmtUpdStream = $pdo->prepare('UPDATE streams SET weight = ?, is_active = ?, filters_json = ?, schema_type = ?, schema_custom_json = ? WHERE id = ?');
            $droppedCriteria = [];
            $markAsBotNotes = 0;
            $hideReferrerNotes = 0;
            // Direct-URL offers are synthesized once per URL and reused for
            // the whole run (and, via the url lookup, across runs).
            $directOfferCache = [];
            $stmtFindOfferByUrl = $pdo->prepare('SELECT id FROM offers WHERE url = ? LIMIT 1');
            $stmtInsDirectOffer = $pdo->prepare("INSERT INTO offers (name, url, notes, state) VALUES (?, ?, ?, 'active')");
            $directOfferFactory = function (string $url, string $label) use ($pdo, &$directOfferCache, $stmtFindOfferByUrl, $stmtInsDirectOffer): ?int {
                if (isset($directOfferCache[$url])) {
                    return $directOfferCache[$url];
                }
                $stmtFindOfferByUrl->execute([$url]);
                $oid = (int) ($stmtFindOfferByUrl->fetchColumn() ?: 0);
                if ($oid === 0) {
                    $stmtInsDirectOffer->execute([$url, $url, '[Binom] direct URL offer']);
                    $oid = (int) ($pdo->lastInsertId() ?: 0);
                }
                if ($oid > 0) {
                    $directOfferCache[$url] = $oid;
                }
                return $oid > 0 ? $oid : null;
            };

            foreach ($data['campaigns'] as $c) {
                $bid = (int) ($c['id'] ?? 0);
                if ($bid <= 0) {
                    continue;
                }
                $name = trim((string) ($c['name'] ?? '')) ?: ('binom-' . $bid);

                // Alias is UNIQUE here and Binom has none: slug the name and
                // dodge collisions. An alias taken by OUR previous import of
                // the same campaign (same token) means an idempotent re-run.
                $campaignId = 0;
                $alias = orbitraBinomMakeAlias($name, $bid);
                $suffix = 1;
                while (true) {
                    $stmtCheckPrev->execute([$alias]);
                    $prev = $stmtCheckPrev->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($prev === null) {
                        break; // free alias
                    }
                    $isOurs = $hasCampaignBinomCol
                        ? ((int) ($prev['binom_id'] ?? 0) === $bid)
                        : (!empty($c['key']) && ($prev['token'] ?? null) === (string) $c['key']);
                    if ($isOurs) {
                        $campaignId = (int) $prev['id'];
                        break;
                    }
                    $alias = orbitraBinomMakeAlias($name, $bid) . '-' . (++$suffix);
                }

                $domainId = $domainUuidToId[(string) ($c['domainUuid'] ?? '')] ?? null;
                $groupId = $groupUuidToId[(string) ($c['groupUuid'] ?? '')] ?? null;
                $sourceId = $sourceIdMap[(int) ($c['trafficSourceId'] ?? 0)] ?? null;

                $cost = is_array($c['cost'] ?? null) ? $c['cost'] : [];
                $money = is_array($cost['money'] ?? null) ? $cost['money'] : [];
                $costModel = strtoupper(trim((string) ($cost['model'] ?? 'CPC')));
                if (!in_array($costModel, ['CPC', 'CPM', 'CPA'], true)) {
                    $costModel = 'CPC';
                }
                $costValue = (float) ($money['amount'] ?? 0);

                $deletedAt = trim((string) ($c['deletedAt'] ?? ''));
                $state = $deletedAt !== '' ? 'disabled' : 'active';
                $isArchived = $deletedAt !== '' ? 1 : 0;

                $token = trim((string) ($c['key'] ?? '')) ?: null;

                if ($campaignId > 0) {
                    $result['imported']['campaigns']['skipped']++;
                    if ($stmtUpdB) {
                        $stmtUpdB->execute([$bid, $campaignId]);
                    }
                } else {
                    $stmtInsCampaign->execute([
                        $name, $alias, $domainId, $groupId, $sourceId,
                        $costModel, $costValue, 'ip', 24,
                        orbitraBinomMapRotation((string) ($c['distributionType'] ?? '')),
                        $token, $state, $isArchived,
                        $deletedAt !== '' ? $deletedAt : null,
                        $bid,
                    ]);
                    $campaignId = (int) ($pdo->lastInsertId() ?: 0);
                    $result['imported']['campaigns']['inserted']++;
                }
                if ($campaignId <= 0) {
                    continue;
                }

                $label = 'campaign ' . $name;
                if (strpos((string) ($c['hideReferrer']['type'] ?? 'NONE'), 'SMART') === 0) {
                    $hideReferrerNotes++;
                }

                if (!$o['import_streams']) {
                    continue;
                }

                $rotation = is_array($c['customRotation'] ?? null) ? $c['customRotation'] : [];

                // Rules first (regular streams, early positions).
                $position = 10;
                $ruleStreams = [];
                foreach ((array) ($rotation['rules'] ?? []) as $rule) {
                    if (!is_array($rule)) {
                        continue;
                    }
                    $filters = orbitraBinomCriteriaToFilters((array) ($rule['criteria'] ?? []), $droppedCriteria);
                    if (!empty($rule['markAsBot'])) {
                        $markAsBotNotes++;
                    }
                    $paths = orbitraKeitaroNormalizeWeights(
                        array_values(array_filter(array_map(
                            fn ($p) => is_array($p) ? orbitraBinomPathToStream((array) $p, $offerIdMap, $landingIdMap, $directOfferFactory, $warnings, $label) : null,
                            (array) ($rule['paths'] ?? [])
                        ))),
                        'weight',
                        100
                    );
                    foreach ($paths as $p) {
                        $ruleStreams[] = ['filters' => $filters, 'position' => $position, 'stream' => $p];
                        $position += 10;
                    }
                }
                // Then the default rotation (fallback streams, pushed last).
                $defaultStreams = [];
                $dp = 1000000;
                foreach ((array) ($rotation['defaultPaths'] ?? []) as $path) {
                    if (!is_array($path)) {
                        continue;
                    }
                    $st = orbitraBinomPathToStream($path, $offerIdMap, $landingIdMap, $directOfferFactory, $warnings, $label);
                    if ($st === null) {
                        continue;
                    }
                    $defaultStreams[] = ['stream' => $st, 'position' => $dp];
                    $dp += 10;
                }
                // Normalize default-path weights among themselves.
                $bare = orbitraKeitaroNormalizeWeights(array_map(fn ($x) => $x['stream'], $defaultStreams), 'weight', 100);
                foreach ($bare as $i => $norm) {
                    $defaultStreams[$i]['stream']['weight'] = $norm['weight'];
                }

                foreach (array_merge($ruleStreams, $defaultStreams) as $entry) {
                    $st = $entry['stream'];
                    $isFallback = $entry['position'] >= 1000000;
                    $stmtFindStream->execute([$campaignId, $st['name'], $entry['position']]);
                    $existingStream = (int) ($stmtFindStream->fetchColumn() ?: 0);
                    $filtersJson = json_encode($entry['filters'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $customJson = json_encode($st['schema_custom'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if ($existingStream > 0) {
                        $stmtUpdStream->execute([
                            $st['weight'], $st['enabled'] ? 1 : 0, $filtersJson,
                            $st['schema_type'], $customJson, $existingStream,
                        ]);
                        $result['imported']['streams']['updated']++;
                        continue;
                    }
                    $legacyOffer = null;
                    foreach ((array) ($st['schema_custom']['offers'] ?? []) as $so) {
                        if (!empty($so['id'])) {
                            $legacyOffer = (int) $so['id'];
                            break;
                        }
                    }
                    $stmtInsStream->execute([
                        $campaignId, $legacyOffer, $st['name'], $st['weight'], $st['enabled'] ? 1 : 0,
                        $isFallback ? 'fallback' : 'regular', $entry['position'],
                        $filtersJson, $st['schema_type'], $customJson,
                    ]);
                    $result['imported']['streams']['inserted']++;
                }
            }

            if ($droppedCriteria !== []) {
                arsort($droppedCriteria);
                $parts = [];
                foreach ($droppedCriteria as $t => $n) {
                    $parts[] = $t . ' ×' . $n;
                }
                $warnings[] = array_sum($droppedCriteria) . ' rule condition(s) not migrated (no Orbitra equivalent): ' . implode(', ', $parts);
            }
            if ($markAsBotNotes > 0) {
                $warnings[] = $markAsBotNotes . ' rule(s) used markAsBot — imported as ordinary streams, Binom would also flag matching clicks as bots';
            }
            if ($hideReferrerNotes > 0) {
                $warnings[] = $hideReferrerNotes . ' campaign(s) used a SMART hide-referrer mode — imported with the default redirect';
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    unset($warnings);
    return $result;
}
