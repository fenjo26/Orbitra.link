(() => {
  if (!location.pathname.toLowerCase().includes('adsmanager')) return;

  const OVERLAY_ATTR = 'data-orbitra-overlay';
  const DEFAULT_SETTINGS = { pollingInterval: 30 };
  const levelKeys = {
    campaign: {
      attributes: ['data-campaign-id', 'data-campaign_id'],
      params: ['campaign_id', 'campaign_ids', 'selected_campaign_ids']
    },
    adset: {
      attributes: ['data-adset-id', 'data-adset_id', 'data-ad-set-id'],
      params: ['adset_id', 'adset_ids', 'selected_adset_ids']
    },
    ad: {
      attributes: ['data-ad-id', 'data-ad_id'],
      params: ['ad_id', 'ad_ids', 'selected_ad_ids']
    }
  };

  let refreshTimer = null;
  let pollTimer = null;
  let lastUrl = location.href;
  let requestSerial = 0;

  function installStyles() {
    if (document.getElementById('orbitra-overlay-styles')) return;
    const style = document.createElement('style');
    style.id = 'orbitra-overlay-styles';
    style.textContent = `
      [${OVERLAY_ATTR}] {
        display: inline-flex !important;
        align-items: center;
        flex-wrap: wrap;
        gap: 4px;
        max-width: 100%;
        margin: 4px 0 2px 6px;
        padding: 4px 6px;
        border: 1px solid rgba(139, 92, 246, .42);
        border-radius: 7px;
        background: linear-gradient(135deg, #151322 0%, #211a38 100%);
        color: #f8fafc;
        box-shadow: 0 3px 12px rgba(15, 23, 42, .22);
        font: 600 10px/1.25 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        vertical-align: middle;
      }
      [${OVERLAY_ATTR}] .orbitra-brand { color: #c4b5fd; letter-spacing: .02em; }
      [${OVERLAY_ATTR}] .orbitra-metric { white-space: nowrap; color: #e2e8f0; }
      [${OVERLAY_ATTR}] .orbitra-metric b { color: #fff; font-weight: 700; }
      [${OVERLAY_ATTR}] .orbitra-positive b { color: #4ade80; }
      [${OVERLAY_ATTR}] .orbitra-negative b { color: #fb7185; }
      [${OVERLAY_ATTR}] .orbitra-divider { color: #64748b; font-weight: 400; }
      [${OVERLAY_ATTR}="loading"] { color: #cbd5e1; }
      [${OVERLAY_ATTR}="error"] { border-color: rgba(251, 113, 133, .55); color: #fecdd3; }
    `;
    document.documentElement.appendChild(style);
  }

  function digits(value) {
    const matches = String(value || '').match(/\d{6,32}/g);
    return matches ? matches[matches.length - 1] : null;
  }

  function currentLevel() {
    let value = '';
    try {
      value = new URL(location.href).searchParams.get('level') || '';
    } catch (_error) {}
    value = value.toLowerCase().replace(/s$/, '');
    if (value.includes('campaign')) return 'campaign';
    if (value.includes('adset') || value.includes('ad_set')) return 'adset';
    if (value === 'ad' || value.includes('advert')) return 'ad';
    return null;
  }

  function idFromAttributes(row, level) {
    const config = levelKeys[level];
    const candidates = [row, ...row.querySelectorAll(config.attributes.map(a => `[${a}]`).join(','))];
    for (const node of candidates) {
      for (const attribute of config.attributes) {
        const found = digits(node.getAttribute?.(attribute));
        if (found) return found;
      }
    }
    return null;
  }

  function idFromLinks(row, level) {
    const wanted = levelKeys[level].params;
    for (const link of row.querySelectorAll('a[href]')) {
      try {
        const url = new URL(link.getAttribute('href'), location.origin);
        for (const param of wanted) {
          const found = digits(url.searchParams.get(param));
          if (found) return found;
        }
      } catch (_error) {}
    }
    return null;
  }

  function idFromTestIds(row, level) {
    const pattern = level === 'campaign' ? 'campaign' : level === 'adset' ? 'ad[ _-]?set' : 'ad';
    for (const node of row.querySelectorAll('[data-testid]')) {
      const testId = node.getAttribute('data-testid') || '';
      const match = testId.match(new RegExp(`${pattern}[^0-9]{0,12}(\\d{6,32})`, 'i'));
      if (match) return match[1];
    }
    return null;
  }

  function identifyRow(row) {
    if (row.closest('[role="columnheader"]') || row.getAttribute('aria-rowindex') === '1') return null;
    const preferred = currentLevel();
    const order = preferred ? [preferred, ...['campaign', 'adset', 'ad'].filter(level => level !== preferred)] : ['ad', 'adset', 'campaign'];
    for (const level of order) {
      const id = idFromAttributes(row, level) || idFromLinks(row, level) || idFromTestIds(row, level);
      if (id) return { row, level, id };
    }
    return null;
  }

  function overlayHost(row) {
    const cells = [...row.querySelectorAll(':scope > [role="gridcell"], :scope > [role="cell"]')];
    return cells.find(cell => cell.querySelector('a[href]')) || cells[1] || cells[0] || row;
  }

  function ensureOverlay(info) {
    let overlay = info.row.querySelector(`:scope [${OVERLAY_ATTR}]`);
    if (!overlay) {
      overlay = document.createElement('span');
      overlay.setAttribute(OVERLAY_ATTR, 'loading');
      overlay.textContent = 'Orbitra · loading…';
      overlayHost(info.row).appendChild(overlay);
    }
    overlay.dataset.orbitraLevel = info.level;
    overlay.dataset.orbitraId = info.id;
    return overlay;
  }

  function money(value, signed = false) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) return '—';
    const number = Number(value);
    const sign = signed && number > 0 ? '+' : number < 0 ? '−' : '';
    return `${sign}$${Math.abs(number).toFixed(2)}`;
  }

  function percent(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) return '—';
    const number = Number(value);
    return `${number > 0 ? '+' : number < 0 ? '−' : ''}${Math.abs(number).toFixed(1)}%`;
  }

  function metric(label, value, tone = '') {
    return `<span class="orbitra-metric ${tone}">${label}: <b>${value}</b></span>`;
  }

  function tone(value) {
    if (Number(value) > 0) return 'orbitra-positive';
    if (Number(value) < 0) return 'orbitra-negative';
    return '';
  }

  function renderStats(overlay, stats, template) {
    const row = stats || {
      cpa: 0, cpl: 0, cps: 0, cost: 0, revenue: 0,
      revenue_confirmed: 0, roi: null, roi_confirmed: null,
      profit: 0, profit_confirmed: 0
    };
    const divider = '<span class="orbitra-divider">|</span>';
    const parts = ['<span class="orbitra-brand">Orbitra</span>'];
    if (template === 'cod') {
      parts.push(
        metric('CPL', money(row.cpl)), divider,
        metric('CPS', money(row.cps)), divider,
        metric('SPENT', money(row.cost)), divider,
        metric('REV (CONF)', money(row.revenue_confirmed)), divider,
        metric('ROI (CONF)', percent(row.roi_confirmed), tone(row.roi_confirmed)), divider,
        metric('PROFIT', money(row.profit_confirmed, true), tone(row.profit_confirmed))
      );
    } else {
      parts.push(
        metric('CPA', money(row.cpa)), divider,
        metric('SPENT', money(row.cost)), divider,
        metric('REV', money(row.revenue)), divider,
        metric('ROI', percent(row.roi), tone(row.roi)), divider,
        metric('PROFIT', money(row.profit, true), tone(row.profit))
      );
    }
    overlay.setAttribute(OVERLAY_ATTR, 'ready');
    overlay.innerHTML = parts.join('');
  }

  function showError(infos, message) {
    const text = String(message || 'Unable to load stats').slice(0, 120);
    for (const info of infos) {
      const overlay = ensureOverlay(info);
      overlay.setAttribute(OVERLAY_ATTR, 'error');
      overlay.textContent = `Orbitra · ${text}`;
    }
  }

  function scanRows() {
    const rows = [...document.querySelectorAll('div[role="row"], tr[role="row"], [data-testid*="campaign"][role="row"], [data-testid*="adset"][role="row"], [data-testid*="ad"][role="row"]')];
    const seen = new Set();
    const infos = [];
    for (const row of rows) {
      if (seen.has(row)) continue;
      seen.add(row);
      const info = identifyRow(row);
      if (info) infos.push(info);
    }
    return infos;
  }

  async function refresh(force = false) {
    installStyles();
    const infos = scanRows();
    if (!infos.length) return;
    const serial = ++requestSerial;
    infos.forEach(ensureOverlay);

    const ids = { campaign: new Set(), adset: new Set(), ad: new Set() };
    infos.forEach(info => ids[info.level].add(info.id));
    let response;
    try {
      response = await chrome.runtime.sendMessage({
        type: 'ORBITRA_GET_STATS',
        date: 'today',
        force,
        campaignIds: [...ids.campaign],
        adsetIds: [...ids.adset],
        adIds: [...ids.ad]
      });
    } catch (error) {
      showError(infos, error.message);
      return;
    }
    if (serial !== requestSerial) return;
    if (!response?.ok) {
      showError(infos, response?.error);
      return;
    }

    const maps = response.data || {};
    const keyByLevel = { campaign: 'campaigns', adset: 'adsets', ad: 'ads' };
    for (const info of infos) {
      const overlay = ensureOverlay(info);
      renderStats(overlay, maps[keyByLevel[info.level]]?.[info.id], response.template || 'cpa');
    }
  }

  function scheduleRefresh(delay = 450) {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => refresh(false), delay);
  }

  async function startPolling() {
    clearInterval(pollTimer);
    const settings = await chrome.storage.sync.get(DEFAULT_SETTINGS);
    const seconds = Math.max(15, Math.min(300, Number(settings.pollingInterval) || 30));
    pollTimer = setInterval(() => refresh(true), seconds * 1000);
  }

  const observer = new MutationObserver(mutations => {
    const needsRefresh = mutations.some(mutation => {
      if (mutation.target.nodeType === Node.ELEMENT_NODE && mutation.target.closest?.(`[${OVERLAY_ATTR}]`)) {
        return false;
      }
      const changed = [...mutation.addedNodes, ...mutation.removedNodes];
      if (changed.length && changed.every(node =>
        node.nodeType === Node.ELEMENT_NODE
        && (node.matches?.(`[${OVERLAY_ATTR}]`) || node.closest?.(`[${OVERLAY_ATTR}]`))
      )) {
        return false;
      }
      return true;
    });
    if (needsRefresh) scheduleRefresh();
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });

  chrome.storage.onChanged.addListener((_changes, area) => {
    if (area !== 'sync') return;
    startPolling();
    refresh(true);
  });

  setInterval(() => {
    if (location.href !== lastUrl) {
      lastUrl = location.href;
      document.querySelectorAll(`[${OVERLAY_ATTR}]`).forEach(node => node.remove());
      scheduleRefresh(700);
    }
  }, 1000);

  startPolling();
  scheduleRefresh(250);
})();
