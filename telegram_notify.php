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

// Shared digest windows/totals with the panel push digests: both channels
// must agree on the numbers. Loaded unconditionally — sendDailySummary and
// sendPeriodicSummary both call it, and whichever runs first must not decide
// whether the class exists for the other.
require_once __DIR__ . '/core/AdminPushNotify.php';

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

    // Totals and top campaigns come from the SAME shared window helper the
    // panel push digests use (core/AdminPushNotify.php, required at the top
    // of this file), so the two channels can never disagree on a day's
    // numbers. The window is UTC, like the clicks table.
    // The day is the panel owner's local day (profile timezone), the same
    // zone the daily_time picker is read in.
    $dayWindow = AdminPushNotify::digestWindow('daily', time(), AdminPushNotify::panelTimezone($pdo));
    $data = AdminPushNotify::windowStats($pdo, $dayWindow['from'], $dayWindow['to']);

    // Get top campaigns
    $stmt = $pdo->prepare("
        SELECT c.name, SUM(cl.revenue) as revenue, COUNT(*) as clicks, SUM(cl.is_conversion) as conv
        FROM clicks cl JOIN campaigns c ON c.id = cl.campaign_id
        WHERE cl.created_at >= ? AND cl.created_at < ? AND " . AdminPushNotify::safePageSql('cl.') . "
        GROUP BY c.id ORDER BY revenue DESC LIMIT 3
    ");
    $stmt->execute([$dayWindow['from'], $dayWindow['to']]);
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

        $msg = botText($lang, 'daily_summary', ['date' => $dayWindow['label']]) . "\n\n";
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

        // ---- Creatives (the library, этап 1): day's top / worst + burnout.
        // Everything creative_* is optional on purpose: a pre-migration DB or
        // an empty library must never cost the digest its numbers.
        try {
            $stmt = $pdo->prepare("
                SELECT ma.orig_name AS name, (cd.revenue - (cd.spend + cd.fee)) AS profit
                FROM creative_daily cd JOIN media_assets ma ON ma.id = cd.asset_id
                WHERE cd.day = ? AND (cd.spend + cd.fee) > 0
                ORDER BY profit DESC LIMIT 3
            ");
            $stmt->execute([$today]);
            $topCreatives = $stmt->fetchAll();
            $stmt = $pdo->prepare("
                SELECT ma.orig_name AS name, (cd.revenue - (cd.spend + cd.fee)) AS profit
                FROM creative_daily cd JOIN media_assets ma ON ma.id = cd.asset_id
                WHERE cd.day = ? AND (cd.spend + cd.fee) > 0
                ORDER BY profit ASC LIMIT 3
            ");
            $stmt->execute([$today]);
            $worstCreatives = $stmt->fetchAll();

            if (!empty($topCreatives)) {
                $msg .= "\n" . botText($lang, 'daily_top_creatives') . "\n";
                foreach ($topCreatives as $i => $c) {
                    $profit = number_format((float)$c['profit'], 2);
                    $msg .= ($i + 1) . ". " . orbitraTelegramEscape($c['name']) . " — \${$profit}\n";
                }
            }
            if (!empty($worstCreatives)) {
                $msg .= "\n" . botText($lang, 'daily_worst_creatives') . "\n";
                foreach ($worstCreatives as $i => $c) {
                    $profit = number_format((float)$c['profit'], 2);
                    $msg .= ($i + 1) . ". " . orbitraTelegramEscape($c['name']) . " — \${$profit}\n";
                }
            }

            // Burnout: the last 7 days vs the 7 before them. ROI or platform
            // CTR falling harder than the threshold on a still-spending
            // creative is the "swap the creative" signal.
            $dropPct = 50.0;
            $minSpend = 50.0;
            try {
                $stmt = $pdo->query("SELECT value FROM settings WHERE key IN ('telegram_creative_burnout_drop_pct','telegram_creative_burnout_min_spend')");
                foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                    if ($k === 'telegram_creative_burnout_drop_pct') $dropPct = max(1.0, (float)$v);
                    if ($k === 'telegram_creative_burnout_min_spend') $minSpend = (float)$v;
                }
            } catch (\Throwable $e) {
            }
            $w2From = date('Y-m-d', strtotime('-6 days'));
            $w1From = date('Y-m-d', strtotime('-13 days'));
            $w1To = date('Y-m-d', strtotime('-7 days'));
            $stmt = $pdo->prepare("
                SELECT ma.orig_name AS name,
                       SUM(CASE WHEN cd.day >= ? THEN cd.revenue ELSE 0 END) AS rev2,
                       SUM(CASE WHEN cd.day >= ? THEN cd.spend + cd.fee ELSE 0 END) AS spend2,
                       SUM(CASE WHEN cd.day >= ? THEN cd.platform_clicks ELSE 0 END) AS pc2,
                       SUM(CASE WHEN cd.day >= ? THEN cd.impressions ELSE 0 END) AS impr2,
                       SUM(CASE WHEN cd.day <= ? THEN cd.revenue ELSE 0 END) AS rev1,
                       SUM(CASE WHEN cd.day <= ? THEN cd.spend + cd.fee ELSE 0 END) AS spend1,
                       SUM(CASE WHEN cd.day <= ? THEN cd.platform_clicks ELSE 0 END) AS pc1,
                       SUM(CASE WHEN cd.day <= ? THEN cd.impressions ELSE 0 END) AS impr1
                FROM creative_daily cd JOIN media_assets ma ON ma.id = cd.asset_id
                WHERE cd.day >= ? AND cd.day <= ?
                GROUP BY cd.asset_id
            ");
            $stmt->execute([$w2From, $w2From, $w2From, $w2From, $w1To, $w1To, $w1To, $w1To, $w1From, $today]);
            $burnouts = [];
            foreach ($stmt->fetchAll() as $b) {
                if ((float)$b['spend2'] < $minSpend) {
                    continue;
                }
                $roi1 = (float)$b['spend1'] > 0 ? ((float)$b['rev1'] - (float)$b['spend1']) / (float)$b['spend1'] * 100 : null;
                $roi2 = (float)$b['spend2'] > 0 ? ((float)$b['rev2'] - (float)$b['spend2']) / (float)$b['spend2'] * 100 : null;
                $ctr1 = (int)$b['impr1'] > 0 ? (int)$b['pc1'] / (int)$b['impr1'] : null;
                $ctr2 = (int)$b['impr2'] > 0 ? (int)$b['pc2'] / (int)$b['impr2'] : null;
                $roiBurned = $roi1 !== null && $roi2 !== null && $roi1 > 0 && $roi2 < $roi1 * (1 - $dropPct / 100);
                $ctrBurned = $ctr1 !== null && $ctr2 !== null && $ctr1 > 0 && $ctr2 < $ctr1 * (1 - $dropPct / 100);
                if ($roiBurned || $ctrBurned) {
                    $burnouts[] = [
                        'name' => (string)$b['name'],
                        'roi1' => $roi1 !== null ? round($roi1, 1) : null,
                        'roi2' => $roi2 !== null ? round($roi2, 1) : null,
                    ];
                }
            }
            if (!empty($burnouts)) {
                $msg .= "\n";
                foreach (array_slice($burnouts, 0, 5) as $b) {
                    $msg .= botText($lang, 'creative_burnout', [
                        'name' => orbitraTelegramEscape($b['name']),
                        'was' => (string)$b['roi1'],
                        'now' => (string)$b['roi2'],
                    ]) . "\n";
                }
            }
        } catch (\Throwable $creativeDigestError) {
            // creative_daily / media_assets absent (pre-migration DB): the
            // campaigns part above already went out, this section just skips.
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

    $local = (new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(AdminPushNotify::panelTimezone($pdo)));
    if (strcmp($local->format('H:i'), $dailyTime) < 0) {
        return;
    }

    // Atomic claim: the UPDATE only matches when the flag still holds another
    // day, so exactly one caller proceeds even with several crons racing.
    $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES ('telegram_daily_last_sent', '')")->execute();
    $claim = $pdo->prepare("UPDATE settings SET value = ? WHERE key = 'telegram_daily_last_sent' AND value != ?");
    $claim->execute([$local->format('Y-m-d'), $local->format('Y-m-d')]);
    if ($claim->rowCount() === 0) {
        return;
    }

    sendDailySummary($pdo);
}

function sendTelegramNotification($token, $chatId, $text)
{
    sendTelegram($token, $chatId, $text);
}

/**
 * Weekly/monthly digest body. Deliberately leaner than the daily one: totals
 * plus the top-3 campaigns over the window. The creatives section of the
 * daily summary is keyed by single days (creative_daily), so it stays a
 * daily-only feature. Totals come from the shared window helper — the same
 * one the panel push digests use — so Telegram and push never disagree.
 */
function sendPeriodicSummary($pdo, string $period, ?array $onlyChats = null, ?int $now = null)
{
    $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'telegram_bot_token'");
    $token = $stmt ? $stmt->fetchColumn() : '';
    if (!$token) {
        return;
    }

    // The last COMPLETE week/month in the panel owner's timezone — the
    // digest goes out at the start of the next one.
    $window = AdminPushNotify::digestWindow($period, $now ?? time(), AdminPushNotify::panelTimezone($pdo));
    $stats = AdminPushNotify::windowStats($pdo, $window['from'], $window['to']);
    $clicks = (int)$stats['clicks'];
    $conv = (int)$stats['conversions'];
    $rev = number_format((float)$stats['revenue'], 2);
    $costVal = number_format((float)$stats['cost'], 2);
    $profit = number_format((float)$stats['revenue'] - (float)$stats['cost'], 2);

    $topCampaigns = [];
    try {
        $stmt = $pdo->prepare("
            SELECT c.name, SUM(cl.revenue) as revenue
            FROM clicks cl JOIN campaigns c ON c.id = cl.campaign_id
            WHERE cl.created_at >= ? AND cl.created_at < ? AND " . AdminPushNotify::safePageSql('cl.') . "
            GROUP BY c.id ORDER BY revenue DESC LIMIT 3
        ");
        $stmt->execute([$window['from'], $window['to']]);
        $topCampaigns = $stmt->fetchAll();
    } catch (\Throwable $e) {
    }

    $label = $window['label'];

    $chats = $pdo->query(
        "SELECT chat_id, language FROM telegram_bot_chats WHERE notify_{$period} = 1 AND is_active = 1"
    )->fetchAll();
    // The gate below claims each chat before this runs; sending only to the
    // claimed set closes the gap the claim-then-send split otherwise had (a
    // chat subscribed mid-flight would get a digest with no marker, then a
    // second copy from the next cron tick's claim).
    if ($onlyChats !== null) {
        $chats = array_values(array_filter($chats, static fn ($c) => in_array((string)$c['chat_id'], $onlyChats, true)));
    }
    foreach ($chats as $chat) {
        $lang = $chat['language'] ?: 'ru';

        $msg = botText($lang, $period . '_summary', ['date' => $label]) . "\n\n";
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
 * Once-per-window gate for the weekly/monthly digests, safe from every
 * per-minute cron. Unlike the daily claim (one settings row for the whole
 * install) this marks PER CHAT (tg_digest_log, unique (chat, period, key)):
 * the sent-marker doubles as the lock, so racing crons cannot double-send.
 *
 * The digest covers the last COMPLETE week/month, so it goes out at the
 * start of the next one: Monday / the 1st, at the chosen time, in the panel
 * owner's timezone. The key is the CURRENT ISO week / month, so a missed
 * Monday (cron down) catches up on the next tick instead of being lost, and
 * a chat that turns the digest on mid-week gets last week's summary once.
 */
function orbitraTelegramMaybeSendPeriodic($pdo, string $period, ?int $now = null)
{
    if ($period !== 'weekly' && $period !== 'monthly') {
        return;
    }

    $timeKey = 'telegram_' . $period . '_time';
    $dueTime = '21:00';
    try {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$timeKey]);
        $v = (string)$stmt->fetchColumn();
        $stmt->closeCursor();
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
            $dueTime = $v;
        }
    } catch (\Throwable $e) {
    }

    $want = (int)$pdo->query("SELECT COUNT(*) FROM telegram_bot_chats WHERE notify_{$period} = 1 AND is_active = 1")->fetchColumn();
    if ($want === 0) {
        return;
    }

    $now = $now ?? time();
    $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(AdminPushNotify::panelTimezone($pdo)));
    // On the first day of the period wait for the chosen time; any later
    // day of the period is a catch-up and sends right away.
    $firstDay = $period === 'weekly' ? ((int)$local->format('N') === 1) : ((int)$local->format('j') === 1);
    if ($firstDay && strcmp($local->format('H:i'), $dueTime) < 0) {
        return;
    }

    $periodKey = $period === 'weekly' ? $local->format('o-\WW') : $local->format('Y-m');
    $chats = $pdo->query("SELECT chat_id FROM telegram_bot_chats WHERE notify_{$period} = 1 AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
    $claim = $pdo->prepare("INSERT OR IGNORE INTO tg_digest_log (chat_id, period, period_key) VALUES (?, ?, ?)");
    $claimed = [];
    foreach ($chats as $chatId) {
        try {
            $claim->execute([$chatId, $period, $periodKey]);
            if ((int)$pdo->query("SELECT changes()")->fetchColumn() === 0) {
                continue; // already sent in this window
            }
        } catch (\Throwable $e) {
            continue; // pre-migration table: better silent than double-sent
        }
        // Claimed. A chat whose send then fails keeps its marker — a
        // half-failed digest must not re-fire all period long (the same
        // semantics as the push digests).
        $claimed[] = (string)$chatId;
    }
    if (!$claimed) {
        return;
    }

    sendPeriodicSummary($pdo, $period, $claimed, $now);
}
