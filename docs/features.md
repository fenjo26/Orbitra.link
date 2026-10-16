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
- **Click API** — Keitaro-compatible campaign tokens, `click.php`, Click API v3, `kclient.php`.

## Conversions and money

- **Postbacks** — status training, built-in aliases, custom conversion types, currency conversion, an incoming-postback log. → [Postbacks and conversions](postbacks.md)
- **S2S to the source** — a queue with retries, a tester, re-sending missed conversions, auto-filling the source postback.
- **Conversions API** — Meta and TikTok, with reliable delivery and confirmation. → [Facebook / Meta](facebook.md)
- **Costs** — Facebook, TikTok (including without a developer app, via MCP), Google Ads with MCC, Dolphin / Fbtool, unmatched-cost diagnostics. → [Costs](costs.md)
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
