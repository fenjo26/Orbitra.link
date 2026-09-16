<?php
// Real HTTP regression: best-effort diagnostics/attribution must not swallow an
// automatic SQLite rollback and let subsequent CAPI writes run in autocommit.
// All data is disposable; no worker, Meta, TikTok or Telegram transport runs.
require_once __DIR__ . '/lib/http.php';

$failures = 0;
$checks = 0;
$check = static function (string $name, bool $ok) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) { $failures++; }
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . PHP_EOL;
};
$harness = new OrbitraTestHarness($argv[1] ?? dirname(__DIR__));
try {
    $harness->start();
    $seed = $harness->seedTestData();
    $db = $harness->getPdo();
    $db->exec('PRAGMA busy_timeout=1000');
    foreach (['currency'=>'USD', 'telegram_notify_conversions'=>'0'] as $key=>$value) {
        $db->prepare('INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)')->execute([$key,$value]);
    }
    $insertPixel = $db->prepare('INSERT INTO campaign_pixels(campaign_id,type,pixel_id,token,is_active,mapping_json,event_source_url) VALUES(?,?,?,?,1,?,?)');
    foreach (['meta_warning', 'meta_skipped', 'tiktok_skipped', 'attribution'] as $phase) {
        foreach (['ROLLBACK', 'ABORT', 'ROLLBACK_LOCK'] as $failureMode) {
            $label = $phase . '/' . $failureMode;
            $raiseMode = $failureMode === 'ROLLBACK_LOCK' ? 'ROLLBACK' : $failureMode;
            $failureMessage = $failureMode === 'ROLLBACK_LOCK' ? 'database is locked' : 'fixture automatic transaction failure';
            $clickId = 'auto-rollback-' . bin2hex(random_bytes(8));
            $tid = 'same-existing-order';
            $db->prepare("INSERT INTO clicks(id,campaign_id,offer_id,ip,user_agent,is_conversion,revenue)
                VALUES(?,?,?,'192.0.2.1','Synthetic browser',1,0)")
                ->execute([$clickId,$seed['campaign_id'],$seed['offer_id']]);
            // An existing row makes leaked queue inserts satisfy their FK even
            // though the attempted lead -> sale update has already rolled back.
            $db->prepare("INSERT INTO conversions(click_id,tid,status,original_status,payout,currency)
                VALUES(?,?,'lead','lead',0,'USD')")->execute([$clickId,$tid]);
            $conversionId = (int) $db->lastInsertId();
            $db->exec('DELETE FROM campaign_pixels');
            if ($phase === 'meta_warning') {
                $insertPixel->execute([$seed['campaign_id'],'facebook','first-pixel','fixture-token','{"sale":"Purchase"}','']);
            } elseif ($phase === 'meta_skipped' || $phase === 'tiktok_skipped') {
                $insertPixel->execute([$seed['campaign_id'],$phase === 'meta_skipped' ? 'facebook' : 'tiktok',
                    'first-pixel','fixture-token','{"sale":""}','https://checkout.example/complete']);
            }
            // A later eligible pixel must not write after an earlier optional
            // diagnostic has rolled the entire conversion transaction back.
            $insertPixel->execute([$seed['campaign_id'],'facebook','second-pixel','fixture-token','{"sale":"Purchase"}','https://checkout.example/complete']);
            if ($phase === 'attribution') {
                $db->exec("CREATE TRIGGER optional_failure BEFORE UPDATE OF campaign_id ON conversions
                    BEGIN SELECT RAISE($raiseMode,'$failureMessage'); END");
            } else {
                $prefix = match ($phase) {
                    'meta_warning' => 'Facebook CAPI: missing or invalid event_source_url.%',
                    'meta_skipped' => 'Facebook CAPI: status %',
                    default => 'TikTok Events API: status %',
                };
                $db->exec('CREATE TRIGGER optional_failure BEFORE INSERT ON system_logs WHEN NEW.message LIKE '
                    . $db->quote($prefix) . " BEGIN SELECT RAISE($raiseMode,'$failureMessage'); END");
            }
            $response = $harness->get('/' . $seed['postback_key'] . '/postback?' . http_build_query([
                'subid'=>$clickId,'tid'=>$tid,'status'=>'sale','payout'=>10,'currency'=>'USD',
            ]));
            $db->exec('DROP TRIGGER optional_failure');
            $conversion = $db->query('SELECT status,payout FROM conversions WHERE id=' . $conversionId)->fetch(PDO::FETCH_ASSOC);
            $click = $db->query('SELECT is_conversion,revenue FROM clicks WHERE id=' . $db->quote($clickId))->fetch(PDO::FETCH_ASSOC);
            $queueCount = (int) $db->query('SELECT COUNT(*) FROM s2s_postbacks_log WHERE conversion_id=' . $conversionId)->fetchColumn();
            $rolledBack = $failureMode !== 'ABORT';
            $expectedStatus = $failureMode === 'ROLLBACK_LOCK' ? 503 : ($rolledBack ? 500 : 200);
            $check($label . ' returns the appropriate HTTP status', $response['code'] === $expectedStatus);
            $check($label . ' preserves atomic conversion state', $conversion['status'] === ($rolledBack ? 'lead' : 'sale')
                && (float) $conversion['payout'] === ($rolledBack ? 0.0 : 10.0));
            $check($label . ' preserves atomic click totals', (int) $click['is_conversion'] === 1
                && (float) $click['revenue'] === ($rolledBack ? 0.0 : 10.0));
            $check($label . ' has no residual autocommit event', $queueCount === ($rolledBack ? 0 : ($phase === 'meta_warning' ? 2 : 1)));
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    $failures++;
} finally {
    $harness->stop();
}

// The provider classes are also public standalone enqueuers: a broken log
// outside a caller-owned transaction must remain best-effort, not require BEGIN.
require_once __DIR__ . '/../core/FacebookConversions.php';
require_once __DIR__ . '/../core/TikTokConversions.php';
require_once __DIR__ . '/../core/PostbackDelivery.php';
foreach (['FacebookConversions', 'TikTokConversions'] as $provider) {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE system_logs(level TEXT,message TEXT,context TEXT)');
    $db->exec('CREATE TABLE s2s_postbacks_log(conversion_id INTEGER,url TEXT,method TEXT,status TEXT,attempts INTEGER,next_retry_at TEXT,postback_id INTEGER,payload_json TEXT,content_type TEXT,proxy_url TEXT,headers_json TEXT,updated_at TEXT)');
    $db->exec("CREATE TRIGGER optional_failure BEFORE INSERT ON system_logs BEGIN SELECT RAISE(ROLLBACK,'original diagnostic failure'); END");
    $pixel = ['pixel_id'=>'fixture-pixel','token'=>'fixture-token'];
    $context = ['status'=>'sale','event_id'=>'fixture_sale','click_params'=>[]];
    $check($provider . ' standalone mapped enqueue remains compatible', $provider::enqueue($db,$pixel,[],$context,1)
        && (int) $db->query('SELECT COUNT(*) FROM s2s_postbacks_log')->fetchColumn() === 1);
    $check($provider . ' standalone skipped diagnostic remains best-effort',
        !$provider::enqueue($db,$pixel,[],['status'=>'rejected'],1));
    $db->exec('BEGIN IMMEDIATE');
    $threwOriginal = false;
    try { $provider::enqueue($db,$pixel,[],['status'=>'rejected'],1); }
    catch (PDOException $error) { $threwOriginal = str_contains($error->getMessage(),'original diagnostic failure'); }
    $check($provider . ' preserves the original automatic-rollback exception', $threwOriginal && !$db->inTransaction());
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$threw = false;
try { orbitraPostbackEnqueueCapi($db, [], [], [], 1); }
catch (RuntimeException $error) { $threw = str_contains($error->getMessage(), 'Postback transaction ended'); }
$check('postback-only enqueue requires the promised active transaction', $threw);
$cause = new PDOException('original lookup failure');
$preserved = false;
try { orbitraPostbackAssertTransactionActive($db, $cause); }
catch (PDOException $error) { $preserved = $error === $cause; }
$check('lookup guard preserves the original exception object', $preserved);
$threw = false;
try {
    orbitraPostbackTransaction($db, static function () use ($db): array {
        $db->exec('ROLLBACK');
        return [];
    });
} catch (RuntimeException $error) { $threw = str_contains($error->getMessage(), 'Postback transaction ended'); }
$check('transaction wrapper rejects a lost transaction before COMMIT', $threw && !$db->inTransaction());
echo "Checks: $checks; failures: $failures\n";
exit($failures === 0 ? 0 : 1);
