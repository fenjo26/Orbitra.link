import React, { useState, useMemo, useEffect } from 'react';
import { Plus, Trash2, Edit3, Settings2, DollarSign, XCircle, ChevronUp, ChevronDown, ChevronsUpDown, Filter, RefreshCw, X, Copy, BarChart2, SlidersHorizontal, GripVertical } from 'lucide-react';
import InfoBanner from './InfoBanner';
import GroupsModal from './GroupsModal';
import CampaignReports from './CampaignReports';
import DateRangePicker, { formatDate, getPresetDates } from './DateRangePicker';
import ReportCustomizerModal, { ALL_REPORT_METRICS, PRESETS } from './ReportCustomizerModal';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const Campaigns = ({ campaigns: initialCampaigns, refreshData, setActiveTab, setEditingCampaignId }) => {
    const { t } = useLanguage();
    const [actionModal, setActionModal] = useState({ type: null, campaignId: null });
    const [selectedCampaignIds, setSelectedCampaignIds] = useState(() => new Set());
    const [sortBy, setSortBy] = useState({ key: null, dir: 'desc' }); // key=null keeps API order
    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState('');
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [showGroupsModal, setShowGroupsModal] = useState(false);
    const [showGlobalReports, setShowGlobalReports] = useState(false);

    // Date & Timezone Range Picker State
    const todayPreset = getPresetDates('today');
    const [dateFrom, setDateFrom] = useState(todayPreset?.from || formatDate(new Date()));
    const [dateTo, setDateTo] = useState(todayPreset?.to || formatDate(new Date()));
    const [timezone, setTimezone] = useState(() => localStorage.getItem('orbitra_tz') || 'UTC');

    // Group Filtering State
    const [groups, setGroups] = useState([]);
    const [selectedGroupId, setSelectedGroupId] = useState('');

    // Active Campaign Data (fetched with date & group parameters)
    const [campaignList, setCampaignList] = useState(initialCampaigns || []);

    // Column Customizer State & Presets
    const [columnsFilterOpen, setColumnsFilterOpen] = useState(false);
    const [chosenColumns, setChosenColumns] = useState(() => {
        try {
            const saved = localStorage.getItem('orbitra_campaign_columns');
            if (saved) return JSON.parse(saved);
        } catch (e) {}
        return [...PRESETS.best];
    });

    // Header Drag-and-Drop state
    const [thDragIdx, setThDragIdx] = useState(null);

    // Fetch groups on mount
    const fetchGroups = () => {
        axios.get(`${API_URL}?action=campaign_groups`)
            .then(res => {
                if (res.data.status === 'success') {
                    setGroups(res.data.data || []);
                }
            })
            .catch(() => {});
    };

    useEffect(() => {
        fetchGroups();
    }, []);

    // Fetch campaigns with date_from, date_to, group_id
    const fetchCampaigns = async () => {
        setRefreshing(true);
        try {
            const params = {
                date_from: dateFrom,
                date_to: dateTo
            };
            if (selectedGroupId) params.group_id = selectedGroupId;
            const res = await axios.get(`${API_URL}?action=campaigns`, { params });
            if (res.data.status === 'success') {
                setCampaignList(res.data.data || []);
            }
        } catch (e) {
            console.error(e);
        } finally {
            setRefreshing(false);
        }
    };

    useEffect(() => {
        fetchCampaigns();
    }, [dateFrom, dateTo, selectedGroupId]);

    const handleDateChange = (from, to) => {
        setDateFrom(from);
        setDateTo(to);
    };

    const handleSaveColumns = (cols) => {
        setChosenColumns(cols);
        localStorage.setItem('orbitra_campaign_columns', JSON.stringify(cols));
    };

    const handleThDragStart = (idx) => {
        setThDragIdx(idx);
    };

    const handleThDragOver = (e, idx) => {
        e.preventDefault();
        if (thDragIdx === null || thDragIdx === idx) return;
        const copy = [...chosenColumns];
        const item = copy.splice(thDragIdx, 1)[0];
        copy.splice(idx, 0, item);
        setThDragIdx(idx);
        setChosenColumns(copy);
        localStorage.setItem('orbitra_campaign_columns', JSON.stringify(copy));
    };

    const handleThDragEnd = () => {
        setThDragIdx(null);
    };

    const handleCreate = () => {
        setEditingCampaignId(null);
        setActiveTab('campaign_editor');
    };

    const handleEdit = (id) => {
        setEditingCampaignId(id);
        setActiveTab('campaign_editor');
    };

    const handleDelete = async (id) => {
        if (window.confirm(t('campaigns.deleteConfirm'))) {
            try {
                await axios.post(`${API_URL}?action=delete_campaign`, { id });
                fetchCampaigns();
                if (refreshData) refreshData();
            } catch (err) {
                alert(t('common.deleteError'));
            }
        }
    };

    const requestSort = (key, defaultDir = 'asc') => {
        setSortBy(prev => {
            if (prev.key === key) {
                return { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' };
            }
            return { key, dir: defaultDir };
        });
    };

    const filteredCampaigns = useMemo(() => {
        const q = String(search || '').trim().toLowerCase();
        if (!q) return campaignList;
        return campaignList.filter(c => {
            const n = String(c.name || '').toLowerCase();
            const a = String(c.alias || '').toLowerCase();
            return n.includes(q) || a.includes(q);
        });
    }, [campaignList, search]);

    const visibleCampaigns = useMemo(() => {
        if (!sortBy.key) return filteredCampaigns;
        const dirMul = sortBy.dir === 'asc' ? 1 : -1;

        return filteredCampaigns
            .map((camp, idx) => ({ camp, idx }))
            .sort((a, b) => {
                const av = a.camp[sortBy.key];
                const bv = b.camp[sortBy.key];
                let cmp = 0;
                if (typeof av === 'number' || typeof bv === 'number' || !isNaN(Number(av))) {
                    cmp = (Number(av) || 0) - (Number(bv) || 0);
                } else {
                    cmp = String(av || '').localeCompare(String(bv || ''), undefined, { sensitivity: 'base' });
                }
                if (cmp !== 0) return cmp * dirMul;
                return a.idx - b.idx; // stable
            })
            .map(x => x.camp);
    }, [filteredCampaigns, sortBy]);

    // Grand Totals Calculation across all visible campaigns
    const grandTotals = useMemo(() => {
        const t0 = {
            clicks: 0,
            unique_clicks: 0,
            prelander_clicks: 0,
            offer_clicks: 0,
            conversions: 0,
            purchases: 0,
            holds: 0,
            rejected: 0,
            trash: 0,
            cost: 0,
            revenue: 0,
            revenue_confirmed: 0,
            revenue_hold: 0,
            revenue_rejected: 0,
            revenue_trash: 0,
            profit: 0,
            real_revenue: 0,
            real_profit: 0,
        };

        visibleCampaigns.forEach(c => {
            t0.clicks += Number(c.clicks) || 0;
            t0.unique_clicks += Number(c.unique_clicks) || 0;
            t0.prelander_clicks += Number(c.prelander_clicks) || 0;
            t0.offer_clicks += Number(c.offer_clicks) || 0;
            t0.conversions += Number(c.conversions) || 0;
            t0.purchases += Number(c.purchases) || 0;
            t0.holds += Number(c.holds) || 0;
            t0.rejected += Number(c.rejected) || 0;
            t0.trash += Number(c.trash) || 0;
            t0.cost += Number(c.cost) || 0;
            t0.revenue += Number(c.revenue) || 0;
            t0.revenue_confirmed += Number(c.revenue_confirmed) || 0;
            t0.revenue_hold += Number(c.revenue_hold) || 0;
            t0.revenue_rejected += Number(c.revenue_rejected) || 0;
            t0.revenue_trash += Number(c.revenue_trash) || 0;
            t0.profit += Number(c.profit) || (Number(c.revenue || 0) - Number(c.cost || 0));
            t0.real_revenue += Number(c.real_revenue) || 0;
            t0.real_profit += Number(c.real_profit) || 0;
        });

        // Calculated composite metrics
        const uc_rate = t0.clicks > 0 ? (t0.unique_clicks / t0.clicks) * 100 : 0;
        const lp_ctr = t0.clicks > 0 ? (t0.offer_clicks / t0.clicks) * 100 : 0;
        const cr = t0.clicks > 0 ? (t0.conversions / t0.clicks) * 100 : 0;
        const cr_sales = t0.clicks > 0 ? (t0.purchases / t0.clicks) * 100 : 0;
        const cr_holds = t0.clicks > 0 ? (t0.holds / t0.clicks) * 100 : 0;
        const approve_rate = t0.conversions > 0 ? (t0.purchases / t0.conversions) * 100 : 0;
        const nonTrash = t0.purchases + t0.holds + t0.rejected;
        const approve_rate_excl_trash = nonTrash > 0 ? (t0.purchases / nonTrash) * 100 : 0;
        const roi = t0.cost > 0 ? (t0.profit / t0.cost) * 100 : 0;
        const real_roi = t0.cost > 0 ? (t0.real_profit / t0.cost) * 100 : 0;
        const epc = t0.clicks > 0 ? t0.revenue / t0.clicks : 0;
        const uepc = t0.unique_clicks > 0 ? t0.revenue / t0.unique_clicks : 0;
        const cpc = t0.clicks > 0 ? t0.cost / t0.clicks : 0;
        const ucpc = t0.unique_clicks > 0 ? t0.cost / t0.unique_clicks : 0;
        const cpa = t0.conversions > 0 ? t0.cost / t0.conversions : 0;
        const earnings_per_conv = t0.conversions > 0 ? t0.revenue / t0.conversions : 0;

        return {
            ...t0,
            uc_rate,
            lp_ctr,
            cr,
            cr_sales,
            cr_holds,
            approve_rate,
            approve_rate_excl_trash,
            roi,
            real_roi,
            epc,
            uepc,
            cpc,
            ucpc,
            cpa,
            earnings_per_conv
        };
    }, [visibleCampaigns]);

    const toggleSelected = (id, checked) => {
        setSelectedCampaignIds(prev => {
            const next = new Set(prev);
            if (checked) next.add(id);
            else next.delete(id);
            return next;
        });
    };

    const toggleSelectAll = (checked) => {
        setSelectedCampaignIds(prev => {
            const next = new Set(prev);
            if (checked) {
                visibleCampaigns.forEach(c => next.add(c.id));
            } else {
                visibleCampaigns.forEach(c => next.delete(c.id));
            }
            return next;
        });
    };

    const allSelected = visibleCampaigns.length > 0 && visibleCampaigns.every(c => selectedCampaignIds.has(c.id));
    const someSelected = visibleCampaigns.some(c => selectedCampaignIds.has(c.id));

    const handleBulkDeleteSelected = async () => {
        const ids = Array.from(selectedCampaignIds);
        if (ids.length === 0) return;
        const msg = (t('common.deleteSelectedConfirm') || t('campaigns.deleteConfirm')).replace('{count}', String(ids.length));
        if (!window.confirm(msg)) return;
        try {
            await axios.post(`${API_URL}?action=bulk_delete_campaigns`, { ids });
            setSelectedCampaignIds(new Set());
            fetchCampaigns();
            if (refreshData) refreshData();
        } catch (err) {
            alert(t('common.deleteError'));
        }
    };

    const handleBulkCopySelected = async () => {
        const ids = Array.from(selectedCampaignIds);
        if (ids.length === 0) return;

        const confirmMsg = t('campaigns.bulkCopyConfirm');
        if (!window.confirm(confirmMsg)) return;

        let successCount = 0;
        let errorCount = 0;

        for (const id of ids) {
            try {
                await axios.post(`${API_URL}?action=copy_campaign`, { id });
                successCount++;
            } catch (err) {
                errorCount++;
            }
        }

        if (successCount > 0) {
            alert(`${t('campaigns.copied')}: ${successCount}`);
            fetchCampaigns();
            if (refreshData) refreshData();
        }
        if (errorCount > 0) {
            alert(`${t('campaigns.copyErrors')}: ${errorCount}`);
        }

        setSelectedCampaignIds(new Set());
    };

    const SortIcon = ({ colKey }) => {
        if (sortBy.key !== colKey) return <ChevronsUpDown className="w-3.5 h-3.5 opacity-40" />;
        return sortBy.dir === 'asc'
            ? <ChevronUp className="w-3.5 h-3.5" style={{ color: 'var(--color-primary)' }} />
            : <ChevronDown className="w-3.5 h-3.5" style={{ color: 'var(--color-primary)' }} />;
    };

    const SortableTh = ({ colKey, label, fullTitle, defaultDir = 'asc', alignRight = false, draggable = false, onDragStart, onDragOver, onDragEnd }) => {
        const isActive = sortBy.key === colKey;
        return (
            <th
                className={`${alignRight ? 'text-right' : 'text-left'} whitespace-nowrap`}
                aria-sort={isActive ? (sortBy.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
                title={fullTitle}
                draggable={draggable}
                onDragStart={onDragStart}
                onDragOver={onDragOver}
                onDragEnd={onDragEnd}
                style={{
                    textAlign: alignRight ? 'right' : 'left',
                    cursor: draggable ? 'grab' : 'pointer',
                    userSelect: 'none'
                }}
            >
                <button
                    type="button"
                    onClick={() => requestSort(colKey, defaultDir)}
                    className={`inline-flex items-center gap-1 text-xs font-semibold whitespace-nowrap ${alignRight ? 'justify-end w-full' : ''}`}
                    style={{
                        color: isActive ? 'var(--color-primary)' : 'var(--color-text-secondary)',
                        textAlign: alignRight ? 'right' : 'left'
                    }}
                >
                    {draggable && <GripVertical className="w-3 h-3 opacity-30 -ml-1 cursor-grab flex-shrink-0" />}
                    <span>{label}</span>
                    <SortIcon colKey={colKey} />
                </button>
            </th>
        );
    };

    const formatMetricCell = (metricId, row) => {
        const val = row[metricId];
        const num = Number(val) || 0;

        switch (metricId) {
            case 'clicks':
            case 'unique_clicks':
            case 'visitors':
            case 'unique_clicks_stream':
            case 'unique_clicks_global':
            case 'bots':
            case 'proxies':
            case 'empty_referrers':
            case 'prelander_clicks':
            case 'offer_clicks':
            case 'lp_views':
            case 'lp_clicks':
            case 'purchases':
            case 'sales':
            case 'holds':
            case 'leads':
            case 'registrations':
            case 'deposits':
            case 'rejected':
            case 'trash':
                return num.toLocaleString();

            case 'conversions':
                return num > 0 ? <span className="font-semibold" style={{ color: 'var(--color-success)' }}>{num.toLocaleString()}</span> : '0';

            case 'profitability':
            case 'uc_rate':
            case 'uc_rate_stream':
            case 'uc_rate_global':
            case 'bot_rate':
            case 'lp_ctr':
            case 'approve_rate':
            case 'approve_rate_excl_trash':
            case 'cr':
            case 'cr_all':
            case 'cr_sales':
            case 'cr_holds':
            case 'cr_leads':
            case 'cr_registrations':
            case 'cr_deposits':
            case 'cr_regs_to_deps':
            case 'ucr':
                return `${num.toFixed(2)}%`;

            case 'roi':
            case 'roi_all':
            case 'roi_confirmed':
            case 'real_roi': {
                if (val === null || val === undefined) return '—';
                const isPos = num >= 0;
                return (
                    <span style={{ color: isPos ? 'var(--color-success)' : 'var(--color-danger)', fontWeight: 600 }}>
                        {isPos ? '+' : ''}{num.toFixed(2)}%
                    </span>
                );
            }

            case 'profit':
            case 'profit_all':
            case 'profit_confirmed':
            case 'real_profit': {
                const isPos = num >= 0;
                return (
                    <span style={{ color: isPos ? 'var(--color-success)' : 'var(--color-danger)', fontWeight: 600 }}>
                        {isPos ? '+' : ''}${num.toFixed(2)}
                    </span>
                );
            }

            case 'cost':
            case 'revenue':
            case 'revenue_all':
            case 'revenue_confirmed':
            case 'revenue_hold':
            case 'revenue_rejected':
            case 'revenue_trash':
            case 'revenue_registration':
            case 'revenue_deposit':
            case 'real_revenue':
            case 'cpa':
            case 'cps':
            case 'cpl':
            case 'cpr':
            case 'cpd':
            case 'ecpc':
            case 'ecpm_all':
            case 'ecpm_confirmed':
            case 'earnings_per_conv':
            case 'ec_all':
            case 'ec_confirmed':
                return `$${num.toFixed(2)}`;

            case 'epc':
            case 'epc_all':
            case 'uepc':
            case 'uepc_all':
            case 'epc_confirmed':
            case 'uepc_confirmed':
            case 'epc_hold':
            case 'uepc_hold':
            case 'epc_registration':
            case 'uepc_registration':
            case 'cpc':
            case 'ucpc':
                return `$${num.toFixed(4)}`;

            default:
                return val !== undefined && val !== null ? String(val) : '-';
        }
    };

    const handleClearStats = async () => {
        try {
            await axios.post(`${API_URL}?action=clear_stats`, { campaign_id: actionModal.campaignId });
            fetchCampaigns();
            if (refreshData) refreshData();
            setActionModal({ type: null, campaignId: null });
        } catch (err) {
            alert(t('common.clearError'));
        }
    };

    const handleUpdateCosts = async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const data = {
            campaign_id: actionModal.campaignId,
            cost: parseFloat(fd.get('cost')),
            start_date: fd.get('start_date'),
            end_date: fd.get('end_date'),
            unique_only: fd.get('unique_only') === 'on'
        };
        try {
            const res = await axios.post(`${API_URL}?action=update_costs`, data);
            if (res.data.status === 'success') {
                alert(t('campaigns.updatedClicks').replace('{count}', res.data.updated_clicks));
                fetchCampaigns();
                if (refreshData) refreshData();
                setActionModal({ type: null, campaignId: null });
            } else {
                alert(res.data.message);
            }
        } catch (err) {
            alert(t('common.networkError'));
        }
    };

    return (
        <div className="page-card">
            <InfoBanner storageKey="help_campaigns" title={t('help.campaignBannerTitle')}>
                <p>{t('help.campaignBanner')}</p>
            </InfoBanner>

            {/* Toolbar Header */}
            <div className="page-header flex-wrap gap-4">
                {/* Left Side Action Buttons */}
                <div className="flex flex-wrap gap-2.5 items-center">
                    <button onClick={handleCreate} className="btn btn-primary text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5 font-medium">
                        <Plus className="w-3.5 h-3.5" />
                        {t('common.create')}
                    </button>
                    <button onClick={() => setShowGlobalReports(true)} className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5 font-medium" title={t('campaignReports.report')}>
                        <BarChart2 className="w-3.5 h-3.5" />
                        {t('campaignReports.report')}
                    </button>
                    <button onClick={() => setShowGroupsModal(true)} className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl font-medium">
                        {t('campaigns.groups')}
                    </button>
                    <button onClick={() => setActiveTab('sources')} className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl font-medium">
                        {t('campaigns.sources')}
                    </button>

                    {/* Columns Customizer Button [ ☵ ] */}
                    <button
                        type="button"
                        onClick={() => setColumnsFilterOpen(true)}
                        className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5 font-medium"
                        title={t('reportCustomizer.campaignColumnsTitle')}
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

                    {selectedCampaignIds.size > 0 && (
                        <>
                            <button onClick={handleBulkCopySelected} className="btn btn-success text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5" title={t('campaigns.copySelected')}>
                                <Copy className="w-3.5 h-3.5" />
                                {(t('campaigns.copySelected'))} ({selectedCampaignIds.size})
                            </button>
                            <button onClick={handleBulkDeleteSelected} className="btn btn-danger text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5" title={t('common.deleteSelected')}>
                                <Trash2 className="w-3.5 h-3.5" />
                                {(t('common.deleteSelected') || t('common.delete'))} ({selectedCampaignIds.size})
                            </button>
                        </>
                    )}
                </div>

                {/* Right Side Filters, DateRangePicker, and Group Filter */}
                <div className="flex flex-wrap items-center gap-2.5">
                    {/* Group Filter Dropdown */}
                    <select
                        value={selectedGroupId}
                        onChange={(e) => setSelectedGroupId(e.target.value)}
                        className="form-select text-xs py-1.5 px-3 rounded-xl"
                        style={{ width: '150px' }}
                    >
                        <option value="">{t('campaigns.allGroups', 'All groups')}</option>
                        {groups.map(g => (
                            <option key={g.id} value={g.id}>{g.name}</option>
                        ))}
                    </select>

                    {/* Interactive DateRangePicker with Timezones */}
                    <DateRangePicker
                        dateFrom={dateFrom}
                        dateTo={dateTo}
                        onChange={handleDateChange}
                        selectedTimezone={timezone}
                        onTimezoneChange={setTimezone}
                    />

                    {/* Search / Filter Toggle */}
                    <button
                        type="button"
                        onClick={() => setShowFilters(!showFilters)}
                        className={`btn btn-ghost text-xs py-1.5 px-2.5 rounded-xl ${showFilters ? 'bg-[var(--color-primary-light)]' : ''}`}
                        style={showFilters ? { color: 'var(--color-primary)' } : {}}
                    >
                        <Filter className="w-3.5 h-3.5" />
                        {search ? (
                            <span className="ml-1 px-1.5 py-0.5 bg-[var(--color-primary)] text-white text-[10px] rounded-full">1</span>
                        ) : null}
                    </button>

                    {/* Refresh */}
                    <button
                        type="button"
                        onClick={fetchCampaigns}
                        className="btn btn-ghost btn-icon p-1.5 rounded-xl"
                        title={t('common.refresh')}
                        disabled={refreshing}
                    >
                        <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin' : ''}`} />
                    </button>
                </div>
            </div>

            {/* Quick Filter Bar */}
            {showFilters && (
                <div className="flex flex-wrap gap-4 items-center py-3 px-4 mb-4 rounded-xl" style={{ backgroundColor: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                    <div className="flex items-center gap-2 flex-1 max-w-sm">
                        <label className="text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>{t('common.search', 'Search')}:</label>
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="form-input text-xs py-1.5 px-3 rounded-xl flex-1"
                            placeholder={t('common.searchPlaceholder', 'Name or alias...')}
                        />
                    </div>
                    {search && (
                        <button type="button" onClick={() => setSearch('')} className="btn btn-ghost text-xs py-1 px-2">
                            <X className="w-3.5 h-3.5" />
                            {t('common.clear')}
                        </button>
                    )}
                </div>
            )}

            {/* Main Campaigns Data Table */}
            <div className="overflow-x-auto">
                <table className="page-table" style={{ fontVariantNumeric: 'tabular-nums' }}>
                    <thead>
                        <tr>
                            <th className="w-8" style={{ textAlign: 'left' }}>
                                <input
                                    type="checkbox"
                                    checked={allSelected}
                                    ref={(el) => {
                                        if (el) el.indeterminate = !allSelected && someSelected;
                                    }}
                                    onChange={(e) => toggleSelectAll(e.target.checked)}
                                    className="w-3.5 h-3.5 rounded"
                                    style={{ accentColor: 'var(--color-primary)' }}
                                />
                            </th>
                            <SortableTh colKey="id" label="ID" defaultDir="desc" />
                            <SortableTh colKey="name" label={t('campaigns.campaign')} defaultDir="asc" />
                            <SortableTh colKey="group_name" label={t('campaigns.group')} defaultDir="asc" />

                            {/* Dynamically configured metric columns */}
                            {chosenColumns.map((colId, colIdx) => {
                                const def = ALL_REPORT_METRICS.find(m => m.id === colId);
                                return (
                                    <SortableTh
                                        key={colId}
                                        colKey={colId}
                                        label={def?.shortLabel || def?.label || colId}
                                        fullTitle={def?.label}
                                        defaultDir="desc"
                                        alignRight={true}
                                        draggable={true}
                                        onDragStart={() => handleThDragStart(colIdx)}
                                        onDragOver={(e) => handleThDragOver(e, colIdx)}
                                        onDragEnd={handleThDragEnd}
                                    />
                                );
                            })}

                            <th className="text-right" style={{ textAlign: 'right' }}>{t('common.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {visibleCampaigns.length === 0 ? (
                            <tr>
                                <td colSpan={5 + chosenColumns.length} className="text-center py-12">
                                    <div className="empty-state">
                                        <p className="empty-state-title">{t('campaigns.noCampaignsCreated')}</p>
                                        <p className="empty-state-text">{t('campaigns.createFirstCampaign')}</p>
                                    </div>
                                </td>
                            </tr>
                        ) : (
                            visibleCampaigns.map((camp) => (
                                <tr key={camp.id}>
                                    <td>
                                        <input
                                            type="checkbox"
                                            checked={selectedCampaignIds.has(camp.id)}
                                            onChange={(e) => toggleSelected(camp.id, e.target.checked)}
                                            className="w-3.5 h-3.5 rounded"
                                            style={{ accentColor: 'var(--color-primary)' }}
                                        />
                                    </td>
                                    <td className="font-medium">
                                        <span title={camp.keitaro_id ? `Keitaro ID: ${camp.keitaro_id}` : ''}>{camp.id}</span>
                                    </td>
                                    <td>
                                        <div className="flex flex-col">
                                            <span
                                                className="font-semibold cursor-pointer hover:underline"
                                                style={{ color: 'var(--color-primary)' }}
                                                onClick={() => handleEdit(camp.id)}
                                            >
                                                {camp.name}
                                            </span>
                                            <span style={{ color: 'var(--color-text-muted)', fontSize: '11px' }}>{camp.alias}</span>
                                        </div>
                                    </td>
                                    <td style={{ color: 'var(--color-text-secondary)' }}>{camp.group_name || '-'}</td>

                                    {/* Render dynamic metric cells */}
                                    {chosenColumns.map((colId) => (
                                        <td key={colId} style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                                            {formatMetricCell(colId, camp)}
                                        </td>
                                    ))}

                                    <td style={{ textAlign: 'right' }}>
                                        <div className="action-buttons justify-end">
                                            <button onClick={() => handleEdit(camp.id)} className="action-btn text-blue" title={t('common.edit')}>
                                                <Edit3 className="w-3.5 h-3.5" />
                                            </button>
                                            <button onClick={() => setActionModal({ type: 'update_costs', campaignId: camp.id })} className="action-btn text-green" title={t('campaigns.updateCosts')}>
                                                <DollarSign className="w-3.5 h-3.5" />
                                            </button>
                                            <button onClick={() => setActionModal({ type: 'clear_stats', campaignId: camp.id })} className="action-btn text-orange" title={t('common.clearStats')}>
                                                <XCircle className="w-3.5 h-3.5" />
                                            </button>
                                            <button onClick={() => handleDelete(camp.id)} className="action-btn text-red" title={t('common.delete')}>
                                                <Trash2 className="w-3.5 h-3.5" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>

                    {/* Sticky Grand Totals Footer */}
                    {visibleCampaigns.length > 0 && (
                        <tfoot>
                            <tr style={{ backgroundColor: 'var(--color-bg-soft)', borderTop: '2px solid var(--color-border)', fontWeight: 700 }}>
                                <td></td>
                                <td>Σ</td>
                                <td>{t('campaignReports.total', 'Totals')} ({visibleCampaigns.length})</td>
                                <td>-</td>
                                {chosenColumns.map(colId => (
                                    <td key={colId} style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                                        {formatMetricCell(colId, grandTotals)}
                                    </td>
                                ))}
                                <td></td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>

            {/* Columns Customizer Modal [ ☵ ] */}
            <ReportCustomizerModal
                isOpen={columnsFilterOpen}
                onClose={() => setColumnsFilterOpen(false)}
                selectedColumns={chosenColumns}
                onSaveColumns={handleSaveColumns}
                mode="campaigns"
            />

            {/* Clear Stats Modal */}
            {actionModal.type === 'clear_stats' && (
                <div className="modal-overlay">
                    <div className="modal-content max-w-md w-full rounded-2xl p-6" style={{ backgroundColor: 'var(--color-bg-card)', border: '1px solid var(--color-border)' }}>
                        <div className="modal-header pb-3 mb-4" style={{ borderBottom: '1px solid var(--color-border)' }}>
                            <h3 className="modal-title font-bold text-base">{t('common.clearStats')}?</h3>
                        </div>
                        <p style={{ color: 'var(--color-text-secondary)', fontSize: '13px', marginBottom: '24px' }}>
                            {t('campaigns.clearStatsWarning')}
                        </p>
                        <div className="modal-footer flex justify-end gap-2">
                            <button onClick={() => setActionModal({ type: null, campaignId: null })} className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl">{t('common.cancel')}</button>
                            <button onClick={handleClearStats} className="btn btn-danger text-xs py-1.5 px-4 rounded-xl">{t('common.clear')}</button>
                        </div>
                    </div>
                </div>
            )}

            {/* Update Costs Modal */}
            {actionModal.type === 'update_costs' && (
                <div className="modal-overlay">
                    <div className="modal-content max-w-md w-full rounded-2xl p-6" style={{ backgroundColor: 'var(--color-bg-card)', border: '1px solid var(--color-border)' }}>
                        <div className="modal-header pb-3 mb-4" style={{ borderBottom: '1px solid var(--color-border)' }}>
                            <h3 className="modal-title font-bold text-base">{t('campaigns.updateCosts')}</h3>
                        </div>
                        <form onSubmit={handleUpdateCosts} className="space-y-4">
                            <div>
                                <label className="form-label text-xs">{t('campaigns.costAmount')}</label>
                                <input type="number" step="0.01" name="cost" required className="form-input text-xs py-2 rounded-xl" placeholder="0.00" />
                            </div>
                            <div className="flex gap-3">
                                <div className="flex-1">
                                    <label className="form-label text-xs">{t('campaigns.startDate')}</label>
                                    <input type="date" name="start_date" required defaultValue={dateFrom} className="form-input text-xs py-2 rounded-xl" />
                                </div>
                                <div className="flex-1">
                                    <label className="form-label text-xs">{t('campaigns.endDate')}</label>
                                    <input type="date" name="end_date" required defaultValue={dateTo} className="form-input text-xs py-2 rounded-xl" />
                                </div>
                            </div>
                            <div>
                                <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--color-text-primary)' }}>
                                    <input type="checkbox" name="unique_only" className="w-3.5 h-3.5 rounded" style={{ accentColor: 'var(--color-primary)' }} />
                                    <span>{t('campaigns.distributeUniqueOnly')}</span>
                                </label>
                            </div>
                            <div className="modal-footer flex justify-end gap-2 pt-3" style={{ borderTop: '1px solid var(--color-border)' }}>
                                <button type="button" onClick={() => setActionModal({ type: null, campaignId: null })} className="btn btn-ghost text-xs py-1.5 px-3 rounded-xl">{t('common.cancel')}</button>
                                <button type="submit" className="btn btn-primary text-xs py-1.5 px-4 rounded-xl flex items-center gap-1.5 font-medium">
                                    <DollarSign className="w-3.5 h-3.5" />
                                    {t('common.apply')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Groups Modal */}
            {showGroupsModal && (
                <GroupsModal
                    type="campaign"
                    onClose={() => {
                        setShowGroupsModal(false);
                        fetchGroups();
                    }}
                />
            )}

            {/* Global Report Modal */}
            {showGlobalReports && (
                <CampaignReports
                    campaignId={null}
                    campaignName={null}
                    onClose={() => setShowGlobalReports(false)}
                />
            )}
        </div>
    );
};

export default Campaigns;
