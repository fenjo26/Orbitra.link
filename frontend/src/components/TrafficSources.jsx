import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { Plus, Edit, Trash2, Search, RefreshCw, ExternalLink, Copy } from 'lucide-react';
import InfoBanner from './InfoBanner';
import TrafficSourceEditor from './TrafficSourceEditor';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const TrafficSources = ({ refreshData }) => {
    const { t } = useLanguage();
    const [sources, setSources] = useState([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [stateFilter, setStateFilter] = useState('all');
    const [showEditor, setShowEditor] = useState(false);
    const [editId, setEditId] = useState(null);
    const [selectedIds, setSelectedIds] = useState(() => new Set());

    const fetchSources = async () => {
        setLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=traffic_sources`);
            if (res.data.status === 'success') {
                setSources(res.data.data);
            }
        } catch (error) {
            console.error('Error fetching traffic sources:', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchSources();
    }, []);

    const handleDelete = async (id) => {
        if (!confirm(t('sources.deleteConfirm'))) return;
        try {
            await axios.post(`${API_URL}?action=delete_traffic_source`, { id });
            fetchSources();
            refreshData && refreshData();
        } catch (error) {
            console.error('Error deleting traffic source:', error);
        }
    };

    const toggleSelected = (id, checked) => {
        setSelectedIds(prev => {
            const next = new Set(prev);
            if (checked) next.add(id);
            else next.delete(id);
            return next;
        });
    };

    const toggleSelectAllFiltered = (checked) => {
        setSelectedIds(prev => {
            const next = new Set(prev);
            if (checked) {
                filteredSources.forEach(s => next.add(s.id));
            } else {
                filteredSources.forEach(s => next.delete(s.id));
            }
            return next;
        });
    };

    const allFilteredSelected = filteredSources.length > 0 && filteredSources.every(s => selectedIds.has(s.id));
    const someFilteredSelected = filteredSources.some(s => selectedIds.has(s.id));

    const handleBulkDeleteSelected = async () => {
        const ids = Array.from(selectedIds);
        if (ids.length === 0) return;
        const msg = (t('common.deleteSelectedConfirm') || t('sources.deleteConfirm')).replace('{count}', String(ids.length));
        if (!confirm(msg)) return;
        try {
            await axios.post(`${API_URL}?action=bulk_delete_traffic_sources`, { ids });
            setSelectedIds(new Set());
            fetchSources();
            refreshData && refreshData();
        } catch (error) {
            console.error('Error deleting traffic sources:', error);
        }
    };

    const handleEdit = (id) => {
        setEditId(id);
        setShowEditor(true);
    };

    const handleCreate = () => {
        setEditId(null);
        setShowEditor(true);
    };

    const handleEditorSave = () => {
        setShowEditor(false);
        fetchSources();
        refreshData && refreshData();
    };

    const filteredSources = sources.filter(s => {
        const matchesSearch = s.name.toLowerCase().includes(search.toLowerCase());
        const matchesState = stateFilter === 'all' ||
            (stateFilter === 'active' && s.state === 'active') ||
            (stateFilter === 'paused' && s.state !== 'active');
        return matchesSearch && matchesState;
    });

    const copyUrl = (url) => {
        navigator.clipboard.writeText(url);
    };

    return (
        <div className="space-y-4">
            <InfoBanner storageKey="help_traffic_sources" title={t('help.trafficSourceBannerTitle')}>
                <p>{t('help.trafficSourceBanner')}</p>
            </InfoBanner>
            {/* Header */}
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div className="flex items-center gap-3">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2" size={18} style={{ color: 'var(--color-text-muted)' }} />
                        <input
                            type="text"
                            placeholder={t('sources.search')}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="form-input pl-10"
                        />
                    </div>
                    <select
                        value={stateFilter}
                        onChange={(e) => setStateFilter(e.target.value)}
                        className="form-select"
                    >
                        <option value="all">{t('common.all')}</option>
                        <option value="active">{t('sources.activePlural')}</option>
                        <option value="paused">{t('sources.pausedPlural')}</option>
                    </select>
                </div>
                <div className="flex gap-2">
                    <button
                        onClick={fetchSources}
                        className="action-btn"
                        title={t('common.refresh')}
                    >
                        <RefreshCw size={18} />
                    </button>
                    {selectedIds.size > 0 && (
                        <button
                            onClick={handleBulkDeleteSelected}
                            className="btn btn-danger"
                            title={t('common.deleteSelected')}
                        >
                            <Trash2 size={18} />
                            <span>{(t('common.deleteSelected') || t('common.delete'))} ({selectedIds.size})</span>
                        </button>
                    )}
                    <button
                        onClick={handleCreate}
                        className="btn btn-primary"
                    >
                        <Plus size={18} />
                        <span>{t('common.create')}</span>
                    </button>
                </div>
            </div>

            {/* Table */}
            {loading ? (
                <div className="flex justify-center items-center h-64">
                    <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: 'var(--color-primary)' }}></div>
                </div>
            ) : (
                <div className="page-card p-0 overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="page-table">
                            <thead>
                                <tr>
                                    <th className="w-10">
                                        <input
                                            type="checkbox"
                                            checked={allFilteredSelected}
                                            ref={(el) => {
                                                if (el) el.indeterminate = !allFilteredSelected && someFilteredSelected;
                                            }}
                                            onChange={(e) => toggleSelectAllFiltered(e.target.checked)}
                                        />
                                    </th>
                                    <th>{t('editor.name')}</th>
                                    <th>{t('sources.template')}</th>
                                    <th>{t('campaigns.campaigns')}</th>
                                    <th>{t('components.clicks')}</th>
                                    <th>{t('metrics.conversions')}</th>
                                    <th>{t('components.status')}</th>
                                    <th>{t('common.actions')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filteredSources.length === 0 ? (
                                    <tr>
                                        <td colSpan={8} className="text-center py-8" style={{ color: 'var(--color-text-muted)' }}>
                                            {search ? t('sources.notFound') : t('sources.noSourcesAdd')}
                                        </td>
                                    </tr>
                                ) : (
                                    filteredSources.map(source => (
                                        <tr key={source.id}>
                                            <td>
                                                <input
                                                    type="checkbox"
                                                    checked={selectedIds.has(source.id)}
                                                    onChange={(e) => toggleSelected(source.id, e.target.checked)}
                                                />
                                            </td>
                                            <td>
                                                <div className="font-medium">{source.name}</div>
                                                {source.notes && (
                                                    <div className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>{source.notes.substring(0, 50)}...</div>
                                                )}
                                            </td>
                                            <td>
                                                <span className="status-badge" style={{ backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}>
                                                    {source.template || t('sources.customTemplate')}
                                                </span>
                                            </td>
                                            <td>{source.campaigns_count || 0}</td>
                                            <td>{source.clicks || 0}</td>
                                            <td>{source.conversions || 0}</td>
                                            <td>
                                                <span className={`status-badge ${source.state === 'active' ? 'status-active' : 'status-pending'}`}>
                                                    {source.state === 'active' ? t('components.active') : t('components.paused')}
                                                </span>
                                            </td>
                                            <td>
                                                <div className="action-buttons">
                                                    <button
                                                        onClick={() => handleEdit(source.id)}
                                                        className="action-btn"
                                                        title={t('components.edit')}
                                                    >
                                                        <Edit size={16} />
                                                    </button>
                                                    <button
                                                        onClick={() => handleDelete(source.id)}
                                                        className="action-btn text-red"
                                                        title={t('common.delete')}
                                                    >
                                                        <Trash2 size={16} />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* Info panel */}
            <div className="p-4 rounded-xl" style={{ backgroundColor: 'var(--color-primary-light)', border: '1px solid var(--color-primary)' }}>
                <h3 className="font-medium mb-2" style={{ color: 'var(--color-primary)' }}>💡 {t('sources.title')}</h3>
                <p className="text-sm mb-2" style={{ color: 'var(--color-text-secondary)' }}>
                    {t('sources.infoDesc')}
                </p>
                <ul className="text-sm space-y-1" style={{ color: 'var(--color-text-secondary)' }}>
                    <li>{t('sources.infoList1')}</li>
                    <li>{t('sources.infoList2')}</li>
                    <li>{t('sources.infoList3')}</li>
                    <li>{t('sources.infoList4')}</li>
                </ul>
            </div>

            {/* Editor Modal */}
            {showEditor && (
                <TrafficSourceEditor
                    id={editId}
                    onClose={() => setShowEditor(false)}
                    onSave={handleEditorSave}
                />
            )}
        </div>
    );
};

export default TrafficSources;
