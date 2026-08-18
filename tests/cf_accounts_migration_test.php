<?php
// tests/cf_accounts_migration_test.php
//
// Cloudflare multi-account (schema 32):
//   - the real migration block extracted from config.php (so the test fails
//     when someone edits the migration out of sync with it);
//   - the legacy cf_api_token settings row seeds account #1 with the stored
//     token/ssl_mode/proxied, so a v1.0.5 install keeps parking after the
//     upgrade;
//   - domains.dns_account_id appears (nullable, no default);
//   - a schema-31 database is untouched by re-runs (INSERT INTO ... SELECT
//     guard via the settings check, not blind seeding).
//
// Run: php tests/cf_accounts_migration_test.php

$assert = function (string $label, $got, $expected) {
    if ($got !== $expected) {
        echo "FAIL $label: got " . var_export($got, true) . ", expected " . var_export($expected, true) . "\n";
        exit(1);
    }
    echo "ok  $label\n";
};
$assertTrue = function (string $label, $got) {
    if ($got !== true) {
        echo "FAIL $label: got " . var_export($got, true) . "\n";
        exit(1);
    }
    echo "ok  $label\n";
};

// ------------------------------------------------- extract migration 32
$configSrc = file_get_contents(__DIR__ . '/../config.php');
$start = strpos($configSrc, 'if ($schemaVersion < 32) {');
$end = strpos($configSrc, '// Mark schema as up-to-date. This must be last.');
$assert('migration 32 block present in config.php', $start !== false && $end !== false && $end > $start, true);
$block = substr($configSrc, $start, $end - $start);

// ------------------------------------------------------- throwaway schema
$tmpDb = sys_get_temp_dir() . '/orbitra_cfacc_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$pdo = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)');
$pdo->exec('CREATE TABLE domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    registrar TEXT DEFAULT "",
    dns_provider TEXT DEFAULT "",
    status TEXT DEFAULT "OK"
)');
// A v1.0.5 install: legacy single Cloudflare connection in settings.
$seed = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)');
$seed->execute(['cf_api_token', 'legacy-token-abc']);
$seed->execute(['cf_proxied', '0']);
$seed->execute(['cf_ssl_mode', 'full']);

$schemaVersion = 31;
eval($block);
// The block itself does not touch $schemaVersion — config.php sets it to
// $LATEST_SCHEMA_VERSION after every block, outside the extracted region.

// ---------------------------------------------------------------- tables
$cols = [];
foreach ($pdo->query('PRAGMA table_info(cloudflare_accounts)') as $c) {
    $cols[] = $c['name'];
}
foreach (['id', 'name', 'api_token', 'ssl_mode', 'proxied', 'zones_count', 'is_active', 'created_at'] as $need) {
    $assertTrue("cloudflare_accounts has $need", in_array($need, $cols, true));
}

$domCols = [];
foreach ($pdo->query('PRAGMA table_info(domains)') as $c) {
    $domCols[] = $c['name'];
}
$assertTrue('domains.dns_account_id added', in_array('dns_account_id', $domCols, true));

// ------------------------------------------------------------------ seed
$row = $pdo->query('SELECT name, api_token, ssl_mode, proxied FROM cloudflare_accounts ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$assert('seed row token', $row['api_token'], 'legacy-token-abc');
$assert('seed row ssl_mode', $row['ssl_mode'], 'full');
$assert('seed row proxied=0 respected', (int) $row['proxied'], 0);

// Re-running the guard logic on a fresh database must not double-seed when the
// legacy token is gone (accounts already exist).
$pdo->exec("DELETE FROM settings WHERE key = 'cf_api_token'");
$schemaVersion = 31;
eval($block);
$count = (int) $pdo->query('SELECT COUNT(*) FROM cloudflare_accounts')->fetchColumn();
$assert('no duplicate seed without legacy token', $count, 1);

// A database with NO legacy token gets no phantom account.
$pdo->exec('DELETE FROM cloudflare_accounts');
$schemaVersion = 31;
eval($block);
$count = (int) $pdo->query('SELECT COUNT(*) FROM cloudflare_accounts')->fetchColumn();
$assert('no seed without legacy token', $count, 0);

// ALTER is idempotent-safe: second eval on same db must not throw.
$schemaVersion = 31;
eval($block);
$assertTrue('migration re-run tolerated', true);

@unlink($tmpDb);
echo "\nALL OK\n";
exit(0);
