<?php
// Run: php tests/link_checks_test.php
// The link-check engine (core/LinkChecks.php) against a throwaway SQLite
// database: what gets checked, how a fetch becomes a verdict, the confirm-
// before-alert state machine, proxies, one worker pass, the alerts and the
// history. No network: the fetcher and the Telegram sender are injected.

require_once __DIR__ . '/../core/LinkChecks.php';

$failures = 0;
$checks = 0;
$assert = static function ($expected, $actual, string $label) use (&$failures, &$checks): void {
    $checks++;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$label}\n  expected: " . var_export($expected, true)
            . "\n  actual:   " . var_export($actual, true) . "\n");
    }
};

$tmpDb = sys_get_temp_dir() . '/orbitra_link_checks_test_' . getmypid() . '.sqlite';
@unlink($tmpDb);
$pdo = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT, updated_at DATETIME)');
$pdo->exec('CREATE TABLE affiliate_networks (id INTEGER PRIMARY KEY, offer_params TEXT)');
$pdo->exec('CREATE TABLE offers (id INTEGER PRIMARY KEY, name TEXT, url TEXT, is_local INTEGER DEFAULT 0,
    is_archived INTEGER DEFAULT 0, tg_channel_id TEXT, affiliate_network_id INTEGER, state TEXT DEFAULT \'active\')');
$pdo->exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, ssl_status TEXT DEFAULT \'none\', https_only INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE check_proxies (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, type TEXT NOT NULL, host TEXT NOT NULL, port INTEGER NOT NULL,
    username TEXT, password TEXT, status TEXT DEFAULT \'unknown\', exit_ip TEXT, exit_country TEXT, last_error TEXT,
    last_checked_at DATETIME, is_active INTEGER DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE link_checks (
    id INTEGER PRIMARY KEY AUTOINCREMENT, object_type TEXT NOT NULL, object_id INTEGER NOT NULL, label TEXT, url TEXT NOT NULL,
    proxy_id INTEGER, interval_min INTEGER, user_agent TEXT, expected_mode TEXT DEFAULT \'auto\', expected_domains TEXT,
    learned_domain TEXT, is_enabled INTEGER DEFAULT 1, status TEXT DEFAULT \'pending\', error_code TEXT, http_code INTEGER,
    final_url TEXT, duration_ms INTEGER, fail_streak INTEGER DEFAULT 0, alerted INTEGER DEFAULT 0, last_checked_at DATETIME,
    next_check_at DATETIME, last_change_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(object_type, object_id))');
$pdo->exec('CREATE TABLE link_check_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, check_id INTEGER NOT NULL, started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    duration_ms INTEGER, status TEXT, error_code TEXT, http_code INTEGER, final_url TEXT, proxy_id INTEGER, user_agent TEXT, hops TEXT)');

// A fetcher with the signature of orbitraUrlGuardFetch() that answers from a
// script by URL and remembers every call.
$makeFetch = static function (array $answers, array &$calls): callable {
    return static function (string $url, array $options, callable $resolve, ?callable $nextHop = null) use ($answers, &$calls): array {
        $calls[] = ['url' => $url, 'options' => $options];
        $answer = $answers[$url] ?? ['status' => 200, 'body' => ''];
        return [
            'url' => $answer['url'] ?? $url,
            'status' => (int) ($answer['status'] ?? 200),
            'body' => (string) ($answer['body'] ?? ''),
            'error' => (string) ($answer['error'] ?? ''),
            'reason' => (string) ($answer['reason'] ?? ''),
            'headers' => (string) ($answer['headers'] ?? ''),
            'hops' => $answer['hops'] ?? [['url' => $url, 'status' => (int) ($answer['status'] ?? 200), 'ms' => 120, 'location' => '', 'error' => '']],
        ];
    };
};
$sent = [];
$broadcast = static function (callable $build) use (&$sent): int {
    $sent[] = ['ru' => $build('ru'), 'en' => $build('en')];
    return 1;
};
$settings = orbitraLinkChecksSettings($pdo);
$eff = orbitraLinkChecksEffective([], $settings);
$verdictOf = static function (array $fetch, string $type = 'offer', bool $proxied = false, int $ms = 500, string $learned = '', ?array $effective = null) use ($eff): array {
    return orbitraLinkChecksVerdict($fetch, $effective ?? $eff, $type, $proxied, $ms, $learned);
};
$fetch = static function (int $status, string $body = '', string $error = '', string $url = 'https://site.example/page', ?array $hops = null, string $headers = '', string $reason = '', string $reasonCode = ''): array {
    return ['url' => $url, 'status' => $status, 'body' => $body, 'error' => $error, 'reason' => $reason, 'reason_code' => $reasonCode, 'headers' => $headers,
        'hops' => $hops ?? [['url' => $url, 'status' => $status, 'ms' => 100, 'location' => '', 'error' => $error]]];
};
$verdict = static function (string $status, string $code, string $blame): array {
    return ['status' => $status, 'error_code' => $code, 'blame' => $blame, 'learn' => null];
};

// --- Settings ---------------------------------------------------------------
$assert(['enabled' => false, 'offers' => 'all', 'domains' => 'all', 'interval_min' => 60, 'user_agent' => '', 'autopause' => false],
    orbitraLinkChecksSettings($pdo), 'Defaults of a fresh install: off until switched on');
orbitraLinkChecksSetSettings($pdo, ['enabled' => true, 'interval_min' => 2, 'user_agent' => "Mozilla/5.0\r\nX-Injected: 1", 'offers' => 'off']);
$after = orbitraLinkChecksSettings($pdo);
$assert(true, $after['enabled'], 'Enabled is saved');
$assert(60, $after['interval_min'], 'An interval under five minutes keeps the previous value');
$assert('', $after['user_agent'], 'A User-Agent with a line break is refused, not saved');
$assert('off', $after['offers'], 'Offer scope is saved');
orbitraLinkChecksSetSettings($pdo, ['enabled' => false, 'offers' => 'all', 'interval_min' => 60, 'user_agent' => 'TestAgent/1.0']);

// --- Proxies: parsing and options ----------------------------------------------
[$ok, $parsed, $reason] = orbitraLinkChecksParseProxyUrl('socks5://user:p%40ss@203.0.113.10:1080');
$assert([true, ['type' => 'socks5', 'host' => '203.0.113.10', 'port' => 1080, 'username' => 'user', 'password' => 'p@ss']],
    [$ok, $parsed], 'socks5://user:pass@host:port is parsed, the password decoded');
[$ok, $parsed] = orbitraLinkChecksParseProxyUrl('proxy.example.com:3128');
$assert(['http', 'proxy.example.com', 3128], [$parsed['type'], $parsed['host'], $parsed['port']], 'A bare host:port is an HTTP proxy');
[$ok, $parsed] = orbitraLinkChecksParseProxyUrl('proxy.example.com');
$assert(8080, $parsed['port'], 'An HTTP proxy without a port defaults to 8080');
[$ok] = orbitraLinkChecksParseProxyUrl('ftp://203.0.113.10:21');
$assert(false, $ok, 'ftp:// is not a proxy scheme');
[$ok] = orbitraLinkChecksParseProxyUrl('http://203.0.113.10:70000');
$assert(false, $ok, 'A port out of range is refused');
$assert('', orbitraLinkChecksProxyHostRefusal('8.8.8.8'), 'A public proxy host is accepted');
$assert(true, orbitraLinkChecksProxyHostRefusal('127.0.0.1') !== '', 'A loopback proxy host is refused');
$assert(true, orbitraLinkChecksProxyHostRefusal('bad host!') !== '', 'A malformed proxy host is refused');
$options = orbitraLinkChecksProxyOptions(['type' => 'socks5', 'host' => '203.0.113.10', 'port' => 1080, 'username' => 'u', 'password' => 'p']);
$assert('203.0.113.10:1080', $options[CURLOPT_PROXY], 'Proxy address goes to curl');
$assert(CURLPROXY_SOCKS5_HOSTNAME, $options[CURLOPT_PROXYTYPE], 'SOCKS5 lets the proxy resolve the target (geo-DNS as for a visitor)');
$assert('u:p', $options[CURLOPT_PROXYUSERPWD], 'Proxy credentials go to curl');

// --- What gets checked -----------------------------------------------------------
$pdo->exec("INSERT INTO affiliate_networks (id, offer_params) VALUES (1, 'src=net&aff={offer_id}')");
$pdo->exec("INSERT INTO offers (id, name, url) VALUES (1, 'Offer A', 'https://a.example/go?sub={subid}'), (4, 'Net offer', 'https://n.example/click')");
$pdo->exec("UPDATE offers SET affiliate_network_id = 1 WHERE id = 4");
$pdo->exec("INSERT INTO offers (id, name, url, is_local) VALUES (2, 'Local', '', 1)");
$pdo->exec("INSERT INTO offers (id, name, url, is_archived) VALUES (3, 'Archived', 'https://old.example/', 1)");
$pdo->exec("INSERT INTO offers (id, name, url, tg_channel_id) VALUES (5, 'Telegram', '', '@channel')");
$pdo->exec("INSERT INTO domains (id, name, ssl_status, https_only) VALUES (1, 'Go.Example.com', 'installed', 0), (2, 'plain.example', 'none', 0)");
$sync = orbitraLinkChecksSync($pdo, orbitraLinkChecksSettings($pdo));
$assert(['added' => 4, 'removed' => 0], $sync, 'Sync adds every external offer and every domain');
$assert(['added' => 0, 'removed' => 0], orbitraLinkChecksSync($pdo, orbitraLinkChecksSettings($pdo)), 'A second sync adds nothing');
$link = static fn (string $type, int $objectId): array => $pdo->query("SELECT * FROM link_checks WHERE object_type = '$type' AND object_id = $objectId")->fetch(PDO::FETCH_ASSOC);
$assert(['Offer A', 'https://a.example/go?sub=orbitra-check', 'pending'], array_values(array_intersect_key($link('offer', 1), ['label' => 1, 'url' => 1, 'status' => 1])), 'The offer link is opened as a visitor gets it, with the check\'s own sub id');
$assert('https://n.example/click?src=net&aff=4', $link('offer', 4)['url'], 'Network parameters and macros are filled in');
$assert(['https://go.example.com/', 'http://plain.example/'], [$link('domain', 1)['url'], $link('domain', 2)['url']], 'A domain is opened over https once it has a certificate');
$due = strtotime($link('offer', 1)['next_check_at'] . ' UTC');
$assert(true, $due > time() - 5 && $due <= time() + 600, 'The first checks are staggered over ten minutes');

// A changed URL is re-read; what was learned about the old one is forgotten.
$pdo->exec("UPDATE link_checks SET learned_domain = 'a.example', status = 'ok' WHERE object_type = 'offer' AND object_id = 1");
$pdo->exec("UPDATE offers SET name = 'Offer A (renamed)', url = 'https://new.example/x' WHERE id = 1");
$assert(['added' => 0, 'removed' => 0], orbitraLinkChecksSync($pdo, orbitraLinkChecksSettings($pdo)), 'Sync after an edit');
$row = $link('offer', 1);
$assert(['Offer A (renamed)', 'https://new.example/x', null, 'pending'], [$row['label'], $row['url'], $row['learned_domain'], $row['status']], 'A renamed or re-pointed offer is picked up and learned anew');

orbitraLinkChecksSetSettings($pdo, ['offers' => 'off']);
$assert(2, orbitraLinkChecksSync($pdo, orbitraLinkChecksSettings($pdo))['removed'], 'Turning offers off removes their rows');
$assert(0, (int) $pdo->query("SELECT COUNT(*) FROM link_checks WHERE object_type = 'offer'")->fetchColumn(), 'No offer rows are left');
$assert(2, (int) $pdo->query("SELECT COUNT(*) FROM link_checks WHERE object_type = 'domain'")->fetchColumn(), 'Domains keep their rows');
orbitraLinkChecksSetSettings($pdo, ['offers' => 'all']);
orbitraLinkChecksSync($pdo, orbitraLinkChecksSettings($pdo));

// --- Redirects the page performs itself ---------------------------------------------
$assert('https://go.example/next', orbitraLinkChecksPageRedirect('https://a.example/', 200,
    '<html><head><meta http-equiv="refresh" content="0; url=https://go.example/next"></head></html>', ''), 'A meta refresh is followed');
$assert('', orbitraLinkChecksPageRedirect('https://a.example/', 200,
    '<html><head><meta http-equiv="refresh" content="30; url=https://go.example/next"></head></html>', ''), 'A meta refresh that waits half a minute is not a redirect');
$assert('https://go.example/js', orbitraLinkChecksPageRedirect('https://a.example/', 200,
    '<html><body><script>window.location.href = "https://go.example/js";</script></body></html>', ''), 'A location assignment in an inline script is followed');
$assert('', orbitraLinkChecksPageRedirect('https://a.example/', 200,
    '<html><body><a onclick="location.href=\'https://go.example/button\'">Go</a></body></html>', ''), 'A jump wired to a click is a button, not a redirect');
$assert('', orbitraLinkChecksPageRedirect('https://a.example/', 200, '<html><body><a href="/register">Play</a></body></html>', ''), 'A plain page has no redirect');

// --- Verdicts -----------------------------------------------------------------------
$assert($verdict('failed', 'timeout', 'target'), $verdictOf($fetch(0, '', 'Operation timed out after 15001 milliseconds')), 'A timeout fails the link');
$assert($verdict('failed', 'dns_error', 'target'), $verdictOf($fetch(0, '', 'Could not resolve host: a.example')), 'An unresolvable host is a DNS error');
$assert($verdict('failed', 'connection_refused', 'target'), $verdictOf($fetch(0, '', 'Failed to connect to a.example port 443: Connection refused')), 'A refused connection');
$assert($verdict('failed', 'ssl_error', 'target'), $verdictOf($fetch(0, '', 'SSL certificate problem: certificate has expired')), 'A certificate error');
$assert($verdict('failed', 'redirect_loop', 'target'), $verdictOf($fetch(0, '', 'Redirect loop')), 'A redirect loop');
$assert($verdict('failed', 'too_many_redirects', 'target'), $verdictOf($fetch(0, '', 'Too many redirects')), 'Too many redirects');
$assert($verdict('failed', 'url_blocked', ''), $verdictOf($fetch(0, '', '', 'https://a.example/', null, '', 'Host resolves to a private or reserved address', 'url_internal_address')), 'A refused URL fails without blaming anyone');
$assert($verdict('failed', 'dns_error', 'target'), $verdictOf($fetch(0, '', '', 'https://gone.example/', null, '', 'Could not resolve host', 'url_host_unresolved')), 'A name nothing resolves is a dead link, blamed on the link (found on the live stand)');
$assert($verdict('failed', 'http_404', 'target'), $verdictOf($fetch(404)), 'A 404 fails the link');
$assert($verdict('failed', 'http_500', 'target'), $verdictOf($fetch(500)), 'A 500 fails the link');
$assert($verdict('warning', 'site_protection', 'target'), $verdictOf($fetch(403, 'Forbidden', '', 'https://a.example/', null, "HTTP/2 403\r\ncf-mitigated: challenge\r\n")), 'A Cloudflare challenge is a warning, not a dead link');
$assert($verdict('warning', 'site_protection', 'target'), $verdictOf($fetch(503, '<title>Just a moment...</title>')), 'A challenge page is recognised by its body too');
$assert(['status' => 'ok', 'error_code' => '', 'blame' => '', 'learn' => 'site.example'], $verdictOf($fetch(200, 'ok')), 'A good page is OK, and its domain is what the link is expected to lead to');
$assert($verdict('ok', '', ''), $verdictOf($fetch(200, 'ok', '', 'https://site.example/page'), 'offer', false, 500, 'site.example'), 'A known destination stays OK');
$assert($verdict('warning', 'unexpected_final_url', 'target'), $verdictOf($fetch(200, 'ok', '', 'https://other.example/'), 'offer', false, 500, 'site.example'), 'A link that now leads elsewhere is a warning');
$assert($verdict('ok', '', ''), $verdictOf($fetch(200, 'ok', '', 'https://www.site.example/x'), 'offer', false, 500, 'site.example'), 'A subdomain of the expected domain is fine');
$listMode = ['interval_min' => 60, 'user_agent' => 'ua', 'expected_mode' => 'list', 'expected_domains' => ['allowed.example', 'site.example']];
$assert($verdict('ok', '', ''), $verdictOf($fetch(200, 'ok'), 'offer', false, 500, '', $listMode), 'An explicit list accepts a listed domain');
$assert($verdict('warning', 'unexpected_final_url', 'target'), $verdictOf($fetch(200, 'ok', '', 'https://sneaky.example/'), 'offer', false, 500, '', $listMode), 'An explicit list refuses an unlisted domain');
$assert($verdict('failed', 'proxy_error', 'proxy'), $verdictOf($fetch(0, '', 'Failed to connect to 203.0.113.10 port 1080'), 'offer', true), 'Through a proxy, no answer is the proxy\'s failure');
$assert($verdict('failed', 'http_404', 'target'), $verdictOf($fetch(404), 'offer', true), 'Through a proxy, the site\'s own answer is the site\'s');
$assert($verdict('failed', 'redirect_loop', 'target'), $verdictOf($fetch(0, '', 'Redirect loop'), 'offer', true), 'A loop is never blamed on the proxy');
$downgrade = $fetch(200, 'ok', '', 'http://site.example/page', [
    ['url' => 'https://site.example/a', 'status' => 302, 'ms' => 10, 'location' => 'http://site.example/page', 'error' => ''],
    ['url' => 'http://site.example/page', 'status' => 200, 'ms' => 10, 'location' => '', 'error' => ''],
]);
$assert($verdict('warning', 'https_to_http', 'target'), $verdictOf($downgrade), 'HTTPS dropping to HTTP is a warning');
$assert($verdict('warning', 'slow_response', 'target'), $verdictOf($fetch(200, 'ok', '', 'https://site.example/page'), 'offer', false, 11000, 'site.example'), 'A very slow answer is a warning');
$assert($verdict('ok', '', ''), $verdictOf($fetch(404, 'Not found'), 'domain'), 'A domain answering 404 is still up');
$assert($verdict('failed', 'http_503', 'target'), $verdictOf($fetch(503), 'domain'), 'A domain answering 503 is down for the visitor too');
$assert($verdict('failed', 'dns_error', 'target'), $verdictOf($fetch(0, '', 'Could not resolve host: go.example.com'), 'domain'), 'A domain that does not resolve is down');

// --- The state machine: confirm before alert -------------------------------------------
$eff = ['interval_min' => 60, 'user_agent' => 'ua', 'expected_mode' => 'auto', 'expected_domains' => []];
$failed = $verdict('failed', 'http_404', 'target');
$good = $verdict('ok', '', '');
$fresh = ['status' => 'pending', 'fail_streak' => 0, 'alerted' => 0, 'last_change_at' => null];
$ts = 1795786800;
$t1 = orbitraLinkChecksTransition($fresh, $failed, $eff, '2026-11-27 12:00:00', $ts);
$assert('warning', $t1['fields']['status'], 'The first failure is only a warning');
$assert(null, $t1['event'], 'The first failure raises no alert');
$assert(gmdate('Y-m-d H:i:s', $ts + 120), $t1['fields']['next_check_at'], 'The first failure books a second look two minutes later');
$t2 = orbitraLinkChecksTransition(array_merge($fresh, ['error_code' => 'http_404'], $t1['fields']), $failed, $eff, '2026-11-27 12:02:00', $ts + 120);
$assert('failed', $t2['fields']['status'], 'The second failure fails the link');
$assert(['kind' => 'failed'], $t2['event'], 'The second failure raises the alert');
$assert(1, $t2['fields']['alerted'], 'The link remembers that it has told the owner');
$next = strtotime($t2['fields']['next_check_at'] . ' UTC');
$assert(true, $next >= $ts + 120 + 3240 && $next <= $ts + 120 + 3960, 'A failed link keeps its interval, give or take 10 %');
$t3 = orbitraLinkChecksTransition(array_merge($fresh, ['error_code' => 'http_404'], $t2['fields']), $failed, $eff, '2026-11-27 13:02:00', $ts + 3720);
$assert(null, $t3['event'], 'A link that stays failed does not repeat its alert');
$assert(3, $t3['fields']['fail_streak'], 'The failure streak is counted');
$t4 = orbitraLinkChecksTransition(array_merge($fresh, ['error_code' => 'http_404'], $t2['fields'], $t3['fields']), $good, $eff, '2026-11-27 14:02:00', $ts + 7320);
$assert(['kind' => 'recovered', 'since' => '2026-11-27 12:02:00'], $t4['event'], 'One good check recovers the link and says so');
$assert([0, 0], [$t4['fields']['alerted'], $t4['fields']['fail_streak']], 'Recovery clears the alert and the streak');
$blink = orbitraLinkChecksTransition(array_merge($fresh, ['error_code' => 'http_404'], $t1['fields']), $good, $eff, '2026-11-27 12:02:00', $ts + 120);
$assert([null, 'ok'], [$blink['event'], $blink['fields']['status']], 'A failure that does not repeat was a blink: no alert');
$proxyDown = $verdict('failed', 'proxy_error', 'proxy');
$p1 = orbitraLinkChecksTransition(['status' => 'ok', 'fail_streak' => 0, 'alerted' => 0, 'last_change_at' => null], $proxyDown, $eff, '2026-11-27 12:00:00', $ts);
$assert([null, 'failed', false], [$p1['event'], $p1['fields']['status'], array_key_exists('fail_streak', $p1['fields'])], 'A dead proxy fails the link but raises nothing and does not count as the link\'s failure');
$assert(gmdate('Y-m-d H:i:s', $ts + 300), $p1['fields']['next_check_at'], 'A link through a dead proxy is looked at again in five minutes');
$p2 = orbitraLinkChecksTransition(array_merge(['status' => 'failed', 'fail_streak' => 0, 'alerted' => 0, 'last_change_at' => 'x'], $p1['fields']), $good, $eff, '2026-11-27 12:05:00', $ts + 300);
$assert(null, $p2['event'], 'When the proxy comes back the link recovers silently');
$warn = $verdict('warning', 'unexpected_final_url', 'target');
$w1 = orbitraLinkChecksTransition(['status' => 'ok', 'fail_streak' => 0, 'alerted' => 0, 'last_change_at' => null], $warn, $eff, '2026-11-27 12:00:00', $ts);
$assert(['warning', 1], [$w1['fields']['status'], $w1['fields']['alerted']], 'A warning (another domain, protection) is told at once');
$w2 = orbitraLinkChecksTransition(array_merge(['status' => 'warning', 'fail_streak' => 0, 'alerted' => 1, 'last_change_at' => 'x'], $w1['fields']), $warn, $eff, '2026-11-27 13:00:00', $ts + 3600);
$assert(null, $w2['event'], 'A warning that stays is not repeated');

// --- One worker pass ----------------------------------------------------------------
$pdo->exec('DELETE FROM offers');
$pdo->exec('DELETE FROM domains');
$pdo->exec('DELETE FROM link_checks');
$pdo->exec('DELETE FROM link_check_logs');
$pdo->exec("INSERT INTO offers (id, name, url) VALUES (10, 'Offer A', 'https://a.example/'), (11, 'Offer B', 'https://b.example/landing'), (12, 'Offer C', 'https://c.example/')");
$pdo->exec('DELETE FROM domains');
$pdo->exec("INSERT INTO domains (id, name, ssl_status) VALUES (1, 'go.example.com', 'installed')");
orbitraLinkChecksSetSettings($pdo, ['enabled' => true, 'offers' => 'all', 'domains' => 'all', 'interval_min' => 60, 'user_agent' => '', 'autopause' => true]);
orbitraLinkChecksSync($pdo, orbitraLinkChecksSettings($pdo));
$pdo->prepare('INSERT INTO check_proxies (name, type, host, port, status) VALUES (?, ?, ?, ?, ?)')->execute(['GR-1', 'socks5', '8.8.8.8', 1080, 'unknown']);
$proxyId = (int) $pdo->lastInsertId();
$offerC = $link('offer', 12);
$pdo->prepare('UPDATE link_checks SET proxy_id = ? WHERE id = ?')->execute([$proxyId, $offerC['id']]);
$makeDue = static function () use ($pdo): void {
    $pdo->exec("UPDATE link_checks SET next_check_at = datetime('now', '-1 minute')");
};
$makeDue();
$calls = [];
$answers = [
    'https://a.example/' => ['status' => 404],
    'https://b.example/landing' => ['status' => 200, 'body' => '<html><body>welcome</body></html>'],
    'https://c.example/' => ['error' => 'Failed to connect to 8.8.8.8 port 1080: Connection refused'],
    'https://go.example.com/' => ['status' => 404],
    ORBITRA_LINK_CHECKS_PROBE_URL => ['status' => 200, 'body' => "fl=123\nh=www.cloudflare.com\nip=203.0.113.9\nloc=DE\nts=1.0\n"],
];
$stats = orbitraLinkChecksPass($pdo, ['fetch' => $makeFetch($answers, $calls), 'broadcast' => $broadcast]);
$assert(['checked' => 4, 'problems' => 0, 'recovered' => 0, 'proxies_probed' => 1, 'added' => 0, 'removed' => 0], $stats, 'The first pass checks what is due and probes the new proxy');
$assert([], $sent, 'Nothing is told after a single failure');
$assert('warning', $link('offer', 10)['status'], 'Offer A is a warning until the failure is confirmed');
$assert(['ok', 'b.example', 0], [$link('offer', 11)['status'], $link('offer', 11)['learned_domain'], $link('offer', 11)['fail_streak']], 'Offer B is OK and its destination is learned');
$assert(['failed', 'proxy_error', 0], [$link('offer', 12)['status'], $link('offer', 12)['error_code'], $link('offer', 12)['alerted']], 'Offer C is failed by its proxy, without an alert of its own');
$assert('ok', $link('domain', 1)['status'], 'A domain that answers 404 is up');
$assert(['ok', '203.0.113.9', 'DE'], array_values(array_intersect_key($pdo->query('SELECT * FROM check_proxies')->fetch(PDO::FETCH_ASSOC), ['status' => 1, 'exit_ip' => 1, 'exit_country' => 1])), 'The probe records the proxy\'s exit address and country');
$byUrl = [];
foreach ($calls as $call) {
    $byUrl[$call['url']] = $call['options'];
}
$assert(ORBITRA_LINK_CHECKS_UA, $byUrl['https://a.example/'][CURLOPT_USERAGENT] ?? null, 'A check calls itself a browser');
$assert('8.8.8.8:1080', $byUrl['https://c.example/'][CURLOPT_PROXY] ?? null, 'The link\'s proxy is used for its check');
$assert(true, !isset($byUrl['https://a.example/'][CURLOPT_PROXY]), 'A link without a proxy is checked directly');
$assert(4, (int) $pdo->query('SELECT COUNT(*) FROM link_check_logs')->fetchColumn(), 'Every check leaves a history row');
$logA = $pdo->query("SELECT l.* FROM link_check_logs l JOIN link_checks c ON c.id = l.check_id WHERE c.object_type = 'offer' AND c.object_id = 10 ORDER BY l.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$assert(['failed', 'http_404', 404, 'https://a.example/'], array_values(array_intersect_key($logA, ['status' => 1, 'error_code' => 1, 'http_code' => 1, 'final_url' => 1])), 'The history row keeps the check\'s own verdict');
$hopsA = json_decode($logA['hops'], true);
$assert([404, 'https://a.example/'], [$hopsA[0]['status'], $hopsA[0]['url']], 'The history row keeps the chain of hops');

$makeDue();
$calls = [];
$stats = orbitraLinkChecksPass($pdo, ['fetch' => $makeFetch($answers, $calls), 'broadcast' => $broadcast]);
$assert(['checked' => 4, 'problems' => 1, 'recovered' => 0, 'proxies_probed' => 0, 'added' => 0, 'removed' => 0], $stats, 'The second pass confirms the failure');
$assert(1, count($sent), 'One message for the confirmed failure');
$assert(true, str_contains($sent[0]['ru'], '🔴 Оффер «Offer A» не открывается'), 'The message names the link: ' . ($sent[0]['ru'] ?? ''));
$assert(true, str_contains($sent[0]['ru'], 'Причина: сайт ответил HTTP 404'), 'The message gives the reason in plain words');
$assert(true, str_contains($sent[0]['ru'], 'Цепочка: a.example (404)'), 'The message shows the chain');
$assert(true, str_contains($sent[0]['ru'], 'Оффер поставлен на паузу автоматически'), 'The message says the offer was paused');
$assert(true, str_contains($sent[0]['en'], '🔴 Offer «Offer A» is not opening'), 'Every chat gets the message in its own language');
$assert(false, str_contains($sent[0]['ru'], 'https://'), 'The link itself stays out of the message');
$assert(['failed', 1], [$link('offer', 10)['status'], $link('offer', 10)['alerted']], 'Offer A is failed and marked as told');
$assert('paused', $pdo->query('SELECT state FROM offers WHERE id = 10')->fetchColumn(), 'Autopause stops the broken offer');
$assert('active', $pdo->query('SELECT state FROM offers WHERE id = 11')->fetchColumn(), 'A healthy offer keeps running');

$makeDue();
$sent = [];
$fixed = $answers;
$fixed['https://a.example/'] = ['status' => 200, 'body' => '<html><body>back</body></html>'];
orbitraLinkChecksPass($pdo, ['fetch' => $makeFetch($fixed, $calls), 'broadcast' => $broadcast]);
$assert(1, count($sent), 'One message for the recovery');
$assert(true, str_contains($sent[0]['ru'], '🟢 Оффер «Offer A» снова работает (не открывался'), 'The message says the link is back and for how long it was down: ' . ($sent[0]['ru'] ?? ''));
$assert(['ok', 0], [$link('offer', 10)['status'], $link('offer', 10)['alerted']], 'Offer A is OK again');
$assert('a.example', $link('offer', 10)['learned_domain'], 'The first good look learns the destination');
$assert('paused', $pdo->query('SELECT state FROM offers WHERE id = 10')->fetchColumn(), 'Recovery does not restart the offer by itself: that is the owner\'s call');

// A dead proxy is one message about the proxy, whatever many links use it.
$pdo->exec("UPDATE check_proxies SET last_checked_at = datetime('now', '-20 minutes') WHERE id = $proxyId");
$makeDue();
$sent = [];
$down = $fixed;
$down[ORBITRA_LINK_CHECKS_PROBE_URL] = ['error' => 'Failed to connect to 8.8.8.8 port 1080: Connection refused'];
orbitraLinkChecksPass($pdo, ['fetch' => $makeFetch($down, $calls), 'broadcast' => $broadcast]);
$assert(1, count($sent), 'One message for the proxy');
$assert(true, str_contains($sent[0]['ru'], '⚠️ Прокси «GR-1» не отвечает. Проверок через него: 1.'), 'It is about the proxy, with the number of links: ' . ($sent[0]['ru'] ?? ''));
$assert(['failed'], array_values(array_intersect_key($pdo->query('SELECT * FROM check_proxies')->fetch(PDO::FETCH_ASSOC), ['status' => 1])), 'The proxy is marked failed');
$assert(0, $link('offer', 12)['alerted'], 'The link behind the dead proxy is still not blamed');

// Twenty minutes later the proxy is probed again.
$pdo->exec("UPDATE check_proxies SET last_checked_at = datetime('now', '-20 minutes') WHERE id = $proxyId");
$pdo->exec("UPDATE link_checks SET next_check_at = datetime('now', '-1 minute') WHERE object_type = 'offer' AND object_id = 12");
$sent = [];
orbitraLinkChecksPass($pdo, ['fetch' => $makeFetch($fixed, $calls), 'broadcast' => $broadcast]);
$assert(1, count($sent), 'One message when the proxy is back');
$assert(true, str_contains($sent[0]['ru'], '✅ Прокси «GR-1» снова отвечает (203.0.113.9).'), 'It carries the exit address: ' . ($sent[0]['ru'] ?? ''));

// Many failures at once are one digest, not a storm of messages.
$pdo->exec("UPDATE check_proxies SET is_active = 0 WHERE id = $proxyId");
$pdo->exec('UPDATE link_checks SET proxy_id = NULL');
for ($i = 20; $i < 27; $i++) {
    $pdo->prepare('INSERT INTO offers (id, name, url) VALUES (?, ?, ?)')->execute([$i, "Offer $i", "https://o$i.example/"]);
}
orbitraLinkChecksSync($pdo, orbitraLinkChecksSettings($pdo));
$storm = $answers;
for ($i = 20; $i < 27; $i++) {
    $storm["https://o$i.example/"] = ['status' => 500];
    $pdo->prepare("UPDATE link_checks SET next_check_at = datetime('now', '-1 minute') WHERE object_type = 'offer' AND object_id = $i")->execute();
}
orbitraLinkChecksPass($pdo, ['fetch' => $makeFetch($storm, $calls), 'broadcast' => $broadcast]);
for ($i = 20; $i < 27; $i++) {
    $pdo->prepare("UPDATE link_checks SET next_check_at = datetime('now', '-1 minute') WHERE object_type = 'offer' AND object_id = $i")->execute();
}
$sent = [];
orbitraLinkChecksPass($pdo, ['fetch' => $makeFetch($storm, $calls), 'broadcast' => $broadcast]);
$assert(true, count($sent) === 1 && str_contains($sent[0]['ru'] ?? '', '🔴 Сразу 7 ссылок перестали открываться: Offer 20, Offer 21, Offer 22, Offer 23, Offer 24, и ещё 2'),
    'Seven failures at once are one digest naming five: ' . count($sent) . ' message(s): ' . ($sent[0]['ru'] ?? ''));

// --- Reading, for the panel ------------------------------------------------------------
$summary = orbitraLinkChecksSummary($pdo);
$assert(['total' => 10, 'ok' => 2, 'warning' => 0, 'failed' => 8, 'pending' => 0], $summary['offers'], 'Summary counts offers by status');
$assert(['total' => 1, 'ok' => 1, 'warning' => 0, 'failed' => 0, 'pending' => 0], $summary['domains'], 'Summary counts domains by status');
$assert(8, $summary['failed'], 'Summary carries the number for the badge');
$list = orbitraLinkChecksList($pdo, ['status' => 'failed', 'type' => 'offer']);
$assert([8, 'failed'], [$list['total'], $list['rows'][0]['status']], 'The list filters by type and status');
$assert(true, in_array('Offer 20', array_map(static fn (array $r): string => (string) $r['label'], $list['rows']), true), 'The failed offer is in the list');
$assert([8, 1], [orbitraLinkChecksList($pdo, ['status' => 'problem'])['total'], orbitraLinkChecksList($pdo, ['q' => 'Offer B'])['total']], 'A "problem" filter and a search by name');
$assert('Offer B', orbitraLinkChecksList($pdo, ['q' => 'Offer B'])['rows'][0]['label'], 'The search finds the link');
$assert(true, is_int($list['rows'][0]['last_checked_at_ts']), 'Timestamps come as seconds for the panel');
$assert(4, count(orbitraLinkChecksLog($pdo, (int) $link('offer', 10)['id'])), 'The history lists every check of a link');
$assert('ok', orbitraLinkChecksLog($pdo, (int) $link('offer', 10)['id'])[0]['status'], 'Newest first');

$queue = orbitraLinkChecksQueueNow($pdo, [(int) $link('offer', 10)['id']], false);
$assert(1, $queue['queued'], '"Check now" books the link');
$limited = false;
for ($i = 0; $i < 120; $i++) {
    $queue = orbitraLinkChecksQueueNow($pdo, [(int) $link('offer', 10)['id']], false);
    $limited = $limited || $queue['limited'];
}
$assert(true, $limited, '"Check now" is capped, so a held-down button cannot flood partner statistics');

$saved = orbitraLinkChecksSaveLink($pdo, (int) $link('offer', 10)['id'], ['interval_min' => 120, 'expected_mode' => 'list', 'expected_domains' => "Tracker.example\n\n go.example.com"]);
$assert([120, 'list', ['tracker.example', 'go.example.com']], [$saved['interval_min'], $saved['expected_mode'], $saved['expected_domains']], 'Per-link settings are saved, domains parsed and normalised');
$assert(false, orbitraLinkChecksSaveLink($pdo, (int) $link('offer', 10)['id'], ['expected_domains' => 'not a domain']), 'An invalid domain is refused');
$assert(false, orbitraLinkChecksSaveLink($pdo, (int) $link('offer', 10)['id'], ['user_agent' => "a\r\nb"]), 'A User-Agent with a line break is refused');
$assert(false, orbitraLinkChecksSaveLink($pdo, (int) $link('offer', 10)['id'], ['proxy_id' => 999999]), 'An unknown proxy is refused');
$assert(null, orbitraLinkChecksSaveLink($pdo, (int) $link('offer', 10)['id'], ['interval_min' => 1])['interval_min'], 'An interval under five minutes means "the default"');
$assert(null, orbitraLinkChecksSaveLink($pdo, 999999, []), 'An unknown link is not found');
$assert(11, orbitraLinkChecksList($pdo)['total'], 'Every enabled link is in the list');
$assert(false, orbitraLinkChecksSaveLink($pdo, (int) $link('offer', 11)['id'], ['is_enabled' => false])['is_enabled'], 'A link can be switched off');
$assert(10, orbitraLinkChecksList($pdo)['total'], 'A switched-off link leaves the list');
$assert(9, orbitraLinkChecksSummary($pdo)['offers']['total'], 'And out of the summary');

$probeFetch = $makeFetch([ORBITRA_LINK_CHECKS_PROBE_URL => $answers[ORBITRA_LINK_CHECKS_PROBE_URL]], $calls);
[$proxy, $error] = orbitraLinkChecksSaveProxy($pdo, ['url' => 'socks5://bob:secret@8.8.4.4:1080', 'name' => 'DE-1'], $probeFetch);
$assert(['', 'DE-1', 'ok', '203.0.113.9'], [$error, $proxy['name'] ?? '', $proxy['status'] ?? '', $proxy['exit_ip'] ?? ''], 'A proxy pasted as one line is saved and probed at once');
$assert(true, !array_key_exists('password', $proxy) && $proxy['has_password'] === true, 'The password never leaves the server, only the fact of it');
[$proxy2, $error2] = orbitraLinkChecksSaveProxy($pdo, ['url' => 'http://127.0.0.1:3128'], $probeFetch);
$assert([null, 'proxy_private'], [$proxy2, $error2], 'A proxy on a private address is refused');
[$proxy3, $error3] = orbitraLinkChecksSaveProxy($pdo, ['url' => 'nonsense://x'], $probeFetch);
$assert(true, $proxy3 === null && $error3 !== '', 'A malformed proxy line is refused');
[$renamed, $renameError] = orbitraLinkChecksSaveProxy($pdo, ['id' => $proxy['id'], 'name' => 'DE residential'], $probeFetch);
$assert(['DE residential', 'ok'], [$renamed['name'], $renamed['status']], 'Renaming a proxy does not re-probe it');
$deleted = orbitraLinkChecksSaveLink($pdo, (int) $link('offer', 10)['id'], ['proxy_id' => $proxy['id']]);
$assert($proxy['id'], $deleted['proxy_id'], 'A link can be pointed at a proxy');
$assert(1, orbitraLinkChecksProxyRows($pdo)[0]['links'] ?? 0, 'The proxy row knows how many links use it');

// --- History is kept for a month, then dropped ---------------------------------------------
$pdo->exec("INSERT INTO link_check_logs (check_id, started_at, status) VALUES (1, datetime('now', '-40 days'), 'ok')");
$pdo->exec("UPDATE settings SET value = '' WHERE key = 'link_checks_last_prune_at'");
$pdo->exec("UPDATE link_checks SET next_check_at = datetime('now', '+1 hour')");
$before = (int) $pdo->query('SELECT COUNT(*) FROM link_check_logs')->fetchColumn();
orbitraLinkChecksPass($pdo, ['fetch' => $makeFetch([], $calls), 'broadcast' => $broadcast]);
$assert($before - 1, (int) $pdo->query('SELECT COUNT(*) FROM link_check_logs')->fetchColumn(), 'A check older than a month is dropped, the rest kept');

@unlink($tmpDb);
echo "Link checks tests: {$checks} checks, {$failures} failures.\n";
exit($failures === 0 ? 0 : 1);
