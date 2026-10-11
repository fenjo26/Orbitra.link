<?php
// tests/cloak_gate_pass_through_http_test.php
//
// Cloak "gate" mode (pass_clean_to_next) through the real redirect router:
// a suspicious visitor gets the gate's safe page, a clean one is handed to
// the streams below — and those streams' own filters (here the region-aware
// Language filter) still decide where they land.
//
// Run: php tests/cloak_gate_pass_through_http_test.php

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
        'timeout' => 5,
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

$harness = new OrbitraTestHarness(dirname(__DIR__));
$iphone = 'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1';
$desktop = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$googlebot = 'User-Agent: Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

try {
    $harness->start();
    $data = $harness->seedCloakTestData();
    $pdo = $harness->getPdo();
    $cid = $data['campaign_id'];
    $base = $harness->getBaseUrl() . '/?campaign_id=' . $cid;

    // Two destinations below the gate.
    $tier1Offer = random_int(100000, 199999);
    $otherOffer = random_int(200000, 299999);
    $ins = $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, ?, ?, 0, 'active', 0)");
    $ins->execute([$tier1Offer, 'Tier1', 'https://example.com/tier1']);
    $ins->execute([$otherOffer, 'Other', 'https://example.com/other']);

    // Gate: the seeded cloak stream, money page emptied, pass-through on.
    $schema = json_decode($pdo->query("SELECT schema_custom_json FROM streams WHERE id = " . (int) $data['cloak_stream_id'])->fetchColumn(), true);
    $schema['pass_clean_to_next'] = true;
    $schema['offers'] = [];
    $schema['landings'] = [];
    $pdo->prepare("UPDATE streams SET schema_custom_json = ?, offer_id = NULL WHERE id = ?")
        ->execute([json_encode($schema), $data['cloak_stream_id']]);

    // Stream 2: Tier-1 English, en-IN excluded. Stream 3: everyone else.
    $pdo->prepare("UPDATE streams SET offer_id = ?, schema_custom_json = ?, filters_json = ? WHERE id = ?")->execute([
        $tier1Offer,
        json_encode(['offers' => [['id' => $tier1Offer, 'weight' => 100]]]),
        json_encode([['name' => 'Language', 'mode' => 'exclude', 'payload' => ['en-IN']]]),
        $data['redirect_stream_id'],
    ]);
    $pdo->prepare("INSERT INTO streams (campaign_id, offer_id, name, type, position, schema_type, schema_custom_json, is_active, collect_clicks)
                   VALUES (?, ?, 'Other', 'regular', 3, 'redirect', ?, 1, 1)")
        ->execute([$cid, $otherOffer, json_encode(['offers' => [['id' => $otherOffer, 'weight' => 100]]])]);

    [$s, $loc] = fetchNoFollow($base, [$iphone, 'X-Forwarded-For: 8.8.4.4', 'Accept-Language: en-US,en;q=0.9']);
    check($s >= 300 && $s < 400 && str_contains($loc, '/tier1'), "clean en-US visitor passes the gate to the Tier-1 stream ($s $loc)");

    usleep(2100000); // debounce window
    [$s, $loc] = fetchNoFollow($base, [$iphone, 'X-Forwarded-For: 8.8.4.5', 'Accept-Language: en-US,en-IN;q=0.5']);
    check($s >= 300 && $s < 400 && str_contains($loc, '/other'), "en-IN anywhere in the list keeps the visitor out of Tier-1 ($s $loc)");

    usleep(2100000);
    [$s, $loc, $body] = fetchNoFollow($base, [$googlebot, 'X-Forwarded-For: 8.8.4.6', 'Accept-Language: en-US']);
    check($loc === '' && str_contains($body, 'Safe page content'), "a bot is caught by the gate and gets its safe page ($s)");

    usleep(2100000);
    [$s, $loc, $body] = fetchNoFollow($base, [$desktop, 'X-Forwarded-For: 8.8.4.7', 'Accept-Language: en-US']);
    check($loc === '' && str_contains($body, 'Safe page content'), "the gate's own device targeting still sends desktop to the safe page ($s)");

    // Gate off: the old behaviour — the cloak stream serves its own money page.
    $schema['pass_clean_to_next'] = false;
    $schema['offers'] = [['id' => $data['offer_id'], 'weight' => 100]];
    $pdo->prepare("UPDATE streams SET schema_custom_json = ? WHERE id = ?")->execute([json_encode($schema), $data['cloak_stream_id']]);
    usleep(2100000);
    [$s, $loc] = fetchNoFollow($base, [$iphone, 'X-Forwarded-For: 8.8.4.8', 'Accept-Language: en-US']);
    check(str_contains($loc, '/money') || str_contains($loc, '/landing'), "with the box unticked the cloak keeps the visitor on its money page ($s $loc)");
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    $ok = false;
} finally {
    $harness->stop();
}

exit($ok ? 0 : 1);
