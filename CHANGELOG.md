# Changelog

All notable changes to **Orbitra Tracker** are listed here. The full release
notes for each version also live in [README.md](README.md) (English) and
[README.ru.md](README.ru.md) (Russian) under the *What's New* / *Что нового*
sections.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.9.6.9] — 2026-08-10

### Fixed
- **The update button failed with `unable to unlink old
  'frontend/dist/assets/index.js': Permission denied` on every install made by
  `install.sh`.** The script chowned the tree to `www-data` and *then* built the
  frontend as root. Vite empties and recreates `frontend/dist` on each build, so
  the bundle and its directory came out root-owned — and replacing a file
  requires write permission on its directory, not on the file, so `git pull`
  running as the web user could never get past it. The same applied to `.git`
  and `mcp/node_modules`. The chown now runs last, after the frontend build and
  the MCP dependency install. Existing installs need one manual
  `chown -R www-data:www-data /var/www/orbitra`; after that the button works.
- **`run_update` passed git's raw permission error through.** It already
  translates "dubious ownership" into an actionable message; a permission
  failure now gets the same treatment — the cause and the `chown` command to fix
  it, rather than git's wording. Anything stashed before the pull is restored
  before the handler returns.

## [0.9.6.8] — 2026-08-10

### Fixed
- **Creating a landing failed with "network error" on servers without the
  `intl` extension.** A local landing left with an empty *Folder* field derives
  its slug from the name, and that derivation called
  `transliterator_transliterate()` — a function only `php-intl` provides, which
  `install.sh` never installed. On PHP 8 calling a function that does not exist
  raises an `Error`, and `@` does not suppress it, so `save_landing` died as a
  bare 500. Slugs now transliterate through a built-in Cyrillic/Latin table
  (with `iconv` as a second pass) whenever `intl` is missing, producing the same
  slug it would have produced with `intl` for the alphabets that reach this code.
- **A failed landing save reported "network error" regardless of the cause.**
  `save_landing` could let a `Throwable` escape as a 500, and the landing forms
  alerted a fixed string on any thrown request — so a PHP fatal, a rejected slug
  and a genuinely unreachable server all read identically. The endpoint now
  answers `{status: "error", message: …}` and logs the fatal, and both landing
  forms show the server's own message, falling back to the HTTP status and only
  then to "network error".
- **Slug errors were shown as raw codes.** `landing_slug_taken` and friends are
  stable codes so each locale can phrase them, but the forms alerted the code
  itself. They are translated now, in all seven UI languages.
- **An auto-generated slug that collided rejected the save.** A landing named
  after an existing one — or named `lander` — was refused for a folder name the
  operator never typed. A derived slug that is taken or reserved now falls back
  to `name-2`, `name-3`, …, and to `landings/<id>/` if nothing is free. A slug
  typed by hand still reports the conflict rather than being silently changed.

### Changed
- `install.sh` installs `php-intl`. It is optional — the fallback table covers
  Russian, Ukrainian and Latin diacritics — but with it every alphabet
  transliterates. System status reports whether it is loaded.

## [0.9.6.7] — 2026-08-08

### Fixed
- **Creating a local or action landing failed with a NOT NULL constraint
  violation.** The `url` column on `landings` is `NOT NULL`, but local and
  action landings legitimately have no URL, and `save_landing` passed `null`
  through whenever the caller (the MCP tools, the old quick form, the new
  stream modal) did not send one. The field now defaults to an empty string,
  so every caller saves cleanly. Surfaced by the v0.9.6.6 stream modal, which
  sends no URL for those two types.

## [0.9.6.6] — 2026-08-08

### Added
- **Create and edit a landing from inside a campaign stream.** The quick modal a
  stream opened (name / type / URL) is now the full landing editor: four type
  tabs (Local / Redirect / Preload / Action), group and status, a named folder
  for local landings, a redirect-method selector, the complete Action block
  (send-to-campaign / 404 / text / HTML / nothing), and the offer-link hint with
  a copy button and HTML/JS/PHP formats for redirect landings. A new Edit button
  next to each landing in the stream opens the existing landing in the same
  modal, so the full form is reachable from where the landing is wired up — not
  only from the dedicated Landings page. The misleading
  `window.location.replace('{offer}')` placeholder has been removed from the
  Action text/HTML fields.

## [0.9.6.5] — 2026-08-08

### Added
- **The landing create/edit form now matches Keitaro.** The landing type is
  chosen from four tabs (Local / Redirect / Preload / Action) instead of a
  dropdown, matching the offer editor's tab pattern.
- **Named folders for local landings.** A local landing's files used to unpack
  into `landings/<id>/` — functional but opaque. Landings now carry a `slug`,
  and the directory is `landings/<slug>/` (shown as `/lander/<slug>` in the
  editor). The slug is the single source of truth resolved through one helper,
  `orbitraLandingDir()`, so every path-bearing endpoint (upload, file list,
  file operations, asset serving) follows it. The visitor never controls the
  slug: the cookie carries the landing's numeric id and the slug is looked up
  server-side, so a request cannot be aimed at an arbitrary directory. Existing
  landings are backfilled a slug from their name on migrate (transliterated,
  uniqueness ensured), and renaming the slug in the editor moves the folder.
  An empty slug falls back to `landings/<id>/`, so nothing ever breaks.
- **Redirect method for redirect landings.** A redirect landing can pick its
  method — HTTP 302, JavaScript, or Meta refresh — the way an offer already
  could. The chosen method applies on the final hop to the landing URL.
- **Copyable offer-link snippets in three formats.** The offer-link hint now
  carries a copy button on every snippet, is shown for redirect landings too
  (not only local/preload), and for a redirect landing offers the three
  integration shapes an external page needs: plain HTML (`<a href="{offer}">`),
  `document.write` JS (carrying `window.location.search`), and server-side PHP
  (carrying `_token`). Localised across all seven UI languages.

## [0.9.6.4] — 2026-08-08

### Security
- **Five System Settings fields were never saved.** `global_settings` had a
  hardcoded key whitelist that accepted eight settings and silently discarded
  five more that `SystemSettings.jsx` sends: `stats_enabled`,
  `stats_retention_days`, `archive_retention_days`, `admin_ip_access` and
  `ignore_prefetch`. The form showed them, the save reported success, and the
  values were thrown away — so every value the operator believed they had set
  was still the default. Three of them were never read by anything at all.
  All five are now whitelisted for read and write, validated (booleans
  normalised, retention windows clamped, the access list parsed before it is
  stored), and — where they describe behaviour — actually enforced.
- **`admin_ip_access` advertised access control that did nothing.** The panel
  showed it under a shield icon as an IP allow-list for the admin surface, but
  no code ever read it. It is now a real allow-list: an empty value leaves the
  panel open to everyone (the default, unchanged), a populated list restricts
  both `admin.php` and the authenticated `api.php` surface (sessions *and* API
  keys) to the listed IPv4/IPv6 addresses and CIDR ranges. The check runs
  before the session is created, so an unlisted client never reaches a login
  form. First-time setup (no users yet) is exempt, so the operator cannot lock
  themselves out before creating the first account.
- **Any signed-in user could read files outside a landing's folder.** The landing
  file endpoints interpolated the landing id into the path as a string, so
  `?action=get_landing_file&id=..&path=config.php` pointed the "allowed" root at
  the application directory and returned `config.php` — the postback signing key
  and the database path — as well as `api.php` itself. The containment test was
  also a bare string prefix, which treats `/landings/12` as living inside
  `/landings/1`. Both are gone: the id is cast to an integer, the relative path is
  normalised with any `..` segment rejected outright, and an existing file is
  re-checked through `realpath()` so a symlink cannot lead out either. The same
  resolver now serves every landing file operation.
- **A bare `_subid` in the URL was accepted at the landing→offer transition.**
  `/?_lp=1` resolved the original click first by signed `_token`, then by
  cookie, then — as a documented "Keitaro-shaped" fallback — by an unsigned
  `_subid` query parameter. That last step let anyone attribute a visit to any
  click id they liked, since `_subid` carried no proof. The fallback is
  removed: a signed `_token` (for landings on another domain) or the tracker's
  own cookie (for local/preload landings) are the only accepted sources. The
  documentation's claim that an unsigned click id is refused now matches the
  code.

### Added
- **The `{offer}` macro in local landings.** A landing's buy button is written the
  way it is in Keitaro — `<a href="{offer}">Buy</a>` — and the tracker substitutes
  the URL of the offer bound to the stream, click id included. `{offer_id}`,
  `{clickid}`, `{subid}`, `{token}` and every click parameter are substituted too,
  with values taken from the URL escaped. No other braces are touched, so JS
  template literals, Vue and Angular syntax inside a landing survive. With no offer
  on the stream `{offer}` becomes `/?_lp=1` rather than an empty link. Note that
  `{offer}` expands to the advertiser's URL, so choosing between several offers
  goes through `/?_lp=1&offer_id=N`, which also re-attributes the click.
- **Landing actions, as five choices instead of a free-text field.** Send to
  campaign, show a 404, show as text, show as HTML, do nothing — available both on
  a landing of type Action and on a stream with the Action schema, which
  previously understood two of them and silently did nothing for the rest.
  Existing action landings keep their behaviour: a payload becomes "show as HTML",
  an empty one becomes "do nothing".
- **`_token` for landings hosted elsewhere.** A redirect landing lives on another
  domain and cannot read the tracker's cookies, so its offer link had no way to
  say which click it belonged to and simply failed. The tracker now appends
  `_subid` and a signed `_token` (HMAC-SHA256, 24 hours) to the landing URL, and
  `/?_lp=1` accepts the token in the cookie's place. Unsigned click ids from
  strangers are refused.
- **JS adapter** (`js/orbitra-adapter.js`). Carries the click onto a landing's
  inner pages and forms, and exposes `orbitraPostback()` so a thank-you page can
  report a conversion with no affiliate network in between. Unlike its Keitaro
  counterpart it leaves `#anchors`, `mailto:`, `tel:` and `javascript:` links
  alone — rewriting those is what breaks popup forms and smooth scrolling — and
  never hands the click id to a third-party domain.
- **Create a landing or an offer without leaving the stream.** The schema editor
  gained "Create landing" and "Create offer" next to "Add"; the new item drops
  straight into the stream, and the cached dropdown is refreshed so it appears.
- **File operations in the landing editor**: create, upload, rename, move and
  delete. Writes are limited to a whitelist of extensions, so `.php` and
  `.htaccess` cannot be created, uploaded, or arrived at by renaming an HTML file.
- **Offer selection before or after the click.** A stream can defer the choice to
  the moment the visitor leaves the landing, so a slot that fills up while they
  read is not already spent on them. The click is logged without an offer and is
  attributed when `/?_lp=1` fires.
- **PHP landings**, off by default and enabled per instance in
  Settings → General. Entry point `index.php`, `$rawClick->get('parameter')` for
  the click, and an execution timeout of 1–9 seconds so one slow landing cannot
  tie up the workers the whole site shares. Uploads are scanned with PHP's own
  tokenizer for shell, `eval` and timeout-defeating calls, and a failing archive is
  removed rather than left half-installed. The scan is a speed bump, not a
  sandbox — `disable_functions` and `open_basedir` in `php.ini` are what actually
  contain a landing, and the documentation says so.
- **[docs/landing-pages.md](docs/landing-pages.md)** — one page covering all four
  landing types, the macros, the adapter, PHP landings and the mistakes that
  produce each error message.

### Fixed
- **The panel became unreachable at the server IP once a domain was parked.**
  Two causes, one symptom. `updateNginxConfig()` rewrote the site config with
  `server_name <parked domains>` and no catch-all block, so nothing explicitly
  owned requests addressed to the bare IP; and SSL was issued with
  `certbot --nginx`, whose installer plugin then edited that same file —
  narrowing `server_name` to the domain being issued and appending
  `return 404`, which the IP request landed on. It came back on every renewal.
  Deleting the domain you always used left no way in short of remembering which
  other domains were parked.

  The generated config now always begins with a
  `listen 80 default_server; server_name _;` block, so access by IP is
  structural rather than incidental, and certificates are obtained with
  `certbot certonly --webroot` — Certbot never touches nginx again. Orbitra
  writes the HTTPS server blocks itself, as it already did.
- **HTTPS on the server IP served a parked domain's certificate.** Let's Encrypt
  does not issue for bare IPs, so `https://<ip>/admin.php` matched whichever
  domain owned the first 443 block and failed on a name mismatch. The installer
  now generates a self-signed certificate for the IP and gives it a
  `listen 443 ssl default_server` block: the browser still warns, but the panel
  opens.
- **`/.well-known/acme-challenge/` was blocked by the dotfile deny rule**, so a
  webroot certificate could never be issued. It is now served from an explicit
  `location ^~` above the deny.
- **The config hardcoded `php8.3-fpm.sock`**, so on any other PHP version the
  first domain save produced a config that failed `nginx -t`. The socket is
  detected.
- **A failing config was installed anyway.** The old writer staged the new
  config as `orbitra.tmp` — a file nginx does not include — then ran `nginx -t`,
  which therefore tested the *old* config, and renamed the untested file into
  place. A bad generation could leave a server that would not come back up after
  a restart. The new config is tested where nginx actually reads it, and the
  previous one is restored if the test fails.
- **The installer, the panel and the recovery scripts each generated their own
  config**, and the three had drifted apart. They now share
  `core/nginx_config.php`.

### Added
- **`cli/nginx_sync.php`** — one command that rebuilds the web-server config
  from the database, repairs renewal configs left behind by the old
  `certbot --nginx` (otherwise the next renewal re-breaks IP access), and
  generates the self-signed certificate:

      sudo php /var/www/orbitra/cli/nginx_sync.php

  Existing installations should run it once after updating. `fix_nginx.sh` and
  `restore_https.sh` are now thin wrappers around it.
- **Configurable admin panel path.** *Settings → System → Admin panel path*
  moves the panel from `/admin.php` to `/your-path`, after which `/admin.php`
  answers 404 — so the login form is not sitting at the one URL every scanner
  tries against an IP range. This hides the panel; it does not replace the
  password, and `/api.php` still answers and enforces its own authentication.
  The way back in if the path is forgotten:

      php /var/www/orbitra/cli/admin_path.php reset

### Added
- **Data retention is now enforced.** The *Log retention* and *Archive retention*
  fields in System Settings were saved but never acted on — clicks, conversions
  and archived campaigns accumulated forever. `cli/cleanup_cron.php` purges them
  in chunks (so a multi-million-row table is not locked under one statement),
  honouring the configured windows, and is meant to run once daily:

      0 3 * * * php /var/www/orbitra/cli/cleanup_cron.php >> /var/log/orbitra_cleanup.log 2>&1

  Conversions are deleted both by their own `created_at` and via the
  `ON DELETE CASCADE` on `clicks(id)`, so neither table anchors the other.
- **`ignore_prefetch` is now enforced on every click entry point**, not only
  `index.php`. The header check also recognises modern `Sec-Purpose` /
  `Sec-Fetch-Mode` hints, so current Chrome and Edge prefetches are dropped
  instead of counted as real clicks.

### Fixed
- **The dead `fix_nginx` generator still shipped alongside the shared one.**
  `action=fix_nginx` hand-wrote its own nginx config, hardcoded the `php8.3`
  FPM socket, copied it into place via `sudo cp` and reloaded **without running
  `nginx -t`** — exactly the class of bug the `core/nginx_config.php` rewrite
  was written to eliminate. The action now delegates to that shared generator
  (the same path `regenerate_nginx` uses), so a "fix my nginx" call produces a
  tested, reload-safe config.
- **The landing editor's code examples ignored the UI language.** The
  `{offer}` / `/?_lp=1` snippets hardcoded Russian ("Купить", "Оффер 10")
  regardless of the selected locale; only the surrounding prose was translated.
  The example text is now localised across all seven languages.

## [0.9.6.3] — 2026-08-07

### Fixed
- **MCP connector returned 0 tools.** `mcp.php` decoded `tools.json` with
  `json_decode(..., true)`, which turns an empty `{}` into an empty PHP array,
  and `json_encode` then emits `"[]"` rather than `"{}"`. Every parameter-less
  tool therefore served `"properties":[]` and `"additionalProperties":[]`, both
  of which the MCP spec defines as records, so the client rejected the whole
  `tools/list` with *“expected record, received array”* and exposed no tools.
  `inputSchema` is now normalised at manifest load — `properties`,
  `patternProperties`, `$defs` and `definitions` are coerced to objects, and an
  array-valued `additionalProperties` becomes `true` — recursively over nested
  schemas. The fix holds regardless of which `tools.json` revision is deployed.
- **Every panel save returned 503.** The bulk edit shipped in 0.9.6.2 rewrote
  the request-body helper so it recursed on itself instead of reading
  `php://input`, so every real POST (login, bot lists, every form save) spun
  until the PHP-FPM worker died and nginx answered 503. The helper reads the
  stream again.
- **Bot IP table rendered blank rows.** The panel read `item.value` while the
  table column is `ip_or_cidr`, so every row was empty; the API now returns a
  stable `value` alias. Bot lists are also searched and paged in SQL — a flat
  first-1000 fetch left everything past row 1000 invisible and undeletable, and
  blacklists routinely reach tens of thousands of rows. The panel gained a
  search box, a shown-of-total counter and a *load more* button.
- **Raw translation keys showed across the panel.** `t()` returns the key
  itself when a translation is missing and no fallback is passed; 39 keys had
  no entry. The bot-list keys had landed in the wrong locale section (anchored
  on `"noRecords"`, which occurs earlier in the file), so the Bots page showed
  literal key names, and the payout-model dropdown, campaign parameters, stream
  device types, all 15 admin tile descriptions and six common labels had no
  translation at all. Re-anchored and added across all seven locales
  (de / en / es / fr / ru / uk / zh); parity at 1838 keys.

## [0.9.6.2] — 2026-08-07

See [README.md — What's New in v0.9.6.2](README.md#whats-new-in-v0962).

### Added
- Remote MCP over a URL (`mcp.php`).

### Fixed
- Uploaded landings showed no images, video or fonts (relative paths resolved
  against the domain root → 404). Assets now resolve against the shown landing.
- Media requests (`.webp`, `.mp4`, `.avif`, `.webm`) fell through into campaign
  matching, logging phantom clicks and returning HTML in place of the file.
- Bot blacklists on Settings → Bots were no-ops that reported success.
- The MCP page pointed at the wrong Claude dialog.
- CSRF was only enforced on POST; now covers every mutating method.

## [0.9.6.1] — 2026

See [README.md — What's New in v0.9.6.1](README.md#whats-new-in-v0961).

### Fixed
- Postback queue never ran (no UI to install the delivery cron).
- S2S log marked every postback as an error (read a legacy column).
- Rows abandoned by a crashed worker were stuck forever (stale `in_flight`).
- Retry ladder stopped one step short of the 24h attempt.
- Today's ad spend froze at the first sync (re-syncs discarded as duplicates).
- Cloaking sensitivity had two levels instead of three (`medium` == `high`).
- SSRF re-check bypassed bare-IP hosts; hardened `curl proxy`.
- `form_submit` dropped the port.

### Added
- JS fingerprint check for cloaking (optional, off by default).

## [0.9.6.0] — 2026

See [README.md — What's New in v0.9.6.0](README.md#whats-new-in-v0960).

### Added
- Redirect types at runtime (HTTP 302, JS, Meta refresh, iframe, form submit,
  curl-proxy).
- Cloaking with safe-page / money-page split.
- S2S postback queue with retry.
- Cost auto-import (Facebook Ads / Google Ads).
- Redirect/iframe/file redirect types, local landings.
