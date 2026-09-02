<?php
// tests/push_cron_test.php
//
// Phase-4 delivery worker e2e (cli/push_cron.php) on the sandboxed /tmp copy
// with a scratch SQLite DB — the working orbitra_db.sqlite is never touched
// and nothing leaves the machine (the only "network" is loopback):
//   - dead subscription → queue 'failed' with no HTTP attempt;
//   - endpoint refusing connections (127.0.0.1:1) → 'pending' again with
//     attempts+1 and a future run_at (requeue semantics);
//   - a loopback responder answering 429 (with Retry-After) and 410:
//     rate-limit rows requeue with the code logged, 410 ages the
//     subscription out (is_active=0) and lands in push_sends;
//   - the trigger scan enqueues install/lead/sale event messages from clicks
//     and conversions and stores its rowid cursor in settings; a second run
//     dedups;
//   - a poison row dies after the attempt budget instead of looping forever.
//
// Run: php tests/push_cron_test.php

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/tests/lib/http.php';
require_once $repoRoot . '/core/PushBase.php';

$testPassed = true;
function assertTrue($condition, string $message): bool {
    global $testPassed;
    if (!$condition) { fwrite(STDERR, "FAILED: $message\n"); $testPassed = false; }
    else { echo "✓ $message\n"; }
    return (bool) $condition;
}

// Minimal loopback push service: /gone → 410, /limited → 429 + Retry-After.
$srv = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
assertTrue($srv !== false, 'loopback responder socket opened');
stream_set_blocking($srv, false);
$sockName = stream_socket_get_name($srv, false);
$loopPort = (int) substr($sockName, strrpos($sockName, ':') + 1);
$answerLoop = static function () use ($srv) {
    while (($conn = @stream_socket_accept($srv, 0)) !== false) {
        stream_set_blocking($conn, false);
        usleep(50000); // let curl finish writing the request headers
        $req = (string) @fread($conn, 8192);
        $line = strtok($req, "\r\n") ?: '';
        $path = '';
        if (preg_match('#^[A-Z]+\s+(\S+)#', $line, $m)) {
            $path = parse_url($m[1], PHP_URL_PATH) ?: '';
        }
        if ($path === '/limited') {
            $resp = "HTTP/1.1 429 Too Many Requests\r\nRetry-After: 5\r\nContent-Length: 0\r\n\r\n";
        } else {
            $resp = "HTTP/1.1 410 Gone\r\nContent-Length: 0\r\n\r\n";
        }
        @fwrite($conn, $resp);
        @fclose($conn);
    }
};

$harness = new OrbitraTestHarness($repoRoot);
$harness->start();
$madeDirs = [];

try {
    $pdo = $harness->getPdo();
    $workDir = $harness->getWorkingDir();
    assertTrue(is_dir($workDir), 'sandbox working dir exists');

    // The worker lives in cli/ — the harness does not copy that directory.
    @mkdir($workDir . '/cli', 0775, true);
    copy($repoRoot . '/cli/push_cron.php', $workDir . '/cli/push_cron.php');
    $cron = $workDir . '/cli/push_cron.php';
    $runCron = static function () use ($answerLoop, $cron, $workDir) {
        $proc = proc_open(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cron) . ' --quiet 2>&1',
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workDir
        );
        assertTrue(is_resource($proc), 'cron process started');
        do {
            $answerLoop();
            usleep(20000);
            $st = proc_get_status($proc);
        } while ($st['running']);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return ['code' => $st['exitcode'], 'out' => $out];
    };

    // VAPID keys are required for any send attempt.
    PushBase::storeKeys($pdo, PushBase::generateKeys());

    // Fixtures: one campaign, clicks with events, subscriptions.
    $campaignId = random_int(10000, 99999);
    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived) VALUES (?, ?, ?, ?, 'active', 0)")
        ->execute([$campaignId, 'Push Cron Campaign', 'pushcron' . $campaignId, 'pushcron_' . $campaignId]);
    $addClick = static function (string $cid, bool $install = false) use ($pdo, $campaignId) {
        $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, pwa_install_at) VALUES (?, ?, '1.2.3.4', 'UA', ?)")
            ->execute([$cid, $campaignId, $install ? date('Y-m-d H:i:s') : null]);
    };
    $addClick('cron-dead');
    $addClick('cron-refuse');
    $addClick('cron-gone');
    $addClick('cron-limited');
    $addClick('cron-inst', true);
    $addClick('cron-lead');
    $addClick('cron-sale');
    $pdo->prepare("INSERT INTO conversions (click_id, status) VALUES ('cron-lead', 'lead')")->execute();
    $pdo->prepare("INSERT INTO conversions (click_id, status) VALUES ('cron-sale', 'sale')")->execute();

    // Real client P-256 material: PushSender must build a valid aes128gcm
    // record (ECDH + HKDF) BEFORE the HTTP call, so dummy p256dh/auth strings
    // would abort every send with "encrypt failed" and never reach the wire.
    $b64u = static function (string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    };
    $makeClientKeys = static function () use ($b64u): array {
        $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $d = openssl_pkey_get_details($res);
        if ($d === false || !isset($d['ec']['x'], $d['ec']['y'])) {
            throw new RuntimeException('client EC keygen failed');
        }
        return ['p256dh' => $b64u("\x04" . $d['ec']['x'] . $d['ec']['y']), 'auth' => $b64u(random_bytes(16))];
    };

    $addSub = static function (string $cid, int $active = 1, ?string $endpoint = null) use ($pdo, $makeClientKeys) {
        $keys = $makeClientKeys();
        $pdo->prepare("INSERT INTO push_subscriptions (click_id, endpoint, p256dh, auth, is_active) VALUES (?, ?, ?, ?, ?)")
            ->execute([$cid, $endpoint ?? ('https://ep/' . $cid), $keys['p256dh'], $keys['auth'], $active]);
        return (int) $pdo->lastInsertId();
    };
    $deadSubId = $addSub('cron-dead', 0);
    $refuseSubId = $addSub('cron-refuse', 1, 'http://127.0.0.1:1/refused');
    $limitedSubId = $addSub('cron-limited', 1, "http://127.0.0.1:$loopPort/limited");
    $goneSubId = $addSub('cron-gone', 1, "http://127.0.0.1:$loopPort/gone");

    $addMessage = static function (array $over = []) use ($pdo) {
        $row = array_merge(['title' => 'T', 'text' => 'B', 'kind' => 'manual', 'event' => null,
            'delay_seconds' => 0, 'segment' => 'all', 'active' => 1], $over);
        $pdo->prepare("INSERT INTO push_messages (title, text, icon_url, link_url, kind, event, delay_seconds, segment, active)
                       VALUES (?, ?, '', '', ?, ?, ?, ?, ?)")
            ->execute([$row['title'], $row['text'], $row['kind'], $row['event'], $row['delay_seconds'], $row['segment'], $row['active']]);
        $row['id'] = (int) $pdo->lastInsertId();
        return $row;
    };
    $msgDead = $addMessage();
    $msgRefuse = $addMessage();
    $msgLimited = $addMessage();
    $msgGone = $addMessage();
    // Event messages exist from the start so the trigger scan wires them up.
    $msgInstall = $addMessage(['kind' => 'event', 'event' => 'install', 'delay_seconds' => 0]);
    $msgLead = $addMessage(['kind' => 'event', 'event' => 'lead', 'delay_seconds' => 120]);
    $msgSale = $addMessage(['kind' => 'event', 'event' => 'sale', 'delay_seconds' => 0]);
    $addSub('cron-inst');
    $addSub('cron-lead');
    $addSub('cron-sale');

    $queueRow = static function (int $messageId, int $subId) use ($pdo): array {
        $stmt = $pdo->prepare("SELECT * FROM push_queue WHERE message_id = ? AND subscription_id = ?");
        $stmt->execute([$messageId, $subId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $enqueue = static function (int $messageId, int $subId) use ($pdo) {
        $pdo->prepare("INSERT INTO push_queue (message_id, subscription_id, run_at, status) VALUES (?, ?, datetime('now'), 'pending')")
            ->execute([$messageId, $subId]);
    };
    $enqueue($msgDead['id'], $deadSubId);
    $enqueue($msgRefuse['id'], $refuseSubId);
    $enqueue($msgLimited['id'], $limitedSubId);
    $enqueue($msgGone['id'], $goneSubId);

    // --- run 1: dead fails with no HTTP; refused requeues; 429 requeues; ----
    // --- 410 ages the subscription out; the trigger scan wires events ------
    $result = $runCron();
    assertTrue($result['code'] === 0, 'cron run 1 exits cleanly');

    $dead = $queueRow($msgDead['id'], $deadSubId);
    assertTrue(($dead['status'] ?? '') === 'failed', 'dead subscription row fails without a send attempt');
    assertTrue((int) $pdo->query("SELECT COUNT(*) FROM push_sends WHERE subscription_id = $deadSubId")->fetchColumn() === 0,
        'no push_sends row for a dead subscription');

    $refused = $queueRow($msgRefuse['id'], $refuseSubId);
    assertTrue(($refused['status'] ?? '') === 'pending' && (int) $refused['attempts'] === 1,
        'refused endpoint: row stays pending with attempts+1');
    assertTrue((string) $refused['run_at'] > date('Y-m-d H:i:s'), 'refused endpoint: run_at moved into the future (backoff)');
    assertTrue((int) $refused['last_code'] === 0, 'refused endpoint records code 0 (transport failure)');
    assertTrue((int) $pdo->query("SELECT COUNT(*) FROM push_sends WHERE subscription_id = $refuseSubId")->fetchColumn() === 0,
        'no push_sends row for a requeued row');

    $limited = $queueRow($msgLimited['id'], $limitedSubId);
    assertTrue(($limited['status'] ?? '') === 'pending' && (int) $limited['last_code'] === 429,
        '429 row requeued with the rate-limit code logged');
    assertTrue((string) $limited['run_at'] > date('Y-m-d H:i:s', time() + 60),
        '429 Retry-After honored with at least the backoff floor');

    $gone = $queueRow($msgGone['id'], $goneSubId);
    assertTrue(($gone['status'] ?? '') === 'failed', '410 endpoint row fails');
    assertTrue((int) $pdo->query("SELECT is_active FROM push_subscriptions WHERE id = $goneSubId")->fetchColumn() === 0,
        '410 endpoint ages the subscription out (is_active=0)');
    $sendsGone = $pdo->query("SELECT ok, response_code FROM push_sends WHERE subscription_id = $goneSubId")->fetch(PDO::FETCH_ASSOC);
    assertTrue($sendsGone !== false && (int) $sendsGone['ok'] === 0 && (int) $sendsGone['response_code'] === 410,
        'push_sends logs the 410 delivery failure');

    // Trigger scan results.
    $instRow = $pdo->query("SELECT q.id FROM push_queue q JOIN push_subscriptions s ON s.id = q.subscription_id
                            WHERE q.message_id = {$msgInstall['id']} AND s.click_id = 'cron-inst'")->fetchColumn();
    assertTrue((int) $instRow > 0, 'install event enqueued for the install click subscriber');
    $leadRunAt = $pdo->query("SELECT q.run_at FROM push_queue q JOIN push_subscriptions s ON s.id = q.subscription_id
                              WHERE q.message_id = {$msgLead['id']} AND s.click_id = 'cron-lead'")->fetchColumn();
    assertTrue((string) $leadRunAt > date('Y-m-d H:i:s', time() + 60), 'lead event queued with its 120s delay');
    $saleRow = $pdo->query("SELECT q.id FROM push_queue q JOIN push_subscriptions s ON s.id = q.subscription_id
                            WHERE q.message_id = {$msgSale['id']} AND s.click_id = 'cron-sale'")->fetchColumn();
    assertTrue((int) $saleRow > 0, 'sale event enqueued for the sale conversion');
    $cursor = json_decode((string) $pdo->query("SELECT value FROM settings WHERE key = 'push_trigger_cursor'")->fetchColumn(), true);
    assertTrue(is_array($cursor) && (int) ($cursor['clicks'] ?? 0) > 0 && (int) ($cursor['conversions'] ?? 0) > 0,
        'trigger cursor stored in settings for both tables');

    // Second run: no duplicates for the same events.
    $before = (int) $pdo->query("SELECT COUNT(*) FROM push_queue")->fetchColumn();
    $runCron();
    $after = (int) $pdo->query("SELECT COUNT(*) FROM push_queue")->fetchColumn();
    assertTrue($before === $after, 'second cron run enqueues nothing new (cursor + dedup)');

    // --- poison row: keep making the refused row due until attempts die -----
    $status = 'pending';
    for ($i = 0; $i < 6 && $status === 'pending'; $i++) {
        $pdo->exec("UPDATE push_queue SET run_at = datetime('now') WHERE message_id = {$msgRefuse['id']} AND subscription_id = $refuseSubId");
        $runCron();
        $status = (string) $queueRow($msgRefuse['id'], $refuseSubId)['status'];
    }
    assertTrue($status === 'failed', 'poison row dies after the attempt budget (never loops forever)');
    assertTrue((int) $queueRow($msgRefuse['id'], $refuseSubId)['attempts'] <= 6, 'attempt counter stayed bounded');
} catch (Throwable $e) {
    fwrite(STDERR, 'EXCEPTION: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $testPassed = false;
} finally {
    if (isset($srv) && is_resource($srv)) {
        @fclose($srv);
    }
    $harness->stop();
    foreach ($madeDirs as $d) {
        if (!is_dir($d)) continue;
        foreach (array_reverse(glob($d . '/*') ?: []) as $item) { is_dir($item) ? @rmdir($item) : @unlink($item); }
        @rmdir($d);
    }
}

echo $testPassed ? "\nALL TESTS PASSED\n" : "\nSOME TESTS FAILED\n";
exit($testPassed ? 0 : 1);
