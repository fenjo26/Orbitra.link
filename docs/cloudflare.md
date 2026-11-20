# Cloudflare — Domain DNS and SSL

The integration connects the tracker to Cloudflare via API: domains you park in Orbitra get an A record pointing at the tracker server **automatically**, and with the proxy enabled (orange cloud) SSL is served by the Cloudflare edge — no certbot and no waiting for validation.

Configuration: **⚙ → Integrations → Cloudflare** (the "Domains and SSL" group). The big picture on domains — [Domains and SSL](domains.md).

## 1. API token

1. Cloudflare → **My Profile → API Tokens → Create Token**.
2. The **"Edit zone DNS"** template → *Use template*.
3. **Add more** → add **Zone · Zone · Edit** (needed to change the zone's SSL mode; DNS works without it too).
4. **Zone Resources: Include → All zones**.
5. Continue to summary → **Create Token** → copy the token.

## 2. Accounts

There can be several Cloudflare accounts (for example, one per buyer). For each: a name, the token (validated on save), the number of zones.

- **Server IP** — what the A records point to. Detected automatically by default.
- **Zone SSL mode** — `Flexible` (SSL right away, Cloudflare reaches the server over HTTP), `Full` (the server needs a certificate — Orbitra installs a self-signed one), `Strict` (a trusted certificate is required, see [Cloudflare Full Strict SSL](CLOUDFLARE_FULL_STRICT_SSL.md)).
- **Proxy** — the orange cloud on by default for new domains.

In the domain's **"Manage DNS via"** field you pick the account; empty means the default account. A token saved before multi-account support appeared became account #1.

## 3. How it works

- **Parking a domain.** When a domain is added (including a subdomain), the tracker looks up the zone in the account — `sub.example.com` will find the zone `example.com` — and creates or updates a single A record to the server IP. Extra A/AAAA records of the same name are deleted.
- **"Create the Cloudflare zone & set its name servers at the registrar"** (a checkbox in the add form, also `cloudflare_create_zone` on the MCP `orbitra_create_domain` tool). When the zone is in none of the connected accounts, the tracker creates it for the domain's **registered** zone (the Namecheap/Dynadot account holding the domain knows the registrable name), reveals the name servers Cloudflare assigned, and sets them at that registrar (`namecheap.domains.dns.setCustom` / Dynadot `set_ns`). The domain no registrar holds still gets its zone — the response carries the NS pair to set by hand. Zone creation needs the token permission **Zone · Zone · Edit** (step 3 of the token recipe above); without it Cloudflare's refusal lands in the save warnings. Switching name servers replaces the domain's whole DNS, so the checkbox is opt-in and add-only — a plain re-save never touches NS.
- **The zone is not in the account and the checkbox is off** — the domain works as usual: DNS managed manually, a Let's Encrypt certificate.
- **Import & Auto-DNS** — pull the account's zones and park the selected ones into the tracker in one action. Already-added ones are marked "already in the tracker".
- **Moving to a new server** — change the server IP and press **"Rewrite A records"** on the account (or **"Re-park all domains"**).
- **Proxied domains** do not enter the certbot queue; in the domain list their SSL reads "Cloudflare".

## 4. Which calls the tracker makes

`/user/tokens/verify`, `/zones`, `/zones/{id}/dns_records` (GET / POST / PUT / DELETE), `/zones/{id}/settings/ssl`, `/accounts`, `POST /zones` (zone provisioning). Accounts are stored in the `cloudflare_accounts` table; domains carry `dns_account_id`.
