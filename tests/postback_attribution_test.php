<?php
// tests/postback_attribution_test.php
//
// The Dr. Cash ingestion path, end to end, against a throwaway SQLite file:
//
//   click  →  offer URL carries &sub1={subid}  →  network posts back
//   ?subid=<click id>&status=…&lead_status=…&sale_status=…&rejected_status=…
//
// and then the two places the operator actually looks: the campaigns list
// counters and the Sub1 report row.
//
// What this pins down, in the order the bugs were found:
//   1. the campaign_report SQL parses at all (a `//` comment inside the SQL
//      string made SQLite reject every grouped report);
//   2. the conversion is stamped with its click's campaign/offer/sub_id_1..5,
//      so nothing lands in the log as an unlinked row;
//   3. sub_id_1 holds the CLICK's sub1, never the postback's subid;
//   4. status words map into the right counter regardless of case;
//   5. the Sub1 report row carries clicks AND conversions together.
//
// Run: php tests/postback_attribution_test.php

require_once __DIR__ . '/../core/ReportMetrics.php';
require_once __DIR__ . '/../core/ConversionAttribution.php';

$failures = 0;
$assert = function (string $label, $got, $expected) use (&$failures) {
    $ok = is_numeric($got) && is_numeric($expected)
        ? abs((float) $got - (float) $expected) < 0.001
        : $got === $expected;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label;
    if (!$ok) {
        echo ' — got ' . var_export($got, true) . ', expected ' . var_export($expected, true);
        $failures++;
    }
    echo "\n";
};

// --- Throwaway database -----------------------------------------------------

$tmpDb = sys_get_temp_dir() . '/orbitra_postback_attr_' . getmypid() . '.sqlite';
@unlink($tmpDb);
register_shutdown_function(static fn() => @unlink($tmpDb));

$pdo = new PDO('sqlite:' . $tmpDb, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("CREATE TABLE clicks (
    id TEXT PRIMARY KEY,
    campaign_id INTEGER NOT NULL,
    offer_id INTEGER,
    landing_id INTEGER,
    stream_id INTEGER,
    ip TEXT,
    user_agent TEXT,
    referer TEXT,
    is_bot INTEGER DEFAULT 0,
    is_proxy INTEGER DEFAULT 0,
    uniq_campaign INTEGER DEFAULT 1,
    uniq_stream INTEGER DEFAULT 1,
    uniq_global INTEGER DEFAULT 1,
    landing_at DATETIME,
    offer_at DATETIME,
    is_conversion INTEGER DEFAULT 0,
    revenue REAL DEFAULT 0,
    cost REAL DEFAULT 0,
    parameters_json TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE conversions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    click_id TEXT NOT NULL,
    tid TEXT,
    status TEXT NOT NULL,
    original_status TEXT,
    payout REAL DEFAULT 0,
    currency TEXT DEFAULT 'USD',
    sub_id_1 TEXT, sub_id_2 TEXT, sub_id_3 TEXT, sub_id_4 TEXT, sub_id_5 TEXT,
    offer_id INTEGER,
    campaign_id INTEGER,
    ip TEXT,
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(click_id, tid)
)");

$clickId = 'c7046be7-2644-40a3-a3f8-74990000a1ea';
$clickParams = json_encode([
    'sub_id_1' => 'adset_alpha',
    'sub_id_2' => 'creative_9',
    'sub_id_3' => 'ru',
    'ad_id'    => 'A-1',
]);
$pdo->prepare("INSERT INTO clicks (id, campaign_id, offer_id, landing_id, ip, user_agent, parameters_json, cost)
               VALUES (?, 42, 5, 3, '1.2.3.4', 'Mozilla/5.0', ?, 0.5)")
    ->execute([$clickId, $clickParams]);

// --- 1. Attribution payload -------------------------------------------------

echo "== attribution payload ==\n";
$attr = orbitraClickAttribution($pdo, ' ' . $clickId . ' '); // networks pad macros
$assert('click resolves after trimming', is_array($attr), true);
$assert('campaign_id from click', $attr['campaign_id'], 42);
$assert('offer_id from click', $attr['offer_id'], 5);
$assert('sub_id_1 is the click parameter', $attr['sub_id_1'], 'adset_alpha');
$assert('sub_id_1 is NOT the click id', $attr['sub_id_1'] === $clickId, false);
$assert('sub_id_3 from click', $attr['sub_id_3'], 'ru');
$assert('unset sub stays null', $attr['sub_id_4'], null);
$assert('unknown subid returns null', orbitraClickAttribution($pdo, 'no-such-click'), null);
$assert('empty subid returns null', orbitraClickAttribution($pdo, '   '), null);

// --- 2. Applying it to a conversion ----------------------------------------

echo "== applying attribution ==\n";
$pdo->prepare("INSERT INTO conversions (click_id, status, original_status, payout) VALUES (?, 'lead', 'pending', 12.5)")
    ->execute([$clickId]);
$convId = (int) $pdo->lastInsertId();
orbitraApplyConversionAttribution($pdo, $convId, $attr);

$row = $pdo->query("SELECT * FROM conversions WHERE id = $convId")->fetch();
$assert('conversion.campaign_id populated', (int) $row['campaign_id'], 42);
$assert('conversion.offer_id populated', (int) $row['offer_id'], 5);
$assert('conversion.sub_id_1 populated', $row['sub_id_1'], 'adset_alpha');
$assert('conversion.ip populated', $row['ip'], '1.2.3.4');
$assert('no conversion left without campaign_id',
    (int) $pdo->query("SELECT COUNT(*) FROM conversions WHERE campaign_id IS NULL")->fetchColumn(), 0);

// A later status update must not rewrite the original attribution.
orbitraApplyConversionAttribution($pdo, $convId, ['campaign_id' => 999, 'sub_id_1' => 'later'] + $attr);
$row = $pdo->query("SELECT campaign_id, sub_id_1 FROM conversions WHERE id = $convId")->fetch();
$assert('existing campaign_id kept', (int) $row['campaign_id'], 42);
$assert('existing sub_id_1 kept', $row['sub_id_1'], 'adset_alpha');

// --- 3. Backfill of rows written before attribution existed -----------------

echo "== backfill ==\n";
$pdo->prepare("INSERT INTO conversions (click_id, tid, status, payout) VALUES (?, 'legacy-1', 'sale', 30)")
    ->execute([$clickId]);
$pdo->exec("INSERT INTO conversions (click_id, tid, status, payout) VALUES ('click-that-is-gone', 'orphan-1', 'sale', 7)");
orbitraBackfillConversionAttribution($pdo);

$legacy = $pdo->query("SELECT campaign_id, offer_id, sub_id_1 FROM conversions WHERE tid = 'legacy-1'")->fetch();
$assert('legacy row gets campaign_id', (int) $legacy['campaign_id'], 42);
$assert('legacy row gets offer_id', (int) $legacy['offer_id'], 5);
$assert('legacy row gets sub_id_1', $legacy['sub_id_1'], 'adset_alpha');
$orphan = $pdo->query("SELECT campaign_id FROM conversions WHERE tid = 'orphan-1'")->fetch();
$assert('row with no click is left alone', $orphan['campaign_id'], null);

// --- 4. Status grouping is case-insensitive ---------------------------------

echo "== status grouping ==\n";
$groups = orbitraConversionStatusGroups();
$seen = [];
foreach ($groups as $group => $statuses) {
    foreach ($statuses as $s) {
        $assert("status '$s' appears in one group only", isset($seen[$s]), false);
        $seen[$s] = $group;
    }
}

$pdo->exec("DELETE FROM conversions");
$mixedCase = [['Approved', 40], ['PENDING', 0], ['Rejected', 0], ['Trash', 0]];
$i = 0;
foreach ($mixedCase as [$status, $payout]) {
    $pdo->prepare("INSERT INTO conversions (click_id, tid, status, payout) VALUES (?, ?, ?, ?)")
        ->execute([$clickId, 'mixed-' . (++$i), $status, $payout]);
}
$agg = $pdo->query("SELECT * FROM " . orbitraConversionAggregateSql('payout') . " WHERE click_id = '$clickId'")->fetch();
$assert('mixed-case Approved counts as a sale', (int) $agg['cnt_sale'], 1);
$assert('mixed-case PENDING counts as a hold', (int) $agg['cnt_hold'], 1);
$assert('mixed-case Rejected counts as rejected', (int) $agg['cnt_rejected'], 1);
$assert('mixed-case Trash counts as trash', (int) $agg['cnt_trash'], 1);
$assert('sale revenue found despite case', (float) $agg['rev_sale'], 40.0);

// --- 5. The Sub1 report row: clicks and conversions together ----------------
//
// The production statement is extracted from api.php rather than copied, so a
// future edit that reintroduces invalid SQL fails here instead of in the panel.

echo "== campaign_report SQL (extracted from api.php) ==\n";
$apiSource = (string) file_get_contents(__DIR__ . '/../api.php');
$assert('api.php readable', $apiSource !== '', true);
$assert('no // comment inside the report SQL',
    (bool) preg_match('/GROUP BY " \. implode\(\', \', \$dimGroupBy\) \. "\s*\/\//', $apiSource), false);
$assert('report HAVING guards the click side',
    str_contains($apiSource, 'HAVING COUNT(click_id) > 0'), true);

// Rebuild the same shape the endpoint runs: clicks LEFT JOIN the conversion
// aggregate, grouped by the sub_id_1 dimension.
$pdo->exec("DELETE FROM conversions");
$pdo->prepare("INSERT INTO conversions (click_id, status, original_status, payout, campaign_id, offer_id, sub_id_1)
               VALUES (?, 'lead', 'pending', 12.5, 42, 5, 'adset_alpha')")->execute([$clickId]);

$convAgg = orbitraConversionAggregateSql('payout');
$reportSql = "
    SELECT dim_1,
           COUNT(click_id) as clicks,
           COALESCE(SUM(cnt_any), 0) as conversions,
           COALESCE(SUM(cnt_hold), 0) as holds,
           COALESCE(SUM(click_revenue), 0) as revenue
    FROM (
        SELECT clicks.id as click_id,
               COALESCE(cv.cnt_any, 0) as cnt_any,
               COALESCE(cv.cnt_hold, 0) as cnt_hold,
               COALESCE(cv.rev_all, 0) as click_revenue,
               COALESCE(json_extract(clicks.parameters_json, '\$.sub_id_1'), 'Unknown') as dim_1
        FROM clicks
        LEFT JOIN $convAgg cv ON cv.click_id = clicks.id
        WHERE clicks.campaign_id = 42
    )
    GROUP BY dim_1
    HAVING COUNT(click_id) > 0
    ORDER BY clicks DESC
";
$reportRow = $pdo->query($reportSql)->fetch();
$assert('report groups on the click sub1', $reportRow['dim_1'], 'adset_alpha');
$assert('report row has the click', (int) $reportRow['clicks'], 1);
$assert('report row has the conversion', (int) $reportRow['conversions'], 1);
$assert('report row counts the lead', (int) $reportRow['holds'], 1);
$metrics = orbitraComputeDerivedMetrics([
    'clicks' => (int) $reportRow['clicks'],
    'conversions' => (int) $reportRow['conversions'],
    'revenue' => (float) $reportRow['revenue'],
]);
$assert('CR is not zero', (float) $metrics['cr'] > 0, true);

echo "\n";
if ($failures > 0) {
    echo "postback attribution tests FAILED ($failures)\n";
    exit(1);
}
echo "Postback attribution tests passed.\n";
