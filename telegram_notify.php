<?php
/**
 * Telegram Bot Notification Helper
 * Call notifyConversion() after a conversion is recorded to alert subscribed chats
 * Call sendDailySummary() via cron for daily report
 *
 * Message wording lives in telegram_bot.php's botText() — one translation
 * table for every string the bot says. This file defines the webhook guard
 * before that require because postback.php pulls it in mid-request: without
 * the constant, telegram_bot.php would run its webhook body and try to read
 * php://input out from under the caller.
 */

if (!function_exists('botText')) {
    if (!defined('ORBITRA_TELEGRAM_NO_WEBHOOK')) {
        define('ORBITRA_TELEGRAM_NO_WEBHOOK', true);
    }
    require_once __DIR__ . '/telegram_bot.php';
}

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
    $campaignName = orbitraTelegramEscape((string)($stmt->fetchColumn() ?: "ID: {$campaignId}"));

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
        $msg = botText($lang, 'new_conversion', [
            'campaign' => $campaignName,
            'status' => $status,
            'payout' => $payout,
            'currency' => $currency,
            'country' => trim($flag . ' ' . $country),
            'time' => $time,
        ]);
        sendTelegramNotification($token, $chat['chat_id'], $msg);
    }
}

/**
 * The daily summary the /daily toggle promises. Called once a day from the
 * poller cron (see orbitraTelegramMaybeSendDaily for the once-a-day claim);
 * keyed to each chat's own language.
 */
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

        $msg = botText($lang, 'daily_summary', ['date' => $today]) . "\n\n";
        $msg .= "👆 " . botText($lang, 'clicks') . ": *{$clicks}*\n";
        $msg .= "🎯 " . botText($lang, 'conversions') . ": *{$conv}*\n";
        $msg .= "💰 " . botText($lang, 'revenue') . ": *\${$rev}*\n";
        $msg .= "💸 " . botText($lang, 'cost') . ": *\${$costVal}*\n";
        $msg .= "📈 " . botText($lang, 'profit') . ": *\${$profit}*\n";

        if (!empty($topCampaigns)) {
            $msg .= "\n" . botText($lang, 'daily_top') . "\n";
            $medals = ['🥇', '🥈', '🥉'];
            foreach ($topCampaigns as $i => $tc) {
                $tcRev = number_format((float)$tc['revenue'], 2);
                $msg .= "{$medals[$i]} " . orbitraTelegramEscape($tc['name']) . " — \${$tcRev}\n";
            }
        }

        sendTelegramNotification($token, $chat['chat_id'], $msg);
    }
}

/**
 * Once-a-day gate for the daily summary, safe to call from every per-minute
 * cron: the day is claimed with an UPDATE that only one process can win, so
 * the poller and any other cron can race on this without double-sending.
 *
 * Uses the server's PHP timezone — the same clock the panel's daily_time
 * picker writes against. A chat that enables /daily after the time has
 * already passed simply starts receiving from the next day.
 */
function orbitraTelegramMaybeSendDaily($pdo)
{
    $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'telegram_daily_time'");
    $dailyTime = $stmt ? (string)$stmt->fetchColumn() : '';
    if ($stmt) {
        $stmt->closeCursor();
    }
    if ($dailyTime === '') {
        $dailyTime = '21:00';
    }

    // Nobody subscribed: nothing to claim and nothing to send.
    $want = (int)$pdo->query("SELECT COUNT(*) FROM telegram_bot_chats WHERE notify_daily = 1 AND is_active = 1")->fetchColumn();
    if ($want === 0) {
        return;
    }

    if (strcmp(date('H:i'), $dailyTime) < 0) {
        return;
    }

    // Atomic claim: the UPDATE only matches when the flag still holds another
    // day, so exactly one caller proceeds even with several crons racing.
    $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES ('telegram_daily_last_sent', '')")->execute();
    $claim = $pdo->prepare("UPDATE settings SET value = ? WHERE key = 'telegram_daily_last_sent' AND value != ?");
    $claim->execute([date('Y-m-d'), date('Y-m-d')]);
    if ($claim->rowCount() === 0) {
        return;
    }

    sendDailySummary($pdo);
}

function sendTelegramNotification($token, $chatId, $text)
{
    sendTelegram($token, $chatId, $text);
}
