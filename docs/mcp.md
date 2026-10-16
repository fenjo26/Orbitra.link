# MCP server: working with the tracker via AI

Starting with version **0.9.5.0** you can connect an AI assistant (Claude Desktop and other
MCP clients) to Orbitra through the **MCP server** — it lives in the [`mcp/`](../mcp/README.md) folder. Once connected,
the tracker can be driven in plain language:

> "How did my campaigns do over the last 7 days?"
> "Create 10 campaigns for offer #4 — one per GEO: US, CA, GB, DE, FR."
> "Add the domain track.example.com and put campaign 12 on its root."

The server talks to your tracker over HTTPS using a personal **API key**. It stores nothing
on its own side, and read-only keys physically cannot modify data.

## How it works

```
Claude Desktop  ──stdio──►  mcp/src/index.js  ──HTTPS + API key──►  ваш Orbitra (api.php)
```

The MCP server is a thin client over the tracker's regular API (`api.php`). It simply wraps
API actions into tools an AI can understand and adds a safeguard (read keys cannot write).

## Step 1. Get an API key

In the panel: **⚙ → AI / MCP** or **⚙ → Users → API keys** (keys are created by an admin).

- **Read key** — analytics only (metrics, lists, reports).
- **Write key** — plus management (creating/editing/deleting campaigns, offers, domains…).

The key is shown once — copy it.

## Step 2. Install dependencies

When installed via `install.sh`, MCP dependencies are installed automatically. Manually:

```bash
cd mcp
npm install
```

Self-check (starts the server and prints the tool list; no live tracker needed):

```bash
npm run smoke
```

Checking against a live tracker:

```bash
ORBITRA_URL=https://tracker.example.com ORBITRA_API_KEY=your_key npm run smoke -- --ping
```

## Step 3. Connect Claude Desktop

The Claude Desktop config file:

- **macOS:** `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows:** `%APPDATA%\Claude\claude_desktop_config.json`

## Option A. Remote connection by URL (since version 0.9.6.2)

The fastest option, and the only one that works in the browser version of Claude: the tracker itself serves MCP over HTTP through `mcp.php`.

1. **⚙ → Users → API keys**, create a key (Read — analytics only, Write — plus management).
2. Click the link button next to the key — a ready-made URL is copied to the clipboard:

```
https://ваш-трекер.example.com/mcp.php?k=ВАШ_КЛЮЧ
```

3. In Claude: **Settings → Connectors → Add custom connector**, paste the URL, set a name, "Add".

Nothing to install — neither Node nor the repository on your machine.

> 🔑 **The key travels right inside the URL**, because the connector dialog has no field for a key and only supports OAuth. Treat such a URL as a password: do not publish it, and to cut off access delete the key in **⚙ → Users → API keys** — the URL stops working immediately. A read key physically cannot change anything.

**How it works.** `mcp.php` implements sessionless MCP Streamable HTTP: every POST is a self-contained JSON-RPC exchange. It takes the tool list from `mcp/tools.json`, which is generated from the Node server (`node mcp/src/manifest.js`), so both connection types show exactly the same set of 36 tools. A tool call runs as a short CLI process `cli/api_invoke.php` — the tracker does not call itself over HTTP, which on a VPS with two PHP-FPM workers could otherwise block the whole site.

**Requirements:** PHP CLI (already needed for cron) and `proc_open` allowed. If `proc_open` is disabled in `php.ini`, the endpoint will tell you so in plain text — use option B in that case. The PHP path can be set manually via the `mcp_php_binary` setting.

## Option B. Local server for Claude Desktop

> ⚠️ **This is not the "Add custom connector" dialog.** The Connectors section in Claude only accepts remote MCP servers over HTTPS and authorizes them via OAuth — that is why there is no API key field there. The Orbitra server runs over stdio: it starts locally next to the assistant and is registered in the config file below. A tracker URL pasted into the connector dialog will not work.

You can open the file right from the app: **Settings → Developer → Edit Config**.

Add an `orbitra` server (use the absolute path to `mcp/src/index.js`):

```json
{
  "mcpServers": {
    "orbitra": {
      "command": "/usr/local/bin/node",
      "args": ["/absolute/path/to/Orbitra/mcp/src/index.js"],
      "env": {
        "ORBITRA_URL": "https://tracker.example.com",
        "ORBITRA_API_KEY": "ваш-api-ключ"
      }
    }
  }
}
```

Fully restart Claude Desktop (⌘Q on macOS) — the Orbitra tools will appear in the list. Closing the window is not enough; the config is read only at startup.

**If the server does not appear:**

- **Node path.** `command` needs an absolute path: the app does not start from your shell, so Node from nvm or Homebrew never reaches its `PATH` and the server silently fails to start. Find the path with `which node` (macOS/Linux) or `where node` (Windows).
- **Dependencies not installed.** `cd mcp && npm install`.
- **Wrong key or URL.** Verify separately: `ORBITRA_URL=... ORBITRA_API_KEY=... npm run smoke -- --ping`.
- **`403 API key is read-only`** on create or update — you need a Write key; a Read key only returns analytics.

## Tools (36)

**Read:** `orbitra_ping`, `orbitra_get_metrics`, `orbitra_get_chart`, `orbitra_list_campaigns`,
`orbitra_campaigns_stats`, `orbitra_get_campaign`, `orbitra_campaign_report`, `orbitra_list_offers`, `orbitra_get_offer`,
`orbitra_all_offers`, `orbitra_list_domains`, `orbitra_ssl_environment`, `orbitra_list_traffic_sources`,
`orbitra_list_landings`, `orbitra_list_affiliate_networks`, `orbitra_list_conversions`,
`orbitra_recent_clicks`, `orbitra_system_status`.

**Management (requires a write key):** `orbitra_create_campaign`, `orbitra_update_campaign`,
`orbitra_bulk_create_campaigns`, `orbitra_bulk_set_campaign_domain`, `orbitra_delete_campaign`, `orbitra_copy_campaign`,
`orbitra_create_offer`, `orbitra_update_offer`, `orbitra_delete_offer`, `orbitra_create_domain`,
`orbitra_delete_domain`, `orbitra_check_domain_dns`, `orbitra_reissue_ssl`, `orbitra_run_ssl_worker`,
`orbitra_create_traffic_source`,
`orbitra_list_traffic_source_templates`, `orbitra_create_landing`.

**Advanced:** `orbitra_api_request` — call any `api.php` action directly (the fallback option).

**Large accounts.** `orbitra_list_campaigns` and `orbitra_list_domains` return compact lists (id, name, alias, domain, link) with ID-range and domain filters and pagination — the response stays small even with thousands of campaigns. Stats for all campaigns come from `orbitra_campaigns_stats` (a heavy response), for a single one — `orbitra_campaign_report`. To change the domain of many campaigns at once — `orbitra_bulk_set_campaign_domain`: only the domain changes, streams and postbacks are untouched, and the response carries each campaign's old and new link. `orbitra_update_campaign` preserves stream IDs, their names, filter logic, offer selection and Bot Challenge.

A full parameter reference lives in [mcp/README.md](../mcp/README.md).

## Security

- Treat the API key like a password. Use a **read** key by default; **write** only
  when management is needed.
- The key goes only to **your** `ORBITRA_URL` (`Authorization: Bearer` / `X-Api-Key` headers).
- Write actions go through the regular API and land in the audit logs like any other operation.
- A read key gets a `403` on write attempts and changes nothing.
- `ORBITRA_INSECURE=1` disables TLS verification — for local development only.

## Updates

MCP ships with the project, so the regular update (**⚙ → Update** or
`git pull`) pulls the new files automatically. After an update that changed dependencies,
run `cd mcp && npm install`. If the tool list changed in the new version,
reconnect the connector in the client so it re-reads the tools.
