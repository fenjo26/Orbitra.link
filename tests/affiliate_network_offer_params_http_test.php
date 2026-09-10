<?php
// Run: php tests/affiliate_network_offer_params_http_test.php
// Exercise the real public entrypoints against synthetic data in the HTTP
// harness's disposable SQLite database. Never follow an external redirect.

require_once __DIR__ . '/lib/http.php';

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

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();

try {
    $harness->start();
    $pdo = $harness->getPdo();
    $insert = static function (string $table, array $values) use ($pdo): int {
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', array_keys($values))
            . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')';
        $pdo->prepare($sql)->execute(array_values($values));
        return (int) $pdo->lastInsertId();
    };
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES
        ('postback_key', 'network-http-test-key'), ('stats_enabled', '1'), ('ignore_prefetch', '0')");

    $requestNumber = 0;
    $request = static function (string $path, array $headers = []) use ($harness, &$requestNumber): array {
        // Unique synthetic visitor per request also avoids the browser debounce.
        $requestNumber++;
        $headers[] = 'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 Version/16.0 Mobile/15E148 Safari/604.1';
        $headers[] = 'X-Forwarded-For: 203.0.113.' . $requestNumber;
        $ctx = stream_context_create(['http' => [
            'timeout' => 8,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => implode("\r\n", $headers),
        ]]);
        $body = @file_get_contents($harness->getBaseUrl() . '/' . ltrim($path, '/'), false, $ctx);
        if ($body === false) {
            throw new RuntimeException('Local test server did not answer ' . $path);
        }
        $lines = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?: []) : ($http_response_header ?? []);
        $code = 0;
        $location = '';
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\d\.\d (\d{3})#', $line, $match)) {
                $code = (int) $match[1];
            } elseif (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
            }
        }
        return ['code' => $code, 'body' => $body, 'location' => $location];
    };
    $latestClick = static function (int $campaignId) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT * FROM clicks WHERE campaign_id = ? ORDER BY rowid DESC LIMIT 1');
        $stmt->execute([$campaignId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('No click recorded for fixture campaign ' . $campaignId);
        }
        return $row;
    };
    $href = static function (string $html, string $id): string {
        if (!preg_match('/id="' . preg_quote($id, '/') . '" href="([^"]*)"/', $html, $match)) {
            throw new RuntimeException('Missing fixture link ' . $id . ': ' . substr($html, 0, 300));
        }
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    };

    $networkId = $insert('affiliate_networks', [
        'name' => 'HTTP test network',
        'offer_params' => 'subid={subid}&tracking={sub_id_1}&selected={offer_id}&fixed=network',
    ]);
    $baseUrl = 'https://offers.example/checkout?encoded=a%2Fb%2Bc%3D&repeated=one&repeated=two&fixed=offer';
    $offerId = $insert('offers', [
        'name' => 'Network offer', 'url' => $baseUrl . '#checkout', 'affiliate_network_id' => $networkId,
    ]);
    $explicitOfferId = $insert('offers', [
        'name' => 'Explicitly selected offer', 'url' => 'https://offers.example/alternate?subid={subid}#kit',
        'affiliate_network_id' => $networkId,
    ]);
    $plainUrl = 'https://plain.example/offer?encoded=a%2Fb+z#plain';
    $plainOfferId = $insert('offers', ['name' => 'No network', 'url' => $plainUrl]);
    $localOfferId = $insert('offers', [
        'name' => 'Local network offer', 'url' => '', 'is_local' => 1, 'affiliate_network_id' => $networkId,
    ]);
    $localOfferDir = $harness->getWorkingDir() . '/offers/' . $localOfferId;
    mkdir($localOfferDir, 0700, true);
    file_put_contents($localOfferDir . '/index.html', '<html><body>LOCAL_NETWORK_OFFER</body></html>');

    $landingHtml = '<html><body>NETWORK_TEST_LANDING'
        . '<a id="transition" href="{lp_url}">Continue</a>'
        . '<a id="offer" href="{offer}">Direct offer</a></body></html>';
    $landingId = $insert('landings', [
        'name' => 'Network action landing', 'type' => 'action', 'action_type' => 'show_html',
        'url' => '', 'action_payload' => $landingHtml,
    ]);
    $localLandingId = $insert('landings', ['name' => 'Network local landing', 'type' => 'local', 'url' => '']);
    $localLandingDir = $harness->getWorkingDir() . '/landings/' . $localLandingId;
    mkdir($localLandingDir, 0700, true);
    file_put_contents($localLandingDir . '/index.html', $landingHtml);

    $campaignNumber = 0;
    $campaign = static function (string $schemaType, int $selectedOffer, ?int $selectedLanding = null,
        string $selection = 'before', array $extraSchema = [], bool $legacyOffer = false) use ($insert, &$campaignNumber): array {
        $campaignNumber++;
        $alias = 'networktest' . $campaignNumber;
        $token = 'network-http-token-' . $campaignNumber;
        $id = $insert('campaigns', ['name' => $alias, 'alias' => $alias, 'token' => $token, 'state' => 'active']);
        $schema = array_merge([
            'offers' => $legacyOffer ? [] : [['id' => $selectedOffer, 'weight' => 100]],
            'landings' => $selectedLanding ? [['id' => $selectedLanding, 'weight' => 100]] : [],
            'detect_datacenter' => false, 'detect_vpn' => false, 'detect_bots' => false, 'detect_ua' => false,
        ], $extraSchema);
        $streamId = $insert('streams', [
            'campaign_id' => $id, 'name' => $alias, 'type' => 'regular', 'position' => 1,
            'schema_type' => $schemaType, 'schema_custom_json' => json_encode($schema),
            'offer_id' => $legacyOffer ? $selectedOffer : null,
            'offer_selection' => $selection, 'is_active' => 1, 'collect_clicks' => 1,
        ]);
        return ['id' => $id, 'alias' => $alias, 'token' => $token, 'stream_id' => $streamId];
    };
    $assertOfferUrl = static function (string $url, string $clickId, int $selectedOffer, string $label,
        bool $alternate = false) use ($assert, $baseUrl): void {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $assert($clickId, $query['subid'] ?? null, $label . ': network receives the original click id');
        $assert('campaign & value', $query['tracking'] ?? null, $label . ': saved tracking macro resolves');
        $assert((string) $selectedOffer, $query['selected'] ?? null, $label . ': selected offer macro resolves');
        $assert($alternate ? 'network' : 'offer', $query['fixed'] ?? null, $label . ': explicit query values take precedence');
        $assert(1, preg_match_all('/(?:^|&)subid=/', (string) parse_url($url, PHP_URL_QUERY)), $label . ': subid is not duplicated');
        $assert($alternate ? 'kit' : 'checkout', parse_url($url, PHP_URL_FRAGMENT), $label . ': fragment stays last');
        if (!$alternate) {
            $assert(true, strpos($url, $baseUrl . '&') === 0, $label . ': original encoded bytes, order and repeated keys survive');
        }
        $assert(false, strpos($url, '{') !== false, $label . ': no unresolved macros');
    };
    $traffic = '?sub_id_1=' . rawurlencode('campaign & value');

    // Deferred and preselected offers use the same click row at the LP hop.
    foreach (['landing_offer', 'cloak'] as $schemaType) {
        foreach (['before', 'after'] as $selection) {
            $label = $schemaType . '/' . $selection;
            $fixture = $campaign($schemaType, $offerId, $landingId, $selection);
            $view = $request('/' . $fixture['alias'] . $traffic);
            $assert(200, $view['code'], $label . ': action landing served');
            $click = $latestClick($fixture['id']);
            $assert($selection === 'after' ? 0 : $offerId, (int) $click['offer_id'], $label . ': selection timing preserved');
            $transition = $href($view['body'], 'transition');
            $hop = $request($transition);
            $assert(302, $hop['code'], $label . ': automatic LP hop redirects');
            $assertOfferUrl($hop['location'], $click['id'], $offerId, $label);
            $after = $latestClick($fixture['id']);
            $assert($click['id'], $after['id'], $label . ': LP hop reuses the original click');
            $assert($offerId, (int) $after['offer_id'], $label . ': selected offer attributed');
            $assert(true, !empty($after['offer_at']), $label . ': offer transition timestamp recorded');
            // An explicit kit button must override the preselected/weighted offer.
            $explicit = $request($transition . '&offer_id=' . $explicitOfferId);
            $assert(302, $explicit['code'], $label . ': explicit offer hop redirects');
            $assertOfferUrl($explicit['location'], $click['id'], $explicitOfferId, $label . '/explicit', true);
            $assert($explicitOfferId, (int) $latestClick($fixture['id'])['offer_id'], $label . ': explicit offer attributed');
        }
    }

    // A multi-offer local landing can choose a kit on its first hop using the
    // browser cookie, without a preselected offer or a signed-link macro.
    $fixture = $campaign('cloak', $offerId, $localLandingId, 'after');
    $request('/' . $fixture['alias'] . $traffic);
    $click = $latestClick($fixture['id']);
    $assert(0, (int) $click['offer_id'], 'Cookie-based explicit LP: no offer preselected');
    $explicit = $request('/index.php?_lp=1&offer_id=' . $explicitOfferId, ['Cookie: orbitra_click=' . $click['id']]);
    $assert(302, $explicit['code'], 'Cookie-based explicit LP: redirects');
    $assertOfferUrl($explicit['location'], $click['id'], $explicitOfferId, 'Cookie-based explicit LP', true);
    $assert($explicitOfferId, (int) $latestClick($fixture['id'])['offer_id'], 'Cookie-based explicit LP: correct kit attributed');

    // Macros embedded in both supported inline landing types receive the
    // prepared offer URL, before HTML escaping of incoming tracking values.
    foreach ([$landingId => 'action', $localLandingId => 'local'] as $id => $type) {
        $fixture = $campaign('landing_offer', $offerId, $id);
        $view = $request('/' . $fixture['alias'] . $traffic);
        $assert(200, $view['code'], $type . ' landing macro: served');
        $assertOfferUrl($href($view['body'], 'offer'), $latestClick($fixture['id'])['id'], $offerId, $type . ' landing macro');
    }

    // Direct routes include both modern weighted offers and the legacy FK.
    foreach (['redirect', 'landing_offer', 'cloak', 'legacy'] as $schemaType) {
        $fixture = $campaign($schemaType === 'legacy' ? 'redirect' : $schemaType,
            $offerId, null, 'before', [], $schemaType === 'legacy');
        $response = $request('/' . $fixture['alias'] . $traffic);
        $assert(302, $response['code'], $schemaType . ' direct: redirects');
        $assertOfferUrl($response['location'], $latestClick($fixture['id'])['id'], $offerId, $schemaType . ' direct');
    }

    $clickApi = static function (array $fixture, array $extra = []) use ($request): array {
        $response = $request('/click_api/v3?' . http_build_query(array_merge([
            'token' => $fixture['token'], 'info' => 1, 'sub_id_1' => 'campaign & value',
            'ip' => '203.0.113.200', 'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)',
        ], $extra)));
        $json = json_decode($response['body'], true);
        if ($response['code'] !== 200 || !is_array($json) || empty($json['info']['sub_id'])) {
            throw new RuntimeException('Invalid Click API response: ' . substr($response['body'], 0, 500));
        }
        return $json;
    };
    $apiLocation = static function (array $json): string {
        foreach ($json['headers'] ?? [] as $header) {
            if (stripos($header, 'Location:') === 0) {
                return trim(substr($header, 9));
            }
        }
        return '';
    };
    foreach (['redirect', 'landing_offer', 'cloak'] as $schemaType) {
        $fixture = $campaign($schemaType, $offerId);
        $json = $clickApi($fixture);
        $assertOfferUrl($apiLocation($json), $json['info']['sub_id'], $offerId, 'Click API/' . $schemaType);
    }
    $fixture = $campaign('landing_offer', $offerId, $landingId);
    $json = $clickApi($fixture);
    $assert($json['info']['offer_link'], $href($json['body'], 'offer'), 'Click API: action landing keeps signed offer link');
    $signedUrl = $json['info']['offer_link'];
    $assert($harness->getBaseUrl(), parse_url($signedUrl, PHP_URL_SCHEME) . '://' . parse_url($signedUrl, PHP_URL_HOST)
        . ':' . parse_url($signedUrl, PHP_URL_PORT), 'Click API: signed link points to local tracker');
    // router.php assigns / to the SPA; /index.php reaches the same LP handler.
    $hop = $request('/index.php?' . parse_url($signedUrl, PHP_URL_QUERY));
    $assert(302, $hop['code'], 'Click API: signed offer link redirects');
    $assertOfferUrl($hop['location'], $json['info']['sub_id'], $offerId, 'Click API/signed LP');
    $forced = $clickApi($fixture, ['force_redirect_offer' => 1]);
    $assertOfferUrl($apiLocation($forced), $forced['info']['sub_id'], $offerId, 'Click API/force offer');

    $fixture = $campaign('redirect', $offerId);
    $legacyPath = '/click.php?token=' . rawurlencode($fixture['token']);
    $legacy = $request($legacyPath . '&sub_id_1=' . rawurlencode('campaign & value'));
    $assert(302, $legacy['code'], 'click.php: registered offer redirects');
    $assertOfferUrl($legacy['location'], $latestClick($fixture['id'])['id'], $offerId, 'click.php');
    $overrideUrl = 'https://offers.example/override?own=1#override';
    $override = $request($legacyPath . '&url=' . rawurlencode($overrideUrl));
    $assert(302, $override['code'], 'click.php: explicit allowed URL still redirects');
    $assert($overrideUrl, $override['location'], 'click.php: explicit URL does not inherit a selected offer network');
    $denied = $request($legacyPath . '&url=' . rawurlencode('https://unregistered.example/'));
    $assert(400, $denied['code'], 'click.php: redirect domain allowlist remains enforced');

    // Unrelated destinations and uploaded offers remain unaffected.
    $fixture = $campaign('redirect', $plainOfferId);
    $assert($plainUrl, $request('/' . $fixture['alias'])['location'], 'No network: index destination unchanged');
    $assert($plainUrl, $apiLocation($clickApi($fixture)), 'No network: Click API destination unchanged');
    $rawUrl = 'https://raw.example/path?raw=a%2Fb#raw';
    $fixture = $campaign('redirect', $offerId, null, 'before', ['direct_url' => $rawUrl]);
    $assert($rawUrl, $request('/' . $fixture['alias'])['location'], 'Raw stream URL: no network attached');
    $externalLandingUrl = 'https://landing.example/page?own=1#landing';
    $externalLanding = $insert('landings', ['name' => 'External landing', 'type' => 'redirect', 'url' => $externalLandingUrl]);
    $fixture = $campaign('landing_offer', $offerId, $externalLanding);
    $assert($externalLandingUrl, $request('/' . $fixture['alias'])['location'], 'External landing does not inherit offer network parameters');
    $assert($externalLandingUrl, $apiLocation($clickApi($fixture)), 'Click API external landing does not inherit offer network parameters');

    // Resolve the entire offer before embedding it in a landing's query. A
    // second macro pass after URL-encoding would leave %7Bsubid%7D unresolved.
    $nestedLanding = $insert('landings', [
        'name' => 'Nested offer landing', 'type' => 'redirect',
        'url' => 'https://landing.example/page?destination={offer}',
    ]);
    $fixture = $campaign('landing_offer', $offerId, $nestedLanding);
    $nested = $request('/' . $fixture['alias'] . $traffic);
    $nestedQuery = [];
    parse_str((string) parse_url($nested['location'], PHP_URL_QUERY), $nestedQuery);
    $assert(302, $nested['code'], 'Nested offer: landing redirects');
    $assert(['destination'], array_keys($nestedQuery), 'Nested offer: network parameters stay inside the offer URL');
    $assertOfferUrl($nestedQuery['destination'] ?? '', $latestClick($fixture['id'])['id'], $offerId, 'Nested offer/index');
    $nestedApi = $clickApi($fixture);
    parse_str((string) parse_url($apiLocation($nestedApi), PHP_URL_QUERY), $nestedQuery);
    $assert(['destination'], array_keys($nestedQuery), 'Nested offer/Click API: network parameters stay inside the offer URL');
    $assertOfferUrl($nestedQuery['destination'] ?? '', $nestedApi['info']['sub_id'], $offerId, 'Nested offer/Click API');

    // Built-in networks can use path suffixes instead of a query parameter.
    $pathNetwork = $insert('affiliate_networks', [
        'name' => 'Path suffix network', 'offer_params' => '/{sub_id_1}/{subid}?selected={offer_id}&fixed=network',
    ]);
    $pathOffer = $insert('offers', [
        'name' => 'Path suffix offer', 'affiliate_network_id' => $pathNetwork,
        'url' => 'https://path.example/offer?encoded=a%2Fb&fixed=offer#path',
    ]);
    $expectedPath = static function (string $clickId) use ($pathOffer): string {
        return 'https://path.example/offer/campaign+%26+value/' . $clickId
            . '?encoded=a%2Fb&fixed=offer&selected=' . $pathOffer . '#path';
    };
    $fixture = $campaign('redirect', $pathOffer);
    $pathResponse = $request('/' . $fixture['alias'] . $traffic);
    $assert(302, $pathResponse['code'], 'Path suffix/index: redirects');
    $assert($expectedPath($latestClick($fixture['id'])['id']), $pathResponse['location'], 'Path suffix/index: inserted before original query and fragment');
    $pathApi = $clickApi($fixture);
    $assert($expectedPath($pathApi['info']['sub_id']), $apiLocation($pathApi), 'Path suffix/Click API: same destination composition');
    $fixture = $campaign('landing_offer', $pathOffer, $landingId, 'after');
    $pathView = $request('/' . $fixture['alias'] . $traffic);
    $pathClick = $latestClick($fixture['id']);
    $pathHop = $request($href($pathView['body'], 'transition'));
    $assert(302, $pathHop['code'], 'Path suffix/LP: redirects');
    $assert($expectedPath($pathClick['id']), $pathHop['location'], 'Path suffix/LP: original click and saved parameters resolve');

    $fixture = $campaign('redirect', $localOfferId);
    $local = $request('/' . $fixture['alias']);
    $assert(200, $local['code'], 'Local offer: served inline');
    $assert(true, strpos($local['body'], 'LOCAL_NETWORK_OFFER') !== false, 'Local offer: uploaded content served');
    $assert('', $local['location'], 'Local offer: no affiliate redirect');
    $assert('/offers/' . $localOfferId . '/', $apiLocation($clickApi($fixture)), 'Click API local offer: clean local route');
    $fixture = $campaign('landing_offer', $localOfferId, $landingId);
    $view = $request('/' . $fixture['alias']);
    $localHop = $request($href($view['body'], 'transition'));
    $assert(200, $localHop['code'], 'Local offer LP hop: served inline');
    $assert(true, strpos($localHop['body'], 'LOCAL_NETWORK_OFFER') !== false, 'Local offer LP hop: uploaded content served');

    // Composition happens at read time: changing the shared network applies to
    // existing offers, without saving a copy of its parameters into every URL.
    $pdo->prepare('UPDATE affiliate_networks SET offer_params = ? WHERE id = ?')->execute(['fresh={subid}', $networkId]);
    $fixture = $campaign('redirect', $offerId);
    $fresh = $request('/' . $fixture['alias']);
    $assert($baseUrl . '&fresh=' . $latestClick($fixture['id'])['id'] . '#checkout', $fresh['location'], 'Network edit applies to an existing offer');
    $stmt = $pdo->prepare('SELECT url FROM offers WHERE id = ?');
    $stmt->execute([$offerId]);
    $assert($baseUrl . '#checkout', $stmt->fetchColumn(), 'Stored offer URL is not rewritten by serving clicks');
} catch (Throwable $e) {
    $failures++;
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
} finally {
    $harness->stop();
}

echo "Affiliate network HTTP tests: {$checks} checks, {$failures} failures.\n";
exit($failures === 0 ? 0 : 1);
