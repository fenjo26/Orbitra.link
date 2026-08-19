# ORB-008 — router.php is dead code, and may be hiding two access guards

- **Severity:** Low as cleanup — **potentially High as a security finding**, see below
- **Run:** wave 3, alone, after wave 2 is merged
- **Owns:** `index.php`, `router.php`, `install.sh`, `core/nginx_config.php`, `.htaccess`, `docs/`, `tests/`
- **No migration**

Not parallelisable: it touches `index.php` and every routing surface at once.

## Problem

There are two front controllers. `router.php` is the complete one and is also
the one that never runs: `install.sh` → `write_baseline_nginx_config()` and
`core/nginx_config.php` (~130) both emit
`location / { try_files $uri $uri/ /index.php?$query_string; }`, and every
`.htaccess` rule rewrites to `index.php`. `router.php` executes only under
`php -S host:port router.php`.

The routing table is therefore true in development and false in production.
That is exactly how ORB-001 shipped, and it will happen again to the next route
someone adds to `router.php`.

## Check this first — it may not be cleanup at all

`router.php` enforces two access rules that `index.php` may not:

- `domains.status = 'disabled'` → 404 for the entire host
- `domains.admin_access = 0` → `/admin`, `/admin.php` and `/api.php` return 404
  on that host, while tracking on it keeps working

If these live only in `router.php`, then **both are inoperative in production**,
and a parked domain that was meant to hide the admin panel is currently serving
it. Verify with real requests before writing any code. If confirmed, raise the
severity and fix that part first, separately from the cleanup.

## Decide and write it down

**Option A (recommended)** — `index.php` is the only front controller. Port every
missing route and guard from `router.php`, delete `router.php`, and change
`WALKTHROUGH.md` to start the dev server with `php -S 0.0.0.0:8000 index.php`.

**Option B** — nginx and Apache route to `router.php` as the single entry point.
Much larger blast radius: every location and rewrite in `install.sh`,
`core/nginx_config.php` and `.htaccess` changes, and every existing install needs
`cli/nginx_sync.php` re-run.

## Acceptance

- [ ] The decision (A or B) is recorded in `docs/`.
- [ ] `domains.status = 'disabled'` returns 404 for the whole host **under nginx**, verified with a real request.
- [ ] `domains.admin_access = 0` returns 404 for `/admin`, `/admin.php`, `/api.php` on that host under nginx, while tracking on it still works.
- [ ] Every route on the surviving controller has a test asserting it resolves.
- [ ] `grep -rn "router.php"` returns only intended references.
- [ ] `WALKTHROUGH.md` and `README` describe the actual production entry point.
