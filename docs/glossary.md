# Glossary

Abbreviations and terms of affiliate marketing and media buying, with what each one means in Orbitra. Formulas of every report column are in the [Metrics cheat sheet](guide-metrics.md).

## Payment models

| Term | Stands for | Meaning |
|---|---|---|
| **CPA** | Cost Per Action | You are paid (or pay) for a target action: a lead, a sale, a deposit. The most common model in affiliate networks |
| **CPL** | Cost Per Lead | Paid for a lead — a filled form, a phone number |
| **CPS** | Cost Per Sale | Paid for a confirmed sale |
| **CPI** | Cost Per Install | Paid for an app install |
| **CPR** | Cost Per Registration | Paid for a registration (gambling, dating, finance) |
| **CPD**, **FTD** | Cost Per Deposit, First Time Deposit | Paid for a player's first deposit. "FTD" is also the name of the event itself |
| **BL** | Baseline | The minimum a player must deposit or bet for the FTD to be paid |
| **RS**, **RevShare** | Revenue Share | You get a percentage of what the player or customer spends, for as long as they spend. In Orbitra it comes as `revshare` postbacks that add to profit without counting a new conversion |
| **Hybrid** | — | CPA + RevShare together |
| **CPC** | Cost Per Click | Paying per click — a typical price model of traffic sources |
| **CPuC** | Cost Per unique Click | Paying per unique click |
| **CPM** | Cost Per Mille | Paying per 1000 impressions |
| **CPV** | Cost Per View / Visit | Paying per visit (pop and push traffic) |
| **COD** | Cash On Delivery | The customer pays the courier on delivery. Typical for nutra and e-commerce offers in many GEOs; leads are confirmed by a call center, so Approve % matters |
| **SS** | Self-Service | The opposite of COD: the customer pays online right away |
| **Payout** | — | What the network pays you for one conversion |
| **Cap** | — | A limit on conversions per day the advertiser accepts on an offer. Over the cap, conversions may go unpaid |
| **Hold** | — | The time a conversion waits before it is approved and paid. Conversions "in hold" are *leads* in Orbitra |

## Conversion statuses

| Term | Meaning |
|---|---|
| **Lead** | A conversion is registered but not yet confirmed (hold, pending) |
| **Sale / Approved** | Confirmed and paid |
| **Rejected / Declined** | Rejected by the advertiser — not paid |
| **Trash** | Invalid: fake phone, duplicate, wrong GEO |
| **Approve, Approve %** | The share of leads that became sales |
| **Rebill** | A repeat payment by the same customer (subscriptions) |

## Performance metrics

| Term | Stands for | Meaning |
|---|---|---|
| **CR** | Conversion Rate | Conversions ÷ clicks |
| **uCR** | unique Conversion Rate | Registrations ÷ unique clicks |
| **CTR** | Click-Through Rate | Clicks ÷ impressions (for an ad) or clicks ÷ views (for a landing: LP CTR) |
| **EPC** | Earnings Per Click | Revenue ÷ clicks — what one click is worth |
| **EPV** | Earnings Per Visit | Revenue ÷ visits |
| **eCPC**, **eCPM** | effective CPC / CPM | The real price per click / per 1000, whatever the payment model was |
| **ROI** | Return On Investment | (Revenue − Cost) ÷ Cost. +100% = you doubled the money |
| **ROAS** | Return On Ad Spend | Revenue ÷ Cost. ROAS 2.0 = ROI +100% |
| **Margin** | — | Profit ÷ Revenue |
| **P/L** | Profit / Loss | Revenue − Cost |
| **LTV** | Lifetime Value | All the money one customer brings over time (important for RevShare) |
| **ARPU** | Average Revenue Per User | Revenue ÷ users |
| **Uniques** | — | Visitors counted once in a period, by IP, IP + User-Agent or cookie |

## Traffic and funnel

| Term | Meaning |
|---|---|
| **Arbitrage, media buying** | Buying traffic and sending it to offers, earning on the difference between cost and payout |
| **Media buyer, webmaster, affiliate, publisher** | The person who brings traffic |
| **Advertiser** | The owner of the product who pays for results |
| **Affiliate network** (CPA network) | The middleman between advertisers and affiliates: Dr.Cash, Leadbit, Everad… → [Offers and affiliate networks](guide-offers-networks.md) |
| **Offer** | A specific product with its terms (GEO, payout, allowed traffic) |
| **Vertical** | The offer category: nutra (health), gambling, betting, dating, finance, crypto, e-commerce, sweepstakes, apps… |
| **GEO** | The country being targeted. **Tier 1/2/3** — rich / middle / developing countries |
| **Traffic source** | Where visitors come from: Facebook, TikTok, Google, push, pop, native, in-app networks |
| **Push, pop, native, in-page** | Ad formats: browser notifications; pop-under windows; ads styled like articles; push-looking banners inside a page |
| **Funnel** | The visitor's path: ad → prelander → landing → offer → conversion |
| **Prelander, landing (LP)** | A page before the offer that warms the visitor up → [Landing pages](guide-landings.md) |
| **Smartlink** | A link that picks the best offer for each visitor automatically |
| **Creative (creo)** | The ad itself: picture, video, text → [Creatives](guide-creatives.md) |
| **UGC** | User-Generated Content — creatives that look filmed by an ordinary person |
| **Ad set** | A group of ads with one audience and budget (Facebook terminology) |
| **Pixel** | A script of the ad platform on the page that reports events back to it |
| **CAPI** | Conversions API — sending conversions to Facebook or TikTok server-to-server instead of (or together with) the pixel → [Facebook / Meta](facebook.md) |
| **Split test, A/B test** | Showing variants to equal shares of traffic and comparing the results |
| **Scaling** | Increasing the budget of what works |

## Tracker terms

| Term | Meaning in Orbitra |
|---|---|
| **Tracker** | The software in the middle: routes visitors, counts clicks and conversions — Orbitra itself |
| **Campaign** | A tracking link with its rules → [First campaign](guide-first-campaign.md) |
| **Stream (flow)** | A rule inside a campaign: filters + where to send → [Streams](guide-streams.md) |
| **Intercepting / Regular / Fallback** | The three stream types and their priority |
| **Rotation** | Distributing traffic between streams, offers or landings by weight |
| **Click ID, subid** | The unique ID of a click, passed to the network and returned in the postback |
| **Sub ID, sub1…sub30** | Extra parameters that travel with a click (`sub_id_1`…`sub_id_30`) |
| **Macro** | A placeholder like `{subid}` or `{{ad.id}}` that is replaced with a real value |
| **Postback, S2S** | Server-to-server notification about a conversion → [Postbacks](guide-postbacks.md) |
| **Token** | A campaign's key for integration scripts and the Click API |
| **Click API** | Keitaro-compatible API for routing a click from your own code (`kclient.php`, `tracking.js`) |
| **Cloaking** | Showing a safe page to bots and moderators and the money page to people → [Cloaking](guide-cloaking.md) |
| **White page, safe page** | The page moderators see |
| **Black page, money page** | The page real visitors see |
| **Moderator, review bot** | Who checks your ad on the platform side |
| **Bot** | Automated traffic: crawlers, scripts, scanners |
| **ASN, ISP** | The network and the provider the visitor's IP belongs to (needs the ASN database) → [Geo databases](guide-geo-databases.md) |
| **Datacenter traffic** | Visits from servers and clouds — almost never a real person |
| **PWA** | An installable web app → [PWA apps](guide-pwa.md) |
| **VAPID** | The keys that sign web push notifications → [Push](guide-push.md) |
| **In-app browser, WebView** | The browser inside Facebook, Instagram, TikTok, Telegram |
| **MCP** | Model Context Protocol — how an AI assistant connects to the tracker → [AI assistant](guide-mcp.md) |
