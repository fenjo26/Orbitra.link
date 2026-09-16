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

foreach ($failures as $failure) {
    echo "FAIL: $failure\n";
}
echo 'Facebook matching payload: ' . ($checks - count($failures)) . "/$checks passed\n";
exit($failures ? 1 : 0);
