<?php
/**
 * tests/tiktok_cost_timezone_test.php
 *
 * Таймзона кабинета TikTok в раскладке расхода по дням.
 *
 * До фикса TikTok-подключения несли пустую таймзону (её не отдаёт ни
 * /oauth2/advertiser/get/, ни MCP-дискавери), и CostImporter раскладывал
 * расход по UTC-дням: у кабинета в UTC+3 около трёх часов ночных кликов
 * получали стоимость соседнего дня. Тест закрывает цепочку целиком:
 *
 *  1. CostImporter::resolveTimezone() — живой lookup через ветку tiktok
 *     (TikTokAdsEngine::accountTimezone) и кэш в settings на 24 часа;
 *  2. раскладка: клик в 23:30 UTC принадлежит расходному дню кабинета
 *     Europe/Athens, а UTC-подключение тот же клик в тот день не берёт;
 *  3. CostImporter::rematch() — починка истории: стёртая и заново
 *     разложенная по правильным границам стоимость, идемпотентность.
 *
 * Запуск из корня проекта:
 *
 *     php tests/tiktok_cost_timezone_test.php
 *
 * Сети нет: HTTP-слой подменён TikTokAdsEngine::$testHttpHandler.
 */

require_once __DIR__ . '/../core/ClickParams.php';
require_once __DIR__ . '/../core/CurrencyRates.php';
require_once __DIR__ . '/../core/CostImporter.php';
require_once __DIR__ . '/../aggregator_engines/TikTokAdsEngine.php';

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

function freshDb(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT, updated_at DATETIME)");
    $pdo->exec("CREATE TABLE aggregator_connections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT, engine TEXT, credentials_json TEXT, field_mapping_json TEXT,
        is_active INTEGER DEFAULT 1
    )");
    $pdo->exec("CREATE TABLE clicks (
        id TEXT PRIMARY KEY, campaign_id INTEGER, ip TEXT,
        cost REAL DEFAULT 0, fee REAL NOT NULL DEFAULT 0, revenue REAL DEFAULT 0,
        parameters_json TEXT, created_at DATETIME
    )");
    $pdo->exec("CREATE TABLE cost_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER, external_id TEXT,
        source_campaign_id TEXT, ad_id TEXT, adset_id TEXT, amount REAL, fee_amount REAL NOT NULL DEFAULT 0, impressions INTEGER, platform_clicks INTEGER,
        currency TEXT, click_date DATE, raw_json TEXT, is_matched INTEGER DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE agencies (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, fee_percent REAL NOT NULL DEFAULT 0,
        fee_model TEXT NOT NULL DEFAULT 'percent', contacts TEXT DEFAULT '', payment_terms TEXT DEFAULT '', notes TEXT DEFAULT ''
    )");
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('currency', 'USD')");
    return $pdo;
}

function addConnection(PDO $pdo, string $engine, array $credentials): int
{
    $stmt = $pdo->prepare("INSERT INTO aggregator_connections (name, engine, credentials_json, field_mapping_json) VALUES (?, ?, ?, '{}')");
    $stmt->execute([$engine . ' test', $engine, json_encode($credentials)]);
    return (int) $pdo->lastInsertId();
}

function addClick(PDO $pdo, string $id, string $createdAt, array $params, float $cost = 0.0): void
{
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, cost, parameters_json, created_at) VALUES (?, 1, '1.1.1.1', ?, ?, ?)")
        ->execute([$id, $cost, json_encode($params), $createdAt]);
}

function clickCost(PDO $pdo, string $id): float
{
    $stmt = $pdo->prepare("SELECT cost FROM clicks WHERE id = ?");
    $stmt->execute([$id]);
    return (float) $stmt->fetchColumn();
}

function rec(string $date, float $amount, string $ad): array
{
    return [
        'external_id' => "tt_1_{$ad}_{$date}",
        'source_campaign_id' => '1790000000000000001',
        'ad_id' => $ad,
        'adset_id' => '1795000000000000001',
        'amount' => $amount,
        'currency' => 'USD',
        'date' => $date,
        'raw_json' => '{}',
    ];
}

/** Ответ /advertiser/info/ одним кабинетом; display_timezone перекрывает timezone. */
function advertiserInfoResponse(string $displayTz, string $tz): array
{
    return [
        'body' => json_encode(['code' => 0, 'message' => 'OK', 'data' => ['list' => [[
            'advertiser_id' => '7661936120595972112',
            'name' => 'Athens Cabinet',
            'currency' => 'EUR',
            'display_timezone' => $displayTz,
            'timezone' => $tz,
        ]]]]),
        'error' => null,
    ];
}

$creds = ['access_token' => 'TEST_TOKEN', 'advertiser_id' => '7661936120595972112'];
$opts = ['exclude_safe_page' => false];

// ---- Живой lookup таймзоны + кэш --------------------------------------------

echo "resolveTimezone: live lookup through the tiktok branch, cached for a day\n";

$pdo = freshDb();
// Пустая таймзона в credentials — как оставляли OAuth-потоки до фикса.
$connId = addConnection($pdo, 'tiktok', $creds);

TikTokAdsEngine::$testHttpHandler = function () use (&$liveLookupCalls) {
    $liveLookupCalls++;
    return advertiserInfoResponse('Europe/Athens', 'Etc/GMT-3');
};

$pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, parameters_json, created_at) VALUES ('warmup', 1, '1.1.1.1', '{}', '2026-10-11 10:00:00')")
    ->execute();
CostImporter::import($pdo, $connId, [], [], $opts + ['credentials' => $creds]);

$cached = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
$cached->execute(['aggregator_tz_' . $connId]);
check('live timezone lands in the settings cache', (string) $cached->fetchColumn() === 'Europe/Athens');

TikTokAdsEngine::$testHttpHandler = function (): array {
    throw new RuntimeException('advertiser/info must not be hit again within the cache window');
};
addClick($pdo, 'cached-run', '2026-10-11 12:00:00', ['ad_id' => 'TT_CACHED']);
$stats = CostImporter::import($pdo, $connId, [rec('2026-10-11', 5.0, 'TT_CACHED')], [], $opts + ['credentials' => $creds]);
check('cached timezone answers the second import without a request', $stats['timezone'] === 'Europe/Athens', json_encode($stats));

// ---- Раскладка: 23:30 UTC = уже следующий день в Афинах ----------------------

echo "day attribution under the account timezone\n";

$pdo = freshDb();
$connId = addConnection($pdo, 'tiktok', $creds + ['timezone' => 'Europe/Athens']);
addClick($pdo, 'night-click', '2026-10-10 23:30:00', ['ad_id' => 'TT_NIGHT']);

$stats = CostImporter::import($pdo, $connId, [rec('2026-10-11', 12.0, 'TT_NIGHT')], [], $opts + ['credentials' => $creds + ['timezone' => 'Europe/Athens']]);
check('a 23:30 UTC click matches the Athens spend day (UTC+3)', $stats['matched'] === 1, json_encode($stats));
check('the spend landed on the click', abs(clickCost($pdo, 'night-click') - 12.0) < 0.0001, (string) clickCost($pdo, 'night-click'));

// Контроль: то же подключение, но честный UTC — тот же клик в тот день не берётся.
$pdo = freshDb();
$connUtc = addConnection($pdo, 'tiktok', $creds + ['timezone' => 'UTC']);
addClick($pdo, 'night-click', '2026-10-10 23:30:00', ['ad_id' => 'TT_NIGHT']);

$stats = CostImporter::import($pdo, $connUtc, [rec('2026-10-11', 12.0, 'TT_NIGHT')], [], $opts + ['credentials' => $creds + ['timezone' => 'UTC']]);
check('the same click stays outside a UTC spend day (the pre-fix behaviour)', $stats['matched'] === 0 && $stats['unmatched'] === 1, json_encode($stats));

// ---- Ремэтч истории ----------------------------------------------------------

echo "rematch: moving spend onto the account-timezone day boundaries\n";

// История, как её оставил UTC-импорт: R1 (день 10-го, 30$) лёг на C1 и C2
// поровну, R2 (день 11-го, 20$) — целиком на C3. В Афинах C2 (23:30 UTC)
// принадлежит 11-му: после ремэтча R1 забирает только C1, R2 делится на C2/C3.
$pdo = freshDb();
$connId = addConnection($pdo, 'tiktok', $creds + ['timezone' => 'Europe/Athens']);
addClick($pdo, 'C1', '2026-10-10 12:00:00', ['ad_id' => 'TT9'], 15.0);
addClick($pdo, 'C2', '2026-10-10 23:30:00', ['ad_id' => 'TT9'], 15.0);
addClick($pdo, 'C3', '2026-10-11 10:00:00', ['ad_id' => 'TT9'], 20.0);
$pdo->prepare("INSERT INTO cost_records (connection_id, external_id, source_campaign_id, ad_id, adset_id, amount, currency, click_date, raw_json, is_matched) VALUES (?,?,?,?,?,?,?,?,?,1)")
    ->execute([$connId, 'tt_1_TT9_2026-10-10', '1790000000000000001', 'TT9', '1795000000000000001', 30.0, 'USD', '2026-10-10', '{}']);
$pdo->prepare("INSERT INTO cost_records (connection_id, external_id, source_campaign_id, ad_id, adset_id, amount, currency, click_date, raw_json, is_matched) VALUES (?,?,?,?,?,?,?,?,?,1)")
    ->execute([$connId, 'tt_1_TT9_2026-10-11', '1790000000000000001', 'TT9', '1795000000000000001', 20.0, 'USD', '2026-10-11', '{}']);

$stats = CostImporter::rematch($pdo, $connId, '2026-10-10', '2026-10-11');
check('rematch processed both rows and matched both', $stats['rows'] === 2 && $stats['matched'] === 2, json_encode($stats));
check('rematch zeroed the three misattributed clicks', $stats['zeroed_clicks'] === 3, json_encode($stats));
check('Oct 10 spend (Athens) concentrates on C1', abs(clickCost($pdo, 'C1') - 30.0) < 0.0001, (string) clickCost($pdo, 'C1'));
check('the 23:30 UTC click moved to the Oct 11 spend', abs(clickCost($pdo, 'C2') - 10.0) < 0.0001, (string) clickCost($pdo, 'C2'));
check('Oct 11 spend split across C2 and C3', abs(clickCost($pdo, 'C3') - 10.0) < 0.0001, (string) clickCost($pdo, 'C3'));
check('total spend preserved (30 + 20)', abs(clickCost($pdo, 'C1') + clickCost($pdo, 'C2') + clickCost($pdo, 'C3') - 50.0) < 0.0001);

$again = CostImporter::rematch($pdo, $connId, '2026-10-10', '2026-10-11');
check('rematch is idempotent', $again['matched'] === 2
    && abs(clickCost($pdo, 'C1') - 30.0) < 0.0001
    && abs(clickCost($pdo, 'C2') - 10.0) < 0.0001
    && abs(clickCost($pdo, 'C3') - 10.0) < 0.0001, json_encode($again));

TikTokAdsEngine::$testHttpHandler = null;

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
