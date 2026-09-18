<?php

/**
 * Per-stream traffic counters for the campaign editor's Streams tab — the
 * "where does the traffic inside this campaign go" answer, next to each
 * stream, Keitaro-style (hits / unique / bots for a period).
 *
 * Counts every hit, safe-page ones included: the question is routing (how
 * much fell into the white vs the money stream), not the money-side funnel
 * that the reports' safe-page exclusion is for.
 */

require_once __DIR__ . '/logs_query.php';

const ORBITRA_STREAM_STATS_PERIODS = ['today', 'yesterday', '7d', '30d'];

/**
 * Panel-local period → [utcFrom, utcTo]. $now is injectable for tests.
 *
 * @return array{0:string,1:string}
 */
function orbitraStreamStatsRange(string $period, int $tzOffsetSeconds, ?int $now = null): array
{
    $now = $now ?? time();
    $localToday = gmdate('Y-m-d', $now + $tzOffsetSeconds);
    $daysBack = ['today' => 0, 'yesterday' => 1, '7d' => 6, '30d' => 29][$period] ?? 0;
    $fromDay = gmdate('Y-m-d', strtotime($localToday . ' UTC') - $daysBack * 86400);
    $toDay = $period === 'yesterday' ? $fromDay : $localToday;

    return [
        orbitraLogsUtcBound($fromDay, $tzOffsetSeconds, false),
        orbitraLogsUtcBound($toDay, $tzOffsetSeconds, true),
    ];
}

/**
 * @return array{period:string,total:int,streams:array<int,array>,other:?array}
 *   streams: stream_id => [visitors, unique, bots, conversions, share]
 *   other:   hits whose stream no longer exists (deleted, or recorded before
 *            stream IDs became stable on save) — shown so totals add up.
 */
function orbitraStreamStats(PDO $pdo, int $campaignId, string $period, int $tzOffsetSeconds, ?int $now = null): array
{
    if (!in_array($period, ORBITRA_STREAM_STATS_PERIODS, true)) {
        $period = 'today';
    }
    [$from, $to] = orbitraStreamStatsRange($period, $tzOffsetSeconds, $now);

    $stmt = $pdo->prepare("
        SELECT cl.stream_id,
               COUNT(*) AS visitors,
               COUNT(DISTINCT cl.ip) AS uniq,
               COALESCE(SUM(cl.is_bot), 0) AS bots,
               COALESCE(SUM(cl.is_conversion), 0) AS conversions
        FROM clicks cl
        WHERE cl.campaign_id = ? AND cl.created_at >= ? AND cl.created_at <= ?
        GROUP BY cl.stream_id
    ");
    $stmt->execute([$campaignId, $from, $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $live = $pdo->prepare("SELECT id FROM streams WHERE campaign_id = ?");
    $live->execute([$campaignId]);
    $liveIds = array_flip(array_map('intval', $live->fetchAll(PDO::FETCH_COLUMN)));

    $total = 0;
    foreach ($rows as $r) {
        $total += (int) $r['visitors'];
    }
    $share = static fn(int $n) => $total > 0 ? round($n * 100 / $total, 1) : 0.0;

    $streams = [];
    $other = null;
    foreach ($rows as $r) {
        $sid = (int) ($r['stream_id'] ?? 0);
        $entry = [
            'visitors' => (int) $r['visitors'],
            'unique' => (int) $r['uniq'],
            'bots' => (int) $r['bots'],
            'conversions' => (int) $r['conversions'],
        ];
        if ($sid > 0 && isset($liveIds[$sid])) {
            $entry['share'] = $share($entry['visitors']);
            $streams[$sid] = $entry;
        } else {
            // Unique IPs cannot be summed across groups; the other bucket's
            // unique is an upper bound, which is fine for a "leftovers" row.
            $other = $other ?? ['visitors' => 0, 'unique' => 0, 'bots' => 0, 'conversions' => 0];
            foreach (['visitors', 'unique', 'bots', 'conversions'] as $k) {
                $other[$k] += $entry[$k];
            }
        }
    }
    if ($other !== null) {
        $other['share'] = $share($other['visitors']);
    }

    return ['period' => $period, 'from' => $from, 'to' => $to, 'total' => $total, 'streams' => $streams, 'other' => $other];
}

/**
 * Save a campaign's streams keeping their IDs.
 *
 * The editor used to DELETE every stream and INSERT them again, so each save
 * minted new IDs: clicks already recorded kept pointing at the old ones, the
 * report's "Stream" grouping splintered into bare numbers (27, 28, 30 …) with
 * no name, per-stream history reset, and catch_404_stream_id pointed at a
 * stream that no longer existed. Now a payload stream carrying the id of one
 * of this campaign's streams is updated in place; anything else is inserted;
 * streams missing from the payload are deleted.
 *
 * @param callable $rowValues fn(array $stream): array — the 13 column values
 *        after campaign_id, in ORBITRA_STREAM_SAVE_COLUMNS order
 * @return array<int,int> payload index => stream id
 */
const ORBITRA_STREAM_SAVE_COLUMNS = [
    'offer_id', 'weight', 'is_active', 'type', 'position', 'filters_json', 'filters_logic',
    'schema_type', 'action_payload', 'schema_custom_json', 'offer_selection', 'name', 'collect_clicks',
];

function orbitraSaveCampaignStreams(PDO $pdo, int $campaignId, array $streams, callable $rowValues): array
{
    $existing = $pdo->prepare("SELECT id FROM streams WHERE campaign_id = ?");
    $existing->execute([$campaignId]);
    $existingIds = array_flip(array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN)));

    $cols = ORBITRA_STREAM_SAVE_COLUMNS;
    $insert = $pdo->prepare(
        'INSERT INTO streams (campaign_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')'
    );
    $update = $pdo->prepare(
        'UPDATE streams SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ? AND campaign_id = ?'
    );

    $kept = [];
    $map = [];
    foreach (array_values($streams) as $i => $str) {
        $values = array_values($rowValues($str));
        $sid = (int) ($str['id'] ?? 0);
        // A duplicated stream in the editor carries its source's id: the
        // first occurrence keeps it, the copy becomes a new stream.
        if ($sid > 0 && isset($existingIds[$sid]) && !isset($kept[$sid])) {
            $update->execute(array_merge($values, [$sid, $campaignId]));
        } else {
            $insert->execute(array_merge([$campaignId], $values));
            $sid = (int) $pdo->lastInsertId();
        }
        $kept[$sid] = true;
        $map[$i] = $sid;
    }

    $keepIds = array_keys($kept);
    if ($keepIds) {
        $ph = implode(',', array_fill(0, count($keepIds), '?'));
        $pdo->prepare("DELETE FROM streams WHERE campaign_id = ? AND id NOT IN ($ph)")
            ->execute(array_merge([$campaignId], $keepIds));
    } else {
        $pdo->prepare("DELETE FROM streams WHERE campaign_id = ?")->execute([$campaignId]);
    }

    return $map;
}
