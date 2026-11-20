<?php
// Orbitra — Stream Rotation Optimiser Cron
//
// Автооптимизация ротации лендингов/офферов внутри стрима. Периодически
// пересчитывает веса по метрикам из core/ReportMetrics.php (те же числа, что
// в отчётах) и записывает их в schema_custom_json — роутер продолжает читать
// веса ровно так же, как раньше. Путь клика не меняется вообще.
// Вторым шагом тот же проход исполняет правила кампаний (core/CampaignRules.php),
// чтобы им не нужна была новая строка в crontab на уже установленных серверах.
//
// Использование:
//   php rotation_optimiser_cron.php                — обработать все due-стримы
//   php rotation_optimiser_cron.php --force        — игнорировать интервалы
//   php rotation_optimiser_cron.php --stream=12    — только стрим #12
//   php rotation_optimiser_cron.php --quiet        — без вывода
//
// Crontab example (the per-stream interval check skips non-due streams
// cheaply, so a */5 cadence is safe):
//   */5 * * * * php /var/www/orbitra/rotation_optimiser_cron.php >> /var/log/orbitra_rotation.log 2>&1

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

$options = getopt('', ['force', 'stream:', 'quiet']);
$isQuiet = isset($options['quiet']);

function rotation_log(string $msg): void
{
    global $isQuiet;
    if (!$isQuiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/RotationOptimiser.php';

rotation_log('=== Orbitra Rotation Optimiser Cron Start ===');

try {
    $summary = orbitraRunRotationOptimiser($pdo, [
        'force' => isset($options['force']),
        'stream_id' => isset($options['stream']) ? (int) $options['stream'] : null,
    ]);

    foreach ($summary['details'] as $d) {
        $label = 'stream #' . $d['stream_id'] . ' ' . $d['list'];
        if (($d['status'] ?? '') === 'error') {
            rotation_log("[$label] ✗ ERROR: " . ($d['error'] ?? 'unknown'));
        } elseif (($d['status'] ?? '') === 'ok_changed') {
            rotation_log("[$label] ✓ moved {$d['items']} item weight(s)");
        } else {
            rotation_log("[$label] · {$d['status']}");
        }
    }
    rotation_log("=== Cron Complete. Streams: {$summary['streams']}, Runs: {$summary['runs']}, Changed: {$summary['changed']}, Audit rows: {$summary['audit_rows']} ===");
} catch (Throwable $e) {
    rotation_log('✗ FATAL: ' . $e->getMessage());
    exit(1);
}

// Campaign rules ride this cron on purpose: it is installed on every server
// (every minute), so the rules need no new crontab line on existing installs.
// Each rule carries its own check interval; an idle pass is one SELECT.
if (isset($options['stream'])) {
    exit(0); // a single-stream debug run stays a single-stream run
}
try {
    require_once __DIR__ . '/core/CampaignRules.php';
    $rules = CampaignRules::runAll($pdo, ['force' => isset($options['force'])]);
    foreach ($rules['details'] as $d) {
        $label = 'rule #' . $d['rule_id'] . ' campaign #' . $d['campaign_id'];
        if (!empty($d['error'])) {
            rotation_log("[$label] ✗ ERROR: " . $d['error']);
        } elseif ($d['fired'] > 0) {
            rotation_log("[$label] ✓ fired on {$d['fired']} target(s), {$d['actions']} action(s)");
        }
    }
    if ($rules['rules'] > 0) {
        rotation_log("=== Campaign rules: checked {$rules['rules']}, fired {$rules['fired']}, actions {$rules['actions']} ===");
    }

    // Landing text A/B tests re-weigh themselves here too (every 30 min each).
    require_once __DIR__ . '/core/TextTests.php';
    $tt = TextTests::reweighAll($pdo);
    if ($tt['tests'] > 0) {
        rotation_log("=== Text tests: checked {$tt['tests']}, re-weighed {$tt['changed']}, new winners " . count($tt['winners']) . " ===");
    }
    exit(0);
} catch (Throwable $e) {
    rotation_log('✗ FATAL: ' . $e->getMessage());
    exit(1);
}
