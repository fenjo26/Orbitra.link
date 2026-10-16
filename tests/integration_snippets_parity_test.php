<?php
// Run: php tests/integration_snippets_parity_test.php
//
// The panel generates integration snippets in the browser
// (frontend/src/utils/integrationSnippets.js); api.php's integration_code
// action and the MCP tools generate them in PHP
// (core/integration_snippets.php). Two generators WILL drift — that is how
// the back-button trap once shipped a version that never fired. This test
// runs both over the same battery of methods and options and fails on the
// first byte that differs.
//
// Requires node (the same dependency the MCP manifest build already has).
// Without node it prints SKIP and exits 0 — run it on a machine that has node.

require_once __DIR__ . '/../core/integration_snippets.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

/** camelCase (the panel's option names) → snake_case (the PHP twin's). */
function camelToSnakeOpts(array $opts): array
{
    $out = [];
    foreach ($opts as $k => $v) {
        $snake = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', (string) $k));
        if (!array_key_exists($snake, $out)) {
            $out[$snake] = $v;
        }
    }
    return $out;
}

/** Neutralize the random DOM ids both sides generate (crypto UUID / base36 / hex). */
function normalizeIds(string $code): string
{
    $code = preg_replace('/id="[a-z0-9-]{8,}"/', 'id="__CID__"', $code);
    $code = preg_replace('/getElementById\(\'[a-z0-9-]{8,}\'\)/', "getElementById('__CID__')", $code);
    $code = preg_replace('/_cid=[a-z0-9-]{8,}/', '_cid=__CID__', $code);
    return (string) $code;
}

function firstDiff(string $a, string $b): string
{
    $n = min(strlen($a), strlen($b));
    for ($i = 0; $i < $n; $i++) {
        if ($a[$i] !== $b[$i]) {
            $s = max(0, $i - 60);
            return "at byte $i\n    JS:  ..." . substr($a, $s, 120) . "...\n    PHP: ..." . substr($b, $s, 120) . "...";
        }
    }
    return strlen($a) !== strlen($b)
        ? 'same prefix, lengths differ (' . strlen($a) . ' vs ' . strlen($b) . ")\n    JS tail:  ..." . substr($a, $n, 120) . "\n    PHP tail: ..." . substr($b, $n, 120)
        : 'identical';
}

// ——— the battery (option names in the panel's camelCase) ———
$cases = [
    ['name' => 'kclient_js', 'method' => 'kclient_js', 'opts' => []],
    ['name' => 'kclient_js_base64', 'method' => 'kclient_js', 'opts' => ['base64' => true]],
    ['name' => 'kclient_php', 'method' => 'kclient_php', 'opts' => []],
    ['name' => 'kclient_php_no_params', 'method' => 'kclient_php', 'opts' => ['sendParams' => false]],
    ['name' => 'kclient_php_secondary', 'fn' => 'kclientPhpSecondary', 'opts' => []],
    ['name' => 'kclient_php_offer_link', 'fn' => 'kclientPhpOfferLink', 'opts' => []],
    ['name' => 'kclient_php_get_offer', 'fn' => 'kclientPhpGetOffer', 'opts' => []],
    ['name' => 'tracking_script', 'method' => 'tracking_script', 'opts' => []],
    ['name' => 'banner_script_default', 'method' => 'banner_script', 'opts' => []],
    ['name' => 'banner_script_728x90', 'method' => 'banner_script', 'opts' => ['width' => 728, 'height' => 90]],
    ['name' => 'banner_iframe', 'method' => 'banner_iframe', 'opts' => ['width' => 468, 'height' => 60]],
    ['name' => 'campaign_url', 'method' => 'campaign_url', 'opts' => []],
    ['name' => 'link', 'method' => 'link', 'opts' => []],
    ['name' => 'iframe', 'method' => 'iframe', 'opts' => []],
    ['name' => 'script', 'method' => 'script', 'opts' => []],
    ['name' => 'pixel_click', 'method' => 'pixel', 'opts' => []],
    ['name' => 'pixel_conversion_default', 'method' => 'pixel', 'opts' => ['pixelType' => 'conversion']],
    ['name' => 'pixel_conversion_full', 'method' => 'pixel', 'opts' => ['pixelType' => 'conversion', 'convStatus' => 'sale', 'payout' => 12.5, 'subidParam' => 'my_sub&id"1']],
    ['name' => 'countdown_default', 'method' => 'countdown', 'opts' => []],
    ['name' => 'countdown_full', 'method' => 'countdown', 'opts' => ['hours' => 2, 'minutes' => 5, 'theme' => 'fire', 'headerText' => 'HURRY <UP> & "now"', 'buttonText' => 'BUY — сейчас', 'offerUrl' => 'https://x.io/lp?a=1&b=2']],
    ['name' => 'countdown_expire_redirect', 'method' => 'countdown', 'opts' => ['expireAction' => 'redirect', 'expireUrl' => 'https://r.io/expired']],
    ['name' => 'back_button_default', 'method' => 'back_button', 'opts' => []],
    ['name' => 'back_button_delay', 'method' => 'back_button', 'opts' => ['trapUrl' => 'https://offer.net/lp', 'delay' => 7]],
    ['name' => 'back_button_nolog', 'method' => 'back_button', 'opts' => ['trapUrl' => 'https://offer.net/lp?x=1', 'logClick' => false]],
    ['name' => 'exit_intent_default', 'method' => 'exit_intent', 'opts' => []],
    ['name' => 'exit_intent_full', 'method' => 'exit_intent', 'opts' => ['heading' => 'Стой! <b>Спец</b> предложение', 'text' => 'Don\'t miss "it"', 'popupButtonText' => 'ЖМИ &ldquo;СЮДА&rdquo;', 'buttonColor' => '#3b82f6', 'showDelay' => 10, 'closeOnBackdrop' => false, 'offerUrl' => 'https://o.io/buy?x=1']],
    ['name' => 'wordpress', 'method' => 'wordpress', 'opts' => []],
];

$ctx = [
    'tracker_url' => 'https://track.example.com',
    'campaign' => [
        'id' => 42,
        'alias' => 'mycamp',
        'token' => 'tok123abc',
        'url' => 'https://track.example.com/mycamp?utm_source=fb',
    ],
];

$nodeBin = trim((string) getenv('NODE_BIN') ?: '');
if ($nodeBin === '') {
    foreach (['node', '/usr/local/bin/node', '/opt/homebrew/bin/node'] as $candidate) {
        $probe = $candidate === 'node' ? trim((string) @shell_exec('command -v node 2>/dev/null')) : $candidate;
        if ($probe !== '' && @is_executable($probe)) {
            $nodeBin = $probe;
            break;
        }
    }
}
if ($nodeBin === '') {
    echo "SKIP: node not found — the parity between core/integration_snippets.php and\n";
    echo "      frontend/src/utils/integrationSnippets.js was NOT verified on this machine.\n";
    exit(0);
}

$tmpDir = sys_get_temp_dir() . '/orbitra_parity_' . getmypid();
@mkdir($tmpDir);
$casesFile = "$tmpDir/cases.json";
file_put_contents($casesFile, json_encode($cases, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
// ESM import of a .js without "type":"module" fails, so the module travels as .mjs.
copy(dirname(__DIR__) . '/frontend/src/utils/integrationSnippets.js', "$tmpDir/integrationSnippets.mjs");

$cmd = [$nodeBin, __DIR__ . '/fixtures/integration_snippets_runner.mjs', $casesFile, "$tmpDir/integrationSnippets.mjs"];
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
if (!is_resource($proc)) {
    echo "SKIP: could not launch node\n";
    exit(0);
}
fwrite($pipes[0], json_encode($ctx));
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($proc);

$jsResults = $exit === 0 ? json_decode((string) $stdout, true) : null;
check(is_array($jsResults), 'node runner produced JSON' . ($exit !== 0 ? ' (exit ' . $exit . '): ' . substr(trim((string) $stderr), 0, 300) : ''));

if (is_array($jsResults)) {
    // tagBackButtonUrl is its own exported contract — check it directly.
    foreach ([
        '' => '',
        'https://a.b/c' => 'https://a.b/c?sub1=back_button',
        'https://a.b/c?d=1' => 'https://a.b/c?d=1&sub1=back_button',
        'https://a.b/c?sub1=x' => 'https://a.b/c?sub1=x',
        'https://a.b/c#frag' => 'https://a.b/c?sub1=back_button#frag',
    ] as $in => $expected) {
        check(orbitraTagBackButtonUrl($in) === $expected, "tagBackButtonUrl('$in')");
    }

    $checked = 0;
    foreach ($cases as $case) {
        $name = $case['name'];
        $js = normalizeIds((string) ($jsResults[$name] ?? ''));
        if ($case['fn'] ?? '') {
            $fn = 'orbitra' . $case['fn'];
            $php = normalizeIds((string) $fn($ctx));
        } else {
            $php = normalizeIds((string) orbitraBuildSnippet($case['method'], $ctx, camelToSnakeOpts($case['opts'])));
        }
        check($js === $php, "parity: $name" . ($js !== $php ? "\n  " . firstDiff($js, $php) : ''));
        $checked++;
    }
    check($checked === count($cases), "all {$checked} battery cases compared");
}

cleanup:
foreach (glob("$tmpDir/*") ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmpDir);
exit($failures ? 1 : 0);
