import test from 'node:test';
import assert from 'node:assert/strict';
import {
    DEFAULT_TRENDS_METRICS,
    TRENDS_METRIC_KEYS,
    DEFAULT_TRENDS_GROUP_BY,
    DEFAULT_TRENDS_DATE_PRESET,
    MAX_TRENDS_METRIC_PRESETS,
    getTrendsMetricsStorageKey,
    getTrendsGroupByStorageKey,
    getTrendsDateRangeStorageKey,
    getTrendsPresetsStorageKey,
    sanitizeTrendsMetrics,
    sanitizeTrendsGroupBy,
    loadTrendsMetrics,
    saveTrendsMetrics,
    loadTrendsGroupBy,
    saveTrendsGroupBy,
    loadTrendsDateRange,
    saveTrendsDateRange,
    sanitizeTrendsMetricPresets,
    loadTrendsMetricPresets,
    saveTrendsMetricPresets,
    upsertTrendsMetricPreset,
    deleteTrendsMetricPreset,
    metricsMatchPreset,
    presetMatchesTrendsState,
} from '../frontend/src/utils/trendsMetrics.js';

const createMemoryStorage = (initial = {}) => {
    const map = new Map(Object.entries(initial));
    return {
        getItem: (k) => (map.has(k) ? map.get(k) : null),
        setItem: (k, v) => { map.set(k, String(v)); },
        removeItem: (k) => { map.delete(k); },
    };
};

const fakePresetDates = (today = '2026-11-28') => (preset) => {
    if (preset === 'today') return { from: today, to: today };
    if (preset === 'yesterday') return { from: '2026-11-27', to: '2026-11-27' };
    if (preset === 'last7Days') return { from: '2026-11-22', to: today };
    if (preset === 'last30Days') return { from: '2026-10-30', to: today };
    return null;
};

test('storage keys scope by user id, fall back to username, then shared key', () => {
    assert.equal(getTrendsMetricsStorageKey({ id: 42, username: 'ruslan' }), 'orbitra_trends_metrics_42');
    assert.equal(getTrendsMetricsStorageKey({ username: 'ruslan' }), 'orbitra_trends_metrics_ruslan');
    assert.equal(getTrendsMetricsStorageKey(null), 'orbitra_trends_metrics');
    assert.equal(getTrendsMetricsStorageKey({}), 'orbitra_trends_metrics');

    assert.equal(getTrendsGroupByStorageKey({ id: 42 }), 'orbitra_trends_group_by_42');
    assert.equal(getTrendsDateRangeStorageKey({ id: 42 }), 'orbitra_trends_date_range_42');
    assert.equal(getTrendsPresetsStorageKey({ id: 42, username: 'ruslan' }), 'orbitra_trends_metric_presets_42');
    assert.equal(getTrendsPresetsStorageKey({ username: 'ruslan' }), 'orbitra_trends_metric_presets_ruslan');
    assert.equal(getTrendsPresetsStorageKey(null), 'orbitra_trends_metric_presets');
});

test('loadTrendsMetrics returns defaults when missing, invalid JSON, or empty', () => {
    const user = { id: 7 };
    assert.deepEqual(loadTrendsMetrics(user, createMemoryStorage()), DEFAULT_TRENDS_METRICS);
    assert.deepEqual(
        loadTrendsMetrics(user, createMemoryStorage({ orbitra_trends_metrics_7: '{bad json' })),
        DEFAULT_TRENDS_METRICS,
    );
    assert.deepEqual(
        loadTrendsMetrics(user, createMemoryStorage({ orbitra_trends_metrics_7: '[]' })),
        DEFAULT_TRENDS_METRICS,
    );
    assert.deepEqual(
        loadTrendsMetrics(user, createMemoryStorage({ orbitra_trends_metrics_7: '["nonexistent"]' })),
        DEFAULT_TRENDS_METRICS,
    );
});

test('saveTrendsMetrics persists and loadTrendsMetrics restores exact selection per user', () => {
    const storage = createMemoryStorage();
    const userA = { id: 1 };
    const userB = { id: 2 };

    saveTrendsMetrics(userA, ['unique_clicks'], storage);
    saveTrendsMetrics(userB, ['revenue', 'real_revenue', 'roi', 'real_roi', 'epc', 'cpa'], storage);

    assert.deepEqual(loadTrendsMetrics(userA, storage), ['unique_clicks']);
    assert.deepEqual(
        loadTrendsMetrics(userB, storage),
        ['revenue', 'real_revenue', 'roi', 'real_roi', 'epc', 'cpa'],
    );
});

test('groupBy auto-saves per user and sanitizes unknown values', () => {
    const storage = createMemoryStorage();
    assert.equal(loadTrendsGroupBy({ id: 1 }, storage), DEFAULT_TRENDS_GROUP_BY);
    assert.equal(sanitizeTrendsGroupBy('invalid'), DEFAULT_TRENDS_GROUP_BY);

    saveTrendsGroupBy({ id: 1 }, 'hour', storage);
    saveTrendsGroupBy({ id: 2 }, 'month', storage);
    assert.equal(loadTrendsGroupBy({ id: 1 }, storage), 'hour');
    assert.equal(loadTrendsGroupBy({ id: 2 }, storage), 'month');
    assert.equal(
        loadTrendsGroupBy({ id: 3 }, createMemoryStorage({ orbitra_trends_group_by_3: 'quarter' })),
        DEFAULT_TRENDS_GROUP_BY,
    );
});

test('dateRange auto-saves relative presets and re-derives fresh dates on later days', () => {
    const storage = createMemoryStorage();
    const user = { id: 5 };

    // Default when nothing saved
    assert.deepEqual(loadTrendsDateRange(user, fakePresetDates('2026-11-28'), storage), {
        from: '2026-11-22',
        to: '2026-11-28',
        preset: DEFAULT_TRENDS_DATE_PRESET,
    });

    // Save "today" on Nov 28 -> re-opens on Nov 29 with Nov 29 dates!
    saveTrendsDateRange(user, { from: '2026-11-28', to: '2026-11-28', preset: 'today' }, fakePresetDates('2026-11-28'), storage);
    assert.deepEqual(loadTrendsDateRange(user, fakePresetDates('2026-11-29'), storage), {
        from: '2026-11-29',
        to: '2026-11-29',
        preset: 'today',
    });

    // Custom range touching today survives; past custom range falls back to default
    saveTrendsDateRange(user, { from: '2026-11-20', to: '2026-11-28', preset: 'custom' }, fakePresetDates('2026-11-28'), storage);
    assert.deepEqual(loadTrendsDateRange(user, fakePresetDates('2026-11-28'), storage), {
        from: '2026-11-20',
        to: '2026-11-28',
        preset: 'custom',
    });
    assert.deepEqual(loadTrendsDateRange(user, fakePresetDates('2026-11-29'), storage), {
        from: '2026-11-22',
        to: '2026-11-29',
        preset: DEFAULT_TRENDS_DATE_PRESET,
    });
});

test('sanitizeTrendsMetrics deduplicates, filters invalid keys, and accepts every supported metric', () => {
    assert.deepEqual(
        sanitizeTrendsMetrics(['unique_clicks', 'bogus', 'unique_clicks', 123, 'cpa']),
        ['unique_clicks', 'cpa'],
    );
    assert.deepEqual(sanitizeTrendsMetrics(TRENDS_METRIC_KEYS), TRENDS_METRIC_KEYS);
});

test('presets upsert stores metrics, groupBy, and relative datePreset, updates in place, and persists per user', () => {
    const storage = createMemoryStorage();
    const user = { id: 9 };
    let counter = 0;
    const nextId = () => `p_${++counter}`;

    let presets = loadTrendsMetricPresets(user, storage);
    assert.deepEqual(presets, []);

    presets = upsertTrendsMetricPreset(presets, '  Сегодня уники  ', ['unique_clicks'], {
        groupBy: 'hour',
        datePreset: 'today',
        idFactory: nextId,
    });
    presets = upsertTrendsMetricPreset(presets, 'Финансы', ['revenue', 'cost', 'profit', 'roi'], {
        groupBy: 'day',
        datePreset: 'custom', // custom dates are not frozen into presets
        idFactory: nextId,
    });
    assert.deepEqual(presets, [
        { id: 'p_1', name: 'Сегодня уники', metrics: ['unique_clicks'], groupBy: 'hour', datePreset: 'today' },
        { id: 'p_2', name: 'Финансы', metrics: ['revenue', 'cost', 'profit', 'roi'], groupBy: 'day' },
    ]);

    // Case-insensitive update preserves position & id, updates metrics/groupBy/datePreset cleanly
    presets = upsertTrendsMetricPreset(presets, 'сегодня уники', ['unique_clicks', 'ctr'], {
        groupBy: 'day',
        datePreset: 'last7Days',
        idFactory: nextId,
    });
    assert.deepEqual(presets, [
        { id: 'p_1', name: 'сегодня уники', metrics: ['unique_clicks', 'ctr'], groupBy: 'day', datePreset: 'last7Days' },
        { id: 'p_2', name: 'Финансы', metrics: ['revenue', 'cost', 'profit', 'roi'], groupBy: 'day' },
    ]);

    saveTrendsMetricPresets(user, presets, storage);
    assert.deepEqual(loadTrendsMetricPresets(user, storage), presets);
    assert.deepEqual(loadTrendsMetricPresets({ id: 10 }, storage), []);

    presets = deleteTrendsMetricPreset(presets, 'p_1');
    assert.deepEqual(presets, [
        { id: 'p_2', name: 'Финансы', metrics: ['revenue', 'cost', 'profit', 'roi'], groupBy: 'day' },
    ]);
});

test('sanitizeTrendsMetricPresets drops corrupt entries, keeps legacy metrics-only presets, and enforces cap', () => {
    const dirty = [
        null,
        { id: 'a', name: '   ', metrics: ['clicks'] },
        { id: 'b', name: 'Empty', metrics: ['unknown_only'] },
        { id: 'c', name: 'Legacy', metrics: ['clicks', 'unknown', 'clicks', 'cr'], groupBy: 'bogus', datePreset: 'custom' },
        { id: 'c', name: 'DupId', metrics: ['roi'] },
    ];
    assert.deepEqual(sanitizeTrendsMetricPresets(dirty), [
        { id: 'c', name: 'Legacy', metrics: ['clicks', 'cr'] },
    ]);

    let many = [];
    for (let i = 1; i <= MAX_TRENDS_METRIC_PRESETS + 5; i += 1) {
        many = upsertTrendsMetricPreset(many, `Preset ${i}`, ['clicks'], () => `id_${i}`);
    }
    assert.equal(many.length, MAX_TRENDS_METRIC_PRESETS);
    assert.equal(many[0].name, 'Preset 6');
    assert.equal(many[many.length - 1].name, `Preset ${MAX_TRENDS_METRIC_PRESETS + 5}`);
});

test('metricsMatchPreset and presetMatchesTrendsState match sets and optional groupBy/datePreset', () => {
    assert.equal(metricsMatchPreset(['clicks', 'cr'], ['cr', 'clicks']), true);
    assert.equal(metricsMatchPreset(['clicks'], ['clicks', 'cr']), false);
    assert.equal(metricsMatchPreset([], []), false);

    const fullPreset = { id: '1', name: 'Today', metrics: ['clicks', 'cr'], groupBy: 'hour', datePreset: 'today' };
    assert.equal(presetMatchesTrendsState(fullPreset, { metrics: ['cr', 'clicks'], groupBy: 'hour', datePreset: 'today' }), true);
    assert.equal(presetMatchesTrendsState(fullPreset, { metrics: ['cr', 'clicks'], groupBy: 'day', datePreset: 'today' }), false);
    assert.equal(presetMatchesTrendsState(fullPreset, { metrics: ['cr', 'clicks'], groupBy: 'hour', datePreset: 'last7Days' }), false);

    // Legacy preset without groupBy/datePreset matches on metrics alone
    const legacyPreset = { id: '2', name: 'Old', metrics: ['clicks'] };
    assert.equal(presetMatchesTrendsState(legacyPreset, { metrics: ['clicks'], groupBy: 'hour', datePreset: 'today' }), true);
});

test('storage errors (private mode / quota exceeded) are handled gracefully', () => {
    const throwingStorage = {
        getItem: () => { throw new Error('SecurityError'); },
        setItem: () => { throw new Error('QuotaExceededError'); },
    };
    assert.deepEqual(loadTrendsMetrics({ id: 1 }, throwingStorage), DEFAULT_TRENDS_METRICS);
    assert.doesNotThrow(() => saveTrendsMetrics({ id: 1 }, ['clicks'], throwingStorage));
    assert.equal(loadTrendsGroupBy({ id: 1 }, throwingStorage), DEFAULT_TRENDS_GROUP_BY);
    assert.doesNotThrow(() => saveTrendsGroupBy({ id: 1 }, 'hour', throwingStorage));
    assert.deepEqual(loadTrendsDateRange({ id: 1 }, fakePresetDates('2026-11-28'), throwingStorage), {
        from: '2026-11-22',
        to: '2026-11-28',
        preset: DEFAULT_TRENDS_DATE_PRESET,
    });
    assert.doesNotThrow(() => saveTrendsDateRange({ id: 1 }, { from: '2026-11-28', to: '2026-11-28', preset: 'today' }, fakePresetDates('2026-11-28'), throwingStorage));
    assert.deepEqual(loadTrendsMetricPresets({ id: 1 }, throwingStorage), []);
    assert.doesNotThrow(() => saveTrendsMetricPresets({ id: 1 }, [{ id: '1', name: 'X', metrics: ['clicks'] }], throwingStorage));
});
