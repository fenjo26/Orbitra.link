import React, { useState, useEffect, useMemo } from 'react';
import axios from 'axios';
import { X, Download, Filter, BarChart3, Plus, Trash2 } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

// Layered reporting (Keitaro-style): stack up to 3 group-by dimensions —
// e.g. Country → Campaign → adset_id — and read sales/profit down the tree.
// campaignId may be null, which means "all campaigns" (campaign is then a
// useful layer).
const CampaignReports = ({ campaignId, campaignName, onClose }) => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [rows, setRows] = useState([]);
    const [layerKeys, setLayerKeys] = useState([]);
    const defaultLayers = campaignId ? ['country', 'adset_id'] : ['country', 'campaign_id', 'adset_id'];
    const [layers, setLayers] = useState(defaultLayers);
    const [dateFrom, setDateFrom] = useState(() => {
        const d = new Date(); d.setDate(d.getDate() - 7);
        return d.toISOString().split('T')[0];
    });
    const [dateTo, setDateTo] = useState(() => new Date().toISOString().split('T')[0]);

    const dimensions = [
        { value: 'country', label: t('campaignReports.geoCountry') },
        { value: 'campaign_id', label: t('campaignReports.campaign') },
        { value: 'adset_id', label: t('campaignReports.adsetId', 'Adset ID') },
        { value: 'ad_id', label: t('campaignReports.adId', 'Ad ID') },
        { value: 'ad_campaign_id', label: t('campaignReports.adCampaignId', 'Ad Campaign ID') },
        { value: 'offer_id', label: t('campaignReports.offer', 'Offer') },
        { value: 'landing_id', label: t('campaignReports.landing', 'Landing') },
        { value: 'stream_id', label: t('campaignReports.stream') },
        { value: 'source_id', label: t('campaignReports.source') },
        { value: 'device_type', label: t('campaignReports.deviceType') },
        { value: 'os', label: 'OS' },
        { value: 'browser', label: t('campaignReports.browser', 'Browser') },
        { value: 'language', label: t('campaignReports.language') },
        { value: 'day', label: t('campaignReports.day', 'Day') },
        ...Array.from({ length: 10 }, (_, i) => ({ value: 'sub_id_' + (i + 1), label: 'Sub ID ' + (i + 1) })),
    ];
    const dimLabel = (v) => dimensions.find(d => d.value === v)?.label || v;

    const fetchReport = async () => {
        setLoading(true);
        try {
            const params = { group_by: layers.join(','), date_from: dateFrom, date_to: dateTo };
            if (campaignId) params.campaign_id = campaignId;
            const res = await axios.get(`${API_URL}?action=campaign_report`, { params });
            if (res.data.status === 'success') {
                setRows(res.data.data.rows || []);
                setLayerKeys(res.data.data.layers || layers);
            } else {
                alert(t('campaignReports.loadError') + res.data.message);
            }
        } catch (e) {
            console.error(e);
            alert(t('campaignReports.networkError'));
        } finally { setLoading(false); }
    };

    useEffect(() => { fetchReport(); }, [campaignId, layers.join(','), dateFrom, dateTo]);

    // Build the layered tree from flat rows, then flatten it back into display
    // rows with subtotals per level — the totals are aggregated from the leaves,
    // so they always match what the SQL returned.
    const displayRows = useMemo(() => {
        const agg = { clicks: 0, unique_clicks: 0, conversions: 0, cost: 0, revenue: 0, real_revenue: 0 };
        const add = (node, row) => {
            node.clicks += row.clicks; node.unique_clicks += row.unique_clicks; node.conversions += row.conversions;
            node.cost += row.cost; node.revenue += row.revenue; node.real_revenue += row.real_revenue;
        };
        const root = { ...agg, children: new Map() };
        rows.forEach(row => {
            let node = root;
            add(root, row);
            row.dims.forEach((dimValue, depth) => {
                if (!node.children.has(dimValue)) {
                    node.children.set(dimValue, { ...agg, children: new Map() });
                }
                node = node.children.get(dimValue);
                add(node, row);
            });
        });
        const out = [];
        const walk = (node, depth, name) => {
            const children = [...node.children.entries()].sort((a, b) => b[1].clicks - a[1].clicks);
            out.push({ name, depth, subtotal: depth < layers.length - 1 || children.length > 0, ...node, childrenCount: children.length });
            children.forEach(([childName, child]) => walk(child, depth + 1, childName));
        };
        [...root.children.entries()].sort((a, b) => b[1].clicks - a[1].clicks).forEach(([name, child]) => walk(child, 0, name));
        return out;
    }, [rows, layers.length]);

    const grandTotal = useMemo(() => {
        const t0 = { clicks: 0, unique_clicks: 0, conversions: 0, cost: 0, revenue: 0, real_revenue: 0 };
        rows.forEach(r => {
            t0.clicks += r.clicks; t0.unique_clicks += r.unique_clicks; t0.conversions += r.conversions;
            t0.cost += r.cost; t0.revenue += r.revenue; t0.real_revenue += r.real_revenue;
        });
        return t0;
    }, [rows]);

    const setLayer = (idx, value) => {
        setLayers(prev => {
            const next = [...prev.slice(0, idx), value, ...prev.slice(idx + 1)].filter(Boolean);
            return next.slice(0, 3);
        });
    };

    const exportToCSV = () => {
        if (!displayRows.length) return;
        const headers = [
            ...layerKeys.map(dimLabel),
            t('campaignReports.clicks'), t('campaignReports.unique'), t('campaignReports.conversions'),
            'CR (%)', t('campaignReports.cost'), t('campaignReports.revenue'), 'Real Rev',
            t('campaignReports.profit'), 'ROI (%)'
        ];
        const csvContent = [
            headers.join(','),
            ...displayRows.map(r => [
                `"${'  '.repeat(r.depth)}${String(r.name).replace(/"/g, '""')}"`,
                r.clicks, r.unique_clicks, r.conversions,
                r.clicks > 0 ? ((r.conversions / r.clicks) * 100).toFixed(2) : '0',
                r.cost.toFixed(2), r.revenue.toFixed(2), r.real_revenue.toFixed(2),
                (r.revenue - r.cost).toFixed(2),
                r.cost > 0 ? (((r.revenue - r.cost) / r.cost) * 100).toFixed(2) : '0'
            ].join(','))
        ].join('\n');
        const bom = new Uint8Array([0xEF, 0xBB, 0xBF]);
        const blob = new Blob([bom, csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.setAttribute('href', URL.createObjectURL(blob));
        link.setAttribute('download', `report_${campaignId || 'all'}_${layerKeys.join('_by_')}_${dateFrom}_to_${dateTo}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link); link.click(); document.body.removeChild(link);
    };

    const num = (v) => Number(v || 0).toLocaleString('ru-RU');
    const money = (v) => Number(v || 0).toFixed(2);
    const profitColor = (p) => p > 0 ? 'var(--color-success)' : p < 0 ? 'var(--color-danger)' : 'inherit';

    const thStyle = { textAlign: 'right', whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums' };
    const tdStyle = { textAlign: 'right', whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums' };

    const renderMetrics = (r, strong) => (
        <>
            <td style={tdStyle} className={strong ? 'font-semibold' : ''}>{num(r.clicks)}</td>
            <td style={tdStyle} className={strong ? 'font-semibold' : ''} >{num(r.unique_clicks)}</td>
            <td style={tdStyle} className={strong ? 'font-semibold' : ''}>{r.conversions > 0 ? <span style={{ color: 'var(--color-success)' }}>{num(r.conversions)}</span> : '0'}</td>
            <td style={tdStyle} className={strong ? 'font-semibold' : ''} >{r.clicks > 0 ? ((r.conversions / r.clicks) * 100).toFixed(2) : '0'}%</td>
            <td style={tdStyle} className={strong ? 'font-semibold' : ''}>{money(r.cost)}</td>
            <td style={tdStyle} className={strong ? 'font-semibold' : ''}>{money(r.revenue)}</td>
            <td style={tdStyle} className={strong ? 'font-semibold' : ''}>{money(r.real_revenue)}</td>
            <td style={{ ...tdStyle, color: profitColor(r.revenue - r.cost) }} className={strong ? 'font-semibold' : 'font-medium'}>
                {r.revenue - r.cost > 0 ? '+' : ''}{money(r.revenue - r.cost)}
            </td>
            <td style={tdStyle} className={strong ? 'font-semibold' : ''}>
                {r.cost > 0 ? (((r.revenue - r.cost) / r.cost) * 100).toFixed(2) + '%' : '—'}
            </td>
        </>
    );

    return (
        <div className="fixed top-[88px] left-0 right-0 bottom-0 z-[1100] flex bg-black bg-opacity-50">
            <div className="flex flex-col w-full h-full bg-[var(--color-bg-main)]">
                <div className="flex justify-between items-center px-6 py-4 border-b shadow-sm" style={{ background: 'var(--color-bg-header)', color: 'var(--color-text-header)', borderColor: 'var(--color-border)' }}>
                    <div className="flex items-center gap-3">
                        <BarChart3 size={20} />
                        <div><h2 className="text-xl font-semibold">{t('campaignReports.report')} {campaignName || t('campaignReports.allCampaigns', 'Все кампании')}</h2></div>
                    </div>
                    <div className="flex gap-3">
                        <button onClick={exportToCSV} className="btn btn-success flex items-center gap-2 text-sm font-medium">
                            <Download size={16} /> {t('campaignReports.exportCsv')}
                        </button>
                        <button onClick={onClose} className="btn btn-ghost btn-icon" title={t('campaignReports.close')}>
                            <X size={24} />
                        </button>
                    </div>
                </div>

                <div className="p-4 bg-[var(--color-bg-card)] border-b shadow-sm flex flex-wrap gap-4 items-center" style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}>
                    <div className="flex items-center gap-2 flex-wrap">
                        <Filter size={16} style={{ color: 'var(--color-text-muted)' }} />
                        <span className="text-sm font-medium">{t('campaignReports.layers', 'Слои группировки')}:</span>
                        {layers.map((layer, idx) => (
                            <div key={idx} className="flex items-center gap-1">
                                <select value={layer} onChange={(e) => setLayer(idx, e.target.value)} className="form-select" style={{ minWidth: '140px' }}>
                                    {dimensions.filter(d => !layers.includes(d.value) || d.value === layer).map(d => (
                                        <option key={d.value} value={d.value}>{idx + 1}. {d.label}</option>
                                    ))}
                                </select>
                                {layers.length > 1 && (
                                    <button type="button" className="btn btn-ghost btn-icon" style={{ padding: '2px' }}
                                        onClick={() => setLayers(prev => prev.filter((_, i) => i !== idx))}
                                        title={t('common.delete')}>
                                        <Trash2 size={14} />
                                    </button>
                                )}
                            </div>
                        ))}
                        {layers.length < 3 && (
                            <button type="button" className="btn btn-secondary" style={{ padding: '4px 10px' }}
                                onClick={() => setLayers(prev => {
                                    const unused = dimensions.find(d => !prev.includes(d.value));
                                    return [...prev, unused ? unused.value : 'ad_id'];
                                })}
                                title={t('campaignReports.addLayer', 'Добавить слой')}>
                                <Plus size={14} />
                            </button>
                        )}
                    </div>
                    <div className="flex items-center gap-2 ml-auto">
                        <span className="text-sm font-medium">{t('campaignReports.period')}</span>
                        <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="form-input" />
                        <span>-</span>
                        <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="form-input" />
                    </div>
                </div>

                <div className="flex-1 overflow-auto p-6" style={{ color: 'var(--color-text-primary)' }}>
                    {loading ? (
                        <div className="flex justify-center items-center h-64">
                            <div className="animate-spin rounded-full h-12 w-12 border-b-2" style={{ borderColor: 'var(--color-primary)' }}></div>
                        </div>
                    ) : (
                        <div className="page-card" style={{ padding: 0, overflow: 'hidden' }}>
                            <table className="page-table" style={{ fontVariantNumeric: 'tabular-nums' }}>
                                <thead>
                                    <tr>
                                        <th style={{ minWidth: '260px' }}>{layerKeys.map(dimLabel).join(' → ')}</th>
                                        <th style={thStyle}>{t('campaignReports.clicks')}</th>
                                        <th style={thStyle}>{t('campaignReports.unique')}</th>
                                        <th style={thStyle}>{t('campaignReports.conversions')}</th>
                                        <th style={thStyle}>CR</th>
                                        <th style={thStyle}>{t('campaignReports.cost')}</th>
                                        <th style={thStyle}>{t('campaignReports.revenue')}</th>
                                        <th style={thStyle}>Real Rev</th>
                                        <th style={thStyle}>{t('campaignReports.profit')}</th>
                                        <th style={thStyle}>ROI</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.length === 0 ? (
                                        <tr><td colSpan="10" className="text-center p-8" style={{ color: 'var(--color-text-muted)' }}>{t('campaignReports.noDataFilters')}</td></tr>
                                    ) : (
                                        <>
                                            <tr className="text-sm" style={{ background: 'var(--color-bg-soft)', position: 'sticky', top: 0 }}>
                                                <td className="font-bold">{t('campaignReports.total', 'Итого')}</td>
                                                {renderMetrics(grandTotal, true)}
                                            </tr>
                                            {displayRows.map((r, idx) => {
                                                const isSubtotal = r.depth < layers.length - 1;
                                                return (
                                                    <tr key={idx} className="text-sm" style={{ background: isSubtotal ? 'color-mix(in srgb, var(--color-bg-soft) 55%, transparent)' : undefined }}>
                                                        <td style={{
                                                            paddingLeft: (12 + r.depth * 22) + 'px',
                                                            fontWeight: isSubtotal ? 600 : 400,
                                                            color: isSubtotal ? 'var(--color-text-primary)' : 'var(--color-text-secondary)',
                                                            whiteSpace: 'nowrap'
                                                        }}>
                                                            {r.name}
                                                            {isSubtotal && r.childrenCount > 0 && (
                                                                <span style={{ color: 'var(--color-text-muted)', marginLeft: '6px', fontSize: '12px' }}>({r.childrenCount})</span>
                                                            )}
                                                        </td>
                                                        {renderMetrics(r, isSubtotal)}
                                                    </tr>
                                                );
                                            })}
                                        </>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default CampaignReports;
