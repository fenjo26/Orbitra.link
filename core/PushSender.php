<?php
/**
 * PushSender — Web Push delivery for the own-VAPID base (phase 4).
 *
 * Pure PHP on openssl/json — no composer, no web-push-php. Implements the two
 * RFCs the browser vendors enforce:
 *   RFC 8291 — message encryption, content-encoding "aes128gcm";
 *   RFC 8292 — VAPID auth, JWT ES256 + the P-256 key thumbprint.
 *
 * The ECDH/ECDSA math is done by openssl (openssl_pkey_derive /
 * openssl_sign). The only hand-rolled parts are ASN.1 template wrapping (raw
 * P-256 points → PEM, DER signature → raw r||s) and HKDF-SHA256.
 *
 * Delivery semantics (the push_queue worker maps these to statuses):
 *   201        sent;
 *   404/410    endpoint gone → the subscription ages out (is_active = 0);
 *   429        rate limited → honor Retry-After, back into the queue;
 *   401/403    one retry with a freshly minted JWT, then a hard error;
 *   5xx / I/O  back into the queue.
 *
 * Randomness is behind randomBytes()/generateEphemeralKey() so the unit test
 * can pin salt + ephemeral key deterministically; production callers use the
 * real randomness.
 */

class PushSender
{
    /** mailto contact for the VAPID "sub" claim, a plain settings row. */
    public const VAPID_SUB_SETTING = 'push_vapid_sub';
    /** Fallback when the setting is absent — must still look like a contact. */
    public const VAPID_SUB_DEFAULT = 'mailto:orbitra@localhost';

    /** VAPID JWT lifetime: 12 hours, as recommended by RFC 8292 §5. */
    public const JWT_TTL_SECONDS = 43200;
    /** aes128gcm record size. Payload + padding + tag must stay below rs-17. */
    public const RECORD_SIZE = 4096;
    /** Push-service TTL header (28 days): undelivered pushes expire quietly. */
    public const TTL_HEADER_SECONDS = 2419200;

    private const DER_OID_PRIME256V1 = "\x2a\x86\x48\xce\x3d\x03\x01\x07";

    // ------------------------------------------------------------------
    // VAPID (RFC 8292)
    // ------------------------------------------------------------------

    /**
     * Build the Authorization header payload for one endpoint.
     *
     * @param array  $keys     PushBase keypair ('public'/'private' base64url)
     * @param string $endpoint push-service URL
     * @param string $sub      "mailto:…" contact (or https:)
     * @return array{jwt:string, key:string} — header is
     *         "Authorization: vapid t=<jwt>, k=<key>"
     */
    public static function buildVapidAuth(array $keys, string $endpoint, string $sub): array
    {
        $claims = [
            'aud' => self::vapidAudience($endpoint),
            'exp' => time() + self::JWT_TTL_SECONDS,
            'sub' => $sub !== '' ? $sub : self::VAPID_SUB_DEFAULT,
        ];
        $input = self::base64Url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            . '.' . self::base64Url(json_encode($claims));
        return [
            'jwt' => $input . '.' . self::base64Url(self::signEs256($input, $keys)),
            'key' => self::vapidKeyThumbprint($keys['public'] ?? ''),
        ];
    }

    /** aud is only scheme+host — the push service matches on that, not the path. */
    public static function vapidAudience(string $endpoint): string
    {
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new RuntimeException('push endpoint has no host: ' . $endpoint);
        }
        $scheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($endpoint, PHP_URL_PORT);
        return $scheme . '://' . $host . ($port ? ':' . $port : '');
    }

    /** Sign with the EC key (PEM or raw-32-byte D), return raw 64-byte r||s. */
    public static function signEs256(string $data, array $keys): string
    {
        $pem = $keys['private_pem'] ?? '';
        if ($pem === '') {
            $d = self::base64UrlDecode((string) ($keys['private'] ?? ''));
            if (strlen($d) !== 32) {
                throw new RuntimeException('VAPID private key missing for signing');
            }
            $pem = self::privatePemFromRaw($d, self::base64UrlDecode((string) ($keys['public'] ?? '')));
        }
        $res = openssl_pkey_get_private($pem);
        if ($res === false) {
            throw new RuntimeException('openssl_pkey_get_private failed: ' . openssl_error_string());
        }
        $der = '';
        if (openssl_sign($data, $der, $res, OPENSSL_ALGO_SHA256) === false) {
            throw new RuntimeException('openssl_sign failed: ' . openssl_error_string());
        }
        return self::derSignatureToRaw($der);
    }

    /**
     * DER SEQUENCE { INTEGER r, INTEGER s } → raw 64-byte r||s. Both integers
     * are ≤ 33 bytes for P-256, so only the short DER length form appears.
     */
    public static function derSignatureToRaw(string $der): string
    {
        $pos = 0;
        $fail = static function () {
            throw new RuntimeException('malformed DER ECDSA signature');
        };
        if (strlen($der) < 6 || ord($der[$pos++]) !== 0x30) {
            $fail();
        }
        $seqLen = ord($der[$pos++]);
        if ($seqLen & 0x80) {
            $fail(); // long form never occurs at 70 bytes, reject instead of guess
        }
        $readInt = function () use ($der, &$pos, $fail): string {
            if ($pos + 2 > strlen($der) || ord($der[$pos]) !== 0x02) {
                $fail();
            }
            $len = ord($der[$pos + 1]);
            if ($len & 0x80 || $pos + 2 + $len > strlen($der)) {
                $fail();
            }
            $num = substr($der, $pos + 2, $len);
            $pos += 2 + $len;
            // DER integers are signed and may carry a leading 0x00 — strip,
            // then pad-left to the fixed 32-byte field.
            $num = ltrim($num, "\x00");
            return str_pad($num, 32, "\x00", STR_PAD_LEFT);
        };
        $r = $readInt();
        $s = $readInt();
        return $r . $s;
    }

    /**
     * RFC 7638 JWK thumbprint of the P-256 public key: SHA-256 over the
     * lexicographically-canonical JSON {"crv","kty","x","y"}, base64url.
     * The same string goes into the "k=" Authorization parameter.
     */
    public static function vapidKeyThumbprint(string $publicKeyB64Url): string
    {
        $raw = self::base64UrlDecode($publicKeyB64Url);
        if (strlen($raw) !== 65 || $raw[0] !== "\x04") {
            throw new RuntimeException('public key is not an uncompressed P-256 point');
        }
        $jwk = '{"crv":"P-256","kty":"EC","x":"' . self::base64Url(substr($raw, 1, 32))
            . '","y":"' . self::base64Url(substr($raw, 33, 32)) . '"}';
        return self::base64Url(hash('sha256', $jwk, true));
    }

    /** "Authorization: vapid t=…, k=…" header value. */
    public static function vapidHeader(array $auth): string
    {
        return 'vapid t=' . $auth['jwt'] . ', k=' . $auth['key'];
    }

    // ------------------------------------------------------------------
    // Key wrapping (ASN.1 templates)
    // ------------------------------------------------------------------

    /**
     * SEC1 "EC PRIVATE KEY" PEM from raw D + raw uncompressed public point.
     * For keys generated before this upgrade only the raw base64url pieces
     * exist in settings — openssl needs them wrapped (known template).
     */
    public static function privatePemFromRaw(string $dRaw, string $publicRaw): string
    {
        if (strlen($dRaw) !== 32 || strlen($publicRaw) !== 65 || $publicRaw[0] !== "\x04") {
            throw new RuntimeException('cannot wrap private key: bad raw components');
        }
        $der = "\x30\x77"                      // SEQUENCE, 119 bytes
            . "\x02\x01\x01"                   // INTEGER 1 (version)
            . "\x04\x20" . $dRaw               // OCTET STRING privkey
            . "\xa0\x0a\x06\x08" . self::DER_OID_PRIME256V1 // [0] curve OID
            . "\xa1\x44\x03\x42\x00" . $publicRaw;          // [1] BIT STRING pubkey
        return self::pem($der, 'EC PRIVATE KEY');
    }

    /** SPKI "PUBLIC KEY" PEM from a raw 65-byte uncompressed P-256 point. */
    public static function publicPemFromRaw(string $publicRaw): string
    {
        if (strlen($publicRaw) !== 65 || $publicRaw[0] !== "\x04") {
            throw new RuntimeException('cannot wrap public key: bad raw point');
        }
        $der = "\x30\x59"                      // SEQUENCE, 89 bytes
            . "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" // id-ecPublicKey
            . "\x06\x08" . self::DER_OID_PRIME256V1          // prime256v1
            . "\x03\x42\x00" . $publicRaw;                   // BIT STRING point
        return self::pem($der, 'PUBLIC KEY');
    }

    private static function pem(string $der, string $label): string
    {
        return "-----BEGIN $label-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END $label-----\n";
    }

    // ------------------------------------------------------------------
    // Payload encryption (RFC 8291, aes128gcm)
    // ------------------------------------------------------------------

    /**
     * Encrypt one record. Body layout:
     *   salt(16) || rs(u32be) || ids(u8=0) || ciphertext(=aes-128-gcm, tag at end)
     *
     * @param string      $payload       plaintext (already macro-expanded JSON)
     * @param string      $clientPubB64  subscription p256dh (base64url)
     * @param string      $authB64       subscription auth secret (base64url)
     * @param string|null $salt          16-byte salt override (tests)
     * @param mixed       $ephPriv       ephemeral private key override (tests)
     * @return string aes128gcm record
     */
    public static function encrypt(string $payload, string $clientPubB64, string $authB64, ?string $salt = null, $ephPriv = null): string
    {
        $clientRaw = self::base64UrlDecode($clientPubB64);
        $auth = self::base64UrlDecode($authB64);
        if (strlen($clientRaw) !== 65 || $clientRaw[0] !== "\x04") {
            throw new RuntimeException('subscription p256dh is not a valid P-256 point');
        }
        if ($auth === '' || strlen($auth) < 16) {
            throw new RuntimeException('subscription auth secret too short');
        }
        // One record carries rs-17 ciphertext bytes, of which 16 are the GCM
        // tag and 1 the padding delimiter → payload must fit rs-34.
        $maxPayload = self::RECORD_SIZE - 34;
        if (strlen($payload) > $maxPayload) {
            throw new RuntimeException('payload exceeds the aes128gcm record limit');
        }

        $salt = $salt !== null ? $salt : static::randomBytes(16);
        $eph = $ephPriv ?? static::generateEphemeralKey();
        $ephDetails = openssl_pkey_get_details($eph);
        if ($ephDetails === false) {
            throw new RuntimeException('ephemeral key details unavailable');
        }
        $ephPubRaw = "\x04"
            . str_pad($ephDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($ephDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        $clientPem = self::publicPemFromRaw($clientRaw);
        $as = self::deriveSharedSecret($eph, $clientPem);

        // RFC 8291 §4.2 — ikm = auth_secret || ecdh_secret, then one HKDF run
        // with the salt from the record header, then CEK/nonce runs saltless.
        $ikm = $auth . $as;
        $prk = self::hkdfSha256(
            $ikm,
            $salt,
            'WebPush: info' . "\x00" . $clientRaw . $ephPubRaw,
            32
        );
        $cek = self::hkdfSha256($prk, '', "Content-Encoding: aes128gcm\x01", 16);
        $nonce = self::hkdfSha256($prk, '', "Content-Encoding: nonce\x01", 12);

        // Padding: payload || 0x02 || zeros — padded plaintext fills exactly
        // rs-17-16 bytes, so ciphertext + tag is exactly rs-17 (RFC 8291 §2).
        $padded = $payload . "\x02" . str_repeat("\x00", $maxPayload - strlen($payload));
        $tag = '';
        $ct = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ct === false) {
            throw new RuntimeException('openssl_encrypt failed: ' . openssl_error_string());
        }
        return $salt . pack('N', self::RECORD_SIZE) . "\x00" . $ct . $tag;
    }

    /**
     * ECDH shared secret: ephemeral private key × peer public PEM. The
     * documented signature is derive(priv, peer), but some PHP 8.5 builds
     * swap the roles and reject a bare PEM string as the peer — try the
     * documented order first, then the flipped one (exactly one works on
     * every build; a wrong pairing returns false without touching state).
     * The P-256 secret is 32 bytes, no explicit length needed.
     */
    private static function deriveSharedSecret($privKey, string $peerPubPem): string
    {
        $secret = openssl_pkey_derive($privKey, $peerPubPem);
        if (!is_string($secret) || $secret === '') {
            $peer = openssl_pkey_get_public($peerPubPem);
            if ($peer === false) {
                throw new RuntimeException('peer public key unreadable: ' . openssl_error_string());
            }
            $secret = openssl_pkey_derive($peer, $privKey);
        }
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('openssl_pkey_derive failed: ' . openssl_error_string());
        }
        return $secret;
    }

    /** RFC 5869 HKDF with SHA-256 (extract-then-expand). */
    public static function hkdfSha256(string $ikm, string $salt, string $info, int $length): string    {
        if ($length <= 0 || $length > 255 * 32) {
            throw new RuntimeException('HKDF output length out of range');
        }
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $okm = '';
        $prev = '';
        $counter = 1;
        while (strlen($okm) < $length) {
            $prev = hash_hmac('sha256', $prev . $info . chr($counter++), $prk, true);
            $okm .= $prev;
        }
        return substr($okm, 0, $length);
    }

    // ------------------------------------------------------------------
    // Delivery
    // ------------------------------------------------------------------

    /**
     * Send one message to one subscription. Macros are expanded HERE, at send
     * time — push_queue keeps the raw text.
     *
     * @param array $subscription push_subscriptions row
     * @param array $message      push_messages row (raw title/text/link)
     * @return array{ok:bool, code:?int, dead:bool, retryable:bool, retry_after:?int, error:?string}
     */
    public static function send(PDO $pdo, array $subscription, array $message): array
    {
        $keys = PushBase::getKeys($pdo);
        if ($keys === []) {
            return self::result(false, null, false, false, null, 'VAPID keys are not generated');
        }

        $subid = (string) ($subscription['click_id'] ?? '');
        $title = PushMacros::expand((string) ($message['title'] ?? ''), $subid);
        $body = PushMacros::expand((string) ($message['text'] ?? ''), $subid);
        $link = PushMacros::expand((string) ($message['link_url'] ?? ''), $subid);
        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'icon'  => (string) ($message['icon_url'] ?? ''),
            'data'  => ['url' => $link],
        ], JSON_UNESCAPED_UNICODE);

        $sub = self::getVapidSub($pdo);
        try {
            $auth = self::buildVapidAuth($keys, (string) $subscription['endpoint'], $sub);
            $record = self::encrypt($payload, (string) $subscription['p256dh'], (string) $subscription['auth']);
        } catch (\Throwable $e) {
            return self::result(false, null, false, false, null, 'encrypt failed: ' . $e->getMessage());
        }

        // 401/403 get exactly one retry with a fresh JWT (clock drift, cached
        // auth header upstream) — then it is a hard failure.
        $response = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = self::httpPost((string) $subscription['endpoint'], $record, [
                'TTL: ' . self::TTL_HEADER_SECONDS,
                'Urgency: normal',
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'Authorization: ' . self::vapidHeader($auth),
            ]);
            if (!in_array($response['code'], [401, 403], true)) {
                break;
            }
            try {
                $auth = self::buildVapidAuth($keys, (string) $subscription['endpoint'], $sub);
            } catch (\Throwable $e) {
                return self::result(false, $response['code'], false, false, null, 'auth failed: ' . $e->getMessage());
            }
        }

        $code = $response['code'];
        if ($code >= 200 && $code < 300) {
            return self::result(true, $code, false, false, null, null);
        }
        if ($code === 404 || $code === 410) {
            // Endpoint gone — the subscription ages out here (no extra cron).
            return self::result(false, $code, true, false, null, 'endpoint gone');
        }
        if ($code === 429) {
            return self::result(false, $code, false, true, $response['retry_after'], 'rate limited');
        }
        if ($code === 0 || $code >= 500) {
            // 0 = transport failure (DNS/connect/timeout) — as retryable as a 5xx.
            return self::result(false, $code ?: null, false, true, null, 'push service unreachable');
        }
        return self::result(false, $code, false, false, null, 'rejected: ' . ($response['error'] ?? ('HTTP ' . $code)));
    }

    private static function result(bool $ok, ?int $code, bool $dead, bool $retryable, ?int $retryAfter, ?string $error): array
    {
        return ['ok' => $ok, 'code' => $code, 'dead' => $dead, 'retryable' => $retryable, 'retry_after' => $retryAfter, 'error' => $error];
    }

    /**
     * cURL POST. TLS verification stays on (repo-wide rule); network errors
     * are reported as code 0 and treated as retryable by the queue worker.
     */
    public static function httpPost(string $endpoint, string $body, array $headers): array
    {
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['code' => 0, 'retry_after' => null, 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['code' => 0, 'retry_after' => null, 'error' => $err];
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $retryAfter = null;
        foreach (explode("\r\n", substr((string) $raw, 0, $headerSize)) as $line) {
            if (stripos($line, 'retry-after:') === 0) {
                $val = trim(substr($line, strlen('retry-after:')));
                if (ctype_digit($val)) {
                    $retryAfter = (int) $val; // seconds form; HTTP-date form ignored
                }
            }
        }
        return ['code' => $code, 'retry_after' => $retryAfter, 'error' => null];
    }

    /** VAPID contact from settings ('mailto:…'), with a safe default. */
    public static function getVapidSub(PDO $pdo): string
    {
        try {
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
            $stmt->execute([self::VAPID_SUB_SETTING]);
            $val = trim((string) $stmt->fetchColumn());
        } catch (\Throwable $e) {
            $val = '';
        }
        if ($val !== '' && (stripos($val, 'mailto:') === 0 || stripos($val, 'https:') === 0)) {
            return $val;
        }
        return self::VAPID_SUB_DEFAULT;
    }

    // ------------------------------------------------------------------
    // Randomness seam — tests subclass these two to pin salt + ephemeral.
    // ------------------------------------------------------------------

    protected static function randomBytes(int $length): string
    {
        return random_bytes($length);
    }

    /** @return OpenSSLAsymmetricKey fresh ephemeral P-256 keypair */
    protected static function generateEphemeralKey()
    {
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if ($res === false) {
            throw new RuntimeException('ephemeral openssl_pkey_new failed: ' . openssl_error_string());
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // base64url
    // ------------------------------------------------------------------

    public static function base64Url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $b64): string
    {
        $bin = base64_decode(strtr($b64, '-_', '+/'), true);
        return $bin === false ? '' : $bin;
    }
}
