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
// blocking the postback response: Meta occasionally answers slowly. The incoming
// postback transaction reuses an existing event intent when the network retries.
//
// Personally identifiable fields are SHA-256 hashed before they leave the server.
// IP, user agent and the opaque fbc/fbp identifiers must be sent unhashed.

require_once __DIR__ . '/MetaCapiResponse.php';

if (!function_exists('orbitraPostbackTransactionActive')) {
    /**
     * Whether a SQLite transaction is really open on this connection.
     *
     * PHP < 8.4 cannot see a transaction started with a raw BEGIN through
     * PDO::exec() — inTransaction() stays false for its whole life (php bug
     * #81227, only fixed in 8.4). The transaction guards in this file exist
     * to detect an automatic SQLite rollback mid-transaction, so they must
     * ask the real state, not PDO's tracking. Canonical copy lives in
     * core/PostbackDelivery.php; the function_exists guard keeps this file
     * loadable on its own (test fixtures copy files one by one).
     */
    function orbitraPostbackTransactionActive(PDO $pdo): bool
    {
        if (PHP_VERSION_ID >= 80400) {
            return $pdo->inTransaction();
        }
        try {
            $started = $pdo->exec('BEGIN DEFERRED');
            if ($started === false) {
                // Non-exception error mode: the failure text is in errorInfo().
                return str_contains((string) ($pdo->errorInfo()[2] ?? ''), 'within a transaction');
            }
        } catch (\Throwable $e) {
            return str_contains($e->getMessage(), 'within a transaction');
        }
        try {
            $pdo->exec('ROLLBACK'); // undoes only the probe's own BEGIN
        } catch (\Throwable $e) {
            // Nothing left to undo; the connection is still in autocommit.
        }
        return false;
    }
}

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
     *                      campaign_url / landing_url / offer_url / event_source_url (macro values for
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

        // This identity was established at acquisition and can be shared with
        // the browser Pixel. The legacy external_id is often a traffic-source
        // click ID, not a visitor ID. Never create/replace identity at postback.
        $visitorId = $clickParams['meta_external_id'] ?? '';
        if (is_string($visitorId) && $visitorId !== '' && strlen($visitorId) <= 512
            && !preg_match('/[\x00-\x20\x7f]/', $visitorId)) {
            $userData['external_id'] = [hash('sha256', $visitorId)];
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
        // The operator-configured thank-you/checkout page wins.
        // A campaign may opt into a JSON object keyed by the final Meta event
        // name; ordinary URL strings and existing macros remain compatible.
        // Only an absent event key permits the explicit postback URL fallback.
        // An empty/invalid configured value does not silently change pages.
        // Otherwise only an explicitly supplied event URL is truthful:
        // the acquisition referrer (often Facebook/Google) and the landing page
        // do not identify a conversion that happened on the partner's checkout.
        $configuredUrl = is_string($pixel['event_source_url'] ?? null)
            ? trim($pixel['event_source_url']) : '';
        $hasConfiguredUrl = $configuredUrl !== '';
        $eventUrls = json_decode($configuredUrl);
        if ($eventUrls instanceof \stdClass) {
            $eventUrls = get_object_vars($eventUrls);
            $hasConfiguredUrl = array_key_exists($event['event_name'], $eventUrls);
            $configuredUrl = $hasConfiguredUrl && is_string($eventUrls[$event['event_name']])
                ? trim($eventUrls[$event['event_name']]) : '';
        }
        if ($configuredUrl !== '') {
            $landingUrl = $ctx['landing_url'] ?? $clickParams['landing_page_url'] ?? '';
            $landingUrl = is_string($landingUrl) ? $landingUrl : '';
            $offerUrl = is_string($ctx['offer_url'] ?? null) ? $ctx['offer_url'] : '';
            // A required offer macro cannot turn into a different valid URL
            // merely because its missing/invalid value was replaced with ''.
            if (str_contains($configuredUrl, '{offer_url}') && self::eventSourceUrl($offerUrl) === '') {
                $configuredUrl = '';
            }
            $configuredUrl = str_replace(
                ['{campaign_url}', '{landing_url}', '{offer_url}', '{clickid}'],
                [(string) ($ctx['campaign_url'] ?? ''), $landingUrl, $offerUrl, (string) ($click['id'] ?? '')],
                $configuredUrl
            );
        }
        $sourceUrl = $hasConfiguredUrl
            ? $configuredUrl
            : ($ctx['event_source_url'] ?? $extra['event_source_url'] ?? '');
        $sourceUrl = self::eventSourceUrl($sourceUrl);
        if ($sourceUrl !== '') {
            $event['event_source_url'] = $sourceUrl;
        }

        $payout = (float) ($ctx['payout'] ?? 0);
        $contentId = trim((string) ($ctx['content_id'] ?? ''));
        $customData = [];
        if ($payout > 0) {
            $customData['value'] = round($payout, 4);
            $customData['currency'] = strtoupper((string) ($ctx['currency'] ?? 'USD'));
        }
        // Symmetry with the TikTok side (see TikTokConversions.php) — Meta uses
        // content_ids for catalog / dynamic-ads matching even though it is not
        // flagged as critical the way TikTok's diagnostics flag it.
        if ($contentId !== '') {
            $customData['content_type'] = 'product';
            $customData['content_ids'] = [$contentId];
        }
        if (!empty($customData)) {
            $event['custom_data'] = $customData;
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
            // No queue row, no HTTP call, no trace. That is correct for statuses
            // deliberately mapped to nothing (rejected/trash) and silent data loss for
            // everything else: a custom conversion type named after a lead status --
            // "hold" in COD -- shadows the built-in mapping and drops every lead.
            // Always leave a line behind so the loss is diagnosable.
            self::logSkippedStatus($pdo, $pixel, (string) ($ctx['status'] ?? ''), $conversionId);
            return false;
        }

        $ctx['event_name'] = $event;
        $payload = self::buildPayload($pixel, $click, $ctx);

        // Do not silently substitute a different page for required website
        // context. Keep the durable delivery intent and expose the integration
        // issue; the partner/operator must supply the real conversion URL.
        if (empty($payload['data'][0]['event_source_url'])) {
            $inTransaction = orbitraPostbackTransactionActive($pdo);
            try {
                $pdo->prepare('INSERT INTO system_logs (level, message, context) VALUES (?, ?, ?)')->execute([
                    'WARNING',
                    'Facebook CAPI: missing or invalid event_source_url. Configure the actual event page or supply event_source_url in the postback; acquisition referrers are not conversion URLs.',
                    json_encode(['pixel_id' => (string) $pixel['pixel_id'], 'conversion_id' => $conversionId]),
                ]);
            } catch (\Throwable $e) {
                if ($inTransaction && !orbitraPostbackTransactionActive($pdo)) {
                    throw $e; // Do not enqueue in autocommit after SQLite rolled back.
                }
                // Diagnostics do not replace or prevent the durable queue write.
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO s2s_postbacks_log
                (conversion_id, url, method, status, attempts, next_retry_at, postback_id,
                 payload_json, content_type, proxy_url, updated_at)
            VALUES (?, ?, 'POST', 'pending', 0, datetime('now'), NULL, ?, 'application/json', ?, datetime('now'))
        ");
        $stmt->execute([
            $conversionId,
            self::endpoint($pixel),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            trim((string) ($pixel['proxy_url'] ?? '')) ?: null,
        ]);

        return true;
    }

    /**
     * Record a conversion that produced no CAPI event.
     *
     * A status explicitly mapped to '' is an operator decision and logs at INFO.
     * A status with no mapping at all logs at WARNING: it is nearly always a
     * misconfiguration, and the one that bites hardest is a custom conversion type
     * whose name collides with a built-in status.
     */
    private static function logSkippedStatus(PDO $pdo, array $pixel, string $status, ?int $conversionId): void
    {
        $inTransaction = orbitraPostbackTransactionActive($pdo);
        try {
            $needle = strtolower(trim($status));
            if ($needle === '') {
                return;
            }

            $mapping = [];
            if (!empty($pixel['mapping_json'])) {
                $decoded = json_decode((string) $pixel['mapping_json'], true);
                if (is_array($decoded)) {
                    $mapping = array_change_key_case($decoded, CASE_LOWER);
                }
            }
            $deliberate = array_key_exists($needle, $mapping)
                || array_key_exists($needle, self::defaultMapping());

            $stmt = $pdo->prepare("INSERT INTO system_logs (level, message, context) VALUES (?, ?, ?)");
            $stmt->execute([
                $deliberate ? 'INFO' : 'WARNING',
                $deliberate
                    ? "Facebook CAPI: status '{$status}' is mapped to no Meta event - conversion not sent."
                    : "Facebook CAPI: status '{$status}' has no Meta event mapping - conversion NOT sent. "
                        . "A custom conversion type named after a built-in status shadows the built-in mapping.",
                json_encode([
                    'pixel_id'      => (string) ($pixel['pixel_id'] ?? ''),
                    'conversion_id' => $conversionId,
                    'status'        => $status,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            if ($inTransaction && !orbitraPostbackTransactionActive($pdo)) {
                throw $e;
            }
            // Logging must never break delivery.
        }
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
        // graph.facebook.com publishes AAAA records that are unroutable from plenty of
        // hosts. curl tries v6 first, waits out the connect timeout, then falls back --
        // which reads as "Resolving timed out" on an otherwise healthy box. Pin v4 and
        // leave enough room for a slow proxy handshake.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);

        $proxy = trim((string) ($pixel['proxy_url'] ?? ''));
        if ($proxy !== '') {
            self::applyProxy($ch, $proxy);
        }

        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $result = MetaCapiResponse::evaluate($code, $response, $curlErr,
            is_array($payload['data'] ?? null) ? count($payload['data']) : 0,
            ['url' => self::endpoint($pixel), 'payload' => $payload, 'proxy_url' => $proxy]);
        return ['success' => $result['success'], 'message' => $result['message'], 'response' => $result['response']];
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

    private static function eventSourceUrl($value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 8192
            || preg_match('/[\x00-\x20\x7f{}]/', $value)) {
            return '';
        }
        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['host'])
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        return $value;
    }

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
