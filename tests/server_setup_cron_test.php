<?php
/**
 * The cron-package regression (field report 2026-11-21): a standard install on
 * a minimal image shipped without the cron package, `crontab: command not
 * found` turned every scheduling step into a NOTE nobody read, and NO
 * background task ever ran — spool, WAL checkpoints, postback queue, SSL,
 * geo, aggregator — while the panel could only say "the checkpoint has not
 * run for over 5 minutes".
 *
 * This test pins the contract that prevents a repeat, at three levels:
 *
 *   1. install.sh installs the cron package as its own step (fatal on
 *      failure), enables the service, and VERIFIES the web user's crontab
 *      reads back the spool line — an error, not a NOTE.
 *   2. cli/server_setup.sh (the catch-up path for existing servers, and the
 *      command the panel banner shows) does the same BEFORE any crontab is
 *      written, and refuses to write the version marker when scheduling
 *      fails, so the panel keeps asking.
 *   3. core/server_setup.php requires v3, and orbitraServerSetupCommand()
 *      builds the download-first command the banner and the system-status
 *      warning both show.
 *
 * Usage: php tests/server_setup_cron_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

$repoRoot = dirname(__DIR__);

// --- 1. install.sh -----------------------------------------------------------
$install = (string) file_get_contents($repoRoot . '/install.sh');
check(strpos($install, 'apt-get install -y cron') !== false,
    'install.sh installs the cron package');
check(preg_match('/ERROR: could not install the cron package/', $install) === 1,
    'install.sh fails loudly when the cron package cannot be installed');
check(strpos($install, 'systemctl enable --now cron') !== false,
    'install.sh enables and starts the cron service');
// The verification must run AFTER the scheduling blocks (the tg-funnel poller
// is the last one) and BEFORE the www-data handover.
$lastCronBlock = strpos($install, 'orbitra-tg-funnel');
$verifyPos = strpos($install, "crontab -u www-data -l 2>/dev/null | grep -qF \"click_spool_cron.php\"");
$handoverPos = strpos($install, 'Handing the installation over to www-data');
check($verifyPos !== false && $lastCronBlock !== false && $handoverPos !== false
    && $lastCronBlock < $verifyPos && $verifyPos < $handoverPos,
    'install.sh verifies the spool line after scheduling, before the handover');
// A failure of that verification must be an error, not a warning line.
$verifyChunk = $verifyPos !== false ? substr($install, $verifyPos - 600, 1200) : '';
check(strpos($verifyChunk, 'exit 1') !== false,
    'a missing spool line aborts install.sh with an error');

// --- 2. cli/server_setup.sh --------------------------------------------------
$setup = (string) file_get_contents($repoRoot . '/cli/server_setup.sh');
check(preg_match('/^SERVER_SETUP_VERSION=3$/m', $setup) === 1,
    'cli/server_setup.sh is at SERVER_SETUP_VERSION=3');
check(strpos($setup, 'apt-get install -y cron') !== false,
    'server_setup.sh installs the cron package');
check(strpos($setup, 'systemctl enable --now cron') !== false,
    'server_setup.sh enables and starts the cron service');
// The cron step must precede the click-spool scheduling section. The anchor
// is the code, not the header comment: "click-spool worker" also appears in
// the What-it-does list at the top of the file.
$cronStepPos = strpos($setup, 'Installing the cron package');
$spoolStepPos = strpos($setup, 'local marker="# orbitra-click-spool"');
check($cronStepPos !== false && $spoolStepPos !== false && $cronStepPos < $spoolStepPos,
    'the cron package is ensured before the spool line is written');
// The scheduling section must read the line back and refuse to write the
// version marker otherwise (exit before step 7's marker write).
$spoolChunk = $spoolStepPos !== false ? substr($setup, $spoolStepPos, 1800) : '';
check(strpos($spoolChunk, 'grep -qF "click_spool_cron.php"') !== false,
    'server_setup.sh verifies the spool line reads back');
check(strpos($spoolChunk, 'exit 1') !== false,
    'a failed verification aborts the setup');
// The marker write itself, not its mention in the header comment.
$markerPos = strpos($setup, 'echo "$SERVER_SETUP_VERSION" > /etc/orbitra/server-setup-version');
check($markerPos !== false && $spoolStepPos < $markerPos,
    'the version marker is written after the verification');

// --- 3. core/server_setup.php ------------------------------------------------
require_once $repoRoot . '/core/shell.php';
require_once $repoRoot . '/core/server_setup.php';

check(ORBITRA_SERVER_SETUP_REQUIRED === 3,
    'the panel requires server setup v3');
// An install marked v2 must read as "needed": that is what makes the banner
// (and its command) appear on existing servers without cron — the update
// itself can never install the package, only the root-side command can.
$status = orbitraServerSetupStatus('9.9.9');
check($status['required'] === 3 && isset($status['state'], $status['severity'], $status['command'], $status['ref']),
    'orbitraServerSetupStatus() returns the full banner payload');

$command = orbitraServerSetupCommand('9.9.9');
check(strpos($command, 'https://raw.githubusercontent.com/fenjo26/Orbitra.link/v9.9.9/cli/server_setup.sh') !== false,
    'the command fetches server_setup.sh from the release tag');
check(strpos($command, 'curl -fsSL') === 0 && strpos($command, ' && sudo ORBITRA_REF=v9.9.9 bash ') !== false,
    'the command downloads first and runs second (no empty curl|bash)');
check(orbitraServerSetupCommand('v9.9.9') === $command,
    'a v-prefixed version maps to the same ref');
check(strpos(orbitraServerSetupCommand(null) !== '' ? orbitraServerSetupCommand() : '', 'cli/server_setup.sh') !== false,
    'the command builds without an explicit version');

// orbitraCronDiagnosis(): shape and null-vs-bool contract.
$diag = orbitraCronDiagnosis();
check(is_array($diag) && array_keys($diag) === ['crontab_installed', 'spool_scheduled', 'service_active'],
    'orbitraCronDiagnosis() returns the three fields');
foreach ($diag as $k => $v) {
    check($v === null || is_bool($v), "cron diagnosis field '$k' is null or bool");
}
if ($diag['crontab_installed'] === true) {
    check(is_bool($diag['spool_scheduled']),
        'spool_scheduled is a verdict when crontab exists');
} else {
    check($diag['spool_scheduled'] === null,
        'spool_scheduled stays null when crontab is missing/unavailable');
}

// cli/os_matrix.sh gained a per-release cron check.
$matrix = (string) file_get_contents($repoRoot . '/cli/os_matrix.sh');
check(strpos($matrix, 'cron_pkg') !== false,
    'os_matrix.sh checks the cron package per release');

// --- 4. the banner verdict ----------------------------------------------------
// Point 4 of the field report: an existing install without cron cannot be
// fixed by the panel update, so the banner must come back while background
// tasks are broken — including on a server whose marker already says v3
// (cron died LATER). And it must NOT flash on a healthy fresh install
// during the minute before the first cron tick, nor nag on shared hosting.
$v = static fn(int $installed, bool $legacy, bool $managed, ?bool $healthy, bool $never, int $age)
    => orbitraServerSetupVerdict($installed, $legacy, $managed, $healthy, $never, $age);

$r = $v(3, false, true, true, false, 999999);
check($r['state'] === 'ok', 'healthy v3 server: ok');
// A pulse that lags a minute after a setup run is the grace case below. The
// same "never" on an ANCIENT marker is not: the worker refreshes the
// heartbeat on every tick (even a zero-budget one), so a healthy cron that
// has allegedly run for months without ever writing it means the worker
// itself is failing — banner territory, same as the 3600 s case.
$r = $v(3, false, true, true, true, 60);
check($r['state'] === 'ok', 'fresh setup (marker 60 s old), no pulse yet: ok — the grace window holds');
$r = $v(3, false, true, true, true, 3600);
check($r['state'] === 'needed' && $r['severity'] === 'required' && $r['reason'] === 'background',
    'marker says v3, cron looks configured, but no heartbeat an hour later: banner returns, reason=background');
$r = $v(3, false, true, false, true, 60);
check($r['state'] === 'needed' && $r['severity'] === 'required' && $r['reason'] === 'background',
    'cron package gone/service down on a v3 server: banner returns immediately (no grace)');
$r = $v(3, false, true, false, false, 60);
check($r['state'] === 'needed' && $r['reason'] === 'background',
    'cron broken but heartbeat exists (stale): banner returns too');
$r = $v(3, true, true, true, false, 999999);
check($r['state'] === 'needed' && $r['reason'] === 'legacy', 'legacy sudo rules: needed regardless of version');
$r = $v(2, false, true, true, false, 999999);
check($r['state'] === 'needed' && $r['severity'] === 'recommended' && $r['reason'] === 'version',
    'v2 server with healthy cron: needed, but the soft wording (v3 would be a no-op there)');
$r = $v(0, false, false, null, true, 0);
check($r['state'] === 'not_applicable', 'Docker/shared hosting: not_applicable even without cron');
$r = $v(0, false, true, null, true, 0);
check($r['state'] === 'needed' && $r['severity'] === 'required', 'managed server, setup never ran: required');

// The status payload carries the reason the banner text switches on.
check(isset($status['reason']) && is_string($status['reason']),
    'orbitraServerSetupStatus() exposes a reason field');

echo $failures === 0 ? "\nServer-setup cron tests passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
