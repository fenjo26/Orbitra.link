<?php
// cli/stats_freeze_cron.php
//
// Thin standalone wrapper around the freeze tick (core/stats_freeze.php).
// NO crontab line is needed on regular installs: cli/click_spool_cron.php —
// already scheduled every minute on every install — calls the same
// orbitraStatsFreezeTick() after its spool replay, under the same
// var/locks/stats_freeze.lock, so the two entries never double-tick.
//
// This wrapper exists for load stands and manual runs:
//   php cli/stats_freeze_cron.php [--quiet] [--budget=55] [--max-hours=0]
//
// See core/stats_freeze.php for the full contract (watermark semantics,
// phases, budget rules).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/stats_freeze.php';

$options = getopt('', ['quiet', 'budget:', 'max-hours:']);
$isQuiet = isset($options['quiet']);
$budgetOpt = $options['budget'] ?? getenv('ORBITRA_FREEZE_BUDGET');
$budgetSeconds = is_numeric($budgetOpt) ? max(0.0, (float) $budgetOpt) : 55.0;
$maxHours = isset($options['max-hours']) ? max(0, (int) $options['max-hours']) : 0;

orbitraStatsFreezeTick($pdo, $budgetSeconds, $maxHours, $isQuiet ? null : static function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
});
