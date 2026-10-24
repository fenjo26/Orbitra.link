# Orbitra Features

A short overview with links to detailed pages. The per-version change history is in the [CHANGELOG](../CHANGELOG.md).

## Traffic

- **Campaigns and streams** — intercepting, regular, and default streams; rotation by position or weight; 14 filter types (country, device, OS, browser, language with regions, bot, IP, ISP, connection type, referer, keyword, URL parameter, day of week, time); AND / OR logic. → [Campaigns and streams](campaigns.md)
- **Schemas** — direct redirect to offer, landing + offer, action (404, text, HTML, another campaign), cloaking.
- **Rotation auto-optimization** — offer and landing weights recalculated by sales, CR, EPV, EPC, or ROI.
- **Test links** — real routing without recording a click, country override with `_geo`, tracing with `_dbg`.
- **Cloaking** — data centers, VPNs and proxies, bot lists, User-Agent heuristics, JavaScript verification, a "clean visitors to the next stream" mode. → [Cloaking](cloaking.md)
- **Landing pages** — local (ZIP and the built-in editor), external, preload, action, PHP; the `{offer}` macro, passing the choice made on the landing into `sub_id_N`, time on page. → [Landing pages](landing-pages.md), [Tracking Client (PHP)](tracking-client-php.md)
- **PWA landing pages and Web Push** — a store-style app builder with a rich native install sheet (your screenshots inside Chrome's install prompt, a live install-readiness check), a screen funnel, a localized offline page and in-app-browser guidance, your own subscriber base and push campaigns on your own VAPID keys with push-click tracking, per-message CTR, geo/language targeting, banner images and one-tap unsubscribe. → [PWA and Web Push](pwa-push.md), [PWA Funnel](pwa-funnel.md), [Content gallery](content-gallery.md)
- **Conversion popups** — each campaign's own popup with the offer button (editor → Popup tab): template, text & amount, image, timing, a live preview and a readiness hint; popup funnel statistics and a design × text variant dimension; Settings → Plugins holds the overview and the template for new popups. → [Conversion popups](conversion-popups.md)
- **Social link preview** — messenger and social preview bots get your og:* card instead of the click flow; humans are routed as always. → [Campaigns and streams](campaigns.md#social-link-preview)
- **Click API** — Keitaro-compatible campaign tokens, `click.php`, Click API v3, `kclient.php`.

## Conversions and money

- **Postbacks** — status training, built-in aliases, custom conversion types, currency conversion, an incoming-postback log. → [Postbacks and conversions](postbacks.md)
- **S2S to the source** — a queue with retries, a tester, re-sending missed conversions, auto-filling the source postback.
- **Conversions API** — Meta and TikTok, with reliable delivery and confirmation. → [Facebook / Meta](facebook.md)
- **Costs** — Facebook, TikTok (including without a developer app, via MCP), Google Ads with MCC, Dolphin / Fbtool, unmatched-cost diagnostics. → [Costs](costs.md)
- **Push & pop networks** — spend import from AdMaven, PropellerAds, Kadam, Galaxion, HilltopAds, Adsterra and Clickadu, per campaign and — where the network reports it — per zone (site). → [Push & pop networks](push-pop-networks.md)
- **Zone Killer** — per-campaign rules that surface (and in auto mode block through the network's API) the zones burning money; dry-run by default, with a block journal. → [Push & pop networks](push-pop-networks.md#zone-killer)
- **Revenue aggregator** — pulls revenue from affiliate-network dashboards without postbacks.

## Analytics

- Dashboard, campaign list with customizable columns, a report builder with ~40 dimensions, trends, cohorts. → [Reports, logs, and metrics](reports.md)
- A Keitaro-style click log: columns and filters on any parameter, CSV up to 100,000 rows, a click card with full device detail and the real direction.
- An honest landing funnel: real clicks through, time to offer, time on page, "possible bots".

## Infrastructure

- **Domains and SSL** — automatic Let's Encrypt, Cloudflare (multiple accounts), Namecheap and Dynadot with purchase and parking, backorder. → [Domains and SSL](domains.md)
- **One-command install** on Ubuntu / Debian, updates from the panel. → [Installation and updates](deployment.md)
- **Migration from Keitaro** with token preservation. → [Migrating from Keitaro](keitaro-migration.md)

## Management

- Users with per-resource permissions and access to individual campaigns, two-factor login, API keys. → [Administration](administration.md)
- **Telegram bot** — notifications, a digest, commands with statistics. → [Telegram bot](telegram.md)
- **MCP** — connecting Claude and other AI assistants to the tracker. → [MCP](mcp.md)
- **REST API** — ~290 actions. → [REST API](api.md)
- A Facebook Ads Manager extension, 7 interface languages, themes.

## v1.7.1 additions

- **TikTok cost timezone**: spend is laid out by the ad account's own calendar day (was UTC); OAuth connects pick timezone/currency up automatically; `aggregator_cron.php --rematch --days=30` re-spreads history.
- **Agencies**: the reseller's commission is written into every import — ROI includes it from the first sync, history never rewrites.
- **Ad names & platform metrics in reports**: names instead of ids, status chips, Impressions / platform clicks / CPM / CTR / **Click loss** (1 − tracker visitors / platform clicks).
- **Creatives library**: the gallery's Creatives tab — platform-synced files with spend/revenue/ROI, dHash families (a manual draft picks up metrics when an ad starts running it), tags, per-day series, campaign-report grouping, Telegram top/worst digest with burnout alerts, MCP tools.
- **In-app browser escape** (PWA renderer v19+): intent:// / x-safari exit with click continuity and a success-rate metric.
- **Push engagement**: honest "viewed" events from the device, conversion attribution per push (window, last click wins), {offer.value} macros from the offer's per-country table, sending at the subscriber's local time with quiet hours.
- **FB comment cleaner**: rule sets (stop words with whole-word mode, admin regexes, links/contacts/emoji/repeats/length), dry-run by default, hide by default, journal with restore, auto-pause on rate limits.
- **External PWA builders** (PWA.Group / TSL.app / custom) as weighted stream targets with `/eflow/<campaign>` return and `{eid}` postbacks.
- **Telegram channels as offers** on separate funnel bots (never the admin bot): `t.me/<funnel bot>?start=<subid>` deep links, join-request invites, auto-approval within the offer window, `tg_join` conversions, inbound postbacks by `tg_user_id` + `channel_id`.
- **Conversion alerts to channels**: rule-based alerts through the admin bot — chosen chat, statuses and campaigns, HTML template with ad-name macros, test send and log.


## v1.7.3 additions

- **Operator web push**: the panel installs as an app (navbar Install pill / iOS guidance); every user can subscribe their own devices and receive conversion pushes (status/campaign filters) and day/week/month digests at their profile-local time. → [PWA and Web Push](pwa-push.md)
- **Notifications page**: push (personal) + Telegram (admin: chats with per-chat toggles, link codes, digest schedule, channel rules) in one place, with entries from the avatar/gear menus, the Profile and the Push page; deep links `?tab=notifications&sub=…`.
- **Telegram weekly/monthly digests**: the last complete week (Mon–Sun) and month, dated titles, `/weekly` `/monthly` commands in seven languages, once-per-window delivery with catch-up.
- **Digest honesty**: windows in the reader's timezone (push — profile; Telegram — first admin), safe-page hits of cloaked campaigns excluded, unique tray tags per conversion, tap-throughs to the conversions view / dashboard.

## v1.7.4 additions

- **Push delivery hardening**: the operator push sender loads on every path (the *Send test* button, conversion pushes from postbacks, funnel alerts), not only in the digest cron; on macOS a repeated notification tag no longer arrives as a silent Notification Center update — the worker retires the previous same-tag notification first, and every test push has its own tag.
- **Mobile drawer account block**: the burger menu in a narrow or installed panel opens with the user, *Notifications* (→ *Push to your phone*), *Profile* and — when offered — *Install*, mirroring the desktop avatar menu.
- **Masked secrets for API-key readers**: `api.php?action=settings` called with an API key returns every credential as `********` + the last 4 characters; posting a masked value back never overwrites the stored secret.

## v1.7.5 additions

- **PHP security banner ("php2shell")**: the panel judges the running interpreter against the October 2026 advisory (CVE-2026-109618/109619, unauthenticated RCE, fixed in 8.5.12 / 8.4.27 / 8.3.36 / 8.2.35) and shows a red dashboard banner with branch-specific fix commands until the server is patched; EOL branches (8.1 and older) are pointed at the installer's PHP-upgrade path, distro backports (`apt changelog | grep 109618`) explain the dismiss button, and the in-panel updater prints the same advisory after updating. → [Deployment: Updating PHP](deployment.md)

## v1.7.7 additions

- **Paste-safe patch block in the PHP banner**: pasting the command block whole used to die at apt's `Continue? [Y/n]` (the next line was consumed as the answer and apt aborted); the block is now fully non-interactive — `NEEDRESTART_MODE=a apt install -y --only-upgrade 'php8.X-*'` — with `-y` answering the confirmation and `NEEDRESTART_MODE=a` answering the Ubuntu 22.04+ service-restart dialog.

## v1.7.6 additions

- **Runnable patch command in the PHP banner**: the v1.7.5 instruction `apt upgrade 'php*'` made apt expand the glob to the whole php section of the Ubuntu archive and abort on conflicts before upgrading anything; the banner, the updater notice and the docs now print `apt install --only-upgrade 'php8.X-*'` (only installed packages of your branch) and restart php-fpm with nginx without naming apache2 on servers that do not have it.

## v1.8.0 additions

- **Push & pop network spend import**: AdMaven, PropellerAds, Kadam, Galaxion (per campaign × zone) and HilltopAds, Adsterra, Clickadu (per campaign) in a *Push/Pop networks* card; zone rows attribute only to clicks carrying both the zone and the network campaign id, leftover zone spend sums onto the campaign's zone-less clicks. → [Push & pop networks](push-pop-networks.md)
- **Zone Killer**: per-campaign rules that surface — and in auto mode block through the network's API — the zones burning money; dry-run by default, block cap per run, append-only journal, Telegram notify. Blocking: AdMaven, HilltopAds, PropellerAds, Kadam, Galaxion; Adsterra/Clickadu copy the list for a manual blacklist. → [Push & pop networks](push-pop-networks.md#zone-killer)
- **Social link preview**: messenger/social preview bots get your og:* card instead of the click flow; humans are routed as always, search crawlers are not touched. → [Campaigns and streams](campaigns.md#social-link-preview)
- **Conversion popups**: a per-campaign plugin (editor → Popup tab, off by default): a popup with the offer button after a delay and real visitor activity; 7 verticals × 11 languages, three designs, a live preview and a readiness hint, popup funnel metrics and the design × text variant dimension; Settings → Plugins is the overview + the template for new popups, and `kclient.php` injects the popup itself. → [Conversion popups](conversion-popups.md)
- **Conversion notifications no longer arrive twice**: a repeat postback with the same status is still stored and logged but stays silent; a new conversion or a status change (lead → sale) notifies as before.
