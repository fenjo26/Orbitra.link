<?php
/**
 * TikTokAdsEngine — импорт рекламного расхода из TikTok Ads.
 *
 * Cost-shaped engine: возвращает записи {amount, currency, source_campaign_id,
 * ad_id, adset_id, date}, которые aggregator_cron.php и api.php записывают в
 * cost_records через core/CostImporter.php и распределяют по кликам по
 * ad_id / adset_id / campaign_id — тем ключам, что проставляет шаблон
 * источника трафика TikTok (__CID__, __AID__, __CAMPAIGN_ID__).
 *
 * Авторизация: Access Token из TikTok Ads Manager (Settings → API →
 * System API access token, или токен из личного кабинета разработчика).
 * Advertiser ID — id рекламного кабинета (Ads Manager → аккаунт).
 *
 * API: Business API v1.3 /report/integrated/get/ (POST, JSON body),
 * data_level=AUCTION_AD, dimensions ad_id + stat_time_day, metrics spend.
 * Валюты в отчёте нет — она читается отдельным запросом /advertiser/info/.
 */

class TikTokAdsEngine
{
    private const API_BASE = 'https://business-api.tiktok.com/open_api/v1.3';
    private const PAGE_SIZE = 200;
    private const MAX_PAGES = 100;

    /**
     * Поля формы подключения. `label_key` — ключ локализации, `label` — английский
     * фолбэк: фронтенд рендерит t(field.label_key, field.label).
     */
    public static function getRequiredFields(): array
    {
        return [
            ['key' => 'access_token', 'label_key' => 'costImport.fields.ttToken', 'label' => 'Access Token', 'type' => 'password', 'required' => true, 'placeholder' => '9c1f...'],
            ['key' => 'advertiser_id', 'label_key' => 'costImport.fields.ttAdvertiser', 'label' => 'Advertiser ID', 'type' => 'text', 'required' => true, 'placeholder' => '1234567890'],
            ['key' => 'proxy_url', 'label_key' => 'costImport.fields.proxy', 'label' => 'Proxy (optional) — scheme://user:pass@host:port', 'type' => 'text', 'required' => false, 'placeholder' => 'http://user:pass@1.2.3.4:8080'],
        ];
    }

    public static function testConnection(array $credentials): array
    {
        try {
            $missing = self::validateCredentials($credentials);
            if ($missing) {
                return ['success' => false, 'message' => 'Missing: ' . implode(', ', $missing)];
            }

            // Читаем сам кабинет, а не отчёт: пустой отчёт за сегодня — тоже
            // валидный ответ, и по нему нельзя отличить живой токен от мёртвого.
            $info = self::advertiserInfo($credentials);
            if (isset($info['error'])) {
                return ['success' => false, 'message' => $info['error']];
            }

            $name = $info['data']['name'] ?? '';
            $currency = $info['data']['currency'] ?? '?';
            $label = $name !== '' ? "\"$name\"" : 'advertiser ' . self::advertiserId($credentials);

            return [
                'success' => true,
                'message' => "Connected to $label — currency $currency.",
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Дневной расход в разбивке ad / adgroup / campaign за период.
     *
     * @throws RuntimeException при ошибке API — вызывающий код обязан записать это
     *         в last_sync_error. Пустой массив на ошибке выглядит в UI как
     *         «успешно, 0 записей» и прячет протухший токен.
     */
    public static function fetchRecords(array $credentials, string $dateFrom, string $dateTo, array $fieldMapping = []): array
    {
        $missing = self::validateCredentials($credentials);
        if ($missing) {
            throw new \RuntimeException('TikTok: ' . implode(', ', $missing) . ' are required.');
        }

        // В отчёте валюты нет — берём её из профиля кабинета один раз на синк.
        $info = self::advertiserInfo($credentials);
        if (isset($info['error'])) {
            throw new \RuntimeException('TikTok: ' . $info['error']);
        }
        $currency = strtoupper((string) ($info['data']['currency'] ?? 'USD'));
        if ($currency === '') {
            $currency = 'USD';
        }

        $records = [];
        $page = 1;

        while ($page <= self::MAX_PAGES) {
            $payload = [
                'advertiser_id' => self::advertiserId($credentials),
                'report_type'   => 'BASIC',
                'data_level'    => 'AUCTION_AD',
                'dimensions'    => ['ad_id', 'stat_time_day'],
                'metrics'       => ['spend', 'campaign_id', 'adgroup_id', 'campaign_name'],
                'start_date'    => $dateFrom,
                'end_date'      => $dateTo,
                'page'          => $page,
                'page_size'     => self::PAGE_SIZE,
            ];

            $response = self::httpJson(self::API_BASE . '/report/integrated/get/', $credentials, $payload, 45);
            if ($response['error'] !== null) {
                throw new \RuntimeException('TikTok: ' . $response['error']);
            }

            $decoded = json_decode($response['body'], true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('TikTok: unreadable API response.');
            }
            if ((int) ($decoded['code'] ?? -1) !== 0) {
                throw new \RuntimeException('TikTok: ' . (string) ($decoded['message'] ?? 'API error') . ' (code ' . $decoded['code'] . ')');
            }

            $list = $decoded['data']['list'] ?? [];
            foreach ($list as $row) {
                $dims = $row['dimensions'] ?? [];
                $metrics = $row['metrics'] ?? [];

                // stat_time_day приходит как "2026-08-10 00:00:00" — оставляем дату.
                $date = substr((string) ($dims['stat_time_day'] ?? ''), 0, 10);
                if ($date === '') {
                    continue;
                }

                $adId = (string) ($dims['ad_id'] ?? '');
                $adsetId = (string) ($metrics['adgroup_id'] ?? '');
                $campaignId = (string) ($metrics['campaign_id'] ?? '');

                $records[] = [
                    // Ключ идемпотентности: одна строка на (объявление, день).
                    'external_id'        => 'tt_' . $campaignId . '_' . $adId . '_' . $date,
                    'source_campaign_id' => $campaignId,
                    'ad_id'              => $adId,
                    'adset_id'           => $adsetId,
                    'amount'             => (float) ($metrics['spend'] ?? 0),
                    'currency'           => $currency,
                    'date'               => $date,
                    'raw_json'           => json_encode($row),
                ];
            }

            $totalPage = (int) ($decoded['data']['page_info']['total_page'] ?? 1);
            if ($page >= $totalPage) {
                break;
            }
            $page++;
        }

        return $records;
    }

    /** @return string[] список отсутствующих обязательных полей */
    private static function validateCredentials(array $credentials): array
    {
        $missing = [];
        foreach (['access_token', 'advertiser_id'] as $field) {
            if (trim((string) ($credentials[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    private static function advertiserId(array $credentials): string
    {
        // Пользователи иногда копируют id с пробелами или ведущими нулями-кавычками.
        return preg_replace('/[^0-9]/', '', (string) ($credentials['advertiser_id'] ?? ''));
    }

    /**
     * GET /advertiser/info/ — имя и валюта кабинета; лёгкий запрос для
     * проверки токена и источник валюты для fetchRecords().
     * @return array{data:array,error:?string}
     */
    private static function advertiserInfo(array $credentials): array
    {
        $url = self::API_BASE . '/advertiser/info/?advertiser_id=' . urlencode(self::advertiserId($credentials));
        $response = self::httpGet($url, $credentials, 15);
        if ($response['error'] !== null) {
            return ['data' => [], 'error' => $response['error']];
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            return ['data' => [], 'error' => 'Unreadable advertiser info response.'];
        }
        if ((int) ($decoded['code'] ?? -1) !== 0) {
            return ['data' => [], 'error' => (string) ($decoded['message'] ?? 'API error') . ' (code ' . $decoded['code'] . ')'];
        }
        if (empty($decoded['data'])) {
            return ['data' => [], 'error' => 'Advertiser not found — check the Advertiser ID.'];
        }

        return ['data' => $decoded['data'], 'error' => null];
    }

    /**
     * GET с заголовком Access-Token и опциональным прокси.
     * @return array{body:string,error:?string}
     */
    private static function httpGet(string $url, array $credentials, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Access-Token: ' . trim((string) ($credentials['access_token'] ?? '')),
        ]);
        self::applyProxy($ch, $credentials);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        return self::interpretResponse($body, $code, $curlErr);
    }

    /**
     * POST JSON с заголовком Access-Token и опциональным прокси.
     * @return array{body:string,error:?string}
     */
    private static function httpJson(string $url, array $credentials, array $payload, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Access-Token: ' . trim((string) ($credentials['access_token'] ?? '')),
        ]);
        self::applyProxy($ch, $credentials);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        return self::interpretResponse($body, $code, $curlErr);
    }

    /** Общая интерпретация ответа: транспорт, HTTP-код, тело TikTok API. */
    private static function interpretResponse($body, int $code, string $curlErr): array
    {
        if ($curlErr !== '') {
            return ['body' => '', 'error' => 'HTTP transport error: ' . $curlErr];
        }
        if (!is_string($body)) {
            return ['body' => '', 'error' => 'Empty response from TikTok API.'];
        }
        if ($code < 200 || $code >= 300) {
            $decoded = json_decode($body, true);
            $msg = is_array($decoded) ? (string) ($decoded['message'] ?? '') : '';
            if ($msg !== '') {
                return ['body' => '', 'error' => "HTTP $code: $msg"];
            }
            return ['body' => '', 'error' => "HTTP $code: " . substr($body, 0, 300)];
        }

        return ['body' => $body, 'error' => null];
    }

    /** @param resource|\CurlHandle $ch */
    private static function applyProxy($ch, array $credentials): void
    {
        $proxy = trim((string) ($credentials['proxy_url'] ?? ''));
        if ($proxy === '') {
            return;
        }

        $parts = parse_url($proxy);
        if (!is_array($parts) || empty($parts['host'])) {
            return;
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $type = CURLPROXY_HTTP;
        if ($scheme === 'socks5' || $scheme === 'socks5h') {
            $type = CURLPROXY_SOCKS5_HOSTNAME;
        } elseif ($scheme === 'socks4') {
            $type = CURLPROXY_SOCKS4;
        }

        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        curl_setopt($ch, CURLOPT_PROXY, $host);
        curl_setopt($ch, CURLOPT_PROXYTYPE, $type);

        if (isset($parts['user'])) {
            $auth = urldecode($parts['user']) . ':' . urldecode($parts['pass'] ?? '');
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $auth);
        }
    }
}
