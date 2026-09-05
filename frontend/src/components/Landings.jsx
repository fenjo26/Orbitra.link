import React, { useState, useMemo, useEffect, useCallback, useRef } from 'react';
import { Plus, Trash2, Edit3, Settings2, Filter, RefreshCw, X, SlidersHorizontal, Smartphone, Copy, CopyPlus, Check } from 'lucide-react';
import InfoBanner from './InfoBanner';
import LandingEditor from './LandingEditor';
import PwaEditor from './PwaEditor';
import GroupsModal from './GroupsModal';
import ReportCustomizerModal, { ALL_REPORT_METRICS, PRESETS, getReportMetricTooltip, normalizeReportMetricIds } from './ReportCustomizerModal';
import { useIsDesktop, useResizableTableColumns, ColumnResizeHandle, ColRow } from './common/ColumnResize';
import { colWidth } from './common/tableColumns';
import { SortableTh, sortRows, nextSortState } from './common/SortableTh';
import PaginationToolbar from './common/PaginationToolbar';
import MobileCards from './common/MobileCards';
import { useCardMetrics } from './common/CardMetrics';
import DateRangePicker, { formatDate, getPresetDates } from './DateRangePicker';
import { useTimezone } from '../utils/useTimezone';
import axios from 'axios';
import { canWriteResource } from '../utils/permissions';
import { useLanguage } from '../contexts/LanguageContext';
import { entityDeleteErrorText } from '../utils/entityInUseError';
import { copyToClipboard } from '../utils/clipboard';
import { pwaLandingUrl } from '../utils/pwaUrl';
import CampaignUrlModal from './CampaignUrlModal';

const API_URL = '/api.php';

// Fixed entity columns for landings — these are always present and not part of
// the metric customization. Metrics come from ALL_REPORT_METRICS.
const FIXED_LANDING_COLUMNS = [
    // 'check', not 'checkbox': the same id the resize hook looks up, the
    // colgroup is keyed by and ColRow stamps as data-col. A mismatch there
    // measures nothing and reports no error.
    { id: 'check', label: '', fixed: true },
    { id: 'id', label: 'ID', fixed: true },
    { id: 'state', label: 'Status', fixed: true },
    { id: 'name', label: 'Name', fixed: true },
    { id: 'group_name', label: 'Group', fixed: true },
    { id: 'type', label: 'Type', fixed: true },
    { id: 'url', label: 'URL', fixed: true },
    { id: 'last_event', label: 'Last Event', fixed: true },
];

// What a card shows before the user says otherwise. Four numbers is what fits
// above the fold on a phone; the picker goes up to eight.
const CARD_METRIC_DEFAULTS = ['clicks', 'conversions', 'cost', 'roi'];
// Module scope so the identity is stable: useCardMetrics keys its callbacks off
// this set, and a fresh Set per render would rebuild them on every keystroke.
const CARD_METRIC_ALLOWED = new Set(ALL_REPORT_METRICS.map(m => m.id));
const CARD_METRIC_OPTIONS = ALL_REPORT_METRICS.map(m => ({ id: m.id, label: m.shortLabel || m.label || m.id }));

const LANDING_COLUMNS_KEY = 'orbitra_landing_columns';

// The saved list holds *metric* ids only. An entity field that ends up in
// there (`name`, `url`, `last_event`…) misses ALL_REPORT_METRICS.find(), and
// the dynamic header falls back to `|| colId`, printing the raw DB column
// name in a second column beside the properly labelled fixed one. Landings'
// fixed set is wider than Offers' — more ids can leak — so the same guard
// applies. Drop anything that is not a real metric or is already a fixed
// column, and write the repaired list back once.
const FIXED_LANDING_COLUMN_IDS = new Set(FIXED_LANDING_COLUMNS.map(c => c.id));

const sanitizeLandingMetricIds = (ids) => normalizeReportMetricIds(ids)
    .filter(id => !FIXED_LANDING_COLUMN_IDS.has(id) && ALL_REPORT_METRICS.some(m => m.id === id));

const loadLandingColumns = () => {
    try {
        const saved = localStorage.getItem(LANDING_COLUMNS_KEY);
        if (saved) {
            const parsed = JSON.parse(saved);
            const cleaned = sanitizeLandingMetricIds(parsed);
            if (cleaned.length) {
                if (JSON.stringify(cleaned) !== JSON.stringify(parsed)) {
                    localStorage.setItem(LANDING_COLUMNS_KEY, JSON.stringify(cleaned));
                }
                return cleaned;
            }
        }
    } catch { /* unreadable saved metrics — preset below */ }
    // Fallback to 'lander_to_offer' preset for landings
    return sanitizeLandingMetricIds(PRESETS.lander_to_offer);
};

const Landings = ({ landings, refreshData, user }) => {
    const { t } = useLanguage();
    const [landingList, setLandingList] = useState(() => landings || []);
    const [isEditorOpen, setIsEditorOpen] = useState(false);
    const [pwaEditorOpen, setPwaEditorOpen] = useState(false);
    const [copiedLandingId, setCopiedLandingId] = useState(null);
    const [urlModal, setUrlModal] = useState(null); // { name, url } — copy fallback
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

    // Resizable columns — desktop table only; below lg the list renders as
    // Card metrics are the user's own, independent of the desktop columns:
    // eight numbers on a phone, twenty in the table, one list each.
    const cardMetrics = useCardMetrics('landings', CARD_METRIC_DEFAULTS, CARD_METRIC_ALLOWED);
    // The card renders fields for the union of "chosen for the card" and
    // "visible in the table", so a metric picked for the card still has a field
    // to render when it is hidden on desktop.
    const cardFieldIds = useMemo(() => {
        const seen = new Set();
        const out = [];
        for (const id of [...cardMetrics.ids, ...chosenColumns]) {
            if (seen.has(id)) continue;
            seen.add(id);
            out.push(id);
        }
        return out;
    }, [cardMetrics.ids, chosenColumns]);

    // MobileCards and skips resizing entirely. Ids mirror FIXED_LANDING_COLUMNS.
    const isDesktop = useIsDesktop();
    // The label matters: colWidth() measures it, and "Actions" in English is
    // "Aktionen" in German. These must stay the same strings the header renders.
    const fixedColLabels = useMemo(() => ({
        id: 'ID',
        state: t('components.status'),
        name: t('editor.name'),
        group_name: t('components.group'),
        type: t('components.type'),
        url: 'URL',
        last_event: t('landingColumns.lastEvent'),
        actions: t('common.actions'),
    }), [t]);
    // ORDER MUST MIRROR RENDER ORDER: this list feeds the <colgroup> AND the
    // data-col stamping in ColRow. Widths come from common/tableColumns.js, so
    // a metric is the same width here as on Campaigns and Offers.
    const columnDefs = useMemo(() => ([
        ...FIXED_LANDING_COLUMNS.map(c => c.id),
        ...chosenColumns,
        'actions',
    ].map(id => {
        const def = ALL_REPORT_METRICS.find(m => m.id === id);
        const label = fixedColLabels[id] || def?.shortLabel || def?.label || id;
        return { id, width: colWidth(id, label) };
    })), [chosenColumns, fixedColLabels]);
    const colResize = useResizableTableColumns({ tableId: 'landings', columns: columnDefs, enabled: isDesktop });

    // Metric column reordering by dragging the header grip. Landings never had
    // this; Campaigns and Offers always did, and the shared SortableTh already
    // carried every handler it needs.
    const [thDragIdx, setThDragIdx] = useState(null);
    const [thDragOverIdx, setThDragOverIdx] = useState(null);

    const handleThDragStart = (e, idx) => {
        // Firefox refuses to start a native drag until the payload is set.
        e.dataTransfer.setData('text/plain', String(idx));
        e.dataTransfer.effectAllowed = 'move';
        setThDragIdx(idx);
    };

    const handleThDragOver = (e, idx) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (thDragOverIdx !== idx) setThDragOverIdx(idx);
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
                    try { localStorage.setItem(LANDING_COLUMNS_KEY, JSON.stringify(copy)); } catch { /* storage unavailable */ }
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

    const [dateFrom, setDateFrom] = useState(() => getPresetDates('today')?.from || formatDate(new Date()));
    const [dateTo, setDateTo] = useState(() => getPresetDates('today')?.to || formatDate(new Date()));
    const [timezone, setTimezone] = useTimezone();
    // key=null keeps the API's own order, same contract as Campaigns and Offers.
    const [sortBy, setSortBy] = useState({ key: null, dir: 'desc' });
    const landingRequestId = useRef(0);

    const requestSort = (key, defaultDir = 'asc') => {
        setSortBy(prev => nextSortState(prev, key, defaultDir));
    };

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

    // A PWA landing (config_json.pwa) is edited by its own constructor — the
    // plain landing editor would show an empty local landing.
    const isPwaLanding = (landing) => {
        try {
            return !!(JSON.parse(landing.config_json || '{}')?.pwa);
        } catch {
            return false;
        }
    };
    const openEditorFor = (landing) => {
        if (isPwaLanding(landing)) {
            setEditingLandingId(landing.id);
            setPwaEditorOpen(true);
        } else {
            handleEdit(landing.id);
        }
    };

    // The public address of a PWA landing: the bound domain's root when one is
    // bound (`pwa_domain` rides along with the list), the panel origin's
    // /lander/<slug>/ otherwise. Same helper the PWA editor footer uses, so the
    // link copied here and the one shown there cannot drift.
    const pwaUrlFor = (landing) => pwaLandingUrl(landing.slug, landing.id, landing.pwa_domain || null);

    const handleCopyPwaLink = async (landing) => {
        const url = pwaUrlFor(landing);
        if (!url) return;
        const ok = await copyToClipboard(url);
        if (ok) {
            setCopiedLandingId(landing.id);
            setTimeout(() => setCopiedLandingId(null), 2000);
        } else {
            // Every clipboard transport blocked (plain-HTTP panel): fall back
            // to the selectable-URL modal instead of a silent no-op.
            setUrlModal({ name: landing.name, url });
        }
    };

    // Icon-only copy button, shared by the desktop Actions cell and the mobile
    // card header so the two never drift apart. PWA landings only: a redirect
    // landing's address is its own url column, and a plain local landing is
    // reached through its campaign, not directly.
    const renderCopyLinkButton = (landing) => {
        if (!isPwaLanding(landing)) return null;
        const done = copiedLandingId === landing.id;
        return (
            <button
                onClick={() => handleCopyPwaLink(landing)}
                className="action-btn"
                title={done ? t('common.copied') : t('pwa.copyLink')}
                // Inline, not a class: .action-btn (unlayered index.css) wins
                // over layered Tailwind color utilities, so text-green-500
                // would silently no-op here — same trap as icon inputs.
                style={done ? { color: 'var(--color-success)' } : undefined}
            >
                {done ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
            </button>
        );
    };

    // Duplicate a landing the way Campaigns duplicates theirs: the backend
    // creates a Copy #N row (a PWA copy regenerates its statics under a fresh
    // slug), then the list refetches so the new row shows up at once.
    const handleDuplicateLanding = async (landing) => {
        try {
            const res = await axios.post(`${API_URL}?action=copy_landing`, { id: landing.id });
            if (res.data?.status === 'success') {
                await Promise.all([
                    fetchLandings(),
                    Promise.resolve(refreshData?.()),
                ]);
            } else {
                alert(res.data?.message || t('common.error'));
            }
        } catch (err) {
            alert(err?.message || t('common.networkError'));
        }
    };

    // Icon-only duplicate button, shared by the desktop Actions cell and the
    // mobile card so the two never drift apart.
    const renderDuplicateButton = (landing) => (
        <button
            onClick={() => handleDuplicateLanding(landing)}
            className="action-btn"
            title={t('table.duplicate')}
        >
            <CopyPlus className="w-4 h-4" />
        </button>
    );

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

    const visibleLandings = useMemo(() => sortRows(filteredLandings, sortBy), [filteredLandings, sortBy]);

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

    const handlePwaEditorClose = (wasSaved) => {
        setPwaEditorOpen(false);
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
            case 'lp_measured':
            case 'lp_clicks':
            case 'real_lp_clicks':
            case 'real_offer_clicks':
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
            case 'real_lp_ctr':
            case 'approve_rate':
            case 'cr':
            case 'cr_sales':
            case 'cr_leads':
            case 'cr_holds':
            case 'cr_registrations':
            case 'cr_deposits':
                return val === null || val === undefined ? '—' : `${num.toFixed(2)}%`;

            // Landing-timer metrics: null (dash) when nothing measured —
            // never a fabricated 0 that would read as "everyone bounced".
            case 'lp_bounce_rate':
            case 'lp_scroll_depth':
                return val === null || val === undefined ? '—' : `${num.toFixed(1)}%`;

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
            lp_clicks: 0, lp_views: 0, real_lp_clicks: 0, real_offer_clicks: 0,
            conversions: 0, leads: 0, sales: 0,
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
            t0.real_lp_clicks += Number(l.real_lp_clicks) || 0;
            t0.real_offer_clicks += Number(l.real_offer_clicks) || 0;
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
            case 'lp_measured':
            case 'lp_clicks':
            case 'real_lp_clicks':
            case 'real_offer_clicks':
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
                // Match the row formatter: a totals row reading 0 in success green
                // reads as a positive signal when scanning the table.
                return num > 0 ? <span className="font-semibold" style={{ color: 'var(--color-success)' }}>{num.toLocaleString()}</span> : '0';
            case 'lp_ctr':
            case 'real_lp_ctr':
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
            case 'check':
                return (
                    <td key={colId} className="col-check">
                        <input
                            type="checkbox"
                            checked={selectedLandingIds.has(landing.id)}
                            onChange={(e) => toggleSelected(landing.id, e.target.checked)}
                        />
                    </td>
                );
            case 'id':
                return <td key={colId} className="font-medium cell-text">{landing.id}</td>;
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
                                onClick={() => openEditorFor(landing)}
                            >
                                {landing.name}
                            </span>
                            {landing.type !== 'local' && landing.type !== 'action' && (
                                <span style={{ color: 'var(--color-text-muted)', fontSize: '12px' }} className="truncate max-w-[200px]" title={landing.url}>
                                    {landing.url}
                                </span>
                            )}
                            {/* A PWA is a local landing with an empty url column, so the
                                row showed no address at all — the one thing an operator
                                comes to this list for. */}
                            {isPwaLanding(landing) && (
                                <span style={{ color: 'var(--color-text-muted)', fontSize: '12px' }} className="truncate max-w-[200px]" title={pwaUrlFor(landing)}>
                                    {pwaUrlFor(landing)}
                                </span>
                            )}
                        </div>
                    </td>
                );
            case 'group_name':
                return <td key={colId} className="cell-text" style={{ color: 'var(--color-text-secondary)' }}>{landing.group_name || '-'}</td>;
            case 'type':
                return (
                    <td key={colId}>
                        {isPwaLanding(landing) ? (
                            <span className="px-2 py-1 rounded text-xs font-semibold inline-flex items-center gap-1" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                                <Smartphone className="w-3 h-3" />
                                PWA
                            </span>
                        ) : (
                            <span className="px-2 py-1 rounded text-xs font-semibold" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                                {landing.type}
                            </span>
                        )}
                    </td>
                );
            case 'url': {
                const cellUrl = isPwaLanding(landing) ? pwaUrlFor(landing) : landing.url;
                return (
                    <td key={colId} style={{ color: 'var(--color-text-muted)', fontSize: '12px' }} className="truncate max-w-[200px] cell-text" title={cellUrl}>
                        {cellUrl}
                    </td>
                );
            }
            case 'last_event':
                return <td key={colId} className="cell-text" style={{ color: 'var(--color-text-secondary)' }}>{formatLastEvent(landing.last_event)}</td>;
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
                    {canWriteResource(user, 'landings') && (
                        <button onClick={handleCreate} className="btn btn-primary">
                            <Plus className="w-4 h-4" />
                            {t('common.create')}
                        </button>
                    )}
                    {canWriteResource(user, 'landings') && (
                        <button
                            onClick={() => { setEditingLandingId(null); setPwaEditorOpen(true); }}
                            className="btn btn-secondary"
                            title={t('pwa.title')}
                        >
                            <Smartphone className="w-4 h-4" />
                            PWA
                        </button>
                    )}
                    <button onClick={() => setShowGroupsModal(true)} className="btn btn-secondary">
                        {t('campaigns.groups')}
                    </button>
                    {selectedLandingIds.size > 0 && canWriteResource(user, 'landings') && (
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
                        className="form-select text-xs font-semibold py-2 px-3.5 rounded-xl transition-all tb-release"
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
                        <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} className="form-select tb-release" style={{ width: 'auto', minWidth: '140px' }}>
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
                <table className="page-table tracker-table" style={{ ...colResize.tableStyle }}>
                    {colResize.colgroup}
                    <thead>
                        {/* ColRow stamps data-col + the alignment class on every
                            cell from the same list that feeds the <colgroup>. */}
                        <ColRow columns={columnDefs}>
                            <th className="col-check">
                                <input
                                    type="checkbox"
                                    checked={allSelected}
                                    ref={(el) => {
                                        if (el) el.indeterminate = !allSelected && someSelected;
                                    }}
                                    onChange={(e) => toggleSelectAll(e.target.checked)}
                                />
                            </th>
                            <SortableTh sortBy={sortBy} requestSort={requestSort} colKey="id" label="ID" defaultDir="desc" resize={colResize} />
                            <SortableTh sortBy={sortBy} requestSort={requestSort} colKey="state" label={t('components.status')} resize={colResize} />
                            <SortableTh sortBy={sortBy} requestSort={requestSort} colKey="name" label={t('editor.name')} resize={colResize} />
                            <SortableTh sortBy={sortBy} requestSort={requestSort} colKey="group_name" label={t('components.group')} resize={colResize} />
                            <SortableTh sortBy={sortBy} requestSort={requestSort} colKey="type" label={t('components.type')} resize={colResize} />
                            <SortableTh sortBy={sortBy} requestSort={requestSort} colKey="url" label="URL" resize={colResize} />
                            <SortableTh sortBy={sortBy} requestSort={requestSort} colKey="last_event" label={t('landingColumns.lastEvent')} defaultDir="desc" resize={colResize} />

                            {/* Dynamic metric columns */}
                            {chosenColumns.map((colId, colIdx) => {
                                const def = ALL_REPORT_METRICS.find(m => m.id === colId);
                                return (
                                    <SortableTh
                                        key={colId}
                                        sortBy={sortBy}
                                        requestSort={requestSort}
                                        colKey={colId}
                                        label={def?.shortLabel || def?.label || colId}
                                        fullTitle={getReportMetricTooltip(def, t)}
                                        defaultDir="desc"
                                        draggable
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
                        </ColRow>
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
                                <ColRow key={landing.id} columns={columnDefs}>
                                    {/* The checkbox cell renders here too — renderEntityCell
                                        has the 'check' case, and the body must keep the
                                        header's column count or every metric shifts left. */}
                                    {FIXED_LANDING_COLUMNS.map(col =>
                                        renderEntityCell(landing, col.id)
                                    )}
                                    {/* Metric cells */}
                                    {chosenColumns.map((colId) => (
                                        <td key={colId} className="cell-text">
                                            {formatMetricCell(colId, landing)}
                                        </td>
                                    ))}
                                    <td>
                                        <div className="action-buttons">
                                            {/* openEditorFor, not handleEdit: the pencil on a PWA
                                                row used to open the plain landing editor, which
                                                shows an empty local landing — the constructor is
                                                the only editor that can read its config_json. */}
                                            <button onClick={() => openEditorFor(landing)} className="action-btn text-blue" title={t('common.edit') || t('components.edit')}>
                                                <Edit3 className="w-4 h-4" />
                                            </button>
                                            {renderCopyLinkButton(landing)}
                                            {renderDuplicateButton(landing)}
                                            <button onClick={() => handleDelete(landing.id)} className="action-btn text-red" title={t('common.delete')}>
                                                <Trash2 className="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </ColRow>
                            ))
                        )}
                    </tbody>
                    {/* Totals Footer */}
                    {visibleLandings.length > 0 && (
                        <tfoot style={{ background: 'var(--color-bg-soft)' }}>
                            <tr className="font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                {/* This row spans the entity columns, so its cell
                                    count deliberately differs from the column list
                                    and ColRow cannot stamp it - the alignment
                                    classes are applied by hand instead, and must
                                    keep matching common/tableColumns.js. */}
                                <td className="col-check"></td>
                                <td className="align-left" colSpan={7}>Σ Total ({visibleLandings.length})</td>
                                {chosenColumns.map((colId) => (
                                    <td key={colId} className="cell-text align-right">
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
                                className="font-semibold text-sm cursor-pointer flex-1 min-w-0 line-clamp-2 break-words"
                                style={{ color: 'var(--color-primary)' }}
                                onClick={() => openEditorFor(landing)}
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
                            <button onClick={() => openEditorFor(landing)} className="action-btn text-blue" title={t('common.edit') || t('components.edit')}>
                                <Edit3 className="w-4 h-4" />
                            </button>
                            {renderCopyLinkButton(landing)}
                            {renderDuplicateButton(landing)}
                            <button onClick={() => handleDelete(landing.id)} className="action-btn text-red" title={t('common.delete')}>
                                <Trash2 className="w-4 h-4" />
                            </button>
                        </>
                    )}
                    fields={[
                        ...cardFieldIds.map((colId) => {
                            const def = ALL_REPORT_METRICS.find(m => m.id === colId);
                            return {
                                id: colId,
                                label: def?.shortLabel || def?.label || colId,
                                render: (row) => formatMetricCell(colId, row),
                            };
                        }),
                        { id: 'group_name', label: entityLabel('group_name'), render: (l) => l.group_name || '-' },
                        { id: 'type', label: entityLabel('type'), render: (l) => l.type },
                        { id: 'url', label: 'URL', render: (l) => {
                            const u = isPwaLanding(l) ? pwaUrlFor(l) : ((l.type !== 'local' && l.type !== 'action') ? l.url : '');
                            return u ? <span className="break-all whitespace-normal">{u}</span> : '-';
                        } },
                        { id: 'last_event', label: entityLabel('last_event'), render: (l) => formatLastEvent(l.last_event) },
                    ]}
                    primaryIds={cardMetrics.ids}
                    metricsPicker={{
                        options: CARD_METRIC_OPTIONS,
                        onChange: cardMetrics.setIds,
                        onReset: cardMetrics.reset,
                    }}
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

            {pwaEditorOpen && (
                <PwaEditor
                    landingId={editingLandingId}
                    onClose={handlePwaEditorClose}
                />
            )}

            {/* Copy fallback — only when every clipboard transport failed. */}
            {urlModal && (
                <CampaignUrlModal
                    name={urlModal.name}
                    url={urlModal.url}
                    onClose={() => setUrlModal(null)}
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
                onResetColumnWidths={colResize.api.resetAll}
            />
        </div>
    );
};

export default Landings;
