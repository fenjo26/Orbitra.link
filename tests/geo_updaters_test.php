<?php
// tests/geo_updaters_test.php
//
// The Sypex updater (core/geo_databases.php) against a fixture zip — no
// network. This is the code the installer, the monthly cron and the panel's
// post-update self-heal all run: it must install the .dat, install the
// SxGeo.php reader when it is missing or a 0-byte stub (the old empty-file
// bug that silently killed every Sypex lookup), leave a working reader
// alone, and fail softly (a result array, not a crash) when sypexgeo.net is
// unreachable.
//
// The fixture "reader" is a stub class whose getCountry() answers 'US', so
// the updater's post-install sanity probe passes against the fixture .dat.
// The class is declared conditionally: the updater require_once's the parser
// more than once across scenarios in this single process, and a second
// unconditional declaration would be a fatal.
//
// Run: php tests/geo_updaters_test.php

require_once __DIR__ . '/../core/geo_databases.php';

$failures = 0;
$assert = function (string $label, $got, $expected) use (&$failures) {
    $ok = $got === $expected;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label;
    if (!$ok) {
        echo ' — got ' . var_export($got, true), ', expected ' . var_export($expected, true);
        $failures++;
    }
    echo "\n";
};

$stubClass = "if (!class_exists('SxGeo')) {\n"
    . "class SxGeo {\n"
    . "    public function __construct(\$file = '') {}\n"
    . "    public function getCountry(\$ip) { return 'US'; }\n"
    . "    public function close() {}\n"
    . "}\n"
    . "}\n";
$archiveParser = "<?php // fixture parser from the archive\n" . $stubClass;

// Fixture zip shaped like the vendor bundle: the .dat nested in a directory,
// the SxGeo.php reader at the root. The dat must be > 1024 bytes (the
// updater's sanity floor).
$fixtureZip = sys_get_temp_dir() . '/orbitra_fixture_zip_' . bin2hex(random_bytes(4)) . '.zip';
$zip = new ZipArchive;
if ($zip->open($fixtureZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "cannot create fixture zip\n");
    exit(1);
}
$zip->addFromString('SxGeoCity/SxGeoCity.dat', 'SXGEO-FIXTURE-' . str_repeat('x', 4096));
$zip->addFromString('SxGeo.php', $archiveParser);
$zip->close();
$fixtureData = file_get_contents($fixtureZip);
$fetchFixture = static fn (string $url): ?string => $fixtureData;

$mkRoot = static function (): string {
    $root = sys_get_temp_dir() . '/orbitra_geo_root_' . bin2hex(random_bytes(5));
    mkdir($root . '/core', 0755, true);
    return $root;
};

echo "installed detector\n";
$root = $mkRoot();
$assert('empty root → nothing installed', orbitraGeoDatabasesInstalled($root), false);

echo "successful install (fixture zip)\n";
$res = orbitraUpdateSypex($root, $fetchFixture);
$assert('ok', $res['ok'], true);
$datPath = $root . '/var/geoip/SxGeoCity/SxGeoCity.dat';
$assert('dat installed', is_file($datPath), true);
$assert('dat is the fixture payload', strpos((string) file_get_contents($datPath), 'SXGEO-FIXTURE-'), 0);
$parserPath = $root . '/core/SxGeo.php';
$assert('reader shipped from the archive', strpos((string) file_get_contents($parserPath), 'fixture parser') !== false, true);
$assert('installed detector flips', orbitraGeoDatabasesInstalled($root), true);
$assert('no temp leftovers in the geo dir', glob($root . '/var/geoip/SxGeoCity/*.tmp-*'), []);
$assert('no zip leftovers', is_file($root . '/var/geoip/SxGeoCity/SxGeoCity_utf8.zip'), false);
$assert('extraction temp dir cleaned', glob(sys_get_temp_dir() . '/orbitra_sypex_*'), []);

echo "a 0-byte reader stub gets replaced\n";
file_put_contents($parserPath, '');
$res0 = orbitraUpdateSypex($root, $fetchFixture);
$assert('ok after stub replacement', $res0['ok'], true);
$assert('0-byte stub replaced from the archive', strpos((string) file_get_contents($parserPath), 'fixture parser') !== false, true);

echo "existing parser is never overwritten\n";
$ownParser = "<?php // repository's own parser — must win\n" . $stubClass;
file_put_contents($parserPath, $ownParser);
$res2 = orbitraUpdateSypex($root, $fetchFixture);
$assert('second run ok (idempotent)', $res2['ok'], true);
$assert('own parser kept', strpos((string) file_get_contents($parserPath), "repository's own parser") !== false, true);

echo "soft failures\n";
$resFail = orbitraUpdateSypex($root, static fn (string $url): ?string => null);
$assert('unreachable → ok=false (no crash)', $resFail['ok'], false);
$assert('unreachable → human message', strpos($resFail['message'], 'sypexgeo.net') !== false, true);
$assert('dat survives a failed update', is_file($datPath), true);

$badZip = sys_get_temp_dir() . '/orbitra_sypex_bad_' . bin2hex(random_bytes(4)) . '.zip';
$zip = new ZipArchive;
$zip->open($badZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('readme.txt', 'no database here');
$zip->close();
$badData = file_get_contents($badZip);
$resBad = orbitraUpdateSypex($root, static fn (string $url): ?string => $badData);
$assert('zip without .dat → ok=false', $resBad['ok'], false);
$assert('zip without .dat → says so', strpos($resBad['message'], 'SxGeoCity.dat') !== false, true);

@unlink($fixtureZip);
@unlink($badZip);

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
