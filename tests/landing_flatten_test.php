<?php
// tests/landing_flatten_test.php
//
// First-load landing fix: nested-folder ZIP flattening at upload time and the
// serve-time directory resolution fallback, exercised against a real temp
// filesystem.
//
// Run: php tests/landing_flatten_test.php

require_once __DIR__ . '/../core/landing_path.php';

$assert = function (string $label, $got, $expected) {
    if ($got !== $expected) {
        echo "FAIL $label: got " . var_export($got, true) . ", expected " . var_export($expected, true) . "\n";
        exit(1);
    }
    echo "ok  $label = " . var_export($got, true) . "\n";
};

$mkTree = function (array $files, array $dirs = []): string {
    $root = sys_get_temp_dir() . '/orbitra_flatten_' . uniqid();
    @mkdir($root, 0775, true);
    foreach ($dirs as $d) {
        @mkdir($root . '/' . $d, 0775, true);
    }
    foreach ($files as $path => $content) {
        file_put_contents($root . '/' . $path, $content);
    }
    return $root;
};
$rmTree = function (string $dir): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $e) {
        $e->isDir() ? @rmdir($e->getPathname()) : @unlink($e->getPathname());
    }
    @rmdir($dir);
};

// --- Flatten: nested folder + __MACOSX junk -----------------------------------
// A Finder-style archive: __MACOSX and one real folder holding everything.
$d = $mkTree([
    'site/index.html' => '<h1>hi</h1>',
    'site/css/style.css' => 'body{}',
    '__MACOSX/site/._index.html' => 'junk',
], ['site', 'site/css', '__MACOSX', '__MACOSX/site']);
orbitraFlattenSingleNestedDir($d);
$assert('flatten: index.html lifted to root', is_file($d . '/index.html'), true);
$assert('flatten: subdirectory lifted too', is_dir($d . '/css'), true);
$assert('flatten: css file present', is_file($d . '/css/style.css'), true);
$assert('flatten: nested folder gone', is_dir($d . '/site'), false);
$assert('flatten: __MACOSX removed', is_dir($d . '/__MACOSX'), false);
$rmTree($d);

// --- Flatten must NOT touch flat archives -------------------------------------
$d = $mkTree(['index.html' => 'x', 'a.txt' => 'y']);
orbitraFlattenSingleNestedDir($d);
$assert('flat archive: index stays at root', is_file($d . '/index.html'), true);
$assert('flat archive: second file stays', is_file($d . '/a.txt'), true);
$rmTree($d);

// --- Flatten must NOT touch "folder + root file" archives ---------------------
// Root has a file of its own — not a single-nested layout, left alone.
$d = $mkTree(['README.txt' => 'x', 'site/index.html' => 'y'], ['site']);
orbitraFlattenSingleNestedDir($d);
$assert('mixed archive: folder untouched', is_file($d . '/site/index.html'), true);
$assert('mixed archive: root file untouched', is_file($d . '/README.txt'), true);
$rmTree($d);

// --- Serve-time resolution: orbitraLandingContentDir --------------------------
// Shared with index.php via core/landing_path.php — one implementation.
$d = $mkTree(['sanjeevani/index.html' => '<h1>nested</h1>'], ['sanjeevani']);
$resolved = orbitraLandingContentDir($d);
$assert('resolve: nested dir found', is_file($resolved . '/index.html'), true);
$assert('resolve: points inside root', strpos($resolved, $d) === 0, true);
$rmTree($d);

$d = $mkTree(['index.php' => '<?php //ok']);
$resolved = orbitraLandingContentDir($d);
$assert('resolve: flat php landing stays at root', $resolved, $d);
$rmTree($d);

$d = $mkTree(['__MACOSX/._x' => 'j', 'site/index.html' => 'x'], ['site', '__MACOSX']);
$resolved = orbitraLandingContentDir($d);
$assert('resolve: skips __MACOSX', basename($resolved), 'site');
$rmTree($d);

$d = $mkTree(['readme.txt' => 'no index anywhere'], ['subdir']);
$resolved = orbitraLandingContentDir($d);
$assert('resolve: no index -> dir unchanged', $resolved, $d);
$rmTree($d);

echo "landing_flatten_test: all assertions passed\n";
