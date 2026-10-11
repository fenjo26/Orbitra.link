<?php

require_once __DIR__ . '/../core/StreamFilters.php';

$failures = [];

// orbitraLanguageFilterMatches(payload, raw Accept-Language) — the Language
// stream filter. A bare language matches every region of it; a tag with a
// region matches only that region; position in the header does not matter.
$cases = [
    'en matches en-US'                         => [['en'], 'en-US,en;q=0.9', true],
    'en matches en-IN'                         => [['en'], 'en-IN,hi;q=0.8', true],
    'en-IN matches en-IN first'                => [['en-IN'], 'en-IN,en-US;q=0.9', true],
    'en-IN matches en-IN listed later'         => [['en-IN'], 'en-US,en-IN;q=0.5', true],
    'en-IN does not match en-US'               => [['en-IN'], 'en-US,en;q=0.9', false],
    'en-IN does not match bare en'             => [['en-in'], 'en', false],
    'case and underscore are normalised'       => [['EN_in'], 'en-in', true],
    'hi listed later still matches'            => [['hi'], 'en-IN,hi;q=0.8', true],
    'other language does not match'            => [['de'], 'en-US,en;q=0.9', false],
    'empty header never matches'               => [['en'], '', false],
    'wildcard header never matches'            => [['en'], '*', false],
    'q weights are ignored'                    => [['ru'], 'ru;q=0.1', true],
    'script subtag prefix matches'             => [['zh-Hant'], 'zh-Hant-TW', true],
    'prefix must end on a subtag boundary'     => [['en-u'], 'en-us', false],
    'any value in a list is enough'            => [['de', 'en-GB'], 'en-GB', true],
    'junk payload values are skipped'          => [['', '*', 'en'], 'en-US', true],
];

foreach ($cases as $name => [$payload, $raw, $expected]) {
    $got = orbitraLanguageFilterMatches($payload, $raw);
    if ($got !== $expected) {
        $failures[] = "$name: expected " . var_export($expected, true) . ', got ' . var_export($got, true);
    }
}

$tags = orbitraAcceptLanguageTags('en-IN, en-US;q=0.9, en-IN;q=0.1, *, hi;q=0.8');
if ($tags !== ['en-in', 'en-us', 'hi']) {
    $failures[] = 'tag list: got ' . json_encode($tags);
}

if ($failures) {
    echo "FAILED:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo 'OK: ' . (count($cases) + 1) . " language filter checks passed\n";
