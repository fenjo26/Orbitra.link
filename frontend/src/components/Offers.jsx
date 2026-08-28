import React, { useState, useMemo, useEffect, useCallback, useRef } from 'react';
import { Plus, Search, Trash2, Edit3, Settings2, RefreshCw, X, ChevronUp, ChevronDown, ChevronsUpDown, Copy, SlidersHorizontal, GripVertical, MoreVertical } from 'lucide-react';
import InfoBanner from './InfoBanner';
import OfferEditor from './OfferEditor';
import GroupsModal from './GroupsModal';
import ReportCustomizerModal, { ALL_REPORT_METRICS, PRESETS, getReportMetricTooltip, normalizeReportMetricIds } from './ReportCustomizerModal';
import { useIsDesktop, useResizableTableColumns, ColumnResizeHandle } from './common/ColumnResize';
import { SortableTh, sortRows, nextSortState } from './common/SortableTh';
import DateRangePicker, { formatDate, getPresetDates } from './DateRangePicker';
import { useTimezone } from '../utils/useTimezone';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';
import { entityDeleteErrorText } from '../utils/entityInUseError';
import PaginationToolbar from './common/PaginationToolbar';
import MobileCards from './common/MobileCards';

const API_URL = '/api.php';

// Fixed entity columns for offers — these are always present and not part of
// the metric customization. Metrics come from ALL_REPORT_METRICS.
const FIXED_OFFER_COLUMNS = [
    { id: 'checkbox', label: '', fixed: true },
    { id: 'id', label: 'ID', fixed: true },
    { id: 'state', label: 'Status', fixed: true },
    { id: 'name', label: 'Name', fixed: true },
    { id: 'group_name', label: 'Group', fixed: true },
    { id: 'affiliate_network_name', label: 'Affiliate Network', fixed: true },
    { id: 'geo', label: 'GEO', fixed: true },
    { id: 'payout', label: 'Payout', fixed: true },
    { id: 'redirect_type', label: 'Type', fixed: true },
];

const OFFER_COLUMNS_KEY = 'orbitra_offer_columns';

// The saved list holds *metric* ids only. An entity field that ends up in there —
// `name`, `state`, `affiliate_network_name` — misses ALL_REPORT_METRICS.find(), and
// the dynamic header falls back to `|| colId`, printing the raw DB column name in a
// second column beside the properly labelled fixed one. Drop anything that is not a
// real metric, and anything already rendered as a fixed column.
const FIXED_OFFER_COLUMN_IDS = new Set(FIXED_OFFER_COLUMNS.map(c => c.id));

const sanitizeOfferMetricIds = (ids) => normalizeReportMetricIds(ids)
    .filter(id => !FIXED_OFFER_COLUMN_IDS.has(id) && ALL_REPORT_METRICS.some(m => m.id === id));

const loadOfferColumns = () => {
    try {
        const saved = localStorage.getItem(OFFER_COLUMNS_KEY);
        if (saved) {
            const parsed = JSON.parse(saved);
            const cleaned = sanitizeOfferMetricIds(parsed);
            if (cleaned.length) {
                // Write the cleaned list back, so a bad id is repaired once rather
                // than re-filtered on every mount until the user happens to save.
                if (JSON.stringify(cleaned) !== JSON.stringify(parsed)) {
                    localStorage.setItem(OFFER_COLUMNS_KEY, JSON.stringify(cleaned));
                }
                return cleaned;
            }
        }
    } catch (e) {}
    // Fallback to 'best' preset for offers (revenue-focused metrics)
    return sanitizeOfferMetricIds(PRESETS.best);
};

const Offers = ({ offers: initialOffers = [], refreshData }) => {
    const { t } = useLanguage();
    const [isEditorOpen, setIsEditorOpen] = useState(false);
    const [editingOfferId, setEditingOfferId] = useState(null);
    const [isGroupsModalOpen, setIsGroupsModalOpen] = useState(false);
    const [filterGroup, setFilterGroup] = useState('');
    const [search, setSearch] = useState('');
    const [pageSize, setPageSize] = useState(() => {
        const saved = localStorage.getItem('orbitra_table_page_size');
        return saved === 'All' ? 'All' : ([25, 50, 100, 250].includes(Number(saved)) ? Number(saved) : 25);
    });
    const [currentPage, setCurrentPage] = useState(0);
    // Type pill: '' | 'local' | 'external' — local archives vs everything else
    const [typeTab, setTypeTab] = useState('');
    const [filterNetwork, setFilterNetwork] = useState('');
    const [filterState, setFilterState] = useState('');
    const [selectedOfferIds, setSelectedOfferIds] = useState(() => new Set());
    const [sortBy, setSortBy] = useState({ key: null, dir: 'desc' }); // key=null keeps API order
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [columnsModalOpen, setColumnsModalOpen] = useState(false);
    const [chosenColumns, setChosenColumns] = useState(() => loadOfferColumns());

    // Header Drag-and-Drop state
    const [thDragIdx, setThDragIdx] = useState(null);
    const [thDragOverIdx, setThDragOverIdx] = useState(null);

    // Resizable columns — desktop table only; below lg the list renders as
    // MobileCards and skips resizing entirely.
    const isDesktop = useIsDesktop();
    const columnDefs = useMemo(() => ([
        { id: 'check', width: 40 },
        { id: 'id', width: 70 },
        { id: 'state', width: 90 },
        { id: 'name', width: 260 },
        { id: 'group_name', width: 130 },
        { id: 'affiliate_network_name', width: 140 },
        { id: 'geo', width: 80 },
        { id: 'payout', width: 110 },
        { id: 'redirect_type', width: 110 },
        ...chosenColumns.map(id => ({ id, width: 120 })),
        { id: 'actions', width: 110 }
    ]), [chosenColumns]);
    const colResize = useResizableTableColumns({ tableId: 'offers', columns: columnDefs, enabled: isDesktop });

    // Row action dropdown (⋮)
    const [menuAnchor, setMenuAnchor] = useState(null);

    // This page owns its reporting period instead of inheriting the dashboard
    // range. Only joined traffic rows are date-limited; zero-traffic offers
    // stay visible and editable.
    const [offers, setOffers] = useState(initialOffers);
    const [dateFrom, setDateFrom] = useState(() => getPresetDates('today')?.from || formatDate(new Date()));
    const [dateTo, setDateTo] = useState(() => getPresetDates('today')?.to || formatDate(new Date()));
    const [timezone, setTimezone] = useTimezone();
    const fetchSequence = useRef(0);

    const fetchOffers = useCallback(async ({ showSpinner = true } = {}) => {
        const sequence = ++fetchSequence.current;
        if (showSpinner) setRefreshing(true);
        try {
            const res = await axios.get(`${API_URL}?action=offers`, {
                params: {
                    date_from: dateFrom,
                    date_to: dateTo,
                    timezone
                }
            });
            if (sequence === fetchSequence.current && res.data.status === 'success') {
                setOffers(res.data.data || []);
            }
        } catch (error) {
            console.error('Failed to load offer statistics', error);
        } finally {
            if (sequence === fetchSequence.current) setRefreshing(false);
        }
    }, [dateFrom, dateTo, timezone]);

    useEffect(() => {
        fetchOffers({ showSpinner: false });
    }, [fetchOffers]);

    const refreshOfferData = async () => {
        const tasks = [fetchOffers()];
        if (refreshData) tasks.push(Promise.resolve().then(() => refreshData()));
        await Promise.allSettled(tasks);
    };

    const handleDateChange = (from, to) => {
        setDateFrom(from);
        setDateTo(to);
    };

    const handleSaveColumns = (cols) => {
        // Sanitise on the way in as well as on load, so a bad id never reaches
        // storage in the first place.
        const cleaned = sanitizeOfferMetricIds(cols);
        setChosenColumns(cleaned);
        localStorage.setItem(OFFER_COLUMNS_KEY, JSON.stringify(cleaned));
    };

    const handleThDragStart = (e, idx) => {
        // Firefox refuses to start a native drag until the payload is set.
        e.dataTransfer.setData('text/plain', String(idx));
        e.dataTransfer.effectAllowed = 'move';
        setThDragIdx(idx);
    };

    const handleThDragOver = (e, idx) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (thDragOverIdx !== idx) {
            setThDragOverIdx(idx);
        }
    };

    const handleThDrop = (e, targetIdx) => {
        e.preventDefault();
        if (thDragIdx !== null && thDragIdx !== targetIdx) {
            const sourceColId = chosenColumns[thDragIdx];
            const targetColId = chosenColumns[targetIdx];
            if (sourceColId && targetColId) {
                const copy = [...chosenColumns];
                const from = copy.indexOf(sourceColId);
                const to = copy.indexOf(targetColId);
                if (from !== -1 && to !== -1) {
                    const [item] = copy.splice(from, 1);
                    copy.splice(to, 0, item);
                    setChosenColumns(copy);
                    localStorage.setItem(OFFER_COLUMNS_KEY, JSON.stringify(copy));
                }
            }
        }
        setThDragIdx(null);
        setThDragOverIdx(null);
    };

    const handleThDragEnd = () => {
        setThDragIdx(null);
        setThDragOverIdx(null);
    };

    const handleToggleMenu = (event, offerId) => {
        event.stopPropagation();
        if (menuAnchor?.id === offerId) {
            setMenuAnchor(null);
            return;
        }
        const rect = event.currentTarget.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom;
        const openUp = spaceBelow < 240;
        setMenuAnchor({
            id: offerId,
            top: openUp ? rect.top - 8 : rect.bottom + 4,
            right: Math.max(8, window.innerWidth - rect.right),
            openUp
        });
    };

    // Close menu on outside clicks
    useEffect(() => {
        if (!menuAnchor) return undefined;
        const close = () => setMenuAnchor(null);
        window.addEventListener('click', close);
        return () => window.removeEventListener('click', close);
    }, [menuAnchor]);

    // Get unique values for filters
    const groups = [...new Set(offers.map(o => o.group_name).filter(Boolean))];
    const networks = [...new Set(offers.map(o => o.affiliate_network_name).filter(Boolean))];
    const noGroupCount = offers.filter(o => !o.group_name).length;
    const localCount = offers.filter(o => o.is_local).length;
    const externalCount = offers.length - localCount;
    const groupCount = (name) => offers.filter(o => o.group_name === name).length;

    const filteredOffers = offers.filter(o => {
        const q = String(search || '').trim().toLowerCase();
        if (q) {
            const haystack = [o.name, o.url, o.group_name, o.affiliate_network_name]
                .map(v => String(v || '').toLowerCase());
            if (!haystack.some(v => v.includes(q)) && String(o.id || '') !== q) return false;
        }
        if (filterGroup === '__no_group__' && o.group_name) return false;
        if (filterGroup && filterGroup !== '__no_group__' && o.group_name !== filterGroup) return false;
        if (typeTab === 'local' && !o.is_local) return false;
        if (typeTab === 'external' && o.is_local) return false;
        if (filterNetwork && o.affiliate_network_name !== filterNetwork) return false;
        if (filterState === 'active' && o.state !== 'active') return false;
        if (filterState === 'inactive' && o.state === 'active') return false;
        return true;
    });

    const requestSort = (key, defaultDir = 'asc') => {
        setSortBy(prev => nextSortState(prev, key, defaultDir));
    };

    const visibleOffers = useMemo(() => sortRows(filteredOffers, sortBy), [filteredOffers, sortBy]);

    // The paged slice the table renders. Totals footer and CSV export stay
    // over the whole filtered list — paging must not change TOTAL.
    const pagedOffers = useMemo(() => {
        if (pageSize === 'All') return visibleOffers;
        const start = currentPage * pageSize;
        return visibleOffers.slice(start, start + pageSize);
    }, [visibleOffers, currentPage, pageSize]);

    // Any narrowing of the list must drop the user back to the first page.
    useEffect(() => {
        setCurrentPage(0);
    }, [search, filterGroup, filterNetwork, filterState, typeTab, pageSize]);

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
                const res = await axios.post(`${API_URL}?action=delete_offer`, { id });
                if (res?.data?.status !== 'success') {
                    alert(entityDeleteErrorText(t, res?.data));
                    return;
                }
                await refreshOfferData();
            } catch (err) {
                alert(entityDeleteErrorText(t, null, err));
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
            const res = await axios.post(`${API_URL}?action=bulk_delete_offers`, { ids });
            if (res?.data?.status !== 'success') {
                alert(entityDeleteErrorText(t, res?.data));
                return;
            }
            setSelectedOfferIds(new Set());
            await refreshOfferData();
        } catch (err) {
            alert(entityDeleteErrorText(t, null, err));
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
            } catch {
                errorCount++;
            }
        }

        if (successCount > 0) {
            alert(`${t('offers.copied')}: ${successCount}`);
            await refreshOfferData();
        }
        if (errorCount > 0) {
            alert(`${t('offers.copyErrors')}: ${errorCount}`);
        }

        setSelectedOfferIds(new Set());
    };

    const handleEditorClose = (wasSaved) => {
        setIsEditorOpen(false);
        if (wasSaved) {
            refreshOfferData();
        }
    };

    const clearFilters = () => {
        setFilterGroup('');
        setFilterNetwork('');
        setFilterState('');
        setSearch('');
    };

    const hasActiveFilters = filterGroup || filterNetwork || filterState || search;

    // Calculate grand totals for all visible offers (not just paged slice).
    // Ratios (CR/EPC/CPC/ROI) are recomputed from the summed base metrics.
    const grandTotals = useMemo(() => {
        const t0 = {
            clicks: 0, unique_clicks: 0, visits: 0, unique_visits: 0,
            lp_clicks: 0, lp_views: 0, conversions: 0, leads: 0, sales: 0,
            rejected: 0, trash: 0, cost: 0, revenue: 0, revenue_confirmed: 0,
            revenue_hold: 0, revenue_rejected: 0, revenue_trash: 0,
            registrations: 0, deposits: 0, bots: 0, proxies: 0, empty_referrers: 0
        };

        visibleOffers.forEach(o => {
            t0.clicks += Number(o.clicks) || 0;
            t0.unique_clicks += Number(o.unique_clicks) || 0;
            const lpViews = Number(o.visits ?? o.clicks) || 0;
            t0.visits += lpViews;
            t0.lp_views += lpViews;
            t0.lp_clicks += Number(o.lp_clicks) || 0;
            t0.conversions += Number(o.conversions) || 0;
            t0.leads += Number(o.leads) || 0;
            t0.sales += Number(o.sales) || 0;
            t0.rejected += Number(o.rejected) || 0;
            t0.trash += Number(o.trash) || 0;
            t0.cost += Number(o.cost) || 0;
            t0.revenue += Number(o.revenue) || 0;
            t0.revenue_confirmed += Number(o.revenue_confirmed) || 0;
            t0.revenue_hold += Number(o.revenue_hold) || 0;
            t0.revenue_rejected += Number(o.revenue_rejected) || 0;
            t0.revenue_trash += Number(o.revenue_trash) || 0;
            t0.registrations += Number(o.registrations) || 0;
            t0.deposits += Number(o.deposits) || 0;
            t0.bots += Number(o.bots) || 0;
            t0.proxies += Number(o.proxies) || 0;
            t0.empty_referrers += Number(o.empty_referrers) || 0;
        });

        // Computed ratios from totals (same logic as Campaigns.jsx)
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
    }, [visibleOffers]);

    // Unified metric cell formatter (same as Campaigns.jsx)
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
            case 'lp_views':
            case 'lp_clicks':
            case 'sales':
            case 'leads':
            case 'registrations':
            case 'deposits':
            case 'rejected':
            case 'trash':
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
                return num.toLocaleString();
            case 'conversions':
                // Match the row formatter: a totals row reading 0 in success green
                // reads as a positive signal when scanning the table.
                return num > 0 ? <span className="font-semibold" style={{ color: 'var(--color-success)' }}>{num.toLocaleString()}</span> : '0';
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
        await fetchOffers();
    };

    // Entity column label helper
    const entityLabel = (colId) => ({
        id: 'ID',
        name: t('editor.name'),
        state: t('components.status'),
        affiliate_network_name: t('offers.network'),
        group_name: t('components.group'),
        redirect_type: t('components.type'),
        geo: t('offerColumns.geo'),
        payout: t('offerColumns.payout'),
    }[colId] || colId);

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
                    <div className="relative tb-search" style={{ width: 220 }}>
                        <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--color-text-muted)' }} />
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder={t('offers.searchPlaceholder', 'Search offers...')}
                            className="form-input"
                            style={{ fontSize: '0.75rem', padding: '0.5rem 1.9rem', paddingLeft: '2.1rem', borderRadius: '0.75rem' }}
                        />
                        {search && (
                            <button
                                type="button"
                                onClick={() => setSearch('')}
                                title={t('common.clear', 'Clear')}
                                className="absolute right-2.5 top-1/2 -translate-y-1/2 flex items-center justify-center"
                                style={{ color: 'var(--color-text-muted)', width: 18, height: 18 }}
                            >
                                <X className="w-3 h-3" />
                            </button>
                        )}
                    </div>
                    {selectedOfferIds.size > 0 && (
                        <>
                            <button onClick={handleBulkCopySelected} className="btn btn-success text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5" title={t('offers.copySelected')}>
                                <Copy className="w-3.5 h-3.5" />
                                {(t('offers.copySelected'))} ({selectedOfferIds.size})
                            </button>
                            <button onClick={handleBulkDeleteSelected} className="btn btn-danger text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5" title={t('common.deleteSelected')}>
                                <Trash2 className="w-3.5 h-3.5" />
                                {(t('common.deleteSelected') || t('common.delete'))} ({selectedOfferIds.size})
                            </button>
                        </>
                    )}
                </div>
                <div className="flex gap-2">
                    {/* Columns Customizer Button */}
                    <button
                        type="button"
                        onClick={() => setColumnsModalOpen(true)}
                        className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5 font-medium"
                    >
                        <SlidersHorizontal className="w-3.5 h-3.5" style={{ color: 'var(--color-primary)' }} />
                        <span>{t('reportCustomizer.columns')}</span>
                        <span className="text-[10px] px-1.5 py-0.2 rounded-full" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                            {chosenColumns.length}
                        </span>
                    </button>
                    <button
                        type="button"
                        onClick={handleRefresh}
                        className="btn btn-ghost btn-icon p-1.5 rounded-xl"
                        title={t('common.refresh')}
                        disabled={refreshing}
                    >
                        <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin' : ''}`} />
                    </button>
                    <button
                        type="button"
                        className="btn btn-ghost btn-icon p-1.5 rounded-xl"
                        title={t('common.settings', 'Settings')}
                        onClick={() => setSettingsOpen(true)}
                    >
                        <Settings2 className="w-4 h-4" />
                    </button>
                </div>
            </div>

            {/* Filters and Date Range */}
            <div className="flex flex-wrap items-center justify-between gap-3 py-3 mb-3 border-b" style={{ borderColor: 'var(--color-border)' }}>
                <div className="flex flex-wrap items-center gap-2">
                    <select
                        value={filterGroup}
                        onChange={(e) => setFilterGroup(e.target.value)}
                        className="form-select text-xs font-semibold py-2 px-3.5 rounded-xl transition-all tb-release"
                        style={{
                            width: 'auto',
                            minWidth: '140px',
                            backgroundColor: filterGroup ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                            borderColor: filterGroup ? 'var(--color-primary)' : 'var(--color-border)',
                            color: filterGroup ? 'var(--color-primary)' : 'var(--color-text-primary)'
                        }}
                    >
                        <option value="">{t('offers.allGroups')}</option>
                        {groups.map(g => <option key={g} value={g}>{g}</option>)}
                        {noGroupCount > 0 && <option value="__no_group__">{t('offerEditor.noGroup')}</option>}
                    </select>

                    <select
                        value={filterNetwork}
                        onChange={(e) => setFilterNetwork(e.target.value)}
                        className="form-select text-xs font-semibold py-2 px-3.5 rounded-xl transition-all"
                        style={{
                            width: 'auto',
                            minWidth: '180px',
                            backgroundColor: filterNetwork ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                            borderColor: filterNetwork ? 'var(--color-primary)' : 'var(--color-border)',
                            color: filterNetwork ? 'var(--color-primary)' : 'var(--color-text-primary)'
                        }}
                    >
                        <option value="">{t('offers.allNetworks')}</option>
                        {networks.map(n => <option key={n} value={n}>{n}</option>)}
                    </select>

                    <select
                        value={filterState}
                        onChange={(e) => setFilterState(e.target.value)}
                        className="form-select text-xs font-semibold py-2 px-3.5 rounded-xl transition-all"
                        style={{
                            width: 'auto',
                            minWidth: '130px',
                            backgroundColor: filterState ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                            borderColor: filterState ? 'var(--color-primary)' : 'var(--color-border)',
                            color: filterState ? 'var(--color-primary)' : 'var(--color-text-primary)'
                        }}
                    >
                        <option value="">{t('offers.allStates')}</option>
                        <option value="active">🟢 {t('components.active')}</option>
                        <option value="inactive">⚪ {t('offers.inactiveStates')}</option>
                    </select>

                    {hasActiveFilters && (
                        <button type="button" onClick={clearFilters} className="btn btn-ghost btn-sm text-xs" style={{ color: 'var(--color-danger)' }}>
                            <X className="w-3.5 h-3.5" />
                            {t('common.clear')}
                        </button>
                    )}
                </div>

                <DateRangePicker
                    dateFrom={dateFrom}
                    dateTo={dateTo}
                    onChange={handleDateChange}
                    selectedTimezone={timezone}
                    onTimezoneChange={setTimezone}
                />
            </div>

            {/* Group pill tabs */}
            <div className="flex items-center gap-2 overflow-x-auto pb-2 pt-1 mb-4 border-b" style={{ borderColor: 'var(--color-border)' }}>
                {[
                    { key: '', label: t('common.all'), count: offers.length },
                    ...groups.map(name => ({
                        key: name,
                        label: name,
                        count: groupCount(name),
                        icon: /white|safe|бел/i.test(name) ? '🛡' : (/cod|товар/i.test(name) ? '📦' : (/nutra|нутр/i.test(name) ? '💊' : null)),
                    })),
                    ...(noGroupCount > 0 ? [{ key: '__no_group__', label: t('landings.noGroup'), count: noGroupCount }] : []),
                ].map(tab => {
                    const activePill = tab.key === '' ? !filterGroup : filterGroup === tab.key;
                    return (
                        <button
                            key={tab.key || '__all__'}
                            type="button"
                            onClick={() => setFilterGroup(activePill ? '' : tab.key)}
                            className="px-3.5 py-1.5 rounded-xl text-xs font-semibold whitespace-nowrap transition-all flex items-center gap-1.5"
                            style={{
                                backgroundColor: activePill ? 'var(--color-primary)' : 'var(--color-bg-card)',
                                color: activePill ? 'var(--color-text-inverse)' : 'var(--color-text-secondary)',
                                border: `1px solid ${activePill ? 'var(--color-primary)' : 'var(--color-border)'}`,
                                cursor: 'pointer'
                            }}
                        >
                            {tab.icon && <span>{tab.icon}</span>}
                            <span>{tab.label}</span>
                            <span className="text-[10px] px-1.5 rounded-full" style={{ backgroundColor: activePill ? 'color-mix(in srgb, var(--color-text-inverse) 15%, transparent)' : 'var(--color-bg-soft)', color: 'inherit' }}>
                                {tab.count}
                            </span>
                        </button>
                    );
                })}

                <div className="h-5 w-[1px] flex-shrink-0" style={{ backgroundColor: 'var(--color-border)' }} />

                {[
                    { key: 'local', label: t('landingEditor.typeLocal'), count: localCount, icon: '📁' },
                    { key: 'external', label: t('landingEditor.typeRedirect'), count: externalCount, icon: '🔗' },
                ].map(tab => {
                    const activePill = typeTab === tab.key;
                    return (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => setTypeTab(activePill ? '' : tab.key)}
                            className="px-3 py-1.5 rounded-xl text-xs font-medium whitespace-nowrap transition-all flex items-center gap-1.5"
                            style={{
                                backgroundColor: activePill ? 'var(--color-primary-light)' : 'transparent',
                                color: activePill ? 'var(--color-primary)' : 'var(--color-text-muted)',
                                border: `1px solid ${activePill ? 'var(--color-primary)' : 'var(--color-border)'}`,
                                cursor: 'pointer'
                            }}
                        >
                            <span>{tab.icon} {tab.label}</span>
                            <span className="text-[10px] px-1.5 rounded-full" style={{ backgroundColor: 'var(--color-bg-soft)' }}>
                                {tab.count}
                            </span>
                        </button>
                    );
                })}
            </div>

            {/* Table — desktop table / mobile stacked cards */}
            <div className="tracker-table-container hidden lg:block">
                <table className="page-table tracker-table" style={{ ...colResize.tableStyle }}>
                    {colResize.colgroup}
                    <thead>
                        <tr>
                            <th className="col-check">
                                <input
                                    type="checkbox"
                                    checked={allFilteredSelected}
                                    ref={(el) => {
                                        if (el) el.indeterminate = !allFilteredSelected && someFilteredSelected;
                                    }}
                                    onChange={(e) => toggleSelectAllFiltered(e.target.checked)}
                                />
                            </th>
                            <SortableTh sortBy={sortBy} requestSort={requestSort} colKey="id" label="ID" defaultDir="desc" resize={colResize} />
                            <th className="resizable-th">
                                {t('common.status')}
                                <ColumnResizeHandle rt={colResize} colId="state" />
                            </th>
                            <SortableTh sortBy={sortBy} requestSort={requestSort} colKey="name" label={t('editor.name')} defaultDir="asc" resize={colResize} />
                            <SortableTh sortBy={sortBy} requestSort={requestSort} colKey="group_name" label={t('components.group')} defaultDir="asc" resize={colResize} />
                            <SortableTh sortBy={sortBy} requestSort={requestSort} colKey="affiliate_network_name" label={t('offers.network')} defaultDir="asc" resize={colResize} />
                            <SortableTh sortBy={sortBy} requestSort={requestSort} colKey="geo" label="GEO" defaultDir="asc" resize={colResize} />
                            <th className="resizable-th cell-text">
                                {t('offerColumns.payout')}
                                <ColumnResizeHandle rt={colResize} colId="payout" />
                            </th>
                            <SortableTh sortBy={sortBy} requestSort={requestSort} colKey="redirect_type" label={t('components.type')} defaultDir="asc" resize={colResize} />

                            {/* Dynamic metric columns */}
                            {chosenColumns.map((colId, colIdx) => {
                                const def = ALL_REPORT_METRICS.find(m => m.id === colId);
                                return (
                                    <SortableTh
                                                    sortBy={sortBy}
                                                    requestSort={requestSort}
                                        key={colId}
                                        colKey={colId}
                                        label={def?.shortLabel || def?.label || colId}
                                        fullTitle={getReportMetricTooltip(def, t)}
                                        defaultDir="desc"
                                        alignRight={true}
                                        draggable={true}
                                        resize={colResize}
                                        isDragOver={thDragOverIdx === colIdx && thDragIdx !== null && thDragIdx !== colIdx}
                                        onDragStart={(e) => handleThDragStart(e, colIdx)}
                                        onDragOver={(e) => handleThDragOver(e, colIdx)}
                                        onDrop={(e) => handleThDrop(e, colIdx)}
                                        onDragEnd={handleThDragEnd}
                                    />
                                );
                            })}
                            <th className="resizable-th cell-text">
                                {t('common.actions')}
                                <ColumnResizeHandle rt={colResize} colId="actions" />
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {visibleOffers.length === 0 ? (
                            <tr>
                                <td colSpan={10 + chosenColumns.length} className="text-center py-12">
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
                            pagedOffers.map((offer) => (
                                <tr key={offer.id}>
                                    <td className="col-check">
                                        <input
                                            type="checkbox"
                                            checked={selectedOfferIds.has(offer.id)}
                                            onChange={(e) => toggleSelected(offer.id, e.target.checked)}
                                        />
                                    </td>
                                    <td className="font-medium cell-text">{offer.id}</td>
                                    <td>
                                        <span className="flex items-center text-xs font-medium" style={{ color: offer.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}>
                                            <span className="w-2 h-2 rounded-full mr-1.5" style={{ backgroundColor: offer.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}></span>
                                            {offer.state === 'active' ? t('components.active') : t('components.archive')}
                                        </span>
                                    </td>
                                    <td>
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
                                            {Boolean(offer.is_local) && (
                                                <span style={{ color: 'var(--color-accent-purple)', fontSize: '12px' }}>{t('offers.localOffer')}</span>
                                            )}
                                        </div>
                                    </td>
                                    <td className="cell-text" style={{ color: 'var(--color-text-secondary)' }}>{offer.group_name || '-'}</td>
                                    <td className="cell-text" style={{ color: 'var(--color-text-secondary)' }}>{offer.affiliate_network_name || '-'}</td>
                                    <td><span className="px-2 py-1 rounded text-xs font-semibold" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>{offer.geo || t('offerColumns.allGeo')}</span></td>
                                    <td className="cell-text" style={{ color: 'var(--color-text-secondary)' }}>
                                        {offer.payout_auto ? t('offerColumns.payoutAuto') : `$${parseFloat(offer.payout_value || 0).toFixed(2)} (${String(offer.payout_type || 'cpa').toUpperCase()})`}
                                    </td>
                                    <td>
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

                                    {/* Metric cells */}
                                    {chosenColumns.map((colId) => (
                                        <td key={colId} className="cell-text">
                                            {formatMetricCell(colId, offer)}
                                        </td>
                                    ))}

                                    <td>
                                        <div className="flex items-center justify-center gap-1">
                                            <button onClick={() => handleEdit(offer.id)} className="action-btn text-blue" title={t('common.edit') || t('components.edit')}>
                                                <Edit3 className="w-4 h-4" />
                                            </button>
                                            <button onClick={(e) => handleToggleMenu(e, offer.id)} className="action-btn text-gray-500" title={t('common.more')}>
                                                <MoreVertical className="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                    {/* Totals Footer */}
                    {visibleOffers.length > 0 && (
                        <tfoot style={{ background: 'var(--color-bg-soft)' }}>
                            <tr className="font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                <td className="col-check"></td>
                                <td colSpan={8}>Σ Total ({visibleOffers.length})</td>
                                {chosenColumns.map((colId) => (
                                    <td key={colId} className="cell-text">
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
                set; fixed offer columns (GEO, payout, type, network) live in
                the "More" expander. */}
            <div className="lg:hidden">
                <MobileCards
                    rows={pagedOffers}
                    getId={(offer) => offer.id}
                    renderTitle={(offer) => (
                        <>
                            <input
                                type="checkbox"
                                checked={selectedOfferIds.has(offer.id)}
                                onChange={(e) => toggleSelected(offer.id, e.target.checked)}
                                className="rounded flex-shrink-0"
                                style={{ accentColor: 'var(--color-primary)' }}
                                aria-label={offer.name}
                            />
                            <span
                                className="font-semibold text-sm cursor-pointer flex-1 min-w-0 line-clamp-2 break-words"
                                style={{ color: 'var(--color-primary)' }}
                                onClick={() => handleEdit(offer.id)}
                                title={offer.name}
                            >
                                {offer.name}
                            </span>
                            {Boolean(offer.is_local) && (
                                <span className="text-[10px] font-medium flex-shrink-0" style={{ color: 'var(--color-accent-purple)' }}>{t('offers.localOffer')}</span>
                            )}
                        </>
                    )}
                    renderSubtitle={(offer) => `#${offer.id}${offer.group_name ? ` · ${offer.group_name}` : ''}`}
                    renderHeaderRight={(offer) => (
                        <>
                            <span className="flex items-center text-xs font-medium mr-1" style={{ color: offer.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}>
                                <span className="w-2 h-2 rounded-full mr-1.5" style={{ backgroundColor: offer.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}></span>
                                {offer.state === 'active' ? t('components.active') : t('components.archive')}
                            </span>
                            <button onClick={() => handleEdit(offer.id)} className="action-btn text-blue" title={t('common.edit') || t('components.edit')}>
                                <Edit3 className="w-4 h-4" />
                            </button>
                            <button onClick={(e) => handleToggleMenu(e, offer.id)} className="action-btn text-gray-500" title={t('common.more')}>
                                <MoreVertical className="w-4 h-4" />
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
                        {
                            id: 'geo',
                            label: 'GEO',
                            render: (o) => <span className="px-2 py-0.5 rounded text-[11px] font-semibold" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>{o.geo || t('offerColumns.allGeo')}</span>,
                        },
                        {
                            id: 'payout',
                            label: t('offerColumns.payout'),
                            render: (o) => o.payout_auto ? t('offerColumns.payoutAuto') : `$${parseFloat(o.payout_value || 0).toFixed(2)} (${String(o.payout_type || 'cpa').toUpperCase()})`,
                        },
                        {
                            id: 'redirect_type',
                            label: t('components.type'),
                            render: (o) => (
                                <span className="px-2 py-0.5 rounded text-[11px] font-semibold" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                                    {o.redirect_type === 'redirect' ? t('offers.redirect') :
                                        o.redirect_type === 'frame' ? t('offers.iframe') :
                                            o.redirect_type === 'local' ? t('offers.local') :
                                                o.redirect_type === 'js' ? t('redirectTypes.jsName') :
                                                    o.redirect_type === 'meta_refresh' ? t('redirectTypes.metaName') :
                                                        o.redirect_type === 'form_submit' ? t('redirectTypes.formName') :
                                                            o.redirect_type === 'preload' ? t('offerEditor.preloadCurl') :
                                                                o.redirect_type === 'curl_proxy' ? t('redirectTypes.curlProxyName') :
                                                                    o.redirect_type}
                                </span>
                            ),
                        },
                        { id: 'affiliate_network_name', label: t('offers.network'), render: (o) => o.affiliate_network_name || '-' },
                        {
                            id: 'url',
                            label: 'URL',
                            render: (o) => (!o.is_local && o.url ? <span className="break-all whitespace-normal">{o.url}</span> : '-'),
                        },
                    ]}
                    primaryIds={['clicks', 'conversions', 'cost', 'roi']}
                    emptyState={
                        <div className="text-center py-12">
                            <div className="empty-state">
                                <p className="empty-state-title">
                                    {offers.length === 0 ? t('offers.noOffers') : t('offers.noOffersFiltered')}
                                </p>
                                <p className="empty-state-text">
                                    {offers.length === 0 ? t('offers.noOffersDesc') : t('offers.changeFilters')}
                                </p>
                            </div>
                        </div>
                    }
                />
            </div>

            <PaginationToolbar
                totalRows={visibleOffers.length}
                currentPage={currentPage}
                pageSize={pageSize}
                onPageChange={setCurrentPage}
                onPageSizeChange={(size) => { setPageSize(size); setCurrentPage(0); }}
            />

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

            {/* Report Customizer Modal */}
            <ReportCustomizerModal
                isOpen={columnsModalOpen}
                onClose={() => setColumnsModalOpen(false)}
                selectedColumns={chosenColumns}
                onSaveColumns={handleSaveColumns}
                mode="offers"
                onResetColumnWidths={colResize.api.resetAll}
            />

            {/* Row Action Menu */}
            {menuAnchor && (
                <div
                    className="dropdown-menu"
                    style={{
                        position: 'fixed',
                        top: menuAnchor.top,
                        right: menuAnchor.right,
                        zIndex: 1000
                    }}
                >
                    <button
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            handleEdit(menuAnchor.id);
                            setMenuAnchor(null);
                        }}
                        className="dropdown-item"
                    >
                        {t('common.edit')}
                    </button>
                    <button
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            setMenuAnchor(null);
                            handleDelete(menuAnchor.id);
                        }}
                        className="dropdown-item text-red-600"
                    >
                        {t('common.delete')}
                    </button>
                </div>
            )}

            {/* Settings Modal */}
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
