<?php
/**
 * Running shell commands without assuming the shell is there.
 *
 * `shell_exec` is not guaranteed to exist. Shared hosting removes it outright,
 * and `disable_functions` is common on managed panels — and on PHP 8 calling a
 * function that has been removed raises an `Error` that the `@` operator does
 * not suppress. Scattered `@shell_exec(...)` calls therefore do not degrade;
 * they kill the request with a 500, which the panel can only report as a generic
 * failure. That has already cost this project three separate bugs, so every
 * shell call goes through here.
 *
 * orbitraShellAvailable() answers the question directly, so a feature that needs
 * a shell can say so plainly instead of failing halfway through.
 */

/**
 * Is running an external command possible at all in this process?
 */
function orbitraShellAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    if (!function_exists('shell_exec')) {
        return $available = false;
    }

    $disabled = array_filter(preg_split('/[\s,]+/', (string) ini_get('disable_functions')));
    foreach ($disabled as $fn) {
        if (strcasecmp(trim($fn), 'shell_exec') === 0) {
            return $available = false;
        }
    }

    return $available = true;
}

/**
 * Run a command, or return null when there is no shell to run it in.
 *
 * Null and empty string mean different things and callers rely on it: null is
 * "could not run", '' is "ran and said nothing".
 */
function orbitraShell(string $command): ?string
{
    if (!orbitraShellAvailable()) {
        return null;
    }

    $output = @shell_exec($command);

    return $output === null ? null : (string) $output;
}

/**
 * Is this command on PATH? False when the shell itself is unavailable.
 */
function orbitraCommandExists(string $command): bool
{
    $safe = escapeshellarg($command);
    $out = orbitraShell("command -v $safe 2>/dev/null");

    return is_string($out) && trim($out) !== '';
}

/**
 * Read a file the web user may not be able to open directly.
 *
 * certbot writes /etc/letsencrypt as root, and on many hosts those directories
 * stay root-only, so a plain file_get_contents() from PHP-FPM returns false
 * for a perfectly healthy certificate. This tries the direct read first and
 * falls back to `sudo -n cat`, which install.sh's sudoers file allows for the
 * PUBLIC chain files only — never privkey.pem.
 *
 * @return string|null null means "could not read this file by any route";
 *                     only a genuinely readable file returns its contents.
 */
function orbitraReadPrivilegedFile(string $path): ?string
{
    $raw = @file_get_contents($path);
    if (is_string($raw) && $raw !== '') {
        return $raw;
    }
    if (!orbitraShellAvailable() || !orbitraCommandExists('sudo')) {
        return null;
    }
    // Without the matching sudoers rule sudo -n fails quietly, and the caller
    // sees "unreadable" — which is the honest verdict for a panel that cannot
    // read the file, not a fabricated "certificate is broken".
    //
    // The helper first. Its sudoers rule carries no argument pattern, which is
    // what makes it work everywhere: the older rules spelled the path as
    // /etc/letsencrypt/live/*/fullchain.pem, and sudo-rs — the default sudo on
    // Ubuntu 25.10 and newer — rejects a wildcard in a command argument, so on
    // those hosts every one of those rules was dropped as a parse error.
    $out = orbitraShell('sudo -n /usr/local/bin/orbitra-catcert ' . escapeshellarg($path) . ' 2>/dev/null');
    if (is_string($out) && $out !== '') {
        return $out;
    }
    // Installs made before the helper existed still have the cat rules, and on
    // a classic sudo they work. Spelled with a full path on purpose: sudo
    // resolves a bare name through secure_path (/usr/bin first on usrmerged
    // systems) while the old sudoers entry names /bin/cat — naming the full
    // path on both sides removes the dependency on how a given sudo resolves.
    $out = orbitraShell('sudo -n /bin/cat ' . escapeshellarg($path) . ' 2>/dev/null');
    return is_string($out) && $out !== '' ? $out : null;
}

/**
 * Delete a directory tree without shelling out to `rm -rf`.
 *
 * PHP can do this itself, and doing it in PHP removes one more dependency on a
 * shell that may not exist. Contained by design: it refuses anything that is not
 * a real directory, and follows no symlinks out of the tree.
 */
function orbitraRemoveDirectory(string $dir): bool
{
    $real = realpath($dir);
    if ($real === false || !is_dir($real) || $real === '/' || strlen($real) < 4) {
        return false;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    return @rmdir($real);
}
