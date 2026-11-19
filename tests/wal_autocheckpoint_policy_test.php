<?php
/**
 * ТЗ 1.9.7 — the WAL autocheckpoint policy table (core/wal_policy.php).
 *
 * SQLite's auto-checkpoint hook fires on every commit while the TOTAL WAL
 * size is over the threshold (sqlite3WalDefaultHook compares the total log,
 * not the backlog), so under sustained load a threshold on web connections
 * turned into an inline PASSIVE checkpoint inside every click (stand round:
 * checkpointer byte held by php-fpm, WAL 6.8 GB, restarts_ok = 0). The
 * policy: only the spool worker checkpoint-checkpoints; everyone else runs
 * bare while its heartbeat is fresh, and the 10000-page fuse returns when
 * the heartbeat goes stale (dead cron) — see DIAG_CKPT_B.
 *
 * Usage: php tests/wal_autocheckpoint_policy_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../core/wal_policy.php';

$failures = 0;
function check($condition, string $label): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

// Web + fresh pulse → no autocheckpoint (the background worker owns folding).
check(orbitraWalAutocheckpointPolicy(0, false, 'index.php') === 0, 'web, pulse age 0 → 0');
check(orbitraWalAutocheckpointPolicy(9, false, 'index.php') === 0, 'web, pulse age 9 s → 0');
check(orbitraWalAutocheckpointPolicy(orbitraWalPulseFreshSeconds(), false, 'index.php') === 0, 'web, pulse age exactly the threshold → 0');
// Stale or missing pulse → the 10000-page fuse returns (dead-cron installs).
check(orbitraWalAutocheckpointPolicy(orbitraWalPulseFreshSeconds() + 1, false, 'index.php') === 10000, 'web, pulse one second past the threshold → 10000');
check(orbitraWalAutocheckpointPolicy(600, false, 'index.php') === 10000, 'web, stale pulse → 10000');
check(orbitraWalAutocheckpointPolicy(null, false, 'index.php') === 10000, 'web, no heartbeat file yet → 10000 (fuse exists before the first tick)');
// CLI: only the spool worker keeps SQLite's default; every other cron/tool
// runs without an inline auto-checkpoint.
check(orbitraWalAutocheckpointPolicy(null, true, 'click_spool_cron.php') === 1000, 'cli spool worker keeps the SQLite default (1000)');
check(orbitraWalAutocheckpointPolicy(0, true, 'rotation_optimiser_cron.php') === 0, 'cli rotation cron → 0');
check(orbitraWalAutocheckpointPolicy(0, true, 'postback_queue_cron.php') === 0, 'cli postback queue cron → 0');
check(orbitraWalAutocheckpointPolicy(0, true, 'wal_autocheckpoint_policy_test.php') === 0, 'cli test script → 0');
// The env override wins on web regardless of the pulse (A/B knob).
check(orbitraWalAutocheckpointPolicy(0, false, 'index.php', 0) === 0, 'env 0 with fresh pulse → 0');
check(orbitraWalAutocheckpointPolicy(600, false, 'index.php', 0) === 0, 'env 0 with stale pulse → 0 (override wins)');
check(orbitraWalAutocheckpointPolicy(600, false, 'index.php', 5000) === 5000, 'env 5000 → 5000');
check(orbitraWalAutocheckpointPolicy(600, true, 'rotation_optimiser_cron.php', 1000) === 0, 'env does not touch CLI policy');
// Threshold sanity.
check(orbitraWalPulseFreshSeconds() === 10, 'freshness threshold is 10 s (per-pass heartbeat, ~5 s tick seam)');

echo $failures === 0 ? "WAL autocheckpoint policy tests passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
