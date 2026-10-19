<?php
/**
 * tests/revenue_attribution_health_test.php
 *
 * Баннер «доход не привязан к объявлениям» (этап 0.4).
 *
 * Доля дохода считается по кликам: revenue клика «привязан», когда хоть один
 * ad-уровневый параметр несёт НАСТОЯЩИЙ id площадки — нераскрытый макрос
 * (__CID__, {{ad.id}}) считается отсутствующим, тем же правилом, что и
 * CostImporter::diagnoseUnmatched(). Тест проверяет само правило на фикстуре
 * кликов: настоящий id / макрос / пусто / creative с подчёркиваниями в id.
 *
 * Запуск из корня проекта:
 *
 *     php tests/revenue_attribution_health_test.php
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

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE clicks (
    id TEXT PRIMARY KEY, source_id INTEGER, revenue REAL DEFAULT 0,
    parameters_json TEXT, created_at DATETIME
)");

// ad-уровневые ключи дефолтного набора: ad_id, creative_id, creative.
$keys = CostImporter::attributionKeys()['ad'];
$real = CostImporter::attributionValuePredicateSql($keys, 'real');
$macro = CostImporter::attributionValuePredicateSql($keys, 'macro');

$cases = [
    // id           source  revenue  params
    ['real-ad',     10,     100.0,   ['ad_id' => '1780000000000000001']],
    ['real-creative', 10,   50.0,    ['creative' => '1203_456']],   // подчёркивания в id — не макрос
    ['macro-cid',   20,     80.0,    ['ad_id' => '__CID__']],
    ['macro-braces', 20,    20.0,    ['creative_id' => '{{ad.id}}']],
    ['no-keys',     30,     40.0,    ['sub_id_1' => 'x']],
    ['empty-ad',    30,     10.0,    ['ad_id' => '']],
    ['mixed',       20,     60.0,    ['ad_id' => '__CID__', 'adset_id' => '1795000000000000001']],
];
$ins = $pdo->prepare("INSERT INTO clicks (id, source_id, revenue, parameters_json, created_at) VALUES (?,?,?,?, '2026-10-15 12:00:00')");
foreach ($cases as $c) {
    $ins->execute([$c[0], $c[1], $c[2], json_encode($c[3])]);
}

$stmt = $pdo->query("SELECT id, source_id, revenue,
    CASE WHEN {$real} THEN 1 ELSE 0 END AS is_real,
    CASE WHEN {$macro} THEN 1 ELSE 0 END AS is_macro
    FROM clicks");
$rows = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rows[$r['id']] = $r;
}

check('a real ad_id counts as linked', (int) $rows['real-ad']['is_real'] === 1 && (int) $rows['real-ad']['is_macro'] === 0);
check('underscores inside a creative id are NOT a macro (double underscore is)', (int) $rows['real-creative']['is_real'] === 1, json_encode($rows['real-creative']));
check('__CID__ is a macro, not a real id', (int) $rows['macro-cid']['is_real'] === 0 && (int) $rows['macro-cid']['is_macro'] === 1);
check('{{ad.id}} is a macro in any ad-level key', (int) $rows['macro-braces']['is_real'] === 0 && (int) $rows['macro-braces']['is_macro'] === 1);
check('no ad keys at all: neither real nor macro', (int) $rows['no-keys']['is_real'] === 0 && (int) $rows['no-keys']['is_macro'] === 0);
check('an empty ad_id string counts as absent', (int) $rows['empty-ad']['is_real'] === 0);
check('a macro in ad_id with a REAL adset still flags the ad level as macro', (int) $rows['mixed']['is_macro'] === 1 && (int) $rows['mixed']['is_real'] === 0, json_encode($rows['mixed']));

// Доли по источникам — как их считает action=revenue_attribution_health.
$agg = $pdo->query("SELECT source_id,
    SUM(revenue) AS rev,
    SUM(CASE WHEN {$real} THEN revenue ELSE 0 END) AS rev_ad,
    SUM(CASE WHEN {$macro} THEN revenue ELSE 0 END) AS rev_macro
    FROM clicks GROUP BY source_id")->fetchAll(PDO::FETCH_ASSOC);
$bySource = [];
foreach ($agg as $a) {
    $bySource[(int) $a['source_id']] = $a;
}

// Источник 10: всё привязано (100+50 к real) → issue нет.
check('source 10: fully linked revenue', abs((float) $bySource[10]['rev_ad'] - 150.0) < 0.0001 && abs((float) $bySource[10]['rev'] - 150.0) < 0.0001);
// Источник 20: 160 дохода, 0 real, 100 из нераскрытых макросов → классифицируется как macros.
$unlinked20 = (float) $bySource[20]['rev'] - (float) $bySource[20]['rev_ad'];
$isMacros20 = ((float) $bySource[20]['rev_macro'] / max($unlinked20, 0.01)) > 0.5;
check('source 20: unlinked revenue dominated by macros → issue=macros', $unlinked20 > 159 && $isMacros20, json_encode($bySource[20]));
// Источник 30: 50 дохода без каких-либо ad-ключей → no_ad_id.
$unlinked30 = (float) $bySource[30]['rev'] - (float) $bySource[30]['rev_ad'];
$isMacros30 = ((float) $bySource[30]['rev_macro'] / max($unlinked30, 0.01)) > 0.5;
check('source 30: no keys at all → issue=no_ad_id', $unlinked30 > 49 && !$isMacros30, json_encode($bySource[30]));

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
