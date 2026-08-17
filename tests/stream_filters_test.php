<?php
// tests/stream_filters_test.php
//
// AND/OR stream-filter combination: the shared combiner
// (core/StreamFilters.php) every matching engine routes through, plus an
// end-to-end run of the Click API matcher with both logics.
//
// Run: php tests/stream_filters_test.php

require_once __DIR__ . '/../core/click_api.php';

$assert = function (string $label, $got, $expected) {
    if ($got !== $expected) {
        echo "FAIL $label: got " . var_export($got, true) . ", expected " . var_export($expected, true) . "\n";
        exit(1);
    }
    echo "ok  $label = " . var_export($got, true) . "\n";
};

// --- orbitraStreamFilterLogic: normalization ---------------------------------
$assert("logic default", orbitraStreamFilterLogic([]), 'and');
$assert("logic 'or'", orbitraStreamFilterLogic(['filters_logic' => 'or']), 'or');
$assert("logic 'OR' case-insensitive", orbitraStreamFilterLogic(['filters_logic' => 'OR']), 'or');
$assert("logic garbage -> and", orbitraStreamFilterLogic(['filters_logic' => 'xor']), 'and');

// --- orbitraCombineFilterVotes ------------------------------------------------
// Abstentions (empty votes) pass under both logics — a stream whose filters
// all abstained behaves like a stream without filters.
$assert("no votes AND", orbitraCombineFilterVotes([], 'and'), true);
$assert("no votes OR", orbitraCombineFilterVotes([], 'or'), true);

// AND: every filter must pass.
$assert("AND all pass", orbitraCombineFilterVotes([true, true], 'and'), true);
$assert("AND one fails", orbitraCombineFilterVotes([true, false], 'and'), false);

// OR: any passing filter is enough.
$assert("OR one passes", orbitraCombineFilterVotes([false, true], 'or'), true);
$assert("OR none pass", orbitraCombineFilterVotes([false, false], 'or'), false);

// --- End-to-end: the Click API matcher ----------------------------------------
// Two filters: Country IN (IN) and Device IN (mobile). Visitor from IN on a
// desktop — satisfies exactly one of them.
$filtersJson = json_encode([
    ['name' => 'Country', 'mode' => 'include', 'payload' => ['IN']],
    ['name' => 'Device', 'mode' => 'include', 'payload' => ['mobile']],
]);
$mkStream = fn($logic) => ['filters_json' => $filtersJson, 'filters_logic' => $logic];

// A stub pdo is never touched unless a Bot filter runs.
$stubPdo = new PDO('sqlite::memory:');

// Visitor IN + desktop: AND must reject, OR must accept.
$and = orbitraClickApiStreamMatchesFilters($mkStream('and'), '1.2.3.4', 'IN', 'desktop', [], 'UA', [], 'en', $stubPdo);
$or = orbitraClickApiStreamMatchesFilters($mkStream('or'), '1.2.3.4', 'IN', 'desktop', [], 'UA', [], 'en', $stubPdo);
$assert("visitor IN/desktop, logic AND -> rejected", $and, false);
$assert("visitor IN/desktop, logic OR -> accepted", $or, true);

// Visitor US + desktop: neither filter passes — both logics reject.
$and2 = orbitraClickApiStreamMatchesFilters($mkStream('and'), '1.2.3.4', 'US', 'desktop', [], 'UA', [], 'en', $stubPdo);
$or2 = orbitraClickApiStreamMatchesFilters($mkStream('or'), '1.2.3.4', 'US', 'desktop', [], 'UA', [], 'en', $stubPdo);
$assert("visitor US/desktop, logic AND -> rejected", $and2, false);
$assert("visitor US/desktop, logic OR -> rejected", $or2, false);

// Visitor IN + mobile: both pass — both logics accept.
$and3 = orbitraClickApiStreamMatchesFilters($mkStream('and'), '1.2.3.4', 'IN', 'mobile', [], 'UA', [], 'en', $stubPdo);
$or3 = orbitraClickApiStreamMatchesFilters($mkStream('or'), '1.2.3.4', 'IN', 'mobile', [], 'UA', [], 'en', $stubPdo);
$assert("visitor IN/mobile, logic AND -> accepted", $and3, true);
$assert("visitor IN/mobile, logic OR -> accepted", $or3, true);

// Legacy stream without filters_logic keeps the original AND behavior.
$legacy = orbitraClickApiStreamMatchesFilters(['filters_json' => $filtersJson], '1.2.3.4', 'IN', 'desktop', [], 'UA', [], 'en', $stubPdo);
$assert("legacy stream (no filters_logic) stays AND", $legacy, false);

// Device aliases are grouped before matching, including imported granular
// values that predate the canonical Mobile/Tablet/Desktop taxonomy.
$mobileStream = ['filters_json' => json_encode([
    ['name' => 'Device', 'mode' => 'include', 'payload' => ['mobile']],
])];
$tabletStream = ['filters_json' => json_encode([
    ['name' => 'Device', 'mode' => 'include', 'payload' => ['tablet']],
])];
$assert("mobile filter accepts smartphone alias", orbitraClickApiStreamMatchesFilters($mobileStream, '1.2.3.4', 'US', 'smartphone', [], 'UA', [], 'en', $stubPdo), true);
$assert("mobile filter accepts feature-phone alias", orbitraClickApiStreamMatchesFilters($mobileStream, '1.2.3.4', 'US', 'feature phone', [], 'UA', [], 'en', $stubPdo), true);
$assert("mobile filter rejects tablet", orbitraClickApiStreamMatchesFilters($mobileStream, '1.2.3.4', 'US', 'iPad', [], 'UA', [], 'en', $stubPdo), false);
$assert("tablet filter accepts iPad alias", orbitraClickApiStreamMatchesFilters($tabletStream, '1.2.3.4', 'US', 'iPad', [], 'UA', [], 'en', $stubPdo), true);
$assert("tablet filter rejects desktop", orbitraClickApiStreamMatchesFilters($tabletStream, '1.2.3.4', 'US', 'desktop', [], 'UA', [], 'en', $stubPdo), false);

echo "stream_filters_test: all assertions passed\n";
