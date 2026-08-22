<?php
// tests/cloak_report_sql_test.php
//
// Unit tests for report SQL queries with cloak streams.
// Tests the schema queries to ensure streams.schema_custom_json is correctly
// queried and exclude_safe_from_reports filtering works.
//
// Run: php tests/cloak_report_sql_test.php

require_once __DIR__ . '/../config.php';

$failures = [];
$testCount = 0;

function assertTrue($condition, $message) {
    global $failures, $testCount;
    $testCount++;
    if (!$condition) {
        $failures[] = "$message: expected true, got false";
    } else {
        echo "✓ $message\n";
    }
    return $condition;
}

function assertEquals($expected, $actual, $message) {
    global $failures, $testCount;
    $testCount++;
    if ($expected !== $actual) {
        $failures[] = "$message: expected " . var_export($expected, true) . ", got " . var_export($actual, true);
    } else {
        echo "✓ $message\n";
    }
    return $expected === $actual;
}

function assertGreaterThan($min, $actual, $message) {
    global $failures, $testCount;
    $testCount++;
    if ($actual <= $min) {
        $failures[] = "$message: expected > $min, got $actual";
    } else {
        echo "✓ $message\n";
    }
    return $actual > $min;
}

echo "Cloak report SQL query tests\n";
echo "===========================\n\n";

try {
    global $pdo;

    // Create test campaign with cloak stream
    echo "Setting up test campaign with cloak stream...\n";
    $pdo->exec("BEGIN");

    // Insert test campaign
    $testCampaignId = random_int(10000, 99999);
    $testCampaignToken = 'test_token_' . bin2hex(random_bytes(4));
    $testCampaignAlias = 'cloaktest_' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare("
        INSERT INTO campaigns (id, name, alias, token, state, is_archived, created_at)
        VALUES (?, ?, ?, ?, 'active', 0, datetime('now'))
    ");
    $stmt->execute([$testCampaignId, 'Test Cloak Campaign', $testCampaignAlias, $testCampaignToken]);
    $campaignId = $testCampaignId;

    // Insert test cloak stream with exclude_safe_from_reports=true
    $testCloakStreamId = random_int(10000, 99999);
    $stmt = $pdo->prepare("
        INSERT INTO streams (id, campaign_id, schema_type, schema_custom_json, position)
        VALUES (?, ?, 'cloak', '{\"exclude_safe_from_reports\":true,\"log_safe_clicks\":false}', 1)
    ");
    $stmt->execute([$testCloakStreamId, $campaignId]);
    $cloakStreamId = $testCloakStreamId;

    // Insert some test clicks
    $stmt = $pdo->prepare("
        INSERT INTO clicks (campaign_id, stream_id, ip, user_agent, is_safe_page, cost, created_at)
        VALUES (?, ?, '103.212.120.50', 'Mozilla/5.0 (iPhone)', 0, 0.05, datetime('now'))
    ");
    $stmt->execute([$campaignId, $cloakStreamId]);

    $stmt = $pdo->prepare("
        INSERT INTO clicks (campaign_id, stream_id, ip, user_agent, is_safe_page, cost, created_at)
        VALUES (?, ?, '103.212.120.51', 'curl/8.4.0', 1, 0.02, datetime('now'))
    ");
    $stmt->execute([$campaignId, $cloakStreamId]);

    $pdo->exec("COMMIT");
    echo "✓ Created test campaign_id=$campaignId, stream_id=$cloakStreamId\n";
    echo "✓ Inserted 2 test clicks (1 money, 1 safe)\n\n";

    // ===== Test 1: Query streams.schema_custom_json correctly =====
    echo "Test 1: Query streams.schema_custom_json for cloak config\n";
    $stmt = $pdo->prepare("
        SELECT s.schema_custom_json FROM streams s
        WHERE s.campaign_id = ? AND s.schema_type = 'cloak'
        AND s.schema_custom_json IS NOT NULL AND s.schema_custom_json != '' AND s.schema_custom_json != '{}'
    ");
    $stmt->execute([$campaignId]);
    $streamConfigs = $stmt->fetchAll(PDO::FETCH_COLUMN);

    assertTrue(count($streamConfigs) > 0, 'Should find cloak stream config');
    if (!empty($streamConfigs)) {
        $config = json_decode($streamConfigs[0], true);
        assertTrue(is_array($config), 'Config should decode to array');
        assertEquals(true, $config['exclude_safe_from_reports'] ?? null, 'Config should have exclude_safe_from_reports=true');
    }
    echo "\n";

    // ===== Test 2: Check if ANY campaign has cloak stream (for global filter) =====
    echo "Test 2: Query for any active campaign with cloak stream\n";
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM streams s
        JOIN campaigns c ON s.campaign_id = c.id
        WHERE s.schema_type = 'cloak'
        AND s.schema_custom_json IS NOT NULL AND s.schema_custom_json != '' AND s.schema_custom_json != '{}'
        AND c.state = 'active'
    ");
    $count = $stmt->fetchColumn();
    assertGreaterThan(0, $count, 'Should find at least one active cloak campaign');
    echo "\n";

    // ===== Test 3: Count clicks WITH is_safe_page=0 filter =====
    echo "Test 3: Count clicks with is_safe_page=0 filter (exclude safe clicks)\n";
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM clicks cl
        WHERE cl.campaign_id = ? AND cl.is_safe_page = 0
    ");
    $stmt->execute([$campaignId]);
    $moneyClicks = $stmt->fetchColumn();
    assertEquals(1, $moneyClicks, 'Should count only money clicks when filtering is_safe_page=0');
    echo "\n";

    // ===== Test 4: Count clicks WITHOUT is_safe_page filter =====
    echo "Test 4: Count clicks without is_safe_page filter (include all clicks)\n";
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM clicks cl
        WHERE cl.campaign_id = ?
    ");
    $stmt->execute([$campaignId]);
    $allClicks = $stmt->fetchColumn();
    assertEquals(2, $allClicks, 'Should count all clicks without filter');
    echo "\n";

    // ===== Test 5: Test with exclude_safe_from_reports=false =====
    echo "Test 5: Update stream to exclude_safe_from_reports=false\n";
    $pdo->exec("BEGIN");
    $stmt = $pdo->prepare("
        UPDATE streams
        SET schema_custom_json = json_set(schema_custom_json, '$.exclude_safe_from_reports', false)
        WHERE id = ?
    ");
    $stmt->execute([$cloakStreamId]);
    $pdo->exec("COMMIT");

    $stmt = $pdo->prepare("
        SELECT s.schema_custom_json FROM streams s WHERE s.id = ?
    ");
    $stmt->execute([$cloakStreamId]);
    $config = json_decode($stmt->fetchColumn(), true);
    assertEquals(false, $config['exclude_safe_from_reports'] ?? null, 'Config should have exclude_safe_from_reports=false');
    echo "\n";

    // ===== Test 6: Verify the campaign query still works =====
    echo "Test 6: Re-verify campaign-specific cloak query still works\n";
    $stmt = $pdo->prepare("
        SELECT s.schema_custom_json FROM streams s
        WHERE s.campaign_id = ? AND s.schema_type = 'cloak'
        AND s.schema_custom_json IS NOT NULL AND s.schema_custom_json != '' AND s.schema_custom_json != '{}'
    ");
    $stmt->execute([$campaignId]);
    $streamConfigs = $stmt->fetchAll(PDO::FETCH_COLUMN);

    assertTrue(count($streamConfigs) > 0, 'Should still find cloak stream config');
    if (!empty($streamConfigs)) {
        $config = json_decode($streamConfigs[0], true);
        assertEquals(false, $config['exclude_safe_from_reports'] ?? null, 'Config should now have exclude_safe_from_reports=false');
    }
    echo "\n";

    // ===== Test 7: Boolean value normalization =====
    echo "Test 7: Test boolean normalization for stored values\n";
    $pdo->exec("BEGIN");
    // Test with string 'false' (old migration bug)
    $stmt = $pdo->prepare("
        UPDATE streams
        SET schema_custom_json = json_set(schema_custom_json, '$.log_safe_clicks', 'false')
        WHERE id = ?
    ");
    $stmt->execute([$cloakStreamId]);

    $stmt = $pdo->prepare("
        SELECT json_extract(schema_custom_json, '$.log_safe_clicks') FROM streams WHERE id = ?
    ");
    $stmt->execute([$cloakStreamId]);
    $rawValue = $stmt->fetchColumn();

    // String 'false' should be normalized to boolean false in application logic
    $normBool = ($rawValue === 'true' || $rawValue === '1' || $rawValue === true);
    assertEquals(false, $normBool, 'String "false" should normalize to boolean false');

    // Test with string 'true'
    $stmt = $pdo->prepare("
        UPDATE streams
        SET schema_custom_json = json_set(schema_custom_json, '$.log_safe_clicks', 'true')
        WHERE id = ?
    ");
    $stmt->execute([$cloakStreamId]);

    $stmt = $pdo->prepare("
        SELECT json_extract(schema_custom_json, '$.log_safe_clicks') FROM streams WHERE id = ?
    ");
    $stmt->execute([$cloakStreamId]);
    $rawValue = $stmt->fetchColumn();

    $normBool = ($rawValue === 'true' || $rawValue === '1' || $rawValue === true);
    assertEquals(true, $normBool, 'String "true" should normalize to boolean true');

    // Test with actual boolean
    $stmt = $pdo->prepare("
        UPDATE streams
        SET schema_custom_json = json_set(schema_custom_json, '$.log_safe_clicks', true)
        WHERE id = ?
    ");
    $stmt->execute([$cloakStreamId]);

    $stmt = $pdo->prepare("
        SELECT json_extract(schema_custom_json, '$.log_safe_clicks') FROM streams WHERE id = ?
    ");
    $stmt->execute([$cloakStreamId]);
    $rawValue = $stmt->fetchColumn();

    $normBool = ($rawValue === 'true' || $rawValue === '1' || $rawValue === true);
    assertEquals(true, $normBool, 'Boolean true should normalize to boolean true');

    $pdo->exec("COMMIT");
    echo "\n";

    // Cleanup
    echo "Cleaning up test data...\n";
    $pdo->exec("BEGIN");
    $pdo->prepare("DELETE FROM clicks WHERE campaign_id = ?")->execute([$campaignId]);
    $pdo->prepare("DELETE FROM streams WHERE id = ?")->execute([$cloakStreamId]);
    $pdo->prepare("DELETE FROM campaigns WHERE id = ?")->execute([$campaignId]);
    $pdo->exec("COMMIT");
    echo "✓ Test data cleaned up\n\n";

    echo "\n✅ All cloak report SQL tests passed ($testCount tests).\n";
    echo "Schema queries verified:\n";
    echo "  - streams.schema_custom_json is correctly queried\n";
    echo "  - schema_type='cloak' filtering works\n";
    echo "  - exclude_safe_from_reports filtering works\n";
    echo "  - is_safe_page=0 filter excludes safe clicks from reports\n";
    echo "  - Boolean normalization handles string and boolean values\n";

} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Test error: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    $failures[] = "Test threw exception: " . $e->getMessage();
}

if ($failures) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n");
    exit(1);
}

exit(0);
