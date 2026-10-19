<?php
// core/AdEntities.php
//
// Hourly refresh of ad_entities names + statuses for cost connections.
//
// The cost sync (core/CostImporter.php) upserts entities with names taken from
// the spend rows, but spend knows nothing about an ad being paused or deleted —
// and a report that shows "running" next to a long-dead ad misleads exactly when
// it matters. Each engine therefore also lists its account's entities with live
// statuses (Facebook: /act_X/ads|adsets|campaigns edges, one paginated call per
// level; TikTok: /ad/get/ + /adgroup/get/ + /campaign/get/), and this class
// stores the result into ad_entities, throttled to once per hour per connection
// — the cadence reports need, without per-report-open API reads.

class AdEntities
{
    private const THROTTLE_SECONDS = 3600;

    /**
     * Refresh statuses (and any renames) for one cost connection's platform
     * entities. Safe to call after every sync: an internal settings-key
     * throttle keeps it to one live pass per hour per connection.
     *
     * @param array $credentials connection credentials_json
     * @param string $engine     'facebook' | 'tiktok' (others: no-op)
     * @return array{refreshed:int, skipped:bool, error:?string}
     */
    public static function refreshStatuses(PDO $pdo, int $connectionId, array $credentials, string $engine): array
    {
        $platform = strtolower(trim($engine));
        if ($platform !== 'facebook' && $platform !== 'facebook_ads' && $platform !== 'tiktok') {
            return ['refreshed' => 0, 'skipped' => true, 'error' => null];
        }
        if ($platform === 'facebook_ads') {
            $platform = 'facebook';
        }

        $throttleKey = 'ad_entities_status_sync_' . $connectionId;
        try {
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ? LIMIT 1");
            $stmt->execute([$throttleKey]);
            $last = (int) $stmt->fetchColumn();
            if ($last > 0 && time() - $last < self::THROTTLE_SECONDS) {
                return ['refreshed' => 0, 'skipped' => true, 'error' => null];
            }
        } catch (\Throwable $e) {
            // No settings row yet: fall through and do the pass.
        }

        try {
            if ($platform === 'facebook') {
                require_once __DIR__ . '/../aggregator_engines/FacebookAdsEngine.php';
                $levels = FacebookAdsEngine::fetchAccountEntities($credentials);
            } else {
                require_once __DIR__ . '/../aggregator_engines/TikTokAdsEngine.php';
                $levels = TikTokAdsEngine::fetchAccountEntities($credentials);
            }
        } catch (\Throwable $e) {
            // A dead token or a fields drift must never fail the cost sync that
            // called us — record the attempt so the throttle still applies.
            self::stampThrottle($pdo, $throttleKey);
            return ['refreshed' => 0, 'skipped' => false, 'error' => $e->getMessage()];
        }

        $refreshed = 0;
        try {
            $upsert = $pdo->prepare("
                INSERT INTO ad_entities (platform, level, external_id, name, status, parent_id, connection_id, first_seen_at, last_seen_at)
                VALUES (?,?,?,?,?,?,?,datetime('now'),datetime('now'))
                ON CONFLICT(platform, level, external_id) DO UPDATE SET
                    name = CASE WHEN excluded.name != '' THEN excluded.name ELSE ad_entities.name END,
                    status = excluded.status,
                    parent_id = COALESCE(NULLIF(excluded.parent_id, ''), ad_entities.parent_id),
                    last_seen_at = datetime('now')
            ");
            foreach (['campaign', 'adset', 'ad'] as $level) {
                foreach (($levels[$level] ?? []) as $externalId => $entity) {
                    if ((string) $externalId === '') {
                        continue;
                    }
                    $upsert->execute([
                        $platform,
                        $level,
                        (string) $externalId,
                        (string) ($entity['name'] ?? ''),
                        self::normalizeStatus((string) ($entity['status'] ?? ($entity['effective'] ?? ''))),
                        (string) ($entity['parent_id'] ?? ''),
                        $connectionId,
                    ]);
                    $refreshed++;
                }
            }
        } catch (\Throwable $e) {
            self::stampThrottle($pdo, $throttleKey);
            return ['refreshed' => $refreshed, 'skipped' => false, 'error' => $e->getMessage()];
        }

        self::stampThrottle($pdo, $throttleKey);
        return ['refreshed' => $refreshed, 'skipped' => false, 'error' => null];
    }

    private static function stampThrottle(PDO $pdo, string $throttleKey): void
    {
        try {
            $pdo->prepare("
                INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')
            ")->execute([$throttleKey, (string) time()]);
        } catch (\Throwable $e) {
            // Losing the throttle costs an extra pass, nothing more.
        }
    }

    /**
     * One vocabulary across platforms: ACTIVE means spendable. Unknown values
     * pass through uppercase so the UI can still show what the platform said.
     */
    public static function normalizeStatus(string $status): string
    {
        $status = strtoupper(trim($status));
        if ($status === 'ENABLE') {
            return 'ACTIVE';
        }
        if ($status === 'DISABLE') {
            return 'PAUSED';
        }
        if ($status === 'DELETE' || $status === 'DELETED') {
            return 'DELETED';
        }
        return $status;
    }
}
