<?php
/**
 * tests/zone_rules_test.php
 *
 * Zone Killer: rule upsert + validation, the candidates table (min spend /
 * zero conversions / ROI cap / already-blocked), network campaign id
 * resolution, blocking through the engine write API (GET-merge-PUT asserted
 * on AdMaven, error journaling on refusal), unblock, and the auto run's cap.
 *
 *     php tests/zone_rules_test.php
 *
 * No network: AdMavenEngine routes through its $testHttpHandler.
 */

require_once __DIR__ . '/../aggregator_engines/AdMavenEngine.php';
require_once __DIR__ . '/../aggregator_engines/KadamEngine.php';
require_once __DIR__ . '/../aggregator_engines/GalaxionEngine.php';
require_once __DIR__ . '/../core/ClickParams.php';
require_once __DIR__ . '/../core/CurrencyRates.php';
require_once __DIR__ . '/../core/CostImporter.php';
require_once __DIR__ . '/../core/ZoneRules.php';

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
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
$pdo->exec("CREATE TABLE campaigns (id INTEGER PRIMARY KEY, name TEXT, is_archived INTEGER DEFAULT 0)");
$pdo->exec("CREATE TABLE aggregator_connections (id INTEGER PRIMARY KEY, name TEXT, engine TEXT, credentials_json TEXT, field_mapping_json TEXT)");
$pdo->exec("CREATE TABLE zone_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER NOT NULL, connection_id INTEGER NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1, mode TEXT NOT NULL DEFAULT 'dry_run', window_days INTEGER NOT NULL DEFAULT 7,
    min_spend REAL NOT NULL DEFAULT 3, require_zero_conversions INTEGER NOT NULL DEFAULT 1, max_roi REAL,
    max_blocks_per_run INTEGER NOT NULL DEFAULT 20, last_run_at DATETIME, created_at DATETIME, updated_at DATETIME,
    UNIQUE(campaign_id))");
$pdo->exec("CREATE TABLE zone_blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER NOT NULL, connection_id INTEGER NOT NULL,
    engine TEXT NOT NULL DEFAULT '', network_campaign_id TEXT NOT NULL DEFAULT '', zone_id TEXT NOT NULL,
    action TEXT NOT NULL, reason TEXT NOT NULL DEFAULT '', metrics_json TEXT NOT NULL DEFAULT '{}',
    acted_by TEXT NOT NULL DEFAULT 'manual', ok INTEGER NOT NULL DEFAULT 1, error TEXT NOT NULL DEFAULT '',
    created_at DATETIME DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE clicks (
    id TEXT PRIMARY KEY, campaign_id INTEGER, cost REAL DEFAULT 0, fee REAL NOT NULL DEFAULT 0,
    revenue REAL DEFAULT 0, is_conversion INTEGER DEFAULT 0, parameters_json TEXT, created_at DATETIME)");

$pdo->exec("INSERT INTO campaigns (id, name) VALUES (1, 'Push camp')");
$pdo->exec("INSERT INTO aggregator_connections (id, name, engine, credentials_json, field_mapping_json) VALUES (5, 'AdMaven main', 'admaven', '{}', '{}')");
$pdo->exec("INSERT INTO aggregator_connections (id, name, engine, credentials_json) VALUES (6, 'FB', 'facebook', '{}')");

$today = date('Y-m-d');
$ins = $pdo->prepare("INSERT INTO clicks (id, campaign_id, cost, revenue, is_conversion, parameters_json, created_at) VALUES (?,?,?,?,?,?,?)");
// zBurn: $5 spend, no conversions → candidate under min_spend=3 + zero-conv.
$ins->execute(['b1', 1, 2.5, 0, 0, json_encode(['ad_campaign_id' => '829137', 'source' => 'zBurn']), $today . ' 10:00:00']);
$ins->execute(['b2', 1, 2.5, 0, 0, json_encode(['ad_campaign_id' => '829137', 'source' => 'zBurn']), $today . ' 11:00:00']);
// zProfit: spend $2, revenue $10, has a conversion → NOT a candidate.
$ins->execute(['p1', 1, 2.0, 10.0, 1, json_encode(['ad_campaign_id' => '829137', 'source' => 'zProfit']), $today . ' 12:00:00']);
// zSmall: $0.5 spend — below min_spend.
$ins->execute(['s1', 1, 0.5, 0, 0, json_encode(['ad_campaign_id' => '829137', 'source' => 'zSmall']), $today . ' 13:00:00']);
// zRoi: spend $4, revenue $0, no conversions — candidate by spend AND ROI -100 ≤ -80.
$ins->execute(['r1', 1, 4.0, 0, 0, json_encode(['ad_campaign_id' => '829137', 'source' => 'zRoi']), $today . ' 14:00:00']);
// Outside the campaign: never counted.
$ins->execute(['x1', 2, 100.0, 0, 0, json_encode(['source' => 'zBurn']), $today . ' 15:00:00']);

// ---- Rule CRUD ---------------------------------------------------------------

echo "\nrule\n";

try {
    ZoneRules::saveRule($pdo, 1, ['connection_id' => 6]);
    check('non-push connection rejected', false);
} catch (RuntimeException $e) {
    check('non-push connection rejected', str_contains($e->getMessage(), 'push/pop cost connection'));
}

$rule = ZoneRules::saveRule($pdo, 1, [
    'connection_id' => 5, 'is_active' => 1, 'mode' => 'dry_run',
    'window_days' => 99, 'min_spend' => 3, 'require_zero_conversions' => 1,
    'max_roi' => '', 'max_blocks_per_run' => 2,
]);
check('rule stored with clamped window', (int) $rule['window_days'] === 30, json_encode($rule));
check('empty max_roi stored as null', $rule['max_roi'] === null);

$rule = ZoneRules::saveRule($pdo, 1, [
    'connection_id' => 5, 'is_active' => 1, 'mode' => 'dry_run',
    'window_days' => 7, 'min_spend' => 3, 'require_zero_conversions' => 1,
    'max_roi' => -80, 'max_blocks_per_run' => 1,
]);
check('rule upserts, one row per campaign', (int) $pdo->query("SELECT COUNT(*) FROM zone_rules")->fetchColumn() === 1);
check('default mode is dry_run', $rule['mode'] === 'dry_run');

// ---- Candidates --------------------------------------------------------------

echo "\ncandidates\n";

$table = ZoneRules::candidates($pdo, 1);
$byZone = [];
foreach ($table['zones'] as $z) {
    $byZone[$z['zone']] = $z;
}
check('zBurn is a candidate (spend≥3, 0 conversions)', $byZone['zBurn']['candidate'] === true, json_encode($byZone['zBurn'] ?? null));
check('zRoi is a candidate (ROI −100 ≤ −80)', $byZone['zRoi']['candidate'] === true);
check('zProfit NOT a candidate (has conversion)', $byZone['zProfit']['candidate'] !== true);
check('zSmall NOT a candidate (below min spend)', $byZone['zSmall']['candidate'] !== true);
check('spend per zone sums clicks.cost', abs($byZone['zBurn']['spend'] - 5.0) < 0.001, 'got ' . ($byZone['zBurn']['spend'] ?? '?'));
check('roi computed', $byZone['zRoi']['roi'] === -100.0, 'got ' . var_export($byZone['zRoi']['roi'] ?? null, true));

// ---- Network campaign id -----------------------------------------------------

echo "\nnetwork campaign id\n";

check('network campaign id from click params', ZoneRules::networkCampaignId($pdo, 1) === '829137', ZoneRules::networkCampaignId($pdo, 1) ?? 'null');

// ---- Blocking through the engine ---------------------------------------------

echo "\nblock\n";

$putBodies = [];
AdMavenEngine::$testHttpHandler = function (string $method, string $path, array $query, array $credentials, ?array $jsonBody) use (&$putBodies) {
    if ($method === 'GET' && $path === '/api/public/targeting/source_id') {
        return ['body' => json_encode(['message' => ['values' => ['someoneElseZone'], 'include' => false]]), 'error' => null];
    }
    if ($method === 'PUT') {
        $putBodies[] = $jsonBody;
        return ['body' => json_encode(['type' => 'success']), 'error' => null];
    }
    return ['body' => '{}', 'error' => null];
};

$result = ZoneRules::block($pdo, 1, ['zBurn'], 'manual', 'test');
check('block reports ok', $result['ok'] === 1 && $result['failed'] === 0, json_encode($result));
check('GET-merge-PUT kept foreign zone and added ours',
    count($putBodies) === 1
    && in_array('someoneElseZone', $putBodies[0]['values'], true)
    && in_array('zBurn', $putBodies[0]['values'], true)
    && $putBodies[0]['include'] === false,
    json_encode($putBodies));
check('journal row written with metrics', (int) $pdo->query("SELECT COUNT(*) FROM zone_blocks WHERE ok = 1 AND action = 'block' AND zone_id = 'zBurn'")->fetchColumn() === 1);
$metrics = json_decode((string) $pdo->query("SELECT metrics_json FROM zone_blocks WHERE zone_id = 'zBurn'")->fetchColumn(), true);
check('decision metrics journaled', abs(($metrics['spend'] ?? 0) - 5.0) < 0.001, json_encode($metrics));
check('blockedZones now lists zBurn', isset(ZoneRules::blockedZones($pdo, 1)['zBurn']));
check('network campaign id journaled', (string) $pdo->query("SELECT network_campaign_id FROM zone_blocks WHERE zone_id = 'zBurn'")->fetchColumn() === '829137');

// Blocked zones drop out of candidates.
$table = ZoneRules::candidates($pdo, 1);
$byZone = [];
foreach ($table['zones'] as $z) {
    $byZone[$z['zone']] = $z;
}
check('blocked zone is not a candidate again', $byZone['zBurn']['candidate'] !== true && $byZone['zBurn']['blocked'] === true);

// Whitelist-mode refusal: journaled as a failure, nothing blocked.
AdMavenEngine::$testHttpHandler = function (string $method, string $path, array $query, array $credentials, ?array $jsonBody) {
    if ($method === 'GET') {
        return ['body' => json_encode(['message' => ['values' => [], 'include' => true]]), 'error' => null];
    }
    return ['body' => '{}', 'error' => null];
};
$result = ZoneRules::block($pdo, 1, ['zRoi'], 'manual', 'test');
check('whitelist-mode campaign refused', $result['ok'] === 0 && str_contains($result['error'], 'whitelist'), json_encode($result));
check('refusal journaled ok=0', (int) $pdo->query("SELECT COUNT(*) FROM zone_blocks WHERE ok = 0 AND error != ''")->fetchColumn() === 1);
check('zRoi not in blocked set', !isset(ZoneRules::blockedZones($pdo, 1)['zRoi']));

// ---- Unblock -----------------------------------------------------------------

echo "\nunblock\n";

AdMavenEngine::$testHttpHandler = function (string $method, string $path, array $query, array $credentials, ?array $jsonBody) use (&$putBodies) {
    if ($method === 'GET') {
        return ['body' => json_encode(['message' => ['values' => ['zBurn', 'other'], 'include' => false]]), 'error' => null];
    }
    if ($method === 'PUT') {
        $putBodies[] = $jsonBody;
        return ['body' => json_encode(['type' => 'success']), 'error' => null];
    }
    return ['body' => '{}', 'error' => null];
};
$result = ZoneRules::unblock($pdo, 1, ['zBurn']);
check('unblock reports ok', $result['ok'] === 1, json_encode($result));
check('unblock PUT removed only our zone',
    end($putBodies)['values'] === ['other'], json_encode(end($putBodies)));
check('zBurn out of the blocked set', !isset(ZoneRules::blockedZones($pdo, 1)['zBurn']));

// ---- Auto run ----------------------------------------------------------------

echo "\nauto run\n";

AdMavenEngine::$testHttpHandler = function (string $method, string $path, array $query, array $credentials, ?array $jsonBody) {
    if ($method === 'GET') {
        return ['body' => json_encode(['message' => ['values' => [], 'include' => false]]), 'error' => null];
    }
    return ['body' => json_encode(['type' => 'success']), 'error' => null];
};

// max_blocks_per_run = 1, two candidates (zBurn unblocked again + zRoi).
ZoneRules::saveRule($pdo, 1, [
    'connection_id' => 5, 'is_active' => 1, 'mode' => 'auto',
    'window_days' => 7, 'min_spend' => 3, 'require_zero_conversions' => 1,
    'max_roi' => -80, 'max_blocks_per_run' => 1,
]);
$summary = ZoneRules::runAuto($pdo);
check('auto run blocked exactly the cap', $summary['blocked'] === 1, json_encode($summary));
check('journal marks the auto action', (int) $pdo->query("SELECT COUNT(*) FROM zone_blocks WHERE acted_by = 'auto' AND ok = 1")->fetchColumn() === 1);
check('last_run_at stamped', $pdo->query("SELECT last_run_at FROM zone_rules WHERE campaign_id = 1")->fetchColumn() !== null);

// Dry-run rules never execute: flip the mode back and confirm no new blocks.
ZoneRules::saveRule($pdo, 1, [
    'connection_id' => 5, 'is_active' => 1, 'mode' => 'dry_run',
    'window_days' => 7, 'min_spend' => 3, 'require_zero_conversions' => 1,
    'max_roi' => -80, 'max_blocks_per_run' => 10,
]);
$before = (int) $pdo->query("SELECT COUNT(*) FROM zone_blocks")->fetchColumn();
ZoneRules::runAuto($pdo);
$after = (int) $pdo->query("SELECT COUNT(*) FROM zone_blocks")->fetchColumn();
check('dry-run rule executes nothing', $before === $after, "$before → $after");

AdMavenEngine::$testHttpHandler = null;

// ---- Kadam / Galaxion (API blocking) and Adsterra / Clickadu (manual) ---------

echo "\nother networks\n";

check('canBlock: kadam, galaxion yes', ZoneRules::canBlock('kadam') && ZoneRules::canBlock('galaxion'));
check('canBlock: adsterra, clickadu no', !ZoneRules::canBlock('adsterra') && !ZoneRules::canBlock('clickadu'));

$pdo->exec("INSERT INTO campaigns (id, name) VALUES (3, 'Kadam camp'), (4, 'Galaxion camp'), (5, 'Adsterra camp')");
$pdo->exec("INSERT INTO aggregator_connections (id, name, engine, credentials_json, field_mapping_json) VALUES
    (7, 'Kadam', 'kadam', '{\"api_token\":\"T\"}', '{}'),
    (8, 'Galaxion', 'galaxion', '{\"api_token\":\"G\"}', '{}'),
    (9, 'Adsterra', 'adsterra', '{\"api_key\":\"A\"}', '{}')");
$ins->execute(['k1', 3, 4.0, 0, 0, json_encode(['ad_campaign_id' => '955714', 'source' => 'site_77']), $today . ' 10:00:00']);
$ins->execute(['g1', 4, 4.0, 0, 0, json_encode(['ad_campaign_id' => '2192879', 'source' => '364306']), $today . ' 10:00:00']);
$ins->execute(['a1', 5, 4.0, 0, 0, json_encode(['ad_campaign_id' => '1509244', 'source' => '26109447']), $today . ' 10:00:00']);
$baseRule = ['is_active' => 1, 'mode' => 'dry_run', 'window_days' => 7, 'min_spend' => 3, 'require_zero_conversions' => 1, 'max_roi' => '', 'max_blocks_per_run' => 5];

// Kadam: one POST /stats/sites/block with the numeric campaign id + zones.
ZoneRules::saveRule($pdo, 3, ['connection_id' => 7] + $baseRule);
$kadamCalls = [];
KadamEngine::$testHttpHandler = function (string $method, string $path, array $body) use (&$kadamCalls) {
    $kadamCalls[] = [$method, $path, $body];
    return ['body' => json_encode(['success' => true, 'code' => 200]), 'error' => null];
};
$r = ZoneRules::block($pdo, 3, ['site_77'], 'manual', 'test');
check('kadam block ok', $r['ok'] === 1, json_encode($r));
check('kadam POST /stats/sites/block {campaignIds:[int], zones}',
    ($kadamCalls[0][0] ?? '') === 'POST' && ($kadamCalls[0][1] ?? '') === '/stats/sites/block'
    && ($kadamCalls[0][2]['campaignIds'] ?? null) === [955714] && ($kadamCalls[0][2]['zones'] ?? null) === ['site_77'],
    json_encode($kadamCalls));
$r = ZoneRules::unblock($pdo, 3, ['site_77']);
check('kadam unblock hits /stats/sites/unblock', $r['ok'] === 1 && ($kadamCalls[1][1] ?? '') === '/stats/sites/unblock');
KadamEngine::$testHttpHandler = function () {
    return ['body' => json_encode(['success' => false, 'code' => 422, 'msg' => ['campaignIds' => ['Unknown campaign']]]), 'error' => null];
};
$r = ZoneRules::block($pdo, 3, ['site_77'], 'manual', 'test');
check('kadam success=false → failure journaled with the reason', $r['ok'] === 0 && str_contains((string) $r['error'], 'Unknown campaign'), json_encode($r));
KadamEngine::$testHttpHandler = null;

// Galaxion: GET-merge-PATCH, foreign zones kept, blacklist flag set, 204 ok.
ZoneRules::saveRule($pdo, 4, ['connection_id' => 8] + $baseRule);
$gxCalls = [];
$gxList = [111];
GalaxionEngine::$testHttpHandler = function (string $method, string $path, array $params, array $auth) use (&$gxCalls, &$gxList) {
    $gxCalls[] = [$method, $path, $params, $auth];
    if ($method === 'GET') {
        return ['body' => json_encode(['id' => 2192879, 'name' => 'GB041010M', 'targeting' => ['zoneLimitations' => $gxList, 'zoneLimitationsExclude' => true, 'countries' => ['ID']]]), 'error' => null];
    }
    return ['body' => '', 'error' => null];  // 204
};
$r = ZoneRules::block($pdo, 4, ['364306'], 'manual', 'test');
$patch = $gxCalls[1] ?? [];
check('galaxion block ok', $r['ok'] === 1, json_encode($r));
check('galaxion PATCHes the campaign with only name + zone fields',
    ($patch[0] ?? '') === 'PATCH' && ($patch[1] ?? '') === '/v1/advertiser/campaigns/2192879'
    && ($patch[2]['name'] ?? '') === 'GB041010M' && array_keys($patch[2]['targeting'] ?? []) === ['zoneLimitations', 'zoneLimitationsExclude'],
    json_encode($patch));
check('galaxion merge keeps foreign zone, ids numeric', ($patch[2]['targeting']['zoneLimitations'] ?? null) === [111, 364306], json_encode($patch[2]['targeting'] ?? null));
check('galaxion blacklist flag', ($patch[2]['targeting']['zoneLimitationsExclude'] ?? null) === true);
check('galaxion token sent', ($patch[3]['token'] ?? '') === 'G');
$gxCalls = [];
$gxList = [111, 364306];
$r = ZoneRules::unblock($pdo, 4, ['364306']);
check('galaxion unblock removes only our zone', $r['ok'] === 1 && ($gxCalls[1][2]['targeting']['zoneLimitations'] ?? null) === [111], json_encode($gxCalls));
GalaxionEngine::$testHttpHandler = function (string $method) {
    if ($method === 'GET') {
        return ['body' => json_encode(['id' => 1, 'name' => 'W', 'targeting' => ['zoneLimitations' => [5, 6], 'zoneLimitationsExclude' => false]]), 'error' => null];
    }
    return ['body' => '', 'error' => 'PATCH must not be sent'];
};
$r = ZoneRules::block($pdo, 4, ['364306'], 'manual', 'test');
check('galaxion whitelist campaign refused, nothing written', $r['ok'] === 0 && str_contains((string) $r['error'], 'whitelist'), json_encode($r));
GalaxionEngine::$testHttpHandler = null;

// Adsterra (no write API): auto is clamped to dry-run, blocking explains why,
// and a rule forced to auto in the DB is skipped by the cron.
$ad = ZoneRules::saveRule($pdo, 5, ['connection_id' => 9, 'mode' => 'auto'] + $baseRule);
check('manual network: auto mode stored as dry_run', $ad['mode'] === 'dry_run', json_encode($ad));
$r = ZoneRules::block($pdo, 5, ['26109447'], 'manual', 'test');
check('manual network: block refused with a copy hint', $r['ok'] === 0 && str_contains((string) $r['error'], 'copy the zones'), json_encode($r));
$tbl = ZoneRules::candidates($pdo, 5);
check('manual network: candidates still listed', in_array('26109447', $tbl['candidates'], true), json_encode($tbl['candidates']));
$pdo->exec("UPDATE zone_rules SET mode = 'auto' WHERE campaign_id = 5");
$before = (int) $pdo->query("SELECT COUNT(*) FROM zone_blocks WHERE campaign_id = 5")->fetchColumn();
$summary = ZoneRules::runAuto($pdo, 5);
$after = (int) $pdo->query("SELECT COUNT(*) FROM zone_blocks WHERE campaign_id = 5")->fetchColumn();
check('manual network: cron skips an auto rule, journals nothing',
    ($summary['details'][0]['status'] ?? '') === 'manual_only' && $after === $before, json_encode($summary));

// ---- Summary -----------------------------------------------------------------

echo "\n" . str_repeat('-', 60) . "\n";
echo "passed: $passed   failed: $failed\n";
exit($failed === 0 ? 0 : 1);
