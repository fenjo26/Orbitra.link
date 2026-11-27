import React, { useState, useEffect, useMemo, useCallback } from 'react';
import {
    Calendar, Filter, Download, BarChart3, TrendingUp, Clock, PieChart, Grid3x3,
    FileChartColumn, GitCompareArrows, Palette, Sparkles, AlertTriangle
} from 'lucide-react';
import InfoBanner from './InfoBanner';
import CohortView from './CohortView';
import ReportsPage from './ReportsPage';
import AnalyticsCompareView from './AnalyticsCompareView';
import DateRangePicker from './DateRangePicker';
import AnalyticsEntityFilters from './common/AnalyticsEntityFilters';
import { DeltaBadge } from './common/PeriodCompare';
import SeriesStylePopover, { StyleSwatch } from './common/SeriesStylePopover';
import { PageSortTh } from './common/PageTableSort';
import { usePageTableSort } from './common/usePageTableSort';
import { DemoTour, DemoBar } from './common/DemoTour';
import { useDemoMode } from './common/useDemoMode';
import { buildTrendsDemo } from '../utils/analyticsDemo';
import { canAccessTab } from '../utils/permissions';
import { useTimezone } from '../utils/useTimezone';
import { formatDate, getPresetDates } from '../utils/dateRange';
import { shiftedPeriod, metricDelta, nowInTimezone } from '../utils/periodCompare';
import {
    clearMetricColorCache,
    LINE_DASH_MAP,
    metricAxisId,
    metricColorRgb,
    metricStyle,
    rgbToCss,
    rgbToHex,
} from '../utils/chartMetrics';
import { Chart } from 'react-chartjs-2';
import {
    Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement,
    BarElement, LineController, BarController, Title, Tooltip, Legend, Filler
} from 'chart.js';
import { useLanguage } from '../contexts/LanguageContext';

ChartJS.register(
    CategoryScale, LinearScale, PointElement, LineElement,
    BarElement, LineController, BarController, Title, Tooltip, Legend, Filler
);

const API_URL = '/api.php';
const TRENDS_STYLE_STORAGE_KEY = 'orbitra_trends_metric_styles';

const LOCALE_TAGS = {
    ru: 'ru-RU', en: 'en-US', uk: 'uk-UA', es: 'es-ES',
    zh: 'zh-CN', fr: 'fr-FR', de: 'de-DE', ko: 'ko-KR',
};
const MONEY_METRICS = ['revenue', 'real_revenue', 'cost', 'profit', 'cpa'];
const UNIT_MONEY_METRICS = ['epc'];
const PERCENT_METRICS = ['ctr', 'cr', 'roi', 'real_roi'];

const trendSortValue = (row, key) => (key === 'period' ? (row.period_key ?? row.period) : row[key]);

const useDebounce = (value, delay) => {
    const [debouncedValue, setDebouncedValue] = useState(value);
    useEffect(() => {
        const handler = setTimeout(() => setDebouncedValue(value), delay);
        return () => clearTimeout(handler);
    }, [value, delay]);
    return debouncedValue;
};

const readLocalStyles = () => {
    try {
        const raw = JSON.parse(localStorage.getItem(TRENDS_STYLE_STORAGE_KEY) || '{}');
        return {
            colors: raw && typeof raw.colors === 'object' ? raw.colors : {},
            styles: raw && typeof raw.styles === 'object' ? raw.styles : {},
            fills: raw && typeof raw.fills === 'object' ? raw.fills : {},
        };
    } catch {
        return { colors: {}, styles: {}, fills: {} };
    }
};

const formatShortDate = (iso, locale) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
    if (!m) return iso || '';
    try {
        return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', timeZone: 'UTC' })
            .format(new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])));
    } catch {
        return iso;
    }
};

const TrendsPage = ({ user, chartPrefs = null, currency = 'USD' }) => {
    const { t, language } = useLanguage();
    const localeTag = LOCALE_TAGS[language] || 'en-US';
    const [timezone, setTimezone] = useTimezone();
    const demoMode = useDemoMode();
    const canSeeReports = canAccessTab(user, 'reports');

    // Repaint canvas gradients & CSS-var colours on theme change.
    const [themeTick, setThemeTick] = useState(0);
    useEffect(() => {
        const onThemeChange = () => {
            clearMetricColorCache();
            setThemeTick((n) => n + 1);
        };
        window.addEventListener('themeChanged', onThemeChange);
        window.addEventListener('storage', onThemeChange);
        return () => {
            window.removeEventListener('themeChanged', onThemeChange);
            window.removeEventListener('storage', onThemeChange);
        };
    }, []);

    const [view, setView] = useState(() => {
        const saved = localStorage.getItem('orbitra_analytics_view');
        if (saved === 'reports' && canSeeReports) return 'reports';
        if (saved === 'trend' || saved === 'compare' || saved === 'cohort') return saved;
        return canSeeReports ? 'reports' : 'trend';
    });
    const effectiveView = view === 'reports' && !canSeeReports ? 'trend' : view;
    const handleSetView = (v) => {
        setView(v);
        try { localStorage.setItem('orbitra_analytics_view', v); } catch { /* ignore */ }
    };

    const [groupBy, setGroupBy] = useState('day');
    const [datePreset, setDatePreset] = useState('last7Days');
    const [dateFrom, setDateFrom] = useState(() => {
        const r = getPresetDates('last7Days');
        return r?.from || formatDate(new Date(Date.now() - 6 * 86400000));
    });
    const [dateTo, setDateTo] = useState(() => {
        const r = getPresetDates('last7Days');
        return r?.to || formatDate(new Date());
    });

    // Period comparison state (persisted toggle + preset + optional custom dates).
    const [compareOn, setCompareOn] = useState(() => {
        try { return localStorage.getItem('orbitra_trends_compare') === '1'; } catch { return false; }
    });
    const [comparePreset, setComparePreset] = useState(() => {
        try { return localStorage.getItem('orbitra_trends_compare_preset') || 'prev'; } catch { return 'prev'; }
    });
    const [customCompareFrom, setCustomCompareFrom] = useState(() => shiftedPeriod(dateFrom, dateTo, 'wow')?.from || dateFrom);
    const [customCompareTo, setCustomCompareTo] = useState(() => shiftedPeriod(dateFrom, dateTo, 'wow')?.to || dateTo);
    const [pacingOn, setPacingOn] = useState(true);

    // Local per-metric style overrides (on top of Dashboard chartPrefs + theme defaults).
    const [localStyles, setLocalStyles] = useState(readLocalStyles);
    const [openStyleMetric, setOpenStyleMetric] = useState(null);

    const persistLocalStyles = (next) => {
        setLocalStyles(next);
        try { localStorage.setItem(TRENDS_STYLE_STORAGE_KEY, JSON.stringify(next)); } catch { /* ignore */ }
    };

    const mergedChartPrefs = useMemo(() => ({
        colors: { ...(chartPrefs?.colors || {}), ...(localStyles.colors || {}) },
        styles: { ...(chartPrefs?.styles || {}), ...(localStyles.styles || {}) },
    }), [chartPrefs, localStyles]);

    const [selectedMetrics, setSelectedMetrics] = useState(['clicks', 'unique_clicks', 'conversions', 'revenue', 'profit', 'cr']);
    const [filters, setFilters] = useState([]);
    const [selectedCampaignIds, setSelectedCampaignIds] = useState([]);
    const [selectedOfferIds, setSelectedOfferIds] = useState([]);
    const [selectedLandingIds, setSelectedLandingIds] = useState([]);
    const [showFilterModal, setShowFilterModal] = useState(false);
    const [newFilter, setNewFilter] = useState({ field: 'country_code', operator: 'contains', value: '' });
    const [loading, setLoading] = useState(true);
    const [tableData, setTableData] = useState([]);
    const [totals, setTotals] = useState(null);
    const [pacingTotals, setPacingTotals] = useState(null);
    const [compareData, setCompareData] = useState(null);
    const [seamNote, setSeamNote] = useState(null);
    const [filterError, setFilterError] = useState('');

    const debouncedCustomCompareFrom = useDebounce(customCompareFrom, 350);
    const debouncedCustomCompareTo = useDebounce(customCompareTo, 350);

    // Effective comparison date range. Only raw custom <input type="date"> is debounced;
    // DateRangePicker commits dateFrom/dateTo atomically on Apply/preset click, so
    // groupBy, dateFrom, dateTo and preset-derived compareRange always stay in sync.
    const compareRange = useMemo(() => {
        if (!compareOn) return null;
        if (comparePreset === 'custom') {
            if (!debouncedCustomCompareFrom || !debouncedCustomCompareTo) return null;
            return debouncedCustomCompareFrom <= debouncedCustomCompareTo
                ? { from: debouncedCustomCompareFrom, to: debouncedCustomCompareTo }
                : { from: debouncedCustomCompareTo, to: debouncedCustomCompareFrom };
        }
        return shiftedPeriod(dateFrom, dateTo, comparePreset);
    }, [compareOn, comparePreset, dateFrom, dateTo, debouncedCustomCompareFrom, debouncedCustomCompareTo]);

    // Check if primary range ends today in the active timezone → Pacing applies!
    const tzNow = useMemo(() => nowInTimezone(timezone), [timezone]);
    const endsToday = dateTo === tzNow.date;
    const isSingleDay = dateFrom === dateTo;
    const activePacingHour = compareOn && endsToday && pacingOn ? tzNow.hour : null;

    const handleToggleCompare = () => {
        const next = !compareOn;
        setCompareOn(next);
        try { localStorage.setItem('orbitra_trends_compare', next ? '1' : '0'); } catch { /* ignore */ }
        if (next && comparePreset === 'custom' && (!customCompareFrom || !customCompareTo)) {
            const def = shiftedPeriod(dateFrom, dateTo, 'wow') || shiftedPeriod(dateFrom, dateTo, 'prev');
            if (def) {
                setCustomCompareFrom(def.from);
                setCustomCompareTo(def.to);
            }
        }
    };

    const handleSelectComparePreset = (preset) => {
        setComparePreset(preset);
        try { localStorage.setItem('orbitra_trends_compare_preset', preset); } catch { /* ignore */ }
        if (preset === 'custom') {
            const base = shiftedPeriod(dateFrom, dateTo, comparePreset === 'custom' ? 'prev' : comparePreset)
                || shiftedPeriod(dateFrom, dateTo, 'prev');
            if (base) {
                setCustomCompareFrom(base.from);
                setCustomCompareTo(base.to);
            }
        }
    };

    const handleDateRangeChange = (from, to, preset) => {
        setDateFrom(from);
        setDateTo(to);
        if (preset) setDatePreset(preset);
        // "Today" or "Yesterday" single-day pick reads best hourly when comparing.
        if (from === to && groupBy === 'day' && (preset === 'today' || preset === 'yesterday')) {
            setGroupBy('hour');
        } else if (from !== to && groupBy === 'hour') {
            const days = Math.round((new Date(to) - new Date(from)) / 86400000) + 1;
            if (days > 4) setGroupBy('day');
        }
    };

    // Demo mode state
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

    const compareFromParam = compareRange?.from || '';
    const compareToParam = compareRange?.to || '';

    const availableMetrics = useMemo(() => [
        { key: 'clicks', label: t('trends.clicks') },
        { key: 'unique_clicks', label: t('trends.uniqueClicks') },
        { key: 'conversions', label: t('trends.conversions') },
        { key: 'revenue', label: t('trends.revenue') },
        { key: 'real_revenue', label: t('trends.realRevenue') },
        { key: 'cost', label: t('trends.cost') },
        { key: 'profit', label: t('trends.profit') },
        { key: 'roi', label: t('metrics.roi', 'ROI') },
        { key: 'real_roi', label: t('trends.realRoi') },
        { key: 'ctr', label: t('trends.ctr') },
        { key: 'cr', label: t('trends.cr') },
        { key: 'epc', label: t('metrics.epc', 'EPC') },
        { key: 'cpa', label: t('metrics.cpa', 'CPA') },
    ], [t]);

    const filterFields = [
        { value: 'country_code', label: t('trends.country') },
        { value: 'device_type', label: t('trends.device') },
        { value: 'campaign_id', label: t('trends.campaignId') },
        { value: 'offer_id', label: t('trends.offerId') },
        { value: 'ip', label: t('trends.ipAddress') },
        { value: 'browser', label: t('trends.browser') },
        { value: 'os', label: t('trends.os') },
        { value: 'source', label: t('trends.source') },
    ];

    const filterOperators = [
        { value: 'contains', label: t('trends.contains') },
        { value: 'not_contains', label: t('trends.notContains') },
        { value: 'equals', label: t('trends.equals') },
        { value: 'not_equals', label: t('trends.notEquals') },
        { value: 'starts_with', label: t('trends.startsWith') },
        { value: 'ends_with', label: t('trends.endsWith') },
        { value: 'regexp', label: t('trends.regexp') },
        { value: 'regexp_exclude', label: t('trends.regexpExclude') },
    ];

    const filtersKey = JSON.stringify(filters);
    const campaignIdsKey = JSON.stringify(selectedCampaignIds);
    const offerIdsKey = JSON.stringify(selectedOfferIds);
    const landingIdsKey = JSON.stringify(selectedLandingIds);

    const fetchTrends = useCallback(async (signal) => {
        if (demo.active) return;
        setLoading(true);
        try {
            const activeFilters = [...filters];
            if (selectedCampaignIds.length === 1) {
                activeFilters.push({ field: 'campaign_id', operator: 'equals', value: String(selectedCampaignIds[0]) });
            } else if (selectedCampaignIds.length > 1) {
                activeFilters.push({ field: 'campaign_id', operator: 'in', value: selectedCampaignIds.join(',') });
            }
            if (selectedOfferIds.length === 1) {
                activeFilters.push({ field: 'offer_id', operator: 'equals', value: String(selectedOfferIds[0]) });
            } else if (selectedOfferIds.length > 1) {
                activeFilters.push({ field: 'offer_id', operator: 'in', value: selectedOfferIds.join(',') });
            }
            if (selectedLandingIds.length === 1) {
                activeFilters.push({ field: 'landing_id', operator: 'equals', value: String(selectedLandingIds[0]) });
            } else if (selectedLandingIds.length > 1) {
                activeFilters.push({ field: 'landing_id', operator: 'in', value: selectedLandingIds.join(',') });
            }

            const params = new URLSearchParams({
                action: 'trends',
                group_by: groupBy,
                date_from: dateFrom,
                date_to: dateTo,
                metrics: selectedMetrics.join(','),
                filters: JSON.stringify(activeFilters),
            });
            if (timezone) params.set('timezone', timezone);
            if (compareOn && compareFromParam && compareToParam) {
                params.set('compare_from', compareFromParam);
                params.set('compare_to', compareToParam);
                if (activePacingHour !== null) {
                    params.set('pacing_cutoff_hour', String(activePacingHour));
                }
            }

            const res = await fetch(`${API_URL}?${params}`, { signal });
            const data = await res.json();
            if (signal?.aborted) return;
            setSeamNote(data.stats_seam?.raw_available_from ? data.stats_seam.raw_available_from : null);
            if (data.status === 'error' && data.code === 'invalid_regex') {
                setFilterError(t('trends.invalidRegex', { pattern: data.pattern }));
                setTableData([]);
                setTotals(null);
                setPacingTotals(null);
                setCompareData(null);
                return;
            }
            setFilterError('');
            if (data.status === 'success') {
                setTableData(Array.isArray(data.data.table) ? data.data.table : []);
                setTotals(data.data.totals || null);
                setPacingTotals(data.data.pacing_totals || null);
                setCompareData(data.data.compare || null);
            }
        } catch (e) {
            if (e?.name === 'AbortError' || signal?.aborted) return;
            console.error('Error fetching trends:', e);
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        demo.active, groupBy, dateFrom, dateTo, timezone,
        compareOn, compareFromParam, compareToParam, activePacingHour,
        filtersKey, campaignIdsKey, offerIdsKey, landingIdsKey,
    ]);

    useEffect(() => {
        if (effectiveView !== 'trend') return;
        const controller = new AbortController();
        fetchTrends(controller.signal);
        return () => controller.abort();
    }, [effectiveView, fetchTrends]);

    const addFilter = () => {
        if (newFilter.value.trim()) {
            setFilters([...filters, { ...newFilter, id: Date.now() }]);
            setNewFilter({ field: 'country_code', operator: 'contains', value: '' });
            setShowFilterModal(false);
        }
    };
    const removeFilter = (id) => setFilters(filters.filter((f) => f.id !== id));

    const toggleMetric = (key) => {
        if (selectedMetrics.includes(key)) {
            if (selectedMetrics.length > 1) setSelectedMetrics(selectedMetrics.filter((m) => m !== key));
        } else {
            setSelectedMetrics([...selectedMetrics, key]);
        }
    };

    const demoData = useMemo(() => (demo.active ? buildTrendsDemo(groupBy) : null), [demo.active, groupBy]);
    const rows = demoData ? demoData.table : tableData;
    const rowTotals = demoData ? demoData.totals : totals;
    const effectiveCompare = demoData ? null : (compareOn ? compareData : null);
    const compareRows = useMemo(() => effectiveCompare?.table || [], [effectiveCompare]);
    const isLoading = demoData ? false : loading;

    const periodLabel = useCallback((row) => {
        if (!row) return '';
        if (groupBy === 'day_of_week' && row.period_key !== undefined && /^[0-6]$/.test(String(row.period_key))) {
            const d = new Date(2023, 0, 1 + Number(row.period_key));
            return d.toLocaleDateString(localeTag, { weekday: 'short' });
        }
        if (groupBy === 'hour' && typeof row.period_key === 'string' && row.period_key.length >= 16) {
            if (isSingleDay) return row.period_key.slice(11, 16);
            return `${formatShortDate(row.period_key.slice(0, 10), localeTag)} ${row.period_key.slice(11, 16)}`;
        }
        if (groupBy === 'day' && typeof row.period_key === 'string') {
            return formatShortDate(row.period_key, localeTag);
        }
        return row.period;
    }, [groupBy, localeTag, isSingleDay]);

    const currencyCode = (currency || 'USD').toUpperCase();
    const formatMetric = useCallback((m, value) => {
        if (value === null || value === undefined) return '—';
        const n = Number(value) || 0;
        if (UNIT_MONEY_METRICS.includes(m)) {
            try {
                return new Intl.NumberFormat(localeTag, {
                    style: 'currency', currency: currencyCode,
                    maximumFractionDigits: Math.abs(n) < 1 ? 4 : 2,
                }).format(n);
            } catch {
                return `$${n.toFixed(Math.abs(n) < 1 ? 4 : 2)}`;
            }
        }
        if (MONEY_METRICS.includes(m)) {
            try {
                return new Intl.NumberFormat(localeTag, {
                    style: 'currency', currency: currencyCode,
                    maximumFractionDigits: 2,
                }).format(n);
            } catch {
                return `$${n.toFixed(2)}`;
            }
        }
        if (PERCENT_METRICS.includes(m)) return `${n.toFixed(2)}%`;
        return n.toLocaleString(localeTag);
    }, [localeTag, currencyCode]);

    // Align Period B rows to Period A rows for both the chart and the table.
    const alignedCompareRows = useMemo(() => {
        if (!effectiveCompare || !compareRows.length) return [];
        if (groupBy === 'day_of_week') {
            const byKey = new Map(compareRows.map((r) => [String(r.period_key), r]));
            return rows.map((r) => byKey.get(String(r.period_key)) || null);
        }
        const len = Math.max(rows.length, compareRows.length);
        return Array.from({ length: len }, (_, i) => compareRows[i] || null);
    }, [effectiveCompare, compareRows, rows, groupBy]);

    // Enrich rows with index so table sorting still knows each row's aligned compare partner.
    const tableRowsWithCompare = useMemo(() => rows.map((r, i) => ({
        ...r,
        __compare: alignedCompareRows[i] || null,
    })), [rows, alignedCompareRows]);

    const { sorted: sortedRows, sort, onSort } = usePageTableSort(tableRowsWithCompare, {
        defaultKey: 'period', defaultDir: 'asc', getValue: trendSortValue, storageKey: 'orbitra_trends_sort',
    });

    // Helper for reading CSS variables in Canvas 2D.
    const getCssVar = (name, fallback) => {
        if (typeof window !== 'undefined') {
            const val = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            if (val) return val;
        }
        return fallback;
    };

    // Build Chart.js data & multi-axis scales using Dashboard's theme/palette engine.
    const { chartObj, chartOptions } = useMemo(() => {
        const len = effectiveCompare && groupBy !== 'day_of_week'
            ? Math.max(rows.length, compareRows.length)
            : rows.length;
        if (!len) return { chartObj: null, chartOptions: {} };

        const labels = Array.from({ length: len }, (_, i) => {
            const rowA = rows[i];
            const rowB = alignedCompareRows[i];
            if (rowA) return periodLabel(rowA);
            if (rowB) {
                if (groupBy === 'hour' && typeof rowB.period_key === 'string' && rowB.period_key.length >= 16) {
                    return rowB.period_key.slice(11, 16);
                }
                return `${t('analyticsCompare.stepDay', 'Day')} ${i + 1}`;
            }
            return String(i + 1);
        });

        const futureCutoffKey = (groupBy === 'hour' && endsToday && pacingOn)
            ? `${tzNow.date} ${String(tzNow.hour).padStart(2, '0')}:00:00`
            : null;

        const datasets = [];
        const axisIdsSet = new Set();

        selectedMetrics.forEach((key) => {
            const metaLabel = availableMetrics.find((am) => am.key === key)?.label || key;
            const rgb = metricColorRgb(key, mergedChartPrefs);
            const color = rgbToCss(rgb);
            const style = metricStyle(key, mergedChartPrefs);
            const axisId = metricAxisId(key);
            axisIdsSet.add(axisId);

            const hasExplicitFill = Object.prototype.hasOwnProperty.call(localStyles.fills || {}, key);
            const shouldFill = hasExplicitFill
                ? Boolean(localStyles.fills[key])
                : (style === 'solid' && !PERCENT_METRICS.includes(key) && selectedMetrics.length <= 4);

            const dataA = Array.from({ length: len }, (_, i) => {
                const r = rows[i];
                if (!r) return null;
                if (futureCutoffKey && String(r.period_key) > futureCutoffKey) return null;
                return r[key] === null || r[key] === undefined ? null : Number(r[key]);
            });

            if (style === 'bars') {
                datasets.push({
                    type: 'bar',
                    metric: key,
                    isCompare: false,
                    label: metaLabel,
                    data: dataA,
                    yAxisID: axisId,
                    borderColor: color,
                    backgroundColor: rgbToCss(rgb, 0.55),
                    borderWidth: 1,
                    borderRadius: 3,
                    maxBarThickness: 18,
                    order: 10,
                });
            } else {
                datasets.push({
                    type: 'line',
                    metric: key,
                    isCompare: false,
                    label: metaLabel,
                    data: dataA,
                    yAxisID: axisId,
                    borderColor: color,
                    borderDash: LINE_DASH_MAP[style] || [],
                    borderCapStyle: style === 'dotted' ? 'round' : 'butt',
                    backgroundColor: shouldFill
                        ? (context) => {
                            const { chart } = context;
                            const area = chart.chartArea;
                            if (!area) return rgbToCss(rgb, 0.12);
                            const g = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
                            g.addColorStop(0, rgbToCss(rgb, 0.18));
                            g.addColorStop(1, rgbToCss(rgb, 0));
                            return g;
                        }
                        : rgbToCss(rgb, 0),
                    fill: shouldFill,
                    cubicInterpolationMode: 'monotone',
                    tension: 0.3,
                    borderWidth: 2.5,
                    pointRadius: len <= 2 ? 3 : 0,
                    pointHoverRadius: 5,
                    pointBackgroundColor: color,
                    spanGaps: PERCENT_METRICS.includes(key),
                    order: 1,
                });
            }

            // Comparison period (Period B): ALWAYS dashed by default in the exact same colour, no fill!
            if (effectiveCompare && alignedCompareRows.length > 0) {
                const dataB = Array.from({ length: len }, (_, i) => {
                    const r = alignedCompareRows[i];
                    if (!r) return null;
                    return r[key] === null || r[key] === undefined ? null : Number(r[key]);
                });
                const softColor = rgbToCss(rgb, 0.72);
                datasets.push({
                    type: 'line',
                    metric: key,
                    isCompare: true,
                    label: `${metaLabel} (${t('trends.prevPeriodShort', 'prev')})`,
                    data: dataB,
                    yAxisID: axisId,
                    borderColor: softColor,
                    borderDash: [6, 5],
                    backgroundColor: 'transparent',
                    fill: false,
                    cubicInterpolationMode: 'monotone',
                    tension: 0.3,
                    borderWidth: 1.8,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointBackgroundColor: softColor,
                    spanGaps: PERCENT_METRICS.includes(key),
                    order: 2,
                });
            }
        });

        const axisIds = [...axisIdsSet];
        const hasTraffic = axisIds.includes('yTraffic');
        const hasEvents = axisIds.includes('yEvents');
        const hasMoney = axisIds.includes('yMoney');
        const leftAxisId = hasTraffic ? 'yTraffic' : (hasEvents ? 'yEvents' : (axisIds[0] || null));
        const rightAxisId = hasMoney && leftAxisId !== 'yMoney'
            ? 'yMoney'
            : (axisIds.find((id) => id !== leftAxisId) || null);

        const mutedColor = getCssVar('--color-text-muted', '#6B7280');
        const borderColor = getCssVar('--color-border', '#E5E7EB');
        const cardBg = getCssVar('--color-bg-card', 'rgba(15, 23, 42, 0.94)');
        const textPrimary = getCssVar('--color-text-primary', '#111827');

        const scales = {
            x: {
                grid: { display: false },
                ticks: { color: mutedColor, maxRotation: 0, autoSkipPadding: 14 },
            },
        };
        axisIds.forEach((axisId) => {
            const isLeft = axisId === leftAxisId;
            const isRight = axisId === rightAxisId;
            const isPercentAxis = axisId === 'yRoi' || axisId === 'y_cr' || axisId === 'y_ctr';
            scales[axisId] = {
                type: 'linear',
                display: isLeft || isRight,
                position: isRight ? 'right' : 'left',
                beginAtZero: true,
                grace: '8%',
                grid: {
                    drawOnChartArea: isLeft || (isRight && !leftAxisId),
                    color: borderColor,
                },
                ticks: {
                    color: mutedColor,
                    callback: (val) => {
                        if (isPercentAxis) return `${val}%`;
                        const n = Number(val);
                        if (!Number.isFinite(n)) return val;
                        if (Math.abs(n) >= 10000) {
                            try { return new Intl.NumberFormat(localeTag, { notation: 'compact', maximumFractionDigits: 1 }).format(n); }
                            catch { return String(n); }
                        }
                        return String(Math.round(n * 100) / 100);
                    },
                },
            };
        });

        const options = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', axis: 'x', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: cardBg,
                    titleColor: textPrimary,
                    bodyColor: textPrimary,
                    borderColor,
                    borderWidth: 1,
                    padding: 12,
                    cornerRadius: 10,
                    filter: (item) => item.raw !== null && item.raw !== undefined,
                    callbacks: {
                        title: (items) => {
                            const idx = items?.[0]?.dataIndex;
                            if (idx === undefined) return '';
                            const rA = rows[idx];
                            const rB = alignedCompareRows[idx];
                            const lblA = rA ? periodLabel(rA) : '—';
                            if (!effectiveCompare) return lblA;
                            const lblB = rB ? periodLabel(rB) : '—';
                            return `${lblA}  vs  ${lblB}`;
                        },
                        label: (context) => {
                            const ds = context.dataset;
                            const mKey = ds.metric;
                            const formatted = formatMetric(mKey, context.raw);
                            if (!effectiveCompare || ds.isCompare) {
                                return `${ds.label}: ${formatted}`;
                            }
                            // Append inline delta vs the paired comparison bucket!
                            const rB = alignedCompareRows[context.dataIndex];
                            const prevVal = rB ? rB[mKey] : null;
                            const d = metricDelta(mKey, context.raw, prevVal);
                            if (!d) return `${ds.label}: ${formatted}`;
                            const dTxt = d.isNew ? t('periodCompare.new') : (d.points ? `${d.text} ${t('periodCompare.points')}` : d.text);
                            return `${ds.label}: ${formatted} (${dTxt})`;
                        },
                    },
                },
            },
            scales,
        };

        return { chartObj: { labels, datasets }, chartOptions: options };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        rows, alignedCompareRows, compareRows.length, effectiveCompare, selectedMetrics,
        availableMetrics, mergedChartPrefs, localStyles, groupBy, periodLabel, formatMetric,
        endsToday, pacingOn, tzNow.date, tzNow.hour, localeTag, themeTick, t,
    ]);

    const exportCSV = () => {
        if (!rows.length) return;
        const headers = [
            t('trends.period'),
            ...selectedMetrics.flatMap((m) => {
                const lbl = availableMetrics.find((am) => am.key === m)?.label || m;
                return effectiveCompare ? [lbl, `${lbl} (${t('trends.prevPeriodShort', 'prev')})`] : [lbl];
            }),
        ];
        const csvValue = (v) => (v === null || v === undefined ? '' : v);
        const csvRows = sortedRows.map((row) => [
            periodLabel(row),
            ...selectedMetrics.flatMap((m) => effectiveCompare
                ? [csvValue(row[m] ?? 0), csvValue(row.__compare?.[m] ?? '')]
                : [csvValue(row[m] ?? 0)]),
        ]);
        if (rowTotals) {
            const cmpTotals = activePacingHour !== null && effectiveCompare?.pacing_totals
                ? effectiveCompare.pacing_totals
                : effectiveCompare?.totals;
            csvRows.push([
                t('trends.total'),
                ...selectedMetrics.flatMap((m) => effectiveCompare
                    ? [csvValue(rowTotals[m] ?? 0), csvValue(cmpTotals?.[m] ?? '')]
                    : [csvValue(rowTotals[m] ?? 0)]),
            ]);
        }
        const csv = [headers.join(';'), ...csvRows.map((r) => r.join(';'))].join('\n');
        const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `trends_${dateFrom}_${dateTo}.csv`;
        link.click();
    };

    // Totals used for KPI summary cards (pacing-adjusted when comparing an in-progress today).
    const summaryCurTotals = (effectiveCompare && activePacingHour !== null && pacingTotals) ? pacingTotals : rowTotals;
    const summaryCmpTotals = (effectiveCompare && activePacingHour !== null && effectiveCompare.pacing_totals)
        ? effectiveCompare.pacing_totals
        : effectiveCompare?.totals;

    // 4-tab switcher: Reports | Trends | Compare | Cohort
    const viewSwitcher = (
        <div className="flex flex-wrap items-center gap-1 p-1 rounded-2xl"
            style={{ background: 'var(--color-bg-soft)', width: 'fit-content' }}>
            {canSeeReports && (
                <button
                    onClick={() => handleSetView('reports')}
                    className="btn btn-sm"
                    style={effectiveView === 'reports'
                        ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }
                        : { background: 'transparent', color: 'var(--color-text-secondary)', boxShadow: 'none' }}>
                    <FileChartColumn className="w-4 h-4" />{t('nav.reports')}
                </button>
            )}
            <button
                onClick={() => handleSetView('trend')}
                className="btn btn-sm"
                style={effectiveView === 'trend'
                    ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }
                    : { background: 'transparent', color: 'var(--color-text-secondary)', boxShadow: 'none' }}>
                <TrendingUp className="w-4 h-4" />{t('analytics.trends')}
            </button>
            <button
                onClick={() => handleSetView('compare')}
                className="btn btn-sm"
                style={effectiveView === 'compare'
                    ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }
                    : { background: 'transparent', color: 'var(--color-text-secondary)', boxShadow: 'none' }}>
                <GitCompareArrows className="w-4 h-4" />{t('analytics.compare', 'Compare')}
            </button>
            <button
                onClick={() => handleSetView('cohort')}
                className="btn btn-sm"
                style={effectiveView === 'cohort'
                    ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }
                    : { background: 'transparent', color: 'var(--color-text-secondary)', boxShadow: 'none' }}>
                <Grid3x3 className="w-4 h-4" />{t('analytics.cohort')}
            </button>
        </div>
    );

    if (effectiveView === 'reports') {
        return (
            <div className="space-y-4">
                {viewSwitcher}
                <ReportsPage />
            </div>
        );
    }

    if (effectiveView === 'compare') {
        return (
            <div className="space-y-4">
                {viewSwitcher}
                <AnalyticsCompareView chartPrefs={chartPrefs} currency={currency} />
            </div>
        );
    }

    if (effectiveView === 'cohort') {
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

            {/* Main Controls Card */}
            <div className="page-card space-y-3">
                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex items-center gap-2" data-tour="trends-grouping">
                        <PieChart className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                        <label className="form-label" style={{ margin: 0 }}>{t('trends.grouping')}</label>
                        <select value={groupBy} onChange={(e) => setGroupBy(e.target.value)} className="form-select" style={{ width: 'auto', padding: '7px 12px' }}>
                            <option value="month">{t('trends.months')}</option>
                            <option value="day_of_week">{t('trends.daysOfWeek')}</option>
                            <option value="day">{t('trends.days')}</option>
                            <option value="hour">{t('trends.hours')}</option>
                        </select>
                    </div>

                    <DateRangePicker
                        dateFrom={dateFrom}
                        dateTo={dateTo}
                        onChange={handleDateRangeChange}
                        initialPreset={datePreset}
                        selectedTimezone={timezone}
                        onTimezoneChange={setTimezone}
                    />

                    {/* Period Compare Toggle */}
                    <button
                        type="button"
                        onClick={handleToggleCompare}
                        disabled={demo.active}
                        className={`btn btn-sm ${compareOn ? 'btn-primary' : 'btn-secondary'}`}
                        title={t('trends.comparePeriodsHint', 'Overlay a previous or custom period as dashed lines')}
                    >
                        <GitCompareArrows className="w-4 h-4" />
                        {t('trends.comparePeriods', 'Compare periods')}
                    </button>

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

                {/* Comparison Period Bar */}
                {compareOn && !demo.active && (
                    <div
                        className="flex flex-wrap items-center gap-3 px-3 py-2.5 rounded-xl border"
                        style={{
                            backgroundColor: 'var(--color-bg-soft)',
                            borderColor: 'color-mix(in srgb, var(--color-primary) 28%, var(--color-border))',
                        }}
                    >
                        <div className="flex items-center gap-2 text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                            <StyleSwatch style="dashed" color="var(--color-primary)" />
                            <span>{t('trends.compareWith', 'Compare with')}:</span>
                        </div>
                        <div className="flex flex-wrap items-center gap-1.5">
                            {[
                                { id: 'prev', label: t('trends.presetPrev', 'Previous period') },
                                { id: 'wow', label: t('trends.presetWow', 'Week ago (−7d)') },
                                { id: 'mom', label: t('trends.presetMom', 'Month ago (−28d)') },
                                { id: 'custom', label: t('trends.presetCustom', 'Custom dates') },
                            ].map((p) => (
                                <button
                                    key={p.id}
                                    type="button"
                                    onClick={() => handleSelectComparePreset(p.id)}
                                    className="px-2.5 py-1 rounded-lg text-xs font-medium transition-colors"
                                    style={comparePreset === p.id
                                        ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }
                                        : { backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-secondary)', border: '1px solid var(--color-border)' }}
                                >
                                    {p.label}
                                </button>
                            ))}
                        </div>

                        {comparePreset === 'custom' ? (
                            <div className="flex items-center gap-2">
                                <Calendar className="w-3.5 h-3.5" style={{ color: 'var(--color-text-muted)' }} />
                                <input
                                    type="date"
                                    value={customCompareFrom}
                                    onChange={(e) => setCustomCompareFrom(e.target.value)}
                                    className="form-input text-xs"
                                    style={{ width: 'auto', padding: '5px 8px' }}
                                />
                                <span style={{ color: 'var(--color-text-muted)' }}>—</span>
                                <input
                                    type="date"
                                    value={customCompareTo}
                                    onChange={(e) => setCustomCompareTo(e.target.value)}
                                    className="form-input text-xs"
                                    style={{ width: 'auto', padding: '5px 8px' }}
                                />
                            </div>
                        ) : compareRange && (
                            <span className="text-xs font-mono px-2 py-1 rounded-lg"
                                style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-secondary)', border: '1px solid var(--color-border)' }}>
                                {compareRange.from} — {compareRange.to}
                            </span>
                        )}

                        {endsToday && (
                            <label
                                className="flex items-center gap-1.5 text-xs ml-auto cursor-pointer select-none"
                                style={{ color: 'var(--color-text-secondary)' }}
                                title={t('trends.pacingHint', 'Compare today up to the current hour against the same elapsed hours of the previous period')}
                            >
                                <input
                                    type="checkbox"
                                    checked={pacingOn}
                                    onChange={(e) => setPacingOn(e.target.checked)}
                                    className="accent-[var(--color-primary)]"
                                />
                                <Sparkles className="w-3.5 h-3.5" style={{ color: 'var(--color-primary)' }} />
                                <span>
                                    {t('trends.pacingLabel', 'Match elapsed hours')} ({String(tzNow.hour).padStart(2, '0')}:59)
                                </span>
                            </label>
                        )}
                    </div>
                )}

                {filterError && (
                    <div className="alert alert-error text-xs">{filterError}</div>
                )}
                {(filters.length > 0 || selectedCampaignIds.length > 0 || selectedOfferIds.length > 0 || selectedLandingIds.length > 0) && (
                    <div className="flex flex-wrap gap-2">
                        {filters.map((f) => (
                            <span key={f.id} className="status-badge" style={{ background: 'var(--color-info-bg)', color: 'var(--color-info)' }}>
                                {filterFields.find((ff) => ff.value === f.field)?.label} · {filterOperators.find((o) => o.value === f.operator)?.label}: {f.value}
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

            {/* Metrics Bar with per-metric colour & style picker */}
            <div className="page-card" data-tour="trends-metrics">
                <div className="flex flex-wrap items-center justify-between gap-2" style={{ marginBottom: '12px' }}>
                    <div className="flex items-center gap-2">
                        <TrendingUp className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                        <span className="form-label" style={{ margin: 0 }}>{t('trends.metrics')}</span>
                    </div>
                    {compareOn && selectedMetrics.length > 4 && (
                        <div className="flex items-center gap-2 text-xs px-2.5 py-1 rounded-lg"
                            style={{ backgroundColor: 'color-mix(in srgb, var(--color-warning, #f59e0b) 12%, transparent)', color: 'var(--color-text-secondary)' }}>
                            <AlertTriangle className="w-3.5 h-3.5 shrink-0" style={{ color: 'var(--color-warning, #f59e0b)' }} />
                            <span>{t('trends.tooManyMetricsHint', 'Many lines active — keep 4 for clearest comparison')}</span>
                            <button
                                type="button"
                                onClick={() => setSelectedMetrics(selectedMetrics.slice(0, 4))}
                                className="font-semibold underline cursor-pointer"
                                style={{ color: 'var(--color-primary)' }}
                            >
                                {t('trends.keepTop4', 'Keep 4')}
                            </button>
                        </div>
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                    {availableMetrics.map((metric) => {
                        const active = selectedMetrics.includes(metric.key);
                        const rgb = metricColorRgb(metric.key, mergedChartPrefs);
                        const hex = rgbToHex(rgb);
                        const style = metricStyle(metric.key, mergedChartPrefs);
                        return (
                            <div key={metric.key} className="relative inline-flex items-center">
                                <button
                                    type="button"
                                    onClick={() => toggleMetric(metric.key)}
                                    className={`btn btn-sm ${active ? '' : 'btn-secondary'}`}
                                    style={active
                                        ? { backgroundColor: hex, color: '#ffffff', paddingRight: '28px' }
                                        : {}}
                                >
                                    {metric.label}
                                </button>
                                {active && (
                                    <button
                                        type="button"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setOpenStyleMetric(openStyleMetric === metric.key ? null : metric.key);
                                        }}
                                        className="absolute right-1.5 top-1/2 -translate-y-1/2 p-0.5 rounded hover:bg-black/15 text-white/90"
                                        title={t('analyticsCompare.customizeSeries', 'Customize color & line style')}
                                    >
                                        <Palette className="w-3.5 h-3.5" />
                                    </button>
                                )}
                                {openStyleMetric === metric.key && (
                                    <SeriesStylePopover
                                        color={localStyles.colors?.[metric.key]}
                                        resolvedHex={hex}
                                        style={style}
                                        fill={Object.prototype.hasOwnProperty.call(localStyles.fills || {}, metric.key)
                                            ? localStyles.fills[metric.key]
                                            : (!PERCENT_METRICS.includes(metric.key) && selectedMetrics.length <= 4)}
                                        onChangeColor={(c) => persistLocalStyles({
                                            ...localStyles,
                                            colors: { ...localStyles.colors, [metric.key]: c },
                                        })}
                                        onChangeStyle={(s) => persistLocalStyles({
                                            ...localStyles,
                                            styles: { ...localStyles.styles, [metric.key]: s },
                                        })}
                                        onChangeFill={(f) => persistLocalStyles({
                                            ...localStyles,
                                            fills: { ...localStyles.fills, [metric.key]: f },
                                        })}
                                        onReset={() => {
                                            const colors = { ...localStyles.colors };
                                            const styles = { ...localStyles.styles };
                                            const fills = { ...localStyles.fills };
                                            delete colors[metric.key];
                                            delete styles[metric.key];
                                            delete fills[metric.key];
                                            persistLocalStyles({ colors, styles, fills });
                                        }}
                                        onClose={() => setOpenStyleMetric(null)}
                                    />
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Chart Card + Screenshot-style KPI Delta Strip */}
            <div className="page-card" data-tour="trends-chart">
                <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div className="flex items-center gap-2">
                        <BarChart3 className="w-5 h-5" style={{ color: 'var(--color-text-muted)' }} />
                        <h3 className="page-title">{t('trends.chartTitle')}</h3>
                    </div>
                    {effectiveCompare && (
                        <div className="flex items-center gap-4 text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                            <span className="inline-flex items-center gap-1.5">
                                <StyleSwatch style="solid" color="var(--color-primary)" />
                                <span>{dateFrom} — {dateTo}</span>
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <StyleSwatch style="dashed" color="var(--color-primary)" />
                                <span>{effectiveCompare.date_from} — {effectiveCompare.date_to}</span>
                            </span>
                        </div>
                    )}
                </div>

                {/* KPI Summary Strip (matches the screenshot reference: coloured dot + total + ↑/↓ delta) */}
                {!isLoading && summaryCurTotals && (
                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2.5 mb-4">
                        {selectedMetrics.map((m) => {
                            const lbl = availableMetrics.find((am) => am.key === m)?.label || m;
                            const rgb = metricColorRgb(m, mergedChartPrefs);
                            const hex = rgbToHex(rgb);
                            const curVal = summaryCurTotals[m];
                            const prevVal = summaryCmpTotals ? summaryCmpTotals[m] : null;
                            const delta = effectiveCompare ? metricDelta(m, curVal, prevVal) : null;
                            return (
                                <div
                                    key={m}
                                    className="rounded-xl px-3 py-2 border transition-colors"
                                    style={{
                                        backgroundColor: 'var(--color-bg-soft)',
                                        borderColor: 'var(--color-border)',
                                    }}
                                    title={effectiveCompare && prevVal !== null && prevVal !== undefined
                                        ? `${lbl}: ${formatMetric(m, curVal)} vs ${formatMetric(m, prevVal)}`
                                        : lbl}
                                >
                                    <div className="flex items-center gap-1.5 text-[11px] font-medium truncate" style={{ color: 'var(--color-text-muted)' }}>
                                        <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ backgroundColor: hex }} />
                                        <span className="truncate">{lbl}</span>
                                    </div>
                                    <div className="flex items-baseline gap-2 mt-1 flex-wrap">
                                        <span className="text-base font-bold tabular-nums" style={{ color: 'var(--color-text-primary)' }}>
                                            {formatMetric(m, curVal)}
                                        </span>
                                        {delta && <DeltaBadge delta={delta} t={t} />}
                                    </div>
                                    {effectiveCompare && prevVal !== null && prevVal !== undefined && (
                                        <div className="text-[10px] tabular-nums mt-0.5" style={{ color: 'var(--color-text-muted)' }}>
                                            {t('trends.wasValue', 'prev')}: {formatMetric(m, prevVal)}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}

                <div style={{ height: '340px' }}>
                    {isLoading ? (
                        <div className="empty-state"><p style={{ color: 'var(--color-text-muted)' }}>{t('trends.loading')}</p></div>
                    ) : chartObj ? (
                        <Chart type="line" data={chartObj} options={chartOptions} />
                    ) : (
                        <div className="empty-state"><p style={{ color: 'var(--color-text-muted)' }}>{t('trends.noData')}</p></div>
                    )}
                </div>
            </div>

            {/* Table Card */}
            <div className="page-card" style={{ padding: 0 }}>
                <div className="page-header" style={{ padding: '16px 24px', marginBottom: 0 }}>
                    <div className="flex items-center gap-2">
                        <Clock className="w-5 h-5" style={{ color: 'var(--color-text-muted)' }} />
                        <h3 className="page-title">{t('trends.reportTitle')}</h3>
                    </div>
                    <span style={{ fontSize: '14px', color: 'var(--color-text-muted)' }}>{rows.length} {t('trends.records')}</span>
                </div>
                <div className="overflow-x-auto min-w-0">
                    <table className="page-table">
                        <thead data-tour="trends-table-head">
                            <tr>
                                <PageSortTh data-col colKey="period" label={t('trends.period')} sort={sort} onSort={onSort} firstDir="asc" />
                                {selectedMetrics.map((m) => (
                                    <PageSortTh key={m} data-col colKey={m} align="right" sort={sort} onSort={onSort}
                                        label={availableMetrics.find((am) => am.key === m)?.label || m} />
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
                                sortedRows.map((row) => (
                                    <tr key={row.period_key ?? row.period}>
                                        <td style={{ fontWeight: 500 }}>
                                            <div>{periodLabel(row)}</div>
                                            {effectiveCompare && row.__compare && (
                                                <div className="text-[10px]" style={{ color: 'var(--color-text-muted)' }}>
                                                    vs {periodLabel(row.__compare)}
                                                </div>
                                            )}
                                        </td>
                                        {selectedMetrics.map((m) => {
                                            const cmpVal = effectiveCompare ? row.__compare?.[m] : null;
                                            const d = effectiveCompare && row.__compare ? metricDelta(m, row[m], cmpVal) : null;
                                            return (
                                                <td key={m} style={{ textAlign: 'right', color: 'var(--color-text-secondary)', fontVariantNumeric: 'tabular-nums' }}
                                                    title={effectiveCompare && row.__compare ? `${formatMetric(m, row[m])} vs ${formatMetric(m, cmpVal)}` : undefined}>
                                                    <div>{formatMetric(m, row[m])}</div>
                                                    {d && <div><DeltaBadge delta={d} t={t} /></div>}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))
                            )}
                        </tbody>
                        {!isLoading && rows.length > 0 && rowTotals && (
                            <tfoot data-tour="trends-total">
                                <tr>
                                    <td title={t('trends.totalHint')}>{t('trends.total')}</td>
                                    {selectedMetrics.map((m) => {
                                        const curTot = summaryCurTotals?.[m] ?? rowTotals[m];
                                        const prevTot = summaryCmpTotals?.[m];
                                        const d = effectiveCompare && summaryCmpTotals ? metricDelta(m, curTot, prevTot) : null;
                                        return (
                                            <td key={m} style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                                                <div>{formatMetric(m, rowTotals[m])}</div>
                                                {d && <div><DeltaBadge delta={d} t={t} /></div>}
                                            </td>
                                        );
                                    })}
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
                                    {filterFields.map((f) => (<option key={f.value} value={f.value}>{f.label}</option>))}
                                </select>
                            </div>
                            <div>
                                <label className="form-label">{t('trends.condition')}</label>
                                <select value={newFilter.operator} onChange={(e) => setNewFilter({ ...newFilter, operator: e.target.value })} className="form-select">
                                    {filterOperators.map((o) => (<option key={o.value} value={o.value}>{o.label}</option>))}
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
