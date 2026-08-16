import React, { useState, useEffect, useMemo } from 'react';
import axios from 'axios';
import { X, Download, Filter, BarChart3, Plus, Trash2, SlidersHorizontal, GripVertical, ChevronRight } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import DateRangePicker, { formatDate, getPresetDates } from './DateRangePicker';
import ReportCustomizerModal, { ALL_REPORT_METRICS, PRESETS } from './ReportCustomizerModal';

const API_URL = '/api.php';

const CampaignReports = ({ campaignId, campaignName, onClose }) => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [rows, setRows] = useState([]);
    const [layerKeys, setLayerKeys] = useState([]);

    // A flat, single-dimension report by default — the multi-level drill-down is
    // an opt-in via the layer builder. Starting layered made every report look
    // like duplicated subtotal rows.
    const defaultLayers = ['country'];
    const [layers, setLayers] = useState(defaultLayers);
    const [filters, setFilters] = useState([]);

    // Date & Timezone Picker
    const todayPreset = getPresetDates('last7Days') || getPresetDates('today');
    const [dateFrom, setDateFrom] = useState(todayPreset?.from || formatDate(new Date()));
    const [dateTo, setDateTo] = useState(todayPreset?.to || formatDate(new Date()));
    const [timezone, setTimezone] = useState(() => localStorage.getItem('orbitra_tz') || 'UTC');

    // Column customizer state
    const [customizerOpen, setCustomizerOpen] = useState(false);
    const [chosenColumns, setChosenColumns] = useState(() => {
        try {
            const saved = localStorage.getItem('orbitra_report_columns');
            if (saved) return JSON.parse(saved);
        } catch (e) {}
        return [...PRESETS.best];
    });

    // Drag-and-drop column state
    const [thDragIdx, setThDragIdx] = useState(null);

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
        localStorage.setItem('orbitra_report_columns', JSON.stringify(copy));
    };

    const handleThDragEnd = () => {
        setThDragIdx(null);
    };

    const handleSaveColumns = (cols) => {
        setChosenColumns(cols);
        localStorage.setItem('orbitra_report_columns', JSON.stringify(cols));
    };

    const handleSaveLayers = (newLayers) => {
        setLayers(newLayers.length > 0 ? newLayers : ['country']);
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
            const tz = localStorage.getItem('orbitra_tz');
            if (tz) params.timezone = tz;
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
    }, [campaignId, layers.join(','), dateFrom, dateTo, JSON.stringify(filters)]);

    // Build the hierarchical tree from flat rows, then flatten into display rows
    const displayRows = useMemo(() => {
        const createEmptyAgg = () => ({
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
        });

        const addRow = (node, row) => {
            node.clicks += Number(row.clicks) || 0;
            node.unique_clicks += Number(row.unique_clicks) || 0;
            node.prelander_clicks += Number(row.prelander_clicks) || 0;
            node.offer_clicks += Number(row.offer_clicks) || 0;
            node.conversions += Number(row.conversions) || 0;
            node.purchases += Number(row.purchases) || 0;
            node.holds += Number(row.holds) || 0;
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
            node.uc_rate = node.clicks > 0 ? (node.unique_clicks / node.clicks) * 100 : 0;
            node.lp_ctr = node.clicks > 0 ? (node.offer_clicks / node.clicks) * 100 : 0;
            node.cr = node.clicks > 0 ? (node.conversions / node.clicks) * 100 : 0;
            node.cr_sales = node.clicks > 0 ? (node.purchases / node.clicks) * 100 : 0;
            node.cr_holds = node.clicks > 0 ? (node.holds / node.clicks) * 100 : 0;
            node.approve_rate = node.conversions > 0 ? (node.purchases / node.conversions) * 100 : 0;
            const nonTrash = node.purchases + node.holds + node.rejected;
            node.approve_rate_excl_trash = nonTrash > 0 ? (node.purchases / nonTrash) * 100 : 0;
            node.roi = node.cost > 0 ? (node.profit / node.cost) * 100 : 0;
            node.real_roi = node.cost > 0 ? (node.real_profit / node.cost) * 100 : 0;
            node.epc = node.clicks > 0 ? node.revenue / node.clicks : 0;
            node.uepc = node.unique_clicks > 0 ? node.revenue / node.unique_clicks : 0;
            node.cpc = node.clicks > 0 ? node.cost / node.clicks : 0;
            node.ucpc = node.unique_clicks > 0 ? node.cost / node.unique_clicks : 0;
            node.cpa = node.conversions > 0 ? node.cost / node.conversions : 0;
            node.earnings_per_conv = node.conversions > 0 ? node.revenue / node.conversions : 0;
        };

        const root = { ...createEmptyAgg(), children: new Map() };
        rows.forEach(row => {
            let node = root;
            addRow(root, row);
            const dims = row.dims || [];
            dims.forEach((dimValue) => {
                const key = dimValue !== undefined && dimValue !== null && dimValue !== '' ? String(dimValue) : 'none';
                if (!node.children.has(key)) {
                    node.children.set(key, { ...createEmptyAgg(), children: new Map() });
                }
                node = node.children.get(key);
                addRow(node, row);
            });
        });

        const out = [];
        const walk = (node, depth, name) => {
            computeDerived(node);
            const children = [...node.children.entries()].sort((a, b) => b[1].clicks - a[1].clicks);
            out.push({
                name,
                depth,
                subtotal: depth < layers.length - 1 || children.length > 0,
                ...node,
                childrenCount: children.length
            });
            children.forEach(([childName, child]) => walk(child, depth + 1, childName));
        };

        [...root.children.entries()].sort((a, b) => b[1].clicks - a[1].clicks).forEach(([name, child]) => walk(child, 0, name));
        return out;
    }, [rows, layers.length]);

    const grandTotal = useMemo(() => {
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

        rows.forEach(r => {
            t0.clicks += Number(r.clicks) || 0;
            t0.unique_clicks += Number(r.unique_clicks) || 0;
            t0.prelander_clicks += Number(r.prelander_clicks) || 0;
            t0.offer_clicks += Number(r.offer_clicks) || 0;
            t0.conversions += Number(r.conversions) || 0;
            t0.purchases += Number(r.purchases) || 0;
            t0.holds += Number(r.holds) || 0;
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
                    <span style={{ color: isPos ? 'var(--color-success)' : 'var(--color-danger)', fontWeight: strong ? 700 : 600 }}>
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
                    <span style={{ color: isPos ? 'var(--color-success)' : 'var(--color-danger)', fontWeight: strong ? 700 : 600 }}>
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

    const exportToCSV = () => {
        if (!displayRows.length) return;
        const headers = [
            layerKeys.join(' > '),
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
                    return typeof v === 'number' ? v.toFixed(2) : String(v || '');
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
        <div className="fixed top-[88px] left-0 right-0 bottom-0 z-[1100] flex bg-black/60 backdrop-blur-sm">
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
                                <span>{lName}</span>
                            </span>
                        ))}
                    </div>

                    {/* Right Side: DateRangePicker */}
                    <div className="flex items-center gap-2">
                        <DateRangePicker
                            dateFrom={dateFrom}
                            dateTo={dateTo}
                            onChange={(from, to) => {
                                setDateFrom(from);
                                setDateTo(to);
                            }}
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
                            {/* Horizontal scroll for wide column sets (all 64 metrics
                                used to be clipped by overflow:hidden — looked like
                                "selecting columns does nothing"). The name column
                                stays pinned while scrolling. */}
                            <div style={{ overflowX: 'auto' }}>
                            <table className="page-table" style={{ fontVariantNumeric: 'tabular-nums', minWidth: '100%', width: 'max-content' }}>
                                <thead>
                                    <tr>
                                        <th style={{
                                            minWidth: '240px', textAlign: 'left',
                                            position: 'sticky', left: 0, zIndex: 3,
                                            backgroundColor: 'var(--color-bg-card)'
                                        }}>
                                            {layerKeys.join(' → ')}
                                        </th>
                                        {chosenColumns.map((colId, colIdx) => {
                                            const def = ALL_REPORT_METRICS.find(m => m.id === colId);
                                            const label = def?.label || colId;
                                            return (
                                                <th
                                                    key={colId}
                                                    draggable
                                                    onDragStart={() => handleThDragStart(colIdx)}
                                                    onDragOver={(e) => handleThDragOver(e, colIdx)}
                                                    onDragEnd={handleThDragEnd}
                                                    style={{
                                                        textAlign: 'right',
                                                        cursor: 'grab',
                                                        userSelect: 'none',
                                                        whiteSpace: 'nowrap'
                                                    }}
                                                >
                                                    <div className="inline-flex items-center justify-end gap-1 w-full">
                                                        <GripVertical className="w-3 h-3 opacity-30 -ml-1" />
                                                        <span>{label}</span>
                                                    </div>
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
                                                <td className="font-bold" style={{ position: 'sticky', left: 0, zIndex: 2, backgroundColor: 'var(--color-bg-soft)' }}>{t('campaignReports.total', 'Totals')}</td>
                                                {chosenColumns.map(cId => (
                                                    <td key={cId} style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
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
                                                        <td style={{
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
                                                                <span>{r.name}</span>
                                                                {isSubtotal && r.childrenCount > 0 && (
                                                                    <span style={{ color: 'var(--color-text-muted)', fontSize: '11px' }}>({r.childrenCount})</span>
                                                                )}
                                                            </div>
                                                        </td>
                                                        {chosenColumns.map(cId => (
                                                            <td key={cId} style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
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
                currentFilters={filters}
                onSaveFilters={handleSaveFilters}
            />
        </div>
    );
};

export default CampaignReports;
