import React, { useState, useMemo, useEffect, useCallback, useRef } from 'react';
import { Plus, Trash2, Edit3, Settings2, Filter, RefreshCw, X, SlidersHorizontal } from 'lucide-react';
import InfoBanner from './InfoBanner';
import LandingEditor from './LandingEditor';
import GroupsModal from './GroupsModal';
import ReportCustomizerModal, { ALL_REPORT_METRICS, PRESETS, getReportMetricTooltip, normalizeReportMetricIds } from './ReportCustomizerModal';
import PaginationToolbar from './common/PaginationToolbar';
import MobileCards from './common/MobileCards';
import DateRangePicker, { formatDate, getPresetDates } from './DateRangePicker';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';
import { entityDeleteErrorText } from '../utils/entityInUseError';

const API_URL = '/api.php';

// Fixed entity columns for landings — these are always present and not part of
// the metric customization. Metrics come from ALL_REPORT_METRICS.
const FIXED_LANDING_COLUMNS = [
    { id: 'checkbox', label: '', fixed: true },
    { id: 'id', label: 'ID', fixed: true },
    { id: 'state', label: 'Status', fixed: true },
    { id: 'name', label: 'Name', fixed: true },
    { id: 'group_name', label: 'Group', fixed: true },
    { id: 'type', label: 'Type', fixed: true },
    { id: 'url', label: 'URL', fixed: true },
    { id: 'last_event', label: 'Last Event', fixed: true },
];

const LANDING_COLUMNS_KEY = 'orbitra_landing_columns';

const loadLandingColumns = () => {
    try {
        const saved = localStorage.getItem(LANDING_COLUMNS_KEY);
        if (saved) return normalizeReportMetricIds(JSON.parse(saved));
    } catch (e) {}
    // Fallback to 'lander_to_offer' preset for landings
    return normalizeReportMetricIds(PRESETS.lander_to_offer);
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
                    alert(entityDeleteErrorText(t, res?.data));
                    return;
                }
                await Promise.all([
                    fetchLandings(),
                    Promise.resolve(refreshData?.()),
                ]);
            } catch (err) {
                alert(entityDeleteErrorText(t, null, err));
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
            const res = await axios.post(`${API_URL}?action=bulk_delete_landings`, { ids });
            // Same 200-with-error refusal as the single delete: a blocked
            // batch must not look like it went through.
            if (res?.data?.status !== 'success') {
                alert(entityDeleteErrorText(t, res?.data));
                return;
            }
            setSelectedLandingIds(new Set());
            await Promise.all([
                fetchLandings(),
                Promise.resolve(refreshData?.()),
            ]);
        } catch (err) {
            alert(entityDeleteErrorText(t, null, err));
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

    // SQLite hands back "YYYY-MM-DD HH:MM:SS"; the space separator chokes
    // Safari's Date parser, so normalize to ISO before formatting.
    const formatLastEvent = (v) => {
        if (!v) return t('landingColumns.never');
        const d = new Date(String(v).replace(' ', 'T'));
        if (isNaN(d.getTime())) return t('landingColumns.never');
        const p = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
    };

    // Entity column label helper
    const entityLabel = (colId) => ({
        id: 'ID',
        name: t('editor.name'),
        state: t('components.status'),
        group_name: t('components.group'),
        type: t('components.type'),
        url: 'URL',
        last_event: t('landingColumns.lastEvent'),
    }[colId] || colId);

    // Unified metric cell formatter (same as Campaigns.jsx/Offers.jsx)
    const formatMetricCell = (metricId, row) => {
        const val = row[metricId];
        const num = Number(val) || 0;

        switch (metricId) {
            case 'clicks':
            case 'unique_clicks':
            case 'visits':
            case 'unique_visits':
            case 'lp_views':
            case 'lp_clicks':
            case 'sales':
            case 'leads':
            case 'registrations':
            case 'deposits':
            case 'rejected':
            case 'trash':
            case 'bots':
            case 'proxies':
            case 'empty_referrers':
                return num.toLocaleString();

            case 'conversions':
                return num > 0 ? <span className="font-semibold" style={{ color: 'var(--color-success)' }}>{num.toLocaleString()}</span> : '0';

            case 'lp_ctr':
            case 'approve_rate':
            case 'cr':
            case 'cr_sales':
            case 'cr_leads':
            case 'cr_holds':
            case 'cr_registrations':
            case 'cr_deposits':
                return val === null || val === undefined ? '—' : `${num.toFixed(2)}%`;

            case 'roi':
            case 'roi_confirmed': {
                if (val === null || val === undefined) return '—';
                if (num === 0) return <span style={{ color: 'var(--color-text-muted)' }}>0.00%</span>;
                const isPos = num > 0;
                return (
                    <span style={{ color: isPos ? 'var(--color-success)' : 'var(--color-danger)', fontWeight: 600 }}>
                        {isPos ? '+' : ''}{num.toFixed(2)}%
                    </span>
                );
            }

            case 'profit':
            case 'profit_confirmed': {
                if (Math.abs(num) < 0.0001) {
                    return <span style={{ color: 'var(--color-text-secondary)' }}>$0.00</span>;
                }
                const isPos = num > 0;
                return (
                    <span style={{ color: isPos ? 'var(--color-success)' : 'var(--color-danger)', fontWeight: 600 }}>
                        {isPos ? '+' : '-'}${Math.abs(num).toFixed(2)}
                    </span>
                );
            }

            case 'cost':
            case 'revenue':
            case 'revenue_confirmed':
            case 'revenue_hold':
            case 'revenue_rejected':
            case 'revenue_trash':
            case 'cpa':
            case 'cps':
            case 'cpl':
            case 'cpd':
            case 'cpc':
            case 'cpv':
            case 'epc':
            case 'uepc':
            case 'epv':
            case 'ecpm_all':
            case 'ecpm_confirmed':
            case 'earnings_per_conv':
            case 'epc_confirmed':
            case 'uepc_confirmed':
            case 'epc_hold':
            case 'uepc_hold':
            case 'epc_registration':
            case 'uepc_registration':
            case 'ucpc':
                return `$${num.toFixed(2)}`;

            default:
                return val !== undefined && val !== null ? String(val) : '-';
        }
    };

    // Calculate grand totals for all visible landings (not just paged slice).
    // Ratios (CR/EPC/CPC/ROI) are recomputed from the summed base metrics.
    const grandTotals = useMemo(() => {
        const t0 = {
            clicks: 0, unique_clicks: 0, visits: 0, unique_visits: 0,
            lp_clicks: 0, lp_views: 0, conversions: 0, leads: 0, sales: 0,
            rejected: 0, trash: 0, cost: 0, revenue: 0, revenue_confirmed: 0,
            revenue_hold: 0, revenue_rejected: 0, revenue_trash: 0,
            registrations: 0, deposits: 0, bots: 0, proxies: 0, empty_referrers: 0
        };

        visibleLandings.forEach(l => {
            t0.clicks += Number(l.clicks) || 0;
            t0.unique_clicks += Number(l.unique_clicks) || 0;
            const lpViews = Number(l.visits ?? l.clicks) || 0;
            t0.visits += lpViews;
            t0.lp_views += lpViews;
            t0.lp_clicks += Number(l.lp_clicks) || 0;
            t0.conversions += Number(l.conversions) || 0;
            t0.leads += Number(l.leads) || 0;
            t0.sales += Number(l.sales) || 0;
            t0.rejected += Number(l.rejected) || 0;
            t0.trash += Number(l.trash) || 0;
            t0.cost += Number(l.cost) || 0;
            t0.revenue += Number(l.revenue) || 0;
            t0.revenue_confirmed += Number(l.revenue_confirmed) || 0;
            t0.revenue_hold += Number(l.revenue_hold) || 0;
            t0.revenue_rejected += Number(l.revenue_rejected) || 0;
            t0.revenue_trash += Number(l.revenue_trash) || 0;
            t0.registrations += Number(l.registrations) || 0;
            t0.deposits += Number(l.deposits) || 0;
            t0.bots += Number(l.bots) || 0;
            t0.proxies += Number(l.proxies) || 0;
            t0.empty_referrers += Number(l.empty_referrers) || 0;
        });

        // Computed ratios from totals (same logic as Campaigns.jsx/Offers.jsx)
        const lpClickDenominator = t0.lp_clicks > 0 ? t0.lp_clicks : t0.clicks;
        const lp_ctr = t0.lp_views > 0 ? (t0.lp_clicks / t0.lp_views) * 100 : 0;
        const cr = t0.clicks > 0 ? (t0.conversions / t0.clicks) * 100 : 0;
        const cr_sales = t0.clicks > 0 ? (t0.sales / t0.clicks) * 100 : 0;
        const cr_leads = t0.clicks > 0 ? (t0.leads / t0.clicks) * 100 : 0;
        const profit_confirmed = t0.revenue_confirmed - t0.cost;
        const roi_confirmed = t0.cost > 0 ? (profit_confirmed / t0.cost) * 100 : 0;
        const cpl = t0.leads > 0 ? t0.cost / t0.leads : 0;
        const cps = t0.sales > 0 ? t0.cost / t0.sales : 0;
        const cpa = t0.conversions > 0 ? t0.cost / t0.conversions : 0;
        const approve_rate = t0.conversions > 0 ? (t0.sales / t0.conversions) * 100 : 0;
        const roi = t0.cost > 0 ? ((t0.revenue - t0.cost) / t0.cost) * 100 : 0;
        const epc = lpClickDenominator > 0 ? t0.revenue / lpClickDenominator : 0;
        const epv = t0.lp_views > 0 ? t0.revenue / t0.lp_views : 0;
        const uepc = t0.unique_clicks > 0 ? t0.revenue / t0.unique_clicks : 0;
        const cpc = lpClickDenominator > 0 ? t0.cost / lpClickDenominator : 0;
        const cpv = t0.lp_views > 0 ? t0.cost / t0.lp_views : 0;

        return {
            ...t0,
            profit: t0.revenue - t0.cost,
            profit_confirmed,
            roi_confirmed,
            cpl, cps, cpa,
            lp_ctr, cr, cr_sales, cr_leads,
            approve_rate, roi,
            epc, epv, uepc, cpc, cpv,
            sales: t0.sales,
            leads: t0.leads
        };
    }, [visibleLandings]);

    const formatTotalCell = (colId) => {
        const val = grandTotals[colId];
        const num = Number(val) || 0;
        switch (colId) {
            case 'clicks':
            case 'unique_clicks':
            case 'lp_views':
            case 'lp_clicks':
            case 'sales':
            case 'leads':
            case 'registrations':
            case 'deposits':
            case 'rejected':
            case 'trash':
            case 'bots':
            case 'proxies':
            case 'empty_referrers':
            case 'visits':
            case 'unique_visits':
                return num.toLocaleString();
            case 'conversions':
                return <span className="font-semibold" style={{ color: 'var(--color-success)' }}>{num.toLocaleString()}</span>;
            case 'lp_ctr':
            case 'approve_rate':
            case 'cr':
            case 'cr_sales':
            case 'cr_leads':
                return `${num.toFixed(2)}%`;
            case 'roi':
            case 'roi_confirmed': {
                if (num === 0) return <span style={{ color: 'var(--color-text-muted)' }}>0.00%</span>;
                const isPos = num > 0;
                return (
                    <span style={{ color: isPos ? 'var(--color-success)' : 'var(--color-danger)', fontWeight: 600 }}>
                        {isPos ? '+' : ''}{num.toFixed(2)}%
                    </span>
                );
            }
            case 'profit':
            case 'profit_confirmed': {
                if (Math.abs(num) < 0.0001) return <span style={{ color: 'var(--color-text-secondary)' }}>$0.00</span>;
                const isPos = num > 0;
                return (
                    <span style={{ color: isPos ? 'var(--color-success)' : 'var(--color-danger)', fontWeight: 600 }}>
                        {isPos ? '+' : '-'}${Math.abs(num).toFixed(2)}
                    </span>
                );
            }
            case 'cost':
            case 'revenue':
            case 'revenue_confirmed':
            case 'cpa':
            case 'cps':
            case 'cpl':
            case 'cpc':
            case 'cpv':
            case 'epc':
            case 'uepc':
            case 'epv':
                return `$${num.toFixed(2)}`;
            default:
                return val !== undefined && val !== null ? String(val) : '-';
        }
    };

    // Entity cell renderer for fixed columns
    const renderEntityCell = (landing, colId) => {
        switch (colId) {
            case 'checkbox':
                return (
                    <td key={colId}>
                        <input
                            type="checkbox"
                            checked={selectedLandingIds.has(landing.id)}
                            onChange={(e) => toggleSelected(landing.id, e.target.checked)}
                        />
                    </td>
                );
            case 'id':
                return <td key={colId} className="font-medium">{landing.id}</td>;
            case 'state':
                return (
                    <td key={colId}>
                        <span className="flex items-center text-xs font-medium" style={{ color: landing.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}>
                            <span className="w-2 h-2 rounded-full mr-1.5" style={{ backgroundColor: landing.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}></span>
                            {landing.state === 'active' ? t('components.active') : t('components.archive')}
                        </span>
                    </td>
                );
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
            case 'group_name':
                return <td key={colId} style={{ color: 'var(--color-text-secondary)' }}>{landing.group_name || '-'}</td>;
            case 'type':
                return (
                    <td key={colId}>
                        <span className="px-2 py-1 rounded text-xs font-semibold" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                            {landing.type}
                        </span>
                    </td>
                );
            case 'url':
                return (
                    <td key={colId} style={{ color: 'var(--color-text-muted)', fontSize: '12px' }} className="truncate max-w-[200px]" title={landing.url}>
                        {landing.url}
                    </td>
                );
            case 'last_event':
                return <td key={colId} style={{ color: 'var(--color-text-secondary)' }}>{formatLastEvent(landing.last_event)}</td>;
            default:
                return <td key={colId}>-</td>;
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
                            color: 'var(--color-text-inverse)',
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
                                color: active ? 'var(--color-text-inverse)' : 'var(--color-text-secondary)',
                                border: `1px solid ${active ? 'var(--color-primary)' : 'var(--color-border)'}`,
                                cursor: 'pointer'
                            }}
                        >
                            {tab.icon && <span>{tab.icon}</span>}
                            <span>{tab.label}</span>
                            <span className="text-[10px] px-1.5 rounded-full" style={{ backgroundColor: active ? 'color-mix(in srgb, var(--color-text-inverse) 15%, transparent)' : 'var(--color-bg-soft)', color: 'inherit' }}>
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

            <div className="tracker-table-container hidden lg:block">
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
                            <th>ID</th>
                            <th>{t('components.status')}</th>
                            <th>{t('editor.name')}</th>
                            <th>{t('components.group')}</th>
                            <th>{t('components.type')}</th>
                            <th>URL</th>
                            <th>{t('landingColumns.lastEvent')}</th>

                            {/* Dynamic metric columns */}
                            {chosenColumns.map((colId) => {
                                const def = ALL_REPORT_METRICS.find(m => m.id === colId);
                                return (
                                    <th
                                        key={colId}
                                        title={getReportMetricTooltip(def, t)}
                                        className="text-right whitespace-nowrap"
                                    >
                                        {def?.shortLabel || def?.label || colId}
                                    </th>
                                );
                            })}
                            <th className="text-right">{t('common.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {visibleLandings.length === 0 ? (
                            <tr>
                                <td colSpan={9 + chosenColumns.length} className="text-center py-12">
                                    <div className="empty-state">
                                        <p className="empty-state-title">{t('landings.noLandings')}</p>
                                        <p className="empty-state-text">{t('landings.noLandingsDesc')}</p>
                                    </div>
                                </td>
                            </tr>
                        ) : (
                            pagedLandings.map((landing) => (
                                <tr key={landing.id}>
                                    {/* The checkbox cell renders here too — renderEntityCell
                                        has the 'checkbox' case, and the body must keep the
                                        header's column count or every metric shifts left. */}
                                    {FIXED_LANDING_COLUMNS.map(col =>
                                        renderEntityCell(landing, col.id)
                                    )}
                                    {/* Metric cells */}
                                    {chosenColumns.map((colId) => (
                                        <td key={colId} className="text-right">
                                            {formatMetricCell(colId, landing)}
                                        </td>
                                    ))}
                                    <td className="text-right">
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
                    {/* Totals Footer */}
                    {visibleLandings.length > 0 && (
                        <tfoot style={{ background: 'var(--color-bg-soft)' }}>
                            <tr className="font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                <td className="px-4 py-3"></td>
                                <td className="px-4 py-3" colSpan={7}>Σ Total ({visibleLandings.length})</td>
                                {chosenColumns.map((colId) => (
                                    <td key={colId} className="px-4 py-3 text-right">
                                        {formatTotalCell(colId)}
                                    </td>
                                ))}
                                <td></td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>

            {/* Mobile stacked cards (below lg) — same customiser-driven metric
                set as the table; the fixed entity columns (group, type, URL,
                last event) sit behind the "More" expander. */}
            <div className="lg:hidden">
                <MobileCards
                    rows={pagedLandings}
                    getId={(landing) => landing.id}
                    renderTitle={(landing) => (
                        <>
                            <input
                                type="checkbox"
                                checked={selectedLandingIds.has(landing.id)}
                                onChange={(e) => toggleSelected(landing.id, e.target.checked)}
                                className="rounded flex-shrink-0"
                                style={{ accentColor: 'var(--color-primary)' }}
                                aria-label={landing.name}
                            />
                            <span
                                className="font-semibold text-sm cursor-pointer truncate"
                                style={{ color: 'var(--color-primary)' }}
                                onClick={() => handleEdit(landing.id)}
                                title={landing.name}
                            >
                                {landing.name}
                            </span>
                            <span className="px-2 py-0.5 rounded text-[10px] font-semibold flex-shrink-0" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                                {landing.type}
                            </span>
                        </>
                    )}
                    renderSubtitle={(landing) => `#${landing.id}${landing.group_name ? ` · ${landing.group_name}` : ''}`}
                    renderHeaderRight={(landing) => (
                        <>
                            <span className="flex items-center text-xs font-medium mr-1" style={{ color: landing.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}>
                                <span className="w-2 h-2 rounded-full mr-1.5" style={{ backgroundColor: landing.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}></span>
                                {landing.state === 'active' ? t('components.active') : t('components.archive')}
                            </span>
                            <button onClick={() => handleEdit(landing.id)} className="action-btn text-blue" title={t('common.edit') || t('components.edit')}>
                                <Edit3 className="w-4 h-4" />
                            </button>
                            <button onClick={() => handleDelete(landing.id)} className="action-btn text-red" title={t('common.delete')}>
                                <Trash2 className="w-4 h-4" />
                            </button>
                        </>
                    )}
                    fields={[
                        ...chosenColumns.map((colId) => {
                            const def = ALL_REPORT_METRICS.find(m => m.id === colId);
                            return {
                                id: colId,
                                label: def?.shortLabel || def?.label || colId,
                                render: (row) => formatMetricCell(colId, row),
                            };
                        }),
                        { id: 'group_name', label: entityLabel('group_name'), render: (l) => l.group_name || '-' },
                        { id: 'type', label: entityLabel('type'), render: (l) => l.type },
                        { id: 'url', label: 'URL', render: (l) => (l.type !== 'local' && l.type !== 'action' && l.url ? <span className="break-all whitespace-normal">{l.url}</span> : '-') },
                        { id: 'last_event', label: entityLabel('last_event'), render: (l) => formatLastEvent(l.last_event) },
                    ]}
                    primaryIds={['clicks', 'conversions', 'cost', 'roi']}
                    emptyState={
                        <div className="text-center py-12">
                            <div className="empty-state">
                                <p className="empty-state-title">{t('landings.noLandings')}</p>
                                <p className="empty-state-text">{t('landings.noLandingsDesc')}</p>
                            </div>
                        </div>
                    }
                />
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

            <ReportCustomizerModal
                isOpen={columnsModalOpen}
                onClose={() => setColumnsModalOpen(false)}
                selectedColumns={chosenColumns}
                onSaveColumns={(ids) => {
                    setChosenColumns(ids);
                    localStorage.setItem(LANDING_COLUMNS_KEY, JSON.stringify(ids));
                    setColumnsModalOpen(false);
                }}
                mode="landings"
            />
        </div>
    );
};

export default Landings;
