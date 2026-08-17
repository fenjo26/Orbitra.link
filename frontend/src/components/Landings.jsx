import React, { useState, useMemo, useEffect, useCallback, useRef } from 'react';
import { Plus, Trash2, Edit3, Settings2, Filter, RefreshCw, X, SlidersHorizontal } from 'lucide-react';
import InfoBanner from './InfoBanner';
import LandingEditor from './LandingEditor';
import GroupsModal from './GroupsModal';
import ColumnsOrderModal from './ColumnsOrderModal';
import PaginationToolbar from './common/PaginationToolbar';
import DateRangePicker, { formatDate, getPresetDates } from './DateRangePicker';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

// Every column the landings table can show, backed by fields the `landings`
// endpoint actually returns. Money and status counters come from the same
// conversion aggregate as the reports (see core/ReportMetrics.php); visits
// equal clicks because one click row IS one landing visit.
export const ALL_LANDING_COLUMNS = [
    { id: 'id', label: 'ID' },
    { id: 'name', label: 'Name', required: true },
    { id: 'group_name', label: 'Group' },
    { id: 'type', label: 'Type' },
    { id: 'state', label: 'Status' },
    { id: 'visits', label: 'Visits', alignRight: true },
    { id: 'unique_visits', label: 'uVisits', alignRight: true },
    { id: 'clicks', label: 'Clicks', alignRight: true },
    { id: 'unique_clicks', label: 'Uniques', alignRight: true },
    { id: 'lp_clicks', label: 'LP Clicks', alignRight: true },
    { id: 'lp_ctr', label: 'LP CTR', alignRight: true },
    { id: 'conversions', label: 'Conversions', alignRight: true },
    { id: 'leads', label: 'Leads', alignRight: true },
    { id: 'sales', label: 'Sales', alignRight: true },
    { id: 'rejected', label: 'Rejected', alignRight: true },
    { id: 'trash', label: 'Trash', alignRight: true },
    { id: 'approve_rate', label: 'Approve %', alignRight: true },
    { id: 'cr', label: 'CR', alignRight: true },
    { id: 'cost', label: 'Cost', alignRight: true },
    { id: 'revenue', label: 'Revenue', alignRight: true },
    { id: 'revenue_confirmed', label: 'Revenue (conf)', alignRight: true },
    { id: 'profit', label: 'Profit', alignRight: true },
    { id: 'profit_confirmed', label: 'Profit (conf)', alignRight: true },
    { id: 'cpc', label: 'CPC', alignRight: true },
    { id: 'cpv', label: 'CPV', alignRight: true },
    { id: 'epc', label: 'EPC', alignRight: true },
    { id: 'epc_confirmed', label: 'EPC (conf)', alignRight: true },
    { id: 'epv', label: 'EPV', alignRight: true },
    { id: 'roi', label: 'ROI', alignRight: true },
    { id: 'roi_confirmed', label: 'ROI (conf)', alignRight: true },
    { id: 'last_event', label: 'Last Event' },
];

// The table as it shipped: order and composition users already know.
export const DEFAULT_LANDING_COLUMNS = [
    'id', 'name', 'group_name', 'type', 'state',
    'clicks', 'unique_clicks', 'lp_clicks', 'lp_ctr',
];

const LANDING_COLUMNS_KEY = 'orbitra_landing_columns';

const loadLandingColumns = () => {
    try {
        const saved = JSON.parse(localStorage.getItem(LANDING_COLUMNS_KEY) || 'null');
        if (Array.isArray(saved) && saved.length) {
            const valid = saved.filter(id => ALL_LANDING_COLUMNS.some(c => c.id === id));
            if (valid.includes('name')) return valid;
        }
    } catch { /* fall through to default */ }
    return [...DEFAULT_LANDING_COLUMNS];
};

const Landings = ({ landings, refreshData }) => {
    const { t } = useLanguage();
    const [landingList, setLandingList] = useState(() => landings || []);
    const [isEditorOpen, setIsEditorOpen] = useState(false);
    const [showGroupsModal, setShowGroupsModal] = useState(false);
    const [editingLandingId, setEditingLandingId] = useState(null);
    const [selectedLandingIds, setSelectedLandingIds] = useState(() => new Set());
    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState('');
    const [pageSize, setPageSize] = useState(() => {
        const saved = localStorage.getItem('orbitra_table_page_size');
        return saved === 'All' ? 'All' : ([25, 50, 100, 250].includes(Number(saved)) ? Number(saved) : 25);
    });
    const [currentPage, setCurrentPage] = useState(0);
    const [typeFilter, setTypeFilter] = useState('');
    const [stateFilter, setStateFilter] = useState('');
    // Group pill tab: 'all' | group id | 'no_group' | 'local_only' | 'redirect_only'
    const [groupTab, setGroupTab] = useState('all');
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [columnsModalOpen, setColumnsModalOpen] = useState(false);
    const [chosenColumns, setChosenColumns] = useState(() => loadLandingColumns());
    const [dateFrom, setDateFrom] = useState(() => getPresetDates('today')?.from || formatDate(new Date()));
    const [dateTo, setDateTo] = useState(() => getPresetDates('today')?.to || formatDate(new Date()));
    const [timezone, setTimezone] = useState(() => localStorage.getItem('orbitra_tz') || 'UTC');
    const landingRequestId = useRef(0);

    const fetchLandings = useCallback(async () => {
        const requestId = ++landingRequestId.current;
        setRefreshing(true);
        try {
            const res = await axios.get(`${API_URL}?action=landings`, {
                params: {
                    date_from: dateFrom,
                    date_to: dateTo,
                    timezone,
                },
            });
            if (requestId === landingRequestId.current && res?.data?.status === 'success') {
                setLandingList(res.data.data || []);
            }
        } catch (err) {
            if (requestId === landingRequestId.current) {
                console.error('Error fetching landings:', err);
            }
        } finally {
            if (requestId === landingRequestId.current) setRefreshing(false);
        }
    }, [dateFrom, dateTo, timezone]);

    useEffect(() => {
        fetchLandings();
    }, [fetchLandings]);

    const handleDateChange = (from, to) => {
        setDateFrom(from);
        setDateTo(to);
    };

    const handleCreate = () => {
        setEditingLandingId(null);
        setIsEditorOpen(true);
    };

    const handleEdit = (id) => {
        setEditingLandingId(id);
        setIsEditorOpen(true);
    };

    const handleDelete = async (id) => {
        if (window.confirm(t('common.deleteConfirm'))) {
            try {
                const res = await axios.post(`${API_URL}?action=delete_landing`, { id });
                if (res?.data?.status !== 'success') {
                    alert(res?.data?.message || t('common.error'));
                    return;
                }
                await Promise.all([
                    fetchLandings(),
                    Promise.resolve(refreshData?.()),
                ]);
            } catch (err) {
                alert(err?.response?.data?.message || err?.message || t('common.error'));
            }
        }
    };

    // Pill counts, derived from the rows themselves (group_id/group_name ship
    // with the landings list), so tabs and numbers can never drift from data.
    const groupTabs = useMemo(() => {
        const byGroup = new Map(); // id -> {id, name, count}
        let noGroup = 0, local = 0, redirect = 0;
        landingList.forEach(l => {
            if (l.group_id) {
                const entry = byGroup.get(l.group_id) || { id: l.group_id, name: l.group_name || `#${l.group_id}`, count: 0 };
                entry.count += 1;
                byGroup.set(l.group_id, entry);
            } else {
                noGroup += 1;
            }
            if (l.type === 'local') local += 1;
            if (l.type === 'redirect') redirect += 1;
        });
        return {
            all: landingList.length,
            groups: [...byGroup.values()].sort((a, b) => a.name.localeCompare(b.name)),
            noGroup,
            local,
            redirect,
        };
    }, [landingList]);

    const filteredLandings = useMemo(() => {
        const q = String(search || '').trim().toLowerCase();
        return landingList.filter(l => {
            if (q) {
                const n = String(l.name || '').toLowerCase();
                const u = String(l.url || '').toLowerCase();
                const g = String(l.group_name || '').toLowerCase();
                if (!n.includes(q) && !u.includes(q) && !g.includes(q) && String(l.id || '') !== q) return false;
            }
            if (groupTab === 'no_group' && l.group_id) return false;
            if (groupTab === 'local_only' && l.type !== 'local') return false;
            if (groupTab === 'redirect_only' && l.type !== 'redirect') return false;
            // Any other tab value is a group id (compare as strings — the API
            // may hand ids back as either).
            if (!['all', 'no_group', 'local_only', 'redirect_only'].includes(groupTab)
                && String(l.group_id || '') !== String(groupTab)) return false;
            if (typeFilter && String(l.type || '') !== typeFilter) return false;
            if (stateFilter && String(l.state || '') !== stateFilter) return false;
            return true;
        });
    }, [landingList, search, typeFilter, stateFilter, groupTab]);

    const visibleLandings = filteredLandings;

    // The paged slice the table renders. Totals footer and CSV export stay
    // over the whole filtered list — paging must not change TOTAL.
    const pagedLandings = useMemo(() => {
        if (pageSize === 'All') return visibleLandings;
        const start = currentPage * pageSize;
        return visibleLandings.slice(start, start + pageSize);
    }, [visibleLandings, currentPage, pageSize]);

    // Any narrowing of the list must drop the user back to the first page.
    useEffect(() => {
        setCurrentPage(0);
    }, [search, typeFilter, stateFilter, groupTab, pageSize]);
    const selectedGroupValue = groupTab === 'no_group'
        ? 'no_group'
        : (!['all', 'local_only', 'redirect_only'].includes(groupTab) ? String(groupTab) : '');

    const toggleSelected = (id, checked) => {
        setSelectedLandingIds(prev => {
            const next = new Set(prev);
            if (checked) next.add(id);
            else next.delete(id);
            return next;
        });
    };

    const toggleSelectAll = (checked) => {
        setSelectedLandingIds(prev => {
            const next = new Set(prev);
            if (checked) {
                visibleLandings.forEach(l => next.add(l.id));
            } else {
                visibleLandings.forEach(l => next.delete(l.id));
            }
            return next;
        });
    };

    const allSelected = visibleLandings.length > 0 && visibleLandings.every(l => selectedLandingIds.has(l.id));
    const someSelected = visibleLandings.some(l => selectedLandingIds.has(l.id));

    const handleBulkDeleteSelected = async () => {
        const ids = Array.from(selectedLandingIds);
        if (ids.length === 0) return;
        const msg = (t('common.deleteSelectedConfirm') || t('common.deleteConfirm')).replace('{count}', String(ids.length));
        if (!window.confirm(msg)) return;
        try {
            await axios.post(`${API_URL}?action=bulk_delete_landings`, { ids });
            setSelectedLandingIds(new Set());
            await Promise.all([
                fetchLandings(),
                Promise.resolve(refreshData?.()),
            ]);
        } catch {
            alert(t('common.error'));
        }
    };

    const exportVisibleCsv = () => {
        const cols = [
            { key: 'id', label: 'id' },
            { key: 'name', label: 'name' },
            { key: 'group_name', label: 'group' },
            { key: 'type', label: 'type' },
            { key: 'state', label: 'state' },
            { key: 'clicks', label: 'clicks' },
            { key: 'unique_clicks', label: 'unique_clicks' },
            { key: 'lp_clicks', label: 'lp_clicks' },
            { key: 'lp_ctr', label: 'lp_ctr' },
            { key: 'url', label: 'url' },
        ];

        const escape = (v) => {
            const s = v === null || v === undefined ? '' : String(v);
            if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
            return s;
        };

        const header = cols.map(c => escape(c.label)).join(',');
        const lines = visibleLandings.map(l => cols.map(c => escape(l[c.key])).join(','));
        const csv = [header, ...lines].join('\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `landings_${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    };

    const handleRefresh = async () => {
        if (refreshing) return;
        await fetchLandings();
    };

    const handleEditorClose = (wasSaved) => {
        setIsEditorOpen(false);
        if (wasSaved) {
            fetchLandings();
            refreshData?.();
        }
    };

    const columnLabel = (colId) => ({
        id: 'ID',
        name: t('components.aliasName'),
        type: t('components.type'),
        state: t('components.status'),
        visits: t('metrics.visits'),
        unique_visits: t('metrics.uniqueVisits'),
        clicks: t('components.clicks'),
        unique_clicks: t('components.uniques'),
        lp_clicks: t('components.lpClicks'),
        lp_ctr: t('components.lpCtr'),
        conversions: t('landingColumns.conversions'),
        leads: t('offerColumns.leads'),
        sales: t('offerColumns.sales'),
        rejected: t('offerColumns.rejected'),
        trash: t('metrics.trash'),
        approve_rate: t('metrics.approve'),
        cr: t('landingColumns.cr'),
        cost: t('metrics.cost'),
        revenue: t('metrics.revenue'),
        revenue_confirmed: t('offerColumns.revenueConfirmed'),
        profit: t('metrics.profit'),
        profit_confirmed: t('offerColumns.profitConfirmed'),
        cpc: t('metrics.cpc'),
        cpv: t('metrics.cpv', 'CPV'),
        epc: t('metrics.epc'),
        epc_confirmed: t('offerColumns.epcConfirmed'),
        epv: t('metrics.epv'),
        roi: t('metrics.roi'),
        roi_confirmed: t('offerColumns.roiConfirmed'),
        group_name: t('components.group'),
        last_event: t('landingColumns.lastEvent'),
    }[colId] || colId);

    const localizedColumns = ALL_LANDING_COLUMNS.map(c => ({ ...c, label: columnLabel(c.id) }));

    const metricHint = (colId) => {
        const hintKey = ({
            visits: 'lpViewsHint',
            clicks: 'lpViewsHint',
            lp_clicks: 'lpClicksHint',
            lp_ctr: 'lpCtrHint',
            cpv: 'cpvHint',
            cpc: 'cpcHint',
            epv: 'epvHint',
            epc: 'epcHint',
            epc_confirmed: 'epcHint',
        })[colId];
        return hintKey ? t(`metrics.${hintKey}`) : undefined;
    };

    // SQLite hands back "YYYY-MM-DD HH:MM:SS"; the space separator chokes
    // Safari's Date parser, so normalize to ISO before formatting.
    const formatLastEvent = (v) => {
        if (!v) return t('landingColumns.never');
        const d = new Date(String(v).replace(' ', 'T'));
        if (isNaN(d.getTime())) return t('landingColumns.never');
        const p = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
    };

    const money = (v, precision = 2) => `$${(parseFloat(v) || 0).toFixed(precision)}`;

    const totals = visibleLandings.reduce((acc, landing) => {
        acc.clicks += Number(landing.clicks) || 0;
        acc.unique_clicks += Number(landing.unique_clicks) || 0;
        acc.visits += Number(landing.visits) || 0;
        acc.unique_visits += Number(landing.unique_visits) || 0;
        acc.lp_clicks += Number(landing.lp_clicks) || 0;
        acc.conversions += Number(landing.conversions) || 0;
        acc.leads += Number(landing.leads) || 0;
        acc.sales += Number(landing.sales) || 0;
        acc.rejected += Number(landing.rejected) || 0;
        acc.trash += Number(landing.trash) || 0;
        acc.revenue += Number(landing.revenue) || 0;
        acc.revenue_confirmed += Number(landing.revenue_confirmed) || 0;
        acc.cost += Number(landing.cost) || 0;
        return acc;
    }, { clicks: 0, unique_clicks: 0, visits: 0, unique_visits: 0, lp_clicks: 0,
        conversions: 0, leads: 0, sales: 0, rejected: 0, trash: 0,
        revenue: 0, revenue_confirmed: 0, cost: 0 });

    const totalsProfit = totals.revenue - totals.cost;
    const totalsProfitConfirmed = totals.revenue_confirmed - totals.cost;
    const totalsApproveDenom = totals.sales + totals.leads + totals.rejected + totals.trash;
    const totalsLpViews = totals.visits > 0 ? totals.visits : totals.clicks;
    const totalsLpClickDenominator = totals.lp_clicks > 0 ? totals.lp_clicks : totals.clicks;
    const renderTotalCell = (colId) => {
        switch (colId) {
            case 'clicks': return totals.clicks.toLocaleString();
            case 'unique_clicks': return totals.unique_clicks.toLocaleString();
            case 'visits': return totals.visits.toLocaleString();
            case 'unique_visits': return totals.unique_visits.toLocaleString();
            case 'lp_clicks': return totals.lp_clicks.toLocaleString();
            case 'conversions': return totals.conversions.toLocaleString();
            case 'leads': return totals.leads.toLocaleString();
            case 'sales': return totals.sales.toLocaleString();
            case 'rejected': return totals.rejected.toLocaleString();
            case 'trash': return totals.trash.toLocaleString();
            case 'approve_rate': return totalsApproveDenom > 0 ? `${((totals.sales / totalsApproveDenom) * 100).toFixed(2)}%` : '0%';
            case 'lp_ctr': return totalsLpViews > 0 ? `${((totals.lp_clicks / totalsLpViews) * 100).toFixed(2)}%` : '0%';
            case 'revenue': return money(totals.revenue);
            case 'revenue_confirmed': return money(totals.revenue_confirmed);
            case 'cost': return money(totals.cost);
            case 'profit': return money(totalsProfit);
            case 'profit_confirmed': return money(totalsProfitConfirmed);
            case 'cr': return totals.clicks > 0 ? `${((totals.conversions / totals.clicks) * 100).toFixed(2)}%` : '0%';
            case 'epc': return totalsLpClickDenominator > 0 ? money(totals.revenue / totalsLpClickDenominator) : '$0.00';
            case 'epc_confirmed': return totalsLpClickDenominator > 0 ? money(totals.revenue_confirmed / totalsLpClickDenominator) : '$0.00';
            case 'epv': return totalsLpViews > 0 ? money(totals.revenue / totalsLpViews) : '$0.00';
            case 'cpc': return totalsLpClickDenominator > 0 ? money(totals.cost / totalsLpClickDenominator) : '$0.00';
            case 'cpv': return totalsLpViews > 0 ? money(totals.cost / totalsLpViews) : '$0.00';
            case 'roi': return totals.cost > 0 ? `${((totalsProfit / totals.cost) * 100).toFixed(2)}%` : '—';
            case 'roi_confirmed': return totals.cost > 0 ? `${((totalsProfitConfirmed / totals.cost) * 100).toFixed(2)}%` : '—';
            default: return null;
        }
    };

    const renderLandingCell = (landing, colId) => {
        const tdCls = ALL_LANDING_COLUMNS.find(c => c.id === colId)?.alignRight ? 'text-right' : '';
        switch (colId) {
            case 'id':
                return <td key={colId} className="font-medium">{landing.id}</td>;
            case 'name':
                return (
                    <td key={colId}>
                        <div className="flex flex-col">
                            <span
                                className="font-semibold cursor-pointer hover:underline"
                                style={{ color: 'var(--color-primary)' }}
                                onClick={() => handleEdit(landing.id)}
                            >
                                {landing.name}
                            </span>
                            {landing.type !== 'local' && landing.type !== 'action' && (
                                <span style={{ color: 'var(--color-text-muted)', fontSize: '12px' }} className="truncate max-w-[200px]" title={landing.url}>
                                    {landing.url}
                                </span>
                            )}
                        </div>
                    </td>
                );
            case 'type':
                return (
                    <td key={colId}>
                        <span className="px-2 py-1 rounded text-xs font-semibold" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                            {landing.type}
                        </span>
                    </td>
                );
            case 'state':
                return (
                    <td key={colId}>
                        <span className="flex items-center text-xs font-medium" style={{ color: landing.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}>
                            <span className="w-2 h-2 rounded-full mr-1.5" style={{ backgroundColor: landing.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}></span>
                            {landing.state === 'active' ? t('components.active') : t('components.archive')}
                        </span>
                    </td>
                );
            case 'group_name':
                return <td key={colId} style={{ color: 'var(--color-text-secondary)' }}>{landing.group_name || '-'}</td>;
            case 'cost':
                return <td key={colId} className={tdCls}>{money(landing.cost)}</td>;
            case 'revenue':
                return <td key={colId} className={`${tdCls} font-medium`} style={{ color: 'var(--color-success)' }}>{money(landing.revenue)}</td>;
            case 'revenue_confirmed':
                return <td key={colId} className={`${tdCls} font-medium`} style={{ color: 'var(--color-success)' }}>{money(landing.revenue_confirmed)}</td>;
            case 'profit':
            case 'profit_confirmed': {
                const v = parseFloat(landing[colId]) || 0;
                return (
                    <td key={colId} className={`${tdCls} font-medium`} style={{ color: v > 0 ? 'var(--color-success)' : v < 0 ? 'var(--color-danger)' : 'var(--color-text-secondary)' }}>
                        {money(v)}
                    </td>
                );
            }
            case 'cpc':
            case 'cpv':
            case 'epc':
            case 'epc_confirmed':
            case 'epv':
                return <td key={colId} className={tdCls}>{money(landing[colId])}</td>;
            case 'approve_rate':
                return <td key={colId} className={tdCls}>{`${parseFloat(landing.approve_rate) || 0}%`}</td>;
            case 'roi':
            case 'roi_confirmed': {
                const v = landing[colId];
                return (
                    <td key={colId} className={`${tdCls} font-medium`} style={{ color: (parseFloat(v) || 0) > 0 ? 'var(--color-success)' : 'var(--color-text-secondary)' }}>
                        {v !== null && v !== undefined ? `${v}%` : '—'}
                    </td>
                );
            }
            case 'lp_ctr':
                return <td key={colId} className={tdCls}>{landing.lp_ctr !== undefined ? `${landing.lp_ctr}%` : '0%'}</td>;
            case 'cr':
                return <td key={colId} className={tdCls}>{landing.cr !== undefined ? `${landing.cr}%` : '0%'}</td>;
            case 'last_event':
                return <td key={colId} style={{ color: 'var(--color-text-secondary)' }}>{formatLastEvent(landing.last_event)}</td>;
            default:
                return <td key={colId} className={tdCls}>{Number(landing[colId] || 0).toLocaleString()}</td>;
        }
    };

    return (
        <div className="page-card">
            <InfoBanner storageKey="help_landings" title={t('help.landingBannerTitle')}>
                <p>{t('help.landingBanner')}</p>
            </InfoBanner>
            <div className="page-header">
                <div className="flex flex-wrap gap-3">
                    <button onClick={handleCreate} className="btn btn-primary">
                        <Plus className="w-4 h-4" />
                        {t('common.create')}
                    </button>
                    <button onClick={() => setShowGroupsModal(true)} className="btn btn-secondary">
                        {t('campaigns.groups')}
                    </button>
                    {selectedLandingIds.size > 0 && (
                        <button onClick={handleBulkDeleteSelected} className="btn btn-danger" title={t('common.deleteSelected')}>
                            <Trash2 className="w-4 h-4" />
                            {(t('common.deleteSelected') || t('common.delete'))} ({selectedLandingIds.size})
                        </button>
                    )}
                </div>
                <div className="flex gap-2">
                    {/* Columns Customizer Button [ ☵ ] */}
                    <button
                        type="button"
                        onClick={() => setColumnsModalOpen(true)}
                        className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5 font-medium"
                        title={t('landingColumns.title')}
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
                        {typeFilter ? (
                            <span className="ml-1 px-1.5 py-0.5 bg-[var(--color-primary)] text-white text-xs rounded-full">
                                1
                            </span>
                        ) : null}
                    </button>
                    <button type="button" className="btn btn-ghost btn-icon" title={t('common.settings')} onClick={() => setSettingsOpen(true)}>
                        <Settings2 className="w-5 h-5" />
                    </button>
                </div>
            </div>

            {/* Unified quick toolbar: reporting period, entity filters, refresh and search. */}
            <div className="flex flex-wrap items-center justify-between gap-3 my-3 pb-3 border-b" style={{ borderColor: 'var(--color-border)' }}>
                <div className="flex flex-wrap items-center gap-2">
                    <DateRangePicker
                        dateFrom={dateFrom}
                        dateTo={dateTo}
                        onChange={handleDateChange}
                        selectedTimezone={timezone}
                        onTimezoneChange={setTimezone}
                    />

                    <select
                        value={selectedGroupValue}
                        onChange={(e) => setGroupTab(e.target.value || 'all')}
                        className="form-select text-xs font-semibold py-2 px-3.5 rounded-xl transition-all"
                        style={{
                            backgroundColor: selectedGroupValue ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                            borderColor: selectedGroupValue ? 'var(--color-primary)' : 'var(--color-border)',
                            color: selectedGroupValue ? 'var(--color-primary)' : 'var(--color-text-primary)',
                            minWidth: '140px',
                            width: 'auto',
                        }}
                    >
                        <option value="">{t('campaigns.allGroups', 'All groups')}</option>
                        {groupTabs.groups.map(g => (
                            <option key={g.id} value={String(g.id)}>{g.name}</option>
                        ))}
                        <option value="no_group">{t('landings.noGroup', 'No group')}</option>
                    </select>

                    <select
                        value={stateFilter}
                        onChange={(e) => setStateFilter(e.target.value)}
                        className="form-select text-xs font-semibold py-2 px-3.5 rounded-xl transition-all"
                        style={{
                            backgroundColor: stateFilter ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                            borderColor: stateFilter ? 'var(--color-primary)' : 'var(--color-border)',
                            color: stateFilter ? 'var(--color-primary)' : 'var(--color-text-primary)',
                            minWidth: '130px',
                            width: 'auto',
                        }}
                    >
                        <option value="">{t('landings.allStates', 'All states')}</option>
                        <option value="active">🟢 {t('components.active', 'Active')}</option>
                        <option value="archived">⚪ {t('offers.inactiveStates', 'Archived / Not active')}</option>
                    </select>

                    <button
                        type="button"
                        onClick={handleRefresh}
                        disabled={refreshing}
                        className="p-2.5 rounded-xl border flex items-center justify-center transition hover:opacity-80 disabled:opacity-50"
                        style={{
                            backgroundColor: 'var(--color-primary)',
                            borderColor: 'var(--color-primary)',
                            color: '#ffffff',
                        }}
                        title={t('common.refresh', 'Refresh')}
                    >
                        <RefreshCw size={14} className={refreshing ? 'animate-spin' : ''} />
                    </button>

                    {(selectedGroupValue || stateFilter) && (
                        <button
                            type="button"
                            onClick={() => { setGroupTab('all'); setStateFilter(''); }}
                            className="btn btn-ghost btn-sm text-xs"
                            style={{ color: 'var(--color-danger)' }}
                        >
                            <X size={13} />
                            {t('common.clear', 'Clear')}
                        </button>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    <label htmlFor="landings-search" className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                        {t('common.search', 'Search')}:
                    </label>
                    <input
                        id="landings-search"
                        type="search"
                        className="form-input text-xs py-1.5 px-3 rounded-xl"
                        style={{ width: '200px' }}
                        placeholder={t('landings.searchPlaceholder', 'Find landing...')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
            </div>

            {/* Group pill tabs — one-click narrowing to a group (White Pages,
                COD, …) or a landing type; counts come from the rows. */}
            <div className="flex items-center gap-2 overflow-x-auto pb-2 pt-1 mb-4 border-b" style={{ borderColor: 'var(--color-border)' }}>
                {[
                    { key: 'all', label: t('common.all'), count: groupTabs.all },
                    ...groupTabs.groups.map(g => ({
                        key: g.id,
                        label: g.name,
                        count: g.count,
                        icon: /white|safe|бел/i.test(g.name) ? '🛡' : (/cod|товар/i.test(g.name) ? '📦' : (/nutra|нутр/i.test(g.name) ? '💊' : null)),
                    })),
                    ...(groupTabs.noGroup > 0 ? [{ key: 'no_group', label: t('landings.noGroup'), count: groupTabs.noGroup }] : []),
                ].map(tab => {
                    const active = groupTab === tab.key;
                    return (
                        <button
                            key={String(tab.key)}
                            type="button"
                            onClick={() => setGroupTab(active && tab.key === 'all' ? 'all' : tab.key)}
                            className="px-3.5 py-1.5 rounded-xl text-xs font-semibold whitespace-nowrap transition-all flex items-center gap-1.5"
                            style={{
                                backgroundColor: active ? 'var(--color-primary)' : 'var(--color-bg-card)',
                                color: active ? '#ffffff' : 'var(--color-text-secondary)',
                                border: `1px solid ${active ? 'var(--color-primary)' : 'var(--color-border)'}`,
                                cursor: 'pointer'
                            }}
                        >
                            {tab.icon && <span>{tab.icon}</span>}
                            <span>{tab.label}</span>
                            <span className="text-[10px] px-1.5 rounded-full" style={{ backgroundColor: active ? 'rgba(255,255,255,0.25)' : 'var(--color-bg-soft)' }}>
                                {tab.count}
                            </span>
                        </button>
                    );
                })}

                <div className="h-5 w-[1px] flex-shrink-0" style={{ backgroundColor: 'var(--color-border)' }} />

                {[
                    { key: 'local_only', label: t('landingEditor.typeLocal'), count: groupTabs.local },
                    { key: 'redirect_only', label: t('landingEditor.typeRedirect'), count: groupTabs.redirect },
                ].map(tab => {
                    const active = groupTab === tab.key;
                    return (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => setGroupTab(active ? 'all' : tab.key)}
                            className="px-3 py-1.5 rounded-xl text-xs font-medium whitespace-nowrap transition-all flex items-center gap-1.5"
                            style={{
                                backgroundColor: active ? 'var(--color-primary-light)' : 'transparent',
                                color: active ? 'var(--color-primary)' : 'var(--color-text-muted)',
                                border: `1px solid ${active ? 'var(--color-primary)' : 'var(--color-border)'}`,
                                cursor: 'pointer'
                            }}
                        >
                            <span>{tab.key === 'local_only' ? '📁' : '🔗'} {tab.label}</span>
                            <span className="text-[10px] px-1.5 rounded-full" style={{ backgroundColor: 'var(--color-bg-soft)' }}>
                                {tab.count}
                            </span>
                        </button>
                    );
                })}
            </div>

            {showFilters && (
                <div className="flex flex-wrap gap-4 items-center py-4 mb-4 border-b" style={{ borderColor: 'var(--color-border)' }}>
                    <div className="flex items-center gap-2">
                        <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('components.type')}:</label>
                        <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} className="form-select" style={{ width: 'auto', minWidth: '140px' }}>
                            <option value="">{t('common.all')}</option>
                            <option value="local">local</option>
                            <option value="redirect">redirect</option>
                            <option value="action">action</option>
                        </select>
                    </div>
                    {typeFilter && (
                        <button type="button" onClick={() => setTypeFilter('')} className="btn btn-ghost btn-sm">
                            <X className="w-4 h-4" />
                            {t('common.clear')}
                        </button>
                    )}
                </div>
            )}

            <div className="tracker-table-container">
                <table className="page-table tracker-table">
                    <thead>
                        <tr>
                            <th className="w-10">
                                <input
                                    type="checkbox"
                                    checked={allSelected}
                                    ref={(el) => {
                                        if (el) el.indeterminate = !allSelected && someSelected;
                                    }}
                                    onChange={(e) => toggleSelectAll(e.target.checked)}
                                />
                            </th>
                            {chosenColumns.map((colId) => (
                                <th key={colId} title={metricHint(colId)} className={ALL_LANDING_COLUMNS.find(c => c.id === colId)?.alignRight ? 'text-right' : ''}>{columnLabel(colId)}</th>
                            ))}
                            <th className="text-right">{t('common.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {visibleLandings.length === 0 ? (
                            <tr>
                                <td colSpan={chosenColumns.length + 2} className="text-center py-12">
                                    <div className="empty-state">
                                        <p className="empty-state-title">{t('landings.noLandings')}</p>
                                        <p className="empty-state-text">{t('landings.noLandingsDesc')}</p>
                                    </div>
                                </td>
                            </tr>
                        ) : (
                            pagedLandings.map((landing) => (
                                <tr key={landing.id}>
                                    <td>
                                        <input
                                            type="checkbox"
                                            checked={selectedLandingIds.has(landing.id)}
                                            onChange={(e) => toggleSelected(landing.id, e.target.checked)}
                                        />
                                    </td>
                                    {chosenColumns.map((colId) => renderLandingCell(landing, colId))}
                                    <td>
                                        <div className="action-buttons">
                                            <button onClick={() => handleEdit(landing.id)} className="action-btn text-blue" title={t('common.edit') || t('components.edit')}>
                                                <Edit3 className="w-4 h-4" />
                                            </button>
                                            <button onClick={() => handleDelete(landing.id)} className="action-btn text-red" title={t('common.delete')}>
                                                <Trash2 className="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                    {visibleLandings.length > 0 && (
                        <tfoot style={{ background: 'var(--color-bg-soft)' }}>
                            <tr className="font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                <td className="px-4 py-3"></td>
                                {chosenColumns.map((colId) => {
                                    if (colId === 'name') {
                                        return <td key={colId} className="px-4 py-3">{t('campaignReports.total', 'Total')} ({visibleLandings.length})</td>;
                                    }
                                    const val = renderTotalCell(colId);
                                    const alignRight = ALL_LANDING_COLUMNS.find(c => c.id === colId)?.alignRight;
                                    const isNegativeProfit = (colId === 'profit' && totalsProfit < 0)
                                        || (colId === 'profit_confirmed' && totalsProfitConfirmed < 0);
                                    return <td key={colId} className={`px-4 py-3 ${alignRight ? 'text-right' : ''}`} style={{ color: isNegativeProfit ? 'var(--color-danger)' : undefined }}>{val ?? ''}</td>;
                                })}
                                <td></td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>

            <PaginationToolbar
                totalRows={visibleLandings.length}
                currentPage={currentPage}
                pageSize={pageSize}
                onPageChange={setCurrentPage}
                onPageSizeChange={(size) => { setPageSize(size); setCurrentPage(0); }}
            />

            {isEditorOpen && (
                <LandingEditor
                    landingId={editingLandingId}
                    onClose={handleEditorClose}
                />
            )}

            {settingsOpen && (
                <div className="modal-overlay" onClick={() => setSettingsOpen(false)}>
                    <div className="modal-content" onClick={(e) => e.stopPropagation()} style={{ maxWidth: '560px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title">{t('common.settings')}</h3>
                            <button type="button" className="btn btn-ghost btn-icon" onClick={() => setSettingsOpen(false)} title={t('common.close')}>
                                <X className="w-5 h-5" />
                            </button>
                        </div>
                        <div className="space-y-3">
                            <button type="button" className="btn btn-secondary w-full" onClick={() => { setSelectedLandingIds(new Set()); }}>
                                {t('common.clearSelection')}
                            </button>
                            <button type="button" className="btn btn-primary w-full" onClick={exportVisibleCsv}>
                                {t('common.exportCsv')}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {showGroupsModal && (
                <GroupsModal
                    type="landing"
                    onClose={() => setShowGroupsModal(false)}
                />
            )}

            {columnsModalOpen && (
                <ColumnsOrderModal
                    columns={localizedColumns}
                    selectedIds={chosenColumns}
                    defaultIds={DEFAULT_LANDING_COLUMNS}
                    onClose={() => setColumnsModalOpen(false)}
                    onSave={(ids) => {
                        setChosenColumns(ids);
                        localStorage.setItem(LANDING_COLUMNS_KEY, JSON.stringify(ids));
                        setColumnsModalOpen(false);
                    }}
                />
            )}
        </div>
    );
};

export default Landings;
