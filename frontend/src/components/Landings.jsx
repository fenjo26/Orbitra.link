import React, { useState, useMemo } from 'react';
import { Plus, Trash2, Edit3, Settings2, Filter, RefreshCw, X } from 'lucide-react';
import InfoBanner from './InfoBanner';
import LandingEditor from './LandingEditor';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const Landings = ({ landings, refreshData }) => {
    const { t } = useLanguage();
    const [isEditorOpen, setIsEditorOpen] = useState(false);
    const [editingLandingId, setEditingLandingId] = useState(null);
    const [selectedLandingIds, setSelectedLandingIds] = useState(() => new Set());
    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [stateFilter, setStateFilter] = useState('');
    const [settingsOpen, setSettingsOpen] = useState(false);

    const handleCreate = () => {
        setEditingLandingId(null);
        setIsEditorOpen(true);
    };

    const handleEdit = (id) => {
        setEditingLandingId(id);
        setIsEditorOpen(true);
    };

    const handleDelete = async (id) => {
        if (window.confirm(t('common.deleteConfirm'))) {
            try {
                const res = await axios.post(`${API_URL}?action=delete_landing`, { id });
                if (res?.data?.status !== 'success') {
                    alert(res?.data?.message || t('common.error'));
                    return;
                }
                refreshData();
            } catch (err) {
                alert(err?.response?.data?.message || err?.message || t('common.error'));
            }
        }
    };

    const filteredLandings = useMemo(() => {
        const q = String(search || '').trim().toLowerCase();
        return landings.filter(l => {
            if (q) {
                const n = String(l.name || '').toLowerCase();
                const u = String(l.url || '').toLowerCase();
                if (!n.includes(q) && !u.includes(q)) return false;
            }
            if (typeFilter && String(l.type || '') !== typeFilter) return false;
            if (stateFilter && String(l.state || '') !== stateFilter) return false;
            return true;
        });
    }, [landings, search, typeFilter, stateFilter]);

    const visibleLandings = filteredLandings;

    const toggleSelected = (id, checked) => {
        setSelectedLandingIds(prev => {
            const next = new Set(prev);
            if (checked) next.add(id);
            else next.delete(id);
            return next;
        });
    };

    const toggleSelectAll = (checked) => {
        setSelectedLandingIds(prev => {
            const next = new Set(prev);
            if (checked) {
                visibleLandings.forEach(l => next.add(l.id));
            } else {
                visibleLandings.forEach(l => next.delete(l.id));
            }
            return next;
        });
    };

    const allSelected = visibleLandings.length > 0 && visibleLandings.every(l => selectedLandingIds.has(l.id));
    const someSelected = visibleLandings.some(l => selectedLandingIds.has(l.id));

    const handleBulkDeleteSelected = async () => {
        const ids = Array.from(selectedLandingIds);
        if (ids.length === 0) return;
        const msg = (t('common.deleteSelectedConfirm') || t('common.deleteConfirm')).replace('{count}', String(ids.length));
        if (!window.confirm(msg)) return;
        try {
            await axios.post(`${API_URL}?action=bulk_delete_landings`, { ids });
            setSelectedLandingIds(new Set());
            refreshData();
        } catch (err) {
            alert(t('common.error'));
        }
    };

    const exportVisibleCsv = () => {
        const cols = [
            { key: 'id', label: 'id' },
            { key: 'name', label: 'name' },
            { key: 'group_name', label: 'group' },
            { key: 'type', label: 'type' },
            { key: 'state', label: 'state' },
            { key: 'clicks', label: 'clicks' },
            { key: 'unique_clicks', label: 'unique_clicks' },
            { key: 'url', label: 'url' },
        ];

        const escape = (v) => {
            const s = v === null || v === undefined ? '' : String(v);
            if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
            return s;
        };

        const header = cols.map(c => escape(c.label)).join(',');
        const lines = visibleLandings.map(l => cols.map(c => escape(l[c.key])).join(','));
        const csv = [header, ...lines].join('\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `landings_${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    };

    const handleEditorClose = (wasSaved) => {
        setIsEditorOpen(false);
        if (wasSaved) {
            refreshData();
        }
    };

    return (
        <div className="page-card">
            <InfoBanner storageKey="help_landings" title={t('help.landingBannerTitle')}>
                <p>{t('help.landingBanner')}</p>
            </InfoBanner>
            <div className="page-header">
                <div className="flex flex-wrap gap-3">
                    <button onClick={handleCreate} className="btn btn-primary">
                        <Plus className="w-4 h-4" />
                        {t('common.create')}
                    </button>
                    <button className="btn btn-secondary">
                        {t('campaigns.groups')}
                    </button>
                    {selectedLandingIds.size > 0 && (
                        <button onClick={handleBulkDeleteSelected} className="btn btn-danger" title={t('common.deleteSelected')}>
                            <Trash2 className="w-4 h-4" />
                            {(t('common.deleteSelected') || t('common.delete'))} ({selectedLandingIds.size})
                        </button>
                    )}
                </div>
                <div className="flex gap-2">
                    <button
                        type="button"
                        onClick={() => setShowFilters(!showFilters)}
                        className={`btn btn-ghost ${showFilters ? 'bg-[var(--color-primary-light)]' : ''}`}
                        style={showFilters ? { color: 'var(--color-primary)' } : {}}
                    >
                        <Filter className="w-4 h-4" />
                        {t('editor.filters')}
                        {(search || typeFilter || stateFilter) ? (
                            <span className="ml-1 px-1.5 py-0.5 bg-[var(--color-primary)] text-white text-xs rounded-full">
                                {[search, typeFilter, stateFilter].filter(Boolean).length}
                            </span>
                        ) : null}
                    </button>
                    <button type="button" onClick={refreshData} className="btn btn-ghost btn-icon" title={t('common.refresh')}>
                        <RefreshCw className="w-5 h-5" />
                    </button>
                    <button type="button" className="btn btn-ghost btn-icon" title={t('common.settings')} onClick={() => setSettingsOpen(true)}>
                        <Settings2 className="w-5 h-5" />
                    </button>
                </div>
            </div>

            {showFilters && (
                <div className="flex flex-wrap gap-4 items-center py-4 mb-4 border-b" style={{ borderColor: 'var(--color-border)' }}>
                    <div className="flex items-center gap-2">
                        <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('common.search')}:</label>
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="form-input"
                            style={{ width: 'auto', minWidth: '260px' }}
                            placeholder={t('common.searchPlaceholder')}
                        />
                    </div>
                    <div className="flex items-center gap-2">
                        <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('components.type')}:</label>
                        <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} className="form-select" style={{ width: 'auto', minWidth: '140px' }}>
                            <option value="">{t('common.all')}</option>
                            <option value="local">local</option>
                            <option value="redirect">redirect</option>
                            <option value="action">action</option>
                        </select>
                    </div>
                    <div className="flex items-center gap-2">
                        <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('components.status')}:</label>
                        <select value={stateFilter} onChange={(e) => setStateFilter(e.target.value)} className="form-select" style={{ width: 'auto', minWidth: '140px' }}>
                            <option value="">{t('common.all')}</option>
                            <option value="active">{t('components.active')}</option>
                            <option value="archived">{t('components.archive')}</option>
                        </select>
                    </div>
                    {(search || typeFilter || stateFilter) && (
                        <button type="button" onClick={() => { setSearch(''); setTypeFilter(''); setStateFilter(''); }} className="btn btn-ghost btn-sm">
                            <X className="w-4 h-4" />
                            {t('common.clear')}
                        </button>
                    )}
                </div>
            )}

            <div className="overflow-x-auto">
                <table className="page-table">
                    <thead>
                        <tr>
                            <th className="w-10">
                                <input
                                    type="checkbox"
                                    checked={allSelected}
                                    ref={(el) => {
                                        if (el) el.indeterminate = !allSelected && someSelected;
                                    }}
                                    onChange={(e) => toggleSelectAll(e.target.checked)}
                                />
                            </th>
                            <th>ID</th>
                            <th>{t('components.aliasName')}</th>
                            <th>{t('components.group')}</th>
                            <th>{t('components.type')}</th>
                            <th>{t('components.status')}</th>
                            <th>{t('components.clicks')}</th>
                            <th>{t('components.uniques')}</th>
                            <th className="text-right">{t('common.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {visibleLandings.length === 0 ? (
                            <tr>
                                <td colSpan="9" className="text-center py-12">
                                    <div className="empty-state">
                                        <p className="empty-state-title">{t('landings.noLandings')}</p>
                                        <p className="empty-state-text">{t('landings.noLandingsDesc')}</p>
                                    </div>
                                </td>
                            </tr>
                        ) : (
                            visibleLandings.map((landing) => (
                                <tr key={landing.id}>
                                    <td>
                                        <input
                                            type="checkbox"
                                            checked={selectedLandingIds.has(landing.id)}
                                            onChange={(e) => toggleSelected(landing.id, e.target.checked)}
                                        />
                                    </td>
                                    <td className="font-medium">{landing.id}</td>
                                    <td>
                                        <div className="flex flex-col">
                                            <span
                                                className="font-semibold cursor-pointer hover:underline"
                                                style={{ color: 'var(--color-primary)' }}
                                                onClick={() => handleEdit(landing.id)}
                                            >
                                                {landing.name}
                                            </span>
                                            {landing.type !== 'local' && landing.type !== 'action' && (
                                                <span style={{ color: 'var(--color-text-muted)', fontSize: '12px' }} className="truncate max-w-[200px]" title={landing.url}>
                                                    {landing.url}
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                    <td style={{ color: 'var(--color-text-secondary)' }}>{landing.group_name || '-'}</td>
                                    <td>
                                        <span className={`px-2 py-1 rounded text-xs font-semibold ${landing.type === 'local' ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-800'
                                            }`}>
                                            {landing.type}
                                        </span>
                                    </td>
                                    <td>
                                        <span className="flex items-center text-xs font-medium" style={{ color: landing.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}>
                                            <span className="w-2 h-2 rounded-full mr-1.5" style={{ backgroundColor: landing.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}></span>
                                            {landing.state === 'active' ? t('components.active') : t('components.archive')}
                                        </span>
                                    </td>
                                    <td>{landing.clicks || 0}</td>
                                    <td>{landing.unique_clicks || 0}</td>
                                    <td>
                                        <div className="action-buttons">
                                            <button onClick={() => handleEdit(landing.id)} className="action-btn text-blue" title={t('common.edit') || t('components.edit')}>
                                                <Edit3 className="w-4 h-4" />
                                            </button>
                                            <button onClick={() => handleDelete(landing.id)} className="action-btn text-red" title={t('common.delete')}>
                                                <Trash2 className="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {isEditorOpen && (
                <LandingEditor
                    landingId={editingLandingId}
                    onClose={handleEditorClose}
                />
            )}

            {settingsOpen && (
                <div className="modal-overlay" onClick={() => setSettingsOpen(false)}>
                    <div className="modal-content" onClick={(e) => e.stopPropagation()} style={{ maxWidth: '560px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title">{t('common.settings')}</h3>
                            <button type="button" className="btn btn-ghost btn-icon" onClick={() => setSettingsOpen(false)} title={t('common.close')}>
                                <X className="w-5 h-5" />
                            </button>
                        </div>
                        <div className="space-y-3">
                            <button type="button" className="btn btn-secondary w-full" onClick={() => { setSelectedLandingIds(new Set()); }}>
                                {t('common.clearSelection')}
                            </button>
                            <button type="button" className="btn btn-primary w-full" onClick={exportVisibleCsv}>
                                {t('common.exportCsv')}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default Landings;
