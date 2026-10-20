# PWA Landings and Web Push

A PWA landing is a local landing that Orbitra renders into a full-blown
web app: an "app store" storefront, a manifest and a service worker.
Combined with the **Web Push** module it lets you grow a subscriber base right
on the storefront and send them pushes — all by yourself, without external services (FCM
is used only as the Android transport, Apple as the iOS transport; the keys
and the database are yours).

This document covers: the PWA builder, app installation, domain binding,
funnel instrumentation, the subscriber base, messages (manual and event-driven), the delivery
cron and troubleshooting.

---

## 1. PWA landing: what it is and how it works

### 1.1 How it works inside

- A PWA is an ordinary row in the `landings` table (type `local`) whose
  `config_json` contains `"pwa": true`. The click router, stream rotation and cloaking
  treat it like any other local landing — no new types.
- On save, the builder generates three files in the landing directory:
  `index.html` (a Google Play-style storefront), `manifest.webmanifest` and `sw.js`.
- The landing is served at **`/lander/<slug>/`** — Chrome only offers installation
  inside the manifest scope, so a root URL is not used.
- The click (a Click Log row + attribution cookies) is written **before** the redirect to the
  storefront, so the push subscription, funnel beacons and the hop to the offer
  are attributed to the real click.
- `manifest.webmanifest` (renderer v17) is built for Chrome's **rich install
  sheet** (Android 94+ / desktop 105+): besides the base fields it declares
  `id`, `lang`, `description` (with the `{value}` macros resolved) and
  `display_override`, and lists every storefront screenshot that passes
  Chrome's sheet constraints (each side 320–3840px, side ratio ≤ 2.3) as
  `screenshots` with a `narrow`/`wide` form factor. The editor's
  **Install readiness** card shows the same verdict live.
- Browsers without a native install prompt get an instructions overlay:
  Firefox/Opera on Android see the browser-menu steps (iOS sees the Safari
  steps while `ios_flow = instruction`), and in-app webviews (Instagram,
  Facebook, TikTok, generic WebView) get a dismissible
  "open this page in your browser" card — the funnel underneath keeps
  running.

### 1.2 The builder

Landings → **Add PWA** (or "Duplicate" on an existing one). The editor's main
blocks:

| Block | What it configures |
|---|---|
| General | App name, slug, group, icon and screenshots (via the Content Gallery), category, developer, language, version, "updated" |
| Store | Description (with `{value}` macros), tags, rating histogram, reviews, "what's new", badges (verified, glow, live counter), light/dark theme, color scheme |
| Screen | The in-app screen shown after installation: type, title, text, button, image, custom HTML/JS |
| Actions | Install-button action: to the offer / to a campaign / to a URL / nowhere (`action_target`), auto-redirect timers |
| Push | Enable the push subscription on the app screen (`push_enabled`) |
| Domain | Domain binding (see §2) + the offer for the direct domain |
| Readiness | A live checklist of what the native install sheet will look like: icon, description, screenshot sizes, push keys, domain binding |

The **Preview** button renders the config draft with the production renderer —
a live preview of the storefront and the screen (auto/iOS platform) without saving.

### 1.3 The funnel and beacons

The storefront page sends one-pixel beacons that write into the click:

- `pwa_intent_at` — a tap on "Install";
- `pwa_install_at` — the app was installed (the `appinstalled` event or the first
  standalone launch on iOS);
- `pwa_open_at` / `pwa_open_count` — re-openings of the installed app
  (at most once per 10 minutes per click);
- `push_prompted_at` / `push_declined_at` / `push_subscribed_at` — push
  subscription events;
- `push_fail_reason` — why a subscription did not happen
  (`nokey` / `unsupported` / `insecure` / `denied` / `dismissed` / `error` /
  `timeout`).

All the columns are visible in **click details** — the main funnel diagnostics tool:
the tracker names the reason itself, no browser logs needed.

### 1.4 The push subscription lives in the service worker

The subscription runs entirely inside the generated `sw.js`
(`orbitraSubscribe`): the page merely sends a message to the worker and heads off
to the offer. This is deliberate — on iOS the first, cold `pushManager.subscribe` can
take tens of seconds, and the subscription must not depend on how long
the user stays on the page. The worker also:

- re-subscribes by itself on `pushsubscriptionchange` (endpoint key rotation on
  the push service's side, VAPID key update);
- on every opening of the installed app quietly reconciles the subscription with
  the tracker settings (**self-heal**) — reopening the app repairs the
  subscription without a reinstall;
- shows incoming pushes and handles notification clicks
  (focusing an existing window and navigating to `data.url`).

The `{vapid_public}` placeholder in `sw.js` is substituted **at file-serving time**,
not at generation — a VAPID key change reaches already installed
apps without regenerating the static files.

### 1.5 RENDERER_VERSION

Every renderer change bumps `RENDERER_VERSION`
(`core/PwaLanding.php`). On the next view of the landing the tracker compares
the version marker in the rendered static files and **regenerates** outdated
files by itself — no manual re-uploading to the server.

---

## 2. Domain → PWA binding

A domain can be bound to a PWA directly: a visit to `https://domain.com/` opens
the storefront from the root, with no hop through a campaign and `/lander/` — a clean install URL.

- The binding is configured in the builder (Domain block) or in the domain card.
- One domain — at most one PWA; one PWA can hang on several
  domains.
- Next to the domain an **offer** is selected (`pwa_offer_id`): even without a campaign the
  "Offer" button on the storefront still leads to the offer via `/?_lp=1` with attribution.
- Priorities: an explicit `?campaign_id=` or campaign alias always beats the
  binding; the binding beats `index_campaign_id` on the root.
- Organic visits (a cold visitor without a click cookie) are logged as
  real clicks into the archived system campaign `orbitra-pwa-organic` — it is
  created automatically and is not visible in the campaign list. Repeat visits with
  a live cookie do not spawn duplicates.

---

## 3. Web Push: the base, keys, sending

### 3.1 VAPID keys and the contact

The **Push Base** page (panel → Push):

1. Fill in the **contact** — `mailto:you@domain.com` or `https://…`. Without
   a real contact key generation is blocked: Apple
   (`web.push.apple.com`) validates the `sub` claim in the JWT and answers
   **403 BadJwtToken** to placeholder addresses.
2. Click **Generate keys**. The keys are stored in the tracker settings
   (`vapid_public_key` / `vapid_private_key` / `vapid_private_pem`); encryption
   and signing are pure PHP + openssl (RFC 8291 + RFC 8292), no composer.
3. **Key rotation** asks for confirmation: existing subscriptions stop being
   valid, but every device quietly re-subscribes on the next
   opening of the app (self-heal); the base restores itself.

The diagnostics strip on the page shows: whether keys exist, the public key,
the contact (with a warning if the default one is set), the age of the worker's
last ping and the latest delivery error codes.

### 3.2 The "Subscribers" tab

The `push_subscriptions` table: id, click, endpoint, country, language, UA, status
(active / expired), created, last shown. Capabilities:

- filters by status/country, search by endpoint and click_id, 50 rows/page pagination;
- operations: **activate / deactivate / delete** (hard deletion —
  the GDPR scenario);
- **CSV export** of the whole base (`;` separator: id;date;status;country;language;click_id;endpoint);
- the **Send** button on a row — a test push to one subscriber **bypassing the queue
  and the cron**, instantly. Responses are decoded honestly: 403 — a hint about
  an invalid VAPID contact, 404/410 — the subscription is dead, anything else — the service's
  error code. This is the fastest way to test the whole pipe (keys → JWT →
  push service → device).

Subscribers multiply through reinstalls and re-subscriptions (the endpoint is changed by the
push service) — old rows expire by themselves via 404/410 on send,
no separate cleanup needed. Before a blast, check a subscriber's "freshness"
by its click (click details show the landing and the install UA).

### 3.3 The "Messages" tab

Messages are broadcast templates. Two kinds:

- **Manual** — what you send with a button;
- **Event-driven** (kind `event`) — sent by the cron automatically on an event:
  `install` (PWA install), `lead`, `sale`, delayed by `delay_seconds` seconds
  from the event.

Message fields: title (≤250), body (≤400), icon (a URL, conveniently from the
Gallery), banner image (a URL — Android Chrome shows it as a large picture on
the notification; iOS ignores it silently), click-through link, segment,
country and language targeting, the "active" flag.

**Segments** are computed from the subscriber's conversion counters at the moment of
queueing:

| Segment | Who receives it |
|---|---|
| `all` | all active subscribers |
| `reg0` | no registration |
| `reg1dep0` | registered, no deposit |
| `reg1dep1` | registered and deposited |

**Targeting** (migration 60) narrows any segment further at queue time:
`countries` — a comma list of ISO codes matched against the subscriber's geo
(`US, DE`), `languages` — 2-letter browser-language codes matched by primary
subtag (`en` matches a subscriber stored as `en-US`). Empty list = no
constraint. Junk entries are dropped on save, never stored.

The body supports macros expanded **at send time**:
`{variant1|variant2}` (random choice, nesting ≤10), `{Random=(X,Y)}`,
`{subid}` and other click macros — one message gives every recipient
its own text.

### 3.3.1 Push clicks and the message CTR

Every notification the SW shows carries the delivery ids in its `data`
(`subid`, `message_id`, `sid`). Tapping it fires one beacon
(`/pixel.gif?action=pwa&kind=pushclick`) **before** the navigation, so the tap
is counted even though the browser immediately leaves for the offer:

- `clicks.push_clicks` + `push_click_at` on the click row (throttled to one
  count per 10 minutes, like reopen counting) — this is the ROI number:
  subscribers → clicks → conversions, all on one funnel;
- `push_sends.clicked_at` on the delivery row — the Messages table renders
  **Push clicks** per message with the click-through percentage;
- the PWA funnel card totals include the landing's push clicks.

### 3.3.2 Unsubscribing

The notification itself carries an **Unsubscribe** action button (localized,
baked into the landing's SW; iOS ignores actions — tapping the notification
there still opens). Pressing it POSTs the browser's endpoint to the public
`/push_unsubscribe` ingest, which sets `is_active = 0` (idempotent; the keys
stay, so the panel can re-activate). There is also a standalone public page
`/push-unsubscribe?endpoint=<urlencoded>` — it renders a confirm button (the
deactivation is a POST behind it, so a prefetched link never unsubscribes
anyone) and can be linked from anywhere.

The generated SW also serves a **dedicated offline page** for failed
navigations (localized, with a Retry button) instead of the stale cached
listing — a cached store page would beacon a stale click id and its CTA cannot
work offline anyway.

### 3.4 Sending: the queue and the cron

The panel itself sends nothing. The **"Send now"** button on a message row
queues (`push_queue`) one entry per active subscriber of the segment, with duplicate protection, and answers "Queued: N".

Delivery is done by the cron worker **`cli/push_cron.php`** — **every minute**:

```
* * * * * www-data php /var/www/orbitra/cli/push_cron.php --quiet >> /var/www/orbitra/var/logs/push.log 2>&1
```

The line is installed by `install.sh` automatically (marker `# orbitra-push`); check:
`crontab -u www-data -l | grep orbitra-push`. The worker: flock against parallel
runs, batches ≤300, retry after 300 seconds, 429 respects
`Retry-After`, 401/403 — one retry with a fresh JWT, 404/410 — the subscription
expires, 5xx/network — back into the queue. Event messages are triggered
by the same worker via a cursor in settings.

Queue health is shown in the strip above the message list: pending/done/failed and the time
of the worker's last ping. If the ping is not ticking — the cron is not installed or not
running, and delivery will not happen.

### 3.5 What "sent" means

**A 201 from the push service means "envelope accepted", not delivered.** The push service
cannot break the encryption (E2E): it answers 201 and silently drops the message
if the device never reads it. The final judge is the phone alone. A monitored
verification chain:

1. Push Base → Subscribers → **Send** on the row → the "accepted" banner;
2. on the device: the notification shade (the banner may have silently passed while the
   app was open);
3. if 201 but silence — see §4.

---

## 4. Troubleshooting and common problems

**No subscription at all** — click details: `push_fail_reason` names the reason
(`unsupported` = no Push API — the Telegram/Chrome webview on iOS; `insecure` =
no HTTPS; `nokey` = VAPID not configured; `denied` = refused; `timeout` = slow
network — the subscription will arrive on the next open via self-heal).

**iOS — Safari only, and only from the home screen.** Web push on iOS works
under these conditions: iOS ≥ 16.4, the app added to the home screen **specifically from
Safari** (plain Safari has no `Notification` object — a subscription is never
created), a domain with a proper public certificate. An app added
to the home screen from **Chrome (CriOS)** gets no push guarantee: check the
installing browser by UA in click details (`CriOS/…` = Chrome,
`Version/…` = Safari). If it does not work — reinstall the icon from Safari.

**403 BadJwtToken when sending** — an invalid VAPID contact: Apple rejects
placeholder addresses (`mailto:orbitra@localhost`). Enter a real `mailto:`.

**201 but not arriving for everyone everywhere** — check that the server is on the latest
version (`git log --oneline -1` via `sudo -u www-data`): early builds had
crypto-envelope defects (a header without keyid, a key schedule not per RFC 8291)
that the service still accepted with a 201. Then a control experiment:
desktop Chrome → install the PWA → Allow → Send: if it arrives on the desktop but not on the
iPhone — the problem is the device/channel (a VPN breaks the persistent
APNs connection; Chrome installs); if the desktop gets nothing either — write to support with the
error code from the diagnostics strip.

**"Buttons don't click" after a panel update** — almost always the browser
cache with an old `index.html`: hard-reload (Cmd+Shift+R) or a private
tab.

**Silent cron** — `push_cron_last_ping_at` is not ticking: the cron is not installed.
`crontab -u www-data -l | grep orbitra-push`; the log is `var/logs/push.log`.

---

## 5. Tests

- `tests/push_sender_test.php` — cryptography: the ES256 signature verifies with
  openssl, the key in `k=` matches the signed key, the record decrypts
  byte-for-byte the way the browser does it (keyid read off the wire), a reference vector from the
  web-push library (npm) decrypts with our code, HKDF per RFC 5869,
  record size limits (body ≤ 4096 bytes — the Apple/FCM limit).
- `tests/push_macros_test.php` — macros and segment SQL.
- `tests/push_api_test.php` — message CRUD, geo/language targeting, the
  pushclick pixel (throttle + `push_sends.clicked_at`), `/push_unsubscribe`
  and the public unsubscribe page, RBAC.
- `tests/push_base_test.php` — the `/push_subscribe` validator, migrations, static files.
- `tests/push_cron_test.php` — queue, retries, subscription expiry.
- `tests/pwa_landing_test.php` — SW handlers, `{vapid_public}` substitution at
  serve time, the `Service-Worker-Allowed` header.
- `tests/domain_pwa_test.php` — root rendering of a bound domain, cookies,
  organic clicks, binding priorities.

## Leaving the in-app browser (renderer v19+)

A PWA cannot be installed inside the FB/IG/TikTok webview — every webview visit is a guaranteed funnel loss. With *Auto-open in browser* on (default), the landing leaves immediately: Android through an `intent://` into Chrome (current URL as the fallback), iOS 17+ through `x-safari-https://`; older iOS keeps the instruction card. The escape URL carries the click id, and the external browser **continues the same click** — no second click, no split funnel. The PWA funnel card shows *attempts* and *success rate* (returns in a real browser). Only the money side ever escapes: a safe page keeps serving the safe content.

## Delivery and conversion attribution

- **Viewed**: the service worker beacons the moment it actually shows the notification (`push_sends.viewed_at`) — *shown %* and CTR-from-views are honest, not inferred.
- **Conversions per push** (`core/PushAttribution.php`): a conversion on the subscriber's click counts for the push when it lands within the attribution window after the push **click** (recommended; `send` mode also available) — the *last* click before the conversion wins. Window: `push_attribution_hours` (1–720, default 72). FTD = the subscriber's first deposit, repeats count as re-deposits. The deposit stays attributed to the ad click too — campaign reports are unaffected.
- A banner warns when delivery drops below 50%: 404/410 endpoints are deactivated automatically on send attempts, the base heals itself.

## Offer macros in push text

`{offer.value}` / `{offer.value1}` / `{offer.value2}` resolve from the **offer's per-country table** (Country → bonus / freespins / currency; empty country = universal fallback) by the subscriber's country, through the offer the subscription's click went to. The message editor has a live preview for a chosen country + offer. Without context the macro stays visible — never silently blank.

## Sending at the subscriber's local time

The subscription stores the device's IANA timezone (older rows: derived from the geo country). A message with *Send at subscriber's local time* waits out the quiet hours — by default 23:00–08:00 local (`push_quiet_hours`) — and sends in the first allowed minute. The requeue keeps the row pending (`last_code=quiet`), it never fails.


## Operator's own push notifications (panel PWA)

Everything above pushes to **landing subscribers**. The panel itself is also a
PWA (installable from the browser), and Users & Profile → Profile →
**Push notifications** lets the operator receive web push on their own devices — conversion alerts and
day/week/month digests — no Telegram needed.

- **Where**: the Notifications page (avatar menu → Notifications, or the gear
  menu) — subtab *Push to phone*, personal for every user; the Telegram
  subtab (admin-only) holds the bot's chats and digest schedule. The Profile
  keeps a pointer card; the Push page carries a "these are landing
  subscribers" note pointing here.
- **Install**: the panel ships a manifest (`<panel>?orbitra_manifest=1`) and
  the same `/sw.js`. When the browser offers installation, an *Install* pill
  appears in the navbar; iOS Safari is guided by the card's instructions
  (Share → Add to Home Screen). Admins can generate the shared VAPID keys
  right in the card (contact included) — no detour to the Push page.
- **Enable on this device** subscribes the browser through the panel service
  worker. The VAPID keypair is **shared** with the landing push base (Push
  page → Subscribers → keys) — one keypair, two audiences; no keys yet is the
  card's first hint. On iOS, notifications work only inside the installed app.
- **Conversion alerts**: a push per matching postback (status badges —
  `sale`, `deposit`, … — and campaign IDs; empty = everything). Sent
  synchronously from the postback, like the Telegram alerts; a 404/410
  endpoint retires the device automatically.
- **Digests**: daily (today), weekly (the last complete week, Mon–Sun, sent
  on Monday) and monthly (the last complete month, sent on the 1st) — clicks,
  conversions, revenue, profit — at the user's local `digest_time`. Windows
  are computed in the user's profile timezone (DST-safe) and compared against
  the UTC `clicks.created_at`; excluded safe-page hits are left out exactly as
  on the dashboard (`orbitraSafePagePredicate`). A missed Monday / 1st (cron
  down, digest just switched on) catches up on the next tick. Sent by
  `cli/push_cron.php`; the `admin_push_digest_log` sent-marker makes a double
  send impossible.
- Tables: `admin_push_subs`, `admin_push_settings`, `admin_push_digest_log`
  (migration 69) — kept strictly apart from `push_subscriptions` (those are
  landing visitors). API actions: `admin_push_state`, `admin_push_subscribe`,
  `admin_push_unsubscribe`, `admin_push_settings_save`, `admin_push_test`.
- Core: `core/AdminPushNotify.php`; the panel `sw.js` handles `push` and
  `notificationclick` (same-origin links only). Tests:
  `php tests/admin_push_test.php`.

## Telegram weekly/monthly digests (migration 70)

The admin bot's daily summary grew two siblings, on the Notifications page →
Telegram: weekly (the last complete week, sent on Monday) and monthly (the
last complete month, sent on the 1st); only the send time is configurable.
All Telegram times — daily included — are read in the timezone of the first
admin's profile, not server time. Per-chat toggles mirror the
`/weekly on|off` and `/monthly on|off` bot commands; once-per-window
delivery is locked by `tg_digest_log` (unique chat × period × window). Totals
come from the same `AdminPushNotify::digestWindow()/windowStats()` helper the
push digests use, so Telegram and push can never disagree on the numbers.
