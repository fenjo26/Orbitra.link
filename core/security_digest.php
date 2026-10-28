<?php

require_once __DIR__ . '/shell.php';

/**
 * System-package security status ("October 2026 digest").
 *
 * The tracker's own stack (PHP code, SQLite, the generated nginx vhost) is
 * only half of an install: the nginx PACKAGE, the kernel and the CA trust
 * store belong to the operating system, and the October 2026 advisories —
 * nginx alias/rewrite/map flaws rated up to CVSS 9.2 among them — live in
 * those packages. The panel cannot apt-get for the operator, so this reports
 * what it can see (the running nginx version) and the banner built on top
 * hands over the rest as a checklist.
 *
 * The level is derived from `nginx -v` when a shell is available and nginx is
 * on PATH. Distributions backport security fixes into older version numbers,
 * which a version check cannot see — that is why the banner is dismissible,
 * not permanent (the same philosophy as core/php_security.php). No CVE ids
 * are quoted because the vendor advisories behind this digest did not
 * publish them in the digest itself.
 *
 * @param string|null $nginxVersion Parsed "1.24.0"-style version; null probes
 *                                  the running host (tests inject versions).
 * @return array{advisory:string, level:string, nginx_version:?string, fixed_in:?string}
 */
function orbitraSecurityDigestStatus(?string $nginxVersion = null): array
{
    $level = 'unknown';
    $version = $nginxVersion;
    $fixedIn = null;

    if ($version === null && orbitraShellAvailable()) {
        // `nginx -v` writes to stderr, hence the redirection; a missing binary
        // yields unparseable output and stays "unknown" rather than "ok".
        $raw = (string) orbitraShell('nginx -v 2>&1');
        if (preg_match('#nginx/([0-9]+\.[0-9]+\.[0-9]+)#', $raw, $m) === 1) {
            $version = $m[1];
        }
    }

    if ($version !== null && preg_match('#^([0-9]+)\.([0-9]+)\.([0-9]+)$#', $version, $m) === 1) {
        [$maj, $min] = [(int) $m[1], (int) $m[2]];
        // First fixed releases: mainline 1.31.7, stable 1.30.6. Everything
        // older (1.30.5 and below, which covers 1.22–1.29 distro builds) is
        // affected and updates to the stable fix; 1.32+ postdates the advisory.
        if ($maj > 1 || ($maj === 1 && $min >= 32)) {
            $level = 'ok';
        } else {
            $fixedIn = ($min === 31) ? '1.31.7' : '1.30.6';
            // Same convention as php_security: fixed_in is only set while the
            // level still says affected, so the banner never shows a target
            // version the server already runs.
            $level = version_compare($version, $fixedIn, '>=') ? 'ok' : 'affected';
            if ($level === 'ok') {
                $fixedIn = null;
            }
        }
    }

    return [
        'advisory' => 'syspkgs-2026-10',
        'level' => $level, // ok | affected | unknown
        'nginx_version' => $version,
        'fixed_in' => $fixedIn,
    ];
}
