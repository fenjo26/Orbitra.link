<?php

/**
 * PHP interpreter security status ("php2shell" advisory, October 2026).
 *
 * CVE-2026-109618 / CVE-2026-109619 are unauthenticated RCE bugs in PHP's
 * request initialisation, disclosed 2026-10-21 with a public exploit. Fixed
 * upstream in 8.5.12 / 8.4.27 / 8.3.36 / 8.2.35; branches at 8.1 and below
 * were already end-of-life and will never receive the fix.
 *
 * The level is derived from PHP_VERSION_ID alone. Distributions backport
 * security fixes into older version numbers (Debian's php8.2 keeps a lower
 * version string with the patch inside), which this check cannot see — the
 * advisory text built on top (the updater's completion report) words itself
 * accordingly and points at the changelog check. The October 2026 dashboard
 * banners around this advisory were retired after the patch wave: installs
 * from install.sh ship a current interpreter, and distro backports made the
 * version-only verdict cry wolf at freshly patched servers.
 *
 * @param int|null $versionId The PHP_VERSION_ID to judge; null judges the
 *                            running interpreter (tests inject other builds).
 * @return array{advisory:string, level:string, php_version:string, php_version_id:int, fixed_in:?string, cves:string[]}
 */
function orbitraPhpSecurityStatus(?int $versionId = null): array
{
    $versionId = $versionId ?? PHP_VERSION_ID;

    // First fixed release of every branch that received the patch.
    $fixed = [
        805 => [80512, '8.5.12'],
        804 => [80427, '8.4.27'],
        803 => [80336, '8.3.36'],
        802 => [80235, '8.2.35'],
    ];

    $branch = intdiv($versionId, 100);

    $level = 'ok';
    $fixedIn = null;
    if ($branch < 802) {
        // 8.1 and older were EOL before the advisory — no fix is coming.
        $level = 'eol';
    } elseif (isset($fixed[$branch]) && $versionId < $fixed[$branch][0]) {
        $level = 'vulnerable';
        $fixedIn = $fixed[$branch][1];
    }
    // Branches above 8.5 (8.6+, 9.x) postdate the advisory: treated as ok.

    return [
        'advisory' => 'php2shell-2026-10',
        'level' => $level,
        'php_version' => $versionId === PHP_VERSION_ID ? PHP_VERSION
            : sprintf('%d.%d.%d', intdiv($versionId, 10000), intdiv($versionId, 100) % 100, $versionId % 100),
        'php_version_id' => $versionId,
        'fixed_in' => $fixedIn,
        'cves' => ['CVE-2026-109618', 'CVE-2026-109619'],
    ];
}
