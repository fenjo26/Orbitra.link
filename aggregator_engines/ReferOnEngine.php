<?php
// ReferOnEngine — движок для платформы ReferOn (используется BroPartners и другими).
//
// ReferOn API предоставляет REST-endpoints для получения статистики:
//   POST /api/v1/reports/players — список игроков с доходами
//   POST /api/v1/reports/conversions — конверсии (FTD, deposits)
//
// Credentials:
//   - api_url: базовый URL (напр. https://api.bropartners.com)
//   - api_key: Personal Access Token (выдаётся в Settings → API)
//   - brand_id: ID бренда (если несколько)

class ReferOnEngine
{
    public static function getRequiredFields(): array
    {
        return [
            ['key' => 'api_url', 'label' => 'API Base URL', 'type' => 'text', 'required' => true, 'placeholder' => 'https://api.bropartners.com'],
            ['key' => 'api_key', 'label' => 'API Token (Personal Access Token)', 'type' => 'password', 'required' => true],
            ['key' => 'brand_id', 'label' => 'Brand ID (optional)', 'type' => 'text', 'required' => false, 'placeholder' => ''],
            ['key' => 'report_type', 'label' => 'Report Type', 'type' => 'select', 'options' => ['players', 'conversions', 'commissions'], 'required' => true],
        ];
    }

    public static function testConnection(array $credentials): array
    {
        try {
            $url = rtrim($credentials['api_url'] ?? '', '/') . '/api/v1/ping';
            $headers = self::buildHeaders($credentials);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ['success' => false, 'message' => 'cURL: ' . $error];
            }

            // Некоторые ReferOn API возвращают 200 на /ping, некоторые — 404, пробуем /reports
            if ($code >= 200 && $code < 400) {
                return ['success' => true, 'message' => "Connected! HTTP $code"];
            }

            // Пробуем endpoint reports вместо ping
            $url2 = rtrim($credentials['api_url'] ?? '', '/') . '/api/v1/reports/conversions';
            $ch2 = curl_init($url2);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d'), 'limit' => 1]),
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch2, CURLOPT_HTTPHEADER, $headers);
            $body2 = curl_exec($ch2);
            $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);

            if ($code2 >= 200 && $code2 < 400) {
                return ['success' => true, 'message' => "Connected via reports endpoint! HTTP $code2"];
            }

            return ['success' => false, 'message' => "HTTP $code — " . substr($body, 0, 200)];
        }
        catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function fetchRecords(array $credentials, string $dateFrom, string $dateTo, array $fieldMapping = []): array
    {
        $baseUrl = rtrim($credentials['api_url'] ?? '', '/');
        $reportType = $credentials['report_type'] ?? 'conversions';
        $headers = self::buildHeaders($credentials);
        $headers[] = 'Content-Type: application/json';

        $endpoint = $baseUrl . '/api/v1/reports/' . $reportType;

        $payload = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'limit' => 5000, // max per request
            'offset' => 0,
        ];

        if (!empty($credentials['brand_id'])) {
            $payload['brand_id'] = $credentials['brand_id'];
        }

        $allRecords = [];
        $page = 0;
        $maxPages = 20; // safety limit

        do {
            $payload['offset'] = $page * 5000;

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
            ]);

            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new \Exception('ReferOn cURL: ' . $error);
            }
            if ($httpCode < 200 || $httpCode >= 400) {
                throw new \Exception('ReferOn HTTP ' . $httpCode . ': ' . substr($body, 0, 300));
            }

            $data = json_decode($body, true);
            if (!$data) {
                throw new \Exception('ReferOn: failed to parse JSON');
            }

            $rows = $data['data'] ?? $data['items'] ?? $data['results'] ?? [];
            if (empty($rows))
                break;

            foreach ($rows as $row) {
                $allRecords[] = self::mapRecord($row, $fieldMapping, $credentials);
            }

            $page++;
            $total = $data['total'] ?? $data['count'] ?? count($rows);
            $hasMore = ($page * 5000) < $total;

        } while ($hasMore && $page < $maxPages);

        return $allRecords;
    }

    private static function buildHeaders(array $credentials): array
    {
        $headers = [
            'Accept: application/json',
            'User-Agent: Orbitra/0.9.2.2',
        ];

        $token = $credentials['api_key'] ?? '';
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        return $headers;
    }

    private static function mapRecord(array $row, array $mapping, array $credentials): array
    {
        // ReferOn стандартные поля
        $defaults = [
            'external_id' => 'id',
            'click_id' => 'sub_id', // обычно click_id в sub_id или sub1
            'player_id' => 'player_id',
            'event_type' => 'type', // ftd, deposit, commission
            'amount' => 'commission', // или revenue/amount
            'currency' => 'currency',
            'country' => 'country',
            'brand' => 'brand_name',
            'sub_id' => 'sub1',
            'event_date' => 'created_at',
        ];

        // Альтернативные fallback-маппинги для amount
        $amountFields = ['commission', 'revenue', 'amount', 'payout', 'profit'];

        $map = array_merge($defaults, $mapping);
        $result = [];

        foreach ($map as $ourField => $theirField) {
            $result[$ourField] = $row[$theirField] ?? null;
        }

        // Fallback для amount — ищем первое непустое поле
        if (empty($result['amount'])) {
            foreach ($amountFields as $af) {
                if (isset($row[$af]) && (float)$row[$af] > 0) {
                    $result['amount'] = (float)$row[$af];
                    break;
                }
            }
        }

        // Fallback для click_id — проверяем sub1..sub5
        if (empty($result['click_id'])) {
            foreach (['sub_id', 'sub1', 'sub2', 'sub3', 'sub4', 'sub5', 'click_id', 'tracker_id'] as $subField) {
                if (!empty($row[$subField])) {
                    $result['click_id'] = $row[$subField];
                    break;
                }
            }
        }

        $result['raw_json'] = json_encode($row);
        return $result;
    }
}