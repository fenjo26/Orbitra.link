const DEFAULT_SETTINGS = {
  trackerUrl: '',
  apiKey: '',
  template: 'cpa',
  pollingInterval: 30
};

const responseCache = new Map();
const CACHE_TTL_MS = 25 * 1000;

chrome.runtime.onInstalled.addListener(async () => {
  const current = await chrome.storage.sync.get(DEFAULT_SETTINGS);
  await chrome.storage.sync.set(current);
});

function statsEndpoint(trackerUrl) {
  const parsed = new URL(trackerUrl);
  parsed.hash = '';
  if (!/\/api\.php\/?$/i.test(parsed.pathname)) {
    parsed.pathname = `${parsed.pathname.replace(/\/$/, '')}/api.php`;
  }
  parsed.search = '';
  parsed.searchParams.set('action', 'extension_ads_stats');
  return parsed;
}

function deepStatsEndpoint(trackerUrl) {
  const endpoint = statsEndpoint(trackerUrl);
  endpoint.searchParams.set('action', 'extension_deep_stats');
  return endpoint;
}

function normalizedIds(value) {
  return [...new Set((Array.isArray(value) ? value : [])
    .map(id => String(id).trim())
    .filter(id => /^\d{1,32}$/.test(id)))]
    .sort()
    .slice(0, 500);
}

function cacheKey(payload, endpoint) {
  return JSON.stringify([
    endpoint,
    payload.date,
    payload.campaign_ids,
    payload.adset_ids,
    payload.ad_ids
  ]);
}

async function requestStats(message, force = false) {
  const settings = await chrome.storage.sync.get(DEFAULT_SETTINGS);
  if (!settings.trackerUrl || !settings.apiKey) {
    throw new Error('Open the Orbitra extension popup and save the Tracker URL and API key.');
  }

  let endpoint;
  try {
    endpoint = statsEndpoint(settings.trackerUrl);
  } catch (_error) {
    throw new Error('Tracker URL must be a valid http:// or https:// address.');
  }

  const payload = {
    date: message.date || 'today',
    campaign_ids: normalizedIds(message.campaignIds).join(','),
    adset_ids: normalizedIds(message.adsetIds).join(','),
    ad_ids: normalizedIds(message.adIds).join(',')
  };
  const key = cacheKey(payload, endpoint);
  const cached = responseCache.get(key);
  if (!force && cached && Date.now() - cached.createdAt < CACHE_TTL_MS) {
    return { data: cached.data, template: settings.template, cached: true };
  }

  const response = await fetch(endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Api-Key': settings.apiKey
    },
    body: JSON.stringify(payload),
    cache: 'no-store'
  });

  let body;
  try {
    body = await response.json();
  } catch (_error) {
    throw new Error(`Orbitra returned HTTP ${response.status} instead of JSON.`);
  }
  if (!response.ok || body.status !== 'success') {
    throw new Error(body.message || `Orbitra request failed with HTTP ${response.status}.`);
  }

  responseCache.set(key, { data: body.data || {}, createdAt: Date.now() });
  if (responseCache.size > 30) {
    const oldest = responseCache.keys().next().value;
    responseCache.delete(oldest);
  }
  return { data: body.data || {}, template: settings.template, cached: false };
}

// Deep stats for the detail modal (multi-day fusion, daily history,
// landings/offers, CAPI accuracy). Same auth and cache policy as the row
// pills, cached a bit longer: the modal is opened deliberately, not on every
// table re-render.
async function requestDeepStats(entities) {
  const settings = await chrome.storage.sync.get(DEFAULT_SETTINGS);
  if (!settings.trackerUrl || !settings.apiKey) {
    throw new Error('Open the Orbitra extension popup and save the Tracker URL and API key.');
  }
  const valid = (Array.isArray(entities) ? entities : [])
    .filter(e => e && ['ad', 'adset', 'campaign'].includes(e.type) && /^\d{1,32}$/.test(String(e.id)))
    .slice(0, 50);
  if (!valid.length) {
    throw new Error('No valid entities requested.');
  }
  const dateTo = new Date();
  const dateFrom = new Date(Date.now() - 2 * 86400000); // last 3 days, popup-configurable later
  const fmt = (d) => d.toISOString().slice(0, 10);
  const payload = {
    date_from: fmt(dateFrom),
    date_to: fmt(dateTo),
    entities: valid
  };
  const key = 'deep:' + JSON.stringify(payload);
  const cached = responseCache.get(key);
  if (cached && Date.now() - cached.createdAt < 60 * 1000) {
    return cached.data;
  }
  const response = await fetch(deepStatsEndpoint(settings.trackerUrl), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Api-Key': settings.apiKey
    },
    body: JSON.stringify(payload),
    cache: 'no-store'
  });
  let body;
  try {
    body = await response.json();
  } catch (_error) {
    throw new Error(`Orbitra returned HTTP ${response.status} instead of JSON.`);
  }
  if (!response.ok || body.status !== 'success') {
    throw new Error(body.message || `Orbitra request failed with HTTP ${response.status}.`);
  }
  const data = body.data || {};
  responseCache.set(key, { data, createdAt: Date.now() });
  return data;
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {  if (!message || !['ORBITRA_GET_STATS', 'ORBITRA_TEST_CONNECTION', 'ORBITRA_DEEP_STATS'].includes(message.type)) {
    return false;
  }

  if (message.type === 'ORBITRA_DEEP_STATS') {
    requestDeepStats(message.entities || [])
      .then(data => sendResponse({ ok: true, data }))
      .catch(error => sendResponse({ ok: false, error: error.message || String(error) }));
    return true;
  }

  const request = message.type === 'ORBITRA_TEST_CONNECTION'
    ? {
        type: message.type,
        date: 'today',
        campaignIds: ['0'],
        adsetIds: ['0'],
        adIds: ['0']
      }
    : message;

  requestStats(request, message.type === 'ORBITRA_TEST_CONNECTION' || message.force === true)
    .then(result => sendResponse({ ok: true, ...result }))
    .catch(error => sendResponse({ ok: false, error: error.message || String(error) }));
  return true;
});
