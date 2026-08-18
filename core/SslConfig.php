<?php
/**
 * SSL/TLS Configuration Helpers
 *
 * Detects system CA certificates and provides fallback configuration for PHP
 * stream contexts. This is essential for outbound HTTPS connections when:
 *
 * - Running on shared hosting with broken CA bundles
 * - Running on Alpine Docker images without ca-certificates
 * - Running on systems with non-standard certificate paths
 */

/**
 * Common CA certificate bundle paths across different systems.
 *
 * Ordered by likelihood of existence. The first found path will be used.
 */
// File-scope const, not a class member: these helpers are plain functions,
// so `private const` (and the matching `self::`) was a parse error and the
// file could not be loaded at all.
const CA_CERTIFICATE_PATHS = [
    // Debian/Ubuntu
    '/etc/ssl/certs/ca-certificates.crt',
    // RedHat/CentOS/Fedora
    '/etc/pki/tls/certs/ca-bundle.crt',
    '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',
    // Alpine Linux
    '/etc/ssl/cert.pem',
    // macOS (Homebrew)
    '/usr/local/etc/openssl/cert.pem',
    '/opt/homebrew/etc/openssl@3/cert.pem',
    '/opt/homebrew/etc/openssl@1.1/cert.pem',
    // Generic OpenSSL
    '/usr/lib/ssl/certs/ca-certificates.crt',
    '/usr/local/share/ca-certificates/ca-bundle.crt',
    // Windows (Git for Windows, Cygwin, MSYS2)
    'C:\\Program Files\\Git\\mingw64\\ssl\\certs\\ca-bundle.crt',
    'C:\\msys64\\usr\\ssl\\certs\\ca-bundle.crt',
    'C:\\cygwin64\\usr\\ssl\\certs\\ca-bundle.crt',
];

/**
 * Get the path to the system CA certificate bundle, if one exists.
 *
 * Returns null if no system bundle is found, in which case the caller should
 * either use a bundled certificate or fail gracefully.
 *
 * @return string|null Path to CA bundle, or null if not found
 */
function orbitraGetSystemCaBundle(): ?string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached ?: null;
    }

    // Check openssl.cafile configuration first
    $configuredCafile = ini_get('openssl.cafile');
    if ($configuredCafile && is_file($configuredCafile) && is_readable($configuredCafile)) {
        return $cached = $configuredCafile;
    }

    // Check curl.cainfo as fallback
    $curlCafile = ini_get('curl.cainfo');
    if ($curlCafile && is_file($curlCafile) && is_readable($curlCafile)) {
        return $cached = $curlCafile;
    }

    // Probe known system paths
    foreach (CA_CERTIFICATE_PATHS as $path) {
        if (@is_file($path) && @is_readable($path)) {
            return $cached = $path;
        }
    }

    // No CA bundle found
    return $cached = null;
}

/**
 * Check if a system CA bundle is available for HTTPS connections.
 *
 * @return bool True if a CA bundle exists and can be used
 */
function orbitraHasSystemCaBundle(): bool
{
    return orbitraGetSystemCaBundle() !== null;
}

/**
 * Get a stream context configuration for HTTPS with proper CA certificate setup.
 *
 * Returns an array suitable for passing to stream_context_create() or as the
 * 'options' key in a context array. If no system CA bundle is found, returns
 * a configuration that will verify peer certificates but may fail if the
 * system has no usable certificates.
 *
 * @param array $extra Additional options to merge into the context
 * @return array Stream context options
 */
function orbitraGetSslContext(array $extra = []): array
{
    $caBundle = orbitraGetSystemCaBundle();

    $options = [
        'ssl' => [
            // Always verify certificates for security
            'verify_peer' => true,
            'verify_peer_name' => true,
            // Use system CA bundle if available
            'cafile' => $caBundle,
            // Fall back to CAPATH if no bundle exists (may work on some systems)
            'capath' => $caBundle ? null : '/etc/ssl/certs',
            // Timeout for TLS handshake
            'SNI_enabled' => true,
            'SNI_server_name' => $_SERVER['HTTP_HOST'] ?? null,
        ],
        'http' => [
            'timeout' => 30,
            'follow_location' => true,
            'max_redirects' => 5,
            'ignore_errors' => false,
        ],
    ];

    // Clean up null values
    $options['ssl'] = array_filter($options['ssl'], fn($v) => $v !== null);

    return array_merge_recursive($options, $extra);
}

/**
 * Create a stream context with proper SSL configuration.
 *
 * Shortcut for stream_context_create(orbitraGetSslContext($extra)).
 *
 * @param array $extra Additional options to merge
 * @return resource A stream context resource
 */
function orbitraCreateSslContext(array $extra = [])
{
    return stream_context_create(orbitraGetSslContext($extra));
}

/**
 * Test an HTTPS connection using the configured SSL settings.
 *
 * Makes a lightweight HEAD request to verify that outbound HTTPS
 * connections work with the current CA certificate configuration.
 *
 * @param string $url The URL to test (default: https://www.google.com/)
 * @return bool True if the connection succeeded, false otherwise
 */
function orbitraTestHttpsConnection(string $url = 'https://www.google.com/'): bool
{
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https') {
        return false;
    }

    $host = $parts['host'] ?? '';
    $port = $parts['port'] ?? 443;
    $timeout = 5;

    $context = orbitraCreateSslContext();

    $socket = @stream_socket_client(
        "ssl://{$host}:{$port}",
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($socket === false) {
        return false;
    }

    fclose($socket);
    return true;
}

/**
 * Get diagnostic information about SSL/TLS configuration.
 *
 * Useful for debugging connection issues and for admin panels.
 *
 * @return array Diagnostic information
 */
function orbitraGetSslDiagnostics(): array
{
    $diagnostics = [
        'has_ca_bundle' => orbitraHasSystemCaBundle(),
        'ca_bundle_path' => orbitraGetSystemCaBundle(),
        'openssl_loaded' => extension_loaded('openssl'),
        'openssl_version' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : null,
        'openssl_cafile_ini' => ini_get('openssl.cafile') ?: null,
        'curl_cafile_ini' => ini_get('curl.cainfo') ?: null,
        'https_test' => null,  // Will be populated if test is run
    ];

    // Test connection if requested
    if (function_exists('orbitraTestHttpsConnection')) {
        $diagnostics['https_test'] = orbitraTestHttpsConnection();
    }

    return $diagnostics;
}

/**
 * Configure PHP runtime with detected CA bundle if not already configured.
 *
 * This function can be called early in application bootstrap to ensure
 * openssl.cafile is set to a valid path if PHP is not already configured.
 *
 * Returns true if configuration was applied or was already sufficient.
 * Returns false if no valid CA bundle could be found.
 *
 * @return bool True if SSL can verify peer certificates
 */
function orbitraConfigureSslRuntime(): bool
{
    // Already configured?
    $configured = ini_get('openssl.cafile');
    if ($configured && is_file($configured) && is_readable($configured)) {
        return true;
    }

    $systemBundle = orbitraGetSystemCaBundle();
    if ($systemBundle) {
        return @ini_set('openssl.cafile', $systemBundle) !== false;
    }

    return false;
}
