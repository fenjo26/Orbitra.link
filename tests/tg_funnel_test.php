<?php
/**
 * tests/tg_funnel_test.php
 *
 * The Telegram ad funnel runs on SEPARATE bots, never the admin bot, and the
 * admin bot gains rule-based conversion alerts to channels. No network: the
 * Bot API is stubbed through TelegramFunnel::$testApi, sends through
 * TelegramNotifyRules::$testSender and the admin bot's outbox.
 *
 *  1. schema: migration 68 creates the funnel/alert tables on a real migrated DB;
 *  2. separation: the admin bot ignores "/start <click>" from a stranger (no
 *     binding, the stranger gets the not-connected refusal), its poller never
 *     asks for chat_join_request; a funnel bot refuses the admin token;
 *  3. funnel: /start binds and sends a join-request invite (once per click),
 *     a join request from a stranger is left alone, the bound user's join is
 *     approved and becomes a tg_join conversion with campaign/offer, outside
 *     the offer's window it is not approved;
 *  4. channels: my_chat_member keeps the list, @username resolves to the
 *     numeric id chat_join_request reports;
 *  5. inbound postback by tg_user_id + channel (numeric or @username);
 *  6. offer destination: t.me link of the FUNNEL bot; a disabled bot → empty;
 *  7. alert rules: status groups, campaign filter, HTML-escaped render,
 *     unknown macros kept, ad names from ad_entities, unreplaced platform
 *     macros never shown; tg_join fires a tg_join rule.
 *
 * Run: php tests/tg_funnel_test.php
 */

define('ORBITRA_TELEGRAM_NO_WEBHOOK', true);
define('ORBITRA_TELEGRAM_TEST_OUTBOX', true);

$root = realpath(__DIR__ . '/..');
$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

// --- A real, fully migrated database in a temp dir --------------------------
$tmp = sys_get_temp_dir() . '/orbitra_tg_funnel_' . getmypid();
@mkdir($tmp, 0700, true);
$dbFile = $tmp . '/db.sqlite';
@unlink($dbFile);
$cfg = (string) file_get_contents($root . '/config.php');
$cfg = preg_replace('/\$db_file\s*=\s*__DIR__\s*\.\s*[\'"]\/orbitra_db\.sqlite[\'"];/', '$db_file = ' . var_export($dbFile, true) . ';', $cfg, 1, $replaced);
$cfg = str_replace('__DIR__', var_export($root, true), $cfg);
file_put_contents($tmp . '/config.php', $cfg);
register_shutdown_function(function () use ($tmp) {
    foreach (glob($tmp . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
});
ob_start();
require $tmp . '/config.php';
ob_end_clean();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

require_once $root . '/core/TelegramFunnel.php';
require_once $root . '/core/TelegramNotifyRules.php';
require_once $root . '/core/OfferUrl.php';

// Bot API stub: records calls, answers like Telegram.
$calls = [];
TelegramFunnel::$testApi = function (string $token, string $method, array $params) use (&$calls) {
    $calls[] = ['token' => $token, 'method' => $method, 'params' => $params];
    switch ($method) {
        case 'getMe':
            return ['ok' => true, 'result' => ['id' => 5550001, 'username' => 'promo_funnel_bot']];
        case 'deleteWebhook':
        case 'approveChatJoinRequest':
        case 'sendMessage':
            return ['ok' => true, 'result' => true];
        case 'createChatInviteLink':
            return ['ok' => true, 'result' => ['invite_link' => 'https://t.me/+INVITE' . count($GLOBALS['calls'] ?? [])]];
        case 'getChat':
            if (($params['chat_id'] ?? '') === '@promo_channel' || ($params['chat_id'] ?? '') === '-1002000000001') {
                return ['ok' => true, 'result' => ['id' => -1002000000001, 'title' => 'Promo', 'username' => 'promo_channel', 'type' => 'channel']];
            }
            return ['ok' => false, 'description' => 'Bad Request: chat not found'];
        case 'getChatMember':
            return ['ok' => true, 'result' => ['status' => 'administrator', 'can_invite_users' => true, 'can_post_messages' => true]];
    }
    return ['ok' => false, 'description' => 'unexpected method ' . $method];
};
$GLOBALS['calls'] = &$calls;
$methods = function () use (&$calls): array {
    return array_map(fn($c) => $c['method'], $calls);
};

// ---- 1. schema ---------------------------------------------------------------
echo "schema\n";
$version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
check('database migrated to 68', $version === 68, "user_version=$version");
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['tg_funnel_bots', 'tg_funnel_channels', 'tg_notify_rules', 'tg_notify_log', 'tg_start'] as $t) {
    check("table $t exists", in_array($t, $tables, true));
}
$offerCols = array_column($pdo->query('PRAGMA table_info(offers)')->fetchAll(), 'name');
check('offers.tg_bot_id exists', in_array('tg_bot_id', $offerCols, true));

// ---- fixtures ------------------------------------------------------------------
$pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('telegram_bot_token', '1111111:ADMINTOKENADMINTOKENADMINTOKENADMIN')");
$pdo->exec("INSERT INTO campaigns (id, name, alias) VALUES (70, 'TG Promo', 'tgpromo')");
$add = TelegramFunnel::addBot($pdo, '2222222:FUNNELTOKENFUNNELTOKENFUNNELTOKEN12', 'Promo bot');
$botId = (int) ($add['id'] ?? 0);
$bot = TelegramFunnel::bot($pdo, $botId);
$pdo->exec("INSERT INTO offers (id, name, url, tg_bot_id, tg_channel_id, tg_window_days, tg_welcome, state) VALUES (80, 'TG offer', '', $botId, '@promo_channel', 2, '', 'active')");
$pdo->exec("INSERT INTO clicks (id, campaign_id, offer_id, ip, country, created_at, parameters_json)
            VALUES ('clk-tg-1', 70, 80, '1.1.1.1', 'GR', datetime('now'), '{\"ad_id\":\"120001\",\"adset_id\":\"__AID__\",\"sub_id_1\":\"buyer<b>\"}')");

// ---- 2. separation ---------------------------------------------------------------
echo "separation from the admin bot\n";
check('a funnel bot is registered by token (getMe + deleteWebhook)',
    $botId > 0 && ($add['username'] ?? '') === 'promo_funnel_bot' && in_array('deleteWebhook', $methods(), true), json_encode($add));
$refused = TelegramFunnel::addBot($pdo, '1111111:ADMINTOKENADMINTOKENADMINTOKENADMIN');
check('the admin bot token is refused as a funnel bot', ($refused['error'] ?? '') === 'admin_token', json_encode($refused));
check('a malformed token is refused before any API call', (TelegramFunnel::addBot($pdo, 'nonsense')['error'] ?? '') === 'token_format');

// telegram_bot.php require_once's the REAL config.php; point it at ours.
$botSrc = (string) file_get_contents($root . '/telegram_bot.php');
$botSrc = str_replace("require_once __DIR__ . '/config.php';", 'require_once ' . var_export($tmp . '/config.php', true) . ';', $botSrc);
$botSrc = str_replace('__DIR__', var_export($root, true), $botSrc);
file_put_contents($tmp . '/telegram_bot.php', $botSrc);
require_once $tmp . '/telegram_bot.php';
$GLOBALS['orbitra_telegram_outbox'] = [];
orbitraTelegramProcessUpdate($pdo, '1111111:ADMINTOKENADMINTOKENADMINTOKENADMIN', [
    'message' => ['chat' => ['id' => '987654', 'type' => 'private'], 'from' => ['id' => 987654], 'text' => '/start clk-tg-1'],
]);
$bindings = (int) $pdo->query("SELECT COUNT(*) FROM tg_start")->fetchColumn();
$adminReply = (string) (($GLOBALS['orbitra_telegram_outbox'][0]['params']['text'] ?? ''));
check('the ADMIN bot ignores "/start <click>" — no binding', $bindings === 0, "bindings=$bindings");
check('…and the stranger only gets the not-connected refusal', strpos($adminReply, '⛔') !== false, $adminReply);
check('telegram_bot.php has no funnel code left',
    strpos((string) file_get_contents($root . '/telegram_bot.php'), 'chat_join_request') === false);
check('the admin poller does not ask for chat_join_request',
    strpos((string) file_get_contents($root . '/telegram_poll_cron.php'), 'chat_join_request') === false);
check('the funnel poller does', in_array('chat_join_request', TelegramFunnel::ALLOWED_UPDATES, true));

// ---- 4. channels (before the funnel: the offer points at @promo_channel) ----------
echo "channels\n";
$ch = TelegramFunnel::addChannel($pdo, $bot, 'https://t.me/promo_channel');
check('a channel added by t.me link is stored under its numeric id with invite rights',
    ($ch['channel']['chat_id'] ?? '') === '-1002000000001' && (int) ($ch['channel']['can_invite'] ?? 0) === 1, json_encode($ch));
check('@username resolves to the numeric id', TelegramFunnel::canonicalChannel($pdo, $botId, '@promo_channel') === '-1002000000001');
check('an unknown channel is reported, not stored', (TelegramFunnel::addChannel($pdo, $bot, '@nope_channel')['error'] ?? '') === 'not_found');
TelegramFunnel::processUpdate($pdo, $bot, ['my_chat_member' => [
    'chat' => ['id' => -1003000000003, 'title' => 'Second', 'type' => 'channel'],
    'new_chat_member' => ['status' => 'administrator', 'can_invite_users' => false],
]]);
$second = $pdo->query("SELECT can_invite FROM tg_funnel_channels WHERE chat_id = '-1003000000003'")->fetchColumn();
check('promotion in a channel is picked up from my_chat_member (without invite right)', $second !== false && (int) $second === 0);
TelegramFunnel::processUpdate($pdo, $bot, ['my_chat_member' => [
    'chat' => ['id' => -1003000000003, 'type' => 'channel'],
    'new_chat_member' => ['status' => 'left'],
]]);
check('removal drops the channel', $pdo->query("SELECT COUNT(*) FROM tg_funnel_channels WHERE chat_id = '-1003000000003'")->fetchColumn() == 0);

// ---- 3. funnel ------------------------------------------------------------------
echo "funnel\n";
$calls = [];
TelegramFunnel::processUpdate($pdo, $bot, ['message' => [
    'chat' => ['id' => 42, 'type' => 'private'], 'from' => ['id' => 42, 'language_code' => 'ru'], 'text' => '/start clk-tg-1',
]]);
$b = $pdo->query("SELECT * FROM tg_start WHERE click_id = 'clk-tg-1'")->fetch();
check('/start <click> binds the visitor to the click under the funnel bot',
    $b && $b['tg_user_id'] === '42' && (int) $b['bot_id'] === $botId && (int) $b['campaign_id'] === 70);
check('the binding stores the NUMERIC channel id', $b && $b['channel_id'] === '-1002000000001', $b['channel_id'] ?? '');
$invite = array_values(array_filter($calls, fn($c) => $c['method'] === 'createChatInviteLink'))[0]['params'] ?? [];
check('the invite is a join-request link that expires with the offer window',
    !empty($invite['creates_join_request']) && ($invite['expire_date'] ?? 0) > time() + 86400 && ($invite['expire_date'] ?? 0) <= time() + 2 * 86400 + 5);
$sent = array_values(array_filter($calls, fn($c) => $c['method'] === 'sendMessage'))[0]['params'] ?? [];
check('the visitor gets the welcome in their language with the join button',
    strpos((string) ($sent['text'] ?? ''), 'Добро пожаловать') !== false
    && strpos((string) ($sent['reply_markup']['inline_keyboard'][0][0]['url'] ?? ''), 'https://t.me/+INVITE') === 0,
    json_encode($sent, JSON_UNESCAPED_UNICODE));
check('every funnel call used the FUNNEL token', !array_filter($calls, fn($c) => strpos($c['token'], '1111111:') === 0));

$calls = [];
TelegramFunnel::processUpdate($pdo, $bot, ['message' => [
    'chat' => ['id' => 42, 'type' => 'private'], 'from' => ['id' => 42], 'text' => '/start clk-tg-1',
]]);
check('a repeated /start re-sends the same invite (no new link, no new binding)',
    !in_array('createChatInviteLink', $methods(), true)
    && (int) $pdo->query("SELECT COUNT(*) FROM tg_start")->fetchColumn() === 1);

$calls = [];
TelegramFunnel::processUpdate($pdo, $bot, ['message' => [
    'chat' => ['id' => 43, 'type' => 'private'], 'from' => ['id' => 43], 'text' => '/start no-such-click',
]]);
check('an unknown click gets "link expired", no binding',
    (int) $pdo->query("SELECT COUNT(*) FROM tg_start WHERE tg_user_id = '43'")->fetchColumn() === 0
    && in_array('sendMessage', $methods(), true));
$calls = [];
check('group chatter is ignored', TelegramFunnel::processUpdate($pdo, $bot, ['message' => [
    'chat' => ['id' => -100, 'type' => 'supergroup'], 'from' => ['id' => 9], 'text' => '/start clk-tg-1',
]]) === false && $calls === []);

// Alert rule listening for tg_join.
$alerts = [];
TelegramNotifyRules::$testSender = function ($token, $chat, $html) use (&$alerts) {
    $alerts[] = compact('token', 'chat', 'html');
    return ['ok' => true, 'error' => ''];
};
$pdo->exec("INSERT INTO tg_notify_rules (name, chat_id, statuses, campaign_ids, template) VALUES ('joins', '-1009999', '[\"tg_join\"]', '[]', 'join {campaign}')");

$calls = [];
$strangerHandled = TelegramFunnel::processUpdate($pdo, $bot, ['chat_join_request' => [
    'chat' => ['id' => -1002000000001], 'from' => ['id' => 777],
]]);
check('a join request from someone the bot never invited is left to moderators',
    $strangerHandled === false && !in_array('approveChatJoinRequest', $methods(), true));

$calls = [];
TelegramFunnel::processUpdate($pdo, $bot, ['chat_join_request' => [
    'chat' => ['id' => -1002000000001], 'from' => ['id' => 42],
]]);
$conv = $pdo->query("SELECT * FROM conversions WHERE click_id = 'clk-tg-1'")->fetch();
check('the invited visitor is approved', in_array('approveChatJoinRequest', $methods(), true));
check('…and a tg_join conversion lands on the click with campaign and offer',
    $conv && $conv['status'] === 'tg_join' && (int) $conv['campaign_id'] === 70 && (int) $conv['offer_id'] === 80, json_encode($conv));
check('the tg_join alert rule fired through the ADMIN bot',
    count($alerts) === 1 && $alerts[0]['html'] === 'join TG Promo' && strpos($alerts[0]['token'], '1111111:') === 0, json_encode($alerts));
$calls = [];
TelegramFunnel::processUpdate($pdo, $bot, ['chat_join_request' => ['chat' => ['id' => -1002000000001], 'from' => ['id' => 42]]]);
check('a second join request of the same user is not a second conversion',
    (int) $pdo->query("SELECT COUNT(*) FROM conversions WHERE click_id = 'clk-tg-1'")->fetchColumn() === 1);

// Outside the window: a 3-day-old binding on a 2-day offer.
$pdo->exec("INSERT INTO clicks (id, campaign_id, offer_id, ip, created_at) VALUES ('clk-old', 70, 80, '2.2.2.2', datetime('now'))");
$pdo->exec("INSERT INTO tg_start (click_id, campaign_id, offer_id, tg_user_id, channel_id, bot_id, created_at)
            VALUES ('clk-old', 70, 80, '55', '-1002000000001', $botId, datetime('now', '-3 days'))");
$calls = [];
TelegramFunnel::processUpdate($pdo, $bot, ['chat_join_request' => ['chat' => ['id' => -1002000000001], 'from' => ['id' => 55]]]);
check('a join after the offer window is not approved', !in_array('approveChatJoinRequest', $methods(), true));

// ---- 5. inbound postback -----------------------------------------------------------
echo "inbound postback\n";
check('resolves by numeric channel id',
    TelegramFunnel::resolveInbound($pdo, ['tg_user_id' => '42', 'channel_id' => '-1002000000001']) === 'clk-tg-1');
check('resolves by @username', TelegramFunnel::resolveInbound($pdo, ['tg_user_id' => '42', 'channel_id' => '@promo_channel']) === 'clk-tg-1');
check('a user who never joined resolves to nothing', TelegramFunnel::resolveInbound($pdo, ['tg_user_id' => '55', 'channel_id' => '-1002000000001']) === null);
check('another channel resolves to nothing', TelegramFunnel::resolveInbound($pdo, ['tg_user_id' => '42', 'channel_id' => '-1000000000000']) === null);

// ---- 6. offer destination ------------------------------------------------------------
echo "offer destination\n";
$pdo->exec("UPDATE offers SET tg_channel_id = '-1002000000001' WHERE id = 80");
$dest = orbitraGetOfferDestination($pdo, 80);
check('the offer links to the FUNNEL bot', ($dest['url'] ?? '') === 'https://t.me/promo_funnel_bot?start={subid}' && !empty($dest['is_telegram']), $dest['url'] ?? '');
$pdo->exec("UPDATE tg_funnel_bots SET is_active = 0 WHERE id = $botId");
check('a disabled funnel bot gives no link', (orbitraGetOfferDestination($pdo, 80)['url'] ?? 'x') === '');
$pdo->exec("UPDATE tg_funnel_bots SET is_active = 1 WHERE id = $botId");

// ---- 7. alert rules ---------------------------------------------------------------------
echo "alert rules\n";
check('deposit aliases map to the deposit filter', TelegramNotifyRules::statusKeyOf('dep') === 'deposit');
check('sale aliases map to the sale filter', TelegramNotifyRules::statusKeyOf('approved') === 'sale');
$rule = ['statuses' => '["deposit"]', 'campaign_ids' => '[70]'];
check('rule: deposit on campaign 70 fires', TelegramNotifyRules::matches($rule, 'deposit', 70));
check('rule: registration does not', !TelegramNotifyRules::matches($rule, 'reg', 70));
check('rule: another campaign does not', !TelegramNotifyRules::matches($rule, 'deposit', 71));
check('rule with empty lists fires on anything', TelegramNotifyRules::matches(['statuses' => '[]', 'campaign_ids' => '[]'], 'whatever', 5));

$pdo->exec("INSERT INTO ad_entities (platform, level, external_id, name) VALUES ('facebook', 'ad', '120001', 'Girl_phone_v3')");
$ctx = TelegramNotifyRules::context($pdo, 'clk-tg-1', 'deposit', 120, 'USD', 70);
check('ad name comes from ad_entities', $ctx['ad_name'] === 'Girl_phone_v3', $ctx['ad_name']);
check('an unreplaced platform macro is never shown', $ctx['adset_name'] === '', $ctx['adset_name']);
check('country flag', $ctx['flag'] === '🇬🇷');
$html = TelegramNotifyRules::render('<b>{campaign}</b> {sub1} {unknown}', $ctx);
check('values are HTML-escaped, template markup kept, unknown macros stay',
    $html === '<b>TG Promo</b> buyer&lt;b&gt; {unknown}', $html);

$alerts = [];
$pdo->exec("DELETE FROM tg_notify_rules");
$pdo->exec("INSERT INTO tg_notify_rules (name, chat_id, statuses, campaign_ids, template) VALUES ('deps', '-1001', '[\"deposit\"]', '[70]', '{payout} {currency} {ad_name}')");
$pdo->exec("INSERT INTO tg_notify_rules (name, chat_id, statuses, campaign_ids, template, is_active) VALUES ('off', '-1002', '[]', '[]', 'x', 0)");
$n = TelegramNotifyRules::dispatch($pdo, 'clk-tg-1', 'deposit', 120, 'USD', 70);
check('dispatch sends only matching active rules', $n === 1 && count($alerts) === 1 && $alerts[0]['html'] === '120.00 USD Girl_phone_v3', json_encode($alerts, JSON_UNESCAPED_UNICODE));
check('every send is logged', (int) $pdo->query("SELECT COUNT(*) FROM tg_notify_log WHERE ok = 1")->fetchColumn() >= 1);
$alerts = [];
TelegramNotifyRules::dispatch($pdo, 'clk-tg-1', 'reg', 0, 'USD', 70);
check('a non-matching status sends nothing', $alerts === []);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
