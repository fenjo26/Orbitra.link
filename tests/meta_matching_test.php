<?php
// No Meta calls. Pure matching rules + real HTTP entry points in a disposable DB.
require_once __DIR__ . '/../core/ClickParams.php';
require_once __DIR__ . '/lib/http.php';

$checks = 0;
$failures = [];
$check = static function (string $label, bool $ok) use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) { $failures[] = $label; echo "FAIL $label\n"; }
};
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)');
$pdo->exec('CREATE TABLE meta_matching_keys (id INTEGER PRIMARY KEY CHECK(id=1), secret TEXT NOT NULL)');
$pdo->exec('CREATE TABLE clicks (id TEXT PRIMARY KEY, parameters_json TEXT)');
$oldFbc = 'fb.1.1750000000000.OldCaseSensitiveAd';
$fresh = str_repeat('AbZ_-9', 120);
$params = orbitraCollectClickParams($pdo, ['fbclid' => $fresh, 'external_id' => 'NetworkClick'], [
    '_fbc' => $oldFbc, '_fbp' => 'fb.1.1750000000000.987654', 'orbitra_visitor' => 'StableCaseID',
]);
$check('opaque fbclid >512 is preserved whole', ($params['fbclid'] ?? '') === $fresh);
$check('fresh fbclid overrides stale fbc', str_ends_with($params['fbc'] ?? '', '.' . $fresh));
$check('legacy external_id is not visitor identity', $params['external_id'] === 'NetworkClick' && $params['meta_external_id'] === 'StableCaseID');
$check('existing real fbp is preserved', $params['fbp'] === 'fb.1.1750000000000.987654');
$same = 'fb.1.1750000000000.' . $fresh . '.Ag';
$params = orbitraCollectClickParams($pdo, ['fbclid' => $fresh], ['_fbc' => $same]);
$check('matching original timestamp and SDK appendix preserved', ($params['fbc'] ?? '') === $same);
$params = orbitraCollectClickParams($pdo, ['fbclid' => str_repeat('X', 8001), 'meta_external_id' => str_repeat('X', 513), 'fbc' => ['wrong']], []);
$check('overlong/array identifiers rejected not silently truncated', !isset($params['fbclid']) && !isset($params['meta_external_id']) && !isset($params['fbc']));
$params = orbitraCollectClickParams($pdo, [], [], null, false);
$check('S2S connection does not invent visitor identity or fbp', !isset($params['meta_external_id'], $params['fbp']));
$params = orbitraCollectClickParams($pdo, ['meta_matching' => '1', 'landing_page_url' => 'https://shop.example/checkout?q=1#fragment'], []);
$check('page URL is separate context with fragment omitted', $params['landing_page_url'] === 'https://shop.example/checkout?q=1' && !isset($params['event_source_url']));
$check('malformed and credentialed page URLs rejected', orbitraMetaPageUrl('https://bad"host/a') === null && orbitraMetaPageUrl('https://u:p@example.com') === null);
$token = orbitraMetaMatchingToken($pdo, 'click-a');
$check('valid token matches exactly one click', orbitraMetaVerifyMatchingToken($pdo, 'click-a', $token) && !orbitraMetaVerifyMatchingToken($pdo, 'click-b', $token));
$check('expired token rejected', !orbitraMetaVerifyMatchingToken($pdo, 'click-a', orbitraMetaMatchingToken($pdo, 'click-a', time() - 1)));
$check('forged token rejected', !orbitraMetaVerifyMatchingToken($pdo, 'click-a', substr($token, 0, -4) . '0000'));
$check('secret absent from public settings', (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() === 0);
$pdo->exec("INSERT INTO clicks VALUES ('click-a', '{}')");
$_COOKIE['orbitra_click'] = 'click-a';
$check('untrusted click cookie cannot mint HTML matching capability', orbitraMetaMatchingScript($pdo, 'click-a') === '');
$_COOKIE['orbitra_meta_context'] = 'click-a|' . $token;
$check('signed native context emits deferred helper', str_contains(orbitraMetaMatchingScript($pdo, 'click-a'), '<script defer'));
$serverBefore = $_SERVER;
$_SERVER['HTTP_USER_AGENT'] = 'BrowserIdentityFixture';
$pdo->exec('CREATE TABLE campaign_pixels (campaign_id INTEGER, type TEXT, is_active INTEGER)');
$unrelated = orbitraCollectClickParams($pdo, ['gclid' => 'GoogleClick'], [], null, true, 41);
$check('unrelated campaign never gets a Meta identity or capability marker', !isset($unrelated['meta_external_id']) && ($unrelated['meta_matching'] ?? '') === '0' && $unrelated['gclid'] === 'GoogleClick');
$pdo->exec("INSERT INTO campaign_pixels VALUES (42,'facebook',1)");
$active = orbitraCollectClickParams($pdo, [], [], null, true, 42);
$check('active Facebook campaign starts browser identity without fbclid', preg_match('/^[a-f0-9]{32}$/D', $active['meta_external_id'] ?? '') === 1);
$explicit = orbitraCollectClickParams($pdo, ['meta_matching' => '1'], [], null, true, 43);
$check('explicit operator opt-in starts matching without Meta evidence', isset($explicit['meta_external_id']));
$pdo->exec('CREATE TABLE traffic_sources (id INTEGER PRIMARY KEY, parameters_json TEXT)');
$pdo->prepare('INSERT INTO traffic_sources VALUES (7,?)')->execute([json_encode([['param' => 'custom_fbclid', 'alias' => 'fbclid']])]);
$alias = orbitraCollectClickParams($pdo, ['fbclid' => 'Original', 'custom_fbclid' => $fresh], [], 7);
$check('legacy source alias still wins without identifier truncation', ($alias['fbclid'] ?? '') === $fresh);
$_SERVER = $serverBefore;
$pdo->exec("INSERT INTO settings VALUES ('use_cookies', '0')");
$disabled = orbitraCollectClickParams($pdo, ['fbclid' => 'A'], ['orbitra_visitor' => 'B']);
$check('use_cookies=0 suppresses matching identifiers/capability', $disabled === ['meta_matching' => '0'] && orbitraMetaMatchingConfig($pdo, 'click-a') === null);
$_COOKIE = [];

require_once __DIR__ . '/../kclient.php';
$client = new KClickClient('https://tracker.example', 'fixture');
$_SERVER['HTTP_USER_AGENT'] = 'ActualBrowserCase';
$_SERVER['REMOTE_ADDR'] = '2001:db8::1234';
$_SERVER['HTTP_HOST'] = 'landing.example';
$_SERVER['REQUEST_URI'] = '/real-path';
$_COOKIE = ['orbitra_visitor' => 'PersistentBrowserCase', '_fbp' => 'fb.1.1750000000000.42'];
$context = (new ReflectionMethod(KClickClient::class, 'browserContext'))->invoke($client);
$check('PHP client forwards visitor IPv6 and actual UA rather than hosting client', $context['ip'] === '2001:db8::1234' && $context['user_agent'] === 'ActualBrowserCase');
$check('PHP client forwards browser identity/cookies and actual landing URL', $context['meta_external_id'] === 'PersistentBrowserCase' && $context['fbp'] === $_COOKIE['_fbp'] && $context['landing_page_url'] === 'http://landing.example/real-path');
(new ReflectionProperty(KClickClient::class, 'matching'))->setValue($client, ['meta_external_id' => 'PriorIdentity', 'subid' => 'prior', 'token' => 'prior']);
(new ReflectionProperty(KClickClient::class, 'restored'))->setValue($client, true);
$client->param('meta_matching', '0');
$check('PHP opt-out also suppresses previously restored matching', $client->getExternalId() === null && $client->matchingScript() === '');
$_SERVER = $serverBefore;
$_COOKIE = [];

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->start();
try {
    $db = $harness->getPdo();
    $db->exec("INSERT INTO offers (id,name,url,state,is_archived) VALUES (71001,'Matching offer','https://example.invalid/?x={meta_external_id}&legacy={external_id}','active',0)");
    $db->exec("INSERT INTO campaigns (id,name,alias,token,state,is_archived) VALUES (71001,'Matching fixture','matchingfixture','matchingfixture','active',0)");
    $db->exec("INSERT INTO streams (id,campaign_id,offer_id,name,weight,is_active,type,position,schema_type) VALUES (71001,71001,71001,'Matching',100,1,'regular',1,'redirect')");
    $ua = 'Mozilla/5.0 OrbitraMatchingFixture';
    $request = '/click_api/v3?' . http_build_query([
        'token' => 'matchingfixture', 'info' => 1, 'fbclid' => 'NewClickCase', 'fbc' => $oldFbc,
        'fbp' => 'fb.1.1750000000000.42', 'meta_external_id' => 'BrowserIdentityCase', 'external_id' => 'NetworkId',
        'landing_page_url' => 'https://external.example/landing?x=1',
    ]);
    $response = $harness->getWithHeaders($request, ['User-Agent: ' . $ua]);
    $decoded = json_decode($response['body'], true);
    $matching = $decoded['info']['matching'] ?? null;
    $check('Click API returns scoped matching context', is_array($matching) && !empty($matching['token']) && $matching['meta_external_id'] === 'BrowserIdentityCase');
    $id = (string) ($decoded['info']['sub_id'] ?? '');
    $read = static function (string $clickId) use ($db): array {
        $stmt = $db->prepare('SELECT * FROM clicks WHERE id=?');
        $stmt->execute([$clickId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stmt->closeCursor();
        $row['params'] = json_decode($row['parameters_json'] ?? '{}', true);
        return $row;
    };
    $row = $read($id);
    $check('API saved initial matching + actual visitor UA', ($row['user_agent'] ?? '') === $ua && ($row['params']['fbp'] ?? '') === 'fb.1.1750000000000.42');
    $check('API retains fresh click, real landing URL and separate IDs', ($row['params']['fbc'] ?? '') !== $oldFbc && ($row['params']['landing_page_url'] ?? '') === 'https://external.example/landing?x=1' && ($row['params']['external_id'] ?? '') === 'NetworkId');
    $check('new identity macro does not replace old network ID macro', str_contains(implode(' ', $decoded['headers'] ?? []), 'x=BrowserIdentityCase&legacy=NetworkId'));
    if (is_array($matching)) {
        $body = ['subid' => $id, 'token' => $matching['token'], 'fbp' => 'fb.1.1750000000999.999', 'fbc' => $oldFbc,
            'meta_external_id' => 'ATTACK', 'external_id' => 'ATTACK', 'ip' => '1.1.1.1', 'user_agent' => 'ATTACK',
            'landing_page_url' => 'https://evil.example/replacement', 'event_source_url' => 'https://evil.example/checkout'];
        $update = static fn(array $payload) => $harness->postWithHeaders('/pixel.gif?action=matching', json_encode($payload), ['Content-Type: text/plain']);
        $check('late cookie update accepted over POST', $update($body)['code'] === 204);
        $after = $read($id);
        $check('late fbp persists without overwriting IDs, UA or original landing', $after['params']['fbp'] === $body['fbp'] && $after['params']['meta_external_id'] === 'BrowserIdentityCase' && $after['params']['external_id'] === 'NetworkId' && $after['user_agent'] === $ua && $after['params']['landing_page_url'] === $row['params']['landing_page_url'] && !isset($after['params']['event_source_url']));
        $check('stale late fbc never replaces current click', $after['params']['fbc'] === $row['params']['fbc']);
        $body['fbc'] = 'fb.1.1750000000999.NewClickCase.Ag';
        $check('late same-click cookie timestamp/SDK appendix accepted', $update($body)['code'] === 204 && $read($id)['params']['fbc'] === $body['fbc']);
        $body['token'] = str_repeat('X', 75);
        $check('forged token rejected HTTP403', $update($body)['code'] === 403);
        $body['token'] = $matching['token']; $body['subid'] = 'another-click';
        $check('cross-click capability rejected HTTP403', $update($body)['code'] === 403);
        $check('GET cannot mutate matching context', $harness->getWithHeaders('/pixel.gif?action=matching&subid=' . $id)['code'] === 405);
        $check('oversized bodies rejected', $harness->postWithHeaders('/pixel.gif?action=matching', str_repeat('X', 32769), ['Content-Type: text/plain'])['code'] === 413);
    }
    // Actual native page: no outbound redirects or external requests.
    $db->exec("INSERT INTO landings (id,name,url,type,action_type,action_payload,state,is_archived) VALUES (71001,'Native matching','','action','show_html','<html><head></head><body>{meta_external_id}</body></html>','active',0)");
    $db->prepare("UPDATE streams SET schema_type='landing_offer',schema_custom_json=? WHERE id=71001")->execute([json_encode([
        'landings' => [['id' => 71001, 'weight' => 100, 'state' => 'active']],
        'offers' => [['id' => 71001, 'weight' => 100, 'state' => 'active']],
    ])]);
    // The native router debounces repeat visits from one IP within two seconds.
    $db->exec("UPDATE clicks SET created_at=datetime('now','-10 seconds') WHERE campaign_id=71001");
    $native = $harness->getWithHeaders('/?campaign_id=71001&fbclid=NativeNew', ['User-Agent: NativeMatchingFixture', 'Cookie: orbitra_visitor=NativeStable; _fbc=' . $oldFbc]);
    $check('native HTML injects matching and stable raw identity macro', str_contains($native['body'], '<script defer src="/meta-matching.js"') && str_contains($native['body'], 'NativeStable'));
    $nativeHeaders = implode("\n", array_filter($native['headers'], 'is_string'));
    $check('native persisted click issues HttpOnly capability cookie', str_contains($nativeHeaders, 'orbitra_meta_context=') && str_contains(strtolower($nativeHeaders), 'httponly'));
    $db->exec("UPDATE streams SET collect_clicks=0 WHERE id=71001");
    $noCollect = json_decode($harness->getWithHeaders($request, ['User-Agent: NoCollectFixture'])['body'], true);
    $check('no-collect does not issue a capability', empty($noCollect['info']['matching']));
    $db->exec("UPDATE streams SET collect_clicks=1 WHERE id=71001");
    $db->exec("UPDATE settings SET value='0' WHERE key='use_cookies'");
    $noCookies = $harness->getWithHeaders('/?campaign_id=71001&fbclid=DisabledCookies', ['User-Agent: DisabledCookiesFixture']);
    $check('native use_cookies=0 never injects matching helper', !str_contains($noCookies['body'], 'data-orbitra-matching'));
} finally {
    $harness->stop();
}
echo 'Meta matching: ' . ($checks - count($failures)) . "/$checks passed\n";
exit($failures ? 1 : 0);
