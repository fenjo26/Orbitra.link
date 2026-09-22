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
const ORBITRA_SERVER_SETUP_REQUIRED = 1;
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

    $url = 'https://raw.githubusercontent.com/fenjo26/Orbitra.link/' . $ref . '/cli/server_setup.sh';
    return [
        'state' => $state,
        'installed' => $installed,
        'required' => ORBITRA_SERVER_SETUP_REQUIRED,
        'legacy_rules' => $legacy,
        'command' => 'curl -fsSL ' . $url . ' | sudo ORBITRA_REF=' . $ref . ' bash',
        'ref' => $ref,
    ];
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
