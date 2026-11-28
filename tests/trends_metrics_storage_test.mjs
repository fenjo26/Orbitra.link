import test from 'node:test';
import assert from 'node:assert/strict';
import {
    DEFAULT_TRENDS_METRICS,
    TRENDS_METRIC_KEYS,
    MAX_TRENDS_METRIC_PRESETS,
    getTrendsMetricsStorageKey,
    getTrendsPresetsStorageKey,
    sanitizeTrendsMetrics,
    loadTrendsMetrics,
    saveTrendsMetrics,
    sanitizeTrendsMetricPresets,
    loadTrendsMetricPresets,
    saveTrendsMetricPresets,
    upsertTrendsMetricPreset,
    deleteTrendsMetricPreset,
    metricsMatchPreset,
} from '../frontend/src/utils/trendsMetrics.js';

const createMemoryStorage = (initial = {}) => {
    const map = new Map(Object.entries(initial));
    return {
        getItem: (k) => (map.has(k) ? map.get(k) : null),
        setItem: (k, v) => { map.set(k, String(v)); },
        removeItem: (k) => { map.delete(k); },
    };
};

test('storage keys scope by user id, fall back to username, then shared key', () => {
    assert.equal(getTrendsMetricsStorageKey({ id: 42, username: 'ruslan' }), 'orbitra_trends_metrics_42');
    assert.equal(getTrendsMetricsStorageKey({ username: 'ruslan' }), 'orbitra_trends_metrics_ruslan');
    assert.equal(getTrendsMetricsStorageKey(null), 'orbitra_trends_metrics');
    assert.equal(getTrendsMetricsStorageKey({}), 'orbitra_trends_metrics');

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

test('sanitizeTrendsMetrics deduplicates, filters invalid keys, and accepts every supported metric', () => {
    assert.deepEqual(
        sanitizeTrendsMetrics(['unique_clicks', 'bogus', 'unique_clicks', 123, 'cpa']),
        ['unique_clicks', 'cpa'],
    );
    assert.deepEqual(sanitizeTrendsMetrics(TRENDS_METRIC_KEYS), TRENDS_METRIC_KEYS);
});

test('presets upsert creates, updates case-insensitively in place, deletes, and persists per user', () => {
    const storage = createMemoryStorage();
    const user = { id: 9 };
    let counter = 0;
    const nextId = () => `p_${++counter}`;

    let presets = loadTrendsMetricPresets(user, storage);
    assert.deepEqual(presets, []);

    presets = upsertTrendsMetricPreset(presets, '  Трафик  ', ['clicks', 'unique_clicks'], nextId);
    presets = upsertTrendsMetricPreset(presets, 'Финансы', ['revenue', 'cost', 'profit', 'roi'], nextId);
    assert.deepEqual(presets, [
        { id: 'p_1', name: 'Трафик', metrics: ['clicks', 'unique_clicks'] },
        { id: 'p_2', name: 'Финансы', metrics: ['revenue', 'cost', 'profit', 'roi'] },
    ]);

    // Case-insensitive update preserves position & id, updates metrics and casing.
    presets = upsertTrendsMetricPreset(presets, 'трафик', ['unique_clicks', 'ctr'], nextId);
    assert.deepEqual(presets, [
        { id: 'p_1', name: 'трафик', metrics: ['unique_clicks', 'ctr'] },
        { id: 'p_2', name: 'Финансы', metrics: ['revenue', 'cost', 'profit', 'roi'] },
    ]);

    saveTrendsMetricPresets(user, presets, storage);
    assert.deepEqual(loadTrendsMetricPresets(user, storage), presets);
    assert.deepEqual(loadTrendsMetricPresets({ id: 10 }, storage), []);

    presets = deleteTrendsMetricPreset(presets, 'p_1');
    assert.deepEqual(presets, [
        { id: 'p_2', name: 'Финансы', metrics: ['revenue', 'cost', 'profit', 'roi'] },
    ]);
});

test('sanitizeTrendsMetricPresets drops corrupt entries and enforces cap', () => {
    const dirty = [
        null,
        { id: 'a', name: '   ', metrics: ['clicks'] },
        { id: 'b', name: 'Empty', metrics: ['unknown_only'] },
        { id: 'c', name: 'Good', metrics: ['clicks', 'unknown', 'clicks', 'cr'] },
        { id: 'c', name: 'DupId', metrics: ['roi'] },
    ];
    assert.deepEqual(sanitizeTrendsMetricPresets(dirty), [
        { id: 'c', name: 'Good', metrics: ['clicks', 'cr'] },
    ]);

    let many = [];
    for (let i = 1; i <= MAX_TRENDS_METRIC_PRESETS + 5; i += 1) {
        many = upsertTrendsMetricPreset(many, `Preset ${i}`, ['clicks'], () => `id_${i}`);
    }
    assert.equal(many.length, MAX_TRENDS_METRIC_PRESETS);
    assert.equal(many[0].name, 'Preset 6');
    assert.equal(many[many.length - 1].name, `Preset ${MAX_TRENDS_METRIC_PRESETS + 5}`);
});

test('metricsMatchPreset compares metric sets regardless of order', () => {
    assert.equal(metricsMatchPreset(['clicks', 'cr'], ['cr', 'clicks']), true);
    assert.equal(metricsMatchPreset(['clicks'], ['clicks', 'cr']), false);
    assert.equal(metricsMatchPreset([], []), false);
});

test('storage errors (private mode / quota exceeded) are handled gracefully', () => {
    const throwingStorage = {
        getItem: () => { throw new Error('SecurityError'); },
        setItem: () => { throw new Error('QuotaExceededError'); },
    };
    assert.deepEqual(loadTrendsMetrics({ id: 1 }, throwingStorage), DEFAULT_TRENDS_METRICS);
    assert.doesNotThrow(() => saveTrendsMetrics({ id: 1 }, ['clicks'], throwingStorage));
    assert.deepEqual(loadTrendsMetricPresets({ id: 1 }, throwingStorage), []);
    assert.doesNotThrow(() => saveTrendsMetricPresets({ id: 1 }, [{ id: '1', name: 'X', metrics: ['clicks'] }], throwingStorage));
});
