<?php
// tests/report_metrics_test.php
//
// End-to-end math check for the 65-metric set: seeds a reference dataset
// (clicks with costs, landings/offers paths, conversions in every status,
// aggregator revenue) into a throwaway SQLite file, runs the shared
// conversion-aggregate SQL and orbitraComputeDerivedMetrics(), and asserts
// every derived number against hand-computed expectations.
//
// Run: php tests/report_metrics_test.php

require_once __DIR__ . '/../core/ReportMetrics.php';

$tmpDb = sys_get_temp_dir() . '/orbitra_metrics_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$pdo = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec('CREATE TABLE conversions (id INTEGER PRIMARY KEY, click_id TEXT, tid TEXT, status TEXT, payout REAL)');
// Mirror of the live seed used to hunt the registration/deposit bugs:
// 2 sales + 1 confirmed (sale group), 1 deposit, 1 registration, 1 lead,
// 1 rejected, 1 trash — payouts 20/15/10/30/1/2/5/0.5 = 83.5 total,
// 8 conversion EVENTS on 6 clicks.
$rows = [
    ['m1', 'a1', 'sale', 20], ['m1', 'a2', 'sale', 15], ['m1', 'a3', 'confirmed', 10],
    ['m2', 'a4', 'deposit', 30], ['m2', 'a5', 'registration', 1],
    ['m3', 'a6', 'lead', 2], ['m4', 'a7', 'rejected', 5], ['m5', 'a8', 'trash', 0.5],
];
$st = $pdo->prepare('INSERT INTO conversions (click_id, tid, status, payout) VALUES (?,?,?,?)');
foreach ($rows as $r) { $st->execute($r); }

$aggSql = orbitraConversionAggregateSql('payout');
$agg = $pdo->query("SELECT * FROM $aggSql WHERE click_id = 'm1'")->fetch(PDO::FETCH_ASSOC);
$assert = function (string $label, $got, $expected, float $tol = 0.01) {
    $ok = is_numeric($got) && is_numeric($expected)
        ? abs((float) $got - (float) $expected) <= $tol
        : $got === $expected;
    if (!$ok) {
        echo "FAIL $label: got " . var_export($got, true) . ", expected " . var_export($expected, true) . "\n";
        exit(1);
    }
};

// Aggregate per click m1: 3 conversion events; the sale group swallows
// 'confirmed' too (sale/confirmed/approved/purchase), so 3 events / 45.00.
$assert('cnt_any m1', $agg['cnt_any'], 3, 0);
$assert('cnt_sale m1', $agg['cnt_sale'], 3, 0);
$assert('rev_sale m1', $agg['rev_sale'], 45);

// Whole-set derived metrics under the offer-funnel semantics: visitors 6 =
// ALL inbound hits (4 landing views + 2 direct-to-offer visits); clicks 4 =
// offer hits (the 2 completed CTA transitions + the 2 direct visits). One of
// the landing views carries a pre-bound offer that never transitioned (the
// legacy offer_selection='before' shape) — a visitor, not a click — which is
// why the legacy raw offer_clicks counter (5) overshoots the honest clicks.
// CPV/EPV divide by visitors; CR/CPC-family by the offer funnel.
$m = orbitraComputeDerivedMetrics([
    'clicks' => 4, 'unique_clicks' => 5,
    'unique_clicks_stream' => 5, 'unique_clicks_global' => 4, 'visitors' => 6,
    'bots' => 1, 'proxies' => 2, 'empty_referrers' => 3, 'avg_lp_seconds' => 95,
    'prelander_clicks' => 4, 'offer_clicks' => 5, 'lp_clicks' => 2,
    'real_lp_clicks' => 2, 'real_offer_clicks' => 4,
    'conversions' => 8, 'purchases' => 3, 'holds' => 1, 'rejected' => 1, 'trash' => 1,
    'registrations' => 1, 'deposits' => 1,
    'cost' => 21, 'revenue' => 83.5, 'revenue_confirmed' => 45, 'revenue_hold' => 2,
    'revenue_rejected' => 5, 'revenue_trash' => 0.5, 'revenue_deposit' => 30,
    'revenue_registration' => 1, 'real_revenue' => 40,
]);

$expected = [
    'clicks' => 4, 'unique_clicks' => 5, 'uc_rate' => 125.0,
    'unique_clicks_stream' => 5, 'unique_clicks_global' => 4, 'visitors' => 6,
    'bots' => 1, 'bot_rate' => 25.0, 'proxies' => 2, 'empty_referrers' => 3,
    'time_since_lp_click' => '1m 35s',
    'conversions' => 8, 'sales' => 3, 'leads' => 1, 'registrations' => 1, 'deposits' => 1,
    'approve_rate' => 50.0,
    'revenue' => 83.5, 'revenue_confirmed' => 45, 'revenue_deposit' => 30, 'revenue_registration' => 1,
    'cost' => 21, 'profit' => 62.5, 'profit_confirmed' => 24,
    'roi' => 297.62, 'roi_confirmed' => 114.29, 'profitability' => 74.85,
    'cr' => 200.0, 'cr_sales' => 75, 'cr_deposits' => 25, 'cr_regs_to_deps' => 100, 'ucr' => 20,
    'epc' => 41.75, 'uepc' => 16.7, 'epc_confirmed' => 22.5, 'uepc_confirmed' => 9,
    'epv' => 13.9167, 'epv_confirmed' => 7.5,
    'cps' => 7, 'cpl' => 21, 'cpr' => 21, 'cpd' => 21, 'cpa' => 2.63,
    'cpc' => 10.5, 'ucpc' => 4.2, 'cpv' => 3.5, 'ecpc' => 5.25,
    'ecpm_all' => 15625.0, 'ecpm_confirmed' => 6000.0,
    'earnings_per_conv' => 10.44, 'ec_confirmed' => 15,
    'real_profit' => 19, 'real_roi' => 90.48,
    'lp_views' => 4, 'lp_clicks' => 2, 'offer_clicks' => 5, 'lp_ctr' => 50,
];
foreach ($expected as $k => $v) {
    $tol = is_numeric($v) ? max(0.02, abs($v) * 0.005) : 0;
    $assert($k, $m[$k] ?? null, $v, $tol);
}

// Canonical media-buying funnel: 1,000 visitors (all of them LP views),
// 200 CTA clicks (= the offer funnel), $200 cost, $500 revenue. These five
// values pin every requested denominator explicitly. Pure Lander flow: the
// click count IS the CTA count, so the offer-funnel and LP-funnel agree.
$funnel = orbitraComputeDerivedMetrics([
    'clicks' => 200, 'visitors' => 1000, 'prelander_clicks' => 1000, 'lp_clicks' => 200,
    'cost' => 200, 'revenue' => 500,
]);
foreach (['lp_ctr' => 20, 'cpv' => 0.2, 'cpc' => 1, 'epv' => 0.5, 'epc' => 2.5] as $metric => $value) {
    $assert("canonical funnel $metric", $funnel[$metric], $value);
}

// Direct-to-offer stream (no landing in the chain): every visit IS an offer
// click, so visitors == clicks, CPV/EPV carry the economics alone, EPC/CPC
// mirror them, and LP CTR is a dash — there is no CTA to measure.
$direct = orbitraComputeDerivedMetrics([
    'clicks' => 400, 'visitors' => 400, 'prelander_clicks' => 0, 'lp_clicks' => 0, 'offer_clicks' => 400,
    'cost' => 80, 'revenue' => 200,
]);
foreach (['cpv' => 0.2, 'cpc' => 0.2, 'epv' => 0.5, 'epc' => 0.5] as $metric => $value) {
    $assert("direct flow $metric (visit-denominated)", $direct[$metric], $value);
}
$assert('direct flow lp_ctr is a dash', $direct['lp_ctr'], null);

// ROI at zero spend must be null (rendered as a dash), not a made-up 100%.
$zero = orbitraComputeDerivedMetrics(['clicks' => 1, 'cost' => 0, 'revenue' => 5]);
$assert('roi at zero cost is null', $zero['roi'], null);
$assert('EPC falls back to incoming clicks without LP clicks', $zero['epc'], 5);
$assert('EPV falls back to clicks when no LP-view field is supplied', $zero['epv'], 5);

$funnelZero = orbitraComputeDerivedMetrics([
    'clicks' => 0, 'prelander_clicks' => 0, 'lp_clicks' => 0,
    'cost' => 10, 'revenue' => 20,
]);
foreach (['cpv', 'cpc', 'epv', 'epc'] as $metric) {
    $assert("$metric is zero with a zero denominator", $funnelZero[$metric], 0);
}
$assert('lp_ctr is a dash with a zero denominator', $funnelZero['lp_ctr'], null);

// Honest transition counters: the SQL layer emits lp_clicks as the honest
// count already, so the real_* columns (their v1.4.0 names) alias the same
// numbers — real_lp_ctr divides by the same LP views the plain CTR uses.
$honest = orbitraComputeDerivedMetrics([
    'clicks' => 4, 'visitors' => 6, 'prelander_clicks' => 3, 'lp_clicks' => 2, 'offer_clicks' => 4,
    'real_lp_clicks' => 2, 'real_offer_clicks' => 4,
]);
$assert('real_lp_clicks pass-through', $honest['real_lp_clicks'], 2, 0);
$assert('real_offer_clicks pass-through', $honest['real_offer_clicks'], 4, 0);
$assert('real_lp_ctr (2 real / 3 views)', $honest['real_lp_ctr'], 66.67);
$assert('plain lp_ctr equals the real one (lp_clicks is honest)', $honest['lp_ctr'], 66.67);
// Legacy callers (no real_* keys in the raw row): the aliases fall back to
// the honest counters — lp_clicks to real_lp_clicks, clicks to
// real_offer_clicks — so every surface agrees by construction.
$legacyRow = orbitraComputeDerivedMetrics([
    'clicks' => 4, 'prelander_clicks' => 3, 'lp_clicks' => 2,
]);
$assert('legacy row real_lp_clicks aliases lp_clicks', $legacyRow['real_lp_clicks'], 2, 0);
$assert('legacy row real_offer_clicks aliases clicks', $legacyRow['real_offer_clicks'], 4, 0);
$assert('legacy row real_lp_ctr reads 66.67%', $legacyRow['real_lp_ctr'], 66.67);
// No landing in the chain at all: both CTRs are dashes.
$noLp = orbitraComputeDerivedMetrics(['clicks' => 6, 'visitors' => 6, 'prelander_clicks' => 0, 'lp_clicks' => 0]);
$assert('no-landing real_lp_ctr is a dash', $noLp['real_lp_ctr'], null);

// ---------------------------------------------------------------------------
// Production SQL check for the Landings/Offers table pages: seed the mini
// universe below, run the exact queries core/ReportMetrics.php ships, apply
// the same derivation loop the api.php endpoints run, and assert every column
// the tables render. Hand-computed expectations, so a formula drift anywhere
// in visits/LP CTR/approve/EPV/ROI fails here before it ships.
//
// Clicks (one row = one visit; offer_at set means the visitor actually left
// through the offer link — the router UPDATEs the row, it never inserts a
// second one):
//   m1 landing 1 → offer 7, transitioned, ip A, cost 10  (3 conversions, sale group)
//   m2 landing 1, no offer,    ip A, cost 5              (deposit + registration)
//   m3 landing 1, no offer,    ip B, cost 1              (lead)
//   m4 landing 2 → offer 8, transitioned, ip C, cost 4   (rejected)
//   m5 no landing → offer 9,  ip D, cost 1               (trash)
//   m6 nothing,               ip E, cost 0               (no conversions)
//   m7 landing 1, pre-bound offer 7, NEVER transitioned, ip A, cost 0
//      (the legacy offer_selection='before' shape: a visitor to landing 1
//      and to offer 7's row count, but NOT an offer click anywhere)
$pdo->exec('CREATE TABLE clicks (id TEXT PRIMARY KEY, campaign_id INTEGER, offer_id INTEGER,
    landing_id INTEGER, ip TEXT, cost REAL DEFAULT 0, is_conversion INTEGER DEFAULT 0,
    revenue REAL DEFAULT 0, is_bot INTEGER DEFAULT 0, is_proxy INTEGER DEFAULT 0,
    referer TEXT, created_at TEXT DEFAULT "2026-01-01 10:00:00",
    uniq_campaign INTEGER DEFAULT 1, uniq_stream INTEGER DEFAULT 1, uniq_global INTEGER DEFAULT 1,
    landing_at TEXT, offer_at TEXT, lp_seconds INTEGER, lp_scroll INTEGER,
    pwa_intent_at TEXT, pwa_install_at TEXT, pwa_open_at TEXT, pwa_open_count INTEGER DEFAULT 0,
    push_prompted_at TEXT, push_subscribed_at TEXT, push_declined_at TEXT)');
// "Real" revenue (aggregator payouts) lives in revenue_records, joined per
// click exactly like the campaigns report does.
$pdo->exec('CREATE TABLE revenue_records (id INTEGER PRIMARY KEY, click_id TEXT, amount REAL)');
$pdo->exec('CREATE TABLE landings (id INTEGER PRIMARY KEY, name TEXT, type TEXT, url TEXT,
    state TEXT, group_id INTEGER, is_archived INTEGER DEFAULT 0, config_json TEXT)');
$pdo->exec('CREATE TABLE landing_groups (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE offers (id INTEGER PRIMARY KEY, name TEXT, group_id INTEGER,
    affiliate_network_id INTEGER, url TEXT, redirect_type TEXT, is_local INTEGER, geo TEXT,
    payout_type TEXT, payout_value REAL, payout_auto INTEGER, allow_rebills INTEGER,
    capping_limit INTEGER, capping_timezone TEXT, alt_offer_id INTEGER, notes TEXT,
    state TEXT, created_at TEXT, is_archived INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE offer_groups (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE affiliate_networks (id INTEGER PRIMARY KEY, name TEXT)');

$st = $pdo->prepare('INSERT INTO clicks (id, landing_id, offer_id, ip, cost) VALUES (?,?,?,?,?)');
foreach ([['m1',1,7,'A',10], ['m2',1,null,'A',5], ['m3',1,null,'B',1],
          ['m4',2,8,'C',4], ['m5',null,9,'D',1], ['m6',null,null,'E',0],
          ['m7',1,7,'A',0]] as $r) { $st->execute($r); }
$pdo->exec("UPDATE clicks SET is_bot = 1 WHERE id = 'm4'");
// Uniqueness / referer / funnel-timing extras for the parity counters:
// m2 is the same IP as m1 (not unique anywhere), m3 arrives with an empty
// referer, m1 waits 95s on the landing before clicking to the offer.
$pdo->exec("UPDATE clicks SET uniq_campaign = 0, uniq_stream = 0, uniq_global = 0 WHERE id = 'm2'");
$pdo->exec("UPDATE clicks SET referer = 'https://fb.com/' WHERE id IN ('m1','m2','m4','m7')");
$pdo->exec("UPDATE clicks SET referer = '' WHERE id = 'm3'");
$pdo->exec("UPDATE clicks SET landing_at = '2026-01-01 10:00:00', offer_at = '2026-01-01 10:01:35' WHERE id = 'm1'");
$pdo->exec("UPDATE clicks SET landing_at = '2026-01-01 10:00:00', offer_at = '2026-01-01 10:02:00' WHERE id = 'm4'");
// m7 saw the landing (serve-time landing_at) with the offer pre-bound but
// never transitioned — no offer_at, so it stays out of every honest counter.
$pdo->exec("UPDATE clicks SET landing_at = '2026-01-01 10:03:00' WHERE id = 'm7'");
// Landing dwell, written by the /pixel.gif?action=lp beacon: m2 and m3 never
// reached an offer, so the landing_at/offer_at pair above says nothing about
// them — the whole reason this measurement exists. m2 read the page (40s, 90%),
// m3 bounced off the hero (2s, 5%). m6 never reported at all and must stay out
// of both the average and the denominator.
$pdo->exec("UPDATE clicks SET lp_seconds = 40, lp_scroll = 90 WHERE id = 'm2'");
$pdo->exec("UPDATE clicks SET lp_seconds = 2,  lp_scroll = 5  WHERE id = 'm3'");
$pdo->exec("INSERT INTO revenue_records (click_id, amount) VALUES ('m1', 12)");
$st = $pdo->prepare('INSERT INTO landings (id, name, group_id) VALUES (?,?,?)');
foreach ([[1,'LP one',1], [2,'LP two',null], [3,'LP empty',null]] as $r) { $st->execute($r); }
$pdo->exec("INSERT INTO landing_groups (id, name) VALUES (1, 'grp')");
$pdo->exec('INSERT INTO offers (id, name) VALUES (7,"of7"), (8,"of8"), (9,"of9")');

// Dashboard cards aggregate the same dataset in one row, then run the shared
// derivation helper. This catches missing cards and guards against multiplying
// click cost when one click has several conversion events.
$dashboardRaw = $pdo->query(orbitraDashboardMetricsSql('payout', null))->fetch(PDO::FETCH_ASSOC);
$dashboard = orbitraComputeDerivedMetrics($dashboardRaw ?: []);
$assert('Dashboard clicks (offer hits only)', $dashboard['clicks'], 3, 0);
// m7 is the pre-bound landing view: a visitor, not a click — the exact
// separation the offer-funnel semantics exist for.
$assert('Dashboard visitors (all hits)', $dashboard['visitors'], 7, 0);
// The dwell metrics are computed over MEASURED visits only: two of the six
// clicks reported, so a 21s average and a 50% bounce share — not 2/6.
$assert('Dashboard LP measured visits', $dashboard['lp_measured'], 2, 0);
$assert('Dashboard LP scroll depth', $dashboard['lp_scroll_depth'], 47.5, 0.01);
$assert('Dashboard LP bounce rate', $dashboard['lp_bounce_rate'], 50.0, 0.01);
$assert('Dashboard time on LP', $dashboard['time_on_lp'], '21s');
$assert('Dashboard unique clicks', $dashboard['unique_clicks'], 5, 0);
$assert('Dashboard conversions', $dashboard['conversions'], 8, 0);
$assert('Dashboard leads', $dashboard['leads'], 1, 0);
$assert('Dashboard sales', $dashboard['sales'], 3, 0);
$assert('Dashboard bots', $dashboard['bots'], 1, 0);
$assert('Dashboard cost is not multiplied', $dashboard['cost'], 21);
$assert('Dashboard confirmed revenue', $dashboard['revenue_confirmed'], 45);
$assert('Dashboard confirmed profit', $dashboard['profit_confirmed'], 24);
$assert('Dashboard CPL', $dashboard['cpl'], 21);
$assert('Dashboard CPS', $dashboard['cps'], 7);
$assert('Dashboard LP CTR', $dashboard['lp_ctr'], 40);
$assert('Dashboard bot rate (bots per offer click)', $dashboard['bot_rate'], 33.33);
// Honest counters on the same seed: m1 and m4 completed the CTA transition
// (offer_at set), m5 is a direct offer click; m2/m3 stayed on the landing
// and m7's offer was pre-bound but never transitioned.
$assert('Dashboard real LP clicks', $dashboard['real_lp_clicks'], 2, 0);
$assert('Dashboard real offer clicks', $dashboard['real_offer_clicks'], 3, 0);
$assert('Dashboard real LP CTR', $dashboard['real_lp_ctr'], 40);

// The derivation loop the landings/offers endpoints run after the SQL:
// array_merge of ALL derived metrics — the same 65-metric parity the panel
// tables now ship (registrations, deposits, real_* revenue family etc.).
$deriveRow = function (array $row): array {
    $row['prelander_clicks'] = $row['clicks'];
    $m = orbitraComputeDerivedMetrics($row);
    $row = array_merge($row, $m);
    $row['visits'] = $m['clicks'];
    $row['unique_visits'] = $m['unique_clicks'];
    return $row;
};

$rows = $pdo->query(orbitraLandingsWithStatsSql('', 'payout', 'amount') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$lp = [];
foreach ($rows as $r) { $lp[$r['id']] = $deriveRow($r); }

// Landing 1: 4 visits / 2 unique (m1, m2, m3 and m7 — the last never left),
// 1 honest LP click (m1), 6 conversion events (3 sale + deposit + reg + lead),
// revenue 45+31+2=78, cost 16 (m7 costs nothing).
$assert('L1 clicks', $lp[1]['clicks'], 4, 0);
$assert('L1 group_id is available to UI filters', $lp[1]['group_id'], 1, 0);
$assert('L1 visits alias', $lp[1]['visits'], 4, 0);
$assert('L1 unique_clicks', $lp[1]['unique_clicks'], 2, 0);
$assert('L1 unique_visits alias', $lp[1]['unique_visits'], 2, 0);
$assert('L1 lp_clicks', $lp[1]['lp_clicks'], 1, 0);
$assert('L1 lp_ctr', $lp[1]['lp_ctr'], 25);
$assert('L1 conversions (events, not the is_conversion flag)', $lp[1]['conversions'], 6, 0);
$assert('L1 sales', $lp[1]['sales'], 3, 0);
$assert('L1 leads', $lp[1]['leads'], 1, 0);
$assert('L1 revenue', $lp[1]['revenue'], 78);
$assert('L1 revenue_confirmed', $lp[1]['revenue_confirmed'], 45);
$assert('L1 cost', $lp[1]['cost'], 16);
$assert('L1 cr', $lp[1]['cr'], 150);
$assert('L1 approve_rate', $lp[1]['approve_rate'], 75);
$assert('L1 epc (revenue / LP clicks)', $lp[1]['epc'], 78);
$assert('L1 epv (revenue / visitors)', $lp[1]['epv'], 19.5);
$assert('L1 epc_confirmed', $lp[1]['epc_confirmed'], 45);
$assert('L1 cpc (cost / LP clicks)', $lp[1]['cpc'], 16);
$assert('L1 cpv', $lp[1]['cpv'], 4);
$assert('L1 profit', $lp[1]['profit'], 62);
$assert('L1 profit_confirmed', $lp[1]['profit_confirmed'], 29);
$assert('L1 roi', $lp[1]['roi'], 387.5);
$assert('L1 roi_confirmed', $lp[1]['roi_confirmed'], 181.25);

// Parity counters: m2 carried a deposit (30) + a registration (1), m3 an empty
// referer, m2 was not unique, m1 waited 95s before the LP click and earned 12
// of aggregator "real" revenue (real_profit 12-16=-4, real_roi -25%).
$assert('L1 registrations', $lp[1]['registrations'], 1, 0);
$assert('L1 deposits', $lp[1]['deposits'], 1, 0);
$assert('L1 revenue_deposit', $lp[1]['revenue_deposit'], 30);
$assert('L1 revenue_registration', $lp[1]['revenue_registration'], 1);
$assert('L1 revenue_hold', $lp[1]['revenue_hold'], 2);
$assert('L1 bots', $lp[1]['bots'], 0, 0);
$assert('L1 empty_referrers', $lp[1]['empty_referrers'], 1, 0);
$assert('L1 unique_clicks_stream', $lp[1]['unique_clicks_stream'], 3, 0);
$assert('L1 unique_clicks_global', $lp[1]['unique_clicks_global'], 3, 0);
// visitors counts logged hits (COUNT), not the global unique sum — the old
// `SUM(uniq_global) as visitors` alias made the panel show Clicks > Visitors,
// an impossible relationship. Uniqueness has its own column above.
$assert('L1 visitors', $lp[1]['visitors'], 4, 0);
$assert('L1 avg_lp_seconds', $lp[1]['avg_lp_seconds'], 95);
$assert('L1 real_revenue', $lp[1]['real_revenue'], 12);
$assert('L1 real_profit', $lp[1]['real_profit'], -4);
$assert('L1 real_roi', $lp[1]['real_roi'], -25);
$assert('L1 cr_deposits (1 deposit / 4 visitors)', $lp[1]['cr_deposits'], 25);

// Landing 2: 1 visit that clicked through, one rejected conversion.
$assert('L2 clicks', $lp[2]['clicks'], 1, 0);
$assert('L2 lp_ctr', $lp[2]['lp_ctr'], 100);
$assert('L2 approve_rate (1 rejected → 0%)', $lp[2]['approve_rate'], 0);
$assert('L2 profit_confirmed', $lp[2]['profit_confirmed'], -4);
$assert('L2 roi_confirmed', $lp[2]['roi_confirmed'], -100);
// m4 is the bot click and took 120s from landing to offer.
$assert('L2 bots', $lp[2]['bots'], 1, 0);
$assert('L2 bot_rate', $lp[2]['bot_rate'], 100);
$assert('L2 avg_lp_seconds', $lp[2]['avg_lp_seconds'], 120);
$assert('L2 real_revenue (no revenue_records)', $lp[2]['real_revenue'], 0);

// Landing 3: no clicks at all — zero counters, ratios 0, LP CTR and ROI dashes.
$assert('L3 clicks', $lp[3]['clicks'], 0, 0);
$assert('L3 lp_ctr', $lp[3]['lp_ctr'], null);
$assert('L3 cpv', $lp[3]['cpv'], 0);
$assert('L3 roi null', $lp[3]['roi'], null);
// Honest transitions per landing: L1's only transition is m1 (m2/m3 never
// left), L2's m4 went through, L3 has no traffic at all.
$assert('L1 real_lp_clicks', $lp[1]['real_lp_clicks'], 1, 0);
$assert('L1 real_offer_clicks', $lp[1]['real_offer_clicks'], 1, 0);
$assert('L1 real_lp_ctr (1 real / 4 views)', $lp[1]['real_lp_ctr'], 25);
$assert('L2 real_lp_clicks', $lp[2]['real_lp_clicks'], 1, 0);
$assert('L2 real_lp_ctr (1 real / 1 view)', $lp[2]['real_lp_ctr'], 100);
$assert('L3 real_lp_ctr is a dash', $lp[3]['real_lp_ctr'], null);

// Date predicates belong in the click JOIN: an empty date must zero the
// metrics without removing landing rows from the management table.
$datedLandingStmt = $pdo->prepare(
    orbitraLandingsWithStatsSql(
        "AND date(cl.created_at, '+00:00') >= date(?) AND date(cl.created_at, '+00:00') <= date(?)",
        'payout'
    ) . ' ORDER BY id'
);
$datedLandingStmt->execute(['2026-01-02', '2026-01-02']);
$datedLandings = $datedLandingStmt->fetchAll(PDO::FETCH_ASSOC);
$assert('Dated landing filter keeps all rows', count($datedLandings), 3, 0);
$assert('Dated landing filter zeroes out-of-range clicks', $datedLandings[0]['clicks'], 0, 0);

$of = [];
foreach ($pdo->query(orbitraOffersWithStatsSql('', 'payout', 'amount') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $of[$r['id']] = $deriveRow($r);
}
// Offer 7 got exactly one offer click, m1 (m7 rows it too, but its pre-bound
// offer never transitioned — a visitor to the offer, not a click):
// LP share 100%, CR 300%.
$assert('O7 clicks (offer hits only)', $of[7]['clicks'], 1, 0);
$assert('O7 visitors (m1 + the pre-bound m7)', $of[7]['visitors'], 2, 0);
$assert('O7 lp_clicks (arrived via a landing)', $of[7]['lp_clicks'], 1, 0);
$assert('O7 lp_ctr', $of[7]['lp_ctr'], 100);
$assert('O7 conversions', $of[7]['conversions'], 3, 0);
$assert('O7 cr', $of[7]['cr'], 300);
$assert('O7 approve_rate', $of[7]['approve_rate'], 100);
$assert('O7 roi', $of[7]['roi'], 350);
// Parity counters reach the offers table too.
$assert('O7 real_revenue', $of[7]['real_revenue'], 12);
$assert('O7 registrations', $of[7]['registrations'], 0, 0);
$assert('O7 bots', $of[7]['bots'], 0, 0);
// Offer 8 got the bot click through landing 2.
$assert('O8 bots', $of[8]['bots'], 1, 0);
$assert('O8 revenue_rejected', $of[8]['revenue_rejected'], 5);
// Offer 9 got a direct click (no landing): lp_clicks 0, its conversion is trash.
$assert('O9 clicks', $of[9]['clicks'], 1, 0);
$assert('O9 lp_clicks', $of[9]['lp_clicks'], 0, 0);
$assert('O9 trash', $of[9]['trash'], 1, 0);
// Honest counters per offer: landing offers only credit transitions, the
// direct offer keeps counting (its click IS the transition).
$assert('O7 real_lp_clicks', $of[7]['real_lp_clicks'], 1, 0);
$assert('O7 real_offer_clicks', $of[7]['real_offer_clicks'], 1, 0);
$assert('O8 real_lp_clicks', $of[8]['real_lp_clicks'], 1, 0);
$assert('O8 real_offer_clicks', $of[8]['real_offer_clicks'], 1, 0);
$assert('O9 real_lp_clicks (no landing — stays 0)', $of[9]['real_lp_clicks'], 0, 0);
$assert('O9 real_offer_clicks (direct click counts)', $of[9]['real_offer_clicks'], 1, 0);

@unlink($tmpDb);
echo "Report metrics tests passed (" . count($expected) . " derived + landing/offer production-SQL checks).\n";
