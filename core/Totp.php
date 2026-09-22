<?php
// core/Totp.php
// RFC 6238 time-based one-time passwords (TOTP) for the panel's two-factor
// login (external audit item #20), plus the RFC 4648 Base32 codec the secrets
// are stored and shared in.
//
// Design notes:
//  * Core PHP only: hash_hmac() and hash_equals() cover everything, so the
//    class works on any install regardless of loaded extensions (no gmp/bcmath).
//  * Codes are compared with hash_equals() only — a plain == would leak the
//    match position through timing.
//  * verifyCode() accepts the neighbouring time windows (t-1, t, t+1) so a
//    clock drift of up to 30 seconds between the server and the authenticator
//    does not lock the operator out.

class Totp
{
    /** Seconds one code is valid (RFC 6238 default). */
    private const PERIOD = 30;
    /** Unix epoch the counters start from (RFC 6238 T0). */
    private const EPOCH = 0;

    /** RFC 4648 Base32 alphabet. */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * New random secret for enrollment: 20 random bytes base32-encoded
     * (32 characters, no padding — what authenticator apps expect to type
     * or scan). One call per totp_setup; the caller stores it unconfirmed
     * until a first valid code proves the user scanned it correctly.
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** RFC 4648 Base32 encode (no padding). */
    public static function base32Encode(string $bytes): string
    {
        $output = '';
        $buffer = 0;
        $bits = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $buffer = (($buffer << 8) | ord($bytes[$i])) & 0xFFFF;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $output .= self::BASE32_ALPHABET[($buffer >> $bits) & 0x1F];
            }
        }
        // Partial trailing group: pad with zero bits on the right.
        if ($bits > 0) {
            $output .= self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 0x1F];
        }
        return $output;
    }

    /**
     * RFC 4648 Base32 decode. Tolerant on input: case-insensitive, padding
     * ('='), spaces and dashes are ignored — the same secret may arrive
     * hand-typed or pasted from an app that groups characters. Characters
     * outside the alphabet (0, 1, 8, 9, punctuation) are dropped rather than
     * fataling; an empty result yields '' which every caller treats as invalid.
     */
    public static function base32Decode(string $encoded): string
    {
        $encoded = preg_replace('/[^A-Za-z2-7]/', '', $encoded) ?? '';
        if ($encoded === '') {
            return '';
        }
        $output = '';
        $buffer = 0;
        $bits = 0;
        for ($i = 0, $n = strlen($encoded); $i < $n; $i++) {
            $pos = strpos(self::BASE32_ALPHABET, strtoupper($encoded[$i]));
            if ($pos === false) {
                continue; // unreachable after the regexp, kept as a guard
            }
            $buffer = (($buffer << 5) | $pos) & 0xFFFF;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xFF);
            }
        }
        return $output;
    }

    /**
     * The otpauth:// URI an authenticator app scans as a QR code.
     * Both the label and the issuer are percent-encoded: usernames may
     * contain characters that are illegal raw in a URI.
     */
    public static function otpauthUri(string $secret, string $account, string $issuer = 'Orbitra'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer);
    }

    /**
     * The HOTP value (RFC 4226) for one counter, as a zero-padded digit string.
     * SHA-1, the algorithm every mainstream authenticator defaults to.
     */
    public static function codeAtCounter(string $secret, int $counter, int $digits = 6): ?string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return null;
        }
        return self::hotp($key, $counter, $digits);
    }

    /**
     * Verify a user-supplied code against the current time window and the
     * $window neighbours on either side (RFC 6238 recommends ±1).
     *
     * Input handling: surrounding whitespace is trimmed, the code must be all
     * digits (6-8, the lengths RFC 4226/6238 define). Leading zeros matter —
     * the comparison is the padded string, never the numeric value, and
     * non-numeric input of any shape ("12a456", " 123456  ", "-123456") fails.
     *
     * @return bool true when the code matches for t-1, t or t+1
     */
    public static function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        return self::matchCounter($secret, $code, $window) !== null;
    }

    /**
     * Like verifyCode(), but returns the time-step counter the code matched
     * (null = no match). Callers use it to refuse a second login with the same
     * code (RFC 6238 §5.2: a verifier MUST NOT accept a code twice).
     */
    public static function matchCounter(string $secret, string $code, int $window = 1): ?int
    {
        $code = trim($code);
        if (!preg_match('/^\d{6,8}$/', $code)) {
            return null;
        }
        $key = self::base32Decode($secret);
        if ($key === '') {
            return null;
        }
        $counter = (int) floor((time() - self::EPOCH) / self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            $candidate = self::hotp($key, $counter + $i, strlen($code));
            if (hash_equals($candidate, $code)) {
                return $counter + $i;
            }
        }
        return null;
    }

    /** Dynamic truncation + modulus per RFC 4226 §5.3. */
    private static function hotp(string $key, int $counter, int $digits): string
    {
        // 8-byte big-endian counter (pack 'J' needs 64-bit builds; N* pair is portable).
        $binCounter = pack('N*', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }
}
