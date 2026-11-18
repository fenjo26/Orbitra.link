<?php
// cli/diag/shm_locks.php
//
// WHO holds the WAL right now — a reader census for the load investigation
// (ТЗ 1.9.7). SQLite WAL readers/writer/checkpointer coordinate through POSIX
// advisory locks on the -shm file: each live reader holds a READ_MARK byte,
// the active writer holds the WRITER byte, the copying checkpointer holds the
// CHECKPOINTER byte. F_GETLK on those bytes reports the holder's PID — so a
// sampling loop of F_GETLK probes answers, with numbers and process names,
// exactly what the ТЗ asks: кто держит чтение, сколько и из какого процесса.
//
// The SQLite unix VFS maps wal-index locks onto fcntl byte locks of the -shm
// file starting at SHM_BASE(120) (os_unix.c unixShmSystemLock; the positions
// below were verified empirically on darwin against a held write transaction —
// byte 120 lit up for the WRITER). The sampler probes bytes 118..134
// individually, so a shifted layout still shows up; the named map interprets
// the canonical positions:
//   120 WRITER        held while a write transaction is appending frames
//   121 CHECKPOINTER  held while a checkpoint copies frames / updates marks
//   122 RECOVER       wal-index recovery only (transient)
//   123..128 READ_MARK(0..5)  reader slots (0 = "database file only")
//
// Usage:
//   php cli/diag/shm_locks.php --shm=/path/db.sqlite-shm --hz=5 --seconds=60 \
//       [--out=/tmp/shmcensus.csv]
//
// Output: CSV per sample:
//   ts, writer, wtype, ckpt, ctype, readers, n, other
// writer/ckpt = holder PID (empty when free), wtype/ctype = the holder's
// process TYPE (php-fpm / cron-spool / php-cli / …). The ckpt columns answer
// the 1.9.7 question directly: WHO runs the inline PASSIVE checkpoints the
// wal_autocheckpoint fuse triggers once the log grows past its threshold —
// sqlite3WalDefaultHook (main.c, 3.45.1) fires on EVERY commit of EVERY
// connection while the TOTAL WAL size is over wal_autocheckpoint pages.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['shm:', 'hz:', 'seconds:', 'out::']);
$shmPath = $opts['shm'] ?? '';
if ($shmPath === '' || !is_file($shmPath)) {
    fwrite(STDERR, "usage: php cli/diag/shm_locks.php --shm=<db.sqlite-shm> [--hz=5] [--seconds=60] [--out=csv]\n");
    exit(1);
}
$hz = max(0.5, min(50.0, (float) ($opts['hz'] ?? 5)));
$seconds = max(1.0, min(3600.0, (float) ($opts['seconds'] ?? 60)));
$outPath = $opts['out'] ?? null;

// Lock region offsets (see the header comment) live in the Python sampler
// below: PHP has no fcntl(F_GETLK), so the census itself runs through
// python3 (present on the stand per the kit README).

$py = trim((string) shell_exec('command -v python3 2>/dev/null'));
if ($py === '') {
    fwrite(STDERR, "python3 required (PHP has no fcntl F_GETLK)\n");
    exit(1);
}

$pyCode = <<<'PY'
import fcntl, os, struct, sys, time
shm = sys.argv[1]; hz = float(sys.argv[2]); seconds = float(sys.argv[3])
fh = open(shm, 'rb')
NAMES = {120:'W',121:'C',122:'R',123:'r0',124:'r1',125:'r2',126:'r3',127:'r4',128:'r5'}
_ptype_cache = {}
def ptype(pid):
    # Classify a lock holder: php-fpm vs the spool cron vs another CLI php vs
    # anything else. /proc on Linux (the stand); `ps` fallback elsewhere.
    if pid in _ptype_cache:
        return _ptype_cache[pid]
    cmd = ''
    try:
        with open('/proc/%d/cmdline' % pid, 'rb') as f:
            cmd = f.read().replace(b'\0', b' ').decode('utf-8', 'replace')
    except Exception:
        try:
            cmd = os.popen('ps -p %d -o command=' % pid).read()
        except Exception:
            cmd = ''
    if 'php-fpm' in cmd:
        t = 'php-fpm'
    elif 'click_spool_cron' in cmd:
        t = 'cron-spool'
    elif cmd.startswith('php ') or '/php ' in cmd:
        t = 'php-cli'
    elif cmd.strip():
        t = cmd.split()[0]
    else:
        t = 'gone'
    if len(_ptype_cache) > 512:
        _ptype_cache.clear()
    _ptype_cache[pid] = t
    return t
def pack_probe(off):
    # struct flock field ORDER differs: darwin (start, len, pid, type, whence);
    # linux (type, whence, start, len, pid) with alignment padding.
    if sys.platform == 'darwin':
        return struct.pack('@qqihh', off, 1, os.getpid(), fcntl.F_WRLCK, os.SEEK_SET)
    return struct.pack('@hhqqi', fcntl.F_WRLCK, os.SEEK_SET, off, 1, os.getpid())
def unpack(res):
    if sys.platform == 'darwin':
        start, ln, pid, t, w = struct.unpack('@qqihh', res)
    else:
        t, w, start, ln, pid = struct.unpack('@hhqqi', res)
    return t, pid
def probe(off):
    # F_GETLK only DESCRIBES the first conflicting lock; probing with F_WRLCK
    # (write intent) conflicts with BOTH read and write locks held by other
    # processes — an F_RDLCK probe would silently miss every reader mark.
    try:
        res = fcntl.fcntl(fh.fileno(), fcntl.F_GETLK, pack_probe(off))
        t, pid = unpack(res)
        if t != fcntl.F_UNLCK and pid > 0 and pid != os.getpid():
            return pid
    except Exception:
        pass
    return None
end = time.time() + seconds
interval = 1.0 / hz
nxt = time.time()
print('ts,writer,wtype,ckpt,ctype,readers,n,other')
while time.time() < end:
    delay = nxt - time.time()
    if delay > 0:
        time.sleep(delay)
    nxt += interval
    now = time.time()
    writer = ckpt = ''
    wtype = ctype = ''
    readers = []
    other = []
    for off in range(118, 135):
        pid = probe(off)
        if pid is None:
            continue
        name = NAMES.get(off, 'b%d' % off)
        if name == 'W':
            writer = str(pid)
            wtype = ptype(pid)
        elif name == 'C':
            ckpt = str(pid)
            ctype = ptype(pid)
        elif name == 'R':
            other.append('%d:recover' % pid)
        elif name.startswith('r'):
            readers.append('%d:%s' % (pid, name))
        else:
            other.append('%d:b%d' % (pid, off))
    print('%.3f,%s,%s,%s,%s,"%s",%d,"%s"' % (now, writer, wtype, ckpt, ctype,
        ';'.join(readers), len(readers), ';'.join(other)))
PY
;

$cmd = escapeshellarg($py) . ' -c ' . escapeshellarg($pyCode) . ' ' . escapeshellarg($shmPath) . ' '
    . escapeshellarg((string) $hz) . ' ' . escapeshellarg((string) $seconds);
if ($outPath !== null) {
    passthru($cmd . ' >' . escapeshellarg($outPath));
    echo "written to $outPath\n";
} else {
    passthru($cmd);
}
