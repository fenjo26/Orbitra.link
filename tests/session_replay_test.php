<?php
// tests/session_replay_test.php
//
// Session recordings (core/SessionReplay.php), end to end:
//
//   1. A landing that does not record gets no recorder at all.
//   2. Switched on at 100%, the served landing carries /replay.js bound to
//      THIS click with a valid signature, and an 'armed' row exists.
//   3. Bots / safe-page visitors and an expired recording window never arm.
//   4. Ingest: a forged signature is refused, plain and gzipped batches are
//      appended to one file, counters + heatmap points + rage clicks land.
//   5. A subid the server never armed is refused (no free disk for strangers).
//   6. The panel stream returns every event of every batch, in one array.
//   7. The length cap closes a recording; later batches are refused.
//   8. Housekeeping: retention deletes old recordings and their files; the
//      storage cap deletes the OLDEST until the total fits.
//   9. Sampling is deterministic and close to the configured share.
//
// Run: php tests/session_replay_test.php

$testPassed = true;

function assertTrue($condition, $message) {
    global $testPassed;
    if (!$condition) {
        fwrite(STDERR, "FAILED: $message\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return $condition;
}

function assertEquals($expected, $actual, $message) {
    global $testPassed;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected: " . var_export($expected, true) . "\n");
        fwrite(STDERR, "  Actual:   " . var_export($actual, true) . "\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return $expected === $actual;
}

require_once __DIR__ . '/lib/http.php';

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);
$harness->useProductionRouter();
$harness->start();

$request = function (string $method, string $path, array $headers = [], ?string $body = null) use ($harness): array {
    $url = $harness->getBaseUrl() . '/' . ltrim($path, '/');
    $opts = ['timeout' => 8, 'ignore_errors' => true, 'follow_location' => 0, 'method' => $method,
        'header' => implode("\r\n", $headers)];
    if ($body !== null) {
        $opts['content'] = $body;
    }
    $resp = @file_get_contents($url, false, stream_context_create(['http' => $opts]));
    $lines = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?: [])
        : ($http_response_header ?? []);
    $code = 0;
    foreach ($lines as $h) {
        if (preg_match('#^HTTP/\d\.\d (\d{3})#', (string) $h, $m)) {
            $code = (int) $m[1];
        }
    }
    return ['code' => $code, 'body' => (string) $resp];
};

$browser = function (string $ip): array {
    return [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
        "X-Forwarded-For: $ip",
        'Accept-Language: en-US,en;q=0.9',
    ];
};

try {
    $pdo = $harness->getPdo();
    $work = $harness->getWorkingDir();
    define('ORBITRA_REPLAY_DIR', $work . '/var/replays');
    require_once $work . '/core/SessionReplay.php';

    assertTrue((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= 76, 'Schema is at version 76 or later');
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('session_replays','replay_clicks','replay_landing_settings')")->fetchAll(PDO::FETCH_COLUMN);
    assertEquals(3, count($tables), 'Migration 76 created the three recording tables');

    $cid = random_int(100000, 999990);
    $sid = $cid + 1;
    $lid = $cid + 2;
    $oid = $cid + 3;
    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, 'RR Offer', 'https://pp.example/t', 0, 'active', 0)")->execute([$oid]);
    $pdo->prepare("INSERT INTO landings (id, name, url, type, action_type, action_payload, state, is_archived)
                   VALUES (?, 'RR LP', '', 'action', 'show_html', '<html><body>RR_LP_MARKER <a href=\"/?_lp=1\">go</a></body></html>', 'active', 0)")->execute([$lid]);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived) VALUES (?, 'RR Camp', 'rrcamp$cid', '', 'active', 0)")->execute([$cid]);
    $schema = json_encode([
        'landings' => [['id' => $lid, 'weight' => 100, 'state' => 'active']],
        'offers' => [['id' => $oid, 'weight' => 100, 'state' => 'active']],
    ]);
    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks, offer_selection)
                   VALUES (?, ?, NULL, 'LP Stream', 'regular', 1, 'landing_offer', ?, 1, 1, 'after')")->execute([$sid, $cid, $schema]);
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('postback_key', 'rr_key')");
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('ignore_prefetch', '0')");
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('stats_enabled', '1')");

    // --- 1. Off → no recorder ---
    $r = $request('GET', "/rrcamp$cid", $browser('203.0.113.40'));
    assertEquals(200, $r['code'], 'Landing served (200)');
    assertTrue(strpos($r['body'], 'RR_LP_MARKER') !== false, 'Landing body is the action payload');
    assertTrue(strpos($r['body'], 'data-orbitra-replay') === false, 'Recording off: no recorder on the page');
    assertTrue(strpos($r['body'], 'action=lp&subid=') !== false, 'The dwell timer is still there');

    // --- 2. On at 100% → recorder bound to this click ---
    $pdo->prepare("INSERT INTO replay_landing_settings (landing_id, enabled, sample_pct, until_at, updated_at) VALUES (?, 1, 100, NULL, datetime('now'))")->execute([$lid]);
    $r = $request('GET', "/rrcamp$cid", $browser('203.0.113.41'));
    $click = $pdo->query("SELECT * FROM clicks WHERE campaign_id = $cid ORDER BY rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $clickId = (string) $click['id'];
    assertTrue(strpos($r['body'], 'src="/replay.js"') !== false, 'Recording on: /replay.js is injected');
    preg_match('/data-orbitra-replay="([^"]+)"/', $r['body'], $m);
    $payload = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);
    assertEquals($clickId, $payload['subid'] ?? null, 'The recorder is bound to THIS click');
    assertEquals('/pixel.gif', $payload['endpoint'] ?? null, 'Same-origin ingest endpoint');
    assertEquals(orbitraReplayToken($pdo, $clickId), $payload['k'] ?? null, 'The payload carries the click signature');
    assertTrue(strpos($r['body'], 'data-orbitra-replay') < strpos($r['body'], '</body>'), 'Injected before </body>');
    $row = $pdo->query("SELECT * FROM session_replays WHERE click_id = " . $pdo->quote($clickId))->fetch(PDO::FETCH_ASSOC);
    assertEquals('armed', $row['status'] ?? null, 'An armed recording row exists');
    assertEquals($lid, (int) $row['landing_id'], 'The row carries the landing');
    assertEquals(1, substr_count($r['body'], 'data-orbitra-replay'), 'Exactly one recorder tag');

    // --- 3. Bots, safe page, expired window ---
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, landing_id, ip, is_bot, created_at) VALUES (?, ?, ?, '203.0.113.50', 1, datetime('now'))")->execute(["rrbot$cid", $cid, $lid]);
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, landing_id, ip, is_safe_page, created_at) VALUES (?, ?, ?, '203.0.113.51', 1, datetime('now'))")->execute(["rrsafe$cid", $cid, $lid]);
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, landing_id, ip, created_at) VALUES (?, ?, ?, '203.0.113.52', datetime('now'))")->execute(["rrhuman$cid", $cid, $lid]);
    assertTrue(orbitraReplayArm($pdo, "rrbot$cid") === null, 'A bot is never recorded');
    assertTrue(orbitraReplayArm($pdo, "rrsafe$cid") === null, 'A safe-page visitor is never recorded');
    $pdo->prepare("UPDATE replay_landing_settings SET until_at = datetime('now', '-1 hour') WHERE landing_id = ?")->execute([$lid]);
    assertTrue(orbitraReplayArm($pdo, "rrhuman$cid") === null, 'An expired recording window arms nothing');
    $cfg = orbitraReplayLandingConfig($pdo, $lid);
    assertTrue($cfg['enabled'] && !$cfg['active'], 'Expired window: enabled but not active');
    $pdo->prepare("UPDATE replay_landing_settings SET until_at = datetime('now', '+1 hour') WHERE landing_id = ?")->execute([$lid]);
    // (A fresh click: arming memoizes its answer per request, like the server does.)
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, landing_id, ip, created_at) VALUES (?, ?, ?, '203.0.113.53', datetime('now'))")->execute(["rrhuman2$cid", $cid, $lid]);
    assertTrue(orbitraReplayArm($pdo, "rrhuman2$cid") !== null, 'An open window arms the human click');

    // --- 4. Ingest ---
    $t0 = (int) (microtime(true) * 1000);
    $batch1 = json_encode(['e' => [
        ['type' => 4, 'timestamp' => $t0, 'data' => ['href' => 'https://lp.example/', 'width' => 1280, 'height' => 720]],
        ['type' => 2, 'timestamp' => $t0 + 5, 'data' => ['node' => ['type' => 0, 'childNodes' => [], 'id' => 1], 'initialOffset' => ['left' => 0, 'top' => 0]]],
        ['type' => 3, 'timestamp' => $t0 + 900, 'data' => ['source' => 2, 'type' => 2, 'id' => 5, 'x' => 100, 'y' => 200]],
        'garbage',
    ], 'c' => [
        ['x' => 0.5, 'y' => 400, 'w' => 1280, 'h' => 3000, 'r' => 0, 's' => 'a.cta'],
        ['x' => 0.51, 'y' => 402, 'w' => 1280, 'h' => 3000, 'r' => 1, 's' => 'a.cta'],
    ]]);
    $base = "/pixel.gif?action=rr&subid=" . urlencode($clickId);
    $bad = $request('POST', $base . '&k=forged&n=1', ['Content-Type: text/plain'], $batch1);
    assertEquals(403, $bad['code'], 'A forged signature is refused');
    $ok = $request('POST', $base . '&k=' . $payload['k'] . '&n=1', ['Content-Type: text/plain'], $batch1);
    assertEquals(204, $ok['code'], 'A signed plain batch is accepted (204)');
    $batch2 = json_encode(['e' => [
        ['type' => 3, 'timestamp' => $t0 + 4000, 'data' => ['source' => 1, 'positions' => [['x' => 1, 'y' => 2, 'id' => 5, 'timeOffset' => 0]]]],
    ], 'c' => []]);
    $ok2 = $request('POST', $base . '&k=' . $payload['k'] . '&n=2&z=1', ['Content-Type: application/octet-stream'], gzencode($batch2));
    assertEquals(204, $ok2['code'], 'A gzipped batch is accepted (204)');
    $badGz = $request('POST', $base . '&k=' . $payload['k'] . '&n=3&z=1', [], 'not gzip');
    assertEquals(400, $badGz['code'], 'A z=1 body that is not gzip is refused');

    $row = $pdo->query("SELECT * FROM session_replays WHERE click_id = " . $pdo->quote($clickId))->fetch(PDO::FETCH_ASSOC);
    assertEquals('recording', $row['status'], 'Status moved to recording');
    assertEquals(2, (int) $row['chunks'], 'Two batches stored');
    assertEquals(4, (int) $row['events'], 'Four valid events counted (garbage dropped)');
    assertEquals(4000, (int) $row['duration_ms'], 'Duration = last − first event timestamp');
    assertEquals(1280, (int) $row['viewport_w'], 'Viewport width from the meta event');
    assertEquals('https://lp.example/', $row['page_url'], 'Page URL from the meta event');
    assertEquals(2, (int) $row['clicks'], 'Two heatmap clicks counted');
    assertEquals(1, (int) $row['rage_clicks'], 'One rage click counted');
    $file = ORBITRA_REPLAY_DIR . '/' . $row['file'];
    assertTrue(is_file($file), 'The recording file exists under var/replays');
    assertEquals((int) $row['bytes'], filesize($file), 'bytes matches the file size');
    $hc = $pdo->query("SELECT COUNT(*) FROM replay_clicks WHERE click_id = " . $pdo->quote($clickId))->fetchColumn();
    assertEquals(2, (int) $hc, 'Heatmap points stored');

    // --- 5. Not armed ---
    $stranger = 'never-armed-' . $cid;
    $nr = $request('POST', '/pixel.gif?action=rr&subid=' . $stranger . '&k=' . orbitraReplayToken($pdo, $stranger), [], $batch1);
    assertEquals(410, $nr['code'], 'A subid the server never armed is refused');
    assertTrue(!is_dir(ORBITRA_REPLAY_DIR . '/' . gmdate('Y-m-d')) || !is_file(ORBITRA_REPLAY_DIR . '/' . gmdate('Y-m-d') . '/' . $stranger . '.ndjson.gz'), 'Nothing written for it');

    // --- 6. Panel stream ---
    ob_start();
    orbitraReplayStreamEvents($pdo, $clickId);
    $json = json_decode(ob_get_clean(), true);
    assertEquals('success', $json['status'] ?? null, 'replay_events answers success');
    assertEquals(4, count($json['events'] ?? []), 'All events of both batches come back in one array');
    assertEquals('RR LP', $json['meta']['landing_name'] ?? null, 'Meta carries the landing name');
    assertTrue(!isset($json['meta']['file']), 'The file path is not exposed');

    $list = orbitraReplayList($pdo, ['landing_id' => $lid]);
    assertEquals(1, $list['total'], 'The list shows the recording with data (armed-only rows hidden)');
    $heat = orbitraReplayHeatmap($pdo, ['landing_id' => $lid, 'device' => 'desktop', 'days' => 7]);
    assertEquals(2, $heat['total_clicks'], 'Heatmap returns both points');
    assertEquals($clickId, $heat['background']['click_id'] ?? null, 'Heatmap background is the newest desktop recording');

    // --- 7. Length cap ---
    $late = json_encode(['e' => [['type' => 3, 'timestamp' => $t0 + (ORBITRA_REPLAY_MAX_SECONDS + 120) * 1000, 'data' => ['source' => 1]]]]);
    $cap = $request('POST', $base . '&k=' . $payload['k'] . '&n=4', [], $late);
    assertEquals(410, $cap['code'], 'A batch past the length cap is refused');
    $after = $request('POST', $base . '&k=' . $payload['k'] . '&n=5', [], $batch2);
    assertEquals(410, $after['code'], 'A closed recording stays closed');
    $row = $pdo->query("SELECT status, chunks FROM session_replays WHERE click_id = " . $pdo->quote($clickId))->fetch(PDO::FETCH_ASSOC);
    assertEquals('closed', $row['status'], 'The recording is closed');
    assertEquals(2, (int) $row['chunks'], 'What was stored before the cap is kept');

    // --- 8. Housekeeping ---
    $mk = function (string $id, string $createdAt, int $bytes) use ($pdo, $lid, $cid) {
        $rel = substr($createdAt, 0, 10) . '/' . $id . '.ndjson.gz';
        @mkdir(dirname(ORBITRA_REPLAY_DIR . '/' . $rel), 0770, true);
        file_put_contents(ORBITRA_REPLAY_DIR . '/' . $rel, gzencode("[]\n"));
        $pdo->prepare("INSERT INTO session_replays (click_id, landing_id, campaign_id, status, file, bytes, chunks, events, created_at)
                       VALUES (?, ?, ?, 'recording', ?, ?, 1, 1, ?)")->execute([$id, $lid, $cid, $rel, $bytes, $createdAt]);
        return ORBITRA_REPLAY_DIR . '/' . $rel;
    };
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('replay_retention_days', '3')");
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('replay_max_mb', '50')");
    $oldFile = $mk("rrold$cid", gmdate('Y-m-d H:i:s', time() - 5 * 86400), 1000);
    $pdo->prepare("INSERT INTO session_replays (click_id, landing_id, status, created_at) VALUES (?, ?, 'armed', ?)")
        ->execute(["rrempty$cid", $lid, gmdate('Y-m-d H:i:s', time() - 3 * 3600)]);
    $big1 = $mk("rrbig1$cid", gmdate('Y-m-d H:i:s', time() - 7200), 30 * 1024 * 1024);
    $big2 = $mk("rrbig2$cid", gmdate('Y-m-d H:i:s', time() - 3600), 30 * 1024 * 1024);
    $gc = orbitraReplayGc($pdo);
    assertEquals(1, $gc['expired'], 'Retention deleted the 5-day-old recording');
    assertTrue(!is_file($oldFile), '…and its file');
    assertEquals(1, $gc['empty'], 'An armed row that never got data is dropped after two hours');
    assertEquals(1, $gc['over_cap'], 'Over the cap: exactly one recording had to go');
    assertTrue(!is_file($big1) && is_file($big2), 'The OLDEST went, the newer one stays');
    $total = (int) $pdo->query("SELECT SUM(bytes) FROM session_replays")->fetchColumn();
    assertTrue($total <= 50 * 1024 * 1024, 'The total now fits the cap');
    $summary = orbitraReplayStorageSummary($pdo);
    assertEquals(50, $summary['max_mb'], 'Storage summary reports the cap');
    assertEquals(3, $summary['retention_days'], 'Storage summary reports the retention');

    // --- 9. Sampling ---
    $hits = 0;
    for ($i = 0; $i < 4000; $i++) {
        $hits += orbitraReplaySampleHit('click' . $i, 10) ? 1 : 0;
    }
    assertTrue($hits > 300 && $hits < 500, "10% sample hits ~400 of 4000 (got $hits)");
    assertEquals(orbitraReplaySampleHit('same-click', 37), orbitraReplaySampleHit('same-click', 37), 'Sampling is deterministic per click');
    assertTrue(orbitraReplaySampleHit('x', 100) && !orbitraReplaySampleHit('x', 0), '100% always, 0% never');

    // --- 10. Sorting + "delete everything matching the filters" ---
    $lid2 = $lid + 100;
    foreach ([['rrs1', 300, 5000], ['rrs2', 100, 9000], ['rrs3', 200, 1000]] as [$id, $bytes, $dur]) {
        $pdo->prepare("INSERT INTO session_replays (click_id, landing_id, status, file, bytes, chunks, events, duration_ms, viewport_w, created_at)
                       VALUES (?, ?, 'recording', '', ?, 1, 1, ?, 390, datetime('now'))")->execute(["$id$cid", $lid2, $bytes, $dur]);
    }
    $bySize = array_column(orbitraReplayList($pdo, ['landing_id' => $lid2, 'sort' => 'size', 'dir' => 'asc'])['rows'], 'click_id');
    assertEquals(["rrs2$cid", "rrs3$cid", "rrs1$cid"], $bySize, 'Sort by size ascending');
    $byDur = array_column(orbitraReplayList($pdo, ['landing_id' => $lid2, 'sort' => 'duration', 'dir' => 'desc'])['rows'], 'click_id');
    assertEquals(["rrs2$cid", "rrs1$cid", "rrs3$cid"], $byDur, 'Sort by duration descending');
    $bad = orbitraReplayList($pdo, ['landing_id' => $lid2, 'sort' => '1; DROP TABLE clicks', 'dir' => 'sideways']);
    assertEquals(3, count($bad['rows']), 'An unknown sort key falls back to time (whitelist)');
    $page2 = orbitraReplayList($pdo, ['landing_id' => $lid2, 'sort' => 'size', 'dir' => 'asc', 'limit' => 2, 'offset' => 2]);
    assertEquals(["rrs1$cid"], array_column($page2['rows'], 'click_id'), 'Pagination: page 2 of size 2');
    assertEquals(3, $page2['total'], 'Pagination: total counts every matching row');

    if (!function_exists('orbitraRequestBody')) {
        function orbitraRequestBody() { return (string) ($GLOBALS['RR_TEST_BODY'] ?? ''); }
    }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['RR_TEST_BODY'] = json_encode(['filter' => ['landing_id' => $lid2, 'device' => 'mobile', 'min_seconds' => 4]]);
    ob_start();
    orbitraReplayApi($pdo, 'replay_delete');
    $del = json_decode(ob_get_clean(), true);
    assertEquals(2, $del['data']['deleted'] ?? null, 'Filter delete removes exactly the matching rows (≥4 s)');
    $left = array_column(orbitraReplayList($pdo, ['landing_id' => $lid2])['rows'], 'click_id');
    assertEquals(["rrs3$cid"], $left, 'The non-matching recording stays');
    $GLOBALS['RR_TEST_BODY'] = json_encode(['ids' => ["rrs3$cid", '../../etc/passwd']]);
    ob_start();
    orbitraReplayApi($pdo, 'replay_delete');
    $del2 = json_decode(ob_get_clean(), true);
    assertEquals(1, $del2['data']['deleted'] ?? null, 'Id delete ignores malformed ids');
    $_SERVER['REQUEST_METHOD'] = 'GET';

    // /replay.js ships with the release and is a self-contained IIFE.
    $js = (string) @file_get_contents($repoRoot . '/replay.js');
    assertTrue(strlen($js) > 10000 && strpos($js, '(function(){') === 0, 'replay.js is built and wrapped (no globals leak onto the landing)');
    assertTrue(strpos($js, 'data-orbitra-replay') !== false && strpos($js, 'action=rr') !== false, 'replay.js reads its tag and posts to action=rr');
} finally {
    $harness->stop();
}

if ($testPassed) {
    echo "\nAll session replay tests passed.\n";
    exit(0);
}
fwrite(STDERR, "\nSession replay tests FAILED.\n");
exit(1);
