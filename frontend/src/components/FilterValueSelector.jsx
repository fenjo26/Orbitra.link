import React, { useState, useEffect, useRef, useMemo } from 'react';
import { X, ChevronDown, Check, Plus } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

/**
 * Chip picker for stream filters whose values come from a known list
 * (Device, OS, Browser, Language) — the same feel as the country picker.
 *
 * Before this the modal was a bare text input, and the router only
 * understands specific tokens ("android", not "Android 14"; "tiktok" for the
 * in-app browser), so a user had no way to know what to type. The list shows
 * what the tracker recognises; free text is still accepted with Enter for
 * anything the list does not carry (another language region, say).
 *
 * value/onChange speak the modal's comma-separated string, so saving and
 * editing a filter work exactly as before.
 *
 * options: [{ value, label?, hint?, group? }]
 */
const splitValues = (value) =>
    typeof value === 'string' && value.trim()
        ? [...new Set(value.split(',').map(v => v.trim()).filter(Boolean))]
        : [];

const FilterValueSelector = ({ value = '', onChange, options = [], allowCustom = true, placeholder, hint }) => {
    const { t } = useLanguage();
    const [isOpen, setIsOpen] = useState(false);
    const [search, setSearch] = useState('');
    const wrapperRef = useRef(null);
    const inputRef = useRef(null);

    const selected = splitValues(value);
    const isSelected = (v) => selected.some(s => s.toLowerCase() === String(v).toLowerCase());

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const labelFor = (v) => {
        const opt = options.find(o => String(o.value).toLowerCase() === String(v).toLowerCase());
        return opt?.label && opt.label !== opt.value ? opt.label : null;
    };

    const add = (v) => {
        const clean = String(v).trim();
        if (clean && !isSelected(clean)) {
            onChange([...selected, clean].join(', '));
        }
        setSearch('');
        if (inputRef.current) inputRef.current.focus();
    };

    const remove = (v) => onChange(selected.filter(s => s !== v).join(', '));

    const toggle = (v) => (isSelected(v)
        ? onChange(selected.filter(s => s.toLowerCase() !== String(v).toLowerCase()).join(', '))
        : add(v));

    const q = search.trim().toLowerCase();
    const filtered = useMemo(() => options.filter(o =>
        !q || String(o.value).toLowerCase().includes(q) || (o.label || '').toLowerCase().includes(q)
    ), [options, q]);

    const groups = [];
    filtered.forEach(o => {
        const g = o.group || '';
        let bucket = groups.find(x => x.name === g);
        if (!bucket) {
            bucket = { name: g, items: [] };
            groups.push(bucket);
        }
        bucket.items.push(o);
    });

    const exactExists = q && options.some(o => String(o.value).toLowerCase() === q);
    const canAddCustom = allowCustom && q && !exactExists && !isSelected(search.trim()) && !search.includes(',');

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (!q) return;
            const exact = options.find(o => String(o.value).toLowerCase() === q);
            if (exact) add(exact.value);
            else if (filtered.length === 1) add(filtered[0].value);
            else if (allowCustom) add(search);
        } else if (e.key === 'Backspace' && !search && selected.length) {
            remove(selected[selected.length - 1]);
        } else if (e.key === 'Escape') {
            setIsOpen(false);
        }
    };

    return (
        <div className="w-full relative" ref={wrapperRef}>
            <div
                className={`min-h-[42px] border rounded-[4px] p-1.5 pr-7 flex flex-wrap items-center gap-1.5 cursor-text transition-colors bg-[var(--color-bg-card)] ${isOpen
                    ? 'border-[var(--color-primary)] ring-1 ring-[var(--color-primary)]'
                    : 'border-[var(--color-border)] hover:border-[var(--color-text-muted)]'}`}
                onClick={() => {
                    setIsOpen(true);
                    if (inputRef.current) inputRef.current.focus();
                }}
            >
                {selected.map(v => (
                    <div key={v} className="flex items-center gap-1 bg-[var(--color-bg-soft)] border border-[var(--color-border)] text-[var(--color-text-primary)] px-2 py-0.5 rounded-[4px] text-[13px] min-w-0">
                        <span className="font-medium">{v}</span>
                        {labelFor(v) && (
                            <span className="text-[var(--color-text-muted)] max-w-[140px] truncate" title={labelFor(v)}>({labelFor(v)})</span>
                        )}
                        <button
                            type="button"
                            onClick={(e) => {
                                e.stopPropagation();
                                remove(v);
                            }}
                            className="ml-0.5 text-[var(--color-text-muted)] hover:text-[var(--color-danger)] hover:bg-[var(--color-danger-bg)] rounded-full p-0.5 transition-colors"
                        >
                            <X size={12} />
                        </button>
                    </div>
                ))}
                <div className="flex-1 min-w-[120px]">
                    <input
                        ref={inputRef}
                        type="text"
                        value={search}
                        onChange={e => {
                            setSearch(e.target.value);
                            setIsOpen(true);
                        }}
                        onFocus={() => setIsOpen(true)}
                        onKeyDown={handleKeyDown}
                        className="w-full bg-transparent border-none outline-none text-sm p-1 text-[var(--color-text-primary)] placeholder:text-[var(--color-text-muted)]"
                        // The global form-input rules otherwise paint a grey
                        // pill inside the chip box.
                        style={{ background: 'transparent', border: 'none', boxShadow: 'none', borderRadius: 0, minHeight: 0, height: 'auto' }}
                        placeholder={selected.length === 0 ? (placeholder || t('filterValues.placeholder')) : ''}
                    />
                </div>
                <div className="absolute right-2 top-[21px] -translate-y-1/2 text-[var(--color-text-muted)] pointer-events-none">
                    <ChevronDown size={16} />
                </div>
            </div>

            {hint && (
                <div className="mt-1.5 text-[11px] text-[var(--color-text-muted)]" style={{ lineHeight: 1.4 }}>
                    {hint}
                </div>
            )}

            {isOpen && (
                <div className="absolute top-[46px] left-0 w-full bg-[var(--color-bg-dropdown)] border border-[var(--color-border)] rounded-[4px] shadow-lg z-50 max-h-64 overflow-y-auto">
                    {canAddCustom && (
                        <button
                            type="button"
                            onClick={(e) => {
                                e.stopPropagation();
                                add(search);
                            }}
                            className="w-full text-left px-3 py-2 hover:bg-[var(--color-bg-hover)] flex items-center gap-2 text-sm text-[var(--color-primary)] border-b border-[var(--color-border)]"
                        >
                            <Plus size={14} />
                            {t('filterValues.addCustom', { value: search.trim() })}
                        </button>
                    )}
                    {groups.length > 0 ? groups.map(g => (
                        <div key={g.name || '_'}>
                            {g.name && (
                                <div className="px-3 py-1.5 border-b border-[var(--color-border)] bg-[var(--color-bg-soft)] text-[11px] font-semibold text-[var(--color-text-muted)] uppercase tracking-wider">
                                    {g.name}
                                </div>
                            )}
                            {g.items.map(o => (
                                <button
                                    key={o.value}
                                    type="button"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        toggle(o.value);
                                    }}
                                    className="w-full text-left px-3 py-2 hover:bg-[var(--color-bg-hover)] flex items-center justify-between gap-3 group transition-colors"
                                >
                                    <div className="flex items-baseline gap-2 min-w-0">
                                        <span className="text-sm font-medium text-[var(--color-text-primary)] group-hover:text-[var(--color-primary)] shrink-0">
                                            {o.label || o.value}
                                        </span>
                                        {o.label && o.label !== o.value && (
                                            <span className="text-xs text-[var(--color-text-muted)] font-mono shrink-0">{o.value}</span>
                                        )}
                                        {o.hint && (
                                            <span className="text-xs text-[var(--color-text-muted)] truncate">{o.hint}</span>
                                        )}
                                    </div>
                                    {isSelected(o.value) && <Check size={16} className="text-[var(--color-primary)] shrink-0" />}
                                </button>
                            ))}
                        </div>
                    )) : !canAddCustom && (
                        <div className="px-3 py-3 text-sm text-[var(--color-text-muted)] text-center">
                            {t('filterValues.nothingFound')}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

export default FilterValueSelector;
