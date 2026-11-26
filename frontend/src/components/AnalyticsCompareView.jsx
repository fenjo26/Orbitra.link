import React, { useState, useEffect, useMemo, useCallback } from 'react';
import {
    GitCompareArrows, Layers, Package, LayoutTemplate, Images, Calendar, Sliders,
    Plus, Trash2, Eye, EyeOff, Download, Bookmark, Trophy, Percent, Palette, X,
    PieChart, Check
} from 'lucide-react';
import DateRangePicker from './DateRangePicker';
import MultiSelect from './common/MultiSelect';
import { DeltaBadge } from './common/PeriodCompare';
import SeriesStylePopover, { StyleSwatch } from './common/SeriesStylePopover';
import { useEntityList } from '../utils/useEntityList';
import { useTimezone } from '../utils/useTimezone';
import { formatDate, getPresetDates } from '../utils/dateRange';
import { shiftedPeriod, metricDelta, shiftIsoDate } from '../utils/periodCompare';
import {
    clearMetricColorCache,
    LINE_DASH_MAP,
    resolveCssColor,
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
const PRESETS_STORAGE_KEY = 'orbitra_analytics_compare_presets_v1';
const MAX_SERIES = 8;

const LOCALE_TAGS = {
    ru: 'ru-RU', en: 'en-US', uk: 'uk-UA', es: 'es-ES',
    zh: 'zh-CN', fr: 'fr-FR', de: 'de-DE', ko: 'ko-KR',
};

const MONEY_METRICS = new Set(['revenue', 'real_revenue', 'cost', 'profit', 'cpa']);
const UNIT_MONEY_METRICS = new Set(['epc']);
const PERCENT_METRICS = new Set(['ctr', 'cr', 'roi', 'real_roi']);
const LOWER_BETTER_METRICS = new Set(['cost', 'cpa']);
const SUMMARY_COLUMNS = ['clicks', 'unique_clicks', 'conversions', 'cr', 'ctr', 'cost', 'revenue', 'profit', 'roi', 'epc', 'cpa'];

// Theme-driven default colours for series 1..8 (follows active light/dark/custom branding palette).
const THEME_SERIES_VARS = [
    { varName: '--color-primary', fallback: '#2563EB' },
    { varName: '--color-success', fallback: '#10B981' },
    { varName: '--color-warning', fallback: '#F59E0B' },
    { varName: '--color-accent-purple', fallback: '#8B5CF6' },
    { varName: '--color-accent-turquoise', fallback: '#0EA5E9' },
    { varName: '--color-danger', fallback: '#EF4444' },
    { varName: '--color-ctr', fallback: '#EC4899' },
    { varName: '--color-real-roi', fallback: '#6366F1' },
];

// Okabe-Ito colour-blind-safe palette + distinct strokes.
const CVD_SERIES_STYLES = [
    { color: '#0072b2', style: 'solid' },
    { color: '#e69f00', style: 'dashed' },
    { color: '#009e73', style: 'solid' },
    { color: '#cc79a7', style: 'dotted' },
    { color: '#56b4e9', style: 'dashdot' },
    { color: '#d55e00', style: 'dashed' },
    { color: '#f0e442', style: 'dotted' },
    { color: '#999999', style: 'solid' },
];

// Past periods in multi-period mode automatically default to dashed lines.
const PERIOD_DEFAULT_STYLES = ['solid', 'dashed', 'dotted', 'dashdot', 'dashed', 'dotted', 'dashdot', 'dashed'];

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

const loadSavedPresets = () => {
    try {
        const list = JSON.parse(localStorage.getItem(PRESETS_STORAGE_KEY) || '[]');
        return Array.isArray(list) ? list : [];
    } catch {
        return [];
    }
};

const buildFiltersFromSegment = (seg) => {
    const out = [];
    if (seg.campaignIds?.length === 1) out.push({ field: 'campaign_id', operator: 'equals', value: String(seg.campaignIds[0]) });
    else if (seg.campaignIds?.length > 1) out.push({ field: 'campaign_id', operator: 'in', value: seg.campaignIds.join(',') });

    if (seg.offerIds?.length === 1) out.push({ field: 'offer_id', operator: 'equals', value: String(seg.offerIds[0]) });
    else if (seg.offerIds?.length > 1) out.push({ field: 'offer_id', operator: 'in', value: seg.offerIds.join(',') });

    if (seg.landingIds?.length === 1) out.push({ field: 'landing_id', operator: 'equals', value: String(seg.landingIds[0]) });
    else if (seg.landingIds?.length > 1) out.push({ field: 'landing_id', operator: 'in', value: seg.landingIds.join(',') });

    if (seg.creativeIds?.length === 1) out.push({ field: 'creative_asset_id', operator: 'equals', value: String(seg.creativeIds[0]) });
    else if (seg.creativeIds?.length > 1) out.push({ field: 'creative_asset_id', operator: 'in', value: seg.creativeIds.join(',') });

    if (seg.sourceIds?.length === 1) out.push({ field: 'source_id', operator: 'equals', value: String(seg.sourceIds[0]) });
    else if (seg.sourceIds?.length > 1) out.push({ field: 'source_id', operator: 'in', value: seg.sourceIds.join(',') });

    if (seg.country && seg.country.trim()) {
        out.push({ field: 'country_code', operator: 'equals', value: seg.country.trim().toUpperCase() });
    }
    if (seg.device && seg.device.trim()) {
        out.push({ field: 'device_type', operator: 'equals', value: seg.device.trim() });
    }
    return out;
};

const AnalyticsCompareView = ({ currency = 'USD' }) => {
    const { t, language } = useLanguage();
    const localeTag = LOCALE_TAGS[language] || 'en-US';
    const currencyCode = (currency || 'USD').toUpperCase();
    const [timezone, setTimezone] = useTimezone();

    // Redraw theme-derived colours when palette/night-mode changes.
    const [themeTick, setThemeTick] = useState(0);
    useEffect(() => {
        const onTheme = () => {
            clearMetricColorCache();
            setThemeTick((n) => n + 1);
        };
        window.addEventListener('themeChanged', onTheme);
        window.addEventListener('storage', onTheme);
        return () => {
            window.removeEventListener('themeChanged', onTheme);
            window.removeEventListener('storage', onTheme);
        };
    }, []);

    // Entity lists (cached across session via useEntityList).
    const { items: campaigns } = useEntityList('campaigns_simple');
    const { items: offers } = useEntityList('offers_simple');
    const { items: landings } = useEntityList('landings_simple');
    const { items: creatives } = useEntityList('creatives_simple');
    const { items: sources } = useEntityList('traffic_sources_simple');
    const { items: campaignGroups } = useEntityList('campaign_groups');
    const { items: offerGroups } = useEntityList('offer_groups');
    const { items: landingGroups } = useEntityList('landing_groups');

    // Mode: 'campaigns' | 'offers' | 'landings' | 'creatives' | 'periods' | 'segments'
    const [mode, setMode] = useState('campaigns');
    const [groupBy, setGroupBy] = useState('day');
    const [datePreset, setDatePreset] = useState('last7Days');
    const [dateFrom, setDateFrom] = useState(() => getPresetDates('last7Days')?.from || formatDate(new Date(Date.now() - 6 * 86400000)));
    const [dateTo, setDateTo] = useState(() => getPresetDates('last7Days')?.to || formatDate(new Date()));

    const [primaryMetric, setPrimaryMetric] = useState('clicks');
    const [secondaryMetric, setSecondaryMetric] = useState('');
    const [normalizeMode, setNormalizeMode] = useState(false);
    const [cvdMode, setCvdMode] = useState(false);
    const [overlayPrevPeriod, setOverlayPrevPeriod] = useState(false);
    const [overlayPreset, setOverlayPreset] = useState('prev');

    // Mode-specific entity selections
    const [pickedCampaignIds, setPickedCampaignIds] = useState([]);
    const [pickedOfferIds, setPickedOfferIds] = useState([]);
    const [pickedLandingIds, setPickedLandingIds] = useState([]);
    const [pickedCreativeIds, setPickedCreativeIds] = useState([]);

    // Multi-period series (Series 1 = current period solid; Series 2..N = past periods dashed)
    const [periodSlots, setPeriodSlots] = useState(() => {
        const cur = getPresetDates('last7Days') || { from: formatDate(new Date(Date.now() - 6 * 86400000)), to: formatDate(new Date()) };
        const prev = shiftedPeriod(cur.from, cur.to, 'wow') || shiftedPeriod(cur.from, cur.to, 'prev') || cur;
        return [
            { id: 'period_1', label: '', dateFrom: cur.from, dateTo: cur.to },
            { id: 'period_2', label: '', dateFrom: prev.from, dateTo: prev.to },
        ];
    });

    // Custom segments (freeform cross-entity comparison: e.g. Landing A vs Creative B, Campaign+DE vs Campaign+FR)
    const [segments, setSegments] = useState(() => [
        { id: 'seg_1', label: 'Segment 1', campaignIds: [], offerIds: [], landingIds: [], creativeIds: [], sourceIds: [], country: '', device: '', useCustomDates: false, dateFrom: '', dateTo: '' },
        { id: 'seg_2', label: 'Segment 2', campaignIds: [], offerIds: [], landingIds: [], creativeIds: [], sourceIds: [], country: '', device: '', useCustomDates: false, dateFrom: '', dateTo: '' },
    ]);
    const [editingSegmentId, setEditingSegmentId] = useState(null);

    // Per-series visual overrides (keyed by series id): { color, style, fill, hidden }
    const [seriesStyles, setSeriesStyles] = useState({});
    const [openStyleId, setOpenStyleId] = useState(null);

    // Saved presets
    const [savedPresets, setSavedPresets] = useState(loadSavedPresets);
    const [showSavePreset, setShowSavePreset] = useState(false);
    const [presetName, setPresetName] = useState('');

    // Auto-select top 2 entities on first visit to an entity mode if nothing is picked yet.
    useEffect(() => {
        if (mode === 'campaigns' && pickedCampaignIds.length === 0 && campaigns.length > 0) {
            setPickedCampaignIds(campaigns.slice(0, Math.min(3, campaigns.length)).map((c) => c.id));
        }
    }, [mode, campaigns, pickedCampaignIds.length]);
    useEffect(() => {
        if (mode === 'offers' && pickedOfferIds.length === 0 && offers.length > 0) {
            setPickedOfferIds(offers.slice(0, Math.min(3, offers.length)).map((o) => o.id));
        }
    }, [mode, offers, pickedOfferIds.length]);
    useEffect(() => {
        if (mode === 'landings' && pickedLandingIds.length === 0 && landings.length > 0) {
            setPickedLandingIds(landings.slice(0, Math.min(3, landings.length)).map((l) => l.id));
        }
    }, [mode, landings, pickedLandingIds.length]);
    useEffect(() => {
        if (mode === 'creatives' && pickedCreativeIds.length === 0 && creatives.length > 0) {
            setPickedCreativeIds(creatives.slice(0, Math.min(3, creatives.length)).map((c) => c.id));
        }
    }, [mode, creatives, pickedCreativeIds.length]);

    const availableMetrics = useMemo(() => [
        { key: 'clicks', label: t('trends.clicks') },
        { key: 'unique_clicks', label: t('trends.uniqueClicks') },
        { key: 'conversions', label: t('trends.conversions') },
        { key: 'cr', label: t('trends.cr') },
        { key: 'ctr', label: t('trends.ctr') },
        { key: 'cost', label: t('trends.cost') },
        { key: 'revenue', label: t('trends.revenue') },
        { key: 'real_revenue', label: t('trends.realRevenue') },
        { key: 'profit', label: t('trends.profit') },
        { key: 'roi', label: t('metrics.roi', 'ROI') },
        { key: 'real_roi', label: t('trends.realRoi') },
        { key: 'epc', label: t('metrics.epc', 'EPC') },
        { key: 'cpa', label: t('metrics.cpa', 'CPA') },
    ], [t]);

    const summaryColumns = SUMMARY_COLUMNS;

    // Build normalized list of active series specifications for the current mode.
    const activeSeriesSpecs = useMemo(() => {
        if (mode === 'campaigns') {
            const byId = new Map(campaigns.map((c) => [String(c.id), c]));
            return pickedCampaignIds.slice(0, MAX_SERIES).map((id) => ({
                id: `camp_${id}`,
                label: byId.get(String(id))?.name || `#${id}`,
                date_from: dateFrom,
                date_to: dateTo,
                filters: [{ field: 'campaign_id', operator: 'equals', value: String(id) }],
                isPastPeriod: false,
            }));
        }
        if (mode === 'offers') {
            const byId = new Map(offers.map((o) => [String(o.id), o]));
            return pickedOfferIds.slice(0, MAX_SERIES).map((id) => ({
                id: `offer_${id}`,
                label: byId.get(String(id))?.name || `#${id}`,
                date_from: dateFrom,
                date_to: dateTo,
                filters: [{ field: 'offer_id', operator: 'equals', value: String(id) }],
                isPastPeriod: false,
            }));
        }
        if (mode === 'landings') {
            const byId = new Map(landings.map((l) => [String(l.id), l]));
            return pickedLandingIds.slice(0, MAX_SERIES).map((id) => ({
                id: `land_${id}`,
                label: byId.get(String(id))?.name || `#${id}`,
                date_from: dateFrom,
                date_to: dateTo,
                filters: [{ field: 'landing_id', operator: 'equals', value: String(id) }],
                isPastPeriod: false,
            }));
        }
        if (mode === 'creatives') {
            const byId = new Map(creatives.map((c) => [String(c.id), c]));
            return pickedCreativeIds.slice(0, MAX_SERIES).map((id) => ({
                id: `creo_${id}`,
                label: byId.get(String(id))?.name || `#${id}`,
                date_from: dateFrom,
                date_to: dateTo,
                filters: [{ field: 'creative_asset_id', operator: 'equals', value: String(id) }],
                isPastPeriod: false,
            }));
        }
        if (mode === 'periods') {
            return periodSlots.slice(0, MAX_SERIES).map((slot, idx) => ({
                id: slot.id,
                label: slot.label?.trim() || `${formatShortDate(slot.dateFrom, localeTag)} — ${formatShortDate(slot.dateTo, localeTag)}`,
                date_from: slot.dateFrom,
                date_to: slot.dateTo,
                filters: [],
                // Slot 0 = current/primary (solid), slots 1..N = past periods (dashed by default!)
                isPastPeriod: idx > 0,
            }));
        }
        // Custom segments
        return segments.slice(0, MAX_SERIES).map((seg) => ({
            id: seg.id,
            label: seg.label?.trim() || seg.id,
            date_from: (seg.useCustomDates && seg.dateFrom) ? seg.dateFrom : dateFrom,
            date_to: (seg.useCustomDates && seg.dateTo) ? seg.dateTo : dateTo,
            filters: buildFiltersFromSegment(seg),
            isPastPeriod: false,
        }));
    }, [
        mode, campaigns, offers, landings, creatives,
        pickedCampaignIds, pickedOfferIds, pickedLandingIds, pickedCreativeIds,
        periodSlots, segments, dateFrom, dateTo, localeTag,
    ]);

    // Fetch batched data from action=analytics_compare
    const [loading, setLoading] = useState(false);
    const [seriesResults, setSeriesResults] = useState({});
    const [seamNote, setSeamNote] = useState(null);

    const requestPayload = useMemo(() => {
        const list = [];
        activeSeriesSpecs.forEach((s) => {
            list.push({
                id: s.id,
                date_from: s.date_from,
                date_to: s.date_to,
                filters: s.filters,
            });
            if (overlayPrevPeriod && mode !== 'periods') {
                const prev = shiftedPeriod(s.date_from, s.date_to, overlayPreset);
                if (prev) {
                    list.push({
                        id: `${s.id}__prev`,
                        date_from: prev.from,
                        date_to: prev.to,
                        filters: s.filters,
                    });
                }
            }
        });
        return list;
    }, [activeSeriesSpecs, overlayPrevPeriod, overlayPreset, mode]);

    const payloadKey = JSON.stringify({ groupBy, timezone, requestPayload });

    useEffect(() => {
        if (requestPayload.length === 0) {
            setSeriesResults({});
            setLoading(false);
            return;
        }
        let cancelled = false;
        setLoading(true);
        const timer = setTimeout(async () => {
            try {
                const qs = new URLSearchParams({ action: 'analytics_compare' });
                if (timezone) qs.set('timezone', timezone);
                const res = await fetch(`${API_URL}?${qs}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        group_by: groupBy,
                        date_from: dateFrom,
                        date_to: dateTo,
                        series: requestPayload,
                    }),
                });
                const json = await res.json();
                if (cancelled) return;
                setSeamNote(json.stats_seam?.raw_available_from || null);
                if (json.status === 'success' && Array.isArray(json.data?.series)) {
                    const map = {};
                    json.data.series.forEach((entry) => { map[entry.id] = entry; });
                    setSeriesResults(map);
                }
            } catch (e) {
                console.error('Compare fetch error:', e);
            } finally {
                if (!cancelled) setLoading(false);
            }
        }, 250);
        return () => { cancelled = true; clearTimeout(timer); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [payloadKey]);

    // Resolve each series' visual style (theme default → CVD override → user custom override).
    const resolveSeriesVisual = useCallback((spec, idx) => {
        const override = seriesStyles[spec.id] || {};
        const themeSlot = THEME_SERIES_VARS[idx % THEME_SERIES_VARS.length];
        const cvdSlot = CVD_SERIES_STYLES[idx % CVD_SERIES_STYLES.length];
        const baseCss = override.color || (cvdMode ? cvdSlot.color : `var(${themeSlot.varName}, ${themeSlot.fallback})`);
        const rgb = resolveCssColor(baseCss, themeSlot.fallback);
        const hex = rgbToHex(rgb);
        const defaultStyle = mode === 'periods'
            ? (PERIOD_DEFAULT_STYLES[idx] || 'dashed')
            : (cvdMode ? cvdSlot.style : 'solid');
        const style = override.style || defaultStyle;
        const fill = override.fill !== undefined ? Boolean(override.fill) : (idx === 0 && activeSeriesSpecs.length <= 3 && style === 'solid');
        const hidden = Boolean(override.hidden);
        return { rgb, hex, color: rgbToCss(rgb), style, fill, hidden, hasOverride: Boolean(override.color || override.style || override.fill !== undefined) };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [seriesStyles, cvdMode, mode, activeSeriesSpecs.length, themeTick]);

    const updateSeriesStyle = (id, patch) => {
        setSeriesStyles((prev) => ({
            ...prev,
            [id]: { ...(prev[id] || {}), ...patch },
        }));
    };

    const resetSeriesStyle = (id) => {
        setSeriesStyles((prev) => {
            const copy = { ...prev };
            delete copy[id];
            return copy;
        });
    };

    const formatMetric = useCallback((m, value) => {
        if (value === null || value === undefined) return '—';
        const n = Number(value) || 0;
        if (UNIT_MONEY_METRICS.includes(m)) {
            try {
                return new Intl.NumberFormat(localeTag, { style: 'currency', currency: currencyCode, maximumFractionDigits: Math.abs(n) < 1 ? 4 : 2 }).format(n);
            } catch { return `$${n.toFixed(Math.abs(n) < 1 ? 4 : 2)}`; }
        }
        if (MONEY_METRICS.includes(m)) {
            try {
                return new Intl.NumberFormat(localeTag, { style: 'currency', currency: currencyCode, maximumFractionDigits: 2 }).format(n);
            } catch { return `$${n.toFixed(2)}`; }
        }
        if (PERCENT_METRICS.includes(m)) return `${n.toFixed(2)}%`;
        return n.toLocaleString(localeTag);
    }, [localeTag, currencyCode]);

    const getCssVar = (name, fallback) => {
        if (typeof window !== 'undefined') {
            const val = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            if (val) return val;
        }
        return fallback;
    };

    // Build Chart.js dataset & options
    const { chartObj, chartOptions } = useMemo(() => {
        const visibleSpecs = activeSeriesSpecs
            .map((spec, idx) => ({ spec, idx, visual: resolveSeriesVisual(spec, idx), res: seriesResults[spec.id] }))
            .filter((item) => !item.visual.hidden && item.res && Array.isArray(item.res.table));

        if (!visibleSpecs.length) return { chartObj: null, chartOptions: {} };

        const maxLen = Math.max(...visibleSpecs.map((v) => v.res.table.length), 0);
        if (!maxLen) return { chartObj: null, chartOptions: {} };

        const isMultiDate = mode === 'periods' || (mode === 'segments' && segments.some((s) => s.useCustomDates));
        const firstTable = visibleSpecs[0].res.table;
        const singleDayHour = groupBy === 'hour' && maxLen <= 24;

        const labels = Array.from({ length: maxLen }, (_, i) => {
            const row0 = firstTable[i];
            if (groupBy === 'day_of_week' && row0) {
                const d = new Date(2023, 0, 1 + Number(row0.period_key));
                return d.toLocaleDateString(localeTag, { weekday: 'short' });
            }
            if (singleDayHour && row0 && typeof row0.period_key === 'string' && row0.period_key.length >= 16) {
                return row0.period_key.slice(11, 16);
            }
            if (isMultiDate) {
                if (groupBy === 'hour') return `${t('analyticsCompare.stepHour', 'H')} ${i + 1}`;
                if (groupBy === 'month') return `${t('analyticsCompare.stepMonth', 'M')} ${i + 1}`;
                return `${t('analyticsCompare.stepDay', 'Day')} ${i + 1}`;
            }
            if (row0 && groupBy === 'day') return formatShortDate(row0.period_key, localeTag);
            if (row0 && groupBy === 'hour' && typeof row0.period_key === 'string' && row0.period_key.length >= 16) {
                return `${formatShortDate(row0.period_key.slice(0, 10), localeTag)} ${row0.period_key.slice(11, 16)}`;
            }
            return row0?.period || String(i + 1);
        });

        const extractValues = (table, metricKey) => {
            const raw = Array.from({ length: maxLen }, (_, i) => {
                const r = table[i];
                if (!r) return null;
                return r[metricKey] === null || r[metricKey] === undefined ? null : Number(r[metricKey]);
            });
            if (!normalizeMode) return raw;
            const base = raw.find((v) => v !== null && v !== 0);
            if (!base) return raw.map((v) => (v === null ? null : 0));
            return raw.map((v) => (v === null ? null : Math.round((v / Math.abs(base)) * 1000) / 10));
        };

        const datasets = [];
        visibleSpecs.forEach(({ spec, visual, res }) => {
            const vals = extractValues(res.table, primaryMetric);
            const rawVals = Array.from({ length: maxLen }, (_, i) => res.table[i]?.[primaryMetric] ?? null);
            const dateKeys = Array.from({ length: maxLen }, (_, i) => res.table[i]?.period_key || '');

            if (visual.style === 'bars') {
                datasets.push({
                    type: 'bar',
                    seriesId: spec.id,
                    metricKey: primaryMetric,
                    rawValues: rawVals,
                    dateKeys,
                    label: spec.label,
                    data: vals,
                    yAxisID: 'yPrimary',
                    borderColor: visual.color,
                    backgroundColor: rgbToCss(visual.rgb, 0.55),
                    borderWidth: 1,
                    borderRadius: 3,
                    maxBarThickness: 18,
                    order: 10,
                });
            } else {
                datasets.push({
                    type: 'line',
                    seriesId: spec.id,
                    metricKey: primaryMetric,
                    rawValues: rawVals,
                    dateKeys,
                    label: spec.label,
                    data: vals,
                    yAxisID: 'yPrimary',
                    borderColor: visual.color,
                    borderDash: LINE_DASH_MAP[visual.style] || [],
                    borderCapStyle: visual.style === 'dotted' ? 'round' : 'butt',
                    backgroundColor: visual.fill
                        ? (context) => {
                            const { chart } = context;
                            const area = chart.chartArea;
                            if (!area) return rgbToCss(visual.rgb, 0.12);
                            const g = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
                            g.addColorStop(0, rgbToCss(visual.rgb, 0.18));
                            g.addColorStop(1, rgbToCss(visual.rgb, 0));
                            return g;
                        }
                        : 'transparent',
                    fill: visual.fill,
                    cubicInterpolationMode: 'monotone',
                    tension: 0.3,
                    borderWidth: spec.isPastPeriod ? 2 : 2.5,
                    pointRadius: maxLen <= 2 ? 3 : 0,
                    pointHoverRadius: 5,
                    pointBackgroundColor: visual.color,
                    spanGaps: PERCENT_METRICS.includes(primaryMetric),
                    order: spec.isPastPeriod ? 3 : 1,
                });
            }

            // Optional previous-period dashed shadow for each entity series
            if (overlayPrevPeriod && mode !== 'periods') {
                const prevRes = seriesResults[`${spec.id}__prev`];
                if (prevRes && Array.isArray(prevRes.table)) {
                    const prevVals = extractValues(prevRes.table, primaryMetric);
                    const prevRaw = Array.from({ length: maxLen }, (_, i) => prevRes.table[i]?.[primaryMetric] ?? null);
                    const prevDates = Array.from({ length: maxLen }, (_, i) => prevRes.table[i]?.period_key || '');
                    const softColor = rgbToCss(visual.rgb, 0.65);
                    datasets.push({
                        type: 'line',
                        seriesId: `${spec.id}__prev`,
                        metricKey: primaryMetric,
                        rawValues: prevRaw,
                        dateKeys: prevDates,
                        isShadow: true,
                        label: `${spec.label} (${t('trends.prevPeriodShort', 'prev')})`,
                        data: prevVals,
                        yAxisID: 'yPrimary',
                        borderColor: softColor,
                        borderDash: [6, 5],
                        backgroundColor: 'transparent',
                        fill: false,
                        cubicInterpolationMode: 'monotone',
                        tension: 0.3,
                        borderWidth: 1.6,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        pointBackgroundColor: softColor,
                        spanGaps: PERCENT_METRICS.includes(primaryMetric),
                        order: 4,
                    });
                }
            }

            // Optional secondary metric on right Y-axis (dotted/dashed)
            if (secondaryMetric && secondaryMetric !== primaryMetric && !normalizeMode) {
                const secVals = Array.from({ length: maxLen }, (_, i) => {
                    const r = res.table[i];
                    if (!r) return null;
                    return r[secondaryMetric] === null || r[secondaryMetric] === undefined ? null : Number(r[secondaryMetric]);
                });
                const secLbl = availableMetrics.find((m) => m.key === secondaryMetric)?.label || secondaryMetric;
                datasets.push({
                    type: 'line',
                    seriesId: `${spec.id}__sec`,
                    metricKey: secondaryMetric,
                    rawValues: secVals,
                    dateKeys,
                    label: `${spec.label} · ${secLbl}`,
                    data: secVals,
                    yAxisID: 'ySecondary',
                    borderColor: rgbToCss(visual.rgb, 0.8),
                    borderDash: [2, 4],
                    borderCapStyle: 'round',
                    backgroundColor: 'transparent',
                    fill: false,
                    cubicInterpolationMode: 'monotone',
                    tension: 0.3,
                    borderWidth: 1.8,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    spanGaps: PERCENT_METRICS.includes(secondaryMetric),
                    order: 5,
                });
            }
        });

        const mutedColor = getCssVar('--color-text-muted', '#6B7280');
        const borderColor = getCssVar('--color-border', '#E5E7EB');
        const cardBg = getCssVar('--color-bg-card', 'rgba(15, 23, 42, 0.94)');
        const textPrimary = getCssVar('--color-text-primary', '#111827');

        const primaryLbl = availableMetrics.find((m) => m.key === primaryMetric)?.label || primaryMetric;
        const secondaryLbl = secondaryMetric ? (availableMetrics.find((m) => m.key === secondaryMetric)?.label || secondaryMetric) : '';

        const scales = {
            x: {
                grid: { display: false },
                ticks: { color: mutedColor, maxRotation: 0, autoSkipPadding: 14 },
            },
            yPrimary: {
                type: 'linear',
                position: 'left',
                beginAtZero: !normalizeMode,
                grace: '8%',
                grid: { color: borderColor },
                title: {
                    display: true,
                    text: normalizeMode ? `${primaryLbl} (${t('analyticsCompare.index100', 'Index 100%')})` : primaryLbl,
                    color: mutedColor,
                    font: { size: 11 },
                },
                ticks: {
                    color: mutedColor,
                    callback: (val) => {
                        if (normalizeMode || PERCENT_METRICS.includes(primaryMetric)) return `${val}%`;
                        const n = Number(val);
                        if (!Number.isFinite(n)) return val;
                        if (Math.abs(n) >= 10000) {
                            try { return new Intl.NumberFormat(localeTag, { notation: 'compact', maximumFractionDigits: 1 }).format(n); }
                            catch { return String(n); }
                        }
                        return String(Math.round(n * 100) / 100);
                    },
                },
            },
        };

        if (secondaryMetric && secondaryMetric !== primaryMetric && !normalizeMode) {
            scales.ySecondary = {
                type: 'linear',
                position: 'right',
                beginAtZero: true,
                grace: '8%',
                grid: { drawOnChartArea: false },
                title: { display: true, text: secondaryLbl, color: mutedColor, font: { size: 11 } },
                ticks: {
                    color: mutedColor,
                    callback: (val) => (PERCENT_METRICS.includes(secondaryMetric) ? `${val}%` : String(Math.round(Number(val) * 100) / 100)),
                },
            };
        }

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
                        label: (ctx) => {
                            const ds = ctx.dataset;
                            const dKey = ds.dateKeys?.[ctx.dataIndex];
                            const dateSuffix = isMultiDate && dKey ? ` [${groupBy === 'day' ? formatShortDate(dKey, localeTag) : dKey}]` : '';
                            if (normalizeMode) {
                                const rawVal = ds.rawValues?.[ctx.dataIndex];
                                return `${ds.label}${dateSuffix}: ${ctx.raw}% (${formatMetric(ds.metricKey, rawVal)})`;
                            }
                            return `${ds.label}${dateSuffix}: ${formatMetric(ds.metricKey, ctx.raw)}`;
                        },
                    },
                },
            },
            scales,
        };

        return { chartObj: { labels, datasets }, chartOptions: options };
    }, [
        activeSeriesSpecs, seriesResults, resolveSeriesVisual, primaryMetric, secondaryMetric,
        normalizeMode, overlayPrevPeriod, mode, segments, groupBy, localeTag, availableMetrics,
        formatMetric, t,
    ]);

    // Determine best (leader) value per column in the benchmark summary table.
    const columnLeaders = useMemo(() => {
        const leaders = {};
        if (activeSeriesSpecs.length < 2) return leaders;
        SUMMARY_COLUMNS.forEach((col) => {
            const lowerBetter = LOWER_BETTER_METRICS.has(col);
            let bestId = null;
            let bestVal = null;
            activeSeriesSpecs.forEach((spec) => {
                const tot = seriesResults[spec.id]?.totals;
                if (!tot || tot[col] === null || tot[col] === undefined) return;
                const val = Number(tot[col]);
                if (!Number.isFinite(val)) return;
                // Don't crown a $0 CPA / $0 cost with 0 clicks as "winner".
                if (lowerBetter && val === 0 && (tot.clicks || 0) === 0) return;
                if (bestVal === null || (lowerBetter ? val < bestVal : val > bestVal)) {
                    bestVal = val;
                    bestId = spec.id;
                }
            });
            if (bestId !== null && bestVal !== 0) leaders[col] = bestId;
        });
        return leaders;
    }, [activeSeriesSpecs, seriesResults]);

    // Multi-period slot management
    const addPeriodSlot = () => {
        if (periodSlots.length >= MAX_SERIES) return;
        const last = periodSlots[periodSlots.length - 1];
        const shifted = shiftedPeriod(last.dateFrom, last.dateTo, 'wow')
            || shiftedPeriod(last.dateFrom, last.dateTo, 'prev')
            || { from: shiftIsoDate(last.dateFrom, -7), to: shiftIsoDate(last.dateTo, -7) };
        setPeriodSlots([
            ...periodSlots,
            { id: `period_${Date.now()}`, label: '', dateFrom: shifted.from, dateTo: shifted.to },
        ]);
    };
    const updatePeriodSlot = (id, patch) => setPeriodSlots((prev) => prev.map((p) => (p.id === id ? { ...p, ...patch } : p)));
    const removePeriodSlot = (id) => {
        if (periodSlots.length <= 2) return;
        setPeriodSlots((prev) => prev.filter((p) => p.id !== id));
    };

    // Segment management
    const addSegment = () => {
        if (segments.length >= MAX_SERIES) return;
        const id = `seg_${Date.now()}`;
        setSegments([
            ...segments,
            {
                id,
                label: `${t('analyticsCompare.segment', 'Segment')} ${segments.length + 1}`,
                campaignIds: [], offerIds: [], landingIds: [], creativeIds: [], sourceIds: [],
                country: '', device: '', useCustomDates: false, dateFrom, dateTo,
            },
        ]);
        setEditingSegmentId(id);
    };
    const updateSegment = (id, patch) => setSegments((prev) => prev.map((s) => (s.id === id ? { ...s, ...patch } : s)));
    const removeSegment = (id) => {
        if (segments.length <= 1) return;
        setSegments((prev) => prev.filter((s) => s.id !== id));
        if (editingSegmentId === id) setEditingSegmentId(null);
    };

    const removeEntityFromMode = (specId) => {
        const numId = Number(String(specId).split('_')[1]);
        if (mode === 'campaigns') setPickedCampaignIds((prev) => prev.filter((x) => x !== numId));
        else if (mode === 'offers') setPickedOfferIds((prev) => prev.filter((x) => x !== numId));
        else if (mode === 'landings') setPickedLandingIds((prev) => prev.filter((x) => x !== numId));
        else if (mode === 'creatives') setPickedCreativeIds((prev) => prev.filter((x) => x !== numId));
        else if (mode === 'periods') removePeriodSlot(specId);
        else if (mode === 'segments') removeSegment(specId);
    };

    // Presets save / load / delete
    const handleSavePreset = () => {
        const name = presetName.trim();
        if (!name) return;
        const preset = {
            id: `preset_${Date.now()}`,
            name,
            mode,
            groupBy,
            primaryMetric,
            secondaryMetric,
            normalizeMode,
            overlayPrevPeriod,
            pickedCampaignIds,
            pickedOfferIds,
            pickedLandingIds,
            pickedCreativeIds,
            periodSlots,
            segments,
            seriesStyles,
        };
        const next = [preset, ...savedPresets].slice(0, 20);
        setSavedPresets(next);
        try { localStorage.setItem(PRESETS_STORAGE_KEY, JSON.stringify(next)); } catch { /* ignore */ }
        setPresetName('');
        setShowSavePreset(false);
    };

    const handleLoadPreset = (p) => {
        setMode(p.mode || 'campaigns');
        if (p.groupBy) setGroupBy(p.groupBy);
        if (p.primaryMetric) setPrimaryMetric(p.primaryMetric);
        setSecondaryMetric(p.secondaryMetric || '');
        setNormalizeMode(Boolean(p.normalizeMode));
        setOverlayPrevPeriod(Boolean(p.overlayPrevPeriod));
        if (Array.isArray(p.pickedCampaignIds)) setPickedCampaignIds(p.pickedCampaignIds);
        if (Array.isArray(p.pickedOfferIds)) setPickedOfferIds(p.pickedOfferIds);
        if (Array.isArray(p.pickedLandingIds)) setPickedLandingIds(p.pickedLandingIds);
        if (Array.isArray(p.pickedCreativeIds)) setPickedCreativeIds(p.pickedCreativeIds);
        if (Array.isArray(p.periodSlots) && p.periodSlots.length) setPeriodSlots(p.periodSlots);
        if (Array.isArray(p.segments) && p.segments.length) setSegments(p.segments);
        if (p.seriesStyles && typeof p.seriesStyles === 'object') setSeriesStyles(p.seriesStyles);
    };

    const handleDeletePreset = (id) => {
        const next = savedPresets.filter((p) => p.id !== id);
        setSavedPresets(next);
        try { localStorage.setItem(PRESETS_STORAGE_KEY, JSON.stringify(next)); } catch { /* ignore */ }
    };

    const exportCSV = () => {
        if (!activeSeriesSpecs.length) return;
        const headers = [
            t('analyticsCompare.series', 'Series'),
            t('trends.period'),
            ...summaryColumns.map((c) => availableMetrics.find((m) => m.key === c)?.label || c),
        ];
        const rowsOut = activeSeriesSpecs.map((spec) => {
            const tot = seriesResults[spec.id]?.totals || {};
            return [
                `"${String(spec.label).replace(/"/g, '""')}"`,
                `${spec.date_from}..${spec.date_to}`,
                ...summaryColumns.map((c) => (tot[c] === null || tot[c] === undefined ? '' : tot[c])),
            ];
        });
        const csv = [headers.join(';'), ...rowsOut.map((r) => r.join(';'))].join('\n');
        const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `compare_${mode}_${dateFrom}_${dateTo}.csv`;
        link.click();
    };

    const modeTabs = [
        { id: 'campaigns', label: t('analytics.campaigns'), icon: Layers },
        { id: 'offers', label: t('analytics.offers'), icon: Package },
        { id: 'landings', label: t('analytics.landings'), icon: LayoutTemplate },
        { id: 'creatives', label: t('analyticsCompare.creatives', 'Creatives'), icon: Images },
        { id: 'periods', label: t('analyticsCompare.periods', 'Periods'), icon: Calendar },
        { id: 'segments', label: t('analyticsCompare.segments', 'Segments'), icon: Sliders },
    ];

    const baselineTotals = activeSeriesSpecs.length > 0 ? seriesResults[activeSeriesSpecs[0].id]?.totals : null;
    const editingSegment = segments.find((s) => s.id === editingSegmentId) || null;

    return (
        <div className="space-y-4">
            {seamNote && (
                <div className="text-sm text-[var(--color-text-secondary)] bg-[var(--color-bg-secondary)] rounded-lg px-4 py-2">
                    {t('dashboard.seamAvailableFrom', { date: seamNote.split(' ')[0] })}
                </div>
            )}

            {/* Mode Switcher + Global Controls */}
            <div className="page-card space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-1.5">
                        {modeTabs.map((tab) => {
                            const Icon = tab.icon;
                            const active = mode === tab.id;
                            return (
                                <button
                                    key={tab.id}
                                    type="button"
                                    onClick={() => setMode(tab.id)}
                                    className="btn btn-sm"
                                    style={active
                                        ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }
                                        : { backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)', border: '1px solid var(--color-border)' }}
                                >
                                    <Icon className="w-3.5 h-3.5" />
                                    {tab.label}
                                </button>
                            );
                        })}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <div className="flex items-center gap-1.5">
                            <PieChart className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                            <select value={groupBy} onChange={(e) => setGroupBy(e.target.value)} className="form-select text-xs" style={{ width: 'auto', padding: '6px 10px' }}>
                                <option value="hour">{t('trends.hours')}</option>
                                <option value="day">{t('trends.days')}</option>
                                <option value="day_of_week">{t('trends.daysOfWeek')}</option>
                                <option value="month">{t('trends.months')}</option>
                            </select>
                        </div>

                        {mode !== 'periods' && (
                            <DateRangePicker
                                dateFrom={dateFrom}
                                dateTo={dateTo}
                                onChange={(from, to, preset) => {
                                    setDateFrom(from);
                                    setDateTo(to);
                                    if (preset) setDatePreset(preset);
                                }}
                                initialPreset={datePreset}
                                selectedTimezone={timezone}
                                onTimezoneChange={setTimezone}
                            />
                        )}

                        <button
                            type="button"
                            onClick={() => setShowSavePreset((v) => !v)}
                            className="btn btn-secondary btn-sm"
                            title={t('analyticsCompare.savePreset', 'Save comparison preset')}
                        >
                            <Bookmark className="w-3.5 h-3.5" />
                            {t('analyticsCompare.presets', 'Presets')}
                            {savedPresets.length > 0 && (
                                <span className="text-[10px] px-1.5 rounded-full" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                                    {savedPresets.length}
                                </span>
                            )}
                        </button>

                        <button type="button" onClick={exportCSV} className="btn btn-secondary btn-sm" disabled={!activeSeriesSpecs.length}>
                            <Download className="w-3.5 h-3.5" />
                            {t('trends.exportCsv')}
                        </button>
                    </div>
                </div>

                {/* Saved Presets Drawer */}
                {showSavePreset && (
                    <div className="p-3 rounded-xl border space-y-2.5"
                        style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
                        <div className="flex flex-wrap items-center gap-2">
                            <input
                                type="text"
                                value={presetName}
                                onChange={(e) => setPresetName(e.target.value)}
                                onKeyDown={(e) => { if (e.key === 'Enter') handleSavePreset(); }}
                                placeholder={t('analyticsCompare.presetPlaceholder', 'Preset name (e.g. Top 3 DE campaigns)...')}
                                className="form-input text-xs flex-1 min-w-[200px]"
                                style={{ padding: '6px 10px' }}
                            />
                            <button type="button" onClick={handleSavePreset} className="btn btn-primary btn-sm" disabled={!presetName.trim()}>
                                <Check className="w-3.5 h-3.5" />
                                {t('analyticsCompare.save', 'Save')}
                            </button>
                        </div>
                        {savedPresets.length > 0 ? (
                            <div className="flex flex-wrap gap-2">
                                {savedPresets.map((p) => (
                                    <span key={p.id} className="inline-flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-lg border"
                                        style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}>
                                        <button type="button" onClick={() => handleLoadPreset(p)} className="font-medium hover:underline"
                                            style={{ color: 'var(--color-text-primary)' }}>
                                            {p.name}
                                        </button>
                                        <button type="button" onClick={() => handleDeletePreset(p.id)} className="opacity-60 hover:opacity-100">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                ))}
                            </div>
                        ) : (
                            <p className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                {t('analyticsCompare.noPresets', 'No saved presets yet. Save your frequent comparisons for 1-click access.')}
                            </p>
                        )}
                    </div>
                )}

                {/* Entity / Period / Segment Pickers */}
                <div className="pt-2 border-t flex flex-wrap items-center gap-3" style={{ borderColor: 'var(--color-border)' }}>
                    {mode === 'campaigns' && (
                        <MultiSelect
                            icon={<Layers className="w-4 h-4" />}
                            label={t('analyticsCompare.pickCampaigns', 'Select campaigns (up to 8)')}
                            placeholder={t('analyticsCompare.chooseEntities', 'Choose items to compare...')}
                            items={campaigns}
                            groups={campaignGroups}
                            value={pickedCampaignIds}
                            onChange={(ids) => setPickedCampaignIds(ids.slice(0, MAX_SERIES))}
                        />
                    )}
                    {mode === 'offers' && (
                        <MultiSelect
                            icon={<Package className="w-4 h-4" />}
                            label={t('analyticsCompare.pickOffers', 'Select offers (up to 8)')}
                            placeholder={t('analyticsCompare.chooseEntities', 'Choose items to compare...')}
                            items={offers}
                            groups={offerGroups}
                            value={pickedOfferIds}
                            onChange={(ids) => setPickedOfferIds(ids.slice(0, MAX_SERIES))}
                        />
                    )}
                    {mode === 'landings' && (
                        <MultiSelect
                            icon={<LayoutTemplate className="w-4 h-4" />}
                            label={t('analyticsCompare.pickLandings', 'Select landings (up to 8)')}
                            placeholder={t('analyticsCompare.chooseEntities', 'Choose items to compare...')}
                            items={landings}
                            groups={landingGroups}
                            value={pickedLandingIds}
                            onChange={(ids) => setPickedLandingIds(ids.slice(0, MAX_SERIES))}
                        />
                    )}
                    {mode === 'creatives' && (
                        <MultiSelect
                            icon={<Images className="w-4 h-4" />}
                            label={t('analyticsCompare.pickCreatives', 'Select creatives (up to 8)')}
                            placeholder={t('analyticsCompare.chooseEntities', 'Choose items to compare...')}
                            items={creatives}
                            groups={[]}
                            value={pickedCreativeIds}
                            onChange={(ids) => setPickedCreativeIds(ids.slice(0, MAX_SERIES))}
                        />
                    )}

                    {mode === 'periods' && (
                        <div className="w-full space-y-2">
                            <div className="flex flex-wrap items-center gap-2">
                                {periodSlots.map((slot, idx) => (
                                    <div key={slot.id} className="flex flex-wrap items-center gap-1.5 px-2.5 py-1.5 rounded-xl border"
                                        style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
                                        <StyleSwatch style={idx === 0 ? 'solid' : 'dashed'} color={resolveSeriesVisual({ id: slot.id }, idx).hex} />
                                        <span className="text-[11px] font-semibold" style={{ color: 'var(--color-text-muted)' }}>
                                            {idx === 0 ? t('analyticsCompare.currentPeriod', 'Current') : `#${idx + 1}`}
                                        </span>
                                        <input
                                            type="date"
                                            value={slot.dateFrom}
                                            onChange={(e) => updatePeriodSlot(slot.id, { dateFrom: e.target.value })}
                                            className="form-input text-xs"
                                            style={{ width: 'auto', padding: '4px 6px' }}
                                        />
                                        <span style={{ color: 'var(--color-text-muted)' }}>—</span>
                                        <input
                                            type="date"
                                            value={slot.dateTo}
                                            onChange={(e) => updatePeriodSlot(slot.id, { dateTo: e.target.value })}
                                            className="form-input text-xs"
                                            style={{ width: 'auto', padding: '4px 6px' }}
                                        />
                                        {periodSlots.length > 2 && (
                                            <button type="button" onClick={() => removePeriodSlot(slot.id)} className="p-0.5 opacity-60 hover:opacity-100">
                                                <X className="w-3.5 h-3.5" />
                                            </button>
                                        )}
                                    </div>
                                ))}
                                {periodSlots.length < MAX_SERIES && (
                                    <button type="button" onClick={addPeriodSlot} className="btn btn-secondary btn-sm">
                                        <Plus className="w-3.5 h-3.5" />
                                        {t('analyticsCompare.addPeriod', 'Add period')}
                                    </button>
                                )}
                            </div>
                        </div>
                    )}

                    {mode === 'segments' && (
                        <div className="w-full space-y-2.5">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                                    {t('analyticsCompare.segmentsHint', 'Build arbitrary segments (e.g. Landing A vs Creative B, or Campaign + DE vs Campaign + FR). Click a segment card to edit its filters.')}
                                </span>
                                {segments.length < MAX_SERIES && (
                                    <button type="button" onClick={addSegment} className="btn btn-primary btn-sm">
                                        <Plus className="w-3.5 h-3.5" />
                                        {t('analyticsCompare.addSegment', 'Add segment')}
                                    </button>
                                )}
                            </div>
                            {editingSegment && (
                                <div className="p-3 rounded-xl border space-y-3"
                                    style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-primary)' }}>
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <input
                                            type="text"
                                            value={editingSegment.label}
                                            onChange={(e) => updateSegment(editingSegment.id, { label: e.target.value })}
                                            className="form-input text-xs font-semibold"
                                            style={{ maxWidth: 260, padding: '6px 10px' }}
                                            placeholder={t('analyticsCompare.segmentName', 'Segment name')}
                                        />
                                        <button type="button" onClick={() => setEditingSegmentId(null)} className="btn btn-secondary btn-sm">
                                            <Check className="w-3.5 h-3.5" />
                                            {t('demo.done', 'Done')}
                                        </button>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2.5">
                                        <MultiSelect
                                            icon={<Layers className="w-3.5 h-3.5" />}
                                            label={t('analytics.campaigns')}
                                            placeholder={t('analytics.allCampaigns')}
                                            items={campaigns}
                                            groups={campaignGroups}
                                            value={editingSegment.campaignIds}
                                            onChange={(v) => updateSegment(editingSegment.id, { campaignIds: v })}
                                        />
                                        <MultiSelect
                                            icon={<Package className="w-3.5 h-3.5" />}
                                            label={t('analytics.offers')}
                                            placeholder={t('analytics.allOffers')}
                                            items={offers}
                                            groups={offerGroups}
                                            value={editingSegment.offerIds}
                                            onChange={(v) => updateSegment(editingSegment.id, { offerIds: v })}
                                        />
                                        <MultiSelect
                                            icon={<LayoutTemplate className="w-3.5 h-3.5" />}
                                            label={t('analytics.landings')}
                                            placeholder={t('analytics.allLandings')}
                                            items={landings}
                                            groups={landingGroups}
                                            value={editingSegment.landingIds}
                                            onChange={(v) => updateSegment(editingSegment.id, { landingIds: v })}
                                        />
                                        <MultiSelect
                                            icon={<Images className="w-3.5 h-3.5" />}
                                            label={t('analyticsCompare.creatives', 'Creatives')}
                                            placeholder={t('analyticsCompare.allCreatives', 'All creatives')}
                                            items={creatives}
                                            groups={[]}
                                            value={editingSegment.creativeIds}
                                            onChange={(v) => updateSegment(editingSegment.id, { creativeIds: v })}
                                        />
                                        <MultiSelect
                                            icon={<Sliders className="w-3.5 h-3.5" />}
                                            label={t('nav.sources', 'Sources')}
                                            placeholder={t('analyticsCompare.allSources', 'All sources')}
                                            items={sources}
                                            groups={[]}
                                            value={editingSegment.sourceIds}
                                            onChange={(v) => updateSegment(editingSegment.id, { sourceIds: v })}
                                        />
                                        <input
                                            type="text"
                                            maxLength={2}
                                            value={editingSegment.country}
                                            onChange={(e) => updateSegment(editingSegment.id, { country: e.target.value })}
                                            placeholder={t('analyticsCompare.countryCode', 'GEO (e.g. DE)')}
                                            className="form-input text-xs uppercase"
                                            style={{ width: 110, padding: '6px 8px' }}
                                        />
                                        <select
                                            value={editingSegment.device}
                                            onChange={(e) => updateSegment(editingSegment.id, { device: e.target.value })}
                                            className="form-select text-xs"
                                            style={{ width: 'auto', padding: '6px 8px' }}
                                        >
                                            <option value="">{t('analyticsCompare.allDevices', 'All devices')}</option>
                                            <option value="mobile">Mobile</option>
                                            <option value="desktop">Desktop</option>
                                            <option value="tablet">Tablet</option>
                                        </select>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-3 text-xs">
                                        <label className="inline-flex items-center gap-1.5 cursor-pointer" style={{ color: 'var(--color-text-secondary)' }}>
                                            <input
                                                type="checkbox"
                                                checked={Boolean(editingSegment.useCustomDates)}
                                                onChange={(e) => updateSegment(editingSegment.id, {
                                                    useCustomDates: e.target.checked,
                                                    dateFrom: editingSegment.dateFrom || dateFrom,
                                                    dateTo: editingSegment.dateTo || dateTo,
                                                })}
                                                className="accent-[var(--color-primary)]"
                                            />
                                            {t('analyticsCompare.customSegmentPeriod', 'Own date range for this segment')}
                                        </label>
                                        {editingSegment.useCustomDates && (
                                            <div className="flex items-center gap-1.5">
                                                <input
                                                    type="date"
                                                    value={editingSegment.dateFrom || dateFrom}
                                                    onChange={(e) => updateSegment(editingSegment.id, { dateFrom: e.target.value })}
                                                    className="form-input text-xs"
                                                    style={{ width: 'auto', padding: '4px 8px' }}
                                                />
                                                <span>—</span>
                                                <input
                                                    type="date"
                                                    value={editingSegment.dateTo || dateTo}
                                                    onChange={(e) => updateSegment(editingSegment.id, { dateTo: e.target.value })}
                                                    className="form-input text-xs"
                                                    style={{ width: 'auto', padding: '4px 8px' }}
                                                />
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Optional dashed previous-period overlay for entity modes */}
                    {mode !== 'periods' && (
                        <div className="flex items-center gap-2 ml-auto">
                            <label className="inline-flex items-center gap-1.5 text-xs cursor-pointer select-none"
                                style={{ color: 'var(--color-text-secondary)' }}>
                                <input
                                    type="checkbox"
                                    checked={overlayPrevPeriod}
                                    onChange={(e) => setOverlayPrevPeriod(e.target.checked)}
                                    className="accent-[var(--color-primary)]"
                                />
                                <StyleSwatch style="dashed" color="var(--color-primary)" width={18} />
                                <span>{t('analyticsCompare.overlayPrev', 'Overlay prev period (dashed)')}</span>
                            </label>
                            {overlayPrevPeriod && (
                                <select
                                    value={overlayPreset}
                                    onChange={(e) => setOverlayPreset(e.target.value)}
                                    className="form-select text-xs"
                                    style={{ width: 'auto', padding: '4px 8px' }}
                                >
                                    <option value="prev">{t('trends.presetPrev', 'Previous period')}</option>
                                    <option value="wow">{t('trends.presetWow', 'Week ago (−7d)')}</option>
                                    <option value="mom">{t('trends.presetMom', 'Month ago (−28d)')}</option>
                                </select>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {/* Metric Selector + Chart Display Modes */}
            <div className="page-card space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-1.5">
                        {availableMetrics.map((m) => (
                            <button
                                key={m.key}
                                type="button"
                                onClick={() => setPrimaryMetric(m.key)}
                                className="px-2.5 py-1 rounded-lg text-xs font-medium transition-colors"
                                style={primaryMetric === m.key
                                    ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }
                                    : { backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)', border: '1px solid var(--color-border)' }}
                            >
                                {m.label}
                            </button>
                        ))}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {!normalizeMode && (
                            <select
                                value={secondaryMetric}
                                onChange={(e) => setSecondaryMetric(e.target.value)}
                                className="form-select text-xs"
                                style={{ width: 'auto', padding: '5px 10px' }}
                                title={t('analyticsCompare.secondaryMetricHint', 'Optional second metric on the right Y-axis')}
                            >
                                <option value="">+ {t('analyticsCompare.secondAxis', '2nd axis metric')}</option>
                                {availableMetrics.filter((m) => m.key !== primaryMetric).map((m) => (
                                    <option key={m.key} value={m.key}>{m.label}</option>
                                ))}
                            </select>
                        )}

                        <button
                            type="button"
                            onClick={() => setNormalizeMode((v) => !v)}
                            className={`btn btn-sm ${normalizeMode ? 'btn-primary' : 'btn-secondary'}`}
                            title={t('analyticsCompare.normalizeHint', 'Scale every series to 100% at its start so differently-sized entities can be compared by shape')}
                        >
                            <Percent className="w-3.5 h-3.5" />
                            {t('analyticsCompare.normalize', 'Normalize (%)')}
                        </button>

                        <button
                            type="button"
                            onClick={() => setCvdMode((v) => !v)}
                            className={`btn btn-sm ${cvdMode ? 'btn-primary' : 'btn-secondary'}`}
                            title={t('analyticsCompare.cvdHint', 'Color-blind safe palette (Okabe–Ito) with distinct line patterns')}
                        >
                            <Palette className="w-3.5 h-3.5" />
                            {t('analyticsCompare.cvdPalette', 'High-contrast')}
                        </button>
                    </div>
                </div>

                {/* Interactive Series Cards (Legend + Color/Dash Picker + Headline Delta) */}
                {activeSeriesSpecs.length > 0 && (
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5 pt-2 border-t" style={{ borderColor: 'var(--color-border)' }}>
                        {activeSeriesSpecs.map((spec, idx) => {
                            const visual = resolveSeriesVisual(spec, idx);
                            const tot = seriesResults[spec.id]?.totals;
                            const prevTot = overlayPrevPeriod && mode !== 'periods'
                                ? seriesResults[`${spec.id}__prev`]?.totals
                                : (idx > 0 ? baselineTotals : null);
                            const delta = tot && prevTot ? metricDelta(primaryMetric, tot[primaryMetric], prevTot[primaryMetric]) : null;
                            const isEditing = mode === 'segments' && editingSegmentId === spec.id;

                            return (
                                <div
                                    key={spec.id}
                                    onClick={mode === 'segments' ? () => setEditingSegmentId(isEditing ? null : spec.id) : undefined}
                                    className="relative rounded-xl px-3 py-2.5 border transition-all"
                                    style={{
                                        backgroundColor: 'var(--color-bg-soft)',
                                        borderColor: isEditing ? 'var(--color-primary)' : 'var(--color-border)',
                                        opacity: visual.hidden ? 0.45 : 1,
                                        cursor: mode === 'segments' ? 'pointer' : 'default',
                                    }}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <button
                                            type="button"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                setOpenStyleId(openStyleId === spec.id ? null : spec.id);
                                            }}
                                            className="inline-flex items-center gap-2 min-w-0 text-left hover:opacity-80"
                                            title={t('analyticsCompare.customizeSeries', 'Customize color & line style')}
                                        >
                                            <span className="p-1 rounded-md border flex items-center"
                                                style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}>
                                                <StyleSwatch style={visual.style} color={visual.hex} />
                                            </span>
                                            <span className="text-xs font-semibold truncate" style={{ color: 'var(--color-text-primary)' }}>
                                                {spec.label}
                                            </span>
                                        </button>

                                        <div className="flex items-center gap-1 shrink-0" onClick={(e) => e.stopPropagation()}>
                                            <button
                                                type="button"
                                                onClick={() => updateSeriesStyle(spec.id, { hidden: !visual.hidden })}
                                                className="p-1 rounded hover:bg-black/10"
                                                style={{ color: 'var(--color-text-muted)' }}
                                                title={visual.hidden ? t('analyticsCompare.show', 'Show') : t('analyticsCompare.hide', 'Hide')}
                                            >
                                                {visual.hidden ? <EyeOff className="w-3.5 h-3.5" /> : <Eye className="w-3.5 h-3.5" />}
                                            </button>
                                            {(mode !== 'periods' || periodSlots.length > 2) && (mode !== 'segments' || segments.length > 1) && (
                                                <button
                                                    type="button"
                                                    onClick={() => removeEntityFromMode(spec.id)}
                                                    className="p-1 rounded hover:bg-black/10"
                                                    style={{ color: 'var(--color-text-muted)' }}
                                                    title={t('common.delete', 'Remove')}
                                                >
                                                    <Trash2 className="w-3.5 h-3.5" />
                                                </button>
                                            )}
                                        </div>
                                    </div>

                                    <div className="flex items-baseline justify-between gap-2 mt-1.5">
                                        <span className="text-lg font-bold tabular-nums" style={{ color: 'var(--color-text-primary)' }}>
                                            {tot ? formatMetric(primaryMetric, tot[primaryMetric]) : '—'}
                                        </span>
                                        {delta && <DeltaBadge delta={delta} t={t} />}
                                        {!delta && idx === 0 && !overlayPrevPeriod && (
                                            <span className="text-[10px] px-1.5 py-0.5 rounded font-medium"
                                                style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                                                {t('analyticsCompare.baseline', 'Baseline')}
                                            </span>
                                        )}
                                    </div>

                                    {mode === 'segments' && (
                                        <div className="text-[10px] mt-0.5 truncate" style={{ color: 'var(--color-text-muted)' }}>
                                            {spec.filters.length > 0
                                                ? `${spec.filters.length} ${t('analyticsCompare.filtersActive', 'filters')} · ${spec.date_from}..${spec.date_to}`
                                                : t('analyticsCompare.clickToFilter', 'All traffic · click to filter')}
                                        </div>
                                    )}

                                    {openStyleId === spec.id && (
                                        <SeriesStylePopover
                                            color={seriesStyles[spec.id]?.color}
                                            resolvedHex={visual.hex}
                                            style={visual.style}
                                            fill={visual.fill}
                                            onChangeColor={(c) => updateSeriesStyle(spec.id, { color: c })}
                                            onChangeStyle={(s) => updateSeriesStyle(spec.id, { style: s })}
                                            onChangeFill={(f) => updateSeriesStyle(spec.id, { fill: f })}
                                            onReset={() => resetSeriesStyle(spec.id)}
                                            onClose={() => setOpenStyleId(null)}
                                        />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}

                {/* Main Chart */}
                <div style={{ height: '360px' }}>
                    {loading ? (
                        <div className="empty-state"><p style={{ color: 'var(--color-text-muted)' }}>{t('trends.loading')}</p></div>
                    ) : activeSeriesSpecs.length === 0 ? (
                        <div className="empty-state">
                            <GitCompareArrows className="w-8 h-8 mb-2 opacity-40" style={{ color: 'var(--color-text-muted)' }} />
                            <p className="empty-state-title">{t('analyticsCompare.emptyTitle', 'Select items to compare')}</p>
                            <p className="empty-state-text">{t('analyticsCompare.emptyHint', 'Pick 2 or more campaigns, offers, landings, creatives or periods above.')}</p>
                        </div>
                    ) : chartObj ? (
                        <Chart type="line" data={chartObj} options={chartOptions} />
                    ) : (
                        <div className="empty-state"><p style={{ color: 'var(--color-text-muted)' }}>{t('trends.noData')}</p></div>
                    )}
                </div>
            </div>

            {/* Summary Benchmark Table */}
            {activeSeriesSpecs.length > 0 && (
                <div className="page-card" style={{ padding: 0 }}>
                    <div className="page-header" style={{ padding: '16px 24px', marginBottom: 0 }}>
                        <div className="flex items-center gap-2">
                            <Trophy className="w-5 h-5" style={{ color: 'var(--color-warning, #f59e0b)' }} />
                            <h3 className="page-title">{t('analyticsCompare.summaryTitle', 'Comparison Summary')}</h3>
                        </div>
                        <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                            {t('analyticsCompare.deltaVsBaseline', 'Deltas shown vs first series (baseline)')}
                        </span>
                    </div>
                    <div className="overflow-x-auto min-w-0">
                        <table className="page-table">
                            <thead>
                                <tr>
                                    <th data-col style={{ textAlign: 'left' }}>{t('analyticsCompare.series', 'Series')}</th>
                                    {summaryColumns.map((col) => (
                                        <th key={col} data-col style={{ textAlign: 'right' }}>
                                            {availableMetrics.find((m) => m.key === col)?.label || col}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {activeSeriesSpecs.map((spec, idx) => {
                                    const visual = resolveSeriesVisual(spec, idx);
                                    const tot = seriesResults[spec.id]?.totals;
                                    return (
                                        <tr key={spec.id} style={{ opacity: visual.hidden ? 0.5 : 1 }}>
                                            <td style={{ fontWeight: 600 }}>
                                                <div className="flex items-center gap-2">
                                                    <StyleSwatch style={visual.style} color={visual.hex} width={18} />
                                                    <span>{spec.label}</span>
                                                    {idx === 0 && (
                                                        <span className="text-[10px] px-1.5 py-0.5 rounded font-medium"
                                                            style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                                                            {t('analyticsCompare.baseline', 'Base')}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="text-[10px] font-normal mt-0.5" style={{ color: 'var(--color-text-muted)' }}>
                                                    {spec.date_from} — {spec.date_to}
                                                </div>
                                            </td>
                                            {summaryColumns.map((col) => {
                                                const val = tot?.[col];
                                                const isLeader = columnLeaders[col] === spec.id;
                                                const d = (idx > 0 && tot && baselineTotals)
                                                    ? metricDelta(col, val, baselineTotals[col])
                                                    : null;
                                                return (
                                                    <td
                                                        key={col}
                                                        style={{
                                                            textAlign: 'right',
                                                            fontVariantNumeric: 'tabular-nums',
                                                            backgroundColor: isLeader
                                                                ? 'color-mix(in srgb, var(--color-success, #10b981) 9%, transparent)'
                                                                : undefined,
                                                        }}
                                                    >
                                                        <div style={{
                                                            fontWeight: isLeader ? 700 : 400,
                                                            color: isLeader ? 'var(--color-success, #10b981)' : 'var(--color-text-primary)',
                                                        }}>
                                                            {tot ? formatMetric(col, val) : '—'}
                                                        </div>
                                                        {d && <div><DeltaBadge delta={d} t={t} /></div>}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
};

export default AnalyticsCompareView;
