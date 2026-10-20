<?php
// tests/admin_push_test.php
//
// AdminPushNotify — web push to the operator's own devices (the panel PWA).
// Covers the subscriber upsert, settings validation, the conversion dispatch
// filters (statuses / campaigns / enabled), dead-endpoint aging, and the
// digest gate (due-time check + once-per-period sent marker).
//
// Delivery never touches the network: a subclass overrides the protected
// deliver() seam and scripts per-call responses.
//
// Run: php tests/admin_push_test.php

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/core/AdminPushNotify.php';

$failures = 0;
$check = static function (string $label, $expected, $actual) use (&$failures): void {
    $ok = $expected === $actual;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label;
    if (!$ok) {
        echo ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
        $failures++;
    }
    echo "\n";
};

/** Scriptable stand-in for the HTTP delivery seam. */
class CapturingNotifier extends AdminPushNotify
{
    /** @var array every deliver() call: [device, message] */
    public static array $calls = [];
    /** @var array|null queued response for the next call (defaults to ok) */
    public static ?array $script = null;

    protected static function deliver(PDO $pdo, array $device, array $message): array
    {
        self::$calls[] = ['device' => $device, 'message' => $message];
        $res = self::$script ?? ['ok' => true, 'code' => 201, 'dead' => false, 'error' => null];
        self::$script = null;
        return $res;
    }

    public static function reset(): void
    {
        self::$calls = [];
        self::$script = null;
    }
}

// --- sandbox -----------------------------------------------------------------

$tmp = sys_get_temp_dir() . '/orbitra_admin_push_' . getmypid();
@mkdir($tmp, 0775, true);
$pdo = new PDO('sqlite:' . $tmp . '/db.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout = 2000;');
foreach ([
    "CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, language TEXT DEFAULT 'en', timezone TEXT DEFAULT 'UTC')",
    "CREATE TABLE campaigns (id INTEGER PRIMARY KEY, name TEXT)",
    "CREATE TABLE offers (id INTEGER PRIMARY KEY, name TEXT)",
    "CREATE TABLE clicks (id TEXT PRIMARY KEY, offer_id INTEGER, country_code TEXT, campaign_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, is_conversion INTEGER DEFAULT 0, revenue REAL DEFAULT 0, cost REAL DEFAULT 0)",
    "CREATE TABLE admin_push_subs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        endpoint TEXT NOT NULL UNIQUE,
        p256dh TEXT NOT NULL,
        auth TEXT NOT NULL,
        user_agent TEXT NOT NULL DEFAULT '',
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME
    )",
    "CREATE TABLE admin_push_settings (
        user_id INTEGER PRIMARY KEY,
        conv_enabled INTEGER NOT NULL DEFAULT 1,
        conv_statuses TEXT NOT NULL DEFAULT '',
        conv_campaigns TEXT NOT NULL DEFAULT '',
        digest_daily INTEGER NOT NULL DEFAULT 0,
        digest_weekly INTEGER NOT NULL DEFAULT 0,
        digest_monthly INTEGER NOT NULL DEFAULT 0,
        digest_time TEXT NOT NULL DEFAULT '21:00',
        updated_at DATETIME
    )",
    "CREATE TABLE admin_push_digest_log (
        user_id INTEGER NOT NULL,
        period TEXT NOT NULL,
        period_key TEXT NOT NULL,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, period, period_key)
    )",
] as $sql) {
    $pdo->exec($sql);
}
register_shutdown_function(static function () use ($tmp): void {
    @unlink($tmp . '/db.sqlite');
    @rmdir($tmp);
});

$mkUser = static function (string $name, string $lang = 'en', string $tz = 'UTC') use ($pdo): int {
    $pdo->prepare("INSERT INTO users (username, language, timezone) VALUES (?, ?, ?)")->execute([$name, $lang, $tz]);
    return (int) $pdo->lastInsertId();
};
$sub = static function (int $userId, string $ep = 'https://fcm.example/ep') use ($pdo): array {
    return CapturingNotifier::subscribe($pdo, $userId, [
        'endpoint' => $ep,
        'keys' => ['p256dh' => 'BKey', 'auth' => 'AKey'],
        'user_agent' => 'TestAgent/1.0',
    ]);
};

// --- subscribe ----------------------------------------------------------------

echo "subscribe\n";
$u1 = $mkUser('alice');
$u2 = $mkUser('bob');

$r1 = $sub($u1);
$check('subscribe returns an id', true, $r1['id'] > 0);
$sub($u1); // same user, same endpoint
$check('re-subscribe upserts instead of duplicating', 1, (int) $pdo->query("SELECT COUNT(*) FROM admin_push_subs")->fetchColumn());
$r2 = $sub($u2); // same endpoint, another user
$check('another user on the same endpoint rebinds the row', $u2, (int) $pdo->query("SELECT user_id FROM admin_push_subs")->fetchColumn());
$check('rebind keeps one row', 1, (int) $pdo->query("SELECT COUNT(*) FROM admin_push_subs")->fetchColumn());

$bad = CapturingNotifier::subscribe($pdo, $u1, ['endpoint' => 'http://insecure.example/ep', 'keys' => ['p256dh' => 'x', 'auth' => 'y']]);
$check('a non-https endpoint is refused', true, isset($bad['error']));
$bad = CapturingNotifier::subscribe($pdo, $u1, ['endpoint' => 'https://ok.example/ep']);
$check('missing keys are refused', true, isset($bad['error']));

// --- settings ------------------------------------------------------------------

echo "\nsettings\n";
$s = CapturingNotifier::saveSettings($pdo, $u1, [
    'conv_statuses' => ['sale', 'deposit', 'bogus'],
    'conv_campaigns' => '7, 12, x, 7',
    'digest_time' => '09:30',
    'digest_daily' => 1,
]);
$check('unknown statuses are dropped from the filter', 'sale,deposit', $s['conv_statuses']);
$check('campaign ids are deduped and junk dropped', '7,12', $s['conv_campaigns']);
$check('digest time accepted', '09:30', $s['digest_time']);
$check('daily digest flagged', 1, $s['digest_daily']);
$s = CapturingNotifier::saveSettings($pdo, $u1, ['digest_time' => '25:99']);
$check('an out-of-range digest time keeps the stored one', '09:30', $s['digest_time']);
$s = CapturingNotifier::saveSettings($pdo, $u1, ['conv_enabled' => 0]);
$check('absent keys keep their stored value (absent = keep)', 'sale,deposit', $s['conv_statuses']);
CapturingNotifier::saveSettings($pdo, $u1, ['conv_enabled' => 1]);

// --- dispatch filters ------------------------------------------------------------

echo "\nconversion dispatch\n";
$pdo->exec("INSERT INTO campaigns (id, name) VALUES (5, 'FB GR Casino'), (9, 'TT Slots')");
$pdo->exec("INSERT INTO offers (id, name) VALUES (3, 'Casino GR')");
$pdo->prepare("INSERT INTO clicks (id, offer_id, country_code, campaign_id) VALUES ('cid1', 3, 'GR', 5)")->execute();

// Fresh users/devices for the filter matrix (the rebound row belongs to bob).
$pdo->exec("DELETE FROM admin_push_subs");
$ua = $mkUser('filter-all'); // no filters
$ub = $mkUser('filter-sale'); // statuses = sale only
$uc = $mkUser('filter-camp9'); // campaigns = 9 only
$ud = $mkUser('filter-off'); // conv_enabled = 0
$sub($ua, 'https://fcm.example/a');
$sub($ub, 'https://fcm.example/b');
$sub($uc, 'https://fcm.example/c');
$sub($ud, 'https://fcm.example/d');
CapturingNotifier::saveSettings($pdo, $ub, ['conv_statuses' => ['sale']]);
CapturingNotifier::saveSettings($pdo, $uc, ['conv_campaigns' => '9']);
CapturingNotifier::saveSettings($pdo, $ud, ['conv_enabled' => 0]);

CapturingNotifier::reset();
$sent = CapturingNotifier::dispatch($pdo, 'cid1', 'deposit', 120.0, 'USD', 5);
$check('deposit reaches the unfiltered user only (sale-only and camp9 skip)', 1, $sent);
$check('the notification carries the status and payout', true, strpos(CapturingNotifier::$calls[0]['message']['title'], 'DEPOSIT') !== false);
$check('the notification carries campaign and offer names', true, strpos(CapturingNotifier::$calls[0]['message']['text'], 'FB GR Casino') !== false && strpos(CapturingNotifier::$calls[0]['message']['text'], 'Casino GR') !== false);
$check('the flag emoji doubles as the country', true, strpos(CapturingNotifier::$calls[0]['message']['title'], "\u{1F1EC}\u{1F1F7}") !== false);

CapturingNotifier::reset();
$sent = CapturingNotifier::dispatch($pdo, 'cid1', 'sale', 80.0, 'USD', 5);
$check('sale reaches both the unfiltered and sale-only users', 2, $sent);

CapturingNotifier::reset();
$sent = CapturingNotifier::dispatch($pdo, 'cid1', 'sale', 80.0, 'USD', 9);
$check('sale on campaign 9 matches all three filter users', 3, $sent);
$tags = array_map(static fn ($c) => $c['message']['tag'] ?? '', CapturingNotifier::$calls);
$check('the conversion tag is pinned to click + status', true, strpos($tags[0] ?? '', 'orbitra-conv-cid1-sale') === 0);
$check('a conversion tap-through targets the conversions view', true,
    array_reduce(CapturingNotifier::$calls, static fn ($acc, $c) => $acc && ($c['message']['link_url'] === '?tab=conversions'), true));
// A different conversion must NOT share the tray slot with the previous one
// (five conversions in a row = five notifications, not one replacing the rest).
$pdo->prepare("INSERT INTO clicks (id, offer_id, country_code, campaign_id) VALUES ('cid2', 3, 'GR', 5)")->execute();
CapturingNotifier::reset();
CapturingNotifier::dispatch($pdo, 'cid2', 'sale', 10.0, 'USD', 5);
$tag2 = CapturingNotifier::$calls[0]['message']['tag'] ?? '';
$check('a second conversion gets a different tray tag', true, $tag2 !== '' && $tag2 !== $tags[0] && strpos($tag2, 'orbitra-conv-cid2-sale') === 0);

// Dead endpoint: a 404 retires the device, the others still get their push.
CapturingNotifier::reset();
CapturingNotifier::$script = ['ok' => false, 'code' => 404, 'dead' => true, 'error' => 'gone'];
$sent = CapturingNotifier::dispatch($pdo, 'cid1', 'sale', 80.0, 'USD', 9);
$check('the dead device does not count, the others do', 2, $sent);
$check('the dead device is retired', 0, (int) $pdo->query("SELECT COUNT(*) FROM admin_push_subs WHERE is_active = 1 AND endpoint LIKE '%/a'")->fetchColumn());
$check('a delivered device got its last_seen stamped', 1, (int) $pdo->query("SELECT COUNT(*) FROM admin_push_subs WHERE endpoint LIKE '%/b' AND last_seen IS NOT NULL")->fetchColumn());

// --- digests ---------------------------------------------------------------------

echo "\ndigests\n";
$pdo->exec("DELETE FROM admin_push_settings");
$pdo->exec("DELETE FROM admin_push_digest_log");
$pdo->exec("UPDATE admin_push_subs SET is_active = 1");
$ue = $mkUser('digest-user', 'ru', 'UTC');
$sub($ue, 'https://fcm.example/e');
// Yesterday's clicks count into the weekly window but not the daily one:
// distinct revenue sums make the two texts distinguishable.
$pdo->exec("INSERT INTO clicks (id, offer_id, country_code, campaign_id, revenue) VALUES ('c-old', 3, 'GR', 5, 50)");
$pdo->exec("UPDATE clicks SET created_at = datetime('now', '-1 day') WHERE id = 'c-old'");
$pdo->exec("UPDATE clicks SET revenue = 100 WHERE id = 'cid1'");
CapturingNotifier::saveSettings($pdo, $ue, ['digest_daily' => 1, 'digest_weekly' => 1, 'digest_time' => '00:00']);

CapturingNotifier::reset();
$sent = CapturingNotifier::runDigests($pdo);
$check('due daily + weekly digests both send', 2, $sent);
$digestTags = array_map(static fn ($c) => $c['message']['tag'] ?? '', CapturingNotifier::$calls);
$check('digest tags are per period + window', true,
    strpos($digestTags[0] ?? '', 'orbitra-digest-') === 0 && $tags !== $digestTags);
$check('a digest tap-through targets the dashboard', true,
    array_reduce(CapturingNotifier::$calls, static fn ($acc, $c) => $acc && ($c['message']['link_url'] === '?tab=dashboard'), true));
$bodies = array_map(static fn ($c) => $c['message']['title'] . ' ' . $c['message']['text'], CapturingNotifier::$calls);
$getBody = static function (string $needle) use ($bodies): string {
    foreach ($bodies as $b) {
        if (strpos($b, $needle) !== false) {
            return $b;
        }
    }
    return '';
};
$daily = $getBody('Итоги дня');
$check('the daily digest is labelled in the user language (ru)', true, $daily !== '');
$check('the daily digest sums today only', true, strpos($daily, '100.00') !== false && strpos($daily, '150.00') === false);
// On a Monday the ISO week starts today, so "yesterday" is not in it yet.
$weeklyExpect = (date('o-W') === date('o-W', time() - 86400)) ? '150.00' : '100.00';
$check('the weekly digest window follows the ISO week', true, strpos($getBody('Итоги недели'), $weeklyExpect) !== false);

$sent = CapturingNotifier::runDigests($pdo);
$check('a second tick the same day sends nothing (sent-marker)', 0, $sent);

// Not yet due: 23:59 with the local clock at noon.
CapturingNotifier::reset();
$pdo->exec("DELETE FROM admin_push_digest_log");
CapturingNotifier::saveSettings($pdo, $ue, ['digest_daily' => 1, 'digest_weekly' => 0, 'digest_time' => '23:59']);
$noon = strtotime(date('Y-m-d') . ' 12:00:00 UTC');
$check('a digest before digest_time is not sent', 0, CapturingNotifier::runDigests($pdo, $noon));
$late = strtotime(date('Y-m-d') . ' 23:59:30 UTC');
$check('the same digest fires after digest_time', 1, CapturingNotifier::runDigests($pdo, $late));

// A user with digests on but no devices sends nothing.
$pdo->exec("DELETE FROM admin_push_digest_log");
$uf = $mkUser('no-device');
CapturingNotifier::saveSettings($pdo, $uf, ['digest_daily' => 1, 'digest_time' => '00:00']);
$check('digest without devices is a no-op', 0, CapturingNotifier::runDigests($pdo));

// --- test send --------------------------------------------------------------------

echo "\ntest send\n";
CapturingNotifier::reset();
$res = CapturingNotifier::sendTest($pdo, $ue);
$check('test push reaches the user devices', 1, count($res['results']));
$check('test result is ok', true, !empty($res['results'][0]['ok']));

// ---------------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "All admin push tests passed.\n";
