<?php
/**
 * Git worktree recovery helpers for the admin-panel updater.
 *
 * A failed merge or stash restore leaves entries in the index at stages 1/2/3.
 * Until they are cleared, every later `git pull` stops immediately with
 * "Pulling is not possible because you have unmerged files". Detect that state
 * directly instead of relying only on Git's (potentially localized) wording.
 */

function orbitraGitHasUnmergedFiles(string $repoDir): bool
{
    $git = 'git -C ' . escapeshellarg($repoDir);
    $output = [];
    $returnCode = 0;
    exec($git . ' diff --name-only --diff-filter=U 2>&1', $output, $returnCode);

    if ($returnCode !== 0) {
        return false;
    }

    foreach ($output as $line) {
        if (trim((string) $line) !== '') {
            return true;
        }
    }

    return false;
}

function orbitraGitOutputShowsConflict(array $output): bool
{
    $text = strtolower(implode("\n", $output));
    $needles = [
        'would be overwritten',
        'local changes',
        'overwritten by merge',
        'commit your changes or stash',
        'unmerged files',
        'unresolved conflict',
        'pulling is not possible',
        'needs merge',
        'you need to resolve your current index first',
    ];

    foreach ($needles as $needle) {
        if (strpos($text, $needle) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Abort any unfinished Git operation and return tracked files to a clean target.
 *
 * User data is not stored in tracked files: SQLite databases, uploaded landings,
 * GeoIP databases, caches and logs are gitignored. A failed `stash pop` keeps the
 * original stash entry, so resetting its half-applied conflict does not delete the
 * saved copy of local code changes.
 */
function orbitraGitRepairConflictState(string $repoDir, array &$diagnostics, string $target = 'HEAD'): bool
{
    $git = 'git -C ' . escapeshellarg($repoDir);
    $operations = ['merge', 'rebase', 'cherry-pick', 'revert'];

    foreach ($operations as $operation) {
        $abortOutput = [];
        $abortCode = 0;
        exec($git . ' ' . $operation . ' --abort 2>&1', $abortOutput, $abortCode);
        if ($abortCode === 0) {
            $diagnostics[] = '[Aborted unfinished git ' . $operation . ']';
        }
    }

    $resetOutput = [];
    $resetCode = 0;
    exec($git . ' reset --hard ' . escapeshellarg($target) . ' 2>&1', $resetOutput, $resetCode);
    $diagnostics = array_merge($diagnostics, $resetOutput);

    return $resetCode === 0 && !orbitraGitHasUnmergedFiles($repoDir);
}
