<?php
// AffilkaEngine — движок для платформы Affilka / SoftSwiss (используется CatAff и другими).
//
// Affilka API:
//   GET /api/affiliate/statistics — агрегированная статистика
//   GET /api/affiliate/players    — список игроков
//   GET /api/affiliate/commissions — комиссии
//
// Credentials:
//   - api_url: базовый URL партнёрки (напр. https://api.cataff.com)
//   - api_key: API Key или Personal Access Token
//   - affiliate_id: ID аффилейта (если нужен)

class AffilkaEngine
{
    public static function getRequiredFields(): array
    {
        return [
            ['key' => 'api_url', 'label' => 'API Base URL', 'type' => 'text', 'required' => true, 'placeholder' => 'https://api.cataff.com'],
            ['key' => 'api_key', 'label' => 'API Key / PAT', 'type' => 'password', 'required' => true],
            ['key' => 'affiliate_id', 'label' => 'Affiliate ID (optional)', 'type' => 'text', 'required' => false],
            ['key' => 'report_endpoint', 'label' => 'Report Endpoint', 'type' => 'select', 'options' => ['commissions', 'players', 'statistics'], 'required' => true],
        ];
    }

    public static function testConnection(array $credentials): array
    {
        try {
            $baseUrl = rtrim($credentials['api_url'] ?? '', '/');
            $headers = self::buildHeaders($credentials);

            // Пробуем основной endpoint
            $testUrl = $baseUrl . '/api/affiliate/statistics?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d');

            $ch = curl_init($testUrl);
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

            if ($code >= 200 && $code < 400) {
                return ['success' => true, 'message' => "Connected! HTTP $code"];
            }

            // Some Affilka APIs use /v2/ prefix
            $testUrl2 = $baseUrl . '/api/v2/affiliate/statistics?date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d');
            $ch2 = curl_init($testUrl2);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body2 = curl_exec($ch2);
            $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);

            if ($code2 >= 200 && $code2 < 400) {
                return ['success' => true, 'message' => "Connected via v2 API! HTTP $code2"];
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
        $endpoint = $credentials['report_endpoint'] ?? 'commissions';
        $headers = self::buildHeaders($credentials);

        // Строим URL
        $url = $baseUrl . '/api/affiliate/' . $endpoint;
        $params = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'per_page' => 1000,
            'page' => 1,
        ];

        if (!empty($credentials['affiliate_id'])) {
            $params['affiliate_id'] = $credentials['affiliate_id'];
        }

        $allRecords = [];
        $maxPages = 50;

        do {
            $requestUrl = $url . '?' . http_build_query($params);

            $ch = curl_init($requestUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
            ]);

            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new \Exception('Affilka cURL: ' . $error);
            }
            if ($httpCode < 200 || $httpCode >= 400) {
                throw new \Exception('Affilka HTTP ' . $httpCode . ': ' . substr($body, 0, 300));
            }

            $data = json_decode($body, true);
            if (!$data) {
                throw new \Exception('Affilka: failed to parse JSON');
            }

            // Affilka может возвращать данные в разных обёртках
            $rows = $data['data'] ?? $data['items'] ?? $data['results'] ?? $data['records'] ?? [];
            if (empty($rows) && isset($data[0])) {
                $rows = $data;
            }
            if (empty($rows))
                break;

            foreach ($rows as $row) {
                $allRecords[] = self::mapRecord($row, $fieldMapping);
            }

            // Pagination
            $params['page']++;
            $totalPages = $data['last_page'] ?? $data['total_pages'] ?? $data['pages'] ?? 1;
            $hasMore = $params['page'] <= $totalPages;

        } while ($hasMore && $params['page'] <= $maxPages);

        return $allRecords;
    }

    private static function buildHeaders(array $credentials): array
    {
        $headers = [
            'Accept: application/json',
            'User-Agent: Orbitra/0.9.2.3',
        ];

        $apiKey = $credentials['api_key'] ?? '';
        if ($apiKey) {
            // Affilka обычно использует Bearer или X-Api-Key
            $headers[] = 'Authorization: Bearer ' . $apiKey;
            $headers[] = 'X-Api-Key: ' . $apiKey;
        }

        return $headers;
    }

    private static function mapRecord(array $row, array $mapping): array
    {
        // Affilka / SoftSwiss стандартные поля
        $defaults = [
            'external_id' => 'id',
            'click_id' => 'tracker_id', // или sub_id / btag
            'player_id' => 'player_id',
            'event_type' => 'type',
            'amount' => 'commission_amount', // или revenue, amount
            'currency' => 'currency',
            'country' => 'country',
            'brand' => 'brand',
            'sub_id' => 'btag',
            'event_date' => 'date',
        ];

        $map = array_merge($defaults, $mapping);
        $result = [];

        foreach ($map as $ourField => $theirField) {
            $result[$ourField] = $row[$theirField] ?? null;
        }

        // Fallback для click_id
        if (empty($result['click_id'])) {
            foreach (['tracker_id', 'sub_id', 'btag', 'click_id', 'sub1', 'tag', 'tracking_code'] as $f) {
                if (!empty($row[$f])) {
                    $result['click_id'] = $row[$f];
                    break;
                }
            }
        }

        // Fallback для amount
        if (empty($result['amount']) || (float)$result['amount'] == 0) {
            foreach (['commission_amount', 'commission', 'revenue', 'amount', 'payout', 'net_revenue'] as $af) {
                if (isset($row[$af]) && (float)$row[$af] > 0) {
                    $result['amount'] = (float)$row[$af];
                    break;
                }
            }
        }

        // Fallback для event_type
        if (empty($result['event_type'])) {
            if (isset($row['is_first_deposit']) && $row['is_first_deposit']) {
                $result['event_type'] = 'ftd';
            }
            else {
                $result['event_type'] = 'commission';
            }
        }

        $result['raw_json'] = json_encode($row);
        return $result;
    }
}