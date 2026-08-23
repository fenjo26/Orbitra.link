<?php
// tests/rotation_allocator_test.php
//
// Table-driven unit tests for the pure rotation allocator
// (orbitraAllocateRotationWeights): warm-up gating, exploration floor, cap,
// dampening, integer sum-100 rounding, disabled items untouched.
//
// Run: php tests/rotation_allocator_test.php

require_once __DIR__ . '/../core/RotationOptimiser.php';

$failures = 0;
$assert = function (string $label, $cond, string $detail = '') use (&$failures) {
    if ($cond) {
        echo "  ok  $label\n";
    } else {
        $failures++;
        echo "FAIL  $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
};

// Shared config: the shipped defaults.
$cfg = orbitraNormalizeRotationAutoConfig(null);
if ($cfg['metric'] !== 'epv_confirmed' || $cfg['min_sample'] !== 3 || $cfg['floor_pct'] !== 5 || $cfg['cap_pct'] !== 70) {
    echo "FAIL  defaults: " . var_export($cfg, true) . "\n";
    exit(1);
}

$mkItems = static fn(array $spec) => array_map(
    static fn($row) => ['id' => $row[0], 'weight' => $row[1], 'enabled' => $row[2] ?? true],
    $spec
);
$mkMetrics = static fn(array $spec) => array_combine(
    array_map(static fn($r) => $r[0], $spec),
    array_map(static fn($r) => ['value' => $r[1], 'sample' => $r[2]], $spec)
);
$sum = static fn(array $w) => array_sum($w);
$totalMove = static fn(array $old, array $new) => array_sum(array_map(
    static fn($id) => abs($new[$id] - $old[$id]),
    array_keys($new)
));

echo "== 1. no qualified items (warm-up, rule 1/6) ==\n";
$out = orbitraAllocateRotationWeights(
    $mkItems([[1, 50], [2, 50]]),
    $cfg,
    $mkMetrics([[1, 1.0, 1], [2, 0.5, 0]]) // samples below min_sample=3
);
$assert('fewer than two qualified → null', $out === null);

echo "== 2. exactly one qualified item (rule 6) ==\n";
$out = orbitraAllocateRotationWeights(
    $mkItems([[1, 50], [2, 50]]),
    $cfg,
    $mkMetrics([[1, 1.0, 5], [2, 0.5, 1]])
);
$assert('one qualified → null', $out === null);

echo "== 3. all equal: stays at the even split ==\n";
$items = $mkItems([[1, 34], [2, 33], [3, 33]]);
$metrics = $mkMetrics([[1, 2.0, 10], [2, 2.0, 10], [3, 2.0, 10]]);
$out = orbitraAllocateRotationWeights($items, $cfg, $metrics);
$assert('returns weights', is_array($out));
$assert('sums to 100', $sum($out) === 100, var_export($out, true));
$assert('still even (±1)', max($out) - min($out) <= 1, var_export($out, true));
$assert('no movement (±1 per item)', $totalMove([1 => 34, 2 => 33, 3 => 33], $out) <= 3, var_export($out, true));

echo "== 4. one dominant: rises, capped, damped ==\n";
$items = $mkItems([[1, 25], [2, 25], [3, 25], [4, 25]]);
$metrics = $mkMetrics([[1, 100.0, 30], [2, 1.0, 5], [3, 1.0, 5], [4, 1.0, 5]]);
$out = orbitraAllocateRotationWeights($items, $cfg, $metrics);
$assert('sums to 100', $sum($out) === 100, var_export($out, true));
$assert('winner above the others', $out[1] > $out[2] && $out[1] > $out[3] && $out[1] > $out[4], var_export($out, true));
$assert('winner respects 70% cap', $out[1] <= 70, var_export($out, true));
$assert('losers keep the 5% floor', $out[2] >= 5 && $out[3] >= 5 && $out[4] >= 5, var_export($out, true));
$assert(
    'movement damped to ≤20pp total',
    $totalMove([1 => 25, 2 => 25, 3 => 25, 4 => 25], $out) <= 20,
    'moved ' . $totalMove([1 => 25, 2 => 25, 3 => 25, 4 => 25], $out) . 'pp: ' . var_export($out, true)
);

echo "== 5. item at the floor recovers after new data ==\n";
// A crushed the field for long enough to sit at the cap, B sat at the floor.
// New data flips the picture: B now has the strong numbers. B must climb
// (rule 2 exists so it CAN), and repeated runs would walk it to the top.
$items = $mkItems([[1, 70], [2, 30]]);
$metrics = $mkMetrics([[1, 0.1, 10], [2, 9.0, 10]]);
$out = orbitraAllocateRotationWeights($items, $cfg, $metrics);
$assert('sums to 100', $sum($out) === 100, var_export($out, true));
$assert('B climbs above 30', $out[2] > 30, var_export($out, true));
$assert('A declines below 70', $out[1] < 70, var_export($out, true));
$assert('movement ≤20pp', $totalMove([1 => 70, 2 => 30], $out) <= 20, var_export($out, true));
// Repeated runs converge toward B dominance without ever breaking cap/floor.
$cur = [1 => 70, 2 => 30];
for ($i = 0; $i < 40; $i++) {
    $step = orbitraAllocateRotationWeights($mkItems([[1, $cur[1]], [2, $cur[2]]]), $cfg, $metrics);
    if ($step === null) break;
    $cur = $step;
}
$assert('converged with B dominant', $cur[2] > $cur[1], var_export($cur, true));
$assert('A never dropped below floor across runs', $cur[1] >= 5, var_export($cur, true));
$assert('B never exceeded cap across runs', $cur[2] <= 70, var_export($cur, true));

echo "== 6. dampening cap on an extreme target ==\n";
// Start from 50/50 where the metric says 99:1 — the raw target would be a
// 70/5 split (65/30 move); dampening must hold the run to 20pp total.
$items = $mkItems([[1, 50], [2, 50]]);
$metrics = $mkMetrics([[1, 99.0, 20], [2, 1.0, 20]]);
$out = orbitraAllocateRotationWeights($items, $cfg, $metrics);
$assert('sums to 100', $sum($out) === 100, var_export($out, true));
$assert(
    'exactly ≤20pp moved',
    $totalMove([1 => 50, 2 => 50], $out) <= 20,
    'moved ' . $totalMove([1 => 50, 2 => 50], $out) . 'pp: ' . var_export($out, true)
);
$assert('winner moved up', $out[1] > 50, var_export($out, true));

echo "== 7. rounding always totals 100 ==\n";
// Awkward counts: 3, 6 and 7 enabled items with equal values — floor(100/N)
// leaves remainders that must be distributed, not lost.
foreach ([3, 6, 7] as $n) {
    $spec = [];
    for ($i = 1; $i <= $n; $i++) {
        $base = intdiv(100, $n);
        $spec[] = [$i, $base, 8]; // weights don't sum to 100 on purpose
    }
    $out = orbitraAllocateRotationWeights($mkItems($spec), $cfg, $mkMetrics(
        array_map(static fn($i) => [$i, 3.0, 8], range(1, $n))
    ));
    $assert("n=$n sums to 100", is_array($out) && $sum($out) === 100, var_export($out, true));
    $assert("n=$n every item ≥ floor", is_array($out) && min($out) >= 5, var_export($out, true));
}

echo "== 8. disabled items untouched (rule 5) ==\n";
$items = $mkItems([[1, 40], [2, 40], [3, 20, false]]);
$out = orbitraAllocateRotationWeights(
    $items,
    $cfg,
    $mkMetrics([[1, 5.0, 10], [2, 1.0, 10]])
);
$assert('enabled-only result', is_array($out) && !array_key_exists(3, $out), var_export($out, true));
$assert('enabled weights sum to 100', $sum($out) === 100, var_export($out, true));

echo "== 9. warm-up: below-threshold item keeps its equal share ==\n";
// 3 enabled items, only 2 qualified. The cold item keeps 100/3 — it neither
// competes nor drops to the floor while its data gathers.
$items = $mkItems([[1, 34], [2, 33], [3, 33]]);
$metrics = $mkMetrics([[1, 9.0, 10], [2, 1.0, 10], [3, 99.0, 1]]); // 3 is cold
$out = orbitraAllocateRotationWeights($items, $cfg, $metrics);
$assert('cold item stays at ~33', is_array($out) && abs($out[3] - 33) <= 1, var_export($out, true));
$assert('sums to 100', $sum($out) === 100, var_export($out, true));

echo "== 10. negative metric values are losers, not errors ==\n";
$items = $mkItems([[1, 50], [2, 50]]);
$metrics = $mkMetrics([[1, -25.0, 6], [2, 4.0, 6]]); // negative ROI
$out = orbitraAllocateRotationWeights($items, $cfg, $metrics);
$assert('sums to 100', is_array($out) && $sum($out) === 100, var_export($out, true));
$assert('negative item ≤ the other', $out[1] <= $out[2], var_export($out, true));

echo "== 11. config normalisation refuses floor ≥ cap ==\n";
$bad = orbitraNormalizeRotationAutoConfig(['floor_pct' => 80, 'cap_pct' => 20]);
$assert('floor reset to default', $bad['floor_pct'] === 5 && $bad['cap_pct'] === 70, var_export($bad, true));
$clamped = orbitraNormalizeRotationAutoConfig(['lookback_days' => 400, 'interval_min' => 1, 'min_sample' => 0]);
$assert('lookback clamped to 90', $clamped['lookback_days'] === 90);
$assert('interval clamped to ≥5', $clamped['interval_min'] === 5);
$assert('min_sample clamped to ≥1', $clamped['min_sample'] === 1);

echo "== 12. save-path sanitiser: metric guard, key minting, clamps ==\n";
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE campaigns (id INTEGER PRIMARY KEY, cost_model TEXT DEFAULT 'CPC', cost_value REAL DEFAULT 0)");
$pdo->exec("CREATE TABLE clicks (id TEXT PRIMARY KEY, campaign_id INTEGER, cost REAL DEFAULT 0, created_at TEXT)");
$pdo->exec("INSERT INTO campaigns (id, cost_value) VALUES (1, 0)");   // no cost at all
$pdo->exec("INSERT INTO campaigns (id, cost_value) VALUES (2, 0.35)"); // manual cost
$payload = [[
    'name' => 'S', 'schema_type' => 'landing_offer',
    'schema_custom' => [
        'landings' => [['id' => 1, 'weight' => 50], ['id' => 2, 'weight' => 50]],
        'offers' => [],
        'auto' => [
            'landings' => [
                'enabled' => true, 'metric' => 'roi_confirmed',
                'min_sample' => 500, 'lookback_days' => 400,
                'floor_pct' => 90, 'cap_pct' => 10, 'interval_min' => 1,
                'last_run_at' => '2026-01-01 00:00:00', // stale editor copy
            ],
            'offers' => ['enabled' => false],
        ],
    ],
]];
$out = orbitraSanitizeAutoConfigs($pdo, 1, $payload);
$a = $out[0]['schema_custom']['auto']['landings'];
$assert('cost metric refused without cost → sales', $a['metric'] === 'sales', var_export($a, true));
$assert('rotation key minted', isset($a['key']) && strpos($a['key'], 'rot_') === 0, var_export($a, true));
$assert('clamps applied', $a['min_sample'] === 500 && $a['lookback_days'] === 90
    && $a['floor_pct'] === 5 && $a['cap_pct'] === 70 && $a['interval_min'] === 5, var_export($a, true));
$assert('stale bookkeeping stripped', !isset($a['last_run_at']), var_export($a, true));
// Cost exists → the same original config keeps its metric; and a second
// pass over the sanitized result keeps the minted key (save round-trip).
$mkPayload = static function () use ($payload) { return $payload; };
$out2 = orbitraSanitizeAutoConfigs($pdo, 2, $mkPayload());
$b = $out2[0]['schema_custom']['auto']['landings'];
$assert('cost metric kept when cost exists', $b['metric'] === 'roi_confirmed', var_export($b, true));
$out3 = orbitraSanitizeAutoConfigs($pdo, 2, $out2);
$c = $out3[0]['schema_custom']['auto']['landings'];
$assert('key stable across saves', $c['key'] === $b['key']);

echo "== 13. cost gating covers ROI only — EPC is revenue ÷ clicks ==\n";
// epc_confirmed = revenue_confirmed / clicks (ReportMetrics has no cost term
// in it), so a zero-cost campaign must keep an EPC config verbatim.
$assert('only roi_confirmed needs cost',
    orbitraRotationMetricNeedsCost('roi_confirmed') === true
    && orbitraRotationMetricNeedsCost('epc_confirmed') === false
    && orbitraRotationMetricNeedsCost('epv_confirmed') === false
    && orbitraRotationMetricNeedsCost('cr') === false
    && orbitraRotationMetricNeedsCost('sales') === false);
$epcPayload = [[
    'name' => 'S', 'schema_type' => 'landing_offer',
    'schema_custom' => [
        'landings' => [['id' => 1, 'weight' => 50], ['id' => 2, 'weight' => 50]],
        'offers' => [],
        'auto' => [
            'landings' => ['enabled' => true, 'metric' => 'epc_confirmed', 'key' => 'rot_epc1'],
            'offers' => ['enabled' => false],
        ],
    ],
]];
$out4 = orbitraSanitizeAutoConfigs($pdo, 1, $epcPayload); // campaign 1: no cost at all
$d = $out4[0]['schema_custom']['auto']['landings'];
$assert('EPC config not coerced on a zero-cost campaign', $d['metric'] === 'epc_confirmed', var_export($d, true));

if ($failures > 0) {
    echo "\n$failures FAILURE(S)\n";
    exit(1);
}
echo "\nALL OK\n";
