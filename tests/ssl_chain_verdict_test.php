<?php
/**
 * Bug 1 (docs/TZ_SSL_CHAIN_AND_PRIVACY.md): the chain verdict must separate
 * "the chain is truncated" from "the panel cannot confirm the chain".
 *
 * The conflation marked healthy certificates on root-only /etc/letsencrypt
 * hosts as failed and fed their retry backoff for nothing. Pins the verdicts
 * of orbitraChainVerdict() and the readable flag contract of
 * orbitraCertificateChainComplete() on real files, including one this process
 * cannot open.
 *
 * The unreadable-file path no longer stops at "cannot read": the verdict
 * falls back to a live HTTPS probe of the served chain (orbitraProbeServedChain),
 * so installs that never got the sudoers cat rule still reach a verified
 * 'ok'. The probe is injected here as a stub — production never passes it.
 */
require_once __DIR__ . '/../core/ssl_manager.php';

$fails = 0;
$checks = 0;

function check(string $name, bool $ok): void
{
    global $fails, $checks;
    $checks++;
    if (!$ok) {
        $fails++;
        echo "FAIL: $name\n";
    }
}

/**
 * Probe stub factory: subject must contain the domain for the verdict to
 * trust it, mirroring orbitraProbeServedChain()'s real-world shape.
 */
function probe(array $spec): callable
{
    return function (string $domain) use ($spec): array {
        if (!($spec['reached'] ?? false)) {
            return ['reached' => false, 'count' => 0, 'subject' => '', 'issuer' => ''];
        }
        return [
            'reached' => true,
            'count' => $spec['count'],
            'subject' => $spec['subject'] ?? ('CN=' . $domain),
            'issuer' => $spec['issuer'] ?? "C=US, O=Let's Encrypt, CN=E1",
        ];
    };
}

$tmp = sys_get_temp_dir() . '/orbitra_chain_test_' . getmypid();
@mkdir($tmp, 0777, true);

$twoCerts = "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n-----BEGIN CERTIFICATE-----\nBBBB\n-----END CERTIFICATE-----\n";
$oneCert  = "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n";

// 1. A healthy chain reads and verdicts ok.
$healthy = "$tmp/healthy.pem";
file_put_contents($healthy, $twoCerts);
$v = orbitraChainVerdict($healthy);
check("healthy chain => ok", $v === 'ok');
$c = orbitraCertificateChainComplete($healthy);
check("healthy chain readable", $c['readable'] === true);
check("healthy chain count 2", $c['count'] === 2 && $c['ok'] === true);

// 2. A single-cert file reads and verdicts incomplete_chain (the real
//    Firefox-ok / Chrome-fail case) — NOT an unreadable-file verdict.
$leafOnly = "$tmp/leafonly.pem";
file_put_contents($leafOnly, $oneCert);
$v = orbitraChainVerdict($leafOnly);
check("leaf-only chain => incomplete_chain", $v === 'incomplete_chain');
$c = orbitraCertificateChainComplete($leafOnly);
check("leaf-only readable, count 1", $c['readable'] === true && $c['count'] === 1 && $c['ok'] === false);

// 3. An unreadable file with no domain to probe verdicts chain_unverified —
//    the state that must NOT mark a domain failed. chmod 000 only simulates
//    unreadability when this process is not root; as root fall back to a
//    nonexistent path.
$locked = "$tmp/locked.pem";
file_put_contents($locked, $twoCerts);
chmod($locked, 0000);
$unreadablePath = is_readable($locked) ? "$tmp/definitely-missing.pem" : $locked;
$v = orbitraChainVerdict($unreadablePath);
check("unreadable file, no probe => chain_unverified", $v === 'chain_unverified');
$c = orbitraCertificateChainComplete($unreadablePath);
check("unreadable file not readable, count 0", $c['readable'] === false && $c['count'] === 0);

// 4. Unreadable file + the probe answers with a full served chain for the
//    domain => ok, no filesystem rights needed (the out-of-the-box path for
//    installs without the sudoers cat rule).
$v = orbitraChainVerdict($unreadablePath, 'example.com', probe(['reached' => true, 'count' => 2]));
check("unreadable + probe full chain => ok", $v === 'ok');

// 5. Unreadable file + probe answers with one LE-signed link for the domain
//    => the real served-chain truncation, failed.
$v = orbitraChainVerdict($unreadablePath, 'example.com', probe(['reached' => true, 'count' => 1]));
check("unreadable + probe leaf-only LE => incomplete_chain", $v === 'incomplete_chain');

// 6. Unreadable file + probe answers with one SELF-SIGNED link (the
//    placeholder wired between issue and nginx sync) => NOT a failure.
$v = orbitraChainVerdict($unreadablePath, 'example.com', probe([
    'reached' => true, 'count' => 1,
    'subject' => 'CN=example.com', 'issuer' => 'CN=example.com',
]));
check("unreadable + probe self-signed placeholder => chain_unverified", $v === 'chain_unverified');

// 7. Unreadable file + probe reaches a different vhost (subject mismatch)
//    or nothing at all => chain_unverified, retried next run.
$v = orbitraChainVerdict($unreadablePath, 'example.com', probe([
    'reached' => true, 'count' => 2,
    'subject' => 'CN=other.example.org',
]));
check("unreadable + probe wrong vhost => chain_unverified", $v === 'chain_unverified');
$v = orbitraChainVerdict($unreadablePath, 'example.com', probe(['reached' => false]));
check("unreadable + probe unreachable => chain_unverified", $v === 'chain_unverified');

// 8. certonly command flags: the re-issue path forces, the worker keeps.
$worker = orbitraCertbotCertonlyCommand('example.com');
$forced = orbitraCertbotCertonlyCommand('example.com', true);
check("worker keeps --keep-until-expiring", strpos($worker, '--keep-until-expiring') !== false);
check("reissue forces --force-renewal", strpos($forced, '--force-renewal') !== false && strpos($forced, '--keep-until-expiring') === false);

chmod($locked, 0644);
@unlink($healthy);
@unlink($leafOnly);
@unlink($locked);
@rmdir($tmp);

echo "ssl_chain_verdict_test: " . ($fails === 0 ? "OK" : "FAILED") . " ($checks checks)\n";
exit($fails === 0 ? 0 : 1);
