import React, { useState, useEffect } from 'react';
import { X, Check, GripVertical, Plus, Trash2, SlidersHorizontal, Search, ArrowUp, ArrowDown } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

export const ALL_REPORT_METRICS = [
    // Traffic
    { id: 'clicks', labelKey: 'reportCustomizer.colClicks', defaultLabel: 'Clicks', category: 'catTraffic' },
    { id: 'unique_clicks', labelKey: 'reportCustomizer.colUniqueClicks', defaultLabel: 'Unique clicks', category: 'catTraffic' },
    { id: 'uc_rate', labelKey: 'reportCustomizer.colUcRate', defaultLabel: 'U/C (%)', category: 'catTraffic' },
    { id: 'prelander_clicks', labelKey: 'reportCustomizer.colPrelanderClicks', defaultLabel: 'Prelander clicks', category: 'catTraffic' },
    { id: 'offer_clicks', labelKey: 'reportCustomizer.colOfferClicks', defaultLabel: 'Offer clicks', category: 'catTraffic' },
    { id: 'lp_ctr', labelKey: 'reportCustomizer.colLpCtr', defaultLabel: 'LP CTR (%)', category: 'catTraffic' },

    // Conversions & Statuses
    { id: 'conversions', labelKey: 'reportCustomizer.colConversions', defaultLabel: 'Conversions (CV)', category: 'catConversions' },
    { id: 'purchases', labelKey: 'reportCustomizer.colPurchases', defaultLabel: 'Purchases (Sales)', category: 'catConversions' },
    { id: 'holds', labelKey: 'reportCustomizer.colHolds', defaultLabel: 'Holds (Leads)', category: 'catConversions' },
    { id: 'rejected', labelKey: 'reportCustomizer.colRejected', defaultLabel: 'Rejected', category: 'catConversions' },
    { id: 'trash', labelKey: 'reportCustomizer.colTrash', defaultLabel: 'Trash', category: 'catConversions' },
    { id: 'cr', labelKey: 'reportCustomizer.colCrAll', defaultLabel: 'CR (all) (%)', category: 'catConversions' },
    { id: 'cr_sales', labelKey: 'reportCustomizer.colCrSales', defaultLabel: 'CR (sales) (%)', category: 'catConversions' },
    { id: 'cr_holds', labelKey: 'reportCustomizer.colCrHolds', defaultLabel: 'CR (holds) (%)', category: 'catConversions' },
    { id: 'approve_rate', labelKey: 'reportCustomizer.colApproveRate', defaultLabel: 'Approve rate (%)', category: 'catConversions' },
    { id: 'approve_rate_excl_trash', labelKey: 'reportCustomizer.colApproveRateExclTrash', defaultLabel: 'Approve (excl. trash) (%)', category: 'catConversions' },

    // Financial
    { id: 'cost', labelKey: 'reportCustomizer.colCost', defaultLabel: 'Cost', category: 'catFinancial' },
    { id: 'revenue', labelKey: 'reportCustomizer.colRevenue', defaultLabel: 'Revenue', category: 'catFinancial' },
    { id: 'revenue_confirmed', labelKey: 'reportCustomizer.colRevenueConfirmed', defaultLabel: 'Revenue (confirmed)', category: 'catFinancial' },
    { id: 'revenue_hold', labelKey: 'reportCustomizer.colRevenueHold', defaultLabel: 'Revenue (hold)', category: 'catFinancial' },
    { id: 'revenue_rejected', labelKey: 'reportCustomizer.colRevenueRejected', defaultLabel: 'Revenue (rejected)', category: 'catFinancial' },
    { id: 'revenue_trash', labelKey: 'reportCustomizer.colRevenueTrash', defaultLabel: 'Revenue (trash)', category: 'catFinancial' },
    { id: 'profit', labelKey: 'reportCustomizer.colProfit', defaultLabel: 'Profit', category: 'catFinancial' },
    { id: 'roi', labelKey: 'reportCustomizer.colRoi', defaultLabel: 'ROI (%)', category: 'catFinancial' },
    { id: 'real_revenue', labelKey: 'reportCustomizer.colRealRevenue', defaultLabel: 'Real Revenue', category: 'catFinancial' },
    { id: 'real_profit', labelKey: 'reportCustomizer.colRealProfit', defaultLabel: 'Real Profit', category: 'catFinancial' },
    { id: 'real_roi', labelKey: 'reportCustomizer.colRealRoi', defaultLabel: 'Real ROI (%)', category: 'catFinancial' },

    // Rates & Unit Economics
    { id: 'epc', labelKey: 'reportCustomizer.colEpc', defaultLabel: 'EPC', category: 'catRates' },
    { id: 'uepc', labelKey: 'reportCustomizer.colUepc', defaultLabel: 'uEPC', category: 'catRates' },
    { id: 'cpc', labelKey: 'reportCustomizer.colCpc', defaultLabel: 'CPC', category: 'catRates' },
    { id: 'ucpc', labelKey: 'reportCustomizer.colUcpc', defaultLabel: 'uCPC', category: 'catRates' },
    { id: 'cpa', labelKey: 'reportCustomizer.colCpa', defaultLabel: 'CPA', category: 'catRates' },
    { id: 'earnings_per_conv', labelKey: 'reportCustomizer.colEarningsPerConv', defaultLabel: 'Earnings / Conv', category: 'catRates' },
];

export const PRESETS = {
    best: ['clicks', 'unique_clicks', 'conversions', 'cr', 'cost', 'revenue', 'profit', 'roi', 'cpc', 'cpa', 'epc'],
    cod: ['clicks', 'unique_clicks', 'conversions', 'purchases', 'holds', 'rejected', 'trash', 'approve_rate', 'cost', 'revenue_confirmed', 'profit', 'roi', 'cpa'],
    lander_to_offer: ['clicks', 'unique_clicks', 'prelander_clicks', 'offer_clicks', 'lp_ctr', 'conversions', 'cr', 'cost', 'revenue', 'profit', 'roi', 'epc', 'cpc'],
    finance: ['cost', 'revenue', 'revenue_confirmed', 'revenue_hold', 'revenue_rejected', 'profit', 'roi', 'real_revenue', 'real_profit', 'real_roi', 'cpa', 'epc'],
    all: ALL_REPORT_METRICS.map(m => m.id),
};

const ReportCustomizerModal = ({
    isOpen,
    onClose,
    selectedColumns = [],
    onSaveColumns,
    mode = 'report', // 'report' or 'campaigns'
    availableDimensions = [],
    currentLayers = [],
    onSaveLayers,
    currentFilters = [],
    onSaveFilters
}) => {
    const { t } = useLanguage();
    const [activeTab, setActiveTab] = useState('columns'); // 'columns', 'layers', 'filters'
    const [chosenColumns, setChosenColumns] = useState([]);
    const [searchQuery, setSearchQuery] = useState('');
    const [activePreset, setActivePreset] = useState('custom');

    // Layers (Group By)
    const [layers, setLayers] = useState([]);
    // Filters
    const [filters, setFilters] = useState([]);

    // Drag-and-drop state
    const [dragIndex, setDragIndex] = useState(null);

    useEffect(() => {
        if (isOpen) {
            setChosenColumns(selectedColumns.length > 0 ? [...selectedColumns] : [...PRESETS.best]);
            if (currentLayers) setLayers([...currentLayers]);
            if (currentFilters) setFilters([...currentFilters]);
        }
    }, [isOpen, selectedColumns, currentLayers, currentFilters]);

    if (!isOpen) return null;

    const handleApplyPreset = (presetKey) => {
        setActivePreset(presetKey);
        if (PRESETS[presetKey]) {
            setChosenColumns([...PRESETS[presetKey]]);
        }
    };

    const handleToggleColumn = (colId) => {
        setActivePreset('custom');
        if (chosenColumns.includes(colId)) {
            setChosenColumns(chosenColumns.filter(c => c !== colId));
        } else {
            setChosenColumns([...chosenColumns, colId]);
        }
    };

    const handleSelectAll = () => {
        setActivePreset('all');
        setChosenColumns(ALL_REPORT_METRICS.map(m => m.id));
    };

    const handleDeselectAll = () => {
        setActivePreset('custom');
        setChosenColumns(['clicks']);
    };

    const handleMoveColumn = (index, direction) => {
        const newIndex = index + direction;
        if (newIndex < 0 || newIndex >= chosenColumns.length) return;
        const copy = [...chosenColumns];
        const item = copy.splice(index, 1)[0];
        copy.splice(newIndex, 0, item);
        setChosenColumns(copy);
    };

    // Drag and Drop inside columns reorder list
    const handleDragStart = (idx) => {
        setDragIndex(idx);
    };

    const handleDragOver = (e, idx) => {
        e.preventDefault();
        if (dragIndex === null || dragIndex === idx) return;
        const copy = [...chosenColumns];
        const item = copy.splice(dragIndex, 1)[0];
        copy.splice(idx, 0, item);
        setDragIndex(idx);
        setChosenColumns(copy);
    };

    const handleDragEnd = () => {
        setDragIndex(null);
    };

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

    const handleSave = () => {
        onSaveColumns(chosenColumns);
        if (onSaveLayers && mode === 'report') {
            onSaveLayers(layers);
        }
        if (onSaveFilters && mode === 'report') {
            onSaveFilters(filters);
        }
        onClose();
    };

    // Filtered metric list for checkbox grid
    const filteredMetrics = ALL_REPORT_METRICS.filter(m => {
        const name = t(m.labelKey, m.defaultLabel).toLowerCase();
        return name.includes(searchQuery.toLowerCase()) || m.id.toLowerCase().includes(searchQuery.toLowerCase());
    });

    const categories = [
        { id: 'catTraffic', label: t('reportCustomizer.catTraffic', 'Traffic') },
        { id: 'catConversions', label: t('reportCustomizer.catConversions', 'Conversions & Statuses') },
        { id: 'catFinancial', label: t('reportCustomizer.catFinancial', 'Financial') },
        { id: 'catRates', label: t('reportCustomizer.catRates', 'Rates & Unit Economics') },
    ];

    // Human labels for the group-by chips — raw keys like device_type used to
    // render straight into the UI.
    const DIM_LABELS = {
        country: t('campaignReports.geoCountry'), city: t('reportCustomizer.dimCity', 'City'),
        region: t('reportCustomizer.dimRegion', 'Region'), device_type: t('campaignReports.deviceType'),
        os: 'OS', browser: t('campaignReports.browser', 'Browser'), language: t('campaignReports.language'),
        day: t('campaignReports.day', 'Day'), hour: t('reportCustomizer.dimHour', 'Hour'),
        campaign_id: t('campaignReports.campaign'), source_id: t('campaignReports.source'),
        stream_id: t('campaignReports.stream'), landing_id: t('campaignReports.landing', 'Landing'),
        offer_id: t('campaignReports.offer', 'Offer'), ad_id: t('campaignReports.adId', 'Ad ID'),
        adset_id: t('campaignReports.adsetId', 'Adset ID'), keyword: t('parameters.keyword'),
        creative_id: t('reportCustomizer.dimCreative', 'Creative'), external_id: t('reportCustomizer.dimExternal', 'External ID'),
        sub_id_1: 'Sub ID 1', sub_id_2: 'Sub ID 2', sub_id_3: 'Sub ID 3', sub_id_4: 'Sub ID 4', sub_id_5: 'Sub ID 5',
    };

    return (
        <div className="modal-overlay">
            <div
                className="modal-content max-w-4xl w-full rounded-2xl shadow-2xl flex flex-col max-h-[90vh] overflow-hidden animate-in fade-in zoom-in-95 duration-150"
                style={{
                    backgroundColor: 'var(--color-bg-card)',
                    border: '1px solid var(--color-border)',
                    color: 'var(--color-text-primary)'
                }}
            >
                {/* Modal Header */}
                <div className="modal-header flex items-center justify-between p-4" style={{ borderBottom: '1px solid var(--color-border)' }}>
                    <div className="flex items-center gap-2">
                        <SlidersHorizontal className="w-5 h-5" style={{ color: 'var(--color-primary)' }} />
                        <h2 className="text-base font-bold">
                            {mode === 'campaigns' ? t('reportCustomizer.campaignColumnsTitle') : t('reportCustomizer.title')}
                        </h2>
                    </div>
                    <button onClick={onClose} className="btn-icon">
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Navigation Tabs (if report mode) */}
                {mode === 'report' && (
                    <div className="flex gap-5 px-6 pt-3" style={{ borderBottom: '1px solid var(--color-border)' }}>
                        {[['columns', t('reportCustomizer.columns') + ' (' + chosenColumns.length + ')'],
                          ['layers', t('reportCustomizer.groupBy') + ' (' + layers.length + ')'],
                          ['filters', t('reportCustomizer.filters') + ' (' + filters.length + ')']].map(([tabId, tabLabel]) => (
                            <button
                                key={tabId}
                                type="button"
                                onClick={() => setActiveTab(tabId)}
                                className="pb-2 text-xs font-semibold uppercase tracking-wider transition-colors"
                                style={{
                                    color: activeTab === tabId ? 'var(--color-primary)' : 'var(--color-text-muted)',
                                    borderBottom: activeTab === tabId ? '2px solid var(--color-primary)' : '2px solid transparent'
                                }}
                            >
                                {tabLabel}
                            </button>
                        ))}
                    </div>
                )}

                {/* Modal Body */}
                <div className="flex-1 overflow-y-auto p-6">
                    {activeTab === 'columns' && (
                        <div className="flex flex-col gap-6">
                            {/* Presets Bar */}
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-xs font-semibold uppercase mr-1" style={{ color: 'var(--color-text-muted)' }}>
                                    {t('reportCustomizer.presets')}:
                                </span>
                                {[
                                    ['best', t('reportCustomizer.presetBest')],
                                    ['cod', t('reportCustomizer.presetCod')],
                                    ['lander_to_offer', t('reportCustomizer.presetLanderToOffer')],
                                    ['finance', t('reportCustomizer.presetFinance')],
                                    ['all', t('reportCustomizer.presetAll')],
                                ].map(([pKey, pLabel]) => (
                                    <button
                                        key={pKey}
                                        type="button"
                                        onClick={() => handleApplyPreset(pKey)}
                                        className="text-xs px-3 py-1.5 rounded-lg border transition-all"
                                        style={{
                                            backgroundColor: activePreset === pKey ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                                            borderColor: activePreset === pKey ? 'var(--color-primary)' : 'var(--color-border)',
                                            color: activePreset === pKey ? 'var(--color-primary)' : 'var(--color-text-primary)',
                                            fontWeight: activePreset === pKey ? 600 : 400
                                        }}
                                    >
                                        {pLabel}
                                    </button>
                                ))}
                            </div>

                            {/* Search and Quick Actions */}
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="relative flex-1 max-w-sm">
                                    <Search className="w-4 h-4 absolute left-3 top-2.5" style={{ color: 'var(--color-text-muted)' }} />
                                    <input
                                        type="text"
                                        value={searchQuery}
                                        onChange={(e) => setSearchQuery(e.target.value)}
                                        placeholder={t('reportCustomizer.searchMetrics')}
                                        className="form-input text-xs pl-9 py-1.5 rounded-xl w-full"
                                    />
                                </div>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={handleSelectAll}
                                        className="btn btn-ghost text-xs py-1 px-2.5 rounded-lg"
                                    >
                                        {t('reportCustomizer.selectAll')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={handleDeselectAll}
                                        className="btn btn-ghost text-xs py-1 px-2.5 rounded-lg"
                                        style={{ color: 'var(--color-danger)' }}
                                    >
                                        {t('reportCustomizer.deselectAll')}
                                    </button>
                                </div>
                            </div>

                            {/* Two-Pane Layout: Left: Category Selection Grid | Right: Chosen Columns Order */}
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                                {/* Left (2 cols): Categorized Checkbox List */}
                                <div className="md:col-span-2 space-y-6">
                                    {categories.map((cat) => {
                                        const catMetrics = filteredMetrics.filter(m => m.category === cat.id);
                                        if (catMetrics.length === 0) return null;

                                        return (
                                            <div key={cat.id} className="space-y-2">
                                                <h4 className="text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--color-text-muted)' }}>
                                                    {cat.label}
                                                </h4>
                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                                    {catMetrics.map((m) => {
                                                        const isChecked = chosenColumns.includes(m.id);
                                                        return (
                                                            <label
                                                                key={m.id}
                                                                className="flex items-center gap-2.5 p-2.5 rounded-xl border cursor-pointer select-none transition-all"
                                                                style={{
                                                                    backgroundColor: isChecked ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                                                                    borderColor: isChecked ? 'var(--color-primary)' : 'var(--color-border)',
                                                                    color: 'var(--color-text-primary)',
                                                                    minHeight: '40px'
                                                                }}
                                                            >
                                                                <input
                                                                    type="checkbox"
                                                                    checked={isChecked}
                                                                    onChange={() => handleToggleColumn(m.id)}
                                                                    className="w-4 h-4 rounded"
                                                                    style={{ accentColor: 'var(--color-primary)' }}
                                                                />
                                                                <span className="text-xs font-medium">
                                                                    {t(m.labelKey, m.defaultLabel)}
                                                                </span>
                                                            </label>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>

                                {/* Right (1 col): Selected Columns Order & Drag List */}
                                <div
                                    className="p-3 rounded-2xl flex flex-col gap-2"
                                    style={{
                                        backgroundColor: 'var(--color-bg-soft)',
                                        border: '1px solid var(--color-border)'
                                    }}
                                >
                                    <div className="flex items-center justify-between pb-2" style={{ borderBottom: '1px solid var(--color-border)' }}>
                                        <span className="text-xs font-bold uppercase" style={{ color: 'var(--color-text-primary)' }}>
                                            {t('reportCustomizer.selected')} ({chosenColumns.length})
                                        </span>
                                        <span className="text-[10px]" style={{ color: 'var(--color-text-muted)' }}>
                                            {t('reportCustomizer.dragReorderHint')}
                                        </span>
                                    </div>

                                    <div className="flex-1 overflow-y-auto space-y-1.5 max-h-[360px] pr-1">
                                        {chosenColumns.map((cId, idx) => {
                                            const def = ALL_REPORT_METRICS.find(m => m.id === cId);
                                            const label = def ? t(def.labelKey, def.defaultLabel) : cId;

                                            return (
                                                <div
                                                    key={cId}
                                                    draggable
                                                    onDragStart={() => handleDragStart(idx)}
                                                    onDragOver={(e) => handleDragOver(e, idx)}
                                                    onDragEnd={handleDragEnd}
                                                    className="flex items-center justify-between p-2 rounded-lg text-xs transition-colors cursor-grab active:cursor-grabbing"
                                                    style={{
                                                        backgroundColor: 'var(--color-bg-card)',
                                                        border: '1px solid var(--color-border)',
                                                        opacity: dragIndex === idx ? 0.5 : 1
                                                    }}
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <GripVertical className="w-3.5 h-3.5" style={{ color: 'var(--color-text-muted)' }} />
                                                        <span className="font-medium">{label}</span>
                                                    </div>
                                                    <div className="flex items-center gap-1">
                                                        <button
                                                            type="button"
                                                            disabled={idx === 0}
                                                            onClick={() => handleMoveColumn(idx, -1)}
                                                            className="p-1 rounded disabled:opacity-30"
                                                            style={{ color: 'var(--color-text-secondary)' }}
                                                        >
                                                            <ArrowUp className="w-3 h-3" />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={idx === chosenColumns.length - 1}
                                                            onClick={() => handleMoveColumn(idx, 1)}
                                                            className="p-1 rounded disabled:opacity-30"
                                                            style={{ color: 'var(--color-text-secondary)' }}
                                                        >
                                                            <ArrowDown className="w-3 h-3" />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => handleToggleColumn(cId)}
                                                            className="p-1 rounded ml-1"
                                                            style={{ color: 'var(--color-danger)' }}
                                                        >
                                                            <X className="w-3 h-3" />
                                                        </button>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Group By (Layers) Tab */}
                    {activeTab === 'layers' && (
                        <div className="space-y-4">
                            <div className="flex items-center justify-between">
                                <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                    {t('reportCustomizer.groupByHint', 'Select up to 5 dimensions for multi-level hierarchical breakdown')}
                                </span>
                                <button
                                    type="button"
                                    onClick={handleAddUrlParam}
                                    className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5"
                                >
                                    <Plus className="w-3.5 h-3.5" />
                                    {t('reportCustomizer.addUrlParam')}
                                </button>
                            </div>

                            <div className="grid grid-cols-2 md:grid-cols-3 gap-2">
                                {[
                                    'country', 'city', 'region', 'device_type', 'os', 'browser', 'language',
                                    'day', 'hour', 'campaign_id', 'source_id', 'stream_id', 'landing_id', 'offer_id',
                                    'ad_id', 'adset_id', 'keyword', 'creative_id', 'external_id',
                                    'sub_id_1', 'sub_id_2', 'sub_id_3', 'sub_id_4', 'sub_id_5'
                                ].map((dim) => {
                                    const isChosen = layers.includes(dim);
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
                                                color: isChosen ? 'var(--color-primary)' : 'var(--color-text-primary)'
                                            }}
                                        >
                                            <span className="font-medium">{DIM_LABELS[dim] || dim}</span>
                                            {isChosen && (
                                                <span className="text-[10px] min-w-[18px] text-center px-1.5 py-0.5 rounded-full font-bold"
                                                    style={{ backgroundColor: 'var(--color-primary)', color: 'var(--color-bg-card)' }}>
                                                    {layers.indexOf(dim) + 1}
                                                </span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {/* Filters Tab */}
                    {activeTab === 'filters' && (
                        <div className="space-y-4">
                            <div className="flex items-center justify-between">
                                <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                    {t('reportCustomizer.filtersHint', 'Apply exact or partial match filters')}
                                </span>
                                <button
                                    type="button"
                                    onClick={handleAddFilter}
                                    className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5"
                                >
                                    <Plus className="w-3.5 h-3.5" />
                                    {t('reportCustomizer.addFilter', '+ Add Filter')}
                                </button>
                            </div>

                            {filters.length === 0 && (
                                <div className="text-xs text-center py-8" style={{ color: 'var(--color-text-muted)' }}>
                                    {t('reportCustomizer.noFilters', 'No filters configured. Click "+ Add Filter" to add a condition.')}
                                </div>
                            )}

                            {filters.map((f, fIdx) => (
                                <div key={fIdx} className="flex flex-wrap items-center gap-2">
                                    <select
                                        value={f.field}
                                        onChange={(e) => handleUpdateFilter(fIdx, 'field', e.target.value)}
                                        className="form-select text-xs py-1.5 rounded-xl"
                                        style={{ minWidth: '150px', flex: '0 1 auto' }}
                                    >
                                        <option value="country">{t('campaignReports.geoCountry')}</option>
                                        <option value="city">{t('reportCustomizer.fCity', 'City')}</option>
                                        <option value="device_type">{t('campaignReports.deviceType')}</option>
                                        <option value="os">OS</option>
                                        <option value="browser">{t('campaignReports.browser', 'Browser')}</option>
                                        <option value="sub_id_1">Sub ID 1</option>
                                        <option value="sub_id_2">Sub ID 2</option>
                                        <option value="adset_id">{t('campaignReports.adsetId', 'Adset ID')}</option>
                                        <option value="ad_id">{t('campaignReports.adId', 'Ad ID')}</option>
                                        <option value="keyword">{t('parameters.keyword')}</option>
                                    </select>
                                    <select
                                        value={f.op}
                                        onChange={(e) => handleUpdateFilter(fIdx, 'op', e.target.value)}
                                        className="form-select text-xs py-1.5 rounded-xl"
                                        style={{ minWidth: '140px', flex: '0 1 auto' }}
                                    >
                                        <option value="eq">{t('reportCustomizer.opEq', 'Equal (=)')}</option>
                                        <option value="neq">{t('reportCustomizer.opNeq', 'Not equal (!=)')}</option>
                                        <option value="contains">{t('reportCustomizer.opContains', 'Contains')}</option>
                                        <option value="not_contains">{t('reportCustomizer.opNotContains', 'Not contains')}</option>
                                    </select>
                                    <input
                                        type="text"
                                        value={f.value}
                                        onChange={(e) => handleUpdateFilter(fIdx, 'value', e.target.value)}
                                        placeholder={t('reportCustomizer.fValue', 'Value...')}
                                        className="form-input text-xs py-1.5 px-3 rounded-xl"
                                        style={{ flex: '1 1 160px', minWidth: '140px' }}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => handleRemoveFilter(fIdx)}
                                        className="p-1.5 rounded"
                                        style={{ color: 'var(--color-danger)' }}
                                    >
                                        <Trash2 className="w-4 h-4" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* Modal Footer */}
                <div className="modal-footer flex items-center justify-between p-4" style={{ borderTop: '1px solid var(--color-border)' }}>
                    <button
                        type="button"
                        onClick={onClose}
                        className="btn btn-ghost text-xs py-2 px-4 rounded-xl"
                    >
                        {t('common.cancel', 'Cancel')}
                    </button>
                    <button
                        type="button"
                        onClick={handleSave}
                        className="btn btn-primary text-xs py-2 px-6 rounded-xl font-semibold"
                    >
                        {t('reportCustomizer.apply', 'Apply')}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default ReportCustomizerModal;
