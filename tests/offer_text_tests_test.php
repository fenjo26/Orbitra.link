<?php
/**
 * Text A/B tests on LOCAL OFFERS (core/TextTests.php, migration 88).
 *
 * The tests lived on local landings only; an operator who keeps his pages as
 * local offers had no "Text A/B" tab at all. Pins:
 *  - engine: a test belongs to a landing or to an offer and cannot be moved
 *    between the two; the swap on an offer page touches the offer's tests
 *    only, is stable per click and records a hit only when the text is there;
 *  - the goal follows the page: on an offer page the winner is the text with
 *    the best CONVERSION rate (on a landing, the best LP CTR) — in the
 *    stats, in "probability best" and in the automatic re-weighing;
 *  - HTTP through the production router: candidates, saving, the page a click
 *    gets really carries this click's variant, deletion, and the access
 *    rules — the offer's rights and scope, not the landing's;
 *  - migration 88 on a pre-88 text_tests: the column and the index appear,
 *    the rows survive, the version is stamped.
 *
 * Usage: php tests/offer_text_tests_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../core/TextTests.php';
require_once __DIR__ . '/../core/AiClient.php';
require_once __DIR__ . '/lib/http.php';

$failures = 0;
function check(bool $condition, string $label, string $detail = ''): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . ($condition || $detail === '' ? '' : " ({$detail})") . "\n";
    if (!$condition) {
        $failures++;
    }
}

// ===========================================================================
// A. The engine, on a scratch database
// ===========================================================================
echo "--- A. engine ---\n";
$tmpDb = sys_get_temp_dir() . '/orbitra_offer_text_tests_' . getmypid() . '.sqlite';
@unlink($tmpDb);
register_shutdown_function(static function () use ($tmpDb): void {
    @unlink($tmpDb);
});
$pdo = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$cfg = file_get_contents(__DIR__ . '/../config.php');
$start = strpos($cfg, "if (!function_exists('orbitraEnsureAiTables'))");
$end = strpos($cfg, "if (!function_exists('orbitraEnsureCampaignRuleTables'))", $start);
eval(substr($cfg, $start, $end - $start));
orbitraEnsureAiTables($pdo);
$pdo->exec('CREATE TABLE clicks (id TEXT PRIMARY KEY, campaign_id INTEGER, offer_at TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE conversions (id INTEGER PRIMARY KEY AUTOINCREMENT, click_id TEXT, status TEXT, payout REAL DEFAULT 0)');

$OFFER = 7101;
[$t, $e] = TextTests::save($pdo, ['offer_id' => $OFFER, 'original_text' => 'Deposit and win', 'variants' => ['Top up and win', 'Win on every spin']]);
check($e === null && (int) $t['offer_id'] === $OFFER && (int) $t['landing_id'] === 0 && $t['goal'] === 'cr',
    'an offer test is stored on the offer (landing_id 0) and its goal is the conversion rate', json_encode($e));
$offerTestId = (int) ($t['id'] ?? 0);
check(count($t['variants'] ?? []) === 3 && array_sum(array_column($t['variants'] ?? [], 'weight')) === 100, 'control + 2 variants, weights summing to 100');

[$t2, $e2] = TextTests::save($pdo, ['landing_id' => 5, 'offer_id' => $OFFER, 'original_text' => 'Cashback every Monday', 'variants' => ['Cashback each Monday']]);
check($e2 === null && (int) $t2['landing_id'] === 5 && (int) $t2['offer_id'] === 0, 'with both ids the landing wins (one rule with the API): one page per test');
[, $e3] = TextTests::save($pdo, ['original_text' => 'No page', 'variants' => ['x']]);
check($e3 === 'landing_required', 'no page at all is refused');

// A test stays on its page — and on its kind of page.
[$lt] = TextTests::save($pdo, ['landing_id' => 5, 'original_text' => 'Play now', 'variants' => ['Start now']]);
[$x, $e4] = TextTests::save($pdo, ['landing_id' => 5, 'original_text' => 'Deposit and win', 'variants' => ['Moved']], $offerTestId);
check($x === null && $e4 === 'not_found', 'an offer test cannot be moved onto a landing');
[$x, $e5] = TextTests::save($pdo, ['offer_id' => 7102, 'original_text' => 'Deposit and win', 'variants' => ['Moved']], $offerTestId);
check($x === null && $e5 === 'not_found', 'an offer test cannot be moved onto another offer');
[$x, $e6] = TextTests::save($pdo, ['offer_id' => $OFFER, 'original_text' => 'Play now', 'variants' => ['Start now']], (int) $lt['id']);
check($x === null && $e6 === 'not_found', 'a landing test cannot be moved onto an offer');
[$u, $ue] = TextTests::save($pdo, ['offer_id' => $OFFER, 'original_text' => 'Deposit and win', 'variants' => ['Top up and win', 'Win on every spin', 'Bonus on deposit']], $offerTestId);
check($ue === null && count($u['variants']) === 4 && (int) $u['offer_id'] === $OFFER, 'a real update keeps the test on its offer');

check(array_column(TextTests::listForLanding($pdo, 5), 'id') === [(int) $t2['id'], (int) $lt['id']], "a landing's list holds the landing's tests only");
check(in_array($offerTestId, array_column(TextTests::listForOffer($pdo, $OFFER), 'id'), true) && TextTests::listForOffer($pdo, 7102) === [],
    "an offer's list holds the offer's tests only");
check((TextTests::listForOffer($pdo, $OFFER)[0]['goal'] ?? '') === 'cr', 'the listed offer tests carry their goal');

// ---- the swap on the page -----------------------------------------------------------------
$page = '<html><body><h1>Deposit and win</h1><p>Play now</p></body></html>';
$h1 = static function (string $html): string {
    return preg_match('#<h1>(.*?)</h1>#s', $html, $m) ? $m[1] : '(no h1)';
};
$texts = ['Deposit and win', 'Top up and win', 'Win on every spin', 'Bonus on deposit'];
$out = TextTests::applyToOffer($pdo, $page, $OFFER, 'clk-offer-1');
check(in_array($h1($out), $texts, true) && strpos($out, '<p>Play now</p>') !== false,
    'an offer page gets one of its test\'s texts — and the landing\'s test (same words "Play now" on the page) leaves it alone', $h1($out));
$onLanding = TextTests::apply($pdo, $page, 5, 'clk-offer-1');
check($h1($onLanding) === 'Deposit and win', "the offer's test leaves a landing's page alone");
$served = [];
for ($i = 0; $i < 40; $i++) {
    $click = 'clk-' . $i;
    $a = $h1(TextTests::applyToOffer($pdo, $page, $OFFER, $click));
    $b = $h1(TextTests::applyToOffer($pdo, $page, $OFFER, $click));
    if ($a !== $b) {
        $served['unstable'] = true;
    }
    $served[$a] = true;
}
check(!isset($served['unstable']), 'a reload of the same click shows the same text');
check(count($served) > 1 && count(array_diff(array_keys($served), $texts)) === 0, 'different clicks see different variants', implode(' | ', array_keys($served)));
$hits = (int) $pdo->query("SELECT COUNT(*) FROM text_test_hits WHERE test_id = {$offerTestId}")->fetchColumn();
check($hits === 41, 'one hit per served click (40 + the first one)', (string) $hits);
check((int) $pdo->query("SELECT COUNT(*) FROM text_test_hits WHERE click_id = 'clk-offer-1'")->fetchColumn() === 2,
    'the first click got a hit for the offer page and one for the landing page, in two tests');
$untouched = TextTests::applyToOffer($pdo, '<html><body><p>Nothing here</p></body></html>', $OFFER, 'clk-missing');
$hits2 = (int) $pdo->query("SELECT COUNT(*) FROM text_test_hits WHERE test_id = {$offerTestId}")->fetchColumn();
check($untouched === '<html><body><p>Nothing here</p></body></html>' && $hits2 === $hits, 'a page without the text is served as is — no hit, no data');

// ---- stats and the goal -------------------------------------------------------------------
// Variant "Win on every spin": 5 clicks, 2 of them converted, none went on
// (an offer page has nowhere further to go); variant "Top up and win": 5
// clicks, 0 conversions.
$variantsById = [];
foreach ($u['variants'] as $v) {
    $variantsById[(int) $v['id']] = $v['text'];
}
$byText = array_flip($variantsById);
$rows = $pdo->query("SELECT click_id, variant_id FROM text_test_hits WHERE test_id = {$offerTestId}")->fetchAll(PDO::FETCH_ASSOC);
$seen = [];
foreach ($rows as $r) {
    $text = $variantsById[(int) $r['variant_id']] ?? '';
    if (!isset($seen[$text])) {
        $seen[$text] = 0;
    }
    if ($seen[$text] >= 5 || !in_array($text, ['Win on every spin', 'Top up and win'], true)) {
        continue;
    }
    $seen[$text]++;
    $pdo->prepare('INSERT INTO clicks (id, campaign_id, offer_at, created_at) VALUES (?, 1, NULL, ?)')->execute([$r['click_id'], '2026-11-24 10:00:00']);
    if ($text === 'Win on every spin' && $seen[$text] <= 2) {
        $pdo->prepare("INSERT INTO conversions (click_id, status, payout) VALUES (?, 'sale', 10)")->execute([$r['click_id']]);
    }
}
$stats = TextTests::stats($pdo, $offerTestId);
$winStats = $stats[$byText['Win on every spin']] ?? [];
$topStats = $stats[$byText['Top up and win']] ?? [];
$winHits = (int) $pdo->query("SELECT COUNT(*) FROM text_test_hits WHERE test_id = {$offerTestId} AND variant_id = " . (int) $byText['Win on every spin'])->fetchColumn();
check($winHits > 0 && ($winStats['views'] ?? 0) === $winHits && ($winStats['conversions'] ?? 0) === 2 && ($winStats['lp_clicks'] ?? -1) === 0
    && abs(($winStats['cr'] ?? 0) - round(2 / $winHits * 100, 2)) < 0.001,
    'offer stats: every hit is a view, conversions counted, no "went on to the offer", CR = conversions per view', json_encode($winStats) . " hits {$winHits}");
check(($topStats['cr'] ?? -1) === 0.0, 'the other variant: CR 0%', json_encode($topStats));

$probe = [
    1 => ['views' => 100, 'lp_clicks' => 0, 'ctr' => 0.0, 'conversions' => 10, 'cr' => 10.0, 'sales' => 0, 'revenue' => 0.0],
    2 => ['views' => 100, 'lp_clicks' => 90, 'ctr' => 90.0, 'conversions' => 1, 'cr' => 1.0, 'sales' => 0, 'revenue' => 0.0],
];
$pCr = TextTests::probabilityBest($probe, 4000, 7, 'cr');
$pLp = TextTests::probabilityBest($probe, 4000, 7);
check(($pCr[1] ?? 0) > 0.9 && ($pLp[2] ?? 0) > 0.9, 'probability best: by conversions with the offer goal, by LP clicks with the landing goal',
    'cr: ' . json_encode($pCr) . ' lp: ' . json_encode($pLp));
check(TextTests::goal(['offer_id' => 3]) === 'cr' && TextTests::goal(['landing_id' => 3]) === 'lp_ctr' && TextTests::goal([]) === 'lp_ctr',
    'the goal follows the page');

// ---- automatic re-weighing on an offer follows the conversion rate ------------------------
[$auto] = TextTests::save($pdo, ['offer_id' => $OFFER, 'original_text' => 'Claim your prize', 'variants' => ['Grab the prize'], 'mode' => 'auto']);
$autoOriginal = (int) $auto['variants'][0]['id'];
$autoVariant = (int) $auto['variants'][1]['id'];
$autoPage = '<html><body><h1>Claim your prize</h1></body></html>';
for ($i = 0; $i < 240; $i++) {
    $click = 'auto-' . $i;
    TextTests::applyToOffer($pdo, $autoPage, $OFFER, $click);
}
$hitRows = $pdo->query("SELECT click_id, variant_id FROM text_test_hits WHERE test_id = " . (int) $auto['id'])->fetchAll(PDO::FETCH_ASSOC);
$perVariant = [$autoOriginal => 0, $autoVariant => 0];
foreach ($hitRows as $r) {
    $vid = (int) $r['variant_id'];
    if ($perVariant[$vid] >= 120) {
        continue;
    }
    $perVariant[$vid]++;
    // The ORIGINAL converts in 1 of 5; the VARIANT never converts — but every
    // one of its visitors "clicks on" (offer_at set), which is what a landing
    // test would reward.
    $converts = $vid === $autoOriginal && $perVariant[$vid] % 5 === 0;
    $pdo->prepare('INSERT INTO clicks (id, campaign_id, offer_at, created_at) VALUES (?, 1, ?, ?)')
        ->execute([$r['click_id'], $vid === $autoVariant ? '2026-11-24 10:01:00' : null, '2026-11-24 10:00:00']);
    if ($converts) {
        $pdo->prepare("INSERT INTO conversions (click_id, status, payout) VALUES (?, 'sale', 5)")->execute([$r['click_id']]);
    }
}
check(min($perVariant) >= (int) TextTests::MIN_VIEWS_TO_MOVE && min($perVariant) >= (int) TextTests::WIN_VIEWS,
    'enough views on both variants to re-weigh and to name a winner', json_encode($perVariant));
$rw = TextTests::reweigh($pdo, (int) $auto['id'], strtotime('2026-11-24 12:00:00 UTC'));
$autoAfter = TextTests::get($pdo, (int) $auto['id'], false);
$weightOf = static fn(int $vid): int => (int) (array_values(array_filter($autoAfter['variants'], static fn($v) => (int) $v['id'] === $vid))[0]['weight'] ?? 0);
check($weightOf($autoOriginal) > $weightOf($autoVariant), 'an offer test in auto mode gives more traffic to the text that converts better',
    'original ' . $weightOf($autoOriginal) . '% vs variant ' . $weightOf($autoVariant) . '%');
check($rw['winner'] === $autoOriginal && (int) $autoAfter['winner_variant_id'] === $autoOriginal,
    'and names that text the winner — although the other one "clicked on" every time');
check(TextTests::offerSource($pdo, 999999901) === '', "an offer without a page here has no source");

// ===========================================================================
// B. Over HTTP, through the production router
// ===========================================================================
echo "--- B. HTTP ---\n";
$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();
$harness->start();
try {
    $hpdo = $harness->getPdo();
    $adminKey = 'test_key_' . bin2hex(random_bytes(8));
    $hpdo->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)')
        ->execute(['offer_tt_admin', password_hash('x', PASSWORD_DEFAULT), 'admin']);
    $hpdo->prepare('INSERT INTO user_api_keys (user_id, api_key, key_name, permissions) VALUES (last_insert_rowid(), ?, ?, ?)')
        ->execute([$adminKey, 'offer-tt-admin', 'full']);
    $mkUser = static function (string $name, array $permissions) use ($hpdo): string {
        $hpdo->prepare('INSERT INTO users (username, password, role, permissions_json) VALUES (?, ?, ?, ?)')
            ->execute([$name, password_hash('x', PASSWORD_DEFAULT), 'user', json_encode($permissions)]);
        $key = 'test_key_' . bin2hex(random_bytes(8));
        $hpdo->prepare('INSERT INTO user_api_keys (user_id, api_key, key_name, permissions) VALUES (last_insert_rowid(), ?, ?, ?)')
            ->execute([$key, $name, 'full']);
        return $key;
    };
    $call = static function (string $key, string $path, ?array $body = null) use ($harness): array {
        $headers = ['Authorization: Bearer ' . $key, 'Content-Type: application/json'];
        $res = $body === null ? $harness->getWithHeaders($path, $headers) : $harness->postWithHeaders($path, json_encode($body), $headers);
        $json = json_decode($res['body'], true);
        return [is_array($json) ? $json : ['status' => 'error', 'raw' => substr((string) $res['body'], 0, 160)], (int) $res['code']];
    };

    $offerId = random_int(10000, 99999);
    $otherOfferId = $offerId + 1;
    $campaignId = random_int(10000, 99999);
    $alias = 'ott' . bin2hex(random_bytes(4));
    $hpdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, 'Offer with a page', '', 1, 'active', 0)")->execute([$offerId]);
    $hpdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, 'Other local offer', '', 1, 'active', 0)")->execute([$otherOfferId]);
    $extLandingId = random_int(10000, 99999);
    $hpdo->prepare("INSERT INTO landings (id, name, url, type, state) VALUES (?, 'External landing', 'https://example.com/l', 'external', 'active')")->execute([$extLandingId]);

    $work = $harness->getWorkingDir();
    $offerDir = $work . '/offers/' . $offerId;
    @mkdir($offerDir, 0755, true);
    $original = 'Deposit and win today';
    $variantA = 'Top up and win today';
    $variantB = 'Win on every spin today';
    file_put_contents($offerDir . '/index.html',
        '<!DOCTYPE html><html><head><title>Offer</title></head><body><h1>' . $original . '</h1><p>Bonus 100</p></body></html>');

    $hpdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived) VALUES (?, 'Offer text test campaign', ?, ?, 'active', 0)")
        ->execute([$campaignId, $alias, 'test_' . bin2hex(random_bytes(4))]);
    $hpdo->prepare("INSERT INTO streams (campaign_id, name, type, schema_type, schema_custom_json, is_active, collect_clicks, position)
                    VALUES (?, 'Direct', 'regular', 'redirect', ?, 1, 1, 1)")
        ->execute([$campaignId, json_encode(['offers' => [['id' => $offerId, 'weight' => 100]]])]);
    $hpdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_enabled', '1')")->execute();
    $hpdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')")->execute();
    $harness->setPostbackKey('testkey42');

    [$cand] = $call($adminKey, "/api.php?action=text_test_candidates&offer_id={$offerId}");
    $candTexts = array_column($cand['data'] ?? [], 'text');
    check(($cand['status'] ?? '') === 'success' && in_array($original, $candTexts, true) && in_array('Bonus 100', $candTexts, true),
        'candidates: the texts of the offer page are found on it', json_encode($candTexts));
    [$list] = $call($adminKey, "/api.php?action=text_tests&offer_id={$offerId}");
    check(($list['status'] ?? '') === 'success' && ($list['data']['local'] ?? false) === true && ($list['data']['tests'] ?? null) === [],
        'the offer counts as a local page with tests');
    [$ext] = $call($adminKey, "/api.php?action=text_tests&landing_id={$extLandingId}");
    check(($ext['status'] ?? '') === 'success' && ($ext['data']['local'] ?? true) === false, 'landings still answer: an external landing is not local');
    [, $codeNoPage] = $call($adminKey, '/api.php?action=text_tests');
    [$noPage] = $call($adminKey, '/api.php?action=text_tests');
    check($codeNoPage === 200 && $noPage['code'] === 'landing_required', 'neither id: the old error code');
    [$nf] = $call($adminKey, '/api.php?action=text_tests&offer_id=424242');
    check(($nf['code'] ?? '') === 'offer_not_found', 'an unknown offer');

    [$saved] = $call($adminKey, '/api.php?action=text_test_save', [
        'offer_id' => $offerId, 'name' => 'Headline', 'original_text' => $original, 'mode' => 'equal',
        'variants' => [$variantA, $variantB],
    ]);
    check(($saved['status'] ?? '') === 'success' && (int) ($saved['data']['offer_id'] ?? 0) === $offerId
        && (int) ($saved['data']['landing_id'] ?? -1) === 0 && ($saved['data']['goal'] ?? '') === 'cr' && count($saved['data']['variants'] ?? []) === 3,
        'saved through the API on the offer', json_encode($saved));
    $savedId = (int) ($saved['data']['id'] ?? 0);
    [$bound] = $call($adminKey, '/api.php?action=text_test_save', [
        'landing_id' => $extLandingId, 'offer_id' => $offerId, 'original_text' => $original, 'variants' => [$variantA],
    ]);
    check(($bound['status'] ?? '') === 'success' && (int) ($bound['data']['landing_id'] ?? 0) === $extLandingId && (int) ($bound['data']['offer_id'] ?? -1) === 0,
        'a save naming both ids lands on the landing — the one rule of the engine, the handler and the access gate');
    [$moved] = $call($adminKey, '/api.php?action=text_test_save', [
        'id' => $savedId, 'landing_id' => $extLandingId, 'offer_id' => $offerId, 'original_text' => $original, 'variants' => [$variantA, $variantB],
    ]);
    check(($moved['code'] ?? '') === 'not_found', "an offer test cannot be re-saved onto a landing through the API");

    // ---- the page a click gets --------------------------------------------------------
    $mobileUa = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    $servedTexts = [];
    $clickIds = [];
    for ($i = 0; $i < 14; $i++) {
        $res = $harness->getWithHeaders("/{$alias}", ['User-Agent: ' . $mobileUa, 'X-Forwarded-For: 185.13.10.' . ($i + 1)]);
        $clickIds[] = (int) $res['code'];
        if (preg_match('#<h1>(.*?)</h1>#s', $res['body'], $m)) {
            $servedTexts[] = $m[1];
        }
    }
    check(count(array_keys($clickIds, 200)) === 14, 'the campaign URL serves the local offer page', json_encode($clickIds));
    $expected = [];
    $st = $hpdo->prepare('SELECT v.text FROM text_test_hits h JOIN text_test_variants v ON v.id = h.variant_id
                          JOIN clicks c ON c.id = h.click_id WHERE h.test_id = ? AND c.campaign_id = ?');
    $st->execute([$savedId, $campaignId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $text) {
        $expected[] = $text;
    }
    sort($servedTexts);
    sort($expected);
    check(count($expected) === 14 && $servedTexts === $expected,
        'every served page carried the variant its click was assigned', json_encode([$servedTexts, $expected]));
    check(count(array_unique($servedTexts)) > 1, 'the clicks were split between the texts', json_encode(array_count_values($servedTexts)));

    // ---- who may: the offer's rights and scope, not the landing's -------------------
    $noneOffers = $mkUser('offer_tt_none_offers', ['offers' => ['access' => 'none'], 'landings' => ['access' => 'full']]);
    [, $c1] = $call($noneOffers, "/api.php?action=text_tests&offer_id={$offerId}");
    check($c1 === 403, 'no access to offers: refused (403)', (string) $c1);
    $noLandings = $mkUser('offer_tt_no_landings', ['landings' => ['access' => 'none'], 'offers' => ['access' => 'full']]);
    [$r2, $c2] = $call($noLandings, "/api.php?action=text_tests&offer_id={$offerId}");
    [, $c3] = $call($noLandings, "/api.php?action=text_tests&landing_id={$extLandingId}");
    check($c2 === 200 && ($r2['status'] ?? '') === 'success' && $c3 === 403,
        'access to offers but not to landings: the offer answers, the landing is refused');
    $scopedKey = $mkUser('offer_tt_scoped', ['offers' => ['access' => 'selected', 'items' => [$otherOfferId], 'groups' => []]]);
    [, $c4] = $call($scopedKey, "/api.php?action=text_tests&offer_id={$offerId}");
    [$r5, $c5] = $call($scopedKey, "/api.php?action=text_tests&offer_id={$otherOfferId}");
    [, $c6] = $call($scopedKey, '/api.php?action=text_test_save', ['offer_id' => $offerId, 'original_text' => 'x', 'variants' => ['y']]);
    check($c4 === 403 && $c5 === 200 && ($r5['status'] ?? '') === 'success' && $c6 === 403,
        'a user limited to selected offers: another offer is refused for reading and for writing, their own answers');

    // ---- deleting -----------------------------------------------------------------------
    [$del1] = $call($adminKey, '/api.php?action=text_test_delete', ['id' => $savedId, 'offer_id' => $otherOfferId]);
    check(($del1['code'] ?? '') === 'not_found', "a test cannot be deleted through another offer");
    [$del2] = $call($adminKey, '/api.php?action=text_test_delete', ['id' => $savedId, 'landing_id' => $extLandingId]);
    check(($del2['code'] ?? '') === 'not_found', 'nor through a landing');
    [$del3] = $call($adminKey, '/api.php?action=text_test_delete', ['id' => $savedId, 'offer_id' => $offerId]);
    [$after] = $call($adminKey, "/api.php?action=text_tests&offer_id={$offerId}");
    check(($del3['status'] ?? '') === 'success' && ($after['data']['tests'] ?? null) === [], 'deleted through its own offer');
    check((int) $hpdo->query("SELECT COUNT(*) FROM text_test_hits WHERE test_id = {$savedId}")->fetchColumn() === 0, 'the statistics went with it');
} finally {
    $harness->stop();
}

// ===========================================================================
// C. Migration 88 on a pre-88 database
// ===========================================================================
echo "--- C. migration 88 ---\n";
$repoRoot = dirname(__DIR__);
$work = sys_get_temp_dir() . '/orbitra_mig88_' . getmypid();
@mkdir($work . '/cli', 0777, true);
@mkdir($work . '/var/cache', 0777, true);
// The scratch tree holds a copy of core/ (nested): remove it file by file, bottom-up.
$removeTree = static function (string $dir) use (&$removeTree): void {
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_dir($path) && !is_link($path)) {
            $removeTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
};
register_shutdown_function(static function () use ($work, $removeTree): void {
    if (is_dir($work)) {
        $removeTree($work);
    }
});
$configSrc = preg_replace(
    '/\$db_file\s*=\s*__DIR__\s*\.\s*[\'"]\/orbitra_db\.sqlite[\'"];/',
    '$db_file = __DIR__ . "/test.sqlite";',
    (string) file_get_contents($repoRoot . '/config.php')
);
file_put_contents($work . '/config.php', $configSrc);
exec('cp -R ' . escapeshellarg($repoRoot . '/core') . ' ' . escapeshellarg($work . '/core') . ' 2>/dev/null');
$boot = static function () use ($work): void {
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export($work . '/config.php', true) . ';') . ' 2>/dev/null');
};
$db = $work . '/test.sqlite';
try {
    $boot();
    $mpdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $latest = (int) $mpdo->query('PRAGMA user_version')->fetchColumn();
    $cols = array_column($mpdo->query('PRAGMA table_info(text_tests)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    check($latest === 88 && in_array('offer_id', $cols, true), "a fresh install builds schema 88 with text_tests.offer_id ({$latest})");

    // Shape it as a 2.1.4 database: no offer_id, one landing test with its
    // variants and hits, version 87.
    $mpdo->exec('INSERT INTO text_tests (landing_id, name, original_text) VALUES (5, \'Old test\', \'Old text\')');
    $oldTestId = (int) $mpdo->lastInsertId();
    $mpdo->exec('INSERT INTO text_test_variants (test_id, text, is_original) VALUES (' . $oldTestId . ', \'Old text\', 1)');
    $mpdo->exec('INSERT INTO text_test_hits (click_id, test_id, variant_id) VALUES (\'old-click\', ' . $oldTestId . ', 1)');
    $mpdo->exec('DROP INDEX IF EXISTS idx_text_tests_offer');
    $mpdo->exec('CREATE TABLE text_tests_pre88 (id INTEGER PRIMARY KEY AUTOINCREMENT, landing_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT \'\',
                 original_text TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, mode TEXT NOT NULL DEFAULT \'auto\', winner_variant_id INTEGER,
                 last_run_at TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $mpdo->exec('INSERT INTO text_tests_pre88 SELECT id, landing_id, name, original_text, is_active, mode, winner_variant_id, last_run_at, created_at, updated_at FROM text_tests');
    $mpdo->exec('DROP TABLE text_tests');
    $mpdo->exec('ALTER TABLE text_tests_pre88 RENAME TO text_tests');
    $mpdo->exec('CREATE INDEX IF NOT EXISTS idx_text_tests_landing ON text_tests(landing_id, is_active)');
    $mpdo->exec('PRAGMA user_version = 87');
    $cols = array_column($mpdo->query('PRAGMA table_info(text_tests)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    check(!in_array('offer_id', $cols, true) && (int) $mpdo->query('PRAGMA user_version')->fetchColumn() === 87, 'shaped as a 2.1.4 database (no offer_id, version 87)');
    $mpdo = null;

    $boot();
    $mpdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $cols = array_column($mpdo->query('PRAGMA table_info(text_tests)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $idx = $mpdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'text_tests'")->fetchAll(PDO::FETCH_COLUMN);
    check(in_array('offer_id', $cols, true) && in_array('idx_text_tests_offer', $idx, true) && in_array('idx_text_tests_landing', $idx, true)
        && (int) $mpdo->query('PRAGMA user_version')->fetchColumn() === 88,
        'migration 88 adds the column and the index and stamps the version');
    $old = TextTests::get($mpdo, $oldTestId);
    check($old !== null && (int) $old['offer_id'] === 0 && $old['goal'] === 'lp_ctr' && count($old['variants']) === 1
        && (int) $mpdo->query("SELECT COUNT(*) FROM text_test_hits WHERE test_id = {$oldTestId}")->fetchColumn() === 1,
        'the landing test from before, with its variants and hits, is still a landing test');
    [$migOffer] = TextTests::save($mpdo, ['offer_id' => 31, 'original_text' => 'New page text', 'variants' => ['Other text']]);
    check($migOffer !== null && (int) $migOffer['offer_id'] === 31, 'and an offer test can be saved on the migrated base');
    $mpdo = null;
    $boot(); // idempotence: the migration runs into a current schema without harm
    $mpdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    check((int) $mpdo->query('PRAGMA user_version')->fetchColumn() === 88
        && (int) $mpdo->query('SELECT COUNT(*) FROM text_tests')->fetchColumn() === 2, 'a second boot changes nothing');
} catch (Throwable $e) {
    check(false, 'migration 88: ' . $e->getMessage());
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILURES\n";
exit($failures === 0 ? 0 : 1);
