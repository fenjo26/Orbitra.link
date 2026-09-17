<?php
// Real HTTP postbacks against the harness's disposable SQLite database. Meta and
// Telegram are never called; the notification boundary simulates another writer.
// Optional argv[1] selects an unmodified checkout to reproduce the regressions.
require_once __DIR__ . '/lib/http.php';

$failures = 0;
function deliveryCheck(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . PHP_EOL;
    if (!$ok) $failures++;
}

$harness = new OrbitraTestHarness($argv[1] ?? dirname(__DIR__));
try {
    $harness->start();
    $data = $harness->seedTestData();
    $pdo = $harness->getPdo();
    $pdo->exec('PRAGMA busy_timeout=1000');
    $pdo->exec('PRAGMA user_version=51');
    $campaign = $data['campaign_id'];
    $clickId = $data['click_id'];
    $pdo->prepare("INSERT INTO campaign_pixels (campaign_id, type, pixel_id, token, is_active, mapping_json, event_source_url)
        VALUES (?, 'facebook', '123456', 'test-only-token', 1, ?, 'https://checkout.example/complete')")
        ->execute([$campaign, json_encode(['lead' => 'InitiateCheckout', 'sale' => 'Purchase'])]);
    $pixelId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE clicks SET parameters_json=? WHERE id=?')->execute([
        json_encode(['fbclid' => 'synthetic-click', 'fbp' => 'fb.1.1700000000000.123']), $clickId,
    ]);
    foreach (['currency' => 'USD', 'telegram_notify_conversions' => '0',
              'fx_rates_json' => '{"USD":1,"BRL":5}', 'fx_rates_updated_at' => (string) time()] as $key => $value) {
        $pdo->prepare('INSERT OR REPLACE INTO settings (key,value) VALUES (?,?)')->execute([$key, $value]);
    }
    $send = function (array $params = []) use ($harness, $data, $clickId): array {
        return $harness->get('/' . $data['postback_key'] . '/postback?' . http_build_query(
            $params + ['subid' => $clickId, 'status' => 'lead', 'payout' => 10, 'currency' => 'USD']
        ));
    };
    $queue = function () use ($pdo): array {
        return $pdo->query('SELECT * FROM s2s_postbacks_log WHERE postback_id IS NULL ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    };
    $conversion = function (string $id) use ($pdo) {
        $stmt = $pdo->prepare('SELECT * FROM conversions WHERE click_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    };

    // Failure at the real outbox INSERT must not acknowledge a partial commit.
    $pdo->exec("CREATE TRIGGER capi_write_failure BEFORE INSERT ON s2s_postbacks_log
        BEGIN SELECT RAISE(ABORT, 'synthetic queue write failure'); END");
    $response = $send();
    deliveryCheck('queue write failure returns 500', $response['code'] === 500);
    deliveryCheck('failed outbox insert rolls conversion back', $conversion($clickId) === false);
    deliveryCheck('failed outbox insert rolls click counters back', (int) $pdo->query('SELECT is_conversion FROM clicks LIMIT 1')->fetchColumn() === 0);
    deliveryCheck('failed attempt has no queued event', count($queue()) === 0);
    $response = $harness->get('/pixel.gif?' . http_build_query([
        'action' => 'conversion', 'subid' => $clickId, 'status' => 'lead', 'payout' => 10, 'currency' => 'USD',
    ]));
    deliveryCheck('pixel route preserves server failure while returning a GIF', $response['code'] === 500
        && in_array(substr($response['body'], 0, 6), ['GIF87a', 'GIF89a'], true));
    deliveryCheck('pixel route failure has no partial conversion', $conversion($clickId) === false && count($queue()) === 0);
    $response = $send(['currency' => 'BRL']);
    $failedFxParams = json_decode($pdo->query('SELECT parameters_json FROM clicks LIMIT 1')->fetchColumn(), true);
    deliveryCheck('FX audit rolls back with failed outbox and keeps browser identifiers', $response['code'] === 500
        && !isset($failedFxParams['fx_orig_currency']) && ($failedFxParams['fbp'] ?? '') === 'fb.1.1700000000000.123');
    $pdo->exec('DROP TRIGGER capi_write_failure');

    $pdo->exec("CREATE TRIGGER capi_lock_failure BEFORE INSERT ON s2s_postbacks_log
        BEGIN SELECT RAISE(ABORT, 'database is locked'); END");
    $response = $send();
    deliveryCheck('exhausted contention returns 503 without a partial commit', $response['code'] === 503
        && $conversion($clickId) === false && count($queue()) === 0);
    $pdo->exec('DROP TRIGGER capi_lock_failure');

    $response = $send();
    deliveryCheck('new postback commits conversion and CAPI', $response['code'] === 200 && $conversion($clickId) && count($queue()) === 1);
    $firstPayload = $queue()[0]['payload_json'];
    $send();
    deliveryCheck('identical postback does not duplicate CAPI', count($queue()) === 1);
    deliveryCheck('duplicate preserves original event time and body', $queue()[0]['payload_json'] === $firstPayload);

    // A token rotation or API-version change does not change the event identity.
    $pdo->prepare("UPDATE campaign_pixels SET token='rotated-test-token', api_version='v24.0' WHERE id=?")->execute([$pixelId]);
    $send();
    deliveryCheck('token/version rotation does not replay the event', count($queue()) === 1);
    $send(['status' => 'sale']);
    deliveryCheck('lead to sale produces the distinct Purchase event', count($queue()) === 2
        && json_decode($queue()[1]['payload_json'], true)['data'][0]['event_name'] === 'Purchase');
    $send(['status' => 'sale', 'tid' => 'order-1']);
    $send(['status' => 'sale', 'tid' => 'order-1']);
    $send(['status' => 'sale', 'tid' => 'order-2']);
    $send(['status' => 'sale', 'tid' => '0']);
    deliveryCheck('distinct transaction IDs, including zero, stay distinct', count($queue()) === 5);
    $response = $send(['status' => 'rejected', 'tid' => 'rejected-order']);
    deliveryCheck('suppressed status still records without CAPI', $response['code'] === 200 && count($queue()) === 5);
    $response = $send(['status' => 'unmapped-network-status', 'tid' => 'unknown-order']);
    deliveryCheck('unmapped status preserves record-first behavior', $response['code'] === 200 && count($queue()) === 5);

    // A second active pixel failing rolls back the first pixel's queue insert too.
    $pdo->prepare("INSERT INTO campaign_pixels (campaign_id,type,pixel_id,token,is_active,event_source_url)
        VALUES (?, 'facebook', '999999', 'second-test-token', 1, 'https://checkout.example/complete')")->execute([$campaign]);
    $pdo->exec("CREATE TRIGGER capi_second_failure BEFORE INSERT ON s2s_postbacks_log
        WHEN NEW.url LIKE '%/999999/events?%'
        BEGIN SELECT RAISE(ABORT, 'second pixel unavailable'); END");
    $before = count($queue());
    $response = $send(['tid' => 'two-pixels']);
    deliveryCheck('multi-pixel failure rolls back every eligible event', $response['code'] === 500 && count($queue()) === $before);
    $pdo->exec('DROP TRIGGER capi_second_failure');
    $response = $send(['tid' => 'two-pixels']);
    deliveryCheck('sender retry records both pixels exactly once', $response['code'] === 200 && count($queue()) === $before + 2);
    $send(['tid' => 'two-pixels']);
    deliveryCheck('repeat postback deduplicates per pixel', count($queue()) === $before + 2);

    // Fault injection at the notification boundary: another connection commits
    // while the original request is alive. This reproduces the stale WAL reader
    // on the old route; notification must now see the already-committed outbox.
    file_put_contents($harness->getWorkingDir() . '/telegram_notify.php', <<<'PHP'
<?php
function notifyConversion($pdo, $clickId, $status, $payout, $campaignId, $currency = 'USD') {
    $writer = new PDO('sqlite:' . __DIR__ . '/orbitra_test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $writer->exec('PRAGMA busy_timeout=100');
    $stmt = $writer->prepare('SELECT COUNT(*) FROM s2s_postbacks_log q JOIN conversions c ON c.id=q.conversion_id WHERE c.click_id=?');
    $stmt->execute([$clickId]);
    $count = $stmt->fetchColumn();
    $stmt->closeCursor();
    $writer->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES ('notification_observed_queue', ?)")->execute([(string) $count]);
    $writer->exec("INSERT OR REPLACE INTO settings (key,value) VALUES ('concurrent_writer_finished', '1')");
}
PHP
    );
    $freshClick = 'concurrency-' . bin2hex(random_bytes(8));
    $pdo->prepare("INSERT INTO clicks (id,campaign_id,ip,user_agent,country_code) VALUES (?,?,'8.8.8.8','Test-Agent','US')")->execute([$freshClick, $campaign]);
    $response = $send(['subid' => $freshClick]);
    deliveryCheck('concurrent writer cannot lose the acknowledged CAPI event', $response['code'] === 200 && count($queue()) === $before + 4);
    deliveryCheck('notification runs after the outbox commit', (int) $pdo->query("SELECT value FROM settings WHERE key='notification_observed_queue'")->fetchColumn() === 2);
    deliveryCheck('notification holds no SQLite write transaction', $pdo->query("SELECT value FROM settings WHERE key='concurrent_writer_finished'")->fetchColumn() === '1');
    $lastIncoming = $pdo->query('SELECT result,matched FROM incoming_postbacks_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    deliveryCheck('concurrent writer leaves a complete incoming outcome', !empty($lastIncoming['result']) && (int) $lastIncoming['matched'] === 1);

    // Existing S2S macro/method contract survives outside the durable CAPI unit.
    $pdo->prepare("INSERT INTO campaign_postbacks (campaign_id,url,method,statuses) VALUES (?,?,'GET','lead')")
        ->execute([$campaign, 'https://8.8.8.8/track?click={subid}&status={status}&value={payout}']);
    $response = $send(['subid' => $freshClick, 'tid' => 's2s-contract']);
    $s2s = $pdo->query('SELECT url,method FROM s2s_postbacks_log WHERE postback_id IS NOT NULL ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    deliveryCheck('S2S postback remains queued with resolved macros', $response['code'] === 200 && is_array($s2s)
        && strpos($s2s['url'], 'click=' . $freshClick) !== false && $s2s['method'] === 'GET');

    // Provider compatibility: TikTok gets the same transactional/idempotent path.
    $pdo->prepare("INSERT INTO campaign_pixels (campaign_id,type,pixel_id,token,is_active) VALUES (?,'tiktok','TT123','tt-test',1)")->execute([$campaign]);
    $before = count($queue());
    $send(['tid' => 'tiktok-contract']);
    $send(['tid' => 'tiktok-contract']);
    deliveryCheck('TikTok and both Meta pixels enqueue once each', count($queue()) === $before + 3);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    $failures++;
} finally {
    $harness->stop();
}
echo "Failures: $failures\n";
exit($failures === 0 ? 0 : 1);
