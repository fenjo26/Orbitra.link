<?php
// core/AiAgent.php
//
// The panel's built-in assistant: a chat whose model can call the same tools
// the remote MCP connector exposes (core/McpTools.php over mcp/tools.json),
// with the signed-in user's own permissions.
//
// One HTTP request = at most one model turn (the client calls step() again
// while the status is 'continue'), so nginx's 60-second limit never meets a
// stack of model calls, and the chat window can show progress between turns.
//
// Tools run through a per-user API key the agent creates for itself
// ("Orbitra AI assistant"), so api.php applies exactly that user's role,
// resource access and campaign scope — the assistant can never do what the
// user could not do by hand.
//
// Changes wait for a click: with confirm_mode 'writes' (default) every
// write tool call is held until the user presses "Run"; with 'dangerous'
// only deletions, bulk operations and raw API writes are held.

require_once __DIR__ . '/AiClient.php';
require_once __DIR__ . '/McpTools.php';

class AiAgent
{
    public const MAX_STEPS_PER_MESSAGE = 12;
    public const MAX_TOOL_RESULT = 8000;
    public const HISTORY_ENTRIES = 40;
    public const KEY_NAME = 'Orbitra AI assistant';

    /** Languages the panel ships; the model is told to answer in the user's. */
    public const LANGUAGE_NAMES = [
        'en' => 'English', 'ru' => 'Russian', 'uk' => 'Ukrainian', 'es' => 'Spanish',
        'de' => 'German', 'fr' => 'French', 'zh' => 'Simplified Chinese',
    ];

    // ---- chats --------------------------------------------------------------

    public static function listChats(PDO $pdo, int $userId): array
    {
        $st = $pdo->prepare('SELECT id, title, updated_at FROM ai_chats WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 50');
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function loadChat(PDO $pdo, int $userId, int $chatId): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ai_chats WHERE id = ? AND user_id = ?');
        $st->execute([$chatId, $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['messages'] = json_decode((string) $row['messages_json'], true) ?: [];
        $row['pending'] = json_decode((string) ($row['pending_json'] ?? ''), true) ?: [];
        unset($row['messages_json'], $row['pending_json']);
        $row['steps'] = (int) ($row['steps'] ?? 0);
        return $row;
    }

    public static function createChat(PDO $pdo, int $userId, string $firstMessage): int
    {
        $title = mb_substr(trim(preg_replace('/\s+/u', ' ', $firstMessage)), 0, 80);
        $st = $pdo->prepare("INSERT INTO ai_chats (user_id, title, messages_json, pending_json, steps) VALUES (?, ?, '[]', '[]', 0)");
        $st->execute([$userId, $title !== '' ? $title : '…']);
        return (int) $pdo->lastInsertId();
    }

    public static function deleteChat(PDO $pdo, int $userId, int $chatId): bool
    {
        $st = $pdo->prepare('DELETE FROM ai_chats WHERE id = ? AND user_id = ?');
        $st->execute([$chatId, $userId]);
        return $st->rowCount() > 0;
    }

    private static function saveChat(PDO $pdo, array $chat): void
    {
        $st = $pdo->prepare('UPDATE ai_chats SET messages_json = ?, pending_json = ?, steps = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $st->execute([
            json_encode($chat['messages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($chat['pending'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (int) $chat['steps'],
            (int) $chat['id'],
        ]);
    }

    // ---- tools --------------------------------------------------------------

    /** Manifest tools in the provider-neutral shape AiClient takes. */
    public static function toolDefs(): array
    {
        $out = [];
        foreach (mcpTools() as $t) {
            $schema = $t['inputSchema'] ?? ['type' => 'object'];
            if (is_array($schema)) {
                unset($schema['$schema'], $schema['additionalProperties']);
                if (!isset($schema['properties']) || $schema['properties'] === [] ) {
                    $schema['properties'] = new stdClass();
                }
                $schema = mcpNormalizeSchema($schema);
            }
            $out[] = ['name' => $t['name'], 'description' => self::shorten((string) ($t['description'] ?? ''), 300), 'parameters' => self::compactSchema($schema)];
        }
        return $out;
    }

    /**
     * Tool definitions ride along with every model turn, so they are the
     * biggest fixed token cost: descriptions are cut to their first
     * sentences, property descriptions to 120 characters.
     */
    private static function shorten(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $dot = mb_strrpos($cut, '. ');
        return $dot !== false && $dot > $max / 2 ? mb_substr($cut, 0, $dot + 1) : rtrim($cut) . '…';
    }

    private static function compactSchema($schema)
    {
        if ($schema instanceof stdClass || !is_array($schema)) {
            return $schema;
        }
        foreach ($schema as $k => $v) {
            if ($k === 'description' && is_string($v)) {
                $schema[$k] = self::shorten($v, 120);
            } elseif (is_array($v)) {
                $schema[$k] = self::compactSchema($v);
            }
        }
        return $schema;
    }

    public static function isWrite(array $tool, array $args): bool
    {
        if (($tool['handler'] ?? '') === 'api_request') {
            return strtoupper((string) ($args['method'] ?? 'GET')) === 'POST';
        }
        return mcpToolWrites($tool);
    }

    public static function isDangerous(array $tool, array $args): bool
    {
        $name = (string) $tool['name'];
        if (preg_match('/(delete|bulk|reissue|run_ssl)/', $name)) {
            return true;
        }
        if (($tool['handler'] ?? '') === 'api_request' && strtoupper((string) ($args['method'] ?? 'GET')) === 'POST') {
            return true;
        }
        return false;
    }

    /** The user's own key for tool calls, created on first use. */
    public static function userApiKey(PDO $pdo, int $userId): string
    {
        $st = $pdo->prepare('SELECT api_key FROM user_api_keys WHERE user_id = ? AND key_name = ? LIMIT 1');
        $st->execute([$userId, self::KEY_NAME]);
        $key = $st->fetchColumn();
        if (is_string($key) && $key !== '') {
            return $key;
        }
        $key = 'ai_' . bin2hex(random_bytes(20));
        $pdo->prepare('INSERT INTO user_api_keys (user_id, key_name, api_key, permissions) VALUES (?, ?, ?, ?)')
            ->execute([$userId, self::KEY_NAME, $key, 'write']);
        return $key;
    }

    // ---- conversation -----------------------------------------------------------

    public static function systemPrompt(string $lang, array $ctx = []): string
    {
        $langName = self::LANGUAGE_NAMES[$lang] ?? 'English';
        $lines = [
            'You are the assistant built into Orbitra, a self-hosted traffic tracker for media buyers (campaigns, streams, offers, landings, traffic sources, conversions, costs).',
            "Reply in {$langName} unless the user writes in another language — then use theirs.",
            'Use the tools to read real data before you answer; never invent numbers, ids or names. Quote figures as the tools return them, with the period they cover.',
            'Be brief and practical: short paragraphs or short lists, money with currency, percentages with one decimal.',
            'When asked to optimise, look at campaign/offer/landing stats over a sensible window, name the concrete problem (spend without sales, low CR, negative ROI, bots) and propose concrete actions; offer to apply them with the tools.',
            'Write actions (create, update, delete, pause) may be held for the user to confirm in the interface; that is normal — just call the tool with the right arguments. Do not ask "shall I?" in text when a tool call can wait for the click instead.',
            'Never delete anything the user did not explicitly ask to delete.',
            'Campaign rules (notify / pause a campaign / switch an offer off in the split) and landing text A/B tests are available through their tools.',
        ];
        if (!empty($ctx['today'])) {
            $lines[] = 'Today is ' . $ctx['today'] . ($ctx['timezone'] ?? '' ? ' (' . $ctx['timezone'] . ')' : '') . '.';
        }
        if (!empty($ctx['role'])) {
            $lines[] = 'The user\'s role in the tracker: ' . $ctx['role'] . '.';
        }
        return implode("\n", $lines);
    }

    /** Model-facing history: the last N entries, never starting on a tool result. */
    private static function trimmedHistory(array $messages): array
    {
        $msgs = array_values(array_filter($messages, static fn($m) => in_array($m['role'] ?? '', ['user', 'assistant', 'tool'], true)));
        if (count($msgs) <= self::HISTORY_ENTRIES) {
            return $msgs;
        }
        $msgs = array_slice($msgs, -self::HISTORY_ENTRIES);
        while ($msgs && $msgs[0]['role'] !== 'user') {
            array_shift($msgs);
        }
        return $msgs;
    }

    /** Append a user message and run the first step. */
    public static function send(PDO $pdo, AiClient $client, array $user, ?int $chatId, string $text, array $ctx = []): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('empty_message');
        }
        $userId = (int) $user['id'];
        if (!$chatId) {
            $chatId = self::createChat($pdo, $userId, $text);
        }
        $chat = self::loadChat($pdo, $userId, $chatId);
        if (!$chat) {
            throw new InvalidArgumentException('chat_not_found');
        }
        if ($chat['pending']) {
            // A new message abandons the held calls; the model must see them
            // answered, or the next turn is malformed for every provider.
            foreach ($chat['pending'] as $p) {
                $chat['messages'][] = ['role' => 'tool', 'id' => $p['id'], 'name' => $p['name'], 'content' => 'Not run: the user sent a new message instead of confirming.', 'status' => 'declined'];
            }
            $chat['pending'] = [];
        }
        $chat['messages'][] = ['role' => 'user', 'text' => mb_substr($text, 0, 8000), 'at' => gmdate('c')];
        $chat['steps'] = 0;
        self::saveChat($pdo, $chat);
        return self::step($pdo, $client, $user, $chatId, $ctx);
    }

    /** One model turn (plus running the calls that need no confirmation). */
    public static function step(PDO $pdo, AiClient $client, array $user, int $chatId, array $ctx = [], array $opts = []): array
    {
        $chat = self::loadChat($pdo, (int) $user['id'], $chatId);
        if (!$chat) {
            throw new InvalidArgumentException('chat_not_found');
        }
        if ($chat['pending']) {
            return self::state($chat, 'confirm');
        }
        $last = end($chat['messages']);
        if ($last && $last['role'] === 'assistant' && empty($last['tool_calls'])) {
            return self::state($chat, 'done'); // nothing to continue
        }
        if ($chat['steps'] >= self::MAX_STEPS_PER_MESSAGE) {
            $chat['messages'][] = ['role' => 'assistant', 'text' => '', 'notice' => 'step_limit', 'tool_calls' => []];
            self::saveChat($pdo, $chat);
            return self::state($chat, 'done');
        }

        $tools = self::toolDefs();
        $lang = (string) ($ctx['lang'] ?? 'en');
        try {
            $reply = $client->chat(self::systemPrompt($lang, $ctx), self::trimmedHistory($chat['messages']), $tools);
        } catch (\Throwable $e) {
            return self::state($chat, 'error', $e->getMessage());
        }
        $chat['steps']++;
        $known = mcpTools();
        $calls = [];
        foreach ($reply['tool_calls'] as $c) {
            if (!isset($known[$c['name']])) {
                // A hallucinated tool: answer it as an error the model can read.
                $calls[] = $c + ['unknown' => true];
                continue;
            }
            $calls[] = $c;
        }
        $chat['messages'][] = [
            'role' => 'assistant',
            'text' => (string) $reply['text'],
            'tool_calls' => array_map(static fn($c) => array_intersect_key($c, array_flip(['id', 'name', 'args', 'extra'])), $calls),
            'usage' => $reply['usage'] ?? null,
            'at' => gmdate('c'),
        ];
        if (!$calls) {
            self::saveChat($pdo, $chat);
            return self::state($chat, 'done');
        }

        $confirmMode = (string) ($ctx['confirm_mode'] ?? 'writes');
        $hold = [];
        $run = [];
        foreach ($calls as $c) {
            if (!empty($c['unknown'])) {
                $run[] = $c;
                continue;
            }
            $tool = $known[$c['name']];
            $needs = $confirmMode === 'dangerous'
                ? self::isDangerous($tool, $c['args'])
                : (self::isWrite($tool, $c['args']) || self::isDangerous($tool, $c['args']));
            if ($needs) {
                $hold[] = $c + ['dangerous' => self::isDangerous($tool, $c['args'])];
            } else {
                $run[] = $c;
            }
        }
        // Reads run right away even when a write in the same turn waits.
        foreach ($run as $c) {
            $chat['messages'][] = self::execute($pdo, $user, $c, $opts);
        }
        if ($hold) {
            $chat['pending'] = array_map(static fn($c) => [
                'id' => $c['id'], 'name' => $c['name'], 'args' => $c['args'], 'dangerous' => !empty($c['dangerous']),
            ], $hold);
            self::saveChat($pdo, $chat);
            return self::state($chat, 'confirm');
        }
        self::saveChat($pdo, $chat);
        return self::state($chat, 'continue');
    }

    /** The user's answer to held calls: run them all, or decline them all. */
    public static function confirm(PDO $pdo, array $user, int $chatId, bool $approve, array $opts = []): array
    {
        $chat = self::loadChat($pdo, (int) $user['id'], $chatId);
        if (!$chat) {
            throw new InvalidArgumentException('chat_not_found');
        }
        foreach ($chat['pending'] as $p) {
            $chat['messages'][] = $approve
                ? self::execute($pdo, $user, $p, $opts)
                : ['role' => 'tool', 'id' => $p['id'], 'name' => $p['name'], 'status' => 'declined',
                    'content' => 'The user declined this action. Do not retry it; ask what they want instead.'];
        }
        $chat['pending'] = [];
        self::saveChat($pdo, $chat);
        return self::state($chat, 'continue');
    }

    /** Run one tool call; returns the tool entry for the history. */
    private static function execute(PDO $pdo, array $user, array $call, array $opts): array
    {
        $entry = ['role' => 'tool', 'id' => (string) $call['id'], 'name' => (string) $call['name']];
        if (!empty($call['unknown'])) {
            return $entry + ['status' => 'error', 'content' => 'Error: there is no tool named "' . $call['name'] . '".'];
        }
        try {
            $tool = mcpTools()[$call['name']];
            $runner = $opts['runner'] ?? null;
            $data = is_callable($runner)
                ? $runner($tool, $call['args'])
                : mcpDispatchTool($pdo, self::userApiKey($pdo, (int) $user['id']), $tool, is_array($call['args']) ? $call['args'] : []);
            $text = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (mb_strlen($text) > self::MAX_TOOL_RESULT) {
                $text = mb_substr($text, 0, self::MAX_TOOL_RESULT) . "\n…[truncated: ask for a narrower query]";
            }
            return $entry + ['status' => 'ok', 'content' => $text];
        } catch (\Throwable $e) {
            return $entry + ['status' => 'error', 'content' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * What the chat window shows: user and assistant text, one line per tool
     * call with its outcome, the held calls. Raw tool payloads stay server-side.
     */
    public static function state(array $chat, string $status, ?string $error = null): array
    {
        $view = [];
        $results = [];
        foreach ($chat['messages'] as $m) {
            if ($m['role'] === 'tool') {
                $results[$m['id']] = $m['status'] ?? 'ok';
            }
        }
        foreach ($chat['messages'] as $m) {
            if ($m['role'] === 'user') {
                $view[] = ['type' => 'user', 'text' => $m['text']];
            } elseif ($m['role'] === 'assistant') {
                if (!empty($m['notice'])) {
                    $view[] = ['type' => 'notice', 'code' => $m['notice']];
                }
                if (($m['text'] ?? '') !== '') {
                    $view[] = ['type' => 'assistant', 'text' => $m['text']];
                }
                foreach (($m['tool_calls'] ?? []) as $c) {
                    $view[] = ['type' => 'tool', 'name' => $c['name'], 'args' => $c['args'],
                        'status' => $results[$c['id']] ?? 'pending'];
                }
            }
        }
        $out = [
            'chat_id' => (int) $chat['id'],
            'title' => (string) ($chat['title'] ?? ''),
            'status' => $status,
            'messages' => $view,
            'pending' => $chat['pending'] ?? [],
        ];
        if ($error !== null) {
            $out['error'] = $error;
        }
        return $out;
    }
}
