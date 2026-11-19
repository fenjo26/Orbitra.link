<?php
// cli/diag/wal_watch.php
//
// Pure OBSERVER of the WAL for the load investigation (ТЗ 1.9.7): it runs NO
// checkpoint of any kind — every PRAGMA wal_checkpoint(...) copies frames and
// takes the checkpointer lock, which an observer must never do (round 2
// note). Everything it reports comes from reading files:
//
//   wal_bytes   size of the -wal file
//   mxframe     frames in the log — decoded from the wal-index header in the
//               -shm file (offsets: isInit 12, szPage 14, mxFrame 16,
//               nBackfill 24, native byte order, two header copies at 0/56)
//   nbackfill   frames already copied into the main database
//   lag_mb      (mxframe − nbackfill) × page size — what a RESTART would have
//               to copy right now
//   hb_*        the spool worker's heartbeat (var/cache/wal_checkpoint.json):
//               its age and the cumulative restart counters, so one CSV lines
//               up the log size with the folding machinery's behavior
//
// Usage:
//   php cli/diag/wal_watch.php --db=/var/www/orbitra/orbitra_db.sqlite \
//       --hz=5 --seconds=120 [--out=/tmp/walwatch.csv]
//
// Output: one CSV row per sample on stdout (and to --out when given).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['db:', 'hz:', 'seconds:', 'out::']);
$dbPath = $opts['db'] ?? '';
if ($dbPath === '' || !is_file($dbPath)) {
    fwrite(STDERR, "usage: php cli/diag/wal_watch.php --db=<sqlite file> [--hz=5] [--seconds=120] [--out=walwatch.csv]\n");
    exit(1);
}
$hz = max(0.5, min(50.0, (float) ($opts['hz'] ?? 5)));
$seconds = max(1.0, min(3600.0, (float) ($opts['seconds'] ?? 120)));
$outPath = $opts['out'] ?? null;
$shmPath = $dbPath . '-shm';
$hbPath = dirname($dbPath) . '/var/cache/wal_checkpoint.json';

/**
 * Decode the wal-index header from the -shm file (read-only, no locks).
 * Layout per wal.c: iVersion u32@0, iChange u32@8, isInit u8@12, bigEndCksum
 * u8@13, szPage u16@14, mxFrame u32@16, nPage u32@20, nBackfill u32@24. Two
 * copies exist; return the initialized one with the higher iChange, or nulls.
 *
 * @return array{mx:int,backfill:int,page:int}
 */
function orbitraWalIndexHeader(string $shmPath): array
{
    $out = ['mx' => -1, 'backfill' => -1, 'page' => -1];
    if (!is_file($shmPath)) {
        return $out;
    }
    $fh = @fopen($shmPath, 'rb');
    if (!$fh) {
        return $out;
    }
    $raw = @fread($fh, 112);
    fclose($fh);
    if (strlen($raw) < 48) {
        return $out;
    }
    $best = null;
    foreach ([0, 48] as $off) {
        $hdr = substr($raw, $off, 48);
        if (strlen($hdr) < 48) {
            continue;
        }
        $isInit = ord($hdr[12]);
        if ($isInit !== 1) {
            continue;
        }
        $iChange = unpack('V', substr($hdr, 8, 4))[1] ?? 0;
        if ($best === null || $iChange > $best['iChange']) {
            $best = [
                'iChange' => $iChange,
                'page' => unpack('v', substr($hdr, 14, 2))[1] ?: 4096,
                'mx' => unpack('V', substr($hdr, 16, 4))[1] ?? -1,
                'backfill' => unpack('V', substr($hdr, 24, 4))[1] ?? -1,
            ];
        }
    }
    if ($best !== null) {
        $out = ['mx' => (int) $best['mx'], 'backfill' => (int) $best['backfill'], 'page' => (int) $best['page']];
    }
    return $out;
}

$out = $outPath !== null ? @fopen($outPath, 'wb') : null;
$header = 'ts,wal_bytes,mxframe,nbackfill,lag_mb,hb_age_s,restarts_ok,restarts_busy,forced,natural,max_block_ms';
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
    $hdr = orbitraWalIndexHeader($shmPath);
    $lagMb = ($hdr['mx'] > 0 && $hdr['backfill'] >= 0 && $hdr['page'] > 0)
        ? ($hdr['mx'] - $hdr['backfill']) * $hdr['page'] / 1048576.0
        : -1.0;

    $hbAge = -1;
    $ok = $busy = $forced = $natural = 0;
    $blockMs = 0.0;
    $hbMtime = @filemtime($hbPath);
    if ($hbMtime !== false) {
        $hbAge = max(0, microtime(true) - $hbMtime);
        $hb = json_decode((string) @file_get_contents($hbPath), true);
        if (is_array($hb)) {
            $ok = (int) ($hb['restarts_ok'] ?? 0);
            $busy = (int) ($hb['restarts_busy'] ?? 0);
            $forced = (int) ($hb['forced_restarts'] ?? 0);
            $natural = (int) ($hb['natural_restarts'] ?? 0);
            $blockMs = (float) ($hb['max_block_ms'] ?? 0);
        }
    }

    $line = sprintf('%.3f,%d,%d,%d,%.1f,%.1f,%d,%d,%d,%d,%.1f',
        microtime(true), $walBytes, $hdr['mx'], $hdr['backfill'], $lagMb, $hbAge, $ok, $busy, $forced, $natural, $blockMs);
    echo $line . "\n";
    if ($out) {
        fwrite($out, $line . "\n");
    }
}
if ($out) {
    fclose($out);
}
exit(0);
