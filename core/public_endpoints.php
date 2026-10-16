<?php
/**
 * Public api.php endpoints — called from visitors' browsers on landings and
 * third-party sites, so they must answer WITHOUT a panel session.
 *
 *   api.php?action=detect_geo          -> {"country":"DE","ip":"…"}  (Geo Redirect script)
 *   api.php?action=app_config&key=K    -> the config JSON             (App Config / WordPress / static site)
 *
 * They used to live in the main switch, below the admin-IP allowlist and the
 * auth middleware: every visitor got 401 Unauthorized (with no CORS header, so
 * the browser reported only "Failed to fetch") and the Geo Redirect, WordPress,
 * static-site and App Config integrations silently did nothing. api.php now
 * dispatches these two before any of that.
 */

function orbitraPublicAppConfig(PDO $pdo): void
{
    header('Access-Control-Allow-Origin: *');
    $key = $_GET['key'] ?? '';
    if (!$key) {
        echo json_encode(['error' => 'Key required']);
        return;
    }
    $stmt = $pdo->prepare("SELECT config_json, is_active FROM app_configs WHERE config_key = ?");
    $stmt->execute([$key]);
    $config = $stmt->fetch();
    if (!$config) {
        http_response_code(404);
        echo json_encode(['error' => 'Config not found']);
    } elseif (!$config['is_active']) {
        echo json_encode(['active' => false]);
    } else {
        echo $config['config_json'];
    }
}

function orbitraPublicDetectGeo(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');

    // Reuse GeoIP logic from index.php
    if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Check forwarded headers
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            foreach (explode(',', $_SERVER[$key]) as $candidateIp) {
                $candidateIp = trim($candidateIp);
                if (filter_var($candidateIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    $ip = $candidateIp;
                    break 2;
                }
            }
        }
    }

    $country = 'UNKNOWN';
    if (!in_array($ip, ['127.0.0.1', '::1'])) {
        // 1. MaxMind
        $maxMindDb = dirname(__DIR__) . '/geo/GeoLite2-City.mmdb';
        if (file_exists($maxMindDb) && class_exists('\GeoIp2\Database\Reader')) {
            try {
                $reader = new \GeoIp2\Database\Reader($maxMindDb);
                $record = $reader->city($ip);
                $country = $record->country->isoCode ?: 'UNKNOWN';
            } catch (\Exception $e) {
            }
        }

        // 2. IP2Location (DB11)
        if ($country === 'UNKNOWN') {
            $ip2locCandidates = [
                dirname(__DIR__) . '/geo/IP2LOCATION-LITE-DB11.BIN',
                dirname(__DIR__) . '/geo/IP2LOCATION-LITE.BIN'
            ];
            $ip2locDb = null;
            foreach ($ip2locCandidates as $candidate) {
                if (file_exists($candidate)) {
                    $ip2locDb = $candidate;
                    break;
                }
            }

            if ($ip2locDb && class_exists('\IP2Location\Database')) {
                try {
                    $db = new \IP2Location\Database($ip2locDb, \IP2Location\Database::FILE_IO);
                    $records = $db->lookup($ip, \IP2Location\Database::ALL);
                    if ($records && is_array($records) && !empty($records['countryCode']) && $records['countryCode'] !== '-') {
                        $country = $records['countryCode'];
                    }
                } catch (\Exception $e) {
                }
            }
        }

        // 3. SxGeo
        if ($country === 'UNKNOWN') {
            $sxGeoDat = dirname(__DIR__) . '/var/geoip/SxGeoCity/SxGeoCity.dat';
            $sxGeoParser = dirname(__DIR__) . '/core/SxGeo.php';
            if (file_exists($sxGeoDat) && file_exists($sxGeoParser)) {
                require_once $sxGeoParser;
                try {
                    $sxGeoClass = '\SxGeo';
                    if (class_exists($sxGeoClass)) {
                        $sxGeo = new $sxGeoClass($sxGeoDat);
                        $cc = $sxGeo->getCountry($ip);
                        if ($cc)
                            $country = $cc;
                    }
                } catch (\Exception $e) {
                }
            }
        }

        // 4. Fallback: external API
        if ($country === 'UNKNOWN') {
            $ch = curl_init("http://ip-api.com/json/{$ip}?fields=countryCode");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            $response = curl_exec($ch);
            // curl_close() deprecated in PHP 8.5 - resources are auto-freed
            if ($response) {
                $data = json_decode($response, true);
                if (!empty($data['countryCode']))
                    $country = $data['countryCode'];
            }
        }
    }

    echo json_encode(['country' => $country, 'ip' => $ip]);
}
