<?php
/**
 * Migration 30 fixture test: seed allow_php_landings / php_landing_timeout.
 *
 * The default-rows block in config.php only executes when the migration
 * closure runs, and v1.0.4 added those rows without bumping the schema —
 * so every database already at user_version 29 updated straight to v1.0.4
 * without them and LeadForge builds failed with php_landings_disabled.
 * This test reproduces exactly that database and runs the real block,
 * extracted from config.php, not copied.
 */

$failures = [];

$check = static function (string $name, $expected, $actual) use (&$failures) {
    if ($expected !== $actual) {
        $failures[] = "$name: expected " . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

require_once __DIR__ . '/../core/PhpLanding.php';

$makePdo = static function (): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE settings (
            key TEXT PRIMARY KEY,
            value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        PRAGMA user_version = 29;
    ");
    return $pdo;
};

$configSource = file_get_contents(__DIR__ . '/../config.php');
$start = strpos($configSource, 'if ($schemaVersion < 30) {');
$end = strpos($configSource, '// Mark schema as up-to-date.');
if ($start === false || $end === false || $end <= $start) {
    $failures[] = 'could not locate the migration 30 block in config.php';
} else {
    // --- The bug scenario: v1.0.2 install (schema 29) updated to v1.0.4 ---
    // No allow_php_landings row at all, so enabled() saw no row and LeadForge
    // builds failed with php_landings_disabled even after the update.
    $pdo = $makePdo();
    $schemaVersion = 29;
    eval(substr($configSource, $start, $end - $start));

    $check('allow_php_landings seeded on a schema-29 database', '1', (string) $pdo->query("SELECT value FROM settings WHERE key = 'allow_php_landings'")->fetchColumn());
    $check('php_landing_timeout seeded on a schema-29 database', '3', (string) $pdo->query("SELECT value FROM settings WHERE key = 'php_landing_timeout'")->fetchColumn());
    $check('enabled() after seed', true, PhpLanding::enabled($pdo));

    // Re-running at v30 must be a no-op.
    $schemaVersion = 30;
    eval(substr($configSource, $start, $end - $start));
    $check('re-run at v30 changes nothing', '1', (string) $pdo->query("SELECT value FROM settings WHERE key = 'allow_php_landings'")->fetchColumn());

    // --- An explicit admin opt-out must survive the seed ---
    $pdoOptedOut = $makePdo();
    $pdoOptedOut->exec("INSERT INTO settings (key, value) VALUES ('allow_php_landings', '0')");
    $schemaVersion = 29;
    eval(substr($configSource, $start, $end - $start));

    $check('explicit admin opt-out survives the seed', '0', (string) $pdoOptedOut->query("SELECT value FROM settings WHERE key = 'allow_php_landings'")->fetchColumn());
    $check('enabled() respects the explicit opt-out', false, PhpLanding::enabled($pdoOptedOut));
}

// --- enabled() semantics: missing row is the on-by-default product default ---
$pdoMissing = $makePdo();
$check('enabled() with no settings row (on by default)', true, PhpLanding::enabled($pdoMissing));

$pdoOn = $makePdo();
$pdoOn->exec("INSERT INTO settings (key, value) VALUES ('allow_php_landings', '1')");
$check("enabled() with '1'", true, PhpLanding::enabled($pdoOn));

if ($failures) {
    echo "SETTINGS SEED V30 TEST FAILED\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "SETTINGS SEED V30 TEST PASSED\n";
exit(0);
