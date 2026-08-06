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
 *
 * refresh_token обменивается на короткоживущий access_token при каждом запросе; полученный
 * access_token кешируется в oauth_tokens (provider='google_ads') до истечения.
 *
 * Использует Google Ads API REST endpoint (v19) с GAQL-запросом по campaign_report.
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
            if ($accessToken === null) {
                return ['success' => false, 'message' => 'Failed to obtain access token (check client_id / client_secret / refresh_token).'];
            }

            // Light call: list accessible customers confirms the developer token + auth.
            $url = self::API_BASE . self::API_VERSION . '/customers:listAccessibleCustomers';
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'developer-token: ' . $credentials['developer_token'],
            ]);
            $body = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($err !== '') {
                return ['success' => false, 'message' => 'HTTP error: ' . $err];
            }
            if ($code >= 200 && $code < 400) {
                return ['success' => true, 'message' => 'Google Ads connection OK.'];
            }
            $decoded = json_decode($body, true);
            $msg = $decoded['error']['message'] ?? substr($body, 0, 300);
            return ['success' => false, 'message' => "HTTP $code: $msg"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Fetch daily spend broken down by campaign / ad group / ad for the given period.
     * Returns cost-shaped records.
     */
    public static function fetchRecords(array $credentials, string $dateFrom, string $dateTo, array $fieldMapping = []): array
    {
        $missing = self::validateCredentials($credentials);
        if ($missing) {
            return [];
        }
        $accessToken = self::getAccessToken($credentials);
        if ($accessToken === null) {
            return [];
        }

        $customerId = preg_replace('/\D/', '', (string) $credentials['customer_id']);
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
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $gaql]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'developer-token: ' . $credentials['developer_token'],
            'login-customer-id: ' . $customerId,
            'Content-Type: application/json',
        ]);
        $body = (string) curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
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

    /**
     * Exchange the refresh token for a short-lived access token.
     * Caches the result in the oauth_tokens table until expiry.
     */
    private static function getAccessToken(array $credentials): ?string
    {
        global $pdo;
        // Try cache first.
        if (isset($pdo)) {
            try {
                $stmt = $pdo->query("SELECT access_token, expires_at FROM oauth_tokens WHERE provider='google_ads' ORDER BY id DESC LIMIT 1");
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
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id'     => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'refresh_token' => $credentials['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]));
        $body = (string) curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode($body, true);
        $token = $decoded['access_token'] ?? null;
        $expiresIn = (int) ($decoded['expires_in'] ?? 3600);

        if ($token && isset($pdo)) {
            try {
                $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
                $stmt = $pdo->prepare("INSERT INTO oauth_tokens (provider, access_token, expires_at) VALUES ('google_ads', ?, ?)");
                $stmt->execute([$token, $expiresAt]);
            } catch (\Throwable $e) {
                // Cache write failure is non-fatal.
            }
        }
        return $token;
    }
}
