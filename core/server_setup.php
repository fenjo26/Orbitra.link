<?php
/**
 * Panel side of cli/server_setup.sh — "has this server had its root-side
 * setup, at the version this code expects?"
 *
 * The in-panel update runs as the web user: it can pull new code but can never
 * change sudoers or install root helpers. So after an update that needs a root
 * step, the panel detects what the server still lacks and shows the one command
 * to run (a curl from the release tag — never the web-user-owned copy in the
 * tree), until /etc/orbitra/server-setup-version reports the required version.
 */

require_once __DIR__ . '/shell.php';

/** Bump together with SERVER_SETUP_VERSION in cli/server_setup.sh. */
const ORBITRA_SERVER_SETUP_REQUIRED = 3;
const ORBITRA_SERVER_SETUP_MARKER = '/etc/orbitra/server-setup-version';

/**
 * @return array{
 *   state: string,            // ok | needed | not_applicable
 *   installed: int,           // version recorded by the script, 0 = never ran
 *   required: int,
 *   legacy_rules: bool,       // the old certbot / cp sudo rules are still live
 *   command: string,          // what the operator runs over SSH
 *   ref: string
 * }
 */
function orbitraServerSetupStatus(?string $version = null): array
{
    $version = $version ?? (defined('ORBITRA_VERSION') ? ORBITRA_VERSION : '');
    $ref = $version !== '' ? 'v' . ltrim($version, 'v') : 'main';

    $installed = 0;
    $raw = @file_get_contents(ORBITRA_SERVER_SETUP_MARKER);
    if (is_string($raw) && preg_match('/^\s*(\d+)/', $raw, $m)) {
        $installed = (int) $m[1];
    }

    // What the web user may run through sudo. `sudo -n -l` needs no password
    // when the user's rules are NOPASSWD (sudo's default listpw=any), which is
    // exactly the install.sh shape. Empty output = no Orbitra rules at all.
    $sudoList = '';
    if (orbitraShellAvailable()) {
        $sudoList = (string) orbitraShell('sudo -n -l 2>/dev/null');
    }
    $legacy = (bool) preg_match('#NOPASSWD:\s*/usr/bin/certbot\s*$#m', $sudoList)
        || strpos($sudoList, 'orbitra_nginx_update.conf') !== false;
    $managedByInstaller = $legacy
        || strpos($sudoList, 'orbitra-') !== false
        || strpos($sudoList, 'certbot') !== false
        || $installed > 0;

    if ($installed >= ORBITRA_SERVER_SETUP_REQUIRED && !$legacy) {
        $state = 'ok';
    } elseif ($managedByInstaller) {
        $state = 'needed';
    } else {
        // Docker, shared hosting, a hand-made server: no sudo rules of ours to
        // replace, nothing for the operator to run.
        $state = 'not_applicable';
    }

    // v3's real step is the cron package: without it (minimal images ship
    // without cron) every background worker is a line in a crontab nothing
    // executes, and the panel can only say "checkpoint has not run". When
    // the server reports that state, the banner is an alarm, not advice.
    // Everything else v3 adds (nginx limits, a re-run of the helpers) stays
    // a recommendation for servers that already ran v1.
    $cron = orbitraCronDiagnosis();
    $cronHealthy = null; // null = could not tell (no shell)
    if ($cron['crontab_installed'] === true) {
        $cronHealthy = $cron['spool_scheduled'] === true
            && ($cron['service_active'] === null || $cron['service_active'] === true);
    } elseif ($cron['crontab_installed'] === false) {
        $cronHealthy = false;
    }
    $severity = ($legacy || $cronHealthy === false)
        ? 'required'
        : (($installed >= 1 && !$legacy) ? 'recommended' : 'required');

    return [
        'state' => $state,
        'severity' => $severity,
        'installed' => $installed,
        'required' => ORBITRA_SERVER_SETUP_REQUIRED,
        'legacy_rules' => $legacy,
        'command' => orbitraServerSetupCommand($version),
        'ref' => $ref,
    ];
}

/**
 * The one command the operator runs over SSH to bring the root side up to
 * date. Kept out of orbitraServerSetupStatus() so a cheaper caller — the
 * system-status warning about unconfigured background tasks — can show the
 * exact same command without paying for the sudo probe.
 */
function orbitraServerSetupCommand(?string $version = null): string
{
    $version = $version ?? (defined('ORBITRA_VERSION') ? ORBITRA_VERSION : '');
    $ref = $version !== '' ? 'v' . ltrim($version, 'v') : 'main';
    $url = 'https://raw.githubusercontent.com/fenjo26/Orbitra.link/' . $ref . '/cli/server_setup.sh';
    // Download first, run second: a plain `curl | sudo bash` against a ref
    // that does not exist yet (a release candidate, for instance) pipes an
    // EMPTY script into bash — exit 0, zero output, nothing applied. With the
    // separate step curl fails loudly (exit 22, its own error line) and the
    // && never runs sudo. Acceptance part 1 hit exactly this on the stand.
    return 'curl -fsSL ' . $url . ' -o /tmp/orbitra-server-setup.sh && sudo ORBITRA_REF=' . $ref . ' bash /tmp/orbitra-server-setup.sh';
}

/**
 * What the server can tell about its own background scheduling, from the web
 * user's seat: is the crontab binary there (the cron package), is the spool
 * line in the web user's own crontab, and is the daemon actually up.
 * `systemctl is-active cron` is a read-only query, so it needs no sudo rule
 * — the existing helpers cover nothing of the kind, and this way the panel
 * shows the real state instead of inferring it from a missing heartbeat.
 *
 * null means "could not tell" (no shell, no systemd) and is never a verdict:
 * the spool line is only checked when the binary exists, the service state
 * only when systemd answered.
 */
function orbitraCronDiagnosis(): array
{
    $diag = ['crontab_installed' => null, 'spool_scheduled' => null, 'service_active' => null];
    if (!orbitraShellAvailable()) {
        return $diag;
    }
    $diag['crontab_installed'] = orbitraCommandExists('crontab');
    if ($diag['crontab_installed']) {
        $diag['spool_scheduled'] = strpos((string) orbitraShell('crontab -l 2>/dev/null'), '# orbitra-click-spool') !== false;
    }
    $active = trim((string) orbitraShell('systemctl is-active cron 2>/dev/null'));
    if ($active !== '') {
        $diag['service_active'] = $active === 'active';
    }
    return $diag;
}

/**
 * Schedule cli/click_spool_cron.php in the web user's own crontab when it is
 * missing. No root needed; servers installed before the spool existed never got
 * the line from install.sh, and without it spooled clicks would wait forever.
 * Idempotent (marker line) and silent when the crontab cannot be managed.
 */
function orbitraEnsureClickSpoolCron(): bool
{
    static $done = null;
    if ($done !== null) {
        return $done;
    }
    $marker = '# orbitra-click-spool';
    $root = dirname(__DIR__);
    $script = $root . '/cli/click_spool_cron.php';
    if (!is_file($script) || !orbitraShellAvailable() || !orbitraCommandExists('crontab')) {
        return $done = false;
    }
    $current = (string) orbitraShell('crontab -l 2>/dev/null');
    if (strpos($current, $marker) !== false) {
        return $done = true;
    }
    $logDir = $root . '/var/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $php = trim((string) orbitraShell('command -v php 2>/dev/null')) ?: 'php';
    $line = "* * * * * $php " . escapeshellarg($script) . ' --quiet >> '
        . escapeshellarg($logDir . '/click_spool.log') . " 2>&1 $marker";
    $updated = rtrim($current, "\n");
    $updated = ($updated === '' ? '' : $updated . "\n") . $line . "\n";
    $tmp = @tempnam(sys_get_temp_dir(), 'orbitra_cron_');
    if ($tmp === false || @file_put_contents($tmp, $updated) === false) {
        return $done = false;
    }
    orbitraShell('crontab ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return $done = strpos((string) orbitraShell('crontab -l 2>/dev/null'), $marker) !== false;
}

/**
 * Schedule cli/cleanup_cron.php in the web user's own crontab when missing.
 * The SystemSettings retention windows ("log retention", "archive retention")
 * are only enforced by that worker: installs made before it was shipped (and
 * installs whose install.sh predates the cron line) saved the fields while
 * nothing ever purged — the database grew forever. Same contract as the spool
 * cron: idempotent, marker-line, silent when the crontab cannot be managed.
 */
function orbitraEnsureCleanupCron(): bool
{
    static $done = null;
    if ($done !== null) {
        return $done;
    }
    $marker = '# orbitra-cleanup';
    $root = dirname(__DIR__);
    $script = $root . '/cli/cleanup_cron.php';
    if (!is_file($script) || !orbitraShellAvailable() || !orbitraCommandExists('crontab')) {
        return $done = false;
    }
    $current = (string) orbitraShell('crontab -l 2>/dev/null');
    if (strpos($current, $marker) !== false) {
        return $done = true;
    }
    $logDir = $root . '/var/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $php = trim((string) orbitraShell('command -v php 2>/dev/null')) ?: 'php';
    $line = "41 3 * * * $php " . escapeshellarg($script) . ' >> '
        . escapeshellarg($logDir . '/cleanup.log') . " 2>&1 $marker";
    $updated = rtrim($current, "\n");
    $updated = ($updated === '' ? '' : $updated . "\n") . $line . "\n";
    $tmp = @tempnam(sys_get_temp_dir(), 'orbitra_cron_');
    if ($tmp === false || @file_put_contents($tmp, $updated) === false) {
        return $done = false;
    }
    orbitraShell('crontab ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return $done = strpos((string) orbitraShell('crontab -l 2>/dev/null'), $marker) !== false;
}
