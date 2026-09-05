<?php
/**
 * tests/extension_stats_test.php
 *
 * ExtensionStats::deepStats against a hand-computed fixture: attributed FB
 * spend fused with tracker revenue, daily history, landing/offer breakdowns
 * and the CAPI delivered-vs-recorded accuracy. Run from the project root:
 *
 *     php tests/extension_stats_test.php
 */

require_once __DIR__ . '/../core/ReportMetrics.php';
require_once __DIR__ . '/../core/ExtensionStats.php';

$passed = 0;
$failed = 0;

function check(string $name, $expected, $actual): void
{
    global $passed, $failed;
    $ok = $expected === $actual;
    if ($ok) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name — expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec("CREATE TABLE clicks (
    id TEXT PRIMARY KEY, campaign_id INTEGER, landing_id INTEGER, offer_id INTEGER,
    cost REAL DEFAULT 0, uniq_campaign INTEGER DEFAULT 1, is_safe_page INTEGER DEFAULT 0,
    parameters_json TEXT, created_at DATETIME
)");
// streams is what orbitraSafePagePredicate() resolves the per-campaign
// "Exclude Safe Page clicks from reports" setting from.
$pdo->exec("CREATE TABLE streams (
    id INTEGER PRIMARY KEY, campaign_id INTEGER, schema_type TEXT, schema_custom_json TEXT
)");
$pdo->exec("CREATE TABLE conversions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, click_id TEXT, status TEXT, payout REAL
)");
$pdo->exec("CREATE TABLE landings (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE offers (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE s2s_postbacks_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, conversion_id INTEGER, url TEXT, status TEXT
)");

// Fixture: adset 1201 over two days. Spend is what the cost importer wrote
// onto clicks. Sales carry payout (confirmed revenue), leads are 'lead'.
//
// 2026-08-16: 3 clicks (cost 10+20+30=60), 1 sale payout 150, 1 lead
// 2026-08-17: 2 clicks (cost 15+25=40), 1 sale payout 100, 1 delivered CAPI row
//             (the 2026-08-16 sale has NO CAPI row — pixel dropped it)
// click c3 saw landing 4 then offer 12 (lp_click), c1/c2 offer-only.
// A click for adset 9999 (no revenue) must not leak into 1201.
// A click for 1201 OUTSIDE the range must be excluded.
$ins = $pdo->prepare("INSERT INTO clicks (id, campaign_id, landing_id, offer_id, cost, uniq_campaign, parameters_json, created_at) VALUES (?,?,?,?,?,?,?,?)");
$ins->execute(['c1', 5, null, 12, 10.0, 1, '{"adset_id":"1201","ad_id":"9281"}', '2026-08-16 10:00:00']);
$ins->execute(['c2', 5, null, 12, 20.0, 1, '{"adset_id":"1201","ad_id":"9281"}', '2026-08-16 11:00:00']);
$ins->execute(['c3', 5, 4, 12, 30.0, 1, '{"adset_id":"1201","ad_id":"9282"}', '2026-08-16 12:00:00']);
$ins->execute(['c4', 5, 4, 12, 15.0, 1, '{"adset_id":"1201","ad_id":"9281"}', '2026-08-17 09:00:00']);
$ins->execute(['c5', 5, null, null, 25.0, 1, '{"adset_id":"1201","ad_id":"9281"}', '2026-08-17 10:00:00']);
$ins->execute(['x1', 5, null, null, 99.0, 1, '{"adset_id":"9999"}', '2026-08-16 10:00:00']);
$ins->execute(['x2', 5, null, null, 500.0, 1, '{"adset_id":"1201"}', '2025-01-01 10:00:00']); // out of range

// Campaign 5 cloaks and leaves the checkbox at its default (the key is absent,
// which resolves to "exclude"). Campaign 6 cloaks with the box explicitly
// unticked, so its white-page hits stay in the numbers.
$pdo->exec("INSERT INTO streams (id, campaign_id, schema_type, schema_custom_json) VALUES
    (1, 5, 'cloak', '{\"safe_mode\":\"landing\",\"safe_landing_id\":4}'),
    (2, 6, 'cloak', '{\"safe_mode\":\"landing\",\"exclude_safe_from_reports\":false}')");

// The regression this fixture exists for: a white-page hit carries the safe
// landing's id (index.php sets $landingIdToLog = $safeLandingId), so before the
// filter reached this class it was counted as a click, as spend and as an LP
// view in the extension overlay while the panel showed none of it. s1 must
// change none of the numbers asserted below.
$insSafe = $pdo->prepare("INSERT INTO clicks (id, campaign_id, landing_id, offer_id, cost, uniq_campaign, is_safe_page, parameters_json, created_at) VALUES (?,?,?,?,?,?,1,?,?)");
$insSafe->execute(['s1', 5, 4, 12, 40.0, 1, '{"adset_id":"1201","ad_id":"9281"}', '2026-08-17 11:00:00']);
// Campaign 6, checkbox unticked: this one counts.
$insSafe->execute(['s2', 6, 4, 12, 7.0, 1, '{"adset_id":"7777"}', '2026-08-17 11:30:00']);

$pdo->exec("INSERT INTO landings (id, name) VALUES (4, 'White COD')");
$pdo->exec("INSERT INTO offers (id, name) VALUES (12, 'Nutra Hair Oil')");

$conv = $pdo->prepare("INSERT INTO conversions (click_id, status, payout) VALUES (?,?,?)");
$conv->execute(['c1', 'sale', 150.0]);
$conv->execute(['c2', 'lead', 0.0]);
$conv->execute(['c4', 'sale', 100.0]);

// CAPI: c4's sale delivered to graph.facebook.com; c1's sale was never sent.
$pdo->exec("INSERT INTO s2s_postbacks_log (conversion_id, url, status) VALUES (3, 'https://graph.facebook.com/v25.0/.../events', 'delivered')");

$data = ExtensionStats::deepStats($pdo, 'payout', '2026-08-16', '2026-08-17', [
    ['type' => 'adset', 'id' => '1201'],
    ['type' => 'adset', 'id' => '9999'],   // clicks but no conversions
    ['type' => 'adset', 'id' => 'bogus'],  // filtered: non-numeric id
    ['type' => 'nope',  'id' => '123'],    // filtered: unknown type
]);

// --- totals across the two requested, click-bearing entities -------------
check('totals clicks', 6, $data['totals']['clicks']);
check('totals spend', 199.0, round($data['totals']['spend'], 2));          // 100 + 99
check('totals revenue (all conversions)', 250.0, round($data['totals']['revenue'], 2));
check('totals revenue_confirmed (sales)', 250.0, round($data['totals']['revenue_confirmed'], 2));
check('totals conversions', 3, $data['totals']['conversions']);
check('totals sales', 2, $data['totals']['sales']);
check('totals profit', 51.0, round($data['totals']['profit'], 2));

// --- entity 1201 ----------------------------------------------------------
$e = $data['entities']['1201'];
check('1201 clicks', 5, $e['clicks']);
check('1201 spend', 100.0, round($e['spend'], 2));
check('1201 revenue', 250.0, round($e['revenue'], 2));
check('1201 profit', 150.0, round($e['profit'], 2));
check('1201 roi', 150.0, round($e['roi'], 4));
check('1201 cpa (spend/sales)', 50.0, round($e['cpa'], 2));
check('1201 cpl (spend/conversions)', 33.3333, round($e['cpl'], 4));
check('1201 cpc', 20.0, round($e['cpc'], 2));
check('1201 epc', 50.0, round($e['epc'], 2));
check('1201 cr', 60.0, round($e['cr'], 2));
check('1201 lp_ctr (lp_clicks/prelander)', 100.0, round($e['lp_ctr'], 2)); // c3, c4 both landing→offer

// --- daily history: newest first, per-day math ----------------------------
check('daily days', ['2026-08-17', '2026-08-16'], array_column($e['daily_history'], 'date'));
$d17 = $e['daily_history'][0];
check('08-17 spend', 40.0, round($d17['spend'], 2));
check('08-17 revenue', 100.0, round($d17['revenue'], 2));
check('08-17 profit', 60.0, round($d17['profit'], 2));
check('08-17 sales', 1, (int) $d17['sales']);

// --- landing / offer breakdowns -------------------------------------------
check('landings count', 1, count($e['landings']));
check('landing name', 'White COD', $e['landings'][0]['name']);
check('landing clicks', 2, (int) $e['landings'][0]['clicks']);
check('landing lp_ctr', 100.0, round($e['landings'][0]['lp_ctr'], 2));
check('offers count', 1, count($e['offers']));
check('offer conversions', 3, (int) $e['offers'][0]['conversions']);

// --- pixel accuracy: 2 tracker sales, 1 delivered to Meta -----------------
check('accuracy tracker', 3, $e['pixel_accuracy']['tracker_leads']);
check('accuracy fb', 1, $e['pixel_accuracy']['fb_reported']);
check('accuracy pct', 33.3, $e['pixel_accuracy']['accuracy_pct']);

// --- entity 9999: clicks, zero revenue ------------------------------------
$e2 = $data['entities']['9999'];
check('9999 clicks', 1, $e2['clicks']);
check('9999 profit', -99.0, round($e2['profit'], 2));
check('9999 roi', -100.0, round($e2['roi'], 4));
check('9999 out-of-range click excluded', 1, (int) array_sum(array_map(static fn($d) => $d['clicks'], $e2['daily_history'])));

// --- safe-page exclusion --------------------------------------------------
// Every assertion above already runs against a fixture holding s1; these name
// the three the white-page hit would have moved, so a regression says what
// broke instead of just which number changed.
check('safe-page hit not counted as a click', 5, $e['clicks']);
check('safe-page hit not counted as spend', 100.0, round($e['spend'], 2));
check('safe-page hit not counted as an LP view', 2, (int) $e['landings'][0]['clicks']);

// Unticking the checkbox has to work in the same place: campaign 6 keeps its
// white-page hits, so 7777 exists at all and reports the one click it got.
$kept = ExtensionStats::deepStats($pdo, 'payout', '2026-08-16', '2026-08-17', [
    ['type' => 'adset', 'id' => '7777'],
]);
check('exclude_safe_from_reports=false keeps the hit', 1, (int) ($kept['entities']['7777']['clicks'] ?? 0));

echo "\nextension_stats_test: " . ($failed === 0 ? "ALL OK ($passed)" : "FAILED ($failed failed)") . "\n";
exit($failed === 0 ? 0 : 1);
