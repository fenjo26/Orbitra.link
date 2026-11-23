<?php
// tests/local_page_actions_test.php
//
// Tests for core/LocalPageActions.php (local landing & local offer creation,
// static copying, live-campaign protection, no-PHP allowlist, path safety)
// and AiAgent admin-only CRM tool gating.
//
// Run: php tests/local_page_actions_test.php

require_once __DIR__ . '/../core/LocalPageActions.php';
require_once __DIR__ . '/../core/AiAgent.php';

// Minimal stubs for api.php helpers used by LocalPageActions.php
if (!function_exists('orbitraLandingEditableExtensions')) {
    function orbitraLandingEditableExtensions(): array
    {
        return ['html', 'htm', 'css', 'js', 'mjs', 'json', 'txt', 'md', 'xml', 'svg', 'png', 'jpg', 'webp'];
    }
}
if (!function_exists('orbitraOfferFilePath')) {
    function orbitraOfferFilePath($id, $relPath, bool $mustExist = true): ?string
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $resolved = dirname(__DIR__) . '/offers/' . $id;
        if (!is_dir($resolved) && !$mustExist) {
            @mkdir($resolved, 0775, true);
        }
        $root = realpath($resolved);
        if ($root === false) {
            return null;
        }
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', (string) $relPath)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null;
            }
            $segments[] = $seg;
        }
        if (!$segments) {
            return null;
        }
        $target = $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        $real = realpath($target);
        if ($real === false) {
            return $mustExist ? null : $target;
        }
        return ($real === $root || strpos($real, $root . DIRECTORY_SEPARATOR) === 0) ? $real : null;
    }
}

$failures = 0;
$assert = function (string $label, $cond, string $detail = '') use (&$failures) {
    if ($cond) {
        echo "  ok  $label\n";
    } else {
        $failures++;
        echo "FAIL  $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
};

// Setup isolated SQLite DB + admin session
$_SESSION = ['user_id' => 1, 'role' => 'admin'];
$tmpDb = sys_get_temp_dir() . '/orbitra_local_page_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$pdo = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec("CREATE TABLE landings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT, group_id INTEGER, type TEXT DEFAULT 'local', url TEXT DEFAULT '',
    action_payload TEXT, action_type TEXT DEFAULT '', state TEXT DEFAULT 'active',
    slug TEXT DEFAULT '', redirect_type TEXT DEFAULT 'redirect', owner_user_id INTEGER
)");
$pdo->exec("CREATE TABLE offers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT, group_id INTEGER, affiliate_network_id INTEGER, url TEXT DEFAULT '',
    redirect_type TEXT DEFAULT 'redirect', is_local INTEGER DEFAULT 0, geo TEXT DEFAULT '',
    payout_type TEXT DEFAULT 'cpa', payout_value REAL DEFAULT 0, payout_auto INTEGER DEFAULT 0,
    allow_rebills INTEGER DEFAULT 0, capping_limit INTEGER DEFAULT 0, capping_timezone TEXT DEFAULT 'UTC',
    alt_offer_id INTEGER, notes TEXT DEFAULT '', values_json TEXT DEFAULT '[]',
    country_values TEXT DEFAULT '{}', tg_channel_id TEXT DEFAULT '', tg_window_days INTEGER DEFAULT 0,
    tg_welcome TEXT DEFAULT '', tg_bot_id INTEGER DEFAULT 0, state TEXT DEFAULT 'active',
    owner_user_id INTEGER
)");
$pdo->exec("CREATE TABLE campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, state TEXT DEFAULT 'active'
)");
$pdo->exec("CREATE TABLE streams (
    id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, offer_id INTEGER,
    is_active INTEGER DEFAULT 1, schema_custom_json TEXT
)");

// Track created directories for cleanup
$cleanupDirs = [];
$rmTree = function (string $dir) use (&$rmTree) {
    if (!is_dir($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
        $p = $dir . '/' . $f;
        is_dir($p) ? $rmTree($p) : @unlink($p);
    }
    @rmdir($dir);
};

// 1. Create a new local landing with initial HTML + CSS
$slug = 'ai-test-lp-' . getmypid();
$res = orbitraLocalLandingCreate($pdo, [
    'name' => 'Test Prelander DE',
    'slug' => $slug,
    'html' => '<!doctype html><html><body><h1>Hallo</h1></body></html>',
    'files' => [['path' => 'styles.css', 'content' => 'body{color:#111}']],
]);
$assert('create local landing succeeds', !empty($res['ok']), json_encode($res));
$lpId = (int) ($res['data']['id'] ?? 0);
$lpDir = orbitraLandingDir($pdo, $lpId);
$cleanupDirs[] = $lpDir;
$assert('initial index.html and styles.css written', is_file($lpDir . '/index.html') && is_file($lpDir . '/styles.css'));

// 2. Write a JS file to the draft landing (not in any campaign)
$w1 = orbitraLocalLandingWriteFile($pdo, ['id' => $lpId, 'path' => 'app.js', 'content' => 'console.log(1);']);
$assert('write static JS to draft landing succeeds', !empty($w1['ok']) && is_file($lpDir . '/app.js'), json_encode($w1));

// 3. Block PHP and path traversal
$wPhp = orbitraLocalLandingWriteFile($pdo, ['id' => $lpId, 'path' => 'shell.php', 'content' => '<?php phpinfo();']);
$assert('PHP file write is blocked', empty($wPhp['ok']) && strpos($wPhp['message'], 'PHP') !== false, json_encode($wPhp));
$wTrav = orbitraLocalLandingWriteFile($pdo, ['id' => $lpId, 'path' => '../../evil.html', 'content' => 'x']);
$assert('path traversal is blocked', empty($wTrav['ok']), json_encode($wTrav));

// 4. Live-campaign guard: attach landing to an active campaign's active stream
$pdo->exec("INSERT INTO campaigns (id, name, state) VALUES (10, 'Live DE Nutra', 'active')");
$pdo->prepare("INSERT INTO streams (campaign_id, is_active, schema_custom_json) VALUES (10, 1, ?)")
    ->execute([json_encode(['landings' => [['id' => $lpId, 'weight' => 100]]])]);
$wLive = orbitraLocalLandingWriteFile($pdo, ['id' => $lpId, 'path' => 'index.html', 'content' => 'overwrite']);
$assert('live landing write is refused', empty($wLive['ok']) && ($wLive['code'] ?? '') === 'live_entity_protected', json_encode($wLive));

// 5. Copy the live landing (putting a .php file inside first to verify it is skipped!)
file_put_contents($lpDir . '/order.php', '<?php // legacy handler');
$copySlug = 'ai-test-lp-copy-' . getmypid();
$copy = orbitraLocalLandingCreate($pdo, ['name' => 'Test Prelander ES', 'slug' => $copySlug, 'copy_from_id' => $lpId]);
$assert('copy of live landing succeeds', !empty($copy['ok']), json_encode($copy));
$copyId = (int) ($copy['data']['id'] ?? 0);
$copyDir = orbitraLandingDir($pdo, $copyId);
$cleanupDirs[] = $copyDir;
$assert('copy keeps HTML/CSS/JS and skips PHP', is_file($copyDir . '/index.html') && is_file($copyDir . '/styles.css') && !file_exists($copyDir . '/order.php'));

// Writing to the copy succeeds because the copy is not in an active campaign
$wCopy = orbitraLocalLandingWriteFile($pdo, ['id' => $copyId, 'path' => 'index.html', 'content' => '<h1>Hola</h1>']);
$assert('writing to copy succeeds', !empty($wCopy['ok']) && file_get_contents($copyDir . '/index.html') === '<h1>Hola</h1>');

// 6. Local offer create + live-campaign guard + copy
$off = orbitraLocalOfferCreate($pdo, [
    'name' => 'Local Offer IT',
    'geo' => 'IT',
    'html' => '<h1>Offerta</h1>',
]);
$assert('create local offer succeeds', !empty($off['ok']), json_encode($off));
$offId = (int) ($off['data']['id'] ?? 0);
$offDir = dirname(__DIR__) . '/offers/' . $offId;
$cleanupDirs[] = $offDir;
$assert('local offer index.html written', is_file($offDir . '/index.html'));

$offPhp = orbitraLocalOfferWriteFile($pdo, ['id' => $offId, 'path' => 'order.php', 'content' => '<?php']);
$assert('local offer PHP write is blocked', empty($offPhp['ok']), json_encode($offPhp));

$pdo->prepare("INSERT INTO streams (campaign_id, offer_id, is_active) VALUES (10, ?, 1)")->execute([$offId]);
$offLive = orbitraLocalOfferWriteFile($pdo, ['id' => $offId, 'path' => 'index.html', 'content' => 'x']);
$assert('live local offer write is refused', empty($offLive['ok']) && ($offLive['code'] ?? '') === 'live_entity_protected', json_encode($offLive));

// Paused campaign does NOT block writes
$pdo->exec("UPDATE campaigns SET state = 'paused' WHERE id = 10");
$offPaused = orbitraLocalOfferWriteFile($pdo, ['id' => $offId, 'path' => 'index.html', 'content' => '<h1>Updated</h1>']);
$assert('paused campaign allows local offer write', !empty($offPaused['ok']));

// 7. AiAgent admin-only CRM tool gating + pageLine
$adminTools = array_column(AiAgent::toolDefs('admin'), 'name');
$userTools = array_column(AiAgent::toolDefs('user'), 'name');
$assert('admin sees CRM tools', in_array('orbitra_crm_leads', $adminTools, true) && in_array('orbitra_crm_lead_evidence', $adminTools, true));
$assert('non-admin does not see CRM tools', !in_array('orbitra_crm_leads', $userTools, true) && !in_array('orbitra_crm_update_lead', $userTools, true));
$assert('non-admin still sees local landing tools', in_array('orbitra_create_local_landing', $userTools, true));
$assert('pageLine supports crm and leadforge',
    strpos((string) AiAgent::pageLine(['kind' => 'crm']), 'CRM') !== false
    && strpos((string) AiAgent::pageLine(['kind' => 'leadforge']), 'LeadForge') !== false);

// Cleanup
foreach ($cleanupDirs as $d) {
    $rmTree($d);
}
@unlink($tmpDb);

echo $failures === 0 ? "ALL PASSED\n" : "FAILED: $failures\n";
exit($failures === 0 ? 0 : 1);
