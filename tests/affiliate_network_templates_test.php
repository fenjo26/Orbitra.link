<?php
/**
 * Regression checks for the built-in affiliate-network presets in api.php.
 *
 * Run from the project root:
 *
 *     php tests/affiliate_network_templates_test.php
 */

$source = (string) file_get_contents(__DIR__ . '/../api.php');
$caseStart = strpos($source, "case 'affiliate_network_templates':");
$assignmentStart = $caseStart === false ? false : strpos($source, '$templates = [', $caseStart);
$arrayStart = $assignmentStart === false ? false : strpos($source, '[', $assignmentStart);
$mergeStart = $arrayStart === false ? false : strpos($source, '$templates = orbitraMergeTemplates', $arrayStart);

if ($arrayStart === false || $mergeStart === false) {
    fwrite(STDERR, "FAIL: affiliate network template array not found\n");
    exit(1);
}

$arrayExpression = trim(substr($source, $arrayStart, $mergeStart - $arrayStart));
$templates = eval('return ' . $arrayExpression);
if (!is_array($templates)) {
    fwrite(STDERR, "FAIL: affiliate network template array could not be loaded\n");
    exit(1);
}

$byName = [];
$duplicates = [];
foreach ($templates as $template) {
    $name = (string) ($template['name'] ?? '');
    if ($name === '') {
        continue;
    }
    if (isset($byName[$name])) {
        $duplicates[] = $name;
    }
    $byName[$name] = $template;
}

$expected = [
    'generic' => [
        'display_name' => 'Generic Postback',
        'offer_params_template' => '&subid={subid}',
        'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={subid}&status={status}&payout={payout}&tid={tid}',
    ],
    'lemonad' => [
        'display_name' => 'LemonAD.com',
        'offer_params_template' => '&clickid={subid}',
        'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={clickid}&payout={payout}&status={status}&lead_status=lead&sale_status=sale&rejected_status=rejected,trash&currency=USD&from=LemonAD.com',
    ],
    'traffic_light' => [
        'offer_params_template' => '&subid={subid}',
        'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={subid}&payout={payout}&status={status}&lead_status=1&sale_status=2&rejected_status=3,4&currency=rub&from=TrafficLight',
    ],
    'everflow' => [
        'offer_params_template' => '&sub1={subid}',
        'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={sub1}&payout={amount}&status={status}&currency={currency}&from=Everflow',
    ],
    'affise' => [
        'offer_params_template' => '&sub1={subid}',
        'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={sub1}&payout={sum}&status={status}&currency={currency}&from=Affise',
    ],
    'cake' => [
        'offer_params_template' => '&s1={subid}',
        'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid=#s1#&payout=#payout#&status=#status#&from=CAKE',
    ],
    'tune' => [
        'offer_params_template' => '&aff_sub={subid}',
        'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={aff_sub}&payout={payout}&status={status}&currency={currency}&from=TUNE',
    ],
    'drcash' => [
        'offer_params_template' => '&sub1={subid}',
        'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={sub1}&payout={payment}&status={status}&lead_status=pending&sale_status=approved&rejected_status=rejected,trash&currency=USD&from=drcash',
    ],
    'webvork' => [
        'offer_params_template' => '&utm_campaign={subid}',
        'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={utm_campaign}&payout={price}&status={status}&lead_status=pending&sale_status=approved&rejected_status=rejected,trash&currency=EUR&from=webvork',
    ],
    'adcombo' => [
        'offer_params_template' => '&subacc={subid}',
        'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={subacc}&payout={revenue}&status={status}&lead_status=hold&sale_status=confirmed&rejected_status=rejected,trash&currency=USD&from=adcombo',
    ],
    'kma' => [
        'offer_params_template' => '&data1={subid}',
        'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={data1}&payout={sum}&status={status}&lead_status=pending&sale_status=accepted&rejected_status=declined,trash&currency=rub&from=kma.biz',
    ],
    'everad' => [
        'offer_params_template' => '&sid1={subid}',
        'postback_url_template' => 'http://{domain}/{postback_key}/postback?subid={sid1}&payout={payout}&status={status}&lead_status=new&sale_status=approved&rejected_status=rejected,trash&currency=USD&from=everad',
    ],
];

$failures = [];
if ($duplicates !== []) {
    $failures[] = 'duplicate built-in template names: ' . implode(', ', $duplicates);
}

foreach ($expected as $name => $fields) {
    if (!isset($byName[$name])) {
        $failures[] = "$name template missing";
        continue;
    }
    foreach ($fields as $field => $expectedValue) {
        $actualValue = (string) ($byName[$name][$field] ?? '');
        if ($actualValue !== $expectedValue) {
            $failures[] = "$name.$field mismatch";
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: $failure\n");
    }
    exit(1);
}

echo 'ok: ' . count($expected) . " affiliate network presets match the production definitions\n";
