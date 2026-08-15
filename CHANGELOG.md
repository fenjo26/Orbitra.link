# Changelog

All notable changes to **Orbitra Tracker** are listed here. The full release
notes for each version also live in [README.md](README.md) (English) and
[README.ru.md](README.ru.md) (Russian) under the *What's New* / *Что нового*
sections.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.9.7.6] — 2026-08-15

### Added
- 📤 **Facebook Conversions API.** Conversions are now sent to Meta from the server,
  not only from the browser pixel — the events ad blockers, ITP and iOS strip out
  reach the optimiser again. Configured per campaign (Integrations tab): pixel ID,
  Conversions API token, a status→event mapping, optional test event code and proxy.
  Events carry `fbc`/`fbp`, the click's IP and user agent, and SHA-256 hashed
  email/phone/name/geo, with an `event_id` that deduplicates against the browser
  pixel. Delivery rides the existing S2S queue, so a slow answer from Meta never
  delays the reply to the affiliate network's postback. A pixel without a token
  stays browser-only, and `rejected`/`trash` are unmapped by default.
- 💱 **Ad spend is converted to the tracker's currency.** Meta and Google bill in the
  ad account's currency, which was previously written into `clicks.cost` as-is and
  silently mixed with revenue in another currency. Rates are fetched and cached for
  12 hours; `fx_rates_manual_json` pins them manually. The platform's original
  amount and currency are kept in `cost_records.raw_json` for audit.
- 🧪 `tests/facebook_integration_test.php` — end-to-end coverage of capture,
  attribution, currency, idempotency and CAPI payload construction. No network.
- 📖 `docs/facebook.md` — setup, macros, token issuance, mapping, troubleshooting.

### Fixed
- 💰 **Facebook cost import fetched nothing at all.** The insights request asked for a
  field named `currency`, which does not exist on that endpoint — Meta rejects the
  whole request when one field identifier is wrong (error 100), so every sync
  returned zero rows. The field is `account_currency`.
- 🔇 **A failing sync reported success.** `fetchRecords()` swallowed transport and API
  errors and returned an empty array, so an expired token showed up in the UI as
  "success, 0 records". Errors now propagate and land in `last_sync_error` with
  Meta's own message.
- 🎯 **Clicks never recorded the ad IDs cost import matches on.** The traffic-source
  templates advertised `{{ad.id}}` and `{{adset.id}}`, but click logging only kept a
  fixed list of `sub_id_*` keys and dropped everything else — so imported spend could
  not be attached to any click and campaigns showed cost 0. Capture now lives in one
  shared helper (`core/ClickParams.php`) used by both the redirect path and the Click
  API, and covers the ad-network IDs, the platform click identifiers (`fbclid`,
  `gclid`, `ttclid`, …), the `_fbp`/`_fbc` cookies, and any parameter the campaign's
  traffic source declares.
- 🎯 **Spend was never attributed at adset level.** Matching went straight from ad ID
  to campaign ID, so `{{adset.id}}` — the one parameter Keitaro requires — did
  nothing, and any campaign whose ad IDs the tracker had not seen fell back to
  campaign-level or went unmatched. The chain is now ad → adset → campaign, and a
  connection can point each level at a different click parameter (`ad_id_param`,
  `adset_id_param`, `campaign_id_param`) for traffic that passes through an app.
- 📅 **The sync window was too short to be correct.** Two days of lookback froze
  restated spend at whatever it was when first read. Cost connections now re-read
  the last 5 days, and 30 days on their first sync.
- 📄 **Pagination could stop early.** The cursor was rebuilt by hand instead of
  following Meta's `paging.next`, so a parameter set that drifted dropped pages
  silently. Page size raised from 200 to 500.

### Changed
- Facebook connections gained **API version** and **proxy** settings — Meta
  periodically geo/IP-filters requests coming from a tracker's server IP.
- **Test connection** now reads the ad account (name, currency, timezone, status)
  instead of an insights report, which could come back empty and valid for a dead
  token. A disabled or closed account is reported as a failure.
- Existing pixels can be **edited**, not only created and deleted.
- The cost sync log line reports the date window, unmatched count and currency, and
  warns explicitly when spend was imported but matched zero clicks.
- New indexes on `clicks(date(created_at))`, `cost_records(connection_id, external_id)`
  and the postback queue's pending lookup (schema v17).
- All new interface strings are translated in every locale (en, ru, uk, es, de, fr, zh);
  `npm run check:i18n` passes with all seven in parity. Aggregator connection fields now
  carry a `label_key` alongside their English text, so engine form labels come from the
  locale files instead of being hardcoded in the PHP engine.

## [0.9.7.5] — 2026-08-13

### Fixed
- **`install.sh` installs PHP's `bcmath` extension.** `ip2location/ip2location-php`
  and `ip2location/ip2proxy-php` both declare `ext-bcmath` as a hard requirement,
  so on a server without it Composer rejected the entire lock file with "Your lock
  file does not contain a compatible set of packages" and no dependency was
  installed. A version-pinned fallback covers servers whose PHP CLI is not the
  distribution default.
- **A failing step no longer leaves the installation permanently un-updatable.**
  The script runs under `set -e`, so the Composer failure above aborted it before
  the closing `chown -R www-data:www-data` — the tree stayed root-owned and the
  update button reported that part of the directory belongs to another user. The
  ownership handover now runs from an `EXIT` trap and therefore happens on every
  exit path, and the Composer and frontend build steps are no longer fatal.
- **Admin-panel updates survive a missing `bcmath`.** All three Composer call
  sites go through one helper that retries with `--ignore-platform-req=ext-bcmath`
  and reports the single command that installs the extension, instead of failing
  the update and printing every `php.ini` path on the server.

### Changed
- The installer exports `COMPOSER_ALLOW_SUPERUSER=1`, so the root warning no
  longer looks like an installation error in the log.
- The manual fallback command shown when `exec()` is disabled now begins with
  `sudo apt-get install -y php-bcmath`.

## [0.9.7.4] — 2026-08-11

### Added
- **IP2Proxy LITE PX12 and IP2Location ASN LITE support.** Both databases have
  separate status/update entries, use the existing IP2Location download token,
  and feed proxy/VPN/Tor/datacenter/threat/fraud-score and ASN/ISP signals into
  live cloaking and Traffic Simulation through the official provider readers.
- **Keitaro-style `Bot: Yes` stream filter.** It is available in the campaign
  editor and uses the same ASN, PX12, blocklist and browser detector in direct
  campaign traffic, Traffic Simulation and the Keitaro-compatible Click API.
  An intercepting `Bot: Yes` + `Do nothing` stream can therefore hide embedded
  content while clean traffic continues to the regular streams.
- Existing Git installations automatically install locked Composer dependencies
  after a successful update from the admin panel.

### Fixed
- **Universal Geo DB uploads no longer confuse provider formats.** Orbitra
  identifies DB11, ASN, PX12, MMDB and Sypex files before installation, stores
  each in its own slot, migrates PX12 files previously misplaced as DB11, and
  rejects impossible latitude/longitude values.
- **Apache campaign aliases now work at `/campaign-alias`.** The root short URL
  is forwarded to the tracker just like `/r/campaign-alias`; real files and
  directories remain untouched.
- Removed the Sypex-only hint beside the universal database upload control.

### Changed
- The manual Git-update card now shows the complete safe fallback: `git pull
  --ff-only` followed by the locked production Composer install. All seven
  interface locales contain the updated guidance.

## [0.9.7.3] — 2026-08-11

### Added
- **Real Cloak verdicts in Traffic Simulation.** The simulator accepts ASN/ISP,
  JavaScript-executed and `navigator.webdriver` inputs, runs the same passive
  detector as live traffic, and explains why the result is the safe or money page.
- A regression test reproduces an update followed by a conflicting `stash pop`,
  verifies automatic repair, and proves that the next pull succeeds normally.

### Fixed
- **Admin updates stuck on `unmerged files`.** Before pulling, the updater now
  detects unmerged index entries directly, aborts unfinished Git operations and
  restores a clean `HEAD`. It no longer depends only on matching Git's error text.
- **A failed stash restore poisoned every future update.** Local code changes stay
  saved in the Git stash, while the half-applied conflict is reset immediately so
  the repository never remains in an unresolved state.
- Installations already stuck in an unresolved state on `0.9.7.2` need one SSH
  recovery to receive this updater code:
  `sudo -u www-data git -C /var/www/orbitra reset --hard HEAD && sudo -u www-data git -C /var/www/orbitra pull --ff-only origin main`.
  Later conflicts are repaired by the admin panel automatically.
- **Cloaking false positives on residential traffic.** Generic substring matches
  no longer classify Comcast, CloudMTS or InterServer as hosting providers, while
  known datacenter ASNs and precise cloud-provider names remain blocked.

## [0.9.7.2] — 2026-08-11

### Added
- **Landing-to-Offer transition metrics** (`lp_clicks` & `lp_ctr`) in Landings analytics API and UI table view.
- **Cloudflare Turnstile** anti-bot verification support in bot challenges.
- **Direct stream filter editing**: click any filter item in Campaign Editor to modify values/countries without deleting and re-creating.
- Full 100% key parity across all 7 supported UI locales (`ru`, `en`, `de`, `es`, `fr`, `uk`, `zh`).

### Fixed
- **Intercepting streams URL resolution**: fixed `$finalUrl` and redirect type calculation in `landing_offer` and `cloak` (safe page & money page) schemes so missing/invalid landing URLs fall back to the offer URL instead of raising `URL not found.`.
- **Prefetch false positives**: removed broad `no-cors` check in `core/prefetch.php` that improperly blocked legitimate user navigations over VPNs and mobile browsers.

## [0.9.7.1] — 2026-08-10

### Added
- **Cohort analysis** (new *Analytics* tab, was *Trends*). Campaigns are grouped
  by the month or quarter they were created, and each cohort is tracked across
  its lifetime periods (M0, M1, M2…):
  - **Retention curves** — one line per cohort decaying/growing across M0..Mn,
    the canonical cohort chart.
  - **Heatmap matrix** with an **Absolute / % of M0** toggle. Retention mode
    normalises each cohort to its first period so cohorts of very different
    sizes can be compared by decay shape.
  - **Per-cohort summary** with totals, ROI, and first/last active period.
  - Revenue and conversions are attributed to the period the event occurred,
    not the click period — delayed payouts no longer collapse into the launch
    month. CR is the share of clicks that converted (0–100%).
- **Browser-language detection** for first-time visitors: the UI now opens in
  the language the browser reports instead of a hard-coded Russian default. The
  same `'ru'` fallback was removed across user creation, login and profile
  settings.

### Fixed
- **click.php** returned a bare HTTP 500 on any campaign without a configured
  stream/offer (`FOREIGN KEY constraint failed` on `offer_id = 0`). Clicks now
  log with `offer_id NULL`, and the click path is wrapped in try/catch so any
  future failure returns a JSON error + a `system_logs` row instead of a silent
  empty-body 500.
- **Trends** only plotted days that had traffic, so a single active day looked
  "stuck" at the X origin. Days and hours are now zero-filled across the
  selected range, matching the dashboard.
- **i18n**: hardcoded Russian labels in the Trends chart tooltip, and
  browser-locale date/number formatting in Cohort, now follow the selected UI
  language across all seven locales (en, ru, uk, es, zh, fr, de). The heatmap
  intensity scale reads `--color-primary` via `color-mix`, so it adapts to every
  theme (light/dark/green/neon/custom) instead of a hard-coded coral hex.

## [0.9.7.0] — 2026-08-10

### Added
- **A local landing is served at its own `/lander/<slug>/`, matching Keitaro.**
  The Folder field advertised that URL from the day slugs landed, but nothing
  answered it: a landing's files were reachable only during a real click,
  resolved from the `orbitra_lp` cookie, so there was no way to look at a landing
  without sending traffic through a campaign. Like Keitaro, the served HTML gets
  a `<base>` tag injected so the page's relative paths (`img/a.png`) resolve
  inside its folder — which is why Keitaro's own requirements say a landing must
  not ship a `<base>` of its own. Assets go through the same extension whitelist
  and path containment the click flow uses; `.php` is not served or executed
  there, since a PHP landing needs the click context this route has none of.
  Nothing is logged: this is a look at the landing, not a visit to a campaign.
  On Apache the route also needs a rewrite: `.htaccess` only forwarded `/r/` and
  the Click API to `index.php`, with no catch-all, so `/lander/<slug>/` matched
  nothing on disk and Apache answered its own 404 before PHP ran. Nginx installs
  were unaffected — their `try_files` already falls through to `index.php`.
- **Code / Preview toggle in the landing editor.** Preview loads the landing from
  `/lander/<slug>/` in an iframe, so it is the page as a visitor receives it —
  images, video, CSS and scripts included — rather than a rendering of the HTML
  on its own. Switching to it forces a reload, so it never shows the state from
  before the last save or upload, and a button opens the same URL in a new tab.
- **The Domains page says why SSL is unavailable instead of leaving it to guess.**
  On shared hosting where `shell_exec` is removed or Certbot is not installed, a
  parked domain sat at "waiting for certificate" with no indication that the
  server can never issue one. The page now checks the server's capability once
  on load — shell, Certbot, nginx config, writable ACME directory — and shows a
  banner naming the blocker when issuance is impossible, so the operator knows
  to use a dedicated VPS or issue through their hosting instead of waiting.


  `chmod +x /var/www/orbitra/cli/*.php`, and git tracks the executable bit — so
  those files were permanently "locally modified" and any update touching one of
  them stopped with *your local changes would be overwritten by merge*. The bit
  bought nothing: the scripts are invoked as `php <path>`. It is no longer set,
  `core.fileMode` is turned off for the repository, and the panel's update button
  turns it off too, so installs that already carry the mode change can update
  without being reinstalled.
- **PHP called `shell_exec` directly in 46 places.** On hosts where the function
  has been removed rather than merely disabled, PHP 8 raises an `Error` that `@`
  does not suppress — so saving a domain, syncing the nginx config or checking
  for Certbot died as a bare 500 with no explanation. Everything goes through
  `core/shell.php` now: `orbitraShell()` returns `null` instead of fataling,
  `orbitraShellAvailable()` lets a feature check before it starts, and
  `orbitraRemoveDirectory()` replaces the two `system('rm -rf …')` calls that had
  the same problem. Certificate issuance checks for a usable shell and for
  Certbot before touching any domain, rather than marking domains failed for a
  reason that has nothing to do with them.
- **Nothing could start issuance from the panel.** The queue was worked only by
  cron and by a process spawned in the background on save, both of which need
  `shell_exec` — disabled on a great many hosts. Where it is, every domain sat at
  "pending" with nothing to click and no indication why. The Domains page now has
  an *Issue SSL* button that runs the worker inside the request and reports back
  how many certificates were issued, how many are waiting on DNS and how many
  failed — naming the blocking reason outright when Certbot is missing,
  `shell_exec` is disabled, or the server is not running nginx.
- **A certificate was attempted once and never again.** Issuance was a single
  background shot fired when a domain was saved with HTTPS-only ticked, and
  nothing was ever scheduled to run it a second time — `install.sh` installs no
  cron at all. Minutes after pointing an A record, DNS has not propagated, so
  that one attempt failed, the domain was marked `failed`, and it stayed on a
  broken certificate until a human reopened and re-saved it. Certificates are now
  worked by `core/ssl_manager.php`: every parked domain is a candidate, the
  domain's A record is compared with this server's address *before* Certbot runs
  so a domain that cannot validate never spends one of Let's Encrypt's five
  failures per hostname per hour, and a failure is rescheduled on a widening
  ladder (1h, 1h, 2h, 6h, then 12h) rather than being final. A domain still gets
  its certificate immediately on being added; the hourly pass exists only to
  finish what could not be issued then.
- **Issuance was tied to the HTTPS-only toggle.** Adding a domain without it left
  `ssl_status` at `none`, which meant no certificate was ever requested, and
  turning it off later reset the status and dropped the domain out of the queue.
  Parking a domain is now the request for a certificate, as it is in Keitaro;
  HTTPS-only only decides whether `http://` redirects to `https://`.
- **A domain could show an SSL tick while the browser was served the catch-all's
  self-signed certificate.** `ssl_status` was set to `installed` the moment a
  certificate appeared under `/etc/letsencrypt/live/<domain>/`, and nothing ever
  checked whether nginx was actually serving it — the HTTPS server block for a
  domain is written only when the config is regenerated with its `fullchain.pem`
  already on disk. `cli/ssl_installer.php` made this reachable: when it found a
  certificate already present it marked the domain installed and `continue`d,
  skipping the config rebuild entirely. `check_ssl_status` now reconciles every
  answer against the filesystem and the live config, returns `cert_present` and
  `https_active` separately, and rebuilds the config once when a certificate
  exists that nothing is pointing at. The installer reconciles once per run
  instead of per domain, including on the path where the certificate was already
  there.

### Changed
- **The campaign stream no longer carries its own copy of the landing form.** It
  had one — 271 lines duplicating `LandingEditor` — which is why the two behaved
  differently: the stream's copy took a ZIP while creating a landing and the
  Landings page did not, and every fix had to be written twice. The stream now
  renders `LandingEditor` and receives the saved id through a new `onSaved`
  callback to wire the landing into its rotation. `CampaignEditor` loses 309
  lines along with the state that existed only to feed the copy (landing groups,
  the campaign list, the postback key, the offer-link hint) and three API calls
  it made on every open.

### Fixed
- **Creating a local landing from the Landings page gave no way to upload the
  archive.** The form said "save the landing settings first to upload archive
  files" and then closed the window, so the file panel it was pointing at could
  only be reached by finding the landing in the list and reopening it. Saving now
  keeps the editor open and switches it to edit mode in place, and the create
  form takes a ZIP directly — held until the landing has an id, then uploaded.
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
- **"ZIP upload error" was the only thing a failed archive upload ever said.**
  `handleZipUpload` alerted a fixed string from its `catch`, so every non-2xx
  answer — a 500 from the handler, a 413 from nginx, a request that never
  finished — arrived as the same four words. `upload_landing` now names each
  failure: the body exceeding `post_max_size` (with the actual sizes), each
  `UPLOAD_ERR_*` code in words, a missing `zip` or `fileinfo` extension with the
  package to install, a `landings/` directory the web server cannot write to
  (with the `chown` command), and the MIME type actually detected when the file
  is not a ZIP. The whole handler is wrapped so a `Throwable` returns JSON
  instead of a 500.
- **A failed extraction was reported as a successful upload.** `mkdir()` and
  `ZipArchive::extractTo()` both had their return values discarded, so when the
  landing directory could not be created or written, the panel said the archive
  was extracted and the landing quietly served nothing. Both are checked now.
- **An archive PHP cannot decompress looked like a permissions problem.** The
  "maximum compression" preset in 7-Zip and WinRAR writes LZMA, BZip2 or PPMd
  entries; libzip is normally built with Store and Deflate only, so the archive
  opens and lists correctly and only `extractTo()` fails. A failed extraction
  now inspects each entry's compression method and, when it is one PHP cannot
  read, names it and says to repack with Deflate.
- **The landing editor swallowed the server's message on every file
  operation.** Reading, saving and uploading a file inside a landing each
  alerted a generic string. They all show what the server said, falling back to
  the HTTP status and only then to "network error".
- **A certificate the browser rejected could show as installed.** A
  `fullchain.pem` that holds only the leaf — missing the intermediate — is the
  single most confusing TLS state: Firefox fills the gap from its own store and
  opens the site, while Chrome and curl fail with *unable to get local issuer
  certificate*. That happens when a config pointed at `cert.pem`, a manual edit
  truncated the file, or a certbot run wrote leaf only. `core/ssl_manager.php`
  now counts the `BEGIN CERTIFICATE` blocks in the chain on every run: fewer
  than two marks the domain `failed` with a named reason (`incomplete_chain`)
  instead of `installed`, both right after issue and on the later pass that
  catches a file that went wrong after the fact. nginx is never pointed at a
  half-chain.
- **Server-side error messages were hard-coded in Russian.** `upload_landing`
  and the SSL worker returned whole sentences in Russian — the DNS mismatch
  detail, the Certbot-no-output fallback, every archive-upload error. In a panel
  that ships seven locales, a backend string the frontend cannot translate is a
  bug, not a stylistic preference. The backend now returns machine codes
  (`dns_mismatch`, `certbot_no_output`, `not_a_zip`, `zip_unsupported_compression`,
  …) plus a `detail` object of the measured facts (sizes, addresses, the MIME
  type detected), and the frontend phrases them per locale. Certbot's own output
  is diagnostic text and passes through untranslated.

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
