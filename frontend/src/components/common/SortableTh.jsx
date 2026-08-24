import React from 'react';
import { ChevronUp, ChevronDown, ChevronsUpDown, GripVertical } from 'lucide-react';
import { ColumnResizeHandle } from './ColumnResize';

// One sortable table header, shared by Campaigns, Offers and Landings.
//
// Campaigns.jsx and Offers.jsx each carried a byte-identical private copy of this,
// and Landings.jsx had none at all — which is why its headers were plain <th> and
// could not be sorted. One implementation, three call sites.

// Module scope on purpose: a component defined in the render body is a new
// type on every render, so React remounts its DOM — and a remounted <th>
// cancels a column drag in flight (the dragover highlight re-renders it).
export const SortIcon = ({ sortBy, colKey }) => {
    if (sortBy.key !== colKey) return <ChevronsUpDown className="w-3.5 h-3.5 opacity-40" />;
    return sortBy.dir === 'asc'
        ? <ChevronUp className="w-3.5 h-3.5" style={{ color: 'var(--color-primary)' }} />
        : <ChevronDown className="w-3.5 h-3.5" style={{ color: 'var(--color-primary)' }} />;
};

// The drag source is the GRIP, not the <th>: a native drag never starts on
// an interactive descendant, so a grip inside the sort <button> was dead.
// The <th> itself stays the drop target (highlight + onDrop).
// `resize` (the shared column-resize controller) mounts a resize handle on
// the header's right edge and suspends the reorder grip while a resize drag
// is in flight, so both gestures coexist on the same header.
export const SortableTh = ({ colKey, label, fullTitle, defaultDir = 'asc', alignRight = false, draggable = false, isDragOver = false, sortBy, requestSort, onDragStart, onDragOver, onDrop, onDragEnd, resize }) => {
    const isActive = sortBy.key === colKey;
    return (
        <th
            className={`${alignRight ? 'text-right' : 'text-left'} whitespace-nowrap transition-all resizable-th`}
            aria-sort={isActive ? (sortBy.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
            title={fullTitle}
            onDragOver={onDragOver}
            onDrop={onDrop}
            onDragEnd={onDragEnd}
            style={{
                textAlign: alignRight ? 'right' : 'left',
                userSelect: 'none',
                boxShadow: isDragOver ? 'inset 2px 0 0 var(--color-primary)' : 'none',
                backgroundColor: isDragOver ? 'var(--color-bg-soft)' : undefined
            }}
        >
            <div className={`inline-flex items-center gap-1.5 ${alignRight ? 'justify-end w-full' : ''}`}>
                {draggable && (
                    <span
                        draggable={!resize?.resizingId}
                        onDragStart={onDragStart}
                        className="cursor-grab active:cursor-grabbing flex-shrink-0 -ml-1"
                    >
                        <GripVertical className="w-3 h-3 opacity-25 hover:opacity-75" />
                    </span>
                )}
                <button
                    type="button"
                    onClick={() => requestSort(colKey, defaultDir)}
                    className="inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap cursor-pointer"
                    style={{
                        color: isActive ? 'var(--color-primary)' : 'var(--color-text-secondary)',
                        textAlign: alignRight ? 'right' : 'left'
                    }}
                >
                    <span>{label}</span>
                    <SortIcon sortBy={sortBy} colKey={colKey} />
                </button>
            </div>
            {resize && <ColumnResizeHandle rt={resize} colId={colKey} />}
        </th>
    );
};


/**
 * Metric ids whose values sort numerically. Anything not listed sorts as text,
 * which is correct for names, states, URLs and country codes.
 */
export const NUMERIC_METRIC_KEYS = new Set([
    'id', 'payout_value', 'clicks', 'unique_clicks', 'visits', 'unique_visits',
    'lp_clicks', 'lp_ctr', 'conversions', 'leads', 'sales', 'rejected', 'trash',
    'approve_rate', 'revenue', 'revenue_confirmed', 'cost', 'cr', 'epc', 'epc_confirmed',
    'epv', 'cpc', 'cpv', 'profit', 'profit_confirmed', 'roi', 'roi_confirmed',
    // All derived metrics from orbitraComputeDerivedMetrics
    'cpa', 'cpl', 'cps', 'cpr', 'cpd', 'cr_sales', 'cr_holds', 'cr_leads', 'cr_registrations',
    'cr_deposits', 'registrations', 'deposits', 'uc_rate', 'bot_rate', 'uepc', 'uepc_confirmed',
    'epc_hold', 'uepc_hold', 'epc_registration', 'uepc_registration', 'ucpc', 'ecpm_all', 'ecpm_confirmed',
    'earnings_per_conv', 'ec_confirmed', 'revenue_hold', 'revenue_rejected', 'revenue_trash',
    'revenue_registration', 'revenue_deposit', 'real_revenue', 'real_profit', 'real_roi',
    'bots', 'proxies', 'empty_referrers', 'unique_clicks_stream', 'unique_clicks_global',
]);

/**
 * Sort rows by `sortBy` ({ key, dir }), stably. A null key keeps the API's own order.
 */
export const sortRows = (rows, sortBy) => {
    if (!sortBy?.key) return rows;
    const dirMul = sortBy.dir === 'asc' ? 1 : -1;
    const isNumeric = NUMERIC_METRIC_KEYS.has(sortBy.key);

    const getVal = (row) => {
        const val = row[sortBy.key];
        if (val === null || val === undefined) return '';
        if (typeof val === 'number') return val;
        return String(val);
    };

    return rows
        .map((row, idx) => ({ row, idx }))
        .sort((a, b) => {
            const av = getVal(a.row);
            const bv = getVal(b.row);
            const cmp = isNumeric
                ? (Number(av) || 0) - (Number(bv) || 0)
                : String(av).localeCompare(String(bv), undefined, { sensitivity: 'base' });
            if (cmp !== 0) return cmp * dirMul;
            return a.idx - b.idx; // stable
        })
        .map(x => x.row);
};

/**
 * The shared toggle: same column flips direction, a new column starts at its
 * natural direction.
 */
export const nextSortState = (prev, key, defaultDir = 'asc') => (
    prev.key === key
        ? { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' }
        : { key, dir: defaultDir }
);
