<?php
/**
 * A postback that outruns its click (core/PostbackPending.php).
 *
 * A click whose INSERT hit a locked database sits in var/spool/clicks.log for
 * up to a minute. A conversion reported in that window used to get 404 and
 * was lost. Covered here, over real HTTP and the real spool cron:
 *
 *  - idle spool + unknown subid          → 404, exactly as before;
 *  - ordinary conversion                 → 200 recorded, exactly as before;
 *  - click in the spool                  → 200 "waiting", parked as
 *    pending_click, no conversion yet; after the cron the conversion exists,
 *    stamped with the time the postback was RECEIVED, the S2S queue is fed
 *    and the log row is closed;
 *  - the same postback sent twice        → one conversion;
 *  - lead then sale, both parked         → the conversion ends as sale;
 *  - lead parked, sale applied live      → the lead is 'superseded', the
 *    conversion stays sale;
 *  - the click never arrives             → 'unmatched' after the TTL;
 *  - database locked while parking       → 503, nothing acknowledged;
 *  - database locked during the replay   → the row stays in the queue and is
 *    processed on a later tick.
 *
 * Usage: php tests/postback_pending_click_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/http.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->start();
try {
    $seed = $harness->seedTestData();
    $key = $seed['postback_key'];
    $campaignId = $seed['campaign_id'];
    $pdo = $harness->getPdo();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $work = $harness->getWorkingDir();
    @mkdir($work . '/cli', 0777, true);
    copy(__DIR__ . '/../cli/click_spool_cron.php', $work . '/cli/click_spool_cron.php');
    copy(__DIR__ . '/../cli/postback_replay.php', $work . '/cli/postback_replay.php');
    require_once $work . '/core/click_logger.php';
    require_once $work . '/core/PostbackPending.php';

    $runCron = static function () use ($work): void {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php') . ' --quiet 2>/dev/null');
    };
    $spoolClick = static function (string $id) use ($campaignId, $seed): bool {
        return orbitraSpoolClick([
            'id' => $id, 'campaign_id' => $campaignId, 'offer_id' => $seed['offer_id'],
            'ip' => '203.0.113.9', 'user_agent' => 'Spool-Agent/1.0',
            'parameters_json' => json_encode(['sub_id_1' => 'banner7']),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    };
    $post = static function (string $query, int $timeout = 5) use ($harness, $key): array {
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        $body = @file_get_contents($harness->getBaseUrl() . "/{$key}/postback?" . $query, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
        return ['code' => $code, 'body' => (string) $body];
    };
    $logRow = static function (string $clickId) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT * FROM incoming_postbacks_log WHERE click_id = ? ORDER BY id');
        $stmt->execute([$clickId]);
        return $stmt->fetchAll();
    };
    // fetchAll everywhere: an open cursor pins this connection to an old WAL
    // snapshot and the next write here fails with SQLITE_BUSY_SNAPSHOT.
    $one = static function (string $sql, array $params = []) use ($pdo): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return $rows[0] ?? [];
    };
    $conversions = static function (string $clickId) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT * FROM conversions WHERE click_id = ? ORDER BY id');
        $stmt->execute([$clickId]);
        return $stmt->fetchAll();
    };

    // One outgoing S2S postback on the campaign: the replay must feed the queue.
    $pdo->prepare("INSERT INTO campaign_postbacks (campaign_id, url, method, statuses) VALUES (?, ?, 'GET', 'lead,sale')")
        ->execute([$campaignId, 'https://93.184.216.34/pb?cid={subid}&st={status}']);

    // --- 1. idle spool: nothing changes -----------------------------------------
    check(!orbitraPostbackClickMayBeInFlight($work), 'idle spool: no click can be in flight');
    $r = $post('subid=no-such-click&status=lead&payout=1');
    check($r['code'] === 404, 'unknown subid with an idle spool is still 404 (got ' . $r['code'] . ')');
    $rows = $logRow('no-such-click');
    check(count($rows) === 1 && $rows[0]['result'] === 'rejected', 'and is logged as rejected, not parked');

    $r = $post('subid=' . $seed['click_id'] . '&status=lead&payout=10&currency=USD');
    check($r['code'] === 200 && count($conversions($seed['click_id'])) === 1, 'ordinary conversion is recorded as before');

    // --- 2. click in the spool ---------------------------------------------------
    check($spoolClick('spooled-1'), 'click written to the spool');
    check(orbitraPostbackClickMayBeInFlight($work), 'non-empty spool: a click may be in flight');
    $r = $post('subid=spooled-1&status=lead&payout=12.5&currency=USD');
    check($r['code'] === 200 && stripos($r['body'], 'waiting') !== false, 'postback for a spooled click is accepted with 200 (got ' . $r['code'] . ')');
    $rows = $logRow('spooled-1');
    check(count($rows) === 1 && $rows[0]['result'] === 'pending_click', 'parked as pending_click');
    check($conversions('spooled-1') === [], 'no conversion before the click exists');

    // Make "received" visibly earlier than the replay.
    $pdo->prepare("UPDATE incoming_postbacks_log SET created_at = datetime('now', '-40 minutes') WHERE id = ?")
        ->execute([$rows[0]['id']]);
    $receivedAt = $one('SELECT created_at FROM incoming_postbacks_log WHERE id = ?', [$rows[0]['id']])['created_at'];

    $runCron();
    $conv = $conversions('spooled-1');
    check(count($conv) === 1, 'after the cron the conversion exists');
    check(($conv[0]['status'] ?? '') === 'lead' && abs((float) ($conv[0]['payout'] ?? 0) - 12.5) < 0.001, 'with the reported status and payout');
    check(($conv[0]['created_at'] ?? '') === $receivedAt, 'stamped with the time the postback was received');
    check((int) ($conv[0]['campaign_id'] ?? 0) === $campaignId && ($conv[0]['sub_id_1'] ?? '') === 'banner7', 'attributed to the click (campaign, sub_id_1)');
    $rows = $logRow('spooled-1');
    check($rows[0]['result'] === 'recorded' && (int) $rows[0]['conversion_id'] === (int) $conv[0]['id'] && (int) $rows[0]['matched'] === 1, 'log row closed as recorded and linked to the conversion');
    check(count($rows) === 1, 'the replay reused the log row instead of adding one');
    $click = $one("SELECT is_conversion, revenue FROM clicks WHERE id = 'spooled-1'");
    check((int) $click['is_conversion'] === 1 && abs((float) $click['revenue'] - 12.5) < 0.001, 'click counters updated');
    $queuedUrls = static function (int $conversionId) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT url FROM s2s_postbacks_log WHERE conversion_id = ?');
        $stmt->execute([$conversionId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    };
    $urls = $queuedUrls((int) $conv[0]['id']);
    check(count($urls) === 1 && strpos($urls[0], 'cid=spooled-1') !== false, 'outgoing S2S postback queued once (' . json_encode($urls) . ')');

    $runCron();
    check(count($conversions('spooled-1')) === 1 && count($queuedUrls((int) $conv[0]['id'])) === 1, 'a second cron tick changes nothing');

    // --- 3. duplicates -----------------------------------------------------------
    $spoolClick('spooled-dup');
    $post('subid=spooled-dup&status=lead&payout=5');
    $post('subid=spooled-dup&status=lead&payout=5');
    check(count($logRow('spooled-dup')) === 2, 'two identical postbacks parked');
    $runCron();
    $conv = $conversions('spooled-dup');
    check(count($conv) === 1 && abs((float) $conv[0]['payout'] - 5) < 0.001, 'duplicates produce one conversion, payout not doubled');
    $click = $one("SELECT revenue FROM clicks WHERE id = 'spooled-dup'");
    check(abs((float) $click['revenue'] - 5) < 0.001, 'click revenue counted once');
    check(array_column($logRow('spooled-dup'), 'result') === ['recorded', 'updated'], 'log shows recorded then updated');

    // --- 4. status change, both parked ---------------------------------------------
    $spoolClick('spooled-chg');
    $post('subid=spooled-chg&status=lead&payout=0');
    $post('subid=spooled-chg&status=sale&payout=30');
    $runCron();
    $conv = $conversions('spooled-chg');
    check(count($conv) === 1 && $conv[0]['status'] === 'sale' && abs((float) $conv[0]['payout'] - 30) < 0.001, 'lead then sale, both delayed: conversion ends as sale');

    // --- 5. a newer live postback wins over a parked older one ---------------------
    $spoolClick('spooled-old');
    $post('subid=spooled-old&status=lead&payout=0');
    // The click lands (as the cron would do it) but the parked lead is not replayed yet.
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, offer_id, ip, user_agent) VALUES ('spooled-old', ?, ?, '203.0.113.9', 'Spool-Agent/1.0')")
        ->execute([$campaignId, $seed['offer_id']]);
    $r = $post('subid=spooled-old&status=sale&payout=40');
    check($r['code'] === 200 && ($conversions('spooled-old')[0]['status'] ?? '') === 'sale', 'live sale recorded once the click is there');
    $runCron();
    $conv = $conversions('spooled-old');
    check(count($conv) === 1 && $conv[0]['status'] === 'sale' && abs((float) $conv[0]['payout'] - 40) < 0.001, 'the delayed lead did not roll the sale back');
    check(array_column($logRow('spooled-old'), 'result') === ['superseded', 'recorded'], 'the delayed lead is closed as superseded');

    // --- 6. the click never arrives -------------------------------------------------
    $pdo->prepare("INSERT INTO incoming_postbacks_log (created_at, remote_ip, request_uri, params_json, source, click_id, result)
                   VALUES (datetime('now', '-3 hours'), '198.51.100.1', '/x/postback', ?, 'postback', 'never-came', 'pending_click')")
        ->execute([json_encode(['subid' => 'never-came', 'status' => 'lead'])]);
    $pdo->prepare("INSERT INTO incoming_postbacks_log (created_at, remote_ip, request_uri, params_json, source, click_id, result)
                   VALUES (datetime('now', '-5 minutes'), '198.51.100.1', '/x/postback', ?, 'postback', 'not-yet', 'pending_click')")
        ->execute([json_encode(['subid' => 'not-yet', 'status' => 'lead'])]);
    $runCron();
    check($logRow('never-came')[0]['result'] === 'unmatched', 'no click within the TTL: unmatched');
    check($logRow('not-yet')[0]['result'] === 'pending_click', 'younger than the TTL: keeps waiting');
    check($conversions('never-came') === [] && $conversions('not-yet') === [], 'and no orphan conversions are created');

    // --- 7. database locked ---------------------------------------------------------
    $spoolClick('spooled-lock');
    $locker = $harness->getPdo();
    $locker->exec('BEGIN IMMEDIATE');
    $r = $post('subid=spooled-lock&status=lead&payout=7', 25);
    check($r['code'] === 503, 'cannot park while the database is locked: 503, not 200 (got ' . $r['code'] . ')');
    $locker->exec('ROLLBACK');
    $r = $post('subid=spooled-lock&status=lead&payout=7');
    check($r['code'] === 200, 'the retry is accepted once the lock is gone');

    // The click lands, then the database is locked again while the cron runs.
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, offer_id, ip, user_agent) VALUES ('spooled-lock', ?, ?, '203.0.113.9', 'Spool-Agent/1.0')")
        ->execute([$campaignId, $seed['offer_id']]);
    @unlink($work . '/var/spool/clicks.log'); // already landed "by hand"
    $pendingId = null;
    foreach ($logRow('spooled-lock') as $row) {
        if ($row['result'] === 'pending_click') {
            $pendingId = (int) $row['id'];
        }
    }
    check($pendingId !== null, 'the retried postback is parked');
    $pdo->exec("UPDATE incoming_postbacks_log SET result = 'processing' WHERE id = " . (int) $pendingId);
    $locker->exec('BEGIN IMMEDIATE');
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/postback_replay.php') . ' ' . (int) $pendingId . ' 2>/dev/null');
    $locker->exec('ROLLBACK');
    $state = $one('SELECT result FROM incoming_postbacks_log WHERE id = ?', [$pendingId])['result'];
    check($state === 'processing' && $conversions('spooled-lock') === [], 'replay under a locked database leaves the row unfinished (no false "error")');
    $runCron();
    $state = $one('SELECT result FROM incoming_postbacks_log WHERE id = ?', [$pendingId])['result'];
    $conv = $conversions('spooled-lock');
    check($state === 'recorded' && count($conv) === 1 && abs((float) $conv[0]['payout'] - 7) < 0.001, 'and the next tick processes it');

    // --- 8. the lookup the cron runs every minute uses the partial index -------------
    $plan = implode(' ', array_column($pdo->query("EXPLAIN QUERY PLAN SELECT id FROM incoming_postbacks_log WHERE result = 'pending_click' ORDER BY id LIMIT 500")->fetchAll(), 'detail'));
    check(strpos($plan, 'idx_incoming_postbacks_pending') !== false, 'pending lookup uses idx_incoming_postbacks_pending (' . $plan . ')');
    $plan = implode(' ', array_column($pdo->query("EXPLAIN QUERY PLAN UPDATE incoming_postbacks_log SET result = 'pending_click' WHERE result = 'processing'")->fetchAll(), 'detail'));
    check(strpos($plan, 'idx_incoming_postbacks_processing') !== false, 'stale-claim reset uses idx_incoming_postbacks_processing (' . $plan . ')');
} finally {
    $harness->stop();
}

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
