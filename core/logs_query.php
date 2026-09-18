<?php

/**
 * Logs page queries (Logs → Traffic / Postbacks / S2S / System / Audit).
 *
 * One builder serves both the paged JSON endpoint (`action=logs`) and the CSV
 * export (`action=logs_export`), so the two can never disagree about which
 * rows a filter selects.
 *
 * Paging is keyset, not OFFSET: the traffic log grows while the operator is
 * reading it, and an OFFSET page 2 fetched after ten new clicks arrived would
 * repeat ten rows from page 1. The cursor is the last row's raw (UTC)
 * created_at plus its id — `id` breaks ties between rows written in the same
 * second. OFFSET is still accepted for older callers (dashboard, MCP).
 */

const ORBITRA_LOGS_TYPES = ['traffic', 'postbacks', 's2s', 'system', 'audit'];
const ORBITRA_LOGS_PAGE_MAX = 500;
const ORBITRA_LOGS_EXPORT_MAX = 100000;

function orbitraLogsClampLimit($raw, int $default = 50): int
{
    $n = is_numeric($raw) ? (int) $raw : $default;
    return max(1, min(ORBITRA_LOGS_PAGE_MAX, $n));
}

/** "2026-09-18 10:00:00|abc" → ['ts' => ..., 'id' => ...]; null when malformed. */
function orbitraLogsParseCursor(?string $cursor): ?array
{
    if ($cursor === null || $cursor === '') {
        return null;
    }
    $parts = explode('|', $cursor, 2);
    if (count($parts) !== 2 || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $parts[0])) {
        return null;
    }
    return ['ts' => $parts[0], 'id' => $parts[1]];
}

function orbitraLogsMakeCursor(array $row): ?string
{
    if (!isset($row['_cur_ts'], $row['_cur_id'])) {
        return null;
    }
    return $row['_cur_ts'] . '|' . $row['_cur_id'];
}

/** Drop the internal cursor columns before a row leaves the server. */
function orbitraLogsPublicRow(array $row): array
{
    unset($row['_cur_ts'], $row['_cur_id']);
    return $row;
}

/**
 * Panel-local Y-m-d → UTC 'Y-m-d H:i:s' bound. created_at is stored in UTC, so
 * the bound is shifted instead of the column: that keeps the created_at index
 * usable on a large clicks table.
 */
function orbitraLogsUtcBound(?string $date, int $tzOffsetSeconds, bool $endOfDay): ?string
{
    if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    $ts = strtotime($date . ($endOfDay ? ' 23:59:59' : ' 00:00:00') . ' UTC');
    if ($ts === false) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', $ts - $tzOffsetSeconds);
}

/**
 * Build the SELECT for one log type.
 *
 * $f keys (all optional): campaign_id, stream_id, route (all|money|safe),
 * reason, date_from, date_to (panel-local Y-m-d), cursor (see above).
 *
 * @return array{0:string,1:array} SQL without LIMIT/OFFSET, and its params
 */
function orbitraLogsBuildQuery(string $type, array $f, string $dbTzOffset, int $tzOffsetSeconds): array
{
    if (!in_array($type, ORBITRA_LOGS_TYPES, true)) {
        throw new InvalidArgumentException('Unknown log type: ' . $type);
    }
    if (!preg_match('/^[+-]\d{2}:\d{2}$/', $dbTzOffset)) {
        $dbTzOffset = '+00:00';
    }

    $alias = ['traffic' => 'cl', 'postbacks' => 'pb', 's2s' => 's2', 'system' => 'sl', 'audit' => 'al'][$type];
    $where = [];
    $params = [];

    if ($type === 'traffic' || $type === 'postbacks') {
        $campaignId = isset($f['campaign_id']) ? (int) $f['campaign_id'] : 0;
        if ($campaignId > 0) {
            $where[] = "$alias.campaign_id = ?";
            $params[] = $campaignId;
        }
    }

    if ($type === 'traffic') {
        $streamId = isset($f['stream_id']) ? (int) $f['stream_id'] : 0;
        if ($streamId > 0) {
            $where[] = 'cl.stream_id = ?';
            $params[] = $streamId;
        }
        $route = $f['route'] ?? 'all';
        if ($route === 'money') {
            // NULL is_safe_page = money-side traffic (pre-v38 rows);
            // a plain "= 0" would hide them from the money filter.
            $where[] = 'COALESCE(cl.is_safe_page, 0) = 0';
        } elseif ($route === 'safe') {
            $where[] = 'cl.is_safe_page = 1';
        }
        $reason = (string) ($f['reason'] ?? '');
        if ($reason !== '') {
            $where[] = 'cl.cloak_reasons LIKE ?';
            $params[] = '%' . $reason . '%';
        }
    }

    $from = orbitraLogsUtcBound($f['date_from'] ?? null, $tzOffsetSeconds, false);
    if ($from !== null) {
        $where[] = "$alias.created_at >= ?";
        $params[] = $from;
    }
    $to = orbitraLogsUtcBound($f['date_to'] ?? null, $tzOffsetSeconds, true);
    if ($to !== null) {
        $where[] = "$alias.created_at <= ?";
        $params[] = $to;
    }

    $cursor = orbitraLogsParseCursor($f['cursor'] ?? null);
    if ($cursor !== null) {
        $where[] = "($alias.created_at < ? OR ($alias.created_at = ? AND $alias.id < ?))";
        $cid = $type === 'traffic' ? (string) $cursor['id'] : (int) $cursor['id'];
        array_push($params, $cursor['ts'], $cursor['ts'], $cid);
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $order = "ORDER BY $alias.created_at DESC, $alias.id DESC";
    $cur = "$alias.created_at AS _cur_ts, $alias.id AS _cur_id";

    switch ($type) {
        case 'traffic':
            $sql = "
                SELECT
                    cl.id,
                    cl.id as click_id,
                    datetime(cl.created_at, '$dbTzOffset') as created_at,
                    c.name as campaign_name,
                    cl.ip,
                    COALESCE(NULLIF(cl.country_code, ''), cl.country) as country_code,
                    cl.region,
                    cl.city,
                    cl.timezone as geo_timezone,
                    cl.language,
                    cl.accept_language_raw,
                    cl.device_type,
                    cl.user_agent,
                    -- Time on the landing (the /pixel.gif?action=lp beacon):
                    -- present whether or not the visitor went on to the offer,
                    -- which is what makes a bounce visible in the log at all.
                    cl.lp_seconds,
                    cl.lp_scroll,
                    o.url as redirect_url,
                    CASE WHEN json_valid(cl.parameters_json)
                         THEN COALESCE(json_extract(cl.parameters_json, '$.sub_id_1'), '')
                         ELSE '' END as subid,
                    -- W2: Cloak observability columns
                    cl.cloak_verdict,
                    cl.cloak_reasons,
                    cl.is_safe_page,
                    cl.isp,
                    cl.asn,
                    cl.proxy_type,
                    cl.cloak_sensitivity,
                    l.name AS landing_name,
                    o.name AS offer_name,
                    -- Which stream the click fell into: answers how much traffic went
                    -- to the intercepting / regular / fallback stream, per row.
                    cl.stream_id,
                    s.name AS stream_name,
                    s.type AS stream_type,
                    $cur
                FROM clicks cl
                LEFT JOIN campaigns c ON cl.campaign_id = c.id
                LEFT JOIN offers o ON cl.offer_id = o.id
                LEFT JOIN landings l ON cl.landing_id = l.id
                LEFT JOIN streams s ON cl.stream_id = s.id
                $whereSql
                $order";
            break;
        case 'postbacks':
            // Campaign name via JOIN — the old per-row lookup was one query per row.
            $sql = "
                SELECT
                    pb.id,
                    pb.click_id,
                    pb.status,
                    pb.original_status,
                    pb.payout,
                    pb.currency,
                    datetime(pb.created_at, '$dbTzOffset') as created_at,
                    pb.campaign_id,
                    c.name AS campaign_name,
                    pb.result,
                    pb.error,
                    pb.remote_ip,
                    pb.source,
                    pb.matched,
                    $cur
                FROM incoming_postbacks_log pb
                LEFT JOIN campaigns c ON pb.campaign_id = c.id
                $whereSql
                $order";
            break;
        case 's2s':
            // next_retry_at lives in the DB as UTC; shift it the same way as
            // created_at so "next attempt" is not displayed 3 hours behind on
            // a panel whose timezone is ahead of UTC.
            $sql = "SELECT s2.*, datetime(s2.created_at, '$dbTzOffset') as created_at, datetime(s2.next_retry_at, '$dbTzOffset') as next_retry_at, $cur
                    FROM s2s_postbacks_log s2 $whereSql $order";
            break;
        case 'system':
            $sql = "SELECT sl.*, datetime(sl.created_at, '$dbTzOffset') as created_at, $cur FROM system_logs sl $whereSql $order";
            break;
        default: // audit
            $sql = "SELECT al.*, datetime(al.created_at, '$dbTzOffset') as created_at, $cur FROM audit_logs al $whereSql $order";
    }

    return [$sql, $params];
}

/**
 * One page. Fetches limit+1 rows so has_more costs nothing (no COUNT(*) over
 * clicks, which is slow on a big install).
 *
 * @return array{rows:array,has_more:bool,next_cursor:?string}
 */
function orbitraLogsFetchPage(PDO $pdo, string $type, array $f, int $limit, int $offset, string $dbTzOffset, int $tzOffsetSeconds): array
{
    [$sql, $params] = orbitraLogsBuildQuery($type, $f, $dbTzOffset, $tzOffsetSeconds);
    $limit = max(1, min(ORBITRA_LOGS_PAGE_MAX, $limit));
    $offset = max(0, $offset);
    if (!empty($f['cursor'])) {
        $offset = 0; // cursor and offset are alternatives, never combined
    }
    $stmt = $pdo->prepare($sql . ' LIMIT ? OFFSET ?');
    $i = 1;
    foreach ($params as $p) {
        $stmt->bindValue($i++, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue($i++, $limit + 1, PDO::PARAM_INT);
    $stmt->bindValue($i, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        $rows = array_slice($rows, 0, $limit);
    }
    $next = ($hasMore && $rows) ? orbitraLogsMakeCursor($rows[count($rows) - 1]) : null;

    return [
        'rows' => array_map('orbitraLogsPublicRow', $rows),
        'has_more' => $hasMore,
        'next_cursor' => $next,
    ];
}

/**
 * Walk every matching row in batches (keyset), newest first, up to $max.
 * Memory stays flat however large the export is.
 */
function orbitraLogsIterate(PDO $pdo, string $type, array $f, string $dbTzOffset, int $tzOffsetSeconds, int $max = ORBITRA_LOGS_EXPORT_MAX, int $batch = 1000): Generator
{
    $f['cursor'] = null;
    $sent = 0;
    while ($sent < $max) {
        $page = orbitraLogsFetchPage($pdo, $type, $f, min($batch, ORBITRA_LOGS_PAGE_MAX), 0, $dbTzOffset, $tzOffsetSeconds);
        foreach ($page['rows'] as $row) {
            yield $row;
            if (++$sent >= $max) {
                return;
            }
        }
        if (!$page['has_more'] || $page['next_cursor'] === null) {
            return;
        }
        $f['cursor'] = $page['next_cursor'];
    }
}

/** Write rows as ;-separated UTF-8 CSV with a BOM (opens correctly in Excel). */
function orbitraLogsWriteCsv($out, iterable $rows): int
{
    fwrite($out, "\xEF\xBB\xBF");
    $n = 0;
    $header = null;
    foreach ($rows as $row) {
        if ($header === null) {
            $header = array_keys($row);
            fputcsv($out, $header, ';', '"', '\\');
        }
        $line = [];
        foreach ($header as $k) {
            $v = $row[$k] ?? '';
            // Neutralise spreadsheet formula injection from visitor-controlled
            // fields (user agent, referer, sub ids).
            if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v)) {
                $v = "'" . $v;
            }
            $line[] = $v;
        }
        fputcsv($out, $line, ';', '"', '\\');
        $n++;
    }
    return $n;
}
