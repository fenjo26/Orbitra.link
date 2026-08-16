<?php
/**
 * tests/traffic_source_templates_test.php
 *
 * Pins the hand-written builtin traffic-source templates in api.php. The
 * Keitaro-sourced packs under data/ are guarded by keitaro_templates_test.php;
 * the builtin list has no such export to check against, and it is exactly where
 * a plausible-looking but fabricated macro once shipped ({{site.name}} is not a
 * Meta parameter and substituted to nothing on every click). Extracting the
 * template block from the api.php source keeps this test independent of the
 * router's auth/DB bootstrap.
 */

$apiSource = (string) file_get_contents(__DIR__ . '/../api.php');
if ($apiSource === '') {
    fwrite(STDERR, 'api.php not readable' . PHP_EOL);
    exit(1);
}

// Slice the builtin facebook template out of the $templates array literal,
// dropping full-line comments so a note about a removed macro does not read
// as the macro itself.
$anchor = strpos($apiSource, "'name' => 'facebook'");
$block = $anchor === false ? '' : substr($apiSource, $anchor, 1600);
$block = preg_replace('/^\s*\/\/.*$/m', '', $block);

$failures = [];
$check = function (string $name, bool $condition) use (&$failures, &$passed) {
    if ($condition) {
        $passed++;
    } else {
        $failures[] = $name;
    }
};
$passed = 0;

$check('builtin facebook template exists', $anchor !== false);

// Every macro in the block must be one of Meta's documented dynamic URL
// parameters — anything else silently substitutes to an empty value.
$macros = [];
if (preg_match_all("/'macro' => '([^']+)'/", $block, $m)) {
    $macros = $m[1];
}
$check('facebook template carries 8 parameters', count($macros) === 8);

$officialMetaMacros = [
    '{{placement}}', '{{site_source_name}}',
    '{{campaign.id}}', '{{campaign.name}}',
    '{{adset.id}}', '{{adset.name}}',
    '{{ad.id}}', '{{ad.name}}',
];
$check(
    'all macros are official Meta parameters: ' . implode(' ', $macros),
    empty(array_diff($macros, $officialMetaMacros)) && count(array_diff($officialMetaMacros, $macros)) === 0
);
$check('fabricated {{site.name}} macro is gone', strpos($block, '{{site.name}}') === false);

// The placement parameter must round-trip into reports: captured under the
// utm_placement alias (ClickParams source-declared mapping) and groupable via
// the param_utm_placement report layer.
$check(
    'utm_placement param/alias pair present',
    strpos($block, "['alias' => 'utm_placement', 'param' => 'utm_placement', 'macro' => '{{placement}}']") !== false
);
$check(
    'platform param uses {{site_source_name}} under the source alias',
    strpos($block, "['alias' => 'source', 'param' => 'source', 'macro' => '{{site_source_name}}']") !== false
);

// Cost import keys survive: adset_id and ad_id stay declared as themselves.
$check('adset_id kept for cost import', strpos($block, "['alias' => 'adset_id', 'param' => 'adset_id', 'macro' => '{{adset.id}}']") !== false);
$check('ad_id kept for cost import', strpos($block, "['alias' => 'ad_id', 'param' => 'ad_id', 'macro' => '{{ad.id}}']") !== false);

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Builtin traffic-source template tests passed ($passed checks)." . PHP_EOL;
