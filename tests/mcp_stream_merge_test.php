<?php
// tests/mcp_stream_merge_test.php
//
// orbitra_update_campaign patches streams instead of replacing them: a stream
// sent with the id of an existing one keeps every field it did not carry, and
// the streams the caller did not send stay. Before this, {id, weight} reset
// the stream's type, filters and rotation and dropped its siblings, because
// save_campaign deletes whatever the list leaves out.
//
// The merge lives twice — mcp/src/streamMerge.js for the stdio server and
// mcpMergeStreamsForUpdate() in core/McpTools.php for the HTTP endpoint and the
// panel's assistant — so both run the same fixtures here. Without node the
// parity half prints SKIP; the PHP assertions still run.
//
// Run: php tests/mcp_stream_merge_test.php

require_once __DIR__ . '/../core/McpTools.php';

$failures = 0;
$check = function (bool $ok, string $label, string $detail = '') use (&$failures): void {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
    if (!$ok) {
        $failures++;
    }
};

$s1 = ['id' => 1, 'weight' => 30, 'is_active' => 1, 'type' => 'regular', 'schema_type' => 'redirect', 'offer_id' => 10, 'filters' => [['country' => 'DE']], 'name' => 'DE'];
$s2 = ['id' => 2, 'weight' => 70, 'is_active' => 1, 'type' => 'regular', 'schema_type' => 'landing_offer', 'offer_id' => null, 'name' => null, 'schema_custom' => ['offers' => [['id' => 7, 'weight' => 100]]]];
$s3 = ['id' => 3, 'weight' => 0, 'is_active' => 0, 'type' => 'fallback', 'schema_type' => 'redirect', 'offer_id' => 11];
$current = [$s1, $s2, $s3];

// The fixtures both implementations run. [current, incoming, deleteIds].
$cases = [
    'one field of one stream' => [$current, [['id' => 2, 'weight' => 50]], []],
    'sibling patched too, the third stays' => [$current, [['id' => 1, 'weight' => 10], ['id' => 3, 'weight' => 5]], []],
    'new stream without id is appended' => [$current, [['id' => 1, 'weight' => 10], ['offer_id' => 99, 'weight' => 0]], []],
    'nothing passed keeps the list' => [$current, null, []],
    'empty list keeps the list' => [$current, [], []],
    'delete by id' => [$current, null, [1]],
    'patch and delete together' => [$current, [['id' => 2, 'weight' => 90]], [3]],
    'deleting an unknown id changes nothing' => [$current, null, [999]],
    'same id twice: the second is a copy' => [$current, [['id' => 1, 'weight' => 20], ['id' => 1, 'weight' => 20]], []],
    'id of another campaign is a new stream' => [$current, [['id' => 999, 'weight' => 5]], []],
    'numbers as strings' => [$current, [['id' => '2', 'weight' => '40']], []],
    'null overrides the stored value' => [$current, [['id' => 1, 'offer_id' => null]], []],
    'non-object entries are skipped' => [$current, ['x', 7, null, ['id' => 1, 'weight' => 8]], []],
    'stored list missing' => [null, [['offer_id' => 5]], []],
    'delete only, stored list missing' => [[], null, [4]],
];

$php = [];
foreach ($cases as $name => [$cur, $inc, $del]) {
    $php[$name] = mcpMergeStreamsForUpdate($cur, $inc, $del);
}

// ---- PHP: what the merge must guarantee --------------------------------------

$m = $php['one field of one stream'];
$check(count($m) === 3 && $m[0] === $s1 && $m[1]['weight'] === 50 && $m[1]['schema_type'] === 'landing_offer'
    && $m[1]['schema_custom'] === ['offers' => [['id' => 7, 'weight' => 100]]] && $m[2] === $s3,
    'a stream sent as {id, weight} keeps its type, rotation and siblings', json_encode($m));

$m = $php['new stream without id is appended'];
$check(count($m) === 4 && $m[0]['weight'] === 10 && $m[1] === $s2 && $m[3] === ['offer_id' => 99, 'weight' => 0],
    'a stream without an id is added at the end', json_encode($m));

$m = $php['delete by id'];
$check(array_column($m, 'id') === [2, 3], 'delete_stream_ids removes exactly that stream', json_encode($m));

$m = $php['patch and delete together'];
$check(array_column($m, 'id') === [1, 2] && $m[1]['weight'] === 90, 'patch and delete in one call', json_encode($m));

$check($php['nothing passed keeps the list'] === $current && $php['empty list keeps the list'] === $current, 'no streams passed → the stored list, untouched');
$check($php['deleting an unknown id changes nothing'] === $current, 'deleting an id that is not there changes nothing');

$m = $php['same id twice: the second is a copy'];
$check(count($m) === 4 && !isset($m[3]['id']) && $m[3]['weight'] === 20 && $m[3]['offer_id'] === 10 && $m[3]['filters'] === [['country' => 'DE']],
    'a second stream with the same id becomes a copy without an id', json_encode($m));

$m = $php['non-object entries are skipped'];
$check(count($m) === 3 && $m[0]['weight'] === 8 && $m[1] === $s2, 'entries that are not objects are skipped', json_encode($m));

// What update_campaign hands to save_campaign: the patched stream goes through
// the normaliser with the stored fields still in place.
$normalized = array_map(static fn($s) => mcpNormalizeStream($s), $php['one field of one stream']);
$check($normalized[0]['filters'] === [['country' => 'DE']] && $normalized[0]['offer_id'] === 10 && $normalized[0]['weight'] === 30
    && $normalized[1]['weight'] === 50 && $normalized[1]['schema_type'] === 'landing_offer' && count($normalized) === 3,
    'the merged list normalises with filters, offer and rotation intact', json_encode($normalized));

// ---- Node: the stdio server's twin on the same fixtures ----------------------

$nodeBin = trim((string) getenv('NODE_BIN') ?: '');
if ($nodeBin === '') {
    foreach (['node', '/usr/local/bin/node', '/opt/homebrew/bin/node'] as $candidate) {
        $probe = $candidate === 'node' ? trim((string) @shell_exec('command -v node 2>/dev/null')) : $candidate;
        if ($probe !== '' && is_executable($probe)) {
            $nodeBin = $probe;
            break;
        }
    }
}
if ($nodeBin === '') {
    echo "SKIP: node not found — the parity between mcp/src/streamMerge.js and core/McpTools.php\n";
    echo "was not checked. Run it on a machine that has node.\n";
} else {
    $tmpDir = sys_get_temp_dir() . '/orbitra_stream_merge_' . getmypid();
    @mkdir($tmpDir);
    $casesFile = "$tmpDir/cases.json";
    file_put_contents($casesFile, json_encode(array_values($cases)));
    $proc = proc_open([$nodeBin, __DIR__ . '/fixtures/stream_merge_runner.mjs', $casesFile],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    @unlink($casesFile);
    @rmdir($tmpDir);
    $js = json_decode((string) $stdout, true);
    $check(is_array($js) && count($js) === count($cases), 'node runner returned one result per case' . ($exit !== 0 ? " (exit $exit): " . substr(trim((string) $stderr), 0, 300) : ''));
    if (is_array($js)) {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        foreach (array_keys($cases) as $i => $name) {
            $check(json_encode($php[$name], $flags) === json_encode($js[$i] ?? null, $flags),
                "parity: $name", 'php ' . json_encode($php[$name], $flags) . ' ≠ js ' . json_encode($js[$i] ?? null, $flags));
        }
    }
}

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
