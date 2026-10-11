<?php
/**
 * TikTokMcpClient — клиент TikTok for Business MCP Server.
 *
 * MCP-сервер TikTok (business-api.tiktok.com/open_mcp/tt-ads-mcp-layer)
 * оборачивает тот же Business API v1.3, что и прямое подключение по токену,
 * но для входа не нужно developer-приложение: панель регистрирует себя сама
 * (RFC 7591, dynamic client registration — эндпоинт /oauth/register открыт,
 * проверено 2026-10-11) и работает как public client с PKCE S256, без секрета.
 *
 * Жизненный цикл авторизации: access-токен ~24 часа, refresh-токен 30 дней,
 * каждый refresh выдаёт новый refresh-токен (ротация). Инструменты MCP —
 * обёртки Marketing API: ответ tools/call несёт тот же JSON {code, message,
 * data}, что и прямой вызов, поэтому TikTokAdsEngine переиспользует разбор.
 *
 * Используется действиями tiktok_mcp_* в api.php (OAuth-флоу и подключение
 * кабинетов) и движком TikTokAdsEngine (транспорт отчётов для подключений
 * с флагом mcp=1 в credentials_json).
 */

class TikTokMcpClient
{
    public const MCP_URL = 'https://business-api.tiktok.com/open_mcp/tt-ads-mcp-layer';
    private const AUTHORIZE_URL = 'https://business-api.tiktok.com/portal/mcp-tt4b-authorize';
    private const OAUTH_BASE = 'https://business-api.tiktok.com/open_mcp/tt-ads-mcp-layer/oauth';
    private const SCOPE = 'mcp:tt4b';

    /**
     * Test seam: callable(string $method, string $url, ?array $params, array $context): array{status:int, body:string, error:?string}.
     * $params — form-поля (token-эндпоинты) или JSON-тело (register / tools/call);
     * $context — ['kind' => 'register'|'token'|'refresh'|'tool', 'headers' => [...], 'tool' => ?string].
     * Production никогда его не ставит; тесты через него проверяют, что именно
     * уходит в TikTok, без сети.
     * @var callable|null
     */
    public static $testHttpHandler = null;

    /**
     * client_id этой панели: из settings, при первом входе (или после смены
     * адреса панели) — саморегистрация через DCR. Секрет public client'у не
     * выдаётся, хранить нечего.
     *
     * @return string client_id (непустой)
     * @throws RuntimeException когда регистрация не удалась
     */
    public static function ensureClient(PDO $pdo, string $redirectUri): string
    {
        $stored = self::readSettings($pdo, ['tiktok_mcp_client_id', 'tiktok_mcp_redirect_uri']);
        $clientId = trim((string) ($stored['tiktok_mcp_client_id'] ?? ''));
        $storedRedirect = trim((string) ($stored['tiktok_mcp_redirect_uri'] ?? ''));
        if ($clientId !== '' && $storedRedirect === $redirectUri) {
            return $clientId;
        }

        $host = (string) (parse_url($redirectUri, PHP_URL_HOST) ?: 'panel');
        $registration = self::request('POST', self::OAUTH_BASE . '/register', null, [
            'client_name' => 'Orbitra tracker (' . $host . ')',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'redirect_uris' => [$redirectUri],
            'scope' => self::SCOPE,
        ], [], 20, 'register');

        $decoded = json_decode($registration['body'], true);
        $clientId = is_array($decoded) ? trim((string) ($decoded['client_id'] ?? '')) : '';
        if ($registration['error'] !== null || $clientId === '') {
            $detail = $registration['error'] ?? (is_array($decoded) ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? '') : '');
            throw new \RuntimeException('TikTok MCP refused client registration' . ($detail !== '' ? ': ' . $detail : ' (no client_id in response).'));
        }

        $pdo->prepare("INSERT INTO settings (key, value) VALUES ('tiktok_mcp_client_id', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")
            ->execute([$clientId]);
        $pdo->prepare("INSERT INTO settings (key, value) VALUES ('tiktok_mcp_redirect_uri', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")
            ->execute([$redirectUri]);
        return $clientId;
    }

    /** client_id из settings без сети (для cron-refresh токена). '' если панель ещё не регистрировалась. */
    public static function storedClientId(PDO $pdo): string
    {
        $stored = self::readSettings($pdo, ['tiktok_mcp_client_id']);
        return trim((string) ($stored['tiktok_mcp_client_id'] ?? ''));
    }

    /**
     * @return array{verifier:string,challenge:string} PKCE pair, S256
     */
    public static function pkcePair(): array
    {
        $verifier = self::base64url(random_bytes(48));
        return [
            'verifier' => $verifier,
            'challenge' => self::base64url(hash('sha256', $verifier, true)),
        ];
    }

    public static function authorizeUrl(string $clientId, string $redirectUri, string $state, string $codeChallenge): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Обмен authorization code на токены (public client: только client_id +
     * PKCE verifier, без секрета).
     *
     * @return array{access_token:string, refresh_token:string, expires_in:int, refresh_token_expires_in:int}
     * @throws RuntimeException при ошибке OAuth
     */
    public static function exchangeCode(string $clientId, string $redirectUri, string $code, string $verifier, array $proxyCredentials = []): array
    {
        return self::tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
        ], $proxyCredentials);
    }

    /**
     * Продление токена. Refresh-токен одноразовый — ответ содержит новый.
     *
     * @return array{access_token:string, refresh_token:string, expires_in:int, refresh_token_expires_in:int}
     * @throws RuntimeException при ошибке OAuth
     */
    public static function refreshToken(array $proxyCredentials, string $clientId, string $refreshToken): array
    {
        return self::tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
        ], $proxyCredentials);
    }

    /**
     * Вызов инструмента MCP (JSON-RPC 2.0 поверх streamable HTTP). Ответ
     * инструмента — тот же JSON Marketing API {code, message, data}, что и у
     * прямого вызова обёрнутого эндпоинта; он и возвращается.
     *
     * @param array $credentials credentials подключения (access_token, proxy_url)
     * @return array decoded payload инструмента
     * @throws RuntimeException при транспортной ошибке, ошибке OAuth или ошибке самого инструмента
     */
    public static function callTool(array $credentials, string $tool, array $arguments): array
    {
        $rpc = self::request('POST', self::MCP_URL, null, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => (object) $arguments,
            ],
        ], $credentials, 45, 'tool', $tool);

        if ($rpc['error'] !== null) {
            throw new \RuntimeException($tool . ': ' . $rpc['error']);
        }

        $decoded = self::decodeRpcBody($rpc['body']);
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $message = trim((string) ($decoded['error']['message'] ?? '')) ?: ('code ' . ($decoded['error']['code'] ?? '?'));
            throw new \RuntimeException($tool . ': ' . $message);
        }
        $result = is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
        $content = is_array($result['content'] ?? null) ? $result['content'] : [];
        $text = '';
        foreach ($content as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'text' && is_string($item['text'] ?? null)) {
                $text .= $item['text'];
            }
        }
        if (!empty($result['isError']) && trim($text) !== '') {
            throw new \RuntimeException($tool . ': ' . trim($text));
        }

        $payload = json_decode($text, true);
        if (!is_array($payload)) {
            throw new \RuntimeException($tool . ': unreadable tool response' . (trim($text) !== '' ? ' — ' . substr(trim($text), 0, 200) : ' (empty).'));
        }
        return $payload;
    }

    /**
     * POST на token-эндпоинт: OAuth 2.1, form-urlencoded, ответ JSON.
     * @return array{access_token:string, refresh_token:string, expires_in:int, refresh_token_expires_in:int}
     */
    private static function tokenRequest(array $form, array $proxyCredentials): array
    {
        $kind = $form['grant_type'] === 'refresh_token' ? 'refresh' : 'token';
        $response = self::request('POST', self::OAUTH_BASE . '/token', $form, null, $proxyCredentials, 20, $kind);

        $decoded = json_decode($response['body'], true);
        if ($response['error'] !== null || !is_array($decoded)) {
            throw new \RuntimeException('TikTok MCP token endpoint: ' . ($response['error'] ?: 'unreadable response.'));
        }
        $accessToken = trim((string) ($decoded['access_token'] ?? ''));
        if ($accessToken === '') {
            $detail = trim((string) ($decoded['error_description'] ?? $decoded['error'] ?? 'no access token in response.'));
            throw new \RuntimeException('TikTok MCP token endpoint: ' . $detail);
        }
        return [
            'access_token' => $accessToken,
            'refresh_token' => trim((string) ($decoded['refresh_token'] ?? '')),
            'expires_in' => max(0, (int) ($decoded['expires_in'] ?? 86400)),
            'refresh_token_expires_in' => max(0, (int) ($decoded['refresh_token_expires_in'] ?? 0)),
        ];
    }

    /**
     * Единый сетевой слой. $form — form-urlencoded поля, $json — JSON-тело.
     * @return array{status:int, body:string, error:?string}
     */
    private static function request(string $method, string $url, ?array $form, ?array $json, array $credentials, int $timeout, string $kind, ?string $tool = null): array
    {
        $headers = ['Accept: application/json'];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        } else {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if ($kind === 'tool') {
            // streamable HTTP: сервер может ответить как plain JSON, так и SSE.
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json, text/event-stream',
                'Authorization: Bearer ' . trim((string) ($credentials['access_token'] ?? '')),
            ];
        }

        if (is_callable(self::$testHttpHandler)) {
            return call_user_func(self::$testHttpHandler, $method, $url, $json ?? $form, [
                'kind' => $kind,
                'tool' => $tool,
                'headers' => $headers,
                'form' => $form,
            ]);
        }

        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'PHP cURL is required for the TikTok MCP connection.'];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json !== null
                ? json_encode($json, JSON_UNESCAPED_UNICODE)
                : http_build_query($form ?? [], '', '&', PHP_QUERY_RFC3986));
        }
        if ($kind !== 'register') {
            self::applyProxy($ch, $credentials);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => 'HTTP transport error: ' . $curlErr];
        }
        if (!is_string($body) || $body === '') {
            return ['status' => $status, 'body' => '', 'error' => 'Empty response from TikTok MCP.'];
        }
        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($body, true);
            $detail = is_array($decoded) ? trim((string) ($decoded['error_description'] ?? $decoded['error'] ?? $decoded['message'] ?? '')) : '';
            return ['status' => $status, 'body' => $body, 'error' => 'HTTP ' . $status . ($detail !== '' ? ': ' . $detail : ': ' . substr($body, 0, 200))];
        }
        return ['status' => $status, 'body' => $body, 'error' => null];
    }

    /**
     * Ответ streamable HTTP — либо один JSON-объект, либо SSE-поток с data:-строками;
     * из потока берётся первое сообщение с result/error.
     */
    private static function decodeRpcBody(string $body): array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && (isset($decoded['result']) || isset($decoded['error']) || isset($decoded['jsonrpc']))) {
            return $decoded;
        }
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            if (strpos($line, 'data:') !== 0) {
                continue;
            }
            $event = json_decode(trim(substr($line, 5)), true);
            if (is_array($event) && (isset($event['result']) || isset($event['error']))) {
                return $event;
            }
        }
        throw new \RuntimeException('unreadable MCP response envelope' . ($body !== '' ? ' — ' . substr($body, 0, 200) : ' (empty).'));
    }

    /** Прокси берётся из TikTokAdsEngine, чтобы логика lived в одном месте. */
    private static function applyProxy($ch, array $credentials): void
    {
        if (trim((string) ($credentials['proxy_url'] ?? '')) === '') {
            return;
        }
        require_once __DIR__ . '/../aggregator_engines/TikTokAdsEngine.php';
        TikTokAdsEngine::applyProxy($ch, $credentials);
    }

    /** @return array<string,string> значения запрошенных ключей settings */
    private static function readSettings(PDO $pdo, array $keys): array
    {
        try {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare("SELECT key, value FROM settings WHERE key IN ($placeholders)");
            $stmt->execute($keys);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
