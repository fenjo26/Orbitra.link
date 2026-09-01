<?php
// cli/generate_vapid.php
//
// Generates (or replaces) the tracker's own VAPID keypair for the push
// subscriber base and stores it in the settings table. Run once before the
// first PWA starts collecting subscribers:
//
//   php cli/generate_vapid.php          # generate only if no keys exist
//   php cli/generate_vapid.php --force  # replace existing keys
//
// WARNING on --force: a new private key invalidates every existing
// subscription — browsers refuse payloads signed with a different key and the
// whole base would have to re-subscribe. Only rotate deliberately.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/PushBase.php';

$force = in_array('--force', $argv ?? [], true);

$existing = PushBase::getKeys($pdo);
if ($existing !== [] && !$force) {
    echo "VAPID keys already exist. Public key:\n  " . $existing['public'] . "\n";
    echo "Use --force to replace them (invalidates every existing subscription).\n";
    exit(0);
}

$keys = PushBase::generateKeys();
PushBase::storeKeys($pdo, $keys);

echo "VAPID keypair generated and stored.\n";
echo "Public key (serve to browsers as applicationServerKey):\n  " . $keys['public'] . "\n";
if ($force) {
    echo "NOTE: keys were replaced — previously subscribed browsers must re-subscribe.\n";
}
