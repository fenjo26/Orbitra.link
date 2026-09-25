<?php
/**
 * Stage 3 of docs/TZ_LOAD_PERFORMANCE.md: report date filters as UTC ranges.
 *
 * The report surfaces filtered clicks with date(created_at, tz) >= date(…).
 * A function over the column defeats the (campaign_id, created_at) index, so
 * every request scanned the whole campaign history. The replacement compares
 * clicks.created_at (always 'YYYY-MM-DD HH:MM:SS' UTC) against UTC string
 * bounds derived from the same local day.
 *
 * "Same numbers" is the acceptance bar, so the reference here is the v1.6.2
 * SQL itself: the old expressions are hardcoded below and executed against
 * the same fixture as the new helpers. For every dashboard preset, custom
 * range and the campaigns-list join both versions must return the exact same
 * row sets (GROUP_CONCAT of ids, not just counts). The anchor day the presets
 * used is compared directly against SQLite's own date('now', …) on the same
 * clock, and EXPLAIN QUERY PLAN must show the index seek.
 *
 * Usage: php tests/report_date_range_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

require_once __DIR__ . '/../core/ReportMetrics.php';

$dbPath = sys_get_temp_dir() . '/orbitra_report_range_' . getmypid() . '.sqlite';
foreach ([$dbPath, "$dbPath-wal", "$dbPath-shm"] as $f) {
    @unlink($f);
}
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->exec("
    CREATE TABLE clicks (
        id TEXT PRIMARY KEY,
        campaign_id INTEGER NOT NULL,
        ip TEXT NOT NULL,
        user_agent TEXT,
        is_safe_page INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");
$pdo->exec("CREATE INDEX idx_clicks_campaign_created ON clicks(campaign_id, created_at)");
$pdo->exec("CREATE TABLE campaigns (id INTEGER PRIMARY KEY, name TEXT, is_archived INTEGER DEFAULT 0)");
$pdo->exec("INSERT INTO campaigns (id, name) VALUES (1, 'One'), (2, 'Empty')");

/**
 * Insert a click whose created_at is the UTC rendering of a report-local
 * moment under $tz — exactly how a click made at that local moment is stored.
 */
$insertLocal = static function (string $id, int $campaignId, string $localMoment, string $tz) use ($pdo): void {
    $utc = gmdate('Y-m-d H:i:s', strtotime($localMoment . ' UTC') - orbitraTzOffsetSeconds($tz));
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, is_safe_page, created_at) VALUES (?, ?, '203.0.113.1', 'RangeTest/1.0', ?, ?)")
        ->execute([$id, $campaignId, (int) (str_ends_with($id, 'safe')), $utc]);
};

$presets = [
    // [operator, inner date expression] — the TRUE v1.6.2 semantics:
    // today/yesterday were EQUALITIES, the other four were lower bounds.
    'today'        => ['=', "date('now', '%s')"],
    'yesterday'    => ['=', "date('now', '-1 day', '%s')"],
    'this_week'    => ['>=', "date('now', 'weekday 1', '-7 days', '%s')"],
    'last_7_days'  => ['>=', "date('now', '-7 days', '%s')"],
    'this_month'   => ['>=', "date('now', 'start of month', '%s')"],
    'last_30_days' => ['>=', "date('now', '-30 days', '%s')"],
];

foreach (['+00:00', '+03:00', '-05:00'] as $tz) {
    $tag = str_replace(['+', ':'], '', $tz);
    // Clicks around every boundary the presets can cut at: local midnights of
    // today/yesterday/tomorrow, week and month edges, the 7d/30d anchors and
    // deep history. Both sides of every boundary must classify identically.
    $todayLocal = (string) $pdo->query("SELECT date('now', '$tz')")->fetchColumn();
    $yesterdayLocal = gmdate('Y-m-d', strtotime($todayLocal . ' UTC') - 86400);
    $tomorrowLocal = gmdate('Y-m-d', strtotime($todayLocal . ' UTC') + 86400);
    $n = 0;
    $put = static function (string $localMoment, int $campaignId = 1) use ($insertLocal, $tag, $tz, &$n): void {
        $n++;
        $insertLocal("{$tag}-$n", $campaignId, $localMoment, $tz);
    };
    $day = static fn(string $d, string $t) => "$d $t";

    $put($day($yesterdayLocal, '23:59:59'));           // last second of yesterday
    $put($day($todayLocal, '00:00:00'));               // first second of today
    $put($day($todayLocal, '00:00:01'));
    $put($day($todayLocal, '12:00:00'));
    $put($day($tomorrowLocal, '00:00:00'));            // a future-dated click
    $put($day($yesterdayLocal, '00:00:00'));
    // Week edge: the Monday this_week starts from (±1 second around midnight).
    $weekAnchor = orbitraDashboardAnchorDay('this_week', $tz);
    if ($weekAnchor === null) {
        $weekAnchor = gmdate('Y-m-d', strtotime($todayLocal . ' UTC') - 86400);
    }
    $put($day($weekAnchor, '00:00:00'));
    $put($day($weekAnchor, '00:00:01'));
    $put($day(gmdate('Y-m-d', strtotime($weekAnchor . ' UTC') - 86400), '23:59:59'));
    // Month edge.
    $monthAnchor = orbitraDashboardAnchorDay('this_month', $tz) ?: $todayLocal;
    $put($day($monthAnchor, '00:00:00'));
    $put($day(gmdate('Y-m-d', strtotime($monthAnchor . ' UTC') - 86400), '23:59:59'));
    $put($day(gmdate('Y-m-d', strtotime($monthAnchor . ' UTC') + 15 * 86400), '09:30:00'));
    // 7d / 30d anchors, with the second before each.
    foreach (['last_7_days' => 7, 'last_30_days' => 30] as $preset => $days) {
        $anchor = orbitraDashboardAnchorDay($preset, $tz) ?: $todayLocal;
        $put($day($anchor, '00:00:00'));
        $put($day(gmdate('Y-m-d', strtotime($anchor . ' UTC') - 86400), '23:59:59'));
    }
    // Deep history + a safe-page hit + a second campaign's row.
    $put($day(gmdate('Y-m-d', strtotime($todayLocal . ' UTC') - 45 * 86400), '10:00:00'));
    $insertLocal("{$tag}-safe", 1, $day($todayLocal, '08:00:00'), $tz); // id ends in 'safe'
    $pdo->exec("UPDATE clicks SET is_safe_page = 1 WHERE id = '{$tag}-safe'");
    $put($day($todayLocal, '09:00:00'), 2);

    // --- anchor day: PHP must agree with SQLite's own date('now', …) ---------
    foreach ($presets as $preset => [$op, $oldExpr]) {
        $sqliteDay = (string) $pdo->query('SELECT ' . sprintf($oldExpr, $tz))->fetchColumn();
        $phpDay = orbitraDashboardAnchorDay($preset, $tz);
        check($phpDay === $sqliteDay,
            "[$tz] $preset anchor day: PHP $phpDay == SQLite $sqliteDay");
    }

    // --- presets: old expression vs PRODUCTION condition, row-set equality ---
    // The new side is the real builder getDashboardFilters() delegates to —
    // not a re-implementation (that is how the 'yesterday includes today'
    // regression slipped past an earlier revision of this test).
    foreach ($presets as $preset => [$op, $oldExpr]) {
        $oldCond = sprintf("date(cl.created_at, '%s') %s %s", $tz, $op, sprintf($oldExpr, $tz));
        [$newCond] = orbitraDashboardDateCondition('cl.created_at', $preset, null, null, $tz);
        $oldRows = $pdo->query("SELECT COUNT(*) n, COALESCE(GROUP_CONCAT(id ORDER BY id), '') ids FROM clicks cl WHERE $oldCond")->fetch(PDO::FETCH_ASSOC);
        $newRows = $pdo->query("SELECT COUNT(*) n, COALESCE(GROUP_CONCAT(id ORDER BY id), '') ids FROM clicks cl WHERE $newCond")->fetch(PDO::FETCH_ASSOC);
        check($oldRows === $newRows,
            "[$tz] $preset: old {$oldRows['n']} rows == new {$newRows['n']} rows, same ids");
    }

    // --- custom ranges --------------------------------------------------------
    $customs = [
        'from+to mid-window' => [$todayLocal, $todayLocal],
        'week so far'        => [$weekAnchor, $todayLocal],
        'two days'           => [$yesterdayLocal, $todayLocal],
        'from only'          => [$weekAnchor, null],
        'to only'            => [null, $yesterdayLocal],
        'inverted'           => [$todayLocal, $yesterdayLocal],
        'garbage from'       => ['nonsense', $todayLocal],
        'unpadded date'      => ['2026-9-5', $todayLocal],
    ];
    foreach ($customs as $label => [$from, $to]) {
        $oldConds = [];
        $oldParams = [];
        if ($from !== null) {
            $oldConds[] = "date(cl.created_at, '$tz') >= date(?)";
            $oldParams[] = $from;
        }
        if ($to !== null) {
            $oldConds[] = "date(cl.created_at, '$tz') <= date(?)";
            $oldParams[] = $to;
        }
        [$newCond] = orbitraDashboardDateCondition('cl.created_at', 'custom', $from, $to, $tz);
        $stmtOld = $pdo->prepare("SELECT COUNT(*) n, COALESCE(GROUP_CONCAT(id ORDER BY id), '') ids FROM clicks cl" . ($oldConds ? ' WHERE ' . implode(' AND ', $oldConds) : ''));
        $stmtOld->execute($oldParams);
        $oldRows = $stmtOld->fetch(PDO::FETCH_ASSOC);
        $newRows = $pdo->query("SELECT COUNT(*) n, COALESCE(GROUP_CONCAT(id ORDER BY id), '') ids FROM clicks cl" . ($newCond !== '' ? " WHERE $newCond" : ''))->fetch(PDO::FETCH_ASSOC);
        check($oldRows === $newRows,
            "[$tz] custom $label: old {$oldRows['n']} rows == new {$newRows['n']} rows, same ids");
    }

    // --- day equality (the ExtensionAdsStats shape) ---------------------------
    $oldEq = (int) $pdo->query("SELECT COUNT(*) FROM clicks cl WHERE date(cl.created_at, '$tz') = date('$todayLocal')")->fetchColumn();
    [$eqStart, $eqEnd] = orbitraLocalDayBoundsUtc($todayLocal, $tz);
    $newEq = (int) $pdo->query("SELECT COUNT(*) FROM clicks cl WHERE cl.created_at >= '$eqStart' AND cl.created_at < '$eqEnd'")->fetchColumn();
    check($oldEq === $newEq, "[$tz] day equality: old $oldEq == new $newEq");

    // --- campaigns-list join shape: identical per-campaign aggregates ---------
    $joinQuery = static fn(string $cond) => $pdo->query("
        SELECT c.id, COUNT(cl.id) visitors,
               SUM(cl.is_safe_page + 0) safe, COALESCE(GROUP_CONCAT(cl.id ORDER BY cl.id), '') ids
        FROM campaigns c
        LEFT JOIN clicks cl ON c.id = cl.campaign_id AND ($cond)
        GROUP BY c.id ORDER BY c.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $oldJoin = $joinQuery(sprintf("date(cl.created_at, '%s') >= %s", $tz, sprintf($presets['last_7_days'][1], $tz)));
    $newJoin = $joinQuery(orbitraLocalDayLowerBoundSql('cl.created_at', orbitraDashboardAnchorDay('last_7_days', $tz), $tz));
    check($oldJoin == $newJoin, "[$tz] campaigns join: identical per-campaign rows");

    // Clean slate for the next timezone.
    $pdo->exec('DELETE FROM clicks');
}

// --- the fixture really covers both sides of the boundaries -------------------
// Re-seed a minimal fixed dataset to pin the bounds mathematically (no 'now').
foreach ([
    ['b1', '2026-03-07 20:59:59'], // +03:00 → local 2026-03-07 23:59:59
    ['b2', '2026-03-07 21:00:00'], // +03:00 → local 2026-03-08 00:00:00
    ['b3', '2026-03-08 20:59:59'], // +03:00 → local 2026-03-08 23:59:59
    ['b4', '2026-03-08 21:00:00'], // +03:00 → local 2026-03-09 00:00:00
] as [$id, $utc]) {
    $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at) VALUES (?, 1, '203.0.113.2', ?)")
        ->execute([$id, $utc]);
}
[$s8, $e8] = orbitraLocalDayBoundsUtc('2026-03-08', '+03:00');
check($s8 === '2026-03-07 21:00:00' && $e8 === '2026-03-08 21:00:00',
    "bounds for 2026-03-08 at +03:00: [$s8, $e8)");
$inside = (string) $pdo->query("SELECT COALESCE(GROUP_CONCAT(id ORDER BY id), '') FROM clicks WHERE created_at >= '$s8' AND created_at < '$e8'")->fetchColumn();
check($inside === 'b2,b3', "fixed-boundary rows inside the +03:00 day: $inside");

// --- EXPLAIN QUERY PLAN: the range must be an index seek ----------------------
[$pStart] = orbitraLocalDayBoundsUtc('2026-03-01', '+03:00');
[$pEnd] = orbitraLocalDayBoundsUtc('2026-03-09', '+03:00');
$plan = $pdo->query("EXPLAIN QUERY PLAN
    SELECT COUNT(*) FROM campaigns c
    LEFT JOIN clicks cl ON c.id = cl.campaign_id AND cl.created_at >= '$pStart' AND cl.created_at < '$pEnd'
    WHERE c.is_archived = 0 GROUP BY c.id")->fetchAll(PDO::FETCH_ASSOC);
$planText = implode(' ', array_map(static fn($r) => (string) ($r['detail'] ?? ''), $plan));
check(strpos($planText, 'idx_clicks_campaign_created') !== false && strpos($planText, 'created_at>?') !== false && strpos($planText, 'created_at<?') !== false,
    "query plan seeks idx_clicks_campaign_created by the range: $planText");

@unlink($dbPath);
@unlink("$dbPath-wal");
@unlink("$dbPath-shm");

echo $failures === 0 ? "Report date range tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
