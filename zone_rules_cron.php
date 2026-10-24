<?php
// Orbitra — Zone Killer Cron
//
// Исполняет активные авто-правила блокировки зон (zone_rules.mode='auto').
// Правила в dry_run здесь не трогаются — их кандидаты видны в редакторе
// кампании, блокирует кнопка. Один проход:
//   кандидаты (расход/конверсии/ROI по зонам за окно правила, те же числа,
//   что в отчётах) → срез по max_blocks_per_run → блок через API сети →
//   журнал zone_blocks (acted_by='auto', с метриками решения) → Telegram.
//
// Использование:
//   php zone_rules_cron.php                  — все активные авто-правила
//   php zone_rules_cron.php --campaign=12    — только кампания #12
//   php zone_rules_cron.php --quiet          — без вывода
//
// Crontab example (каждый проход дешёвый: правила без кандидатов — это один
// GROUP BY по кликам кампании):
//   */30 * * * * php /var/www/orbitra/zone_rules_cron.php >> /var/log/orbitra_zone_rules.log 2>&1

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

$options = getopt('', ['campaign:', 'quiet']);
$isQuiet = isset($options['quiet']);

function zone_rules_log(string $msg): void
{
    global $isQuiet;
    if (!$isQuiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/ZoneRules.php';

zone_rules_log('=== Orbitra Zone Killer Cron Start ===');

try {
    $summary = ZoneRules::runAuto($pdo, isset($options['campaign']) ? (int) $options['campaign'] : null);
    foreach ($summary['details'] as $d) {
        $label = 'campaign #' . $d['campaign_id'];
        if (($d['status'] ?? '') === 'blocked') {
            zone_rules_log("[$label] ✓ blocked {$d['ok']} zone(s): " . implode(', ', $d['zones'] ?? []));
        } elseif (($d['status'] ?? '') === 'error') {
            zone_rules_log("[$label] ✗ ERROR: " . ($d['error'] ?? 'unknown'));
        } else {
            zone_rules_log("[$label] · {$d['status']}");
        }
    }
    zone_rules_log("=== Cron Complete. Auto rules: {$summary['rules']}, Zones blocked: {$summary['blocked']} ===");
    exit(0);
} catch (Throwable $e) {
    zone_rules_log('✗ FATAL: ' . $e->getMessage());
    exit(1);
}
