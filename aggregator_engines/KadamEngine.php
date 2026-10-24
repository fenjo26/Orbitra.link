<?php
// aggregator_engines/KadamEngine.php
//
// Kadam (kadam.net) advertiser spend, reported per campaign × zone (site) × day.
// API shapes from the live OpenAPI (partners.kadam.net/api-doc/openapi.json)
// and a working sync (probe + sync 2026-10-12):
//
//   GET  /me                → {success, data: {id, balance, currency, timezone}}
//   POST /campaigns         {page, perPage}
//                           → {success, data: {rows: [ {campaign: {id, name, state}} ], totalRows}}
//   POST /stats/sites       {page, perPage, filters: {campaignIds: [id], dateFrom, dateTo, timezone}}
//                           → {success, data: {rows: [ {macrosId, spending: "$1.23", views, clicks} ],
//                                               totalRows, total: {spending, …}}}
//        macrosId is the zone id — the same value Kadam's zone block endpoint
//        (POST /stats/sites/block) takes, and what the {site_id} macro of the
//        Kadam source template puts into `source` on the click.
//        Money and counters come back as display strings ("$1 234.50").
//        `timezone` is an hour offset; it is taken from the connection's IANA
//        timezone for each day, so DST days stay right.
//
// ⚠ Auth is `Authorization: Bearer <token>`, and Cloudflare in front of the API
//   rejects default library user agents (error 1010) — always send our own UA.
// ⚠ Errors can come back as HTTP 200 with {success: false, msg: …}.
//
// Zone blocking (Zone Killer):
//   POST /stats/sites/block    {campaignIds: [id], zones: [macrosId, …]}
//   POST /stats/sites/unblock  same body
//   Endpoint + auth verified live (a fake campaign id is rejected at the app
//   level with 422 "Unknown campaign", i.e. the route and token are right).

class KadamEngine
{
    private const API_BASE = 'https://partners.kadam.net/api/v1';
    private const USER_AGENT = 'Orbitra-Tracker/1.0 (+cost-import)';
    private const PER_PAGE = 1000;

    /** Tests replace this to fake the network (see tests/push_cost_engines_more_test.php). */
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
            if (trim((string) ($credentials['api_token'] ?? '')) === '') {
                return ['success' => false, 'message' => 'Missing: api_token'];
            }
            $decoded = self::call('GET', '/me', $credentials, null, 15);
            $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
            $balance = round((float) ($data['balance'] ?? 0), 2);
            $cur = strtoupper((string) ($data['currency'] ?? ''));
            return ['success' => true, 'message' => 'Kadam OK — balance ' . $balance . ($cur !== '' ? ' ' . $cur : '')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Per-day, per-campaign, per-zone spend. Campaigns without spend over the
     * whole window are skipped after one cheap totals request each.
     *
     * @throws RuntimeException on auth/transport errors.
     */
    public static function fetchRecords(array $credentials, string $dateFrom, string $dateTo, array $fieldMapping = []): array
    {
        if (trim((string) ($credentials['api_token'] ?? '')) === '') {
            throw new \RuntimeException('Kadam: api_token required.');
        }
        $currency = strtoupper(trim((string) ($credentials['currency'] ?? ''))) ?: 'USD';
        $tz = self::timezone($credentials);

        $from = strtotime($dateFrom . ' UTC');
        $to = min(strtotime($dateTo . ' UTC'), $from + 366 * 86400);
        $windowFrom = gmdate('Y-m-d', $from);
        $windowTo = gmdate('Y-m-d', $to);

        $records = [];
        foreach (self::fetchCampaigns($credentials) as $campaignId => $campaignName) {
            // Whole-window total first: idle campaigns cost one request, not N days.
            $total = self::call('POST', '/stats/sites', $credentials, [
                'page' => 1, 'perPage' => 1,
                'filters' => ['campaignIds' => [(int) $campaignId], 'dateFrom' => $windowFrom, 'dateTo' => $windowTo, 'timezone' => self::offsetHours($tz, $windowTo)],
            ], 40);
            $totalSpend = $total['data']['total']['spending'] ?? null;
            if ($totalSpend !== null && self::money($totalSpend) <= 0) {
                continue;
            }

            for ($d = $from; $d <= $to; $d += 86400) {
                $day = gmdate('Y-m-d', $d);
                $page = 1;
                do {
                    $decoded = self::call('POST', '/stats/sites', $credentials, [
                        'page' => $page, 'perPage' => self::PER_PAGE,
                        'filters' => ['campaignIds' => [(int) $campaignId], 'dateFrom' => $day, 'dateTo' => $day, 'timezone' => self::offsetHours($tz, $day)],
                    ], 40);
                    $rows = $decoded['data']['rows'] ?? [];
                    $rows = is_array($rows) ? $rows : [];
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $zoneId = trim((string) ($row['macrosId'] ?? ($row['site'] ?? '')));
                        $amount = self::money($row['spending'] ?? 0);
                        if ($zoneId === '' || $amount <= 0) {
                            continue;
                        }
                        $records[] = [
                            'external_id' => 'kd_' . $campaignId . '_' . $zoneId . '_' . $day,
                            'source_campaign_id' => (string) $campaignId,
                            'campaign_name' => $campaignName,
                            'ad_id' => '',
                            'adset_id' => '',
                            'zone_id' => $zoneId,
                            'amount' => $amount,
                            'currency' => $currency,
                            'date' => $day,
                            'impressions' => self::count($row['views'] ?? 0),
                            'platform_clicks' => self::count($row['clicks'] ?? 0),
                            'raw_json' => json_encode($row),
                        ];
                    }
                    $totalRows = (int) ($decoded['data']['totalRows'] ?? 0);
                    $more = count($rows) === self::PER_PAGE && $page * self::PER_PAGE < $totalRows;
                    $page++;
                } while ($more && $page <= 50);
                usleep(300000);
            }
        }
        return $records;
    }

    /**
     * Block / unblock zones (macrosId values — what the {site_id} macro puts
     * into `source` on the click) on one Kadam campaign. One request for the
     * whole list; Kadam answers success=false with a reason on bad input.
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
                return ['ok' => false, 'error' => "Kadam campaign id must be numeric, got '$networkCampaignId'"];
            }
            self::call('POST', $block ? '/stats/sites/block' : '/stats/sites/unblock', $credentials, [
                'campaignIds' => [(int) $networkCampaignId],
                'zones' => $zones,
            ], 30);
            return ['ok' => true, 'applied' => count($zones)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string,string> campaign id => name */
    private static function fetchCampaigns(array $credentials): array
    {
        $campaigns = [];
        for ($page = 1; $page <= 50; $page++) {
            $decoded = self::call('POST', '/campaigns', $credentials, ['page' => $page, 'perPage' => 200], 30);
            $rows = $decoded['data']['rows'] ?? [];
            if (!is_array($rows) || $rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $c = is_array($row['campaign'] ?? null) ? $row['campaign'] : $row;
                $id = (string) ($c['id'] ?? '');
                if ($id !== '' && $id !== '0') {
                    $campaigns[$id] = (string) ($c['name'] ?? '');
                }
            }
            if (count($rows) < 200) {
                break;
            }
        }
        return $campaigns;
    }

    /** "$1 234.50" / "1,234.50" / 1.5 → float. */
    private static function money($value): float
    {
        $s = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $value));
        return is_numeric($s) ? (float) $s : 0.0;
    }

    private static function count($value): int
    {
        return (int) preg_replace('/[^0-9]/', '', (string) $value);
    }

    private static function timezone(array $credentials): \DateTimeZone
    {
        $name = trim((string) ($credentials['timezone'] ?? ''));
        try {
            return new \DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    /** Whole-hour UTC offset of the account timezone on that day (Kadam takes an int). */
    private static function offsetHours(\DateTimeZone $tz, string $day): int
    {
        $at = new \DateTimeImmutable($day . ' 12:00:00', new \DateTimeZone('UTC'));
        return (int) round($tz->getOffset($at) / 3600);
    }

    /** JSON call; HTTP 200 + success=false becomes an exception. */
    private static function call(string $method, string $path, array $credentials, ?array $body, int $timeout): array
    {
        $raw = self::httpCall($method, $path, $credentials, $body, $timeout);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Kadam: unreadable response from ' . $path . '.');
        }
        if (array_key_exists('success', $decoded) && $decoded['success'] === false) {
            $msg = $decoded['msg'] ?? ($decoded['message'] ?? 'request failed');
            if (is_array($msg)) {
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
            throw new \RuntimeException('Kadam: ' . $msg);
        }
        return $decoded;
    }

    private static function httpCall(string $method, string $path, array $credentials, ?array $body, int $timeout): string
    {
        if (self::$testHttpHandler) {
            $handler = self::$testHttpHandler;
            $result = $handler($method, $path, $body ?? [], $credentials);
            if (is_array($result) && array_key_exists('error', $result) && $result['error']) {
                throw new \RuntimeException('Kadam: ' . $result['error']);
            }
            return (string) ($result['body'] ?? '');
        }

        $ch = curl_init(self::API_BASE . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . trim((string) $credentials['api_token']),
            ],
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? new \stdClass());
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new \RuntimeException('Kadam: HTTP transport error ' . $err);
        }
        if ($code === 401 || $code === 403) {
            throw new \RuntimeException("Kadam: HTTP $code — the API token was rejected.");
        }
        if ($code < 200 || $code >= 400) {
            throw new \RuntimeException("Kadam: HTTP $code: " . substr((string) $resp, 0, 300));
        }
        return (string) $resp;
    }
}
