import React, { useState, useEffect } from 'react';
import { X, Plus, Trash2 } from 'lucide-react';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const GroupsModal = ({ type, onClose, onGroupCreated }) => {
    const { t } = useLanguage();
    const [groups, setGroups] = useState([]);
    const [loading, setLoading] = useState(true);
    const [newGroupName, setNewGroupName] = useState('');

    const getEndpoint = () => {
        switch (type) {
            case 'offer': return 'offer_groups';
            case 'landing': return 'landing_groups';
            case 'campaign': return 'campaign_groups';
            case 'domain': return 'domain_groups';
            default: return 'offer_groups';
        }
    };

    const getDeleteEndpoint = () => {
        switch (type) {
            case 'offer': return 'delete_offer_group';
            case 'landing': return 'delete_landing_group';
            case 'campaign': return 'delete_campaign_group';
            case 'domain': return 'delete_domain_group';
            default: return 'delete_offer_group';
        }
    };

    const getTitle = () => {
        switch (type) {
            case 'offer': return t('groupsModal.offerGroups');
            case 'landing': return t('groupsModal.landingGroups');
            case 'campaign': return t('groupsModal.campaignGroups');
            case 'domain': return t('groupsModal.domainGroups');
            default: return t('groupsModal.groups');
        }
    };

    const endpoint = getEndpoint();

    useEffect(() => { fetchGroups(); }, [endpoint]);

    const fetchGroups = async () => {
        setLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=${endpoint}`);
            if (res.data.status === 'success') setGroups(res.data.data);
        } catch (err) {
            console.error('Error fetching groups:', err);
        } finally { setLoading(false); }
    };

    const handleCreate = async () => {
        if (!newGroupName.trim()) return;
        try {
            const res = await axios.post(`${API_URL}?action=${endpoint}`, { name: newGroupName.trim() });
            if (res.data.status === 'success') {
                const created = { id: res.data.data?.id, name: newGroupName.trim() };
                setNewGroupName('');
                fetchGroups();
                // Let the caller (e.g. a "+" button next to a group select) select
                // the new group immediately instead of reopening the dropdown.
                if (onGroupCreated) onGroupCreated(created);
            }
            else alert(res.data.message || t('groupsModal.createError'));
        } catch { alert(t('groupsModal.networkError')); }
    };

    const handleDelete = async (id) => {
        if (!window.confirm(t('groupsModal.deleteConfirm'))) return;
        try {
            await axios.post(`${API_URL}?action=${getDeleteEndpoint()}`, { id });
            fetchGroups();
        } catch { alert(t('groupsModal.deleteError')); }
    };

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '480px' }}>
                <div className="modal-header">
                    <h2 className="modal-title">{getTitle()}</h2>
                    <button onClick={() => onClose(false)} className="action-btn">
                        <X className="w-5 h-5" />
                    </button>
                </div>
                <div className="p-4 border-b" style={{ borderColor: 'var(--color-border)' }}>
                    <div className="flex gap-2">
                        <input
                            type="text"
                            value={newGroupName}
                            onChange={(e) => setNewGroupName(e.target.value)}
                            onKeyPress={(e) => e.key === 'Enter' && handleCreate()}
                            placeholder={t('groups.placeholder')}
                            className="form-input text-sm"
                            autoFocus
                        />
                        <button onClick={handleCreate} disabled={!newGroupName.trim()} className="btn btn-primary whitespace-nowrap">
                            <Plus className="w-4 h-4" />{t('groupsModal.add')}
                        </button>
                    </div>
                </div>
                <div className="max-h-80 overflow-y-auto">
                    {loading ? (
                        <div className="p-8 text-center" style={{ color: 'var(--color-text-muted)' }}>{t('groupsModal.loading')}</div>
                    ) : groups.length === 0 ? (
                        <div className="p-8 text-center" style={{ color: 'var(--color-text-muted)' }}>
                            <p>{t('groupsModal.noGroups')}</p>
                            <p className="text-sm mt-1">{t('groupsModal.createFirst')}</p>
                        </div>
                    ) : (
                        <ul className="divide-y" style={{ borderColor: 'var(--color-border)' }}>
                            {groups.map((group) => (
                                <li key={group.id} className="flex items-center justify-between px-6 py-3 transition" style={{ borderColor: 'var(--color-border)' }}>
                                    <div className="flex items-center gap-3">
                                        <span className="text-sm font-medium" style={{ color: 'var(--color-text-primary)' }}>{group.name}</span>
                                        <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>ID: {group.id}</span>
                                    </div>
                                    <button onClick={() => handleDelete(group.id)} className="action-btn" title={t('groups.delete')}>
                                        <Trash2 className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div className="px-6 py-4 border-t flex justify-end" style={{ borderColor: 'var(--color-border)' }}>
                    <button onClick={() => onClose(false)} className="btn btn-secondary">{t('groupsModal.close')}</button>
                </div>
            </div>
        </div>
    );
};

export default GroupsModal;
