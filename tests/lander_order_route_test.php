<?php
// tests/lander_order_route_test.php
//
// The LeadForge order bridge on its own URLs:
//   - /lander/<slug>/order.php and /offers/<id>/order.php used to die with
//     404: the LeadForge form rewrite points action at a relative order.php,
//     the <base> the routes inject resolves it right back onto the route, and
//     the asset whitelist never serves .php. These routes now execute the
//     handlers in-process, gated by the same switch/budget as the root bridge.
//   - The domain-root /order.php bridge falls back to the Referer when no
//     cookie names the page (blocked cookies, absolute action from a
//     /lander/<slug>/ preview).
//   - success.php, the page order.php redirects to with a relative Location.
//     It was missing from the handler whitelist, so a lead could reach the
//     network and the buyer still land on a 404 one hop later.
//   - The whitelist stops at the bundle: /api.php stays the tracker's own
//     admin API even while a bundle cookie is set, and a .php that is not a
//     known handler is still never executed.
//   - orbitraDefineOfferContext(): the ORBITRA_OFFER_* constants a bundle uses
//     to build an absolute URL for itself, and orbitraAbsolutizeBundleActions(),
//     which rewrites the relative form action a bundle ships so the lead POST
//     does not land on the campaign root.
//
// The code under test is extracted from index.php, not copied — index.php
// cannot be required standalone (it IS the router). __DIR__ inside the
// extracted code is substituted with ORBITRA_INDEX_DIR so the paths match
// production; every scenario runs in a child process because the router
// functions exit.
//
// Run: php tests/lander_order_route_test.php

$repoRoot = dirname(__DIR__);
$pid = getmypid();
$slug = 'orderbridge' . $pid;
$siblingSlug = 'orderbridge-sib' . $pid;
$offerId = 90000000 + ($pid % 99999);

/** Extract a top-level function from index.php by name (body braces are indented, so "\n}" only ends the function). */
$extractFunction = static function (string $name) use ($repoRoot): string {
    $src = file_get_contents($repoRoot . '/index.php');
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\(.*?\n\}/ms', $src, $m)) {
        fwrite(STDERR, "could not extract function {$name} from index.php\n");
        exit(1);
    }
    return str_replace('__DIR__', 'ORBITRA_INDEX_DIR', $m[0]);
};

/** Extract the top-level order-bridge block (comment-to-comment anchors, like settings_seed_v30_test.php). */
$extractBridge = static function () use ($repoRoot): string {
    $src = file_get_contents($repoRoot . '/index.php');
    $start = strpos($src, '// Local-offer / local-landing order bridge (LeadForge):');
    $end = strpos($src, '// A local offer\'s own address, /offers/<id>/');
    if ($start === false || $end === false || $end <= $start) {
        fwrite(STDERR, "could not locate the order-bridge block in index.php\n");
        exit(1);
    }
    return str_replace('__DIR__', 'ORBITRA_INDEX_DIR', substr($src, $start, $end - $start));
};

/** Fixture PDO + real landing/offers directory trees, removed on shutdown. */
$makeFixture = static function () use ($repoRoot, $slug, $siblingSlug, $offerId): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE landings (id INTEGER PRIMARY KEY, slug TEXT, type TEXT, is_archived INTEGER DEFAULT 0)');
    $pdo->exec('CREATE TABLE offers (id INTEGER PRIMARY KEY, is_local INTEGER DEFAULT 0, state TEXT DEFAULT "active")');
    $pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)');
    $pdo->prepare('INSERT INTO landings (id, slug, type) VALUES (1, ?, "local")')->execute([$slug]);
    $pdo->prepare('INSERT INTO landings (id, slug, type) VALUES (2, ?, "local")')->execute([$siblingSlug]);
    $pdo->prepare('INSERT INTO offers (id, is_local, state) VALUES (?, 1, "active")')->execute([$offerId]);

    $landingDir = $repoRoot . '/landings/' . $slug;
    $siblingDir = $repoRoot . '/landings/' . $siblingSlug;
    $offerDir = $repoRoot . '/offers/' . $offerId;
    foreach ([$landingDir, $siblingDir, $offerDir] as $d) {
        @mkdir($d, 0775, true);
    }
    file_put_contents($landingDir . '/index.html', '<html><body>form</body></html>');
    file_put_contents($landingDir . '/order.php', '<?php echo "LANDER_ORDER_OK:" . ($pdo instanceof PDO ? "pdo" : "nopdo");');
    file_put_contents($landingDir . '/thank_you.php', '<?php echo "LANDER_TY_OK:" . ($pdo instanceof PDO ? "pdo" : "nopdo");');
    file_put_contents($landingDir . '/not_a_handler.php', '<?php echo "should never run";');
    file_put_contents($siblingDir . '/order.php', '<?php echo "SIBLING_ORDER_RAN";');
    file_put_contents($offerDir . '/index.html', '<html><body>offer</body></html>');
    file_put_contents($offerDir . '/order.php', '<?php echo "OFFER_ORDER_OK:" . ($pdo instanceof PDO ? "pdo" : "nopdo");');
    file_put_contents($offerDir . '/success.php', '<?php echo "OFFER_SUCCESS_OK:" . ($pdo instanceof PDO ? "pdo" : "nopdo");');
    // Checks the offer context against its own location, since the fixture id
    // comes from the pid and the parent process does not share it.
    file_put_contents(
        $offerDir . '/thank_you.php',
        '<?php $me = basename(__DIR__); echo "OFFER_CTX:"'
        . ' . (defined("ORBITRA_OFFER_ID") && ORBITRA_OFFER_ID === (int) $me ? "id" : "-")'
        . ' . "|" . (defined("ORBITRA_OFFER_URL") && ORBITRA_OFFER_URL === "/offers/" . $me . "/" ? "url" : "-")'
        . ' . "|" . (defined("ORBITRA_OFFER_PATH") && ORBITRA_OFFER_PATH === __DIR__ . "/" ? "path" : "-");'
    );
    file_put_contents($offerDir . '/api.php', '<?php echo "OFFER_API_RAN";');

    register_shutdown_function(static function () use ($landingDir, $siblingDir, $offerDir): void {
        foreach ([$landingDir, $siblingDir, $offerDir] as $d) {
            foreach ((array) glob($d . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($d);
        }
    });
    return $pdo;
};

/** The real resolvers + the extracted router code, with the asset servers stubbed. */
$loadRouter = static function () use ($repoRoot, $extractFunction): void {
    require_once $repoRoot . '/core/landing_path.php';
    require_once $repoRoot . '/core/PhpLanding.php';
    define('ORBITRA_INDEX_DIR', $repoRoot);
    eval($extractFunction('orbitraBundleHandlers'));
    eval($extractFunction('orbitraDefineOfferContext'));
    eval($extractFunction('orbitraOfferDir'));
    eval($extractFunction('orbitraOfferIsLocal'));
    eval($extractFunction('orbitraServeLanderPath'));
    eval($extractFunction('orbitraServeOfferPath'));
    // Stubs: the extracted functions call these for non-page paths; they must
    // record and return so the caller proceeds to its own 404.
    $GLOBALS['assetCalls'] = [];
    eval('function serveLandingAsset($id, $path) { $GLOBALS["assetCalls"][] = "lp:{$id}{$path}"; echo "ASSET lp:{$id}{$path}\n"; }
        function serveOfferAsset($id, $path) { $GLOBALS["assetCalls"][] = "lo:{$id}{$path}"; echo "ASSET lo:{$id}{$path}\n"; }');
};

// ------------------------------------------------------------ child scenarios
if (($argv[1] ?? '') !== '') {
    $scenario = $argv[1];
    $pdo = $makeFixture();
    $loadRouter();

    switch ($scenario) {
        case 'lander-order':
            orbitraServeLanderPath($pdo, $slug, 'order.php');
            break;
        case 'lander-thankyou':
            orbitraServeLanderPath($pdo, $slug, 'thank_you.php');
            break;
        case 'lander-other-php':
            orbitraServeLanderPath($pdo, $slug, 'not_a_handler.php');
            break;
        case 'lander-disabled':
            $pdo->exec("INSERT INTO settings (key, value) VALUES ('allow_php_landings', '0')");
            orbitraServeLanderPath($pdo, $slug, 'order.php');
            break;
        case 'lander-traversal':
            orbitraServeLanderPath($pdo, $slug, '../' . $siblingSlug . '/order.php');
            break;
        case 'lander-unknown-slug':
            orbitraServeLanderPath($pdo, 'nosuch' . $pid, 'order.php');
            break;
        case 'offer-order':
            orbitraServeOfferPath($pdo, $offerId, 'order.php');
            break;
        case 'offer-success':
            orbitraServeOfferPath($pdo, $offerId, 'success.php');
            break;
        case 'offer-context':
            orbitraServeOfferPath($pdo, $offerId, 'thank_you.php');
            break;
        case 'offer-api':
            // Unambiguous here: the id is in the URL, so a bundle whose sender
            // is named api.php runs its own file, not the tracker's.
            orbitraServeOfferPath($pdo, $offerId, 'api.php');
            break;
        case 'bridge-success-cookie':
            $uriPath = '/success.php';
            $_COOKIE = ['orbitra_lo' => (string) $offerId];
            eval($extractBridge());
            echo 'BRIDGE_FELL_THROUGH';
            break;
        case 'bridge-offer-context':
            $uriPath = '/thank_you.php';
            $_COOKIE = ['orbitra_lo' => (string) $offerId];
            eval($extractBridge());
            echo 'BRIDGE_FELL_THROUGH';
            break;
        case 'bridge-api-shadow':
            // /api.php is the tracker's admin API. A bundle cookie must not
            // make the root bridge hand it an uploaded api.php instead.
            $uriPath = '/api.php';
            $_COOKIE = ['orbitra_lo' => (string) $offerId];
            eval($extractBridge());
            echo 'BRIDGE_FELL_THROUGH';
            break;
        case 'bridge-cookie-lander':
            $uriPath = '/order.php';
            $_COOKIE = ['orbitra_lp' => '1'];
            eval($extractBridge());
            echo 'BRIDGE_FELL_THROUGH';
            break;
        case 'bridge-referer-lander':
            $uriPath = '/order.php';
            $_COOKIE = [];
            $_SERVER['HTTP_REFERER'] = 'https://track.example.com/lander/' . $slug . '/';
            eval($extractBridge());
            echo 'BRIDGE_FELL_THROUGH';
            break;
        case 'bridge-referer-offer':
            $uriPath = '/order.php';
            $_COOKIE = [];
            $_SERVER['HTTP_REFERER'] = 'https://track.example.com/offers/' . $offerId . '/';
            eval($extractBridge());
            echo 'BRIDGE_FELL_THROUGH';
            break;
        case 'bridge-referer-foreign':
            $uriPath = '/order.php';
            $_COOKIE = [];
            $_SERVER['HTTP_REFERER'] = 'https://elsewhere.example.com/some-page';
            eval($extractBridge());
            echo 'BRIDGE_FELL_THROUGH';
            break;
        default:
            fwrite(STDERR, "unknown scenario {$scenario}\n");
            exit(2);
    }
    exit(0);
}

// ------------------------------------------------------------------- parent
$failures = [];
$passed = 0;
$check = static function (string $name, string $needle, string $output) use (&$failures, &$passed): void {
    if (strpos($output, 'Fatal error') !== false || strpos($output, 'PHP Parse error') !== false) {
        $failures[] = "$name: child crashed: " . trim($output);
        return;
    }
    if (strpos($output, $needle) === false) {
        $failures[] = "$name: expected " . var_export($needle, true) . ' in: ' . trim($output);
    } else {
        $passed++;
        echo "ok  $name\n";
    }
};
$run = static function (string $scenario): string {
    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($scenario) . ' 2>&1');
};

// The tester's exact report: form on /lander/<slug>/, POST order.php → was 404.
$check('lander order.php executes in-process with $pdo in scope', 'LANDER_ORDER_OK:pdo', $run('lander-order'));
$check('lander thank_you.php executes (the order redirect target)', 'LANDER_TY_OK:pdo', $run('lander-thankyou'));
$out = $run('lander-other-php');
$check('non-bridge .php still 404s (whitelist intact)', '404 Not Found', $out);
$check('non-bridge .php went through the asset server, not execution', 'ASSET lp:1/not_a_handler.php', $out);
$check('PHP landings switch gates the lander bridge', 'Allow PHP landings', $run('lander-disabled'));
$check('traversal via ../sibling/order.php is contained', '404 Not Found', $run('lander-traversal'));
$check('unknown slug still 404s', '404 Not Found', $run('lander-unknown-slug'));
$check('offer order.php executes from the URL id', 'OFFER_ORDER_OK:pdo', $run('offer-order'));
$check('offer success.php executes (order.php redirects to it)', 'OFFER_SUCCESS_OK:pdo', $run('offer-success'));
$check('offer route runs a bundle api.php, not the tracker API', 'OFFER_API_RAN', $run('offer-api'));
$check('offer route defines ORBITRA_OFFER_ID/URL/PATH for the handler', 'OFFER_CTX:id|url|path', $run('offer-context'));

// Root bridge: cookie path regression + Referer fallback.
$check('root bridge still resolves via orbitra_lp cookie', 'LANDER_ORDER_OK:pdo', $run('bridge-cookie-lander'));
$check('root bridge falls back to a /lander/<slug>/ Referer', 'LANDER_ORDER_OK:pdo', $run('bridge-referer-lander'));
$check('root bridge falls back to an /offers/<id>/ Referer', 'OFFER_ORDER_OK:pdo', $run('bridge-referer-offer'));
$check('root bridge ignores a foreign Referer (falls through)', 'BRIDGE_FELL_THROUGH', $run('bridge-referer-foreign'));
$check('root bridge runs success.php for the cookie\'s offer', 'OFFER_SUCCESS_OK:pdo', $run('bridge-success-cookie'));
$check('root bridge defines the offer context too', 'OFFER_CTX:id|url|path', $run('bridge-offer-context'));
$check('root bridge never claims /api.php from a bundle', 'BRIDGE_FELL_THROUGH', $run('bridge-api-shadow'));

// ---------------------------------------------------------------- form action
// The reported 404: a local offer is printed at the campaign URL ("/pr6sxv41"),
// which has no trailing segment, so a bare action="order.php" posts the lead to
// the domain root. These rewrites put the offer's id in the action itself.
eval($extractFunction('orbitraBundleHandlers'));
eval($extractFunction('orbitraAbsolutizeBundleActions'));
$base = '/offers/5/';
$rewrite = static fn(string $html): string => orbitraAbsolutizeBundleActions($html, $base);
$same = static function (string $name, string $expected, string $actual) use (&$failures, &$passed): void {
    if ($actual !== $expected) {
        $failures[] = "$name: expected " . var_export($expected, true) . ' got ' . var_export($actual, true);
    } else {
        $passed++;
        echo "ok  $name\n";
    }
};

$same(
    'relative action and its lock both gain the offer prefix',
    '<form method="POST" action="/offers/5/order.php" data-leadforge-action-lock="/offers/5/order.php">',
    $rewrite('<form method="POST" action="order.php" data-leadforge-action-lock="order.php">')
);
$same(
    'a nested sender keeps its subdirectory',
    '<form action="/offers/5/api/success.php">',
    $rewrite('<form action="api/success.php">')
);
$same(
    'the ./ prefix is dropped, the query string is kept',
    '<form action="/offers/5/order.php?step=2">',
    $rewrite('<form action="./order.php?step=2">')
);
$same(
    'single quotes and case are preserved',
    "<FORM ACTION='/offers/5/order.php'>",
    $rewrite("<FORM ACTION='order.php'>")
);
$same(
    'rewriting twice changes nothing',
    '<form action="/offers/5/order.php">',
    $rewrite($rewrite('<form action="order.php">'))
);
$same(
    'a network endpoint is left alone',
    '<form action="https://api.network.example/send.php">',
    $rewrite('<form action="https://api.network.example/send.php">')
);
$same(
    'climbing out of the bundle is left alone',
    '<form action="../other/order.php">',
    $rewrite('<form action="../other/order.php">')
);
$same(
    'a non-PHP action is left alone',
    '<form action="submit"><input name="phone"></form>',
    $rewrite('<form action="submit"><input name="phone"></form>')
);
$same(
    'an unresolved macro is left alone',
    '<form action="{offer}order.php">',
    $rewrite('<form action="{offer}order.php">')
);
// Why this is a rewrite and not a <base> tag: a base would turn every in-page
// anchor into a navigation off the campaign URL, and on a lead lander those
// buttons are the entire interaction.
$same(
    'in-page anchors and assets are untouched',
    '<a href="#order">Order</a><img src="img/a.png"><form action="/offers/5/order.php">',
    $rewrite('<a href="#order">Order</a><img src="img/a.png"><form action="order.php">')
);
$same(
    'an inline pinned sender follows the action',
    '<script>currentRequestModify = "/offers/5/order.php";</script>',
    $rewrite('<script>currentRequestModify = "order.php";</script>')
);
$same(
    'a form without an action is left alone',
    '<form><input name="name"></form>',
    $rewrite('<form><input name="name"></form>')
);

// ---------------------------------------------------------------------- nginx
// Everything above is unreachable if the web server answers first, which is what
// actually produced the reported 404: snippets/fastcgi-php.conf ends in
// "try_files $fastcgi_script_name =404", so nginx's generic PHP handler replied
// to POST /order.php by itself and index.php never ran. Both writers of the
// vhost — the generator the panel and the SSL installer share, and install.sh's
// baseline — have to carry the bundle routes, and carry them *before* that
// generic handler, since nginx tries regex locations in written order.
require_once $repoRoot . '/core/nginx_config.php';
$configs = [
    'generated vhost' => orbitraBuildNginxConfig([]),
    // install.sh writes its baseline through an unquoted heredoc, so every nginx
    // variable and every regex "$" is backslash-escaped in the source. Unescape
    // before comparing, or the two writers can never be checked against one text.
    'install.sh baseline' => str_replace(
        '\\$',
        '$',
        (string) file_get_contents($repoRoot . '/install.sh')
    ),
];
$assert = static function (string $name, bool $ok) use (&$failures, &$passed): void {
    if ($ok) {
        $passed++;
        echo "ok  $name\n";
    } else {
        $failures[] = $name;
    }
};
foreach ($configs as $where => $config) {
    $handlerAt = strpos($config, 'location ~ ^/(?:order|thank_you|success|send|lucky|lemon)\\.php$');
    $bundleAt = strpos($config, 'location ~ ^/(?:offers|lander)/[^/]+/.*\\.php$');
    $genericAt = strpos($config, 'location ~ \\.php$ {');
    $assert("$where: the domain-root handler names are routed", $handlerAt !== false);
    $assert("$where: the /offers and /lander bundle routes are routed", $bundleAt !== false);
    $assert(
        "$where: both come before the generic PHP handler",
        $handlerAt !== false && $bundleAt !== false && $genericAt !== false
            && $handlerAt < $genericAt && $bundleAt < $genericAt
    );
    $assert(
        "$where: uploaded PHP under /landings/ is never executed",
        strpos($config, 'location ~ ^/landings/.*\\.php$') !== false
    );
    // Every handler index.php will execute must be a handler nginx forwards,
    // or the lead dies one hop later with nobody looking at index.php.
    $handlerLine = $handlerAt === false ? '' : substr($config, $handlerAt, 80);
    foreach (orbitraBundleHandlers() as $handler) {
        $assert(
            "$where: {$handler} is forwarded to the front controller",
            strpos($handlerLine, basename($handler, '.php') . '|') !== false
                || strpos($handlerLine, basename($handler, '.php') . ')') !== false
        );
    }
}

if ($failures) {
    echo "LANDER ORDER ROUTE TEST FAILED\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "LANDER ORDER ROUTE TEST PASSED ({$passed} checks)\n";
