# Domains and SSL

The **Domains** section. A domain is the host campaign links point to. The panel also works without domains, by the server IP: `http://<ip>/admin.php`.

## Adding

1. **Add domain** — one or several, comma-separated (`http(s)://` is stripped automatically). The list is added in the background, one request per domain; rows appear in the table as they are added, no need to wait for the dialog.
2. Point the domain's A record at the server IP (shown at the top of the page). If the domain's DNS is managed by a connected Cloudflare, Namecheap or Dynadot, the record is written automatically.
3. The tracker checks DNS and issues the certificate automatically.

The **server IP** is detected automatically. If the server is behind NAT or has no outgoing HTTP, set it manually in **Settings → General** — both the DNS check and SSL issuance use it.

## Domain settings

| Field | Meaning |
|---|---|
| **Index page** | The campaign that opens when the domain is visited without an alias |
| **Catch 404** | Nonexistent paths of the domain also open the Index campaign instead of a 404 |
| **Search robots** | Disallow (default: `noindex` and a `robots.txt` with `Disallow: /`) or allow indexing |
| **HTTPS-only** | 301 from HTTP to HTTPS |
| **Admin panel** | "Disallow" — the panel and `api.php` on this domain respond with 404, tracking keeps working. The panel stays available on other domains and by IP |
| **Status** | "Disabled" — the domain answers 404 to everything, including tracking |
| **SSL mode** | Let's Encrypt, Cloudflare, your own certificate, or self-signed. Not selected — Cloudflare for proxied domains, otherwise Let's Encrypt |
| **Cloudflare proxy** | The orange cloud: TLS for the visitor is terminated by Cloudflare; the server still answers over HTTPS on 443 (Orbitra configures this itself) |
| **Manage DNS via** | Which Cloudflare / Namecheap / Dynadot account parks this domain's A record |
| **Group** | For sorting; groups can be renamed right in the list |
| **PWA** | The domain can be bound to a PWA landing — it will serve the app from the root ([PWA and Web Push](pwa-push.md)) |

## SSL

### Let's Encrypt

Certificates are issued by the background worker `cli/ssl_installer.php` (a cron every 5 minutes, installed by the installer) through the root helper `orbitra-issue-cert` with fixed arguments. Issuance uses the `webroot` method; the nginx config is rebuilt from the database. The **Issue SSL** button starts the worker immediately.

The statuses in the SSL column explain what is missing: the domain does not point at the server yet (the certificate will be issued by itself once the A record propagates), certbot is not installed, the web user cannot run the helper via sudo, the certificate is issued but nginx is not serving it yet, the file is missing the intermediate certificate (Chrome will refuse to open — reissue), and so on.

If the panel says the web user cannot run the helper, run this once:

```bash
sudo bash /var/www/orbitra/cli/server_setup.sh
```

Full SSL diagnostics on the server: `sudo bash /var/www/orbitra/cli/ssl_diagnose.sh`.

### Cloudflare

With the Cloudflare proxy, the visitor's certificate is served by Cloudflare, and a self-signed one is installed on the server for the **Full** mode. For **Full (Strict)**, enter the paths to the Cloudflare Origin CA certificate and key in the "Custom certificate" field. The modes are covered in detail in [Cloudflare Full Strict SSL](CLOUDFLARE_FULL_STRICT_SSL.md) (in English).

### Custom certificate

Paths to the certificate and key files on the server. Paths are validated for allowed characters before being written to the nginx config.

### Panel by IP

`https://<ip>/admin.php` works with a self-signed certificate (Let's Encrypt does not issue certificates for IPs). If the nginx config was edited by hand and the panel stopped responding, rebuild it from the database: `sudo php /var/www/orbitra/cli/nginx_sync.php`.

## Registrars and DNS providers

Connected under **⚙ → Integrations**, in the "Domains and SSL" group. Each provider can have several accounts; for each domain you choose which account manages it.

| Provider | What it can do |
|---|---|
| **Cloudflare** | An A record to the server when a domain is added (the zone is looked up by parent: `sub.example.com` → `example.com`), extra A/AAAA records of that name are removed; the zone's SSL mode; proxy; **Import & Auto-DNS** — pull an account's zones into the tracker; rewrite all of an account's A records to a new IP. For details, see [Cloudflare](cloudflare.md) |
| **Namecheap** | Balance, domain import, purchase with parking (contact from Address Book, one-year registration price from the account's price list), A records when a domain is added |
| **Dynadot** | Accounts by API key with balance, domain import, purchase with parking, A records on adding. Domains on custom NS (for example, Cloudflare) are not touched; in Dynadot's DNS zone the remaining records (MX, TXT) are preserved. Requests to one key run sequentially — Dynadot bans parallel ones |

## Backorder

**⚙ → Backorder** — monitoring of domains you want to catch: a list of domains with "free / taken" checks, notes, and TXT/CSV export. Auto-checking works while the page is open; around the clock — via the cron in **Settings → Automation** (every 3 minutes, `backorder_cron.php`).
