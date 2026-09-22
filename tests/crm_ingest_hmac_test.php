<?php
// tests/crm_ingest_hmac_test.php
//
// Standalone checks for the /crm-ingest HMAC gate (audit #19) and the
// CloakDetector iCloud Private Relay / VPN-ASN changes (audit #15).
// Run: php tests/crm_ingest_hmac_test.php   (exit code 0 = all passed)
//
// The HMAC verifier lives inside index.php at file scope, so the real shipped
// function is extracted from the source and eval()ed here instead of a copy
// being tested. The detector checks use explicit config options, so no
// database (and therefore no settings row) is involved.

error_reporting(E_ALL);

$orbitraTestFailures = 0;
function orbitraTestCheck(string $name, bool $condition): void
{
    global $orbitraTestFailures;
    if ($condition) {
        echo "  ok  {$name}\n";
    } else {
        $orbitraTestFailures++;
        echo "FAIL  {$name}\n";
    }
}

// --- Part 1: orbitraCrmIngestSignatureValid, extracted from index.php ---------

$indexSrc = (string) file_get_contents(dirname(__DIR__) . '/index.php');
if (preg_match('/function orbitraCrmIngestSignatureValid\(.*?^\}/ms', $indexSrc, $m) !== 1) {
    echo "FAIL  orbitraCrmIngestSignatureValid() not found in index.php\n";
    exit(1);
}
// The match is anchored to the function's own top-level closing brace: every
// interior line is indented, so ^\} can only end the declaration itself.
eval($m[0]);

$secret = 'unit-test-secret';
$body = json_encode(['name' => 'John Doe', 'phone' => '+1 555 0100', 'click_id' => 'abc']);
$sig = hash_hmac('sha256', $body, $secret);

orbitraTestCheck('valid signature accepted', orbitraCrmIngestSignatureValid($body, $secret, $sig));
orbitraTestCheck('uppercase hex accepted', orbitraCrmIngestSignatureValid($body, $secret, strtoupper($sig)));
orbitraTestCheck('whitespace-padded header accepted', orbitraCrmIngestSignatureValid($body, $secret, "  {$sig}\t\n"));
orbitraTestCheck('empty header rejected', orbitraCrmIngestSignatureValid($body, $secret, '') === false);
orbitraTestCheck('tampered body rejected', orbitraCrmIngestSignatureValid($body . ' ', $secret, $sig) === false);
orbitraTestCheck('wrong secret rejected', orbitraCrmIngestSignatureValid($body, 'other-secret', $sig) === false);
orbitraTestCheck('garbage header rejected', orbitraCrmIngestSignatureValid($body, $secret, str_repeat('0', 64)) === false);

// --- Part 2: CloakDetector relay short-circuit and VPN ASN gate ---------------

require_once dirname(__DIR__) . '/core/CloakDetector.php';

// The relay assertions need a populated snapshot; with the placeholder file
// they degrade to "feature off" notices instead of failing the suite.
$relayData = json_decode((string) file_get_contents(dirname(__DIR__) . '/core/data/icloud_private_relay.json'), true);
$relayRanges = is_array($relayData['ranges'] ?? null) ? $relayData['ranges'] : [];
$firstV4 = null;
$firstV6 = null;
foreach ($relayRanges as $cidr) {
    $host = explode('/', trim((string) $cidr), 2)[0];
    if ($firstV4 === null && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $firstV4 = $host;
    }
    if ($firstV6 === null && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $firstV6 = $host;
    }
}

if (empty($relayRanges)) {
    echo "SKIP  relay assertions (core/data/icloud_private_relay.json has no ranges yet)\n";
} else {
    orbitraTestCheck('relay range network is recognized', CloakDetector::isIcloudPrivateRelayIp((string) $firstV4));
    if ($firstV6 !== null) {
        orbitraTestCheck('relay IPv6 range is recognized', CloakDetector::isIcloudPrivateRelayIp((string) $firstV6));
    }
    orbitraTestCheck('ordinary address is not relay', !CloakDetector::isIcloudPrivateRelayIp('192.0.2.77'));
    orbitraTestCheck('garbage address is not relay', !CloakDetector::isIcloudPrivateRelayIp('not-an-ip'));

    $relayVisitor = [
        'ip' => (string) $firstV4,
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'asn' => 'AS16509', // AWS: inside datacenter_hosting AND inside cloud range lists
        'isp' => 'Amazon.com, Inc.',
        'accept_language' => 'en-US,en;q=0.9',
        'is_proxy' => 0,
        'proxy_type' => '',
        'proxy_threat' => '',
        'proxy_fraud_score' => null,
    ];
    $layer2 = ['detect_datacenter' => true, 'detect_vpn' => true, 'detect_bots' => false, 'detect_ua' => false];
    $relayVerdict = CloakDetector::detect($relayVisitor, $layer2 + ['sensitivity' => 'high']);
    $relayCodes = array_map(['CloakDetector', 'reasonCode'], $relayVerdict['reasons']);
    orbitraTestCheck('relay IP gets no datacenter_asn', !in_array('datacenter_asn', $relayCodes, true));
    orbitraTestCheck('relay IP gets no vpn_proxy_asn', !in_array('vpn_proxy_asn', $relayCodes, true));
    orbitraTestCheck('relay IP gets no iprange_datacenter', !in_array('iprange_datacenter', $relayCodes, true));
    orbitraTestCheck('relay IP verdict stays clean at high sensitivity', $relayVerdict['is_suspicious'] === false);
}

$plainVisitor = [
    'ip' => '192.0.2.77', // TEST-NET-1: no geo, no relay membership
    'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    'asn' => 'AS16509',
    'isp' => 'Example Connectivity LLC',
    'accept_language' => 'en-US,en;q=0.9',
    'is_proxy' => 0,
    'proxy_type' => '',
    'proxy_threat' => '',
    'proxy_fraud_score' => null,
];
$layer2 = ['detect_datacenter' => true, 'detect_vpn' => true, 'detect_bots' => false, 'detect_ua' => false];

$plainVerdict = CloakDetector::detect($plainVisitor, $layer2 + ['sensitivity' => 'high']);
$plainCodes = array_map(['CloakDetector', 'reasonCode'], $plainVerdict['reasons']);
orbitraTestCheck('datacenter ASN still fires for a non-relay IP', in_array('datacenter_asn', $plainCodes, true));

$vpnVisitor = array_merge($plainVisitor, ['asn' => 'AS9009']); // M247, vpn_proxy category
$vpnDefault = CloakDetector::detect($vpnVisitor, $layer2 + ['sensitivity' => 'high']);
$vpnDefaultCodes = array_map(['CloakDetector', 'reasonCode'], $vpnDefault['reasons']);
orbitraTestCheck('vpn_proxy_asn fires by default', in_array('vpn_proxy_asn', $vpnDefaultCodes, true));

// The "clean verdict" probe turns the datacenter layer off as well: the
// shipped cloud-range lists cover TEST-NET blocks (192.0.2.0/24 →
// iprange_datacenter), which would fire as an unrelated second signal and
// mask what this check isolates — the VPN-ASN gate.
$vpnOff = CloakDetector::detect($vpnVisitor, ['detect_datacenter' => false, 'detect_vpn' => true, 'detect_bots' => false, 'detect_ua' => false, 'sensitivity' => 'high', 'vpn_asn_signal' => false]);
$vpnOffCodes = array_map(['CloakDetector', 'reasonCode'], $vpnOff['reasons']);
orbitraTestCheck('vpn_proxy_asn silent with vpn_asn_signal=false', !in_array('vpn_proxy_asn', $vpnOffCodes, true));
orbitraTestCheck('vpn_asn_signal=false yields a clean verdict at high sensitivity', $vpnOff['is_suspicious'] === false);

$dcWithVpnOff = CloakDetector::detect($plainVisitor, $layer2 + ['sensitivity' => 'high', 'vpn_asn_signal' => false]);
$dcWithVpnOffCodes = array_map(['CloakDetector', 'reasonCode'], $dcWithVpnOff['reasons']);
orbitraTestCheck('datacenter ASN unaffected by vpn_asn_signal=false', in_array('datacenter_asn', $dcWithVpnOffCodes, true));

$botFilterOff = CloakDetector::detectBotFilter($vpnVisitor, ['vpn_asn_signal' => false]);
$botFilterOffCodes = array_map(['CloakDetector', 'reasonCode'], $botFilterOff['reasons']);
orbitraTestCheck('detectBotFilter honors the option override', !in_array('vpn_proxy_asn', $botFilterOffCodes, true));

echo $orbitraTestFailures === 0
    ? "\nAll checks passed.\n"
    : "\n{$orbitraTestFailures} check(s) FAILED.\n";
exit($orbitraTestFailures === 0 ? 0 : 1);
