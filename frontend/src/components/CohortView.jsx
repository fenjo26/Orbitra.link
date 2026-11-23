import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { Calendar, Download, Grid3x3, Table2, BarChart3, TrendingUp } from 'lucide-react';
import InfoBanner from './InfoBanner';
import AnalyticsEntityFilters from './common/AnalyticsEntityFilters';
import { PageSortTh } from './common/PageTableSort';
import { usePageTableSort } from './common/usePageTableSort';
import { DemoTour, DemoBar } from './common/DemoTour';
import { useDemoMode } from './common/useDemoMode';
import { buildCohortDemo } from '../utils/analyticsDemo';
import { Line } from 'react-chartjs-2';
import {
    Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement,
    Title, Tooltip, Legend, Filler
} from 'chart.js';
import { useLanguage } from '../contexts/LanguageContext';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler);

const API_URL = '/api.php';

// Debounce hook to delay execution until user stops typing/changing values
const useDebounce = (value, delay) => {
    const [debouncedValue, setDebouncedValue] = useState(value);
    useEffect(() => {
        const handler = setTimeout(() => setDebouncedValue(value), delay);
        return () => clearTimeout(handler);
    }, [value, delay]);
    return debouncedValue;
};

// Map Orbitra UI language codes to BCP47 locale tags for date formatting.
const LOCALE_TAGS = {
    ru: 'ru-RU', en: 'en-US', uk: 'uk-UA', es: 'es-ES',
    zh: 'zh-CN', fr: 'fr-FR', de: 'de-DE'
};

// Format a cohort label ("2026-01" or "2026-Q1") into a localized short label.
// Locale is derived from the active UI language so the month name follows the
// interface language, not the browser locale.
const formatCohortLabel = (label, granularity, lang) => {
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
    const locale = LOCALE_TAGS[lang] || 'en-US';
    return date.toLocaleDateString(locale, { month: 'short', year: 'numeric' });
};

const formatCellValue = (metric, value, lang) => {
    const n = Number(value || 0);
    if (['revenue', 'real_revenue', 'cost', 'profit'].includes(metric)) {
        return `$${n.toFixed(2)}`;
    }
    if (['roi', 'real_roi', 'cr'].includes(metric)) {
        return `${n.toFixed(2)}%`;
    }
    return n.toLocaleString(LOCALE_TAGS[lang] || 'en-US');
};

const CohortView = () => {
    const { t, language } = useLanguage();
    const demo = useDemoMode();
    const [granularity, setGranularity] = useState('month');
    const [metric, setMetric] = useState('revenue');
    const [viewMode, setViewMode] = useState('absolute'); // 'absolute' | 'retention'
    const [dateFrom, setDateFrom] = useState(() => {
        const d = new Date(); d.setMonth(d.getMonth() - 6);
        return d.toISOString().split('T')[0];
    });
    const [dateTo, setDateTo] = useState(() => new Date().toISOString().split('T')[0]);
    const [selectedCampaignIds, setSelectedCampaignIds] = useState([]);
    const [selectedOfferIds, setSelectedOfferIds] = useState([]);
    const [selectedLandingIds, setSelectedLandingIds] = useState([]);
    const [loading, setLoading] = useState(true);
    const [serverData, setData] = useState(null); // { rows: [...], launched: {label: n}, max_period }
    // null, or { detail } / { network: true } once a load has failed. The
    // words come from the locale at render time.
    const [error, setError] = useState(null);

    // Debounce date changes to reduce API calls
    const debouncedDateFrom = useDebounce(dateFrom, 500);
    const debouncedDateTo = useDebounce(dateTo, 500);

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

    const fetchCohort = useCallback(async () => {
        // Demo mode shows example data only: no request, nothing overwritten.
        if (demo.active) return;
        setLoading(true);
        setError(null);
        try {
            // Build filters array from campaign/offer selections
            const cohortFilters = [];

            if (selectedCampaignIds.length === 1) {
                cohortFilters.push({ field: 'campaign_id', operator: 'equals', value: String(selectedCampaignIds[0]) });
            } else if (selectedCampaignIds.length > 1) {
                cohortFilters.push({ field: 'campaign_id', operator: 'in', value: selectedCampaignIds.join(',') });
            }

            if (selectedOfferIds.length === 1) {
                cohortFilters.push({ field: 'offer_id', operator: 'equals', value: String(selectedOfferIds[0]) });
            } else if (selectedOfferIds.length > 1) {
                cohortFilters.push({ field: 'offer_id', operator: 'in', value: selectedOfferIds.join(',') });
            }

            if (selectedLandingIds.length === 1) {
                cohortFilters.push({ field: 'landing_id', operator: 'equals', value: String(selectedLandingIds[0]) });
            } else if (selectedLandingIds.length > 1) {
                cohortFilters.push({ field: 'landing_id', operator: 'in', value: selectedLandingIds.join(',') });
            }

            const params = new URLSearchParams({
                action: 'cohort',
                granularity,
                date_from: debouncedDateFrom,
                date_to: debouncedDateTo,
                filters: JSON.stringify(cohortFilters),
            });
            const res = await fetch(`${API_URL}?${params}`);
            const json = await res.json();
            if (json.status === 'success') {
                setData(json.data);
            } else {
                setError({ detail: json.message || '' });
                setData(null);
            }
        } catch (e) {
            console.error('Error fetching cohort:', e);
            // A flag, not t(...): t is a new function on every provider render,
            // and fetchCohort drives a refetch effect.
            setError({ network: true });
            setData(null);
        } finally {
            setLoading(false);
        }
    }, [demo.active, granularity, debouncedDateFrom, debouncedDateTo, selectedCampaignIds, selectedOfferIds, selectedLandingIds]);

    // What the page shows: the server's cohorts, or the example in demo mode.
    const demoData = useMemo(() => (demo.active ? buildCohortDemo(granularity) : null), [demo.active, granularity]);
    const data = demoData || serverData;
    const isLoading = demoData ? false : loading;

    // fetchCohort is memoized on every filter input, so this refetches on
    // date, granularity AND campaign/offer/landing selections
    useEffect(() => { fetchCohort(); }, [fetchCohort]);

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

    // M0 value of the selected metric for a cohort — the denominator for the
    // retention-rate (% of M0) view mode. Falls back to the earliest available
    // period if M0 has no data yet (e.g. a cohort whose first clicks arrived later).
    const cohortM0 = (label) => {
        const cohort = matrix[label];
        if (!cohort) return 0;
        for (let p = 0; p <= maxPeriod; p++) {
            const cell = cohort[p];
            if (cell && cell[metric] !== undefined) return Number(cell[metric]) || 0;
        }
        return 0;
    };

    // Resolve the display value for a cell under the current view mode:
    // absolute = raw metric value; retention = value / M0 * 100.
    const cellValue = (label, periodIndex) => {
        const cohort = matrix[label];
        const cell = cohort?.[periodIndex];
        if (!cell) return null;
        const raw = Number(cell[metric] ?? 0);
        if (viewMode === 'retention') {
            const m0 = cohortM0(label);
            return m0 !== 0 ? (raw / m0) * 100 : null;
        }
        return raw;
    };

    // Per-row peak under the current view mode (absolute or retention %),
    // used for heat intensity. Computed via cellValue so the colour scale
    // matches whatever the cells actually show.
    const rowMax = (cohortLabel) => {
        let mx = 0;
        for (let p = 0; p <= maxPeriod; p++) {
            const v = cellValue(cohortLabel, p);
            if (v !== null && v > mx) mx = v;
        }
        return mx;
    };

    // Cell background + text colour. Two scales:
    //  - retention: semantic traffic-light thresholds (>=90 green, 70-89 light
    //    green, 50-69 amber, <50 red) — readable at a glance, matches the
    //    cadence-dashboard cohort heatmap pattern.
    //  - absolute: proportional intensity of --color-primary relative to the
    //    row peak. Uses color-mix() with the theme's --color-primary so it
    //    adapts to every theme (light/dark/green/neon/custom) automatically,
    //    instead of a hardcoded hex that would clash with non-coral themes.
    const cellStyle = (v, mx) => {
        if (v === null || v === undefined) {
            return { background: 'var(--color-bg-soft)', color: 'var(--color-text-muted)' };
        }
        if (viewMode === 'retention') {
            let bg, color = '#fff', weight = 600;
            if (v >= 90)      bg = 'var(--color-success)';
            else if (v >= 70) bg = 'color-mix(in srgb, var(--color-success) 50%, transparent)';
            else if (v >= 50) bg = 'var(--color-warning)';
            else              bg = 'var(--color-danger)';
            return { background: bg, color, fontWeight: weight };
        }
        const ratio = mx > 0 ? Math.max(0, v) / mx : 0;
        // Map ratio [0..1] to primary opacity [12%..92%] via color-mix so the
        // scale follows whatever --color-primary the active theme defines.
        const pct = Math.round(12 + ratio * (92 - 12));
        const ratioForText = ratio > 0.55;
        return {
            background: `color-mix(in srgb, var(--color-primary) ${pct}%, transparent)`,
            color: ratioForText ? '#fff' : 'var(--color-text-primary)',
            fontWeight: ratioForText ? 600 : 400
        };
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


    // Totals row of the summary: additive columns summed over the cohorts,
    // ROI recomputed from the summed profit and cost (not an average of the
    // cohorts' ROIs), first/last period left empty — they don't add up.
    const summaryTotals = useMemo(() => {
        if (summary.length === 0) return null;
        const tot = summary.reduce((acc, s) => {
            acc.launched += s.launched; acc.campaignsActive += s.campaignsActive;
            acc.clicks += s.clicks; acc.conversions += s.conversions;
            acc.revenue += s.revenue; acc.cost += s.cost; acc.profit += s.profit;
            return acc;
        }, { launched: 0, campaignsActive: 0, clicks: 0, conversions: 0, revenue: 0, cost: 0, profit: 0 });
        const roi = tot.cost > 0 ? (tot.profit / tot.cost) * 100 : (tot.profit > 0 ? 100 : 0);
        return { ...tot, roi: Math.round(roi * 100) / 100 };
    }, [summary]);

    const { sorted: sortedSummary, sort: summarySort, onSort: onSummarySort } = usePageTableSort(summary, {
        defaultKey: 'label', defaultDir: 'asc', storageKey: 'orbitra_cohort_summary_sort',
    });

    const hasData = data && data.rows && data.rows.length > 0;
    const launchedMap = data?.launched || {};

    // Retention curves: one dataset (line) per cohort, X = period index M0..Mmax,
    // Y = selected metric value. Missing periods break the line (spanGaps false),
    // so a cohort that stops producing shows a terminating curve — the canonical
    // cohort-analysis visualization.
    const retentionChart = useMemo(() => {
        if (!hasData || cohortLabels.length === 0) return null;
        // Distinct palette so cohorts are distinguishable without the primary brand color.
        const palette = ['#f05a3e', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16', '#6366f1', '#ef4444'];
        const labels = Array.from({ length: maxPeriod + 1 }, (_, p) => 'M' + p);
        const datasets = cohortLabels.map((label, i) => {
            const points = Array.from({ length: maxPeriod + 1 }, (_, p) => cellValue(label, p));
            return {
                label: formatCohortLabel(label, granularity, language),
                data: points,
                borderColor: palette[i % palette.length],
                backgroundColor: palette[i % palette.length] + '20',
                fill: false,
                tension: 0.3,
                spanGaps: false,
            };
        });
        return { labels, datasets };
    }, [hasData, cohortLabels, maxPeriod, metric, granularity, language, viewMode, matrix]);

    const chartOptions = {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top', labels: { boxWidth: 12, boxHeight: 12 } },
            tooltip: {
                mode: 'index', intersect: false,
                callbacks: viewMode === 'retention' ? {
                    label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y !== null ? ctx.parsed.y.toFixed(1) + '%' : '—'}`
                } : {}
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: viewMode === 'retention' ? { callback: (v) => v + '%' } : {}
            },
            x: { title: { display: true, text: t('cohort.period') } }
        },
        interaction: { mode: 'nearest', axis: 'x', intersect: false }
    };

    // A failed load must not read as "no data for this period": the title
    // says the load failed, the line under it carries the reason if any.
    const renderLoadError = () => (
        <div className="empty-state" style={{ padding: '48px' }}>
            <p className="empty-state-title">{t('cohort.loadError')}</p>
            {(error.network || error.detail) && (
                <p className="empty-state-text">{error.network ? t('common.networkError') : error.detail}</p>
            )}
        </div>
    );

    return (
        <div className="space-y-4">
            {demo.active && <DemoBar onReplay={demo.replay} onExit={demo.exit} />}
            {demo.active && demo.tour && <DemoTour steps={[
                { target: 'cohort-granularity', title: t('demo.cohort.granularityTitle'), text: t('demo.cohort.granularityText') },
                { target: 'cohort-metric', title: t('demo.cohort.metricTitle'), text: t('demo.cohort.metricText') },
                { target: 'cohort-matrix', title: t('demo.cohort.matrixTitle'), text: t('demo.cohort.matrixText') },
                { target: 'cohort-mode', title: t('demo.cohort.modeTitle'), text: t('demo.cohort.modeText') },
                { target: 'cohort-summary', title: t('demo.cohort.summaryTitle'), text: t('demo.cohort.summaryText') },
            ]} onClose={demo.closeTour} />}
            {!demo.active && (
            <InfoBanner storageKey="help_cohort" title={t('cohort.bannerTitle')} onDemo={demo.start}>
                <p style={{ marginBottom: '10px' }}>{t('cohort.banner')}</p>
                <div style={{ marginBottom: '8px' }}>
                    <strong style={{ color: 'var(--color-text-primary)' }}>{t('cohort.what')}</strong>
                    <span style={{ marginLeft: '6px' }}>{t('cohort.whatText')}</span>
                </div>
                <div style={{ marginBottom: '8px' }}>
                    <strong style={{ color: 'var(--color-text-primary)' }}>{t('cohort.why')}</strong>
                    <span style={{ marginLeft: '6px' }}>{t('cohort.whyText')}</span>
                </div>
                <div>
                    <strong style={{ color: 'var(--color-text-primary)' }}>{t('cohort.how')}</strong>
                    <span style={{ marginLeft: '6px' }}>{t('cohort.howText')}</span>
                </div>
            </InfoBanner>
            )}

            {/* Controls */}
            <div className="page-card">
                <div className="flex flex-wrap items-center gap-4">
                    <div className="flex items-center gap-2" data-tour="cohort-granularity">
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
                    <AnalyticsEntityFilters
                        campaignIds={selectedCampaignIds}
                        onCampaignChange={setSelectedCampaignIds}
                        offerIds={selectedOfferIds}
                        onOfferChange={setSelectedOfferIds}
                        landingIds={selectedLandingIds}
                        onLandingChange={setSelectedLandingIds}
                    />
                    <button onClick={exportCSV} className="btn btn-secondary btn-sm" disabled={!hasData || demo.active}>
                        <Download className="w-4 h-4" />{t('cohort.exportCsv')}
                    </button>
                </div>

                {/* Active filters badges */}
                {(selectedCampaignIds.length > 0 || selectedOfferIds.length > 0 || selectedLandingIds.length > 0) && (
                    <div className="flex flex-wrap gap-2" style={{ marginTop: '12px' }}>
                        {selectedCampaignIds.length > 0 && (
                            <span className="status-badge" style={{ background: 'var(--color-primary)', color: 'var(--color-text-inverse)' }}>
                                {t('analytics.campaigns')}: {selectedCampaignIds.length}
                                <button onClick={() => setSelectedCampaignIds([])} style={{ marginLeft: '4px', cursor: 'pointer' }}>×</button>
                            </span>
                        )}
                        {selectedOfferIds.length > 0 && (
                            <span className="status-badge" style={{ background: 'var(--color-primary)', color: 'var(--color-text-inverse)' }}>
                                {t('analytics.offers')}: {selectedOfferIds.length}
                                <button onClick={() => setSelectedOfferIds([])} style={{ marginLeft: '4px', cursor: 'pointer' }}>×</button>
                            </span>
                        )}
                        {selectedLandingIds.length > 0 && (
                            <span className="status-badge" style={{ background: 'var(--color-primary)', color: 'var(--color-text-inverse)' }}>
                                {t('analytics.landings')}: {selectedLandingIds.length}
                                <button onClick={() => setSelectedLandingIds([])} style={{ marginLeft: '4px', cursor: 'pointer' }}>×</button>
                            </span>
                        )}
                    </div>
                )}

                {/* Metric selector */}
                <div className="flex flex-wrap items-center gap-2" style={{ marginTop: '12px' }} data-tour="cohort-metric">
                    <span className="form-label" style={{ margin: 0 }}>{t('cohort.metric')}</span>
                    {availableMetrics.map(m => (
                        <button key={m.key}
                            onClick={() => setMetric(m.key)}
                            className={`btn btn-sm ${metric === m.key ? '' : 'btn-secondary'}`}
                            style={metric === m.key ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' } : {}}>
                            {m.label}
                        </button>
                    ))}
                </div>
                {/* View mode: absolute values or retention rate (% of M0).
                    Retention mode normalises each cohort to its first period so
                    cohorts of different sizes can be compared by decay shape. */}
                <div className="flex flex-wrap items-center gap-2" style={{ marginTop: '12px' }} data-tour="cohort-mode">
                    <span className="form-label" style={{ margin: 0 }}>{t('cohort.viewMode')}</span>
                    <button
                        onClick={() => setViewMode('absolute')}
                        className={`btn btn-sm ${viewMode === 'absolute' ? '' : 'btn-secondary'}`}
                        style={viewMode === 'absolute' ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' } : {}}>
                        {t('cohort.absolute')}
                    </button>
                    <button
                        onClick={() => setViewMode('retention')}
                        className={`btn btn-sm ${viewMode === 'retention' ? '' : 'btn-secondary'}`}
                        style={viewMode === 'retention' ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' } : {}}>
                        {t('cohort.retention')}
                    </button>
                </div>
            </div>

            {/* Retention curves — the canonical cohort chart: one line per cohort
                decaying/growing across M0..Mmax. Shown above the matrix so the
                "is this cohort aging well?" story reads top-down. */}
            <div className="page-card" style={{ padding: 0 }}>
                <div className="page-header" style={{ padding: '16px 24px', marginBottom: 0 }}>
                    <div className="flex items-center gap-2">
                        <TrendingUp className="w-5 h-5" style={{ color: 'var(--color-text-muted)' }} />
                        <h3 className="page-title">{t('cohort.curveTitle')}</h3>
                    </div>
                    <span style={{ fontSize: '14px', color: 'var(--color-text-muted)' }}>
                        {cohortLabels.length} {t('cohort.cohortLabel').toLowerCase()}
                    </span>
                </div>
                <div style={{ height: '360px', padding: '16px' }}>
                    {isLoading ? (
                        <div className="empty-state" style={{ padding: '48px' }}>
                            <p style={{ color: 'var(--color-text-muted)' }}>{t('cohort.loading')}</p>
                        </div>
                    ) : error ? renderLoadError() : !hasData ? (
                        <div className="empty-state" style={{ padding: '48px' }}>
                            <p className="empty-state-title">{t('cohort.noDataTitle')}</p>
                            <p className="empty-state-text">{t('cohort.noDataText')}</p>
                        </div>
                    ) : (
                        <Line data={retentionChart} options={chartOptions} />
                    )}
                </div>
            </div>

            {/* Cohort matrix (heatmap) */}
            <div className="page-card" style={{ padding: 0 }} data-tour="cohort-matrix">
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
                    {isLoading ? (
                        <div className="empty-state" style={{ padding: '48px' }}>
                            <p style={{ color: 'var(--color-text-muted)' }}>{t('cohort.loading')}</p>
                        </div>
                    ) : error ? renderLoadError() : !hasData ? (
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
                                    const mx = rowMax(label);
                                    return (
                                        <tr key={label}>
                                            <td style={{ fontWeight: 500, position: 'sticky', left: 0, zIndex: 1,
                                                background: 'var(--color-bg-card)' }}>
                                                {formatCohortLabel(label, granularity, language)}
                                            </td>
                                            <td className="text-right" style={{ color: 'var(--color-text-muted)' }}>
                                                {launchedMap[label] ?? 0}
                                            </td>
                                            {Array.from({ length: maxPeriod + 1 }, (_, p) => {
                                                const v = cellValue(label, p);
                                                const style = cellStyle(v, mx);
                                                if (v === null) {
                                                    return (
                                                        <td key={p} className="text-right"
                                                            style={style}>—</td>
                                                    );
                                                }
                                                const display = viewMode === 'retention'
                                                    ? `${v.toFixed(1)}%`
                                                    : formatCellValue(metric, v, language);
                                                const cohortName = formatCohortLabel(label, granularity, language);
                                                return (
                                                    <td key={p} className="text-right"
                                                        title={`${cohortName} · M${p}: ${display}`}
                                                        style={{ ...style, fontVariantNumeric: 'tabular-nums' }}>
                                                        {display}
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
            {hasData && !isLoading && (
                <div className="page-card" style={{ padding: 0 }} data-tour="cohort-summary">
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
                                    <PageSortTh colKey="label" label={t('cohort.cohortLabel')} sort={summarySort} onSort={onSummarySort} firstDir="asc" />
                                    <PageSortTh colKey="launched" align="right" label={t('cohort.launched')} sort={summarySort} onSort={onSummarySort} />
                                    <PageSortTh colKey="campaignsActive" align="right" label={t('cohort.campaignsActive')} sort={summarySort} onSort={onSummarySort} />
                                    <PageSortTh colKey="clicks" align="right" label={t('cohort.totalClicks')} sort={summarySort} onSort={onSummarySort} />
                                    <PageSortTh colKey="conversions" align="right" label={t('cohort.conversions')} sort={summarySort} onSort={onSummarySort} />
                                    <PageSortTh colKey="revenue" align="right" label={t('cohort.totalRevenue')} sort={summarySort} onSort={onSummarySort} />
                                    <PageSortTh colKey="profit" align="right" label={t('cohort.totalProfit')} sort={summarySort} onSort={onSummarySort} />
                                    <PageSortTh colKey="roi" align="right" label={t('cohort.avgRoi')} sort={summarySort} onSort={onSummarySort} />
                                    <PageSortTh colKey="firstIdx" align="right" label={t('cohort.firstSeen')} sort={summarySort} onSort={onSummarySort} firstDir="asc" />
                                    <PageSortTh colKey="lastIdx" align="right" label={t('cohort.lastSeen')} sort={summarySort} onSort={onSummarySort} />
                                </tr>
                            </thead>
                            <tbody>
                                {sortedSummary.map(s => (
                                    <tr key={s.label}>
                                        <td style={{ fontWeight: 500, textAlign: 'left', whiteSpace: 'nowrap' }}>
                                            {formatCohortLabel(s.label, granularity, language)}
                                        </td>
                                        <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--color-text-muted)' }}>
                                            {s.launched}
                                        </td>
                                        <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{s.campaignsActive}</td>
                                        <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{s.clicks.toLocaleString(LOCALE_TAGS[language] || 'en-US')}</td>
                                        <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{s.conversions}</td>
                                        <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>${s.revenue.toFixed(2)}</td>
                                        <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: s.profit > 0 ? 'var(--color-success)' : s.profit < 0 ? 'var(--color-danger)' : 'var(--color-text-secondary)' }}>
                                            {s.profit > 0 ? '+' : s.profit < 0 ? '-' : ''}${Math.abs(s.profit).toFixed(2)}
                                        </td>
                                        <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: s.roi > 0 ? 'var(--color-success)' : s.roi < 0 ? 'var(--color-danger)' : 'var(--color-text-muted)' }}>
                                            {s.roi > 0 ? '+' : ''}{s.roi.toFixed(2)}%
                                        </td>
                                        <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--color-text-muted)' }}>
                                            M{s.firstIdx ?? 0}
                                        </td>
                                        <td style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--color-text-muted)' }}>
                                            M{s.lastIdx ?? 0}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            {summaryTotals && (
                                <tfoot>
                                    <tr>
                                        <td style={{ textAlign: 'left' }}>{t('trends.total')}</td>
                                        <td style={{ textAlign: 'right' }}>{summaryTotals.launched}</td>
                                        <td style={{ textAlign: 'right' }}>{summaryTotals.campaignsActive}</td>
                                        <td style={{ textAlign: 'right' }}>{summaryTotals.clicks.toLocaleString(LOCALE_TAGS[language] || 'en-US')}</td>
                                        <td style={{ textAlign: 'right' }}>{summaryTotals.conversions}</td>
                                        <td style={{ textAlign: 'right' }}>${summaryTotals.revenue.toFixed(2)}</td>
                                        <td style={{ textAlign: 'right', color: summaryTotals.profit > 0 ? 'var(--color-success)' : summaryTotals.profit < 0 ? 'var(--color-danger)' : undefined }}>
                                            {summaryTotals.profit > 0 ? '+' : summaryTotals.profit < 0 ? '-' : ''}${Math.abs(summaryTotals.profit).toFixed(2)}
                                        </td>
                                        <td style={{ textAlign: 'right', color: summaryTotals.roi > 0 ? 'var(--color-success)' : summaryTotals.roi < 0 ? 'var(--color-danger)' : undefined }}>
                                            {summaryTotals.roi > 0 ? '+' : ''}{summaryTotals.roi.toFixed(2)}%
                                        </td>
                                        <td style={{ textAlign: 'right', color: 'var(--color-text-muted)' }}>—</td>
                                        <td style={{ textAlign: 'right', color: 'var(--color-text-muted)' }}>—</td>
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
};

export default CohortView;
