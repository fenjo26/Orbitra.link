<?php
// tests/telegram_bot_test.php
//
// The bot used to be a mute menu-less switch: the handler knew every command
// and seven languages, but Telegram never registered a quick-command menu, a
// bare /lang silently reset the chat to Russian, a bare /notify silently
// switched notifications OFF, nobody ever sent the daily summary the /daily
// toggle promised, and only two of the seven languages existed past the
// help text. This pins the interactive layer:
//
//   1. setMyCommands payloads — default menu + one per language, ten
//      commands, descriptions localized through botText().
//   2. /start greets with a one-tap language keyboard.
//   3. callback_query routing: lang:<code> switches language, camp:<id>
//      opens the campaign detail, notify/daily toggle their own column.
//   4. /lang and /notify /daily without arguments answer with the current
//      state instead of mutating it; a misspelled language is an error,
//      never a silent reset.
//   5. /campaigns carries one button per campaign; tapping it opens the
//      detail view.
//   6. notifyConversion respects the global switch, the per-chat switch,
//      and speaks the chat's language.
//   7. The daily summary sends exactly once per day, even when several
//      crons race the once-a-day claim.
//
// Run: php tests/telegram_bot_test.php

define('ORBITRA_TELEGRAM_NO_WEBHOOK', true);
define('ORBITRA_TELEGRAM_TEST_OUTBOX', true);

$testPassed = true;

function assertTrue($condition, $message) {
    global $testPassed;
    if (!$condition) {
        fwrite(STDERR, "FAILED: $message\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return $condition;
}

function assertEquals($expected, $actual, $message) {
    global $testPassed;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected: " . var_export($expected, true) . "\n");
        fwrite(STDERR, "  Actual:   " . var_export($actual, true) . "\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return $expected === $actual;
}

function assertContains($needle, $haystack, $message) {
    global $testPassed;
    if (strpos((string) $haystack, (string) $needle) === false) {
        fwrite(STDERR, "FAILED: $message\n");
        fwrite(STDERR, "  Expected to contain: " . var_export($needle, true) . "\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
}

function outboxReset() {
    $GLOBALS['orbitra_telegram_outbox'] = [];
}

function outboxMethods() {
    return array_map(fn($e) => $e['method'], $GLOBALS['orbitra_telegram_outbox'] ?? []);
}

function outboxLast($method) {
    for ($i = count($GLOBALS['orbitra_telegram_outbox'] ?? []) - 1; $i >= 0; $i--) {
        if ($GLOBALS['orbitra_telegram_outbox'][$i]['method'] === $method) {
            return $GLOBALS['orbitra_telegram_outbox'][$i]['params'];
        }
    }
    return null;
}

function msg(string $text, array $overrides = []): array {
    return array_merge([
        'message' => array_merge([
            'chat' => ['id' => '100'],
            'from' => ['username' => 'op', 'first_name' => 'Op'],
            'text' => $text,
        ], $overrides),
    ], $overrides);
}

function setChatFlag($pdo, string $chatId, string $column, $value): void {
    $pdo->prepare("UPDATE telegram_bot_chats SET {$column} = ? WHERE chat_id = ?")->execute([$value, $chatId]);
}

function chatFlag($pdo, string $chatId, string $column) {
    $stmt = $pdo->prepare("SELECT {$column} FROM telegram_bot_chats WHERE chat_id = ?");
    $stmt->execute([$chatId]);
    return $stmt->fetchColumn();
}

// --- Fixture: a private throwaway sqlite, no harness, no working DB ---------
$tmpDb = sys_get_temp_dir() . '/orbitra_telegram_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$pdo = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("
    CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
    CREATE TABLE telegram_bot_chats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id TEXT NOT NULL UNIQUE,
        username TEXT, first_name TEXT,
        language TEXT DEFAULT 'ru',
        notify_conversions INTEGER DEFAULT 1,
        notify_daily INTEGER DEFAULT 1,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE campaigns (id INTEGER PRIMARY KEY, name TEXT, alias TEXT, token TEXT, state TEXT, is_archived INTEGER DEFAULT 0);
    CREATE TABLE clicks (id INTEGER PRIMARY KEY, campaign_id INTEGER, ip TEXT, revenue REAL DEFAULT 0, cost REAL DEFAULT 0, is_conversion INTEGER DEFAULT 0, country TEXT, created_at TEXT);
    CREATE TABLE conversions (id INTEGER PRIMARY KEY, click_id INTEGER, status TEXT, payout REAL DEFAULT 0, currency TEXT DEFAULT 'USD', created_at TEXT);
    CREATE TABLE traffic_sources (id INTEGER PRIMARY KEY, name TEXT, url TEXT, http_status TEXT, last_checked TEXT, status_message TEXT, is_archived INTEGER DEFAULT 0);
");

// CANARY + PDO preservation. Requiring telegram_bot.php pulls in config.php,
// which opens the working database into the same top-level $pdo variable —
// without the swap below this suite would silently run its fixture writes
// against the working DB. The canary proves the handle survived the require.
$pdo->exec("INSERT INTO settings (key, value) VALUES ('__fixture_canary', '1')");
$fixturePdo = $pdo;
require_once __DIR__ . '/../telegram_bot.php';
$pdo = $fixturePdo;
if ($pdo->query("SELECT value FROM settings WHERE key = '__fixture_canary'")->fetchColumn() !== '1') {
    fwrite(STDERR, "FATAL: fixture PDO was replaced during require — refusing to run against the working DB\n");
    exit(1);
}

// 1. Command menu payloads ---------------------------------------------------
$payloads = orbitraTelegramCommandPayloads();
assertEquals(8, count($payloads), 'setMyCommands payloads: default + 7 languages');
assertEquals(10, count($payloads['default']['commands']), 'menu carries all ten commands');
assertEquals('stats', $payloads['default']['commands'][0]['command'], 'stats is the first menu entry');
assertEquals('en', botText('en', 'cmd_stats') ? 'en' : '', 'en stats description resolves');
assertTrue($payloads['default']['commands'][0]['description'] !== $payloads['ru']['commands'][0]['description'],
    'menu descriptions are localized (ru ≠ en)');
assertEquals('ru', $payloads['ru']['language_code'], 'ru payload carries language_code');
assertTrue(!isset($payloads['default']['language_code']), 'default payload has no language_code');

outboxReset();
$results = orbitraTelegramRegisterCommands('TOKEN');
assertEquals(9, count(outboxMethods()), 'registration pushes 8 setMyCommands + 1 setChatMenuButton');
assertEquals('setMyCommands', outboxMethods()[0], 'all pushes are setMyCommands');
$menuBtn = outboxLast('setChatMenuButton');
assertTrue($menuBtn !== null && $menuBtn['menu_button']['type'] === 'commands',
    'menu button opens the quick-command list');

// 2. /start — welcome + language picker inline, then the pinned visual menu.
outboxReset();
$pdo->exec("DELETE FROM telegram_bot_chats");
orbitraTelegramProcessUpdate($pdo, 'TOKEN', msg('/start'));
$sends = array_values(array_filter($GLOBALS['orbitra_telegram_outbox'], fn($e) => $e['method'] === 'sendMessage'));
assertEquals(2, count($sends), '/start answers with welcome + pinned menu');
$startMsg = $sends[0]['params'];
assertContains('Orbitra', $startMsg['text'], '/start greets the operator');
$langButtons = 0;
foreach ($startMsg['reply_markup']['inline_keyboard'] as $row) {
    foreach ($row as $btn) {
        if (str_starts_with($btn['callback_data'], 'lang:')) $langButtons++;
    }
}
assertEquals(7, $langButtons, '/start keyboard offers all 7 languages');

$menuMsg = $sends[1]['params'];
$replyKb = $menuMsg['reply_markup'];
assertTrue(($replyKb['is_persistent'] ?? false) === true, 'pinned menu is persistent');
assertTrue(($replyKb['resize_keyboard'] ?? false) === true, 'pinned menu resizes to compact rows');
$menuButtons = [];
foreach ($replyKb['keyboard'] as $row) {
    foreach ($row as $btn) {
        $menuButtons[] = $btn['text'];
    }
}
assertEquals(8, count($menuButtons), 'pinned menu carries 8 labeled buttons');
assertEquals('📊 Статистика', $menuButtons[0], 'menu labels are readable, not slash syntax');
assertTrue(botText('ru', 'kbd_hint') !== 'kbd_hint', 'kbd placeholder key is localized');
assertEquals('ru', chatFlag($pdo, '100', 'language'), 'new chat defaults to ru');

// A tap on a menu label arrives as that text: it must resolve to the command.
$pdo->exec("INSERT INTO clicks (campaign_id, ip, revenue, cost, is_conversion, country, created_at)
            VALUES (1, '9.9.9.9', 2.0, 0.5, 0, 'DE', datetime('now'))");
outboxReset();
orbitraTelegramProcessUpdate($pdo, 'TOKEN', msg('📊 Статистика'));
$tapped = outboxLast('sendMessage');
assertTrue($tapped !== null && strpos($tapped['text'], '❓') !== 0,
    'tapping a menu label routes to its command');
assertContains('Статистика', $tapped['text'], 'label tap runs the stats command');

// 3. /lang — bare, invalid, callback ------------------------------------------
outboxReset();
orbitraTelegramProcessUpdate($pdo, 'TOKEN', msg('/lang'));
$last = end($GLOBALS['orbitra_telegram_outbox']);
assertTrue($last && $last['method'] === 'sendMessage' && isset($last['params']['reply_markup']),
    'bare /lang shows the picker instead of touching state');
assertEquals('ru', chatFlag($pdo, '100', 'language'), 'bare /lang leaves language alone');

outboxReset();
orbitraTelegramProcessUpdate($pdo, 'TOKEN', msg('/lang qq'));
$langMsg = outboxLast('sendMessage');
assertContains('qq', $langMsg['text'], 'invalid /lang names the bad argument');
assertContains('ru, en, uk, es, zh, fr, de', $langMsg['text'], 'invalid /lang lists valid codes');
assertEquals('ru', chatFlag($pdo, '100', 'language'), 'invalid /lang never silently resets to ru');

outboxReset();
orbitraTelegramProcessUpdate($pdo, 'TOKEN', ['callback_query' => [
    'id' => 'cb1', 'data' => 'lang:de',
    'from' => ['username' => 'op'],
    'message' => ['chat' => ['id' => '100']],
]]);
assertTrue(in_array('answerCallbackQuery', outboxMethods(), true), 'language tap answers the callback query');
assertEquals('de', chatFlag($pdo, '100', 'language'), 'lang:de callback switches language');
assertContains('Neue Conversion', botText('de', 'new_conversion') ?: '', 'de templates exist for later checks');

// 4. /notify and /daily — bare is read-only, on/off set, button toggles -------
// The lang:de test above switched the chat to German; the /notify wording
// assertions below are pinned to the Russian strings, so switch back first.
setChatFlag($pdo, '100', 'language', 'ru');
outboxReset();
setChatFlag($pdo, '100', 'notify_conversions', 1);
orbitraTelegramProcessUpdate($pdo, 'TOKEN', msg('/notify'));
assertContains('включены', outboxLast('sendMessage')['text'], 'bare /notify reports the enabled state (ru)');
assertEquals(1, chatFlag($pdo, '100', 'notify_conversions'), 'bare /notify changes nothing');

outboxReset();
orbitraTelegramProcessUpdate($pdo, 'TOKEN', msg('/notify off'));
assertEquals(0, chatFlag($pdo, '100', 'notify_conversions'), '/notify off disables');
assertContains('отключены', outboxLast('sendMessage')['text'], '/notify off confirms in Russian');

outboxReset();
orbitraTelegramProcessUpdate($pdo, 'TOKEN', msg('/notify nonsense'));
assertEquals(0, chatFlag($pdo, '100', 'notify_conversions'), 'unknown /notify argument changes nothing');

outboxReset();
orbitraTelegramProcessUpdate($pdo, 'TOKEN', ['callback_query' => [
    'id' => 'cb2', 'data' => 'notify', 'from' => [],
    'message' => ['chat' => ['id' => '100']],
]]);
assertEquals(1, chatFlag($pdo, '100', 'notify_conversions'), 'notify button toggles off→on');

setChatFlag($pdo, '100', 'notify_daily', 0);
outboxReset();
orbitraTelegramProcessUpdate($pdo, 'TOKEN', msg('/daily'));
assertContains('отключена', outboxLast('sendMessage')['text'], 'bare /daily reports the disabled state');
assertEquals(0, chatFlag($pdo, '100', 'notify_daily'), 'bare /daily changes nothing');

// 5. /campaigns with buttons, camp: callback, /campaign detail -----------------
// The name carries a Markdown metacharacter on purpose: legacy Markdown must
// never let an operator's campaign name eat the message's own *bold* markers.
$pdo->exec("INSERT INTO campaigns (id, name, alias, state) VALUES (11, 'Pocket_Final', 'pock', 'active')");
$pdo->exec("INSERT INTO campaigns (id, name, alias, state) VALUES (12, 'Archived One', 'arch', 'active')");
$pdo->exec("UPDATE campaigns SET is_archived = 1 WHERE id = 12");
$pdo->exec("INSERT INTO clicks (campaign_id, ip, revenue, cost, is_conversion, country, created_at)
            VALUES (11, '1.2.3.4', 4.5, 1.0, 1, 'DE', datetime('now'))");

outboxReset();
orbitraTelegramProcessUpdate($pdo, 'TOKEN', msg('/campaigns'));
$campsMsg = outboxLast('sendMessage');
assertContains('Pocket\\_Final', $campsMsg['text'], '/campaigns escapes Markdown in campaign names');
assertContains('Pocket_Final', $campsMsg['reply_markup']['inline_keyboard'][0][0]['text'],
    'button labels stay plain text (no escaping needed)');
assertTrue(strpos($campsMsg['text'], 'Archived One') === false, '/campaigns hides archived campaigns');
$campCallbacks = [];
foreach ($campsMsg['reply_markup']['inline_keyboard'] as $row) {
    foreach ($row as $btn) {
        if (str_starts_with($btn['callback_data'], 'camp:')) $campCallbacks[] = $btn['callback_data'];
    }
}
assertEquals(['camp:11'], $campCallbacks, 'one button per listed campaign');

outboxReset();
orbitraTelegramProcessUpdate($pdo, 'TOKEN', ['callback_query' => [
    'id' => 'cb3', 'data' => 'camp:11', 'from' => [],
    'message' => ['chat' => ['id' => '100']],
]]);
assertContains('Pocket\\_Final', outboxLast('sendMessage')['text'], 'camp: button opens the detail view');

outboxReset();
orbitraTelegramProcessUpdate($pdo, 'TOKEN', msg('/campaign 999'));
assertContains('❌', outboxLast('sendMessage')['text'], 'unknown /campaign ID answers with the not-found text');

// 6. /stats — today with data, and the empty case ------------------------------
outboxReset();
orbitraTelegramProcessUpdate($pdo, 'TOKEN', msg('/stats today'));
assertContains('📊', outboxLast('sendMessage')['text'], '/stats today answers with the stats block');
outboxReset();
orbitraTelegramProcessUpdate($pdo, 'TOKEN', msg('/stats'));
assertContains('📊', outboxLast('sendMessage')['text'], 'bare /stats falls back to today');

// 7. notifyConversion — global gate, per-chat gate, chat language --------------
require_once __DIR__ . '/../telegram_notify.php';
$pdo->exec("INSERT INTO clicks (id, campaign_id, ip, revenue, cost, is_conversion, country, created_at)
            VALUES (501, 11, '5.6.7.8', 7.5, 1.0, 1, 'BR', datetime('now'))");
$pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('telegram_bot_token', 'TOKEN')");
$pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('telegram_notify_conversions', '0')");

outboxReset();
notifyConversion($pdo, 501, 'lead', 7.5, 11, 'USD');
assertEquals([], outboxMethods(), 'global notifications switch off → nothing sent');

$pdo->exec("UPDATE settings SET value = '1' WHERE key = 'telegram_notify_conversions'");
setChatFlag($pdo, '100', 'notify_conversions', 0);
outboxReset();
notifyConversion($pdo, 501, 'lead', 7.5, 11, 'USD');
assertEquals([], outboxMethods(), 'chat opted out → nothing sent');

setChatFlag($pdo, '100', 'notify_conversions', 1);
setChatFlag($pdo, '100', 'language', 'de');
outboxReset();
notifyConversion($pdo, 501, 'lead', 7.5, 11, 'USD');
$convMsg = outboxLast('sendMessage');
assertTrue($convMsg !== null, 'subscribed chat receives the conversion push');
assertContains('🇧🇷', $convMsg['text'], 'conversion push carries the country flag');

// 8. Daily summary — due gate, exactly-once claim ------------------------------
setChatFlag($pdo, '100', 'language', 'ru');
$pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('telegram_daily_time', '00:00')");
$pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('telegram_daily_last_sent', '')");
setChatFlag($pdo, '100', 'notify_daily', 1);

outboxReset();
orbitraTelegramMaybeSendDaily($pdo);
assertTrue(in_array('sendMessage', outboxMethods(), true), 'daily summary sends when due');
assertContains('Ежедневная сводка', outboxLast('sendMessage')['text'], 'summary is in the chat language');
$claimed = $pdo->query("SELECT value FROM settings WHERE key = 'telegram_daily_last_sent'")->fetchColumn();
assertEquals(date('Y-m-d'), $claimed, 'summary claim recorded for today');

outboxReset();
orbitraTelegramMaybeSendDaily($pdo);
assertEquals([], outboxMethods(), 'second call the same day sends nothing');

$pdo->exec("UPDATE settings SET value = '23:59' WHERE key = 'telegram_daily_time'");
$pdo->exec("UPDATE settings SET value = '" . date('Y-m-d', strtotime('-1 day')) . "' WHERE key = 'telegram_daily_last_sent'");
outboxReset();
orbitraTelegramMaybeSendDaily($pdo);
assertEquals([], outboxMethods(), 'before the configured time nothing sends');

setChatFlag($pdo, '100', 'notify_daily', 0);
$pdo->exec("UPDATE settings SET value = '00:00' WHERE key = 'telegram_daily_time'");
outboxReset();
orbitraTelegramMaybeSendDaily($pdo);
assertEquals([], outboxMethods(), 'no subscribed chats → no summary and no day claim');

// Cleanup
@unlink($tmpDb);
echo $testPassed ? "\nALL TESTS PASSED\n" : "\nSOME TESTS FAILED\n";
exit($testPassed ? 0 : 1);
