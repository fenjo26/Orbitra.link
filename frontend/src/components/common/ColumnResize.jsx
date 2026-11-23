import React, { useState, useRef } from 'react';
import { useLanguage } from '../../contexts/LanguageContext';
import { colAlignClass } from './tableColumns';
import { MIN_COL_WIDTH, measureColumnContent } from './resizableColumns';

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
