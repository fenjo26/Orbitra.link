<?php
// Direct verification of the traffic log SUBID fix
// This script bypasses HTTP auth and directly tests the SQL query

require_once __DIR__ . '/../config.php';

$failures = 0;
$assert = function (string $label, $got, $expected) use (&$failures) {
    $ok = $got === $expected;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label;
    if (!$ok) {
        echo ' — got ' . var_export($got, true) . ', expected ' . var_export($expected, true);
        $failures++;
    }
    echo "\n";
};

echo "Verifying traffic log SUBID extraction...\n\n";

// Test with the actual query from api.php
$stmt = $pdo->prepare("
    SELECT
        cl.id,
        cl.id as click_id,
        datetime(cl.created_at) as created_at,
        c.name as campaign_name,
        cl.ip,
        COALESCE(NULLIF(cl.country_code, ''), cl.country) as country_code,
        cl.region,
        cl.city,
        cl.timezone as geo_timezone,
        cl.language,
        cl.accept_language_raw,
        cl.device_type,
        cl.user_agent,
        o.url as redirect_url,
        CASE WHEN json_valid(cl.parameters_json)
             THEN COALESCE(json_extract(cl.parameters_json, '$.sub_id_1'), '')
             ELSE '' END as subid
    FROM clicks cl
    LEFT JOIN campaigns c ON cl.campaign_id = c.id
    LEFT JOIN offers o ON cl.offer_id = o.id
    ORDER BY cl.created_at DESC
    LIMIT 5
");
$stmt->execute();
$rows = $stmt->fetchAll();

echo "Sample results from actual database:\n";
echo "========================================\n";

if (empty($rows)) {
    echo "No clicks found in database. Inserting test data...\n";

    // Insert test data
    $testClicks = [
        ['id' => 'test-click-with-subid', 'params' => json_encode(['sub_id_1' => 'test_subid_123', 'ad_id' => 'A-1'])],
        ['id' => 'test-click-without-subid', 'params' => json_encode(['ad_id' => 'B-2'])],
        ['id' => 'test-click-empty-params', 'params' => ''],
    ];

    foreach ($testClicks as $click) {
        $pdo->prepare("INSERT OR REPLACE INTO clicks (id, campaign_id, ip, parameters_json, created_at) VALUES (?, 1, '127.0.0.1', ?, datetime('now'))")
            ->execute([$click['id'], $click['params']]);
    }

    // Re-run query
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

foreach ($rows as $row) {
    echo "\nClick ID: {$row['click_id']}\n";
    echo "  SubID: " . ($row['subid'] ?: '(empty)') . "\n";
    echo "  IP: {$row['ip']}\n";
    echo "  Campaign: " . ($row['campaign_name'] ?: '(none)') . "\n";

    // For our test clicks, verify the expected values
    if ($row['click_id'] === 'test-click-with-subid') {
        $assert('Click with sub_id_1 returns the value', $row['subid'], 'test_subid_123');
    } elseif ($row['click_id'] === 'test-click-without-subid') {
        $assert('Click without sub_id_1 returns empty string', $row['subid'], '');
    } elseif ($row['click_id'] === 'test-click-empty-params') {
        $assert('Click with empty parameters_json returns empty string', $row['subid'], '');
    }
}

// Show the raw JSON output format expected by frontend
echo "\n========================================\n";
echo "JSON response format (like /api.php):\n";
echo json_encode(['status' => 'success', 'data' => $rows], JSON_PRETTY_PRINT);

echo "\n\n========================================\n";
if ($failures === 0) {
    echo "✓ Traffic log SUBID extraction verified!\n";
} else {
    echo "✗ $failures verification(s) failed.\n";
    exit(1);
}
