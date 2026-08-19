# ORB-008 Decision: router.php Consolidation

**Date**: 2026-08-19
**Severity**: HIGH (Security - two access guards are inoperative in production)

## Decision: Option A — index.php as the only front controller

We choose **Option A** because:
- nginx, Apache, and install.sh already route to `index.php`
- Smaller blast radius — no changes to every web server config
- No migration needed for existing installations
- Aligns with the actual production behavior

## What Happens

1. **`index.php`** becomes the sole front controller
2. **`router.php`** is deleted
3. **All routes and guards** from `router.php` are ported to `index.php`
4. **Dev server docs** change to `php -S 0.0.0.0:8000 index.php`

## Security Fix (Separate Priority Work)

Before consolidation, two access guards must be ported from `router.php` to `index.php`:

### 1. Disabled Domain Guard (router.php:15-19)

```php
// A manually disabled domain serves nothing: 404 the whole host before any routing
if ($domain && strtolower((string)($domain['status'] ?? 'OK')) === 'disabled') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('404 Not Found');
}
```

### 2. Admin Access Guard (router.php:21-31)

```php
// Admin panel visibility on this host.
$adminAllowed = !$domain || (int)($domain['admin_access'] ?? 1) === 1;
$orbitraDenyAdmin = static function (): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('404 Not Found');
};
```

Applied to:
- `/` when serving `admin.php` (line 80-82, 167)
- `/admin` path when `admin_path` is set (needs addition)
- `/api.php` (line 97-99, 130)

## Files to Modify

- `index.php` — Add both guards at the top of the request (after domain lookup)
- `core/admin_path.php` — Add `admin_access` check before serving admin
- `WALKTHROUGH.md` — Update dev server command
- `README.md` — Update dev server command if applicable
- Delete `router.php`

## Tests Required

For every route on the surviving controller:
- [ ] Test asserts route resolves correctly
- [ ] Test `domains.status = 'disabled'` returns 404
- [ ] Test `domains.admin_access = 0` returns 404 for admin routes
- [ ] Test `domains.admin_access = 0` allows tracking to continue
