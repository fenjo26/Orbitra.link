<?php
/**
 * Conversion notifications fire once per EVENT, not once per postback.
 *
 * Field report (2026-10-24): every conversion arrived in Telegram twice, as two
 * identical messages. postback.php called notifyConversion() (and the channel
 * rules and the panel push) on every postback, so a network retry — or a
 * thank-you pixel plus the S2S postback reporting the same conversion — pinged
 * the operator again with the same text.
 *
 * Now: a new conversion or a status change notifies; a repeat with the same
 * status is still recorded, but stays silent.
 *
 * REAL HTTP test: a PHP server runs postback.php, and a second PHP server stands
 * in for api.telegram.org and logs every sendMessage it receives.
 *
 * Run with: php tests/postback_notify_dedupe_test.php
 */

require_once __DIR__ . '/lib/http.php';

$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

// --- Telegram stand-in -------------------------------------------------------
$stubDir = sys_get_temp_dir() . '/orbitra_tg_stub_' . bin2hex(random_bytes(4));
mkdir($stubDir, 0700, true);
$stubLog = $stubDir . '/log.txt';
file_put_contents($stubDir . '/stub.php', '<?php
file_put_contents(__DIR__ . "/log.txt", $_SERVER["REQUEST_URI"] . "\n", FILE_APPEND | LOCK_EX);
header("Content-Type: application/json");
echo "{\"ok\":true,\"result\":{\"message_id\":1}}";
');
$sock = stream_socket_server('tcp://127.0.0.1:0');
$stubPort = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$stub = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $stubPort, $stubDir . '/stub.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $stubPipes
);
usleep(300000);

$sends = static function () use ($stubLog): int {
    if (!is_file($stubLog)) {
        return 0;
    }
    return substr_count((string) file_get_contents($stubLog), 'sendMessage');
};
$resetSends = static function () use ($stubLog): void {
    @unlink($stubLog);
};

$harness = new OrbitraTestHarness(__DIR__ . '/..');
try {
    $harness->start();
    $seed = $harness->seedTestData();
    $key = $seed['postback_key'];
    $clickId = $seed['click_id'];

    // Point the server's copy of the shared Telegram transport at the stub.
    $apiFile = $harness->getWorkingDir() . '/core/telegram_api.php';
    $src = (string) file_get_contents($apiFile);
    $check('telegram transport has the api.telegram.org base to redirect', strpos($src, 'https://api.telegram.org') !== false);
    file_put_contents($apiFile, str_replace('https://api.telegram.org', 'http://127.0.0.1:' . $stubPort, $src));

    $harness->setSetting('telegram_bot_token', 'TESTTOKEN');
    $harness->setSetting('telegram_notify_conversions', '1');
    $harness->getPdo()->exec("INSERT OR IGNORE INTO telegram_bot_chats (chat_id, username, first_name, notify_conversions, is_active) VALUES ('111', 't', 't', 1, 1)");

    $pb = static function (string $query) use ($harness, $key, $clickId): int {
        return (int) $harness->get("/{$key}/postback?subid={$clickId}&{$query}")['code'];
    };

    // 1. The same postback twice: recorded, announced once.
    $resetSends();
    $pb('status=lead&payout=10');
    $pb('status=lead&payout=10');
    $check('identical postback twice → one Telegram message', $sends() === 1);

    // 2. A real status change is a new event.
    $resetSends();
    $pb('status=sale&payout=10');
    $check('lead → sale → one Telegram message', $sends() === 1);

    // 3. Repeat of the current status stays silent, but is still stored.
    $resetSends();
    $code = $pb('status=sale&payout=12');
    $check('repeat sale → no Telegram message', $sends() === 0);
    $check('repeat sale still answered 200', $code === 200);
    $payout = (float) $harness->getPdo()->query("SELECT payout FROM conversions WHERE click_id = " . $harness->getPdo()->quote($clickId) . " AND tid IS NULL")->fetchColumn();
    $check('repeat sale still updated the stored payout', abs($payout - 12.0) < 0.001);

    // 4. tid: each distinct tid is its own conversion; a resent tid is not.
    $resetSends();
    $pb('status=deposit&payout=5&tid=t1');
    $pb('status=deposit&payout=5&tid=t1');
    $pb('status=deposit&payout=7&tid=t2');
    $check('tid t1 twice + tid t2 → two Telegram messages', $sends() === 2);

    // 5. The incoming log tells a first postback from a repeat (it used to say
    //    "updated" for every fresh tid insert).
    $results = $harness->getPdo()->query("SELECT result FROM incoming_postbacks_log ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
    $check('log: t2 recorded, t1 repeat updated, t1 first recorded', $results === ['recorded', 'updated', 'recorded']);
} finally {
    $harness->stop();
    if (is_resource($stub)) {
        proc_terminate($stub);
        proc_close($stub);
    }
    @unlink($stubLog);
    @unlink($stubDir . '/stub.php');
    @rmdir($stubDir);
}

if ($failures > 0) {
    echo "FAILED: {$failures} check(s)\n";
    exit(1);
}
echo "All checks passed\n";
