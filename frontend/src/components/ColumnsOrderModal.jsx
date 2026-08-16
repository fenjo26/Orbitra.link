import React, { useEffect, useState } from 'react';
import { X, GripVertical } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

/**
 * Compact "Select and Order Columns" modal for entity tables (Landings now;
 * built generic so Offers and friends can reuse it). Checkbox per column,
 * Select All, drag-to-reorder by grip handle, Restore to default.
 *
 * Columns with required: true (e.g. Name) stay checked and can't be removed —
 * a table row without its identity column is meaningless.
 *
 * Drag semantics follow the fixed ReportCustomizerModal pattern: only the grip
 * is draggable (so row clicks reach the checkbox), the reorder happens once on
 * drop by column id, never mid-drag.
 */
const ColumnsOrderModal = ({ columns, selectedIds, defaultIds, onClose, onSave }) => {
    const { t } = useLanguage();
    const [selectedSet, setSelectedSet] = useState(() => new Set(selectedIds));
    const [orderedIds, setOrderedIds] = useState(() => {
        // Keep the caller's order for selected columns, then the rest in
        // definition order — untouched columns never jump around.
        const chosen = selectedIds.filter(id => columns.some(c => c.id === id));
        const rest = columns.map(c => c.id).filter(id => !chosen.includes(id));
        return [...chosen, ...rest];
    });
    const [draggedId, setDraggedId] = useState(null);
    const [dragOverId, setDragOverId] = useState(null);

    useEffect(() => {
        setSelectedSet(new Set(selectedIds));
        const chosen = selectedIds.filter(id => columns.some(c => c.id === id));
        const rest = columns.map(c => c.id).filter(id => !chosen.includes(id));
        setOrderedIds([...chosen, ...rest]);
        // Reset the picker state whenever it is reopened with a new selection.
    }, [selectedIds]); // eslint-disable-line react-hooks/exhaustive-deps

    const isAllSelected = columns.every(c => selectedSet.has(c.id));

    const handleToggle = (id, required) => {
        if (required) return;
        setSelectedSet(prev => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    };

    const handleToggleAll = () => {
        setSelectedSet(prev => {
            const allIn = columns.every(c => prev.has(c.id));
            const next = new Set(prev);
            if (allIn) {
                columns.forEach(c => { if (!c.required) next.delete(c.id); });
            } else {
                columns.forEach(c => next.add(c.id));
            }
            return next;
        });
    };

    const handleRestoreDefault = () => {
        setSelectedSet(new Set(defaultIds));
        const rest = columns.map(c => c.id).filter(id => !defaultIds.includes(id));
        setOrderedIds([...defaultIds.filter(id => columns.some(c => c.id === id)), ...rest]);
    };

    const handleDragStart = (e, id) => {
        setDraggedId(id);
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', id);
    };

    const handleDragOver = (e, id) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (dragOverId !== id) setDragOverId(id);
    };

    const handleDrop = (e, targetId) => {
        e.preventDefault();
        try {
            const droppedId = draggedId || e.dataTransfer.getData('text/plain');
            if (droppedId && droppedId !== targetId) {
                const copy = [...orderedIds];
                const from = copy.indexOf(droppedId);
                const to = copy.indexOf(targetId);
                if (from !== -1 && to !== -1) {
                    copy.splice(from, 1);
                    copy.splice(to, 0, droppedId);
                    setOrderedIds(copy);
                }
            }
        } finally {
            setDraggedId(null);
            setDragOverId(null);
        }
    };

    const handleDragEnd = () => {
        setDraggedId(null);
        setDragOverId(null);
    };

    const handleSave = () => {
        const result = orderedIds.filter(id => selectedSet.has(id));
        onSave(result.length > 0 ? result : defaultIds);
    };

    return (
        <div className="modal-overlay" style={{ padding: '24px 16px', zIndex: 1200 }}>
            <div
                className="modal-content rounded-2xl shadow-2xl flex flex-col overflow-hidden"
                style={{
                    maxWidth: '480px',
                    width: '100%',
                    maxHeight: '80vh',
                    padding: 0,
                    backgroundColor: 'var(--color-bg-card)',
                    border: '1px solid var(--color-border)',
                    color: 'var(--color-text-primary)'
                }}
            >
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b" style={{ borderColor: 'var(--color-border)' }}>
                    <h3 className="text-base font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                        {t('landingColumns.title')}
                    </h3>
                    <button
                        type="button"
                        onClick={onClose}
                        className="btn-icon p-1 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                        style={{ color: 'var(--color-text-muted)' }}
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Select All */}
                <div className="flex items-center gap-3 px-6 py-3 border-b select-none" style={{ borderColor: 'var(--color-border)' }}>
                    <input
                        type="checkbox"
                        checked={isAllSelected}
                        onChange={handleToggleAll}
                        className="w-4 h-4 rounded cursor-pointer"
                        style={{ accentColor: 'var(--color-primary)' }}
                    />
                    <span className="text-xs font-medium" style={{ color: 'var(--color-text-primary)' }}>
                        {t('editor.selectAll')}
                    </span>
                </div>

                {/* Column list */}
                <div
                    className="flex-1 overflow-y-auto px-4 py-3 space-y-0.5"
                    style={{ scrollbarWidth: 'thin' }}
                    onDragLeave={() => setDragOverId(null)}
                >
                    {orderedIds.map((id) => {
                        const col = columns.find(c => c.id === id);
                        if (!col) return null;
                        const isChecked = selectedSet.has(id);
                        const isDragging = draggedId === id;
                        const isOver = dragOverId === id && draggedId && draggedId !== id;
                        return (
                            <div
                                key={id}
                                onDragOver={(e) => handleDragOver(e, id)}
                                onDrop={(e) => handleDrop(e, id)}
                                onClick={() => handleToggle(id, col.required)}
                                className="flex items-center gap-3 px-2 py-2 rounded-lg text-xs cursor-pointer select-none transition-colors hover:bg-black/5 dark:hover:bg-white/5"
                                style={{
                                    opacity: isDragging ? 0.4 : 1,
                                    boxShadow: isOver ? 'inset 0 2px 0 var(--color-primary)' : 'none'
                                }}
                            >
                                <div
                                    draggable
                                    onDragStart={(e) => handleDragStart(e, id)}
                                    onDragEnd={handleDragEnd}
                                    className="cursor-grab active:cursor-grabbing p-0.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <GripVertical className="w-3.5 h-3.5 opacity-60" />
                                </div>

                                <input
                                    type="checkbox"
                                    checked={isChecked}
                                    disabled={col.required}
                                    onChange={() => handleToggle(id, col.required)}
                                    onClick={(e) => e.stopPropagation()}
                                    className="w-4 h-4 rounded cursor-pointer"
                                    style={{ accentColor: 'var(--color-primary)' }}
                                />

                                <span
                                    className="flex-1 font-normal"
                                    style={{ color: isChecked ? 'var(--color-text-primary)' : 'var(--color-text-secondary)' }}
                                >
                                    {col.label}
                                </span>

                                {col.required && (
                                    <span className="text-[10.5px] px-1.5 py-0.5 rounded-md" style={{ backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-muted)', border: '1px solid var(--color-border)' }}>
                                        {t('landingColumns.required')}
                                    </span>
                                )}
                            </div>
                        );
                    })}
                </div>

                {/* Footer */}
                <div className="flex items-center justify-between px-6 py-3.5 border-t" style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-card)' }}>
                    <button
                        type="button"
                        onClick={handleRestoreDefault}
                        className="text-xs transition-colors hover:underline"
                        style={{ color: 'var(--color-primary)' }}
                    >
                        {t('reportCustomizer.restoreDefault')}
                    </button>
                    <div className="flex items-center gap-2.5">
                        <button type="button" onClick={onClose} className="btn btn-secondary text-xs py-2 px-4 rounded-xl font-medium">
                            {t('common.cancel')}
                        </button>
                        <button type="button" onClick={handleSave} className="btn btn-primary text-xs py-2 px-5 rounded-xl font-medium">
                            {t('common.save')}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default ColumnsOrderModal;
