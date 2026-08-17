<?php
/**
 * core/google_ads_tree.php
 *
 * Чистая сборка дерева Google-аккаунтов для 1-Click подключения (Integrations →
 * Google Ads Costs). Отдельная от api.php точка входа — чтобы тест
 * tests/google_ads_tree_test.php прогонял логику без загрузки роутера.
 *
 * Входные данные приходят из двух Google Ads API вызовов в oauth-callback:
 *   - listAccessibleCustomers → CID'ы, доступные логину напрямую;
 *   - GAQL "FROM customer" по каждому CID → имя/валюта/менеджер;
 *   - GAQL "FROM customer_client" по каждому MCC → все потомки (любой уровень).
 *
 * Правила дерева:
 *   - Прямые аккаунты и сами MCC — верхний уровень, login_customer_id пуст
 *     (обращение к API идёт от своего имени).
 *   - Кабинеты, найденные только внутри MCC, получают login_customer_id того
 *     доступного менеджера, из запроса которого они найдены — это и есть
 *     заголовок login-customer-id для их синка.
 *   - Кабинет, доступный и напрямую, и через MCC, остаётся прямым.
 *   - Скрытые (customer_client.hidden) и тестовые кабинеты не подключаются.
 */

/**
 * Нормализует CID: только цифры («123-456-7890» → «1234567890»).
 */
function orbitraGoogleAdsCidDigits($cid): string
{
    return preg_replace('/\D/', '', (string) $cid);
}

/**
 * Форматирует CID для показа: «1234567890» → «123-456-7890».
 */
function orbitraGoogleAdsFormatCid(string $cid): string
{
    $digits = orbitraGoogleAdsCidDigits($cid);
    if (strlen($digits) !== 10) {
        return $digits;
    }
    return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4);
}

/**
 * @param string[] $accessibleCids   CID'ы из listAccessibleCustomers (любой формат)
 * @param array    $selfMeta         cid => метаданные GAQL "FROM customer":
 *                                   {name, currency, timezone, manager: bool}
 * @param array    $managerChildren  managerCid => список строк GAQL "FROM customer_client":
 *                                   {cid|client_customer, name, currency, timezone, manager, level, hidden}
 * @return array{managers: array<int,array{cid:string,display_cid:string,name:string,currency:string}>,
 *               accounts: array<int,array{cid:string,display_cid:string,name:string,currency:string,timezone:string,manager:bool,login_customer_id:string}>}
 *         accounts — плоский список; группировку по MCC фронтенд делает сам
 *         (по login_customer_id), managers — справочник имён MCC для заголовков групп.
 */
function orbitraGoogleAdsBuildAccountTree(array $accessibleCids, array $selfMeta, array $managerChildren): array
{
    $accounts = [];

    $addAccessible = function (string $cid, array $meta) use (&$accounts): void {
        $cid = orbitraGoogleAdsCidDigits($cid);
        if ($cid === '' || isset($accounts[$cid])) {
            return;
        }
        $accounts[$cid] = [
            'cid'               => $cid,
            'display_cid'       => orbitraGoogleAdsFormatCid($cid),
            'name'              => substr(trim((string) ($meta['name'] ?? '')) ?: $cid, 0, 190),
            'currency'          => strtoupper(trim((string) ($meta['currency'] ?? ''))),
            'timezone'          => trim((string) ($meta['timezone'] ?? '')),
            'manager'           => !empty($meta['manager']),
            'login_customer_id' => '',
        ];
    };

    // Прямые аккаунты и сами MCC — с метаданными из self-запроса. CID без
    // метаданных (self-запрос не удался) к подключению не предлагаем.
    foreach ($accessibleCids as $cid) {
        $cidDigits = orbitraGoogleAdsCidDigits($cid);
        if ($cidDigits !== '' && isset($selfMeta[$cidDigits]) && is_array($selfMeta[$cidDigits])) {
            $addAccessible($cidDigits, $selfMeta[$cidDigits]);
        }
    }

    // Потомки MCC: client_customer приходит как "customers/1234567890".
    foreach ($managerChildren as $managerCid => $rows) {
        $managerCid = orbitraGoogleAdsCidDigits($managerCid);
        if ($managerCid === '' || !is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clientCustomer = (string) ($row['cid'] ?? $row['client_customer'] ?? '');
            // «customers/1234567890» → «1234567890»
            if (strpos($clientCustomer, '/') !== false) {
                $clientCustomer = substr($clientCustomer, strrpos($clientCustomer, '/') + 1);
            }
            $cid = orbitraGoogleAdsCidDigits($clientCustomer);
            if ($cid === '' || $cid === $managerCid) {
                continue;
            }
            // Доступен напрямую — прямым и остаётся (см. правила дерева).
            if (isset($accounts[$cid])) {
                continue;
            }
            if (!empty($row['hidden']) || !empty($row['test_account'])) {
                continue;
            }
            $accounts[$cid] = [
                'cid'               => $cid,
                'display_cid'       => orbitraGoogleAdsFormatCid($cid),
                'name'              => substr(trim((string) ($row['name'] ?? $row['descriptive_name'] ?? '')) ?: $cid, 0, 190),
                'currency'          => strtoupper(trim((string) ($row['currency'] ?? $row['currency_code'] ?? ''))),
                'timezone'          => trim((string) ($row['timezone'] ?? $row['time_zone'] ?? '')),
                'manager'           => !empty($row['manager']),
                'login_customer_id' => $managerCid,
            ];
        }
    }

    $managers = [];
    foreach ($accounts as $cid => $account) {
        if ($account['manager']) {
            $managers[] = [
                'cid'         => $account['cid'],
                'display_cid' => $account['display_cid'],
                'name'        => $account['name'],
                'currency'    => $account['currency'],
            ];
        }
    }

    // Стабильный порядок: прямые аккаунты, затем MCC-группы.
    ksort($accounts, SORT_NUMERIC);

    return [
        'managers' => $managers,
        'accounts' => array_values($accounts),
    ];
}
