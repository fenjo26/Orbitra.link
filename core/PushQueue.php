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
 * Parse a comma/space/semicolon list of 2-letter codes into a clean unique
 * array ("US, de;  ru" → ['us','de','ru']). Anything longer or shorter is
 * dropped — a stored filter must never become a LIKE injection surface.
 */
function orbitraPushCodeList(string $raw): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $code) {
        $code = strtolower(trim($code));
        if (preg_match('/^[a-z]{2}$/', $code) === 1) {
            $out[$code] = true;
        }
    }
    return array_keys($out);
}

/**
 * WHERE fragment for the message's geo/language targeting, appended to the
 * enqueue SELECTs. Empty lists → no constraint (every subscriber). Country
 * compares uppercased; language matches on the primary subtag — the stored
 * value is navigator.language ('en', 'en-US', 'pt-BR'), the operator filters
 * by a 2-letter code.
 *
 * @param array $message push_messages row (countries/languages, csv)
 * @param array $params  prepared-statement params, appended IN SQL ORDER —
 *                      callers must place this fragment before the NOT EXISTS
 *                      clause whose placeholder comes last.
 */
function orbitraPushTargetingSql(array $message, array &$params): string
{
    $parts = [];
    $countries = orbitraPushCodeList((string) ($message['countries'] ?? ''));
    if ($countries !== []) {
        $parts[] = 'UPPER(COALESCE(ps.country_code, \'\')) IN ('
            . implode(',', array_fill(0, count($countries), '?')) . ')';
        foreach ($countries as $country) {
            $params[] = strtoupper($country);
        }
    }
    $languages = orbitraPushCodeList((string) ($message['languages'] ?? ''));
    if ($languages !== []) {
        $parts[] = '(' . implode(' OR ', array_fill(0, count($languages), "LOWER(COALESCE(ps.language, '')) LIKE ?")) . ')';
        foreach ($languages as $language) {
            $params[] = $language . '%';
        }
    }
    return $parts === [] ? '' : '              AND ' . implode("\n              AND ", $parts) . "\n";
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
    $params = [$messageId, '+' . $delay . ' seconds'];

    $sql = "INSERT INTO push_queue (message_id, subscription_id, run_at, status)
            SELECT ?, ps.id, datetime('now', ?), 'pending'
            " . orbitraPushSegmentJoinSql() . "
            WHERE ps.is_active = 1
              AND " . orbitraPushSegmentConditionSql($segment) . "
" . orbitraPushTargetingSql($message, $params) . "              AND NOT EXISTS (
                  SELECT 1 FROM push_queue q
                  WHERE q.message_id = ? AND q.subscription_id = ps.id
              )";
    $params[] = $messageId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
    $params = [$messageId, '+' . $delay . ' seconds'];

    $in = implode(',', $ids);
    $sql = "INSERT INTO push_queue (message_id, subscription_id, run_at, status)
            SELECT ?, ps.id, datetime('now', ?), 'pending'
            " . orbitraPushSegmentJoinSql() . "
            WHERE ps.id IN ($in)
              AND ps.is_active = 1
              AND " . orbitraPushSegmentConditionSql($segment) . "
" . orbitraPushTargetingSql($message, $params) . "              AND NOT EXISTS (
                  SELECT 1 FROM push_queue q
                  WHERE q.message_id = ? AND q.subscription_id = ps.id
              )";
    $params[] = $messageId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/** Active push_messages rows wired to one event kind ('install'|'lead'|'sale'). */
function orbitraPushEventMessages(PDO $pdo, string $event): array
{
    $stmt = $pdo->prepare("SELECT * FROM push_messages WHERE active = 1 AND kind = 'event' AND event = ?");
    $stmt->execute([$event]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The subscriber's IANA zone: the stored one wins; older rows (pre-stage-2.5)
 * get a zone derived from their geo country; UTC is the honest last resort.
 */
function orbitraPushZoneFor(string $stored, string $countryCode): string
{
    $stored = trim($stored);
    if ($stored !== '') {
        try {
            new DateTimeZone($stored);
            return $stored;
        } catch (\Throwable $e) {
        }
    }
    $map = [
        'gr' => 'Europe/Athens', 'cy' => 'Asia/Nicosia', 'de' => 'Europe/Berlin',
        'pl' => 'Europe/Warsaw', 'ro' => 'Europe/Bucharest', 'cz' => 'Europe/Prague',
        'ua' => 'Europe/Kyiv', 'tr' => 'Europe/Istanbul', 'fr' => 'Europe/Paris',
        'es' => 'Europe/Madrid', 'pt' => 'Europe/Lisbon', 'it' => 'Europe/Rome',
        'gb' => 'Europe/London', 'br' => 'America/Sao_Paulo', 'mx' => 'America/Mexico_City',
        'ar' => 'America/Argentina/Buenos_Aires', 'cl' => 'America/Santiago',
        'co' => 'America/Bogota', 'pe' => 'America/Lima', 'in' => 'Asia/Kolkata',
        'id' => 'Asia/Jakarta', 'th' => 'Asia/Bangkok', 'vn' => 'Asia/Ho_Chi_Minh',
        'ph' => 'Asia/Manila', 'my' => 'Asia/Kuala_Lumpur', 'ng' => 'Africa/Lagos',
        'za' => 'Africa/Johannesburg', 'ke' => 'Africa/Nairobi', 'eg' => 'Africa/Cairo',
        'sa' => 'Asia/Riyadh', 'ae' => 'Asia/Dubai', 'ca' => 'America/Toronto',
        'us' => 'America/New_York', 'au' => 'Australia/Sydney', 'nz' => 'Pacific/Auckland',
    ];
    return $map[strtolower(substr($countryCode, 0, 2))] ?? 'UTC';
}

/**
 * Quiet hours window (settings push_quiet_hours, "23-08" by default) as
 * [fromHour, toHour) — the to-hour is exclusive, so 23-08 silences 23:00..07:59.
 */
function orbitraPushQuietHours(PDO $pdo): array
{
    $raw = '23-08';
    try {
        $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'push_quiet_hours' LIMIT 1");
        $stored = (string) $stmt->fetchColumn();
        if (preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $stored)) {
            $raw = $stored;
        }
    } catch (\Throwable $e) {
    }
    preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $raw, $m) || $m = [0, 23, 8];
    return [min(23, max(0, (int) $m[1])), min(24, max(0, (int) $m[2]))];
}

/**
 * The subscriber's local hour right now, plus the UTC timestamp of the next
 * whole local minute outside... used as the requeue run_at. The queue's run_at
 * column is UTC everywhere.
 *
 * @return array{0:int,1:string} [localHour, 'Y-m-d H:i:s' UTC of next wake]
 */
function orbitraPushLocalHour(string $zone): array
{
    try {
        $tz = new DateTimeZone($zone);
    } catch (\Throwable $e) {
        $tz = new DateTimeZone('UTC');
    }
    $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
    $local = clone $nowUtc;
    $local->setTimezone($tz);

    // Next whole local hour is overkill for quiet-hours precision; the next
    // whole local MINUTE is cheap and wakes the row exactly when the window
    // could open. Round up to the next :00 seconds of the local clock.
    $nextLocal = clone $local;
    $nextLocal->modify('+60 seconds');
    $nextLocal->setTime((int) $nextLocal->format('G'), (int) $nextLocal->format('i'), 0);
    $nextUtc = clone $nextLocal;
    $nextUtc->setTimezone(new DateTimeZone('UTC'));

    return [(int) $local->format('G'), $nextUtc->format('Y-m-d H:i:s')];
}
