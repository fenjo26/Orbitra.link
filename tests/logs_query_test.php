<?php
// tests/logs_query_test.php
//
// Logs page queries (core/logs_query.php): keyset paging without duplicates,
// limit clamping, the stream columns and filters, panel-local date bounds, the
// postbacks campaign JOIN and the CSV writer used by action=logs_export.
//
// Run: php tests/logs_query_test.php

require_once __DIR__ . '/../core/logs_query.php';

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

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE clicks (
    id TEXT PRIMARY KEY, campaign_id INTEGER, offer_id INTEGER, landing_id INTEGER, stream_id INTEGER,
    ip TEXT, country_code TEXT, country TEXT, region TEXT, city TEXT, timezone TEXT, language TEXT,
    accept_language_raw TEXT, device_type TEXT, user_agent TEXT, lp_seconds INTEGER, lp_scroll INTEGER,
    parameters_json TEXT, cloak_verdict TEXT, cloak_reasons TEXT, is_safe_page INTEGER, isp TEXT, asn TEXT,
    proxy_type TEXT, cloak_sensitivity TEXT, created_at DATETIME)");
$pdo->exec("CREATE TABLE campaigns (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE offers (id INTEGER PRIMARY KEY, name TEXT, url TEXT)");
$pdo->exec("CREATE TABLE landings (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE streams (id INTEGER PRIMARY KEY, campaign_id INTEGER, name TEXT, type TEXT)");
$pdo->exec("CREATE TABLE incoming_postbacks_log (id INTEGER PRIMARY KEY AUTOINCREMENT, click_id TEXT, status TEXT,
    original_status TEXT, payout REAL, currency TEXT, created_at DATETIME, campaign_id INTEGER, result TEXT,
    error TEXT, remote_ip TEXT, source TEXT, matched INTEGER)");
$pdo->exec("CREATE TABLE system_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, level TEXT, message TEXT, context TEXT, created_at DATETIME)");

$pdo->exec("INSERT INTO campaigns VALUES (1, 'Camp A'), (2, 'Camp B')");
$pdo->exec("INSERT INTO offers VALUES (1, 'Offer 1', 'https://o.example/1')");
$pdo->exec("INSERT INTO streams VALUES (10, 1, 'Bots → white', 'intercepting'), (11, 1, 'Main', 'regular'), (12, 1, 'Fallback', 'fallback')");

// 250 clicks, many sharing a second — the tie-breaker case OFFSET-free paging must handle.
$ins = $pdo->prepare("INSERT INTO clicks (id, campaign_id, offer_id, stream_id, ip, is_safe_page, user_agent, created_at)
                      VALUES (?, ?, 1, ?, '1.1.1.1', ?, ?, ?)");
for ($i = 0; $i < 250; $i++) {
    $stream = [10, 11, 12][$i % 3];
    $ts = sprintf('2026-09-17 %02d:%02d:00', 10 + intdiv($i, 60), intdiv($i % 60, 5)); // 5 clicks per second-slot
    $ins->execute([sprintf('c%04d', $i), $i < 200 ? 1 : 2, $stream, $stream === 10 ? 1 : 0, $i === 7 ? '=HYPERLINK("x")' : 'Mozilla', $ts]);
}

echo "limits\n";
$assert('clamp: 0 → 1', orbitraLogsClampLimit('0'), 1);
$assert('clamp: 99999 → 500', orbitraLogsClampLimit('99999'), 500);
$assert('clamp: junk → default', orbitraLogsClampLimit('abc', 50), 50);

echo "keyset paging\n";
$seen = [];
$cursor = null;
$pages = 0;
do {
    $page = orbitraLogsFetchPage($pdo, 'traffic', ['cursor' => $cursor], 100, 0, '+03:00', 10800);
    foreach ($page['rows'] as $r) {
        $seen[] = $r['click_id'];
    }
    $cursor = $page['next_cursor'];
    $pages++;
    if ($pages === 1) {
        // A click arriving between page 1 and page 2 must not shift page 2.
        $pdo->exec("INSERT INTO clicks (id, campaign_id, created_at) VALUES ('late', 1, '2026-09-18 00:00:00')");
        $assert('page 1 hides the cursor columns', array_key_exists('_cur_ts', $page['rows'][0]), false);
    }
} while ($page['has_more'] && $pages < 10);
$assert('3 pages for 250 rows', $pages, 3);
$assert('every row exactly once', count($seen), 250);
$assert('no duplicates', count(array_unique($seen)), 250);
$assert('late row not injected mid-walk', in_array('late', $seen, true), false);
$assert('last page has no cursor', $page['next_cursor'], null);

echo "stream columns and filters\n";
$one = orbitraLogsFetchPage($pdo, 'traffic', ['stream_id' => 10, 'campaign_id' => 1], 500, 0, '+00:00', 0);
$assert('stream filter: only stream 10', array_values(array_unique(array_column($one['rows'], 'stream_id'))), [10]);
$assert('stream name joined', $one['rows'][0]['stream_name'], 'Bots → white');
$assert('stream type joined', $one['rows'][0]['stream_type'], 'intercepting');
$safe = orbitraLogsFetchPage($pdo, 'traffic', ['route' => 'safe'], 500, 0, '+00:00', 0);
$assert('route=safe ⇔ intercepting rows here', count($safe['rows']), count(array_filter(range(0, 249), fn($i) => $i % 3 === 0)));
$b = orbitraLogsFetchPage($pdo, 'traffic', ['campaign_id' => 2], 500, 0, '+00:00', 0);
$assert('campaign filter', count($b['rows']), 50);

echo "date bounds (panel UTC+3)\n";
// 2026-09-17 in UTC+3 = 2026-09-16 21:00 … 2026-09-17 20:59:59 UTC → all 250 seeded rows; the 'late' row is out.
$d = orbitraLogsFetchPage($pdo, 'traffic', ['date_from' => '2026-09-17', 'date_to' => '2026-09-17'], 500, 0, '+03:00', 10800);
$assert('local day covers seeded rows', count($d['rows']), 250);
$d2 = orbitraLogsFetchPage($pdo, 'traffic', ['date_from' => '2026-09-18'], 500, 0, '+03:00', 10800);
$assert('next local day: only the late row', array_column($d2['rows'], 'click_id'), ['late']);
$assert('bad date ignored', orbitraLogsUtcBound('2026/09/17', 0, false), null);
$assert('local time shown', substr($d2['rows'][0]['created_at'], 0, 16), '2026-09-18 03:00');

echo "postbacks + system\n";
$pdo->exec("INSERT INTO incoming_postbacks_log (click_id, status, payout, created_at, campaign_id) VALUES ('c0001', 'sale', 5, '2026-09-17 10:00:00', 1), ('x', 'lead', 0, '2026-09-17 11:00:00', NULL)");
$pb = orbitraLogsFetchPage($pdo, 'postbacks', [], 50, 0, '+00:00', 0);
$assert('newest first', $pb['rows'][0]['click_id'], 'x');
$assert('campaign name via JOIN', $pb['rows'][1]['campaign_name'], 'Camp A');
$assert('no campaign → null', $pb['rows'][0]['campaign_name'], null);
$pdo->exec("INSERT INTO system_logs (level, message, created_at) VALUES ('INFO', 'a', '2026-09-17 10:00:00'), ('WARN', 'b', '2026-09-17 10:00:00')");
$sl = orbitraLogsFetchPage($pdo, 'system', [], 1, 0, '+00:00', 0);
$sl2 = orbitraLogsFetchPage($pdo, 'system', ['cursor' => $sl['next_cursor']], 1, 0, '+00:00', 0);
$assert('integer-id tie broken by id', [$sl['rows'][0]['message'], $sl2['rows'][0]['message']], ['b', 'a']);
$assert('offset still works (dashboard/MCP)', orbitraLogsFetchPage($pdo, 'system', [], 1, 1, '+00:00', 0)['rows'][0]['message'], 'a');
$threw = false;
try { orbitraLogsBuildQuery('nope', [], '+00:00', 0); } catch (InvalidArgumentException $e) { $threw = true; }
$assert('unknown type rejected', $threw, true);

echo "csv export\n";
$fh = fopen('php://memory', 'w+');
$n = orbitraLogsWriteCsv($fh, orbitraLogsIterate($pdo, 'traffic', ['campaign_id' => 1], '+00:00', 0, 100000, 37));
rewind($fh);
$csv = stream_get_contents($fh);
$assert('rows written (batch 37, all of campaign 1)', $n, 201);
$assert('UTF-8 BOM', substr($csv, 0, 3), "\xEF\xBB\xBF");
$lines = array_values(array_filter(explode("\n", substr($csv, 3)), 'strlen'));
$assert('header + rows', count($lines), 202);
$assert('header has stream_name', in_array('stream_name', str_getcsv($lines[0], ';', '"', '\\'), true), true);
$assert('no cursor columns in CSV', strpos($lines[0], '_cur_'), false);
$assert('formula neutralised', strpos($csv, "'=HYPERLINK") !== false, true);
$capped = iterator_count(orbitraLogsIterate($pdo, 'traffic', [], '+00:00', 0, 120, 50));
$assert('export cap honoured', $capped, 120);

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
