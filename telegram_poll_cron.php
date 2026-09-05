<?php
/**
 * Telegram long-polling worker.
 *
 * Telegram will only call a webhook back over HTTPS with a valid certificate on
 * a real domain. A fresh Orbitra install is reached at http://<server-ip>/admin.php,
 * so setWebhook is refused there ("HTTPS url must be provided for webhook") and
 * not a single /start ever arrives — the panel showed "no chats connected" with
 * no way to fix it short of buying a domain. Polling needs no inbound
 * connection at all, so the bot works on a bare IP, behind a proxy, anywhere
 * outbound HTTPS works.
 *
 * Run every minute from cron; one pass long-polls for most of that minute and
 * exits, so a missed tick costs one minute of latency and nothing else:
 *
 *   * * * * * php /var/www/orbitra/telegram_poll_cron.php --quiet >> var/logs/telegram_poll.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ORBITRA_TELEGRAM_NO_WEBHOOK', true);
require_once __DIR__ . '/telegram_bot.php';

$quiet = in_array('--quiet', $argv, true);
$once = in_array('--once', $argv, true);

function tgPollLog(string $msg): void
{
    global $quiet;
    if (!$quiet) {
        echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
    }
}

function tgPollSetting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val === false ? $default : (string)$val;
}

function tgPollSave(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)")->execute([$key, $value]);
}

/** One Telegram Bot API call. Returns the decoded body, or null on transport failure. */
function tgPollApi(string $token, string $method, array $params = [], int $timeout = 40): ?array
{
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    $body = curl_exec($ch);
    if ($body === false) {
        tgPollLog("curl error on {$method}: " . curl_error($ch));
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

// --- Mode gate -------------------------------------------------------------
// 'webhook' is the default so an install that already works keeps working; the
// panel writes 'polling' when setWebhook could not be used.
$mode = tgPollSetting($pdo, 'telegram_mode', 'webhook');
if ($mode !== 'polling') {
    tgPollLog("mode is '{$mode}', nothing to poll");
    exit(0);
}

$token = tgPollSetting($pdo, 'telegram_bot_token', '');
if (!$token) {
    tgPollLog('no bot token configured');
    exit(0);
}

// --- Single-flight lock ----------------------------------------------------
// Two pollers on the same token make Telegram return 409 Conflict and updates
// get delivered to whichever process asked last. flock() releases on exit,
// including a fatal, so a crashed pass never wedges the next tick.
$lockDir = __DIR__ . '/var/locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lockFile = $lockDir . '/telegram_poll.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    tgPollLog('another poller holds the lock, exiting');
    exit(0);
}

// --- getUpdates and setWebhook are mutually exclusive ----------------------
// While a webhook is registered Telegram answers getUpdates with 409, forever.
// Clear it once, and record that we did so the panel can explain the mode.
if (tgPollSetting($pdo, 'telegram_webhook_cleared', '0') !== '1') {
    $del = tgPollApi($token, 'deleteWebhook', ['drop_pending_updates' => false], 15);
    if ($del && ($del['ok'] ?? false)) {
        tgPollSave($pdo, 'telegram_webhook_cleared', '1');
        tgPollSave($pdo, 'telegram_webhook_set', '0');
        tgPollLog('webhook cleared, switching to polling');
    }
}

$offset = (int)tgPollSetting($pdo, 'telegram_poll_offset', '0');

// Long-poll for most of the minute, then let cron start a fresh process. A
// resident daemon would need its own supervision; this borrows cron's.
$deadline = time() + ($once ? 0 : 50);
$handled = 0;

do {
    $remaining = $deadline - time();
    $waitFor = $once ? 0 : max(1, min(25, $remaining));

    // Send `offset` only once we have one — Telegram rejects a null, and the
    // first ever call is meant to omit it entirely.
    $params = ['timeout' => $waitFor, 'allowed_updates' => ['message']];
    if ($offset > 0) {
        $params['offset'] = $offset;
    }

    $res = tgPollApi($token, 'getUpdates', $params, $waitFor + 15);

    if ($res === null) {
        tgPollSave($pdo, 'telegram_poll_last_error', 'network error contacting api.telegram.org');
        break;
    }

    if (!($res['ok'] ?? false)) {
        $desc = $res['description'] ?? 'unknown error';
        tgPollSave($pdo, 'telegram_poll_last_error', $desc);
        tgPollLog("getUpdates failed: {$desc}");
        // 409 means a webhook is still registered — clear it and let the next
        // tick retry rather than hammering the API for the rest of the minute.
        if ((int)($res['error_code'] ?? 0) === 409) {
            tgPollSave($pdo, 'telegram_webhook_cleared', '0');
        }
        break;
    }

    tgPollSave($pdo, 'telegram_poll_last_error', '');

    $updates = $res['result'] ?? [];
    foreach ($updates as $update) {
        // Advance the offset before handling. Telegram redelivers everything at
        // or after `offset` until it moves, so a single update that throws would
        // otherwise be replayed on every tick forever.
        $offset = max($offset, (int)($update['update_id'] ?? 0) + 1);
        tgPollSave($pdo, 'telegram_poll_offset', (string)$offset);

        try {
            if (orbitraTelegramProcessUpdate($pdo, $token, $update)) {
                $handled++;
            }
        } catch (Throwable $e) {
            tgPollLog('handler error on update ' . ($update['update_id'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    tgPollSave($pdo, 'telegram_poll_last_run', date('Y-m-d H:i:s'));
} while (!$once && time() < $deadline);

tgPollSave($pdo, 'telegram_poll_last_run', date('Y-m-d H:i:s'));
tgPollLog("done, {$handled} update(s) handled, offset {$offset}");

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
