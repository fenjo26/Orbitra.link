import React, { useCallback, useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { Play, Flame, Trash2, HardDrive, Monitor, Smartphone, Target, Video, Loader2, RefreshCw, X } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import { useTimezone } from '../../utils/useTimezone';
import ReplayPlayerModal from './ReplayPlayerModal';
import HeatmapModal from './HeatmapModal';
import ReplayStorageCard from './ReplayStorageCard';
import Dialog from '../common/Dialog';
import PaginationToolbar from '../common/PaginationToolbar';
import { SortableTh, nextSortState } from '../common/SortableTh';
import { API_URL, formatBytes, formatDuration, formatUtcDateTime, flagEmoji } from './replayUtils';

// Same persisted page size as every other table with PaginationToolbar.
const initialPageSize = () => {
    try {
        const v = localStorage.getItem('orbitra_table_page_size');
        if (v === 'All') return 'All';
        const n = Number(v);
        return [25, 50, 100, 250].includes(n) ? n : 50;
    } catch {
        return 50;
    }
};
// "All" in the toolbar = one big page; the API caps a page at 1000 rows.
const ALL_LIMIT = 1000;
const PERIODS = { day: 1, week: 7, month: 30, all: 0 };

const utcDaysAgo = (days) => {
    const d = new Date(Date.now() - days * 86400000);
    return d.toISOString().slice(0, 19).replace('T', ' ');
};

const consumeHint = (key) => {
    try {
        const v = sessionStorage.getItem(key);
        if (v !== null) sessionStorage.removeItem(key);
        return v;
    } catch {
        return null;
    }
};

/** Logs → "Session recordings": the list, the filters, the player and the heatmap. */
const SessionReplays = ({ initialLandingId = null }) => {
    const { t, language } = useLanguage();
    const [timezone] = useTimezone();
    const [landingId, setLandingId] = useState(() => initialLandingId || consumeHint('orbitra_replays_landing') || '');
    const [campaignId, setCampaignId] = useState(() => consumeHint('orbitra_replays_campaign') || '');
    const [period, setPeriod] = useState('week');
    const [device, setDevice] = useState('');
    const [onlyOffer, setOnlyOffer] = useState(false);
    const [onlyConverted, setOnlyConverted] = useState(false);
    const [onlyRage, setOnlyRage] = useState(false);
    const [multiPage, setMultiPage] = useState(false);
    const [source, setSource] = useState('');
    const [pageQuery, setPageQuery] = useState('');
    const [minSeconds, setMinSeconds] = useState(0);
    const [rows, setRows] = useState([]);
    const [total, setTotal] = useState(0);
    const [landings, setLandings] = useState([]);
    const [campaigns, setCampaigns] = useState([]);
    const [storage, setStorage] = useState(null);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(0);
    const [pageSize, setPageSize] = useState(initialPageSize);
    const [sortBy, setSortBy] = useState({ key: 'created_at', dir: 'desc' });
    // Selection: explicit ids, or "every recording matching the filters".
    const [selected, setSelected] = useState(() => new Set());
    const [allMatching, setAllMatching] = useState(false);
    const [playing, setPlaying] = useState(null);
    const [heatmapFor, setHeatmapFor] = useState(null);
    const [showStorage, setShowStorage] = useState(false);
    const [confirm, setConfirm] = useState(null); // { ids } | { filter } | { landing_id } | { all }
    const [busy, setBusy] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);

    useEffect(() => {
        const onTab = (e) => {
            if (e?.detail?.landingId) {
                setLandingId(String(e.detail.landingId));
                setCampaignId('');
                setPage(0);
                setSelected(new Set());
                setAllMatching(false);
            } else if (e?.detail?.campaignId) {
                setCampaignId(String(e.detail.campaignId));
                setLandingId('');
                setPage(0);
                setSelected(new Set());
                setAllMatching(false);
            }
        };
        window.addEventListener('orbitra:logs-tab', onTab);
        return () => window.removeEventListener('orbitra:logs-tab', onTab);
    }, []);

    // The filters alone — the list query AND the "delete all matching" body.
    const filter = useMemo(() => {
        const f = {};
        if (landingId) f.landing_id = landingId;
        if (campaignId) f.campaign_id = campaignId;
        if (PERIODS[period]) f.date_from = utcDaysAgo(PERIODS[period]);
        if (device) f.device = device;
        if (onlyOffer) f.offer = 1;
        if (onlyConverted) f.converted = 1;
        if (onlyRage) f.rage = 1;
        if (multiPage) f.pages_min = 2;
        if (source) f.source = source;
        if (pageQuery.trim()) f.page = pageQuery.trim();
        if (minSeconds) f.min_seconds = minSeconds;
        return f;
    // period is re-evaluated on reload so "last 7 days" stays relative to now
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [landingId, campaignId, period, device, onlyOffer, onlyConverted, onlyRage, multiPage, source, pageQuery, minSeconds, reloadKey]);

    const limit = pageSize === 'All' ? ALL_LIMIT : Number(pageSize);
    const buildParams = useCallback(() => {
        const p = new URLSearchParams({ action: 'replays', limit: String(limit), offset: String(page * limit), sort: sortBy.key, dir: sortBy.dir });
        Object.entries(filter).forEach(([k, v]) => p.set(k, String(v)));
        return p;
    }, [filter, limit, page, sortBy]);

    // Any filter change starts over: first page, nothing selected.
    const resetView = () => {
        setPage(0);
        setSelected(new Set());
        setAllMatching(false);
    };
    const withReset = (setter) => (value) => { setter(value); resetView(); };

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        axios.get(`${API_URL}?${buildParams().toString()}`)
            .then(res => {
                if (cancelled || res.data?.status !== 'success') return;
                setRows(res.data.data || []);
                setTotal(res.data.total || 0);
                setLandings(res.data.landings || []);
                setCampaigns(res.data.campaigns || []);
                setStorage(res.data.storage || null);
            })
            .catch(() => { if (!cancelled) setRows([]); })
            .finally(() => { if (!cancelled) setLoading(false); });
        return () => { cancelled = true; };
    }, [buildParams]);

    const requestSort = (key, defaultDir) => {
        setSortBy(prev => nextSortState(prev, key, defaultDir));
        setPage(0);
    };

    // Selection helpers.
    const pageIds = rows.map(r => r.click_id);
    const pageAllSelected = pageIds.length > 0 && pageIds.every(id => allMatching || selected.has(id));
    const pageSomeSelected = !pageAllSelected && pageIds.some(id => selected.has(id));
    const selectedCount = allMatching ? total : selected.size;
    const toggleOne = (id) => {
        if (allMatching) {
            // Leaving "all matching" mode: keep this page minus the one unticked.
            setAllMatching(false);
            setSelected(new Set(pageIds.filter(x => x !== id)));
            return;
        }
        setSelected(prev => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    };
    const togglePage = () => {
        if (pageAllSelected) {
            setAllMatching(false);
            setSelected(prev => {
                const next = new Set(prev);
                pageIds.forEach(id => next.delete(id));
                return next;
            });
        } else {
            setSelected(prev => new Set([...prev, ...pageIds]));
        }
    };
    const clearSelection = () => { setSelected(new Set()); setAllMatching(false); };
    const askDeleteSelected = () => {
        if (allMatching) setConfirm({ filter, count: total });
        else setConfirm({ ids: [...selected], count: selected.size });
    };

    const doDelete = async () => {
        if (!confirm) return;
        setBusy(true);
        try {
            const { count: _count, ...body } = confirm;
            const res = await axios.post(`${API_URL}?action=replay_delete`, body);
            if (res.data?.status === 'success') {
                setStorage(res.data.data.storage);
                clearSelection();
                setPage(0);
                setReloadKey(k => k + 1);
            }
        } finally {
            setBusy(false);
            setConfirm(null);
        }
    };

    const confirmCount = confirm?.count || 0;
    const dialogTitle = !confirm ? '' : confirm.all ? t('replay.deleteAllTitle')
        : confirm.landing_id ? t('replay.deleteLandingTitle')
            : confirmCount > 1 ? t('replay.deleteManyTitle', { count: confirmCount }) : t('replay.deleteOneTitle');
    const dialogText = !confirm ? '' : confirm.all ? t('replay.deleteAllText')
        : confirm.landing_id ? t('replay.deleteLandingText')
            : confirm.filter ? t('replay.deleteFilteredText', { count: confirmCount })
                : confirmCount > 1 ? t('replay.deleteManyText') : t('replay.deleteOneText');

    const selectedLanding = landings.find(l => String(l.id) === String(landingId));
    const selectedCampaign = campaigns.find(c => String(c.id) === String(campaignId));
    const anyEnabled = landings.some(l => l.active) || campaigns.some(c => c.active);
    const noRecordingsAtAll = !loading && rows.length === 0 && landings.length === 0 && campaigns.length === 0;

    const goToLandings = () => window.dispatchEvent(new CustomEvent('orbitra:navigate', { detail: { tab: 'landings' } }));

    // Path of a recorded URL, for the pages column.
    const pathOf = (url) => {
        try { return new URL(String(url), 'https://replay.invalid').pathname; } catch { return String(url || ''); }
    };

    return (
        <div className="space-y-4" data-testid="session-replays">
            {/* Filters */}
            <div className="flex flex-wrap items-end gap-3">
                <div className="min-w-[200px]">
                    <label className="form-label">{t('replay.filterLanding')}</label>
                    <select
                        className="form-select"
                        value={landingId}
                        onChange={e => { setCampaignId(''); withReset(setLandingId)(e.target.value); }}
                        data-testid="replay-landing-filter"
                    >
                        <option value="">{t('replay.allLandings')}</option>
                        {landings.map(l => (
                            <option key={l.id} value={l.id}>
                                {l.name}{l.active ? ` · ● ${l.sample_pct}%` : ''} ({l.recordings})
                            </option>
                        ))}
                    </select>
                </div>
                {campaigns.length > 0 && (
                    <div className="min-w-[200px]">
                        <label className="form-label">{t('replay.filterCampaign')}</label>
                        <select
                            className="form-select"
                            value={campaignId}
                            onChange={e => { setLandingId(''); withReset(setCampaignId)(e.target.value); }}
                            data-testid="replay-campaign-filter"
                        >
                            <option value="">{t('replay.allCampaigns')}</option>
                            {campaigns.map(c => (
                                <option key={c.id} value={c.id}>
                                    {c.name}{c.active ? ` · ● ${c.sample_pct}%` : ''} ({c.recordings})
                                </option>
                            ))}
                        </select>
                    </div>
                )}
                <div>
                    <label className="form-label">{t('replay.period')}</label>
                    <select className="form-select" value={period} onChange={e => withReset(setPeriod)(e.target.value)}>
                        <option value="day">{t('replay.lastDays', { days: 1 })}</option>
                        <option value="week">{t('replay.lastDays', { days: 7 })}</option>
                        <option value="month">{t('replay.lastDays', { days: 30 })}</option>
                        <option value="all">{t('replay.allTime')}</option>
                    </select>
                </div>
                <div>
                    <label className="form-label">{t('replay.device')}</label>
                    <select className="form-select" value={device} onChange={e => withReset(setDevice)(e.target.value)}>
                        <option value="">{t('replay.allDevices')}</option>
                        <option value="desktop">{t('replay.desktop')}</option>
                        <option value="mobile">{t('replay.mobile')}</option>
                    </select>
                </div>
                <div>
                    <label className="form-label">{t('replay.filterSource')}</label>
                    <select className="form-select" value={source} onChange={e => withReset(setSource)(e.target.value)} data-testid="replay-source-filter">
                        <option value="">{t('replay.anySource')}</option>
                        <option value="google">Google</option>
                        <option value="bing">Bing</option>
                        <option value="yandex">Yandex</option>
                        <option value="social">{t('replay.sourceSocial')}</option>
                        <option value="direct">{t('replay.sourceDirect')}</option>
                    </select>
                </div>
                <div>
                    <label className="form-label">{t('replay.minDuration')}</label>
                    <select className="form-select" value={minSeconds} onChange={e => withReset(setMinSeconds)(Number(e.target.value))}>
                        <option value={0}>{t('replay.anyDuration')}</option>
                        <option value={5}>≥ 5 s</option>
                        <option value={15}>≥ 15 s</option>
                        <option value={30}>≥ 30 s</option>
                        <option value={60}>≥ 1 min</option>
                    </select>
                </div>
                <div className="w-[190px]">
                    <label className="form-label">{t('replay.filterPage')}</label>
                    <input
                        type="text"
                        className="form-input"
                        placeholder="/pricing"
                        value={pageQuery}
                        onChange={e => withReset(setPageQuery)(e.target.value)}
                        data-testid="replay-page-filter"
                    />
                </div>
                <div className="flex flex-wrap items-center gap-3 pb-2 text-sm text-[var(--color-text-secondary)]">
                    <label className="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" checked={onlyOffer} onChange={e => withReset(setOnlyOffer)(e.target.checked)} />{t('replay.onlyOffer')}</label>
                    <label className="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" checked={onlyConverted} onChange={e => withReset(setOnlyConverted)(e.target.checked)} />{t('replay.onlyConverted')}</label>
                    <label className="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" checked={onlyRage} onChange={e => withReset(setOnlyRage)(e.target.checked)} />{t('replay.onlyRage')}</label>
                    <label className="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" checked={multiPage} onChange={e => withReset(setMultiPage)(e.target.checked)} />{t('replay.onlyMultiPage')}</label>
                </div>
                <div className="flex-1" />
                <div className="flex items-center gap-2 pb-0.5">
                    <button type="button" className="btn btn-secondary text-xs flex items-center gap-1.5" onClick={() => setReloadKey(k => k + 1)} title={t('replay.refresh')} aria-label={t('replay.refresh')}>
                        <RefreshCw size={14} />
                    </button>
                    <button
                        type="button"
                        className="btn btn-secondary text-xs flex items-center gap-1.5"
                        disabled={!landingId && !campaignId}
                        title={(!landingId && !campaignId) ? t('replay.heatmapPickLanding') : ''}
                        onClick={() => setHeatmapFor(
                            landingId
                                ? { landingId: Number(landingId), landingName: selectedLanding?.name || '' }
                                : { campaignId: Number(campaignId), campaignName: selectedCampaign?.name || '' }
                        )}
                    >
                        <Flame size={14} /> {t('replay.openHeatmap')}
                    </button>
                    <button type="button" className="btn btn-secondary text-xs flex items-center gap-1.5" onClick={() => setShowStorage(s => !s)}>
                        <HardDrive size={14} /> {storage ? `${formatBytes(storage.used_bytes)} / ${formatBytes(storage.max_mb * 1024 * 1024)}` : t('replay.storageTitle')}
                    </button>
                </div>
            </div>

            {showStorage && (
                <div className="space-y-2">
                    <ReplayStorageCard storage={storage} onChange={(s) => { setStorage(s); setReloadKey(k => k + 1); }} />
                    <div className="flex justify-end gap-2">
                        {landingId && (
                            <button type="button" className="btn btn-secondary text-xs" onClick={() => setConfirm({ landing_id: Number(landingId) })}>
                                {t('replay.deleteLanding')}
                            </button>
                        )}
                        <button type="button" className="btn btn-secondary text-xs" style={{ color: 'rgb(220,38,38)' }} onClick={() => setConfirm({ all: true })}>
                            {t('replay.deleteAll')}
                        </button>
                    </div>
                </div>
            )}

            {/* Empty states */}
            {noRecordingsAtAll && (
                <div className="rounded-xl border p-6 text-center space-y-3" style={{ borderColor: 'var(--color-border)' }}>
                    <Video className="w-10 h-10 mx-auto" style={{ color: 'var(--color-primary)' }} />
                    <div className="font-semibold text-[var(--color-text-primary)]">{t('replay.emptyTitle')}</div>
                    <p className="text-sm text-[var(--color-text-secondary)] max-w-xl mx-auto m-0">{t('replay.emptyText')}</p>
                    <button type="button" className="btn btn-primary" onClick={goToLandings}>{t('replay.emptyCta')}</button>
                </div>
            )}
            {!loading && rows.length === 0 && (landings.length > 0 || campaigns.length > 0) && (
                <div className="rounded-xl border p-6 text-center text-sm text-[var(--color-text-secondary)]" style={{ borderColor: 'var(--color-border)' }}>
                    {anyEnabled ? t('replay.waitingFirst') : t('replay.noMatch')}
                </div>
            )}

            {/* Bulk actions */}
            {selectedCount > 0 && (
                <div className="flex flex-wrap items-center gap-3 px-4 py-2.5 rounded-xl text-sm" style={{ background: 'var(--color-primary-light)', color: 'var(--color-text-primary)' }} data-testid="replay-bulk-bar">
                    <span className="font-semibold">{t('replay.selectedCount', { count: selectedCount })}</span>
                    {!allMatching && pageAllSelected && total > selected.size && (
                        <button type="button" className="underline font-medium" style={{ color: 'var(--color-primary)' }} onClick={() => setAllMatching(true)}>
                            {t('replay.selectAllMatching', { total })}
                        </button>
                    )}
                    {allMatching && <span className="text-xs text-[var(--color-text-secondary)]">{t('replay.allMatchingHint')}</span>}
                    <div className="flex-1" />
                    <button type="button" className="btn btn-secondary text-xs flex items-center gap-1.5" onClick={clearSelection}>
                        <X size={14} /> {t('replay.clearSelection')}
                    </button>
                    <button type="button" className="btn btn-primary text-xs flex items-center gap-1.5" style={{ background: 'rgb(220,38,38)', borderColor: 'rgb(220,38,38)' }} onClick={askDeleteSelected} data-testid="replay-delete-selected">
                        <Trash2 size={14} /> {t('replay.deleteSelected', { count: selectedCount })}
                    </button>
                </div>
            )}

            {/* Table */}
            {(loading || rows.length > 0) && (
                <div className="overflow-x-auto rounded-lg border border-[var(--color-border)]">
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th style={{ width: 40 }}>
                                    <input
                                        type="checkbox"
                                        aria-label={t('replay.selectPage')}
                                        title={t('replay.selectPage')}
                                        checked={pageAllSelected}
                                        ref={el => { if (el) el.indeterminate = pageSomeSelected; }}
                                        onChange={togglePage}
                                        data-testid="replay-select-page"
                                    />
                                </th>
                                <th style={{ width: 52 }} />
                                <SortableTh colKey="created_at" label={t('replay.colTime')} defaultDir="desc" sortBy={sortBy} requestSort={requestSort} />
                                <SortableTh colKey="country" label={t('replay.colVisitor')} sortBy={sortBy} requestSort={requestSort} />
                                <SortableTh colKey="landing" label={t('replay.colLanding')} sortBy={sortBy} requestSort={requestSort} />
                                <SortableTh colKey="duration" label={t('replay.colDuration')} defaultDir="desc" sortBy={sortBy} requestSort={requestSort} />
                                <SortableTh colKey="clicks" label={t('replay.colClicks')} defaultDir="desc" sortBy={sortBy} requestSort={requestSort} />
                                <SortableTh colKey="scroll" label={t('replay.colScroll')} defaultDir="desc" sortBy={sortBy} requestSort={requestSort} />
                                <SortableTh colKey="result" label={t('replay.colResult')} defaultDir="desc" sortBy={sortBy} requestSort={requestSort} />
                                <SortableTh colKey="size" label={t('replay.colSize')} defaultDir="desc" sortBy={sortBy} requestSort={requestSort} />
                                <th style={{ width: 44 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {loading && (
                                <tr><td colSpan={11} className="text-center py-6 text-[var(--color-text-muted)]"><Loader2 className="w-4 h-4 animate-spin inline mr-2" />{t('replay.playerLoading')}</td></tr>
                            )}
                            {!loading && rows.map(r => {
                                const cc = r.country_code || r.country || '';
                                const live = r.status === 'recording' && r.updated_at && (Date.now() - new Date(String(r.updated_at).replace(' ', 'T') + 'Z').getTime()) < 120000;
                                return (
                                    <tr key={r.click_id} className="cursor-pointer" onClick={() => setPlaying(r.click_id)} style={(allMatching || selected.has(r.click_id)) ? { background: 'var(--color-primary-light)' } : undefined}>
                                        <td onClick={(e) => e.stopPropagation()}>
                                            <input
                                                type="checkbox"
                                                aria-label={t('replay.selectRow')}
                                                checked={allMatching || selected.has(r.click_id)}
                                                onChange={() => toggleOne(r.click_id)}
                                            />
                                        </td>
                                        <td>
                                            <button type="button" className="w-8 h-8 rounded-full flex items-center justify-center" style={{ background: 'var(--color-primary)', color: 'var(--color-text-inverse)' }} aria-label={t('replay.play')}>
                                                <Play size={14} />
                                            </button>
                                        </td>
                                        <td className="whitespace-nowrap text-xs">
                                            {formatUtcDateTime(r.created_at, language, timezone)}
                                            {live && <span className="ml-2 px-1.5 py-0.5 rounded text-[10px] font-bold" style={{ background: 'rgba(239,68,68,.15)', color: 'rgb(220,38,38)' }}>LIVE</span>}
                                            <div className="font-mono text-[10px] text-[var(--color-text-muted)]">{r.click_id}</div>
                                        </td>
                                        <td className="text-xs whitespace-nowrap">
                                            <div>{cc ? `${flagEmoji(cc)} ${cc}` : '—'}{r.city ? ` · ${r.city}` : ''}</div>
                                            <div className="flex items-center gap-1 text-[var(--color-text-muted)]">
                                                {r.device === 'mobile' ? <Smartphone size={12} /> : <Monitor size={12} />}
                                                {[r.os, r.browser].filter(Boolean).join(' · ') || `${r.viewport_w || '?'}×${r.viewport_h || '?'}`}
                                            </div>
                                        </td>
                                        <td className="text-xs max-w-[220px]">
                                            <div className="truncate" title={r.landing_name || r.campaign_name || ''}>{r.landing_name || r.campaign_name || `#${r.landing_id || r.campaign_id}`}</div>
                                            {!r.landing_name && <div className="truncate text-[var(--color-text-muted)]" title={r.campaign_name || ''}>{r.campaign_name || ''}</div>}
                                            {Number(r.pages) > 1 && (
                                                <div className="truncate text-[10px] text-[var(--color-text-muted)]" title={t('replay.pagesHint', { count: r.pages })}>
                                                    {t('replay.pagesBadge', { count: r.pages })}: {pathOf(r.entry_url)} → {pathOf(r.page_url)}
                                                </div>
                                            )}
                                        </td>
                                        <td className="text-xs font-mono whitespace-nowrap">{formatDuration(r.duration_ms)}</td>
                                        <td className="text-xs whitespace-nowrap">
                                            {r.clicks}
                                            {Number(r.rage_clicks) > 0 && (
                                                <span className="ml-1.5 inline-flex items-center gap-0.5 px-1.5 rounded-full text-[10px] font-semibold" style={{ background: 'rgba(239,68,68,.15)', color: 'rgb(220,38,38)' }} title={t('replay.rageHint')}>
                                                    <Flame size={10} />{r.rage_clicks}
                                                </span>
                                            )}
                                        </td>
                                        <td className="text-xs">{r.lp_scroll !== null && r.lp_scroll !== undefined ? `${r.lp_scroll}%` : '—'}</td>
                                        <td className="text-xs whitespace-nowrap">
                                            {Number(r.is_conversion) > 0 ? (
                                                <span className="px-2 py-0.5 rounded-full font-semibold" style={{ background: 'rgba(34,197,94,.15)', color: 'rgb(22,163,74)' }}>{t('replay.converted')}</span>
                                            ) : r.went_to_offer ? (
                                                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-semibold" style={{ background: 'var(--color-primary-light)', color: 'var(--color-primary)' }}><Target size={10} />{t('replay.wentToOffer')}</span>
                                            ) : (
                                                <span className="text-[var(--color-text-muted)]">{t('replay.stayed')}</span>
                                            )}
                                        </td>
                                        <td className="text-xs text-[var(--color-text-muted)] whitespace-nowrap">{formatBytes(r.bytes)}</td>
                                        <td>
                                            <button
                                                type="button"
                                                className="action-btn"
                                                onClick={(e) => { e.stopPropagation(); setConfirm({ ids: [r.click_id], count: 1 }); }}
                                                aria-label={t('replay.delete')}
                                                title={t('replay.delete')}
                                            >
                                                <Trash2 className="w-4 h-4" />
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            {!loading && rows.length > 0 && (
                <PaginationToolbar
                    totalRows={total}
                    currentPage={page}
                    pageSize={pageSize === 'All' && total > ALL_LIMIT ? ALL_LIMIT : pageSize}
                    onPageChange={(p) => setPage(p)}
                    onPageSizeChange={(size) => { setPageSize(size); setPage(0); }}
                />
            )}

            {playing && (
                <ReplayPlayerModal
                    clickId={playing}
                    onClose={() => setPlaying(null)}
                    onDeleted={() => setReloadKey(k => k + 1)}
                />
            )}
            {heatmapFor && (
                <HeatmapModal
                    landingId={heatmapFor.landingId}
                    landingName={heatmapFor.landingName}
                    campaignId={heatmapFor.campaignId}
                    campaignName={heatmapFor.campaignName}
                    onClose={() => setHeatmapFor(null)}
                />
            )}
            <Dialog
                open={!!confirm}
                title={dialogTitle}
                message={dialogText}
                confirmLabel={t('replay.delete')}
                cancelLabel={t('common.cancel', 'Cancel')}
                danger
                busy={busy}
                onConfirm={doDelete}
                onClose={() => setConfirm(null)}
            />
        </div>
    );
};

export default SessionReplays;
