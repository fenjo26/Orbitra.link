import React, { useState, useRef, useEffect } from 'react';
import { ChevronDown, Search } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';

/**
 * Multi-select dropdown component with search, groups, and bulk actions.
 *
 * @param {Object} props
 * @param {Array<{id: number, name: string, group_id?: number}>} props.items - Items to select from
 * @param {Array<{id: number, name: string}>} props.groups - Optional groups for grouping items
 * @param {number[]} props.value - Selected item IDs
 * @param {function(number[]): void} props.onChange - Callback when selection changes
 * @param {string} props.placeholder - Placeholder text when nothing selected
 * @param {string} props.label - Label for display (e.g., "Campaigns")
 * @param {React.ReactNode} props.icon - Optional icon element (JSX)
 */
export const MultiSelect = ({ items, groups = [], value, onChange, placeholder, label, icon }) => {
    const { t } = useLanguage();
    const [isOpen, setIsOpen] = useState(false);
    const [search, setSearch] = useState('');
    const wrapperRef = useRef(null);

    // Filter items by search term
    const filteredItems = items.filter(item =>
        item.name?.toLowerCase().includes(search.toLowerCase())
    );

    // Group items by group_id
    const itemsByGroup = groups.length > 0
        ? groups.map(g => ({
            ...g,
            items: items.filter(i => i.group_id === g.id)
        }))
        : [];

    // Get count of selected items
    const selectedCount = value.length;
    const totalCount = items.length;

    // Get label text
    const getLabelText = () => {
        if (selectedCount === 0) return placeholder;
        if (selectedCount === totalCount) return label;
        return `${label}: ${selectedCount}`;
    };

    // Handle click outside to close
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Handle Escape key to close
    useEffect(() => {
        const handleEscape = (event) => {
            if (event.key === 'Escape') setIsOpen(false);
        };
        document.addEventListener('keydown', handleEscape);
        return () => document.removeEventListener('keydown', handleEscape);
    }, []);

    // Toggle item selection
    const toggleItem = (id) => {
        if (value.includes(id)) {
            onChange(value.filter(v => v !== id));
        } else {
            onChange([...value, id]);
        }
    };

    // Select all items in a group
    const selectGroup = (groupId) => {
        const groupItems = items.filter(i => i.group_id === groupId);
        const groupIds = groupItems.map(i => i.id);
        const newValue = [...new Set([...value, ...groupIds])];
        onChange(newValue);
    };

    // Select all visible items
    const selectAll = () => {
        onChange(filteredItems.map(i => i.id));
    };

    // Clear all selections
    const clearAll = () => {
        onChange([]);
    };

    const isItemSelected = (id) => value.includes(id);

    return (
        <div ref={wrapperRef} className="relative" style={{ minWidth: '160px' }}>
            {/* Trigger button */}
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className="btn btn-secondary btn-sm flex items-center justify-between gap-2"
                style={{ minWidth: '160px', justifyContent: 'space-between' }}
            >
                <span className="flex items-center gap-2">
                    {icon}
                    <span>{getLabelText()}</span>
                </span>
                <ChevronDown className={`w-4 h-4 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
            </button>

            {/* Dropdown */}
            {isOpen && (
                <div
                    className="absolute z-[1000] mt-1 w-full min-w-[240px] max-h-[360px] flex flex-col overflow-hidden rounded-xl shadow-lg"
                    style={{
                        background: 'var(--color-bg-card)',
                        border: '1px solid var(--color-border)',
                    }}
                >
                    {/* Search input */}
                    <div className="p-2 border-b" style={{ borderColor: 'var(--color-border)' }}>
                        <div className="relative">
                            <Search className="absolute left-2 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--color-text-muted)]" />
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder={t('analytics.search')}
                                className="w-full pl-8 pr-3 py-2 text-sm rounded-lg border"
                                style={{
                                    background: 'var(--color-bg-soft)',
                                    borderColor: 'var(--color-border)',
                                    color: 'var(--color-text-primary)',
                                }}
                            />
                        </div>
                    </div>

                    {/* Scrollable content */}
                    <div className="flex-1 overflow-y-auto">
                        {/* Groups section */}
                        {groups.length > 0 && (
                            <div>
                                <div className="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-[var(--color-text-muted)]">
                                    {t('analytics.groups')}
                                </div>
                                {itemsByGroup.map(group => (
                                    <label
                                        key={group.id}
                                        className="flex items-center gap-2 px-3 py-1.5 cursor-pointer hover:bg-[var(--color-bg-soft)]"
                                        style={{ color: 'var(--color-text-primary)' }}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={group.items.every(i => isItemSelected(i.id))}
                                            onChange={() => selectGroup(group.id)}
                                            className="w-4 h-4 rounded border-gray-300"
                                        />
                                        <span className="flex-1 truncate">{group.name}</span>
                                        <span className="text-xs text-[var(--color-text-muted)]">
                                            ({group.items.length})
                                        </span>
                                    </label>
                                ))}
                            </div>
                        )}

                        {/* Items section */}
                        <div>
                            <div className="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-[var(--color-text-muted)]">
                                {label}
                            </div>
                            {filteredItems.length === 0 ? (
                                <div className="px-3 py-4 text-center text-sm text-[var(--color-text-muted)]">
                                    {t('analytics.noResults')}
                                </div>
                            ) : (
                                filteredItems.map(item => (
                                    <label
                                        key={item.id}
                                        className={`flex items-center gap-2 px-3 py-1.5 cursor-pointer hover:bg-[var(--color-bg-soft)] ${
                                            isItemSelected(item.id) ? 'bg-[var(--color-primary-soft)]' : ''
                                        }`}
                                        style={{ color: 'var(--color-text-primary)' }}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={isItemSelected(item.id)}
                                            onChange={() => toggleItem(item.id)}
                                            className="w-4 h-4 rounded border-gray-300"
                                        />
                                        <span className="flex-1 truncate">{item.name}</span>
                                    </label>
                                ))
                            )}
                        </div>
                    </div>

                    {/* Actions footer */}
                    <div
                        className="flex items-center justify-between px-3 py-2 border-t"
                        style={{ borderColor: 'var(--color-border)' }}
                    >
                        <button
                            type="button"
                            onClick={selectAll}
                            className="text-xs font-medium text-[var(--color-primary)] hover:underline"
                        >
                            {t('analytics.selectAll')}
                        </button>
                        <button
                            type="button"
                            onClick={clearAll}
                            className="text-xs font-medium text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)]"
                        >
                            {t('analytics.clearAll')}
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};

export default MultiSelect;
