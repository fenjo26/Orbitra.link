import React, { useState, useEffect, useMemo } from 'react';
import axios from 'axios';
import { X, Download, Filter, BarChart3, Plus, Trash2, SlidersHorizontal, GripVertical, ChevronRight } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import DateRangePicker, { formatDate, getPresetDates } from './DateRangePicker';
import { useTimezone } from '../utils/useTimezone';
import ReportCustomizerModal, { ALL_REPORT_METRICS, PRESETS, getDimensionLabel, getDefaultTemplateColumns, getReportMetricTooltip, normalizeReportMetricIds, resolveInitialGroupLayers, persistLastAppliedGroupBy, DEFAULT_REPORT_LAYERS } from './ReportCustomizerModal';
import { useIsDesktop, useResizableTableColumns, ColumnResizeHandle } from './common/ColumnResize';
import { resolveConversionColor } from '../utils/conversionColors';

const API_URL = '/api.php';
const FB_HIERARCHY_LAYERS = ['ad_campaign_id', 'adset_id', 'ad_id'];
// Status-count columns whose header carries the conversion-type color marker.
const STATUS_COLUMN_STATUSES = {
    leads: 'lead',
    sales: 'sale',
    registrations: 'registration',
    deposits: 'deposit',
    rejected: 'rejected',
    trash: 'trash',
};
const REPORT_LAYER_PRESETS = [
    { id: 'facebook_hierarchy', label: 'Facebook Hierarchy', layers: FB_HIERARCHY_LAYERS },
    { id: 'geo', label: 'Geo (Country → City)', layers: ['country', 'region', 'city'] },
    { id: 'devices', label: 'Devices', layers: ['device_type', 'os', 'browser'] },
    { id: 'funnel', label: 'Funnel (Stream → Offer)', layers: ['stream_id', 'landing_id', 'offer_id'] },
    { id: 'time', label: 'Time (Day → Hour)', layers: ['day', 'hour'] },
    { id: 'subids', label: 'Sub IDs', layers: ['sub_id_1', 'sub_id_2', 'sub_id_3'] },
    { id: 'google', label: 'Google Ads', layers: ['campaign_id', 'keyword', 'creative_id'] },
];
const ENTITY_TYPE_BY_DIMENSION = {
    campaign_id: 'tracker_campaign',
    ad_campaign_id: 'ad_campaign',
    adset_id: 'adset',
    ad_id: 'ad'
};

// ad_entity_statuses entries are { status, effective }; an optimistic toggle
// writes a bare string. Both shapes must keep rendering (cached responses,
// in-flight marks).
const entityToggleStatus = (entry) => (typeof entry === 'string' ? entry : (entry && typeof entry === 'object' ? entry.status : undefined));
const entityEffectiveStatus = (entry) => (entry && typeof entry === 'object' && typeof entry.effective === 'string' ? entry.effective : null);

const CampaignReports = ({ campaignId, campaignName, onClose }) => {
    const { t } = useLanguage();
    const formatDimensionLabel = (dimension) => getDimensionLabel(dimension, t);
    const [loading, setLoading] = useState(true);
    const [rows, setRows] = useState([]);
    const [layerKeys, setLayerKeys] = useState([]);

    // Play/pause toggles (campaign_id layer = internal campaign state,
    // ad_id / adset_id / ad_campaign_id = a command to the ad network).
    // The tracker is not the source of truth for network state, so the mark
    // is optimistic: it flips on click and the notice carries the verdict.
    const [entityStatus, setEntityStatus] = useState({});
    const [togglingIds, setTogglingIds] = useState(new Set());
    const [toggleNotice, setToggleNotice] = useState(null);
    // Custom conversion-type colors for the status column header markers.
    const [conversionTypes, setConversionTypes] = useState([]);

    useEffect(() => {
        axios.get(`${API_URL}?action=conversion_types`)
            .then(res => {
                if (res.data.status === 'success') setConversionTypes(res.data.data || []);
            })
            .catch(() => {});
    }, []);

    const handleToggleEntityStatus = async (dimKey, row) => {
        const entityId = String(row.dimId ?? '').trim();
        if (!/^\d+$/.test(entityId) || togglingIds.has(entityId)) return;

        const entityType = ENTITY_TYPE_BY_DIMENSION[dimKey];
        if (!entityType) return;

        const nextStatus = (entityToggleStatus(entityStatus[entityId]) || 'ACTIVE') === 'ACTIVE' ? 'PAUSED' : 'ACTIVE';
        setTogglingIds(prev => new Set(prev).add(entityId));
        try {
            const res = await axios.post(`${API_URL}?action=ad_entity_toggle_status`, {
                entity_type: entityType,
                entity_id: entityId,
                target_status: nextStatus
            });
            if (res.data.status === 'success') {
                setEntityStatus(prev => ({ ...prev, [entityId]: nextStatus }));
                const target = entityType === 'tracker_campaign' ? 'Orbitra' : 'Ads Manager';
                setToggleNotice({
                    type: 'success',
                    text: `${formatDimensionLabel(dimKey)} ${entityId} → ${nextStatus} in ${target}`
                });
            } else {
                const codeMap = { no_connection: 'automation.noConnection', unsupported_network: 'automation.unsupportedNetwork', invalid_id: 'automation.invalidId' };
                const text = (res.data.code && t(codeMap[res.data.code])) || res.data.message || t('automation.statusUpdateError');
                setToggleNotice({ type: 'error', text });
            }
        } catch {
            setToggleNotice({ type: 'error', text: t('automation.networkError') });
        } finally {
            setTogglingIds(prev => { const s = new Set(prev); s.delete(entityId); return s; });
            setTimeout(() => setToggleNotice(null), 4000);
        }
    };

    // A flat, single-dimension report by default — the multi-level drill-down is
    // an opt-in via the layer builder. Starting layered made every report look
    // like duplicated subtotal rows.
    const [layers, setLayers] = useState(() => resolveInitialGroupLayers(REPORT_LAYER_PRESETS));
    const [filters, setFilters] = useState([]);

    // Date & Timezone Picker. The range survives a reload of an open overlay
    // (sessionStorage); opening from Campaigns clears the key, so every fresh
    // open starts at the default. A stored preset is re-derived, not replayed
    // as literal dates — "today" must still mean today tomorrow.
    const savedRange = (() => {
        try { return JSON.parse(sessionStorage.getItem('orbitra_reports_range') || 'null'); } catch { return null; }
    })();
    const todayPreset = (savedRange?.preset && savedRange.preset !== 'custom' && getPresetDates(savedRange.preset))
        || getPresetDates('last7Days') || getPresetDates('today');
    const [dateFrom, setDateFrom] = useState(
        savedRange?.preset === 'custom'
            ? (savedRange?.from || todayPreset?.from || formatDate(new Date()))
            : (todayPreset?.from || formatDate(new Date()))
    );
    const [dateTo, setDateTo] = useState(
        savedRange?.preset === 'custom'
            ? (savedRange?.to || todayPreset?.to || formatDate(new Date()))
            : (todayPreset?.to || formatDate(new Date()))
    );
    const [timezone, setTimezone] = useTimezone();

    // Column customizer state
    const [customizerOpen, setCustomizerOpen] = useState(false);
    const [chosenColumns, setChosenColumns] = useState(() => {
        try {
            const saved = localStorage.getItem('orbitra_report_columns');
            if (saved) return normalizeReportMetricIds(JSON.parse(saved));
        } catch { /* unreadable saved value — fall through */ }
        // No per-page selection yet — fall back to the user's default template
        const fromDefaultTemplate = getDefaultTemplateColumns();
        if (fromDefaultTemplate) return fromDefaultTemplate;
        return [...PRESETS.best];
    });

    // Drag-and-drop column state
    const [thDragIdx, setThDragIdx] = useState(null);
    const [thDragOverIdx, setThDragOverIdx] = useState(null);

    // Resizable columns — the report table is desktop-only, but the flag
    // keeps the controller honest below lg anyway.
    const isDesktop = useIsDesktop();
    const columnDefs = useMemo(
        () => [{ id: 'name', width: 300 }, ...chosenColumns.map(id => ({ id, width: 120 }))],
        [chosenColumns]
    );
    const colResize = useResizableTableColumns({ tableId: 'campaign_reports', columns: columnDefs, enabled: isDesktop });

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
            const copy = [...chosenColumns];
            const item = copy.splice(thDragIdx, 1)[0];
            copy.splice(targetIdx, 0, item);
            setChosenColumns(copy);
            localStorage.setItem('orbitra_report_columns', JSON.stringify(copy));
        }
        setThDragIdx(null);
        setThDragOverIdx(null);
    };

    const handleThDragEnd = () => {
        setThDragIdx(null);
        setThDragOverIdx(null);
    };

    const handleSaveColumns = (cols) => {
        setChosenColumns(cols);
        localStorage.setItem('orbitra_report_columns', JSON.stringify(cols));
    };

    const handleSaveLayers = (newLayers) => {
        const normalized = newLayers.length > 0 ? newLayers : [...DEFAULT_REPORT_LAYERS];
        setLayers(normalized);
        persistLastAppliedGroupBy(normalized);
    };

    const handleSaveFilters = (newFilters) => {
        setFilters(newFilters);
    };

    const fetchReport = async () => {
        setLoading(true);
        try {
            const params = {
                group_by: layers.join(','),
                date_from: dateFrom,
                date_to: dateTo
            };
            if (campaignId) params.campaign_id = campaignId;
            // The picker's timezone decides which day a click belongs to — send it.
            // Read the state, not localStorage: this fetch used to read the key
            // directly while the header rendered React state, so the two could
            // disagree about which period was on screen.
            if (timezone) params.timezone = timezone;
            if (filters.length > 0) {
                params.filters = JSON.stringify(filters);
            }
            const res = await axios.get(`${API_URL}?action=campaign_report`, { params });
            if (res.data.status === 'success') {
                setRows(res.data.data.rows || []);
                setLayerKeys(res.data.data.layers || layers);
            } else {
                alert(t('campaignReports.loadError') + (res.data.message || ''));
            }
        } catch (e) {
            console.error(e);
            alert(t('campaignReports.networkError'));
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchReport();
    }, [campaignId, layers.join(','), dateFrom, dateTo, JSON.stringify(filters), timezone]);

    // Build the hierarchical tree from flat rows, then flatten into display rows
    const displayRows = useMemo(() => {
        const createEmptyAgg = () => ({
            clicks: 0,
            unique_clicks: 0,
            prelander_clicks: 0,
            lp_views: 0,
            lp_clicks: 0,
            offer_clicks: 0,
            real_lp_clicks: 0,
            real_offer_clicks: 0,
            pwa_intents: 0,
            pwa_installs: 0,
            real_pwa_installs: 0,
            pwa_opens: 0,
            push_subscribed: 0,
            conversions: 0,
            purchases: 0,
            sales: 0,
            holds: 0,
            leads: 0,
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
        });

        const addRow = (node, row) => {
            node.clicks += Number(row.clicks) || 0;
            node.unique_clicks += Number(row.unique_clicks) || 0;
            const lpViews = Number(row.lp_views ?? row.prelander_clicks ?? row.clicks) || 0;
            node.prelander_clicks += lpViews;
            node.lp_views += lpViews;
            node.lp_clicks += Number(row.lp_clicks ?? row.offer_clicks) || 0;
            node.offer_clicks += Number(row.offer_clicks) || 0;
            node.real_lp_clicks += Number(row.real_lp_clicks) || 0;
            node.real_offer_clicks += Number(row.real_offer_clicks) || 0;
            node.pwa_intents += Number(row.pwa_intents) || 0;
            node.pwa_installs += Number(row.pwa_installs) || 0;
            node.real_pwa_installs += Number(row.real_pwa_installs) || 0;
            node.pwa_opens += Number(row.pwa_opens) || 0;
            node.push_subscribed += Number(row.push_subscribed) || 0;
            node.conversions += Number(row.conversions) || 0;
            const purchases = Number(row.purchases ?? row.sales) || 0;
            node.purchases += purchases;
            node.sales += purchases;
            const holds = Number(row.holds ?? row.leads) || 0;
            node.holds += holds;
            node.leads += holds;
            node.rejected += Number(row.rejected) || 0;
            node.trash += Number(row.trash) || 0;
            node.cost += Number(row.cost) || 0;
            node.revenue += Number(row.revenue) || 0;
            node.revenue_confirmed += Number(row.revenue_confirmed) || 0;
            node.revenue_hold += Number(row.revenue_hold) || 0;
            node.revenue_rejected += Number(row.revenue_rejected) || 0;
            node.revenue_trash += Number(row.revenue_trash) || 0;
            node.profit += Number(row.profit) || (Number(row.revenue || 0) - Number(row.cost || 0));
            node.real_revenue += Number(row.real_revenue) || 0;
            node.real_profit += Number(row.real_profit) || 0;
        };

        const computeDerived = (node) => {
            const lpClickDenominator = node.lp_clicks > 0 ? node.lp_clicks : node.clicks;
            node.sales = node.purchases;
            node.leads = node.holds;
            node.uc_rate = node.clicks > 0 ? (node.unique_clicks / node.clicks) * 100 : 0;
            node.lp_ctr = node.lp_views > 0 ? (node.lp_clicks / node.lp_views) * 100 : 0;
            node.real_lp_ctr = node.lp_views > 0 ? (node.real_lp_clicks / node.lp_views) * 100 : 0;
            // Zero installs → null, rendered as a dash (backend honesty rule).
            node.pwa_install_rate = node.clicks > 0 && node.real_pwa_installs > 0
                ? (node.real_pwa_installs / node.clicks) * 100
                : null;
            node.cr = node.clicks > 0 ? (node.conversions / node.clicks) * 100 : 0;
            node.cr_all = node.cr;
            node.cr_sales = node.clicks > 0 ? (node.purchases / node.clicks) * 100 : 0;
            node.cr_holds = node.clicks > 0 ? (node.holds / node.clicks) * 100 : 0;
            node.cr_leads = node.cr_holds;
            node.approve_rate = node.conversions > 0 ? (node.purchases / node.conversions) * 100 : 0;
            const nonTrash = node.purchases + node.holds + node.rejected;
            node.approve_rate_excl_trash = nonTrash > 0 ? (node.purchases / nonTrash) * 100 : 0;
            node.profit_confirmed = node.revenue_confirmed - node.cost;
            node.roi = node.cost > 0 ? (node.profit / node.cost) * 100 : 0;
            node.roi_confirmed = node.cost > 0 ? (node.profit_confirmed / node.cost) * 100 : 0;
            node.real_roi = node.cost > 0 ? (node.real_profit / node.cost) * 100 : 0;
            node.epc = lpClickDenominator > 0 ? node.revenue / lpClickDenominator : 0;
            node.epc_all = node.epc;
            node.epv = node.lp_views > 0 ? node.revenue / node.lp_views : 0;
            node.uepc = node.unique_clicks > 0 ? node.revenue / node.unique_clicks : 0;
            node.cpc = lpClickDenominator > 0 ? node.cost / lpClickDenominator : 0;
            node.ucpc = node.unique_clicks > 0 ? node.cost / node.unique_clicks : 0;
            node.cpv = node.lp_views > 0 ? node.cost / node.lp_views : 0;
            node.cpa = node.conversions > 0 ? node.cost / node.conversions : 0;
            node.cps = node.purchases > 0 ? node.cost / node.purchases : 0;
            node.cpl = node.holds > 0 ? node.cost / node.holds : 0;
            node.earnings_per_conv = node.conversions > 0 ? node.revenue / node.conversions : 0;
        };

        const root = { ...createEmptyAgg(), children: new Map() };
        rows.forEach(row => {
            let node = root;
            addRow(root, row);
            const dims = row.dims || [];
            const dimIds = row.dim_ids || dims;
            dims.forEach((dimValue, i) => {
                // Group by the RAW id (dim_ids), not the display name — two
                // entities can share a name (bulk copies) and must not merge
                // into one node. `name` stays the display value.
                const rawId = String(dimIds[i] ?? dimValue ?? '');
                const key = rawId !== '' ? rawId : 'none';
                if (!node.children.has(key)) {
                    node.children.set(key, { ...createEmptyAgg(), children: new Map(), dimId: rawId === '' ? 'none' : rawId, dimName: String(dimValue ?? '') });
                }
                node = node.children.get(key);
                addRow(node, row);
            });
        });

        const out = [];
        const walk = (node, depth) => {
            computeDerived(node);
            const children = [...node.children.entries()].sort((a, b) => b[1].clicks - a[1].clicks);
            out.push({
                name: node.dimName,
                depth,
                dimId: node.dimId,
                subtotal: depth < layers.length - 1 || children.length > 0,
                ...node,
                childrenCount: children.length
            });
            children.forEach(([, child]) => walk(child, depth + 1));
        };

        [...root.children.entries()].sort((a, b) => b[1].clicks - a[1].clicks).forEach(([, child]) => walk(child, 0));
        return out;
    }, [rows, layers.length]);

    // Server truth for the play-pause toggles: one read per report shape.
    // Server wins by default — only ids with a toggle in flight keep their
    // optimistic value, and the re-run when the flight ends reconciles the
    // mark against what the network actually reports. Spreading `prev` last
    // instead made the first fetched value permanent: a pause never survived
    // closing and reopening the report.
    useEffect(() => {
        const byType = new Map();
        displayRows.forEach(r => {
            const entityType = ENTITY_TYPE_BY_DIMENSION[layers[r.depth]];
            if (!entityType || !r.dimId || r.dimId === 'Unknown' || r.dimId === 'none') return;
            const entityId = String(r.dimId).trim();
            if (!/^\d+$/.test(entityId)) return;
            if (!byType.has(entityType)) byType.set(entityType, new Set());
            byType.get(entityType).add(entityId);
        });
        if (byType.size === 0) return undefined;

        const items = [...byType.entries()].flatMap(([entity_type, ids]) =>
            [...ids].map(entity_id => ({ entity_type, entity_id })));
        let cancelled = false;
        axios.post(`${API_URL}?action=ad_entity_statuses`, { items })
            .then(res => {
                if (cancelled || res.data.status !== 'success' || !res.data.data) return;
                const fetched = res.data.data;
                setEntityStatus(prev => {
                    const next = { ...prev, ...fetched };
                    togglingIds.forEach(id => { if (id in prev) next[id] = prev[id]; });
                    return next;
                });
            })
            .catch(() => {});
        return () => { cancelled = true; };
        // togglingIds on purpose: the optimistic mark protects the id while
        // the command is in flight, and the post-flight change re-reads the
        // server so a failed command cannot leave a stale PAUSED behind.
    }, [displayRows, layers, togglingIds]);

    const grandTotal = useMemo(() => {
        const t0 = {
            clicks: 0,
            unique_clicks: 0,
            prelander_clicks: 0,
            lp_views: 0,
            lp_clicks: 0,
            offer_clicks: 0,
            real_lp_clicks: 0,
            real_offer_clicks: 0,
            pwa_intents: 0,
            pwa_installs: 0,
            real_pwa_installs: 0,
            pwa_opens: 0,
            push_subscribed: 0,
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

        rows.forEach(r => {
            t0.clicks += Number(r.clicks) || 0;
            t0.unique_clicks += Number(r.unique_clicks) || 0;
            const lpViews = Number(r.lp_views ?? r.prelander_clicks ?? r.clicks) || 0;
            t0.prelander_clicks += lpViews;
            t0.lp_views += lpViews;
            t0.lp_clicks += Number(r.lp_clicks ?? r.offer_clicks) || 0;
            t0.offer_clicks += Number(r.offer_clicks) || 0;
            t0.real_lp_clicks += Number(r.real_lp_clicks) || 0;
            t0.real_offer_clicks += Number(r.real_offer_clicks) || 0;
            t0.pwa_intents += Number(r.pwa_intents) || 0;
            t0.pwa_installs += Number(r.pwa_installs) || 0;
            t0.real_pwa_installs += Number(r.real_pwa_installs) || 0;
            t0.pwa_opens += Number(r.pwa_opens) || 0;
            t0.push_subscribed += Number(r.push_subscribed) || 0;
            t0.conversions += Number(r.conversions) || 0;
            const purchases = Number(r.purchases ?? r.sales) || 0;
            t0.purchases += purchases;
            const holds = Number(r.holds ?? r.leads) || 0;
            t0.holds += holds;
            t0.rejected += Number(r.rejected) || 0;
            t0.trash += Number(r.trash) || 0;
            t0.cost += Number(r.cost) || 0;
            t0.revenue += Number(r.revenue) || 0;
            t0.revenue_confirmed += Number(r.revenue_confirmed) || 0;
            t0.revenue_hold += Number(r.revenue_hold) || 0;
            t0.revenue_rejected += Number(r.revenue_rejected) || 0;
            t0.revenue_trash += Number(r.revenue_trash) || 0;
            t0.profit += Number(r.profit) || (Number(r.revenue || 0) - Number(r.cost || 0));
            t0.real_revenue += Number(r.real_revenue) || 0;
            t0.real_profit += Number(r.real_profit) || 0;
        });

        const uc_rate = t0.clicks > 0 ? (t0.unique_clicks / t0.clicks) * 100 : 0;
        const lpClickDenominator = t0.lp_clicks > 0 ? t0.lp_clicks : t0.clicks;
        const lp_ctr = t0.lp_views > 0 ? (t0.lp_clicks / t0.lp_views) * 100 : 0;
        const real_lp_ctr = t0.lp_views > 0 ? (t0.real_lp_clicks / t0.lp_views) * 100 : 0;
        const pwa_install_rate = t0.clicks > 0 && t0.real_pwa_installs > 0
            ? (t0.real_pwa_installs / t0.clicks) * 100
            : null;
        const cr = t0.clicks > 0 ? (t0.conversions / t0.clicks) * 100 : 0;
        const cr_sales = t0.clicks > 0 ? (t0.purchases / t0.clicks) * 100 : 0;
        const cr_holds = t0.clicks > 0 ? (t0.holds / t0.clicks) * 100 : 0;
        const cr_leads = cr_holds;
        const sales = t0.purchases;
        const leads = t0.holds;
        const profit_confirmed = t0.revenue_confirmed - t0.cost;
        const roi_confirmed = t0.cost > 0 ? (profit_confirmed / t0.cost) * 100 : 0;
        const cpl = t0.holds > 0 ? t0.cost / t0.holds : 0;
        const cps = t0.purchases > 0 ? t0.cost / t0.purchases : 0;
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
            sales,
            leads,
            profit_confirmed,
            roi_confirmed,
            cpl,
            cps,
            uc_rate,
            lp_ctr,
            real_lp_ctr,
            pwa_install_rate,
            cr,
            cr_all: cr,
            cr_sales,
            cr_holds,
            cr_leads,
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
    }, [rows]);

    const formatMetricCell = (metricId, row, strong = false) => {
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
            case 'lp_measured':
            case 'lp_clicks':
            case 'real_lp_clicks':
            case 'real_offer_clicks':
            case 'pwa_intents':
            case 'pwa_installs':
            case 'real_pwa_installs':
            case 'pwa_opens':
            case 'push_subscribed':
            case 'pwa_intents':
            case 'pwa_installs':
            case 'real_pwa_installs':
            case 'pwa_opens':
            case 'purchases':
            case 'sales':
            case 'holds':
            case 'leads':
            case 'registrations':
            case 'deposits':
            case 'rejected':
            case 'trash':
            case 'lp_measured':
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
            case 'real_lp_ctr':
            case 'pwa_install_rate':
                return val === null || val === undefined ? '—' : `${num.toFixed(2)}%`;

            // The landing timer only reports for pages that ran it, so these
            // are null (dash) rather than a fabricated 0 when nothing measured.
            case 'lp_bounce_rate':
            case 'lp_scroll_depth':
                return val === null || val === undefined ? '—' : `${num.toFixed(1)}%`;

            case 'roi':
            case 'roi_all':
            case 'roi_confirmed':
            case 'real_roi': {
                if (val === null || val === undefined) return '—';
                // Zero ROI (no spend yet) is neutral, not "positive" — a green
                // +0.00% reads as profit on idle rows. Same rule for the Σ row.
                if (num === 0) {
                    return <span style={{ color: 'var(--color-text-muted)' }}>0.00%</span>;
                }
                const isPos = num > 0;
                return (
                    <span style={{ color: isPos ? 'var(--color-success)' : 'var(--color-danger)', fontWeight: strong ? 700 : 600 }}>
                        {isPos ? '+' : ''}{num.toFixed(2)}%
                    </span>
                );
            }

            case 'profit':
            case 'profit_all':
            case 'profit_confirmed':
            case 'real_profit': {
                // $0.00 is neutral gray — zero means "nothing happened yet",
                // not a profitable row. The minus goes before the currency
                // sign (-$5.20, not $-5.20).
                if (Math.abs(num) < 0.0001) {
                    return <span style={{ color: 'var(--color-text-secondary)' }}>$0.00</span>;
                }
                const isPos = num > 0;
                return (
                    <span style={{ color: isPos ? 'var(--color-success)' : 'var(--color-danger)', fontWeight: strong ? 700 : 600 }}>
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

    const exportToCSV = () => {
        if (!displayRows.length) return;
        const headers = [
            layerKeys.map(formatDimensionLabel).join(' > '),
            ...chosenColumns.map(cId => {
                const def = ALL_REPORT_METRICS.find(m => m.id === cId);
                return def?.label || cId;
            })
        ];

        const csvContent = [
            headers.join(','),
            ...displayRows.map(r => [
                `"${'  '.repeat(r.depth)}${String(r.name).replace(/"/g, '""')}"`,
                ...chosenColumns.map(cId => {
                    const v = r[cId];
                    if (typeof v !== 'number') return String(v || '');
                    return v.toFixed(2);
                })
            ].join(','))
        ].join('\n');

        const bom = new Uint8Array([0xEF, 0xBB, 0xBF]);
        const blob = new Blob([bom, csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.setAttribute('href', URL.createObjectURL(blob));
        link.setAttribute('download', `report_${campaignId || 'all'}_${layerKeys.join('_by_')}_${dateFrom}_to_${dateTo}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    return (
        <div className="fixed top-[88px] left-0 right-0 bottom-0 z-[2000] flex bg-black/60 backdrop-blur-sm">
            <div className="flex flex-col w-full h-full" style={{ backgroundColor: 'var(--color-bg-main)', color: 'var(--color-text-primary)' }}>
                {/* Header Toolbar */}
                <div
                    className="flex justify-between items-center px-6 py-3.5 border-b shadow-sm"
                    style={{
                        backgroundColor: 'var(--color-bg-header)',
                        color: 'var(--color-text-header)',
                        borderColor: 'var(--color-border)'
                    }}
                >
                    <div className="flex items-center gap-3">
                        <BarChart3 className="w-5 h-5" style={{ color: 'var(--color-primary)' }} />
                        <h2 className="text-base font-bold">
                            {t('campaignReports.report')} — {campaignName || t('campaignReports.allCampaigns', 'All Campaigns')}
                        </h2>
                    </div>

                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            onClick={exportToCSV}
                            className="btn btn-success text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5 font-medium"
                        >
                            <Download className="w-3.5 h-3.5" />
                            {t('campaignReports.exportCsv')}
                        </button>
                        <button
                            type="button"
                            onClick={onClose}
                            className="btn-icon"
                            title={t('campaignReports.close')}
                        >
                            <X className="w-5 h-5" />
                        </button>
                    </div>
                </div>

                {/* Sub-Header: Layers Pills, Columns Customizer Button [ ☵ ], DateRangePicker */}
                <div
                    className="p-3 px-6 flex flex-wrap gap-4 items-center justify-between border-b shadow-sm"
                    style={{
                        backgroundColor: 'var(--color-bg-card)',
                        borderColor: 'var(--color-border)'
                    }}
                >
                    {/* Active Layers and Columns Customizer Button */}
                    <div className="flex items-center gap-2.5 flex-wrap">
                        {/* Columns [ ☵ ] Button */}
                        <button
                            type="button"
                            onClick={() => setCustomizerOpen(true)}
                            className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5 font-semibold"
                            style={{
                                backgroundColor: 'var(--color-bg-soft)',
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

                        <div className="h-4 w-[1px]" style={{ backgroundColor: 'var(--color-border)' }}></div>

                        <span className="text-xs font-semibold uppercase" style={{ color: 'var(--color-text-muted)' }}>
                            {t('reportCustomizer.groupBy')}:
                        </span>
                        {layers.map((lName, idx) => (
                            <span
                                key={idx}
                                className="text-xs px-2.5 py-1 rounded-lg border font-medium flex items-center gap-1"
                                style={{
                                    backgroundColor: 'var(--color-bg-soft)',
                                    borderColor: 'var(--color-border)',
                                    color: 'var(--color-text-primary)'
                                }}
                            >
                                <span className="text-[10px] font-bold text-blue-500">{idx + 1}.</span>
                                <span>{formatDimensionLabel(lName)}</span>
                            </span>
                        ))}
                    </div>

                    {/* Right Side: DateRangePicker */}
                    <div className="flex items-center gap-2">
                        <DateRangePicker
                            dateFrom={dateFrom}
                            dateTo={dateTo}
                            onChange={(from, to, preset) => {
                                setDateFrom(from);
                                setDateTo(to);
                                const p = preset || 'custom';
                                try { sessionStorage.setItem('orbitra_reports_range', JSON.stringify({ preset: p, from, to })); } catch { /* storage unavailable */ }
                            }}
                            initialPreset={savedRange?.preset || 'last7Days'}
                            selectedTimezone={timezone}
                            onTimezoneChange={setTimezone}
                        />
                    </div>
                </div>

                {/* Table Content */}
                <div className="flex-1 overflow-auto p-6" style={{ color: 'var(--color-text-primary)' }}>
                    {loading ? (
                        <div className="flex justify-center items-center h-64">
                            <div className="animate-spin rounded-full h-10 w-10 border-b-2" style={{ borderColor: 'var(--color-primary)' }}></div>
                        </div>
                    ) : (
                        <div className="page-card" style={{ padding: 0 }}>
                            {/* Horizontal scroll for wide column sets (all 65 metrics
                                used to be clipped by overflow:hidden — looked like
                                "selecting columns does nothing"). The name column
                                stays pinned while scrolling. minWidth 0 lets the
                                wrapper actually shrink inside flex ancestors;
                                pan-x keeps iOS from locking the sheet to one scroll
                                axis — horizontal drags scroll the table, vertical
                                ones pass through to the page/sheet. */}
                            <div style={{ overflowX: 'auto', minWidth: 0, touchAction: 'pan-x' }}>
                    {toggleNotice && (
                        <div className={`alert ${toggleNotice.type === 'error' ? 'alert-danger' : 'alert-success'} mb-3 flex items-center gap-2`}>
                            {toggleNotice.type === 'error' ? <X size={14} /> : <BarChart3 size={14} />}
                            {toggleNotice.text}
                        </div>
                    )}

                            <table className="page-table tracker-table" style={{ fontVariantNumeric: 'tabular-nums', ...colResize.tableStyle }}>
                                {colResize.colgroup}
                                <thead>
                                    <tr>
                                        <th
                                            className="resizable-th report-pinned-name"
                                            style={{
                                                textAlign: 'left',
                                                position: 'sticky', left: 0,
                                                /* above .tracker-table th's z-index:10 — the pinned
                                                   header must not slide under scrolling metric th's */
                                                zIndex: 11,
                                                backgroundColor: 'var(--color-bg-soft)'
                                            }}
                                        >
                                            {layerKeys.map(formatDimensionLabel).join(' → ')}
                                            <ColumnResizeHandle rt={colResize} colId="name" />
                                        </th>
                                        {chosenColumns.map((colId, colIdx) => {
                                            const def = ALL_REPORT_METRICS.find(m => m.id === colId);
                                            const isDragOver = thDragOverIdx === colIdx && thDragIdx !== null && thDragIdx !== colIdx;
                                            return (
                                                <th
                                                    key={colId}
                                                    draggable={!colResize.resizingId}
                                                    onDragStart={(e) => handleThDragStart(e, colIdx)}
                                                    onDragOver={(e) => handleThDragOver(e, colIdx)}
                                                    onDrop={(e) => handleThDrop(e, colIdx)}
                                                    onDragEnd={handleThDragEnd}
                                                    title={getReportMetricTooltip(def, t)}
                                                    className="resizable-th"
                                                    style={{
                                                        textAlign: 'center',
                                                        cursor: 'grab',
                                                        userSelect: 'none',
                                                        whiteSpace: 'nowrap',
                                                        boxShadow: isDragOver ? 'inset 2px 0 0 var(--color-primary)' : 'none',
                                                        backgroundColor: isDragOver ? 'var(--color-bg-soft)' : undefined
                                                    }}
                                                >
                                                    <div className="inline-flex items-center justify-center gap-1 w-full">
                                                        <GripVertical className="w-3 h-3 opacity-30 -ml-1" />
                                                        {STATUS_COLUMN_STATUSES[colId] && (
                                                            <span
                                                                title={def?.label || colId}
                                                                style={{
                                                                    width: '7px',
                                                                    height: '7px',
                                                                    borderRadius: '50%',
                                                                    flexShrink: 0,
                                                                    backgroundColor: resolveConversionColor(STATUS_COLUMN_STATUSES[colId], conversionTypes)
                                                                }}
                                                            />
                                                        )}
                                                        <span>{def?.shortLabel || def?.label || colId}</span>
                                                    </div>
                                                    <ColumnResizeHandle rt={colResize} colId={colId} />
                                                </th>
                                            );
                                        })}
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.length === 0 ? (
                                        <tr>
                                            <td colSpan={1 + chosenColumns.length} className="text-center p-8" style={{ color: 'var(--color-text-muted)' }}>
                                                {t('campaignReports.noDataFilters', 'No report data found for this period and grouping.')}
                                            </td>
                                        </tr>
                                    ) : (
                                        <>
                                            {/* Sticky Summary Header Row */}
                                            <tr className="text-xs" style={{ backgroundColor: 'var(--color-bg-soft)', position: 'sticky', top: 0, fontWeight: 700, borderBottom: '2px solid var(--color-border)' }}>
                                                <td className="font-bold report-pinned-name" style={{ position: 'sticky', left: 0, zIndex: 2, backgroundColor: 'var(--color-bg-soft)' }}>{t('campaignReports.total', 'Totals')}</td>
                                                {chosenColumns.map(cId => (
                                                    <td key={cId} className="cell-text" style={{ fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
                                                        {formatMetricCell(cId, grandTotal, true)}
                                                    </td>
                                                ))}
                                            </tr>

                                            {/* Hierarchical Breakdown Rows */}
                                            {displayRows.map((r, idx) => {
                                                const isSubtotal = r.depth < layers.length - 1;
                                                return (
                                                    <tr
                                                        key={idx}
                                                        className="text-xs transition-colors"
                                                        style={{
                                                            backgroundColor: isSubtotal ? 'color-mix(in srgb, var(--color-bg-soft) 40%, transparent)' : undefined
                                                        }}
                                                    >
                                                        <td className="report-pinned-name" style={{
                                                            paddingLeft: `${12 + r.depth * 20}px`,
                                                            fontWeight: isSubtotal ? 600 : 400,
                                                            color: isSubtotal ? 'var(--color-text-primary)' : 'var(--color-text-secondary)',
                                                            whiteSpace: 'nowrap',
                                                            position: 'sticky', left: 0, zIndex: 2,
                                                            backgroundColor: isSubtotal
                                                                ? 'color-mix(in srgb, var(--color-bg-soft) 55%, transparent)'
                                                                : 'var(--color-bg-card)'
                                                        }}>
                                                            <div className="inline-flex items-center gap-1.5">
                                                                {isSubtotal && <ChevronRight className="w-3 h-3 inline" style={{ color: 'var(--color-primary)' }} />}
                                                                {(() => {
                                                                    const dimKey = layers[r.depth];
                                                                    if (!ENTITY_TYPE_BY_DIMENSION[dimKey] || !r.dimId || r.dimId === 'Unknown' || r.dimId === 'none') return null;
                                                                    const entityId = String(r.dimId).trim();
                                                                    const validEntityId = /^\d+$/.test(entityId);
                                                                    const paused = entityToggleStatus(entityStatus[entityId]) === 'PAUSED';
                                                                    const busy = togglingIds.has(entityId);
                                                                    return (
                                                                        <button
                                                                            type="button"
                                                                            disabled={busy || !validEntityId}
                                                                            onClick={(e) => { e.stopPropagation(); handleToggleEntityStatus(dimKey, r); }}
                                                                            className="relative inline-flex h-4 w-7 flex-shrink-0 items-center rounded-full transition-colors"
                                                                            style={{
                                                                                background: paused || busy || !validEntityId ? 'var(--color-border)' : 'var(--color-success, #10b981)',
                                                                                opacity: busy || !validEntityId ? 0.5 : 1,
                                                                                cursor: validEntityId && !busy ? 'pointer' : 'not-allowed'
                                                                            }}
                                                                            title={validEntityId
                                                                                ? `${dimKey === 'campaign_id' ? 'Orbitra' : 'Facebook'} · ${paused ? t('automation.clickToResume') : t('automation.clickToPause')}`
                                                                                : t('automation.invalidId')}
                                                                        >
                                                                            <span className="inline-block h-3 w-3 transform rounded-full bg-white shadow transition-transform" style={{ transform: paused ? 'translateX(2px)' : 'translateX(12px)' }} />
                                                                        </button>
                                                                    );
                                                                })()}
                                                                <span className="font-semibold text-xs text-[var(--color-text-primary)]">
                                                                    {r.name === 'Direct (No Lander)'
                                                                        ? t('dimensions.directNoLander', r.name)
                                                                        : r.name === 'Default / Direct Stream'
                                                                            ? t('dimensions.defaultStream', r.name)
                                                                            : r.name}
                                                                </span>
                                                                {r.dimId && r.dimId !== '0' && r.dimId !== 'Unknown' && r.dimId !== 'none' && r.dimId !== r.name && (
                                                                    <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-[var(--color-bg-soft)] text-[var(--color-text-muted)] border border-[var(--color-border)]">
                                                                        #{r.dimId}
                                                                    </span>
                                                                )}
                                                                {(() => {
                                                                    // Effective-status badge: the two states the
                                                                    // toggle itself cannot show — an ad rejected by
                                                                    // moderation or still waiting for it.
                                                                    const eff = entityEffectiveStatus(entityStatus[String(r.dimId ?? '').trim()]);
                                                                    if (eff !== 'DISAPPROVED' && eff !== 'PENDING_REVIEW') return null;
                                                                    const disapproved = eff === 'DISAPPROVED';
                                                                    const color = disapproved ? 'var(--color-danger)' : 'var(--color-warning, #f59e0b)';
                                                                    return (
                                                                        <span
                                                                            className="text-[10px] px-1.5 py-0.5 rounded font-semibold border whitespace-nowrap"
                                                                            style={{
                                                                                color: color,
                                                                                borderColor: `color-mix(in srgb, ${color} 35%, transparent)`,
                                                                                backgroundColor: `color-mix(in srgb, ${color} 10%, transparent)`
                                                                            }}
                                                                            title={eff}
                                                                        >
                                                                            {disapproved
                                                                                ? t('campaignReports.statusDisapproved', 'Disapproved')
                                                                                : t('campaignReports.statusInReview', 'In review')}
                                                                        </span>
                                                                    );
                                                                })()}
                                                                {isSubtotal && r.childrenCount > 0 && (
                                                                    <span style={{ color: 'var(--color-text-muted)', fontSize: '11px' }}>({r.childrenCount})</span>
                                                                )}
                                                            </div>
                                                        </td>
                                                        {chosenColumns.map(cId => (
                                                            <td key={cId} className="cell-text" style={{ fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
                                                                {formatMetricCell(cId, r, isSubtotal)}
                                                            </td>
                                                        ))}
                                                    </tr>
                                                );
                                            })}
                                        </>
                                    )}
                                </tbody>
                            </table>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* Columns, GroupBy & Filter Customizer Modal */}
            <ReportCustomizerModal
                isOpen={customizerOpen}
                onClose={() => setCustomizerOpen(false)}
                selectedColumns={chosenColumns}
                onSaveColumns={handleSaveColumns}
                mode="report"
                currentLayers={layers}
                onSaveLayers={handleSaveLayers}
                layerPresets={REPORT_LAYER_PRESETS}
                currentFilters={filters}
                onSaveFilters={handleSaveFilters}
                onResetColumnWidths={colResize.api.resetAll}
            />
        </div>
    );
};

export default CampaignReports;
