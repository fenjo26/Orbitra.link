import React, { useState, useMemo, useEffect, useCallback } from 'react';

// Shared resizable-table-column machinery. One implementation, used by every
// list table (Campaigns, Landings, Offers, ConversionsLog, the traffic click
// log and the report view) so resizing behaves identically everywhere.
//
// Layout model: the table gets `table-layout: fixed` plus a <colgroup> whose
// widths come from state — without fixed layout the browser re-sizes columns
// from content and dragging feels dead. Widths are keyed by column ID, never
// by index: users reorder and toggle columns, and an index-keyed width would
// land on the wrong column after either.

export const MIN_COL_WIDTH = 60;
// A stored width always beats a code default, so when the default geometry
// changes (tableColumns.js, ColumnResize floors) the stored maps must not
// outlive it — otherwise every table stays pinned to the old layout until the
// user happens to press Reset. Bump GEOMETRY_VERSION to orphan every stored
// map at once; the previous version's key is swept on next load.
const GEOMETRY_VERSION = 2;
const STORAGE_PREFIX = `orbitra_colwidths_v${GEOMETRY_VERSION}_`;
const LEGACY_STORAGE_PREFIX = 'orbitra_colwidths_';
const DEFAULT_COL_WIDTH = 120;

// The list tables are replaced by MobileCards below the Tailwind `lg`
// breakpoint — resizing is skipped entirely there (no handles, no stored
// widths applied), so a phone visit never fights the card layout.
export function useIsDesktop() {
    const [isDesktop, setIsDesktop] = useState(() => {
        if (typeof window === 'undefined' || !window.matchMedia) return true;
        return window.matchMedia('(min-width: 1024px)').matches;
    });

    useEffect(() => {
        if (typeof window === 'undefined' || !window.matchMedia) return undefined;
        const mql = window.matchMedia('(min-width: 1024px)');
        const onChange = (e) => setIsDesktop(e.matches);
        mql.addEventListener('change', onChange);
        return () => mql.removeEventListener('change', onChange);
    }, []);

    return isDesktop;
}

const readStoredWidths = (storageKey) => {
    try {
        const parsed = JSON.parse(localStorage.getItem(storageKey) || '{}');
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return {};
        const clean = {};
        for (const [id, w] of Object.entries(parsed)) {
            // Unknown/removed column ids are simply never asked for by the
            // tables; invalid values (hand-edited, older shape) are dropped so
            // the column falls back to its default width.
            if (typeof w === 'number' && Number.isFinite(w) && w >= MIN_COL_WIDTH) {
                clean[id] = Math.round(w);
            }
        }
        return clean;
    } catch {
        return {};
    }
};

// One localStorage key per table (orbitra_colwidths_v<N>_<tableId>) holding
// {columnId: px} for the columns the USER resized. Columns never resized (or
// added later) keep their code-defined default width.
export function useColumnWidths(tableId) {
    const storageKey = STORAGE_PREFIX + tableId;
    const [widths, setWidths] = useState(() => {
        // Sweep the pre-geometry map so a stale width cannot resurface from
        // an old key after the version bump.
        try {
            localStorage.removeItem(LEGACY_STORAGE_PREFIX + tableId);
        } catch {
            // Private mode / quota — nothing was readable, nothing to sweep.
        }
        return readStoredWidths(storageKey);
    });

    const setWidth = useCallback((colId, px) => {
        setWidths(prev => {
            const next = { ...prev, [colId]: Math.max(MIN_COL_WIDTH, Math.round(px)) };
            try {
                localStorage.setItem(storageKey, JSON.stringify(next));
            } catch {
                // Private mode / quota — the in-memory copy still works this session.
            }
            return next;
        });
    }, [storageKey]);

    const resetColumn = useCallback((colId) => {
        setWidths(prev => {
            if (!(colId in prev)) return prev;
            const next = { ...prev };
            delete next[colId];
            try {
                localStorage.setItem(storageKey, JSON.stringify(next));
            } catch {
                // See setWidth.
            }
            return next;
        });
    }, [storageKey]);

    const resetAll = useCallback(() => {
        setWidths({});
        try {
            localStorage.removeItem(storageKey);
        } catch {
            // See setWidth.
        }
    }, [storageKey]);

    return { widths, setWidth, resetColumn, resetAll, storageKey };
}

// Per-table controller. `columns` is the ordered column list in render order:
// [{ id, width }] where `width` is the default. Returns the <colgroup> and the
// table style to spread, plus the drag-state gate pages use to suspend header
// drag-to-reorder while a resize is in flight.
export function useResizableTableColumns({ tableId, columns, enabled = true }) {
    const api = useColumnWidths(tableId);
    const [resizingId, setResizingId] = useState(null);

    const resolved = useMemo(() => {
        const byId = {};
        let total = 0;
        for (const col of columns) {
            const stored = api.widths[col.id];
            // MIN applies to stored (user-touched) widths only — fixed narrow
            // columns like the checkbox (40px) are never resizable anyway.
            const w = stored != null
                ? Math.max(MIN_COL_WIDTH, Math.round(stored))
                : Math.round(col.width ?? DEFAULT_COL_WIDTH);
            byId[col.id] = w;
            total += w;
        }
        return { byId, total };
    }, [columns, api.widths]);

    const beginResize = useCallback((colId) => setResizingId(colId), []);
    const endResize = useCallback(() => setResizingId(null), []);

    const colgroup = enabled ? (
        <colgroup>
            {columns.map(col => (
                <col key={col.id} style={{ width: resolved.byId[col.id] }} />
            ))}
        </colgroup>
    ) : null;

    const tableStyle = enabled
        ? { tableLayout: 'fixed', width: resolved.total, minWidth: '100%' }
        : null;

    return {
        api,
        enabled,
        resizingId,
        beginResize,
        endResize,
        widthOf: resolved.byId,
        colgroup,
        tableStyle
    };
}


// --- content measurement -------------------------------------------------
//
// The narrowest a column may be dragged is the width its own content needs.
// Without a floor a column collapses to MIN_COL_WIDTH and its heading is cut
// mid-word - and `.tracker-table th` CLIPS rather than ellipses (see
// index.css: text-overflow cannot shorten a flex row of grip + label + icon),
// so "Leads" becomes "eads" and reads as a typo, not as truncation.

const RULER_ID = 'orbitra-col-ruler';

const getRuler = () => {
    let ruler = document.getElementById(RULER_ID);
    if (!ruler) {
        ruler = document.createElement('div');
        ruler.id = RULER_ID;
        ruler.setAttribute('aria-hidden', 'true');
        document.body.appendChild(ruler);
    }
    // Off-screen rather than display:none - a hidden element has no layout and
    // measures 0.
    ruler.style.cssText = 'position:absolute;left:-99999px;top:0;visibility:hidden;pointer-events:none;';
    return ruler;
};

/**
 * Width, in px, that column `colId` needs to show its widest cell in full.
 *
 * Measured from detached clones in a ruler table that carries the SAME classes
 * as the real one, so `.tracker-table` padding and font sizes apply - measuring
 * a bare clone returns a number for a cell that does not exist.
 *
 * Only the resize handle is stripped, because it is absolutely positioned
 * furniture that occupies no column width. Icons and the drag grip STAY: they
 * are always laid out here (hover only changes their opacity, never their
 * box), so a clone measures exactly what the column shows. Fade furniture in
 * and out, never collapse it to `width: 0` - a clone parked off-screen has no
 * hover, so it would report the expanded width and floor the column at a size
 * the user can see no reason for and can never drag back past.
 *
 * Cells are matched by `data-col`, never by `nth-child`: columns reorder.
 */
export function measureColumnContent(tableEl, colId) {
    if (!tableEl || !colId || typeof document === 'undefined') return 0;
    let cells;
    try {
        cells = tableEl.querySelectorAll(`[data-col="${CSS.escape(String(colId))}"]`);
    } catch {
        return 0;
    }
    if (!cells.length) return 0;

    const ruler = getRuler();
    ruler.textContent = '';
    const rulerTable = document.createElement('table');
    rulerTable.className = tableEl.className;
    // auto layout and no minimum: the clone must be free to report the width it
    // WANTS, which is the whole measurement.
    rulerTable.style.cssText = 'table-layout:auto;width:auto;min-width:0;position:static;';
    const body = document.createElement('tbody');
    rulerTable.appendChild(body);
    ruler.appendChild(rulerTable);

    const clones = [];
    cells.forEach((cell) => {
        const row = document.createElement('tr');
        const clone = cell.cloneNode(true);
        clone.querySelectorAll('.col-resize-handle').forEach(el => el.remove());
        clone.style.width = 'auto';
        clone.style.minWidth = '0';
        clone.style.maxWidth = 'none';
        clone.style.overflow = 'visible';
        clone.style.textOverflow = 'clip';
        clone.style.position = 'static';
        row.appendChild(clone);
        body.appendChild(row);
        clones.push(clone);
    });

    // One layout pass for all of them, then read.
    let widest = 0;
    for (const clone of clones) {
        widest = Math.max(widest, Math.ceil(clone.getBoundingClientRect().width));
    }
    ruler.textContent = '';
    return widest;
}
