<?php
/**
 * tests/namecheap_accounts_test.php
 *
 * Multi-account Namecheap wiring, run against the REAL helper block extracted
 * from api.php (not a copy) plus a fixture HTTP transport that answers per
 * account (ApiUser):
 *   - the default connection is the first active account,
 *   - a domain parks through whichever account owns its registered zone,
 *   - one account's domain list is fetched once per request even when several
 *     domains are parked in a row (the bulk-import memo),
 *   - a no-accounts install falls back to the legacy settings connection.
 *
 * Run from the project root:
 *
 *     php tests/namecheap_accounts_test.php
 */

require_once __DIR__ . '/../core/NamecheapClient.php';

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

// --- Extract the helper family from api.php ----------------------------------
$source = file_get_contents(__DIR__ . '/../api.php');
$start = strpos($source, '// === Namecheap integration helpers ===');
$endFn = strpos($source, 'function orbitraComposerInstall');
if ($start === false || $endFn === false || $endFn <= $start) {
    fwrite(STDERR, "FAIL: Namecheap helper block not found in api.php\n");
    exit(1);
}
$cut = substr($source, $start, $endFn - $start);
$doc = strrpos($cut, '/**');
// The block's require_once uses __DIR__ of api.php — inside eval that points
// at tests/. The class is already loaded at the top of this file, so the
// require line is dropped from both copies.
$code = str_replace("require_once __DIR__ . '/core/NamecheapClient.php';", '', substr($cut, 0, $doc));
eval($code);
// The helper family memoizes per process (static caches), so the legacy
// scenario below re-evals a renamed copy instead of reusing the first one.
eval(str_replace('orbitraNamecheap', 'orbitraNcLegacy', $code));

// --- Fixture transport: per-account domain lists ------------------------------
$apiCalls = []; // [username][command] => count
$capturedSetHosts = [];
$domainLists = [
    'buyer1' => ['example.com', 'shop.example.com'],
    'buyer2' => ['other.org'],
    'legacyowner' => ['legacy.com'],
];
NamecheapClient::$http = function (string $url) use (&$apiCalls, &$capturedSetHosts, $domainLists): array {
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
    $user = (string) ($q['UserName'] ?? '?');
    $command = (string) ($q['Command'] ?? '');
    $apiCalls[$user][$command] = ($apiCalls[$user][$command] ?? 0) + 1;

    if ($command === 'namecheap.domains.getList') {
        $names = $domainLists[$user] ?? [];
        $domainXml = implode('', array_map(static fn ($n) => "<Domain ID=\"1\" Name=\"$n\" User=\"$user\"/>", $names));
        return ['body' => "<ApiResponse Status=\"OK\"><CommandResponse><DomainGetListResult>{$domainXml}</DomainGetListResult></CommandResponse></ApiResponse>", 'err' => ''];
    }
    if ($command === 'namecheap.domains.dns.getHosts') {
        return ['body' => '<ApiResponse Status="OK"><CommandResponse><DomainDNSGetHostsResult IsUsingOurDNS="true" HostCount="0" EmailType="NONE"/></CommandResponse></ApiResponse>', 'err' => ''];
    }
    if ($command === 'namecheap.domains.dns.setHosts') {
        $capturedSetHosts[] = $q;
        return ['body' => '<ApiResponse Status="OK"><CommandResponse><DomainDNSSetHostsResult IsSuccess="true"/></CommandResponse></ApiResponse>', 'err' => ''];
    }
    return ['body' => '<ApiResponse Status="OK"><CommandResponse></CommandResponse></ApiResponse>', 'err' => ''];
};

$makePdo = static function (array $accounts, array $settings = []): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
    $pdo->exec("CREATE TABLE namecheap_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL, username TEXT NOT NULL, api_key TEXT NOT NULL,
        contact_id TEXT DEFAULT '', sandbox INTEGER DEFAULT 0,
        last_balance TEXT DEFAULT '', domains_count INTEGER,
        is_active INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $ins = $pdo->prepare("INSERT INTO namecheap_accounts (name, username, api_key) VALUES (?, ?, ?)");
    foreach ($accounts as $i => $username) {
        $ins->execute([$username, $username, 'key-' . $username]);
    }
    $set = $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?)");
    foreach ($settings as $k => $v) {
        $set->execute([$k, $v]);
    }
    return $pdo;
};

echo "== default connection ==\n";
$pdoA = $makePdo(['buyer1', 'buyer2'], ['nc_server_ip' => '198.51.100.7', 'nc_detected_ip' => '87.232.72.54']);
$cfg = orbitraNamecheapConfig($pdoA);
check('default = first active account', $cfg['username'] === 'buyer1' && $cfg['account_id'] === 1, json_encode([$cfg['username'] ?? null, $cfg['account_id'] ?? null]));
check('globals merged into account cfg', $cfg['server_ip'] === '198.51.100.7' && $cfg['client_ip'] === '87.232.72.54', json_encode([$cfg['server_ip'], $cfg['client_ip']]));

echo "== multi-account parking fan-out ==\n";
$sync1 = orbitraNamecheapSyncDomain($pdoA, ['id' => 1, 'name' => 'tracker.example.com']);
check('buyer1 domain parks', $sync1['ok'], $sync1['message']);
check('parked through buyer1', ($capturedSetHosts[0]['UserName'] ?? '') === 'buyer1', $capturedSetHosts[0]['UserName'] ?? 'none');
check('A record written to the server IP', ($capturedSetHosts[0]['Address1'] ?? '') === '198.51.100.7', $capturedSetHosts[0]['Address1'] ?? 'none');

$sync2 = orbitraNamecheapSyncDomain($pdoA, ['id' => 2, 'name' => 'campaign.other.org']);
check('buyer2 domain parks', $sync2['ok'], $sync2['message']);
check('parked through buyer2', ($capturedSetHosts[1]['UserName'] ?? '') === 'buyer2', $capturedSetHosts[1]['UserName'] ?? 'none');

$sync3 = orbitraNamecheapSyncDomain($pdoA, ['id' => 3, 'name' => 'unknown.net']);
check('unknown domain is not an API write', !$sync3['ok'] && strpos($sync3['message'], 'not found') !== false, $sync3['message']);

echo "== per-request list memo ==\n";
$apiCalls = [];
orbitraNamecheapSyncDomain($pdoA, ['id' => 4, 'name' => 'a.example.com']);
orbitraNamecheapSyncDomain($pdoA, ['id' => 5, 'name' => 'b.example.com']);
orbitraNamecheapSyncDomain($pdoA, ['id' => 6, 'name' => 'deep.shop.example.com']);
// buyer1's list was already fetched by the fan-out section above; three more
// parks of buyer1 zones must be served from the memo (zero getList calls) —
// a broken memo would re-list the account for every domain.
check('bulk import lists each account once', ($apiCalls['buyer1']['namecheap.domains.getList'] ?? 0) === 0, json_encode($apiCalls));

echo "== legacy fallback (no account rows) ==\n";
$pdoL = $makePdo([], ['nc_api_key' => 'legacy-key', 'nc_username' => 'legacyowner', 'nc_server_ip' => '198.51.100.7']);
$cfgL = orbitraNcLegacyConfig($pdoL);
check('legacy cfg from settings', $cfgL['username'] === 'legacyowner' && $cfgL['api_key'] === 'legacy-key', json_encode([$cfgL['username'] ?? null]));
$syncL = orbitraNcLegacySyncDomain($pdoL, ['id' => 9, 'name' => 'legacy.com']);
check('legacy connection still parks', $syncL['ok'], $syncL['message']);
check('legacy parks via legacyowner', end($capturedSetHosts)['UserName'] === 'legacyowner', end($capturedSetHosts)['UserName'] ?? 'none');

echo "== payload never leaks the api_key ==\n";
$payload = print_r(orbitraNamecheapAccountsPayload($pdoA), true);
check('accounts payload has no api_key', strpos($payload, 'key-buyer') === false && strpos($payload, 'api_key') === false);

echo "== source guards on api.php ==\n";
foreach (['namecheap_accounts_list', 'namecheap_account_save', 'namecheap_account_delete', 'namecheap_account_balance'] as $action) {
    check("action '$action' exists", strpos($source, "case '$action':") !== false);
}
check('auto-park passes a null pin when the domain has none (loop mode)', strpos($source, "orbitraNamecheapSyncDomain(\$pdo, ['id' => \$newId, 'name' => \$domainName], \$ncPinCfg)") !== false && strpos($source, '$ncPinCfg = null;') !== false);
check('account-aware endpoints resolve account_id', strpos($source, 'orbitraNamecheapCfgForRequest($pdo') !== false);

NamecheapClient::$http = null;

echo "\n" . ($failed === 0 ? "ALL OK ($passed)" : "FAILED: $failed of " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
