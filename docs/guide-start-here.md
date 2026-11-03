# Beginner's guide: start here

New to Orbitra, or to trackers in general? This section walks you through the tracker the way you will actually use it — screen by screen, with screenshots. Every guide has a "What it is" part in plain words and a "How to set it up" part with steps.

The reference pages ([Campaigns and streams](campaigns.md), [Postbacks](postbacks.md) and the rest) stay the place for every option and edge case. The guides link to them when you want more depth.

## What a tracker does, in one picture

A tracker sits between your ads and the advertiser's page. Every visitor passes through it, so it can decide where to send them and remember what happened.

```
 Ad (Facebook, TikTok, push…)
        │  visitor clicks the ad
        ▼
 Campaign link  https://go.example.com/5i5pvic4
        │  Orbitra writes a click and gives it an ID (subid)
        ▼
 Streams — rules: "Germany + mobile → offer A", "bots → 404", "everyone else → landing"
        │
        ├──► Landing page (optional) ──► Offer
        └──► Offer (the advertiser's page in the affiliate network)
                         │  the visitor buys / registers
                         ▼
 Affiliate network ── postback with subid ──► Orbitra marks the click as a conversion
                         │
                         ▼
 Reports: clicks, conversions, cost, revenue, profit, ROI — by campaign, country, creative…
```

## The words you will meet

| Word | What it is in Orbitra |
|---|---|
| **Campaign** | One tracking link you put into an ad, plus the rules (streams) behind it |
| **Stream** | A rule inside a campaign: who it matches (filters) and where it sends them (offer, landing, safe page, 404…) |
| **Offer** | The advertiser's page you are paid for. Usually a link from an affiliate network |
| **Landing (prelander)** | Your own page shown before the offer to warm the visitor up |
| **Affiliate network** | The partner who pays you (Dr.Cash, Leadbit…). It sends conversions back by postback |
| **Traffic source** | Where the visitors come from (Facebook, TikTok, Google, push networks). It sets which ad IDs get into the link |
| **Postback** | A server-to-server notification "click X converted, status sale, payout 30" |
| **Click ID / subid** | The unique ID Orbitra gives every click. The network must send it back in the postback |

The full list of abbreviations (CPA, RevShare, FTD, EPC…) is in the [Glossary](glossary.md); every number in reports is explained in the [Metrics cheat sheet](guide-metrics.md).

## Suggested order

1. **[Your first campaign, step by step](guide-first-campaign.md)** — from an empty panel to a working link and a test conversion. Start here.
2. [Connecting a domain](guide-domains.md)
3. [Offers and affiliate networks](guide-offers-networks.md)
4. [Postbacks: what they are and what to tell your affiliate manager](guide-postbacks.md)
5. [Streams: how traffic is distributed inside a campaign](guide-streams.md)
6. [Stream filters](guide-filters.md)
7. [Cloaking for beginners](guide-cloaking.md)
8. [Landing pages](guide-landings.md)
9. [PWA apps](guide-pwa.md) and [push notifications to PWA users](guide-push.md)
10. [Content Gallery](guide-gallery.md)
11. [Creatives: tracking them and testing them in one campaign](guide-creatives.md)
12. [Metrics cheat sheet](guide-metrics.md) and the [Glossary](glossary.md)
13. [Geo databases](guide-geo-databases.md) — install them on day one
14. [AI assistant (MCP)](guide-mcp.md) — manage the tracker in plain language

> 💡 Want to click around before installing? The live demo is at [demo.orbitra.link/admin.php](https://demo.orbitra.link/admin.php) (login `admin`, password `password`, data is reset from time to time).
