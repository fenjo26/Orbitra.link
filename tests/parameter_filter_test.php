<?php
// tests/parameter_filter_test.php
//
// The Parameter stream filter (any URL parameter by name, "@empty" for a
// missing/blank one) — the shared matcher in core/StreamFilters.php, the
// Click API engine end to end, and the Keitaro import mapping.
//
// Run: php tests/parameter_filter_test.php

require_once __DIR__ . '/../core/click_api.php';
require_once __DIR__ . '/../core/keitaro_import.php';

$fails = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$fails) {
    if ($ok) {
        echo "ok   $label\n";
        return;
    }
    $fails++;
    echo "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
};

// --- matcher -----------------------------------------------------------------
$check('exact value matches', orbitraParameterFilterMatches(['__PLACEMENT__'], '__PLACEMENT__'));
$check('match is case-insensitive', orbitraParameterFilterMatches(['__placement__'], '__PLACEMENT__'));
$check('different value does not match', !orbitraParameterFilterMatches(['__PLACEMENT__'], 'PLACEMENT_TIKTOK'));
$check('no substring matching', !orbitraParameterFilterMatches(['PLACEMENT'], '__PLACEMENT__'));
$check('@empty matches empty', orbitraParameterFilterMatches(['@empty'], ''));
$check('@empty is case-insensitive', orbitraParameterFilterMatches(['@EMPTY'], ''));
$check('@empty does not match a value', !orbitraParameterFilterMatches(['@empty'], 'x'));
$check('a value never matches empty', !orbitraParameterFilterMatches(['__PLACEMENT__'], ''));
$check('wildcard prefix', orbitraParameterFilterMatches(['PLACEMENT_*'], 'PLACEMENT_TIKTOK'));
$check('wildcard anchored', !orbitraParameterFilterMatches(['PLACEMENT_*'], 'X_PLACEMENT_TIKTOK'));
$check('wildcard for any unreplaced macro', orbitraParameterFilterMatches(['__*__'], '__CSITE__'));
$check('regex chars are literal', !orbitraParameterFilterMatches(['a.c'], 'abc') && orbitraParameterFilterMatches(['a.c'], 'a.c'));
$check('blank tokens are ignored', !orbitraParameterFilterMatches(['', '  '], ''));

// --- value lookup ------------------------------------------------------------
$check('raw query is read by any name',
    orbitraParameterFilterValue('xxx', ['xxx' => ' __PLACEMENT__ '], []) === '__PLACEMENT__');
$check('missing parameter reads as empty', orbitraParameterFilterValue('xxx', ['other' => '1'], []) === '');
$check('click params are the fallback (source alias)',
    orbitraParameterFilterValue('sub_id_1', ['placement' => 'feed'], ['sub_id_1' => 'feed']) === 'feed');
$check('raw query wins over click params',
    orbitraParameterFilterValue('sub_id_1', ['sub_id_1' => 'raw'], ['sub_id_1' => 'alias']) === 'raw');
$check('names are case-sensitive like PHP keys', orbitraParameterFilterValue('XXX', ['xxx' => 'a'], []) === '');
$check('filter name is trimmed', orbitraParameterFilterName(['param' => ' xxx ']) === 'xxx');

// --- Click API engine, the article's setup ------------------------------------
// Intercepting stream: Parameter xxx IN (__PLACEMENT__, @empty) → white page.
$pdo = new PDO('sqlite::memory:');
$botCatch = ['filters_json' => json_encode([
    ['name' => 'Parameter', 'mode' => 'include', 'param' => 'xxx', 'payload' => ['__PLACEMENT__', '@empty']],
])];
$run = fn(array $stream, array $raw, array $click = []) => orbitraClickApiStreamMatchesFilters(
    $stream, '1.2.3.4', 'US', 'mobile', ['en'], 'UA', [], 'en', $pdo, $raw, $click
);
$check('unreplaced macro → caught', $run($botCatch, ['xxx' => '__PLACEMENT__']) === true);
$check('parameter missing → caught', $run($botCatch, []) === true);
$check('parameter blank → caught', $run($botCatch, ['xxx' => '']) === true);
$check('real ad click (macro replaced) → passes on', $run($botCatch, ['xxx' => 'PLACEMENT_TIKTOK']) === false);

// Exclude mode on the money stream does the same job the other way round.
$money = ['filters_json' => json_encode([
    ['name' => 'Parameter', 'mode' => 'exclude', 'param' => 'xxx', 'payload' => ['__PLACEMENT__', '@empty']],
])];
$check('exclude: real click allowed', $run($money, ['xxx' => 'PLACEMENT_PANGLE']) === true);
$check('exclude: macro rejected', $run($money, ['xxx' => '__PLACEMENT__']) === false);
$check('exclude: missing rejected', $run($money, []) === false);

// No parameter name → abstains (stream behaves as unfiltered), in both modes.
$noName = fn($mode) => ['filters_json' => json_encode([
    ['name' => 'Parameter', 'mode' => $mode, 'param' => '', 'payload' => ['x']],
])];
$check('no name, include → abstain', $run($noName('include'), []) === true);
$check('no name, exclude → abstain', $run($noName('exclude'), []) === true);

// Combines with other filters under AND / OR.
$and = ['filters_logic' => 'and', 'filters_json' => json_encode([
    ['name' => 'Country', 'mode' => 'include', 'payload' => ['US']],
    ['name' => 'Parameter', 'mode' => 'include', 'param' => 'sub_id_1', 'payload' => ['abc']],
])];
$check('AND: country + parameter both pass', $run($and, ['sub_id_1' => 'abc']) === true);
$check('AND: parameter fails', $run($and, ['sub_id_1' => 'zzz']) === false);
$check('AND: alias value via click params', $run($and, [], ['sub_id_1' => 'abc']) === true);

// --- Keitaro import ----------------------------------------------------------
$dropped = [];
$out = orbitraKeitaroBuildOrbitraFilters([
    ['name' => 'sub_id_1', 'mode' => 'accept', 'payload' => '["__PLACEMENT__","@empty"]'],
    ['name' => 'parameter', 'mode' => 'reject', 'payload' => '{"name":"xxx","values":["__PLACEMENT__"]}'],
    ['name' => 'parameter', 'mode' => 'accept', 'payload' => '[{"name":"a","value":"1"},{"name":"b","value":["2","3"]}]'],
    ['name' => 'parameter', 'mode' => 'accept', 'payload' => 'zone=1,2'],
    ['name' => 'parameter', 'mode' => 'accept', 'payload' => '[]'],
], $dropped);
$find = function (string $param) use ($out) {
    foreach ($out as $f) {
        if (($f['name'] ?? '') === 'Parameter' && ($f['param'] ?? '') === $param) return $f;
    }
    return null;
};
$f = $find('sub_id_1');
$check('sub_id_1 → Parameter sub_id_1 with @empty kept',
    $f && $f['mode'] === 'include' && $f['payload'] === ['__PLACEMENT__', '@empty'], json_encode($out));
$f = $find('xxx');
$check('parameter {name, values} → Parameter xxx, reject → exclude',
    $f && $f['mode'] === 'exclude' && $f['payload'] === ['__PLACEMENT__'], json_encode($out));
$check('parameter pair list → one filter per name',
    ($find('a')['payload'] ?? null) === ['1'] && ($find('b')['payload'] ?? null) === ['2', '3'], json_encode($out));
$check('parameter "name=v1,v2" → Parameter',
    ($find('zone')['payload'] ?? null) === ['1', '2'], json_encode($out));
$check('empty parameter payload is reported as dropped', ($dropped['parameter'] ?? 0) === 1, json_encode($dropped));
$check('mapped sub_id is not reported as dropped', !isset($dropped['sub_id_1']), json_encode($dropped));

if ($fails > 0) {
    echo "\n$fails FAILED\n";
    exit(1);
}
echo "\nAll parameter filter tests passed\n";
