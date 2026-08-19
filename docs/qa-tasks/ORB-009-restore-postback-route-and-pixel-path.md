# ORB-009 — Restore the ORB-001 route and repair the pixel path

- **Severity:** Blocker
- **Run:** alone, on `main`, before any other task
- **Owns:** `index.php`, `postback.php`, `tests/`
- **Do not touch:** `api.php`, `config.php`, `core/**`, `frontend/**`, locales

## Why this exists

ORB-001 was implemented correctly and then destroyed: another agent working in
the same tree ran a file-level revert on `index.php` and took the postback route
with it. The agent's own test still reported green, because it checked that a
string existed in a file — and it ran before the revert. Three defects are on
disk right now as a result.

## 1. The postback route is gone

`grep -n "postback_key.*postback" index.php` returns nothing;
`git diff --stat index.php` is empty. The tester's original blocker is live.

Re-add the handler right after the `/click_api/v3` block (~line 1713), before
`/pixel.gif`:

```php
$pbKey = (string) ($postback_key ?? '');
if ($pbKey !== '' && ($uriPath === '/' . $pbKey . '/postback'
                   || $uriPath === '/' . $pbKey . '/postback/')) {
    require __DIR__ . '/postback.php';
    exit;
}
```

`$postback_key` is already overridden from the `settings` table by `config.php`
(~line 1840), so changing the key in the panel must take effect with no nginx
reload.

## 2. The pixel path is broken by a constant nothing defines

`postback.php` line 78 branches on `ORBITRA_INSIDE_PIXEL_GIF`. The `define()`
lived in the reverted `index.php`, so it never happens: today
`/pixel.gif?action=conversion` with a bad subid answers **400/404/500** instead
of a 200 GIF. This is a regression against pre-batch behaviour.

In the `/pixel.gif?action=conversion` branch (~line 1739):

- `define('ORBITRA_INSIDE_PIXEL_GIF', true);` before the `require`
- `http_response_code(200);` inside the shutdown closure, before the GIF is
  echoed — the buffer is already cleared there, the status code is not

## 3. Fixing 2 arms a second bug — do both in one pass

Once the constant is defined, `orbitraPostbackExit()` **returns** instead of
exiting on the pixel path, and no call site expects that:

```php
if (!$clickId) {
    orbitraPostbackExit(400, "Missing subid.", [...]);   // returns here
}
// execution continues with $clickId = null
```

Add `return;` after every `orbitraPostbackExit()` call in `postback.php`
(~lines 205, 210, 226, 246, 552). The success call at 552 also needs it — it
sits inside the `try` block, and falling through re-runs the S2S and CAPI
enqueue.

## 4. Collateral

- Recreate `tests/postback_status_codes_test.php` (deleted by the same revert).
- Delete the empty `database.db` in the repo root, left behind by a test run,
  and add it to `.gitignore` if it isn't covered.
- The tree currently holds uncommitted ORB-002/003/004 work. Commit that as
  separate, labelled commits **before** starting, so this fix is reviewable on
  its own.

## Acceptance — run these, do not grep for them

```bash
# 1. the route exists and records
curl -i "http://<host>/<postback_key>/postback?subid=<real click id>&status=lead&payout=10&currency=USD"
#    -> 200, "Postback recorded successfully.", one row in conversions

# 2. rejection reports itself honestly
curl -i "http://<host>/<postback_key>/postback?subid=nope&status=lead"
#    -> 404, and a row in incoming_postbacks_log with matched=0, result=rejected

# 3. wrong key is not a route
curl -i "http://<host>/wrongkey/postback?subid=x&status=lead"
#    -> not handled by postback.php

# 4. the pixel path survives all of it
curl -i "http://<host>/pixel.gif?action=conversion&subid=nope&status=lead"
#    -> 200, Content-Type: image/gif, valid GIF body
curl -i "http://<host>/pixel.gif?action=conversion&subid=<real click id>&status=lead"
#    -> 200, valid GIF, and the conversion is recorded
```

- [ ] All four checks pass against a running instance.
- [ ] Changing `postback_key` in Settings makes the new path work immediately and the old one stop, with no nginx reload.
- [ ] `tests/postback_route_test.php` asserts routing behaviour, not the presence of source lines.
