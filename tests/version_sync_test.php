<?php
// version.php (ORBITRA_VERSION) and version.json must name the same release.
// check_update / ping / the panel read the PHP constant, the updater's
// "latest" comes from version.json — a release that bumps only one of them
// leaves every server on "update available" forever, even after a good pull
// (1.8.8 shipped with version.php still at 1.8.7).
require __DIR__ . '/../version.php';
$json = json_decode((string) file_get_contents(__DIR__ . '/../version.json'), true);
$jsonVersion = is_array($json) ? (string) ($json['version'] ?? '') : '';
if ($jsonVersion === '' || $jsonVersion !== ORBITRA_VERSION) {
    fwrite(STDERR, "FAIL: version.php " . ORBITRA_VERSION . " != version.json {$jsonVersion}\n");
    exit(1);
}
echo "OK: version.php and version.json both at " . ORBITRA_VERSION . "\n";
