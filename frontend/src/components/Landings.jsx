import React, { useState } from 'react';
import { Plus, Trash2, Edit3, Settings2 } from 'lucide-react';
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
                landings.forEach(l => next.add(l.id));
            } else {
                landings.forEach(l => next.delete(l.id));
            }
            return next;
        });
    };

    const allSelected = landings.length > 0 && landings.every(l => selectedLandingIds.has(l.id));
    const someSelected = landings.some(l => selectedLandingIds.has(l.id));

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
                <button className="btn btn-ghost btn-icon">
                    <Settings2 className="w-5 h-5" />
                </button>
            </div>

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
                        {landings.length === 0 ? (
                            <tr>
                                <td colSpan="9" className="text-center py-12">
                                    <div className="empty-state">
                                        <p className="empty-state-title">{t('landings.noLandings')}</p>
                                        <p className="empty-state-text">{t('landings.noLandingsDesc')}</p>
                                    </div>
                                </td>
                            </tr>
                        ) : (
                            landings.map((landing) => (
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
        </div>
    );
};

export default Landings;
