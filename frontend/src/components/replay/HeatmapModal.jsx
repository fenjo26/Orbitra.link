import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { X, Loader2, Monitor, Smartphone, MousePointerClick, ArrowDownToLine } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import SegmentedControl from '../common/SegmentedControl';
import { API_URL, loadRrweb, sortEvents } from './replayUtils';

// Classic heatmap palette: transparent blue → green → yellow → red.
const buildPalette = () => {
    const c = document.createElement('canvas');
    c.width = 256;
    c.height = 1;
    const g = c.getContext('2d');
    const grad = g.createLinearGradient(0, 0, 256, 0);
    grad.addColorStop(0.0, 'rgba(0,0,255,0)');
    grad.addColorStop(0.25, 'rgb(0,120,255)');
    grad.addColorStop(0.5, 'rgb(0,220,120)');
    grad.addColorStop(0.75, 'rgb(255,220,0)');
    grad.addColorStop(1.0, 'rgb(255,30,0)');
    g.fillStyle = grad;
    g.fillRect(0, 0, 256, 1);
    return g.getImageData(0, 0, 256, 1).data;
};

const drawHeat = (canvas, points, width, height) => {
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, width, height);
    if (!points.length) return;
    const radius = width < 768 ? 18 : 26;
    // Alpha pass: each click adds a soft dot; density = alpha.
    const alpha = Math.max(0.04, Math.min(0.35, 12 / Math.sqrt(points.length)));
    for (const [x, y] of points) {
        const px = x * width;
        if (y > height + radius) continue;
        const g = ctx.createRadialGradient(px, y, 0, px, y, radius);
        g.addColorStop(0, `rgba(0,0,0,${alpha})`);
        g.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = g;
        ctx.fillRect(px - radius, y - radius, radius * 2, radius * 2);
    }
    // Colorize pass.
    const img = ctx.getImageData(0, 0, width, height);
    const pal = buildPalette();
    const d = img.data;
    for (let i = 0; i < d.length; i += 4) {
        const a = d[i + 3];
        if (!a) continue;
        const o = a * 4;
        d[i] = pal[o];
        d[i + 1] = pal[o + 1];
        d[i + 2] = pal[o + 2];
        d[i + 3] = Math.min(230, a * 1.6 + 40);
    }
    ctx.putImageData(img, 0, 0);
    // Rage clicks on top as red rings.
    ctx.strokeStyle = 'rgba(220,38,38,.95)';
    ctx.lineWidth = 2;
    for (const [x, y, rage] of points) {
        if (!rage) continue;
        ctx.beginPath();
        ctx.arc(x * width, y, 9, 0, Math.PI * 2);
        ctx.stroke();
    }
};

/**
 * Click heatmap and scroll map of one landing — or, for an organic site, of a
 * whole campaign (campaignId instead of landingId). The page itself is drawn
 * from the newest recording of that device class (rrweb rebuilds its first
 * frame), so no request ever reaches the live landing and no click is logged.
 * Multi-page sites get a page selector: the points and the background frame
 * both switch to the chosen path.
 */
const HeatmapModal = ({ landingId, landingName, campaignId, campaignName, onClose }) => {
    const { t } = useLanguage();
    const [device, setDevice] = useState('desktop');
    const [days, setDays] = useState(7);
    const [mode, setMode] = useState('clicks');
    const [pageFilter, setPageFilter] = useState('');
    // Results are keyed by the request that produced them, so "loading" and
    // "which background is shown" are derived instead of set inside effects.
    const reqKey = `${landingId}|${campaignId}|${device}|${days}|${pageFilter}`;
    const [result, setResult] = useState({ key: null, data: null });
    const [bg, setBg] = useState({ id: null, state: 'idle', page: null }); // state: ready | none | error
    const [scale, setScale] = useState(1);
    const scrollerRef = useRef(null);
    const hostRef = useRef(null);
    const canvasRef = useRef(null);
    const replayerRef = useRef(null);

    useEffect(() => {
        let cancelled = false;
        const scope = landingId
            ? `landing_id=${landingId}`
            : `campaign_id=${campaignId}`;
        axios.get(`${API_URL}?action=replay_heatmap&${scope}&device=${device}&days=${days}${pageFilter ? `&page=${encodeURIComponent(pageFilter)}` : ''}`)
            .then(res => { if (!cancelled) setResult({ key: reqKey, data: res.data?.status === 'success' ? res.data.data : null }); })
            .catch(() => { if (!cancelled) setResult({ key: reqKey, data: null }); });
        return () => { cancelled = true; };
    }, [landingId, campaignId, device, days, pageFilter, reqKey]);

    const loading = result.key !== reqKey;
    const data = result.data;
    const bgId = data?.background?.click_id || null;
    const bgState = !data ? 'idle' : !bgId ? 'none' : bg.id === bgId ? bg.state : 'loading';
    const fallbackPage = { w: data?.doc_w || (device === 'mobile' ? 390 : 1280), h: data?.doc_h || 2000 };
    const page = bgState === 'ready' && bg.page ? bg.page : fallbackPage;

    // Background page from the newest recording of that device class.
    const docH = data?.doc_h || 2000;
    const bgViewportW = data?.background?.viewport_w || 1280;
    // In a multi-page recording each document starts with a meta event and a
    // full snapshot. For a chosen page the frame to rebuild is the full
    // snapshot whose meta carries that path; without a page filter it is the
    // first frame of the stream (as before).
    const findFrame = (events, pagePath) => {
        const pathOf = (href) => {
            try { return new URL(href, 'https://replay.invalid').pathname.slice(0, 500); } catch { return null; }
        };
        let currentHref = null;
        for (let i = 0; i < events.length; i++) {
            const e = events[i];
            if (e.type === 4 && e.data?.href) currentHref = String(e.data.href);
            if (e.type === 2 && (!pagePath || pathOf(currentHref) === pagePath)) {
                return { idx: i, href: currentHref };
            }
        }
        return null;
    };
    useEffect(() => {
        let cancelled = false;
        try { replayerRef.current?.destroy(); } catch { /* gone */ }
        replayerRef.current = null;
        if (hostRef.current) hostRef.current.innerHTML = '';
        if (!bgId) return undefined;
        (async () => {
            try {
                const [res, rrweb] = await Promise.all([
                    axios.get(`${API_URL}?action=replay_events&id=${encodeURIComponent(bgId)}`),
                    loadRrweb(),
                ]);
                if (cancelled) return;
                const events = sortEvents(res.data?.events);
                const frame = findFrame(events, pageFilter);
                if (!frame) { setBg({ id: bgId, state: 'none', page: null }); return; }
                // Only the first frame of that page: meta + full snapshot (+ anything up to it).
                const firstFrame = events.slice(0, frame.idx + 1);
                const metaEv = firstFrame.find(e => e.type === 4);
                const vw = metaEv?.data?.width || bgViewportW;
                const replayer = new rrweb.Replayer(firstFrame, {
                    root: hostRef.current, showWarning: false, mouseTail: false, triggerFocus: false,
                });
                replayerRef.current = replayer;
                replayer.pause(0);
                // Let the snapshot lay out, then stretch the frame to the page height.
                setTimeout(() => {
                    if (cancelled) return;
                    const iframe = replayer.iframe;
                    let h = docH;
                    try {
                        const doc = iframe.contentDocument;
                        h = Math.max(doc.documentElement.scrollHeight, doc.body ? doc.body.scrollHeight : 0, h);
                    } catch { /* cross-origin never happens here, keep estimate */ }
                    h = Math.min(h, 30000);
                    iframe.setAttribute('height', String(h));
                    iframe.style.height = `${h}px`;
                    iframe.setAttribute('width', String(vw));
                    if (replayer.wrapper) {
                        replayer.wrapper.style.width = `${vw}px`;
                        replayer.wrapper.style.height = `${h}px`;
                    }
                    setBg({ id: bgId, state: 'ready', page: { w: vw, h } });
                }, 400);
            } catch {
                if (!cancelled) setBg({ id: bgId, state: 'error', page: null });
            }
        })();
        return () => { cancelled = true; };
    }, [bgId, docH, bgViewportW, pageFilter]);

    useEffect(() => () => { try { replayerRef.current?.destroy(); } catch { /* gone */ } }, []);

    // Fit width.
    useEffect(() => {
        const fit = () => {
            const el = scrollerRef.current;
            if (!el) return;
            setScale(Math.min(1, (el.clientWidth - 24) / page.w));
        };
        fit();
        window.addEventListener('resize', fit);
        return () => window.removeEventListener('resize', fit);
    }, [page.w]);

    // Overlay.
    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas || !data) return;
        if (mode === 'clicks') {
            drawHeat(canvas, data.points || [], page.w, page.h);
        } else {
            canvas.width = page.w;
            canvas.height = page.h;
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, page.w, page.h);
        }
    }, [data, mode, page.w, page.h]);

    const reach = data?.scroll?.reach || [];
    const measured = data?.scroll?.measured || 0;
    const bandColor = (share) => {
        if (share === null || share === undefined) return 'transparent';
        const hue = Math.round(share * 120); // 0 = red (nobody), 120 = green (everyone)
        return `hsla(${hue}, 85%, 50%, 0.28)`;
    };

    return (
        <div className="fixed inset-0 z-[1900] bg-black/70 flex items-center justify-center p-2 md:p-6" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
            <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl shadow-2xl w-full h-full max-w-[1400px] flex flex-col overflow-hidden">
                <div className="flex items-center justify-between gap-3 px-4 py-3 border-b border-[var(--color-border)]">
                    <div className="min-w-0">
                        <div className="font-semibold text-[var(--color-text-primary)] truncate">
                            {t('replay.heatmapTitle')}{landingName ? ` — ${landingName}` : campaignName ? ` — ${campaignName}` : ''}
                        </div>
                        <div className="text-xs text-[var(--color-text-muted)]">
                            {mode === 'clicks'
                                ? t('replay.heatmapClicksSummary', { clicks: data?.total_clicks ?? 0, sessions: data?.sessions ?? 0 })
                                : t('replay.heatmapScrollSummary', { visits: measured })}
                        </div>
                    </div>
                    <button type="button" className="action-btn" onClick={onClose} aria-label={t('common.close', 'Close')}><X className="w-5 h-5" /></button>
                </div>
                <div className="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-[var(--color-border)]">
                    <div className="w-[240px]">
                        <SegmentedControl
                            ariaLabel={t('replay.heatmapMode')}
                            value={mode}
                            onChange={setMode}
                            options={[
                                { value: 'clicks', label: t('replay.heatmapClicks'), icon: MousePointerClick },
                                { value: 'scroll', label: t('replay.heatmapScroll'), icon: ArrowDownToLine },
                            ]}
                        />
                    </div>
                    <div className="w-[240px]">
                        <SegmentedControl
                            ariaLabel={t('replay.device')}
                            value={device}
                            onChange={setDevice}
                            options={[
                                { value: 'desktop', label: t('replay.desktop'), icon: Monitor },
                                { value: 'mobile', label: t('replay.mobile'), icon: Smartphone },
                            ]}
                        />
                    </div>
                    <select className="form-select w-auto" value={days} onChange={e => setDays(Number(e.target.value))} aria-label={t('replay.period')}>
                        {[1, 7, 30].map(d => <option key={d} value={d}>{t('replay.lastDays', { days: d })}</option>)}
                    </select>
                    {data?.pages?.length > 0 && (
                        <select
                            className="form-select w-auto max-w-[280px]"
                            value={pageFilter}
                            onChange={e => setPageFilter(e.target.value)}
                            aria-label={t('replay.heatmapPage')}
                            data-testid="replay-heatmap-page"
                        >
                            <option value="">{t('replay.heatmapAllPages')}</option>
                            {data.pages.map(p => (
                                <option key={p.page} value={p.page}>{p.page} ({p.clicks})</option>
                            ))}
                        </select>
                    )}
                    {mode === 'clicks' && <span className="text-xs text-[var(--color-text-muted)]">{t('replay.heatmapRageLegend')}</span>}
                    {mode === 'scroll' && <span className="text-xs text-[var(--color-text-muted)]">{t('replay.heatmapScrollLegend')}</span>}
                </div>
                <div ref={scrollerRef} className="flex-1 min-h-0 overflow-auto p-3" style={{ background: 'var(--color-bg-soft)' }}>
                    {(loading || bgState === 'loading') && (
                        <div className="flex items-center gap-2 text-[var(--color-text-muted)] text-sm mb-3"><Loader2 className="w-4 h-4 animate-spin" /> {t('replay.playerLoading')}</div>
                    )}
                    {!loading && bgState === 'none' && (
                        <div className="text-sm text-[var(--color-text-muted)] mb-3">{t('replay.heatmapNoBackground')}</div>
                    )}
                    {!loading && mode === 'clicks' && data && data.total_clicks === 0 && (
                        <div className="text-sm text-[var(--color-text-muted)] mb-3">{t('replay.heatmapNoClicks')}</div>
                    )}
                    {!loading && mode === 'scroll' && measured === 0 && (
                        <div className="text-sm text-[var(--color-text-muted)] mb-3">{t('replay.heatmapNoScroll')}</div>
                    )}
                    <div style={{ width: page.w * scale, height: page.h * scale, margin: '0 auto', position: 'relative' }}>
                        <div style={{ width: page.w, height: page.h, transform: `scale(${scale})`, transformOrigin: 'top left', position: 'absolute', left: 0, top: 0, background: '#fff', boxShadow: '0 8px 30px rgba(0,0,0,.2)', overflow: 'hidden' }}>
                            {/* The first frame only: rrweb's cursor would sit in the corner. */}
                            <style>{'.orbitra-heatmap-bg .replayer-mouse, .orbitra-heatmap-bg .replayer-mouse-tail { display: none !important; }'}</style>
                            <div ref={hostRef} className="orbitra-heatmap-bg" style={{ pointerEvents: 'none' }} />
                            <canvas ref={canvasRef} style={{ position: 'absolute', left: 0, top: 0, width: page.w, height: page.h, pointerEvents: 'none' }} />
                            {mode === 'scroll' && reach.length > 1 && reach.slice(0, -1).map((r, i) => {
                                const top = (r.pct / 100) * page.h;
                                const h = page.h / 10;
                                const share = reach[i + 1]?.share ?? r.share;
                                return (
                                    <div key={r.pct} style={{ position: 'absolute', left: 0, right: 0, top, height: h, background: bandColor(share), borderTop: '1px dashed rgba(0,0,0,.25)' }}>
                                        <span style={{ position: 'absolute', right: 8, top: 4, fontSize: Math.max(12, 12 / scale), fontWeight: 700, background: 'rgba(17,24,39,.85)', color: '#fff', padding: '2px 8px', borderRadius: 6 }}>
                                            {share === null ? '—' : t('replay.scrollReached', { share: Math.round((r.share ?? 0) * 100), depth: r.pct })}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default HeatmapModal;
