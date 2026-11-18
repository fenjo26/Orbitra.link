# DIAG — where a click's time goes (Orbitra 1.9.4, this VPS)

Date: 2026-11-16. Before the measurements: the generator test had finished
(disconnected at 19:18), the post-test database was saved to
`/root/loadtest/post_test_backup.sqlite` (62 687 clicks, window
19:04:27–19:08:01), then `/usr/local/bin/orbitra-lt-reset` was run → RESET OK,
clicks=0.

*(Русская версия: `DIAG-RU.md`.)*

## Symptom (from Ruslan)

- 20 rps: every unique click ≥ ~26 ms (p50 44 ms), CPU ~6%.
- A repeat click (not written to the database) — ~5 ms.
- 160 rps, 3 min: latency grows 30 → 600 ms, CPU ~25%, loadavg up to 9.
- The same code in a different environment (2 vCPU, local disk, Sypex only):
  160 rps → p50 5 ms / p99 11 ms, 480 rps → 0 errors.
- Working hypothesis: the processes are **waiting** (disk/locks/network),
  not computing.

## 1. The disk

- Database: `/var/www/orbitra/orbitra_db.sqlite` on `/dev/vda1`, mount point `/`.
- `lsblk`: `vda` 70G (virtio, KVM guest), partitions vda1(/) 69G,
  vda15(/boot/efi), vda16(/boot), sr0 rom.
- `mount | grep ' / '`: `/dev/vda1 on / type ext4 (rw,relatime,discard,errors=remount-ro,commit=30)`
  — ⚠️ `commit=30` (ext4 journal commit interval 30 s instead of the default 5 s)
  — a sign of a custom image/provider setup. Noted, NOT changed.
- `/sys/block/vda/queue/rotational` = 1 — the guest sees the disk as rotating
  (typical for virtio-blk on top of network storage; a local NVMe usually
  reports rotational=0).
  Bottom line: **a virtual disk, most likely network-backed/slow**, not a local NVMe.

### Synchronous write latency (dd, oflag=dsync)

```
$ cd /var/www/orbitra/var && dd if=/dev/zero of=fsync.test bs=4k count=500 oflag=dsync
500+0 records in
500+0 records out
2048000 bytes (2.0 MB, 2.0 MiB) copied, 3.31299 s, 618 kB/s
```
→ **6.63 ms per dsync write** (500 operations in 3.31 s).

### fio (4k write + fdatasync on every operation, 20 s, iodepth=1)

Output (two runs, the second complete):
```
write: IOPS=100, BW=401KiB/s
sync (usec): min=5629, max=92024, avg=9908.83, stdev=4611.91
sync percentiles (usec):
 |  1.00th=[ 6259],  5.00th=[ 6652], 10.00th=[ 6915], 20.00th=[ 7308],
 | 30.00th=[ 7701], 40.00th=[ 8029], 50.00th=[ 8455], 60.00th=[ 9241],
 | 70.00th=[10290], 80.00th=[11469], 90.00th=[14091], 95.00th=[16909],
 | 99.00th=[27919], 99.50th=[34341], 99.90th=[47973], 99.95th=[58983],
 | 99.99th=[91751]
```
(first run: IOPS=106, avg=9.31 ms, max=44.4 ms — consistent.)

→ **fdatasync: p50 ≈ 8.5 ms, p90 ≈ 14 ms, p99 ≈ 28 ms, max 92 ms.**
For comparison: a local NVMe does 0.1–1 ms, a decent network SSD 1–3 ms.
This is a very slow disk for a WAL workload: every fsync ≈ 8–10 ms, tails up
to 30–90 ms.

Note: `fio` was installed via apt (a utility, not a service), allowed by the
task's terms.

## 2. The SQLite mode the application sees

A one-off script `/tmp/opencode/diag_pragmas.php` (`require config.php`,
`$pdo` from it), run as `sudo -u www-data php ...` — as the methodology
requires:

```
journal_mode         = wal
synchronous          = 1        (NORMAL)
wal_autocheckpoint   = 1000
busy_timeout         = 30000    ← CLI branch (PHP_SAPI==='cli'); for web requests
                                  config.php sets 5000 (line 33)
foreign_keys         = 1
cache_size           = -2000    (2 MB)
mmap_size            = 0
temp_store           = 0
page_size            = 4096
locking_mode         = normal
```

Important: in WAL mode synchronous=NORMAL does not fsync on every commit —
the fsyncs land on **WAL checkpoints** (automatic at ~1000 WAL pages) and
housekeeping operations. So the disk hits the click not constantly but in
bursts — consistent with "processes wait, CPU idles".

## 3. Single-click time without load (200 sequential, localhost)

```
loop A — 200 new visitors (distinct XFF):
  p50 0.0240  p90 0.0404  p99 0.0657  max 0.102   (seconds)

loop B — the same visitor 200 times (repeats):
  p50 0.0063  p90 0.0104  p99 0.0121  max 0.041   (seconds)
```

→ Matches the generator's observation: a unique click ≥ ~24 ms (p50), a
repeat ~5–6 ms. **The delta ≈ 18 ms is the price of writing the click to the
database.** With fdatasync ≈ 8–10 ms on this disk, the delta is explained by
2–4 synchronizations per write.

A test caveat (for honesty): the database ended up with 202 rows, not 200:
- the first "repeat" was in fact a new visitor: due to the `$((i%200+1))`
  arithmetic the XFF `85.1.10.1` never appeared in loop A → loop B's first
  request was honestly recorded (uniq_campaign=1);
- 1 row (diag-144) was written with `uniq_campaign=0` — the dedup marked the
  click non-unique but still created the row (tracker behavior, observed
  without changes).

## 4. Syscalls of a single click (strace)

The first summary `strace -f -c -p $(pgrep -d, -f "php-fpm: pool")` had to be
discarded: `pgrep -f` caught its own bash wrapper (the pattern in its
cmdline) + 400 curl processes from the background loop → the execve/connect/
wait4 in that summary belonged to the curl loop, NOT the click path. Re-run
cleanly (attached to 4 worker pids, 20 s, 374 clicks from a 400-unique loop
in the window):

```
syscall            calls   per click (÷374)  avg
fdatasync          1496    4.0             54 µs (skewed by ptrace; real durations in the -T trace below)
pwrite64           15423   41
pread64            22336   59              3 µs (page cache: binary searches over the geo files)
fcntl              33287   89
openat             2992    8               (db+wal+shm+3 geo files+zoneinfo+directory)
unlink             748     2.0
ftruncate          748     2.0
accept             374     1.0
mmap               748     2.0
```

connect/sendto/execve are ABSENT from the clean trace — the click path is
entirely local, no outbound network calls (the first "summary" with connect
was an artifact of the curl loop).

A detailed trace of one click (`strace -f -T` on a worker):

```
openat(orbitra_db.sqlite) → openat(-wal, O_CREAT) → openat(-shm, O_CREAT)
openat(geo/keitaro-geo.dat), openat(var/geoip/SxGeoCity/SxGeoCity.dat), openat(geo/keitaro-carriers.dat)
pwrite64(wal header, offset 0)                ← the WAL starts over
fdatasync(wal)                                <0.003528>   3.5 ms
openat(/var/www/orbitra, O_RDONLY directory)
fdatasync(directory)                          <0.004788>   4.8 ms
pwrite64 ×8 pages into the WAL (the click's commit)
fdatasync(wal)                                <0.004284>   4.3 ms
pwrite64 ×9 pages into the MAIN database      ← a WAL checkpoint inside this very click
fdatasync(main database)                      <0.002408>   2.4 ms
```

→ **4 fdatasync per click at 2.4–4.8 ms ≈ 12–18 ms of disk wait per click.**
The checkpoint and WAL restart happen in every click (unlink/ftruncate 2 per
click) — with wal_autocheckpoint=1000 the autocheckpoint fires constantly
here, because the WAL is small after each reset and every click's write
(a few pages + indexes) quickly reaches the threshold.

## 5. Geo databases (Orbitra Geo 519 MB, Mobile Operator, BotDB — Sypex NOT touched)

Temporarily renamed to `*.off` (after a database reset, measured with the
same 200-new-visitors loop, XFF 96.x.x.x / sub1=geodiag-*), then restored
immediately:

```
with geo databases (§3):  p50 24.0 ms   p90 40.4 ms   p99 65.7 ms
without the three geo:    p50 22.3 ms   p90 36.4 ms   p99 70.0 ms
```

→ **No difference** (within noise). The geo databases are NOT the cause of
the latency: their lookups are ~59 preads per click from the page cache at
~3 µs each. The files were restored, the panel sees all four databases:
sypex_city_lite OK, keitaro_geo OK, keitaro_carriers OK, keitaro_bots OK
(verified via `geo_dbs` right after restoring).

## 6. WAL under load

Load: 20 000 unique clicks, parallel curl c=32 (the analogue of
`ab -n 20000 -c 32`), ~90 s (~220 rps). A monitor every 5 s: the `-wal` size
+ the time of one probe click.

Load result: **mean 70 ms, p50 57 ms, p90 142 ms, p99 229 ms, max 959 ms**
(20 000 requests).

Monitor (full log, WAL size in bytes → probe click):

```
19:46:37 wal=none        37 ms    (start, after reset)
19:46:42 wal=1034152     58 ms
19:46:47 wal=1112432     57 ms
19:46:52 wal=1005312     37 ms
19:46:57 wal=1252512     50 ms
19:47:03 wal=622152      73 ms
19:47:08 wal=32          17 ms    ← checkpoint ran, WAL reset
19:47:13 wal=1652152     73 ms
19:47:18 wal=1617920     16 ms
19:47:23 wal=1557392     44 ms
19:47:28 wal=1841672     99 ms
19:47:33 wal=1631552     81 ms
19:47:38 wal=2369032     17 ms
19:47:43 wal=1479112     12 ms
19:47:48 wal=1936432    147 ms    ← WAL ~2 MB → latency grew
19:47:54 wal=2039432    172 ms    ← WAL peak → latency peak
19:47:59 wal=156592      18 ms    ← checkpoint → everything is fast again
19:48:04 wal=1367872    105 ms
19:48:09 wal=none        37 ms    (load ended, the last checkpoint deleted the WAL)
```

- The WAL **does not grow unbounded**: it saw-tooths 0.15–2.4 MB, checkpoints
  keep resetting it.
- Latency **correlates with the WAL size**: at WAL ~2 MB a probe click takes
  105–172 ms, right after a checkpoint 12–18 ms. The bigger the WAL, the more
  pages must be synchronized into the main database → the longer the tail.
- `database is locked`: 25 entries, all timestamped 16:21:53 (the generator
  test window) in `var/logs/php_errors.log` ("Orbitra click logging failed
  ... database is locked" — those clicks went to the spool). There are NO such
  errors in my own 90-second load run.

## 7. php-fpm pool and nginx (read-only, nothing changed)

```
/etc/php/8.5/fpm/pool.d/www.conf:
  listen = /run/php/php8.5-fpm.sock (unix socket)
  pm = dynamic, pm.max_children = 64, start_servers = 4,
  min_spare_servers = 2, max_spare_servers = 8
  listen.backlog not set

/etc/nginx/nginx.conf:
  worker_processes auto;
  worker_connections 768;
```

## Summary table

| Metric | Value |
|---|---|
| Disk | /dev/vda1 virtio, ext4, `commit=30`, rotational=1 — likely network storage |
| fdatasync (dd dsync) | 6.6 ms average (500 ops) |
| fdatasync (fio, 4k+sync) | **p50 8.5 ms, p90 14 ms, p99 28 ms**, avg 9.9 ms, max 92 ms |
| SQLite (as the app sees it) | WAL, synchronous=NORMAL, autocheckpoint=1000, busy_timeout 5000(web)/30000(cli) |
| 1 click, new visitor | **p50 24 ms, p90 40 ms, p99 66 ms** |
| 1 click, repeat (no write) | **p50 6.3 ms, p90 10 ms, p99 12 ms** |
| 1 click without geo databases | p50 22.3 ms — unchanged → geo is not the culprit |
| fsync per click | **4 × fdatasync, 2.4–4.8 ms each** (WAL commit, directory, WAL, checkpoint into the db) |
| Network on the click path | none (no connect/sendto in the clean trace) |
| 20k clicks c=32 (~220 rps) | p50 57 ms, p99 229 ms, max 959 ms |
| WAL under load | saw-tooth 0.15–2.4 MB, no unbounded growth; latency follows the WAL size |
| database is locked | 25, all at 16:21:53 (the generator test); none in my load run |
| php-fpm | dynamic, max_children 64 |
| nginx | worker_processes auto, worker_connections 768 |

## Conclusion (one sentence)

**The unique click's time is eaten by the disk: every click write runs into
4 fdatasync at ~3–8 ms each (~12–18 ms of waiting — hence p50 24 ms versus
6 ms for a write-less repeat), and under load the WAL growing to ~2 MB
inflates the checkpoint syncs to hundreds of milliseconds; geo databases,
network and CPU are not involved — in an environment with a fast local disk
(fsync ~0.1–1 ms) the same code does p50 5–11 ms.**

## Temporary changes and rollbacks

| What | When | Rollback |
|---|---|---|
| `apt-get install -y fio strace` | before the measurements | not rolled back (utilities without services; allowed by the task) |
| `keitaro-{geo,carriers,bots}.dat` → `.off` | §5, ~1 min | restored with the same mv, the panel sees the databases (verified) |
| `fsync.test` / `fio.test` | §1 | deleted right after the measurements |
| database reset to the snapshot | before §3/5/6 and after all measurements | the standard `/usr/local/bin/orbitra-lt-reset` |
| Configs/code/pragmas | — | NOT changed |

The generator's post-test database is saved:
`/root/loadtest/post_test_backup.sqlite`. After the measurements the database
was reset to the clean snapshot (RESET OK), the configuration was not
changed — awaiting instructions.
