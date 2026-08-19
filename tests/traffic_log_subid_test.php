<?php
// tests/traffic_log_subid_test.php
//
// Tests that the SUBID column in Logs → Traffic correctly extracts sub_id_1
// from clicks.parameters_json using json_extract.
//
// Covers three cases:
//   1. sub_id_1 present in parameters_json
//   2. sub_id_1 absent (empty parameters_json or missing key)
//   3. malformed JSON in parameters_json
//
// The SQL query matches api.php case 'logs' / type 'traffic' around line 6242.
//
// Run: php tests/traffic_log_subid_test.php

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

// --- Throwaway database -----------------------------------------------------

$tmpDb = sys_get_temp_dir() . '/orbitra_traffic_subid_' . getmypid() . '.sqlite';
@unlink($tmpDb);
register_shutdown_function(static fn() => @unlink($tmpDb));

$pdo = new PDO('sqlite:' . $tmpDb, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Create tables needed for the traffic log query
$pdo->exec("CREATE TABLE clicks (
    id TEXT PRIMARY KEY,
    campaign_id INTEGER,
    offer_id INTEGER,
    ip TEXT,
    country_code TEXT,
    country TEXT,
    region TEXT,
    city TEXT,
    timezone TEXT,
    language TEXT,
    accept_language_raw TEXT,
    device_type TEXT,
    user_agent TEXT,
    parameters_json TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE campaigns (
    id INTEGER PRIMARY KEY,
    name TEXT
)");
$pdo->exec("CREATE TABLE offers (
    id INTEGER PRIMARY KEY,
    url TEXT
)");

// Insert campaign and offer for LEFT JOIN
$pdo->exec("INSERT INTO campaigns (id, name) VALUES (1, 'Test Campaign')");
$pdo->exec("INSERT INTO offers (id, url) VALUES (1, 'https://example.com/offer')");

// --- Test case 1: sub_id_1 present in parameters_json -----------------------

$click1Id = 'click-1-present';
$click1Params = json_encode([
    'sub_id_1' => 'abc123',
    'sub_id_2' => 'xyz789',
    'ad_id' => 'A-1',
]);
$pdo->prepare("INSERT INTO clicks (id, campaign_id, offer_id, ip, parameters_json)
               VALUES (?, 1, 1, '1.2.3.4', ?)")
    ->execute([$click1Id, $click1Params]);

// --- Test case 2: sub_id_1 absent (empty parameters_json) ------------------

$click2Id = 'click-2-empty-params';
$pdo->prepare("INSERT INTO clicks (id, campaign_id, offer_id, ip, parameters_json)
               VALUES (?, 1, 1, '2.3.4.5', ?)")
    ->execute([$click2Id, '']);

// --- Test case 3: sub_id_1 absent (valid JSON but missing key) -------------

$click3Id = 'click-3-missing-key';
$click3Params = json_encode([
    'sub_id_2' => 'only_two',
    'ad_id' => 'B-2',
]);
$pdo->prepare("INSERT INTO clicks (id, campaign_id, offer_id, ip, parameters_json)
               VALUES (?, 1, 1, '3.4.5.6', ?)")
    ->execute([$click3Id, $click3Params]);

// --- Test case 4: malformed JSON -------------------------------------------

$click4Id = 'click-4-malformed';
$pdo->prepare("INSERT INTO clicks (id, campaign_id, offer_id, ip, parameters_json)
               VALUES (?, 1, 1, '4.5.6.7', ?)")
    ->execute([$click4Id, '{invalid json}']);

// --- Test case 5: NULL parameters_json --------------------------------------

$click5Id = 'click-5-null';
$pdo->prepare("INSERT INTO clicks (id, campaign_id, offer_id, ip, parameters_json)
               VALUES (?, 1, 1, '5.6.7.8', NULL)")
    ->execute([$click5Id]);

// --- Execute the actual query from api.php ---------------------------------

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
        CASE WHEN json_valid(cl.parameters_json) THEN COALESCE(json_extract(cl.parameters_json, '$.sub_id_1'), '') ELSE '' END as subid
    FROM clicks cl
    LEFT JOIN campaigns c ON cl.campaign_id = c.id
    LEFT JOIN offers o ON cl.offer_id = o.id
    ORDER BY cl.created_at ASC
");
$stmt->execute();
$rows = $stmt->fetchAll();

// --- Verify results ----------------------------------------------------------

echo "Traffic log SUBID extraction tests:\n";

// Find each row by click_id
$row1 = current(array_filter($rows, fn($r) => $r['click_id'] === $click1Id));
$row2 = current(array_filter($rows, fn($r) => $r['click_id'] === $click2Id));
$row3 = current(array_filter($rows, fn($r) => $r['click_id'] === $click3Id));
$row4 = current(array_filter($rows, fn($r) => $r['click_id'] === $click4Id));
$row5 = current(array_filter($rows, fn($r) => $r['click_id'] === $click5Id));

$assert('Present sub_id_1 returns value', $row1['subid'], 'abc123');
$assert('Empty parameters_json returns empty string', $row2['subid'], '');
$assert('Missing sub_id_1 key returns empty string', $row3['subid'], '');
$assert('Malformed JSON returns empty string', $row4['subid'], '');
$assert('NULL parameters_json returns empty string', $row5['subid'], '');

// --- Summary -----------------------------------------------------------------

if ($failures > 0) {
    echo "\n$failures test(s) failed.\n";
    exit(1);
}

echo "\nAll traffic log SUBID tests passed.\n";
