<?php
/**
 * tests/keitaro_import_test.php
 *
 * Guards the Keitaro -> Orbitra importer's data-integrity decisions.
 * Run from the project root:
 *
 *     php tests/keitaro_import_test.php
 *
 * Covers the behaviours that used to fail silently and were found by the
 * external security audit (block "import from Keitaro", #9):
 * - reject/accept filter modes must not invert ("reject RU" became "only RU");
 * - the dump parser must not cut an INSERT at the first ';' inside a string
 *   literal (rows after it were dropped);
 * - a deleted/paused Keitaro campaign must not arrive serving traffic;
 * - status404 belongs to the action schema only — a landing stream with a
 *   stray 404 action type must stay a landing stream;
 * - direct-URL streams, show_html/show_text content and "pass to campaign"
 *   targets travel from the stream's action_payload (previously ignored, so
 *   such streams arrived empty and died with "URL not found." at runtime).
 *
 * The pure-function sections run anywhere; the end-to-end section needs
 * pdo_sqlite and is skipped (not failed) without it.
 */

require_once __DIR__ . '/../core/keitaro_import.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

echo "\n== filter mode mapping (c) ==\n";

check('reject → exclude', orbitraKeitaroMapFilterMode('reject') === 'exclude');
check('REJECT (case/space) → exclude', orbitraKeitaroMapFilterMode(' REJECT ') === 'exclude');
check('accept → include', orbitraKeitaroMapFilterMode('accept') === 'include');
check('exclude → exclude', orbitraKeitaroMapFilterMode('exclude') === 'exclude');
check('not_in → exclude', orbitraKeitaroMapFilterMode('not_in') === 'exclude');
check('notin → exclude', orbitraKeitaroMapFilterMode('notin') === 'exclude');
check('!= → exclude', orbitraKeitaroMapFilterMode('!=') === 'exclude');
check('in → include', orbitraKeitaroMapFilterMode('in') === 'include');
check('= → include', orbitraKeitaroMapFilterMode('=') === 'include');
check('unknown falls back to include', orbitraKeitaroMapFilterMode('weird') === 'include');

echo "\n== dump parser: ';' inside string literals (f) ==\n";

$dump = <<<SQL
CREATE TABLE `keitaro_streams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) DEFAULT NULL,
  `action_payload` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

INSERT INTO `keitaro_streams` VALUES (1,10,'<a href="/x;a=b">go</a>; done'),(2,10,'a'';b''c'),(3,10,'line
break; ''quoted'''),(4,10,NULL);
SQL;
$dumpFile = tempnam(sys_get_temp_dir(), 'keitaro-import-test');
file_put_contents($dumpFile, $dump);
$parsed = orbitraKeitaroParseSqlDump($dumpFile, ['keitaro_streams']);
$rows = $parsed['keitaro_streams']['rows'];

check('no rows lost to semicolons inside literals', count($rows) === 4, 'got ' . count($rows));
check('html payload with ";" intact', ($rows[0]['action_payload'] ?? '') === '<a href="/x;a=b">go</a>; done', var_export($rows[0]['action_payload'] ?? null, true));
check("doubled-quote payload ('') intact", ($rows[1]['action_payload'] ?? '') === "a';b'c", var_export($rows[1]['action_payload'] ?? null, true));
check('newline inside literal intact', ($rows[2]['action_payload'] ?? '') === "line\nbreak; 'quoted'", var_export($rows[2]['action_payload'] ?? null, true));
check('NULL literal stays NULL', $rows[3]['action_payload'] === null, var_export($rows[3]['action_payload'] ?? 'missing', true));
unlink($dumpFile);

echo "\n== campaign status mapping (a) ==\n";

$m = orbitraKeitaroMapCampaignStatus(['status' => 'deleted']);
check('deleted → archived with a timestamp',
    $m['is_archived'] === 1 && is_string($m['archived_at']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $m['archived_at']) === 1,
    json_encode($m));
$m = orbitraKeitaroMapCampaignStatus(['status' => 'disabled']);
check('status=disabled → paused, not archived', $m['state'] === 'disabled' && $m['is_archived'] === 0, json_encode($m));
$m = orbitraKeitaroMapCampaignStatus(['status' => 'paused']);
check('status=paused → paused', $m['state'] === 'disabled' && $m['is_archived'] === 0, json_encode($m));
$m = orbitraKeitaroMapCampaignStatus(['status' => 'active', 'state' => 'disabled']);
check('state=disabled → paused', $m['state'] === 'disabled' && $m['is_archived'] === 0, json_encode($m));
$m = orbitraKeitaroMapCampaignStatus(['status' => 'active']);
check('active → defaults', $m === ['state' => 'active', 'is_archived' => 0, 'archived_at' => null], json_encode($m));
$m = orbitraKeitaroMapCampaignStatus([]);
check('rows without the columns degrade to defaults', $m === ['state' => 'active', 'is_archived' => 0, 'archived_at' => null], json_encode($m));

// The dropped-filter counter is what the import report quotes verbatim.
echo "\n== dropped filter counting (d) ==\n";

$dropped = [];
$filters = orbitraKeitaroBuildOrbitraFilters([
    ['type' => 'uniqueness', 'mode' => 'accept', 'payload' => 'ip_ua'],
    ['type' => 'sub_id_8', 'mode' => 'accept', 'value' => 'abc'],
    ['type' => 'uniqueness', 'mode' => 'reject', 'payload' => 'ip'],
    ['type' => 'country', 'mode' => 'reject', 'value' => '["RU"]'],
], $dropped);
$byName = [];
foreach ($filters as $f) {
    $byName[$f['name']] = $f;
}
check('country reject still maps to exclude', ($byName['Country']['mode'] ?? '') === 'exclude', json_encode($byName));
check('uniqueness ×2 counted', ($dropped['uniqueness'] ?? 0) === 2, json_encode($dropped));
check('sub_id_8 ×1 counted', ($dropped['sub_id_8'] ?? 0) === 1, json_encode($dropped));
check('mapped filters are not counted as dropped', !isset($dropped['country']), json_encode($dropped));

// "Pass to campaign" resolution: mapped, db-known, and missing targets.
echo "\n== to_campaign target resolution (b) ==\n";

$resultBox = ['warnings' => []];
$map = ['31' => 140];
$dbMap = ['32' => 141];
check('imported target resolves',
    orbitraKeitaroResolveCampaignTargetPayload(500, 31, $map, $dbMap, $resultBox) === 'to_campaign:140');
check('previously imported target resolves via keitaro_id',
    orbitraKeitaroResolveCampaignTargetPayload(501, 32, $map, $dbMap, $resultBox) === 'to_campaign:141'
    && ($map['32'] ?? 0) === 141);
check('missing target degrades to do_nothing + warning',
    orbitraKeitaroResolveCampaignTargetPayload(502, 77, $map, $dbMap, $resultBox) === 'do_nothing'
    && count($resultBox['warnings']) === 1
    && str_contains($resultBox['warnings'][0], 'Stream 502'));

// End-to-end on a minimal in-memory schema: schema=action vs everything else.
echo "\n== stream schema mapping end-to-end (b, e) ==\n";

if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "  skip end-to-end sections (pdo_sqlite not available)\n";
} else {
    $campaignsDdl = "CREATE TABLE campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL, alias TEXT NOT NULL UNIQUE, domain_id INTEGER, group_id INTEGER, source_id INTEGER,
        cost_model TEXT DEFAULT 'CPC', cost_value REAL DEFAULT 0, uniqueness_method TEXT DEFAULT 'IP',
        uniqueness_hours INTEGER DEFAULT 24, rotation_type TEXT DEFAULT 'position', token TEXT, catch_404_stream_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, is_archived INTEGER DEFAULT 0, archived_at DATETIME,
        state TEXT DEFAULT 'active', keitaro_id INTEGER)";
    $domainsDdl = "CREATE TABLE domains (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, index_campaign_id INTEGER,
        catch_404 INTEGER, group_id INTEGER, is_noindex INTEGER, https_only INTEGER, keitaro_id INTEGER)";
    $groupsDdl = "CREATE TABLE campaign_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)";
    // Importers for entities this test does not exercise are switched off, so
    // only the tables above are needed.
    $importOpts = static fn(array $extra = []): array => array_merge([
        'import_campaigns' => true,
        'import_companies' => false,
        'import_offers' => false,
        'import_domains' => false,
    ], $extra);

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec($campaignsDdl);
    $pdo->exec($domainsDdl);
    $pdo->exec($groupsDdl);
    $pdo->exec("CREATE TABLE streams (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER NOT NULL, offer_id INTEGER,
        name TEXT, weight INTEGER DEFAULT 100, is_active INTEGER DEFAULT 1, type TEXT DEFAULT 'regular',
        position INTEGER DEFAULT 0, filters_json TEXT, filters_logic TEXT DEFAULT 'and',
        schema_type TEXT DEFAULT 'redirect', action_payload TEXT, schema_custom_json TEXT,
        offer_selection TEXT DEFAULT 'before', collect_clicks INTEGER DEFAULT 1, keitaro_id INTEGER)");

    $streamsDump = "CREATE TABLE `keitaro_streams` (\n"
        . "  `id` int(11) NOT NULL AUTO_INCREMENT,\n"
        . "  `campaign_id` int(11) DEFAULT NULL,\n"
        . "  `name` varchar(150) DEFAULT NULL,\n"
        . "  `schema` varchar(20) DEFAULT NULL,\n"
        . "  `action_type` varchar(50) DEFAULT NULL,\n"
        . "  `action_payload` text,\n"
        . "  `state` varchar(20) DEFAULT 'active',\n"
        . "  `type` varchar(20) DEFAULT 'regular',\n"
        . "  `position` int(11) DEFAULT '1',\n"
        . "  PRIMARY KEY (`id`)\n"
        . ") ENGINE=InnoDB;"
        . "INSERT INTO `keitaro_streams` VALUES "
        // direct URL, plain 302
        . "(201,21,'direct','redirect','http','https://aff.example/landing?sub={subid}','active','regular',1),"
        // direct URL via meta refresh, and the double-meta simplification
        . "(202,21,'metaredir','redirect','meta','https://aff.example/meta','active','regular',2),"
        . "(203,21,'doublemeta','redirect','double_meta','https://aff.example/double','active','regular',3),"
        // actions with content
        . "(204,21,'html','action','show_html','<h1>Hi; stay awhile</h1>','active','regular',4),"
        . "(205,21,'text','action','show_text','plain; text','active','regular',5),"
        . "(206,21,'fourohfour','action','status404',NULL,'active','regular',6),"
        // pass to campaign: resolvable and not
        . "(207,21,'pass','campaign','to_campaign','22','active','regular',7),"
        . "(208,21,'ghost','campaign','to_campaign','999','active','regular',8),"
        // the audit's (e) case: a 404 action type on a NON-action schema
        . "(209,21,'landing404','landings','status404',NULL,'active','regular',9);";
    $campaignsDump = "CREATE TABLE `keitaro_campaigns` (\n"
        . "  `id` int(11) NOT NULL,\n"
        . "  `name` varchar(150) DEFAULT NULL,\n"
        . "  `alias` varchar(150) DEFAULT NULL,\n"
        . "  `status` varchar(20) DEFAULT 'active',\n"
        . "  `token` varchar(150) DEFAULT NULL,\n"
        . "  PRIMARY KEY (`id`)\n"
        . ") ENGINE=InnoDB;"
        . "INSERT INTO `keitaro_campaigns` VALUES (21,'Main','main','active','tok'),(22,'Other','other','active','tok2');";

    $dumpFile2 = tempnam(sys_get_temp_dir(), 'keitaro-import-test');
    file_put_contents($dumpFile2, $streamsDump . "\n" . $campaignsDump);
    $res = orbitraKeitaroImportSqlDump($pdo, $dumpFile2, $importOpts(['import_streams' => true]));

    $streams = $pdo->query("SELECT name, schema_type, action_payload, schema_custom_json FROM streams ORDER BY position")
        ->fetchAll(PDO::FETCH_ASSOC);
    $byName = [];
    foreach ($streams as $s) {
        $byName[$s['name']] = $s;
    }
    $customOf = static fn(string $name): array => json_decode((string) ($byName[$name]['schema_custom_json'] ?? '{}'), true) ?: [];

    check('redirect schema → direct_url destination',
        ($customOf('direct')['direct_url'] ?? '') === 'https://aff.example/landing?sub={subid}'
        && ($customOf('direct')['redirect_mode'] ?? '') === 'direct_url',
        json_encode($byName['direct'] ?? null));
    check('http action_type stays a plain redirect', ($customOf('direct')['redirect_type'] ?? 'redirect') === 'redirect');
    check('meta action_type → meta_refresh', ($customOf('metaredir')['redirect_type'] ?? '') === 'meta_refresh', json_encode($byName['metaredir'] ?? null));
    check('double_meta → meta_refresh (no double meta in Orbitra)', ($customOf('doublemeta')['redirect_type'] ?? '') === 'meta_refresh', json_encode($byName['doublemeta'] ?? null));
    check('show_html keeps its content as type:payload',
        ($byName['html']['schema_type'] ?? '') === 'action' && ($byName['html']['action_payload'] ?? '') === 'show_html:<h1>Hi; stay awhile</h1>',
        json_encode($byName['html'] ?? null));
    check('show_text keeps its content',
        ($byName['text']['schema_type'] ?? '') === 'action' && ($byName['text']['action_payload'] ?? '') === 'show_text:plain; text',
        json_encode($byName['text'] ?? null));
    check('status404 under schema=action → not_found',
        ($byName['fourohfour']['schema_type'] ?? '') === 'action' && ($byName['fourohfour']['action_payload'] ?? '') === 'not_found',
        json_encode($byName['fourohfour'] ?? null));
    $orbitraTarget = (int) $pdo->query("SELECT id FROM campaigns WHERE alias = 'other'")->fetchColumn();
    check('campaign schema → to_campaign with the mapped Orbitra id',
        ($byName['pass']['schema_type'] ?? '') === 'action' && ($byName['pass']['action_payload'] ?? '') === 'to_campaign:' . $orbitraTarget,
        json_encode($byName['pass'] ?? null));
    check('unresolvable campaign target → do_nothing + warning',
        ($byName['ghost']['action_payload'] ?? '') === 'do_nothing'
        && count(array_filter($res['warnings'], static fn($w) => str_contains($w, 'Stream 208'))) === 1,
        json_encode($byName['ghost'] ?? null));
    check('status404 under schema=landings does NOT become a 404 action',
        ($byName['landing404']['schema_type'] ?? '') === 'redirect' && ($byName['landing404']['action_payload'] ?? null) === null,
        json_encode($byName['landing404'] ?? null));
    check('double_meta simplification surfaces in the report warnings',
        count(array_filter($res['warnings'], static fn($w) => str_contains($w, 'double_meta redirect imported as meta_refresh'))) === 1,
        implode(' | ', $res['warnings']));

    // Deleted/paused campaigns must arrive archived/disabled, and a re-import
    // must not die on the alias UNIQUE index because of archived rows.
    $campaignsDump2 = "CREATE TABLE `keitaro_campaigns` (\n"
        . "  `id` int(11) NOT NULL,\n"
        . "  `name` varchar(150) DEFAULT NULL,\n"
        . "  `alias` varchar(150) DEFAULT NULL,\n"
        . "  `status` varchar(20) DEFAULT 'active',\n"
        . "  `token` varchar(150) DEFAULT NULL,\n"
        . "  PRIMARY KEY (`id`)\n"
        . ") ENGINE=InnoDB;"
        . "INSERT INTO `keitaro_campaigns` VALUES "
        . "(31,'Live','live','active',NULL),"
        . "(32,'Dead','dead','deleted',NULL),"
        . "(33,'Paused','paused','disabled',NULL);";
    file_put_contents($dumpFile2, $campaignsDump2);
    orbitraKeitaroImportSqlDump($pdo, $dumpFile2, $importOpts());
    $campaignRow = static function (string $alias) use ($pdo): array {
        $st = $pdo->prepare("SELECT * FROM campaigns WHERE alias = ?");
        $st->execute([$alias]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    check('active campaign imports live',
        ($campaignRow('live')['state'] ?? '') === 'active' && (int) ($campaignRow('live')['is_archived'] ?? 1) === 0);
    check('deleted campaign arrives archived',
        (int) ($campaignRow('dead')['is_archived'] ?? 0) === 1 && ($campaignRow('dead')['archived_at'] ?? '') !== '');
    check('disabled campaign arrives paused',
        ($campaignRow('paused')['state'] ?? '') === 'disabled' && (int) ($campaignRow('paused')['is_archived'] ?? 1) === 0);
    $importTwice = true;
    try {
        orbitraKeitaroImportSqlDump($pdo, $dumpFile2, $importOpts());
    } catch (Throwable $e) {
        $importTwice = false;
    }
    check('re-import is idempotent (archived rows are found by alias)', $importTwice
        && (int) $pdo->query("SELECT COUNT(*) FROM campaigns WHERE alias IN ('live','dead','paused')")->fetchColumn() === 3);

    // preserve_campaign_ids must survive migration 47's seeded system campaign.
    $pdo2 = new PDO('sqlite::memory:');
    $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo2->exec($campaignsDdl);
    $pdo2->exec($domainsDdl);
    $pdo2->exec($groupsDdl);
    $pdo2->exec("INSERT INTO campaigns (name, alias, token, is_archived, archived_at, state)
        VALUES ('PWA organic (system)', 'orbitra-pwa-organic', 'organic', 1, datetime('now'), 'active')");
    orbitraKeitaroImportSqlDump($pdo2, $dumpFile2, $importOpts(['preserve_campaign_ids' => true]));
    $ids = $pdo2->query("SELECT id, alias FROM campaigns ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check('preserve mode: only the system campaign is cleared, Keitaro ids kept',
        count($ids) === 3 && (int) $ids[0]['id'] === 31 && $ids[0]['alias'] === 'live',
        json_encode($ids));

    unlink($dumpFile2);
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "passed: $passed   failed: $failed\n";
exit($failed === 0 ? 0 : 1);
