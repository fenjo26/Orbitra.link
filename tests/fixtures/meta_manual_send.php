<?php
// Runs the real manual-send entry point with the same transport fixture as cron.
require_once __DIR__ . '/core/FacebookConversions.php';

$cases = json_decode(file_get_contents(__DIR__ . '/transport_cases.json'), true);
$input = json_decode(file_get_contents(__DIR__ . '/manual_input.json'), true);
$results = [];
foreach ($cases as $name => $case) {
    if (isset($case['host'])) {
        continue;
    }
    $GLOBALS['metaTransportCase'] = $name;
    $payload = $input['payload'];
    if (($case['batch_size'] ?? 1) === 2) {
        $payload['data'][] = array_merge($payload['data'][0], ['event_id' => 'fixture-order-2']);
    }
    $results[$name] = FacebookConversions::send($input['pixel'], $payload);
}
file_put_contents(__DIR__ . '/manual_results.json', json_encode($results));
