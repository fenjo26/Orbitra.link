<?php
/** Regression checks for Namecheap's primary address ID "0". No network calls. */
require_once __DIR__ . '/../core/NamecheapClient.php';

$failed = 0;
$passed = 0;
$check = static function (string $name, bool $ok) use (&$failed, &$passed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
};
$cfg = ['api_key' => 'test-placeholder', 'username' => 'test-placeholder'];
$xml = '';
NamecheapClient::$http = static function (string $url) use (&$xml): array {
    return ['body' => $xml, 'err' => ''];
};
$response = static function (string $items): string {
    return '<ApiResponse xmlns="http://api.namecheap.com/xml.response" Status="OK">'
        . '<CommandResponse Type="namecheap.users.address.getList"><AddressGetListResult>'
        . $items . '</AddressGetListResult></CommandResponse></ApiResponse>';
};

$primary = '<List AddressId="0" AddressName="Primary Address" IsDefault="true"/>';
$secondary = '<List AddressId="49" AddressName="Secondary" IsDefault="false"/>';
$xml = $response($primary);
$addresses = NamecheapClient::listAddresses($cfg);
$check('primary-only account retains ID zero', count($addresses) === 1 && ($addresses[0]['id'] ?? null) === '0');
$check('primary name and default flag retained', ($addresses[0]['name'] ?? '') === 'Primary Address' && ($addresses[0]['is_default'] ?? false));
$xml = $response($primary . $secondary);
$check('primary and secondary both retained', array_column(NamecheapClient::listAddresses($cfg), 'id') === ['0', '49']);
$xml = $response('<List AddressName="Missing ID"/><List AddressId="" AddressName="Empty ID"/>' . $secondary);
$check('missing and empty IDs still excluded', array_column(NamecheapClient::listAddresses($cfg), 'id') === ['49']);
$xml = $response('');
$check('empty address book stays empty', NamecheapClient::listAddresses($cfg) === []);

// Execute only the address-selection statements from the registration handler.
// Do not load api.php or invoke the live registration endpoint.
$source = file_get_contents(__DIR__ . '/../api.php');
$handlerStart = strpos($source, "case 'namecheap_register_domain':");
$selectionStart = $handlerStart === false ? false : strpos($source, '$addressId = trim', $handlerStart);
$selectionEnd = $selectionStart === false ? false : strpos($source, 'if ($domain ===', $selectionStart);
if ($selectionStart === false || $selectionEnd === false) {
    throw new RuntimeException('Could not locate the address selection block.');
}
$selection = substr($source, $selectionStart, $selectionEnd - $selectionStart);
$select = static function (array $input, string $saved) use ($selection): string {
    $dataNc = $input;
    $cfgNc = ['address_id' => $saved];
    eval($selection);
    return $addressId;
};
$check('explicit zero overrides a saved secondary contact', $select(['address_id' => '0'], '49') === '0');
$check('explicit zero works without a saved contact', $select(['address_id' => '0'], '') === '0');
$check('saved primary zero remains valid', $select([], '0') === '0');
$check('blank input uses the saved contact', $select(['address_id' => ''], '49') === '49');
$check('missing contact stays missing', $select([], '') === '');

NamecheapClient::$http = null;
echo "Passed: $passed; failed: $failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
