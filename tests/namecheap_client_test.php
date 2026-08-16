<?php
/**
 * tests/namecheap_client_test.php
 *
 * Covers the Namecheap XML API client against canned XML fixtures — no network
 * is touched: the HTTP transport is swapped for one that answers from an array
 * keyed by the Command= query parameter. Run from the project root:
 *
 *     php tests/namecheap_client_test.php
 */

require_once __DIR__ . '/../core/NamecheapClient.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/** @var array<string,string> $fixtures command => XML body */
$fixtures = [
    'namecheap.users.getBalance' => <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
  <CommandResponse Type="namecheap.users.getBalance">
    <UserGetBalanceResult Currency="USD" Balance="142.35" AvailableBalance="142.35" AccountType="RESELLER"/>
  </CommandResponse>
</ApiResponse>
XML,
    'namecheap.domains.getList' => <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
  <CommandResponse Type="namecheap.domains.getList">
    <DomainGetListResult TotalItems="3" CurrentPage="1" PageSize="100">
      <Domain ID="1" Name="example.com" User="owner" Created="01/01/2024" Expires="01/01/2027"/>
      <Domain ID="2" Name="my-site.co.uk" User="owner" Created="02/02/2024" Expires="02/02/2027"/>
      <Domain ID="3" Name="shop.example.com" User="owner" Created="03/03/2024" Expires="03/03/2027"/>
    </DomainGetListResult>
  </CommandResponse>
</ApiResponse>
XML,
    'namecheap.users.address.getList' => <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
  <CommandResponse Type="namecheap.users.address.getList">
    <AddressGetListResult>
      <List AddressId="111" AddressName="Primary Address" IsDefault="true"/>
      <List AddressId="222" AddressName="Secondary" IsDefault="false"/>
    </AddressGetListResult>
  </CommandResponse>
</ApiResponse>
XML,
    'namecheap.domains.check' => <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
  <CommandResponse Type="namecheap.domains.check">
    <DomainCheckResult Domain="freshtld.com" Available="true" IsPremiumName="true" PremiumRegistrationPrice="129.99"/>
  </CommandResponse>
</ApiResponse>
XML,
    'namecheap.domains.dns.getHosts' => <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
  <CommandResponse Type="namecheap.domains.dns.getHosts">
    <DomainDNSGetHostsResult IsUsingOurDNS="true" HostCount="3" EmailType="MX">
      <host HostId="1" Name="@" Type="A" Address="203.0.113.9" TTL="1800" MXPref="10"/>
      <host HostId="2" Name="mail" Type="MX" Address="mx1.park-co.net" TTL="1800" MXPref="10"/>
      <host HostId="3" Name="ftp" Type="CNAME" Address="example.com" TTL="1800"/>
    </DomainDNSGetHostsResult>
  </CommandResponse>
</ApiResponse>
XML,
    'namecheap.domains.create' => <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
  <CommandResponse Type="namecheap.domains.create">
    <DomainCreateResult Domain="newdomain.com" Registered="true" OrderID="12345" TransactionID="999" ChargedAmount="9.98"/>
  </CommandResponse>
</ApiResponse>
XML,
];

// Errors: whitelist failure carries the caller's real IP in the text.
$whitelistError = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response">
  <Errors>
    <Error Number="1011102">API Key is invalid or IP 87.232.72.54 is not whitelisted.</Error>
  </Errors>
</ApiResponse>
XML;

$capturedSetHosts = null;
NamecheapClient::$http = function (string $url) use ($fixtures, $whitelistError, &$capturedSetHosts): array {
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
    $command = (string) ($q['Command'] ?? '');
    if ($command === 'namecheap.domains.dns.setHosts') {
        $capturedSetHosts = $q;
        return ['body' => '<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response"><CommandResponse Type="namecheap.domains.dns.setHosts"><DomainDNSSetHostsResult IsSuccess="true"/></CommandResponse></ApiResponse>', 'err' => ''];
    }
    if (strpos((string) ($q['ApiKey'] ?? ''), 'bad') === 0) {
        return ['body' => $whitelistError, 'err' => ''];
    }
    $body = $fixtures[$command] ?? false;
    return ['body' => $body, 'err' => $body === false ? 'no fixture' : ''];
};

$cfg = ['api_key' => 'k' . str_repeat('e', 24), 'username' => 'owner', 'client_ip' => '87.232.72.54', 'sandbox' => false];

echo "== request / verifyConnection ==\n";
$balance = NamecheapClient::verifyConnection($cfg);
check('balance ok', $balance['ok']);
check('balance amount parsed', $balance['balance'] === 'USD 142.35', $balance['balance'] ?? 'null');

$bad = NamecheapClient::verifyConnection(['api_key' => 'badkey', 'username' => 'owner', 'client_ip' => '', 'sandbox' => false]);
check('error not ok', !$bad['ok']);
check('error text carries whitelist message', strpos($bad['message'], 'whitelisted') !== false, $bad['message']);
check('caller IP extracted from whitelist error', $bad['ip_hint'] === '87.232.72.54', $bad['ip_hint']);

echo "== listDomains / findRegisteredDomain ==\n";
$domains = NamecheapClient::listDomains($cfg);
check('3 domains listed', count($domains) === 3, implode(',', $domains));
check('findRegisteredDomain root', NamecheapClient::findRegisteredDomain($cfg, 'example.com') === 'example.com');
check('findRegisteredDomain sub', NamecheapClient::findRegisteredDomain($cfg, 'tracker.example.com') === 'example.com');
check('findRegisteredDomain deep sub', NamecheapClient::findRegisteredDomain($cfg, 'a.b.my-site.co.uk') === 'my-site.co.uk');
check('findRegisteredDomain multi-level zone', NamecheapClient::findRegisteredDomain($cfg, 'x.shop.example.com') === 'shop.example.com');
check('findRegisteredDomain unknown', NamecheapClient::findRegisteredDomain($cfg, 'other.org') === null);

echo "== listAddresses ==\n";
$addresses = NamecheapClient::listAddresses($cfg);
check('2 addresses', count($addresses) === 2);
check('address shape', $addresses[0]['id'] === '111' && $addresses[0]['name'] === 'Primary Address' && $addresses[0]['is_default'] === true);

echo "== checkDomain ==\n";
$check = NamecheapClient::checkDomain($cfg, 'freshtld.com');
check('available', $check['available'] === true);
check('premium price', $check['is_premium'] === true && $check['price'] === '129.99');

echo "== parseHost / splitSldTld ==\n";
$p = NamecheapClient::parseHost('https://Promo.My-Site.co.uk/path?q=1');
check('parseHost strips scheme/path/case', $p === ['root' => 'my-site.co.uk', 'sub' => 'promo'], json_encode($p));
$s = NamecheapClient::splitSldTld('my-site.co.uk');
check('splitSldTld multi-part TLD', $s === ['sld' => 'my-site', 'tld' => 'co.uk'], json_encode($s));
$s2 = NamecheapClient::splitSldTld('example.com');
check('splitSldTld simple TLD', $s2 === ['sld' => 'example', 'tld' => 'com'], json_encode($s2));

echo "== registerDomain ==\n";
$reg = NamecheapClient::registerDomain($cfg, 'newdomain.com', 1, '111');
check('registered', $reg['ok'] && $reg['message'] === 'Registered');

echo "== setHostRecords ==\n";
$host = NamecheapClient::setHostRecords($cfg, 'example', 'com', '@', '198.51.100.7');
check('setHosts ok', $host['ok'], $host['message']);
check('setHosts message names zone and ip', strpos($host['message'], 'example.com') !== false && strpos($host['message'], '198.51.100.7') !== false, $host['message']);
$q = $capturedSetHosts;
check('existing @ A updated', ($q['HostName1'] ?? '') === '@' && ($q['Address1'] ?? '') === '198.51.100.7');
check('MX record preserved with MXPref', ($q['RecordType2'] ?? '') === 'MX' && ($q['Address2'] ?? '') === 'mx1.park-co.net' && ($q['MXPref2'] ?? '') === '10');
check('CNAME preserved', ($q['RecordType3'] ?? '') === 'CNAME' && ($q['HostName3'] ?? '') === 'ftp');
check('www A added', ($q['HostName4'] ?? '') === 'www' && ($q['RecordType4'] ?? '') === 'A' && ($q['Address4'] ?? '') === '198.51.100.7');
check('EmailType omitted when MX present', !isset($q['EmailType']));

$sub = NamecheapClient::setHostRecords($cfg, 'example', 'com', 'tracker', '198.51.100.7');
check('subdomain setHosts ok', $sub['ok'], $sub['message']);
$q2 = $capturedSetHosts;
$names = [];
for ($i = 1; isset($q2["HostName$i"]); $i++) { $names[] = $q2["HostName$i"] . ':' . $q2["RecordType$i"]; }
check('subdomain adds tracker A only (no www)', in_array('tracker:A', $names, true) && !in_array('www:A', $names, true), implode(',', $names));
check('existing records untouched for sub park', in_array('ftp:CNAME', $names, true) && ($q2['Address1'] ?? '') === '203.0.113.9');

NamecheapClient::$http = null;

echo "\n" . ($failed === 0 ? "ALL OK ($passed)" : "FAILED: $failed of " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
