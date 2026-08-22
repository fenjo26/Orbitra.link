<?php

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
require_once __DIR__ . '/../core/geo_databases.php';

$failures = [];
$tempRoot = sys_get_temp_dir() . '/orbitra-geo-test-' . bin2hex(random_bytes(5));
mkdir($tempRoot . '/geo', 0755, true);

$writeHeader = static function (string $path, int $package, int $productCode): void {
    $header = str_repeat("\0", 64);
    $header[0] = chr($package);
    $header[29] = chr($productCode);
    file_put_contents($path, $header);
};

try {
    $geoFile = $tempRoot . '/geo.bin';
    $writeHeader($geoFile, 11, 1);
    $geo = orbitraGeoClassifyFile($geoFile, 'IP2LOCATION-LITE-DB11.BIN');
    if (($geo['kind'] ?? '') !== 'ip2location_geo') {
        $failures[] = 'DB11 was not classified as geolocation';
    }

    $asn = orbitraGeoClassifyFile($geoFile, 'IP2LOCATION-LITE-ASN.BIN');
    if (($asn['kind'] ?? '') !== 'ip2location_asn') {
        $failures[] = 'ASN BIN was not classified separately';
    }

    $proxyFile = $tempRoot . '/proxy.bin';
    $writeHeader($proxyFile, 12, 2);
    $proxy = orbitraGeoClassifyFile($proxyFile, 'renamed-file.BIN');
    if (($proxy['kind'] ?? '') !== 'ip2proxy' || ($proxy['package'] ?? 0) !== 12) {
        $failures[] = 'PX12 header was not classified as IP2Proxy';
    }

    if (orbitraGeoCoordinate('3.68e24', -90, 90) !== null) {
        $failures[] = 'Invalid latitude was accepted';
    }
    if (orbitraGeoCoordinate('-80.1289', -180, 180) !== -80.1289) {
        $failures[] = 'Valid longitude was rejected';
    }

    $misplaced = $tempRoot . '/geo/IP2LOCATION-LITE-DB11.BIN';
    $writeHeader($misplaced, 12, 2);
    if (!orbitraGeoMigrateMisplacedProxy($tempRoot)) {
        $failures[] = 'Misplaced PX12 was not migrated';
    }
    if (is_file($misplaced) || !is_file($tempRoot . '/geo/IP2PROXY-LITE-PX12.BIN')) {
        $failures[] = 'PX12 migration used incorrect paths';
    }

    $sample = __DIR__ . '/../vendor/ip2location/ip2proxy-php/data/PX12.SAMPLE.BIN';
    if (is_file($sample)) {
        copy($sample, $tempRoot . '/geo/IP2PROXY-LITE-PX12.BIN');
        $record = orbitraLookupIp2Proxy('23.83.130.186', $tempRoot);
        if ((int) ($record['isProxy'] ?? 0) !== 1 || ($record['proxyType'] ?? '') !== 'VPN') {
            $failures[] = 'Official IP2Proxy sample was not resolved as VPN';
        }
    }

    // Test orbitraGeoTargetingReady() - Phase 0 cloak warnings
    // Test with empty directory - all should be false
    $emptyRoot = sys_get_temp_dir() . '/orbitra-geo-empty-' . bin2hex(random_bytes(5));
    mkdir($emptyRoot . '/geo', 0755, true);
    $emptyReady = orbitraGeoTargetingReady($emptyRoot);
    if ($emptyReady['country'] !== false) {
        $failures[] = 'Empty directory should report country as false';
    }
    if ($emptyReady['asn'] !== false) {
        $failures[] = 'Empty directory should report asn as false';
    }
    if ($emptyReady['proxy'] !== false) {
        $failures[] = 'Empty directory should report proxy as false';
    }
    if (count($emptyReady['files']) !== 0) {
        $failures[] = 'Empty directory should report zero files';
    }
    foreach (glob($emptyRoot . '/geo/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($emptyRoot . '/geo');
    @rmdir($emptyRoot);

    // Readiness deep-validates through the official library, so the
    // with-file case needs a real database — a bare header stub is exactly
    // the truncated file the check must reject. Memoisation is per-root, so
    // every scenario uses its own fresh root.
    $sampleGeo = __DIR__ . '/../vendor/ip2location/ip2location-php/data/IP2LOCATION-LITE-DB1.BIN';
    if (is_file($sampleGeo)) {
        $countryRoot = sys_get_temp_dir() . '/orbitra-geo-country-' . bin2hex(random_bytes(5));
        mkdir($countryRoot . '/geo', 0755, true);
        copy($sampleGeo, $countryRoot . '/geo/IP2LOCATION-LITE-DB11.BIN');
        $withCountry = orbitraGeoTargetingReady($countryRoot);
        if ($withCountry['country'] !== true) {
            $failures[] = 'With IP2Location DB11, country should be true';
        }
        if ($withCountry['asn'] !== false) {
            $failures[] = 'With IP2Location DB11 only, asn should be false';
        }

        // Memoisation: a second call on the same root returns the first
        // result even after the file disappeared underneath.
        unlink($countryRoot . '/geo/IP2LOCATION-LITE-DB11.BIN');
        if (orbitraGeoTargetingReady($countryRoot) !== $withCountry) {
            $failures[] = 'orbitraGeoTargetingReady() should return memoised result';
        }
        foreach (glob($countryRoot . '/geo/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($countryRoot . '/geo');
        @rmdir($countryRoot);

        // Truncated file (fresh root): a 64-byte header stub must not count.
        $truncRoot = sys_get_temp_dir() . '/orbitra-geo-trunc-' . bin2hex(random_bytes(5));
        mkdir($truncRoot . '/geo', 0755, true);
        $writeHeader($truncRoot . '/geo/IP2LOCATION-LITE-DB11.BIN', 11, 1);
        if (orbitraGeoTargetingReady($truncRoot)['country'] !== false) {
            $failures[] = 'Truncated file should report country as false';
        }
        foreach (glob($truncRoot . '/geo/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($truncRoot . '/geo');
        @rmdir($truncRoot);

        // Unreadable file (fresh root) must not be ready. Root bypasses file
        // permissions, so the assertion is skipped when running as root.
        $unrRoot = sys_get_temp_dir() . '/orbitra-geo-unread-' . bin2hex(random_bytes(5));
        mkdir($unrRoot . '/geo', 0755, true);
        copy($sampleGeo, $unrRoot . '/geo/IP2LOCATION-LITE-DB11.BIN');
        chmod($unrRoot . '/geo/IP2LOCATION-LITE-DB11.BIN', 0000);
        $runningAsRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
        if (!$runningAsRoot && orbitraGeoTargetingReady($unrRoot)['country'] !== false) {
            $failures[] = 'Unreadable file should report country as false';
        }
        chmod($unrRoot . '/geo/IP2LOCATION-LITE-DB11.BIN', 0644);
        foreach (glob($unrRoot . '/geo/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($unrRoot . '/geo');
        @rmdir($unrRoot);
    }
} finally {
    foreach (glob($tempRoot . '/geo/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tempRoot . '/geo');
    foreach (glob($tempRoot . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tempRoot);
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Geo database tests passed.' . PHP_EOL;
