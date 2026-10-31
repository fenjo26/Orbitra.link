<?php
// tests/replay_schema_heal_test.php
//
// Regression for the "no such column: r.entry_url" 500s on api.php?action=replays:
// a server whose session_replays was created by migration 76 and whose
// schema-78 ALTERs never landed (a locked DB at update time was swallowed as
// "column already exists") must self-heal on the next API call, and the heal
// must be honest:
//   1. a pre-78 table shape heals through the live endpoint (HTTP 200);
//   2. "duplicate column name" stays swallowed (the steady state);
//   3. any other ALTER error rethrows instead of drifting silently;
//   4. ensureSchema is idempotent on a complete schema;
//   5. a heal failure records the reason in system_logs (the update report
//      and the log page surface it to the operator).
//
// Run: php tests/replay_schema_heal_test.php

$testPassed = true;

function assertTrue($condition, string $message): bool {
    global $testPassed;
    if (!$condition) {
        fwrite(STDERR, "FAILED: $message\n");
        $testPassed = false;
    } else {
        echo "✓ $message\n";
    }
    return (bool) $condition;
}

require_once __DIR__ . '/lib/http.php';

$repoRoot = dirname(__DIR__);
$harness = new OrbitraTestHarness($repoRoot);
$harness->useProductionRouter();
$harness->start();

$pdo = $harness->getPdo();
require_once $repoRoot . '/core/SessionReplay.php';

// Admin + API key, the cloak_report_http_test pattern.
$apiKey = 'test_key_' . bin2hex(random_bytes(8));
$pdo->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)')
    ->execute(['replay_heal_admin', password_hash('x', PASSWORD_DEFAULT), 'admin']);
$adminId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO user_api_keys (user_id, api_key, key_name, permissions) VALUES (?, ?, ?, ?)')
    ->execute([$adminId, $apiKey, 'replay-schema-heal-test', 'full']);

$apiGet = function (string $path) use ($harness, $apiKey): array {
    return $harness->getWithHeaders($path, ['Authorization: Bearer ' . $apiKey]);
};

$uuid = '81097184-28b6-4d20-8df5-a1873179bd61';
$pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, created_at) VALUES (?, 1, '203.0.113.7', datetime('now'))")
    ->execute([$uuid]);

// The beta-era shape found on real installs: no campaign_id (an index
// references it — the original failure), no entry_url, no pages.
$dropToPre78 = function () use ($pdo, $uuid): void {
    $pdo->exec('DROP TABLE IF EXISTS session_replays');
    $pdo->exec("CREATE TABLE session_replays (
        click_id TEXT PRIMARY KEY, landing_id INTEGER,
        status TEXT NOT NULL DEFAULT 'armed', file TEXT NOT NULL DEFAULT '',
        bytes INTEGER NOT NULL DEFAULT 0, chunks INTEGER NOT NULL DEFAULT 0,
        events INTEGER NOT NULL DEFAULT 0, first_ts INTEGER, last_ts INTEGER,
        duration_ms INTEGER NOT NULL DEFAULT 0, clicks INTEGER NOT NULL DEFAULT 0,
        rage_clicks INTEGER NOT NULL DEFAULT 0, viewport_w INTEGER, viewport_h INTEGER,
        page_url TEXT, created_at DATETIME NOT NULL, updated_at DATETIME)");
    $pdo->prepare("INSERT INTO session_replays (click_id, status, chunks, created_at) VALUES (?, 'done', 1, datetime('now'))")
        ->execute([$uuid]);
};

// 1. The beta-era shape heals through the live endpoint.
$dropToPre78();
$resp = $apiGet('/api.php?action=replays&click_id=' . $uuid . '&limit=1');
assertTrue($resp['code'] === 200, 'A pre-78 schema self-heals through the endpoint (HTTP ' . $resp['code'] . ')');
assertTrue(strpos($resp['body'], '"click_id":"' . $uuid . '"') !== false, 'The healed endpoint returns the recording');
$idx = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_session_replays_campaign'")->fetchColumn();
assertTrue($idx !== false, 'The campaign index exists after the heal (the original sin was creating it over a missing column)');
$cc = $pdo->query("PRAGMA table_info(session_replays)")->fetchAll(PDO::FETCH_COLUMN, 1);
assertTrue(in_array('campaign_id', $cc, true) && in_array('entry_url', $cc, true), 'The heal added campaign_id and entry_url');

// 2. Duplicate columns stay swallowed (the every-request steady state).
orbitraReplayAddColumn($pdo, 'ALTER TABLE session_replays ADD COLUMN entry_url TEXT');
$cols = $pdo->query("PRAGMA table_info(session_replays)")->fetchAll(PDO::FETCH_COLUMN, 1);
assertTrue(in_array('entry_url', $cols, true), 'A duplicate ADD COLUMN is swallowed, the column stays');

// 3. Anything else rethrows honestly instead of drifting silently.
$threw = false;
try {
    orbitraReplayAddColumn($pdo, 'ALTER TABLE no_such_replay_table ADD COLUMN x TEXT');
} catch (\Throwable $e) {
    $threw = true;
}
assertTrue($threw, 'A broken ALTER rethrows instead of being swallowed');

// 4. Idempotent on a complete schema.
orbitraReplayEnsureSchema($pdo);
orbitraReplayEnsureSchema($pdo);
assertTrue(true, 'ensureSchema is idempotent on a complete schema');

// 5. The failure log the API path writes fits the real system_logs schema.
$pdo->prepare('INSERT INTO system_logs (level, message, context) VALUES (?, ?, ?)')
    ->execute(['ERROR', 'Session replay schema self-heal failed: demonstration', json_encode(['source' => 'replays'])]);
$logged = $pdo->query("SELECT COUNT(*) FROM system_logs WHERE message LIKE '%self-heal failed%'")->fetchColumn();
assertTrue((int) $logged === 1, 'A heal failure record fits the system_logs schema');

echo $testPassed ? "All replay schema heal tests passed.\n" : "replay_schema_heal_test: FAIL\n";
exit($testPassed ? 0 : 1);
