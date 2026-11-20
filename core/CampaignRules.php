<?php
// core/CampaignRules.php
//
// Campaign rules — "if these numbers over the last N hours, then do X".
//
// One rule belongs to one campaign and judges one of three targets:
//   - 'campaign'  the campaign as a whole;
//   - 'offers'    every offer that is currently ON in one of the campaign's
//                 stream rotations, separately;
//   - 'landings'  the same for landings.
//
// Conditions are ANDed: [{metric, op, value}, …]. Metrics come from the same
// formulas the reports use (orbitraComputeDerivedMetrics over the same
// conversion aggregate, safe-page traffic excluded with the shared report
// predicate), so a rule fires on exactly the numbers the user sees.
//
// Actions:
//   - 'notify'          Telegram only (the default — nothing changes);
//   - 'pause_campaign'  campaign target only: state = 'disabled', the same
//                       write as the play/pause toggle in the campaigns table;
//   - 'disable_item'    offers/landings target only: the item gets
//                       state = 'paused' in every stream rotation of the
//                       campaign — but NEVER the last enabled item of a list,
//                       so a rule can not leave a stream with nothing to show.
//
// A target that fired is not judged again by the same rule for
// cooldown_hours (the journal is the memory), so a rule over a 24-hour window
// does not send the same alert every 15 minutes.
//
// Execution rides rotation_optimiser_cron.php (every minute on every
// install since the optimiser shipped), so existing servers need no new
// crontab line; each rule carries its own check interval.

require_once __DIR__ . '/ReportMetrics.php';

class CampaignRules
{
    public const TARGETS = ['campaign', 'offers', 'landings'];
    public const ACTIONS = ['notify', 'pause_campaign', 'disable_item'];
    public const OPS = ['gt', 'gte', 'lt', 'lte', 'eq'];
    /** Metric id => needs spend to mean anything. */
    public const METRICS = [
        'clicks' => false,
        'conversions' => false,
        'sales' => false,
        'registrations' => false,
        'deposits' => false,
        'cost' => true,
        'revenue' => false,
        'profit' => true,
        'roi' => true,
        'cr' => false,
        'epc' => false,
    ];
    public const MAX_CONDITIONS = 5;
    public const MAX_RULES_PER_CAMPAIGN = 20;

    /** Default shape of a rule, also what the editor starts from. */
    public static function defaults(): array
    {
        return [
            'name' => '',
            'is_active' => 1,
            'target' => 'campaign',
            'action' => 'notify',
            'conditions' => [
                ['metric' => 'cost', 'op' => 'gte', 'value' => 20],
                ['metric' => 'sales', 'op' => 'eq', 'value' => 0],
            ],
            'window_hours' => 24,
            'cooldown_hours' => 24,
            'interval_min' => 15,
        ];
    }

    /**
     * Validate a client payload into a storable rule. Returns [rule, error].
     * The action is coerced to one the target supports.
     */
    public static function normalize(array $in): array
    {
        $d = self::defaults();
        $target = in_array($in['target'] ?? null, self::TARGETS, true) ? $in['target'] : $d['target'];
        $action = in_array($in['action'] ?? null, self::ACTIONS, true) ? $in['action'] : 'notify';
        if ($target === 'campaign' && $action === 'disable_item') {
            $action = 'notify';
        }
        if ($target !== 'campaign' && $action === 'pause_campaign') {
            $action = 'notify';
        }
        $conds = [];
        foreach (is_array($in['conditions'] ?? null) ? $in['conditions'] : [] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $metric = (string) ($c['metric'] ?? '');
            $op = (string) ($c['op'] ?? '');
            if (!array_key_exists($metric, self::METRICS) || !in_array($op, self::OPS, true)) {
                continue;
            }
            if (!isset($c['value']) || $c['value'] === '' || !is_numeric($c['value'])) {
                continue;
            }
            $conds[] = ['metric' => $metric, 'op' => $op, 'value' => (float) $c['value']];
            if (count($conds) >= self::MAX_CONDITIONS) {
                break;
            }
        }
        if (!$conds) {
            return [null, 'conditions_required'];
        }
        $int = static function ($v, int $lo, int $hi, int $dflt): int {
            $v = is_numeric($v) ? (int) $v : $dflt;
            return max($lo, min($hi, $v));
        };
        $name = trim((string) ($in['name'] ?? ''));
        return [[
            'name' => mb_substr($name, 0, 120),
            'is_active' => !empty($in['is_active']) && !in_array($in['is_active'], ['0', 'false'], true) ? 1 : 0,
            'target' => $target,
            'action' => $action,
            'conditions' => $conds,
            'window_hours' => $int($in['window_hours'] ?? null, 1, 24 * 30, $d['window_hours']),
            'cooldown_hours' => $int($in['cooldown_hours'] ?? null, 1, 24 * 30, $d['cooldown_hours']),
            'interval_min' => $int($in['interval_min'] ?? null, 5, 1440, $d['interval_min']),
        ], null];
    }

    public static function decode(array $row): array
    {
        $row['conditions'] = json_decode((string) ($row['conditions_json'] ?? '[]'), true) ?: [];
        unset($row['conditions_json']);
        foreach (['id', 'campaign_id', 'is_active', 'window_hours', 'cooldown_hours', 'interval_min'] as $k) {
            if (isset($row[$k])) {
                $row[$k] = (int) $row[$k];
            }
        }
        return $row;
    }

    public static function listForCampaign(PDO $pdo, int $campaignId): array
    {
        $st = $pdo->prepare('SELECT * FROM campaign_rules WHERE campaign_id = ? ORDER BY id');
        $st->execute([$campaignId]);
        return array_map([self::class, 'decode'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function get(PDO $pdo, int $ruleId): ?array
    {
        $st = $pdo->prepare('SELECT * FROM campaign_rules WHERE id = ? LIMIT 1');
        $st->execute([$ruleId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::decode($r) : null;
    }

    /** Insert or update. Returns [rule, error]. */
    public static function save(PDO $pdo, int $campaignId, array $in, ?int $ruleId = null): array
    {
        [$rule, $err] = self::normalize($in);
        if ($err) {
            return [null, $err];
        }
        if ($ruleId) {
            $existing = self::get($pdo, $ruleId);
            if (!$existing || (int) $existing['campaign_id'] !== $campaignId) {
                return [null, 'not_found'];
            }
            $st = $pdo->prepare("UPDATE campaign_rules SET name = ?, is_active = ?, target = ?, action = ?,
                conditions_json = ?, window_hours = ?, cooldown_hours = ?, interval_min = ?,
                updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $st->execute([
                $rule['name'], $rule['is_active'], $rule['target'], $rule['action'],
                json_encode($rule['conditions']), $rule['window_hours'], $rule['cooldown_hours'],
                $rule['interval_min'], $ruleId,
            ]);
            return [self::get($pdo, $ruleId), null];
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM campaign_rules WHERE campaign_id = ?');
        $st->execute([$campaignId]);
        if ((int) $st->fetchColumn() >= self::MAX_RULES_PER_CAMPAIGN) {
            return [null, 'too_many_rules'];
        }
        $st = $pdo->prepare("INSERT INTO campaign_rules
            (campaign_id, name, is_active, target, action, conditions_json, window_hours, cooldown_hours, interval_min)
            VALUES (?,?,?,?,?,?,?,?,?)");
        $st->execute([
            $campaignId, $rule['name'], $rule['is_active'], $rule['target'], $rule['action'],
            json_encode($rule['conditions']), $rule['window_hours'], $rule['cooldown_hours'], $rule['interval_min'],
        ]);
        return [self::get($pdo, (int) $pdo->lastInsertId()), null];
    }

    public static function delete(PDO $pdo, int $campaignId, int $ruleId): bool
    {
        $st = $pdo->prepare('DELETE FROM campaign_rules WHERE id = ? AND campaign_id = ?');
        $st->execute([$ruleId, $campaignId]);
        return $st->rowCount() > 0;
    }

    public static function journal(PDO $pdo, int $campaignId, int $limit = 50): array
    {
        $st = $pdo->prepare('SELECT * FROM campaign_rule_log WHERE campaign_id = ? ORDER BY id DESC LIMIT ?');
        $st->bindValue(1, $campaignId, PDO::PARAM_INT);
        $st->bindValue(2, max(1, min(500, $limit)), PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['metrics'] = json_decode((string) ($r['metrics_json'] ?? '{}'), true) ?: [];
            unset($r['metrics_json']);
        }
        return $rows;
    }

    // ---- evaluation --------------------------------------------------------

    /**
     * Raw report numbers for one campaign over a window, grouped by the
     * target (null = the campaign as one row, keyed 0).
     */
    public static function metrics(PDO $pdo, int $campaignId, string $target, string $windowFrom): array
    {
        $groupCol = $target === 'offers' ? 'cl.offer_id' : ($target === 'landings' ? 'cl.landing_id' : null);
        $agg = orbitraConversionAggregateSql('payout');
        $safe = orbitraSafePagePredicate('cl.');
        $select = $groupCol ? "$groupCol AS item_id," : '0 AS item_id,';
        $where = $groupCol ? " AND $groupCol IS NOT NULL AND $groupCol > 0" : '';
        $group = $groupCol ? " GROUP BY $groupCol" : '';
        $sql = "SELECT $select
                   COUNT(cl.id) AS clicks,
                   COALESCE(SUM(cl.cost), 0) AS cost,
                   COALESCE(SUM(cva.cnt_any), 0) AS conversions,
                   COALESCE(SUM(cva.cnt_sale), 0) AS sales,
                   COALESCE(SUM(cva.cnt_registration), 0) AS registrations,
                   COALESCE(SUM(cva.cnt_deposit), 0) AS deposits,
                   COALESCE(SUM(cva.rev_all), 0) AS revenue,
                   COALESCE(SUM(cva.rev_sale), 0) AS revenue_confirmed
                FROM clicks cl
                LEFT JOIN $agg cva ON cva.click_id = cl.id
                WHERE cl.campaign_id = ? AND cl.created_at >= ? AND $safe $where $group";
        $st = $pdo->prepare($sql);
        $st->execute([$campaignId, $windowFrom]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $c = orbitraComputeDerivedMetrics($row);
            $out[(int) $row['item_id']] = [
                'clicks' => (int) $c['clicks'],
                'conversions' => (int) $c['conversions'],
                'sales' => (int) $c['sales'],
                'registrations' => (int) ($row['registrations'] ?? 0),
                'deposits' => (int) ($row['deposits'] ?? 0),
                'cost' => (float) $c['cost'],
                'revenue' => (float) $c['revenue'],
                'profit' => (float) $c['profit'],
                'roi' => $c['roi'],
                'cr' => (float) $c['cr'],
                'epc' => (float) $c['epc'],
            ];
        }
        if (!$groupCol && !$out) {
            $out[0] = self::zeroMetrics();
        }
        return $out;
    }

    public static function zeroMetrics(): array
    {
        return ['clicks' => 0, 'conversions' => 0, 'sales' => 0, 'registrations' => 0, 'deposits' => 0,
            'cost' => 0.0, 'revenue' => 0.0, 'profit' => 0.0, 'roi' => null, 'cr' => 0.0, 'epc' => 0.0];
    }

    /** Pure: do the metrics satisfy every condition? ROI with no spend never does. */
    public static function matches(array $conditions, array $m): bool
    {
        if (!$conditions) {
            return false;
        }
        foreach ($conditions as $c) {
            $v = $m[$c['metric']] ?? null;
            if ($v === null) {
                return false;
            }
            $v = (float) $v;
            $x = (float) $c['value'];
            $ok = match ($c['op']) {
                'gt' => $v > $x,
                'gte' => $v >= $x,
                'lt' => $v < $x,
                'lte' => $v <= $x,
                'eq' => abs($v - $x) < 0.000001,
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /**
     * Items currently ON in at least one stream rotation of the campaign:
     * id => ['name' => …, 'streams' => [stream ids]]. Items with no rotation
     * entry (a one-offer stream stored in streams.offer_id) are not
     * candidates: there is nothing to switch to.
     */
    public static function rotationItems(PDO $pdo, int $campaignId, string $target): array
    {
        $st = $pdo->prepare("SELECT id, schema_custom_json FROM streams
                             WHERE campaign_id = ? AND is_active = 1
                               AND schema_custom_json IS NOT NULL AND schema_custom_json != ''");
        $st->execute([$campaignId]);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $custom = json_decode((string) $s['schema_custom_json'], true);
            $list = is_array($custom[$target] ?? null) ? $custom[$target] : [];
            foreach ($list as $it) {
                if (!is_array($it) || empty($it['id']) || !self::itemEnabled($it)) {
                    continue;
                }
                $items[(int) $it['id']]['streams'][] = (int) $s['id'];
            }
        }
        if ($items) {
            $table = $target === 'landings' ? 'landings' : 'offers';
            $ids = array_keys($items);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id, name FROM $table WHERE id IN ($ph)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[(int) $r['id']]['name'] = (string) $r['name'];
            }
            foreach ($items as $id => &$it) {
                $it['name'] = $it['name'] ?? ('#' . $id);
            }
        }
        return $items;
    }

    private static function itemEnabled(array $it): bool
    {
        $state = $it['state'] ?? null;
        $isActive = $it['is_active'] ?? true;
        return !in_array($state, ['disabled', 'paused'], true) && !in_array($isActive, [false, 0, '0'], true);
    }

    /**
     * What a rule would hit right now, without acting:
     * [['target_id', 'target_name', 'metrics', 'match', 'cooldown'], …].
     */
    public static function preview(PDO $pdo, array $rule, ?int $now = null): array
    {
        $now = $now ?? time();
        $campaignId = (int) $rule['campaign_id'];
        $windowFrom = gmdate('Y-m-d H:i:s', $now - (int) $rule['window_hours'] * 3600);
        $metrics = self::metrics($pdo, $campaignId, $rule['target'], $windowFrom);
        $rows = [];
        if ($rule['target'] === 'campaign') {
            $st = $pdo->prepare('SELECT name, state FROM campaigns WHERE id = ?');
            $st->execute([$campaignId]);
            $c = $st->fetch(PDO::FETCH_ASSOC) ?: ['name' => '#' . $campaignId, 'state' => 'active'];
            $m = $metrics[0] ?? self::zeroMetrics();
            $rows[] = [
                'target_id' => $campaignId,
                'target_name' => (string) $c['name'],
                'metrics' => $m,
                'match' => self::matches($rule['conditions'], $m),
                'cooldown' => !empty($rule['id']) && self::inCooldown($pdo, $rule, $campaignId, $now),
                'already' => strtolower((string) $c['state']) === 'disabled',
            ];
            return $rows;
        }
        foreach (self::rotationItems($pdo, $campaignId, $rule['target']) as $id => $it) {
            $m = $metrics[$id] ?? self::zeroMetrics();
            $rows[] = [
                'target_id' => $id,
                'target_name' => $it['name'],
                'metrics' => $m,
                'match' => self::matches($rule['conditions'], $m),
                'cooldown' => !empty($rule['id']) && self::inCooldown($pdo, $rule, $id, $now),
                'already' => false,
            ];
        }
        usort($rows, static fn($a, $b) => ($b['match'] <=> $a['match']) ?: ($b['metrics']['cost'] <=> $a['metrics']['cost']));
        return $rows;
    }

    private static function inCooldown(PDO $pdo, array $rule, int $targetId, int $now): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM campaign_rule_log
                             WHERE rule_id = ? AND target_id = ? AND status IN ('ok', 'notified') AND created_at >= ?
                             LIMIT 1");
        $st->execute([(int) $rule['id'], $targetId, gmdate('Y-m-d H:i:s', $now - (int) $rule['cooldown_hours'] * 3600)]);
        return (bool) $st->fetchColumn();
    }

    /**
     * One cron pass: every active rule whose interval elapsed, on campaigns
     * that are not archived. Options: now, force, campaign_id, sender (tests).
     */
    public static function runAll(PDO $pdo, array $opts = []): array
    {
        $now = isset($opts['now']) ? (int) $opts['now'] : time();
        $force = !empty($opts['force']);
        $summary = ['rules' => 0, 'fired' => 0, 'actions' => 0, 'details' => []];

        try {
            $sql = "SELECT r.* FROM campaign_rules r JOIN campaigns c ON c.id = r.campaign_id
                    WHERE r.is_active = 1 AND COALESCE(c.is_archived, 0) = 0";
            $params = [];
            if (!empty($opts['campaign_id'])) {
                $sql .= ' AND r.campaign_id = ?';
                $params[] = (int) $opts['campaign_id'];
            }
            $st = $pdo->prepare($sql . ' ORDER BY r.id');
            $st->execute($params);
            $rules = array_map([self::class, 'decode'], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            // Table missing on a half-migrated DB: nothing to run.
            return $summary;
        }

        foreach ($rules as $rule) {
            if (!$force && !empty($rule['last_run_at'])) {
                $last = strtotime((string) $rule['last_run_at'] . ' UTC');
                if ($last !== false && $now < $last + max(5, (int) $rule['interval_min']) * 60) {
                    continue;
                }
            }
            $summary['rules']++;
            try {
                $res = self::runRule($pdo, $rule, $now, $opts);
                $summary['fired'] += $res['fired'];
                $summary['actions'] += $res['actions'];
                $summary['details'][] = ['rule_id' => $rule['id'], 'campaign_id' => $rule['campaign_id']] + $res;
                $status = $res['fired'] > 0 ? 'fired' : 'ok';
            } catch (\Throwable $e) {
                $status = 'error: ' . mb_substr($e->getMessage(), 0, 200);
                $summary['details'][] = ['rule_id' => $rule['id'], 'campaign_id' => $rule['campaign_id'],
                    'fired' => 0, 'actions' => 0, 'error' => $e->getMessage()];
            }
            $up = $pdo->prepare('UPDATE campaign_rules SET last_run_at = ?, last_status = ? WHERE id = ?');
            $up->execute([gmdate('Y-m-d H:i:s', $now), $status, (int) $rule['id']]);
        }
        return $summary;
    }

    /** Evaluate one rule and act on every matching target out of cooldown. */
    public static function runRule(PDO $pdo, array $rule, int $now, array $opts = []): array
    {
        $out = ['fired' => 0, 'actions' => 0];
        $campaignName = self::campaignName($pdo, (int) $rule['campaign_id']);
        foreach (self::preview($pdo, $rule, $now) as $row) {
            if (!$row['match'] || $row['cooldown']) {
                continue;
            }
            if ($rule['action'] === 'pause_campaign' && $row['already']) {
                continue; // already off — nothing to do, nothing to say
            }
            $out['fired']++;
            [$status, $message] = self::act($pdo, $rule, $row);
            if ($status === 'ok') {
                $out['actions']++;
            }
            $log = $pdo->prepare("INSERT INTO campaign_rule_log
                (rule_id, campaign_id, rule_name, target_type, target_id, target_name, action, status, message, metrics_json, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $log->execute([
                (int) $rule['id'], (int) $rule['campaign_id'], (string) $rule['name'], $rule['target'],
                (int) $row['target_id'], (string) $row['target_name'], $rule['action'], $status, $message,
                json_encode($row['metrics']), gmdate('Y-m-d H:i:s', $now),
            ]);
            if ($status === 'ok' || $status === 'notified') {
                self::notify($pdo, $rule, $row, $campaignName, $opts);
            }
        }
        return $out;
    }

    /** @return array{0:string,1:string} [status, message] */
    private static function act(PDO $pdo, array $rule, array $row): array
    {
        if ($rule['action'] === 'notify') {
            return ['notified', ''];
        }
        if ($rule['action'] === 'pause_campaign') {
            $st = $pdo->prepare("UPDATE campaigns SET state = 'disabled' WHERE id = ?");
            $st->execute([(int) $rule['campaign_id']]);
            self::audit($pdo, (int) $rule['campaign_id'], 'State: disabled (rule #' . $rule['id'] . ')');
            return ['ok', ''];
        }
        // disable_item — every rotation of the campaign that has it ON, unless
        // it is the last enabled item there.
        $itemId = (int) $row['target_id'];
        $list = $rule['target'];
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT id, schema_custom_json FROM streams WHERE campaign_id = ? AND is_active = 1");
            $st->execute([(int) $rule['campaign_id']]);
            $changed = 0;
            $kept = 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $custom = json_decode((string) $s['schema_custom_json'], true);
                if (!is_array($custom) || !is_array($custom[$list] ?? null)) {
                    continue;
                }
                $idx = null;
                $enabled = 0;
                foreach ($custom[$list] as $i => $it) {
                    if (!is_array($it) || !self::itemEnabled($it)) {
                        continue;
                    }
                    $enabled++;
                    if ((int) ($it['id'] ?? 0) === $itemId) {
                        $idx = $i;
                    }
                }
                if ($idx === null) {
                    continue;
                }
                if ($enabled <= 1) {
                    $kept++;
                    continue;
                }
                $custom[$list][$idx]['state'] = 'paused';
                $custom[$list][$idx]['paused_by_rule'] = (int) $rule['id'];
                $up = $pdo->prepare('UPDATE streams SET schema_custom_json = ? WHERE id = ?');
                $up->execute([json_encode($custom, JSON_UNESCAPED_UNICODE), (int) $s['id']]);
                $changed++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['error', $e->getMessage()];
        }
        if ($changed === 0) {
            return ['skipped', $kept > 0 ? 'last_enabled_item' : 'not_in_rotation'];
        }
        self::audit($pdo, (int) $rule['campaign_id'],
            ucfirst(rtrim($list, 's')) . " #$itemId paused in $changed stream(s) (rule #" . $rule['id'] . ')');
        return ['ok', $kept > 0 ? 'kept_as_last_item' : ''];
    }

    private static function audit(PDO $pdo, int $campaignId, string $text): void
    {
        try {
            // Same row shape as api.php logAudit(); ip/user agent mark the cron.
            $st = $pdo->prepare("INSERT INTO audit_logs (action, resource, resource_id, context, ip, user_agent, status_code)
                                 VALUES ('UPDATE', 'Campaign', ?, ?, '', 'campaign-rules-cron', 200)");
            $st->execute([$campaignId, $text]);
        } catch (\Throwable $e) {
            // audit_log shape differs on very old installs — the rule journal
            // already has the row.
        }
    }

    private static function campaignName(PDO $pdo, int $id): string
    {
        $st = $pdo->prepare('SELECT name FROM campaigns WHERE id = ?');
        $st->execute([$id]);
        return (string) ($st->fetchColumn() ?: ('#' . $id));
    }

    /** Human condition line for a message, in the chat language. */
    public static function describeConditions(array $conditions, array $m, string $lang): string
    {
        require_once __DIR__ . '/AutomationNotify.php';
        $sym = ['gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤', 'eq' => '='];
        $parts = [];
        foreach ($conditions as $c) {
            $parts[] = orbitraAutomationText($lang, 'm_' . $c['metric']) . ' '
                . orbitraAutomationNum($m[$c['metric']] ?? null)
                . ' (' . ($sym[$c['op']] ?? $c['op']) . ' ' . orbitraAutomationNum($c['value']) . ')';
        }
        return implode(', ', $parts);
    }

    private static function notify(PDO $pdo, array $rule, array $row, string $campaignName, array $opts): void
    {
        require_once __DIR__ . '/AutomationNotify.php';
        $ruleName = $rule['name'] !== '' ? $rule['name'] : ('#' . $rule['id']);
        orbitraAutomationBroadcast($pdo, static function (string $lang) use ($rule, $row, $campaignName, $ruleName): string {
            $lines = [];
            if ($rule['action'] === 'pause_campaign') {
                $lines[] = orbitraAutomationText($lang, 'rule_title_pause', ['rule' => $ruleName]);
            } elseif ($rule['action'] === 'disable_item') {
                $lines[] = orbitraAutomationText($lang, 'rule_title_disable', [
                    'rule' => $ruleName,
                    'kind' => orbitraAutomationText($lang, 'kind_' . $rule['target']),
                    'item' => $row['target_name'],
                ]);
            } else {
                $lines[] = orbitraAutomationText($lang, 'rule_title_notify', ['rule' => $ruleName]);
            }
            $lines[] = orbitraAutomationText($lang, 'campaign') . ': ' . $campaignName . ' (#' . $rule['campaign_id'] . ')';
            if ($rule['target'] !== 'campaign' && $rule['action'] !== 'disable_item') {
                $lines[] = orbitraAutomationText($lang, 'item_' . $rule['target']) . ': ' . $row['target_name'];
            }
            $lines[] = orbitraAutomationText($lang, 'window', ['hours' => (int) $rule['window_hours']]) . ': '
                . self::describeConditions($rule['conditions'], $row['metrics'], $lang);
            if ($rule['action'] === 'pause_campaign') {
                $lines[] = orbitraAutomationText($lang, 'pause_note');
            }
            return implode("\n", $lines);
        }, isset($opts['sender']) ? ['sender' => $opts['sender']] : []);
    }
}
