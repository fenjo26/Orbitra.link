<?php
/**
 * ClickFlags — правдивая статистика заглушечных метрик отчётов.
 *
 * Считается ДО вставки строки клика и пишется тем же INSERT (все точки входа:
 * index.php-роутер, pixel.gif, click.php, PWA-мостики) — один коммит, один
 * набор страниц в WAL. Раньше флаги падали отдельным UPDATE сразу после
 * INSERT, и каждый клик платил за одни и те же страницы дважды (~44 КБ WAL
 * на клик вместо ~20 КБ — нагрузочная приёмка, ТЗ часть 2, исправление 2):
 *   is_bot        — UA похож на краулер/инструмент (те же сигнатуры, что в клокере)
 *       + вердикт browser-check из этого же запроса (peek, без записи);
 *   is_proxy      — вердикт IP2Proxy из geo-данных клика (если БД подключена);
 *   uniq_campaign / uniq_stream / uniq_global — уникальность IP(+UA) в рамках
 *       окна уникальности кампании — по кампании, по потоку и глобально;
 *
 * Строка click_browser_checks пишется отдельной точкой ПОСЛЕ вставки
 * (orbitraBcPersistPending) — ей нужен существующий клик.
 *
 * landing_at / offer_at ведут отдельные точки (index.php): первый показ
 * локального лендинга и переход /?_lp=1 — их разница и есть Time since LP click.
 */

require_once __DIR__ . '/CloakDetector.php';
require_once __DIR__ . '/click_logger.php';
require_once __DIR__ . '/BrowserCheck.php';

/**
 * Флаги честной статистики клика — ДО вставки строки.
 *
 * Возвращает [is_bot, is_proxy, uniq_campaign, uniq_stream, uniq_global]
 * (0/1) или null, если посчитать не удалось: клик важнее флагов, вызывающий
 * просто вставит строку без них, как это было при неудачном UPDATE.
 *
 * Пробы уникальности идут по таблице БЕЗ нашей строки — она ещё не вставлена,
 * поэтому исключать собственный id не нужно: результат тот же, что давал
 * старый пост-INSERT-зонд с excludeClickId.
 *
 * @return array{0:int,1:int,2:int,3:int,4:int}|null
 */
function orbitraComputeClickFlags(PDO $pdo, string $ip, string $userAgent, array $campaign, $streamId, array $geoData = []): ?array
{
    try {
        $isBot = CloakDetector::isBotUserAgent($userAgent) ? 1 : 0;
        // Верdict browser-check из этого запроса подсматривается (peek), а не
        // забирается: строка проверки пишется после вставки клика, как раньше.
        $bcPending = orbitraBcRemember(null, true);
        if (is_array($bcPending) && ($bcPending['verdict'] ?? '') === 'bot') {
            $isBot = 1;
        }
        $isProxy = !empty($geoData['is_proxy']) ? 1 : 0;

        // Uniqueness window and method come from the campaign, as the router
        // applies them. Cookies-based uniqueness degrades to IP here — the flag
        // computation runs before any cookie state could be trusted anyway.
        $hours = max(1, (int) ($campaign['uniqueness_hours'] ?? 24));
        $byUa = strtoupper((string) ($campaign['uniqueness_method'] ?? 'IP')) === 'IP_UA';

        $uniqCampaign = orbitraClickIsUnique($pdo, $ip, $userAgent, $byUa, $hours, ['campaign_id' => (int) ($campaign['id'] ?? 0)]);
        $streamId = (int) $streamId;
        $uniqStream = $streamId > 0
            ? orbitraClickIsUnique($pdo, $ip, $userAgent, $byUa, $hours, ['stream_id' => $streamId])
            : $uniqCampaign;
        $uniqGlobal = orbitraClickIsUnique($pdo, $ip, $userAgent, $byUa, $hours, []);

        return [$isBot, $isProxy, $uniqCampaign ? 1 : 0, $uniqStream ? 1 : 0, $uniqGlobal ? 1 : 0];
    } catch (\Throwable $e) {
        // Статистические флаги не должны ронять клик.
        return null;
    }
}

/** Merge computed flags into a click context for orbitraBuildClickRow(). */
function orbitraMergeClickFlags(array $ctx, ?array $flags): array
{
    if (!is_array($flags)) {
        return $ctx;
    }
    return $ctx + [
        'is_bot' => $flags[0],
        'is_proxy' => $flags[1],
        'uniq_campaign' => $flags[2],
        'uniq_stream' => $flags[3],
        'uniq_global' => $flags[4],
    ];
}

function orbitraClickIsUnique(PDO $pdo, string $ip, string $userAgent, bool $byUa, int $hours, array $scope = [], string $excludeClickId = ''): bool
{
    // The exclude-id parameter stays for callers that probe AFTER an insert
    // (the pre-INSERT hot path passes nothing — its row does not exist yet).
    if (!$byUa) {
        // IP-only: the first click of this IP inside the window settles it —
        // one seek on the ip prefix of idx_clicks_ip_ua_created (idx_clicks_
        // ip_created was retired in migration 82, ТЗ 1.9.8).
        $conds = ['ip = ?', "created_at >= datetime('now', ?)"];
        $params = [$ip, "-{$hours} hours"];
        if ($excludeClickId !== '') {
            $conds[] = 'id != ?';
            $params[] = $excludeClickId;
        }
        foreach ($scope as $scopeColumn => $scopeValue) {
            $conds[] = "$scopeColumn = ?";
            $params[] = $scopeValue;
        }
        // INDEXED BY pins this to an ip-leading index — without stats the
        // planner prefers campaign_created and scans the window (see
        // orbitraClicksIpIndexHint, stand incident 2026-11-21).
        $stmt = $pdo->prepare('SELECT 1 FROM clicks' . orbitraClicksIpIndexHint($pdo) . ' WHERE ' . implode(' AND ', $conds) . ' LIMIT 1');
        $stmt->execute($params);
        $found = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $found === false;
    }
    // IP_UA: two exact seeks on idx_clicks_ip_ua_created (ip, ua_hash,
    // created_at) — hashed rows first, then the NULL-hash rows that predate
    // migration 55. This replaces the per-click scan that read every click of
    // a busy carrier IP and compared user agents row by row (load ТЗ,
    // acceptance blocker 2).
    return !orbitraUaMatchExists($pdo, $scope, $ip, $userAgent, "created_at >= datetime('now', ?)", "-{$hours} hours", $excludeClickId);
}
