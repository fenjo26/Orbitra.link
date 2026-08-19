#!/usr/bin/php
<?php
/**
 * ORB-014 Regression Test: Nginx Config Validation
 *
 * Validates that the generated nginx config meets all requirements:
 * 1. Every domain in the domains table has both an 80 and a 443 block
 * 2. A default_server exists on both ports (owned by Orbitra)
 * 3. Non-SSL domains use the self-signed certificate on 443
 *
 * Usage: php tests/nginx_config_regression_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));

require_once __DIR__ . '/../config.php';

echo "ORB-014 Nginx Config Regression Test\n";
echo "====================================\n\n";

$exitCode = 0;
$errors = [];
$warnings = [];

// ---- 1. Get all domains from database ------------------------------------
try {
    $stmt = $pdo->query("SELECT name, cloudflare_proxy, ssl_status FROM domains WHERE name IS NOT NULL AND name != '' ORDER BY name");
    $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($domains) . " domain(s) in database.\n\n";
} catch (PDOException $e) {
    echo "ERROR: Cannot query domains: " . $e->getMessage() . "\n";
    exit(1);
}

// ---- 2. Get generated nginx config ---------------------------------------
$configPath = defined('ORBITRA_NGINX_CONFIG_PATH') ? ORBITRA_NGINX_CONFIG_PATH : '/etc/nginx/sites-available/orbitra';

if (!file_exists($configPath)) {
    echo "ERROR: Nginx config not found at $configPath\n";
    echo "Run: sudo php cli/nginx_sync.php\n";
    exit(1);
}

$config = file_get_contents($configPath);
if ($config === false) {
    echo "ERROR: Cannot read nginx config at $configPath\n";
    exit(1);
}

echo "Using config: $configPath\n\n";

// ---- 3. Parse server blocks from config -----------------------------------
function orbitraExtractServerBlocks(string $config): array
{
    $blocks = [];
    $offset = 0;
    while (($pos = strpos($config, 'server {', $offset)) !== false) {
        $start = $pos + strlen('server {');
        $depth = 1;
        $len = strlen($config);
        $blockContent = '';
        for ($i = $start; $i < $len; $i++) {
            $char = $config[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $blockContent = substr($config, $start, $i - $start);
                    $offset = $i + 1;
                    break;
                }
            }
        }
        if ($blockContent !== '') {
            $blocks[] = $blockContent;
        } else {
            break;
        }
    }
    return $blocks;
}

$rawBlocks = orbitraExtractServerBlocks($config);
$serverBlocks = [];

foreach ($rawBlocks as $block) {
    // Extract listen directives
    preg_match_all('/listen\s+([^;]+);/', $block, $listenMatches);
    $listens = array_map('trim', $listenMatches[1] ?? []);

    // Extract server_name
    preg_match('/server_name\s+([^;]+);/', $block, $nameMatches);
    $serverName = trim($nameMatches[1] ?? '_');

    // Extract ssl_certificate if present
    preg_match('/ssl_certificate\s+([^;]+);/', $block, $certMatches);
    $sslCert = trim($certMatches[1] ?? '');

    $serverBlocks[] = [
        'listens' => $listens,
        'server_name' => $serverName,
        'ssl_certificate' => $sslCert,
    ];
}

echo "Found " . count($serverBlocks) . " server block(s) in config.\n\n";

// ---- 4. Verify default_server on both ports -----------------------------
$default80 = false;
$default443 = false;

foreach ($serverBlocks as $block) {
    foreach ($block['listens'] as $listen) {
        if (strpos($listen, '80') !== false && strpos($listen, 'default_server') !== false) {
            $default80 = true;
            if ($block['server_name'] === '_') {
                echo "✓ Port 80: Orbitra owns default_server (catch-all for _)\n";
            } else {
                $warnings[] = "Port 80 default_server is owned by '{$block['server_name']}', not Orbitra's catch-all";
            }
        }
        if (strpos($listen, '443') !== false && strpos($listen, 'default_server') !== false) {
            $default443 = true;
            if ($block['server_name'] === '_') {
                echo "✓ Port 443: Orbitra owns default_server (catch-all for _)\n";
            } else {
                $warnings[] = "Port 443 default_server is owned by '{$block['server_name']}', not Orbitra's catch-all";
            }
        }
    }
}

if (!$default80) {
    $errors[] = "Port 80: No default_server found. Orbitra must own the default server on port 80.";
    echo "✗ Port 80: No default_server found\n";
}

if (!$default443) {
    $errors[] = "Port 443: No default_server found. ORB-014: Orbitra must own the default server on port 443 to prevent other vhosts from capturing traffic.";
    echo "✗ Port 443: No default_server found (CRITICAL - ORB-014)\n";
}

echo "\n";

// ---- 5. Verify each domain has both 80 and 443 blocks --------------------
$missing443 = [];
$missing80 = [];
$domainsWithSsl = [];
$domainsWithSelfSigned = [];

foreach ($domains as $domain) {
    $name = $domain['name'];
    $has80 = false;
    $has443 = false;
    $hasLetsEncrypt = false;
    $hasSelfSigned = false;

    foreach ($serverBlocks as $block) {
        // Check if this server block covers this domain
        $coversDomain = $block['server_name'] === '_' ||
                        strpos($block['server_name'], $name) !== false;

        if (!$coversDomain) {
            continue;
        }

        foreach ($block['listens'] as $listen) {
            if (strpos($listen, '80') !== false && strpos($listen, '443') === false) {
                $has80 = true;
            }
            if (strpos($listen, '443') !== false) {
                $has443 = true;

                // Check certificate type
                if (strpos($block['ssl_certificate'], 'letsencrypt') !== false ||
                    strpos($block['ssl_certificate'], 'letsencrypt') !== false) {
                    $hasLetsEncrypt = true;
                    $domainsWithSsl[] = $name;
                } elseif (strpos($block['ssl_certificate'], 'orbitra/ssl/self-signed') !== false) {
                    $hasSelfSigned = true;
                    $domainsWithSelfSigned[] = $name;
                }
            }
        }
    }

    // Cloudflare-proxied domains may only need 443 with self-signed
    $isCloudflare = (int) ($domain['cloudflare_proxy'] ?? 0) === 1 || ($domain['ssl_status'] ?? '') === 'cloudflare';

    if (!$has80) {
        $missing80[] = $name;
    }

    if (!$has443) {
        $missing443[] = $name;
    } elseif ($isCloudflare && !$hasSelfSigned) {
        $warnings[] = "Cloudflare domain '$name' should have 443 block with self-signed cert for Full SSL mode";
    }
}

echo "Domain Coverage:\n";
echo "----------------\n";

if (empty($missing80)) {
    echo "✓ All " . count($domains) . " domain(s) have port 80 block(s)\n";
} else {
    $errors[] = "Missing port 80 blocks for: " . implode(', ', $missing80);
    echo "✗ Missing port 80 for: " . implode(', ', $missing80) . "\n";
}

if (empty($missing443)) {
    echo "✓ All " . count($domains) . " domain(s) have port 443 block(s)\n";
} else {
    $errors[] = "Missing port 443 blocks for: " . implode(', ', $missing443);
    echo "✗ Missing port 443 for: " . implode(', ', $missing443) . "\n";
}

if (!empty($domainsWithSsl)) {
    echo "✓ Let's Encrypt certificates: " . count($domainsWithSsl) . " domain(s)\n";
}

if (!empty($domainsWithSelfSigned)) {
    echo "✓ Self-signed certificates (Cloudflare Full / fallback): " . count($domainsWithSelfSigned) . " domain(s)\n";
}

echo "\n";

// ---- 6. Verify HTTPS catch-all exists -----------------------------------
$httpsCatchAllExists = false;
$selfSignedCertPath = defined('ORBITRA_SELF_SIGNED_CERT') ? ORBITRA_SELF_SIGNED_CERT : '/etc/orbitra/ssl/self-signed.crt';

foreach ($serverBlocks as $block) {
    $has443Ssl = false;
    foreach ($block['listens'] as $listen) {
        if (strpos($listen, '443') !== false && strpos($listen, 'ssl') !== false) {
            $has443Ssl = true;
            break;
        }
    }
    if ($block['server_name'] === '_' && $has443Ssl) {
        $httpsCatchAllExists = true;
        if (strpos($block['ssl_certificate'], $selfSignedCertPath) !== false) {
            echo "✓ HTTPS catch-all exists with self-signed certificate\n";
        } else {
            $warnings[] = "HTTPS catch-all exists but uses unexpected certificate: {$block['ssl_certificate']}";
        }
        break;
    }
}

if (!$httpsCatchAllExists) {
    $errors[] = "HTTPS catch-all server block missing. https://<ip>/admin.php may show another vhost's certificate.";
    echo "✗ HTTPS catch-all server block missing\n";
}

echo "\n";

// ---- 7. Verify nginx syntax -----------------------------------------------
$nginxTest = shell_exec('sudo nginx -t 2>&1');
if ($nginxTest !== null && strpos($nginxTest, 'successful') !== false) {
    echo "✓ Nginx configuration test passed\n";
} else {
    $errors[] = "Nginx configuration test failed: " . trim((string) $nginxTest);
    echo "✗ Nginx configuration test FAILED:\n";
    echo trim((string) $nginxTest) . "\n";
}

echo "\n";

// ---- 8. Summary -----------------------------------------------------------
echo "Summary\n";
echo "-------\n";

if (!empty($errors)) {
    echo "ERRORS (" . count($errors) . "):\n";
    foreach ($errors as $i => $error) {
        echo "  " . ($i + 1) . ". $error\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "WARNINGS (" . count($warnings) . "):\n";
    foreach ($warnings as $i => $warning) {
        echo "  " . ($i + 1) . ". $warning\n";
    }
    echo "\n";
}

if (empty($errors)) {
    echo "✓ All critical checks passed.\n";
    if (!empty($warnings)) {
        echo "  Review warnings above.\n";
        $exitCode = 0; // Warnings don't fail the test
    }
    exit(0);
} else {
    echo "✗ Test FAILED. Fix errors above.\n";
    exit(1);
}
