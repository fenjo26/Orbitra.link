<?php

require_once __DIR__ . '/../core/CloakDetector.php';

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
    foreach ($expectedReasons as $reason) {
        if (!in_array($reason, $result['reasons'], true)) {
            $failures[] = "$name: missing reason $reason";
        }
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
if (!$botSignatureResult['is_suspicious'] || !in_array('bot_blocklist', $botSignatureResult['reasons'], true)) {
    $failures[] = 'Known bot signature was not detected outside index.php';
}
unset($GLOBALS['pdo']);

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'CloakDetector tests passed (' . (count($cases) + 2) . ' cases).' . PHP_EOL;
