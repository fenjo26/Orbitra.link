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
 * Verify the Sypex reader actually works: the SxGeo class must load from
 * core/SxGeo.php and resolve a known IP (8.8.8.8 → US) against the .dat.
 * A present .dat alone proved not enough: v1.5.15 shipped a 0-byte
 * core/SxGeo.php, so every Sypex lookup was silently skipped while the
 * database looked "installed". Memoised per .dat version — the click path
 * runs this probe on every readiness check.
 */
function orbitraSypexReaderReady(string $datPath, ?string $root = null): bool
{
    static $memo = [];
    $root = $root ?: dirname(__DIR__);
    $parserPath = $root . '/core/SxGeo.php';

    if (!is_file($datPath) || !is_file($parserPath)) {
        return false;
    }

    // Key on the .dat's identity so a freshly replaced database is probed
    // again instead of inheriting the previous version's verdict.
    $key = $datPath . '|' . (filemtime($datPath) ?: 0) . '|' . (filesize($datPath) ?: 0);
    if (isset($memo[$key])) {
        return $memo[$key];
    }

    $ready = false;
    try {
        require_once $parserPath;
        if (class_exists('\\SxGeo')) {
            // Silence warnings from a corrupt file: the probe only answers
            // yes/no and must not leak diagnostics into the click path.
            $reader = @new \SxGeo($datPath);
            $country = @$reader->getCountry('8.8.8.8');
            $ready = is_string($country) && $country !== '';
            if (method_exists($reader, 'close')) {
                $reader->close();
            }
        }
    } catch (Throwable $e) {
        $ready = false;
    }

    $memo[$key] = $ready;
    return $ready;
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

/**
 * Check which geo targeting capabilities are available.
 * Memoised per root directory to avoid multiple file checks.
 *
 * @param string|null $root Base directory, defaults to parent of core/.
 * @return array ['country' => bool, 'asn' => bool, 'proxy' => bool, 'files' => string[]]
 */
function orbitraGeoTargetingReady(?string $root = null): array
{
    static $memo = [];
    $root = $root ?: dirname(__DIR__);

    if (isset($memo[$root])) {
        return $memo[$root];
    }

    $paths = orbitraGeoDatabasePaths($root);

    $files = [];

    // Country targeting: at least one of IP2Location DB11 or MaxMind City
    $countryReady = false;
    $ip2locPath = $paths['ip2location_geo'];
    $maxmindPath = $paths['maxmind_city'];
    $sypexPath = $paths['sypex_city'];

    foreach ([
        [$ip2locPath, 'ip2location_geo', 'IP2LOCATION-LITE-DB11.BIN'],
        [$maxmindPath, 'maxmind_city', 'GeoLite2-City.mmdb'],
        [$sypexPath, 'sypex_city', 'SxGeoCity.dat']
    ] as $check) {
        [$path, $kind, $name] = $check;
        if (orbitraGeoFileStatus($path, $kind, $name) !== 'OK') {
            continue;
        }
        // A valid-looking Sypex .dat is not enough: the tracker reads it
        // through core/SxGeo.php, so the reader class must load and resolve
        // a lookup. With an empty parser the database sat there unused.
        if ($kind === 'sypex_city' && !orbitraSypexReaderReady($path, $root)) {
            continue;
        }
        $countryReady = true;
        $files[] = $path;
        break;
    }

    // ASN targeting: either IP2Location ASN or MaxMind ASN
    $asnReady = false;
    $asnPath = $paths['ip2location_asn'];
    $maxmindAsnPath = $paths['maxmind_asn'];

    foreach ([
        [$asnPath, 'ip2location_asn', 'IP2LOCATION-LITE-ASN.BIN'],
        [$maxmindAsnPath, 'maxmind_asn', 'GeoLite2-ASN.mmdb']
    ] as $check) {
        [$path, $kind, $name] = $check;
        if (orbitraGeoFileStatus($path, $kind, $name) === 'OK') {
            $asnReady = true;
            $files[] = $path;
            break;
        }
    }

    // Proxy detection: IP2Proxy PX12
    $proxyPath = $paths['ip2proxy'];
    $proxyReady = orbitraGeoFileStatus($proxyPath, 'ip2proxy', 'IP2PROXY-LITE-PX12.BIN') === 'OK';
    if ($proxyReady) {
        $files[] = $proxyPath;
    }

    $memo[$root] = [
        'country' => $countryReady,
        'asn' => $asnReady,
        'proxy' => $proxyReady,
        'files' => $files
    ];

    return $memo[$root];
}

// ═══════════════════════════════════════════════════════════════════════════
// Updaters. The same code serves the panel's update buttons (api.php), the
// installer, the monthly cron and the post-git-pull self-heal — one place to
// get the timeouts and the non-fatal error handling right.
//
// sypexgeo.net is sometimes slow or unreachable from hosting networks, so a
// failed download returns a result array instead of throwing: installing the
// tracker must never fail because of a geo database (TZ_LOGS_GEO_STREAMS §2).

require_once __DIR__ . '/shell.php';

const ORBITRA_SYPEX_ZIP_URL = 'https://sypexgeo.net/files/SxGeoCity_utf8.zip';

/** Is any provider database installed and non-empty? */
function orbitraGeoDatabasesInstalled(?string $root = null): bool
{
    foreach (orbitraGeoDatabasePaths($root) as $path) {
        if (is_file($path) && (filesize($path) ?: 0) > 1024) {
            return true;
        }
    }
    return false;
}

/**
 * GET a URL into memory with a bounded timeout; null on any failure.
 */
function orbitraGeoFetch(string $url, int $timeout = 60): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }
    // TLS is verified unconditionally. This runs in the installer and the
    // monthly cron (both CLI) and from the panel; skipping peer verification
    // on CLI would let a man-in-the-middle serve a tampered geo database.
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Orbitra-Tracker',
    ]);
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return (is_string($data) && $httpCode === 200) ? $data : null;
}

/**
 * Install/update the free Sypex Geo City database (country / region / city).
 * No account or key: download the zip, extract SxGeoCity.dat, then verify the
 * whole chain — the reader class must load from core/SxGeo.php (shipped in
 * the repository, patched for PHP 8) and a probe lookup must actually
 * resolve. The install only counts when the probe passes.
 *
 * $fetch is injectable so tests run against a fixture zip without network.
 *
 * @param callable|null $fetch fn(string $url): ?string
 * @return array{ok: bool, message: string, path?: string}
 */
function orbitraUpdateSypex(?string $root = null, ?callable $fetch = null, int $timeout = 60): array
{
    $root = $root ?: dirname(__DIR__);
    $fetch = $fetch ?: static fn (string $url): ?string => orbitraGeoFetch($url, $timeout);
    $geoDir = $root . '/var/geoip/SxGeoCity';
    $tempDir = null;

    try {
        if (!is_dir($geoDir) && !mkdir($geoDir, 0777, true) && !is_dir($geoDir)) {
            throw new RuntimeException('Не удалось создать каталог ' . $geoDir);
        }

        $zipData = $fetch(ORBITRA_SYPEX_ZIP_URL);
        if (!is_string($zipData) || $zipData === '') {
            throw new RuntimeException('Не удалось скачать архив базы от Sypex (sypexgeo.net недоступен или медленный). Повторите позже или загрузите базу вручную в настройках.');
        }
        $zipFile = $geoDir . '/SxGeoCity_utf8.zip';
        if (file_put_contents($zipFile, $zipData) === false) {
            throw new RuntimeException('Не удалось сохранить архив базы: ' . $zipFile);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('Не удалось открыть скачанный архив Sypex.');
        }
        $tempDir = sys_get_temp_dir() . '/orbitra_sypex_' . bin2hex(random_bytes(6));
        if (!mkdir($tempDir, 0755, true)) {
            $zip->close();
            throw new RuntimeException('Не удалось создать временный каталог для распаковки.');
        }
        $zip->extractTo($tempDir);
        $zip->close();
        @unlink($zipFile);

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS)
        );
        $datPath = null;
        foreach ($iter as $file) {
            if ($file->isFile() && $file->getFilename() === 'SxGeoCity.dat') {
                $datPath = $file->getPathname();
                break;
            }
        }
        if ($datPath === null || filesize($datPath) < 1024) {
            throw new RuntimeException('В архиве Sypex не найден SxGeoCity.dat.');
        }
        $destDat = $geoDir . '/SxGeoCity.dat';
        $staged = $destDat . '.tmp-' . bin2hex(random_bytes(4));
        if (!copy($datPath, $staged) || !rename($staged, $destDat)) {
            if (is_file($staged)) {
                @unlink($staged);
            }
            throw new RuntimeException('Не удалось сохранить SxGeoCity.dat в ' . $geoDir);
        }
        @chmod($destDat, 0644);

        // The repository ships the reader itself (core/SxGeo.php — Sypex Geo
        // API 2.2.3, BSD, patched for PHP 8): the vendor DB archive carries
        // only SxGeoCity.dat. Keep a copy-from-archive fallback in case the
        // vendor bundle ever ships a reader again. Install it whenever the
        // file is missing or empty: a 0-byte stub passes file_exists() but
        // silently kills every Sypex lookup.
        $parserPath = $root . '/core/SxGeo.php';
        if (!file_exists($parserPath) || (filesize($parserPath) ?: 0) === 0) {
            foreach ($iter as $file) {
                if ($file->isFile() && $file->getFilename() === 'SxGeo.php') {
                    @copy($file->getPathname(), $parserPath);
                    break;
                }
            }
        }

        // Sanity check before declaring victory: the reader must load and
        // resolve a known IP (8.8.8.8 is expected to be US). A corrupt .dat
        // or a broken parser would otherwise leave geo "installed" while
        // every lookup still fails.
        $classUsable = false;
        $sanityOk = false;
        if (is_file($parserPath)) {
            try {
                require_once $parserPath;
                if (class_exists('\\SxGeo')) {
                    $classUsable = true;
                    $probe = new \SxGeo($destDat);
                    $country = @$probe->getCountry('8.8.8.8');
                    $sanityOk = is_string($country) && $country !== '';
                    if (method_exists($probe, 'close')) {
                        $probe->close();
                    }
                }
            } catch (Throwable $e) {
                $sanityOk = false;
            }
        }
        if (!$sanityOk) {
            // Drop the freshly installed .dat so the installer's
            // `test -s SxGeoCity.dat` and the readiness probe report
            // "not installed" honestly instead of a silent no-op geo.
            @unlink($destDat);
            if (!$classUsable) {
                // A parser that fails to load would break the click path too
                // (require_once of a broken file is fatal there). The reader
                // is version-controlled, so removing a broken copy is safe:
                // git brings it back on the next pull.
                @unlink($parserPath);
            }
            throw new RuntimeException('База Sypex скачана, но не прошла проверку чтения (класс SxGeo не загрузился или getCountry(8.8.8.8) не вернул страну). Загруженные файлы удалены — повторите обновление позже.');
        }

        return ['ok' => true, 'message' => 'База Sypex успешно обновлена', 'path' => $destDat];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    } finally {
        if (is_string($tempDir) && is_dir($tempDir)) {
            orbitraRemoveDirectory($tempDir);
        }
    }
}

/**
 * Download and activate a MaxMind GeoLite2 edition (Basic-auth permalink that
 * redirects to Cloudflare R2 — FOLLOWLOCATION is mandatory since 2024).
 *
 * @return array{ok: bool, message: string}
 */
function orbitraUpdateMaxMind(string $editionId, string $accountId, string $licenseKey, ?string $root = null, int $timeout = 300): array
{
    $root = $root ?: dirname(__DIR__);
    $tmpArchive = sys_get_temp_dir() . '/orbitra-' . strtolower($editionId) . '-' . bin2hex(random_bytes(6)) . '.tar.gz';
    try {
        $url = "https://download.maxmind.com/geoip/databases/{$editionId}/download?suffix=tar.gz";
        $ch = curl_init($url);
        $fp = @fopen($tmpArchive, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Не удалось создать временный файл для загрузки MaxMind.');
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_HEADER => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERPWD => $accountId . ':' . $licenseKey,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Orbitra-Tracker',
        ]);
        $downloadOk = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        fclose($fp);

        if ($downloadOk === false || $httpCode !== 200 || !is_file($tmpArchive) || filesize($tmpArchive) <= 1024) {
            $details = $curlError !== '' ? " cURL: {$curlError}" : '';
            throw new RuntimeException("Failed to download {$editionId}. HTTP Code: {$httpCode}. Check your Account ID, License Key and outbound HTTPS access.{$details}");
        }

        $dbFileName = $editionId . '.mmdb';
        $destPath = $root . '/geo/' . $dbFileName;
        if (!is_dir($root . '/geo') && !mkdir($root . '/geo', 0755, true) && !is_dir($root . '/geo')) {
            throw new RuntimeException('Не удалось создать каталог geo.');
        }

        $ref = new ReflectionClass('\PharData');
        $p = $ref->newInstance($tmpArchive);
        $extracted = false;
        foreach (new RecursiveIteratorIterator($p) as $file) {
            if ($file->getFilename() === $dbFileName) {
                $tmpDestPath = $destPath . '.tmp-' . bin2hex(random_bytes(4));
                if (copy($file->getPathname(), $tmpDestPath) && filesize($tmpDestPath) > 1024) {
                    chmod($tmpDestPath, 0644);
                    $extracted = rename($tmpDestPath, $destPath);
                }
                if (is_file($tmpDestPath)) {
                    @unlink($tmpDestPath);
                }
                break;
            }
        }
        if (!$extracted) {
            throw new RuntimeException("Failed to find {$dbFileName} in downloaded archive");
        }
        return ['ok' => true, 'message' => "База MaxMind {$editionId} успешно обновлена", 'path' => $destPath];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    } finally {
        if (is_file($tmpArchive)) {
            @unlink($tmpArchive);
        }
    }
}

/**
 * Download and activate an IP2Location / IP2Proxy package. The endpoint
 * answers with a small HTML error page (not a ZIP) on a bad token or an
 * exhausted quota — hence the explicit ZIP check.
 *
 * @return array{ok: bool, message: string}
 */
function orbitraUpdateIp2(string $variant, string $kind, string $token, ?string $root = null, int $timeout = 600): array
{
    $root = $root ?: dirname(__DIR__);
    $tmpArchive = sys_get_temp_dir() . '/orbitra-ip2-' . bin2hex(random_bytes(6)) . '.zip';
    try {
        $url = 'https://www.ip2location.com/download?' . http_build_query(['token' => $token, 'file' => $variant]);
        $ch = curl_init($url);
        $fp = @fopen($tmpArchive, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Не удалось создать временный файл для загрузки. Проверьте права на запись в ' . sys_get_temp_dir());
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_HEADER => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Orbitra-Tracker',
        ]);
        $downloadOk = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        fclose($fp);

        if ($downloadOk === false || $httpCode !== 200 || !is_file($tmpArchive) || filesize($tmpArchive) <= 1024) {
            $details = $curlError !== '' ? ' cURL: ' . $curlError : '';
            throw new RuntimeException("Не удалось скачать {$variant}. Проверьте токен и квоту IP2Location.{$details}");
        }

        $zip = new ZipArchive;
        if ($zip->open($tmpArchive) !== true) {
            throw new RuntimeException('Архив IP2Location не является корректным ZIP. Возможно, исчерпана квота скачиваний.');
        }
        $installed = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = (string) $zip->getNameIndex($i);
            if (strtolower(pathinfo($entryName, PATHINFO_EXTENSION)) !== 'bin') {
                continue;
            }
            $input = $zip->getStream($entryName);
            $tempPath = tempnam(sys_get_temp_dir(), 'orbitra-ip2-bin-');
            $output = $tempPath !== false ? fopen($tempPath, 'wb') : false;
            if ($input === false || $output === false) {
                if (is_resource($input)) {
                    fclose($input);
                }
                throw new RuntimeException('Не удалось распаковать BIN из архива.');
            }
            stream_copy_to_stream($input, $output);
            fclose($input);
            fclose($output);

            try {
                $classification = orbitraGeoClassifyFile($tempPath, basename($entryName));
                if ($classification['kind'] !== $kind) {
                    throw new RuntimeException('Полученный BIN имеет неожиданный тип: ' . $classification['kind']);
                }
                $installed = orbitraGeoInstallFile($tempPath, basename($entryName), $root, true);
            } finally {
                if (is_file($tempPath)) {
                    @unlink($tempPath);
                }
            }
            break;
        }
        $zip->close();

        if ($installed === null) {
            throw new RuntimeException('В скачанном архиве не найден BIN.');
        }
        return ['ok' => true, 'message' => "База {$installed['label']} успешно обновлена ({$variant})", 'path' => $installed['path']];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Ошибка установки: ' . $e->getMessage()];
    } finally {
        if (is_file($tmpArchive)) {
            @unlink($tmpArchive);
        }
    }
}
