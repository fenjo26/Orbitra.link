<?php
// aggregator_engines/AdMavenEngine.php
//
// AdMaven (ad-maven.com) advertiser spend, reported per campaign × zone × day.
// API shapes verified against a live advertiser account (probe 2026-10-12):
//
//   GET  /api/public/campaign            → {type, message: [ {id, name, …} ]}
//        (`group_by=campaign` on /reports is REJECTED with HTTP 500 — the
//        campaign list endpoint is the only way to enumerate campaign ids)
//   GET  /api/public/reports?from_date&to_date&campaign_id&group_by=source_id&columns=cost
//                                        → {message: {rows: [ {source_id, cost, …} ]}}
//        group_by=source_id is the zone breakdown; sub_source_id also exists.
//        Rows carry no date, so this engine always requests single days: a
//        multi-day window booked on one date puts a week of spend on one
//        day's clicks, and the next 5-day re-sync then overwrites that row
//        with a single day and the rest of the week is lost.
//        One group_by=campaign_id call over the whole window first tells which
//        campaigns spent at all, so idle campaigns cost no per-day requests.
//   GET  /api/public/available_balance   → {message: <number>}
//
// Auth: `Authorization: Bearer <api token>` (the advertiser panel's API token).
//
// Zone blocking (PUT /api/public/targeting/source_id) is deliberately NOT here:
// the Zone Killer ships dry-run first and only calls verified write endpoints
// once its rules UI lands.

class AdMavenEngine
{
    private const API_BASE = 'https://advertisers.ad-maven.com';

    /** Tests replace this to fake the network (see tests/push_cost_engines_test.php). */
    public static $testHttpHandler = null;

    public static function getRequiredFields(): array
    {
        return [
            ['key' => 'api_token', 'label_key' => 'costImport.fields.apiToken', 'label' => 'API Token', 'type' => 'password', 'required' => true],
            ['key' => 'currency', 'label_key' => 'costImport.fields.accountCurrency', 'label' => 'Account currency (ISO, e.g. USD)', 'type' => 'text', 'required' => false, 'placeholder' => 'USD'],
            ['key' => 'timezone', 'label_key' => 'costImport.fields.accountTimezone', 'label' => 'Stats timezone (IANA, default UTC)', 'type' => 'text', 'required' => false, 'placeholder' => 'UTC'],
        ];
    }

    public static function testConnection(array $credentials): array
    {
        try {
            $missing = self::validateCredentials($credentials);
            if ($missing) {
                return ['success' => false, 'message' => 'Missing: ' . implode(', ', $missing)];
            }
            $response = self::httpCall('GET', '/api/public/available_balance', $credentials, [], 15);
            $decoded = json_decode($response['body'], true);
            $balance = is_array($decoded) ? (float) ($decoded['message'] ?? 0) : 0.0;
            return ['success' => true, 'message' => 'AdMaven OK — balance ' . round($balance, 2)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Per-day, per-campaign, per-zone spend for the window.
     *
     * Rows carry no date, so the day is the request's own day — always single
     * days (see the file header for why chunking loses spend). The request
     * count is bounded by asking once which campaigns spent in the window.
     *
     * @throws RuntimeException on auth/transport errors — the caller stores it
     *         in last_sync_error (aggregator_cron.php / api.php aggregator_sync).
     */
    public static function fetchRecords(array $credentials, string $dateFrom, string $dateTo, array $fieldMapping = []): array
    {
        $missing = self::validateCredentials($credentials);
        if ($missing) {
            throw new \RuntimeException('AdMaven: ' . implode(', ', $missing) . ' required.');
        }

        $currency = strtoupper(trim((string) ($credentials['currency'] ?? ''))) ?: 'USD';

        // Campaign ids first: the reports endpoint needs campaign_id for a
        // zone breakdown that is attributable, and `group_by=campaign_id`
        // without it would return zone rows no click can be matched to.
        $campaigns = self::fetchCampaigns($credentials);

        // Single days only (see the method note).
        $chunks = [];
        $from = strtotime($dateFrom . ' UTC');
        $to = min(strtotime($dateTo . ' UTC'), $from + 366 * 86400);
        for ($d = $from; $d <= $to; $d += 86400) {
            $chunks[] = [gmdate('Y-m-d', $d), gmdate('Y-m-d', $d)];
        }

        // Skip campaigns with no spend in the whole window — a 30-day first
        // sync over dozens of paused campaigns would otherwise be thousands
        // of requests. Unreadable answer → keep every campaign (never lose
        // spend to an optimisation).
        $spent = self::campaignsWithSpend($credentials, gmdate('Y-m-d', $from), gmdate('Y-m-d', $to));
        if ($spent !== null) {
            $campaigns = array_values(array_filter($campaigns, fn($c) => isset($spent[(string) $c['id']])));
        }

        $records = [];
        foreach ($campaigns as $campaign) {
            $campaignId = (string) $campaign['id'];
            $campaignName = (string) ($campaign['name'] ?? '');
            foreach ($chunks as [$chunkFrom, $chunkTo]) {
                $response = self::httpCall('GET', '/api/public/reports', $credentials, [
                    'from_date' => $chunkFrom,
                    'to_date' => $chunkTo,
                    'campaign_id' => $campaignId,
                    'group_by' => 'source_id',
                    'columns' => 'cost',
                ], 40);
                $decoded = json_decode($response['body'], true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('AdMaven: unreadable reports response.');
                }
                $message = $decoded['message'] ?? [];
                $rows = is_array($message) ? ($message['rows'] ?? []) : [];
                foreach ((array) $rows as $row) {
                    $zoneId = trim((string) ($row['source_id'] ?? ''));
                    if ($zoneId === '') {
                        continue;
                    }
                    // Production-verified key is `cost` (columns=cost); the
                    // probe without columns saw `revenue` — accept both.
                    $amount = self::rowAmount($row, ['cost', 'revenue', 'spend', 'spent']);
                    if ($amount <= 0) {
                        continue;
                    }
                    $records[] = [
                        'external_id' => 'am_' . $campaignId . '_' . $zoneId . '_' . $chunkTo,
                        'source_campaign_id' => $campaignId,
                        'campaign_name' => $campaignName,
                        'ad_id' => '',
                        'adset_id' => '',
                        'zone_id' => $zoneId,
                        'amount' => $amount,
                        'currency' => $currency,
                        'date' => $chunkTo,
                        'impressions' => (int) ($row['impressions'] ?? ($row['views'] ?? 0)),
                        'platform_clicks' => (int) ($row['clicks'] ?? 0),
                        'raw_json' => json_encode($row),
                    ];
                }
                // Be polite to the reporting API — the cron walks every
                // campaign × day through this loop.
                usleep(150000);
            }
        }
        return $records;
    }

    /**
     * Campaign list: {message: [ {id, name} ]}. A transient failure here must
     * surface (empty return would read as "no spend today" in the UI).
     *
     * @return array<int, array{id:mixed, name:mixed}>
     */
    private static function fetchCampaigns(array $credentials): array
    {
        $response = self::httpCall('GET', '/api/public/campaign', $credentials, [], 20);
        $decoded = json_decode($response['body'], true);
        $list = is_array($decoded) ? ($decoded['message'] ?? $decoded['data'] ?? null) : null;
        if (!is_array($list)) {
            throw new \RuntimeException('AdMaven: campaign list response has no message[] array.');
        }
        $campaigns = [];
        foreach ($list as $item) {
            if (is_array($item) && isset($item['id'])) {
                $campaigns[] = $item;
            }
        }
        return $campaigns;
    }

    private static function rowAmount(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && (float) $row[$key] > 0) {
                return (float) $row[$key];
            }
        }
        return 0.0;
    }

    /**
     * Campaign ids with spend > 0 over the window (one group_by=campaign_id
     * request), or null when the answer is not readable.
     *
     * @return array<string,true>|null
     */
    private static function campaignsWithSpend(array $credentials, string $from, string $to): ?array
    {
        try {
            $response = self::httpCall('GET', '/api/public/reports', $credentials, [
                'from_date' => $from,
                'to_date' => $to,
                'group_by' => 'campaign_id',
                'columns' => 'cost',
            ], 40);
        } catch (\Throwable $e) {
            return null;
        }
        $decoded = json_decode($response['body'], true);
        $rows = is_array($decoded) && is_array($decoded['message'] ?? null) ? ($decoded['message']['rows'] ?? null) : null;
        if (!is_array($rows)) {
            return null;
        }
        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['campaign_id'])) {
                return null;
            }
            if (self::rowAmount($row, ['cost', 'revenue', 'spend', 'spent']) > 0) {
                $ids[(string) $row['campaign_id']] = true;
            }
        }
        return $ids;
    }

    private static function validateCredentials(array $c): array
    {
        $missing = [];
        foreach (['api_token'] as $k) {
            if (trim((string) ($c[$k] ?? '')) === '') {
                $missing[] = $k;
            }
        }
        return $missing;
    }

    /**
     * Block/unblock zones (sites) on one AdMaven campaign. Verified write
     * shape: GET /api/public/targeting/source_id?id= → {message:{values:[…],
     * include:bool}}, then PUT the same endpoint with {id, values, include:false}.
     * The merge keeps zones blacklisted by other tools — a blind PUT would
     * erase them.
     *
     * Guard from the probe: when the campaign targets in INCLUDE (whitelist)
     * mode, blacklist entries have no effect — refuse with an explanation
     * instead of silently "succeeding".
     *
     * @param string[] $zones
     * @return array{ok:bool, error?:string, applied?:int}
     */
    public static function setZoneBlock(array $credentials, string $networkCampaignId, array $zones, bool $block): array
    {
        try {
            $zones = array_values(array_unique(array_filter(array_map('strval', $zones), fn ($z) => $z !== '')));
            if ($zones === []) {
                return ['ok' => false, 'error' => 'no zones given'];
            }
            $response = self::httpCall('GET', '/api/public/targeting/source_id', $credentials, ['id' => $networkCampaignId], 20);
            $decoded = json_decode($response['body'], true);
            $targeting = is_array($decoded) ? ($decoded['message'] ?? []) : [];
            if (!is_array($targeting) || !array_key_exists('values', $targeting)) {
                return ['ok' => false, 'error' => 'targeting response has no message.values'];
            }
            if (($targeting['include'] ?? false) === true) {
                return ['ok' => false, 'error' => 'campaign is in whitelist (include) targeting — blacklist entries have no effect; switch the campaign to blacklist mode in AdMaven'];
            }
            $current = array_map('strval', (array) $targeting['values']);
            $merged = $block ? array_values(array_unique(array_merge($current, $zones))) : array_values(array_diff($current, $zones));
            self::httpCall('PUT', '/api/public/targeting/source_id', $credentials, [], 20, [
                'id' => (int) $networkCampaignId,
                'values' => $merged,
                'include' => false,
            ]);
            return ['ok' => true, 'applied' => count(array_intersect($block ? $merged : $current, $zones))];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{body:string}
     */
    private static function httpCall(string $method, string $path, array $credentials, array $query, int $timeout, ?array $jsonBody = null): array
    {
        if (self::$testHttpHandler) {
            $handler = self::$testHttpHandler;
            $result = $handler($method, $path, $query, $credentials, $jsonBody);
            if (is_array($result) && array_key_exists('error', $result) && $result['error']) {
                throw new \RuntimeException('AdMaven: ' . $result['error']);
            }
            return ['body' => (string) ($result['body'] ?? '')];
        }

        $url = self::API_BASE . $path . ($query ? '?' . http_build_query($query) : '');
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . trim((string) $credentials['api_token']),
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new \RuntimeException('AdMaven: HTTP transport error ' . $err);
        }
        if ($code === 401 || $code === 403) {
            throw new \RuntimeException("AdMaven: HTTP $code — the API token was rejected or lacks write access.");
        }
        if ($code < 200 || $code >= 400) {
            throw new \RuntimeException("AdMaven: HTTP $code: " . substr((string) $body, 0, 300));
        }
        return ['body' => (string) $body];
    }
}
