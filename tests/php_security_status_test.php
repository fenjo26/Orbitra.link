<?php

require_once __DIR__ . '/../core/php_security.php';

$failures = [];

// [versionId, expectedLevel, expectedFixedIn]
$cases = [
    // The four branches that received the php2shell fix, at and past it.
    [80512, 'ok', null],
    [80513, 'ok', null],
    [80427, 'ok', null],
    [80336, 'ok', null],
    [80235, 'ok', null],
    // One patch below the fix on every branch.
    [80511, 'vulnerable', '8.5.12'],
    [80426, 'vulnerable', '8.4.27'],
    [80335, 'vulnerable', '8.3.36'],
    [80234, 'vulnerable', '8.2.35'],
    [80200, 'vulnerable', '8.2.35'],
    [80500, 'vulnerable', '8.5.12'],
    // EOL branches never get the fix: 8.1, 8.0, 7.4, 5.6.
    [80199, 'eol', null],
    [80100, 'eol', null],
    [80001, 'eol', null],
    [70433, 'eol', null],
    [50640, 'eol', null],
    // Branches newer than the advisory postdate it.
    [80600, 'ok', null],
    [90000, 'ok', null],
];

foreach ($cases as [$versionId, $level, $fixedIn]) {
    $st = orbitraPhpSecurityStatus($versionId);
    if ($st['level'] !== $level) {
        $failures[] = "versionId {$versionId}: level '{$st['level']}', expected '{$level}'";
    }
    if ($st['fixed_in'] !== $fixedIn) {
        $failures[] = "versionId {$versionId}: fixed_in " . var_export($st['fixed_in'], true)
            . ', expected ' . var_export($fixedIn, true);
    }
}

// Version string reconstruction for injected ids.
$st = orbitraPhpSecurityStatus(80233);
if ($st['php_version'] !== '8.2.33') {
    $failures[] = 'injected 80233 rebuilt as ' . var_export($st['php_version'], true) . ', expected 8.2.33';
}
$st = orbitraPhpSecurityStatus(70404);
if ($st['php_version'] !== '7.4.4') {
    $failures[] = 'injected 70404 rebuilt as ' . var_export($st['php_version'], true) . ', expected 7.4.4';
}

// Stable advisory shape the frontend dismiss-key depends on.
foreach ([80233, 80100, 80512] as $versionId) {
    $st = orbitraPhpSecurityStatus($versionId);
    if ($st['advisory'] !== 'php2shell-2026-10') {
        $failures[] = "versionId {$versionId}: advisory " . var_export($st['advisory'], true);
    }
    if (count($st['cves']) !== 2 || $st['cves'][0] !== 'CVE-2026-109618' || $st['cves'][1] !== 'CVE-2026-109619') {
        $failures[] = "versionId {$versionId}: cves " . json_encode($st['cves']);
    }
}

// The running interpreter reports its real version string (local build is
// 8.5.3, i.e. itself inside the vulnerable band — which is exactly what the
// banner must show until the package is patched).
$st = orbitraPhpSecurityStatus();
if ($st['php_version'] !== PHP_VERSION || $st['php_version_id'] !== PHP_VERSION_ID) {
    $failures[] = 'live interpreter reported ' . var_export($st['php_version'], true)
        . ' / ' . var_export($st['php_version_id'], true)
        . ', expected ' . PHP_VERSION . ' / ' . PHP_VERSION_ID;
}

if ($failures) {
    echo "php_security_status_test: FAIL\n" . implode("\n", $failures) . "\n";
    exit(1);
}
echo 'php_security_status_test: PASS (' . count($cases) . " cases)\n";
