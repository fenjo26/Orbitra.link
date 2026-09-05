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
    if (sortBy.key !== colKey) return <ChevronsUpDown className="w-3 h-3 opacity-40" />;
    return sortBy.dir === 'asc'
        ? <ChevronUp className="w-3 h-3" style={{ color: 'var(--color-primary)' }} />
        : <ChevronDown className="w-3 h-3" style={{ color: 'var(--color-primary)' }} />;
};

// The drag source is the GRIP, not the <th>: a native drag never starts on
// an interactive descendant, so a grip inside the sort <button> was dead.
// The <th> itself stays the drop target (highlight + onDrop).
// `resize` (the shared column-resize controller) mounts a resize handle on
// the header's right edge and suspends the reorder grip while a resize drag
// is in flight, so both gestures coexist on the same header.
// `sortable={false}` renders grip + label + resize for columns with nothing
// to sort by (Actions) — they still reorder and resize like everything else.
// Header labels centre within their column: with user-resizable widths a
// label hugging the left/right edge of a wide cell reads crooked.
export const SortableTh = ({ colKey, label, fullTitle, defaultDir = 'asc', draggable = false, isDragOver = false, sortBy, requestSort, onDragStart, onDragOver, onDrop, onDragEnd, resize, hideSortIcon = false, sortable = true, className = '', style, ...rest }) => {
    const isActive = sortBy.key === colKey;
    const startColumnDrag = (e) => {
        if (onDragStart) onDragStart(e);
        // Compact drag image: the browser's default ghost is a snapshot of
        // the whole (wide) header at partial opacity, floating over the
        // table and making every header it passes unreadable. The opaque
        // chip (.col-drag-ghost, index.css) only names the column.
        try {
            let ghost = document.getElementById('orbitra-col-drag-ghost');
            if (!ghost) {
                ghost = document.createElement('div');
                ghost.id = 'orbitra-col-drag-ghost';
                ghost.className = 'col-drag-ghost';
                document.body.appendChild(ghost);
            }
            ghost.textContent = label;
            e.dataTransfer.setDragImage(ghost, 24, 14);
        } catch {
            // setDragImage is best-effort; the native ghost still drags.
        }
    };
    return (
        <th
            // ColRow clones `data-col` and the alignment class in from the
            // column list that feeds the <colgroup>; both must reach the DOM,
            // because the CSS and the resize floor key off them.
            {...rest}
            className={`whitespace-nowrap transition-all resizable-th ${className}`.trim()}
            aria-sort={isActive ? (sortBy.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
            title={fullTitle}
            onDragOver={onDragOver}
            onDrop={onDrop}
            onDragEnd={onDragEnd}
            style={{
                /* Caller styles (the pinned-column insets) merge under the
                   drag highlight, which always wins while it is up. */
                ...style,
                userSelect: 'none',
                /* Opaque drop target: a washed-out highlight under the
                   floating ghost made the two headers unreadable together. */
                boxShadow: isDragOver ? 'inset 2px 0 0 var(--color-primary)' : 'none',
                backgroundColor: isDragOver ? 'var(--color-bg-card)' : undefined
            }}
        >
            {/* Justification is set in index.css from the column's alignment
                class, so the header follows its column instead of every table
                hard-coding centre. */}
            <div className="th-inner inline-flex items-center gap-1 w-full min-w-0">
                {draggable && (
                    <span
                        draggable={!resize?.resizingId}
                        onDragStart={startColumnDrag}
                        // col-grip: invisible until the header is hovered
                        // (index.css). It keeps its 12px box at rest, so
                        // nothing shifts when it appears and the resize floor
                        // measures the same width the column really uses.
                        className="col-grip cursor-grab active:cursor-grabbing flex-shrink-0 -ml-1"
                    >
                        <GripVertical className="w-3 h-3" />
                    </span>
                )}
                {sortable ? (
                    <button
                        type="button"
                        onClick={() => requestSort(colKey, defaultDir)}
                        className="inline-flex items-center gap-1 text-[10px] font-semibold whitespace-nowrap cursor-pointer min-w-0 max-w-full"
                        style={{
                            color: isActive ? 'var(--color-primary)' : 'var(--color-text-secondary)'
                        }}
                    >
                        {/* truncate: the label stays inside its own cell —
                            clipping belongs to the header, not the table. */}
                        <span className="truncate">{label}</span>
                        {/* hideSortIcon: the column still sorts on click, but rows
                        nobody re-orders don't need the affordance shouting. */}
                        {!hideSortIcon && <SortIcon sortBy={sortBy} colKey={colKey} />}
                    </button>
                ) : (
                    <span
                        className="inline-flex items-center gap-1 text-[10px] font-semibold whitespace-nowrap min-w-0 max-w-full"
                        style={{ color: 'var(--color-text-secondary)' }}
                    >
                        <span className="truncate">{label}</span>
                    </span>
                )}
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
