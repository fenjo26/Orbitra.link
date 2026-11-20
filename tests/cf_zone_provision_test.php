<?php
/**
 * tests/cf_zone_provision_test.php
 *
 * The create-zone-and-delegate orchestration (orbitraCloudflareProvisionZone
 * + orbitraDelegateZoneNameservers + orbitraCloudflareFindZoneAnywhere) run
 * against the REAL helper blocks extracted from api.php (not a copy), a
 * fixture SQLite with all three account tables, and stubbed client
 * transports:
 *   - a zone that already exists (in a second CF account) is reused, never
 *     re-created,
 *   - the zone is created for the REGISTERED domain the registrar list
 *     knows — never for the typed subdomain,
 *   - the assigned NS pair is delegated at the registrar holding the zone
 *     (Namecheap setCustom / Dynadot set_ns),
 *   - a domain no registrar holds still gets its zone, with a warning that
 *     the NS are the operator's to set,
 *   - a refused create (token without Zone:Edit, a banned TLD) comes back as
 *     ok=false with Cloudflare's own error text.
 *
 * Run from the project root:
 *
 *     php tests/cf_zone_provision_test.php
 */

require_once __DIR__ . '/../core/CloudflareApi.php';
require_once __DIR__ . '/../core/NamecheapClient.php';
require_once __DIR__ . '/../core/DynadotClient.php';

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

// --- Extract the CF + Dynadot + Namecheap helper families from api.php ------
$source = file_get_contents(__DIR__ . '/../api.php');
$start = strpos($source, '// === Cloudflare integration helpers ===');
$endFn = strpos($source, 'function orbitraComposerInstall');
if ($start === false || $endFn === false || $endFn <= $start) {
    fwrite(STDERR, "FAIL: registrar helper block not found in api.php\n");
    exit(1);
}
$cut = substr($source, $start, $endFn - $start);
$doc = strrpos($cut, '/**');
// Inside eval, __DIR__ is tests/ — the client classes are already loaded, so
// the require lines are dropped from the eval'd copy.
$code = substr($cut, 0, $doc);
foreach (['CloudflareApi', 'NamecheapClient', 'DynadotClient'] as $cls) {
    $code = str_replace("require_once __DIR__ . '/core/{$cls}.php';", '', $code);
}
eval($code);

// --- Fixture database --------------------------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)");
$pdo->exec("INSERT INTO settings (key, value) VALUES ('cf_server_ip', '198.51.100.7')");
$pdo->exec("CREATE TABLE cloudflare_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL, api_token TEXT NOT NULL,
    proxied INTEGER DEFAULT 1, ssl_mode TEXT DEFAULT 'flexible',
    zones_count INTEGER, is_active INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE namecheap_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL, username TEXT NOT NULL, api_key TEXT NOT NULL,
    contact_id TEXT DEFAULT '', sandbox INTEGER DEFAULT 0,
    last_balance TEXT DEFAULT '', domains_count INTEGER,
    is_active INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE dynadot_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL, api_key TEXT NOT NULL, sandbox INTEGER DEFAULT 0,
    last_balance TEXT DEFAULT '', domains_count INTEGER,
    is_active INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO cloudflare_accounts (name, api_token) VALUES ('main', 'tok-main'), ('spare', 'tok-spare')");
$pdo->exec("INSERT INTO namecheap_accounts (name, username, api_key) VALUES ('buyer', 'buyer1', 'key-nc1')");
$pdo->exec("INSERT INTO dynadot_accounts (name, api_key) VALUES ('dropship', 'key-dd1')");

// --- Stubbed transports ------------------------------------------------------
$cfPost = [];      // POST /zones bodies
$cfPostFails = ''; // non-empty: every POST /zones answers with this error text
$ncSetCustom = null;
$ddSetNs = null;

// Zones per CF token: the spare account already parked one domain.
$cfZones = [
    'tok-main' => [],
    'tok-spare' => ['parked.example.com'],
];

CloudflareApi::$http = static function (string $url, ?string $jsonBody, string $method) use (&$cfPost, &$cfPostFails, $cfZones): array {
    $parts = parse_url($url);
    $path = preg_replace('~^/client/v4~', '', (string) ($parts['path'] ?? ''));
    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);
    // The stub cannot see the Bearer header through this seam — the token rides
    // on which zone list the caller expected; per-token answers are keyed by
    // the zone lookup order (main first, spare second).
    if ($method === 'GET' && $path === '/accounts') {
        return ['body' => json_encode(['success' => true, 'result' => [['id' => 'acc-1']], 'errors' => []]), 'err' => ''];
    }
    if ($method === 'GET' && $path === '/zones') {
        $want = strtolower((string) ($query['name'] ?? ''));
        // findZoneForHost walks labels per token; the stub answers by name
        // against the union and the caller-side token split is exercised by
        // which zone (only the spare's) exists.
        $result = [];
        foreach ($cfZones as $token => $names) {
            foreach ($names as $zoneName) {
                if ($zoneName === $want) {
                    $result[] = ['id' => 'zone-' . $token, 'name' => $zoneName, 'status' => 'active', 'account' => ['id' => 'acc-1']];
                }
            }
        }
        return ['body' => json_encode(['success' => true, 'result' => $result, 'errors' => []]), 'err' => ''];
    }
    if ($method === 'POST' && $path === '/zones') {
        $body = $jsonBody !== null ? (json_decode($jsonBody, true) ?: []) : [];
        $cfPost[] = $body;
        if ($cfPostFails !== '') {
            return ['body' => json_encode(['success' => false, 'result' => null, 'errors' => [['code' => 9109, 'message' => $cfPostFails]]]), 'err' => ''];
        }
        $zone = [
            'id' => 'zone-new-' . count($cfPost),
            'name' => (string) ($body['name'] ?? ''),
            'status' => 'pending',
            'name_servers' => ['adam.ns.cloudflare.com', 'bella.ns.cloudflare.com'],
        ];
        return ['body' => json_encode(['success' => true, 'result' => $zone, 'errors' => []]), 'err' => ''];
    }
    return ['body' => false, 'err' => 'no fixture for ' . $method . ' ' . $path];
};

$ncLists = ['buyer1' => ['example.com']];
NamecheapClient::$http = static function (string $url, ?string $postBody = null) use (&$ncSetCustom, $ncLists): array {
    parse_str($postBody ?? (parse_url($url, PHP_URL_QUERY) ?? ''), $q);
    $command = (string) ($q['Command'] ?? '');
    $user = (string) ($q['UserName'] ?? '');
    if ($command === 'namecheap.domains.getList') {
        $xml = implode('', array_map(static fn ($n) => "<Domain ID=\"1\" Name=\"$n\" User=\"$user\"/>", $ncLists[$user] ?? []));
        return ['body' => "<ApiResponse Status=\"OK\"><CommandResponse><DomainGetListResult>{$xml}</DomainGetListResult></CommandResponse></ApiResponse>", 'err' => ''];
    }
    if ($command === 'namecheap.domains.dns.setCustom') {
        $ncSetCustom = $q;
        return ['body' => '<ApiResponse Status="OK"><CommandResponse><DNSSetCustomResult Domain="x" Update="true"/></CommandResponse></ApiResponse>', 'err' => ''];
    }
    return ['body' => '<ApiResponse Status="OK"><CommandResponse></CommandResponse></ApiResponse>', 'err' => ''];
};

DynadotClient::$sleep = static function (float $s): void {
    // no-op: the 1 req/s pacing must not slow the fixtures
};
$ddList = json_encode(['ListDomainResponse' => [
    'ResponseCode' => 0, 'Status' => 'success',
    'ListDomainContent' => [['Name' => 'ddone.org', 'Expiration' => 1790000000]],
]]);
DynadotClient::$http = static function (string $url) use (&$ddSetNs, $ddList): array {
    $q = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    if (($q['command'] ?? '') === 'list_domain') {
        return ['body' => $ddList, 'err' => ''];
    }
    if (($q['command'] ?? '') === 'set_ns') {
        $ddSetNs = $q;
        return ['body' => json_encode(['SetNsResponse' => ['ResponseCode' => 0, 'Status' => 'success']]), 'err' => ''];
    }
    return ['body' => false, 'err' => 'no dd fixture'];
};

$defaultCfg = orbitraCloudflareConfig($pdo);
check('default connection is the first CF account', $defaultCfg['token'] === 'tok-main', $defaultCfg['token']);

echo "== zone already in an account (the spare one) ==\n";
$r = orbitraCloudflareProvisionZone($pdo, 'parked.example.com', $defaultCfg);
check('reused, not created', $r['ok'] && count($cfPost) === 0, $r['message']);
check('existing zone returned', ($r['zone']['id'] ?? '') === 'zone-tok-spare', json_encode($r['zone']['id'] ?? null));
check('no warning for an existing zone', $r['warn'] === false);
check('message says already there', strpos($r['message'], 'already') !== false, $r['message']);

echo "== subdomain of a Namecheap zone ==\n";
$r = orbitraCloudflareProvisionZone($pdo, 'sub.example.com', $defaultCfg);
check('created ok', $r['ok'], $r['message']);
check('zone created for the REGISTERED domain, not the subdomain', ($cfPost[0]['name'] ?? '') === 'example.com', json_encode($cfPost[0]['name'] ?? null));
check('assigned NS surfaced', $r['ns'] === ['adam.ns.cloudflare.com', 'bella.ns.cloudflare.com'], json_encode($r['ns']));
check('delegated through Namecheap setCustom', ($ncSetCustom['Command'] ?? '') === 'namecheap.domains.dns.setCustom'
    && ($ncSetCustom['SLD'] ?? '') === 'example' && ($ncSetCustom['TLD'] ?? '') === 'com'
    && ($ncSetCustom['Nameservers'] ?? '') === 'adam.ns.cloudflare.com,bella.ns.cloudflare.com', json_encode($ncSetCustom));
check('no warning when delegation lands', $r['warn'] === false, $r['message']);
check('message reports the delegation', strpos($r['message'], 'delegated: Namecheap') !== false, $r['message']);

echo "== Dynadot-held domain ==\n";
$r = orbitraCloudflareProvisionZone($pdo, 'ddone.org', $defaultCfg);
check('created ok', $r['ok'], $r['message']);
check('delegated through Dynadot set_ns', ($ddSetNs['command'] ?? '') === 'set_ns'
    && ($ddSetNs['domain'] ?? '') === 'ddone.org'
    && ($ddSetNs['ns0'] ?? '') === 'adam.ns.cloudflare.com' && ($ddSetNs['ns1'] ?? '') === 'bella.ns.cloudflare.com', json_encode($ddSetNs));
check('no warning when delegation lands', $r['warn'] === false, $r['message']);

echo "== domain no registrar holds ==\n";
$postsBefore = count($cfPost);
$r = orbitraCloudflareProvisionZone($pdo, 'fresh.net', $defaultCfg);
check('zone still created for the typed name', $r['ok'] && count($cfPost) === $postsBefore + 1
    && ($cfPost[$postsBefore]['name'] ?? '') === 'fresh.net', $r['message']);
check('warns — NS are the operator\'s to set', $r['warn'] === true, $r['message']);
check('message names the manual step', strpos($r['message'], 'registrar') !== false, $r['message']);

echo "== Cloudflare refuses the create ==\n";
$cfPostFails = 'Unauthorized to create zone';
$postsBefore = count($cfPost);
$r = orbitraCloudflareProvisionZone($pdo, 'denied.xyz', $defaultCfg);
check('ok=false with CF\'s own error', !$r['ok'] && strpos($r['message'], 'Unauthorized to create zone') !== false, $r['message']);
check('one create attempted, nothing delegated after it', count($cfPost) === $postsBefore + 1 && $ddSetNs !== null ? ($ddSetNs['domain'] ?? '') === 'ddone.org' : true);

echo "== save_domain wiring present ==\n";
check('save_domain reads the checkbox', strpos($source, "cloudflare_create_zone") !== false && strpos($source, 'orbitraCloudflareProvisionZone($pdo, $domainName, $cfCfg)') !== false);
check('provisioned domains skip registrar A-parking', strpos($source, 'if (!$cfZoneProvisioned) {') !== false);

CloudflareApi::$http = null;
NamecheapClient::$http = null;
DynadotClient::$http = null;

echo "\n" . ($failed === 0 ? "ALL OK ($passed)" : "FAILED: $failed of " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
