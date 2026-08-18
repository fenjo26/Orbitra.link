<?php
// tests/active_campaign_guard_test.php
//
// The delete guard for landings/offers used by serving campaigns:
//   - orbitraActiveCampaignsUsingEntity() walks streams of ACTIVE, unarchived
//     campaigns (campaigns.state, not a status column — that was the trap in
//     the original spec) and matches every reference shape CampaignEditor
//     writes: streams.offer_id, schema_custom.offers[].id,
//     schema_custom.landings[].id, safe_landing_id, safe_offer_id.
//   - orbitraEntityInUseError() is the shared error payload the four delete
//     handlers (delete/bulk_delete × landing/offer) return instead of
//     archiving.
//
// The functions are extracted from api.php, not copied — api.php cannot be
// required standalone (it is the API switch). Bodies are indented, so "\n}"
// only ends a top-level function.
//
// Run: php tests/active_campaign_guard_test.php

$repoRoot = dirname(__DIR__);
$src = file_get_contents($repoRoot . '/api.php');
foreach (['orbitraActiveCampaignsUsingEntity', 'orbitraEntityInUseError'] as $fn) {
    if (!preg_match('/^function ' . preg_quote($fn, '/') . '\(.*?\n\}/ms', $src, $m)) {
        fwrite(STDERR, "could not extract function {$fn} from api.php\n");
        exit(1);
    }
    eval($m[0] . ';');
}

$assert = function (string $label, $got, $expected) {
    if ($got !== $expected) {
        echo "FAIL $label: got " . var_export($got, true) . ", expected " . var_export($expected, true) . "\n";
        exit(1);
    }
    echo "ok  $label\n";
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE campaigns (id INTEGER PRIMARY KEY, name TEXT, state TEXT DEFAULT \'active\', is_archived INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE streams (id INTEGER PRIMARY KEY, campaign_id INTEGER, offer_id INTEGER, is_active INTEGER DEFAULT 1, schema_custom_json TEXT)');

$mkCampaign = function (int $id, string $name, string $state = 'active', int $archived = 0) use ($pdo) {
    $pdo->prepare('INSERT INTO campaigns (id, name, state, is_archived) VALUES (?, ?, ?, ?)')
        ->execute([$id, $name, $state, $archived]);
};
$mkStream = function (int $id, int $campaignId, ?int $offerId, array $custom = [], int $isActive = 1) use ($pdo) {
    $pdo->prepare('INSERT INTO streams (id, campaign_id, offer_id, is_active, schema_custom_json) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, $campaignId, $offerId, $isActive, $custom ? json_encode($custom) : null]);
};

$mkCampaign(1, 'Sweep Zeytyn', 'active', 0);
$mkCampaign(2, 'Nutra Alpha', 'active', 0);
$mkCampaign(3, 'Paused One', 'disabled', 0);   // paused: must not block
$mkCampaign(4, 'Dead One', 'active', 1);       // archived: must not block
$mkCampaign(5, '', 'active', 0);               // empty name: fallback label

// offer 10 direct on campaign 1 (stream-level offer_id)
$mkStream(101, 1, 10);
// offer 11 in campaign 2 rotation + landing 21; landing 22 as its safe page
$mkStream(102, 2, null, ['offers' => [['id' => 11, 'weight' => 100]], 'landings' => [['id' => 21, 'weight' => 100]], 'safe_landing_id' => 22]);
// offer 12 only as campaign 1's safe-page offer
$mkStream(103, 1, null, ['safe_mode' => 'offer', 'safe_offer_id' => 12]);
// offer 13 on a paused campaign / offer 14 on an archived one / offer 15 on a disabled stream
$mkStream(104, 3, 13);
$mkStream(105, 4, 14);
$mkStream(106, 1, 15, [], 0);
// landing 23 only inside a disabled stream's schema
$mkStream(107, 1, null, ['landings' => [['id' => 23]]], 0);
// campaign 5 (empty name) uses offer 16 via schema
$mkStream(108, 5, null, ['offers' => [['id' => 16]]]);
// malformed schema_custom_json must not fatal the scan
$pdo->prepare('INSERT INTO streams (id, campaign_id, offer_id, is_active, schema_custom_json) VALUES (109, 1, NULL, 1, ?)')
    ->execute(['{"offers": [broken']);

$guard = fn(string $type, array $ids) => orbitraActiveCampaignsUsingEntity($pdo, $type, $ids);

// --- offers -------------------------------------------------------------------
$assert('offer: direct streams.offer_id', $guard('offer', [10]), ['Sweep Zeytyn']);
$assert('offer: schema offers[].id', $guard('offer', [11]), ['Nutra Alpha']);
$assert('offer: safe_offer_id', $guard('offer', [12]), ['Sweep Zeytyn']);
$assert('offer: paused campaign does not block', $guard('offer', [13]), []);
$assert('offer: archived campaign does not block', $guard('offer', [14]), []);
$assert('offer: disabled stream does not block', $guard('offer', [15]), []);
$assert('offer: empty campaign name falls back to #id', $guard('offer', [16]), ['Campaign #5']);
$assert('offer: unknown id', $guard('offer', [999]), []);
$assert('offer: mixed batch reports every serving campaign, unique', $guard('offer', [10, 11, 12]), ['Sweep Zeytyn', 'Nutra Alpha']);
$assert('offer: mixed batch with one free id still blocks', $guard('offer', [999, 11]), ['Nutra Alpha']);
$assert('offer: empty id list', $guard('offer', []), []);

// --- landings -----------------------------------------------------------------
$assert('landing: schema landings[].id', $guard('landing', [21]), ['Nutra Alpha']);
$assert('landing: safe_landing_id', $guard('landing', [22]), ['Nutra Alpha']);
$assert('landing: disabled stream does not block', $guard('landing', [23]), []);
$assert('landing: stream offer_id must not match a landing id', $guard('landing', [10]), []);
$assert('landing: schema offers[].id must not match a landing id', $guard('landing', [11]), []);
$assert('landing: safe_offer_id must not match a landing id', $guard('landing', [12]), []);

// --- error payload --------------------------------------------------------------
$payload = orbitraEntityInUseError('offer', ['Nutra Alpha']);
$assert('error: code', $payload['code'], 'entity_in_use');
$assert('error: status', $payload['status'], 'error');
$assert('error: campaigns passthrough', $payload['campaigns'], ['Nutra Alpha']);
$assert('error: message names the campaign', strpos($payload['message'], '"Nutra Alpha"') !== false, true);
$assert('error: message tells how to unblock', strpos($payload['message'], 'archive the campaign') !== false, true);

echo "active_campaign_guard: all scenarios passed\n";
