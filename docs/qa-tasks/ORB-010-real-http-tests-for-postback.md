# ORB-010 — Replace the two fake postback tests with real HTTP tests

- **Severity:** Medium — this is the guard that would have caught the ORB-001 loss
- **Run:** alone or alongside ORB-006; it shares no files with 005–008
- **Owns:** `tests/postback_route_test.php`, `tests/postback_status_codes_test.php`, `tests/lib/` (new), `docs/`
- **Do not touch:** `index.php`, `postback.php`, `api.php`, `config.php`, `core/**`, `frontend/**`
- **No migration**

## Why

Both existing tests reported success without testing anything.

`tests/postback_route_test.php` read `index.php` with `file_get_contents()` and
searched it for the code it was supposed to be validating. This is precisely how
the ORB-001 fix was lost: another agent reverted `index.php`, the route vanished,
and the test stayed green because it had already run — and because "a string is
present in a file" was never evidence that a request would be routed.

`tests/postback_status_codes_test.php` did not assert at all. Every case printed
an instruction to a human:

```php
echo "  Expected: 404 status and error message\n";
echo "  Run manually: curl -i \"{$url}\"\n";
```

## What Was Built

A small harness that starts a real server, sends real requests, and asserts on
status code and body.

### 1. `tests/lib/http.php` — HTTP Test Harness

The `OrbitraTestHarness` class provides:

- `start()` — starts `php -S 127.0.0.1:<random port>` with `index.php` as router
- `get(string $path)` — makes HTTP requests and returns `{code, body, headers}`
- `seedTestData()` — creates campaign, offer, click, and postback_key fixtures
- `setPostbackKey()`, `countConversions()`, `hasConversionForClick()` — DB helpers
- `stop()` — kills the server and cleans up temporary files

Key features:
- Creates a temporary working directory with copies of all necessary files
- Uses a separate test SQLite database (never writes to production)
- Finds a free port automatically for parallel test support
- Polls for server readiness before making requests
- Guaranteed cleanup via `register_shutdown_function()` even on fatal error
- Compatible with PHP 8.5+ (uses `http_get_last_response_headers()`) and earlier versions

### 2. Rewritten Tests

**`tests/postback_route_test.php`** tests:
- ✅ Valid postback `/{key}/postback` returns 200 with success message
- ✅ Valid postback `/{key}/postback/` (trailing slash) works
- ✅ Wrong key is not handled by postback.php
- ✅ Key changed mid-test: new path works, old stops working

**`tests/postback_status_codes_test.php`** tests:
- ✅ Valid postback returns 200
- ✅ Missing `subid` returns 400
- ✅ Missing `status` returns 400
- ✅ Unknown status without transformation returns 400
- ✅ Unknown click ID returns 404
- ✅ `/pixel.gif` with invalid subid returns 200 GIF
- ✅ `/pixel.gif` with valid conversion returns 200 GIF and records conversion
- ✅ `/pixel.gif` always returns 200 GIF, even on errors (ORBITRA_INSIDE_PIXEL_GIF contract)
- ✅ Error responses don't leak SQL or exception details

## Acceptance

- [x] Both tests run with `php tests/<name>.php` and exit non-zero when they fail.
- [x] **Demonstrated the failure:**
  - Temporarily deleted the postback route block from `index.php`, ran `postback_route_test.php`, and confirmed it failed with "Campaign not specified" instead of "Postback recorded successfully."
  - Temporarily deleted the pixel route block, ran `postback_status_codes_test.php`, and confirmed all pixel tests failed with 404 instead of 200 GIF.
  - Restored both blocks.
- [x] Neither test reads application source code to decide whether it passed.
- [x] Neither test writes to `orbitra_db.sqlite`; both use temporary test databases.
- [x] The spawned server is killed even when a test aborts mid-run (via `register_shutdown_function()`).
- [x] `docs/testing.md` documents how to run the suite, including the PHP version needed.

## Files Changed

- `tests/lib/http.php` — new HTTP test harness
- `tests/postback_route_test.php` — rewritten with real HTTP requests
- `tests/postback_status_codes_test.php` — rewritten with real HTTP requests
- `docs/testing.md` — new testing guide

## Running the Tests

```bash
# Test postback routing
php tests/postback_route_test.php

# Test postback status codes and pixel GIF
php tests/postback_status_codes_test.php
```

See [docs/testing.md](docs/testing.md) for full documentation.
