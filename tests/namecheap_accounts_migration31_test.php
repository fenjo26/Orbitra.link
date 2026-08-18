<?php
/**
 * tests/namecheap_accounts_migration31_test.php
 *
 * Migration 31 fixture test: namecheap_accounts + the legacy single
 * connection seeded as row #1. A v1.0.5 install (schema 30) upgraded in place
 * must keep parking and buying through the same credentials — if the seed
 * were missing, every connected tracker would wake up "not connected".
 * The real block is extracted from config.php, not copied.
 *
 * Run from the project root:
 *
 *     php tests/namecheap_accounts_migration31_test.php
 */

$failures = [];

$check = static function (string $name, $expected, $actual) use (&$failures) {
    if ($expected !== $actual) {
        $failures[] = "$name: expected " . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

$makePdo = static function (): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE settings (
            key TEXT PRIMARY KEY,
            value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        PRAGMA user_version = 30;
    ");
    return $pdo;
};

$configSource = file_get_contents(__DIR__ . '/../config.php');
$start = strpos($configSource, 'if ($schemaVersion < 31) {');
$end = strpos($configSource, 'if ($schemaVersion < 32) {');
if ($start === false || $end === false || $end <= $start) {
    // Single-migration future: fall back to the schema marker comment.
    $end = strpos($configSource, '// Mark schema as up-to-date.');
}
if ($start === false || $end === false || $end <= $start) {
    $failures[] = 'could not locate the migration 31 block in config.php';
} else {
    // --- The upgrade scenario: schema 30 with the legacy connection set ---
    $pdoSeeded = $makePdo();
    $pdo = $pdoSeeded;
    $seedSettings = $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?)");
    $seedSettings->execute(['nc_api_key', 'legacy-key-123']);
    $seedSettings->execute(['nc_username', 'aditya']);
    $seedSettings->execute(['nc_sandbox', '0']);
    $seedSettings->execute(['nc_address_id', '77']);
    $schemaVersion = 30;
    eval(substr($configSource, $start, $end - $start));

    $row = $pdo->query("SELECT name, username, api_key, contact_id, sandbox, is_active FROM namecheap_accounts")->fetch(PDO::FETCH_ASSOC);
    $row = array_map('strval', $row);
    $check('legacy connection seeded as row #1', [
        'name' => 'aditya',
        'username' => 'aditya',
        'api_key' => 'legacy-key-123',
        'contact_id' => '77',
        'sandbox' => '0',
        'is_active' => '1',
    ], $row);

    // --- Re-running at v31 must be a no-op (no duplicate seed row) ---
    $schemaVersion = 31;
    eval(substr($configSource, $start, $end - $start));
    $check('re-run at v31 adds no duplicate', 1, (int) $pdo->query("SELECT COUNT(*) FROM namecheap_accounts")->fetchColumn());

    // --- An install that never connected seeds nothing ---
    // (the extracted block addresses $pdo, so each scenario rebinds it)
    $pdoEmpty = $makePdo();
    $pdo = $pdoEmpty;
    $schemaVersion = 30;
    eval(substr($configSource, $start, $end - $start));
    $check('no legacy connection means no seed row', 0, (int) $pdoEmpty->query("SELECT COUNT(*) FROM namecheap_accounts")->fetchColumn());

    // --- Half-configured (key but no username) seeds nothing ---
    $pdoHalf = $makePdo();
    $pdoHalf->exec("INSERT INTO settings (key, value) VALUES ('nc_api_key', 'orphan-key')");
    $pdo = $pdoHalf;
    $schemaVersion = 30;
    eval(substr($configSource, $start, $end - $start));
    $check('key without username seeds nothing', 0, (int) $pdoHalf->query("SELECT COUNT(*) FROM namecheap_accounts")->fetchColumn());

    // --- Legacy settings rows survive the migration (downgrade safety) ---
    $check('legacy settings rows kept', 'legacy-key-123', (string) $pdoSeeded->query("SELECT value FROM settings WHERE key = 'nc_api_key'")->fetchColumn());
}

if ($failures) {
    echo "NAMECHEAP MIGRATION 31 TEST FAILED\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "NAMECHEAP MIGRATION 31 TEST PASSED\n";
exit(0);
