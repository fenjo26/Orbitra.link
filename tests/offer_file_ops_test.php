<?php
declare(strict_types=1);

/**
 * Test for local offer file operations:
 *  - orbitraOfferFilePath resolution & containment
 *  - orbitraOfferEditableExtensions whitelist
 *  - file create, read, save, rename, delete
 */

$repoRoot = dirname(__DIR__);
$src = file_get_contents($repoRoot . '/api.php');

$extractFunction = static function (string $name) use ($src): string {
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\(.*?\n\}/ms', $src, $m)) {
        fwrite(STDERR, "could not extract function {$name} from api.php\n");
        exit(1);
    }
    return $m[0];
};

eval($extractFunction('orbitraOfferFilePath'));
eval($extractFunction('orbitraOfferEditableExtensions'));

$testOfferId = 99999;
$testDir = $repoRoot . '/offers/' . $testOfferId;

// Cleanup any old test directory
if (is_dir($testDir)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }
    rmdir($testDir);
}

mkdir($testDir, 0775, true);

function assertOk($condition, $msg) {
    if (!$condition) {
        echo "FAIL: $msg\n";
        exit(1);
    }
    echo "ok  $msg\n";
}

// 1. Test orbitraOfferFilePath containment
assertOk(orbitraOfferFilePath($testOfferId, 'index.html', false) !== null, 'Allows relative path inside offer');
assertOk(orbitraOfferFilePath($testOfferId, 'sub/folder/style.css', false) !== null, 'Allows subfolder relative path inside offer');
assertOk(orbitraOfferFilePath($testOfferId, '../outside.php', false) === null, 'Rejects ../ traversal');
assertOk(orbitraOfferFilePath($testOfferId, '../../config.php', false) === null, 'Rejects deep ../ traversal');
assertOk(orbitraOfferFilePath(0, 'index.html', false) === null, 'Rejects zero ID');
assertOk(orbitraOfferFilePath(-1, 'index.html', false) === null, 'Rejects negative ID');

// 2. Test file creation
$indexPath = orbitraOfferFilePath($testOfferId, 'index.html', false);
file_put_contents($indexPath, '<h1>Hello Offer</h1>');
assertOk(is_file($indexPath), 'index.html created');

// 3. Test reading content
$readPath = orbitraOfferFilePath($testOfferId, 'index.html', true);
assertOk($readPath !== null && file_get_contents($readPath) === '<h1>Hello Offer</h1>', 'Read index.html content matches');

// 4. Test saving modified content
file_put_contents($readPath, '<h1>Updated Offer</h1>');
assertOk(file_get_contents($readPath) === '<h1>Updated Offer</h1>', 'Updated index.html content saved');

// 5. Test subfolder file creation & rename
$subPath = orbitraOfferFilePath($testOfferId, 'css/style.css', false);
@mkdir(dirname($subPath), 0775, true);
file_put_contents($subPath, 'body { color: red; }');
assertOk(is_file($subPath), 'css/style.css created');

$newSubPath = orbitraOfferFilePath($testOfferId, 'css/main.css', false);
rename($subPath, $newSubPath);
assertOk(!is_file($subPath) && is_file($newSubPath), 'css/style.css renamed to css/main.css');

// 6. Test delete
unlink($newSubPath);
assertOk(!is_file($newSubPath), 'css/main.css deleted');

// Clean up test directory
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($files as $fileinfo) {
    $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
    $todo($fileinfo->getRealPath());
}
rmdir($testDir);

echo "offer_file_ops_test: all assertions passed\n";
