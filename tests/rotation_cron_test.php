<?php
// tests/rotation_cron_test.php
//
// Integration test for the rotation optimiser cron loop
// (orbitraRunRotationOptimiser): seeds clicks + conversions into a throwaway
// SQLite database, runs the optimiser, and asserts the weights written into
// schema_custom_json, the audit rows in stream_rotation_log, the safe-page
// exclusion, the re-evaluation interval gating, the cost-metric guard, and
// the campaign-save weight merge.
//
// Run: php tests/rotation_cron_test.php

require_once __DIR__ . '/../core/ReportMetrics.php';
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

$tmpDb = sys_get_temp_dir() . '/orbitra_rotation_cron_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$pdo = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec('CREATE TABLE campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, alias TEXT,
    cost_model TEXT DEFAULT \'CPC\', cost_value REAL DEFAULT 0,
    state TEXT DEFAULT \'active\', is_archived INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE streams (
    id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, offer_id INTEGER,
    name TEXT, weight INTEGER DEFAULT 100, is_active INTEGER DEFAULT 1,
    type TEXT DEFAULT \'regular\', position INTEGER DEFAULT 0,
    schema_type TEXT DEFAULT \'redirect\', schema_custom_json TEXT)');
$pdo->exec('CREATE TABLE landings (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE offers (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE clicks (
    id TEXT PRIMARY KEY, campaign_id INTEGER, stream_id INTEGER,
    landing_id INTEGER, offer_id INTEGER, ip TEXT, cost REAL DEFAULT 0,
    is_safe_page INTEGER DEFAULT 0, created_at TEXT)');
$pdo->exec('CREATE TABLE conversions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, click_id TEXT, status TEXT, payout REAL DEFAULT 0)');
$pdo->exec('CREATE TABLE stream_rotation_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER NOT NULL,
    rotation_key TEXT NOT NULL, stream_id INTEGER, stream_name TEXT,
    list_type TEXT NOT NULL, item_id INTEGER NOT NULL, item_name TEXT,
    old_weight INTEGER NOT NULL, new_weight INTEGER NOT NULL, metric TEXT NOT NULL,
    metric_value REAL, sample_size INTEGER NOT NULL DEFAULT 0,
    window_from TEXT NOT NULL, window_to TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');

// ——— Seed ————————————————————————————————————————————————
// Campaign 1: no cost at all. Stream A rotates 3 landings, Auto on for
// landings only. Landing 1 is the strong performer (6 confirmed sales on 20
// money clicks), landing 2 weak (1 sale), landing 3 cold (no sales — under
// the min-sample gate).
// Stream B mirrors the setup, but every click and conversion it has is a
// SAFE-PAGE hit: the optimiser must see none of it.
$pdo->exec("INSERT INTO campaigns (id, name, alias) VALUES (1, 'Rot', 'rot')");
$pdo->exec("INSERT INTO landings (id, name) VALUES (1,'LP1'),(2,'LP2'),(3,'LP3')");

$autoLandings = json_encode([
    'enabled' => true,
    'key' => 'rot_aaa',
    'metric' => 'epv_confirmed',
    'min_sample' => 3,
    'lookback_days' => 7,
    'floor_pct' => 5,
    'cap_pct' => 70,
    'interval_min' => 60,
], JSON_UNESCAPED_UNICODE);
$autoLandingsB = json_encode([
    'enabled' => true,
    'key' => 'rot_bbb',
    'metric' => 'epv_confirmed',
    'min_sample' => 3,
    'lookback_days' => 7,
    'floor_pct' => 5,
    'cap_pct' => 70,
    'interval_min' => 60,
], JSON_UNESCAPED_UNICODE);

$mkStreamCustom = static function (string $autoJson) {
    return json_encode([
        'landings' => [
            ['id' => 1, 'weight' => 34],
            ['id' => 2, 'weight' => 33],
            ['id' => 3, 'weight' => 33],
        ],
        'offers' => [],
        'auto' => ['landings' => json_decode($autoJson, true), 'offers' => ['enabled' => false]],
    ], JSON_UNESCAPED_UNICODE);
};
$pdo->prepare("INSERT INTO streams (id, campaign_id, name, is_active, schema_type, schema_custom_json) VALUES (10, 1, 'Main', 1, 'landing_offer', ?)")
    ->execute([$mkStreamCustom($autoLandings)]);
$pdo->prepare("INSERT INTO streams (id, campaign_id, name, is_active, schema_type, schema_custom_json) VALUES (11, 1, 'SafeOnly', 1, 'landing_offer', ?)")
    ->execute([$mkStreamCustom($autoLandingsB)]);

$seedClicks = static function (int $streamId, int $landingId, int $count, array $sales, bool $safe = false) use ($pdo) {
    for ($i = 0; $i < $count; $i++) {
        $clickId = sprintf('c%d_%d_%d%s', $streamId, $landingId, $i, $safe ? 's' : 'm');
        $pdo->prepare('INSERT INTO clicks (id, campaign_id, stream_id, landing_id, ip, is_safe_page, created_at) VALUES (?,?,?,?,?,?,datetime(\'now\'))')
            ->execute([$clickId, 1, $streamId, $landingId, '10.0.0.' . (($streamId * 31 + $landingId * 7 + $i) % 250), $safe ? 1 : 0]);
        if (isset($sales[$i])) {
            $pdo->prepare('INSERT INTO conversions (click_id, status, payout) VALUES (?,?,?)')
                ->execute([$clickId, 'sale', $sales[$i]]);
        }
    }
};
// Stream A money traffic: LP1 20 clicks/6 sales (EPV 1.5), LP2 20 clicks/3
// sales (EPV 0.75 — qualified but weaker), LP3 20 clicks/0 sales (cold: below
// the min-sample gate it keeps its warm-up share and does not compete).
$seedClicks(10, 1, 20, [5 => 5, 6 => 5, 7 => 5, 8 => 5, 9 => 5, 10 => 5]);
$seedClicks(10, 2, 20, [3 => 5, 4 => 5, 5 => 5]);
$seedClicks(10, 3, 20, []);
// Stream B: same shape, but everything is a safe-page hit (cloak traffic).
$seedClicks(11, 1, 20, [5 => 5, 6 => 5, 7 => 5, 8 => 5, 9 => 5, 10 => 5], true);
$seedClicks(11, 2, 20, [3 => 5, 4 => 5, 5 => 5], true);
$seedClicks(11, 3, 20, [], true);
// Stream 12 (cost-metric guard): same money shape, enabled later.
$seedClicks(12, 1, 20, [5 => 5, 6 => 5, 7 => 5, 8 => 5, 9 => 5, 10 => 5]);
$seedClicks(12, 2, 20, [3 => 5, 4 => 5, 5 => 5]);
$seedClicks(12, 3, 20, []);

$streamCustom = static function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT schema_custom_json FROM streams WHERE id = ?');
    $stmt->execute([$id]);
    return json_decode((string) $stmt->fetchColumn(), true);
};
$landingWeights = static function (int $id) use ($streamCustom): array {
    $c = $streamCustom($id);
    $w = [];
    foreach ($c['landings'] as $l) {
        $w[(int) $l['id']] = (int) $l['weight'];
    }
    return $w;
};

// ——— Run 1: the optimiser pass ————————————————————————————
echo "== run 1: weights move toward the winner ==\n";
$summary = orbitraRunRotationOptimiser($pdo, ['force' => true]);

$w = $landingWeights(10);
$assert('stream A weights still sum to 100', array_sum($w) === 100, var_export($w, true));
$assert('winner LP1 rose above 34', $w[1] > 34, var_export($w, true));
$assert('loser LP2 fell below 33', $w[2] < 33, var_export($w, true));
$assert('cold LP3 kept its warm-up share (~33)', abs($w[3] - 33) <= 1, var_export($w, true));
$move = abs($w[1] - 34) + abs($w[2] - 33) + abs($w[3] - 33);
$assert('movement damped to ≤20pp', $move <= 20, "moved {$move}pp: " . var_export($w, true));

echo "== safe-page clicks changed nothing ==\n";
$wB = $landingWeights(11);
$assert('stream B (safe-only traffic) untouched', $wB === [1 => 34, 2 => 33, 3 => 33], var_export($wB, true));
$autoB = $streamCustom(11)['auto']['landings'];
$assert('stream B recorded ok_noop', ($autoB['last_status'] ?? '') === 'ok_noop', var_export($autoB, true));
$stmt = $pdo->prepare("SELECT COUNT(*) FROM stream_rotation_log WHERE rotation_key = 'rot_bbb'");
$stmt->execute();
$assert('no audit rows for the safe-only stream', (int) $stmt->fetchColumn() === 0);

echo "== audit rows answer why ==\n";
$stmt = $pdo->prepare("SELECT item_id, old_weight, new_weight, metric, metric_value, sample_size, window_from, window_to, item_name, stream_name
                       FROM stream_rotation_log WHERE rotation_key = 'rot_aaa' ORDER BY item_id");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$assert('audit rows exist for changed items', count($rows) >= 2, var_export($rows, true));
$byItem = [];
foreach ($rows as $r) {
    $byItem[(int) $r['item_id']] = $r;
}
$assert('LP1 audit row matches the write', (int) $byItem[1]['old_weight'] === 34 && (int) $byItem[1]['new_weight'] === $w[1], var_export($byItem[1] ?? null, true));
$assert('LP1 sample = confirmed sales (6)', (int) $byItem[1]['sample_size'] === 6, var_export($byItem[1] ?? null, true));
$assert('metric recorded', $byItem[1]['metric'] === 'epv_confirmed');
// EPV confirmed for LP1: 6 sales × 5 payout on 20 clicks = 1.5
$assert('metric value matches report math (1.5)', abs((float) $byItem[1]['metric_value'] - 1.5) < 0.001, var_export($byItem[1] ?? null, true));
$assert('window is the rolling 7 days', strtotime($byItem[1]['window_to']) - strtotime($byItem[1]['window_from']) === 7 * 86400, var_export($byItem[1] ?? null, true));
$assert('item/stream names snapshotted', $byItem[1]['item_name'] === 'LP1' && $byItem[1]['stream_name'] === 'Main');

echo "== bookkeeping written ==\n";
$autoA = $streamCustom(10)['auto']['landings'];
$assert('last_run_at set', !empty($autoA['last_run_at']));
$assert('last_updated_at set', !empty($autoA['last_updated_at']));
$assert('last_status ok', ($autoA['last_status'] ?? '') === 'ok');

// ——— Run 2: interval gating ———————————————————————————————
echo "== run 2: not due, nothing happens ==\n";
$wBefore = $landingWeights(10);
$summary2 = orbitraRunRotationOptimiser($pdo, []);
$assert('second run within interval is a no-op', $landingWeights(10) === $wBefore);
$stmt = $pdo->query("SELECT COUNT(*) FROM stream_rotation_log");
$countAfter2 = (int) $stmt->fetchColumn();

echo "== run 3: drift continues toward the target, budgeted per run ==\n";
// Dampening is a per-RUN budget: after run 1 the split is still on the way
// to the data's ideal (44.4/22.2/33.3), and each further run may keep
// drifting — up to 20pp per run, converging on the fixed point.
$wAfter1 = $landingWeights(10);
$summary3 = orbitraRunRotationOptimiser($pdo, ['force' => true]);
$wAfter3 = $landingWeights(10);
$assert('run 3 still sums to 100', array_sum($wAfter3) === 100, var_export($wAfter3, true));
$move3 = 0;
foreach ($wAfter3 as $id => $w) {
    $move3 += abs($w - $wAfter1[$id]);
}
$assert('run 3 moved ≤20pp', $move3 <= 20, "moved {$move3}pp: " . var_export($wAfter3, true));
$assert('winner still ahead', $wAfter3[1] > $wAfter3[2] && $wAfter3[1] > $wAfter3[3], var_export($wAfter3, true));

echo "== run 4: fixed point — stable data writes nothing ==\n";
$stmt = $pdo->query("SELECT COUNT(*) FROM stream_rotation_log");
$countBefore4 = (int) $stmt->fetchColumn();
orbitraRunRotationOptimiser($pdo, ['force' => true]);
$stmt = $pdo->query("SELECT COUNT(*) FROM stream_rotation_log");
$assert('converged: no new audit rows', (int) $stmt->fetchColumn() === $countBefore4);
$assert('converged: weights unchanged', $landingWeights(10) === $wAfter3, var_export($landingWeights(10), true) . ' vs ' . var_export($wAfter3, true));

// ——— Cost metric guard ————————————————————————————————————
echo "== cost-dependent metric refused without cost ==\n";
$guardCustom = $mkStreamCustom(json_encode([
    'enabled' => true, 'key' => 'rot_ccc', 'metric' => 'roi_confirmed',
    'min_sample' => 3, 'lookback_days' => 7, 'floor_pct' => 5, 'cap_pct' => 70, 'interval_min' => 60,
], JSON_UNESCAPED_UNICODE));
$pdo->prepare("INSERT INTO streams (id, campaign_id, name, is_active, schema_type, schema_custom_json) VALUES (12, 1, 'Guard', 1, 'landing_offer', ?)")
    ->execute([$guardCustom]);
orbitraRunRotationOptimiser($pdo, ['force' => true, 'stream_id' => 12]);
$autoC = $streamCustom(12)['auto']['landings'];
$assert('skipped_no_cost recorded', ($autoC['last_status'] ?? '') === 'skipped_no_cost', var_export($autoC, true));
$assert('guard stream weights untouched', $landingWeights(12) === [1 => 34, 2 => 33, 3 => 33], var_export($landingWeights(12), true));
$stmt = $pdo->prepare("SELECT COUNT(*) FROM stream_rotation_log WHERE rotation_key = 'rot_ccc'");
$stmt->execute();
$assert('no audit rows from the guard', (int) $stmt->fetchColumn() === 0);

// The same zero-cost campaign, an EPC config: revenue ÷ clicks has no cost
// term, so this must run and reallocate while the ROI config above is skipped.
$seedClicks(13, 1, 20, [5 => 5, 6 => 5, 7 => 5, 8 => 5, 9 => 5, 10 => 5]);
$seedClicks(13, 2, 20, [3 => 5, 4 => 5, 5 => 5]);
$seedClicks(13, 3, 20, []);
$epcCustom = $mkStreamCustom(json_encode([
    'enabled' => true, 'key' => 'rot_epc', 'metric' => 'epc_confirmed',
    'min_sample' => 3, 'lookback_days' => 7, 'floor_pct' => 5, 'cap_pct' => 70, 'interval_min' => 60,
], JSON_UNESCAPED_UNICODE));
$pdo->prepare("INSERT INTO streams (id, campaign_id, name, is_active, schema_type, schema_custom_json) VALUES (13, 1, 'EpcNoCost', 1, 'landing_offer', ?)")
    ->execute([$epcCustom]);
orbitraRunRotationOptimiser($pdo, ['force' => true, 'stream_id' => 13]);
$autoE = $streamCustom(13)['auto']['landings'];
$assert('EPC runs on the zero-cost campaign', ($autoE['last_status'] ?? '') === 'ok', var_export($autoE, true));
$assert('EPC config not coerced to sales', ($autoE['metric'] ?? '') === 'epc_confirmed', var_export($autoE, true));
$wE = $landingWeights(13);
$assert('EPC reallocated toward the winner', $wE[1] > 34 && $wE[2] < 33, var_export($wE, true));
$stmt = $pdo->prepare("SELECT COUNT(*), MAX(metric), MAX(metric_value) FROM stream_rotation_log WHERE rotation_key = 'rot_epc'");
$stmt->execute();
$rowE = $stmt->fetch(PDO::FETCH_NUM);
$assert('audit rows written for the EPC run', (int) $rowE[0] > 0, var_export($rowE, true));
$assert('audit rows record epc_confirmed', $rowE[1] === 'epc_confirmed', var_export($rowE, true));
// EPC confirmed for LP1: 6 sales × 5 payout on 20 clicks (direct traffic →
// the clicks fallback) = 1.5
$assert('metric value matches report math (1.5)', $rowE[2] !== null && abs((float) $rowE[2] - 1.5) < 0.001, var_export($rowE, true));
// Cost arrives (synced spend on the campaign's clicks) → the same config now runs.
$pdo->exec('UPDATE clicks SET cost = 0.5 WHERE campaign_id = 1');
$stmt = $pdo->prepare("UPDATE streams SET schema_custom_json = json_set(schema_custom_json, '$.auto.landings.last_status', NULL, '$.auto.landings.last_run_at', NULL) WHERE id = 12");
$stmt->execute();
orbitraRunRotationOptimiser($pdo, ['force' => true, 'stream_id' => 12]);
$autoC = $streamCustom(12)['auto']['landings'];
$assert('runs once cost exists', ($autoC['last_status'] ?? '') === 'ok', var_export($autoC, true));

// ——— Campaign-save merge —————————————————————————————————
echo "== campaign save protects cron-owned weights ==\n";
$oldRows = $pdo->query("SELECT id, schema_custom_json FROM streams WHERE campaign_id = 1")->fetchAll(PDO::FETCH_ASSOC);
$storedW = $landingWeights(10);
// The editor round-trips a stale payload: someone "evened" the list to
// 50/50/0 in the UI before saving; item 4 is a brand-new landing.
$payload = [[
    'name' => 'Main',
    'schema_type' => 'landing_offer',
    'schema_custom' => [
        'landings' => [
            ['id' => 1, 'weight' => 50],
            ['id' => 2, 'weight' => 50],
            ['id' => 3, 'weight' => 50],
            ['id' => 4, 'weight' => 100],
        ],
        'offers' => [],
        'auto' => [
            'landings' => json_decode($autoLandings, true),
            'offers' => ['enabled' => false],
        ],
    ],
]];
$merged = orbitraMergeAutoWeights($oldRows, $payload);
$mergedList = $merged[0]['schema_custom']['landings'];
$got = [];
foreach ($mergedList as $l) {
    $got[(int) $l['id']] = (int) $l['weight'];
}
$assert('stored weights restored for items 1-3',
    $got[1] === $storedW[1] && $got[2] === $storedW[2] && $got[3] === $storedW[3],
    var_export($got, true) . ' vs stored ' . var_export($storedW, true));
$assert('brand-new item keeps its payload weight', $got[4] === 100, var_export($got, true));
$assert('bookkeeping carried over', !empty($merged[0]['schema_custom']['auto']['landings']['last_run_at']));

echo "== paused campaign is left alone ==\n";
$pdo->exec("UPDATE campaigns SET state = 'disabled' WHERE id = 1");
$before = $landingWeights(10);
orbitraRunRotationOptimiser($pdo, ['force' => true]);
$assert('disabled campaign → no change', $landingWeights(10) === $before);
$pdo->exec("UPDATE campaigns SET state = 'active' WHERE id = 1");

@unlink($tmpDb);
if ($failures > 0) {
    echo "\n$failures FAILURE(S)\n";
    exit(1);
}
echo "\nALL OK\n";
