<?php
// tests/click_api_filter_parity_test.php
//
// The Click API (core/click_api.php — KClient JS/PHP, Tracking Script,
// banners) routes a click through the same stream filters as the redirect
// router (index.php).
//
// The Click API carried its own copy of the matcher, and that copy answered
// "matched" for every filter it did not implement: OS, Browser, IP, ISP,
// Keyword, Referer, Weekday and Time. A stream with "OS = iOS" therefore took
// Windows visitors, one with "IP = 10.0.0.*" took every address — and the same
// campaign routed differently depending on which entry point the visitor used.
//
// Each case pins the expected verdict and compares it with the router's own
// matcher on the same visitor.
//
// Run: php tests/click_api_filter_parity_test.php

require_once __DIR__ . '/../core/click_api.php';
require_once __DIR__ . '/../core/stream_match.php';

$fails = 0;
$check = function (string $label, $got, $expected) use (&$fails): void {
    if ($got !== $expected) {
        $fails++;
        echo "FAIL {$label}: got " . var_export($got, true) . ', expected ' . var_export($expected, true) . "\n";
        return;
    }
    echo "ok  {$label}\n";
};

$pdo = new PDO('sqlite::memory:');
$windows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1';
$tiktok = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1 musical_ly_2022803040 JsSdk/2.0 NetType/WIFI Channel/App Store ByteLocale/en';
$base = static fn (array $extra = []) => array_merge([
    'ip' => '73.1.2.3',
    'country' => 'US',
    'deviceType' => 'Desktop',
    'ua' => $windows,
    'langs' => ['en'],
    'accept' => 'en-US,en;q=0.9',
    'geo' => ['asn' => 'AS7922', 'isp' => 'Comcast Cable', 'is_proxy' => 0, 'proxy_type' => '',
              'connection_type' => 'wifi', 'timezone' => 'America/New_York'],
    'raw' => [],
    'params' => [],
], $extra);

/** The same visitor in the shape index.php hands to streamMatchesFilters(). */
$routerVisitor = static function (array $c): array {
    return [
        'ip' => $c['ip'], 'country' => $c['country'], 'device' => $c['deviceType'],
        'os' => orbitraUaOsFamily($c['ua']), 'browser' => orbitraUaBrowserFamily($c['ua']),
        'languageCodes' => $c['langs'], 'userAgent' => $c['ua'],
        'referer' => orbitraCloakVisitorHeadersFromParams($c['raw'])['http_referer'],
        'params' => $c['params'], 'rawParams' => $c['raw'],
        'timezone' => (string) ($c['geo']['timezone'] ?? ''),
        'isp' => (string) ($c['geo']['isp'] ?? ''), 'asn' => (string) ($c['geo']['asn'] ?? ''),
        'carrier' => (string) ($c['geo']['carrier'] ?? ''),
        'connectionType' => (string) ($c['geo']['connection_type'] ?? ''),
        'isProxy' => (int) ($c['geo']['is_proxy'] ?? 0),
        'proxyType' => (string) ($c['geo']['proxy_type'] ?? ''),
        'proxyThreat' => (string) ($c['geo']['proxy_threat'] ?? ''),
        'proxyProvider' => (string) ($c['geo']['proxy_provider'] ?? ''),
        'proxyFraudScore' => $c['geo']['proxy_fraud_score'] ?? null,
        'acceptLanguage' => $c['accept'],
        'acceptLanguageUnknown' => orbitraClickApiAcceptLanguageUnknown(),
    ];
};

$run = function (string $label, array $filters, bool $expected, array $extra = [], string $logic = 'and', array $server = []) use ($check, $pdo, $base, $routerVisitor): void {
    $c = $base($extra);
    $stream = ['id' => 5, 'filters_json' => json_encode($filters), 'filters_logic' => $logic];
    $_GET = [];
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $server['accept_header'] ?? 'en-US,en;q=0.9';
    if (array_key_exists('language_param', $server)) {
        $_GET['language'] = $server['language_param'];
    }
    if ($server['no_header'] ?? false) {
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }
    $api = orbitraClickApiStreamMatchesFilters($stream, $c['ip'], $c['country'], $c['deviceType'], $c['langs'], $c['ua'],
        $c['geo'], $c['accept'], $pdo, $c['raw'], $c['params']);
    $router = streamMatchesFilters($stream, $routerVisitor($c), $pdo);
    $check($label, [$api, $api === $router], [$expected, true]);
};
$f = static fn (string $name, string $mode, array $payload, array $extra = []): array => array_merge(['name' => $name, 'mode' => $mode, 'payload' => $payload], $extra);

// --- the filters the old copy answered "matched" for, whatever the visitor ---
$run('OS = iOS does not take a Windows visitor', [$f('OS', 'include', ['iOS'])], false);
$run('OS = windows takes a Windows visitor (any case)', [$f('OS', 'include', ['windows'])], true);
$run('Browser = Firefox does not take Chrome', [$f('Browser', 'include', ['Firefox'])], false);
$run('Browser = TikTok takes an in-app TikTok visit', [$f('Browser', 'include', ['tiktok'])], true, ['ua' => $tiktok, 'deviceType' => 'Mobile']);
$run('IP = 10.0.0.* does not take 73.1.2.3', [$f('IP', 'include', ['10.0.0.*'])], false);
$run('IP = 73.1.2.* takes 73.1.2.3', [$f('IP', 'include', ['73.1.2.*'])], true);
$run('IP = 73.1.0.0/16 takes 73.1.2.3', [$f('IP', 'include', ['73.1.0.0/16'])], true);
$run('IP exclude 73.1.2.* turns the visitor away', [$f('IP', 'exclude', ['73.1.2.*'])], false);
$run('ISP = hetzner does not take Comcast', [$f('ISP', 'include', ['hetzner'])], false);
$run('ISP = comcast takes "Comcast Cable" (any case)', [$f('ISP', 'include', ['comcast'])], true);
$run('Connection = mobile does not take wifi', [$f('Connection', 'include', ['mobile'])], false);
$run('Connection = cable/wifi takes wifi', [$f('Connection', 'include', ['cable'])], true);
$run('Referer = google.com does not take a direct visit', [$f('Referer', 'include', ['google.com'])], false);
$run('Referer = google.com takes a search visit forwarded as se_referrer', [$f('Referer', 'include', ['google.com'])], true,
    ['raw' => ['se_referrer' => 'https://www.google.com/search?q=x']]);
$run('Referer = @empty takes a direct visit', [$f('Referer', 'include', ['@empty'])], true);
$run('Keyword = shoes does not take a click without it', [$f('Keyword', 'include', ['shoes'])], false);
$run('Keyword = shoes takes a click that carries it', [$f('Keyword', 'include', ['shoes'])], true, ['params' => ['keyword' => 'red shoes']]);
$run('Time = 0-23 takes any hour', [$f('Time', 'include', ['0-23'])], true);
$run('Time = 25-26 is not a range and matches nobody', [$f('Time', 'include', ['25-26'])], false);
$allWeekdays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
$run('Weekday: all seven match', [$f('Weekday', 'include', $allWeekdays)], true);
$tomorrow = strtolower((new DateTime('now', new DateTimeZone('America/New_York')))->modify('+1 day')->format('l'));
$run("Weekday = {$tomorrow} does not match today (in the visitor's time zone)", [$f('Weekday', 'include', [$tomorrow])], false);

// --- what the copy did implement, now with the router's rules ------------------
$run('Country = us takes US (any case)', [$f('Country', 'include', ['us'])], true);
$run('Country exclude US turns a US visitor away', [$f('Country', 'exclude', ['US'])], false);
$run('Country = DE does not take US', [$f('Country', 'include', ['DE'])], false);
$run('Device = smartphone takes an iPhone', [$f('Device', 'include', ['smartphone'])], true, ['ua' => $iphone, 'deviceType' => 'Mobile']);
$run('Parameter sub_id_1 = abc', [$f('Parameter', 'include', ['abc'], ['param' => 'sub_id_1'])], true, ['raw' => ['sub_id_1' => 'abc']]);
$run('Parameter sub_id_1 = abc does not take another value', [$f('Parameter', 'include', ['abc'], ['param' => 'sub_id_1'])], false, ['raw' => ['sub_id_1' => 'xyz']]);
$run('Parameter sub_id_1 = @empty takes a click without it', [$f('Parameter', 'include', ['@empty'], ['param' => 'sub_id_1'])], true);
$run('Bot = yes (low) lets a clean visitor through', [$f('Bot', 'include', ['yes'], ['sensitivity' => 'low'])], false);
$run('Bot = yes (low) takes curl', [$f('Bot', 'include', ['yes'], ['sensitivity' => 'low'])], true, ['ua' => 'curl/8.4.0']);

// An old client never sent the language key: an empty Accept-Language there is
// the client server's, not the visitor's, and is no bot signal.
$run('Bot = yes (high): no language header from an old client is not a signal', [$f('Bot', 'include', ['yes'], ['sensitivity' => 'high'])], false,
    ['accept' => ''], 'and', ['no_header' => true]);
$run('Bot = yes (high): a visitor whose browser sent no language is a signal', [$f('Bot', 'include', ['yes'], ['sensitivity' => 'high'])], true,
    ['accept' => ''], 'and', ['language_param' => '']);

// --- combination and abstention ------------------------------------------------
$run('OR: one of two filters is enough', [$f('Country', 'include', ['DE']), $f('OS', 'include', ['Windows'])], true, [], 'or');
$run('OR: neither filter matches', [$f('Country', 'include', ['DE']), $f('OS', 'include', ['iOS'])], false, [], 'or');
$run('AND: both must match', [$f('Country', 'include', ['US']), $f('OS', 'include', ['Windows'])], true);
$run('a filter without a payload abstains', [['name' => 'OS', 'mode' => 'include', 'payload' => []]], true);
$run('an unknown filter type abstains', [$f('Uniqueness', 'include', ['yes'])], true);

if ($fails) {
    echo "\n{$fails} failure(s)\n";
    exit(1);
}
echo "\nall passed\n";
