<?php

require_once __DIR__ . '/../core/click_api.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE bot_ips (id INTEGER PRIMARY KEY, ip_or_cidr TEXT NOT NULL)');
$pdo->exec('CREATE TABLE bot_signatures (id INTEGER PRIMARY KEY, signature TEXT NOT NULL)');

$botStream = [
    'filters_json' => json_encode([[
        'name' => 'Bot',
        'mode' => 'include',
        'payload' => ['yes'],
    ]]),
];
$normalGeo = [
    'asn' => 'AS7922',
    'isp' => 'Comcast Cable Communications LLC',
    'is_proxy' => 0,
    'proxy_type' => '',
];
$proxyGeo = $normalGeo + [
    'proxy_threat' => '',
    'proxy_provider' => '',
    'proxy_fraud_score' => null,
];
$proxyGeo['asn'] = '';
$proxyGeo['isp'] = '';
$proxyGeo['is_proxy'] = 1;
$proxyGeo['proxy_type'] = 'VPN';

$common = [
    'ip' => '73.1.2.3',
    'country' => 'US',
    'device' => 'Desktop',
    'languages' => ['en'],
    'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/127.0.0.0 Safari/537.36',
    'accept_language' => 'en-US,en;q=0.9',
];

$normalMatched = orbitraClickApiStreamMatchesFilters(
    $botStream,
    $common['ip'],
    $common['country'],
    $common['device'],
    $common['languages'],
    $common['ua'],
    $normalGeo,
    $common['accept_language'],
    $pdo
);
if ($normalMatched) {
    fwrite(STDERR, "Bot stream matched residential traffic.\n");
    exit(1);
}

$proxyMatched = orbitraClickApiStreamMatchesFilters(
    $botStream,
    '185.100.87.136',
    'DE',
    $common['device'],
    ['de', 'en'],
    $common['ua'],
    $proxyGeo,
    'de-DE,de;q=0.9,en;q=0.8',
    $pdo
);
if (!$proxyMatched) {
    fwrite(STDERR, "Bot stream did not match PX12 VPN traffic.\n");
    exit(1);
}

echo "Bot stream filter tests passed.\n";
