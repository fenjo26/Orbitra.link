<?php
/**
 * Standalone unit tests for cli/server_setup_swap.sh.
 *
 * Runs the real shell helper against temporary fixture files with fake
 * fallocate/mkswap/swapon binaries — no root, no 4 GiB disk allocation,
 * works identically on macOS and Linux.
 *
 * Usage: php tests/server_setup_swap_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

$repoRoot = dirname(__DIR__);
$helper = $repoRoot . '/cli/server_setup_swap.sh';
$tmp = sys_get_temp_dir() . '/orbitra_swap_test_' . getmypid();
@mkdir($tmp, 0777, true);

register_shutdown_function(static function () use ($tmp): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($tmp);
});

check(is_file($helper), 'cli/server_setup_swap.sh exists');

// Fake binaries: record calls to a log file without allocating gigabytes.
$binDir = $tmp . '/bin';
@mkdir($binDir, 0777, true);
$logFile = $tmp . '/calls.log';

file_put_contents($binDir . '/fallocate-ok', <<<SH
#!/bin/sh
echo "fallocate \$*" >> '$logFile'
# Last argument is the target path
for last; do :; done
printf 'FAKE_SWAP' > "\$last"
exit 0
SH
);
file_put_contents($binDir . '/fallocate-fail', "#!/bin/sh\necho \"fallocate-fail \$*\" >> '$logFile'\nexit 1\n");
file_put_contents($binDir . '/mkswap-ok', "#!/bin/sh\necho \"mkswap \$*\" >> '$logFile'\nexit 0\n");
file_put_contents($binDir . '/mkswap-fail', "#!/bin/sh\necho \"mkswap-fail \$*\" >> '$logFile'\nexit 1\n");
file_put_contents($binDir . '/swapon-ok', "#!/bin/sh\necho \"swapon \$*\" >> '$logFile'\nexit 0\n");
file_put_contents($binDir . '/swapon-fail', "#!/bin/sh\necho \"swapon-fail \$*\" >> '$logFile'\nexit 1\n");

foreach (glob($binDir . '/*') ?: [] as $b) {
    chmod($b, 0755);
}

$emptySwaps = "Filename\t\t\t\tType\t\tSize\tUsed\tPriority\n";
$activeSwaps = $emptySwaps . "/dev/sda2                               partition\t2097148\t0\t-2\n";
$mem2G = "MemTotal:        2048000 kB\nMemFree:          512000 kB\n";
$mem16G = "MemTotal:       16384000 kB\nMemFree:         8000000 kB\n";

$run = static function (array $env) use ($helper, $logFile, $binDir): array {
    @unlink($logFile);
    $defaults = [
        'FALLOCATE_BIN' => $binDir . '/fallocate-ok',
        'MKSWAP_BIN'    => $binDir . '/mkswap-ok',
        'SWAPON_BIN'    => $binDir . '/swapon-ok',
        'SWAP_FS_TYPE'  => 'ext4',
    ];
    $merged = array_merge($defaults, $env);
    $prefix = '';
    foreach ($merged as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg((string) $v) . ' ';
    }
    exec($prefix . 'bash ' . escapeshellarg($helper) . ' 2>&1', $out, $rc);
    return [
        'rc'   => $rc,
        'out'  => implode("\n", $out),
        'log'  => is_file($logFile) ? (string) file_get_contents($logFile) : '',
    ];
};

try {
    // --- 1. 2 GiB VPS, 25 GiB free -> 4096 MiB swap + fstab ------------------
    $case = $tmp . '/c1';
    @mkdir($case, 0777, true);
    file_put_contents($case . '/swaps', $emptySwaps);
    file_put_contents($case . '/meminfo', $mem2G);
    file_put_contents($case . '/fstab', "UUID=abc / ext4 defaults 0 1"); // no trailing newline on purpose
    $r = $run([
        'SWAP_FILE'       => $case . '/swapfile',
        'SWAP_FSTAB'      => $case . '/fstab',
        'SWAP_PROC_SWAPS' => $case . '/swaps',
        'SWAP_MEMINFO'    => $case . '/meminfo',
        'SWAP_FREE_MB'    => 25000,
    ]);
    check($r['rc'] === 0, '2 GiB VPS / 25 GiB free exits 0');
    check(is_file($case . '/swapfile'), 'swapfile created');
    check((fileperms($case . '/swapfile') & 0777) === 0600, 'swapfile permissions are 0600');
    check(strpos($r['log'], 'fallocate -l 4096M ') !== false, '25 GiB free selects 4096 MiB swap');
    check(strpos($r['log'], 'mkswap ') !== false && strpos($r['log'], 'swapon ') !== false, 'mkswap and swapon both ran');
    $fstab = (string) file_get_contents($case . '/fstab');
    check(
        $fstab === "UUID=abc / ext4 defaults 0 1\n" . $case . "/swapfile none swap sw 0 0\n",
        'fstab appended on a new line even when original lacked trailing newline'
    );

    // --- 2. 10 GiB free -> 2048 MiB swap -------------------------------------
    $case = $tmp . '/c2';
    @mkdir($case, 0777, true);
    file_put_contents($case . '/swaps', $emptySwaps);
    file_put_contents($case . '/meminfo', $mem2G);
    file_put_contents($case . '/fstab', "# fstab\n");
    $r = $run([
        'SWAP_FILE'       => $case . '/swapfile',
        'SWAP_FSTAB'      => $case . '/fstab',
        'SWAP_PROC_SWAPS' => $case . '/swaps',
        'SWAP_MEMINFO'    => $case . '/meminfo',
        'SWAP_FREE_MB'    => 10000,
    ]);
    check($r['rc'] === 0 && strpos($r['log'], 'fallocate -l 2048M ') !== false, '10 GiB free selects 2048 MiB swap');

    // --- 3. 6 GiB free -> 1024 MiB swap --------------------------------------
    $case = $tmp . '/c3';
    @mkdir($case, 0777, true);
    file_put_contents($case . '/swaps', $emptySwaps);
    file_put_contents($case . '/meminfo', $mem2G);
    file_put_contents($case . '/fstab', "# fstab\n");
    $r = $run([
        'SWAP_FILE'       => $case . '/swapfile',
        'SWAP_FSTAB'      => $case . '/fstab',
        'SWAP_PROC_SWAPS' => $case . '/swaps',
        'SWAP_MEMINFO'    => $case . '/meminfo',
        'SWAP_FREE_MB'    => 6000,
    ]);
    check($r['rc'] === 0 && strpos($r['log'], 'fallocate -l 1024M ') !== false, '6 GiB free selects 1024 MiB swap');

    // --- 4. 4 GiB free -> skipped --------------------------------------------
    $case = $tmp . '/c4';
    @mkdir($case, 0777, true);
    file_put_contents($case . '/swaps', $emptySwaps);
    file_put_contents($case . '/meminfo', $mem2G);
    file_put_contents($case . '/fstab', "# fstab\n");
    $r = $run([
        'SWAP_FILE'       => $case . '/swapfile',
        'SWAP_FSTAB'      => $case . '/fstab',
        'SWAP_PROC_SWAPS' => $case . '/swaps',
        'SWAP_MEMINFO'    => $case . '/meminfo',
        'SWAP_FREE_MB'    => 4000,
    ]);
    check($r['rc'] === 0 && !file_exists($case . '/swapfile') && $r['log'] === '', '4 GiB free skips without creating swapfile');

    // --- 5. Swap already active -> no-op -------------------------------------
    $case = $tmp . '/c5';
    @mkdir($case, 0777, true);
    file_put_contents($case . '/swaps', $activeSwaps);
    file_put_contents($case . '/meminfo', $mem2G);
    file_put_contents($case . '/fstab', "# fstab\n");
    $r = $run([
        'SWAP_FILE'       => $case . '/swapfile',
        'SWAP_FSTAB'      => $case . '/fstab',
        'SWAP_PROC_SWAPS' => $case . '/swaps',
        'SWAP_MEMINFO'    => $case . '/meminfo',
        'SWAP_FREE_MB'    => 25000,
    ]);
    check($r['rc'] === 0 && !file_exists($case . '/swapfile') && $r['log'] === '', 'already active swap is a no-op');

    // --- 6. Existing swapfile path -> never overwritten ----------------------
    $case = $tmp . '/c6';
    @mkdir($case, 0777, true);
    file_put_contents($case . '/swaps', $emptySwaps);
    file_put_contents($case . '/meminfo', $mem2G);
    file_put_contents($case . '/fstab', "# fstab\n");
    file_put_contents($case . '/swapfile', 'KEEP_ME');
    $r = $run([
        'SWAP_FILE'       => $case . '/swapfile',
        'SWAP_FSTAB'      => $case . '/fstab',
        'SWAP_PROC_SWAPS' => $case . '/swaps',
        'SWAP_MEMINFO'    => $case . '/meminfo',
        'SWAP_FREE_MB'    => 25000,
    ]);
    check($r['rc'] === 0 && file_get_contents($case . '/swapfile') === 'KEEP_ME' && $r['log'] === '', 'existing swapfile is never overwritten');

    // --- 7. High-RAM server (16 GiB) -> skipped ------------------------------
    $case = $tmp . '/c7';
    @mkdir($case, 0777, true);
    file_put_contents($case . '/swaps', $emptySwaps);
    file_put_contents($case . '/meminfo', $mem16G);
    file_put_contents($case . '/fstab', "# fstab\n");
    $r = $run([
        'SWAP_FILE'       => $case . '/swapfile',
        'SWAP_FSTAB'      => $case . '/fstab',
        'SWAP_PROC_SWAPS' => $case . '/swaps',
        'SWAP_MEMINFO'    => $case . '/meminfo',
        'SWAP_FREE_MB'    => 25000,
    ]);
    check($r['rc'] === 0 && !file_exists($case . '/swapfile') && $r['log'] === '', '16 GiB RAM host skips swap step');

    // --- 8. Incompatible filesystem (btrfs / zfs) -> skipped -----------------
    foreach (['btrfs', 'zfs'] as $fs) {
        $case = $tmp . '/c8_' . $fs;
        @mkdir($case, 0777, true);
        file_put_contents($case . '/swaps', $emptySwaps);
        file_put_contents($case . '/meminfo', $mem2G);
        file_put_contents($case . '/fstab', "# fstab\n");
        $r = $run([
            'SWAP_FILE'       => $case . '/swapfile',
            'SWAP_FSTAB'      => $case . '/fstab',
            'SWAP_PROC_SWAPS' => $case . '/swaps',
            'SWAP_MEMINFO'    => $case . '/meminfo',
            'SWAP_FREE_MB'    => 25000,
            'SWAP_FS_TYPE'    => $fs,
        ]);
        check($r['rc'] === 0 && !file_exists($case . '/swapfile') && $r['log'] === '', $fs . ' filesystem skips swap step');
    }

    // --- 9. swapon denied (LXC/OpenVZ/Docker) -> rollback file & fstab -------
    $case = $tmp . '/c9';
    @mkdir($case, 0777, true);
    file_put_contents($case . '/swaps', $emptySwaps);
    file_put_contents($case . '/meminfo', $mem2G);
    file_put_contents($case . '/fstab', "# fstab\n");
    $r = $run([
        'SWAP_FILE'       => $case . '/swapfile',
        'SWAP_FSTAB'      => $case . '/fstab',
        'SWAP_PROC_SWAPS' => $case . '/swaps',
        'SWAP_MEMINFO'    => $case . '/meminfo',
        'SWAP_FREE_MB'    => 25000,
        'SWAPON_BIN'      => $binDir . '/swapon-fail',
    ]);
    check($r['rc'] === 0, 'swapon failure is non-fatal (exit 0)');
    check(!file_exists($case . '/swapfile'), 'swapon failure removes the allocated swapfile (rollback)');
    check(file_get_contents($case . '/fstab') === "# fstab\n", 'swapon failure leaves fstab untouched');

    // --- 10. mkswap failure -> rollback file & fstab -------------------------
    $case = $tmp . '/c10';
    @mkdir($case, 0777, true);
    file_put_contents($case . '/swaps', $emptySwaps);
    file_put_contents($case . '/meminfo', $mem2G);
    file_put_contents($case . '/fstab', "# fstab\n");
    $r = $run([
        'SWAP_FILE'       => $case . '/swapfile',
        'SWAP_FSTAB'      => $case . '/fstab',
        'SWAP_PROC_SWAPS' => $case . '/swaps',
        'SWAP_MEMINFO'    => $case . '/meminfo',
        'SWAP_FREE_MB'    => 25000,
        'MKSWAP_BIN'      => $binDir . '/mkswap-fail',
    ]);
    check($r['rc'] === 0 && !file_exists($case . '/swapfile') && file_get_contents($case . '/fstab') === "# fstab\n",
        'mkswap failure removes swapfile, keeps fstab unchanged, exits 0');

    // --- 11. fallocate fails -> dd fallback succeeds (1 MiB test file) -------
    $case = $tmp . '/c11';
    @mkdir($case, 0777, true);
    file_put_contents($case . '/swaps', $emptySwaps);
    file_put_contents($case . '/meminfo', $mem2G);
    file_put_contents($case . '/fstab', "# fstab\n");
    $r = $run([
        'SWAP_FILE'       => $case . '/swapfile',
        'SWAP_FSTAB'      => $case . '/fstab',
        'SWAP_PROC_SWAPS' => $case . '/swaps',
        'SWAP_MEMINFO'    => $case . '/meminfo',
        'SWAP_FREE_MB'    => 25000,
        'SWAP_SIZE_MB'    => 1,
        'FALLOCATE_BIN'   => $binDir . '/fallocate-fail',
    ]);
    check($r['rc'] === 0 && is_file($case . '/swapfile') && filesize($case . '/swapfile') === 1048576,
        'when fallocate fails, dd fallback allocates the exact requested size');
    check((fileperms($case . '/swapfile') & 0777) === 0600, 'dd fallback swapfile has mode 0600');

    // --- 12. fstab already has the entry -> no duplicate ---------------------
    $case = $tmp . '/c12';
    @mkdir($case, 0777, true);
    file_put_contents($case . '/swaps', $emptySwaps);
    file_put_contents($case . '/meminfo', $mem2G);
    $existingLine = $case . "/swapfile none swap sw 0 0\n";
    file_put_contents($case . '/fstab', "# fstab\n" . $existingLine);
    $r = $run([
        'SWAP_FILE'       => $case . '/swapfile',
        'SWAP_FSTAB'      => $case . '/fstab',
        'SWAP_PROC_SWAPS' => $case . '/swaps',
        'SWAP_MEMINFO'    => $case . '/meminfo',
        'SWAP_FREE_MB'    => 25000,
    ]);
    check(substr_count((string) file_get_contents($case . '/fstab'), $case . '/swapfile') === 1,
        'fstab entry is not duplicated when already present');

    // --- 13. server_setup.sh wires server_setup_swap.sh ----------------------
    $setupSh = (string) file_get_contents($repoRoot . '/cli/server_setup.sh');
    check(strpos($setupSh, 'server_setup_swap.sh') !== false, 'cli/server_setup.sh invokes server_setup_swap.sh');
    check(strpos((string) file_get_contents($repoRoot . '/install.sh'), 'server_setup_swap') === false,
        'install.sh does NOT duplicate the swap step (it calls server_setup.sh once)');
} catch (\Throwable $e) {
    check(false, 'unexpected exception: ' . $e->getMessage());
}

echo $failures === 0 ? "Server setup swap tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
