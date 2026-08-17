<?php
/**
 * Migration 23 fixture test: dedicated domain_groups + domain attributes.
 *
 * Builds a realistic pre-v23 database (including the keitaro_id and dns_*
 * columns that arrived in later migrations on real installs, and the
 * campaigns.domain_id FK that points back at domains), then executes the
 * exact migration block from config.php — extracted from the source, not
 * copied — so what is tested is what ships.
 */

$failures = [];

$check = static function (string $name, $expected, $actual) use (&$failures) {
    if ($expected !== $actual) {
        $failures[] = "$name: expected " . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

// --- Pre-v23 schema: the shape real installs have at user_version 22 ---
$pdo->exec("
    CREATE TABLE campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        alias TEXT NOT NULL UNIQUE,
        domain_id INTEGER,
        FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE SET NULL
    );
    CREATE TABLE offer_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE domains (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        index_campaign_id INTEGER,
        catch_404 INTEGER DEFAULT 0,
        group_id INTEGER,
        is_noindex INTEGER DEFAULT 0,
        https_only INTEGER DEFAULT 0,
        ssl_status TEXT DEFAULT 'none',
        ssl_error TEXT,
        ssl_attempts INTEGER DEFAULT 0,
        ssl_last_attempt TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        dns_status TEXT, dns_ip TEXT, dns_checked_at DATETIME,
        keitaro_id INTEGER,
        FOREIGN KEY (index_campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
        FOREIGN KEY (group_id) REFERENCES offer_groups(id) ON DELETE SET NULL
    );
    PRAGMA user_version = 22;
");

$pdo->exec("INSERT INTO offer_groups (id, name) VALUES (1, 'FB Nutra'), (2, 'Unused Offer Group')");
$pdo->exec("INSERT INTO domains (name, group_id, ssl_status, dns_status, keitaro_id, https_only) VALUES ('track1.com', 1, 'installed', 'active', 77, 1)");
$pdo->exec("INSERT INTO domains (name, group_id, ssl_status) VALUES ('track2.com', NULL, 'pending')");
$pdo->exec("INSERT INTO domains (name, group_id, https_only) VALUES ('panel.com', NULL, 0)");
$track1Id = (int) $pdo->query("SELECT id FROM domains WHERE name = 'track1.com'")->fetchColumn();

// --- Run the real migration block, extracted from config.php ---
$configSource = file_get_contents(__DIR__ . '/../config.php');
$start = strpos($configSource, 'if ($schemaVersion < 23) {');
$end = strpos($configSource, '// Mark schema as up-to-date.');
if ($start === false || $end === false || $end <= $start) {
    $failures[] = 'could not locate the migration 23 block in config.php';
} else {
    $schemaVersion = 22;
    eval(substr($configSource, $start, $end - $start));
}

// --- Structure ---
$colNames = [];
foreach ($pdo->query("PRAGMA table_info(domains)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $colNames[] = $r['name'];
}
foreach (['admin_access', 'cloudflare_proxy', 'registrar', 'dns_provider', 'status', 'keitaro_id', 'dns_status'] as $required) {
    $check("column $required exists", true, in_array($required, $colNames, true));
}

$dgExists = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='domain_groups'")->fetchColumn();
$check('domain_groups table created', true, $dgExists);

// --- Data survives the rebuild ---
$row = $pdo->query("SELECT group_id, ssl_status, dns_status, keitaro_id, https_only, admin_access, cloudflare_proxy, registrar, dns_provider, status FROM domains WHERE name='track1.com'")->fetch(PDO::FETCH_ASSOC);
$check('track1.com group preserved', 1, (int) $row['group_id']);
$check('track1.com ssl_status preserved', 'installed', $row['ssl_status']);
$check('track1.com dns_status preserved', 'active', $row['dns_status']);
$check('track1.com keitaro_id preserved', 77, (int) $row['keitaro_id']);
$check('track1.com https_only preserved', 1, (int) $row['https_only']);
$check('existing rows keep admin access (no panel lockout)', 1, (int) $row['admin_access']);
$check('cloudflare_proxy defaults off', 0, (int) $row['cloudflare_proxy']);
$check('status defaults OK', 'OK', $row['status']);
$check('registrar defaults empty', '', $row['registrar']);

$check('all three domains survive', '3', (string) $pdo->query('SELECT COUNT(*) FROM domains')->fetchColumn());

// --- Group seeding: the offer group a domain used became a domain group with the same id/name ---
$seeded = $pdo->query("SELECT name FROM domain_groups WHERE id = 1")->fetchColumn();
$check('used offer group seeded into domain_groups under same id', 'FB Nutra', $seeded);
$unusedSeeded = (int) $pdo->query("SELECT COUNT(*) FROM domain_groups WHERE id = 2")->fetchColumn();
$check('unused offer groups are not seeded', 0, $unusedSeeded);

// --- FK now targets domain_groups ---
// Deleting an offer group with a colliding id must NOT touch domain groups
// (the pre-v23 FK would have nulled them via ON DELETE SET NULL).
$pdo->exec('DELETE FROM offer_groups WHERE id = 1');
$stillGrouped = (int) $pdo->query("SELECT group_id FROM domains WHERE name='track1.com'")->fetchColumn();
$check('offer group deletion no longer detaches domains', 1, $stillGrouped);

// Deleting a domain group detaches its domains.
$pdo->exec('DELETE FROM domain_groups WHERE id = 1');
$detached = $pdo->query("SELECT group_id FROM domains WHERE name='track1.com'")->fetchColumn();
$check('domain group deletion detaches domains', null, $detached);

// The campaigns.domain_id FK still points at the rebuilt table: inserts validate again.
$pdo->exec("INSERT INTO campaigns (name, alias, domain_id) VALUES ('C1', 'c1', $track1Id)");
$bad = false;
try {
    $pdo->exec("INSERT INTO campaigns (name, alias, domain_id) VALUES ('C2', 'c2', 99999)");
} catch (PDOException $e) {
    $bad = true;
}
$check('campaigns.domain_id FK enforced against rebuilt domains', true, $bad);

// Half-migrated safety: running the block again (already at 23) must be a no-op.
$schemaVersion = 23;
eval(substr($configSource, $start, $end - $start));
$check('re-run at v23 is a no-op', '3', (string) $pdo->query('SELECT COUNT(*) FROM domains')->fetchColumn());

if ($failures) {
    echo "DOMAINS MIGRATION V23 TEST FAILED\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "DOMAINS MIGRATION V23 TEST PASSED\n";
exit(0);
