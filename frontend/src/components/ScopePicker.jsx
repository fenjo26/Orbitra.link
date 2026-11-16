import React, { useMemo, useState } from 'react';
import { Search } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

// Picks what a "Selected" access level covers: whole groups (resolved live on
// the server — whatever sits in the group at request time) and single items
// regardless of their group. The value is the stored permission shape:
// { groups: [group ids], items: [entity ids] }.
const toIds = (list) => (Array.isArray(list) ? list.map(Number).filter((n) => n > 0) : []);

const ScopePicker = ({ groups = [], items = [], value, onChange, readOnlyMode = false }) => {
    const { t } = useLanguage();
    const [query, setQuery] = useState('');
    const selectedGroups = useMemo(() => new Set(toIds(value?.groups)), [value]);
    const selectedItems = useMemo(() => new Set(toIds(value?.items)), [value]);

    const needle = query.trim().toLowerCase();
    const matches = (name) => !needle || String(name || '').toLowerCase().includes(needle);
    const groupNames = useMemo(() => {
        const map = new Map();
        groups.forEach((g) => map.set(Number(g.id), g.name));
        return map;
    }, [groups]);
    const visibleGroups = groups.filter((g) => matches(g.name));
    const visibleItems = items.filter((it) => matches(it.name) || matches(groupNames.get(Number(it.group_id))));

    const toggle = (kind, id, checked) => {
        const current = new Set(kind === 'groups' ? selectedGroups : selectedItems);
        if (checked) current.add(id); else current.delete(id);
        onChange({
            groups: kind === 'groups' ? [...current] : [...selectedGroups],
            items: kind === 'items' ? [...current] : [...selectedItems],
        });
    };

    const nothingChosen = selectedGroups.size === 0 && selectedItems.size === 0;
    const sectionTitle = { fontSize: 11, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--color-text-secondary)', margin: '10px 0 6px' };
    const listStyle = { maxHeight: 150, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 4 };
    const rowStyle = { fontSize: 13, cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 8, minWidth: 0 };
    const muted = { fontSize: 12, color: 'var(--color-text-secondary)' };

    return (
        <div data-scope-picker style={{ padding: '10px 12px', margin: '4px 0 8px', borderRadius: 12, background: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
            <div style={muted}>
                {t('users.scopeHint')}
                {readOnlyMode ? ` ${t('users.scopeReadNote')}` : ''}
            </div>
            <div className="relative" style={{ marginTop: 8 }}>
                <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--color-text-muted)' }} />
                <input
                    type="text"
                    className="form-input"
                    style={{ paddingLeft: 32, fontSize: 13 }}
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder={t('users.scopeSearch')}
                />
            </div>

            <div style={sectionTitle}>{t('users.scopeGroups')}</div>
            <div style={listStyle} data-scope-groups>
                {visibleGroups.length === 0 && <span style={muted}>{t('users.scopeEmpty')}</span>}
                {visibleGroups.map((g) => (
                    <label key={g.id} style={rowStyle}>
                        <input
                            type="checkbox"
                            checked={selectedGroups.has(Number(g.id))}
                            onChange={(e) => toggle('groups', Number(g.id), e.target.checked)}
                        />
                        <span className="truncate">{g.name}</span>
                    </label>
                ))}
            </div>

            <div style={sectionTitle}>{t('users.scopeItems')}</div>
            <div style={listStyle} data-scope-items>
                {visibleItems.length === 0 && <span style={muted}>{t('users.scopeEmpty')}</span>}
                {visibleItems.map((it) => {
                    const viaGroup = it.group_id != null && selectedGroups.has(Number(it.group_id));
                    const groupName = groupNames.get(Number(it.group_id));
                    return (
                        <label key={it.id} style={{ ...rowStyle, cursor: viaGroup ? 'default' : 'pointer' }}>
                            <input
                                type="checkbox"
                                checked={viaGroup || selectedItems.has(Number(it.id))}
                                disabled={viaGroup}
                                onChange={(e) => toggle('items', Number(it.id), e.target.checked)}
                            />
                            <span className="truncate">{it.name}</span>
                            {groupName && (
                                <span className="truncate" style={{ ...muted, flexShrink: 0, maxWidth: '40%' }}>
                                    {viaGroup ? `${t('users.scopeViaGroup')}: ${groupName}` : groupName}
                                </span>
                            )}
                        </label>
                    );
                })}
            </div>

            {nothingChosen && (
                <div style={{ ...muted, marginTop: 8, color: 'var(--color-warning, #b45309)' }}>
                    {t('users.scopeNothingSelected')}
                </div>
            )}
        </div>
    );
};

export default ScopePicker;
