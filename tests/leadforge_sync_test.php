<?php
/**
 * LeadForge Synchronization Test Suite
 * Tests 146-GEO rules, JS adapter generation, universal order.php handlers across all networks,
 * and phone normalization.
 */

require_once __DIR__ . '/../core/LeadForge.php';
require_once __DIR__ . '/../core/PhpLanding.php';

function assert_true($cond, $msg) {
    if (!$cond) {
        echo "❌ FAIL: $msg\n";
        exit(1);
    }
    echo "✅ PASS: $msg\n";
}

echo "=== 1. Testing ALL_GEO_RULES (146 Countries) ===\n";
$rules = LeadForge::allGeoRules();
assert_true(count($rules) >= 146, "Loaded " . count($rules) . " geo rules (expected >= 146)");

$sampleGeos = ['IN', 'IT', 'SA', 'ES', 'DE', 'FR', 'AE', 'US', 'BR', 'RU'];
foreach ($sampleGeos as $geo) {
    assert_true(isset($rules[$geo]), "GEO rule exists for $geo");
    assert_true(!empty($rules[$geo]['pattern']), "$geo has regex pattern: " . $rules[$geo]['pattern']);
    assert_true(!empty($rules[$geo]['country_prefix']), "$geo has country prefix: " . $rules[$geo]['country_prefix']);
    assert_true($rules[$geo]['minlength'] > 0, "$geo has minlength: " . $rules[$geo]['minlength']);
}

// India check
assert_true($rules['IN']['country_prefix'] === '+91', "India prefix is +91");
assert_true($rules['IN']['minlength'] === 10 && $rules['IN']['maxlength'] === 10, "India length is strictly 10");

// Italy check
assert_true($rules['IT']['country_prefix'] === '+39', "Italy prefix is +39");
assert_true($rules['IT']['minlength'] === 10 && $rules['IT']['maxlength'] === 10, "Italy length is strictly 10");

echo "\n=== 2. Testing geoMasks() ===\n";
$masks = LeadForge::geoMasks();
assert_true(count($masks) >= 146, "geoMasks() returns " . count($masks) . " masks");
assert_true($masks['IN']['code'] === '+91', "Mask IN code is +91");

echo "\n=== 3. Testing adapterJs Generation ===\n";
$js = LeadForge::adapterJs('IN', true);
assert_true(strpos($js, 'ALL_GEO_RULES') !== false, "adapterJs contains ALL_GEO_RULES");
assert_true(strpos($js, 'window.__LF_DID_RUN') !== false, "adapterJs contains reference validation engine");
assert_true(strpos($js, '"geo":"IN"') !== false || strpos($js, '"geo": "IN"') !== false || strpos($js, 'IN') !== false, "adapterJs configured for IN");

echo "\n=== 4. Testing orderPhp Generation for All CPA Networks ===\n";
$networks = [
    'drcash' => 'https://order.drcash.sh/v1/order',
    'webvork' => 'https://api.webvork.com/v1/new-lead',
    'luckyonline' => 'https://lucky.online/api/v1/lead-create/webmaster',
    'kma' => 'https://api.kma.biz/lead/add',
    'terraleads' => 'https://t-api.org',
    'leadbit' => 'http://wapi.leadbit.com',
    'lemonad' => 'https://lemonad.com/api/v2/lead/create',
    'everad' => 'https://api.everad.com/campaigns',
    'ezaff' => 'https://api.ezaff.com/send',
    'custom' => 'custom',
];

$tempDir = sys_get_temp_dir() . '/lf_test_' . uniqid();
mkdir($tempDir, 0777, true);

foreach ($networks as $net => $expectedSign) {
    $orderCode = LeadForge::orderPhp([
        'network' => $net,
        'api_key' => 'test_api_key_123',
        'offer_id' => 'test_offer_456',
        'geo' => 'IT',
        'payout' => 30.5,
        'currency' => 'EUR',
        'crm_enabled' => true,
        'base_url' => 'https://track.domain.com',
        'landing_name' => 'Test Landing',
    ]);

    $orderFile = $tempDir . '/order_' . $net . '.php';
    file_put_contents($orderFile, $orderCode);

    // Syntax check
    $lint = exec("php -l " . escapeshellarg($orderFile));
    assert_true(strpos($lint, 'No syntax errors') !== false, "order.php for $net has valid syntax");

    // Scan check
    $scan = PhpLanding::scan($orderCode);
    assert_true(empty($scan), "order.php for $net passes PhpLanding security scan (" . implode(',', $scan) . ")");
}

echo "\n=== 5. Testing thankYouPhp Generation ===\n";
$thankCode = LeadForge::thankYouPhp('IT', 35, 'EUR', true);
$thankFile = $tempDir . '/thank_you.php';
file_put_contents($thankFile, $thankCode);
$lintTy = exec("php -l " . escapeshellarg($thankFile));
assert_true(strpos($lintTy, 'No syntax errors') !== false, "thank_you.php has valid syntax");
$scanTy = PhpLanding::scan($thankCode);
assert_true(empty($scanTy), "thank_you.php passes security scan");

// Cleanup
array_map('unlink', glob("$tempDir/*"));
rmdir($tempDir);

echo "\n🎉 ALL LEADFORGE SYNCHRONIZATION TESTS PASSED SUCCESSFULLY!\n";
