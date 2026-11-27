<?php
// Run: php tests/link_checks_cron_test.php
// How the link-checks worker gets into cron, proven against a stubbed `crontab`
// and never the developer's own: the installer's block, the catch-up setup
// script's block and the panel's self-heal all add the same line, once.
//
// The stub keeps every user's crontab in a scratch folder and is first on PATH
// for the whole run; the real crontab is compared before and after as a guard.

require_once __DIR__ . '/../core/shell.php';
require_once __DIR__ . '/../core/server_setup.php';

$failures = 0;
$checks = 0;
$assert = static function ($expected, $actual, string $label) use (&$failures, &$checks): void {
    $checks++;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$label}\n  expected: " . var_export($expected, true)
            . "\n  actual:   " . var_export($actual, true) . "\n");
    }
};

$repo = dirname(__DIR__);
$realCrontab = static function (): string {
    exec('/usr/bin/crontab -l 2>/dev/null', $out, $rc);
    return $rc === 0 ? implode("\n", $out) : '(none)';
};
$crontabBefore = $realCrontab();

$stubDir = sys_get_temp_dir() . '/orbitra_lc_cron_' . getmypid();
$tabs = $stubDir . '/tabs';
mkdir($tabs, 0700, true);
// Like the real crontab, the stub reads its input to the end before it
// installs anything: the installer pipes "the old crontab, then the new line"
// into it, and a stub that truncated the file on open would lose the old lines
// while the left side of the pipe is still reading them.
file_put_contents($stubDir . '/crontab', <<<'SH'
#!/bin/bash
user="$USER"; mode=""; file=""
while [ $# -gt 0 ]; do
    case "$1" in
        -u) user="$2"; shift 2 ;;
        -l) mode=list; shift ;;
        -) mode=stdin; shift ;;
        *) mode=file; file="$1"; shift ;;
    esac
done
target="$STUB_CRON_DIR/$user"
case "$mode" in
    list) if [ -f "$target" ]; then cat "$target"; else echo "no crontab for $user" >&2; exit 1; fi ;;
    stdin) buf="$(cat)"; printf '%s\n' "$buf" > "$target.new" && mv "$target.new" "$target" ;;
    file) cat "$file" > "$target" ;;
esac
SH
);
chmod($stubDir . '/crontab', 0755);
$tab = static function (string $user) use ($tabs): string {
    $path = "$tabs/$user";
    clearstatcache(true, $path);
    return is_file($path) ? (string) file_get_contents($path) : '';
};
$tabLines = static function (string $user) use ($tab): array {
    return array_values(array_filter(explode("\n", $tab($user)), static fn (string $l): bool => trim($l) !== ''));
};
$withStub = static function (callable $run) use ($stubDir, $tabs): mixed {
    $oldPath = getenv('PATH');
    putenv('PATH=' . $stubDir . ':' . $oldPath);
    putenv('STUB_CRON_DIR=' . $tabs);
    try {
        return $run();
    } finally {
        putenv('PATH=' . $oldPath);
        putenv('STUB_CRON_DIR');
    }
};

// --- 0. The stub is what runs under the name `crontab` -------------------------------
$withStub(static function () use ($assert, $stubDir, $tabs): void {
    exec('command -v crontab', $out);
    $assert($stubDir . '/crontab', $out[0] ?? '', 'the stub stands in for crontab');
    exec('printf "first\\nsecond\\n" | crontab - && crontab -l', $out2);
    $assert(['first', 'second'], $out2, 'the stub round-trips a crontab');
    @unlink($tabs . '/' . get_current_user());
});

// --- 1. install.sh: the block that schedules the worker -------------------------------
$install = (string) file_get_contents($repo . '/install.sh');
preg_match('/^LINK_CHECKS_CRON_MARKER=.*?^fi\n/ms', $install, $m);
$assert(true, isset($m[0]) && str_contains($m[0], 'link_checks_cron.php'), 'install.sh carries the scheduling block for the worker');
$installBlock = $m[0] ?? 'exit 9';
$installerLine = '* * * * * php /var/www/orbitra/link_checks_cron.php >> /var/www/orbitra/var/logs/link_checks.log 2>&1 # orbitra-link-checks';
$withStub(static function () use ($assert, $tabLines, $installBlock, $tabs, $stubDir, $installerLine): void {
    // A fresh install: no crontab for the web user yet.
    @unlink("$tabs/www-data");
    file_put_contents($stubDir . '/block.sh', "set -e\n" . $installBlock);
    exec('bash ' . escapeshellarg($stubDir . '/block.sh') . ' 2>&1', $out, $rc);
    $assert(0, $rc, 'the installer block runs on a fresh install: ' . implode(' | ', $out));
    $assert([$installerLine], $tabLines('www-data'), 'a fresh install gets the worker every minute, with its log and marker');
    $assert(true, in_array('  > Link checks worker scheduled (every minute; idle until switched on in the panel).', $out, true), 'and says so');

    // An existing server whose crontab already has other workers.
    file_put_contents("$tabs/www-data", "41 3 * * * php /var/www/orbitra/cli/cleanup_cron.php # orbitra-cleanup\n* * * * * php /var/www/orbitra/cli/click_spool_cron.php --quiet # orbitra-click-spool\n");
    exec('bash ' . escapeshellarg($stubDir . '/block.sh') . ' 2>&1', $out, $rc);
    $kept = [
        '41 3 * * * php /var/www/orbitra/cli/cleanup_cron.php # orbitra-cleanup',
        '* * * * * php /var/www/orbitra/cli/click_spool_cron.php --quiet # orbitra-click-spool',
        $installerLine,
    ];
    $assert(0, $rc, 'the installer block runs on an existing crontab');
    $assert($kept, $tabLines('www-data'), 'it appends the worker and keeps every line that was there');

    // A second run (the installer re-run after an update) must not duplicate it.
    exec('bash ' . escapeshellarg($stubDir . '/block.sh') . ' 2>&1', $again, $rc2);
    $assert([$rc, $kept], [$rc2, $tabLines('www-data')], 'a second run of the installer changes nothing');
    $assert(true, !in_array('  > Link checks worker scheduled (every minute; idle until switched on in the panel).', $again, true), 'and does not pretend to have scheduled it again');
});

// --- 2. cli/server_setup.sh: the catch-up block for existing servers -------------------
$setup = (string) file_get_contents($repo . '/cli/server_setup.sh');
preg_match('/^    # --- 5b\. link checks worker.*?(?=^    # --- 6\.)/ms', $setup, $m2);
$assert(true, isset($m2[0]) && str_contains($m2[0], 'link_checks_cron.php'), 'cli/server_setup.sh carries the catch-up block for the worker');
$setupBlock = preg_replace('/^    /m', '', rtrim($m2[0] ?? 'exit 9'));
$fakeDir = $stubDir . '/srv_tracker';
mkdir($fakeDir, 0700, true);
touch($fakeDir . '/link_checks_cron.php');
$catchUpLine = "* * * * * php {$fakeDir}/link_checks_cron.php >> {$fakeDir}/var/logs/link_checks.log 2>&1 # orbitra-link-checks";
$withStub(static function () use ($assert, $tabLines, $setupBlock, $tabs, $stubDir, $fakeDir, $catchUpLine): void {
    @unlink("$tabs/orbitra");
    // The block lives inside a function in the real script (it uses `local`).
    file_put_contents($stubDir . '/setup-block.sh', "set -e\norbitra_dir=" . escapeshellarg($fakeDir) . "\nweb_user=orbitra\nstep5b() {\n" . $setupBlock . "\n}\nstep5b\n");
    exec('bash ' . escapeshellarg($stubDir . '/setup-block.sh') . ' 2>&1', $out, $rc);
    $assert(0, $rc, 'the catch-up block runs: ' . implode(' | ', $out));
    $assert([$catchUpLine], $tabLines('orbitra'), 'the catch-up block names the real install folder and web user');
    $assert(true, in_array('  > Scheduling the link checks worker for orbitra', $out, true), 'and says so');
    exec('bash ' . escapeshellarg($stubDir . '/setup-block.sh') . ' 2>&1', $again, $rc2);
    $assert([$rc, [$catchUpLine]], [$rc2, $tabLines('orbitra')], 'a second run changes nothing');
    $assert(true, !in_array('  > Scheduling the link checks worker for orbitra', $again, true), 'and does not pretend to have scheduled it again');
});

// --- 3. The panel's self-heal: the moment the feature is switched on --------------------
// Each variant runs in a process of its own: the helper remembers its answer
// for the lifetime of the request, exactly as it does in the panel.
$probe = static function (array $env, string $tabs, string $label = '') use ($repo, $assert): array {
    $script = 'require ' . var_export($repo . '/core/server_setup.php', true) . ';'
        . 'echo json_encode(["ok" => orbitraEnsureLinkChecksCron(), "tab" => @file_get_contents(' . var_export($tabs . '/' . get_current_user(), true) . ')]);';
    $cmd = '';
    foreach ($env as $key => $value) {
        $cmd .= $value === null ? 'unset ' . $key . '; ' : $key . '=' . escapeshellarg($value) . ' ';
    }
    exec($cmd . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $out, $rc);
    $last = json_decode((string) end($out), true);
    $assert(0, $rc, 'self-heal probe runs' . ($label ? " ({$label})" : '') . ': ' . implode(' | ', $out));
    return is_array($last) ? $last : ['ok' => null, 'tab' => null];
};
$stubEnv = static function (array $extra = []) use ($stubDir, $tabs): array {
    return ['PATH' => $stubDir . ':' . getenv('PATH'), 'STUB_CRON_DIR' => $tabs] + $extra;
};
$php = trim((string) shell_exec('command -v php'));
$panelLine = '* * * * * ' . $php . ' ' . escapeshellarg($repo . '/link_checks_cron.php')
    . ' >> ' . escapeshellarg($repo . '/var/logs/link_checks.log') . ' 2>&1 # orbitra-link-checks';

@unlink($tabs . '/' . get_current_user());
$first = $probe($stubEnv(), $tabs);
$assert([true, $panelLine . "\n"], [$first['ok'], $first['tab']], 'self-heal schedules the worker into the web user\'s own crontab, pointing at this checkout');
$second = $probe($stubEnv(), $tabs);
$assert([true, $panelLine . "\n"], [$second['ok'], $second['tab']], 'a second call still reports success and adds no second line');

@unlink($tabs . '/' . get_current_user());
$optedOut = $probe($stubEnv(['ORBITRA_CRON_SELF_HEAL' => '0']), $tabs, 'opted out');
$assert([false, ''], [$optedOut['ok'], (string) $optedOut['tab']], 'with ORBITRA_CRON_SELF_HEAL=0 the self-heal steps aside and writes nothing');

$noCrontab = $probe(['PATH' => sys_get_temp_dir(), 'STUB_CRON_DIR' => $tabs, 'ORBITRA_CRON_SELF_HEAL' => null], $tabs, 'no crontab command');
$assert([false, ''], [$noCrontab['ok'], (string) $noCrontab['tab']], 'no crontab command: the self-heal answers false and writes nothing');

// --- 4. The worker itself is where every path points --------------------------------
$assert(true, is_file($repo . '/link_checks_cron.php'), 'link_checks_cron.php exists at the path every cron line names');
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($repo . '/link_checks_cron.php'), $lint, $rc);
$assert(0, $rc, 'and parses');
$assert(false, str_contains($realCrontab(), 'orbitra-link-checks'), 'the developer\'s real crontab was never touched by this test');

@unlink($fakeDir . '/link_checks_cron.php');
@rmdir($fakeDir . '/var/logs');
@rmdir($fakeDir . '/var');
@rmdir($fakeDir);
foreach (glob($tabs . '/*') ?: [] as $file) {
    @unlink($file);
}
foreach (glob($stubDir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($tabs);
@rmdir($stubDir);

echo "Link checks cron tests: {$checks} checks, {$failures} failures.\n";
exit($failures === 0 ? 0 : 1);
