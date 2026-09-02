<?php
// tests/lp_offer_macros_test.php
//
// Two regressions found in the v1.4.1 acceptance pass, both on the path a
// landing page takes to the offer:
//
//   1. applyOfferMacros() — the /?_lp=1 landing→offer transition — substituted
//      {clickid} but not {subid}, so an offer URL written with {subid} (the
//      macro the stream editor documents) reached the affiliate network as the
//      literal string "{subid}". It now substitutes the same set the main click
//      flow does ({clickid}, {subid}, {ip}, {country}, extracted parameters,
//      {offer_id}) and drops the macros the click carried no value for.
//
//   2. serveLandingAsset() handed sw.js to nginx via X-Accel-Redirect, which
//      carries only a fixed set of upstream headers — "Service-Worker-Allowed:
//      /" was dropped, and without it the browser REFUSES the { scope: '/' }
//      registration a domain-bound PWA store makes (no worker, no push
//      subscription). Service workers are now streamed by PHP itself.
//
// The code under test is extracted from index.php, not copied — index.php
// cannot be required standalone (it IS the router).
//
// Run: php tests/lp_offer_macros_test.php

$repoRoot = dirname(__DIR__);
$failures = 0;

$assert = static function (string $label, $got, $expected) use (&$failures): void {
    $ok = $got === $expected;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label;
    if (!$ok) {
        echo ' — got ' . var_export($got, true) . ', expected ' . var_export($expected, true);
        $failures++;
    }
    echo "\n";
};

/** Extract a top-level function from index.php by name (body braces are indented, so "\n}" only ends the function). */
$extractFunction = static function (string $name) use ($repoRoot): string {
    $src = file_get_contents($repoRoot . '/index.php');
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\(.*?\n\}/ms', $src, $m)) {
        fwrite(STDERR, "could not extract function {$name} from index.php\n");
        exit(1);
    }
    return $m[0];
};

// --- 1. Macro substitution on the landing→offer hop -------------------------

echo "applyOfferMacros (/?_lp=1 transition)\n";

eval($extractFunction('applyOfferMacros'));

$clickId = 'a1b2c3d4e5';
$context = ['ip' => '203.0.113.9', 'country' => 'Germany'];
$params  = ['sub_id_1' => 'camp42', 'keyword' => 'buy now'];

$assert(
    '{subid} resolves to the click id (the reported bug)',
    applyOfferMacros('https://net.example/o?cid={subid}', $clickId, 7, [], []),
    'https://net.example/o?cid=' . $clickId
);
$assert(
    '{clickid} still resolves',
    applyOfferMacros('https://net.example/o?cid={clickid}', $clickId, 7, [], []),
    'https://net.example/o?cid=' . $clickId
);
$assert(
    '{ip} and {country} come from the originating click',
    applyOfferMacros('https://net.example/o?ip={ip}&geo={country}', $clickId, 7, [], $context),
    'https://net.example/o?ip=203.0.113.9&geo=Germany'
);
$assert(
    'extracted parameters and {offer_id} resolve',
    applyOfferMacros('https://net.example/o?s={sub_id_1}&k={keyword}&o={offer_id}', $clickId, 7, $params, $context),
    'https://net.example/o?s=camp42&k=buy+now&o=7'
);
$assert(
    'a macro the click carried no value for is dropped, not passed through',
    applyOfferMacros('https://net.example/o?t={utm_term}&s={subid}', $clickId, 7, $params, $context),
    'https://net.example/o?t=&s=' . $clickId
);
$assert(
    'a scheme-less destination still gets one',
    applyOfferMacros('net.example/o?c={subid}', $clickId, 0, [], []),
    'http://net.example/o?c=' . $clickId
);
$assert(
    'an empty URL (direct local offer) stays empty rather than becoming "http://"',
    applyOfferMacros(null, $clickId, 0, [], []),
    ''
);

// --- 2. sw.js must reach the browser with its scope allowance ---------------

echo "\nserveLandingAsset (service worker scope header)\n";

$tmp = sys_get_temp_dir() . '/orbitra_sw_' . getmypid();
$landingDir = $tmp . '/lander/test-pwa';
@mkdir($landingDir, 0775, true);
file_put_contents($landingDir . '/sw.js', "self.addEventListener('install', () => {});\n");
file_put_contents($landingDir . '/app.css', "body{}\n");

file_put_contents($tmp . '/fns.php', "<?php\n"
    . $extractFunction('orbitraStreamAssetFile') . "\n"
    . $extractFunction('serveLandingAsset') . "\n");
file_put_contents($tmp . '/probe.php', <<<'PROBE'
<?php
require __DIR__ . '/fns.php';
serveLandingAsset(1, $_GET['f'] ?? '/sw.js', __DIR__ . '/lander/test-pwa');
http_response_code(404);
PROBE);

register_shutdown_function(static function () use ($tmp): void {
    foreach (['/lander/test-pwa/sw.js', '/lander/test-pwa/app.css', '/fns.php', '/probe.php'] as $f) {
        @unlink($tmp . $f);
    }
    foreach (['/lander/test-pwa', '/lander', ''] as $d) {
        @rmdir($tmp . $d);
    }
});

$port = 8000 + (getmypid() % 1000);
$server = proc_open(
    sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($tmp)),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "could not start the probe server\n");
    exit(1);
}
register_shutdown_function(static function () use ($server): void {
    proc_terminate($server);
    proc_close($server);
});

$fetch = static function (string $file) use ($port): array {
    $url = sprintf('http://127.0.0.1:%d/probe.php?f=%s', $port, rawurlencode($file));
    for ($try = 0; $try < 40; $try++) {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]));
        if ($body !== false) {
            return ['headers' => $http_response_header ?? [], 'body' => $body];
        }
        usleep(100000);
    }
    fwrite(STDERR, "probe server never answered\n");
    exit(1);
};

$hasHeader = static function (array $headers, string $needle): bool {
    foreach ($headers as $header) {
        if (stripos($header, $needle) === 0) {
            return true;
        }
    }
    return false;
};

$sw = $fetch('/sw.js');
$assert('sw.js carries Service-Worker-Allowed: /', $hasHeader($sw['headers'], 'Service-Worker-Allowed: /'), true);
$assert('sw.js is streamed by PHP, not handed to nginx', $hasHeader($sw['headers'], 'X-Accel-Redirect:'), false);
$assert('sw.js body is the worker itself', strpos($sw['body'], "addEventListener('install'") !== false, true);

$css = $fetch('/app.css');
$assert('an ordinary asset still goes out through X-Accel-Redirect', $hasHeader($css['headers'], 'X-Accel-Redirect:'), true);

// ---------------------------------------------------------------------------

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "All LP offer macro / service worker tests passed.\n";
