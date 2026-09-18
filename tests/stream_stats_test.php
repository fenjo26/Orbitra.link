<?php
// tests/stream_stats_test.php
//
// Streams keep their IDs across campaign saves, and the Streams tab counters
// (core/stream_stats.php) split a period's hits per stream.
//
// Run: php tests/stream_stats_test.php

require_once __DIR__ . '/../core/stream_stats.php';

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
$pdo->exec("CREATE TABLE streams (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER NOT NULL, offer_id INTEGER,
    weight INTEGER, is_active INTEGER, type TEXT, position INTEGER, filters_json TEXT, filters_logic TEXT, schema_type TEXT,
    action_payload TEXT, schema_custom_json TEXT, offer_selection TEXT, name TEXT, collect_clicks INTEGER)");
$pdo->exec("CREATE TABLE clicks (id TEXT PRIMARY KEY, campaign_id INTEGER, stream_id INTEGER, ip TEXT,
    is_bot INTEGER DEFAULT 0, is_conversion INTEGER DEFAULT 0, created_at DATETIME)");

$row = static fn(array $s): array => [
    $s['offer_id'] ?? null, $s['weight'] ?? 100, 1, $s['type'] ?? 'regular', $s['position'] ?? 0,
    json_encode($s['filters'] ?? []), 'and', 'redirect', '', '{}', 'after', $s['name'] ?? null, 1,
];

echo "stable stream ids\n";
$first = orbitraSaveCampaignStreams($pdo, 1, [
    ['name' => 'Black', 'type' => 'regular'],
    ['name' => 'dno', 'type' => 'fallback'],
], $row);
$assert('two streams inserted', count($first), 2);
[$black, $dno] = $first;
$pdo->exec("INSERT INTO streams (campaign_id, name, type) VALUES (2, 'other campaign', 'regular')");
$foreign = (int) $pdo->lastInsertId();

// Second save: rename Black, keep dno, add Filter, and try to hijack a stream
// of another campaign by id — it must be inserted fresh, not updated.
$second = orbitraSaveCampaignStreams($pdo, 1, [
    ['id' => 'temp_123', 'name' => 'Filter', 'type' => 'intercepting'],
    ['id' => $black, 'name' => 'Black RU', 'type' => 'regular'],
    ['id' => $dno, 'name' => 'dno', 'type' => 'fallback'],
    ['id' => $foreign, 'name' => 'hijack', 'type' => 'regular'],
], $row);
$assert('Black kept its id', $second[1], $black);
$assert('dno kept its id', $second[2], $dno);
$assert('temp id → new stream', $second[0] > $foreign, true);
$assert('foreign id not reused', $second[3] !== $foreign, true);
$assert('rename applied in place', $pdo->query("SELECT name FROM streams WHERE id = $black")->fetchColumn(), 'Black RU');
$assert('other campaign untouched', $pdo->query("SELECT name FROM streams WHERE id = $foreign")->fetchColumn(), 'other campaign');

// Duplicate in the editor carries the source id — first keeps it, copy is new.
$third = orbitraSaveCampaignStreams($pdo, 1, [
    ['id' => $black, 'name' => 'Black RU'],
    ['id' => $black, 'name' => 'Black RU (copy)'],
    ['id' => $dno, 'name' => 'dno', 'type' => 'fallback'],
], $row);
$assert('duplicate: original keeps id', $third[0], $black);
$assert('duplicate: copy gets new id', $third[1] !== $black, true);
$assert('removed streams deleted (Filter, hijack copy)', (int) $pdo->query("SELECT COUNT(*) FROM streams WHERE campaign_id = 1")->fetchColumn(), 3);
orbitraSaveCampaignStreams($pdo, 1, [], $row);
$assert('empty payload clears campaign', (int) $pdo->query("SELECT COUNT(*) FROM streams WHERE campaign_id = 1")->fetchColumn(), 0);
$assert('…and only that campaign', (int) $pdo->query("SELECT COUNT(*) FROM streams WHERE campaign_id = 2")->fetchColumn(), 1);

echo "period ranges (panel UTC+3)\n";
$now = strtotime('2026-09-18 10:00:00 UTC'); // 13:00 local
$assert('today', orbitraStreamStatsRange('today', 10800, $now), ['2026-09-17 21:00:00', '2026-09-18 20:59:59']);
$assert('yesterday', orbitraStreamStatsRange('yesterday', 10800, $now), ['2026-09-16 21:00:00', '2026-09-17 20:59:59']);
$assert('7d', orbitraStreamStatsRange('7d', 10800, $now)[0], '2026-09-11 21:00:00');
$late = strtotime('2026-09-18 22:30:00 UTC'); // already the 19th locally
$assert('local midnight crossed', orbitraStreamStatsRange('today', 10800, $late)[0], '2026-09-18 21:00:00');

echo "counters\n";
$ids = orbitraSaveCampaignStreams($pdo, 5, [['name' => 'Filter', 'type' => 'intercepting'], ['name' => 'Black'], ['name' => 'dno', 'type' => 'fallback']], $row);
[$fil, $blk, $dn] = $ids;
$ins = $pdo->prepare("INSERT INTO clicks (id, campaign_id, stream_id, ip, is_bot, is_conversion, created_at) VALUES (?, 5, ?, ?, ?, ?, ?)");
$n = 0;
$add = function (int $stream, int $count, int $ips, int $bots, int $conv, string $ts) use ($ins, &$n) {
    for ($i = 0; $i < $count; $i++) {
        $ins->execute(['k' . (++$n), $stream, "10.0.$stream." . ($i % $ips), $i < $bots ? 1 : 0, $i < $conv ? 1 : 0, $ts]);
    }
};
$add($fil, 10, 4, 7, 0, '2026-09-18 08:00:00');
$add($blk, 80, 50, 0, 3, '2026-09-18 09:00:00');
$add($dn, 5, 5, 0, 0, '2026-09-18 09:30:00');
$add(9999, 5, 5, 0, 0, '2026-09-18 09:45:00');   // old / deleted stream id
$add($blk, 100, 100, 0, 0, '2026-09-17 12:00:00'); // yesterday local
$st = orbitraStreamStats($pdo, 5, 'today', 10800, $now);
$assert('total today', $st['total'], 100);
$assert('Filter visitors', $st['streams'][$fil]['visitors'], 10);
$assert('Filter unique IPs', $st['streams'][$fil]['unique'], 4);
$assert('Filter bots', $st['streams'][$fil]['bots'], 7);
$assert('Black share', $st['streams'][$blk]['share'], 80.0);
$assert('Black conversions', $st['streams'][$blk]['conversions'], 3);
$assert('deleted stream → other bucket', [$st['other']['visitors'], $st['other']['share']], [5, 5.0]);
$assert('other campaign excluded', isset($st['streams'][$foreign]), false);
$y = orbitraStreamStats($pdo, 5, 'yesterday', 10800, $now);
$assert('yesterday only yesterday', [$y['total'], $y['streams'][$blk]['visitors']], [100, 100]);
$assert('bad period → today', orbitraStreamStats($pdo, 5, 'DROP', 10800, $now)['period'], 'today');
$assert('no traffic → empty', orbitraStreamStats($pdo, 77, 'today', 0, $now)['total'], 0);

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
