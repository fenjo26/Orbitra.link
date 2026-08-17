<?php
/**
 * tests/google_ads_tree_test.php
 *
 * Покрывает сборку дерева аккаунтов Google Ads для 1-Click подключения:
 * прямые аккаунты против MCC-потомков, приоритет прямого доступа, скрытые
 * кабинеты, форматирование CID и справочник менеджеров.
 *
 * Запуск из корня проекта:
 *
 *     php tests/google_ads_tree_test.php
 */

require_once __DIR__ . '/../core/google_ads_tree.php';

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

function accountByCid(array $tree, string $cid): ?array
{
    foreach ($tree['accounts'] as $account) {
        if ($account['cid'] === orbitraGoogleAdsCidDigits($cid)) {
            return $account;
        }
    }
    return null;
}

// ---- Форматирование CID -----------------------------------------------------

check('CID digits strips dashes and spaces', orbitraGoogleAdsCidDigits(' 123-456-7890 ') === '1234567890');
check('CID format renders 3-3-4', orbitraGoogleAdsFormatCid('1234567890') === '123-456-7890');
check('CID format leaves short ids alone', orbitraGoogleAdsFormatCid('12345') === '12345');

// ---- Смешанный сетап: прямые аккаунты + MCC с потомками ----------------------

$accessible = ['1111111111', '2222222222', '3333333333']; // аккаунт, аккаунт, MCC
$selfMeta = [
    '1111111111' => ['name' => 'Direct Shop', 'currency' => 'USD', 'timezone' => 'Europe/Berlin', 'manager' => false],
    '2222222222' => ['name' => 'Direct Leadgen', 'currency' => 'EUR', 'timezone' => 'Europe/Riga', 'manager' => false],
    '3333333333' => ['name' => 'Big MCC', 'currency' => 'USD', 'timezone' => 'America/New_York', 'manager' => true],
];
$managerChildren = [
    '3333333333' => [
        ['client_customer' => 'customers/4444444444', 'name' => 'Client A', 'currency_code' => 'USD', 'manager' => false, 'level' => 1],
        ['client_customer' => 'customers/5555555555', 'name' => 'Client B', 'currency_code' => 'PLN', 'manager' => false, 'level' => 2],
        // доступен и напрямую, и под MCC — остаётся прямым
        ['client_customer' => 'customers/2222222222', 'name' => 'Duplicate of Direct', 'currency_code' => 'USD', 'manager' => false, 'level' => 1],
        // скрытый кабинет не подключается
        ['client_customer' => 'customers/6666666666', 'name' => 'Hidden', 'manager' => false, 'level' => 1, 'hidden' => true],
        // сам MCC в списке своих потомков — пропускается
        ['client_customer' => 'customers/3333333333', 'name' => 'Big MCC', 'manager' => true, 'level' => 0],
    ],
];

$tree = orbitraGoogleAdsBuildAccountTree($accessible, $selfMeta, $managerChildren);

check('tree has 5 accounts (2 direct + MCC + 2 clients, hidden and self dropped)', count($tree['accounts']) === 5, json_encode($tree['accounts']));

$direct = accountByCid($tree, '2222222222');
check('directly accessible account stays direct (no login_customer_id)', $direct !== null && $direct['login_customer_id'] === '' && $direct['name'] === 'Direct Leadgen');

$mcc = accountByCid($tree, '3333333333');
check('MCC itself is in the accounts and flagged manager', $mcc !== null && $mcc['manager'] === true && $mcc['login_customer_id'] === '');

$clientA = accountByCid($tree, '4444444444');
check('MCC child gets login_customer_id of the MCC', $clientA !== null && $clientA['login_customer_id'] === '3333333333' && $clientA['name'] === 'Client A');
check('MCC child display cid formatted', $clientA !== null && $clientA['display_cid'] === '444-444-4444');
check('nested (level 2) client uses currency_code fallback', accountByCid($tree, '5555555555')['currency'] ?? '' === 'PLN');

check('hidden client dropped', accountByCid($tree, '6666666666') === null);
check('managers directory lists the MCC with its name', count($tree['managers']) === 1 && $tree['managers'][0]['cid'] === '3333333333' && $tree['managers'][0]['name'] === 'Big MCC');

// ---- Доступный CID без метаданных не подключается ----------------------------

$tree2 = orbitraGoogleAdsBuildAccountTree(['1111111111', '9999999999'], [
    '1111111111' => ['name' => 'Known', 'currency' => 'USD', 'manager' => false],
], []);
check('accessible CID without self metadata is dropped', count($tree2['accounts']) === 1 && accountByCid($tree2, '9999999999') === null);

// ---- Пустой вход ------------------------------------------------------------

$tree3 = orbitraGoogleAdsBuildAccountTree([], [], []);
check('empty input yields empty tree', $tree3['accounts'] === [] && $tree3['managers'] === []);

echo PHP_EOL . ($failed === 0 ? "PASSED: $passed checks" : "FAILED: $failed of " . ($passed + $failed)) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
