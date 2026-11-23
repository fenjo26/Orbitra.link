# AI assistant

A chat built into the panel: ask in plain words and the assistant reads your reports and, on request, works with campaigns, streams, offers, landings, rules and text tests — with the permissions of whoever is chatting. It runs on **your own key** with the provider you choose; nothing goes through any Orbitra service.

## Connecting

Click the round button in the bottom-right corner of any page. Without a key the chat opens on the setup step (administrators only; other users see "ask your administrator"):

1. **Provider** — GLM (Zhipu / z.ai), Google Gemini, OpenAI, Anthropic (Claude), DeepSeek, OpenRouter, Ollama (local) or any OpenAI-compatible address.
2. **API key** — the "Where to get a key" link opens the provider's key page.
3. **Model** — the list comes from the provider itself (only chat models; OpenRouter also shows prices). Each provider starts on its economical model, marked "recommended to start": it is enough for reports and everyday tasks and costs several times less. "Own model…" takes any model id.
4. **Save and check** — one tiny test call; a wrong key or model is reported right there.

The same settings live in **Settings → Integrations → AI assistant**, together with the confirmation mode:

- *Ask me before every change* (default) — every create/update/delete waits for a **Run** click in the chat;
- *Ask only before deleting and bulk actions*.

GLM accounts on open.bigmodel.cn use `https://open.bigmodel.cn/api/paas/v4` (Advanced → server address). Ollama must be reachable from the tracker server.

## Using it

The chat greets you in the panel language with your own numbers — today's clicks, conversions and profit, the campaign in the red over 7 days, the best one — and offers ready requests: today's report, what to optimise in the losing campaign, which offers spend without sales, a stop-loss rule, landing headline tests. The greeting is built by the tracker, so it costs no tokens.

The chat also knows which page you have open. With the campaign, landing or offer editor open, the model is told the entity by name, so "this campaign" or "here" needs no search, and the first ready request follows the page: *What to improve in this campaign?*, *Test headlines on this page*, *How is this offer doing?*.

Examples:

- "How did Germany do this week compared to last week?"
- "Create 10 test campaigns for DE with source PropellerAds"
- "Which offers in campaign 12 spend without sales over 3 days? Switch them off"
- "Add a rule: if campaign 7 spends $100 without conversions in 24 h — pause it"
- "Make 4 headline variants for landing 'GR casino' and start a test"
- "Give stream 3 seventy percent of the traffic in campaign 12, the rest to stream 2"
- "Pause campaign 7 — and its ads too"
- "Switch stream 2 off"

Each tool step shows as a line in the chat (✓ done, ⚠ error). Changes wait for your **Run** / **Cancel**, and what waits is shown in plain words rather than as the raw request: a text test lists its variants with a checkbox each (untick the ones you do not want; with none left the test is not created), stream shares and rotation weights come as "was 30% → will be 70%" rows with the streams' names, a stop says whether the ads at the traffic source are paused too, a delete names what it deletes. When several actions wait together, each has a checkbox: leave one out and the rest run, and the assistant is told what did run. Once a change has run, the page behind the chat reloads what it shows: the campaign editor (unless it holds edits of yours that a reload would throw away) and the landing's Text A/B list. Chats are private to each user (history button in the header).

## How it works

- Tools are the same as the remote [MCP connector](mcp.md) (`mcp/tools.json`, `core/McpTools.php`); the assistant calls them with a per-user key named "Orbitra AI assistant" (created automatically, visible in Users → API keys), so the tracker applies that user's role, resource access and campaign scope.
- One HTTP request = one model turn; the chat continues step by step, so a long task never hits the web server's timeout.
- Tool descriptions are shortened and large tool results are cut before they go to the model to keep token use down.
- Requires `proc_open()` for tool calls (as the MCP endpoint does on most hosts) and the PHP curl extension.
- The open page reaches the model as one line of the system prompt. The panel sends only the kind and the id; the tracker looks the name up inside the user's own campaign scope (`core/AiPageContext.php`), so the model is never told about an entity the user may not open.
- Everyday changes have tools of their own — `orbitra_set_stream_weights`, `orbitra_set_stream_active`, `orbitra_set_campaign_state`, `orbitra_set_rotation_weights` — on four api.php actions (`core/CampaignQuickActions.php`) that change only what they are given, refuse what would leave a campaign nothing to serve and write a line to the audit log. `orbitra_update_campaign` patches streams by id: one sent with the id of an existing stream keeps every field it did not carry, and streams that were not sent stay.
- Local landings and local offers can be generated, copied and adapted one static file at a time (`core/LocalPageActions.php`, `orbitra_create_local_landing`, `orbitra_write_landing_file`, `orbitra_create_local_offer`, `orbitra_write_offer_file`, plus file-read tools). PHP files are always blocked, and writing into a landing or offer attached to an active campaign is refused so live traffic is never overwritten — the assistant creates a copy first and offers an isolated HTML preview inside the confirmation card.
- CRM (Anti-Shaving Vault) tools (`orbitra_crm_leads`, `orbitra_crm_lead_evidence`, `orbitra_crm_update_lead`) are exposed only to admins; the assistant can also explain LeadForge workflows and point to the CRM/LeadForge guides.

Code: `core/AiClient.php` (providers), `core/AiAgent.php` (chat loop), `core/AiPageContext.php` (the open page), `core/CampaignQuickActions.php` (everyday actions), `frontend/src/components/ai/`.
