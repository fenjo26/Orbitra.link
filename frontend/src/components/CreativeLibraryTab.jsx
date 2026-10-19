import React, { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import {
    AlertTriangle, Download, Film, Image as ImageIcon, Play, Search, Table, LayoutGrid, X
} from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

// The Creatives tab of the media library (docs stage 1): platform-synced
// creatives with the creative_daily metrics aggregate — spend with the agency
// fee, revenue, ROI (a dash, never −100%, while nothing came back), the ads
// that ran the file and the near-duplicate family. Everything reads the
// creatives_* API family; the worker banner explains why previews may lag.
const CreativeLibraryTab = () => {
    const { t } = useLanguage();

    const [rows, setRows] = useState([]);
    const [totals, setTotals] = useState(null);
    const [worker, setWorker] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const [dateFrom, setDateFrom] = useState(() => {
        const d = new Date(); d.setDate(d.getDate() - 6);
        return d.toISOString().split('T')[0];
    });
    const [dateTo, setDateTo] = useState(() => new Date().toISOString().split('T')[0]);
    const [platform, setPlatform] = useState('');
    const [kind, setKind] = useState('');
    const [tag, setTag] = useState('');
    const [q, setQ] = useState('');
    const [sort, setSort] = useState('spend');
    const [activeOnly, setActiveOnly] = useState(false);
    const [view, setView] = useState('grid');

    const [detail, setDetail] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [tagDraft, setTagDraft] = useState('');

    const fmtMoney = (v) => (v === null || v === undefined) ? '—' : `$${Number(v).toFixed(2)}`;
    const fmtPct = (v) => (v === null || v === undefined) ? '—' : `${Number(v) > 0 ? '+' : ''}${Number(v).toFixed(1)}%`;
    const roiColor = (v) => {
        if (v === null || v === undefined) return 'var(--color-text-muted)';
        if (v >= 30) return 'var(--color-success)';
        if (v >= 0) return 'var(--color-warning, #f59e0b)';
        return 'var(--color-danger)';
    };

    const load = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const params = { action: 'creatives_list', date_from: dateFrom, date_to: dateTo, sort };
            if (platform) params.platform = platform;
            if (kind) params.kind = kind;
            if (tag.trim()) params.tag = tag.trim();
            if (q.trim()) params.q = q.trim();
            if (activeOnly) params.active_only = 1;
            const res = await axios.get(API_URL, { params });
            if (res.data?.status === 'success') {
                setRows(res.data.data || []);
                setTotals(res.data.totals || null);
                setWorker(res.data.worker || null);
            } else {
                setError(res.data?.message || t('creatives.errLoad', 'Failed to load creatives'));
            }
        } catch (err) {
            setError(err?.response?.data?.message || err.message);
        } finally {
            setLoading(false);
        }
    }, [dateFrom, dateTo, platform, kind, tag, q, sort, activeOnly, t]);

    useEffect(() => { load(); }, [load]);

    const openDetail = async (id) => {
        setDetailLoading(true);
        setDetail(null);
        try {
            const res = await axios.get(API_URL, { params: { action: 'creative_detail', id, date_from: dateFrom, date_to: dateTo } });
            if (res.data?.status === 'success') {
                setDetail(res.data.data);
                setTagDraft((res.data.data.tags || []).join(', '));
            }
        } catch (err) {
            setError(err?.response?.data?.message || err.message);
        } finally {
            setDetailLoading(false);
        }
    };

    const saveTags = async () => {
        if (!detail) return;
        const tags = tagDraft.split(',').map(s => s.trim()).filter(Boolean);
        try {
            const res = await axios.post(API_URL, { id: detail.id, tags }, { params: { action: 'creative_tag_op' } });
            if (res.data?.status === 'success') {
                setDetail({ ...detail, tags: res.data.data.tags });
                load();
            }
        } catch (err) {
            setError(err?.response?.data?.message || err.message);
        }
    };

    const workerStalled = worker && worker.pending > 0
        && (!worker.last_run || (worker.oldest_pending_min !== null && worker.oldest_pending_min > 15));

    const maxSeriesSpend = detail ? Math.max(1, ...(detail.series || []).map(s => Number(s.spend) + Number(s.fee))) : 1;

    const selectStyle = { padding: '6px 8px', borderRadius: 8, border: '1px solid var(--color-border)', background: 'var(--color-bg-card)', color: 'var(--color-text-primary)', fontSize: '0.8rem' };

    return (
        <div>
            {/* Worker banner: files queued but the fetch worker has not run. */}
            {workerStalled && (
                <div className="mb-3 flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm"
                     style={{ borderColor: 'color-mix(in srgb, var(--color-warning, #f59e0b) 40%, transparent)', backgroundColor: 'color-mix(in srgb, var(--color-warning, #f59e0b) 8%, transparent)' }}>
                    <AlertTriangle className="h-4 w-4 flex-shrink-0" style={{ color: 'var(--color-warning, #f59e0b)' }} />
                    {t('creatives.workerStalled', 'Files are queued but the creative worker has not run lately — previews will not arrive. Check the cron: php cli/creative_fetch_cron.php')}
                </div>
            )}

            {/* Filters */}
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <input type="date" value={dateFrom} onChange={e => setDateFrom(e.target.value)} className="input" style={{ ...selectStyle }} />
                <span style={{ color: 'var(--color-text-muted)' }}>→</span>
                <input type="date" value={dateTo} onChange={e => setDateTo(e.target.value)} className="input" style={{ ...selectStyle }} />
                <select value={platform} onChange={e => setPlatform(e.target.value)} style={selectStyle}>
                    <option value="">{t('creatives.allPlatforms', 'All platforms')}</option>
                    <option value="facebook">Facebook</option>
                    <option value="tiktok">TikTok</option>
                </select>
                <select value={kind} onChange={e => setKind(e.target.value)} style={selectStyle}>
                    <option value="">{t('creatives.allFormats', 'All formats')}</option>
                    <option value="image">{t('creatives.images', 'Images')}</option>
                    <option value="video">{t('creatives.videos', 'Videos')}</option>
                </select>
                <select value={sort} onChange={e => setSort(e.target.value)} style={selectStyle}>
                    <option value="spend">{t('creatives.sortBySpend', 'By spend')}</option>
                    <option value="revenue">{t('creatives.sortByRevenue', 'By revenue')}</option>
                    <option value="roi">{t('creatives.sortByRoi', 'By ROI')}</option>
                    <option value="clicks">{t('creatives.sortByClicks', 'By clicks')}</option>
                </select>
                <div className="flex items-center gap-1.5 rounded-lg border px-2" style={{ borderColor: 'var(--color-border)' }}>
                    <Search className="h-3.5 w-3.5" style={{ color: 'var(--color-text-muted)' }} />
                    <input value={q} onChange={e => setQ(e.target.value)} placeholder={t('creatives.searchPh', 'Name…')}
                           style={{ border: 'none', background: 'transparent', color: 'var(--color-text-primary)', fontSize: '0.8rem', padding: '6px 0', width: 120, outline: 'none' }} />
                </div>
                <input value={tag} onChange={e => setTag(e.target.value)} placeholder={t('creatives.tagPh', 'Tag…')}
                       style={{ ...selectStyle, width: 100 }} />
                <label className="flex items-center gap-1.5 text-xs" style={{ color: 'var(--color-text-secondary)', cursor: 'pointer' }}>
                    <input type="checkbox" checked={activeOnly} onChange={e => setActiveOnly(e.target.checked)} />
                    {t('creatives.activeOnly', 'Active only')}
                </label>
                <div className="ml-auto flex items-center gap-1">
                    <button type="button" onClick={() => setView('grid')} className="btn btn-sm" style={{ opacity: view === 'grid' ? 1 : 0.5 }} title={t('creatives.gridView', 'Grid')}>
                        <LayoutGrid className="h-4 w-4" />
                    </button>
                    <button type="button" onClick={() => setView('table')} className="btn btn-sm" style={{ opacity: view === 'table' ? 1 : 0.5 }} title={t('creatives.tableView', 'Table')}>
                        <Table className="h-4 w-4" />
                    </button>
                </div>
            </div>

            {/* KPI cards */}
            {totals && (
                <div className="mb-4 grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))' }}>
                    {[
                        { label: t('creatives.kpiSpend', 'Spend + fee'), value: fmtMoney(totals.spend_total) },
                        { label: t('creatives.kpiRevenue', 'Revenue'), value: fmtMoney(totals.revenue) },
                        { label: t('creatives.kpiProfit', 'Profit'), value: fmtMoney(totals.profit) },
                        { label: 'ROI', value: fmtPct(totals.roi), color: roiColor(totals.roi) },
                        { label: t('creatives.kpiCreatures', 'Creatives'), value: totals.count ?? 0 },
                    ].map(card => (
                        <div key={card.label} className="card p-3 text-center">
                            <div className="text-[11px]" style={{ color: 'var(--color-text-muted)' }}>{card.label}</div>
                            <div className="text-lg font-bold" style={{ color: card.color || 'var(--color-text-primary)' }}>{card.value}</div>
                        </div>
                    ))}
                </div>
            )}

            {error && <div className="alert alert-danger mb-3">{error}</div>}
            {loading && <div className="py-10 text-center text-sm" style={{ color: 'var(--color-text-muted)' }}>{t('common.loading', 'Loading…')}</div>}

            {!loading && rows.length === 0 && (
                <div className="card p-10 text-center" style={{ color: 'var(--color-text-muted)' }}>
                    {t('creatives.empty', 'No creatives yet. They appear here after a Facebook/TikTok cost sync pulls what your ads run.')}
                </div>
            )}

            {/* Grid */}
            {!loading && view === 'grid' && rows.length > 0 && (
                <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))' }}>
                    {rows.map(r => (
                        <div key={r.id} role="button" tabIndex={0}
                             onClick={() => openDetail(r.id)}
                             onKeyDown={e => { if (e.key === 'Enter') openDetail(r.id); }}
                             className="flex cursor-pointer flex-col rounded-xl border p-2 transition"
                             style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-card)' }}>
                            <div className="relative mb-1.5 flex h-28 items-center justify-center overflow-hidden rounded-lg" style={{ backgroundColor: 'var(--color-bg-soft)' }}>
                                {r.kind === 'video'
                                    ? <>
                                        <img src={r.thumb_url || r.url} alt={r.name} className="max-h-28 max-w-full object-contain opacity-80" loading="lazy" />
                                        <span className="absolute flex h-8 w-8 items-center justify-center rounded-full" style={{ background: 'rgba(0,0,0,0.55)' }}>
                                            <Play className="h-4 w-4 text-white" />
                                        </span>
                                      </>
                                    : <img src={r.url} alt={r.name} className="max-h-28 max-w-full object-contain" loading="lazy" />}
                                <span className="absolute left-1 top-1 rounded px-1.5 py-0.5 text-[9px] font-bold uppercase" style={{ background: 'rgba(0,0,0,0.5)', color: '#fff' }}>
                                    {r.platform === 'facebook' ? 'FB' : 'TT'}
                                </span>
                            </div>
                            <div className="truncate text-xs font-medium" title={r.name} style={{ color: 'var(--color-text-primary)' }}>{r.name}</div>
                            <div className="mt-1 flex items-center justify-between text-[11px]">
                                <span style={{ color: 'var(--color-text-muted)' }}>{fmtMoney(r.spend_total)}</span>
                                <span style={{ color: roiColor(r.roi), fontWeight: 600 }}>{fmtPct(r.roi)}</span>
                            </div>
                            <div className="mt-0.5 flex flex-wrap gap-1">
                                {(r.tags || []).slice(0, 3).map(tg => (
                                    <span key={tg} className="rounded px-1.5 py-0.5 text-[9px]" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-muted)' }}>{tg}</span>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* Table */}
            {!loading && view === 'table' && rows.length > 0 && (
                <div className="table-container">
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th style={{ textAlign: 'left' }}>{t('creatives.colCreative', 'Creative')}</th>
                                <th>{t('creatives.colPlatform', 'Platform')}</th>
                                <th>{t('creatives.colSpend', 'Spend + fee')}</th>
                                <th>{t('creatives.colRevenue', 'Revenue')}</th>
                                <th>{t('creatives.colProfit', 'Profit')}</th>
                                <th>ROI</th>
                                <th>CTR</th>
                                <th>CPM</th>
                                <th>CPC</th>
                                <th>{t('creatives.colClicks', 'Clicks')}</th>
                                <th>{t('creatives.colInstalls', 'Installs')}</th>
                                <th>{t('creatives.colRegs', 'Regs')}</th>
                                <th>FTD</th>
                                <th>{t('creatives.colAds', 'Ads')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map(r => (
                                <tr key={r.id} style={{ cursor: 'pointer' }} onClick={() => openDetail(r.id)}>
                                    <td style={{ textAlign: 'left' }}>
                                        <div className="flex items-center gap-2">
                                            {r.kind === 'video'
                                                ? <Film className="h-4 w-4 flex-shrink-0" style={{ color: 'var(--color-text-muted)' }} />
                                                : <ImageIcon className="h-4 w-4 flex-shrink-0" style={{ color: 'var(--color-text-muted)' }} />}
                                            <span className="max-w-[220px] truncate" title={r.name}>{r.name}</span>
                                        </div>
                                    </td>
                                    <td>{r.platform === 'facebook' ? 'Facebook' : 'TikTok'}</td>
                                    <td>{fmtMoney(r.spend_total)}</td>
                                    <td>{fmtMoney(r.revenue)}</td>
                                    <td>{fmtMoney(r.profit)}</td>
                                    <td style={{ color: roiColor(r.roi), fontWeight: 600 }}>{fmtPct(r.roi)}</td>
                                    <td>{r.ctr === null || r.ctr === undefined ? '—' : `${r.ctr}%`}</td>
                                    <td>{fmtMoney(r.cpm)}</td>
                                    <td>{fmtMoney(r.cpc)}</td>
                                    <td>{r.tracker_clicks ?? 0}</td>
                                    <td>{r.installs ?? 0}</td>
                                    <td>{r.registrations ?? 0}</td>
                                    <td>{r.ftd ?? 0}</td>
                                    <td>{r.ads_count ?? 0}{(r.active_ads ?? 0) > 0 ? ` (${r.active_ads})` : ''}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Detail modal */}
            {(detail || detailLoading) && (
                <div className="modal-overlay" onClick={() => { setDetail(null); setDetailLoading(false); }}>
                    <div className="modal-content" onClick={e => e.stopPropagation()} style={{ maxWidth: 760, maxHeight: '88vh', overflow: 'auto' }}>
                        {detailLoading || !detail ? (
                            <div className="py-10 text-center text-sm" style={{ color: 'var(--color-text-muted)' }}>{t('common.loading', 'Loading…')}</div>
                        ) : (
                            <>
                                <div className="mb-3 flex items-start justify-between gap-3">
                                    <h3 className="m-0 flex items-center gap-2 text-base font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                        {detail.kind === 'video' ? <Film className="h-5 w-5" /> : <ImageIcon className="h-5 w-5" />}
                                        <span className="max-w-[420px] truncate" title={detail.name}>{detail.name}</span>
                                    </h3>
                                    <button type="button" className="btn-icon" onClick={() => setDetail(null)}><X className="h-5 w-5" /></button>
                                </div>

                                <div className="mb-4 flex flex-wrap items-start gap-4">
                                    <div className="flex h-36 w-48 flex-shrink-0 items-center justify-center overflow-hidden rounded-lg" style={{ background: 'var(--color-bg-soft)' }}>
                                        <img src={detail.thumb_url || detail.url} alt={detail.name} className="max-h-36 max-w-full object-contain" />
                                    </div>
                                    <div className="grid flex-1 gap-2" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(110px, 1fr))' }}>
                                        {[
                            { label: t('creatives.colSpend', 'Spend + fee'), value: fmtMoney(detail.series?.reduce((s, d) => s + Number(d.spend) + Number(d.fee), 0)) },
                            { label: t('creatives.colRevenue', 'Revenue'), value: fmtMoney(detail.series?.reduce((s, d) => s + Number(d.revenue), 0)) },
                            { label: 'ROI', value: fmtPct(detail.series?.reduce((s, d) => s + Number(d.revenue), 0) > 0 && detail.series?.reduce((s, d) => s + Number(d.spend) + Number(d.fee), 0) > 0
                                    ? (detail.series.reduce((s, d) => s + Number(d.revenue), 0) - detail.series.reduce((s, d) => s + Number(d.spend) + Number(d.fee), 0)) / detail.series.reduce((s, d) => s + Number(d.spend) + Number(d.fee), 0) * 100
                                    : null) },
                                        ].map(card => (
                                            <div key={card.label} className="card p-2 text-center">
                                                <div className="text-[10px]" style={{ color: 'var(--color-text-muted)' }}>{card.label}</div>
                                                <div className="text-sm font-bold" style={{ color: 'var(--color-text-primary)' }}>{card.value}</div>
                                            </div>
                                        ))}
                                    </div>
                                    <a href={detail.url} download className="btn btn-sm" style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                                        <Download className="h-3.5 w-3.5" /> {t('creatives.download', 'Download')}
                                    </a>
                                </div>

                                {/* Daily series: spend bars with an ROI line value on top. */}
                                {detail.series?.length > 0 && (
                                    <div className="mb-4">
                                        <div className="mb-1.5 text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('creatives.byDay', 'By day')}</div>
                                        <div className="flex items-end gap-1" style={{ height: 80 }}>
                                            {detail.series.map(d => {
                                                const spend = Number(d.spend) + Number(d.fee);
                                                const roi = Number(d.revenue) > 0 && spend > 0 ? (Number(d.revenue) - spend) / spend * 100 : null;
                                                return (
                                                    <div key={d.day} className="flex flex-1 flex-col items-center gap-1" title={`${d.day}: ${fmtMoney(spend)} · ROI ${fmtPct(roi)}`}>
                                                        <span className="text-[9px]" style={{ color: roiColor(roi) }}>{roi === null ? '' : Math.round(roi)}</span>
                                                        <div className="w-full rounded-t" style={{ height: Math.max(2, spend / maxSeriesSpend * 55), background: 'var(--color-primary)', opacity: 0.75 }} />
                                                        <span className="text-[8px]" style={{ color: 'var(--color-text-muted)' }}>{d.day.slice(5)}</span>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}

                                {/* Tags */}
                                <div className="mb-4">
                                    <div className="mb-1 text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('creatives.tags', 'Tags')}</div>
                                    <div className="flex gap-2">
                                        <input value={tagDraft} onChange={e => setTagDraft(e.target.value)}
                                               placeholder={t('creatives.tagsPh', 'offer, geo, approach, creator — comma separated')}
                                               className="input" style={{ fontSize: '0.8rem' }} />
                                        <button type="button" className="btn btn-primary btn-sm" onClick={saveTags}>{t('common.save', 'Save')}</button>
                                    </div>
                                </div>

                                {/* Ads that ran it */}
                                {detail.usages?.length > 0 && (
                                    <div className="mb-4">
                                        <div className="mb-1.5 text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('creatives.adsRan', 'Ads that ran it')}</div>
                                        <div className="table-container" style={{ maxHeight: 200, overflow: 'auto' }}>
                                            <table className="page-table">
                                                <thead>
                                                    <tr>
                                                        <th style={{ textAlign: 'left' }}>{t('creatives.adName', 'Ad')}</th>
                                                        <th>{t('creatives.adStatus', 'Status')}</th>
                                                        <th>{t('creatives.adSpend', 'Spend')}</th>
                                                        <th style={{ textAlign: 'left' }}>{t('creatives.adText', 'Ad text')}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {detail.usages.map((u, i) => (
                                                        <tr key={(u.ad_id || '') + i}>
                                                            <td style={{ textAlign: 'left' }}>
                                                                <div style={{ fontWeight: 600 }}>{u.ad_name || u.ad_id}</div>
                                                                <div className="text-[10px]" style={{ color: 'var(--color-text-muted)' }}>
                                                                    {t('creatives.via', 'via')} {u.campaign_id || '—'} › {u.adset_id || '—'}
                                                                </div>
                                                            </td>
                                                            <td>{u.ad_status || '—'}</td>
                                                            <td>{fmtMoney(u.ad_spend)}</td>
                                                            <td style={{ textAlign: 'left', maxWidth: 260 }}>
                                                                <div className="truncate" title={`${u.ad_title || ''} ${u.ad_text || ''}`}>
                                                                    {u.ad_title ? <b>{u.ad_title}. </b> : ''}{u.ad_text || '—'}
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                )}

                                {/* Family */}
                                {detail.family?.length > 0 && (
                                    <div>
                                        <div className="mb-1.5 text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('creatives.family', 'Similar creatives')}</div>
                                        <div className="flex flex-wrap gap-2">
                                            {detail.family.map(f => (
                                                <div key={f.id} className="flex items-center gap-2 rounded-lg border p-1.5" style={{ borderColor: 'var(--color-border)' }}>
                                                    <img src={f.url} alt={f.name} className="h-10 w-10 rounded object-cover" loading="lazy" />
                                                    <span className="max-w-[160px] truncate text-[11px]" title={f.name}>{f.name}</span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
};

export default CreativeLibraryTab;
