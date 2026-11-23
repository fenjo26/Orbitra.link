<?php
/**
 * Dashboard top blocks and the conversions log around the stats seam (ТЗ 2.1
 * rollups), HTTP-level through the production router.
 *
 * Pins:
 *  - the resource lists (offers / landings / sources) honour the campaign
 *    filter and the signed-in user's campaign scope on BOTH halves of the
 *    seam aggregate — the frozen half used to carry every campaign;
 *  - campaign_report's country layer is one row per country across the seam
 *    (the raw half grouped by the country NAME, the frozen half by the code);
 *  - top_countries (the dashboard's "Countries" block) equals the all-raw
 *    reference, with the campaign filter and the user's scope applied;
 *  - the money columns of every block are masked for a finance-restricted user;
 *  - the conversions log filters by geo / campaign / offer / landing.
 *
 * Usage: php tests/dashboard_blocks_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/http.php';

$failures = 0;
function check(bool $condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

$harness = new OrbitraTestHarness(dirname(__DIR__));
// Panel-API test: router.php knows /api.php (index.php-as-router would answer
// with a campaign page).
$harness->useProductionRouter();
$harness->start();
try {
    $pdo = $harness->getPdo();
    $work = $harness->getWorkingDir();
    @mkdir($work . '/cli', 0777, true);
    copy(__DIR__ . '/../cli/stats_freeze_cron.php', $work . '/cli/stats_freeze_cron.php');

    $setSetting = static function (string $k, string $v) use ($pdo): void {
        $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                       ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$k, $v]);
    };

    // --- seed: two campaigns sharing an offer, a landing and a source ---------
    // Three clicks sit in a frozen hour (5 days old, past the 3-day raw
    // window), two in the raw window — every request below crosses the seam.
    $setSetting('stats_raw_retention_days', '3');
    $now = time();
    $oldHour = (int) (($now - 5 * 86400) / 3600);
    $hourTs = static fn (int $h): string => gmdate('Y-m-d H:i:s', $h * 3600);
    $freshTs = gmdate('Y-m-d H:i:s', $now - 1800);

    // Ids come from a private range: a freshly installed schema already holds
    // system rows with low ids.
    $c1 = 9001;   // campaign A
    $c2 = 9002;   // campaign B
    $ox = 9020;   // offer X, used by both campaigns
    $oy = 9021;   // offer Y, campaign A only
    $lid = 9030;  // landing L
    $sid = 9040;  // source S
    $pdo->exec("INSERT INTO campaigns (id, name, alias, token, state, is_archived, source_id) VALUES
        ($c1, 'Camp A', 'camp-a', 'tok-a', 'active', 0, $sid),
        ($c2, 'Camp B', 'camp-b', 'tok-b', 'active', 0, $sid)");
    $pdo->exec("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES
        ($ox, 'Offer X', 'https://example.com/x', 0, 'active', 0),
        ($oy, 'Offer Y', 'https://example.com/y', 0, 'active', 0)");
    $pdo->exec("INSERT INTO landings (id, name, url, type, state) VALUES ($lid, 'Landing L', 'https://example.com/l', 'external', 'active')");
    $pdo->exec("INSERT INTO traffic_sources (id, name, template) VALUES ($sid, 'Source S', 'tiktok')");

    $insClick = $pdo->prepare("
        INSERT INTO clicks (id, campaign_id, offer_id, landing_id, source_id, ip, referer,
            country, country_code, device_type, os, browser, is_bot, pointer_activity,
            uniq_campaign, uniq_stream, uniq_global, is_safe_page, cost, created_at)
        VALUES (:id, :c, :o, :l, :s, :ip, '', :country, :cc, 'desktop', 'windows', 'chrome', 0, 1,
            1, 1, 1, 0, :cost, :ts)");
    $mkClick = static function (string $id, int $c, ?int $o, ?int $l, ?int $s, string $cc, string $country, float $cost, string $ts) use ($insClick): void {
        $insClick->execute(['id' => $id, 'c' => $c, 'o' => $o, 'l' => $l, 's' => $s, 'ip' => '203.0.113.' . random_int(1, 250),
            'cc' => $cc, 'country' => $country, 'cost' => $cost, 'ts' => $ts]);
    };
    $old = $hourTs($oldHour);
    $mkClick('blk-o1', $c1, $ox, $lid, $sid, 'DE', 'Germany', 0.50, $old);
    $mkClick('blk-o2', $c2, $ox, $lid, $sid, 'US', 'United States', 0.20, $old);
    $mkClick('blk-o3', $c1, $oy, $lid, null, 'DE', 'Germany', 0.10, $old);
    $mkClick('blk-f1', $c1, $ox, null, $sid, 'TR', 'Türkiye', 0.30, $freshTs);
    $mkClick('blk-f2', $c1, $ox, $lid, $sid, 'DE', 'Germany', 0.10, $freshTs);

    $insConv = $pdo->prepare("
        INSERT INTO conversions (click_id, tid, status, payout, currency, campaign_id, offer_id,
            landing_id, country_code, created_at)
        VALUES (:click, NULL, :status, :payout, 'USD', :c, :o, :l, :cc, :ts)");
    $mkConv = static function (string $click, int $c, int $o, ?int $l, string $cc, string $status, float $payout, string $ts) use ($insConv): void {
        $insConv->execute(['click' => $click, 'c' => $c, 'o' => $o, 'l' => $l, 'cc' => $cc, 'status' => $status, 'payout' => $payout, 'ts' => $ts]);
    };
    $mkConv('blk-o1', $c1, $ox, $lid, 'DE', 'sale', 12.30, $old);
    $mkConv('blk-o2', $c2, $ox, $lid, 'US', 'sale', 5.00, $old);
    $mkConv('blk-f1', $c1, $ox, null, 'TR', 'lead', 1.00, $freshTs);
    $mkConv('blk-f2', $c1, $ox, $lid, 'DE', 'rejected', 0.50, $freshTs);

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/stats_freeze_cron.php')
        . ' --quiet --budget=10 2>/dev/null');
    $watermark = (int) $pdo->query("SELECT value FROM settings WHERE key='stats_frozen_through_hour'")->fetchColumn();
    check($watermark === (int) (($now - 3 * 86400) / 3600) - 1, 'freeze reached the window target');
    check((int) $pdo->query("SELECT COALESCE(SUM(clicks), 0) FROM stats_place_country")->fetchColumn() === 3,
        'the three old clicks are frozen');

    // api.php needs a signed-in principal: one admin, one user limited to campaign 1.
    $apiKey = static function (string $name, string $role, ?array $permissions) use ($pdo): string {
        $pdo->prepare('INSERT INTO users (username, password, role, permissions_json) VALUES (?, ?, ?, ?)')
            ->execute([$name, password_hash('x', PASSWORD_DEFAULT), $role, $permissions ? json_encode($permissions) : null]);
        $userId = (int) $pdo->lastInsertId();
        $key = 'test_key_' . bin2hex(random_bytes(8));
        $pdo->prepare('INSERT INTO user_api_keys (user_id, api_key, key_name, permissions) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $key, $name, 'full']);
        return $key;
    };
    $adminKey = $apiKey('dash_admin', 'admin', null);
    $scopedKey = $apiKey('dash_scoped', 'user', ['campaigns' => ['access' => 'selected', 'items' => [$c1]]]);
    $noMoneyKey = $apiKey('dash_nomoney', 'user', ['finance' => ['show_costs' => false, 'show_revenue' => false, 'show_payout' => false]]);

    $call = static function (string $key, string $path) use ($harness): array {
        $resp = $harness->getWithHeaders($path, ['Authorization: Bearer ' . $key]);
        $json = json_decode($resp['body'], true);
        return is_array($json) ? $json : ['status' => 'error', 'message' => substr((string) $resp['body'], 0, 200)];
    };
    // A masked money column arrives as an explicit null; `?? ` would hide that.
    $isNull = static fn (array $row, string $key): bool => array_key_exists($key, $row) && $row[$key] === null;
    $rowById = static function (array $rows, string $key, $value): ?array {
        foreach ($rows as $row) {
            if ((string) ($row[$key] ?? '') === (string) $value) {
                return $row;
            }
        }
        return null;
    };

    // ===== A. Resource lists across the seam: campaign filter + scope ========
    // Offer X: 4 clicks in total (blk-o1, blk-o2, blk-f1, blk-f2), 3 of them
    // in campaign 1 (cost 0.90); blk-o2 is the frozen click of campaign 2.
    $all = $call($adminKey, '/api.php?action=offers&date_range=all');
    $offerAll = $rowById($all['data'] ?? [], 'id', $ox);
    check($all['status'] === 'success' && $offerAll && (int) $offerAll['clicks'] === 4,
        'offers, all campaigns: the shared offer counts 4 clicks across the seam (got ' . ($offerAll['clicks'] ?? 'n/a') . ')');

    $filtered = $call($adminKey, '/api.php?action=offers&date_range=all&campaign_id=' . $c1);
    $offerFiltered = $rowById($filtered['data'] ?? [], 'id', $ox);
    check($offerFiltered && (int) $offerFiltered['clicks'] === 3 && abs((float) $offerFiltered['cost'] - 0.90) < 0.0001,
        'offers, campaign_id=1: the frozen half honours the campaign filter (clicks ' . ($offerFiltered['clicks'] ?? 'n/a') . ', expect 3)');

    $scoped = $call($scopedKey, '/api.php?action=offers&date_range=all');
    $offerScoped = $rowById($scoped['data'] ?? [], 'id', $ox);
    check($offerScoped && (int) $offerScoped['clicks'] === 3 && abs((float) $offerScoped['cost'] - 0.90) < 0.0001,
        'offers, user scoped to campaign 1: the frozen half honours the campaign scope (clicks ' . ($offerScoped['clicks'] ?? 'n/a') . ', expect 3)');

    $landings = $call($adminKey, '/api.php?action=landings&date_range=all&campaign_id=' . $c1);
    $landingFiltered = $rowById($landings['data'] ?? [], 'id', $lid);
    check($landingFiltered && (int) $landingFiltered['clicks'] === 3,
        'landings, campaign_id=1: the frozen half honours the campaign filter (clicks ' . ($landingFiltered['clicks'] ?? 'n/a') . ', expect 3)');

    $sources = $call($adminKey, '/api.php?action=traffic_sources&date_range=all&campaign_id=' . $c2);
    $sourceFiltered = $rowById($sources['data'] ?? [], 'id', $sid);
    check($sourceFiltered && (int) $sourceFiltered['clicks'] === 1,
        'sources, campaign_id=2: the frozen half honours the campaign filter (clicks ' . ($sourceFiltered['clicks'] ?? 'n/a') . ', expect 1)');

    // The list still names every campaign; the filter only narrows the stats —
    // campaign A's row must not keep its two frozen clicks.
    $campaigns = $call($adminKey, '/api.php?action=campaigns&date_range=all&campaign_id=' . $c2);
    $campB = $rowById($campaigns['data'] ?? [], 'id', $c2);
    $campA = $rowById($campaigns['data'] ?? [], 'id', $c1);
    check($campB && (int) $campB['clicks'] === 1 && $campA && (int) $campA['clicks'] === 0,
        'campaigns, campaign_id=B: B keeps its click, A shows none (got A ' . ($campA['clicks'] ?? 'n/a') . ', B ' . ($campB['clicks'] ?? 'n/a') . ')');

    // ===== B. Country layer of the campaign report across the seam ============
    // Three clicks from Germany: two frozen, one raw. They are one country.
    $report = $call($adminKey, '/api.php?action=campaign_report&group_by=country&date_from=2000-01-01&date_to=2030-12-31');
    $byCountry = [];
    foreach ($report['data']['rows'] ?? [] as $row) {
        $byCountry[implode('|', $row['dims'] ?? [])] = (int) ($row['clicks'] ?? 0);
    }
    ksort($byCountry);
    check($byCountry === ['DE' => 3, 'TR' => 1, 'US' => 1],
        'campaign_report by country: one row per country code across the seam (got ' . json_encode($byCountry) . ')');

    $reportFiltered = $call($adminKey, '/api.php?action=campaign_report&group_by=country&date_from=2000-01-01&date_to=2030-12-31&filters=' . urlencode(json_encode([['field' => 'country', 'op' => 'eq', 'value' => 'DE']])));
    $deClicks = 0;
    foreach ($reportFiltered['data']['rows'] ?? [] as $row) {
        $deClicks += (int) ($row['clicks'] ?? 0);
    }
    check($deClicks === 3, "campaign_report, country = DE: the filter matches the raw half too (clicks {$deClicks}, expect 3)");

    // ===== C. "Countries" block: equals the all-raw reference ================
    $countries = $call($adminKey, '/api.php?action=top_countries&date_range=all');
    $byCode = [];
    foreach ($countries['data'] ?? [] as $row) {
        $byCode[(string) ($row['country_code'] ?? '')] = $row;
    }
    check(($countries['status'] ?? '') === 'success' && count($byCode) === 3 && array_keys($byCode) === ['DE', 'TR', 'US'],
        'top_countries: three countries, busiest first (got ' . implode(',', array_keys($byCode)) . ')');
    $de = $byCode['DE'] ?? [];
    check((int) ($de['clicks'] ?? 0) === 3 && (int) ($de['conversions'] ?? 0) === 2
        && abs((float) ($de['revenue'] ?? 0) - 12.80) < 0.0001 && abs((float) ($de['cost'] ?? 0) - 0.70) < 0.0001
        && abs((float) ($de['profit'] ?? 0) - 12.10) < 0.0001 && (int) ($de['unique_clicks'] ?? 0) === 3,
        'top_countries, Germany: 3 clicks, 2 conversions, 12.80 revenue, 0.70 cost, 12.10 profit across the seam');
    check(abs((float) ($byCode['TR']['revenue'] ?? 0) - 1.00) < 0.0001 && abs((float) ($byCode['US']['revenue'] ?? 0) - 5.00) < 0.0001,
        'top_countries: Turkey 1.00 and the United States 5.00 revenue');

    $countriesCamp = $call($adminKey, '/api.php?action=top_countries&date_range=all&campaign_id=' . $c1);
    $campCodes = array_map(static fn ($r) => (string) $r['country_code'], $countriesCamp['data'] ?? []);
    sort($campCodes);
    check($campCodes === ['DE', 'TR'], 'top_countries, campaign_id=A: only that campaign\'s countries (got ' . implode(',', $campCodes) . ')');

    $countriesScoped = $call($scopedKey, '/api.php?action=top_countries&date_range=all');
    $scopedCodes = array_map(static fn ($r) => (string) $r['country_code'], $countriesScoped['data'] ?? []);
    sort($scopedCodes);
    check(($countriesScoped['status'] ?? '') === 'success' && $scopedCodes === ['DE', 'TR'],
        'top_countries, user scoped to campaign A: allowed, and only their countries (got ' . implode(',', $scopedCodes) . ')');

    $countriesNoMoney = $call($noMoneyKey, '/api.php?action=top_countries&date_range=all');
    $nomoneyRow = $rowById($countriesNoMoney['data'] ?? [], 'country_code', 'DE') ?? [];
    check($isNull($nomoneyRow, 'cost') && $isNull($nomoneyRow, 'revenue') && $isNull($nomoneyRow, 'profit')
        && (int) ($nomoneyRow['clicks'] ?? 0) === 3,
        'top_countries, user without the money rights: cost/revenue/profit are null, clicks stay');

    // A period entirely inside the raw window takes the raw-only path and must
    // count exactly the clicks of that local day (timezone=UTC keeps it plain).
    $todayStart = gmdate('Y-m-d') . ' 00:00:00';
    $expectedToday = (int) $pdo->query("SELECT COUNT(*) FROM clicks WHERE created_at >= '$todayStart' AND campaign_id IN ($c1, $c2)")->fetchColumn();
    $countriesToday = $call($adminKey, '/api.php?action=top_countries&date_range=today&timezone=UTC');
    $sumToday = array_sum(array_map(static fn ($r) => (int) ($r['clicks'] ?? 0), $countriesToday['data'] ?? []));
    check(($countriesToday['status'] ?? '') === 'success' && $sumToday === $expectedToday && $expectedToday > 0,
        "top_countries, raw-only period: {$sumToday} clicks of today, the base holds {$expectedToday}");

    // ===== D. Sources carry money; every list masks it =======================
    // Source S: all four clicks (cost 1.10) and all four conversions (18.80).
    $sourcesAll = $call($adminKey, '/api.php?action=traffic_sources&date_range=all');
    $sourceAll = $rowById($sourcesAll['data'] ?? [], 'id', $sid) ?? [];
    check((int) ($sourceAll['clicks'] ?? 0) === 4 && (int) ($sourceAll['conversions'] ?? 0) === 4
        && abs((float) ($sourceAll['cost'] ?? 0) - 1.10) < 0.0001 && abs((float) ($sourceAll['revenue'] ?? 0) - 18.80) < 0.0001
        && abs((float) ($sourceAll['profit'] ?? 0) - 17.70) < 0.0001,
        'sources: cost, revenue and profit across the seam (clicks ' . ($sourceAll['clicks'] ?? 'n/a') . ', revenue ' . ($sourceAll['revenue'] ?? 'n/a') . ')');
    $sourceNoMoney = $rowById($call($noMoneyKey, '/api.php?action=traffic_sources&date_range=all')['data'] ?? [], 'id', $sid) ?? [];
    check($isNull($sourceNoMoney, 'cost') && $isNull($sourceNoMoney, 'revenue') && $isNull($sourceNoMoney, 'profit'),
        'sources, user without the money rights: cost/revenue/profit are null');

    // The raw-only path reads the same money: today's clicks of the source's
    // campaigns and the conversions on them (expected values from the base).
    $sourcesToday = $call($adminKey, '/api.php?action=traffic_sources&date_range=today&timezone=UTC');
    $sourceToday = $rowById($sourcesToday['data'] ?? [], 'id', $sid) ?? [];
    $expectedTodaySource = $pdo->query("
        SELECT COUNT(*), COALESCE(SUM(cva.rev_all), 0)
        FROM clicks cl
        JOIN campaigns c ON c.id = cl.campaign_id
        LEFT JOIN (SELECT click_id, SUM(payout) AS rev_all FROM conversions GROUP BY click_id) cva ON cva.click_id = cl.id
        WHERE cl.created_at >= '$todayStart' AND c.source_id = $sid")->fetch(PDO::FETCH_NUM);
    check((int) ($sourceToday['clicks'] ?? -1) === (int) $expectedTodaySource[0]
        && abs((float) ($sourceToday['revenue'] ?? 0) - (float) $expectedTodaySource[1]) < 0.0001,
        "sources, raw-only period: {$expectedTodaySource[0]} clicks of today, {$expectedTodaySource[1]} revenue (got " . ($sourceToday['clicks'] ?? 'n/a') . ' / ' . ($sourceToday['revenue'] ?? 'n/a') . ')');

    foreach (['offers' => $ox, 'landings' => $lid] as $listAction => $listId) {
        $adminRow = $rowById($call($adminKey, "/api.php?action={$listAction}&date_range=all")['data'] ?? [], 'id', $listId) ?? [];
        $noMoneyRow = $rowById($call($noMoneyKey, "/api.php?action={$listAction}&date_range=all")['data'] ?? [], 'id', $listId) ?? [];
        check(($adminRow['revenue'] ?? null) !== null && abs((float) ($adminRow['cost'] ?? 0)) > 0
            && $isNull($noMoneyRow, 'cost') && $isNull($noMoneyRow, 'revenue') && $isNull($noMoneyRow, 'profit')
            && (int) ($noMoneyRow['clicks'] ?? 0) > 0,
            "{$listAction}: the admin sees money, a user without the money rights gets nulls and keeps the clicks");
    }

    // ===== E. Conversions log filters =========================================
    // One more conversion, 23:30 UTC on 2026-01-15: 02:30 on the 16th in Istanbul.
    $mkConv('blk-f1', $c1, $ox, null, 'TR', 'deposit', 7.77, '2026-01-15 23:30:00');
    $conversions = static function (string $key, string $extra = '') use ($call): array {
        $res = $call($key, '/api.php?action=conversions&per_page=50' . $extra);
        return is_array($res['data'] ?? null) ? $res : ['status' => 'error', 'data' => [], 'pagination' => ['total' => -1]];
    };

    $all = $conversions($adminKey);
    $sortedDesc = static function (array $rows): bool {
        for ($i = 1; $i < count($rows); $i++) {
            if (strcmp((string) $rows[$i - 1]['created_at'], (string) $rows[$i]['created_at']) < 0) {
                return false;
            }
        }
        return true;
    };
    check(($all['status'] ?? '') === 'success' && count($all['data']) === 5 && $sortedDesc($all['data']),
        'conversions: all five, newest first (got ' . count($all['data']) . ')');
    $cv1 = $rowById($all['data'], 'click_id', 'blk-o1') ?? [];
    check(($cv1['country_code'] ?? '') === 'DE' && ($cv1['landing_name'] ?? '') === 'Landing L',
        'conversions: every row carries its country and the site (landing) name');

    $de = $conversions($adminKey, '&country=de');
    check(count($de['data']) === 2 && (int) ($de['pagination']['total'] ?? 0) === 2
        && array_filter($de['data'], static fn ($r) => $r['country_code'] !== 'DE') === [],
        'conversions, country=de: the two German ones, the code is normalised (got ' . count($de['data']) . ')');
    $byCamp = $conversions($adminKey, '&campaign_id=' . $c2);
    check(count($byCamp['data']) === 1 && (int) ($byCamp['data'][0]['campaign_id'] ?? 0) === $c2,
        'conversions, campaign_id=B: the one conversion of campaign B');
    $byOffer = $conversions($adminKey, '&offer_id=' . $oy);
    check(count($byOffer['data']) === 0, 'conversions, offer_id=Y: none (offer Y has no conversions)');
    $bySite = $conversions($adminKey, '&landing_id=' . $lid);
    check(count($bySite['data']) === 3 && array_filter($bySite['data'], static fn ($r) => (int) ($r['landing_id'] ?? 0) !== $lid) === [],
        'conversions, landing_id: the three made on that site');
    $both = $conversions($adminKey, '&country=DE&landing_id=' . $lid);
    check(count($both['data']) === 2, 'conversions, country + site: the filters narrow each other');
    $scopedConvs = $conversions($scopedKey);
    check(count($scopedConvs['data']) === 4 && array_filter($scopedConvs['data'], static fn ($r) => (int) ($r['campaign_id'] ?? 0) !== $c1) === [],
        'conversions, user scoped to campaign A: only that campaign\'s four');
    $noMoneyConvs = $conversions($noMoneyKey);
    check(count($noMoneyConvs['data']) === 5 && $isNull($noMoneyConvs['data'][0], 'payout'),
        'conversions, user without the payout right: rows stay, payout is null');

    $geoOptions = $call($adminKey, '/api.php?action=conversions_countries');
    $geoScoped = $call($scopedKey, '/api.php?action=conversions_countries');
    check(($geoOptions['data'] ?? null) === ['DE', 'TR', 'US'] && ($geoScoped['data'] ?? null) === ['DE', 'TR'],
        'conversions_countries: DE,TR,US for the admin, DE,TR for the scoped user (got ' . json_encode($geoOptions['data'] ?? null) . ' / ' . json_encode($geoScoped['data'] ?? null) . ')');

    $tzDay = static function (string $tz) use ($conversions, $adminKey): int {
        $res = $conversions($adminKey, '&date_from=2026-01-16&date_to=2026-01-16&timezone=' . rawurlencode($tz));
        return count(array_filter($res['data'], static fn ($r) => ($r['created_at'] ?? '') === '2026-01-15 23:30:00'));
    };
    check($tzDay('Europe/Istanbul') === 1 && $tzDay('UTC') === 0,
        'conversions, 16 January: the 23:30 UTC conversion belongs to the day in the picker\'s timezone, not to UTC');
} finally {
    $harness->stop();
}

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);
