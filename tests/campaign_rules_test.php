<?php
// tests/campaign_rules_test.php
//
// Campaign rules (core/CampaignRules.php) end to end on a throwaway SQLite DB:
// normalisation, the pure matcher, report-parity metrics (safe-page traffic
// excluded), the three actions, the "never the last enabled item" guard,
// cooldown, the check interval, and the Telegram message per chat language.
// Also the rotation optimiser's "weights moved" message and its 1-hour throttle.
//
// Run: php tests/campaign_rules_test.php

require_once __DIR__ . '/../core/ReportMetrics.php';
require_once __DIR__ . '/../core/RotationOptimiser.php';
require_once __DIR__ . '/../core/CampaignRules.php';
require_once __DIR__ . '/../core/AutomationNotify.php';

$failures = 0;
$assert = function (string $label, $cond, string $detail = '') use (&$failures) {
    if ($cond) {
        echo "  ok  $label\n";
    } else {
        $failures++;
        echo "FAIL  $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
};

$tmpDb = sys_get_temp_dir() . '/orbitra_campaign_rules_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$pdo = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE campaigns (id INTEGER PRIMARY KEY, name TEXT, alias TEXT,
    cost_model TEXT DEFAULT \'CPC\', cost_value REAL DEFAULT 0,
    state TEXT DEFAULT \'active\', is_archived INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE streams (id INTEGER PRIMARY KEY, campaign_id INTEGER, offer_id INTEGER,
    name TEXT, weight INTEGER DEFAULT 100, is_active INTEGER DEFAULT 1, type TEXT DEFAULT \'regular\',
    position INTEGER DEFAULT 0, schema_type TEXT DEFAULT \'redirect\', schema_custom_json TEXT)');
$pdo->exec('CREATE TABLE offers (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE landings (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE clicks (id TEXT PRIMARY KEY, campaign_id INTEGER, stream_id INTEGER,
    landing_id INTEGER, offer_id INTEGER, cost REAL DEFAULT 0, is_safe_page INTEGER DEFAULT 0, created_at TEXT)');
$pdo->exec('CREATE TABLE conversions (id INTEGER PRIMARY KEY AUTOINCREMENT, click_id TEXT, status TEXT, payout REAL DEFAULT 0)');
$pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)');
$pdo->exec('CREATE TABLE telegram_bot_chats (id INTEGER PRIMARY KEY, chat_id TEXT, language TEXT,
    notify_conversions INTEGER DEFAULT 1, is_active INTEGER DEFAULT 1)');
$pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT, resource TEXT,
    resource_id INTEGER, context TEXT, ip TEXT, user_agent TEXT, status_code INTEGER)');
$pdo->exec('CREATE TABLE stream_rotation_log (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER NOT NULL,
    rotation_key TEXT NOT NULL, stream_id INTEGER, stream_name TEXT, list_type TEXT NOT NULL, item_id INTEGER NOT NULL,
    item_name TEXT, old_weight INTEGER NOT NULL, new_weight INTEGER NOT NULL, metric TEXT NOT NULL, metric_value REAL,
    sample_size INTEGER NOT NULL DEFAULT 0, window_from TEXT NOT NULL, window_to TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');

// The migration helper lives in config.php; pull just that function out so
// the test exercises the real DDL without booting the app.
$cfg = file_get_contents(__DIR__ . '/../config.php');
$start = strpos($cfg, "if (!function_exists('orbitraEnsureCampaignRuleTables'))");
$end = strpos($cfg, '$LATEST_SCHEMA_VERSION =', $start);
eval(substr($cfg, $start, $end - $start));
orbitraEnsureCampaignRuleTables($pdo);
orbitraEnsureCampaignRuleTables($pdo); // idempotent
$assert('migration DDL creates both tables', (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE name='campaign_rule_log'")->fetchColumn());

$pdo->exec("INSERT INTO settings VALUES ('telegram_bot_token', 'TKN')");
$pdo->exec("INSERT INTO telegram_bot_chats (chat_id, language) VALUES ('100', 'ru'), ('200', 'en'), ('300', 'de')");
$pdo->exec("INSERT INTO telegram_bot_chats (chat_id, language, notify_conversions) VALUES ('400', 'ru', 0)");
$sent = [];
$sender = function (string $tok, string $chat, string $text) use (&$sent) { $sent[] = [$chat, $text]; };

$now = strtotime('2026-11-20 12:00:00 UTC');
$ts = fn(int $hoursAgo) => gmdate('Y-m-d H:i:s', $now - $hoursAgo * 3600);

// Campaign 1: two offers in one rotation. Offer 10 burns money (20 × 1.5 =
// 30 spend, no sales), offer 11 sells. Safe-page clicks with huge cost must
// be invisible to the rules.
$pdo->exec("INSERT INTO campaigns (id, name) VALUES (1, 'Greece CPA'), (2, 'Single')");
$pdo->exec("INSERT INTO offers VALUES (10, 'Burner'), (11, 'Seller'), (12, 'Lonely')");
$rot = json_encode(['offers' => [
    ['id' => 10, 'weight' => 50], ['id' => 11, 'weight' => 50],
]]);
$pdo->prepare("INSERT INTO streams (id, campaign_id, name, schema_custom_json) VALUES (1, 1, 'Main', ?)")->execute([$rot]);
// A cloak stream makes the campaign's safe-page hits invisible to reports —
// and so to rules (orbitraSafePagePredicate, the shared report predicate).
$pdo->exec("INSERT INTO streams (id, campaign_id, name, schema_type, schema_custom_json) VALUES (9, 1, 'Cloak', 'cloak', '{\"safe_url\":\"x\"}')");
$pdo->prepare("INSERT INTO streams (id, campaign_id, name, schema_custom_json) VALUES (2, 2, 'Solo', ?)")
    ->execute([json_encode(['offers' => [['id' => 12, 'weight' => 100]]])]);
$ins = $pdo->prepare("INSERT INTO clicks (id, campaign_id, stream_id, offer_id, cost, is_safe_page, created_at) VALUES (?,?,?,?,?,?,?)");
for ($i = 0; $i < 20; $i++) {
    $ins->execute(["a$i", 1, 1, 10, 1.5, 0, $ts(2)]);
    $ins->execute(["b$i", 1, 1, 11, 1.0, 0, $ts(2)]);
    $ins->execute(["c$i", 2, 2, 12, 2.0, 0, $ts(2)]);
}
for ($i = 0; $i < 5; $i++) {
    $ins->execute(["s$i", 1, 1, 10, 100.0, 1, $ts(2)]); // safe page
    $ins->execute(["old$i", 1, 1, 11, 100.0, 0, $ts(72)]); // outside a 24h window
}
$pdo->exec("INSERT INTO conversions (click_id, status, payout) VALUES ('b1','sale',30),('b2','sale',30),('b3','lead',0)");

// ---- normalize ---------------------------------------------------------
[$r, $e] = CampaignRules::normalize(['conditions' => []]);
$assert('rule without conditions is refused', $r === null && $e === 'conditions_required');
[$r] = CampaignRules::normalize(['target' => 'campaign', 'action' => 'disable_item',
    'conditions' => [['metric' => 'cost', 'op' => 'gt', 'value' => '5'], ['metric' => 'bogus', 'op' => 'gt', 'value' => 1]]]);
$assert('campaign target cannot disable an item → notify', $r['action'] === 'notify');
$assert('unknown metric dropped, numeric string kept', count($r['conditions']) === 1 && $r['conditions'][0]['value'] === 5.0);
[$r] = CampaignRules::normalize(['target' => 'offers', 'action' => 'pause_campaign', 'window_hours' => 99999,
    'conditions' => [['metric' => 'cost', 'op' => 'gt', 'value' => 1]]]);
$assert('item target cannot pause the campaign → notify', $r['action'] === 'notify');
$assert('window clamped to 30 days', $r['window_hours'] === 720);

// ---- matcher -------------------------------------------------------------
$m = ['cost' => 30.0, 'sales' => 0, 'roi' => null];
$assert('AND of conditions', CampaignRules::matches([['metric' => 'cost', 'op' => 'gte', 'value' => 20], ['metric' => 'sales', 'op' => 'eq', 'value' => 0]], $m));
$assert('one false condition → no match', !CampaignRules::matches([['metric' => 'cost', 'op' => 'gte', 'value' => 20], ['metric' => 'sales', 'op' => 'gt', 'value' => 0]], $m));
$assert('ROI without spend never matches', !CampaignRules::matches([['metric' => 'roi', 'op' => 'lt', 'value' => 0]], $m));

// ---- metrics parity ------------------------------------------------------
$met = CampaignRules::metrics($pdo, 1, 'offers', $ts(24));
$assert('offer metrics exclude safe-page and out-of-window clicks',
    $met[10]['clicks'] === 20 && abs($met[10]['cost'] - 30.0) < 0.001, json_encode($met[10] ?? null));
$assert('sales and revenue per offer', $met[11]['sales'] === 2 && abs($met[11]['revenue'] - 60.0) < 0.001 && $met[11]['conversions'] === 3);
$assert('ROI from report formula', abs($met[11]['roi'] - 200.0) < 0.01, (string) $met[11]['roi']);
$camp = CampaignRules::metrics($pdo, 1, 'campaign', $ts(24));
$assert('campaign row aggregates both offers', $camp[0]['clicks'] === 40 && abs($camp[0]['cost'] - 50.0) < 0.001);

// ---- disable_item ------------------------------------------------------------
[$rule, $err] = CampaignRules::save($pdo, 1, [
    'name' => 'Kill burners', 'target' => 'offers', 'action' => 'disable_item', 'is_active' => 1,
    'conditions' => [['metric' => 'cost', 'op' => 'gte', 'value' => 20], ['metric' => 'sales', 'op' => 'eq', 'value' => 0]],
    'window_hours' => 24, 'cooldown_hours' => 24, 'interval_min' => 15,
]);
$assert('rule saved', $err === null && $rule['id'] > 0);
$preview = CampaignRules::preview($pdo, $rule, $now);
$assert('preview: burner matches, seller does not',
    $preview[0]['target_id'] === 10 && $preview[0]['match'] && !$preview[1]['match']);
$assert('preview changes nothing', strpos((string) $pdo->query('SELECT schema_custom_json FROM streams WHERE id=1')->fetchColumn(), 'paused') === false);

$sum = CampaignRules::runAll($pdo, ['now' => $now, 'sender' => $sender]);
$s1 = json_decode($pdo->query('SELECT schema_custom_json FROM streams WHERE id=1')->fetchColumn(), true);
$assert('burner paused in the rotation', ($s1['offers'][0]['state'] ?? null) === 'paused' && ($s1['offers'][0]['paused_by_rule'] ?? 0) === (int) $rule['id']);
$assert('seller untouched', !isset($s1['offers'][1]['state']));
$assert('summary counts one action', $sum['actions'] === 1 && $sum['fired'] === 1, json_encode($sum));
$assert('journal row written', (int) $pdo->query("SELECT COUNT(*) FROM campaign_rule_log WHERE status='ok' AND target_id=10")->fetchColumn() === 1);
$assert('three chats notified (alerts-off chat skipped)', count($sent) === 3, json_encode($sent));
$ruText = $sent[0][1];
$assert('message in the chat language with numbers', strpos($ruText, 'отключило оффер «Burner»') !== false
    && strpos($ruText, 'расход 30') !== false && strpos($ruText, 'Greece CPA') !== false, $ruText);
$assert('english chat gets english', strpos($sent[1][1], 'switched off offer «Burner»') !== false, $sent[1][1]);
$assert('audit row', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE resource_id = 1")->fetchColumn() === 1);

$sent = [];
$sum = CampaignRules::runAll($pdo, ['now' => $now + 600, 'sender' => $sender]);
$assert('interval: not re-checked after 10 min', $sum['rules'] === 0);
$sum = CampaignRules::runAll($pdo, ['now' => $now + 1800, 'sender' => $sender]);
$assert('after the interval the rule runs but burner is now off → nothing fires', $sum['rules'] === 1 && $sum['fired'] === 0 && !$sent);

// ---- last item guard ----------------------------------------------------------
[$rule2] = CampaignRules::save($pdo, 2, [
    'target' => 'offers', 'action' => 'disable_item', 'is_active' => 1,
    'conditions' => [['metric' => 'cost', 'op' => 'gte', 'value' => 10]],
]);
CampaignRules::runAll($pdo, ['now' => $now, 'campaign_id' => 2, 'sender' => $sender]);
$s2 = json_decode($pdo->query('SELECT schema_custom_json FROM streams WHERE id=2')->fetchColumn(), true);
$assert('the last enabled offer is never switched off', !isset($s2['offers'][0]['state']));
$assert('journal says skipped/last_enabled_item',
    $pdo->query("SELECT status || '/' || message FROM campaign_rule_log WHERE rule_id = " . (int) $rule2['id'])->fetchColumn() === 'skipped/last_enabled_item');
$assert('a skipped action sends no message', !$sent);

// ---- pause_campaign + cooldown ------------------------------------------------
[$rule3] = CampaignRules::save($pdo, 2, [
    'name' => 'Stop loss', 'target' => 'campaign', 'action' => 'pause_campaign', 'is_active' => 1,
    'conditions' => [['metric' => 'cost', 'op' => 'gte', 'value' => 30], ['metric' => 'conversions', 'op' => 'eq', 'value' => 0]],
    'cooldown_hours' => 6,
]);
$sent = [];
CampaignRules::runAll($pdo, ['now' => $now, 'campaign_id' => 2, 'force' => true, 'sender' => $sender]);
$assert('campaign paused', $pdo->query('SELECT state FROM campaigns WHERE id=2')->fetchColumn() === 'disabled');
$pauseMsgs = array_filter($sent, fn($x) => strpos($x[1], 'Stop loss') !== false);
$assert('pause message carries the ad-network warning', $pauseMsgs && strpos(array_values($pauseMsgs)[0][1], 'Рекламная сеть') !== false);
$pdo->exec("UPDATE campaigns SET state='active' WHERE id=2");
$sent = [];
CampaignRules::runAll($pdo, ['now' => $now + 3600, 'campaign_id' => 2, 'force' => true, 'sender' => $sender]);
$assert('cooldown: re-enabled campaign not paused again within 6h', $pdo->query('SELECT state FROM campaigns WHERE id=2')->fetchColumn() === 'active');
CampaignRules::runAll($pdo, ['now' => $now + 7 * 3600, 'campaign_id' => 2, 'force' => true, 'sender' => $sender]);
// window moved 7h on: clicks are 9h old, still inside 24h → fires again
$assert('after the cooldown the rule acts again', $pdo->query('SELECT state FROM campaigns WHERE id=2')->fetchColumn() === 'disabled');

// ---- notify-only rule on an archived campaign is not run ------------------------
$pdo->exec("INSERT INTO campaigns (id, name, is_archived) VALUES (3, 'Gone', 1)");
CampaignRules::save($pdo, 3, ['conditions' => [['metric' => 'clicks', 'op' => 'gte', 'value' => 0]]]);
$sum = CampaignRules::runAll($pdo, ['now' => $now, 'campaign_id' => 3, 'sender' => $sender]);
$assert('archived campaigns are skipped', $sum['rules'] === 0);

// ---- delete -------------------------------------------------------------------
$assert('delete scoped to campaign', !CampaignRules::delete($pdo, 1, (int) $rule3['id']) && CampaignRules::delete($pdo, 2, (int) $rule3['id']));

// ---- rotation optimiser Telegram ------------------------------------------------
$pdo->exec("INSERT INTO campaigns (id, name) VALUES (5, 'Rot camp')");
$pdo->exec("INSERT INTO offers VALUES (51, 'Strong'), (52, 'Weak')");
$autoCfg = ['enabled' => true, 'key' => 'k5', 'metric' => 'epv_confirmed', 'min_sample' => 1,
    'lookback_days' => 7, 'floor_pct' => 5, 'cap_pct' => 90, 'interval_min' => 5, 'notify_tg' => true];
$pdo->prepare("INSERT INTO streams (id, campaign_id, name, schema_custom_json) VALUES (5, 5, 'S5', ?)")->execute([json_encode([
    'offers' => [['id' => 51, 'weight' => 50], ['id' => 52, 'weight' => 50]],
    'auto' => ['offers' => $autoCfg],
])]);
for ($i = 0; $i < 10; $i++) {
    $ins->execute(["r$i", 5, 5, 51, 0, 0, date('Y-m-d H:i:s', $now - 3600)]);
    $ins->execute(["w$i", 5, 5, 52, 0, 0, date('Y-m-d H:i:s', $now - 3600)]);
}
$pdo->exec("INSERT INTO conversions (click_id, status, payout) VALUES ('r1','sale',50),('r2','sale',50),('r3','sale',50),('w1','sale',10)");
$sent = [];
$rs = orbitraRunRotationOptimiser($pdo, ['now' => $now, 'force' => true, 'notify_sender' => $sender]);
$assert('optimiser moved weights', $rs['changed'] === 1, json_encode($rs));
$assert('optimiser notified 3 chats', count($sent) === 3, json_encode($sent));
$assert('message lists items with arrows and metric',
    strpos($sent[0][1], 'Strong: 50% →') !== false && strpos($sent[0][1], '↑') !== false && strpos($sent[0][1], 'EPV') !== false, $sent[0][1] ?? '');
$sent = [];
orbitraRunRotationOptimiser($pdo, ['now' => $now + 600, 'force' => true, 'notify_sender' => $sender]);
$assert('throttle: no second message within an hour', !$sent);
$cfgNow = json_decode($pdo->query('SELECT schema_custom_json FROM streams WHERE id=5')->fetchColumn(), true)['auto']['offers'];
$assert('last_notified_at stored', !empty($cfgNow['last_notified_at']));

// notify_tg off → silent
$pdo->exec("UPDATE streams SET schema_custom_json = json_set(schema_custom_json, '$.auto.offers.notify_tg', json('false'), '$.offers[0].weight', 50, '$.offers[1].weight', 50) WHERE id = 5");
$sent = [];
orbitraRunRotationOptimiser($pdo, ['now' => $now + 7200, 'force' => true, 'notify_sender' => $sender]);
$assert('notify_tg off → no message', !$sent);

@unlink($tmpDb);
if ($failures > 0) {
    echo "\nFAILED: $failures assertion(s)\n";
    exit(1);
}
echo "\nAll campaign rules assertions passed.\n";
