<?php
// tests/php_landing_sanitize_test.php
//
// Tiered PHP landing scan: FORBIDDEN_FUNCTIONS (shell/eval/symlink) still
// reject an archive, while the runtime-limit trio (ini_set / ini_alter /
// set_time_limit) that opens nearly every third-party template is stripped
// from the extracted code and reported instead. Plus the stage-then-swap
// extraction: a replacement archive replaces, and a rejected archive leaves
// the previous files standing.
//
// Run: php tests/php_landing_sanitize_test.php

require_once __DIR__ . '/../core/landing_path.php';
require_once __DIR__ . '/../core/PhpLanding.php';

$assert = function (string $label, $got, $expected) {
    if ($got !== $expected) {
        echo "FAIL $label: got " . var_export($got, true) . ", expected " . var_export($expected, true) . "\n";
        exit(1);
    }
    echo "ok  $label\n";
};
$rmTree = function (string $dir): void {
    orbitraRmdirTree($dir);
};

// --- scan(): the soft trio no longer rejects, the hard tier still does -------
$assert('scan: ini_set alone passes',
    PhpLanding::scan("<?php ini_set('display_errors', '0'); set_time_limit(60);"), []);
$assert('scan: exec still flagged',
    PhpLanding::scan('<?php exec("ls");'), ['exec']);
$assert('scan: fully qualified \exec flagged too',
    PhpLanding::scan('<?php \\exec("ls");'), ['exec']);
$assert('scan: own-namespace call is not the global one',
    PhpLanding::scan('<?php Foo\\exec("ls");'), []);
$assert('scan: eval still flagged',
    PhpLanding::scan('<?php eval($x);'), ['eval']);
$assert('scan: ini_set in a string is not a call',
    PhpLanding::scan("<?php echo 'ini_set(1,2)';"), []);

// --- sanitize(): the exact call goes, everything around it stays -------------
$r = PhpLanding::sanitize("<?php\nini_set('display_errors', '0');\necho 'go';\n");
$assert('sanitize: names reported', $r['names'], ['ini_set']);
$assert('sanitize: statement becomes null', $r['source'], "<?php\nnull;\necho 'go';\n");

$r = PhpLanding::sanitize("<?php \$x = \\set_time_limit(30);");
$assert('sanitize: fully qualified span has no stray backslash', $r['source'], '<?php $x = null;');

$src = "<?php\nif (!ini_set('a(b)', 'c)')) { echo 1; }\nfunction ini_set2() {}\n"
    . "// ini_set('commented', 1)\n\$o->ini_set(1); Foo::ini_set(2);\n";
$r = PhpLanding::sanitize($src);
$assert('sanitize: only the real call touched',
    $r['source'],
    "<?php\nif (!null) { echo 1; }\nfunction ini_set2() {}\n"
    . "// ini_set('commented', 1)\n\$o->ini_set(1); Foo::ini_set(2);\n");

$r = PhpLanding::sanitize("<?php set_time_limit(\n  60\n); ini_alter('x', 1); ini_set('y', 2);");
$assert('sanitize: every soft name collected', $r['names'], ['set_time_limit', 'ini_alter', 'ini_set']);
$assert('sanitize: no call left behind', strpos($r['source'], 'set_time_limit') === false
    && strpos($r['source'], 'ini_alter(') === false && strpos($r['source'], 'ini_set(') === false, true);

$assert('sanitize: no php tags means untouched', PhpLanding::sanitize('<h1>plain html</h1>'), null);
$assert('sanitize: clean php means untouched', PhpLanding::sanitize('<?php echo 1;'), null);

// Sanitized output must still parse and keep semantics of the surrounding code.
$after = PhpLanding::sanitize("<?php\n\$keep = 1;\nini_set('display_errors', '0');\n\$also = \$keep + 1;\n")['source'];
$assert('sanitize: result is valid php', token_get_all($after, TOKEN_PARSE) !== [], true);

// --- sanitizeDirectory(): rewrites files, reports relative paths -------------
$d = sys_get_temp_dir() . '/orbitra_sanitize_' . uniqid();
mkdir($d . '/sub', 0775, true);
file_put_contents($d . '/order.php', "<?php\nini_set('display_errors', '0');\nset_time_limit(60);\necho 'ok';\n");
file_put_contents($d . '/sub/helper.php', "<?php ini_alter('a', 1);");
file_put_contents($d . '/index.html', "<p>ini_set(1,2) stays — not php</p>");
$report = PhpLanding::sanitizeDirectory($d);
$assert('sanitizeDirectory: order.php reported',
    $report['order.php'], ['ini_set', 'set_time_limit']);
$assert('sanitizeDirectory: nested file reported',
    $report['sub/helper.php'], ['ini_alter']);
$assert('sanitizeDirectory: html not reported', isset($report['index.html']), false);
$assert('sanitizeDirectory: file rewritten on disk',
    strpos((string) file_get_contents($d . '/order.php'), 'ini_set'), false);
$rmTree($d);

// --- orbitraExtractArchiveSwap(): replace semantics + failure keeps old tree --
$pdo = new PDO('sqlite::memory:');
$mkZip = function (array $files): string {
    $path = sys_get_temp_dir() . '/orbitra_sanitize_' . uniqid() . '.zip';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    return $path;
};

// First upload: a realistic template with the boilerplate pair.
$dest = sys_get_temp_dir() . '/orbitra_sanitize_dest_' . uniqid();
$zipPath = $mkZip([
    'tpl/index.html' => '<a href="order.php">order</a>',
    'tpl/order.php' => "<?php\nini_set('display_errors', '0');\nset_time_limit(60);\n\$name = \$_POST['name'] ?? '';\n",
]);
$zip = new ZipArchive();
$zip->open($zipPath);
$res = orbitraExtractArchiveSwap($zip, $dest, $pdo);
$assert('swap: template with ini_set/set_time_limit accepted', $res['ok'], true);
$assert('swap: flatten lifted index.html', is_file($dest . '/index.html'), true);
$assert('swap: sanitized report present',
    $res['sanitized']['order.php'], ['ini_set', 'set_time_limit']);
$assert('swap: stripped from the installed file',
    strpos((string) file_get_contents($dest . '/order.php'), 'set_time_limit'), false);

// Replacing with an archive that dropped a file: the old file must be gone.
$zipPath2 = $mkZip(['index.html' => '<h1>v2</h1>', 'new.txt' => 'x']);
$zip = new ZipArchive();
$zip->open($zipPath2);
$res = orbitraExtractArchiveSwap($zip, $dest, $pdo);
$assert('swap: replacement ok', $res['ok'], true);
$assert('swap: stale order.php removed', is_file($dest . '/order.php'), false);
$assert('swap: new file present', is_file($dest . '/new.txt'), true);
$assert('swap: replaced content served', (string) file_get_contents($dest . '/index.html'), '<h1>v2</h1>');
$assert('swap: stage dir cleaned up', count((array) glob($dest . '.stage-*')), 0);

// A hard-tier archive is rejected and the previous tree survives untouched.
$zipPath3 = $mkZip(['index.html' => 'h', 'evil.php' => '<?php exec("rm -rf /");']);
$zip = new ZipArchive();
$zip->open($zipPath3);
$res = orbitraExtractArchiveSwap($zip, $dest, $pdo);
$assert('swap: exec archive rejected', $res['ok'], false);
$assert('swap: hard-tier error names the file',
    strpos(implode(' | ', $res['error']['detail']['files']), 'evil.php'), 0);
$assert('swap: previous files still standing', (string) file_get_contents($dest . '/index.html'), '<h1>v2</h1>');
$assert('swap: rejected stage cleaned up', count((array) glob($dest . '.stage-*')), 0);
$rmTree($dest);
@unlink($zipPath);
@unlink($zipPath2);
@unlink($zipPath3);

echo "php_landing_sanitize_test: all assertions passed\n";
