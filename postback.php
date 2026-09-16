<?php
require_once 'config.php';
require_once 'telegram_notify.php';
require_once __DIR__ . '/core/PostbackMacros.php';
require_once __DIR__ . '/core/CrmVault.php';
require_once __DIR__ . '/core/ConversionAttribution.php';
require_once __DIR__ . '/core/PostbackDelivery.php';

/**
 * Exit helper for postback responses.
 *
 * Sets the proper HTTP status code, emits the response body, logs the request
 * to incoming_postbacks_log, and exits. When running inside the pixel GIF path
 * (/pixel.gif?action=conversion), validation errors become 200 GIF responses,
 * but server failures keep their 5xx status so lost writes are not acknowledged.
 * The shutdown function in index.php still returns a valid GIF in either case.
 *
 * Logging is best-effort: a broken audit trail must never break a paying postback.
 *
 * @param int    $statusCode HTTP status code (200, 400, 404, 500, 503)
 * @param string $message    Response body message
 * @param array  $logContext Context data for the log entry
 * @param bool   $isSuccess  Whether this is a successful postback (affects exit vs return)
 */
function orbitraPostbackExit($statusCode, $message, $logContext = [], $isSuccess = false)
{
    global $pdo, $orbitraIncomingLogId;

    http_response_code($statusCode);
    echo $message;

    // Update the incoming postback log entry with the outcome
    if (!empty($orbitraIncomingLogId) && !empty($pdo)) {
        try {
            $updateFields = ['result = ?, error = ?'];
            $updateValues = [$logContext['result'] ?? 'rejected', $logContext['error'] ?? null];

            // Add matched/campaign_id/status fields if available
            if (isset($logContext['matched'])) {
                $updateFields[] = 'matched = ?';
                $updateValues[] = $logContext['matched'] ? 1 : 0;
            }
            if (isset($logContext['campaign_id'])) {
                $updateFields[] = 'campaign_id = ?';
                $updateValues[] = $logContext['campaign_id'];
            }
            if (isset($logContext['original_status'])) {
                $updateFields[] = 'original_status = ?';
                $updateValues[] = $logContext['original_status'];
            }
            if (isset($logContext['status'])) {
                $updateFields[] = 'status = ?';
                $updateValues[] = $logContext['status'];
            }
            if (isset($logContext['payout'])) {
                $updateFields[] = 'payout = ?';
                $updateValues[] = $logContext['payout'];
            }
            if (isset($logContext['currency'])) {
                $updateFields[] = 'currency = ?';
                $updateValues[] = $logContext['currency'];
            }
            if (isset($logContext['conversion_id'])) {
                $updateFields[] = 'conversion_id = ?';
                $updateValues[] = $logContext['conversion_id'];
            }

            $updateValues[] = $orbitraIncomingLogId;
            $sql = "UPDATE incoming_postbacks_log SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($updateValues);
        } catch (\Throwable $e) {
            // Logging failure must not break the postback response
        }
    }

    // When called from /pixel.gif?action=conversion, we must return instead of exit
    // to allow the shutdown function in index.php to replace the response with a GIF.
    // The ORBITRA_INSIDE_PIXEL_GIF constant is set by index.php before requiring this file.
    if (defined('ORBITRA_INSIDE_PIXEL_GIF') && ORBITRA_INSIDE_PIXEL_GIF) {
        // Return control to index.php; its shutdown function will replace our output
        return;
    }

    exit;
}

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

// Reuses the tracker's existing FX helper (core/CurrencyRates.php, already
// used by CostImporter/admin_api.php for ad-spend currency conversion) so
// conversion payouts get the same treatment — see the usage below for why.
require_once __DIR__ . '/core/CurrencyRates.php';

// Incoming postback log entry ID — will be created on first request and updated on exit
$orbitraIncomingLogId = null;

// Log the incoming request FIRST, before any validation — this ensures a fatal
// mid-request error still leaves the raw request visible in the logs.
// Best-effort: if logging fails, continue without breaking the postback.
$source = defined('ORBITRA_INSIDE_PIXEL_GIF') && ORBITRA_INSIDE_PIXEL_GIF ? 'pixel' : 'postback';
try {
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/postback.php';
    $paramsJson = json_encode($_GET, JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
        INSERT INTO incoming_postbacks_log
            (created_at, remote_ip, request_uri, params_json, source)
        VALUES
            (datetime('now'), ?, ?, ?, ?)
    ");
    $stmt->execute([$remoteIp, $requestUri, $paramsJson, $source]);
    $orbitraIncomingLogId = (int) $pdo->lastInsertId();
} catch (\Throwable $e) {
    // Non-critical: the postback continues without logging
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

// Convert into the account's own base currency (settings.currency) if the
// postback's currency is different — otherwise every report/dashboard sum
// silently mixes currencies as if they were equal. Original amount +
// currency + rate are kept on the click's own parameters_json below (after
// click_id is confirmed real) purely for audit — $payout/$currency
// themselves become the converted values and flow into everything below
// that reads them (the conversions row, and the CAPI payout/currency
// TikTok/Meta receive). $currency is only reassigned once both sides of the
// pair are actually known — CurrencyRates::convert() returns the amount
// unchanged (not null) when a rate is missing, so checking its rate table
// directly is what keeps an unconverted amount from being mislabeled as
// already-converted.
$orbitraOrigPayout = $payout;
$orbitraOrigCurrency = $currency;
$orbitraFxRateUsed = null;
$orbitraTrackerCurrency = CurrencyRates::trackerCurrency($pdo);
$orbitraPostbackCurrency = strtoupper((string) $currency);
if ($orbitraPostbackCurrency !== $orbitraTrackerCurrency) {
    $orbitraFxRates = CurrencyRates::table($pdo);
    if (isset($orbitraFxRates[$orbitraPostbackCurrency], $orbitraFxRates[$orbitraTrackerCurrency])) {
        $orbitraConvertedPayout = CurrencyRates::convert($pdo, (float) $payout, $orbitraPostbackCurrency, $orbitraTrackerCurrency);
        $orbitraFxRateUsed = ((float) $payout) > 0 ? $orbitraConvertedPayout / ((float) $payout) : null;
        $payout = round($orbitraConvertedPayout, 2);
        $currency = $orbitraTrackerCurrency;
    }
}
$tid = isset($_GET['tid']) && (string) $_GET['tid'] !== '' ? (string) $_GET['tid'] : null;
$returnMsg = $_GET['return'] ?? null;
// Optional free-text rejection reason (e.g. "Invalid Phone") — stored on the
// CRM row so an anti-shaving dispute can quote the network's own wording.
$reason = trim((string) ($_GET['reason'] ?? $_GET['reject_reason'] ?? ''));

// Store click_id for logging even if we reject later
try {
    if ($orbitraIncomingLogId && $clickId) {
        $pdo->prepare("UPDATE incoming_postbacks_log SET click_id = ? WHERE id = ?")
            ->execute([$clickId, $orbitraIncomingLogId]);
    }
} catch (\Throwable $e) {
    // Non-critical
}

if (!$clickId) {
    orbitraPostbackExit(400, "Missing subid.", ['result' => 'rejected', 'error' => 'Missing subid']);
    return;
}

if (!$originalStatus) {
    // В трекере логируется, но мы просто игнорируем
    orbitraPostbackExit(400, "Ignored: Missing status.", ['result' => 'rejected', 'error' => 'Missing status']);
    return;
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
$stmt->closeCursor();
if (!$clickData) {
    orbitraPostbackExit(404, "Click ID not found in database.", [
        'result' => 'rejected',
        'error' => 'Click ID not found in database',
        'click_id' => $clickId
    ]);
    return;
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

// Record first, classify second. An unmapped status is stored with status='custom'
// (or a slug of the original) and original_status kept verbatim. It responds 200
// — the network did nothing wrong — and does not pollute money or counters until
// an operator decides what it means.
// Only genuinely empty input (no subid, no status) returns 400, which was handled above.

// Persist the conversion and its eligible CAPI events together. No notification,
// HTTP call or DNS lookup may run while the SQLite write transaction is held.
$requestEventTime = time();
try {
    [$conversionResult, $conversionId] = orbitraPostbackTransaction($pdo, function () use (
        $pdo, $tid, $clickId, $internalStatus, $originalStatus, $payout, $currency,
        $clickAttribution, $campaignId, $requestEventTime,
        $orbitraOrigCurrency, $orbitraOrigPayout, $orbitraFxRateUsed
    ): array {
        // Read current parameters only after acquiring the writer lock. A late
        // browser fbp/fbc update must not be overwritten by the earlier lookup.
        // The FX audit now rolls back with its conversion on a failed enqueue.
        if ($orbitraOrigCurrency !== $currency) {
            $fxStmt = $pdo->prepare('SELECT parameters_json FROM clicks WHERE id = ?');
            $fxStmt->execute([$clickId]);
            $fxParams = json_decode((string) ($fxStmt->fetchColumn() ?: '{}'), true);
            $fxStmt->closeCursor();
            if (!is_array($fxParams)) {
                $fxParams = [];
            }
            $fxParams['fx_orig_payout'] = $orbitraOrigPayout;
            $fxParams['fx_orig_currency'] = $orbitraOrigCurrency;
            $fxParams['fx_rate_used'] = $orbitraFxRateUsed;
            $fxParams['fx_converted_payout'] = $payout;
            $pdo->prepare('UPDATE clicks SET parameters_json = ? WHERE id = ?')
                ->execute([json_encode($fxParams, JSON_UNESCAPED_UNICODE), $clickId]);
        }

        $conversionResult = 'recorded';
        if ($tid !== null) {
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

            // Check if this was an update (row already existed)
            $checkStmt = $pdo->prepare("SELECT changes() as changed");
            $checkStmt->execute();
            $changed = $checkStmt->fetchColumn();
            $checkStmt->closeCursor();
            if ($changed > 0) {
                // Check if the row was updated vs inserted
                $existsStmt = $pdo->prepare("SELECT id FROM conversions WHERE click_id = ? AND tid = ?");
                $existsStmt->execute([$clickId, $tid]);
                $exists = $existsStmt->fetch();
                $existsStmt->closeCursor();
                if ($exists) {
                    $conversionResult = 'updated';
                }
            }
        }
        else {
            // Если без tid, пытаемся найти конверсию без tid и обновить, либо создать новую
            $stmt = $pdo->prepare("SELECT id FROM conversions WHERE click_id = ? AND tid IS NULL");
            $stmt->execute([$clickId]);
            $existing = $stmt->fetch();
            $stmt->closeCursor();

            if ($existing) {
                $updateStmt = $pdo->prepare("
                    UPDATE conversions
                    SET status = ?, original_status = ?, payout = ?, currency = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([$internalStatus, $originalStatus, $payout, $currency, $existing['id']]);
                $conversionResult = 'updated';
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
        if ($tid !== null) {
            $cidStmt = $pdo->prepare("SELECT id FROM conversions WHERE click_id = ? AND tid = ? ORDER BY id DESC LIMIT 1");
            $cidStmt->execute([$clickId, $tid]);
        } else {
            $cidStmt = $pdo->prepare("SELECT id FROM conversions WHERE click_id = ? AND tid IS NULL ORDER BY id DESC LIMIT 1");
            $cidStmt->execute([$clickId]);
        }
        $conversionId = (int) ($cidStmt->fetchColumn() ?: 0) ?: null;
        $cidStmt->closeCursor();

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
        $totalStats->closeCursor();

        $updateClick = $pdo->prepare("UPDATE clicks SET is_conversion = ?, revenue = ? WHERE id = ?");
        $updateClick->execute([$totals['is_conv'] > 0 ? 1 : 0, $totals['total_rev'] ?: 0, $clickId]);

        // Facebook Conversions API — отправка события в Meta по этой конверсии.
        // Ставим в ту же очередь, что и S2S, чтобы не ждать HTTP-ответа Meta.
        // Повторный постбек переиспользует уже записанное намерение отправки.
        $capiStmt = $pdo->prepare("SELECT * FROM campaign_pixels WHERE campaign_id = ? AND type IN ('facebook', 'tiktok') AND is_active = 1");
        $capiStmt->execute([$campaignId]);
        $capiPixels = $capiStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($capiPixels)) {
            require_once __DIR__ . '/core/FacebookConversions.php';
            require_once __DIR__ . '/core/TikTokConversions.php';
            require_once __DIR__ . '/core/landing_path.php';

            $clickStmt = $pdo->prepare("
                SELECT id, ip, user_agent, referer, country_code, region, city, zipcode,
                       parameters_json, created_at, landing_id, offer_id
                FROM clicks WHERE id = ? LIMIT 1
            ");
            $clickStmt->execute([$clickId]);
            $clickRow = $clickStmt->fetch(PDO::FETCH_ASSOC);
            $clickStmt->closeCursor();

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
                $capiLandingUrl = trim((string) ($clickParamsForCapi['landing_page_url'] ?? ''));
                try {
                    $campUrlStmt = $pdo->prepare("
                        SELECT c.alias, d.name AS domain_name
                        FROM campaigns c LEFT JOIN domains d ON d.id = c.domain_id
                        WHERE c.id = ? LIMIT 1
                    ");
                    $campUrlStmt->execute([$campaignId]);
                    $campUrlRow = $campUrlStmt->fetch(PDO::FETCH_ASSOC);
                    $campUrlStmt->closeCursor();
                    if ($campUrlRow && !empty($campUrlRow['domain_name'])) {
                        $capiCampaignUrl = 'https://' . $campUrlRow['domain_name'] . '/' . ltrim((string) $campUrlRow['alias'], '/');
                    }
                } catch (\Throwable $e) {
                }
                if ($capiLandingUrl === '' && !empty($clickRow['landing_id'])) {
                    try {
                        $landUrlStmt = $pdo->prepare("SELECT url FROM landings WHERE id = ? LIMIT 1");
                        $landUrlStmt->execute([(int) $clickRow['landing_id']]);
                        $capiLandingUrl = (string) ($landUrlStmt->fetchColumn() ?: '');
                        $landUrlStmt->closeCursor();
                    } catch (\Throwable $e) {
                    }
                }

                // Explicit {offer_url} means the operator confirms that the
                // configured offer destination is the event page. Resolve the
                // offer saved on THIS click, never another campaign offer or
                // an offer_id supplied by the postback. No redirects/network
                // calls: this is current configuration, not a historical URL.
                $capiOfferUrl = '';
                foreach ($capiPixels as $capiPixel) {
                    if (($capiPixel['type'] ?? '') !== 'facebook') {
                        continue;
                    }
                    $capiUrlConfig = (string) ($capiPixel['event_source_url'] ?? '');
                    $capiUrlMap = json_decode($capiUrlConfig, true);
                    $capiUrlTemplates = is_array($capiUrlMap) ? $capiUrlMap : [$capiUrlConfig];
                    if (!array_filter($capiUrlTemplates, static fn($value): bool =>
                        is_string($value) && str_contains($value, '{offer_url}'))) {
                        continue;
                    }
                    if (!empty($clickRow['offer_id'])) {
                        try {
                            require_once __DIR__ . '/core/OfferUrl.php';
                            $capiOffer = orbitraGetOfferDestination($pdo, (int) $clickRow['offer_id']);
                            // Local offer routes are not represented by offers.url.
                            if ($capiOffer && empty($capiOffer['is_local'])) {
                                $capiOfferUrl = orbitraResolveOfferUrlMacros(
                                    $capiOffer['url'], $clickId, (int) $clickRow['offer_id'], $clickParamsForCapi,
                                    ['ip' => $clickRow['ip'] ?? '', 'country' => $clickRow['country_code'] ?? '']
                                );
                            }
                        } catch (\Throwable $e) {
                            // Missing context omits the URL; it never drops the event.
                        }
                    }
                    break;
                }

                // content_id for CAPI events (TikTok flags its absence as a
                // Critical diagnostic; Meta uses it for catalog/dynamic-ads
                // matching too) — sourced from the landing's own _config.php
                // ($products = N;), the same value the landing's own client-side
                // pixel already sends. Read-only regex over the file, never
                // include()'d, so the file's own session_start()/etc. never runs
                // in this server-to-server request.
                $capiContentId = '';
                if (!empty($clickRow['landing_id'])) {
                    try {
                        $contentIdDir = orbitraLandingContentDir(orbitraLandingDir($pdo, (int) $clickRow['landing_id']));
                        $contentIdConfigPath = $contentIdDir . '/_config.php';
                        if (is_file($contentIdConfigPath)) {
                            $contentIdSrc = file_get_contents($contentIdConfigPath, false, null, 0, 16384);
                            if ($contentIdSrc !== false
                                && preg_match('/\$products\s*=\s*([\'"]?)([A-Za-z0-9_-]+)\1\s*;/', $contentIdSrc, $contentIdMatch)) {
                                $capiContentId = $contentIdMatch[2];
                            }
                        }
                    } catch (\Throwable $e) {
                    }
                }

                foreach ($capiPixels as $pixel) {
                    $capiContext = [
                        'status'       => $internalStatus,
                        'payout'       => (float) $payout,
                        'currency'     => $currency,
                        'event_time'   => $requestEventTime,
                        // Дедупликация с браузерным пикселем: одинаковый event_id
                        // для одного и того же события с обеих сторон.
                        'event_id'     => $clickId . '_' . $internalStatus . ($tid !== null ? '_' . $tid : ''),
                        'click_params' => $clickParamsForCapi,
                        'extra'        => $_GET,
                        'campaign_url' => $capiCampaignUrl,
                        'landing_url'  => $capiLandingUrl,
                        'offer_url'    => $capiOfferUrl,
                        'content_id'   => $capiContentId,
                    ];
                    orbitraPostbackEnqueueCapi($pdo, $pixel, $clickRow, $capiContext, $capiConversionId);
                }
            }
        }
        return [$conversionResult, $conversionId];
    });

    // CRM vault reconciliation: every CRM row of this click moves to the
    // network's verdict, and rejected-with-valid-phone rows become shave
    // suspects. Best-effort by design — the postback that pays must not die
    // because the audit trail hiccuped.
    try {
        orbitraCrmSyncPostbackStatus($pdo, (string) $clickId, (string) $internalStatus, (float) $payout, $reason);
    } catch (\Throwable $e) {
    }

    // Telegram bot notification
    try {
        notifyConversion($pdo, $clickId, $internalStatus, $payout, $campaignId, $currency);
    }
    catch (\Throwable $e) {
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
        $pbStmt->closeCursor();

        // Загружаем параметры исходного клика для подстановки макросов {sub_id_*}, {keyword} и т.д.
        $clickParams = [];
        $cpStmt = $pdo->prepare("SELECT parameters_json, cost, revenue, offer_id FROM clicks WHERE id = ?");
        $cpStmt->execute([$clickId]);
        $cpRow = $cpStmt->fetch(PDO::FETCH_ASSOC);
        $cpStmt->closeCursor();
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
    catch (\Throwable $e) {
    // Игнорируем ошибки отправки S2S, чтобы не ломать ответ
    }

    orbitraPostbackExit(200, $returnMsg ? htmlspecialchars($returnMsg) : "Postback recorded successfully.", [
        'result' => $conversionResult,
        'matched' => true,
        'campaign_id' => $campaignId,
        'original_status' => $originalStatus,
        'status' => $internalStatus,
        'payout' => $payout,
        'currency' => $currency,
        'conversion_id' => $conversionId,
        'error' => null
    ], true);
    return;

}
catch (\Throwable $e) {
    // The durable write failed. Do not acknowledge an event that has no outbox
    // row. Keep a diagnostic even when SQLite itself cannot accept log writes.
    error_log('Orbitra postback delivery failed: ' . get_class($e)
        . (orbitraDbErrorIsLock($e) ? ' (database is locked)' : ' (conversion/CAPI transaction failed)'));
    // Keep actionable locations without stack arguments or exception messages:
    // provider payloads and URLs can contain tokens or customer identifiers.
    try {
        $logStmt = $pdo->prepare("INSERT INTO system_logs (level, message, context) VALUES (?, ?, ?)");
        $logStmt->execute([
            'ERROR',
            'postback.php database error',
            json_encode([
                'exception' => get_class($e),
                'category' => orbitraDbErrorIsLock($e) ? 'database_locked' : 'conversion_capi_write',
                'code' => (string) $e->getCode(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ])
        ]);
    } catch (\Throwable $logErr) {
        // If logging fails, continue anyway
    }

    $responseCode = orbitraDbErrorIsLock($e) ? 503 : 500;
    orbitraPostbackExit($responseCode, "Internal error.", ['result' => 'error', 'error' => 'Internal server error']);
    return;
}
