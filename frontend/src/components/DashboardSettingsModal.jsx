import React, { useEffect, useRef, useState } from 'react';
import { X, Check, LayoutGrid, Sliders, Eye, Palette } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { RECENT_CLICK_COLUMNS, DEFAULT_CLICK_COLUMNS } from './recentClicksColumns';
import {
    CHART_STYLES,
    COLOR_BLIND_PRESET,
    COLOR_SWATCHES,
    EMPTY_CHART_PREFS,
    isChartMetric,
    metricColorCss,
    metricColorRgb,
    metricDefaultStyle,
    rgbToHex,
    sanitizeChartPrefs,
} from '../utils/chartMetrics';

export const DEFAULT_METRICS = [
    'clicks', 'unique_clicks', 'conversions', 'cost', 'revenue', 'profit', 'roi'
];

const METRIC_PRESETS = {
    general: DEFAULT_METRICS,
    cod: ['leads', 'sales', 'cpl', 'cps', 'cost', 'revenue_confirmed', 'profit_confirmed', 'roi_confirmed', 'approve_rate'],
    media_buying: ['clicks', 'unique_clicks', 'cpv', 'cpc', 'epc', 'cpa', 'cost', 'revenue', 'profit', 'roi', 'cr'],
};

const DashboardSettingsModal = ({ preferences, setPreferences, chartPrefs = null, onSaveChartPrefs = null, onClose, initialSection = null }) => {
    const { t } = useLanguage();
    const [localPrefs, setLocalPrefs] = useState(() => ({
        visible_metrics: preferences?.visible_metrics || DEFAULT_METRICS,
        visible_blocks: preferences?.visible_blocks || ['campaigns', 'offers', 'landings', 'sources'],
        click_columns: preferences?.click_columns || [...DEFAULT_CLICK_COLUMNS]
    }));
    const clickColumnsRef = useRef(null);
    // Chart colours / line styles — edited here, saved to the user's profile.
    const [localChart, setLocalChart] = useState(() => sanitizeChartPrefs(chartPrefs || EMPTY_CHART_PREFS));
    const [openColorFor, setOpenColorFor] = useState(null);

    useEffect(() => {
        if (initialSection === 'click_columns' && clickColumnsRef.current) {
            clickColumnsRef.current.scrollIntoView({ block: 'start' });
        }
    }, [initialSection]);

    const metricGroups = [
        {
            id: 'traffic',
            title: t('dashboard.groupTraffic', 'Traffic & Volume'),
            metrics: [
                { id: 'clicks', label: t('metrics.clicks') },
                { id: 'unique_clicks', label: t('metrics.uniqueClicks') },
                { id: 'real_offer_clicks', label: t('metrics.offerClicks') },
                { id: 'conversions', label: t('metrics.conversions') },
                { id: 'leads', label: t('metrics.leads', 'Leads') },
                { id: 'sales', label: t('metrics.sales', 'Sales') },
                { id: 'bots', label: t('metrics.bots', 'Bots') },
            ]
        },
        {
            id: 'financial',
            title: t('dashboard.groupFinancial', 'Financials & Profit'),
            metrics: [
                { id: 'cost', label: t('metrics.cost') },
                { id: 'revenue', label: t('metrics.revenue') },
                { id: 'profit', label: t('metrics.profit') },
                { id: 'roi', label: t('metrics.roi') },
                { id: 'revenue_confirmed', label: t('metrics.revenueConfirmed', 'Confirmed Revenue') },
                { id: 'profit_confirmed', label: t('metrics.profitConfirmed', 'Confirmed Profit') },
                { id: 'roi_confirmed', label: t('metrics.roiConfirmed', 'Confirmed ROI') },
                { id: 'real_revenue', label: t('metrics.realRevenue') },
                { id: 'real_roi', label: t('metrics.realRoi') },
            ]
        },
        {
            id: 'unit_economics',
            title: t('dashboard.groupUnitEconomics', 'Unit Economics'),
            metrics: [
                { id: 'cpa', label: t('metrics.cpa') },
                { id: 'cpc', label: t('metrics.cpc') },
                { id: 'cpv', label: t('metrics.cpv', 'CPV') },
                { id: 'cpl', label: t('metrics.cpl', 'CPL') },
                { id: 'cps', label: t('metrics.cps', 'CPS') },
                { id: 'epc', label: t('metrics.epc') },
                { id: 'epv', label: t('metrics.epv', 'EPV') },
            ]
        },
        {
            id: 'rates',
            title: t('dashboard.groupRates', 'Rates & Percentages'),
            metrics: [
                { id: 'cr', label: t('metrics.cr', 'CR %') },
                { id: 'approve_rate', label: t('metrics.approveRate', 'Approve %') },
                { id: 'lp_ctr', label: t('metrics.lpCtr', 'LP CTR %') },
                { id: 'bot_rate', label: t('metrics.botRate', 'Bot %') },
                { id: 'ctr', label: t('metrics.ctr') },
            ]
        }
    ];

    const blocksList = [
        { id: 'campaigns', label: t('nav.campaigns') },
        { id: 'offers', label: t('nav.offers') },
        { id: 'landings', label: t('nav.landings') },
        { id: 'sources', label: t('nav.sources') },
    ];

    const columnsList = RECENT_CLICK_COLUMNS.map((col) => ({ id: col.id, label: t(col.labelKey) }));

    const toggleArrayItem = (array, item) => {
        const current = array || [];
        return current.includes(item) ? current.filter(i => i !== item) : [...current, item];
    };

    const updatePreferenceList = (key, id) => {
        setLocalPrefs(prev => ({
            ...prev,
            [key]: toggleArrayItem(prev[key], id)
        }));
    };

    const applyPreset = (presetKey) => {
        setLocalPrefs(prev => ({
            ...prev,
            visible_metrics: [...(METRIC_PRESETS[presetKey] || DEFAULT_METRICS)]
        }));
    };

    const handleSave = () => {
        setPreferences(localPrefs);
        if (onSaveChartPrefs) onSaveChartPrefs(localChart);
        onClose();
    };

    const setMetricColor = (id, color) => {
        setLocalChart(prev => {
            const colors = { ...prev.colors };
            if (color) colors[id] = color.toLowerCase();
            else delete colors[id];
            return { ...prev, colors };
        });
    };

    const setMetricStyle = (id, style) => {
        setLocalChart(prev => {
            const styles = { ...prev.styles };
            if (style && style !== 'auto') styles[id] = style;
            else delete styles[id];
            return { ...prev, styles };
        });
    };

    const styleLabels = {
        solid: t('dashboard.chartStyleSolid', 'Solid'),
        dashed: t('dashboard.chartStyleDashed', 'Dashed'),
        dotted: t('dashboard.chartStyleDotted', 'Dotted'),
        dashdot: t('dashboard.chartStyleDashdot', 'Dash-dot'),
        bars: t('dashboard.chartStyleBars', 'Bars'),
    };

    // Only the metrics switched on above — the ones that have a card to click.
    const appearanceMetrics = metricGroups
        .flatMap(group => group.metrics)
        .filter(metric => isChartMetric(metric.id) && localPrefs.visible_metrics?.includes(metric.id));
    const hasCustomAppearance = Object.keys(localChart.colors).length > 0 || Object.keys(localChart.styles).length > 0;

    const CheckboxItem = ({ checked, onChange, label }) => (
        <label
            onClick={(event) => { event.preventDefault(); onChange(); }}
            className="flex items-center gap-2.5 p-2 rounded-xl cursor-pointer transition select-none border"
            style={{
                borderColor: checked ? 'var(--color-primary)' : 'var(--color-border)',
                backgroundColor: checked
                    ? 'color-mix(in srgb, var(--color-primary) 8%, var(--color-bg-card))'
                    : 'var(--color-bg-card)'
            }}
        >
            <div
                className="w-4 h-4 rounded flex items-center justify-center transition-colors flex-shrink-0"
                style={{
                    backgroundColor: checked ? 'var(--color-primary)' : 'transparent',
                    border: checked ? 'none' : '1px solid var(--color-border)'
                }}
            >
                {checked && <Check size={12} style={{ color: 'var(--color-text-inverse)' }} />}
            </div>
            <span
                title={label}
                className="text-xs font-medium truncate"
                style={{ color: checked ? 'var(--color-text-primary)' : 'var(--color-text-secondary)' }}
            >
                {label}
            </span>
        </label>
    );

    const selectedLabel = t('dashboard.selectedMetrics', '{count} selected')
        .replace('{count}', String(localPrefs.visible_metrics?.length || 0));

    return (
        <div
            className="fixed inset-0 z-[2000] flex items-center justify-center p-4"
            style={{ backgroundColor: 'rgba(0, 0, 0, 0.65)', backdropFilter: 'blur(4px)' }}
        >
            <div
                className="w-full max-w-3xl max-h-[90vh] flex flex-col rounded-2xl shadow-2xl border overflow-hidden"
                style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
            >
                <div
                    className="flex justify-between items-center p-5 border-b gap-4"
                    style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}
                >
                    <div className="flex items-center gap-2.5 min-w-0">
                        <div
                            className="p-2 rounded-xl border flex-shrink-0"
                            style={{ color: 'var(--color-primary)', backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
                        >
                            <Sliders className="w-5 h-5" />
                        </div>
                        <div className="min-w-0">
                            <h2 className="text-base font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                {t('dashboard.dashboardSettings')}
                            </h2>
                            <p className="text-xs truncate" style={{ color: 'var(--color-text-muted)' }}>
                                {t('dashboard.settingsSubtitle', 'Customize metric cards, data blocks, and recent click columns')}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="p-1.5 rounded-lg flex-shrink-0"
                        style={{ color: 'var(--color-text-muted)' }}
                        aria-label={t('common.close', 'Close')}
                    >
                        <X size={20} />
                    </button>
                </div>

                <div className="overflow-y-auto p-6 space-y-6 flex-1">
                    <div
                        className="p-3 rounded-xl border flex flex-wrap items-center justify-between gap-2"
                        style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}
                    >
                        <span className="text-xs font-semibold uppercase" style={{ color: 'var(--color-text-secondary)' }}>
                            {t('dashboard.quickPresets', 'Quick Presets')}:
                        </span>
                        <div className="flex flex-wrap items-center gap-2">
                            <button type="button" onClick={() => applyPreset('general')} className="btn btn-secondary btn-sm">
                                {t('dashboard.presetGeneral', 'General')}
                            </button>
                            <button type="button" onClick={() => applyPreset('cod')} className="btn btn-secondary btn-sm">
                                {t('dashboard.presetCod', 'COD / Nutra')}
                            </button>
                            <button type="button" onClick={() => applyPreset('media_buying')} className="btn btn-secondary btn-sm">
                                {t('dashboard.presetMediaBuying', 'Media Buying')}
                            </button>
                        </div>
                    </div>

                    <section className="space-y-4">
                        <div
                            className="flex items-center justify-between gap-3 border-b pb-1.5"
                            style={{ borderColor: 'var(--color-border)' }}
                        >
                            <h3 className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--color-text-muted)' }}>
                                <LayoutGrid size={14} /> {t('dashboard.metricsCards')}
                            </h3>
                            <span className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>{selectedLabel}</span>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            {metricGroups.map(group => (
                                <div
                                    key={group.id}
                                    className="p-3 rounded-xl border"
                                    style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}
                                >
                                    <div className="text-xs font-semibold mb-2.5" style={{ color: 'var(--color-text-primary)' }}>
                                        {group.title}
                                    </div>
                                    <div className="grid grid-cols-2 gap-1.5">
                                        {group.metrics.map(metric => (
                                            <CheckboxItem
                                                key={metric.id}
                                                label={metric.label}
                                                checked={localPrefs.visible_metrics?.includes(metric.id)}
                                                onChange={() => updatePreferenceList('visible_metrics', metric.id)}
                                            />
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section data-testid="chart-appearance-section">
                        <div
                            className="flex flex-wrap items-center justify-between gap-2 border-b pb-1.5 mb-2"
                            style={{ borderColor: 'var(--color-border)' }}
                        >
                            <h3 className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--color-text-muted)' }}>
                                <Palette size={14} /> {t('dashboard.chartAppearance', 'Chart colours and lines')}
                            </h3>
                            <div className="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    className="btn btn-secondary btn-sm"
                                    data-testid="chart-preset-colorblind"
                                    title={t('dashboard.chartPresetColorBlindHint', 'A palette that stays distinguishable with colour blindness; lines also differ by type')}
                                    onClick={() => { setLocalChart(sanitizeChartPrefs(COLOR_BLIND_PRESET)); setOpenColorFor(null); }}
                                >
                                    {t('dashboard.chartPresetColorBlind', 'Colour-blind friendly')}
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-secondary btn-sm"
                                    data-testid="chart-preset-theme"
                                    disabled={!hasCustomAppearance}
                                    style={{ opacity: hasCustomAppearance ? 1 : 0.5 }}
                                    onClick={() => { setLocalChart({ colors: {}, styles: {} }); setOpenColorFor(null); }}
                                >
                                    {t('dashboard.chartPresetTheme', 'Reset to theme colours')}
                                </button>
                            </div>
                        </div>
                        <p className="text-xs mb-3" style={{ color: 'var(--color-text-secondary)' }}>
                            {t('dashboard.chartAppearanceHint', 'The colour of a line is also the glow of its card. Saved to your profile only — other users keep their own.')}
                        </p>

                        {appearanceMetrics.length === 0 ? (
                            <p className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                {t('dashboard.chartNoMetricsSelected', 'Tick metrics above — their colours will appear here')}
                            </p>
                        ) : (
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                                {appearanceMetrics.map(metric => {
                                    const isOpen = openColorFor === metric.id;
                                    const picked = localChart.colors[metric.id];
                                    const currentCss = metricColorCss(metric.id, localChart);
                                    const defaultStyle = metricDefaultStyle(metric.id);
                                    return (
                                        <div
                                            key={metric.id}
                                            data-appearance-metric={metric.id}
                                            className="rounded-xl border p-2"
                                            style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}
                                        >
                                            <div className="flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => setOpenColorFor(isOpen ? null : metric.id)}
                                                    className="w-6 h-6 rounded-full flex-shrink-0 border"
                                                    style={{
                                                        backgroundColor: currentCss,
                                                        borderColor: 'var(--color-border)',
                                                        boxShadow: isOpen ? `0 0 0 2px var(--color-bg-card), 0 0 0 4px ${currentCss}` : 'none'
                                                    }}
                                                    aria-label={`${t('dashboard.chartPickColor', 'Choose colour')}: ${metric.label}`}
                                                    aria-expanded={isOpen}
                                                    title={t('dashboard.chartPickColor', 'Choose colour')}
                                                />
                                                <span
                                                    title={metric.label}
                                                    className="text-xs font-medium truncate flex-1 min-w-0"
                                                    style={{ color: 'var(--color-text-primary)' }}
                                                >
                                                    {metric.label}
                                                </span>
                                                <select
                                                    value={localChart.styles[metric.id] || 'auto'}
                                                    onChange={(event) => setMetricStyle(metric.id, event.target.value)}
                                                    className="text-xs rounded-lg border px-1.5 py-1 flex-shrink-0"
                                                    style={{
                                                        backgroundColor: 'var(--color-bg-card)',
                                                        borderColor: 'var(--color-border)',
                                                        color: 'var(--color-text-primary)',
                                                        maxWidth: '9.5rem'
                                                    }}
                                                    aria-label={`${t('dashboard.chartLineStyle', 'Line type')}: ${metric.label}`}
                                                >
                                                    <option value="auto">
                                                        {t('dashboard.chartStyleAuto', 'Auto')} ({styleLabels[defaultStyle]})
                                                    </option>
                                                    {CHART_STYLES.map(style => (
                                                        <option key={style} value={style}>{styleLabels[style]}</option>
                                                    ))}
                                                </select>
                                            </div>

                                            {isOpen && (
                                                <div className="flex flex-wrap items-center gap-1.5 mt-2 pt-2 border-t" style={{ borderColor: 'var(--color-border)' }}>
                                                    {COLOR_SWATCHES.map(swatch => (
                                                        <button
                                                            key={swatch}
                                                            type="button"
                                                            data-swatch={swatch}
                                                            onClick={() => setMetricColor(metric.id, swatch)}
                                                            className="w-6 h-6 rounded-full border flex items-center justify-center"
                                                            style={{ backgroundColor: swatch, borderColor: 'var(--color-border)' }}
                                                            aria-label={swatch}
                                                            aria-pressed={picked === swatch}
                                                        >
                                                            {picked === swatch && <Check size={12} style={{ color: '#fff', mixBlendMode: 'difference' }} />}
                                                        </button>
                                                    ))}
                                                    <label
                                                        className="flex items-center gap-1.5 text-xs cursor-pointer ml-1"
                                                        style={{ color: 'var(--color-text-secondary)' }}
                                                    >
                                                        <input
                                                            type="color"
                                                            value={picked || rgbToHex(metricColorRgb(metric.id, localChart))}
                                                            onChange={(event) => setMetricColor(metric.id, event.target.value)}
                                                            className="w-6 h-6 p-0 border-0 bg-transparent cursor-pointer"
                                                            aria-label={`${t('dashboard.chartCustomColor', 'Custom colour')}: ${metric.label}`}
                                                        />
                                                        {t('dashboard.chartCustomColor', 'Custom colour')}
                                                    </label>
                                                    {picked && (
                                                        <button
                                                            type="button"
                                                            onClick={() => setMetricColor(metric.id, null)}
                                                            className="text-xs underline ml-auto"
                                                            style={{ color: 'var(--color-text-secondary)' }}
                                                        >
                                                            {t('dashboard.chartResetColor', 'As in theme')}
                                                        </button>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </section>

                    <section>
                        <h3
                            className="text-xs font-bold uppercase tracking-wider border-b pb-1.5 mb-3"
                            style={{ color: 'var(--color-text-muted)', borderColor: 'var(--color-border)' }}
                        >
                            {t('dashboard.dataBlocks')}
                        </h3>
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            {blocksList.map(block => (
                                <CheckboxItem
                                    key={block.id}
                                    label={block.label}
                                    checked={localPrefs.visible_blocks?.includes(block.id)}
                                    onChange={() => updatePreferenceList('visible_blocks', block.id)}
                                />
                            ))}
                        </div>
                    </section>

                    <section ref={clickColumnsRef} data-testid="click-columns-section">
                        <h3
                            className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider border-b pb-1.5 mb-3"
                            style={{ color: 'var(--color-text-muted)', borderColor: 'var(--color-border)' }}
                        >
                            <Eye size={14} /> {t('dashboard.recentClicksColumns')}
                        </h3>
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            {columnsList.map(column => (
                                <CheckboxItem
                                    key={column.id}
                                    label={column.label}
                                    checked={localPrefs.click_columns?.includes(column.id)}
                                    onChange={() => updatePreferenceList('click_columns', column.id)}
                                />
                            ))}
                        </div>
                    </section>
                </div>

                <div
                    className="p-4 border-t flex justify-end gap-3"
                    style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}
                >
                    <button type="button" onClick={onClose} className="btn btn-secondary btn-sm">
                        {t('common.cancel')}
                    </button>
                    <button type="button" onClick={handleSave} className="btn btn-primary btn-sm">
                        {t('dashboard.saveSettings')}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default DashboardSettingsModal;
