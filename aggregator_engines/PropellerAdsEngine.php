<?php
// aggregator_engines/PropellerAdsEngine.php
//
// PropellerAds (propellerads.com) advertiser spend via the SSP API v5,
// per campaign × zone × day.
// API shapes verified against a live account (probe 2026-10-12):
//
//   GET  /v5/adv/balance                        → auth check
//   GET  /v5/adv/campaigns?per_page             → {result: [ {id, …} ]}
//   GET  /v5/adv/statistics?group_by[]=campaign_id&group_by[]=zone_id&day_from&day_to&per_page
//                                             → items[] rows: campaign_id / campaign_name,
//                                               zone_id, money, impressions, clicks
//
//   The working sync this follows requests one day at a time
//   (day_from == day_to) — the row date below is that day.
//   403 on write probes was per-campaign (archived/status-locked campaigns),
//   reads answer 200 for every status.
//
// Auth: `Authorization: Bearer <token>` (SSP Profile page).
//
// Zone blocking (PATCH /v5/adv/campaigns/{id}/targeting/exclude/zone with the
// mirrored {"zone": [...]} body — the docs' merge format is wrong) is
// verified but not wired here; the Zone Killer ships dry-run first.

class PropellerAdsEngine
{
    private const API_BASE = 'https://ssp-api.propellerads.com/v5';

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
            $response = self::httpCall('GET', '/adv/balance', $credentials, [], 15);
            $decoded = json_decode($response['body'], true);
            $balance = is_array($decoded)
                ? (float) ($decoded['balance'] ?? $decoded['result']['balance'] ?? $decoded['message'] ?? 0)
                : 0.0;
            return ['success' => true, 'message' => 'PropellerAds OK — balance ' . round($balance, 2)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Per-day, per-campaign, per-zone spend (group_by[]=campaign_id&group_by[]
     * =zone_id, single-day windows — the verified request shape).
     *
     * @throws RuntimeException on auth/transport errors.
     */
    public static function fetchRecords(array $credentials, string $dateFrom, string $dateTo, array $fieldMapping = []): array
    {
        $missing = self::validateCredentials($credentials);
        if ($missing) {
            throw new \RuntimeException('PropellerAds: ' . implode(', ', $missing) . ' required.');
        }
        $currency = strtoupper(trim((string) ($credentials['currency'] ?? ''))) ?: 'USD';

        $records = [];
        $from = strtotime($dateFrom . ' UTC');
        $to = min(strtotime($dateTo . ' UTC'), $from + 366 * 86400);
        for ($d = $from; $d <= $to; $d += 86400) {
            $day = gmdate('Y-m-d', $d);
            $response = self::httpCall('GET', '/adv/statistics', $credentials, [
                'group_by[]' => ['campaign_id', 'zone_id'],
                'day_from' => $day,
                'day_to' => $day,
                'per_page' => 20000,
            ], 40);
            $decoded = json_decode($response['body'], true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('PropellerAds: unreadable statistics response.');
            }
            $items = $decoded['items'] ?? ($decoded['result']['items'] ?? ($decoded['result'] ?? []));
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $campaignId = (string) ($row['campaign_id'] ?? ($row['id'] ?? ''));
                if ($campaignId === '') {
                    continue;
                }
                $zoneId = trim((string) ($row['zone_id'] ?? ($row['zone'] ?? '')));
                // The working sync also matched rows by campaign_name (an id
                // glued before "|"); an id column beats that, take it first.
                $amount = self::rowAmount($row);
                if ($amount <= 0) {
                    continue;
                }
                $records[] = [
                    'external_id' => 'pa_' . $campaignId . '_' . ($zoneId !== '' ? $zoneId : 'all') . '_' . $day,
                    'source_campaign_id' => $campaignId,
                    'campaign_name' => (string) ($row['campaign_name'] ?? ''),
                    'ad_id' => '',
                    'adset_id' => '',
                    'zone_id' => $zoneId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'date' => $day,
                    'impressions' => (int) ($row['impressions'] ?? ($row['views'] ?? 0)),
                    'platform_clicks' => (int) ($row['clicks'] ?? 0),
                    'raw_json' => json_encode($row),
                ];
            }
            usleep(150000);
        }
        return $records;
    }

    /** Production key is `money`; accept the usual aliases. */
    private static function rowAmount(array $row): float
    {
        foreach (['money', 'spend', 'cost', 'spent'] as $key) {
            if (isset($row[$key]) && (float) $row[$key] > 0) {
                return (float) $row[$key];
            }
        }
        return 0.0;
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
     * Block/unblock zones on one campaign. Verified shape: GET
     * /adv/campaigns/{id}/targeting/exclude/zone answers the mirrored
     * {"zone":[…]} format, and PATCH back the SAME format (the docs' merge
     * operation is wrong — the mirror is what was verified working). The
     * GET-merge-PATCH keeps zones excluded by other tools.
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
            $path = '/adv/campaigns/' . rawurlencode($networkCampaignId) . '/targeting/exclude/zone';
            $response = self::httpCall('GET', $path, $credentials, [], 20);
            $decoded = json_decode($response['body'], true);
            $current = array_map('strval', (array) (is_array($decoded) ? ($decoded['zone'] ?? []) : []));
            $merged = $block ? array_values(array_unique(array_merge($current, $zones))) : array_values(array_diff($current, $zones));
            self::httpCall('PATCH', $path, $credentials, [], 20, ['zone' => $merged]);
            return ['ok' => true, 'applied' => count(array_intersect($block ? $merged : $current, $zones))];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (strpos($msg, '403') !== false) {
                $msg .= ' — the campaign is archived or locked for edits (status-locked campaigns answer 403 on writes).';
            }
            return ['ok' => false, 'error' => $msg];
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
                throw new \RuntimeException('PropellerAds: ' . $result['error']);
            }
            return ['body' => (string) ($result['body'] ?? '')];
        }

        $url = self::API_BASE . $path . ($query ? '?' . self::buildQuery($query) : '');
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
            throw new \RuntimeException('PropellerAds: HTTP transport error ' . $err);
        }
        if ($code === 401) {
            throw new \RuntimeException('PropellerAds: HTTP 401 — the API token was rejected or expired.');
        }
        if ($code === 429) {
            throw new \RuntimeException('PropellerAds: HTTP 429 — rate limited, retry on the next sync.');
        }
        if ($code < 200 || $code >= 400) {
            throw new \RuntimeException("PropellerAds: HTTP $code: " . substr((string) $body, 0, 300));
        }
        return ['body' => (string) $body];
    }

    /** http_build_query() mangles repeated group_by[] keys; build it manually. */
    private static function buildQuery(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            foreach ((array) $value as $item) {
                $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $item);
            }
        }
        return implode('&', $parts);
    }
}
