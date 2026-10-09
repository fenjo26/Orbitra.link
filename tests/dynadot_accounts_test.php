<?php
/**
 * tests/dynadot_accounts_test.php
 *
 * Multi-account Dynadot wiring, run against the REAL helper block extracted
 * from api.php plus a fixture API3 transport answering per API key:
 *   - a domain parks through whichever account owns its registered zone,
 *   - a subdomain parks as a host of its registered zone,
 *   - one account's domain list is fetched once per request (bulk memo),
 *   - a domain in no account is reported as "not found", nothing written,
 *   - the migration's table shape is what the helpers read.
 *
 *     php tests/dynadot_accounts_test.php
 */

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

$source = file_get_contents(__DIR__ . '/../api.php');
$start = strpos($source, '// === Dynadot integration helpers ===');
$end = strpos($source, '// === Namecheap integration helpers ===');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "FAIL: Dynadot helper block not found in api.php\n");
    exit(1);
}
$code = substr($source, $start, $end - $start);
$code = str_replace("require_once __DIR__ . '/core/DynadotClient.php';", '', $code);

// The helpers take the server IPs from the Namecheap globals.
function orbitraNamecheapGlobals(PDO $pdo): array
{
    return ['server_ip' => '203.0.113.7', 'client_ip' => '203.0.113.7', 'detected_ip' => ''];
}
eval($code);

// Schema exactly as migration 57 creates it.
$config = file_get_contents(__DIR__ . '/../config.php');
preg_match('/CREATE TABLE IF NOT EXISTS dynadot_accounts \((.*?)\)"\);/s', $config, $m);
check('migration 57 present in config.php', !empty($m[1]));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE dynadot_accounts (' . ($m[1] ?? '') . ')');
$pdo->exec("INSERT INTO dynadot_accounts (name, api_key) VALUES ('Buyer A', 'key-a'), ('Buyer B', 'key-b')");
$pdo->exec("INSERT INTO dynadot_accounts (name, api_key, is_active) VALUES ('Off', 'key-off', 0)");

$lists = ['key-a' => ['alpha.com'], 'key-b' => ['beta.net', 'shop.beta.net']];
$calls = [];
$sets = [];
DynadotClient::$sleep = static function (float $s): void {};
DynadotClient::$http = static function (string $url) use (&$calls, &$sets, $lists): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $key = (string) $q['key'];
    $calls[$key][$q['command']] = ($calls[$key][$q['command']] ?? 0) + 1;
    switch ($q['command']) {
        case 'list_domain':
            $rows = array_map(static fn ($n) => ['Name' => $n, 'Expiration' => 1], $lists[$key] ?? []);
            return ['body' => json_encode(['ListDomainInfoResponse' => ['ResponseCode' => 0, 'Status' => 'success', 'MainDomains' => $rows]]), 'err' => ''];
        case 'domain_info':
            return ['body' => json_encode(['DomainInfoResponse' => ['ResponseCode' => 0, 'Status' => 'success',
                'DomainInfo' => ['Name' => $q['domain'], 'NameServerSettings' => ['Type' => 'Dynadot Parking']]]]), 'err' => ''];
        case 'set_dns2':
            $sets[] = $q + ['_key' => $key];
            return ['body' => json_encode(['SetDnsResponse' => ['ResponseCode' => 0, 'Status' => 'success']]), 'err' => ''];
    }
    return ['body' => '', 'err' => 'unexpected ' . $q['command']];
};

echo "\n== accounts ==\n";
$payload = orbitraDynadotAccountsPayload($pdo);
check('only active accounts, no key in payload', count($payload) === 2 && !isset($payload[0]['api_key']), json_encode($payload));
check('default account = first active', (orbitraDynadotCfgForRequest($pdo, [])['api_key'] ?? '') === 'key-a');
check('explicit account_id', (orbitraDynadotCfgForRequest($pdo, ['account_id' => 2])['api_key'] ?? '') === 'key-b');
check('inactive account_id is not served', orbitraDynadotCfgForRequest($pdo, ['account_id' => 3]) === null || orbitraDynadotCfgForRequest($pdo, ['account_id' => 3])['api_key'] !== 'key-off');

echo "\n== parking routes to the owning account ==\n";
$r = orbitraDynadotSyncDomain($pdo, ['id' => 1, 'name' => 'beta.net']);
check('beta.net parked through key-b', $r['ok'] && ($sets[0]['_key'] ?? '') === 'key-b' && ($sets[0]['domain'] ?? '') === 'beta.net', json_encode([$r, $sets]));
$r = orbitraDynadotSyncDomain($pdo, ['id' => 2, 'name' => 'promo.alpha.com']);
$last = end($sets);
check('subdomain parks as host of its zone', $r['ok'] && $last['_key'] === 'key-a' && $last['domain'] === 'alpha.com' && ($last['subdomain0'] ?? '') === 'promo', json_encode($last));
$r = orbitraDynadotSyncDomain($pdo, ['id' => 3, 'name' => 'x.shop.beta.net']);
$last = end($sets);
check('longest registered zone wins', $r['ok'] && $last['domain'] === 'shop.beta.net' && ($last['subdomain0'] ?? '') === 'x', json_encode($last));
$before = count($sets);
$r = orbitraDynadotSyncDomain($pdo, ['id' => 4, 'name' => 'nowhere.org']);
check('unknown domain → not found, nothing written', !$r['ok'] && str_contains($r['message'], 'not found') && count($sets) === $before, $r['message']);
check('each account listed once despite four parkings (memo)', ($calls['key-a']['list_domain'] ?? 0) === 1 && ($calls['key-b']['list_domain'] ?? 0) === 1, json_encode($calls));
check('inactive account never called', !isset($calls['key-off']));

echo "\n== source guards ==\n";
check('save_domain parks new domains through Dynadot', str_contains($source, 'orbitraDynadotSyncDomain($pdo, [\'id\' => $newId'));
check('dynadot pin is validated like namecheap', str_contains($source, "} elseif (strcasecmp(\$dnsProvider, 'dynadot') === 0) {"));
$ra = file_get_contents(__DIR__ . '/../core/resource_access.php');
foreach (['dynadot_account_save', 'dynadot_register_domain', 'dynadot_domains', 'dynadot_sync_domain'] as $action) {
    check("resource scope lists $action", str_contains($ra, "'$action'"));
}

echo "\n" . str_repeat('-', 60) . "\npassed: $passed   failed: $failed\n";
exit($failed === 0 ? 0 : 1);
