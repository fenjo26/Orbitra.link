<?php
// tests/local_archive_download_test.php
//
// landing_download / offer_download: a local landing's or offer's files,
// streamed back as one ZIP (core/archive_download.php).
//
// The panel could upload an archive and edit the files one by one, but never
// give the folder back. This drives both actions through HTTP:
//   - the archive opens clean in ZipArchive (a strict reader) and carries every
//     file byte for byte — text deflated, large files stored and streamed;
//   - a symlink planted in the folder is not followed;
//   - the file name follows the landing's own name (UTF-8 spelling kept);
//   - nothing to give: an unknown id, a redirect landing, an external offer, a
//     landing nothing was uploaded to;
//   - access: it is a read of the landing / offer resource, and a user whose
//     scope is "selected" gets only their own items.
//
// Run: php tests/local_archive_download_test.php

require_once __DIR__ . '/lib/http.php';

$ok = true;
function check(bool $cond, string $msg): void
{
    global $ok;
    if ($cond) {
        echo "✓ $msg\n";
    } else {
        fwrite(STDERR, "FAILED: $msg\n");
        $ok = false;
    }
}

/** GET without redirects: [status, Content-Type, Content-Disposition, body]. */
function fetchRaw(string $url, string $cookie): array
{
    $ctx = stream_context_create(['http' => [
        'timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0, 'header' => 'Cookie: ' . $cookie,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 200;
    $type = '';
    $disposition = '';
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        } elseif (stripos($h, 'Content-Type:') === 0) {
            $type = trim(substr($h, 13));
        } elseif (stripos($h, 'Content-Disposition:') === 0) {
            $disposition = trim(substr($h, 20));
        }
    }
    return [$status, strtolower($type), $disposition, (string) $body];
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "SKIP: ext/zip is needed to read the archives back\n");
    exit(0);
}

$harness = new OrbitraTestHarness(dirname(__DIR__));
$harness->useProductionRouter();

try {
    $harness->start();
    $pdo = $harness->getPdo();
    $base = $harness->getBaseUrl();
    $work = (string) $harness->getWorkingDir();

    $addUser = static function (string $username, string $role, array $permissions) use ($pdo, $harness): string {
        $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, ?, 1, ?)")
            ->execute([$username, password_hash('dl_pass_123', PASSWORD_DEFAULT), $role, json_encode($permissions)]);
        try { $pdo->exec('DELETE FROM rate_limits'); } catch (\Throwable $e) {}
        $resp = $harness->postWithHeaders('/api.php?action=login', json_encode(['username' => $username, 'password' => 'dl_pass_123']), ['Content-Type: application/json']);
        preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $m);
        if (($m[1] ?? '') === '') {
            throw new RuntimeException('login failed for ' . $username . ': ' . $resp['body']);
        }
        return 'ORBITRASESSID=' . $m[1];
    };
    $admin = $addUser('dl_admin', 'admin', []);

    // --- fixtures: two local landings (one in the limited user's scope), one redirect landing, two offers
    $id = static fn (): int => random_int(500000, 599999);
    $landingId = $id(); $otherLandingId = $id(); $redirectLandingId = $id(); $emptyLandingId = $id();
    $offerId = $id(); $otherOfferId = $id(); $externalOfferId = $id();
    $insLanding = $pdo->prepare("INSERT INTO landings (id, name, url, type, state, is_archived, slug) VALUES (?, ?, '', ?, 'active', 0, ?)");
    $insLanding->execute([$landingId, 'Nutra Лендинг', 'local', 'dl-nutra']);
    $insLanding->execute([$otherLandingId, 'Other landing', 'local', 'dl-other']);
    $insLanding->execute([$redirectLandingId, 'Redirect', 'redirect', '']);
    $insLanding->execute([$emptyLandingId, 'Nothing uploaded', 'local', 'dl-empty']);
    $insOffer = $pdo->prepare("INSERT INTO offers (id, name, url, is_local, state, is_archived) VALUES (?, ?, '', ?, 'active', 0)");
    $insOffer->execute([$offerId, 'Vinter offer', 1]);
    $insOffer->execute([$otherOfferId, 'Other offer', 1]);
    $insOffer->execute([$externalOfferId, 'External offer', 0]);

    $dir = $work . '/landings/dl-nutra';
    mkdir($dir . '/css', 0775, true);
    mkdir($dir . '/img', 0775, true);
    $indexHtml = str_repeat("<html><body>Buy now</body></html>\n", 800);
    $binary = random_bytes(250000);                       // incompressible: stored, not deflated
    $big = str_repeat('A', 2 * 1024 * 1024);              // deflated; the stored path is covered by the binary
    file_put_contents($dir . '/index.html', $indexHtml);
    file_put_contents($dir . '/css/style.css', "body{margin:0}\n");
    file_put_contents($dir . '/img/logo.bin', $binary);
    file_put_contents($dir . '/big.txt', $big);
    mkdir($dir . '/папка', 0775, true);
    file_put_contents($dir . '/папка/страница.html', '<p>Привет</p>');
    $offerDir = $work . '/offers/' . $offerId;
    mkdir($offerDir, 0775, true);
    file_put_contents($offerDir . '/index.html', '<html><body>Offer page</body></html>');
    // A link planted in the folder, pointing at a file outside of it.
    $secret = $work . '/dl-secret.txt';
    file_put_contents($secret, 'OUTSIDE-THE-LANDING');
    symlink($secret, $dir . '/leak.txt');
    mkdir($work . '/landings/dl-other', 0775, true);
    file_put_contents($work . '/landings/dl-other/index.html', '<p>other</p>');

    /** The archive of a download, as ZipArchive sees it — or the error that came instead. */
    $zipOf = static function (string $action, int $entityId, string $cookie) use ($base): array {
        [$status, $type, $disposition, $body] = fetchRaw($base . "/api.php?action={$action}&id={$entityId}", $cookie);
        if ($status !== 200) {
            $json = json_decode($body, true);
            return ['status' => $status, 'error' => (string) ($json['message'] ?? $body), 'type' => $type];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'orbitra_dl_');
        file_put_contents($tmp, $body);
        $zip = new ZipArchive();
        $open = $zip->open($tmp, ZipArchive::CHECKCONS);
        return ['status' => $status, 'type' => $type, 'disposition' => $disposition, 'zip' => $zip, 'open' => $open, 'tmp' => $tmp, 'bytes' => strlen($body)];
    };
    $close = static function (array $got): void {
        if (isset($got['zip']) && $got['zip'] instanceof ZipArchive) { $got['zip']->close(); }
        if (isset($got['tmp'])) { @unlink($got['tmp']); }
    };

    // --- a local landing comes back whole -----------------------------------------
    $got = $zipOf('landing_download', $landingId, $admin);
    $zip = $got['zip'] ?? null;
    check($got['status'] === 200 && $got['type'] === 'application/zip', 'landing_download answers 200 application/zip — got ' . json_encode([$got['status'], $got['type'], $got['error'] ?? null]));
    check(isset($got['open']) && $got['open'] === true, 'the archive opens clean in a strict reader (CHECKCONS)');
    if ($zip) {
        check($zip->getFromName('index.html') === $indexHtml, 'index.html is byte for byte the file on disk (deflated)');
        check($zip->getFromName('css/style.css') === "body{margin:0}\n" && $zip->getFromName('папка/страница.html') === '<p>Привет</p>', 'subfolders and non-ASCII file names survive');
        check($zip->getFromName('img/logo.bin') === $binary, 'a binary file is byte for byte the file on disk (stored)');
        check($zip->getFromName('big.txt') === $big, 'a multi-megabyte file is byte for byte the file on disk');
        $stat = $zip->statName('index.html');
        check(($stat['comp_method'] ?? 0) === 8 && ($zip->statName('img/logo.bin')['comp_method'] ?? -1) === 0, 'text is deflated, incompressible data is stored as is');
        check($zip->locateName('leak.txt') === false, 'a symlink in the landing folder is not followed');
        check($zip->numFiles === 5, 'exactly the five real files are in the archive — got ' . $zip->numFiles);
    }
    check(isset($got['disposition']) && str_contains($got['disposition'], 'filename="landing-nutra-lending.zip"')
        && str_contains($got['disposition'], "filename*=UTF-8''landing-Nutra-%D0%9B%D0%B5%D0%BD%D0%B4%D0%B8%D0%BD%D0%B3.zip"),
        'the file name is the landing\'s own name, ASCII and UTF-8 — got ' . ($got['disposition'] ?? ''));
    $close($got);

    // --- a local offer, the same way -------------------------------------------------
    $got = $zipOf('offer_download', $offerId, $admin);
    check($got['status'] === 200 && isset($got['zip']) && $got['zip']->getFromName('index.html') === '<html><body>Offer page</body></html>'
        && str_contains($got['disposition'] ?? '', 'filename="offer-vinter-offer.zip"'), 'offer_download answers the offer\'s files as a ZIP — got ' . json_encode([$got['status'], $got['error'] ?? null]));
    $close($got);

    // --- nothing to give ---------------------------------------------------------------
    $expect = static function (string $label, string $action, int $entityId, int $status, string $error, ?string $cookie = null) use ($zipOf, $admin): bool {
        $got = $zipOf($action, $entityId, $cookie ?? $admin);
        $good = $got['status'] === $status && ($got['error'] ?? '') === $error;
        echo ($good ? '✓ ' : 'FAILED: ') . "{$label} — got " . json_encode([$got['status'], $got['error'] ?? null]) . "\n";
        return $good;
    };
    $ok = $expect('an unknown landing', 'landing_download', $id(), 404, 'landing_not_found') && $ok;
    $ok = $expect('an unknown offer', 'offer_download', $id(), 404, 'offer_not_found') && $ok;
    $ok = $expect('a redirect landing has no files to download', 'landing_download', $redirectLandingId, 404, 'landing_not_local') && $ok;
    $ok = $expect('an external offer has no files to download', 'offer_download', $externalOfferId, 404, 'offer_not_local') && $ok;
    $ok = $expect('a local landing nothing was uploaded to', 'landing_download', $emptyLandingId, 404, 'archive_no_files') && $ok;

    // --- access --------------------------------------------------------------------------
    [$status] = fetchRaw($base . "/api.php?action=landing_download&id={$landingId}", '');
    check($status === 401 || $status === 403, "a stranger is refused (got {$status})");
    // 'groups' must be present: an entry without it predates 1.10 and is read as 'full'.
    $limited = $addUser('dl_limited', 'user', [
        'landings' => ['access' => 'selected', 'items' => [$landingId], 'groups' => []],
        'offers' => ['access' => 'selected', 'items' => [$offerId], 'groups' => []],
    ]);
    $got = $zipOf('landing_download', $landingId, $limited);
    check($got['status'] === 200 && isset($got['zip']), 'a scope-limited user downloads a landing in their scope');
    $close($got);
    $ok = $expect('…but not a landing outside it', 'landing_download', $otherLandingId, 403, 'Forbidden', $limited) && $ok;
    $ok = $expect('…nor an offer outside it', 'offer_download', $otherOfferId, 403, 'Forbidden', $limited) && $ok;
    $readOnly = $addUser('dl_reader', 'user', ['landings' => ['access' => 'read']]);
    $got = $zipOf('landing_download', $landingId, $readOnly);
    check($got['status'] === 200 && isset($got['zip']), 'a read-only landing user can download (it is a read)');
    $close($got);
    $none = $addUser('dl_none', 'user', ['landings' => ['access' => 'none']]);
    $got = $zipOf('landing_download', $landingId, $none);
    check($got['status'] === 403, 'a user without landing access is refused');
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    $ok = false;
} finally {
    $harness->stop();
}

exit($ok ? 0 : 1);
