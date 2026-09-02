<?php
// tests/push_sender_test.php
//
// Phase-4 crypto suite for core/PushSender.php — fully OFFLINE (no sockets,
// no DB file): VAPID JWT ES256 (RFC 8292) with an openssl-verified raw r||s
// signature, PEM reconstruction from raw P-256 components (the pre-upgrade
// key path), aes128gcm record layout (RFC 8291) and a full decrypt roundtrip
// against a deterministic salt + ephemeral key (static-mock seam).
//
// Run: php tests/push_sender_test.php

require_once __DIR__ . '/../core/PushBase.php';
require_once __DIR__ . '/../core/PushSender.php';
require_once __DIR__ . '/../core/PushMacros.php';

$testPassed = true;
function assertTrue($condition, string $message): bool {
    global $testPassed;
    if (!$condition) { fwrite(STDERR, "FAILED: $message\n"); $testPassed = false; }
    else { echo "✓ $message\n"; }
    return (bool) $condition;
}
function assertThrows(callable $fn, string $message): bool {
    try {
        $fn();
        return assertTrue(false, $message);
    } catch (\Throwable $e) {
        return assertTrue(true, $message);
    }
}

function b64url_decode(string $s): string {
    $b = base64_decode(strtr($s, '-_', '+/'), true);
    return $b === false ? '' : $b;
}
/** Uncompressed point from openssl EC details. */
function rawPoint(array $details): string {
    return "\x04"
        . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
}
/** SPKI PEM built independently of the class under test. */
function spkiPem(string $pub65): string {
    $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $pub65;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
/**
 * raw r||s → DER SEQUENCE, so openssl_verify (which takes DER, unlike the
 * JWS raw form the JWT carries) can check a signature from the JWT.
 */
function rawSigToDer(string $raw): string {
    $r = ltrim(substr($raw, 0, 32), "\x00");
    $s = ltrim(substr($raw, 32, 32), "\x00");
    if ($r === '' || (ord($r[0]) & 0x80)) $r = "\x00" . $r;
    if ($s === '' || (ord($s[0]) & 0x80)) $s = "\x00" . $s;
    return "\x30" . chr(4 + strlen($r) + strlen($s))
        . "\x02" . chr(strlen($r)) . $r
        . "\x02" . chr(strlen($s)) . $s;
}
/** Deterministic salt/ephemeral seam (the production subclasses nothing). */
class DeterministicPushSender extends PushSender
{
    public static string $salt = '';
    public static $ephemeral = null;
    protected static function randomBytes(int $length): string { return self::$salt; }
    protected static function generateEphemeralKey() { return self::$ephemeral; }
}

// ----------------------------------------------------------------------
// HKDF-SHA256 against RFC 5869 Appendix A Test Case 1.
// ----------------------------------------------------------------------
$okm = PushSender::hkdfSha256(
    str_repeat("\x0b", 22),
    "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c",
    "\xf0\xf1\xf2\xf3\xf4\xf5\xf6\xf7",
    42
);
assertTrue($okm === hex2bin('79ba22a42df67283a92336c6210c15573cbaa75b49ee129817b01483a55606b829501b3a5e5d57deeb50'),
    'HKDF-SHA256 matches the RFC 5869 test vector (cross-checked against OpenSSL)');

// ----------------------------------------------------------------------
// Keygen (PushBase) now carries the PEM export; raw pieces unchanged.
// ----------------------------------------------------------------------
$keys = PushBase::generateKeys();
$pubRaw = b64url_decode($keys['public']);
$privRaw = b64url_decode($keys['private']);
assertTrue(strlen($pubRaw) === 65 && $pubRaw[0] === "\x04", 'generateKeys: public is an uncompressed P-256 point');
assertTrue(strlen($privRaw) === 32, 'generateKeys: private is 32 raw bytes');
assertTrue(strpos($keys['private_pem'] ?? '', '-----BEGIN PRIVATE KEY-----') === 0, 'generateKeys: PEM export present');
$pemKey = openssl_pkey_get_private($keys['private_pem']);
assertTrue($pemKey !== false, 'generateKeys: PEM export loads in openssl');
$pemDetails = openssl_pkey_get_details($pemKey);
assertTrue($pemDetails !== false && rawPoint($pemDetails) === $pubRaw, 'generateKeys: PEM export matches the raw public point');

// ----------------------------------------------------------------------
// PEM reconstruction from raw components (keys generated pre-upgrade).
// ----------------------------------------------------------------------
$rebuilt = PushSender::privatePemFromRaw($privRaw, $pubRaw);
$rebuiltKey = openssl_pkey_get_private($rebuilt);
assertTrue($rebuiltKey !== false, 'SEC1 PEM reconstruction loads in openssl');
$rebuiltDetails = openssl_pkey_get_details($rebuiltKey);
assertTrue($rebuiltDetails !== false
    && str_pad($rebuiltDetails['ec']['d'], 32, "\x00", STR_PAD_LEFT) === $privRaw
    && rawPoint($rebuiltDetails) === $pubRaw,
    'SEC1 PEM reconstruction preserves D and the public point');
$spki = PushSender::publicPemFromRaw($pubRaw);
$spkiKey = openssl_pkey_get_public($spki);
assertTrue($spkiKey !== false && rawPoint(openssl_pkey_get_details($spkiKey)) === $pubRaw,
    'SPKI PEM from raw client point loads and matches');
assertThrows(static function () { PushSender::privatePemFromRaw('short', str_repeat('x', 65)); },
    'PEM reconstruction rejects a malformed private scalar');
assertThrows(static function () { PushSender::publicPemFromRaw("\x03" . str_repeat('x', 64)); },
    'SPKI wrapping rejects a compressed point');

// ----------------------------------------------------------------------
// VAPID JWT ES256.
// ----------------------------------------------------------------------
$endpoint = 'https://fcm.googleapis.com/fcm/send/abc-123';
$auth = PushSender::buildVapidAuth($keys, $endpoint, 'mailto:ops@example.com');
$segments = explode('.', $auth['jwt']);
assertTrue(count($segments) === 3, 'JWT has exactly 3 segments');
$header = json_decode(b64url_decode($segments[0]), true);
$claims = json_decode(b64url_decode($segments[1]), true);
assertTrue(($header['typ'] ?? '') === 'JWT' && ($header['alg'] ?? '') === 'ES256', 'JWT header is {"typ":"JWT","alg":"ES256"}');
assertTrue(($claims['aud'] ?? '') === 'https://fcm.googleapis.com', 'aud is scheme+host of the endpoint');
assertTrue(abs(($claims['exp'] ?? 0) - (time() + PushSender::JWT_TTL_SECONDS)) < 5, 'exp is now + 12h');
assertTrue(($claims['sub'] ?? '') === 'mailto:ops@example.com', 'sub carries the mailto contact');
$sig = b64url_decode($segments[2]);
assertTrue(strlen($sig) === 64, 'ES256 signature is raw 64-byte r||s');
$input = $segments[0] . '.' . $segments[1];
// openssl_verify takes a DER signature, the JWS carries raw r||s — convert
// the JWT's raw sig back to DER with an independent wrapper and check it.
$verifyPub = openssl_pkey_get_public(spkiPem($pubRaw));
assertTrue(openssl_verify($input, rawSigToDer($sig), $verifyPub, OPENSSL_ALGO_SHA256) === 1,
    'signature VERIFIES with openssl_verify against the public PEM');
$tampered = $input . 'x';
assertTrue(openssl_verify($tampered, rawSigToDer($sig), $verifyPub, OPENSSL_ALGO_SHA256) !== 1,
    'signature fails on a tampered payload');

// The same JWT is produced when only the raw base64url pieces exist (no PEM).
$rawOnlyKeys = ['public' => $keys['public'], 'private' => $keys['private']];
$auth2 = PushSender::buildVapidAuth($rawOnlyKeys, $endpoint, 'mailto:ops@example.com');
$sig2 = b64url_decode(substr($auth2['jwt'], strrpos($auth2['jwt'], '.') + 1));
$input2 = substr($auth2['jwt'], 0, strrpos($auth2['jwt'], '.'));
assertTrue(openssl_verify($input2, rawSigToDer($sig2), $verifyPub, OPENSSL_ALGO_SHA256) === 1,
    'signing works from raw D without a stored PEM (reconstruction path)');

assertTrue(PushSender::vapidAudience('http://localhost:8080/x') === 'http://localhost:8080',
    'aud keeps an explicit non-default port');
assertThrows(static function () { PushSender::vapidAudience('not a url'); },
    'aud rejects an endpoint without a host');

// RFC 7638 thumbprint, computed independently.
$jwk = '{"crv":"P-256","kty":"EC","x":"' . PushSender::base64Url(substr($pubRaw, 1, 32))
    . '","y":"' . PushSender::base64Url(substr($pubRaw, 33, 32)) . '"}';
assertTrue($auth['key'] === PushSender::base64Url(hash('sha256', $jwk, true)),
    'k= parameter equals the RFC 7638 JWK thumbprint');

assertThrows(static function () { PushSender::derSignatureToRaw("\x02\x01\x00"); },
    'DER parser rejects a non-SEQUENCE blob');

// ----------------------------------------------------------------------
// aes128gcm record (RFC 8291): layout + full RFC decrypt roundtrip.
// ----------------------------------------------------------------------
$client = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$clientDetails = openssl_pkey_get_details($client);
$clientPubRaw = rawPoint($clientDetails);
$clientAuth = random_bytes(16);
$salt = random_bytes(16);
$ephemeral = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
DeterministicPushSender::$salt = $salt;
DeterministicPushSender::$ephemeral = $ephemeral;

$payload = json_encode(['title' => 'Привет', 'body' => 'Hello {subid}'], JSON_UNESCAPED_UNICODE);
$record = DeterministicPushSender::encrypt($payload, $keys['public'] === '' ? '' : PushSender::base64Url($clientPubRaw), PushSender::base64Url($clientAuth));

assertTrue(substr($record, 0, 16) === $salt, 'record starts with the pinned 16-byte salt');
assertTrue(unpack('N', substr($record, 16, 4))[1] === PushSender::RECORD_SIZE, 'rs field is u32be 4096');
assertTrue(ord($record[20]) === 0, 'ids field is a single 0x00 byte');
$ciphertext = substr($record, 21);
// padded plaintext fills rs-17-16; +16 tag → ciphertext chunk is rs-17.
assertTrue(strlen($ciphertext) === PushSender::RECORD_SIZE - 17,
    'ciphertext+tag is exactly rs-17 (payload padded with 0x02-delimited zeros)');

// Independent RFC decrypt: ECDH on the CLIENT private key × ephemeral public.
$ephDetails = openssl_pkey_get_details(DeterministicPushSender::$ephemeral);
$ephPubRaw = rawPoint($ephDetails);
$ephPubPem = PushSender::publicPemFromRaw($ephPubRaw);
$as = openssl_pkey_derive(openssl_pkey_get_public($ephPubPem), $client);
if (!is_string($as) || $as === '') {
    // PHP builds that flip the derive argument roles (seen on 8.5).
    $as = openssl_pkey_derive($client, $ephPubPem);
}
assertTrue(is_string($as) && strlen($as) === 32, 'ECDH shared secret is 32 bytes');
$ikm = $clientAuth . $as;
$prk = PushSender::hkdfSha256($ikm, $salt, 'WebPush: info' . "\x00" . $clientPubRaw . $ephPubRaw, 32);
$cek = PushSender::hkdfSha256($prk, '', "Content-Encoding: aes128gcm\x01", 16);
$nonce = PushSender::hkdfSha256($prk, '', "Content-Encoding: nonce\x01", 12);
$tag = substr($ciphertext, -16);
$encrypted = substr($ciphertext, 0, -16);
$padded = openssl_decrypt($encrypted, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
assertTrue($padded !== false, 'RFC decrypt: GCM tag authenticates');
$expectedPadded = $payload . "\x02" . str_repeat("\x00", PushSender::RECORD_SIZE - 34 - strlen($payload));
assertTrue($padded === $expectedPadded, 'decrypted padded plaintext is payload || 0x02 || zeros');
assertTrue(rtrim($padded, "\x00") === $payload . "\x02", 'payload roundtrips exactly after padding strip');

assertThrows(static function () use ($keys) {
    PushSender::encrypt('x', PushSender::base64Url(str_repeat('x', 64)), PushSender::base64Url(random_bytes(16)));
}, 'encrypt rejects a p256dh that is not an uncompressed P-256 point');
assertThrows(static function () use ($keys, $clientPubRaw, $clientAuth) {
    PushSender::encrypt(str_repeat('x', PushSender::RECORD_SIZE - 33), PushSender::base64Url($clientPubRaw), PushSender::base64Url($clientAuth));
}, 'encrypt rejects a payload over the aes128gcm record limit');

// ----------------------------------------------------------------------
// getVapidSub: settings row with a safe default (in-memory SQLite).
// ----------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
assertTrue(PushSender::getVapidSub($pdo) === PushSender::VAPID_SUB_DEFAULT,
    'getVapidSub falls back to the default contact');
$pdo->prepare("INSERT INTO settings (key, value) VALUES ('push_vapid_sub', 'mailto:me@example.com')")->execute();
assertTrue(PushSender::getVapidSub($pdo) === 'mailto:me@example.com', 'getVapidSub reads the settings row');
$pdo->prepare("UPDATE settings SET value = 'garbage' WHERE key = 'push_vapid_sub'")->execute();
assertTrue(PushSender::getVapidSub($pdo) === PushSender::VAPID_SUB_DEFAULT,
    'getVapidSub ignores a value without a mailto/https scheme');

// ----------------------------------------------------------------------
// Macros — nested choice, Random, {subid}; unknown stays verbatim.
// ----------------------------------------------------------------------
assertTrue(in_array(PushMacros::expand('Hi {Vasya|Petya}!', 'c1'), ['Hi Vasya!', 'Hi Petya!'], true),
    'choice macro picks one of its options');
$r = (int) PushMacros::expand('{Random=(5,10)}');
assertTrue($r >= 5 && $r <= 10, '{Random=(X,Y)} returns an integer within the range');
assertTrue(PushMacros::expand('/go?subid={subid}', 'click-9') === '/go?subid=click-9', '{subid} expands to the click_id');
$nested = PushMacros::expand('{A|{B|C}}', '');
assertTrue(in_array($nested, ['A', 'B', 'C'], true), 'nested choice resolves inner group first');
$deep = 'k';
for ($i = 0; $i < 10; $i++) {
    $letter = chr(ord('j') - $i);
    $deep = '{' . $letter . '|' . $deep . '}';
}
assertTrue(in_array(PushMacros::expand($deep, ''), ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k'], true),
    '10 nesting levels still resolve');
$deeper = '{x|' . $deep . '}';
assertTrue(strpos(PushMacros::expand($deeper, ''), '{') !== false, '11 levels deep stays verbatim (as-is)');
assertTrue(PushMacros::expand('{offer.url}', 'c1') === '{offer.url}', 'unknown macro stays verbatim');
assertTrue(PushMacros::expand('{}', '') === '{}', 'empty braces stay verbatim');
assertTrue(PushMacros::expand('{Random=(3,3)}') === '3', 'Random with equal bounds is deterministic');
assertTrue(PushMacros::expand('no braces at all', 'c1') === 'no braces at all', 'plain text passes through');
$many = PushMacros::expand('{a|b} {a|b} {a|b} {a|b} {a|b} {a|b} {a|b} {a|b}', '');
assertTrue(preg_match('/^([ab] ){7}[ab]$/', $many) === 1, 'many independent choices resolve in one pass');

echo $testPassed ? "\nALL TESTS PASSED\n" : "\nSOME TESTS FAILED\n";
exit($testPassed ? 0 : 1);
