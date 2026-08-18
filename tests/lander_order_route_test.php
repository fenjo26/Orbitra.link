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
    file_put_contents($landingDir . '/send.php', '<?php echo "should never run";');
    file_put_contents($siblingDir . '/order.php', '<?php echo "SIBLING_ORDER_RAN";');
    file_put_contents($offerDir . '/index.html', '<html><body>offer</body></html>');
    file_put_contents($offerDir . '/order.php', '<?php echo "OFFER_ORDER_OK:" . ($pdo instanceof PDO ? "pdo" : "nopdo");');

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
            orbitraServeLanderPath($pdo, $slug, 'send.php');
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
$check = static function (string $name, string $needle, string $output) use (&$failures): void {
    if (strpos($output, 'Fatal error') !== false || strpos($output, 'PHP Parse error') !== false) {
        $failures[] = "$name: child crashed: " . trim($output);
        return;
    }
    if (strpos($output, $needle) === false) {
        $failures[] = "$name: expected " . var_export($needle, true) . ' in: ' . trim($output);
    } else {
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
$check('non-bridge .php went through the asset server, not execution', 'ASSET lp:1/send.php', $out);
$check('PHP landings switch gates the lander bridge', 'Allow PHP landings', $run('lander-disabled'));
$check('traversal via ../sibling/order.php is contained', '404 Not Found', $run('lander-traversal'));
$check('unknown slug still 404s', '404 Not Found', $run('lander-unknown-slug'));
$check('offer order.php executes from the URL id', 'OFFER_ORDER_OK:pdo', $run('offer-order'));

// Root bridge: cookie path regression + Referer fallback.
$check('root bridge still resolves via orbitra_lp cookie', 'LANDER_ORDER_OK:pdo', $run('bridge-cookie-lander'));
$check('root bridge falls back to a /lander/<slug>/ Referer', 'LANDER_ORDER_OK:pdo', $run('bridge-referer-lander'));
$check('root bridge falls back to an /offers/<id>/ Referer', 'OFFER_ORDER_OK:pdo', $run('bridge-referer-offer'));
$check('root bridge ignores a foreign Referer (falls through)', 'BRIDGE_FELL_THROUGH', $run('bridge-referer-foreign'));

if ($failures) {
    echo "LANDER ORDER ROUTE TEST FAILED\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
$tests = 12;
echo "LANDER ORDER ROUTE TEST PASSED ({$tests} checks)\n";
