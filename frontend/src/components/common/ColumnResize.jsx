import React, { useState, useMemo, useRef, useEffect, useCallback } from 'react';
import { useLanguage } from '../../contexts/LanguageContext';

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

        const startW = th.getBoundingClientRect().width;
        dragRef.current = {
            pointerId: e.pointerId,
            startX: e.clientX,
            startW,
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

        const w = Math.max(MIN_COL_WIDTH, Math.round(d.startW + (e.clientX - d.startX)));
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
