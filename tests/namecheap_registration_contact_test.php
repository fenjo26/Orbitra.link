<?php
/** Registration contract checks with a blocked, fake HTTP transport. No purchases. */
require_once __DIR__ . '/../core/NamecheapClient.php';

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
};
$cfg = ['username' => 'fixture-user', 'api_key' => 'fixture-key'];
$fields = [
    'AddressId' => '0', 'FirstName' => 'Jane', 'LastName' => 'Doe',
    'Organization' => 'Example & Sons', 'JobTitle' => 'Owner',
    'Address1' => '123 Example Street', 'Address2' => 'Suite 2',
    'City' => 'Example City', 'StateProvince' => 'CA',
    'StateProvinceChoice' => 'S', 'Zip' => '90001', 'Country' => 'US',
    'Phone' => '+1.2025550100', 'PhoneExt' => '12',
    'Fax' => '', 'EmailAddress' => 'jane@example.com',
];
$required = [
    'FirstName' => 'FirstName', 'LastName' => 'LastName',
    'Address1' => 'Address1', 'City' => 'City',
    'StateProvince' => 'StateProvince', 'PostalCode' => 'Zip',
    'Country' => 'Country', 'Phone' => 'Phone', 'EmailAddress' => 'EmailAddress',
];
$calls = [];
$lookupError = false;
NamecheapClient::$http = static function (string $url, ?string $body = null) use (&$calls, &$fields, &$lookupError, $required): array {
    parse_str($body ?? (parse_url($url, PHP_URL_QUERY) ?? ''), $params);
    $calls[] = ['url' => $url, 'body' => $body, 'params' => $params];
    $command = $params['Command'] ?? '';
    if ($command === 'namecheap.users.address.getInfo') {
        if ($lookupError) {
            return ['body' => '<ApiResponse Status="ERROR"><Errors><Error>Address lookup denied</Error></Errors></ApiResponse>', 'err' => ''];
        }
        $xml = '<ApiResponse xmlns="http://api.namecheap.com/xml.response" Status="OK"><CommandResponse><GetAddressInfoResult>';
        foreach ($fields as $name => $value) {
            $xml .= '<' . $name . '>' . htmlspecialchars($value, ENT_XML1, 'UTF-8') . '</' . $name . '>';
        }
        return ['body' => $xml . '</GetAddressInfoResult></CommandResponse></ApiResponse>', 'err' => ''];
    }
    if ($command === 'namecheap.domains.create') {
        // Mimic the provider's required fields instead of accepting any payload.
        foreach (['Registrant', 'Tech', 'Admin', 'AuxBilling'] as $role) {
            foreach ($required as $suffix => $source) {
                if (trim((string) ($params[$role . $suffix] ?? '')) === '') {
                    return ['body' => '<ApiResponse Status="ERROR"><Errors><Error>Parameter ' . $role . $suffix . ' is Missing</Error></Errors></ApiResponse>', 'err' => ''];
                }
            }
        }
        return ['body' => '<ApiResponse Status="OK"><CommandResponse><DomainCreateResult Registered="true"/></CommandResponse></ApiResponse>', 'err' => ''];
    }
    throw new RuntimeException('Unexpected command in isolated test: ' . $command);
};

$result = NamecheapClient::registerDomain($cfg, 'example.invalid', 1, '0');
$check('registration supplies all provider-required contact fields', $result['ok']);
$check('address zero is fetched before purchase', array_column(array_column($calls, 'params'), 'Command') === ['namecheap.users.address.getInfo', 'namecheap.domains.create'] && ($calls[0]['params']['AddressId'] ?? null) === '0');
$create = end($calls);
foreach (['Registrant', 'Tech', 'Admin', 'AuxBilling'] as $role) {
    $matches = true;
    foreach ($required as $suffix => $source) {
        $matches = $matches && ($create['params'][$role . $suffix] ?? null) === $fields[$source];
    }
    $check($role . ' has all nine required fields', $matches);
    $check($role . ' organization and optional fields mapped', ($create['params'][$role . 'OrganizationName'] ?? null) === 'Example & Sons' && ($create['params'][$role . 'Address2'] ?? null) === 'Suite 2' && ($create['params'][$role . 'PhoneExt'] ?? null) === '12');
    $check($role . ' has no undocumented AddressId shortcut', !array_key_exists($role . 'AddressId', $create['params']));
    $check($role . ' empty fax omitted', !array_key_exists($role . 'Fax', $create['params']));
}
$check('contact requests use POST with credentials outside the URL', count($calls) === 2 && count(array_filter($calls, static fn ($call) => $call['body'] !== null && parse_url($call['url'], PHP_URL_QUERY) === null)) === 2);

$calls = [];
$fields['FirstName'] = '';
$result = NamecheapClient::registerDomain($cfg, 'example.invalid', 1, '0');
$check('incomplete contact blocks the purchase request', !$result['ok'] && count($calls) === 1 && ($calls[0]['params']['Command'] ?? '') === 'namecheap.users.address.getInfo');
$check('missing-field error identifies FirstName', str_contains($result['message'], 'FirstName'));
$fields['FirstName'] = 'Jane';
$calls = [];
$lookupError = true;
$result = NamecheapClient::registerDomain($cfg, 'example.invalid', 1, '0');
$check('lookup failure blocks purchase and preserves its error', !$result['ok'] && $result['message'] === 'Address lookup denied' && count($calls) === 1);
$calls = [];
$result = NamecheapClient::registerDomain($cfg, 'example.invalid', 1, null);
$check('missing contact ID makes no API calls', !$result['ok'] && $calls === []);

NamecheapClient::$http = null;
echo "Passed: $passed; failed: $failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
