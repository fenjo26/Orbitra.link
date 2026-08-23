<?php
// tests/kclient_php_client_test.php
//
// The Tracking Client (PHP) panel now ships ONE snippet — executeAndBreak(),
// with debug() and execute() sitting next to it as comments — plus a
// secondary-page snippet and a getOffer() link recipe. This pins the three
// pieces of behaviour those blocks promise, because each of them was wrong
// when the panel was first pointed at a live campaign:
//
//   1. core/click_api.php substituted only {clickid}. An offer URL written
//      with {subid} — the Keitaro spelling, and what most networks ask for —
//      reached the network EMPTY when the visitor arrived through KClient,
//      while the same campaign link through index.php filled it in. The
//      conversion postback then had no click to attach itself to.
//   2. getOffer(ID) is what the panel documents, and the client only accepted
//      array('offer_id' => N). It also appended a second offer_id to a
//      transition link that already carried one.
//   3. restoreFromQuery() restored the click but not the offer, so the
//      "How to link to the offer" block rendered an empty href on exactly the
//      secondary pages the block above it tells you to use.
//
// Run: php tests/kclient_php_client_test.php

require_once __DIR__ . '/lib/http.php';

$failures = 0;
$assert = function (string $label, $got, $expected) use (&$failures) {
    $ok = $got === $expected;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label;
    if (!$ok) {
        echo ' — got ' . var_export($got, true) . ', expected ' . var_export($expected, true);
        $failures++;
    }
    echo "\n";
};
$assertTrue = function (string $label, bool $ok) use (&$failures) {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

// ---------------------------------------------------------------------------
// 1. Click API macro substitution, over real HTTP
// ---------------------------------------------------------------------------

echo "Click API: destination-URL macros\n";

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->start();

try {
    $pdo = $harness->getPdo();

    $offerId    = random_int(10000, 99999);
    $campaignId = random_int(10000, 99999);
    $streamId   = random_int(10000, 99999);
    $token      = 'kclient_' . bin2hex(random_bytes(6));

    $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived)
                   VALUES (?, 'KClient offer', ?, 0, 'active', 0)")
        ->execute([$offerId, 'https://example.com/o?click={subid}&cid={clickid}&geo={country}']);

    $pdo->prepare("INSERT INTO campaigns (id, name, alias, token, state, is_archived)
                   VALUES (?, 'KClient PHP', 'kclientphp', ?, 'active', 0)")
        ->execute([$campaignId, $token]);

    $pdo->prepare("INSERT INTO streams (id, campaign_id, offer_id, name, weight, is_active, type, position, schema_type)
                   VALUES (?, ?, ?, 'to offer', 100, 1, 'regular', 1, 'redirect')")
        ->execute([$streamId, $campaignId, $offerId]);

    $resp = $harness->getWithHeaders('/click_api/v3?token=' . urlencode($token) . '&info=1', [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
    ]);

    $body = json_decode((string) $resp['body'], true);
    $location = '';
    foreach ((array) ($body['headers'] ?? []) as $h) {
        if (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        }
    }
    if ($location === '') {
        $location = (string) ($resp['headers']['Location'] ?? '');
    }

    $clickId = (string) ($body['info']['sub_id'] ?? '');

    $assertTrue('Click API answered with a destination URL', $location !== '');
    $assertTrue('the click id reached the response', $clickId !== '');
    // The point of the whole test: {subid} must not travel to the network as
    // an empty value (or, worse, as the literal "{subid}").
    $assertTrue('{subid} carries the click id', $clickId !== '' && strpos($location, 'click=' . $clickId) !== false);
    $assertTrue('{clickid} carries the click id', $clickId !== '' && strpos($location, 'cid=' . $clickId) !== false);
    $assertTrue('no literal macro survives into the URL', strpos($location, '{') === false);
} finally {
    $harness->stop();
}

// ---------------------------------------------------------------------------
// 2. getOffer(): the form the panel documents
// ---------------------------------------------------------------------------

echo "\nKClickClient::getOffer()\n";

require_once dirname(__DIR__) . '/kclient.php';

$makeClient = static function (?string $offerUrl): KClickClient {
    $ref = new ReflectionClass('KClickClient');
    $client = $ref->newInstanceWithoutConstructor();
    // Pretend the click already happened: perform() must not go to the network.
    foreach (['executed' => true, 'offerUrl' => $offerUrl] as $prop => $value) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($client, $value);
    }
    return $client;
};

$c = $makeClient('https://a.b/x');
$assert('bare id appends offer_id', $c->getOffer(7), 'https://a.b/x?offer_id=7');
$assert('numeric string behaves like the int', $makeClient('https://a.b/x')->getOffer('7'), 'https://a.b/x?offer_id=7');
$assert('array form still works', $makeClient('https://a.b/x')->getOffer(['offer_id' => 7]), 'https://a.b/x?offer_id=7');
$assert('no id leaves the URL alone', $makeClient('https://a.b/x')->getOffer(), 'https://a.b/x');

// The transition link the tracker hands back already carries offer_id. Two of
// them in one URL is a coin flip on which one the far end reads.
$assert('existing offer_id is replaced, not doubled',
    $makeClient('https://a.b/x?offer_id=9')->getOffer(7), 'https://a.b/x?offer_id=7');
$assert('other params survive the replacement',
    $makeClient('https://a.b/x?a=1&offer_id=9&b=2')->getOffer(7), 'https://a.b/x?a=1&b=2&offer_id=7');
$assert('leading offer_id leaves no stray separator',
    $makeClient('https://a.b/x?offer_id=9&a=1')->getOffer(7), 'https://a.b/x?a=1&offer_id=7');

$assert('unresolved offer returns null', $makeClient(null)->getOffer(7), null);
$assert('unresolved offer takes the default', $makeClient(null)->getOffer(null, 'https://fallback'), 'https://fallback');

// ---------------------------------------------------------------------------
// 3. restoreFromQuery(): the click AND, on the same site, the offer
// ---------------------------------------------------------------------------

echo "\nKClickClient::restoreFromQuery()\n";

$restore = static function (string $querySubid, ?string $sessionSubid, ?string $sessionOffer): array {
    $ref = new ReflectionClass('KClickClient');
    $client = $ref->newInstanceWithoutConstructor();
    $useSessions = $ref->getProperty('useSessions');
    $useSessions->setAccessible(true);
    $useSessions->setValue($client, false);   // no real session in CLI

    $_GET['_subid'] = $querySubid;
    $_SESSION = [];
    if ($sessionSubid !== null) {
        $_SESSION['orbitra_kclient_subid'] = $sessionSubid;
    }
    if ($sessionOffer !== null) {
        $_SESSION['orbitra_kclient_offer'] = $sessionOffer;
    }

    $client->restoreFromQuery();

    $offer = $ref->getProperty('offerUrl');
    $offer->setAccessible(true);
    return [$client->getSubid(), $offer->getValue($client)];
};

[$subid, $offer] = $restore('click-1', 'click-1', 'https://a.b/offer');
$assert('the click comes from the URL', $subid, 'click-1');
$assert('the offer comes from the session of the same click', $offer, 'https://a.b/offer');

[$subid, $offer] = $restore('click-1', 'click-2', 'https://a.b/other');
$assert('a different click still restores', $subid, 'click-1');
$assert('but never borrows the other click\'s offer', $offer, null);

[$subid, $offer] = $restore('click-1', null, null);
$assert('no session at all is fine', $subid, 'click-1');
$assert('and leaves the offer unresolved', $offer, null);

echo "\n";
if ($failures) {
    fwrite(STDERR, "KClient PHP client tests: $failures failure(s).\n");
    exit(1);
}
echo "KClient PHP client tests passed.\n";
