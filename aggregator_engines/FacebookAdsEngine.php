<?php
/**
 * FacebookAdsEngine — импорт рекламного расхода из Facebook / Meta Ads.
 *
 * Cost-shaped engine: возвращает записи {amount, currency, campaign_id, ad_id, adset_id, date},
 * которые aggregator_cron.php записывает в cost_records и распределяет по кликам через
 * ad_id / campaign_id (теми ключами, что уже проставляются traffic-source-шаблоном Facebook).
 *
 * Авторизация: long-lived User Access Token или System User Token (передаётся в поле token).
 * Ad account id передаётся с префиксом act_ (например act_1234567890).
 *
 * API: Facebook Marketing API /v23.0/act_<id>/insights с breakdown по campaign/adset/ad,
 * поля spend, campaign_id, adset_id, ad_id, date_start.
 */

class FacebookAdsEngine
{
    private const API_VERSION = 'v23.0';
    private const API_BASE = 'https://graph.facebook.com/';

    public static function getRequiredFields(): array
    {
        return [
            ['key' => 'token', 'label' => 'Access Token (long-lived / system user)', 'type' => 'password', 'required' => true, 'placeholder' => 'EAAG...'],
            ['key' => 'ad_account_id', 'label' => 'Ad Account ID', 'type' => 'text', 'required' => true, 'placeholder' => 'act_1234567890'],
            ['key' => 'app_id', 'label' => 'App ID (optional, for some tokens)', 'type' => 'text', 'required' => false],
            ['key' => 'app_secret', 'label' => 'App Secret (optional)', 'type' => 'password', 'required' => false],
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

            // Minimal request: ask for today's spend. A 200 means the token + account are valid.
            $today = date('Y-m-d');
            $params = http_build_query([
                'access_token' => $token,
                'level'        => 'campaign',
                'fields'       => 'spend,campaign_id',
                'time_increment' => 1,
                'time_range'   => json_encode(['since' => $today, 'until' => $today]),
                'limit'        => 1,
            ]);
            $url = self::API_BASE . self::API_VERSION . '/' . $accountId . '/insights?' . $params;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            $body = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($err !== '') {
                return ['success' => false, 'message' => 'HTTP error: ' . $err];
            }
            if ($code >= 200 && $code < 400) {
                return ['success' => true, 'message' => 'Facebook Ads connection OK.'];
            }
            $decoded = json_decode($body, true);
            $msg = $decoded['error']['message'] ?? substr($body, 0, 300);
            return ['success' => false, 'message' => "HTTP $code: $msg"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Fetch daily spend broken down by campaign / adset / ad for the given period.
     * Returns cost-shaped records compatible with cost_records.
     */
    public static function fetchRecords(array $credentials, string $dateFrom, string $dateTo, array $fieldMapping = []): array
    {
        $token = trim((string) ($credentials['token'] ?? ''));
        $accountId = self::normalizeAccountId((string) ($credentials['ad_account_id'] ?? ''));
        if ($token === '' || $accountId === '') {
            return [];
        }

        $records = [];
        $url = self::API_BASE . self::API_VERSION . '/' . $accountId . '/insights';
        $params = [
            'access_token'    => $token,
            'level'           => 'ad',
            'fields'          => 'spend,campaign_id,adset_id,ad_id,currency',
            'time_increment'  => 1,
            'time_range'      => json_encode(['since' => $dateFrom, 'until' => $dateTo]),
            'limit'           => 200,
        ];

        // Paginate through the cursor.
        $after = null;
        $safety = 0;
        do {
            if ($after !== null) {
                $params['after'] = $after;
            }
            $ch = curl_init($url . '?' . http_build_query($params));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            $body = (string) curl_exec($ch);
            curl_close($ch);

            $decoded = json_decode($body, true);
            $data = $decoded['data'] ?? [];
            foreach ($data as $row) {
                $records[] = [
                    'external_id'       => ($row['campaign_id'] ?? '') . '_' . ($row['ad_id'] ?? '') . '_' . ($row['date_start'] ?? ''),
                    'source_campaign_id' => (string) ($row['campaign_id'] ?? ''),
                    'ad_id'             => (string) ($row['ad_id'] ?? ''),
                    'adset_id'          => (string) ($row['adset_id'] ?? ''),
                    'amount'            => (float) ($row['spend'] ?? 0),
                    'currency'          => (string) ($row['currency'] ?? 'USD'),
                    'date'              => (string) ($row['date_start'] ?? date('Y-m-d')),
                    'raw_json'          => json_encode($row),
                ];
            }
            $after = $decoded['paging']['cursors']['after'] ?? null;
            $hasNext = !empty($decoded['paging']['next']);
            $safety++;
        } while ($after && $hasNext && $safety < 50);

        return $records;
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
