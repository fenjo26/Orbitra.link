<?php
// aggregator_engines/HilltopAdsEngine.php
//
// HilltopAds (hilltopads.com) advertiser spend, per campaign per day.
// API shapes verified against a live account (probe 2026-10-12):
//
//   GET /advertiser/balance                → balance / auth check
//   GET /advertiser/listStats?Date&Date2&group=campaignId
//                                         → {status, result: { "<campaignId>": [row, …], … }}
//        Each campaign maps to a LIST of rows (the working sync reads rows[0]);
//        a bare row object is accepted too. An empty day is `result: []`.
//   Errors come back as HTTP 200 with {status: "error", errors: [...]} — they
//   must surface as a sync error, not as "no spend".
//   GET /advertiser/listStats?…&group=zoneId  — the endpoint answers, but its
//        rows carry NO campaign id (that is why zone ids never made it into
//        the working sync this engine follows), so v1 imports campaign-level
//        spend only. Zone-level for HilltopAds waits for a verified row shape.
//
//   ⚠ The date params are `Date` / `Date2` — capitalized. Lowercase date/date2
//     are silently ignored and the API quietly answers a yesterday-only
//     window, which used to look like "sync works, no spend".
//   ⚠ group value casing: `zoneId` (capital D… lowercase d) / `campaignId`.
//
// Auth: `key` query parameter (My Account → API tab).
//
// Zone blocking (POST /advertiser/addToBlackList, DELETE …/removeFromBlackList,
// params campaignID/sourceID) is verified but not wired here — the Zone Killer
// ships dry-run first.

class HilltopAdsEngine
{
    private const API_BASE = 'https://api.hilltopads.com';

    /** Tests replace this to fake the network (see tests/push_cost_engines_test.php). */
    public static $testHttpHandler = null;

    public static function getRequiredFields(): array
    {
        return [
            ['key' => 'api_key', 'label_key' => 'costImport.fields.apiKey', 'label' => 'API Key', 'type' => 'password', 'required' => true],
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
            $response = self::httpCall('GET', '/advertiser/balance', $credentials, [], 15);
            $decoded = json_decode($response['body'], true);
            if (!is_array($decoded)) {
                return ['success' => false, 'message' => 'HilltopAds: unreadable balance response.'];
            }
            self::assertSuccess($decoded);
            // Real shape: {status: success, result: {balance: "579.64", currency: "USD"}}
            $result = $decoded['result'] ?? null;
            $balance = is_array($result) ? (float) ($result['balance'] ?? 0) : (float) ($decoded['balance'] ?? 0);
            $cur = is_array($result) && !empty($result['currency']) ? ' ' . $result['currency'] : '';
            return ['success' => true, 'message' => 'HilltopAds OK — balance ' . round($balance, 2) . $cur];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Campaign-level spend per day (see the file header for why not zones).
     *
     * @throws RuntimeException on auth/transport errors.
     */
    public static function fetchRecords(array $credentials, string $dateFrom, string $dateTo, array $fieldMapping = []): array
    {
        $missing = self::validateCredentials($credentials);
        if ($missing) {
            throw new \RuntimeException('HilltopAds: ' . implode(', ', $missing) . ' required.');
        }
        $currency = strtoupper(trim((string) ($credentials['currency'] ?? ''))) ?: 'USD';

        $records = [];
        $from = strtotime($dateFrom . ' UTC');
        $to = min(strtotime($dateTo . ' UTC'), $from + 366 * 86400);
        for ($d = $from; $d <= $to; $d += 86400) {
            $day = gmdate('Y-m-d', $d);
            // Date / Date2 — capitalized, see the file header.
            $response = self::httpCall('GET', '/advertiser/listStats', $credentials, [
                'Date' => $day,
                'Date2' => $day,
                'group' => 'campaignId',
            ], 40);
            $decoded = json_decode($response['body'], true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('HilltopAds: unreadable listStats response.');
            }
            self::assertSuccess($decoded);
            $result = $decoded['result'] ?? [];
            if (!is_array($result)) {
                continue;
            }
            // Keyed by campaign id (verified). A plain list carries no ids in
            // its keys — then only an explicit id column counts.
            $keyedById = !self::isList($result);
            foreach ($result as $key => $entry) {
                if (!is_array($entry) || $entry === []) {
                    continue;
                }
                // A list of rows per campaign (verified shape) or one row.
                $rows = self::isList($entry) ? $entry : [$entry];
                $first = is_array($rows[0] ?? null) ? $rows[0] : [];
                // json_decode hands numeric object keys back as ints — cast;
                // an explicit id column wins when present.
                $campaignId = (string) ($first['campaignId'] ?? ($first['campaign_id'] ?? ($keyedById ? (string) $key : '')));
                if ($campaignId === '' || $campaignId === '0') {
                    continue;
                }
                $amount = 0.0;
                $impressions = 0;
                $clicks = 0;
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $amount += self::rowAmount($row);
                    $impressions += (int) ($row['impressions'] ?? ($row['views'] ?? 0));
                    $clicks += (int) ($row['clicks'] ?? 0);
                }
                if ($amount <= 0) {
                    continue;
                }
                $records[] = [
                    'external_id' => 'ht_' . $campaignId . '_' . $day,
                    'source_campaign_id' => $campaignId,
                    'campaign_name' => (string) ($first['campaignTitle'] ?? ($first['title'] ?? '')),
                    'ad_id' => '',
                    'adset_id' => '',
                    'zone_id' => '',
                    'amount' => $amount,
                    'currency' => $currency,
                    'date' => $day,
                    'impressions' => $impressions,
                    'platform_clicks' => $clicks,
                    'raw_json' => json_encode($entry),
                ];
            }
            usleep(150000);
        }
        return $records;
    }

    /** Production sync read `revenue` first, `spent` as fallback. */
    private static function rowAmount(array $row): float
    {
        foreach (['revenue', 'spent', 'spend', 'cost'] as $key) {
            if (isset($row[$key]) && (float) $row[$key] > 0) {
                return (float) $row[$key];
            }
        }
        return 0.0;
    }

    /** array_is_list() without needing PHP 8.1 (installs ride distro PHP). */
    private static function isList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    /** HTTP 200 with status=error (bad key, foreign campaign…) → exception. */
    private static function assertSuccess(array $decoded): void
    {
        if (($decoded['status'] ?? '') === 'error') {
            $errors = $decoded['errors'] ?? ($decoded['message'] ?? 'unknown error');
            $text = is_array($errors) ? implode('; ', array_map('strval', $errors)) : (string) $errors;
            throw new \RuntimeException('HilltopAds: ' . $text);
        }
    }

    private static function validateCredentials(array $c): array
    {
        $missing = [];
        foreach (['api_key'] as $k) {
            if (trim((string) ($c[$k] ?? '')) === '') {
                $missing[] = $k;
            }
        }
        return $missing;
    }

    /**
     * Block/unblock zones on one campaign. Verified shape: POST
     * /advertiser/addToBlackList with campaignID + sourceID, DELETE
     * removeFromBlackList to undo — one zone per call.
     *
     * @param string[] $zones
     * @return array{ok:bool, error?:string, applied?:int, zone_errors?:array<string,string>}
     */
    public static function setZoneBlock(array $credentials, string $networkCampaignId, array $zones, bool $block): array
    {
        try {
            $zones = array_values(array_unique(array_filter(array_map('strval', $zones), fn ($z) => $z !== '')));
            if ($zones === []) {
                return ['ok' => false, 'error' => 'no zones given'];
            }
            $applied = 0;
            $zoneErrors = [];
            foreach ($zones as $zone) {
                try {
                    $query = ['campaignID' => $networkCampaignId, 'sourceID' => $zone];
                    $response = self::httpCall(
                        $block ? 'POST' : 'DELETE',
                        $block ? '/advertiser/addToBlackList' : '/advertiser/removeFromBlackList',
                        $credentials,
                        $query,
                        20
                    );
                    $decoded = json_decode($response['body'], true);
                    if (is_array($decoded)) {
                        self::assertSuccess($decoded);
                    }
                    $applied++;
                } catch (\Throwable $e) {
                    // A "zone not found" answer on a probe-style fake id is the
                    // endpoint itself confirming auth works; a REAL zone that
                    // the network no longer knows is still worth reporting.
                    $zoneErrors[$zone] = $e->getMessage();
                }
            }
            if ($applied === 0) {
                $first = reset($zoneErrors);
                return ['ok' => false, 'error' => $first !== false ? $first : 'all zone calls failed', 'zone_errors' => $zoneErrors];
            }
            return ['ok' => true, 'applied' => $applied, 'zone_errors' => $zoneErrors];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{body:string}
     */
    private static function httpCall(string $method, string $path, array $credentials, array $query, int $timeout): array
    {
        $query = array_merge($query, ['key' => trim((string) $credentials['api_key'])]);
        if (self::$testHttpHandler) {
            $handler = self::$testHttpHandler;
            $result = $handler($method, $path, $query, $credentials, null);
            if (is_array($result) && array_key_exists('error', $result) && $result['error']) {
                throw new \RuntimeException('HilltopAds: ' . $result['error']);
            }
            return ['body' => (string) ($result['body'] ?? '')];
        }

        $ch = curl_init(self::API_BASE . $path . '?' . http_build_query($query));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        if ($method !== 'GET' && $method !== 'DELETE') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new \RuntimeException('HilltopAds: HTTP transport error ' . $err);
        }
        if ($code === 401 || $code === 403) {
            throw new \RuntimeException("HilltopAds: HTTP $code — the API key was rejected.");
        }
        if ($code < 200 || $code >= 400) {
            throw new \RuntimeException("HilltopAds: HTTP $code: " . substr((string) $body, 0, 300));
        }
        return ['body' => (string) $body];
    }
}
