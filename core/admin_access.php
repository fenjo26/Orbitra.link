<?php

// Admin-surface visibility per request host (domains.admin_access = 0).
//
// A parked domain with admin_access=0 keeps tracking (campaign aliases, /r/,
// postback, click API) but the panel and api.php answer 404, so the admin
// surface stays on the hosts the operator manages the tracker from. router.php
// (dev routing) enforces the same rule inline; these helpers are the shared
// implementation for admin.php and api.php, which production reaches without
// the router.

/** Normalize a Host header for the domains lookup: lowercase, no port, no trailing dot. */
function orbitraNormalizeHost($host): string
{
    $host = strtolower(trim((string) $host));
    $host = preg_replace('/:\d+$/', '', $host);
    return rtrim($host, '.');
}

/**
 * Whether this host may serve the admin surface. Hosts with no domains row
 * (localhost, the bare server IP, fresh installs) are unaffected — the flag
 * only exists on parked domains and defaults to allowed.
 */
function orbitraHostAdminAllowed(PDO $pdo, $host): bool
{
    $host = orbitraNormalizeHost($host);
    if ($host === '') {
        return true;
    }
    try {
        $stmt = $pdo->prepare("SELECT admin_access FROM domains WHERE name = ? LIMIT 1");
        $stmt->execute([$host]);
        $adminAccess = $stmt->fetchColumn();
        $stmt->closeCursor();
    } catch (\Throwable $e) {
        // A lookup failure must not take the panel down; fail open like a
        // missing row would.
        return true;
    }
    return $adminAccess === false || (int) $adminAccess === 1;
}

/** The 404 a host with admin_access=0 gets for every admin-surface request. */
function orbitraDenyAdminHost(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('404 Not Found');
}
