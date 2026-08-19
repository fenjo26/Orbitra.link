# ORB-007 — `CF-Connecting-IP` is not read, so clicks behind Cloudflare log the edge IP

- **Severity:** Medium
- **Run:** wave 2, worktree `../orbitra-orb007`, branch `orb-007`
- **Owns:** `core/ip_access.php`, `index.php` (**line ~35 only**), `click.php`, `core/click_api.php`, `core/LeadForge.php`, `tests/client_ip_resolution_test.php`
- **Do not touch:** `postback.php`, `api.php`, `config.php`, `router.php`, `install.sh`, `frontend/**`, locales
- **No migration**

⚠️ `index.php` was changed by ORB-009 (postback route ~1713, pixel path ~1739).
Rebase onto `main` before you start and leave those blocks alone. You replace
exactly one thing in that file: the `$ipKeys` resolution at ~line 35.

## Problem

Orbitra actively encourages putting tracking domains behind Cloudflare — the
Edit Domain dialog has a proxy toggle. Behind it the origin sees Cloudflare's
edge address and the real visitor IP arrives in `CF-Connecting-IP`. `index.php`,
the front controller that logs every click, does not read that header:

```php
// index.php ~35
$ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED',
           'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED',
           'REMOTE_ADDR'];
```

`X-Forwarded-For` usually rescues it by accident, but `HTTP_CLIENT_IP` is
checked **first** and is fully attacker-controlled. Visitor IP drives geo
targeting, bot filtering, IP-range blocking and the IP written onto the lead
sent to the network — a spoofable first entry is the real problem here.

Four different orderings of the same list exist in one codebase:

| file | reads CF-Connecting-IP |
|---|---|
| `core/ip_access.php` (~161) | yes — use as the reference |
| `core/LeadForge.php` (~1049) | yes |
| `index.php` (~35) | **no** |
| `click.php` (~82) | **no** |
| `core/click_api.php` (~75) | **no** |

## Fix

Extract one `orbitraClientIp()` from the `core/ip_access.php` implementation and
call it from all of the above. Precedence: `CF-Connecting-IP` **only when
`REMOTE_ADDR` is inside a Cloudflare range** (`CloudDetector::isCloudflareIp()`)
→ leftmost public entry of `X-Forwarded-For` from a trusted proxy →
`REMOTE_ADDR`. Drop `HTTP_CLIENT_IP`, or accept it only from a configured
trusted-proxy list. Validate every candidate with `FILTER_VALIDATE_IP`.

## Acceptance

- [ ] A click through a Cloudflare-proxied domain logs the real visitor IP and correct geo.
- [ ] A **direct** request carrying a forged `CF-Connecting-IP` is ignored.
- [ ] A forged `X-Forwarded-For` or `Client-IP` on a direct connection cannot change the logged IP.
- [ ] A direct non-proxied click behaves exactly as before.
- [ ] One implementation, five call sites.
- [ ] `tests/client_ip_resolution_test.php` covers: direct, behind Cloudflare, forged headers direct, forged headers behind Cloudflare.
