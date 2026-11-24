<?php
// tests/simulator_parity_test.php
//
// The traffic simulator (api.php simulate_traffic — "Run simulation" in the
// campaign editor and the Traffic simulation page) must answer what the live
// router (index.php) does for the same visitor.
//
// Field report (2026-11-24): a cloak intercepting stream with "Block (not in
// list): US, GB, NL, FI" and a provider on the Bot ISP list. The simulator said
// "clean visitor — passes them on to the next stream" for a US visitor and for
// the listed provider, i.e. "US goes further down the list" — while the router
// sent both to the Safe Page. The simulator carried a private, shortened copy
// of the routing: it ran the passive detector only (never the cloak card's
// targeting rules, never gate mode) and treated OS / Browser / IP / ISP /
// Keyword / Referer / Weekday / Time filters as always matched.
//
// Both now run the same code (core/stream_match.php + orbitraCloakDecision()).
// This test drives the real router and the real simulator through HTTP on the
// same sandbox database and compares the verdicts.
//
// Run: php tests/simulator_parity_test.php

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

/**
 * What the router did with the visitor, in the simulator's vocabulary:
 *   safe             the Safe Page was served
 *   stream:<name>    which stream was selected (redirect / action)
 *   none             no stream matched
 */
function liveVerdict(string $body, string $location, int $status): string
{
    if ($body !== '' && str_contains($body, 'Safe page content')) {
        return 'safe';
    }
    if (str_contains($location, '/landing')) {
        return 'stream:CLOAK_MONEY';
    }
    if (str_contains($location, '/money')) {
        return 'stream:Redirect Stream';
    }
    if (str_contains($body, 'hello-fallback')) {
        return 'stream:Bots';
    }
    // The router's answer when no stream is left ("Do nothing.").
    if (trim($body) === 'Do nothing.' || $status === 404) {
        return 'none';
    }
    return 'unknown(' . $status . '): ' . substr(preg_replace('/\s+/', ' ', strip_tags($body)), 0, 60);
}

/** The simulator's verdict from its trace, in the same vocabulary. */
function simVerdict(array $trace): string
{
    $selected = null;
    foreach ($trace as $line) {
        if (preg_match('/^=> (?:MATCHED \w+ Stream|Selected by (?:position|weight)(?: \(rand=\d+\))?|Weights are 0, picking first): (.+)$/', $line, $m)) {
            $selected = $m[1];
        }
    }
    foreach ($trace as $line) {
        if (preg_match('/^Cloak result: SAFE page \((\w+)\)\.$/', $line, $m)) {
            return 'safe:' . $m[1];
        }
        if (str_starts_with($line, 'Cloak result: SAFE page (JS challenge')) {
            return 'safe:js_challenge';
        }
        if (str_starts_with($line, 'Cloak result: MONEY page')) {
            return 'stream:CLOAK_MONEY';
        }
    }
    if ($selected === null) {
        return 'none';
    }
    return 'stream:' . $selected;
}

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();
$desktop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

try {
    $harness->start();
    $data = $harness->seedCloakTestData();
    $pdo = $harness->getPdo();
    $cid = $data['campaign_id'];
    $cloakId = $data['cloak_stream_id'];
    $redirectId = $data['redirect_stream_id'];
    $offerId = $data['offer_id'];

    // With router.php a host only reaches the campaign path when it is a
    // tracking domain of this install.
    $pdo->exec("INSERT INTO domains (name, is_noindex, https_only, index_campaign_id, catch_404, status) VALUES ('127.0.0.1', 0, 0, NULL, 0, 'OK')");

    // An admin session for the simulator endpoint.
    $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES ('sim_admin', ?, 'admin', 1, '{}')")
        ->execute([password_hash('sim_pass_123', PASSWORD_DEFAULT)]);
    $resp = $harness->postWithHeaders('/api.php?action=login',
        json_encode(['username' => 'sim_admin', 'password' => 'sim_pass_123']), ['Content-Type: application/json']);
    $login = json_decode($resp['body'], true);
    if (($login['status'] ?? '') !== 'success') {
        throw new RuntimeException('login failed: ' . $resp['body']);
    }
    preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $m);
    $auth = ['Cookie: ORBITRASESSID=' . $m[1], 'X-CSRF-TOKEN: ' . ($login['data']['csrf_token'] ?? ''), 'Content-Type: application/json'];

    // Destinations a verdict can be read from.
    $pdo->prepare("UPDATE offers SET url = 'https://example.com/money' WHERE id = ?")->execute([$offerId]);
    $pdo->prepare("UPDATE landings SET url = 'https://example.com/landing' WHERE id = ?")->execute([$data['landing_id']]);

    $base = $harness->getBaseUrl();
    $sig = $cid . ':' . substr(hash_hmac('sha256', 'test-link|' . $cid . '|cloakcamp', 'testkey42'), 0, 32);
    $ipSeq = 0;

    $live = static function (string $country, string $userAgent) use ($base, $cid, $sig, &$ipSeq): string {
        $ipSeq++;
        // A signed test link: the full routing pipeline, nothing written.
        [$s, $loc, $body] = fetchNoFollow($base . '/cloakcamp?_t=' . $sig . '&_geo=' . $country, [
            'User-Agent: ' . $userAgent,
            'X-Forwarded-For: 73.1.2.' . $ipSeq,
            'Accept-Language: en-US,en;q=0.9',
        ]);
        return liveVerdict($body, $loc, $s);
    };
    $sim = static function (array $extra) use ($harness, $auth, $cid, $desktop): array {
        $resp = $harness->postWithHeaders('/api.php?action=simulate_traffic', json_encode(array_merge([
            'campaign_id' => $cid,
            'ip' => '73.1.2.3',
            'user_agent' => $desktop,
            'device_type' => 'desktop',
            'accept_language' => 'en-US,en;q=0.9',
            'asn' => 'AS7922',
            'isp' => 'Comcast Cable',
            'js_executed' => true,
            'webdriver' => false,
        ], $extra)), $auth);
        $j = json_decode($resp['body'], true);
        return is_array($j['trace'] ?? null) ? $j['trace'] : ['no trace: ' . substr($resp['body'], 0, 200)];
    };
    /** Both verdicts, or a readable failure when they differ. */
    $parity = static function (string $label, string $country, array $extra = []) use ($live, $sim, $desktop): bool {
        $l = $live($country, (string) ($extra['user_agent'] ?? $desktop));
        $trace = $sim(array_merge(['country' => $country], $extra));
        $s = simVerdict($trace);
        $same = ($l === 'safe') ? str_starts_with($s, 'safe:') : $l === $s;
        echo ($same ? '✓ ' : "FAILED: ") . "$label — router: {$l}; simulator: {$s}\n";
        if (!$same) {
            foreach ($trace as $line) {
                fwrite(STDERR, "    | {$line}\n");
            }
        }
        return $same;
    };

    $setCloak = static function (array $patch, bool $gate) use ($pdo, $cloakId): void {
        $schema = json_decode($pdo->query("SELECT schema_custom_json FROM streams WHERE id = " . (int) $cloakId)->fetchColumn(), true);
        // Every suite starts from a card without targeting rules of its own.
        unset($schema['devices'], $schema['device_mode'], $schema['countries'], $schema['geo_mode'], $schema['block_bot_isps'], $schema['custom_bot_isps'], $schema['js_challenge']);
        $schema = array_merge($schema, $patch, ['pass_clean_to_next' => $gate]);
        $pdo->prepare("UPDATE streams SET schema_custom_json = ? WHERE id = ?")->execute([json_encode($schema), $cloakId]);
    };
    $clean = ['detect_datacenter' => true, 'detect_vpn' => true, 'detect_bots' => true, 'detect_ua' => true, 'sensitivity' => 'medium'];

    // ---------------------------------------------------------------- suites
    $pdo->prepare("UPDATE streams SET type = 'intercepting', position = 0 WHERE id = ?")->execute([$cloakId]);
    $pdo->prepare("UPDATE streams SET position = 1, filters_json = '[]', filters_logic = 'and' WHERE id = ?")->execute([$redirectId]);

    // 1. The reported setup: gate on, listed countries go to the Safe Page.
    $setCloak($clean + ['countries' => 'US,GB,NL,FI', 'geo_mode' => 'deny', 'block_bot_isps' => true, 'custom_bot_isps' => 'B2 Net Solutions'], true);
    $ok = $parity('gate on, block-list US: a US visitor sees the Safe Page', 'US') && $ok;
    $ok = $parity('gate on, block-list US: a DE visitor passes on to the stream below', 'DE') && $ok;

    // The listed provider: the sandbox has no geo database, so the router
    // cannot be handed an ISP — the simulator alone, against the same rule.
    $trace = $sim(['country' => 'DE', 'asn' => 'AS36352', 'isp' => 'B2 Net Solutions Inc.']);
    check(simVerdict($trace) === 'safe:targeting_safe'
        && in_array('Cloak strictness: medium; signals: [bot_isp:B2 Net Solutions]', $trace, true),
        'gate on: a provider from the Bot ISP list sees the Safe Page (bot_isp)');

    // 2. Classic cloak (no gate): its own money page for the others.
    $setCloak($clean + ['countries' => 'US,GB,NL,FI', 'geo_mode' => 'deny'], false);
    $ok = $parity('gate off, block-list US: a US visitor sees the Safe Page', 'US') && $ok;
    $ok = $parity('gate off, block-list US: a DE visitor gets the cloak stream\'s money page', 'DE') && $ok;

    // 3. Allow-list is the mirror image — the mode the radio label warns about.
    $setCloak($clean + ['countries' => 'DE,FR', 'geo_mode' => 'allow'], false);
    $ok = $parity('allow-list DE,FR: a DE visitor gets the money page', 'DE') && $ok;
    $ok = $parity('allow-list DE,FR: a US visitor sees the Safe Page', 'US') && $ok;

    // 4. Device rule of the cloak card.
    $setCloak($clean + ['devices' => 'mobile,tablet', 'device_mode' => 'allow'], false);
    $ok = $parity('allow mobile+tablet only: a desktop visitor sees the Safe Page', 'DE') && $ok;

    // 5. Passive detector, both sides: a crawler User-Agent is a hard signal.
    $setCloak($clean, false);
    $crawler = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    $ok = $parity('crawler User-Agent on medium: Safe Page', 'DE', ['user_agent' => $crawler]) && $ok;
    // A datacenter ASN the router cannot be handed here (no geo database in the
    // sandbox): the simulator alone, against the same ASN blocklist.
    $trace = $sim(['country' => 'DE', 'asn' => 'AS16276', 'isp' => 'OVH SAS']);
    check(in_array('Cloak strictness: medium; signals: [datacenter_asn:AS16276]', $trace, true)
        && simVerdict($trace) === 'safe:passive_safe',
        'datacenter ASN on medium: Safe Page (datacenter_asn)');

    // 6. Ordinary stream filters on a plain stream. The old simulator treated
    //    OS, IP and Weekday as always matched. The intercepting cloak stream is
    //    switched off, so only the plain and the fallback stream are in play.
    $pdo->prepare("UPDATE streams SET is_active = 0 WHERE id = ?")->execute([$cloakId]);
    $pdo->prepare("UPDATE streams SET filters_json = ? WHERE id = ?")
        ->execute([json_encode([['name' => 'OS', 'mode' => 'include', 'payload' => ['iOS']]]), $redirectId]);
    $ok = $parity('regular stream with "OS iOS": a Windows visitor matches no stream', 'DE') && $ok;
    $pdo->prepare("UPDATE streams SET filters_json = ? WHERE id = ?")
        ->execute([json_encode([['name' => 'IP', 'mode' => 'include', 'payload' => ['10.0.0.*']]]), $redirectId]);
    $ok = $parity('regular stream with "IP 10.0.0.*": another address matches no stream', 'DE') && $ok;
    $pdo->prepare("UPDATE streams SET filters_json = ? WHERE id = ?")
        ->execute([json_encode([['name' => 'Country', 'mode' => 'exclude', 'payload' => ['US', 'DE']]]), $redirectId]);
    $ok = $parity('regular stream with "Country exclude US,DE": DE matches no stream', 'DE') && $ok;
    $ok = $parity('regular stream with "Country exclude US,DE": FR matches it', 'FR') && $ok;
    $pdo->prepare("UPDATE streams SET filters_json = '[]' WHERE id = ?")->execute([$redirectId]);

    // 7. The fallback stream's own filters decide, exactly as in the router.
    $pdo->exec("INSERT INTO streams (campaign_id, offer_id, name, type, position, schema_type, action_payload, schema_custom_json, is_active, collect_clicks)
                VALUES ({$cid}, NULL, 'Bots', 'fallback', 1000001, 'action', 'show_text:hello-fallback', '[]', 1, 1)");
    $fallbackId = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE streams SET filters_json = ? WHERE id = ?")
        ->execute([json_encode([['name' => 'Country', 'mode' => 'include', 'payload' => ['FR']]]), $redirectId]);
    $ok = $parity('fallback without filters catches a visitor the plain stream does not want', 'DE') && $ok;
    $pdo->prepare("UPDATE streams SET filters_json = ? WHERE id = ?")
        ->execute([json_encode([['name' => 'Country', 'mode' => 'include', 'payload' => ['US']]]), $fallbackId]);
    $ok = $parity('fallback limited to US: a DE visitor matches no stream', 'DE') && $ok;
    $ok = $parity('fallback limited to US: a US visitor gets it', 'US') && $ok;
    $ok = $parity('plain stream for FR still comes before the fallback', 'FR') && $ok;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    $ok = false;
} finally {
    $harness->stop();
}

exit($ok ? 0 : 1);
