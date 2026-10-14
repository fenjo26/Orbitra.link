#!/usr/bin/php
<?php
/**
 * Add an intercepting "Antibot" stream (filter Bot = yes → 404) to every
 * campaign that does not have one yet.
 *
 * Why: campaigns imported from Keitaro usually carry only a regular geo stream
 * plus a "Bots" FALLBACK stream. A fallback only catches what no regular stream
 * took, so a datacenter visitor from an allowed country (DigitalOcean NL, Google
 * Cloud DE…) matches the geo stream and reaches the offer. An intercepting
 * stream is evaluated before every regular one, whatever its position.
 *
 * The script only INSERTs new stream rows. Existing streams, their filters,
 * offers, weights and click history are never updated or deleted.
 *
 *     sudo -u www-data php /var/www/orbitra/cli/add_antibot_stream.php            dry run (default)
 *     sudo -u www-data php /var/www/orbitra/cli/add_antibot_stream.php --apply    write
 *     sudo -u www-data php /var/www/orbitra/cli/add_antibot_stream.php --rollback=var/backup/antibot_streams.<ts>.json
 *
 * Run it as www-data: the SQLite WAL/SHM files must stay writable by php-fpm.
 *
 * --apply first writes a full copy of the database (VACUUM INTO) and a JSON
 * list of the stream ids it inserted; --rollback deletes exactly those ids.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));
require_once __DIR__ . '/../config.php';

$apply = in_array('--apply', $argv, true);
$rollbackFile = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--rollback=') === 0) {
        $rollbackFile = substr($arg, strlen('--rollback='));
    }
}

const ANTIBOT_NAME = 'Antibot';
const ANTIBOT_FILTERS = [['mode' => 'include', 'name' => 'Bot', 'payload' => ['yes']]];

/** Does a stream row already intercept on the Bot filter? */
function hasBotIntercept(array $stream): bool
{
    if (($stream['type'] ?? '') !== 'intercepting' || (int) ($stream['is_active'] ?? 1) !== 1) {
        return false;
    }
    $filters = json_decode((string) ($stream['filters_json'] ?? '[]'), true);
    if (!is_array($filters)) {
        return false;
    }
    foreach ($filters as $f) {
        if (!is_array($f) || strcasecmp((string) ($f['name'] ?? ''), 'Bot') !== 0) {
            continue;
        }
        if (($f['mode'] ?? 'include') !== 'include') {
            continue;
        }
        $payload = array_map(fn($v) => strtolower((string) $v), (array) ($f['payload'] ?? []));
        if (in_array('yes', $payload, true)) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------- rollback
if ($rollbackFile !== null) {
    $ids = json_decode((string) @file_get_contents($rollbackFile), true);
    if (!is_array($ids) || !isset($ids['stream_ids']) || !is_array($ids['stream_ids'])) {
        fwrite(STDERR, "Cannot read stream ids from $rollbackFile\n");
        exit(1);
    }
    $del = $pdo->prepare("DELETE FROM streams WHERE id = ? AND name = ? AND type = 'intercepting'");
    $pdo->beginTransaction();
    $n = 0;
    foreach ($ids['stream_ids'] as $sid) {
        $del->execute([(int) $sid, ANTIBOT_NAME]);
        $n += $del->rowCount();
    }
    $pdo->commit();
    echo "Rolled back: $n Antibot stream(s) deleted.\n";
    exit(0);
}

// ------------------------------------------------------------------ survey
$streamCols = array_column($pdo->query('PRAGMA table_info(streams)')->fetchAll(PDO::FETCH_ASSOC), 'name');

$campaigns = $pdo->query('SELECT id, name FROM campaigns WHERE is_archived = 0 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$streamStmt = $pdo->prepare('SELECT * FROM streams WHERE campaign_id = ?');

$todo = [];
$skipped = 0;
foreach ($campaigns as $c) {
    $streamStmt->execute([(int) $c['id']]);
    $streams = $streamStmt->fetchAll(PDO::FETCH_ASSOC);
    $covered = false;
    foreach ($streams as $s) {
        if (hasBotIntercept($s)) {
            $covered = true;
            break;
        }
    }
    if ($covered) {
        $skipped++;
    } else {
        $todo[] = $c;
    }
}

echo count($campaigns) . " active campaigns: $skipped already have a Bot intercepting stream, "
    . count($todo) . " will get one.\n";
foreach ($todo as $c) {
    echo "  #{$c['id']}  {$c['name']}\n";
}

if (!$apply) {
    echo "\nDry run — nothing written. Re-run with --apply to add the streams.\n";
    exit(0);
}
if (!$todo) {
    echo "Nothing to do.\n";
    exit(0);
}

// ------------------------------------------------------------------- apply
$backupDir = __DIR__ . '/../var/backup';
if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true)) {
    fwrite(STDERR, "Cannot create $backupDir\n");
    exit(1);
}
$ts = date('Ymd_His');
$dbCopy = realpath($backupDir) . "/orbitra_db.before_antibot.$ts.sqlite";
try {
    $pdo->exec('VACUUM INTO ' . $pdo->quote($dbCopy));
} catch (Throwable $e) {
    fwrite(STDERR, "Backup failed, nothing written: " . $e->getMessage() . "\n");
    exit(1);
}
echo "\nBackup: $dbCopy\n";

$row = [
    'offer_id' => null,
    'name' => ANTIBOT_NAME,
    'weight' => 100,
    'is_active' => 1,
    'type' => 'intercepting',
    'position' => 0,
    'filters_json' => json_encode(ANTIBOT_FILTERS),
    'filters_logic' => 'or',
    'schema_type' => 'action',
    'action_payload' => 'not_found',
    'schema_custom_json' => '[]',
    'offer_selection' => 'before',
    'collect_clicks' => 1,
];
// Only the columns this install's schema actually has.
$row = array_intersect_key($row, array_flip($streamCols));
$cols = array_merge(['campaign_id'], array_keys($row));
$insert = $pdo->prepare(
    'INSERT INTO streams (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
);

$inserted = [];
$pdo->beginTransaction();
try {
    foreach ($todo as $c) {
        $insert->execute(array_merge([(int) $c['id']], array_values($row)));
        $inserted[] = (int) $pdo->lastInsertId();
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Failed, nothing written: " . $e->getMessage() . "\n");
    exit(1);
}

$idsFile = realpath($backupDir) . "/antibot_streams.$ts.json";
file_put_contents($idsFile, json_encode(['created_at' => date('c'), 'stream_ids' => $inserted], JSON_PRETTY_PRINT));

echo "Added " . count($inserted) . " Antibot stream(s).\n";
echo "Undo: sudo -u www-data php " . __FILE__ . " --rollback=$idsFile\n";
