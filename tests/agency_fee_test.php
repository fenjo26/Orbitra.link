<?php
/**
 * tests/agency_fee_test.php
 *
 * Комиссия агентства при импорте расхода (миграция 61).
 *
 * Реальная себестоимость трафика на арендном кабинете выше расхода в кабинете
 * на процент агентства. Комиссия вписывается в момент импорта: cost_records
 * .fee_amount хранит её построчно, clicks.cost несёт расход С комиссией (ROI
 * честный сразу), clicks.fee — долю комиссии на клике, чтобы отчёт любого
 * разреза показывал и «Расход кабинета», и «Расход + комиссия». Смена процента
 * не переписывает историю: уже сматченные строки пропускаются при реимпорте.
 *
 * Запуск из корня проекта:
 *
 *     php tests/agency_fee_test.php
 */

require_once __DIR__ . '/../core/ClickParams.php';
require_once __DIR__ . '/../core/CurrencyRates.php';
require_once __DIR__ . '/../core/CostImporter.php';

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
    $pdo->exec("CREATE TABLE agencies (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT,
        fee_percent REAL NOT NULL DEFAULT 0, fee_model TEXT NOT NULL DEFAULT 'percent',
        contacts TEXT DEFAULT '', payment_terms TEXT DEFAULT '', notes TEXT DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE aggregator_connections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT, engine TEXT, credentials_json TEXT, field_mapping_json TEXT,
        agency_id INTEGER, is_active INTEGER DEFAULT 1
    )");
    $pdo->exec("CREATE TABLE clicks (
        id TEXT PRIMARY KEY, campaign_id INTEGER, ip TEXT,
        cost REAL DEFAULT 0, fee REAL NOT NULL DEFAULT 0, revenue REAL DEFAULT 0,
        parameters_json TEXT, created_at DATETIME
    )");
    $pdo->exec("CREATE TABLE cost_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER, external_id TEXT,
        source_campaign_id TEXT, ad_id TEXT, adset_id TEXT, amount REAL, fee_amount REAL NOT NULL DEFAULT 0, impressions INTEGER, platform_clicks INTEGER,
        currency TEXT, click_date DATE, raw_json TEXT, zone_id TEXT NOT NULL DEFAULT '', is_matched INTEGER DEFAULT 0
    )");
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('currency', 'USD')");
    return $pdo;
}

function addAgencyConnection(PDO $pdo, float $feePercent, ?int $agencyId): int
{
    $pdo->prepare("INSERT INTO agencies (name, fee_percent) VALUES ('Rent Agency', ?)")->execute([$feePercent]);
    $agencyRow = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO aggregator_connections (name, engine, credentials_json, field_mapping_json, agency_id) VALUES ('fb test', 'facebook', '{}', '{}', ?)");
    $stmt->execute([$agencyId ?? $agencyRow]);
    return (int) $pdo->lastInsertId();
}

function addClick(PDO $pdo, string $id, string $date, array $params): void
{
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, parameters_json, created_at) VALUES (?, 1, '1.1.1.1', ?, ?)")
        ->execute([$id, json_encode($params), $date . ' 12:00:00']);
}

function rec(string $date, float $amount): array
{
    return [
        'external_id' => "act_1_{$date}", 'source_campaign_id' => '6010000000001',
        'ad_id' => '6010000000003', 'adset_id' => '6010000000002',
        'amount' => $amount, 'currency' => 'USD', 'date' => $date, 'raw_json' => '{}',
    ];
}

function clickCosts(PDO $pdo, string $id): array
{
    $stmt = $pdo->prepare("SELECT cost, fee FROM clicks WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [(float) $row['cost'], (float) $row['fee']];
}

$opts = ['exclude_safe_page' => false];
$d = '2026-10-12';

// ---- Комиссия ложится на клики -----------------------------------------------

echo "agency fee lands on clicks\n";

$pdo = freshDb();
$connId = addAgencyConnection($pdo, 10.0, null);
addClick($pdo, 'K1', $d, ['ad_id' => '6010000000003']);
addClick($pdo, 'K2', $d, ['ad_id' => '6010000000003']);

$stats = CostImporter::import($pdo, $connId, [rec($d, 100.0)], [], $opts);
check('stats expose the applied percent and fee', $stats['fee_percent'] === 10.0 && abs($stats['fee'] - 10.0) < 0.0001, json_encode($stats));

[$c1, $f1] = clickCosts($pdo, 'K1');
[$c2, $f2] = clickCosts($pdo, 'K2');
check('clicks.cost carries spend WITH the fee (55 each: (100+10)/2)', abs($c1 - 55.0) < 0.0001 && abs($c2 - 55.0) < 0.0001, "$c1/$c2");
check('clicks.fee carries the fee share (5 each)', abs($f1 - 5.0) < 0.0001 && abs($f2 - 5.0) < 0.0001, "$f1/$f2");

$row = $pdo->query("SELECT amount, fee_amount, raw_json FROM cost_records WHERE connection_id = $connId")->fetch(PDO::FETCH_ASSOC);
check('cost_records keeps the cabinet amount (100) and the fee (10) apart', abs((float) $row['amount'] - 100.0) < 0.0001 && abs((float) $row['fee_amount'] - 10.0) < 0.0001, json_encode($row));
$raw = json_decode((string) $row['raw_json'], true)['_orbitra'] ?? [];
check('raw_json._orbitra carries the fee annotation', abs(($raw['fee_amount'] ?? 0) - 10.0) < 0.0001 && abs(($raw['fee_percent'] ?? 0) - 10.0) < 0.0001, json_encode($raw));

// ---- Без агентства комиссия нулевая -------------------------------------------

$pdo = freshDb();
$connId = addAgencyConnection($pdo, 10.0, null);
$pdo->prepare("UPDATE aggregator_connections SET agency_id = NULL WHERE id = ?")->execute([$connId]);
addClick($pdo, 'K1', $d, ['ad_id' => '6010000000003']);
CostImporter::import($pdo, $connId, [rec($d, 100.0)], [], $opts);
[$c1, $f1] = clickCosts($pdo, 'K1');
check('no agency — cost equals the cabinet spend, fee zero', abs($c1 - 100.0) < 0.0001 && $f1 === 0.0, "$c1/$f1");

// ---- Смена процента не переписывает историю -----------------------------------

echo "a percent change never rewrites matched history\n";

$pdo = freshDb();
$connId = addAgencyConnection($pdo, 10.0, null);
addClick($pdo, 'K1', $d, ['ad_id' => '6010000000003']);
CostImporter::import($pdo, $connId, [rec($d, 100.0)], [], $opts);

// Тот же день реимпортируется с уже изменённым процентом (обычный реимпорт
// дня: сумма та же, строка сматчена → пропуск, как у расхода без комиссии).
$pdo->prepare("UPDATE agencies SET fee_percent = 20 WHERE id = (SELECT agency_id FROM aggregator_connections WHERE id = ?)")->execute([$connId]);
$stats = CostImporter::import($pdo, $connId, [rec($d, 100.0)], [], $opts);
[$c1, $f1] = clickCosts($pdo, 'K1');
check('a matched unchanged row keeps its imported fee (skip path)', abs($c1 - 110.0) < 0.0001 && abs($f1 - 10.0) < 0.0001, "$c1/$f1");
check('the re-import did not count the row as changed', ($stats['updated'] ?? 1) === 0, json_encode($stats));

// ---- Ремэтч сохраняет комиссию -------------------------------------------------

echo "rematch keeps the stored fee\n";

$pdo = freshDb();
$connId = addAgencyConnection($pdo, 15.0, null);
addClick($pdo, 'K1', $d, ['ad_id' => '6010000000003']);
CostImporter::import($pdo, $connId, [rec($d, 200.0)], [], $opts);
[$c1, $f1] = clickCosts($pdo, 'K1');
check('import with 15%: cost 230, fee 30', abs($c1 - 230.0) < 0.0001 && abs($f1 - 30.0) < 0.0001, "$c1/$f1");

CostImporter::rematch($pdo, $connId, $d, $d);
[$c1, $f1] = clickCosts($pdo, 'K1');
check('rematch re-attributes with the stored fee intact', abs($c1 - 230.0) < 0.0001 && abs($f1 - 30.0) < 0.0001, "$c1/$f1");

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
