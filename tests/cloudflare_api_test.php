<?php
/**
 * tests/cloudflare_api_test.php
 *
 * CloudflareApi::createZone against a stubbed v4 transport — no network is
 * touched: the HTTP hook (the same seam the Namecheap/Dynadot clients expose)
 * answers from per-path fixtures. The point is the zone-create contract:
 * account.id resolution (GET /accounts, then an existing zone), the POST body
 * (type=full, no jump_start) and the NS pair lifted out of the response.
 *
 * Run from the project root:
 *
 *     php tests/cloudflare_api_test.php
 */

require_once __DIR__ . '/../core/CloudflareApi.php';

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

/** @var array<int,array{method:string,path:string,query:array,body:?array}> $log */
$log = [];
/** @var array<string,callable> $routes "METHOD path" => fn(query, body) => array (CF envelope) */
$routes = [];

CloudflareApi::$http = static function (string $url, ?string $jsonBody, string $method) use (&$log, &$routes): array {
    $parts = parse_url($url);
    $path = (string) ($parts['path'] ?? '');
    // Strip the /client/v4 prefix so routes read like the API reference.
    $path = preg_replace('~^/client/v4~', '', $path) ?? $path;
    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);
    $body = $jsonBody !== null ? (json_decode($jsonBody, true) ?: null) : null;
    $log[] = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => $body];
    $handler = $routes[$method . ' ' . $path] ?? null;
    if (!is_callable($handler)) {
        return ['body' => false, 'err' => 'no fixture for ' . $method . ' ' . $path];
    }
    $envelope = $handler($query, $body);
    return ['body' => json_encode($envelope, JSON_UNESCAPED_UNICODE), 'err' => ''];
};

$zoneEnvelope = static fn (array $zone): array => ['success' => true, 'result' => $zone, 'errors' => []];

echo "== createZone: account id from /accounts ==\n";
$routes['GET /accounts'] = static fn () => ['success' => true, 'result' => [['id' => 'acc-123', 'name' => 'Main']], 'errors' => []];
$routes['POST /zones'] = static function (array $q, ?array $body) use ($zoneEnvelope): array {
    if (($body['name'] ?? '') !== 'fresh.xyz') {
        return ['success' => false, 'result' => null, 'errors' => [['code' => 1061, 'message' => 'zone already exists']]];
    }
    return $zoneEnvelope([
        'id' => 'zone-789',
        'name' => 'fresh.xyz',
        'status' => 'pending',
        'name_servers' => ['adam.ns.cloudflare.com', 'bella.ns.cloudflare.com'],
    ]);
};
$created = CloudflareApi::createZone('tok-1', ' Fresh.XYZ. ');
check('created ok', $created['ok'], $created['message']);
check('zone payload with id', ($created['zone']['id'] ?? '') === 'zone-789');
check('NS pair extracted lowercased', $created['ns'] === ['adam.ns.cloudflare.com', 'bella.ns.cloudflare.com'], json_encode($created['ns']));
$post = null;
foreach ($log as $entry) {
    if ($entry['method'] === 'POST' && $entry['path'] === '/zones') {
        $post = $entry;
    }
}
check('POST body: name, type=full, jump_start off', ($post['body']['name'] ?? '') === 'fresh.xyz'
    && ($post['body']['type'] ?? '') === 'full'
    && ($post['body']['jump_start'] ?? null) === false, json_encode($post['body'] ?? null));
check('POST body carries the /accounts id', (($post['body']['account']['id'] ?? '') === 'acc-123'), json_encode($post['body']['account'] ?? null));

echo "== createZone: account id via an existing zone when /accounts is closed ==\n";
$log = [];
$routes['GET /accounts'] = static fn () => ['success' => false, 'result' => null, 'errors' => [['code' => 9109, 'message' => 'Unauthorized']]];
$routes['GET /zones'] = static fn (): array => [
    'success' => true,
    'errors' => [],
    'result' => [
        ['id' => 'zone-1', 'name' => 'other.test', 'account' => ['id' => 'acc-from-zone']],
    ],
];
$routes['POST /zones'] = static function () use ($zoneEnvelope): array {
    return $zoneEnvelope(['id' => 'zone-2', 'name' => 'fresh2.xyz', 'status' => 'pending', 'name_servers' => ['ns1', 'ns2']]);
};
$created2 = CloudflareApi::createZone('tok-1', 'fresh2.xyz');
check('created through the zone-sourced account id', $created2['ok'], $created2['message']);
$post2 = null;
foreach ($log as $entry) {
    if ($entry['method'] === 'POST' && $entry['path'] === '/zones') {
        $post2 = $entry;
    }
}
check('POST body carries the zone-sourced id', ($post2['body']['account']['id'] ?? '') === 'acc-from-zone', json_encode($post2['body']['account'] ?? null));

echo "== createZone: refusals ==\n";
$log = [];
$routes['GET /accounts'] = static fn () => ['success' => true, 'result' => [['id' => 'a1']], 'errors' => []];
$routes['POST /zones'] = static fn () => ['success' => false, 'result' => null, 'errors' => [['code' => 1061, 'message' => 'zone already exists']]];
$dup = CloudflareApi::createZone('tok-1', 'taken.test');
check('API error text propagates', !$dup['ok'] && strpos($dup['message'], '1061') !== false && strpos($dup['message'], 'zone already exists') !== false, $dup['message']);
$callsBefore = count($log);
$badName = CloudflareApi::createZone('tok-1', 'not a zone name');
check('garbage name refused before any API call', !$badName['ok'] && count($log) === $callsBefore && strpos($badName['message'], 'Invalid zone name') !== false, $badName['message'] . ' calls=' . count($log));

CloudflareApi::$http = null;

echo "\n" . ($failed === 0 ? "ALL OK ($passed)" : "FAILED: $failed of " . ($passed + $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
