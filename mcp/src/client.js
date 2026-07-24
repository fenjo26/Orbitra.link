// Orbitra API client used by the MCP server.
// Talks to a running Orbitra instance's api.php using a personal API key.
//
// Configuration (environment variables):
//   ORBITRA_URL      Base URL of the Orbitra install, e.g. https://tracker.example.com
//                    (with or without a trailing slash; /api.php is appended automatically
//                     unless the URL already points at a php file).
//   ORBITRA_API_KEY  API key generated in Orbitra UI (Users -> API Keys).
//                    Use a "write" key for management tools, a "read" key for read-only.
//   ORBITRA_INSECURE If "1", allow self-signed / invalid TLS certificates (dev only).

const RAW_URL = (process.env.ORBITRA_URL || '').trim().replace(/\/+$/, '');
const API_KEY = (process.env.ORBITRA_API_KEY || '').trim();

if (process.env.ORBITRA_INSECURE === '1') {
  process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
}

function apiBase() {
  if (!RAW_URL) {
    throw new Error(
      'ORBITRA_URL is not set. Point it at your Orbitra install, e.g. https://tracker.example.com'
    );
  }
  // If the URL already targets a .php entry point, use it as-is; otherwise append /api.php
  if (/\.php($|\?)/.test(RAW_URL)) return RAW_URL;
  return `${RAW_URL}/api.php`;
}

function assertKey() {
  if (!API_KEY) {
    throw new Error(
      'ORBITRA_API_KEY is not set. Generate one in Orbitra: Users -> (your user) -> API Keys.'
    );
  }
}

function buildUrl(action, params = {}) {
  const url = new URL(apiBase());
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '') continue;
    url.searchParams.set(k, String(v));
  }
  return url.toString();
}

async function parse(res) {
  const text = await res.text();
  let body;
  try {
    body = JSON.parse(text);
  } catch {
    // Non-JSON (HTML error page, redirect to login, etc.)
    throw new Error(
      `Orbitra returned a non-JSON response (HTTP ${res.status}). ` +
        `Check ORBITRA_URL and that the API key is valid. First 200 chars: ${text.slice(0, 200)}`
    );
  }
  if (!res.ok || body?.status === 'error') {
    const msg = body?.message || `HTTP ${res.status}`;
    throw new Error(`Orbitra API error: ${msg}`);
  }
  return body;
}

const headers = () => ({
  Authorization: `Bearer ${API_KEY}`,
  'X-Api-Key': API_KEY,
  Accept: 'application/json',
});

export async function apiGet(action, params = {}) {
  assertKey();
  const res = await fetch(buildUrl(action, params), {
    method: 'GET',
    headers: headers(),
  });
  return parse(res);
}

export async function apiPost(action, payload = {}, params = {}) {
  assertKey();
  const res = await fetch(buildUrl(action, params), {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  return parse(res);
}

export function config() {
  return { url: RAW_URL, hasKey: Boolean(API_KEY), apiBase: RAW_URL ? apiBase() : null };
}
