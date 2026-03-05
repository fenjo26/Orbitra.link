<?php
/**
 * Telegram Bot Notification Helper
 * Call notifyConversion() after a conversion is recorded to alert subscribed chats
 * Call sendDailySummary() via cron for daily report
 */

function notifyConversion($pdo, $clickId, $status, $payout, $campaignId, $currency = 'USD')
{
    // Check if notifications are enabled globally
    $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'telegram_notify_conversions'");
    $notifyEnabled = $stmt ? $stmt->fetchColumn() : '0';
    if ($notifyEnabled !== '1')
        return;

    // Get bot token
    $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'telegram_bot_token'");
    $token = $stmt ? $stmt->fetchColumn() : '';
    if (!$token)
        return;

    // Get campaign name
    $stmt = $pdo->prepare("SELECT name FROM campaigns WHERE id = ?");
    $stmt->execute([$campaignId]);
    $campaignName = $stmt->fetchColumn() ?: "ID: {$campaignId}";

    // Get country from click
    $stmt = $pdo->prepare("SELECT country FROM clicks WHERE id = ?");
    $stmt->execute([$clickId]);
    $country = $stmt->fetchColumn() ?: '??';

    // Get flag
    $flag = '';
    if (strlen($country) === 2) {
        $country = strtoupper($country);
        $flag = mb_chr(0x1F1E6 + ord($country[0]) - ord('A')) . mb_chr(0x1F1E6 + ord($country[1]) - ord('A'));
    }

    $time = date('H:i:s');

    // Get all chats with notifications enabled
    $stmt = $pdo->query("SELECT chat_id, language FROM telegram_bot_chats WHERE notify_conversions = 1 AND is_active = 1");
    $chats = $stmt->fetchAll();

    foreach ($chats as $chat) {
        $lang = $chat['language'] ?: 'ru';
        $texts = [
            'ru' => "🔔 *Новая конверсия!*\n\n📊 Кампания: *{$campaignName}*\n📌 Статус: `{$status}`\n💰 Сумма: *{$payout} {$currency}*\n🌍 Страна: {$flag} {$country}\n🕐 Время: {$time}",
            'en' => "🔔 *New Conversion!*\n\n📊 Campaign: *{$campaignName}*\n📌 Status: `{$status}`\n💰 Amount: *{$payout} {$currency}*\n🌍 Country: {$flag} {$country}\n🕐 Time: {$time}"
        ];

        $msg = $texts[$lang] ?? $texts['en'];
        sendTelegramNotification($token, $chat['chat_id'], $msg);
    }
}

function sendDailySummary($pdo)
{
    // Get bot token
    $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'telegram_bot_token'");
    $token = $stmt ? $stmt->fetchColumn() : '';
    if (!$token)
        return;

    $today = date('Y-m-d');

    // Get today's stats
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as clicks,
            COUNT(DISTINCT ip) as unique_clicks,
            SUM(is_conversion) as conversions,
            SUM(revenue) as revenue,
            SUM(cost) as cost
        FROM clicks 
        WHERE DATE(created_at) = ?
    ");
    $stmt->execute([$today]);
    $data = $stmt->fetch();

    // Get top campaigns
    $stmt = $pdo->prepare("
        SELECT c.name, SUM(cl.revenue) as revenue, COUNT(*) as clicks, SUM(cl.is_conversion) as conv
        FROM clicks cl JOIN campaigns c ON c.id = cl.campaign_id
        WHERE DATE(cl.created_at) = ?
        GROUP BY c.id ORDER BY revenue DESC LIMIT 3
    ");
    $stmt->execute([$today]);
    $topCampaigns = $stmt->fetchAll();

    $clicks = (int)$data['clicks'];
    $conv = (int)$data['conversions'];
    $rev = number_format((float)$data['revenue'], 2);
    $costVal = number_format((float)$data['cost'], 2);
    $profit = number_format((float)$data['revenue'] - (float)$data['cost'], 2);

    // Get all chats with daily enabled
    $stmt = $pdo->query("SELECT chat_id, language FROM telegram_bot_chats WHERE notify_daily = 1 AND is_active = 1");
    $chats = $stmt->fetchAll();

    foreach ($chats as $chat) {
        $lang = $chat['language'] ?: 'ru';

        if ($lang === 'ru') {
            $msg = "📊 *Ежедневная сводка — {$today}*\n\n";
            $msg .= "👆 Кликов: *{$clicks}*\n";
            $msg .= "🎯 Конверсий: *{$conv}*\n";
            $msg .= "💰 Доход: *\${$rev}*\n";
            $msg .= "💸 Расход: *\${$costVal}*\n";
            $msg .= "📈 Профит: *\${$profit}*\n";
        }
        else {
            $msg = "📊 *Daily Summary — {$today}*\n\n";
            $msg .= "👆 Clicks: *{$clicks}*\n";
            $msg .= "🎯 Conversions: *{$conv}*\n";
            $msg .= "💰 Revenue: *\${$rev}*\n";
            $msg .= "💸 Cost: *\${$costVal}*\n";
            $msg .= "📈 Profit: *\${$profit}*\n";
        }

        if (!empty($topCampaigns)) {
            $msg .= "\n🏆 " . ($lang === 'ru' ? 'ТОП кампании:' : 'Top campaigns:') . "\n";
            $medals = ['🥇', '🥈', '🥉'];
            foreach ($topCampaigns as $i => $tc) {
                $tcRev = number_format((float)$tc['revenue'], 2);
                $msg .= "{$medals[$i]} {$tc['name']} — \${$tcRev}\n";
            }
        }

        sendTelegramNotification($token, $chat['chat_id'], $msg);
    }
}

function sendTelegramNotification($token, $chatId, $text)
{
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}