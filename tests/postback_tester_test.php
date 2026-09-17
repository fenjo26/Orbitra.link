<?php
/**
 * Postback tester test — drives the admin `postback_test` action end to end:
 * it must fire a real postback at its own server, map the status, queue the
 * matching S2S postback, report worker health — and leave no throwaway rows
 * behind. Delivery probing is skipped via probe_delivery=false so the test
 * never touches the network (the probe is the same curl transport the queue
 * worker uses, covered by postback_queue_response_test).
 *
 * Run with: php tests/postback_tester_test.php
 */

$testPassed = true;

function testerOk($message) {
    echo "✓ $message\n";
}
function testerEquals($expected, $actual, $message) {
    global $testPassed;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAILED: $message\n  Expected: " . var_export($expected, true) . "\n  Actual: " . var_export($actual, true) . "\n");
        $testPassed = false;
        return false;
    }
    testerOk($message);
    return true;
}

require_once __DIR__ . '/lib/http.php';

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);
$harness->useProductionRouter();
$harness->start();

try {
    $pdo = $harness->getPdo();
    $data = $harness->seedTestData();
    $campaignId = $data['campaign_id'];

    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, 'admin', 1, '{}')")
        ->execute(['tester_admin', password_hash('pass123', PASSWORD_DEFAULT)]);
    try { $pdo->exec('DELETE FROM rate_limits'); } catch (\Throwable $e) {}
    $login = $harness->postWithHeaders('/api.php?action=login', json_encode(['username' => 'tester_admin', 'password' => 'pass123']), ['Content-Type: application/json']);
    $loginBody = json_decode($login['body'], true);
    preg_match('/ORBITRASESSID=([^;]+)/', $login['headers']['Set-Cookie'] ?? '', $m);
    $ctx = ['cookie' => 'ORBITRASESSID=' . ($m[1] ?? ''), 'csrf' => $loginBody['data']['csrf_token'] ?? ''];
    testerEquals('success', $loginBody['status'] ?? 'x', 'admin login');

    // An S2S postback listening for sale. example.com resolves publicly, so
    // the enqueue-time SSRF check passes and a queue row is created. The
    // second one carries {sub_id_9} — the throwaway click has no such
    // parameter, so its queue row keeps the literal macro and must be flagged.
    // The three after it pin the v1.5.13 filter-compat verdicts: a legacy
    // 'custom' chip, the network's own word, and a template URL with an
    // unfilled bbg=xxx slot.
    $pdo->exec("INSERT INTO campaign_postbacks (campaign_id, url, method, statuses)
        VALUES ({$campaignId}, 'https://example.com/conv?subid={subid}&status={status}', 'GET', 'sale')");
    $pdo->exec("INSERT INTO campaign_postbacks (campaign_id, url, method, statuses)
        VALUES ({$campaignId}, 'https://example.com/ext?sub9={sub_id_9}', 'GET', 'sale')");
    $pdo->exec("INSERT INTO campaign_postbacks (campaign_id, url, method, statuses)
        VALUES ({$campaignId}, 'https://example.com/legacy?cid={subid}', 'GET', 'custom')");
    $pdo->exec("INSERT INTO campaign_postbacks (campaign_id, url, method, statuses)
        VALUES ({$campaignId}, 'https://example.com/byword?cid={subid}', 'GET', 'confirmed')");
    $pdo->exec("INSERT INTO campaign_postbacks (campaign_id, url, method, statuses)
        VALUES ({$campaignId}, 'https://example.com/tpl?bbg=xxx', 'GET', 'sale')");

    $resp = $harness->postWithHeaders('/api.php?action=postback_test', json_encode([
        'campaign_id' => $campaignId,
        'status' => 'confirmed',
        'probe_delivery' => false,
    ]), [
        'Cookie: ' . $ctx['cookie'], 'X-CSRF-TOKEN: ' . $ctx['csrf'], 'Content-Type: application/json',
    ]);
    $body = json_decode($resp['body'], true);
    testerEquals('success', $body['status'] ?? 'x', 'postback_test answers success');
    $d = $body['data'] ?? [];

    // The self-request must reach the real postback pipeline and record.
    testerEquals(200, $d['request']['http_code'] ?? null, 'self-postback returned 200');
    testerEquals(true, $d['recorded']['ok'] ?? null, 'recording reported ok');
    testerEquals('confirmed', $d['recorded']['original_status'] ?? null, 'original status preserved');
    // 'confirmed' has no configured type here, so the built-in alias sells it.
    testerEquals('sale', $d['recorded']['internal_status'] ?? null, 'alias mapped confirmed to sale');

    // S2S: filter matched and a queue row was created for the test click.
    testerEquals(5, count($d['s2s'] ?? []), 'configured S2S postbacks reported');
    $s2s = $d['s2s'][0] ?? [];
    testerEquals(true, $s2s['status_matched'] ?? null, 'statuses filter matched the internal status');
    testerEquals('internal', $s2s['match_reason'] ?? null, 'internal match reason reported');
    testerEquals(true, $s2s['queued'] ?? null, 'queue row created');
    testerEquals(null, ($s2s['unresolved_macros'] ?? null), 'no unresolved macros on the resolving URL');
    $ext = $d['s2s'][1] ?? [];
    testerEquals(true, ($ext['queued'] ?? false) === true && in_array('{sub_id_9}', $ext['unresolved_macros'] ?? [], true), 'unresolved {sub_id_9} flagged on the queue URL');
    // v1.5.13 compat: a pre-1.5.11 'custom' chip and the network's own word
    // both still deliver, and the tester says WHICH rule let them through.
    $legacy = $d['s2s'][2] ?? [];
    testerEquals(true, $legacy['queued'] ?? null, "legacy 'custom' chip queued the aliased conversion");
    testerEquals('alias_custom', $legacy['match_reason'] ?? null, 'alias_custom match reason reported');
    $byWord = $d['s2s'][3] ?? [];
    testerEquals(true, $byWord['queued'] ?? null, "the network's own word queued the conversion");
    testerEquals('original', $byWord['match_reason'] ?? null, 'original match reason reported');
    $tpl = $d['s2s'][4] ?? [];
    testerEquals(true, $tpl['queued'] ?? null, 'template URL queued');
    testerEquals(['xxx'], $tpl['placeholder_values'] ?? null, 'bbg=xxx template placeholder flagged');

    // Worker block present even where no cron ever ran (sandbox).
    testerEquals(true, array_key_exists('worker', $d), 'worker health reported');
    testerEquals(false, $d['worker']['cron_installed'] ?? null, 'sandbox reports cron as absent');

    // Cleanup: no pbtest- rows survive in working tables. Fresh handles: a
    // connection held across the request can stay pinned to an old snapshot.
    $fresh = $harness->getPdo();
    testerEquals(0, (int) $fresh->query("SELECT COUNT(*) FROM clicks WHERE id LIKE 'pbtest-%'")->fetchColumn(), 'throwaway click removed');
    testerEquals(0, (int) $fresh->query("SELECT COUNT(*) FROM conversions WHERE click_id LIKE 'pbtest-%'")->fetchColumn(), 'throwaway conversion removed');
    testerEquals(0, (int) $fresh->query("SELECT COUNT(*) FROM s2s_postbacks_log WHERE url LIKE '%pbtest-%'")->fetchColumn(), 'throwaway queue row removed');
    // The audit trail survives on purpose.
    testerEquals(1, (int) $fresh->query("SELECT COUNT(*) FROM incoming_postbacks_log WHERE click_id LIKE 'pbtest-%'")->fetchColumn(), 'incoming log kept as audit trail');

    // Backfill: a conversion recorded while the filter did not match (the
    // v1.5.11 break — a pre-1.5.11 'custom' chip stopped seeing aliased words)
    // must be re-enqueued by postback_backfill, exactly once, with the same
    // macro substitution a live enqueue would produce.
    $postbackKey = $data['postback_key'];
    $bfClick = 'bf-' . bin2hex(random_bytes(6));
    $pdo->exec("INSERT INTO clicks (id, campaign_id, offer_id, ip, user_agent, country_code)
        VALUES ('{$bfClick}', {$campaignId}, {$data['offer_id']}, '127.0.0.1', 'Test-Agent/1.0', 'US')");
    $pdo->exec("INSERT INTO campaign_postbacks (campaign_id, url, method, statuses)
        VALUES ({$campaignId}, 'https://example.com/bf?cid={subid}&sum={payout}', 'GET', 'lead')");
    $resp = $harness->get("/{$postbackKey}/postback?subid={$bfClick}&status=converted&payout=5");
    testerEquals(200, $resp['code'], 'missed-window postback returns 200');
    $rows = $pdo->query("SELECT COUNT(*) FROM s2s_postbacks_log WHERE url LIKE '%/bf?%'")->fetchColumn();
    testerEquals(0, (int) $rows, 'lead filter queued nothing for the aliased conversion');
    // The operator repairs the filter the way v1.5.13 expects...
    $pdo->exec("UPDATE campaign_postbacks SET statuses = 'custom' WHERE campaign_id = {$campaignId} AND url LIKE '%/bf?%'");

    $resp = $harness->postWithHeaders('/api.php?action=postback_backfill', json_encode([
        'campaign_id' => $campaignId,
        'hours' => 72,
    ]), [
        'Cookie: ' . $ctx['cookie'], 'X-CSRF-TOKEN: ' . $ctx['csrf'], 'Content-Type: application/json',
    ]);
    $bf = json_decode($resp['body'], true);
    testerEquals('success', $bf['status'] ?? 'x', 'postback_backfill answers success');
    testerEquals(true, ($bf['data']['enqueued'] ?? 0) >= 1, 'backfill enqueued the missed conversion');
    $bfUrl = null;
    foreach ($pdo->query("SELECT url FROM s2s_postbacks_log WHERE url LIKE '%/bf?%'", PDO::FETCH_ASSOC) as $row) {
        $bfUrl = $row['url'];
    }
    testerEquals(true, strpos((string) $bfUrl, "cid={$bfClick}") !== false && strpos((string) $bfUrl, 'sum=5') !== false, 'backfilled URL carries the live-path macro substitution');

    // Idempotent: the second press finds the pair already queued.
    $resp = $harness->postWithHeaders('/api.php?action=postback_backfill', json_encode(['campaign_id' => $campaignId]), [
        'Cookie: ' . $ctx['cookie'], 'X-CSRF-TOKEN: ' . $ctx['csrf'], 'Content-Type: application/json',
    ]);
    $bf2 = json_decode($resp['body'], true);
    testerEquals('success', $bf2['status'] ?? 'x', 'second backfill run answers success');
    testerEquals(0, (int) ($bf2['data']['enqueued'] ?? -1), 'second backfill run enqueues nothing');
    testerEquals(true, ($bf2['data']['existing'] ?? 0) >= 1, 'second backfill run counts the existing pair');

    // Non-admin must not run the tester.
    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, 'user', 1, '{}')")
        ->execute(['tester_user', password_hash('pass123', PASSWORD_DEFAULT)]);
    try { $pdo->exec('DELETE FROM rate_limits'); } catch (\Throwable $e) {}
    $login2 = $harness->postWithHeaders('/api.php?action=login', json_encode(['username' => 'tester_user', 'password' => 'pass123']), ['Content-Type: application/json']);
    $lb2 = json_decode($login2['body'], true);
    preg_match('/ORBITRASESSID=([^;]+)/', $login2['headers']['Set-Cookie'] ?? '', $m2);
    $resp2 = $harness->postWithHeaders('/api.php?action=postback_test', json_encode(['campaign_id' => $campaignId, 'status' => 'confirmed']), [
        'Cookie: ' . 'ORBITRASESSID=' . ($m2[1] ?? ''), 'X-CSRF-TOKEN: ' . ($lb2['data']['csrf_token'] ?? ''), 'Content-Type: application/json',
    ]);
    testerEquals('error', (json_decode($resp2['body'], true)['status'] ?? 'x'), 'non-admin is rejected');
} finally {
    $harness->stop();
}

echo $testPassed ? "\nALL TESTS PASSED\n" : "\nSOME TESTS FAILED\n";
exit($testPassed ? 0 : 1);
