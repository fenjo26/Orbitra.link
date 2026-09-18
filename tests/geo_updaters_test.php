<?php
// tests/geo_updaters_test.php
//
// The Sypex updater (core/geo_databases.php) against a fixture zip — no
// network. This is the code the installer, the monthly cron and the panel's
// post-update self-heal all run: it must install the .dat, ship the SxGeo.php
// parser when missing, never overwrite an existing parser, and fail softly
// (a result array, not a crash) when sypexgeo.net is unreachable.
//
// Run: php tests/geo_updaters_test.php

require_once __DIR__ . '/../core/geo_databases.php';

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

// Fixture zip shaped like the real one: the .dat nested in a directory, the
// SxGeo.php reader at the root. The dat must be > 1024 bytes (the updater's
// sanity floor).
$fixtureZip = sys_get_temp_dir() . '/orbitra_fixture_zip_' . bin2hex(random_bytes(4)) . '.zip';
$zip = new ZipArchive;
if ($zip->open($fixtureZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "cannot create fixture zip\n");
    exit(1);
}
$zip->addFromString('SxGeoCity/SxGeoCity.dat', 'SXGEO-FIXTURE-' . str_repeat('x', 4096));
$zip->addFromString('SxGeo.php', "<?php // fixture parser from the archive\n");
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
$assert('parser shipped from the archive', strpos((string) file_get_contents($root . '/core/SxGeo.php'), 'fixture parser') !== false, true);
$assert('installed detector flips', orbitraGeoDatabasesInstalled($root), true);
$assert('no temp leftovers in the geo dir', glob($root . '/var/geoip/SxGeoCity/*.tmp-*'), []);
$assert('no zip leftovers', is_file($root . '/var/geoip/SxGeoCity/SxGeoCity_utf8.zip'), false);
$assert('extraction temp dir cleaned', glob(sys_get_temp_dir() . '/orbitra_sypex_*'), []);

echo "existing parser is never overwritten\n";
file_put_contents($root . '/core/SxGeo.php', "<?php // repository's own parser — must win\n");
$res2 = orbitraUpdateSypex($root, $fetchFixture);
$assert('second run ok (idempotent)', $res2['ok'], true);
$assert('own parser kept', strpos((string) file_get_contents($root . '/core/SxGeo.php'), "repository's own parser") !== false, true);

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
