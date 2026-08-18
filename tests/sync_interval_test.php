<?php
// tests/sync_interval_test.php
//
// Sub-hour cost-sync intervals (sync_interval_hours = 0.333 / 0.5, i.e. the
// 20/30-min options) must survive the whole chain:
//   1. the api.php clamp keeps fractions instead of snapping to (int),
//   2. the SQLite column (INTEGER affinity) stores them as REAL without
//      truncation — truncation to 0 would make the cron consider the
//      connection "always due" and hammer the ad APIs every run,
//   3. aggregator_cron.php turns the fraction back into whole seconds.
//
// Run: php tests/sync_interval_test.php

$root = dirname(__DIR__);

$assert = function (string $label, $got, $expected) {
    if ($got !== $expected) {
        echo "FAIL $label: got " . var_export($got, true) . ", expected " . var_export($expected, true) . "\n";
        exit(1);
    }
    echo "ok  $label\n";
};

// --- 1. The real schema, extracted from config.php ---------------------------
$config = file_get_contents($root . '/config.php');
if (!preg_match('/CREATE TABLE IF NOT EXISTS aggregator_connections \(.*?sync_interval_hours INTEGER DEFAULT 2.*?\);/s', $config, $m)) {
    echo "FAIL schema: aggregator_connections CREATE TABLE with sync_interval_hours not found in config.php\n";
    exit(1);
}
echo "ok  schema: real CREATE TABLE extracted from config.php\n";

$pdo = new PDO('sqlite::memory:');
$pdo->exec($m[0]);

// --- 2. Fractional hours round-trip through the INTEGER-affinity column ------
// PDO binds params as strings by default; SQLite must keep 0.333 as REAL.
$insert = $pdo->prepare("INSERT INTO aggregator_connections (name, engine, sync_interval_hours, is_active) VALUES (?, 'facebook', ?, 1)");
$insert->execute(['twenty', '0.333']);
$insert->execute(['thirty', '0.5']);
$insert->execute(['legacy', '2']);

$stored = $pdo->query("SELECT sync_interval_hours FROM aggregator_connections ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$assert('storage: 0.333 survives the INTEGER column (not truncated to 0)', (float) $stored[0], 0.333);
$assert('storage: 0.5 survives the INTEGER column', (float) $stored[1], 0.5);
$assert('storage: legacy 2 unaffected', (float) $stored[2], 2.0);

// --- 3. The api.php clamp (replicated verbatim from the 4 save points) -------
$clamp = function ($raw) {
    return max(0.333, min(168.0, (float) ($raw ?? 2)));
};
$assert('clamp: 20-min floor (0.1 → 0.333)', $clamp(0.1), 0.333);
$assert('clamp: 30 min passes', $clamp(0.5), 0.5);
$assert('clamp: string "0.333" passes (JSON-decoded body)', $clamp('0.333'), 0.333);
$assert('clamp: 168h ceiling', $clamp(300), 168.0);
$assert('clamp: default 2 when absent', $clamp(null), 2.0);

// Source guards: all four save points keep the float clamp, no (int) left.
$api = file_get_contents($root . '/api.php');
$assert('source: api.php has the float clamp in all 4 save points',
    substr_count($api, "max(0.333, min(168.0, (float) (\$data['sync_interval_hours'] ?? 2)))"), 4);
$hasIntClamp = strpos($api, "min(168, (int) (\$data['sync_interval_hours']") !== false;
$assert('source: no (int) interval clamp left in api.php', $hasIntClamp, false);

// --- 4. The cron formula (aggregator_cron.php) -------------------------------
$cron = file_get_contents($root . '/aggregator_cron.php');
$hasCronFormula = strpos($cron, "(int) round(((float) (\$conn['sync_interval_hours'] ?: 2)) * 3600)") !== false;
$assert('source: cron computes nextSync via round((float) … * 3600)', $hasCronFormula, true);

$nextSyncSeconds = function ($v) {
    return (int) round(((float) ($v ?: 2)) * 3600);
};
$assert('cron: 0.333 → 1199s (≈20 min)', $nextSyncSeconds(0.333), 1199);
$assert('cron: string "0.333" → 1199s (PDO string fetch)', $nextSyncSeconds('0.333'), 1199);
$assert('cron: 0.5 → 1800s', $nextSyncSeconds(0.5), 1800);
$assert('cron: empty value falls back to 2h', $nextSyncSeconds(''), 7200);
$assert('cron: 0 falls back to 2h (legacy bad row never "always due")', $nextSyncSeconds(0), 7200);
$assert('cron: plain 2 stays 7200s', $nextSyncSeconds(2), 7200);

echo "All sync-interval tests passed.\n";
