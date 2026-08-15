<?php
// Orbitra — Revenue Aggregator Cron Script
//
// Автоматически синхронизирует данные доходов из подключённых партнёрских кабинетов.
//
// Использование:
//   php aggregator_cron.php                  — синхронизация всех активных подключений (за последние 2 дня)
//   php aggregator_cron.php --force          — принудительная синхронизация (игнорируя интервал)
//   php aggregator_cron.php --connection=5   — синхронизация только подключения #5
//   php aggregator_cron.php --days=7         — синхронизация за последние 7 дней
//   php aggregator_cron.php --rematch        — повторный matching для неприкреплённых записей
//
// Crontab example:
//   0 */2 * * * php /var/www/orbitra/aggregator_cron.php >> /var/log/orbitra_aggregator.log 2>&1

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

// Parse CLI arguments
$options = getopt('', ['force', 'connection:', 'days:', 'rematch', 'quiet']);
$isForce = isset($options['force']);
$onlyConnectionId = $options['connection'] ?? null;
$daysPeriod = (int)($options['days'] ?? 2);
$isRematch = isset($options['rematch']);
$isQuiet = isset($options['quiet']);

function cron_log(string $msg): void
{
    global $isQuiet;
    if (!$isQuiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }
}

// Load config & DB
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/aggregator_engines/GenericApiEngine.php';

cron_log('=== Orbitra Revenue Aggregator Cron Start ===');

// ————————————————————————————————————————————
// Mode: Rematch — привязать ранее неприкреплённые записи к кликам
// ————————————————————————————————————————————
if ($isRematch) {
    cron_log('[REMATCH] Re-matching unmatched revenue records...');

    $unmatchedStmt = $pdo->query("SELECT id, click_id FROM revenue_records WHERE is_matched = 0 AND click_id IS NOT NULL AND click_id != ''");
    $unmatched = $unmatchedStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($unmatched)) {
        cron_log('[REMATCH] No unmatched records found.');
        exit(0);
    }

    cron_log('[REMATCH] Found ' . count($unmatched) . ' unmatched records');

    $clickCheck = $pdo->prepare("SELECT id FROM clicks WHERE id = ?");
    $markMatched = $pdo->prepare("UPDATE revenue_records SET is_matched = 1 WHERE id = ?");
    $updateRevenue = $pdo->prepare("UPDATE clicks SET revenue = revenue + ? WHERE id = ?");

    $matchedCount = 0;
    $pdo->beginTransaction();

    foreach ($unmatched as $rec) {
        $clickCheck->execute([$rec['click_id']]);
        if ($clickCheck->fetch()) {
            $markMatched->execute([$rec['id']]);
            // Получить amount для обновления clicks.revenue
            $amountStmt = $pdo->prepare("SELECT amount FROM revenue_records WHERE id = ?");
            $amountStmt->execute([$rec['id']]);
            $amount = (float)($amountStmt->fetchColumn() ?: 0);
            if ($amount > 0) {
                $updateRevenue->execute([$amount, $rec['click_id']]);
            }
            $matchedCount++;
        }
    }

    $pdo->commit();
    cron_log("[REMATCH] Done. Matched: $matchedCount / " . count($unmatched));
    exit(0);
}

// ————————————————————————————————————————————
// Normal mode: синхронизация подключений
// ————————————————————————————————————————————

$dateFrom = date('Y-m-d', strtotime("-{$daysPeriod} days"));
$dateTo = date('Y-m-d');

// Получаем все активные подключения (или одно конкретное)
if ($onlyConnectionId) {
    $stmt = $pdo->prepare("SELECT * FROM aggregator_connections WHERE id = ? AND is_active = 1");
    $stmt->execute([$onlyConnectionId]);
}
else {
    $stmt = $pdo->query("SELECT * FROM aggregator_connections WHERE is_active = 1");
}
$connections = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($connections)) {
    cron_log('No active connections found.');
    exit(0);
}

cron_log('Found ' . count($connections) . ' active connection(s). Period: ' . $dateFrom . ' — ' . $dateTo);

$totalFetched = 0;
$totalMatched = 0;
$totalNew = 0;

foreach ($connections as $conn) {
    $connName = $conn['name'] . ' (#' . $conn['id'] . ')';

    // Проверяем интервал (если не --force)
    if (!$isForce && $conn['last_sync_at']) {
        $lastSync = strtotime($conn['last_sync_at']);
        $nextSync = $lastSync + ($conn['sync_interval_hours'] * 3600);
        if (time() < $nextSync) {
            $remaining = round(($nextSync - time()) / 60);
            cron_log("[$connName] Skipping — next sync in {$remaining}min");
            continue;
        }
    }

    cron_log("[$connName] Starting sync...");
    $startTime = microtime(true);

    $credentials = json_decode($conn['credentials_json'] ?? '{}', true);
    $fieldMapping = json_decode($conn['field_mapping_json'] ?? '{}', true);
    if (!is_array($fieldMapping)) {
        $fieldMapping = [];
    }

    // Ad platforms keep restating past days: spend is attributed late, refunds and
    // invalid-click credits land days after the fact, and the current day is a
    // running total. A 2-day window therefore freezes yesterday's numbers wrong.
    // Cost connections re-read the last 5 days, and 30 days on the very first sync
    // so a connection added today does not leave the previous month at zero cost.
    $connDateFrom = $dateFrom;
    $connDateTo = $dateTo;
    $isCostConnection = in_array($conn['engine'], ['facebook', 'google_ads'], true);
    if ($isCostConnection && !isset($options['days'])) {
        $lookbackDays = empty($conn['last_sync_at']) ? 30 : 5;
        $connDateFrom = date('Y-m-d', strtotime("-{$lookbackDays} days"));
    }

    try {
        // Load appropriate engine
        $records = [];
        $isCostEngine = false;
        switch ($conn['engine']) {
            case 'referon':
                if (file_exists(__DIR__ . '/aggregator_engines/ReferOnEngine.php')) {
                    require_once __DIR__ . '/aggregator_engines/ReferOnEngine.php';
                    $records = ReferOnEngine::fetchRecords($credentials, $dateFrom, $dateTo, $fieldMapping);
                }
                else {
                    $records = GenericApiEngine::fetchRecords($credentials, $dateFrom, $dateTo, $fieldMapping);
                }
                break;
            case 'affilka':
                if (file_exists(__DIR__ . '/aggregator_engines/AffilkaEngine.php')) {
                    require_once __DIR__ . '/aggregator_engines/AffilkaEngine.php';
                    $records = AffilkaEngine::fetchRecords($credentials, $dateFrom, $dateTo, $fieldMapping);
                }
                else {
                    $records = GenericApiEngine::fetchRecords($credentials, $dateFrom, $dateTo, $fieldMapping);
                }
                break;
            case 'facebook':
                if (file_exists(__DIR__ . '/aggregator_engines/FacebookAdsEngine.php')) {
                    require_once __DIR__ . '/aggregator_engines/FacebookAdsEngine.php';
                    $records = FacebookAdsEngine::fetchRecords($credentials, $connDateFrom, $connDateTo, $fieldMapping);
                    $isCostEngine = true;
                }
                break;
            case 'google_ads':
                if (file_exists(__DIR__ . '/aggregator_engines/GoogleAdsEngine.php')) {
                    require_once __DIR__ . '/aggregator_engines/GoogleAdsEngine.php';
                    $records = GoogleAdsEngine::fetchRecords($credentials, $connDateFrom, $connDateTo, $fieldMapping);
                    $isCostEngine = true;
                }
                break;
            default:
                $records = GenericApiEngine::fetchRecords($credentials, $dateFrom, $dateTo, $fieldMapping);
        }

        // Cost engines (FB / Google Ads) write spend into cost_records and distribute it
        // across clicks by campaign_id / ad_id — those keys are captured by the
        // traffic-source templates already. Revenue engines keep their own path below.
        if ($isCostEngine) {
            // Shared with the manual "Sync now" path in api.php — see core/CostImporter.php.
            // Records are upserted, not skipped on duplicate, so re-syncing the current
            // day picks up spend accrued since the previous run.
            require_once __DIR__ . '/core/CostImporter.php';

            $pdo->beginTransaction();
            $costStats = CostImporter::import($pdo, (int) $conn['id'], $records, $fieldMapping);
            $fetched  = $costStats['fetched'];
            $matched  = $costStats['matched'];
            $newCount = $costStats['new'];
            $updatedCount = $costStats['updated'];

            $pdo->commit();
            $durationMs = round((microtime(true) - $startTime) * 1000);

            $pdo->prepare("UPDATE aggregator_connections SET last_sync_at = datetime('now'), last_sync_status = 'success', last_sync_error = NULL WHERE id = ?")
                ->execute([$conn['id']]);
            $pdo->prepare("INSERT INTO aggregator_sync_logs (connection_id, status, records_fetched, records_matched, records_new, duration_ms, date_from, date_to) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$conn['id'], 'success', $fetched, $matched, $newCount, $durationMs, $connDateFrom, $connDateTo]);

            $totalFetched += $fetched;
            $totalMatched += $matched;
            $totalNew += $newCount;
            $unmatched = $costStats['unmatched'];
            $costCurrency = $costStats['currency'];
            cron_log("[$connName] ✓ Done (cost, $connDateFrom→$connDateTo). Fetched: $fetched, Matched: $matched, Unmatched: $unmatched, New: $newCount, Updated: $updatedCount, Currency: $costCurrency ({$durationMs}ms)");
            if ($fetched > 0 && $matched === 0) {
                // The single most common misconfiguration: spend arrives, but the
                // campaign URL carries no {{ad.id}}/{{adset.id}}, so none of it can
                // be attached to a click. Silence here reads as "working".
                cron_log("[$connName] ⚠ Spend was imported but matched 0 clicks — check that the campaign URL passes ad_id/adset_id/campaign_id (Facebook source template).");
            }
            continue;
        }

        $insertStmt = $pdo->prepare("INSERT INTO revenue_records (connection_id, external_id, click_id, player_id, event_type, amount, currency, country, brand, sub_id, event_date, raw_json, is_matched) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $clickCheckStmt = $pdo->prepare("SELECT id FROM clicks WHERE id = ?");
        $updateRevenueStmt = $pdo->prepare("UPDATE clicks SET revenue = revenue + ? WHERE id = ?");

        $pdo->beginTransaction();
        $fetched = count($records);
        $matched = 0;
        $newCount = 0;

        foreach ($records as $rec) {
            $externalId = $rec['external_id'] ?? null;
            $clickId = $rec['click_id'] ?? null;

            // Duplicate check
            if ($externalId) {
                $dupCheck = $pdo->prepare("SELECT id FROM revenue_records WHERE connection_id = ? AND external_id = ?");
                $dupCheck->execute([$conn['id'], $externalId]);
                if ($dupCheck->fetch())
                    continue;
            }

            // Click matching
            $isMatched = 0;
            if ($clickId) {
                $clickCheckStmt->execute([$clickId]);
                if ($clickCheckStmt->fetch()) {
                    $isMatched = 1;
                    $matched++;

                    // Update clicks.revenue with real amount from partner
                    $amount = (float)($rec['amount'] ?? 0);
                    if ($amount > 0) {
                        $updateRevenueStmt->execute([$amount, $clickId]);
                    }
                }
            }

            $insertStmt->execute([
                $conn['id'],
                $externalId,
                $clickId,
                $rec['player_id'] ?? null,
                $rec['event_type'] ?? 'ftd',
                (float)($rec['amount'] ?? 0),
                $rec['currency'] ?? 'USD',
                $rec['country'] ?? null,
                $rec['brand'] ?? null,
                $rec['sub_id'] ?? null,
                $rec['event_date'] ?? date('Y-m-d'),
                $rec['raw_json'] ?? null,
                $isMatched
            ]);
            $newCount++;
        }

        $pdo->commit();
        $durationMs = round((microtime(true) - $startTime) * 1000);

        // Update connection status
        $pdo->prepare("UPDATE aggregator_connections SET last_sync_at = datetime('now'), last_sync_status = 'success', last_sync_error = NULL WHERE id = ?")
            ->execute([$conn['id']]);

        // Save sync log
        $pdo->prepare("INSERT INTO aggregator_sync_logs (connection_id, status, records_fetched, records_matched, records_new, duration_ms, date_from, date_to) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$conn['id'], 'success', $fetched, $matched, $newCount, $durationMs, $dateFrom, $dateTo]);

        $totalFetched += $fetched;
        $totalMatched += $matched;
        $totalNew += $newCount;

        cron_log("[$connName] ✓ Done. Fetched: $fetched, Matched: $matched, New: $newCount ({$durationMs}ms)");

    }
    catch (\Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $durationMs = round((microtime(true) - $startTime) * 1000);

        $pdo->prepare("UPDATE aggregator_connections SET last_sync_at = datetime('now'), last_sync_status = 'error', last_sync_error = ? WHERE id = ?")
            ->execute([$e->getMessage(), $conn['id']]);
        $pdo->prepare("INSERT INTO aggregator_sync_logs (connection_id, status, error_message, duration_ms, date_from, date_to) VALUES (?,?,?,?,?,?)")
            ->execute([$conn['id'], 'error', $e->getMessage(), $durationMs, $dateFrom, $dateTo]);

        cron_log("[$connName] ✗ ERROR: " . $e->getMessage() . " ({$durationMs}ms)");
    }
}

cron_log("=== Cron Complete. Total — Fetched: $totalFetched, Matched: $totalMatched, New: $totalNew ===");