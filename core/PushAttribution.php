<?php
// core/PushAttribution.php
//
// Конверсии, пришедшие ПОСЛЕ клика (или показа) пуша — «какой пуш принёс
// депозиты» (этап 2.2).
//
// Клик по пушу открывает приложение с subid = click_id подписки, конверсия
// возвращается постбеком на этот же click_id. Атрибуция: конверсия считается
// заслугой пуша, если она случилась в окне N часов после якоря — клика по
// пушу (push_attribution_from = 'click', рекомендуется: клик — доказанный
// интерес) или отправки ('send'). Кликов по пушам могло быть несколько —
// берём ПОСЛЕДНИЙ якорь, не позже самой конверсии (план: «берём последний»).
//
// Депозит при этом остаётся и за рекламным кликом: атрибуция никуда не
// «переезжает» — это отдельный взгляд на те же события, отчёты кампаний не
// меняются.
//
// Расчёт в PHP, а не одним SQL: сообщения считанные штуки, а кликнутых
// доставок у сообщения ограниченное число — «последний якорь ≤ конверсии»
// проще и честнее обходить попарно, чем выражать оконной функцией, которой
// в SQLite нет.

require_once __DIR__ . '/ReportMetrics.php';

class PushAttribution
{
    /** Часы окна атрибуции (1..720), по умолчанию 72. */
    public static function windowHours(PDO $pdo): int
    {
        try {
            $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'push_attribution_hours' LIMIT 1");
            $hours = (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            $hours = 0;
        }
        return $hours > 0 ? min(720, max(1, $hours)) : 72;
    }

    /** 'click' (по клику, рекомендуется) | 'send' (по отправке). */
    public static function anchorMode(PDO $pdo): string
    {
        try {
            $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'push_attribution_from' LIMIT 1");
            $mode = strtolower(trim((string) $stmt->fetchColumn()));
        } catch (\Throwable $e) {
            $mode = '';
        }
        return $mode === 'send' ? 'send' : 'click';
    }

    /**
     * Атрибуция по сообщениям.
     *
     * @param int|null $messageId одно сообщение (null = все, у которых были
     *                            клики или отправки за $daysBack дней)
     * @param int      $daysBack глубина выборки доставок
     * @return array<int, array{clicks:int, regs:int, ftd:int, redeps:int, revenue:float}>
     *         ключ — message_id. ftd = подписчики с ≥1 депозитом в окне,
     *         redeps = депозиты сверх первого. revenue — сумма value конверсий
     *         в окне (регистрации + депозиты).
     */
    public static function messageAttribution(PDO $pdo, ?int $messageId = null, int $daysBack = 60): array
    {
        $window = self::windowHours($pdo);
        $mode = self::anchorMode($pdo);

        // Якоря: одна строка на (сообщение, подписка). При mode=click — только
        // кликнутые доставки; последний клик определяет якорь (MAX).
        $anchorSql = $mode === 'click'
            ? "SELECT s.message_id, s.subscription_id, MAX(s.clicked_at) AS anchor
               FROM push_sends s
               WHERE s.clicked_at IS NOT NULL"
            : "SELECT s.message_id, s.subscription_id, MAX(COALESCE(s.clicked_at, s.sent_at)) AS anchor
               FROM push_sends s";
        try {
            $stmt = $pdo->prepare("
                SELECT a.message_id, a.subscription_id, sub.click_id, a.anchor
                FROM (" . $anchorSql . " GROUP BY s.message_id, s.subscription_id) a
                JOIN push_subscriptions sub ON sub.id = a.subscription_id AND sub.click_id IS NOT NULL
                " . ($messageId !== null ? 'WHERE a.message_id = ?' : '') . "
            ");
            $stmt->execute($messageId !== null ? [$messageId] : []);
            $anchors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
        if (!$anchors) {
            return [];
        }

        // Конверсии этих кликов (за разумную глубину — окно + запас).
        $clickIds = [];
        foreach ($anchors as $a) {
            $clickIds[(string) $a['click_id']] = true;
        }
        $list = array_keys($clickIds);
        $convs = [];
        $chunks = array_chunk($list, 400);
        $groups = orbitraConversionStatusGroups();
        try {
            foreach ($chunks as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $cStmt = $pdo->prepare("
                    SELECT c.click_id, c.status, c.created_at, c.revenue
                    FROM conversions c
                    WHERE c.click_id IN ($ph)
                ");
                $cStmt->execute($chunk);
                foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $convs[(string) $c['click_id']][] = $c;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        // Перевод статусов в группы (без второго запроса — правило общее с отчётами).
        $isReg = fn($status) => in_array(strtolower((string) $status), array_merge($groups['registration'], $groups['hold']), true);
        $isDep = fn($status) => in_array(strtolower((string) $status), array_merge($groups['deposit'], $groups['sale']), true);

        $out = [];
        $seenConvIds = []; // (message, sub, click_id, conv_time) — конверсия в двух окнах не считается дважды
        foreach ($anchors as $a) {
            $clickConvs = $convs[(string) $a['click_id']] ?? [];
            if (!$clickConvs) {
                continue;
            }
            $anchorTs = strtotime((string) $a['anchor'] . ' UTC');
            if ($anchorTs === false) {
                continue;
            }
            $msgId = (int) $a['message_id'];
            foreach ($clickConvs as $c) {
                $convTs = strtotime((string) $c['created_at'] . ' UTC');
                if ($convTs === false) {
                    continue;
                }
                // Последний якорь, не позже конверсии: если конверсия раньше
                // якоря — она была до пуша, не считается.
                if ($convTs < $anchorTs || $convTs > $anchorTs + $window * 3600) {
                    continue;
                }
                $key = $msgId . '|' . $a['subscription_id'] . '|' . $convTs . '|' . strtolower((string) $c['status']);
                if (isset($seenConvIds[$key])) {
                    continue;
                }
                $seenConvIds[$key] = true;
                if (!isset($out[$msgId])) {
                    $out[$msgId] = ['clicks' => 0, 'regs' => 0, 'ftd' => 0, 'redeps' => 0, 'revenue' => 0.0, '_dep_subs' => []];
                }
                if ($isReg($c['status'])) {
                    $out[$msgId]['regs']++;
                }
                if ($isDep($c['status'])) {
                    if (!in_array($a['subscription_id'], $out[$msgId]['_dep_subs'], true)) {
                        $out[$msgId]['_dep_subs'][] = $a['subscription_id'];
                        $out[$msgId]['ftd']++;
                    } else {
                        $out[$msgId]['redeps']++;
                    }
                }
                $out[$msgId]['revenue'] += (float) ($c['revenue'] ?? 0);
            }
        }
        foreach ($out as $msgId => $row) {
            unset($out[$msgId]['_dep_subs']);
        }
        return $out;
    }
}
