<?php
/**
 * Shared Telegram transport for the panel and bot handlers.
 * No database or webhook bootstrap: callers can require this in either order.
 */
/**
 * Give a webhook that was registered without a secret_token one (audit #5).
 *
 * Installs connected before secrets existed have a live webhook Telegram
 * calls unsigned, and telegram_bot.php can only verify what it knows. This
 * reads the registered URL back from getWebhookInfo, re-registers it with a
 * fresh secret and stores the secret only after Telegram accepted it — a
 * failed call changes nothing. Called by the poll cron every minute and by
 * the webhook itself on its first unsigned delivery.
 *
 * @return bool true when a secret is (now) in place
 */
function orbitraTelegramEnsureWebhookSecret(PDO $pdo, string $token): bool
{
    if ($token === '') {
        return false;
    }
    try {
        $get = static function (string $key) use ($pdo): string {
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
            $stmt->execute([$key]);
            $v = $stmt->fetchColumn();
            $stmt->closeCursor();
            return $v === false ? '' : (string) $v;
        };
        if ($get('telegram_webhook_secret') !== '') {
            return true;
        }
        $mode = $get('telegram_mode');
        if ($mode !== '' && $mode !== 'webhook') {
            return false;
        }
        $info = orbitraTelegramApi($token, 'getWebhookInfo', [], 8);
        $url = (string) ($info['result']['url'] ?? '');
        if (!($info['ok'] ?? false) || $url === '') {
            return false;
        }
        $secret = bin2hex(random_bytes(32));
        $set = orbitraTelegramApi($token, 'setWebhook', [
            'url' => $url,
            'allowed_updates' => ['message', 'callback_query'],
            'secret_token' => $secret,
        ], 15);
        if (!($set['ok'] ?? false)) {
            return false;
        }
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('telegram_webhook_secret', ?)")->execute([$secret]);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function orbitraTelegramApi(string $token, string $method, array $params = [], int $timeout = 10): ?array
{
    // Keep the bot suite's no-network outbox available to every caller.
    if (defined('ORBITRA_TELEGRAM_TEST_OUTBOX')) {
        $GLOBALS['orbitra_telegram_outbox'][] = ['method' => $method, 'params' => $params];
        return ['ok' => true, 'result' => true];
    }

    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    if ($params) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    $body = curl_exec($ch);
    if ($body === false) {
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}
