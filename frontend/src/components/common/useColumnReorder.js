import { useState } from 'react';

// Header drag-to-reorder for tables whose columns are an ordered id list.
// Same gesture as the metric columns on Offers/Campaigns (SortableTh's grip is
// the drag source, the <th> the drop target), packaged so a table only supplies
// its current order and what to do with the new one.
//
//   const reorder = useColumnReorder(ids, (next) => save(next));
//   <SortableTh draggable {...reorder.thProps(index)} ... />
export function moveItem(list, from, to) {
    if (from === to || from < 0 || to < 0 || from >= list.length || to >= list.length) return list;
    const copy = [...list];
    const [item] = copy.splice(from, 1);
    copy.splice(to, 0, item);
    return copy;
}

export default function useColumnReorder(ids, onReorder) {
    const [dragIdx, setDragIdx] = useState(null);
    const [overIdx, setOverIdx] = useState(null);

    const reset = () => {
        setDragIdx(null);
        setOverIdx(null);
    };

    const thProps = (idx) => ({
        isDragOver: overIdx === idx && dragIdx !== null && dragIdx !== idx,
        onDragStart: (e) => {
            // Firefox refuses to start a native drag until the payload is set.
            e.dataTransfer.setData('text/plain', String(idx));
            e.dataTransfer.effectAllowed = 'move';
            setDragIdx(idx);
        },
        onDragOver: (e) => {
            if (dragIdx === null) return; // not our drag (a file, a link…)
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (overIdx !== idx) setOverIdx(idx);
        },
        onDrop: (e) => {
            e.preventDefault();
            if (dragIdx !== null && dragIdx !== idx) {
                const next = moveItem(ids, dragIdx, idx);
                if (next !== ids) onReorder(next);
            }
            reset();
        },
        onDragEnd: reset,
    });

    return { thProps, dragging: dragIdx !== null };
}
