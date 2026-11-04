<?php
// SEO-shield test matrix: search-bot verification (published IP lists,
// ASN-gated DNS fallback with a disk cache), the Chrome prefetch-proxy
// exemption (geofeed + Sec-Purpose), and the search-referrer soft signal.
//
// All feeds are FIXTURES: IpRanges::useDir() points at a temp directory so
// the assertions never depend on this install's real var/ipranges files or
// on the network. DNS resolvers are injected callables; the disk cache is a
// temp file. Everything is restored at shutdown.

require_once __DIR__ . '/../core/CloakDetector.php';
require_once __DIR__ . '/../core/IpRanges.php';
require_once __DIR__ . '/../core/prefetch.php';

$failures = [];
$cases = 0;

$browserUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
    . 'AppleWebKit/537.36 Chrome/127.0.0.0 Safari/537.36';
$gbUa = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

// ── Fixtures ───────────────────────────────────────────────────────────────
$tmpDir = sys_get_temp_dir() . '/orbitra_seo_shield_' . getmypid();
@mkdir($tmpDir, 0775, true);
file_put_contents($tmpDir . '/google_crawlers.txt', "66.249.64.0/23\n2001:4860:4801:10::/64\n");
file_put_contents($tmpDir . '/bing_crawlers.txt', "157.55.39.0/24\n");
file_put_contents(
    $tmpDir . '/prefetch_geofeed.txt',
    "162.120.184.0/28,IN\n162.120.185.0/29,DE\n"
);
$dnsCacheFile = $tmpDir . '/cloak_dns_verify.json';
IpRanges::useDir($tmpDir);
CloakDetector::useDnsCacheFile($dnsCacheFile);
register_shutdown_function(static function () use ($tmpDir, $dnsCacheFile): void {
    IpRanges::useDir(null);
    CloakDetector::useDnsCacheFile(null);
    @unlink($dnsCacheFile);
    @rmdir($tmpDir);
});

// Reset both verification caches between cases (per-process memoization
// would otherwise make tests order-dependent).
$verifyCacheProp = new ReflectionProperty(CloakDetector::class, 'searchBotVerifyCache');
$resetCaches = static function () use ($verifyCacheProp, $dnsCacheFile): void {
    $verifyCacheProp->setValue(null, []);
    CloakDetector::useDnsCacheFile($dnsCacheFile); // also resets the disk-cache copy
    @unlink($dnsCacheFile);
};

$visitor = static function (array $overrides = []) use ($browserUa): array {
    return array_merge([
        'ip' => '73.1.2.3',
        'user_agent' => $browserUa,
        'asn' => 'AS7922',
        'isp' => 'Comcast Cable Communications LLC',
        'accept_language' => 'en-US,en;q=0.9',
    ], $overrides);
};

$config = static function (array $overrides = []) use ($browserUa): array {
    return array_merge([
        'detect_datacenter' => true,
        'detect_vpn' => true,
        'detect_bots' => true,
        'detect_ua' => true,
        'sensitivity' => 'low',
    ], $overrides);
};

// DNS resolvers that log calls, so "no DNS was attempted" is assertable.
$mkResolvers = static function (array &$calls, ?string $ptr, array $forward): array {
    return [
        'dns_reverse' => static function ($ip) use (&$calls, $ptr) {
            $calls['reverse'] = ($calls['reverse'] ?? 0) + 1;
            return $ptr;
        },
        'dns_forward' => static function ($host, $family) use (&$calls, $forward) {
            $calls['forward'] = ($calls['forward'] ?? 0) + 1;
            return $forward;
        },
    ];
};

$code = static fn (string $reason): string => CloakDetector::reasonCode($reason);
$codes = static fn (array $result): array => array_map($code, $result['reasons']);

// ── 1. Verification by published IP lists ─────────────────────────────────
$cases++;
$v = CloakDetector::verifySearchBot(
    // Wrong ASN on purpose: a list hit is conclusive on its own, DNS must
    // not even be consulted (the resolvers below throw if called).
    $visitor(['ip' => '66.249.64.1', 'asn' => 'AS7922', 'dns_reverse' => static function () {
        throw new RuntimeException('DNS must not run when the IP list hit');
    }]),
    'googlebot'
);
if (!$v['verified'] || $v['method'] !== 'list' || $v['evidence'] !== '66.249.64.0/23') {
    $failures[] = 'google list hit must verify without DNS: ' . json_encode($v);
}

$cases++;
$v = CloakDetector::verifySearchBot($visitor(['ip' => '157.55.39.5', 'asn' => '']), 'bingbot');
if (!$v['verified'] || $v['method'] !== 'list') {
    $failures[] = 'bing list hit must verify with an empty ASN: ' . json_encode($v);
}

$cases++;
$v = CloakDetector::verifySearchBot(
    $visitor(['ip' => '66.249.64.2', 'user_agent' => 'Mozilla/5.0 (compatible; Google-InspectionTool; +https://search.google.com)']),
    CloakDetector::claimedSearchBot('Mozilla/5.0 (compatible; Google-InspectionTool; +https://search.google.com)')
);
if (!$v['verified'] || $v['method'] !== 'list') {
    $failures[] = 'Google-InspectionTool must verify through the google list: ' . json_encode($v);
}

// ── 2. Spoofed: no list hit, ASN not the engine's → no DNS at all ─────────
$cases++;
$calls = [];
$v = CloakDetector::verifySearchBot(
    $visitor(['ip' => '203.0.113.9'] + $mkResolvers($calls, 'crawl-66-249-64-1.googlebot.com', ['203.0.113.9'])),
    'googlebot'
);
if ($v['verified'] || $v['method'] !== 'asn' || !empty($calls)) {
    $failures[] = 'non-Google ASN must be spoofed without any DNS: ' . json_encode($v + ['dns_calls' => $calls]);
}

// ── 3. DNS fallback: engine ASN + PTR round-trip (Yandex has no list) ─────
$cases++;
$calls = [];
$v = CloakDetector::verifySearchBot(
    $visitor(['ip' => '5.255.253.20', 'asn' => 'AS13238'] + $mkResolvers($calls, ' spider-5-255-253-20.YANDEX.com. ', ['5.255.253.20'])),
    'yandexbot'
);
if (!$v['verified'] || $v['method'] !== 'dns' || $v['evidence'] !== 'spider-5-255-253-20.yandex.com') {
    $failures[] = 'Yandex PTR round-trip must verify via DNS: ' . json_encode($v);
}

$cases++;
$calls = [];
$v = CloakDetector::verifySearchBot(
    // Google IP not in the (fixture) list, but from Google's ASN: DNS decides.
    $visitor(['ip' => '66.249.99.99', 'asn' => 'AS15169'] + $mkResolvers($calls, 'crawl-66-249-99-99.googlebot.com', ['66.249.99.99'])),
    'googlebot'
);
if (!$v['verified'] || $v['method'] !== 'dns') {
    $failures[] = 'engine ASN + valid PTR must verify via DNS: ' . json_encode($v);
}

$cases++;
$calls = [];
$v = CloakDetector::verifySearchBot(
    // PTR fine but forward points elsewhere → spoofed (Google's procedure
    // requires the round trip). Distinct IP: the valid-PTR case above has
    // already cached 66.249.99.99 as verified.
    $visitor(['ip' => '66.249.97.97', 'asn' => 'AS15169'] + $mkResolvers($calls, 'crawl-66-249-97-97.googlebot.com', ['8.8.8.8'])),
    'googlebot'
);
if ($v['verified'] || $v['method'] !== 'dns') {
    $failures[] = 'forward-DNS mismatch must not verify: ' . json_encode($v);
}

// ── 4. The DNS disk cache survives a "process restart" ────────────────────
$cases++;
$calls = [];
$good = $visitor(['ip' => '66.249.99.99', 'asn' => 'AS15169'] + $mkResolvers($calls, 'crawl-66-249-99-99.googlebot.com', ['66.249.99.99']));
CloakDetector::verifySearchBot($good, 'googlebot');
// Simulate FPM wiping the statics: drop every per-process memo, keep the file.
$verifyCacheProp->setValue(null, []);
$badResolvers = $visitor(['ip' => '66.249.99.99', 'asn' => 'AS15169'] + $mkResolvers($calls, 'evil.example', []));
$v = CloakDetector::verifySearchBot($badResolvers, 'googlebot');
if (!$v['verified'] || $v['method'] !== 'dns-cache') {
    $failures[] = 'verified DNS verdict must be served from the disk cache: ' . json_encode($v);
}

$cases++;
// Negative answers are cached too: a spoofed Google-ASN hit must not get a
// second DNS pass once cached.
$calls = [];
$spoofResolvers = $visitor(['ip' => '66.249.98.98', 'asn' => 'AS15169'] + $mkResolvers($calls, 'not-google.example', []));
CloakDetector::verifySearchBot($spoofResolvers, 'googlebot');
$verifyCacheProp->setValue(null, []);
$truthyResolvers = $visitor(['ip' => '66.249.98.98', 'asn' => 'AS15169'] + $mkResolvers($calls, 'crawl-66-249-98-98.googlebot.com', ['66.249.98.98']));
$v = CloakDetector::verifySearchBot($truthyResolvers, 'googlebot');
if ($v['verified'] || $v['method'] !== 'dns-cache') {
    $failures[] = 'cached negative DNS verdict must not be re-resolved: ' . json_encode($v);
}

// ── 5. detect(): shield on / off through the whole layer stack ───────────
$cases++;
$resetCaches();
$r = CloakDetector::detect(
    $visitor(['ip' => '66.249.64.1', 'user_agent' => $gbUa, 'asn' => 'AS15169', 'isp' => 'Google LLC', 'accept_language' => '']),
    $config(['search_bot_money' => true])
);
if ($r['is_suspicious'] || empty($r['verified_search_bot']) || !in_array('verified_search_bot', $codes($r), true)) {
    $failures[] = 'verified googlebot must pass every layer with the flag: ' . json_encode($r);
}

$cases++;
$resetCaches();
$r = CloakDetector::detect(
    $visitor(['ip' => '203.0.113.9', 'user_agent' => $gbUa, 'asn' => 'AS7922', 'isp' => '', 'accept_language' => 'en-US']),
    $config(['search_bot_money' => true])
);
if (!$r['is_suspicious'] || !in_array('spoofed_search_bot', $codes($r), true) || !in_array('crawler_or_tool_ua', $codes($r), true)) {
    $failures[] = 'spoofed googlebot must be a hard signal alongside the UA reason: ' . json_encode($r);
}

// ── 6. Chrome prefetch proxy: geofeed + Sec-Purpose, nothing less ─────────
$prefetchIp = '162.120.184.1'; // fixture geofeed: 162.120.184.0/28, IN
$prefetchHeaders = ['sec_purpose' => 'Prefetch; anonymous-client-ip'];
$dchVisitor = static function (array $overrides = []) use ($prefetchIp, $browserUa): array {
    return array_merge([
        'ip' => $prefetchIp,
        'user_agent' => $browserUa,
        'asn' => 'AS15169',
        'isp' => 'Google LLC',
        'is_proxy' => 2,
        'proxy_type' => 'DCH',
        'accept_language' => 'en-US',
    ], $overrides);
};

$cases++;
if (CloakDetector::prefetchProxyCountry($dchVisitor($prefetchHeaders)) !== 'IN') {
    $failures[] = 'geofeed IP + Sec-Purpose must yield the geofeed country';
}

$cases++;
$r = CloakDetector::detect($dchVisitor($prefetchHeaders), $config(['sensitivity' => 'high']));
foreach (['datacenter_asn', 'iprange_datacenter', 'ip2proxy_datacenter', 'hosting_isp', 'ip2proxy_bot'] as $banned) {
    if (in_array($banned, $codes($r), true)) {
        $failures[] = "prefetch proxy request must not be flagged as {$banned}: " . implode(',', $r['reasons']);
    }
}

$cases++;
// Same IP, no header: this is some OTHER Google fetcher (e.g. the Translate
// proxy a moderator might use) — the datacenter verdicts must keep firing.
$r = CloakDetector::detect($dchVisitor(), $config(['sensitivity' => 'low']));
if (!in_array('ip2proxy_datacenter', $codes($r), true)) {
    $failures[] = 'geofeed IP without Sec-Purpose must stay a datacenter hit: ' . implode(',', $r['reasons']);
}

$cases++;
// Header but IP outside the geofeed: a plain visitor cannot talk the proxy
// into exempting itself (Sec-Purpose is unforgeable, but be explicit anyway).
$r = CloakDetector::detect(
    $dchVisitor(['ip' => '203.0.113.50'] + $prefetchHeaders),
    $config(['sensitivity' => 'low', 'iprange_hard_signal' => false])
);
if (in_array('verified_search_bot', $codes($r), true)) {
    $failures[] = 'Sec-Purpose alone must never verify anything';
}
if (CloakDetector::prefetchProxyCountry($dchVisitor(['ip' => '203.0.113.50'] + $prefetchHeaders)) !== null) {
    $failures[] = 'Sec-Purpose with an IP outside the geofeed must not match';
}

// ── 7. Search referrer: soft signal, opt-in ────────────────────────────────
$cases++;
foreach ([
    ['https://www.google.com/search?q=x', true],
    ['https://google.de/search?q=x', true],
    ['https://duckduckgo.com/?q=x', true],
    ['https://www.bing.com/search?q=x', true],
    ['', false],
    ['https://facebook.com/somepage', false],
    ['https://google.evil.com/search', false],
    ['not a url', false],
] as [$ref, $expected]) {
    if (CloakDetector::isSearchReferer($ref) !== $expected) {
        $failures[] = 'isSearchReferer(' . var_export($ref, true) . ') must be ' . var_export($expected, true);
    }
}

$cases++;
// Search referer → no reason. Direct → soft reason that ALONE passes medium
// but blocks high; with a second soft signal it blocks medium too.
$r = CloakDetector::detect(
    $visitor(['http_referer' => 'https://www.google.com/search?q=x']),
    $config(['search_referrer' => true, 'sensitivity' => 'high'])
);
if (in_array('non_search_referrer', $codes($r), true) || $r['is_suspicious']) {
    $failures[] = 'a search referer must not trip the rule: ' . implode(',', $r['reasons']);
}

$direct = $visitor(['http_referer' => '']);
$directCfgMedium = $config(['search_referrer' => true, 'sensitivity' => 'medium']);
$r = CloakDetector::detect($direct, $directCfgMedium);
if ($r['is_suspicious'] || !in_array('non_search_referrer', $codes($r), true)) {
    $failures[] = 'direct hit alone must stay a soft signal on medium: ' . json_encode($r);
}
$r = CloakDetector::detect($direct, $config(['search_referrer' => true, 'sensitivity' => 'high']));
if (!$r['is_suspicious'] || !in_array('non_search_referrer', $codes($r), true)) {
    $failures[] = 'direct hit must block on high sensitivity: ' . json_encode($r);
}
$r = CloakDetector::detect(
    $visitor(['http_referer' => '', 'accept_language' => '']),
    $directCfgMedium
);
if (!$r['is_suspicious'] || !in_array('non_search_referrer', $codes($r), true)) {
    $failures[] = 'direct + a second soft signal must block on medium: ' . json_encode($r);
}

$cases++;
// Off by default: no referer in the visitor context, no rule, no reason.
$r = CloakDetector::detect($visitor(), $config());
if (in_array('non_search_referrer', $codes($r), true)) {
    $failures[] = 'referrer rule must be off unless the stream opts in';
}

// ── 8. traffic-advice serving ──────────────────────────────────────────────
$cases++;
ob_start();
orbitraServeTrafficAdvice('1');
$body = (string) ob_get_clean();
if (trim($body) !== '[{"user_agent":"prefetch-proxy","disallow":true}]') {
    $failures[] = 'traffic-advice on must publish the disallow advice, got: ' . $body;
}
$cases++;
ob_start();
orbitraServeTrafficAdvice('0');
$body = (string) ob_get_clean();
if ($body !== '' || http_response_code() !== 404) {
    $failures[] = 'traffic-advice off must answer 404 with an empty body, got: ' . var_export($body, true);
}

// ── Result ────────────────────────────────────────────────────────────────
if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo 'Cloak SEO-shield tests passed (' . $cases . ' cases).' . PHP_EOL;
