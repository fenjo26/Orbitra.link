// extension/modal.js — deep-stats popover for the Ads Manager overlay.
//
// Listens for clicks on the row pills content.js renders ([data-orbitra-overlay]
// with data-orbitra-level / data-orbitra-id), asks the service worker for
// extension_deep_stats and shows the detail modal: Overview (with CAPI
// accuracy), Daily history, Landings & Offers. Loaded after content.js; the
// only coupling is those data attributes.
(() => {
  if (!location.pathname.toLowerCase().includes('adsmanager')) return;

  const L = (navigator.language || 'en').toLowerCase().startsWith('ru')
    ? {
        title: 'Orbitra', overview: 'ОБЗОР', daily: 'ИСТОРИЯ ПО ДНЯМ', funnel: 'ЛЕНДИНГИ И ОФФЕРЫ',
        spend: 'Расход', revenue: 'Доход', profit: 'Прибыль', roi: 'ROI', cpa: 'CPA', cpl: 'CPL',
        cps: 'CPS', cpc: 'CPC', epc: 'EPC', clicks: 'Клики', sales: 'Сейлы', conv: 'Конверсии',
        cr: 'CR', lpCtr: 'LP CTR', capi: 'Точность Pixel и CAPI', capiHint: 'Конверсии трекера против событий, доставленных в Meta через CAPI.',
        date: 'Дата', landing: 'Лендинг', offer: 'Оффер', noData: 'Нет данных за период',
        loading: 'Загрузка…', error: 'Ошибка', close: 'Закрыть', lastSync: 'Синхронизировано'
      }
    : {
        title: 'Orbitra', overview: 'OVERVIEW', daily: 'DAILY HISTORY', funnel: 'LANDINGS & OFFERS',
        spend: 'Spend', revenue: 'Revenue', profit: 'Profit', roi: 'ROI', cpa: 'CPA', cpl: 'CPL',
        cps: 'CPS', cpc: 'CPC', epc: 'EPC', clicks: 'Clicks', sales: 'Sales', conv: 'Conversions',
        cr: 'CR', lpCtr: 'LP CTR', capi: 'Pixel & CAPI accuracy', capiHint: 'Tracker conversions vs events actually delivered to Meta via CAPI.',
        date: 'Date', landing: 'Landing', offer: 'Offer', noData: 'No data for this period',
        loading: 'Loading…', error: 'Error', close: 'Close', lastSync: 'Synced'
      };

  const CSS = `
    .orbitra-modal-overlay { position: fixed; inset: 0; z-index: 2147483000; background: rgba(2,4,12,.62); display: flex; align-items: center; justify-content: center; }
    .orbitra-modal { width: 620px; max-width: 94vw; max-height: 86vh; overflow: auto; border-radius: 14px;
      background: #12101d; color: #e2e8f0; border: 1px solid rgba(139,92,246,.35); box-shadow: 0 24px 80px rgba(0,0,0,.55);
      font: 12px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    .orbitra-modal header { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid rgba(148,163,184,.14); }
    .orbitra-modal header b { font-size: 13px; }
    .orbitra-modal header .sub { color: #94a3b8; }
    .orbitra-modal header button { margin-left: auto; background: none; border: 0; color: #94a3b8; font-size: 16px; cursor: pointer; }
    .orbitra-tabs { display: flex; gap: 4px; padding: 10px 16px 0; }
    .orbitra-tabs button { background: transparent; border: 1px solid transparent; border-radius: 8px 8px 0 0; color: #94a3b8; padding: 6px 12px; cursor: pointer; font-weight: 600; font-size: 11px; letter-spacing: .04em; }
    .orbitra-tabs button.on { color: #e2e8f0; border-color: rgba(139,92,246,.35); background: rgba(139,92,246,.10); }
    .orbitra-body { padding: 14px 16px 18px; }
    .orbitra-kv { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 8px; }
    .orbitra-kv div { background: rgba(148,163,184,.07); border: 1px solid rgba(148,163,184,.12); border-radius: 9px; padding: 8px 10px; }
    .orbitra-kv span { display: block; color: #94a3b8; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; }
    .orbitra-kv b { font-size: 13px; }
    .orbitra-pos { color: #4ade80; } .orbitra-neg { color: #fb7185; }
    .orbitra-modal table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    .orbitra-modal th { text-align: left; color: #94a3b8; font-size: 10px; text-transform: uppercase; padding: 5px 8px; border-bottom: 1px solid rgba(148,163,184,.16); }
    .orbitra-modal td { padding: 6px 8px; border-bottom: 1px solid rgba(148,163,184,.08); white-space: nowrap; }
    .orbitra-note { color: #94a3b8; margin-top: 10px; }
    .orbitra-warn { background: rgba(245,158,11,.10); border: 1px solid rgba(245,158,11,.4); color: #fcd34d; border-radius: 9px; padding: 8px 10px; margin-top: 10px; }
    [data-orbitra-overlay="ready"] { cursor: pointer; }
  `;

  const styleEl = document.createElement('style');
  styleEl.textContent = CSS;
  document.documentElement.appendChild(styleEl);

  const money = (v, signed) => {
    const n = Number(v);
    if (!Number.isFinite(n)) return '—';
    const sign = signed && n > 0 ? '+' : n < 0 ? '−' : '';
    return `${sign}$${Math.abs(n).toFixed(2)}`;
  };
  const pct = (v) => {
    const n = Number(v);
    if (!Number.isFinite(n)) return '—';
    return `${n > 0 ? '+' : n < 0 ? '−' : ''}${Math.abs(n).toFixed(1)}%`;
  };
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const tone = (v) => (Number(v) > 0 ? 'orbitra-pos' : Number(v) < 0 ? 'orbitra-neg' : '');

  function kv(label, value, cls = '') {
    return `<div><span>${esc(label)}</span><b class="${cls}">${value}</b></div>`;
  }

  function renderOverview(e) {
    const pa = e.pixel_accuracy || {};
    const acc = pa.tracker_leads > 0
      ? `${pa.fb_reported} / ${pa.tracker_leads} = ${pa.accuracy_pct}%`
      : '—';
    return `
      <div class="orbitra-kv">
        ${kv(L.spend, money(e.spend))}
        ${kv(L.revenue, money(e.revenue))}
        ${kv(L.profit, money(e.profit, true), tone(e.profit))}
        ${kv(L.roi, pct(e.roi), tone(e.roi))}
        ${kv(L.cpa, money(e.cpa))}
        ${kv(L.cpl, money(e.cpl))}
        ${kv(L.cps, money(e.cps))}
        ${kv(L.cpc, money(e.cpc))}
        ${kv(L.epc, money(e.epc))}
        ${kv(L.clicks, e.clicks ?? 0)}
        ${kv(L.conv, e.conversions ?? 0)}
        ${kv(L.sales, e.sales ?? 0)}
        ${kv(L.cr, pct(e.cr))}
        ${kv(L.lpCtr, pct(e.lp_ctr))}
      </div>
      <div class="orbitra-note">${esc(L.capi)}: <b>${acc}</b> — ${esc(L.capiHint)}</div>
      ${pa.tracker_leads > 0 && pa.fb_reported < pa.tracker_leads
        ? `<div class="orbitra-warn">${esc(L.capi)}: ${pa.fb_reported}/${pa.tracker_leads}</div>` : ''}
    `;
  }

  function renderDaily(e) {
    const rows = (e.daily_history || []);
    if (!rows.length) return `<div class="orbitra-note">${esc(L.noData)}</div>`;
    return `<table><thead><tr><th>${esc(L.date)}</th><th>${esc(L.clicks)}</th><th>${esc(L.spend)}</th><th>${esc(L.revenue)}</th><th>${esc(L.profit)}</th><th>${esc(L.roi)}</th><th>${esc(L.sales)}</th></tr></thead>
      <tbody>${rows.map((d) => `<tr><td>${esc(d.date)}</td><td>${d.clicks}</td><td>${money(d.spend)}</td><td>${money(d.revenue)}</td><td class="${tone(d.profit)}">${money(d.profit, true)}</td><td class="${tone(d.roi)}">${pct(d.roi)}</td><td>${d.sales}</td></tr>`).join('')}</tbody></table>`;
  }

  function renderFunnel(e) {
    const landings = e.landings || [];
    const offers = e.offers || [];
    if (!landings.length && !offers.length) return `<div class="orbitra-note">${esc(L.noData)}</div>`;
    return `
      ${landings.length ? `<div style="margin:10px 0 2px;font-weight:700">${esc(L.landing)}</div>
      <table><thead><tr><th>${esc(L.landing)}</th><th>${esc(L.clicks)}</th><th>${esc(L.lpCtr)}</th><th>${esc(L.spend)}</th><th>${esc(L.revenue)}</th><th>${esc(L.profit)}</th></tr></thead>
      <tbody>${landings.map((l) => `<tr><td>${esc(l.name || l.id)}</td><td>${l.clicks}</td><td>${pct(l.lp_ctr)}</td><td>${money(l.spend)}</td><td>${money(l.revenue)}</td><td class="${tone(l.profit)}">${money(l.profit, true)}</td></tr>`).join('')}</tbody></table>` : ''}
      ${offers.length ? `<div style="margin:12px 0 2px;font-weight:700">${esc(L.offer)}</div>
      <table><thead><tr><th>${esc(L.offer)}</th><th>${esc(L.clicks)}</th><th>${esc(L.conv)}</th><th>CR</th><th>${esc(L.spend)}</th><th>${esc(L.revenue)}</th><th>${esc(L.profit)}</th></tr></thead>
      <tbody>${offers.map((o) => `<tr><td>${esc(o.name || o.id)}</td><td>${o.clicks}</td><td>${o.conversions}</td><td>${pct(o.cr)}</td><td>${money(o.spend)}</td><td>${money(o.revenue)}</td><td class="${tone(o.profit)}">${money(o.profit, true)}</td></tr>`).join('')}</tbody></table>` : ''}
    `;
  }

  let openModal = null;

  function closeModal() {
    openModal?.remove();
    openModal = null;
  }

  document.addEventListener('click', (event) => {
    const pill = event.target.closest?.('[data-orbitra-overlay="ready"]');
    if (!pill) {
      if (openModal && !event.target.closest('.orbitra-modal')) closeModal();
      return;
    }
    event.stopPropagation();
    const level = pill.dataset.orbitraLevel;
    const id = pill.dataset.orbitraId;
    if (!level || !id) return;
    openDeepStats(level, id);
  }, true);

  async function openDeepStats(level, id) {
    closeModal();
    const overlayEl = document.createElement('div');
    overlayEl.className = 'orbitra-modal-overlay';
    overlayEl.innerHTML = `<div class="orbitra-modal">
      <header><b>${L.title}</b><span class="sub">${esc(level)} · ${esc(id)}</span><button title="${esc(L.close)}">✕</button></header>
      <div class="orbitra-tabs"></div>
      <div class="orbitra-body">${esc(L.loading)}</div>
    </div>`;
    overlayEl.addEventListener('click', (e) => { if (e.target === overlayEl) closeModal(); });
    overlayEl.querySelector('header button').addEventListener('click', closeModal);
    document.body.appendChild(overlayEl);
    openModal = overlayEl;

    const typeByLevel = { campaign: 'campaign', adset: 'adset', ad: 'ad' };
    let resp;
    try {
      resp = await chrome.runtime.sendMessage({
        type: 'ORBITRA_DEEP_STATS',
        entities: [{ type: typeByLevel[level], id }]
      });
    } catch (err) {
      resp = { ok: false, error: err.message };
    }
    if (!openModal) return; // closed while loading
    const body = overlayEl.querySelector('.orbitra-body');
    const tabsEl = overlayEl.querySelector('.orbitra-tabs');

    if (!resp?.ok) {
      body.innerHTML = `<div class="orbitra-warn">${esc(L.error)}: ${esc(resp?.error || '—')}</div>`;
      return;
    }
    const entity = (resp.data?.entities || {})[id];
    if (!entity) {
      body.innerHTML = `<div class="orbitra-note">${esc(L.noData)}</div>`;
      return;
    }

    const tabs = [
      [L.overview, renderOverview],
      [L.daily, renderDaily],
      [L.funnel, renderFunnel]
    ];
    const header = overlayEl.querySelector('header .sub');
    header.textContent = `${level} · ${id} — ${money(entity.profit, true)} (${pct(entity.roi)}) · ${L.lastSync}: ${new Date().toLocaleTimeString()}`;
    tabsEl.innerHTML = tabs.map(([label], i) => `<button class="${i === 0 ? 'on' : ''}">${esc(label)}</button>`).join('');
    const btns = [...tabsEl.querySelectorAll('button')];
    const show = (i) => {
      body.innerHTML = tabs[i][1](entity);
      btns.forEach((b, j) => b.classList.toggle('on', i === j));
    };
    btns.forEach((b, i) => b.addEventListener('click', () => show(i)));
    show(0);
  }

  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
})();
