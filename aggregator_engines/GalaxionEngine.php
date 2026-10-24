<?php
// aggregator_engines/GalaxionEngine.php
//
// Galaxion / Galaksion SSP (galaksion.com) advertiser spend, per campaign ×
// zone × day. API shapes from a live account (probe + sync 2026-10-12):
//
//   POST /v1/auth                        {email, password} → {token}
//   GET  /v1/advertiser/balance          → {balance}
//   GET  /v1/advertiser/statistics?dateFrom=YYYY-MM-DD 00:00:00&dateTo=… 23:59:59
//        &groupBy[0]=campaign&groupBy[1]=zone&orderBy[field]=money&orderBy[direction]=desc&limit
//        → {rows: [ {campaignId, campaign: "2192879 - name", zone, money, impressions, clicks} ]}
//
// ⚠ dateFrom/dateTo must be full datetimes; orderBy is mandatory; groupBy is a
//   PHP-style indexed array. Rate limit: max 5 requests / 10 s — one request
//   per day with a 1 s pause stays well inside it.
// ⚠ Auth header is `X-Auth-Token`. The token comes from /v1/auth with the
//   panel login; a ready token can be pasted instead of the password.
//
// Zone blocking (Zone Killer) — there is no dedicated endpoint; the zone list
// lives in the campaign's targeting:
//   GET   /v1/advertiser/campaigns/{id}  → {name, targeting: {zoneLimitations: [ids], zoneLimitationsExclude: bool}}
//   PATCH /v1/advertiser/campaigns/{id}  {name, targeting: {zoneLimitations, zoneLimitationsExclude: true}} → 204
//   Verified live as a no-op PATCH (identical list → 204). Send ONLY these
//   fields: echoing the whole targeting fails validation on CPA campaigns.
//   zoneLimitationsExclude=false with a non-empty list is a WHITELIST — adding
//   a zone there would ALLOW it, so such campaigns are refused, never flipped.

class GalaxionEngine
{
    private const API_BASE = 'https://ssp2-api.galaksion.com/api';
    private const LIMIT = 10000;

    /** Tests replace this to fake the network (see tests/push_cost_engines_more_test.php). */
    public static $testHttpHandler = null;

    /** Seconds between day requests (tests set 0). */
    public static $pauseSeconds = 1.0;

    public static function getRequiredFields(): array
    {
        return [
            ['key' => 'email', 'label_key' => 'costImport.fields.email', 'label' => 'Login e-mail', 'type' => 'text', 'required' => false],
            ['key' => 'password', 'label_key' => 'costImport.fields.password', 'label' => 'Password', 'type' => 'password', 'required' => false],
            ['key' => 'api_token', 'label_key' => 'costImport.fields.apiTokenAlt', 'label' => 'API token (instead of e-mail and password)', 'type' => 'password', 'required' => false],
            ['key' => 'currency', 'label_key' => 'costImport.fields.accountCurrency', 'label' => 'Account currency (ISO, e.g. USD)', 'type' => 'text', 'required' => false, 'placeholder' => 'USD'],
            ['key' => 'timezone', 'label_key' => 'costImport.fields.accountTimezone', 'label' => 'Stats timezone (IANA, default UTC)', 'type' => 'text', 'required' => false, 'placeholder' => 'UTC'],
        ];
    }

    public static function testConnection(array $credentials): array
    {
        try {
            $token = self::token($credentials);
            $decoded = self::call('GET', '/v1/advertiser/balance', $token, [], null, 15);
            $balance = round((float) ($decoded['balance'] ?? 0), 2);
            return ['success' => true, 'message' => 'Galaxion OK — balance ' . $balance];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @throws RuntimeException on auth/transport errors.
     */
    public static function fetchRecords(array $credentials, string $dateFrom, string $dateTo, array $fieldMapping = []): array
    {
        $token = self::token($credentials);
        $currency = strtoupper(trim((string) ($credentials['currency'] ?? ''))) ?: 'USD';

        $records = [];
        $from = strtotime($dateFrom . ' UTC');
        $to = min(strtotime($dateTo . ' UTC'), $from + 366 * 86400);
        for ($d = $from; $d <= $to; $d += 86400) {
            $day = gmdate('Y-m-d', $d);
            $decoded = self::call('GET', '/v1/advertiser/statistics', $token, [
                'dateFrom' => $day . ' 00:00:00',
                'dateTo' => $day . ' 23:59:59',
                'groupBy[0]' => 'campaign',
                'groupBy[1]' => 'zone',
                'orderBy[field]' => 'money',
                'orderBy[direction]' => 'desc',
                'limit' => self::LIMIT,
            ], null, 60);
            $rows = $decoded['rows'] ?? [];
            $rows = is_array($rows) ? $rows : [];
            // Rows are ordered by spend: a full page means the cheapest rows
            // were cut off. Say so instead of importing a silently short day.
            if (count($rows) >= self::LIMIT) {
                throw new \RuntimeException("Galaxion: more than " . self::LIMIT . " campaign × zone rows on $day — the day would be imported incomplete.");
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $campaignId = (string) ($row['campaignId'] ?? '');
                if ($campaignId === '' && preg_match('/^\s*(\d+)\s*-/', (string) ($row['campaign'] ?? ''), $m)) {
                    $campaignId = $m[1];
                }
                $zoneId = trim((string) ($row['zone'] ?? ''));
                $amount = (float) ($row['money'] ?? 0);
                if ($campaignId === '' || $amount <= 0) {
                    continue;
                }
                $name = (string) ($row['campaign'] ?? '');
                if (preg_match('/^\s*\d+\s*-\s*(.*)$/', $name, $m)) {
                    $name = $m[1];
                }
                $records[] = [
                    'external_id' => 'gx_' . $campaignId . '_' . ($zoneId !== '' ? $zoneId : 'all') . '_' . $day,
                    'source_campaign_id' => $campaignId,
                    'campaign_name' => $name,
                    'ad_id' => '',
                    'adset_id' => '',
                    'zone_id' => $zoneId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'date' => $day,
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'platform_clicks' => (int) ($row['clicks'] ?? 0),
                    'raw_json' => json_encode($row),
                ];
            }
            if ($d < $to && self::$pauseSeconds > 0) {
                usleep((int) (self::$pauseSeconds * 1000000));
            }
        }
        return $records;
    }

    /**
     * Block / unblock zones on one Galaxion campaign: read its zone list,
     * merge, write back in blacklist mode.
     *
     * @return array{ok:bool, applied?:int, error?:string}
     */
    public static function setZoneBlock(array $credentials, string $networkCampaignId, array $zones, bool $block): array
    {
        try {
            $zones = array_values(array_unique(array_filter(array_map('strval', $zones), fn ($z) => $z !== '')));
            if ($zones === []) {
                return ['ok' => false, 'error' => 'no zones given'];
            }
            if (!ctype_digit($networkCampaignId)) {
                return ['ok' => false, 'error' => "Galaxion campaign id must be numeric, got '$networkCampaignId'"];
            }
            $token = self::token($credentials);
            $path = '/v1/advertiser/campaigns/' . $networkCampaignId;
            $decoded = self::call('GET', $path, $token, [], null, 30);
            $campaign = isset($decoded['id']) || isset($decoded['targeting']) ? $decoded : ($decoded['data'] ?? []);
            if (!is_array($campaign) || !is_array($campaign['targeting'] ?? null)) {
                return ['ok' => false, 'error' => 'Galaxion: campaign targeting not readable'];
            }
            $targeting = $campaign['targeting'];
            $current = [];
            foreach ((array) ($targeting['zoneLimitations'] ?? []) as $z) {
                $id = is_array($z) ? ($z['id'] ?? ($z['zone'] ?? '')) : $z;
                if ((string) $id !== '') {
                    $current[] = (string) $id;
                }
            }
            $exclude = (bool) ($targeting['zoneLimitationsExclude'] ?? false);
            if (!$exclude && $current !== []) {
                return ['ok' => false, 'error' => 'campaign uses a zone whitelist (include list) — blocking would need blacklist mode; switch the campaign to a zone blacklist in Galaxion'];
            }
            $merged = $block
                ? array_values(array_unique(array_merge($current, $zones)))
                : array_values(array_diff($current, $zones));
            // Ids go back as numbers when they are numbers (the API's own shape).
            $out = array_map(fn ($z) => ctype_digit($z) ? (int) $z : $z, $merged);
            self::call('PATCH', $path, $token, [], [
                'name' => (string) ($campaign['name'] ?? ''),
                'targeting' => [
                    'zoneLimitations' => $out,
                    'zoneLimitationsExclude' => true,
                ],
            ], 30, true);
            return ['ok' => true, 'applied' => count($zones)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** A pasted token wins; otherwise log in with e-mail + password. */
    private static function token(array $credentials): string
    {
        $token = trim((string) ($credentials['api_token'] ?? ''));
        if ($token !== '') {
            return $token;
        }
        $email = trim((string) ($credentials['email'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');
        if ($email === '' || $password === '') {
            throw new \RuntimeException('Galaxion: e-mail and password (or an API token) required.');
        }
        $decoded = self::call('POST', '/v1/auth', null, [], ['email' => $email, 'password' => $password], 20);
        $token = (string) ($decoded['token'] ?? ($decoded['data']['token'] ?? ''));
        if ($token === '') {
            throw new \RuntimeException('Galaxion: login failed — check the e-mail and password.');
        }
        return $token;
    }

    private static function call(string $method, string $path, ?string $token, array $query, ?array $body, int $timeout, bool $allowEmpty = false): array
    {
        $raw = self::httpCall($method, $path, $token, $query, $body, $timeout);
        if ($allowEmpty && trim($raw) === '') {
            return [];  // 204 No Content on a successful PATCH
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Galaxion: unreadable response from ' . $path . '.');
        }
        return $decoded;
    }

    private static function httpCall(string $method, string $path, ?string $token, array $query, ?array $body, int $timeout): string
    {
        if (self::$testHttpHandler) {
            $handler = self::$testHttpHandler;
            $result = $handler($method, $path, $body ?? $query, ['token' => $token]);
            if (is_array($result) && array_key_exists('error', $result) && $result['error']) {
                throw new \RuntimeException('Galaxion: ' . $result['error']);
            }
            return (string) ($result['body'] ?? '');
        }

        $url = self::API_BASE . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($token !== null) {
            $headers[] = 'X-Auth-Token: ' . $token;
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? new \stdClass());
        } elseif ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? new \stdClass());
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new \RuntimeException('Galaxion: HTTP transport error ' . $err);
        }
        if ($code === 401 || $code === 403) {
            throw new \RuntimeException("Galaxion: HTTP $code — " . ($path === '/v1/auth' ? 'wrong e-mail or password.' : 'the token was rejected or expired.'));
        }
        if ($code === 429) {
            throw new \RuntimeException('Galaxion: HTTP 429 — rate limited, retry on the next sync.');
        }
        if ($code < 200 || $code >= 400) {
            throw new \RuntimeException("Galaxion: HTTP $code: " . substr((string) $resp, 0, 300));
        }
        return (string) $resp;
    }
}
