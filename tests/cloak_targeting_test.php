<?php

require_once __DIR__ . '/../core/CloakDetector.php';

$failures = [];

// CloakDetector::targetingReasons() — the quick filters on the cloak card.
// Each case: [targeting override, country, device, "isp asn", global list, expected reasons].
$cases = [
    'no filters configured -> nothing to say'        => [[], 'US', 'Desktop', 'Comcast AS7922', '', []],
    'geo allow + visitor in list'                    => [['countries' => 'US, CA'], 'US', 'Desktop', '', '', []],
    'geo allow + visitor outside list'               => [['countries' => 'US, CA'], 'DE', 'Desktop', '', '', ['geo_country']],
    'geo deny + visitor in list'                     => [['countries' => 'US', 'geo_mode' => 'deny'], 'US', 'Desktop', '', '', ['geo_country']],
    'geo deny + visitor outside list'                => [['countries' => 'US', 'geo_mode' => 'deny'], 'DE', 'Desktop', '', '', []],
    'geo accepts array form too'                     => [['countries' => ['us', 'ca']], 'CA', 'Desktop', '', '', []],
    'geo match is case-insensitive'                  => [['countries' => 'us'], 'US', 'Desktop', '', '', []],
    'empty geo list disables the filter'             => [['countries' => ''], 'DE', 'Desktop', '', '', []],

    'device allow + matching type'                   => [['devices' => 'mobile, desktop'], 'US', 'Mobile', '', '', []],
    'device allow + other type'                      => [['devices' => 'mobile'], 'US', 'Desktop', '', '', ['device_type']],
    'device deny + listed type'                      => [['devices' => 'desktop', 'device_mode' => 'deny'], 'US', 'Desktop', '', '', ['device_type']],
    'device deny + unlisted type'                    => [['devices' => 'desktop', 'device_mode' => 'deny'], 'US', 'Mobile', '', '', []],
    'device comparison lowercases both sides'        => [['devices' => ['MOBILE']], 'US', 'Mobile', '', '', []],
    'empty device list disables the filter'          => [['devices' => ''], 'US', 'Desktop', '', '', []],
    'mobile filter matches smartphone'               => [['devices' => 'mobile'], 'US', 'smartphone', '', '', []],
    'mobile filter matches phablet'                  => [['devices' => 'mobile'], 'US', 'phablet', '', '', []],
    'mobile filter matches feature phone'            => [['devices' => 'mobile'], 'US', 'feature phone', '', '', []],
    'feature phone target stays one mobile alias'    => [['devices' => 'feature phone'], 'US', 'Mobile', '', '', []],
    'feature phone target does not allow desktop'    => [['devices' => 'feature phone'], 'US', 'Desktop', '', '', ['device_type']],
    'tablet filter matches tablet'                   => [['devices' => 'tablet'], 'US', 'Tablet', '', '', []],
    'tablet filter matches iPad'                     => [['devices' => 'tablet'], 'US', 'iPad', '', '', []],
    'mobile filter blocks tablet'                    => [['devices' => 'mobile'], 'US', 'Tablet', '', '', ['device_type']],
    'tablet filter blocks desktop'                   => [['devices' => 'tablet'], 'US', 'Desktop', '', '', ['device_type']],

    'isp hit from the global list'                   => [['block_bot_isps' => true], 'US', 'Desktop', 'AS32934 Facebook, Inc.', 'facebook, hetzner', ['bot_isp']],
    'isp keyword matched inside ASN string'          => [[], 'US', 'Desktop', 'Hetzner Online GmbH AS24940', 'facebook, hetzner', ['bot_isp']],
    'clean residential isp stays clean'              => [[], 'US', 'Desktop', 'Comcast Cable AS7922', 'facebook, hetzner', []],
    'word boundary: meta must not hit Metronet'      => [[], 'US', 'Desktop', 'Metronet AS', 'meta', []],
    'word boundary: aws must not hit Lawson'         => [[], 'US', 'Desktop', 'Lawson Communications', 'aws', []],
    'multi-word keyword matches as a phrase'         => [[], 'US', 'Desktop', 'Amazon Web Services AS16509', 'amazon web services', ['bot_isp']],
    'local override replaces the global list'        => [['custom_bot_isps' => 'ovh'], 'US', 'Desktop', 'OVH SAS AS16276', 'facebook, hetzner', ['bot_isp']],
    'local override hides global entries'            => [['custom_bot_isps' => 'ovh'], 'US', 'Desktop', 'Facebook Inc.', 'facebook, hetzner', []],
    'block_bot_isps=false disables the layer'        => [['block_bot_isps' => false], 'US', 'Desktop', 'Hetzner Online', 'hetzner', []],
    'isp default is ON when unset'                   => [[], 'US', 'Desktop', 'Hetzner Online', 'hetzner', ['bot_isp']],
    'empty haystack never matches'                   => [[], 'US', 'Desktop', '  ', 'hetzner', []],
    'empty global list never matches'                => [[], 'US', 'Desktop', 'Hetzner Online', '', []],

    'reasons accumulate across filters'              => [['countries' => 'US', 'devices' => 'mobile'], 'DE', 'Desktop', 'Facebook Inc.', 'facebook', ['geo_country', 'device_type', 'bot_isp']],
];

foreach ($cases as $name => [$targeting, $country, $device, $isp, $globalList, $expected]) {
    $got = CloakDetector::targetingReasons($targeting, $country, $device, $isp, $globalList);
    if ($got !== $expected) {
        $failures[] = "$name: expected [" . implode(', ', $expected) . '], got [' . implode(', ', $got) . ']';
    }
}

$clickPolicyCases = [
    'money page is always recorded' => [[], false, false],
    'missing option defaults to skipping safe clicks' => [[], true, true],
    'explicit true skips safe clicks' => [['dont_record_safe_clicks' => true], true, true],
    'string true skips safe clicks' => [['dont_record_safe_clicks' => 'true'], true, true],
    'explicit false records safe clicks' => [['dont_record_safe_clicks' => false], true, false],
    'string false records safe clicks' => [['dont_record_safe_clicks' => 'false'], true, false],
];

foreach ($clickPolicyCases as $name => [$config, $showSafe, $expected]) {
    $got = CloakDetector::shouldSkipSafePageClick($config, $showSafe);
    if ($got !== $expected) {
        $failures[] = "$name: expected " . ($expected ? 'true' : 'false') . ', got ' . ($got ? 'true' : 'false');
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Cloak targeting and click policy tests passed (' . (count($cases) + count($clickPolicyCases)) . ' cases).' . PHP_EOL;
