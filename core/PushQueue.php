<?php
/**
 * PushQueue — enqueue helpers shared by the panel (push_send_now) and the
 * delivery worker (cli/push_cron.php): the segment filter is computed here,
 * once, as a single SQL join (phase-4 MVP).
 *
 * Segments ride on the same conversion groups the reports use
 * (orbitraConversionAggregateSql → orbitraConversionStatusGroups):
 *   reg1 = cnt_registration + cnt_hold > 0   (a lead/hold counts as a reg)
 *   dep1 = cnt_deposit    + cnt_sale   > 0   (a sale counts as a deposit)
 *   all      — every active subscription;
 *   reg0     — not reg1;
 *   reg1dep0 — reg1 and not dep1;
 *   reg1dep1 — reg1 and dep1.
 *
 * Dedup is structural: a (message, subscription) pair can sit in push_queue
 * only once, whatever its status — NOT EXISTS at insert time plus the
 * single-writer cron (flock) keeps it true.
 */

require_once __DIR__ . '/ReportMetrics.php';

function orbitraPushSegments(): array
{
    return ['all', 'reg0', 'reg1dep0', 'reg1dep1'];
}

/**
 * FROM/JOIN clause resolving the conversion counters for a subscription.
 * cva is the per-click conversion aggregate aliased onto ps.click_id.
 */
function orbitraPushSegmentJoinSql(): string
{
    $agg = orbitraConversionAggregateSql(null);
    return "FROM push_subscriptions ps
            LEFT JOIN clicks c ON c.id = ps.click_id
            LEFT JOIN $agg cva ON cva.click_id = ps.click_id";
}

/**
 * WHERE fragment implementing one segment over the cva counters.
 * COALESCE everywhere — a subscription without a click row (or without
 * conversions) must land in reg0, never in SQL NULL limbo.
 */
function orbitraPushSegmentConditionSql(string $segment): string
{
    $reg = '(COALESCE(cva.cnt_registration, 0) + COALESCE(cva.cnt_hold, 0)) > 0';
    $dep = '(COALESCE(cva.cnt_deposit, 0) + COALESCE(cva.cnt_sale, 0)) > 0';
    switch ($segment) {
        case 'reg0':
            return "NOT $reg";
        case 'reg1dep0':
            return "$reg AND NOT $dep";
        case 'reg1dep1':
            return "$reg AND $dep";
        case 'all':
        default:
            return '1';
    }
}

/**
 * Queue one message for every ACTIVE subscription matching its segment.
 *
 * @param array      $message push_messages row (id, segment, delay_seconds…)
 * @param string|null $segmentOverride panel "send now" may target another
 *                                     segment than the stored one
 * @return int rows enqueued by this call
 */
function orbitraPushEnqueueMessage(PDO $pdo, array $message, ?string $segmentOverride = null): int
{
    $messageId = (int) ($message['id'] ?? 0);
    if ($messageId <= 0) {
        return 0;
    }
    $segment = $segmentOverride ?? (string) ($message['segment'] ?? 'all');
    if (!in_array($segment, orbitraPushSegments(), true)) {
        $segment = 'all';
    }
    $delay = max(0, (int) ($message['delay_seconds'] ?? 0));

    $sql = "INSERT INTO push_queue (message_id, subscription_id, run_at, status)
            SELECT ?, ps.id, datetime('now', ?), 'pending'
            " . orbitraPushSegmentJoinSql() . "
            WHERE ps.is_active = 1
              AND " . orbitraPushSegmentConditionSql($segment) . "
              AND NOT EXISTS (
                  SELECT 1 FROM push_queue q
                  WHERE q.message_id = ? AND q.subscription_id = ps.id
              )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$messageId, '+' . $delay . ' seconds', $messageId]);
    return $stmt->rowCount();
}

/**
 * Queue one message for an explicit subscription set (the event trigger path:
 * the ids were already selected from the new clicks/conversions rows), still
 * filtered by the message's segment and the dedup rule. run_at honors the
 * message's delay_seconds.
 */
function orbitraPushEnqueueForSubscriptions(PDO $pdo, array $message, array $subscriptionIds): int
{
    $messageId = (int) ($message['id'] ?? 0);
    $ids = array_values(array_unique(array_filter(array_map('intval', $subscriptionIds))));
    if ($messageId <= 0 || !$ids || (int) ($message['active'] ?? 0) !== 1) {
        return 0;
    }
    $segment = (string) ($message['segment'] ?? 'all');
    if (!in_array($segment, orbitraPushSegments(), true)) {
        $segment = 'all';
    }
    $delay = max(0, (int) ($message['delay_seconds'] ?? 0));

    $in = implode(',', $ids);
    $sql = "INSERT INTO push_queue (message_id, subscription_id, run_at, status)
            SELECT ?, ps.id, datetime('now', ?), 'pending'
            " . orbitraPushSegmentJoinSql() . "
            WHERE ps.id IN ($in)
              AND ps.is_active = 1
              AND " . orbitraPushSegmentConditionSql($segment) . "
              AND NOT EXISTS (
                  SELECT 1 FROM push_queue q
                  WHERE q.message_id = ? AND q.subscription_id = ps.id
              )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$messageId, '+' . $delay . ' seconds', $messageId]);
    return $stmt->rowCount();
}

/** Active push_messages rows wired to one event kind ('install'|'lead'|'sale'). */
function orbitraPushEventMessages(PDO $pdo, string $event): array
{
    $stmt = $pdo->prepare("SELECT * FROM push_messages WHERE active = 1 AND kind = 'event' AND event = ?");
    $stmt->execute([$event]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
