<?php

/**
 * Shared helpers for the provider database files kept by Orbitra.
 *
 * Provider files are intentionally stored separately. IP2Location DB11 is a
 * geolocation database, while IP2Proxy PX12 has a different binary layout. A
 * previous generic uploader treated every .BIN as DB11, which made a valid PX12
 * file produce corrupt latitude/longitude values when read by the wrong parser.
 */

function orbitraGeoDatabasePaths(?string $root = null): array
{
    $root = $root ?: dirname(__DIR__);

    return [
        'sypex_city' => $root . '/var/geoip/SxGeoCity/SxGeoCity.dat',
        'ip2location_geo' => $root . '/geo/IP2LOCATION-LITE-DB11.BIN',
        'ip2location_asn' => $root . '/geo/IP2LOCATION-LITE-ASN.BIN',
        'ip2proxy' => $root . '/geo/IP2PROXY-LITE-PX12.BIN',
        'maxmind_city' => $root . '/geo/GeoLite2-City.mmdb',
        'maxmind_asn' => $root . '/geo/GeoLite2-ASN.mmdb',
    ];
}

function orbitraGeoBinHeader(string $path): ?array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }
    $header = fread($handle, 64);
    fclose($handle);

    if (!is_string($header) || strlen($header) < 32) {
        return null;
    }

    return [
        'package' => ord($header[0]),
        'product_code' => ord($header[29]),
    ];
}

function orbitraGeoClassifyFile(string $path, string $originalName): array
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $name = strtoupper(basename($originalName));

    if ($extension === 'dat') {
        return ['kind' => 'sypex_city', 'label' => 'Sypex Geo City'];
    }

    if ($extension === 'bin') {
        $header = orbitraGeoBinHeader($path);
        if ($header === null || $header['package'] < 1) {
            throw new RuntimeException('Файл BIN повреждён или имеет неизвестный формат.');
        }

        // Product code 2 is IP2Proxy. This header check is authoritative even
        // when a user renamed the file before uploading it.
        if ($header['product_code'] === 2) {
            return [
                'kind' => 'ip2proxy',
                'label' => 'IP2Proxy PX' . $header['package'],
                'package' => $header['package'],
            ];
        }

        // ASN LITE is distributed as IP2LOCATION-LITE-ASN.BIN. Keep it apart
        // from the geolocation BIN because it has no latitude/longitude fields.
        if (strpos($name, 'ASN') !== false) {
            return ['kind' => 'ip2location_asn', 'label' => 'IP2Location ASN LITE'];
        }

        return [
            'kind' => 'ip2location_geo',
            'label' => 'IP2Location DB' . $header['package'],
            'package' => $header['package'],
        ];
    }

    if ($extension === 'mmdb') {
        if (!class_exists('\\MaxMind\\Db\\Reader')) {
            throw new RuntimeException('Для проверки MMDB не установлена библиотека MaxMind.');
        }
        $reader = new \MaxMind\Db\Reader($path);
        $databaseType = (string) $reader->metadata()->databaseType;
        $reader->close();

        if (stripos($databaseType, 'ASN') !== false) {
            return ['kind' => 'maxmind_asn', 'label' => $databaseType];
        }
        if (stripos($databaseType, 'City') !== false || stripos($databaseType, 'Country') !== false) {
            return ['kind' => 'maxmind_city', 'label' => $databaseType];
        }
        throw new RuntimeException('MMDB имеет неподдерживаемый тип: ' . $databaseType);
    }

    throw new RuntimeException('Поддерживаются только .dat, .bin, .mmdb и архивы .zip.');
}

function orbitraGeoValidateFile(string $path, array $classification): void
{
    if (!is_file($path) || filesize($path) < 1024) {
        throw new RuntimeException('Файл базы пустой или загружен не полностью.');
    }

    $kind = $classification['kind'] ?? '';
    if ($kind === 'ip2proxy') {
        if (!class_exists('\\IP2Proxy\\Database')) {
            throw new RuntimeException('Для IP2Proxy не установлена официальная PHP-библиотека.');
        }
        $db = new \IP2Proxy\Database($path, \IP2Proxy\Database::FILE_IO);
        $package = (int) $db->getPackageVersion();
        if ($package < 1 || $package > 12) {
            throw new RuntimeException('Неизвестная версия базы IP2Proxy.');
        }
        $db->close();
        return;
    }

    if ($kind === 'ip2location_geo' || $kind === 'ip2location_asn') {
        if (!class_exists('\\IP2Location\\Database')) {
            throw new RuntimeException('Для IP2Location не установлена официальная PHP-библиотека.');
        }
        $db = new \IP2Location\Database($path, \IP2Location\Database::FILE_IO);
        $fields = $db->getFields(true);
        if ($kind === 'ip2location_geo' && !in_array('countryCode', $fields, true)) {
            throw new RuntimeException('В IP2Location BIN отсутствует поле страны.');
        }
        if ($kind === 'ip2location_asn' && !in_array('asn', $fields, true)) {
            throw new RuntimeException('В ASN BIN отсутствует поле ASN.');
        }
        unset($db);
    }
}

function orbitraGeoFileStatus(string $path, string $expectedKind, string $displayName): string
{
    if (!is_file($path)) {
        return 'missing';
    }
    try {
        $classification = orbitraGeoClassifyFile($path, $displayName);
        if (($classification['kind'] ?? '') !== $expectedKind) {
            return 'invalid database type';
        }
        orbitraGeoValidateFile($path, $classification);
        return 'OK';
    } catch (Throwable $e) {
        return 'invalid database';
    }
}

/**
 * Validate a provider file and replace only its own destination atomically.
 */
function orbitraGeoInstallFile(string $sourcePath, string $originalName, ?string $root = null, bool $moveSource = false): array
{
    $root = $root ?: dirname(__DIR__);
    $classification = orbitraGeoClassifyFile($sourcePath, $originalName);
    orbitraGeoValidateFile($sourcePath, $classification);

    $paths = orbitraGeoDatabasePaths($root);
    $destination = $paths[$classification['kind']] ?? null;
    if ($destination === null) {
        throw new RuntimeException('Неизвестное назначение базы данных.');
    }

    $destinationDir = dirname($destination);
    if (!is_dir($destinationDir) && !mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
        throw new RuntimeException('Не удалось создать каталог для базы данных.');
    }

    $staged = $destination . '.upload-' . bin2hex(random_bytes(5));
    $stagedOk = false;
    if ($moveSource) {
        $stagedOk = @rename($sourcePath, $staged);
    }
    if (!$stagedOk) {
        $stagedOk = copy($sourcePath, $staged);
    }
    if (!$stagedOk) {
        throw new RuntimeException('Не удалось сохранить файл базы данных.');
    }

    try {
        orbitraGeoValidateFile($staged, $classification);
        chmod($staged, 0644);
        if (!rename($staged, $destination)) {
            throw new RuntimeException('Не удалось активировать новую базу данных.');
        }
    } catch (Throwable $e) {
        if (is_file($staged)) {
            @unlink($staged);
        }
        throw $e;
    }

    return $classification + [
        'path' => $destination,
        'size' => filesize($destination) ?: 0,
    ];
}

/**
 * Repair the legacy uploader mistake without losing the already uploaded PX file.
 */
function orbitraGeoMigrateMisplacedProxy(?string $root = null): bool
{
    $root = $root ?: dirname(__DIR__);
    $paths = orbitraGeoDatabasePaths($root);
    $wrongPath = $paths['ip2location_geo'];
    $proxyPath = $paths['ip2proxy'];

    if (!is_file($wrongPath) || is_file($proxyPath)) {
        return false;
    }
    $header = orbitraGeoBinHeader($wrongPath);
    if (($header['product_code'] ?? null) !== 2) {
        return false;
    }

    if (!is_dir(dirname($proxyPath))) {
        mkdir(dirname($proxyPath), 0755, true);
    }
    return rename($wrongPath, $proxyPath);
}

function orbitraGeoCoordinate($value, float $minimum, float $maximum): ?float
{
    if (!is_numeric($value)) {
        return null;
    }
    $coordinate = (float) $value;
    if (!is_finite($coordinate) || $coordinate < $minimum || $coordinate > $maximum) {
        return null;
    }
    return $coordinate;
}

function orbitraLookupIp2Proxy(string $ip, ?string $root = null): array
{
    $paths = orbitraGeoDatabasePaths($root);
    $path = $paths['ip2proxy'];
    if (!is_file($path) || !class_exists('\\IP2Proxy\\Database')) {
        return [];
    }

    try {
        $db = new \IP2Proxy\Database($path, \IP2Proxy\Database::FILE_IO);
        $record = $db->lookup($ip, \IP2Proxy\Database::ALL);
        $db->close();
        return is_array($record) ? $record : [];
    } catch (Throwable $e) {
        return [];
    }
}

function orbitraLookupIp2LocationAsn(string $ip, ?string $root = null): array
{
    $paths = orbitraGeoDatabasePaths($root);
    $path = $paths['ip2location_asn'];
    if (!is_file($path) || !class_exists('\\IP2Location\\Database')) {
        return [];
    }

    try {
        $db = new \IP2Location\Database($path, \IP2Location\Database::FILE_IO);
        $record = $db->lookup($ip, \IP2Location\Database::ALL);
        unset($db);
        return is_array($record) ? $record : [];
    } catch (Throwable $e) {
        return [];
    }
}
