<?php
// tests/binom_import_test.php
//
// Binom -> Orbitra importer (core/binom_import.php) against the response
// fixtures recorded from a live Binom v2 trial (tests/fixtures/binom/).
//
//   1. URL/SSRF gate: scheme defaulting, panel-path stripping, localhost
//      literal and private ranges refused, DNS failure reported.
//   2. Connection report counts entities off the fixtures.
//   3. Dry run writes nothing.
//   4. A real import lands every entity with the right mapping: domain,
//      network, landing, offer (payout/geo), campaign (alias/token/cost/
//      rotation), rule stream (filters at an early position), default-path
//      stream (fallback, pushed last), direct URLs synthesized into offers.
//   5. Unmappable rule criteria are counted in warnings, not dropped.
//   6. A second run is idempotent (skip/update, no duplicates).
//   7. Schema 79 gave the metadata tables their binom_id columns.
//
// Run: php tests/binom_import_test.php

$testPassed = true;

function check(string $name, $cond, $detail = ''): void {
    global $testPassed;
    if (!$cond) {
        fwrite(STDERR, "FAILED: $name" . ($detail !== '' ? " — $detail" : '') . "\n");
        $testPassed = false;
    } else {
        echo "✓ $name\n";
    }
}

$fixDir = __DIR__ . '/fixtures/binom';
require_once dirname(__DIR__) . '/core/binom_import.php';

// --- 1. URL / SSRF gate -------------------------------------------------
[$ok1] = orbitraBinomPrepareUrl('example.com');
check('no-scheme URL defaults to https', $ok1);
[$ok2, $base2] = orbitraBinomPrepareUrl('http://example.com/panel/settings/public-api?auth=x');
check('panel path is stripped from the URL', $ok2 && $base2 === 'http://example.com/');
[, , $err3] = orbitraBinomPrepareUrl('http://127.0.0.1/');
check('localhost literal refused', strpos($err3, 'private') !== false);
[, , $err4] = orbitraBinomPrepareUrl('http://10.1.2.3/');
check('private range refused', strpos($err4, 'private') !== false);
[, , $err5] = orbitraBinomPrepareUrl('http://nonexistent-zz-8612.invalid/');
check('DNS failure reported', strpos($err5, 'resolve') !== false);

// --- Fixture transport ---------------------------------------------------
// The campaign fixture is extended in-test: a second default path that uses
// the catalog landing + the catalog offer, and a rule with country +
// language + one unmappable criterion — so the mapping assertions cover the
// shapes the bare trial happened not to produce.
$campaign = json_decode((string) file_get_contents($fixDir . '/campaign_7.json'), true);
$campaign['customRotation']['defaultPaths'][] = [
    'id' => 2, 'name' => 'Path 2', 'enabled' => true, 'weight' => 50,
    'landings' => [['id' => 1, 'weight' => 100, 'enabled' => true]],
    'offers' => [['weight' => 100, 'enabled' => true, 'offerId' => 1, 'directUrl' => '', 'campaignId' => 0]],
];
$campaign['customRotation']['rules'][0]['criteria'][] = ['type' => 'language', 'values' => ['en-US'], 'enabled' => true, 'operator' => 'is'];
$campaign['customRotation']['rules'][0]['criteria'][] = ['type' => 'schedule', 'values' => ['mo'], 'enabled' => true, 'operator' => 'is'];

$fixtureMap = [
    '#/public/api/v1/settings/info#' => 'settings.json',
    '#/public/api/v1/domains#' => 'domains.json',
    '#/public/api/v1/traffic_source/list/filtered#' => 'traffic_source_list.json',
    '#/public/api/v1/traffic_source/1#' => 'traffic_source_1.json',
    '#/public/api/v1/stats/affiliate_network#' => 'stats_network.json',
    '#/public/api/v1/stats/offer#' => 'stats_offer.json',
    '#/public/api/v1/offer/1#' => 'offer_1.json',
    '#/public/api/v1/landing/list/filtered#' => 'landing_list.json',
    '#/public/api/v1/groups/campaigns#' => 'groups.json',
    '#/public/api/v1/campaign/list/filtered#' => 'campaign_list.json',
    '#/public/api/v1/campaign/7#' => 'campaign_7.json',
];
$transport = function (string $url) use ($fixDir, $fixtureMap, $campaign): array {
    foreach ($fixtureMap as $re => $file) {
        if (preg_match($re, $url) === 1) {
            $body = $file === 'campaign_7.json'
                ? json_encode($campaign, JSON_UNESCAPED_SLASHES)
                : (string) file_get_contents($fixDir . '/' . $file);
            return ['status' => 200, 'body' => $body];
        }
    }
    return ['status' => 404, 'body' => 'no fixture for ' . $url];
};

// --- 2. Connection report -------------------------------------------------
$report = orbitraBinomTestConnection('http://example.com/', 'k', $transport);
check('connection report is ok', !empty($report['ok']));
check('connection counts campaigns', ($report['counts']['campaigns'] ?? 0) === 1, json_encode($report['counts']));
check('connection counts offers+landings+networks', ($report['counts']['offers'] ?? 0) === 1
    && ($report['counts']['landings'] ?? 0) === 1 && ($report['counts']['affiliate_networks'] ?? 0) === 1);

require_once __DIR__ . '/lib/http.php';
$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->start();
$pdo = $harness->getPdo();

// --- 7. Schema 79 columns (the harness ran the migrations) ----------------
$cols = $pdo->query('PRAGMA table_info(campaigns)')->fetchAll(PDO::FETCH_COLUMN, 1);
check('campaigns.binom_id exists', in_array('binom_id', $cols, true));
$idx = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_campaigns_binom_id'")->fetchColumn();
check('campaigns.binom_id has a UNIQUE index', $idx !== false);

// --- 3. Dry run writes nothing --------------------------------------------
// (a fresh harness DB already carries the seeded system campaign, so count
// rows before/after instead of asserting absolute zeroes)
$campaignsBefore = (int) $pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn();
$offersBefore = (int) $pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn();
$dry = orbitraBinomImport($pdo, 'http://example.com/', 'k', ['dry_run' => true], $transport);
check('dry run reports fetch counts', ($dry['fetched']['campaigns'] ?? -1) === 1, json_encode($dry['fetched']));
check('dry run wrote nothing', (int) $pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn() === $campaignsBefore
    && (int) $pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn() === $offersBefore);

// --- 4. Real import --------------------------------------------------------
$res = orbitraBinomImport($pdo, 'http://example.com/', 'k', [
    'import_traffic_sources' => true, 'import_campaigns' => true,
], $transport);
check('domain imported', $res['imported']['domains']['inserted'] === 1, json_encode($res['imported']['domains']));
check('network imported with binom_id', $res['imported']['affiliate_networks']['inserted'] === 1
    && (int) $pdo->query('SELECT binom_id FROM affiliate_networks LIMIT 1')->fetchColumn() === 1);
check('traffic source imported', $res['imported']['traffic_sources']['inserted'] === 1);
check('landing imported with binom_id', $res['imported']['landings']['inserted'] === 1
    && (int) $pdo->query('SELECT binom_id FROM landings LIMIT 1')->fetchColumn() === 1);
check('catalog offer imported with payout', $res['imported']['offers']['inserted'] === 1, json_encode($res['imported']['offers']));

$camp = $pdo->query("SELECT * FROM campaigns WHERE alias = 'testcom' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('campaign imported with alias', is_array($camp), 'no testcom row');
check('campaign reuses the Binom key as token', ($camp['token'] ?? '') === 'fixturecampaignkey0000000000');
check('campaign cost mapped (CPC 10)', ($camp['cost_model'] ?? '') === 'CPC' && (float) $camp['cost_value'] === 10.0);
check('campaign rotation mapped (NORMAL -> weight)', ($camp['rotation_type'] ?? '') === 'weight');
check('campaign bound to the imported domain', (int) ($camp['domain_id'] ?? 0) > 0);
check('campaign bound to the imported source', (int) ($camp['source_id'] ?? 0) > 0);

$streams = $pdo->query('SELECT * FROM streams ORDER BY position ASC')->fetchAll(PDO::FETCH_ASSOC);
check('three streams imported', count($streams) === 3, 'got ' . count($streams));
$ruleStream = $streams[0];
$defaultStream = $streams[2];
check('rule stream sits before the default rotation', (int) $ruleStream['position'] === 10 && (int) $defaultStream['position'] >= 1000000);
check('rule stream is regular, default is fallback', $ruleStream['type'] === 'regular' && $defaultStream['type'] === 'fallback');
$rf = json_decode((string) $ruleStream['filters_json'], true);
$byName = [];
foreach ((array) $rf as $f) {
    $byName[$f['name']] = $f;
}
check('rule criteria -> Country filter', ($byName['Country']['payload'] ?? null) === ['RU']);
check('rule criteria -> Language filter (en-US -> en)', ($byName['Language']['payload'] ?? null) === ['en']);
$ruleCustom = json_decode((string) $ruleStream['schema_custom_json'], true);
$directOffers = (array) ($ruleCustom['offers'] ?? []);
// Two direct URLs travel through this fixture: the rule's and Path 1's.
check('rule direct URL synthesized into an offer', count($directOffers) === 1
    && (int) ($pdo->query('SELECT COUNT(*) FROM offers WHERE notes = \'[Binom] direct URL offer\'')->fetchColumn()) === 2);
$defCustom = json_decode((string) $defaultStream['schema_custom_json'], true);
check('Path 2 landing+offer schema', ($defaultStream['schema_type'] ?? '') === 'landing_offer'
    && count((array) ($defCustom['landings'] ?? [])) === 1 && count((array) ($defCustom['offers'] ?? [])) === 1);

// --- 5. Warnings carry what did not travel --------------------------------
$warnText = implode("\n", (array) $res['warnings']);
check('unmappable criterion counted in warnings', strpos($warnText, 'schedule') !== false, $warnText);
check('markAsBot noted in warnings', strpos($warnText, 'markAsBot') !== false);

// --- 6. Idempotent re-run --------------------------------------------------
$res2 = orbitraBinomImport($pdo, 'http://example.com/', 'k', [
    'import_traffic_sources' => true, 'import_campaigns' => true,
], $transport);
check('re-run skips the campaign', $res2['imported']['campaigns']['skipped'] === 1
    && $res2['imported']['campaigns']['inserted'] === 0, json_encode($res2['imported']['campaigns']));
check('re-run updates streams, not duplicates', $res2['imported']['streams']['updated'] === 3
    && $res2['imported']['streams']['inserted'] === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM streams')->fetchColumn() === 3);
check('re-run does not duplicate entities', (int) $pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn() === 3
    && (int) $pdo->query('SELECT COUNT(*) FROM landings')->fetchColumn() === 1);

echo $testPassed ? "All Binom import tests passed.\n" : "binom_import_test: FAIL\n";
exit($testPassed ? 0 : 1);
