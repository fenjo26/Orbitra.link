<?php
// tests/filter_payload_shape_test.php
//
// 2.0.0 field report: every click into a campaign answered HTTP 500 —
//   TypeError: {closure:streamMatchesFilters()}(): Argument #4 ($payload)
//   must be of type array, string given
// One stream filter had a string payload (the editor saves lists, but the
// API, the MCP tools / AI assistant, imports and hand-edited rows do not
// have to). The trace closure type-hinted the list, and PHP checks the
// types before the body runs, so the crash happened with tracing off too.
//
// Covers: orbitraFilterPayloadList() / orbitraNormalizeStreamFilters(), the
// router (streamMatchesFilters — core/stream_match.php, which index.php
// itself runs; the fallback below still reads it out of index.php for a tree
// where it lives there), its ?_dbg=1 trace, and the Click API matcher.
//
// Run: php tests/filter_payload_shape_test.php

require_once __DIR__ . '/../core/click_api.php';
require_once __DIR__ . '/../core/stream_match.php';

$repoRoot = dirname(__DIR__);
$indexSrc = (string) file_get_contents($repoRoot . '/index.php');
foreach (['filterTokenEquals', 'browserMatchesToken', 'ipMatchesToken', 'timeToMinutes', 'streamMatchesFilters'] as $fn) {
    if (function_exists($fn)) {
        continue;
    }
    if (!preg_match('/^function ' . preg_quote($fn, '/') . '\(.*?\n\}/ms', $indexSrc, $m)) {
        fwrite(STDERR, "could not extract {$fn} from index.php\n");
        exit(1);
    }
    eval(str_replace('__DIR__', var_export($repoRoot, true), $m[0]));
}

$fails = 0;
$check = function (string $label, $got, $expected) use (&$fails) {
    if ($got !== $expected) {
        $fails++;
        echo "FAIL $label: got " . var_export($got, true) . ', expected ' . var_export($expected, true) . "\n";
        return;
    }
    echo "ok  $label\n";
};

// --- helpers -----------------------------------------------------------------
$check('list stays list', orbitraFilterPayloadList(['US', 'DE']), ['US', 'DE']);
$check('comma string', orbitraFilterPayloadList(' US, DE ,,'), ['US', 'DE']);
$check('single string', orbitraFilterPayloadList('yes'), ['yes']);
$check('int', orbitraFilterPayloadList(1), ['1']);
$check('true', orbitraFilterPayloadList(true), ['1']);
$check('null/false/object -> empty', [orbitraFilterPayloadList(null), orbitraFilterPayloadList(false), orbitraFilterPayloadList(new stdClass())], [[], [], []]);
$check('normalize drops non-objects, lists payloads',
    orbitraNormalizeStreamFilters('[{"name":"Country","payload":"US, DE"},"junk",{"name":"Bot","payload":1}]'),
    [['name' => 'Country', 'payload' => ['US', 'DE']], ['name' => 'Bot', 'payload' => ['1']]]);
$check('normalize garbage', orbitraNormalizeStreamFilters('not json'), []);

// --- router ------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$visitor = [
    'ip' => '73.1.2.3', 'country' => 'US', 'device' => 'desktop', 'os' => 'Windows',
    'browser' => 'Chrome', 'languageCodes' => ['en'], 'acceptLanguage' => 'en-US,en;q=0.9',
    'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/127.0.0.0 Safari/537.36',
    'referer' => '', 'params' => [], 'rawParams' => [], 'timezone' => 'UTC', 'isp' => '', 'asn' => '',
];
$stream = static function (array $filters): array {
    return ['id' => 1, 'filters_json' => json_encode($filters)];
};
$route = static function (array $filters, bool $trace = false) use ($stream, $visitor, $pdo) {
    $t = $trace ? [] : null;
    try {
        $r = streamMatchesFilters($stream($filters), $visitor, $pdo, $t);
    } catch (\Throwable $e) {
        return 'THROWN ' . get_class($e) . ': ' . $e->getMessage();
    }
    return $trace ? [$r, $t] : $r;
};

// The exact report: unknown filter type, string payload -> abstain, no 500.
$check('unknown type + string payload passes (abstains)', $route([['name' => 'Uniqueness', 'mode' => 'include', 'payload' => 'yes']]), true);
$check('unknown type + string payload, traced', $route([['name' => 'Uniqueness', 'payload' => 'yes']], true)[0], true);
$check('Country string include match', $route([['name' => 'Country', 'mode' => 'include', 'payload' => 'US, DE']]), true);
$check('Country string include miss', $route([['name' => 'Country', 'mode' => 'include', 'payload' => 'DE, FR']]), false);
$check('Country string exclude', $route([['name' => 'Country', 'mode' => 'exclude', 'payload' => 'US']]), false);
$check('OS string payload', $route([['name' => 'OS', 'mode' => 'include', 'payload' => 'Windows']]), true);
$check('Bot scalar payload 1 (old import), residential visitor', $route([['name' => 'Bot', 'mode' => 'include', 'payload' => 1, 'sensitivity' => 'medium']]), false);
$check('non-object filter entry skipped', $route(['junk', ['name' => 'Country', 'payload' => ['US']]]), true);
$check('filter without name abstains', $route([['payload' => 'x']]), true);
$traced = $route([['name' => 'Country', 'mode' => 'include', 'payload' => 'US, DE']], true);
$check('trace records string payload as list', $traced[1][0]['payload'] ?? null, 'US, DE');

// --- Click API agrees with the router ------------------------------------------
$api = static function (array $filters) use ($stream, $visitor, $pdo) {
    return orbitraClickApiStreamMatchesFilters($stream($filters), $visitor['ip'], 'US', 'desktop', ['en'],
        $visitor['userAgent'], ['asn' => 'AS7922', 'isp' => 'Comcast', 'is_proxy' => 0, 'proxy_type' => ''], $visitor['acceptLanguage'], $pdo);
};
$check('Click API Country string miss', $api([['name' => 'Country', 'mode' => 'include', 'payload' => 'DE, FR']]), false);
$check('Click API Country string match', $api([['name' => 'Country', 'mode' => 'include', 'payload' => 'US']]), true);
$check('Click API unknown + string', $api([['name' => 'Uniqueness', 'payload' => 'yes']]), true);

if ($fails) {
    echo "\n$fails failure(s)\n";
    exit(1);
}
echo "\nall passed\n";
