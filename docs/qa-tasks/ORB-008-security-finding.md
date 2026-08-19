# ORB-008 Security Finding: Inoperative Access Guards

**Severity**: HIGH
**Type**: Access Control Bypass
**Status**: Confirmed
**Date**: 2026-08-19

## Summary

Two access control guards exist in the database schema and are enforced in `router.php`, but `router.php` never executes in production. All production traffic (nginx, Apache) routes to `index.php`, which does NOT implement these guards.

## Vulnerability Details

### 1. Disabled Domain Bypass (domains.status = 'disabled')

**Expected behavior**: A domain with `status = 'disabled'` should return 404 for ALL requests, including tracking.

**Actual behavior**: The disabled domain continues serving campaigns and tracking normally.

**Database schema**:
```sql
status TEXT DEFAULT 'OK'
```

**Code location of check**: `router.php:15-19`
```php
if ($domain && strtolower((string)($domain['status'] ?? 'OK')) === 'disabled') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('404 Not Found');
}
```

**Production routing**:
- nginx: `try_files $uri $uri/ /index.php?$query_string;` (core/nginx_config.php:131)
- Apache: All RewriteRules point to index.php
- **Result**: Guard never executes

### 2. Admin Access Bypass (domains.admin_access = 0)

**Expected behavior**: A domain with `admin_access = 0` should:
- Return 404 for `/admin`, `/admin.php`, `/api.php`
- Continue serving tracking (campaigns, postbacks, click API)

**Actual behavior**: The admin panel and API are fully accessible on the domain.

**Database schema**:
```sql
admin_access INTEGER DEFAULT 1
```

**Code location of check**: `router.php:21-31`
```php
$adminAllowed = !$domain || (int)($domain['admin_access'] ?? 1) === 1;
$orbitraDenyAdmin = static function (): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('404 Not Found');
};
```

Applied to:
- `/` root when serving admin.php (line 80-82)
- `/api.php` (line 97-99)
- `/admin` path (implied by comment)

**Production routing**: Same as above — guard never executes

## Impact

- **Disabled domains**: Cannot be taken offline properly; they continue serving and logging traffic
- **Admin exposure**: Parked domains intended to hide the admin panel expose it fully
- **Compliance**: Violates the principle of least privilege for parked domains

## Proof of Concept

1. Set a domain's `status = 'disabled'` in the database
2. Access the domain via HTTP to nginx/Apache
3. **Result**: Campaigns continue serving (should 404)

1. Set a domain's `admin_access = 0` in the database
2. Access `/admin.php` on that domain via HTTP to nginx/Apache
3. **Result**: Admin panel loads (should 404)

## Remediation

Port both guards from `router.php` to `index.php`:

### Location in index.php

After the domain lookup (around line 1665) and before any routing:

```php
// === DOMAIN ACCESS GUARDS ===
// These guards must run BEFORE any routing, tracking, or admin panel serving.

// 1. Disabled domain - nothing serves on a disabled host
if ($domainInfo && strtolower((string)($domainInfo['status'] ?? 'OK')) === 'disabled') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('404 Not Found');
}

// 2. Admin access control - panel/API hidden on parked domains
$adminAllowedOnHost = !$domainInfo || (int)($domainInfo['admin_access'] ?? 1) === 1;
```

Then use `$adminAllowedOnHost` when:
- Serving admin panel at `/` (replace current admin.php include)
- Serving `/api.php` (add guard before include)
- Serving via `core/admin_path.php` (pass the flag)

## Testing Requirements

After remediation, verify with real HTTP requests (not code inspection):

```bash
# Test 1: Disabled domain 404s
# UPDATE domains SET status='disabled' WHERE name='test.example.com';
curl -I http://test.example.com/
# Expected: HTTP/1.1 404 Not Found

# Test 2: Admin blocked on parked domain
# UPDATE domains SET admin_access=0 WHERE name='parked.example.com';
curl -I http://parked.example.com/admin.php
# Expected: HTTP/1.1 404 Not Found

# Test 3: Tracking continues on parked domain
curl http://parked.example.com/my-campaign-alias
# Expected: Campaign serves normally
```

## References

- Task: ORB-008
- Decision document: ORB-008-router-php-dead-code-decision.md
- Original issue docs: ORB-008-router-php-dead-code.md
