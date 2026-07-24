# Orbitra MCP Server

An [MCP](https://modelcontextprotocol.io) server that connects the **Orbitra Tracker** to
AI assistants like **Claude Desktop**. Once connected, you can analyse campaigns, read stats
and manage your tracker in plain language:

> "How did my campaigns do in the last 7 days?"
> "Create 10 campaigns for offer #4, one per GEO in US, CA, GB, DE, FR."
> "Add the domain track.example.com and point its root at campaign 12."

It talks to your Orbitra instance over HTTPS using a personal **API key** — nothing is stored
by the server, and read-only keys physically cannot make changes.

---

## 1. Requirements

- **Orbitra 0.9.5.0+** (API-key authentication was added in this version).
- **Node.js 18+** on the machine running your AI assistant (Claude Desktop, etc.).

## 2. Get an API key

In the Orbitra UI: **Users → your user → API Keys → Generate key**.

- Choose **Read** for analytics only, or **Write** to also let the assistant create/edit/delete.
- Copy the key (shown once).

## 3. Install

From your Orbitra install directory:

```bash
cd mcp
npm install
```

(`npm install` is needed once, and again after an update that changes dependencies.)

Quick self-test — boots the server and lists all tools (no live Orbitra needed):

```bash
npm run smoke
```

Test against your live instance:

```bash
ORBITRA_URL=https://tracker.example.com ORBITRA_API_KEY=yourkey npm run smoke -- --ping
```

## 4. Connect to Claude Desktop

Edit Claude Desktop's config file:

- **macOS:** `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows:** `%APPDATA%\Claude\claude_desktop_config.json`

Add an `orbitra` server (use the absolute path to `mcp/src/index.js`):

```json
{
  "mcpServers": {
    "orbitra": {
      "command": "node",
      "args": ["/absolute/path/to/Orbitra/mcp/src/index.js"],
      "env": {
        "ORBITRA_URL": "https://tracker.example.com",
        "ORBITRA_API_KEY": "your-api-key"
      }
    }
  }
}
```

Restart Claude Desktop. You should see the Orbitra tools appear.

> The same config works for any MCP-compatible client — just point it at
> `node /absolute/path/to/mcp/src/index.js` with the two env vars.

## 5. Available tools

**Read**

| Tool | Purpose |
|---|---|
| `orbitra_ping` | Check connectivity + system status |
| `orbitra_get_metrics` | Clicks / conversions / revenue / profit for a period |
| `orbitra_get_chart` | Time-series data for trends |
| `orbitra_list_campaigns` | All campaigns + stats |
| `orbitra_get_campaign` | One campaign incl. streams & postbacks |
| `orbitra_campaign_report` | Detailed campaign breakdown |
| `orbitra_list_offers` / `orbitra_get_offer` / `orbitra_all_offers` | Offers |
| `orbitra_list_domains` | Tracking domains + SSL/DNS status |
| `orbitra_list_traffic_sources` | Traffic sources |
| `orbitra_list_landings` | Landing pages |
| `orbitra_list_affiliate_networks` | Affiliate/CPA networks |
| `orbitra_list_conversions` | Conversion log (filter/paginate) |
| `orbitra_recent_clicks` | Recent raw clicks |
| `orbitra_system_status` | Server resources / DB info |

**Manage** (require a **write** key)

| Tool | Purpose |
|---|---|
| `orbitra_create_campaign` | New campaign (name + alias, optional streams) |
| `orbitra_update_campaign` | Update a campaign; streams preserved unless replaced |
| `orbitra_bulk_create_campaigns` | Create many campaigns at once |
| `orbitra_delete_campaign` / `orbitra_copy_campaign` | Archive / duplicate |
| `orbitra_create_offer` / `orbitra_update_offer` / `orbitra_delete_offer` | Offers |
| `orbitra_create_domain` / `orbitra_delete_domain` / `orbitra_check_domain_dns` | Domains |
| `orbitra_create_traffic_source` / `orbitra_list_traffic_source_templates` | Sources |
| `orbitra_create_landing` | Landing entries |

**Advanced**

| Tool | Purpose |
|---|---|
| `orbitra_api_request` | Call any `api.php` action directly (escape hatch) |

## 6. Security notes

- Treat the API key like a password. Prefer a **read** key unless you need management.
- The server only sends the key as `Authorization: Bearer` / `X-Api-Key` to *your* Orbitra URL.
- Write actions map to normal Orbitra API calls and are subject to the same rules/logging.
- `ORBITRA_INSECURE=1` disables TLS verification — use only for local development.

## Troubleshooting

- **"non-JSON response"** → wrong `ORBITRA_URL`, or it redirected to a login page. Point the URL at the folder containing `api.php`.
- **"Invalid API key"** → key was revoked or copied incorrectly.
- **"read-only (write permission required)"** → generate a **write** key for management tools.
