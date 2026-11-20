<?php
// tests/ai_agent_test.php
//
// The built-in AI assistant without a network: AiClient's two wire formats
// (OpenAI-compatible — also Gemini/GLM/DeepSeek/OpenRouter/Ollama — and
// Anthropic) against a scripted transport, and AiAgent's step loop: reads run
// at once, writes wait for confirmation, declines reach the model, unknown
// tools come back as errors, the step limit stops a runaway loop, and the
// chat is private to its user.
//
// Run: php tests/ai_agent_test.php

require_once __DIR__ . '/../core/AiAgent.php';

$failures = 0;
$assert = function (string $label, $cond, string $detail = '') use (&$failures) {
    if ($cond) {
        echo "  ok  $label\n";
    } else {
        $failures++;
        echo "FAIL  $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
};

// ---- AiClient: OpenAI wire ---------------------------------------------------
$captured = [];
AiClient::$transport = function (string $url, array $headers, array $body) use (&$captured) {
    $captured[] = compact('url', 'headers', 'body');
    return [200, ['choices' => [['message' => [
        'content' => 'Looking.',
        'tool_calls' => [[
            'id' => 'c1', 'type' => 'function',
            'function' => ['name' => 'orbitra_list_campaigns', 'arguments' => '{"limit":5}'],
            'extra_content' => ['google' => ['thought_signature' => 'SIG']],
        ]],
    ]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3]], ''];
};
$c = new AiClient('glm', '', 'KEY', '');
$r = $c->chat('SYS', [
    ['role' => 'user', 'text' => 'hi'],
    ['role' => 'assistant', 'text' => '', 'tool_calls' => [['id' => 'p1', 'name' => 'x', 'args' => [], 'extra' => ['google' => ['thought_signature' => 'OLD']]]]],
    ['role' => 'tool', 'id' => 'p1', 'name' => 'x', 'content' => 'R'],
], [['name' => 'orbitra_list_campaigns', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass()]]]);
$req = $captured[0];
$assert('GLM preset → z.ai OpenAI-compatible endpoint', $req['url'] === 'https://api.z.ai/api/paas/v4/chat/completions', $req['url']);
$assert('bearer auth', in_array('Authorization: Bearer KEY', $req['headers'], true));
$assert('default GLM model is the economical one', $req['body']['model'] === 'glm-4.5-flash');
$assert('system first, tool result as role=tool', $req['body']['messages'][0]['role'] === 'system'
    && $req['body']['messages'][3]['role'] === 'tool' && $req['body']['messages'][3]['tool_call_id'] === 'p1');
$assert('thought signature echoed back (Gemini 3)', ($req['body']['messages'][2]['tool_calls'][0]['extra_content']['google']['thought_signature'] ?? '') === 'OLD');
$assert('empty args serialise as {}', $req['body']['messages'][2]['tool_calls'][0]['function']['arguments'] === '{}');
$assert('tools in function format', $req['body']['tools'][0]['type'] === 'function' && $req['body']['tool_choice'] === 'auto');
$assert('parsed text + call + args', $r['text'] === 'Looking.' && $r['tool_calls'][0]['name'] === 'orbitra_list_campaigns' && $r['tool_calls'][0]['args'] === ['limit' => 5]);
$assert('signature kept on the parsed call', ($r['tool_calls'][0]['extra']['google']['thought_signature'] ?? '') === 'SIG');
$assert('usage', $r['usage'] === ['in' => 10, 'out' => 3]);
$assert('GLM rounds disable the reasoning pass (thinking latency vs the 55 s cap)', ($req['body']['thinking']['type'] ?? '') === 'disabled');

$captured = [];
(new AiClient('glm_coding', '', 'K', ''))->chat('S', [['role' => 'user', 'text' => 'q']]);
$assert('GLM Coding Plan preset → coding endpoint, plan default model, thinking off',
    $captured[0]['url'] === 'https://api.z.ai/api/coding/paas/v4/chat/completions'
    && $captured[0]['body']['model'] === 'glm-4.6'
    && ($captured[0]['body']['thinking']['type'] ?? '') === 'disabled',
    $captured[0]['url']);

$captured = [];
(new AiClient('gemini', 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', 'G', 'gemini-x'))->chat('S', [['role' => 'user', 'text' => 'q']]);
$assert('pasted full endpoint is trimmed to the base', $captured[0]['url'] === 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', $captured[0]['url']);
$assert('no tools → no tool_choice', !isset($captured[0]['body']['tools']) && !isset($captured[0]['body']['tool_choice']));
$captured = [];
(new AiClient('ollama', '', '', ''))->chat('S', [['role' => 'user', 'text' => 'q']]);
$assert('ollama: local default, no auth header', $captured[0]['url'] === 'http://127.0.0.1:11434/v1/chat/completions' && $captured[0]['headers'] === []);

$captured = [];
(new AiClient('openai', '', 'K', ''))->chat('S', [['role' => 'user', 'text' => 'q']]);
$assert('non-GLM providers get no thinking flag', !isset($captured[0]['body']['thinking']));

// the endpoint rejects the thinking flag → one retry without it, then the answer
$seq = [
    [400, ['error' => ['message' => 'Unknown parameter: thinking']], ''],
    [200, ['choices' => [['message' => ['content' => 'ok']]]], ''],
];
$i = 0; $bodies = [];
AiClient::$transport = function (string $url, array $headers, array $body) use (&$seq, &$i, &$bodies) {
    $bodies[] = $body;
    return $seq[$i++];
};
$r = (new AiClient('glm_coding', '', 'K', ''))->chat('S', [['role' => 'user', 'text' => 'q']]);
$assert('rejected thinking flag → one retry without it', count($bodies) === 2
    && ($bodies[0]['thinking']['type'] ?? '') === 'disabled'
    && !isset($bodies[1]['thinking']) && $r['text'] === 'ok');

// errors
AiClient::$transport = fn() => [401, ['error' => ['message' => 'Invalid API key']], ''];
try {
    (new AiClient('openai', '', 'bad', ''))->chat('S', [['role' => 'user', 'text' => 'q']]);
    $assert('401 throws', false);
} catch (RuntimeException $e) {
    $assert('401 → readable error with hint', strpos($e->getMessage(), 'Invalid API key') !== false && strpos($e->getMessage(), 'check the API key') !== false, $e->getMessage());
}

// ---- AiClient: Anthropic wire ----------------------------------------------------
$captured = [];
AiClient::$transport = function (string $url, array $headers, array $body) use (&$captured) {
    $captured[] = compact('url', 'headers', 'body');
    return [200, ['content' => [
        ['type' => 'text', 'text' => 'Sure.'],
        ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'orbitra_get_metrics', 'input' => ['date_range' => 'today']],
    ], 'usage' => ['input_tokens' => 7, 'output_tokens' => 2]], ''];
};
$r = (new AiClient('anthropic', '', 'AK', ''))->chat('SYS', [
    ['role' => 'user', 'text' => 'hi'],
    ['role' => 'assistant', 'text' => 'x', 'tool_calls' => [['id' => 'a', 'name' => 'n', 'args' => []], ['id' => 'b', 'name' => 'n', 'args' => []]]],
    ['role' => 'tool', 'id' => 'a', 'name' => 'n', 'content' => '1'],
    ['role' => 'tool', 'id' => 'b', 'name' => 'n', 'content' => '2'],
    ['role' => 'user', 'text' => 'next'],
], [['name' => 't', 'description' => 'd', 'parameters' => ['type' => 'object']]]);
$b = $captured[0]['body'];
$assert('anthropic endpoint + headers', $captured[0]['url'] === 'https://api.anthropic.com/v1/messages'
    && in_array('x-api-key: AK', $captured[0]['headers'], true) && in_array('anthropic-version: 2023-06-01', $captured[0]['headers'], true));
$assert('system separate', $b['system'] === 'SYS');
$assert('roles alternate: tool results and the next user text merge into one user turn',
    count($b['messages']) === 3 && $b['messages'][2]['role'] === 'user'
    && $b['messages'][2]['content'][0]['type'] === 'tool_result' && $b['messages'][2]['content'][2]['type'] === 'text', json_encode($b['messages']));
$assert('tools as input_schema', isset($b['tools'][0]['input_schema']));
$assert('parsed tool_use', $r['text'] === 'Sure.' && $r['tool_calls'][0]['args'] === ['date_range' => 'today']);

// ---- AiAgent ----------------------------------------------------------------------
$tmpDb = sys_get_temp_dir() . '/orbitra_ai_agent_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$pdo = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$cfg = file_get_contents(__DIR__ . '/../config.php');
$start = strpos($cfg, "if (!function_exists('orbitraEnsureAiTables'))");
$end = strpos($cfg, "if (!function_exists('orbitraEnsureCampaignRuleTables'))", $start);
eval(substr($cfg, $start, $end - $start));
orbitraEnsureAiTables($pdo);
$pdo->exec('CREATE TABLE user_api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, key_name TEXT, api_key TEXT, permissions TEXT)');

$assert('every manifest tool converts to a provider schema', count(AiAgent::toolDefs()) === count(mcpTools()));
$defs = array_column(AiAgent::toolDefs(), null, 'name');
$assert('parameter-less tool schema has an object "properties"', $defs['orbitra_all_offers']['parameters']['properties'] instanceof stdClass);
$assert('$schema stripped', !isset($defs['orbitra_all_offers']['parameters']['$schema']));
$tools = mcpTools();
$assert('classification: list is a read', !AiAgent::isWrite($tools['orbitra_list_campaigns'], []));
$assert('classification: create is a write, not dangerous', AiAgent::isWrite($tools['orbitra_create_offer'], []) && !AiAgent::isDangerous($tools['orbitra_create_offer'], []));
$assert('classification: delete is dangerous', AiAgent::isDangerous($tools['orbitra_delete_campaign'], []));
$assert('classification: raw GET is a read, raw POST dangerous',
    !AiAgent::isWrite($tools['orbitra_api_request'], ['method' => 'GET']) && AiAgent::isDangerous($tools['orbitra_api_request'], ['method' => 'POST']));

$key = AiAgent::userApiKey($pdo, 3);
$assert('per-user key created once', $key === AiAgent::userApiKey($pdo, 3) && (int) $pdo->query("SELECT COUNT(*) FROM user_api_keys WHERE user_id=3")->fetchColumn() === 1);

// Scripted model: turn 1 reads + creates; turn 2 answers.
$script = [];
AiClient::$transport = function (string $url, array $headers, array $body) use (&$script, &$captured) {
    $captured[] = $body;
    return [200, ['choices' => [['message' => array_shift($script)]]], ''];
};
$captured = [];
$ran = [];
$runner = function (array $tool, array $args) use (&$ran) {
    $ran[] = $tool['name'];
    return ['status' => 'success', 'data' => ['tool' => $tool['name'], 'args' => $args]];
};
$client = new AiClient('openai', '', 'K', 'm');
$user = ['id' => 3];
$ctx = ['lang' => 'ru', 'confirm_mode' => 'writes'];

$script = [[
    'content' => '',
    'tool_calls' => [
        ['id' => 'r1', 'type' => 'function', 'function' => ['name' => 'orbitra_list_offers', 'arguments' => '{}']],
        ['id' => 'w1', 'type' => 'function', 'function' => ['name' => 'orbitra_create_offer', 'arguments' => '{"name":"DE test"}']],
        ['id' => 'x1', 'type' => 'function', 'function' => ['name' => 'orbitra_made_up', 'arguments' => '{}']],
    ],
]];
// send() goes through the real dispatcher; inject the runner via step() by
// sending the user message first with an empty script turn.
$s = AiAgent::send($pdo, $client, $user, null, 'создай оффер DE test', $ctx);
$chatId = $s['chat_id'];
$assert('send: system prompt asks for Russian', strpos($captured[0]['messages'][0]['content'], 'Reply in Russian') !== false);
$assert('write held for confirmation', $s['status'] === 'confirm' && count($s['pending']) === 1 && $s['pending'][0]['name'] === 'orbitra_create_offer');
$view = array_column($s['messages'], 'status', 'name');
$assert('unknown tool answered as an error', ($view['orbitra_made_up'] ?? '') === 'error');
$assert('user text in the view', $s['messages'][0] === ['type' => 'user', 'text' => 'создай оффер DE test']);

$chat = AiAgent::loadChat($pdo, 3, $chatId);
$readEntry = array_values(array_filter($chat['messages'], fn($m) => $m['role'] === 'tool' && $m['id'] === 'r1'))[0] ?? null;
$assert('read ran without waiting (real dispatcher attempted)', $readEntry !== null);
$assert('chat is private: other user cannot load it', AiAgent::loadChat($pdo, 4, $chatId) === null);

$s = AiAgent::confirm($pdo, $user, $chatId, true, ['runner' => $runner]);
$assert('approve runs the held write', $ran === ['orbitra_create_offer'] && $s['status'] === 'continue');

$script = [['content' => 'Готово: оффер создан.']];
$s = AiAgent::step($pdo, $client, $user, $chatId, $ctx, ['runner' => $runner]);
$assert('final answer → done', $s['status'] === 'done' && end($s['messages'])['text'] === 'Готово: оффер создан.');
$lastReq = end($captured);
$toolMsgs = array_values(array_filter($lastReq['messages'], fn($m) => $m['role'] === 'tool'));
$assert('model got a result for every call it made', count($toolMsgs) === 3);
$s2 = AiAgent::step($pdo, $client, $user, $chatId, $ctx);
$assert('step after done is a no-op', $s2['status'] === 'done' && count($captured) === 2);

// decline path
$script = [['content' => '', 'tool_calls' => [['id' => 'd1', 'type' => 'function', 'function' => ['name' => 'orbitra_delete_campaign', 'arguments' => '{"id":5}']]]]];
$s = AiAgent::send($pdo, $client, $user, $chatId, 'удали кампанию 5', ['confirm_mode' => 'dangerous'] + $ctx);
$assert('dangerous mode still holds a delete', $s['status'] === 'confirm' && $s['pending'][0]['dangerous'] === true);
$ran = [];
$s = AiAgent::confirm($pdo, $user, $chatId, false, ['runner' => $runner]);
$assert('decline runs nothing', $ran === []);
$chat = AiAgent::loadChat($pdo, 3, $chatId);
$last = end($chat['messages']);
$assert('decline is reported to the model', $last['role'] === 'tool' && $last['status'] === 'declined');

// dangerous mode lets plain writes through
$script = [['content' => '', 'tool_calls' => [['id' => 'w2', 'type' => 'function', 'function' => ['name' => 'orbitra_create_offer', 'arguments' => '{"name":"x"}']]]]];
$ran = [];
AiAgent::send($pdo, $client, $user, $chatId, 'ещё один', ['confirm_mode' => 'dangerous'] + $ctx);
// send() used the real dispatcher (no runner); re-run with the runner to check routing
$chat = AiAgent::loadChat($pdo, 3, $chatId);
$assert('in "dangerous" mode a create runs without confirmation', !$chat['pending']);

// new message abandons held calls
$script = [['content' => '', 'tool_calls' => [['id' => 'w3', 'type' => 'function', 'function' => ['name' => 'orbitra_create_offer', 'arguments' => '{}']]]]];
AiAgent::step($pdo, $client, $user, $chatId, $ctx); // after tool results → model asks to create again → held
$script = [['content' => 'ok']];
$s = AiAgent::send($pdo, $client, $user, $chatId, 'нет, не надо', $ctx);
$chat = AiAgent::loadChat($pdo, 3, $chatId);
$abandoned = array_values(array_filter($chat['messages'], fn($m) => ($m['id'] ?? '') === 'w3' && $m['role'] === 'tool'));
$assert('a new message answers the held call as not run', $abandoned && $abandoned[0]['status'] === 'declined');

// step limit
$pdo->exec("UPDATE ai_chats SET steps = " . AiAgent::MAX_STEPS_PER_MESSAGE . " WHERE id = $chatId");
$pdo->exec("UPDATE ai_chats SET messages_json = json_insert(messages_json, '$[#]', json('{\"role\":\"tool\",\"id\":\"z\",\"name\":\"n\",\"content\":\"x\"}')) WHERE id = $chatId");
$s = AiAgent::step($pdo, $client, $user, $chatId, $ctx);
$assert('step limit stops the loop with a notice', $s['status'] === 'done' && end($s['messages'])['type'] === 'notice');

// provider error surfaces, chat intact
AiClient::$transport = fn() => [429, ['error' => ['message' => 'Quota exceeded']], ''];
$s = AiAgent::send($pdo, $client, $user, $chatId, 'ещё', $ctx);
$assert('provider error → status error with message', $s['status'] === 'error' && strpos($s['error'], 'Quota exceeded') !== false);

$assert('list + delete scoped to the owner', count(AiAgent::listChats($pdo, 3)) === 1 && !AiAgent::deleteChat($pdo, 4, $chatId) && AiAgent::deleteChat($pdo, 3, $chatId));

AiClient::$transport = null;
@unlink($tmpDb);
if ($failures > 0) {
    echo "\nFAILED: $failures assertion(s)\n";
    exit(1);
}
echo "\nAll AI assistant assertions passed.\n";
