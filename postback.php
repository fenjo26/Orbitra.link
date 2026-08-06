<?php
require_once 'config.php';
require_once 'telegram_notify.php';

function mapStatus($pdo, $status, $params)
{
    if (!$status)
        return null;

    $stmt = $pdo->query("SELECT name, status_values FROM conversion_types");
    $db_types = [];
    foreach ($stmt->fetchAll() as $row) {
        $db_types[$row['name']] = array_map('trim', explode(',', $row['status_values']));
    }

    $known_types = ['lead', 'sale', 'rejected', 'registration', 'deposit', 'trash'];
    $all_known = array_merge($known_types, array_keys($db_types));

    // Сначала ищем по значениям статусов из БД
    foreach ($db_types as $typeName => $values) {
        if (in_array($status, $values)) {
            return $typeName;
        }
    }

    // Если статус уже является встроенным типом, и нет переопределений, возвращаем его
    $mapped_status = in_array($status, $all_known) ? $status : 'custom';

    // Проверяем правила маппинга в параметрах
    foreach ($all_known as $type) {
        $param_name = $type . '_status';
        if (!empty($params[$param_name])) {
            $mapped_values = array_map('trim', explode(',', $params[$param_name]));
            if (in_array($status, $mapped_values)) {
                return $type; // Нашли совпадение
            }
        }
    }

    return $mapped_status;
}

$clickId = $_GET['subid'] ?? $_GET['clickid'] ?? null;
$originalStatus = $_GET['status'] ?? $_GET['type'] ?? null;
$payout = $_GET['payout'] ?? $_GET['revenue'] ?? $_GET['profit'] ?? 0.00;
$currency = $_GET['currency'] ?? 'USD';
$tid = $_GET['tid'] ?? null;
$returnMsg = $_GET['return'] ?? null;

if (!$clickId) {
    die("Missing subid.");
}

if (!$originalStatus) {
    // В трекере логируется, но мы просто игнорируем
    die("Ignored: Missing status.");
}

// Проверяем существование клика
$stmt = $pdo->prepare("SELECT id, campaign_id FROM clicks WHERE id = ?");
$stmt->execute([$clickId]);
$clickData = $stmt->fetch();
if (!$clickData) {
    die("Click ID not found in database.");
}
$campaignId = $clickData['campaign_id'];

// Маппинг статуса
$internalStatus = mapStatus($pdo, $originalStatus, $_GET);

$stmt = $pdo->query("SELECT name FROM conversion_types");
$customTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);
$allKnown = array_merge(['lead', 'sale', 'rejected', 'registration', 'deposit', 'trash'], $customTypes);

if ($internalStatus === 'custom' && !in_array($originalStatus, $allKnown)) {
    // Если статус новый и не указана трансформация, возвращаем ошибку
    die("Ignored: Unknown status and no transformation specified.");
}

// Запись конверсии
try {
    if ($tid) {
        // Если передан tid, это может быть новая уникальная конверсия или апдейт существующей
        $stmt = $pdo->prepare("
            INSERT INTO conversions (click_id, tid, status, original_status, payout, currency) 
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(click_id, tid) DO UPDATE SET 
                status = excluded.status,
                original_status = excluded.original_status,
                payout = excluded.payout,
                currency = excluded.currency
        ");
        $stmt->execute([$clickId, $tid, $internalStatus, $originalStatus, $payout, $currency]);
    }
    else {
        // Если без tid, пытаемся найти конверсию без tid и обновить, либо создать новую
        $stmt = $pdo->prepare("SELECT id FROM conversions WHERE click_id = ? AND tid IS NULL");
        $stmt->execute([$clickId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $updateStmt = $pdo->prepare("
                UPDATE conversions 
                SET status = ?, original_status = ?, payout = ?, currency = ? 
                WHERE id = ?
            ");
            $updateStmt->execute([$internalStatus, $originalStatus, $payout, $currency, $existing['id']]);
        }
        else {
            $insertStmt = $pdo->prepare("
                INSERT INTO conversions (click_id, status, original_status, payout, currency) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([$clickId, $internalStatus, $originalStatus, $payout, $currency]);
        }
    }

    // Для совместимости обновляем общую revenue и is_conversion в таблице clicks
    // Подсчитываем тотал по клику, учитывая настройки типов конверсий (record_conversion, record_revenue)
    $stmt = $pdo->query("SELECT name, record_conversion, record_revenue FROM conversion_types");
    $ct = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $convStatuses = ['sale', 'deposit', 'lead'];
    $revStatuses = ['sale', 'deposit', 'lead', 'registration'];

    foreach ($ct as $row) {
        if ($row['record_conversion'])
            $convStatuses[] = $row['name'];
        if ($row['record_revenue'])
            $revStatuses[] = $row['name'];
    }

    $inConv = "'" . implode("','", array_map('addslashes', $convStatuses)) . "'";
    $inRev = "'" . implode("','", array_map('addslashes', $revStatuses)) . "'";

    $totalStats = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN status IN ($inConv) THEN 1 ELSE 0 END) as is_conv,
            SUM(CASE WHEN status IN ($inRev) AND payout > 0 THEN payout ELSE 0 END) as total_rev
        FROM conversions WHERE click_id = ?
    ");
    $totalStats->execute([$clickId]);
    $totals = $totalStats->fetch();

    $updateClick = $pdo->prepare("UPDATE clicks SET is_conversion = ?, revenue = ? WHERE id = ?");
    $updateClick->execute([$totals['is_conv'] > 0 ? 1 : 0, $totals['total_rev'] ?: 0, $clickId]);

    // Telegram bot notification
    try {
        notifyConversion($pdo, $clickId, $internalStatus, $payout, $campaignId, $currency);
    }
    catch (\Exception $e) {
    // Don't break postback flow on notification error
    }

    // Обработка S2S Postbacks для кампании — постановка в очередь надёжной доставки.
    // Сама HTTP-отправка выполняется воркером postback_queue_cron.php с retry/backoff,
    // чтобы медленный или упавший эндпоинт партнёрки не ломал ответ на входящий постбек
    // и не приводил к потере данных.
    try {
        $pbStmt = $pdo->prepare("SELECT * FROM campaign_postbacks WHERE campaign_id = ?");
        $pbStmt->execute([$campaignId]);
        $postbacks = $pbStmt->fetchAll();

        // Загружаем параметры исходного клика для подстановки макросов {sub_id_*}, {keyword} и т.д.
        $clickParams = [];
        $cpStmt = $pdo->prepare("SELECT parameters_json, cost, revenue FROM clicks WHERE id = ?");
        $cpStmt->execute([$clickId]);
        $cpRow = $cpStmt->fetch(PDO::FETCH_ASSOC);
        if ($cpRow && !empty($cpRow['parameters_json'])) {
            $decoded = json_decode($cpRow['parameters_json'], true);
            if (is_array($decoded)) {
                $clickParams = $decoded;
            }
        }
        $clickCost = (float) ($cpRow['cost'] ?? 0);
        $clickRevenue = (float) ($cpRow['revenue'] ?? 0);

        // Определяем conversion_id для связи логов очереди с конверсией.
        $convId = null;
        if ($tid) {
            $cidStmt = $pdo->prepare("SELECT id FROM conversions WHERE click_id = ? AND tid = ? ORDER BY id DESC LIMIT 1");
            $cidStmt->execute([$clickId, $tid]);
        } else {
            $cidStmt = $pdo->prepare("SELECT id FROM conversions WHERE click_id = ? AND tid IS NULL ORDER BY id DESC LIMIT 1");
            $cidStmt->execute([$clickId]);
        }
        $convId = (int) ($cidStmt->fetchColumn() ?: 0) ?: null;

        $enqueueStmt = $pdo->prepare("
            INSERT INTO s2s_postbacks_log
                (conversion_id, url, method, status, attempts, next_retry_at, postback_id)
            VALUES (?, ?, ?, 'pending', 0, datetime('now'), ?)
        ");

        foreach ($postbacks as $pb) {
            $statuses = array_map('trim', explode(',', strtolower($pb['statuses'])));
            if (!in_array(strtolower($internalStatus), $statuses)) {
                continue;
            }

            // Подстановка расширенного набора макросов.
            $macroValues = [
                '{subid}'       => $clickId,
                '{status}'      => $internalStatus,
                '{payout}'      => (string) $payout,
                '{currency}'    => $currency,
                '{external_id}' => (string) $tid,
                '{tid}'         => (string) $tid,
                '{campaign_id}' => (string) $campaignId,
                '{cost}'        => (string) $clickCost,
                '{revenue}'     => (string) $clickRevenue,
                '{profit}'      => (string) ($clickRevenue - $clickCost),
            ];
            // sub_id_1..30 и прочие сохранённые параметры клика.
            if (!empty($clickParams)) {
                foreach ($clickParams as $key => $val) {
                    $macroValues['{' . $key . '}'] = (string) $val;
                }
            }
            // urldecode обратный: макро-значения urlencode'им, как и раньше, чтобы URL был корректным.
            $url = $pb['url'];
            foreach ($macroValues as $macro => $value) {
                $url = str_replace($macro, urlencode($value), $url);
            }

            // SSRF Protection: предотвращаем запросы к локальным/приватным IP.
            // Проверку повторит и воркер (на случай смены DNS), но отсекаем очевидное уже при enqueue.
            $parsedUrl = parse_url($url);
            $host = $parsedUrl['host'] ?? '';
            if ($host) {
                $ip = gethostbyname($host);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    continue; // Skip restricted IPs
                }
            }

            $method = strtoupper($pb['method'] ?? 'GET') === 'POST' ? 'POST' : 'GET';
            $enqueueStmt->execute([$convId, $url, $method, (int) $pb['id']]);
        }
    }
    catch (\Exception $e) {
    // Игнорируем ошибки отправки S2S, чтобы не ломать ответ
    }

    if ($returnMsg) {
        echo htmlspecialchars($returnMsg);
    }
    else {
        echo "Postback recorded successfully.";
    }

}
catch (\Exception $e) {
    die("Database error: " . $e->getMessage());
}