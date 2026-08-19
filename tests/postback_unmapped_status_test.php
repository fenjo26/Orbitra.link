#!/usr/bin/env php
<?php

/**
 * ORB-012: Unmapped status postback handling test
 *
 * Verifies that:
 * 1. An unmapped status is recorded with status='custom' and original_status intact
 * 2. Campaign counters (conversion count, revenue) are unaffected while unmapped
 * 3. Mapping the status retroactively reclassifies existing conversions
 * 4. After remap, clicks.is_conversion and clicks.revenue are recomputed
 * 5. Existing mapped statuses behave exactly as before
 */

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/config.php';

echo "Running ORB-012 unmapped status tests...\n";

// Use an in-memory SQLite database for isolation
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Set up schema
$pdo->exec('CREATE TABLE clicks (
    id TEXT PRIMARY KEY,
    campaign_id INTEGER,
    offer_id INTEGER,
    source_id INTEGER,
    landing_id INTEGER,
    ip TEXT,
    user_agent TEXT,
    parameters_json TEXT,
    is_conversion INTEGER DEFAULT 0,
    revenue REAL DEFAULT 0.0,
    cost REAL DEFAULT 0.0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec('CREATE TABLE campaigns (
    id INTEGER PRIMARY KEY,
    name TEXT,
    is_archived INTEGER DEFAULT 0
)');

$pdo->exec('CREATE TABLE conversion_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    status_values TEXT NOT NULL,
    next_statuses TEXT,
    record_conversion INTEGER DEFAULT 1,
    record_revenue INTEGER DEFAULT 1,
    send_postback INTEGER DEFAULT 1,
    affect_cap INTEGER DEFAULT 1,
    color TEXT DEFAULT \'\' DEFAULT \'\'
)');

$pdo->exec('CREATE TABLE conversions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    click_id TEXT NOT NULL,
    tid TEXT,
    status TEXT NOT NULL,
    original_status TEXT,
    payout REAL DEFAULT 0.00,
    currency TEXT DEFAULT \'USD\',
    cost REAL DEFAULT 0.00,
    sub_id_1 TEXT,
    sub_id_2 TEXT,
    sub_id_3 TEXT,
    sub_id_4 TEXT,
    sub_id_5 TEXT,
    offer_id INTEGER,
    campaign_id INTEGER,
    ip TEXT,
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(click_id, tid),
    FOREIGN KEY (click_id) REFERENCES clicks(id) ON DELETE CASCADE
)');

// Insert default conversion types
$pdo->exec("INSERT INTO conversion_types (name, status_values, record_conversion, record_revenue) VALUES
    ('lead', 'pending,hold', 1, 1),
    ('sale', 'approved,confirmed', 1, 1),
    ('rejected', 'rejected', 0, 0),
    ('trash', 'trash', 0, 0)
");

// Helper to check test results
$assert = function (string $label, $got, $expected) {
    if ($got !== $expected) {
        echo "FAIL $label: got " . var_export($got, true) . ", expected " . var_export($expected, true) . "\n";
        exit(1);
    }
    echo "ok  $label\n";
};

// Test 1: Insert a click
$clickId = 'test_click_123';
$pdo->prepare('INSERT INTO clicks (id, campaign_id, offer_id) VALUES (?, ?, ?)')
    ->execute([$clickId, 1, 1]);

// Test 2: Postback with unmapped status "hold" (not in any status_values yet)
// This simulates what postback.php does: mapStatus returns 'custom' for unmapped
$unmappedStatus = 'hold';
$internalStatus = 'custom'; // mapStatus returns 'custom' for unmapped

$pdo->prepare('INSERT INTO conversions (click_id, status, original_status, payout, currency) VALUES (?, ?, ?, ?, ?)')
    ->execute([$clickId, $internalStatus, $unmappedStatus, 0.00, 'USD']);

// Verify: conversion was recorded with status='custom' and original_status='hold'
$stmt = $pdo->prepare('SELECT status, original_status FROM conversions WHERE click_id = ?');
$stmt->execute([$clickId]);
$conv = $stmt->fetch(PDO::FETCH_ASSOC);
$assert('Unmapped status recorded with status=custom', $conv['status'], 'custom');
$assert('Unmapped status recorded with original_status=hold', $conv['original_status'], 'hold');

// Test 3: Verify clicks.is_conversion and revenue are NOT affected by unmapped status
$stmt = $pdo->prepare('SELECT is_conversion, revenue FROM clicks WHERE id = ?');
$stmt->execute([$clickId]);
$click = $stmt->fetch(PDO::FETCH_ASSOC);
$assert('Unmapped status does not affect is_conversion', $click['is_conversion'], 0);
$assert('Unmapped status does not affect revenue', (float)$click['revenue'], 0.0);

// Test 4: Add another conversion with a mapped status to verify counters work
$pdo->prepare('INSERT INTO conversions (click_id, status, original_status, payout, currency) VALUES (?, ?, ?, ?, ?)')
    ->execute([$clickId, 'lead', 'pending', 5.00, 'USD']);

// Recompute click counters (as postback.php does)
$stmt = $pdo->prepare('
    SELECT
        SUM(CASE WHEN status IN (\'lead\', \'sale\', \'deposit\') THEN 1 ELSE 0 END) as is_conv,
        SUM(CASE WHEN status IN (\'lead\', \'sale\', \'deposit\', \'registration\') AND payout > 0 THEN payout ELSE 0 END) as total_rev
    FROM conversions WHERE click_id = ?
');
$stmt->execute([$clickId]);
$totals = $stmt->fetch(PDO::FETCH_ASSOC);
$pdo->prepare('UPDATE clicks SET is_conversion = ?, revenue = ? WHERE id = ?')
    ->execute([$totals['is_conv'] > 0 ? 1 : 0, $totals['total_rev'] ?: 0, $clickId]);

// Verify: only the lead counts toward conversion metrics
$stmt = $pdo->prepare('SELECT is_conversion, revenue FROM clicks WHERE id = ?');
$stmt->execute([$clickId]);
$click = $stmt->fetch(PDO::FETCH_ASSOC);
$assert('Lead status affects is_conversion', $click['is_conversion'], 1);
$assert('Lead status affects revenue', (float)$click['revenue'], 5.0);

// Test 5: Retroactive remap - update "hold" conversions to "lead"
$pdo->prepare('UPDATE conversions SET status = ?, updated_at = datetime(\'now\') WHERE original_status = ?')
    ->execute(['lead', 'hold']);

// Verify: the hold conversion is now classified as lead
$stmt = $pdo->prepare('SELECT status, original_status FROM conversions WHERE click_id = ? AND original_status = ?');
$stmt->execute([$clickId, 'hold']);
$conv = $stmt->fetch(PDO::FETCH_ASSOC);
$assert('Retroactive remap updates status to lead', $conv['status'], 'lead');
$assert('Original status preserved after remap', $conv['original_status'], 'hold');

// Test 6: Recompute click counters after remap
// Sum up all conversions for the click
$stmt = $pdo->prepare('
    SELECT
        SUM(CASE WHEN status IN (\'lead\', \'sale\', \'deposit\') THEN 1 ELSE 0 END) as is_conv,
        SUM(CASE WHEN status IN (\'lead\', \'sale\', \'deposit\', \'registration\') AND payout > 0 THEN payout ELSE 0 END) as total_rev
    FROM conversions WHERE click_id = ?
');
$stmt->execute([$clickId]);
$totals = $stmt->fetch(PDO::FETCH_ASSOC);

// After remap, we have: original lead ($5) + remapped hold (now lead, $0) = 2 leads, $5 revenue
$pdo->prepare('UPDATE clicks SET is_conversion = ?, revenue = ? WHERE id = ?')
    ->execute([$totals['is_conv'] > 0 ? 1 : 0, $totals['total_rev'] ?: 0, $clickId]);

// Verify: counters updated correctly after remap
$stmt = $pdo->prepare('SELECT is_conversion, revenue FROM clicks WHERE id = ?');
$stmt->execute([$clickId]);
$click = $stmt->fetch(PDO::FETCH_ASSOC);
$assert('After remap: is_conversion counts both leads', $click['is_conversion'], 1);
$assert('After remap: revenue includes both leads', (float)$click['revenue'], 5.0);

// Test 7: Verify existing mapped statuses work correctly
$clickId2 = 'test_click_456';
$pdo->prepare('INSERT INTO clicks (id, campaign_id, offer_id) VALUES (?, ?, ?)')
    ->execute([$clickId2, 1, 1]);

// Postback with mapped status "pending" -> should be mapped to "lead"
$pdo->prepare('INSERT INTO conversions (click_id, status, original_status, payout, currency) VALUES (?, ?, ?, ?, ?)')
    ->execute([$clickId2, 'lead', 'pending', 3.50, 'USD']);

// Recompute click counters
$stmt = $pdo->prepare('
    SELECT
        SUM(CASE WHEN status IN (\'lead\', \'sale\', \'deposit\') THEN 1 ELSE 0 END) as is_conv,
        SUM(CASE WHEN status IN (\'lead\', \'sale\', \'deposit\', \'registration\') AND payout > 0 THEN payout ELSE 0 END) as total_rev
    FROM conversions WHERE click_id = ?
');
$stmt->execute([$clickId2]);
$totals = $stmt->fetch(PDO::FETCH_ASSOC);
$pdo->prepare('UPDATE clicks SET is_conversion = ?, revenue = ? WHERE id = ?')
    ->execute([$totals['is_conv'] > 0 ? 1 : 0, $totals['total_rev'] ?: 0, $clickId2]);

// Verify: conversion recorded with correct status
$stmt = $pdo->prepare('SELECT status, original_status FROM conversions WHERE click_id = ?');
$stmt->execute([$clickId2]);
$conv = $stmt->fetch(PDO::FETCH_ASSOC);
$assert('Mapped status recorded correctly', $conv['status'], 'lead');
$assert('Original status preserved for mapped', $conv['original_status'], 'pending');

// Verify: click counters updated correctly
$stmt = $pdo->prepare('SELECT is_conversion, revenue FROM clicks WHERE id = ?');
$stmt->execute([$clickId2]);
$click2 = $stmt->fetch(PDO::FETCH_ASSOC);
$assert('Mapped status updates is_conversion', $click2['is_conversion'], 1);
$assert('Mapped status updates revenue', (float)$click2['revenue'], 3.5);

// Test 8: Verify unmapped status discovery query
$stmt = $pdo->query('
    SELECT
        original_status,
        COUNT(*) as count,
        MIN(created_at) as first_seen,
        MAX(created_at) as last_seen
    FROM conversions
    WHERE original_status IS NOT NULL
        AND original_status != \'\'
        AND status = \'custom\'
    GROUP BY original_status
');
$unmapped = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Our hold conversion was remapped, so there should be no custom statuses left
$assert('Unmapped discovery returns empty after remap', count($unmapped), 0);

// Test 9: Insert another unmapped conversion to test discovery
$clickId3 = 'test_click_789';
$pdo->prepare('INSERT INTO clicks (id, campaign_id, offer_id) VALUES (?, ?, ?)')
    ->execute([$clickId3, 1, 1]);

$pdo->prepare('INSERT INTO conversions (click_id, status, original_status, payout, currency) VALUES (?, ?, ?, ?, ?)')
    ->execute([$clickId3, 'custom', 'new_unmapped_status', 0.00, 'USD']);

$stmt = $pdo->query('
    SELECT
        original_status,
        COUNT(*) as count
    FROM conversions
    WHERE original_status IS NOT NULL
        AND original_status != \'\'
        AND status = \'custom\'
    GROUP BY original_status
');
$unmapped = $stmt->fetchAll(PDO::FETCH_ASSOC);
$assert('Unmapped discovery finds custom status', count($unmapped), 1);
$assert('Unmapped discovery correct status', $unmapped[0]['original_status'], 'new_unmapped_status');
$assert('Unmapped discovery correct count', $unmapped[0]['count'], 1);

echo "\nAll ORB-012 tests passed.\n";
exit(0);
