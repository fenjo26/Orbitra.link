# Quick Start: Your First Campaign

From a bare server to your first conversion. Each step links to a detailed page.

## 1. Installation

On a clean Ubuntu 22.04+ or Debian 12+ as root:

```bash
wget -O orbitra-install.sh https://raw.githubusercontent.com/fenjo26/Orbitra.link/main/install.sh && bash orbitra-install.sh
```

For details and requirements, see [Installation and updates](deployment.md).

## 2. First login

Open `http://<server-ip>/admin.php`. There is no default user: the first-run wizard will ask you to create an administrator (login of at least 3 characters, password of at least 8), choose a time zone and a language.

Worth doing right away:

- enable two-factor login (**⚙ → Users → Profile**);
- optionally, change the admin panel path and restrict access by IP ([Administration](administration.md)).

## 3. Domain

**Domains → Add domain**, then point the domain's A record at the server IP. The certificate is issued automatically once DNS propagates. With Cloudflare, Namecheap, or Dynadot connected, the A record is also set automatically. → [Domains and SSL](domains.md)

After that, it is more convenient to open the panel at `https://your-domain/admin.php`: Google and TikTok sign-in and the Telegram webhook only work over HTTPS.

## 4. Affiliate network and offer

1. **Affiliate networks → Add**, pick a network from the templates. The template provides the Postback URL for the network's dashboard and the offer parameters (for example `&subid={subid}`).
2. Copy the Postback URL into the network's dashboard as the global postback.
3. **Offers → Create**: a name, the URL from the network's dashboard, the affiliate network. The network's parameters are appended to the URL automatically at click time.

A local offer (your own landing with a form) is uploaded as a ZIP archive and edited in the built-in file manager. → [Traffic sources and affiliate networks](sources-and-networks.md), [Landing pages](landing-pages.md)

## 5. Traffic source

**Sources → Add**, pick an ad-network template (Facebook, TikTok, Google Ads…). The template adds macros with the campaign, group, and ad IDs to the campaign URL — they are needed for cost import and statistics breakdown.

## 6. Campaign

**Campaigns → Create**:

1. **General:** name, domain, traffic source.
2. **Streams:** add a regular stream with the "Direct redirect to offer" schema and pick an offer. Need country or device filters — add them to the stream; need a landing — use the "Landing + Offer" schema.
3. Save. Put the campaign URL (`https://your-domain/alias`) into the ad.

→ [Campaigns and streams](campaigns.md), [Cloaking](cloaking.md)

## 7. Verification

- The **Test link** row on the "General" tab with `&_dbg=1` shows which stream will fire and where the visitor will go. No click is recorded. `&_geo=DE` tests a different country without a VPN.
- **⚙ → Postback URL → Test** will send a sample conversion against a real click ID.
- The tester on the **S2S Postbacks** tab will verify the whole postback chain to the source.

## 8. Costs and notifications

- Connect an ad account under **⚙ → Integrations** (Facebook, TikTok, Google Ads) so ROI is calculated from real spend. → [Costs](costs.md)
- Connect the Telegram bot for conversion notifications. → [Telegram bot](telegram.md)

## 9. Statistics

Dashboard, campaign list, per-campaign report with groupings, and the full click log. → [Reports, logs, and metrics](reports.md)

Moving from Keitaro? → [Migrating from Keitaro](keitaro-migration.md)
