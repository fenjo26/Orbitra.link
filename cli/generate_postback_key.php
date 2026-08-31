<?php
// cli/generate_postback_key.php
//
// The postback key that ships in the repository is public — anyone can read
// the fd12e72 default in the open repo — so an install that keeps it accepts
// forged conversions on /fd12e72/postback from anyone who knows the install
// is unmodified. install.sh runs this script right after handing the app to
// www-data: it boots the database (a fresh install has none until the first
// request) and replaces the default key with a random one, then prints the
// effective key to STDOUT so install.sh can show the finished postback URL.
// A key the operator already changed is kept as-is, so re-running the
// installer never breaks live postback URLs.

require_once __DIR__ . '/../config.php';

// Mirrors the placeholder in config.php ($postback_key). Keep in sync.
$publicDefault = 'fd12e72';

$key = bin2hex(random_bytes(12)); // 24 hex chars — URL-safe, no path ambiguity
$row = $pdo->query("SELECT value FROM settings WHERE key = 'postback_key'")->fetchColumn();

if ($row === false) {
    // The migrations seed this row; reaching it missing means seeding was
    // interrupted — insert the key so the route is never left on the default.
    $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?)")
        ->execute(['postback_key', $key]);
} elseif ($row === $publicDefault) {
    $pdo->prepare("UPDATE settings SET value = ? WHERE key = 'postback_key'")
        ->execute([$key]);
} else {
    // Operator already rotated the key; keep theirs.
    $key = $row;
}

echo $key;
