<?php
// tests/campaign_quick_actions_test.php
//
// The routine campaign actions behind orbitra_set_stream_weights,
// orbitra_set_stream_active, orbitra_set_campaign_state and
// orbitra_set_rotation_weights, and the patch semantics of
// orbitra_update_campaign:
//
//   1. the functions in core/CampaignQuickActions.php against an in-memory DB;
//   2. the whole chain over the real mcp.php HTTP endpoint — mcp.php →
//      core/McpTools.php → cli/api_invoke.php → api.php → the sandbox DB —
//      because an api.php action that lost its `case` still passes every
//      function-level test, and because the update_campaign trap lived in the
//      handler, not in a function.
//
// Run: php tests/campaign_quick_actions_test.php

require_once __DIR__ . '/../core/CampaignQuickActions.php';

$failures = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
    if (!$ok) {
        $failures++;
    }
}

/** Run $fn and return the OrbitraQuickActionError it must raise. */
function qerr(callable $fn): OrbitraQuickActionError
{
    try {
        $fn();
    } catch (OrbitraQuickActionError $e) {
        return $e;
    }
    throw new RuntimeException('expected an OrbitraQuickActionError');
}

$shares = static function (array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $out[$r['id']] = $r['percent'];
    }
    return $out;
};

// =============================================================================
// 1. Functions, in-memory DB
// =============================================================================

$mem = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$mem->exec("CREATE TABLE campaigns (id INTEGER PRIMARY KEY, name TEXT, state TEXT DEFAULT 'active', rotation_type TEXT DEFAULT 'position', is_archived INTEGER DEFAULT 0)");
$mem->exec("CREATE TABLE streams (id INTEGER PRIMARY KEY, campaign_id INTEGER, name TEXT, weight INTEGER DEFAULT 100, is_active INTEGER DEFAULT 1, type TEXT DEFAULT 'regular', position INTEGER DEFAULT 0, schema_custom_json TEXT DEFAULT '{}')");
$mem->exec('CREATE TABLE offers (id INTEGER PRIMARY KEY, name TEXT)');
$mem->exec('CREATE TABLE landings (id INTEGER PRIMARY KEY, name TEXT)');

$mem->exec("INSERT INTO campaigns (id, name, state, rotation_type, is_archived) VALUES
    (1, 'Main', 'active', 'position', 0),
    (2, 'Stopped', 'disabled', 'weight', 0),
    (3, 'Archived', 'active', 'position', 1)");
$split = json_encode([
    'landings' => [['id' => 3, 'weight' => 100]],
    'offers' => [['id' => 7, 'weight' => 60], ['id' => 8, 'weight' => 40]],
]);
$st = $mem->prepare('INSERT INTO streams (id, campaign_id, name, weight, is_active, type, position, schema_custom_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$st->execute([1, 1, 'DE', 30, 1, 'regular', 0, '{}']);
$st->execute([2, 1, 'Split', 70, 1, 'regular', 1, $split]);
$st->execute([3, 1, 'Spare', 0, 0, 'regular', 2, '{}']);
$st->execute([4, 1, 'Catch', 100, 0, 'fallback', 3, '{}']);
$st->execute([5, 2, 'Theirs', 100, 1, 'regular', 0, '{}']);
$mem->exec("INSERT INTO offers (id, name) VALUES (7, 'Offer Seven'), (8, 'Offer Eight')");
$mem->exec("INSERT INTO landings (id, name) VALUES (3, 'Landing Three')");

$streamRow = static function (int $id) use ($mem): array {
    $q = $mem->prepare('SELECT * FROM streams WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC);
};
$reset = static function () use ($mem, $st, $split): void {
    $mem->exec('DELETE FROM streams');
    $st->execute([1, 1, 'DE', 30, 1, 'regular', 0, '{}']);
    $st->execute([2, 1, 'Split', 70, 1, 'regular', 1, $split]);
    $st->execute([3, 1, 'Spare', 0, 0, 'regular', 2, '{}']);
    $st->execute([4, 1, 'Catch', 100, 0, 'fallback', 3, '{}']);
    $st->execute([5, 2, 'Theirs', 100, 1, 'regular', 0, '{}']);
    $mem->exec("UPDATE campaigns SET state = 'active', rotation_type = CASE id WHEN 2 THEN 'weight' ELSE 'position' END");
};

// ---- numbers ----------------------------------------------------------------

check(orbitraQuickInt(7) === 7 && orbitraQuickInt(7.0) === 7 && orbitraQuickInt('7') === 7 && orbitraQuickInt(' 7 ') === 7,
    'whole numbers arrive as ints whatever JSON made of them');
check(orbitraQuickInt(7.5) === null && orbitraQuickInt('x') === null && orbitraQuickInt(true) === null && orbitraQuickInt(null) === null && orbitraQuickInt([]) === null,
    'anything that is not a whole number is refused, not read as 0');

// ---- stream weights ---------------------------------------------------------

$res = orbitraSetStreamWeights($mem, ['id' => 1, 'weights' => [['stream_id' => 1, 'weight' => 60], ['stream_id' => 2, 'weight' => 40]]]);
check((int) $streamRow(1)['weight'] === 60 && (int) $streamRow(2)['weight'] === 40, 'weights written for the listed streams only',
    json_encode([$streamRow(1)['weight'], $streamRow(2)['weight']]));
check($streamRow(3)['weight'] == 0 && $streamRow(4)['weight'] == 100, 'streams that were not listed keep their weight');
check($shares($res['streams']) === [1 => 60.0, 2 => 40.0, 3 => null, 4 => null], 'shares among the enabled regular streams; off and fallback streams get none',
    json_encode($res['streams']));
check($res['rotation_type'] === 'position' && strpos($res['warning'], 'rotation_type="weight"') !== false,
    'rotation by position → the result says the weights are not used yet');
check($res['changed'] === [
    ['stream_id' => 1, 'what' => 'stream #1', 'from' => 30, 'to' => 60],
    ['stream_id' => 2, 'what' => 'stream #2', 'from' => 70, 'to' => 40],
], 'changed lists from → to for every stream', json_encode($res['changed']));

$res = orbitraSetStreamWeights($mem, ['id' => 1, 'weights' => [['stream_id' => 1, 'weight' => 20]], 'rotation_type' => 'weight']);
check($res['rotation_type'] === 'weight' && $res['warning'] === null, 'rotation_type switches the campaign to rotation by weight');
$q = $mem->query("SELECT rotation_type FROM campaigns WHERE id = 1")->fetchColumn();
check($q === 'weight', 'the switch is stored');

$e = qerr(static fn() => orbitraSetStreamWeights($mem, ['id' => 1, 'weights' => [['stream_id' => 77, 'weight' => 10]]]));
check($e->errorCode === 'stream_not_found' && strpos($e->getMessage(), 'does not belong to campaign 1') !== false
    && array_column($e->detail['streams'], 'id') === [1, 2, 3, 4], 'a stream of another campaign is refused, and the message lists the real ones', $e->getMessage());
check($streamRow(5)['weight'] == 100, 'the refused call changed nothing');
check(qerr(static fn() => orbitraSetStreamWeights($mem, ['id' => 1, 'weights' => [['stream_id' => 1, 'weight' => 101]]]))->errorCode === 'invalid_weight', 'a weight above 100 is refused');
check(qerr(static fn() => orbitraSetStreamWeights($mem, ['id' => 1, 'weights' => [['stream_id' => 1, 'weight' => -1]]]))->errorCode === 'invalid_weight', 'a negative weight is refused');
check(qerr(static fn() => orbitraSetStreamWeights($mem, ['id' => 1, 'weights' => [['stream_id' => 1, 'weight' => 5.5]]]))->errorCode === 'invalid_weight', 'a fractional weight is refused');
check(qerr(static fn() => orbitraSetStreamWeights($mem, ['id' => 1, 'weights' => []]))->errorCode === 'weights_required', 'no weights → nothing to do');
check(qerr(static fn() => orbitraSetStreamWeights($mem, ['id' => 1, 'weights' => ['x']]))->errorCode === 'invalid_weights', 'a weights entry that is not an object is refused');
check(qerr(static fn() => orbitraSetStreamWeights($mem, ['id' => 1, 'weights' => [['stream_id' => 1, 'weight' => 0], ['stream_id' => 2, 'weight' => 0]]]))->errorCode === 'no_traffic_left',
    'zero everywhere is refused: the campaign could not pick a stream');
check(qerr(static fn() => orbitraSetStreamWeights($mem, ['id' => 1, 'weights' => [['stream_id' => 1, 'weight' => 10]], 'rotation_type' => 'random']))->errorCode === 'invalid_rotation', 'an unknown rotation type is refused');
check(qerr(static fn() => orbitraSetStreamWeights($mem, ['weights' => [['stream_id' => 1, 'weight' => 10]]]))->errorCode === 'campaign_required', 'the campaign id is required');
check(qerr(static fn() => orbitraSetStreamWeights($mem, ['id' => 999, 'weights' => [['stream_id' => 1, 'weight' => 10]]]))->errorCode === 'campaign_not_found', 'an unknown campaign is refused');
check(qerr(static fn() => orbitraSetStreamWeights($mem, ['id' => 3, 'weights' => [['stream_id' => 1, 'weight' => 10]]]))->errorCode === 'campaign_archived', 'a campaign in the archive is refused');
check(orbitraSetStreamWeights($mem, ['id' => 2, 'weights' => [['stream_id' => 5, 'weight' => 100]]])['campaign'] === 'Stopped', 'a stopped campaign can still be prepared');
$reset();

// ---- stream on / off --------------------------------------------------------

$res = orbitraSetStreamActive($mem, ['id' => 1, 'streams' => [['stream_id' => 1, 'is_active' => false]]]);
check($streamRow(1)['is_active'] == 0 && $streamRow(2)['is_active'] == 1 && $streamRow(1)['weight'] == 30,
    'a stream is switched off and nothing else about it changes');
check($res['changed'] === [['stream_id' => 1, 'what' => 'stream #1', 'from' => true, 'to' => false]], 'changed says on → off', json_encode($res['changed']));
$e = qerr(static fn() => orbitraSetStreamActive($mem, ['id' => 1, 'streams' => [['stream_id' => 2, 'is_active' => false]]]));
check($e->errorCode === 'last_active_stream' && $streamRow(2)['is_active'] == 1,
    'the last enabled stream cannot be switched off', $e->getMessage());
check(qerr(static fn() => orbitraSetStreamActive($mem, ['id' => 1, 'streams' => [['stream_id' => 1, 'is_active' => false], ['stream_id' => 2, 'is_active' => false]]]))->errorCode === 'last_active_stream'
    && $streamRow(1)['is_active'] == 0 && $streamRow(2)['is_active'] == 1,
    'the guard looks at the result of the whole call, and a refused call writes nothing');
orbitraSetStreamActive($mem, ['id' => 1, 'streams' => [['stream_id' => 3, 'is_active' => true], ['stream_id' => 1, 'is_active' => 1], ['stream_id' => 3, 'is_active' => '0']]]);
check($streamRow(1)['is_active'] == 1 && $streamRow(3)['is_active'] == 0, 'is_active accepts true/false, 1/0 and "1"/"0"; the last word on a stream wins');
check(qerr(static fn() => orbitraSetStreamActive($mem, ['id' => 1, 'streams' => [['stream_id' => 9, 'is_active' => false]]]))->errorCode === 'stream_not_found', 'an unknown stream is refused');
check(qerr(static fn() => orbitraSetStreamActive($mem, ['id' => 1, 'streams' => [['stream_id' => 1, 'is_active' => 'maybe']]]))->errorCode === 'invalid_streams', 'is_active must be a boolean');
check(qerr(static fn() => orbitraSetStreamActive($mem, ['id' => 1, 'streams' => []]))->errorCode === 'streams_required', 'no streams → nothing to do');
$reset();

// ---- campaign state ---------------------------------------------------------

$res = orbitraSetCampaignState($mem, ['id' => 1, 'state' => 'paused']);
$q = $mem->query("SELECT state FROM campaigns WHERE id = 1")->fetchColumn();
check($q === 'disabled' && $res['state'] === 'disabled' && $res['running'] === false && $res['changed'] === true, 'paused stops the campaign');
check(strpos((string) $res['note'], 'sync_ads=true') !== false && $res['ads_synced'] === [],
    'without sync_ads the result says the ads at the source are still running', (string) $res['note']);
$res = orbitraSetCampaignState($mem, ['id' => 1, 'state' => 'disabled']);
check($res['changed'] === false && strpos((string) $res['note'], 'sync_ads=true') !== false,
    'stopping a stopped campaign changes nothing, and the ads are still worth a word');
$res = orbitraSetCampaignState($mem, ['id' => 1, 'state' => 'ACTIVE']);
check($res['state'] === 'active' && $res['running'] === true && $res['changed'] === true
    && $mem->query("SELECT state FROM campaigns WHERE id = 1")->fetchColumn() === 'active', 'active starts it again (case does not matter)');
check(qerr(static fn() => orbitraSetCampaignState($mem, ['id' => 1, 'state' => 'deleted']))->errorCode === 'invalid_state', 'an unknown state is refused');
check(qerr(static fn() => orbitraSetCampaignState($mem, ['id' => 3, 'state' => 'paused']))->errorCode === 'campaign_archived', 'a campaign in the archive is left alone');
$reset();

// ---- weights inside a stream's rotation -------------------------------------

$res = orbitraSetRotationWeights($mem, ['campaign_id' => 1, 'stream_id' => 2, 'offers' => [['id' => 7, 'weight' => 80]]]);
$custom = json_decode($streamRow(2)['schema_custom_json'], true);
check($custom['offers'] === [['id' => 7, 'weight' => 80], ['id' => 8, 'weight' => 40]] && $custom['landings'] === [['id' => 3, 'weight' => 100]],
    'the listed offer is re-weighed; the other offer and the landings keep theirs', json_encode($custom));
check($res['changed'] === [['what' => 'offer #7', 'from' => 60, 'to' => 80]], 'changed names the offer and its weights', json_encode($res['changed']));
check($res['lists']['offers'][0]['name'] === 'Offer Seven' && abs($res['lists']['offers'][0]['percent'] - 66.7) < 0.01
    && abs($res['lists']['offers'][1]['percent'] - 33.3) < 0.01 && $res['lists']['landings'][0]['name'] === 'Landing Three',
    'the result carries names and shares', json_encode($res['lists']));
check($res['auto_turned_off'] === [], 'nothing was turned off when Auto was not on');

$e = qerr(static fn() => orbitraSetRotationWeights($mem, ['campaign_id' => 1, 'stream_id' => 2, 'offers' => [['id' => 9, 'weight' => 10]]]));
check($e->errorCode === 'item_not_in_list' && strpos($e->getMessage(), '#7, #8') !== false && $e->detail['items'] === [7, 8],
    'an offer that is not in the rotation is refused, and the message lists what is', $e->getMessage());
$e = qerr(static fn() => orbitraSetRotationWeights($mem, ['campaign_id' => 1, 'stream_id' => 1, 'offers' => [['id' => 7, 'weight' => 10]]]));
check($e->errorCode === 'list_not_found', 'a plain redirect stream has nothing to weigh', $e->getMessage());
check(qerr(static fn() => orbitraSetRotationWeights($mem, ['campaign_id' => 1, 'stream_id' => 5, 'offers' => [['id' => 7, 'weight' => 10]]]))->errorCode === 'stream_not_found',
    "another campaign's stream is refused");
check(qerr(static fn() => orbitraSetRotationWeights($mem, ['campaign_id' => 1, 'offers' => [['id' => 7, 'weight' => 10]]]))->errorCode === 'stream_required', 'the stream id is required');
check(qerr(static fn() => orbitraSetRotationWeights($mem, ['campaign_id' => 1, 'stream_id' => 2]))->errorCode === 'weights_required', 'neither offers nor landings → nothing to do');
check(qerr(static fn() => orbitraSetRotationWeights($mem, ['campaign_id' => 1, 'stream_id' => 2, 'offers' => ['7' => ['weight' => 10]]]))->errorCode === 'invalid_weights',
    'offers must be a list');
check(qerr(static fn() => orbitraSetRotationWeights($mem, ['campaign_id' => 1, 'stream_id' => 2, 'offers' => [['id' => 7, 'weight' => 0], ['id' => 8, 'weight' => 0]]]))->errorCode === 'no_traffic_left',
    'zero everywhere inside a list is refused');

// Auto-optimisation owns the weights while it is on.
$withAuto = $split;
$custom = json_decode($withAuto, true);
$custom['auto'] = ['offers' => ['enabled' => true, 'key' => 'rot_test', 'metric' => 'sales']];
$mem->prepare('UPDATE streams SET schema_custom_json = ? WHERE id = 2')->execute([json_encode($custom)]);
$e = qerr(static fn() => orbitraSetRotationWeights($mem, ['campaign_id' => 1, 'stream_id' => 2, 'offers' => [['id' => 7, 'weight' => 90]]]));
check($e->errorCode === 'auto_enabled' && $e->detail['list'] === 'offers', 'while Auto manages the list, a manual weight is refused', $e->getMessage());
check(json_decode($streamRow(2)['schema_custom_json'], true)['offers'][0]['weight'] === 60, 'the refused call wrote nothing');
$res = orbitraSetRotationWeights($mem, ['campaign_id' => 1, 'stream_id' => 2, 'landings' => [['id' => 3, 'weight' => 50]]]);
check($res['auto_turned_off'] === [] && $res['lists']['offers'][0]['weight'] === 60 && $res['lists']['landings'][0]['weight'] === 50,
    'the landings list, which has no Auto, can be re-weighed while the offers are left alone');
$res = orbitraSetRotationWeights($mem, ['campaign_id' => 1, 'stream_id' => 2, 'offers' => [['id' => 7, 'weight' => 90]], 'turn_off_auto' => true]);
$custom = json_decode($streamRow(2)['schema_custom_json'], true);
check($res['auto_turned_off'] === ['offers'] && $custom['auto']['offers']['enabled'] === false && $custom['auto']['offers']['key'] === 'rot_test'
    && $custom['offers'][0]['weight'] === 90, 'turn_off_auto switches Auto off for that list only, keeps its settings, and writes the weight', json_encode($custom));

// What API clients sent before 1.8.9: the schema as a bare list of offers.
$mem->prepare('UPDATE streams SET schema_custom_json = ? WHERE id = 1')->execute(['[{"id":8,"weight":100}]']);
$res = orbitraSetRotationWeights($mem, ['campaign_id' => 1, 'stream_id' => 1, 'offers' => [['id' => 8, 'weight' => 50]]]);
check(json_decode($streamRow(1)['schema_custom_json'], true) === ['offers' => [['id' => 8, 'weight' => 50]]], 'a bare offers list is read as the offers list and stored as an object');
$reset();

// ---- audit lines ------------------------------------------------------------

$res = orbitraSetStreamWeights($mem, ['id' => 1, 'weights' => [['stream_id' => 1, 'weight' => 60], ['stream_id' => 2, 'weight' => 40]]]);
check(orbitraQuickAuditLine($res) === 'campaign #1: stream #1 30→60, stream #2 70→40', orbitraQuickAuditLine($res));
$res = orbitraSetCampaignState($mem, ['id' => 1, 'state' => 'paused']);
check(orbitraQuickAuditLine($res) === 'campaign #1 state → disabled', orbitraQuickAuditLine($res));
$res = orbitraSetRotationWeights($mem, ['campaign_id' => 1, 'stream_id' => 2, 'landings' => [['id' => 3, 'weight' => 20]]]);
check(orbitraQuickAuditLine($res) === 'campaign #1 stream #2: landing #3 100→20', orbitraQuickAuditLine($res));

// =============================================================================
// 2. Over the real MCP endpoint
// =============================================================================

require_once __DIR__ . '/lib/http.php';
date_default_timezone_set('UTC');

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();
$harness->start();
$server = null;

try {
    $harness->get('/nonexistent-boot-probe');
    $pdo = $harness->getPdo();
    $dir = $harness->getWorkingDir();

    copy(dirname(__DIR__) . '/mcp.php', $dir . '/mcp.php');
    mkdir($dir . '/mcp');
    copy(dirname(__DIR__) . '/mcp/tools.json', $dir . '/mcp/tools.json');
    mkdir($dir . '/cli');
    copy(dirname(__DIR__) . '/cli/api_invoke.php', $dir . '/cli/api_invoke.php');

    $pdo->exec("INSERT INTO users (id, username, password, role, is_active, permissions_json) VALUES
        (1, 'qa_admin', 'unused', 'admin', 1, '{}'),
        (2, 'qa_viewer', 'unused', 'user', 1, '{\"campaigns\":{\"access\":\"none\"}}'),
        (3, 'qa_scoped', 'unused', 'user', 1, '{\"campaigns\":{\"access\":\"own\",\"mode\":\"full\"}}')");
    $pdo->exec("INSERT INTO user_api_keys (user_id, key_name, api_key, permissions) VALUES
        (1, 'admin write', 'QA_ADMIN_WRITE', 'write'),
        (1, 'admin read',  'QA_ADMIN_READ',  'read'),
        (2, 'viewer write', 'QA_VIEWER_WRITE', 'write'),
        (3, 'scoped write', 'QA_SCOPED_WRITE', 'write')");
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('mcp_php_binary', ?)")
        ->execute([PHP_BINARY]);

    $pdo->exec("INSERT INTO domains (id, name, ssl_status) VALUES (7, 'track.qa-test.example', 'installed')");
    $pdo->exec("INSERT INTO campaigns (id, name, alias, token, state, is_archived, domain_id, rotation_type, owner_user_id) VALUES
        (42, 'QA Camp', 'qacamp', 'tok42qa', 'active', 0, 7, 'position', NULL),
        (43, 'QA Other', 'qaother', 'tok43qa', 'active', 0, 7, 'position', NULL),
        (44, 'QA Mine', 'qamine', 'tok44qa', 'active', 0, 7, 'position', 3)");
    $pdo->exec("INSERT INTO offers (id, name) VALUES (7, 'Offer Seven'), (8, 'Offer Eight'), (9, 'Offer Nine')");
    $pdo->exec("INSERT INTO landings (id, name, url) VALUES (3, 'Landing Three', '')");
    $pdo->exec("INSERT INTO streams (id, campaign_id, offer_id, name, weight, is_active, type, position, filters_json, schema_type, schema_custom_json) VALUES
        (1, 42, 7, 'DE main', 30, 1, 'regular', 0, '[{\"type\":\"country\",\"value\":\"DE\"}]', 'redirect', '{}'),
        (2, 42, NULL, 'Split', 70, 1, 'regular', 1, '[]', 'landing_offer', '{\"landings\":[{\"id\":3,\"weight\":100}],\"offers\":[{\"id\":7,\"weight\":60},{\"id\":8,\"weight\":40}]}'),
        (3, 42, 9, 'Off one', 0, 0, 'regular', 2, '[]', 'redirect', '{}'),
        (9, 43, 8, 'Theirs', 100, 1, 'regular', 0, '[]', 'redirect', '{}'),
        (10, 44, 9, 'Mine', 100, 1, 'regular', 0, '[]', 'redirect', '{}')");

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) {
        throw new RuntimeException($error);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $dir],
        [0 => ['pipe', 'r'], 1 => ['file', $dir . '/qa-http.log', 'a'],
         2 => ['file', $dir . '/qa-http.log', 'a']], $pipes, $dir);
    if (!is_resource($server)) {
        throw new RuntimeException('Could not start the quick-actions test server');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $probe = @file_get_contents("http://$address/mcp.php", false, stream_context_create(['http' => ['ignore_errors' => true]]));
        if (is_string($probe) && strpos($probe, 'send JSON-RPC') !== false) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('quick-actions test server did not start');
    }

    $rpc = static function (string $tool, array $args, string $key) use ($address): array {
        $raw = file_get_contents("http://$address/mcp.php?k=" . urlencode($key), false, stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $args]]),
            'ignore_errors' => true,
            'timeout' => 20,
        ]]));
        $envelope = json_decode((string) $raw, true);
        $text = (string) ($envelope['result']['content'][0]['text'] ?? '');
        $decoded = json_decode($text, true);
        return [
            'isError' => (bool) ($envelope['result']['isError'] ?? false),
            'payload' => is_array($decoded) ? $decoded : $text,
            'text' => $text,
        ];
    };
    $W = 'QA_ADMIN_WRITE';
    $streamState = static function () use ($pdo): array {
        $out = [];
        foreach ($pdo->query('SELECT * FROM streams WHERE campaign_id = 42 ORDER BY position, id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    };

    // --- the tools are on the list -------------------------------------------
    $raw = file_get_contents("http://$address/mcp.php?k=QA_ADMIN_READ", false, stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
        'content' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']), 'ignore_errors' => true, 'timeout' => 20,
    ]]));
    $names = array_column(json_decode((string) $raw, true)['result']['tools'] ?? [], 'name');
    foreach (['orbitra_set_stream_weights', 'orbitra_set_stream_active', 'orbitra_set_campaign_state', 'orbitra_set_rotation_weights'] as $tool) {
        check(in_array($tool, $names, true), "tools/list exposes $tool");
    }

    // --- update_campaign patches streams (the trap) --------------------------
    // Before: this call reset stream 1 to a bare redirect without filters and
    // deleted streams 2 and 3, because save_campaign removes what the list
    // leaves out.
    $res = $rpc('orbitra_update_campaign', ['id' => 42, 'streams' => [['id' => 1, 'weight' => 10]]], $W);
    check(!$res['isError'], 'update_campaign with one partial stream succeeds', $res['text']);
    $s = $streamState();
    check(count($s) === 3 && array_keys($s) === [1, 2, 3], 'the streams that were not sent are still there', json_encode(array_keys($s)));
    check((int) $s[1]['weight'] === 10 && (int) $s[1]['offer_id'] === 7 && $s[1]['schema_type'] === 'redirect' && $s[1]['name'] === 'DE main',
        'the sent stream keeps its offer, type and name', json_encode($s[1] ?? null));
    $f = json_decode((string) ($s[1]['filters_json'] ?? ''), true);
    check(count($f) === 1 && $f[0]['type'] === 'country' && $f[0]['value'] === 'DE', '…and its filters', (string) ($s[1]['filters_json'] ?? ''));
    check((int) $s[2]['weight'] === 70 && json_decode((string) $s[2]['schema_custom_json'], true)['offers'] === [['id' => 7, 'weight' => 60], ['id' => 8, 'weight' => 40]],
        '…and its sibling keeps its weight and rotation', (string) ($s[2]['schema_custom_json'] ?? ''));

    $res = $rpc('orbitra_update_campaign', ['id' => 42, 'streams' => [['id' => 2, 'schema_custom' => [
        'landings' => [['id' => 3, 'weight' => 100]], 'offers' => [['id' => 7, 'weight' => 100], ['id' => 8, 'weight' => 0]],
    ]]]], $W);
    check(!$res['isError'], 'a field a stream does carry replaces its stored value, as before', $res['text']);
    $s = $streamState();
    check(json_decode((string) $s[2]['schema_custom_json'], true)['offers'] === [['id' => 7, 'weight' => 100], ['id' => 8, 'weight' => 0]]
        && (int) $s[2]['weight'] === 70 && $s[2]['name'] === 'Split', '…and the fields it does not carry keep theirs', (string) ($s[2]['schema_custom_json'] ?? ''));

    $res = $rpc('orbitra_update_campaign', ['id' => 42, 'streams' => [['offer_id' => 9, 'weight' => 0]], 'rotation_type' => 'weight'], $W);
    $s = $streamState();
    $added = array_values(array_diff(array_keys($s), [1, 2, 3]));
    $newId = (int) ($added[0] ?? 0);
    check(!$res['isError'] && count($s) === 4 && $newId > 0 && (int) $s[$newId]['offer_id'] === 9
        && $pdo->query("SELECT rotation_type FROM campaigns WHERE id = 42")->fetchColumn() === 'weight',
        'a stream without an id is added; campaign fields update as before', $res['text']);

    $res = $rpc('orbitra_update_campaign', ['id' => 42, 'delete_stream_ids' => [3]], $W);
    $s = $streamState();
    $left = array_keys($s);
    sort($left);
    $want = [1, 2, $newId];
    sort($want);
    check(!$res['isError'] && $left === $want, 'delete_stream_ids removes that stream and only it', json_encode($left));

    // --- set_stream_weights ---------------------------------------------------
    $res = $rpc('orbitra_set_stream_weights', ['campaign_id' => 42, 'weights' => [['stream_id' => 1, 'weight' => 60], ['stream_id' => 2, 'weight' => 40]]], $W);
    $s = $streamState();
    check(!$res['isError'] && (int) $s[1]['weight'] === 60 && (int) $s[2]['weight'] === 40 && $s[1]['name'] === 'DE main'
        && json_decode((string) $s[1]['filters_json'], true) !== null, 'set_stream_weights writes the weights and touches nothing else', $res['text']);
    $data = is_array($res['payload']) ? ($res['payload']['data'] ?? []) : [];
    $pct = [];
    foreach (($data['streams'] ?? []) as $r) {
        $pct[$r['id']] = $r['percent'];
    }
    check((float) ($pct[1] ?? -1) === 60.0 && (float) ($pct[2] ?? -1) === 40.0 && (float) ($pct[$newId] ?? -1) === 0.0 && $data['campaign'] === 'QA Camp',
        'the answer carries every stream with its share', json_encode($data));

    $res = $rpc('orbitra_set_stream_weights', ['campaign_id' => 42, 'weights' => [['stream_id' => 9, 'weight' => 10]]], $W);
    check($res['isError'] && strpos($res['text'], 'does not belong to campaign 42') !== false, "another campaign's stream is refused over MCP", $res['text']);
    $res = $rpc('orbitra_set_stream_weights', ['campaign_id' => 42, 'weights' => [['stream_id' => 1, 'weight' => 500]]], $W);
    check($res['isError'] && strpos($res['text'], 'from 0 to 100') !== false, 'a weight out of range comes back as a readable error', $res['text']);
    $res = $rpc('orbitra_set_stream_weights', ['campaign_id' => 42, 'weights' => [['stream_id' => 1, 'weight' => 0], ['stream_id' => 2, 'weight' => 0]]], $W);
    check($res['isError'] && strpos($res['text'], 'weight 0') !== false && (int) $streamState()[1]['weight'] === 60,
        'zero everywhere is refused and nothing is written', $res['text']);
    $res = $rpc('orbitra_set_stream_weights', ['campaign_id' => 999, 'weights' => [['stream_id' => 1, 'weight' => 10]]], $W);
    check($res['isError'] && strpos($res['text'], 'Campaign 999 not found') !== false, 'an unknown campaign is a clean error', $res['text']);

    // --- set_stream_active ----------------------------------------------------
    $res = $rpc('orbitra_set_stream_active', ['campaign_id' => 42, 'streams' => [['stream_id' => 1, 'is_active' => false]]], $W);
    check(!$res['isError'] && (int) $streamState()[1]['is_active'] === 0 && (int) $streamState()[2]['is_active'] === 1, 'a stream is switched off', $res['text']);
    $res = $rpc('orbitra_set_stream_active', ['campaign_id' => 42, 'streams' => [['stream_id' => $newId, 'is_active' => false]]], $W);
    check(!$res['isError'] && (int) $streamState()[$newId]['is_active'] === 0, 'a second stream is switched off while one stays on', $res['text']);
    $res = $rpc('orbitra_set_stream_active', ['campaign_id' => 42, 'streams' => [['stream_id' => 2, 'is_active' => false]]], $W);
    check($res['isError'] && strpos($res['text'], 'last enabled stream') !== false && (int) $streamState()[2]['is_active'] === 1,
        'the last enabled stream stays on', $res['text']);
    $res = $rpc('orbitra_set_stream_active', ['campaign_id' => 42, 'streams' => [['stream_id' => 1, 'is_active' => true]]], $W);
    check(!$res['isError'] && (int) $streamState()[1]['is_active'] === 1, '…and back on', $res['text']);

    // --- set_campaign_state ---------------------------------------------------
    $res = $rpc('orbitra_set_campaign_state', ['campaign_id' => 42, 'state' => 'paused'], $W);
    check(!$res['isError'] && $pdo->query("SELECT state FROM campaigns WHERE id = 42")->fetchColumn() === 'disabled'
        && strpos($res['text'], 'sync_ads=true') !== false, 'paused stops the campaign and the answer mentions the ads', $res['text']);
    check((int) $streamState()[1]['weight'] === 60 && count($streamState()) === 3, 'stopping leaves the streams as they were');
    $res = $rpc('orbitra_set_campaign_state', ['campaign_id' => 42, 'state' => 'active'], $W);
    check(!$res['isError'] && $pdo->query("SELECT state FROM campaigns WHERE id = 42")->fetchColumn() === 'active', 'active starts it again', $res['text']);

    // --- set_rotation_weights -------------------------------------------------
    $res = $rpc('orbitra_set_rotation_weights', ['campaign_id' => 42, 'stream_id' => 2, 'offers' => [['id' => 8, 'weight' => 75]]], $W);
    $custom = json_decode((string) $streamState()[2]['schema_custom_json'], true);
    check(!$res['isError'] && $custom['offers'] === [['id' => 7, 'weight' => 100], ['id' => 8, 'weight' => 75]] && $custom['landings'] === [['id' => 3, 'weight' => 100]],
        'one offer is re-weighed inside the stream', $res['text']);
    $res = $rpc('orbitra_set_rotation_weights', ['campaign_id' => 42, 'stream_id' => 2, 'offers' => [['id' => 9, 'weight' => 10]]], $W);
    check($res['isError'] && strpos($res['text'], 'is not in the rotation') !== false, 'an offer outside the rotation is refused', $res['text']);
    $res = $rpc('orbitra_set_rotation_weights', ['campaign_id' => 42, 'stream_id' => 9, 'offers' => [['id' => 8, 'weight' => 10]]], $W);
    check($res['isError'] && strpos($res['text'], 'does not belong to campaign 42') !== false, "another campaign's stream is refused", $res['text']);

    // Auto on → refused unless the caller turns it off.
    $custom['auto'] = ['offers' => ['enabled' => true, 'key' => 'rot_qa', 'metric' => 'sales']];
    $pdo->prepare('UPDATE streams SET schema_custom_json = ? WHERE id = 2')->execute([json_encode($custom)]);
    $res = $rpc('orbitra_set_rotation_weights', ['campaign_id' => 42, 'stream_id' => 2, 'offers' => [['id' => 7, 'weight' => 10]]], $W);
    check($res['isError'] && strpos($res['text'], 'Auto-optimisation is on') !== false, 'Auto on: refused with the way out in the message', $res['text']);
    $res = $rpc('orbitra_set_rotation_weights', ['campaign_id' => 42, 'stream_id' => 2, 'offers' => [['id' => 7, 'weight' => 10]], 'turn_off_auto' => true], $W);
    $custom = json_decode((string) $streamState()[2]['schema_custom_json'], true);
    check(!$res['isError'] && $custom['auto']['offers']['enabled'] === false && $custom['offers'][0]['weight'] === 10, 'turn_off_auto writes the weight and switches Auto off', $res['text']);

    // --- who may --------------------------------------------------------------
    foreach (['orbitra_set_stream_weights' => ['campaign_id' => 42, 'weights' => [['stream_id' => 1, 'weight' => 5]]],
              'orbitra_set_stream_active' => ['campaign_id' => 42, 'streams' => [['stream_id' => 1, 'is_active' => false]]],
              'orbitra_set_campaign_state' => ['campaign_id' => 42, 'state' => 'paused'],
              'orbitra_set_rotation_weights' => ['campaign_id' => 42, 'stream_id' => 2, 'offers' => [['id' => 7, 'weight' => 5]]]] as $tool => $args) {
        $res = $rpc($tool, $args, 'QA_ADMIN_READ');
        check($res['isError'] && stripos($res['text'], 'read-only') !== false, "$tool needs a write key", $res['text']);
        $res = $rpc($tool, $args, 'QA_VIEWER_WRITE');
        check($res['isError'] && stripos($res['text'], 'forbidden') !== false, "$tool is closed to a user without campaign rights", $res['text']);
        $res = $rpc($tool, $args, 'QA_SCOPED_WRITE');
        check($res['isError'] && stripos($res['text'], 'forbidden') !== false, "$tool stays closed to a user whose own campaigns do not include it", $res['text']);
    }
    $res = $rpc('orbitra_set_stream_weights', ['campaign_id' => 44, 'weights' => [['stream_id' => 10, 'weight' => 55]]], 'QA_SCOPED_WRITE');
    check(!$res['isError'] && (int) $pdo->query('SELECT weight FROM streams WHERE id = 10')->fetchColumn() === 55,
        'the same user may change a campaign of their own', $res['text']);
    check((int) $streamState()[1]['weight'] === 60 && $pdo->query("SELECT state FROM campaigns WHERE id = 42")->fetchColumn() === 'active',
        'none of the refused calls wrote anything');

    // --- a text test started through MCP carries the "model wrote it" mark -----
    $res = $rpc('orbitra_save_text_test', ['landing_id' => 3, 'original_text' => 'Buy now', 'variants' => ['Order today', 'Get it now'], 'mode' => 'auto'], $W);
    $rows = $pdo->query("SELECT v.text, v.source, v.is_original FROM text_test_variants v JOIN text_tests t ON t.id = v.test_id WHERE t.landing_id = 3 ORDER BY v.id")->fetchAll(PDO::FETCH_ASSOC);
    check(!$res['isError'] && count($rows) === 3 && (int) $rows[0]['is_original'] === 1
        && ($rows[1]['source'] ?? '') === 'ai' && ($rows[2]['source'] ?? '') === 'ai' && ($rows[1]['text'] ?? '') === 'Order today',
        'a text test started over MCP marks its variants as the model\'s, as the stdio server does', json_encode([$res['text'], $rows]));

    // --- audit ----------------------------------------------------------------
    $n = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE resource = 'Campaign' AND resource_id = '42'
        AND (context LIKE 'campaign_set_stream_weights:%' OR context LIKE 'campaign_set_state:%' OR context LIKE 'stream_set_rotation_weights:%')")->fetchColumn();
    check($n >= 4, "every successful call left a line in the audit log ($n)");
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $harness->stop();
}

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
