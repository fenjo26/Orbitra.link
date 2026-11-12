<?php
// core/DashboardChart.php
//
// The dashboard chart's time axis and series, as pure functions so the whole
// thing is testable without a request (tests/dashboard_chart_test.php).
//
// Rules this file exists to keep:
//   - every card on the dashboard has a series, computed by the same
//     orbitraComputeDerivedMetrics() the cards use — a line can never disagree
//     with its card, and a metric switched on in the gear menu can be drawn;
//   - a bucket that has not happened yet is null, not 0: a zero-filled future
//     drew a fake collapse at the end of "today" / "this week" / "this month";
//   - the response carries machine-readable period keys and the granularity,
//     so the panel formats axis labels in the user's own language (the labels
//     used to be hard-coded Russian for all seven locales).

require_once __DIR__ . '/ReportMetrics.php';

if (!function_exists('orbitraDashboardChartMetricKeys')) {

    /** Every series the chart exposes — one per dashboard metric card. */
    function orbitraDashboardChartMetricKeys(): array
    {
        return [
            // Traffic & volume
            'clicks', 'unique_clicks', 'real_offer_clicks', 'bots',
            // Events
            'conversions', 'leads', 'sales',
            // Money
            'cost', 'revenue', 'profit', 'revenue_confirmed', 'profit_confirmed', 'real_revenue',
            // ROI family
            'roi', 'roi_confirmed', 'real_roi',
            // Unit economics
            'cpa', 'cpc', 'cpv', 'cpl', 'cps', 'epc', 'epv',
            // Rates
            'cr', 'approve_rate', 'lp_ctr', 'bot_rate', 'ctr',
        ];
    }

    /**
     * The chart's time axis for one dashboard preset.
     *
     * "Now" is taken under the same fixed $dbTzOffset the SQL buckets clicks
     * with, so the last drawn bucket and the first future one can never
     * disagree with the grouping.
     *
     * @return array{granularity:string,time_format:string,periods:string[],labels:string[],future_from:?int}
     *         periods      bucket keys exactly as the SQL emits them
     *                      ('Y-m-d H:00:00' | 'Y-m-d' | 'Y-m-01');
     *         labels       legacy, language-neutral axis labels (API/MCP callers);
     *         future_from  index of the first bucket that has not started yet,
     *                      null when the whole axis is in the past or present.
     */
    function orbitraDashboardChartPlan(string $dateRange, ?string $customFrom, ?string $customTo, string $dbTzOffset, ?int $nowTs = null): array
    {
        $nowTs = $nowTs ?? time();
        $localNow = $nowTs + orbitraTzOffsetSeconds($dbTzOffset);
        $today = gmdate('Y-m-d', $localNow);
        $isDay = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1
            && strtotime($d . ' 00:00:00 UTC') !== false;
        $shiftDay = static fn(string $day, int $days): string =>
            gmdate('Y-m-d', strtotime($day . ' 00:00:00 UTC') + $days * 86400);

        $customValid = $dateRange === 'custom' && $isDay($customFrom) && $isDay($customTo);

        // --- one local day → 24 hourly buckets -----------------------------
        $singleDay = null;
        if ($dateRange === 'today') {
            $singleDay = $today;
        } elseif ($dateRange === 'yesterday') {
            $singleDay = $shiftDay($today, -1);
        } elseif ($customValid && $customFrom === $customTo) {
            $singleDay = $customFrom;
        }

        if ($singleDay !== null) {
            $periods = [];
            $labels = [];
            for ($h = 0; $h < 24; $h++) {
                $hh = str_pad((string) $h, 2, '0', STR_PAD_LEFT);
                $periods[] = "$singleDay $hh:00:00";
                $labels[] = "$hh:00";
            }
            $nowKey = gmdate('Y-m-d H:00:00', $localNow);
            return [
                'granularity' => 'hour',
                'time_format' => '%Y-%m-%d %H:00:00',
                'periods' => $periods,
                'labels' => $labels,
                'future_from' => orbitraDashboardChartFutureIndex($periods, $nowKey),
            ];
        }

        // --- this_year → 12 monthly buckets --------------------------------
        if ($dateRange === 'this_year') {
            $year = gmdate('Y', $localNow);
            $periods = [];
            $labels = [];
            for ($m = 1; $m <= 12; $m++) {
                $key = sprintf('%s-%02d-01', $year, $m);
                $periods[] = $key;
                $labels[] = gmdate('M', strtotime($key . ' 00:00:00 UTC'));
            }
            return [
                'granularity' => 'month',
                'time_format' => '%Y-%m-01',
                'periods' => $periods,
                'labels' => $labels,
                'future_from' => orbitraDashboardChartFutureIndex($periods, gmdate('Y-m-01', $localNow)),
            ];
        }

        // --- everything else → daily buckets -------------------------------
        // The rolling presets start on the SAME anchor day the click filter
        // uses (orbitraDashboardAnchorDay): the chart used to start a day
        // later than the cards for last_7_days / last_30_days and silently
        // dropped the oldest day the cards were still counting.
        $start = $today;
        $end = $today;
        $labelFormat = 'd.m';
        switch ($dateRange) {
            case 'this_week':
                $start = orbitraDashboardAnchorDay('this_week', $dbTzOffset, $nowTs) ?? $today;
                // The axis runs to the Sunday of the current local week.
                $end = $shiftDay($today, 7 - (int) gmdate('N', $localNow));
                $labelFormat = 'D';
                break;
            case 'last_7_days':
            case 'last_30_days':
                $start = orbitraDashboardAnchorDay($dateRange, $dbTzOffset, $nowTs) ?? $today;
                break;
            case 'last_month':
                $end = $shiftDay(gmdate('Y-m-01', $localNow), -1);
                $start = substr($end, 0, 8) . '01';
                $labelFormat = 'd';
                break;
            case 'custom':
                if ($customValid) {
                    $start = $customFrom;
                    $end = $customTo;
                }
                break;
            case 'this_month':
                $start = orbitraDashboardAnchorDay('this_month', $dbTzOffset, $nowTs) ?? gmdate('Y-m-01', $localNow);
                $end = gmdate('Y-m-t', $localNow);
                $labelFormat = 'd';
                break;
            default:
                // No/unknown preset keeps the historical default: the current
                // calendar month, day by day.
                $start = gmdate('Y-m-01', $localNow);
                $end = gmdate('Y-m-t', $localNow);
                $labelFormat = 'd';
                break;
        }
        if (strcmp($start, $end) > 0) {
            $start = $end;
        }

        $periods = [];
        $labels = [];
        $cursor = $start;
        // Hard cap: a mistyped custom range must not build an unbounded axis.
        for ($i = 0; $i < 366 && strcmp($cursor, $end) <= 0; $i++) {
            $periods[] = $cursor;
            $labels[] = gmdate($labelFormat, strtotime($cursor . ' 00:00:00 UTC'));
            $cursor = $shiftDay($cursor, 1);
        }

        return [
            'granularity' => 'day',
            'time_format' => '%Y-%m-%d',
            'periods' => $periods,
            'labels' => $labels,
            'future_from' => orbitraDashboardChartFutureIndex($periods, $today),
        ];
    }

    /** Index of the first period key strictly after $nowKey, or null. */
    function orbitraDashboardChartFutureIndex(array $periods, string $nowKey): ?int
    {
        foreach ($periods as $i => $key) {
            if (strcmp($key, $nowKey) > 0) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Series for every chart metric over the plan's periods.
     *
     * @param array $plan  orbitraDashboardChartPlan() result
     * @param array $rows  raw grouped SQL rows (orbitraDashboardMetricsSql with
     *                     a `period` column)
     * @return array<int,array{label:string,data:array}> one dataset per metric;
     *         a value is null for a bucket in the future, and for a ratio that
     *         is undefined in that bucket (ROI without spend, LP CTR without a
     *         landing) — never a made-up 0.
     */
    function orbitraDashboardChartDatasets(array $plan, array $rows): array
    {
        $byPeriod = [];
        foreach ($rows as $row) {
            $period = (string) ($row['period'] ?? '');
            if ($period !== '') {
                $byPeriod[$period] = $row;
            }
        }

        $keys = orbitraDashboardChartMetricKeys();
        $series = array_fill_keys($keys, []);
        $futureFrom = $plan['future_from'] ?? null;

        foreach ($plan['periods'] as $i => $period) {
            if ($futureFrom !== null && $i >= $futureFrom) {
                foreach ($keys as $key) {
                    $series[$key][] = null;
                }
                continue;
            }
            $m = orbitraComputeDerivedMetrics($byPeriod[$period] ?? []);
            // CTR stays the same placeholder the card shows until impression
            // tracking exists (see the `metrics` action).
            $m['ctr'] = 100;
            foreach ($keys as $key) {
                $series[$key][] = $m[$key] ?? null;
            }
        }

        $datasets = [];
        foreach ($keys as $key) {
            $datasets[] = ['label' => $key, 'data' => $series[$key]];
        }
        return $datasets;
    }

    /** Line styles a user may pick per metric ('auto' = the family default). */
    function orbitraDashboardChartStyles(): array
    {
        // Must match CHART_STYLES in frontend/src/utils/chartMetrics.js: a
        // style the picker offers but this list drops is lost on save — the
        // user sees it until the next load from the server, then it silently
        // resets (the colour-blind preset sets CTR to 'dashdot').
        return ['solid', 'dashed', 'dotted', 'dashdot', 'bars'];
    }

    /**
     * Per-user chart appearance, reduced to what the panel understands:
     * {colors: {metric: '#rrggbb'}, styles: {metric: style}}. Unknown metrics,
     * malformed colours and unknown styles are dropped rather than stored —
     * the value is rendered into a canvas and into inline CSS for every card.
     */
    function orbitraDashboardChartPrefsSanitize($input): array
    {
        $out = ['colors' => [], 'styles' => []];
        if (!is_array($input)) {
            return $out;
        }
        $metrics = array_flip(orbitraDashboardChartMetricKeys());
        $styles = orbitraDashboardChartStyles();
        foreach ((array) ($input['colors'] ?? []) as $metric => $color) {
            if (isset($metrics[$metric]) && is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $out['colors'][$metric] = strtolower($color);
            }
        }
        foreach ((array) ($input['styles'] ?? []) as $metric => $style) {
            if (isset($metrics[$metric]) && is_string($style) && in_array($style, $styles, true)) {
                $out['styles'][$metric] = $style;
            }
        }
        return $out;
    }

    /** Stored chart prefs of one user ({colors:{}, styles:{}} when none). */
    function orbitraDashboardChartPrefsLoad(PDO $pdo, int $userId): array
    {
        orbitraDashboardChartPrefsEnsureTable($pdo);
        $stmt = $pdo->prepare('SELECT chart_prefs FROM user_dashboard_prefs WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $raw = $stmt->fetchColumn();
        $stmt->closeCursor();
        return orbitraDashboardChartPrefsSanitize(is_string($raw) ? json_decode($raw, true) : null);
    }

    /** Save (replace) one user's chart prefs; returns what was stored. */
    function orbitraDashboardChartPrefsSave(PDO $pdo, int $userId, $input): array
    {
        orbitraDashboardChartPrefsEnsureTable($pdo);
        $clean = orbitraDashboardChartPrefsSanitize($input);
        // Empty maps must serialise as {} — [] would come back as a list.
        $json = json_encode([
            'colors' => (object) $clean['colors'],
            'styles' => (object) $clean['styles'],
        ]);
        $pdo->prepare('INSERT OR REPLACE INTO user_dashboard_prefs (user_id, chart_prefs, updated_at) VALUES (?, ?, ?)')
            ->execute([$userId, $json, gmdate('Y-m-d H:i:s')]);
        return $clean;
    }

    /**
     * Created on first use instead of by a migration: the feature needs no
     * action on existing servers, and an install that never opens the colour
     * picker never grows the table.
     */
    function orbitraDashboardChartPrefsEnsureTable(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_dashboard_prefs (
            user_id INTEGER PRIMARY KEY,
            chart_prefs TEXT NOT NULL DEFAULT '{}',
            updated_at TEXT
        )");
        $done = true;
    }
}
