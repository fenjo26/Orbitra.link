<?php
/**
 * TelegramNotifyRules — conversion alerts to a Telegram channel or group
 * (the adset.pro "Telegram Bot" pixel, on Orbitra's existing ADMIN bot).
 *
 * The admin bot already pings admitted chats on every conversion with a fixed
 * text. A rule adds what a team needs: a specific channel/group, only some
 * statuses (deposits only, say), only some campaigns, and its own HTML
 * template with macros — including the ad-platform names of the campaign, ad
 * set and ad behind the click (ad_entities), so a buyer sees WHICH ad paid.
 *
 * The bot must be an admin of a channel (or a member of a group). Sending is
 * synchronous from the postback with a short timeout; every send is logged.
 */

class TelegramNotifyRules
{
    /** Status filter keys: the report status groups plus the funnel's tg_join. */
    public static function statusKeys(): array
    {
        return ['sale', 'deposit', 'registration', 'hold', 'rejected', 'trash', 'tg_join'];
    }

    /** Macro list for the template editor (key → sample value). */
    public static function macros(): array
    {
        return [
            'campaign'         => 'FB GR Casino',
            'campaign_id'      => '12',
            'offer'            => 'Casino GR',
            'status'           => 'deposit',
            'payout'           => '120.00',
            'currency'         => 'USD',
            'country'          => 'GR',
            'flag'             => '🇬🇷',
            'source'           => 'Facebook',
            'ad_campaign_name' => 'GR_broad_25-45',
            'adset_name'       => 'Interests_casino',
            'ad_name'          => 'Girl_phone_v3',
            'sub1'             => '',
            'sub2'             => '',
            'sub3'             => '',
            'click_id'         => 'a1b2c3d4',
            'time'             => '14:05',
            'date'             => '2026-10-19',
        ];
    }

    public static function defaultTemplate(): string
    {
        // Language-neutral on purpose: the panel offers a localized starter
        // template; this is only the fallback for an empty one.
        return "<b>{flag} {status} · {payout} {currency}</b>\n"
            . "📣 {campaign}\n"
            . "🎯 {offer}\n"
            . "———\n"
            . "{ad_campaign_name} › {adset_name} › {ad_name}";
    }

    /**
     * Test seam: callable(string $token, string $chatId, string $html): array{ok:bool,error:string}.
     * @var callable|null
     */
    public static $testSender = null;

    /** Which filter key a stored conversion status belongs to ('' = none). */
    public static function statusKeyOf(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'tg_join') {
            return 'tg_join';
        }
        if (!function_exists('orbitraConversionStatusGroups')) {
            require_once __DIR__ . '/ReportMetrics.php';
        }
        foreach (orbitraConversionStatusGroups() as $group => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $group;
            }
        }
        return '';
    }

    /** Does a rule fire for this status/campaign? Empty lists mean "all". */
    public static function matches(array $rule, string $status, int $campaignId): bool
    {
        $statuses = json_decode((string) ($rule['statuses'] ?? '[]'), true);
        $statuses = is_array($statuses) ? $statuses : [];
        if ($statuses && !in_array(self::statusKeyOf($status), $statuses, true)) {
            return false;
        }
        $campaigns = json_decode((string) ($rule['campaign_ids'] ?? '[]'), true);
        $campaigns = is_array($campaigns) ? array_map('intval', $campaigns) : [];
        if ($campaigns && !in_array($campaignId, $campaigns, true)) {
            return false;
        }
        return true;
    }

    /** Macro values for one conversion. Every value is plain text. */
    public static function context(PDO $pdo, string $clickId, string $status, float $payout, string $currency, int $campaignId): array
    {
        $ctx = array_fill_keys(array_keys(self::macros()), '');
        $ctx['status'] = $status;
        $ctx['payout'] = number_format($payout, 2, '.', '');
        $ctx['currency'] = $currency;
        $ctx['click_id'] = $clickId;
        $ctx['time'] = date('H:i');
        $ctx['date'] = date('Y-m-d');
        $ctx['campaign_id'] = (string) $campaignId;

        $click = [];
        try {
            $stmt = $pdo->prepare("SELECT * FROM clicks WHERE id = ? LIMIT 1");
            $stmt->execute([$clickId]);
            $click = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
        }
        $one = function (string $sql, array $args) use ($pdo): string {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($args);
                $v = $stmt->fetchColumn();
                return $v === false || $v === null ? '' : (string) $v;
            } catch (\Throwable $e) {
                return '';
            }
        };

        $ctx['campaign'] = $one("SELECT name FROM campaigns WHERE id = ?", [$campaignId]) ?: ('#' . $campaignId);
        if (!empty($click['offer_id'])) {
            $ctx['offer'] = $one("SELECT name FROM offers WHERE id = ?", [(int) $click['offer_id']]);
        }
        if (!empty($click['source_id'])) {
            $ctx['source'] = $one("SELECT name FROM traffic_sources WHERE id = ?", [(int) $click['source_id']]);
        }
        $country = strtoupper((string) ($click['country_code'] ?? $click['country'] ?? ''));
        $ctx['country'] = $country;
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $ctx['flag'] = mb_chr(0x1F1E6 + ord($country[0]) - 65) . mb_chr(0x1F1E6 + ord($country[1]) - 65);
        }

        $params = json_decode((string) ($click['parameters_json'] ?? ''), true);
        $params = is_array($params) ? $params : [];
        $param = function (array $keys) use ($params): string {
            foreach ($keys as $k) {
                $v = trim((string) ($params[$k] ?? ''));
                // An unreplaced platform macro is not a value.
                if ($v !== '' && !preg_match('/^(__[A-Z_]+__|\{\{.*\}\}|\{[a-z_.]+\})$/i', $v)) {
                    return $v;
                }
            }
            return '';
        };
        for ($i = 1; $i <= 3; $i++) {
            $ctx['sub' . $i] = $param(['sub_id_' . $i, 'sub' . $i]);
        }
        // Platform names: ad_entities (filled by the cost sync) first, then a
        // *_name parameter the source passed, then the bare id.
        $levels = [
            'ad_campaign_name' => ['campaign', ['campaign_id', 'campaignid', 'ad_campaign_id'], ['campaign_name']],
            'adset_name'       => ['adset', ['adset_id', 'adgroup_id', 'adgroupid', 'ad_group_id'], ['adset_name']],
            'ad_name'          => ['ad', ['ad_id', 'creative_id'], ['ad_name']],
        ];
        foreach ($levels as $macro => [$level, $idKeys, $nameKeys]) {
            $id = $param($idKeys);
            $name = $id !== '' ? $one("SELECT name FROM ad_entities WHERE level = ? AND external_id = ? AND name != '' LIMIT 1", [$level, $id]) : '';
            $ctx[$macro] = $name !== '' ? $name : ($param($nameKeys) ?: $id);
        }
        return $ctx;
    }

    /** Fill a template: values are HTML-escaped, unknown macros stay as typed. */
    public static function render(string $template, array $ctx): string
    {
        return preg_replace_callback('/\{([a-z0-9_]+)\}/', function ($m) use ($ctx) {
            return array_key_exists($m[1], $ctx)
                ? htmlspecialchars((string) $ctx[$m[1]], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : $m[0];
        }, $template);
    }

    public static function adminToken(PDO $pdo): string
    {
        try {
            return (string) $pdo->query("SELECT value FROM settings WHERE key = 'telegram_bot_token'")->fetchColumn();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** @return array{ok:bool, error:string} */
    public static function send(string $token, string $chatId, string $html): array
    {
        if (self::$testSender !== null) {
            return (self::$testSender)($token, $chatId, $html);
        }
        $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['chat_id' => $chatId, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        $res = is_string($body) ? json_decode($body, true) : null;
        if (is_array($res) && ($res['ok'] ?? false)) {
            return ['ok' => true, 'error' => ''];
        }
        return ['ok' => false, 'error' => is_array($res) ? (string) ($res['description'] ?? 'error') : ($err !== '' ? $err : 'network error')];
    }

    /**
     * Fire every matching active rule for one conversion. Never throws: a
     * failed alert must not cost the postback.
     */
    public static function dispatch(PDO $pdo, string $clickId, string $status, float $payout, string $currency, int $campaignId): int
    {
        try {
            $rules = $pdo->query("SELECT * FROM tg_notify_rules WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return 0; // pre-migration database
        }
        if (!$rules) {
            return 0;
        }
        $token = self::adminToken($pdo);
        $ctx = null;
        $sent = 0;
        foreach ($rules as $rule) {
            if (!self::matches($rule, $status, $campaignId)) {
                continue;
            }
            if ($token === '') {
                self::log($pdo, (int) $rule['id'], $clickId, $status, false, 'admin bot is not connected');
                continue;
            }
            $ctx = $ctx ?? self::context($pdo, $clickId, $status, $payout, $currency, $campaignId);
            $template = trim((string) $rule['template']) !== '' ? (string) $rule['template'] : self::defaultTemplate();
            $res = self::send($token, (string) $rule['chat_id'], self::render($template, $ctx));
            self::log($pdo, (int) $rule['id'], $clickId, $status, $res['ok'], $res['error']);
            $sent += $res['ok'] ? 1 : 0;
        }
        return $sent;
    }

    /** Test message with sample values. */
    public static function test(PDO $pdo, array $rule): array
    {
        $token = self::adminToken($pdo);
        if ($token === '') {
            return ['ok' => false, 'error' => 'admin bot is not connected'];
        }
        $template = trim((string) ($rule['template'] ?? '')) !== '' ? (string) $rule['template'] : self::defaultTemplate();
        $res = self::send($token, (string) ($rule['chat_id'] ?? ''), self::render($template, self::macros()));
        if (!empty($rule['id'])) {
            self::log($pdo, (int) $rule['id'], 'test', 'test', $res['ok'], $res['error']);
        }
        return $res;
    }

    /**
     * Resolve a chat reference (@channel, -100…, t.me/x) through the admin bot:
     * numeric id, title, and whether the bot may post there.
     *
     * @return array{ok:bool, error?:string, chat_id?:string, title?:string, can_post?:bool}
     */
    public static function checkChat(PDO $pdo, string $ref): array
    {
        $ref = trim($ref);
        if (preg_match('~^(?:https?://)?t\.me/([A-Za-z0-9_]{4,64})/?$~i', $ref, $m)) {
            $ref = '@' . $m[1];
        }
        if (preg_match('/^(@[A-Za-z0-9_]{4,64}|-?\d{5,})$/', $ref) !== 1) {
            return ['ok' => false, 'error' => 'chat_format'];
        }
        $token = self::adminToken($pdo);
        if ($token === '') {
            return ['ok' => false, 'error' => 'no_admin_bot'];
        }
        require_once __DIR__ . '/TelegramFunnel.php';
        $chat = TelegramFunnel::api($token, 'getChat', ['chat_id' => $ref]);
        if (!($chat['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'not_found', 'detail' => (string) ($chat['description'] ?? '')];
        }
        $chatId = (string) ($chat['result']['id'] ?? '');
        $me = TelegramFunnel::api($token, 'getMe');
        $member = TelegramFunnel::api($token, 'getChatMember', ['chat_id' => $chatId, 'user_id' => (int) ($me['result']['id'] ?? 0)]);
        $status = (string) ($member['result']['status'] ?? '');
        $type = (string) ($chat['result']['type'] ?? '');
        $canPost = $type === 'channel'
            ? ($status === 'creator' || ($status === 'administrator' && !empty($member['result']['can_post_messages'])))
            : in_array($status, ['creator', 'administrator', 'member'], true);
        return [
            'ok'       => true,
            'chat_id'  => $chatId,
            'title'    => (string) ($chat['result']['title'] ?? $chat['result']['username'] ?? $ref),
            'can_post' => $canPost,
        ];
    }

    private static function log(PDO $pdo, int $ruleId, string $clickId, string $status, bool $ok, string $error): void
    {
        try {
            $pdo->prepare("INSERT INTO tg_notify_log (rule_id, click_id, status, ok, error) VALUES (?, ?, ?, ?, ?)")
                ->execute([$ruleId, $clickId, $status, $ok ? 1 : 0, mb_substr($error, 0, 300)]);
            // Bounded journal: keep the latest 200 rows per rule.
            if (mt_rand(1, 20) === 1) {
                $pdo->prepare("DELETE FROM tg_notify_log WHERE rule_id = ? AND id NOT IN (SELECT id FROM tg_notify_log WHERE rule_id = ? ORDER BY id DESC LIMIT 200)")
                    ->execute([$ruleId, $ruleId]);
            }
        } catch (\Throwable $e) {
        }
    }
}
