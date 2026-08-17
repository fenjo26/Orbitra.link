<?php
/**
 * GoogleAdsEngine — импорт рекламного расхода из Google Ads.
 *
 * Cost-shaped engine: возвращает записи {amount, currency, campaign_id, ad_id, adset_id, date}.
 * Атрибуция к кликам — по campaign_id / creative (ad_id), теми ключами, что проставляются
 * traffic-source-шаблоном Google Ads (ValueTrack {campaignid}, {creative}).
 *
 * Авторизация (server-to-server OAuth2):
 *   - developer_token  — токен из Google Ads API Center
 *   - client_id        — OAuth2 client id консольного проекта
 *   - client_secret    — OAuth2 client secret
 *   - refresh_token    — долгоживущий refresh-токен (не протекает, обновляется автоматически)
 *   - customer_id      — CID аккаунта Google Ads (без тире, напр. 1234567890)
 *   - login_customer_id — CID управляющего аккаунта (MCC), когда кабинет доступен
 *                          только через менеджера; опционален для прямых аккаунтов
 *   - proxy_url        — опциональный прокси scheme://user:pass@host:port
 *
 * refresh_token обменивается на короткоживущий access_token при каждом запросе; полученный
 * access_token кешируется в oauth_tokens (ключ — провайдер + хеш refresh-токена, чтобы
 * подключения разных Google-логинов не видели чужие токены) до истечения.
 *
 * Использует Google Ads API REST endpoint (v19) с GAQL-запросом по ad_group_ad.
 *
 * Ошибки (протухший refresh-токен, INVALID_GRANT, недоступный кабинет) бросаются
 * RuntimeException'ом — как в TikTokAdsEngine: тихий возврат пустого массива выглядел
 * бы в UI как «успешно, 0 записей» и прятал реальную причину.
 */

class GoogleAdsEngine
{
    private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_VERSION = 'v19';
    private const API_BASE = 'https://googleads.googleapis.com/';

    public static function getRequiredFields(): array
    {
        return [
            ['key' => 'developer_token', 'label' => 'Developer Token', 'type' => 'password', 'required' => true, 'placeholder' => '1a2B3c...'],
            ['key' => 'client_id', 'label' => 'OAuth2 Client ID', 'type' => 'text', 'required' => true, 'placeholder' => 'xxxx.apps.googleusercontent.com'],
            ['key' => 'client_secret', 'label' => 'OAuth2 Client Secret', 'type' => 'password', 'required' => true],
            ['key' => 'refresh_token', 'label' => 'OAuth2 Refresh Token', 'type' => 'password', 'required' => true],
            ['key' => 'customer_id', 'label' => 'Customer ID (CID, no dashes)', 'type' => 'text', 'required' => true, 'placeholder' => '1234567890'],
            ['key' => 'login_customer_id', 'label_key' => 'costImport.fields.gaLoginCustomerId', 'label' => 'Login Customer ID (MCC, optional, no dashes)', 'type' => 'text', 'required' => false, 'placeholder' => '1234567890'],
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
            $accessToken = self::getAccessToken($credentials);

            // Light call: list accessible customers confirms the developer token + auth.
            $response = self::httpCall('GET', self::API_BASE . self::API_VERSION . '/customers:listAccessibleCustomers', $credentials, $accessToken, null, 15);
            $decoded = json_decode($response['body'], true);
            $count = is_array($decoded) ? count($decoded['resourceNames'] ?? []) : 0;
            return ['success' => true, 'message' => "Google Ads connection OK — $count accessible account(s)."];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Fetch daily spend broken down by campaign / ad group / ad for the given period.
     * Returns cost-shaped records.
     *
     * @throws RuntimeException при ошибке API/авторизации — вызывающий код записывает
     *         это в last_sync_error (aggregator_cron.php и api.php aggregator_sync).
     */
    public static function fetchRecords(array $credentials, string $dateFrom, string $dateTo, array $fieldMapping = []): array
    {
        $missing = self::validateCredentials($credentials);
        if ($missing) {
            throw new \RuntimeException('Google Ads: ' . implode(', ', $missing) . ' are required.');
        }
        $accessToken = self::getAccessToken($credentials);

        $customerId = self::cidDigits($credentials['customer_id']);
        // GAQL: per-day, per-ad cost.
        $since = str_replace('-', '', $dateFrom);
        $until = str_replace('-', '', $dateTo);
        $gaql = "SELECT "
            . "segments.date, segments.ad_group_ad, "
            . "campaign.id, ad_group.id, ad_group_ad.ad_id, "
            . "metrics.cost_micros, customer.currency_code "
            . "FROM ad_group_ad "
            . "WHERE segments.date BETWEEN '$since' AND '$until' "
            . "AND metrics.cost_micros > 0";

        $url = self::API_BASE . self::API_VERSION . "/customers/$customerId/googleAds:searchStream";
        $response = self::httpCall('POST', $url, $credentials, $accessToken, ['query' => $gaql], 40);

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Google Ads: unreadable API response.');
        }
        // Ошибки приходят как [{error:{code,message,...}}] вместо массива результатов.
        if (isset($decoded['error']) || isset($decoded[0]['error'])) {
            $error = $decoded['error'] ?? $decoded[0]['error'];
            throw new \RuntimeException('Google Ads: ' . (string) ($error['message'] ?? 'API error') . (isset($error['code']) ? ' (code ' . $error['code'] . ')' : ''));
        }

        $records = [];
        $results = $decoded[0]['results'] ?? ($decoded['results'] ?? []);
        foreach ($results as $row) {
            $costMicros = (int) ($row['metrics']['cost_micros'] ?? 0);
            $amount = $costMicros / 1000000.0; // micros → currency units
            $date = (string) ($row['segments']['date'] ?? date('Y-m-d'));
            $campaignId = (string) ($row['campaign']['id'] ?? '');
            $adId = (string) ($row['adGroupAd']['adId'] ?? ($row['ad_group_ad']['ad_id'] ?? ''));
            $adsetId = (string) ($row['adGroup']['id'] ?? '');
            $records[] = [
                'external_id'        => $campaignId . '_' . $adId . '_' . $date,
                'source_campaign_id' => $campaignId,
                'ad_id'              => $adId,
                'adset_id'           => $adsetId,
                'amount'             => $amount,
                'currency'           => (string) ($row['customer']['currency_code'] ?? 'USD'),
                'date'               => $date,
                'raw_json'           => json_encode($row),
            ];
        }
        return $records;
    }

    private static function validateCredentials(array $c): array
    {
        $required = ['developer_token', 'client_id', 'client_secret', 'refresh_token', 'customer_id'];
        $missing = [];
        foreach ($required as $k) {
            if (trim((string) ($c[$k] ?? '')) === '') {
                $missing[] = $k;
            }
        }
        return $missing;
    }

    /** CID в каноническом виде: только цифры (пользователи копируют и 123-456-7890). */
    private static function cidDigits($cid): string
    {
        return preg_replace('/\D/', '', (string) $cid);
    }

    /**
     * login-customer-id для запросов: CID менеджера, через которого доступен
     * кабинет; для прямых аккаунтов — сам CID.
     */
    private static function loginCustomerId(array $credentials): string
    {
        $login = self::cidDigits($credentials['login_customer_id'] ?? '');
        return $login !== '' ? $login : self::cidDigits($credentials['customer_id'] ?? '');
    }

    /**
     * Exchange the refresh token for a short-lived access token.
     * Caches the result in the oauth_tokens table until expiry, keyed by the
     * refresh token hash so parallel connections of different Google logins
     * never read each other's tokens.
     *
     * @throws RuntimeException когда обмен не удался (в т.ч. INVALID_GRANT —
     *         протухший/отозванный refresh-токен).
     */
    private static function getAccessToken(array $credentials): string
    {
        global $pdo;
        $refreshToken = trim((string) ($credentials['refresh_token'] ?? ''));
        $cacheKey = 'google_ads_' . substr(sha1($refreshToken), 0, 12);

        // Try cache first.
        if (isset($pdo)) {
            try {
                $stmt = $pdo->prepare("SELECT access_token, expires_at FROM oauth_tokens WHERE provider = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$cacheKey]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['access_token']) && (!empty($row['expires_at']) && strtotime($row['expires_at']) > time() + 60)) {
                    return $row['access_token'];
                }
            } catch (\Throwable $e) {
                // Cache miss / table missing — fall through to refresh.
            }
        }

        $ch = curl_init(self::OAUTH_TOKEN_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id'     => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]));
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new \RuntimeException('Google Ads token endpoint: HTTP transport error ' . $err);
        }

        $decoded = json_decode($body, true);
        $token = is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';
        if ($code < 200 || $code >= 300 || $token === '') {
            $reason = is_array($decoded) ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? '') : '';
            if ($reason === '') {
                $reason = substr((string) $body, 0, 300);
            }
            // Самая частая причина: refresh-токен отозван (или год не использовался
            // и истёк). Лечится только переподключением аккаунта.
            if (stripos($reason, 'invalid_grant') !== false) {
                $reason .= ' — the refresh token was revoked or expired, reconnect the Google Ads account.';
            }
            throw new \RuntimeException("Google Ads token endpoint: HTTP $code: $reason");
        }
        $expiresIn = (int) ($decoded['expires_in'] ?? 3600);

        if (isset($pdo)) {
            try {
                $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
                $stmt = $pdo->prepare("INSERT INTO oauth_tokens (provider, access_token, expires_at) VALUES (?, ?, ?)");
                $stmt->execute([$cacheKey, $token, $expiresAt]);
            } catch (\Throwable $e) {
                // Cache write failure is non-fatal.
            }
        }
        return $token;
    }

    /**
     * HTTP-вызов Google Ads API REST с общими заголовками (Authorization,
     * developer-token, login-customer-id) и опциональным прокси.
     *
     * @param array|null $jsonBody POST-тело как PHP-массив (GET → null)
     * @return array{body:string}
     * @throws RuntimeException при транспортной ошибке или HTTP-коде вне 2xx/3xx
     */
    private static function httpCall(string $method, string $url, array $credentials, string $accessToken, ?array $jsonBody, int $timeout): array
    {
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'developer-token: ' . trim((string) ($credentials['developer_token'] ?? '')),
        ];
        $loginCid = self::loginCustomerId($credentials);
        if ($loginCid !== '') {
            $headers[] = 'login-customer-id: ' . $loginCid;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody ?? new \stdClass()));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        self::applyProxy($ch, $credentials);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            throw new \RuntimeException('Google Ads: HTTP transport error ' . $curlErr);
        }
        if (!is_string($body) || $body === '') {
            throw new \RuntimeException('Google Ads: empty API response.');
        }
        if ($code < 200 || $code >= 400) {
            $decoded = json_decode($body, true);
            $msg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            if ($msg === '') {
                $msg = is_string($decoded['error'] ?? null) ? (string) $decoded['error'] : substr($body, 0, 300);
            }
            throw new \RuntimeException("Google Ads: HTTP $code: $msg");
        }

        return ['body' => $body];
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
