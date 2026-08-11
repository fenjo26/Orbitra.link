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
