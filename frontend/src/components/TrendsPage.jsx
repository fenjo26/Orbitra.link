import React, { useState, useEffect, useMemo } from 'react';
import { Calendar, Filter, Download, BarChart3, TrendingUp, Clock, PieChart, Grid3x3 } from 'lucide-react';
import InfoBanner from './InfoBanner';
import CohortView from './CohortView';
import AnalyticsEntityFilters from './common/AnalyticsEntityFilters';
import { PageSortTh } from './common/PageTableSort';
import { usePageTableSort } from './common/usePageTableSort';
import { DemoTour, DemoBar } from './common/DemoTour';
import { useDemoMode } from './common/useDemoMode';
import { buildTrendsDemo } from '../utils/analyticsDemo';
import { Line } from 'react-chartjs-2';
import {
    Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement,
    Title, Tooltip, Legend, Filler
} from 'chart.js';
import { useLanguage } from '../contexts/LanguageContext';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler);

const API_URL = '/api.php';

const LOCALE_TAGS = {
    ru: 'ru-RU', en: 'en-US', uk: 'uk-UA', es: 'es-ES',
    zh: 'zh-CN', fr: 'fr-FR', de: 'de-DE'
};
const MONEY_METRICS = ['revenue', 'real_revenue', 'cost', 'profit'];
const PERCENT_METRICS = ['ctr', 'cr', 'real_roi'];

// Period column sorts by the raw bucket key the server sends (dates sort as
// strings, weekdays as 0..6), never by the displayed label.
const trendSortValue = (row, key) => (key === 'period' ? (row.period_key ?? row.period) : row[key]);

// Debounce hook to delay execution until user stops typing/changing values
const useDebounce = (value, delay) => {
    const [debouncedValue, setDebouncedValue] = useState(value);
    useEffect(() => {
        const handler = setTimeout(() => setDebouncedValue(value), delay);
        return () => clearTimeout(handler);
    }, [value, delay]);
    return debouncedValue;
};

const TrendsPage = () => {
    const { t, language } = useLanguage();
    const demoMode = useDemoMode();
    const [view, setView] = useState(() => localStorage.getItem('orbitra_analytics_view') || 'trend');
    const handleSetView = (v) => {
        setView(v);
        localStorage.setItem('orbitra_analytics_view', v);
    };
    const [groupBy, setGroupBy] = useState('day');
    const [dateFrom, setDateFrom] = useState(() => {
        // Default to 3 days instead of 7 for faster initial load
        const d = new Date(); d.setDate(d.getDate() - 3);
        return d.toISOString().split('T')[0];
    });
    const [dateTo, setDateTo] = useState(() => new Date().toISOString().split('T')[0]);
    const [selectedMetrics, setSelectedMetrics] = useState(['clicks', 'unique_clicks', 'conversions', 'revenue', 'real_revenue', 'cost', 'profit', 'cr']);
    const [filters, setFilters] = useState([]);
    const [selectedCampaignIds, setSelectedCampaignIds] = useState([]);
    const [selectedOfferIds, setSelectedOfferIds] = useState([]);
    const [selectedLandingIds, setSelectedLandingIds] = useState([]);
    const [showFilterModal, setShowFilterModal] = useState(false);
    const [newFilter, setNewFilter] = useState({ field: 'country_code', operator: 'contains', value: '' });
    const [loading, setLoading] = useState(true);
    const [chartData, setChartData] = useState(null);
    const [tableData, setTableData] = useState([]);
    const [totals, setTotals] = useState(null);
    const [seamNote, setSeamNote] = useState(null);
    const [filterError, setFilterError] = useState('');

    // The example tells one story — clicks up, CR down — so it opens with just
    // those metrics; the user's own selection comes back when they leave it.
    const [metricsBeforeDemo, setMetricsBeforeDemo] = useState(null);
    const demo = {
        ...demoMode,
        start: () => {
            setMetricsBeforeDemo(selectedMetrics);
            setSelectedMetrics(['clicks', 'unique_clicks', 'conversions', 'cr']);
            demoMode.start();
        },
        exit: () => {
            if (metricsBeforeDemo) setSelectedMetrics(metricsBeforeDemo);
            setMetricsBeforeDemo(null);
            demoMode.exit();
        },
    };

    // Debounce date changes to reduce API calls while user is selecting dates
    const debouncedDateFrom = useDebounce(dateFrom, 500);
    const debouncedDateTo = useDebounce(dateTo, 500);

    const availableMetrics = [
        { key: 'clicks', label: t('trends.clicks'), color: '#3B82F6' },
        { key: 'unique_clicks', label: t('trends.uniqueClicks'), color: '#10B981' },
        { key: 'conversions', label: t('trends.conversions'), color: '#F59E0B' },
        { key: 'revenue', label: t('trends.revenue'), color: '#8B5CF6' },
        { key: 'real_revenue', label: t('trends.realRevenue'), color: '#4338CA' },
        { key: 'cost', label: t('trends.cost'), color: '#EF4444' },
        { key: 'profit', label: t('trends.profit'), color: '#06B6D4' },
        { key: 'real_roi', label: t('trends.realRoi'), color: '#6366F1' },
        { key: 'ctr', label: t('trends.ctr'), color: '#EC4899' },
        { key: 'cr', label: t('trends.cr'), color: '#84CC16' }
    ];

    const filterFields = [
        { value: 'country_code', label: t('trends.country') },
        { value: 'device_type', label: t('trends.device') },
        { value: 'campaign_id', label: t('trends.campaignId') },
        { value: 'offer_id', label: t('trends.offerId') },
        { value: 'ip', label: t('trends.ipAddress') },
        { value: 'browser', label: t('trends.browser') },
        { value: 'os', label: t('trends.os') },
        { value: 'source', label: t('trends.source') }
    ];

    const filterOperators = [
        { value: 'contains', label: t('trends.contains') },
        { value: 'not_contains', label: t('trends.notContains') },
        { value: 'equals', label: t('trends.equals') },
        { value: 'not_equals', label: t('trends.notEquals') },
        { value: 'starts_with', label: t('trends.startsWith') },
        { value: 'ends_with', label: t('trends.endsWith') },
        { value: 'regexp', label: t('trends.regexp') },
        { value: 'regexp_exclude', label: t('trends.regexpExclude') }
    ];

    const fetchTrends = async () => {
        // Demo mode shows example data only: no request, nothing overwritten.
        if (demo.active) return;
        setLoading(true);
        try {
            // Build active filters array from existing filters + campaign/offer selections
            const activeFilters = [...filters];

            // Add campaign filter if any selected
            if (selectedCampaignIds.length === 1) {
                activeFilters.push({ field: 'campaign_id', operator: 'equals', value: String(selectedCampaignIds[0]) });
            } else if (selectedCampaignIds.length > 1) {
                activeFilters.push({ field: 'campaign_id', operator: 'in', value: selectedCampaignIds.join(',') });
            }

            // Add offer filter if any selected
            if (selectedOfferIds.length === 1) {
                activeFilters.push({ field: 'offer_id', operator: 'equals', value: String(selectedOfferIds[0]) });
            } else if (selectedOfferIds.length > 1) {
                activeFilters.push({ field: 'offer_id', operator: 'in', value: selectedOfferIds.join(',') });
            }

            // Add landing filter if any selected
            if (selectedLandingIds.length === 1) {
                activeFilters.push({ field: 'landing_id', operator: 'equals', value: String(selectedLandingIds[0]) });
            } else if (selectedLandingIds.length > 1) {
                activeFilters.push({ field: 'landing_id', operator: 'in', value: selectedLandingIds.join(',') });
            }

            const params = new URLSearchParams({
                action: 'trends', group_by: groupBy, date_from: debouncedDateFrom,
                date_to: debouncedDateTo, metrics: selectedMetrics.join(','), filters: JSON.stringify(activeFilters)
            });
            const res = await fetch(`${API_URL}?${params}`);
            const data = await res.json();
            setSeamNote(data.stats_seam?.raw_available_from ? data.stats_seam.raw_available_from : null);
            if (data.status === 'error' && data.code === 'invalid_regex') {
                setFilterError(t('trends.invalidRegex', { pattern: data.pattern }));
                setChartData(null);
                setTableData([]);
                setTotals(null);
                return;
            }
            setFilterError('');
            if (data.status === 'success') {
                setChartData(data.data.chart || null);
                setTableData(Array.isArray(data.data.table) ? data.data.table : []);
                setTotals(data.data.totals || null);
            }
        } catch (e) { console.error('Error fetching trends:', e); }
        finally { setLoading(false); }
    };

    // Only fetch trends when debounced dates change (not on every keystroke)
    useEffect(() => { fetchTrends(); }, [demo.active, groupBy, debouncedDateFrom, debouncedDateTo, JSON.stringify(filters), JSON.stringify(selectedCampaignIds), JSON.stringify(selectedOfferIds), JSON.stringify(selectedLandingIds)]);

    const addFilter = () => {
        if (newFilter.value.trim()) {
            setFilters([...filters, { ...newFilter, id: Date.now() }]);
            setNewFilter({ field: 'country_code', operator: 'contains', value: '' });
            setShowFilterModal(false);
        }
    };

    const removeFilter = (id) => setFilters(filters.filter(f => f.id !== id));

    const toggleMetric = (key) => {
        if (selectedMetrics.includes(key)) {
            if (selectedMetrics.length > 1) setSelectedMetrics(selectedMetrics.filter(m => m !== key));
        } else setSelectedMetrics([...selectedMetrics, key]);
    };

    // What the page shows: the server's rows, or the example in demo mode.
    const demoData = useMemo(() => (demo.active ? buildTrendsDemo(groupBy) : null), [demo.active, groupBy]);
    const rows = demoData ? demoData.table : tableData;
    const rowTotals = demoData ? demoData.totals : totals;
    const isLoading = demoData ? false : loading;

    // Weekday buckets arrive as 0..6 (0 = Sunday); name them in the interface
    // language rather than showing the server's fixed Russian abbreviations.
    const periodLabel = (row) => {
        if (groupBy === 'day_of_week' && row.period_key !== undefined && /^[0-6]$/.test(String(row.period_key))) {
            // 2023-01-01 was a Sunday, so day N of that week is weekday N.
            const d = new Date(2023, 0, 1 + Number(row.period_key));
            return d.toLocaleDateString(LOCALE_TAGS[language] || 'en-US', { weekday: 'short' });
        }
        return row.period;
    };

    const formatMetric = (m, value) => {
        if (value === null || value === undefined) return '—';
        const n = Number(value) || 0;
        if (MONEY_METRICS.includes(m)) return `$${n.toFixed(2)}`;
        if (PERCENT_METRICS.includes(m)) return `${n.toFixed(2)}%`;
        return n.toLocaleString(LOCALE_TAGS[language] || 'en-US');
    };

    const { sorted: sortedRows, sort, onSort } = usePageTableSort(rows, {
        defaultKey: 'period', defaultDir: 'asc', getValue: trendSortValue, storageKey: 'orbitra_trends_sort',
    });

    // Chart built here from the rows rather than taken from the server: the
    // metric toggles redraw it at once (they never refetched, so the chart
    // used to keep showing the metrics the page was opened with), labels and
    // weekday names follow the UI language, and demo mode draws the same way.
    // Always chronological — sorting the table does not reorder the chart.
    const chart = useMemo(() => {
        if (!rows.length) return demoData ? null : chartData;
        return {
            labels: rows.map(periodLabel),
            datasets: selectedMetrics.map(key => {
                const meta = availableMetrics.find(am => am.key === key);
                const color = meta?.color || '#666666';
                return {
                    metric: key,
                    label: meta?.label || key,
                    data: rows.map(r => (r[key] === null || r[key] === undefined ? null : Number(r[key]))),
                    borderColor: color,
                    backgroundColor: color + '20',
                    // Percentages on their own right-hand axis: on the shared
                    // axis a 3% CR next to 1,500 clicks is a flat line at
                    // zero, and the CR trend is the thing worth seeing.
                    yAxisID: PERCENT_METRICS.includes(key) ? 'y1' : 'y',
                    fill: !PERCENT_METRICS.includes(key),
                    tension: 0.4,
                    spanGaps: true,
                };
            }),
        };
    // availableMetrics / periodLabel are rebuilt every render; what they
    // depend on (language, groupBy) is listed instead, so typing in the
    // filter modal does not redraw the chart.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rows, selectedMetrics, language, groupBy, demoData, chartData]);

    const exportCSV = () => {
        if (!rows.length) return;
        const headers = [t('trends.period'), ...selectedMetrics.map(m => availableMetrics.find(am => am.key === m)?.label || m)];
        const csvValue = (v) => (v === null || v === undefined ? '' : v);
        const csvRows = sortedRows.map(row => [periodLabel(row), ...selectedMetrics.map(m => csvValue(row[m] ?? 0))]);
        if (rowTotals) csvRows.push([t('trends.total'), ...selectedMetrics.map(m => csvValue(rowTotals[m] ?? 0))]);
        const csv = [headers.join(';'), ...csvRows.map(r => r.join(';'))].join('\n');
        const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `trends_${dateFrom}_${dateTo}.csv`;
        link.click();
    };

    const chartOptions = {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            // Legend hidden — the metric toggle buttons above already show name + colour.
            legend: { display: false },
            tooltip: { mode: 'index', intersect: false }
        },
        scales: {
            y: { beginAtZero: true, display: selectedMetrics.some(m => !PERCENT_METRICS.includes(m)) },
            y1: {
                position: 'right',
                display: selectedMetrics.some(m => PERCENT_METRICS.includes(m)),
                grid: { drawOnChartArea: false },
                ticks: { callback: (v) => `${v}%` },
            },
        },
        interaction: { mode: 'nearest', axis: 'x', intersect: false }
    };

    // Segmented switch between Trend and Cohort views. Persisted to localStorage
    // so a reload keeps the user on the same analytics sub-view.
    const viewSwitcher = (
        <div className="flex items-center gap-1 p-1 rounded-2xl"
            style={{ background: 'var(--color-bg-soft)', width: 'fit-content' }}>
            <button
                onClick={() => handleSetView('trend')}
                className="btn btn-sm"
                style={view === 'trend'
                    ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }
                    : { background: 'transparent', color: 'var(--color-text-secondary)', boxShadow: 'none' }}>
                <TrendingUp className="w-4 h-4" />{t('analytics.trends')}
            </button>
            <button
                onClick={() => handleSetView('cohort')}
                className="btn btn-sm"
                style={view === 'cohort'
                    ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }
                    : { background: 'transparent', color: 'var(--color-text-secondary)', boxShadow: 'none' }}>
                <Grid3x3 className="w-4 h-4" />{t('analytics.cohort')}
            </button>
        </div>
    );

    if (view === 'cohort') {
        return (
            <div className="space-y-4">
                {viewSwitcher}
                <CohortView />
            </div>
        );
    }

    const tourSteps = [
        { target: 'trends-grouping', title: t('demo.trends.groupingTitle'), text: t('demo.trends.groupingText') },
        { target: 'trends-metrics', title: t('demo.trends.metricsTitle'), text: t('demo.trends.metricsText') },
        { target: 'trends-chart', title: t('demo.trends.chartTitle'), text: t('demo.trends.chartText') },
        { target: 'trends-table-head', title: t('demo.trends.sortTitle'), text: t('demo.trends.sortText') },
        { target: 'trends-total', title: t('demo.trends.totalTitle'), text: t('demo.trends.totalText') },
    ];

    return (
        <div className="space-y-4">
            {seamNote && (
                <div className="text-sm text-[var(--color-text-secondary)] bg-[var(--color-bg-secondary)] rounded-lg px-4 py-2">
                    {t('dashboard.seamAvailableFrom', { date: seamNote.split(' ')[0] })}
                </div>
            )}
            {viewSwitcher}
            {demo.active ? (
                <DemoBar onReplay={demo.replay} onExit={demo.exit} />
            ) : (
                <InfoBanner storageKey="help_trends" title={t('help.trendsBannerTitle')} onDemo={demo.start}>
                    <p>{t('help.trendsBanner')}</p>
                </InfoBanner>
            )}
            {demo.active && demo.tour && <DemoTour steps={tourSteps} onClose={demo.closeTour} />}
            <div className="page-card">
                <div className="flex flex-wrap items-center gap-4">
                    <div className="flex items-center gap-2" data-tour="trends-grouping">
                        <PieChart className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                        <label className="form-label" style={{ margin: 0, marginBottom: 0 }}>{t('trends.grouping')}</label>
                        <select value={groupBy} onChange={(e) => setGroupBy(e.target.value)} className="form-select" style={{ width: 'auto', padding: '8px 12px' }}>
                            <option value="month">{t('trends.months')}</option>
                            <option value="day_of_week">{t('trends.daysOfWeek')}</option>
                            <option value="day">{t('trends.days')}</option>
                            <option value="hour">{t('trends.hours')}</option>
                        </select>
                    </div>
                    <div className="flex items-center gap-2">
                        <Calendar className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                        <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="form-input" style={{ width: 'auto', padding: '8px 12px' }} />
                        <span style={{ color: 'var(--color-text-muted)' }}>—</span>
                        <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="form-input" style={{ width: 'auto', padding: '8px 12px' }} />
                    </div>
                    <AnalyticsEntityFilters
                        campaignIds={selectedCampaignIds}
                        onCampaignChange={setSelectedCampaignIds}
                        offerIds={selectedOfferIds}
                        onOfferChange={setSelectedOfferIds}
                        landingIds={selectedLandingIds}
                        onLandingChange={setSelectedLandingIds}
                    />
                    <button onClick={() => setShowFilterModal(true)} className="btn btn-secondary btn-sm">
                        <Filter className="w-4 h-4" />{t('trends.addFilter')}
                    </button>
                    <button onClick={exportCSV} className="btn btn-secondary btn-sm" disabled={demo.active}>
                        <Download className="w-4 h-4" />{t('trends.exportCsv')}
                    </button>
                </div>
                {filterError && (
                    <div className="alert alert-error text-xs" style={{ marginTop: '12px' }}>{filterError}</div>
                )}
                {(filters.length > 0 || selectedCampaignIds.length > 0 || selectedOfferIds.length > 0 || selectedLandingIds.length > 0) && (
                    <div className="flex flex-wrap gap-2" style={{ marginTop: '12px' }}>
                        {filters.map(f => (
                            <span key={f.id} className="status-badge" style={{ background: 'var(--color-info-bg)', color: 'var(--color-info)' }}>
                                {filterFields.find(ff => ff.value === f.field)?.label} · {filterOperators.find(o => o.value === f.operator)?.label}: {f.value}
                                <button onClick={() => removeFilter(f.id)} style={{ marginLeft: '4px', cursor: 'pointer' }}>×</button>
                            </span>
                        ))}
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
            </div>

            <div className="page-card" data-tour="trends-metrics">
                <div className="flex items-center gap-2" style={{ marginBottom: '12px' }}>
                    <TrendingUp className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                    <span className="form-label" style={{ margin: 0 }}>{t('trends.metrics')}</span>
                </div>
                <div className="flex flex-wrap gap-2">
                    {availableMetrics.map(metric => (
                        <button key={metric.key} onClick={() => toggleMetric(metric.key)}
                            className={`btn btn-sm ${selectedMetrics.includes(metric.key) ? '' : 'btn-secondary'}`}
                            style={selectedMetrics.includes(metric.key) ? { backgroundColor: metric.color, color: 'white' } : {}}>
                            {metric.label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="page-card" data-tour="trends-chart">
                <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: '16px' }}>
                    <div className="flex items-center gap-2">
                        <BarChart3 className="w-5 h-5" style={{ color: 'var(--color-text-muted)' }} />
                        <h3 className="page-title">{t('trends.chartTitle')}</h3>
                    </div>
                </div>
                <div style={{ height: '320px' }}>
                    {isLoading ? (
                        <div className="empty-state"><p style={{ color: 'var(--color-text-muted)' }}>{t('trends.loading')}</p></div>
                    ) : chart ? (
                        <Line data={chart} options={chartOptions} />
                    ) : (
                        <div className="empty-state"><p style={{ color: 'var(--color-text-muted)' }}>{t('trends.noData')}</p></div>
                    )}
                </div>
            </div>

            <div className="page-card" style={{ padding: 0 }}>
                <div className="page-header" style={{ padding: '16px 24px', marginBottom: 0 }}>
                    <div className="flex items-center gap-2">
                        <Clock className="w-5 h-5" style={{ color: 'var(--color-text-muted)' }} />
                        <h3 className="page-title">{t('trends.reportTitle')}</h3>
                    </div>
                    <span style={{ fontSize: '14px', color: 'var(--color-text-muted)' }}>{rows.length} {t('trends.records')}</span>
                </div>
                {/* min-w-0: without it the wrapper grows past the card instead
                    of scrolling — overflow-x-auto alone is a no-op on a flex
                    child with default min-width:auto. */}
                <div className="overflow-x-auto min-w-0">
                    <table className="page-table">
                        {/* data-col on every header: the centred-default rule
                            (.page-table th:not([data-col]) !important) is for
                            tables that set no alignment of their own; these
                            sortable headers declare theirs inline (Period
                            left, metrics right) and must follow it. */}
                        <thead data-tour="trends-table-head">
                            <tr>
                                <PageSortTh data-col colKey="period" label={t('trends.period')} sort={sort} onSort={onSort} firstDir="asc" />
                                {selectedMetrics.map(m => (
                                    <PageSortTh key={m} data-col colKey={m} align="right" sort={sort} onSort={onSort}
                                        label={availableMetrics.find(am => am.key === m)?.label || m} />
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {isLoading ? (
                                <tr><td colSpan={selectedMetrics.length + 1} className="text-center" style={{ padding: '32px' }}>
                                    <div className="empty-state"><p style={{ color: 'var(--color-text-muted)' }}>{t('trends.loading')}</p></div>
                                </td></tr>
                            ) : rows.length === 0 ? (
                                <tr><td colSpan={selectedMetrics.length + 1} className="text-center" style={{ padding: '32px' }}>
                                    <div className="empty-state">
                                        <p className="empty-state-title">{t('trends.noDataTitle')}</p>
                                        <p className="empty-state-text">{t('trends.noDataPeriod')}</p>
                                    </div>
                                </td></tr>
                            ) : (
                                sortedRows.map(row => (
                                    <tr key={row.period_key ?? row.period}>
                                        <td style={{ fontWeight: 500 }}>{periodLabel(row)}</td>
                                        {selectedMetrics.map(m => (
                                            <td key={m} style={{ textAlign: 'right', color: 'var(--color-text-secondary)', fontVariantNumeric: 'tabular-nums' }}>
                                                {formatMetric(m, row[m])}
                                            </td>
                                        ))}
                                    </tr>
                                ))
                            )}
                        </tbody>
                        {!isLoading && rows.length > 0 && rowTotals && (
                            <tfoot data-tour="trends-total">
                                <tr>
                                    <td title={t('trends.totalHint')}>{t('trends.total')}</td>
                                    {selectedMetrics.map(m => (
                                        <td key={m} style={{ textAlign: 'right' }}>{formatMetric(m, rowTotals[m])}</td>
                                    ))}
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            </div>

            {showFilterModal && (
                <div className="modal-overlay">
                    <div className="modal-content">
                        <div className="modal-header"><h3 className="modal-title">{t('trends.addFilterTitle')}</h3></div>
                        <div className="space-y-4">
                            <div>
                                <label className="form-label">{t('trends.field')}</label>
                                <select value={newFilter.field} onChange={(e) => setNewFilter({ ...newFilter, field: e.target.value })} className="form-select">
                                    {filterFields.map(f => (<option key={f.value} value={f.value}>{f.label}</option>))}
                                </select>
                            </div>
                            <div>
                                <label className="form-label">{t('trends.condition')}</label>
                                <select value={newFilter.operator} onChange={(e) => setNewFilter({ ...newFilter, operator: e.target.value })} className="form-select">
                                    {filterOperators.map(o => (<option key={o.value} value={o.value}>{o.label}</option>))}
                                </select>
                            </div>
                            <div>
                                <label className="form-label">{t('trends.value')}</label>
                                <input type="text" value={newFilter.value} onChange={(e) => setNewFilter({ ...newFilter, value: e.target.value })} className="form-input" placeholder={newFilter.operator.startsWith('regexp') ? t('trends.regexpPlaceholder') : t('trends.valuePlaceholder')} />
                                {newFilter.operator.startsWith('regexp') && (
                                    <p className="text-xs text-gray-500 mt-1">{t('trends.regexpHint')}</p>
                                )}
                            </div>
                        </div>
                        <div className="modal-footer">
                            <button onClick={() => setShowFilterModal(false)} className="btn btn-secondary">{t('trends.cancel')}</button>
                            <button onClick={addFilter} className="btn btn-primary">{t('trends.add')}</button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default TrendsPage;
