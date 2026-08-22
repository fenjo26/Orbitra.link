# Technical Specification — Cloak Observability & Geo-Targeting Safety

**Project:** Orbitra tracker
**Component:** Cloak schema (`schema_type = 'cloak'`), click logging, GeoIP targeting
**Status:** Draft for implementation
**Author:** Engineering
**Date:** 2026-08-22
**Related docs:** `docs/cloaking.md`, `docs/architecture.md`, `docs/api.md`

---

## 1. Background

A field tester reported: *"The cloaker is not working properly. 18 link clicks in the Facebook ad account, 0 clicks in the tracker."*

His configuration: all four detection layers ON, sensitivity default (`medium`), Country filter = allow `IN` only, Device filter = allow Mobile + Tablet, "Block bot & datacenter ISPs" ON, "Do not record clicks for Safe Page" ON, 6 local offers on the money page, a fallback stream with a white page.

Source investigation established the following facts.

**1.1 The zero is a logging artefact, not a routing failure.**
`CloakDetector::shouldSkipSafePageClick()` (`core/CloakDetector.php:39`) defaults `dont_record_safe_clicks` to `true` when the key is absent from `schema_custom_json`. The click INSERT is gated on `!$skipClickLogging` (`index.php:3390`, mirrored in `click.php:577` and `core/click_api.php:818`). Therefore a campaign whose traffic is 100% safe-page-routed writes zero rows and produces a completely empty Analytics view — indistinguishable, from the operator's seat, from "the tracking link is broken".

**1.2 The routing itself is very likely wrong for this tester.**
Facebook's own crawlers (`facebookexternalhit`, `meta-externalagent`) do not increment the **Link clicks** metric in the ad account. 18 Link clicks therefore represent real users. They were all routed to the safe page. The most probable mechanism, in order of likelihood:

- **Missing GeoIP database.** `getGeoData()` (`index.php:70`) initialises `country_code` to the literal string `'Unknown'` and only overwrites it if `geo/IP2LOCATION-LITE-DB11.BIN` or `geo/GeoLite2-City.mmdb` is installed and readable. `CloakDetector::targetingReasons()` (`core/CloakDetector.php:172`) then evaluates `in_array('UNKNOWN', ['IN'])` → false → reason `geo_country` → `TARGETING_SAFE`. With no geo database installed, **an allow-list country filter rejects 100% of traffic, silently.** Combined with 1.1, the operator sees exactly nothing.
- **IP2Proxy PX12 classifying carrier-grade NAT ranges.** If `IP2PROXY-LITE-PX12.BIN` is installed, proxy types `RES`, `PUB`, `CPN`, `EPN` are treated as `ip2proxy_vpn_proxy`, a *hard* signal — suspicious at every sensitivity including `low` (`core/CloakDetector.php:315`, `:413`). Some Indian mobile pools are classified this way.
- ASN-based VPN detection is *not* a plausible cause here: `core/data/asn_blocklist.json` currently ships 63 datacenter ASNs and 29 VPN ASNs and contains no Indian carrier.

**1.3 The operator cannot diagnose any of this from the product.**
The `clicks` table (`config.php:320`) has no ISP, ASN, proxy, or verdict column. The verdict strings (`PASSIVE_SAFE`, `TARGETING_SAFE`, `JS_SAFE`) and their reason codes are emitted by `logCloakEvent()` (`index.php:1674`) to `error_log()` only — i.e. to the PHP error log, reachable by SSH and by nobody else. Support currently has to ask for shell access to answer "why did this click go white?".

**1.4 The warning that would have prevented this ticket already exists — and is never rendered.**
The key `admin.noGeoDb` — *"No geo database installed. Geo filters will not work."* — is present and translated in all seven locale files (`frontend/src/locales/*.js:927`, `fr`/`zh` at `:926`). `grep` over `frontend/src` outside `locales/` returns **zero usages**: no component reads it. Somebody anticipated exactly this failure, wrote and translated the string, and never wired it to a screen. Reconnecting it is the cheapest fix in this document (§8.3, §11 Phase 0) and does not require a single new i18n key.

**Conclusion.** Orbitra's cloaker is a black box with a mute button switched on by default. This specification makes every routing decision observable inside the product, and prevents the "no geo database → block everything" failure mode from being silent.

---

## 2. Goals

| # | Goal |
|---|---|
| G1 | Every cloak routing decision is persisted with its verdict, its reason codes, and the network facts it was based on. |
| G2 | An operator can answer "why did this visitor see the white page?" from the UI, without shell access. |
| G3 | "Zero clicks" can never again mean "everything was suppressed" — a suppressed hit is still counted somewhere visible. |
| G4 | A missing or unreadable GeoIP database can never silently send 100% of traffic to the safe page. |
| G5 | Reports stay clean: safe-page traffic must remain excludable from campaign metrics — that was the legitimate reason the suppression checkbox exists. |

## 3. Non-goals

- Changing detection accuracy, thresholds, or the ASN/IP-range list content.
- Adding new detection layers or a third-party reputation API.
- Any synchronous outbound network call on the click path. (This was removed deliberately once — see `docs/architecture.md` and the WAL/latency work — and must not return.)
- Reworking the fallback-stream / `collect_clicks` semantics beyond what §7.4 states.
- Rewriting the cloak UI card layout.

---

## 4. Architectural constraint: three duplicated click paths

The click-logging block exists three times, in near-identical form:

| Entry point | Verdict computed | INSERT gate |
|---|---|---|
| `index.php` | `:3160`–`:3234` | `:3390` |
| `click.php` | `:483` | `:577` |
| `core/click_api.php` | `:669` | `:818` |

These have already drifted once. **Requirement A-1:** before implementing §5–§8, extract the shared pieces into a single module — proposed `core/click_logger.php`, exposing:

```php
orbitraBuildClickRow(array $ctx): array          // column => value, one place that knows the schema
orbitraPersistClick(PDO $pdo, array $row): bool  // INSERT + error handling
orbitraCloakDecision(array $customSchema, array $visitorCtx, array $settings, string $countryCode, string $deviceType): array
    // => ['show_safe'=>bool,'verdict'=>string,'reasons'=>string[],'skip_click_log'=>bool,'geo_ready'=>bool]
```

All three entry points must call the same functions. A change that adds a column to one path and not the others will be rejected in review. Any new field added by this spec must be produced by `orbitraCloakDecision()` and consumed by `orbitraBuildClickRow()`.

---

## 5. Workstream W1 — Persist the cloak verdict

### 5.1 Schema

Add to `clicks`:

| Column | Type | Default | Meaning |
|---|---|---|---|
| `cloak_verdict` | TEXT | `NULL` | `money` \| `passive_safe` \| `targeting_safe` \| `js_safe` \| `NULL` (non-cloak stream) |
| `cloak_reasons` | TEXT | `NULL` | Comma-separated reason codes, e.g. `geo_country,device_type`. Empty string for `money`. |
| `is_safe_page` | INTEGER | `0` | `1` when the visitor was served the safe/white destination. Denormalised for cheap report filtering. |
| `isp` | TEXT | `NULL` | `$geoData['isp']` as resolved at click time. |
| `asn` | TEXT | `NULL` | `$geoData['asn']`, `AS`-prefixed. |
| `proxy_type` | TEXT | `NULL` | IP2Proxy `proxyType` (`DCH`, `VPN`, `RES`, …), empty when PX12 is absent. |
| `cloak_sensitivity` | TEXT | `NULL` | Sensitivity in force for this decision. Needed to interpret a verdict after the operator changes the setting. |

**Indexes:** `CREATE INDEX IF NOT EXISTS idx_clicks_safe ON clicks(campaign_id, is_safe_page, created_at);`

### 5.2 Migration

Migrations live **inline in `config.php`**, under `$LATEST_SCHEMA_VERSION` (currently `37`, `config.php:76`). The `migrations/` directory is executed by nothing; a `.sql` file placed there will never run. Add:

```php
if ($schemaVersion < 38) {
    // ALTER TABLE ... ADD COLUMN, one per column, each wrapped so a
    // re-run on a partially migrated DB does not abort the batch.
}
```

and bump `$LATEST_SCHEMA_VERSION` to `38`. Follow the existing pattern at `config.php:1938` (v37) for column-exists guards. Historical rows keep `NULL` verdicts; the UI must render `NULL` as `—`, never as "money".

### 5.3 Write path

- `orbitraCloakDecision()` returns the verdict and reasons already computed today at `index.php:3166`–`:3234`; nothing new is detected, the values are simply no longer thrown away.
- `logCloakEvent()` stays as-is (`error_log`) — it is useful for post-mortems where the DB is unavailable. It is no longer the only sink.
- For non-cloak streams (`landing_offer`, `redirect`, bot filter) the three cloak columns stay `NULL` and `is_safe_page = 0`.
- The bot-intercepting stream filter (`CloakDetector::detectBotFilter()`) writes `cloak_verdict = 'passive_safe'`, `cloak_reasons` from its verdict, `is_safe_page = 1`.

### 5.4 Performance budget

Seven additional TEXT/INTEGER columns on an INSERT that already carries 23. No additional queries, no additional I/O beyond the row itself. **Requirement:** p95 of the click path must not regress by more than 5 ms measured on the reference instance; report the before/after numbers in the PR.

---

## 6. Workstream W2 — Surface it

### 6.1 API

**`GET api.php?action=logs&type=traffic`** (`api.php:6385`) — extend the SELECT with:

```sql
cl.cloak_verdict,
cl.cloak_reasons,
cl.is_safe_page,
cl.isp,
cl.asn,
cl.proxy_type,
l.name  AS landing_name,
o.name  AS offer_name
```

(`landings` is currently not joined at all in this query — add `LEFT JOIN landings l ON cl.landing_id = l.id`.)

New optional query parameters, all additive and all defaulting to today's behaviour:

| Param | Values | Effect |
|---|---|---|
| `campaign_id` | int | Restrict to one campaign. |
| `route` | `all` (default) \| `money` \| `safe` | Filter on `is_safe_page`. |
| `reason` | reason code | Rows whose `cloak_reasons` contains the code. |

Response shape is unchanged apart from the new keys; existing consumers keep working.

**`GET api.php?action=cloak_summary&campaign_id=N&from=…&to=…`** — new endpoint, aggregate for the campaign editor and the diagnostics panel:

```json
{
  "status": "success",
  "data": {
    "total": 18,
    "money": 0,
    "safe": 18,
    "suppressed": 18,
    "by_reason": [
      {"reason": "geo_country", "count": 18},
      {"reason": "ip2proxy_vpn_proxy", "count": 3}
    ],
    "geo_ready": false,
    "px12_installed": true,
    "sensitivity": "medium"
  }
}
```

`suppressed` is sourced from W3 (§7.3) so the number is correct even when `dont_record_safe_clicks` is on. `by_reason` counts each code independently; a click with two reasons appears in both buckets, and the endpoint documents that.

### 6.2 Logs page (`frontend/src/components/LogsPage.jsx`)

Add to the traffic table, all behind the existing column-visibility preferences mechanism (`ColumnsOrderModal.jsx`):

- **Route** — badge: green `Money`, amber `Safe`, grey `—`. Tooltip carries the verdict (`targeting_safe`) and the sensitivity.
- **Reason** — comma-joined reason codes, each rendered as a chip with a `HelpTooltip` giving a one-line human explanation (`geo_country` → "Visitor's country was not in the stream's allow-list"). New i18n namespace `cloakReasons.*`.
- **Destination** — `landing_name` / `offer_name` so white-vs-money is legible even to a user who ignores the badge. Note that the underlying data already exists today: the safe branch writes the white page's id into `landing_id` (`index.php:3254`), and a local white offer into `offer_id` (`:3274`). Until this release ships, that column is the only in-product way to tell a safe click from a money one — which is worth saying in the support answer to the originating ticket.
- **ISP / ASN** — hidden by default, available in the column picker.

Add a filter bar above the table: campaign selector, Route (All / Money / Safe), Reason (populated from `cloak_summary.by_reason`).

### 6.3 Campaign editor — cloak diagnostics panel

Inside the cloak card (`CampaignEditor.jsx`, cloak section around `:3700`), below the detection-layer switches, add a read-only panel driven by `cloak_summary` for the last 24 h:

```
Last 24h:  18 hits  →  0 money  ·  18 safe
Top reasons:  geo_country ×18
[ View in click log ]
```

When `safe / total ≥ 0.9` and `total ≥ 10`, render a warning banner: *"Almost all traffic is being routed to the safe page. Check the reasons below before spending more on this campaign."* This is the single highest-value item in the whole specification — it is the screen that would have answered the tester's question in five seconds.

### 6.4 i18n

Every string above goes through the i18n system in **all seven locales** — `en, ru, uk, es, de, fr, zh` (`frontend/src/locales/*.js`; confirm the list in `LanguageContext.jsx` before assuming seven). Run `npm run check:i18n` before the PR is opened; it must pass. Do not write a second checker.

---

## 7. Workstream W3 — Never report a silent zero

### 7.1 Change the default for new streams

The back-end default (`configBool($config, 'dont_record_safe_clicks', true)`) exists because older streams have no key stored and must keep their behaviour. Sequence:

1. Migration v38 additionally rewrites every existing cloak stream's `schema_custom_json` to contain an **explicit** `"dont_record_safe_clicks": true`, so legacy behaviour is pinned by data rather than by a code default.
2. Flip the code default in `CloakDetector::shouldSkipSafePageClick()` to `false`.
3. Flip the editor's new-stream default object (`CampaignEditor.jsx:1132` area) to `dont_record_safe_clicks: false`, and always serialise the key explicitly so no future stream depends on a default at all.

Net effect: no existing installation changes behaviour; every stream created after the upgrade logs its safe traffic.

### 7.2 Re-label the checkbox

Current copy (`en.js:2684`) promises clean reports and does not warn about the diagnostic cost. New copy:

- Label: *"Exclude Safe Page clicks from reports"*
- Hint: *"Safe Page hits are still logged and visible in the click log, but are not counted in campaign metrics, cost, or CPC. Uncheck 'Log Safe Page clicks' below to drop them from the database entirely."*

This splits one checkbox into two orthogonal decisions, which is what the operator actually wants:

| Setting | Key | Default (new streams) | Effect |
|---|---|---|---|
| Log Safe Page clicks | `log_safe_clicks` | `true` | Row is written to `clicks` with `is_safe_page = 1`. |
| Exclude Safe Page clicks from reports | `exclude_safe_from_reports` | `true` | Rows with `is_safe_page = 1` are filtered out of campaign metrics. |

`dont_record_safe_clicks` is kept as the legacy input: when present and `true` and the new keys are absent, it maps to `log_safe_clicks = false`. Migration v38 performs that mapping explicitly.

### 7.3 Suppressed-hit counter

Even with logging off, the operator must see that *something arrived*. Add table:

```sql
CREATE TABLE IF NOT EXISTS cloak_suppressed_stats (
    campaign_id INTEGER NOT NULL,
    stream_id   INTEGER,
    day         TEXT NOT NULL,          -- 'YYYY-MM-DD' UTC
    verdict     TEXT NOT NULL,
    reason      TEXT NOT NULL DEFAULT '',
    hits        INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (campaign_id, stream_id, day, verdict, reason)
);
```

Written with a single `INSERT … ON CONFLICT DO UPDATE SET hits = hits + 1` whenever a click is *not* persisted because of safe-page suppression or `collect_clicks = 0`. One statement, no read, no join — acceptable on the click path. It feeds `cloak_summary.suppressed` and §6.3.

### 7.4 Report filtering

Campaign report queries that aggregate `clicks` must apply `AND is_safe_page = 0` when the stream/campaign has `exclude_safe_from_reports` on. Audit and update every aggregate touching `FROM clicks`: `api.php` metrics, `CampaignReports`, `StatCards`, `MainChart`, `CohortView`, the MCP `orbitra_get_metrics` / `orbitra_campaign_report` handlers, and `core/*` report helpers. **A safe click leaking into CPC or cost is a P1 regression** — this is the risk that pays for the whole workstream, so it needs an explicit test per surface.

---

## 8. Workstream W4 — Geo-targeting safety

### 8.1 Detect readiness

Add to `core/geo_databases.php`:

```php
function orbitraGeoTargetingReady(?string $root = null): array
// => ['country' => bool, 'asn' => bool, 'proxy' => bool, 'files' => [...]]
```

`country` is true when at least one of `geo/IP2LOCATION-LITE-DB11.BIN`, `geo/GeoLite2-City.mmdb` exists, is readable, and passes `orbitraGeoFileStatus()` as `ok`. Result is memoised per request; the check must not stat files more than once per click.

### 8.2 Distinguish "not in list" from "cannot tell"

`CloakDetector::targetingReasons()` gains a parameter (or a `geo_ready` key in `$targeting`). When a country filter is configured and the country resolves to `Unknown` / empty:

- emit reason code **`geo_unknown`**, never `geo_country`;
- route according to a new per-stream setting `geo_unknown_action`:

| Value | Behaviour |
|---|---|
| `safe` (default) | Current behaviour. Fail closed — the safe page is the conservative choice for a cloaker. |
| `money` | Fail open. Documented as "only for operators who accept that unidentifiable visitors reach the offer". |

The default deliberately does **not** change routing on upgrade. The fix is making the cause visible and giving the operator a switch — not silently opening the money page on installations that were relying on the block.

### 8.3 Warn, loudly, before money is spent

- **Campaign editor:** when a cloak stream has a country allow-list and `orbitraGeoTargetingReady()['country'] === false`, render a blocking-style warning directly under the country selector, with a link to the GeoDB page. Use the **existing** key `admin.noGeoDb` (already translated in all seven locales, currently referenced by nothing) as the headline; add one new key for the cloak-specific consequence: *"Every visitor resolves as country Unknown, so this filter will send 100% of your traffic to the Safe Page."* This must appear at edit time, not only after traffic has been wasted. Do not introduce a second string for "no geo database installed" — reuse `admin.noGeoDb`.
- **Save-time API validation:** `api.php` campaign save returns a non-blocking `warnings[]` entry with the same message and code `geo_db_missing`, so the condition is visible to API and MCP clients too.
- **System status:** `api.php` system status already reports `geo_dbs` (`api.php:10837`). Add a `warnings[]` entry when a geo DB is missing **and** at least one active campaign has a country-filtered cloak stream — a missing geo DB on an installation that does not use geo targeting is not worth a warning.
- **First-run:** the same `admin.noGeoDb` banner at the top of the GeoDB page when no country DB is present, so the state is visible on the page that fixes it.

### 8.4 ISP / ASN filter degradation

`bot_isp` matching depends on `$geoData['isp']`, which is empty without an ASN database — the filter then silently passes everything. Surface this the same way: when "Block bot & datacenter ISPs" is on and `orbitraGeoTargetingReady()['asn'] === false`, show *"ISP blocklist is inactive: no ASN database installed."* under the switch. Same for the PX12/proxy layer and the VPN/proxy switch — an operator who thinks four layers are running when two are inert makes bad decisions with real money.

---

## 9. Testing

Follow the project's existing conventions in `tests/` (plain PHP files, run directly, no framework).

**Rejected outright:** tests that assert a string exists in a source file. This has already produced a green suite over reverted code. Tests must exercise behaviour.

### 9.1 Unit

Extend `tests/cloak_targeting_test.php`:

- country allow-list + `countryCode = 'Unknown'` + `geo_ready = false` → reason `geo_unknown`, not `geo_country`.
- same, with `geo_unknown_action = 'money'` → no reason emitted.
- country allow-list + `geo_ready = true` + `countryCode = 'US'` vs allow `IN` → `geo_country`.

Extend `tests/cloak_click_logging_test.php`:

- `log_safe_clicks = false` → no row in `clicks`, one row in `cloak_suppressed_stats` with the right verdict and reason.
- `log_safe_clicks = true` → row exists with `is_safe_page = 1`, `cloak_verdict = 'targeting_safe'`, `cloak_reasons` containing the codes, `isp` / `asn` populated from the visitor context.
- money verdict → `is_safe_page = 0`, `cloak_reasons = ''`.
- legacy stream carrying only `dont_record_safe_clicks = true` behaves exactly as before the upgrade.

New `tests/cloak_verdict_migration_test.php`:

- v37 database with three cloak streams → run migrations → `user_version = 38`, all seven columns present, every cloak stream's `schema_custom_json` contains an explicit `log_safe_clicks` matching its previous effective behaviour.
- migration is idempotent: running it twice leaves the schema and the JSON unchanged.

Extend `tests/geo_databases_test.php`: `orbitraGeoTargetingReady()` against present / absent / truncated / unreadable files.

### 9.2 Integration (HTTP)

New `tests/cloak_click_http_test.php`, modelled on the postback HTTP tests: boot `php -S`, seed a campaign with a cloak stream, then issue real requests with crafted `User-Agent`, `X-Forwarded-For`, and `Accept-Language`, and assert on the response **and** on the resulting database rows. Minimum cases:

| Case | Expectation |
|---|---|
| Indian mobile UA, geo DB present, country IN | money page served, row with `cloak_verdict = 'money'` |
| Same visitor, geo DB absent | safe page served, row with `geo_unknown`, warning surfaced by `cloak_summary` |
| `curl/8.4` UA | safe page, `passive_safe`, reason `crawler_or_tool_ua` |
| Desktop UA with mobile-only filter | safe page, `targeting_safe`, reason `device_type` |
| Suppression on | HTTP 200 with the safe page, zero `clicks` rows, `cloak_suppressed_stats.hits = 1` |

### 9.3 Regression

Run the full `tests/` suite plus `npm run check:i18n` and `npm run build`. Re-run `tests/report_metrics_test.php` and add assertions that safe clicks are excluded from cost/CPC when `exclude_safe_from_reports` is on and included when it is off.

### 9.4 Manual acceptance — the reporter's scenario

Reproduce the original ticket end to end on a staging instance:

1. Cloak stream, all layers on, country allow `IN`, devices mobile+tablet, **no GeoIP database installed**.
2. Send 18 hits with realistic Indian mobile user-agents.
3. **Expected:** the campaign editor shows `18 hits → 0 money · 18 safe`, top reason `geo_unknown ×18`, and the geo-database warning is visible under the country selector.
4. Install a country database, repeat.
5. **Expected:** hits route to the money page, the warning disappears, and the click log shows `Money` with the money landing in the Destination column.

Step 3 is the acceptance bar for this entire specification: the product, unaided, must state the cause.

---

## 10. Acceptance criteria

- [ ] `admin.noGeoDb` is rendered by at least `CampaignEditor` and `GeoDBPage`; no locale key added for it.
- [ ] All three click entry points call one shared logger; no duplicated INSERT column list remains.
- [ ] `clicks` carries verdict, reasons, `is_safe_page`, isp, asn, proxy_type, sensitivity; migration v38 is idempotent and preserves legacy behaviour.
- [ ] The click log shows Route, Reason, Destination, and can filter by campaign / route / reason.
- [ ] `cloak_summary` returns a correct breakdown, including for campaigns with suppression enabled.
- [ ] A campaign whose traffic is ≥90% safe shows the warning banner in the editor.
- [ ] A country filter with no geo database produces reason `geo_unknown`, a warning in the editor, a `warnings[]` entry on save, and a system-status warning.
- [ ] Inactive detection layers (no ASN DB, no PX12) are labelled as inactive in the UI.
- [ ] Safe clicks never appear in campaign cost, CPC, CR, or chart series while `exclude_safe_from_reports` is on.
- [ ] Click-path p95 regression ≤ 5 ms, measured and reported.
- [ ] All new strings present in all seven locales; `npm run check:i18n` passes.
- [ ] Full `tests/` suite green, including the new HTTP test.
- [ ] `docs/cloaking.md` updated: verdict codes, reason codes, the two new checkboxes, `geo_unknown_action`, and a "why is all my traffic going white?" troubleshooting section.
- [ ] `version.json` (with `release_notes` and `released_at`) and `version.php` bumped in sync; `CHANGELOG.md` `[Unreleased]` section moved to the new version with its date.

---

## 11. Delivery notes

- One `git worktree` per agent if this is parallelised. Four agents sharing one tree has already cost a full round: a `git checkout <file>` intended to undo one agent's work silently reverted another's. `git checkout <file>`, `git restore`, and `git stash` are forbidden in agent prompts on this repository.
- Verify by running things, not by reading reports. Work has been reported complete twice while not present on disk.
- **Phase 0 — ship first, independently of everything else (½ day).** Wire the dead `admin.noGeoDb` key into `CampaignEditor.jsx` (next to the cloak geo filter) and `GeoDBPage.jsx`. No schema change, no API change, no new locale keys, no behaviour change to routing. This alone would have closed the originating ticket, and it can go out in a patch release while the rest of this specification is still in review.
- Suggested sequencing after that: A-1 (shared logger) → W1 → W3.3 (counter) → W2 → W4 → W3.1/7.2/7.4 (defaults and report filtering last, since they are the only behaviour-visible changes and want the tests from W1/W2 already green underneath them).

## 12. Open questions

1. **Retention.** Safe-page rows will multiply click volume on aggressively filtered campaigns. Do we need a retention policy (e.g. purge `is_safe_page = 1` rows older than N days) in this release, or is `migrate_archive.php` sufficient?
2. **Reason vocabulary.** Reason codes are currently internal identifiers. Do we expose the raw codes with tooltips (proposed) or translate them fully, losing greppability against the error log?
3. **`geo_unknown_action` default.** Proposal keeps `safe`. An argument exists for defaulting new streams to `money` *only when no country filter is configured at all* — but that case emits no reason today either, so it is out of scope unless product disagrees.
4. **PX12 residential classification.** If field data confirms Indian carrier ranges are returned as `RES`, should `RES` be demoted from hard to soft signal, or gated behind a separate "Block residential proxies" switch? Needs data before deciding; out of scope for this release.
