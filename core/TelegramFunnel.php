<?php
/**
 * TelegramFunnel — the "Telegram channel" offer type, run by SEPARATE ad bots.
 *
 * Why separate: the admin bot (telegram_bot.php) answers statistics to the
 * chats the operator admitted. A funnel bot talks to strangers from an ad.
 * Sharing one bot meant every ad visitor reached the process that serves the
 * panel's numbers, and the funnel's "/start <click>" collided with the
 * panel's "/start <link code>". Now the two never meet: different tokens,
 * different tables, different poller (cli/tg_funnel_poll_cron.php). Adding the
 * admin bot's token as a funnel bot is refused.
 *
 * Flow: ad click → offer URL t.me/<funnel bot>?start=<click id> → /start binds
 * the Telegram user to the click (tg_start) and answers with a join-request
 * invite to the offer's channel → chat_join_request → the bot approves it and
 * writes a tg_join conversion on the click. A network postback without a
 * click id (/postback/tg?tg_user_id=&channel_id=) resolves to the last join of
 * that user in that channel within 30 days.
 *
 * Polling only: no webhook, so a funnel bot works on any install (bare IP,
 * no domain) — the same reason the admin bot polls.
 */

class TelegramFunnel
{
    /** Updates a funnel bot asks Telegram for. */
    public const ALLOWED_UPDATES = ['message', 'chat_join_request', 'my_chat_member'];

    /** How long a join request may arrive after /start when the offer sets nothing. */
    private const DEFAULT_WINDOW_DAYS = 2;

    /** A network postback resolves joins this far back. */
    private const INBOUND_DAYS = 30;

    /**
     * Test seam: callable(string $token, string $method, array $params): ?array
     * standing in for the Bot API. Production never sets it.
     * @var callable|null
     */
    public static $testApi = null;

    // ------------------------------------------------------------------ API

    /** One Bot API call; the decoded body, or null on transport failure. */
    public static function api(string $token, string $method, array $params = [], int $timeout = 15): ?array
    {
        if (self::$testApi !== null) {
            return (self::$testApi)($token, $method, $params);
        }
        // ORBITRA_TG_API_BASE: a test stub for the CLI poller's end-to-end run
        // (same variable cli/tg_funnel_poll_cron.php reads); plain HTTP is only
        // ever allowed for it.
        $base = getenv('ORBITRA_TG_API_BASE') ?: '';
        $ch = curl_init(($base !== '' ? rtrim($base, '/') : 'https://api.telegram.org') . '/bot' . $token . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS      => $base !== '' ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Bot API token shape: <digits>:<35-ish url-safe chars>. */
    public static function isTokenShape(string $token): bool
    {
        return preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,64}$/', $token) === 1;
    }

    // ------------------------------------------------------------- texts

    public static function languages(): array
    {
        return ['ru', 'en', 'uk', 'es', 'zh', 'fr', 'de'];
    }

    /** Visitor-facing texts. Kept here, not in the admin bot's botText(). */
    public static function text(string $lang, string $key): string
    {
        static $texts = [
            'ru' => [
                'welcome'      => 'Добро пожаловать! Остался один шаг — вступайте в канал:',
                'join_button'  => 'Вступить в канал',
                'link_expired' => 'Ссылка устарела. Откройте её заново со страницы, с которой пришли.',
                'start_plain'  => 'Чтобы вступить в канал, откройте ссылку со страницы, с которой пришли.',
            ],
            'en' => [
                'welcome'      => 'Welcome! One step left — join the channel:',
                'join_button'  => 'Join the channel',
                'link_expired' => 'This link has expired. Open it again from the page you came from.',
                'start_plain'  => 'To join the channel, open the link on the page you came from.',
            ],
            'uk' => [
                'welcome'      => 'Вітаємо! Залишився один крок — приєднуйтеся до каналу:',
                'join_button'  => 'Приєднатися до каналу',
                'link_expired' => 'Посилання застаріло. Відкрийте його знову зі сторінки, з якої прийшли.',
                'start_plain'  => 'Щоб приєднатися до каналу, відкрийте посилання зі сторінки, з якої прийшли.',
            ],
            'es' => [
                'welcome'      => '¡Bienvenido! Queda un paso: únete al canal:',
                'join_button'  => 'Unirse al canal',
                'link_expired' => 'Este enlace ha caducado. Ábrelo de nuevo desde la página de origen.',
                'start_plain'  => 'Para unirte al canal, abre el enlace de la página de origen.',
            ],
            'zh' => [
                'welcome'      => '欢迎！只差一步——加入频道：',
                'join_button'  => '加入频道',
                'link_expired' => '链接已过期。请从原页面重新打开。',
                'start_plain'  => '请从原页面打开链接以加入频道。',
            ],
            'fr' => [
                'welcome'      => 'Bienvenue ! Une dernière étape — rejoignez le canal :',
                'join_button'  => 'Rejoindre le canal',
                'link_expired' => 'Ce lien a expiré. Rouvrez-le depuis la page d’origine.',
                'start_plain'  => 'Pour rejoindre le canal, ouvrez le lien depuis la page d’origine.',
            ],
            'de' => [
                'welcome'      => 'Willkommen! Nur noch ein Schritt — tritt dem Kanal bei:',
                'join_button'  => 'Kanal beitreten',
                'link_expired' => 'Dieser Link ist abgelaufen. Öffne ihn erneut über die Ursprungsseite.',
                'start_plain'  => 'Um dem Kanal beizutreten, öffne den Link auf der Ursprungsseite.',
            ],
        ];
        return $texts[$lang][$key] ?? $texts['en'][$key] ?? $key;
    }

    /** The visitor's Telegram UI language, mapped onto the supported set. */
    public static function langOf(array $from): string
    {
        $code = strtolower(substr((string) ($from['language_code'] ?? ''), 0, 2));
        if ($code === 'be' || $code === 'kk') {
            $code = 'ru';
        }
        return in_array($code, self::languages(), true) ? $code : 'en';
    }

    // -------------------------------------------------------------- bots

    /**
     * Register a funnel bot by token: getMe for the username, deleteWebhook so
     * getUpdates works. Refuses the admin bot's own token — that is the whole
     * point of the separation.
     *
     * @return array{ok:bool, error?:string, id?:int, username?:string}
     */
    public static function addBot(PDO $pdo, string $token, string $name = ''): array
    {
        $token = trim($token);
        if (!self::isTokenShape($token)) {
            return ['ok' => false, 'error' => 'token_format'];
        }
        $adminToken = '';
        try {
            $adminToken = (string) $pdo->query("SELECT value FROM settings WHERE key = 'telegram_bot_token'")->fetchColumn();
        } catch (\Throwable $e) {
        }
        if ($adminToken !== '' && hash_equals($adminToken, $token)) {
            return ['ok' => false, 'error' => 'admin_token'];
        }
        $dup = $pdo->prepare("SELECT id FROM tg_funnel_bots WHERE token = ? LIMIT 1");
        $dup->execute([$token]);
        if ($dup->fetchColumn() !== false) {
            return ['ok' => false, 'error' => 'duplicate'];
        }

        $me = self::api($token, 'getMe');
        if (!($me['ok'] ?? false) || empty($me['result']['username'])) {
            return ['ok' => false, 'error' => 'get_me', 'detail' => (string) ($me['description'] ?? '')];
        }
        $username = (string) $me['result']['username'];
        $tgId = (string) ($me['result']['id'] ?? '');

        // getUpdates answers 409 while a webhook is registered.
        $del = self::api($token, 'deleteWebhook', ['drop_pending_updates' => false]);
        $cleared = ($del['ok'] ?? false) ? 1 : 0;

        $pdo->prepare("INSERT INTO tg_funnel_bots (name, token, username, tg_bot_id, webhook_cleared) VALUES (?, ?, ?, ?, ?)")
            ->execute([mb_substr(trim($name) !== '' ? trim($name) : '@' . $username, 0, 100), $token, $username, $tgId, $cleared]);
        return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'username' => $username];
    }

    public static function bot(PDO $pdo, int $botId): ?array
    {
        if ($botId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT * FROM tg_funnel_bots WHERE id = ? LIMIT 1");
        $stmt->execute([$botId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Bot list for the panel — the token is never sent back. */
    public static function listBots(PDO $pdo): array
    {
        $bots = $pdo->query("SELECT id, name, username, is_active, last_poll_at, last_error, created_at FROM tg_funnel_bots ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $chStmt = $pdo->prepare("SELECT chat_id, title, username, can_invite, updated_at FROM tg_funnel_channels WHERE bot_id = ? ORDER BY title");
        $stats = $pdo->prepare("SELECT COUNT(*) AS starts, SUM(CASE WHEN joined_at IS NOT NULL THEN 1 ELSE 0 END) AS joins
                                FROM tg_start WHERE bot_id = ? AND created_at >= datetime('now', '-30 days')");
        foreach ($bots as &$bot) {
            $chStmt->execute([(int) $bot['id']]);
            $bot['channels'] = $chStmt->fetchAll(PDO::FETCH_ASSOC);
            $stats->execute([(int) $bot['id']]);
            $s = $stats->fetch(PDO::FETCH_ASSOC) ?: [];
            $bot['starts_30d'] = (int) ($s['starts'] ?? 0);
            $bot['joins_30d'] = (int) ($s['joins'] ?? 0);
            $bot['id'] = (int) $bot['id'];
            $bot['is_active'] = (int) $bot['is_active'];
        }
        unset($bot);
        return $bots;
    }

    /**
     * Add a channel by @username or numeric id: getChat for the canonical
     * numeric id and title, getChatMember for the bot's own rights. A channel
     * the bot cannot invite into is stored with can_invite = 0 so the panel can
     * say exactly what to fix.
     *
     * @return array{ok:bool, error?:string, channel?:array}
     */
    public static function addChannel(PDO $pdo, array $bot, string $ref): array
    {
        $ref = trim($ref);
        if (preg_match('~^(?:https?://)?t\.me/([A-Za-z0-9_]{4,64})/?$~i', $ref, $m)) {
            $ref = '@' . $m[1];
        }
        if (preg_match('/^(@[A-Za-z0-9_]{4,64}|-\d{5,})$/', $ref) !== 1) {
            return ['ok' => false, 'error' => 'channel_format'];
        }
        $chat = self::api((string) $bot['token'], 'getChat', ['chat_id' => $ref]);
        if (!($chat['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'not_found', 'detail' => (string) ($chat['description'] ?? '')];
        }
        $chatId = (string) ($chat['result']['id'] ?? '');
        $canInvite = 0;
        if ((string) $bot['tg_bot_id'] !== '') {
            $member = self::api((string) $bot['token'], 'getChatMember', ['chat_id' => $chatId, 'user_id' => (int) $bot['tg_bot_id']]);
            $status = (string) ($member['result']['status'] ?? '');
            $canInvite = ($status === 'creator' || ($status === 'administrator' && !empty($member['result']['can_invite_users']))) ? 1 : 0;
        }
        $channel = [
            'chat_id'    => $chatId,
            'title'      => (string) ($chat['result']['title'] ?? $ref),
            'username'   => (string) ($chat['result']['username'] ?? ''),
            'can_invite' => $canInvite,
        ];
        self::upsertChannel($pdo, (int) $bot['id'], $channel);
        return ['ok' => true, 'channel' => $channel];
    }

    private static function upsertChannel(PDO $pdo, int $botId, array $channel): void
    {
        $pdo->prepare("INSERT INTO tg_funnel_channels (bot_id, chat_id, title, username, can_invite, updated_at)
                       VALUES (?, ?, ?, ?, ?, datetime('now'))
                       ON CONFLICT(bot_id, chat_id) DO UPDATE SET title = excluded.title, username = excluded.username,
                           can_invite = excluded.can_invite, updated_at = datetime('now')")
            ->execute([$botId, (string) $channel['chat_id'], mb_substr((string) $channel['title'], 0, 200), (string) $channel['username'], (int) $channel['can_invite']]);
    }

    /**
     * The numeric chat id for an offer's channel reference (numeric or
     * @username), resolved through the bot's known channels. chat_join_request
     * always reports the numeric id, so bindings must store that form.
     */
    public static function canonicalChannel(PDO $pdo, int $botId, string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '' || $ref[0] !== '@') {
            return $ref;
        }
        $stmt = $pdo->prepare("SELECT chat_id FROM tg_funnel_channels WHERE bot_id = ? AND LOWER(username) = LOWER(?) LIMIT 1");
        $stmt->execute([$botId, substr($ref, 1)]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (string) $id : $ref;
    }

    /** t.me deep link for a click — the offer destination. */
    public static function deepLink(PDO $pdo, int $botId): string
    {
        $bot = self::bot($pdo, $botId);
        if (!$bot || (int) $bot['is_active'] !== 1 || (string) $bot['username'] === '') {
            return '';
        }
        return 'https://t.me/' . $bot['username'] . '?start={subid}';
    }

    // ----------------------------------------------------------- updates

    /** Route one update of a funnel bot. Returns true when it did something. */
    public static function processUpdate(PDO $pdo, array $bot, array $update): bool
    {
        if (isset($update['my_chat_member'])) {
            return self::onMyChatMember($pdo, $bot, $update['my_chat_member']);
        }
        if (isset($update['chat_join_request'])) {
            return self::onJoinRequest($pdo, $bot, $update['chat_join_request']);
        }
        $message = $update['message'] ?? null;
        if (!is_array($message) || ($message['chat']['type'] ?? '') !== 'private') {
            return false; // the funnel never talks in groups
        }
        $text = trim((string) ($message['text'] ?? ''));
        if (stripos($text, '/start') !== 0) {
            return false; // only /start gets an answer — no chatter for spammers
        }
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $arg = trim((string) substr($text, 6));
        $lang = self::langOf($from);
        if ($arg === '') {
            self::send($bot, (string) ($from['id'] ?? ''), self::text($lang, 'start_plain'));
            return true;
        }
        return self::onStart($pdo, $bot, $arg, $from, $lang);
    }

    /** The bot was promoted/removed in a channel: keep the channel list current. */
    private static function onMyChatMember(PDO $pdo, array $bot, array $upd): bool
    {
        $chat = is_array($upd['chat'] ?? null) ? $upd['chat'] : [];
        if (!in_array($chat['type'] ?? '', ['channel', 'supergroup', 'group'], true)) {
            return false;
        }
        $new = is_array($upd['new_chat_member'] ?? null) ? $upd['new_chat_member'] : [];
        $status = (string) ($new['status'] ?? '');
        if (in_array($status, ['left', 'kicked'], true)) {
            $pdo->prepare("DELETE FROM tg_funnel_channels WHERE bot_id = ? AND chat_id = ?")
                ->execute([(int) $bot['id'], (string) ($chat['id'] ?? '')]);
            return true;
        }
        self::upsertChannel($pdo, (int) $bot['id'], [
            'chat_id'    => (string) ($chat['id'] ?? ''),
            'title'      => (string) ($chat['title'] ?? ''),
            'username'   => (string) ($chat['username'] ?? ''),
            'can_invite' => ($status === 'creator' || ($status === 'administrator' && !empty($new['can_invite_users']))) ? 1 : 0,
        ]);
        return true;
    }

    /** /start <click id>: bind the user to the click, hand out the invite. */
    private static function onStart(PDO $pdo, array $bot, string $subid, array $from, string $lang): bool
    {
        $tgUserId = (string) ($from['id'] ?? '');
        if ($tgUserId === '') {
            return false;
        }
        $expired = function () use ($bot, $tgUserId, $lang): bool {
            self::send($bot, $tgUserId, self::text($lang, 'link_expired'));
            return true;
        };
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $subid) !== 1) {
            return $expired();
        }
        $stmt = $pdo->prepare("SELECT id, campaign_id, offer_id FROM clicks WHERE id = ? LIMIT 1");
        $stmt->execute([$subid]);
        $click = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$click) {
            return $expired();
        }

        // The click's own offer when it is this bot's channel offer; otherwise
        // the bot's only active channel offer (a single-channel setup). Never
        // another bot's offer.
        $offer = null;
        if ((int) ($click['offer_id'] ?? 0) > 0) {
            $o = $pdo->prepare("SELECT * FROM offers WHERE id = ? AND tg_bot_id = ? AND tg_channel_id != '' LIMIT 1");
            $o->execute([(int) $click['offer_id'], (int) $bot['id']]);
            $offer = $o->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($offer === null) {
            $o = $pdo->prepare("SELECT * FROM offers WHERE tg_bot_id = ? AND tg_channel_id != '' AND state = 'active' ORDER BY id LIMIT 2");
            $o->execute([(int) $bot['id']]);
            $rows = $o->fetchAll(PDO::FETCH_ASSOC);
            $offer = count($rows) === 1 ? $rows[0] : null;
        }
        if ($offer === null) {
            return $expired();
        }
        $channelId = self::canonicalChannel($pdo, (int) $bot['id'], (string) $offer['tg_channel_id']);
        $windowDays = max(1, min(7, (int) ($offer['tg_window_days'] ?? self::DEFAULT_WINDOW_DAYS)));

        // One binding per (user, click): a second /start re-sends the same
        // invite instead of minting a new link and a new row each time.
        $existing = $pdo->prepare("SELECT id, invite_link FROM tg_start WHERE click_id = ? AND tg_user_id = ? AND bot_id = ? AND joined_at IS NULL ORDER BY id DESC LIMIT 1");
        $existing->execute([$subid, $tgUserId, (int) $bot['id']]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        $link = $row ? (string) $row['invite_link'] : '';
        $bindingId = $row ? (int) $row['id'] : 0;

        if ($bindingId === 0) {
            // Bind BEFORE the invite: a join request without a binding is not
            // ours and is left to the channel's own moderators.
            $pdo->prepare("INSERT INTO tg_start (click_id, campaign_id, offer_id, tg_user_id, channel_id, bot_id) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$subid, (int) ($click['campaign_id'] ?? 0), (int) $offer['id'], $tgUserId, $channelId, (int) $bot['id']]);
            $bindingId = (int) $pdo->lastInsertId();
        }
        if ($link === '') {
            // creates_join_request: the join arrives as an update we can
            // approve and attribute; a plain invite joins silently.
            $invite = self::api((string) $bot['token'], 'createChatInviteLink', [
                'chat_id'              => $channelId,
                'creates_join_request' => true,
                'name'                 => 'orbitra ' . substr($subid, 0, 24),
                'expire_date'          => time() + $windowDays * 86400,
            ]);
            $link = (string) ($invite['result']['invite_link'] ?? '');
            if ($link === '') {
                self::recordError($pdo, (int) $bot['id'], 'createChatInviteLink: ' . (string) ($invite['description'] ?? 'no response'));
                return $expired();
            }
            $pdo->prepare("UPDATE tg_start SET invite_link = ? WHERE id = ?")->execute([$link, $bindingId]);
        }

        $welcome = trim((string) ($offer['tg_welcome'] ?? ''));
        self::send($bot, $tgUserId, $welcome !== '' ? $welcome : self::text($lang, 'welcome'), [
            'inline_keyboard' => [[['text' => self::text($lang, 'join_button'), 'url' => $link]]],
        ]);
        return true;
    }

    /**
     * chat_join_request: approve only users this bot invited within the
     * offer's window, stamp the join, write the tg_join conversion.
     */
    private static function onJoinRequest(PDO $pdo, array $bot, array $request): bool
    {
        $tgUserId = (string) ($request['from']['id'] ?? '');
        $channelId = (string) ($request['chat']['id'] ?? '');
        if ($tgUserId === '' || $channelId === '') {
            return false;
        }
        $stmt = $pdo->prepare("
            SELECT s.* FROM tg_start s
            LEFT JOIN offers o ON o.id = s.offer_id
            WHERE s.tg_user_id = ? AND s.channel_id = ? AND s.bot_id = ? AND s.joined_at IS NULL
              AND s.created_at >= datetime('now', '-' || MAX(1, MIN(7, COALESCE(o.tg_window_days, ?))) || ' days')
            ORDER BY s.id DESC LIMIT 1
        ");
        $stmt->execute([$tgUserId, $channelId, (int) $bot['id'], self::DEFAULT_WINDOW_DAYS]);
        $binding = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$binding) {
            return false; // not ours — the channel's moderators decide
        }

        $approve = self::api((string) $bot['token'], 'approveChatJoinRequest', ['chat_id' => $channelId, 'user_id' => (int) $tgUserId]);
        if (!($approve['ok'] ?? false)) {
            // USER_ALREADY_PARTICIPANT and the like still mean "joined";
            // anything else is worth seeing in the panel.
            $desc = (string) ($approve['description'] ?? 'no response');
            if (stripos($desc, 'ALREADY') === false) {
                self::recordError($pdo, (int) $bot['id'], 'approveChatJoinRequest: ' . $desc);
            }
        }
        $pdo->prepare("UPDATE tg_start SET joined_at = datetime('now') WHERE id = ?")->execute([(int) $binding['id']]);

        $clickId = (string) $binding['click_id'];
        $tid = 'tg' . (int) $binding['id'];
        try {
            $dup = $pdo->prepare("SELECT id FROM conversions WHERE click_id = ? AND tid = ? LIMIT 1");
            $dup->execute([$clickId, $tid]);
            if ($dup->fetchColumn() === false) {
                $pdo->prepare("INSERT INTO conversions (click_id, tid, status, original_status, payout, currency, offer_id, campaign_id, created_at)
                               VALUES (?, ?, 'tg_join', 'tg_join', 0, 'USD', ?, ?, datetime('now'))")
                    ->execute([$clickId, $tid, (int) ($binding['offer_id'] ?? 0) ?: null, (int) ($binding['campaign_id'] ?? 0) ?: null]);
                $pdo->prepare("UPDATE clicks SET is_conversion = 1 WHERE id = ? AND is_conversion = 0")->execute([$clickId]);
                // Channel alerts (tg_notify_rules) know the tg_join status too.
                if (is_file(__DIR__ . '/TelegramNotifyRules.php')) {
                    require_once __DIR__ . '/TelegramNotifyRules.php';
                    TelegramNotifyRules::dispatch($pdo, $clickId, 'tg_join', 0.0, 'USD', (int) ($binding['campaign_id'] ?? 0));
                }
            }
        } catch (\Throwable $e) {
            error_log('Orbitra tg_join conversion failed: ' . $e->getMessage());
        }
        return true;
    }

    /**
     * Inbound network postback without a click id:
     * /postback/tg?tg_user_id=&channel_id= → the click of that user's last
     * join in that channel within 30 days, or null.
     */
    public static function resolveInbound(PDO $pdo, array $query): ?string
    {
        $tgUserId = trim((string) ($query['tg_user_id'] ?? ''));
        $channel = trim((string) ($query['channel_id'] ?? ''));
        if ($tgUserId === '' || $channel === '') {
            return null;
        }
        $ids = [$channel];
        if ($channel[0] === '@') {
            $s = $pdo->prepare("SELECT chat_id FROM tg_funnel_channels WHERE LOWER(username) = LOWER(?)");
            $s->execute([substr($channel, 1)]);
            $ids = array_merge($ids, $s->fetchAll(PDO::FETCH_COLUMN));
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT click_id FROM tg_start
                               WHERE tg_user_id = ? AND channel_id IN ($in) AND joined_at IS NOT NULL
                                 AND joined_at >= datetime('now', '-" . self::INBOUND_DAYS . " days')
                               ORDER BY joined_at DESC, id DESC LIMIT 1");
        $stmt->execute(array_merge([$tgUserId], $ids));
        $click = $stmt->fetchColumn();
        return $click !== false ? (string) $click : null;
    }

    // ------------------------------------------------------------ helpers

    private static function send(array $bot, string $chatId, string $text, ?array $markup = null): void
    {
        if ($chatId === '') {
            return;
        }
        $params = ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true];
        if ($markup !== null) {
            $params['reply_markup'] = $markup;
        }
        self::api((string) $bot['token'], 'sendMessage', $params);
    }

    public static function recordError(PDO $pdo, int $botId, string $error): void
    {
        try {
            $pdo->prepare("UPDATE tg_funnel_bots SET last_error = ? WHERE id = ?")->execute([mb_substr($error, 0, 300), $botId]);
        } catch (\Throwable $e) {
        }
    }
}
