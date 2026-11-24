<?php
/**
 * Domain dimension and multi-value filter tests for Analytics -> Reports
 * (api.php?action=campaign_report), HTTP-level through the production router.
 *
 * Covers issue #20:
 *  - historical attribution via clicks.domain even after a campaign moves to a new domain;
 *  - empty / blank / NULL clicks.domain attributed to "Unknown";
 *  - case-insensitive normalization of clicks.domain and domain filters;
 *  - standalone and multi-level groupings (domain->campaign, domain->country,
 *    domain->source, campaign->domain, domain->campaign->country);
 *  - full metrics including bots, proxies, conversions, cost, revenue, profit, ROI;
 *  - single and multi-domain filtering (eq, neq, contains, not_contains, in, not_in);
 *  - freeze/rollup seam compatibility: domain falls back cleanly to raw-only with
 *    stats_seam.raw_available_from, while rollup-compatible IN/NOT IN filters
 *    (e.g. country IN) execute across both frozen and raw halves.
 *
 * Usage: php tests/domain_report_test.php
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

    $setSetting('stats_raw_retention_days', '3');
    $now = time();
    $oldHour = (int) (($now - 5 * 86400) / 3600);
    $oldTs = gmdate('Y-m-d H:i:s', $oldHour * 3600);
    $freshTs = gmdate('Y-m-d H:i:s', $now - 1800);

    // Seed two domains, two campaigns, one source, one offer.
    $d1 = 9101;
    $d2 = 9102;
    $c1 = 9111;
    $c2 = 9112;
    $s1 = 9121;
    $s2 = 9122;
    $o1 = 9131;

    $pdo->exec("INSERT INTO domains (id, name, status) VALUES
        ($d1, 'domain-a.com', 'OK'),
        ($d2, 'domain-b.com', 'OK')");
    $pdo->exec("INSERT INTO traffic_sources (id, name, template) VALUES
        ($s1, 'Meta Ads', 'facebook'),
        ($s2, 'TikTok Ads', 'tiktok')");
    $pdo->exec("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES
        ($o1, 'Main Offer', 'https://example.com/offer', 0, 'active', 0)");
    // Campaign A is currently assigned to domain-b.com, even though historical
    // clicks arrived via domain-a.com and without a recorded domain.
    $pdo->exec("INSERT INTO campaigns (id, name, alias, token, state, is_archived, source_id, domain_id) VALUES
        ($c1, 'Camp Alpha', 'camp-alpha', 'tok-alpha', 'active', 0, $s1, $d2),
        ($c2, 'Camp Beta', 'camp-beta', 'tok-beta', 'active', 0, $s2, $d2)");

    $insClick = $pdo->prepare("
        INSERT INTO clicks (id, campaign_id, offer_id, source_id, domain, ip, referer,
            country, country_code, device_type, os, browser, is_bot, is_proxy, pointer_activity,
            uniq_campaign, uniq_stream, uniq_global, is_safe_page, cost, created_at)
        VALUES (:id, :c, :o, :s, :domain, :ip, '', :country, :cc, 'desktop', 'windows', 'chrome',
            :bot, :proxy, 1, :uniq, :uniq, :uniq, 0, :cost, :ts)");
    $mkClick = static function (
        string $id, int $c, int $s, ?string $domain, string $cc, string $country,
        float $cost, string $ts, int $bot = 0, int $proxy = 0, int $uniq = 1
    ) use ($insClick, $o1): void {
        $insClick->execute([
            'id' => $id, 'c' => $c, 'o' => $o1, 's' => $s, 'domain' => $domain,
            'ip' => '203.0.113.' . random_int(1, 250),
            'cc' => $cc, 'country' => $country,
            'bot' => $bot, 'proxy' => $proxy, 'uniq' => $uniq,
            'cost' => $cost, 'ts' => $ts,
        ]);
    };

    // Fresh clicks (in raw window):
    // Campaign A on domain-a.com (including uppercase variant to verify normalization): 3 clicks (1 bot, 1 proxy)
    $mkClick('dom-a1', $c1, $s1, 'domain-a.com', 'US', 'United States', 1.00, $freshTs, 0, 0, 1);
    $mkClick('dom-a2', $c1, $s1, 'DOMAIN-A.COM', 'DE', 'Germany',       0.50, $freshTs, 1, 0, 1);
    $mkClick('dom-a3', $c1, $s1, 'domain-a.com', 'US', 'United States', 0.50, $freshTs, 0, 1, 0);
    // Campaign A after moving to domain-b.com: 2 clicks
    $mkClick('dom-b1', $c1, $s1, 'domain-b.com', 'US', 'United States', 0.40, $freshTs, 0, 0, 1);
    $mkClick('dom-b2', $c1, $s1, 'domain-b.com', 'FR', 'France',        0.60, $freshTs, 0, 1, 1);
    // Campaign B sharing domain-b.com + domain-c.com:
    $mkClick('dom-b3', $c2, $s2, 'domain-b.com', 'DE', 'Germany',       0.50, $freshTs, 0, 0, 1);
    $mkClick('dom-c1', $c2, $s2, 'domain-c.com', 'GB', 'United Kingdom', 0.25, $freshTs, 0, 0, 1);
    // Historical / legacy clicks without a recorded domain -> must group as Unknown (NOT domain-b.com)
    $mkClick('dom-u1', $c1, $s1, null,           'US', 'United States', 0.10, $freshTs, 0, 0, 1);
    $mkClick('dom-u2', $c1, $s1, '',             'US', 'United States', 0.10, $freshTs, 0, 0, 1);
    $mkClick('dom-u3', $c1, $s1, '   ',          'DE', 'Germany',       0.10, $freshTs, 0, 0, 1);

    // Frozen clicks (5 days old, will be folded into stats_place_country):
    $mkClick('dom-old-1', $c1, $s1, 'domain-a.com', 'US', 'United States', 1.00, $oldTs, 0, 0, 1);
    $mkClick('dom-old-2', $c2, $s2, 'domain-b.com', 'DE', 'Germany',       0.50, $oldTs, 0, 0, 1);
    $mkClick('dom-old-3', $c2, $s2, 'domain-c.com', 'FR', 'France',        0.25, $oldTs, 0, 0, 1);

    $insConv = $pdo->prepare("
        INSERT INTO conversions (click_id, tid, status, payout, currency, campaign_id, offer_id, country_code, created_at)
        VALUES (:click, NULL, :status, :payout, 'USD', :c, :o, :cc, :ts)");
    $insConv->execute(['click' => 'dom-a1', 'status' => 'sale', 'payout' => 10.00, 'c' => $c1, 'o' => $o1, 'cc' => 'US', 'ts' => $freshTs]);
    $insConv->execute(['click' => 'dom-b1', 'status' => 'lead', 'payout' => 4.00,  'c' => $c1, 'o' => $o1, 'cc' => 'US', 'ts' => $freshTs]);
    $insConv->execute(['click' => 'dom-old-1', 'status' => 'sale', 'payout' => 8.00, 'c' => $c1, 'o' => $o1, 'cc' => 'US', 'ts' => $oldTs]);

    // Admin API key
    $pdo->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)')
        ->execute(['dom_admin', password_hash('x', PASSWORD_DEFAULT), 'admin']);
    $adminId = (int) $pdo->lastInsertId();
    $adminKey = 'dom_key_' . bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO user_api_keys (user_id, api_key, key_name, permissions) VALUES (?, ?, ?, ?)')
        ->execute([$adminId, $adminKey, 'dom_admin', 'full']);

    $call = static function (string $path) use ($harness, $adminKey): array {
        $resp = $harness->getWithHeaders($path, ['Authorization: Bearer ' . $adminKey]);
        $json = json_decode($resp['body'], true);
        if (!is_array($json)) {
            throw new RuntimeException("Non-JSON on $path: status={$resp['status']} body=" . substr($resp['body'], 0, 300));
        }
        return $json;
    };

    $todayFrom = gmdate('Y-m-d', $now - 86400);
    $todayTo   = gmdate('Y-m-d', $now + 86400);

    // 1. Standalone grouping by domain (raw window)
    $rDomain = $call("/api.php?action=campaign_report&group_by=domain&date_from={$todayFrom}&date_to={$todayTo}");
    check(($rDomain['status'] ?? '') === 'success', 'standalone group_by=domain succeeds');
    $byDomain = [];
    foreach ($rDomain['data']['rows'] ?? [] as $row) {
        $byDomain[$row['dims'][0]] = $row;
    }
    check(isset($byDomain['domain-a.com']) && (int) $byDomain['domain-a.com']['clicks'] === 3,
        'domain-a.com has 3 clicks (case-insensitive merge of domain-a.com + DOMAIN-A.COM)');
    check((int) ($byDomain['domain-a.com']['unique_clicks'] ?? -1) === 2, 'domain-a.com unique_clicks = 2');
    check((int) ($byDomain['domain-a.com']['bots'] ?? -1) === 1, 'domain-a.com bots = 1');
    check((int) ($byDomain['domain-a.com']['proxies'] ?? -1) === 1, 'domain-a.com proxies = 1');
    check((int) ($byDomain['domain-a.com']['sales'] ?? -1) === 1 && abs((float) $byDomain['domain-a.com']['revenue'] - 10.0) < 0.001,
        'domain-a.com sales=1, revenue=10.00');
    check(abs((float) $byDomain['domain-a.com']['cost'] - 2.0) < 0.001 && abs((float) $byDomain['domain-a.com']['profit'] - 8.0) < 0.001,
        'domain-a.com cost=2.00, profit=8.00');
    check(abs((float) $byDomain['domain-a.com']['roi'] - 400.0) < 0.01, 'domain-a.com ROI = 400%');

    check(isset($byDomain['domain-b.com']) && (int) $byDomain['domain-b.com']['clicks'] === 3,
        'domain-b.com has 3 clicks across both campaigns');
    check(isset($byDomain['domain-c.com']) && (int) $byDomain['domain-c.com']['clicks'] === 1,
        'domain-c.com has 1 click');
    check(isset($byDomain['Unknown']) && (int) $byDomain['Unknown']['clicks'] === 3,
        'NULL / empty / whitespace clicks.domain group into Unknown (3 clicks, not current campaign domain)');

    // 2. Historical attribution for a single campaign that moved from domain-a.com to domain-b.com
    $rCampA = $call("/api.php?action=campaign_report&campaign_id={$c1}&group_by=domain&date_from={$todayFrom}&date_to={$todayTo}");
    $campAByDom = [];
    foreach ($rCampA['data']['rows'] ?? [] as $row) {
        $campAByDom[$row['dims'][0]] = (int) $row['clicks'];
    }
    check(($campAByDom['domain-a.com'] ?? 0) === 3 && ($campAByDom['domain-b.com'] ?? 0) === 2 && ($campAByDom['Unknown'] ?? 0) === 3,
        'Campaign A historical attribution splits across domain-a.com (3), domain-b.com (2), Unknown (3)');

    // 3. Multi-level groupings
    foreach ([
        'domain,campaign_id' => ['domain-b.com|Camp Alpha' => 2, 'domain-b.com|Camp Beta' => 1],
        'domain,country'     => ['domain-a.com|US' => 2, 'domain-a.com|DE' => 1],
        'domain,source_id'   => ['domain-b.com|Meta Ads' => 2, 'domain-b.com|TikTok Ads' => 1],
        'campaign_id,domain' => ['Camp Alpha|domain-a.com' => 3, 'Camp Alpha|domain-b.com' => 2],
        'domain,campaign_id,country' => ['domain-b.com|Camp Alpha|US' => 1, 'domain-b.com|Camp Beta|DE' => 1],
    ] as $gb => $expected) {
        $res = $call("/api.php?action=campaign_report&group_by=" . urlencode($gb) . "&date_from={$todayFrom}&date_to={$todayTo}");
        check(($res['status'] ?? '') === 'success', "multi-level group_by={$gb} succeeds");
        $map = [];
        foreach ($res['data']['rows'] ?? [] as $row) {
            $map[implode('|', $row['dims'])] = (int) $row['clicks'];
        }
        $ok = true;
        foreach ($expected as $k => $v) {
            if (($map[$k] ?? -1) !== $v) {
                $ok = false;
                break;
            }
        }
        check($ok, "multi-level group_by={$gb} matches expected breakdown (got " . json_encode($map) . ")");
    }

    // 4. Domain filters (single and multiple)
    $rEq = $call("/api.php?action=campaign_report&group_by=domain&date_from={$todayFrom}&date_to={$todayTo}&filters="
        . urlencode(json_encode([['field' => 'domain', 'op' => 'eq', 'value' => 'DOMAIN-A.COM:443.']])));
    check(count($rEq['data']['rows'] ?? []) === 1 && $rEq['data']['rows'][0]['dims'][0] === 'domain-a.com'
        && (int) $rEq['data']['rows'][0]['clicks'] === 3,
        'domain eq filter normalizes case/port/dot and returns 3 clicks');

    $rContains = $call("/api.php?action=campaign_report&group_by=domain&date_from={$todayFrom}&date_to={$todayTo}&filters="
        . urlencode(json_encode([['field' => 'domain', 'op' => 'contains', 'value' => 'DOMAIN-B']])));
    check(count($rContains['data']['rows'] ?? []) === 1 && $rContains['data']['rows'][0]['dims'][0] === 'domain-b.com',
        'domain contains filter matches case-insensitively');

    $rInCsv = $call("/api.php?action=campaign_report&group_by=domain&date_from={$todayFrom}&date_to={$todayTo}&filters="
        . urlencode(json_encode([['field' => 'domain', 'op' => 'in', 'value' => 'domain-a.com, DOMAIN-C.COM']])));
    $inDoms = array_map(static fn($r) => $r['dims'][0], $rInCsv['data']['rows'] ?? []);
    sort($inDoms);
    check($inDoms === ['domain-a.com', 'domain-c.com'],
        'domain IN filter (comma-separated string) selects multiple domains (got ' . json_encode($inDoms) . ')');

    $rInArr = $call("/api.php?action=campaign_report&group_by=domain&date_from={$todayFrom}&date_to={$todayTo}&filters="
        . urlencode(json_encode([['field' => 'domain', 'op' => 'in', 'value' => ['domain-a.com', 'domain-b.com']]])));
    $inArrDoms = array_map(static fn($r) => $r['dims'][0], $rInArr['data']['rows'] ?? []);
    sort($inArrDoms);
    check($inArrDoms === ['domain-a.com', 'domain-b.com'],
        'domain IN filter (JSON array) selects multiple domains');

    $rNotIn = $call("/api.php?action=campaign_report&group_by=domain&date_from={$todayFrom}&date_to={$todayTo}&filters="
        . urlencode(json_encode([['field' => 'domain', 'op' => 'not_in', 'value' => 'domain-a.com, domain-b.com']])));
    $notInDoms = array_map(static fn($r) => $r['dims'][0], $rNotIn['data']['rows'] ?? []);
    check($notInDoms === ['domain-c.com'],
        'domain NOT IN filter excludes listed domains (got ' . json_encode($notInDoms) . ')');

    // Combined filter: domain IN + country eq
    $rCombo = $call("/api.php?action=campaign_report&group_by=domain&date_from={$todayFrom}&date_to={$todayTo}&filters="
        . urlencode(json_encode([
            ['field' => 'domain', 'op' => 'in', 'value' => 'domain-a.com, domain-b.com'],
            ['field' => 'country', 'op' => 'eq', 'value' => 'US'],
        ])));
    $comboMap = [];
    foreach ($rCombo['data']['rows'] ?? [] as $row) {
        $comboMap[$row['dims'][0]] = (int) $row['clicks'];
    }
    check($comboMap === ['domain-a.com' => 2, 'domain-b.com' => 1],
        'domain IN + country eq combined filter works (got ' . json_encode($comboMap) . ')');

    // 5. Freeze / rollup seam compatibility
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/stats_freeze_cron.php')
        . ' --quiet --budget=10 2>/dev/null');
    $watermark = (int) $pdo->query("SELECT value FROM settings WHERE key='stats_frozen_through_hour'")->fetchColumn();
    check($watermark > 0, 'freeze cron advanced watermark for seam checks');

    // 5a. Period crossing the seam with group_by=domain -> falls back to raw-only with raw_available_from
    $rSeamDom = $call("/api.php?action=campaign_report&group_by=domain&date_from=2000-01-01&date_to=2030-12-31");
    check(($rSeamDom['status'] ?? '') === 'success'
        && !empty($rSeamDom['stats_seam']['raw_available_from'])
        && ($rSeamDom['stats_seam']['reason'] ?? '') === 'dimensions',
        'group_by=domain across freeze seam falls back to raw-only with stats_seam.raw_available_from');

    // 5b. Period crossing the seam with group_by=country + domain filter -> falls back to raw-only (reason=filters)
    $rSeamDomFilter = $call("/api.php?action=campaign_report&group_by=country&date_from=2000-01-01&date_to=2030-12-31&filters="
        . urlencode(json_encode([['field' => 'domain', 'op' => 'in', 'value' => 'domain-a.com, domain-b.com']])));
    check(($rSeamDomFilter['status'] ?? '') === 'success'
        && !empty($rSeamDomFilter['stats_seam']['raw_available_from'])
        && ($rSeamDomFilter['stats_seam']['reason'] ?? '') === 'filters',
        'domain filter on rollup-compatible layer across seam falls back with reason=filters');

    // 5c. Rollup-compatible IN filter (country IN US, DE) crosses seam WITHOUT fallback, summing frozen + raw!
    $rSeamIn = $call("/api.php?action=campaign_report&group_by=country&date_from=2000-01-01&date_to=2030-12-31&filters="
        . urlencode(json_encode([['field' => 'country', 'op' => 'in', 'value' => 'US, DE']])));
    $seamInMap = [];
    foreach ($rSeamIn['data']['rows'] ?? [] as $row) {
        $seamInMap[$row['dims'][0]] = (int) $row['clicks'];
    }
    // Fresh US: dom-a1, dom-a3, dom-b1, dom-u1, dom-u2 (5) + Frozen US: dom-old-1 (1) = 6
    // Fresh DE: dom-a2, dom-b3, dom-u3 (3) + Frozen DE: dom-old-2 (1) = 4
    check(($rSeamIn['status'] ?? '') === 'success'
        && !empty($rSeamIn['stats_seam']['frozen_through'])
        && empty($rSeamIn['stats_seam']['raw_available_from']),
        'country IN filter crosses freeze seam via rollup UNION (no raw-only fallback)');
    check(($seamInMap['US'] ?? 0) === 6 && ($seamInMap['DE'] ?? 0) === 4 && count($seamInMap) === 2,
        'country IN filter across seam sums frozen + raw accurately (got ' . json_encode($seamInMap) . ')');

    // 5d. Rollup-compatible NOT IN filter across seam
    $rSeamNotIn = $call("/api.php?action=campaign_report&group_by=country&date_from=2000-01-01&date_to=2030-12-31&filters="
        . urlencode(json_encode([['field' => 'country', 'op' => 'not_in', 'value' => 'US, DE']])));
    $seamNotInMap = [];
    foreach ($rSeamNotIn['data']['rows'] ?? [] as $row) {
        $seamNotInMap[$row['dims'][0]] = (int) $row['clicks'];
    }
    // Fresh FR (1) + Frozen FR (1) = 2; Fresh GB (1) = 1
    check(($seamNotInMap['FR'] ?? 0) === 2 && ($seamNotInMap['GB'] ?? 0) === 1 && count($seamNotInMap) === 2,
        'country NOT IN filter across seam sums frozen + raw accurately (got ' . json_encode($seamNotInMap) . ')');

    echo "\nDone: $failures failure(s)\n";
    exit($failures > 0 ? 1 : 0);
} finally {
    $harness->stop();
}
