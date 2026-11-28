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
// only deletions, bulk operations and raw API writes are held. The card
// may narrow the run — leave an action out, keep only some text variants
// (see confirm()).

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
        'ko' => 'Korean', 'vi' => 'Vietnamese',
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

    /** Tool prefixes exposed only to tracker admins (CRM is admin-only). */
    public const ADMIN_ONLY_PREFIXES = ['orbitra_crm_'];

    public static function isAdminOnlyTool(string $name): bool
    {
        foreach (self::ADMIN_ONLY_PREFIXES as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Manifest tools in the provider-neutral shape AiClient takes. */
    public static function toolDefs(?string $role = null): array
    {
        $isAdmin = $role === null || $role === '' || $role === 'admin';
        $out = [];
        foreach (mcpTools() as $t) {
            if (!$isAdmin && self::isAdminOnlyTool((string) $t['name'])) {
                continue;
            }
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
            'The tracker currency is ' . (string) ($ctx['currency'] ?? '') . ' — always quote and compute money in this currency with its symbol (e.g. ₽ for RUB, € for EUR), never in dollars unless it is actually USD.',
            'When asked to optimise, look at campaign/offer/landing stats over a sensible window, name the concrete problem (spend without sales, low CR, negative ROI, bots) and propose concrete actions; offer to apply them with the tools.',
            'Write actions (create, update, delete, pause) may be held for the user to confirm in the interface; that is normal — just call the tool with the right arguments. Do not ask "shall I?" in text when a tool call can wait for the click instead.',
            'Never delete anything the user did not explicitly ask to delete.',
            'Campaign rules (notify / pause a campaign / switch an offer off in the split) and text A/B tests on local landings and local offer pages are available through their tools.',
            'Routine changes have their own tools — orbitra_set_stream_weights, orbitra_set_stream_active, orbitra_set_rotation_weights, orbitra_set_campaign_state. Use them for stream shares, on/off, offer or landing weights and start/stop; they touch only what you name, unlike orbitra_update_campaign, which re-saves the campaign.',
            'For a text test on a local landing or a local offer page (landing_id or offer_id): take the original text from orbitra_text_test_candidates, write the variants yourself (different angles, same facts, never invented prices or promises), then call orbitra_save_text_test once. The user can drop variants in the confirmation card.',
            'Local landings and local offers: you can build or translate pre-landings, thank-you pages, and simple quizzes using orbitra_create_local_landing / orbitra_write_landing_file (and orbitra_create_local_offer / orbitra_write_offer_file), reading existing pages first with orbitra_list_landing_files / orbitra_read_landing_file. Always create a new landing/offer or a copy (copy_from_id) — never overwrite files of a live landing or offer attached to an active campaign. Never invent prices, discounts, bonuses or guarantees: ask the user if they are missing. Write one file per call (index.html, styles.css, app.js) to stay within the 55s turn limit; PHP files are blocked.',
            'LeadForge (in the sidebar, admin-only) compiles uploaded ZIP landings with CPA order forms: it strips old network handlers and foreign pixels, builds order.php wired to the target network API + ClickID bridge, adds GEO phone masks, a thank-you page and CRM vault sync. Flow: upload ZIP -> Analyze -> pick network, API key/stream, GEO -> Build -> attach to campaign. LeadForge is UI-driven (ZIP upload), not chat-tool driven — always point the user to LeadForge when they need a full CPA network order-form landing.',
        ];
        if (empty($ctx['role']) || $ctx['role'] === 'admin') {
            $lines[] = 'CRM (Anti-Shaving Vault, admin-only) stores every lead with raw & E.164 phone, exact network request/response and S2S postback status, flagging shave suspects, lost-in-transit leads (24h+ without postback after HTTP 200) and duplicates. Use orbitra_crm_leads to inspect leads/KPIs, orbitra_crm_lead_evidence to assemble a dispute packet for network support, and orbitra_crm_update_lead to change a lead status.';
        }
        if (!empty($ctx['page']) && is_array($ctx['page'])) {
            $line = self::pageLine($ctx['page']);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        if (!empty($ctx['today'])) {
            $lines[] = 'Today is ' . $ctx['today'] . ($ctx['timezone'] ?? '' ? ' (' . $ctx['timezone'] . ')' : '') . '.';
        }
        if (!empty($ctx['role'])) {
            $lines[] = 'The user\'s role in the tracker: ' . $ctx['role'] . '.';
        }
        return implode("\n", $lines);
    }

    /** What the panel may say the user has open (core/AiPageContext.php resolves it). */
    public const PAGE_KINDS = ['campaign', 'landing', 'offer', 'crm', 'leadforge'];

    /**
     * The sentence that tells the model which campaign, landing, offer or
     * section the user has open, so "this campaign" or "here" needs no search.
     * The name comes from the tracker, never from the client.
     */
    public static function pageLine(array $page): ?string
    {
        $kind = (string) ($page['kind'] ?? '');
        if (!in_array($kind, self::PAGE_KINDS, true)) {
            return null;
        }
        if ($kind === 'crm') {
            return 'The user currently has the CRM (Orders & Anti-Shaving Vault) page open. When they ask about leads, shaving or "here", check CRM data with orbitra_crm_leads.';
        }
        if ($kind === 'leadforge') {
            return 'The user currently has the LeadForge page open. They may ask how to analyze/compile a ZIP landing, pick a mode (Auto / Cross-network / Raw), or connect it to CRM.';
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($page['name'] ?? '')));
        $name = mb_substr(str_replace(['"', '`'], "'", $name), 0, 120);
        return 'The user has the ' . $kind . ' editor open: ' . $kind . ' ' . (int) ($page['id'] ?? 0)
            . ($name !== '' ? ' "' . $name . '"' : '')
            . '. When they write about "this" ' . $kind . ' or "here", they mean that one.';
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

        $role = isset($ctx['role']) ? (string) $ctx['role'] : null;
        $isAdmin = $role === null || $role === '' || $role === 'admin';
        $tools = self::toolDefs($role);
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
            if (!isset($known[$c['name']]) || (!$isAdmin && self::isAdminOnlyTool((string) $c['name']))) {
                // A hallucinated or admin-only tool: answer it as an error the model can read.
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

    /**
     * The user's answer to held calls: run them, or decline them all.
     *
     * The confirmation card may narrow the run: $opts['skip'] lists call ids
     * the user left out (they are answered as not run, like a decline), and
     * $opts['edits'] maps a call id to arguments the user changed in the
     * card — picked text variants, a corrected weight. Both stay within the
     * user's own chat and permissions: the calls run exactly as the others do.
     */
    public static function confirm(PDO $pdo, array $user, int $chatId, bool $approve, array $opts = []): array
    {
        $chat = self::loadChat($pdo, (int) $user['id'], $chatId);
        if (!$chat) {
            throw new InvalidArgumentException('chat_not_found');
        }
        $edits = is_array($opts['edits'] ?? null) ? $opts['edits'] : [];
        $skip = array_map('strval', is_array($opts['skip'] ?? null) ? $opts['skip'] : []);
        foreach ($chat['pending'] as $p) {
            $pid = (string) $p['id'];
            if (!$approve || in_array($pid, $skip, true)) {
                $chat['messages'][] = ['role' => 'tool', 'id' => $p['id'], 'name' => $p['name'], 'status' => 'declined',
                    'content' => $approve
                        ? 'Not run: the user left this action out and ran the others. Do not retry it unless asked.'
                        : 'The user declined this action. Do not retry it; ask what they want instead.'];
                continue;
            }
            if (isset($edits[$pid]) && is_array($edits[$pid])) {
                $p['args'] = $edits[$pid];
                $p['edited'] = true;
            }
            $chat['messages'][] = self::execute($pdo, $user, $p, $opts);
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
            // The model asked for one thing and the user ran another: say so,
            // or the next turn reports what was asked for, not what happened.
            if (!empty($call['edited'])) {
                $text .= "\n[Run with the arguments the user changed in the confirmation card: " . json_encode($call['args'], JSON_UNESCAPED_UNICODE) . ']';
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
