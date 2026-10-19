<?php
/**
 * tests/push_stage2_test.php
 *
 * PWA/пуши, этап 2 (миграции 64–65):
 *
 *  1. {offer.value}/{value1}/{value2}: таблица offers.country_values, матчинг
 *     по стране подписчика, фолбэк на безусловную строку, без контекста
 *     макрос остаётся видимым (правило честности);
 *  2. атрибуция конверсий за пуш-кликами (core/PushAttribution): окно от
 *     клика, «последний клик побеждает», FTD vs редепы, доход;
 *  3. тихие часы и зоны (core/PushQueue): парсинг окна, свёрнутые окна
 *     (23–08), зона подписки/гео-фолбэк, локальный час + UTC-пробуждение;
 *  4. рендерер v19: автовыход из вебвью и view-событие на месте.
 *
 * Запуск из корня проекта:
 *
 *     php tests/push_stage2_test.php
 */

require_once __DIR__ . '/../core/ReportMetrics.php';
require_once __DIR__ . '/../core/PushMacros.php';
require_once __DIR__ . '/../core/PushQueue.php';
require_once __DIR__ . '/../core/PushAttribution.php';
require_once __DIR__ . '/../core/PwaLanding.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

// ---- {offer.*} макросы --------------------------------------------------------

echo "offer macros\n";

$json = json_encode([
    ['country' => 'GR', 'value' => '100€ bonus', 'value1' => '50 fs', 'value2' => 'EUR'],
    ['country' => 'GR', 'value' => 'duplicate must lose'],
    ['country' => '', 'value' => '50$ welcome', 'value1' => '25 fs', 'value2' => 'USD'],
]);
$values = PushMacros::parseCountryValues($json);
check('country table parses, first duplicate row wins', ($values['GR'][0] ?? '') === '100€ bonus' && count($values) === 2, json_encode($values));
check('empty country row parses as the universal fallback', ($values[''][0] ?? '') === '50$ welcome');
check('garbage JSON gives an empty table', PushMacros::parseCountryValues('not json') === []);

$ctx = ['country' => 'gr', 'values' => $values]; // нижний регистр — матчинг по strtoupper
$text = 'Get {offer.value} + {offer.value1} in {offer.value2}! Your id {subid}';
$expanded = PushMacros::expand($text, 'abc123', $ctx);
check('macros expand from the subscriber country row',
    $expanded === 'Get 100€ bonus + 50 fs in EUR! Your id abc123', $expanded);

$ctxUnknown = ['country' => 'PT', 'values' => $values];
$expandedFallback = PushMacros::expand('{offer.value}', '', $ctxUnknown);
check('unknown country falls back to the universal row', $expandedFallback === '50$ welcome', $expandedFallback);

check('without offer context the macro stays visible, never blank',
    PushMacros::expand('{offer.value}', 'x', null) === '{offer.value}');
check('empty table context keeps the macro too',
    PushMacros::expand('{offer.value2}', '', ['country' => 'GR', 'values' => []]) === '{offer.value2}');

// ---- Атрибуция ----------------------------------------------------------------

echo "push attribution\n";

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
$pdo->exec("CREATE TABLE push_sends (id INTEGER PRIMARY KEY AUTOINCREMENT, message_id INTEGER, subscription_id INTEGER, ok INTEGER DEFAULT 1, response_code INTEGER, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP, clicked_at DATETIME, viewed_at DATETIME)");
$pdo->exec("CREATE TABLE push_subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, click_id TEXT, endpoint TEXT, p256dh TEXT, auth TEXT, is_active INTEGER DEFAULT 1, country_code TEXT, timezone TEXT)");
$pdo->exec("CREATE TABLE clicks (id TEXT PRIMARY KEY, offer_id INTEGER)");
$pdo->exec("CREATE TABLE conversions (id INTEGER PRIMARY KEY AUTOINCREMENT, click_id TEXT, status TEXT, revenue REAL, created_at DATETIME)");
// окно по умолчанию 72 часа; атрибуция от клика.
$pdo->exec("INSERT INTO push_subscriptions (click_id, endpoint) VALUES ('c1', 'e1'), ('c2', 'e2')");
$pdo->exec("INSERT INTO clicks (id) VALUES ('c1'), ('c2')");
$pdo->exec("INSERT INTO push_sends (message_id, subscription_id, ok, clicked_at) VALUES
    (7, 1, 1, '2026-10-15 10:00:00'),   -- клик по пушу №1
    (7, 1, 1, '2026-10-16 10:00:00'),   -- повторный клик: последний побеждает
    (8, 2, 1, '2026-10-16 12:00:00')");
$conv = $pdo->prepare("INSERT INTO conversions (click_id, status, revenue, created_at) VALUES (?,?,?,?)");
// c1: рега в окне второго клика (+2ч) — считается; депозит в окне (+10ч) — FTD;
// второй депозит (+20ч) — редеп; рега ДО первого клика — не считается; конверсия
// за пределами окна (+100ч) — не считается.
$conv->execute(['c1', 'registration', 2.0, '2026-10-15 09:00:00']);
$conv->execute(['c1', 'registration', 2.0, '2026-10-16 12:00:00']);
$conv->execute(['c1', 'deposit', 80.0, '2026-10-16 20:00:00']);
$conv->execute(['c1', 'deposit', 40.0, '2026-10-17 06:00:00']);
$conv->execute(['c1', 'deposit', 999.0, '2026-10-20 20:00:00']);
// c2: депозит через минуту после клика пуша №8.
$conv->execute(['c2', 'deposit', 25.0, '2026-10-16 12:01:00']);

$attr = PushAttribution::messageAttribution($pdo);
check('message 7: one reg, one FTD, one redep', ($attr[7]['regs'] ?? -1) === 1 && ($attr[7]['ftd'] ?? -1) === 1 && ($attr[7]['redeps'] ?? -1) === 1, json_encode($attr[7] ?? null));
check('message 7: revenue sums the in-window conversions (reg+dep+dep)', abs(($attr[7]['revenue'] ?? 0) - 122.0) < 0.001, (string) ($attr[7]['revenue'] ?? 0));
check('pre-click and out-of-window conversions never counted', ($attr[7]['regs'] ?? 0) === 1);
check('message 8: deposit right after the tap', ($attr[8]['ftd'] ?? 0) === 1 && abs(($attr[8]['revenue'] ?? 0) - 25.0) < 0.001);

// Окно и режим читаются из настроек.
$pdo->exec("INSERT INTO settings (key, value) VALUES ('push_attribution_hours', '1')");
$attr1h = PushAttribution::messageAttribution($pdo);
check('a 1-hour window drops everything that came hours later', !isset($attr1h[7]), json_encode($attr1h));
$pdo->exec("INSERT INTO settings (key, value) VALUES ('push_attribution_from', 'send')");
check('anchor mode setting read back', PushAttribution::anchorMode($pdo) === 'send');
$pdo->exec("DELETE FROM settings WHERE key = 'push_attribution_from'");
check('anchor mode default is click', PushAttribution::anchorMode($pdo) === 'click');

// ---- Тихие часы / зоны ---------------------------------------------------------

echo "quiet hours and zones\n";

check('stored zone wins', orbitraPushZoneFor('Europe/Athens', 'DE') === 'Europe/Athens');
check('garbage stored zone falls back to the geo country', orbitraPushZoneFor('Mars/Olympus', 'GR') === 'Europe/Athens');
check('unknown country falls back to UTC', orbitraPushZoneFor('', 'XX') === 'UTC');

[$from, $to] = orbitraPushQuietHours($pdo);
check('default quiet window is 23-08', $from === 23 && $to === 8);
$pdo->exec("INSERT INTO settings (key, value) VALUES ('push_quiet_hours', '2 - 5')");
[$from2, $to2] = orbitraPushQuietHours($pdo);
check('custom window parses with spaces', $from2 === 2 && $to2 === 5);
$pdo->exec("UPDATE settings SET value = 'garbage' WHERE key = 'push_quiet_hours'");
[$from3, $to3] = orbitraPushQuietHours($pdo);
check('garbage window falls back to 23-08', $from3 === 23 && $to3 === 8);

// Wrap-around math: 23-08 silences 23:00..07:59.
$inQuiet = fn($h) => $from3 <= $to3 ? ($h >= $from3 && $h < $to3) : ($h >= $from3 || $h < $to3);
check('wrapped window: 23 and 7 quiet, 8 and 22 awake', $inQuiet(23) && $inQuiet(7) && !$inQuiet(8) && !$inQuiet(22));

[$hour, $wake] = orbitraPushLocalHour('Europe/Athens');
check('local hour is an int in range and the wake is a UTC timestamp',
    $hour >= 0 && $hour <= 23 && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $wake) === 1, "$hour / $wake");

// ---- Рендерер v19 --------------------------------------------------------------

echo "renderer v19\n";

check('RENDERER_VERSION at v21 (store layout rework: stats strip, action row, desc clamp)', PwaLanding::RENDERER_VERSION === 21);
$renderer = (string) file_get_contents(__DIR__ . '/../core/PwaLanding.php');
check('the webview escape branch is wired to cfg.inapp_escape', strpos($renderer, 'inAppWebview && cfg.inapp_escape') !== false);
check('the escape URL carries the click id and the _ext marker', strpos($renderer, "searchParams.set('subid', subid)") !== false && strpos($renderer, "searchParams.set('_ext', '1')") !== false);
check('Android intent and iOS 17 x-safari are both built', strpos($renderer, 'com.android.chrome') !== false && strpos($renderer, 'x-safari-https:') !== false);
check('the return beacon exists', strpos($renderer, "beacon('inapp_return')") !== false);
check('the SW view beacon fires with the delivery ids', strpos($renderer, "action=pushview") !== false);
check('the SW subscribe posts the timezone', strpos($renderer, 'resolvedOptions().timeZone') !== false);
check('inapp_escape is a default-on config', strpos($renderer, "'inapp_escape'           => true") !== false);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
