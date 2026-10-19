<?php
/**
 * CostImporter's "why did nothing match" diagnostic.
 *
 * Background: a TikTok MCP user saw "Fetched 51 records but matched none — check
 * that the campaign URL passes ad_id / adset_id / campaign_id" while his URL did
 * pass them. The generic hint gave him nothing to act on. The importer now
 * returns a diagnostic naming the concrete cause; this test pins each code.
 *
 * Run: php tests/cost_unmatched_diagnostic_test.php   (no network)
 */

require_once __DIR__ . '/../core/ClickParams.php';
require_once __DIR__ . '/../core/CurrencyRates.php';
require_once __DIR__ . '/../core/CostImporter.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}

function freshDb(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
    $pdo->exec("CREATE TABLE clicks (
        id TEXT PRIMARY KEY, campaign_id INTEGER, ip TEXT,
        cost REAL DEFAULT 0, fee REAL NOT NULL DEFAULT 0, revenue REAL DEFAULT 0,
        parameters_json TEXT, created_at DATETIME
    )");
    $pdo->exec("CREATE TABLE cost_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER, external_id TEXT,
        source_campaign_id TEXT, ad_id TEXT, adset_id TEXT, amount REAL, fee_amount REAL NOT NULL DEFAULT 0, impressions INTEGER, platform_clicks INTEGER,
        currency TEXT,
        click_date DATE, raw_json TEXT, is_matched INTEGER DEFAULT 0
    )");
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('currency', 'USD')");
    return $pdo;
}

function addClick(PDO $pdo, string $id, string $date, array $params): void
{
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, parameters_json, created_at) VALUES (?, 1, '1.1.1.1', ?, ?)")
        ->execute([$id, json_encode($params), $date . ' 12:00:00']);
}

function rec(string $date, float $amount, string $camp = '1846000000000000001', string $ad = '1846000000000000003'): array
{
    return [
        'external_id' => "tt_{$camp}_{$ad}_{$date}", 'source_campaign_id' => $camp,
        'ad_id' => $ad, 'adset_id' => '1846000000000000002',
        'amount' => $amount, 'currency' => 'USD', 'date' => $date, 'raw_json' => '{}',
    ];
}

$opts = ['exclude_safe_page' => false];
$d1 = '2026-10-10';
$d2 = '2026-10-11';

echo "\nCostImporter diagnostic\n";

// no_spend: rows exist, every amount is zero.
$pdo = freshDb();
$s = CostImporter::import($pdo, 1, [rec($d1, 0.0)], [], $opts);
check('no_spend when every row has zero spend', ($s['diagnostic']['code'] ?? null) === 'no_spend', json_encode($s['diagnostic']));

// no_clicks: spend on days the tracker saw nothing.
$pdo = freshDb();
addClick($pdo, 'c-other-day', '2026-10-01', ['ad_id' => '1846000000000000003']);
$s = CostImporter::import($pdo, 1, [rec($d1, 5.0), rec($d2, 7.0)], [], $opts);
check('no_clicks when no clicks exist on any spend day',
    ($s['diagnostic']['code'] ?? null) === 'no_clicks'
    && $s['diagnostic']['from'] === $d1 && $s['diagnostic']['to'] === $d2 && $s['diagnostic']['days'] === 2,
    json_encode($s['diagnostic']));

// no_ids: clicks that day carry no attribution keys at all (link lost the macros).
$pdo = freshDb();
addClick($pdo, 'c1', $d1, ['ttclid' => 'abc', 'sub_id_1' => 'x']);
addClick($pdo, 'c2', $d1, ['utm_source' => 'tiktok']);
$s = CostImporter::import($pdo, 1, [rec($d1, 12.5)], [], $opts);
check('no_ids when clicks that day carry no ad/adgroup/campaign IDs',
    ($s['diagnostic']['code'] ?? null) === 'no_ids'
    && $s['diagnostic']['date'] === $d1 && $s['diagnostic']['clicks'] === 2
    && $s['diagnostic']['amount'] == 12.5 && $s['diagnostic']['campaign_id'] === '1846000000000000001',
    json_encode($s['diagnostic']));

// macros: the platform did not substitute the macro.
$pdo = freshDb();
addClick($pdo, 'c1', $d1, ['campaign_id' => '__CAMPAIGN_ID__', 'ad_id' => '__CID__']);
$s = CostImporter::import($pdo, 1, [rec($d1, 3.0)], [], $opts);
check('macros when clicks carry unreplaced __MACRO__ values',
    ($s['diagnostic']['code'] ?? null) === 'macros' && strpos($s['diagnostic']['value'], '__') === 0,
    json_encode($s['diagnostic']));

// ids_differ: real IDs, just not the spent ones (another account / another campaign link).
$pdo = freshDb();
addClick($pdo, 'c1', $d1, ['campaign_id' => '1777000000000000001', 'ad_id' => '1777000000000000003']);
$s = CostImporter::import($pdo, 1, [rec($d1, 9.0)], [], $opts);
check('ids_differ names the IDs the clicks actually carry',
    ($s['diagnostic']['code'] ?? null) === 'ids_differ'
    && strpos($s['diagnostic']['seen'], 'ad_id=1777000000000000003') !== false
    && strpos($s['diagnostic']['seen'], 'campaign_id=1777000000000000001') !== false,
    json_encode($s['diagnostic']));

// A dead optional macro next to real IDs is not the cause (the real-world click:
// creative=__CREATIVE_ID__ alongside real campaign_id/adgroup_id/ad_id).
$pdo = freshDb();
addClick($pdo, 'c1', $d1, ['campaign_id' => '1878858016755778', 'adgroup_id' => '1878908990538914',
    'ad_id' => '1878908994123889', 'creative' => '__CREATIVE_ID__']);
$s = CostImporter::import($pdo, 1, [rec($d1, 9.0)], [], $opts);
check('leftover creative macro does not mask real IDs (ids_differ, macro not in sample)',
    ($s['diagnostic']['code'] ?? null) === 'ids_differ'
    && strpos($s['diagnostic']['seen'], 'ad_id=1878908994123889') !== false
    && strpos($s['diagnostic']['seen'], '__CREATIVE_ID__') === false,
    json_encode($s['diagnostic']));

// Picks the largest spend row whose day has clicks, not just the first row.
$pdo = freshDb();
addClick($pdo, 'c1', $d2, ['utm_source' => 'tiktok']);
$s = CostImporter::import($pdo, 1, [rec($d1, 50.0), rec($d2, 4.0, '1846000000000000009', '1846000000000000008')], [], $opts);
check('diagnoses the biggest spend row on a day that has clicks',
    ($s['diagnostic']['code'] ?? null) === 'no_ids' && $s['diagnostic']['date'] === $d2
    && $s['diagnostic']['campaign_id'] === '1846000000000000009',
    json_encode($s['diagnostic']));

// A successful match carries no diagnostic.
$pdo = freshDb();
addClick($pdo, 'c1', $d1, ['ad_id' => '1846000000000000003']);
$s = CostImporter::import($pdo, 1, [rec($d1, 6.0)], [], $opts);
check('matched import has no diagnostic', $s['matched'] === 1 && $s['diagnostic'] === null, json_encode($s));

// Re-sync of unchanged rows counts them by their stored outcome
// (used to report "matched 0" on every re-sync of an already attributed day).
$s2 = CostImporter::import($pdo, 1, [rec($d1, 6.0)], [], $opts);
check('unchanged re-sync still reports the row as matched',
    $s2['matched'] === 1 && $s2['unmatched'] === 0 && $s2['new'] === 0 && $s2['updated'] === 0 && $s2['diagnostic'] === null,
    json_encode($s2));

// An unchanged row that stayed unmatched is retried once its clicks exist
// (closed days never change amount, so skipping them meant "unmatched forever").
$pdo = freshDb();
$s1 = CostImporter::import($pdo, 1, [rec($d1, 8.0)], [], $opts);
addClick($pdo, 'late', $d1, ['ad_id' => '1846000000000000003']);
$s2 = CostImporter::import($pdo, 1, [rec($d1, 8.0)], [], $opts);
$late = (float) $pdo->query("SELECT cost FROM clicks WHERE id = 'late'")->fetchColumn();
$flag = (int) $pdo->query("SELECT is_matched FROM cost_records")->fetchColumn();
check('unchanged but unmatched row is retried and attributed on re-sync',
    $s1['matched'] === 0 && $s2['matched'] === 1 && $s2['updated'] === 1 && abs($late - 8.0) < 0.001 && $flag === 1,
    json_encode([$s1['matched'], $s2, $late, $flag]));

// TikTok template param: adgroup_id must be captured even without a source template.
echo "\nClickParams\n";
check('adgroup_id (TikTok __AID__) is in the baseline click keys', in_array('adgroup_id', orbitraClickParamKeys(), true));

echo PHP_EOL . ($failed === 0 ? "PASSED: $passed checks" : "FAILED: $failed of " . ($passed + $failed)) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
