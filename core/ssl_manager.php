<?php
/**
 * Getting and keeping a certificate for every parked domain.
 *
 * What this replaces: certificate issuance used to be a single background shot
 * fired the moment someone saved a domain with HTTPS-only ticked. Nothing ran it
 * again. If DNS had not propagated in that one second — the normal case, minutes
 * after pointing an A record — Certbot failed, the domain was marked failed, and
 * it stayed that way until a human reopened and re-saved it. A tracker whose
 * domains silently sit on a broken certificate cannot be sent traffic, which is
 * the whole point of parking them.
 *
 * The flow here is the one operators expect from Keitaro:
 *
 *   1. A parked domain wants a certificate. Not conditional on HTTPS-only —
 *      parking the domain is the request.
 *   2. Before Certbot runs, the domain's A record is compared with this server's
 *      address. A domain pointing elsewhere is left in waiting_dns rather than
 *      spending an attempt: Let's Encrypt allows five failures per hostname per
 *      hour, and burning them on a domain that cannot possibly validate is how
 *      an account ends up rate-limited exactly when the DNS finally lands.
 *   3. A failed attempt is scheduled again, with a widening gap, instead of
 *      being final.
 *   4. A certificate that exists but that nginx is not pointed at triggers a
 *      config rebuild — having the file is not the same as serving it.
 */

require_once __DIR__ . '/nginx_config.php';
require_once __DIR__ . '/shell.php';

/**
 * Make sure the certificate worker is scheduled, without needing a reinstall.
 *
 * A retry loop is only a retry loop if something runs it. `install.sh` writes
 * this line on a fresh install, but every tracker installed before this existed
 * would otherwise keep the old behaviour — one attempt, then silence — until its
 * owner reinstalled, which is not a reasonable thing to ask for a cron line.
 *
 * Hourly, at a minute that is not zero: a domain gets its certificate the moment
 * it is added, so the schedule only exists to catch what could not be issued then
 * — DNS that had not propagated, mostly. Running it more often would buy minutes
 * at the cost of pushing against Let's Encrypt's rate limits, which is a bad
 * trade when the failure mode is a week-long lockout on that domain.
 *
 * Called on domain save. Best-effort by design: it checks for its own marker
 * first, so it is idempotent, and every failure path leaves the crontab alone.
 * Returns true when the entry is in place.
 */
function orbitraEnsureSslCron(): bool
{
    static $done = null;
    if ($done !== null) {
        return $done;
    }

    $marker = '# orbitra-ssl-renew';
    $script = dirname(__DIR__) . '/cli/ssl_installer.php';
    $log = dirname(__DIR__) . '/var/logs/ssl_installer.log';

    if (!is_file($script) || !orbitraShellAvailable() || !orbitraCommandExists('crontab')) {
        return $done = false;
    }

    $current = (string) orbitraShell('crontab -l 2>/dev/null');
    if (strpos($current, $marker) !== false) {
        return $done = true;
    }

    $php = trim((string) orbitraShell('command -v php 2>/dev/null')) ?: 'php';
    $line = "17 * * * * $php " . escapeshellarg($script) . ' >> ' . escapeshellarg($log) . " 2>&1 $marker";

    $updated = rtrim($current, "\n");
    $updated = ($updated === '' ? '' : $updated . "\n") . $line . "\n";

    $tmp = sys_get_temp_dir() . '/orbitra_cron_' . getmypid();
    if (@file_put_contents($tmp, $updated) === false) {
        return $done = false;
    }
    orbitraShell('crontab ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);

    return $done = strpos((string) orbitraShell('crontab -l 2>/dev/null'), $marker) !== false;
}

/**
 * Can this server issue certificates at all, and if not, why not?
 *
 * Answered in one place so the panel's banner, the "issue now" button and the
 * worker cannot disagree with each other. Everything here is about the server,
 * not about any particular domain: a host that cannot run external commands will
 * never issue anything, and an operator staring at "waiting for certificate"
 * deserves to be told that rather than left to guess.
 *
 * @return array{can_issue: bool, shell: bool, certbot: bool, nginx_config: bool, acme_writable: bool, problems: string[]}
 */
function orbitraSslEnvironment(): array
{
    $shell = orbitraShellAvailable();
    $certbot = $shell && orbitraCommandExists('certbot');
    $nginxConfig = file_exists(ORBITRA_NGINX_CONFIG_PATH);
    $acmeWritable = is_dir(ORBITRA_ACME_WEBROOT) && is_writable(ORBITRA_ACME_WEBROOT);

    $problems = [];
    if (!$shell) {
        $problems[] = 'php_no_shell';
    } elseif (!$certbot) {
        $problems[] = 'no_certbot';
    }
    if (!$nginxConfig) {
        $problems[] = 'no_nginx_config';
    }
    if (!$acmeWritable) {
        $problems[] = 'acme_not_writable';
    }

    return [
        // Issuing needs a shell and Certbot; wiring the result into the web
        // server needs the nginx config. Without the first two nothing can be
        // obtained at all, which is the distinction the panel shows.
        'can_issue' => $shell && $certbot,
        'shell' => $shell,
        'certbot' => $certbot,
        'nginx_config' => $nginxConfig,
        'acme_writable' => $acmeWritable,
        'problems' => $problems,
    ];
}

/**
 * Does a fullchain.pem actually carry the full chain?
 *
 * Let's Encrypt writes leaf + intermediate into fullchain.pem, so a healthy file
 * has at least two `BEGIN CERTIFICATE` blocks. A file with only one — just the
 * leaf — is what produces the single most confusing TLS support ticket: Firefox
 * accepts the site because it fills in the missing intermediate from its own
 * store, while Chrome and curl fail with "unable to get local issuer
 * certificate". That happens when an old config pointed at cert.pem, when a
 * manual edit truncated the file, or when a certbot version/plugin wrote leaf
 * only. Counting the blocks is cheaper and more reliable than parsing with
 * openssl (which may not be on PATH here), and two is the floor: one issuer the
 * browser already trusts is all a chain needs to close.
 *
 * @return array{ok: bool, count: int}
 */
function orbitraCertificateChainComplete(string $certFile): array
{
    if (!is_file($certFile)) {
        return ['ok' => false, 'count' => 0];
    }
    $count = substr_count((string) @file_get_contents($certFile), '-----BEGIN CERTIFICATE-----');
    return ['ok' => $count >= 2, 'count' => $count];
}

/**
 * How long to wait before retrying, by attempt count.
 *
 * Deliberately widening, and measured in hours because the worker runs hourly.
 * The first failures are usually DNS still propagating and worth another go
 * soon; a domain that has failed repeatedly has a real problem and must not keep
 * asking Let's Encrypt about it, which is how an account gets rate-limited.
 * Note that a domain not pointing here never reaches Certbot at all, so waiting
 * on DNS costs nothing against the limits.
 */
function orbitraSslRetryDelay(int $attempts): int
{
    $ladder = [0, 3600, 3600, 7200, 21600, 43200];
    if ($attempts < count($ladder)) {
        return $ladder[$attempts];
    }
    return 43200; // 12 hours from here on
}

/**
 * This server's public address, as a domain's A record should point at it.
 *
 * Runs from cron as often as from a web request, so $_SERVER is not available;
 * the result is cached because the external lookup is the slow path.
 */
function orbitraServerIp(): string
{
    static $ip = null;
    if ($ip !== null) {
        return $ip;
    }

    $cacheFile = dirname(__DIR__) . '/var/server_ip_cache.txt';
    if (is_file($cacheFile) && (time() - (int) @filemtime($cacheFile)) < 86400) {
        $cached = trim((string) @file_get_contents($cacheFile));
        if (filter_var($cached, FILTER_VALIDATE_IP)) {
            return $ip = $cached;
        }
    }

    $found = '';

    // The address the machine actually egresses from, without any network call.
    $sock = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
    if ($sock) {
        $local = @stream_socket_get_name($sock, false);
        @fclose($sock);
        if (is_string($local) && strpos($local, ':') !== false) {
            $candidate = substr($local, 0, strrpos($local, ':'));
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
                $found = $candidate;
            }
        }
    }

    if ($found === '') {
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        foreach (['https://api.ipify.org', 'http://checkip.amazonaws.com'] as $url) {
            $body = @file_get_contents($url, false, $ctx);
            if (is_string($body) && filter_var(trim($body), FILTER_VALIDATE_IP)) {
                $found = trim($body);
                break;
            }
        }
    }

    if ($found !== '') {
        @mkdir(dirname($cacheFile), 0775, true);
        @file_put_contents($cacheFile, $found);
    }

    return $ip = $found;
}

/**
 * Does this domain resolve to this server?
 *
 * @return array{ok: bool, ips: string[], server_ip: string}
 *   ok is true when any A/AAAA record matches. An unresolvable domain is not ok
 *   but is also not an error worth reporting as a failure — it is DNS that has
 *   not landed yet, which is a wait, not a fault.
 */
function orbitraDomainPointsHere(string $domain): array
{
    $serverIp = orbitraServerIp();
    $ips = [];

    foreach ([DNS_A, DNS_AAAA] as $type) {
        $records = @dns_get_record($domain, $type);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                } elseif (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
    }

    // gethostbyname as a fallback: some resolvers answer it when dns_get_record
    // comes back empty.
    if (!$ips) {
        $resolved = @gethostbyname($domain);
        if (is_string($resolved) && $resolved !== $domain && filter_var($resolved, FILTER_VALIDATE_IP)) {
            $ips[] = $resolved;
        }
    }

    $ips = array_values(array_unique($ips));

    return [
        'ok' => $serverIp !== '' && in_array($serverIp, $ips, true),
        'ips' => $ips,
        'server_ip' => $serverIp,
    ];
}

/**
 * Work the certificate queue once. Safe to call from cron every few minutes.
 *
 * @return array{checked: int, issued: int, waiting: int, failed: int, synced: bool}
 */
function orbitraProcessSslQueue(PDO $pdo, int $limit = 5): array
{
    $result = ['checked' => 0, 'issued' => 0, 'waiting' => 0, 'failed' => 0, 'synced' => false, 'can_issue' => false];

    try {
        // Every parked domain, not only the HTTPS-only ones: parking a domain is
        // the request for a certificate, exactly as it is in Keitaro. Domains
        // already serving a certificate are filtered out below rather than in
        // SQL, because "installed" in the database has been wrong before.
        $rows = $pdo->query("SELECT id, name, ssl_status, ssl_attempts, ssl_last_attempt FROM domains WHERE name IS NOT NULL AND name != '' ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return $result;
    }

    // Without a shell there is no Certbot, so the whole loop below would only
    // mark domains as failed for a reason that has nothing to do with them.
    $canIssue = orbitraShellAvailable() && orbitraCommandExists('certbot');

    $needsSync = false;
    $processed = 0;
    $now = time();

    foreach ($rows as $row) {
        $domain = strtolower(trim((string) $row['name']));
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
            continue;
        }

        $id = (int) $row['id'];
        $certFile = ORBITRA_LETSENCRYPT_DIR . "/live/$domain/fullchain.pem";

        // Already covered. Make sure nginx knows about it, then move on.
        if (file_exists($certFile)) {
            // A fullchain.pem with only the leaf is the Firefox-ok / Chrome-fail
            // case: it looks present but the chain is broken. Re-checking it on
            // every run catches a file that went wrong after the original issue
            // (a manual edit, a botched renewal) instead of leaving the domain
            // falsely green.
            $chain = orbitraCertificateChainComplete($certFile);
            if (!$chain['ok']) {
                $error = json_encode([
                    'code' => 'incomplete_chain',
                    'count' => $chain['count'],
                    'path' => $certFile,
                ], JSON_UNESCAPED_UNICODE);
                $pdo->prepare("UPDATE domains SET ssl_status = 'failed', ssl_error = ? WHERE id = ?")
                    ->execute([$error, $id]);
                $result['failed']++;
                continue;
            }
            if (($row['ssl_status'] ?? '') !== 'installed') {
                $pdo->prepare("UPDATE domains SET ssl_status = 'installed', ssl_error = NULL WHERE id = ?")->execute([$id]);
            }
            $needsSync = true; // orbitraSyncNginx() no-ops when the config already matches
            continue;
        }

        if ($processed >= $limit || !$canIssue) {
            continue;
        }

        // Respect the backoff so a domain that keeps failing does not consume the
        // hourly Let's Encrypt allowance the working domains need.
        $attempts = (int) ($row['ssl_attempts'] ?? 0);
        $lastAttempt = (int) strtotime((string) ($row['ssl_last_attempt'] ?? '')) ?: 0;
        if ($lastAttempt > 0 && ($now - $lastAttempt) < orbitraSslRetryDelay($attempts)) {
            continue;
        }

        $result['checked']++;

        // DNS gate. A domain that does not point here cannot validate, so asking
        // Let's Encrypt would only spend one of the five failures it allows per
        // hostname per hour.
        $dns = orbitraDomainPointsHere($domain);
        if (!$dns['ok']) {
            // A code, not a sentence. This string is shown in a panel that speaks
            // seven languages, so the wording belongs in the locale files; what
            // the backend knows is the fact and the two addresses involved.
            $detail = json_encode([
                'code' => 'dns_mismatch',
                'seen' => $dns['ips'],
                'expected' => $dns['server_ip'],
            ], JSON_UNESCAPED_UNICODE);
            $pdo->prepare("UPDATE domains SET ssl_status = 'waiting_dns', ssl_error = ? WHERE id = ?")
                ->execute([$detail, $id]);
            $result['waiting']++;
            continue;
        }

        $processed++;
        $pdo->prepare("UPDATE domains SET ssl_status = 'installing' WHERE id = ?")->execute([$id]);

        $output = (string) orbitraShell(orbitraCertbotCertonlyCommand($domain) . ' 2>&1');

        if (orbitraCertbotSucceeded($output, $domain)) {
            // certbot wrote the files, but the one that matters — fullchain.pem —
            // must actually carry the chain. A single-cert file is what leaves a
            // site green in Firefox (which fetches the intermediate itself) and
            // red in Chrome (which will not), which is exactly the ticket that is
            // hardest to diagnose from the panel. Catching it here marks the
            // domain failed with a named reason instead of "installed", so the
            // operator sees it before a visitor's browser does.
            $chain = orbitraCertificateChainComplete($certFile);
            if (!$chain['ok']) {
                $error = json_encode([
                    'code' => 'incomplete_chain',
                    'count' => $chain['count'],
                    'path' => $certFile,
                ], JSON_UNESCAPED_UNICODE);
                $pdo->prepare("UPDATE domains SET ssl_status = 'failed', ssl_error = ?, ssl_attempts = ssl_attempts + 1, ssl_last_attempt = datetime('now') WHERE id = ?")
                    ->execute([$error, $id]);
                $result['failed']++;
                continue;
            }
            $pdo->prepare("UPDATE domains SET ssl_status = 'installed', ssl_error = NULL, ssl_attempts = 0, ssl_last_attempt = datetime('now') WHERE id = ?")
                ->execute([$id]);
            $needsSync = true;
            $result['issued']++;
        } else {
            // Certbot's own output is not UI copy — it is diagnostic text from the
            // server and is shown as-is. Only our own fallback needs translating.
            $error = trim($output) !== ''
                ? substr(trim($output), -500)
                : json_encode(['code' => 'certbot_no_output'], JSON_UNESCAPED_UNICODE);
            $pdo->prepare("UPDATE domains SET ssl_status = 'failed', ssl_error = ?, ssl_attempts = ssl_attempts + 1, ssl_last_attempt = datetime('now') WHERE id = ?")
                ->execute([$error, $id]);
            $result['failed']++;
        }
    }

    $result['can_issue'] = $canIssue;

    if ($needsSync) {
        try {
            $sync = orbitraSyncNginx($pdo);
            $result['synced'] = is_array($sync) && ($sync['status'] ?? '') === 'success';
        } catch (\Throwable $e) {
            // Non-fatal: the certificates exist, the next run picks them up.
        }
    }

    return $result;
}
