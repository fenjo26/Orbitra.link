(() => {
  if (!location.pathname.toLowerCase().includes('adsmanager')) return;

  const OVERLAY_ATTR = 'data-orbitra-overlay';
  const DRAFT_ATTR = 'data-orbitra-draft';
  // Everything this script and modal.js inject; the MutationObserver ignores
  // changes inside these so re-rendering our own UI never schedules a refresh.
  const OWN_NODES_SELECTOR = `[${OVERLAY_ATTR}], [${DRAFT_ATTR}], #orbitra-floating-widget, .orbitra-modal-overlay`;
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

  const L = (navigator.language || 'en').toLowerCase().startsWith('ru')
    ? {
        liveWord: 'Онлайн', liveNoRows: 'Онлайн · кампаний нет', connecting: 'Соединение…', errorWord: 'Ошибка',
        countLabel: (n) => `кампаний: ${n}`, analyticsBtn: '📊 Статистика', refreshTitle: 'Обновить',
        draftBadge: 'Черновик · ждём трафика', accountWord: 'Кабинет'
      }
    : {
        liveWord: 'Live', liveNoRows: 'Live · no campaigns', connecting: 'Connecting…', errorWord: 'Error',
        countLabel: (n) => `campaigns: ${n}`, analyticsBtn: '📊 Analytics', refreshTitle: 'Refresh',
        draftBadge: 'Draft · awaiting traffic', accountWord: 'Account'
      };

  let refreshTimer = null;
  let pollTimer = null;
  let lastUrl = location.href;
  let requestSerial = 0;
  let lastProbeAt = 0;

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
      #orbitra-floating-widget {
        position: fixed;
        top: 10px;
        right: 14px;
        z-index: 2147482500;
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        padding: 7px 10px;
        border: 1px solid rgba(139, 92, 246, .48);
        border-radius: 10px;
        background: linear-gradient(135deg, #151322 0%, #211a38 100%);
        color: #f8fafc;
        box-shadow: 0 6px 24px rgba(15, 23, 42, .35);
        font: 600 11px/1.3 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        cursor: pointer;
        user-select: none;
      }
      #orbitra-floating-widget .ofw-logo { color: #c4b5fd; letter-spacing: .02em; white-space: nowrap; }
      #orbitra-floating-widget .ofw-status { color: #cbd5e1; font-weight: 500; white-space: nowrap; }
      #orbitra-floating-widget .ofw-status[data-state="live"] { color: #4ade80; }
      #orbitra-floating-widget .ofw-status[data-state="live"]::before { content: '🟢'; margin-right: 4px; }
      #orbitra-floating-widget .ofw-status[data-state="error"] { color: #fb7185; }
      #orbitra-floating-widget .ofw-status[data-state="error"]::before { content: '🔴'; margin-right: 4px; }
      #orbitra-floating-widget .ofw-status[data-state="checking"] { color: #fcd34d; }
      #orbitra-floating-widget .ofw-btn {
        background: rgba(139, 92, 246, .18);
        border: 1px solid rgba(139, 92, 246, .5);
        border-radius: 7px;
        color: #e9d5ff;
        cursor: pointer;
        font: inherit;
        padding: 4px 9px;
        white-space: nowrap;
      }
      #orbitra-floating-widget .ofw-btn:hover { background: rgba(139, 92, 246, .34); }
      [${DRAFT_ATTR}] {
        display: inline-flex !important;
        align-items: center;
        margin: 4px 0 2px 6px;
        padding: 3px 6px;
        border: 1px dashed rgba(148, 163, 184, .5);
        border-radius: 7px;
        background: rgba(148, 163, 184, .08);
        color: #94a3b8;
        font: 600 10px/1.25 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        vertical-align: middle;
      }
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

  // Meta's generic row attribute; only trusted for the level the table is
  // currently showing, so an ad set id in a campaign table never wins.
  function idFromRecordId(row) {
    return digits(row.getAttribute?.('data-record-id'))
      || digits(row.querySelector('[data-record-id]')?.getAttribute('data-record-id'));
  }

  function isProbablyHeader(row) {
    return row.closest('[role="columnheader"]') || row.getAttribute('aria-rowindex') === '1';
  }

  function identifyRow(row) {
    if (isProbablyHeader(row)) return null;
    const preferred = currentLevel();
    const order = preferred ? [preferred, ...['campaign', 'adset', 'ad'].filter(level => level !== preferred)] : ['ad', 'adset', 'campaign'];
    for (const level of order) {
      const id = idFromAttributes(row, level)
        || idFromLinks(row, level)
        || idFromTestIds(row, level)
        || (level === preferred ? idFromRecordId(row) : null);
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

  // Draft / not-yet-published rows carry no numeric Meta id, so there is
  // nothing to request from the tracker — mark them so the user still sees
  // the extension is alive and watching the table.
  function markDraftRows() {
    for (const row of document.querySelectorAll('div[role="row"], tr[role="row"]')) {
      if (row.querySelector(`:scope [${OVERLAY_ATTR}], :scope [${DRAFT_ATTR}]`)) continue;
      if (isProbablyHeader(row) || !row.closest('[role="grid"]')) continue;
      if (row.querySelectorAll(':scope > [role="gridcell"], :scope > [role="cell"]').length < 3) continue;
      if (identifyRow(row)) continue;
      const badge = document.createElement('span');
      badge.setAttribute(DRAFT_ATTR, '1');
      badge.textContent = `Orbitra · ${L.draftBadge}`;
      overlayHost(row).appendChild(badge);
    }
  }

  function accountActId() {
    return (location.href.match(/act=(\d+)/) || [])[1] || '';
  }

  // All detected entities of one level (campaign > adset > ad) — a single
  // level only, so the account totals are never double-counted.
  function collectAccountEntities() {
    const byLevel = { campaign: new Map(), adset: new Map(), ad: new Map() };
    for (const info of scanRows()) byLevel[info.level].set(info.id, { type: info.level, id: info.id });
    for (const level of ['campaign', 'adset', 'ad']) {
      if (byLevel[level].size) return [...byLevel[level].values()].slice(0, 50);
    }
    return [];
  }

  function openAccountStats() {
    const entities = collectAccountEntities();
    const actId = accountActId();
    const subtitle = `${L.accountWord}${actId ? ` act_${actId}` : ''} · ${L.countLabel(entities.length)}`;
    window.OrbitraModal?.open(entities, subtitle);
  }

  function installFloatingWidget() {
    if (document.getElementById('orbitra-floating-widget')) return;
    const widget = document.createElement('div');
    widget.id = 'orbitra-floating-widget';
    const logo = document.createElement('span');
    logo.className = 'ofw-logo';
    logo.textContent = '🪐 Orbitra';
    const status = document.createElement('span');
    status.className = 'ofw-status';
    status.dataset.state = 'checking';
    status.textContent = L.connecting;
    const analytics = document.createElement('button');
    analytics.type = 'button';
    analytics.className = 'ofw-btn';
    analytics.textContent = L.analyticsBtn;
    const reload = document.createElement('button');
    reload.type = 'button';
    reload.className = 'ofw-btn';
    reload.title = L.refreshTitle;
    reload.textContent = '🔄';
    widget.append(logo, status, analytics, reload);
    widget.addEventListener('click', openAccountStats);
    analytics.addEventListener('click', event => { event.stopPropagation(); openAccountStats(); });
    reload.addEventListener('click', event => { event.stopPropagation(); refresh(true); });
    document.body.appendChild(widget);
  }

  function updateWidget(state, text, title = '') {
    const status = document.querySelector('#orbitra-floating-widget .ofw-status');
    if (!status) return;
    status.dataset.state = state;
    status.textContent = text;
    status.title = title;
  }

  // Page with no identifiable rows (drafts only): ping the tracker so the
  // widget still reports connection health. Throttled — MutationObserver
  // refreshes on a draft-only page would otherwise hammer the tracker.
  async function probeConnection(serial) {
    if (Date.now() - lastProbeAt < 25000) return;
    lastProbeAt = Date.now();
    let response;
    try {
      response = await chrome.runtime.sendMessage({ type: 'ORBITRA_TEST_CONNECTION' });
    } catch (error) {
      response = { ok: false, error: error.message };
    }
    if (serial !== requestSerial) return;
    if (response?.ok) updateWidget('live', L.liveNoRows);
    else updateWidget('error', L.errorWord, response?.error || '');
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
    installFloatingWidget();
    markDraftRows();
    const serial = ++requestSerial;
    const infos = scanRows();
    if (!infos.length) {
      const status = document.querySelector('#orbitra-floating-widget .ofw-status');
      if (!status || status.dataset.state === 'checking') updateWidget('checking', L.connecting);
      probeConnection(serial);
      return;
    }
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
      updateWidget('error', L.errorWord, error.message);
      showError(infos, error.message);
      return;
    }
    if (serial !== requestSerial) return;
    if (!response?.ok) {
      updateWidget('error', L.errorWord, response?.error || '');
      showError(infos, response?.error);
      return;
    }

    updateWidget('live', `${L.liveWord} · ${L.countLabel(ids.campaign.size + ids.adset.size + ids.ad.size)}`);
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
      if (mutation.target.nodeType === Node.ELEMENT_NODE && mutation.target.closest?.(OWN_NODES_SELECTOR)) {
        return false;
      }
      const changed = [...mutation.addedNodes, ...mutation.removedNodes];
      if (changed.length && changed.every(node =>
        node.nodeType === Node.ELEMENT_NODE
        && (node.matches?.(OWN_NODES_SELECTOR) || node.closest?.(OWN_NODES_SELECTOR))
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
    const onAdsManager = location.pathname.toLowerCase().includes('adsmanager');
    const widget = document.getElementById('orbitra-floating-widget');
    if (widget) widget.style.display = onAdsManager ? '' : 'none';
    if (location.href !== lastUrl) {
      lastUrl = location.href;
      document.querySelectorAll(`[${OVERLAY_ATTR}], [${DRAFT_ATTR}]`).forEach(node => node.remove());
      if (onAdsManager) scheduleRefresh(700);
    }
  }, 1000);

  startPolling();
  scheduleRefresh(250);
})();
