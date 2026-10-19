<?php
/**
 * Long-polling worker for the Telegram AD-FUNNEL bots (tg_funnel_bots).
 *
 * Separate from telegram_poll_cron.php on purpose: that one serves the admin
 * bot (statistics for admitted chats); this one serves bots that strangers
 * from an ad talk to. Different tokens, different process, different tables.
 *
 * All active funnel bots are long-polled IN PARALLEL (curl_multi), so one slow
 * or broken bot never delays another's /start answer. One pass polls for ~50s
 * and exits; cron runs it every minute:
 *
 *   * * * * * php /var/www/orbitra/cli/tg_funnel_poll_cron.php --quiet >> var/logs/tg_funnel_poll.log 2>&1
 *
 * Exits at once when no funnel bot exists, so the cron line is free on
 * installs that never use the funnel.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/TelegramFunnel.php';

$argv = $argv ?? [];
$quiet = in_array('--quiet', $argv, true);
$once = in_array('--once', $argv, true);
$apiBase = getenv('ORBITRA_TG_API_BASE') ?: 'https://api.telegram.org'; // tests point this at a stub

$log = function (string $msg) use ($quiet): void {
    if (!$quiet) {
        echo '[' . date('Y-m-d H:i:s') . "] {$msg}\n";
    }
};

try {
    $bots = $pdo->query("SELECT * FROM tg_funnel_bots WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $bots = []; // pre-migration database
}
if (!$bots) {
    exit(0);
}

$lockDir = __DIR__ . '/../var/locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lock = fopen($lockDir . '/tg_funnel_poll.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log('another funnel poller holds the lock, exiting');
    exit(0);
}

$saveOffset = $pdo->prepare("UPDATE tg_funnel_bots SET poll_offset = ? WHERE id = ?");
$stamp = $pdo->prepare("UPDATE tg_funnel_bots SET last_poll_at = datetime('now'), last_error = ? WHERE id = ?");
$markCleared = $pdo->prepare("UPDATE tg_funnel_bots SET webhook_cleared = ? WHERE id = ?");

// getUpdates and a webhook are mutually exclusive (409 forever otherwise).
foreach ($bots as &$bot) {
    if ((int) $bot['webhook_cleared'] !== 1) {
        $del = TelegramFunnel::api((string) $bot['token'], 'deleteWebhook', ['drop_pending_updates' => false]);
        if ($del['ok'] ?? false) {
            $markCleared->execute([1, (int) $bot['id']]);
            $bot['webhook_cleared'] = 1;
        }
    }
}
unset($bot);

$deadline = time() + ($once ? 0 : 50);
$handled = 0;

do {
    $wait = $once ? 0 : max(1, min(25, $deadline - time()));
    $multi = curl_multi_init();
    $handles = [];
    foreach ($bots as $i => $bot) {
        $params = ['timeout' => $wait, 'allowed_updates' => TelegramFunnel::ALLOWED_UPDATES];
        if ((int) $bot['poll_offset'] > 0) {
            $params['offset'] = (int) $bot['poll_offset']; // first call omits it: Telegram rejects null
        }
        $ch = curl_init(rtrim($apiBase, '/') . '/bot' . $bot['token'] . '/getUpdates');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $wait + 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$i] = $ch;
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    foreach ($handles as $i => $ch) {
        $bot = &$bots[$i];
        $body = curl_multi_getcontent($ch);
        $curlErr = curl_error($ch);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);

        $res = is_string($body) && $body !== '' ? json_decode($body, true) : null;
        if (!is_array($res)) {
            $stamp->execute(['network error contacting api.telegram.org' . ($curlErr !== '' ? ': ' . $curlErr : ''), (int) $bot['id']]);
            unset($bot);
            continue;
        }
        if (!($res['ok'] ?? false)) {
            $desc = (string) ($res['description'] ?? 'unknown error');
            $stamp->execute([mb_substr($desc, 0, 300), (int) $bot['id']]);
            if ((int) ($res['error_code'] ?? 0) === 409) {
                $markCleared->execute([0, (int) $bot['id']]); // next tick deletes the webhook again
            }
            $log("bot {$bot['id']}: getUpdates failed: {$desc}");
            unset($bot);
            continue;
        }
        $stamp->execute(['', (int) $bot['id']]);
        foreach (($res['result'] ?? []) as $update) {
            // Offset BEFORE handling: an update that throws must not replay forever.
            $bot['poll_offset'] = max((int) $bot['poll_offset'], (int) ($update['update_id'] ?? 0) + 1);
            $saveOffset->execute([(int) $bot['poll_offset'], (int) $bot['id']]);
            try {
                if (TelegramFunnel::processUpdate($pdo, $bot, $update)) {
                    $handled++;
                }
            } catch (\Throwable $e) {
                $log("bot {$bot['id']}: handler error on update " . ($update['update_id'] ?? '?') . ': ' . $e->getMessage());
            }
        }
        unset($bot);
    }
    curl_multi_close($multi);
} while (!$once && time() < $deadline);

$log("done, {$handled} update(s) handled across " . count($bots) . ' bot(s)');
flock($lock, LOCK_UN);
fclose($lock);
exit(0);
