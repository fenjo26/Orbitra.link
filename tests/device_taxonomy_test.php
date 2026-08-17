<?php

require_once __DIR__ . '/../core/Device.php';

$failures = [];
$assert = static function (string $label, $actual, $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = "$label: expected " . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

$detectionCases = [
    'iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1', 'Mobile'],
    'Android phone' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36', 'Mobile'],
    'feature phone' => ['Nokia feature phone WAP browser', 'Mobile'],
    'iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1', 'Tablet'],
    'iPadOS desktop UA' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1', 'Tablet'],
    'Android tablet' => ['Mozilla/5.0 (Linux; Android 13; SM-T870) AppleWebKit/537.36 Chrome/119.0 Safari/537.36', 'Tablet'],
    'Kindle Fire' => ['Mozilla/5.0 (Linux; U; en-US) AppleWebKit/535.19 Silk/3.13 Safari/535.19', 'Tablet'],
    'Windows desktop' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36', 'Desktop'],
    'Mac desktop' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 13_6) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15', 'Desktop'],
];

foreach ($detectionCases as $label => [$userAgent, $expected]) {
    $assert($label, orbitraDetectDeviceType($userAgent), $expected);
}

foreach (['mobile', 'smartphone', 'phablet', 'feature phone', 'phone'] as $alias) {
    $assert("normalize $alias", orbitraNormalizeDeviceGroup($alias), 'mobile');
}
foreach (['tablet', 'tab', 'ipad', 'kindle', 'playbook', 'silk'] as $alias) {
    $assert("normalize $alias", orbitraNormalizeDeviceGroup($alias), 'tablet');
}
foreach (['desktop', 'pc', 'mac', 'laptop', 'workstation', 'other'] as $alias) {
    $assert("normalize $alias", orbitraNormalizeDeviceGroup($alias), 'desktop');
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Device taxonomy tests passed (' . (count($detectionCases) + 17) . ' cases).' . PHP_EOL;
