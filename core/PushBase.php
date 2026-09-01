<?php
/**
 * PushBase — own-VAPID push subscriber base (no intermediaries).
 *
 * Phase 3 owns COLLECTION only: generate/store the VAPID keypair, accept
 * browser subscriptions (POST /push_subscribe), keep them in
 * push_subscriptions. Sending lives in a later phase (PushSender); the
 * private key is stored from day one so that phase needs no re-subscription
 * from the base.
 *
 * Keys are plain `settings` rows ('vapid_public_key' / 'vapid_private_key' /
 * 'vapid_private_pem' — the PEM export PushSender signs with), written
 * directly via PDO — NOT through the global_settings whitelist API,
 * which is for the System Settings UI and would silently drop unknown keys.
 *
 * VAPID application keys are EC P-256:
 *   public  = base64url( 0x04 || X(32) || Y(32) )  — the uncompressed point
 *   private = base64url( D(32) )
 * The browser receives the public key as applicationServerKey.
 */

class PushBase
{
    public const PUBLIC_KEY_SETTING = 'vapid_public_key';
    public const PRIVATE_KEY_SETTING = 'vapid_private_key';
    /** PEM export of the private key (phase 4) — openssl needs the wrapped form. */
    public const PRIVATE_PEM_SETTING = 'vapid_private_pem';

    /**
     * Generate a fresh P-256 keypair. Returns base64url strings ready for
     * storage / the browser, plus the PKCS8 PEM export PushSender signs with.
     */
    public static function generateKeys(): array
    {
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if ($res === false) {
            throw new RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
        }
        $details = openssl_pkey_get_details($res);
        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            throw new RuntimeException('openssl key details missing EC components');
        }
        // openssl hands X/Y/D out as raw 32-byte binary strings; pad-left for
        // defense against implementations that strip leading zero bytes.
        $x = self::binPad($details['ec']['x']);
        $y = self::binPad($details['ec']['y']);
        $d = self::binPad($details['ec']['d']);

        if (!openssl_pkey_export($res, $pem)) {
            throw new RuntimeException('openssl_pkey_export failed: ' . openssl_error_string());
        }

        return [
            'public'      => self::base64Url("\x04" . $x . $y),
            'private'     => self::base64Url($d),
            'private_pem' => $pem,
        ];
    }

    /** @return array{public:string, private:string}|array{} */
    public static function getKeys(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT key, value FROM settings WHERE key IN ('" . self::PUBLIC_KEY_SETTING . "', '" . self::PRIVATE_KEY_SETTING . "')");
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[$r['key']] = (string) $r['value'];
        }
        if (empty($rows[self::PUBLIC_KEY_SETTING]) || empty($rows[self::PRIVATE_KEY_SETTING])) {
            return [];
        }
        return ['public' => $rows[self::PUBLIC_KEY_SETTING], 'private' => $rows[self::PRIVATE_KEY_SETTING]];
    }

    public static function getPublicKey(PDO $pdo): string
    {
        $keys = self::getKeys($pdo);
        return $keys['public'] ?? '';
    }

    public static function storeKeys(PDO $pdo, array $keys): void
    {
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute([self::PUBLIC_KEY_SETTING, $keys['public']]);
        $stmt->execute([self::PRIVATE_KEY_SETTING, $keys['private']]);
        if (!empty($keys['private_pem'])) {
            // Phase 4 (PushSender) signs with openssl — it needs the wrapped
            // form, so the PEM is persisted alongside the raw scalar.
            $stmt->execute([self::PRIVATE_PEM_SETTING, $keys['private_pem']]);
        }
    }

    /**
     * Private key as PEM for openssl_sign/openssl_pkey_get_private. Reads the
     * 'vapid_private_pem' setting; for keys generated BEFORE that export
     * existed it reconstructs the SEC1 PEM from the raw base64url pieces
     * (ASN.1 template in PushSender::privatePemFromRaw) and backfills the
     * setting, so the rebuild happens at most once per install.
     */
    public static function getPrivatePem(PDO $pdo): string
    {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([self::PRIVATE_PEM_SETTING]);
        $pem = (string) $stmt->fetchColumn();
        if ($pem !== '' && strpos($pem, '-----BEGIN') === 0) {
            return $pem;
        }

        $keys = self::getKeys($pdo);
        if ($keys === []) {
            return '';
        }
        require_once __DIR__ . '/PushSender.php';
        $d = self::rawDecode($keys['private']);
        $pub = self::rawDecode($keys['public']);
        if (strlen($d) !== 32 || strlen($pub) !== 65) {
            return '';
        }
        $pem = PushSender::privatePemFromRaw($d, $pub);
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute([self::PRIVATE_PEM_SETTING, $pem]);
        return $pem;
    }

    private static function rawDecode(string $b64): string
    {
        $bin = base64_decode(strtr($b64, '-_', '+/'), true);
        return $bin === false ? '' : $bin;
    }

    public static function base64Url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function binPad(string $bin): string
    {
        return str_pad($bin, 32, "\x00", STR_PAD_LEFT);
    }
}
