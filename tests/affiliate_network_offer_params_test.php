<?php
/**
 * Regression coverage for affiliate-network defaults on registered offer URLs.
 * Uses synthetic data in SQLite memory; never loads the application database.
 *
 * Run: php tests/affiliate_network_offer_params_test.php
 */

require_once __DIR__ . '/../core/OfferUrl.php';

$failures = 0;
$assertions = 0;
$assert = static function (string $label, $actual, $expected) use (&$failures, &$assertions): void {
    $assertions++;
    if ($actual !== $expected) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . "\n  got: " . var_export($actual, true)
            . "\n  expected: " . var_export($expected, true) . "\n");
    }
};

// Compare complete URLs: parsing and rebuilding a query can silently corrupt
// signed values, repeated keys, punctuation in names, or a checkout redirect.
$cases = [
    ['queryless destination', 'https://net.example/offer', '&subid={subid}', 'https://net.example/offer?subid={subid}'],
    ['existing query', 'https://net.example/offer?aff_id=42', '&subid={subid}', 'https://net.example/offer?aff_id=42&subid={subid}'],
    ['question-mark prefix', 'https://net.example/offer?a=1', '?sub1={subid}', 'https://net.example/offer?a=1&sub1={subid}'],
    ['no prefix', 'https://net.example/offer', 'aff_sub={clickid}', 'https://net.example/offer?aff_sub={clickid}'],
    ['empty query', 'https://net.example/offer?', '&s1={subid}', 'https://net.example/offer?s1={subid}'],
    ['trailing query separator', 'https://net.example/offer?a=1&', '&s1={subid}', 'https://net.example/offer?a=1&s1={subid}'],
    ['fragment follows appended query', 'https://net.example/offer#checkout', '&s1={subid}', 'https://net.example/offer?s1={subid}#checkout'],
    ['fragment bytes and query markers stay intact', 'https://net.example/offer?a=1#tab?subid=fragment&x=%2f', '&subid={subid}', 'https://net.example/offer?a=1&subid={subid}#tab?subid=fragment&x=%2f'],
    ['empty query before fragment', 'https://net.example/offer?#checkout', '&s1={subid}', 'https://net.example/offer?s1={subid}#checkout'],
    ['manual tracking workaround wins', 'https://net.example/offer?subid={subid}', '&subid={subid}&source={source}', 'https://net.example/offer?subid={subid}&source={source}'],
    ['offer-specific literal wins', 'https://net.example/offer?subid=custom', '&subid={subid}', 'https://net.example/offer?subid=custom'],
    ['explicit empty value wins', 'https://net.example/offer?subid=', '&subid={subid}&x=1', 'https://net.example/offer?subid=&x=1'],
    ['bare explicit key wins', 'https://net.example/offer?subid', '&subid={subid}', 'https://net.example/offer?subid'],
    ['all existing repeats are preserved', 'https://net.example/offer?subid=first&subid=second', '&subid={subid}&tag=a&tag=b', 'https://net.example/offer?subid=first&subid=second&tag=a&tag=b'],
    ['network repeated values are preserved', 'https://net.example/offer', '&tag=a&tag=b', 'https://net.example/offer?tag=a&tag=b'],
    ['explicit key masks every matching network repeat', 'https://net.example/offer?tag=', '&tag=a&tag=b&x=1', 'https://net.example/offer?tag=&x=1'],
    ['percent-encoded key collision', 'https://net.example/offer?sub%69d=custom', '&subid={subid}&x=1', 'https://net.example/offer?sub%69d=custom&x=1'],
    ['encoded default key collision', 'https://net.example/offer?subid=custom', '&sub%69d={subid}', 'https://net.example/offer?subid=custom'],
    ['form-encoded key collision', 'https://net.example/offer?sub+id=custom', '&sub%20id={subid}', 'https://net.example/offer?sub+id=custom'],
    ['key comparisons remain case sensitive', 'https://net.example/offer?SubID=custom', '&subid={subid}', 'https://net.example/offer?SubID=custom&subid={subid}'],
    ['query keys are decoded only once', 'https://net.example/offer?%2573ubid=custom', '&subid={subid}', 'https://net.example/offer?%2573ubid=custom&subid={subid}'],
    ['query values containing equal signs', 'https://net.example/offer?redirect=YWJjLys9==&a.b=1&a+b=2&tag=x&tag=y', '&subid={subid}&opaque=A%2fb+%20%3D', 'https://net.example/offer?redirect=YWJjLys9==&a.b=1&a+b=2&tag=x&tag=y&subid={subid}&opaque=A%2fb+%20%3D'],
    ['array query names retain bytes', 'https://net.example/offer?tags[]=explicit', '&tags%5B%5D=default&other%5B%5D=a&other%5B%5D=b', 'https://net.example/offer?tags[]=explicit&other%5B%5D=a&other%5B%5D=b'],
    ['schemeless host and path', 'net.example/offer?a=1', '&subid={subid}', 'net.example/offer?a=1&subid={subid}'],
    ['schemeless host with port', 'net.example:8080/offer', '&subid={subid}', 'net.example:8080/offer?subid={subid}'],
    ['protocol-relative host', '//net.example/offer', '&subid={subid}', '//net.example/offer?subid={subid}'],
    ['uppercase HTTP scheme', 'HTTPS://net.example/offer', '&subid={subid}', 'HTTPS://net.example/offer?subid={subid}'],
    ['empty defaults', 'https://net.example/offer?x=1#f', '', 'https://net.example/offer?x=1#f'],
    ['separator-only defaults', 'https://net.example/offer?x=1#f', '?&&', 'https://net.example/offer?x=1#f'],
    ['empty destination', '', '&subid={subid}', ''],
    ['root-relative local destination', '/lander/offer.php?x=1', '&subid={subid}', '/lander/offer.php?x=1'],
    ['current-directory local destination', './offer.php', '&subid={subid}', './offer.php'],
    ['parent-directory local destination', '../offer.php', '&subid={subid}', '../offer.php'],
    ['query-only destination', '?page=offer', '&subid={subid}', '?page=offer'],
    ['fragment-only destination', '#checkout', '&subid={subid}', '#checkout'],
    ['mailto destination', 'mailto:support@example.com', '&subid={subid}', 'mailto:support@example.com'],
    ['telephone destination', 'tel:+123456789', '&subid={subid}', 'tel:+123456789'],
    ['custom application scheme', 'myapp://checkout?item=1', '&subid={subid}', 'myapp://checkout?item=1'],
    ['non-HTTP file transfer', 'ftp://net.example/file', '&subid={subid}', 'ftp://net.example/file'],
    ['LeadForge full endpoint is not a query', 'https://net.example/offer', 'https://api.net.example/lead?key=synthetic', 'https://net.example/offer'],
    ['protocol-relative endpoint is not a query', 'https://net.example/offer', '//api.net.example/lead', 'https://net.example/offer'],
    ['endpoint with accidental delimiter is not a query', 'https://net.example/offer', '&https://api.net.example/lead', 'https://net.example/offer'],
    ['embedded header control is rejected', 'https://net.example/offer', "&subid={subid}\r\nX-Header: value", 'https://net.example/offer'],
    ['network fragment cannot hide tracking fields', 'https://net.example/offer#checkout', '&subid={subid}#other', 'https://net.example/offer#checkout'],
    ['built-in path macro suffix', 'https://net.example/offer', '/{subid}', 'https://net.example/offer/{subid}'],
    ['built-in source and click path suffix', 'https://net.example/offer', '/{source}/{subid}', 'https://net.example/offer/{source}/{subid}'],
    ['path suffix precedes original query and fragment', 'https://net.example/offer?aff_id=42#checkout', '/{source}/{subid}', 'https://net.example/offer/{source}/{subid}?aff_id=42#checkout'],
    ['path suffix joins an existing trailing slash', 'https://net.example/offer/', '/{subid}', 'https://net.example/offer/{subid}'],
    ['path suffix preserves repeated existing slashes', 'https://net.example/offer///', '/{subid}', 'https://net.example/offer///{subid}'],
    ['manually supplied path macro is not duplicated', 'https://net.example/offer/{subid}?a=1#checkout', '/{subid}', 'https://net.example/offer/{subid}?a=1#checkout'],
    ['path suffix and query defaults work together', 'https://net.example/offer?aff_id=42#checkout', '/{subid}?aff_id=default&source={source}', 'https://net.example/offer/{subid}?aff_id=42&source={source}#checkout'],
    ['existing suffix still receives missing query defaults', 'https://net.example/offer/{subid}?a=1', '/{subid}?source={source}', 'https://net.example/offer/{subid}?a=1&source={source}'],
    ['local destination does not receive a path suffix', '/lander/offer.php', '/{subid}', '/lander/offer.php'],
];

foreach ($cases as [$label, $url, $defaults, $expected]) {
    $assert($label, orbitraAppendOfferParams($url, $defaults), $expected);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE affiliate_networks (
    id INTEGER PRIMARY KEY, offer_params TEXT, state TEXT, is_archived INTEGER DEFAULT 0
)');
$pdo->exec('CREATE TABLE offers (
    id INTEGER PRIMARY KEY, affiliate_network_id INTEGER, url TEXT,
    redirect_type TEXT DEFAULT "redirect", is_local INTEGER DEFAULT 0,
    state TEXT DEFAULT "active", is_archived INTEGER DEFAULT 0
)');

$insertNetwork = $pdo->prepare('INSERT INTO affiliate_networks (id, offer_params, state, is_archived) VALUES (?, ?, ?, ?)');
foreach ([
    [1, '&subid={subid}&source={source}&offer={offer_id}&ip={ip}&country={country}&missing={utm_term}', 'active', 0],
    [2, '&aff_sub={clickid}', 'paused', 0],
    [3, '&s1={subid}', 'active', 1],
    [4, null, 'active', 0],
    [5, '', 'active', 0],
    [6, 'https://api.net.example/lead', 'active', 0],
] as $network) {
    $insertNetwork->execute($network);
}

$insertOffer = $pdo->prepare('INSERT INTO offers (id, affiliate_network_id, url, redirect_type, is_local) VALUES (?, ?, ?, ?, ?)');
foreach ([
    [1, 1, 'https://net.example/offer?aff_id=42#checkout', 'meta', 0],
    [2, null, 'https://net.example/plain?x=%2f', 'redirect', 0],
    [3, 999, 'https://net.example/missing', 'redirect', 0],
    [4, 4, 'https://net.example/null-params', 'redirect', 0],
    [5, 5, 'https://net.example/empty-params', 'redirect', 0],
    [6, 2, 'https://net.example/paused', 'redirect', 0],
    [7, 3, 'https://net.example/archived', 'redirect', 0],
    [8, 1, '', 'redirect', 1],
    [9, 1, 'https://net.example/stored-local-url', 'iframe', 1],
    [10, 1, null, 'redirect', 1],
    [11, 6, 'https://net.example/leadforge', 'redirect', 0],
    [12, 1, 'https://net.example/manual?subid={subid}', 'redirect', 0],
] as $offer) {
    $insertOffer->execute($offer);
}

// Destination assembly must be a read operation, including for local offers.
$pdo->exec('PRAGMA query_only = ON');
$destination = orbitraGetOfferDestination($pdo, 1);
$assert('registered offer receives the associated network defaults', $destination['url'],
    'https://net.example/offer?aff_id=42&subid={subid}&source={source}&offer={offer_id}&ip={ip}&country={country}&missing={utm_term}#checkout');
$assert('redirect strategy survives loading', $destination['redirect_type'], 'meta');
$assert('local flag survives loading', (int) $destination['is_local'], 0);
$assert('unregistered offer stays missing', orbitraGetOfferDestination($pdo, 999), null);

foreach ([
    2 => 'https://net.example/plain?x=%2f',
    3 => 'https://net.example/missing',
    4 => 'https://net.example/null-params',
    5 => 'https://net.example/empty-params',
    6 => 'https://net.example/paused?aff_sub={clickid}',
    7 => 'https://net.example/archived?s1={subid}',
    8 => '',
    9 => 'https://net.example/stored-local-url',
    11 => 'https://net.example/leadforge',
] as $offerId => $expected) {
    $assert('network/local handling for offer ' . $offerId, orbitraGetOfferDestination($pdo, $offerId)['url'], $expected);
}

$assert('null local URL still resolves to an empty destination',
    orbitraResolveOfferUrlMacros(orbitraGetOfferDestination($pdo, 10)['url'], 'click-test', 10, [], []), '');
$assert('network macros resolve with the originating click after composition',
    orbitraResolveOfferUrlMacros($destination['url'], 'click-test', 1, ['source' => 'ad campaign'], ['ip' => '203.0.113.9', 'country' => 'United States']),
    'https://net.example/offer?aff_id=42&subid=click-test&source=ad+campaign&offer=1&ip=203.0.113.9&country=United+States&missing=#checkout');
$assert('existing tracking workaround remains single after macro resolution',
    orbitraResolveOfferUrlMacros(orbitraGetOfferDestination($pdo, 12)['url'], 'click-test', 12, [], []),
    'https://net.example/manual?subid=click-test&source=&offer=12&ip=&country=&missing=');
$assert('another network can use a different click-id parameter',
    orbitraResolveOfferUrlMacros(orbitraGetOfferDestination($pdo, 6)['url'], 'other-click', 6, [], []),
    'https://net.example/paused?aff_sub=other-click');
$assert('path macros resolve with the same click context as query macros',
    orbitraResolveOfferUrlMacros(orbitraAppendOfferParams('https://net.example/offer?aff_id=42#checkout', '/{source}/{subid}'),
        'click-test', 1, ['source' => 'social'], []),
    'https://net.example/offer/social/click-test?aff_id=42#checkout');

$assert('composition never writes defaults back into stored offer URLs',
    $pdo->query('SELECT url FROM offers WHERE id = 1')->fetchColumn(), 'https://net.example/offer?aff_id=42#checkout');

// A network edit must affect existing offers on the next lookup, without
// rewriting every offer or keeping a stale process-level destination cache.
$pdo->exec('PRAGMA query_only = OFF');
$pdo->exec("UPDATE affiliate_networks SET offer_params = '&new_click={subid}' WHERE id = 1");
$pdo->exec('PRAGMA query_only = ON');
$assert('subsequent lookups observe network edits', orbitraGetOfferDestination($pdo, 1)['url'],
    'https://net.example/offer?aff_id=42&new_click={subid}#checkout');

if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} of {$assertions} assertions\n");
    exit(1);
}
echo "ok: {$assertions} affiliate network offer parameter assertions\n";
