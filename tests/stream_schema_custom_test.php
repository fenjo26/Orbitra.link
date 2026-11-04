<?php
// tests/stream_schema_custom_test.php
//
// schema_custom is stored as an object. A bare list of offers (what API/MCP
// clients sent before 1.8.9) is read as the offers list, so the editor shows the
// offer and can save into it.
//
// Run: php tests/stream_schema_custom_test.php

require_once __DIR__ . '/../core/stream_stats.php';

$failures = 0;
$assert = function (string $label, $got, $expected) use (&$failures) {
    $ok = $got === $expected;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label;
    if (!$ok) {
        echo ' — got ' . var_export($got, true) . ', expected ' . var_export($expected, true);
        $failures++;
    }
    echo "\n";
};

$list = [['id' => 170, 'weight' => 100]];
$assert('bare list → offers', orbitraNormalizeSchemaCustom($list), ['offers' => $list]);
$assert('bare list JSON → offers', orbitraNormalizeSchemaCustom('[{"id":170,"weight":100}]'), ['offers' => $list]);
$obj = ['offers' => [['id' => 7, 'weight' => 100]], 'landings' => [], 'auto' => ['offers' => ['enabled' => true]]];
$assert('object kept as is', orbitraNormalizeSchemaCustom($obj), $obj);
$assert('cloak-only object kept', orbitraNormalizeSchemaCustom(['safe_mode' => 'url', 'safe_url' => 'x']), ['safe_mode' => 'url', 'safe_url' => 'x']);
$assert('null → empty', orbitraNormalizeSchemaCustom(null), []);
$assert('broken JSON → empty', orbitraNormalizeSchemaCustom('{nope'), []);
$assert('list of scalars → empty', orbitraNormalizeSchemaCustom([1, 2]), []);

$assert('encode list as object', orbitraEncodeSchemaCustom($list), '{"offers":[{"id":170,"weight":100}]}');
$assert('encode empty as {}', orbitraEncodeSchemaCustom([]), '{}');
$assert('encode null as {}', orbitraEncodeSchemaCustom(null), '{}');
$assert('encode object round-trips', json_decode(orbitraEncodeSchemaCustom($obj), true), $obj);

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
