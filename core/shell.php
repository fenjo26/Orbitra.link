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
