<?php

require_once __DIR__ . '/../core/prefetch.php';

$failures = [];

// A die() inside a guard would end this script with exit code 0 and no output,
// so the runner would not notice. The shutdown hook turns that into a failure.
$reachedEnd = false;
register_shutdown_function(function () use (&$reachedEnd) {
    if (!$reachedEnd) {
        fwrite(STDERR, 'Script terminated early — a guard die()d instead of serving.' . PHP_EOL);
        exit(1);
    }
});

// --- orbitraIsPrefetch: header vectors ---
$vectors = [
    'no prefetch headers'                     => [[], false],
    'Sec-Purpose: prefetch (omnibox preload)' => [['HTTP_SEC_PURPOSE' => 'prefetch'], true],
    'Sec-Purpose: prefetch;prerender list'    => [['HTTP_SEC_PURPOSE' => 'prefetch;prerender'], true],
    'Sec-Purpose: prerender'                  => [['HTTP_SEC_PURPOSE' => 'prerender'], true],
    'Sec-Purpose: idle is not prefetch'       => [['HTTP_SEC_PURPOSE' => 'idle'], false],
    'legacy Sec-Fetch-Purpose draft'          => [['HTTP_SEC_FETCH_PURPOSE' => 'prefetch'], true],
    'Purpose: prefetch (AMP/Web Light)'       => [['HTTP_PURPOSE' => 'prefetch'], true],
    'X-Moz: prefetch (old Firefox)'           => [['HTTP_X_MOZ' => 'prefetch'], true],
    'X-Purpose: preview (Safari)'             => [['HTTP_X_PURPOSE' => 'preview'], true],
    'X-Purpose: other value ignored'          => [['HTTP_X_PURPOSE' => 'instant-page'], false],
    'case-insensitive Sec-Purpose'            => [['HTTP_SEC_PURPOSE' => 'Prefetch'], true],
];

foreach ($vectors as $name => [$server, $expected]) {
    $got = orbitraIsPrefetch($server);
    if ($got !== $expected) {
        $failures[] = "$name: expected " . var_export($expected, true) . ', got ' . var_export($got, true);
    }
}

// --- orbitraShouldSkipClickOnPrefetch: setting gate + $_SERVER wiring ---
$serverBackup = $_SERVER;

$_SERVER = ['HTTP_SEC_PURPOSE' => 'prefetch'];
if (orbitraShouldSkipClickOnPrefetch('0') !== false) {
    $failures[] = 'ignore_prefetch=0 must never skip the click';
}
if (orbitraShouldSkipClickOnPrefetch('1') !== true) {
    $failures[] = 'ignore_prefetch=1 with a prefetch request must skip the click';
}

$_SERVER = ['HTTP_USER_AGENT' => 'Mozilla/5.0'];
if (orbitraShouldSkipClickOnPrefetch('1') !== false) {
    $failures[] = 'a regular navigation must not be skipped even with ignore_prefetch=1';
}

$_SERVER = $serverBackup;

// --- the legacy guard must not terminate the script ---
$_SERVER = ['HTTP_SEC_PURPOSE' => 'prefetch'];
orbitraMaybeDieOnPrefetch('1');
$_SERVER = $serverBackup;

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

$reachedEnd = true;
echo 'Prefetch guard tests passed (' . count($vectors) . ' vectors + 3 gates + legacy no-op).' . PHP_EOL;
