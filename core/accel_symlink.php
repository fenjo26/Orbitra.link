<?php

/**
 * The self-pointing symlink the alias-free X-Accel location resolves
 * through: <app root>/_internal_assets -> .
 *
 * The generated nginx `location /_internal_assets/` uses
 * `root /var/www/orbitra`, so the URI /_internal_assets/<rel> must land on
 * /var/www/orbitra/<rel> — which this symlink provides without an alias
 * directive (ORB-016, October 2026 nginx advisory: alias was listed among
 * the affected directives). The symlink normally arrives with the git
 * checkout itself; orbitraEnsureAccelSymlink() covers installs that lost it
 * (a zip "extract" turns it into a plain file whose whole content is ".")
 * or filesystems where the checkout could not materialise it.
 *
 * Kept in a file of its own, loaded at file scope, because index.php needs
 * it on the landing-asset path and must not pull the whole nginx_config
 * builder into every click.
 */

defined('ORBITRA_ACCEL_SYMLINK') || define('ORBITRA_ACCEL_SYMLINK', dirname(__DIR__) . '/_internal_assets');

/**
 * Returns true when the link exists on return (created here or before).
 */
function orbitraEnsureAccelSymlink(): bool
{
    $link = ORBITRA_ACCEL_SYMLINK;

    if (is_link($link)) {
        return true;
    }

    // A GitHub zip archive stores symlinks as plain files holding the target
    // path as text. That exact artifact is replaced; anything else a human or
    // a tool put at this path (above all a real directory) is left untouched.
    if (is_file($link) && !is_dir($link)) {
        if (trim((string) @file_get_contents($link)) !== '.') {
            return false;
        }
        @unlink($link);
    } elseif (file_exists($link) || is_dir($link)) {
        return false;
    }

    // The relative target keeps working if the app directory is ever moved;
    // "." resolves against the symlink's own directory.
    if (@symlink('.', $link)) {
        return true;
    }

    // A concurrent request may have created it between the checks.
    return is_link($link);
}
