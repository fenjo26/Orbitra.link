<?php
/**
 * core/UserAgent.php — the stored families (click path) and the full
 * breakdown shown in click details.
 *
 * Pins the iOS bug this file was written for: Chrome on iPhone sends CriOS/,
 * not Chrome/, and used to be stored as Safari.
 *
 * Usage: php tests/user_agent_parse_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../core/Device.php';
require_once __DIR__ . '/../core/UserAgent.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}
function expectUa(string $label, string $ua, array $expected): void
{
    $got = orbitraParseUserAgent($ua);
    foreach ($expected as $k => $v) {
        check(($got[$k] ?? null) === $v, "$label: $k = " . var_export($v, true) . ' (got ' . var_export($got[$k] ?? null, true) . ')');
    }
}

// --- stored families --------------------------------------------------------
$families = [
    ['Chrome iOS', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_6_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/154.0.8037.55 Mobile/15E148 Safari/604.1', 'iOS', 'Chrome'],
    ['Firefox iOS', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/124.0 Mobile/15E148 Safari/605.1.15', 'iOS', 'Firefox'],
    ['Edge iOS', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 EdgiOS/123.0 Mobile/15E148 Safari/605.1.15', 'iOS', 'Edge'],
    ['Safari iOS', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'iOS', 'Safari'],
    ['Chrome Mac', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', 'macOS', 'Chrome'],
    ['Edge Win', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0', 'Windows', 'Edge'],
    ['Samsung', 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36', 'Android', 'Samsung Browser'],
    ['bios is not iOS', 'SomeTool/1.0 (bios-check)', 'Unknown', 'Unknown'],
];
foreach ($families as [$label, $ua, $os, $browser]) {
    check(orbitraUaOsFamily($ua) === $os, "$label: os family $os");
    check(orbitraUaBrowserFamily($ua) === $browser, "$label: browser family $browser");
}

// --- full breakdown ---------------------------------------------------------
expectUa('Keitaro sample (Chrome on iPhone)', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_6_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/154.0.8037.55 Mobile/15E148 Safari/604.1', [
    'os' => 'iOS', 'os_version' => '26.6.2', 'browser_name' => 'Chrome (iOS)', 'browser_version' => '154.0.8037.55',
    'device_type' => 'Mobile', 'device_brand' => 'Apple', 'device_model' => 'iPhone', 'bot' => '', 'in_app' => '',
]);
expectUa('Chrome on Mac', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', [
    'os' => 'macOS', 'os_version' => '10.15.7', 'browser_name' => 'Chrome', 'browser_version' => '154.0.0.0',
    'device_type' => 'Desktop', 'device_model' => 'Mac', 'bot' => '',
]);
expectUa('Windows Chrome', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', [
    'os' => 'Windows', 'os_version' => '10/11', 'browser_name' => 'Chrome', 'device_model' => '',
]);
expectUa('Firefox Mac', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 26.5; rv:157.0) Gecko/20100101 Firefox/157.0', [
    'os' => 'macOS', 'os_version' => '26.5', 'browser_name' => 'Firefox', 'browser_version' => '157.0',
]);
expectUa('Samsung phone, Chrome', 'Mozilla/5.0 (Linux; Android 14; SM-S918B Build/UP1A.231005.007) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.6723.102 Mobile Safari/537.36', [
    'os' => 'Android', 'os_version' => '14', 'browser_name' => 'Chrome Mobile', 'browser_version' => '130.0.6723.102',
    'device_brand' => 'Samsung', 'device_model' => 'SM-S918B', 'device_type' => 'Mobile',
]);
expectUa('Reduced Android UA (model K)', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', [
    'os_version' => '10', 'device_model' => '', 'device_brand' => '',
]);
expectUa('Facebook in-app Android', 'Mozilla/5.0 (Linux; Android 13; Pixel 7 Build/TQ3A.230805.001; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120.0.6099.144 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/446.0.0.38.112;]', [
    'in_app' => 'Facebook', 'browser_name' => 'Chrome WebView', 'device_brand' => 'Google', 'device_model' => 'Pixel 7', 'browser' => 'Chrome',
]);
expectUa('Instagram in-app iOS', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 330.0.0.27.89 (iPhone15,3; iOS 17_5; en_US; en; scale=3.00; 1290x2796; 600947034)', [
    'in_app' => 'Instagram', 'browser_name' => 'WebView', 'os_version' => '17.5', 'device_model' => 'iPhone',
]);
expectUa('TikTok in-app', 'Mozilla/5.0 (Linux; Android 12; RMX3363 Build/RKQ1.211119.001; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/112.0.5615.136 Mobile Safari/537.36 trill_2023109040 JsSdk/1.0 NetType/WIFI Channel/googleplay AppName/musical_ly app_version/31.9.4 ByteLocale/en', [
    'in_app' => 'TikTok', 'device_brand' => 'realme', 'device_model' => 'RMX3363',
]);
expectUa('iPad', 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', [
    'os_name' => 'iPadOS', 'os_version' => '17.0', 'device_model' => 'iPad', 'device_type' => 'Tablet', 'browser_name' => 'Safari',
]);
expectUa('bingbot', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36', [
    'bot' => 'bingbot', 'device_type' => 'Bot',
]);
expectUa('MJ12bot', 'Mozilla/5.0 (compatible; MJ12bot/v1.4.8; http://mj12bot.com/)', ['bot' => 'MJ12bot']);
expectUa('Barkrowler (no bot/crawler suffix)', 'Mozilla/5.0 (compatible; Barkrowler/0.9; +https://babbar.tech/crawler)', ['bot' => 'Barkrowler']);
expectUa('unknown crawler', 'Mozilla/5.0 (compatible; SuperFooCrawler/3.1; +https://foo.example)', ['bot' => 'SuperFooCrawler']);
expectUa('curl', 'curl/8.5.0', ['bot' => 'curl', 'browser_name' => 'curl', 'browser_version' => '8.5.0']);
expectUa('Cubot phone is not a bot', 'Mozilla/5.0 (Linux; Android 11; CUBOT X50) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36', ['bot' => '']);
expectUa('empty', '', ['bot' => '', 'browser_name' => '', 'os' => 'Unknown']);

echo $failures === 0 ? "\nAll user-agent checks passed\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
