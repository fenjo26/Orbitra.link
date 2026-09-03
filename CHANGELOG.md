# Changelog

All notable changes to **Orbitra Tracker** are listed here. The full release
notes for each version also live in [README.md](README.md) (English) and
[README.ru.md](README.ru.md) (Russian) under the *What's New* / *Что нового*
sections.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.5.0] — 2026-09-03

First public release of the PWA + web-push stack (53 commits since v1.4.1):
PWA landings, web push campaigns on your own base, the content gallery — see
[docs/pwa-push.md](docs/pwa-push.md) and [docs/content-gallery.md](docs/content-gallery.md)
for the full guides. Findings from the v1.4.1 acceptance pass on the test
server (PWA phases 1–4) and the delivery-hardening campaign that followed.

### Fixed

- **`{subid}` reached the affiliate network as a literal string on the landing→offer
  hop** — the `/?_lp=1` transition ran the destination URL through
  `applyOfferMacros()`, which substituted `{clickid}` but not `{subid}`, while the
  main click flow substituted both. Every funnel with a landing page in it and
  `{subid}` in the offer URL therefore sent `cid={subid}` instead of the click id,
  and the network's sub-id parsing had nothing to bind a conversion to. The two
  paths now substitute the same set — `{clickid}`, `{subid}`, `{ip}`, `{country}`,
  the extracted tracking parameters and `{offer_id}` — and both drop the macros a
  click carried no value for, so a leftover `{utm_term}` no longer travels either.
- **A domain-bound PWA could not register its service worker** — a PWA served from
  a domain root registers `/lander/<slug>/sw.js` with `{ scope: '/' }`, which the
  browser only accepts when the script response carries `Service-Worker-Allowed`.
  PHP set that header, but `sw.js` was then handed to nginx with `X-Accel-Redirect`,
  which carries only a fixed set of upstream headers into the response and dropped
  it (along with `X-Content-Type-Options`) — so no worker was installed and push
  subscription on a bound domain was impossible. Service workers are now streamed by
  PHP itself (a few KB, once per install, so the freed-worker argument for X-Accel
  does not apply), and the generated vhost — plus the `install.sh` baseline —
  restores both headers on the internal assets location as a second line of defence.
- **`session_lifetime` was dead weight, so the panel expired after 24 minutes** —
  the setting has been seeded at 86400 since migration 8, but nothing ever read it:
  PHP's own `gc_maxlifetime` default of 1440 seconds applied, and an admin who left
  the panel open came back to every request answering 401 at once. The bootstrap now
  resolves the lifetime (constant → environment `ORBITRA_SESSION_LIFETIME` → the
  settings table, clamped to 5 minutes … 30 days), applies it to `gc_maxlifetime`,
  sweeps its own session directory (which the distribution's cleanup cron never
  sees), stamps each request so an active session never looks stale to an
  mtime-based cleaner, and enforces the idle cut-off itself.
- **The push subscriber list reported "0 subscribers" with subscribers in it** —
  `push_subscribers` called `fetchColumn()` on a prepared but never executed
  `COUNT(*)`, which returns `false`, so `total` was always 0 and the page count with
  it (the list could not be paged past the first 50).
- **A busy database answered with a raw SQLite error** — the every-minute crons hold
  the write lock in bursts, and any action unlucky enough to collide with one showed
  the operator `SQLSTATE[HY000]: database is locked`, which reads like data loss.
  Lock contention now answers `503` with `Retry-After` and a plain "the database is
  busy, nothing was changed, repeat in a few seconds"; the SQLite message goes to the
  error log with the action that hit it.
- **`save_user` silently demoted a user when the request omitted `role`** — the
  round-trip that protects stored permissions did not cover the rest of the row, so
  a partial save reset the role to `user`, blanked the email and reset the language.
  Absent keys now keep their stored values, exactly as `permissions` already did.

- **The PWA editor's domain picker was empty for anyone who had saved once** —
  `pwa_domain_options` treated `landing_id` as a filter ("the domains bound to this
  landing"), and the editor passes the landing it is editing. Before the first save
  the select is disabled; after it, the endpoint answered with the domains bound to
  a landing that was bound to nothing — an empty list, so no domain could ever be
  chosen. The parameter is now accepted and ignored: the client already finds its
  own binding by matching each row's `pwa_landing_id`, which is why every row
  carries it. Older frontend bundles keep working without a rebuild.
- **Every domain could sit at "waiting for DNS" with an empty server IP** —
  `core/ssl_manager.php` carried a private copy of the server-address detection
  ladder that did not know about the `server_ip_override` setting. On a machine
  where autodetection fails (no outbound HTTP, or a NAT-private egress address),
  the operator would set the address by hand, watch the settings banner confirm it,
  and still see every certificate stall, because the SSL gate was comparing A
  records against an empty string. It now delegates to the ORB-005 detector in
  `core/server_ip.php` — the same one the banner reads — and that detector falls
  back to a stale cache entry rather than answering "unknown" when a lookup fails.
- **"Re-issue certificate" died on `database is locked`** — the button shells out
  to certbot for tens of seconds and then writes, with the 5-second lock wait meant
  for ordinary page requests. An every-minute cron holding the write lock turned it
  into a raw `SQLSTATE[HY000]: General error: 5 database is locked` and changed
  nothing. Certificate re-issue and domain deletion now wait longer and retry the
  write, through the shared helper in `core/db_retry.php` — the same treatment the
  SSL queue worker already gave itself.

### Fixed — push delivery hardening (live-device diagnostics campaign)

- **The installed app never registered its service worker** —
  `navigator.serviceWorker.register()` sat at the bottom of the page script,
  below the standalone-app branch, so in the installed app (the only place
  iOS asks for push permission) no worker ever existed, and iOS home-screen
  web apps get a separate storage partition, so the Safari registration never
  carried over. Registration is now the first statement of the script and the
  whole subscription runs inside the worker (`orbitraSubscribe`,
  `pushsubscriptionchange`, activate-time self-heal, all under `waitUntil`);
  the page posts a message and leaves for the offer.
- **A slow-device subscribe livelock** — the iOS cold subscribe (first APNs
  handshake on mobile data) could exceed the 10s fail-safe, which redirected
  mid-subscription and killed it every time. The fail-safe is now 45s and only
  arms after the user granted permission; the silent sync is capped at 30s and
  no longer re-shows the prompt to users who already said yes.
- **The VAPID `k=` parameter carried a JWK thumbprint instead of the key** —
  RFC 8292 §3.2 says `k=` is the raw public key itself: the push service
  verifies the JWT against the key carried in `k=`. Every provider answered
  403 BadJwtToken. (403)
- **Every request body was padded to a full record — 4100 bytes, over Apple's
  4096 limit** — minimal padding (the 0x02 delimiter alone) per RFC 8291; the
  single-record cap keeps any body at or under 4096. (413)
- **Key derivation ignored RFC 8291** — the secrets combined through one HKDF
  keyed by the record salt instead of HKDF-Extract(salt=auth_secret,
  IKM=ecdh_secret) plus the record-salt stage; receivers derived different
  keys and the GCM open failed silently. The test now also decrypts a captured
  web-push (npm) reference record byte-exact. (201, silent drop)
- **The aes128gcm header omitted the keyid** — for Web Push the keyid IS the
  sender's ephemeral public key, the receiver's only copy; the header went out
  with `idlen=0`, so no browser could run its ECDH half while the service
  answered 201. Record overhead is now 103 bytes, the payload ceiling 3993, a
  max record exactly 4096 on the wire; the roundtrip test reads the keyid off
  the record instead of knowing it out of band. (201, silent drop, any browser)
- **The subscribers-tab test banner lingered over Messages** — the
  "test push accepted" info line survived tab switches and read as if the
  message composer had sent something; saving a message only stores a
  template. Info and error clear on tab change.

### Added

- **PWA landings** — store-style constructor (icon/screenshots from the gallery,
  ratings, reviews, themes, live preview), generated `manifest.webmanifest` +
  `sw.js`, in-app screen, funnel beacons (`pwa_intent_at`/`pwa_install_at`/
  `pwa_open_at` + push funnel columns + `push_fail_reason`), push subscription
  inside the service worker with self-heal on every app open and automatic
  `pushsubscriptionchange` re-subscribe, renderer auto-regeneration on version
  bumps (`RENDERER_VERSION`), `{vapid_public}` substituted at serve time so key
  rotation reaches installed apps, direct **domain → PWA binding**
  (`domains.pwa_landing_id`/`pwa_offer_id`, root serving, organic visits logged
  to the hidden `orbitra-pwa-organic` campaign), landing/PWA duplication.
- **Web push sending** — self-hosted VAPID (RFC 8292) + aes128gcm encryption
  (RFC 8291) in pure PHP; `push_messages` (manual + event kind
  install/lead/sale with `delay_seconds`, segments `all`/`reg0`/`reg1dep0`/
  `reg1dep1`, macros expanded at send time), `push_queue` + `push_sends`,
  `cli/push_cron.php` (every minute: flock, batches ≤300, 429 Retry-After,
  401/403 one retry with a fresh JWT, 404/410 ages the subscription),
  subscriber list with filters/ops/CSV export and a per-subscriber **test
  send** bypassing the queue, queue health strip, `/push_subscribe` ingest
  with endpoint/key validation and attribution-preserving upsert.
- **Content gallery** — media library (`media_folders`/`media_assets`,
  webp/jpg/png/gif ≤10 MB, sha256-named storage, soft delete) with folders,
  drag-and-drop upload and a shared **MediaPicker** (size contracts with
  aspect-locked cropping producing exact-size assets) used by the PWA, landing
  and offer editors.
- `tests/push_sender_test.php` (crypto incl. web-push reference vector),
  `tests/push_macros_test.php`, `tests/push_cron_test.php`,
  `tests/pwa_landing_test.php`, `tests/domain_pwa_test.php`,
  `tests/push_base_test.php`, plus
  `tests/lp_offer_macros_test.php`, `tests/session_lifetime_test.php`,
  `tests/pwa_domain_options_test.php` and `tests/ssl_server_ip_override_test.php`
  covering the fixes above that are worth a regression guard.

## [1.4.1] — 2026-09-01

Bugfix release: two user-reported fixes, no schema changes.

### Fixed

- **Affiliate Networks page crash (issue #7)** — since v1.4.0 the page failed to
  render with `ReferenceError: canWriteResource is not defined`: commit 606ec24
  wrapped the Create button in a permission check but never imported the helper
  from `utils/permissions.js`, and the bundler silently left the bare identifier
  in the production bundle. The import is restored, the check is computed once as
  `canWriteNetworks`, and it now gates every mutation control on the page (create,
  row edit, row delete, bulk delete) — mirroring the write actions of the
  `networks` resource in `core/resource_access.php`. A repo-wide sweep confirmed
  no other module uses a `permissions.js` export without importing it.
- **System Status messages follow the panel language** — the disk/CPU/RAM warnings
  and the database-size recommendations returned by `action=system_status` were
  literal Russian strings, so every user saw "Критически мало места на диске!"
  regardless of the interface language. The API now emits stable `messageKey`
  codes (`diskCritical`, `diskWarning`, `cpuCritical`, `cpuWarning`, `ramCritical`,
  `ramWarning`, `dbOver500`, `dbGrowing`) — the same contract the geo-DB warning
  already used — and the System Status page resolves them through the translation
  dictionary (8 new keys across all 7 locales; the recommendations row learned the
  `messageKey` handling the warnings row already had).

## [1.4.0] — 2026-08-31

Two features in one release: honest landing-funnel metrics with landing→offer
timing (a community report about inflated offer transitions), and real
server-side role enforcement with per-campaign scoping (issue #6).

### Added — honest LP funnel & landing→offer timing

- **Honest transition counters** — *Real LP clicks*, *Real offer clicks* and
  *Real LP CTR* count only clicks whose offer transition (`offer_at`) is
  recorded. A landing view whose visitor never clicked the CTA is no longer an
  "offer transition"; direct-to-offer clicks keep counting as their own
  transition. The new columns sit alongside the legacy ones everywhere —
  campaigns list, the campaign report constructor (with hover hints), the
  offers and landings pages, and the Lander → Offer preset — and the legacy
  columns keep their historical meaning.
- **LP Time grouping** — a new report dimension buckets the landing→offer
  seconds into 0-3s / 3-10s / 10-30s / 30-60s / 60s+ bands. The 0-3s band is
  bot/double-click territory and is usually the answer to "the tracker shows
  transitions but the network sees none". Pairs that never close group as
  Unknown.
- **Landing→offer timing now covers every landing type** — `landing_at` is
  written for redirect and action landings as well (previously local only) and
  by Click API v3 when the stream sends the visitor to an external landing.
- **External landings report their landing time** — tracking.js, kclient.js
  and kclient.php append the visitor's elapsed seconds (`_lt`) to the tracker's
  signed `/?_lp=1` transition link (never to a raw offer URL, so nothing leaks
  to the network); click.php synthesizes the landing→offer pair on its own row,
  and the transition backfills `landing_at` only when the tracker never saw
  the landing view. kclient.js also gains the `data-orbitra-offer` / `{offer}`
  offer-link contract tracking.js already had.
- **Click details** show Landing shown at, Offer transition at and the
  computed Time to offer.

### Changed — LP funnel defaults

- **New landing_offer streams default to "Offer selection: After the click"** —
  the offer is bound (and the transition counted) when the visitor actually
  leaves through the offer link, so LP clicks and LP CTR measure the CTA
  instead of reading ~100% by construction. Existing streams keep their
  setting, and both editor options now carry plain-language captions stating
  exactly what they count.

### Security — roles enforced, per-campaign scoping (issue #6)

- **Permission levels are enforced server-side** for non-admins across
  campaigns, offers, landings, traffic sources, affiliate networks, domains
  and logs: `none` → 403 on everything including the simple picker lists
  (`campaigns_simple` used to hand every campaign to any logged-in user),
  `read` → every write action 403s. The UI mirrors the rule: write buttons are
  hidden for read-only users and the permission modal offers only the real
  levels (Full / Read only / None; the never-implemented Selected/Own modes
  are gone for the other resources).
- **API-key minting is admin-only** — `generate_api_key` / `delete_api_key`
  were open to any logged-in user, who could create a write key under any
  `user_id`, including the admin's.
- **Campaigns gain real per-campaign scoping**: Full / Read only /
  Own + Selected / Selected / None. Migration 42 adds `campaigns.owner_user_id`
  (legacy campaigns are backfilled to the first admin so upgrades never
  orphan them) and a shared scope resolver filters the campaigns list, the
  picker lists, get_campaign, every campaign mutation (save/delete/bulk/copy —
  a copy belongs to the copier), conversions, logs, click details, postback
  logs, trends, cohort, and the dashboard aggregates via
  `getDashboardFilters`. Assigned campaigns are picked in the Users modal
  (Own + Selected / Selected reveal a campaign checklist).
- **Globally destructive actions stay out of scoped users' reach** — clear
  stats, Keitaro SQL import, conversion import and retroactive remap are
  admin/full-only and are not scoped per campaign.

Pinned by `report_metrics_test` (57 checks), the new
`tests/lp_transition_timing_test.php` (31 HTTP checks across the landing view,
signed transition, `_lt` synthesis, honest counters and buckets) and the new
`tests/resource_access_test.php` (52 checks over the admin/full/read/none/
own/selected matrix).

## [1.3.11] — 2026-08-31

The parked-domain release: two community-issue fixes, both backend — no
frontend files changed, so no rebuild is needed. Reported in issues #4 and #5.

### Fixed — parked domains in production (issue #4)

- **A domain's root campaign now resolves in production** — `index_campaign_id`
  ("Campaign to serve on the root path" in the domain form) was honoured only
  by `router.php`, the dev-server entry point; production nginx hands `/` and
  every unknown path straight to `index.php`, which never selected the field,
  so a parked domain's root answered "Campaign not specified." The root now
  serves the domain's campaign, and `catch_404` (previously dev-only as well)
  sends the host's dead paths there too — the alias lookup still runs first,
  so a live alias keeps winning over the domain setting.
- **A Disabled domain 404s the whole host in production** — `router.php`
  already refused everything on a disabled domain; `index.php` silently kept
  serving it. Both entry points now agree.
- An explicit `?campaign_id=` in the URL still wins over the domain setting
  (the workaround from the report keeps working), and the routing id is
  assigned to `$directCampaignId` only — it must not leak into the click's
  captured parameters (`orbitraCollectClickParams` picks up `$_GET` for
  cost-matching). Pinned by `tests/domain_root_campaign_test.php`: 21 checks
  across child-process blocks and a real-HTTP e2e reproduction of the report.

### Fixed — private postback key on install (issue #5)

- **Fresh installs no longer ship the public default key.** The report's
  premise (the settings-table override living inside the migration closure,
  skipped once the schema is current) turned out to be a misreading — the
  override sits outside the closure and runs on every request, and
  `tests/postback_route_test.php` (its Test 4 covers exactly the rotation
  scenario) passes. The report's underlying concern stands, though: `fd12e72`
  is public, so an install that never rotated it accepts forged conversions.
  `install.sh` now runs `cli/generate_postback_key.php` as www-data after the
  ownership handover: it boots the database (a fresh install has none until
  the first request), replaces the default with a random 24-hex key, and the
  install summary prints the finished postback URL. A key the operator already
  changed survives a re-install, existing installs are untouched (rotate in
  Settings → Postback), and config.php now documents that the override is
  deliberately outside the migration closure.

## [1.3.10] — 2026-08-30

The mobile-usability release: two frontend fixes from tester Addendum VI,
both verified in a real browser at 390 / 768 / 1280 px.

### Fixed — Campaigns

- **The name looks like what it is on both surfaces** — the desktop table cell
  rendered the name in `--color-text-primary` at medium while the mobile card
  used `--color-primary` at semibold; both have always opened the editor, now
  both say so. Checked on the light default theme with the orange-red primary —
  the combination the addendum explicitly left unchecked. Landings/Offers keep
  their plain name cells on purpose: one surface first, per the addendum.

### Fixed — CampaignEditor rotation rows

- **Below 640px the row is a placed grid, not a wrapped flex line** — six
  passes of flex-wrap / order / basis each fixed their stated aim and left the
  row wrong: a wrapped desktop row is decided by content width, not design
  (§2.70, whose author never saw his own grid build run). The row is
  `grid-cols-[auto_1fr_auto]` with explicit placements — toggle | name, badges
  and weight | action rail — and `sm:` restores the original single flex row
  (`sm:contents` dissolves the rail); grid placements are inert in flex, so
  nothing at 640px and above moves.
- **The name wraps instead of truncating at phone width** — a truncated offer
  name told the reader nothing (§2.62's original complaint); `break-words`
  below sm keeps long names readable — a four-line wrap verified with zero
  horizontal overflow at 390px.

## [1.3.9] — 2026-08-28

The SSL-and-lead-integrity release: four audited bugs fixed, the domain
workflow rebuilt end to end.

### Fixed — SSL (Bug 1, docs/TZ_SSL_CHAIN_AND_PRIVACY.md)

- **incomplete_chain vs an unreadable file** — `orbitraChainVerdict()` is three-state (ok / incomplete_chain / chain_unreadable); unreadable keeps status installed with a stored warning and burns no retry attempt, shown as an amber warning in the panel. Reads and existence checks work through the web user (`orbitraReadPrivilegedFile` with sudoers rules for the PUBLIC chain files only — privkey never; `orbitraLetsEncryptCertExists` via `sudo certbot certificates` under the existing sudoers rule). The re-issue button really re-issues: `sudo certbot delete` (only when a line exists; exit code checked and surfaced) + `--force-renewal` on the manual path. Migration 41 resets the backoff of domains falsely failed with incomplete_chain; schema 40 → 41.
- **Certificates issue on save** — `orbitraRunSslWorkerNow()` replaces the four `> /dev/null &` fire-and-forget sites: the worker (separate process, lock-retried writes) runs synchronously with its outcome in the response; PHP-FPM gets `set_time_limit(120)`; the sync run caps at 3 domains so bulk pastes answer fast. The worker cron moves hourly → every 5 minutes (upgraded in place by `orbitraEnsureSslCron()`), is flock-guarded (`var/ssl_worker.lock`) against certbot lock collisions, and survives locked-SQLite runs (`orbitraSslWriteWithRetry`). The Add-Domain submit shows a spinner with honest copy (domains.parkingSsl ×7).
- **install.sh opens 80/443 in ufw** when it is active — a fresh cloud VM with an SSH-only firewall showed a perfectly green install that was unreachable from outside. Also ships `cli/ssl_diagnose.sh` (read-only, root-vs-web-user comparison with an automatic verdict).

### Fixed — LeadForge (Bug 4)

- **No more fabricated lead success** — the `default:` branch of the generated order.php returned `{"status":"ok"}` without any HTTP request for adapter-less networks (adcombo, m1, monsterleads, trafficlight were selectable). Now: error log event, CRM-vault snapshot, honest 502 to the visitor, no tracker conversion. `LeadForge::networks()` is the single source of truth (label/placeholder/currency/payout + adapter flag) served by `GET leadforge_networks`; the frontend's drifted hardcoded list is gone; `buildBundle` refuses unknown / adapter-less networks and custom without an endpoint URL. Real AdCombo adapter per the public Incoming Orders API; m1/monsterleads stay adapter-less on purpose (cabinet-only specs — a blind adapter ships fabricated fields). Selector gains a My affiliate networks group (active affiliate_networks rows, read-only) with endpoint prefill and built-in adapter suggestions by name signature — offered, never substituted silently.

### Fixed — settings (Bug 2)

- **Scan protection saves and works** — privacy_enabled/action/redirect_url added to both global_settings whitelists with cross-field validation (redirect requires a valid http(s) URL), admin-only writes, GET backfill; unknown settings keys fail loudly (`unknown_settings` + the ignored list) instead of being dropped with status:success. index.php applies the chosen 302 / 404 / blank to unknown-alias requests (service routes unaffected). Verified live per action on a repo copy.

### Fixed — Domains (Bug 3 + rebuild)

- Custom-SSL fields reachable (the gate omitted ssl_source while the Custom button clears cloudflare_proxy); the table joins the centred fixed-layout system (static 9-column colgroup, dividers, 0 overflowing cells); two-row toolbar with a separate bulk-actions bar; Add-Domain modal restructured (flex-auto labels, full-width toggles, SSL-mode block relocated); `.page-table th` left-align trap fixed for dual-class tables; domains.sslModeAutoHint ×7.

## [1.3.8] — 2026-08-28

Table-polish follow-up to v1.3.7: the ellipsis only appears where something
is actually hidden, and values line up under their headers.

### Fixed — table cells

- **Stray "…" markers** — `text-overflow` cannot elide an atomic inline box (a flex wrapper, an input, an icon button), so a cell made of controls drew an ellipsis beside fully visible content the moment the content box came up a fraction short. `td`/`th` clip by default now; only real-text cells (`.cell-text`: id, group, metrics, URL, totals) keep the ellipsis, where the title tooltip still shows the full value. Plain headers (Actions, Payout) opt in via `th.cell-text`; SortableTh's inner `.truncate` was always correct.
- **Checkbox column** — 40px minus two 14px side paddings left 12px for a 14px checkbox: permanent overflow, permanent "…" next to every checkbox. `.col-check` gets zero side padding; verified non-overflowing across the tracker tables.
- **Centred values** — every tracker-table value, numbers included, centres under its centred header (inline `textAlign: right` removed from Campaigns/Offers/Landings/CampaignReports; `.action-buttons` justifies centre); the report tree column keeps left alignment so its depth indents survive.

### Changed — code hygiene

- All 31 ESLint errors cleared in the four tracker-table components: optional catch bindings (with a reason comment where the error is intentionally ignored), dead `settingsOpen` state, an unused `nextSortState` import and the orphaned `entityLabel` helper removed, and the duplicate `epv` key dropped from the Campaigns totals object. The 5 `react-hooks/exhaustive-deps` warnings are deliberately kept — dependency changes alter runtime behaviour and need per-case analysis.

### Docs

- `docs/TZ_SSL_CHAIN_AND_PRIVACY.md` — specification for the next fix wave: SSL chains stuck in `failed / incomplete_chain` (unreadable cert files conflated with genuinely incomplete chains), privacy settings that never persist (whitelist gap + unimplemented feature), the custom-SSL fields hidden behind an inverted gate, and the LeadForge CPA network selector — including the four adapter-less networks (`adcombo`, `m1`, `monsterleads`, `trafficlight`) whose generated `order.php` returns a fake 200 without any HTTP request, silently dropping leads.

## [1.3.7] — 2026-08-27

Table-UX release: the campaigns table's fixed identity columns become full
peers of the metric columns, and every cell clips at its own boundary.

### Added — table columns

- **Full column reorder.** One unified, persisted column order
  (`orbitra_campaign_col_order`) drives the header, the body rows, the
  totals row and the colgroup — fixed columns (ID, Status, Campaign,
  Actions, Group) and metrics drag alike, and the order survives reloads.
  Header/body/colgroup desync is structurally impossible: there is only
  one list. The old metric-only reorder (which rewrote the customizer's
  visibility list) is replaced; reconciliation keeps the stored order in
  step with customizer and finance-filter changes without storage
  migrations — fixed ids always render, metrics only while visible, new
  metrics append.
- **Sort arrows and resize handles on the fixed columns.** ID, Status and
  Group show their sort arrows again (the columns always sorted on click —
  the affordance was hidden since v1.3.2), and all five carry resize
  handles with per-browser width persistence. Actions renders through the
  shared `SortableTh` with a new `sortable={false}` prop (grip + resize,
  no arrow — nothing to sort by); the checkbox column stays locked as the
  40px anchor.
- **Readable drags.** A column reorder shows a compact opaque chip naming
  the column (`setDragImage` + `.col-drag-ghost`) instead of the browser's
  translucent snapshot of a whole wide header floating over the table; the
  drop target highlights with an opaque card background and a primary
  bar instead of a washed-out tint.

### Fixed — tables

- **Narrowed columns stop painting over their neighbours.** `overflow` on
  `td` defaults to visible: with `table-layout: fixed` a narrowed column
  rendered its nowrap content straight over the neighbouring cell's text
  and icons, while only the header ever clipped (via `.resizable-th`).
  Every `.tracker-table` th/td now has `overflow: hidden` +
  `text-overflow: ellipsis` — long content becomes "Name…" or a hard cut
  at the cell border. Rows paint an opaque background (the hover class
  still swaps the whole row), and buttons/icons inside flex cells keep
  their size (`flex-shrink: 0`) and are clipped rather than deformed.
- **A long campaign name truncates inside its cell.** `truncate` is a
  no-op on inline boxes and the name was an inline `<span>` in a
  colgroup-fixed 300px cell — it rendered onto the Group and Actions
  cells. The span is `block` now; the full name stays in the tooltip.
- **Header labels centre within their column in every table**
  (`.tracker-table` and `.page-table`; data cells keep semantic
  alignment — numbers right, names left). Vertical middle alignment
  extends to `.page-table` — Domains, Networks, Sources, Trends and
  Conversions join the tables that already had it.
- **The Actions column is sized for its four controls** (150px, was the
  110 sized for three — the kebab landed on the Group column's text).
  The Namecheap toolbar buttons render disabled until the status
  resolves (including on failure) instead of popping in after the fetch
  and reflowing the toolbar.

## [1.3.6] — 2026-08-27

Bugfix-and-performance release porting the tester's addendum V — the 14 items
of that report that are real in this repository. Left out by decision: the
§1.39 metric-semantics rewrite (our formulas are pinned by the operator's
v0.9.9.2 resolution and tests), §1.40 safe-page per-campaign exclusion (the
tester's own port broke his panel with 500s and he reverted it), and the
bot-feed feature suite (a feature, not a fix — separate plan).

### Fixed — metrics

- **The Visitors column finally counts visitors.** `visitors` was a duplicate
  alias of `SUM(uniq_global)` at four query sites (two in `api.php`, two in
  `ReportMetrics.php`), so the panel could show Clicks *higher* than Visitors
  — an impossible relationship, since a click cannot happen without a visit.
  It is now `COUNT` over the same filtered rows; uniqueness keeps its own
  `unique_clicks_global` column. The pinned test expectation was asserting
  the old alias and is updated with a comment.
- **The Campaigns totals row agrees with the backend again.** `grandTotals`
  had no `visitors`/`unique_clicks_global` keys (the totals read 0 / dash
  while rows totalled correctly), and its own copies of `cpv`/`epv` divided
  by `lp_views` where the backend divides by clicks. The keys are added and
  the formulas now mirror `orbitraComputeDerivedMetrics`, including eCPC and
  eCPM which the totals row could not show at all.

### Fixed — backend

- **The deferred DNS refresh survives its second domain.** The v1.3.5
  deferred pass prepared its UPDATE once and reused the handle across
  iterations of `orbitraResolveDomainDnsState()` — which issues its own
  queries on the same connection between them. On the live FPM install the
  first domain refreshed and every one after failed with SQLITE error 21;
  error 5 (lock contention against the every-minute crons) simply lost the
  refresh. The statement is now prepared per iteration and the write retries
  three times with a 250 ms pause on a `locked` message. The same
  prepared-per-connection fix applies to the ad-credentials loop in
  `ad_entity_statuses`, which had the identical reuse shape.
- **New: ad-entity status cache (schema 40).** Every Ad/AdSet/Ad Campaign row
  in a report carries a live status toggle, fed by one Graph call per entity
  per open — roughly 25 requests on an ad-level report. Under a rate limit
  every call failed and every open retried all of them, so the account never
  got a window to clear the limit (1,064 logged `User request limit reached`
  errors on the tester's install). Successes now cache for 5 minutes and
  failures for 15; a cached failure serves nothing rather than a fabricated
  ACTIVE, and the panel's own toggle invalidates its row on success.

### Fixed — panel

- **The dashboard poll no longer fires on every tab.** Seven parallel
  requests every 10 seconds, gated on login alone — ~60,000 a day against
  the single SQLite writer, and the actual cause of "reports are slow"
  (the report SQL itself measures 47 ms). The interval is scoped to the
  Dashboard tab, slowed to 15 s, and skipped while the tab is hidden.
- **The date range survives reloads and navigation.** Campaigns persists its
  range in localStorage, the report overlay in sessionStorage (the Report
  button clears it, so every fresh open starts at the default). A stored
  preset is kept by id and re-derived through `getPresetDates()` on mount —
  "today" still means today tomorrow, not yesterday's dates under a today
  label. The picker passes its preset id as an optional third `onChange`
  argument and accepts an `initialPreset`, so a restored range highlights
  the right chip; the other four callers are unchanged.
- **A refresh inside a report returns to that report.** The overlay is
  mirrored in the URL hash (`#report`, `#report/<id>`), read on mount to
  seed both the overlay and the campaign selection, and kept in step via
  `replaceState` — Back still leaves the page rather than walking through
  every open and close.
- **The Campaigns Actions column fits its four controls** (three quick
  actions + kebab): 150px instead of the 110 sized for three, which landed
  the kebab on the Group column's text.
- **The Namecheap toolbar buttons no longer reflow the toolbar.** They
  rendered only after the status fetch resolved, so the layout depended on
  network timing. Both render from first paint, disabled until the status
  answers (including on failure — a dead check must not leave dead buttons).
- **Rotation rows wrap below `sm`.** The name block takes `basis-full` so
  name and badges get their own line with the weight and controls beneath;
  at phone width the three `flex-shrink-0` siblings squeezed the name to
  zero and `truncate` removed it entirely. `sm+` restores the single-row
  layout unchanged.
- **The rotation toolbar group wraps at all five schema sites** — the inner
  group holding AUTO / Split Evenly / Add had no wrap and ran past the card
  once Auto added a fifth control.
- **The Conditions popover is a bottom sheet below `lg`** (its 320px
  right-anchored panel hung off the left edge of a phone); the existing
  fixed backdrop provides the tap-outside exit. Desktop positioning is
  untouched behind `lg:` prefixes.
- **The date picker panel is a bottom sheet below `sm`** with
  non-shrinkable preset chips — `whitespace-nowrap` does not stop a flex
  child being squeezed, and the labels compressed into each other.

## [1.3.5] — 2026-08-26

Mobile-first bugfix release porting the tester's addendum IV — all 12 items
of that report that exist in this repository. His fork-only work (the
aurora theme arc, the ash theme removal, a content-fade wrapper) stays out,
as does the `user_preferences` infrastructure proposal, which needs its own
plan rather than a port.

### Fixed — mobile panel

- **Domains, Affiliate Networks and Traffic Sources render as stacked cards
  below `lg`.** The three pages were still seven-to-nine-column tables
  reached by dragging sideways on a phone. The complex cells — the domain
  status badge (a seven-branch conditional), the SSL cell, the actions row,
  the source URL cell (link + HTTP status + two conditional check buttons)
  — are extracted into shared render helpers both views call, so the
  desktop table and the mobile cards cannot drift apart.
- **Modals below 480px are full-height sheets** (`100dvh`, safe-area
  insets; `viewport-fit=cover` was already set). A centred floating card
  left the footer of tall forms below the fold with the page visible
  behind it. The sheet geometry is `!important` because several modals
  carry inline width/padding/border-radius on `.modal-content` — scoped to
  the media block only.
- **Mobile card titles clamp to two lines** instead of truncating to a
  shared prefix — `Facebook Ads - [IN] - P…` made four consecutive
  campaigns indistinguishable. Actions moved down to the subtitle row, so
  every card keeps one header shape.
- **Toolbar controls release their fixed widths below 480px**
  (`tb-release`/`tb-search`/`tb-hide-sm`): the fixed 140–260px inputs and
  selects share rows instead of stacking one per line, the main search
  takes a full row, and labels the icon already conveys (the word
  "Columns", "Search:"/"Status:") hide.
- **The report's pinned name column unpins below 480px** — at phone width
  it consumed most of the table. Implemented with an explicit
  `report-pinned-name` class on exactly the three left-pinned cells, not
  an attribute selector, so the bottom-pinned totals row cannot be caught.
- **The Conversions filter grid stops overflowing** — `auto-fit` collapses
  to one column below the `minmax` floor while the search cell still
  spanned two.
- **Five `min-width: auto` overflow traps fixed** (the Update page git
  block, the Campaign Reports and Trends tables, the Affiliate Networks
  table that had no scroll container at all): `overflow-x: auto` without a
  width constraint is a no-op when the flex item is free to grow instead.
  `<main>` gets `overflow-x: clip` — not `hidden`, which would create a
  scroll container and break sticky table headers — so one wide child can
  never drag the viewport sideways. The report table also gets
  `touch-action: pan-x` so iOS does not lock the sheet to one scroll axis.
- **84px top padding below 480px** — `pt-32` (128px) is sized for the
  desktop navbar and stranded the page title in empty space on phones.

### Fixed — backend

- **The domains endpoint honours its DNS cache TTL.** `$dnsCacheTtl` was
  computed and never consulted — a cached status was served "regardless of
  age", so it could be stale forever. Stale rows now serve their last known
  status instantly and are refreshed after the response via
  `fastcgi_finish_request()` (capped at the same 20 lookups; on non-FPM
  SAPIs the deferred pass falls back to an inline refresh with the
  remaining budget, so staleness cannot outlive the TTL on dev installs
  either). The per-lookup debug `error_log` is gone — it turned
  `php_errors.log`, the early-warning channel for click loss and the
  Cloudflare automation, into routine chatter.
- **The SSL queue selects `ssl_source`** and writes an
  `awaiting_dns_for_ssl_switch` reason into `ssl_error` when the Cloudflare
  auto-detect overrides an explicit Let's Encrypt/custom choice, instead of
  silently clearing it. The column was missing from the queue's own SELECT
  — a guard reading a column the query never fetched fails open and
  silent. The code is translated in all 7 locales.
- **Login failures return an `invalid_credentials` code** the frontend maps
  through `t()`, falling through verbatim for anything else — an English
  panel no longer greets a wrong password with Russian prose. The remaining
  ~54 localised `message` strings migrate incrementally, one handler at a
  time.

## [1.3.4] — 2026-08-26

Bugfix release porting the tester's addendum III — the part of that report
targeting this repository. His fork-only work (a themed `Dialog` component to
replace native `alert`/`confirm`, Cloudflare zone automation, the
`clicks_unsaved.jsonl` spill file) stays out, as do the two host-configuration
items (`ProtectSystem=full` drop-in, the `ssl-cert` group) which are documented
in `CERTBOT_SETUP.md` territory, not source.

### Fixed — domains & SSL

- **Import from Namecheap was dead on Domain Management.** The toolbar button
  wired `onClick={openImport}`, so React passed the `SyntheticEvent` as the
  account id; the `??` chain accepted it and `JSON.stringify` threw on the
  circular `__reactFiber$` reference — the request never left the browser and
  the modal showed a generic network error. The handler now accepts only a
  real numeric id (`typeof accountId === 'number'`) and falls through to the
  active account, so future miswiring is inert.
- **Opening and saving a domain reset its SSL configuration.** The edit modal
  never loaded `ssl_source`, `custom_ssl_cert` or `custom_ssl_key`, so Save
  wrote the defaults over the stored values — including wiping custom
  certificate paths. All three load from the row now (the list API already
  returns them via `SELECT d.*`).
- **The Cloudflare proxy toggle changed the tracker's row and nothing else.**
  `save_domain` never called `CloudflareApi::upsertDnsRecord()`, so turning
  the proxy off left the orange cloud in place, the SSL queue's pre-flight
  kept seeing edge IPs (`waiting_dns`), and the next hourly run's
  auto-detect flipped the flag back — a control that appeared dead and
  self-reverting. The previous flag is now read *before* the UPDATE and a real
  change is pushed to Cloudflare through `orbitraCloudflareSyncDomain()`
  (which gained a `$proxiedOverride` parameter; parking behaviour is
  unchanged). Success clears the cached `dns_status`/`dns_checked_at` (the A
  record just changed target) and the response carries `cloudflare_sync`;
  failure is `error_log()`ed without aborting the save.
- **The proxy toggle and the SSL Mode buttons contradicted each other.**
  `ssl_source` is now the single source of truth: the Cloudflare button
  highlights on `ssl_source` alone (it used to light simultaneously with
  Let's Encrypt when the proxy was on), every writer keeps both fields in
  step (proxy on promotes to `cloudflare_origin`, off demotes it back to
  `auto`, Custom clears the proxy — an origin cert behind the proxy is Full
  Strict, a different setup), and all writers use booleans instead of a
  boolean/number mix. On load, an auto-detected proxied domain
  (`ssl_source = 'auto'`, flag 1) still shows the flag on, so an unchanged
  save cannot silently flip it.
- **One locked write aborted the whole hourly SSL queue run.** The
  Cloudflare auto-detect UPDATE inside `orbitraProcessSslQueue()` ran without
  error handling, and a single contended SQLite write terminated the cron for
  every remaining domain. Wrapped in `try`/`catch (\Throwable)` +
  `error_log()`, matching the treatment the login and Namecheap counters
  already received.
- **Certificate source invisible in the SSL Status column.** The source
  (`letsencrypt` / `cloudflare_origin` / `custom` / `self_signed`) lived only
  in the icon's `title` attribute; it is now labelled beside the icon for the
  `cloudflare` and `installed` states.
- **Domain Management toolbar: labels wrapped mid-word and Check DNS ignored
  the theme.** The row wraps as a unit (`flex-wrap`) with `whitespace-nowrap`
  labels, and Check DNS is a `btn-secondary` instead of a hardcoded success
  fill that outshouted the primary action and broke under theming.

### Fixed — network & diagnostics

- **Namecheap and Cloudflare calls could stall on IPv6.** The tester measured
  `curl -6` failing outright on his host while `curl -4` succeeded;
  `CURLOPT_CONNECTTIMEOUT` applies per connection attempt, so a stalled AAAA
  connect consumed the whole `CURLOPT_TIMEOUT` budget before IPv4 was tried.
  `CURLOPT_IPRESOLVE_V4` is now set in `NamecheapClient`, `CloudflareApi`,
  `CloudDetector` and `CurrencyRates`, matching what the ad-API clients
  already did.
- **38 catch sites replaced the real error with a constant.** `t('common.networkError')`
  swallowed `e` at every failure site in the panel, discarding diagnostics the
  backend goes to real trouble to produce (the Namecheap IP-whitelist hint,
  HTTP statuses) and hiding client-side exceptions that never touched the
  network. All sites now prefer `e?.message` with the constant as fallback.

### Fixed — i18n

- **`domains.sslCloudflare` was defined twice in all 7 locales.** The SSL
  status tooltip (line ~891) silently overrode the SSL Mode button label
  (line ~837), so the button rendered the long tooltip text in every
  language. The status key is renamed `sslCloudflareStatus`, and a
  `sslSelfSigned` label joins the set for the status column.

## [1.3.3] — 2026-08-25

Bugfix release porting the portable half of the tester's addendum II. The
other half of that report (aurora/carbon/terminal/ash/slate-mono themes, the
neon retune, a 17-theme pre-paint) targeted the tester's own forked theme
system, which has never been part of this repository and stays out.

### Fixed — build size

- **Production builds shipped unminified.** `build.minify: false` in
  vite.config.js read like a debugging convenience that was never reverted.
  `minify: 'esbuild'` ships identical behaviour at 3.16 MB / 891 kB gzipped
  instead of 5.26 MB / 1.11 MB gzipped. The follow-on chunk-size warning is
  acknowledged; `manualChunks` vendor splitting was deliberately deferred
  because it touches import structure.
- **Rebuilds were not reproducible: content hashes changed on every build.**
  Tailwind v4's automatic source detection scanned `frontend/dist/` (tracked,
  so not ignored), so each build's output changed what the next build saw —
  a control rebuild could never come back clean. `@source not "../dist"` in
  index.css pins the scan surface; consecutive builds now produce
  byte-identical assets (and ~0.5 kB less CSS compiled out of build-output
  strings).

### Fixed — report dimensions

- **`isp` and `asn` were collected but unreachable** — populated clicks
  columns absent from `$allowed_dimensions` (campaign_report). Added with
  `NULLIF(..., '')` so unresolved lookups group as Unknown instead of
  forming a bucket of their own.
- **Seven dimensions added to the Group By picker**: Campaign Name, AdSet
  Name, Ad Name, UTM Placement, Source, ISP, ASN. The name/label dimensions
  resolve through the existing generic `param_*` / `custom_*`
  parameters_json handler (no backend entry needed); verified live —
  `param_source` groups real `google` clicks.
- **The picker's dimension list was the fourth copy of a list that must
  agree with three others** — label map, i18n map, and a hardcoded inline
  array that ended at `sub_id_5`; nothing enforced agreement, and a missing
  entry made the dimension invisible with no error. ReportCustomizerModal
  now declares a single `REPORT_DIMENSIONS` registry: the picker renders
  from it and both maps are derived. New labels added to all 7 locales.

### Fixed — panel chrome

- **The login screen ignored the theme system entirely** — a hardcoded gray
  gradient page, white card, indigo accents and slate borders meant the
  first screen of the product was a white rectangle on dark themes. Rebuilt
  on `--color-*` tokens across all 12 modes; the fake terminal in the
  password-recovery modal stays dark by design. `:root` also gained the
  missing `--color-warning-border` / `--color-danger-border` (light
  palettes inherited nothing before).
- **Dark themes flashed the light default on every cold load** — the
  attribute was only set once App.jsx's theme effect ran. index.html now
  applies the saved theme before first paint with a shape-checked inline
  script (no duplicated allowlist: an unknown id matches no CSS block and
  React canonicalizes on mount; `custom` is skipped), and paints a static
  splash inside `#root` that React replaces on mount.
- **Update and worker-health banners hardcoded six amber Tailwind classes**
  — unreadable on dark themes despite the warning tokens existing in every
  theme block. Both banners use the tokens now, banner text uses text
  tokens (the icon carries the signal), and the clickable area gained a
  keyboard path (role/tabIndex/Enter/Space).
- **Update dismissal never persisted** — the ✕ was component state only,
  so the banner returned on every reload. It now writes
  `orbitra_update_dismissed` with the version it dismissed, and the
  `check_update` response compares against `latest_version`: dismissing
  1.3.3 silences 1.3.3; 1.3.4 appears normally.
- **Boot and loading states hardcoded `bg-gray-900` / `border-blue-600`** —
  the boot screen drops its own background (inherits the themed body) and
  both spinners use the primary token.
- **Closing the report overlay left the campaign selection armed** —
  CampaignReports is rendered by Campaigns.jsx, so the list never unmounted
  and "Delete selected (1)" stayed live against rows scrolled out of view.
  `onClose` clears the selection now.

## [1.3.2] — 2026-08-25

Bugfix release ported from the tester's verified live install (the 13-item
addendum to the v1.3.0 audit). Server-side these changes are already running
and verified against a live Facebook-Ads account; this release brings the
repository level with them.

### Fixed — ad status reads (report play/pause toggles)

- **Graph v26 removed the `?ids=` batch parameter — every ad status read
  failed silently.** `fetchEntityStatuses()` (new in the aggregator engine)
  reads paused/active state one request per id
  (`/{entity-id}?fields=id,status,effective_status`): Graph answers
  `HTTP 500, code 100 "The ids query parameter is deprecated in v26.0+"` on
  the batch form and enforces v26 behaviour regardless of the requested
  version. Non-2xx answers now leave an `error_log()` line with the id and
  HTTP code — before the fix, the failure passed through four layers that
  each discarded it, and every toggle rendered ACTIVE, indistinguishable
  from "nothing is paused".
- **New `ad_entity_statuses` api.php action** — the read side the toggles
  were missing: internal (`tracker_`) campaigns answer from the campaigns
  table, ad / adset / ad-campaign ids from the Graph API through the same
  connection resolver as the toggle (extracted into
  `orbitraResolveFacebookConnectionId()`, shared so read and write can never
  disagree about whose token to use). Partial answers are success;
  `catch (\Throwable)` still returns whatever resolved locally.
- **`entityStatus` merge made the first value permanent** (CampaignReports)
  — `setEntityStatus(prev => ({ ...data.data, ...prev }))` spread `prev`
  last, so stale keys overrode every subsequent server read for the
  component's lifetime and a pause never survived reopening the report.
  Server wins by default now; only ids with a toggle in flight keep their
  optimistic value, and `togglingIds` joined the dependency array so the
  post-flight re-read reconciles a failed command.
- **`CURLOPT_IPRESOLVE_V4` on every Graph call the aggregator engine
  makes** — the engine had missed the §1.1 CAPI fix; `httpGet()` (account
  reads, insights, status reads) and `updateEntityStatus()` now pin IPv4
  like the rest of the Facebook paths.

### Fixed — panel

- **Entity ids leaking into Landings' saved metric columns** — the Offers
  guard (`FIXED_OFFER_COLUMN_IDS` + write-back repair) ported verbatim;
  Landings' fixed set is wider (`url`, `last_event`, `type`, `group_name`
  beyond `name`/`state`), so more ids could leak.
- **Campaigns `columnDefs` desynced from render order** — the colgroup
  listed `actions` last while the table drew it fifth, so every column
  after the mismatch drew at a neighbour's width with no error anywhere.
  The list now mirrors render order (with a comment stating the
  constraint), and the table layout changed with it: **Actions moved from
  the far right to fifth position** (with a dozen metric columns to the
  right, far-edge actions needed horizontal scrolling to reach), **the six
  fixed columns are locked** (no resize handles, explicit widths, one-time
  purge of stale stored widths — stored widths take precedence over
  `col.width`), sort chevrons hidden on ID / Status / Group, alias chips
  removed from the row and the mobile card (alias stays in the URL builders
  and search), the 320px name cap removed, and the row menu trimmed to
  Update costs / Duplicate / Clear stats / Delete (Edit, Copy link and
  Open in new tab duplicated the name click and the Actions icons).
- **Copy-link `window.alert()` replaced** — silent copy plus a transient
  toast; a new theme-aware `CampaignUrlModal` (URL as one continuous
  selectable run, macros highlighted) is the fallback shown only when
  `copyToClipboard()` returns false — on plain-HTTP panels that is the one
  surface where the URL can be selected by hand.
- **Three components bypassed `utils/clipboard` and failed silently on
  plain-HTTP installs** — Postback Settings, the Feedback page and the MCP
  page called `navigator.clipboard.writeText` directly; all three now go
  through the shared helper (execCommand fallback) and only report success
  when a transport actually worked.
- **`.modal-content`'s `overflow-y: auto` clipped the DateRangePicker
  popover in the Conversions Log** — Apply/Cancel and the timezone select
  were unreachable. Vertical scrolling moved inward to the conversions
  table's own wrapper; `overflow: visible` on this modal only. (Third
  distinct ancestor found clipping that popover; porting it to
  `document.body` remains the recommended upstream fix.)
- **Timezone chip wrapped mid-date** — the trigger button got
  `whitespace-nowrap`. (The last-segment + underscore rendering was already
  correct in the repo.)

### Operations

- **`install.sh` enables php-fpm `catch_workers_output`** — the stock
  pool config ships it commented, so every application `error_log()` line
  went to a discarded stderr; several silent failures in this batch were
  invisible for exactly that reason. Best-effort sed across the installed
  pools, non-fatal.
- **Rotation optimiser cron runs every minute** (was `*/5`) — a stream set
  to a 5-minute re-check interval must actually be re-checked every 5
  minutes; non-due streams are skipped cheaply.

### Changed (not regressions)

- **`floor_pct` removed from the optimisation conditions UI** — the panel
  exposes Metric / min confirmed sales / lookback days / cap % / re-check
  interval. `floor_pct` still ships at its default via `ROTATION_DEFAULTS`
  (spread first by `setAutoCfg`), so saved configs are unchanged and the
  cron's `floor_pct >= cap_pct` guard still holds.
- **Effective-status badges in the report** — rows badge `Disapproved` /
  `In review` only, states the toggle itself cannot show; parent pauses are
  deliberately not badged (the parent row already displays them).

## [1.3.1] — 2026-08-25

Bugfix release from a 22-item audit of a live Facebook-Ads COD setup
(India, cloak stream active): five silent data-corruption faults, the
timezone family, and a sweep of panel correctness issues.

### Fixed — cost & attribution

- **CostImporter matched UTC click dates against platform-timezone spend**
  — ad platforms report spend by the ad account's calendar day; comparing
  it to `date(created_at)` (UTC) put every pre-morning click on the wrong
  day, so a non-UTC account (here `Asia/Kolkata`) reconciled to nothing.
  The importer now resolves the account's timezone (explicit override →
  connection field mapping → engine credentials → cached live Graph
  lookup, one call cached for a day) and shifts `created_at` per spend day
  — evaluated on the day itself, so DST boundaries land correctly.
- **Cost no longer attributed to safe-page (cloaked) clicks** — reports
  exclude safe-page traffic, so spend spread over it vanished from every
  surface; attribution now targets money-side clicks, with a fallback to
  all clicks for periods that have none (pure crawler days), counted in
  the returned stats.
- **`campaign_report` ignored the safe-page exclusion** — v1.1.11 applied
  it to Campaigns, Landings, Offers and the dashboard but missed the
  report; on the audited data the report showed 363 clicks where ~90 were
  real, with every derived metric wrong to match.

### Fixed — CAPI delivery

- **Unmapped conversion statuses dropped silently** — `enqueue()` returned
  without a trace when `resolveEvent()` found no Meta event, which is what
  a custom conversion type shadowing a built-in status (`hold` in COD)
  does; both the Facebook and TikTok paths now write a system log line
  (INFO for deliberately blank mappings, WARNING otherwise).
- **Pixel Vault test button hardcoded `proxy_url => ''`** — the profile
  itself carries no proxy (it lives on `campaign_pixels`), so a dead proxy
  tested healthy and then failed in production; the test now exercises
  every distinct transport the profile is actually delivered through, with
  proxy credentials stripped from the response.
- **curl pinned to IPv4 with a 10s connect budget** — `graph.facebook.com`
  publishes unroutable AAAA records; curl burned its connect timeout on
  v6 and the failure read as a resolver fault. Applied to the direct CAPI
  send, the TikTok send (which also honors the row's proxy now) and the
  queue worker.
- **The queue worker and aggregator ship scheduled** — `install.sh` now
  installs `postback_queue_cron.php` (every minute; the worker takes a
  lock, so overlaps are free) and `aggregator_cron.php` (*/15), with the
  same marker/idempotent pattern as the existing crons.

### Fixed — timezones

- **Campaigns and the Conversions Log never sent `?timezone=`** — every
  timezone change issued a byte-identical request; both now send it and
  list it in their fetch dependencies.
- **One shared timezone store** — six components each kept a private
  `useState(localStorage)` copy, so two mounted views could show
  different periods and CampaignReports could disagree with itself; a
  `useTimezone` hook (module-level value + subscribers + cross-tab
  `storage` sync) replaces them all, and the dashboard refetches on a
  zone change.
- **`$dbTzOffset` no longer taken at request time** — the SQLite offset
  applied to *historical* conditions is now anchored to the midpoint of
  the requested range (exact for any range inside one DST period; a range
  straddling a transition picks the offset covering most of it, leaving
  at most an hour wrong at the boundary instead of a whole range).

### Fixed — panel correctness

- **Modals render beneath the navbar** — the navbar sits at z-1500 and
  five overlays overrode `.modal-overlay` downward (1100/1200) or skipped
  the class; the modal scale is documented in `index.css` (2000 overlays,
  2050 in-modal fullscreen panes, 2100 secondary modals) and every
  override removed or raised.
- **Column widths never persisted** — Chrome can fire
  `lostpointercapture` before `pointerup`; the reverting handler won the
  race, snapped the column back and the commit call returned at the
  guard. Both release paths commit now; the `moved` guard still rejects
  genuine orphaned drags.
- **Entity field ids leaked into Offers' metric columns** — restored
  metric ids are validated on load (and repaired once in storage) against
  the real metric table minus the fixed columns, so `name` / `state` /
  `affiliate_network_name` can no longer render as raw column names.
- **Totals footer misaligned** — Landings/Offers footer cells added
  `px-4 py-3` on top of the `.tracker-table tfoot td` rule; the 14px
  horizontal padding is now documented as a contract with the body cells.
- **Zero conversions in success green** — footer formatters in
  Landings/Offers match their row formatters (`0` renders plain).
- **SQLite integer booleans rendered as `0`** — `Boolean(...)` guards on
  the `is_local` / `is_template` conditional renders.
- **Campaign name suggestion is idempotent** — re-applying no longer
  echoes the traffic source into the product segment
  (`Facebook Ads - Facebook Ads - [IN]`); the parts the builder appends
  are stripped back off first, and an empty product is omitted rather
  than padded with a placeholder.
- **Timezone chip shows real city names** — tz-database underscores are
  stripped (`New_York` → `New York`).
- **IP2Location sentinel strings passed through as values** — the "This
  parameter is unavailable in selected .BIN data file" placeholder (and
  its relatives) became every visitor's ISP, disabling ISP-based bot
  filtering; `normalizeGeoString` treats them as empty, including on the
  fallback-DB fill path.
- **Dead `isBot()` removed from index.php** — defined and never called;
  `CloakDetector::matchedBotList()` already performs the same check
  against the same tables from every entry point (the
  `function_exists('isBot')` detour is gone from the detector too).
- **Unicode arrow glued to a variable in a log string** — the aggregator
  cron's `→` is now outside the interpolation.

### Added

- **Worker health** — a `worker_health` API action plus an amber panel
  banner (7 locales) surface the three failures that are invisible by
  design: the postback/CAPI queue worker not scheduled or stalled (pending
  events age), the cost aggregator not running on its cadence (heartbeat
  written by the cron), and custom conversion types shadowing a built-in
  status with no Meta event mapped.
- **Sortable Landings headers** — the byte-identical private
  `SortableTh` copies in Campaigns and Offers moved to one shared module
  (`common/SortableTh.jsx`, with `sortRows` / `nextSortState`), and
  Landings joins them.
- **`FacebookAdsEngine::accountTimezone()`** — reads the ad account's
  IANA timezone from Graph for the importer's live lookup.

## [1.3.0] — 2026-08-23

Working-analyzer release: resizable columns in every table, Click/Conversions
Log modals, `kclient.php` 2.0 for secondary pages, and honest test-suite green.

### Added

- **Resizable columns everywhere**: one shared `ColumnResize` module gives the
  Campaigns, Offers, Landings, Logs and Conversions tables (plus the campaign
  report and report customizer) drag-to-resize headers — widths persist per
  table and restore to default. `overflow:hidden` on the resize handle's
  header cell is load-bearing: without it the sticky header paints over the
  neighbouring handle.
- **Click Log and Conversions Log modals**: the Click Log keeps its cloak
  quick-filters (ALL/SAFE/MONEY) and W1/W2 detail fields, now in a modal
  reachable from both the Campaigns list and the stream cards in the campaign
  editor; a matching Conversions Log modal rides along.
- **Shared `campaignUrl` helper**: campaign URLs are built in one place by the
  Campaigns list and the campaign editor.
- **Domain column in the Campaigns table**: `api.php` now joins `domains` on
  `campaigns.domain_id` and selects the name.
- **`kclient.php` 2.0**: on secondary pages of the same site
  `restoreFromQuery()` picks the click back up from `_subid` and restores
  this click's offer from the session (id-matched, so a stale session cannot
  hand over another click's offer); `getOffer(42)` keeps working in the
  bare-id form the integration panel documents; choosing a specific offer
  now *replaces* the tracker's own `offer_id` on the transition link instead
  of appending a second one. New docs page `docs/tracking-client-php.md`,
  linked from the integration panel alongside Keitaro's.
- **KClient direct-destination macros**: `{subid}`, `{ip}` and `{country}`
  are substituted on the KClient path exactly like the campaign-link path —
  an offer URL written with `{subid}` no longer reaches the network empty.

### Fixed

- **Rotation optimiser cost-gating**: EPC is no longer cost-gated — earnings
  per click has no spend term, so EPC configs run on zero-cost campaigns;
  only ROI still requires cost. Pinned in the allocator and cron tests.
- **Test-suite hygiene**: four stale tests aligned with shipped behavior —
  the TikTok `resolveEvent` pixel-first signature (7483d75), unknown postback
  statuses now recording for retroactive mapping instead of a 400 (ORB-012),
  and the DNS/nginx regression tests skipping on hosts without the fixtures
  instead of failing red. The whole suite runs green.

## [1.2.0] — 2026-08-23

Mobile + PWA + rotation auto-optimiser release: the panel is finally usable on
a phone, installs to the home screen, ships content-hashed assets (the
"hard-reload after update" era is over), and streams can hand their
landing/offer weights to a cron that reweights them by real performance.

### Fixed

- **The panel was a 980px desktop on phones**: the frontend shipped no viewport
  meta at all, so mobile browsers rendered a virtual desktop and every `lg:`
  breakpoint already in the code never fired. One tag
  (`width=device-width, viewport-fit=cover`) plus the mobile layout below.
- **"Create stream" menu was dead on touchscreens**: opened on hover; now on
  click, with 44px touch targets.
- **router.php ate real root files**: `/sw.js` (and any real file at the domain
  root) fell into the campaign-alias branch and died as an empty 200; a
  `file_exists` guard mirrors Apache's `RewriteCond !-f`.

### Added

- **Mobile layout**: five list screens (Campaigns, Offers, Landings,
  Conversions, Logs) switch to card rows below `lg` via a shared MobileCards
  component; the campaign editor lays out in one column; pagination and report
  toolbars wrap.
- **PWA**: the manifest is served by admin.php itself so `start_url` always
  matches the real entry (/admin.php or the secret admin path — a static file
  could never cover both); icons 192/512 + maskable + apple-touch; installable
  to the home screen.
- **Service worker**: panel shell network-first (offline fallback only),
  hashed dist assets stale-while-revalidate; new builds swap on reload via an
  update toast — never mid-session. No skipWaiting.
- **Content-hashed build assets**: vite emits `index-[hash].js|css`, admin.php
  resolves them through `.vite/manifest.json` (also heals a stale shell after
  a partial deploy); the `?v=filemtime` cache-buster and the hard-reload
  advice are retired.
- **Rotation auto-optimiser**: an Auto toggle per stream landing/offer list
  with a metric (confirmed sales, CR, EPV, EPC, ROI); a */5 cron recomputes
  weights from the same report-metrics engine over a rolling 7-day window
  (safe-page clicks excluded, bots kept); moves are budgeted (cap 70%, floor
  5%, ≤20pp per run, warm-up items keep their share, rounding always totals
  100); every decision is audited per item in `stream_rotation_log` (migration
  39, keyed by a rotation key inside `schema_custom_json` — stream ids churn
  on campaign save); `save_campaign` sanitises auto configs (cost-dependent
  metrics require cost, clamps) and hands cron-owned weights back — the editor
  round-trips a stale copy that must never resurrect moved weights.
  `rotation_status` feeds the editor cost availability + recent decisions;
  install.sh schedules the cron.

## [1.1.11] — 2026-08-23

Cloak evidence + reconciliation release: every cloak reason now names the fact
that triggered it, the numbers finally agree across every report surface, and
the diagnostics panel survives databases the earlier migration build had
already stamped.

### Fixed

- **Cloak numbers disagreed between screens**: the date-filtered Campaigns list
  counted safe-page clicks (M+N) while Landings/Offers and the dashboard
  excluded them (M). All four surfaces now share `orbitraSafePageExclusionNeeded()`,
  and the filter is `COALESCE(is_safe_page, 0) = 0` everywhere — pre-observability
  rows (NULL = money-side) stay visible instead of silently vanishing (SQLite
  `= 0` excludes NULL).
- **`cloak_summary` window vs report timezone**: the panel floored its days in
  UTC while the Campaigns list bucketed in the report timezone; it now uses the
  same `$dbTzOffset` shift (users.timezone, `?timezone=` override) and returns
  the exact window it computed (`window: from/to/timezone`).
- **Diagnostics panel survives a degraded database**: missing
  `cloak_suppressed_stats` no longer fatals the panel (suppressed falls back to 0).

### Added

- **Evidence in every cloak reason**: `crawler_or_tool_ua:curl/`,
  `iprange_datacenter:52.95.0.0/8` (the matched CIDR — `IpRanges::match()`
  returns the list's own line), `bot_isp:hetzner`, `bot_blocklist:<rule>`,
  `geo_country:US`, `device_type:Mobile`, `ip2proxy_high_fraud:87`,
  `hosting_isp:aws`. Split on the first `:` (IPv6 CIDRs safe); commas/newlines
  stripped, evidence capped at 64 chars; pre-change rows without `:` stay valid.
  Threshold logic and aggregation go through `reasonCode()` so verdicts and
  grouping are unchanged.
- **Cloak panel → Click Log deep link**: the diagnostics card opens the log
  pre-filtered (route, 24h window, stream); the Click Log modal gets
  ALL/SAFE/MONEY tabs + hours/stream filters, and click details show ISP, ASN,
  proxy type, verdict and reasons.
- **Self-heal DDL for `cloak_suppressed_stats`**: databases stamped with the
  current schema version by an earlier build of the cloak migration never re-ran
  it and were missing the table (root cause of "empty cloak diagnostics"
  support cases); the table is recreated idempotently on every boot.
- **Bot-ISP blocklist in the Keitaro format**: one provider per line matched as
  a whole phrase (commas/dots/apostrophes belong to the name); a line splits on
  commas only when every segment is a plausible standalone entry (legacy
  format); generic corporate suffixes (inc/ltd/llc/limited/gmbh/corp/co/sa/ag/
  bv/oy/pty/plc/network/services) and sub-3-char entries are ignored by the
  router and warned about next to the settings textareas via the mirrored
  `frontend/src/utils/botIspList.js`; lookaround matching so "ZSCALER, INC."
  hits a dot-less haystack.
- **Group By star + last-applied** in the report customizer (starred default →
  last applied → country; stored under `orbitra_report_group_by`); the Report
  button in Campaigns follows a single ticked checkbox.

Suite: 45 PASS (4 known env-dependent fails). Locales at 3366-key parity (7
languages). dist rebuilt.

## [1.1.10] — 2026-08-23

Hotfix + editor usability release. The Landings table fix is critical for anyone on 1.1.9.

### Fixed
- 🩹 **CRITICAL: Landings table rendered as dashes (v1.1.9 regression)** — the table rewrite mapped `FIXED_LANDING_COLUMNS` into the cell renderer passing the column OBJECT where a string id is switched on; no case matched, so ID/Status/Name/Group/Type/URL/Last Event all fell through to `default: '-'`. The checkbox cell was also filtered from the body while its `<th>` stayed, shifting every metric one column left of its header. Rows now render every fixed column and the checkbox cell; header and body column counts match. (Offers.jsx audited — not affected: its rows render inline cells.)
- 🖱️ **Column drag-and-drop never started (Campaigns, Offers)** — three stacked causes: the grip lived inside the sort `<button>` and a native drag cannot begin on an interactive descendant; `SortableTh` was defined in the component body, so every render remounted its DOM and the dragover highlight cancelled the drag mid-flight; `handleThDragStart` dropped the event, so `dataTransfer.setData` was never called — Firefox refuses to start a drag without a payload. The grip is now its own drag source (`draggable` + `onDragStart` + grab cursors), the `<th>` remains the drop target with the highlight, `SortableTh`/`SortIcon` live at module scope (sort context via props), and the drag payload + `effectAllowed = 'move'` are set. CampaignReports.jsx has neither the button nor the remount problem and gets the Firefox payload fix only.
- 🎛️ **Navbar dropdowns rendered behind report overlays** — the navbar (`fixed top-0 z-[1000]`) forms a stacking context, so its gear and user-menu dropdowns (z-[100] inside) painted below the report overlay and the dashboard-settings overlay (both z-[1100]) exactly where the dropdowns open. The navbar layer is raised to z-[1500] — above page-level overlays, below true modals (z-[2000]); the mobile drawer and its backdrop move as a pair (1501/1499) to keep their original relation to the bar.

### Added
- 🔌 **Traffic-source-driven parameter buttons** — "Facebook Parameters" and "Add All Tracking Parameters" now derive from the campaign's traffic source (`traffic_sources.parameters_json`, the same `{alias, param, macro}` rows `orbitraSourceParamAliases()` reads): layer 1 emits `param={{macro}}` per source row in the source's order with no duplicate keys (campaign custom parameters appended, explicit beats template); layer 2 emits `param={alias}` plus the tracker-native macros the chips offer (`subid, clickid, country, ip, cost, sub_id_1..3`), skipping any alias the source already declares. The Direct-URL preset MERGES into the existing query instead of wiping it: hand-typed pairs are preserved verbatim (never re-encoded — `{macros}` must not become `%7B`), user values win on key collisions, no key is emitted twice, re-clicking is idempotent. Without a source picked, the Facebook defaults remain as a fallback with a one-line hint (new key, all 7 locales).
- ⚖️ **"Split Evenly" + live share badges in stream Offers/Landings lists** — a shared `isSchemaItemEnabled` helper mirrors `selectWeightedItem()`'s filter verbatim (`state` disabled/paused, `is_active` false/0/'0`), so what the button splits is exactly what the router rotates. The split gives the rounding remainder to the first enabled item so enabled weights total 100; paused rows keep their weight; nothing is rewritten on toggle — the button is the explicit action. The static `%` next to weight inputs is replaced by a live share badge (weight / enabled-total, one decimal, the same badge style the stream rows use, '—' while paused). Row dimming uses the same helper, so a `paused` item no longer looks active.

**AFTER UPDATING, HARD-RELOAD THE PANEL ONCE (Ctrl/Cmd+Shift+R)** — `index.js` has a stable filename and browsers cache the old build.

## [1.1.9] — 2026-08-22

Facebook tracking resilience, cloak geo-safety warnings and full metric parity on Landings/Offers.

### Added
- 🩹 **Auto-heal for double-`?` query strings** — a leading `?` in the Facebook Ads URL-parameters box (or a cloaker concatenating onto a URL that already had one) made PHP swallow every key between the two `?` into the first parameter's value — including the routing keys (`token` / `campaign`), so the click was lost before it could be logged. Healing now runs **before campaign routing** in all 3 entry points (index.php, click.php, Click API v3): the corrupted first value is repaired and every swallowed key is recovered; `utm_placement` is captured into `parameters_json`. Healing only triggers on a literal `?` in the query string — normal traffic is untouched.
- 📘 **"Facebook Parameters" copy button** in the campaign editor — copies the clean tracking-parameter string (strictly without the leading `?`), Meta macros plus the campaign's custom parameters, for direct pasting into Meta Ads Manager.
- ➡️ **"Add All Tracking Parameters" preset** for Direct-URL streams — appends the full macro set (`{subid}`, `{clickid}`, `{campaign_id}`, `{adset_id}`, `{ad_id}`, utm_*) in one click; unresolved `{macros}` are stripped from final redirect URLs (index.php + Click API) so literals never reach the affiliate network.
- 🔍 **Cloak observability (W1–W4, full spec)** — the cloak is no longer a black box:
  - **W1 verdict persistence** — clicks carry `cloak_verdict` (`money`/`passive_safe`/`targeting_safe`/`js_safe`), `cloak_reasons`, `is_safe_page`, `isp`, `asn`, `proxy_type`, `cloak_sensitivity`; a shared logger (`core/click_logger.php`) produces them identically on all three entry points.
  - **W2 surfaced in the UI** — Analytics → Clicks gets Route / Reason / Destination / ISP / ASN columns and campaign/route/reason filters; the campaign editor cloak card shows live diagnostics via the new `cloak_summary` endpoint.
  - **W3 no more silent zeros** — safe-page clicks are **logged by default** on new streams (`log_safe_clicks`, `is_safe_page=1`); legacy streams keep their old behavior via the schema-v38 migration; **Exclude Safe Page clicks from reports** (`exclude_safe_from_reports`) keeps metrics clean while the click log stays complete; every suppressed hit increments a per campaign/stream/day/verdict counter (`cloak_suppressed_stats`).
  - **W4 geo-targeting safety** — a country allow-list with no geo database no longer silently rejects 100% of traffic as `geo_country`: such visitors are marked `geo_unknown` with a configurable `geo_unknown_action` (`safe` default / `money`); the campaign editor and the Geo Databases page warn whenever filters run without a readable database (`geo_targeting_ready` in `global_settings`), including ASN-database degradation for the ISP blocklist.
  - Operator guide rewritten as `docs/cloak-how-it-works.md`.
- 📊 **Full 65+ metric parity on Landings/Offers** — registrations, deposits, bots/proxies, per-status revenue (hold/rejected/trash/registration/deposit), the real-revenue family, stream/global uniques and avg LP→offer time are actually selected and computed now: previously the SQL never fetched those counters, the new columns showed zeros and `real_roi` reported a bogus −100% on every row. Both pages get the customizable metric table (presets, column customizer, totals row) matching the Campaigns page.

### Fixed
- 🎛️ **Stream rotation honors per-item disable toggles** — an individually disabled (paused) offer or landing inside a custom stream schema no longer receives traffic; weighted selection filters inactive items before counting weights, in all 3 entry points.
- 🌍 **Locale parity in all 7 languages** — the Facebook-parameters keys, tracking-preset keys and "Group / Product" shipped English-only before (t() falls back to English); Chrome-extension floating widget remembers its drag position.
- 🖥️ **Admin system-status warnings render again** — `system_status` now returns `messageKey` (AdminPage renders `t(admin.${w.messageKey})`), fixing warnings that serialized to literal "message" keys.
- **AFTER UPDATING, HARD-RELOAD THE PANEL ONCE (Ctrl/Cmd+Shift+R)** — `index.js` has a stable filename and browsers cache the old build.

## [1.1.8] — 2026-08-19

Analytics: multi-select entity filters (campaigns, offers, landings) in Trends and Cohort — plus the fixes that made the earlier attempt invisible.

### Added
- 🎯 **Entity filters in Analytics** — multi-select dropdowns for campaigns, offers **and landings** (group selection, in-dropdown search, select all / clear) on both the Trends and Cohort views, with active-filter badges. Selections feed parameterized `IN (...)` queries through the whitelisted `campaign_id` / `offer_id` / `landing_id` fields; `landing_id` is newly accepted by both the trends and cohort filter whitelists.

### Fixed
- 🕳️ **The filters from the earlier attempt never appeared** — both feature commits changed only `frontend/src/` and never rebuilt `frontend/dist/`, so the panel kept serving the pre-feature bundle. The dist is rebuilt and committed now. **Hard-reload the panel once (Ctrl/Cmd+Shift+R) after updating** — `index.js` has a stable filename and browsers cache the old build.
- 🔁 **Cohort ignored the new filters** — its fetch `useEffect` listed only date/granularity dependencies and never re-ran when campaigns/offers/landings were selected; it is now keyed on the memoized `fetchCohort` callback.
- ⚡ **Dropdowns use the lightweight `*_simple` endpoints** — entity lists load via `campaigns_simple` / `offers_simple` (both now expose `group_id` for grouping) instead of the heavy report JOIN lists.
- 🌍 **"No results" translated** — the dropdown's hardcoded English empty-state string replaced with `analytics.noResults` across all 7 locales.

## [1.1.7] — 2026-08-19

Hotfix: landing assets behind nginx ended in redirect loops (500s) — a nested regex location broke the internal-asset hand-off.

### Fixed
- 🛟 **Nested regex location broke `/_internal_assets/`** — the generated vhost (and the `install.sh` baseline) placed a `location ~* \.(…)$` whitelist block *inside* the `/_internal_assets/` prefix location. A nested regex location under an `alias` breaks alias inheritance in nginx, so asset hand-offs looped into 500s. The block is flattened — no nested location; PHP keeps doing path resolution, the extension whitelist and MIME validation before the hand-off, so the security checks live where they already were.
- 🚑 **Fail-safe detects the broken config variant** — after a `git pull`, servers carry the fixed templates while `/etc/nginx` still holds the broken block until `nginx_sync.php` runs. The PHP fallback now pattern-matches the nested-regex variant (`X-Orbitra-Asset-Fallback: broken_nested_regex_detected`) and streams assets from PHP until the config is regenerated.
- 🧭 **vhost generation order** — `$needsSelfSigned` was computed *after* the Cloudflare-only block consumed it; the self-signed list is now built first and the Cloudflare-only block diffs `$selfSignedDomains` directly.

> Nginx servers: re-run `sudo php cli/nginx_sync.php` once to replace the broken location block.

## [1.1.6] — 2026-08-19

Landing assets are guaranteed to load across Incognito, WebView, blocked cookies and Cloudflare proxy, and SSL gets an explicit per-domain mode.

### Added
- 🔀 **SSL mode selector in Domains** — Let's Encrypt (auto-issued) / Cloudflare (proxy mode; the generated vhost restores the visitor's real IP from Cloudflare's IPv4+IPv6 ranges and honours `CF-Visitor` for HTTPS detection) / Custom (own certificate and key paths). UI strings in all 7 locales.
- 🧰 **Install smoke tests + landing diagnostics** — `install.sh` verifies the `_internal_assets` nginx location, ACME webroot writability, the self-signed certificate and nginx config syntax after install; `cli/check_landings.php` diagnoses landing/offer directory permissions, nginx completeness, ACME accessibility, SSL presence and a sample asset fetch.

### Fixed
- 🧩 **Intelligent HTML rewriter for served landings/offers** — absolute asset paths (`/css/style.css`) are rewritten to relative (`./css/style.css`) before the base tag is injected, and a JS polyfill keeps anchor-link smooth scrolling working under the base tag. Wired into every `orbitraInjectBaseTag()` call point.
- 🧭 **Campaign-aware referer fallback** — referer/conversion resolution understands campaign URLs (`/bd86o7dw`), resolving the landing or offer from the campaign's stream settings instead of only `/lander/<slug>` addresses.
- 🛟 **X-Accel-Redirect fail-safe** — when the nginx `/_internal_assets/` location is missing (config not synced), the tracker detects it and streams the asset directly from PHP with Range support (206 Partial Content) instead of failing.

### Removed
- 🗑️ **Bulk .txt/.csv import** — the upload buttons in Campaigns, Offers, Landings and Traffic Sources are removed by design; the Sources section stays reachable from the main navigation.

> Nginx servers should re-run `sudo php cli/nginx_sync.php` once for the Cloudflare-aware vhost blocks.

## [1.1.5] — 2026-08-19

SSL management rebuilt around per-domain verification and custom certificates, IP access control reworked with SUBID extraction in the traffic log, and a shared bulk-file-upload component across the panel.

### Added
- 🔐 **SSL management and verification (ORB-014)** — `core/ssl_manager.php` verifies certificate coverage per domain, the generated nginx vhost gains an HTTPS server block with a self-signed certificate for domains that are neither Let's Encrypt nor behind Cloudflare, and the Cloudflare Full (Strict) setup is documented in `docs/CLOUDFLARE_FULL_STRICT_SSL.md`. A 279-line regression test (`tests/nginx_config_regression_test.php`) locks the generated vhost shape, including server-block parsing and listen matching. **An existing nginx server needs `sudo php /var/www/orbitra/cli/nginx_sync.php` once** for the new vhost blocks.
- 📜 **Custom SSL certificates per domain** — upload your own certificate and key in domain settings (Domains UI, all 7 locales).
- 🧠 **SUBID extraction in the traffic log** — SUBID values are read from the click's parameters on the click path and in LeadForge; `tests/traffic_log_subid_test.php` and `tests/verify_traffic_subid.php` cover missing keys, malformed JSON and NULL parameters.
- 📤 **Bulk file upload** — a shared `BulkFileUpload` component with CSV parsing and per-file error handling, wired into Bot Settings, Branding, Campaigns, Landings, Offers and Traffic Sources, with matching `api.php` endpoints and a `cli/check_landings.php` inspector.

### Changed
- 🛡️ **IP access control reworked** (`core/ip_access.php`) alongside the traffic-log SUBID work.
- 📦 **Frontend bundle rebuilt** — the SSL and bulk-upload interfaces had been committed without a `dist` rebuild; this release ships them.

## [1.1.4] — 2026-08-19

Generated integration snippets spoke Russian to every user, regardless of the panel language.

### Fixed
- 🔤 **Tracking snippets now generate with English comments** — the code blocks in the campaign editor's Connection method (KClient JS/PHP, tracking script, banner blocks, click and conversion pixels, countdown / back-button / exit-intent widgets, WordPress shortcodes) carried Russian how-to comments hardcoded in the snippet templates, so an English- or German-language panel still produced pasteable code with Russian instructions. All template comments are now English, translated in place — deliberately not behind locale keys, because this is code the user pastes into their own site, not panel UI. The one remaining Russian string is intentional: `text_ru="Купить"` in the WordPress shortcode example demonstrates the multilingual parameter itself.

## [1.1.3] — 2026-08-19

Eight palette themes for the panel, hardcoded grays retired in favour of theme variables, and the repair of a frontend bundle that had shipped broken.

### Added
- 🎨 **Eight palette themes** — four color schemes, each in a light and a dark variant, selectable in Personalization: **Cobalt** (signal blue on white / on pure black), **Canary** (black CTAs on white; canary yellow on charcoal), **Parchment** (warm cream canvas with ink CTAs; ink canvas with cream surfaces), **Indigo** (violet-blue on a light canvas / on deep indigo). The palettes are ported from published brand design analyses under neutral color names — no brand names, marks, or fonts ship with them; the analyses themselves list Inter (already the app's stack) as the sanctioned substitute for their proprietary faces.

### Fixed
- 🌗 **Hardcoded grays retired** — leftover `bg-white` / `gray-*` Tailwind colors in Automation Settings, Admin Status and the campaign Tracking tab now use theme variables (`var(--color-bg-card)`, `var(--color-border)`, `var(--color-text-muted)`, …), so every theme — including the new dark palettes — paints those surfaces correctly instead of flashing light grays. The Tracking tab got more compact along the way: the options panel only renders for methods that have options, and the generated-code block went full-width with a compact copy button in its header.
- ➕ **A doubled "+ +" on the Add Cost Connection button** — the label string itself carried a literal `+` next to the `<Plus />` icon, in every locale.
- 🧱 **The Conversion Types page shipped broken since 1.1.2 — silently** — commit `7483d75` placed sibling JSX inside the page's `{!showForm ? (…) : (…)}` ternary without a fragment, so `ConversionTypesSettings.jsx` stopped compiling from that moment. Nothing flagged it because the shipped `frontend/dist` bundle predated the breakage: the 1.1.2 notes advertised the unmapped-statuses section, but the bundle users actually downloaded never contained it (0 references to its strings in the 1.1.2 `index.js`). The ternary's true branch is now wrapped in `<>…</>` and the bundle rebuilt — the unmapped-statuses UI reaches users for the first time.

## [1.1.2] — 2026-08-19

Postback observability and honest status mapping, routing consolidated into a single front controller, and performance work on the click path and analytics. Existing nginx servers need one `nginx_sync.php` run (see below).

### Added
- 📜 **Incoming postbacks log (ORB-001)** — every postback the tracker receives is now recorded as it arrives, before status mapping and attribution run, so a conversion that went missing or landed on the wrong status can be traced back to the exact request the network sent.
- 🧩 **Unmapped conversion statuses + retroactive mapping** — the Facebook and TikTok engines check custom status mappings before falling back to defaults, and a status that no rule maps is stored unmapped instead of being forced into a guess. A new section in Conversion Types Settings lists the unmapped statuses collected so far and lets you define a mapping for them; a mapping defined later is applied retroactively, reclassifying conversions already stored with that status.
- ⚡ **Landing assets via nginx X-Accel-Redirect (ORB-013)** — PHP resolves the asset path with its security checks, then hands the file to nginx through `X-Accel-Redirect`: nginx serves it with sendfile (zero-copy) while PHP is freed for the next request, so a landing page with 30 assets no longer ties up 30 PHP workers. The internal location serves only whitelisted asset types — everything else, including `.php`, gets `deny all`. **An existing nginx server needs `sudo php /var/www/orbitra/cli/nginx_sync.php` once** — the internal location lives in the vhost, not in the code. Apache installs need nothing manual.
- 🗂️ **Analytics performance indexes** — a new migration adds covering indexes on the `clicks` table for the analytics and trends filters, so grouped reports over large click volumes stop scanning the whole table.

### Fixed
- 🎯 **LeadForge no longer fabricates subids (ORB-011)** — the `bin2hex(random_bytes(8))` fallback that invented a subid for leads with no click context is gone. The subid is now verified tri-state against the `clicks` table: a lead with a stale or missing subid is rejected with a neutral customer-facing message and an entry in `system_logs`, while a lead is still accepted when the database is unreachable (remote deployments, DB errors) so availability never depends on the check.
- 🔒 **Cloudflare Flexible SSL redirect loop** — HTTPS detection goes through `orbitraIsHttps()`, which honours the `CF-Visitor` header, so the panel no longer bounces between HTTP and HTTPS behind Flexible SSL.
- 🌐 **One source of truth for the server's public IP (ORB-005)** — features that need the server's public address (SSL provisioning, integration hints) no longer each run their own detection with their own caching quirks.
- 🧱 **IntegrationsPage JSX fragment** — multiple sibling elements behind a conditional were wrapped in a fragment, fixing a render error on that page.

### Changed
- 🧭 **Routing consolidated into `index.php`** — `router.php` is deleted; `index.php` is the sole front controller and carries the two access guards ported from it: the Disabled Domain Guard (a 404 for domains marked disabled) and the Admin Access Guard (admin routes restricted per the domain's `admin_access` setting). The postback route and the conversion pixel path work through the front controller as well, returning a valid GIF with proper error codes, and are now covered by real-HTTP integration tests (ORB-010) instead of fakes.
- ⏱️ **Geo enrichment off the click path** — synchronous calls to external geo APIs during click processing are disabled; a click no longer waits on a third-party response before being redirected.

## [1.1.1] — 2026-08-18

A local offer hosted on the tracker could take a lead and then answer the buyer with a 404: the form's relative `order.php` never reached PHP at all.

### Fixed
- 🚨 **A local offer's form POST returned 404** — an uploaded offer is served inline at the campaign URL (`/pr6sxv41`), so the browser resolved the `action="order.php"` LeadForge writes against the domain root and posted to `/order.php`. Nginx claimed that path first: `snippets/fastcgi-php.conf` ends in `try_files $fastcgi_script_name =404` and nothing named `order.php` exists at the document root, so nginx answered with its own 404 and index.php's order bridge never ran. The generated vhost (`core/nginx_config.php`) and `install.sh`'s baseline now hand `/order.php`, `/offers/<id>/*.php` and `/lander/<slug>/*.php` to the front controller, which resolves the bundle and runs the handler in-process as it always intended to. **An existing server needs `sudo php /var/www/orbitra/cli/nginx_sync.php` once** — half of this fix lives in the vhost, not in the code.
- 🔁 **`success.php` was missing from the handler whitelist** — the generated `order.php` finishes with a relative `Location: success.php`, so the network could accept a lead and the buyer still land on a 404 one hop later. `orbitraBundleHandlers()` is now the single list every bridge reads: `order.php`, `thank_you.php`, `success.php`, `send.php`, `lucky.php`, `lemon.php` — plus `api.php` on the routes that carry the bundle's id in the URL, never at the domain root, where that name is the tracker's own admin API.
- 🎯 **Form actions are pinned to the offer's own URL** — a relative `*.php` action in a served offer becomes `/offers/<id>/order.php`, so the lead POST carries the offer id instead of depending on a cookie or a Referer surviving, and an Ezaff bundle's `api.php` sender can no longer collide with `/api.php`. In-page `#anchor` links, assets, and any absolute or external action are left alone — deliberately not a `<base>` tag, which would turn every "#order" button into a navigation off the campaign URL.
- 🔒 **Uploaded PHP is no longer executable straight off disk** — `/offers/<id>/order.php` used to go to PHP-FPM directly, running a file out of an uploaded archive outside the "Allow PHP landings" switch and its execution budget. Both bundle routes go through index.php now, and `/landings/<id>/*.php` — the storage directory behind `/lander/<slug>/`, not a public route — returns 404.

### Added
- 🧭 **`ORBITRA_OFFER_ID` / `ORBITRA_OFFER_URL` / `ORBITRA_OFFER_PATH`** — defined before any of a local offer's own PHP runs, on all three paths that execute it. A bundle that wants an absolute URL for itself can write `defined('ORBITRA_OFFER_URL') ? ORBITRA_OFFER_URL . 'order.php' : 'order.php'` and still work standalone.
- 🧪 **`tests/lander_order_route_test.php` grew to 50 checks** — `success.php` on both bridges, the offer-context constants, `/api.php` never claimed from a bundle, twelve form-action rewrite cases, and an assertion that the vhost generator and `install.sh` agree on the bundle routes *and* place them before the generic PHP handler.

## [1.1.0] — 2026-08-18

Conversion attribution for affiliate-network postbacks (Dr. Cash and every other `?subid=` integration) and the report query that had stopped parsing, plus conversion-failure monitoring, cloud-aware SSL provisioning, a Google Ads OAuth walkthrough, TikTok cost-connection fixes, and outbound TLS verification restored.

### Fixed
- 🚨 **Grouped reports returned a SQL error** — the `campaign_report` statement carried a `//` comment inside the SQL string. SQLite only understands `--` and `/* */`, so it rejected the whole statement and *every* layered report (Sub1…Sub30, Country, Day, Campaign, Offer…) failed instead of rendering. The guard it was attached to is now `HAVING COUNT(click_id) > 0`, and `tests/postback_attribution_test.php` fails if a `//` comment reappears there.
- 🔗 **Postback conversions were stored unlinked** — `postback.php` wrote only `click_id`/`status`/`payout`, leaving `conversions.campaign_id`, `offer_id`, `sub_id_1..5`, `ip` and `user_agent` NULL even though the matched click had all of them. The conversions log showed no campaign or offer, and its campaign/offer filters matched nothing. Every conversion is now stamped with its click's dimensions at ingestion (`core/ConversionAttribution.php`), and a repeat postback that only changes the status no longer rewrites the original attribution.
- 🎯 **`sub_id_1` is the click's own sub1, never the postback's `subid`** — `subid` is the tracker's click id (Dr. Cash carries it in `&sub1={subid}`); copying it into the sub1 dimension would give every conversion a unique Sub1 value. The sub dimensions are read from the click's `parameters_json`.
- 🔤 **Status words are matched case-insensitively** — a network sending `Approved` or `PENDING` used to be recorded as a conversion that belonged to no status group, which is how a campaign could show `Conversions: 1` with `Sales`, `Leads`, `Rejected` and `Trash` all at 0. Both `mapStatus()` and the report aggregates now lowercase before comparing, and the groups additionally cover `approve/accepted/paid`, `new/wait/waiting/processing`, `reject/decline/cancelled` and `spam/duplicate`.
- ↔️ **A status's own mapping rule wins** — with `trash_status=trash` *and* `rejected_status=rejected,trash` in the same postback URL, `status=trash` now stays Trash instead of going to whichever type happened to list it first.
- 📥 **CSV conversion import attributes rows too**, so a manual import cannot reintroduce unlinked records.
- 🧹 **Migration 33 backfills history** — existing conversions get `campaign_id`/`offer_id`/`sub_id_1..5`/`ip`/`user_agent` copied from their click. Rows whose click no longer exists are left untouched.

- 🔐 **Outbound TLS certificate verification restored** — `telegram_notify.php`, `telegram_bot.php`, `postback_queue_cron.php` and `core/LeadForge.php` issued cURL requests with `CURLOPT_SSL_VERIFYPEER => false`, accepting any certificate. Verification is now on by default and relaxed only when the environment explicitly says it is local (`APP_ENV=local`, `ORBITRA_ENV=dev`, `ORBITRA_SKIP_SSL_VERIFY=1`).
- 📊 **TikTok cost connection against API v1.3** — `advertiser/info/` is called with the `advertiser_ids` JSON array the current API expects, the Advertiser ID is accepted only as digits (smart quotes, zero-width characters and stray spaces from copy-paste are stripped), and failures name the cause instead of surfacing a bare code. The token field states that a **Marketing API** token with Ads Management / Reporting scope is required — an Events Manager token will not work.
- 🧯 **`core/SslConfig.php` did not parse** — it declared `private const` at file scope, outside any class, so the file was a syntax error from the moment it was added. Nothing required it yet, so the panel was unaffected, but it would have taken down any request that loaded it.

### Added
- 📡 **Conversion failure monitoring** — `core/ConversionMonitor.php` records why a conversion could not be created (unknown click, database error) to `var/logs/conversion_failures.log`, `api.php?action=conversion_monitoring` reports failure counts and rates over a window, and `conversion_monitor_cron.php` alerts through Telegram once the failure rate crosses a threshold. Run it every 5–15 minutes.
- 🔒 **Cloud-aware SSL provisioning** — `core/CloudDetector.php` recognises Cloudflare-proxied domains by IP range and response headers so Certbot is skipped where it cannot validate through the edge, `core/ssl_manager.php` checks DNS and passwordless-sudo prerequisites before requesting a certificate, and Domains gained a reissue action that reports why issuance failed. See `CERTBOT_SETUP.md` and `SECURITY_OPERATIONS_CHECKLIST.md`.
- 🛡 **`core/SslConfig.php`** — locates the system CA bundle across Debian, RedHat, Alpine and macOS layouts and builds stream contexts from it, for hosts whose PHP ships without a usable default.
- 🔑 **Google Ads 1-click OAuth setup guide** — an in-panel walkthrough for the Cloud project, redirect URI and developer token, plus `check_google_ads_oauth.php` as a CLI preflight that names the missing credentials. `.env.example` documents the variables.
- 🌐 **Locale coverage for the above** — SSL, Namecheap whitelist, TikTok token and Google Ads setup strings added across all seven languages.
- 🧪 **`tests/postback_attribution_test.php`** — the Dr. Cash path end to end: attribution payload, non-destructive re-postback, backfill, one-group-per-status, mixed-case status counting, and a Sub1 report row that carries clicks and conversions together.

## [1.0.9] — 2026-08-18

LeadForge 2.0 Reference Synchronization, 150-GEO validation engine, local PHP landing macro resolution, and complete OAuth setup guides.

### Added
- 🌍 **LeadForge 2.0 Reference Sync & 150-GEO Validation Engine** — validation rules for 150 countries with mobile operator prefixes, dynamic country switcher, live input digit-counter badges, Unicode name sanitation, and haptic feedback on mobile.
- 🔤 **Local PHP Landings & Offers Macro Resolution** — output-buffering macro substitution (`{subid}`, `{sub1}`, `{click_id}`, `{{subid}}`, `{data1}`, …) with cross-page Click ID hydration via cookies and sessions.
- 🌉 **Fail-Safe CPA Order Bridge (`order.php`)** — native support for 10 CPA networks (Dr.Cash, Webvork, Lucky.online, KMA.biz, TerraLeads, Leadbit, LemonAD, Everad, Ezaff, Custom Webhooks) with E.164 phone normalization, dual CRM logging, and multi-source Click ID resolution.
- 📖 **Setup guides & keys reference** — in-UI step-by-step instructions for **Google Ads**, **TikTok for Business** and **Facebook / Meta**, with 1-click copyable Authorized Redirect URIs and a Direct Token connection fallback.
- 🌐 **100% multilingual coverage** — translation parity across all 7 supported languages (EN, RU, UK, DE, ES, FR, ZH).

## [1.0.8] — 2026-08-18

LeadForge 2.0 Reference Synchronization, complete 150-GEO validation engine, dynamic country switching, interactive live counter badges, and universal multi-network CPA order bridge.

### Added
- 🌍 **Full 150-GEO Validation Dataset (`core/data/leadforge_geo_rules.json`)** — complete dataset derived directly from the reference LeadForge platform (`87.232.72.54`), covering 150 countries with exact international and national regexes, mobile network prefixes, min/max length constraints, dialing prefixes, trunk rules, and localized messages in 33 languages. Enriched with full rules for CIS countries (`RU`, `KZ`, `BY`, `UZ`, `UA`, `MD`, `GE`, `AM`, `AZ`, `KG`, `TJ`), India (`IN` +91), Europe, LATAM, and MENA/Asia.
- ⚡ **Reference JavaScript Validation Engine (`orbitra_adapter.js`)** — based on the reference validation template (`core/data/leadforge_validation_template.js`):
  - **Dynamic Country Switching**: Automatically updates validation rules, input masks, and counter helpers when a user changes the `<select name="country">` dropdown.
  - **Interactive Live Input Badge Counter**: Visual progress indicator showing digits entered and remaining (*«3 cifre inserite, 7 mancanti»* → *«Numero complete»*).
  - **Strict Unicode Name Validation**: Real-time filtering preventing numbers and invalid symbols in customer name fields.
  - **Haptic Vibration Feedback**: Device vibration on invalid input attempts on mobile browsers.
  - **Comprehensive Parameter Bridge**: Hydrates hidden tracking fields (`subid`, `click_id`, `sub1`..`sub5`, `utm_*`, `fbp`, `fbc`, `pub_sub_id`, etc.) across pages with cookie and `sessionStorage` fallback.
- 🚀 **Universal Multi-Network CPA Order Bridge (`order.php`)** — generated standalone handler with native integration for 10 affiliate networks:
  - **Dr.Cash**: `https://order.drcash.sh/v1/order` (Bearer token, client JSON, `sub1`..`sub5`).
  - **Webvork**: `https://api.webvork.com/v1/new-lead` & `api2` fallback (`utm_campaign` = `{subid}`).
  - **Lucky.online**: `https://lucky.online/api/v1/lead-create/webmaster` (`campaign_hash`, `subid1` / `subid`).
  - **KMA.biz**: `https://api.kma.biz/lead/add` (Bearer token, `data1`..`data5`).
  - **TerraLeads**: `https://t-api.org/api/lead/create` (SHA1 json+key checksum verification).
  - **Leadbit**: `http://wapi.leadbit.com/api/pub/new-order/{token}` (`flow_hash`, `sub1`..`sub5`).
  - **LemonAD**: `https://lemonad.com/api/v2/lead/create` (`click_id`).
  - **Everad**: `https://api.everad.com/campaigns/{offer_id}/order` (`X-Api-Key`, `sid1`).
  - **Ezaff**: `https://api.ezaff.com/send` (`click_id`, `pub_sub_id`).
  - **Custom Webhooks**: Full POST payload passthrough.
- 📱 **Automated E.164 Phone Normalization (`lf_normalize_phone`)** — standardizes local phone inputs into clean E.164 format with automatic prefix handling (stripping leading zeros, country dial code reconciliation).
- 💾 **Dual Logging & Failsafe Lead Storage** — simultaneously submits leads to the CPA network and logs complete raw lead snapshots into the Orbitra CRM Vault (`orbitraCrmRecordLead` / `/crm-ingest`), alongside local append logs (`leadforge.leads.log` & `orbitra_leads_backup.log`).
- 🧪 **Automated Synchronization Test Suite (`tests/leadforge_sync_test.php`)** — verified 150 GEO rules, adapter JS generation, order PHP generation across all 10 networks, and router containment.
- 📦 **Remote Reference Server Backup** — full `/opt/leadforge` directory archived locally to `remote_leadforge_backup/`.

## [1.0.7] — 2026-08-18

Modern Integrations Card Hub architecture, in-browser IDE & File Manager for local offers, and secure file operations API.

### Added
- 🗂️ **Modern Integrations Card Hub** — complete UI redesign of the Integrations page (`IntegrationsPage.jsx`) into a dual-mode Grid/Card Hub (Vercel & Stripe style). Replaces the old nested 240px double sidebar with a spacious responsive catalog. 25 integration services are organized across 4 categories (`Ads & Costs`, `Domains & SSL`, `Tracking & Sites`, `Tools & API`).
- ⚡ **Real-Time Live Status & Metrics** — dynamic pulsing status indicators on integration tiles display live connected account counts, real-time Namecheap balances, Cloudflare zone totals, Telegram bot connection state, and active API keys. Parallel data prefetching on mount (`loadOverviewData`) keeps every tile fresh immediately.
- 🔎 **Instant Hub Search & Category Filtering** — interactive category pills and real-time search filtering by title, subtitle, description, and keywords.
- 🖥️ **100% Full-Width Detail Configuration View** — opening any integration provides full screen width for complex forms, tables, and code snippets, complete with top breadcrumbs and `← Back to all integrations` navigation.
- 💻 **In-Browser IDE & File Manager for Local Offers** — `OfferEditor.jsx` now provides a full-featured code editor and asset manager for direct local offers (`is_local=1`): interactive file tree with file type icons, Monaco-style editor for HTML, CSS, JavaScript, PHP, JSON, XML with syntax highlighting, search & replace, code beautification, live multi-device preview (desktop/mobile iframe toggle), image preview, direct upload, replace, delete, download, and ZIP extraction with validation.
- 🛡️ **Secure Backend File Operations API** — new endpoints in `api.php` (`offer_files`, `offer_file_content`, `save_offer_file`, `offer_file_op`, `upload_offer_file`) equipped with tiered PHP security scanning, strict `realpath` containment to prevent path traversal, file whitelist validation, and automated test suite (`tests/offer_file_ops_test.php`).
- 🌐 **Full 7-Locale Parity** — all new hub headings, category filters, action buttons, and status texts localized across English (`en`), Russian (`ru`), German (`de`), Spanish (`es`), French (`fr`), Ukrainian (`uk`), and Chinese (`zh`).

## [1.0.6] — 2026-08-18

Multi-accounting for the DNS/registrar layer, delete safety for serving entities, and upload hardening — plus in-modal copy feedback the tester could actually see.

### Added
- ☁️ **Cloudflare multi-account** (migration 32) — the legacy single token
  (cf_api_token) becomes account #1 of a new `cloudflare_accounts` table and
  domains gain `dns_account_id`: the Domains page picks the account per domain,
  Integrations manages accounts (add / edit / delete, per-account SSL and proxy
  modes, zone import and repoint via the API).
- 🌍 **Namecheap multi-account** (migration 31) — the registrar mirror: the
  legacy `nc_api_key` becomes account #1 of `namecheap_accounts`; Integrations
  lists accounts with live balance checks plus add / rename / delete.
- 🔐 **OAuth preflight for TikTok and Google Ads** — `<engine>_oauth_status`
  joins Facebook's: without a configured app the 1-Click button disables with
  an amber card instead of dying mid-flow, and each network also takes a direct
  token connection (manual mode is the default for new credentials).
- 🛡️ **Delete guard for landings and offers in use** — deleting an entity still
  referenced by an active campaign stream is refused with `entity_in_use` plus
  the campaign list; the frontend localizes the refusal
  (utils/entityInUseError.js) instead of treating the HTTP-200 error body as a
  silent success.
- ⏱️ **Sub-hour cost sync** — `sync_interval_hours` accepts fractional values
  (0.5 / 0.333) for near-realtime aggregator ticks.

### Changed
- 🎨 **Custom theme contrast** — a picked primary now derives its inverse text
  colour by perceived brightness (the neon-lime primary gets dark text instead
  of unreadable white); leaving the custom theme clears the inline override so
  the built-in themes win again.
- 📋 **In-modal copy feedback (Users → API keys)** — the connector-URL and
  API-key copy buttons swap to a green check for 2 s and confirm with a banner
  inside the modal; the old page-level toast rendered behind the overlay, which
  read as "the button does nothing". Generate/delete-key notices moved in-modal
  for the same reason.
- 🧩 **Chrome extension 1.0.1** — RU/EN localization for the live overlay, a
  draft badge for campaigns without traffic yet, and the MutationObserver now
  ignores the extension's own injections so re-rendering stops scheduling
  refresh loops; rebuilt orbitra-extension.zip.

### Fixed
- 🔒 **Local offer upload hardening** — uploads pass a tiered PHP scan (hard
  block for dangerous constructs, soft sanitization for the rest); the
  `T_NAME_FULLY_QUALIFIED` hole — namespaced function calls invisible to the
  scanner — is closed, and archives land via stage-then-swap with realpath
  containment so symlink tricks can't escape the offer directory.
- 🔗 **order.php / thank_you.php bridge route** — bridge files of a local offer
  are served through a realpath containment check instead of a bare path join.

## [1.0.5] — 2026-08-17

Safe Page white pages: grouped selects, a Local Offer safe mode, and a critical fix that made direct local offers unreachable.

### Added
- 🛡️ **Safe Page: grouped selects** — the "Tracker Landing" picker in the cloaking
  Safe Page block now groups landings by their group (`<optgroup>`, groups sorted
  alphabetically, "No group" collected at the bottom), so a library of dozens of
  whites stays navigable; a 🔍 button next to the select opens the entity picker
  (instant search + group filter) in a new single-select mode — one click picks.
- 📦 **Safe Page: Local Offer mode** — a fourth tab "Local Offer" lets a LeadForge
  direct local offer (`is_local=1`) act as the white page: the new
  `safe_mode='offer'` + `safe_offer_id` stream fields are honoured by all three
  entry points (index.php serves the archive inline with full macro/cookie
  context; click.php and the Click API redirect to the new public
  `/offers/<id>/` address). A missing or non-local offer falls back to the
  default page, exactly like a missing safe landing. The picker hides the
  network/GEO columns when only local offers are listed.
- 🔗 **Public `/offers/<id>/` route** — the offer twin of `/lander/<slug>/`:
  serves a local offer's uploaded index (with `<base>` injection and the
  `orbitra_lo` cookie so relative assets and the order.php bridge keep working),
  404s unknown offers, never executes PHP indexes, and routes non-page files
  through the same extension whitelist and path containment as the click flow.

### Fixed
- 🚨 **Direct local offer streams died with "URL not found."** — the
  `die()` on an empty `$finalUrl` fired before the local-offer serving branch,
  so v1.0.4's marquee "Direct Local Offer" destination never actually worked
  for offer-only streams. The uploaded page is now served as intended; a
  `null` url no longer trips PHP 8.5's `str_replace(null)` deprecation, and the
  Click API answers with the offer's `/offers/<id>/` address instead of an
  empty URL on both the cloak money side and the redirect schema.
- ⚙️ **`allow_php_landings` seed missed on upgrades** — the v1.0.4 default rows
  were added without a schema bump, so databases already at schema 29
  (installed with 1.0.2) never received them and LeadForge builds failed with
  `php_landings_disabled`. Migration 30 inserts
  `allow_php_landings`/`php_landing_timeout` (`INSERT OR IGNORE`, an explicit
  `'0'` survives), and `PhpLanding::enabled()` now treats a missing row as
  enabled; Settings → General and the LeadForge panel follow the same
  default-on semantics.

## [1.0.4] — 2026-08-17

LeadForge tracker destinations, calendar viewport fix, Facebook OAuth preflight.

### Added
- 🎯 **LeadForge: Tracker destination** — every bundle can now be saved as a
  local lander (Landings), a **direct local offer** (Offers, `is_local=1`, files
  served from the tracker's own `/offers/<id>/` route with no landing record),
  or the linked lander+offer pair; an explicit "ZIP only" state replaces the
  old auto-save/auto-create checkboxes. Legacy API flags keep working.
- 📁 **Groups on the fly** — the group picker follows the destination (landing
  groups vs offer groups) and "+ New group" creates one inline without leaving
  the panel; a duplicate name selects the existing group. `new_group_name` is
  resolved once per batch in the API (per-bundle creation would trip
  `UNIQUE(name)`), with a fallback to the existing id.
- 💰 **Opt-in fixed payout** — LeadForge no longer hardcodes a payout into the
  build by default; real revenue comes from the network's S2S postback unless
  "Fixed payout" is checked.
- 🔵 **Facebook OAuth preflight** — `facebook_oauth_status` tells the
  Integrations UI whether a Meta app is configured at all: the 1-Click button
  disables with a warning instead of opening a popup guaranteed to fail; a
  collapsible hint explains where a long-lived token comes from.
- ⚙️ **PHP landings on by default** — LeadForge bundles live on
  order.php/thank_you.php and were unusable on a fresh install; the toggle and
  timeout are now exposed in Settings → General (both API whitelist spots were
  already in place).

### Fixed
- 📅 **Date-range calendar off-screen** — the 540px panel was right-aligned to
  its trigger, so on Landings (picker at the left edge of the toolbar) it
  opened ~300px past the left edge of the window. The panel now measures the
  viewport on open (and on resize) and hangs from the side that fits;
  Campaigns/Offers/CampaignReports keep their previous look.
- 🗄️ **Offer-group FK failure** — `auto_create_offer` copied a landing-group id
  into `offers.group_id` (`FOREIGN KEY … offer_groups`, `PRAGMA foreign_keys=ON`),
  so building with a group selected could fail the whole bundle; the offer now
  links a same-named offer group when one exists, else none.
- 📧 **landing_groups duplicate 500** — POSTing an existing group name returned
  a 500 (missing try/catch, unlike offer_groups); it now returns a clean error.

## [1.0.3] — 2026-08-17

Editor tooling, layout consistency and worldwide GEO coverage.

### Added
- 🔍 **Find & Replace in the landing code editor** — a VS Code-style widget
  over the textarea: `Ctrl/Cmd+F` find, `Ctrl/Cmd+H` replace, `Aa` / `\b` /
  `.*` modifiers, live `N of M` counter, Enter/Shift+Enter navigation with
  scroll-to-match (exact vertical math for the fixed line height, measured
  horizontal reveal for minified one-liners), single Replace and
  count-verified Replace All (literal mode splices the match list, so an
  empty-matching regex never smears the replacement between characters), and
  the query is pre-seeded from the editor selection.
- 🌍 **79-GEO phone-mask coverage** — `geoMasks()` grows from 13 to 79
  countries with mobile patterns and digit bounds; the LeadForge picker
  mirrors it grouped by Europe / Americas / Asia / MENA & Africa
  (`optgroup`s, localized region labels). Masks feed order.php generation,
  adapter validation and Auto QA scoring.
- 🧾 **CRM hover-copy** — SubID and phone copy buttons on row hover with
  check-mark feedback; Approval Rate moved under the Approved Sales KPI
  card; the Shave Suspects card got a rose tint and reports only
  lost-in-transit.

### Changed
- 🖥️ **Full-width CRM & LeadForge** — `max-w-7xl` dropped (the only two
  capped pages in the app), matching the dashboard; App.jsx no longer
  renders its generic `h1` above their hero headers.

### Fixed
- 🔐 **API key + browser session coexistence** — a key arriving on a
  signed-in browser request is honored only when it belongs to the same
  user (403 otherwise); the live session is never re-identified as
  `api_key`, so `extension_credentials` stays reachable for the panel
  browser; invalid keys now 401 even alongside a session.

## [1.0.2] — 2026-08-17

LeadForge grows from a one-shot forge into a two-stage compiler, and the CRM
becomes an anti-shaving evidence vault.

### Added
- 🔬 **LeadForge 2.0: Analyze → Build** — `leadforge_analyze` inspects up to
  15 ZIP/HTML/PHP bundles without touching a byte (source-network detection
  across 13 CPA signatures, forms and input-name inventory, foreign counters,
  UTF-8 checks, GEO from the `lang` attribute, ready-for-build cards), stages
  them for 24h, and `leadforge_build_batch` compiles the selected ones with a
  live execution console.
- 🔀 **Three integration modes** — **Auto** (detect + route to the recognized
  network), **Cross-Network** (cut the old network's `order.php` / `send.php`
  / `api.php` / `sender.php` handlers and re-seat the landing on the chosen
  target), **Raw** (strip FB/TikTok/GA/Yandex counters and hostile snippets —
  DevTools blockers, right-click disablers, back-redirects — inject the
  ClickID bridge and `{offer}` macros, no backend generated).
- 🛡️ **Live Auto QA with Confidence Score** — after each build a
  `QA-Test-Lead` (phone by GEO mask, e.g. `+39 333 000 1122` for IT) is
  posted through the real `/order.php` bridge; four checks worth 25% each
  (form structure, bridge response, dual logging, thank-you redirect) score
  the bundle 0–100%, re-runnable per landing via `leadforge_live_qa`. QA
  clicks and conversions are removed afterwards — analytics stays pristine,
  the vault row stays as reviewable evidence.
- 🗄️ **CRM Anti-Shaving Vault (migration 28)** — `crm_leads` stores the full
  snapshot: raw phone as typed vs the E.164 actually delivered, UTM / adset /
  ad attribution, product & price, and the exact network request/response
  dump (endpoint, payload, HTTP code, body, network lead id). Fed three ways:
  in-process from the generated order.php, via the public `POST /crm-ingest`
  endpoint for bundles hosted on foreign hosting, and manually from the
  panel. `leadforge_profiles` ships seeded with the six networks the engine
  speaks.
- 🚨 **S2S reconciliation & shave detector** — postbacks move every CRM row of
  the click to the network's verdict (`&reason=` is stored verbatim); a lead
  is flagged **Suspected Shave** only when provable: delivered a well-formed
  E.164 number, the network answered HTTP 200, and the verdict is still
  negative. Leads silent for 24h after a 200 flag as **Missing Network ACK**,
  same-phone-same-network repeats within 30 days mark as **Duplicate**. The
  Lead Inspector modal shows the three evidence tabs (Raw Lead Data / Network
  Transaction / Tracking Attribution) with an exportable evidence pack for a
  support ticket.
- 🧷 **orbitra_adapter.js 2.0** — the ClickID bridge reads the tracker's
  `orbitra_click` cookie as the last-resort subid, accepts `clickid` /
  `sub_id` aliases, persists captures across pages (sessionStorage + cookie)
  and enforces the real GEO phone mask (placeholder, input filtering,
  submit-blocking validation).
- 🌐 Full i18n for both pages across the 7 locales.

### Fixed
- 🧯 **order.php bridge died on the execution budget** — the generic PHP
  landing budget (3s default) killed generated handlers mid-network-call
  (curl waits up to 15s for the CPA network); bridge files now run with
  `max(PhpLanding::timeout, 25s)`.
- 🧭 **router.php swallowed service paths** — `/postback.php`, `/order.php`
  and `/crm-ingest` fell into the single-segment alias branch and answered
  empty 200s on the dev server (masked on Apache by the file-exists rewrite);
  the reserved-path list now matches index.php's.
- 🎨 **LeadForge & CRM follow the global theme system** — hardcoded
  amber/indigo/sky gradients replaced with theme primaries (`btn-primary` /
  `btn-secondary`, `color-mix` tints), text on primary uses
  `--color-text-inverse` (white vanished into neon's lime), the «3-in-1»
  header badges are gone and 17 dead icon imports pruned.

## [1.0.1] — 2026-08-17

TikTok gets the same 1-click entry Facebook has, plus fixes for the 1.0.0
follow-ups found in the field.

### Added
- 🎵 **1-Click TikTok for Business (OAuth 2.0)** — a popup login from the
  Pixel Vault discovers every accessible ad account and pixel
  (`/oauth2/advertiser/get/`, `/pixel/list/`), auto-imports the pixels into
  the vault and saves one spend-sync connection per selected cabinet
  (engine `tiktok`, checkboxes + sync interval). Tokens never reach the
  browser: the popup only carries an opaque flow id, mirroring the Facebook
  flow.
- 🔄 **Automatic TikTok token refresh** — TikTok access tokens live ~24h and
  the refresh token is single-use; `TikTokAdsEngine::ensureFreshToken()`
  refreshes on every scheduled/manual cost sync, writes the new pair to all
  sibling connections of the same login and propagates the access token to
  the imported pixel profiles and campaign pixel copies (old-token match).
  A dead refresh token throws, so the sync log names the real reason.
- 🗂️ **Pixel Vault source tabs** — All / Facebook / TikTok / Google Ads…
  filter chips with live counts, next to the existing niche filter.
- 🎯 **Pixel options name their network** — the campaign editor's pixel
  picker shows `pixel_id ( TikTok · Nutra / Name )`, so mixed-network vaults
  stay distinguishable.
- 📄 **TikTok Pixel snippet** — ready-made landing code in the Integrations
  library: the `{pixel}` campaign parameter (TikTok source template's
  `__PIXEL__`) is stored in a cookie and boots `ttq` on the landing or
  thank-you page.
- ⚙️ Optional App ID / App Secret fields on TikTok cost connections — they
  authorize the app for 1-click login and keep token refresh working
  (env `ORBITRA_TIKTOK_APP_ID`/`_APP_SECRET` also honoured).

### Fixed
- ⬜ **Campaigns white screen (TDZ)** — shipped in 1.0.0: the
  traffic-source filter state sat in an effect's dependency array above its
  `const` declarations, so every visit to Campaigns threw
  *Cannot access 'selectedGroupId' before initialization*. The state block
  moved above the pagination-reset effect.
- 🎨 **Dashboard stat cards** — 32px of air above Traffic Dynamics, active
  glow softened to `color-mix(… 25%, transparent)` with a 1px lift,
  theme-safe across all 5 themes.
- 🔍 **Traffic Sources search** — clearer icon sizing, a clear button and
  card-consistent input styling.

## [1.0.0] — 2026-08-17

The 1.0 milestone: Orbitra grows from a click tracker into a full funnel
suite — forge the landings, track the traffic, collect the leads.

### Added
- 🏗️ **LeadForge (landing forge)** — generates ready-to-upload landing
  packages; the order.php / thank_you.php form handlers are served through an
  in-process bridge in index.php (static hosting works), subid is carried via
  a hidden field or the orbitra_click cookie, and finished packages download
  through a tokenized endpoint.
- 🧲 **CRM capsule** — leads arrive via api.php `crm_lead` (a minimal click is
  created when the form post has none); LeadForge / Tracker / CRM are unified
  by a suite switcher in the navbar.
- 🎯 **TikTok Conversions API** — server-side events through
  core/TikTokConversions.php alongside the Facebook CAPI; pixel profiles
  (traffic source, niche, event set, test codes) drive both engines, and a
  schema_migrations panel reports applied versions (schema version 26, SQL
  reference kept in migrations/).
- 🔍 **Inline mini search** in Offers / Campaigns / Landings — the input lives
  in the header action group next to Create / Groups, filters live by name,
  URL, group, network and exact ID, with a one-click ✕ clear.
- 📄 **Universal table pagination** — shared PaginationToolbar (Showing X-Y
  of N rows · Page Size 25/50/100/250/All · First/Prev/pages/Next/Last) under
  the Campaigns, Offers and Landings tables; the page size is remembered in
  localStorage across tables while TOTAL rows stay computed over the whole
  filtered list.
- 🔀 **Traffic source filter in Campaigns** — an "All traffic sources"
  dropdown next to the group filter narrows the table instantly (client-side;
  rows already ship source_id/source_name), and TOTAL recomputes with it.

### Fixed
- **Navbar no longer clips on laptops** — the desktop menu moves to the xl
  breakpoint (1280px) with a compact NavItem; below it the hamburger opens the
  full drawer (suite switcher, navigation, settings) instead of links
  overflowing the right edge on 768–1280px screens.
- **Dashboard horizontal scroll is gone** — StatCards drop the negative-margin
  bleed for a responsive grid (2→7 columns by viewport), and the
  DashboardHeader campaign/date selectors stretch fluidly instead of the fixed
  300px that overlapped on tablets.
- **Stream weights are visible again** — with By-weight rotation every stream
  header carries the weight input plus a live share badge (e.g. 70.0%), a
  Total Stream Weight bar warns when the sum ≠ 100%, and Split Evenly hands
  out 100% across active streams in one click.
- **Traffic Sources page is fast** — the stats query aggregates clicks per
  source in a single subquery pass instead of joining the whole clicks table
  per source row (semantics unchanged), and the source editor caches its
  template list at module level so the modal opens instantly.
- **Copy buttons work on plain HTTP/IP installs** — the postback URL
  (Affiliate Networks), source URL and campaign-link copies now route through
  the shared execCommand-fallback util instead of raw navigator.clipboard
  (blocked outside HTTPS), and the postback code block is click-to-copy.
- Carries everything from the unreleased 0.9.9.2: universal CPV/EPV
  direct-vs-Lander funnel semantics (see the 0.9.9.2 entry below).

## [0.9.9.2] — 2026-08-17

### Fixed
- **Direct vs Lander funnel semantics in the report math** — CPV / EPV (and
  EPV confirmed) are now computed over ALL inbound visits, making them the
  universal unit economics: exact when a campaign mixes Lander streams with
  direct-to-offer streams, and no longer zeroed out on pure direct traffic
  where LP views don't exist. In a pure Lander flow every visit IS an LP
  view, so those values are unchanged.
- **LP CTR on direct offers** is now a dash (`null`) — there is no CTA to
  measure; the UI renders `—` instead of a made-up 0%/100%, and CSV leaves
  the cell empty. Division-by-zero guards cover every denominator.

### Changed
- Metric tooltips (column customizer) explain the dual semantics — universal
  CPV/EPV vs Lander-funnel EPC/CPC / LP CTR — in all 7 languages.
- `tests/report_metrics_test.php` pins the new math with hand-computed
  reference cases: a direct-flow stream, a mixed campaign row, and the
  canonical funnel (Lander parity proof).

## [0.9.9.1] — 2026-08-17

### Added
- 🛑 **Campaign switch stops real spend** — pausing a tracker campaign can now
  pause the linked Facebook campaigns / ad sets / ads through the Meta
  Marketing API (`sync_remote_ads` on `ad_entity_toggle_status`, entities
  resolved from `clicks.parameters_json` with the `ad_campaign_id` →
  `campaign_id` fallback); new `campaign_remote_links` endpoint powers a
  safety confirmation that lists the ad entities before stopping them.
- 📊 **Column grid (Line System)** — light vertical dividers across the
  high-density Campaigns / Reports / Landers / Offers tables (headers, cells
  and the totals footer); the Reports table adopted the tracker styling.
- 🪟 **Tabbed editors** — Affiliate Network and Traffic Source editors split
  into General / Parameters / Notes with a pinned header and footer.
- 📈 **EPV metric** (earnings per landing-page visit) with column hints in
  the report customizer.

### Changed
- The pause switch moved to a dedicated sortable **Status column**, away from
  the campaign name — a stray click on the name can no longer stop live ads.
- **EPC / CPC redefined per LP click** (was: all clicks), aligning the
  metrics with the landing-page funnel.

### Fixed
- **Modals fit the screen at 100% zoom** — the 100px overlay inset that
  pushed Save buttons below the fold is gone; only the modal body scrolls.
- **Copy buttons on plain-HTTP/IP installs** — `navigator.clipboard` is
  unavailable outside secure contexts; a shared `utils/clipboard.js` helper
  falls back to `execCommand` (network editor postback URL + macros,
  integration keys, landing snippets).
- Reports: the pinned name column no longer slides under sticky metric
  headers during horizontal scroll.

## [0.9.9.0] — 2026-08-17

### Added
- 🎮 **RedTrack-style play/pause** — toggle internal campaigns (a disabled
  campaign stops serving immediately, `index.php` answers 503) and real
  Facebook ads / ad sets / campaigns right from the Campaigns table and report
  rows (`campaign_id` / `ad_id` / `adset_id` / `ad_campaign_id` layers) via
  `FacebookAdsEngine::updateEntityStatus`; the owning ad account is resolved
  through `cost_records`, falling back to a single connected account. Reports
  now return raw `dim_ids` alongside display names.
- 🚦 **Per-stream "Collect clicks"** (schema v24, `streams.collect_clicks`,
  default on) — fallback / white-page streams serve their destination without
  writing a `clicks` row, consistently across `index.php`, `click.php` and the
  Click API; stream-header checkbox with a warning note in the editor, 7 locales.
- 🧩 **Ads Manager overlay extension** — row pills fused with attributed spend
  and verified revenue (`extension_ads_stats`), a per-entity deep-stats modal
  with daily history, landings & offers breakdown and Pixel/CAPI delivery
  accuracy (`extension_deep_stats`, 36-assertion fixture test), an
  auto-provisioned read key from the Integrations page, and a downloadable
  `data/orbitra-extension.zip`.
- 🏷️ **Keitaro-style domain panel** (schema v23) — dedicated `domain_groups`
  (seeded from used offer groups under the same ids, FK rebuilt with a
  restore-on-failure guard), per-domain admin access (deny → panel/API 404 on
  that host while tracking keeps working; `status=Disabled` → whole host 404),
  Cloudflare proxy flag (skips the Certbot queue — the SSL worker previously
  retried proxied domains forever), registrar/DNS metadata, bulk add with URL
  cleanup, "Add more" workflow; 7 locales, migration fixture test.
- 📊 **High-density tables** — Campaigns / Landers / Offers get compact 38px
  rows, sticky header and TOTAL footer inside a scrolling container, theme-safe
  zebra striping, single-line campaign rows (switch + name + alias), a ⋮ row
  menu (Edit / Costs / Copy link / Duplicate / Clear / Delete) and pagination
  (25/50/100/All) with filter-wide totals.
- 🖥️ **Device taxonomy** (`core/Device.php`) shared by the tracker, Click API
  and reports; CPV metric; editor preference presets; affiliate-network
  template presets.

- 📈 **Dashboard redesign** — settings modal rebuilt on theme variables with
  four metric groups and three presets; 27 selectable StatCards wired to real
  aggregated metrics (cost no longer hard-coded to zero); finance masking
  extended to CPL, CPS and EPV; dashboard translations across all 7 locales.
- 🎨 **Theme sweep** — hardcoded Tailwind grays/blues purged panel-wide
  (admin header, AutomationSettings, ClickDetailsModal, Login, SetupWizard,
  dashboard modals, RecentClicks and more); ClickDetailsModal's white-on-white
  fixed; semantic status colors on --color-success/--color-danger.
- 📚 **Docs refresh** — READMEs updated to the current feature set and halved
  (58 embedded per-version note blocks dropped; CHANGELOG is the history).

### Changed
- All monetary and unit metrics render as `$0.00` (2 decimals) everywhere,
  including CSV export — previously CPC/EPC/CPV showed four decimals.
- `var/` (sessions, locks, caches) is gitignored.

### Fixed
- Schema v25 adds `campaigns.state` — the column never existed in any DDL or
  migration; the new play/pause toggle surfaced it on live testing with
  "no such column: state".

## [0.9.8.2] — 2026-08-16

### Added
- 🔐 **RBAC role templates** (Admin / Media Buyer / Video Editor / Developer /
  Custom) — one-click role + permission matrix in the user modal — plus
  server-side financial masking: `show_costs` / `show_revenue` / `show_payout`
  null the money families across metrics, chart, campaign_report, campaigns,
  offers endpoints; save-guards restore hidden amounts. Nav tabs hide on
  `None` permission; gear menus filter per item.
- 🌐 **Namecheap integration** — Keitaro-style zero-config DNS parking, domain
  purchasing, import & subdomain parking.
- 🕵️ **Cloaking quick targeting filters** — GEO, devices, bot-ISP blocklist on
  the cloak card + global `bot_isp_list` setting.
- 📡 **CAPI per-pixel `event_source_url`** with `{campaign_url}` /
  `{landing_url}` / `{clickid}` macros (migration 22).
- 🎛️ **Redesigned Tracking tab** — two-column layout, per-method options, live
  widget preview, install hints.
- 📊 **Keitaro-parity Landings & Offers columns** — visits/uVisits, LP
  clicks/CTR, leads/sales/rejected/trash, Approve %, CR, cost/revenue(conf),
  profit, CPC/EPC/EPV, ROI/ROI(conf); compact `shortLabel` report headers with
  full-name tooltips.

### Fixed
- 🚨 **"Prefetch ignored." blank screen** — the prefetch guard killed the page
  instead of the click; all three entry points now serve the campaign, skip
  only the click INSERT and send `Cache-Control: no-store`.
- 🧩 **Generated snippets were broken code** — kclient-php nested open tag,
  back-button trap firing on Forward, unterminated-concat SyntaxErrors in
  link/iframe/script snippets; `EXIT_BUTTON_COLORS` wired into the editor.
- 🔗 Campaign URL kept ad-network macros percent-encoded (`%7B%7Bad.id%7D%7D`)
  so they were never substituted.
- 📘 Facebook Ads template: real Meta macros `{{placement}}` /
  `{{site_source_name}}`, fabricated `{{site.name}}` dropped.
- 🌍 i18n: country names hardcoded Russian in `countries_list`; es/zh/uk
  machine-translation howlers (CPC → "Communist Party", IP → "intellectual
  property", GEO → "orbital").
- 🎨 Themes: duplicate `.btn-primary` made neon buttons unreadable; Geo
  Profiles / GeoSelector hardcoded light-only colors; country dropdown clipped
  by the filter modal; columns-modal checkbox and drag reliability.

## [0.9.8.1] — 2026-08-16

### Fixed
- ✅ **Stream names were wiped on every campaign save** — the stream INSERT in
  `save_campaign` never carried the `name` column, so anything the editor's
  stream-name field collected became NULL on save. Found live while testing
  0.9.8.0 on the demo (a stream saved as "MCP stream" came back as null).
  Lost names need re-entering once after the update.

## [0.9.8.0] — 2026-08-16

### Added
- 🎯 **Keitaro-style landing/offer picker for streams.** The "Add landing pages /
  Add offers" split button opens a selector modal with instant search (name, URL,
  id), Group / Affiliate Network / Country filters, multi-select with Select all,
  and "Already added" badges. Picked entities enter the stream with even weight
  redistribution (floor(100/N), remainder on the first item). The dropdown's
  second action creates a new landing/offer without leaving the stream; the
  stripped-down name+URL offer form is now the full OfferEditor.
- 🔀 **AND / OR filter logic per stream.** The FILTERS header grows an
  `[AND|OR]` switcher (from the second filter on). Abstaining filters
  (undetermined country, missing ISP data) block nothing under AND and satisfy
  nothing under OR. Saved with the campaign, carried by duplicate/export
  (migration 21).
- 🧩 **"Select and Order Columns" for Landings and Offers.** Checkboxes, Select
  All, drag-to-reorder by grip handle, localStorage persistence, Restore to
  default; Name stays required. The offers footer totals sum counters/money and
  recompute ratios from the totals.
- 📊 **Report-grade stats columns on the offers table** — leads, sales,
  rejected, conversions-as-events, CR, EPC/CPC (confirmed), revenue / cost /
  P&L / ROI (confirmed) — computed by the same status-group aggregate and
  derive function behind the verified 64-metric reports, pinned by
  `tests/offers_stats_test.php` (28 assertions). `conversions` counts events
  again, matching the reports.

### Fixed
- ✅ Affiliate-network postback URLs: the full template with macros is built
  (scheme follows the panel protocol), editable inline, copyable in one click,
  and shown in the networks list.
- ✅ Campaign groups: the Groups button on Campaigns wrote into `offer_groups`
  and loaded a non-existent `action=groups` — groups never appeared in the
  filter. `groups` is now an alias of `campaign_groups` for older clients.
- ✅ Column customizer (Campaigns): draggable rows swallowed every click, drag
  reorder reshuffled rows under an active search, and Select All selected all
  64 columns regardless of the filter.
- ✅ Local landings open on the first click after upload: single-nested-folder
  ZIPs flatten automatically (`__MACOSX` junk removed) in both landing and
  offer uploads; legacy nested uploads resolve at serve time — page and assets;
  statcache is dropped before entry lookup; the editor's Save blocks with
  "Uploading archive…" until the ZIP lands.
- ✅ Active toggle buttons (Trend/Cohort, metric and view switchers) became
  unreadable on light-accent themes — text now follows `--color-text-inverse`
  per theme (`.btn-primary` too, which exposed and fixed a wrong dark-theme
  inverse value).
- ✅ Offer/Landing editors unified: "Create Offer/Landing" titles, singular edit
  titles, the mislabeled double-"Notes" tab merged into Parameters, one type
  switcher (the method dropdown no longer fights the segments), matching
  footers, quick-create group "+" in both.
- ✅ Empty ghost rows in streams (`{id:'',weight:100}`) render as clickable
  placeholders that open the picker; empty landing/offer lists show dashed
  empty states.
- 🌏 `Asia/Kolkata` (IST, UTC+5:30) selectable in DateRangePicker, profile,
  setup wizard and offer capping; the CET row reads "Berlin / Paris / Rome".

## [0.9.7.9] — 2026-08-16

### Added
- 📚 **Traffic-source and affiliate-network templates from Keitaro.** 196 source and
  395 network templates ship in `data/keitaro_*.json` and merge into the built-in
  lists — 209 sources and 438 networks in the dropdowns, available to every install
  after an update, nothing to import by hand. They are generated from Keitaro's own
  exports by `cli/generate_keitaro_templates.py`, which replaces a hand-written batch
  whose macros and postback hosts were invented: PopAds mapped the click id to
  `[WEBSITEID]` (that is the site), MaxBounty used `&s1=` / `{rate}` instead of
  `s2=` / `#RATE#`, Zeydoo had payout and subid swapped, and RollerAds, Galaksion,
  Kadam and RichAds pointed at postback hosts that do not exist. Such a template looks
  right in the UI and tracks nothing.
- ↔️ **Keitaro status transforms in outgoing postbacks.** `{status: lead=reg sale=dep}`
  now resolves against the internal status (`core/PostbackMacros.php`) — 15 of the
  imported source templates use it and used to send the literal macro. `{clickid}`,
  `{click_id}`, `{offer_id}` and `{conversion_revenue}` joined the S2S macro set the
  same templates rely on.
- 🧪 **`php tests/keitaro_templates_test.php`** — checks both packs against values taken
  from the Keitaro export, so a regenerated pack that drifts fails in CI, not in
  production.
- 🎛 **Custom reports: column customizer, presets and drag-and-drop.**
  `ReportCustomizerModal` adds a searchable column picker with categories (traffic,
  conversions, financial, unit economics), presets (COD, Lander→Offer, Best,
  Finance & ROI, All), up to **five** group-by levels with URL-param dimensions,
  and eq/neq/contains/not-contains filters. Column headers on the campaigns table
  and the report drag-and-drop to reorder.
- 📏 **30+ report metrics** computed in `core/ReportMetrics.php`: unique rate,
  LP CTR, sales/holds/rejected/trash, approve rate (with and without trash),
  confirmed/hold/rejected revenue split, real (aggregator) revenue and ROI,
  CPC/UCPC/CPA/EPC/UEPC, earnings per conversion — with a sticky totals row.
- 📅 **Date-range picker with a real timezone.** Interactive calendar with quick
  presets and a timezone selector; the choice is sent with every dashboard and
  report request and the API shifts all date conditions by it (a fixed offset per
  check — DST edge days on long ranges can differ by an hour).
- 🔗 **Direct URL streams.** A redirect stream can point straight at an external
  URL instead of an offer record, with `{subid}`, `{clickid}`, `{ip}`, `{country}`
  and every captured click parameter substituted (`{subid}` used to travel to the
  affiliate network as a literal).
- 🧭 **Refined cloaking UI** — toggle pills per protection layer, segmented safe-page
  selector (external URL / tracker landing / inline HTML) — plus all redirect methods
  (JS, meta refresh, iframe, form POST, preload, cURL proxy) on the landing editor.
- 💰 **Cost Sync upgrades**: an *Add connection* modal (Facebook / Google Ads /
  TikTok: account id, token, proxy) and manual cost entry for a custom range,
  right from the campaign's Integrations tab.
- 🤖 **Bot list import**: transactional chunked inserts (500 per chunk backend,
  2000 frontend) with a .txt/.csv picker and progress — 50 000+ IPs/UAs import
  without timeouts.
- 🌍 **i18n hardening**: `npm run check:i18n` is green with full parity in all
  7 locales; the report customizer's hardcoded strings are translated.

### Fixed
- 📈 **Report performance**: migration 19 adds `conversions(click_id)`,
  `conversions(click_id, status)`, `clicks(campaign_id, created_at)` and
  `revenue_records(click_id)` — every report metric joins on those, and none had
  an index.
- 🧮 **Purchases counted clicks, not conversions** in the campaigns table; ROI at
  zero spend now shows "—" instead of a made-up 100%.
- 🔄 **The update check no longer pretends everything is fine when GitHub is
  unreachable**: a failed fetch used to silently report latest=current ("no
  update"); the Update page now explains the check failed and gives the manual
  `git pull` command.
- 🤖 **The placeholder metrics are real now** (migration 20): Bots and Bot %
  come from the cloaker's UA signatures checked per click, Proxies from the
  click's IP2Proxy verdict, unique clicks by campaign/flow/global and Visitors
  from honest uniqueness probes (IP + UA per the campaign's method, within its
  window), Empty referrers from SQL, and Time since LP click from the actual
  landing→offer timestamps (`landing_at`/`offer_at`).
- 🧮 **Conversions count events, not flagged clicks** — a click with three
  conversions counted once and CR could never exceed 100%; LP CTR no longer
  counts direct-linked offer clicks; **deposits stopped double-counting as
  sales**; registrations/deposits/revenue-by-status were dead zeros because the
  queries never selected them; eCPC lost its bogus ×1000.
- 🖥 **Reports open flat by default** (one grouping level; deeper drill-down is
  one "+" away) — the layered default made small reports look like duplicated
  subtotal rows. Wide column sets scroll horizontally with the name column
  pinned; the columns selector no longer blanks the screen (hooks order) and
  its buttons no longer show a doubled "+".
- 🎨 **AffiliateNetworkEditor theme-safe**: hardcoded light-only Tailwind
  colors replaced with the design-system variables — the modal now follows all
  five themes; the postback callout tints with the active theme's primary.

## [0.9.7.8] — 2026-08-15

### Added
- 🎯 **Tracking tab in the campaign editor (Keitaro parity).** Every connection
  method is generated with the campaign's id, alias and token baked in: KClient JS
  (with a base64 anti-adblock option), KClient PHP (download button included),
  Tracking Script (`KTracking.reportConversion` / `update` / `{offer}`), banner
  blocks (script + iframe, sized), Campaign URL, link/iframe/script, Tracking
  Pixel, Countdown Timer, Back Button Trap, Exit Intent and WordPress — with
  per-method options that update the code live.
- 📦 **The integration endpoints exist now.** `kclient.php` (KClickClient-
  compatible class over Click API v3, served for download via `?download=1`),
  `kclient.js`, `tracking.js` and `banner.js` ship with the tracker;
  `/pixel.gif` logs impression clicks, registers conversions by subid and merges
  click-parameter updates. The global Integrations page snippets pointed at
  files that did not exist — they 404'd.
- 🔌 **Click API v3: stream content.** Action landings ("Show as HTML/text")
  return their body with `{offer}` resolved to a signed landing→offer transition
  (`info.offer_link`), and clicks capture the full parameter whitelist via the
  shared `ClickParams` — `ad_id`/`adset_id`/`campaign_id` used to be dropped for
  API traffic, silently breaking cost matching.
- 💰 **Cost Sync on the campaign's Integrations tab.** Spend connections
  (Facebook / Google Ads / TikTok) with a *Sync now* button, a per-campaign
  match diagnostic — do the last 7 days of clicks carry the IDs cost import
  matches on? — and the ready-made Dolphin/Fbtool push URL for this campaign.
- 🏷 **Local offers.** ZIP upload with the same security pipeline as landings
  (mime/method checks, PHP gating, scan, cleanup), serving in place of the
  redirect — including the `/?_lp=1` transition — with macros, PHP offers via
  PhpLanding and asset passthrough (`orbitra_lo` cookie).
- ☁️ **Cloudflare integration.** One API token; parked domains whose zone is in
  the account get their A record written automatically (extra A/AAAA records
  cleaned up), proxied domains take SSL from the CF edge and leave the certbot
  queue, and *Re-point all domains* moves every A record when the tracker
  changes servers. New "Domains & SSL" group on the Integrations page;
  `docs/cloudflare.md`.
- 🛡 **Stronger cloaking.** 50+ field-supplied bot UA signatures; daily-updated
  datacenter/crawler IP ranges from lord-alfred/ipranges (~20k CIDRs, IPv4+IPv6
  binary search) that flag a perfect browser UA sitting on a cloud IP;
  self-healing download — the first cloak visit (or a status probe) fetches the
  lists in the background after the response is sent, so existing installs need
  no cron. Automation page gains a status card with an *Update now* button.
- 🔀 **Stream actions: "Send to campaign"** (the backend supported it, the
  editor never offered it) and **"Show text"** with a payload (empty = blank
  white page); `show_html` finally has a field for the HTML itself. Stored as
  `type:payload`, backward compatible.
- 📊 **Layered reports.** Up to three stacked group-by dimensions — Country →
  Campaign → adset_id drill-down with per-level subtotals — plus a global
  across-campaigns report from the Campaigns page. New dimensions: campaign,
  adset/ad/ad-campaign id, offer, landing, OS, browser, day, Sub ID 1–10 (ids
  resolved to names in batch). Columns right-aligned with tabular numerals,
  sticky header and a grand-total row; CSV export carries the layers.

### Fixed
- 💸 **Report cost was hardcoded to 0.00** — profit and ROI were fiction
  whenever spend was imported. Cost is now `SUM(clicks.cost)` and every
  derived metric is recomputed per row, subtotal and total.
- 🗂 **install.sh deleted uploaded local offers** on a re-run over an existing
  installation: `offers/` is backed up and restored exactly like `landings/`.

## [0.9.7.7] — 2026-08-15

### Added
- 🎯 **Facebook is now selectable in the campaign editor.** The '+' next to the
  traffic-source select opens the source editor, so a Facebook (or any) source can
  be created from a template without leaving the campaign. Picking a traffic source
  auto-fills the campaign's URL parameters with the source's macros
  (`ad_id={{ad.id}}&adset_id={{adset.id}}&campaign_id={{campaign.id}}…`) — the
  Keitaro behaviour: switching a source replaces the parameter set. Parameters now
  persist (`campaigns.parameters_json`, migration 18) instead of living only in
  browser memory, and the built campaign URL carries them as a query string.
- 🧩 **Integration code snippets** on the campaign's Integrations tab: Keitaro-style
  link / iframe / script tags built from the campaign URL, with referrer/title/
  query-string pass-through, ready to paste into site builders.
- 🎵 **TikTok Ads cost engine.** `aggregator_engines/TikTokAdsEngine.php` pulls
  daily spend from the TikTok Business API (Access Token + Advertiser ID, optional
  proxy), attributes it to clicks by `ad_id`/`adgroup_id`/`campaign_id` — the keys
  the TikTok traffic-source template writes (`__CID__`, `__AID__`,
  `__CAMPAIGN_ID__`, plus `ttclid=__CLICKID__`). Same cron, same cost_records, same
  flat-CPC distribution as the Facebook engine.
- 📥 **Keitaro-compatible `update_costs` endpoint (Dolphin / Fbtool).**
  `POST /admin_api/v1/campaigns/{id}/update_costs` with
  `Authorization: Bearer <personal API key>` accepts the exact payload Dolphin and
  Fbtool.pro send to Keitaro: `{start_date, end_date, cost, currency, timezone,
  filters:{sub_id_4:…}}`. Filters match click parameters (`sub_id_3`→`adset_id`,
  `sub_id_4`→`ad_id` — the Keitaro Facebook-template defaults — or any param name
  directly); the amount is
  converted to the tracker currency and split evenly across matched clicks, and a
  re-sent period overwrites rather than accumulates. Unmatched amounts are parked
  in `cost_records` under a hidden `external_api` connection so nothing is lost
  silently. Routing lives in `.htaccess` and `core/nginx_config.php`;
  `docs/dolphin-fbtool.md` documents the setup on both services' sides.

### Fixed
- 🪟 **The '+' buttons that never worked.** Campaign editor (group, traffic
  source), offer editor (group, affiliate network) and the 'Группы'/'Источники'
  buttons on the Campaigns and Landings pages were committed without click
  handlers back in the initial commit; all now open the right dialog, refresh the
  select and auto-pick the created item.
- 🗑 **Group deletion for every type.** Only offer groups could be deleted;
  `delete_campaign_group` and `delete_landing_group` reset the linked rows and
  remove the group. GroupsModal was also hardcoded to light-theme Tailwind colors
  and rendered washed-out in dark themes — it now uses the app's CSS variables.
- 🛬 **Keitaro import: traffic-source parameters were stored as the raw Keitaro
  blob.** Keitaro keeps them PHP-serialized (or JSON, depending on the version)
  with its own field names; Orbitra's `ClickParams` could not read that shape, so
  imported sources never captured `ad_id`/`adset_id`/`campaign_id` on clicks and
  cost matching had nothing to match. Parameters are now normalized into
  `{alias, param, macro}` entries; landing groups (`keitaro_groups` type
  `landings`) are imported and attached to landings.

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
- 🧭 **Both integrations now live on the Integrations page**, as their own entries
  with full management: account list with status and next update, add/edit,
  *Update spend* (a manual sync), pause/resume, clone, delete and search — the
  place a Keitaro user actually looks for them. Cost connections still appear on
  the Aggregators page and a campaign's pixel is still editable from the campaign
  itself; these are views of the same records, not copies.
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
