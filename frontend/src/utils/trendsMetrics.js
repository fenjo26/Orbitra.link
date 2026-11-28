// Persistence helpers for the Trends view: selected metrics, grouping,
// date range, and named presets. Stored per signed-in user in localStorage
// (matching Dashboard's orbitra_dashboard_active_metrics_<user> and the rest
// of Trends settings).

export const TRENDS_METRICS_STORAGE_PREFIX = 'orbitra_trends_metrics';
export const TRENDS_GROUP_BY_STORAGE_PREFIX = 'orbitra_trends_group_by';
export const TRENDS_DATE_RANGE_STORAGE_PREFIX = 'orbitra_trends_date_range';
export const TRENDS_PRESETS_STORAGE_PREFIX = 'orbitra_trends_metric_presets';
export const MAX_TRENDS_METRIC_PRESETS = 20;
const MAX_PRESET_NAME_LEN = 60;

export const DEFAULT_TRENDS_METRICS = [
    'clicks',
    'unique_clicks',
    'conversions',
    'revenue',
    'profit',
    'cr',
];

export const TRENDS_METRIC_KEYS = [
    'clicks',
    'unique_clicks',
    'conversions',
    'revenue',
    'real_revenue',
    'cost',
    'profit',
    'roi',
    'real_roi',
    'ctr',
    'cr',
    'epc',
    'cpa',
];

export const DEFAULT_TRENDS_GROUP_BY = 'day';
export const TRENDS_GROUP_BY_VALUES = ['month', 'day_of_week', 'day', 'hour'];

export const DEFAULT_TRENDS_DATE_PRESET = 'last7Days';
export const TRENDS_DATE_PRESETS = [
    'today',
    'yesterday',
    'thisWeek',
    'last7Days',
    'thisMonth',
    'last30Days',
    'previousMonth',
];

const VALID_TRENDS_METRICS = new Set(TRENDS_METRIC_KEYS);
const VALID_GROUP_BY = new Set(TRENDS_GROUP_BY_VALUES);
const VALID_DATE_PRESETS = new Set(TRENDS_DATE_PRESETS);
const ISO_DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

const getDefaultStorage = () => (typeof window !== 'undefined' ? window.localStorage : null);

const scopedKey = (prefix, user) => {
    const userKey = user?.id ?? user?.username;
    return userKey !== undefined && userKey !== null && userKey !== ''
        ? `${prefix}_${userKey}`
        : prefix;
};

export const getTrendsMetricsStorageKey = (user) => scopedKey(TRENDS_METRICS_STORAGE_PREFIX, user);

export const getTrendsGroupByStorageKey = (user) => scopedKey(TRENDS_GROUP_BY_STORAGE_PREFIX, user);

export const getTrendsDateRangeStorageKey = (user) => scopedKey(TRENDS_DATE_RANGE_STORAGE_PREFIX, user);

export const getTrendsPresetsStorageKey = (user) => scopedKey(TRENDS_PRESETS_STORAGE_PREFIX, user);

export const sanitizeTrendsMetrics = (metrics) => {
    if (!Array.isArray(metrics)) return [...DEFAULT_TRENDS_METRICS];
    const valid = [...new Set(metrics.filter((m) => typeof m === 'string' && VALID_TRENDS_METRICS.has(m)))];
    return valid.length > 0 ? valid : [...DEFAULT_TRENDS_METRICS];
};

export const sanitizeTrendsGroupBy = (groupBy) => (
    typeof groupBy === 'string' && VALID_GROUP_BY.has(groupBy) ? groupBy : DEFAULT_TRENDS_GROUP_BY
);

export const sanitizeTrendsDatePreset = (preset) => (
    typeof preset === 'string' && VALID_DATE_PRESETS.has(preset) ? preset : null
);

export const loadTrendsMetrics = (user, storage = getDefaultStorage()) => {
    if (!storage) return [...DEFAULT_TRENDS_METRICS];
    const key = getTrendsMetricsStorageKey(user);
    try {
        const raw = storage.getItem(key);
        if (raw === null) return [...DEFAULT_TRENDS_METRICS];
        return sanitizeTrendsMetrics(JSON.parse(raw));
    } catch {
        return [...DEFAULT_TRENDS_METRICS];
    }
};

export const saveTrendsMetrics = (user, metrics, storage = getDefaultStorage()) => {
    if (!storage) return;
    const key = getTrendsMetricsStorageKey(user);
    try {
        storage.setItem(key, JSON.stringify(sanitizeTrendsMetrics(metrics)));
    } catch {
        // Ignore storage issues (private mode, quota, etc.).
    }
};

export const loadTrendsGroupBy = (user, storage = getDefaultStorage()) => {
    if (!storage) return DEFAULT_TRENDS_GROUP_BY;
    const key = getTrendsGroupByStorageKey(user);
    try {
        const raw = storage.getItem(key);
        if (raw === null) return DEFAULT_TRENDS_GROUP_BY;
        return sanitizeTrendsGroupBy(raw);
    } catch {
        return DEFAULT_TRENDS_GROUP_BY;
    }
};

export const saveTrendsGroupBy = (user, groupBy, storage = getDefaultStorage()) => {
    if (!storage) return;
    const key = getTrendsGroupByStorageKey(user);
    try {
        storage.setItem(key, sanitizeTrendsGroupBy(groupBy));
    } catch {
        // Ignore storage issues (private mode, quota, etc.).
    }
};

const classifyDateRangePreset = (from, to, resolvePresetDates) => {
    if (!from || !to || typeof resolvePresetDates !== 'function') return 'custom';
    for (const id of TRENDS_DATE_PRESETS) {
        const r = resolvePresetDates(id);
        if (r && r.from === from && r.to === to) return id;
    }
    return 'custom';
};

export const loadTrendsDateRange = (user, resolvePresetDates, storage = getDefaultStorage()) => {
    const fallbackRange = (typeof resolvePresetDates === 'function'
        ? (resolvePresetDates(DEFAULT_TRENDS_DATE_PRESET) || resolvePresetDates('today'))
        : null) || { from: '', to: '' };
    const fallback = {
        from: fallbackRange.from,
        to: fallbackRange.to,
        preset: DEFAULT_TRENDS_DATE_PRESET,
    };
    if (!storage) return fallback;

    const key = getTrendsDateRangeStorageKey(user);
    let saved = null;
    try {
        saved = JSON.parse(storage.getItem(key) || 'null');
    } catch {
        return fallback;
    }
    if (!saved || typeof saved !== 'object') return fallback;

    // Relative presets always re-derive fresh dates (e.g. "today" opens on actual today).
    const validPreset = sanitizeTrendsDatePreset(saved.preset);
    if (validPreset && typeof resolvePresetDates === 'function') {
        const derived = resolvePresetDates(validPreset);
        if (derived && ISO_DATE_RE.test(derived.from) && ISO_DATE_RE.test(derived.to)) {
            return { from: derived.from, to: derived.to, preset: validPreset };
        }
    }

    // Custom ranges survive only while their end date still touches today (same rule as useDateRange).
    const todayIso = (typeof resolvePresetDates === 'function' && resolvePresetDates('today')?.to) || fallback.to;
    if (
        (!saved.preset || saved.preset === 'custom')
        && typeof saved.from === 'string'
        && typeof saved.to === 'string'
        && ISO_DATE_RE.test(saved.from)
        && ISO_DATE_RE.test(saved.to)
        && saved.from <= saved.to
        && (!todayIso || saved.to >= todayIso)
    ) {
        return { from: saved.from, to: saved.to, preset: 'custom' };
    }

    return fallback;
};

export const saveTrendsDateRange = (user, range, resolvePresetDates, storage = getDefaultStorage()) => {
    if (!storage || !range || typeof range !== 'object') return;
    const { from, to } = range;
    if (typeof from !== 'string' || typeof to !== 'string' || !ISO_DATE_RE.test(from) || !ISO_DATE_RE.test(to)) {
        return;
    }
    const preset = sanitizeTrendsDatePreset(range.preset)
        || (range.preset === 'custom' ? 'custom' : classifyDateRangePreset(from, to, resolvePresetDates));
    const key = getTrendsDateRangeStorageKey(user);
    try {
        storage.setItem(key, JSON.stringify({ from, to, preset }));
    } catch {
        // Ignore storage issues (private mode, quota, etc.).
    }
};

export const sanitizeTrendsMetricPresets = (presets) => {
    if (!Array.isArray(presets)) return [];
    const seenIds = new Set();
    const out = [];
    for (const item of presets) {
        if (!item || typeof item !== 'object') continue;
        const name = typeof item.name === 'string' ? item.name.trim().slice(0, MAX_PRESET_NAME_LEN) : '';
        if (!name || !Array.isArray(item.metrics)) continue;
        const validMetrics = [...new Set(item.metrics.filter((m) => typeof m === 'string' && VALID_TRENDS_METRICS.has(m)))];
        if (validMetrics.length === 0) continue;
        const id = typeof item.id === 'string' && item.id.trim()
            ? item.id.trim()
            : `preset_${out.length + 1}_${name.toLowerCase()}`;
        if (seenIds.has(id)) continue;
        seenIds.add(id);
        const entry = { id, name, metrics: validMetrics };
        if (typeof item.groupBy === 'string' && VALID_GROUP_BY.has(item.groupBy)) {
            entry.groupBy = item.groupBy;
        }
        const validDatePreset = sanitizeTrendsDatePreset(item.datePreset);
        if (validDatePreset) {
            entry.datePreset = validDatePreset;
        }
        out.push(entry);
        if (out.length >= MAX_TRENDS_METRIC_PRESETS) break;
    }
    return out;
};

export const loadTrendsMetricPresets = (user, storage = getDefaultStorage()) => {
    if (!storage) return [];
    const key = getTrendsPresetsStorageKey(user);
    try {
        const raw = storage.getItem(key);
        if (raw === null) return [];
        return sanitizeTrendsMetricPresets(JSON.parse(raw));
    } catch {
        return [];
    }
};

export const saveTrendsMetricPresets = (user, presets, storage = getDefaultStorage()) => {
    if (!storage) return;
    const key = getTrendsPresetsStorageKey(user);
    try {
        storage.setItem(key, JSON.stringify(sanitizeTrendsMetricPresets(presets)));
    } catch {
        // Ignore storage issues (private mode, quota, etc.).
    }
};

export const upsertTrendsMetricPreset = (
    presets,
    rawName,
    metrics,
    optionsOrIdFactory = {},
) => {
    const isFn = typeof optionsOrIdFactory === 'function';
    const opts = isFn ? { idFactory: optionsOrIdFactory } : (optionsOrIdFactory || {});
    const idFactory = typeof opts.idFactory === 'function'
        ? opts.idFactory
        : () => `preset_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;

    const name = typeof rawName === 'string' ? rawName.trim().slice(0, MAX_PRESET_NAME_LEN) : '';
    const list = sanitizeTrendsMetricPresets(presets);
    if (!name) return list;

    const sanitizedMetrics = sanitizeTrendsMetrics(metrics);
    const entryFields = { name, metrics: sanitizedMetrics };
    if (typeof opts.groupBy === 'string' && VALID_GROUP_BY.has(opts.groupBy)) {
        entryFields.groupBy = opts.groupBy;
    }
    const validDatePreset = sanitizeTrendsDatePreset(opts.datePreset);
    if (validDatePreset) {
        entryFields.datePreset = validDatePreset;
    }

    const needle = name.toLowerCase();
    const existingIdx = list.findIndex((p) => p.name.toLowerCase() === needle);
    if (existingIdx !== -1) {
        const next = list.slice();
        next[existingIdx] = { id: next[existingIdx].id, ...entryFields };
        return next;
    }
    const created = { id: idFactory(), ...entryFields };
    return [...list, created].slice(-MAX_TRENDS_METRIC_PRESETS);
};

export const deleteTrendsMetricPreset = (presets, id) => (
    sanitizeTrendsMetricPresets(presets).filter((p) => p.id !== id)
);

export const metricsMatchPreset = (selectedMetrics, presetMetrics) => {
    if (!Array.isArray(selectedMetrics) || !Array.isArray(presetMetrics)) return false;
    if (selectedMetrics.length !== presetMetrics.length || selectedMetrics.length === 0) return false;
    const set = new Set(presetMetrics);
    return selectedMetrics.every((m) => set.has(m));
};

export const presetMatchesTrendsState = (preset, state) => {
    if (!preset || !state) return false;
    if (!metricsMatchPreset(state.metrics, preset.metrics)) return false;
    if (preset.groupBy && preset.groupBy !== state.groupBy) return false;
    if (preset.datePreset && preset.datePreset !== state.datePreset) return false;
    return true;
};
