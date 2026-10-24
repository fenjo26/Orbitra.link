<?php
// aggregator_engines/AdsterraEngine.php
//
// Adsterra (adsterra.com) advertiser spend, per campaign per day.
// API v3 shapes from a live account (probe 2026-10-12):
//
//   GET /advertiser/balance.json                      → {item: "330.43"}
//   GET /advertiser/campaigns.json                    → {items: [ {id, alias, …} ]}
//   GET /advertiser/stats.json?start_date&finish_date&group_by=campaign
//                                                    → {items: [ {campaign, spent, impressions, clicks} ], timezone}
//   group_by=placement answers too, but its rows carry NO campaign id, so a
//   placement can't be tied to the campaign whose clicks it bought — v1
//   imports campaign-level spend (zone_id ''), spread over the campaign's
//   clicks like HilltopAds. Stats are GMT+00:00.
//
// Auth: header `X-API-Key` (Settings → API → Generate new token). Only one
// token is valid at a time — generating a new one in the panel kills the old.

class AdsterraEngine
{
    private const API_BASE = 'https://api3.adsterratools.com';

    /** Tests replace this to fake the network (see tests/push_cost_engines_more_test.php). */
    public static $testHttpHandler = null;

    public static function getRequiredFields(): array
    {
        return [
            ['key' => 'api_key', 'label_key' => 'costImport.fields.apiKey', 'label' => 'API Key', 'type' => 'password', 'required' => true],
            ['key' => 'currency', 'label_key' => 'costImport.fields.accountCurrency', 'label' => 'Account currency (ISO, e.g. USD)', 'type' => 'text', 'required' => false, 'placeholder' => 'USD'],
        ];
    }

    public static function testConnection(array $credentials): array
    {
        try {
            if (trim((string) ($credentials['api_key'] ?? '')) === '') {
                return ['success' => false, 'message' => 'Missing: api_key'];
            }
            $decoded = self::call('/advertiser/balance.json', $credentials, [], 15);
            $balance = round((float) ($decoded['item'] ?? ($decoded['balance'] ?? 0)), 2);
            return ['success' => true, 'message' => 'Adsterra OK — balance ' . $balance];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @throws RuntimeException on auth/transport errors.
     */
    public static function fetchRecords(array $credentials, string $dateFrom, string $dateTo, array $fieldMapping = []): array
    {
        if (trim((string) ($credentials['api_key'] ?? '')) === '') {
            throw new \RuntimeException('Adsterra: api_key required.');
        }
        $currency = strtoupper(trim((string) ($credentials['currency'] ?? ''))) ?: 'USD';

        // Names for the reports; spend import does not depend on them.
        $names = [];
        try {
            $list = self::call('/advertiser/campaigns.json', $credentials, [], 30);
            foreach ((array) ($list['items'] ?? []) as $c) {
                if (is_array($c) && isset($c['id'])) {
                    $names[(string) $c['id']] = (string) ($c['alias'] ?? ($c['name'] ?? ''));
                }
            }
        } catch (\Throwable $e) {
            $names = [];
        }

        $records = [];
        $from = strtotime($dateFrom . ' UTC');
        $to = min(strtotime($dateTo . ' UTC'), $from + 366 * 86400);
        for ($d = $from; $d <= $to; $d += 86400) {
            // Single days: rows carry no date, a range would land on one day.
            $day = gmdate('Y-m-d', $d);
            $decoded = self::call('/advertiser/stats.json', $credentials, [
                'start_date' => $day,
                'finish_date' => $day,
                'group_by' => 'campaign',
            ], 40);
            foreach ((array) ($decoded['items'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $campaignId = (string) ($row['campaign'] ?? ($row['campaign_id'] ?? ''));
                $amount = (float) ($row['spent'] ?? ($row['cost'] ?? 0));
                if ($campaignId === '' || $amount <= 0) {
                    continue;
                }
                $records[] = [
                    'external_id' => 'as_' . $campaignId . '_' . $day,
                    'source_campaign_id' => $campaignId,
                    'campaign_name' => $names[$campaignId] ?? '',
                    'ad_id' => '',
                    'adset_id' => '',
                    'zone_id' => '',
                    'amount' => $amount,
                    'currency' => $currency,
                    'date' => $day,
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'platform_clicks' => (int) ($row['clicks'] ?? 0),
                    'raw_json' => json_encode($row),
                ];
            }
            usleep(300000);
        }
        return $records;
    }

    private static function call(string $path, array $credentials, array $query, int $timeout): array
    {
        $raw = self::httpCall($path, $credentials, $query, $timeout);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Adsterra: unreadable response from ' . $path . '.');
        }
        return $decoded;
    }

    private static function httpCall(string $path, array $credentials, array $query, int $timeout): string
    {
        if (self::$testHttpHandler) {
            $handler = self::$testHttpHandler;
            $result = $handler('GET', $path, $query, $credentials);
            if (is_array($result) && array_key_exists('error', $result) && $result['error']) {
                throw new \RuntimeException('Adsterra: ' . $result['error']);
            }
            return (string) ($result['body'] ?? '');
        }

        $ch = curl_init(self::API_BASE . $path . ($query ? '?' . http_build_query($query) : ''));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-API-Key: ' . trim((string) $credentials['api_key']),
            ],
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new \RuntimeException('Adsterra: HTTP transport error ' . $err);
        }
        if ($code === 401 || $code === 403) {
            throw new \RuntimeException("Adsterra: HTTP $code — the API key was rejected (only the newest generated token is valid).");
        }
        if ($code < 200 || $code >= 400) {
            throw new \RuntimeException("Adsterra: HTTP $code: " . substr((string) $resp, 0, 300));
        }
        return (string) $resp;
    }
}
