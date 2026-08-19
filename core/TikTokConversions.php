<?php

// Server-side conversion delivery to TikTok Events API. Like Meta CAPI, live
// events ride the existing S2S retry queue so an ad-network postback never waits
// for TikTok. The access token is kept in headers_json and never exposed by the
// Pixel Vault list endpoint.
class TikTokConversions
{
    private const ENDPOINT = 'https://business-api.tiktok.com/open_api/v1.3/pixel/track/';

    public static function defaultMapping(): array
    {
        return [
            'lead' => 'SubmitForm',
            'sale' => 'CompletePayment',
            'deposit' => 'CompletePayment',
            'registration' => 'CompleteRegistration',
            'rejected' => '',
            'trash' => '',
        ];
    }

    /**
     * Which TikTok event (if any) this tracker status should produce for this pixel.
     * mapping_json wins; otherwise the default table; a status mapped to '' is
     * explicitly suppressed.
     *
     * For custom/unmapped statuses, we still check mapping_json first — an operator
     * can configure a TikTok event for any status, including ones not yet mapped to a
     * conversion type. Only if there's no explicit mapping do we fall back to the
     * defaults, which return null for unknown statuses.
     */
    public static function resolveEvent(array $pixel, string $status): ?string
    {
        $needle = strtolower(trim($status));
        if ($needle === '') {
            return null;
        }

        // Check pixel-level mapping first.
        $mapping = [];
        if (!empty($pixel['mapping_json'])) {
            $decoded = json_decode((string) $pixel['mapping_json'], true);
            if (is_array($decoded)) {
                $mapping = array_change_key_case($decoded, CASE_LOWER);
            }
        }

        if (array_key_exists($needle, $mapping)) {
            $event = trim((string) $mapping[$needle]);
            return $event === '' ? null : $event;
        }

        $event = self::defaultMapping()[$needle] ?? '';
        return $event === '' ? null : $event;
    }

    public static function buildPayload(array $pixel, array $click, array $ctx): array
    {
        $clickParams = is_array($ctx['click_params'] ?? null) ? $ctx['click_params'] : [];
        $extra = is_array($ctx['extra'] ?? null) ? $ctx['extra'] : [];

        $pageUrl = trim((string) ($pixel['event_source_url'] ?? ''));
        if ($pageUrl !== '') {
            $pageUrl = str_replace(
                ['{campaign_url}', '{landing_url}', '{clickid}'],
                [(string) ($ctx['campaign_url'] ?? ''), (string) ($ctx['landing_url'] ?? ''), (string) ($click['id'] ?? '')],
                $pageUrl
            );
        }
        if ($pageUrl === '' || !preg_match('#^https?://#i', $pageUrl)) {
            $pageUrl = (string) ($click['referer'] ?? $ctx['campaign_url'] ?? '');
        }
        if ($pageUrl === '' || !preg_match('#^https?://#i', $pageUrl)) {
            $pageUrl = 'https://localhost/';
        }

        $user = [];
        if (!empty($click['ip'])) {
            $user['ip'] = (string) $click['ip'];
        }
        if (!empty($click['user_agent'])) {
            $user['user_agent'] = (string) $click['user_agent'];
        }
        $email = strtolower(trim((string) ($extra['email'] ?? $extra['em'] ?? $clickParams['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user['email'] = hash('sha256', $email);
        }
        $phone = preg_replace('/\D+/', '', (string) ($extra['phone'] ?? $extra['ph'] ?? $clickParams['phone'] ?? ''));
        if ($phone !== '') {
            $user['phone_number'] = hash('sha256', $phone);
        }
        if (!empty($click['id'])) {
            $user['external_id'] = hash('sha256', (string) $click['id']);
        }

        $context = [
            'page' => ['url' => $pageUrl],
            'user' => $user,
        ];
        $ttclid = trim((string) ($clickParams['ttclid'] ?? ''));
        if ($ttclid !== '') {
            $context['ad'] = ['callback' => $ttclid];
        }

        $properties = [];
        $payout = (float) ($ctx['payout'] ?? 0);
        if ($payout > 0) {
            $properties['value'] = round($payout, 4);
            $properties['currency'] = strtoupper((string) ($ctx['currency'] ?? 'USD'));
        }

        return [
            'pixel_code' => (string) ($pixel['pixel_id'] ?? ''),
            'event' => (string) ($ctx['event_name'] ?? 'SubmitForm'),
            'event_id' => (string) ($ctx['event_id'] ?? ('orbitra_' . bin2hex(random_bytes(6)))),
            'timestamp' => gmdate('c', (int) ($ctx['event_time'] ?? time())),
            'context' => $context,
            'properties' => $properties ?: new stdClass(),
        ];
    }

    public static function enqueue(PDO $pdo, array $pixel, array $click, array $ctx, ?int $conversionId): bool
    {
        if (empty($pixel['pixel_id']) || empty($pixel['token'])) {
            return false;
        }
        $event = self::resolveEvent($pixel, (string) ($ctx['status'] ?? ''));
        if ($event === null) {
            return false;
        }
        $ctx['event_name'] = $event;
        $payload = self::buildPayload($pixel, $click, $ctx);
        $stmt = $pdo->prepare("
            INSERT INTO s2s_postbacks_log
                (conversion_id, url, method, status, attempts, next_retry_at, postback_id,
                 payload_json, content_type, proxy_url, headers_json, updated_at)
            VALUES (?, ?, 'POST', 'pending', 0, datetime('now'), NULL, ?, 'application/json', ?, ?, datetime('now'))
        ");
        $stmt->execute([
            $conversionId,
            self::ENDPOINT,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            trim((string) ($pixel['proxy_url'] ?? '')) ?: null,
            json_encode(['Access-Token' => (string) $pixel['token']], JSON_UNESCAPED_SLASHES),
        ]);
        return true;
    }

    public static function send(array $pixel, array $payload): array
    {
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Access-Token: ' . (string) ($pixel['token'] ?? '')],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        $success = $curlError === '' && $httpCode >= 200 && $httpCode < 300 && (int) ($decoded['code'] ?? -1) === 0;
        return [
            'success' => $success,
            'message' => $success
                ? 'TikTok accepted the event.'
                : (string) ($decoded['message'] ?? ($curlError !== '' ? $curlError : 'HTTP ' . $httpCode)),
            'response' => is_array($decoded) ? $decoded : null,
        ];
    }
}
