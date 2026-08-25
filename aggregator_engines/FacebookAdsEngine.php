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
                // Cost is reported by the ad account's calendar day, so the importer
                // needs this to match spend against UTC click timestamps.
                'timezone' => $tz !== '?' ? $tz : null,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * IANA timezone the ad account reports in, or null if it cannot be read.
     * Meta reports insights by the account's own calendar day; matching those days
     * against UTC click dates is what makes an IST account reconcile to zero.
     */
    public static function accountTimezone(array $credentials): ?string
    {
        try {
            $token = trim((string) ($credentials['token'] ?? ''));
            $accountId = self::normalizeAccountId((string) ($credentials['ad_account_id'] ?? ''));
            if ($token === '' || $accountId === '') {
                return null;
            }

            $url = self::apiBase($credentials) . '/' . $accountId . '?' . http_build_query([
                'access_token' => $token,
                'fields'       => 'timezone_name',
            ]);

            $response = self::httpGet($url, $credentials, 15);
            if ($response['error'] !== null) {
                return null;
            }

            $account = json_decode($response['body'], true);
            $tz = trim((string) ($account['timezone_name'] ?? ''));
            return $tz !== '' ? $tz : null;
        } catch (\Throwable $e) {
            return null;
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

    /**
     * Текущее состояние объявлений / адсетов / рекламных кампаний Meta для
     * play-pause тумблеров в отчёте. Трекер не источник правды о состоянии
     * в сети — этот метод читает её.
     *
     * Один запрос на id: батч-форма `?ids=<id,id,...>` удалена в Graph v26
     * (HTTP 500, code 100 "The ids query parameter is deprecated in v26.0+")
     * причём Graph применяет поведение v26 независимо от запрошенной версии.
     *
     * @param array $credentials credentials_json подключения
     * @param array $entityIds   числовые id объектов Graph API
     * @return array<string, array{status:string, effective:string}> разрешённые id;
     *         неразрешённые просто отсутствуют — частичный ответ лучше пустого
     */
    public static function fetchEntityStatuses(array $credentials, array $entityIds): array
    {
        $token = trim((string) ($credentials['token'] ?? ''));
        if ($token === '') {
            return [];
        }

        $out = [];
        foreach ($entityIds as $entityId) {
            $entityId = trim((string) $entityId);
            if ($entityId === '' || !ctype_digit($entityId)) {
                continue;
            }

            $url = self::apiBase($credentials) . '/' . $entityId . '?' . http_build_query([
                'access_token' => $token,
                'fields'       => 'id,status,effective_status',
            ]);

            // Partial answers are fine: unknown ids simply stay unset. But a
            // total failure must leave a trace — the pre-fix batch call failed
            // here silently for weeks and every toggle rendered ACTIVE.
            $response = self::httpGet($url, $credentials, 15);
            if ($response['error'] !== null) {
                error_log('FacebookAdsEngine::fetchEntityStatuses: id=' . $entityId . ' ' . $response['error']);
                continue;
            }

            $decoded = json_decode($response['body'], true);
            if (!is_array($decoded) || !isset($decoded['id'])) {
                continue;
            }
            $out[(string) $decoded['id']] = [
                'status'    => (string) ($decoded['status'] ?? ''),
                'effective' => (string) ($decoded['effective_status'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Пауза/запуск объявления, адсета или кампании Meta прямо из трекера.
     * Трекер — не источник правды о состоянии в сети, поэтому это команда:
     * подтвердили ответ Graph API и всё, UI ведёт свою оптимистичную отметку.
     *
     * @param array $credentials credentials_json подключения (token, api_version, proxy_url)
     * @param string $entityId   числовой id объекта Graph API (ad / adset / campaign)
     * @param string $status     'ACTIVE' | 'PAUSED'
     * @return array{success:bool,message:string}
     */
    public static function updateEntityStatus(array $credentials, string $entityId, string $status): array
    {
        $token = trim((string) ($credentials['token'] ?? ''));
        $entityId = trim($entityId);
        if ($token === '' || $entityId === '' || !ctype_digit($entityId)) {
            return ['success' => false, 'message' => 'Facebook: entity id and Access Token are required.'];
        }
        $status = strtoupper($status) === 'ACTIVE' ? 'ACTIVE' : 'PAUSED';

        $ch = curl_init(self::apiBase($credentials) . '/' . $entityId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['status' => $status, 'access_token' => $token]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        // Same unroutable-AAAA hazard as the GET paths (§1.12).
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        self::applyProxy($ch, $credentials);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return ['success' => false, 'message' => 'HTTP transport error: ' . $curlErr];
        }
        if (!is_string($body)) {
            return ['success' => false, 'message' => 'Empty response from Facebook API.'];
        }
        $decoded = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            $fbError = is_array($decoded) ? ($decoded['error'] ?? null) : null;
            if (is_array($fbError)) {
                $msg = $fbError['message'] ?? 'unknown error';
                return ['success' => false, 'message' => "HTTP $code: $msg"];
            }
            return ['success' => false, 'message' => "HTTP $code: " . substr($body, 0, 300)];
        }
        if (!is_array($decoded) || empty($decoded['success'])) {
            return ['success' => false, 'message' => 'Facebook did not confirm the status change.'];
        }
        return ['success' => true, 'message' => $status];
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
        // graph.facebook.com's AAAA records are unroutable from many tracker
        // hosts: without the pin the resolver can land on IPv6 and the read
        // reads as a resolver failure (§1.1 fix family — keep it on every
        // Graph call this engine makes).
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

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
