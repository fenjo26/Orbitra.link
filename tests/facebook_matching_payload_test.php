<?php
// Synthetic payload regression; no configuration, database or network is loaded.
// An optional checkout argument permits running the same assertions on the base.
require_once ($argv[1] ?? dirname(__DIR__)) . '/core/FacebookConversions.php';

$failures = [];
$checks = 0;
$check = static function (string $name, bool $ok) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $name;
    }
};
$click = ['id' => 'click-a', 'ip' => '2001:db8::1', 'user_agent' => 'Synthetic browser',
    'referer' => 'https://www.facebook.com/', 'created_at' => '2026-09-16 10:00:00'];
$ctx = ['event_name' => 'InitiateCheckout', 'event_time' => 1789553400, 'event_id' => 'click-a_lead',
    'click_params' => ['meta_external_id' => 'Visitor-Aa123', 'external_id' => 'legacy-ad-click',
        'landing_page_url' => 'https://landing.example/product'],
    'extra' => ['event_source_url' => 'https://checkout.example/order?product=1']];
$event = static function (array $pixel, array $context) use ($click): array {
    return FacebookConversions::buildPayload($pixel, $click, $context)['data'][0];
};

$actual = $event([], $ctx);
$check('uses the actual event URL supplied by the partner',
    ($actual['event_source_url'] ?? '') === 'https://checkout.example/order?product=1');
$check('stable visitor ID is hashed without changing case',
    ($actual['user_data']['external_id'] ?? []) === [hash('sha256', 'Visitor-Aa123')]);
$check('preserves the original visitor IPv6', ($actual['user_data']['client_ip_address'] ?? '') === '2001:db8::1');
$check('does not use legacy traffic-source external_id as Meta identity',
    !isset($event([], array_replace($ctx, ['click_params' => ['external_id' => 'legacy-ad-click']]))['user_data']['external_id']));
$purchase = $event([], array_replace($ctx, ['event_name' => 'Purchase', 'event_id' => 'click-a_sale_order-a']));
$check('same visitor identity survives another event and transaction',
    ($purchase['user_data']['external_id'] ?? null) === [hash('sha256', 'Visitor-Aa123')]);
$check('a postback cannot replace the identity established at acquisition',
    ($event([], array_replace($ctx, ['extra' => ['meta_external_id' => 'Another-visitor']]))['user_data']['external_id'] ?? null)
        === [hash('sha256', 'Visitor-Aa123')]);
$check('never generates an identity when no visitor identity was captured',
    !isset($event([], array_replace($ctx, ['click_params' => []]))['user_data']['external_id']));

$unknown = $event([], array_replace($ctx, ['extra' => [], 'landing_url' => 'https://landing.example/product',
    'campaign_url' => 'https://tracker.example/campaign']));
$check('does not invent event URL from ad referrer, landing or tracker URL', !isset($unknown['event_source_url']));
$check('configured event URL keeps its explicit precedence',
    ($event(['event_source_url' => 'https://merchant.example/thanks/{clickid}'], $ctx)['event_source_url'] ?? '')
        === 'https://merchant.example/thanks/click-a');
$check('trusted context event URL is supported',
    ($event([], $ctx + ['event_source_url' => 'https://merchant.example/checkout'])['event_source_url'] ?? '')
        === 'https://merchant.example/checkout');
$check('explicit landing macro uses captured landing context',
    ($event(['event_source_url' => '{landing_url}'], $ctx)['event_source_url'] ?? '')
        === 'https://landing.example/product');
$check('missing landing macro does not fall back to acquisition referrer',
    !isset($event(['event_source_url' => '{landing_url}'], array_replace($ctx, ['click_params' => []]))['event_source_url']));
foreach (['javascript:alert(1)', '//checkout.example/order', 'https://', 'https://user:secret@checkout.example/',
    "https://checkout.example/\nprivate", 'https://checkout.example/{unknown}', 'https://exa"mple.com/',
    'https://<checkout>.example/', ['https://checkout.example/']] as $invalid) {
    $check('invalid source URL is not sent: ' . json_encode($invalid),
        !isset($event([], array_replace($ctx, ['extra' => ['event_source_url' => $invalid]]))['event_source_url']));
}
foreach ([['unexpected'], str_repeat('A', 513), "visitor\0other"] as $invalid) {
    $check('invalid visitor ID is omitted',
        !isset($event([], array_replace($ctx, ['click_params' => ['meta_external_id' => $invalid]]))['user_data']['external_id']));
}

$perEvent = ['event_source_url' => json_encode([
    'InitiateCheckout' => '{campaign_url}', 'Purchase' => '{offer_url}',
    'CustomFunnelEvent' => 'https://custom.example/event/{clickid}',
])];
$funnelContext = $ctx + ['campaign_url' => 'https://campaign.example/start',
    'offer_url' => 'https://offer.example/order?click=click-a'];
$check('event URL map selects the final Meta event, not tracker status',
    ($event($perEvent, $funnelContext)['event_source_url'] ?? '') === 'https://campaign.example/start');
$mappedPurchase = $event($perEvent, array_replace($funnelContext, ['event_name' => 'Purchase']));
$check('Purchase uses this click offer URL without changing event identity or time',
    ($mappedPurchase['event_source_url'] ?? '') === $funnelContext['offer_url']
    && $mappedPurchase['event_name'] === 'Purchase' && $mappedPurchase['event_time'] === $ctx['event_time']
    && $mappedPurchase['event_id'] === $ctx['event_id']);
$check('per-event URL map supports arbitrary event names without hardcoded funnel rules',
    ($event($perEvent, array_replace($funnelContext, ['event_name' => 'CustomFunnelEvent']))['event_source_url'] ?? '')
        === 'https://custom.example/event/click-a');
$check('absent event key permits explicit partner URL',
    ($event($perEvent, array_replace($funnelContext, ['event_name' => 'Lead']))['event_source_url'] ?? '')
        === $ctx['extra']['event_source_url']);
$check('absent event key preserves trusted context URL precedence',
    ($event($perEvent, array_replace($funnelContext, ['event_name' => 'Lead', 'event_source_url' => 'https://actual.example/lead']))['event_source_url'] ?? '')
        === 'https://actual.example/lead');
$check('event URL map keys are case-sensitive',
    ($event(['event_source_url' => '{"purchase":"https://wrong.example/"}'], array_replace($ctx, ['event_name' => 'Purchase']))['event_source_url'] ?? '')
        === $ctx['extra']['event_source_url']);
$check('empty map permits explicit event context',
    ($event(['event_source_url' => '{}'], $ctx)['event_source_url'] ?? '') === $ctx['extra']['event_source_url']);
$check('absent event key does not invent an acquisition URL',
    !isset($event($perEvent, array_replace($funnelContext, ['event_name' => 'Lead', 'extra' => []]))['event_source_url']));
foreach (['', '  ', null, false, 7, [], ['url' => 'https://wrong.example/'], 'javascript:alert(1)',
    'https://user:secret@checkout.example/', 'https://checkout.example/{unknown}'] as $invalid) {
    $result = $event(['event_source_url' => json_encode(['Purchase' => $invalid])], array_replace($funnelContext, ['event_name' => 'Purchase']));
    $check('present invalid/empty event URL omits URL without fallback or event suppression: ' . json_encode($invalid),
        !isset($result['event_source_url']) && $result['event_name'] === 'Purchase');
}
foreach (['{"Purchase":', '["https://wrong.example/"]', '"https://wrong.example/"', 'null'] as $invalid) {
    $check('malformed/non-object map does not enable URL fallback: ' . $invalid,
        !isset($event(['event_source_url' => $invalid], $ctx)['event_source_url']));
}
$check('standalone offer macro works without a map',
    ($event(['event_source_url' => '{offer_url}'], $funnelContext)['event_source_url'] ?? '') === $funnelContext['offer_url']);
foreach (['', '//offer.example/order', '/offers/1/', 'javascript:alert(1)', 'https://u:p@offer.example/'] as $invalid) {
    foreach (['{offer_url}', 'https://configured.example/?source={offer_url}'] as $template) {
        $check('missing/invalid offer context cannot produce a guessed URL: ' . $template . ' / ' . $invalid,
            !isset($event(['event_source_url' => $template], array_replace($funnelContext, ['offer_url' => $invalid]))['event_source_url']));
    }
}

foreach ($failures as $failure) {
    echo "FAIL: $failure\n";
}
echo 'Facebook matching payload: ' . ($checks - count($failures)) . "/$checks passed\n";
exit($failures ? 1 : 0);
