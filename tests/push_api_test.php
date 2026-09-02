<?php
// tests/push_api_test.php
//
// Phase-4 API e2e on the REAL schema (sandboxed /tmp copy + scratch SQLite,
// the working orbitra_db.sqlite is never touched): message CRUD with the
// 250/400 length validation, segment enqueue via push_send_now (one JOIN over
// the shared conversion aggregate), queue dedup, delay_seconds → run_at,
// delivery stats in push_messages, push_queue_list and the push-resource RBAC
// matrix ('none' blocks reads, 'read' blocks writes).
//
// Boots through the production router — /api.php only exists there (this is
// the media_http_test pattern; index.php's routing knows no /api.php).
//
// Run: php tests/push_api_test.php

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/tests/lib/http.php';

$testPassed = true;
function assertTrue($condition, string $message): bool {
    global $testPassed;
    if (!$condition) { fwrite(STDERR, "FAILED: $message\n"); $testPassed = false; }
    else { echo "✓ $message\n"; }
    return (bool) $condition;
}

$harness = new OrbitraTestHarness($repoRoot);
$harness->useProductionRouter();
$harness->start();

try {
    $pdo = $harness->getPdo();

    // Schema stamp: read from config.php, never hardcoded.
    $expectedSchema = '';
    if (preg_match('/\$LATEST_SCHEMA_VERSION\s*=\s*(\d+)/', file_get_contents($repoRoot . '/config.php'), $mSchema)) {
        $expectedSchema = $mSchema[1];
    }
    assertTrue($expectedSchema !== '' && (int) $pdo->query('PRAGMA user_version')->fetchColumn() === (int) $expectedSchema,
        "migration stamps schema $expectedSchema");

    $insertUser = static function (string $username, string $role, ?array $permissions) use ($pdo) {
        $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, ?, 1, ?)")
            ->execute([$username, password_hash('pass123', PASSWORD_DEFAULT), $role, $permissions === null ? '{}' : json_encode($permissions)]);
    };
    $insertUser('push_admin', 'admin', null);
    $insertUser('push_none', 'user', ['push' => ['access' => 'none']]);
    $insertUser('push_read', 'user', ['push' => ['access' => 'read']]);

    $login = static function (string $username) use ($harness) {
        try { $harness->getPdo()->exec('DELETE FROM rate_limits'); } catch (\Throwable $e) {}
        $resp = $harness->postWithHeaders('/api.php?action=login', json_encode(['username' => $username, 'password' => 'pass123']), ['Content-Type: application/json']);
        $body = json_decode($resp['body'], true);
        if (($body['status'] ?? '') !== 'success') {
            fwrite(STDERR, "login failed for $username: " . $resp['body'] . "\n");
            exit(1);
        }
        preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $m);
        return ['cookie' => 'ORBITRASESSID=' . $m[1], 'csrf' => $body['data']['csrf_token'] ?? ''];
    };
    $get = static function (string $action, array $ctx) use ($harness) {
        return $harness->getWithHeaders("/api.php?action=$action", ['Cookie: ' . $ctx['cookie']]);
    };
    $post = static function (string $action, array $ctx, array $payload) use ($harness) {
        return $harness->postWithHeaders("/api.php?action=$action", json_encode($payload), [
            'Cookie: ' . $ctx['cookie'], 'X-CSRF-TOKEN: ' . $ctx['csrf'], 'Content-Type: application/json',
        ]);
    };

    $admin = $login('push_admin');

    // --- save: create + validation ------------------------------------------
    $save = $post('push_message_save', $admin, [
        'title' => 'Ваш донат {увеличен|удвоен}', 'text' => 'Заберите {Random=(1,5)} бонусов',
        'link_url' => 'https://tracker.example.com/camp?subid={subid}',
        'kind' => 'manual', 'segment' => 'reg1dep0', 'active' => 1,
    ]);
    assertTrue(($save['code'] ?? 0) === 200, 'push_message_save accepted');
    $savedBody = json_decode($save['body'], true);
    $msgId = (int) ($savedBody['data']['row']['id'] ?? 0);
    assertTrue($msgId > 0, 'push_message_save returns the new row id');
    assertTrue(($savedBody['data']['row']['segment'] ?? '') === 'reg1dep0', 'saved row carries the segment');

    $tooLong = $post('push_message_save', $admin, ['title' => str_repeat('x', 251), 'text' => 't', 'active' => 1]);
    assertTrue((json_decode($tooLong['body'], true)['message'] ?? '') === 'push.tooLong', '250-char title limit enforced');
    $tooLongText = $post('push_message_save', $admin, ['title' => 'T', 'text' => str_repeat('x', 401), 'active' => 1]);
    assertTrue((json_decode($tooLongText['body'], true)['message'] ?? '') === 'push.tooLong', '400-char text limit enforced');
    $noText = $post('push_message_save', $admin, ['title' => 'T', 'text' => '', 'active' => 1]);
    assertTrue((json_decode($noText['body'], true)['message'] ?? '') === 'push.titleTextRequired', 'empty text rejected');
    $badEvent = $post('push_message_save', $admin, ['title' => 'T', 'text' => 'B', 'kind' => 'event', 'event' => 'nope', 'active' => 1]);
    assertTrue((json_decode($badEvent['body'], true)['message'] ?? '') === 'push.eventRequired', 'event message requires a known event');

    // Update roundtrip — the modal posts the FULL form, so absent fields are
    // stored as empty (save = full dump, same semantics as the panel form).
    $upd = $post('push_message_save', $admin, [
        'id' => $msgId, 'title' => 'Updated', 'text' => 'B2',
        'link_url' => 'https://tracker.example.com/camp?subid={subid}',
        'kind' => 'manual', 'segment' => 'reg1dep0', 'active' => 0,
    ]);
    assertTrue((json_decode($upd['body'], true)['data']['row']['title'] ?? '') === 'Updated', 'push_message_save updates in place');

    // --- segment fixtures on the real schema ---------------------------------
    $campaignId = random_int(10000, 99999);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived) VALUES (?, ?, ?, ?, 'active', 0)")
        ->execute([$campaignId, 'Push API Campaign', 'pushapicamp' . $campaignId, 'pushapi_' . $campaignId]);
    foreach (['px-reg' => ['lead'], 'px-dep' => ['registration', 'sale'], 'px-plain' => []] as $cid => $convStatuses) {
        $pdo->prepare("INSERT OR IGNORE INTO clicks (id, campaign_id, ip, user_agent) VALUES (?, ?, '1.2.3.4', 'UA')")
            ->execute([$cid, $campaignId]);
        foreach ($convStatuses as $st) {
            $pdo->prepare("INSERT INTO conversions (click_id, status) VALUES (?, ?)")->execute([$cid, $st]);
        }
    }
    $addSub = static function (string $cid, int $active) use ($pdo) {
        $pdo->prepare("INSERT INTO push_subscriptions (click_id, endpoint, p256dh, auth, is_active) VALUES (?, ?, 'k', 'a', ?)")
            ->execute([$cid, 'https://ep/' . $cid . '/' . bin2hex(random_bytes(3)), $active]);
    };
    $addSub('px-reg', 1);
    $addSub('px-dep', 1);
    $addSub('px-dep', 0); // dead — never enqueued
    $addSub('px-plain', 1);

    // Reactivate the message (the update above turned it off).
    $post('push_message_save', $admin, [
        'id' => $msgId, 'title' => 'Updated', 'text' => 'B2',
        'link_url' => 'https://tracker.example.com/camp?subid={subid}',
        'kind' => 'manual', 'segment' => 'reg1dep0', 'active' => 1,
    ]);

    $send = $post('push_send_now', $admin, ['message_id' => $msgId]);
    assertTrue(($send['code'] ?? 0) === 200 && (json_decode($send['body'], true)['data']['enqueued'] ?? -1) === 1,
        'push_send_now enqueued exactly the reg1dep0 subscriber');
    $send2 = $post('push_send_now', $admin, ['message_id' => $msgId]);
    assertTrue((json_decode($send2['body'], true)['data']['enqueued'] ?? -1) === 0, 'second send_now dedups to 0');

    // {subid} stays raw in the message — expansion happens at send time.
    $rawLink = $pdo->query("SELECT link_url FROM push_messages WHERE id = $msgId")->fetchColumn();
    assertTrue($rawLink === 'https://tracker.example.com/camp?subid={subid}', 'stored text keeps the raw {subid} macro');

    // Segment override: same message re-sent (new message row) to reg0.
    $msgReg0 = $post('push_message_save', $admin, ['title' => 'Plain', 'text' => 'B', 'segment' => 'reg0', 'active' => 1]);
    $msgReg0Id = (int) (json_decode($msgReg0['body'], true)['data']['row']['id'] ?? 0);
    $sendReg0 = $post('push_send_now', $admin, ['message_id' => $msgReg0Id]);
    assertTrue((json_decode($sendReg0['body'], true)['data']['enqueued'] ?? -1) === 1,
        'reg0 segment enqueues only the conversion-less subscriber');
    // Segment override on a clean message: reg1dep1 picks exactly px-dep.
    $msgOverride = $post('push_message_save', $admin, ['title' => 'Ovr', 'text' => 'B', 'segment' => 'all', 'active' => 1]);
    $msgOverrideId = (int) (json_decode($msgOverride['body'], true)['data']['row']['id'] ?? 0);
    $sendOverride = $post('push_send_now', $admin, ['message_id' => $msgOverrideId, 'segment' => 'reg1dep1']);
    assertTrue((json_decode($sendOverride['body'], true)['data']['enqueued'] ?? -1) === 1,
        'segment override targets exactly the reg1dep1 subscriber');

    // Event message with delay.
    $msgDep = $post('push_message_save', $admin, ['title' => 'Dep', 'text' => 'B', 'kind' => 'event', 'event' => 'sale', 'delay_seconds' => 300, 'segment' => 'all', 'active' => 1]);
    $msgDepId = (int) (json_decode($msgDep['body'], true)['data']['row']['id'] ?? 0);
    $sendDep = $post('push_send_now', $admin, ['message_id' => $msgDepId, 'segment' => 'reg1dep1']);
    assertTrue((json_decode($sendDep['body'], true)['data']['enqueued'] ?? -1) === 1, 'event message queued for the depositing subscriber');
    $qRow = $pdo->query("SELECT status, run_at FROM push_queue WHERE message_id = $msgDepId")->fetch(PDO::FETCH_ASSOC);
    assertTrue(($qRow['status'] ?? '') === 'pending' && (string) $qRow['run_at'] >= date('Y-m-d H:i:s', time() + 240),
        'event message queued pending with delay_seconds honored in run_at');

    // --- list + queue stats ---------------------------------------------------
    $list = json_decode($get('push_messages', $admin)['body'], true);
    $listRow = null;
    foreach (($list['data']['rows'] ?? []) as $row) {
        if ((int) $row['id'] === $msgId) { $listRow = $row; }
    }
    assertTrue($listRow !== null && (int) $listRow['sent'] === 0 && (int) $listRow['failed'] === 0 && (int) $listRow['queued'] === 1,
        'push_messages list carries sent/failed/queued stats');
    $queue = json_decode($get('push_queue_list', $admin)['body'], true);
    assertTrue(($queue['data']['pending'] ?? 0) === 4, 'push_queue_list counts the pending rows');

    // --- delete ----------------------------------------------------------------
    $del = $post('push_message_delete', $admin, ['id' => $msgId]);
    assertTrue(($del['code'] ?? 0) === 200 && (int) $pdo->query("SELECT COUNT(*) FROM push_messages WHERE id = $msgId")->fetchColumn() === 0,
        'push_message_delete removes the message');
    assertTrue((int) $pdo->query("SELECT COUNT(*) FROM push_queue WHERE message_id = $msgId")->fetchColumn() === 1,
        'queue rows survive the message delete (delivery history)');

    // --- RBAC: push 'none' cannot read, 'read' cannot write -------------------
    $none = $login('push_none');
    assertTrue(($get('push_messages', $none)['code'] ?? 0) === 403, "push access 'none' blocks push_messages");
    assertTrue(($get('push_queue_list', $none)['code'] ?? 0) === 403, "push access 'none' blocks push_queue_list");
    assertTrue(($post('push_send_now', $none, ['message_id' => $msgDepId])['code'] ?? 0) === 403, "push access 'none' blocks push_send_now");
    $read = $login('push_read');
    assertTrue(($get('push_messages', $read)['code'] ?? 0) === 200, "push access 'read' allows push_messages");
    assertTrue(($get('push_queue_list', $read)['code'] ?? 0) === 200, "push access 'read' allows push_queue_list");
    assertTrue(($post('push_message_save', $read, ['title' => 'X', 'text' => 'Y', 'active' => 1])['code'] ?? 0) === 403,
        "push access 'read' blocks push_message_save");
    assertTrue(($post('push_message_delete', $read, ['id' => $msgDepId])['code'] ?? 0) === 403,
        "push access 'read' blocks push_message_delete");
    assertTrue(($post('push_test_send', $none, ['subscription_id' => 1])['code'] ?? 0) === 403,
        "push access 'none' blocks push_test_send");
    assertTrue(($post('push_test_send', $read, ['subscription_id' => 1])['code'] ?? 0) === 403,
        "push access 'read' blocks push_test_send");
} catch (Throwable $e) {
    fwrite(STDERR, 'EXCEPTION: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $testPassed = false;
} finally {
    $harness->stop();
}

echo $testPassed ? "\nALL TESTS PASSED\n" : "\nSOME TESTS FAILED\n";
exit($testPassed ? 0 : 1);
