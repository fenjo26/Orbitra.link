<?php
require_once 'config.php';
require_once 'telegram_notify.php';
require_once __DIR__ . '/core/PostbackMacros.php';
require_once __DIR__ . '/core/CrmVault.php';
require_once __DIR__ . '/core/ConversionAttribution.php';

/**
 * Resolve the network's status word into one of the tracker's own status names.
 *
 * Matching is case-insensitive throughout: networks are inconsistent about it
 * (Dr. Cash sends "approved", others "Approved"/"APPROVED"), and a status that
 * differs only in case used to fall through to 'custom' — the conversion was
 * stored, but landed in none of the Sales/Leads/Rejected/Trash buckets, which is
 * exactly the "conversion recorded, every campaign counter still 0" symptom.
 */
function mapStatus($pdo, $status, $params)
{
    if (!$status)
        return null;

    $needle = strtolower(trim((string) $status));
    if ($needle === '')
        return null;

    $lowerList = static fn($csv) => array_map(
        static fn($v) => strtolower(trim((string) $v)),
        explode(',', (string) $csv)
    );

    $stmt = $pdo->query("SELECT name, status_values FROM conversion_types");
    $db_types = [];
    foreach ($stmt->fetchAll() as $row) {
        $db_types[$row['name']] = $lowerList($row['status_values']);
    }

    $known_types = ['lead', 'sale', 'rejected', 'registration', 'deposit', 'trash'];
    $all_known = array_merge($known_types, array_keys($db_types));

    // Сначала ищем по значениям статусов из БД
    foreach ($db_types as $typeName => $values) {
        if (in_array($needle, $values, true)) {
            return $typeName;
        }
    }

    // Если статус уже является встроенным типом, и нет переопределений, возвращаем его
    $self = null;
    foreach ($all_known as $type) {
        if (strtolower((string) $type) === $needle) {
            $self = $type;
            break;
        }
    }
    $mapped_status = $self ?? 'custom';

    // Проверяем правила маппинга в параметрах.
    // Своё собственное правило имеет приоритет: с trash_status=trash и
    // rejected_status=rejected,trash статус "trash" должен остаться trash,
    // а не достаться первому типу, чей список его упоминает.
    $ordered = $self !== null
        ? array_merge([$self], array_diff($all_known, [$self]))
        : $all_known;

    foreach ($ordered as $type) {
        $param_name = $type . '_status';
        if (!empty($params[$param_name])) {
            if (in_array($needle, $lowerList($params[$param_name]), true)) {
                return $type; // Нашли совпадение
            }
        }
    }

    return $mapped_status;
}

// subid is the tracker's own click id, handed to the network in the offer URL
// (&sub1={subid} for Dr. Cash) and handed back here. Networks vary on the
// parameter name and some append whitespace when the macro is unresolved, so
// the aliases are accepted and the value is trimmed before it becomes a lookup key.
$clickId = trim((string) ($_GET['subid'] ?? $_GET['clickid'] ?? $_GET['click_id'] ?? $_GET['sub_id'] ?? ''));
$clickId = $clickId !== '' ? $clickId : null;
$originalStatus = $_GET['status'] ?? $_GET['type'] ?? null;
$payout = $_GET['payout'] ?? $_GET['revenue'] ?? $_GET['profit'] ?? 0.00;
$currency = $_GET['currency'] ?? 'USD';
$tid = $_GET['tid'] ?? null;
$returnMsg = $_GET['return'] ?? null;
// Optional free-text rejection reason (e.g. "Invalid Phone") — stored on the
// CRM row so an anti-shaving dispute can quote the network's own wording.
$reason = trim((string) ($_GET['reason'] ?? $_GET['reject_reason'] ?? ''));

if (!$clickId) {
    die("Missing subid.");
}

if (!$originalStatus) {
    // В трекере логируется, но мы просто игнорируем
    die("Ignored: Missing status.");
}

// Проверяем существование клика.
// The whole row, not just the campaign: the conversion is stamped with the
// click's own dimensions (campaign, offer, sub_id_1..5, ip, ua) so that the
// conversions log and its campaign/offer filters see a linked record instead of
// a naked click_id. Nothing is created when the subid matches no click — an
// orphaned conversion is worse than a rejected postback.
$stmt = $pdo->prepare("
    SELECT id, campaign_id, offer_id, source_id, landing_id, ip, user_agent, parameters_json
    FROM clicks WHERE id = ? LIMIT 1
");
$stmt->execute([$clickId]);
$clickData = $stmt->fetch();
if (!$clickData) {
    die("Click ID not found in database.");
}
$campaignId = $clickData['campaign_id'];
// sub_id_1..5 here are the CLICK's parameters. $clickId (the incoming subid) is
// deliberately not among them — it is the tracker's key, not a sub dimension.
$clickAttribution = orbitraClickAttributionFromRow($clickData);

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

    // Ссылка на только что записанную конверсию. Одна выборка на весь запрос:
    // раньше её повторяли отдельно для S2S и отдельно для CAPI.
    if ($tid) {
        $cidStmt = $pdo->prepare("SELECT id FROM conversions WHERE click_id = ? AND tid = ? ORDER BY id DESC LIMIT 1");
        $cidStmt->execute([$clickId, $tid]);
    } else {
        $cidStmt = $pdo->prepare("SELECT id FROM conversions WHERE click_id = ? AND tid IS NULL ORDER BY id DESC LIMIT 1");
        $cidStmt->execute([$clickId]);
    }
    $conversionId = (int) ($cidStmt->fetchColumn() ?: 0) ?: null;

    // Перенос измерений клика на конверсию. Уже заполненные колонки не трогаем:
    // повторный постбек со сменой статуса не должен переписывать атрибуцию,
    // сделанную в момент создания конверсии.
    if ($conversionId !== null) {
        orbitraApplyConversionAttribution($pdo, $conversionId, $clickAttribution);
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

    // CRM vault reconciliation: every CRM row of this click moves to the
    // network's verdict, and rejected-with-valid-phone rows become shave
    // suspects. Best-effort by design — the postback that pays must not die
    // because the audit trail hiccuped.
    try {
        orbitraCrmSyncPostbackStatus($pdo, (string) $clickId, (string) $internalStatus, (float) $payout, $reason);
    } catch (\Exception $e) {
    }

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
        $cpStmt = $pdo->prepare("SELECT parameters_json, cost, revenue, offer_id FROM clicks WHERE id = ?");
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
        $clickOfferId = (string) ($cpRow['offer_id'] ?? '');

        // conversion_id для связи логов очереди с конверсией — уже определён выше.
        $convId = $conversionId;

        $enqueueStmt = $pdo->prepare("
            INSERT INTO s2s_postbacks_log
                (conversion_id, url, method, status, attempts, next_retry_at, postback_id, updated_at)
            VALUES (?, ?, ?, 'pending', 0, datetime('now'), ?, datetime('now'))
        ");

        foreach ($postbacks as $pb) {
            $statuses = array_map('trim', explode(',', strtolower($pb['statuses'])));
            if (!in_array(strtolower($internalStatus), $statuses)) {
                continue;
            }

            // Подстановка расширенного набора макросов.
            $macroValues = [
                '{subid}'       => $clickId,
                // Aliases the imported Keitaro source templates use.
                '{clickid}'     => $clickId,
                '{click_id}'    => $clickId,
                '{status}'      => $internalStatus,
                '{payout}'      => (string) $payout,
                '{conversion_revenue}' => (string) $payout,
                '{currency}'    => $currency,
                '{external_id}' => (string) $tid,
                '{tid}'         => (string) $tid,
                '{campaign_id}' => (string) $campaignId,
                '{offer_id}'    => $clickOfferId,
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

            // Keitaro-style status transform: {status: lead=reg sale=dep}.
            $url = orbitraApplyStatusTransform($url, $internalStatus);

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

    // Facebook Conversions API — отправка события в Meta по этой конверсии.
    // Ставим в ту же очередь, что и S2S: Meta иногда отвечает медленно, а партнёрка,
    // не дождавшаяся ответа на постбек, пришлёт его повторно и удвоит конверсию.
    try {
        $capiStmt = $pdo->prepare("SELECT * FROM campaign_pixels WHERE campaign_id = ? AND type IN ('facebook', 'tiktok') AND is_active = 1");
        $capiStmt->execute([$campaignId]);
        $capiPixels = $capiStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($capiPixels)) {
            require_once __DIR__ . '/core/FacebookConversions.php';
            require_once __DIR__ . '/core/TikTokConversions.php';

            $clickStmt = $pdo->prepare("
                SELECT id, ip, user_agent, referer, country_code, region, city, zipcode,
                       parameters_json, created_at, landing_id
                FROM clicks WHERE id = ? LIMIT 1
            ");
            $clickStmt->execute([$clickId]);
            $clickRow = $clickStmt->fetch(PDO::FETCH_ASSOC);

            if ($clickRow) {
                $clickParamsForCapi = json_decode($clickRow['parameters_json'] ?? '{}', true);
                if (!is_array($clickParamsForCapi)) {
                    $clickParamsForCapi = [];
                }

                // conversion_id тот же, что для S2S — по нему в логах очереди
                // видно, какая конверсия породила событие.
                $capiConversionId = $conversionId;

                // Макросы {campaign_url}/{landing_url} для event_source_url пикселя:
                // трекинговый URL кампании (домен + алиас) и фактический URL лендинга
                // этого клика. Оба lookup — по первичным ключам, best effort.
                $capiCampaignUrl = '';
                $capiLandingUrl = '';
                try {
                    $campUrlStmt = $pdo->prepare("
                        SELECT c.alias, d.name AS domain_name
                        FROM campaigns c LEFT JOIN domains d ON d.id = c.domain_id
                        WHERE c.id = ? LIMIT 1
                    ");
                    $campUrlStmt->execute([$campaignId]);
                    $campUrlRow = $campUrlStmt->fetch(PDO::FETCH_ASSOC);
                    if ($campUrlRow && !empty($campUrlRow['domain_name'])) {
                        $capiCampaignUrl = 'https://' . $campUrlRow['domain_name'] . '/' . ltrim((string) $campUrlRow['alias'], '/');
                    }
                } catch (\Throwable $e) {
                }
                if (!empty($clickRow['landing_id'])) {
                    try {
                        $landUrlStmt = $pdo->prepare("SELECT url FROM landings WHERE id = ? LIMIT 1");
                        $landUrlStmt->execute([(int) $clickRow['landing_id']]);
                        $capiLandingUrl = (string) ($landUrlStmt->fetchColumn() ?: '');
                    } catch (\Throwable $e) {
                    }
                }

                foreach ($capiPixels as $pixel) {
                    try {
                        $capiContext = [
                            'status'       => $internalStatus,
                            'payout'       => (float) $payout,
                            'currency'     => $currency,
                            'event_time'   => time(),
                            // Дедупликация с браузерным пикселем: одинаковый event_id
                            // для одного и того же события с обеих сторон.
                            'event_id'     => $clickId . '_' . $internalStatus . ($tid ? '_' . $tid : ''),
                            'click_params' => $clickParamsForCapi,
                            'extra'        => $_GET,
                            'campaign_url' => $capiCampaignUrl,
                            'landing_url'  => $capiLandingUrl,
                        ];
                        if (($pixel['type'] ?? '') === 'tiktok') {
                            TikTokConversions::enqueue($pdo, $pixel, $clickRow, $capiContext, $capiConversionId);
                        } else {
                            FacebookConversions::enqueue($pdo, $pixel, $clickRow, $capiContext, $capiConversionId);
                        }
                    } catch (\Throwable $pixelErr) {
                        // Один сломанный пиксель не должен ронять остальные.
                    }
                }
            }
        }
    }
    catch (\Throwable $e) {
    // CAPI — best effort: ответ на входящий постбек важнее.
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
