import React, { useState, useEffect, useMemo } from 'react';
import { X, GripVertical, Plus, Trash2, Search, SlidersHorizontal, Layers, Filter as FilterIcon } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

// Exact 64 Keitaro Metrics List
export const ALL_REPORT_METRICS = [
    { id: 'profitability', label: 'Profitability' },
    { id: 'clicks', label: 'Clicks' },
    { id: 'unique_clicks', label: 'Unique clicks (campaign)' },
    { id: 'conversions', label: 'Conversions' },
    { id: 'roi_confirmed', label: 'ROI (confirmed)' },
    { id: 'deposits', label: 'Deposits' },
    { id: 'revenue_deposit', label: 'Revenue (deposit)' },
    { id: 'revenue_registration', label: 'Revenue (registration)' },
    { id: 'revenue', label: 'Revenue' },
    { id: 'profit', label: 'Profit/Loss (all)' },
    { id: 'revenue_hold', label: 'Revenue (hold)' },
    { id: 'revenue_confirmed', label: 'Revenue (confirmed)' },
    { id: 'revenue_rejected', label: 'Revenue (rejected)' },
    { id: 'revenue_trash', label: 'Revenue (trash)' },
    { id: 'cost', label: 'Cost' },
    { id: 'visitors', label: 'Visitors' },
    { id: 'unique_clicks_stream', label: 'Unique clicks (flow)' },
    { id: 'unique_clicks_global', label: 'Unique clicks (global)' },
    { id: 'uc_rate', label: 'Unique clicks % (campaign)' },
    { id: 'uc_rate_stream', label: 'Unique clicks % (flow)' },
    { id: 'uc_rate_global', label: 'Unique clicks % (global)' },
    { id: 'bots', label: 'Bots' },
    { id: 'bot_rate', label: 'Bot %' },
    { id: 'proxies', label: 'Proxies' },
    { id: 'empty_referrers', label: 'Empty referrers' },
    { id: 'leads', label: 'Leads' },
    { id: 'sales', label: 'Sales' },
    { id: 'rejected', label: 'Rejected' },
    { id: 'trash', label: 'Trash' },
    { id: 'approve_rate', label: 'Approve %' },
    { id: 'profit_confirmed', label: 'Profit/Loss (confirmed)' },
    { id: 'cr', label: 'CR — Conversion rate' },
    { id: 'cr_sales', label: 'CR (sales) — Conversion rate' },
    { id: 'cr_deposits', label: 'CR (deposits) — Conversion rate' },
    { id: 'cr_holds', label: 'CR (hold) — Conversion rate' },
    { id: 'cr_registrations', label: 'CR (registrations) — Conversion rate' },
    { id: 'roi', label: 'ROI (all) — Return on investment' },
    { id: 'epc', label: 'EPC (all) — Earnings per click' },
    { id: 'uepc', label: 'uEPC (all) — Earnings per unique click' },
    { id: 'epc_hold', label: 'EPC (hold) — Earnings per click' },
    { id: 'uepc_hold', label: 'uEPC (hold) — Earnings per unique click' },
    { id: 'epc_registration', label: 'EPC (registration) — Earnings per click' },
    { id: 'uepc_registration', label: 'uEPC (registration) — Earnings per unique click' },
    { id: 'epc_confirmed', label: 'EPC (confirmed) — Earnings per click' },
    { id: 'uepc_confirmed', label: 'uEPC (confirmed) — Earnings per unique click' },
    { id: 'cps', label: 'CPS — Cost per sale' },
    { id: 'cpl', label: 'CPL — Cost per lead' },
    { id: 'cpr', label: 'CPR — Cost per registration' },
    { id: 'cpd', label: 'CPD — Cost per deposit' },
    { id: 'cpa', label: 'CPA — Cost per conversion' },
    { id: 'cpc', label: 'CPC — Cost per click' },
    { id: 'ucpc', label: 'uCPC — Cost per unique click' },
    { id: 'ecpc', label: 'eCPC — Cost per 1000 clicks' },
    { id: 'ecpm_all', label: 'eCPM (all) — Profit per 1k clicks' },
    { id: 'ecpm_confirmed', label: 'eCPM (confirmed) — Profit per 1k clicks' },
    { id: 'earnings_per_conv', label: 'EC (all) — Earnings per conversion' },
    { id: 'ec_confirmed', label: 'EC (confirmed) — Earning per conversion' },
    { id: 'registrations', label: 'Registrations' },
    { id: 'ucr', label: 'uCR — Unique clicks to registrations' },
    { id: 'time_since_lp_click', label: 'Time since LP click' },
    { id: 'lp_views', label: 'LP views' },
    { id: 'prelander_clicks', label: 'LP clicks' },
    { id: 'lp_ctr', label: 'LP CTR — LP Click-Through Rate' },
    { id: 'cr_regs_to_deps', label: 'CR (regs to deps)' },
];

export const PRESETS = {
    best: ['profitability', 'clicks', 'unique_clicks', 'conversions', 'roi_confirmed', 'cost', 'revenue', 'profit', 'cr', 'epc', 'cpc', 'cpa'],
    cod: ['clicks', 'unique_clicks', 'conversions', 'sales', 'leads', 'rejected', 'trash', 'approve_rate', 'cost', 'revenue_confirmed', 'profit_confirmed', 'roi_confirmed', 'cpa'],
    lander_to_offer: ['clicks', 'unique_clicks', 'lp_views', 'prelander_clicks', 'lp_ctr', 'conversions', 'cr', 'cost', 'revenue', 'profit', 'roi', 'epc', 'cpc'],
    finance: ['cost', 'revenue', 'revenue_confirmed', 'revenue_hold', 'revenue_rejected', 'profit', 'roi', 'profit_confirmed', 'roi_confirmed', 'cpa', 'epc'],
    all: ALL_REPORT_METRICS.map(m => m.id),
};

const DEFAULT_METRIC_ORDER = ALL_REPORT_METRICS.map(m => m.id);

const ReportCustomizerModal = ({
    isOpen,
    onClose,
    selectedColumns = [],
    onSaveColumns,
    mode = 'report', // 'report' or 'campaigns'
    currentLayers = [],
    onSaveLayers,
    currentFilters = [],
    onSaveFilters
}) => {
    const { t } = useLanguage();
    const [activeTab, setActiveTab] = useState('columns');
    const [searchQuery, setSearchQuery] = useState('');

    // Ordered list of all metric IDs (preserves custom drag reorder position)
    const [orderedMetricIds, setOrderedMetricIds] = useState(() => [...DEFAULT_METRIC_ORDER]);
    // Set of selected metric IDs
    const [selectedSet, setSelectedSet] = useState(() => new Set(PRESETS.best));

    // Layers (Group By)
    const [layers, setLayers] = useState([]);
    // Filters
    const [filters, setFilters] = useState([]);

    // Drag-and-drop state
    const [dragIndex, setDragIndex] = useState(null);

    useEffect(() => {
        if (isOpen) {
            const initialSelected = selectedColumns.length > 0 ? selectedColumns : PRESETS.best;
            setSelectedSet(new Set(initialSelected));

            // Put selected items in their user order, followed by the remaining unselected metrics
            const unselected = DEFAULT_METRIC_ORDER.filter(id => !initialSelected.includes(id));
            const initialOrder = [...initialSelected, ...unselected];
            setOrderedMetricIds(initialOrder);

            if (currentLayers) setLayers([...currentLayers]);
            if (currentFilters) setFilters([...currentFilters]);
            setSearchQuery('');
        }
    }, [isOpen, selectedColumns, currentLayers, currentFilters]);

    if (!isOpen) return null;

    const isAllSelected = orderedMetricIds.every(id => selectedSet.has(id));

    const handleToggleAll = () => {
        if (isAllSelected) {
            setSelectedSet(new Set(['clicks']));
        } else {
            setSelectedSet(new Set(orderedMetricIds));
        }
    };

    const handleToggleMetric = (id) => {
        setSelectedSet(prev => {
            const next = new Set(prev);
            if (next.has(id)) {
                if (next.size > 1) next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    };

    const handleRestoreDefault = () => {
        setSelectedSet(new Set(PRESETS.best));
        setOrderedMetricIds([...DEFAULT_METRIC_ORDER]);
    };

    const handleDragStart = (idx) => {
        setDragIndex(idx);
    };

    const handleDragOver = (e, idx) => {
        e.preventDefault();
        if (dragIndex === null || dragIndex === idx) return;
        const copy = [...orderedMetricIds];
        const item = copy.splice(dragIndex, 1)[0];
        copy.splice(idx, 0, item);
        setDragIndex(idx);
        setOrderedMetricIds(copy);
    };

    const handleDragEnd = () => {
        setDragIndex(null);
    };

    const handleSave = () => {
        // Return only the selected columns in the user's customized drag order
        const result = orderedMetricIds.filter(id => selectedSet.has(id));
        onSaveColumns(result.length > 0 ? result : ['clicks']);

        if (onSaveLayers && mode === 'report') {
            onSaveLayers(layers);
        }
        if (onSaveFilters && mode === 'report') {
            onSaveFilters(filters);
        }
        onClose();
    };

    // Filtered ordered metrics based on search query
    const displayMetrics = useMemo(() => {
        const q = searchQuery.trim().toLowerCase();
        return orderedMetricIds
            .map(id => ALL_REPORT_METRICS.find(m => m.id === id))
            .filter(Boolean)
            .filter(m => !q || m.label.toLowerCase().includes(q) || m.id.toLowerCase().includes(q));
    }, [orderedMetricIds, searchQuery]);

    const handleAddUrlParam = () => {
        const param = window.prompt(t('reportCustomizer.urlParamPrompt', 'Enter URL parameter name (e.g. adset_id, utm_source, custom_id):'));
        if (param) {
            const clean = param.trim().replace(/[^a-zA-Z0-9_\-]/g, '');
            if (clean && !layers.includes(`param_${clean}`)) {
                setLayers([...layers, `param_${clean}`]);
            }
        }
    };

    const handleAddFilter = () => {
        setFilters([...filters, { field: 'country', op: 'eq', value: '' }]);
    };

    const handleRemoveFilter = (index) => {
        setFilters(filters.filter((_, idx) => idx !== index));
    };

    const handleUpdateFilter = (index, key, val) => {
        const copy = [...filters];
        copy[index] = { ...copy[index], [key]: val };
        setFilters(copy);
    };

    return (
        <div className="modal-overlay" style={{ padding: '24px 16px', zIndex: 1200 }}>
            <div
                className="modal-content rounded-2xl shadow-2xl flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-150"
                style={{
                    maxWidth: '540px',
                    width: '100%',
                    maxHeight: '88vh',
                    height: '84vh',
                    padding: 0,
                    backgroundColor: 'var(--color-bg-card)',
                    border: '1px solid var(--color-border)',
                    color: 'var(--color-text-primary)'
                }}
            >
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b" style={{ borderColor: 'var(--color-border)' }}>
                    <h3 className="text-base font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                        {t('reportCustomizer.columnsSelector', 'Columns selector')}
                    </h3>
                    <button
                        onClick={onClose}
                        className="btn-icon p-1 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                        style={{ color: 'var(--color-text-muted)' }}
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Optional Tabs for Reports Mode */}
                {mode === 'report' && (
                    <div className="flex gap-6 px-6 pt-2.5 border-b text-xs font-semibold uppercase tracking-wider" style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}>
                        {[
                            ['columns', t('reportCustomizer.columns', 'Columns'), SlidersHorizontal],
                            ['layers', t('reportCustomizer.groupBy', 'Group By') + ` (${layers.length})`, Layers],
                            ['filters', t('reportCustomizer.filters', 'Filters') + ` (${filters.length})`, FilterIcon]
                        ].map(([tabId, tabLabel, Icon]) => (
                            <button
                                key={tabId}
                                type="button"
                                onClick={() => setActiveTab(tabId)}
                                className="flex items-center gap-1.5 pb-2.5 transition-all"
                                style={{
                                    color: activeTab === tabId ? 'var(--color-primary)' : 'var(--color-text-muted)',
                                    borderBottom: activeTab === tabId ? '2px solid var(--color-primary)' : '2px solid transparent'
                                }}
                            >
                                <Icon className="w-3.5 h-3.5" />
                                <span>{tabLabel}</span>
                            </button>
                        ))}
                    </div>
                )}

                {/* Tab: Columns Selector (Keitaro Exact Replica) */}
                {activeTab === 'columns' && (
                    <div className="flex flex-col flex-1 overflow-hidden p-5">
                        {/* Search Input */}
                        <div className="relative mb-3">
                            <input
                                type="text"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                placeholder="Search..."
                                className="form-input text-xs py-2 px-3 rounded-xl w-full"
                                style={{
                                    backgroundColor: 'var(--color-bg-soft)',
                                    borderColor: 'var(--color-border)',
                                    color: 'var(--color-text-primary)'
                                }}
                            />
                            {searchQuery && (
                                <button
                                    type="button"
                                    onClick={() => setSearchQuery('')}
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-xs"
                                    style={{ color: 'var(--color-text-muted)' }}
                                >
                                    ✕
                                </button>
                            )}
                        </div>

                        {/* Select All Checkbox */}
                        <div className="flex items-center gap-3 px-2 py-2 select-none">
                            <input
                                type="checkbox"
                                id="select_all_cols"
                                checked={isAllSelected}
                                onChange={handleToggleAll}
                                className="w-4 h-4 rounded cursor-pointer"
                                style={{ accentColor: 'var(--color-primary)' }}
                            />
                            <label htmlFor="select_all_cols" className="text-xs font-medium cursor-pointer" style={{ color: 'var(--color-text-primary)' }}>
                                {t('reportCustomizer.selectAll', 'Select All')}
                            </label>
                        </div>

                        <div className="h-[1px] my-1.5" style={{ backgroundColor: 'var(--color-border)' }}></div>

                        {/* Reorderable Columns List */}
                        <div className="flex-1 overflow-y-auto space-y-0.5 pr-1" style={{ scrollbarWidth: 'thin' }}>
                            {displayMetrics.map((metric, idx) => {
                                const isChecked = selectedSet.has(metric.id);
                                return (
                                    <div
                                        key={metric.id}
                                        draggable
                                        onDragStart={() => handleDragStart(idx)}
                                        onDragOver={(e) => handleDragOver(e, idx)}
                                        onDragEnd={handleDragEnd}
                                        onClick={() => handleToggleMetric(metric.id)}
                                        className="flex items-center gap-3 px-2 py-2 rounded-lg text-xs cursor-pointer select-none transition-colors hover:bg-black/5 dark:hover:bg-white/5"
                                        style={{
                                            opacity: dragIndex === idx ? 0.4 : 1
                                        }}
                                    >
                                        <div
                                            className="cursor-grab active:cursor-grabbing p-0.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            <GripVertical className="w-3.5 h-3.5 opacity-60" />
                                        </div>

                                        <input
                                            type="checkbox"
                                            checked={isChecked}
                                            onChange={() => {}} // handled by parent div
                                            className="w-4 h-4 rounded cursor-pointer pointer-events-none"
                                            style={{ accentColor: 'var(--color-primary)' }}
                                        />

                                        <span
                                            className="flex-1 font-normal"
                                            style={{
                                                color: isChecked ? 'var(--color-text-primary)' : 'var(--color-text-secondary)'
                                            }}
                                        >
                                            {metric.label}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                {/* Tab: Group By (Layers) */}
                {activeTab === 'layers' && (
                    <div className="flex-1 overflow-y-auto p-5 space-y-4">
                        <div className="flex items-center justify-between">
                            <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                {t('reportCustomizer.groupByHint', 'Select up to 5 dimensions for multi-level hierarchical breakdown')}
                            </span>
                            <button
                                type="button"
                                onClick={handleAddUrlParam}
                                className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl flex items-center gap-1 font-medium"
                            >
                                <Plus className="w-3 h-3" />
                                {t('reportCustomizer.addUrlParam', '+ URL Param')}
                            </button>
                        </div>

                        <div className="grid grid-cols-2 gap-2">
                            {[
                                'country', 'city', 'region', 'device_type', 'os', 'browser', 'language',
                                'day', 'hour', 'campaign_id', 'source_id', 'stream_id', 'landing_id', 'offer_id',
                                'ad_id', 'adset_id', 'keyword', 'creative_id', 'external_id',
                                'sub_id_1', 'sub_id_2', 'sub_id_3', 'sub_id_4', 'sub_id_5'
                            ].map((dim) => {
                                const isChosen = layers.includes(dim);
                                const layerIndex = layers.indexOf(dim);

                                return (
                                    <button
                                        key={dim}
                                        type="button"
                                        onClick={() => {
                                            if (isChosen) {
                                                setLayers(layers.filter(l => l !== dim));
                                            } else if (layers.length < 5) {
                                                setLayers([...layers, dim]);
                                            }
                                        }}
                                        className="p-2.5 rounded-xl border text-xs text-left flex items-center justify-between transition-all"
                                        style={{
                                            backgroundColor: isChosen ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                                            borderColor: isChosen ? 'var(--color-primary)' : 'var(--color-border)',
                                            color: isChosen ? 'var(--color-primary)' : 'var(--color-text-primary)',
                                            fontWeight: isChosen ? 600 : 400
                                        }}
                                    >
                                        <span className="truncate">{dim}</span>
                                        {isChosen && (
                                            <span className="text-[10px] px-1.5 py-0.2 rounded-full bg-blue-500 text-white font-bold">
                                                {layerIndex + 1}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}

                {/* Tab: Filters */}
                {activeTab === 'filters' && (
                    <div className="flex-1 overflow-y-auto p-5 space-y-3">
                        <div className="flex items-center justify-between">
                            <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                {t('reportCustomizer.filtersHint', 'Apply exact or partial match filters')}
                            </span>
                            <button
                                type="button"
                                onClick={handleAddFilter}
                                className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl flex items-center gap-1 font-medium"
                            >
                                <Plus className="w-3 h-3" />
                                {t('reportCustomizer.addFilter', '+ Add Filter')}
                            </button>
                        </div>

                        {filters.length === 0 && (
                            <div className="text-xs text-center py-10 rounded-xl border border-dashed" style={{ color: 'var(--color-text-muted)', borderColor: 'var(--color-border)' }}>
                                {t('reportCustomizer.noFilters', 'No filters configured.')}
                            </div>
                        )}

                        {filters.map((f, fIdx) => (
                            <div key={fIdx} className="flex items-center gap-2 p-2.5 rounded-xl border" style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
                                <select
                                    value={f.field}
                                    onChange={(e) => handleUpdateFilter(fIdx, 'field', e.target.value)}
                                    className="form-select text-xs py-1.5 rounded-xl w-32"
                                >
                                    <option value="country">Country</option>
                                    <option value="city">City</option>
                                    <option value="device_type">Device Type</option>
                                    <option value="os">OS</option>
                                    <option value="browser">Browser</option>
                                    <option value="sub_id_1">Sub ID 1</option>
                                    <option value="adset_id">AdSet ID</option>
                                    <option value="ad_id">Ad ID</option>
                                    <option value="keyword">Keyword</option>
                                </select>
                                <select
                                    value={f.op}
                                    onChange={(e) => handleUpdateFilter(fIdx, 'op', e.target.value)}
                                    className="form-select text-xs py-1.5 rounded-xl w-28"
                                >
                                    <option value="eq">=</option>
                                    <option value="neq">!=</option>
                                    <option value="contains">contains</option>
                                    <option value="not_contains">not contains</option>
                                </select>
                                <input
                                    type="text"
                                    value={f.value}
                                    onChange={(e) => handleUpdateFilter(fIdx, 'value', e.target.value)}
                                    placeholder="Value..."
                                    className="form-input text-xs py-1.5 px-2.5 rounded-xl flex-1"
                                />
                                <button
                                    type="button"
                                    onClick={() => handleRemoveFilter(fIdx)}
                                    className="btn-icon p-1.5 rounded-lg text-red-500 hover:bg-red-500/10"
                                >
                                    <Trash2 className="w-3.5 h-3.5" />
                                </button>
                            </div>
                        ))}
                    </div>
                )}

                {/* Footer (Restore to default | Cancel | Apply) */}
                <div className="flex items-center justify-between px-6 py-3.5 border-t" style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-card)' }}>
                    <button
                        type="button"
                        onClick={handleRestoreDefault}
                        className="text-xs transition-colors hover:underline"
                        style={{ color: 'var(--color-primary)' }}
                    >
                        {t('reportCustomizer.restoreDefault', 'Restore to default')}
                    </button>

                    <div className="flex items-center gap-2.5">
                        <button
                            type="button"
                            onClick={onClose}
                            className="btn btn-secondary text-xs py-2 px-4 rounded-xl font-medium"
                            style={{
                                backgroundColor: 'var(--color-bg-soft)',
                                borderColor: 'var(--color-border)',
                                color: 'var(--color-text-primary)'
                            }}
                        >
                            {t('common.cancel', 'Cancel')}
                        </button>
                        <button
                            type="button"
                            onClick={handleSave}
                            className="btn btn-primary text-xs py-2 px-5 rounded-xl font-medium"
                        >
                            {t('reportCustomizer.apply', 'Apply')}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default ReportCustomizerModal;
