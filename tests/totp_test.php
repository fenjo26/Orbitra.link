<?php

// tests/totp_test.php
// RFC 4648 Base32 + RFC 6238 TOTP vectors for core/Totp.php. No database.

require_once __DIR__ . '/../core/Totp.php';

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$expectSame = static function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = "$message: expected " . var_export($expected, true)
            . ', got ' . var_export($actual, true);
    }
};

// --- Base32 encode: RFC 4648 test vectors (padding omitted by design) ---
$base32Vectors = [
    '' => '',
    'f' => 'MY',
    'fo' => 'MZXQ',
    'foo' => 'MZXW6',
    'foob' => 'MZXW6YQ',
    'fooba' => 'MZXW6YTB',
    'foobar' => 'MZXW6YTBOI',
];
foreach ($base32Vectors as $plain => $encoded) {
    $expectSame($encoded, Totp::base32Encode($plain), "base32Encode('$plain')");
}

// --- Base32 decode: padding, lowercase and stray separators are tolerated ---
foreach ($base32Vectors as $plain => $encoded) {
    $paddedForms = [$encoded, $encoded . str_repeat('=', 8 - (strlen($encoded) % 8))];
    foreach ($paddedForms as $form) {
        $expectSame($plain, Totp::base32Decode($form), "base32Decode('$form')");
    }
    $expectSame($plain, Totp::base32Decode(strtolower($encoded)), "base32Decode lowercase '$encoded'");
}
$expectSame('foobar', Totp::base32Decode("MZ XW-6Y\tTBOI"), 'base32Decode strips separators');
$expectSame('', Totp::base32Decode(''), 'base32Decode of empty string');
$expectSame('', Totp::base32Decode('===='), 'base32Decode of padding only');

// --- Round-trip over random bytes of every relevant length ---
// (random_bytes(0) throws on PHP 8, so start at 1 — the empty string is
// covered by the decode assertions above)
for ($len = 1; $len <= 40; $len++) {
    $bytes = random_bytes($len);
    $expectSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)), "base32 round-trip len=$len");
}

// --- generateSecret: 32 alphabet chars decoding back to 20 random bytes ---
for ($i = 0; $i < 20; $i++) {
    $secret = Totp::generateSecret();
    $expect(32 === strlen($secret), 'generateSecret must be 32 chars');
    $expect(1 === preg_match('/^[A-Z2-7]+$/', $secret), 'generateSecret must be base32 alphabet only');
    $expect(20 === strlen(Totp::base32Decode($secret)), 'generateSecret must decode to 20 bytes');
}
$expect(Totp::generateSecret() !== Totp::generateSecret(), 'generateSecret must not repeat');

// --- RFC 6238 appendix B vectors (SHA-1, secret ASCII '1234567890...') ---
$rfcSecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$rfcVectors = [
    [59, '287082'],          // 94287082, 6-digit truncation 287082
    [1111111109, '081804'],  // 07081804 — leading zero
    [1111111111, '050471'],  // 14050471 — leading zero
    [1234567890, '005924'],  // 89005924 — two leading zeros
    [2000000000, '279037'],
    [20000000000, '353130'],
];
foreach ($rfcVectors as [$t, $code]) {
    $counter = intdiv($t, 30);
    $expectSame($code, Totp::codeAtCounter($rfcSecret, $counter), "RFC 6238 T=$t");
}

// --- verifyCode around the CURRENT window: ±1 accepted, ±2 rejected ---
$liveSecret = Totp::generateSecret();
$counterNow = intdiv(time(), 30);
$expect(true === Totp::verifyCode($liveSecret, Totp::codeAtCounter($liveSecret, $counterNow) ?? ''), 'verifyCode accepts the current window');
$expect(true === Totp::verifyCode($liveSecret, Totp::codeAtCounter($liveSecret, $counterNow - 1) ?? ''), 'verifyCode accepts the previous window (t-1)');
$expect(true === Totp::verifyCode($liveSecret, Totp::codeAtCounter($liveSecret, $counterNow + 1) ?? ''), 'verifyCode accepts the next window (t+1)');
$expect(false === Totp::verifyCode($liveSecret, Totp::codeAtCounter($liveSecret, $counterNow - 2) ?? ''), 'verifyCode rejects two windows back');
$expect(false === Totp::verifyCode($liveSecret, Totp::codeAtCounter($liveSecret, $counterNow + 2) ?? ''), 'verifyCode rejects two windows ahead');

// A wider window accepts what the default rejects.
$codeOld = Totp::codeAtCounter($liveSecret, $counterNow - 2) ?? '';
$expect(false === Totp::verifyCode($liveSecret, $codeOld), 'window=1 must reject t-2');
$expect(true === Totp::verifyCode($liveSecret, $codeOld, 2), 'window=2 must accept t-2');

// --- Input handling: non-numeric, short, overlong, garbage ---
foreach (['', ' ', '12345', '123456789012', 'abc123', '12a456', '-123456', '+123456', '123456.0'] as $bad) {
    $expect(false === Totp::verifyCode($liveSecret, $bad), 'verifyCode rejects malformed code ' . var_export($bad, true));
}
// A well-formed code for a DIFFERENT secret must never verify.
$otherSecret = Totp::generateSecret();
$expect(false === Totp::verifyCode($otherSecret, Totp::codeAtCounter($liveSecret, $counterNow) ?? ''), 'a code from another secret is rejected');
// Codes with leading zeros compare as strings: '005924' at its own counter.
$zeroCounter = intdiv(1234567890, 30);
$zeroCode = Totp::codeAtCounter($rfcSecret, $zeroCounter) ?? '';
$expectSame('005924', $zeroCode, 'leading zeros preserved');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Totp tests passed.' . PHP_EOL;
