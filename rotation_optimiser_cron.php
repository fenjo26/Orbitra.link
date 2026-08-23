<?php
// Orbitra — Stream Rotation Optimiser Cron
//
// Автооптимизация ротации лендингов/офферов внутри стрима. Периодически
// пересчитывает веса по метрикам из core/ReportMetrics.php (те же числа, что
// в отчётах) и записывает их в schema_custom_json — роутер продолжает читать
// веса ровно так же, как раньше. Путь клика не меняется вообще.
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
    exit(0);
} catch (Throwable $e) {
    rotation_log('✗ FATAL: ' . $e->getMessage());
    exit(1);
}
