import React, { useState, useMemo, useRef, useEffect, useCallback } from 'react';
import { useLanguage } from '../../contexts/LanguageContext';
import { colAlignClass } from './tableColumns';

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
const STORAGE_PREFIX = 'orbitra_colwidths_';
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

// One localStorage key per table (orbitra_colwidths_<tableId>) holding
// {columnId: px} for the columns the USER resized. Columns never resized (or
// added later) keep their code-defined default width.
export function useColumnWidths(tableId) {
    const storageKey = STORAGE_PREFIX + tableId;
    const [widths, setWidths] = useState(() => readStoredWidths(storageKey));

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

/**
 * A table row that stamps `data-col` and the alignment class onto its cells,
 * by position, from the same ordered column list that feeds the <colgroup>.
 *
 * Doing it here instead of on forty individual <th>/<td> tags is what keeps the
 * two in step. The colgroup maps <col> positionally, so a row whose cells drift
 * out of the column list draws every column after the mismatch at a
 * neighbour's width, with no error anywhere. This wrapper cannot drift, and it
 * says so out loud in development when the counts disagree.
 *
 * `columns` takes either the columnDefs objects ({ id, width }) or bare ids.
 */
export const ColRow = ({ columns, children, ...rest }) => {
    const cells = React.Children.toArray(children);
    const idOf = (c) => (c && typeof c === 'object' ? c.id : c);
    if (import.meta.env?.DEV && cells.length !== columns.length) {
        console.error(
            '[tracker-table] row rendered %d cells for %d columns (%s) - data-col and the colgroup are now out of step',
            cells.length, columns.length, columns.map(idOf).join(',')
        );
    }
    return (
        <tr {...rest}>
            {cells.map((cell, i) => {
                const id = idOf(columns[i]);
                if (id == null || !React.isValidElement(cell)) return cell;
                const className = [cell.props.className, colAlignClass(id)].filter(Boolean).join(' ');
                return React.cloneElement(cell, { 'data-col': id, className });
            })}
        </tr>
    );
};

// The grab handle on a <th>'s right edge. Must be rendered inside its <th>
// (it walks up to the th/col/table at pointerdown), and every mouse/touch
// event it handles is stopped so the parent header's drag-to-reorder and
// sort click never fire from a resize.
export const ColumnResizeHandle = ({ rt, colId }) => {
    const { t } = useLanguage();
    const [liveWidth, setLiveWidth] = useState(null);
    const dragRef = useRef(null);

    const handlePointerDown = (e) => {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        const th = e.currentTarget.closest('th');
        const table = th?.closest('table');
        const colEl = table?.querySelector('colgroup')?.children?.[th.cellIndex];
        if (!th || !table || !colEl) return;

        e.preventDefault();
        e.stopPropagation();

        // The width this column actually owns, read off its <col> — not the
        // header's rendered box. `tableStyle` carries `minWidth: 100%`, so
        // whenever the columns sum to less than the container the browser
        // stretches them and every rendered box is wider than its assigned
        // width. Starting from the rendered box made the first drag commit that
        // stretch as a real width for the column it touched: the column jumped
        // wider the moment it was grabbed, the stretch it stole never came back,
        // and repeating the gesture ratcheted it wider again.
        const assignedW = parseFloat(colEl.style.width);
        const startW = Number.isFinite(assignedW) && assignedW > 0
            ? assignedW
            : th.getBoundingClientRect().width;
        // Measured once, at grab time: the DOM does not change under the
        // drag, and re-measuring per pointermove would be a layout pass per
        // pixel.
        const minW = Math.max(MIN_COL_WIDTH, measureColumnContent(table, colId));

        dragRef.current = {
            pointerId: e.pointerId,
            startX: e.clientX,
            startW,
            minW,
            startTableW: parseFloat(table.style.width) || startW,
            colEl,
            tableEl: table,
            lastW: null,
            moved: false
        };
        try {
            e.currentTarget.setPointerCapture(e.pointerId);
        } catch {
            // Capture is best-effort; the move/up handlers no-op without it.
        }
        e.currentTarget.classList.add('is-dragging');
        document.body.classList.add('col-resizing');
        rt.beginResize(colId);
        setLiveWidth(Math.round(startW));
    };

    const handlePointerMove = (e) => {
        const d = dragRef.current;
        if (!d || e.pointerId !== d.pointerId) return;
        e.stopPropagation();

        const w = Math.max(d.minW ?? MIN_COL_WIDTH, Math.round(d.startW + (e.clientX - d.startX)));
        if (w === d.lastW) return;
        d.lastW = w;
        d.moved = true;

        // Widths go straight to the DOM during the drag — a state-driven
        // re-render per pointermove would rebuild the row list mid-gesture.
        // State (and localStorage) only catch up on pointerup.
        d.colEl.style.width = `${w}px`;
        d.tableEl.style.width = `${Math.round(d.startTableW + (w - d.startW))}px`;
        setLiveWidth(w);
    };

    const endDrag = (e, { commit = false, revert = false } = {}) => {
        const d = dragRef.current;
        if (!d || (e && e.pointerId !== undefined && e.pointerId !== d.pointerId)) return;
        dragRef.current = null;

        if (e) {
            e.stopPropagation();
            try {
                e.currentTarget.releasePointerCapture(d.pointerId);
            } catch {
                // Already released.
            }
        }
        e?.currentTarget?.classList?.remove?.('is-dragging');
        document.body.classList.remove('col-resizing');

        if (revert) {
            d.colEl.style.width = `${Math.round(d.startW)}px`;
            d.tableEl.style.width = `${Math.round(d.startTableW)}px`;
        }

        rt.endResize();
        setLiveWidth(null);
        if (commit && d.moved) {
            rt.api.setWidth(colId, d.lastW ?? d.startW);
        }
    };

    if (!rt || !rt.enabled) return null;

    return (
        <span
            className="col-resize-handle"
            title={t('common.colResizeHint', 'Drag to resize · double-click to reset')}
            onPointerDown={handlePointerDown}
            onPointerMove={handlePointerMove}
            onPointerUp={(e) => endDrag(e, { commit: true })}
            onPointerCancel={(e) => endDrag(e, { revert: true })}
            // Chrome does not guarantee pointerup before lostpointercapture on
            // release. When lostpointercapture wins the race, a reverting handler
            // here snapped the column back, nulled dragRef, and the pointerup that
            // followed returned at the guard — so setWidth() was never reached and
            // no resize ever persisted. Commit from whichever fires first; the
            // `d.moved` guard inside endDrag still rejects genuine orphaned drags,
            // and the second call is a no-op because dragRef is already null.
            onLostPointerCapture={(e) => endDrag(e, { commit: true })}
            onDoubleClick={(e) => {
                e.preventDefault();
                e.stopPropagation();
                rt.api.resetColumn(colId);
            }}
            onDragStart={(e) => {
                e.preventDefault();
                e.stopPropagation();
            }}
            onMouseDown={(e) => e.stopPropagation()}
            onClick={(e) => e.stopPropagation()}
        >
            {liveWidth !== null && (
                <span className="col-resize-badge">{liveWidth} px</span>
            )}
        </span>
    );
};
