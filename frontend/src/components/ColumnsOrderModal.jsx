import React, { useEffect, useState, useRef } from 'react';
import { X, GripVertical, ChevronUp, ChevronDown, RotateCcw } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

/**
 * Compact "Select and Order Columns" modal for entity tables (Landings, Offers).
 * Checkbox per column, Select All, drag-to-reorder by grip handle, Move Up/Down buttons, Restore to default.
 *
 * Columns with required: true (e.g. Name) stay checked and can't be removed —
 * a table row without its identity column is meaningless.
 */
const ColumnsOrderModal = ({ columns, selectedIds, defaultIds, onClose, onSave }) => {
    const { t } = useLanguage();
    const [selectedSet, setSelectedSet] = useState(() => new Set(selectedIds));
    const [orderedIds, setOrderedIds] = useState(() => {
        const chosen = selectedIds.filter(id => columns.some(c => c.id === id));
        const rest = columns.map(c => c.id).filter(id => !chosen.includes(id));
        return [...chosen, ...rest];
    });

    const draggedIdRef = useRef(null);
    const [draggedId, setDraggedId] = useState(null);
    const [dragOverId, setDragOverId] = useState(null);
    const prevSelectedIdsRef = useRef(null);

    useEffect(() => {
        if (prevSelectedIdsRef.current !== selectedIds) {
            setSelectedSet(new Set(selectedIds));
            const chosen = selectedIds.filter(id => columns.some(c => c.id === id));
            const rest = columns.map(c => c.id).filter(id => !chosen.includes(id));
            setOrderedIds([...chosen, ...rest]);
            prevSelectedIdsRef.current = selectedIds;
        }
    }, [selectedIds, columns]);

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

    const handleMoveMetric = (id, direction) => {
        setOrderedIds(prev => {
            const next = [...prev];
            const currentIndex = next.indexOf(id);
            if (currentIndex === -1) return prev;
            const targetIndex = direction === 'up' ? currentIndex - 1 : currentIndex + 1;
            if (targetIndex < 0 || targetIndex >= next.length) return prev;
            const [item] = next.splice(currentIndex, 1);
            next.splice(targetIndex, 0, item);
            return next;
        });
    };

    const handleDragStart = (e, id) => {
        draggedIdRef.current = id;
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
        const sourceId = draggedIdRef.current || e.dataTransfer.getData('text/plain');
        if (sourceId && sourceId !== targetId) {
            setOrderedIds(prev => {
                const next = [...prev];
                const from = next.indexOf(sourceId);
                const to = next.indexOf(targetId);
                if (from !== -1 && to !== -1) {
                    const [item] = next.splice(from, 1);
                    next.splice(to, 0, item);
                }
                return next;
            });
        }
        draggedIdRef.current = null;
        setDraggedId(null);
        setDragOverId(null);
    };

    const handleDragEnd = () => {
        draggedIdRef.current = null;
        setDraggedId(null);
        setDragOverId(null);
    };

    const handleSave = () => {
        const result = orderedIds.filter(id => selectedSet.has(id));
        onSave(result.length > 0 ? result : defaultIds);
    };

    // Opens on top of another modal, so it sits one step above the 2000 base.
    return (
        <div className="modal-overlay" style={{ padding: '24px 16px', zIndex: 2100 }}>
            <div
                className="modal-content rounded-2xl shadow-2xl flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-150"
                style={{
                    maxWidth: '500px',
                    width: '100%',
                    maxHeight: '84vh',
                    padding: 0,
                    backgroundColor: 'var(--color-bg-card)',
                    border: '1px solid var(--color-border)',
                    color: 'var(--color-text-primary)'
                }}
            >
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b" style={{ borderColor: 'var(--color-border)' }}>
                    <h3 className="text-base font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                        {t('columnsOrder.title', 'Columns')}
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
                <div className="flex items-center justify-between px-6 py-3 border-b select-none bg-[var(--color-bg-soft)]" style={{ borderColor: 'var(--color-border)' }}>
                    <div className="flex items-center gap-3">
                        <input
                            type="checkbox"
                            id="cols_select_all"
                            checked={isAllSelected}
                            onChange={handleToggleAll}
                            className="w-4 h-4 rounded cursor-pointer"
                            style={{ accentColor: 'var(--color-primary)' }}
                        />
                        <label htmlFor="cols_select_all" className="text-xs font-medium cursor-pointer" style={{ color: 'var(--color-text-primary)' }}>
                            {t('editor.selectAll', 'Select All')}
                        </label>
                    </div>
                    <div className="text-[11px] font-medium px-2 py-0.5 rounded-full" style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-secondary)', border: '1px solid var(--color-border)' }}>
                        {selectedSet.size} / {columns.length}
                    </div>
                </div>

                {/* Column list */}
                <div
                    className="flex-1 overflow-y-auto px-4 py-3 space-y-1"
                    style={{ scrollbarWidth: 'thin' }}
                >
                    {orderedIds.map((id, idx) => {
                        const col = columns.find(c => c.id === id);
                        if (!col) return null;
                        const isChecked = selectedSet.has(id);
                        const isDragging = draggedId === id;
                        const isOver = dragOverId === id && draggedId && draggedId !== id;
                        const isFirst = idx === 0;
                        const isLast = idx === orderedIds.length - 1;

                        return (
                            <div
                                key={id}
                                onDragOver={(e) => handleDragOver(e, id)}
                                onDrop={(e) => handleDrop(e, id)}
                                onClick={() => handleToggle(id, col.required)}
                                className="group flex items-center gap-2 px-2.5 py-1.5 rounded-xl text-xs select-none transition-all cursor-pointer border"
                                style={{
                                    backgroundColor: isChecked ? 'var(--color-bg-soft)' : 'transparent',
                                    borderColor: isChecked ? 'var(--color-border)' : 'transparent',
                                    opacity: isDragging ? 0.35 : 1,
                                    boxShadow: isOver ? 'inset 0 2px 0 var(--color-primary)' : 'none'
                                }}
                            >
                                <div
                                    draggable
                                    onDragStart={(e) => handleDragStart(e, id)}
                                    onDragEnd={handleDragEnd}
                                    className="cursor-grab active:cursor-grabbing p-1 text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)] transition-colors flex-shrink-0"
                                    onClick={(e) => e.stopPropagation()}
                                    title="Drag to reorder"
                                >
                                    <GripVertical className="w-3.5 h-3.5 opacity-60 group-hover:opacity-100 pointer-events-none" />
                                </div>

                                {/* Move Up / Move Down buttons — always visible
                                    below lg: group-hover never fires on a
                                    touch screen, and drag handles don't work
                                    with fingers either. */}
                                <div
                                    className="flex items-center gap-0.5 flex-shrink-0 opacity-40 group-hover:opacity-100 max-lg:opacity-100 transition-opacity"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <button
                                        type="button"
                                        disabled={isFirst}
                                        onClick={() => handleMoveMetric(id, 'up')}
                                        className="touch-min-44 p-0.5 rounded hover:bg-black/10 dark:hover:bg-white/10 disabled:opacity-20 text-[var(--color-text-muted)] transition-colors"
                                        title="Move up"
                                    >
                                        <ChevronUp className="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        disabled={isLast}
                                        onClick={() => handleMoveMetric(id, 'down')}
                                        className="touch-min-44 p-0.5 rounded hover:bg-black/10 dark:hover:bg-white/10 disabled:opacity-20 text-[var(--color-text-muted)] transition-colors"
                                        title="Move down"
                                    >
                                        <ChevronDown className="w-3.5 h-3.5" />
                                    </button>
                                </div>

                                <input
                                    type="checkbox"
                                    checked={isChecked}
                                    disabled={col.required}
                                    onChange={() => handleToggle(id, col.required)}
                                    onClick={(e) => e.stopPropagation()}
                                    className="w-4 h-4 rounded cursor-pointer flex-shrink-0"
                                    style={{ accentColor: 'var(--color-primary)' }}
                                />

                                <span
                                    className="flex-1 font-medium truncate"
                                    style={{ color: isChecked ? 'var(--color-text-primary)' : 'var(--color-text-secondary)' }}
                                >
                                    {col.label}
                                </span>

                                {col.required && (
                                    <span className="text-[10.5px] px-1.5 py-0.5 rounded-md flex-shrink-0" style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-muted)', border: '1px solid var(--color-border)' }}>
                                        {t('columnsOrder.required', 'Required')}
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
                        className="text-xs transition-colors hover:underline flex items-center gap-1.5 font-medium"
                        style={{ color: 'var(--color-primary)' }}
                    >
                        <RotateCcw className="w-3.5 h-3.5" />
                        <span>{t('reportCustomizer.restoreDefault', 'Restore to default')}</span>
                    </button>
                    <div className="flex items-center gap-2.5">
                        <button type="button" onClick={onClose} className="btn btn-secondary text-xs py-2 px-4 rounded-xl font-medium">
                            {t('common.cancel', 'Cancel')}
                        </button>
                        <button type="button" onClick={handleSave} className="btn btn-primary text-xs py-2 px-5 rounded-xl font-medium">
                            {t('common.save', 'Save')}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default ColumnsOrderModal;
