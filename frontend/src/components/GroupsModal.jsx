import React, { useState, useEffect } from 'react';
import { X, Plus, Trash2 } from 'lucide-react';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const GroupsModal = ({ type, onClose }) => {
    const { t } = useLanguage();
    const [groups, setGroups] = useState([]);
    const [loading, setLoading] = useState(true);
    const [newGroupName, setNewGroupName] = useState('');

    const getEndpoint = () => {
        switch (type) {
            case 'offer': return 'offer_groups';
            case 'landing': return 'landing_groups';
            case 'campaign': return 'campaign_groups';
            default: return 'offer_groups';
        }
    };

    const getTitle = () => {
        switch (type) {
            case 'offer': return t('groupsModal.offerGroups');
            case 'landing': return t('groupsModal.landingGroups');
            case 'campaign': return t('groupsModal.campaignGroups');
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
            if (res.data.status === 'success') { setNewGroupName(''); fetchGroups(); }
            else alert(res.data.message || t('groupsModal.createError'));
        } catch { alert(t('groupsModal.networkError')); }
    };

    const handleDelete = async (id) => {
        if (!window.confirm(t('groupsModal.deleteConfirm'))) return;
        try {
            const del = type === 'offer' ? 'delete_offer_group' : endpoint;
            await axios.post(`${API_URL}?action=${del}`, { id });
            fetchGroups();
        } catch { alert(t('groupsModal.deleteError')); }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 backdrop-blur-sm p-4">
            <div className="bg-white rounded-lg shadow-xl w-full max-w-md">
                <div className="flex justify-between items-center px-6 py-4 border-b border-gray-200 bg-gray-50 rounded-t-lg">
                    <h3 className="text-lg font-bold text-gray-800">{getTitle()}</h3>
                    <button onClick={() => onClose(false)} className="text-gray-500 hover:text-gray-700"><X className="w-5 h-5" /></button>
                </div>
                <div className="p-4 border-b border-gray-200">
                    <div className="flex space-x-2">
                        <input type="text" value={newGroupName} onChange={(e) => setNewGroupName(e.target.value)} onKeyPress={(e) => e.key === 'Enter' && handleCreate()} placeholder={t('groups.placeholder')} className="flex-1 border border-gray-300 rounded px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500" />
                        <button onClick={handleCreate} disabled={!newGroupName.trim()} className="flex items-center px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition">
                            <Plus className="w-4 h-4 mr-1" />{t('groupsModal.add')}
                        </button>
                    </div>
                </div>
                <div className="max-h-80 overflow-y-auto">
                    {loading ? (
                        <div className="p-8 text-center text-gray-500">{t('groupsModal.loading')}</div>
                    ) : groups.length === 0 ? (
                        <div className="p-8 text-center text-gray-400">
                            <p>{t('groupsModal.noGroups')}</p>
                            <p className="text-sm mt-1">{t('groupsModal.createFirst')}</p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-gray-200">
                            {groups.map((group) => (
                                <li key={group.id} className="flex items-center justify-between px-6 py-3 hover:bg-gray-50 transition">
                                    <div className="flex items-center space-x-3">
                                        <span className="text-sm font-medium text-gray-700">{group.name}</span>
                                        <span className="text-xs text-gray-400">ID: {group.id}</span>
                                    </div>
                                    <button onClick={() => handleDelete(group.id)} className="text-gray-400 hover:text-red-600 p-1" title={t('groups.delete')}>
                                        <Trash2 className="w-4 h-4" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div className="px-6 py-4 border-t border-gray-200 bg-gray-50 rounded-b-lg flex justify-end">
                    <button onClick={() => onClose(false)} className="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 transition">{t('groupsModal.close')}</button>
                </div>
            </div>
        </div>
    );
};

export default GroupsModal;