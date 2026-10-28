<?php

require_once __DIR__ . '/../core/security_digest.php';

$failures = [];

// [$injectedVersion, $expectedLevel, $expectedFixedIn]
$cases = [
    // Fixed mainline and stable, and one patch above each.
    ['1.31.7', 'ok', null],
    ['1.31.8', 'ok', null],
    ['1.30.6', 'ok', null],
    ['1.30.7', 'ok', null],
    // One patch below the fix on both lines.
    ['1.31.6', 'affected', '1.31.7'],
    ['1.31.0', 'affected', '1.31.7'],
    ['1.30.5', 'affected', '1.30.6'],
    // Older distro builds — all affected, stable fix applies.
    ['1.30.0', 'affected', '1.30.6'],
    ['1.28.0', 'affected', '1.30.6'],
    ['1.26.3', 'affected', '1.30.6'],
    ['1.24.0', 'affected', '1.30.6'],
    ['1.22.1', 'affected', '1.30.6'],
    ['1.18.0', 'affected', '1.30.6'],
    // Branches that postdate the advisory.
    ['1.32.0', 'ok', null],
    ['2.0.0', 'ok', null],
    // Unparseable input stays unknown rather than guessing "ok".
    ['garbage', 'unknown', null],
    ['', 'unknown', null],
];

foreach ($cases as [$version, $level, $fixedIn]) {
    $st = orbitraSecurityDigestStatus($version);
    if ($st['level'] !== $level) {
        $failures[] = "version '{$version}': level '{$st['level']}', expected '{$level}'";
    }
    if ($st['fixed_in'] !== $fixedIn) {
        $failures[] = "version '{$version}': fixed_in " . var_export($st['fixed_in'], true)
            . ', expected ' . var_export($fixedIn, true);
    }
}

// Shape of the payload the banner consumes.
$st = orbitraSecurityDigestStatus('1.24.0');
foreach (['advisory' => 'syspkgs-2026-10', 'nginx_version' => '1.24.0'] as $k => $v) {
    if (($st[$k] ?? null) !== $v) {
        $failures[] = "payload field '{$k}' is " . var_export($st[$k] ?? null, true) . ", expected '{$v}'";
    }
}

if ($failures) {
    foreach ($failures as $f) {
        echo "FAIL: $f\n";
    }
    exit(1);
}
echo "security digest tests passed\n";
exit(0);
