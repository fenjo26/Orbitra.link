<?php
/**
 * Bug 1 (docs/TZ_SSL_CHAIN_AND_PRIVACY.md): the chain verdict must separate
 * "the chain is truncated" from "the panel cannot read the file at all".
 *
 * The conflation marked healthy certificates on root-only /etc/letsencrypt
 * hosts as failed and fed their retry backoff for nothing. Pins the three
 * verdicts of orbitraChainVerdict() and the readable flag contract of
 * orbitraCertificateChainComplete() on real files, including one this
 * process cannot open.
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
//    Firefox-ok / Chrome-fail case) — NOT chain_unreadable.
$leafOnly = "$tmp/leafonly.pem";
file_put_contents($leafOnly, $oneCert);
$v = orbitraChainVerdict($leafOnly);
check("leaf-only chain => incomplete_chain", $v === 'incomplete_chain');
$c = orbitraCertificateChainComplete($leafOnly);
check("leaf-only readable, count 1", $c['readable'] === true && $c['count'] === 1 && $c['ok'] === false);

// 3. An unreadable file verdicts chain_unreadable — the state that must NOT
//    mark a domain failed. chmod 000 only simulates unreadability when this
//    process is not root; as root fall back to a nonexistent path.
$locked = "$tmp/locked.pem";
file_put_contents($locked, $twoCerts);
chmod($locked, 0000);
$unreadablePath = is_readable($locked) ? "$tmp/definitely-missing.pem" : $locked;
$v = orbitraChainVerdict($unreadablePath);
check("unreadable file => chain_unreadable", $v === 'chain_unreadable');
$c = orbitraCertificateChainComplete($unreadablePath);
check("unreadable file not readable, count 0", $c['readable'] === false && $c['count'] === 0);

// 4. certonly command flags: the re-issue path forces, the worker keeps.
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
