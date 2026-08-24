import React, { useState } from 'react';
import { X, Check, LayoutGrid, Sliders, Eye } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const DEFAULT_METRICS = [
    'clicks', 'unique_clicks', 'conversions', 'cost', 'revenue', 'profit', 'roi', 'cpc', 'cpa'
];

const METRIC_PRESETS = {
    general: DEFAULT_METRICS,
    cod: ['leads', 'sales', 'cpl', 'cps', 'cost', 'revenue_confirmed', 'profit_confirmed', 'roi_confirmed', 'approve_rate'],
    media_buying: ['clicks', 'unique_clicks', 'cpv', 'cpc', 'epc', 'cpa', 'cost', 'revenue', 'profit', 'roi', 'cr'],
};

const DashboardSettingsModal = ({ preferences, setPreferences, onClose }) => {
    const { t } = useLanguage();
    const [localPrefs, setLocalPrefs] = useState(() => ({
        visible_metrics: preferences?.visible_metrics || DEFAULT_METRICS,
        visible_blocks: preferences?.visible_blocks || ['campaigns', 'offers', 'landings', 'sources'],
        click_columns: preferences?.click_columns || ['created_at', 'campaign_name', 'country_code', 'ip', 'device_type', 'user_agent', 'redirect_url']
    }));

    const metricGroups = [
        {
            id: 'traffic',
            title: t('dashboard.groupTraffic', 'Traffic & Volume'),
            metrics: [
                { id: 'clicks', label: t('metrics.clicks') },
                { id: 'unique_clicks', label: t('metrics.uniqueClicks') },
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

    const columnsList = [
        { id: 'created_at', label: t('dashboard.colDate') },
        { id: 'campaign_name', label: t('dashboard.colCampaign') },
        { id: 'country_code', label: t('dashboard.colGeo') },
        { id: 'device_type', label: t('dashboard.colOs') },
        { id: 'ip', label: t('dashboard.colIp') },
        { id: 'user_agent', label: t('dashboard.colUa') },
        { id: 'redirect_url', label: t('dashboard.colUrl') },
    ];

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
        onClose();
    };

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

                    <section>
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
