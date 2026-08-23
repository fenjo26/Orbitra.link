<?php

require_once __DIR__ . '/../core/CloakDetector.php';
require_once __DIR__ . '/../core/IpRanges.php';

$browserUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
    . 'AppleWebKit/537.36 Chrome/127.0.0.0 Safari/537.36';

$visitor = static function (array $overrides = []) use ($browserUa): array {
    return array_merge([
        'ip' => '73.1.2.3',
        'user_agent' => $browserUa,
        'asn' => 'AS7922',
        'isp' => 'Comcast Cable Communications LLC',
        'accept_language' => 'en-US,en;q=0.9',
    ], $overrides);
};

$config = static function (string $sensitivity, array $overrides = []): array {
    return array_merge([
        'detect_datacenter' => true,
        'detect_vpn' => true,
        'detect_bots' => true,
        'detect_ua' => true,
        'sensitivity' => $sensitivity,
    ], $overrides);
};

$cases = [
    ['Comcast residential, low', $visitor(), $config('low'), false, []],
    ['Comcast residential, high', $visitor(), $config('high'), false, []],
    ['CloudMTS is not a hosting keyword', $visitor(['asn' => '', 'isp' => 'CloudMTS']), $config('high'), false, []],
    ['InterServer is not matched by generic server', $visitor(['asn' => '', 'isp' => 'InterServer Communications']), $config('high'), false, []],
    ['One soft signal passes low', $visitor(['accept_language' => '']), $config('low'), false, ['missing_accept_language']],
    ['One soft signal passes medium', $visitor(['accept_language' => '']), $config('medium'), false, ['missing_accept_language']],
    ['One soft signal blocks high', $visitor(['accept_language' => '']), $config('high'), true, ['missing_accept_language']],
    ['Two soft signals block medium', $visitor(['asn' => '', 'isp' => 'Hetzner Online', 'accept_language' => '']), $config('medium'), true, ['hosting_isp', 'missing_accept_language']],
    ['Datacenter ASN blocks low', $visitor(['asn' => 'AS14618', 'isp' => '']), $config('low'), true, ['datacenter_asn']],
    ['PX12 VPN blocks low', $visitor(['asn' => '', 'isp' => '', 'is_proxy' => 1, 'proxy_type' => 'VPN']), $config('low'), true, ['ip2proxy_vpn_proxy']],
    ['PX12 residential proxy blocks low', $visitor(['asn' => '', 'isp' => '', 'is_proxy' => 1, 'proxy_type' => 'RES']), $config('low'), true, ['ip2proxy_vpn_proxy']],
    ['PX12 datacenter blocks low', $visitor(['asn' => '', 'isp' => '', 'is_proxy' => 2, 'proxy_type' => 'DCH']), $config('low'), true, ['ip2proxy_datacenter']],
    ['PX12 crawler blocks low', $visitor(['asn' => '', 'isp' => '', 'is_proxy' => 2, 'proxy_type' => 'AIC']), $config('low'), true, ['ip2proxy_bot']],
    ['PX12 normal address passes', $visitor(['asn' => '', 'isp' => '', 'is_proxy' => 0, 'proxy_type' => '-']), $config('high'), false, []],
    ['Crawler UA blocks low', $visitor(['user_agent' => 'Googlebot/2.1']), $config('low'), true, ['crawler_or_tool_ua']],
    ['No UA blocks low', $visitor(['user_agent' => '', 'accept_language' => '']), $config('low'), true, ['no_user_agent']],
    ['Disabled layers do not block', $visitor(['user_agent' => 'Googlebot/2.1', 'asn' => 'AS14618']), $config('high', [
        'detect_datacenter' => 'false',
        'detect_vpn' => 'false',
        'detect_bots' => 'false',
        'detect_ua' => 'false',
    ]), false, []],
];

$failures = [];
foreach ($cases as [$name, $caseVisitor, $caseConfig, $expectedSuspicious, $expectedReasons]) {
    $result = CloakDetector::detect($caseVisitor, $caseConfig);
    if ((bool) $result['is_suspicious'] !== $expectedSuspicious) {
        $failures[] = "$name: expected suspicious=" . ($expectedSuspicious ? 'true' : 'false')
            . ', got ' . ($result['is_suspicious'] ? 'true' : 'false');
    }
    // Reasons carry ':evidence' suffixes (hosting_isp:ovh); assertions are
    // about which codes fire, so compare through reasonCode() like every
    // other consumer of these strings.
    $reasonCodes = array_map([CloakDetector::class, 'reasonCode'], $result['reasons']);
    foreach ($expectedReasons as $reason) {
        if (!in_array($reason, $reasonCodes, true)) {
            $failures[] = "$name: missing reason $reason";
        }
    }
}

$botFilterCases = [
    ['Bot filter passes residential traffic', $visitor(), false],
    ['Bot filter catches hosting ISP', $visitor(['asn' => '', 'isp' => 'Leaseweb USA Inc.']), true],
    ['Bot filter catches PX12 VPN', $visitor([
        'asn' => '',
        'isp' => '',
        'is_proxy' => 1,
        'proxy_type' => 'VPN',
    ]), true],
];
foreach ($botFilterCases as [$name, $caseVisitor, $expectedSuspicious]) {
    $result = CloakDetector::detectBotFilter($caseVisitor);
    if ((bool) $result['is_suspicious'] !== $expectedSuspicious) {
        $failures[] = "$name: expected suspicious=" . ($expectedSuspicious ? 'true' : 'false')
            . ', got ' . ($result['is_suspicious'] ? 'true' : 'false');
    }
}

// The detector must work outside index.php and ignore corrupted empty signatures.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE bot_ips (id INTEGER PRIMARY KEY, ip_or_cidr TEXT NOT NULL)');
$pdo->exec('CREATE TABLE bot_signatures (id INTEGER PRIMARY KEY, signature TEXT NOT NULL)');
$pdo->exec("INSERT INTO bot_signatures (signature) VALUES (''), ('   '), ('KnownBadBot')");
$GLOBALS['pdo'] = $pdo;

$emptySignatureResult = CloakDetector::detect($visitor(['asn' => '', 'isp' => '']), $config('low'));
if ($emptySignatureResult['is_suspicious']) {
    $failures[] = 'Empty bot signature matched a normal browser';
}

$botSignatureResult = CloakDetector::detect(
    $visitor(['asn' => '', 'isp' => '', 'user_agent' => 'Mozilla/5.0 KnownBadBot']),
    $config('low')
);
$botSignatureCodes = array_map([CloakDetector::class, 'reasonCode'], $botSignatureResult['reasons']);
if (!$botSignatureResult['is_suspicious'] || !in_array('bot_blocklist', $botSignatureCodes, true)) {
    $failures[] = 'Known bot signature was not detected outside index.php';
}
if (!in_array('bot_blocklist:KnownBadBot', $botSignatureResult['reasons'], true)) {
    $failures[] = 'bot_blocklist evidence must name the matched blocklist row, got: '
        . implode(',', $botSignatureResult['reasons']);
}
unset($GLOBALS['pdo']);

// --- Evidence format: a reason is `code:evidence`, readers split on the FIRST ':' ---
// Rows written before evidence existed stay valid bare codes.
if (CloakDetector::reasonCode('iprange_datacenter:2001:db8::/32') !== 'iprange_datacenter'
    || CloakDetector::reasonEvidence('iprange_datacenter:2001:db8::/32') !== '2001:db8::/32'
    || CloakDetector::reasonCode('crawler_or_tool_ua') !== 'crawler_or_tool_ua'
    || CloakDetector::reasonEvidence('crawler_or_tool_ua') !== '') {
    $failures[] = 'reasonCode/reasonEvidence must split on the first colon only';
}

// The tool signature that matched is the evidence — the actionable part, and it
// cannot bloat the row the way a full UA would.
$curlDetect = CloakDetector::detect(
    ['ip' => '73.1.2.3', 'user_agent' => 'curl/8.4.0', 'asn' => '', 'isp' => '', 'accept_language' => ''],
    ['sensitivity' => 'low']
);
if (!in_array('crawler_or_tool_ua:curl/', $curlDetect['reasons'], true)) {
    $failures[] = 'curl/8.4.0 must record crawler_or_tool_ua:curl/ exactly, got: ' . implode(',', $curlDetect['reasons']);
}

// The matched CIDR is the iprange_datacenter evidence. Self-adjusting: probes
// an address inside the first usable range of whatever list is installed, so
// the assertion survives daily list refreshes. Skipped when lists were never
// downloaded (the layer is inactive in that install).
if (is_readable(IpRanges::fileV4())) {
    $probeLine = null;
    foreach (file(IpRanges::fileV4(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        [$net, $prefix] = array_pad(explode('/', $line, 2), 2, '32');
        if ((int) $prefix <= 30 && ip2long($net) !== false) {
            $probeLine = $line;
            $probeIp = long2ip(ip2long($net) + 1);
            break;
        }
    }
    if ($probeLine !== null) {
        $iprangeDetect = CloakDetector::detect(
            ['ip' => $probeIp, 'user_agent' => $browserUa, 'asn' => '', 'isp' => '', 'accept_language' => 'en-US'],
            ['sensitivity' => 'low']
        );
        if (!in_array('iprange_datacenter:' . $probeLine, $iprangeDetect['reasons'], true)) {
            $failures[] = "iprange_datacenter evidence must be the matched CIDR ({$probeLine}), got: "
                . implode(',', $iprangeDetect['reasons']);
        }
    }
}

// Evidence is capped at 64 chars so a long keyword cannot bloat the
// clicks.cloak_reasons row.
$longKeyword = str_repeat('a', 100);
$ispEvidence = CloakDetector::targetingReasons(
    ['block_bot_isps' => true, 'custom_bot_isps' => $longKeyword],
    'US',
    'Desktop',
    $longKeyword,
    ''
);
$expectedCapped = 'bot_isp:' . str_repeat('a', 64);
if (!in_array($expectedCapped, $ispEvidence, true)) {
    $failures[] = 'bot_isp evidence must be capped at 64 chars, got: ' . implode(',', $ispEvidence);
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'CloakDetector tests passed (' . (count($cases) + count($botFilterCases) + 2) . ' cases).' . PHP_EOL;
