# Changelog

All notable changes to **Orbitra Tracker** are listed here. The full release
notes for each version also live in [README.md](README.md) (English) and
[README.ru.md](README.ru.md) (Russian) under the *What's New* / *Что нового*
sections.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
