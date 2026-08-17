<?php
/** Lightweight aggregation contract used by the Ads Manager extension. */

require_once __DIR__ . '/../core/ExtensionAdsStats.php';

$passed = 0;
$failed = 0;
function extensionCheck(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "PASS $name\n";
        return;
    }
    $failed++;
    echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n";
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE clicks (
    id TEXT PRIMARY KEY,
    cost REAL DEFAULT 0,
    parameters_json TEXT,
    created_at DATETIME
)");
$pdo->exec("CREATE TABLE conversions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    click_id TEXT NOT NULL,
    status TEXT NOT NULL,
    payout REAL DEFAULT 0
)");

$today = date('Y-m-d');
$insertClick = $pdo->prepare('INSERT INTO clicks (id, cost, parameters_json, created_at) VALUES (?, ?, ?, ?)');
$insertClick->execute(['c1', 10, json_encode(['campaign_id' => '100', 'adset_id' => '200', 'ad_id' => '300']), "$today 09:00:00"]);
$insertClick->execute(['c2', 20, json_encode(['campaign_id' => '100', 'adset_id' => '200', 'ad_id' => '301']), "$today 10:00:00"]);
$insertClick->execute(['c3', 5, json_encode(['campaign_id' => '101', 'adset_id' => '201', 'ad_id' => '302']), "$today 11:00:00"]);
$insertClick->execute(['old', 99, json_encode(['campaign_id' => '100', 'adset_id' => '200', 'ad_id' => '300']), '2020-01-01 10:00:00']);

$insertConversion = $pdo->prepare('INSERT INTO conversions (click_id, status, payout) VALUES (?, ?, ?)');
$insertConversion->execute(['c1', 'lead', 20]);
$insertConversion->execute(['c1', 'approved', 80]);
$insertConversion->execute(['c2', 'sale', 50]);
$insertConversion->execute(['c3', 'pending', 10]);

$stats = orbitraExtensionAdsStats($pdo, $today, [
    'campaign_ids' => '100',
    'adset_ids' => ['200'],
    'ad_ids' => '300,301',
], '+00:00', 'payout');

$campaign = $stats['campaigns']['100'] ?? [];
extensionCheck('filters campaign ids', count($stats['campaigns']) === 1 && !isset($stats['campaigns']['101']));
extensionCheck('counts clicks once with multi-event conversions', ($campaign['clicks'] ?? 0) === 2);
extensionCheck('sums cost and all revenue', ($campaign['cost'] ?? 0) === 30.0 && ($campaign['revenue'] ?? 0) === 150.0, json_encode($campaign));
extensionCheck('splits lead and confirmed sales', ($campaign['leads'] ?? 0) === 1 && ($campaign['sales'] ?? 0) === 2);
extensionCheck('derives CPA CPL CPS', ($campaign['cpa'] ?? 0) === 10.0 && ($campaign['cpl'] ?? 0) === 30.0 && ($campaign['cps'] ?? 0) === 15.0);
extensionCheck('derives profit and ROI', ($campaign['profit'] ?? 0) === 120.0 && ($campaign['roi'] ?? 0) === 400.0);
$adKeys = array_map('strval', array_keys($stats['ads']));
sort($adKeys);
extensionCheck('returns requested ads only', $adKeys === ['300', '301']);
extensionCheck('normalizes only decimal IDs', orbitraExtensionAdsNormalizeIds('1, 2,abc,2,3.5') === ['1', '2']);
extensionCheck('validates dates', orbitraExtensionAdsResolveDate('2026-02-29') === null && orbitraExtensionAdsResolveDate('2026-02-28') === '2026-02-28');

echo "\nPassed: $passed, Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
