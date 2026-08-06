#!/usr/bin/env node
// Orbitra MCP server
// Exposes the Orbitra Tracker to MCP-compatible AI assistants (Claude Desktop, etc.).
// Read tools work with any API key; management (write) tools require a "write"-scoped key.

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import { apiGet, apiPost, config } from './client.js';

const server = new McpServer({ name: 'orbitra-mcp', version: '0.9.6.0' });

// ---- helpers ---------------------------------------------------------------

const asText = (data) => ({
  content: [{ type: 'text', text: typeof data === 'string' ? data : JSON.stringify(data, null, 2) }],
});

const asError = (err) => ({
  isError: true,
  content: [{ type: 'text', text: `Error: ${err?.message || String(err)}` }],
});

// Wrap a handler so thrown errors become MCP tool errors instead of crashing the process.
const tool = (name, description, shape, handler) =>
  server.tool(name, description, shape, async (args) => {
    try {
      return await handler(args || {});
    } catch (err) {
      return asError(err);
    }
  });

const DATE_RANGES = [
  'today',
  'yesterday',
  'this_week',
  'last_7_days',
  'this_month',
  'last_30_days',
  'custom',
  'all',
];

// Map a DB stream row (from get_campaign) back into the shape save_campaign expects.
function normalizeStreamForSave(s) {
  const parse = (v, fallback) => {
    if (v === undefined || v === null || v === '') return fallback;
    if (typeof v === 'object') return v;
    try {
      return JSON.parse(v);
    } catch {
      return fallback;
    }
  };
  return {
    offer_id: s.offer_id ?? null,
    weight: s.weight ?? 100,
    is_active: s.is_active ?? 1,
    type: s.type ?? 'regular',
    position: s.position ?? 0,
    filters: s.filters ?? parse(s.filters_json, []),
    schema_type: s.schema_type ?? 'redirect',
    action_payload: s.action_payload ?? '',
    schema_custom: s.schema_custom ?? parse(s.schema_custom_json, []),
  };
}

// =============================================================================
// READ TOOLS
// =============================================================================

tool(
  'orbitra_ping',
  'Verify connectivity and credentials. Returns the configured Orbitra URL and live system status (DB size, click/conversion counts, resource usage).',
  {},
  async () => {
    const cfg = config();
    if (!cfg.url) throw new Error('ORBITRA_URL is not configured.');
    const status = await apiGet('system_status');
    return asText({ config: cfg, system_status: status.data ?? status });
  }
);

tool(
  'orbitra_get_metrics',
  'Aggregated dashboard metrics (clicks, unique clicks, conversions, revenue, cost, profit) for a period. Use for "how are my campaigns doing" style questions.',
  {
    date_range: z.enum(DATE_RANGES).default('today').describe('Reporting period.'),
    custom_from: z.string().optional().describe('YYYY-MM-DD, required when date_range=custom.'),
    custom_to: z.string().optional().describe('YYYY-MM-DD, required when date_range=custom.'),
    campaign_id: z.number().int().optional().describe('Limit metrics to one campaign.'),
  },
  async (a) => asText(await apiGet('metrics', a))
);

tool(
  'orbitra_get_chart',
  'Time-series chart data (clicks and conversions per day/hour) for building trend views.',
  {
    date_range: z.enum(DATE_RANGES).default('last_7_days'),
    custom_from: z.string().optional(),
    custom_to: z.string().optional(),
    campaign_id: z.number().int().optional(),
  },
  async (a) => asText(await apiGet('chart', a))
);

tool(
  'orbitra_list_campaigns',
  'List all campaigns with their traffic stats (clicks, unique clicks, conversions). The primary way to see what campaigns exist and their IDs/aliases/tokens.',
  { limit: z.number().int().optional().describe('Only return campaigns with clicks > 0, capped to N.') },
  async (a) => asText(await apiGet('campaigns', a))
);

tool(
  'orbitra_get_campaign',
  'Full details of one campaign including its streams (rotation/filters/offers) and postbacks.',
  { id: z.number().int().describe('Campaign ID.') },
  async (a) => asText(await apiGet('get_campaign', { id: a.id }))
);

tool(
  'orbitra_campaign_report',
  'Detailed breakdown report for a campaign (by stream/offer/geo etc.).',
  { campaign_id: z.number().int(), limit: z.number().int().optional() },
  async (a) => asText(await apiGet('campaign_report', a))
);

tool(
  'orbitra_list_offers',
  'List offers with basic stats.',
  {},
  async () => asText(await apiGet('offers'))
);

tool(
  'orbitra_all_offers',
  'Compact list of active offers (id + name), handy for picking an offer_id when building streams.',
  {},
  async () => asText(await apiGet('all_offers'))
);

tool(
  'orbitra_get_offer',
  'Full details of one offer.',
  { id: z.number().int() },
  async (a) => asText(await apiGet('get_offer', { id: a.id }))
);

tool(
  'orbitra_list_domains',
  'List tracking domains (with SSL/DNS status).',
  {},
  async () => asText(await apiGet('domains'))
);

tool(
  'orbitra_list_traffic_sources',
  'List traffic sources (Facebook, TikTok, Google Ads, etc.) with their postback config.',
  {},
  async () => asText(await apiGet('traffic_sources'))
);

tool(
  'orbitra_list_landings',
  'List landing pages.',
  {},
  async () => asText(await apiGet('landings'))
);

tool(
  'orbitra_list_affiliate_networks',
  'List affiliate/CPA networks.',
  {},
  async () => asText(await apiGet('affiliate_networks'))
);

tool(
  'orbitra_list_conversions',
  'Conversion log with filters and pagination.',
  {
    page: z.number().int().optional().default(1),
    per_page: z.number().int().optional().default(50),
    status: z.string().optional().describe('Filter by status: lead, sale, rejected, trash, ...'),
    campaign_id: z.number().int().optional(),
  },
  async (a) => asText(await apiGet('conversions', a))
);

tool(
  'orbitra_recent_clicks',
  'Recent raw clicks (the "Recent Clicks" table).',
  { limit: z.number().int().optional().default(50) },
  async (a) => asText(await apiGet('logs', a))
);

tool(
  'orbitra_system_status',
  'Server/system status: DB size, disk, memory, PHP/SQLite info, geo DB status.',
  {},
  async () => asText(await apiGet('system_status'))
);

// =============================================================================
// MANAGEMENT TOOLS (require a write-scoped API key)
// =============================================================================

const streamSchema = z
  .object({
    offer_id: z.number().int().nullable().optional(),
    weight: z.number().int().optional(),
    is_active: z.union([z.number().int(), z.boolean()]).optional(),
    type: z.string().optional().describe("e.g. 'regular'."),
    position: z.number().int().optional(),
    filters: z.array(z.any()).optional(),
    schema_type: z.string().optional().describe("e.g. 'redirect'."),
    action_payload: z.string().optional(),
    schema_custom: z.array(z.any()).optional(),
  })
  .passthrough();

const campaignFields = {
  name: z.string().describe('Display name.'),
  alias: z.string().describe('URL alias (unique).'),
  domain_id: z.number().int().nullable().optional(),
  group_id: z.number().int().nullable().optional(),
  source_id: z.number().int().nullable().optional().describe('Traffic source ID.'),
  cost_model: z.enum(['CPC', 'CPM', 'CPA', 'RevShare']).optional().describe("Default 'CPC'."),
  cost_value: z.number().optional(),
  uniqueness_method: z.string().optional().describe("Default 'IP'."),
  uniqueness_hours: z.number().int().optional().describe('Default 24.'),
  rotation_type: z.enum(['position', 'weight']).optional().describe("Default 'position'."),
  catch_404_stream_id: z.number().int().nullable().optional(),
  streams: z.array(streamSchema).optional().describe('Rotation streams (offers + filters).'),
  postbacks: z.array(z.any()).optional(),
};

tool(
  'orbitra_create_campaign',
  'Create a new campaign. Requires name and alias. Returns the new id and Click API token. Optionally pass streams (offers/rotation).',
  campaignFields,
  async (a) => {
    const res = await apiPost('save_campaign', a);
    return asText(res);
  }
);

tool(
  'orbitra_update_campaign',
  'Update an existing campaign. Only the fields you pass are changed. Streams/postbacks are preserved automatically unless you explicitly pass new ones (Orbitra replaces streams wholesale on save, so this tool re-reads and merges to avoid wiping them).',
  {
    id: z.number().int().describe('Campaign ID to update.'),
    name: z.string().optional(),
    alias: z.string().optional(),
    domain_id: z.number().int().nullable().optional(),
    group_id: z.number().int().nullable().optional(),
    source_id: z.number().int().nullable().optional(),
    cost_model: z.enum(['CPC', 'CPM', 'CPA', 'RevShare']).optional(),
    cost_value: z.number().optional(),
    uniqueness_method: z.string().optional(),
    uniqueness_hours: z.number().int().optional(),
    rotation_type: z.enum(['position', 'weight']).optional(),
    catch_404_stream_id: z.number().int().nullable().optional(),
    streams: z.array(streamSchema).optional().describe('Replace streams. Omit to keep existing.'),
    postbacks: z.array(z.any()).optional().describe('Replace postbacks. Omit to keep existing.'),
  },
  async (a) => {
    const current = (await apiGet('get_campaign', { id: a.id })).data;
    if (!current) throw new Error(`Campaign ${a.id} not found.`);

    const payload = {
      id: a.id,
      name: a.name ?? current.name,
      alias: a.alias ?? current.alias,
      domain_id: a.domain_id !== undefined ? a.domain_id : current.domain_id,
      group_id: a.group_id !== undefined ? a.group_id : current.group_id,
      source_id: a.source_id !== undefined ? a.source_id : current.source_id,
      cost_model: a.cost_model ?? current.cost_model,
      cost_value: a.cost_value !== undefined ? a.cost_value : current.cost_value,
      uniqueness_method: a.uniqueness_method ?? current.uniqueness_method,
      uniqueness_hours: a.uniqueness_hours ?? current.uniqueness_hours,
      rotation_type: a.rotation_type ?? current.rotation_type,
      catch_404_stream_id:
        a.catch_404_stream_id !== undefined ? a.catch_404_stream_id : current.catch_404_stream_id,
      streams: a.streams ?? (current.streams || []).map(normalizeStreamForSave),
      postbacks:
        a.postbacks ??
        (current.postbacks || []).map((p) => ({
          url: p.url,
          method: p.method || 'GET',
          statuses: p.statuses || 'lead,sale,rejected',
        })),
    };
    return asText(await apiPost('save_campaign', payload));
  }
);

tool(
  'orbitra_bulk_create_campaigns',
  'Create many campaigns in one call. Pass an array; each item needs at least name and alias. Returns a per-item result with new ids/tokens or errors.',
  {
    campaigns: z
      .array(z.object(campaignFields).passthrough())
      .min(1)
      .describe('Array of campaign definitions.'),
  },
  async (a) => {
    const results = [];
    for (const c of a.campaigns) {
      try {
        const res = await apiPost('save_campaign', c);
        results.push({ name: c.name, alias: c.alias, ok: true, data: res.data ?? res });
      } catch (err) {
        results.push({ name: c.name, alias: c.alias, ok: false, error: err.message });
      }
    }
    const created = results.filter((r) => r.ok).length;
    return asText({ requested: a.campaigns.length, created, failed: results.length - created, results });
  }
);

tool(
  'orbitra_delete_campaign',
  'Soft-delete a campaign (moves it to the archive).',
  { id: z.number().int() },
  async (a) => asText(await apiPost('delete_campaign', { id: a.id }))
);

tool(
  'orbitra_copy_campaign',
  'Duplicate an existing campaign (with its streams).',
  { id: z.number().int() },
  async (a) => asText(await apiPost('copy_campaign', { id: a.id }))
);

// ---- Offers ----------------------------------------------------------------

const offerFields = {
  name: z.string(),
  url: z.string().optional().describe('Offer/destination URL.'),
  affiliate_network_id: z.number().int().nullable().optional(),
  group_id: z.number().int().nullable().optional(),
  redirect_type: z.string().optional().describe("How the visitor is sent to the offer. One of: 'redirect' (HTTP 302, default), 'js' (client-side window.location), 'meta_refresh' (meta refresh tag), 'frame'/'iframe' (rendered in a full-page iframe), 'form_submit' (auto-submitted POST form), 'preload'/'curl_proxy' (remote page served through this server)."),
  is_local: z.boolean().optional(),
  geo: z.string().optional().describe('Comma-separated country codes, e.g. "US,CA".'),
  payout_type: z.enum(['cpa', 'cpl', 'crg', 'revshare']).optional().describe("Default 'cpa'."),
  payout_value: z.number().optional(),
  payout_auto: z.boolean().optional(),
  allow_rebills: z.boolean().optional(),
  capping_limit: z.number().int().optional(),
  capping_timezone: z.string().optional(),
  alt_offer_id: z.number().int().nullable().optional(),
  notes: z.string().optional(),
  state: z.enum(['active', 'paused', 'archived']).optional(),
};

tool(
  'orbitra_create_offer',
  'Create a new offer.',
  offerFields,
  async (a) => asText(await apiPost('save_offer', a))
);

tool(
  'orbitra_update_offer',
  'Update an existing offer. Only fields you pass are changed (re-reads the offer and merges).',
  { id: z.number().int(), ...Object.fromEntries(Object.entries(offerFields).map(([k, v]) => [k, v.optional?.() ?? v])) },
  async (a) => {
    const current = (await apiGet('get_offer', { id: a.id })).data;
    if (!current) throw new Error(`Offer ${a.id} not found.`);
    const payload = { ...current, ...a };
    return asText(await apiPost('save_offer', payload));
  }
);

tool(
  'orbitra_delete_offer',
  'Delete an offer.',
  { id: z.number().int() },
  async (a) => asText(await apiPost('delete_offer', { id: a.id }))
);

// ---- Domains ---------------------------------------------------------------

tool(
  'orbitra_create_domain',
  'Add a tracking domain. After DNS points to the server you can trigger SSL separately in the UI.',
  {
    name: z.string().describe('Domain name, e.g. track.example.com.'),
    group_id: z.number().int().nullable().optional(),
    index_campaign_id: z.number().int().nullable().optional().describe('Campaign to serve on the root path.'),
    catch_404: z.boolean().optional(),
    is_noindex: z.boolean().optional(),
    https_only: z.boolean().optional(),
  },
  async (a) => asText(await apiPost('save_domain', a))
);

tool(
  'orbitra_delete_domain',
  'Delete a tracking domain.',
  { id: z.number().int() },
  async (a) => asText(await apiPost('delete_domain', { id: a.id }))
);

tool(
  'orbitra_check_domain_dns',
  'Check DNS resolution for a domain (does it point at this server yet).',
  { id: z.number().int() },
  async (a) => asText(await apiGet('check_domain_dns', { id: a.id }))
);

// ---- Traffic sources -------------------------------------------------------

tool(
  'orbitra_create_traffic_source',
  'Create a traffic source. Use orbitra_list_traffic_source_templates first to grab a template + parameters for known platforms.',
  {
    name: z.string(),
    template: z.string().optional().describe('Template key, e.g. facebook, tiktok, google_ads.'),
    postback_url: z.string().optional(),
    postback_statuses: z.string().optional().describe("Default 'lead,sale'."),
    parameters_json: z.any().optional().describe('Token/param mapping object or JSON string.'),
    notes: z.string().optional(),
    state: z.enum(['active', 'paused']).optional(),
  },
  async (a) => asText(await apiPost('traffic_sources', a))
);

tool(
  'orbitra_list_traffic_source_templates',
  'List built-in traffic source templates (Facebook, TikTok, Google Ads, ...) with their default parameters.',
  {},
  async () => asText(await apiGet('traffic_source_templates'))
);

// ---- Landings --------------------------------------------------------------

tool(
  'orbitra_create_landing',
  'Create a landing page entry (external URL). For local ZIP landings use the Orbitra UI upload.',
  {
    name: z.string(),
    url: z.string().optional(),
    group_id: z.number().int().nullable().optional(),
    type: z.string().optional().describe("e.g. 'external'."),
    action_payload: z.string().optional(),
    state: z.enum(['active', 'paused']).optional(),
  },
  async (a) => asText(await apiPost('save_landing', a))
);

// =============================================================================
// ADVANCED ESCAPE HATCH
// =============================================================================

tool(
  'orbitra_api_request',
  'Advanced: call any Orbitra api.php endpoint directly. Use when no dedicated tool exists. method=GET for reads, POST for writes (needs a write key). See docs/api.md for actions.',
  {
    method: z.enum(['GET', 'POST']).default('GET'),
    action: z.string().describe('The api.php action, e.g. "trends", "settings".'),
    params: z.record(z.any()).optional().describe('Query-string params (for GET or extra POST params).'),
    body: z.record(z.any()).optional().describe('JSON body for POST.'),
  },
  async (a) => {
    if (a.method === 'POST') {
      return asText(await apiPost(a.action, a.body || {}, a.params || {}));
    }
    return asText(await apiGet(a.action, a.params || {}));
  }
);

// ---- boot ------------------------------------------------------------------

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  // stderr is safe for logging (stdout is the MCP transport).
  console.error('[orbitra-mcp] ready. URL:', config().url || '(ORBITRA_URL not set)');
}

main().catch((err) => {
  console.error('[orbitra-mcp] fatal:', err);
  process.exit(1);
});
