import React, { useState, useEffect, useMemo } from 'react';
import { Calendar, Download, Grid3x3, Table2, BarChart3 } from 'lucide-react';
import InfoBanner from './InfoBanner';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

// Centralized design system primary color (mirrors --color-primary token).
// Kept as a constant so the heatmap matches the app theme on every theme.
const PRIMARY_HEX = '#f05a3e';

const hexToRgba = (hex, alpha) => {
    let r = 0, g = 0, b = 0;
    if (typeof hex !== 'string') return `rgba(240, 90, 62, ${alpha})`;
    if (hex.startsWith('#')) {
        if (hex.length === 4) {
            r = parseInt(hex[1] + hex[1], 16);
            g = parseInt(hex[2] + hex[2], 16);
            b = parseInt(hex[3] + hex[3], 16);
        } else if (hex.length === 7) {
            r = parseInt(hex.slice(1, 3), 16);
            g = parseInt(hex.slice(3, 5), 16);
            b = parseInt(hex.slice(5, 7), 16);
        }
    }
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
};

// Format a cohort label ("2026-01" or "2026-Q1") into a localized short label.
const formatCohortLabel = (label, granularity, t) => {
    if (!label) return '';
    if (granularity === 'quarter') {
        // "2026-Q1" → "Q1 2026"
        const [year, q] = label.split('-Q');
        if (!year || !q) return label;
        return `${q} ${year}`;
    }
    // "2026-01" → localized month + year
    const [year, month] = label.split('-');
    if (!year || !month) return label;
    const date = new Date(Number(year), Number(month) - 1, 1);
    return date.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
};

const formatCellValue = (metric, value) => {
    const n = Number(value || 0);
    if (['revenue', 'real_revenue', 'cost', 'profit'].includes(metric)) {
        return `$${n.toFixed(2)}`;
    }
    if (['roi', 'real_roi', 'cr'].includes(metric)) {
        return `${n.toFixed(2)}%`;
    }
    return n.toLocaleString('ru-RU');
};

const CohortView = () => {
    const { t } = useLanguage();
    const [granularity, setGranularity] = useState('month');
    const [metric, setMetric] = useState('revenue');
    const [dateFrom, setDateFrom] = useState(() => {
        const d = new Date(); d.setMonth(d.getMonth() - 6);
        return d.toISOString().split('T')[0];
    });
    const [dateTo, setDateTo] = useState(() => new Date().toISOString().split('T')[0]);
    const [loading, setLoading] = useState(true);
    const [data, setData] = useState(null); // { rows: [...], launched: {label: n}, max_period }
    const [error, setError] = useState('');

    const availableMetrics = useMemo(() => ([
        { key: 'revenue', label: t('cohort.revenue') },
        { key: 'real_revenue', label: t('cohort.realRevenue') },
        { key: 'clicks', label: t('cohort.clicks') },
        { key: 'conversions', label: t('cohort.conversions') },
        { key: 'cost', label: t('cohort.cost') },
        { key: 'profit', label: t('cohort.profit') },
        { key: 'roi', label: t('cohort.roi') },
        { key: 'real_roi', label: t('cohort.realRoi') },
        { key: 'cr', label: t('cohort.cr') },
        { key: 'campaigns_active', label: t('cohort.campaignsActive') },
    ]), [t]);

    const fetchCohort = async () => {
        setLoading(true);
        setError('');
        try {
            const params = new URLSearchParams({
                action: 'cohort',
                granularity,
                date_from: dateFrom,
                date_to: dateTo,
            });
            const res = await fetch(`${API_URL}?${params}`);
            const json = await res.json();
            if (json.status === 'success') {
                setData(json.data);
            } else {
                setError(json.message || 'Error');
                setData(null);
            }
        } catch (e) {
            console.error('Error fetching cohort:', e);
            setError(String(e));
            setData(null);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { fetchCohort(); }, [granularity, dateFrom, dateTo]);

    // Pivot flat rows → { [cohortLabel]: { [periodIndex]: row, maxPeriod, rowMaxForMetric } }
    // Also compute the global column-axis maximum per metric for heat intensity.
    const { matrix, cohortLabels, maxPeriod } = useMemo(() => {
        if (!data || !data.rows || data.rows.length === 0) {
            return { matrix: {}, cohortLabels: [], maxPeriod: 0 };
        }
        const m = {};
        let maxP = 0;
        for (const row of data.rows) {
            const lbl = row.cohort_label;
            if (!m[lbl]) m[lbl] = {};
            m[lbl][row.period_index] = row;
            if (row.period_index > maxP) maxP = row.period_index;
        }
        const labels = Object.keys(m).sort();
        return { matrix: m, cohortLabels: labels, maxPeriod: Math.min(maxP, data.max_period ?? maxP) };
    }, [data]);

    // Per-row max of the selected metric (so heat intensity is relative to each cohort's peak,
    // not the global peak — prevents one big cohort from washing out the others).
    const rowMax = (cohortLabel) => {
        const cohort = matrix[cohortLabel];
        if (!cohort) return 0;
        let mx = 0;
        for (let p = 0; p <= maxPeriod; p++) {
            const v = cohort[p]?.[metric];
            if (typeof v === 'number' && v > mx) mx = v;
        }
        return mx;
    };

    const exportCSV = () => {
        if (!data || !data.rows || data.rows.length === 0) return;
        const headers = ['cohort', 'period_index', 'clicks', 'unique_clicks', 'conversions',
            'revenue', 'real_revenue', 'cost', 'profit', 'cr', 'roi', 'real_roi',
            'campaigns_active', 'campaigns_launched'];
        const launchedMap = data.launched || {};
        const lines = [headers.join(';')];
        const sorted = [...data.rows].sort((a, b) =>
            a.cohort_label.localeCompare(b.cohort_label) || a.period_index - b.period_index);
        for (const r of sorted) {
            lines.push([
                r.cohort_label, r.period_index, r.clicks, r.unique_clicks, r.conversions,
                r.revenue, r.real_revenue, r.cost, r.profit, r.cr, r.roi, r.real_roi,
                r.campaigns_active, launchedMap[r.cohort_label] ?? 0
            ].join(';'));
        }
        const csv = '\ufeff' + lines.join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `cohort_${granularity}_${dateFrom}_${dateTo}.csv`;
        link.click();
    };

    // Cohort summary rows (totals across all periods).
    const summary = useMemo(() => {
        if (cohortLabels.length === 0) return [];
        return cohortLabels.map(label => {
            const cohort = matrix[label];
            let clicks = 0, conversions = 0, revenue = 0, cost = 0, profit = 0, realRevenue = 0;
            let campaignsActive = 0, firstIdx = null, lastIdx = null;
            for (let p = 0; p <= maxPeriod; p++) {
                const r = cohort[p];
                if (!r) continue;
                clicks += r.clicks; conversions += r.conversions;
                revenue += r.revenue; cost += r.cost; profit += r.profit;
                realRevenue += r.real_revenue;
                if (r.campaigns_active > campaignsActive) campaignsActive = r.campaigns_active;
                if (firstIdx === null) firstIdx = p;
                lastIdx = p;
            }
            const roi = cost > 0 ? (profit / cost) * 100 : (profit > 0 ? 100 : 0);
            return {
                label, clicks, conversions, revenue, cost, profit, realRevenue,
                campaignsActive, launched: data?.launched?.[label] ?? 0,
                roi: Math.round(roi * 100) / 100,
                firstIdx, lastIdx
            };
        });
    }, [cohortLabels, matrix, maxPeriod, data]);

    const hasData = data && data.rows && data.rows.length > 0;
    const launchedMap = data?.launched || {};

    return (
        <div className="space-y-4">
            <InfoBanner storageKey="help_cohort" title={t('cohort.bannerTitle')}>
                <p>{t('cohort.banner')}</p>
            </InfoBanner>

            {/* Controls */}
            <div className="page-card">
                <div className="flex flex-wrap items-center gap-4">
                    <div className="flex items-center gap-2">
                        <Grid3x3 className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                        <label className="form-label" style={{ margin: 0 }}>{t('cohort.granularity')}</label>
                        <select value={granularity} onChange={(e) => setGranularity(e.target.value)}
                            className="form-select" style={{ width: 'auto', padding: '8px 12px' }}>
                            <option value="month">{t('cohort.month')}</option>
                            <option value="quarter">{t('cohort.quarter')}</option>
                        </select>
                    </div>
                    <div className="flex items-center gap-2">
                        <Calendar className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                        <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)}
                            className="form-input" style={{ width: 'auto', padding: '8px 12px' }} />
                        <span style={{ color: 'var(--color-text-muted)' }}>—</span>
                        <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)}
                            className="form-input" style={{ width: 'auto', padding: '8px 12px' }} />
                    </div>
                    <button onClick={exportCSV} className="btn btn-secondary btn-sm" disabled={!hasData}>
                        <Download className="w-4 h-4" />{t('cohort.exportCsv')}
                    </button>
                </div>

                {/* Metric selector */}
                <div className="flex flex-wrap items-center gap-2" style={{ marginTop: '12px' }}>
                    <span className="form-label" style={{ margin: 0 }}>{t('cohort.metric')}</span>
                    {availableMetrics.map(m => (
                        <button key={m.key}
                            onClick={() => setMetric(m.key)}
                            className={`btn btn-sm ${metric === m.key ? '' : 'btn-secondary'}`}
                            style={metric === m.key ? { backgroundColor: 'var(--color-primary)', color: 'white' } : {}}>
                            {m.label}
                        </button>
                    ))}
                </div>
            </div>

            {/* Cohort matrix (heatmap) */}
            <div className="page-card" style={{ padding: 0 }}>
                <div className="page-header" style={{ padding: '16px 24px', marginBottom: 0 }}>
                    <div className="flex items-center gap-2">
                        <BarChart3 className="w-5 h-5" style={{ color: 'var(--color-text-muted)' }} />
                        <h3 className="page-title">{t('cohort.matrixTitle')}</h3>
                    </div>
                    <span style={{ fontSize: '14px', color: 'var(--color-text-muted)' }}>
                        {cohortLabels.length} {t('cohort.cohortLabel').toLowerCase()}
                    </span>
                </div>
                <div className="overflow-x-auto">
                    {loading ? (
                        <div className="empty-state" style={{ padding: '48px' }}>
                            <p style={{ color: 'var(--color-text-muted)' }}>{t('cohort.loading')}</p>
                        </div>
                    ) : !hasData ? (
                        <div className="empty-state" style={{ padding: '48px' }}>
                            <p className="empty-state-title">{t('cohort.noDataTitle')}</p>
                            <p className="empty-state-text">{t('cohort.noDataText')}</p>
                        </div>
                    ) : (
                        <table className="page-table">
                            <thead>
                                <tr>
                                    <th style={{ position: 'sticky', left: 0, zIndex: 2,
                                        background: 'var(--color-bg-card)' }}>
                                        {t('cohort.cohortLabel')}
                                    </th>
                                    <th className="text-right">{t('cohort.launched')}</th>
                                    {Array.from({ length: maxPeriod + 1 }, (_, p) => (
                                        <th key={p} className="text-right">M{p}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {cohortLabels.map(label => {
                                    const cohort = matrix[label];
                                    const mx = rowMax(label);
                                    return (
                                        <tr key={label}>
                                            <td style={{ fontWeight: 500, position: 'sticky', left: 0, zIndex: 1,
                                                background: 'var(--color-bg-card)' }}>
                                                {formatCohortLabel(label, granularity, t)}
                                            </td>
                                            <td className="text-right" style={{ color: 'var(--color-text-muted)' }}>
                                                {launchedMap[label] ?? 0}
                                            </td>
                                            {Array.from({ length: maxPeriod + 1 }, (_, p) => {
                                                const cell = cohort[p];
                                                if (!cell) {
                                                    // Future period (cohort not old enough yet).
                                                    return (
                                                        <td key={p} className="text-right"
                                                            style={{ background: 'var(--color-bg-soft)',
                                                                color: 'var(--color-text-muted)' }}>—</td>
                                                    );
                                                }
                                                const v = cell[metric];
                                                // Heat intensity: ratio of this cell to the row's peak.
                                                // Clamp alpha to [0.12, 0.92] so even the peak isn't unreadable.
                                                const ratio = mx > 0 ? Math.max(0, Number(v || 0)) / mx : 0;
                                                const alpha = 0.12 + ratio * (0.92 - 0.12);
                                                const bg = hexToRgba(PRIMARY_HEX, alpha);
                                                const textColor = ratio > 0.55 ? '#fff' : 'var(--color-text-primary)';
                                                return (
                                                    <td key={p} className="text-right"
                                                        title={formatCohortLabel(label, granularity, t) + ' · M' + p}
                                                        style={{ background: bg, color: textColor,
                                                            fontWeight: ratio > 0.55 ? 600 : 400 }}>
                                                        {formatCellValue(metric, v)}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>

            {/* Cohort summary table */}
            {hasData && !loading && (
                <div className="page-card" style={{ padding: 0 }}>
                    <div className="page-header" style={{ padding: '16px 24px', marginBottom: 0 }}>
                        <div className="flex items-center gap-2">
                            <Table2 className="w-5 h-5" style={{ color: 'var(--color-text-muted)' }} />
                            <h3 className="page-title">{t('cohort.summaryTitle')}</h3>
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="page-table">
                            <thead>
                                <tr>
                                    <th>{t('cohort.cohortLabel')}</th>
                                    <th className="text-right">{t('cohort.launched')}</th>
                                    <th className="text-right">{t('cohort.campaignsActive')}</th>
                                    <th className="text-right">{t('cohort.totalClicks')}</th>
                                    <th className="text-right">{t('cohort.conversions')}</th>
                                    <th className="text-right">{t('cohort.totalRevenue')}</th>
                                    <th className="text-right">{t('cohort.totalProfit')}</th>
                                    <th className="text-right">{t('cohort.avgRoi')}</th>
                                    <th className="text-right">{t('cohort.firstSeen')}</th>
                                    <th className="text-right">{t('cohort.lastSeen')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {summary.map(s => (
                                    <tr key={s.label}>
                                        <td style={{ fontWeight: 500 }}>
                                            {formatCohortLabel(s.label, granularity, t)}
                                        </td>
                                        <td className="text-right" style={{ color: 'var(--color-text-muted)' }}>
                                            {s.launched}
                                        </td>
                                        <td className="text-right">{s.campaignsActive}</td>
                                        <td className="text-right">{s.clicks.toLocaleString('ru-RU')}</td>
                                        <td className="text-right">{s.conversions}</td>
                                        <td className="text-right">${s.revenue.toFixed(2)}</td>
                                        <td className="text-right"
                                            style={{ color: s.profit >= 0 ? 'var(--color-success)' : 'var(--color-danger)' }}>
                                            ${s.profit.toFixed(2)}
                                        </td>
                                        <td className="text-right"
                                            style={{ color: s.roi >= 0 ? 'var(--color-success)' : 'var(--color-danger)' }}>
                                            {s.roi.toFixed(2)}%
                                        </td>
                                        <td className="text-right" style={{ color: 'var(--color-text-muted)' }}>
                                            M{s.firstIdx ?? 0}
                                        </td>
                                        <td className="text-right" style={{ color: 'var(--color-text-muted)' }}>
                                            M{s.lastIdx ?? 0}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
};

export default CohortView;
