<?php
// core/CreativeStats.php
//
// creative_daily — агрегат метрик библиотеки креативов (миграция 63).
//
// Отчёт по креативу без агрегата — это json_extract по всей таблице кликов на
// каждую строку; на SQLite это минуты. Поэтому синк расхода после каждого
// импорта пересобирает окно (те же 5 дней, что перечитывает CostImporter, 30
// на первом синке): расход/показы/клики площадки из cost_records по ad_id,
// трекерные метрики из clicks по индексированному clicks.ad_ref — днём
// кабинета подключения (тем же смещением, каким расход ложился на клики).
//
// Итоговая строка библиотеки считается по УНИКАЛЬНЫМ ассетам: карусель из трёх
// картинок — это один креатив, а не трижды один.

require_once __DIR__ . '/ReportMetrics.php';

class CreativeStats
{
    /**
     * Пересобрать creative_daily за окно дней (платформенные дни click_date).
     *
     * @return array{assets:int, days:int}
     */
    public static function rebuildWindow(PDO $pdo, string $dateFrom, string $dateTo): array
    {
        // Какие ассеты вообще связаны с объявлениями (плейсхолдеры asset 0
        // метрик не имеют — файл ещё не скачан).
        $usages = $pdo->query("
            SELECT asset_id, platform, ad_id, connection_id
            FROM creative_usages
            WHERE asset_id > 0
        ")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($usages)) {
            return ['assets' => 0, 'days' => 0];
        }

        // ad_id → asset_id (один ad может крутить несколько файлов — карусель;
        // тогда метрики расхода делятся между ними поровну, честнее, чем
        // приписать всё первому).
        $assetsByAd = [];
        $connectionByAd = [];
        foreach ($usages as $u) {
            $adId = (string) $u['ad_id'];
            $assetsByAd[$adId][(int) $u['asset_id']] = true;
            if (!isset($connectionByAd[$adId])) {
                $connectionByAd[$adId] = (int) $u['connection_id'];
            }
        }
        $allAds = array_keys($assetsByAd);

        // Расходная сторона: cost_records окна по ad_id.
        $cells = []; // asset_id => day => metrics
        $addCell = function (int $assetId, string $day, array $metrics) use (&$cells): void {
            if (!isset($cells[$assetId])) {
                $cells[$assetId] = [];
            }
            if (!isset($cells[$assetId][$day])) {
                $cells[$assetId][$day] = [
                    'spend' => 0.0, 'fee' => 0.0, 'impressions' => 0, 'platform_clicks' => 0,
                    'tracker_clicks' => 0, 'visitors' => 0, 'installs' => 0,
                    'registrations' => 0, 'ftd' => 0, 'revenue' => 0.0,
                ];
            }
            foreach ($metrics as $k => $v) {
                $cells[$assetId][$day][$k] += $v;
            }
        };

        // Таймзона подключения — по ней трекерные клики группируются в те же
        // дни, что расход (кэш на окно).
        $tzCache = [];
        $tzModifierFor = function (int $connectionId) use ($pdo, &$tzCache, $dateFrom): string {
            if (!array_key_exists($connectionId, $tzCache)) {
                try {
                    $stmt = $pdo->prepare("SELECT field_mapping_json, credentials_json FROM aggregator_connections WHERE id = ? LIMIT 1");
                    $stmt->execute([$connectionId]);
                    $conn = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $fm = json_decode((string) ($conn['field_mapping_json'] ?? '{}'), true);
                    $tz = CostImporter::resolveTimezone($pdo, $connectionId, is_array($fm) ? $fm : [], [
                        'credentials' => json_decode((string) ($conn['credentials_json'] ?? '{}'), true) ?: [],
                    ]);
                } catch (\Throwable $e) {
                    $tz = null;
                }
                $tzCache[$connectionId] = $tz;
            }
            // Смещение считаем на полдень окна: DST-переход внутри окна в
            // худшем случае сдвигает граничный час — приемлемо для агрегата.
            try {
                $tzObj = $tzCache[$connectionId] !== null ? new DateTimeZone($tzCache[$connectionId]) : null;
            } catch (\Throwable $e) {
                $tzObj = null;
            }
            if ($tzObj === null) {
                return '';
            }
            $ref = DateTime::createFromFormat('Y-m-d H:i:s', $dateFrom . ' 12:00:00', $tzObj) ?: new DateTime('now', $tzObj);
            $minutes = (int) round($tzObj->getOffset($ref) / 60);
            return $minutes === 0 ? '' : sprintf('%+d minutes', $minutes);
        };

        require_once __DIR__ . '/CostImporter.php';

        // Обход по подключениям: у каждого свой набор ad_id и своя таймзона.
        $adsByConnection = [];
        foreach ($connectionByAd as $adId => $connectionId) {
            $adsByConnection[$connectionId][$adId] = true;
        }

        foreach ($adsByConnection as $connectionId => $adIds) {
            $adIdList = array_keys($adIds);
            $placeholders = implode(',', array_fill(0, count($adIdList), '?'));

            // 1) Расход/показы/клики площадки (cost_records).
            $stmt = $pdo->prepare("
                SELECT ad_id, click_date AS day,
                       COALESCE(SUM(amount), 0) AS spend,
                       COALESCE(SUM(fee_amount), 0) AS fee,
                       COALESCE(SUM(impressions), 0) AS impressions,
                       COALESCE(SUM(platform_clicks), 0) AS platform_clicks
                FROM cost_records
                WHERE ad_id IN ($placeholders) AND click_date >= ? AND click_date <= ?
                GROUP BY ad_id, click_date
            ");
            $stmt->execute(array_merge($adIdList, [$dateFrom, $dateTo]));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $adId = (string) $row['ad_id'];
                $assetIds = array_keys($assetsByAd[$adId] ?? []);
                if (empty($assetIds)) {
                    continue;
                }
                $share = 1.0 / count($assetIds); // карусель: делить поровну
                foreach ($assetIds as $assetId) {
                    $addCell((int) $assetId, (string) $row['day'], [
                        'spend' => (float) $row['spend'] * $share,
                        'fee' => (float) $row['fee'] * $share,
                        'impressions' => (int) $row['impressions'],
                        'platform_clicks' => (int) $row['platform_clicks'],
                    ]);
                }
            }

            // 2) Трекерные метрики (clicks по ad_ref, днём кабинета).
            $tzModifier = $tzModifierFor((int) $connectionId);
            $dateExpr = $tzModifier === '' ? 'date(created_at)' : "date(created_at, '{$tzModifier}')";
            // Дни окна в локальном исчислении охватывают и соседние UTC-дни.
            $clickFrom = gmdate('Y-m-d H:i:s', strtotime($dateFrom . ' 00:00:00 UTC') - 15 * 3600);
            $clickTo = gmdate('Y-m-d H:i:s', strtotime($dateTo . ' 00:00:00 UTC') + 39 * 3600);

            $rrColumn = self::revenueRecordsColumn($pdo);
            $realJoin = $rrColumn !== null
                ? 'LEFT JOIN (SELECT click_id, SUM(' . $rrColumn . ') AS real_rev FROM revenue_records GROUP BY click_id) rr ON rr.click_id = clicks.id'
                : '';
            $revExpr = $rrColumn !== null ? 'COALESCE(rr.real_rev, 0)' : 'COALESCE(clicks.revenue, 0)';
            $convJoin = 'LEFT JOIN ' . orbitraConversionAggregateSql(self::conversionsColumn($pdo)) . ' cv ON cv.click_id = clicks.id';
            $safe = orbitraSafePagePredicate('clicks.');

            $stmt = $pdo->prepare("
                SELECT ad_ref, {$dateExpr} AS day,
                       COUNT(*) AS visitors,
                       SUM(CASE WHEN (clicks.offer_id > 0 OR COALESCE(clicks.direct_offer, 0) = 1)
                                AND (clicks.landing_id IS NULL OR clicks.landing_id = 0 OR clicks.offer_at IS NOT NULL)
                                THEN 1 ELSE 0 END) AS tracker_clicks,
                       SUM(CASE WHEN clicks.pwa_install_at IS NOT NULL AND clicks.is_bot = 0 THEN 1 ELSE 0 END) AS installs,
                       COALESCE(SUM(COALESCE(cv.cnt_registration, 0)), 0) AS registrations,
                       COALESCE(SUM(COALESCE(cv.cnt_deposit, 0)), 0) AS ftd,
                       COALESCE(SUM({$revExpr}), 0) AS revenue
                FROM clicks
                {$convJoin}
                {$realJoin}
                WHERE ad_ref IN ($placeholders)
                  AND created_at >= ? AND created_at < ?
                  AND {$safe}
                GROUP BY ad_ref, {$dateExpr}
            ");
            $stmt->execute(array_merge($adIdList, [$clickFrom, $clickTo]));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $adId = (string) $row['ad_ref'];
                $assetIds = array_keys($assetsByAd[$adId] ?? []);
                if (empty($assetIds)) {
                    continue;
                }
                $share = 1.0 / count($assetIds);
                foreach ($assetIds as $assetId) {
                    $addCell((int) $assetId, (string) $row['day'], [
                        'tracker_clicks' => (int) $row['tracker_clicks'],
                        'visitors' => (int) $row['visitors'],
                        'installs' => (int) $row['installs'],
                        'registrations' => (int) $row['registrations'],
                        'ftd' => (int) $row['ftd'],
                        'revenue' => (float) $row['revenue'] * $share,
                    ]);
                }
            }
        }

        // 3) Перезапись окна: сначала убрать протухшее (возвраты/кредиты
        // обнуляют день), затем вставить свежее.
        try {
            $pdo->prepare("DELETE FROM creative_daily WHERE day >= ? AND day <= ?")->execute([$dateFrom, $dateTo]);
        } catch (\Throwable $e) {
            return ['assets' => 0, 'days' => 0];
        }
        $upsert = $pdo->prepare("
            INSERT INTO creative_daily
                (asset_id, day, spend, fee, impressions, platform_clicks, tracker_clicks, visitors, installs, registrations, ftd, revenue, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?, datetime('now'))
            ON CONFLICT(asset_id, day) DO UPDATE SET
                spend = excluded.spend, fee = excluded.fee,
                impressions = excluded.impressions, platform_clicks = excluded.platform_clicks,
                tracker_clicks = excluded.tracker_clicks, visitors = excluded.visitors,
                installs = excluded.installs, registrations = excluded.registrations,
                ftd = excluded.ftd, revenue = excluded.revenue, updated_at = datetime('now')
        ");
        $days = 0;
        foreach ($cells as $assetId => $byDay) {
            foreach ($byDay as $day => $m) {
                if ($day < $dateFrom || $day > $dateTo) {
                    // Клик на границе лёг в соседний день кабинета — он будет
                    // пересобран, когда окно синка дойдёт до того дня.
                    continue;
                }
                $upsert->execute([
                    $assetId, $day,
                    $m['spend'], $m['fee'], $m['impressions'], $m['platform_clicks'],
                    $m['tracker_clicks'], $m['visitors'], $m['installs'],
                    $m['registrations'], $m['ftd'], $m['revenue'],
                ]);
                $days++;
            }
        }
        return ['assets' => count($cells), 'days' => $days];
    }

    private static function revenueRecordsColumn(PDO $pdo): ?string
    {
        try {
            $columns = [];
            foreach ($pdo->query("PRAGMA table_info(revenue_records)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[] = (string) ($col['name'] ?? '');
            }
            if (in_array('amount', $columns, true)) {
                return 'amount';
            }
            if (in_array('revenue', $columns, true)) {
                return 'revenue';
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    private static function conversionsColumn(PDO $pdo): string
    {
        try {
            $columns = [];
            foreach ($pdo->query("PRAGMA table_info(conversions)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[] = (string) ($col['name'] ?? '');
            }
            return in_array('value', $columns, true) ? 'value' : 'revenue';
        } catch (\Throwable $e) {
            return 'revenue';
        }
    }
}
