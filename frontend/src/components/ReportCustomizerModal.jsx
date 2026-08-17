import React, { useState, useEffect, useMemo, useRef } from 'react';
import { X, GripVertical, ChevronUp, ChevronDown, Plus, Trash2, Search, SlidersHorizontal, Layers, Filter as FilterIcon, RotateCcw, Star, Pencil, Save } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

// Report metrics list.
// `label` — full description (columns modal, header tooltips);
// `shortLabel` — compact table-header abbreviation (nowrap <th>).
export const ALL_REPORT_METRICS = [
    { id: 'profitability', label: 'Profitability', shortLabel: 'Profitability' },
    { id: 'clicks', label: 'Clicks', shortLabel: 'Clicks' },
    { id: 'unique_clicks', label: 'Unique clicks (campaign)', shortLabel: 'uClicks' },
    { id: 'conversions', label: 'Conversions', shortLabel: 'Conv' },
    { id: 'roi_confirmed', label: 'ROI (confirmed)', shortLabel: 'ROI (conf)' },
    { id: 'deposits', label: 'Deposits', shortLabel: 'Deposits' },
    { id: 'revenue_deposit', label: 'Revenue (deposit)', shortLabel: 'Rev (dep)' },
    { id: 'revenue_registration', label: 'Revenue (registration)', shortLabel: 'Rev (reg)' },
    { id: 'revenue', label: 'Revenue', shortLabel: 'Revenue' },
    { id: 'profit', label: 'Profit/Loss (all)', shortLabel: 'Profit' },
    { id: 'revenue_hold', label: 'Revenue (hold)', shortLabel: 'Rev (hold)' },
    { id: 'revenue_confirmed', label: 'Revenue (confirmed)', shortLabel: 'Rev (conf)' },
    { id: 'revenue_rejected', label: 'Revenue (rejected)', shortLabel: 'Rev (rej)' },
    { id: 'revenue_trash', label: 'Revenue (trash)', shortLabel: 'Rev (trash)' },
    { id: 'cost', label: 'Cost', shortLabel: 'Cost' },
    { id: 'visitors', label: 'Visitors', shortLabel: 'Visitors' },
    { id: 'unique_clicks_stream', label: 'Unique clicks (flow)', shortLabel: 'uClicks (flow)' },
    { id: 'unique_clicks_global', label: 'Unique clicks (global)', shortLabel: 'uClicks (glob)' },
    { id: 'uc_rate', label: 'Unique clicks % (campaign)', shortLabel: 'uClicks %' },
    { id: 'uc_rate_stream', label: 'Unique clicks % (flow)', shortLabel: 'uClicks % (fl)' },
    { id: 'uc_rate_global', label: 'Unique clicks % (global)', shortLabel: 'uClicks % (gl)' },
    { id: 'bots', label: 'Bots', shortLabel: 'Bots' },
    { id: 'bot_rate', label: 'Bot %', shortLabel: 'Bot %' },
    { id: 'proxies', label: 'Proxies', shortLabel: 'Proxies' },
    { id: 'empty_referrers', label: 'Empty referrers', shortLabel: 'Empty ref' },
    { id: 'leads', label: 'Leads', shortLabel: 'Leads' },
    { id: 'sales', label: 'Sales', shortLabel: 'Sales' },
    { id: 'rejected', label: 'Rejected', shortLabel: 'Rejected' },
    { id: 'trash', label: 'Trash', shortLabel: 'Trash' },
    { id: 'approve_rate', label: 'Approve %', shortLabel: 'Approve %' },
    { id: 'profit_confirmed', label: 'Profit/Loss (confirmed)', shortLabel: 'P/L (conf)' },
    { id: 'cr', label: 'CR — Conversion rate', shortLabel: 'CR' },
    { id: 'cr_sales', label: 'CR (sales) — Conversion rate', shortLabel: 'CR (sales)' },
    { id: 'cr_deposits', label: 'CR (deposits) — Conversion rate', shortLabel: 'CR (deps)' },
    { id: 'cr_holds', label: 'CR (hold) — Conversion rate', shortLabel: 'CR (hold)' },
    { id: 'cr_registrations', label: 'CR (registrations) — Conversion rate', shortLabel: 'CR (regs)' },
    { id: 'roi', label: 'ROI (all) — Return on investment', shortLabel: 'ROI' },
    { id: 'epc', label: 'EPC (all) — Earnings per LP click', shortLabel: 'EPC', hintKey: 'epcHint', hint: 'Revenue / LP Clicks (Lander CTA) — equals EPV on direct offers' },
    { id: 'epv', label: 'EPV — Earnings per visit', shortLabel: 'EPV', hintKey: 'epvHint', hint: 'Revenue / all visits — universal for Direct and Lander flows' },
    { id: 'uepc', label: 'uEPC (all) — Earnings per unique click', shortLabel: 'uEPC' },
    { id: 'epc_hold', label: 'EPC (hold) — Earnings per click', shortLabel: 'EPC (hold)' },
    { id: 'uepc_hold', label: 'uEPC (hold) — Earnings per unique click', shortLabel: 'uEPC (hold)' },
    { id: 'epc_registration', label: 'EPC (registration) — Earnings per click', shortLabel: 'EPC (reg)' },
    { id: 'uepc_registration', label: 'uEPC (registration) — Earnings per unique click', shortLabel: 'uEPC (reg)' },
    { id: 'epc_confirmed', label: 'EPC (confirmed) — Earnings per click', shortLabel: 'EPC (conf)' },
    { id: 'uepc_confirmed', label: 'uEPC (confirmed) — Earnings per unique click', shortLabel: 'uEPC (conf)' },
    { id: 'cps', label: 'CPS — Cost per sale', shortLabel: 'CPS' },
    { id: 'cpl', label: 'CPL — Cost per lead', shortLabel: 'CPL' },
    { id: 'cpr', label: 'CPR — Cost per registration', shortLabel: 'CPR' },
    { id: 'cpd', label: 'CPD — Cost per deposit', shortLabel: 'CPD' },
    { id: 'cpa', label: 'CPA — Cost per conversion', shortLabel: 'CPA' },
    { id: 'cpc', label: 'CPC — Cost per LP click', shortLabel: 'CPC', hintKey: 'cpcHint', hint: 'Cost / LP Clicks (Lander CTA) — equals CPV on direct offers' },
    { id: 'ucpc', label: 'uCPC — Cost per unique click', shortLabel: 'uCPC' },
    { id: 'cpv', label: 'CPV — Cost per visit', shortLabel: 'CPV', hintKey: 'cpvHint', hint: 'Cost / all visits — universal for Direct and Lander flows' },
    { id: 'ecpc', label: 'eCPC — Cost per 1000 clicks', shortLabel: 'eCPC' },
    { id: 'ecpm_all', label: 'eCPM (all) — Profit per 1k clicks', shortLabel: 'eCPM' },
    { id: 'ecpm_confirmed', label: 'eCPM (confirmed) — Profit per 1k clicks', shortLabel: 'eCPM (conf)' },
    { id: 'earnings_per_conv', label: 'EC (all) — Earnings per conversion', shortLabel: 'EC' },
    { id: 'ec_confirmed', label: 'EC (confirmed) — Earning per conversion', shortLabel: 'EC (conf)' },
    { id: 'registrations', label: 'Registrations', shortLabel: 'Regs' },
    { id: 'ucr', label: 'uCR — Unique clicks to registrations', shortLabel: 'uCR' },
    { id: 'time_since_lp_click', label: 'Time since LP click', shortLabel: 'LP time' },
    { id: 'lp_views', label: 'LP views / visits', shortLabel: 'LP Views', hintKey: 'lpViewsHint', hint: 'Total landing-page impressions' },
    { id: 'lp_clicks', label: 'LP clicks', shortLabel: 'LP Clicks', hintKey: 'lpClicksHint', hint: 'Total clicks on landing-page CTA buttons' },
    { id: 'lp_ctr', label: 'LP CTR — LP Click-Through Rate', shortLabel: 'LP CTR', hintKey: 'lpCtrHint', hint: '(LP Clicks / LP Views) × 100% — dash on direct offers (no CTA)' },
    { id: 'cr_regs_to_deps', label: 'CR (regs to deps)', shortLabel: 'CR (r→d)' },
];

// `prelander_clicks` was previously exposed as the LP-click column even
// though the API uses it as the legacy alias for LP views. Migrate saved
// browser templates/selections to the unambiguous canonical field.
export const normalizeReportMetricIds = (ids) => {
    if (!Array.isArray(ids)) return [];
    return [...new Set(ids.map(id => id === 'prelander_clicks' ? 'lp_clicks' : id))];
};

export const PRESETS = {
    best: ['profitability', 'clicks', 'unique_clicks', 'conversions', 'roi_confirmed', 'cost', 'revenue', 'profit', 'cr', 'epc', 'cpc', 'cpa'],
    finance: ['cost', 'revenue', 'revenue_confirmed', 'revenue_hold', 'revenue_rejected', 'profit', 'roi', 'profit_confirmed', 'roi_confirmed', 'cpa', 'epc'],
    cod: ['clicks', 'unique_clicks', 'leads', 'sales', 'approve_rate', 'rejected', 'trash', 'cost', 'cpl', 'cps', 'cpa', 'revenue_confirmed', 'profit_confirmed', 'roi_confirmed'],
    lander_to_offer: ['clicks', 'unique_clicks', 'lp_views', 'lp_clicks', 'lp_ctr', 'conversions', 'cr', 'cpv', 'cpc', 'epv', 'epc', 'cpa', 'cost', 'revenue', 'profit', 'roi'],
    traffic: ['clicks', 'unique_clicks', 'visitors', 'unique_clicks_stream', 'unique_clicks_global', 'uc_rate', 'bots', 'bot_rate', 'proxies', 'empty_referrers', 'conversions', 'cr'],
    all: ALL_REPORT_METRICS.map(m => m.id),
};

const DEFAULT_METRIC_ORDER = ALL_REPORT_METRICS.map(m => m.id);
export const getReportMetricTooltip = (metric, t) => {
    if (!metric) return '';
    return metric.hintKey ? t(`metrics.${metric.hintKey}`, metric.hint || metric.label) : metric.label;
};
export const REPORT_DIMENSION_LABELS = {
    // Tracker entities
    campaign_id: 'Campaign',
    stream_id: 'Stream',
    landing_id: 'Landing Page',
    offer_id: 'Offer',
    source_id: 'Traffic Source',

    // Geography and devices
    country: 'Country',
    region: 'Region',
    city: 'City',
    device_type: 'Device Type',
    os: 'Operating System',
    browser: 'Browser',
    language: 'Language',

    // Time
    day: 'Date (Day)',
    hour: 'Hour',

    // Ad parameters
    ad_campaign_id: 'Ad Campaign ID',
    adset_id: 'AdSet ID',
    ad_id: 'Ad ID',
    keyword: 'Keyword',
    creative_id: 'Creative ID',
    external_id: 'External ID',

    // SubIDs
    sub_id_1: 'Sub ID 1',
    sub_id_2: 'Sub ID 2',
    sub_id_3: 'Sub ID 3',
    sub_id_4: 'Sub ID 4',
    sub_id_5: 'Sub ID 5'
};

const DIMENSION_I18N_KEYS = {
    campaign_id: 'campaign',
    stream_id: 'stream',
    landing_id: 'landing',
    offer_id: 'offer',
    source_id: 'source',
    country: 'country',
    region: 'region',
    city: 'city',
    device_type: 'deviceType',
    os: 'os',
    browser: 'browser',
    language: 'language',
    day: 'day',
    hour: 'hour',
    ad_campaign_id: 'adCampaignId',
    adset_id: 'adsetId',
    ad_id: 'adId',
    keyword: 'keyword',
    creative_id: 'creativeId',
    external_id: 'externalId',
    sub_id_1: 'subId1',
    sub_id_2: 'subId2',
    sub_id_3: 'subId3',
    sub_id_4: 'subId4',
    sub_id_5: 'subId5'
};

export const getDimensionLabel = (dim, t) => {
    if (!dim) return '';
    const i18nKey = DIMENSION_I18N_KEYS[dim];
    if (t && i18nKey) {
        const fullKey = `dimensions.${i18nKey}`;
        const translated = t(fullKey);
        if (translated && translated !== fullKey) {
            return translated;
        }
    }
    return REPORT_DIMENSION_LABELS[dim] || dim;
};

// --- User-saved column templates (localStorage) ---
const TEMPLATES_KEY = 'orbitra_column_templates';
const DEFAULT_TEMPLATE_KEY = 'orbitra_default_template_id';

const arraysEqual = (a, b) => Array.isArray(a) && Array.isArray(b) && a.length === b.length && a.every((v, i) => v === b[i]);

// Template ids only need to be unique per browser — seeded once at module load
let nextTemplateId = Date.now();

const loadColumnTemplates = () => {
    try {
        const parsed = JSON.parse(localStorage.getItem(TEMPLATES_KEY) || '[]');
        if (!Array.isArray(parsed)) return [];
        return parsed
            .filter(t => t && typeof t.id === 'string' && typeof t.name === 'string' && Array.isArray(t.columns))
            .map(t => ({ ...t, columns: normalizeReportMetricIds(t.columns) }));
    } catch {
        // Corrupt storage entry — fall back to no saved templates
        return [];
    }
};

const persistColumnTemplates = (next) => {
    try {
        localStorage.setItem(TEMPLATES_KEY, JSON.stringify(next));
    } catch {
        // Storage full/unavailable — templates just won't survive the session
    }
};

const loadDefaultTemplateId = () => {
    try {
        return localStorage.getItem(DEFAULT_TEMPLATE_KEY);
    } catch {
        return null;
    }
};

const persistDefaultTemplateId = (id) => {
    try {
        if (id) localStorage.setItem(DEFAULT_TEMPLATE_KEY, id);
        else localStorage.removeItem(DEFAULT_TEMPLATE_KEY);
    } catch {
        // Storage full/unavailable — default choice won't survive the session
    }
};

// Columns of the user's default template (system preset id or custom template
// id), or null when no default is set. Pages use this as the initial shape
// when no per-page column selection has been saved yet.
export const getDefaultTemplateColumns = () => {
    const id = loadDefaultTemplateId();
    if (!id) return null;
    if (Array.isArray(PRESETS[id])) return normalizeReportMetricIds(PRESETS[id]);
    const tpl = loadColumnTemplates().find(t => t.id === id);
    return tpl ? normalizeReportMetricIds(tpl.columns) : null;
};

// System preset chips: [preset key, locale key under reportCustomizer.*]
const SYSTEM_PRESETS = [
    ['best', 'presetBest'],
    ['finance', 'presetFinance'],
    ['cod', 'presetCod'],
    ['lander_to_offer', 'presetLanderToOffer'],
    ['traffic', 'presetTraffic'],
    ['all', 'presetAll']
];

const ReportCustomizerModal = ({
    isOpen,
    onClose,
    selectedColumns = [],
    onSaveColumns,
    mode = 'report', // 'report' or 'campaigns'
    currentLayers = [],
    onSaveLayers,
    layerPresets = [],
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

    // User-saved grouping combos (localStorage). System presets arrive via the
    // layerPresets prop; these are the user's own hierarchies.
    const [customGroupTemplates, setCustomGroupTemplates] = useState(() => {
        try {
            return JSON.parse(localStorage.getItem('orbitra_report_group_templates')) || [];
        } catch {
            return [];
        }
    });
    const [groupSaveDialogOpen, setGroupSaveDialogOpen] = useState(false);
    const [groupTemplateName, setGroupTemplateName] = useState('');

    const persistGroupTemplates = (templates) => {
        setCustomGroupTemplates(templates);
        try {
            localStorage.setItem('orbitra_report_group_templates', JSON.stringify(templates));
        } catch {
            // Private mode / quota — the in-memory copy still works this session.
        }
    };

    const handleSaveGroupTemplate = () => {
        const name = groupTemplateName.trim();
        if (!name || layers.length === 0) return;
        persistGroupTemplates([...customGroupTemplates, { id: `gtpl_${Date.now()}`, name, layers: [...layers] }]);
        setGroupSaveDialogOpen(false);
        setGroupTemplateName('');
    };

    const handleDeleteGroupTemplate = (tplId) => {
        persistGroupTemplates(customGroupTemplates.filter((tpl) => tpl.id !== tplId));
    };
    // Filters
    const [filters, setFilters] = useState([]);

    // Saved column templates + the one marked as default
    const [templates, setTemplates] = useState([]);
    const [defaultTemplateId, setDefaultTemplateId] = useState(null);
    // Custom template the user last applied — enables the "Save changes to X" flow
    const [lastAppliedTemplateId, setLastAppliedTemplateId] = useState(null);
    const [saveDialogOpen, setSaveDialogOpen] = useState(false);
    const [templateName, setTemplateName] = useState('');
    const [templateAsDefault, setTemplateAsDefault] = useState(false);

    // Drag-and-drop state, tracked in ref + state so drops never lose the ID
    const draggedIdRef = useRef(null);
    const [draggedId, setDraggedId] = useState(null);
    const [dragOverId, setDragOverId] = useState(null);
    const prevIsOpenRef = useRef(false);

    useEffect(() => {
        if (isOpen && !prevIsOpenRef.current) {
            const initialSelected = normalizeReportMetricIds(
                selectedColumns && selectedColumns.length > 0 ? selectedColumns : PRESETS.best
            );
            setSelectedSet(new Set(initialSelected));

            // Put selected items in their user order, followed by the remaining unselected metrics
            const unselected = DEFAULT_METRIC_ORDER.filter(id => !initialSelected.includes(id));
            const initialOrder = [...initialSelected, ...unselected];
            setOrderedMetricIds(initialOrder);

            if (currentLayers) setLayers([...currentLayers]);
            if (currentFilters) setFilters([...currentFilters]);
            setSearchQuery('');

            setTemplates(loadColumnTemplates());
            setDefaultTemplateId(loadDefaultTemplateId());
            setLastAppliedTemplateId(null);
            setSaveDialogOpen(false);
            setTemplateName('');
            setTemplateAsDefault(false);
        }
        prevIsOpenRef.current = isOpen;
    }, [isOpen, selectedColumns, currentLayers, currentFilters]);

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

    // Apply a system preset or a saved template: exact columns, exact order
    const handleApplyTemplate = (templateId) => {
        const cols = Array.isArray(PRESETS[templateId])
            ? PRESETS[templateId]
            : templates.find(t => t.id === templateId)?.columns;
        if (!cols) return;
        setSelectedSet(new Set(cols));
        const unselected = DEFAULT_METRIC_ORDER.filter(id => !cols.includes(id));
        setOrderedMetricIds([...cols, ...unselected]);
        setLastAppliedTemplateId(templateId);
    };

    const handleRestoreDefault = () => {
        handleApplyTemplate('best');
    };

    const handleToggleDefault = (templateId) => {
        const next = defaultTemplateId === templateId ? null : templateId;
        setDefaultTemplateId(next);
        persistDefaultTemplateId(next);
    };

    const handleSaveTemplate = () => {
        const name = templateName.trim();
        if (!name) return;
        const tpl = { id: `custom_${++nextTemplateId}`, name, columns: selectedOrdered, isCustom: true };
        const next = [...templates, tpl];
        setTemplates(next);
        persistColumnTemplates(next);
        setLastAppliedTemplateId(tpl.id);
        if (templateAsDefault) {
            setDefaultTemplateId(tpl.id);
            persistDefaultTemplateId(tpl.id);
        }
        setSaveDialogOpen(false);
        setTemplateName('');
        setTemplateAsDefault(false);
    };

    const handleUpdateTemplate = (tpl) => {
        const next = templates.map(t => (t.id === tpl.id ? { ...t, columns: selectedOrdered } : t));
        setTemplates(next);
        persistColumnTemplates(next);
    };

    const handleRenameTemplate = (tpl) => {
        const name = window.prompt(t('reportCustomizer.renameTemplatePrompt', 'Template name:'), tpl.name);
        if (name === null) return;
        const clean = name.trim();
        if (!clean || clean === tpl.name) return;
        const next = templates.map(t => (t.id === tpl.id ? { ...t, name: clean } : t));
        setTemplates(next);
        persistColumnTemplates(next);
    };

    const handleDeleteTemplate = (tpl) => {
        if (!window.confirm(t('reportCustomizer.deleteTemplateConfirm', 'Delete template "{name}"?').replace('{name}', tpl.name))) return;
        const next = templates.filter(t => t.id !== tpl.id);
        setTemplates(next);
        persistColumnTemplates(next);
        if (defaultTemplateId === tpl.id) handleToggleDefault(tpl.id);
        if (lastAppliedTemplateId === tpl.id) setLastAppliedTemplateId(null);
    };

    // Move metric up or down by 1 position (instant, keyboard/touch-friendly)
    const handleMoveMetric = (metricId, direction) => {
        setOrderedMetricIds(prev => {
            const next = [...prev];
            const currentIndex = next.indexOf(metricId);
            if (currentIndex === -1) return prev;
            const targetIndex = direction === 'up' ? currentIndex - 1 : currentIndex + 1;
            if (targetIndex < 0 || targetIndex >= next.length) return prev;
            const [item] = next.splice(currentIndex, 1);
            next.splice(targetIndex, 0, item);
            return next;
        });
    };

    // HTML5 Drag-and-Drop with ref fallback for Safari / Chrome Mac
    const handleDragStart = (e, metricId) => {
        draggedIdRef.current = metricId;
        setDraggedId(metricId);
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', metricId);
    };

    const handleDragOver = (e, targetMetricId) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (dragOverId !== targetMetricId) {
            setDragOverId(targetMetricId);
        }
    };

    const handleDrop = (e, targetMetricId) => {
        e.preventDefault();
        const sourceId = draggedIdRef.current || e.dataTransfer.getData('text/plain');
        if (sourceId && sourceId !== targetMetricId) {
            setOrderedMetricIds(prev => {
                const next = [...prev];
                const fromIndex = next.indexOf(sourceId);
                const toIndex = next.indexOf(targetMetricId);
                if (fromIndex !== -1 && toIndex !== -1) {
                    const [item] = next.splice(fromIndex, 1);
                    next.splice(toIndex, 0, item);
                }
                return next;
            });
        }
        draggedIdRef.current = null;
        setDraggedId(null);
        setDragOverId(null);
    };

    const handleDragEnd = () => {
        draggedIdRef.current = null;
        setDraggedId(null);
        setDragOverId(null);
    };

    const handleSave = () => {
        // Return only the selected columns in the user's customized drag order
        const result = orderedMetricIds.filter(id => selectedSet.has(id));
        const finalColumns = result.length > 0 ? result : ['clicks'];

        if (onSaveColumns) {
            onSaveColumns(finalColumns);
        }

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
            .filter(m => !q || m.label.toLowerCase().includes(q) || (m.shortLabel && m.shortLabel.toLowerCase().includes(q)) || m.id.toLowerCase().includes(q));
    }, [orderedMetricIds, searchQuery]);

    // Select All toggles the visible (filtered) metrics
    const isAllSelected = displayMetrics.length > 0 && displayMetrics.every(m => selectedSet.has(m.id));

    const handleToggleAll = () => {
        if (isAllSelected) {
            setSelectedSet(new Set(['clicks']));
        } else {
            const visibleIds = displayMetrics.map(m => m.id);
            setSelectedSet(prev => new Set([...prev, ...visibleIds]));
        }
    };

    // Selected columns in the user's drag order — the exact shape a template stores
    const selectedOrdered = useMemo(
        () => orderedMetricIds.filter(id => selectedSet.has(id)),
        [orderedMetricIds, selectedSet]
    );

    // Which preset/template the current selection matches exactly (drives chip highlight)
    const activeTemplateId = useMemo(() => {
        for (const [key] of SYSTEM_PRESETS) {
            if (arraysEqual(PRESETS[key], selectedOrdered)) return key;
        }
        const match = templates.find(t => arraysEqual(t.columns, selectedOrdered));
        return match ? match.id : null;
    }, [selectedOrdered, templates]);

    // A custom template was applied and then edited — offer an in-place update
    const updatableTemplate = useMemo(() => {
        if (!lastAppliedTemplateId) return null;
        const tpl = templates.find(t => t.id === lastAppliedTemplateId);
        if (!tpl) return null;
        return arraysEqual(tpl.columns, selectedOrdered) ? null : tpl;
    }, [lastAppliedTemplateId, templates, selectedOrdered]);

    // Early return goes AFTER every hook
    if (!isOpen) return null;

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
                    maxWidth: '560px',
                    width: '100%',
                    maxHeight: '90vh',
                    height: '86vh',
                    padding: 0,
                    backgroundColor: 'var(--color-bg-card)',
                    border: '1px solid var(--color-border)',
                    color: 'var(--color-text-primary)'
                }}
            >
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b" style={{ borderColor: 'var(--color-border)' }}>
                    <div className="flex items-center gap-2">
                        <SlidersHorizontal className="w-5 h-5 text-[var(--color-primary)]" />
                        <h3 className="text-base font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                            {t('reportCustomizer.columnsSelector', 'Columns')}
                        </h3>
                    </div>
                    <button
                        onClick={onClose}
                        className="btn-icon p-1 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                        style={{ color: 'var(--color-text-muted)' }}
                        title={t('common.close', 'Close')}
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
                    <div className="flex flex-col flex-1 overflow-hidden p-4 sm:p-5">
                        {/* Search Input */}
                        <div className="relative mb-2.5">
                            <input
                                type="text"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                placeholder={t('reportCustomizer.searchMetrics', 'Search columns...')}
                                className="form-input text-xs py-2 pl-8 pr-8 rounded-xl w-full"
                                style={{
                                    backgroundColor: 'var(--color-bg-soft)',
                                    borderColor: 'var(--color-border)',
                                    color: 'var(--color-text-primary)'
                                }}
                            />
                            <Search className="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--color-text-muted)]" />
                            {searchQuery && (
                                <button
                                    type="button"
                                    onClick={() => setSearchQuery('')}
                                    className="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)]"
                                >
                                    ✕
                                </button>
                            )}
                        </div>

                        {/* Presets & Saved Templates Bar */}
                        <div className="flex items-center gap-1.5 overflow-x-auto pb-2 mb-2 select-none" style={{ scrollbarWidth: 'none' }}>
                            <span className="text-[11px] font-medium text-[var(--color-text-muted)] flex-shrink-0 mr-0.5">
                                {t('reportCustomizer.presets', 'Presets')}:
                            </span>
                            {SYSTEM_PRESETS.map(([pKey, labelKey]) => {
                                const isActive = activeTemplateId === pKey;
                                const isDefault = defaultTemplateId === pKey;
                                return (
                                    <div
                                        key={pKey}
                                        role="button"
                                        tabIndex={0}
                                        onClick={() => handleApplyTemplate(pKey)}
                                        className="group text-[11px] pl-2.5 pr-1.5 py-1 rounded-lg border transition-all flex-shrink-0 flex items-center gap-1 font-medium cursor-pointer hover:border-[var(--color-primary)]"
                                        style={{
                                            backgroundColor: isActive ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                                            borderColor: isActive ? 'var(--color-primary)' : 'var(--color-border)',
                                            color: isActive ? 'var(--color-primary)' : 'var(--color-text-secondary)'
                                        }}
                                    >
                                        <span>{t(`reportCustomizer.${labelKey}`)} ({PRESETS[pKey].length})</span>
                                        <button
                                            type="button"
                                            onClick={(e) => { e.stopPropagation(); handleToggleDefault(pKey); }}
                                            className={`p-0.5 rounded flex-shrink-0 transition-opacity hover:text-[var(--color-primary)] ${isDefault
                                                ? 'text-[var(--color-primary)]'
                                                : 'text-[var(--color-text-muted)] opacity-0 group-hover:opacity-100'}`}
                                            title={isDefault
                                                ? t('reportCustomizer.defaultTemplateActive', 'Default template — click to remove')
                                                : t('reportCustomizer.makeDefault', 'Set as default')}
                                        >
                                            <Star className={`w-3 h-3 ${isDefault ? 'fill-current' : ''}`} />
                                        </button>
                                    </div>
                                );
                            })}
                            {templates.map((tpl) => {
                                const isActive = activeTemplateId === tpl.id;
                                const isDefault = defaultTemplateId === tpl.id;
                                return (
                                    <div
                                        key={tpl.id}
                                        role="button"
                                        tabIndex={0}
                                        onClick={() => handleApplyTemplate(tpl.id)}
                                        className="group text-[11px] pl-2 pr-1.5 py-1 rounded-lg border transition-all flex-shrink-0 flex items-center gap-1 font-medium cursor-pointer hover:border-[var(--color-primary)]"
                                        style={{
                                            backgroundColor: isActive ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                                            borderColor: isActive ? 'var(--color-primary)' : 'var(--color-border)',
                                            color: isActive ? 'var(--color-primary)' : 'var(--color-text-secondary)'
                                        }}
                                    >
                                        <span className="w-1.5 h-1.5 rounded-full flex-shrink-0" style={{ backgroundColor: 'var(--color-primary)' }} />
                                        <span className="max-w-40 truncate">{tpl.name}</span>
                                        <button
                                            type="button"
                                            onClick={(e) => { e.stopPropagation(); handleToggleDefault(tpl.id); }}
                                            className={`p-0.5 rounded flex-shrink-0 transition-opacity hover:text-[var(--color-primary)] ${isDefault
                                                ? 'text-[var(--color-primary)]'
                                                : 'text-[var(--color-text-muted)] opacity-0 group-hover:opacity-100'}`}
                                            title={isDefault
                                                ? t('reportCustomizer.defaultTemplateActive', 'Default template — click to remove')
                                                : t('reportCustomizer.makeDefault', 'Set as default')}
                                        >
                                            <Star className={`w-3 h-3 ${isDefault ? 'fill-current' : ''}`} />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={(e) => { e.stopPropagation(); handleRenameTemplate(tpl); }}
                                            className="p-0.5 rounded flex-shrink-0 text-[var(--color-text-muted)] opacity-0 group-hover:opacity-100 hover:text-[var(--color-primary)] transition-opacity"
                                            title={t('common.edit', 'Edit')}
                                        >
                                            <Pencil className="w-3 h-3" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={(e) => { e.stopPropagation(); handleDeleteTemplate(tpl); }}
                                            className="p-0.5 rounded flex-shrink-0 text-[var(--color-text-muted)] opacity-0 group-hover:opacity-100 hover:text-red-500 transition-opacity"
                                            title={t('common.delete', 'Delete')}
                                        >
                                            <Trash2 className="w-3 h-3" />
                                        </button>
                                    </div>
                                );
                            })}
                            {updatableTemplate && (
                                <button
                                    type="button"
                                    onClick={() => handleUpdateTemplate(updatableTemplate)}
                                    className="text-[11px] px-2.5 py-1 rounded-lg border transition-all flex-shrink-0 flex items-center gap-1 font-medium hover:border-[var(--color-primary)]"
                                    style={{
                                        backgroundColor: 'var(--color-bg-soft)',
                                        borderColor: 'var(--color-border)',
                                        color: 'var(--color-text-primary)'
                                    }}
                                    title={t('reportCustomizer.updateTemplate', 'Save changes to "{name}"').replace('{name}', updatableTemplate.name)}
                                >
                                    <Save className="w-3 h-3 flex-shrink-0" />
                                    <span className="max-w-32 truncate">
                                        {t('reportCustomizer.updateTemplate', 'Save changes to "{name}"').replace('{name}', updatableTemplate.name)}
                                    </span>
                                </button>
                            )}
                            <button
                                type="button"
                                onClick={() => { setTemplateName(''); setTemplateAsDefault(false); setSaveDialogOpen(true); }}
                                className="text-[11px] px-2.5 py-1 rounded-lg border transition-all flex-shrink-0 flex items-center gap-1 font-medium hover:border-[var(--color-primary)]"
                                style={{
                                    backgroundColor: 'var(--color-bg-soft)',
                                    borderColor: 'var(--color-border)',
                                    color: 'var(--color-primary)'
                                }}
                            >
                                <Plus className="w-3 h-3" />
                                {t('reportCustomizer.saveTemplate', 'Save as Template')}
                            </button>
                        </div>

                        {/* Save Template Dialog */}
                        {saveDialogOpen && (
                            <div className="mb-2 p-2.5 rounded-xl border flex flex-col gap-2" style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
                                <input
                                    autoFocus
                                    type="text"
                                    value={templateName}
                                    onChange={(e) => setTemplateName(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') handleSaveTemplate();
                                        if (e.key === 'Escape') setSaveDialogOpen(false);
                                    }}
                                    placeholder={t('reportCustomizer.templateNamePlaceholder', 'e.g. COD Nutra Split')}
                                    className="form-input text-xs py-2 px-3 rounded-xl w-full"
                                    style={{
                                        backgroundColor: 'var(--color-bg-card)',
                                        borderColor: 'var(--color-border)',
                                        color: 'var(--color-text-primary)'
                                    }}
                                />
                                <label className="flex items-center gap-2 text-xs cursor-pointer select-none" style={{ color: 'var(--color-text-secondary)' }}>
                                    <input
                                        type="checkbox"
                                        checked={templateAsDefault}
                                        onChange={(e) => setTemplateAsDefault(e.target.checked)}
                                        className="w-4 h-4 rounded cursor-pointer"
                                        style={{ accentColor: 'var(--color-primary)' }}
                                    />
                                    {t('reportCustomizer.setAsDefault', 'Set as default template')}
                                </label>
                                <div className="flex justify-end gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setSaveDialogOpen(false)}
                                        className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl font-medium"
                                    >
                                        {t('common.cancel', 'Cancel')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={handleSaveTemplate}
                                        disabled={!templateName.trim()}
                                        className="btn btn-primary text-xs py-1.5 px-4 rounded-xl font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        {t('common.save', 'Save')}
                                    </button>
                                </div>
                            </div>
                        )}

                        {/* Select All Checkbox + Selected Count Badge */}
                        <div className="flex items-center justify-between px-2 py-1.5 select-none rounded-lg bg-[var(--color-bg-soft)] mb-2 border border-[var(--color-border)]">
                            <div className="flex items-center gap-2.5">
                                <input
                                    type="checkbox"
                                    id="select_all_cols"
                                    checked={isAllSelected}
                                    onChange={handleToggleAll}
                                    className="w-4 h-4 rounded cursor-pointer"
                                    style={{ accentColor: 'var(--color-primary)' }}
                                />
                                <label htmlFor="select_all_cols" className="text-xs font-medium cursor-pointer" style={{ color: 'var(--color-text-primary)' }}>
                                    {isAllSelected ? t('reportCustomizer.deselectAll', 'Deselect All') : t('reportCustomizer.selectAll', 'Select All')}
                                </label>
                            </div>
                            <div className="text-[11px] font-medium px-2 py-0.5 rounded-full" style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-secondary)', border: '1px solid var(--color-border)' }}>
                                {selectedSet.size} / {ALL_REPORT_METRICS.length} {t('reportCustomizer.selected', 'selected')}
                            </div>
                        </div>

                        {/* Reorderable Columns List */}
                        <div
                            className="flex-1 overflow-y-auto space-y-1 pr-1"
                            style={{ scrollbarWidth: 'thin' }}
                        >
                            {displayMetrics.map((metric, idx) => {
                                const isChecked = selectedSet.has(metric.id);
                                const isDragging = draggedId === metric.id;
                                const isOver = dragOverId === metric.id && draggedId && draggedId !== metric.id;
                                const isFirst = idx === 0;
                                const isLast = idx === displayMetrics.length - 1;

                                return (
                                    <div
                                        key={metric.id}
                                        title={getReportMetricTooltip(metric, t)}
                                        onDragOver={(e) => handleDragOver(e, metric.id)}
                                        onDrop={(e) => handleDrop(e, metric.id)}
                                        onClick={() => handleToggleMetric(metric.id)}
                                        className="group flex items-center gap-2 px-2.5 py-1.5 rounded-xl text-xs select-none transition-all cursor-pointer border"
                                        style={{
                                            backgroundColor: isChecked ? 'var(--color-bg-soft)' : 'transparent',
                                            borderColor: isChecked ? 'var(--color-border)' : 'transparent',
                                            opacity: isDragging ? 0.35 : 1,
                                            boxShadow: isOver ? 'inset 0 2px 0 var(--color-primary)' : 'none'
                                        }}
                                    >
                                        {/* Drag handle */}
                                        <div
                                            draggable
                                            onDragStart={(e) => handleDragStart(e, metric.id)}
                                            onDragEnd={handleDragEnd}
                                            onClick={(e) => e.stopPropagation()}
                                            className="cursor-grab active:cursor-grabbing p-1 text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)] transition-colors flex-shrink-0"
                                            title="Drag to reorder"
                                        >
                                            <GripVertical className="w-3.5 h-3.5 pointer-events-none opacity-60 group-hover:opacity-100" />
                                        </div>

                                        {/* Move Up / Move Down buttons */}
                                        <div
                                            className="flex items-center gap-0.5 flex-shrink-0 opacity-40 group-hover:opacity-100 transition-opacity"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            <button
                                                type="button"
                                                disabled={isFirst}
                                                onClick={() => handleMoveMetric(metric.id, 'up')}
                                                className="p-0.5 rounded hover:bg-black/10 dark:hover:bg-white/10 disabled:opacity-20 text-[var(--color-text-muted)] transition-colors"
                                                title="Move up"
                                            >
                                                <ChevronUp className="w-3.5 h-3.5" />
                                            </button>
                                            <button
                                                type="button"
                                                disabled={isLast}
                                                onClick={() => handleMoveMetric(metric.id, 'down')}
                                                className="p-0.5 rounded hover:bg-black/10 dark:hover:bg-white/10 disabled:opacity-20 text-[var(--color-text-muted)] transition-colors"
                                                title="Move down"
                                            >
                                                <ChevronDown className="w-3.5 h-3.5" />
                                            </button>
                                        </div>

                                        {/* Checkbox */}
                                        <input
                                            type="checkbox"
                                            checked={isChecked}
                                            onChange={() => handleToggleMetric(metric.id)}
                                            onClick={(e) => e.stopPropagation()}
                                            className="w-4 h-4 rounded cursor-pointer flex-shrink-0"
                                            style={{ accentColor: 'var(--color-primary)' }}
                                        />

                                        {/* Full Metric Label */}
                                        <span
                                            className="flex-1 truncate font-medium"
                                            style={{
                                                color: isChecked ? 'var(--color-text-primary)' : 'var(--color-text-secondary)'
                                            }}
                                        >
                                            {metric.label}
                                        </span>

                                        {/* Compact Short Code Tag */}
                                        <span
                                            className="text-[10.5px] px-1.5 py-0.5 rounded font-mono flex-shrink-0"
                                            style={{
                                                backgroundColor: 'var(--color-bg-card)',
                                                color: 'var(--color-text-muted)',
                                                border: '1px solid var(--color-border)'
                                            }}
                                        >
                                            {metric.shortLabel || metric.id}
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

                        {(layerPresets.length > 0 || customGroupTemplates.length > 0) && (
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-xs font-semibold uppercase" style={{ color: 'var(--color-text-muted)' }}>
                                    {t('reportCustomizer.presets', 'Presets')}:
                                </span>
                                {layerPresets.map((preset) => {
                                    const active = arraysEqual(layers, preset.layers);
                                    return (
                                        <button
                                            key={preset.id}
                                            type="button"
                                            onClick={() => setLayers([...preset.layers])}
                                            className="text-xs px-3 py-1.5 rounded-xl border transition-colors"
                                            style={{
                                                backgroundColor: active ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                                                borderColor: active ? 'var(--color-primary)' : 'var(--color-border)',
                                                color: active ? 'var(--color-primary)' : 'var(--color-text-primary)',
                                                fontWeight: active ? 600 : 400
                                            }}
                                        >
                                            {preset.label}
                                        </button>
                                    );
                                })}
                                {customGroupTemplates.map((tpl) => {
                                    const active = arraysEqual(layers, tpl.layers);
                                    return (
                                        <div
                                            key={tpl.id}
                                            onClick={() => setLayers([...tpl.layers])}
                                            className="group inline-flex items-center gap-1 text-xs px-3 py-1.5 rounded-xl border transition-colors cursor-pointer"
                                            style={{
                                                backgroundColor: active ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                                                borderColor: active ? 'var(--color-primary)' : 'var(--color-border)',
                                                color: active ? 'var(--color-primary)' : 'var(--color-text-primary)',
                                                fontWeight: active ? 600 : 400
                                            }}
                                        >
                                            <span>{tpl.name}</span>
                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    handleDeleteGroupTemplate(tpl.id);
                                                }}
                                                className="p-0.5 opacity-0 group-hover:opacity-100 transition-opacity"
                                                style={{ color: 'var(--color-danger)' }}
                                                title={t('common.delete')}
                                            >
                                                <Trash2 className="w-3 h-3" />
                                            </button>
                                        </div>
                                    );
                                })}
                                <button
                                    type="button"
                                    disabled={layers.length === 0}
                                    onClick={() => setGroupSaveDialogOpen(true)}
                                    className="text-xs px-3 py-1.5 rounded-xl border transition-colors flex items-center gap-1 font-semibold disabled:opacity-40"
                                    style={{
                                        backgroundColor: 'var(--color-bg-soft)',
                                        borderColor: 'var(--color-border)',
                                        color: 'var(--color-primary)'
                                    }}
                                >
                                    <Plus className="w-3 h-3" />
                                    {t('reportCustomizer.saveGroupTemplate', 'Save as Template')}
                                </button>
                            </div>
                        )}

                        {groupSaveDialogOpen && (
                            <div className="p-3 rounded-xl border flex flex-col gap-2.5" style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
                                <input
                                    autoFocus
                                    type="text"
                                    value={groupTemplateName}
                                    onChange={(e) => setGroupTemplateName(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') handleSaveGroupTemplate();
                                        if (e.key === 'Escape') setGroupSaveDialogOpen(false);
                                    }}
                                    placeholder={t('reportCustomizer.groupTemplatePlaceholder', 'e.g. Country → City → ISP')}
                                    className="form-input w-full"
                                    style={{ fontSize: '0.75rem', padding: '0.5rem 0.75rem' }}
                                />
                                <div className="flex justify-end gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setGroupSaveDialogOpen(false)}
                                        className="btn btn-secondary text-xs py-1 px-3 rounded-xl"
                                    >
                                        {t('common.cancel')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={handleSaveGroupTemplate}
                                        disabled={!groupTemplateName.trim()}
                                        className="btn btn-primary text-xs py-1 px-4 rounded-xl font-bold"
                                    >
                                        {t('common.save')}
                                    </button>
                                </div>
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-2">
                            {[
                                'country', 'city', 'region', 'device_type', 'os', 'browser', 'language',
                                'day', 'hour', 'campaign_id', 'source_id', 'stream_id', 'landing_id', 'offer_id',
                                'ad_campaign_id', 'adset_id', 'ad_id', 'keyword', 'creative_id', 'external_id',
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
                                        <span className="truncate">{getDimensionLabel(dim, t)}</span>
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
                                    <option value="ad_campaign_id">FB Campaign ID</option>
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
                        className="text-xs transition-colors hover:underline flex items-center gap-1.5 font-medium"
                        style={{ color: 'var(--color-primary)' }}
                    >
                        <RotateCcw className="w-3.5 h-3.5" />
                        <span>{t('reportCustomizer.restoreDefault', 'Restore to default')}</span>
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
