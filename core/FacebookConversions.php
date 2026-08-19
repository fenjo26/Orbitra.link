<?php
// core/FacebookConversions.php
//
// Server-side conversion delivery to Meta's Conversions API (CAPI).
//
// The browser pixel fires on the landing page, which is exactly where ad blockers,
// ITP and iOS privacy settings cut it off — so Meta optimises against a fraction of
// the real conversions. The tracker already knows about every conversion the moment
// the affiliate network posts back, and it knows the click's IP, user agent, fbclid
// and _fbp cookie. Sending the event from here recovers the events the pixel loses.
//
// Delivery rides the existing S2S postback queue (s2s_postbacks_log) rather than
// blocking the postback response: Meta occasionally answers slowly, and an affiliate
// network that times out waiting for us will retry the postback and double-count.
//
// Personally identifiable fields are SHA-256 hashed before they leave the server,
// per Meta's requirements. Only the IP and user agent go in clear — Meta requires
// those unhashed.

class FacebookConversions
{
    public const DEFAULT_API_VERSION = 'v25.0';
    private const API_BASE = 'https://graph.facebook.com/';

    /**
     * Tracker status => Meta standard event. Deliberately conservative: rejected and
     * trash map to nothing, because feeding a rejected lead back as a conversion
     * teaches the algorithm to buy more of exactly the wrong traffic.
     */
    public static function defaultMapping(): array
    {
        return [
            'lead'         => 'Lead',
            'sale'         => 'Purchase',
            'deposit'      => 'Purchase',
            'registration' => 'CompleteRegistration',
            'rejected'     => '',
            'trash'        => '',
        ];
    }

    /** Meta standard events offered in the UI. */
    public static function availableEvents(): array
    {
        return [
            'Lead', 'Purchase', 'CompleteRegistration', 'AddToCart', 'InitiateCheckout',
            'AddPaymentInfo', 'Subscribe', 'StartTrial', 'Contact', 'SubmitApplication',
            'Schedule', 'ViewContent', 'Search', 'PageView',
        ];
    }

    /**
     * Which Meta event (if any) this tracker status should produce for this pixel.
     * mapping_json wins; otherwise the default table; a status mapped to '' is
     * explicitly suppressed.
     *
     * For custom/unmapped statuses, we still check mapping_json first — an operator
     * can configure a Meta event for any status, including ones not yet mapped to a
     * conversion type. Only if there's no explicit mapping do we fall back to the
     * defaults, which return null for unknown statuses.
     */
    public static function resolveEvent(array $pixel, string $internalStatus): ?string
    {
        $status = strtolower(trim($internalStatus));
        if ($status === '') {
            return null;
        }

        $mapping = [];
        if (!empty($pixel['mapping_json'])) {
            $decoded = json_decode((string) $pixel['mapping_json'], true);
            if (is_array($decoded)) {
                $mapping = array_change_key_case($decoded, CASE_LOWER);
            }
        }

        // Explicit mapping wins, even for custom/unmapped statuses.
        if (array_key_exists($status, $mapping)) {
            $event = trim((string) $mapping[$status]);
            return $event === '' ? null : $event;
        }

        $defaults = self::defaultMapping();
        $event = $defaults[$status] ?? '';
        return $event === '' ? null : $event;
    }

    /**
     * Build the Conversions API payload for one conversion.
     *
     * @param array $pixel  campaign_pixels row (pixel_id, token, mapping_json, test_event_code,
     *                      event_source_url)
     * @param array $click  clicks row (id, ip, user_agent, referer, country_code, region, city, zipcode)
     * @param array $ctx    event_name, event_id, event_time, payout, currency,
     *                      click_params (decoded parameters_json), extra ($_GET of the postback),
     *                      campaign_url / landing_url / event_source_url (macro values for
     *                      the pixel's event_source_url, resolved by the caller)
     */
    public static function buildPayload(array $pixel, array $click, array $ctx): array
    {
        $clickParams = is_array($ctx['click_params'] ?? null) ? $ctx['click_params'] : [];
        $extra = is_array($ctx['extra'] ?? null) ? $ctx['extra'] : [];

        $userData = [];

        // Unhashed by requirement — Meta uses these for geo/device matching itself.
        if (!empty($click['ip'])) {
            $userData['client_ip_address'] = (string) $click['ip'];
        }
        if (!empty($click['user_agent'])) {
            $userData['client_user_agent'] = (string) $click['user_agent'];
        }

        // fbc/fbp are the highest-signal identifiers available. fbc is reconstructed
        // from fbclid when the cookie never reached us (server-to-server landings).
        $fbc = (string) ($clickParams['fbc'] ?? '');
        if ($fbc === '' && !empty($clickParams['fbclid'])) {
            $clickTime = isset($click['created_at']) ? strtotime((string) $click['created_at']) : time();
            if ($clickTime <= 0) {
                $clickTime = time();
            }
            $fbc = 'fb.1.' . ($clickTime * 1000) . '.' . $clickParams['fbclid'];
        }
        if ($fbc !== '') {
            $userData['fbc'] = $fbc;
        }
        if (!empty($clickParams['fbp'])) {
            $userData['fbp'] = (string) $clickParams['fbp'];
        }

        // Hashed identifiers. Postback-supplied PII (em/ph/fn/ln) takes priority over
        // anything stored on the click.
        $hashed = [
            'em'      => self::normalizeEmail($extra['em'] ?? $extra['email'] ?? $clickParams['em'] ?? ''),
            'ph'      => self::normalizePhone($extra['ph'] ?? $extra['phone'] ?? $clickParams['ph'] ?? ''),
            'fn'      => self::normalizeName($extra['fn'] ?? $extra['first_name'] ?? ''),
            'ln'      => self::normalizeName($extra['ln'] ?? $extra['last_name'] ?? ''),
            'ct'      => self::normalizeName($extra['ct'] ?? $click['city'] ?? ''),
            'st'      => self::normalizeName($extra['st'] ?? $click['region'] ?? ''),
            'zp'      => self::normalizeName($extra['zp'] ?? $click['zipcode'] ?? ''),
            'country' => self::normalizeCountry($extra['country'] ?? $click['country_code'] ?? ''),
        ];
        foreach ($hashed as $key => $value) {
            if ($value !== '') {
                $userData[$key] = [hash('sha256', $value)];
            }
        }

        $event = [
            'event_name'    => (string) $ctx['event_name'],
            'event_time'    => (int) ($ctx['event_time'] ?? time()),
            'action_source' => 'website',
            'user_data'     => $userData,
        ];

        // event_id lets Meta deduplicate against the browser pixel firing the same
        // conversion. Without it a campaign running both sees every sale twice.
        if (!empty($ctx['event_id'])) {
            $event['event_id'] = (string) $ctx['event_id'];
        }

        // event_source_url — the browser-side URL Meta attributes the event to.
        // The operator-configured thank-you/checkout page wins; its
        // {campaign_url}/{landing_url}/{clickid} macros resolve against this
        // click. Unconfigured pixels keep the old chain: explicit ctx value,
        // then the click's referer. Anything that doesn't survive macro
        // substitution as an absolute http(s) URL is dropped rather than sent
        // as a broken literal.
        $configuredUrl = trim((string) ($pixel['event_source_url'] ?? ''));
        if ($configuredUrl !== '') {
            $landingUrl = trim((string) ($ctx['landing_url'] ?? ''));
            if ($landingUrl === '') {
                $landingUrl = trim((string) ($click['referer'] ?? ''));
            }
            $configuredUrl = str_replace(
                ['{campaign_url}', '{landing_url}', '{clickid}'],
                [(string) ($ctx['campaign_url'] ?? ''), $landingUrl, (string) ($click['id'] ?? '')],
                $configuredUrl
            );
        }
        $sourceUrl = $configuredUrl !== ''
            ? $configuredUrl
            : (string) ($ctx['event_source_url'] ?? $click['referer'] ?? '');
        if ($sourceUrl !== '' && preg_match('#^https?://#i', $sourceUrl)) {
            $event['event_source_url'] = $sourceUrl;
        }

        $payout = (float) ($ctx['payout'] ?? 0);
        if ($payout > 0) {
            $event['custom_data'] = [
                'value'    => round($payout, 4),
                'currency' => strtoupper((string) ($ctx['currency'] ?? 'USD')),
            ];
        }

        $payload = ['data' => [$event]];

        $testCode = trim((string) ($pixel['test_event_code'] ?? ''));
        if ($testCode !== '') {
            $payload['test_event_code'] = $testCode;
        }

        return $payload;
    }

    /** Full endpoint including the access token. */
    public static function endpoint(array $pixel): string
    {
        $version = trim((string) ($pixel['api_version'] ?? ''));
        if ($version === '' || !preg_match('/^v\d+\.\d+$/', $version)) {
            $version = self::DEFAULT_API_VERSION;
        }

        return rtrim(self::API_BASE, '/') . '/' . $version . '/'
            . rawurlencode((string) $pixel['pixel_id']) . '/events?'
            . http_build_query(['access_token' => (string) ($pixel['token'] ?? '')]);
    }

    /**
     * Queue the event for delivery by postback_queue_cron.php.
     * Returns false when the pixel is not CAPI-capable or the status maps to nothing.
     */
    public static function enqueue(PDO $pdo, array $pixel, array $click, array $ctx, ?int $conversionId): bool
    {
        if (empty($pixel['pixel_id']) || empty($pixel['token'])) {
            return false; // Browser-only pixel: no server token, nothing to send.
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
                 payload_json, content_type, proxy_url, updated_at)
            VALUES (?, ?, 'POST', 'pending', 0, datetime('now'), NULL, ?, 'application/json', ?, datetime('now'))
        ");
        $stmt->execute([
            $conversionId,
            self::endpoint($pixel),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            trim((string) ($pixel['proxy_url'] ?? '')) ?: null,
        ]);

        return true;
    }

    /**
     * Send immediately, bypassing the queue. Used by the "Send test event" button —
     * an operator pressing test wants the answer now, not in a cron tick.
     *
     * @return array{success:bool,message:string,response:?array}
     */
    public static function send(array $pixel, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init(self::endpoint($pixel));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);

        $proxy = trim((string) ($pixel['proxy_url'] ?? ''));
        if ($proxy !== '') {
            self::applyProxy($ch, $proxy);
        }

        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return ['success' => false, 'message' => 'Transport error: ' . $curlErr, 'response' => null];
        }

        $decoded = is_string($response) ? json_decode($response, true) : null;

        if ($code >= 200 && $code < 300) {
            $received = $decoded['events_received'] ?? 0;
            return [
                'success' => true,
                'message' => "Meta accepted the event (events_received: $received).",
                'response' => is_array($decoded) ? $decoded : null,
            ];
        }

        $message = $decoded['error']['message'] ?? ('HTTP ' . $code);
        if (!empty($decoded['error']['error_user_msg'])) {
            $message .= ' — ' . $decoded['error']['error_user_msg'];
        }

        return ['success' => false, 'message' => $message, 'response' => is_array($decoded) ? $decoded : null];
    }

    /** @param resource|\CurlHandle $ch */
    public static function applyProxy($ch, string $proxy): void
    {
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

        curl_setopt($ch, CURLOPT_PROXY, $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        curl_setopt($ch, CURLOPT_PROXYTYPE, $type);
        if (isset($parts['user'])) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, urldecode($parts['user']) . ':' . urldecode($parts['pass'] ?? ''));
        }
    }

    // ---- Normalisation. Meta hashes must be computed over normalised values,
    // otherwise the hash simply never matches anything on their side. ----

    private static function normalizeEmail($value): string
    {
        $value = strtolower(trim((string) $value));
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    private static function normalizePhone($value): string
    {
        // Digits only, country code included, no leading zeros or punctuation.
        $digits = preg_replace('/\D+/', '', (string) $value);
        return (is_string($digits) && strlen($digits) >= 7) ? ltrim($digits, '0') : '';
    }

    private static function normalizeName($value): string
    {
        $value = trim((string) $value);
        if ($value === '' || strtolower($value) === 'unknown') {
            return '';
        }
        return mb_strtolower($value, 'UTF-8');
    }

    private static function normalizeCountry($value): string
    {
        $value = strtolower(trim((string) $value));
        return preg_match('/^[a-z]{2}$/', $value) ? $value : '';
    }
}
