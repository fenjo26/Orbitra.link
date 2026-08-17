#!/usr/bin/php
<?php
/**
 * Reset user password or manage admin accounts from CLI.
 *
 * Usage:
 *     php cli/reset_password.php                           # List users and usage help
 *     php cli/reset_password.php <username> <new_password>  # Reset password for username
 *     php cli/reset_password.php create <user> <password>  # Create a new admin user
 *     php cli/reset_password.php unblock                   # Clear login rate limits
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));

require_once __DIR__ . '/../config.php';

$action = $argv[1] ?? null;
$arg2 = $argv[2] ?? null;
$arg3 = $argv[3] ?? null;

// Function to clear rate limits
function clearRateLimits($pdo) {
    try {
        $pdo->exec("DELETE FROM rate_limits");
    } catch (\Throwable $e) {
        // Table might not exist yet
    }
    if (extension_loaded('redis') && class_exists('Redis')) {
        try {
            $redis = new Redis();
            if (@$redis->connect('127.0.0.1', 6379)) {
                $keys = $redis->keys("ratelimit:login:*");
                if ($keys) {
                    $redis->del($keys);
                }
            }
        } catch (\Throwable $e) {}
    }
}

if ($action === 'unblock') {
    clearRateLimits($pdo);
    echo "✓ All login rate limits cleared.\n";
    exit(0);
}

if ($action === 'create') {
    $username = trim($arg2 ?? '');
    $password = $arg3 ?? '';

    if (strlen($username) < 3 || strlen($password) < 6) {
        fwrite(STDERR, "Error: Username must be >= 3 chars and password >= 6 chars.\n");
        fwrite(STDERR, "Usage: php " . __FILE__ . " create <username> <password>\n");
        exit(1);
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Check if user already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        fwrite(STDERR, "Error: User '{$username}' already exists. Use: php " . __FILE__ . " {$username} {$password}\n");
        exit(1);
    }

    $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, is_active, timezone, language, permissions_json) VALUES (?, ?, ?, 'admin', 1, 'UTC', 'en', ?)");
    $stmt->execute([
        $username,
        $hashedPassword,
        $username . '@localhost',
        json_encode(['can_delete_offers' => true, 'can_delete_campaigns' => true, 'can_manage_users' => true])
    ]);

    clearRateLimits($pdo);
    echo "✓ Admin user '{$username}' created successfully!\n";
    echo "  You can now log in at /admin.php with this username and password.\n";
    exit(0);
}

// If username and password provided directly: php cli/reset_password.php admin mypassword
if ($action !== null && $arg2 !== null) {
    $username = trim($action);
    $password = $arg2;

    if (strlen($password) < 6) {
        fwrite(STDERR, "Error: Password must be at least 6 characters.\n");
        exit(1);
    }

    $stmt = $pdo->prepare("SELECT id, username, role, is_active FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    if ($user) {
        $pdo->prepare("UPDATE users SET password = ?, is_active = 1 WHERE id = ?")
            ->execute([$hashedPassword, $user['id']]);
        clearRateLimits($pdo);
        echo "✓ Password for user '{$username}' updated successfully!\n";
        echo "  Account activated and rate limits cleared. You can now log in.\n";
        exit(0);
    } else {
        // User not found - offer to create or inform
        echo "User '{$username}' not found. Creating admin account...\n";
        $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, is_active, timezone, language, permissions_json) VALUES (?, ?, ?, 'admin', 1, 'UTC', 'en', ?)");
        $stmt->execute([
            $username,
            $hashedPassword,
            $username . '@localhost',
            json_encode(['can_delete_offers' => true, 'can_delete_campaigns' => true, 'can_manage_users' => true])
        ]);
        clearRateLimits($pdo);
        echo "✓ Admin user '{$username}' created successfully!\n";
        echo "  You can now log in at /admin.php with this username and password.\n";
        exit(0);
    }
}

// Default action: List users and show help
$stmt = $pdo->query("SELECT id, username, email, role, is_active, last_login, created_at FROM users ORDER BY id ASC");
$users = $stmt->fetchAll();

echo "========================================\n";
echo " Orbitra User Management & Password Reset\n";
echo "========================================\n\n";

if (empty($users)) {
    echo "No users found in database.\n";
    echo "To create the initial admin user, run:\n";
    echo "  php " . __FILE__ . " admin your_secure_password\n\n";
} else {
    echo "Existing users:\n";
    foreach ($users as $u) {
        $status = $u['is_active'] ? 'active' : 'INACTIVE';
        $lastLogin = $u['last_login'] ?: 'never';
        echo sprintf(" - ID: %-3d | Login: %-15s | Role: %-8s | Status: %-8s | Last login: %s\n", 
            $u['id'], $u['username'], $u['role'], $status, $lastLogin);
    }
    echo "\n";
}

echo "Commands:\n";
echo "  php " . __FILE__ . " <username> <new_password>   # Reset password for existing user\n";
echo "  php " . __FILE__ . " create <username> <password>  # Create a new admin user\n";
echo "  php " . __FILE__ . " unblock                       # Reset login attempt rate limits\n";
echo "\n";
