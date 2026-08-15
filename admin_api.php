<?php
/**
 * admin_api.php — входящий API, совместимый с Keitaro Admin API v1.
 *
 * Dolphin (Настройки → Экспорт расходов → Keitaro) и Fbtool.pro (Расходы →
 * Keitaro) не имеют собственных интеграций с трекерами: они сами отправляют
 * расходы запросом Keitaro Admin API
 *
 *     POST /admin_api/v1/campaigns/{id}/update_costs
 *     Authorization: Bearer <ключ>
 *     {"start_date":"2026-08-15","end_date":"2026-08-15","cost":12.34,
 *      "currency":"USD","timezone":"Europe/Berlin",
 *      "filters":{"sub_id_4":"120212558973560058"}}
 *
 * Реализация этого одного роута делает оба сервиса работоспособными с Orbitra
 * без изменений на их стороне — в поля «адрес трекера» и «API-ключ» они пишут
 * адрес Orbitra и персональный ключ со страницы «Пользователи» (permissions:
 * write).
 *
 * Матчинг: клики кампании за [start_date..end_date]; каждый элемент `filters`
 * сужает выборку по параметру клика. Ключ пробуется как имя параметра как есть
 * (ad_id, adset_id, campaign_id, ...), плюс дефолты кейтаровского FB-шаблона
 * sub_id_3→adset_id (Dolphin) и sub_id_4→ad_id (Fbtool). Сумма конвертируется
 * в валюту трекера и делится поровну между матчнутыми кликами (flat CPC, та же
 * модель, что у core/CostImporter.php); повторный пуш того же периода
 * перезаписывает распределение, а не суммируется.
 *
 * Если кликов не нашлось, сумма parked в cost_records под скрытым подключением
 * engine='external_api', чтобы расход не терялся молча.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/CurrencyRates.php';

header('Content-Type: application/json; charset=utf-8');

function orbitraAdminApiFail(int $httpCode, string $message): void
{
    http_response_code($httpCode);
    // Keitaro отвечает {"success":false,...}; держим ту же форму.
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// --- Роутинг: единственный поддерживаемый путь -------------------------------
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// За .htaccess/nginx путь приходит как /admin_api/v1/..., при прямом вызове
// файла — /admin_api.php. Поддерживаем оба входа.
if (preg_match('~/admin_api\.php$~', $path)) {
    $path = '/admin_api/v1' . (string) ($_GET['route'] ?? '');
}
if (!preg_match('~^/admin_api/v1/campaigns/(\d+)/update_costs/?$~', $path, $m)) {
    orbitraAdminApiFail(404, 'Unknown endpoint. Expected POST /admin_api/v1/campaigns/{id}/update_costs');
}
$campaignId = (int) $m[1];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    orbitraAdminApiFail(405, 'Method not allowed. Use POST.');
}

// --- Авторизация: персональный ключ из user_api_keys (как в api.php) ---------
$apiKey = '';
$hdrAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($hdrAuth === '' && function_exists('apache_request_headers')) {
    $reqHeaders = apache_request_headers();
    $hdrAuth = $reqHeaders['Authorization'] ?? ($reqHeaders['authorization'] ?? '');
}
if (preg_match('/Bearer\s+(\S+)/i', (string) $hdrAuth, $mAuth)) {
    $apiKey = trim($mAuth[1]);
} elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
    $apiKey = trim($_SERVER['HTTP_X_API_KEY']);
}
if ($apiKey === '') {
    orbitraAdminApiFail(401, 'Missing API key. Send Authorization: Bearer <key> or X-Api-Key: <key>.');
}

try {
    $stmtKey = $pdo->prepare(
        "SELECT k.id, k.user_id, k.permissions, u.role
         FROM user_api_keys k JOIN users u ON u.id = k.user_id
         WHERE k.api_key = ? LIMIT 1"
    );
    $stmtKey->execute([$apiKey]);
    $keyRow = $stmtKey->fetch(PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    orbitraAdminApiFail(500, 'DB error during authentication.');
}

if (!$keyRow) {
    orbitraAdminApiFail(401, 'Invalid API key.');
}
// update_costs — write-действие: ключи с правами read не проходят, независимо
// от роли владельца (permissions — контракт ключа, а не пользователя).
$permissions = strtolower((string) ($keyRow['permissions'] ?? 'read'));
if (!in_array($permissions, ['write', 'full'], true)) {
    orbitraAdminApiFail(403, 'This API key is read-only. Create a key with write permissions.');
}
try {
    $pdo->prepare("UPDATE user_api_keys SET last_used = datetime('now') WHERE id = ?")
        ->execute([$keyRow['id']]);
} catch (\Exception $e) {
    // last_used — телеметрия, не повод ронять запрос.
}

// --- Payload ------------------------------------------------------------------
$rawBody = file_get_contents('php://input');
$body = json_decode((string) $rawBody, true);
if (!is_array($body)) {
    orbitraAdminApiFail(400, 'Request body must be JSON.');
}

// Keitaro шлёт даты и как "YYYY-MM-DD", и как "YYYY-MM-DD HH:MM:SS".
$startDate = substr((string) ($body['start_date'] ?? ''), 0, 10);
$endDate = substr((string) ($body['end_date'] ?? $body['start_date'] ?? ''), 0, 10);
$cost = (float) ($body['cost'] ?? 0);
$currency = strtoupper((string) ($body['currency'] ?? 'USD'));
$filters = is_array($body['filters'] ?? null) ? $body['filters'] : [];

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    orbitraAdminApiFail(400, 'start_date and end_date are required (YYYY-MM-DD).');
}
if ($endDate < $startDate) {
    orbitraAdminApiFail(400, 'end_date is before start_date.');
}
if ($cost < 0) {
    orbitraAdminApiFail(400, 'cost must be >= 0.');
}

// --- Кампания ------------------------------------------------------------------
try {
    $stmtCamp = $pdo->prepare("SELECT id, name FROM campaigns WHERE id = ? AND is_archived = 0");
    $stmtCamp->execute([$campaignId]);
    $campaign = $stmtCamp->fetch(PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    orbitraAdminApiFail(500, 'DB error.');
}
if (!$campaign) {
    orbitraAdminApiFail(404, "Campaign {$campaignId} not found.");
}

// --- Валюта --------------------------------------------------------------------
$trackerCurrency = CurrencyRates::trackerCurrency($pdo);
$amount = CurrencyRates::convert($pdo, $cost, $currency, $trackerCurrency);

// --- Выборка кликов -------------------------------------------------------------
// Кандидаты имён параметров для ключа фильтра: сам ключ + дефолты FB-шаблона
// Keitaro, под которые настроены Dolphin (adset.id → Sub ID 3) и Fbtool
// (ad.id → sub_id_4). Клик матчится, если хоть один кандидат равен значению.
$keitaroSubAliases = [
    'sub_id_3' => 'adset_id',
    'sub_id_4' => 'ad_id',
];

$where = ['campaign_id = ?', 'date(created_at) >= ?', 'date(created_at) <= ?'];
$args = [$campaignId, $startDate, $endDate];

foreach ($filters as $fKey => $fValue) {
    if (!is_string($fKey) || $fValue === null || $fValue === '') {
        continue;
    }
    $candidates = [$fKey];
    if (isset($keitaroSubAliases[$fKey])) {
        $candidates[] = $keitaroSubAliases[$fKey];
    }
    $orParts = [];
    foreach (array_unique($candidates) as $cand) {
        // json_extract path не биндится — чистим имя от всего лишнего.
        $safe = preg_replace('/[^A-Za-z0-9_]/', '', $cand);
        if ($safe === '') {
            continue;
        }
        $orParts[] = "json_extract(parameters_json, '$.{$safe}') = ?";
        $args[] = (string) $fValue;
    }
    if ($orParts) {
        $where[] = '(' . implode(' OR ', $orParts) . ')';
    }
}

try {
    $stmtClicks = $pdo->prepare('SELECT id FROM clicks WHERE ' . implode(' AND ', $where));
    $stmtClicks->execute($args);
    $clickIds = $stmtClicks->fetchAll(PDO::FETCH_COLUMN);
} catch (\Exception $e) {
    orbitraAdminApiFail(500, 'DB error while matching clicks.');
}

if (!empty($clickIds) && $amount > 0) {
    // Flat CPC: та же модель, что у CostImporter — дневной расход делится поровну
    // между кликами. Прямое присваивание (не +=) даёт повторному пушу того же
    // периода семантику replace, как в Keitaro.
    $cpc = $amount / count($clickIds);
    $setCost = $pdo->prepare('UPDATE clicks SET cost = ? WHERE id = ?');
    foreach ($clickIds as $clickId) {
        $setCost->execute([$cpc, $clickId]);
    }
    echo json_encode([
        'success'      => true,
        'campaign_id'  => $campaignId,
        'clicks'       => count($clickIds),
        'cost'         => $amount,
        'currency'     => $trackerCurrency,
    ]);
    exit;
}

// --- Парковка несовпавшего расхода ---------------------------------------------
// Отчёты читают clicks.cost, поэтому в статистику этот расход не попадёт — но
// он сохраняется в cost_records под скрытым подключением external_api, чтобы
// его можно было найти и разобраться (совпал ли фильтр, в тот ли день).
try {
    $connId = null;
    $stmtConn = $pdo->prepare("SELECT id FROM aggregator_connections WHERE engine = 'external_api' LIMIT 1");
    $stmtConn->execute();
    $connId = $stmtConn->fetchColumn();
    if (!$connId) {
        $pdo->prepare("INSERT INTO aggregator_connections (name, engine, auth_type, credentials_json, is_active) VALUES ('External cost API (Dolphin/Fbtool)', 'external_api', 'api_key', '{}', 0)")
            ->execute();
        $connId = (int) $pdo->lastInsertId();
    }

    // Идемпотентность: повторный пуш того же периода и фильтра обновляет запись.
    $externalId = 'ext_' . $campaignId . '_' . $startDate . '_' . $endDate . '_' . md5(json_encode($filters));
    $stmtFind = $pdo->prepare('SELECT id FROM cost_records WHERE connection_id = ? AND external_id = ?');
    $stmtFind->execute([$connId, $externalId]);
    $existing = $stmtFind->fetchColumn();

    $rawJson = json_encode([
        'source'      => 'admin_api update_costs',
        'campaign_id' => $campaignId,
        'filters'     => $filters,
        'timezone'    => $body['timezone'] ?? null,
        'start_date'  => $startDate,
        'end_date'    => $endDate,
        'pushed_at'   => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE);

    if ($existing) {
        $pdo->prepare('UPDATE cost_records SET amount = ?, currency = ?, raw_json = ? WHERE id = ?')
            ->execute([$amount, $trackerCurrency, $rawJson, $existing]);
    } else {
        $pdo->prepare('INSERT INTO cost_records (connection_id, external_id, amount, currency, click_date, raw_json, is_matched) VALUES (?,?,?,?,?,?,0)')
            ->execute([$connId, $externalId, $amount, $trackerCurrency, $startDate, $rawJson]);
    }
} catch (\Exception $e) {
    orbitraAdminApiFail(500, 'DB error while storing unmatched cost.');
}

echo json_encode([
    'success'     => true,
    'campaign_id' => $campaignId,
    'clicks'      => 0,
    'cost'        => $amount,
    'currency'    => $trackerCurrency,
    'note'        => 'No clicks matched — cost parked without attribution.',
]);
