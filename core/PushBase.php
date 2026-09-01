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
 * Keys are plain `settings` rows ('vapid_public_key' / 'vapid_private_key'),
 * written directly via PDO — NOT through the global_settings whitelist API,
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

    /**
     * Generate a fresh P-256 keypair. Returns base64url strings ready for
     * storage / the browser.
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

        return [
            'public'  => self::base64Url("\x04" . $x . $y),
            'private' => self::base64Url($d),
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
