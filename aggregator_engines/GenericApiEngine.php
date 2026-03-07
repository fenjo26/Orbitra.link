<?php
/**
 * GenericApiEngine — универсальный движок для сбора данных из API партнёрских кабинетов.
 * Пользователь задаёт URL, метод, заголовки авторизации и маппинг полей.
 */

class GenericApiEngine
{

    /**
     * Возвращает список полей, которые нужно заполнить для этого движка.
     */
    public static function getRequiredFields(): array
    {
        return [
            ['key' => 'api_url', 'label' => 'API URL', 'type' => 'text', 'required' => true, 'placeholder' => 'https://api.partner.com/v1/stats'],
            ['key' => 'method', 'label' => 'HTTP Method', 'type' => 'select', 'options' => ['GET', 'POST'], 'required' => true],
            ['key' => 'auth_header', 'label' => 'Authorization Header', 'type' => 'text', 'required' => false, 'placeholder' => 'Bearer YOUR_TOKEN'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => false],
            ['key' => 'api_key_param', 'label' => 'API Key Parameter Name', 'type' => 'text', 'required' => false, 'placeholder' => 'apikey'],
            ['key' => 'extra_headers', 'label' => 'Extra Headers (JSON)', 'type' => 'textarea', 'required' => false, 'placeholder' => '{"X-Custom": "value"}'],
            ['key' => 'date_from_param', 'label' => 'Date From Parameter', 'type' => 'text', 'required' => false, 'placeholder' => 'date_from'],
            ['key' => 'date_to_param', 'label' => 'Date To Parameter', 'type' => 'text', 'required' => false, 'placeholder' => 'date_to'],
            ['key' => 'date_format', 'label' => 'Date Format', 'type' => 'text', 'required' => false, 'placeholder' => 'Y-m-d'],
        ];
    }

    /**
     * Тест подключения — проверяет что API отвечает 200.
     */
    public static function testConnection(array $credentials): array
    {
        try {
            $response = self::makeRequest($credentials, date('Y-m-d'), date('Y-m-d'));
            if ($response['http_code'] >= 200 && $response['http_code'] < 400) {
                return ['success' => true, 'message' => 'Connection successful! HTTP ' . $response['http_code']];
            }
            return ['success' => false, 'message' => 'HTTP Error: ' . $response['http_code'] . ' — ' . substr($response['body'], 0, 300)];
        }
        catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Запрос данных из API за указанный период.
     */
    public static function fetchRecords(array $credentials, string $dateFrom, string $dateTo, array $fieldMapping = []): array
    {
        $response = self::makeRequest($credentials, $dateFrom, $dateTo);

        if ($response['http_code'] < 200 || $response['http_code'] >= 400) {
            throw new \Exception('API returned HTTP ' . $response['http_code']);
        }

        $data = json_decode($response['body'], true);
        if (!$data) {
            throw new \Exception('Failed to parse JSON response');
        }

        // Если ответ — массив, используем его напрямую
        // Если объект с ключом data/results/items — пробуем найти массив
        $records = [];
        if (isset($data[0])) {
            $records = $data;
        }
        elseif (isset($data['data']) && is_array($data['data'])) {
            $records = $data['data'];
        }
        elseif (isset($data['results']) && is_array($data['results'])) {
            $records = $data['results'];
        }
        elseif (isset($data['items']) && is_array($data['items'])) {
            $records = $data['items'];
        }
        elseif (isset($data['rows']) && is_array($data['rows'])) {
            $records = $data['rows'];
        }
        else {
            $records = [$data]; // Wrap single object
        }

        // Применяем маппинг полей
        $mapped = [];
        foreach ($records as $record) {
            $mapped[] = self::mapFields($record, $fieldMapping);
        }

        return $mapped;
    }

    /**
     * HTTP запрос к API партнёрки.
     */
    private static function makeRequest(array $creds, string $dateFrom, string $dateTo): array
    {
        $url = $creds['api_url'] ?? '';
        $method = strtoupper($creds['method'] ?? 'GET');
        $dateFormat = $creds['date_format'] ?? 'Y-m-d';

        // Собираем параметры дат
        $params = [];
        if (!empty($creds['date_from_param'])) {
            $params[$creds['date_from_param']] = date($dateFormat, strtotime($dateFrom));
        }
        if (!empty($creds['date_to_param'])) {
            $params[$creds['date_to_param']] = date($dateFormat, strtotime($dateTo));
        }
        if (!empty($creds['api_key']) && !empty($creds['api_key_param'])) {
            $params[$creds['api_key_param']] = $creds['api_key'];
        }

        // Собираем заголовки
        $headers = ['Accept: application/json', 'User-Agent: Orbitra/0.9.2.9'];
        if (!empty($creds['auth_header'])) {
            $headers[] = 'Authorization: ' . $creds['auth_header'];
        }
        if (!empty($creds['extra_headers'])) {
            $extra = json_decode($creds['extra_headers'], true);
            if (is_array($extra)) {
                foreach ($extra as $k => $v) {
                    $headers[] = "$k: $v";
                }
            }
        }

        $ch = curl_init();
        if ($method === 'GET') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('cURL Error: ' . $error);
        }

        return ['http_code' => $httpCode, 'body' => $body];
    }

    /**
     * Маппинг полей ответа партнёрки в формат revenue_records.
     */
    private static function mapFields(array $record, array $mapping): array
    {
        $defaults = [
            'external_id' => 'id',
            'click_id' => 'click_id',
            'player_id' => 'player_id',
            'event_type' => 'event_type',
            'amount' => 'amount',
            'currency' => 'currency',
            'country' => 'country',
            'brand' => 'brand',
            'sub_id' => 'sub_id',
            'event_date' => 'date',
        ];

        $map = array_merge($defaults, $mapping);
        $result = [];

        foreach ($map as $ourField => $theirField) {
            $result[$ourField] = $record[$theirField] ?? null;
        }

        // Всегда сохраняем оригинальный JSON
        $result['raw_json'] = json_encode($record);

        return $result;
    }
}