<?php
require 'config.php';

echo "Testing Offer Creation...\n";
try {
    $stmt = $pdo->prepare("
        INSERT INTO offers 
        (name, group_id, affiliate_network_id, url, redirect_type, is_local, geo, 
         payout_type, payout_value, payout_auto, allow_rebills, capping_limit, 
         capping_timezone, alt_offer_id, notes, values_json, state)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        'Test Offer', null, null, 'https://test.com', 'redirect',
        0, '', 'cpa', 0, 0,
        0, 0, 'UTC', null,
        '', '[]', 'active'
    ]);
    echo "Offer created: " . $pdo->lastInsertId() . "\n";
}
catch (\Exception $e) {
    echo "Offer Error: " . $e->getMessage() . "\n";
}

echo "\nTesting Campaign Creation...\n";
try {
    $stmt = $pdo->prepare("
        INSERT INTO campaigns 
        (name, alias, domain_id, group_id, source_id, cost_model, cost_value, uniqueness_method, uniqueness_hours, catch_404_stream_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        'Test Campaign', 'test-camp', null, null, null,
        'CPC', 0, 'IP', 24, null
    ]);
    echo "Campaign created: " . $pdo->lastInsertId() . "\n";
}
catch (\Exception $e) {
    echo "Campaign Error: " . $e->getMessage() . "\n";
}

echo "\nChecking ZipArchive class...\n";
if (class_exists('ZipArchive')) {
    echo "ZipArchive is supported.\n";
}
else {
    echo "WARNING: ZipArchive class is missing!\n";
}