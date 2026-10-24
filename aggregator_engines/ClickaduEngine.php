<?php
// aggregator_engines/ClickaduEngine.php
//
// Clickadu (clickadu.com) advertiser spend, per campaign per day.
// API shapes from a live account (probe + sync 2026-10-12):
//
//   GET /api/v2/client/balance/                      → {result: {advertiser: {balance: "367.16"}}}
//   GET /v1.0/api/client/statistics/?dateFrom&dateTill&groupBy=campaignId
//        → {result: {page, items: [ {campaignId, campaignName, spent, impressions, clicks} ]}}
//   groupBy=zone answers too, but its rows carry no campaign id and the
//   campaign filter is ignored — v1 imports campaign-level spend (zone_id ''),
//   spread over the campaign's clicks.
//
// Auth: `Authorization: <key>` — the RAW key, no "Bearer" prefix (per the
// official docs). Errors can arrive as HTTP 200 with an `error`/`errors` body.

class ClickaduEngine
{
    private const API_BASE = 'https://ssp.clickadu.com';

    /** Tests replace this to fake the network (see tests/push_cost_engines_more_test.php). */
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
            if (trim((string) ($credentials['api_key'] ?? '')) === '') {
                return ['success' => false, 'message' => 'Missing: api_key'];
            }
            $decoded = self::call('/api/v2/client/balance/', $credentials, [], 15);
            $balance = round((float) ($decoded['result']['advertiser']['balance'] ?? 0), 2);
            return ['success' => true, 'message' => 'Clickadu OK — balance ' . $balance];
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
            throw new \RuntimeException('Clickadu: api_key required.');
        }
        $currency = strtoupper(trim((string) ($credentials['currency'] ?? ''))) ?: 'USD';

        $records = [];
        $from = strtotime($dateFrom . ' UTC');
        $to = min(strtotime($dateTo . ' UTC'), $from + 366 * 86400);
        for ($d = $from; $d <= $to; $d += 86400) {
            $day = gmdate('Y-m-d', $d);
            $decoded = self::call('/v1.0/api/client/statistics/', $credentials, [
                'dateFrom' => $day,
                'dateTill' => $day,
                'groupBy' => 'campaignId',
            ], 40);
            $items = $decoded['result']['items'] ?? [];
            foreach (is_array($items) ? $items : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $campaignId = (string) ($row['campaignId'] ?? '');
                $amount = (float) ($row['spent'] ?? 0);
                if ($campaignId === '' || $amount <= 0) {
                    continue;
                }
                $records[] = [
                    'external_id' => 'ca_' . $campaignId . '_' . $day,
                    'source_campaign_id' => $campaignId,
                    'campaign_name' => (string) ($row['campaignName'] ?? ''),
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
            usleep(500000);
        }
        return $records;
    }

    private static function call(string $path, array $credentials, array $query, int $timeout): array
    {
        $raw = self::httpCall($path, $credentials, $query, $timeout);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Clickadu: unreadable response from ' . $path . '.');
        }
        $error = $decoded['error'] ?? ($decoded['errors'] ?? null);
        if ($error && !isset($decoded['result'])) {
            $text = is_array($error) ? json_encode($error, JSON_UNESCAPED_UNICODE) : (string) $error;
            throw new \RuntimeException('Clickadu: ' . $text);
        }
        return $decoded;
    }

    private static function httpCall(string $path, array $credentials, array $query, int $timeout): string
    {
        if (self::$testHttpHandler) {
            $handler = self::$testHttpHandler;
            $result = $handler('GET', $path, $query, $credentials);
            if (is_array($result) && array_key_exists('error', $result) && $result['error']) {
                throw new \RuntimeException('Clickadu: ' . $result['error']);
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
                // Raw key — Clickadu rejects "Bearer <key>".
                'Authorization: ' . trim((string) $credentials['api_key']),
            ],
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new \RuntimeException('Clickadu: HTTP transport error ' . $err);
        }
        if ($code === 401 || $code === 403) {
            throw new \RuntimeException("Clickadu: HTTP $code — the API key was rejected.");
        }
        if ($code < 200 || $code >= 400) {
            throw new \RuntimeException("Clickadu: HTTP $code: " . substr((string) $resp, 0, 300));
        }
        return (string) $resp;
    }
}
