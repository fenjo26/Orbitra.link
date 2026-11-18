<?php
// cli/diag/wal_watch.php
//
// Stand-alone WAL observer for the load investigation (ТЗ 1.9.7). Touches no
// application code: it opens its own SQLite connection and samples, at a fixed
// rate, the three numbers that explain why the WAL does or does not fold:
//
//   wal_bytes     size of the -wal file
//   passive       PRAGMA wal_checkpoint(PASSIVE) → (busy, log, checkpointed):
//                   busy=1          a reader pinned frames — the copy stopped
//                                   at that reader's snapshot
//                   log             frames in the log (mxFrame)
//                   checkpointed    frames copied into the main database
//                   lag = log − checkpointed   what readers are holding back
//   full          PRAGMA wal_checkpoint(FULL) with busy_timeout=0 → ok|busy.
//                 FULL succeeds only when nothing blocked the copy for the
//                 whole call, so its success rate at N Hz is a direct estimate
//                 of the READER-FREE fraction of time — the quantity the
//                 background RESTART (and any WAL restart) waits on. It does
//                 NOT rewind the log, so watching does not change the system
//                 under test.
//
// Usage:
//   php cli/diag/wal_watch.php --db=/var/www/orbitra/orbitra_db.sqlite \
//       --hz=5 --seconds=120 [--out=/tmp/walwatch.csv] [--probe=full|restart]
//
//   --probe=restart switches the second probe to a zero-wait RESTART. ONLY
//   for dedicated experiments: a successful RESTART actually rewinds the WAL,
//   i.e. the observer becomes a checkpoint driver. Never use it to evaluate
//   the shipped background scheme.
//
// Output: one CSV row per sample on stdout (ts, wal_bytes, mxframe, ckpt,
// lag, passive_busy, full_ok) and the same rows to --out when given. Run as a
// user with write access to the database directory (checkpointing needs it).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['db:', 'hz:', 'seconds:', 'out::', 'probe::']);
$dbPath = $opts['db'] ?? '';
if ($dbPath === '' || !is_file($dbPath)) {
    fwrite(STDERR, "usage: php cli/diag/wal_watch.php --db=<sqlite file> [--hz=5] [--seconds=120] [--out=walwatch.csv] [--probe=full|restart]\n");
    exit(1);
}
$hz = max(0.5, min(50.0, (float) ($opts['hz'] ?? 5)));
$seconds = max(1.0, min(3600.0, (float) ($opts['seconds'] ?? 120)));
$probe = ($opts['probe'] ?? 'full') === 'restart' ? 'restart' : 'full';
$outPath = $opts['out'] ?? null;

$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout = 0;'); // the probes must never wait

$out = $outPath !== null ? @fopen($outPath, 'wb') : null;
$header = 'ts,wal_bytes,mxframe,ckpt,lag,passive_busy,probe_ok';
echo $header . "\n";
if ($out) {
    fwrite($out, $header . "\n");
}

$deadline = microtime(true) + $seconds;
$interval = 1.0 / $hz;
$next = microtime(true);
while (microtime(true) < $deadline) {
    usleep(max(0, (int) (($next - microtime(true)) * 1000000)));
    $next += $interval;

    clearstatcache();
    $walBytes = is_file($dbPath . '-wal') ? (int) filesize($dbPath . '-wal') : 0;

    $mx = $ck = 0;
    $passiveBusy = -1;
    try {
        $row = $pdo->query('PRAGMA wal_checkpoint(PASSIVE)')->fetch(PDO::FETCH_NUM);
        if (is_array($row) && count($row) >= 3) {
            $passiveBusy = (int) $row[0];
            $mx = (int) $row[1];
            $ck = (int) $row[2];
        }
    } catch (Throwable $e) {
        // checkpointer lock held by the real cron — the sample still records size
    }

    $probeOk = -1;
    try {
        $row = $pdo->query('PRAGMA wal_checkpoint(' . $probe . ')')->fetch(PDO::FETCH_NUM);
        $probeOk = (is_array($row) && (int) ($row[0] ?? 1) === 0) ? 1 : 0;
    } catch (Throwable $e) {
        $probeOk = 0;
    }

    $line = sprintf('%.3f,%d,%d,%d,%d,%d,%d', microtime(true), $walBytes, $mx, $ck, max(0, $mx - $ck), $passiveBusy, $probeOk);
    echo $line . "\n";
    if ($out) {
        fwrite($out, $line . "\n");
    }
}
if ($out) {
    fclose($out);
}
exit(0);
