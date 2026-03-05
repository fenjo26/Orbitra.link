<?php
/**
 * Telegram Bot Webhook Handler
 * Receives updates from Telegram and processes bot commands
 */
require_once 'config.php';

// Bot translations
function botText($lang, $key, $params = [])
{
    $texts = [
        'ru' => [
            'welcome' => "🚀 *Добро пожаловать в Orbitra v0.9 Bot!*\n\nЯ помогу отслеживать статистику ваших кампаний.\n\nДоступные команды:\n/stats — Статистика за сегодня\n/stats 7d — За последние 7 дней\n/campaigns — Активные кампании\n/campaign ID — Стата по кампании\n/top — ТОП-5 по доходу\n/conversions — Последние конверсии\n/notify on|off — Уведомления\n/daily on|off — Ежедневная сводка\n/lang ru|en — Язык бота\n/help — Справка",
            'help' => "📖 *Доступные команды:*\n\n/stats — Статистика за сегодня\n/stats 1d|7d|30d — За период\n/stats yesterday — За вчера\n/campaigns — Список кампаний\n/campaign ID — Детали кампании\n/top — ТОП-5 кампаний\n/conversions — Последние 10 конверсий\n/notify on|off — Уведомления о конверсиях\n/daily on|off — Ежедневная сводка\n/lang ru|en — Сменить язык",
            'stats_title' => "📊 *Статистика: {period}*",
            'clicks' => "Кликов",
            'unique_clicks' => "Уникальных",
            'conversions' => "Конверсий",
            'revenue' => "Доход",
            'cost' => "Расход",
            'profit' => "Профит",
            'roi' => "ROI",
            'cr' => "CR",
            'no_data' => "Нет данных за этот период.",
            'today' => "Сегодня",
            'yesterday' => "Вчера",
            'last_7d' => "7 дней",
            'last_30d' => "30 дней",
            'campaigns_title' => "📋 *Активные кампании:*",
            'no_campaigns' => "Нет активных кампаний.",
            'campaign_detail' => "📊 *Кампания: {name}*",
            'campaign_not_found' => "❌ Кампания не найдена.",
            'top_title' => "🏆 *ТОП-5 кампаний по доходу (сегодня):*",
            'no_top' => "Нет данных за сегодня.",
            'conversions_title' => "🔔 *Последние конверсии:*",
            'no_conversions' => "Нет конверсий.",
            'notify_on' => "✅ Уведомления о конверсиях *включены*.",
            'notify_off' => "🔕 Уведомления о конверсиях *отключены*.",
            'daily_on' => "✅ Ежедневная сводка *включена*.",
            'daily_off' => "🔕 Ежедневная сводка *отключена*.",
            'lang_set' => "✅ Язык установлен: *Русский*",
            'unknown' => "❓ Неизвестная команда. Используйте /help",
            'new_conversion' => "🔔 *Новая конверсия!*\n\n📊 Кампания: *{campaign}*\n📌 Статус: `{status}`\n💰 Сумма: *{payout} {currency}*\n🌍 Страна: {country}\n🕐 Время: {time}",
            'daily_summary' => "📊 *Ежедневная сводка — {date}*",
            'status' => "Статус",
            'payout' => "Выплата",
            'campaign' => "Кампания",
            'country' => "Страна",
        ],
        'en' => [
            'welcome' => "🚀 *Welcome to Orbitra v0.9 Bot!*\n\nI'll help you track your campaign stats.\n\nAvailable commands:\n/stats — Today's statistics\n/stats 7d — Last 7 days\n/campaigns — Active campaigns\n/campaign ID — Campaign details\n/top — Top 5 by revenue\n/conversions — Recent conversions\n/notify on|off — Notifications\n/daily on|off — Daily summary\n/lang ru|en — Bot language\n/help — Help",
            'help' => "📖 *Available commands:*\n\n/stats — Today's statistics\n/stats 1d|7d|30d — For a period\n/stats yesterday — Yesterday\n/campaigns — Campaign list\n/campaign ID — Campaign details\n/top — Top 5 campaigns\n/conversions — Last 10 conversions\n/notify on|off — Conversion notifications\n/daily on|off — Daily summary report\n/lang ru|en — Change language",
            'stats_title' => "📊 *Statistics: {period}*",
            'clicks' => "Clicks",
            'unique_clicks' => "Unique",
            'conversions' => "Conversions",
            'revenue' => "Revenue",
            'cost' => "Cost",
            'profit' => "Profit",
            'roi' => "ROI",
            'cr' => "CR",
            'no_data' => "No data for this period.",
            'today' => "Today",
            'yesterday' => "Yesterday",
            'last_7d' => "7 days",
            'last_30d' => "30 days",
            'campaigns_title' => "📋 *Active campaigns:*",
            'no_campaigns' => "No active campaigns.",
            'campaign_detail' => "📊 *Campaign: {name}*",
            'campaign_not_found' => "❌ Campaign not found.",
            'top_title' => "🏆 *Top 5 campaigns by revenue (today):*",
            'no_top' => "No data for today.",
            'conversions_title' => "🔔 *Recent conversions:*",
            'no_conversions' => "No conversions.",
            'notify_on' => "✅ Conversion notifications *enabled*.",
            'notify_off' => "🔕 Conversion notifications *disabled*.",
            'daily_on' => "✅ Daily summary *enabled*.",
            'daily_off' => "🔕 Daily summary *disabled*.",
            'lang_set' => "✅ Language set: *English*",
            'unknown' => "❓ Unknown command. Use /help",
            'new_conversion' => "🔔 *New Conversion!*\n\n📊 Campaign: *{campaign}*\n📌 Status: `{status}`\n💰 Amount: *{payout} {currency}*\n🌍 Country: {country}\n🕐 Time: {time}",
            'daily_summary' => "📊 *Daily Summary — {date}*",
            'status' => "Status",
            'payout' => "Payout",
            'campaign' => "Campaign",
            'country' => "Country",
        ]
    ];

    $text = $texts[$lang][$key] ?? $texts['en'][$key] ?? $key;
    foreach ($params as $k => $v) {
        $text = str_replace('{' . $k . '}', $v, $text);
    }
    return $text;
}

// Send message to Telegram
function sendTelegram($token, $chatId, $text, $parseMode = 'Markdown')
{
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => true
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}

// Get bot token from settings
$stmt = $pdo->query("SELECT value FROM settings WHERE key = 'telegram_bot_token'");
$botToken = $stmt ? $stmt->fetchColumn() : '';

if (!$botToken) {
    http_response_code(200);
    die('No token configured');
}

// Read incoming update
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update || !isset($update['message'])) {
    http_response_code(200);
    die('ok');
}

$message = $update['message'];
$chatId = (string)$message['chat']['id'];
$text = trim($message['text'] ?? '');
$username = $message['from']['username'] ?? '';
$firstName = $message['from']['first_name'] ?? '';

// Register/update chat
$stmt = $pdo->prepare("INSERT OR IGNORE INTO telegram_bot_chats (chat_id, username, first_name) VALUES (?, ?, ?)");
$stmt->execute([$chatId, $username, $firstName]);
$pdo->prepare("UPDATE telegram_bot_chats SET username = ?, first_name = ?, is_active = 1 WHERE chat_id = ?")->execute([$username, $firstName, $chatId]);

// Get chat language
$stmt = $pdo->prepare("SELECT language FROM telegram_bot_chats WHERE chat_id = ?");
$stmt->execute([$chatId]);
$lang = $stmt->fetchColumn() ?: 'ru';

// Parse command
$parts = explode(' ', $text, 2);
$command = strtolower($parts[0]);
$arg = $parts[1] ?? '';

switch ($command) {
    case '/start':
        sendTelegram($botToken, $chatId, botText($lang, 'welcome'));
        break;

    case '/help':
        sendTelegram($botToken, $chatId, botText($lang, 'help'));
        break;

    case '/stats':
        handleStats($pdo, $botToken, $chatId, $lang, $arg);
        break;

    case '/campaigns':
        handleCampaigns($pdo, $botToken, $chatId, $lang);
        break;

    case '/campaign':
        handleCampaignDetail($pdo, $botToken, $chatId, $lang, $arg);
        break;

    case '/top':
        handleTop($pdo, $botToken, $chatId, $lang);
        break;

    case '/conversions':
        handleConversions($pdo, $botToken, $chatId, $lang);
        break;

    case '/notify':
        $val = strtolower(trim($arg));
        $enabled = ($val === 'on' || $val === '1') ? 1 : 0;
        $pdo->prepare("UPDATE telegram_bot_chats SET notify_conversions = ? WHERE chat_id = ?")->execute([$enabled, $chatId]);
        sendTelegram($botToken, $chatId, botText($lang, $enabled ? 'notify_on' : 'notify_off'));
        break;

    case '/daily':
        $val = strtolower(trim($arg));
        $enabled = ($val === 'on' || $val === '1') ? 1 : 0;
        $pdo->prepare("UPDATE telegram_bot_chats SET notify_daily = ? WHERE chat_id = ?")->execute([$enabled, $chatId]);
        sendTelegram($botToken, $chatId, botText($lang, $enabled ? 'daily_on' : 'daily_off'));
        break;

    case '/lang':
        $newLang = strtolower(trim($arg));
        if (!in_array($newLang, ['ru', 'en']))
            $newLang = 'ru';
        $pdo->prepare("UPDATE telegram_bot_chats SET language = ? WHERE chat_id = ?")->execute([$newLang, $chatId]);
        sendTelegram($botToken, $chatId, botText($newLang, 'lang_set'));
        break;

    default:
        sendTelegram($botToken, $chatId, botText($lang, 'unknown'));
        break;
}

http_response_code(200);
echo 'ok';

// === Command Handlers ===

function handleStats($pdo, $token, $chatId, $lang, $period)
{
    $period = strtolower(trim($period));

    // Determine date range
    $now = new DateTime();
    switch ($period) {
        case 'yesterday':
            $from = (clone $now)->modify('-1 day')->format('Y-m-d 00:00:00');
            $to = (clone $now)->modify('-1 day')->format('Y-m-d 23:59:59');
            $label = botText($lang, 'yesterday');
            break;
        case '7d':
            $from = (clone $now)->modify('-7 days')->format('Y-m-d 00:00:00');
            $to = $now->format('Y-m-d 23:59:59');
            $label = botText($lang, 'last_7d');
            break;
        case '30d':
            $from = (clone $now)->modify('-30 days')->format('Y-m-d 00:00:00');
            $to = $now->format('Y-m-d 23:59:59');
            $label = botText($lang, 'last_30d');
            break;
        default: // today
            $from = $now->format('Y-m-d 00:00:00');
            $to = $now->format('Y-m-d 23:59:59');
            $label = botText($lang, 'today');
            break;
    }

    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as clicks,
            COUNT(DISTINCT ip) as unique_clicks,
            SUM(is_conversion) as conversions,
            SUM(revenue) as revenue,
            SUM(cost) as cost
        FROM clicks 
        WHERE created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$from, $to]);
    $data = $stmt->fetch();

    if (!$data || $data['clicks'] == 0) {
        sendTelegram($token, $chatId, botText($lang, 'no_data'));
        return;
    }

    $clicks = (int)$data['clicks'];
    $unique = (int)$data['unique_clicks'];
    $conv = (int)$data['conversions'];
    $rev = number_format((float)$data['revenue'], 2);
    $costVal = number_format((float)$data['cost'], 2);
    $profit = number_format((float)$data['revenue'] - (float)$data['cost'], 2);
    $cr = $clicks > 0 ? number_format(($conv / $clicks) * 100, 2) : '0.00';
    $roi = (float)$data['cost'] > 0 ? number_format((((float)$data['revenue'] - (float)$data['cost']) / (float)$data['cost']) * 100, 1) : '∞';

    $msg = botText($lang, 'stats_title', ['period' => $label]) . "\n\n";
    $msg .= "👆 " . botText($lang, 'clicks') . ": *{$clicks}* ({$unique} " . botText($lang, 'unique_clicks') . ")\n";
    $msg .= "🎯 " . botText($lang, 'conversions') . ": *{$conv}*\n";
    $msg .= "💰 " . botText($lang, 'revenue') . ": *\${$rev}*\n";
    $msg .= "💸 " . botText($lang, 'cost') . ": *\${$costVal}*\n";
    $msg .= "📈 " . botText($lang, 'profit') . ": *\${$profit}*\n";
    $msg .= "📊 " . botText($lang, 'cr') . ": *{$cr}%* | " . botText($lang, 'roi') . ": *{$roi}%*";

    sendTelegram($token, $chatId, $msg);
}

function handleCampaigns($pdo, $token, $chatId, $lang)
{
    $today = date('Y-m-d');
    $stmt = $pdo->query("
        SELECT c.id, c.name, c.alias,
            (SELECT COUNT(*) FROM clicks WHERE campaign_id = c.id AND DATE(created_at) = '{$today}') as clicks,
            (SELECT SUM(revenue) FROM clicks WHERE campaign_id = c.id AND DATE(created_at) = '{$today}') as revenue,
            (SELECT SUM(is_conversion) FROM clicks WHERE campaign_id = c.id AND DATE(created_at) = '{$today}') as conv
        FROM campaigns c 
        WHERE c.is_archived = 0
        ORDER BY clicks DESC
        LIMIT 20
    ");
    $campaigns = $stmt->fetchAll();

    if (empty($campaigns)) {
        sendTelegram($token, $chatId, botText($lang, 'no_campaigns'));
        return;
    }

    $msg = botText($lang, 'campaigns_title') . "\n\n";
    foreach ($campaigns as $i => $c) {
        $clicks = (int)$c['clicks'];
        $rev = number_format((float)($c['revenue'] ?? 0), 2);
        $conv = (int)($c['conv'] ?? 0);
        $num = $i + 1;
        $msg .= "*{$num}.* `[{$c['id']}]` {$c['name']}\n";
        $msg .= "   👆 {$clicks} | 🎯 {$conv} | 💰 \${$rev}\n\n";
    }

    sendTelegram($token, $chatId, $msg);
}

function handleCampaignDetail($pdo, $token, $chatId, $lang, $campaignId)
{
    $campaignId = (int)trim($campaignId);
    if (!$campaignId) {
        sendTelegram($token, $chatId, botText($lang, 'campaign_not_found'));
        return;
    }

    $stmt = $pdo->prepare("SELECT id, name, alias FROM campaigns WHERE id = ?");
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch();

    if (!$campaign) {
        sendTelegram($token, $chatId, botText($lang, 'campaign_not_found'));
        return;
    }

    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as clicks,
            COUNT(DISTINCT ip) as unique_clicks,
            SUM(is_conversion) as conversions,
            SUM(revenue) as revenue,
            SUM(cost) as cost
        FROM clicks 
        WHERE campaign_id = ? AND DATE(created_at) = ?
    ");
    $stmt->execute([$campaignId, $today]);
    $data = $stmt->fetch();

    $clicks = (int)$data['clicks'];
    $unique = (int)$data['unique_clicks'];
    $conv = (int)$data['conversions'];
    $rev = number_format((float)$data['revenue'], 2);
    $costVal = number_format((float)$data['cost'], 2);
    $profit = number_format((float)$data['revenue'] - (float)$data['cost'], 2);
    $cr = $clicks > 0 ? number_format(($conv / $clicks) * 100, 2) : '0.00';

    $msg = botText($lang, 'campaign_detail', ['name' => $campaign['name']]) . "\n";
    $msg .= "🔗 Alias: `{$campaign['alias']}`\n\n";
    $msg .= "👆 " . botText($lang, 'clicks') . ": *{$clicks}* ({$unique})\n";
    $msg .= "🎯 " . botText($lang, 'conversions') . ": *{$conv}*\n";
    $msg .= "💰 " . botText($lang, 'revenue') . ": *\${$rev}*\n";
    $msg .= "💸 " . botText($lang, 'cost') . ": *\${$costVal}*\n";
    $msg .= "📈 " . botText($lang, 'profit') . ": *\${$profit}*\n";
    $msg .= "📊 " . botText($lang, 'cr') . ": *{$cr}%*";

    sendTelegram($token, $chatId, $msg);
}

function handleTop($pdo, $token, $chatId, $lang)
{
    $today = date('Y-m-d');
    $stmt = $pdo->query("
        SELECT c.id, c.name,
            COUNT(*) as clicks,
            SUM(cl.is_conversion) as conv,
            SUM(cl.revenue) as revenue
        FROM clicks cl
        JOIN campaigns c ON c.id = cl.campaign_id
        WHERE DATE(cl.created_at) = '{$today}'
        GROUP BY c.id
        ORDER BY revenue DESC
        LIMIT 5
    ");
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        sendTelegram($token, $chatId, botText($lang, 'no_top'));
        return;
    }

    $medals = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'];
    $msg = botText($lang, 'top_title') . "\n\n";
    foreach ($rows as $i => $r) {
        $rev = number_format((float)$r['revenue'], 2);
        $conv = (int)($r['conv'] ?? 0);
        $msg .= "{$medals[$i]} *{$r['name']}*\n";
        $msg .= "   💰 \${$rev} | 🎯 {$conv} | 👆 {$r['clicks']}\n\n";
    }

    sendTelegram($token, $chatId, $msg);
}

function handleConversions($pdo, $token, $chatId, $lang)
{
    $stmt = $pdo->query("
        SELECT cv.status, cv.payout, cv.currency, cv.created_at,
               c.name as campaign_name,
               cl.country
        FROM conversions cv
        LEFT JOIN clicks cl ON cl.id = cv.click_id
        LEFT JOIN campaigns c ON c.id = cl.campaign_id
        ORDER BY cv.created_at DESC
        LIMIT 10
    ");
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        sendTelegram($token, $chatId, botText($lang, 'no_conversions'));
        return;
    }

    $msg = botText($lang, 'conversions_title') . "\n\n";
    foreach ($rows as $r) {
        $time = date('H:i', strtotime($r['created_at']));
        $payout = number_format((float)$r['payout'], 2);
        $flag = getCountryFlag($r['country'] ?? '');
        $msg .= "• `{$r['status']}` | \${$payout} | {$r['campaign_name']} {$flag} {$time}\n";
    }

    sendTelegram($token, $chatId, $msg);
}

// Helper: country code to flag emoji
function getCountryFlag($code)
{
    if (strlen($code) !== 2)
        return '';
    $code = strtoupper($code);
    return mb_chr(0x1F1E6 + ord($code[0]) - ord('A')) . mb_chr(0x1F1E6 + ord($code[1]) - ord('A'));
}