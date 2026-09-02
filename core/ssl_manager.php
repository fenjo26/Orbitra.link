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
require_once __DIR__ . '/CloudDetector.php';
require_once __DIR__ . '/server_ip.php';

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
        // Existing installs carry the old hourly line ("17 * * * *"): a parked
        // domain whose DNS landed a minute after the save waited up to an hour
        // for its certificate. Five minutes is still gentle on Let's Encrypt —
        // the queue itself gates every certbot call on DNS and the backoff
        // ladder — so an old schedule gets upgraded in place.
        if (strpos($current, '*/5 * * * *') !== false) {
            return $done = true;
        }
        $current = implode("\n", array_filter(
            explode("\n", $current),
            static fn($l) => strpos($l, $marker) === false
        ));
        // fall through: the append below writes the new schedule
    }

    $php = trim((string) orbitraShell('command -v php 2>/dev/null')) ?: 'php';
    $line = "*/5 * * * * $php " . escapeshellarg($script) . ' >> ' . escapeshellarg($log) . " 2>&1 $marker";

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
    $sudoCertbot = $certbot && orbitraSudoCertbotAvailable()['ok'];

    $problems = [];
    if (!$shell) {
        $problems[] = 'php_no_shell';
    } elseif (!$certbot) {
        $problems[] = 'no_certbot';
    } elseif (!$sudoCertbot) {
        $problems[] = 'no_sudo_certbot';
    }
    if (!$nginxConfig) {
        $problems[] = 'no_nginx_config';
    }
    if (!$acmeWritable) {
        $problems[] = 'acme_not_writable';
    }

    return [
        // Issuing needs a shell, Certbot, and sudo access to Certbot; wiring the result into the web
        // server needs the nginx config. Without these, nothing can be obtained at all.
        'can_issue' => $shell && $certbot && $sudoCertbot,
        'shell' => $shell,
        'certbot' => $certbot,
        'sudo_certbot' => $sudoCertbot,
        'nginx_config' => $nginxConfig,
        'acme_writable' => $acmeWritable,
        'problems' => $problems,
    ];
}

/**
 * Can the web user run sudo certbot?
 *
 * Certbot needs root to write into /etc/letsencrypt. This checks whether sudo
 * is available and whether the web server user can run certbot without a password
 * (as install.sh configures with a sudoers entry). Returns the reason if not.
 *
 * @return array{ok: bool, reason: string}
 */
function orbitraSudoCertbotAvailable(): array
{
    $shell = orbitraShellAvailable();
    if (!$shell) {
        return ['ok' => false, 'reason' => 'php_no_shell'];
    }

    $sudo = orbitraCommandExists('sudo');
    if (!$sudo) {
        return ['ok' => false, 'reason' => 'no_sudo'];
    }

    // Test if we can run certbot with sudo
    // Using sudo -n (non-interactive) to check if passwordless access is configured
    $test = orbitraShell('sudo -n certbot --help 2>&1');
    if ($test === null) {
        return ['ok' => false, 'reason' => 'sudo_failed'];
    }

    // Check if output contains expected certbot help
    if (stripos($test, 'certbot') === false && stripos($test, 'usage') === false) {
        return ['ok' => false, 'reason' => 'sudo_no_password'];
    }

    return ['ok' => true, 'reason' => ''];
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
 * The read itself goes through orbitraReadPrivilegedFile(): certbot writes as
 * root and /etc/letsencrypt is root-only on many hosts, and a file the panel
 * cannot open must not be reported as "chain truncated" — those are different
 * problems with different fixes.
 *
 * @return array{ok: bool, count: int, readable: bool}
 *   readable=false means the contents could not be read by any route; count
 *   is only meaningful when readable=true.
 */
function orbitraCertificateChainComplete(string $certFile): array
{
    $raw = orbitraReadPrivilegedFile($certFile);
    if (!is_string($raw)) {
        return ['ok' => false, 'count' => 0, 'readable' => false];
    }
    $count = substr_count($raw, '-----BEGIN CERTIFICATE-----');
    return ['ok' => $count >= 2, 'count' => $count, 'readable' => true];
}

/**
 * What chain does the domain actually serve over HTTPS right now?
 *
 * When the panel cannot read /etc/letsencrypt (root-only tree, and no sudoers
 * cat rule — true for every install that predates the rule and only ever
 * updates via git pull), this is the check that needs no filesystem rights at
 * all: it asks our own nginx for the certificate exactly the way a browser
 * does and counts the links it sends. That is a stronger answer than the file,
 * not a weaker one — the file could be fine while nginx still serves something
 * else. The connection is pinned to this server's public IP so a stale DNS
 * record or a CDN in front cannot answer for somebody else, and certificate
 * verification is off because the point is to collect the chain, not to trust
 * it (an unwired vhost or a self-signed placeholder must not abort the
 * handshake before the chain is counted).
 *
 * @return array{reached: bool, count: int, subject: string, issuer: string}
 *   reached=false means no TLS handshake happened (nothing wired, port closed,
 *   no curl); subject/issuer are the leaf's DN strings when reached.
 */
function orbitraProbeServedChain(string $domain): array
{
    $out = ['reached' => false, 'count' => 0, 'subject' => '', 'issuer' => ''];
    if ($domain === '' || !function_exists('curl_init')) {
        return $out;
    }
    $ch = curl_init('https://' . $domain . '/');
    if ($ch === false) {
        return $out;
    }
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CERTINFO => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $ip = orbitraServerIp();
    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
        curl_setopt($ch, CURLOPT_RESOLVE, [$domain . ':443:' . $ip]);
    }
    curl_exec($ch);
    $certs = curl_getinfo($ch, CURLINFO_CERTINFO);
    curl_close($ch);
    if (is_array($certs) && $certs !== []) {
        $out['reached'] = true;
        $out['count'] = count($certs);
        $out['subject'] = (string) ($certs[0]['Subject'] ?? '');
        $out['issuer'] = (string) ($certs[0]['Issuer'] ?? '');
    }
    return $out;
}

/**
 * How many certificates does the domain serve on the wire, counted by openssl?
 *
 * The second witness behind the probe's fail verdict: libcurl's CERTINFO has
 * been seen reporting only the leaf while the wire carries the full chain, and
 * "served chain is truncated" marks a domain failed — that must never rest on
 * one tool's word.
 *
 * @return int chain size, or -1 when openssl is unavailable or the connection
 *             did not complete.
 */
function orbitraWireChainCount(string $domain): int
{
    if ($domain === '' || !orbitraCommandExists('openssl')) {
        return -1;
    }
    $raw = orbitraShell('echo | openssl s_client -connect ' . escapeshellarg($domain . ':443')
        . ' -servername ' . escapeshellarg($domain) . ' -showcerts 2>/dev/null');
    if (!is_string($raw)) {
        return -1;
    }
    return substr_count($raw, '-----BEGIN CERTIFICATE-----');
}

/**
 * Classify a fullchain.pem in one call: 'ok', 'incomplete_chain' (the chain
 * was seen and genuinely carries less than a full chain) or 'chain_unverified'
 * (the panel could not confirm the chain — file unreadable AND the live HTTPS
 * probe could not answer).
 *
 * When the file can be read, the verdict is the block count, as before. When
 * it cannot — root-only /etc/letsencrypt, and no sudoers cat rule on installs
 * that predate it — the served chain decides instead: two or more links for
 * this hostname is a verified chain, and one link is the real Firefox-ok /
 * Chrome-fail truncation ONLY if a second tool (openssl, on the same wire)
 * also counts one — curl's CERTINFO has undercounted on some builds, and this
 * is the one verdict here that fails a domain. Anything else (a self-signed
 * placeholder still wired while the config rebuilds, a different vhost, no
 * answer at all) stays 'chain_unverified'. That third state must NOT mark the
 * domain failed: the certificate exists, nginx will be synced within the hour,
 * and failing it feeds the retry backoff for nothing.
 *
 * $probeFn and $wireFn are injection seams for tests; production never passes
 * them.
 */
function orbitraChainVerdict(string $certFile, string $domain = '', ?callable $probeFn = null, ?callable $wireFn = null): string
{
    $chain = orbitraCertificateChainComplete($certFile);
    if (!$chain['readable']) {
        if ($domain === '') {
            return 'chain_unverified';
        }
        $probe = ($probeFn ?? 'orbitraProbeServedChain')($domain);
        if (!$probe['reached'] || stripos($probe['subject'], $domain) === false) {
            return 'chain_unverified';
        }
        if ($probe['count'] >= 2) {
            return 'ok';
        }
        // One link for our hostname: a real truncation only if Let's Encrypt
        // signed it. A single self-signed cert with the right CN is the
        // placeholder the fresh-issue flow wires up moments before the LE
        // server block — not a failure.
        $isLetsEncrypt = stripos($probe['issuer'], 'let') !== false
            && stripos($probe['issuer'], 'encrypt') !== false;
        if (!$isLetsEncrypt) {
            return 'chain_unverified';
        }
        $wire = ($wireFn ?? 'orbitraWireChainCount')($domain);
        if ($wire >= 2) {
            return 'ok'; // curl undercounted; the wire carries the full chain
        }
        return $wire === 1 ? 'incomplete_chain' : 'chain_unverified';
    }
    return $chain['ok'] ? 'ok' : 'incomplete_chain';
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
 * Delegates to the ORB-005 detector in core/server_ip.php — the single source
 * of truth the settings banner already uses. This function used to carry its
 * own copy of the detection ladder, and that copy did not know about the
 * `server_ip_override` setting: on a machine where autodetection fails (no
 * outbound HTTP, or an egress address that is private behind NAT), the operator
 * would set the address by hand, watch the settings page confirm it, and still
 * see every domain sit at "waiting for DNS" forever with an empty server IP,
 * because the SSL gate below was comparing A records against an empty string.
 *
 * Runs from cron as often as from a web request, so $_SERVER is not available
 * and $pdo is taken from the global the bootstrap builds; the detector caches
 * so the external lookup happens once.
 */
function orbitraServerIp(): string
{
    $pdo = $GLOBALS['pdo'] ?? null;
    return orbitraDetectServerIp($pdo instanceof PDO ? $pdo : null);
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
 * Pre-flight DNS validation before Certbot execution.
 *
 * Performs comprehensive DNS checks and returns detailed error information
 * when DNS is not properly configured. Use this before calling Certbot to
 * avoid wasting rate limit attempts on domains that cannot validate.
 *
 * @return array{valid: bool, error_code: string|null, error_message: string|null, details: array}
 *   Returns validation result with specific error codes:
 *   - 'dns_not_resolved': Domain has no DNS records
 *   - 'dns_mismatch': DNS exists but points to wrong IP(s)
 *   - 'server_ip_unknown': Could not determine server IP
 *   - null when validation passes
 */
function orbitraDnsPreflightCheck(string $domain): array
{
    $serverIp = orbitraServerIp();

    // Cannot validate if we don't know the server's IP
    if ($serverIp === '') {
        return [
            'valid' => false,
            'error_code' => 'server_ip_unknown',
            'error_message' => 'Cannot determine this server IP address. DNS validation requires knowing the expected address.',
            'details' => [
                'server_ip' => null,
            ],
        ];
    }

    $ips = [];
    $hasRecords = false;

    // Try to get A and AAAA records
    foreach ([DNS_A, DNS_AAAA] as $type) {
        $records = @dns_get_record($domain, $type);
        if (is_array($records) && count($records) > 0) {
            $hasRecords = true;
            foreach ($records as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                } elseif (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
    }

    // Fallback to gethostbyname if dns_get_record returned nothing
    if (!$hasRecords) {
        $resolved = @gethostbyname($domain);
        if (is_string($resolved) && $resolved !== $domain && filter_var($resolved, FILTER_VALIDATE_IP)) {
            $ips[] = $resolved;
            $hasRecords = true;
        }
    }

    $ips = array_values(array_unique($ips));

    // No DNS records found
    if (!$hasRecords || empty($ips)) {
        return [
            'valid' => false,
            'error_code' => 'dns_not_resolved',
            'error_message' => sprintf(
                "Domain '%s' does not resolve to any IP address. Please check your DNS configuration and ensure an A record points to this server (%s).",
                $domain,
                $serverIp
            ),
            'details' => [
                'domain' => $domain,
                'server_ip' => $serverIp,
                'resolved_ips' => [],
            ],
        ];
    }

    // DNS exists but doesn't point to this server
    if (!in_array($serverIp, $ips, true)) {
        $ipList = implode(', ', $ips);
        return [
            'valid' => false,
            'error_code' => 'dns_mismatch',
            'error_message' => sprintf(
                "Domain '%s' resolves to %s, but this server expects %s. Please update your DNS A record to point to the correct IP address.",
                $domain,
                $ipList,
                $serverIp
            ),
            'details' => [
                'domain' => $domain,
                'server_ip' => $serverIp,
                'resolved_ips' => $ips,
                'mismatch' => true,
            ],
        ];
    }

    // All checks passed
    return [
        'valid' => true,
        'error_code' => null,
        'error_message' => null,
        'details' => [
            'domain' => $domain,
            'server_ip' => $serverIp,
            'resolved_ips' => $ips,
        ],
    ];
}

/**
 * Quick DNS validation helper for immediate user feedback.
 *
 * Use this when saving a domain or checking its configuration to give the user
 * instant feedback about whether the domain is ready for certificate issuance.
 *
 * @return array{ready: bool, message: string, error_code: string|null}
 *   Returns a simple result suitable for API responses or UI display.
 */
function orbitraValidateDomainForSsl(string $domain): array
{
    $check = orbitraDnsPreflightCheck($domain);

    if ($check['valid']) {
        return [
            'ready' => true,
            'message' => sprintf(
                "Domain '%s' correctly points to this server (%s). Ready for certificate issuance.",
                $domain,
                $check['details']['server_ip']
            ),
            'error_code' => null,
        ];
    }

    return [
        'ready' => false,
        'message' => $check['error_message'],
        'error_code' => $check['error_code'],
    ];
}

/**
 * Run one SQLite write, retrying while the database is locked.
 *
 * The every-minute crons (rotation optimiser, postback queue, aggregator) can
 * hold the write lock past PDO's 5-second busy_timeout, and a certificate
 * worker that dies on the first contended UPDATE leaves every domain after it
 * unprocessed until the next hourly run. The queue's writes are idempotent
 * status transitions, so re-running one after a lock is always safe.
 *
 * Rethrows anything that is not "database is locked", and rethrows the lock
 * itself once the attempts are exhausted — the caller decides whether that is
 * fatal (CLI) or per-domain (the queue logs it and moves on).
 */
function orbitraSslWriteWithRetry(PDO $pdo, string $sql, array $params, int $tries = 4): void
{
    for ($attempt = 1; ; $attempt++) {
        try {
            $pdo->prepare($sql)->execute($params);
            return;
        } catch (\PDOException $e) {
            if ($attempt >= $tries || stripos((string) $e->getMessage(), 'database is locked') === false) {
                throw $e;
            }
            sleep(2);
        }
    }
}

/**
 * Work the certificate queue once. Safe to call from cron every few minutes.
 *
 * @return array{checked: int, issued: int, waiting: int, failed: int, synced: bool}
 */
function orbitraProcessSslQueue(PDO $pdo, int $limit = 5): array
{
    $result = ['checked' => 0, 'issued' => 0, 'waiting' => 0, 'failed' => 0, 'synced' => false, 'can_issue' => false, 'cloudflare' => 0];

    try {
        // Every parked domain, not only the HTTPS-only ones: parking a domain is
        // the request for a certificate, exactly as it is in Keitaro. Domains
        // already serving a certificate are filtered out below rather than in
        // SQL, because "installed" in the database has been wrong before.
        // Cloudflare-proxied domains are the exception — the edge serves their
        // certificate and certbot cannot validate through the proxy, so asking
        // Let's Encrypt would only burn the hourly failure budget.
        $rows = $pdo->query("SELECT id, name, ssl_status, ssl_error, ssl_attempts, ssl_last_attempt, cloudflare_proxy, ssl_source FROM domains WHERE name IS NOT NULL AND name != '' ORDER BY id")
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

        // Auto-detect Cloudflare proxy if not manually set
        $currentFlag = (int) ($row['cloudflare_proxy'] ?? 0);
        $sslStatus = (string) ($row['ssl_status'] ?? '');

        // Skip if already marked as Cloudflare (manually or previously detected)
        if ($currentFlag === 1 || $sslStatus === 'cloudflare') {
            continue;
        }

        // Auto-detect Cloudflare proxy to avoid Certbot failures
        // Cloudflare-proxied domains resolve to CF IPs and can't validate through the edge
        if (CloudDetector::isCloudflareProxied($domain)) {
            // DNS still answers through Cloudflare, so certbot cannot validate
            // the origin — asking Let's Encrypt would only burn its failure
            // budget. When the admin explicitly chose an origin certificate
            // source, say why the flag moved instead of silently clearing the
            // error. NB: this reads ssl_source, which only works because the
            // queue SELECT above selects it — a guard on an unfetched column
            // fails open and silent.
            $explicitSource = in_array((string)($row['ssl_source'] ?? 'auto'), ['letsencrypt', 'custom'], true);
            $sslError = $explicitSource
                ? json_encode(['code' => 'awaiting_dns_for_ssl_switch'], JSON_UNESCAPED_UNICODE)
                : null;
            // Bookkeeping only: the domain is proxied whether or not this write
            // lands, and one contended SQLite write must not abort processing
            // for every domain after it in the queue run.
            try {
                orbitraSslWriteWithRetry($pdo, "UPDATE domains SET cloudflare_proxy = 1, ssl_status = 'cloudflare', ssl_error = ? WHERE id = ?", [$sslError, $id]);
                $result['cloudflare']++;
            } catch (\Throwable $e) {
                error_log('SSL queue: cloudflare flag update failed for domain id ' . $id . ' (' . $domain . '): ' . $e->getMessage());
            }
            continue;
        }

        $certFile = ORBITRA_LETSENCRYPT_DIR . "/live/$domain/fullchain.pem";

        // Already covered. Make sure nginx knows about it, then move on.
        // Root's view on existence: a root-only /etc/letsencrypt must not
        // make a healthy certificate invisible to the queue (which would
        // send the domain back to certbot and into the backoff).
        if (orbitraLetsEncryptCertExists($domain)) {
            // A fullchain.pem with only the leaf is the Firefox-ok / Chrome-fail
            // case: it looks present but the chain is broken. Re-checking it on
            // every run catches a file that went wrong after the original issue
            // (a manual edit, a botched renewal) instead of leaving the domain
            // falsely green.
            $verdict = orbitraChainVerdict($certFile, $domain);
            if ($verdict === 'chain_unverified') {
                // The certificate exists (the line above said so, possibly via
                // certbot's root view) but the chain could not be confirmed —
                // the file is unreadable to this process and the live HTTPS
                // probe did not answer yet (or is still serving the
                // self-signed placeholder). nginx reads the file as root and
                // serves it, so the honest status is installed — with the
                // reason stored for the panel's warning instead of a failure
                // that would feed the retry backoff. The next run re-probes
                // and clears the warning once the chain answers.
                $error = json_encode([
                    'code' => 'chain_unverified',
                    'path' => $certFile,
                ], JSON_UNESCAPED_UNICODE);
                orbitraSslWriteWithRetry($pdo, "UPDATE domains SET ssl_status = 'installed', ssl_error = ? WHERE id = ?", [$error, $id]);
                $needsSync = true; // the LE server block must replace self-signed
                continue;
            }
            if ($verdict === 'incomplete_chain') {
                $chain = orbitraCertificateChainComplete($certFile);
                $error = json_encode([
                    'code' => 'incomplete_chain',
                    'count' => $chain['count'],
                    'path' => $certFile,
                ], JSON_UNESCAPED_UNICODE);
                orbitraSslWriteWithRetry($pdo, "UPDATE domains SET ssl_status = 'failed', ssl_error = ? WHERE id = ?", [$error, $id]);
                $result['failed']++;
                continue;
            }
            // Verified, by file or by the served chain. Clear a stale warning
            // too: an unverified run stored an error on an already-installed
            // domain, and nothing else ever removes it.
            if (($row['ssl_status'] ?? '') !== 'installed' || ($row['ssl_error'] ?? null) !== null) {
                orbitraSslWriteWithRetry($pdo, "UPDATE domains SET ssl_status = 'installed', ssl_error = NULL WHERE id = ?", [$id]);
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
        $dnsCheck = orbitraDnsPreflightCheck($domain);
        if (!$dnsCheck['valid']) {
            // Store structured error with code and details for UI rendering
            $detail = json_encode([
                'code' => $dnsCheck['error_code'],
                'message' => $dnsCheck['error_message'],
                'details' => $dnsCheck['details'],
            ], JSON_UNESCAPED_UNICODE);
            orbitraSslWriteWithRetry($pdo, "UPDATE domains SET ssl_status = 'waiting_dns', ssl_error = ? WHERE id = ?", [$detail, $id]);
            $result['waiting']++;
            continue;
        }

        $processed++;
        orbitraSslWriteWithRetry($pdo, "UPDATE domains SET ssl_status = 'installing' WHERE id = ?", [$id]);

        $output = (string) orbitraShell(orbitraCertbotCertonlyCommand($domain) . ' 2>&1');

        if (orbitraCertbotSucceeded($output, $domain)) {
            // certbot wrote the files, but the one that matters — fullchain.pem —
            // must actually carry the chain. A single-cert file is what leaves a
            // site green in Firefox (which fetches the intermediate itself) and
            // red in Chrome (which will not), which is exactly the ticket that is
            // hardest to diagnose from the panel. Catching it here marks the
            // domain failed with a named reason instead of "installed", so the
            // operator sees it before a visitor's browser does. An UNVERIFIABLE
            // chain, on the other hand, is not a chain problem: certbot just
            // wrote it as root into a tree this process cannot open, and the
            // live probe needs the nginx sync that follows — installed with a
            // stored warning, no attempt burned, re-probed on the next run.
            $verdict = orbitraChainVerdict($certFile, $domain);
            if ($verdict === 'chain_unverified') {
                $error = json_encode([
                    'code' => 'chain_unverified',
                    'path' => $certFile,
                ], JSON_UNESCAPED_UNICODE);
                orbitraSslWriteWithRetry($pdo, "UPDATE domains SET ssl_status = 'installed', ssl_error = ?, ssl_attempts = 0, ssl_last_attempt = datetime('now') WHERE id = ?", [$error, $id]);
                $needsSync = true;
                $result['issued']++;
                continue;
            }
            if ($verdict === 'incomplete_chain') {
                $chain = orbitraCertificateChainComplete($certFile);
                $error = json_encode([
                    'code' => 'incomplete_chain',
                    'count' => $chain['count'],
                    'path' => $certFile,
                ], JSON_UNESCAPED_UNICODE);
                orbitraSslWriteWithRetry($pdo, "UPDATE domains SET ssl_status = 'failed', ssl_error = ?, ssl_attempts = ssl_attempts + 1, ssl_last_attempt = datetime('now') WHERE id = ?", [$error, $id]);
                $result['failed']++;
                continue;
            }
            orbitraSslWriteWithRetry($pdo, "UPDATE domains SET ssl_status = 'installed', ssl_error = NULL, ssl_attempts = 0, ssl_last_attempt = datetime('now') WHERE id = ?", [$id]);
            $needsSync = true;
            $result['issued']++;
        } else {
            // Certbot's own output is not UI copy — it is diagnostic text from the
            // server and is shown as-is. Only our own fallback needs translating.
            $error = trim($output) !== ''
                ? substr(trim($output), -500)
                : json_encode(['code' => 'certbot_no_output'], JSON_UNESCAPED_UNICODE);
            orbitraSslWriteWithRetry($pdo, "UPDATE domains SET ssl_status = 'failed', ssl_error = ?, ssl_attempts = ssl_attempts + 1, ssl_last_attempt = datetime('now') WHERE id = ?", [$error, $id]);
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

/**
 * Verify SSL status by making an actual TLS connection to the origin.
 *
 * ORB-014: The SSL column must reflect reality, not just a stored flag.
 * This function opens a TLS connection to port 443 with the hostname as SNI
 * and confirms the response comes from Orbitra rather than another vhost.
 *
 * @return array{status: string, reachable: bool, orbitra_serves: bool, details: string}
 *   status is one of:
 *   - 'serving': Orbitra is correctly serving HTTPS for this hostname
 *   - 'no_certificate': No certificate on the origin (connection failed or no TLS)
 *   - 'answered_elsewhere': Another vhost answered (not Orbitra)
 */
function orbitraVerifyOriginSsl(string $domain): array
{
    $result = [
        'status' => 'no_certificate',
        'reachable' => false,
        'orbitra_serves' => false,
        'details' => '',
    ];

    // Try to open a TLS connection
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'capture_peer_cert' => true,
            'SNI_enabled' => true,
            'peer_name' => $domain,
        ],
        'socket' => [
            'bindto' => '0:0', // Bind to all interfaces
        ],
    ]);

    $socket = @stream_socket_client(
        'tls://' . $domain . ':443',
        $errno,
        $errstr,
        3, // 3 second timeout
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($socket === false) {
        $result['details'] = "Cannot connect to $domain:443 (errno $errno: $errstr)";
        return $result;
    }

    $result['reachable'] = true;

    // Get the peer certificate
    $params = stream_context_get_params($socket);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;

    if ($cert === null) {
        fclose($socket);
        $result['details'] = 'Connected but no certificate returned';
        return $result;
    }

    $certInfo = openssl_x509_parse($cert);
    fclose($socket);

    if ($certInfo === false) {
        $result['details'] = 'Certificate returned but could not be parsed';
        return $result;
    }

    // Check if this is Orbitra's certificate
    // Orbitra certificates are either:
    // 1. Self-signed (/CN=orbitra or /CN=<server-ip>)
    // 2. Let's Encrypt for the specific domain
    // 3. Cloudflare Origin CA (if we add support)

    $subject = $certInfo['subject']['CN'] ?? '';
    $issuer = $certInfo['issuer']['CN'] ?? '';
    $san = $certInfo['extensions']['subjectAltName'] ?? '';

    // Check if this is Orbitra's self-signed cert
    $isOrbitraSelfSigned = (
        stripos($subject, 'orbitra') !== false ||
        stripos($issuer, 'orbitra') !== false
    );

    // Check if this is Let's Encrypt for this domain
    $isLetsEncrypt = (
        stripos($issuer, "Let's Encrypt") !== false ||
        stripos($issuer, 'R3') !== false || // LE intermediate
        stripos($issuer, 'ISRG Root X1') !== false
    );
    $matchesDomain = (
        stripos($san, $domain) !== false ||
        stripos($subject, $domain) !== false
    );
    $isLetsEncryptForDomain = $isLetsEncrypt && $matchesDomain;

    // Check if this is Cloudflare Origin CA
    $isCloudflareOrigin = (
        stripos($issuer, "Cloudflare") !== false &&
        stripos($issuer, 'Origin') !== false
    );

    if ($isOrbitraSelfSigned) {
        $result['status'] = 'serving';
        $result['orbitra_serves'] = true;
        $result['details'] = 'Served by Orbitra (self-signed certificate)';
    } elseif ($isLetsEncryptForDomain) {
        $result['status'] = 'serving';
        $result['orbitra_serves'] = true;
        $result['details'] = 'Served by Orbitra (Let\'s Encrypt)';
    } elseif ($isCloudflareOrigin) {
        $result['status'] = 'serving';
        $result['orbitra_serves'] = true;
        $result['details'] = 'Served by Orbitra (Cloudflare Origin CA)';
    } else {
        // Certificate exists but it's not from Orbitra - another vhost answered
        $result['status'] = 'answered_elsewhere';
        $result['details'] = "Answered by another vhost (CN: $subject, Issuer: $issuer)";
    }

    return $result;
}
