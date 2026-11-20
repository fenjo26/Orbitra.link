<?php
// core/AiClient.php
//
// The one place Orbitra talks to a language model. The operator brings their
// own key (Settings → Integrations → AI assistant); nothing goes through any
// Orbitra-run service. Two wire formats cover the market:
//
//   - 'openai'    — the OpenAI Chat Completions format, which OpenAI,
//                   OpenRouter, DeepSeek, Groq, Together, a local Ollama /
//                   LM Studio and most gateways speak (base URL decides);
//   - 'anthropic' — the Anthropic Messages API.
//
// Internally a conversation is a list of entries:
//   ['role' => 'user', 'text' => …]
//   ['role' => 'assistant', 'text' => …, 'tool_calls' => [['id','name','args'], …]]
//   ['role' => 'tool', 'id' => …, 'name' => …, 'content' => string]
// and chat() returns one assistant entry in the same shape, so the agent
// never sees provider differences.

class AiClient
{
    /**
     * Presets the settings card offers. Every one but Anthropic speaks the
     * OpenAI Chat Completions format at its own base URL (Gemini and GLM
     * through their official OpenAI-compatible endpoints). The default model
     * is each provider's economical one — the operator pays for the tokens;
     * any model the account has can be chosen instead.
     */
    public const PRESETS = [
        'openai' => ['base' => 'https://api.openai.com/v1', 'model' => 'gpt-5-nano', 'wire' => 'openai', 'key' => true],
        'anthropic' => ['base' => 'https://api.anthropic.com/v1', 'model' => 'claude-haiku-5-5', 'wire' => 'anthropic', 'key' => true],
        'gemini' => ['base' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'model' => 'gemini-flash-lite-latest', 'wire' => 'openai', 'key' => true],
        'glm' => ['base' => 'https://api.z.ai/api/paas/v4', 'model' => 'glm-4.5-flash', 'wire' => 'openai', 'key' => true],
        'glm_coding' => ['base' => 'https://api.z.ai/api/coding/paas/v4', 'model' => 'glm-4.6', 'wire' => 'openai', 'key' => true],
        'deepseek' => ['base' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-chat', 'wire' => 'openai', 'key' => true],
        'openrouter' => ['base' => 'https://openrouter.ai/api/v1', 'model' => 'openai/gpt-5-nano', 'wire' => 'openai', 'key' => true],
        'ollama' => ['base' => 'http://127.0.0.1:11434/v1', 'model' => 'qwen3', 'wire' => 'openai', 'key' => false],
        'custom' => ['base' => '', 'model' => '', 'wire' => 'openai', 'key' => false],
    ];
    public const PROVIDERS = ['openai', 'anthropic', 'gemini', 'glm', 'glm_coding', 'deepseek', 'openrouter', 'ollama', 'custom'];

    /** Test seam: fn(string $url, array $headers, ?array $body): array [httpStatus, decodedJson|null, rawText] — $body null = GET */
    public static $transport = null;

    private string $provider;
    private string $wire;
    private string $baseUrl;
    private string $apiKey;
    private string $model;

    public function __construct(string $provider, string $baseUrl, string $apiKey, string $model)
    {
        $this->provider = in_array($provider, self::PROVIDERS, true) ? $provider : 'openai';
        $preset = self::PRESETS[$this->provider];
        $this->wire = $preset['wire'];
        $base = trim($baseUrl) !== '' ? trim($baseUrl) : $preset['base'];
        // People paste the full endpoint; the client appends the path itself.
        $base = preg_replace('~/(chat/completions|messages)/?$~', '', rtrim($base, '/'));
        $this->baseUrl = rtrim((string) $base, '/');
        $this->apiKey = $apiKey;
        $this->model = trim($model) !== '' ? trim($model) : $preset['model'];
    }

    /** Settings rows → client, or null when no key is configured. */
    public static function fromSettings(PDO $pdo): ?self
    {
        $s = self::settings($pdo);
        if (!self::isConfigured($s)) {
            return null;
        }
        return new self($s['provider'], $s['base_url'], $s['api_key'], $s['model']);
    }

    /** A key is required except for keyless local presets (Ollama / custom with a URL). */
    public static function isConfigured(array $s): bool
    {
        if ($s['api_key'] !== '') {
            return true;
        }
        $preset = self::PRESETS[$s['provider']] ?? self::PRESETS['openai'];
        return !$preset['key'] && (trim($s['base_url']) !== '' || $preset['base'] !== '');
    }

    public static function settings(PDO $pdo): array
    {
        $out = ['provider' => 'openai', 'base_url' => '', 'api_key' => '', 'model' => '', 'confirm_mode' => 'writes'];
        try {
            $rows = $pdo->query("SELECT key, value FROM settings WHERE key IN
                ('ai_provider','ai_base_url','ai_api_key','ai_model','ai_confirm_mode')")->fetchAll(PDO::FETCH_KEY_PAIR);
            $out['provider'] = in_array($rows['ai_provider'] ?? '', self::PROVIDERS, true) ? $rows['ai_provider'] : 'openai';
            $out['base_url'] = (string) ($rows['ai_base_url'] ?? '');
            $out['api_key'] = (string) ($rows['ai_api_key'] ?? '');
            $out['model'] = (string) ($rows['ai_model'] ?? '');
            $out['confirm_mode'] = ($rows['ai_confirm_mode'] ?? 'writes') === 'dangerous' ? 'dangerous' : 'writes';
        } catch (\Throwable $e) {
        }
        return $out;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * One model turn. $tools: [['name','description','parameters' => JSON schema], …].
     * Returns ['role' => 'assistant', 'text' => string, 'tool_calls' => [...], 'usage' => [...]].
     */
    public function chat(string $system, array $history, array $tools = [], int $maxTokens = 4096): array
    {
        return $this->wire === 'anthropic'
            ? $this->chatAnthropic($system, $history, $tools, $maxTokens)
            : $this->chatOpenAi($system, $history, $tools, $maxTokens);
    }

    /** Plain completion without tools — text generation helpers use it. */
    public function complete(string $system, string $prompt, int $maxTokens = 2048): string
    {
        $r = $this->chat($system, [['role' => 'user', 'text' => $prompt]], [], $maxTokens);
        return (string) $r['text'];
    }

    /**
     * The chat models this account can use, straight from the provider
     * (GET /models — OpenAI, Gemini, GLM, DeepSeek, OpenRouter, Ollama and
     * Anthropic all answer it). Non-chat models (embeddings, speech, images,
     * moderation…) are dropped. Each entry: id, name, and when the provider
     * publishes it (OpenRouter) the price in USD per 1M input/output tokens.
     * 'recommended' marks the preset's economical default when it is listed.
     */
    public function listModels(): array
    {
        $headers = $this->wire === 'anthropic'
            ? ['x-api-key: ' . $this->apiKey, 'anthropic-version: 2023-06-01']
            : ($this->apiKey !== '' ? ['Authorization: Bearer ' . $this->apiKey] : []);
        [$status, $json, $raw] = $this->post($this->baseUrl . '/models' . ($this->wire === 'anthropic' ? '?limit=100' : ''), $headers, null);
        if ($status < 200 || $status >= 300 || !is_array($json)) {
            throw new RuntimeException(self::errorText($status, $json, $raw));
        }
        $rows = $json['data'] ?? $json['models'] ?? [];
        $skip = '/(embed|tts|whisper|dall-?e|image|audio|moderation|realtime|transcri|speech|veo|imagen|aqa|rerank|babbage|davinci|lyria|-native-audio)/i';
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $m) {
            $id = (string) ($m['id'] ?? $m['name'] ?? '');
            $id = preg_replace('~^models/~', '', $id);
            if ($id === '' || preg_match($skip, $id)) {
                continue;
            }
            $row = ['id' => $id, 'name' => (string) ($m['display_name'] ?? $m['name'] ?? $id)];
            if (isset($m['pricing']['prompt']) && is_numeric($m['pricing']['prompt'])) {
                $row['price_in'] = round((float) $m['pricing']['prompt'] * 1e6, 3);
                $row['price_out'] = round((float) ($m['pricing']['completion'] ?? 0) * 1e6, 3);
            }
            if (!empty($m['context_length'])) {
                $row['context'] = (int) $m['context_length'];
            }
            $out[$id] = $row;
        }
        $out = array_values($out);
        $default = self::PRESETS[$this->provider]['model'] ?? '';
        foreach ($out as &$r) {
            $r['recommended'] = $r['id'] === $default;
        }
        unset($r);
        usort($out, static function ($a, $b) {
            if ($a['recommended'] !== $b['recommended']) {
                return $a['recommended'] ? -1 : 1;
            }
            if (isset($a['price_in'], $b['price_in']) && $a['price_in'] != $b['price_in']) {
                return $a['price_in'] <=> $b['price_in'];
            }
            return strcmp($a['id'], $b['id']);
        });
        return $out;
    }

    // ---- OpenAI-compatible ----------------------------------------------------

    private function chatOpenAi(string $system, array $history, array $tools, int $maxTokens): array
    {
        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $e) {
            if ($e['role'] === 'user') {
                $messages[] = ['role' => 'user', 'content' => (string) $e['text']];
            } elseif ($e['role'] === 'assistant') {
                $m = ['role' => 'assistant', 'content' => ($e['text'] ?? '') !== '' ? (string) $e['text'] : null];
                if (!empty($e['tool_calls'])) {
                    $m['tool_calls'] = array_map(static function ($c) {
                        $tc = [
                            'id' => (string) $c['id'],
                            'type' => 'function',
                            'function' => [
                                'name' => (string) $c['name'],
                                'arguments' => json_encode($c['args'] ?: new stdClass(), JSON_UNESCAPED_UNICODE),
                            ],
                        ];
                        // Gemini 3 refuses a tool turn whose thought
                        // signature was dropped; echo whatever came with it.
                        if (!empty($c['extra'])) {
                            $tc['extra_content'] = $c['extra'];
                        }
                        return $tc;
                    }, $e['tool_calls']);
                }
                $messages[] = $m;
            } elseif ($e['role'] === 'tool') {
                $messages[] = ['role' => 'tool', 'tool_call_id' => (string) $e['id'], 'content' => (string) $e['content']];
            }
        }
        $body = ['model' => $this->model, 'messages' => $messages, 'max_tokens' => $maxTokens];
        if ($tools) {
            $body['tools'] = array_map(static fn($t) => [
                'type' => 'function',
                'function' => [
                    'name' => $t['name'],
                    'description' => mb_substr((string) $t['description'], 0, 1000),
                    'parameters' => $t['parameters'],
                ],
            ], $tools);
            $body['tool_choice'] = 'auto';
        }
        // GLM agent rounds: with the reasoning pass on, a thinking model can
        // sit past the 55 s provider cap (sized to fit nginx's 60 s) on a
        // tool-heavy context before the first byte arrives — the round dies
        // with "Operation timed out ... 0 bytes received". Tool calling
        // wants decisions, not essays: switch the reasoning off on z.ai and
        // fall back to the plain request once if the endpoint rejects the flag.
        $glmNoThinking = in_array($this->provider, ['glm', 'glm_coding'], true);
        if ($glmNoThinking) {
            $body['thinking'] = ['type' => 'disabled'];
        }
        $headers = $this->apiKey !== '' ? ['Authorization: Bearer ' . $this->apiKey] : [];
        [$status, $json, $raw] = $this->post($this->baseUrl . '/chat/completions', $headers, $body);
        if ($glmNoThinking && $status >= 400 && $status < 500 && isset($body['thinking'])) {
            unset($body['thinking']);
            [$status, $json, $raw] = $this->post($this->baseUrl . '/chat/completions', $headers, $body);
        }
        if ($status < 200 || $status >= 300 || !is_array($json)) {
            throw new RuntimeException(self::errorText($status, $json, $raw));
        }
        $msg = $json['choices'][0]['message'] ?? [];
        $calls = [];
        foreach (($msg['tool_calls'] ?? []) as $c) {
            $args = json_decode((string) ($c['function']['arguments'] ?? '{}'), true);
            $call = [
                'id' => (string) (($c['id'] ?? '') !== '' ? $c['id'] : ('call_' . bin2hex(random_bytes(4)))),
                'name' => (string) ($c['function']['name'] ?? ''),
                'args' => is_array($args) ? $args : [],
            ];
            if (!empty($c['extra_content']) && is_array($c['extra_content'])) {
                $call['extra'] = $c['extra_content'];
            }
            $calls[] = $call;
        }
        return [
            'role' => 'assistant',
            'text' => is_string($msg['content'] ?? null) ? $msg['content'] : '',
            'tool_calls' => $calls,
            'usage' => [
                'in' => (int) ($json['usage']['prompt_tokens'] ?? 0),
                'out' => (int) ($json['usage']['completion_tokens'] ?? 0),
            ],
        ];
    }

    // ---- Anthropic -----------------------------------------------------------

    private function chatAnthropic(string $system, array $history, array $tools, int $maxTokens): array
    {
        $messages = [];
        $push = static function (string $role, array $blocks) use (&$messages) {
            // The Messages API wants strictly alternating roles: merge runs
            // (several tool results in a row become one user turn).
            $n = count($messages);
            if ($n > 0 && $messages[$n - 1]['role'] === $role) {
                $messages[$n - 1]['content'] = array_merge($messages[$n - 1]['content'], $blocks);
            } else {
                $messages[] = ['role' => $role, 'content' => $blocks];
            }
        };
        foreach ($history as $e) {
            if ($e['role'] === 'user') {
                $push('user', [['type' => 'text', 'text' => (string) $e['text'] !== '' ? (string) $e['text'] : '…']]);
            } elseif ($e['role'] === 'assistant') {
                $blocks = [];
                if (($e['text'] ?? '') !== '') {
                    $blocks[] = ['type' => 'text', 'text' => (string) $e['text']];
                }
                foreach (($e['tool_calls'] ?? []) as $c) {
                    $blocks[] = ['type' => 'tool_use', 'id' => (string) $c['id'], 'name' => (string) $c['name'],
                        'input' => $c['args'] ?: new stdClass()];
                }
                if ($blocks) {
                    $push('assistant', $blocks);
                }
            } elseif ($e['role'] === 'tool') {
                $push('user', [['type' => 'tool_result', 'tool_use_id' => (string) $e['id'], 'content' => (string) $e['content']]]);
            }
        }
        $body = ['model' => $this->model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => $messages];
        if ($tools) {
            $body['tools'] = array_map(static fn($t) => [
                'name' => $t['name'],
                'description' => mb_substr((string) $t['description'], 0, 1000),
                'input_schema' => $t['parameters'],
            ], $tools);
        }
        [$status, $json, $raw] = $this->post($this->baseUrl . '/messages', [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
        ], $body);
        if ($status < 200 || $status >= 300 || !is_array($json)) {
            throw new RuntimeException(self::errorText($status, $json, $raw));
        }
        $text = '';
        $calls = [];
        foreach (($json['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text') {
                $text .= (string) $b['text'];
            } elseif (($b['type'] ?? '') === 'tool_use') {
                $calls[] = [
                    'id' => (string) $b['id'],
                    'name' => (string) $b['name'],
                    'args' => is_array($b['input'] ?? null) ? $b['input'] : [],
                ];
            }
        }
        return [
            'role' => 'assistant',
            'text' => $text,
            'tool_calls' => $calls,
            'usage' => [
                'in' => (int) ($json['usage']['input_tokens'] ?? 0),
                'out' => (int) ($json['usage']['output_tokens'] ?? 0),
            ],
        ];
    }

    // ---- transport -------------------------------------------------------------

    private function post(string $url, array $headers, ?array $body): array
    {
        if (is_callable(self::$transport)) {
            return (self::$transport)($url, $headers, $body);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP curl extension is required for the AI assistant.');
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_CONNECTTIMEOUT => 15,
            // One model turn; the panel calls step by step, so a single
            // request never stacks several model calls against nginx's 60 s.
            CURLOPT_TIMEOUT => 55,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Could not reach the AI provider: ' . $err);
        }
        return [$status, json_decode((string) $raw, true), (string) $raw];
    }

    private static function errorText(int $status, $json, string $raw): string
    {
        $msg = '';
        if (is_array($json)) {
            $msg = (string) ($json['error']['message'] ?? $json['error']['type'] ?? $json['message'] ?? '');
            if ($msg === '' && is_string($json['error'] ?? null)) {
                $msg = $json['error'];
            }
        }
        if ($msg === '') {
            $msg = mb_substr(trim(strip_tags($raw)), 0, 300);
        }
        $hint = match (true) {
            $status === 401, $status === 403 => ' (check the API key)',
            $status === 404 => ' (check the base URL and the model name)',
            $status === 429 => ' (rate limit or no credit on the account)',
            default => '',
        };
        return 'AI provider error ' . $status . ': ' . $msg . $hint;
    }
}
