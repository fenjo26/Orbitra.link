# AI assistant

A chat built into the panel: ask in plain words and the assistant reads your reports and, on request, works with campaigns, offers, landings, rules and text tests — with the permissions of whoever is chatting. It runs on **your own key** with the provider you choose; nothing goes through any Orbitra service.

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

Examples:

- "How did Germany do this week compared to last week?"
- "Create 10 test campaigns for DE with source PropellerAds"
- "Which offers in campaign 12 spend without sales over 3 days? Switch them off"
- "Add a rule: if campaign 7 spends $100 without conversions in 24 h — pause it"
- "Make 4 headline variants for landing 'GR casino' and start a test"

Each tool step shows as a line in the chat (✓ done, ⚠ error). Changes wait for your **Run** / **Cancel**. Chats are private to each user (history button in the header).

## How it works

- Tools are the same as the remote [MCP connector](mcp.md) (`mcp/tools.json`, `core/McpTools.php`); the assistant calls them with a per-user key named "Orbitra AI assistant" (created automatically, visible in Users → API keys), so the tracker applies that user's role, resource access and campaign scope.
- One HTTP request = one model turn; the chat continues step by step, so a long task never hits the web server's timeout.
- Tool descriptions are shortened and large tool results are cut before they go to the model to keep token use down.
- Requires `proc_open()` for tool calls (as the MCP endpoint does on most hosts) and the PHP curl extension.

Code: `core/AiClient.php` (providers), `core/AiAgent.php` (chat loop), `frontend/src/components/ai/`.
