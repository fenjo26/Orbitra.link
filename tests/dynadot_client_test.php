<?php
/**
 * tests/dynadot_client_test.php — DynadotClient against a stubbed API3.
 *
 *     php tests/dynadot_client_test.php
 *
 * The DNS cases are the point: set_dns2 replaces a domain's whole DNS setup,
 * so parking a domain must never cost the operator their MX/TXT records, and
 * must never pull a domain off the name servers it actually uses.
 */

require_once __DIR__ . '/../core/DynadotClient.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/** Stub state: replies per command, every call recorded. */
$calls = [];
$replies = [];
DynadotClient::$sleep = static function (float $s): void {
    global $slept;
    $slept[] = $s;
};
DynadotClient::$http = static function (string $url): array {
    global $calls, $replies;
    $q = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $q['_xml'] = str_contains($url, 'api3.xml');
    $calls[] = $q;
    $reply = $replies[$q['command']] ?? null;
    if (is_callable($reply)) {
        $reply = $reply($q);
    }
    return ['body' => $reply ?? '', 'err' => $reply === null ? 'no stub' : ''];
};
$cfg = ['api_key' => 'k-' . bin2hex(random_bytes(4)), 'sandbox' => false];
$reset = static function (array $r): void {
    global $calls, $replies;
    $calls = [];
    $replies = $r;
};
$setCalls = static function () {
    global $calls;
    return array_values(array_filter($calls, static fn ($c) => $c['command'] === 'set_dns2'));
};
$domainInfo = static fn (string $type) => json_encode(['DomainInfoResponse' => [
    'ResponseCode' => 0, 'Status' => 'success',
    'DomainInfo' => ['Name' => 'example.com', 'Expiration' => 1, 'NameServerSettings' => ['Type' => $type]],
]]);
$ok = json_encode(['SetDnsResponse' => ['ResponseCode' => 0, 'Status' => 'success']]);
$dnsXml = static function (string $main, string $sub, string $ttl = '300'): string {
    return "<GetDnsResponse><GetDnsHeader><ResponseCode>0</ResponseCode><Status>success</Status></GetDnsHeader>"
        . "<GetDnsContent><NameServerSettings><Type>Dynadot DNS</Type><MainDomains>$main</MainDomains>"
        . "<SubDomains>$sub</SubDomains><TTL>$ttl</TTL></NameServerSettings></GetDnsContent></GetDnsResponse>";
};
$mainRec = static fn ($t, $v, $v2 = '') => "<MainDomainRecord><RecordType>$t</RecordType><Value>$v</Value>" . ($v2 !== '' ? "<Value2>$v2</Value2>" : '') . "</MainDomainRecord>";
$subRec = static fn ($h, $t, $v, $v2 = '') => "<SubDomainRecord><Subhost>$h</Subhost><RecordType>$t</RecordType><Value>$v</Value>" . ($v2 !== '' ? "<Value2>$v2</Value2>" : '') . "</SubDomainRecord>";
$IP = '203.0.113.7';

echo "\n== errors ==\n";
$reset(['account_info' => json_encode(['Response' => ['ResponseCode' => '-1', 'Error' => 'invalid key']])]);
$v = DynadotClient::verifyConnection($cfg);
check('invalid key → not ok, message kept', !$v['ok'] && $v['message'] === 'invalid key', json_encode($v));
$reset([]);
$v = DynadotClient::verifyConnection($cfg);
check('empty response → connection error', !$v['ok'] && str_contains($v['message'], 'Connection error'));
check('no key → refused without a call', !DynadotClient::verifyConnection(['api_key' => ''])['ok'] && count($calls) === 1);

echo "\n== balance ==\n";
$reset(['account_info' => json_encode(['AccountInfoResponse' => ['ResponseCode' => 0, 'Status' => 'success',
    'AccountInfo' => ['Username' => 'u', 'BalanceList' => [['Currency' => 'EUR', 'Amount' => '3.00'], ['Currency' => 'USD', 'Amount' => '42.50']]]]])]);
$b = DynadotClient::getBalance($cfg);
check('USD balance picked from BalanceList', $b['ok'] && $b['balance'] === 'USD 42.50', json_encode($b));
$reset(['account_info' => json_encode(['AccountInfoResponse' => ['ResponseCode' => 0, 'Status' => 'success', 'AccountInfo' => ['AccountBalance' => '7.25']]])]);
check('flat AccountBalance fallback', DynadotClient::getBalance($cfg)['available'] === '7.25');

echo "\n== list_domain ==\n";
$reset(['list_domain' => static function ($q) {
    $page = (int) $q['page_index'];
    $make = static fn ($from, $n) => array_map(static fn ($i) => ['Name' => "d$i.com", 'Expiration' => 1, 'NameServerSettings' => ['Type' => 'x']], range($from, $from + $n - 1));
    $rows = $page === 1 ? $make(1, 100) : ($page === 2 ? $make(101, 5) : []);
    return json_encode(['ListDomainInfoResponse' => ['ResponseCode' => 0, 'Status' => 'success', 'MainDomains' => $rows]]);
}]);
$l = DynadotClient::listDomains($cfg);
check('paginates until a short page (105 domains, 2 calls)', $l['ok'] && count($l['domains']) === 105 && count($calls) === 2, count($l['domains']) . ' / ' . count($calls));
$reset(['list_domain' => json_encode(['ListDomainInfoResponse' => ['ResponseCode' => 0, 'Status' => 'success',
    'MainDomains' => array_map(static fn ($i) => ['Name' => "x$i.net", 'Expiration' => 1], range(1, 100))]])]);
$l = DynadotClient::listDomains($cfg);
check('API ignoring paging stops at the first repeat', count($l['domains']) === 100 && count($calls) === 2, (string) count($calls));

echo "\n== search ==\n";
$reset(['search' => json_encode(['SearchResponse' => ['ResponseCode' => '0', 'Status' => 'success', 'SearchResults' => [
    ['DomainName' => 'free.com', 'Available' => 'yes', 'Price' => 'Registration Price: 10.99 in USD and Renewal price: 10.99 in USD'],
]]])]);
$c = DynadotClient::checkDomain($cfg, 'Free.com');
check('available + price parsed', $c['available'] && $c['price'] === '10.99' && $c['currency'] === 'USD', json_encode($c));
$reset(['search' => json_encode(['SearchResponse' => ['ResponseCode' => '0', 'SearchResults' => [['DomainName' => 'taken.com', 'Available' => 'no']]]])]);
check('taken domain', !DynadotClient::checkDomain($cfg, 'taken.com')['available']);

echo "\n== DNS: name server modes ==\n";
check('"Name Servers" → external', DynadotClient::classifyNameServerType('Name Servers') === 'external');
check('"Dynadot Parking" → fresh', DynadotClient::classifyNameServerType('Dynadot Parking') === 'fresh');
check('"Dynadot DNS" → dynadot_dns', DynadotClient::classifyNameServerType('Dynadot DNS') === 'dynadot_dns');

$reset(['domain_info' => $domainInfo('Name Servers'), 'set_dns2' => $ok]);
$r = DynadotClient::setARecord($cfg, 'example.com', '', $IP);
check('external NS → refused, nothing written', !$r['ok'] && count($setCalls()) === 0 && str_contains($r['message'], 'own name servers'), $r['message']);

$reset(['domain_info' => $domainInfo('Dynadot Parking'), 'set_dns2' => $ok]);
$r = DynadotClient::setARecord($cfg, 'example.com', '', $IP);
$s = $setCalls()[0] ?? [];
check('parked root → A @ + A www', $r['ok'] && ($s['main_record_type0'] ?? '') === 'a' && ($s['main_record0'] ?? '') === $IP
    && ($s['subdomain0'] ?? '') === 'www' && ($s['sub_record0'] ?? '') === $IP && !isset($s['add_dns_to_current_setting']), json_encode($s));

echo "\n== DNS: Dynadot DNS zone ==\n";
$zoneMx = $dnsXml($mainRec('MX', 'mx1.mail.net', '10') . $mainRec('TXT', 'v=spf1 include:mail.net ~all'), $subRec('mail', 'CNAME', 'ghs.mail.net'));
$reset(['domain_info' => $domainInfo('Dynadot DNS'), 'get_dns' => $zoneMx, 'set_dns2' => $ok]);
$r = DynadotClient::setARecord($cfg, 'example.com', '', $IP);
$s = $setCalls()[0] ?? [];
check('no A yet → append mode (others untouched)', $r['ok'] && ($s['add_dns_to_current_setting'] ?? '') === '1'
    && ($s['main_record0'] ?? '') === $IP && ($s['subdomain0'] ?? '') === 'www' && !isset($s['main_record1']), json_encode($s));
$getDnsCall = array_values(array_filter($calls, static fn ($c) => $c['command'] === 'get_dns'))[0] ?? [];
check('get_dns read through the XML endpoint', !empty($getDnsCall['_xml']));

$reset(['domain_info' => $domainInfo('Dynadot DNS'), 'get_dns' => $dnsXml($mainRec('A', $IP), $subRec('www', 'A', $IP)), 'set_dns2' => $ok]);
$r = DynadotClient::setARecord($cfg, 'example.com', '', $IP);
check('already pointing → no write', $r['ok'] && count($setCalls()) === 0, $r['message']);

$zoneOld = $dnsXml($mainRec('A', '198.51.100.1') . $mainRec('MX', 'mx1.mail.net', '5') . $mainRec('TXT', 'v=spf1 -all'),
    $subRec('www', 'CNAME', 'example.com') . $subRec('shop', 'A', '198.51.100.9') . $subRec('_dmarc', 'TXT', 'v=DMARC1; p=none'), '3600');
$reset(['domain_info' => $domainInfo('Dynadot DNS'), 'get_dns' => $zoneOld, 'set_dns2' => $ok]);
$r = DynadotClient::setARecord($cfg, 'example.com', '', $IP);
$s = $setCalls()[0] ?? [];
$mainVals = [];
for ($i = 0; isset($s["main_record_type$i"]); $i++) {
    $mainVals[] = $s["main_record_type$i"] . '=' . $s["main_record$i"] . (isset($s["main_recordx$i"]) ? '/' . $s["main_recordx$i"] : '');
}
$subVals = [];
for ($i = 0; isset($s["subdomain$i"]); $i++) {
    $subVals[] = $s["subdomain$i"] . ':' . $s["sub_record_type$i"] . '=' . $s["sub_record$i"];
}
sort($mainVals);
sort($subVals);
check('conflict → full rewrite, not append', $r['ok'] && !isset($s['add_dns_to_current_setting']), $r['message']);
check('rewrite keeps MX (with priority) and TXT, swaps the root A', $mainVals === ["a=$IP", 'mx=mx1.mail.net/5', 'txt=v=spf1 -all'], json_encode($mainVals));
check('rewrite keeps shop/_dmarc, replaces www CNAME with A', $subVals === ['_dmarc:txt=v=DMARC1; p=none', 'shop:a=198.51.100.9', "www:a=$IP"], json_encode($subVals));
check('rewrite keeps the zone TTL', ($s['ttl'] ?? '') === '3600');

$reset(['domain_info' => $domainInfo('Dynadot DNS'), 'get_dns' => $dnsXml($mainRec('A', '198.51.100.1') . $mainRec('FORWARD', 'https://x.com', '1'), ''), 'set_dns2' => $ok]);
$r = DynadotClient::setARecord($cfg, 'example.com', '', $IP);
check('conflict + forward record → refused, nothing written', !$r['ok'] && count($setCalls()) === 0 && str_contains($r['message'], 'forward'), $r['message']);

$reset(['domain_info' => $domainInfo('Dynadot DNS'), 'get_dns' => $zoneMx, 'set_dns2' => $ok]);
$r = DynadotClient::setARecord($cfg, 'example.com', 'promo', $IP);
$s = $setCalls()[0] ?? [];
check('subdomain → append only that host', $r['ok'] && ($s['subdomain0'] ?? '') === 'promo' && !isset($s['main_record0']) && !isset($s['subdomain1']), json_encode($s));

echo "\n== serialisation ==\n";
$slept = [];
$reset(['account_info' => json_encode(['AccountInfoResponse' => ['ResponseCode' => 0, 'Status' => 'success', 'AccountInfo' => ['AccountBalance' => '1']]])]);
DynadotClient::getBalance($cfg);
DynadotClient::getBalance($cfg);
check('back-to-back calls with one key wait out the minimum interval', count($slept) >= 1 && max($slept) <= DynadotClient::MIN_INTERVAL, json_encode($slept));

echo "\n" . str_repeat('-', 60) . "\npassed: $passed   failed: $failed\n";
exit($failed === 0 ? 0 : 1);
