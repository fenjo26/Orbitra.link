# Testing Guide

## Running Tests

Orbitra includes real HTTP integration tests that start a PHP server and make actual HTTP requests to verify the application works end-to-end.

### Requirements

- **PHP**: 8.0 or higher (8.5+ recommended)
- The PHP binary must be in your PATH or available at standard paths:
  - `/opt/homebrew/bin/php` (Homebrew on macOS)
  - `/usr/local/bin/php`
  - `/usr/bin/php`

### Running Individual Test Suites

#### Postback Route Tests

Tests the postback endpoint routing (`/{postback_key}/postback`):

```bash
php tests/postback_route_test.php
```

This test verifies:
- Valid postback requests with the correct key are routed to `postback.php`
- The route works with and without trailing slashes
- Wrong keys are not handled by the postback handler
- The postback key can be changed in settings mid-test

#### Postback Status Codes Tests

Tests that postback.php returns correct HTTP status codes:

```bash
php tests/postback_status_codes_test.php
```

This test verifies:
- Valid postbacks return 200
- Missing `subid` returns 400
- Missing `status` returns 400
- Unknown status with no transformation returns 400
- Unknown click ID returns 404
- The `/pixel.gif` path always returns 200 with a valid GIF, even on errors
- Error responses don't leak SQL or exception details

### Test Architecture

The tests use the `OrbitraTestHarness` class ([tests/lib/http.php](tests/lib/http.php)) which:

1. **Creates a temporary working directory** with copies of the application files
2. **Starts a real PHP server** on a random port using `php -S`
3. **Sets up a test SQLite database** with seeded fixtures
4. **Makes real HTTP requests** using `file_get_contents()`
5. **Asserts on status code, body, and headers**
6. **Cleans up** by killing the server and removing temporary files

The harness automatically:
- Finds a free port so tests can run in parallel
- Polls for server readiness before making requests
- Ensures the server is killed even on fatal error via `register_shutdown_function()`
- Uses a separate test database that never writes to the production `orbitra_db.sqlite`

### Test Fixtures

Each test seeds a fresh database with:
- One campaign with a known ID
- One offer with a known ID
- One click with a known ID
- A configurable `postback_key` setting

This ensures tests are isolated and repeatable.

### PHP Version Notes

The tests use `http_get_last_response_headers()` on PHP 8.5+ and fall back to the legacy `$http_response_header` variable on earlier versions. Both approaches work correctly.

### Troubleshooting

**"PHP binary not found"**
- Ensure PHP is installed and in your PATH, or create a symlink at one of the expected paths.

**"Could not find a free port"**
- This is rare and occurs if many ports are in use. The test tries 50 random ports between 20000-65000.

**Server fails to start within timeout**
- Check that `php -S` is available and working on your system.
- Verify there are no conflicting servers running.

**Test passes but should fail**
- The tests are now real HTTP tests and will correctly fail when routes are broken or endpoints return wrong status codes.
- To verify: temporarily comment out the postback route block in [index.php](index.php) and run `tests/postback_route_test.php` — it should fail.
