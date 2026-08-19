#!/usr/bin/php
<?php
/**
 * Orbitra Landing Assets Diagnostic Tool
 *
 * Usage:
 *   sudo php /var/www/orbitra/cli/check_landings.php
 *
 * This script checks:
 * 1. Nginx configuration for X-Accel-Redirect support
 * 2. File permissions on the landings directory
 * 3. Sample landing asset accessibility
 * 4. PHP-FPM configuration
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║     Orbitra Landing Assets Diagnostic Tool                ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// ---- 1. Check Nginx configuration ---------------------------------
echo "📋 Checking Nginx configuration...\n";

$nginxConfigPath = '/etc/nginx/sites-available/orbitra';
$nginxEnabledPath = '/etc/nginx/sites-enabled/orbitra';

if (!file_exists($nginxConfigPath)) {
    echo "  ❌ Nginx config not found at: $nginxConfigPath\n";
    echo "  💡 Run: sudo php /var/www/orbitra/cli/nginx_sync.php\n";
} else {
    echo "  ✅ Config file exists: $nginxConfigPath\n";

    $nginxConfig = file_get_contents($nginxConfigPath);
    $hasInternalAssets = strpos($nginxConfig, 'location /_internal_assets/') !== false;
    $isSymlinked = is_link($nginxEnabledPath);

    if ($hasInternalAssets) {
        echo "  ✅ X-Accel-Redirect block found in config\n";

        // Check if the block is properly configured
        if (preg_match('/location\s+\/_internal_assets\/\s*\{([^}]+)\}/', $nginxConfig, $matches)) {
            $blockContent = $matches[1];
            $hasInternal = strpos($blockContent, 'internal') !== false;
            $hasAlias = strpos($blockContent, 'alias') !== false;

            if ($hasInternal && $hasAlias) {
                echo "  ✅ X-Accel-Redirect block is properly configured\n";
            } else {
                echo "  ⚠️  X-Accel-Redirect block is missing 'internal' or 'alias' directive\n";
                echo "  💡 Run: sudo php /var/www/orbitra/cli/nginx_sync.php\n";
            }
        }
    } else {
        echo "  ❌ X-Accel-Redirect block NOT found in config\n";
        echo "  💡 Run: sudo php /var/www/orbitra/cli/nginx_sync.php\n";
    }

    if (!$isSymlinked) {
        echo "  ⚠️  Config is not symlinked to sites-enabled\n";
        echo "  💡 Run: sudo ln -s /etc/nginx/sites-available/orbitra /etc/nginx/sites-enabled/orbitra\n";
    } else {
        echo "  ✅ Config is symlinked to sites-enabled\n";
    }
}

// ---- 2. Check nginx test -----------------------------------------
echo "\n🧪 Testing Nginx configuration...\n";
$output = [];
$returnCode = 0;
@exec('sudo nginx -t 2>&1', $output, $returnCode);

if ($returnCode === 0) {
    echo "  ✅ Nginx configuration test passed\n";
} else {
    echo "  ❌ Nginx configuration test FAILED:\n";
    foreach ($output as $line) {
        echo "     $line\n";
    }
    echo "  💡 Review the errors above and fix the configuration\n";
}

// ---- 3. Check file permissions ------------------------------------
echo "\n🔐 Checking file permissions...\n";

$landingsDir = __DIR__ . '/../landings';
if (!is_dir($landingsDir)) {
    echo "  ⚠️  Landings directory does not exist: $landingsDir\n";
} else {
    echo "  📁 Landings directory: $landingsDir\n";

    $perms = substr(sprintf('%o', fileperms($landingsDir)), -4);
    $owner = posix_getpwuid(fileowner($landingsDir));
    $group = posix_getgrgid(filegroup($landingsDir));

    echo "     Permissions: $perms\n";
    echo "     Owner: {$owner['name']}\n";
    echo "     Group: {$group['name']}\n";

    // Check if web server can read
    $webUser = 'www-data';
    $canRead = is_readable($landingsDir);

    if ($canRead) {
        echo "  ✅ Directory is readable\n";
    } else {
        echo "  ❌ Directory is NOT readable\n";
        echo "  💡 Run: sudo chmod -R 755 $landingsDir\n";
    }

    // Check a sample landing if exists
    $sampleLandingDirs = glob($landingsDir . '/*', GLOB_ONLYDIR);
    if (!empty($sampleLandingDirs)) {
        $sampleLanding = $sampleLandingDirs[0];
        $sampleFiles = glob($sampleLanding . '/*');
        if (!empty($sampleFiles)) {
            $sampleFile = $sampleFiles[0];
            if (is_readable($sampleFile)) {
                echo "  ✅ Sample landing file is readable: " . basename($sampleFile) . "\n";
            } else {
                echo "  ❌ Sample landing file is NOT readable: " . basename($sampleFile) . "\n";
                echo "  💡 Run: sudo chown -R www-data:www-data $landingsDir\n";
            }
        }
    }
}

// ---- 4. Check PHP-FPM socket -------------------------------------
echo "\n🔌 Checking PHP-FPM socket...\n";

$fpmSockets = glob('/var/run/php/php*-fpm.sock') ?: [];
$fpmSockets = array_merge($fpmSockets, glob('/run/php/php*-fpm.sock') ?: []);

if (empty($fpmSockets)) {
    echo "  ⚠️  No PHP-FPM sockets found\n";
} else {
    natsort($fpmSockets);
    $fpmSocket = end($fpmSockets);
    echo "  ✅ PHP-FPM socket found: $fpmSocket\n";

    if (is_readable($fpmSocket)) {
        echo "  ✅ Socket is readable by PHP\n";
    } else {
        echo "  ⚠️  Socket may not be readable by PHP\n";
    }
}

// ---- 5. Check for common issues -----------------------------------
echo "\n🔍 Checking for common issues...\n";

// Check if running on non-standard port
$portCheck = @file_get_contents('/etc/nginx/sites-available/orbitra');
if ($portCheck && preg_match('/listen\s+(\d+)/', $portCheck, $portMatches)) {
    $ports = array_unique($portMatches);
    if (in_array('8750', $ports)) {
        echo "  ⚠️  Port 8750 detected in nginx config\n";
        echo "  💡 Ensure the port-specific config includes /_internal_assets/ block\n";
    }
}

// Check for self-signed certificate
$sslCert = '/etc/orbitra/ssl/self-signed.crt';
if (file_exists($sslCert)) {
    echo "  ✅ Self-signed SSL certificate exists\n";
} else {
    echo "  ⚠️  Self-signed SSL certificate not found\n";
    echo "  💡 HTTPS by IP will not work. Run nginx_sync.php to generate it.\n";
}

// ---- 6. Summary and recommendations -------------------------------
echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║                     Summary                               ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

echo "📝 Recommended actions:\n\n";

// Collect issues
$issues = [];
$fixes = [];

if (!$hasInternalAssets ?? false) {
    $issues[] = "Run nginx sync to generate proper config";
    $fixes[] = "sudo php /var/www/orbitra/cli/nginx_sync.php";
}

if (!$canRead ?? false) {
    $issues[] = "Fix file permissions";
    $fixes[] = "sudo chown -R www-data:www-data /var/www/orbitra/landings";
    $fixes[] = "sudo chmod -R 755 /var/www/orbitra/landings";
}

if ($returnCode !== 0) {
    $issues[] = "Fix nginx configuration errors";
    $fixes[] = "sudo nginx -t";
}

if (empty($issues)) {
    echo "  ✅ No issues found! Your landing assets should work correctly.\n\n";
    echo "  If you still see issues:\n";
    echo "  1. Enable debug mode: putenv('ORBITRA_LANDING_DEBUG=1') in index.php\n";
    echo "  2. Or add ?orbitra_debug_assets=1 to your landing URL\n";
    echo "  3. Check browser DevTools Network tab for X-Orbitra-Asset-* headers\n";
} else {
    foreach ($issues as $i => $issue) {
        echo "  " . ($i + 1) . ". $issue\n";
    }
    echo "\n";
    echo "🔧 Commands to run:\n\n";
    foreach ($fixes as $fix) {
        echo "  $fix\n";
    }
    echo "\n";
    echo "  After running these commands, restart nginx:\n";
    echo "  sudo systemctl reload nginx\n";
}

echo "\n";
exit($returnCode);
