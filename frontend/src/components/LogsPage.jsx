import React, { useState, useEffect, useRef } from 'react';
import { Activity, ArrowRightLeft, ShieldAlert, TerminalSquare, ServerCrash, FileStack, Filter, ChevronDown, Download, Columns3, Plus, X, Video } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import MobileCards from './common/MobileCards';
import ClickDetailsModal from './ClickDetailsModal';
import SessionReplays from './replay/SessionReplays';
import { useIsDesktop, useResizableTableColumns } from './common/ColumnResize';
import { SortableTh } from './common/SortableTh';
import useColumnReorder from './common/useColumnReorder';
import { OsIcon, BrowserIcon } from './PlatformIcons';
import { describeOs, describeBrowser } from './uaLabels';
import ClickDirection from './ClickDirection';
import { useTimezone } from '../utils/useTimezone';

const API_URL = '/api.php';

// Keitaro-style column customization for the traffic log. Base ids drive the
// resize handles and localStorage widths; `field` is the CSV export field the
// id maps to. Param columns are stored as `p:<name>` tokens and read from the
// row's p_<name> keys, added server-side by param_cols.
const LOGS_COLUMNS_KEY = 'orbitra_logs_traffic_columns';
// `min` = fit floor for this column: the width below which its atomic content
// (a full timestamp, an IP, a click id) starts clipping. Columns without one
// use FIT_FLOOR.
const BASE_COLUMN_DEFS = [
    { id: 'time', width: 170, min: 154, field: 'created_at', labelKey: 'logs.colTime' },
    { id: 'click_id', width: 120, min: 96, field: 'click_id', labelKey: 'logs.colClickId' },
    { id: 'subid', width: 100, field: 'subid', labelKey: 'logs.colSubid' },
    { id: 'campaign', width: 180, field: 'campaign_name', labelKey: 'logs.colCampaign' },
    { id: 'domain', width: 160, field: 'domain', labelKey: 'logs.colDomain', defaultHidden: true },
    { id: 'stream', width: 150, field: 'stream_name', labelKey: 'logs.colStream' },
    // Cloak-only: hidden by default, shown automatically while the page has
    // cloak rows (see effectiveColumns) — otherwise they read as "always -".
    { id: 'route', width: 100, field: 'is_safe_page', labelKey: 'logs.colRoute', defaultHidden: true, cloakOnly: true },
    { id: 'reason', width: 180, field: 'cloak_reasons', labelKey: 'logs.colReason', defaultHidden: true, cloakOnly: true },
    { id: 'destination', width: 160, field: 'landing_name', labelKey: 'logs.colDestination' },
    // The URL the visitor was actually sent to (offer + network sub1), or the
    // stream action for non-offer clicks. Not exported: it is rebuilt on read.
    { id: 'direction', width: 260, field: 'redirect_url', labelKey: 'logs.colDirection' },
    { id: 'lp_time', width: 110, field: 'lp_seconds', labelKey: 'logs.colLpTime' },
    { id: 'ip', width: 110, min: 80, field: 'ip', labelKey: 'logs.colIp' },
    { id: 'geo', width: 160, field: 'country_code', labelKey: 'logs.colGeo' },
    { id: 'device', width: 100, field: 'device_type', labelKey: 'logs.colDevice' },
    // Logo + version, parsed per row server-side (row.ua); the CSV exports the
    // stored coarse family. The raw User-Agent is opt-in: it is wide.
    { id: 'os', width: 130, min: 112, field: 'os', labelKey: 'logs.colOs' },
    { id: 'browser', width: 170, min: 150, field: 'browser', labelKey: 'logs.colBrowser' },
    { id: 'user_agent', width: 260, field: 'user_agent', labelKey: 'logs.colUa', defaultHidden: true },
    { id: 'isp', width: 120, field: 'isp', labelKey: 'logs.colIsp' },
    { id: 'asn', width: 110, field: 'asn', labelKey: 'logs.colAsn' }
];
// Registry order (every base column) vs. what a fresh visitor sees.
const ALL_COLUMN_IDS = BASE_COLUMN_DEFS.map(c => c.id);
const DEFAULT_COLUMN_IDS = BASE_COLUMN_DEFS.filter(c => !c.defaultHidden).map(c => c.id);
const PARAM_COL_WIDTH = 150;
// Log headers reorder and resize but do not sort (rows are newest-first, keyset-paged).
const NO_SORT = { key: null, dir: 'asc' };
const noop = () => {};
// Ad-network IDs are 16–19 digit numbers: ~136px at the log's font. Comparing
// them against the platform's report is the column's whole purpose, so they
// must render in full — below this a clipped ID cannot be read without hovering.
const PARAM_COL_MIN = 136;
const FIT_FLOOR = 72;
// Offered even before any click carried them — the IDs cost matching lives on.
const COMMON_PARAM_SUGGESTIONS = [
    'campaign_id', 'campaignid', 'ad_id', 'adset_id', 'adgroup_id', 'adgroupid',
    'creative', 'creative_id', 'ttclid', 'fbclid', 'gclid', 'utm_source', 'utm_campaign'
];
const PARAM_NAME_RE = /^[A-Za-z0-9_]{1,64}$/;

const parseStoredColumns = () => {
    if (typeof window === 'undefined') return { ids: DEFAULT_COLUMN_IDS, customized: false };
    let stored = null;
    try { stored = JSON.parse(localStorage.getItem(LOGS_COLUMNS_KEY) || 'null'); } catch { /* storage unavailable */ }
    if (Array.isArray(stored) && stored.length) {
        // Stored order is the user's (headers drag to reorder); unknown ids drop out.
        const ids = stored.filter(id => typeof id === 'string'
            && (ALL_COLUMN_IDS.includes(id) || (id.startsWith('p:') && PARAM_NAME_RE.test(id.slice(2)))));
        if (ids.length) return { ids: [...new Set(ids)], customized: true };
    }
    return { ids: DEFAULT_COLUMN_IDS, customized: false };
};

const LogsPage = () => {
    const { t } = useLanguage();
    const [timezone] = useTimezone();

    // W3: Initialize from URL params (for deep-linking from diagnostics panel)
    const [activeTab, setActiveTab] = useState(() => {
        if (typeof window === 'undefined') return 'traffic';
        const params = new URLSearchParams(window.location.search);
        // "Open recordings" from the landing editor leaves a one-shot hint.
        let hinted = null;
        try {
            hinted = sessionStorage.getItem('orbitra_logs_tab');
            if (hinted) sessionStorage.removeItem('orbitra_logs_tab');
        } catch { /* private mode */ }
        return params.get('tab') || hinted || 'traffic';
    });
    useEffect(() => {
        const onTab = (e) => { if (e?.detail?.tab) setActiveTab(e.detail.tab); };
        window.addEventListener('orbitra:logs-tab', onTab);
        return () => window.removeEventListener('orbitra:logs-tab', onTab);
    }, []);
    const isReplays = activeTab === 'replays';

    const [logs, setLogs] = useState([]);
    // Same click card Recent Clicks opens; the click id here was plain text
    // before, so a click found in the full logs could not be inspected.
    const [selectedClickId, setSelectedClickId] = useState(null);
    const renderClickIdLink = (clickId, className = 'font-mono text-xs') => (
        clickId ? (
            <button
                type="button"
                onClick={() => setSelectedClickId(clickId)}
                className={`${className} text-[var(--color-primary)] hover:underline text-left`}
                title={t('logs.openClickDetails')}
            >
                {clickId}
            </button>
        ) : '-'
    );
    const [loading, setLoading] = useState(true);
    // Keyset paging: the server hands back the cursor of the last row; "Load
    // more" asks for rows older than it, so new clicks arriving meanwhile can
    // never shift a page and duplicate rows the way an OFFSET would.
    const [hasMore, setHasMore] = useState(false);
    const [nextCursor, setNextCursor] = useState(null);
    const [loadingMore, setLoadingMore] = useState(false);

    // W2: Cloak observability filters
    const [filters, setFilters] = useState(() => {
        if (typeof window === 'undefined') return { campaign_id: '', stream_id: '', route: 'all', reason: '', date_from: '', date_to: '' };
        const params = new URLSearchParams(window.location.search);
        return {
            campaign_id: params.get('campaign_id') || '',
            stream_id: params.get('stream_id') || '',
            route: params.get('route') || 'all',
            reason: params.get('reason') || '',
            date_from: params.get('date_from') || '',
            date_to: params.get('date_to') || ''
        };
    });

    const [showFilters, setShowFilters] = useState(() => {
        if (typeof window === 'undefined') return false;
        const params = new URLSearchParams(window.location.search);
        // Auto-expand filters if any are set
        return !!(params.get('campaign_id') || params.get('stream_id') || (params.get('route') && params.get('route') !== 'all') || params.get('reason') || params.get('date_from') || params.get('date_to'));
    });

    // Visible traffic columns: base ids in registry order + `p:<name>` params.
    // `customized` gates the export projection — a user who never opened the
    // picker keeps the full-width CSV they had before.
    const initialColumns = parseStoredColumns();
    const [visibleColumns, setVisibleColumns] = useState(initialColumns.ids);
    const [columnsCustomized, setColumnsCustomized] = useState(initialColumns.customized);
    const [columnsOpen, setColumnsOpen] = useState(false);
    // Columns in the user's order — base and parameter columns mix freely.
    // { id, labelKey/width/min/field } for base, { id: 'p:x', param: 'x' } for params.
    // A visitor who never customised the columns gets Route/Reason back as soon
    // as the loaded page contains cloak clicks, placed after Stream.
    const hasCloakRows = activeTab === 'traffic' && logs.some(l => l && (l.cloak_verdict || Number(l.is_safe_page) === 1));
    const effectiveColumns = (() => {
        if (columnsCustomized || !hasCloakRows) return visibleColumns;
        const cloakIds = BASE_COLUMN_DEFS.filter(c => c.cloakOnly).map(c => c.id).filter(id => !visibleColumns.includes(id));
        if (!cloakIds.length) return visibleColumns;
        const at = visibleColumns.indexOf('stream');
        const next = [...visibleColumns];
        next.splice(at === -1 ? next.length : at + 1, 0, ...cloakIds);
        return next;
    })();
    const orderedColumns = effectiveColumns
        .map(id => (id.startsWith('p:') ? { id, param: id.slice(2) } : BASE_COLUMN_DEFS.find(c => c.id === id)))
        .filter(Boolean);
    const visibleBase = orderedColumns.filter(c => !c.param);
    const visibleParams = orderedColumns.filter(c => c.param).map(c => c.param);

    const saveColumns = (next) => {
        if (!next.length) return; // never hide every column
        setVisibleColumns(next);
        setColumnsCustomized(true);
        try { localStorage.setItem(LOGS_COLUMNS_KEY, JSON.stringify(next)); } catch { /* storage unavailable */ }
    };
    // Turning a column on appends it at the end; off removes it in place.
    const toggleColumn = (id) => {
        saveColumns(effectiveColumns.includes(id) ? effectiveColumns.filter(x => x !== id) : [...effectiveColumns, id]);
    };
    const columnReorder = useColumnReorder(orderedColumns.map(c => c.id), saveColumns);

    // Click-parameter suggestions (what recent clicks actually carry), loaded
    // lazily: the picker and the parameter-filter select share one fetch.
    const [availableParams, setAvailableParams] = useState(null);
    const ensureParamList = () => {
        if (availableParams) return;
        fetch(`${API_URL}?action=logs_param_keys`)
            .then(res => res.json())
            .then(data => { if (data.status === 'success') setAvailableParams(data.data || []); })
            .catch(() => { /* picker shows suggestions only */ });
    };
    const paramOptions = (() => {
        const fetched = Array.isArray(availableParams) ? availableParams.map(p => p.key) : [];
        const counts = {};
        if (Array.isArray(availableParams)) availableParams.forEach(p => { counts[p.key] = p.clicks; });
        const merged = [...new Set([...COMMON_PARAM_SUGGESTIONS, ...fetched])];
        return merged.map(key => ({ key, clicks: counts[key] }));
    })();

    // Keitaro-style parameter filters: [{param, op, value}] applied server-side
    // (eq/neq/in/nin/empty/nempty — see core/logs_query.php). Not persisted.
    const [paramFilters, setParamFilters] = useState([]);
    const PARAM_OPS = ['eq', 'neq', 'in', 'nin', 'empty', 'nempty'];
    const activeParamFilters = paramFilters
        .filter(r => r.param && PARAM_NAME_RE.test(r.param))
        .filter(r => r.op === 'empty' || r.op === 'nempty' || r.value.trim() !== '');
    const updateParamFilter = (idx, patch) => {
        setParamFilters(prev => prev.map((row, i) => i === idx ? { ...row, ...patch } : row));
    };

    // Click-log column resizing (traffic tab) — desktop table only; below lg
    // the log renders as MobileCards and skips resizing entirely.
    //
    // The default widths sum wider than a typical panel (~1870px + param
    // columns), which used to hang the table out of the card with a clipped
    // right edge — it read as a broken, misaligned grid. Defaults are therefore
    // scaled down to the measured container, so out of the box the table fits
    // the card exactly; horizontal scroll appears only once the user has
    // dragged columns wider than there is room (stored widths always win over
    // the fitted defaults — the resize hook resolves stored ?? default).
    const scrollBoxRef = useRef(null);
    const [fitWidth, setFitWidth] = useState(0);
    useEffect(() => {
        const el = scrollBoxRef.current;
        if (!el || typeof ResizeObserver === 'undefined') return undefined;
        const ro = new ResizeObserver((entries) => {
            const w = Math.floor(entries[0].contentRect.width);
            setFitWidth((prev) => (Math.abs(prev - w) > 1 ? w : prev));
        });
        ro.observe(el);
        return () => ro.disconnect();
    }, []);
    const isDesktop = useIsDesktop();
    // Sum of the widths the CURRENT view wants (visible base + param columns) —
    // hidden columns must not eat the fit budget.
    const desiredTotal = visibleBase.reduce((sum, c) => sum + c.width, 0)
        + visibleParams.length * PARAM_COL_WIDTH;
    const fitScale = isDesktop && fitWidth > 0 && desiredTotal > fitWidth ? fitWidth / desiredTotal : 1;
    // Per-column floors keep atomic values (a full timestamp, an IP, a click
    // id, ad-network IDs) readable; everything else floors at FIT_FLOOR.
    // Floors can push the sum past the card by a few dozen px — that small
    // scroll is the honest price of not clipping the time column.
    const fitted = (w, min = FIT_FLOOR) => Math.max(min, Math.round(w * fitScale));
    const colResize = useResizableTableColumns({
        tableId: 'logs_traffic',
        enabled: isDesktop,
        columns: [
            ...orderedColumns.map(c => (c.param
                ? { id: c.id, width: fitted(PARAM_COL_WIDTH, PARAM_COL_MIN) }
                : { id: c.id, width: fitted(c.width, c.min) }))
        ]
    });

    const tabs = {
        traffic: { name: t('logs.traffic'), icon: <Activity className="w-4 h-4" /> },
        postbacks: { name: t('logs.incomingPostbacks'), icon: <ArrowRightLeft className="w-4 h-4" /> },
        s2s: { name: t('logs.sentS2s'), icon: <ServerCrash className="w-4 h-4" /> },
        system: { name: t('logs.systemLog'), icon: <TerminalSquare className="w-4 h-4" /> },
        audit: { name: t('logs.auditLog'), icon: <ShieldAlert className="w-4 h-4" /> },
        replays: { name: t('replay.tabTitle'), icon: <Video className="w-4 h-4" /> }
    };

    // Same filters for the page, "Load more" and the CSV export — the backend
    // builds all three from one query (core/logs_query.php).
    const buildLogParams = (action) => {
        const params = new URLSearchParams({ action, type: activeTab });
        // The shared panel timezone (date picker / profile), as the dashboard
        // sends it: times and date filters here then agree with Recent clicks
        // and the click card instead of silently using the profile default.
        if (timezone) params.append('timezone', timezone);
        if (activeTab === 'traffic') {
            if (filters.campaign_id) params.append('campaign_id', filters.campaign_id);
            if (filters.stream_id) params.append('stream_id', filters.stream_id);
            if (filters.route !== 'all') params.append('route', filters.route);
            if (filters.reason) params.append('reason', filters.reason);
            if (filters.date_from) params.append('date_from', filters.date_from);
            if (filters.date_to) params.append('date_to', filters.date_to);
            if (activeParamFilters.length) {
                params.append('param_filters', JSON.stringify(activeParamFilters.map(r => ({ param: r.param, op: r.op, value: r.value.trim() }))));
            }
            if (visibleParams.length) {
                params.append('param_cols', visibleParams.join(','));
            }
            // Export what the table shows — but only once the user has actually
            // customized the view, so the default export keeps every field.
            if (action === 'logs_export' && columnsCustomized) {
                const fields = orderedColumns.map(c => (c.param ? c.id : c.field));
                if (fields.length) params.append('columns', fields.join(','));
            }
        }
        return params;
    };

    // Derived arrays are rebuilt every render; the fetch effect below keys on
    // stable string signatures instead, or it would refetch on every render.
    const paramFiltersSig = JSON.stringify(activeParamFilters.map(r => [r.param, r.op, r.value]));
    const paramColsSig = visibleParams.join(',');

    useEffect(() => {
        // Session recordings have their own list and API.
        if (activeTab === 'replays') return undefined;
        let cancelled = false;
        setLoading(true);
        setHasMore(false);
        setNextCursor(null);
        const params = buildLogParams('logs');
        params.append('limit', '100');

        fetch(`${API_URL}?${params.toString()}`)
            .then(res => res.json())
            .then(data => {
                if (cancelled) return;
                if (data.status === 'success') {
                    setLogs(data.data);
                    setHasMore(!!data.has_more);
                    setNextCursor(data.next_cursor || null);
                } else {
                    setLogs([]);
                }
                setLoading(false);
            })
            .catch(() => {
                if (cancelled) return;
                setLogs([]);
                setLoading(false);
            });
        return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeTab, filters, paramFiltersSig, paramColsSig, timezone]);

    const loadMore = () => {
        if (!nextCursor || loadingMore) return;
        setLoadingMore(true);
        const params = buildLogParams('logs');
        params.append('limit', '100');
        params.append('cursor', nextCursor);
        fetch(`${API_URL}?${params.toString()}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    setLogs(prev => [...prev, ...data.data]);
                    setHasMore(!!data.has_more);
                    setNextCursor(data.next_cursor || null);
                }
            })
            .catch(() => { /* keep what is already on screen */ })
            .finally(() => setLoadingMore(false));
    };

    const exportHref = `${API_URL}?${buildLogParams('logs_export').toString()}`;

    const renderStream = (log) => {
        if (!log.stream_id) return <span className="text-xs text-[var(--color-text-muted)]">-</span>;
        const typeLabel = log.stream_type === 'intercepting'
            ? t('editor.streamInterceptingShort')
            : log.stream_type === 'fallback'
                ? t('editor.streamFallbackShort')
                : t('editor.streamRegularShort');
        return (
            <span className="flex flex-col gap-0.5 min-w-0">
                <span className="truncate" title={log.stream_name || ''}>{log.stream_name || `#${log.stream_id}`}</span>
                <span className="text-[10px] font-semibold tracking-wide text-[var(--color-text-muted)]">{typeLabel}</span>
            </span>
        );
    };

    // Filter bar lives outside renderTable: rendered inside it, a filter that
    // matched nothing (or a reload in flight) unmounted the bar together with
    // the table, and the operator had no way back short of reloading the page.
    const renderTrafficFilters = () => (
        <div className="mb-4 p-3 rounded-xl border" style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
            <div className="flex items-center gap-2 mb-2">
                <button
                    onClick={() => setShowFilters(!showFilters)}
                    className="flex items-center gap-2 text-xs font-medium"
                    style={{ color: 'var(--color-text-secondary)' }}
                >
                    <Filter className="w-4 h-4" />
                    {showFilters ? <ChevronDown className="w-4 h-4" /> : <ChevronDown className="w-4 h-4 rotate-[-90deg]" />}
                    {t('logs.filterByCampaign')}
                </button>
            </div>
            {showFilters && (
                <div className="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <input
                        type="number"
                        placeholder={t('logs.filterByCampaign')}
                        value={filters.campaign_id}
                        onChange={e => setFilters({ ...filters, campaign_id: e.target.value })}
                        className="form-input text-xs py-1.5 rounded-lg"
                        style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
                    />
                    <input
                        type="number"
                        placeholder={t('logs.filterByStream')}
                        value={filters.stream_id}
                        onChange={e => setFilters({ ...filters, stream_id: e.target.value })}
                        className="form-input text-xs py-1.5 rounded-lg"
                        style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
                    />
                    <select
                        value={filters.route}
                        onChange={e => setFilters({ ...filters, route: e.target.value })}
                        className="form-select text-xs py-1.5 rounded-lg"
                        style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
                    >
                        <option value="all">{t('logs.routeFilterAll')}</option>
                        <option value="money">{t('logs.routeFilterMoney')}</option>
                        <option value="safe">{t('logs.routeFilterSafe')}</option>
                    </select>
                    <input
                        type="text"
                        placeholder={t('logs.filterByReason')}
                        value={filters.reason}
                        onChange={e => setFilters({ ...filters, reason: e.target.value })}
                        className="form-input text-xs py-1.5 rounded-lg"
                        style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
                    />
                    <input
                        type="date"
                        title={t('logs.dateFrom')}
                        aria-label={t('logs.dateFrom')}
                        value={filters.date_from}
                        onChange={e => setFilters({ ...filters, date_from: e.target.value })}
                        className="form-input text-xs py-1.5 rounded-lg"
                        style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
                    />
                    <input
                        type="date"
                        title={t('logs.dateTo')}
                        aria-label={t('logs.dateTo')}
                        value={filters.date_to}
                        onChange={e => setFilters({ ...filters, date_to: e.target.value })}
                        className="form-input text-xs py-1.5 rounded-lg"
                        style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
                    />
                </div>
            )}
            {showFilters && (
                <div className="mt-3 pt-3 border-t" style={{ borderColor: 'var(--color-border)' }}>
                    {/* Parameter filters — Keitaro-style: server-side conditions on
                        any click parameter (ad_id, ttclid, sub_id_N…). "is empty"
                        answers "which clicks arrived WITHOUT this parameter". */}
                    {paramFilters.map((row, idx) => (
                        <div key={idx} className="flex flex-wrap items-center gap-2 mb-2">
                            <select
                                value={row.param}
                                onChange={e => updateParamFilter(idx, { param: e.target.value })}
                                onFocus={ensureParamList}
                                className="form-select text-xs py-1.5 rounded-lg min-w-[140px]"
                                style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
                                aria-label={t('logs.paramFilterParam')}
                            >
                                {!paramOptions.some(o => o.key === row.param) && row.param && (
                                    <option value={row.param}>{row.param}</option>
                                )}
                                {paramOptions.map(o => (
                                    <option key={o.key} value={o.key}>
                                        {o.key}{o.clicks ? ` (${o.clicks})` : ''}
                                    </option>
                                ))}
                            </select>
                            <select
                                value={row.op}
                                onChange={e => updateParamFilter(idx, { op: e.target.value })}
                                className="form-select text-xs py-1.5 rounded-lg"
                                style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
                            >
                                {PARAM_OPS.map(op => <option key={op} value={op}>{t(`logs.op_${op}`)}</option>)}
                            </select>
                            {row.op !== 'empty' && row.op !== 'nempty' && (
                                <input
                                    type="text"
                                    value={row.value}
                                    onChange={e => updateParamFilter(idx, { value: e.target.value })}
                                    placeholder={row.op === 'in' || row.op === 'nin' ? t('logs.paramFilterListHint') : t('logs.paramFilterValue')}
                                    className="form-input text-xs py-1.5 rounded-lg flex-1 min-w-[160px]"
                                    style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
                                />
                            )}
                            <button
                                type="button"
                                onClick={() => setParamFilters(prev => prev.filter((_, i) => i !== idx))}
                                className="p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5"
                                style={{ color: 'var(--color-text-muted)' }}
                                title={t('common.delete')}
                            >
                                <X className="w-3.5 h-3.5" />
                            </button>
                        </div>
                    ))}
                    <button
                        type="button"
                        onClick={() => { ensureParamList(); setParamFilters(prev => [...prev, { param: '', op: 'eq', value: '' }]); }}
                        className="flex items-center gap-1.5 text-xs font-medium"
                        style={{ color: 'var(--color-primary)' }}
                    >
                        <Plus className="w-3.5 h-3.5" />
                        {t('logs.addParamFilter')}
                    </button>
                </div>
            )}
        </div>
    );

    const renderTable = () => {
        if (loading) return <div className="p-8 text-center text-[var(--color-text-muted)]">{t('logs.loadingLogs')}</div>;
        if (!logs.length) return <div className="p-8 text-center text-[var(--color-text-muted)]">{t('logs.noData')}</div>;

        switch (activeTab) {
            case 'traffic': {
                // Shared row renderers — used by both the desktop table and
                // the mobile stacked cards below.
                const renderRouteBadge = (log) => {
                    if (!log.cloak_verdict && !log.is_safe_page) {
                        return <span className="text-xs text-[var(--color-text-muted)]">{t('logs.routeNone')}</span>;
                    }
                    const isMoney = !log.is_safe_page;
                    const label = isMoney ? t('logs.routeMoney') : t('logs.routeSafe');
                    const className = isMoney
                        ? 'status-active'
                        : 'status-inactive';
                    return (
                        <span className={`status-badge ${className} text-[11px]`}>
                            {label}
                        </span>
                    );
                };

                // Reasons are `code:evidence` (crawler_or_tool_ua:curl/) — split
                // on the FIRST ':' so the label resolves and the evidence stays
                // visible.
                const renderReasonChips = (log) => {
                    if (!log.cloak_reasons) return <span className="text-xs text-[var(--color-text-muted)]">-</span>;
                    const reasons = log.cloak_reasons.split(',').filter(Boolean);
                    if (reasons.length === 0) return <span className="text-xs text-[var(--color-text-muted)]">-</span>;
                    return (
                        <div className="flex flex-wrap gap-1">
                            {reasons.map((reason, idx) => {
                                const colon = reason.indexOf(':');
                                const code = colon === -1 ? reason : reason.slice(0, colon);
                                const evidence = colon === -1 ? '' : reason.slice(colon + 1);
                                return (
                                    <span
                                        key={idx}
                                        className="text-[10px] px-1.5 py-0.5 rounded font-mono"
                                        style={{
                                            backgroundColor: 'var(--color-bg-soft)',
                                            color: 'var(--color-text-secondary)',
                                            border: '1px solid var(--color-border)'
                                        }}
                                        title={t(`cloakReasons.${code}`, '') || code}
                                    >
                                        {code}{evidence ? <b style={{ color: 'var(--color-primary)', fontWeight: 600 }}>{` ${evidence}`}</b> : null}
                                    </span>
                                );
                            })}
                        </div>
                    );
                };

                const renderDestination = (log) => {
                    if (log.landing_name) return log.landing_name;
                    if (log.offer_name) return log.offer_name;
                    return <span className="text-xs text-[var(--color-text-muted)]">-</span>;
                };

                // Time on the landing for THIS visit, whether or not it went on
                // to the offer — the beacon writes it for bounces too, which is
                // the whole point of having it in the log. "—" means the visit
                // never reported: a redirect landing on someone else's domain
                // without the tracking script, a bot that runs no JS, or a row
                // from before the feature existed.
                const renderLpTime = (log) => {
                    const secs = log.lp_seconds === null || log.lp_seconds === undefined
                        ? null : Number(log.lp_seconds);
                    if (secs === null || !isFinite(secs)) {
                        return <span className="text-xs text-[var(--color-text-muted)]">—</span>;
                    }
                    const pretty = secs < 60 ? `${secs}s` : `${Math.floor(secs / 60)}m ${secs % 60}s`;
                    const scroll = log.lp_scroll === null || log.lp_scroll === undefined
                        ? null : Number(log.lp_scroll);
                    // Under 5s is the bounce band the reports count — flag it
                    // here too so a scan of the log shows it without arithmetic.
                    const tone = secs < 5 ? 'var(--color-danger)' : 'var(--color-text-primary)';
                    return (
                        <span className="whitespace-nowrap" style={{ color: tone }}>
                            {pretty}
                            {scroll !== null && isFinite(scroll) ? (
                                <span className="text-xs text-[var(--color-text-muted)]">{` · ${scroll}%`}</span>
                            ) : null}
                        </span>
                    );
                };

                const renderGeo = (log) => (
                    <span className="whitespace-normal break-words">
                        {log.country_code || '-'}
                        {[log.region, log.city].filter(Boolean).length > 0 && ` · ${[log.region, log.city].filter(Boolean).join(', ')}`}
                    </span>
                );

                // Logo + label; the label clips with the cell, the full text stays in the tooltip.
                const renderUaCell = (icon, label) => (
                    <span className="inline-flex items-center gap-1.5 max-w-full" title={label || ''}>
                        {icon}
                        <span className="text-xs truncate">{label || '-'}</span>
                    </span>
                );

                // One renderer per base column — shared by the desktop table.
                // Param columns render the row's p_<name> key directly.
                const renderBaseCell = (colId, log) => {
                    switch (colId) {
                        case 'time': return <span title={log.created_at_utc ? `UTC: ${log.created_at_utc}` : undefined}>{log.created_at}</span>;
                        case 'click_id': return renderClickIdLink(log.click_id);
                        case 'subid': return log.subid || '-';
                        case 'campaign': return log.campaign_name || t('logs.direct');
                        case 'stream': return <span className="text-xs">{renderStream(log)}</span>;
                        case 'route': return renderRouteBadge(log);
                        case 'reason': return renderReasonChips(log);
                        case 'destination': return <span className="text-xs">{renderDestination(log)}</span>;
                        case 'direction': return <span className="text-xs block min-w-0"><ClickDirection destination={log.destination} fallbackUrl={log.redirect_url} /></span>;
                        case 'domain': return <span className="text-xs" title={log.domain || ''}>{log.domain || '-'}</span>;
                        case 'lp_time': return <span className="text-xs">{renderLpTime(log)}</span>;
                        case 'ip': return log.ip;
                        case 'geo':
                            return (
                                <div>
                                    <div>{log.country_code || '-'}</div>
                                    <div className="text-xs text-[var(--color-text-muted)]">
                                        {[log.region, log.city].filter(Boolean).join(', ') || '-'}
                                    </div>
                                    {log.geo_timezone ? (
                                        <div className="text-[11px] text-[var(--color-text-muted)]">{log.geo_timezone}</div>
                                    ) : null}
                                </div>
                            );
                        case 'device': return log.device_type || '-';
                        case 'os': return renderUaCell(<OsIcon ua={log.ua} fallbackOs={log.os} />, describeOs(log.ua, log.os));
                        case 'browser': return renderUaCell(<BrowserIcon ua={log.ua} fallbackBrowser={log.browser} />, describeBrowser(log.ua, log.browser));
                        case 'user_agent': return <span className="text-xs text-[var(--color-text-muted)]" title={log.user_agent || ''}>{log.user_agent || '-'}</span>;
                        case 'isp': return <span className="text-xs text-[var(--color-text-muted)]">{log.isp || '-'}</span>;
                        case 'asn': return <span className="text-xs font-mono text-[var(--color-text-muted)]">{log.asn || '-'}</span>;
                        default: return '-';
                    }
                };
                const renderParamCell = (name, log) => {
                    const v = log['p_' + name];
                    if (v === null || v === undefined || v === '') return <span className="text-xs text-[var(--color-text-muted)]">-</span>;
                    return <span className="font-mono text-xs break-all" title={String(v)}>{String(v)}</span>;
                };

                return (
                    <>
                        <div className="hidden lg:block">
                            {/* tracker-table: the traffic log is a wide multi-column
                                grid like Campaigns/Reports, and gets the same dense
                                treatment — compact padding, cell clipping (a narrow
                                column can never paint over its neighbour) and light
                                column dividers. The other log tabs stay .page-table:
                                they are narrow and read fine roomy. */}
                            <table className="page-table tracker-table" style={{ ...colResize.tableStyle }}>
                                {colResize.colgroup}
                                <thead>
                                    <tr>
                                        {orderedColumns.map((col, idx) => (
                                            <SortableTh
                                                key={col.id}
                                                colKey={col.id}
                                                label={col.param ? col.param : t(col.labelKey)}
                                                sortable={false}
                                                sortBy={NO_SORT}
                                                requestSort={noop}
                                                draggable
                                                resize={colResize}
                                                data-col={col.id}
                                                {...columnReorder.thProps(idx)}
                                            />
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {logs.map((log, i) => (
                                        <tr key={i}>
                                            {orderedColumns.map(col => (
                                                <td key={col.id} data-col={col.id}>
                                                    {col.param ? renderParamCell(col.param, log) : renderBaseCell(col.id, log)}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* Mobile stacked cards (below lg): campaign + route on
                            top, time/click id/destination checked at a glance,
                            the rest (subid, reasons, geo, device, ISP, ASN)
                            behind the "More" expander. */}
                        <div className="lg:hidden">
                            <MobileCards
                                rows={logs}
                                getId={(log, i) => log.click_id || `log-${i}`}
                                renderTitle={(log) => (
                                    <>
                                        <span className="font-semibold text-sm truncate" style={{ color: 'var(--color-text-primary)' }}>
                                            {log.campaign_name || t('logs.direct')}
                                        </span>
                                        {renderRouteBadge(log)}
                                    </>
                                )}
                                renderSubtitle={(log) => (
                                    renderClickIdLink(log.click_id, 'font-mono')
                                )}
                                fields={[
                                    { id: 'created_at', label: t('logs.colTime'), render: (log) => log.created_at },
                                    { id: 'destination', label: t('logs.colDestination'), render: renderDestination },
                                    { id: 'direction', label: t('logs.colDirection'), render: (log) => <ClickDirection destination={log.destination} fallbackUrl={log.redirect_url} wrap /> },
                                    { id: 'stream', label: t('logs.colStream'), render: renderStream },
                                    { id: 'lp_time', label: t('logs.colLpTime'), render: renderLpTime },
                                    { id: 'ip', label: t('logs.colIp'), render: (log) => log.ip },
                                    { id: 'geo', label: t('logs.colGeo'), render: renderGeo },
                                    { id: 'subid', label: t('logs.colSubid'), render: (log) => log.subid || '-' },
                                    { id: 'reasons', label: t('logs.colReason'), render: renderReasonChips },
                                    { id: 'device', label: t('logs.colDevice'), render: (log) => log.device_type || '-' },
                                    { id: 'os', label: t('logs.colOs'), render: (log) => renderUaCell(<OsIcon ua={log.ua} fallbackOs={log.os} />, describeOs(log.ua, log.os)) },
                                    { id: 'browser', label: t('logs.colBrowser'), render: (log) => renderUaCell(<BrowserIcon ua={log.ua} fallbackBrowser={log.browser} />, describeBrowser(log.ua, log.browser)) },
                                    { id: 'isp', label: t('logs.colIsp'), render: (log) => log.isp || '-' },
                                    { id: 'asn', label: t('logs.colAsn'), render: (log) => log.asn || '-' },
                                    ...visibleParams.map(name => ({
                                        id: 'p_' + name,
                                        label: name,
                                        render: (log) => log['p_' + name] || '-'
                                    }))
                                ]}
                                primaryIds={['created_at', 'destination', 'stream', 'ip', 'geo']}
                            />
                        </div>
                    </>
                );
            }

            case 'postbacks':
                return (
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('logs.colTime')}</th>
                                <th>{t('logs.colSourceIp')}</th>
                                <th>{t('logs.colClickId')}</th>
                                <th>{t('logs.colCampaign')}</th>
                                <th>{t('logs.colStatus')}</th>
                                <th>{t('logs.colOrigStatus')}</th>
                                <th className="text-right">{t('logs.colPayout')}</th>
                                <th>{t('logs.colResult')}</th>
                                <th>{t('logs.colError')}</th>
                                <th>{t('logs.colSource')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.map((log, i) => {
                                const resultLabels = {
                                    recorded: t('logs.resultRecorded'),
                                    updated: t('logs.resultUpdated'),
                                    rejected: t('logs.resultRejected'),
                                    error: t('logs.resultError')
                                };
                                const resultClasses = {
                                    recorded: 'status-active',
                                    updated: 'status-pending',
                                    rejected: 'status-inactive',
                                    error: 'status-inactive'
                                };
                                const sourceLabels = {
                                    postback: t('logs.sourcePostback'),
                                    pixel: t('logs.sourcePixel')
                                };
                                return (
                                    <tr key={i}>
                                        <td>{log.created_at}</td>
                                        <td className="font-mono text-xs">{log.remote_ip || '-'}</td>
                                        <td>{renderClickIdLink(log.click_id)}</td>
                                        <td>{log.campaign_name || '-'}</td>
                                        <td>
                                            {log.status ? (
                                                <span className={`status-badge ${log.status === 'sale' || log.status === 'lead' ? 'status-active' : 'status-inactive'}`}>
                                                    {log.status}
                                                </span>
                                            ) : '-'}
                                        </td>
                                        <td>{log.original_status || '-'}</td>
                                        <td className={`text-right font-medium ${log.payout > 0 ? 'text-[var(--color-success)]' : 'text-[var(--color-text-muted)]'}`}>
                                            {parseFloat(log.payout) > 0 ? `${log.payout} ${log.currency}` : '0.00'}
                                        </td>
                                        <td>
                                            <span className={`status-badge ${resultClasses[log.result] || 'status-inactive'}`}>
                                                {resultLabels[log.result] || log.result}
                                            </span>
                                        </td>
                                        <td className="truncate max-w-xs" title={log.error || ''}>{log.error || '-'}</td>
                                        <td>
                                            <span className="text-xs text-[var(--color-text-muted)]">
                                                {sourceLabels[log.source] || log.source}
                                            </span>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                );

            case 'system':
                return (
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('logs.colTime')}</th>
                                <th>{t('logs.colLevel')}</th>
                                <th>{t('logs.colMessage')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.map((log, i) => (
                                <tr key={i}>
                                    <td>{log.created_at}</td>
                                    <td>
                                        <span className={`status-badge ${log.level === 'ERROR' ? 'status-inactive' :
                                            log.level === 'WARN' ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400' :
                                                'status-active'
                                            }`}>{log.level}</span>
                                    </td>
                                    <td className="truncate max-w-md">{log.message}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                );

            case 'audit':
                return (
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('logs.colTime')}</th>
                                <th>{t('logs.colEvent')}</th>
                                <th>{t('logs.colResource')}</th>
                                <th>{t('logs.colIp')}</th>
                                <th className="text-right">{t('logs.colStatus')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.map((log, i) => (
                                <tr key={i}>
                                    <td>{log.created_at}</td>
                                    <td className="font-medium">{log.action}</td>
                                    <td>{log.resource} {log.resource_id ? `#${log.resource_id}` : ''}</td>
                                    <td>{log.ip}</td>
                                    <td className="text-right">
                                        <span className={`status-badge ${log.status_code === 200 ? 'status-active' : 'status-inactive'}`}>
                                            {log.status_code}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                );

            case 's2s': {
                // Rows are queue entries written by postback.php and updated by
                // postback_queue_cron.php. Delivery state lives in `status` / `http_code`;
                // `status_code` is the pre-0.9.6 column and may be null on new rows.
                const statusLabels = {
                    pending: t('postbackQueue.statusPending'),
                    in_flight: t('postbackQueue.statusInFlight'),
                    delivered: t('postbackQueue.statusDelivered'),
                    failed: t('postbackQueue.statusFailed'),
                };
                const statusClasses = {
                    pending: 'status-pending',
                    in_flight: 'status-pending',
                    delivered: 'status-active',
                    failed: 'status-inactive',
                };
                return (
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('logs.colTime')}</th>
                                <th>{t('logs.colConversionId')}</th>
                                <th>{t('logs.colUrl')}</th>
                                <th>{t('postbackQueue.status')}</th>
                                <th className="text-right">{t('postbackQueue.attempts')}</th>
                                <th className="text-right">{t('postbackQueue.httpCode')}</th>
                                <th>{t('postbackQueue.lastError')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.map((log, i) => {
                                // Legacy rows (pre-queue) carry no status: treat a 2xx/3xx
                                // status_code as delivered so old logs still read correctly.
                                const legacyCode = Number(log.status_code);
                                const status = log.status
                                    || (legacyCode >= 200 && legacyCode < 400 ? 'delivered' : 'failed');
                                const httpCode = log.http_code ?? log.status_code;
                                return (
                                    <tr key={i}>
                                        <td>{log.created_at}</td>
                                        <td>{log.conversion_id ? `#${log.conversion_id}` : '-'}</td>
                                        <td className="truncate max-w-sm" title={log.url}>{log.url}</td>
                                        <td>
                                            <span className={`status-badge ${statusClasses[status] || 'status-inactive'}`}>
                                                {statusLabels[status] || status}
                                            </span>
                                            {status === 'pending' && log.next_retry_at && (
                                                <div className="text-xs text-[var(--color-text-muted)] mt-1">
                                                    {t('postbackQueue.nextRetry')}: {log.next_retry_at}
                                                </div>
                                            )}
                                        </td>
                                        <td className="text-right">{Number(log.attempts ?? 0)}</td>
                                        <td className="text-right">{httpCode ? Number(httpCode) : '-'}</td>
                                        <td className="truncate max-w-xs" title={log.last_error || ''}>
                                            {log.last_error || '-'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                );
            }

            default:
                return null;
        }
    };

    return (
        <div className="page-card">
            <div className="flex items-center gap-2 mb-4">
                <FileStack size={18} className="text-[var(--color-primary)]" />
                <h3 className="page-title m-0">{t('logs.title')}</h3>
                <div className="ml-auto relative flex items-center gap-2">
                    {activeTab === 'traffic' && (
                        <>
                            <button
                                type="button"
                                onClick={() => { setColumnsOpen(!columnsOpen); ensureParamList(); }}
                                className={`btn flex items-center gap-2 text-xs ${columnsOpen ? 'btn-primary' : 'btn-secondary'}`}
                                title={t('logs.columnsHint')}
                            >
                                <Columns3 size={14} />
                                <span>{t('logs.columns')}</span>
                            </button>
                            {columnsOpen && (
                                <>
                                    <div className="fixed inset-0" style={{ zIndex: 40 }} onClick={() => setColumnsOpen(false)} />
                                    <div
                                        className="absolute right-0 top-full mt-2 w-80 max-h-[60vh] overflow-y-auto rounded-xl border shadow-xl p-3 text-xs"
                                        style={{
                                            zIndex: 50,
                                            backgroundColor: 'var(--color-bg-card)',
                                            borderColor: 'var(--color-border)'
                                        }}
                                    >
                                        <div className="font-semibold mb-2" style={{ color: 'var(--color-text-primary)' }}>
                                            {t('logs.columnsBase')}
                                        </div>
                                        {BASE_COLUMN_DEFS.map(col => (
                                            <label key={col.id} className="flex items-center gap-2 py-1 cursor-pointer" style={{ color: 'var(--color-text-primary)' }}>
                                                <input
                                                    type="checkbox"
                                                    checked={effectiveColumns.includes(col.id)}
                                                    onChange={() => toggleColumn(col.id)}
                                                />
                                                {t(col.labelKey)}
                                            </label>
                                        ))}
                                        <div className="font-semibold mt-3 mb-1 pt-2 border-t" style={{ color: 'var(--color-text-primary)', borderColor: 'var(--color-border)' }}>
                                            {t('logs.columnsParams')}
                                        </div>
                                        <p className="m-0 mb-2" style={{ color: 'var(--color-text-muted)' }}>
                                            {t('logs.columnsParamsHint')}
                                        </p>
                                        {paramOptions.length === 0 && (
                                            <p className="m-0" style={{ color: 'var(--color-text-muted)' }}>{t('logs.columnsParamsEmpty')}</p>
                                        )}
                                        {paramOptions.map(o => {
                                            const id = 'p:' + o.key;
                                            return (
                                                <label key={id} className="flex items-center gap-2 py-1 cursor-pointer font-mono" style={{ color: 'var(--color-text-primary)' }}>
                                                    <input
                                                        type="checkbox"
                                                        checked={effectiveColumns.includes(id)}
                                                        onChange={() => toggleColumn(id)}
                                                    />
                                                    <span className="break-all">{o.key}</span>
                                                    {o.clicks ? (
                                                        <span className="ml-auto text-[10px] text-[var(--color-text-muted)] whitespace-nowrap pl-2">{o.clicks}</span>
                                                    ) : null}
                                                </label>
                                            );
                                        })}
                                    </div>
                                </>
                            )}
                        </>
                    )}
                    {!isReplays && <a
                        href={exportHref}
                        className="btn btn-secondary flex items-center gap-2 text-xs"
                        title={t('logs.exportHint')}
                    >
                        <Download size={14} />
                        <span>{t('logs.exportCsv')}</span>
                    </a>}
                </div>
            </div>

            {/* Tabs */}
            <div className="flex items-center gap-1 mb-4 p-1 bg-[var(--color-bg-soft)] rounded-lg overflow-x-auto">
                {Object.entries(tabs).map(([id, tab]) => (
                    <button
                        key={id}
                        onClick={() => setActiveTab(id)}
                        className={`flex items-center gap-2 px-3 py-2 rounded-md text-sm font-medium whitespace-nowrap transition-colors ${activeTab === id
                            ? 'bg-[var(--color-bg)] text-[var(--color-primary)] shadow-sm'
                            : 'text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'
                            }`}
                    >
                        {tab.icon}
                        <span>{tab.name}</span>
                    </button>
                ))}
            </div>

            {isReplays && <SessionReplays />}

            {activeTab === 'traffic' && renderTrafficFilters()}

            {/* Table */}
            {!isReplays && <div ref={scrollBoxRef} className="overflow-x-auto rounded-lg border border-[var(--color-border)]">
                {renderTable()}
            </div>}

            {!isReplays && !loading && hasMore && (
                <div className="mt-4 flex justify-center">
                    <button
                        type="button"
                        onClick={loadMore}
                        disabled={loadingMore}
                        className="btn btn-secondary text-sm"
                    >
                        {loadingMore ? t('logs.loadingLogs') : t('logs.loadMore')}
                    </button>
                </div>
            )}

            {/* Info */}
            {!isReplays && <div className="mt-4 text-xs text-[var(--color-text-muted)]">
                {t('logs.shownCount', { count: logs.length })} {t('logs.lastRecords')}
            </div>}

            {selectedClickId && (
                <ClickDetailsModal
                    clickId={selectedClickId}
                    onClose={() => setSelectedClickId(null)}
                />
            )}
        </div>
    );
};

export default LogsPage;
