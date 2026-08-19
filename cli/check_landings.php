#!/usr/bin/env php
<?php
/**
 * Diagnose landing/offer asset serving issues.
 *
 * Usage: sudo php cli/check_landings.php
 *
 * This tool checks:
 * 1. Directory permissions on landings/ and offers/
 * 2. Presence of /_internal_assets/ in nginx config
 * 3. Test HTTP requests to sample assets
 * 4. Verify ACME webroot is accessible
 * 5. Check self-signed certificate exists
 */

$root = dirname(__DIR__);

// Colors for output
$GREEN = "\033[32m";
$YELLOW = "\033[33m";
$RED = "\033[31m";
$RESET = "\033[0m";

function ✓($msg) {
    global $GREEN, $RESET;
    echo "{$GREEN}✓{$RESET} $msg\n";
}

function ⚠($msg) {
    global $YELLOW, $RESET;
    echo "{$YELLOW}⚠{$RESET} $msg\n";
}

function ✗($msg) {
    global $RED, $RESET;
    echo "{$RED}✗{$RESET} $msg\n";
}

echo "======================================================\n";
echo "       Orbitra Landing/Offer Diagnostics              \n";
echo "======================================================\n\n";

// Check 1: Directory permissions
echo "Directory Permissions:\n";

$landingsDir = $root . '/landings';
if (is_dir($landingsDir)) {
    if (is_writable($landingsDir)) {
        ✓("landings/ directory exists and is writable");
    } else {
        ⚠("landings/ directory exists but may not be writable");
    }
} else {
    ✗("landings/ directory does not exist");
}

$offersDir = $root . '/offers';
if (is_dir($offersDir)) {
    if (is_writable($offersDir)) {
        ✓("offers/ directory exists and is writable");
    } else {
        ⚠("offers/ directory exists but may not be writable");
    }
} else {
    ✗("offers/ directory does not exist");
}

// Check 2: Nginx config
echo "\nNginx Configuration:\n";

$nginxConfigPath = '/etc/nginx/sites-available/orbitra';
if (file_exists($nginxConfigPath)) {
    $config = file_get_contents($nginxConfigPath);
    if (strpos($config, 'location /_internal_assets/') !== false) {
        ✓("Nginx config contains /_internal_assets/ location");
    } else {
        ✗("Nginx config missing /_internal_assets/ location");
    }
} else {
    ⚠("Nginx config not found at $nginxConfigPath");
}

// Test nginx config syntax
$nginxTest = shell_exec('nginx -t 2>&1');
if ($nginxTest && stripos($nginxTest, 'successful') !== false) {
    ✓("Nginx configuration syntax is valid");
} else {
    ✗("Nginx configuration syntax may have errors");
}

// Check 3: ACME webroot
echo "\nSSL / Let's Encrypt:\n";

$acmeWebroot = $root . '/var/acme/.well-known/acme-challenge';
if (is_dir($acmeWebroot)) {
    if (is_writable($acmeWebroot)) {
        ✓("ACME webroot is accessible: /.well-known/acme-challenge/");
    } else {
        ⚠("ACME webroot exists but may not be writable");
    }
} else {
    ✗("ACME webroot does not exist");
}

// Check 4: Self-signed certificate
$selfSignedCert = '/etc/orbitra/ssl/self-signed.crt';
$selfSignedKey = '/etc/orbitra/ssl/self-signed.key';
if (file_exists($selfSignedCert) && file_exists($selfSignedKey)) {
    ✓("Self-signed certificate exists at /etc/orbitra/ssl/");
} else {
    ⚠("Self-signed certificate may be missing");
}

// Check 5: Sample asset test (if we have landings)
echo "\nAsset Serving Test:\n";

$testLandingId = null;
$landingsDirs = glob($root . '/landings/*', GLOB_ONLYDIR);
if (!empty($landingsDirs)) {
    foreach ($landingsDirs as $dir) {
        $id = basename($dir);
        if (is_numeric($id)) {
            $testLandingId = $id;
            break;
        }
    }
}

if ($testLandingId) {
    // Try to find a sample asset
    $landingDir = $root . '/landings/' . $testLandingId;
    $sampleFiles = [];
    foreach (['png', 'jpg', 'jpeg', 'css', 'js'] as $ext) {
        $files = glob($landingDir . '/*.' . $ext);
        if (!empty($files)) {
            $sampleFiles[] = $files[0];
            break;
        }
    }

    if (!empty($sampleFiles)) {
        $sampleFile = $sampleFiles[0];
        echo "  Testing with: " . basename($sampleFile) . "\n";

        // Try to fetch via HTTP (this tests the whole chain)
        $testUrl = 'http://localhost/' . basename($sampleFile);
        $response = @file_get_contents($testUrl, false, stream_context_create([
            'http' => ['timeout' => 5]
        ]));

        if ($response !== false) {
            ✓("Sample asset accessible via HTTP");
        } else {
            ⚠("Could not test sample asset via HTTP (this may be normal if testing from CLI)");
        }
    } else {
        echo "  No sample assets found in landing directory\n";
    }
} else {
    echo "  No landings found to test asset serving\n";
}

// Summary
echo "\n======================================================\n";
echo "Diagnostics complete. If all checks passed (✓), your\n";
echo "installation should be working correctly. If you see\n";
echo "warnings (⚠), you may want to investigate those items.\n";
echo "======================================================\n";
