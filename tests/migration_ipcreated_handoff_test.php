<?php
/**
 * Stand incident 2026-11-21 (ТЗ 1.9.8 step 1, hotfix): the load stand
 * resets from clean.sqlite — a 1.9.7-era snapshot taken BEFORE the spool
 * worker's first tick. Such a DB has idx_clicks_ip_created and
 * clicks_ua_index_state='pending' but NO idx_clicks_ip_ua_created. The
 * unconditional drop in migration 84 left every uniqueness/debounce/
 * ClickFlags probe with neither index → full window scans per click →
 * 0 successful responses out of 144k at 100% CPU (480 rps), while 1.9.7
 * passed the same test at 40% CPU.
 *
 * The contract under test:
 *  1. the migration drops ip_created ONLY when ip_ua already exists
 *     (sqlite_master check); a pre-tick snapshot keeps ip_created and the
 *     probes keep their index (EXPLAIN: seek on ip_created);
 *  2. the spool worker, on its first tick, builds ip_ua and then drops the
 *     retired ip_created itself; after that the probes seek the ip_ua
 *     prefix (no SCAN anywhere);
 *  3. a database that already has ip_ua (normal upgrade path) gets the
 *     drop straight from the migration.
 *
 * Usage: php tests/migration_ipcreated_handoff_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$repoRoot = dirname(__DIR__);
$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

// A scratch tree with the real config/core + a 1.9.7-shaped snapshot:
// schema stamped one migration behind (83), ip_created present, ip_ua
// absent, worker state 'pending'.
$work = sys_get_temp_dir() . '/orbitra_mig84_' . getmypid();
@mkdir($work . '/cli', 0777, true);
@mkdir($work . '/var/cache', 0777, true);
register_shutdown_function(static function () use ($work): void {
    foreach (glob($work . '/cli/*') ?: [] as $f) @unlink($f);
    foreach (glob($work . '/var/cache/*') ?: [] as $f) @unlink($f);
    foreach (glob($work . '/var/logs/*') ?: [] as $f) @unlink($f);
    foreach (['/cli', '/var/cache', '/var/logs', '/var', ''] as $d) @rmdir($work . $d);
});
copy($repoRoot . '/cli/click_spool_cron.php', $work . '/cli/click_spool_cron.php');
require_once $repoRoot . '/core/click_logger.php';
$configSrc = preg_replace(
    '/\$db_file\s*=\s*__DIR__\s*\.\s*[\'"]\/orbitra_db\.sqlite[\'"];/',
    '$db_file = __DIR__ . "/test.sqlite";',
    (string) file_get_contents($repoRoot . '/config.php')
);
file_put_contents($work . '/config.php', $configSrc);
exec('cp -R ' . escapeshellarg($repoRoot . '/core') . ' ' . escapeshellarg($work . '/core') . ' 2>/dev/null');

try {
    // --- build the schema once (current code), then shape the snapshot ----
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export($work . '/config.php', true) . ';') . ' 2>/dev/null');
    $db = $work . '/test.sqlite';
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $latest = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    check($latest >= 84, "the current schema built (user_version=$latest)");

    // Shape A: pre-84 snapshot WITHOUT ip_ua (the stand's clean.sqlite).
    // Shape B: normal upgrade, ip_ua already built.
    //
    // Both stamp user_version = 83 — the version migration 84 upgrades FROM —
    // NOT ($latest - 1): this test's contract is migration 84's conditional
    // drop, which is version-absolute, while $latest moves with every new
    // migration (85 landed and made "latest - 1" = 84, silently skipping the
    // very block under test). Newer migrations simply ride along, which is
    // what a real pre-84 database does too.
    $pre84 = 83;
    $shapeSnapshot = static function () use ($pdo, $pre84): void {
        $pdo->exec('DROP INDEX IF EXISTS idx_clicks_ip_ua_created');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clicks_ip_created ON clicks(ip, created_at)');
        $pdo->exec("PRAGMA user_version = " . $pre84);
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('clicks_ua_index_state', ?)")->execute(['pending']);
    };
    $shapeUpgraded = static function () use ($pdo, $pre84): void {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clicks_ip_ua_created ON clicks(ip, ua_hash, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clicks_ip_created ON clicks(ip, created_at)');
        $pdo->exec("PRAGMA user_version = " . $pre84);
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('clicks_ua_index_state', ?)")->execute(['done']);
    };
    $indexExists = static function (string $name) use ($pdo): bool {
        return $pdo->query("SELECT 1 FROM sqlite_master WHERE type='index' AND name='" . $name . "'")->fetchColumn() !== false;
    };
    $planOf = static function (string $sql, array $params) use ($pdo): string {
        $st = $pdo->prepare('EXPLAIN QUERY PLAN ' . $sql);
        $st->execute($params);
        return implode(' | ', $st->fetchAll(PDO::FETCH_COLUMN, 3));
    };
    // the probe exactly as the code builds it: the INDEXED BY hint from
    // orbitraClicksIpIndexHint + the WHERE — exercising the real resolution
    $probeParams = ['9.9.9.9', '2026-01-01 00:00:00', 2];
    $probeSql = 'SELECT id FROM clicks' . orbitraClicksIpIndexHint($pdo) . ' WHERE ip = ? AND created_at >= ? AND campaign_id = ? LIMIT 1';

    // --- 1. the snapshot: migration defers, probes keep ip_created --------
    $shapeSnapshot();
    $pdo = null;
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export($work . '/config.php', true) . ';') . ' 2>/dev/null');
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    check((int) $pdo->query('PRAGMA user_version')->fetchColumn() === $latest, 'migration 84 stamped the version on the ip_ua-less snapshot');
    check($indexExists('idx_clicks_ip_created'), '…but KEPT idx_clicks_ip_created (ip_ua not built yet)');
    check(!$indexExists('idx_clicks_ip_ua_created'), 'ip_ua still absent (waiting for the spool worker)');
    $plan = $planOf($probeSql, $probeParams);
    check(strpos($plan, 'idx_clicks_ip_created') !== false && strpos($plan, 'SCAN') === false,
        "the probe still seeks ip_created ({$plan})");

    // --- 2. the worker tick builds ip_ua and drops the retired index -------
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($work . '/cli/click_spool_cron.php') . ' --quiet --checkpoint-budget=0 2>/dev/null');
    check($indexExists('idx_clicks_ip_ua_created'), 'the spool worker built idx_clicks_ip_ua_created');
    check(!$indexExists('idx_clicks_ip_created'), 'and dropped the retired idx_clicks_ip_created');
    $freshPdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); // hint cache is per-handle
    $probeSql2 = 'SELECT id FROM clicks' . orbitraClicksIpIndexHint($freshPdo) . ' WHERE ip = ? AND created_at >= ? AND campaign_id = ? LIMIT 1';
    $st = $freshPdo->prepare('EXPLAIN QUERY PLAN ' . $probeSql2);
    $st->execute($probeParams);
    $plan = implode(' | ', $st->fetchAll(PDO::FETCH_COLUMN, 3));
    check(strpos($plan, 'idx_clicks_ip_ua_created') !== false && strpos($plan, 'SCAN') === false,
        "the probe now seeks the ip_ua prefix ({$plan})");

    // --- 3. the normal upgrade path: migration drops directly --------------
    $shapeUpgraded();
    $pdo = null;
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export($work . '/config.php', true) . ';') . ' 2>/dev/null');
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    check(!$indexExists('idx_clicks_ip_created'), 'with ip_ua present the migration drops ip_created itself');
    check($indexExists('idx_clicks_ip_ua_created'), 'ip_ua untouched');
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo $failures === 0 ? "Migration ip_created handoff tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
