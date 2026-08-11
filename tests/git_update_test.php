<?php

require_once __DIR__ . '/../core/git_update.php';
require_once __DIR__ . '/../core/shell.php';

$root = sys_get_temp_dir() . '/orbitra-git-update-' . bin2hex(random_bytes(6));
$remote = $root . '/remote.git';
$seed = $root . '/seed';
$deploy = $root . '/deploy';
$failures = [];

$run = static function (string $command, ?int $expectedCode = 0) use (&$failures): array {
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    if ($expectedCode !== null && $code !== $expectedCode) {
        $failures[] = "Command failed ($code): $command\n" . implode("\n", $output);
    }
    return [$code, $output];
};

$git = static function (string $repo, string $arguments): string {
    return 'git -C ' . escapeshellarg($repo) . ' ' . $arguments;
};

try {
    if (!mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create temporary test directory');
    }

    $run('git init --bare --initial-branch=main ' . escapeshellarg($remote));
    $run('git init --initial-branch=main ' . escapeshellarg($seed));
    $run($git($seed, 'config user.email updater-test@example.invalid'));
    $run($git($seed, 'config user.name OrbitraUpdaterTest'));
    file_put_contents($seed . '/app.txt', "base\n");
    $run($git($seed, 'add app.txt'));
    $run($git($seed, 'commit -m initial'));
    $run($git($seed, 'remote add origin ' . escapeshellarg($remote)));
    $run($git($seed, 'push -u origin main'));

    $run('git clone ' . escapeshellarg($remote) . ' ' . escapeshellarg($deploy));
    $run($git($deploy, 'config user.email updater-test@example.invalid'));
    $run($git($deploy, 'config user.name OrbitraUpdaterTest'));

    file_put_contents($seed . '/app.txt', "remote update\n");
    $run($git($seed, 'add app.txt'));
    $run($git($seed, 'commit -m remote-update'));
    $run($git($seed, 'push origin main'));

    // Reproduce the production failure: the update pulls successfully, then a
    // saved local edit conflicts during `stash pop` and leaves an unmerged index.
    file_put_contents($deploy . '/app.txt', "local edit\n");
    $run($git($deploy, 'stash push -u -m orbitra-auto-update'));
    $run($git($deploy, 'pull --ff-only origin main'));
    [$popCode] = $run($git($deploy, 'stash pop'), null);
    if ($popCode === 0 || !orbitraGitHasUnmergedFiles($deploy)) {
        $failures[] = 'Fixture did not produce the expected unmerged stash conflict';
    }

    $diagnostics = [];
    if (!orbitraGitRepairConflictState($deploy, $diagnostics)) {
        $failures[] = 'Conflict repair helper did not restore a clean index';
    }
    if (orbitraGitHasUnmergedFiles($deploy)) {
        $failures[] = 'Unmerged files remain after repair';
    }
    if (file_get_contents($deploy . '/app.txt') !== "remote update\n") {
        $failures[] = 'Repair did not restore the deployed HEAD version';
    }

    [, $stashList] = $run($git($deploy, 'stash list'));
    if (strpos(implode("\n", $stashList), 'orbitra-auto-update') === false) {
        $failures[] = 'Failed stash restore did not retain the saved local changes';
    }

    // The next panel update must work without any manual git intervention.
    file_put_contents($seed . '/app.txt', "next remote update\n");
    $run($git($seed, 'add app.txt'));
    $run($git($seed, 'commit -m next-update'));
    $run($git($seed, 'push origin main'));
    $run($git($deploy, 'pull --ff-only origin main'));
    if (file_get_contents($deploy . '/app.txt') !== "next remote update\n") {
        $failures[] = 'A later pull still failed after conflict repair';
    }

    $sampleError = [
        'error: Pulling is not possible because you have unmerged files.',
        'fatal: Exiting because of an unresolved conflict.',
    ];
    if (!orbitraGitOutputShowsConflict($sampleError)) {
        $failures[] = 'The reported production error was not recognized as a conflict';
    }
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
} finally {
    if (is_dir($root)) {
        orbitraRemoveDirectory($root);
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Git updater conflict recovery tests passed.\n";
