#!/usr/bin/php
<?php
/**
 * Show, change or clear the secret admin path.
 *
 * The way back in when the secret path was forgotten — which is the one real
 * risk of using it, so it is deliberately a single command with no arguments to
 * remember:
 *
 *     php /var/www/orbitra/cli/admin_path.php            show the current path
 *     php /var/www/orbitra/cli/admin_path.php reset      back to /admin.php
 *     php /var/www/orbitra/cli/admin_path.php my-panel   move the panel there
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/admin_path.php';

$errors = [
    'admin_path_invalid' => 'Use 3-64 characters: lowercase letters, digits, "-" and "_", starting with a letter or digit.',
    'admin_path_reserved' => 'That name is used by the tracker itself. Pick another.',
    'admin_path_alias_taken' => 'A campaign already uses that alias — it would shadow the campaign.',
];

$current = orbitraAdminPath($pdo);
$arg = $argv[1] ?? null;

if ($arg === null) {
    if ($current === '') {
        echo "Admin panel: /admin.php (no secret path configured)\n";
    } else {
        echo "Admin panel: /$current\n";
        echo "/admin.php returns 404 while a secret path is set.\n";
    }
    echo "\nChange it:  php " . __FILE__ . " <new-path>\n";
    echo "Reset it:   php " . __FILE__ . " reset\n";
    exit(0);
}

$requested = ($arg === 'reset' || $arg === 'clear' || $arg === 'default') ? '' : $arg;

$check = orbitraValidateAdminPath($pdo, $requested);
if (!$check['ok']) {
    fwrite(STDERR, ($errors[$check['error']] ?? $check['error']) . "\n");
    exit(1);
}

$pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('admin_path', ?, datetime('now'))")
    ->execute([$check['value']]);

if ($check['value'] === '') {
    echo "Secret path cleared. The panel is back at /admin.php\n";
} else {
    echo "Admin panel moved to /{$check['value']}\n";
    echo "/admin.php now returns 404.\n";
}

exit(0);
