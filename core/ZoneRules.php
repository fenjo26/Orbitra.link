<?php
// core/ZoneRules.php
//
// Zone Killer: per-campaign rules that surface (and, in auto mode, block)
// push/pop zones burning money. Everything is computed from clicks.cost /
// clicks.revenue per zone parameter — the SAME distributed numbers the
// reports show — using the SAME zone keys the cost import matched on
// (CostImporter::zoneAttributionKeys()), so a rule can never judge a zone the
// importer priced differently.
//
// Modes:
//  - dry_run (DEFAULT): candidates are only computed and listed in the
//    campaign editor; the operator presses "Block selected".
//  - auto: zone_rules_cron.php executes the candidates (capped by
//    max_blocks_per_run) and notifies Telegram.
//
// Blocking goes through the engine's verified write API:
//  - AdMaven       PUT /api/public/targeting/source_id (GET-merge-PUT; refuses
//                  whitelist-mode campaigns — a blacklist entry there is a no-op)
//  - HilltopAds    POST /advertiser/addToBlackList, DELETE removeFromBlackList
//                  (per zone)
//  - PropellerAds  PATCH /adv/campaigns/{id}/targeting/exclude/zone with the
//                  mirrored {"zone":[…]} body
//  - Kadam         POST /stats/sites/block|unblock {campaignIds, zones}
//  - Galaxion      GET-merge-PATCH of the campaign's zoneLimitations
//                  (blacklist mode; refuses whitelist campaigns)
// Adsterra and Clickadu have no usable zone write API: their rules run in
// dry-run only — the editor lists the candidates for copying into the
// network's blacklist by hand, and auto mode is refused for them.
// Every action lands in the zone_blocks journal (append-only, with the metrics
// behind the decision); the current blocked set = zones whose latest ok action
// is 'block'.

require_once __DIR__ . '/CostImporter.php';

class ZoneRules
{
    /** Every push/pop cost engine a rule can bind to (candidates come from clicks). */
    public const PUSH_ENGINES = ['admaven', 'hilltopads', 'propellerads', 'kadam', 'galaxion', 'adsterra', 'clickadu'];

    /** Engines with a zone blocking API (setZoneBlock()). The rest are manual. */
    private const ENGINE_CLASSES = [
        'admaven' => 'AdMavenEngine',
        'hilltopads' => 'HilltopAdsEngine',
        'propellerads' => 'PropellerAdsEngine',
        'kadam' => 'KadamEngine',
        'galaxion' => 'GalaxionEngine',
    ];

    public static function canBlock(string $engine): bool
    {
        return isset(self::ENGINE_CLASSES[strtolower($engine)]);
    }

    /** Candidate campaign-id keys on clicks, most specific to push first. */
    private const CAMPAIGN_KEYS = ['ad_campaign_id', 'campaign_id', 'campaign', 'campaignid'];

    public static function ruleFor(PDO $pdo, int $campaignId): ?array
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM zone_rules WHERE campaign_id = ? LIMIT 1");
            $stmt->execute([$campaignId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Upsert the rule for a campaign. Values are clamped here so neither the
     * UI nor the API can arm a rule that blocks everything.
     *
     * @return array the stored rule
     */
    public static function saveRule(PDO $pdo, int $campaignId, array $in): array
    {
        $connectionId = (int) ($in['connection_id'] ?? 0);
        if ($connectionId <= 0) {
            throw new \RuntimeException('connection_id required');
        }
        // Only a push/pop cost connection can execute blocks.
        $stmt = $pdo->prepare("SELECT engine FROM aggregator_connections WHERE id = ? LIMIT 1");
        $stmt->execute([$connectionId]);
        $engine = strtolower((string) $stmt->fetchColumn());
        if (!in_array($engine, self::PUSH_ENGINES, true)) {
            throw new \RuntimeException('connection must be a push/pop cost connection (Integrations → Push/Pop networks)');
        }
        $mode = (($in['mode'] ?? 'dry_run') === 'auto') ? 'auto' : 'dry_run';
        if ($mode === 'auto' && !self::canBlock($engine)) {
            // No write API: auto mode would only pretend to block.
            $mode = 'dry_run';
        }

        $rule = [
            'campaign_id' => $campaignId,
            'connection_id' => $connectionId,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
            'mode' => $mode,
            'window_days' => max(1, min(30, (int) ($in['window_days'] ?? 7))),
            'min_spend' => max(0.0, (float) ($in['min_spend'] ?? 3)),
            'require_zero_conversions' => !isset($in['require_zero_conversions']) || $in['require_zero_conversions'] ? 1 : 0,
            'max_roi' => ($in['max_roi'] !== null && $in['max_roi'] !== '' && isset($in['max_roi']))
                ? max(-100.0, min(0.0, (float) $in['max_roi']))
                : null,
            'max_blocks_per_run' => max(1, min(200, (int) ($in['max_blocks_per_run'] ?? 20))),
        ];

        $pdo->prepare("
            INSERT INTO zone_rules (campaign_id, connection_id, is_active, mode, window_days, min_spend, require_zero_conversions, max_roi, max_blocks_per_run, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,datetime('now'))
            ON CONFLICT(campaign_id) DO UPDATE SET
                connection_id = excluded.connection_id,
                is_active = excluded.is_active,
                mode = excluded.mode,
                window_days = excluded.window_days,
                min_spend = excluded.min_spend,
                require_zero_conversions = excluded.require_zero_conversions,
                max_roi = excluded.max_roi,
                max_blocks_per_run = excluded.max_blocks_per_run,
                updated_at = datetime('now')
        ")->execute([
            $rule['campaign_id'], $rule['connection_id'], $rule['is_active'], $rule['mode'],
            $rule['window_days'], $rule['min_spend'], $rule['require_zero_conversions'],
            $rule['max_roi'], $rule['max_blocks_per_run'],
        ]);
        return self::ruleFor($pdo, $campaignId) ?? $rule;
    }

    /**
     * The zone table for a campaign over the rule's window (a default window
     * when no rule exists yet, so the editor can preview before saving).
     * Spend/revenue are the per-click distributed numbers the reports show;
     * conversions counted by the per-click is_conversion flag.
     *
     * @return array{window_days:int, from:string, to:string, zones:array[], candidates:string[]}
     */
    public static function candidates(PDO $pdo, int $campaignId, ?array $rule = null): array
    {
        $rule = $rule ?? self::ruleFor($pdo, $campaignId);
        $windowDays = max(1, min(30, (int) ($rule['window_days'] ?? 7)));
        $minSpend = (float) ($rule['min_spend'] ?? 3);
        $requireZero = !isset($rule['require_zero_conversions']) || (int) $rule['require_zero_conversions'] === 1;
        $maxRoi = ($rule['max_roi'] ?? null) !== null && $rule['max_roi'] !== '' && $rule['max_roi'] !== null ? (float) $rule['max_roi'] : null;

        // The zone keys the importer matched on for THIS connection.
        $zoneKeys = CostImporter::zoneAttributionKeys();
        if (!empty($rule['connection_id'])) {
            try {
                $stmt = $pdo->prepare("SELECT field_mapping_json FROM aggregator_connections WHERE id = ?");
                $stmt->execute([(int) $rule['connection_id']]);
                $fm = json_decode((string) $stmt->fetchColumn(), true);
                if (is_array($fm)) {
                    $zoneKeys = CostImporter::zoneAttributionKeys($fm);
                }
            } catch (\Throwable $e) {
            }
        }

        $from = gmdate('Y-m-d 00:00:00', time() - ($windowDays - 1) * 86400);
        $zones = [];
        foreach ($zoneKeys as $zk) {
            $safe = preg_replace('/[^A-Za-z0-9_]/', '', $zk);
            if ($safe === '') {
                continue;
            }
            try {
                $stmt = $pdo->prepare("
                    SELECT json_extract(parameters_json, '$.{$safe}') AS zone,
                           COUNT(*) AS clicks,
                           SUM(cost) AS spend,
                           SUM(revenue) AS revenue,
                           SUM(CASE WHEN is_conversion = 1 THEN 1 ELSE 0 END) AS conversions
                    FROM clicks
                    WHERE campaign_id = ?
                      AND created_at >= ?
                      AND json_extract(parameters_json, '$.{$safe}') IS NOT NULL
                      AND json_extract(parameters_json, '$.{$safe}') != ''
                    GROUP BY zone
                ");
                $stmt->execute([$campaignId, $from]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $zone = (string) $row['zone'];
                    if ($zone === '') {
                        continue;
                    }
                    if (!isset($zones[$zone])) {
                        $zones[$zone] = ['zone' => $zone, 'clicks' => 0, 'spend' => 0.0, 'revenue' => 0.0, 'conversions' => 0];
                    }
                    $zones[$zone]['clicks'] += (int) $row['clicks'];
                    $zones[$zone]['spend'] += (float) $row['spend'];
                    $zones[$zone]['revenue'] += (float) $row['revenue'];
                    $zones[$zone]['conversions'] += (int) $row['conversions'];
                }
            } catch (\Throwable $e) {
                // Missing column on a legacy schema: the zone table degrades
                // to the keys that do resolve instead of failing the page.
            }
        }

        $blocked = self::blockedZones($pdo, $campaignId);
        $candidates = [];
        foreach ($zones as &$z) {
            $z['roi'] = $z['spend'] > 0 ? round(($z['revenue'] - $z['spend']) / $z['spend'] * 100, 1) : null;
            $z['blocked'] = isset($blocked[$z['zone']]);
            $z['candidate'] = false;
            if (!$z['blocked'] && $z['spend'] >= $minSpend
                && (!$requireZero || $z['conversions'] === 0)
                && ($maxRoi === null || ($z['roi'] !== null && $z['roi'] <= $maxRoi))) {
                $z['candidate'] = true;
                $candidates[] = $z['zone'];
            }
        }
        unset($z);
        usort($zones, fn ($a, $b) => $b['spend'] <=> $a['spend']);

        return [
            'window_days' => $windowDays,
            'from' => $from,
            'to' => gmdate('Y-m-d H:i:s'),
            'zones' => array_values($zones),
            'candidates' => $candidates,
        ];
    }

    /**
     * Current blocked set: zone => ['at'=>…, 'reason'=>…] — zones whose latest
     * successful action in the journal is a block.
     */
    public static function blockedZones(PDO $pdo, int $campaignId): array
    {
        $out = [];
        try {
            $stmt = $pdo->prepare("
                SELECT zone_id, action, ok, created_at, reason FROM zone_blocks
                WHERE campaign_id = ? AND ok = 1
                ORDER BY id ASC
            ");
            $stmt->execute([$campaignId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['action'] === 'block') {
                    $out[$row['zone_id']] = ['at' => $row['created_at'], 'reason' => $row['reason']];
                } else {
                    unset($out[$row['zone_id']]);
                }
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /**
     * The network campaign id this Orbitra campaign's clicks carry — the id
     * the blocking API needs. Most frequent non-macro value wins; a click
     * usually carries exactly one.
     */
    public static function networkCampaignId(PDO $pdo, int $campaignId): ?string
    {
        foreach (self::CAMPAIGN_KEYS as $key) {
            $safe = preg_replace('/[^A-Za-z0-9_]/', '', $key);
            try {
                $stmt = $pdo->prepare("
                    SELECT json_extract(parameters_json, '$.{$safe}') AS v, COUNT(*) AS c
                    FROM clicks
                    WHERE campaign_id = ?
                      AND json_extract(parameters_json, '$.{$safe}') IS NOT NULL
                      AND json_extract(parameters_json, '$.{$safe}') != ''
                      AND json_extract(parameters_json, '$.{$safe}') NOT LIKE '%\\_\\_%' ESCAPE '\\'
                      AND json_extract(parameters_json, '$.{$safe}') NOT LIKE '%{%'
                    GROUP BY v ORDER BY c DESC LIMIT 1
                ");
                $stmt->execute([$campaignId]);
                $value = (string) ($stmt->fetchColumn() ?: '');
                if ($value !== '') {
                    return $value;
                }
            } catch (\Throwable $e) {
            }
        }
        return null;
    }

    /**
     * Block zones through the connection's engine and journal every action.
     *
     * @param string[] $zones
     * @return array{ok:int, failed:int, error?:string, results:array[]}
     */
    public static function block(PDO $pdo, int $campaignId, array $zones, string $actedBy = 'manual', string $reason = ''): array
    {
        return self::apply($pdo, $campaignId, $zones, true, $actedBy, $reason);
    }

    /** @param string[] $zones */
    public static function unblock(PDO $pdo, int $campaignId, array $zones, string $actedBy = 'manual'): array
    {
        return self::apply($pdo, $campaignId, $zones, false, $actedBy, '');
    }

    private static function apply(PDO $pdo, int $campaignId, array $zones, bool $block, string $actedBy, string $reason): array
    {
        $zones = array_values(array_unique(array_filter(array_map('strval', $zones), fn ($z) => $z !== '')));
        if ($zones === []) {
            return ['ok' => 0, 'failed' => 0, 'results' => []];
        }

        $rule = self::ruleFor($pdo, $campaignId);
        if (!$rule) {
            return ['ok' => 0, 'failed' => count($zones), 'error' => 'no zone rule for this campaign', 'results' => []];
        }

        $connectionId = (int) $rule['connection_id'];
        try {
            $stmt = $pdo->prepare("SELECT engine, credentials_json, field_mapping_json FROM aggregator_connections WHERE id = ? LIMIT 1");
            $stmt->execute([$connectionId]);
            $conn = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $conn = [];
        }
        $engine = strtolower((string) ($conn['engine'] ?? ''));
        $credentials = json_decode((string) ($conn['credentials_json'] ?? ''), true);
        $credentials = is_array($credentials) ? $credentials : [];
        $engineClass = self::engineClass($engine);
        if ($engineClass === null) {
            return ['ok' => 0, 'failed' => count($zones), 'error' => "this network has no zone blocking API — copy the zones and add them to the campaign's blacklist in the network panel", 'results' => []];
        }

        $networkCampaignId = self::networkCampaignId($pdo, $campaignId);
        if ($networkCampaignId === null) {
            return ['ok' => 0, 'failed' => count($zones), 'error' => 'cannot tell the network campaign id — no click of this campaign carries ad_campaign_id', 'results' => []];
        }

        $metricsByZone = [];
        foreach (self::candidates($pdo, $campaignId, $rule)['zones'] as $z) {
            $metricsByZone[$z['zone']] = ['spend' => $z['spend'], 'revenue' => $z['revenue'], 'roi' => $z['roi'], 'conversions' => $z['conversions']];
        }

        require_once __DIR__ . '/../aggregator_engines/' . $engineClass . '.php';
        $result = $engineClass::setZoneBlock($credentials, $networkCampaignId, $zones, $block);

        $journal = $pdo->prepare("
            INSERT INTO zone_blocks (campaign_id, connection_id, engine, network_campaign_id, zone_id, action, reason, metrics_json, acted_by, ok, error)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");

        $ok = 0;
        $failed = 0;
        $results = [];
        if (!empty($result['ok'])) {
            foreach ($zones as $zone) {
                $journal->execute([
                    $campaignId, $connectionId, $engine, $networkCampaignId, $zone,
                    $block ? 'block' : 'unblock', $reason,
                    json_encode($metricsByZone[$zone] ?? []), $actedBy, 1, '',
                ]);
                $ok++;
                $results[] = ['zone' => $zone, 'ok' => true];
            }
        } else {
            // One journal row for the attempt so the failure is visible in the
            // history; error attached to the first zone keeps the list compact.
            $journal->execute([
                $campaignId, $connectionId, $engine, $networkCampaignId, $zones[0],
                $block ? 'block' : 'unblock', $reason,
                json_encode($metricsByZone[$zones[0]] ?? []), $actedBy, 0,
                (string) ($result['error'] ?? 'unknown error'),
            ]);
            $failed = count($zones);
            foreach ($zones as $zone) {
                $results[] = ['zone' => $zone, 'ok' => false, 'error' => (string) ($result['error'] ?? 'unknown error')];
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'error' => $result['error'] ?? null, 'results' => $results];
    }

    /**
     * One cron pass: every active auto rule — compute candidates, cap them at
     * max_blocks_per_run, block, journal (acted_by='auto'), notify Telegram.
     *
     * @return array{rules:int, blocked:int, details:array[]}
     */
    public static function runAuto(PDO $pdo, ?int $onlyCampaignId = null): array
    {
        $summary = ['rules' => 0, 'blocked' => 0, 'details' => []];
        try {
            $sql = "SELECT r.*, c.name AS campaign_name, ac.engine AS engine FROM zone_rules r
                    JOIN campaigns c ON c.id = r.campaign_id
                    LEFT JOIN aggregator_connections ac ON ac.id = r.connection_id
                    WHERE r.is_active = 1 AND r.mode = 'auto'";
            if ($onlyCampaignId !== null) {
                $sql .= ' AND r.campaign_id = ' . (int) $onlyCampaignId;
            }
            $rules = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return $summary;
        }

        foreach ($rules as $rule) {
            $campaignId = (int) $rule['campaign_id'];
            $summary['rules']++;
            if (!self::canBlock((string) ($rule['engine'] ?? ''))) {
                // A rule saved before its connection changed network, or set
                // to auto by hand in the DB: never fake a block.
                $summary['details'][] = ['campaign_id' => $campaignId, 'status' => 'manual_only'];
                continue;
            }
            try {
                $table = self::candidates($pdo, $campaignId, $rule);
                $zones = array_slice($table['candidates'], 0, (int) $rule['max_blocks_per_run']);
                $pdo->prepare("UPDATE zone_rules SET last_run_at = datetime('now') WHERE campaign_id = ?")->execute([$campaignId]);
                if ($zones === []) {
                    $summary['details'][] = ['campaign_id' => $campaignId, 'status' => 'no_candidates'];
                    continue;
                }
                $reason = sprintf(
                    'auto: spend>=%s over %dd%s%s',
                    rtrim(rtrim(number_format((float) $rule['min_spend'], 2, '.', ''), '0'), '.'),
                    (int) $rule['window_days'],
                    ((int) $rule['require_zero_conversions'] === 1) ? ', 0 conversions' : '',
                    ($rule['max_roi'] !== null && $rule['max_roi'] !== '') ? sprintf(', ROI<=%s%%', $rule['max_roi']) : ''
                );
                $result = self::block($pdo, $campaignId, $zones, 'auto', $reason);
                $summary['blocked'] += (int) $result['ok'];
                $summary['details'][] = [
                    'campaign_id' => $campaignId,
                    'status' => ((int) $result['ok'] > 0) ? 'blocked' : 'error',
                    'zones' => $zones,
                    'ok' => (int) $result['ok'],
                    'failed' => (int) $result['failed'],
                    'error' => $result['error'] ?? null,
                ];
                if ((int) $result['ok'] > 0) {
                    self::notifyTelegram($pdo, sprintf(
                        "Zone Killer: blocked %d zone(s) on «%s» (#%d)\n%s",
                        (int) $result['ok'],
                        (string) $rule['campaign_name'],
                        $campaignId,
                        implode(', ', $zones)
                    ));
                }
            } catch (\Throwable $e) {
                $summary['details'][] = ['campaign_id' => $campaignId, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }
        return $summary;
    }

    /** @return class-string|null */
    private static function engineClass(string $engine): ?string
    {
        return self::ENGINE_CLASSES[strtolower($engine)] ?? null;
    }

    /**
     * Same delivery contract as ConversionMonitor: the panel's own bot, every
     * chat that turned notifications on. Plain text — the metrics carry
     * percent signs Markdown would eat.
     */
    private static function notifyTelegram(PDO $pdo, string $message): void
    {
        try {
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ? LIMIT 1');
            $stmt->execute(['telegram_bot_token']);
            $token = (string) $stmt->fetchColumn();
            $stmt->closeCursor();
            if ($token === '') {
                return;
            }
            $chats = $pdo->query('SELECT chat_id FROM telegram_bot_chats WHERE is_active = 1 AND notify_conversions = 1')->fetchAll(PDO::FETCH_COLUMN);
            if (!$chats) {
                return;
            }
            require_once __DIR__ . '/telegram_api.php';
            foreach ($chats as $chatId) {
                orbitraTelegramApi($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $message], 10);
            }
        } catch (\Throwable $e) {
            // Notification must never break a blocking pass — the journal row
            // already records what happened.
        }
    }
}
