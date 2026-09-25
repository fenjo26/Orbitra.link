<?php
/**
 * ClickFlags — правдивая статистика заглушечных метрик отчётов.
 *
 * Пишется одним UPDATE сразу после INSERT клика (все четыре точки входа:
 * index.php-роутер, pixel.gif, click.php, Click API v3):
 *   is_bot        — UA похож на краулер/инструмент (те же сигнатуры, что в клокере);
 *   is_proxy      — вердикт IP2Proxy из geo-данных клика (если БД подключена);
 *   uniq_campaign / uniq_stream / uniq_global — уникальность IP(+UA) в рамках
 *       окна уникальности кампании — по кампании, по потоку и глобально;
 *
 * landing_at / offer_at ведут отдельные точки (index.php): первый показ
 * локального лендинга и переход /?_lp=1 — их разница и есть Time since LP click.
 */

require_once __DIR__ . '/CloakDetector.php';
require_once __DIR__ . '/click_logger.php';

function orbitraWriteClickFlags(PDO $pdo, string $clickId, string $ip, string $userAgent, array $campaign, $streamId, array $geoData = []): void
{
    try {
        $isBot = CloakDetector::isBotUserAgent($userAgent) ? 1 : 0;
        $isProxy = !empty($geoData['is_proxy']) ? 1 : 0;

        // Uniqueness window and method come from the campaign, as the router
        // applies them. Cookies-based uniqueness degrades to IP here — the flag
        // writers run before any cookie state could be trusted anyway.
        $hours = max(1, (int) ($campaign['uniqueness_hours'] ?? 24));
        $byUa = strtoupper((string) ($campaign['uniqueness_method'] ?? 'IP')) === 'IP_UA';

        $uniqCampaign = orbitraClickIsUnique($pdo, $ip, $userAgent, $byUa, $hours, ['campaign_id' => (int) ($campaign['id'] ?? 0)], $clickId);
        $streamId = (int) $streamId;
        $uniqStream = $streamId > 0
            ? orbitraClickIsUnique($pdo, $ip, $userAgent, $byUa, $hours, ['stream_id' => $streamId], $clickId)
            : $uniqCampaign;
        $uniqGlobal = orbitraClickIsUnique($pdo, $ip, $userAgent, $byUa, $hours, [], $clickId);

        $pdo->prepare(
            'UPDATE clicks SET is_bot = ?, is_proxy = ?, uniq_campaign = ?, uniq_stream = ?, uniq_global = ? WHERE id = ?'
        )->execute([$isBot, $isProxy, $uniqCampaign ? 1 : 0, $uniqStream ? 1 : 0, $uniqGlobal ? 1 : 0, $clickId]);
    } catch (\Throwable $e) {
        // Статистические флаги не должны ронять клик.
    }
}

function orbitraClickIsUnique(PDO $pdo, string $ip, string $userAgent, bool $byUa, int $hours, array $scope = [], string $excludeClickId = ''): bool
{
    // The click's own row is already inserted at this point — a probe that
    // counts it finds itself and marks every click non-unique.
    if (!$byUa) {
        // IP-only: the first click of this IP inside the window settles it —
        // one seek on idx_clicks_ip_created.
        $conds = ['ip = ?', "created_at >= datetime('now', ?)", 'id != ?'];
        $params = [$ip, "-{$hours} hours", $excludeClickId];
        foreach ($scope as $scopeColumn => $scopeValue) {
            $conds[] = "$scopeColumn = ?";
            $params[] = $scopeValue;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM clicks WHERE ' . implode(' AND ', $conds) . ' LIMIT 1');
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
