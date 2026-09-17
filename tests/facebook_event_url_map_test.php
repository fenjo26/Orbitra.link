<?php
// Actual postback HTTP route, disposable SQLite and outbox assertions only.
// No Meta/offer requests. Optional argv[1] runs against an unmodified checkout.
require_once __DIR__ . '/lib/http.php';

$checks = 0;
$failures = [];
$check = static function (string $label, bool $ok) use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) { $failures[] = $label; echo "FAIL $label\n"; }
};
$harness = new OrbitraTestHarness($argv[1] ?? dirname(__DIR__));
try {
    $harness->start();
    $data = $harness->seedTestData();
    $db = $harness->getPdo();
    $db->exec('PRAGMA user_version=52');
    $db->exec("INSERT OR REPLACE INTO settings(key,value) VALUES ('telegram_notify_conversions','0'),('currency','USD')");
    $db->exec("INSERT INTO domains(name) VALUES ('campaign.example')");
    $domainId = (int) $db->lastInsertId();
    $db->prepare("UPDATE campaigns SET domain_id=?, alias='checkout-start' WHERE id=?")->execute([$domainId, $data['campaign_id']]);
    $db->exec("INSERT INTO affiliate_networks(name,offer_params) VALUES ('Synthetic network','cid={subid}&offer={offer_id}&tag={sub_id_1}')");
    $networkId = (int) $db->lastInsertId();
    $db->prepare("UPDATE offers SET url='https://offer-a.example/order?fixed=A',affiliate_network_id=? WHERE id=?")
        ->execute([$networkId, $data['offer_id']]);
    $db->prepare("INSERT INTO offers(name,url,affiliate_network_id,is_local) VALUES ('Other selected offer','https://offer-b.example/order?fixed=B',?,0)")
        ->execute([$networkId]);
    $otherOfferId = (int) $db->lastInsertId();
    $otherClick = 'other-click-url-map';
    $db->prepare("INSERT INTO clicks(id,campaign_id,offer_id,ip,user_agent,country_code,parameters_json)
        VALUES (?,?,?,'2001:db8::2','Synthetic browser','US',?)")
        ->execute([$otherClick, $data['campaign_id'], $otherOfferId, json_encode(['sub_id_1' => 'Beta Case'])]);
    $db->prepare('UPDATE clicks SET parameters_json=? WHERE id=?')
        ->execute([json_encode(['sub_id_1' => 'Alpha Case', 'fbclid' => 'AdCase']), $data['click_id']]);
    $map = json_encode(['InitiateCheckout' => '{campaign_url}', 'Purchase' => '{offer_url}']);
    $db->prepare("INSERT INTO campaign_pixels(campaign_id,type,pixel_id,token,is_active,mapping_json,event_source_url)
        VALUES (?,'facebook','123456','synthetic-token',1,?,?)")
        ->execute([$data['campaign_id'], json_encode(['lead' => 'InitiateCheckout', 'sale' => 'Purchase']), $map]);
    $pixelId = (int) $db->lastInsertId();
    $configure = static function (string $value) use ($db, $pixelId): void {
        $db->prepare('UPDATE campaign_pixels SET event_source_url=? WHERE id=?')->execute([$value, $pixelId]);
    };
    $send = static function (string $click, string $status, string $tid, array $extra = []) use ($harness, $data): array {
        return $harness->get('/' . $data['postback_key'] . '/postback?' . http_build_query(
            ['subid' => $click, 'status' => $status, 'tid' => $tid, 'payout' => 10, 'currency' => 'USD'] + $extra
        ));
    };
    $queued = static function (string $tid) use ($db): array {
        $stmt = $db->prepare('SELECT q.* FROM s2s_postbacks_log q JOIN conversions c ON c.id=q.conversion_id WHERE c.tid=? AND q.postback_id IS NULL ORDER BY q.id');
        $stmt->execute([$tid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $rows;
    };
    $event = static function (string $tid) use ($queued): array {
        $rows = $queued($tid);
        return json_decode($rows[0]['payload_json'] ?? '{}', true)['data'][0] ?? [];
    };
    $lead = $send($data['click_id'], 'lead', 'start');
    $check('mapped InitiateCheckout uses campaign URL after actual status mapping', $lead['code'] === 200
        && $event('start')['event_name'] === 'InitiateCheckout'
        && ($event('start')['event_source_url'] ?? '') === 'https://campaign.example/checkout-start');
    $saleA = $send($data['click_id'], 'sale', 'sale-a', ['offer_id' => $otherOfferId]);
    $saleB = $send($otherClick, 'sale', 'sale-b');
    $check('first Purchase uses persisted click offer, ignoring postback offer_id override', $saleA['code'] === 200
        && ($event('sale-a')['event_source_url'] ?? '') === 'https://offer-a.example/order?fixed=A&cid=' . $data['click_id']
            . '&offer=' . $data['offer_id'] . '&tag=Alpha+Case');
    $check('another offer in the same campaign resolves independently with network macros', $saleB['code'] === 200
        && ($event('sale-b')['event_source_url'] ?? '') === 'https://offer-b.example/order?fixed=B&cid=' . $otherClick
            . '&offer=' . $otherOfferId . '&tag=Beta+Case');
    $check('one pixel still queues one Purchase per event with original identity', count($queued('sale-a')) === 1
        && count($queued('sale-b')) === 1 && $event('sale-a')['event_name'] === 'Purchase'
        && $event('sale-a')['event_id'] === $data['click_id'] . '_sale_sale-a');
    $configure('{"Purchase":"\u007boffer_url\u007d"}');
    $send($otherClick, 'sale', 'escaped-macro');
    $check('valid JSON Unicode escapes still trigger offer resolution',
        ($event('escaped-macro')['event_source_url'] ?? '') === ($event('sale-b')['event_source_url'] ?? 'missing'));

    $snapshot = $queued('sale-a')[0]['payload_json'] ?? '';
    $db->prepare("UPDATE offers SET url='https://changed.example/later' WHERE id=?")->execute([$data['offer_id']]);
    $configure('{"Purchase":"https://changed-config.example/later"}');
    $repeat = $send($data['click_id'], 'sale', 'sale-a');
    $check('configuration changes and retries never rewrite/replay a recorded payload', $repeat['code'] === 200
        && count($queued('sale-a')) === 1 && ($queued('sale-a')[0]['payload_json'] ?? '') === $snapshot);

    $configure('{"InitiateCheckout":"{campaign_url}"}');
    $send($otherClick, 'sale', 'absent-key', ['event_source_url' => 'https://actual.example/purchase']);
    $check('absent event key permits explicit postback event URL',
        ($event('absent-key')['event_source_url'] ?? '') === 'https://actual.example/purchase');
    foreach (['empty' => '{"Purchase":""}', 'invalid' => '{"Purchase":"javascript:alert(1)"}',
        'null' => '{"Purchase":null}', 'malformed' => '{"Purchase":'] as $label => $value) {
        $configure($value);
        $result = $send($otherClick, 'sale', $label, ['event_source_url' => 'https://wrong-fallback.example/']);
        $check($label . ' map still records Purchase but never falls back to another URL', $result['code'] === 200
            && count($queued($label)) === 1 && ($event($label)['event_name'] ?? '') === 'Purchase'
            && !isset($event($label)['event_source_url']));
    }
    $check('missing event URL retains diagnostic without suppressing delivery',
        (int) $db->query("SELECT COUNT(*) FROM system_logs WHERE message LIKE 'Facebook CAPI: missing or invalid event_source_url.%'")->fetchColumn() >= 4);

    $configure($map);
    $db->prepare('UPDATE clicks SET offer_id=NULL WHERE id=?')->execute([$otherClick]);
    $send($otherClick, 'sale', 'missing-offer');
    $check('missing offer never borrows another campaign offer or campaign URL',
        ($event('missing-offer')['event_name'] ?? '') === 'Purchase' && !isset($event('missing-offer')['event_source_url']));
    $db->prepare('UPDATE clicks SET offer_id=? WHERE id=?')->execute([$otherOfferId, $otherClick]);
    foreach (['relative' => '//offer.example/order', 'invalid-offer' => 'https://user:secret@offer.example/order'] as $label => $url) {
        $db->prepare('UPDATE offers SET url=? WHERE id=?')->execute([$url, $otherOfferId]);
        $send($otherClick, 'sale', $label);
        $check($label . ' is not guessed into an absolute conversion page',
            ($event($label)['event_name'] ?? '') === 'Purchase' && !isset($event($label)['event_source_url']));
    }
    $db->prepare("UPDATE offers SET is_local=1,url='https://configured-but-not-served.example/' WHERE id=?")->execute([$otherOfferId]);
    $send($otherClick, 'sale', 'local-offer');
    $check('local offer does not claim unused offers.url as its served page',
        ($event('local-offer')['event_name'] ?? '') === 'Purchase' && !isset($event('local-offer')['event_source_url']));
    $configure('https://static.example/thanks/{clickid}');
    $send($otherClick, 'sale', 'legacy-string');
    $check('legacy literal URL and clickid macro remain unchanged',
        ($event('legacy-string')['event_source_url'] ?? '') === 'https://static.example/thanks/' . $otherClick);
    $check('configuration remains one pixel row without changing its event mapping',
        (int) $db->query('SELECT COUNT(*) FROM campaign_pixels')->fetchColumn() === 1);
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
} finally {
    $harness->stop();
}
echo 'Facebook event URL map: ' . ($checks - count($failures)) . "/$checks passed\n";
exit($failures ? 1 : 0);
