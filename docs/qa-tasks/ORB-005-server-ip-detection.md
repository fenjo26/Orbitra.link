# ORB-005 — Server-IP detection is duplicated ×3 and can resolve to a private address

- **Severity:** Medium
- **Run:** wave 2, worktree `../orbitra-orb005`, branch `orb-005`
- **Owns:** `core/server_ip.php` (new), `api.php` (the three server-IP blocks only), `core/DomainDnsResolver.php`, `frontend/src/components/Domains.jsx`, `tests/server_ip_detection_test.php`, locales (`domains.*` keys only)
- **Do not touch:** `index.php`, `postback.php`, `router.php`, `api.php` case `'logs'`, `LogsPage.jsx`, `config.php`
- **No migration** — use the `settings` table

## Problem

The "what is my own public IP" routine is copy-pasted into three `api.php`
handlers: `case 'domains'` (~6397), `case 'check_domain_dns'` (~6555),
`case 'force_check_all_dns'` (~6676). Its first strategy is
`$_SERVER['SERVER_ADDR']`, which behind NAT, Docker or a load balancer is a
private address. When that happens every domain is compared against the wrong
value and the page reports `Awaiting DNS` — while the banner above the table
still shows the correct public IP, because that number comes from a different
code path. The two can disagree, and the user has no way to tell.

Two further faults in the same chain:

- The `127.0.0.1` fallback is read as "active" by the callers, so failing to
  determine the server IP silently marks **every** domain as connected.
- The EC2-metadata / `checkip.amazonaws.com` branch is only reached when neither
  `SERVER_ADDR` nor `HTTP_HOST` is set, which never happens during a web
  request. It is dead code.

## Fix

Extract `orbitraDetectServerIp()` into `core/server_ip.php`, used by all three
handlers **and** by whatever populates the Domains banner, so the number shown
and the number compared are the same value by construction.

Strategy order, public-first: cache file → `settings` override → cloud metadata
→ `checkip.amazonaws.com` → `SERVER_ADDR` only if public → fail. Validate with
`FILTER_VALIDATE_IP` + `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`.
On failure return an explicit failure; callers report `unknown`, never `active`.
Add a manual Server IP override field in Settings.

## Context from ORB-004, which already landed

Those three handlers now call `orbitraResolveDomainDnsState()` from
`core/DomainDnsResolver.php`. Feed your helper's result into it — do not
reintroduce inline IP comparison.

## Acceptance

- [ ] One function; all three duplicated blocks are gone.
- [ ] The banner IP and the compared IP are provably the same value.
- [ ] With `SERVER_ADDR=172.17.0.2`, detection still returns the public IP.
- [ ] Detection failure marks domains `unknown`, never `active`.
- [ ] A manual override beats every autodetection strategy.
- [ ] One page load performs at most one external lookup.
- [ ] `tests/server_ip_detection_test.php` covers private `SERVER_ADDR`, override set, total failure.
