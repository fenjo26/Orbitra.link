// Persistence helpers for the Trends view metric selector and named presets.
// Stored per signed-in user in localStorage (matching Dashboard's
// orbitra_dashboard_active_metrics_<user> and the other Trends settings).

export const TRENDS_METRICS_STORAGE_PREFIX = 'orbitra_trends_metrics';
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

const VALID_TRENDS_METRICS = new Set(TRENDS_METRIC_KEYS);

const getDefaultStorage = () => (typeof window !== 'undefined' ? window.localStorage : null);

const scopedKey = (prefix, user) => {
    const userKey = user?.id ?? user?.username;
    return userKey !== undefined && userKey !== null && userKey !== ''
        ? `${prefix}_${userKey}`
        : prefix;
};

export const getTrendsMetricsStorageKey = (user) => scopedKey(TRENDS_METRICS_STORAGE_PREFIX, user);

export const getTrendsPresetsStorageKey = (user) => scopedKey(TRENDS_PRESETS_STORAGE_PREFIX, user);

export const sanitizeTrendsMetrics = (metrics) => {
    if (!Array.isArray(metrics)) return [...DEFAULT_TRENDS_METRICS];
    const valid = [...new Set(metrics.filter((m) => typeof m === 'string' && VALID_TRENDS_METRICS.has(m)))];
    return valid.length > 0 ? valid : [...DEFAULT_TRENDS_METRICS];
};

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
        out.push({ id, name, metrics: validMetrics });
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
    idFactory = () => `preset_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`,
) => {
    const name = typeof rawName === 'string' ? rawName.trim().slice(0, MAX_PRESET_NAME_LEN) : '';
    const list = sanitizeTrendsMetricPresets(presets);
    if (!name) return list;
    const sanitizedMetrics = sanitizeTrendsMetrics(metrics);
    const needle = name.toLowerCase();
    const existingIdx = list.findIndex((p) => p.name.toLowerCase() === needle);
    if (existingIdx !== -1) {
        const next = list.slice();
        next[existingIdx] = { ...next[existingIdx], name, metrics: sanitizedMetrics };
        return next;
    }
    const created = { id: idFactory(), name, metrics: sanitizedMetrics };
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
