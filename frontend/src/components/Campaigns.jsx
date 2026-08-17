import React, { useState, useMemo, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { Plus, Search, Trash2, Edit3, Settings2, DollarSign, XCircle, ChevronUp, ChevronDown, ChevronsUpDown, RefreshCw, X, Copy, BarChart2, SlidersHorizontal, GripVertical, MoreVertical, AlertTriangle } from 'lucide-react';
import InfoBanner from './InfoBanner';
import GroupsModal from './GroupsModal';
import PaginationToolbar from './common/PaginationToolbar';
import CampaignReports from './CampaignReports';
import DateRangePicker, { formatDate, getPresetDates } from './DateRangePicker';
import ReportCustomizerModal, { ALL_REPORT_METRICS, PRESETS, getDefaultTemplateColumns, getReportMetricTooltip, normalizeReportMetricIds } from './ReportCustomizerModal';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';
import { copyToClipboard } from '../utils/clipboard';
import { financeVisibility, financeHiddenMetric } from '../utils/permissions';

const API_URL = '/api.php';

const Campaigns = ({ campaigns: initialCampaigns, refreshData, setActiveTab, setEditingCampaignId, user }) => {
    const { t } = useLanguage();
    const [actionModal, setActionModal] = useState({ type: null, campaignId: null });
    const [selectedCampaignIds, setSelectedCampaignIds] = useState(() => new Set());
    const [sortBy, setSortBy] = useState({ key: null, dir: 'desc' }); // key=null keeps API order
    const [search, setSearch] = useState('');
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [showGroupsModal, setShowGroupsModal] = useState(false);
    const [showGlobalReports, setShowGlobalReports] = useState(false);

    // One-click pause: flips campaigns.state; a disabled campaign stops serving
    // immediately (index.php answers 503). Optimistic — reverts on failure.
    const [campaignStateOverrides, setCampaignStateOverrides] = useState({});

    // Row action dropdown (⋮): one menu replaces the four always-visible
    // buttons that multiplied visual noise across 50–100 rows.
    const [menuAnchor, setMenuAnchor] = useState(null);

    // Pagination for high-volume lists. "All" restores the previous behaviour.
    const [rowsPerPage, setRowsPerPage] = useState(() => {
        const saved = localStorage.getItem('orbitra_table_page_size');
        return saved === 'All' ? 'All' : ([25, 50, 100, 250].includes(Number(saved)) ? Number(saved) : 50);
    });
    const [page, setPage] = useState(0);

    const handleToggleMenu = (event, campaignId) => {
        event.stopPropagation();

        if (menuAnchor?.id === campaignId) {
            setMenuAnchor(null);
            return;
        }

        const rect = event.currentTarget.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom;
        const openUp = spaceBelow < 240;

        setMenuAnchor({
            id: campaignId,
            top: openUp ? rect.top - 8 : rect.bottom + 4,
            right: Math.max(8, window.innerWidth - rect.right),
            openUp
        });
    };

    useEffect(() => {
        if (!menuAnchor) return undefined;

        const close = () => setMenuAnchor(null);
        window.addEventListener('click', close);
        window.addEventListener('scroll', close, true);
        window.addEventListener('resize', close);

        return () => {
            window.removeEventListener('click', close);
            window.removeEventListener('scroll', close, true);
            window.removeEventListener('resize', close);
        };
    }, [menuAnchor]);

    // Group Filtering State — declared before the pagination-reset effect:
    // its deps array evaluates during render and hits the TDZ otherwise.
    const [groups, setGroups] = useState([]);
    const [selectedGroupId, setSelectedGroupId] = useState('');
    const [selectedSourceId, setSelectedSourceId] = useState('');

    // Any narrowing of the list must drop the user back to the first page,
    // or page 3 of yesterday's filter shows an empty slice.
    useEffect(() => {
        setPage(0);
    }, [search, rowsPerPage, selectedGroupId, selectedSourceId]);
    const [togglingCampaignIds, setTogglingCampaignIds] = useState(new Set());

    const campaignEnabled = (camp) => (campaignStateOverrides[camp.id] ?? camp.state ?? 'active') !== 'disabled';

    const handleCopyCampaignLink = async (camp) => {
        const url = `${window.location.origin}/${camp.alias}`;
        // utils/clipboard works on HTTP/IP too; the alert with the raw URL
        // stays as the last resort when even execCommand is unavailable.
        const ok = await copyToClipboard(url);
        if (ok) {
            alert(`${t('common.copied')}: ${url}`);
        } else {
            alert(url);
        }
    };

    const handleDuplicateCampaign = async (camp) => {
        try {
            const res = await axios.post(`${API_URL}?action=copy_campaign`, { id: camp.id });
            if (res.data.status === 'success') {
                fetchCampaigns();
                if (refreshData) refreshData();
            } else {
                alert(res.data.message || t('common.error'));
            }
        } catch (e) {
            alert(t('common.networkError'));
        }
    };

    const handleToggleCampaignState = async (camp, targetOverride) => {
        if (togglingCampaignIds.has(camp.id)) return;
        const target = targetOverride || (campaignEnabled(camp) ? 'PAUSED' : 'ACTIVE');
        setTogglingCampaignIds(prev => new Set(prev).add(camp.id));
        try {
            const res = await axios.post('/api.php?action=ad_entity_toggle_status', {
                entity_type: 'campaign',
                entity_id: String(camp.id),
                target_status: target,
                // Pause/resume follows through to the linked ad-network
                // campaigns (clicks.parameters_json ids) — stopping spend is
                // the whole point of the switch.
                sync_remote_ads: true
            });
            if (res.data.status === 'success') {
                setCampaignStateOverrides(prev => ({ ...prev, [camp.id]: target === 'ACTIVE' ? 'active' : 'disabled' }));
                const failed = (res.data.remote_synced || []).filter(r => !r.success);
                if (failed.length > 0) {
                    alert(`${t('automation.statusUpdateError')}: ${failed[0].message || ''} (${failed.length})`);
                }
            } else {
                alert(res.data.message || t('automation.statusUpdateError'));
            }
        } catch (e) {
            alert(t('automation.statusUpdateError'));
        } finally {
            setTogglingCampaignIds(prev => { const s = new Set(prev); s.delete(camp.id); return s; });
        }
    };

    // Pausing may stop real spend in the ad accounts — when linked ad entities
    // exist, ask first. Resuming goes straight through.
    const [confirmPause, setConfirmPause] = useState(null);
    const handleRequestToggleState = async (camp) => {
        if (togglingCampaignIds.has(camp.id)) return;
        if (!campaignEnabled(camp)) {
            handleToggleCampaignState(camp, 'ACTIVE');
            return;
        }
        try {
            const res = await axios.get(`${API_URL}?action=campaign_remote_links`, { params: { campaign_id: camp.id } });
            const linked = res.data.status === 'success'
                ? (res.data.data || []).flatMap(p => (p.ids || []).map(id => ({ platform: p.platform, id })))
                : [];
            if (linked.length > 0) {
                setConfirmPause({ camp, linked });
                return;
            }
        } catch (e) {
            // No link info — fall through to a plain confirmed-by-absence pause.
        }
        handleToggleCampaignState(camp, 'PAUSED');
    };

    // Date & Timezone Range Picker State
    const todayPreset = getPresetDates('today');
    const [dateFrom, setDateFrom] = useState(todayPreset?.from || formatDate(new Date()));
    const [dateTo, setDateTo] = useState(todayPreset?.to || formatDate(new Date()));
    const [timezone, setTimezone] = useState(() => localStorage.getItem('orbitra_tz') || 'UTC');

    // Active Campaign Data (fetched with date & group parameters)
    const [campaignList, setCampaignList] = useState(initialCampaigns || []);

    // Column Customizer State & Presets
    const [columnsFilterOpen, setColumnsFilterOpen] = useState(false);
    const [chosenColumns, setChosenColumns] = useState(() => {
        try {
            const saved = localStorage.getItem('orbitra_campaign_columns');
            if (saved) return normalizeReportMetricIds(JSON.parse(saved));
        } catch (e) {}
        // No per-page selection yet — fall back to the user's default template
        const fromDefaultTemplate = getDefaultTemplateColumns();
        if (fromDefaultTemplate) return fromDefaultTemplate;
        return [...PRESETS.best];
    });

    // Header Drag-and-Drop state
    const [thDragIdx, setThDragIdx] = useState(null);
    const [thDragOverIdx, setThDragOverIdx] = useState(null);

    // Finance-restricted users never see money columns, whatever the
    // customizer says — the backend already nulls the values.
    const financeVis = useMemo(() => financeVisibility(user), [user]);
    const visibleColumns = useMemo(
        () => chosenColumns.filter(id => !financeHiddenMetric(id, financeVis)),
        [chosenColumns, financeVis]
    );

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
        e.dataTransfer.dropEffect = 'move';
        if (thDragOverIdx !== idx) {
            setThDragOverIdx(idx);
        }
    };

    const handleThDrop = (e, targetIdx) => {
        e.preventDefault();
        if (thDragIdx !== null && thDragIdx !== targetIdx) {
            const sourceColId = visibleColumns[thDragIdx];
            const targetColId = visibleColumns[targetIdx];
            if (sourceColId && targetColId) {
                const copy = [...chosenColumns];
                const from = copy.indexOf(sourceColId);
                const to = copy.indexOf(targetColId);
                if (from !== -1 && to !== -1) {
                    const [item] = copy.splice(from, 1);
                    copy.splice(to, 0, item);
                    setChosenColumns(copy);
                    localStorage.setItem('orbitra_campaign_columns', JSON.stringify(copy));
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

    // Sources present in the current rows (rows ship source_id + source_name).
    const uniqueSources = useMemo(() => {
        const map = new Map();
        campaignList.forEach(c => {
            if (c.source_id && !map.has(String(c.source_id))) {
                map.set(String(c.source_id), c.source_name || `#${c.source_id}`);
            }
        });
        return Array.from(map.entries()).map(([id, name]) => ({ id, name }));
    }, [campaignList]);

    const filteredCampaigns = useMemo(() => {
        const q = String(search || '').trim().toLowerCase();
        if (!q && !selectedSourceId) return campaignList;
        return campaignList.filter(c => {
            if (selectedSourceId && String(c.source_id ?? '') !== selectedSourceId) return false;
            if (!q) return true;
            const n = String(c.name || '').toLowerCase();
            const a = String(c.alias || '').toLowerCase();
            return n.includes(q) || a.includes(q) || String(c.id || '') === q;
        });
    }, [campaignList, search, selectedSourceId]);

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

    // The paged slice the table renders. Totals stay computed over the whole
    // filtered list — paging must not quietly change the numbers in TOTAL.
    const pagedCampaigns = useMemo(() => {
        if (rowsPerPage === 'All') return visibleCampaigns;
        const start = page * rowsPerPage;
        return visibleCampaigns.slice(start, start + rowsPerPage);
    }, [visibleCampaigns, page, rowsPerPage]);

    // Grand Totals Calculation across all visible campaigns
    const grandTotals = useMemo(() => {
        const t0 = {
            clicks: 0,
            unique_clicks: 0,
            prelander_clicks: 0,
            lp_views: 0,
            lp_clicks: 0,
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
            const lpViews = Number(c.lp_views ?? c.prelander_clicks ?? c.clicks) || 0;
            t0.prelander_clicks += lpViews;
            t0.lp_views += lpViews;
            t0.lp_clicks += Number(c.lp_clicks ?? c.offer_clicks) || 0;
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
        const lpClickDenominator = t0.lp_clicks > 0 ? t0.lp_clicks : t0.clicks;
        const lp_ctr = t0.lp_views > 0 ? (t0.lp_clicks / t0.lp_views) * 100 : 0;
        const cr = t0.clicks > 0 ? (t0.conversions / t0.clicks) * 100 : 0;
        const cr_sales = t0.clicks > 0 ? (t0.purchases / t0.clicks) * 100 : 0;
        const cr_holds = t0.clicks > 0 ? (t0.holds / t0.clicks) * 100 : 0;
        const approve_rate = t0.conversions > 0 ? (t0.purchases / t0.conversions) * 100 : 0;
        const nonTrash = t0.purchases + t0.holds + t0.rejected;
        const approve_rate_excl_trash = nonTrash > 0 ? (t0.purchases / nonTrash) * 100 : 0;
        const roi = t0.cost > 0 ? (t0.profit / t0.cost) * 100 : 0;
        const real_roi = t0.cost > 0 ? (t0.real_profit / t0.cost) * 100 : 0;
        const epc = lpClickDenominator > 0 ? t0.revenue / lpClickDenominator : 0;
        const epv = t0.lp_views > 0 ? t0.revenue / t0.lp_views : 0;
        const uepc = t0.unique_clicks > 0 ? t0.revenue / t0.unique_clicks : 0;
        const cpc = lpClickDenominator > 0 ? t0.cost / lpClickDenominator : 0;
        const ucpc = t0.unique_clicks > 0 ? t0.cost / t0.unique_clicks : 0;
        const cpv = t0.lp_views > 0 ? t0.cost / t0.lp_views : 0;
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
            epc_all: epc,
            epv,
            uepc,
            cpc,
            ucpc,
            cpv,
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

    const SortableTh = ({ colKey, label, fullTitle, defaultDir = 'asc', alignRight = false, draggable = false, isDragOver = false, onDragStart, onDragOver, onDrop, onDragEnd }) => {
        const isActive = sortBy.key === colKey;
        return (
            <th
                className={`${alignRight ? 'text-right' : 'text-left'} whitespace-nowrap transition-all`}
                aria-sort={isActive ? (sortBy.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
                title={fullTitle}
                draggable={draggable}
                onDragStart={onDragStart}
                onDragOver={onDragOver}
                onDrop={onDrop}
                onDragEnd={onDragEnd}
                style={{
                    textAlign: alignRight ? 'right' : 'left',
                    cursor: draggable ? 'grab' : 'pointer',
                    userSelect: 'none',
                    boxShadow: isDragOver ? 'inset 2px 0 0 var(--color-primary)' : 'none',
                    backgroundColor: isDragOver ? 'var(--color-bg-soft)' : undefined
                }}
            >
                <button
                    type="button"
                    onClick={() => requestSort(colKey, defaultDir)}
                    className={`inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap ${alignRight ? 'justify-end w-full' : ''}`}
                    style={{
                        color: isActive ? 'var(--color-primary)' : 'var(--color-text-secondary)',
                        textAlign: alignRight ? 'right' : 'left'
                    }}
                >
                    {draggable && <GripVertical className="w-3 h-3 opacity-25 hover:opacity-75 -ml-1 cursor-grab flex-shrink-0" />}
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

            // Direct-to-offer streams have no CTA to measure — the backend
            // sends null, never a made-up 0%/100%.
            case 'lp_ctr':
                return val === null || val === undefined ? '—' : `${num.toFixed(2)}%`;

            case 'roi':
            case 'roi_all':
            case 'roi_confirmed':
            case 'real_roi': {
                if (val === null || val === undefined) return '—';
                // Zero ROI (no spend yet) is neutral, not "positive" — a green
                // +0.00% reads as profit on idle campaigns.
                if (num === 0) {
                    return <span style={{ color: 'var(--color-text-muted)' }}>0.00%</span>;
                }
                const isPos = num > 0;
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
                // $0.00 is neutral gray — zero means "nothing happened yet",
                // not a profitable campaign. The minus goes before the
                // currency sign (-$5.20, not $-5.20).
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
            case 'epv':
            case 'cpc':
            case 'ucpc':
            case 'cpv':
                return `$${num.toFixed(2)}`;

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

                    <div className="relative" style={{ width: 220 }}>
                        <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--color-text-muted)' }} />
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder={t('campaigns.searchPlaceholder', 'Search campaigns...')}
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

                    {/* Traffic Source Filter Dropdown */}
                    <select
                        value={selectedSourceId}
                        onChange={(e) => setSelectedSourceId(e.target.value)}
                        className="form-select text-xs py-1.5 px-3 rounded-xl"
                        style={{ width: '170px' }}
                    >
                        <option value="">{t('campaigns.allSources', 'All traffic sources')}</option>
                        {uniqueSources.map(s => (
                            <option key={s.id} value={s.id}>{s.name}</option>
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

            {/* Main Campaigns Data Table */}
            <div className="tracker-table-container">
                <table className="page-table tracker-table" style={{ fontVariantNumeric: 'tabular-nums' }}>
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
                            <SortableTh colKey="state" label={t('common.status')} defaultDir="asc" />
                            <SortableTh colKey="name" label={t('campaigns.campaign')} defaultDir="asc" />
                            <SortableTh colKey="group_name" label={t('campaigns.group')} defaultDir="asc" />

                            {/* Dynamically configured metric columns */}
                            {visibleColumns.map((colId, colIdx) => {
                                const def = ALL_REPORT_METRICS.find(m => m.id === colId);
                                return (
                                    <SortableTh
                                        key={colId}
                                        colKey={colId}
                                        label={def?.shortLabel || def?.label || colId}
                                        fullTitle={getReportMetricTooltip(def, t)}
                                        defaultDir="desc"
                                        alignRight={true}
                                        draggable={true}
                                        isDragOver={thDragOverIdx === colIdx && thDragIdx !== null && thDragIdx !== colIdx}
                                        onDragStart={() => handleThDragStart(colIdx)}
                                        onDragOver={(e) => handleThDragOver(e, colIdx)}
                                        onDrop={(e) => handleThDrop(e, colIdx)}
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
                                <td colSpan={6 + visibleColumns.length} className="text-center py-12">
                                    <div className="empty-state">
                                        <p className="empty-state-title">{t('campaigns.noCampaignsCreated')}</p>
                                        <p className="empty-state-text">{t('campaigns.createFirstCampaign')}</p>
                                    </div>
                                </td>
                            </tr>
                        ) : (
                            pagedCampaigns.map((camp) => (
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
                                        {/* Dedicated status column keeps the pause switch away
                                            from the campaign name — a stray click while aiming
                                            at the name used to stop live ads. */}
                                        <div className="flex items-center justify-center">
                                            <button
                                                type="button"
                                                disabled={togglingCampaignIds.has(camp.id)}
                                                onClick={() => handleRequestToggleState(camp)}
                                                className="relative inline-flex h-4 w-7 flex-shrink-0 items-center rounded-full transition-colors"
                                                style={{
                                                    background: campaignEnabled(camp) ? 'var(--color-success, #10b981)' : 'var(--color-border)',
                                                    opacity: togglingCampaignIds.has(camp.id) ? 0.5 : 1,
                                                    cursor: 'pointer'
                                                }}
                                                title={campaignEnabled(camp) ? t('automation.clickToPause') : t('automation.clickToResume')}
                                            >
                                                <span className="inline-block h-3 w-3 transform rounded-full bg-white shadow transition-transform" style={{ transform: campaignEnabled(camp) ? 'translateX(12px)' : 'translateX(2px)' }} />
                                            </button>
                                        </div>
                                    </td>
                                    <td>
                                        {/* One line: name, alias — a second line for the
                                            alias halved how many campaigns fit on screen. */}
                                        <div className="flex items-center gap-2" style={{ maxWidth: 320 }}>
                                            <span
                                                className="font-medium text-xs truncate cursor-pointer hover:underline"
                                                style={{ color: 'var(--color-text-primary)' }}
                                                onClick={() => handleEdit(camp.id)}
                                                title={camp.name}
                                            >
                                                {camp.name}
                                            </span>
                                            <span
                                                className="text-[10px] font-mono px-1 rounded border flex-shrink-0"
                                                style={{ color: 'var(--color-text-muted)', borderColor: 'var(--color-border)' }}
                                                title={camp.alias}
                                            >
                                                {camp.alias}
                                            </span>
                                        </div>
                                    </td>
                                    <td style={{ color: 'var(--color-text-secondary)' }}>{camp.group_name || '-'}</td>

                                    {/* Render dynamic metric cells */}
                                    {visibleColumns.map((colId) => (
                                        <td key={colId} style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                                            {formatMetricCell(colId, camp)}
                                        </td>
                                    ))}

                                    <td style={{ textAlign: 'right' }}>
                                        <button
                                            type="button"
                                            onClick={(event) => handleToggleMenu(event, camp.id)}
                                            className="p-1.5 rounded-lg transition-colors hover:bg-black/5 dark:hover:bg-white/5"
                                            style={{ color: menuAnchor?.id === camp.id ? 'var(--color-primary)' : 'var(--color-text-muted)' }}
                                            title={t('table.actions')}
                                        >
                                            <MoreVertical className="w-4 h-4" />
                                        </button>
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
                                <td></td>
                                <td>{t('campaignReports.total', 'Totals')} ({visibleCampaigns.length})</td>
                                <td>-</td>
                                {visibleColumns.map(colId => (
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

            {/* Pagination toolbar: page through 50–100+ campaigns without
                losing the TOTAL row (totals stay over the whole filter). */}
            <PaginationToolbar
                totalRows={visibleCampaigns.length}
                currentPage={page}
                pageSize={rowsPerPage}
                onPageChange={setPage}
                onPageSizeChange={(size) => { setRowsPerPage(size); setPage(0); }}
            />

            {/* Columns Customizer Modal [ ☵ ] */}
            <ReportCustomizerModal
                isOpen={columnsFilterOpen}
                onClose={() => setColumnsFilterOpen(false)}
                selectedColumns={chosenColumns}
                onSaveColumns={handleSaveColumns}
                mode="campaigns"
            />

            {/* Pause Safety Confirmation — stopping a campaign with linked ad
                entities stops real spend in the ad accounts; list them first. */}
            {confirmPause && (
                <div className="modal-overlay" onClick={() => setConfirmPause(null)}>
                    <div className="modal-content max-w-md w-full rounded-2xl p-6" style={{ backgroundColor: 'var(--color-bg-card)', border: '1px solid var(--color-border)' }} onClick={e => e.stopPropagation()}>
                        <div className="flex items-center gap-3 mb-4">
                            <div className="p-2.5 rounded-xl flex-shrink-0" style={{
                                background: 'color-mix(in srgb, var(--color-warning) 12%, transparent)',
                                border: '1px solid color-mix(in srgb, var(--color-warning) 35%, transparent)',
                                color: 'var(--color-warning)'
                            }}>
                                <AlertTriangle className="w-5 h-5" />
                            </div>
                            <div className="min-w-0">
                                <h3 className="modal-title font-bold text-base m-0">
                                    {t('campaigns.pauseRemoteTitle')}
                                </h3>
                                <p className="text-xs m-0 mt-0.5 truncate" style={{ color: 'var(--color-text-muted)' }}>
                                    {confirmPause.camp.name}
                                </p>
                            </div>
                        </div>

                        <p className="text-xs mb-3" style={{ color: 'var(--color-text-secondary)', lineHeight: 1.6 }}>
                            {t('campaigns.pauseRemoteWarning')}
                        </p>

                        <div className="mb-4">
                            <p className="text-xs font-semibold mb-1.5" style={{ color: 'var(--color-text-primary)' }}>
                                {t('campaigns.pauseRemoteLinked')}
                            </p>
                            <div className="flex flex-wrap gap-1.5">
                                {confirmPause.linked.map(l => (
                                    <span
                                        key={`${l.platform}-${l.id}`}
                                        className="text-[10px] font-mono px-1.5 py-0.5 rounded border"
                                        style={{ color: 'var(--color-text-muted)', borderColor: 'var(--color-border)' }}
                                    >
                                        {l.platform}:{l.id}
                                    </span>
                                ))}
                            </div>
                        </div>

                        <div className="flex justify-end gap-2.5 pt-3" style={{ borderTop: '1px solid var(--color-border)' }}>
                            <button type="button" onClick={() => setConfirmPause(null)} className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl">
                                {t('common.cancel')}
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    const camp = confirmPause.camp;
                                    setConfirmPause(null);
                                    handleToggleCampaignState(camp, 'PAUSED');
                                }}
                                className="btn btn-danger text-xs py-1.5 px-4 rounded-xl font-semibold"
                            >
                                {t('campaigns.confirmPauseAll')}
                            </button>
                        </div>
                    </div>
                </div>
            )}

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

            {menuAnchor && typeof document !== 'undefined' && createPortal(
                (() => {
                    const camp = campaignList.find((campaign) => campaign.id === menuAnchor.id);
                    if (!camp) return null;

                    return (
                        <div
                            className="fixed w-48 rounded-2xl shadow-2xl py-1.5 text-xs border animate-in fade-in zoom-in-95 duration-100"
                            style={{
                                backgroundColor: 'var(--color-bg-card)',
                                borderColor: 'var(--color-border)',
                                right: `${menuAnchor.right}px`,
                                top: menuAnchor.openUp ? undefined : `${menuAnchor.top}px`,
                                bottom: menuAnchor.openUp ? `${window.innerHeight - menuAnchor.top}px` : undefined,
                                zIndex: 99999
                            }}
                            onClick={(event) => event.stopPropagation()}
                        >
                            <button onClick={() => { setMenuAnchor(null); handleEdit(camp.id); }} className="w-full text-left px-3.5 py-2 flex items-center gap-2.5 hover:bg-black/5 dark:hover:bg-white/5 transition" style={{ color: 'var(--color-text-primary)' }}>
                                <Edit3 className="w-3.5 h-3.5" /> {t('common.edit')}
                            </button>
                            <button onClick={() => { setMenuAnchor(null); setActionModal({ type: 'update_costs', campaignId: camp.id }); }} className="w-full text-left px-3.5 py-2 flex items-center gap-2.5 hover:bg-black/5 dark:hover:bg-white/5 transition" style={{ color: 'var(--color-text-primary)' }}>
                                <DollarSign className="w-3.5 h-3.5" /> {t('campaigns.updateCosts')}
                            </button>
                            <button onClick={() => { setMenuAnchor(null); handleCopyCampaignLink(camp); }} className="w-full text-left px-3.5 py-2 flex items-center gap-2.5 hover:bg-black/5 dark:hover:bg-white/5 transition" style={{ color: 'var(--color-text-primary)' }}>
                                <Copy className="w-3.5 h-3.5" /> {t('table.copyLink')}
                            </button>
                            <button onClick={() => { setMenuAnchor(null); handleDuplicateCampaign(camp); }} className="w-full text-left px-3.5 py-2 flex items-center gap-2.5 hover:bg-black/5 dark:hover:bg-white/5 transition" style={{ color: 'var(--color-text-primary)' }}>
                                <ChevronsUpDown className="w-3.5 h-3.5 rotate-90" /> {t('table.duplicate')}
                            </button>
                            <button onClick={() => { setMenuAnchor(null); setActionModal({ type: 'clear_stats', campaignId: camp.id }); }} className="w-full text-left px-3.5 py-2 flex items-center gap-2.5 hover:bg-black/5 dark:hover:bg-white/5 transition" style={{ color: 'var(--color-warning, #f59e0b)' }}>
                                <XCircle className="w-3.5 h-3.5" /> {t('common.clearStats')}
                            </button>
                            <div className="my-1 border-t" style={{ borderColor: 'var(--color-border)' }}></div>
                            <button onClick={() => { setMenuAnchor(null); handleDelete(camp.id); }} className="w-full text-left px-3.5 py-2 flex items-center gap-2.5 text-red-500 hover:bg-red-500/10 transition">
                                <Trash2 className="w-3.5 h-3.5" /> {t('common.delete')}
                            </button>
                        </div>
                    );
                })(),
                document.body
            )}
        </div>
    );
};

export default Campaigns;
