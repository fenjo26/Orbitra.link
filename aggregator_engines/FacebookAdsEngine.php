<?php
/**
 * FacebookAdsEngine — импорт рекламного расхода из Facebook / Meta Ads.
 *
 * Cost-shaped engine: возвращает записи {amount, currency, campaign_id, ad_id, adset_id, date},
 * которые aggregator_cron.php и api.php записывают в cost_records через core/CostImporter.php
 * и распределяют по кликам по ad_id / adset_id / campaign_id — тем ключам, что проставляет
 * шаблон источника трафика Facebook.
 *
 * Авторизация: long-lived User Access Token или System User Token (поле token).
 * Ad account id передаётся с префиксом act_ (например act_1234567890).
 *
 * API: Marketing API /<version>/act_<id>/insights, level=ad, time_increment=1,
 * поля spend, campaign_id, adset_id, ad_id, account_currency, date_start.
 *
 * ВАЖНО про поля: у insights нет поля `currency` — валюта кабинета называется
 * `account_currency`. Запрос с несуществующим полем Facebook отклоняет целиком
 * (error 100), то есть один неверный идентификатор поля обнуляет весь импорт.
 */

class FacebookAdsEngine
{
    /** Версия по умолчанию для новых подключений; у сохранённых берётся из credentials. */
    private const DEFAULT_API_VERSION = 'v25.0';
    private const API_BASE = 'https://graph.facebook.com/';
    private const PAGE_LIMIT = 500;
    private const MAX_PAGES = 200;

    /** Поддерживаемые версии — Facebook держит версию живой примерно два года. */
    private const API_VERSIONS = ['v25.0', 'v24.0', 'v23.0', 'v22.0', 'v21.0'];

    /**
     * Поля формы подключения. `label_key` — ключ локализации, `label` — английский
     * фолбэк: фронтенд рендерит t(field.label_key, field.label), поэтому на языке
     * без перевода видно нормальную строку, а не сырой ключ.
     */
    public static function getRequiredFields(): array
    {
        return [
            ['key' => 'token', 'label_key' => 'costImport.fields.fbToken', 'label' => 'Access Token (long-lived / system user)', 'type' => 'password', 'required' => true, 'placeholder' => 'EAAG...'],
            ['key' => 'ad_account_id', 'label_key' => 'costImport.fields.fbAdAccount', 'label' => 'Ad Account ID', 'type' => 'text', 'required' => true, 'placeholder' => 'act_1234567890'],
            ['key' => 'api_version', 'label_key' => 'costImport.fields.fbApiVersion', 'label' => 'Facebook API version', 'type' => 'select', 'required' => false, 'options' => self::API_VERSIONS],
            ['key' => 'proxy_url', 'label_key' => 'costImport.fields.proxy', 'label' => 'Proxy (optional) — scheme://user:pass@host:port', 'type' => 'text', 'required' => false, 'placeholder' => 'http://user:pass@1.2.3.4:8080'],
            ['key' => 'app_id', 'label_key' => 'costImport.fields.fbAppId', 'label' => 'App ID (optional, for some tokens)', 'type' => 'text', 'required' => false],
            ['key' => 'app_secret', 'label_key' => 'costImport.fields.fbAppSecret', 'label' => 'App Secret (optional)', 'type' => 'password', 'required' => false],
        ];
    }

    public static function testConnection(array $credentials): array
    {
        try {
            $token = trim((string) ($credentials['token'] ?? ''));
            $accountId = self::normalizeAccountId((string) ($credentials['ad_account_id'] ?? ''));
            if ($token === '' || $accountId === '') {
                return ['success' => false, 'message' => 'Access Token and Ad Account ID are required.'];
            }

            // Читаем сам кабинет, а не insights: пустой отчёт за сегодня — это тоже
            // валидный ответ, и по нему нельзя отличить рабочий токен от нерабочего.
            // /act_<id>?fields=name,currency,account_status отвечает всегда.
            $url = self::apiBase($credentials) . '/' . $accountId . '?' . http_build_query([
                'access_token' => $token,
                'fields'       => 'name,currency,account_status,timezone_name',
            ]);

            $response = self::httpGet($url, $credentials, 15);
            if ($response['error'] !== null) {
                return ['success' => false, 'message' => $response['error']];
            }

            $account = json_decode($response['body'], true);
            $name = $account['name'] ?? $accountId;
            $currency = $account['currency'] ?? '?';
            $tz = $account['timezone_name'] ?? '?';
            $statusCode = (int) ($account['account_status'] ?? 0);

            // 1 = ACTIVE, 2 = DISABLED, 3 = UNSETTLED, 7 = PENDING_RISK_REVIEW,
            // 8 = PENDING_SETTLEMENT, 9 = IN_GRACE_PERIOD, 100 = PENDING_CLOSURE,
            // 101 = CLOSED, 201 = ANY_ACTIVE, 202 = ANY_CLOSED.
            if ($statusCode === 2 || $statusCode === 100 || $statusCode === 101) {
                return [
                    'success' => false,
                    'message' => "Account \"$name\" is reachable but disabled/closed (account_status=$statusCode). Spend cannot be imported.",
                ];
            }

            return [
                'success' => true,
                'message' => "Connected to \"$name\" — currency $currency, timezone $tz.",
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Дневной расход в разбивке campaign / adset / ad за период.
     *
     * @throws RuntimeException при ошибке API — вызывающий код обязан записать это
     *         в last_sync_error. Возврат пустого массива на ошибке (как было раньше)
     *         выглядит в UI как «успешно, 0 записей» и прячет протухший токен.
     */
    public static function fetchRecords(array $credentials, string $dateFrom, string $dateTo, array $fieldMapping = []): array
    {
        $token = trim((string) ($credentials['token'] ?? ''));
        $accountId = self::normalizeAccountId((string) ($credentials['ad_account_id'] ?? ''));
        if ($token === '' || $accountId === '') {
            throw new \RuntimeException('Facebook: Access Token and Ad Account ID are required.');
        }

        $url = self::apiBase($credentials) . '/' . $accountId . '/insights?' . http_build_query([
            'access_token'   => $token,
            'level'          => 'ad',
            'fields'         => 'spend,campaign_id,adset_id,ad_id,account_currency',
            'time_increment' => 1,
            'time_range'     => json_encode(['since' => $dateFrom, 'until' => $dateTo]),
            'limit'          => self::PAGE_LIMIT,
        ]);

        $records = [];
        $pages = 0;

        // Идём по paging.next: Facebook отдаёт готовый абсолютный URL со всеми
        // параметрами и курсором. Склеивать курсор руками — источник тихих потерь
        // страниц, если набор параметров разъедется.
        while ($url !== null && $pages < self::MAX_PAGES) {
            $response = self::httpGet($url, $credentials, 45);
            if ($response['error'] !== null) {
                throw new \RuntimeException('Facebook: ' . $response['error']);
            }

            $decoded = json_decode($response['body'], true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Facebook: unreadable API response.');
            }

            foreach (($decoded['data'] ?? []) as $row) {
                $date = (string) ($row['date_start'] ?? '');
                if ($date === '') {
                    continue;
                }
                $adId = (string) ($row['ad_id'] ?? '');
                $campaignId = (string) ($row['campaign_id'] ?? '');

                $records[] = [
                    // Ключ идемпотентности: одна строка на (объявление, день). При
                    // повторном синке той же даты запись обновляется, а не дублируется.
                    'external_id'        => 'fb_' . $campaignId . '_' . $adId . '_' . $date,
                    'source_campaign_id' => $campaignId,
                    'ad_id'              => $adId,
                    'adset_id'           => (string) ($row['adset_id'] ?? ''),
                    'amount'             => (float) ($row['spend'] ?? 0),
                    'currency'           => (string) ($row['account_currency'] ?? 'USD'),
                    'date'               => $date,
                    'raw_json'           => json_encode($row),
                ];
            }

            $url = $decoded['paging']['next'] ?? null;
            $pages++;
        }

        return $records;
    }

    /** Базовый URL с версией из настроек подключения. */
    private static function apiBase(array $credentials): string
    {
        $version = trim((string) ($credentials['api_version'] ?? ''));
        if ($version === '' || !preg_match('/^v\d+\.\d+$/', $version)) {
            $version = self::DEFAULT_API_VERSION;
        }
        return rtrim(self::API_BASE, '/') . '/' . $version;
    }

    /**
     * GET к Graph API с опциональным прокси.
     * @return array{body:string,error:?string}
     */
    private static function httpGet(string $url, array $credentials, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);

        // Прокси: Facebook периодически вводит гео/IP-ограничения, и запрос с IP
        // сервера трекера начинает получать капчу или отказ. Тот же прокси, что
        // используется для залива, снимает вопрос.
        self::applyProxy($ch, $credentials);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return ['body' => '', 'error' => 'HTTP transport error: ' . $curlErr];
        }
        if (!is_string($body)) {
            return ['body' => '', 'error' => 'Empty response from Facebook API.'];
        }
        if ($code < 200 || $code >= 300) {
            $decoded = json_decode($body, true);
            $fbError = $decoded['error'] ?? null;
            if (is_array($fbError)) {
                $msg = $fbError['message'] ?? 'unknown error';
                $sub = isset($fbError['error_user_msg']) ? ' — ' . $fbError['error_user_msg'] : '';
                $codeNum = $fbError['code'] ?? '?';
                return ['body' => '', 'error' => "HTTP $code (code $codeNum): $msg$sub"];
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

    private static function normalizeAccountId(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            return '';
        }
        return strpos($id, 'act_') === 0 ? $id : 'act_' . $id;
    }
}
