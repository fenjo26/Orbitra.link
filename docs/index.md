# Orbitra Documentation

Orbitra is a self-hosted traffic and conversion tracker: campaigns and streams, cloaking, landing pages and PWA, postbacks, cost import, reports. It runs on a single inexpensive VPS (PHP + SQLite + Nginx) and is Keitaro-compatible via Click API, campaign tokens, and templates.

This documentation is current for **v1.8.1**. For what changed in each version, see the [CHANGELOG](../CHANGELOG.md).

```bash
wget -O orbitra-install.sh https://raw.githubusercontent.com/fenjo26/Orbitra.link/main/install.sh && bash orbitra-install.sh
```

## Getting started

- [Quick start: your first campaign](getting-started.md)
- [Installation and updates](deployment.md)
- [Migrating from Keitaro](keitaro-migration.md)
- [Feature overview](features.md)

## Traffic

- [Campaigns and streams](campaigns.md) — filters, schemas, rotation, test links
- [Cloaking](cloaking.md) · [How the cloaker works](cloak-how-it-works.md) (EN)
- [Landing pages](landing-pages.md) (EN) — landing types, `{offer}`, macros, the JS adapter, LeadForge
- [Tracking Client (PHP)](tracking-client-php.md) (EN) — `kclient.php`
- [PWA landing pages and Web Push](pwa-push.md) · [PWA Funnel](pwa-funnel.md) (EN)
- [Content gallery](content-gallery.md)
- [Session recordings and click heatmaps](session-recordings.md) — replays of real visits, click and scroll maps, storage limits

## Conversions and money

- [Postbacks and conversions](postbacks.md) — incoming postbacks, statuses, S2S to the source
- [Costs](costs.md) — Facebook, TikTok, Google Ads, the revenue aggregator
- [Facebook / Meta](facebook.md) (EN) — cost import and Conversions API
- [Dolphin / Fbtool](dolphin-fbtool.md) — receiving costs via the Keitaro Admin API
- [Traffic sources and affiliate networks](sources-and-networks.md)
- [Affiliate network offer parameters](affiliate-network-parameters.md) (EN)

## Analytics

- [Reports, logs, and metrics](reports.md)
- [Session recordings and click heatmaps](session-recordings.md)

## Infrastructure

- [Domains and SSL](domains.md)
- [Cloudflare](cloudflare.md) · [Cloudflare Full Strict SSL](CLOUDFLARE_FULL_STRICT_SSL.md) (EN)

## Management and integrations

- [Administration](administration.md) — users, permissions, settings, geo databases, updates
- [Telegram bot](telegram.md)
- [MCP: an AI assistant in the tracker](mcp.md)
- [REST API](api.md) — authorization and the catalog of all actions

## For developers

- [Architecture](architecture.md) — internals, crons, the database, development, releasing a version
- Technical specs and write-ups: [load and SQLite](TZ_LOAD_PERFORMANCE.md), [SSL chains and privacy](TZ_SSL_CHAIN_AND_PRIVACY.md), [Web Push](TZ_PWA_PUSH.md), [logs, geo, streams](TZ_LOGS_GEO_STREAMS.md), [Media Core v1](media-core-v1.md), [LeadForge subid validation](leadforge-subid-validation.md) (EN)
- [Archive of outdated documents](archive/README.md)

## Support

Questions and bugs — [GitHub Issues](https://github.com/fenjo26/Orbitra.link/issues). In the panel: **⚙ → Support and feedback**.
