import React, { useState, useMemo } from 'react';
import { Plus, Trash2, Edit3, Settings2, RefreshCw, Filter, X, ChevronUp, ChevronDown, ChevronsUpDown, Copy, SlidersHorizontal } from 'lucide-react';
import InfoBanner from './InfoBanner';
import OfferEditor from './OfferEditor';
import GroupsModal from './GroupsModal';
import ColumnsOrderModal from './ColumnsOrderModal';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

// Every column the offers table can show. Status counters and money come from
// the report engine's status groups (leads = hold group, sales = sale group,
// "confirmed" = sale group), so the labels match the 64-metric reports.
export const ALL_OFFER_COLUMNS = [
    { id: 'id', label: 'ID' },
    { id: 'name', label: 'Name', required: true },
    { id: 'state', label: 'Status' },
    { id: 'affiliate_network_name', label: 'Affiliate network' },
    { id: 'group_name', label: 'Group' },
    { id: 'redirect_type', label: 'Type' },
    { id: 'geo', label: 'GEO' },
    { id: 'payout', label: 'Payout' },
    { id: 'clicks', label: 'Clicks', alignRight: true },
    { id: 'unique_clicks', label: 'Uniques', alignRight: true },
    { id: 'conversions', label: 'Conversions', alignRight: true },
    { id: 'leads', label: 'Leads', alignRight: true },
    { id: 'sales', label: 'Sales', alignRight: true },
    { id: 'rejected', label: 'Rejected', alignRight: true },
    { id: 'revenue', label: 'Revenue', alignRight: true },
    { id: 'revenue_confirmed', label: 'Revenue (confirmed)', alignRight: true },
    { id: 'cost', label: 'Cost', alignRight: true },
    { id: 'cr', label: 'CR', alignRight: true },
    { id: 'epc_confirmed', label: 'EPC (confirmed)', alignRight: true },
    { id: 'cpc', label: 'CPC', alignRight: true },
    { id: 'profit_confirmed', label: 'P/L (confirmed)', alignRight: true },
    { id: 'roi_confirmed', label: 'ROI (confirmed)', alignRight: true },
];

export const DEFAULT_OFFER_COLUMNS = [
    'name', 'state', 'affiliate_network_name', 'clicks', 'leads', 'sales',
    'rejected', 'cr', 'epc_confirmed', 'cpc', 'revenue_confirmed', 'cost', 'profit_confirmed',
];

const OFFER_COLUMNS_KEY = 'orbitra_offer_columns';

const loadOfferColumns = () => {
    try {
        const saved = JSON.parse(localStorage.getItem(OFFER_COLUMNS_KEY) || 'null');
        if (Array.isArray(saved) && saved.length) {
            const valid = saved.filter(id => ALL_OFFER_COLUMNS.some(c => c.id === id));
            if (valid.includes('name')) return valid;
        }
    } catch (e) { /* fall through to default */ }
    return [...DEFAULT_OFFER_COLUMNS];
};

const Offers = ({ offers, refreshData }) => {
    const { t } = useLanguage();
    const [isEditorOpen, setIsEditorOpen] = useState(false);
    const [editingOfferId, setEditingOfferId] = useState(null);
    const [isGroupsModalOpen, setIsGroupsModalOpen] = useState(false);
    const [filterGroup, setFilterGroup] = useState('');
    const [filterNetwork, setFilterNetwork] = useState('');
    const [filterState, setFilterState] = useState('');
    const [showFilters, setShowFilters] = useState(false);
    const [selectedOfferIds, setSelectedOfferIds] = useState(() => new Set());
    const [sortBy, setSortBy] = useState({ key: null, dir: 'desc' }); // key=null keeps API order
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [columnsModalOpen, setColumnsModalOpen] = useState(false);
    const [chosenColumns, setChosenColumns] = useState(() => loadOfferColumns());

    // Get unique values for filters
    const groups = [...new Set(offers.map(o => o.group_name).filter(Boolean))];
    const networks = [...new Set(offers.map(o => o.affiliate_network_name).filter(Boolean))];

    const filteredOffers = offers.filter(o => {
        if (filterGroup && o.group_name !== filterGroup) return false;
        if (filterNetwork && o.affiliate_network_name !== filterNetwork) return false;
        if (filterState && o.state !== filterState) return false;
        return true;
    });

    const requestSort = (key, defaultDir = 'asc') => {
        setSortBy(prev => {
            if (prev.key === key) {
                return { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' };
            }
            return { key, dir: defaultDir };
        });
    };

    const visibleOffers = useMemo(() => {
        if (!sortBy.key) return filteredOffers;
        const dirMul = sortBy.dir === 'asc' ? 1 : -1;

        const getVal = (o) => {
            switch (sortBy.key) {
                case 'id': return Number(o.id) || 0;
                case 'name': return String(o.name || '');
                case 'group_name': return String(o.group_name || '');
                case 'affiliate_network_name': return String(o.affiliate_network_name || '');
                case 'redirect_type': return String(o.redirect_type || '');
                case 'state': return String(o.state || '');
                case 'geo': return String(o.geo || '');
                case 'payout': return Number(o.payout_value) || 0;
                case 'clicks': return Number(o.clicks) || 0;
                case 'unique_clicks': return Number(o.unique_clicks) || 0;
                case 'conversions': return Number(o.conversions) || 0;
                case 'leads': return Number(o.leads) || 0;
                case 'sales': return Number(o.sales) || 0;
                case 'rejected': return Number(o.rejected) || 0;
                case 'revenue': return Number(o.revenue) || 0;
                case 'revenue_confirmed': return Number(o.revenue_confirmed) || 0;
                case 'cost': return Number(o.cost) || 0;
                case 'cr': return Number(o.cr) || 0;
                case 'epc_confirmed': return Number(o.epc_confirmed) || 0;
                case 'cpc': return Number(o.cpc) || 0;
                case 'profit_confirmed': return Number(o.profit_confirmed) || 0;
                case 'roi_confirmed': return Number(o.roi_confirmed) || 0;
                default: return '';
            }
        };

        const isNumeric = ['id', 'payout', 'clicks', 'unique_clicks', 'conversions', 'leads', 'sales',
            'rejected', 'revenue', 'revenue_confirmed', 'cost', 'cr', 'epc_confirmed', 'cpc',
            'profit_confirmed', 'roi_confirmed'].includes(sortBy.key);

        return filteredOffers
            .map((offer, idx) => ({ offer, idx }))
            .sort((a, b) => {
                const av = getVal(a.offer);
                const bv = getVal(b.offer);
                let cmp = 0;
                if (isNumeric) {
                    cmp = (Number(av) || 0) - (Number(bv) || 0);
                } else {
                    cmp = String(av).localeCompare(String(bv), undefined, { sensitivity: 'base' });
                }
                if (cmp !== 0) return cmp * dirMul;
                return a.idx - b.idx; // stable
            })
            .map(x => x.offer);
    }, [filteredOffers, sortBy]);

    const handleCreate = () => {
        setEditingOfferId(null);
        setIsEditorOpen(true);
    };

    const handleEdit = (id) => {
        setEditingOfferId(id);
        setIsEditorOpen(true);
    };

    const handleDelete = async (id) => {
        if (window.confirm(t('common.deleteConfirm'))) {
            try {
                await axios.post(`${API_URL}?action=delete_offer`, { id });
                refreshData();
            } catch (err) {
                alert(t('common.error'));
            }
        }
    };

    const toggleSelected = (id, checked) => {
        setSelectedOfferIds(prev => {
            const next = new Set(prev);
            if (checked) next.add(id);
            else next.delete(id);
            return next;
        });
    };

    const toggleSelectAllFiltered = (checked) => {
        setSelectedOfferIds(prev => {
            const next = new Set(prev);
            if (checked) {
                visibleOffers.forEach(o => next.add(o.id));
            } else {
                visibleOffers.forEach(o => next.delete(o.id));
            }
            return next;
        });
    };

    const allFilteredSelected = visibleOffers.length > 0 && visibleOffers.every(o => selectedOfferIds.has(o.id));
    const someFilteredSelected = visibleOffers.some(o => selectedOfferIds.has(o.id));

    const handleBulkDeleteSelected = async () => {
        const ids = Array.from(selectedOfferIds);
        if (ids.length === 0) return;
        const msg = (t('common.deleteSelectedConfirm') || t('common.deleteConfirm')).replace('{count}', String(ids.length));
        if (!window.confirm(msg)) return;
        try {
            await axios.post(`${API_URL}?action=bulk_delete_offers`, { ids });
            setSelectedOfferIds(new Set());
            refreshData();
        } catch (err) {
            alert(t('common.error'));
        }
    };

    const handleBulkCopySelected = async () => {
        const ids = Array.from(selectedOfferIds);
        if (ids.length === 0) return;

        const confirmMsg = t('offers.bulkCopyConfirm');
        if (!window.confirm(confirmMsg)) return;

        let successCount = 0;
        let errorCount = 0;

        for (const id of ids) {
            try {
                await axios.post(`${API_URL}?action=copy_offer`, { id });
                successCount++;
            } catch (err) {
                errorCount++;
            }
        }

        if (successCount > 0) {
            alert(`${t('offers.copied')}: ${successCount}`);
            refreshData();
        }
        if (errorCount > 0) {
            alert(`${t('offers.copyErrors')}: ${errorCount}`);
        }

        setSelectedOfferIds(new Set());
    };

    const handleEditorClose = (wasSaved) => {
        setIsEditorOpen(false);
        if (wasSaved) {
            refreshData();
        }
    };

    const clearFilters = () => {
        setFilterGroup('');
        setFilterNetwork('');
        setFilterState('');
    };

    const hasActiveFilters = filterGroup || filterNetwork || filterState;

    // Calculate totals for filtered offers. Ratios (CR/EPC/CPC/ROI) are not
    // summed — they are recomputed from the totals below, same as the reports.
    const totals = filteredOffers.reduce((acc, o) => {
        acc.clicks += parseInt(o.clicks || 0);
        acc.unique_clicks += parseInt(o.unique_clicks || 0);
        acc.conversions += parseInt(o.conversions || 0);
        acc.leads += parseInt(o.leads || 0);
        acc.sales += parseInt(o.sales || 0);
        acc.rejected += parseInt(o.rejected || 0);
        acc.revenue += parseFloat(o.revenue || 0);
        acc.revenue_confirmed += parseFloat(o.revenue_confirmed || 0);
        acc.cost += parseFloat(o.cost || 0);
        return acc;
    }, { clicks: 0, unique_clicks: 0, conversions: 0, leads: 0, sales: 0, rejected: 0, revenue: 0, revenue_confirmed: 0, cost: 0 });

    const totalsProfitConfirmed = totals.revenue_confirmed - totals.cost;
    const renderTotalCell = (colId) => {
        switch (colId) {
            case 'clicks': return totals.clicks.toLocaleString();
            case 'unique_clicks': return totals.unique_clicks.toLocaleString();
            case 'conversions': return totals.conversions.toLocaleString();
            case 'leads': return totals.leads.toLocaleString();
            case 'sales': return totals.sales.toLocaleString();
            case 'rejected': return totals.rejected.toLocaleString();
            case 'revenue': return `$${totals.revenue.toFixed(2)}`;
            case 'revenue_confirmed': return `$${totals.revenue_confirmed.toFixed(2)}`;
            case 'cost': return `$${totals.cost.toFixed(2)}`;
            case 'profit_confirmed': return `$${totalsProfitConfirmed.toFixed(2)}`;
            case 'cr': return totals.clicks > 0 ? `${((totals.conversions / totals.clicks) * 100).toFixed(2)}%` : '0%';
            case 'epc_confirmed': return totals.clicks > 0 ? `$${(totals.revenue_confirmed / totals.clicks).toFixed(2)}` : '$0';
            case 'cpc': return totals.clicks > 0 ? `$${(totals.cost / totals.clicks).toFixed(2)}` : '$0';
            case 'roi_confirmed': return totals.cost > 0 ? `${((totalsProfitConfirmed / totals.cost) * 100).toFixed(2)}%` : '—';
            default: return null;
        }
    };

    const columnLabel = (colId) => ({
        id: 'ID',
        name: t('editor.name'),
        state: t('components.status'),
        affiliate_network_name: t('offers.network'),
        group_name: t('components.group'),
        redirect_type: t('components.type'),
        geo: t('offerColumns.geo'),
        payout: t('offerColumns.payout'),
        clicks: t('components.clicks'),
        unique_clicks: t('components.uniques'),
        conversions: t('metrics.conversions'),
        leads: t('offerColumns.leads'),
        sales: t('offerColumns.sales'),
        rejected: t('offerColumns.rejected'),
        revenue: t('metrics.revenue'),
        revenue_confirmed: t('offerColumns.revenueConfirmed'),
        cost: t('offerColumns.cost'),
        cr: t('offerColumns.cr'),
        epc_confirmed: t('offerColumns.epcConfirmed'),
        cpc: t('offerColumns.cpc'),
        profit_confirmed: t('offerColumns.profitConfirmed'),
        roi_confirmed: t('offerColumns.roiConfirmed'),
    }[colId] || colId);

    const localizedColumns = ALL_OFFER_COLUMNS.map(c => ({ ...c, label: columnLabel(c.id) }));

    const money = (v) => `$${(parseFloat(v) || 0).toFixed(2)}`;

    const renderOfferCell = (offer, colId) => {
        const tdCls = ALL_OFFER_COLUMNS.find(c => c.id === colId)?.alignRight ? 'text-right' : '';
        switch (colId) {
            case 'id':
                return <td key={colId} className="font-medium">{offer.id}</td>;
            case 'name':
                return (
                    <td key={colId}>
                        <div className="flex flex-col">
                            <span
                                className="font-semibold cursor-pointer hover:underline"
                                style={{ color: 'var(--color-primary)' }}
                                onClick={() => handleEdit(offer.id)}
                            >
                                {offer.name}
                            </span>
                            {!offer.is_local && offer.url && (
                                <span style={{ color: 'var(--color-text-muted)', fontSize: '12px' }} className="truncate max-w-[200px]" title={offer.url}>
                                    {offer.url}
                                </span>
                            )}
                            {offer.is_local && (
                                <span style={{ color: 'var(--color-accent-purple)', fontSize: '12px' }}>{t('offers.localOffer')}</span>
                            )}
                        </div>
                    </td>
                );
            case 'state':
                return (
                    <td key={colId}>
                        <span className="flex items-center text-xs font-medium" style={{ color: offer.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}>
                            <span className="w-2 h-2 rounded-full mr-1.5" style={{ backgroundColor: offer.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}></span>
                            {offer.state === 'active' ? t('components.active') : t('components.archive')}
                        </span>
                    </td>
                );
            case 'affiliate_network_name':
                return <td key={colId} className={tdCls} style={{ color: 'var(--color-text-secondary)' }}>{offer.affiliate_network_name || '-'}</td>;
            case 'group_name':
                return <td key={colId} className={tdCls} style={{ color: 'var(--color-text-secondary)' }}>{offer.group_name || '-'}</td>;
            case 'geo':
                return <td key={colId} className={tdCls}><span className="px-2 py-1 rounded text-xs font-semibold" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>{offer.geo || t('offerColumns.allGeo')}</span></td>;
            case 'payout':
                return (
                    <td key={colId} className={tdCls} style={{ color: 'var(--color-text-secondary)' }}>
                        {offer.payout_auto ? t('offerColumns.payoutAuto') : `$${parseFloat(offer.payout_value || 0).toFixed(2)} (${String(offer.payout_type || 'cpa').toUpperCase()})`}
                    </td>
                );
            case 'redirect_type':
                return (
                    <td key={colId} className={tdCls}>
                        <span className="px-2 py-1 rounded text-xs font-semibold" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                            {offer.redirect_type === 'redirect' ? t('offers.redirect') :
                                offer.redirect_type === 'frame' ? t('offers.iframe') :
                                    offer.redirect_type === 'local' ? t('offers.local') :
                                        offer.redirect_type === 'js' ? t('redirectTypes.jsName') :
                                            offer.redirect_type === 'meta_refresh' ? t('redirectTypes.metaName') :
                                                offer.redirect_type === 'form_submit' ? t('redirectTypes.formName') :
                                                    offer.redirect_type === 'preload' ? t('offerEditor.preloadCurl') :
                                                        offer.redirect_type === 'curl_proxy' ? t('redirectTypes.curlProxyName') :
                                                            offer.redirect_type}
                        </span>
                    </td>
                );
            case 'clicks':
                return <td key={colId} className={`${tdCls} font-medium`}>{offer.clicks || 0}</td>;
            case 'conversions':
                return <td key={colId} className={`${tdCls} font-medium`} style={{ color: 'var(--color-success)' }}>{offer.conversions || 0}</td>;
            case 'revenue':
                return <td key={colId} className={`${tdCls} font-medium`} style={{ color: 'var(--color-success)' }}>{money(offer.revenue)}</td>;
            case 'revenue_confirmed':
                return <td key={colId} className={`${tdCls} font-medium`} style={{ color: 'var(--color-success)' }}>{money(offer.revenue_confirmed)}</td>;
            case 'cost':
                return <td key={colId} className={tdCls}>{money(offer.cost)}</td>;
            case 'profit_confirmed':
                return (
                    <td key={colId} className={`${tdCls} font-medium`} style={{ color: (parseFloat(offer.profit_confirmed) || 0) > 0 ? 'var(--color-success)' : (parseFloat(offer.profit_confirmed) || 0) < 0 ? 'var(--color-danger)' : 'var(--color-text-secondary)' }}>
                        {money(offer.profit_confirmed)}
                    </td>
                );
            case 'roi_confirmed':
                return (
                    <td key={colId} className={`${tdCls} font-medium`} style={{ color: (parseFloat(offer.roi_confirmed) || 0) > 0 ? 'var(--color-success)' : 'var(--color-text-secondary)' }}>
                        {offer.roi_confirmed !== null && offer.roi_confirmed !== undefined ? `${offer.roi_confirmed}%` : '—'}
                    </td>
                );
            case 'cr':
                return <td key={colId} className={tdCls}>{`${offer.cr || 0}%`}</td>;
            case 'epc_confirmed':
                return <td key={colId} className={tdCls}>{`$${(parseFloat(offer.epc_confirmed) || 0).toFixed(2)}`}</td>;
            case 'cpc':
                return <td key={colId} className={tdCls}>{`$${(parseFloat(offer.cpc) || 0).toFixed(2)}`}</td>;
            default:
                return <td key={colId} className={tdCls}>{offer[colId] || 0}</td>;
        }
    };

    const SortIcon = ({ colKey }) => {
        if (sortBy.key !== colKey) return <ChevronsUpDown className="w-3.5 h-3.5 opacity-60" />;
        return sortBy.dir === 'asc'
            ? <ChevronUp className="w-3.5 h-3.5" />
            : <ChevronDown className="w-3.5 h-3.5" />;
    };

    const SortableTh = ({ colKey, label, defaultDir = 'asc', alignRight = false }) => {
        const isActive = sortBy.key === colKey;
        return (
            <th className={alignRight ? 'text-right' : ''} aria-sort={isActive ? (sortBy.dir === 'asc' ? 'ascending' : 'descending') : 'none'}>
                <button
                    type="button"
                    onClick={() => requestSort(colKey, defaultDir)}
                    className={`inline-flex items-center gap-1 select-none ${alignRight ? 'justify-end w-full' : ''}`}
                    style={{ color: isActive ? 'var(--color-text-primary)' : 'var(--color-text-secondary)' }}
                    title={t('common.sort', 'Sort')}
                >
                    <span>{label}</span>
                    <SortIcon colKey={colKey} />
                </button>
            </th>
        );
    };

    const exportVisibleCsv = () => {
        const cols = [
            { key: 'id', label: 'id' },
            { key: 'name', label: 'name' },
            { key: 'group_name', label: 'group' },
            { key: 'affiliate_network_name', label: 'affiliate_network' },
            { key: 'redirect_type', label: 'type' },
            { key: 'state', label: 'state' },
            { key: 'clicks', label: 'clicks' },
            { key: 'unique_clicks', label: 'unique_clicks' },
            { key: 'conversions', label: 'conversions' },
            { key: 'revenue', label: 'revenue' },
            { key: 'url', label: 'url' },
        ];

        const escape = (v) => {
            const s = v === null || v === undefined ? '' : String(v);
            if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
            return s;
        };

        const header = cols.map(c => escape(c.label)).join(',');
        const lines = visibleOffers.map(o => cols.map(c => escape(o[c.key])).join(','));
        const csv = [header, ...lines].join('\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `offers_${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    };

    const handleRefresh = async () => {
        if (refreshing) return;
        setRefreshing(true);
        try {
            await Promise.resolve(refreshData?.());
        } finally {
            setRefreshing(false);
        }
    };

    return (
        <div className="page-card">
            <InfoBanner storageKey="help_offers" title={t('help.offerBannerTitle')}>
                <p>{t('help.offerBanner')}</p>
            </InfoBanner>
            {/* Header */}
            <div className="page-header">
                <div className="flex flex-wrap gap-3">
                    <button onClick={handleCreate} className="btn btn-primary">
                        <Plus className="w-4 h-4" />
                        {t('common.create')}
                    </button>
                    <button onClick={() => setIsGroupsModalOpen(true)} className="btn btn-secondary">
                        {t('campaigns.groups')}
                    </button>
                    {selectedOfferIds.size > 0 && (
                        <>
                            <button onClick={handleBulkCopySelected} className="btn btn-success" title={t('offers.copySelected')}>
                                <Copy className="w-4 h-4" />
                                {(t('offers.copySelected'))} ({selectedOfferIds.size})
                            </button>
                            <button onClick={handleBulkDeleteSelected} className="btn btn-danger" title={t('common.deleteSelected')}>
                                <Trash2 className="w-4 h-4" />
                                {(t('common.deleteSelected') || t('common.delete'))} ({selectedOfferIds.size})
                            </button>
                        </>
                    )}
                </div>
                <div className="flex gap-2">
                    {/* Columns Customizer Button [ ☵ ] */}
                    <button
                        type="button"
                        onClick={() => setColumnsModalOpen(true)}
                        className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5 font-medium"
                        title={t('columnsOrder.title')}
                        style={{
                            backgroundColor: 'var(--color-bg-card)',
                            border: '1px solid var(--color-border)',
                            color: 'var(--color-text-primary)'
                        }}
                    >
                        <SlidersHorizontal className="w-3.5 h-3.5" style={{ color: 'var(--color-primary)' }} />
                        <span>{t('reportCustomizer.columns')}</span>
                        <span className="text-[10px] px-1.5 py-0.2 rounded-full" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                            {chosenColumns.length}
                        </span>
                    </button>
                    <button
                        type="button"
                        onClick={() => setShowFilters(!showFilters)}
                        className={`btn btn-ghost ${showFilters ? 'bg-[var(--color-primary-light)]' : ''}`}
                        style={showFilters ? { color: 'var(--color-primary)' } : {}}
                    >
                        <Filter className="w-4 h-4" />
                        {t('editor.filters')}
                        {hasActiveFilters && (
                            <span className="ml-1 px-1.5 py-0.5 bg-[var(--color-primary)] text-white text-xs rounded-full">
                                {[filterGroup, filterNetwork, filterState].filter(Boolean).length}
                            </span>
                        )}
                    </button>
                    <button
                        type="button"
                        onClick={handleRefresh}
                        className="btn btn-ghost btn-icon"
                        title={t('common.refresh')}
                        disabled={refreshing}
                    >
                        <RefreshCw className={`w-5 h-5 ${refreshing ? 'animate-spin' : ''}`} />
                    </button>
                    <button
                        type="button"
                        className="btn btn-ghost btn-icon"
                        title={t('common.settings', 'Settings')}
                        onClick={() => setSettingsOpen(true)}
                    >
                        <Settings2 className="w-5 h-5" />
                    </button>
                </div>
            </div>

            {/* Filters Panel */}
            {showFilters && (
                <div className="flex flex-wrap gap-4 items-center py-4 mb-4 border-b" style={{ borderColor: 'var(--color-border)' }}>
                    <div className="flex items-center gap-2">
                        <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('components.group')}:</label>
                        <select
                            value={filterGroup}
                            onChange={(e) => setFilterGroup(e.target.value)}
                            className="form-select"
                            style={{ width: 'auto', minWidth: '140px' }}
                        >
                            <option value="">{t('common.all')}</option>
                            {groups.map(g => <option key={g} value={g}>{g}</option>)}
                        </select>
                    </div>
                    <div className="flex items-center gap-2">
                        <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('offers.network')}:</label>
                        <select
                            value={filterNetwork}
                            onChange={(e) => setFilterNetwork(e.target.value)}
                            className="form-select"
                            style={{ width: 'auto', minWidth: '160px' }}
                        >
                            <option value="">{t('common.all')}</option>
                            {networks.map(n => <option key={n} value={n}>{n}</option>)}
                        </select>
                    </div>
                    <div className="flex items-center gap-2">
                        <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('components.status')}:</label>
                        <select
                            value={filterState}
                            onChange={(e) => setFilterState(e.target.value)}
                            className="form-select"
                            style={{ width: 'auto', minWidth: '120px' }}
                        >
                            <option value="">{t('common.all')}</option>
                            <option value="active">{t('components.active')}</option>
                            <option value="archived">{t('components.archive')}</option>
                        </select>
                    </div>
                    {hasActiveFilters && (
                        <button onClick={clearFilters} className="btn btn-ghost btn-sm">
                            <X className="w-4 h-4" />
                            {t('common.clear')}
                        </button>
                    )}
                </div>
            )}

            {/* Table */}
            <div className="overflow-x-auto">
                <table className="page-table">
                    <thead>
                        <tr>
                            <th className="w-10">
                                <input
                                    type="checkbox"
                                    checked={allFilteredSelected}
                                    ref={(el) => {
                                        if (el) el.indeterminate = !allFilteredSelected && someFilteredSelected;
                                    }}
                                    onChange={(e) => toggleSelectAllFiltered(e.target.checked)}
                                />
                            </th>
                            {chosenColumns.map((colId) => {
                                const col = ALL_OFFER_COLUMNS.find(c => c.id === colId);
                                if (!col) return null;
                                return (
                                    <SortableTh
                                        key={colId}
                                        colKey={colId}
                                        label={columnLabel(colId)}
                                        defaultDir={col.alignRight ? 'desc' : 'asc'}
                                        alignRight={col.alignRight}
                                    />
                                );
                            })}
                            <th className="text-right">{t('common.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {visibleOffers.length === 0 ? (
                            <tr>
                                <td colSpan={chosenColumns.length + 2} className="text-center py-12">
                                    <div className="empty-state">
                                        <p className="empty-state-title">
                                            {offers.length === 0 ? t('offers.noOffers') : t('offers.noOffersFiltered')}
                                        </p>
                                        <p className="empty-state-text">
                                            {offers.length === 0 ? t('offers.noOffersDesc') : t('offers.changeFilters')}
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        ) : (
                            visibleOffers.map((offer) => (
                                <tr key={offer.id}>
                                    <td>
                                        <input
                                            type="checkbox"
                                            checked={selectedOfferIds.has(offer.id)}
                                            onChange={(e) => toggleSelected(offer.id, e.target.checked)}
                                        />
                                    </td>
                                    {chosenColumns.map((colId) => renderOfferCell(offer, colId))}
                                    <td>
                                        <div className="action-buttons">
                                            <button onClick={() => handleEdit(offer.id)} className="action-btn text-blue" title={t('common.edit') || t('components.edit')}>
                                                <Edit3 className="w-4 h-4" />
                                            </button>
                                            <button onClick={() => handleDelete(offer.id)} className="action-btn text-red" title={t('common.delete')}>
                                                <Trash2 className="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                    {/* Totals Footer */}
                    {filteredOffers.length > 0 && (
                        <tfoot style={{ background: 'var(--color-bg-soft)' }}>
                            <tr className="font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                <td className="px-4 py-3"></td>
                                {chosenColumns.map((colId) => {
                                    if (colId === 'name') {
                                        return <td key={colId} className="px-4 py-3">{t('offers.total').replace('{count}', filteredOffers.length)}</td>;
                                    }
                                    const val = renderTotalCell(colId);
                                    const alignRight = ALL_OFFER_COLUMNS.find(c => c.id === colId)?.alignRight;
                                    return <td key={colId} className={`px-4 py-3 ${alignRight ? 'text-right' : ''}`} style={{ color: colId === 'profit_confirmed' && totalsProfitConfirmed < 0 ? 'var(--color-danger)' : undefined }}>{val ?? ''}</td>;
                                })}
                                <td></td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>

            {/* Editor Modal */}
            {isEditorOpen && (
                <OfferEditor
                    offerId={editingOfferId}
                    onClose={handleEditorClose}
                />
            )}

            {/* Groups Modal */}
            {isGroupsModalOpen && (
                <GroupsModal
                    type="offer"
                    onClose={() => setIsGroupsModalOpen(false)}
                />
            )}

            {columnsModalOpen && (
                <ColumnsOrderModal
                    columns={localizedColumns}
                    selectedIds={chosenColumns}
                    defaultIds={DEFAULT_OFFER_COLUMNS}
                    onClose={() => setColumnsModalOpen(false)}
                    onSave={(ids) => {
                        setChosenColumns(ids);
                        localStorage.setItem(OFFER_COLUMNS_KEY, JSON.stringify(ids));
                        setColumnsModalOpen(false);
                    }}
                />
            )}

            {settingsOpen && (
                <div className="modal-overlay" onClick={() => setSettingsOpen(false)}>
                    <div className="modal-content" onClick={(e) => e.stopPropagation()} style={{ maxWidth: '560px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title">{t('common.settings', 'Settings')}</h3>
                            <button type="button" className="btn btn-ghost btn-icon" onClick={() => setSettingsOpen(false)} title={t('common.close')}>
                                <X className="w-5 h-5" />
                            </button>
                        </div>
                        <div className="space-y-3">
                            <button type="button" className="btn btn-secondary w-full" onClick={() => { setSortBy({ key: null, dir: 'desc' }); }}>
                                {t('common.resetSort', 'Reset sorting')}
                            </button>
                            <button type="button" className="btn btn-secondary w-full" onClick={() => { setSelectedOfferIds(new Set()); }}>
                                {t('common.clearSelection', 'Clear selection')}
                            </button>
                            <button type="button" className="btn btn-primary w-full" onClick={exportVisibleCsv}>
                                {t('common.exportCsv', 'Export CSV')}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default Offers;
