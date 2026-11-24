<?php
// tests/click_php_stream_selection_test.php
//
// click.php — the click handler behind the integration snippets (banners,
// WordPress shortcodes, the exit-intent popup link) — picks the stream the way
// the redirect router (index.php) does.
//
// It used to take the first stream by position, or a weighted pick over every
// stream whatever its type, and never looked at the filters: every visitor of
// a campaign saw its first stream. An intercepting "404 for bots" stream in
// front answered "200 OK, no redirect" to everybody, a country stream took the
// whole world, and a stream action or a Direct URL stream was never served.
//
// The same visitors go through both entry points on one sandbox database; the
// answers must agree, and must be the ones the configuration asks for.
//
// The harness runs with the production router (router.php) on purpose: with
// index.php as the router script, a request to /click.php is answered by
// index.php itself, and a test would never reach click.php at all. Under
// router.php the two entry points are reached through two hosts: the router
// as a tracking domain (Host: track.test, path /<alias>), click.php on the
// panel's own host — on a tracking domain router.php reads every one-segment
// path as a campaign alias, /click.php included.
//
// Run: php tests/click_php_stream_selection_test.php

require_once __DIR__ . '/lib/http.php';

$ok = true;
function check(bool $cond, string $msg): void
{
    global $ok;
    if ($cond) {
        echo "✓ $msg\n";
    } else {
        fwrite(STDERR, "FAILED: $msg\n");
        $ok = false;
    }
}

/** GET without following redirects: [status, Location, body]. */
function fetchNoFollow(string $url, array $headers): array
{
    $ctx = stream_context_create(['http' => [
        'timeout' => 10,
        'ignore_errors' => true,
        'follow_location' => 0,
        'header' => implode("\r\n", $headers),
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    $location = '';
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        } elseif (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        }
    }
    return [$status, $location, (string) $body];
}

/** Where the visitor ended up: a destination name, or what came back. */
function outcome(array $r): string
{
    [$s, $loc, $body] = $r;
    if ($loc !== '') {
        if (str_contains($loc, '/eu-offer')) return 'EU offer';
        if (str_contains($loc, '/us-offer')) return 'US offer';
        if (str_starts_with($loc, 'https://example.org/go?c=') && str_ends_with($loc, '&g=CA')) return 'Direct URL';
        return 'redirect ' . preg_replace('/[?].*/', '', preg_replace('#^https?://[^/]+#', '', $loc));
    }
    if ($s === 404) return '404';
    if (str_contains($body, 'GATE-SAFE')) return 'Safe Page';
    if (trim($body) === 'FALLBACK-TEXT') return 'fallback text';
    return 'other(' . $s . '): ' . substr(preg_replace('/\s+/', ' ', strip_tags($body)), 0, 60);
}

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();
$desktop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$curl = 'curl/8.4.0';

try {
    $harness->start();
    $data = $harness->seedCloakTestData();
    $pdo = $harness->getPdo();
    $cid = $data['campaign_id'];
    $token = $data['campaign_token'];
    $base = $harness->getBaseUrl();

    // The router's host: a tracking domain of this install.
    $pdo->exec("INSERT INTO domains (name, is_noindex, https_only, index_campaign_id, catch_404, status) VALUES ('track.test', 0, 0, NULL, 0, 'OK')");

    // Every click its own row: the debounce window would otherwise fold the pairs below.
    $harness->setSetting('click_debounce_seconds', '0');

    $offerEu = random_int(300000, 399999);
    $offerUs = random_int(400000, 499999);
    $insOffer = $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, ?, ?, 0, 'active', 0)");
    $insOffer->execute([$offerEu, 'EU offer', 'https://example.com/eu-offer']);
    $insOffer->execute([$offerUs, 'US offer', 'https://example.com/us-offer']);

    $pdo->prepare("DELETE FROM streams WHERE campaign_id = ?")->execute([$cid]);
    $ins = $pdo->prepare("INSERT INTO streams (campaign_id, offer_id, name, type, position, schema_type, action_payload, schema_custom_json, filters_json, filters_logic, is_active, collect_clicks)
                          VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 'and', 1, 1)");
    $botFilter = json_encode([['name' => 'Bot', 'mode' => 'include', 'payload' => ['yes'], 'sensitivity' => 'high']]);

    // [0] bots get a 404 — the interceptor of a typical Keitaro import.
    $ins->execute([$cid, 'Antibot', 'intercepting', 0, 'action', 'not_found', '[]', $botFilter]);
    $antibotId = (int) $pdo->lastInsertId();
    // [1] a cloak gate: the listed countries see the Safe Page, the rest go on.
    $ins->execute([$cid, 'Gate', 'intercepting', 1, 'cloak', '', json_encode([
        'pass_clean_to_next' => true, 'countries' => 'US,GB', 'geo_mode' => 'deny',
        'safe_mode' => 'html', 'safe_html' => '<html><body><p>GATE-SAFE</p></body></html>',
        'detect_datacenter' => false, 'detect_vpn' => false, 'detect_bots' => false, 'detect_ua' => false, 'sensitivity' => 'low',
        'block_bot_isps' => false, 'log_safe_clicks' => true,
    ]), '[]']);
    $gateId = (int) $pdo->lastInsertId();
    // [2..4] ordinary streams, each with its own filter.
    $ins->execute([$cid, 'EU', 'regular', 2, 'redirect', '', json_encode(['offers' => [['id' => $offerEu, 'weight' => 100]]]),
        json_encode([['name' => 'Country', 'mode' => 'include', 'payload' => ['DE', 'FR']]])]);
    $euId = (int) $pdo->lastInsertId();
    $ins->execute([$cid, 'US', 'regular', 3, 'redirect', '', json_encode(['offers' => [['id' => $offerUs, 'weight' => 100]]]),
        json_encode([['name' => 'Country', 'mode' => 'include', 'payload' => ['US']]])]);
    $usId = (int) $pdo->lastInsertId();
    $ins->execute([$cid, 'Direct', 'regular', 4, 'redirect', '', json_encode(['redirect_mode' => 'direct_url', 'direct_url' => 'https://example.org/go?c={clickid}&g={country}']),
        json_encode([['name' => 'Country', 'mode' => 'include', 'payload' => ['CA']]])]);
    // the last resort.
    $ins->execute([$cid, 'Bots', 'fallback', 1000001, 'action', 'show_text:FALLBACK-TEXT', '[]', '[]']);

    $sig = $cid . ':' . substr(hash_hmac('sha256', 'test-link|' . $cid . '|cloakcamp', 'testkey42'), 0, 32);
    $ipSeq = 0;

    /** A visitor through one entry point. $entry: 'router' | 'click.php'. */
    $visit = static function (string $entry, string $ua, string $geo = '', ?string $ip = null) use ($base, $cid, $token, $sig, &$ipSeq): array {
        $ipSeq++;
        $ip = $ip ?? '73.1.2.' . $ipSeq;
        $test = $geo === '' ? '' : '&_t=' . $sig . '&_geo=' . $geo;
        $url = $entry === 'router'
            ? $base . '/cloakcamp' . ($test !== '' ? '?' . substr($test, 1) : '')
            : $base . '/click.php?campaign_id=' . $cid . '&token=' . urlencode((string) $token) . $test;
        $headers = ['User-Agent: ' . $ua, 'X-Forwarded-For: ' . $ip, 'Accept-Language: en-US,en;q=0.9'];
        if ($entry === 'router') {
            $headers[] = 'Host: track.test';
        }
        return fetchNoFollow($url, $headers);
    };
    $same = static function (string $label, string $ua, string $geo, string $expected) use ($visit): bool {
        $router = outcome($visit('router', $ua, $geo));
        $click = outcome($visit('click.php', $ua, $geo));
        $good = $router === $expected && $click === $expected;
        echo ($good ? '✓ ' : 'FAILED: ') . "{$label} — router: {$router}; click.php: {$click}\n";
        return $good;
    };

    // The guard for the trap above: each entry point must answer with its own
    // file. click.php logs nothing for a test click, the router neither — but
    // only click.php answers a request without a campaign with its own error.
    $noCampaign = fetchNoFollow($base . '/click.php', ['Host: 127.0.0.1']);
    check($noCampaign[0] === 404 && str_contains($noCampaign[2], 'Campaign not found'),
        'click.php is reached as click.php (its own "Campaign not found") — got ' . outcome($noCampaign));

    // --- the same visitor, both entry points, the configured answer ---------------
    $ok = $same('a DE visitor reaches the EU stream', $desktop, 'DE', 'EU offer') && $ok;
    $ok = $same('an FR visitor reaches the EU stream too', $desktop, 'FR', 'EU offer') && $ok;
    $ok = $same('a country the gate lists sees its Safe Page (US)', $desktop, 'US', 'Safe Page') && $ok;
    $ok = $same('a country the gate lists sees its Safe Page (GB)', $desktop, 'GB', 'Safe Page') && $ok;
    $ok = $same('curl gets the interceptor\'s 404, before the gate (US)', $curl, 'US', '404') && $ok;
    $ok = $same('curl gets the interceptor\'s 404, before the gate (DE)', $curl, 'DE', '404') && $ok;
    $ok = $same('a country no regular stream wants gets the fallback action', $desktop, 'BR', 'fallback text') && $ok;
    $ok = $same('a Direct URL stream is served, with its macros substituted', $desktop, 'CA', 'Direct URL') && $ok;

    // --- rotation by weight applies to the regular streams alone -------------------
    $pdo->prepare("UPDATE campaigns SET rotation_type = 'weight' WHERE id = ?")->execute([$cid]);
    $pdo->prepare("UPDATE streams SET filters_json = '[]', weight = ? WHERE id = ?")->execute([100, $euId]);
    $pdo->prepare("UPDATE streams SET filters_json = '[]', weight = ? WHERE id = ?")->execute([0, $usId]);
    // The interceptor (a 404) and the fallback are in the table too: a weighted
    // pick over every stream used to be able to choose them for a clean visitor.
    $ok = $same('weight rotation: the stream with the weight wins, over interceptor and fallback', $desktop, 'JP', 'EU offer') && $ok;
    $pdo->prepare("UPDATE campaigns SET rotation_type = 'position' WHERE id = ?")->execute([$cid]);
    $pdo->prepare("UPDATE streams SET filters_json = ? WHERE id = ?")->execute([json_encode([['name' => 'Country', 'mode' => 'include', 'payload' => ['DE', 'FR']]]), $euId]);
    $pdo->prepare("UPDATE streams SET filters_json = ? WHERE id = ?")->execute([json_encode([['name' => 'Country', 'mode' => 'include', 'payload' => ['US']]]), $usId]);

    // --- ordinary clicks are logged under the stream that took them ----------------
    // No test link here: a test click is never logged. The sandbox has no geo
    // database, so the country filters abstain for these clicks.
    $r1 = $visit('click.php', $desktop, '', '73.9.9.9');
    $r2 = $visit('click.php', $desktop, '', '73.9.9.9');
    $rows = $pdo->query("SELECT stream_id, offer_id, uniq_campaign FROM clicks WHERE campaign_id = {$cid} AND ip = '73.9.9.9' ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    check(outcome($r1) === 'EU offer' && outcome($r2) === 'EU offer' && count($rows) === 2
        && (int) $rows[0]['stream_id'] === $euId && (int) $rows[0]['offer_id'] === $offerEu
        && (int) $rows[0]['uniq_campaign'] === 1 && (int) $rows[1]['uniq_campaign'] === 0,
        'a visitor click.php sends to the EU stream is logged under it, and the second visit of the same visitor is not unique');

    $r = $visit('click.php', $curl, '', '73.9.9.10');
    $bot = $pdo->query("SELECT stream_id, is_safe_page, cloak_verdict, cloak_reasons FROM clicks WHERE campaign_id = {$cid} AND ip = '73.9.9.10' ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check($r[0] === 404 && (int) $bot['stream_id'] === $antibotId && (int) $bot['is_safe_page'] === 1
        && $bot['cloak_verdict'] === 'bot_filter' && str_contains((string) $bot['cloak_reasons'], 'crawler_or_tool_ua'),
        'a bot caught by click.php is logged under the interceptor with the reason (bot_filter)');

    $r = $visit('click.php', $desktop, '', '73.9.9.11');
    $human = $pdo->query("SELECT stream_id, is_safe_page, cloak_verdict FROM clicks WHERE campaign_id = {$cid} AND ip = '73.9.9.11' ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check(outcome($r) === 'EU offer' && (int) $human['stream_id'] === $euId && (int) $human['is_safe_page'] === 0 && $human['cloak_verdict'] === null,
        'a clean visitor is logged under the stream that took them, not as a bot');
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    $ok = false;
} finally {
    $harness->stop();
}

exit($ok ? 0 : 1);
