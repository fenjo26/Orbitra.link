import React, { useMemo, useState } from 'react';
import { X, Search } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

/**
 * Keitaro-style picker for stream landings/offers. The stream rows used to be
 * plain <select>s fed by allOffers/allLandings, which is unworkable past a few
 * dozen items: no search, no filters, one item per click. This modal lists
 * everything in a table with instant search, group/network/country filters and
 * multi-select, marks what the stream already holds, and hands the chosen ids
 * back in one shot — the caller redistributes rotation weights.
 *
 * Layout follows Keitaro's selector: filters on top, [✓] ID Name Countries
 * Group Network columns for offers (ID Name Group for landings), and a footer
 * with "Select all" on the left, Close/Add on the right.
 */
const EntitySelectorModal = ({ type, items, existingIds, onClose, onAdd, singleSelect = false, title = null }) => {
    const { t } = useLanguage();
    const [q, setQ] = useState('');
    const [groupFilter, setGroupFilter] = useState('');
    const [networkFilter, setNetworkFilter] = useState('');
    const [countryFilter, setCountryFilter] = useState('');
    const [selected, setSelected] = useState(() => new Set());

    const isOffers = type === 'offers';
    // The Safe Page picker passes local offers only: network and GEO filters
    // are noise for uploaded whites, so they disappear when everything on the
    // list is local.
    const allLocal = isOffers && (items || []).length > 0 && (items || []).every(it => it.is_local);
    const showOfferFilters = isOffers && !allLocal;
    const existing = useMemo(() => new Set((existingIds || []).map(id => parseInt(id, 10))), [existingIds]);

    const uniqueByName = (entries) => {
        const seen = new Map();
        entries.forEach(([id, name]) => { if (!seen.has(id)) seen.set(id, name); });
        return Array.from(seen, ([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
    };

    const groups = useMemo(() => uniqueByName(
        (items || []).filter(it => it.group_id).map(it => [it.group_id, it.group_name || `#${it.group_id}`])
    ), [items]);

    const networks = useMemo(() => {
        if (!isOffers) return [];
        return uniqueByName(
            (items || []).filter(it => it.affiliate_network_id).map(it => [it.affiliate_network_id, it.affiliate_network_name || `#${it.affiliate_network_id}`])
        );
    }, [items, isOffers]);

    // Offers store GEO as a comma-separated string ("US, CA"); the filter is a
    // flat list of every country mentioned by at least one offer.
    const countries = useMemo(() => {
        if (!isOffers) return [];
        const seen = new Set();
        (items || []).forEach(it => {
            String(it.geo || '').split(',').forEach(c => {
                const code = c.trim().toUpperCase();
                if (code) seen.add(code);
            });
        });
        return Array.from(seen).sort();
    }, [items, isOffers]);

    const filtered = useMemo(() => {
        const needle = String(q || '').trim().toLowerCase();
        return (items || []).filter(it => {
            if (needle) {
                const haystack = `${it.name || ''} ${it.url || ''} ${it.id}`.toLowerCase();
                if (!haystack.includes(needle)) return false;
            }
            if (groupFilter && String(it.group_id || '') !== String(groupFilter)) return false;
            if (showOfferFilters && networkFilter && String(it.affiliate_network_id || '') !== String(networkFilter)) return false;
            if (showOfferFilters && countryFilter) {
                const codes = String(it.geo || '').split(',').map(c => c.trim().toUpperCase());
                if (!codes.includes(countryFilter)) return false;
            }
            return true;
        });
    }, [items, q, groupFilter, networkFilter, countryFilter, showOfferFilters]);

    const pickable = filtered.filter(it => !existing.has(parseInt(it.id, 10)));
    const allPicked = pickable.length > 0 && pickable.every(it => selected.has(it.id));
    const somePicked = pickable.some(it => selected.has(it.id));

    const toggle = (id) => {
        setSelected(prev => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    };

    const toggleAll = () => {
        setSelected(prev => {
            const allIn = pickable.length > 0 && pickable.every(it => prev.has(it.id));
            const next = new Set(prev);
            if (allIn) {
                pickable.forEach(it => next.delete(it.id));
            } else {
                pickable.forEach(it => next.add(it.id));
            }
            return next;
        });
    };

    const th = { padding: '6px 10px', textAlign: 'left', fontSize: '11.5px', textTransform: 'uppercase', letterSpacing: '0.03em', color: 'var(--color-text-muted)', borderBottom: '1px solid var(--color-border)', whiteSpace: 'nowrap', position: 'sticky', top: 0, backgroundColor: 'var(--color-bg-card)', zIndex: 1 };
    const td = { padding: '7px 10px', color: 'var(--color-text-primary)', borderBottom: '1px solid var(--color-border)', verticalAlign: 'middle' };

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '760px' }}>
                <div className="modal-header">
                    <h3 className="modal-title">{title || (isOffers ? t('picker.offersTitle') : t('picker.landingsTitle'))}</h3>
                    <button type="button" onClick={onClose} className="action-btn">
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Filters: Search + Groups (+ Networks + Countries for offers) */}
                <div className="p-4 flex flex-wrap gap-2" style={{ borderBottom: '1px solid var(--color-border)' }}>
                    <div className="relative flex-1" style={{ minWidth: '180px' }}>
                        <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--color-text-muted)' }} />
                        <input
                            type="text"
                            value={q}
                            onChange={e => setQ(e.target.value)}
                            className="form-input pl-9"
                            placeholder={t('picker.search')}
                            autoFocus
                        />
                    </div>
                    <select value={groupFilter} onChange={e => setGroupFilter(e.target.value)} className="form-select text-sm" style={{ width: 'auto', minWidth: '130px' }}>
                        <option value="">{t('picker.allGroups')}</option>
                        {groups.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                    </select>
                    {showOfferFilters && (
                        <>
                            <select value={networkFilter} onChange={e => setNetworkFilter(e.target.value)} className="form-select text-sm" style={{ width: 'auto', minWidth: '130px' }}>
                                <option value="">{t('picker.allNetworks')}</option>
                                {networks.map(n => <option key={n.id} value={n.id}>{n.name}</option>)}
                            </select>
                            <select value={countryFilter} onChange={e => setCountryFilter(e.target.value)} className="form-select text-sm" style={{ width: 'auto', minWidth: '120px' }}>
                                <option value="">{t('picker.allCountries')}</option>
                                {countries.map(c => <option key={c} value={c}>{c}</option>)}
                            </select>
                        </>
                    )}
                </div>

                {/* Table */}
                <div className="flex-1 overflow-y-auto" style={{ maxHeight: '46vh', minHeight: '200px' }}>
                    {filtered.length === 0 ? (
                        <div className="text-center py-12 text-sm" style={{ color: 'var(--color-text-muted)' }}>
                            {t('picker.noResults')}
                        </div>
                    ) : (
                        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
                            <thead>
                                <tr>
                                    {!singleSelect && <th style={{ ...th, width: '36px' }}></th>}
                                    <th style={{ ...th, width: '60px' }}>ID</th>
                                    <th style={th}>{t('editor.name')}</th>
                                    {showOfferFilters && <th style={th}>{t('picker.countries')}</th>}
                                    <th style={th}>{t('picker.group')}</th>
                                    {showOfferFilters && <th style={th}>{t('picker.network')}</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {filtered.map(it => {
                                    const id = parseInt(it.id, 10);
                                    const isExisting = existing.has(id);
                                    const isSelected = selected.has(id);
                                    const rowStyle = {
                                        ...td,
                                        backgroundColor: isExisting
                                            ? 'var(--color-bg-soft)'
                                            : isSelected
                                                ? 'color-mix(in srgb, var(--color-primary) 8%, transparent)'
                                                : 'transparent',
                                        opacity: isExisting ? 0.6 : 1
                                    };
                                    return (
                                        <tr
                                            key={it.id}
                                            onClick={() => {
                                                if (isExisting) return;
                                                // Single-select (Safe Page): one click IS the pick.
                                                if (singleSelect) { onAdd([id]); return; }
                                                toggle(id);
                                            }}
                                            style={{ cursor: isExisting ? 'default' : 'pointer' }}
                                        >
                                            {!singleSelect && (
                                                <td style={rowStyle}>
                                                    <input
                                                        type="checkbox"
                                                        checked={isExisting || isSelected}
                                                        disabled={isExisting}
                                                        onChange={() => toggle(id)}
                                                        onClick={e => e.stopPropagation()}
                                                        style={{ accentColor: 'var(--color-primary)', verticalAlign: 'middle' }}
                                                    />
                                                </td>
                                            )}
                                            <td style={{ ...rowStyle, color: 'var(--color-text-muted)' }}>{it.id}</td>
                                            <td style={rowStyle}>
                                                <span className="font-medium" style={{ textDecoration: isExisting ? 'line-through' : 'none' }}>
                                                    {it.name}
                                                </span>
                                                {!!it.is_pwa && (
                                                    <span className="ml-2 text-[10.5px] px-1.5 py-0.5 rounded-md" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)', border: '1px solid var(--color-border)' }}>
                                                        PWA
                                                    </span>
                                                )}
                                                {isExisting && (
                                                    <span className="ml-2 text-[10.5px] px-1.5 py-0.5 rounded-md" style={{ backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-muted)', border: '1px solid var(--color-border)' }}>
                                                        {t('picker.alreadyAdded')}
                                                    </span>
                                                )}
                                            </td>
                                            {showOfferFilters && <td style={rowStyle}>{it.geo || <span style={{ color: 'var(--color-text-muted)' }}>—</span>}</td>}
                                            <td style={rowStyle}>{it.group_name || <span style={{ color: 'var(--color-text-muted)' }}>—</span>}</td>
                                            {showOfferFilters && <td style={rowStyle}>{it.affiliate_network_name || <span style={{ color: 'var(--color-text-muted)' }}>—</span>}</td>}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </div>

                {/* Footer: Select all on the left, Close/Add on the right.
                    A single-select pick fires on row click, so only Close stays. */}
                <div className="modal-footer" style={{ justifyContent: singleSelect ? 'flex-end' : 'space-between' }}>
                    {!singleSelect && (
                        <label className="flex items-center gap-2 text-sm cursor-pointer select-none" style={{ color: 'var(--color-text-secondary)' }}>
                            <input
                                type="checkbox"
                                checked={allPicked}
                                ref={el => { if (el) el.indeterminate = !allPicked && somePicked; }}
                                onChange={toggleAll}
                                disabled={pickable.length === 0}
                                style={{ accentColor: 'var(--color-primary)' }}
                            />
                            {t('editor.selectAll')}
                        </label>
                    )}
                    <div className="flex gap-3">
                        <button type="button" onClick={onClose} className="btn btn-secondary">
                            {t('common.close')}
                        </button>
                        {!singleSelect && (
                            <button
                                type="button"
                                onClick={() => onAdd(Array.from(selected))}
                                disabled={selected.size === 0}
                                className="btn btn-primary"
                            >
                                {t('common.add')}
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
};

export default EntitySelectorModal;
