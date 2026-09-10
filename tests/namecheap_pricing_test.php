<?php
/** Run: php tests/namecheap_pricing_test.php — canned XML only, no purchases. */
require_once __DIR__ . '/../core/NamecheapClient.php';

$passed = $failed = 0;
$check = static function (string $name, bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
};
$cfg = ['api_key' => 'test-key', 'username' => 'buyer', 'sandbox' => true];
$calls = [];
$available = true;
$premium = false;
$premiumPrice = '0';
$icannFee = '0';
$pricingError = false;
$pricingXml = '';
$priceEntry = static function (string $price, string $extra = '', int $years = 1): string {
    return '<Price Duration="' . $years . '" DurationType="YEAR" Price="' . $price
        . '" RegularPrice="29.99" YourPrice="19.99" CouponPrice="0" Currency="USD" ' . $extra . '/>';
};
$pricing = static function (string $entries, string $tld = 'com', string $type = 'domains'): string {
    return '<ProductType Name="' . $type . '"><ProductCategory Name="register"><Product Name="'
        . $tld . '">' . $entries . '</Product></ProductCategory></ProductType>';
};
NamecheapClient::$http = static function (string $url, ?string $body = null) use (&$calls, &$available, &$premium, &$premiumPrice, &$icannFee, &$pricingError, &$pricingXml): array {
    parse_str($body ?? parse_url($url, PHP_URL_QUERY) ?? '', $params);
    $calls[] = $params;
    $command = $params['Command'] ?? '';
    if ($command === 'namecheap.domains.check') {
        $xml = '<DomainCheckResult Domain="' . $params['DomainList'] . '" Available="' . ($available ? 'true' : 'false')
            . '" IsPremiumName="' . ($premium ? 'true' : 'false') . '" PremiumRegistrationPrice="'
            . $premiumPrice . '" IcannFee="' . $icannFee . '" EapFee="0"/>';
    } elseif ($command === 'namecheap.users.getPricing') {
        if ($pricingError) {
            return ['body' => '<ApiResponse Status="ERROR"><Errors><Error>Pricing unavailable</Error></Errors></ApiResponse>', 'err' => ''];
        }
        $xml = '<UserGetPricingResult>' . $pricingXml . '</UserGetPricingResult>';
    } else {
        throw new RuntimeException('Unexpected API command: ' . $command);
    }
    return ['body' => '<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><CommandResponse>'
        . $xml . '</CommandResponse></ApiResponse>', 'err' => ''];
};

$pricingXml = $pricing($priceEntry('11.28', 'AdditionalCost="0.20"') . $priceEntry('26.26', '', 2));
$result = NamecheapClient::checkDomain($cfg, ' Example.COM ');
$check('regular domain uses account registration price plus the supplied fee', $result['available'] && !$result['is_premium'] && $result['price'] === '11.48');
$check('quote includes the API currency', ($result['currency'] ?? null) === 'USD');
$request = end($calls);
$check('pricing request uses registration action, full TLD and selected account', ($request['Command'] ?? '') === 'namecheap.users.getPricing'
    && ($request['ProductType'] ?? '') === 'DOMAIN' && ($request['ProductCategory'] ?? '') === 'DOMAINS'
    && ($request['ActionName'] ?? '') === 'REGISTER' && ($request['ProductName'] ?? '') === 'COM'
    && ($request['UserName'] ?? '') === 'buyer');

$pricingXml = $pricing($priceEntry('7.49'), 'co.uk', 'DOMAIN');
$result = NamecheapClient::checkDomain($cfg, 'example.co.uk');
$check('single price node and compound TLD supported', $result['price'] === '7.49' && (end($calls)['ProductName'] ?? '') === 'CO.UK');

// Ignore unrelated types, renewal prices, TLDs, and multi-year rates even
// when a provider returns more than the requested product.
$pricingXml = '<ProductType Name="SSL"><ProductCategory Name="REGISTER"><Product Name="com">'
    . $priceEntry('1.00') . '</Product></ProductCategory></ProductType>'
    . '<ProductType Name="DOMAIN"><ProductCategory Name="RENEW"><Product Name="com">'
    . $priceEntry('2.00') . '</Product></ProductCategory><ProductCategory Name="REGISTER">'
    . '<Product Name="net">' . $priceEntry('3.00') . '</Product><Product Name="COM">'
    . $priceEntry('24.00', '', 2) . $priceEntry('9.50') . '</Product></ProductCategory></ProductType>';
$check('selects the matching one-year registration rate', NamecheapClient::checkDomain($cfg, 'example.com')['price'] === '9.50');

$other = $cfg;
$other['username'] = 'another-buyer';
$pricingXml = $pricing($priceEntry('8.00'));
$result = NamecheapClient::checkDomain($other, 'example.com');
$check('accounts do not share a price', $result['price'] === '8.00' && (end($calls)['UserName'] ?? '') === 'another-buyer');

$premium = true;
$premiumPrice = '129.9900';
$icannFee = '0.20';
$calls = [];
$result = NamecheapClient::checkDomain($cfg, 'example.com');
$check('premium quote uses domain-specific price and fees', $result['price'] === '130.19' && ($result['currency'] ?? null) === 'USD');
$check('premium quote does not use the regular TLD rate', count($calls) === 1);
$premiumPrice = '0';
$check('zero premium price stays unknown', NamecheapClient::checkDomain($cfg, 'example.com')['price'] === null);
$premium = false;
$icannFee = '0';

foreach (['0', '0.00', '0.0001', '-1', 'NaN', '', 'invalid'] as $badPrice) {
    $pricingXml = $pricing($priceEntry($badPrice));
    $result = NamecheapClient::checkDomain($cfg, 'example.com');
    $check('invalid price is unknown: ' . var_export($badPrice, true), $result['available'] && $result['price'] === null && ($result['currency'] ?? null) === null);
}
$pricingXml = $pricing($priceEntry('5.00', 'AdditionalCost="unknown"'));
$check('malformed fee does not produce a misleading quote', NamecheapClient::checkDomain($cfg, 'example.com')['price'] === null);
$pricingXml = $pricing(str_replace('Currency="USD"', '', $priceEntry('5.00')));
$check('missing currency stays unknown', NamecheapClient::checkDomain($cfg, 'example.com')['price'] === null);
$pricingXml = $pricing($priceEntry('25.00', '', 2));
$check('no one-year rate stays unknown', NamecheapClient::checkDomain($cfg, 'example.com')['price'] === null);
$pricingXml = '';
$check('empty pricing stays unknown', NamecheapClient::checkDomain($cfg, 'example.com')['price'] === null);
$pricingError = true;
$result = NamecheapClient::checkDomain($cfg, 'example.com');
$check('pricing API error preserves availability without a fake free price', $result['available'] && $result['price'] === null);
$pricingError = false;

$available = false;
$calls = [];
$result = NamecheapClient::checkDomain($cfg, 'example.com');
$check('taken domains skip pricing', !$result['available'] && $result['price'] === null && count($calls) === 1);
$available = true;
$calls = [];
$result = NamecheapClient::checkDomain($cfg, 'example.com', false);
$check('purchase availability recheck skips a redundant price lookup', $result['available'] && $result['price'] === null && count($calls) === 1);

NamecheapClient::$http = null;
echo "Passed: $passed; failed: $failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
