<?php
// core/RotationOptimiser.php
//
// Auto-optimisation of the landing / offer rotation inside a stream.
//
// The router never changes: it keeps reading weights from
// schema_custom_json.landings[]/.offers[] exactly as before. This module is a
// cron-side rewriter — every N minutes it recomputes the weights from the
// same metric engine the reports use (core/ReportMetrics.php), so what the
// optimiser acts on is what the user sees on screen.
//
// Settings live inside the stream's own schema_custom_json under "auto"
// ({"landings": {...}, "offers": {...}}), one config per list — they ride the
// campaign editor's save/load cycle untouched. That matters because campaign
// save DELETEs and re-INSERTs every stream row, so stream ids churn on each
// save; the config carries a stable "key" (rotation key) that audit rows are
// keyed by, so an item's decision history survives stream reinsertion.
//
// Hard rules baked into the allocator (see orbitraAllocateRotationWeights):
//   1. Warm-up — items below the minimum sample keep an equal share and do
//      not compete; only items that reached the threshold compete.
//   2. Exploration floor — no enabled item drops below floor_pct (default 5).
//   3. Cap — no item rises above cap_pct (default 70).
//   4. Dampening — one run moves at most 20 percentage points in total
//      (sum of absolute deltas), so weights drift instead of whiplashing.
//   5. Enabled weights stay integers summing to 100; disabled items keep
//      their stored weight untouched.
//   6. Fewer than two qualified items → the run does nothing at all.
//
// Metric queries exclude safe-page clicks (COALESCE(is_safe_page,0) = 0 — the
// same convention as the report surfaces): optimising on cloaked bot traffic
// would be worse than not optimising. Bot rows (is_bot=1) deliberately stay
// IN, matching the report counters the user compares against.

if (!function_exists('orbitraRotationAutoDefaults')) {

    /** Default conditions for one rotation list. */
    function orbitraRotationAutoDefaults(): array
    {
        return [
            'enabled' => false,
            'key' => null,            // stable rotation key, set on first enable
            'metric' => 'epv_confirmed',
            'min_sample' => 3,        // confirmed sales before an item competes
            'lookback_days' => 7,     // rolling window
            'floor_pct' => 5,
            'cap_pct' => 70,
            'interval_min' => 60,     // re-evaluation cadence
            // Cron bookkeeping (owned by the cron, displayed by the editor):
            'last_run_at' => null,    // ISO ts of the last due run (any outcome)
            'last_updated_at' => null,// ISO ts of the last run that changed weights
            'last_status' => null,    // 'ok' | 'ok_noop' | 'skipped_no_cost' | error text
        ];
    }

    /**
     * Metrics that need spend to mean anything. Only ROI (profit ÷ spend) is
     * cost-gated; sales/CR/EPV/EPC are revenue-per-click metrics and rank
     * offers fine without any cost sync.
     */
    function orbitraRotationMetricNeedsCost(string $metric): bool
    {
        return in_array($metric, ['roi_confirmed'], true);
    }

    function orbitraRotationMetricExists(string $metric): bool
    {
        return in_array($metric, ['sales', 'cr', 'epv_confirmed', 'epc_confirmed', 'roi_confirmed'], true);
    }

    /**
     * Clamp/validate a client-supplied auto config into a safe one. Unknown
     * keys pass through so cron bookkeeping (last_run_at, …) survives the
     * editor round-trip.
     */
    function orbitraNormalizeRotationAutoConfig(?array $cfg): array
    {
        $d = orbitraRotationAutoDefaults();
        if (!is_array($cfg)) {
            return $d;
        }
        $out = $cfg + $d;
        $out['enabled'] = !empty($cfg['enabled']);
        $out['metric'] = orbitraRotationMetricExists((string) ($cfg['metric'] ?? '')) ? (string) $cfg['metric'] : $d['metric'];
        $clampInt = static function ($v, int $lo, int $hi, int $dflt) use ($cfg) {
            $v = isset($v) && is_numeric($v) ? (int) $v : $dflt;
            return max($lo, min($hi, $v));
        };
        $out['min_sample'] = $clampInt($cfg['min_sample'] ?? null, 1, 10000, $d['min_sample']);
        $out['lookback_days'] = $clampInt($cfg['lookback_days'] ?? null, 1, 90, $d['lookback_days']);
        $out['floor_pct'] = $clampInt($cfg['floor_pct'] ?? null, 1, 50, $d['floor_pct']);
        $out['cap_pct'] = $clampInt($cfg['cap_pct'] ?? null, 10, 100, $d['cap_pct']);
        $out['interval_min'] = $clampInt($cfg['interval_min'] ?? null, 5, 1440, $d['interval_min']);
        if ($out['floor_pct'] >= $out['cap_pct']) {
            // A floor above the cap can never be satisfied; fall back to defaults.
            $out['floor_pct'] = $d['floor_pct'];
            $out['cap_pct'] = $d['cap_pct'];
        }
        if (!is_string($out['key'] ?? null)) {
            $out['key'] = isset($cfg['key']) && is_string($cfg['key']) ? $cfg['key'] : null;
        }
        return $out;
    }

    /**
     * The allocator, as a pure function.
     *
     * $items:      [['id' => mixed, 'weight' => int, 'enabled' => bool], ...]
     * $config:     normalized auto config (metric/min_sample/floor_pct/cap_pct used)
     * $metrics:    itemId => ['value' => float, 'sample' => int]
     *
     * Returns itemId => new integer weight for the ENABLED items only,
     * summing to exactly 100 — or null when this run must do nothing
     * (fewer than two qualified items, or a degenerate config).
     */
    function orbitraAllocateRotationWeights(array $items, array $config, array $metrics): ?array
    {
        // Max total absolute weight movement per run, in percentage points.
        $maxMovePP = 20;

        $enabled = [];
        foreach ($items as $it) {
            if (!empty($it['enabled'])) {
                $enabled[] = $it;
            }
        }
        $n = count($enabled);
        if ($n < 2) {
            return null;
        }

        $minSample = max(1, (int) ($config['min_sample'] ?? 3));
        $qualified = [];
        foreach ($enabled as $it) {
            $m = $metrics[$it['id']] ?? null;
            if ($m && (int) ($m['sample'] ?? 0) >= $minSample) {
                $qualified[] = $it;
            }
        }
        if (count($qualified) < 2) {
            return null; // rule 6: do nothing at all
        }
        $q = count($qualified);

        // Floor and cap yield to the sum-100 invariant when the item count
        // makes the stored bounds impossible (floor*5 items > 100 etc.).
        $floor = (float) $config['floor_pct'];
        if ($floor * $n > 100) {
            $floor = floor(100 / $n);
        }
        $cap = max((float) $config['cap_pct'], ceil(100 / $n));

        // Warm-up share: what even splitting would give an item. Non-qualified
        // enabled items keep exactly this share; qualified items compete for
        // the rest.
        $warm = min(max(100.0 / $n, $floor), $cap);
        $warmCount = $n - $q;
        $pool = 100.0 - $warm * $warmCount;
        if ($pool < $q * $floor) {
            // Squeeze the warm-up share as far as the floor allows.
            $warm = max($floor, (100.0 - $q * $floor) / max(1, $warmCount));
            $pool = 100.0 - $warm * $warmCount;
            if ($pool < $q * $floor) {
                return null; // config cannot sum to 100 — refuse to guess
            }
        }

        // Values clamped at zero: a negative-ROI item is a loser — it
        // keeps the floor but takes no proportional share.
        $vals = [];
        $sumVals = 0.0;
        foreach ($qualified as $it) {
            $v = max(0.0, (float) ($metrics[$it['id']]['value'] ?? 0));
            $vals[$it['id']] = $v;
            $sumVals += $v;
        }

        // Proportional split of the pool with floor/cap water-filling: each
        // pass computes the proportional share of every still-free item;
        // items landing outside [floor, cap] get fixed at the bound and the
        // leftover pool is re-split among the rest. When nothing lands
        // outside, the provisional shares ARE the result.
        $shares = [];
        $free = [];
        foreach ($qualified as $it) {
            $free[] = $it['id'];
        }
        $poolLeft = $pool;
        for ($pass = 0; $pass <= $n + 2; $pass++) {
            $freeSum = 0.0;
            foreach ($free as $id) {
                $freeSum += $vals[$id];
            }
            $assign = [];
            $fixFloor = [];
            $fixCap = [];
            foreach ($free as $id) {
                $share = $freeSum > 0
                    ? $poolLeft * $vals[$id] / $freeSum
                    : $poolLeft / max(1, count($free));
                if ($share < $floor - 0.0001) {
                    $fixFloor[] = $id;
                } elseif ($share > $cap + 0.0001) {
                    $fixCap[] = $id;
                } else {
                    $assign[$id] = $share;
                }
            }
            if (!$fixFloor && !$fixCap) {
                foreach ($assign as $id => $share) {
                    $shares[$id] = $share;
                }
                $poolLeft = 0.0;
                break;
            }
            foreach ($fixFloor as $id) {
                $shares[$id] = $floor;
                $poolLeft -= $floor;
            }
            foreach ($fixCap as $id) {
                $shares[$id] = $cap;
                $poolLeft -= $cap;
            }
            $free = array_keys($assign);
            if (!$free) {
                break;
            }
        }
        // Leftover pool (everyone got fixed at a bound): hand it to items
        // with cap headroom, best value first. All-zero metrics with a
        // leftover can also land here — value order is then arbitrary but
        // the split stays inside [floor, cap].
        if ($poolLeft > 0.0001) {
            $byVal = [];
            foreach ($enabled as $it) {
                $byVal[$it['id']] = $vals[$it['id']] ?? 0.0;
            }
            arsort($byVal);
            foreach ($byVal as $id => $v) {
                if ($poolLeft <= 0.0001) {
                    break;
                }
                $cur = $shares[$id] ?? 0.0;
                $room = $cap - $cur;
                if ($room > 0) {
                    $add = min($room, $poolLeft);
                    $shares[$id] = $cur + $add;
                    $poolLeft -= $add;
                }
            }
            // Still leftover (every item at cap): spread equally; sum-100 wins.
            if ($poolLeft > 0.0001) {
                foreach ($enabled as $it) {
                    if (array_key_exists($it['id'], $shares)) {
                        $shares[$it['id']] += $poolLeft / $n;
                    }
                }
            }
        }

        // Assemble the target vector over ALL enabled items.
        $target = [];
        foreach ($enabled as $it) {
            $id = $it['id'];
            $target[$id] = isset($shares[$id]) ? (float) $shares[$id] : $warm;
        }

        // Baseline: current weights normalised to 100 over the enabled items.
        $sumCur = 0;
        foreach ($enabled as $it) {
            $sumCur += max(0, (int) ($it['weight'] ?? 0));
        }
        $old = [];
        foreach ($enabled as $it) {
            $old[$it['id']] = $sumCur > 0 ? max(0, (int) ($it['weight'] ?? 0)) * 100.0 / $sumCur : 100.0 / $n;
        }

        // Dampening: scale the total movement down to the budget.
        $totalMove = 0.0;
        foreach ($enabled as $it) {
            $totalMove += abs($target[$it['id']] - $old[$it['id']]);
        }
        if ($totalMove > $maxMovePP && $totalMove > 0) {
            $k = $maxMovePP / $totalMove;
            foreach ($enabled as $it) {
                $id = $it['id'];
                $target[$id] = $old[$id] + ($target[$id] - $old[$id]) * $k;
            }
        }

        // The floor is absolute — restore it after dampening, taking the
        // excess from the items with the most headroom above the floor.
        for ($guard = 0; $guard < $n + 2; $guard++) {
            $need = 0.0;
            foreach ($enabled as $it) {
                if ($target[$it['id']] < $floor) {
                    $need += $floor - $target[$it['id']];
                }
            }
            if ($need <= 0.0001) {
                break;
            }
            // Donors sorted by headroom, largest first.
            $donors = [];
            foreach ($enabled as $it) {
                $room = $target[$it['id']] - $floor;
                if ($room > 0.0001) {
                    $donors[$it['id']] = $room;
                }
            }
            if (!$donors) {
                // Degenerate: nobody has headroom. Equal split keeps sum-100
                // and is the only fair shape left.
                foreach ($enabled as $it) {
                    $target[$it['id']] = 100.0 / $n;
                }
                break;
            }
            arsort($donors);
            $donorSum = array_sum($donors);
            foreach ($donors as $id => $room) {
                $take = min($room, $need * ($room / $donorSum));
                $target[$id] -= $take;
                $need -= $take;
            }
            foreach ($enabled as $it) {
                if ($target[$it['id']] < $floor) {
                    $target[$it['id']] = $floor;
                }
            }
        }

        // Integerise with largest-remainder so the enabled weights sum to
        // exactly 100, and never let rounding push an item below its floor.
        $floorInt = max(1, (int) ceil($floor));
        foreach ($target as $id => $t) {
            $target[$id] = max($floorInt, (int) floor($t));
        }
        $diff = 100 - array_sum($target);
        if ($diff !== 0) {
            // Pass 1: adjust by ±1 on the items whose float share justifies it.
            $order = $enabled;
            if ($diff > 0) {
                // add to the largest first (they have cap headroom most often)
                usort($order, static fn($a, $b) => $target[$b['id']] <=> $target[$a['id']]);
                $i = 0;
                while ($diff > 0) {
                    $id = $order[$i % $n]['id'];
                    if ($target[$id] < $cap) {
                        $target[$id]++;
                        $diff--;
                    }
                    $i++;
                    if ($i > 4 * $n + 100) {
                        break;
                    }
                }
            } else {
                usort($order, static fn($a, $b) => $target[$a['id']] <=> $target[$b['id']]);
                $i = 0;
                while ($diff < 0) {
                    $id = $order[$i % $n]['id'];
                    if ($target[$id] > $floorInt) {
                        $target[$id]--;
                        $diff++;
                    }
                    $i++;
                    if ($i > 4 * $n + 100) {
                        break;
                    }
                }
            }
        }

        // Pass 2: rounding can land a point or two past the movement budget;
        // walk the excess back toward the old weights one step at a time.
        // Floor/cap invariants win over the budget when they collide.
        $capInt = (int) floor($cap);
        for ($guard = 0; $guard < 4 * $n + 100; $guard++) {
            $move = 0.0;
            foreach ($enabled as $it) {
                $move += abs($target[$it['id']] - $old[$it['id']]);
            }
            if ($move <= $maxMovePP + 0.001) {
                break;
            }
            // The two items furthest from their old weight on each side swap
            // a single point, keeping the sum at 100.
            $up = null;
            $down = null;
            $upGap = 0.0;
            $downGap = 0.0;
            foreach ($enabled as $it) {
                $id = $it['id'];
                $gap = $target[$id] - $old[$id];
                if ($gap > 0 && $target[$id] > $floorInt && $gap > $upGap) {
                    $up = $id;
                    $upGap = $gap;
                }
                if ($gap < 0 && $target[$id] < $capInt && -$gap > $downGap) {
                    $down = $id;
                    $downGap = -$gap;
                }
            }
            if ($up === null || $down === null) {
                break; // invariants block further walk-back
            }
            $target[$up]--;
            $target[$down]++;
        }

        return $target;
    }

    /**
     * Per-item rotation metrics for one stream list, from the same engine the
     * reports use. Returns itemId => ['value' => float, 'sample' => int,
     * 'raw' => computed metric row] where sample is the CONFIRMED SALES count
     * (the "3-4 confirmed sales" warm-up gate) for every metric.
     *
     * Safe-page clicks are excluded with the reports' exact COALESCE
     * convention; bot rows stay in for parity with the report counters.
     */
    function orbitraRotationMetricsByItem(PDO $pdo, int $streamId, string $listType, string $metric, string $windowFrom): array
    {
        require_once __DIR__ . '/ReportMetrics.php';
        $itemCol = $listType === 'landings' ? 'cl.landing_id' : 'cl.offer_id';
        $agg = orbitraConversionAggregateSql('payout');

        $sql = "
            SELECT $itemCol AS item_id,
                   COUNT(cl.id) AS clicks,
                   COALESCE(SUM(CASE WHEN cl.offer_id IS NOT NULL AND cl.offer_id > 0 THEN 1 ELSE 0 END), 0) AS lp_clicks,
                   COALESCE(SUM(cl.cost), 0) AS cost,
                   COALESCE(SUM(cva.cnt_any), 0) AS conversions,
                   COALESCE(SUM(cva.cnt_sale), 0) AS sales,
                   COALESCE(SUM(cva.rev_all), 0) AS revenue,
                   COALESCE(SUM(cva.rev_sale), 0) AS revenue_confirmed
            FROM clicks cl
            LEFT JOIN $agg cva ON cva.click_id = cl.id
            WHERE cl.stream_id = ?
              AND $itemCol IS NOT NULL AND $itemCol > 0
              AND COALESCE(cl.is_safe_page, 0) = 0
              AND cl.created_at >= ?
            GROUP BY $itemCol
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$streamId, $windowFrom]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $computed = orbitraComputeDerivedMetrics($row);
            $valueMap = [
                'sales' => (float) $computed['sales'],
                'cr' => (float) $computed['cr_sales'],
                'epv_confirmed' => (float) $computed['epv_confirmed'],
                'epc_confirmed' => (float) $computed['epc_confirmed'],
                'roi_confirmed' => $computed['roi_confirmed'] === null ? 0.0 : (float) $computed['roi_confirmed'],
            ];
            $out[(int) $row['item_id']] = [
                'value' => $valueMap[$metric] ?? 0.0,
                'sample' => (int) $computed['sales'],
                'raw' => $computed,
            ];
        }
        return $out;
    }

    /**
     * Can ROI be selected for this campaign? True when a manual cost is
     * configured on the campaign or any of its recent clicks carried synced
     * spend (cost sync distributes into clicks.cost).
     */
    function orbitraRotationCostAvailable(PDO $pdo, int $campaignId): bool
    {
        $stmt = $pdo->prepare("SELECT cost_value FROM campaigns WHERE id = ? LIMIT 1");
        $stmt->execute([$campaignId]);
        $costValue = (float) ($stmt->fetchColumn() ?: 0);
        if ($costValue > 0) {
            return true;
        }
        $stmt = $pdo->prepare("
            SELECT 1 FROM clicks
            WHERE campaign_id = ? AND cost > 0 AND created_at >= datetime('now', '-30 days')
            LIMIT 1
        ");
        $stmt->execute([$campaignId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * One cron pass over every stream with an enabled auto list.
     *
     * Returns a per-run summary array for logging. All writes happen inside a
     * per-stream transaction; the stream row is re-read inside it so a
     * campaign save that lands mid-pass cannot have its weights resurrected
     * from a stale copy.
     */
    function orbitraRunRotationOptimiser(PDO $pdo, array $opts = []): array
    {
        require_once __DIR__ . '/ReportMetrics.php';

        $force = !empty($opts['force']);
        $onlyStreamId = isset($opts['stream_id']) ? (int) $opts['stream_id'] : null;
        $now = isset($opts['now']) ? (int) $opts['now'] : time();
        $nowIso = date('Y-m-d H:i:s', $now);

        $summary = ['streams' => 0, 'runs' => 0, 'changed' => 0, 'audit_rows' => 0, 'details' => []];

        $sql = "SELECT s.id, s.campaign_id, s.name, s.schema_custom_json, c.state AS campaign_state, c.is_archived
                FROM streams s JOIN campaigns c ON c.id = s.campaign_id
                WHERE s.is_active = 1
                  AND s.schema_custom_json IS NOT NULL AND s.schema_custom_json != ''
                  AND s.schema_custom_json LIKE '%\"auto\"%'";
        if ($onlyStreamId) {
            $stmt = $pdo->prepare($sql . ' AND s.id = ?');
            $stmt->execute([$onlyStreamId]);
        } else {
            $stmt = $pdo->query($sql);
        }
        $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($streams as $stream) {
            // Paused or archived campaigns don't serve the rotation — leave it.
            if ((int) ($stream['is_archived'] ?? 0) === 1 || ($stream['campaign_state'] ?? 'active') === 'disabled') {
                continue;
            }
            $custom = json_decode((string) $stream['schema_custom_json'], true);
            if (!is_array($custom) || !isset($custom['auto']) || !is_array($custom['auto'])) {
                continue;
            }
            $summary['streams']++;

            foreach (['landings', 'offers'] as $listType) {
                $cfg = orbitraNormalizeRotationAutoConfig($custom['auto'][$listType] ?? null);
                if (!$cfg['enabled']) {
                    continue;
                }

                // Cadence: due only when the interval elapsed since last_run_at.
                if (!$force && !empty($cfg['last_run_at'])) {
                    $last = strtotime((string) $cfg['last_run_at']);
                    if ($last !== false && $now < $last + max(1, (int) $cfg['interval_min']) * 60) {
                        continue;
                    }
                }

                $summary['runs']++;
                $detail = ['stream_id' => (int) $stream['id'], 'list' => $listType];

                // Server-side metric guard: cost metrics are refused outright
                // when the campaign has no cost at all.
                if (orbitraRotationMetricNeedsCost($cfg['metric'])
                    && !orbitraRotationCostAvailable($pdo, (int) $stream['campaign_id'])) {
                    $custom['auto'][$listType]['last_run_at'] = $nowIso;
                    $custom['auto'][$listType]['last_status'] = 'skipped_no_cost';
                    $detail['status'] = 'skipped_no_cost';
                    $summary['details'][] = $detail;
                    orbitraRotationPersistConfig($pdo, (int) $stream['id'], $custom);
                    continue;
                }

                $windowFrom = date('Y-m-d H:i:s', $now - max(1, (int) $cfg['lookback_days']) * 86400);
                $metrics = orbitraRotationMetricsByItem($pdo, (int) $stream['id'], $listType, $cfg['metric'], $windowFrom);

                // Items in the router's own enable/disable vocabulary.
                $items = [];
                foreach (is_array($custom[$listType] ?? null) ? $custom[$listType] : [] as $it) {
                    if (!is_array($it) || empty($it['id'])) {
                        continue;
                    }
                    $state = $it['state'] ?? null;
                    $isActive = $it['is_active'] ?? true;
                    $items[] = [
                        'id' => (int) $it['id'],
                        'weight' => (int) ($it['weight'] ?? 0),
                        'enabled' => !in_array($state, ['disabled', 'paused'], true)
                            && !in_array($isActive, [false, 0, '0'], true),
                    ];
                }

                $allocation = orbitraAllocateRotationWeights($items, $cfg, $metrics);
                if ($allocation === null) {
                    $custom['auto'][$listType]['last_run_at'] = $nowIso;
                    $custom['auto'][$listType]['last_status'] = 'ok_noop';
                    $detail['status'] = 'ok_noop';
                    $summary['details'][] = $detail;
                    orbitraRotationPersistConfig($pdo, (int) $stream['id'], $custom);
                    continue;
                }

                // Anything actually changing? (Dampening can reproduce the
                // current split exactly on steady data.)
                $changed = [];
                foreach ($items as $it) {
                    if ($it['enabled']) {
                        $new = $allocation[$it['id']];
                        if ($new !== (int) $it['weight']) {
                            $changed[$it['id']] = $new;
                        }
                    }
                }
                if (!$changed) {
                    $custom['auto'][$listType]['last_run_at'] = $nowIso;
                    $custom['auto'][$listType]['last_status'] = 'ok';
                    $detail['status'] = 'ok_unchanged';
                    $summary['details'][] = $detail;
                    orbitraRotationPersistConfig($pdo, (int) $stream['id'], $custom);
                    continue;
                }

                $detail['status'] = 'ok_changed';
                $detail['items'] = count($changed);
                $summary['details'][] = $detail;

                // Re-read + write inside a transaction; a concurrent campaign
                // save replaces stream rows wholesale, so verify the config
                // still exists before writing weights into it.
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("SELECT schema_custom_json FROM streams WHERE id = ? LIMIT 1");
                    $stmt->execute([(int) $stream['id']]);
                    $freshJson = $stmt->fetchColumn();
                    if ($freshJson === false) {
                        $pdo->rollBack();
                        continue; // stream vanished mid-run (campaign saved)
                    }
                    $fresh = json_decode((string) $freshJson, true);
                    if (!is_array($fresh)) {
                        $fresh = [];
                    }
                    $freshCfg = orbitraNormalizeRotationAutoConfig($fresh['auto'][$listType] ?? null);
                    if (!$freshCfg['enabled']) {
                        $pdo->rollBack();
                        continue; // auto was switched off while we computed
                    }

                    $names = [];
                    if ($listType === 'landings') {
                        $st = $pdo->prepare('SELECT id, name FROM landings');
                    } else {
                        $st = $pdo->prepare('SELECT id, name FROM offers');
                    }
                    $st->execute();
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $names[(int) $r['id']] = $r['name'];
                    }

                    foreach (is_array($fresh[$listType] ?? null) ? $fresh[$listType] : [] as $i => $it) {
                        $id = (int) ($it['id'] ?? 0);
                        if (isset($changed[$id])) {
                            $fresh[$listType][$i]['weight'] = $changed[$id];
                        }
                    }
                    $fresh['auto'][$listType]['last_run_at'] = $nowIso;
                    $fresh['auto'][$listType]['last_updated_at'] = $nowIso;
                    $fresh['auto'][$listType]['last_status'] = 'ok';

                    $stmtUp = $pdo->prepare('UPDATE streams SET schema_custom_json = ? WHERE id = ?');
                    $stmtUp->execute([json_encode($fresh, JSON_UNESCAPED_UNICODE), (int) $stream['id']]);

                    $stmtLog = $pdo->prepare("
                        INSERT INTO stream_rotation_log
                            (campaign_id, rotation_key, stream_id, stream_name, list_type, item_id, item_name,
                             old_weight, new_weight, metric, metric_value, sample_size, window_from, window_to)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ");
                    $oldWeights = [];
                    foreach ($items as $it) {
                        $oldWeights[$it['id']] = (int) $it['weight'];
                    }
                    foreach ($changed as $id => $new) {
                        $m = $metrics[$id] ?? ['value' => 0, 'sample' => 0];
                        $stmtLog->execute([
                            (int) $stream['campaign_id'],
                            (string) ($freshCfg['key'] !== null && $freshCfg['key'] !== '' ? $freshCfg['key'] : 'stream_' . $stream['id']),
                            (int) $stream['id'],
                            (string) ($stream['name'] ?? ''),
                            $listType,
                            $id,
                            (string) ($names[$id] ?? ('#' . $id)),
                            $oldWeights[$id],
                            $new,
                            $cfg['metric'],
                            (float) $m['value'],
                            (int) $m['sample'],
                            $windowFrom,
                            $nowIso,
                        ]);
                        $summary['audit_rows']++;
                    }

                    $pdo->commit();
                    $summary['changed']++;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $summary['details'][] = [
                        'stream_id' => (int) $stream['id'],
                        'list' => $listType,
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }
        return $summary;
    }

    /** Write the cron bookkeeping fields back without touching weights. */
    function orbitraRotationPersistConfig(PDO $pdo, int $streamId, array $custom): void
    {
        try {
            // Only the timestamps/status fields change here; re-read to avoid
            // clobbering a concurrent editor save with a stale list.
            $stmt = $pdo->prepare("SELECT schema_custom_json FROM streams WHERE id = ? LIMIT 1");
            $stmt->execute([$streamId]);
            $freshJson = $stmt->fetchColumn();
            if ($freshJson === false) {
                return;
            }
            $fresh = json_decode((string) $freshJson, true);
            if (!is_array($fresh)) {
                return;
            }
            if (!isset($fresh['auto']) || !is_array($fresh['auto'])) {
                $fresh['auto'] = [];
            }
            foreach (['landings', 'offers'] as $lt) {
                if (isset($custom['auto'][$lt]['last_run_at'])) {
                    $fresh['auto'][$lt]['last_run_at'] = $custom['auto'][$lt]['last_run_at'];
                    $fresh['auto'][$lt]['last_status'] = $custom['auto'][$lt]['last_status'] ?? null;
                }
            }
            $stmtUp = $pdo->prepare('UPDATE streams SET schema_custom_json = ? WHERE id = ?');
            $stmtUp->execute([json_encode($fresh, JSON_UNESCAPED_UNICODE), $streamId]);
        } catch (Throwable $e) {
            // Bookkeeping only — a failed timestamp write must not fail the run.
        }
    }

    /**
     * Campaign-save merge: while Auto is on, the rotation weights belong to
     * the cron. The editor round-trips a stale copy (loaded when the editor
     * opened), so on save the incoming weights for an auto-managed list are
     * replaced with the weights currently stored in the DB, matched by
     * rotation key + item id. New items the cron has never seen keep the
     * weight the payload sent.
     *
     * The merge also carries the cron's bookkeeping (last_run_at /
     * last_updated_at / last_status) across — campaign save DELETEs and
     * re-INSERTs every stream row, so without this the "last updated"
     * timestamp and the re-evaluation cadence would reset on every save.
     *
     * $oldStreamRows: DB rows (assoc with schema_custom_json string).
     * $payloadStreams: incoming stream arrays (schema_custom already decoded).
     * Returns the payload streams with merged weights.
     */
    function orbitraMergeAutoWeights(array $oldStreamRows, array $payloadStreams): array
    {
        // rotation_key => list => ['weights' => [item id => weight], 'book' => [...]]
        $stored = [];
        foreach ($oldStreamRows as $row) {
            $custom = json_decode((string) ($row['schema_custom_json'] ?? ''), true);
            if (!is_array($custom) || !isset($custom['auto']) || !is_array($custom['auto'])) {
                continue;
            }
            foreach (['landings', 'offers'] as $lt) {
                $cfg = orbitraNormalizeRotationAutoConfig($custom['auto'][$lt] ?? null);
                if (!$cfg['enabled'] || !is_string($cfg['key']) || $cfg['key'] === '') {
                    continue;
                }
                $book = [];
                foreach (['last_run_at', 'last_updated_at', 'last_status'] as $f) {
                    if (isset($cfg[$f])) {
                        $book[$f] = $cfg[$f];
                    }
                }
                $weights = [];
                foreach (is_array($custom[$lt] ?? null) ? $custom[$lt] : [] as $it) {
                    if (is_array($it) && !empty($it['id'])) {
                        $weights[(int) $it['id']] = (int) ($it['weight'] ?? 0);
                    }
                }
                $stored[$cfg['key']][$lt] = ['weights' => $weights, 'book' => $book];
            }
        }

        foreach ($payloadStreams as $si => $stream) {
            $custom = $stream['schema_custom'] ?? null;
            if (!is_array($custom) || !isset($custom['auto']) || !is_array($custom['auto'])) {
                continue;
            }
            foreach (['landings', 'offers'] as $lt) {
                $cfg = orbitraNormalizeRotationAutoConfig($custom['auto'][$lt] ?? null);
                if (!$cfg['enabled'] || !is_string($cfg['key']) || $cfg['key'] === '') {
                    continue;
                }
                $hit = $stored[$cfg['key']][$lt] ?? null;
                if (!$hit) {
                    continue; // first enable — nothing stored to protect
                }
                foreach (is_array($custom[$lt] ?? null) ? $custom[$lt] : [] as $ii => $it) {
                    $id = (int) ($it['id'] ?? 0);
                    if ($id && isset($hit['weights'][$id])) {
                        $custom[$lt][$ii]['weight'] = $hit['weights'][$id];
                    }
                }
                foreach ($hit['book'] as $f => $v) {
                    $custom['auto'][$lt][$f] = $v;
                }
            }
            $payloadStreams[$si]['schema_custom'] = $custom;
        }
        return $payloadStreams;
    }

    /**
     * Fresh rotation keys for a copied campaign: the copy keeps its auto
     * conditions, but its audit history starts clean instead of interleaving
     * with the original's decisions (audit rows are matched by key).
     */
    function orbitraRegenerateRotationKeys(?string $schemaCustomJson): ?string
    {
        if (!is_string($schemaCustomJson) || $schemaCustomJson === '' || strpos($schemaCustomJson, '"auto"') === false) {
            return $schemaCustomJson;
        }
        $custom = json_decode($schemaCustomJson, true);
        if (!is_array($custom) || !isset($custom['auto']) || !is_array($custom['auto'])) {
            return $schemaCustomJson;
        }
        $touched = false;
        foreach (['landings', 'offers'] as $lt) {
            if (isset($custom['auto'][$lt]) && is_array($custom['auto'][$lt])) {
                $custom['auto'][$lt]['key'] = 'rot_' . bin2hex(random_bytes(8));
                $touched = true;
            }
        }
        return $touched ? json_encode($custom, JSON_UNESCAPED_UNICODE) : $schemaCustomJson;
    }

    /**
     * Campaign-save sanitiser for auto configs: refuse cost-dependent metrics
     * when the campaign has no cost, and backstop a rotation key for every
     * enabled list (the editor generates one, older payloads may not).
     */
    function orbitraSanitizeAutoConfigs(PDO $pdo, int $campaignId, array $payloadStreams): array
    {
        $costAvailable = null; // lazy — only probe when a cost metric shows up
        foreach ($payloadStreams as $si => $stream) {
            $custom = $stream['schema_custom'] ?? null;
            if (!is_array($custom) || !isset($custom['auto']) || !is_array($custom['auto'])) {
                continue;
            }
            foreach (['landings', 'offers'] as $lt) {
                $cfg = orbitraNormalizeRotationAutoConfig($custom['auto'][$lt] ?? null);
                if (!$cfg['enabled']) {
                    continue;
                }
                if (orbitraRotationMetricNeedsCost($cfg['metric'])) {
                    if ($costAvailable === null) {
                        $costAvailable = orbitraRotationCostAvailable($pdo, $campaignId);
                    }
                    if (!$costAvailable) {
                        $cfg['metric'] = 'sales';
                    }
                }
                if (!is_string($cfg['key']) || $cfg['key'] === '') {
                    $cfg['key'] = 'rot_' . bin2hex(random_bytes(8));
                }
                // Keep cron bookkeeping from the DB copy; the editor never
                // edits these fields but its payload may carry stale ones.
                foreach (['last_run_at', 'last_updated_at', 'last_status'] as $f) {
                    if (isset($custom['auto'][$lt][$f])) {
                        unset($custom['auto'][$lt][$f]);
                    }
                }
                $custom['auto'][$lt] = array_intersect_key($cfg, array_flip([
                    'enabled', 'key', 'metric', 'min_sample', 'lookback_days',
                    'floor_pct', 'cap_pct', 'interval_min',
                ])) + $custom['auto'][$lt];
            }
            $payloadStreams[$si]['schema_custom'] = $custom;
        }
        return $payloadStreams;
    }
}
