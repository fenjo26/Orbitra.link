<?php
/**
 * Shared Telegram transport for the panel and bot handlers.
 * No database or webhook bootstrap: callers can require this in either order.
 */
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
